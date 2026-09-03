<?php
require __DIR__ . '/OSS/vendor/autoload.php';
use OSS\OssClient;
use OSS\Core\OssException;

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
    public function getSignedUrl($fileName, $speedLimit = 245760000, $time = 600, $downloadName = '') {
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
            $contentDisposition = $this->buildContentDispositionHeader($downloadName);
            if ($contentDisposition !== '') {
                // 签名 URL 必须把响应头覆盖参数一起签进去，不能生成后再拼 query。
                $options[OssClient::OSS_QUERY_STRING] = [
                    'response-content-disposition' => $contentDisposition
                ];
            }
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
    public function uploadFile($localFilePath, $ossFilePath, $downloadName = '') {
        try {
            $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->internalEndpoint);

            $options = [];
            $contentDisposition = $this->buildContentDispositionHeader($downloadName);
            if ($contentDisposition !== '') {
                // 临时 OSS 对象也写入下载名，兼容不使用响应头覆盖参数的客户端。
                $options[OssClient::OSS_HEADERS] = [
                    OssClient::OSS_CONTENT_DISPOSTION => $contentDisposition
                ];
            }

            // 上传文件
            $ossClient->uploadFile($this->bucket, $ossFilePath, $localFilePath, $options);

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

    private function buildContentDispositionHeader($downloadName) {
        $name = trim((string)$downloadName);
        if ($name === '') {
            return '';
        }

        // 下载名进入 HTTP 响应头前要去掉路径字符和控制字符，避免头注入或跨平台非法文件名。
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name);
        $name = preg_replace('/[<>:"\/\\\\|?*]+/u', '_', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = trim($name, " ._\t\n\r\0\x0B");
        if ($name === '') {
            return '';
        }
        if (strtolower(substr($name, -4)) !== '.apk') {
            $name .= '.apk';
        }

        $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $asciiName = trim($asciiName, '._-');
        if ($asciiName === '' || strtolower(substr($asciiName, -4)) !== '.apk') {
            $asciiName = 'download.apk';
        }

        $asciiName = addcslashes($asciiName, "\\\"");
        return 'attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }
    
    //删除oss中的文件
    public function deleteFile($ossFilePath) {
        try {
            $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
            $ossClient->setConnectTimeout(2);
            $ossClient->setTimeout(5);

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
