<?php
require_once __DIR__ . '/BucketFeature.php';

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
        // 全局桶凭据在数据库中使用 AES-256-GCM；用户级旧凭据仍可以传入明文。
        $this->accessKey = bucketDecryptSecret((string)$accessKey);
        $this->secretKey = bucketDecryptSecret((string)$secretKey);
        $this->endpoint = rtrim($endpoint, '/');
        $this->bucket = $bucket;
        $this->region = $region;

        $parsed = parse_url($this->endpoint);
        $this->host = $parsed['host'] ?? '';
        // SigV4 的 Host 必须与实际请求一致，非默认端口也属于 Host 的一部分。
        if (!empty($parsed['port'])) {
            $this->host .= ':' . (int)$parsed['port'];
        }
    }

    /**
     * 上传内容到桶
     * @param string $objectKey    对象路径（如 config/123.enc）
     * @param string $content      文件内容
     * @param string $contentType  MIME 类型
     * @param array $extraHeaders  额外对象响应头，例如 Cache-Control
     * @return array ['code' => 200|500, 'message' => string]
     */
    public function putObject($objectKey, $content, $contentType = 'application/octet-stream', array $extraHeaders = []) {
        $objectKey = ltrim($objectKey, '/');
        $uri = '/' . $this->bucket . '/' . $objectKey;
        $encodedUri = $this->uriEncodePath($uri);
        $url = $this->endpoint . $encodedUri;

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
        foreach ($extraHeaders as $key => $value) {
            $headerKey = strtolower(trim((string)$key));
            $headerValue = trim((string)$value);
            if ($headerKey === '' || $headerValue === '') {
                continue;
            }
            if (in_array($headerKey, ['host', 'content-length', 'content-type', 'x-amz-content-sha256', 'x-amz-date'], true)) {
                continue;
            }
            $headers[$headerKey] = $headerValue;
        }
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

        // 发送请求。对象元数据头必须同时参与签名并实际发出。
        $curlHeaders = ["Authorization: {$authorization}"];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        return $this->curlRequest('PUT', $url, $content, $curlHeaders);
    }

    /**
     * 从本地文件流式上传到桶（不读入内存，支持大文件）
     * @param string $objectKey    对象路径
     * @param string $filePath     本地文件路径
     * @param string $contentType  MIME 类型
     * @param callable|null $progressCallback 上传进度回调
     * @param array $extraHeaders 额外对象响应头，例如 Content-Disposition
     * @return array ['code' => 200|500, 'message' => string]
     */
    public function putObjectFromFile($objectKey, $filePath, $contentType = 'application/octet-stream', $progressCallback = null, array $extraHeaders = []) {
        $objectKey = ltrim($objectKey, '/');
        $uri = '/' . $this->bucket . '/' . $objectKey;
        $encodedUri = $this->uriEncodePath($uri);
        $url = $this->endpoint . $encodedUri;

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

        // 允许下载链路为对象写入 Content-Disposition，这样公开桶重定向后仍按应用名保存。
        foreach ($extraHeaders as $key => $value) {
            $headerKey = strtolower(trim((string)$key));
            $headerValue = trim((string)$value);
            if ($headerKey === '' || $headerValue === '') {
                continue;
            }
            if (in_array($headerKey, ['host', 'content-length', 'x-amz-content-sha256', 'x-amz-date'], true)) {
                continue;
            }
            $headers[$headerKey] = $headerValue;
        }
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

        $curlHeaders = ["Authorization: {$authorization}"];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }

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
            CURLOPT_HTTPHEADER     => $curlHeaders,
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
        $encodedUri = $this->uriEncodePath($uri);
        $url = $this->endpoint . $encodedUri;

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
     * 按前缀列举桶中的对象，并在受控的页数上限内自动跟进 ContinuationToken。
     *
     * 方法固定向每页请求传入 encoding-type=url 和 max-keys=1000，避免对象键中的
     * XML 保留字符、中文、空格或加号造成歧义。返回的 key 已解除 URL 编码，
     * next_token 保持服务端返回的不透明值，可直接传入下一次调用。
     *
     * @param string $prefix            只列举以此字符串开头的对象键
     * @param string $continuationToken 从指定的 S3 ContinuationToken 继续列举
     * @param int    $maxPages          本次最多读取页数，范围 1-10，默认 10
     * @return array {
     *     code: int,
     *     message: string,
     *     http_code: int,
     *     objects: array<int, array{key:string,size:int,last_modified:string,etag:string,storage_class:string}>,
     *     truncated: bool,
     *     next_token: string,
     *     pages: int
     * }
     */
    public function listObjectsV2($prefix = 'config/', $continuationToken = '', $maxPages = 10) {
        $prefix = (string)$prefix;
        $requestToken = (string)$continuationToken;
        // 每页 max-keys=1000，强制最多 10 页，将单次扫描限制在 10000 个对象内。
        $maxPages = max(1, min(10, (int)$maxPages));

        $objects = [];
        $pages = 0;
        $lastHttpCode = 0;
        $lastNextToken = $requestToken;
        $seenTokens = [];
        if ($requestToken !== '') {
            $seenTokens[$requestToken] = true;
        }

        while ($pages < $maxPages) {
            $pageResult = $this->listObjectsV2Page($prefix, $requestToken);
            $lastHttpCode = (int)($pageResult['http_code'] ?? 0);

            if ((int)($pageResult['code'] ?? 500) !== 200) {
                return [
                    'code' => 500,
                    'message' => $pageResult['message'] ?? '列举存储桶对象失败',
                    'http_code' => $lastHttpCode,
                    'objects' => $objects,
                    'truncated' => true,
                    'next_token' => $requestToken,
                    'pages' => $pages,
                ];
            }

            $pages++;
            foreach (($pageResult['objects'] ?? []) as $object) {
                $objects[] = $object;
            }

            $truncated = !empty($pageResult['truncated']);
            $lastNextToken = (string)($pageResult['next_token'] ?? '');
            if (!$truncated) {
                return [
                    'code' => 200,
                    'message' => '列举存储桶对象成功',
                    'http_code' => $lastHttpCode,
                    'objects' => $objects,
                    'truncated' => false,
                    'next_token' => '',
                    'pages' => $pages,
                ];
            }

            // IsTruncated=true 时下一页令牌属于必要合同，缺失时不将部分结果误报为完整。
            if ($lastNextToken === '') {
                return [
                    'code' => 500,
                    'message' => 'ListObjectsV2 分页响应缺少 NextContinuationToken',
                    'http_code' => $lastHttpCode,
                    'objects' => $objects,
                    'truncated' => true,
                    'next_token' => '',
                    'pages' => $pages,
                ];
            }
            if (isset($seenTokens[$lastNextToken])) {
                return [
                    'code' => 500,
                    'message' => 'ListObjectsV2 返回了重复的 ContinuationToken',
                    'http_code' => $lastHttpCode,
                    'objects' => $objects,
                    'truncated' => true,
                    'next_token' => $lastNextToken,
                    'pages' => $pages,
                ];
            }

            $seenTokens[$lastNextToken] = true;
            $requestToken = $lastNextToken;
        }

        // 达到调用方给定的页数上限时保留 next_token，方便后续分段扫描。
        return [
            'code' => 200,
            'message' => '已达到 ListObjectsV2 最大分页数',
            'http_code' => $lastHttpCode,
            'objects' => $objects,
            'truncated' => true,
            'next_token' => $lastNextToken,
            'pages' => $pages,
        ];
    }

    /**
     * 读取并解析一个 ListObjectsV2 页面。
     *
     * @param string $prefix
     * @param string $continuationToken
     * @return array
     */
    private function listObjectsV2Page($prefix, $continuationToken) {
        $datetime = gmdate('Ymd\THis\Z');
        $request = $this->buildListObjectsV2Request($prefix, $continuationToken, $datetime);
        $response = $this->curlRequest('GET', $request['url'], '', $request['headers'], true, 20);
        if ((int)($response['code'] ?? 500) !== 200) {
            return $response;
        }

        try {
            $parsed = $this->parseListObjectsV2Xml((string)($response['body'] ?? ''));
        } catch (\Throwable $e) {
            return [
                'code' => 500,
                'message' => '解析 ListObjectsV2 XML 失败：' . $e->getMessage(),
                'http_code' => (int)($response['http_code'] ?? 0),
            ];
        }

        return [
            'code' => 200,
            'message' => '列举存储桶对象成功',
            'http_code' => (int)($response['http_code'] ?? 200),
            'objects' => $parsed['objects'],
            'truncated' => $parsed['truncated'],
            'next_token' => $parsed['next_token'],
        ];
    }

    /**
     * 构建与实际 URL 完全一致的 ListObjectsV2 SigV4 请求。
     *
     * canonical query 和发出的 query 共用同一字符串，避免空格、加号、斜杠、
     * 百分号或中文在二次构建时产生不同编码导致 SignatureDoesNotMatch。
     *
     * @param string $prefix
     * @param string $continuationToken
     * @param string $datetime AWS 格式 UTC 时间，如 20260826T010203Z
     * @return array{url:string,headers:array<int,string>,canonical_uri:string,canonical_query:string,canonical_request:string,authorization:string}
     */
    private function buildListObjectsV2Request($prefix, $continuationToken, $datetime) {
        $uri = '/' . $this->bucket;
        $canonicalUri = $this->uriEncodePath($uri);
        $query = [
            'encoding-type' => 'url',
            'list-type' => '2',
            'max-keys' => '1000',
            'prefix' => (string)$prefix,
        ];
        if ((string)$continuationToken !== '') {
            $query['continuation-token'] = (string)$continuationToken;
        }
        $canonicalQuery = $this->buildCanonicalQuery($query);
        $url = $this->endpoint . $canonicalUri . '?' . $canonicalQuery;

        $dateStamp = substr($datetime, 0, 8);
        $payloadHash = hash('sha256', '');
        $headers = [
            'host' => $this->host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $datetime,
        ];
        ksort($headers, SORT_STRING);

        $canonicalHeaders = '';
        $signedHeaderKeys = [];
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= strtolower($key) . ':' . trim((string)$value) . "\n";
            $signedHeaderKeys[] = strtolower($key);
        }
        $signedHeaders = implode(';', $signedHeaderKeys);
        $canonicalRequest = implode("\n", [
            'GET',
            $canonicalUri,
            $canonicalQuery,
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
        $signature = hash_hmac('sha256', $stringToSign, $this->getSigningKey($dateStamp));
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $curlHeaders = ["Authorization: {$authorization}"];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }

        return [
            'url' => $url,
            'headers' => $curlHeaders,
            'canonical_uri' => $canonicalUri,
            'canonical_query' => $canonicalQuery,
            'canonical_request' => $canonicalRequest,
            'authorization' => $authorization,
        ];
    }

    /**
     * 按 AWS SigV4 规则对 query 的键和值分别做 RFC 3986 编码后排序。
     *
     * @param array $params
     * @return string
     */
    private function buildCanonicalQuery(array $params) {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $pairs[] = [rawurlencode((string)$key), rawurlencode((string)$value)];
        }
        usort($pairs, function ($left, $right) {
            $keyComparison = strcmp($left[0], $right[0]);
            if ($keyComparison !== 0) {
                return $keyComparison;
            }
            return strcmp($left[1], $right[1]);
        });

        return implode('&', array_map(function ($pair) {
            return $pair[0] . '=' . $pair[1];
        }, $pairs));
    }

    /**
     * 解析 ListObjectsV2 XML，同时兼容 AWS/R2/B2 常见的默认命名空间响应。
     *
     * @param string $xmlBody
     * @return array{objects:array<int,array{key:string,size:int,last_modified:string,etag:string,storage_class:string}>,truncated:bool,next_token:string}
     */
    private function parseListObjectsV2Xml($xmlBody) {
        if (!function_exists('simplexml_load_string')) {
            throw new \RuntimeException('运行环境缺少 SimpleXML 扩展');
        }
        if ($xmlBody === '') {
            throw new \RuntimeException('响应为空');
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($xmlBody, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) {
                throw new \RuntimeException('XML 格式无效');
            }

            $objects = [];
            $contentNodes = $xml->xpath('//*[local-name()="Contents"]') ?: [];
            foreach ($contentNodes as $contentNode) {
                $rawKey = $this->readXmlChildValue($contentNode, 'Key');
                if ($rawKey === '') {
                    continue;
                }
                $etag = trim($this->readXmlChildValue($contentNode, 'ETag'));
                if (strlen($etag) >= 2 && $etag[0] === '"' && substr($etag, -1) === '"') {
                    $etag = substr($etag, 1, -1);
                }

                $objects[] = [
                    // encoding-type=url 下使用 rawurldecode，确保对象键中的 + 保持为加号而非空格。
                    'key' => rawurldecode($rawKey),
                    'size' => (int)trim($this->readXmlChildValue($contentNode, 'Size')),
                    'last_modified' => trim($this->readXmlChildValue($contentNode, 'LastModified')),
                    'etag' => $etag,
                    'storage_class' => trim($this->readXmlChildValue($contentNode, 'StorageClass')),
                ];
            }

            $truncated = strtolower(trim($this->readXmlRootValue($xml, 'IsTruncated'))) === 'true';
            $nextToken = $this->readXmlRootValue($xml, 'NextContinuationToken');
            return [
                'objects' => $objects,
                'truncated' => $truncated,
                // ContinuationToken 是不透明字符串，只做 XML 实体解码，不做 URL 解码。
                'next_token' => $nextToken,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    /**
     * 读取当前 XML 节点的直接子元素，不依赖供应商的命名空间前缀。
     *
     * @param \SimpleXMLElement $node
     * @param string $name
     * @return string
     */
    private function readXmlChildValue($node, $name) {
        $nodes = $node->xpath('./*[local-name()="' . $name . '"]') ?: [];
        return isset($nodes[0]) ? (string)$nodes[0] : '';
    }

    /**
     * 读取 ListBucketResult 根节点的直接子元素。
     *
     * @param \SimpleXMLElement $xml
     * @param string $name
     * @return string
     */
    private function readXmlRootValue($xml, $name) {
        $nodes = $xml->xpath('./*[local-name()="' . $name . '"]') ?: [];
        return isset($nodes[0]) ? (string)$nodes[0] : '';
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
    private function curlRequest($method, $url, $body, $headers, $includeResponseBody = false, $timeoutSeconds = 5) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => max(1, (int)$timeoutSeconds),
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
            $result = [
                'code' => 200,
                'message' => '操作成功',
                'http_code' => $httpCode,
            ];
            if ($includeResponseBody) {
                $result['body'] = $response === false ? '' : (string)$response;
            }
            return $result;
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
