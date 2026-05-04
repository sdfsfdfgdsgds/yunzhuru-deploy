<?php
require __DIR__ . '/OSS/vendor/autoload.php';
use OSS\OssClient;
use OSS\Core\OssException;
if($_GET['oss']==9998){
    exit;
    require __DIR__ . '/../../config/db.php';//此时可以直接使用 $pdo 这个变量
    $oss = new OSS();
    /*$result = $oss->downloadToLocal(
        'bt_backup/database/mysql/3_38_93_246/3_38_93_246_2025-12-25_22-00-08_mysql_data.sql.gz',
        '/www/wwwroot/yunzhuru.cn/api/utils/3_38_93_246_2025-12-25_22-00-08_mysql_data.sql.gz');*/
    //$result = $oss->deleteFile('uploads/2029_59a99ae7e3fabdb775fb3f87bc48275b.apk');
    $result = $oss->listFiles('uploads/');
    print_r($result);
}

//删除OSS中残留文件(残留文件定义，就是数据库中无记录，但是OSS中存在)
if ($_GET['oss'] == 9999) {
    exit;
    require __DIR__ . '/../../config/db.php'; // 可直接使用 $pdo
    $oss = new OSS();

    // 计数器
    $normalCount = 0;     // 数据库中存在
    $residualCount = 0;   // OSS 残留文件
    $deleteSuccess = 0;   // 删除成功数量
    $deleteFail = 0;      // 删除失败数量

    // 1. 列出 uploads 目录下的文件
    $result = $oss->listFiles('uploads/', 1, 1000);

    if ($result['code'] !== 200) {
        echo "获取 OSS 文件列表失败\n";
        print_r($result);
        exit;
    }

    $files = $result['files'];

    foreach ($files as $file) {
        $key = $file['key'];

        // 2. 查询数据库中是否存在该 osspath
        $stmt = $pdo->prepare(
            "SELECT id FROM cainiao_apk WHERE osspath = :osspath LIMIT 1"
        );
        $stmt->execute([
            ':osspath' => $key
        ]);

        $exists = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$exists) {
            // OSS 残留文件
            $residualCount++;
            echo "发现残留文件，准备删除：{$key}\n";

            $delResult = $oss->deleteFile($key);

            if ($delResult['code'] === 200) {
                $deleteSuccess++;
                echo "删除成功：{$key}\n";
            } else {
                $deleteFail++;
                echo "删除失败：{$key}，原因：{$delResult['message']}\n";
            }
        } else {
            // 正常文件
            $normalCount++;
            echo "文件正常存在，跳过：{$key}\n";
        }
    }

    echo "====================\n";
    echo "处理完成\n";
    echo "正常文件数量：{$normalCount}\n";
    echo "残留文件数量：{$residualCount}\n";
    echo "删除成功数量：{$deleteSuccess}\n";
    echo "删除失败数量：{$deleteFail}\n";
}


class OSS {
    private $accessKeyId;
    private $accessKeySecret;
    private $endpoint;
    private $bucket;
    private $customDomain;
    private $internalEndpoint;

    public function __construct() {
        $this->accessKeyId = self::getSetting("ossKeyId", "");
        $this->accessKeySecret = self::getSetting("ossKeySecret", "");
        $this->endpoint = self::getSetting("ossendpoint", "");
        $this->bucket = self::getSetting("ossbucket", "");
        $this->customDomain = self::getSetting("ossDomain", "");
        $this->internalEndpoint = self::getSetting("ossinternalEndpoint", "");
    }
    
    public function getSetting($keyName, $default)
    {
        require __DIR__ . '/../../config/db.php';
        $stmt = $pdo->prepare("SELECT key_value FROM cainiao_system_setting WHERE key_name = :key LIMIT 1");
        $stmt->execute([':key' => $keyName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || trim($result['key_value']) === '') {
            return $default;
        }

        return $result['key_value'];
    }
    
    
    // 列出 OSS 中指定路径下的文件（支持分页，默认第一页）
    public function listFiles($path = '', $page = 1, $pageSize = 100)
    {
        try {
            $ossClient = new OssClient(
                $this->accessKeyId,
                $this->accessKeySecret,
                $this->endpoint
            );
    
            // 处理路径
            if ($path !== '' && substr($path, -1) !== '/') {
                $path .= '/';
            }
    
            // OSS 的分页游标
            $marker = '';
            $currentPage = 1;
            $result = null;
    
            // 核心：向前翻 page-1 次
            while ($currentPage <= $page) {
    
                $options = [
                    OssClient::OSS_PREFIX      => $path,
                    OssClient::OSS_DELIMITER   => '/',
                    OssClient::OSS_MARKER      => $marker,
                    OssClient::OSS_MAX_KEYS    => $pageSize,
                ];
    
                $result = $ossClient->listObjects($this->bucket, $options);
    
                // 到达指定页就停
                if ($currentPage === $page) {
                    break;
                }
    
                // 没有下一页，提前结束
                if (!$result->getIsTruncated()) {
                    break;
                }
    
                // 设置下一个游标
                $marker = $result->getNextMarker();
                $currentPage++;
            }
    
            $files = [];
    
            if ($result && $result->getObjectList()) {
                foreach ($result->getObjectList() as $objectInfo) {
                    $key = $objectInfo->getKey();
    
                    if ($key === $path) {
                        continue;
                    }
    
                    $files[] = [
                        'key' => $key,
                        'size' => $objectInfo->getSize(),
                        'last_modified' => $objectInfo->getLastModified(),
                    ];
                }
            }
    
            return [
                'code'        => 200,
                'path'        => $path,
                'page'        => $page,
                'page_size'   => $pageSize,
                'count'       => count($files),
                'has_next'    => $result ? $result->getIsTruncated() : false,
                'next_marker' => $result ? $result->getNextMarker() : null,
                'files'       => $files,
                'message'     => '获取成功'
            ];
    
        } catch (OssException $e) {
            return [
                'code' => 500,
                'message' => '获取列表失败：' . $e->getMessage()
            ];
        }
    }



    // 生成外网签名下载链接
    public function getSignedUrl($fileName, $speedLimit = 245760000, $time = 600) {
        try {
            $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
            
            if (!$ossClient->doesObjectExist($this->bucket, $fileName)) {
                return [
                    'code' => 404,
                    'message' => '文件不存在'
                ];
            }
            //$speedLimit = 2457600 * 100; // 最小245760 大约300KB 这里设置3000KB *2 的速度，大约6M/秒
            $options = [
                OssClient::OSS_TRAFFIC_LIMIT => $speedLimit,
            ];
            $signedUrl = $ossClient->signUrl($this->bucket, $fileName, $time, "GET", $options); // 有效期10分钟
            $parsedUrl = parse_url($signedUrl);
            $customSignedUrl = $this->customDomain . $parsedUrl['path'] . '?' . $parsedUrl['query'];

            return [
                'code' => 200,
                'url' => $customSignedUrl,
                'message' => '文件存在'
            ];
        } catch (OssException $e) {
            return [
                'code' => 500,
                'message' => $e->getMessage()
            ];
        }
    }

    // 上传文件(内网通道)
    public function uploadFile($localFilePath, $ossFilePath) {
        try {
            $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->internalEndpoint);

            // 上传文件
            $ossClient->uploadFile($this->bucket, $ossFilePath, $localFilePath);

            return [
                'code' => 200,
                'message' => '文件上传成功',
                'oss_path' => $ossFilePath
            ];
        } catch (OssException $e) {
            return [
                'code' => 500,
                'message' => '上传失败：' . $e->getMessage()
            ];
        }
    }
    
    //删除oss中的文件
    public function deleteFile($ossFilePath) {
        try {
            $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);

            // 删除文件
            $ossClient->deleteObject($this->bucket, $ossFilePath);

            return [
                'code' => 200,
                'message' => '文件删除成功',
                'oss_path' => $ossFilePath
            ];
        } catch (OssException $e) {
            return [
                'code' => 500,
                'message' => '删除失败：' . $e->getMessage()
            ];
        }
    }
    
    //判断oss中是否存在某个文件
    public function fileExists($ossFilePath) {
        try {
            $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);

            $exists = $ossClient->doesObjectExist($this->bucket, $ossFilePath);

            return [
                'code' => 200,
                'exists' => $exists,
                'oss_path' => $ossFilePath,
                'message' => $exists ? '文件存在' : '文件不存在'
            ];
        } catch (OssException $e) {
            return [
                'code' => 500,
                'exists' => false,
                'message' => '判断失败：' . $e->getMessage()
            ];
        }
    }
    
    // 从OSS通过内网通道下载文件到本地
    public function downloadToLocal($ossFilePath, $localSavePath)
    {
        try {
            $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->internalEndpoint);
    
            // 确保本地目录存在
            $dir = dirname($localSavePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
    
            // 下载文件
            $ossClient->getObject(
                $this->bucket,
                $ossFilePath,
                [OssClient::OSS_FILE_DOWNLOAD => $localSavePath]
            );
    
            return [
                'code' => 200,
                'message' => '文件下载成功',
                'oss_path' => $ossFilePath,
                'local_path' => $localSavePath
            ];
        } catch (OssException $e) {
            return [
                'code' => 500,
                'message' => '下载失败：' . $e->getMessage()
            ];
        }
    }

}
