<?php
/**
 * 应用删除后台清理 worker。
 *
 * 前台 `deleteApp()` 只写删除标记并立即返回；本脚本在后台继续清理文件、
 * OSS、桶配置、Redis 和数据库物理记录，避免用户删除时长时间等待大统计表。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
$_GET['oss'] = $_GET['oss'] ?? null;

$root = dirname(__DIR__);
chdir($root);

require_once $root . '/config/db.php';
require_once $root . '/config/redis.php';

// 加载 API 工具类，保持和 /api/index.php 一致，确保 OSS/Auth/S3/删除工具可用。
$utilsDir = $root . '/api/utils/';
foreach (glob($utilsDir . '*.php') as $file) {
    require_once $file;
}
require_once $root . '/api/module/app.php';

$options = getopt('', ['app-id:', 'user-id:', 'role:', 'progress-token:']);
$appId = (int)($options['app-id'] ?? 0);
$userId = (int)($options['user-id'] ?? 0);
$role = (string)($options['role'] ?? '');
$progressToken = (string)($options['progress-token'] ?? '');

if ($appId <= 0 || $userId <= 0 || $progressToken === '') {
    fwrite(STDERR, "参数错误：缺少 app-id/user-id/progress-token\n");
    exit(2);
}

try {
    $result = appDeleteRunCleanup($pdo, $appId, $userId, $role, $progressToken);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    appDeleteDebugLog($appId, 'cleanup_worker_failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    fwrite(STDERR, '后台清理失败：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
