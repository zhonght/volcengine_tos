<?php

namespace Ykyun\VeTos;

use Exception;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
use Illuminate\Support\Facades\Log;
use Tos\Exception\TosClientException;
use Tos\Exception\TosException;
use Tos\Model\CopyObjectInput;
use Tos\Model\DeleteMultiObjectsInput;
use Tos\Model\DeleteObjectInput;
use Tos\Model\GetObjectACLInput;
use Tos\Model\HeadObjectInput;
use Tos\Model\ListObjectsInput;
use Tos\Model\ObjectTobeDeleted;
use Tos\Model\PutObjectACLInput;
use Tos\Model\PutObjectInput;
use Tos\Model\GetObjectInput;
use Tos\Model\UploadFileInput;
use Tos\TosClient;

/**
 *
 * 以下这两个是上传驱动的主要关键实现方法类
 *
 * 上传驱动适配器类:  laravel/framework/src/Illuminate/Filesystem/FilesystemAdapter.php
 *
 * 上传驱动适配器对应调用实现类，这个就是会调用回所设置的驱动适配器，比如我们设置火山引擎驱动上传就是调用当前这个适配器:  $this->driver == league/flysystem/src/Filesystem.php
 *
 */

class VeTosAdapter extends AbstractAdapter
{
    /**
     * @var Log debug Mode true|false
     */
    protected $debug;
    /**
     * @var array
     */
    protected static $resultMap = [
        'Body'           => 'raw_contents',
        'Content-Length' => 'size',
        'ContentType'    => 'mimetype',
        'Size'           => 'size',
        'StorageClass'   => 'storage_class',
    ];


    /**
     * 上传文件的时候配置请求头的所指定的key数组
     *  Storage::put('path/to/file/file.jpg', $contents, ['ACL'=>'private','ContentType'=>'utf-8']);
     *
     * @var string[]
     */
    protected static $metaOptions = [
        'CacheControl',
        'Expires',
        'ServerSideEncryption',
        'Metadata',
        'ACL',
        'ContentType',
        'ContentDisposition',
        'ContentLanguage',
        'ContentEncoding',
    ];
    /**
     * 根据指定的key的兑换请求头的实际参数key
     * ['ACL'=>'private','ContentType'=>'utf-8']
     * 则替换成数组为
     * ['x-tos-object-acl'=>'private','Content-Type'=>'utf-8']
     * @var string[]
     */
    protected static $metaMap = [
        'CacheControl'         => 'Cache-Control',
        'Expires'              => 'Expires',
        'ServerSideEncryption' => 'x-tos-server-side-encryption',
        'Metadata'             => 'x-tos-metadata-directive',
        'ACL'                  => 'x-tos-object-acl',
        'ContentType'          => 'Content-Type',
        'ContentDisposition'   => 'Content-Disposition',
        'ContentLanguage'      => 'response-content-language',
        'ContentEncoding'      => 'Content-Encoding',
    ];

    //火山引擎TOS连接对象
    protected $client;

    //是否使用自定义域名
    protected $isCname;

    //$isCname为false时就是用 $bucket.$endPoint 组合起来的域名进行访问
    //桶名称
    protected $bucket;
    //外网节点
    protected $endPoint;

    //$isCname为true时就是用这个自定义域名
    protected $cdnDomain;

    //是否使用https来进行访问
    protected $ssl;
    //访问权限  private=私有  public-read=公共读  public-read-write=公共读写
    protected $aclAccessAuthority;
    //通过STS模式进行上传图片
    const UPLOAD_WAY_STS = "TOS_STS" ;
    //当前上传到TOS的方式: 默认是通过相关key认证值来进行上传 STS是调用java接口获取相关数据来进行上传
    protected $uploadWay;

    //选项数组的初始数组
    protected $options = [
        'Multipart'   => 128
    ];

    const OSS_LENGTH = 'length';
    const OSS_CONTENT_TYPE = 'Content-Type';
    const OSS_HEADERS = 'headers';
    const OSS_ACL_TYPE_PUBLIC_READ = 'public-read';
    const OSS_ACL_TYPE_PRIVATE = 'private';

    const OSS_ACL_TYPE_PRIVATE_PERMISSION = 'FULL_CONTROL';

    const OSS_CHECK_MD5 = 'checkmd5';

    /**
     * 火山引擎TOS适配器初始化
     *
     * @param TosClient $client
     * @param string $bucket
     * @param string $endPoint
     * @param bool $ssl
     * @param string $cdnDomain
     * @param string $aclAccessAuthority
     * @param string $uploadWay
     * @param bool $isCname
     * @param bool $debug
     * @param string|null $prefix
     * @param array $options
     */
    public function __construct(
        TosClient $client,
        string    $bucket,
        string    $endPoint,
        string    $cdnDomain,
        string    $aclAccessAuthority,
        string    $uploadWay = "",
        bool      $debug = false,
        string    $prefix = null,
        array     $options = []
    )
    {
        //获取是否开启日志记录模式
        $this->debug = $debug;
        //获取TOS连接对象
        $this->client = $client;
        //获取图片的真正访问路径(包含所指定的路径前缀即必须上传到指定的父目录)
        $this->setPathPrefix($prefix);

        //不自定义域名就是 桶名称.外网节点
        //获取桶名称
        $this->bucket = $bucket;
        //获取外网节点
        $this->endPoint = $endPoint;

        //获取自定义域名
        $this->cdnDomain = $cdnDomain;

        //获取访问权限
        $this->aclAccessAuthority = $aclAccessAuthority;
        //获取上传到TOS的方式
        $this->uploadWay = $uploadWay;

        //配置数组
        $this->options = array_merge($this->options, $options);
    }

    /**
     * 获取TOS连接对象的桶名称
     *
     * @return string
     */
    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * 获取TOS连接对象
     *
     * @return TosClient
     */
    public function getClient(): TosClient
    {
        return $this->client;
    }

    /**
     * 将文件内容字符串进行上传到火山引擎TOS
     * @param $path
     * @param $contents
     * @param Config $config
     * @return array|false|string[]
     */
    public function write($path, $contents, Config $config)
    {
        //获取文件的真正上传路径
        $object = $this->applyPathPrefix($path);
        //获取调用TOS的选项数组
        $options = $this->getOptions($this->options, $config);
        if (! isset($options[self::OSS_LENGTH])) {
            $options[self::OSS_LENGTH] = Util::contentSize($contents);
        }
        if (! isset($options[self::OSS_CONTENT_TYPE])) {
            $options[self::OSS_CONTENT_TYPE] = Util::guessMimeType($path, $contents);
        }
        try {
            //火山引擎的对象存储
            $input = new PutObjectInput($this->bucket);
            // 设置对象访问权限
            $input->setACL($this->aclAccessAuthority);
            // 设置上传目录
            $input->setKey($object);
            // 设置文件的内容
            $input->setContent($contents);
            //执行文件上传
            $this->client->putObject($input);
        } catch (TosClientException $e) {

            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return $this->normalizeResponse($options, $path);
    }

    /**
     * 将文件资源流转换为文件内容字符串上传到火山引擎TOS
     *
     * @param $path
     * @param $resource
     * @param Config $config
     * @return array|false|string[]
     */
    public function writeStream($path, $resource, Config $config)
    {
        //$options = $this->getOptions($this->options, $config);
        // 读取资源流到一个字符串，与 file_get_contents() 一样，但是 stream_get_contents() 是对一个已经打开的资源流进行操作，并将其内容写入一个字符串返回
        $contents = stream_get_contents($resource);
        // 上传到火山引擎TOS
        return $this->write($path, $contents, $config);
    }

    /**
     * 以下方法暂无调用的地方，但功能是已经能实现的
     * 指定一个本地文件目录文件进行上传到火山引擎TOS指定的目录上面
     * @param $path -- 火山引擎TOS指定的目录
     * @param $filePath -- 本地文件目录文件
     * @param Config $config
     * @return array|false|string[]
     */
    public function writeFile($path, $filePath, Config $config)
    {
        $object = $this->applyPathPrefix($path);
        $options = $this->getOptions($this->options, $config);
        $options[self::OSS_CHECK_MD5] = true;
        if (!isset($options[self::OSS_CONTENT_TYPE])) {
            $options[self::OSS_CONTENT_TYPE] = Util::guessMimeType($path, '');
        }
        try {
            $input = new UploadFileInput($this->bucket, $object, $filePath);
            // 设置对象访问权限
            $input->setACL($this->aclAccessAuthority);
            // 直接使用文件路径上传文件
            $this->client->uploadFile($input);
        } catch (TosException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return $this->normalizeResponse($options, $path);
    }

    /**
     * 如果设置上传的目录文件已经在火山引擎TOS存在的话则是进行更新替换文件处理
     *
     * @param $path
     * @param $contents
     * @param Config $config
     * @return array|false|string[]
     */
    public function update($path, $contents, Config $config)
    {
        if (! $config->has('visibility') && ! $config->has('ACL')) {
            //配置项没有配置 visibility 且 ACL 参数 则进入设置对象存储的权限
            //目前我们是通过env配置来进行设置文件的访问权限的
            $config->set(static::$metaMap['ACL'], $this->getObjectACL($path));
        }
        // $this->delete($path);
        return $this->write($path, $contents, $config);
    }

    /**
     * 如果设置上传的目录文件资源流已经在火山引擎TOS存在的话则是进行更新替换文件处理
     *
     * @param string $path
     * @param resource $resource
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        $contents = stream_get_contents($resource);
        return $this->update($path, $contents, $config);
    }

    /**
     * 根据指定的旧文件路径按照新文件路径进行命名处理(其实就是复制旧文件路径后进行删除旧文件)
     *
     * @param $path --火山引擎TOS旧文件的路径
     * @param $newPath --上传到火山引擎TOS新文件路径
     * @return bool
     */
    public function rename($path, $newPath)
    {
        if (! $this->copy($path, $newPath)){
            return false;
        }
        return $this->delete($path);
    }

    /**
     * 根据指定的旧文件路径进行复制到新文件路径
     * @param $path --火山引擎TOS旧文件的路径
     * @param $newPath  --上传到火山引擎TOS新文件路径
     * @return bool
     */
    public function copy($path, $newPath)
    {
        //获取旧文件的真正路径
        $object = $this->applyPathPrefix($path);
        //获取新文件的真正路径
        $newObject = $this->applyPathPrefix($newPath);
        try{
            $input = new CopyObjectInput($this->bucket,  $newObject,  $this->bucket,  $object);
            // 设置目标对象 ACL
            $input->setACL($this->aclAccessAuthority);
            //复制文件
            $this->client->copyObject($input);
        }catch (TosException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return true;
    }

    /**
     * 根据指定的文件路径从火山引擎TOS进行删除对应的文件
     *
     * @param $path
     * @return bool
     */
    public function delete($path)
    {
        //获取旧文件的真正路径
        $object = $this->applyPathPrefix($path);
        try{
            // 删除单个对象
            $input = new DeleteObjectInput($this->bucket, $object);
            //删除文件
            $this->client->deleteObject($input);
        }catch (TosException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return ! $this->has($path);
    }

    /**
     * 根据指定的文件目录进行删除同时也会删除该目录下的子目录以及对应的文件也会全部进行删除
     * 即上传目录下的所包含的文件和目录都会删除
     *
     * @param $dirname
     * @return bool
     */
    public function deleteDir($dirname)
    {
        //获取真正的上传目录
        $dirname = rtrim($this->applyPathPrefix($dirname), '/').'/';
        //根据上传目录循环获取该目录下面所包含的所有子目录和文件列表
        $dirObjects = $this->listDirObjects($dirname, true);
        if(count($dirObjects['objects']) > 0 ){
            foreach($dirObjects['objects'] as $object)
            {
                //所需要删除的文件对象路径数组
                $objects[] =  new ObjectTobeDeleted($object['Key']);
            }
            try {
                //批量删除多文件对象
                $input = new DeleteMultiObjectsInput($this->bucket);
                $input->setObjects($objects);
                $this->client->deleteMultiObjects($input);
            } catch (TosException $e) {
                $this->logErr(__FUNCTION__, $e);
                return false;
            }
        }
        try {
            // 删除单个对象
            $input = new DeleteObjectInput($this->bucket, $dirname);
            //删除文件
            $this->client->deleteObject($input);
        } catch (TosException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return true;
    }

    /**
     * 根据上传目录循环获取该目录下面所包含的所有子目录和文件列表
     *
     * @param string $dirname 指定的上传目录
     * @param bool $recursive 是否递归查询
     * @return array
     */
    public function listDirObjects($dirname = '', $recursive =  false)
    {
        $delimiter = '/';
        $nextMarker = '';
        $maxKeys = 1000;
        //存储结果
        $result = [];
        while(true){
            try {
                // 列举对象
                $input = new ListObjectsInput($this->bucket);
                // 设置一次列举返回的对象最大数量，最大值 1000
                $input->setMaxKeys($maxKeys);
                // 设置列举对象的前缀
                $input->setPrefix($dirname);
                // 设置列举对象的起始位置
                $input->setMarker($nextMarker);
                // 设置列举对象的分隔符，用于模拟列举目录时固定为 '/'
                $input->setDelimiter($delimiter);
                $listObjectInfo = $this->client->listObjects($input);
            } catch (TosException $e) {
                $this->logErr(__FUNCTION__, $e);
                // return false;
                throw $e;
            }
            $nextMarker = $listObjectInfo->getNextMarker(); // 得到nextMarker，从上一次listObjects读到的最后一个文件的下一个文件开始继续获取文件列表
            $objectList = $listObjectInfo->getContents(); // 文件列表
            $prefixList = $listObjectInfo->getCommonPrefixes(); // 目录列表
            if (!empty($objectList)) {
                foreach ($objectList as $objectInfo) {
                    $object['Prefix']       = $dirname;
                    $object['Key']          = $objectInfo->getKey();
                    $object['LastModified'] = $objectInfo->getLastModified();
                    $object['eTag']         = $objectInfo->getETag();
                    //$object['Type']         = $objectInfo->getType();
                    $object['Type']         = "";
                    $object['Size']         = $objectInfo->getSize();
                    $object['StorageClass'] = $objectInfo->getStorageClass();
                    $result['objects'][] = $object;
                }
            }else{
                $result["objects"] = [];
            }
            if (!empty($prefixList)) {
                foreach ($prefixList as $prefixInfo) {
                    $result['prefix'][] = $prefixInfo->getPrefix();
                }
            }else{
                $result['prefix'] = [];
            }
            //递归查询子目录所有文件
            if($recursive){
                foreach( $result['prefix'] as $pfix){
                    $next  =  $this->listDirObjects($pfix , $recursive);
                    $result["objects"] = array_merge($result['objects'], $next["objects"]);
                }
            }
            //没有更多结果了
            if ($nextMarker === '') {
                break;
            }
        }
        return $result;
    }

    /**
     * 根据指定的上传目录进行创建目录
     *
     * @param $dirname -- 指定的上传目录
     * @param Config $config
     * @return false|string[]
     */
    public function createDir($dirname, Config $config)
    {
        //获取实际上传路径
        $object = $this->applyPathPrefix($dirname);
        //获取请求头的配置项数组
        $options = $this->getOptionsFromConfig($config);
        try {
            //获取真正的上传目录
            $dirname = rtrim($object, '/').'/';
            $input = new PutObjectInput($this->bucket);
            // 设置对象访问权限
            $input->setACL($this->aclAccessAuthority);
            // 设置上传目录
            $input->setKey($dirname);
            $this->client->putObject($input);
        } catch (TOsException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return ['path' => $dirname, 'type' => 'dir'];
    }


    /**
     * 根据指定的文件路径设置对应的访问权限，设置公共读或者私有
     *
     * @param $path
     * @param $visibility --设置private为私有   public为公共读
     * @return array|false
     */
    public function setVisibility($path, $visibility)
    {
        $object = $this->applyPathPrefix($path);
        $acl = ($visibility === AdapterInterface::VISIBILITY_PUBLIC) ? self::OSS_ACL_TYPE_PUBLIC_READ : self::OSS_ACL_TYPE_PRIVATE;
        // 设置对象访问权限
        $input = new PutObjectACLInput($this->bucket, $object);
        $input->setACL($acl);
        $this->client->putObjectAcl($input);
        return compact('visibility');
    }

    /**
     * 根据指定的图片路径判断是否已经上传到火山引擎TOS
     *
     * @param $path
     * @return bool
     */
    public function has($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $input = new GetObjectInput($this->bucket,$object);
            $output = $this->client->getObject($input);
            if($output->getStatusCode() == 200) return true;
            return false;
        }catch (\Exception $es){
            return false ;
        }
    }


    /**
     * 根据指定的文件路径进行读取文件数据信息
     *
     * @param $path
     * @return array|false
     */
    public function read($path)
    {
        $result = $this->readObject($path);
        $result['contents'] = (string) $result['raw_contents'];
        unset($result['raw_contents']);
        return $result;
    }

    /**
     * 根据指定的文件资源流进行读取文件数据信息
     *
     * @param $path
     * @return array|false
     */
    public function readStream($path)
    {
        $result = $this->readObject($path);
        $result['stream'] = $result['raw_contents'];
        //用于将文件指针的位置设置为文件的开头
        rewind($result['stream']);
        // Ensure the EntityBody object destruction doesn't close the stream
        $result['raw_contents']->detachStream();
        unset($result['raw_contents']);
        return $result;
    }

    /**
     * 从火山引擎TOS进行读取该文件路径数据信息
     *
     * @param $path
     * @return array
     */
    protected function readObject($path)
    {
        $object = $this->applyPathPrefix($path);
        $input = new GetObjectInput($this->bucket,$object);
        $output = $this->client->getObject($input);
        $result['Body'] = $output->getContent();
        $result = array_merge($result, ['type' => 'file']);
        return $this->normalizeResponse($result, $path);
    }

    /**
     * 获取指定目录下面的所有文件数据信息
     *
     * @param $directory --指定的文件根目录
     * @param $recursive --是否递归循环获取  开启的话就会一直递归获取子目录下的所有文件路径
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $dirObjects = $this->listDirObjects($directory, true);
        $contents = $dirObjects["objects"];
        $result = array_map([$this, 'normalizeResponse'], $contents);
        $result = array_filter($result, function ($value) {
            return $value['path'] !== false;
        });
        return Util::emulateDirectories($result);
    }

    /**
     * 根据指定的文件路径获取该文件的请求头的数据信息
     *
     * @param $path
     * @return array|false
     */
    public function getMetadata($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            // 获取对象元数据
            $output = $this->client->headObject(new HeadObjectInput($this->bucket,  $object));
            $objectMeta = [
                'content-length' => $output->getContentLength(),
                'content-type' => $output->getContentType(),
                'last-modified' => $output->getLastModified(),
            ];
        } catch (TosException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return $objectMeta;
    }

    /**
     * 根据指定的文件路径获取文件大小  == 请求头的content-length值
     *
     * @param $path
     * @return array|false
     */
    public function getSize($path)
    {
        $object = $this->getMetadata($path);
        $object['size'] = $object['content-length'];
        return $object;
    }


    /**
     * 根据指定的文件路径获取文件mime类型  == 请求头的content-type值
     *
     * @param $path
     * @return array|false
     */
    public function getMimetype($path)
    {
        if( $object = $this->getMetadata($path))
            $object['mimetype'] = $object['content-type'];
        return $object;
    }


    /**
     * 根据指定的文件路径获取上传文件时间戳  == 请求头的last-modified值
     *
     * @param $path
     * @return array|false
     */
    public function getTimestamp($path)
    {
        if( $object = $this->getMetadata($path))
            $object['timestamp'] = $object['last-modified'];
        return $object;
    }

    /**
     * 从火山引擎TOS获取指定访问路径的访问权限
     *
     * @param $path
     * @return array|false
     */
    public function getVisibility($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            // 获取对象访问权限
            $output = $this->client->getObjectACL(new GetObjectACLInput($this->bucket, $object));
            $acl = self::OSS_ACL_TYPE_PUBLIC_READ ;
            foreach ($output->getGrants() as $grant) {
                $permission = $grant->getPermission() ;
                if($permission == self::OSS_ACL_TYPE_PRIVATE_PERMISSION){
                    $acl = self::OSS_ACL_TYPE_PRIVATE ;
                    break;
                }
            }
        } catch (TOSException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        if ($acl == self::OSS_ACL_TYPE_PUBLIC_READ ){
            $res['visibility'] = AdapterInterface::VISIBILITY_PUBLIC;
        }else{
            $res['visibility'] = AdapterInterface::VISIBILITY_PRIVATE;
        }
        return $res;
    }


    /**
     * 获取上传到火山引擎TOS的访问地址
     *
     * @param $path
     * @return string
     * @throws Exception
     */
    public function getUrl( $path )
    {
        //if (!$this->has($path)) throw new Exception($path.' not found');
        //访问域名拼接文件的真正上传路径等于该文件的实际访问链接
        //getPathPrefix() 返回 Java STS 接口下发的 pathTemplate（去除 {business} 变量后），
        //上传时 applyPathPrefix 已将其拼入实际 TOS 对象 key，因此生成 URL 也必须带上，否则 URL 与实际存储路径不一致
        return $this->cdnDomain.'/'.ltrim($this->getPathPrefix() . $path, '/') ;
    }

    /**
     * 获取对象存储的访问权限
     *
     * @param $path
     * @return string
     */
    protected function getObjectACL($path)
    {
        $metadata = $this->getVisibility($path);
        return $metadata['visibility'] === AdapterInterface::VISIBILITY_PUBLIC ? self::OSS_ACL_TYPE_PUBLIC_READ : self::OSS_ACL_TYPE_PRIVATE;
    }


    /**
     * 获取所指定返回的数据数组
     *
     * @param array $object
     * @param $path
     * @return array
     */
    protected function normalizeResponse(array $object, $path = null): array
    {
        $result = ['path' => $path ?: $this->removePathPrefix(isset($object['Key']) ? $object['Key'] : $object['Prefix'])];
        $result['dirname'] = Util::dirname($result['path']);
        if (isset($object['LastModified'])) {
            $result['timestamp'] = strtotime($object['LastModified']);
        }
        if (substr($result['path'], -1) === '/') {
            $result['type'] = 'dir';
            $result['path'] = rtrim($result['path'], '/');
            return $result;
        }
        $result = array_merge($result, Util::map($object, static::$resultMap), ['type' => 'file']);
        return $result;
    }

    /**
     * 获取调用TOS的选项数组
     *
     * @param array $options
     * @param Config|null $config
     * @return array
     */
    protected function getOptions(array $options = [], Config $config = null): array
    {
        //合并所有选项数组
        $options = array_merge($this->options, $options);
        if ($config) {
            $options = array_merge($options, $this->getOptionsFromConfig($config));
        }
        //将所有选项数组放在headers请求头
        return array(self::OSS_HEADERS => $options);
    }

    /**
     * 获取请求头的其他配置数据数组
     *
     * @param Config $config
     * @return array
     */
    protected function getOptionsFromConfig(Config $config): array
    {
        $options = [];

        /**
         * Storage::put('path/to/file/file.jpg', $contents, ['ACL'=>'private','visibility'=>'public','mimetype'=>'image/gif']);
         */

        //根据所指定的key数组来替换成实际请求头参数以及参数值
        foreach (static::$metaOptions as $option) {
            if (! $config->has($option)) {
                continue;
            }
            $options[static::$metaMap[$option]] = $config->get($option);
        }
        if ($visibility = $config->get('visibility')) {
            //证明配置项存在可见的参数

            // For local reference
            // $options['visibility'] = $visibility;
            // For external reference
            // 重新设置 x-tos-object-acl 访问权限 如果可见的参数是public证明是公共读的 否则就是私有的
            $options['x-tos-object-acl'] = $visibility === AdapterInterface::VISIBILITY_PUBLIC ? self::OSS_ACL_TYPE_PUBLIC_READ : self::OSS_ACL_TYPE_PRIVATE;
        }
        if ($mimetype = $config->get('mimetype')) {
            //证明配置项存在 MIME 类型

            // For local reference
            // $options['mimetype'] = $mimetype;
            // For external reference
            // 重新设置 Content-Type 为 MIME 类型值
            $options['Content-Type'] = $mimetype;
        }

        return $options;
    }

    /**
     * 是否打印日志记录
     *
     * @param $fun
     * @param $e
     * @return void
     */
    protected function logErr($fun, $e){
        if( $this->debug ){
            Log::error($fun . ": FAILED");
            Log::error($e->getMessage());
        }
    }
}
