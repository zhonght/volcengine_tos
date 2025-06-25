# Huoshan-oss-storage for Laravel 5+
实现火山引擎的对象存储的TOS Storage扩展，打造Laravel最好的OSS Storage扩展



# 环境要求

- laravel的框架必须满足5.7版本以上
- 引入火山引擎php版本的sdk 2.1版本以上



# 安装



```
执行以下命名进行安装加载依赖

不指定版本
composer require sharexm/huoshan-tos-storage

需指定版本
composer require sharexm/huoshan-tos-storage:^1.0

```



## 配置驱动

1. 通过配置AccessKey和SecretKey来进行配置上传

   在 app/filesystems.php 添加自定义的驱动:

   ```php
   
   'disks'=>[
       ...
   		//火山引擎的对象存储
           'volcengine' => [
               'driver' => 'volcengine',
               //访问权限
               'acl_access_authority' =>  env('VOLCENGINE_ACL_ACCESS_AUTHORITY', ''),
               //是否需要打印出错误日志
               'debug' => env('VOLCENGINE_DEBUG_DEBUG', false),
   
               // 火山引擎区域
               'region' => env('VOLCENGINE_REGION', ''),
               //火山引擎AccessKey
               'access_key_id' => env('VOLCENGINE_ACCESS_KEY', ''),
               //火山引擎SecretKey
               'access_key_secret' => env('VOLCENGINE_SECRET_KEY', ''),
               // 火山引擎桶名称
               'bucket' => env('VOLCENGINE_BUCKET', ''),
               // 外网节点或自定义外部域名
               'endpoint' => env('VOLCENGINE_ENDPOINT', ''),
               // 如果是true话就是使用这个自定义域名
               'cdnDomain'     => env('VOLCENGINE_CND_ENDPOINT', ''),
               // 是否使用https来进行访问
               'ssl'           => env('VOLCENGINE_SSL', false) ,
               // isCName如果是false的话域名就是 bucket + endpoint ，如果是true话域名就是 cdnDomain
               'isCName' =>  env('VOLCENGINE_ISCNAME', false),
           ],
       	
     	...
   ]
   
   
   .env的对应的配置
   
   #火山引擎的对象存储
   FILESYSTEM_DRIVER=volcengine
   #访问权限  private=私有  public-read=公共读  public-read-write=公共读写
   VOLCENGINE_ACL_ACCESS_AUTHORITY="private"
   #是否需要打印出错误日志:true为需要 false为不需要
   VOLCENGINE_DEBUG_DEBUG=false
   #火山引擎的AccessKey
   VOLCENGINE_ACCESS_KEY=
   #火山引擎的SecretKey
   VOLCENGINE_SECRET_KEY=
   #桶名称
   VOLCENGINE_BUCKET=
   #外网节点或自定义外部域名
   VOLCENGINE_ENDPOINT=
   #火山引擎区域
   VOLCENGINE_REGION=
   #是否使用自定义域名
   VOLCENGINE_ISCNAME=
   #如果isCName为true采用此自定义域名
   VOLCENGINE_CND_ENDPOINT=
   #是否使用https来进行访问
   VOLCENGINE_SSL=
   
   
   ```

2. 通过配置STS方式来进行上传

```php
'disks'=>[
    ...
        //火山引擎的对象存储
        'volcengine' => [
            'driver' => 'volcengine',
            //访问权限
            'acl_access_authority' =>  env('VOLCENGINE_ACL_ACCESS_AUTHORITY', ''),
            //是否需要打印出错误日志
            'debug' => env('VOLCENGINE_DEBUG_DEBUG', false),
             //火山引擎的上传模式方式
            'volcengine_upload_way' => env('VOLCENGINE_UPLOAD_WAY', ''),
            //火山引擎请求java接口地址
            'volcengine_java_host_url' => env('VOLCENGINE_JAVA_HOST_URL', ''),
            //火山引擎请求java应用appId
            'volcengine_appid' => env('VOLCENGINE_APPID', ''),
            //火山引擎请求java应用秘钥
            'volcengine_appid_secret' => env('VOLCENGINE_APPID_SECRET', '')
        ],
    ...
]

    
.env的对应的配置
    
#火山引擎的对象存储
FILESYSTEM_DRIVER=volcengine
#访问权限  private=私有  public-read=公共读  public-read-write=公共读写
VOLCENGINE_ACL_ACCESS_AUTHORITY="private"
#是否需要打印出错误日志:true为需要 false为不需要
VOLCENGINE_DEBUG_DEBUG=false
#火山引擎的上传模式方式,默认是明文key去进行上传，TOS_STS表示用STS创建传入临时登录token进行验证上传
VOLCENGINE_UPLOAD_WAY="TOS_STS"
#火山引擎请求java接口地址
VOLCENGINE_JAVA_HOST_URL=""
#火山引擎请求java应用appId,TOS_STS上传模式需要必配此参数
VOLCENGINE_APPID=""
#火山引擎请求java应用秘钥,TOS_STS上传模式需要必配此参数
VOLCENGINE_APPID_SECRET=""
    
    
需要注意的是通过TOS_STS的方式来上传的话，是需要通过调用java提供的接口和相关参数进行验签后返回第1种上传方式的相关配置数据和临时登录token来初始化火山引擎TOS的连接对象  
    
```


在 app/filesystems.php 设置默认的火山引擎驱动:

```php
'default' => 'volcengine',
```

在 app/admin.php 设置上传的火山引擎驱动:
```php
'upload' => [

    // Disk in `config/filesystem.php`.
    'disk' => 'volcengine',

    // Image and file upload path under the disk above.
    'directory' => [
        'image' => 'images',
        'file'  => 'files',
    ],
]
```

## 使用
See [Larave doc for Storage](https://laravel.com/docs/5.2/filesystem#custom-filesystems)
Or you can learn here:

> First you must use Storage facade

```php
use Illuminate\Support\Facades\Storage;
```
> Then You can use all APIs of laravel Storage

```php
//获取火山引擎的驱动，可以替换成相对应的驱动标识
Storage::disk('volcengine');
//获取火山引擎驱动下的访问文件路径
Storage::disk('volcengine')->url($paths);

//获取上传文件对象
$file = $request->file("icon");
//设置文件名
$file_name = md5(uniqid()).".".$file->getClientOriginalExtension();
//设置文件名所上传的目录
$paths = "upload/{$file_name}" ;

//以文件资源流的方式进行上传
// 获取临时文件的真实路径
$tempPath = $file->getRealPath();
// 以只读模式打开文件资源
$resource = fopen($tempPath, 'r');
//以文件资源流的方式进行上传
Storage::putStream($paths, $resource);
//以文件资源流的方式进行上传
Storage::put($paths, $resource);

//以文件内容字符串进行上传
Storage::put($paths, file_get_contents($file));
//获取文件上传访问地址
$uploadUrl = Storage::disk('volcengine')->url($paths);

//将本地文件进行上传到火山引擎TOS == 系统底层代码暂不支持这种上传方式
Storage::putFile($paths, 'local/path/to/local_file.jpg');
//这种方式才能实现上传后是自动在此目录下面生成一个文件
Storage::putFile("upload/test", $file);


//设置指定的文件路径的访问权限 private 私有 public 公共读 只能传这两个
Storage::setVisibility($paths,'private');
//获取指定的文件路径的访问权限
Storage::getVisibility($paths);

//判断指定的文件路径是否存在
Storage::has($paths);
//判断指定的文件路径是否存在
Storage::exists('path/to/file/file.jpg');
//根据指定的文件路径进行获取文件数据信息
Storage::get('path/to/file/file.jpg');

//获取该目录的下的所有文件路径数据信息
Storage::files($directory);
//获取该目录的下的所有文件路径以及子目录的所有文件路径信息
Storage::files($directory,true);
//获取该目录的下的所有文件路径以及子目录的所有文件路径信息即一直递归查询文件
Storage::allFiles($directory);
//获取该目录的下的所有子目录数据信息
Storage::directories($directory);
//获取该目录的下的所有子目录数据信息以及其他所有子目录数据信息即一直递归查询目录
Storage::allDirectories($directory);

//根据指定的文件路径获取文件大小
Storage::size('path/to/file/file.jpg');
//根据指定的文件路径获取文件mime类型 
Storage::mimeType('path/to/file/file.jpg');
//根据指定的文件路径获取上传文件时间戳
Storage::lastModified('path/to/file/file.jpg');

//将原有的文件进行复制成新文件即两个文件内容是一样，例两张图片都是一样只是名称不同
Storage::copy('old/file1.jpg', 'new/file1.jpg');
//将原有的文件进行转移到新文件路径同时删除旧文件(即重命名操作)
Storage::move('old/file1.jpg', 'new/file1.jpg');
//将原有的文件进行重命名为新文件路径
Storage::rename('path/to/file1.jpg', 'path/to/file2.jpg');

//在指定的文件路径添加一个开头内容
Storage::prepend('file.log', '该内容加在最前面');
//在指定的文件路径添加一个结尾内容
Storage::append('file.log', '该内容加在最后面');

//根据指定文件路径进行单独删除
Storage::delete('file.jpg');
 //根据指定文件路径数组进行循环删除
Storage::delete(['file1.jpg', 'file2.jpg']);

$directory = "upload/testdel";
//根据指定的上传目录进行创建目录
Storage::makeDirectory($directory);
//根据指定的上传目录进行删除以及该目录下面的所有目录和文件都会同时删除
Storage::deleteDirectory($directory);

//第一个是新生成文件路径地址，第二个是火山引擎TOS文件所访问的地址，将这个地址的文件进行上传到新文件路径地址，不删除该源文件
Storage::putRemoteFile('target/path/to/file/jacob.jpg', 'http://example.com/jacob.jpg');
//根据指定文件路径获取访问tos文件的地址
Storage::url('path/to/img.jpg')


```

