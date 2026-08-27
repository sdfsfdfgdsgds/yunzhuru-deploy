<?php
/**
 * 配置桶管理的共享合同。
 *
 * 该文件是桶结构、凭据存储、Provider 校验、统计口径和公开 API 地址的
 * Single Source of Truth（单一事实来源）。API、后台推送和 S3 客户端都复用这里的合同。
 */

if (!function_exists('bucketFeatureToday')) {
    /** 返回统计所用的北京日期。 */
    function bucketFeatureToday(): string {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
    }
}

if (!function_exists('bucketPublicApiUrl')) {
    /**
     * 返回写入配置文件的稳定 API 地址。
     *
     * CLI worker 没有 HTTP_HOST，因此优先使用部署变量；只在真实 HTTPS 请求中
     * 使用当前 Host，最后回退到当前正式域名。
     */
    function bucketPublicApiUrl(): string {
        $configured = trim((string)(getenv('YUNZHURU_PUBLIC_BASE_URL') ?: ''));
        if ($configured !== '') {
            return rtrim($configured, '/') . '/api/index.php';
        }

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($host !== '' && preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $host)) {
            $scheme = in_array($https, ['on', '1', 'true'], true) ? 'https' : 'http';
            return $scheme . '://' . $host . '/api/index.php';
        }

        return 'https://zkzam9hoby.top/api/index.php';
    }
}

if (!function_exists('bucketCredentialKey')) {
    /** 将部署密钥稳定派生为 AES-256 的 32 字节密钥。 */
    function bucketCredentialKey(): string {
        $secret = (string)(getenv('BUCKET_CREDENTIAL_KEY') ?: '');
        if ($secret === '') {
            throw new RuntimeException('未配置 BUCKET_CREDENTIAL_KEY');
        }
        return hash('sha256', $secret, true);
    }
}

if (!function_exists('bucketEncryptSecret')) {
    /**
     * 使用 AES-256-GCM 加密桶凭据。已加密的值保持不变，空值不生成密文。
     */
    function bucketEncryptSecret(string $plainText): string {
        if ($plainText === '' || strncmp($plainText, 'enc:v1:', 7) === 0) {
            return $plainText;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            bucketCredentialKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'yunzhuru:s3-bucket:v1',
            16
        );
        if ($cipherText === false) {
            throw new RuntimeException('桶凭据加密失败');
        }
        return 'enc:v1:' . base64_encode($nonce . $tag . $cipherText);
    }
}

if (!function_exists('bucketDecryptSecret')) {
    /**
     * 解密桶凭据；旧版明文数据直接返回，便于生产平滑迁移。
     */
    function bucketDecryptSecret(?string $storedValue): string {
        $storedValue = (string)$storedValue;
        if ($storedValue === '' || strncmp($storedValue, 'enc:v1:', 7) !== 0) {
            return $storedValue;
        }
        $payload = base64_decode(substr($storedValue, 7), true);
        if ($payload === false || strlen($payload) < 29) {
            throw new RuntimeException('桶凭据密文格式错误');
        }
        $nonce = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $cipherText = substr($payload, 28);
        $plainText = openssl_decrypt(
            $cipherText,
            'aes-256-gcm',
            bucketCredentialKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'yunzhuru:s3-bucket:v1'
        );
        if ($plainText === false) {
            throw new RuntimeException('桶凭据解密失败，请检查 BUCKET_CREDENTIAL_KEY');
        }
        return $plainText;
    }
}

if (!function_exists('bucketMaskSecret')) {
    /** 用前后四位构造管理页摘要，不暴露完整凭据。 */
    function bucketMaskSecret(?string $storedValue): string {
        $plainText = bucketDecryptSecret($storedValue);
        // 按 Unicode 字符切分，避免在中文或 Emoji 的 UTF-8 字节中间截断，
        // 否则 json_encode() 会因非法 UTF-8 直接返回 false。
        $characters = preg_split('//u', $plainText, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) return '****';
        $length = count($characters);
        if ($length === 0) return '';
        if ($length <= 8) return '****';
        return implode('', array_slice($characters, 0, 4))
            . '****'
            . implode('', array_slice($characters, -4));
    }
}

if (!function_exists('bucketProviderLabel')) {
    /** 返回 Provider 的人类可读名称。 */
    function bucketProviderLabel(string $provider): string {
        $labels = ['b2' => 'Backblaze B2', 's3' => 'AWS S3', 'r2' => 'Cloudflare R2'];
        return $labels[strtolower($provider)] ?? 'Unknown';
    }
}

if (!function_exists('bucketNormalizeProvider')) {
    /** 限定当前支持的三种 S3 兼容 Provider。 */
    function bucketNormalizeProvider($provider): string {
        $provider = strtolower(trim((string)$provider));
        if (!in_array($provider, ['b2', 's3', 'r2'], true)) {
            throw new InvalidArgumentException('桶类型只支持 Backblaze B2、AWS S3、Cloudflare R2');
        }
        return $provider;
    }
}

if (!function_exists('bucketNormalizeHttpsUrl')) {
    /**
     * 校验 Endpoint 或公开访问地址，防止账号信息、query 和 fragment 混入配置。
     */
    function bucketNormalizeHttpsUrl($value, string $label, bool $rootOnly = false): string {
        $value = trim((string)$value);
        if ($value === '') throw new InvalidArgumentException($label . '为空');
        if (preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new InvalidArgumentException($label . '中不应包含空白或控制字符');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException($label . '必须是完整 HTTPS 地址');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException($label . '中不应包含帐号信息');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException($label . '中不应包含查询参数或片段');
        }
        if (isset($parts['port']) && ((int)$parts['port'] <= 0 || (int)$parts['port'] > 65535)) {
            throw new InvalidArgumentException($label . '端口格式错误');
        }
        $host = strtolower(trim((string)$parts['host'], '[]'));
        if ($host === 'localhost'
            || substr($host, -10) === '.localhost'
            || substr($host, -6) === '.local'
            || substr($host, -9) === '.internal') {
            throw new InvalidArgumentException($label . '必须使用公网主机');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException($label . '不应指向私有或保留网络');
        }
        $path = (string)($parts['path'] ?? '');
        if ($rootOnly && $path !== '' && $path !== '/') {
            throw new InvalidArgumentException($label . '只填写服务根地址，不附加 Bucket 或对象路径');
        }
        return rtrim($value, '/');
    }
}

if (!function_exists('bucketSafeStoredPublicUrl')) {
    /**
     * 对旧数据库中未经新合同校验的公开地址做容错过滤。
     * 读接口对不合法旧值返回空链接，而不是把危险 scheme 交给浏览器。
     */
    function bucketSafeStoredPublicUrl(?string $value): string {
        try {
            return bucketNormalizeHttpsUrl((string)$value, '公开访问地址');
        } catch (Throwable $ignored) {
            return '';
        }
    }
}

if (!function_exists('bucketPublicObjectUrl')) {
    /**
     * 基于公开根地址生成对象直连，对每个路径段分别做 RFC 3986 编码。
     *
     * 该方法同时服务桶文件盘点和应用制品快照，确保两个页面
     * 使用同一套 URL 拼接与安全过滤规则。
     */
    function bucketPublicObjectUrl(string $domain, string $objectKey): string {
        $domain = bucketSafeStoredPublicUrl($domain);
        if ($domain === '') return '';
        $segments = array_map('rawurlencode', explode('/', ltrim($objectKey, '/')));
        return rtrim($domain, '/') . '/' . implode('/', $segments);
    }
}

if (!function_exists('bucketAppConfigObjectKey')) {
    /** 返回壳端约定的单应用加密配置对象路径。 */
    function bucketAppConfigObjectKey(int $appId): string {
        return $appId > 0 ? "config/{$appId}.enc" : '';
    }
}

if (!function_exists('bucketFormatObjectLastModified')) {
    /** 将 S3 LastModified 的 UTC/ISO-8601 时间统一转换为北京时间。 */
    function bucketFormatObjectLastModified(?string $value): string {
        $value = trim((string)$value);
        if ($value === '') return '';
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('Asia/Shanghai'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable $ignored) {
            return '';
        }
    }
}

if (!function_exists('bucketAttachAppFileFields')) {
    /**
     * 为快照桶条目增加本应用文件地址和稳定的元数据结构。
     *
     * 列表接口只拼接公开 URL，不访问远程对象存储；真实文件时间
     * 由详情弹窗的窄接口按需查询，避免应用列表产生 N x M 远程 I/O。
     */
    function bucketAttachAppFileFields(array $items, int $appId): array {
        $objectKey = bucketAppConfigObjectKey($appId);
        foreach ($items as &$item) {
            if (!is_array($item)) $item = [];
            $fileUrl = $objectKey !== ''
                ? bucketPublicObjectUrl((string)($item['domain'] ?? ''), $objectKey)
                : '';
            $item['app_file_key'] = $objectKey;
            $item['app_file_url'] = $fileUrl;
            $item['app_file_present'] = null;
            $item['app_file_status'] = $fileUrl !== '' && $objectKey !== '' ? 'unchecked' : 'unavailable';
            $item['app_file_last_modified'] = '';
            $item['app_file_updated_at'] = '';
            $item['app_file_size'] = 0;
            $item['app_file_http_code'] = 0;
            $item['app_file_metadata_source'] = '';
        }
        unset($item);
        return $items;
    }
}

if (!function_exists('bucketPublicIpAddressIsSafe')) {
    /**
     * 判断单个 IP 是否适合服务端公开对象请求。
     *
     * PHP 7.4 的 FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE 对 IPv4-mapped、
     * IPv4-compatible、NAT64 和 6to4 IPv6 中嵌入的私网 IPv4 判断不完整，
     * 因此需要解出内层 IPv4 后再执行一次公网校验。
     */
    function bucketPublicIpAddressIsSafe(string $ip): bool {
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) return true;

        $embeddedV4 = null;
        $zero10 = str_repeat("\0", 10);
        $zero12 = str_repeat("\0", 12);
        if (substr($packed, 0, 10) === $zero10 && substr($packed, 10, 2) === "\xff\xff") {
            // IPv4-mapped IPv6：::ffff:W.X.Y.Z
            $embeddedV4 = substr($packed, 12, 4);
        } elseif (substr($packed, 0, 12) === $zero12) {
            // 旧式 IPv4-compatible IPv6：::W.X.Y.Z，同时覆盖 :: 和 ::1。
            $embeddedV4 = substr($packed, 12, 4);
        } elseif (substr($packed, 0, 12) === hex2bin('0064ff9b0000000000000000')) {
            // IETF well-known NAT64：64:ff9b::/96。
            $embeddedV4 = substr($packed, 12, 4);
        } elseif (substr($packed, 0, 6) === hex2bin('0064ff9b0001')) {
            // RFC 8215 local-use NAT64：64:ff9b:1::/48，IPv4 位于末 32 位。
            $embeddedV4 = substr($packed, 12, 4);
        } elseif (substr($packed, 0, 2) === "\x20\x02") {
            // 6to4：2002:V4ADDR::/48，IPv4 位于第 16-48 位。
            $embeddedV4 = substr($packed, 2, 4);
        }

        if ($embeddedV4 === null) return true;
        $ipv4 = @inet_ntop($embeddedV4);
        return is_string($ipv4) && filter_var(
            $ipv4,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}

if (!function_exists('bucketPublicUrlSafeIps')) {
    /** 解析公开 URL；只有全部 DNS 结果都是公网地址时才返回 IP 列表。 */
    function bucketPublicUrlSafeIps(string $url): array {
        $host = strtolower(trim((string)(parse_url($url, PHP_URL_HOST) ?: ''), '[]'));
        if ($host === '') return [];

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips[] = $host;
        } else {
            foreach ((array)@gethostbynamel($host) as $ip) $ips[$ip] = $ip;
            if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
                foreach ((array)@dns_get_record($host, DNS_AAAA) as $record) {
                    if (!empty($record['ipv6'])) $ips[$record['ipv6']] = $record['ipv6'];
                }
            }
        }
        if (empty($ips)) return [];
        foreach ($ips as $ip) if (!bucketPublicIpAddressIsSafe((string)$ip)) return [];
        return array_values(array_unique($ips));
    }
}

if (!function_exists('bucketPublicObjectHeaderTime')) {
    /** 从标准 Last-Modified 或 B2 上传毫秒时间中提取真实对象时间。 */
    function bucketPublicObjectHeaderTime(array $headers): array {
        $lastModified = trim((string)($headers['last-modified'] ?? ''));
        if ($lastModified !== '') {
            return [
                'raw' => $lastModified,
                'display' => bucketFormatObjectLastModified($lastModified),
            ];
        }

        $uploadMilliseconds = trim((string)($headers['x-bz-upload-timestamp'] ?? ''));
        if ($uploadMilliseconds !== '' && preg_match('/^\d{11,16}$/', $uploadMilliseconds)) {
            try {
                $seconds = intdiv((int)$uploadMilliseconds, 1000);
                $display = (new DateTimeImmutable('@' . $seconds))
                    ->setTimezone(new DateTimeZone('Asia/Shanghai'))
                    ->format('Y-m-d H:i:s');
                return ['raw' => $uploadMilliseconds, 'display' => $display];
            } catch (Throwable $ignored) {
            }
        }
        return ['raw' => '', 'display' => ''];
    }
}

if (!function_exists('bucketPublicObjectHeaderSize')) {
    /** 从 Range GET 响应中提取完整对象大小。 */
    function bucketPublicObjectHeaderSize(array $headers): int {
        $contentRange = trim((string)($headers['content-range'] ?? ''));
        if ($contentRange !== '' && preg_match('#/(\d+)$#', $contentRange, $match)) {
            return max(0, (int)$match[1]);
        }
        foreach (['x-bz-content-length', 'x-amz-meta-content-length'] as $headerName) {
            $value = trim((string)($headers[$headerName] ?? ''));
            if ($value !== '' && ctype_digit($value)) return max(0, (int)$value);
        }
        return 0;
    }
}

if (!function_exists('bucketReadPublicObjectBody')) {
    /**
     * 从 APK 真正使用的公开 HTTPS 地址读取单个桶对象。
     *
     * 该方法与元数据探测使用相同的 SSRF 防护：DNS 结果必须全部为公网 IP，
     * 连接时固定已校验 IP，开启 TLS 验证且禁止跳转。返回内容仅用于
     * 已鉴权的单应用明文查看接口，默认最多读取 1 MiB。
     */
    function bucketReadPublicObjectBody(string $url, int $maxBytes = 1048576): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('当前服务缺少 cURL 扩展');
        }

        $url = bucketSafeStoredPublicUrl($url);
        if ($url === '') {
            throw new InvalidArgumentException('桶文件公开地址未通过安全校验');
        }
        $safeIps = bucketPublicUrlSafeIps($url);
        if (empty($safeIps)) {
            throw new RuntimeException('桶文件域名未解析到安全公网 IP');
        }

        $maxBytes = max(1024, min($maxBytes, 4 * 1024 * 1024));
        $host = trim((string)(parse_url($url, PHP_URL_HOST) ?: ''), '[]');
        $port = (int)(parse_url($url, PHP_URL_PORT) ?: 443);

        $capture = (object)[
            'headers' => [],
            'body' => '',
            'bytes' => 0,
            'too_large' => false,
        ];
        $handle = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/octet-stream,text/plain;q=0.9,*/*;q=0.1',
                'Accept-Encoding: identity',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
            CURLOPT_USERAGENT => 'yunzhuru-app-config-plaintext/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use ($capture): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if ($trimmed === '') return $length;
                if (stripos($trimmed, 'HTTP/') === 0) {
                    $capture->headers = [];
                    return $length;
                }
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $value = trim(substr($line, $separator + 1));
                    if ($name !== '') $capture->headers[$name] = $value;
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use ($capture, $maxBytes): int {
                $chunkLength = strlen($chunk);
                if ($capture->bytes + $chunkLength > $maxBytes) {
                    $capture->too_large = true;
                    return 0;
                }
                $capture->body .= $chunk;
                $capture->bytes += $chunkLength;
                return $chunkLength;
            },
        ];
        // 显式清除 PHP/libcurl 运行环境代理，确保实际连接仍由
        // 上方已校验的公网 IP 与 CURLOPT_RESOLVE 固定，不经中间代理重新解析。
        $options[CURLOPT_PROXY] = '';
        if (defined('CURLOPT_NOPROXY')) $options[CURLOPT_NOPROXY] = '*';

        // URL 中已是公网 IP 字面量时无需 RESOLVE，尤其 IPv6 主机会与
        // libcurl 的 host:port:[address] 格式产生冒号歧义；域名才固定到已校验 IP。
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $pinnedIp = (string)$safeIps[0];
            if (strpos($pinnedIp, ':') !== false) $pinnedIp = '[' . $pinnedIp . ']';
            $options[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$pinnedIp}"];
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($handle, $options);

        $executed = curl_exec($handle);
        $httpCode = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = trim((string)curl_error($handle));
        curl_close($handle);

        if ($capture->too_large) {
            throw new RuntimeException('桶配置文件超过 ' . $maxBytes . ' 字节安全上限');
        }
        if ($executed === false) {
            throw new RuntimeException('桶配置文件读取失败' . ($curlError !== '' ? '：' . $curlError : ''));
        }
        if ($httpCode === 401 || $httpCode === 403) {
            throw new RuntimeException("桶配置文件公开读取受限（HTTP {$httpCode}），APK 访问该地址时也会收到同样结果");
        }
        if ($httpCode === 404) {
            throw new RuntimeException('该桶中不存在本应用的配置文件（HTTP 404）');
        }
        // 完整正文请求只接受 200；206 代表部分内容，不参与解密。
        if ($httpCode !== 200) {
            throw new RuntimeException("桶配置文件返回异常（HTTP {$httpCode}）");
        }
        if ($capture->body === '') {
            throw new RuntimeException('桶配置文件内容为空');
        }

        $time = bucketPublicObjectHeaderTime((array)$capture->headers);
        return [
            'url' => $url,
            'http_code' => $httpCode,
            'body' => $capture->body,
            'byte_size' => $capture->bytes,
            'cipher_sha256' => hash('sha256', $capture->body),
            'last_modified' => (string)($time['raw'] ?? ''),
            'updated_at' => (string)($time['display'] ?? ''),
        ];
    }
}

if (!function_exists('bucketDecryptAppConfigPayload')) {
    /**
     * 按壳端协议解密 `config/{APPID}.enc`。
     *
     * 文件合同为 Base64(16 字节随机 IV + AES-128-CBC 密文)，密钥与
     * `ConfigHelper::encrypt_json()` 及 Android 壳端保持一致。只有解密后为 JSON 对象
     * 的内容才会返回给页面。
     */
    function bucketDecryptAppConfigPayload(string $encodedPayload): array {
        $encodedPayload = trim($encodedPayload);
        $raw = base64_decode($encodedPayload, true);
        if ($raw === false || strlen($raw) <= 16) {
            throw new RuntimeException('桶配置密文不是有效的 Base64 + IV 封包');
        }

        $iv = substr($raw, 0, 16);
        $cipherText = substr($raw, 16);
        if ($cipherText === '' || strlen($cipherText) % 16 !== 0) {
            throw new RuntimeException('桶配置 AES 密文长度异常');
        }
        $plainText = openssl_decrypt(
            $cipherText,
            'AES-128-CBC',
            '1234567890abcdef',
            OPENSSL_RAW_DATA,
            $iv
        );
        if ($plainText === false) {
            throw new RuntimeException('桶配置 AES 解密失败');
        }

        $decodedObject = json_decode($plainText);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($decodedObject)) {
            throw new RuntimeException('桶配置解密后不是有效 JSON 对象：' . json_last_error_msg());
        }
        $decoded = json_decode($plainText, true);
        $prettyJson = json_encode(
            // 使用 object 视图格式化，避免纯数字键对象或空对象被改写成 JSON 数组。
            $decodedObject,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($prettyJson === false) {
            throw new RuntimeException('桶配置 JSON 格式化失败：' . json_last_error_msg());
        }

        return [
            'config' => $decoded,
            'plain_text' => $plainText,
            'pretty_json' => $prettyJson,
            'plain_byte_size' => strlen($plainText),
            'plain_sha256' => hash('sha256', $plainText),
            'pretty_byte_size' => strlen($prettyJson),
            'pretty_sha256' => hash('sha256', $prettyJson),
        ];
    }
}

if (!function_exists('bucketRequireMatchingAppConfigId')) {
    /**
     * 要求解密配置中的 APPID 与已鉴权应用完全一致。
     *
     * 这是明文返回前的跨应用边界：历史域名、CDN 或桶路由异常时，
     * 即使密文能正常解密，也不将其完整内容返回给另一个应用的所有者。
     */
    function bucketRequireMatchingAppConfigId(array $config, int $expectedAppId): string {
        if ($expectedAppId <= 0) {
            throw new InvalidArgumentException('预期 APPID 格式错误');
        }
        if (array_key_exists('appid', $config)) {
            $value = $config['appid'];
        } elseif (array_key_exists('app_id', $config)) {
            $value = $config['app_id'];
        } else {
            throw new RuntimeException('桶配置缺少 APPID，已停止返回明文');
        }

        if (is_int($value) && $value > 0) {
            $normalized = (string)$value;
        } elseif (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            $normalized = $value;
        } else {
            throw new RuntimeException('桶配置 APPID 类型或格式异常，已停止返回明文');
        }
        if ((int)$normalized !== $expectedAppId) {
            throw new RuntimeException('桶配置 APPID 与目标应用不一致，已停止返回明文');
        }
        return $normalized;
    }
}

if (!function_exists('bucketLoadAppFileMetadata')) {
    /**
     * 并发读取本应用每个“实际展示 URL”的公开对象元数据。
     *
     * 所有连接都经过公网 IP 校验和 DNS 固定，禁止重定向；最多检查 12 个桶，
     * 单连接 6 秒、整批 7 秒预算。时间与地址来自同一 HTTP 对象，
     * 不使用可能已被修改的当前 Endpoint/Bucket 凭据反查历史地址。
     */
    function bucketLoadAppFileMetadata(int $appId, array $items): array {
        $items = bucketAttachAppFileFields($items, $appId);
        if (empty($items) || !function_exists('curl_multi_init')) return $items;

        $multi = curl_multi_init();
        $requests = [];
        $maxFiles = 12;
        $scheduled = 0;
        // 总预算从 DNS 预解析前开始计算，避免多个异常域名把请求时间线性放大。
        $deadline = microtime(true) + 7.0;
        foreach ($items as $index => &$item) {
            if (microtime(true) >= $deadline) {
                $item['app_file_status'] = 'check_timeout';
                continue;
            }
            if ($scheduled >= $maxFiles) {
                $item['app_file_status'] = 'not_checked_limit';
                continue;
            }
            $url = bucketSafeStoredPublicUrl((string)($item['app_file_url'] ?? ''));
            if ($url === '') {
                $item['app_file_status'] = 'unavailable';
                continue;
            }
            $safeIps = bucketPublicUrlSafeIps($url);
            if (microtime(true) >= $deadline) {
                $item['app_file_status'] = 'check_timeout';
                continue;
            }
            if (empty($safeIps)) {
                $item['app_file_status'] = 'unsafe_address';
                continue;
            }

            $host = trim((string)(parse_url($url, PHP_URL_HOST) ?: ''), '[]');
            $port = (int)(parse_url($url, PHP_URL_PORT) ?: 443);
            $capture = (object)['headers' => []];
            $handle = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPGET => true,
                CURLOPT_RANGE => '0-0',
                CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'Pragma: no-cache'],
                CURLOPT_USERAGENT => 'yunzhuru-app-file-check/1.0',
                CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use ($capture): int {
                    $length = strlen($line);
                    $trimmed = trim($line);
                    if ($trimmed === '') return $length;
                    if (stripos($trimmed, 'HTTP/') === 0) {
                        $capture->headers = [];
                        return $length;
                    }
                    $separator = strpos($line, ':');
                    if ($separator !== false) {
                        $name = strtolower(trim(substr($line, 0, $separator)));
                        $value = trim(substr($line, $separator + 1));
                        if ($name !== '') $capture->headers[$name] = $value;
                    }
                    return $length;
                },
            ];
            // 元数据 Range GET 与正文 GET 共用同一 DNS 固定边界：
            // 清空环境代理，防止代理端对桶域名再次解析。
            $options[CURLOPT_PROXY] = '';
            if (defined('CURLOPT_NOPROXY')) $options[CURLOPT_NOPROXY] = '*';

            if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                $pinnedIp = (string)$safeIps[0];
                if (strpos($pinnedIp, ':') !== false) $pinnedIp = '[' . $pinnedIp . ']';
                $options[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$pinnedIp}"];
            }
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }
            curl_setopt_array($handle, $options);
            curl_multi_add_handle($multi, $handle);
            $requests[] = [
                'index' => $index,
                'handle' => $handle,
                'capture' => $capture,
            ];
            $scheduled++;
        }
        unset($item);

        $running = 0;
        do {
            do {
                $multiCode = curl_multi_exec($multi, $running);
            } while ($multiCode === CURLM_CALL_MULTI_PERFORM);
            if ($running <= 0 || $multiCode !== CURLM_OK || microtime(true) >= $deadline) break;
            $remaining = max(0.01, min(0.20, $deadline - microtime(true)));
            if (curl_multi_select($multi, $remaining) === -1) usleep(10000);
        } while (true);
        $budgetExpired = $running > 0 && microtime(true) >= $deadline;

        foreach ($requests as $request) {
            $index = (int)$request['index'];
            $handle = $request['handle'];
            $headers = (array)$request['capture']->headers;
            $httpCode = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $items[$index]['app_file_http_code'] = $httpCode;
            $items[$index]['app_file_metadata_source'] = 'public_url';
            if ($httpCode >= 200 && $httpCode < 300) {
                $time = bucketPublicObjectHeaderTime($headers);
                $items[$index]['app_file_present'] = 1;
                $items[$index]['app_file_status'] = 'present';
                $items[$index]['app_file_last_modified'] = (string)$time['raw'];
                $items[$index]['app_file_updated_at'] = (string)$time['display'];
                $items[$index]['app_file_size'] = bucketPublicObjectHeaderSize($headers);
            } elseif ($httpCode === 404) {
                $items[$index]['app_file_present'] = 0;
                $items[$index]['app_file_status'] = 'missing';
            } elseif ($httpCode === 401 || $httpCode === 403) {
                $items[$index]['app_file_status'] = 'restricted';
            } elseif ($httpCode === 0 && $budgetExpired) {
                $items[$index]['app_file_status'] = 'check_timeout';
            } else {
                $items[$index]['app_file_status'] = 'check_failed';
            }
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
        return $items;
    }
}

if (!function_exists('bucketInferRegion')) {
    /** 从官方 Endpoint 推导 B2/S3 Region，R2 固定为 auto。 */
    function bucketInferRegion(string $provider, string $endpoint): string {
        $host = strtolower((string)(parse_url($endpoint, PHP_URL_HOST) ?: ''));
        if ($provider === 'r2') return 'auto';
        if ($provider === 'b2' && preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/', $host, $match)) {
            return $match[1];
        }
        if ($provider === 's3') {
            if ($host === 's3.amazonaws.com') return 'us-east-1';
            if (preg_match('/^s3[.-]([a-z0-9-]+)\.amazonaws\.com(?:\.cn)?$/', $host, $match)) {
                return $match[1];
            }
        }
        return '';
    }
}

if (!function_exists('bucketValidateEndpoint')) {
    /** 校验 Provider 与官方 Endpoint 的对应关系。 */
    function bucketValidateEndpoint(string $provider, string $endpoint): void {
        $host = strtolower((string)(parse_url($endpoint, PHP_URL_HOST) ?: ''));
        if ($provider === 'b2' && !preg_match('/^s3\.[a-z0-9-]+\.backblazeb2\.com$/', $host)) {
            throw new InvalidArgumentException('B2 Endpoint 应类似 https://s3.us-west-004.backblazeb2.com');
        }
        if ($provider === 'r2' && !preg_match('/(^|\.)r2\.cloudflarestorage\.com$/', $host)) {
            throw new InvalidArgumentException('R2 Endpoint 应类似 https://ACCOUNT_ID.r2.cloudflarestorage.com');
        }
        if ($provider === 's3' && !preg_match('/^s3(?:[.-][a-z0-9-]+)?\.amazonaws\.com(?:\.cn)?$/', $host)) {
            throw new InvalidArgumentException('S3 Endpoint 应类似 https://s3.ap-east-1.amazonaws.com');
        }
    }
}

if (!function_exists('bucketNormalizeRecord')) {
    /**
     * 合并新增/编辑输入并执行完整服务端校验。
     * 编辑时敏感字段为空或包含脱敏占位符，表示继续使用数据库原值。
     */
    function bucketNormalizeRecord(array $input, ?array $existing = null): array {
        $existing = $existing ?: [];
        $pick = static function (string $key, $default = '') use ($input, $existing) {
            if (array_key_exists($key, $input)) return $input[$key];
            if (array_key_exists($key, $existing)) return $existing[$key];
            return $default;
        };

        $provider = bucketNormalizeProvider($pick('provider', 's3'));
        $name = mb_substr(trim((string)$pick('name')), 0, 100, 'UTF-8');
        $bucket = trim((string)$pick('bucket'));
        if ($name === '') throw new InvalidArgumentException('桶名称为空');
        if ($bucket === '') throw new InvalidArgumentException('Bucket 为空');
        if (preg_match('~[\\\\/?#]~', $bucket)) throw new InvalidArgumentException('Bucket 名称中不应包含路径字符');

        $endpoint = bucketNormalizeHttpsUrl($pick('endpoint'), 'Endpoint', true);
        $domain = bucketNormalizeHttpsUrl($pick('domain'), '公开访问地址');
        if (strpos($domain, ',') !== false) {
            throw new InvalidArgumentException('公开访问地址中不应包含英文逗号');
        }
        bucketValidateEndpoint($provider, $endpoint);

        $region = strtolower(trim((string)$pick('region')));
        if ($provider === 'r2') {
            $region = 'auto';
        } elseif ($region === '' || $region === 'auto') {
            $region = bucketInferRegion($provider, $endpoint);
        }
        if ($region === '') throw new InvalidArgumentException(bucketProviderLabel($provider) . ' 需要填写 Region');
        if (!preg_match('/^[a-z0-9-]{2,64}$/', $region)) throw new InvalidArgumentException('Region 格式错误');

        $credential = static function (string $field, array $aliases = []) use ($input, $existing): string {
            // 平台登录资料是可选字段，仅通过显式 clear 标记删除，
            // 避免把编辑时的空输入误解为删除。
            $clearField = 'clear_' . $field;
            if (in_array($field, ['login_account', 'login_password'], true)
                && !empty($input[$clearField])) {
                return '';
            }
            $incomingFound = false;
            $incoming = '';
            foreach (array_merge([$field], $aliases) as $key) {
                if (array_key_exists($key, $input)) {
                    $incomingFound = true;
                    $incoming = trim((string)$input[$key]);
                    break;
                }
            }
            // 旧管理页可能会回传纯占位符；只识别完整占位值，
            // 合法新凭据中即使包含星号也能正常轮换。
            if ($incomingFound && $incoming !== '' && $incoming !== '****') return $incoming;
            return bucketDecryptSecret((string)($existing[$field] ?? ''));
        };

        $loginAccount = $credential('login_account', ['account']);
        $loginPassword = $credential('login_password', ['password']);
        $accessKey = $credential('access_key');
        $secretKey = $credential('secret_key');
        if ($accessKey === '') throw new InvalidArgumentException('Access Key 为空');
        if ($secretKey === '') throw new InvalidArgumentException('Secret Key 为空');

        return [
            'name' => $name,
            'provider' => $provider,
            'login_account' => mb_substr($loginAccount, 0, 255, 'UTF-8'),
            'login_password' => mb_substr($loginPassword, 0, 512, 'UTF-8'),
            'note' => mb_substr(trim((string)$pick('note')), 0, 512, 'UTF-8'),
            'access_key' => mb_substr($accessKey, 0, 255, 'UTF-8'),
            'secret_key' => mb_substr($secretKey, 0, 512, 'UTF-8'),
            'endpoint' => $endpoint,
            'bucket' => mb_substr($bucket, 0, 255, 'UTF-8'),
            'region' => $region,
            'domain' => $domain,
            'enabled' => (int)$pick('enabled', 1) === 0 ? 0 : 1,
            'inject' => (int)$pick('inject', 1) === 0 ? 0 : 1,
        ];
    }
}

if (!function_exists('bucketMysqlColumnExists')) {
    /** 检查 MySQL/MariaDB 数据表字段是否存在。 */
    function bucketMysqlColumnExists(PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column");
        $stmt->execute([':column' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('bucketEnsureMysqlColumn')) {
    /** 以幂等方式增加 MySQL/MariaDB 字段。 */
    function bucketEnsureMysqlColumn(PDO $pdo, string $table, string $column, string $definition): void {
        if (!bucketMysqlColumnExists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

if (!function_exists('bucketMysqlIndexExists')) {
    /** 检查指定索引是否存在。表名和索引名仅由内部常量传入。 */
    function bucketMysqlIndexExists(PDO $pdo, string $table, string $index): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=:index_name');
        $stmt->execute([':table' => $table, ':index_name' => $index]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('bucketEnsureMysqlIndex')) {
    /** 以幂等方式增加内部定义的普通或唯一索引。 */
    function bucketEnsureMysqlIndex(
        PDO $pdo,
        string $table,
        string $index,
        string $columns,
        bool $unique = false
    ): void {
        if (bucketMysqlIndexExists($pdo, $table, $index)) return;
        $type = $unique ? 'UNIQUE KEY' : 'KEY';
        $pdo->exec("ALTER TABLE `{$table}` ADD {$type} `{$index}` ({$columns})");
    }
}

if (!function_exists('bucketEnsureMysqlVarcharLength')) {
    /** 仅在现有 VARCHAR 长度不足时执行 ALTER，避免 API 请求反复发起 DDL。 */
    function bucketEnsureMysqlVarcharLength(PDO $pdo, string $table, string $column, int $length, string $nullClause = 'NOT NULL'): void {
        $stmt = $pdo->prepare(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1'
        );
        $stmt->execute([':table' => $table, ':column' => $column]);
        $currentLength = (int)$stmt->fetchColumn();
        if ($currentLength > 0 && $currentLength < $length) {
            $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` varchar({$length}) {$nullClause}");
        }
    }
}

if (!function_exists('bucketMigratePlaintextCredentials')) {
    /**
     * 将旧版明文凭据显式迁移为 AES-256-GCM。
     * 上线时必须先确认所有运行实例都已具备解密能力，再由部署步骤显式调用；
     * 不绑定在普通 API 的结构自检中，避免滚动发布期间旧实例读到密文。
     */
    function bucketMigratePlaintextCredentials(PDO $pdo): void {
        if ((string)(getenv('BUCKET_CREDENTIAL_KEY') ?: '') === '') return;
        $rows = $pdo->query('SELECT id, login_account, login_password, access_key, secret_key FROM cainiao_s3_bucket')
            ->fetchAll(PDO::FETCH_ASSOC);
        // 凭据存储形式迁移不是管理员编辑，保留原有业务更新时间。
        $update = $pdo->prepare('UPDATE cainiao_s3_bucket SET login_account=:login_account, login_password=:login_password, access_key=:access_key, secret_key=:secret_key, updated_at=updated_at WHERE id=:id');
        foreach ($rows as $row) {
            $changed = false;
            $values = [':id' => (int)$row['id']];
            foreach (['login_account', 'login_password', 'access_key', 'secret_key'] as $field) {
                $value = (string)($row[$field] ?? '');
                if ($value !== '' && strncmp($value, 'enc:v1:', 7) !== 0) $changed = true;
                $values[':' . $field] = bucketEncryptSecret($value);
            }
            if ($changed) $update->execute($values);
        }
    }
}

if (!function_exists('bucketMigrateLegacyProviderLabels')) {
    /**
     * 旧版页面默认把所有 S3 兼容桶写成 s3。仅对可由官方域名确定的
     * B2/R2 记录做无损归一；其他旧端点保留原值，不擅自更改分发链路。
     */
    function bucketMigrateLegacyProviderLabels(PDO $pdo): void {
        $rows = $pdo->query('SELECT id, provider, endpoint FROM cainiao_s3_bucket')->fetchAll(PDO::FETCH_ASSOC);
        // Provider 归一是结构迁移，不应篡改管理员最后编辑时间。
        $update = $pdo->prepare('UPDATE cainiao_s3_bucket SET provider=:provider, updated_at=updated_at WHERE id=:id');
        foreach ($rows as $row) {
            if (strtolower((string)($row['provider'] ?? '')) !== 's3') continue;
            $host = strtolower((string)(parse_url((string)($row['endpoint'] ?? ''), PHP_URL_HOST) ?: ''));
            $provider = '';
            if (preg_match('/^s3\.[a-z0-9-]+\.backblazeb2\.com$/', $host)) $provider = 'b2';
            if (preg_match('/(^|\.)r2\.cloudflarestorage\.com$/', $host)) $provider = 'r2';
            if ($provider !== '') $update->execute([':provider' => $provider, ':id' => (int)$row['id']]);
        }
    }
}

if (!function_exists('ensureInjectBucketSnapshotSchema')) {
    /**
     * 初始化注入制品的配置桶快照表。
     *
     * 快照表故意不建立任务、应用或桶外键：注入任务会按保留天数清理，
     * 桶管理记录也可能被删除，而制品当时写入的公开地址需要独立保留。
     */
    function ensureInjectBucketSnapshotSchema(PDO $pdo): void {
        static $ready = [];
        $key = spl_object_hash($pdo);
        if (!empty($ready[$key])) return;
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            $ready[$key] = true;
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_inject_bucket_snapshot` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `task_id` int NOT NULL,
          `attempt_no` int unsigned NOT NULL DEFAULT 1,
          `apk_id` int NOT NULL,
          `user_id` int NOT NULL,
          `status` varchar(24) NOT NULL DEFAULT 'prepared',
          `selection_mode` varchar(32) NOT NULL DEFAULT 'global_inject',
          `evidence` varchar(32) NOT NULL DEFAULT 'runtime_snapshot',
          `buckets_json` json NOT NULL,
          `exact_buckets_csv` mediumtext NOT NULL,
          `replacement_count` int unsigned NOT NULL DEFAULT 0,
          `template_id` int NOT NULL DEFAULT 0,
          `template_version` varchar(50) NOT NULL DEFAULT '',
          `artifact_path` varchar(1024) NOT NULL DEFAULT '',
          `artifact_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
          `prepared_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `completed_at` datetime DEFAULT NULL,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_snapshot_task_attempt` (`task_id`,`attempt_no`),
          KEY `idx_snapshot_apk_status_completed` (`apk_id`,`status`,`completed_at`,`id`),
          KEY `idx_snapshot_user_completed` (`user_id`,`completed_at`,`id`),
          KEY `idx_snapshot_status_prepared` (`status`,`prepared_at`),
          KEY `idx_snapshot_artifact_sha256` (`artifact_sha256`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='注入制品静态配置桶快照'");

        // 兼容曾运行过单任务唯一键候选版的环境。CREATE TABLE IF NOT EXISTS
        // 不会升级现存结构，因此字段和索引必须逐项幂等收敛。
        bucketEnsureMysqlColumn(
            $pdo,
            'cainiao_inject_bucket_snapshot',
            'attempt_no',
            "int unsigned NOT NULL DEFAULT 1 AFTER `task_id`"
        );
        $pdo->exec('UPDATE cainiao_inject_bucket_snapshot SET attempt_no=1 WHERE attempt_no IS NULL OR attempt_no<1');
        bucketEnsureMysqlIndex(
            $pdo,
            'cainiao_inject_bucket_snapshot',
            'uniq_snapshot_task_attempt',
            '`task_id`,`attempt_no`',
            true
        );
        if (bucketMysqlIndexExists($pdo, 'cainiao_inject_bucket_snapshot', 'uniq_snapshot_task')) {
            $pdo->exec('ALTER TABLE `cainiao_inject_bucket_snapshot` DROP INDEX `uniq_snapshot_task`');
        }
        bucketEnsureMysqlIndex(
            $pdo,
            'cainiao_inject_bucket_snapshot',
            'idx_snapshot_apk_status_completed',
            '`apk_id`,`status`,`completed_at`,`id`'
        );
        if (bucketMysqlIndexExists($pdo, 'cainiao_inject_bucket_snapshot', 'idx_snapshot_apk_completed')) {
            $pdo->exec('ALTER TABLE `cainiao_inject_bucket_snapshot` DROP INDEX `idx_snapshot_apk_completed`');
        }

        $ready[$key] = true;
    }
}

if (!function_exists('bucketNormalizePublicSnapshotRows')) {
    /**
     * 将同一次桶查询结果归一为可持久的公开快照。
     * 仅保留 ID、名称、Provider 和公开读域名，任何凭据都不进入快照。
     */
    function bucketNormalizePublicSnapshotRows(array $bucketRows, array $currentRows = []): array {
        $attachCurrentState = func_num_args() >= 2;
        $normalized = [];
        $seen = [];
        foreach ($bucketRows as $row) {
            if (!is_array($row)) continue;
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) continue;
            $seen[$id] = true;
            $normalized[] = [
                'id' => $id,
                // 快照必须保留构建当时的原值，以后改名或换域名不回写历史。
                'name' => (string)($row['name'] ?? ''),
                'provider' => (string)($row['provider'] ?? ''),
                'domain' => (string)($row['domain'] ?? ''),
            ];
        }
        return $attachCurrentState
            ? bucketMergeSnapshotCurrentState($normalized, $currentRows)
            : $normalized;
    }
}

if (!function_exists('bucketMergeSnapshotCurrentState')) {
    /**
     * 纯函数：在不改写快照 name/provider/domain 的前提下附加当前状态。
     * 当前桶已删除时标记 deleted；已改名或改域名时只写 current_* 和 changed。
     */
    function bucketMergeSnapshotCurrentState(array $snapshotRows, array $currentRows): array {
        $snapshots = bucketNormalizePublicSnapshotRows($snapshotRows);
        $currentMap = [];
        foreach ($currentRows as $rawCurrent) {
            if (!is_array($rawCurrent)) continue;
            $normalizedCurrent = bucketNormalizePublicSnapshotRows([$rawCurrent]);
            if (empty($normalizedCurrent)) continue;
            $current = $normalizedCurrent[0];
            $current['enabled'] = array_key_exists('enabled', $rawCurrent)
                ? ((int)$rawCurrent['enabled'] === 1 ? 1 : 0)
                : 1;
            $currentMap[(int)$current['id']] = $current;
        }

        $result = [];
        foreach ($snapshots as $snapshot) {
            $id = (int)$snapshot['id'];
            $snapshot['provider_label'] = (string)$snapshot['provider'] !== ''
                ? bucketProviderLabel((string)$snapshot['provider'])
                : '';
            if (!isset($currentMap[$id])) {
                $snapshot['current_name'] = '';
                $snapshot['current_provider'] = '';
                $snapshot['current_domain'] = '';
                $snapshot['current_state'] = 'deleted';
                $snapshot['state'] = 'deleted';
                $snapshot['changed'] = 1;
                $result[] = $snapshot;
                continue;
            }
            $current = $currentMap[$id];
            $snapshot['current_name'] = (string)$current['name'];
            $snapshot['current_provider'] = (string)$current['provider'];
            $snapshot['current_domain'] = (string)$current['domain'];
            $changed = (
                (string)$snapshot['name'] !== (string)$current['name']
                || (string)$snapshot['provider'] !== (string)$current['provider']
                || (string)$snapshot['domain'] !== (string)$current['domain']
            ) ? 1 : 0;
            // 停用会直接影响当前可用性，优先展示；changed 字段
            // 仍保留改名或换域名信息，页面会同时展示新值。
            if ((int)$current['enabled'] !== 1) {
                $currentState = 'disabled';
            } elseif ($changed === 1) {
                $currentState = 'changed';
            } else {
                $currentState = 'current';
            }
            $snapshot['current_state'] = $currentState;
            $snapshot['state'] = $currentState;
            $snapshot['changed'] = $changed;
            $result[] = $snapshot;
        }
        return $result;
    }
}

if (!function_exists('bucketDecodeLegacyTaskBucketIds')) {
    /**
     * 按 worker 的历史判断解析 bucket_ids。
     * 非空 JSON 数组只能证明“当时选择过”；NULL、[] 和异常 JSON
     * 会触发当时的全局 inject 回退，具体历史集合没有留存。
     */
    function bucketDecodeLegacyTaskBucketIds($rawBucketIds): array {
        $decoded = is_array($rawBucketIds)
            ? $rawBucketIds
            : json_decode((string)$rawBucketIds, true);
        if (!is_array($decoded) || empty($decoded)) {
            return [
                'selection_mode' => 'global_inject',
                'evidence' => 'unknown',
                'bucket_ids' => [],
            ];
        }

        $ids = [];
        foreach ($decoded as $value) {
            $id = (int)$value;
            if ($id > 0) $ids[$id] = $id;
        }
        return [
            'selection_mode' => 'explicit_ids',
            'evidence' => 'legacy_inferred',
            'bucket_ids' => array_values($ids),
        ];
    }
}

if (!function_exists('bucketSnapshotLatestAttempt')) {
    /** 读取某个任务最新的构建尝试，供重试保留旧成功制品使用。 */
    function bucketSnapshotLatestAttempt(PDO $pdo, int $taskId): ?array {
        if ($taskId <= 0) return null;
        $stmt = $pdo->prepare("SELECT id, task_id, attempt_no, status, completed_at
            FROM cainiao_inject_bucket_snapshot
            WHERE task_id=:task_id
            ORDER BY attempt_no DESC, id DESC
            LIMIT 1");
        $stmt->execute([':task_id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('bucketSnapshotRuntimeAttempt')) {
    /**
     * 记住当前 worker 进程里某任务正在处理的尝试号。
     * 传入 0 会在新一轮任务开始时清除旧记忆。
     */
    function bucketSnapshotRuntimeAttempt(int $taskId, ?int $attemptNo = null): int {
        static $attempts = [];
        if ($taskId <= 0) return 0;
        if ($attemptNo !== null) {
            if ($attemptNo > 0) {
                $attempts[$taskId] = $attemptNo;
            } else {
                unset($attempts[$taskId]);
            }
        }
        return (int)($attempts[$taskId] ?? 0);
    }
}

if (!function_exists('bucketPrepareInjectSnapshot')) {
    /**
     * 在修改壳配置时保存运行时快照。同一任务重试会新建尝试号，
     * 已成功制品保持不变；同一次未完成构建的重入则幂等更新 prepared 记录。
     */
    function bucketPrepareInjectSnapshot(
        PDO $pdo,
        int $taskId,
        int $apkId,
        int $userId,
        string $selectionMode,
        array $bucketRows,
        int $replacementCount,
        int $templateId = 0,
        string $templateVersion = '',
        string $exactBucketsCsv = ''
    ): int {
        if ($taskId <= 0 || $apkId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('配置桶快照的任务、应用或用户 ID 错误');
        }
        ensureInjectBucketSnapshotSchema($pdo);
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return 0;

        $selectionMode = in_array($selectionMode, ['explicit_ids', 'global_inject'], true)
            ? $selectionMode
            : 'global_inject';
        // 列表必须与真正写入 APK 的行一一对应。旧数据若含非标准
        // 公开地址仍保留原值；前端只将 HTTP(S) 渲染为可点击链接。
        $buckets = bucketNormalizePublicSnapshotRows($bucketRows);
        $bucketsJson = json_encode($buckets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($bucketsJson === false) {
            throw new RuntimeException('配置桶公开快照序列化失败');
        }
        if (func_num_args() < 10) {
            $domains = [];
            foreach ($buckets as $bucket) {
                if ((string)$bucket['domain'] !== '') $domains[] = (string)$bucket['domain'];
            }
            $exactBucketsCsv = implode(',', $domains);
        }

        $latest = bucketSnapshotLatestAttempt($pdo, $taskId);
        $attemptNo = $latest && (string)$latest['status'] === 'prepared'
            ? (int)$latest['attempt_no']
            : (($latest ? (int)$latest['attempt_no'] : 0) + 1);
        if ($attemptNo <= 0) $attemptNo = 1;

        $stmt = $pdo->prepare("INSERT INTO cainiao_inject_bucket_snapshot
            (task_id, attempt_no, apk_id, user_id, status, selection_mode, evidence, buckets_json,
             exact_buckets_csv, replacement_count, template_id, template_version,
             artifact_path, artifact_sha256, prepared_at, completed_at)
            VALUES
            (:task_id, :attempt_no, :apk_id, :user_id, 'prepared', :selection_mode, 'runtime_snapshot', CAST(:buckets_json AS JSON),
             :exact_buckets_csv, :replacement_count, :template_id, :template_version,
             '', '', NOW(), NULL)
            ON DUPLICATE KEY UPDATE
              apk_id=VALUES(apk_id), user_id=VALUES(user_id), status='prepared',
              selection_mode=VALUES(selection_mode), evidence='runtime_snapshot',
              buckets_json=VALUES(buckets_json), exact_buckets_csv=VALUES(exact_buckets_csv),
              replacement_count=VALUES(replacement_count), template_id=VALUES(template_id),
              template_version=VALUES(template_version), artifact_path='', artifact_sha256='',
              prepared_at=NOW(), completed_at=NULL");
        $stmt->execute([
            ':task_id' => $taskId,
            ':attempt_no' => $attemptNo,
            ':apk_id' => $apkId,
            ':user_id' => $userId,
            ':selection_mode' => $selectionMode,
            ':buckets_json' => $bucketsJson,
            ':exact_buckets_csv' => $exactBucketsCsv,
            ':replacement_count' => max(0, $replacementCount),
            ':template_id' => max(0, $templateId),
            ':template_version' => mb_substr($templateVersion, 0, 50, 'UTF-8'),
        ]);
        bucketSnapshotRuntimeAttempt($taskId, $attemptNo);
        return $attemptNo;
    }
}

if (!function_exists('bucketSnapshotArtifactEvidence')) {
    /** 读取注入产物路径并生成 SHA-256；路径只从 release 目录取文件名。 */
    function bucketSnapshotArtifactEvidence(string $storedPath): array {
        $storedPath = trim($storedPath);
        if ($storedPath === '') return ['path' => '', 'sha256' => ''];
        $fileName = basename(str_replace('\\', '/', $storedPath));
        $absolutePath = dirname(__DIR__, 2) . '/release/' . $fileName;
        $sha256 = is_file($absolutePath) ? (string)hash_file('sha256', $absolutePath) : '';
        return ['path' => $storedPath, 'sha256' => $sha256];
    }
}

if (!function_exists('bucketFinalizeInjectSnapshot')) {
    /**
     * 为终态任务写入 success/failed 证据。
     * 如果任务在到达桶查询前就终止，则创建 evidence=unknown 的空快照，
     * 避免失败任务被错认为“没有记录”。
     */
    function bucketFinalizeInjectSnapshot(PDO $pdo, int $taskId, string $taskStatus): void {
        if ($taskId <= 0) return;
        $terminalStatus = $taskStatus === '编译成功'
            ? 'success'
            : (stripos($taskStatus, '失败') !== false ? 'failed' : '');
        if ($terminalStatus === '') return;

        ensureInjectBucketSnapshotSchema($pdo);
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;

        $stmt = $pdo->prepare("SELECT t.id, t.apk_id, t.user_id, t.template_id, t.bucket_ids,
                t.injected_apk, COALESCE(tmp.version, '') AS template_version
            FROM cainiao_inject_task t
            LEFT JOIN cainiao_template tmp ON tmp.id=t.template_id
            WHERE t.id=:id LIMIT 1");
        $stmt->execute([':id' => $taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) return;

        $runtimeAttempt = bucketSnapshotRuntimeAttempt($taskId);
        $target = null;
        if ($runtimeAttempt > 0) {
            $targetStmt = $pdo->prepare("SELECT id, task_id, attempt_no, status, completed_at
                FROM cainiao_inject_bucket_snapshot
                WHERE task_id=:task_id AND attempt_no=:attempt_no
                LIMIT 1");
            $targetStmt->execute([':task_id' => $taskId, ':attempt_no' => $runtimeAttempt]);
            $targetRow = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($targetRow)) $target = $targetRow;
        }
        if ($target === null) $target = bucketSnapshotLatestAttempt($pdo, $taskId);

        // 有 prepared 记录时终结当前尝试。早期失败尚未到达桶解析阶段时，
        // 在最新终态之后新建一次 unknown 尝试，不覆盖上一份成功 APK。
        if ($target === null || ((string)$target['status'] !== 'prepared' && $runtimeAttempt <= 0)) {
            $attemptNo = (($target ? (int)$target['attempt_no'] : 0) + 1);
            if ($attemptNo <= 0) $attemptNo = 1;
            $legacy = bucketDecodeLegacyTaskBucketIds($task['bucket_ids'] ?? null);
            $emptyJson = '[]';
            $insert = $pdo->prepare("INSERT INTO cainiao_inject_bucket_snapshot
                (task_id, attempt_no, apk_id, user_id, status, selection_mode, evidence, buckets_json,
                 exact_buckets_csv, replacement_count, template_id, template_version,
                 artifact_path, artifact_sha256, prepared_at, completed_at)
                VALUES
                (:task_id, :attempt_no, :apk_id, :user_id, 'prepared', :selection_mode, 'unknown', CAST(:buckets_json AS JSON),
                 '', 0, :template_id, :template_version, '', '', NOW(), NULL)");
            $insert->execute([
                ':task_id' => $taskId,
                ':attempt_no' => $attemptNo,
                ':apk_id' => (int)$task['apk_id'],
                ':user_id' => (int)$task['user_id'],
                ':selection_mode' => (string)$legacy['selection_mode'],
                ':buckets_json' => $emptyJson,
                ':template_id' => (int)$task['template_id'],
                ':template_version' => mb_substr((string)$task['template_version'], 0, 50, 'UTF-8'),
            ]);
            $target = ['attempt_no' => $attemptNo, 'status' => 'prepared'];
        }
        $attemptNo = (int)$target['attempt_no'];
        bucketSnapshotRuntimeAttempt($taskId, $attemptNo);

        $artifact = $terminalStatus === 'success'
            ? bucketSnapshotArtifactEvidence((string)($task['injected_apk'] ?? ''))
            : ['path' => '', 'sha256' => ''];
        $update = $pdo->prepare("UPDATE cainiao_inject_bucket_snapshot
            SET status=:status, artifact_path=:artifact_path, artifact_sha256=:artifact_sha256,
                completed_at=NOW()
            WHERE task_id=:task_id AND attempt_no=:attempt_no");
        $update->execute([
            ':status' => $terminalStatus,
            ':artifact_path' => (string)$artifact['path'],
            ':artifact_sha256' => (string)$artifact['sha256'],
            ':task_id' => $taskId,
            ':attempt_no' => $attemptNo,
        ]);
    }
}

if (!function_exists('bucketStartInjectAttempt')) {
    /**
     * 为重试立即创建新的待处理尝试。
     *
     * 重试接口会复用原任务 ID；这里先分配 attempt_no，任务列表便会立刻
     * 展示“待生成”，而上一份 success 快照继续作为应用最近成功制品保留。
     */
    function bucketStartInjectAttempt(PDO $pdo, int $taskId): int {
        if ($taskId <= 0) {
            throw new InvalidArgumentException('配置桶快照的任务 ID 错误');
        }
        ensureInjectBucketSnapshotSchema($pdo);
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return 0;

        $latest = bucketSnapshotLatestAttempt($pdo, $taskId);
        if ($latest && (string)$latest['status'] === 'prepared') {
            return (int)$latest['attempt_no'];
        }

        // Web 重试请求和常驻 worker 通常属于不同进程；清理本进程缓存
        // 也让测试、CLI 和未来同进程调度始终指向新尝试。
        bucketSnapshotRuntimeAttempt($taskId, 0);

        $taskStmt = $pdo->prepare("SELECT t.id, t.apk_id, t.user_id, t.bucket_ids, t.template_id,
                COALESCE(tmp.version, '') AS template_version
            FROM cainiao_inject_task t
            LEFT JOIN cainiao_template tmp ON tmp.id=t.template_id
            WHERE t.id=:id LIMIT 1");
        $taskStmt->execute([':id' => $taskId]);
        $task = $taskStmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            throw new RuntimeException('注入任务不存在');
        }

        $attemptNo = (($latest ? (int)$latest['attempt_no'] : 0) + 1);
        if ($attemptNo <= 0) $attemptNo = 1;
        $legacy = bucketDecodeLegacyTaskBucketIds($task['bucket_ids'] ?? null);
        $insert = $pdo->prepare("INSERT INTO cainiao_inject_bucket_snapshot
            (task_id, attempt_no, apk_id, user_id, status, selection_mode, evidence, buckets_json,
             exact_buckets_csv, replacement_count, template_id, template_version,
             artifact_path, artifact_sha256, prepared_at, completed_at)
            VALUES
            (:task_id, :attempt_no, :apk_id, :user_id, 'prepared', :selection_mode, 'unknown', CAST(:buckets_json AS JSON),
             '', 0, :template_id, :template_version, '', '', NOW(), NULL)");
        $insert->execute([
            ':task_id' => $taskId,
            ':attempt_no' => $attemptNo,
            ':apk_id' => (int)$task['apk_id'],
            ':user_id' => (int)$task['user_id'],
            ':selection_mode' => (string)$legacy['selection_mode'],
            ':buckets_json' => '[]',
            ':template_id' => (int)$task['template_id'],
            ':template_version' => mb_substr((string)$task['template_version'], 0, 50, 'UTF-8'),
        ]);
        return $attemptNo;
    }
}

if (!function_exists('bucketEnsureSuccessfulTaskSnapshot')) {
    /**
     * 成功任务删除或重试前，确保至少存在一条 success 快照。
     *
     * 正常 worker 会先终结快照再公开成功状态；本方法同时兜住哈希、数据库
     * 或旧候选版本留下的 prepared 记录。收口失败时抛出异常，由调用方保留任务。
     */
    function bucketEnsureSuccessfulTaskSnapshot(PDO $pdo, int $taskId): void {
        if ($taskId <= 0) {
            throw new InvalidArgumentException('配置桶快照的任务 ID 错误');
        }
        ensureInjectBucketSnapshotSchema($pdo);
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;

        $latest = bucketSnapshotLatestAttempt($pdo, $taskId);
        if ($latest === null) {
            bucketBackfillLegacySnapshots($pdo, [$taskId]);
            $latest = bucketSnapshotLatestAttempt($pdo, $taskId);
        }
        if ($latest && (string)$latest['status'] === 'success') return;

        if ($latest && (string)$latest['status'] === 'prepared') {
            bucketSnapshotRuntimeAttempt($taskId, (int)$latest['attempt_no']);
        } else {
            bucketSnapshotRuntimeAttempt($taskId, 0);
        }
        try {
            bucketFinalizeInjectSnapshot($pdo, $taskId, '编译成功');
        } finally {
            bucketSnapshotRuntimeAttempt($taskId, 0);
        }

        $final = bucketSnapshotLatestAttempt($pdo, $taskId);
        if (!$final || (string)$final['status'] !== 'success') {
            throw new RuntimeException('成功任务的配置桶快照尚未终结');
        }
    }
}

if (!function_exists('bucketSnapshotDecodeBucketsJson')) {
    /** 解析数据库快照 JSON，异常数据按空数组处理。 */
    function bucketSnapshotDecodeBucketsJson($value): array {
        $decoded = is_array($value) ? $value : json_decode((string)$value, true);
        return is_array($decoded) ? bucketNormalizePublicSnapshotRows($decoded) : [];
    }
}

if (!function_exists('bucketSnapshotSetDefaults')) {
    /** 为列表行写入统一的静态桶字段。 */
    function bucketSnapshotSetDefaults(array &$row): void {
        $row['static_injected_buckets'] = [
            'state' => 'none',
            'source' => 'unknown',
            'evidence' => 'unknown',
            'exact' => 0,
            'status' => '',
            'selection_mode' => 'global_inject',
            'replacement_count' => 0,
            'task_id' => 0,
            'attempt_no' => 0,
            'template_version' => '',
            'completed_at' => '',
            'artifact_path' => '',
            'artifact_sha256' => '',
            'count' => 0,
            'items' => [],
        ];
        $row['static_injected_bucket_source'] = 'unknown';
        $row['static_injected_bucket_exact'] = 0;
        $row['static_injected_bucket_status'] = '';
        $row['static_injected_bucket_selection_mode'] = 'global_inject';
        $row['static_injected_bucket_replacement_count'] = 0;
        $row['static_injected_bucket_task_id'] = 0;
        $row['static_injected_bucket_attempt_no'] = 0;
        $row['static_injected_bucket_artifact_path'] = '';
        $row['static_injected_bucket_artifact_sha256'] = '';
        $row['static_injected_bucket_completed_at'] = '';
    }
}

if (!function_exists('bucketSnapshotApplyRecord')) {
    /** 将一条持久快照映射到 API 列表行。 */
    function bucketSnapshotApplyRecord(array &$row, array $snapshot, array $currentBuckets = []): void {
        $source = trim((string)($snapshot['evidence'] ?? 'runtime_snapshot'));
        if ($source === '') $source = 'runtime_snapshot';
        $status = trim((string)($snapshot['status'] ?? ''));
        $replacementCount = (int)($snapshot['replacement_count'] ?? 0);
        $snapshotItems = bucketSnapshotDecodeBucketsJson($snapshot['buckets_json'] ?? '[]');
        $currentRows = [];
        foreach ($snapshotItems as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id > 0 && isset($currentBuckets[$id]) && empty($currentBuckets[$id]['_missing'])) {
                $currentRows[] = $currentBuckets[$id];
            }
        }
        $items = bucketNormalizePublicSnapshotRows($snapshotItems, $currentRows);
        $appId = (int)($snapshot['apk_id'] ?? $row['apk_id'] ?? $row['id'] ?? 0);
        $items = bucketAttachAppFileFields($items, $appId);
        $snapshotDomains = [];
        foreach ($snapshotItems as $snapshotItem) {
            $domain = (string)($snapshotItem['domain'] ?? '');
            if ($domain !== '') $snapshotDomains[] = $domain;
        }
        $expectedBucketsCsv = implode(',', $snapshotDomains);
        $exactBucketsCsv = (string)($snapshot['exact_buckets_csv'] ?? '');
        $runtimeSnapshotConsistent = !empty($snapshotItems)
            && count($snapshotDomains) === count($snapshotItems)
            && $expectedBucketsCsv !== ''
            && count(array_filter($snapshotDomains, static function (string $domain): bool {
                return strpos($domain, ',') !== false;
            })) === 0
            && hash_equals($expectedBucketsCsv, $exactBucketsCsv);
        if ($status === 'failed') {
            $state = 'failed';
        } elseif ($status === 'prepared') {
            $state = 'pending';
        } elseif ($source === 'legacy_inferred') {
            $state = 'legacy_inferred';
        } elseif ($source === 'unknown') {
            $state = 'unknown';
        } elseif ($status === 'success' && $replacementCount > 0 && $runtimeSnapshotConsistent) {
            $state = 'verified';
        } elseif ($status === 'success') {
            $state = 'unresolved_placeholder';
        } else {
            $state = 'unknown';
        }
        $exact = $state === 'verified' ? 1 : 0;
        $payload = [
            'state' => $state,
            'source' => $source,
            'evidence' => $source,
            'exact' => $exact,
            'status' => $status,
            'selection_mode' => (string)($snapshot['selection_mode'] ?? 'global_inject'),
            'replacement_count' => max(0, $replacementCount),
            'task_id' => (int)($snapshot['task_id'] ?? 0),
            'attempt_no' => (int)($snapshot['attempt_no'] ?? 1),
            'template_version' => (string)($snapshot['template_version'] ?? ''),
            'completed_at' => (string)($snapshot['completed_at'] ?? ''),
            'artifact_path' => (string)($snapshot['artifact_path'] ?? ''),
            'artifact_sha256' => (string)($snapshot['artifact_sha256'] ?? ''),
            'count' => count($items),
            'items' => $items,
        ];
        $row['static_injected_buckets'] = $payload;
        $row['static_injected_bucket_source'] = $source;
        $row['static_injected_bucket_exact'] = $exact;
        $row['static_injected_bucket_status'] = $status;
        $row['static_injected_bucket_selection_mode'] = (string)($snapshot['selection_mode'] ?? 'global_inject');
        $row['static_injected_bucket_replacement_count'] = max(0, $replacementCount);
        $row['static_injected_bucket_task_id'] = (int)($snapshot['task_id'] ?? 0);
        $row['static_injected_bucket_attempt_no'] = (int)($snapshot['attempt_no'] ?? 1);
        $row['static_injected_bucket_artifact_path'] = (string)($snapshot['artifact_path'] ?? '');
        $row['static_injected_bucket_artifact_sha256'] = (string)($snapshot['artifact_sha256'] ?? '');
        $row['static_injected_bucket_completed_at'] = (string)($snapshot['completed_at'] ?? '');
    }
}

if (!function_exists('bucketSnapshotLoadCurrentRows')) {
    /** 批量读取当前桶公开信息，仅用于无运行时快照的历史推断。 */
    function bucketSnapshotLoadCurrentRows(PDO $pdo, array $bucketIds, bool $includeMissing = true): array {
        $ids = [];
        foreach ($bucketIds as $value) {
            $id = (int)$value;
            if ($id > 0) $ids[$id] = $id;
        }
        if (empty($ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, name, provider, domain, enabled FROM cainiao_s3_bucket WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($ids));
        $current = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rawBucket) {
            $normalized = bucketNormalizePublicSnapshotRows([$rawBucket]);
            if (empty($normalized)) continue;
            $bucket = $normalized[0];
            // 当前状态也保留数据库原值，才能与 APK 快照准确比较。
            // 页面仅把 HTTP(S) 地址渲染成链接，其余协议只作为文本证据。
            $bucket['enabled'] = (int)($rawBucket['enabled'] ?? 0) === 1 ? 1 : 0;
            $bucket['_missing'] = 0;
            $current[(int)$bucket['id']] = $bucket;
        }

        $result = [];
        foreach ($ids as $id) {
            if (isset($current[$id])) {
                $result[$id] = $current[$id];
            } elseif ($includeMissing) {
                $result[$id] = [
                    'id' => $id,
                    'name' => '已删除桶 #' . $id,
                    'provider' => '',
                    'domain' => '',
                    'enabled' => 0,
                    '_missing' => 1,
                ];
            }
        }
        return $result;
    }
}

if (!function_exists('bucketBackfillLegacySnapshots')) {
    /**
     * 幂等回填现存成功任务。
     *
     * 回填只能证明历史 bucket_ids 的选择意图，所以始终使用
     * legacy_inferred/unknown，replacement_count 保持 0，不会伪装成 worker 验证快照。
     * 表与任务无外键，回填后任务过期清理不会删除快照。
     */
    function bucketBackfillLegacySnapshots(PDO $pdo, array $taskIds = [], bool $internalOnly = true): array {
        static $done = [];
        $key = spl_object_hash($pdo);
        $normalizedTaskIds = [];
        foreach ($taskIds as $value) {
            $id = (int)$value;
            if ($id > 0) $normalizedTaskIds[$id] = $id;
        }
        $fullBackfill = empty($normalizedTaskIds);
        if ($fullBackfill && isset($done[$key])) return $done[$key];
        $result = ['scanned' => 0, 'inserted' => 0, 'legacy_inferred' => 0, 'unknown' => 0];
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            if ($fullBackfill) $done[$key] = $result;
            return $result;
        }
        ensureInjectBucketSnapshotSchema($pdo);

        $where = "t.status_text='编译成功' AND s.task_id IS NULL";
        $queryParams = [];
        if (!$fullBackfill) {
            $placeholders = implode(',', array_fill(0, count($normalizedTaskIds), '?'));
            $where .= " AND t.id IN ({$placeholders})";
            $queryParams = array_values($normalizedTaskIds);
        }
        $select = $pdo->prepare("SELECT t.id, t.apk_id, t.user_id, t.bucket_ids, t.template_id,
                t.injected_apk, t.created_at, t.completed_at, COALESCE(tmp.version, '') AS template_version
            FROM cainiao_inject_task t
            LEFT JOIN cainiao_template tmp ON tmp.id=t.template_id
            LEFT JOIN cainiao_inject_bucket_snapshot s ON s.task_id=t.id
            WHERE {$where}
            ORDER BY t.id ASC");
        $select->execute($queryParams);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        $result['scanned'] = count($rows);
        if (empty($rows)) {
            if ($fullBackfill) $done[$key] = $result;
            return $result;
        }

        $legacyByTask = [];
        $allBucketIds = [];
        foreach ($rows as $task) {
            $legacy = bucketDecodeLegacyTaskBucketIds($task['bucket_ids'] ?? null);
            $legacyByTask[(int)$task['id']] = $legacy;
            foreach ($legacy['bucket_ids'] as $id) $allBucketIds[(int)$id] = (int)$id;
        }
        $currentBuckets = bucketSnapshotLoadCurrentRows($pdo, array_values($allBucketIds), true);
        $insert = $pdo->prepare("INSERT IGNORE INTO cainiao_inject_bucket_snapshot
            (task_id, attempt_no, apk_id, user_id, status, selection_mode, evidence, buckets_json,
             exact_buckets_csv, replacement_count, template_id, template_version,
             artifact_path, artifact_sha256, prepared_at, completed_at)
            VALUES
            (:task_id, 1, :apk_id, :user_id, 'success', :selection_mode, :evidence, CAST(:buckets_json AS JSON),
             '', 0, :template_id, :template_version, :artifact_path, '', :prepared_at, :completed_at)");

        foreach ($rows as $task) {
            $taskId = (int)$task['id'];
            $legacy = $legacyByTask[$taskId];
            $buckets = [];
            foreach ($legacy['bucket_ids'] as $bucketId) {
                $bucketId = (int)$bucketId;
                if (!isset($currentBuckets[$bucketId])) continue;
                $bucket = $currentBuckets[$bucketId];
                unset($bucket['enabled'], $bucket['_missing']);
                $buckets[] = $bucket;
            }
            $bucketsJson = json_encode(
                bucketNormalizePublicSnapshotRows($buckets),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($bucketsJson === false) $bucketsJson = '[]';
            $completedAt = trim((string)($task['completed_at'] ?? ''));
            $preparedAt = trim((string)($task['created_at'] ?? ''));
            if ($preparedAt === '') $preparedAt = date('Y-m-d H:i:s');
            if ($completedAt === '') $completedAt = $preparedAt;
            $insert->execute([
                ':task_id' => $taskId,
                ':apk_id' => (int)$task['apk_id'],
                ':user_id' => (int)$task['user_id'],
                ':selection_mode' => (string)$legacy['selection_mode'],
                ':evidence' => (string)$legacy['evidence'],
                ':buckets_json' => $bucketsJson,
                ':template_id' => (int)$task['template_id'],
                ':template_version' => mb_substr((string)$task['template_version'], 0, 50, 'UTF-8'),
                ':artifact_path' => (string)($task['injected_apk'] ?? ''),
                ':prepared_at' => $preparedAt,
                ':completed_at' => $completedAt,
            ]);
            if ($insert->rowCount() > 0) {
                $result['inserted']++;
                $evidence = (string)$legacy['evidence'];
                if (isset($result[$evidence])) $result[$evidence]++;
            }
        }
        if ($fullBackfill) $done[$key] = $result;
        return $result;
    }
}

if (!function_exists('bucketSnapshotApplyLegacySelection')) {
    /** 将任务 bucket_ids 以“历史推断”口径映射到 API 列表行。 */
    function bucketSnapshotApplyLegacySelection(
        array &$row,
        array $legacy,
        array $currentBuckets,
        int $taskId = 0,
        string $templateVersion = '',
        string $completedAt = '',
        string $taskStatus = 'success'
    ): void {
        $buckets = [];
        foreach (($legacy['bucket_ids'] ?? []) as $id) {
            $id = (int)$id;
            if ($id > 0 && isset($currentBuckets[$id])) {
                $bucket = $currentBuckets[$id];
                unset($bucket['_missing'], $bucket['enabled']);
                $bucket['provider_label'] = (string)$bucket['provider'] !== ''
                    ? bucketProviderLabel((string)$bucket['provider'])
                    : '';
                if (!empty($currentBuckets[$id]['_missing'])) {
                    $bucket['current_name'] = '';
                    $bucket['current_provider'] = '';
                    $bucket['current_domain'] = '';
                    $bucket['current_state'] = 'deleted';
                    $bucket['state'] = 'deleted';
                    $bucket['changed'] = 1;
                } else {
                    $bucket['current_name'] = (string)$bucket['name'];
                    $bucket['current_provider'] = (string)$bucket['provider'];
                    $bucket['current_domain'] = (string)$bucket['domain'];
                    $bucket['current_state'] = (int)$currentBuckets[$id]['enabled'] === 1 ? 'current' : 'disabled';
                    $bucket['state'] = $bucket['current_state'];
                    $bucket['changed'] = 0;
                }
                $buckets[] = $bucket;
            }
        }
        $source = (string)($legacy['evidence'] ?? 'unknown');
        if ($taskStatus === 'failed' || stripos($taskStatus, '失败') !== false) {
            $state = 'failed';
            $status = 'failed';
            $source = 'unknown';
        } elseif (!in_array(trim($taskStatus), ['success', '编译成功'], true)) {
            // 除成功和失败终态外，worker 还有下载、反编译、
            // 配置写入、签名等多种中间文案，统一视为待生成。
            $state = 'pending';
            $status = 'prepared';
            $source = 'unknown';
        } else {
            $state = $source === 'legacy_inferred' ? 'legacy_inferred' : 'unknown';
            $status = $taskStatus === '编译成功' ? 'success' : $taskStatus;
        }
        $appId = (int)($row['apk_id'] ?? $row['id'] ?? 0);
        $buckets = bucketAttachAppFileFields($buckets, $appId);
        $payload = [
            'state' => $state,
            'source' => $source,
            'evidence' => $source,
            'exact' => 0,
            'status' => $status,
            'selection_mode' => (string)($legacy['selection_mode'] ?? 'global_inject'),
            'replacement_count' => 0,
            'task_id' => $taskId,
            'attempt_no' => 0,
            'template_version' => $templateVersion,
            'completed_at' => $completedAt,
            'artifact_path' => '',
            'artifact_sha256' => '',
            'count' => count($buckets),
            'items' => $buckets,
        ];
        $row['static_injected_buckets'] = $payload;
        $row['static_injected_bucket_source'] = $source;
        $row['static_injected_bucket_exact'] = 0;
        $row['static_injected_bucket_status'] = $status;
        $row['static_injected_bucket_selection_mode'] = (string)($legacy['selection_mode'] ?? 'global_inject');
        $row['static_injected_bucket_replacement_count'] = 0;
        $row['static_injected_bucket_task_id'] = $taskId;
        $row['static_injected_bucket_attempt_no'] = 0;
        $row['static_injected_bucket_artifact_path'] = '';
        $row['static_injected_bucket_artifact_sha256'] = '';
        $row['static_injected_bucket_completed_at'] = $completedAt;
    }
}

if (!function_exists('bucketAttachTaskSnapshots')) {
    /**
     * 为任务列表批量附加 static_injected_buckets，整个列表最多三次查询。
     * 快照缺失时，显式 bucket_ids 标为 legacy_inferred；全局回退标为 unknown。
     */
    function bucketAttachTaskSnapshots(PDO $pdo, array &$list, bool $internalOnly = true): void {
        if (empty($list)) return;
        $taskIndexes = [];
        foreach ($list as $index => &$row) {
            bucketSnapshotSetDefaults($row);
            if (isset($row['task_type']) && (string)$row['task_type'] !== 'inject') continue;
            $taskId = (int)($row['task_id'] ?? $row['id'] ?? 0);
            if ($taskId > 0) $taskIndexes[$taskId][] = $index;
        }
        unset($row);
        if (empty($taskIndexes) || (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;

        ensureInjectBucketSnapshotSchema($pdo);
        $taskIds = array_keys($taskIndexes);
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM cainiao_inject_bucket_snapshot
            WHERE task_id IN ({$placeholders})
            ORDER BY task_id ASC, attempt_no DESC, id DESC");
        $stmt->execute($taskIds);
        $snapshots = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $snapshot) {
            $taskId = (int)$snapshot['task_id'];
            if (!isset($snapshots[$taskId])) $snapshots[$taskId] = $snapshot;
        }

        $legacyByTask = [];
        $allBucketIds = [];
        foreach ($taskIndexes as $taskId => $indexes) {
            if (isset($snapshots[$taskId])) {
                foreach (bucketSnapshotDecodeBucketsJson($snapshots[$taskId]['buckets_json'] ?? '[]') as $bucket) {
                    $bucketId = (int)($bucket['id'] ?? 0);
                    if ($bucketId > 0) $allBucketIds[$bucketId] = $bucketId;
                }
                continue;
            }
            $sourceRow = $list[$indexes[0]];
            $legacy = bucketDecodeLegacyTaskBucketIds($sourceRow['bucket_ids'] ?? null);
            $legacyByTask[$taskId] = $legacy;
            foreach ($legacy['bucket_ids'] as $id) $allBucketIds[(int)$id] = (int)$id;
        }

        $currentBuckets = bucketSnapshotLoadCurrentRows($pdo, array_values($allBucketIds), true);
        foreach ($taskIndexes as $taskId => $indexes) {
            if (isset($snapshots[$taskId])) {
                foreach ($indexes as $index) {
                    bucketSnapshotApplyRecord($list[$index], $snapshots[$taskId], $currentBuckets);
                }
                continue;
            }
            $legacy = $legacyByTask[$taskId];
            foreach ($indexes as $index) {
                $sourceRow = $list[$index];
                bucketSnapshotApplyLegacySelection(
                    $list[$index],
                    $legacy,
                    $currentBuckets,
                    (int)$taskId,
                    (string)($sourceRow['template_version'] ?? ''),
                    (string)($sourceRow['completed_at'] ?? ''),
                    (string)($sourceRow['status_text'] ?? '')
                );
            }
        }
    }
}

if (!function_exists('bucketAttachExactTaskSnapshot')) {
    /**
     * 为单条任务行附加指定 attempt 的制品快照。
     *
     * 列表页使用“该任务最新 attempt”用于发现重试竞态；正文读取则必须
     * 定位用户点击时看到的 task_id + attempt_no。这样同一任务后续又产生
     * 失败重试时，“最近成功制品”仍可读取原成功 attempt 的固定桶证据。
     *
     * attempt_no=0 仅用于未持久化快照的旧任务，沿用原有历史推算合同。
     */
    function bucketAttachExactTaskSnapshot(PDO $pdo, array &$row, int $taskId, int $attemptNo): bool {
        bucketSnapshotSetDefaults($row);
        $appId = (int)($row['apk_id'] ?? 0);
        if ($taskId <= 0 || $attemptNo < 0 || $appId <= 0) return false;
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return false;

        ensureInjectBucketSnapshotSchema($pdo);
        if ($attemptNo === 0) {
            $rows = [$row];
            bucketAttachTaskSnapshots($pdo, $rows);
            $row = $rows[0];
            $legacy = is_array($row['static_injected_buckets'] ?? null)
                ? $row['static_injected_buckets']
                : [];
            return (int)($legacy['task_id'] ?? 0) === $taskId
                && (int)($legacy['attempt_no'] ?? -1) === 0;
        }

        $stmt = $pdo->prepare("SELECT * FROM cainiao_inject_bucket_snapshot
            WHERE task_id=:task_id AND attempt_no=:attempt_no AND apk_id=:apk_id
            ORDER BY id DESC
            LIMIT 1");
        $stmt->execute([
            ':task_id' => $taskId,
            ':attempt_no' => $attemptNo,
            ':apk_id' => $appId,
        ]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) return false;

        $bucketIds = [];
        foreach (bucketSnapshotDecodeBucketsJson($record['buckets_json'] ?? '[]') as $bucket) {
            $bucketId = (int)($bucket['id'] ?? 0);
            if ($bucketId > 0) $bucketIds[$bucketId] = $bucketId;
        }
        $currentBuckets = bucketSnapshotLoadCurrentRows(
            $pdo,
            array_values($bucketIds),
            true
        );
        bucketSnapshotApplyRecord($row, $record, $currentBuckets);
        $attached = is_array($row['static_injected_buckets'] ?? null)
            ? $row['static_injected_buckets']
            : [];
        return (int)($attached['task_id'] ?? 0) === $taskId
            && (int)($attached['attempt_no'] ?? -1) === $attemptNo;
    }
}

if (!function_exists('bucketAttachLatestAppSnapshots')) {
    /**
     * 为应用列表批量附加最近成功制品的静态桶快照。
     * 新表没有数据时仅回退最近一条仍保留的成功任务，不对多任务做并集。
     */
    function bucketAttachLatestAppSnapshots(PDO $pdo, array &$list, bool $internalOnly = true): void {
        if (empty($list)) return;
        $appIndexes = [];
        foreach ($list as $index => &$row) {
            bucketSnapshotSetDefaults($row);
            $appId = (int)($row['apk_id'] ?? $row['id'] ?? 0);
            if ($appId > 0) $appIndexes[$appId][] = $index;
        }
        unset($row);
        if (empty($appIndexes) || (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;

        ensureInjectBucketSnapshotSchema($pdo);
        $appIds = array_keys($appIndexes);
        $placeholders = implode(',', array_fill(0, count($appIds), '?'));
        // MySQL 8 窗口函数只返回每个应用最近一份成功制品，避免随着
        // 历史快照增长而把全部成功记录加载到 PHP 内存。
        $stmt = $pdo->prepare("SELECT ranked.* FROM (
                SELECT s.*,
                    ROW_NUMBER() OVER (
                        PARTITION BY s.apk_id
                        ORDER BY COALESCE(s.completed_at, s.prepared_at) DESC, s.id DESC
                    ) AS snapshot_rank
                FROM cainiao_inject_bucket_snapshot s
                WHERE s.apk_id IN ({$placeholders}) AND s.status='success'
            ) ranked
            WHERE ranked.snapshot_rank=1");
        $stmt->execute($appIds);
        $latestSnapshots = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $snapshot) {
            $appId = (int)$snapshot['apk_id'];
            if (!isset($latestSnapshots[$appId])) $latestSnapshots[$appId] = $snapshot;
        }

        $missingAppIds = [];
        foreach ($appIndexes as $appId => $indexes) {
            if (!isset($latestSnapshots[$appId])) $missingAppIds[] = (int)$appId;
        }
        $latestTasks = [];
        if (!empty($missingAppIds)) {
            $fallbackPlaceholders = implode(',', array_fill(0, count($missingAppIds), '?'));
            $fallbackStmt = $pdo->prepare("SELECT ranked.* FROM (
                    SELECT t.id, t.apk_id, t.bucket_ids, t.completed_at, t.created_at,
                        COALESCE(tmp.version, '') AS template_version,
                        ROW_NUMBER() OVER (
                            PARTITION BY t.apk_id
                            ORDER BY COALESCE(t.completed_at, t.created_at) DESC, t.id DESC
                        ) AS task_rank
                    FROM cainiao_inject_task t
                    LEFT JOIN cainiao_template tmp ON tmp.id=t.template_id
                    WHERE t.apk_id IN ({$fallbackPlaceholders}) AND t.status_text='编译成功'
                ) ranked
                WHERE ranked.task_rank=1");
            $fallbackStmt->execute($missingAppIds);
            foreach ($fallbackStmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
                $appId = (int)$task['apk_id'];
                $latestTasks[$appId] = $task;
            }
        }

        $legacyByApp = [];
        $allBucketIds = [];
        foreach ($latestSnapshots as $snapshot) {
            foreach (bucketSnapshotDecodeBucketsJson($snapshot['buckets_json'] ?? '[]') as $bucket) {
                $bucketId = (int)($bucket['id'] ?? 0);
                if ($bucketId > 0) $allBucketIds[$bucketId] = $bucketId;
            }
        }
        foreach ($missingAppIds as $appId) {
            if (!isset($latestTasks[$appId])) continue;
            $legacy = bucketDecodeLegacyTaskBucketIds($latestTasks[$appId]['bucket_ids'] ?? null);
            $legacyByApp[$appId] = $legacy;
            foreach ($legacy['bucket_ids'] as $id) $allBucketIds[(int)$id] = (int)$id;
        }
        $currentBuckets = bucketSnapshotLoadCurrentRows($pdo, array_values($allBucketIds), true);
        foreach ($latestSnapshots as $appId => $snapshot) {
            foreach ($appIndexes[$appId] as $index) {
                bucketSnapshotApplyRecord($list[$index], $snapshot, $currentBuckets);
            }
        }
        foreach ($legacyByApp as $appId => $legacy) {
            $taskId = (int)$latestTasks[$appId]['id'];
            foreach ($appIndexes[$appId] as $index) {
                bucketSnapshotApplyLegacySelection(
                    $list[$index],
                    $legacy,
                    $currentBuckets,
                    $taskId,
                    (string)($latestTasks[$appId]['template_version'] ?? ''),
                    (string)($latestTasks[$appId]['completed_at'] ?? ''),
                    'success'
                );
            }
        }
    }
}

if (!function_exists('ensureBucketFeatureSchema')) {
    /**
     * 初始化桶管理元数据和两张日聚合统计表。
     * 该方法幂等，管理员和 worker 控制路径可在手工迁移遗漏时补齐结构；
     * 公开高频回执不执行 DDL，生产发布仍先执行 migrate_s3_bucket.sql。
     */
    function ensureBucketFeatureSchema(PDO $pdo): void {
        static $ready = [];
        $key = spl_object_hash($pdo);
        if (!empty($ready[$key])) return;
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            $ready[$key] = true;
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_s3_bucket` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(100) NOT NULL,
          `provider` varchar(20) NOT NULL DEFAULT 's3',
          `login_account` varchar(2048) NOT NULL DEFAULT '',
          `login_password` varchar(4096) NOT NULL DEFAULT '',
          `note` varchar(512) NOT NULL DEFAULT '',
          `access_key` varchar(1024) NOT NULL,
          `secret_key` varchar(2048) NOT NULL,
          `endpoint` varchar(512) NOT NULL,
          `bucket` varchar(255) NOT NULL,
          `region` varchar(64) NOT NULL DEFAULT 'auto',
          `domain` varchar(512) NOT NULL,
          `enabled` tinyint(1) NOT NULL DEFAULT 1,
          `inject` tinyint(1) NOT NULL DEFAULT 1,
          `last_push_at` datetime DEFAULT NULL,
          `last_push_result` text,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配置分发桶'");

        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'login_account', "varchar(2048) NOT NULL DEFAULT '' AFTER `provider`");
        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'login_password', "varchar(4096) NOT NULL DEFAULT '' AFTER `login_account`");
        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'note', "varchar(512) NOT NULL DEFAULT '' AFTER `login_password`");
        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'inject', "tinyint(1) NOT NULL DEFAULT 1 AFTER `enabled`");
        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'last_push_at', "datetime DEFAULT NULL AFTER `inject`");
        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'last_push_result', "text AFTER `last_push_at`");

        // 用户名和密码按 Unicode 字符限制；列容量需覆盖四字节字符经 GCM 与 Base64 包装后的最坏情况。
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'login_account', 2048, "NOT NULL DEFAULT ''");
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'login_password', 4096, "NOT NULL DEFAULT ''");
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'access_key', 1024, 'NOT NULL');
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'secret_key', 2048, 'NOT NULL');
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'endpoint', 512, 'NOT NULL');
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'bucket', 255, 'NOT NULL');
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'region', 64, "NOT NULL DEFAULT 'auto'");
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'domain', 512, 'NOT NULL');

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_s3_bucket_stats` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `bucket_id` int NOT NULL,
          `stat_date` date NOT NULL,
          `hit_count` bigint unsigned NOT NULL DEFAULT 0,
          `ok_count` bigint unsigned NOT NULL DEFAULT 0,
          `fail_count` bigint unsigned NOT NULL DEFAULT 0,
          `last_seen_at` datetime NOT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_bucket_date` (`bucket_id`,`stat_date`),
          KEY `idx_stat_date` (`stat_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='壳端配置桶日命中统计'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_s3_bucket_file_stats` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `bucket_id` int NOT NULL,
          `app_id` int NOT NULL,
          `stat_date` date NOT NULL,
          `hit_count` bigint unsigned NOT NULL DEFAULT 0,
          `last_seen_at` datetime NOT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_bucket_app_date` (`bucket_id`,`app_id`,`stat_date`),
          KEY `idx_bucket_app_date` (`bucket_id`,`app_id`,`stat_date`),
          KEY `idx_stat_date` (`stat_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='壳端配置文件日命中统计'");

        ensureInjectBucketSnapshotSchema($pdo);
        bucketMigrateLegacyProviderLabels($pdo);
        $ready[$key] = true;
    }
}
