<?php

/**
 * API 配置节点实际响应探测工具。
 *
 * 管理页面只提交当前入口的摘要标识；目标 URL、Shell POST 参数和网络连接
 * 全部由服务端重新推导。探测只访问当前有效配置入口，并在 DNS 固定、公网
 * 地址校验、禁止跳转、正文限大和 TLS 校验下取得一次真实响应。
 */

require_once __DIR__ . '/ConfigDelivery.php';
require_once __DIR__ . '/BucketFeature.php';

if (!function_exists('apiConfigProbeEntryKey')) {
    /** 为当前实际下发 URL 生成不透明入口标识。 */
    function apiConfigProbeEntryKey(string $deliveryUrl): string
    {
        return hash('sha256', $deliveryUrl);
    }
}

if (!function_exists('apiConfigProbeAttachEntryKeys')) {
    /** 给当前有效入口附加探测标识；标识随 URL 变化自动失效。 */
    function apiConfigProbeAttachEntryKeys(array $items): array
    {
        foreach ($items as &$item) {
            $deliveryUrl = (string)($item['delivery_url'] ?? '');
            $item['entry_key'] = $deliveryUrl !== '' ? apiConfigProbeEntryKey($deliveryUrl) : '';
        }
        unset($item);
        return $items;
    }
}

if (!function_exists('apiConfigProbeMaterializeApiUrl')) {
    /**
     * 按 Android 壳相同形状展开 `*.` 随机子域，并校验最终 HTTP(S) URL。
     */
    function apiConfigProbeMaterializeApiUrl(string $deliveryUrl): string
    {
        $url = trim($deliveryUrl);
        if ($url === '' || strlen($url) > 2048) {
            throw new InvalidArgumentException('API 下发地址为空或长度异常');
        }

        $wildcardCount = substr_count($url, '*');
        if ($wildcardCount > 0) {
            if ($wildcardCount !== 1 || strpos($url, '://*.') === false) {
                throw new InvalidArgumentException('API 随机子域占位符格式异常');
            }
            $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
            $length = random_int(3, 5);
            $prefix = '';
            for ($index = 0; $index < $length; $index++) {
                $prefix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $url = str_replace('://*.', '://' . $prefix . time() . '.', $url);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('API 实际请求地址必须是完整 HTTP 或 HTTPS URL');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('API 实际请求地址包含被禁止的凭据或 Fragment');
        }
        $queryParameters = [];
        parse_str((string)($parts['query'] ?? ''), $queryParameters);
        foreach (array_keys($queryParameters) as $parameterName) {
            if (strcasecmp((string)$parameterName, 'debug') === 0) {
                throw new InvalidArgumentException('API 实际请求地址包含调试输出参数');
            }
        }
        if ($host === 'localhost'
            || substr($host, -6) === '.local'
            || substr($host, -9) === '.internal') {
            throw new InvalidArgumentException('API 实际请求地址不是公网服务');
        }
        return $url;
    }
}

if (!function_exists('apiConfigProbeSafeHeaderValue')) {
    /** 清理上游响应头，避免控制字符或非 UTF-8 字节破坏外层 JSON。 */
    function apiConfigProbeSafeHeaderValue(string $value, int $maxLength = 512): string
    {
        if ($value === '' || preg_match('//u', $value) !== 1) return '';
        $cleaned = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        if (!is_string($cleaned)) return '';
        $cleaned = trim($cleaned);
        $maxLength = max(1, min($maxLength, 2048));
        if (function_exists('mb_substr')) {
            return mb_substr($cleaned, 0, $maxLength, 'UTF-8');
        }

        $truncated = substr($cleaned, 0, $maxLength);
        while ($truncated !== '' && preg_match('//u', $truncated) !== 1) {
            $truncated = substr($truncated, 0, -1);
        }
        return $truncated;
    }
}

if (!function_exists('apiConfigProbeReleaseRedisLocks')) {
    /** 按随机令牌原子释放锁，避免误删超时后由新请求取得的锁。 */
    function apiConfigProbeReleaseRedisLocks($redis, array $keys, string $token): void
    {
        if (!$redis || empty($keys) || $token === '') return;
        $script = <<<'LUA'
for index, key in ipairs(KEYS) do
    if redis.call('get', key) == ARGV[1] then
        redis.call('del', key)
    end
end
return 1
LUA;
        try {
            $redis->eval($script, array_merge(array_values($keys), [$token]), count($keys));
        } catch (Throwable $ignored) {
            // 锁最长 45 秒后会自动清理，释放失败不覆盖主请求结果。
        }
    }
}

if (!function_exists('apiConfigProbeAcquireGuard')) {
    /**
     * 为真实 API 探测加上用户节流、每用户单并发、入口单并发与全局并发保护。
     *
     * 返回的 Redis 连接和令牌只用于 finally 阶段释放活动锁；
     * 节流键保留 3 秒，防止连续点击制造 Shell 请求与统计副作用。
     */
    function apiConfigProbeAcquireGuard(int $userId, int $appId, string $entryKey): array
    {
        if ($userId <= 0 || $appId <= 0 || preg_match('/^[a-f0-9]{64}$/', $entryKey) !== 1) {
            throw new InvalidArgumentException('API 探测保护参数异常');
        }
        if (!function_exists('getRedisConnection')) {
            throw new RuntimeException('API 数据查看保护服务未就绪');
        }

        try {
            $redis = getRedisConnection(0);
        } catch (Throwable $connectionError) {
            throw new RuntimeException('API 数据查看保护服务暂时繁忙');
        }

        $namespace = 'console:api_config_probe:';
        $rateKey = $namespace . 'rate:user:' . $userId;
        $userLockKey = $namespace . 'active:user:' . $userId;
        $entryLockKey = $namespace . 'active:entry:' . $appId . ':' . $entryKey;
        try {
            $token = bin2hex(random_bytes(16));
        } catch (Throwable $randomError) {
            try { $redis->close(); } catch (Throwable $ignored) {}
            throw new RuntimeException('API 数据查看保护服务暂时繁忙');
        }
        $acquiredKeys = [];
        $expectedErrors = [
            '操作过于频繁，请稍后再查看',
            '当前账号已有 API 数据请求正在处理',
            '该 API 入口已有请求正在处理',
            'API 数据查看请求较多，请稍后再试',
        ];

        try {
            if (!$redis->set($rateKey, '1', ['nx', 'ex' => 3])) {
                throw new RuntimeException('操作过于频繁，请稍后再查看');
            }
            if (!$redis->set($userLockKey, $token, ['nx', 'ex' => 45])) {
                throw new RuntimeException('当前账号已有 API 数据请求正在处理');
            }
            $acquiredKeys[] = $userLockKey;
            if (!$redis->set($entryLockKey, $token, ['nx', 'ex' => 45])) {
                throw new RuntimeException('该 API 入口已有请求正在处理');
            }
            $acquiredKeys[] = $entryLockKey;

            // 当前生产为 8 个 PHP CLI Server worker；正式 Shell 域名也回到同一服务。
            // 每个探测会同时占用外层后台请求和内层 Shell 请求，因此全局仅开 2 槽，
            // 最多占用 4/8 worker，保留一半容量处理 APK 配置和其他后台操作。
            $globalSlotAcquired = false;
            for ($slot = 0; $slot < 2; $slot++) {
                $slotKey = $namespace . 'active:global_slot:' . $slot;
                if ($redis->set($slotKey, $token, ['nx', 'ex' => 45])) {
                    $acquiredKeys[] = $slotKey;
                    $globalSlotAcquired = true;
                    break;
                }
            }
            if (!$globalSlotAcquired) {
                throw new RuntimeException('API 数据查看请求较多，请稍后再试');
            }
        } catch (Throwable $guardError) {
            apiConfigProbeReleaseRedisLocks($redis, $acquiredKeys, $token);
            try { $redis->close(); } catch (Throwable $ignored) {}
            if (in_array($guardError->getMessage(), $expectedErrors, true)) {
                throw new RuntimeException($guardError->getMessage());
            }
            throw new RuntimeException('API 数据查看保护服务暂时繁忙');
        }

        return [
            'redis' => $redis,
            'keys' => $acquiredKeys,
            'token' => $token,
        ];
    }
}

if (!function_exists('apiConfigProbeReleaseGuard')) {
    /** 释放真实 API 探测的活动锁并关闭 Redis 连接。 */
    function apiConfigProbeReleaseGuard(array $guard): void
    {
        $redis = $guard['redis'] ?? null;
        apiConfigProbeReleaseRedisLocks(
            $redis,
            is_array($guard['keys'] ?? null) ? $guard['keys'] : [],
            (string)($guard['token'] ?? '')
        );
        if ($redis) {
            try { $redis->close(); } catch (Throwable $ignored) {}
        }
    }
}

if (!function_exists('apiConfigProbeSafeResponseText')) {
    /** 将任意响应字节转换为可安全放入 JSON 的纯文本。 */
    function apiConfigProbeSafeResponseText(string $body): array
    {
        if ($body === '' || preg_match('//u', $body) === 1) {
            return ['text' => $body, 'encoding' => 'utf-8'];
        }
        return ['text' => base64_encode($body), 'encoding' => 'base64'];
    }
}

if (!function_exists('apiConfigProbePost')) {
    /**
     * 向一个已经由当前有效池选中的 API URL 发起单次 Shell POST。
     *
     * 返回值中的 raw_body 仅供同进程解密，调用方在输出浏览器前必须移除。
     */
    function apiConfigProbePost(string $deliveryUrl, array $postFields, int $maxBytes = 1048576): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('当前服务缺少 cURL 扩展');
        }

        $requestUrl = apiConfigProbeMaterializeApiUrl($deliveryUrl);
        $safeIps = bucketPublicUrlSafeIps($requestUrl);
        if (empty($safeIps)) {
            throw new RuntimeException('API 域名未解析到全部通过校验的公网 IP');
        }

        $parts = parse_url($requestUrl);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = trim((string)($parts['host'] ?? ''), '[]');
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $maxBytes = max(1024, min($maxBytes, 4 * 1024 * 1024));

        $capture = (object)[
            'headers' => [],
            'body' => '',
            'bytes' => 0,
            'too_large' => false,
        ];
        $allowedResponseHeaders = array_fill_keys([
            'content-type',
            'x-data-source',
            'x-data-source-apk',
            'x-data-source-ttl',
            'x-data-ttl',
            'x-config-resolution',
        ], true);
        $handle = curl_init($requestUrl);
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postFields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/octet-stream,text/plain;q=0.9,*/*;q=0.1',
                'Accept-Encoding: identity',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
            CURLOPT_USERAGENT => 'yunzhuru-console-api-config-probe/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use ($capture, $allowedResponseHeaders): int {
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
                    if (isset($allowedResponseHeaders[$name])) {
                        $capture->headers[$name] = substr($value, 0, 2048);
                    }
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

        // 显式清除运行环境代理，确保已校验的 DNS 固定不会被中间代理绕过。
        $options[CURLOPT_PROXY] = '';
        if (defined('CURLOPT_NOPROXY')) $options[CURLOPT_NOPROXY] = '*';

        // IP 字面量已经锁定目标；域名则固定到刚刚通过校验的公网解析结果。
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $pinnedIp = (string)$safeIps[0];
            if (strpos($pinnedIp, ':') !== false) $pinnedIp = '[' . $pinnedIp . ']';
            $options[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$pinnedIp}"];
        }
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($handle, $options);

        $startedAt = microtime(true);
        $executed = curl_exec($handle);
        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
        $httpCode = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $contentType = trim((string)(curl_getinfo($handle, CURLINFO_CONTENT_TYPE) ?: ''));
        $curlError = trim((string)curl_error($handle));
        $curlErrno = (int)curl_errno($handle);
        curl_close($handle);

        $state = 'received';
        $error = '';
        if ($capture->too_large) {
            $state = 'too_large';
            $error = 'API 响应超过 ' . $maxBytes . ' 字节安全上限';
        } elseif ($executed === false) {
            $state = $curlErrno === 28 ? 'timeout' : 'transport_error';
            $error = $curlErrno === 28
                ? 'API 请求超时'
                : 'API 网络请求异常' . ($curlErrno > 0 ? '（代码 ' . $curlErrno . '）' : '');
        } elseif ($httpCode !== 200) {
            $state = 'http_error';
            $error = "API 返回异常 HTTP {$httpCode}";
        } elseif ($capture->body === '') {
            $state = 'empty_response';
            $error = 'API 返回空响应';
        }

        $safeBody = apiConfigProbeSafeResponseText((string)$capture->body);
        $headers = (array)$capture->headers;
        $safeContentType = apiConfigProbeSafeHeaderValue(
            $contentType !== '' ? $contentType : (string)($headers['content-type'] ?? '')
        );
        return [
            'state' => $state,
            'error' => $error,
            'request_url' => $requestUrl,
            'http_code' => $httpCode,
            'content_type' => $safeContentType,
            'body' => (string)$safeBody['text'],
            'body_encoding' => (string)$safeBody['encoding'],
            'raw_body' => (string)$capture->body,
            'byte_size' => (int)$capture->bytes,
            'sha256' => hash('sha256', (string)$capture->body),
            'elapsed_ms' => $elapsedMs,
            'body_complete' => $executed !== false && !$capture->too_large,
            // 只返回诊断需要的白名单响应头，不向页面转发 Cookie 或内部网络信息。
            'data_source' => apiConfigProbeSafeHeaderValue((string)($headers['x-data-source'] ?? '')),
            'app_data_source' => apiConfigProbeSafeHeaderValue((string)($headers['x-data-source-apk'] ?? '')),
            'data_source_ttl' => apiConfigProbeSafeHeaderValue((string)($headers['x-data-source-ttl'] ?? '')),
            'data_ttl' => apiConfigProbeSafeHeaderValue((string)($headers['x-data-ttl'] ?? '')),
            'config_resolution' => apiConfigProbeSafeHeaderValue((string)($headers['x-config-resolution'] ?? '')),
        ];
    }
}
