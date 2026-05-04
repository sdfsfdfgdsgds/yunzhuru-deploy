<?php
/**
 * PHP 内置服务器路由脚本
 * 白名单过滤：只放行合法路径，其他直接关闭
 * 用法：php -S 0.0.0.0:8080 -t /var/www/html router.php
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);

// 诊断端点：确认 router.php 在运行
if ($path === '/router-status') {
    header('Content-Type: application/json');
    echo json_encode(['router' => true, 'version' => 'v27', 'time' => date('Y-m-d H:i:s')]);
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

// 根目录合法 PHP 文件
$allowedPhp = [
    '/shell.php', '/captcha.php', '/diag.php', '/diag_worker.php',
    '/down.php', '/friend_links.php', '/help.php', '/icon.php',
    '/image.php', '/logs.php', '/migrate.php', '/phpqrcode.php',
    '/release.php', '/violation.php'
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
