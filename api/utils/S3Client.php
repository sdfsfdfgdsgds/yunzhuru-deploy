<?php
/**
 * S3 兼容存储客户端（纯 PHP + curl）
 * 支持 AWS S3、Cloudflare R2、Backblaze B2
 * 使用 AWS Signature V4 签名
 */
class S3Client {
    private $accessKey;
    private $secretKey;
    private $endpoint;  // 如 https://s3.us-east-1.amazonaws.com
    private $bucket;
    private $region;
    private $host;      // 从 endpoint 解析出的 host

    /**
     * @param string $accessKey  Access Key ID
     * @param string $secretKey  Secret Access Key
     * @param string $endpoint   S3 API 端点（含协议，如 https://xxx.r2.cloudflarestorage.com）
     * @param string $bucket     桶名称
     * @param string $region     区域（R2 用 auto，B2 看实际，S3 用 us-east-1 等）
     */
    public function __construct($accessKey, $secretKey, $endpoint, $bucket, $region = 'auto') {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->endpoint = rtrim($endpoint, '/');
        $this->bucket = $bucket;
        $this->region = $region;

        $parsed = parse_url($this->endpoint);
        $this->host = $parsed['host'] ?? '';
    }

    /**
     * 上传内容到桶
     * @param string $objectKey    对象路径（如 config/123.enc）
     * @param string $content      文件内容
     * @param string $contentType  MIME 类型
     * @return array ['code' => 200|500, 'message' => string]
     */
    public function putObject($objectKey, $content, $contentType = 'application/octet-stream') {
        $objectKey = ltrim($objectKey, '/');
        $uri = '/' . $this->bucket . '/' . $objectKey;
        $url = $this->endpoint . $uri;

        $datetime = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $content);

        // 构建规范头（按 key 排序）
        $headers = [
            'content-length'       => strlen($content),
            'content-type'         => $contentType,
            'host'                 => $this->host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'          => $datetime,
        ];
        ksort($headers);

        // 规范请求
        $canonicalHeaders = '';
        $signedHeaderKeys = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeaderKeys[] = strtolower($k);
        }
        $signedHeaders = implode(';', $signedHeaderKeys);

        $canonicalRequest = implode("\n", [
            'PUT',
            $this->uriEncodePath($uri),
            '',  // 无 query string
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        // 待签名字符串
        $scope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        // 签名密钥链
        $signingKey = $this->getSigningKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        // 发送请求
        return $this->curlRequest('PUT', $url, $content, [
            "Authorization: {$authorization}",
            "Content-Type: {$contentType}",
            "Content-Length: " . strlen($content),
            "x-amz-content-sha256: {$payloadHash}",
            "x-amz-date: {$datetime}",
            "Host: {$this->host}",
        ]);
    }

    /**
     * 从本地文件流式上传到桶（不读入内存，支持大文件）
     * @param string $objectKey    对象路径
     * @param string $filePath     本地文件路径
     * @param string $contentType  MIME 类型
     * @return array ['code' => 200|500, 'message' => string]
     */
    public function putObjectFromFile($objectKey, $filePath, $contentType = 'application/octet-stream', $progressCallback = null) {
        $objectKey = ltrim($objectKey, '/');
        $uri = '/' . $this->bucket . '/' . $objectKey;
        $url = $this->endpoint . $uri;

        $fileSize = filesize($filePath);
        $datetime = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        // 大文件用 UNSIGNED-PAYLOAD 避免计算整个文件的 SHA256
        $payloadHash = 'UNSIGNED-PAYLOAD';

        $headers = [
            'content-length'       => $fileSize,
            'content-type'         => $contentType,
            'host'                 => $this->host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'          => $datetime,
        ];
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeaderKeys = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeaderKeys[] = strtolower($k);
        }
        $signedHeaders = implode(';', $signedHeaderKeys);

        $canonicalRequest = implode("\n", [
            'PUT',
            $this->uriEncodePath($uri),
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSigningKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        // 流式上传
        $fp = fopen($filePath, 'r');
        if (!$fp) {
            return ['code' => 500, 'message' => '无法打开文件', 'http_code' => 0];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3600,
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fp,
            CURLOPT_INFILESIZE     => $fileSize,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: {$authorization}",
                "Content-Type: {$contentType}",
                "Content-Length: {$fileSize}",
                "x-amz-content-sha256: {$payloadHash}",
                "x-amz-date: {$datetime}",
                "Host: {$this->host}",
            ],
        ]);

        // 上传进度回调
        if ($progressCallback && is_callable($progressCallback)) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($progressCallback, $fileSize) {
                if ($ulNow > 0) {
                    $progressCallback($fileSize, $ulNow);
                }
                return 0;
            });
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        fclose($fp);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['code' => 200, 'message' => '上传成功', 'http_code' => $httpCode];
        }

        $errorMsg = "HTTP {$httpCode}";
        if ($error) $errorMsg .= " curl_err({$errno}): {$error}";
        if ($response && preg_match('/<Message>(.*?)<\/Message>/s', $response, $m)) {
            $errorMsg .= " S3: {$m[1]}";
        } elseif ($response) {
            $errorMsg .= " body: " . substr($response, 0, 500);
        }
        $errorMsg .= " url: {$effectiveUrl}";
        return ['code' => 500, 'message' => $errorMsg, 'http_code' => $httpCode];
    }

    /**
     * 删除桶中的对象
     * @param string $objectKey 对象路径
     * @return array ['code' => 200|500, 'message' => string]
     */
    public function deleteObject($objectKey) {
        $objectKey = ltrim($objectKey, '/');
        $uri = '/' . $this->bucket . '/' . $objectKey;
        $url = $this->endpoint . $uri;

        $datetime = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', ''); // 空 body

        $headers = [
            'host'                 => $this->host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'          => $datetime,
        ];
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeaderKeys = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeaderKeys[] = strtolower($k);
        }
        $signedHeaders = implode(';', $signedHeaderKeys);

        $canonicalRequest = implode("\n", [
            'DELETE',
            $this->uriEncodePath($uri),
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSigningKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $this->curlRequest('DELETE', $url, '', [
            "Authorization: {$authorization}",
            "x-amz-content-sha256: {$payloadHash}",
            "x-amz-date: {$datetime}",
            "Host: {$this->host}",
        ]);
    }

    /**
     * 生成签名密钥（HMAC 链）
     */
    private function getSigningKey($dateStamp) {
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        return $kSigning;
    }

    /**
     * URI 路径编码（保留 /）
     */
    private function uriEncodePath($path) {
        $segments = explode('/', $path);
        $encoded = array_map(function ($seg) {
            return rawurlencode($seg);
        }, $segments);
        return implode('/', $encoded);
    }

    /**
     * 执行 curl 请求
     */
    private function curlRequest($method, $url, $body, $headers) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'PUT' && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'code' => 200,
                'message' => '操作成功',
                'http_code' => $httpCode,
            ];
        }

        // 尝试从 XML 响应中提取错误信息
        $errorMsg = "HTTP {$httpCode}";
        if ($error) {
            $errorMsg .= " curl: {$error}";
        }
        if ($response && preg_match('/<Message>(.*?)<\/Message>/s', $response, $m)) {
            $errorMsg .= " S3: {$m[1]}";
        }

        return [
            'code' => 500,
            'message' => $errorMsg,
            'http_code' => $httpCode,
        ];
    }
}
