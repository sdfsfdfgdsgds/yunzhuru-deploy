<?php
/**
 * 已删应用配置运行面持久化重试 worker。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/config/db.php';
require_once $root . '/config/redis.php';
require_once $root . '/api/utils/AppConfigInvalidation.php';

if (!$pdo || !($pdo instanceof PDO)) {
    fwrite(STDERR, "database unavailable\n");
    exit(2);
}

ensureAppConfigInvalidationJobTable($pdo);

while (true) {
    try {
        $rows = $pdo->query("
            SELECT app_id
            FROM cainiao_app_config_invalidation_job
            WHERE status = 'pending'
              AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            ORDER BY next_retry_at ASC, updated_at ASC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (!$rows) {
            sleep(5);
            continue;
        }

        foreach ($rows as $appId) {
            $appId = (int)$appId;
            if ($appId <= 0) {
                continue;
            }
            try {
                $result = processAppConfigInvalidationJob($pdo, $appId);
                if (!empty($result['busy'])) {
                    // 快速通道或物理清理进程正在处理同一 APPID，避免空转。
                    sleep(1);
                    continue;
                }
                echo json_encode([
                    'time' => date('Y-m-d H:i:s'),
                    'app_id' => $appId,
                    'ok' => !empty($result['ok']),
                    'attempts' => (int)($result['attempts'] ?? 0),
                    'next_retry_at' => $result['next_retry_at'] ?? null,
                    'errors' => $result['errors'] ?? [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            } catch (Throwable $e) {
                fwrite(STDERR, date('Y-m-d H:i:s') . " app_id={$appId} " . $e->getMessage() . PHP_EOL);
                sleep(2);
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, date('Y-m-d H:i:s') . ' poll failed: ' . $e->getMessage() . PHP_EOL);
        // 让 supervisor 重启进程并新建 PDO 连接，避免断线句柄在循环内永久失效。
        exit(3);
    }
}
