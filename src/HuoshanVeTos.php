<?php

namespace Ykyun\VeTos;

use Exception;
use GuzzleHttp\Client;
use Tos\Model\Enum;
use Tos\Model\PreSignedURLInput;
use Tos\TosClient;

class HuoshanVeTos
{
    protected $city;
    protected $networkType;
    protected $ossClient;
    protected $bucket;

    //Endpoint
    protected $CityURLArray = [
        '北京' => 'tos-cn-beijing',
        '广州' => 'tos-cn-guangzhou',
        '上海' => 'tos-cn-shanghai',
        '中国香港' => 'tos-cn-hongkong',
        '亚太东南(柔佛)' => 'tos-ap-southeast-1',
    ];

    //S3 Endpoint
    protected $CityURLArrayForVPC = [
        '北京' => 'tos-s3-cn-beijing',
        '广州' => 'tos-s3-cn-guangzhou',
        '上海' => 'tos-s3-cn-shanghai',
        '中国香港' => 'tos-s3-cn-shanghai',
        '亚太东南(柔佛)' => 'tos-s3-cn-shanghai',
    ];

    //Region中文名称对应的Region ID
    protected $regionArray = [
        '北京' => 'cn-beijing',
        '广州' => 'cn-guangzhou',
        '上海' => 'cn-shanghai',
        '中国香港' => 'cn-hongkong',
        '亚太东南(柔佛)' => 'ap-southeast-1',
    ];

    public function __construct($city, $networkType, $isInternal, $AccessKeyId, $AccessKeySecret)
    {
        $this->city = $city;
        $this->networkType = $networkType;

        $serverAddress = '';
        if ($networkType == '经典网络') {
            if (!array_key_exists($city, $this->CityURLArray)) {
                throw new Exception("城市不存在");
            }
            $serverAddress .= $this->CityURLArray[$city];
        } else if ($networkType == 'S3') {
            if (!array_key_exists($city, $this->CityURLArrayForVPC)) {
                throw new Exception("城市不存在");
            }
            $serverAddress .= $this->CityURLArrayForVPC[$city];
        } else {
            throw new Exception("\$networkType 必须是 '经典网络' 或 'S3'");
        }
        if($isInternal){
            //是内网
            $serverAddress .= '.ivolces.com';
        }else{
            $serverAddress .= '.volces.com';
        }
        $region = $this->regionArray[$city] ?? '' ;
        if(empty($region)) throw new Exception("城市对应的region不存在");

        $this->ossClient = new TosClient([
            'region' => $region,
            'endpoint' => $serverAddress,
            'ak' => $AccessKeyId,
            'sk' => $AccessKeySecret,
        ]);
    }

    public static function boot($city, $networkType, $isInternal, $AccessKeyId, $AccessKeySecret)
    {
        return new self($city, $networkType, $isInternal, $AccessKeyId, $AccessKeySecret);
    }

    public function setBucket($bucket)
    {
        $this->bucket = $bucket;

        return $this;
    }

    public function uploadFile($key, $file, $options = [])
    {
        $handle = fopen($file, 'r');
        $value  = $this->ossClient->putObject(array_merge([
            'Bucket'        => $this->bucket,
            'Key'           => $key,
            'Content'       => $handle,
            'ContentLength' => filesize($file),
        ], $options));
        fclose($handle);

        return $value;
    }

    public function uploadContent($key, $content, $options = [])
    {
        return $this->ossClient->putObject(array_merge([
            'Bucket'        => $this->bucket,
            'Key'           => $key,
            'Content'       => $content,
            'ContentLength' => strlen($content),
        ], $options));
    }

    public function getPublicUrl($key)
    {
        if ($this->networkType == 'S3') {
            throw new Exception("经典网络才能获取公开 api");
        }

        if (!array_key_exists($this->city, $this->CityURLArray)) {
            throw new Exception("城市不存在");
        }

        return 'http://'.$this->bucket.'.'.$this->CityURLArray[$this->city].'.volces.com'.'/'.$key;
    }

    /**
     * 获取访问url
     * @param $key string 上传的文件url地址
     * @param $expire_time int 设置的有效秒数，秒为单位
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getUrl($key="", $expire_time=0)
    {
        //生成下载对象的预签名 URL
        $input = new PreSignedURLInput(Enum::HttpMethodGet, $this->bucket, $key);
        //设置秒为单位的有效期
        $input->setExpires($expire_time);
        //获取预签名url
        $output = $this->ossClient->preSignedURL($input);
        $url_address = $output->getSignedUrl();
        $httpClient = new Client(
            [
                'timeout' => 10,
                'allow_redirects' => false,
                'http_errors' => false,
            ]
        );
        // 使用预签名 URL 发送 HTTP 请求下载对象
        $output = $httpClient->get($url_address, ['headers' => $output->getSignedHeader(), 'stream' => true]);
        $output->getBody()->close();
        return $url_address;
    }

    public function createBucket($bucketName)
    {
        return $this->ossClient->createBucket(['Bucket' => $bucketName]);
    }

    public function getAllObjectKey($bucketName)
    {
        $objectListing = $this->ossClient->listObjects([
            'Bucket' => $bucketName,
        ]);

        $objectKeys = [];
        foreach ($objectListing->getObjectSummarys() as $objectSummary) {
            $objectKeys[] = $objectSummary->getKey();
        }

        return $objectKeys;
    }

    /**
     * 获取指定文件夹下的所有文件
     *
     * @param string $bucketName 存储容器名称
     * @param string $folder_name 文件夹名
     * @return 指定文件夹下的所有文件
     */
    public function getAllObjectKeyWithPrefix($bucketName, $folder_name, $nextMarker = '')
    {
        $objectKeys = [];

        while (true) {
            $objectListing = $this->ossClient->listObjects([
                'Bucket'  => $bucketName,
                'Prefix'  => $folder_name,
                'MaxKeys' => 1000,
                'Marker'  => $nextMarker,
            ]);

            foreach ($objectListing->getObjectSummarys() as $objectSummary) {
                $objectKeys[] = $objectSummary->getKey();
            }

            $nextMarker = $objectListing->getNextMarker();
            if ($nextMarker === '' || is_null($nextMarker)) {
                break;
            }
        }

        return $objectKeys;
    }

    /**
     * 删除阿里云中存储的文件
     *
     * @param string $bucketName 存储容器名称
     * @param string $key 存储key（文件的路径和文件名）
     * @return void
     */
    public function deleteObject($bucketName, $key)
    {
        if ($bucketName === null) {
            $bucketName = $this->bucket;
        }

        return $this->ossClient->deleteObject([
            'Bucket' => $bucketName,
            'Key'    => $key,
        ]);
    }

    /**
     * 复制存储在阿里云OSS中的Object
     *
     * @param string $sourceBuckt 复制的源Bucket
     * @param string $sourceKey - 复制的的源Object的Key
     * @param string $destBucket - 复制的目的Bucket
     * @param string $destKey - 复制的目的Object的Key
     * @return Models\CopyObjectResult
     */
    public function copyObject($sourceBuckt, $sourceKey, $destBucket, $destKey)
    {
        if ($sourceBuckt === null) {
            $sourceBuckt = $this->bucket;
        }
        if ($destBucket === null) {
            $destBucket = $this->bucket;
        }

        return $this->ossClient->copyObject([
            'SourceBucket' => $sourceBuckt,
            'SourceKey'    => $sourceKey,
            'DestBucket'   => $destBucket,
            'DestKey'      => $destKey,
        ]);
    }

    /**
     * 移动存储在阿里云OSS中的Object
     *
     * @param string $sourceBuckt 复制的源Bucket
     * @param string $sourceKey - 复制的的源Object的Key
     * @param string $destBucket - 复制的目的Bucket
     * @param string $destKey - 复制的目的Object的Key
     * @return Models\CopyObjectResult
     */
    public function moveObject($sourceBuckt, $sourceKey, $destBucket, $destKey)
    {
        if ($sourceBuckt === null) {
            $sourceBuckt = $this->bucket;
        }
        if ($destBucket === null) {
            $destBucket = $this->bucket;
        }

        $result = $this->ossClient->copyObject([
            'SourceBucket' => $sourceBuckt,
            'SourceKey'    => $sourceKey,
            'DestBucket'   => $destBucket,
            'DestKey'      => $destKey,
        ]);

        if (is_object($result) && $result->getETag()) {
            $this->deleteObject($sourceBuckt, $sourceKey);
        }

        return $result;
    }

    /**
     * 获取指定存储容器下的某个文件的元信息
     *
     * @param string $bucketName 存储容器名称
     * @param string $key 存储key（文件的路径和文件名）
     * @return
     */
    public function getObjectMeta($bucketName, $key)
    {
        if ($bucketName === null) {
            $bucketName = $this->bucket;
        }

        return $this->ossClient->getObjectMetadata([
            'Bucket' => $bucketName,
            'Key' => $key,
        ]);
    }
}
