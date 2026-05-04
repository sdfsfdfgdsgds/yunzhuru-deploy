#!/usr/bin/env php
<?php
/**
 * 后台推送配置到存储桶（被 afterConfigChange 异步调用）
 * 用法：php push_config.php <apk_id>
 */

if (php_sapi_name() !== 'cli') {
    exit('仅限 CLI 调用');
}

$apkId = (int)($argv[1] ?? 0);
if ($apkId <= 0) {
    exit('缺少 apk_id');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/utils/BucketPush.php';

try {
    $result = pushConfigWithDependents($pdo, $apkId);
    error_log("[push_config.php] appId={$apkId} 推送完成: " . json_encode($result));
} catch (\Throwable $e) {
    error_log("[push_config.php] appId={$apkId} 推送失败: " . $e->getMessage());
}
