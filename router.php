<?php
/**
 * PHP 内置服务器路由脚本
 * 白名单过滤：只放行合法路径，其他直接关闭
 * 用法：php -S 0.0.0.0:8080 -t /var/www/html router.php
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);

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

// 诊断端点：确认 router.php 在运行
if ($path === '/router-status') {
    header('Content-Type: application/json');
    echo json_encode(['router' => true, 'version' => 'v28', 'time' => date('Y-m-d H:i:s')]);
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

// 禁止访问隐藏文件
if (strpos($path, '/.') !== false) {
    http_response_code(403);
    return true;
}

// ========== 白名单路径（放行） ==========

if (servePrecompressedStatic($path)) {
    return true;
}

// 静态资源
if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|apk|jar|keystore|html|map)$/i', $path)) {
    return false; // PHP 内置服务器处理静态文件
}

// config.js
if ($path === '/config.js') {
    return false;
}

// uploads 目录
if (strpos($path, '/uploads/') === 0) {
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

// 根目录合法 PHP 文件
$allowedPhp = [
    '/shell.php', '/captcha.php', '/diag.php', '/diag_worker.php',
    '/down.php', '/friend_links.php', '/help.php', '/icon.php',
    '/image.php', '/logs.php', '/migrate.php', '/phpqrcode.php',
    '/release.php', '/violation.php', '/nettest.php', '/delete_debug.php'
];
if (in_array($path, $allowedPhp)) {
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
