<?php
/**
 * 已删应用配置运行面快速下线脚本。
 *
 * 本脚本不参与物理删库的全局串行锁，专门解决前面已有大清理任务时，
 * 新删应用的 config/{APPID}.enc 仍在桶中等待的窗口。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$appId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($appId <= 0) {
    fwrite(STDERR, "invalid app id\n");
    exit(2);
}

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/config/db.php';
require_once $root . '/config/redis.php';
require_once $root . '/api/utils/AppConfigInvalidation.php';

if (!$pdo || !($pdo instanceof PDO)) {
    fwrite(STDERR, "database unavailable\n");
    exit(3);
}

$result = processAppConfigInvalidationJob($pdo, $appId);
$ok = !empty($result['ok']);

echo json_encode([
    'app_id' => $appId,
    'ok' => $ok,
    'attempts' => (int)($result['attempts'] ?? 0),
    'next_retry_at' => $result['next_retry_at'] ?? null,
    'errors' => $result['errors'] ?? [],
    'finished_at' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 4);
