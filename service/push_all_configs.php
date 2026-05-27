<?php
/**
 * 异步同步所有已注入应用的桶配置。
 * 用于全局配置变化后刷新 S3/R2/B2 上的 config/*.enc。
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/redis.php';
require_once __DIR__ . '/../api/utils/Auth.php';
require_once __DIR__ . '/../api/utils/BucketPush.php';

try {
    $result = pushAllConfigsToBuckets($pdo);
    error_log('[push_all_configs.php] 推送完成: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    error_log('[push_all_configs.php] 推送失败: ' . $e->getMessage());
    exit(1);
}
