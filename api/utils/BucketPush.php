<?php
/**
 * 配置推送到 S3/R2/B2 存储桶
 * 在管理员保存配置后调用，将加密配置推送到所有启用的桶
 */

require_once __DIR__ . '/S3Client.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/DeletedApp.php';
require_once __DIR__ . '/BucketFeature.php';
require_once __DIR__ . '/ConfigSyncState.php';

if (!function_exists('recordBucketPushOutcome')) {
    /** 保存单个桶最近一次真实 PUT 结果，供管理页直接识别失效凭据。 */
    function recordBucketPushOutcome(PDO $pdo, int $bucketId, string $objectKey, int $code, string $message): void {
        if ($bucketId <= 0) return;
        try {
            $summary = $objectKey . ': ' . ($code === 200 ? 'ok' : ('HTTP/SDK ' . $code . ' ' . $message));
            $stmt = $pdo->prepare('UPDATE cainiao_s3_bucket SET last_push_at=UTC_TIMESTAMP(), last_push_result=:result WHERE id=:id');
            $stmt->execute([
                ':result' => mb_substr($summary, 0, 1900, 'UTF-8'),
                ':id' => $bucketId,
            ]);
        } catch (Throwable $ignored) {
            // 可观测字段写入失败不覆盖真实对象推送结果。
        }
    }
}

if (!function_exists('bucketPushAppAvailable')) {
    function bucketPushAppAvailable(PDO $pdo, int $appId): bool {
        ensureApkDeleteMarkerTable($pdo);
        $stmt = $pdo->prepare("
            SELECT 1
            FROM cainiao_apk a
            INNER JOIN cainiao_apk_config c ON c.apk_id = a.id
            LEFT JOIN cainiao_apk_deleted d ON d.apk_id = a.id
            WHERE a.id = :id
              AND d.apk_id IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $appId]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('bucketPushReuseStateToken')) {
    function bucketPushReuseStateToken(PDO $pdo, int $appId): ?string {
        $stmt = $pdo->prepare("
            SELECT
                a.config_mode,
                a.reuse_apk_id,
                a.reuse_options,
                CASE WHEN own_deleted.apk_id IS NULL THEN 1 ELSE 0 END AS own_not_deleted,
                CASE WHEN own_config.apk_id IS NULL THEN 0 ELSE 1 END AS own_config_available,
                CASE
                    WHEN a.config_mode <> 1 OR a.reuse_apk_id IS NULL OR a.reuse_apk_id <= 0 THEN 1
                    WHEN reuse_target.id IS NOT NULL
                      AND reuse_config.apk_id IS NOT NULL
                      AND reuse_deleted.apk_id IS NULL THEN 1
                    ELSE 0
                END AS reuse_target_available
            FROM cainiao_apk a
            LEFT JOIN cainiao_apk_config own_config ON own_config.apk_id = a.id
            LEFT JOIN cainiao_apk_deleted own_deleted ON own_deleted.apk_id = a.id
            LEFT JOIN cainiao_apk reuse_target ON reuse_target.id = a.reuse_apk_id
            LEFT JOIN cainiao_apk_config reuse_config ON reuse_config.apk_id = reuse_target.id
            LEFT JOIN cainiao_apk_deleted reuse_deleted ON reuse_deleted.apk_id = reuse_target.id
            WHERE a.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $appId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : null;
    }
}

/**
 * 推送单个应用的配置到所有启用的桶
 * @param PDO $pdo 数据库连接
 * @param int $appId 应用ID
 * @return array 推送结果
 */
function pushConfigToBucketsUnlocked(PDO $pdo, int $appId, int $stateRetry = 3): array {
    ensureBucketFeatureSchema($pdo);
    $stateRetry = max(0, min(3, $stateRetry));
    // 确保 ConfigHelper 中的函数可用（fetchCol/fetchMap 依赖 global $pdo）
    $GLOBALS['pdo'] = $pdo;
    if (!bucketPushAppAvailable($pdo, $appId)) {
        return ['code' => 410, 'message' => "应用 {$appId} 不存在或已删除，跳过推送"];
    }
    $initialReuseStateToken = bucketPushReuseStateToken($pdo, $appId);

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
    $stmt = $pdo->prepare("
        SELECT
            a.config_mode,
            a.reuse_apk_id,
            a.reuse_options,
            CASE
                WHEN target.id IS NOT NULL
                  AND target_config.apk_id IS NOT NULL
                  AND target_deleted.apk_id IS NULL THEN 1
                ELSE 0
            END AS reuse_target_available
        FROM cainiao_apk a
        LEFT JOIN cainiao_apk_deleted d ON d.apk_id = a.id
        LEFT JOIN cainiao_apk target ON target.id = a.reuse_apk_id
        LEFT JOIN cainiao_apk_config target_config ON target_config.apk_id = target.id
        LEFT JOIN cainiao_apk_deleted target_deleted ON target_deleted.apk_id = target.id
        WHERE a.id = :id
          AND d.apk_id IS NULL
        LIMIT 1
    ");
    $stmt->execute([':id' => $appId]);
    $apkRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($apkRow
        && (int)$apkRow['config_mode'] === 1
        && (int)$apkRow['reuse_apk_id'] > 0
        && !empty($apkRow['reuse_target_available'])) {
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
    $stateChanged = false;

    foreach ($buckets as $b) {
        try {
            if (bucketPushReuseStateToken($pdo, $appId) !== $initialReuseStateToken) {
                $stateChanged = true;
                $results[] = [
                    'bucket' => $b['name'],
                    'code' => 409,
                    'message' => '应用复用状态已变更，本轮快照整体作废',
                ];
                break;
            }

            // 生成配置到真正 PUT 之间可能恰好发生应用删除，上传前再校验一次 tombstone。
            if (!bucketPushAppAvailable($pdo, $appId)) {
                $stateChanged = true;
                $results[] = [
                    'bucket' => $b['name'],
                    'code' => 410,
                    'message' => "应用 {$appId} 不存在或已删除，跳过推送",
                ];
                break;
            }

            $client = new S3Client(
                $b['access_key'],
                $b['secret_key'],
                $b['endpoint'],
                $b['bucket'],
                $b['region'] ?: 'auto'
            );
            $result = $client->putObject(
                $objectKey,
                $encrypted,
                'application/octet-stream',
                [
                    // 配置 URL 固定，禁止 CDN/浏览器继续缓存删除或覆盖前的旧对象。
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                ]
            );

            if (bucketPushReuseStateToken($pdo, $appId) !== $initialReuseStateToken) {
                $stateChanged = true;
                $results[] = [
                    'bucket' => $b['name'],
                    'code' => 409,
                    'message' => '应用复用状态在上传期间变更，本轮快照整体作废',
                ];
                break;
            }

            // 如果删除标记在 PUT 期间写入，立即删掉刚回写的旧对象，关闭 DELETE/PUT 竞态窗口。
            if (!bucketPushAppAvailable($pdo, $appId)) {
                $stateChanged = true;
                $cleanupResult = $client->deleteObject($objectKey);
                $results[] = [
                    'bucket' => $b['name'],
                    'code' => (int)($cleanupResult['code'] ?? 500) === 200 ? 410 : 500,
                    'message' => (int)($cleanupResult['code'] ?? 500) === 200
                        ? "应用 {$appId} 已失效，刚上传的对象已回滚"
                        : "应用 {$appId} 已失效，上传对象回滚失败：" . ($cleanupResult['message'] ?? '未知错误'),
                ];
                break;
            }

            $results[] = [
                'bucket_id' => (int)$b['id'],
                'bucket' => $b['name'],
                'code' => $result['code'],
                'message' => $result['message'],
            ];
            recordBucketPushOutcome(
                $pdo,
                (int)$b['id'],
                $objectKey,
                (int)($result['code'] ?? 500),
                (string)($result['message'] ?? '')
            );
        } catch (\Throwable $e) {
            $results[] = [
                'bucket_id' => (int)($b['id'] ?? 0),
                'bucket' => $b['name'],
                'code' => 500,
                'message' => $e->getMessage(),
            ];
            recordBucketPushOutcome($pdo, (int)($b['id'] ?? 0), $objectKey, 500, $e->getMessage());
        }
    }

    // 状态变更后从第一个桶重新生成/覆盖，避免各桶落在不同快照。
    if ($stateChanged && $stateRetry > 0 && bucketPushAppAvailable($pdo, $appId)) {
        $freshResult = pushConfigToBucketsUnlocked($pdo, $appId, $stateRetry - 1);
        $freshResult['state_retry'] = true;
        $freshResult['discarded_results'] = $results;
        return $freshResult;
    }

    if ($stateChanged) {
        // 连续变更超过稳态重试上限时，删掉已选桶对象，让壳端立即回源 API，
        // 也不把不稳定快照误报为成功。
        $cleanupResults = [];
        foreach ($buckets as $b) {
            try {
                $cleanupClient = new S3Client(
                    $b['access_key'],
                    $b['secret_key'],
                    $b['endpoint'],
                    $b['bucket'],
                    $b['region'] ?: 'auto'
                );
                $cleanup = $cleanupClient->deleteObject($objectKey);
                $cleanupResults[] = [
                    'bucket' => $b['name'],
                    'code' => (int)($cleanup['code'] ?? 500),
                    'message' => $cleanup['message'] ?? '快照清理完成',
                ];
            } catch (\Throwable $e) {
                $cleanupResults[] = ['bucket' => $b['name'], 'code' => 500, 'message' => $e->getMessage()];
            }
        }
        return [
            'code' => bucketPushAppAvailable($pdo, $appId) ? 409 : 410,
            'message' => '应用配置在推送期间持续变更，旧桶快照已执行清理',
            'results' => $results,
            'cleanup_results' => $cleanupResults,
            'state_changed' => true,
        ];
    }

    $failed = array_filter($results, static function (array $item): bool {
        return (int)($item['code'] ?? 500) !== 200;
    });
    return [
        'code' => empty($failed) ? 200 : 500,
        'message' => empty($failed)
            ? "应用 {$appId} 配置已推送到全部目标桶"
            : "应用 {$appId} 有 " . count($failed) . ' 个桶推送失败',
        'partial_failure' => empty($failed) ? 0 : 1,
        'failed_count' => count($failed),
        'results' => $results,
    ];
}

/**
 * 同一 APPID 的桶推送串行化，防止旧 writer 的回滚/删除覆盖新 writer。
 */
function pushConfigToBuckets(PDO $pdo, int $appId, int $stateRetry = 3): array {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'mysql') {
        return pushConfigToBucketsUnlocked($pdo, $appId, $stateRetry);
    }

    $lockName = 'yunzhuru_cfg_push_' . $appId;
    // 每个桶请求都可能等待 5 秒，状态抖动时还会重建整轮。
    // 给等待 writer 充足时间；它取锁后会从数据库重新生成最新快照，不会沿用等待前的内容。
    $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 600)');
    $stmt->execute([':lock_name' => $lockName]);
    if ((int)$stmt->fetchColumn() !== 1) {
        return ['code' => 409, 'message' => "应用 {$appId} 配置正在推送，请稍后重试", 'results' => []];
    }

    try {
        return pushConfigToBucketsUnlocked($pdo, $appId, $stateRetry);
    } finally {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute([':lock_name' => $lockName]);
        } catch (\Throwable $e) {
        }
    }
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
    ensureApkDeleteMarkerTable($pdo);
    $stmt = $pdo->prepare("
        SELECT a.id
        FROM cainiao_apk a
        LEFT JOIN cainiao_apk_deleted d ON d.apk_id = a.id
        WHERE a.config_mode = 1
          AND a.reuse_apk_id = :id
          AND d.apk_id IS NULL
    ");
    $stmt->execute([':id' => $appId]);
    $dependents = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $dependentResults = [];
    foreach ($dependents as $depId) {
        $dependentResult = pushConfigToBuckets($pdo, (int)$depId);
        $dependentResults[(int)$depId] = $dependentResult;
        $dependentCode = (int)($dependentResult['code'] ?? 500);
        if (!in_array($dependentCode, [200, 304, 410], true)) {
            // 向上传递锁竞争/稳态耗尽结果，让 CLI 调度者重试完整的“主应用 + 依赖”快照。
            if ($dependentCode === 409 || (int)($result['code'] ?? 200) !== 409) {
                $result['code'] = $dependentCode;
            }
            $result['message'] = "复用依赖 APPID {$depId} 推送未收敛";
        }
    }

    if (!empty($dependents)) {
        $result['cascade'] = count($dependents) . ' 个复用应用已同步';
        $result['dependent_results'] = $dependentResults;
    }

    return $result;
}

/**
 * 批量推送所有应用配置到桶（管理后台"一键同步"用）
 * @param PDO $pdo
 * @return array
 */
function pushAllConfigsToBucketsUnlocked(PDO $pdo): array {
    ensureApkDeleteMarkerTable($pdo);

    // 只查有成功注入记录的应用（没注入过的无需推送配置）
    $appIds = $pdo->query("
        SELECT DISTINCT a.id FROM cainiao_apk a
        INNER JOIN cainiao_inject_task t ON t.apk_id = a.id AND t.status_text = '编译成功'
        LEFT JOIN cainiao_apk_deleted d ON d.apk_id = a.id
        WHERE d.apk_id IS NULL
    ")->fetchAll(PDO::FETCH_COLUMN);
    $results = [];
    $success = 0;
    $fail = 0;

    // 同步中心以同一份持久快照显示批量进度；状态写入失败时不阻断真实桶推送。
    $syncJobId = '';
    try {
        $syncSnapshot = configSyncStateRead($pdo);
        $syncJobId = (string)($syncSnapshot['job_id'] ?? '');
        configSyncStateMarkProgress($pdo, [
            'phase' => 'sync',
            'phase_label' => '正在同步',
            'message' => '正在同步全部应用配置',
            'expected_total' => count($appIds),
            'current_index' => 0,
            'success' => 0,
            'fail' => 0,
            'current_app_id' => 0,
        ], $syncJobId);
    } catch (Throwable $ignored) {
        $syncJobId = '';
    }

    $index = 0;
    foreach ($appIds as $appId) {
        $index++;
        if ($syncJobId !== '') {
            try {
                configSyncStateMarkProgress($pdo, [
                    'phase' => 'sync',
                    'phase_label' => '正在同步',
                    'message' => '正在同步应用 #' . (int)$appId . ' 的配置',
                    'current_index' => $index - 1,
                    'current_app_id' => (int)$appId,
                    'current_app' => '应用 #' . (int)$appId,
                    'current_bucket' => '全部启用配置桶',
                ], $syncJobId);
            } catch (Throwable $ignored) {
                // 单次状态更新失败不影响真实对象推送。
            }
        }
        $result = pushConfigToBuckets($pdo, (int)$appId);
        if ($result['code'] === 200) {
            $success++;
        } else {
            $fail++;
        }
        $results[$appId] = $result;
        if ($syncJobId !== '') {
            try {
                configSyncStateMarkProgress($pdo, [
                    'current_index' => $index,
                    'success' => $success,
                    'fail' => $fail,
                    'current_app_id' => (int)$appId,
                    'current_app' => '应用 #' . (int)$appId,
                    'current_bucket' => '全部启用配置桶',
                ], $syncJobId);
            } catch (Throwable $ignored) {
                // 单次状态更新失败不影响下一应用及最终结果。
            }
        }
    }

    return [
        'code' => $fail === 0 ? 200 : 500,
        'message' => "同步完成：成功 {$success}，失败 {$fail}，共 " . count($appIds) . " 个应用",
        'success' => $success,
        'fail' => $fail,
        'total' => count($appIds),
        'data' => $results,
    ];
}

/**
 * 全量桶同步使用 MySQL advisory lock 去重。
 *
 * 配置分发页连续保存多个节点时会连续触发异步刷新；后到任务
 * 若发现已有全量任务，直接合并为“已在同步”，避免 126 个应用重复排队。
 */
function pushAllConfigsToBuckets(PDO $pdo, bool $force = true): array {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'mysql') {
        return pushAllConfigsToBucketsUnlocked($pdo);
    }

    $lockName = 'yunzhuru_cfg_push_all';
    // 异步工作者允许等待前一轮；取锁后会检查 dirty 标记，
    // 前一工作者已经吸收了最新变更时会立即返回，不再重复遍历所有应用。
    $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 600)');
    $stmt->execute([':lock_name' => $lockName]);
    if ((int)$stmt->fetchColumn() !== 1) {
        return [
            'code' => 409,
            'message' => '已有全量配置同步在运行，本次触发已合并',
            'success' => 0,
            'fail' => 0,
            'total' => 0,
            'data' => [],
        ];
    }

    try {
        $dirtyAvailable = true;
        try {
            $dirtyStmt = $pdo->prepare("SELECT key_value FROM cainiao_config_delivery_meta
                WHERE key_name='distribution_dirty' LIMIT 1");
            $dirtyStmt->execute();
            $dirty = (string)$dirtyStmt->fetchColumn() === '1';
        } catch (Throwable $ignored) {
            $dirtyAvailable = false;
            $dirty = true;
        }

        if (!$force && $dirtyAvailable && !$dirty) {
            return [
                'code' => 200,
                'message' => '最新全局配置已由前一同步任务处理',
                'success' => 0,
                'fail' => 0,
                'total' => 0,
                'data' => [],
                'coalesced' => 1,
            ];
        }

        $passes = 0;
        $result = [];
        do {
            if ($dirtyAvailable) {
                $clearDirty = $pdo->prepare("INSERT INTO cainiao_config_delivery_meta (key_name,key_value)
                    VALUES ('distribution_dirty','0')
                    ON DUPLICATE KEY UPDATE key_value='0'");
                $clearDirty->execute();
            }

            try {
                $result = pushAllConfigsToBucketsUnlocked($pdo);
            } catch (Throwable $e) {
                if ($dirtyAvailable) {
                    try {
                        $restoreDirty = $pdo->prepare("INSERT INTO cainiao_config_delivery_meta (key_name,key_value)
                            VALUES ('distribution_dirty','1')
                            ON DUPLICATE KEY UPDATE key_value='1'");
                        $restoreDirty->execute();
                    } catch (Throwable $ignored) {}
                }
                throw $e;
            }
            $passes++;

            if (!$dirtyAvailable) {
                $dirty = false;
                break;
            }
            $dirtyStmt->execute();
            $dirty = (string)$dirtyStmt->fetchColumn() === '1';
            // 单个工作者最多吸收三轮并发修改；仍有新变更时由已等锁工作者继续。
        } while ($dirty && $passes < 3);

        $result['coalesced_passes'] = $passes;
        $result['pending_change'] = $dirty ? 1 : 0;
        return $result;
    } finally {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute([':lock_name' => $lockName]);
            $release->fetchColumn();
        } catch (Throwable $ignored) {
            // 连接断开时 MySQL 也会自动释放 advisory lock。
        }
    }
}
