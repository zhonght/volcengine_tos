<?php

namespace Ykyun\VeTos;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tos\TosClient;
use Ykyun\VeTos\Plugins\PutFile;
use Ykyun\VeTos\Plugins\PutRemoteFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class VeTosServiceProvider extends ServiceProvider
{

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        Storage::extend('volcengine', function($app, $config)
        {
            //是否需要打印出错误日志:true为需要 false为不需要
            $debug     = empty($config['debug']) ? false : $config['debug'];
            //访问权限  private=私有  public-read=公共读  public-read-write=公共读写
            $aclAccessAuthority = $config['acl_access_authority'] ?? "private";

            //上传模式方式
            $uploadWay = $config['volcengine_upload_way'] ?? '';
            //应用appId
            $uploadAppId = $config['volcengine_appid'] ?? '';
            //应用秘钥
            $uploadAppIdSecret = $config['volcengine_appid_secret'] ?? '';
            //桶类型：1-小文件桶（默认），2-大文件桶
            $bucketType = $config['volcengine_bucket_type'] ?? 1;
            $bucketType = (int)$bucketType;
            //桶类型不合法时默认使用小文件桶
            if(!in_array($bucketType, [1, 2], true)){
                $bucketType = 1;
            }
            $pathTemplate = null ;
            if($uploadWay == VeTosAdapter::UPLOAD_WAY_STS){
                //用STS创建传入临时登录token进行验证上传

                //缓存key拼接桶类型，避免不同桶类型的临时密钥互相覆盖
                $redisKey = "session_token_key_{$uploadAppId}_{$bucketType}" ;
                $resultInfo = Cache::get($redisKey);
                //缓存里没有，或缓存的临时凭证按真实剩余时间已不足安全阈值 → 强制重新拉取
                if (empty($resultInfo) || !$this->stsCredentialValid($resultInfo)) {
                    //证明缓存中数据已失效或者不存在

                    try {
                        //请求java接口地址
                        $javaHostUrl = $config['volcengine_java_host_url'] ?? '';
                        $requestUrl = "{$javaHostUrl}api/tia/v1/tos/app-credentials" ;
                        //获取当前时间的毫秒时间戳
                        $apiTimestamp = $this->getmillisecond();
                        $apiIdentify = Str::random(32) ;
                        $headers = [
                            //当前时间戳(毫秒)
                            'apiTimestamp' => $apiTimestamp,
                            //唯标识 (例如uuid) 度最32位
                            'apiIdentify' => $apiIdentify,
                            //应用ID
                            'appId' => $uploadAppId ,
                            'versionCode' => 0
                        ] ;

                        //接口业务参数（桶类型：1-小文件桶，2-大文件桶），会同时用于签名和query
                        $requestParams = [
                            'bucketType' => $bucketType,
                        ];

                        //签名串规则：接口参数按 key 字母序拼成 key=value，用 & 分隔，
                        //空数组/空串/空值不参与，最后再接 &当前时间戳&唯一标识&应用密钥
                        $signParts = [];
                        //按参数名(key)字母顺序排序
                        ksort($requestParams);
                        foreach ($requestParams as $paramKey => $paramValue) {
                            //空数组、空字符串、空值不放入签名串
                            if (is_array($paramValue) ? empty($paramValue) : ($paramValue === '' || $paramValue === null)) {
                                continue;
                            }
                            $signParts[] = $paramKey."=".$paramValue;
                        }
                        //拼接接口参数部分（可能为空）
                        $paramSignStr = implode("&", $signParts);
                        //签名串 = 参数串 & 当前时间戳 & 唯一标识 & 应用密钥
                        $signVal = ($paramSignStr === '' ? '' : $paramSignStr."&")
                            .$apiTimestamp."&".$apiIdentify."&".$uploadAppIdSecret ;
                        //小写 sha256 的十六进制字符串
                        $apiSign = hash('sha256',$signVal) ;
                        $headers['apiSign'] = $apiSign ;
                        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

                        //【调试日志】记录即将发起的 java 凭证请求
                        Log::info('[VeTos STS] 请求 java 凭证接口', [
                            'requestUrl'   => $requestUrl,
                            'appId'        => $uploadAppId,
                            //桶类型：1-小文件桶（默认），2-大文件桶
                            'bucketType'   => $bucketType,
                            'apiTimestamp' => $apiTimestamp,
                            'apiIdentify'  => $apiIdentify,
                            //secret 不打印，只标记是否已配置
                            'secretConfigured' => !empty($uploadAppIdSecret),
                        ]);

                        $client = new Client();
                        $res = $client->request('GET',$requestUrl, [
                            //请求头
                            'headers' => $headers,
                            //接口业务参数（含桶类型bucketType），与签名使用同一份参数
                            'query' => $requestParams,
                            //x-www-form-urlencoded 表单数据提交
                            'form_params' => [],
                        ]);
                        //获取接口请求返回来的内容数据,json数据
                        $resultJson= $res->getBody()->getContents();

                        //【调试日志】记录 java 接口返回的状态码与原始响应体
                        Log::info('[VeTos STS] java 凭证接口返回', [
                            'bucketType' => $bucketType,
                            'httpStatus' => $res->getStatusCode(),
                            'body'       => mb_substr((string)$resultJson, 0, 1000),
                        ]);

                        //将json数据转换为数组格式
                        $dataResult = json_decode($resultJson, true);
                        //返回的数据的状态码
                        $dataResultCode = $dataResult['code'] ?? -1 ;
                        if($dataResultCode != 200){
                            Log::warning('[VeTos STS] java 接口业务状态码非 200', [
                                'bucketType' => $bucketType,
                                'code'       => $dataResultCode,
                                'body'       => mb_substr((string)$resultJson, 0, 1000),
                            ]);
                            throw new Exception( "java接口请求状态码失败为:".($dataResult['msg'] ?? "请求返回异常") );
                        }
                        //获取接口返回的数据数组
                        $resultInfo = $dataResult['data'] ?? [] ;
                    }catch (RequestException $e) {
                        // 网络层异常处理
                        if ($e->hasResponse()) {
                            $errorResponse = $e->getResponse();
                            $errorBody = $errorResponse->getBody()->getContents();
                            $errorBodyArr = json_decode($errorBody, true);

                            //【调试日志】先把真实的错误响应记录下来，避免被后续解析异常掩盖
                            Log::error('[VeTos STS] java 接口返回错误响应', [
                                'bucketType' => $bucketType,
                                'httpStatus' => $errorResponse->getStatusCode(),
                                'body'       => mb_substr((string)$errorBody, 0, 1000),
                            ]);

                            $errorMsg = is_array($errorBodyArr) ? ($errorBodyArr["msg"] ?? null) : null;
                            if (empty($errorMsg)) {
                                //响应体不是合法 JSON（例如网关返回的 HTML 错误页），直接带出原始内容
                                $errorMsg = "HTTP ".$errorResponse->getStatusCode().", 响应内容:".mb_substr((string)$errorBody, 0, 500);
                            }
                            throw new Exception("java接口请求成功返回的错误原因为:".$errorMsg);
                        }

                        //【调试日志】没有响应（连接失败/超时/DNS 等），记录底层异常信息
                        Log::error('[VeTos STS] 请求 java 接口异常（无响应）', [
                            'bucketType' => $bucketType,
                            'requestUrl' => $requestUrl,
                            'message'    => $e->getMessage(),
                        ]);
                        throw new Exception("java接口请求异常原因为:".$e->getMessage());
                    }
                }

                //桶名称
                $bucket = $resultInfo['bucket'] ?? "" ;
                //外网节点或自定义外部域名
                $endPoint = $resultInfo['endpoint'] ?? "" ;
                $client  = new TosClient([
                    //火山引擎区域
                    'region' =>  $resultInfo['region'] ?? "",
                    //外网节点或自定义外部域名
                    'endpoint' => $endPoint ,
                    //火山引擎的AccessKey
                    'ak' => $resultInfo['accessKeyId'] ?? '',
                    //火山引擎的SecretKey
                    'sk' => $resultInfo['secretAccessKey'] ?? '',
                    // 使用 STS 临时 AK/SK+Token 访问火山引擎 TOS
                    'securityToken' => $resultInfo['sessionToken'] ?? '' ,
                ]);

                //获取固定上传的图片路径前缀
                $pathTemplate = $resultInfo['pathTemplate'] ?? "" ;
                //剔除图片路径的变量值
                $pathTemplate = str_replace("{business}","",$pathTemplate);

                //TOS的访问域名
                $cdnDomain = $resultInfo['domain'] ?? "" ;
                $cdnDomain = trim($cdnDomain,"/");

                //token过期时间（毫秒时间戳）
                $expireTime = $resultInfo['expireTime'] ?? 0 ;
                //将毫秒转换为秒
                $expireTimes = $expireTime / 1000 ;
                //接口成功后获取的有效秒数 = 接口返回的有效时间戳秒数 与 当前时间戳秒数 进行对比
                $expireSeconds = $expireTimes - time();
                //获取缓存中的秒数，有效秒数再减去1分钟即60秒提前失效处理
                $cacheSeconds = $expireSeconds > 0 ? $expireSeconds - 60 : 0 ;
                if($cacheSeconds > 0){
                    //设置缓存60秒
                    Cache::put($redisKey, $resultInfo, $cacheSeconds);
                }else{
                    //删除缓存
                    Cache::forget($redisKey);
                }
            }else{


                //外网节点或自定义外部域名
                $endPoint  = $config['endpoint'] ?? '';
                //桶名称
                $bucket    = $config['bucket'] ?? '';
                $cdnDomain = empty($config['cdnDomain']) ? '' : $config['cdnDomain'];

                $client  = new TosClient([
                    //火山引擎区域
                    'region' => $config['region'] ?? '',
                    //外网节点或自定义外部域名
                    'endpoint' => $endPoint,
                    //火山引擎的AccessKey
                    'ak' => $config['access_key_id'] ?? '',
                    //火山引擎的SecretKey
                    'sk' => $config['access_key_secret'] ?? '',
                ]);
            }
            //TOS的适配器
            $adapter = new VeTosAdapter(
                $client,
                $bucket,
                $endPoint,
                $cdnDomain,
                $aclAccessAuthority,
                $uploadWay,
                $debug,
                $pathTemplate
            );
            $filesystem =  new Filesystem($adapter);
            $filesystem->addPlugin(new PutFile());
            $filesystem->addPlugin(new PutRemoteFile());
            return $filesystem;
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
    }

    /**
     * 获取当前时间的毫秒时间戳
     * @return string
     */
    public function getmillisecond() {
        list($msec, $sec) = explode(' ', microtime());
        $msectime = (string)number_format(sprintf('%.0f', (floatval($msec) + floatval($sec)) * 1000),0,'','');
        return $msectime;
    }

    /**
     * 判断缓存中的 STS 临时凭证是否仍然有效（带安全阈值）。
     *
     * 只用 Cache TTL 判断存在性并不可靠：Redis key 未到期，但 token 真实有效期可能已临近/过期
     * （例如 Java 返回的 token 有效期偏短，或缓存写入与实际使用之间有较长间隔）。
     * 这里用返回的真实 expireTime（毫秒时间戳）重新计算剩余秒数，剩余不足安全阈值即视为无效，
     * 强制重新向 Java 拉取新凭证，避免把过期 token 发给 TOS 触发 “The provided token has expired.”。
     *
     * 注意：本方法依赖本机 time()。若容器时钟与真实时间存在漂移，此判断同样会被带偏，
     * 时钟漂移需在部署环境层面（如校准 WSL2 / docker 容器时间）解决。
     *
     * @param array $resultInfo java 返回并缓存的凭证数据
     * @param int   $safety     安全阈值（秒），剩余不足该值即视为无效，默认 120 秒
     * @return bool
     */
    protected function stsCredentialValid(array $resultInfo, int $safety = 120): bool
    {
        //缺少关键字段（token / ak）直接判为无效
        if (empty($resultInfo['sessionToken']) || empty($resultInfo['accessKeyId'])) {
            return false;
        }
        //token 过期时间（毫秒时间戳）
        $expireTime = $resultInfo['expireTime'] ?? 0;
        if ($expireTime <= 0) {
            return false;
        }
        //换算成剩余秒数：真实到期秒数 - 当前秒数
        $remaining = ($expireTime / 1000) - time();
        //剩余时间必须大于安全阈值才算有效
        return $remaining > $safety;
    }


}
