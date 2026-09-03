<?php
/**
 * 私有运行制品下载工具。
 *
 * 证书、转换后的 JKS 和本地注入包都不应暴露为可枚举的静态路径。
 * API 在完成用户与资源归属校验后签发一次性短期令牌，
 * private-download.php 原子消费令牌后再流式输出文件。
 */

/**
 * 归一化业务传入的私有文件相对路径。
 *
 * @throws Exception 路径不属于允许的私有目录或文件不存在。
 */
function resolvePrivateDownloadFile(string $relativePath): array
{
    $normalizedPath = '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    if (strpos($normalizedPath, "\0") !== false || strpos($normalizedPath, '..') !== false) {
        throw new Exception('私有文件路径不合法');
    }

    $allowedPrefixes = ['/signfile/', '/jks/', '/local_inject/'];
    $matchedPrefix = null;
    foreach ($allowedPrefixes as $prefix) {
        if (strpos($normalizedPath, $prefix) === 0) {
            $matchedPrefix = $prefix;
            break;
        }
    }
    if ($matchedPrefix === null) {
        throw new Exception('私有文件目录不在允许范围');
    }

    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2));
    if ($documentRoot === false) {
        throw new Exception('站点根目录不存在');
    }

    $allowedRoot = realpath($documentRoot . rtrim($matchedPrefix, '/'));
    $fullPath = realpath($documentRoot . $normalizedPath);
    if (
        $allowedRoot === false ||
        $fullPath === false ||
        !is_file($fullPath) ||
        strpos($fullPath, $allowedRoot . DIRECTORY_SEPARATOR) !== 0
    ) {
        throw new Exception('私有文件不存在');
    }

    return [
        'path' => $normalizedPath,
        'full_path' => $fullPath,
        'name' => basename($fullPath),
    ];
}

/**
 * 返回私有下载入口使用的稳定公开站点基址。
 *
 * Railway 在前置代理终止 TLS，PHP 进程看到的连接可能仍是 HTTP。
 * 因此生产优先读取明确配置；本地开发再根据可信格式的 Host 和代理协议回退。
 */
function privateDownloadPublicBaseUrl(): string
{
    $configured = rtrim(trim((string)(getenv('YUNZHURU_PUBLIC_BASE_URL') ?: '')), '/');
    if ($configured !== '') {
        if (!preg_match('#^https?://[a-z0-9.-]+(?::\d+)?$#i', $configured)) {
            throw new Exception('公开下载域名配置不合法');
        }
        return $configured;
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || !preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $host)) {
        throw new Exception('公开下载域名未配置');
    }

    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $isHttps = $forwardedProto === 'https' || in_array($https, ['on', '1', 'true'], true);
    return ($isHttps ? 'https://' : 'http://') . $host;
}

/**
 * 签发一次性私有下载地址。
 *
 * @param string $relativePath 站点根目录下的私有文件路径。
 * @param string $downloadName 客户端保存时使用的文件名。
 * @param int $ttlSeconds 令牌有效期，上限五分钟。
 * @return string 以 /private-download.php 开头的相对地址。
 * @throws Exception Redis 写入失败或文件路径不合法。
 */
function issuePrivateDownloadUrl(
    string $relativePath,
    string $downloadName = '',
    int $ttlSeconds = 120
): string {
    $file = resolvePrivateDownloadFile($relativePath);
    $ttlSeconds = max(30, min(300, $ttlSeconds));
    $token = bin2hex(random_bytes(32));
    $safeName = basename($downloadName !== '' ? $downloadName : $file['name']);

    $payload = json_encode([
        'path' => $file['path'],
        'name' => $safeName,
        'issued_at' => time(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new Exception('私有下载令牌数据生成失败');
    }

    $redis = getRedisConnection(0);
    try {
        $stored = $redis->setex('private_download:' . $token, $ttlSeconds, $payload);
    } finally {
        $redis->close();
    }
    if (!$stored) {
        throw new Exception('私有下载令牌保存失败');
    }

    return '/private-download.php?token=' . $token . '&name=' . rawurlencode($safeName);
}

/**
 * 原子读取并删除一次性令牌，防止同一地址被重复下载。
 *
 * @return array|null 令牌对应的文件元数据；过期或已消费时返回 null。
 */
function consumePrivateDownloadToken(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $redis = getRedisConnection(0);
    try {
        $payload = $redis->eval(
            "local value = redis.call('GET', KEYS[1]); " .
            "if value then redis.call('DEL', KEYS[1]); end; return value",
            ['private_download:' . $token],
            1
        );
    } finally {
        $redis->close();
    }

    if (!is_string($payload) || $payload === '') {
        return null;
    }
    $metadata = json_decode($payload, true);
    return is_array($metadata) ? $metadata : null;
}
