<?php
/**
 * PHP 内置服务器路由脚本
 * 白名单过滤：只放行合法路径，其他直接关闭
 * 用法：php -S 0.0.0.0:8080 -t /var/www/html router.php
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);

/**
 * 归一化路由安全判断使用的路径。
 *
 * PHP 内置服务器与前置代理对百分号编码、双斜线的解码时机可能不同，
 * 因此黑名单统一使用最多三轮解码后的标准路径，避免变体绕过。
 */
function normalizeSecurityRequestPath($path): ?string
{
    if (!is_string($path)) {
        return null;
    }
    if ($path === '') {
        return '/';
    }

    $decoded = $path;
    for ($round = 0; $round < 3; $round++) {
        $next = rawurldecode($decoded);
        if ($next === $decoded) {
            break;
        }
        $decoded = $next;
    }

    $decoded = str_replace('\\', '/', $decoded);
    $decoded = preg_replace('#/+#', '/', $decoded);
    if (!is_string($decoded) || strpos($decoded, "\0") !== false) {
        return null;
    }

    foreach (explode('/', $decoded) as $segment) {
        if ($segment === '..') {
            return null;
        }
    }

    return $decoded[0] === '/' ? $decoded : '/' . $decoded;
}

$path = normalizeSecurityRequestPath($path);
if ($path === null) {
    http_response_code(403);
    return true;
}

/**
 * 为后台核心静态资源返回预压缩 gzip 文件。
 *
 * Railway 当前由 PHP 内置服务器直接分发静态文件，默认没有 gzip。
 * Element Plus 这类前端资源接近 1MB，弱网下容易表现成后台白屏或长时间加载。
 */
function servePrecompressedStatic(string $path): bool
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }

    if (!preg_match('/\.(css|js|svg)$/i', $path)) {
        return false;
    }

    $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    if (stripos($acceptEncoding, 'gzip') === false) {
        return false;
    }

    if (strpos($path, "\0") !== false || strpos($path, '..') !== false) {
        http_response_code(403);
        return true;
    }

    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
    if ($root === false) {
        return false;
    }

    $file = realpath($root . $path);
    if ($file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }

    $gzFile = $file . '.gz';
    if (!is_file($gzFile)) {
        return false;
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimeMap = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'svg' => 'image/svg+xml',
    ];

    header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
    header('Content-Encoding: gzip');
    header('Vary: Accept-Encoding');
    header('Cache-Control: public, max-age=604800');
    header('Content-Length: ' . filesize($gzFile));

    if ($method !== 'HEAD') {
        readfile($gzFile);
    }

    return true;
}

// 轻量健康探针：只返回进程层状态，不读取数据库、Redis 或文件内容。
if ($path === '/healthz' || $path === '/readyz') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
    return true;
}

// 诊断端点：确认 router.php 在运行
if ($path === '/router-status') {
    header('Content-Type: application/json');
    echo json_encode(['router' => true, 'version' => 'v31', 'time' => date('Y-m-d H:i:s')]);
    return true;
}

// ========== 黑名单路径（直接拒绝，不执行任何逻辑） ==========

// 禁止访问 service/ 目录
if (strpos($path, '/service/') === 0) {
    http_response_code(403);
    return true;
}

// 禁止访问 config/ 目录
if (strpos($path, '/config/') === 0) {
    http_response_code(403);
    return true;
}

// 签名文件、转换产物和本地注入包均由一次性下载入口分发。
// uploads/signfile 是 Railway 持久卷中的真实目录，必须在 uploads 白名单前同时拦截。
$privatePathPrefixes = [
    '/signfile', '/jks', '/local_inject', '/release', '/templates',
    '/uploads/signfile', '/uploads/jks', '/uploads/release', '/uploads/templates'
];
foreach ($privatePathPrefixes as $privatePathPrefix) {
    if ($path === $privatePathPrefix || strpos($path, $privatePathPrefix . '/') === 0) {
        http_response_code(403);
        return true;
    }
}

// 禁止访问隐藏文件
if (strpos($path, '/.') !== false) {
    http_response_code(403);
    return true;
}

// ========== 白名单路径（放行） ==========

// Pure Admin 是独立的静态后台入口。目录请求交给 PHP 内置服务器，
// 由它解析 admin-pure/index.html；Hash 路由随后只访问已放行的静态资源。
if ($path === '/admin-pure' || $path === '/admin-pure/') {
    return false;
}

if (servePrecompressedStatic($path)) {
    return true;
}

// 静态资源
if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|jar|html|map)$/i', $path)) {
    return false; // PHP 内置服务器处理静态文件
}

// config.js
if ($path === '/config.js') {
    return false;
}

// API 路由
if (strpos($path, '/api/') === 0) {
    return false;
}

// 公开卡密业务入口：仅放行明确文件，避免暴露整个目录。
$allowedCardEndpoints = [
    '/kami', '/kami/', '/kami/index.php',
    '/jiebang', '/jiebang/', '/jiebang/index.php', '/jiebang/api.php',
    '/shiyong', '/shiyong/', '/shiyong/index.php'
];
if (in_array($path, $allowedCardEndpoints, true)) {
    return false;
}

// 根目录合法 PHP 文件。诊断、迁移和日志端点默认不公开，避免把运行状态或维护能力暴露到公网。
$allowedPhp = [
    '/shell.php', '/captcha.php',
    '/down.php', '/friend_links.php', '/help.php', '/icon.php',
    '/private-download.php', '/qrcode.php', '/violation.php'
];
// 维护脚本不进入生产发布仓；如需排障，使用受控 SSH 与源码目录。
if (in_array($path, $allowedPhp, true)) {
    return false;
}

// 根目录（首页）
if ($path === '/' || $path === '') {
    return false;
}

// ========== 其他所有路径 → 拒绝 ==========
// 扫描流量到这里，直接返回空响应，不消耗任何资源
http_response_code(403);
return true;
