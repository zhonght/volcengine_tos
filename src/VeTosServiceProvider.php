<?php

namespace Ykyun\VeTos;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
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
            $pathTemplate = null ;
            if($uploadWay == VeTosAdapter::UPLOAD_WAY_STS){
               //用STS创建传入临时登录token进行验证上传

                $redisKey = "session_token_key_{$uploadAppId}" ;
                if (Cache::has($redisKey)) {
                    //证明缓存中存在此数据信息
                    $resultInfo = Cache::get($redisKey);
                }else{
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
                        //签名串
                        $signVal = $apiTimestamp."&".$apiIdentify."&".$uploadAppIdSecret ;
                        //sha256的十六进制字符串
                        $apiSign = hash('sha256',$signVal) ;
                        $headers['apiSign'] = $apiSign ;
                        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
                        $client = new Client();
                        $res = $client->request('GET',$requestUrl, [
                            //请求头
                            'headers' => $headers,
                            //x-www-form-urlencoded 表单数据提交
                            'form_params' => [],
                        ]);
                        //获取接口请求返回来的内容数据,json数据
                        $resultJson= $res->getBody()->getContents();
                        //将json数据转换为数组格式
                        $dataResult = json_decode($resultJson, true);
                        //返回的数据的状态码
                        $dataResultCode = $dataResult['code'] ?? -1 ;
                        if($dataResultCode != 200){
                            throw new Exception( "java接口请求状态码失败为:".$dataResult['msg'] ?? "请求返回异常" );
                        }
                        //获取接口返回的数据数组
                        $resultInfo = $dataResult['data'] ?? [] ;
                    }catch (RequestException $e) {
                        // 网络层异常处理
                        if ($e->hasResponse()) {
                            $errorResponse = $e->getResponse();
                            $errorBody = $errorResponse->getBody()->getContents();
                            $errorBodyArr = json_decode($errorBody, true);
                            throw new Exception("java接口请求成功返回的错误原因为:".$errorBodyArr["msg"] ?? "请求异常");
                        }
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


}
