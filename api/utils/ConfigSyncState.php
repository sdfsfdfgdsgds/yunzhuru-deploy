<?php
/**
 * 配置桶全局同步状态持久化。
 *
 * 管理后台的同步中心需要在页面切换、刷新以及多个管理员窗口之间看到同一份
 * 状态，因此使用独立于 PHP 请求内存的单行 MySQL 表保存轻量快照；
 * 真实同步仍由 BucketPush/worker 执行，状态写入失败不会阻断对象推送主链路。
 */

if (!function_exists('ensureConfigSyncStateSchema')) {
    /** 创建全局同步状态表并补齐初始 idle 行；可重复调用。 */
    function ensureConfigSyncStateSchema(PDO $pdo): void
    {
        // 同一请求/worker 内会按应用多次更新进度；避免每次重复执行 DDL 和 SHOW COLUMNS。
        static $ready = [];
        $pdoKey = function_exists('spl_object_id') ? spl_object_id($pdo) : (string)(int)$pdo;
        if (isset($ready[$pdoKey])) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_config_sync_state (
            id tinyint unsigned NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'idle',
            job_id varchar(80) NOT NULL DEFAULT '',
            phase varchar(32) NOT NULL DEFAULT 'idle',
            phase_label varchar(64) NOT NULL DEFAULT '待命',
            message varchar(255) NOT NULL DEFAULT '尚未执行配置桶全量同步',
            expected_total int unsigned NOT NULL DEFAULT 0,
            current_index int unsigned NOT NULL DEFAULT 0,
            success int unsigned NOT NULL DEFAULT 0,
            fail int unsigned NOT NULL DEFAULT 0,
            current_app_id int unsigned NOT NULL DEFAULT 0,
            current_app varchar(255) NOT NULL DEFAULT '',
            current_bucket varchar(255) NOT NULL DEFAULT '',
            started_at datetime NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            finished_at datetime NULL,
            reasons text NULL,
            result_json longtext NULL,
            PRIMARY KEY (id),
            KEY idx_config_sync_status (status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配置桶全局同步状态'");
        $pdo->exec("INSERT IGNORE INTO cainiao_config_sync_state
            (id,status,phase,phase_label,message,reasons,result_json)
            VALUES (1,'idle','idle','待命','尚未执行配置桶全量同步','[]','{}')");
        // 已存在的旧状态表按需补列，保证滚动发布期间接口字段始终完整。
        foreach (['current_app' => "varchar(255) NOT NULL DEFAULT ''", 'current_bucket' => "varchar(255) NOT NULL DEFAULT ''"] as $column => $definition) {
            try {
                $check = $pdo->query("SHOW COLUMNS FROM cainiao_config_sync_state LIKE '{$column}'");
                if (!$check || !$check->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->exec("ALTER TABLE cainiao_config_sync_state ADD COLUMN {$column} {$definition}");
                }
            } catch (Throwable $ignored) {
                // 只读数据库或并发迁移时保留已有字段，状态主链路仍可读取。
            }
        }
        $ready[$pdoKey] = true;
    }
}

if (!function_exists('configSyncStateNow')) {
    /** 生成短且可读的任务 ID；不携带任何凭据或业务数据。 */
    function configSyncStateNow(): string
    {
        try {
            return 'cfg-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
        } catch (Throwable $ignored) {
            return 'cfg-' . gmdate('YmdHis') . '-' . substr(hash('sha256', uniqid('', true)), 0, 10);
        }
    }
}

if (!function_exists('configSyncStateJson')) {
    /** 将任意值编码为可安全落库的 JSON。 */
    function configSyncStateJson($value, string $fallback = '[]'): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        // S3 返回体偶尔含非 UTF-8 字节；用替换字符保住整份结果，不让一条错误
        // 消息导致 result_json 整体退回空对象，进而丢失成功/失败列表。
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($value, $flags);
        return is_string($json) ? $json : $fallback;
    }
}

if (!function_exists('configSyncStateClassifyAppResult')) {
    /**
     * 判定单个 APP 的聚合结果。
     *
     * code=500 只代表该 APP 至少有一个桶失败，不能直接推出“所有桶都失败”。
     * 统一在这里计算成功桶、失败桶和 APP 级状态，批量 worker 与状态快照共用同一
     * 口径，避免 B2 成功而 AWS/R2 失败时被错误统计为全失败。
     */
    function configSyncStateClassifyAppResult(array $result): array
    {
        $rawBuckets = $result['buckets'] ?? null;
        if (!is_array($rawBuckets) || !$rawBuckets) $rawBuckets = $result['results'] ?? [];
        if (!is_array($rawBuckets)) $rawBuckets = [];
        // cleanup_results 是旧快照删除操作，不是本轮配置 PUT；保留其结果供
        // 明细展示，但不把清理成功计入 bucket_success。归一化后的历史快照
        // 可能把清理项放回 buckets，因此这里再按 phase 过滤一次。
        $primaryBuckets = [];
        $embeddedCleanup = [];
        foreach ($rawBuckets as $bucket) {
            if (!is_array($bucket)) continue;
            if (strtolower(trim((string)($bucket['phase'] ?? ''))) === 'cleanup') {
                $embeddedCleanup[] = $bucket;
            } else {
                $primaryBuckets[] = $bucket;
            }
        }
        // 某些历史快照同时带有 buckets（仅清理项）和 results（真实上传项），
        // 不能因为前者非空就漏掉后者。
        if (!$primaryBuckets && is_array($result['results'] ?? null) && $result['results']) {
            foreach ($result['results'] as $bucket) {
                if (!is_array($bucket)) continue;
                if (strtolower(trim((string)($bucket['phase'] ?? ''))) === 'cleanup') {
                    $embeddedCleanup[] = $bucket;
                } else {
                    $primaryBuckets[] = $bucket;
                }
            }
        }
        $rawBuckets = $primaryBuckets;
        $cleanupBuckets = is_array($result['cleanup_results'] ?? null)
            ? $result['cleanup_results'] : [];
        if ($embeddedCleanup) $cleanupBuckets = array_merge($cleanupBuckets, $embeddedCleanup);
        if ($cleanupBuckets) {
            $uniqueCleanup = [];
            $seenCleanup = [];
            foreach ($cleanupBuckets as $bucket) {
                if (!is_array($bucket)) continue;
                $cleanupKey = implode('|', [
                    (string)($bucket['bucket_id'] ?? $bucket['bucket'] ?? ''),
                    (string)($bucket['object_key'] ?? ''),
                    (string)($bucket['code'] ?? ''),
                    (string)($bucket['message'] ?? ''),
                ]);
                if (isset($seenCleanup[$cleanupKey])) continue;
                $seenCleanup[$cleanupKey] = true;
                $uniqueCleanup[] = $bucket;
            }
            $cleanupBuckets = $uniqueCleanup;
        }
        $bucketSuccess = 0;
        $bucketFail = 0;
        foreach ($rawBuckets as $bucket) {
            if (!is_array($bucket)) continue;
            if ((int)($bucket['code'] ?? 500) === 200) $bucketSuccess++;
            else $bucketFail++;
        }
        $cleanupFail = 0;
        foreach ($cleanupBuckets as $bucket) {
            if (!is_array($bucket)) continue;
            if ((int)($bucket['code'] ?? 500) !== 200) $cleanupFail++;
        }
        // 某些旧接口只保留桶级计数，没有逐桶 results；优先使用该计数恢复
        // 部分成功语义，避免摘要退化为“无桶而跳过”。
        if (!$rawBuckets) {
            $reportedSuccess = max(0, (int)($result['bucket_success']
                ?? $result['bucket_success_count'] ?? $result['successful_bucket_count'] ?? 0));
            $reportedFail = max(0, (int)($result['bucket_fail']
                ?? $result['bucket_failed_count'] ?? $result['failed_bucket_count'] ?? 0));
            if ($reportedSuccess + $reportedFail > 0) {
                $bucketSuccess = $reportedSuccess;
                $bucketFail = $reportedFail;
            }
        }
        $appCode = (int)($result['code'] ?? 200);
        $message = trim((string)($result['message'] ?? ''));
        $statusHint = strtolower(trim((string)($result['status'] ?? $result['outcome'] ?? '')));
        if ($rawBuckets || $bucketSuccess > 0 || $bucketFail > 0) {
            if ($bucketSuccess > 0 && $bucketFail > 0) {
                $status = 'partial';
            } elseif ($bucketFail > 0 || $appCode !== 200) {
                $status = 'failed';
            } else {
                $status = 'success';
            }
            if ($cleanupFail > 0 && $status === 'success') $status = 'partial';
        } elseif ($cleanupFail > 0) {
            // 没有新的 PUT 但旧对象清理失败，不能把这次操作当作普通跳过。
            $status = 'failed';
        } elseif (preg_match('/partial|partial_success|部分成功|部分失败/u', $statusHint)) {
            $status = 'partial';
        } elseif (preg_match('/fail|failed|failure|error|异常|失败/u', $statusHint)) {
            $status = 'failed';
        } elseif (preg_match('/skip|skipped|跳过/u', $statusHint)) {
            $status = 'skipped';
        } elseif ($appCode === 304 || preg_match('/无启用|无需推送|无注入记录|跳过/u', $message)) {
            $status = 'skipped';
        } elseif ($appCode === 200) {
            // 兼容旧返回：没有桶明细但 code=200 通常是“无启用桶”，归为跳过，
            // 避免把没有实际上传对象的 APP 计成成功。
            $status = 'skipped';
        } else {
            $status = 'failed';
        }
        return [
            'status' => $status,
            'code' => $appCode,
            'bucket_total' => $bucketSuccess + $bucketFail,
            'bucket_success' => $bucketSuccess,
            'bucket_fail' => $bucketFail,
            'has_successful_bucket' => $bucketSuccess > 0 ? 1 : 0,
            'has_failed_bucket' => $bucketFail > 0 ? 1 : 0,
            'cleanup_total' => count(array_filter($cleanupBuckets, 'is_array')),
            'cleanup_fail' => $cleanupFail,
        ];
    }
}

if (!function_exists('configSyncStateNormalizeResultSummary')) {
    /**
     * 将 worker 的按 APPID 结果归一成管理页可以直接展示的明细。
     *
     * 旧版结果只有 data[APPID].results[bucket]，没有应用名称、对象路径或统一
     * 状态。这里集中补齐公开身份和计数，禁止把 access/secret 等凭据带入快照；
     * 同一函数同时用于运行中增量状态和终态，避免前端在两种状态下使用两套合同。
     */
    function configSyncStateNormalizeResultSummary(array $result): array
    {
        $source = [];
        if (is_array($result['data'] ?? null) && $result['data']) {
            $source = $result['data'];
        } elseif (is_array($result['app_results'] ?? null) && $result['app_results']) {
            // 兼容已经被新版本归一过、但没有保留原始 data 的历史快照。
            $source = $result['app_results'];
        }

        $appResults = [];
        $successfulApps = [];
        $partialApps = [];
        $failedApps = [];
        $skippedApps = [];
        $successItems = [];
        $failedItems = [];
        $skippedItems = [];
        $cleanupItems = [];
        $allItems = [];

        foreach ($source as $sourceKey => $rawApp) {
            if (!is_array($rawApp)) continue;

            $rawIdentity = is_array($rawApp['app'] ?? null) ? $rawApp['app'] : [];
            $appId = (int)($rawApp['app_id'] ?? $rawIdentity['id'] ?? $rawIdentity['app_id'] ?? (is_numeric($sourceKey) ? $sourceKey : 0));
            $appName = trim((string)($rawApp['app_name'] ?? $rawIdentity['app_name'] ?? $rawIdentity['name'] ?? ''));
            $packageName = trim((string)($rawApp['package_name'] ?? $rawIdentity['package_name'] ?? $rawIdentity['package'] ?? ''));
            $objectKey = trim((string)($rawApp['object_key'] ?? $rawApp['config_object'] ?? ''));
            if ($objectKey === '' && $appId > 0) $objectKey = 'config/' . $appId . '.enc';
            $configScope = trim((string)($rawApp['config_scope'] ?? '应用完整配置'));
            if ($configScope === '') $configScope = '应用完整配置';
            $appCode = (int)($rawApp['code'] ?? 200);
            $appMessage = trim((string)($rawApp['message'] ?? ''));

            $rawBuckets = $rawApp['buckets'] ?? null;
            if (!is_array($rawBuckets) || !$rawBuckets) $rawBuckets = $rawApp['results'] ?? [];
            if (!is_array($rawBuckets)) $rawBuckets = [];
            // 归一化后的历史快照可能把清理项放回 buckets；先拆出真正的
            // 配置 PUT，再把清理结果单独保留到操作明细，避免计数混淆。
            $primaryBuckets = [];
            $embeddedCleanup = [];
            foreach ($rawBuckets as $rawBucket) {
                if (!is_array($rawBucket)) continue;
                if (strtolower(trim((string)($rawBucket['phase'] ?? ''))) === 'cleanup') {
                    $embeddedCleanup[] = $rawBucket;
                } else {
                    $primaryBuckets[] = $rawBucket;
                }
            }
            if (!$primaryBuckets && is_array($rawApp['results'] ?? null) && $rawApp['results']) {
                foreach ($rawApp['results'] as $rawBucket) {
                    if (!is_array($rawBucket)) continue;
                    if (strtolower(trim((string)($rawBucket['phase'] ?? ''))) === 'cleanup') {
                        $embeddedCleanup[] = $rawBucket;
                    } else {
                        $primaryBuckets[] = $rawBucket;
                    }
                }
            }
            $rawCleanup = is_array($rawApp['cleanup_results'] ?? null)
                ? $rawApp['cleanup_results'] : [];
            $rawCleanup = array_merge($rawCleanup, $embeddedCleanup);
            if ($rawCleanup) {
                $uniqueCleanup = [];
                $seenCleanup = [];
                foreach ($rawCleanup as $cleanupItem) {
                    if (!is_array($cleanupItem)) continue;
                    $cleanupKey = implode('|', [
                        (string)($cleanupItem['bucket_id'] ?? $cleanupItem['bucket'] ?? ''),
                        (string)($cleanupItem['object_key'] ?? $objectKey),
                        (string)($cleanupItem['code'] ?? ''),
                        (string)($cleanupItem['message'] ?? ''),
                    ]);
                    if (isset($seenCleanup[$cleanupKey])) continue;
                    $seenCleanup[$cleanupKey] = true;
                    $uniqueCleanup[] = $cleanupItem;
                }
                $rawCleanup = $uniqueCleanup;
            }
            $bucketItems = [];
            $appCleanupItems = [];
            $normalizeBucketItem = static function (array $rawBucket, string $phase) use ($appId, $appName, $packageName, $configScope, $objectKey): array {
                $bucketIdentity = is_array($rawBucket['bucket'] ?? null) ? $rawBucket['bucket'] : [];
                $bucketId = (int)($rawBucket['bucket_id'] ?? $bucketIdentity['id'] ?? 0);
                $bucketName = trim((string)($rawBucket['bucket_name'] ?? ''));
                if ($bucketName === '' && is_string($rawBucket['bucket'] ?? null)) {
                    $bucketName = trim((string)$rawBucket['bucket']);
                }
                if ($bucketName === '') {
                    $bucketName = trim((string)($bucketIdentity['name'] ?? ''));
                }
                $provider = trim((string)($rawBucket['provider'] ?? $bucketIdentity['provider'] ?? ''));
                $code = (int)($rawBucket['code'] ?? 500);
                $status = $code === 200 ? 'success' : 'failed';
                $item = [
                    'status' => $status,
                    'phase' => $phase,
                    'code' => $code,
                    'app_id' => $appId,
                    'app_name' => $appName,
                    'package_name' => $packageName,
                    'config_scope' => $configScope,
                    'object_key' => trim((string)($rawBucket['object_key'] ?? $objectKey)),
                    'bucket_id' => $bucketId,
                    'bucket' => $bucketName,
                    'bucket_name' => $bucketName,
                    'provider' => $provider,
                    'message' => trim((string)($rawBucket['message'] ?? ($status === 'success' ? '上传成功' : '同步失败'))),
                ];
                if (array_key_exists('http_code', $rawBucket)) {
                    $item['http_code'] = (int)$rawBucket['http_code'];
                }
                return $item;
            };
            foreach ($primaryBuckets as $rawBucket) {
                if (!is_array($rawBucket)) continue;
                $item = $normalizeBucketItem($rawBucket, trim((string)($rawBucket['phase'] ?? 'sync')) ?: 'sync');
                $bucketItems[] = $item;
                $allItems[] = $item;
                if (($item['status'] ?? '') === 'success') {
                    $successItems[] = $item;
                } else {
                    $failedItems[] = $item;
                }
            }
            foreach ($rawCleanup as $rawBucket) {
                if (!is_array($rawBucket)) continue;
                $item = $normalizeBucketItem($rawBucket, 'cleanup');
                $appCleanupItems[] = $item;
                $cleanupItems[] = $item;
                $allItems[] = $item;
                // 清理失败需要提醒；清理成功只保留在“全部/清理阶段”明细，
                // 不计入成功上传对象。
                if (($item['status'] ?? '') !== 'success') $failedItems[] = $item;
            }

            $appOutcome = configSyncStateClassifyAppResult($rawApp);
            $bucketSuccess = (int)($appOutcome['bucket_success'] ?? 0);
            $bucketFail = (int)($appOutcome['bucket_fail'] ?? 0);
            $appStatus = (string)($appOutcome['status'] ?? 'failed');

            $appResult = [
                'status' => $appStatus,
                'outcome' => $appStatus,
                'partial_success' => $appStatus === 'partial' ? 1 : 0,
                'code' => $appCode,
                'app_id' => $appId,
                'app_name' => $appName,
                'package_name' => $packageName,
                'config_scope' => $configScope,
                'object_key' => $objectKey,
                'message' => $appMessage,
                'bucket_total' => $bucketSuccess + $bucketFail,
                'bucket_success' => $bucketSuccess,
                'bucket_fail' => $bucketFail,
                'has_successful_bucket' => $bucketSuccess > 0 ? 1 : 0,
                'has_failed_bucket' => $bucketFail > 0 ? 1 : 0,
                'cleanup_total' => count($appCleanupItems),
                'cleanup_fail' => count(array_filter($appCleanupItems, static function (array $item): bool {
                    return (int)($item['code'] ?? 500) !== 200;
                })),
                'buckets' => $bucketItems,
                'cleanup_results' => $appCleanupItems,
            ];
            $appResults[] = $appResult;

            if (!$bucketItems) {
                $appItem = [
                    'status' => $appStatus,
                    'phase' => 'app',
                    'code' => $appCode,
                    'app_id' => $appId,
                    'app_name' => $appName,
                    'package_name' => $packageName,
                    'config_scope' => $configScope,
                    'object_key' => $objectKey,
                    'bucket_id' => 0,
                    'bucket' => '',
                    'bucket_name' => '',
                    'provider' => '',
                    'message' => $appMessage !== '' ? $appMessage : ($appStatus === 'skipped' ? '本应用没有需要推送的目标桶' : '应用配置同步失败'),
                ];
                $allItems[] = $appItem;
                if ($appStatus === 'success') $successItems[] = $appItem;
                elseif ($appStatus === 'skipped') $skippedItems[] = $appItem;
                else $failedItems[] = $appItem;
            }

            if ($appStatus === 'success') $successfulApps[] = $appResult;
            elseif ($appStatus === 'partial') {
                // partial APP 不是全失败：单独归类，避免顶部“失败应用”把它算进去。
                $partialApps[] = $appResult;
            }
            elseif ($appStatus === 'skipped') $skippedApps[] = $appResult;
            else $failedApps[] = $appResult;
        }

        // worker 在数据库/脚本级异常时可能没有任何 APP 行；补一条任务级失败项，
        // 让管理页仍能明确显示“失败发生在全局任务本身”，而不是只显示空列表。
        $taskCode = (int)($result['code'] ?? 200);
        $taskHasFailure = $taskCode !== 200
            || (!$source && (int)($result['fail'] ?? 0) > 0);
        if ($taskHasFailure && !$failedItems) {
            $failedItems[] = [
                'status' => 'failed',
                'phase' => 'task',
                'code' => $taskCode !== 200 ? $taskCode : 500,
                'app_id' => 0,
                'app_name' => '',
                'package_name' => '',
                'config_scope' => '全局配置同步任务',
                'object_key' => '',
                'bucket_id' => 0,
                'bucket' => '',
                'bucket_name' => '',
                'provider' => '',
                'message' => trim((string)($result['message'] ?? '全局配置同步任务失败')),
            ];
            $allItems[] = $failedItems[count($failedItems) - 1];
        }

        // 旧版结果只有桶名称，没有 bucket_id；只按 ID 过滤会把历史 B2/S3/R2
        // 明细全部漏掉。同步对象的阶段已经足以和 APP/任务级占位项区分，故以
        // phase 排除 cleanup、app、task，同时兼容有无 bucket_id 的两种格式。
        $isPrimaryBucketItem = static function (array $item): bool {
            return !in_array(strtolower(trim((string)($item['phase'] ?? 'sync'))), ['cleanup', 'app', 'task'], true);
        };
        $bucketSuccessCount = count(array_filter($successItems, $isPrimaryBucketItem));
        $bucketFailedCount = count(array_filter($failedItems, $isPrimaryBucketItem));
        $cleanupFailedCount = count(array_filter($failedItems, static function (array $item): bool {
            return (string)($item['phase'] ?? 'sync') === 'cleanup';
        }));
        $summary = [
            'app_total' => count($appResults),
            'app_success_count' => count($successfulApps),
            'app_partial_count' => count($partialApps),
            'partial_count' => count($partialApps),
            'app_failed_count' => count($failedApps),
            'app_skipped_count' => count($skippedApps),
            // 至少有一个桶成功的 APP（包含 partial），便于页面回答“哪些已经
            // 更新到 B2”等问题；app_success_count 仍只表示全桶成功，保持语义清晰。
            'app_successful_count' => count($successfulApps) + count($partialApps),
            'app_unsuccessful_count' => count($failedApps),
            'item_total' => count($allItems),
            'success_item_count' => count($successItems),
            'failed_item_count' => count($failedItems),
            'skipped_item_count' => count($skippedItems),
            'bucket_total' => $bucketSuccessCount + $bucketFailedCount,
            'bucket_success_count' => $bucketSuccessCount,
            'bucket_failed_count' => $bucketFailedCount,
            'bucket_success' => $bucketSuccessCount,
            'bucket_fail' => $bucketFailedCount,
            'cleanup_item_count' => count($cleanupItems),
            'cleanup_failed_count' => $cleanupFailedCount,
            // 列表字段的短别名方便旧版外壳直接读取，但不改变原有 app success/fail 计数。
            'success_count' => count($successItems),
            'fail_count' => count($failedItems),
        ];

        return [
            'app_results' => $appResults,
            'successful_apps' => $successfulApps,
            'partial_apps' => $partialApps,
            'failed_apps' => $failedApps,
            'skipped_apps' => $skippedApps,
            'success_items' => $successItems,
            'successful_items' => $successItems,
            'failed_items' => $failedItems,
            'skipped_items' => $skippedItems,
            'cleanup_items' => $cleanupItems,
            'sync_items' => $allItems,
            'items' => $allItems,
            'result_summary' => $summary,
        ];
    }
}

if (!function_exists('configSyncStateRead')) {
    /** 读取并规范化当前单行同步快照，供 API 和 worker 共同复用。 */
    function configSyncStateRead(PDO $pdo): array
    {
        ensureConfigSyncStateSchema($pdo);
        $row = $pdo->query('SELECT * FROM cainiao_config_sync_state WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        $formatTime = static function ($value): string {
            $value = trim((string)$value);
            if ($value === '') return '';
            try {
                return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Asia/Shanghai'))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable $ignored) {
                return $value;
            }
        };
        $reasons = json_decode((string)($row['reasons'] ?? '[]'), true);
        $result = json_decode((string)($row['result_json'] ?? '{}'), true);
        if (!is_array($reasons)) $reasons = [];
        if (!is_array($result)) $result = [];
        // 统一归一化 APP、桶和对象级结果。旧快照没有这些字段时也能由 data
        // 推导；新快照则直接复用同一份公开明细，保证轮询和终态展示一致。
        $details = configSyncStateNormalizeResultSummary($result);
        // 旧版本曾把“任一桶失败”直接落成 failed。读取历史快照时按已经保存的
        // 桶级明细重算一次有效状态，让 B2 成功 + AWS/R2 失败的既有任务立即显示
        // 为“部分失败”，无需用户先重新执行一轮同步才能看到真实结果。
        $storedStatus = (string)($row['status'] ?? 'idle');
        $effectiveStatus = $storedStatus !== '' ? $storedStatus : 'idle';
        if (!in_array($storedStatus, ['queued', 'running'], true)) {
            $summary = $details['result_summary'] ?? [];
            $hasSuccess = (int)($summary['app_success_count'] ?? 0) > 0
                || (int)($summary['app_partial_count'] ?? 0) > 0
                || (int)($summary['bucket_success_count'] ?? 0) > 0;
            $hasFailure = (int)($summary['app_failed_count'] ?? 0) > 0
                || (int)($summary['bucket_failed_count'] ?? 0) > 0
                || (int)($summary['cleanup_failed_count'] ?? 0) > 0;
            if ($hasSuccess && $hasFailure) {
                $effectiveStatus = 'partial_failure';
            } elseif ($hasSuccess && $storedStatus === 'failed') {
                // 任务级异常发生在已有成功对象之后，保留“部分失败”语义，
                // 不让旧的 failed 状态抹掉此前已经完成的对象事实。
                $effectiveStatus = 'partial_failure';
            } elseif ($hasSuccess && $storedStatus === 'partial_failure' && !$hasFailure) {
                $effectiveStatus = 'completed';
            } elseif (!$hasSuccess && $hasFailure) {
                $effectiveStatus = 'failed';
            }
        }
        return [
            'status' => $effectiveStatus,
            'job_id' => (string)($row['job_id'] ?? ''),
            'phase' => (string)($row['phase'] ?? 'idle'),
            'phase_label' => (string)($row['phase_label'] ?? '待命'),
            'message' => (string)($row['message'] ?? '尚未执行配置桶全量同步'),
            'expected_total' => (int)($row['expected_total'] ?? 0),
            'current_index' => (int)($row['current_index'] ?? 0),
            'success' => (int)($row['success'] ?? 0),
            'fail' => (int)($row['fail'] ?? 0),
            // 兼容旧页面的标量字段，同时补充“部分成功”和桶级计数；旧的
            // success/fail 仍表示全桶成功/全桶失败 APP 数，不再混入 partial。
            'partial' => (int)($details['result_summary']['app_partial_count'] ?? 0),
            'partial_success' => (int)($details['result_summary']['app_partial_count'] ?? 0),
            'app_partial_count' => (int)($details['result_summary']['app_partial_count'] ?? 0),
            'skipped' => (int)($details['result_summary']['app_skipped_count'] ?? 0),
            'bucket_success' => (int)($details['result_summary']['bucket_success_count'] ?? 0),
            'bucket_fail' => (int)($details['result_summary']['bucket_failed_count'] ?? 0),
            'bucket_total' => (int)($details['result_summary']['bucket_total'] ?? 0),
            'cleanup_fail' => (int)($details['result_summary']['cleanup_failed_count'] ?? 0),
            'current_app_id' => (int)($row['current_app_id'] ?? 0),
            'current_app' => !empty($row['current_app'])
                ? (string)$row['current_app']
                : (($row['current_app_id'] ?? 0) > 0 ? '应用 #' . (int)$row['current_app_id'] : ''),
            'current_bucket' => (string)($row['current_bucket'] ?? ''),
            'total' => (int)($row['expected_total'] ?? 0),
            'current' => (int)($row['current_index'] ?? 0),
            // 数据库统一使用 UTC 写入，管理页合同输出北京时间可读值。
            'started_at' => $formatTime($row['started_at'] ?? ''),
            'updated_at' => $formatTime($row['updated_at'] ?? ''),
            'finished_at' => $formatTime($row['finished_at'] ?? ''),
            'reasons' => array_values(array_filter(array_map('strval', $reasons))),
            'success_items' => $details['success_items'],
            'successful_items' => $details['successful_items'],
            'failed_items' => $details['failed_items'],
            'skipped_items' => $details['skipped_items'],
            'cleanup_items' => $details['cleanup_items'],
            'sync_items' => $details['sync_items'],
            'items' => $details['items'],
            'app_results' => $details['app_results'],
            'successful_apps' => $details['successful_apps'],
            'partial_apps' => $details['partial_apps'],
            'failed_apps' => $details['failed_apps'],
            'skipped_apps' => $details['skipped_apps'],
            'result_summary' => $details['result_summary'],
            'result' => $result,
            // 与旧版管理页的 results 命名保持兼容：优先暴露按 APPID 分组的结果，
            // 完整摘要仍保留在 result 字段，避免前端把 code/message 当作应用条目。
            'results' => is_array($result['data'] ?? null) ? $result['data'] : $result,
        ];
    }
}

if (!function_exists('configSyncStateMarkDirty')) {
    /** 同步前设置 dirty 标记，避免 worker 的合并模式把本次变更误判为已处理。 */
    function configSyncStateMarkDirty(PDO $pdo): void
    {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_config_delivery_meta (
                key_name varchar(64) NOT NULL,
                key_value varchar(255) NOT NULL,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (key_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配置分发迁移状态'");
            $stmt = $pdo->prepare("INSERT INTO cainiao_config_delivery_meta (key_name,key_value)
                VALUES ('distribution_dirty','1')
                ON DUPLICATE KEY UPDATE key_value='1'");
            $stmt->execute();
        } catch (Throwable $ignored) {
            // 状态表/脏标记不可用时，推送接口仍可由 force 模式完成。
        }
    }
}

if (!function_exists('configSyncStateMarkQueued')) {
    /** 标记排队任务并返回快照；运行中的任务只追加原因并复用当前代次。 */
    function configSyncStateMarkQueued(PDO $pdo, string $reason = '配置变更'): array
    {
        ensureConfigSyncStateSchema($pdo);
        $reason = trim($reason) !== '' ? trim($reason) : '配置变更';
        $current = configSyncStateRead($pdo);
        if (in_array((string)($current['status'] ?? ''), ['queued', 'running'], true)
            && (string)($current['job_id'] ?? '') !== '') {
            $reasons = array_values(array_unique(array_merge($current['reasons'] ?? [], [$reason])));
            $stmt = $pdo->prepare("UPDATE cainiao_config_sync_state SET
                message=:message, reasons=:reasons, updated_at=UTC_TIMESTAMP() WHERE id=1 AND job_id=:job_id");
            $stmt->execute([
                ':message' => '已合并变更：' . $reason,
                ':reasons' => configSyncStateJson($reasons),
                ':job_id' => $current['job_id'],
            ]);
            configSyncStateMarkDirty($pdo);
            return configSyncStateRead($pdo);
        }
        $jobId = configSyncStateNow();
        $stmt = $pdo->prepare("UPDATE cainiao_config_sync_state SET
            status='queued', job_id=:job_id, phase='queued', phase_label='等待同步',
            message=:message, expected_total=0, current_index=0, success=0, fail=0,
            current_app_id=0, current_app='', current_bucket='', started_at=NULL, finished_at=NULL, reasons=:reasons,
            result_json='{}', updated_at=UTC_TIMESTAMP()
            WHERE id=1");
        $stmt->execute([
            ':job_id' => $jobId,
            ':message' => '已排队：' . $reason,
            ':reasons' => configSyncStateJson([$reason]),
        ]);
        configSyncStateMarkDirty($pdo);
        return configSyncStateRead($pdo);
    }
}

if (!function_exists('configSyncStateMarkRunning')) {
    /** 将指定代次切换为 running；CAS 防止旧 worker 覆盖较新的排队任务。 */
    function configSyncStateMarkRunning(PDO $pdo, string $jobId = '', string $message = '正在同步全部配置'): array
    {
        ensureConfigSyncStateSchema($pdo);
        if ($jobId === '') $jobId = (string)(configSyncStateRead($pdo)['job_id'] ?? '');
        $stmt = $pdo->prepare("UPDATE cainiao_config_sync_state SET
            status='running', phase='sync', phase_label='正在同步', message=:message,
            started_at=COALESCE(started_at,UTC_TIMESTAMP()), finished_at=NULL,
            updated_at=UTC_TIMESTAMP()
            WHERE id=1 AND job_id=:job_id AND status IN ('queued','running')");
        $stmt->execute([':job_id' => $jobId, ':message' => $message]);
        return configSyncStateRead($pdo);
    }
}

if (!function_exists('configSyncStateMarkProgress')) {
    /** 更新同步中心进度；仅允许写入当前 job，避免并发任务回填旧计数。 */
    function configSyncStateMarkProgress(PDO $pdo, array $progress = [], string $jobId = ''): array
    {
        ensureConfigSyncStateSchema($pdo);
        if ($jobId === '') $jobId = (string)(configSyncStateRead($pdo)['job_id'] ?? '');
        $allowed = [
            'phase' => 'phase', 'phase_label' => 'phase_label', 'message' => 'message',
            'expected_total' => 'expected_total', 'current_index' => 'current_index',
            'success' => 'success', 'fail' => 'fail', 'current_app_id' => 'current_app_id',
            'current_app' => 'current_app', 'current_bucket' => 'current_bucket',
        ];
        $sets = [];
        $params = [':job_id' => $jobId];
        foreach ($allowed as $inputKey => $column) {
            if (!array_key_exists($inputKey, $progress)) continue;
            $sets[] = "{$column}=:{$inputKey}";
            $params[":" . $inputKey] = is_numeric($progress[$inputKey]) && $inputKey !== 'message' && $inputKey !== 'phase' && $inputKey !== 'phase_label'
                ? max(0, (int)$progress[$inputKey])
                : trim((string)$progress[$inputKey]);
        }
        // 运行中也持久化已完成 APP 的明细，抽屉无需等到终态才知道具体更新了什么。
        // result_json 使用同一归一化函数，避免前端在 running/completed 间切换数据形状。
        if (array_key_exists('result', $progress)) {
            $partialResult = is_array($progress['result']) ? $progress['result'] : [];
            $partialResult = array_merge(
                $partialResult,
                configSyncStateNormalizeResultSummary($partialResult)
            );
            $sets[] = 'result_json=:result_json';
            $params[':result_json'] = configSyncStateJson($partialResult, '{}');
        }
        if (!$sets) return configSyncStateRead($pdo);
        $sets[] = 'updated_at=UTC_TIMESTAMP()';
        $stmt = $pdo->prepare('UPDATE cainiao_config_sync_state SET ' . implode(',', $sets) .
            " WHERE id=1 AND job_id=:job_id AND status IN ('queued','running')");
        $stmt->execute($params);
        return configSyncStateRead($pdo);
    }
}

if (!function_exists('configSyncStateMarkFinished')) {
    /** 固化同步终态与摘要；异常任务使用 failed，部分应用失败使用 partial_failure。 */
    function configSyncStateMarkFinished(PDO $pdo, array $result = [], string $jobId = '', ?Throwable $error = null): array
    {
        ensureConfigSyncStateSchema($pdo);
        if ($jobId === '') $jobId = (string)(configSyncStateRead($pdo)['job_id'] ?? '');
        // worker 在处理中途抛出异常时通常只携带异常信息；先取回当前代次已经
        // 落库的 APP 明细，避免终态写入空结果而抹掉此前成功的 B2/其他桶记录。
        if (!is_array($result['data'] ?? null) || !$result['data']) {
            try {
                $persistedStmt = $pdo->prepare("SELECT result_json FROM cainiao_config_sync_state
                    WHERE id=1 AND job_id=:job_id AND status IN ('queued','running') LIMIT 1");
                $persistedStmt->execute([':job_id' => $jobId]);
                $persistedRaw = json_decode((string)$persistedStmt->fetchColumn(), true);
                if (is_array($persistedRaw)) {
                    if (is_array($persistedRaw['data'] ?? null) && $persistedRaw['data']) {
                        $result['data'] = $persistedRaw['data'];
                    } elseif (is_array($persistedRaw['app_results'] ?? null) && $persistedRaw['app_results']) {
                        $result['app_results'] = $persistedRaw['app_results'];
                    }
                }
            } catch (Throwable $ignored) {
                // 读取旁路明细失败时仍固化异常状态和错误信息。
            }
        }
        $total = max(0, (int)($result['total'] ?? 0));
        $success = max(0, (int)($result['success'] ?? 0));
        $fail = max(0, (int)($result['fail'] ?? 0));
        // 异常路径通常只传 message/error，没有业务 code；补成 500 让任务级失败
        // 也能落入明细列表，而不是终态显示失败但列表为空。
        if ($error && !array_key_exists('code', $result)) $result['code'] = 500;
        $resultCode = (int)($result['code'] ?? 200);
        // 先解析 APP/桶级明细，再决定任务终态。不能只看旧的 fail 列：当每个
        // APP 都是“B2 成功、AWS/R2 失败”时，fail 可能为 0（全部失败 APP 数为 0），
        // 但桶级确实有失败，任务应显示“部分失败”而不是“同步失败”。
        $details = configSyncStateNormalizeResultSummary($result);
        $summary = $details['result_summary'] ?? [];
        $detailAppTotal = (int)($summary['app_total'] ?? 0);
        $detailItemTotal = (int)($summary['item_total'] ?? 0);
        $detailAppSuccess = (int)($summary['app_success_count'] ?? 0);
        $detailAppPartial = (int)($summary['app_partial_count'] ?? 0);
        $detailAppFailed = (int)($summary['app_failed_count'] ?? 0);
        $detailBucketSuccess = (int)($summary['bucket_success_count'] ?? 0);
        $detailBucketFailed = (int)($summary['bucket_failed_count'] ?? 0);
        $detailCleanupFailed = (int)($summary['cleanup_failed_count'] ?? 0);
        $hasDetailedOutcomes = $detailAppTotal > 0 || $detailItemTotal > 0;
        $hasDetailedSuccess = $detailAppSuccess > 0 || $detailAppPartial > 0 || $detailBucketSuccess > 0;
        $hasDetailedFailure = $detailAppFailed > 0 || $detailBucketFailed > 0 || $detailCleanupFailed > 0;
        // 旧调用方可能只传 data/app_results，不带 APP 级 success/fail 标量；此时
        // 用同一份明细补齐数据库兼容计数。调用方明确传入 0（例如全是 partial）时
        // 保留显式值，避免把桶对象数误写成 APP 成功数。
        if (!array_key_exists('success', $result) && !array_key_exists('success_count', $result)) {
            $success = $detailAppSuccess;
        }
        if (!array_key_exists('fail', $result) && !array_key_exists('fail_count', $result)) {
            $fail = $detailAppFailed;
        }
        if ($error) {
            // 已有 APP/桶成功后才发生的异常属于部分失败；只有没有任何成功
            // 结果时才把整轮标为失败，避免覆盖之前已经写好的成功对象事实。
            $status = $hasDetailedSuccess ? 'partial_failure' : 'failed';
            $message = $error->getMessage() ?: '配置桶同步失败';
        } elseif ($hasDetailedOutcomes && $hasDetailedFailure) {
            $status = $hasDetailedSuccess ? 'partial_failure' : 'failed';
            $message = (string)($result['message'] ?? ($status === 'partial_failure' ? '同步完成：部分配置桶成功' : '配置桶同步失败'));
        } elseif (!$hasDetailedOutcomes && $resultCode !== 200 && $fail === 0) {
            $status = 'failed';
            $message = (string)($result['message'] ?? '配置桶同步未完成');
        } elseif ($fail > 0) {
            $status = $success > 0 ? 'partial_failure' : 'failed';
            $message = (string)($result['message'] ?? ('同步完成：成功 ' . $success . '，失败 ' . $fail));
        } else {
            $status = 'completed';
            $message = (string)($result['message'] ?? ('同步完成：成功 ' . $success . '，失败 0'));
        }
        // 终态快照直接带公开明细，后续刷新/换页无需再次执行桶查询。
        $result = array_merge($result, $details);
        if ($total === 0 && $detailAppTotal > 0) $total = $detailAppTotal;
        $encodedResult = configSyncStateJson($result, '{}');
        $stmt = $pdo->prepare("UPDATE cainiao_config_sync_state SET
            status=:status, phase='finished', phase_label=:phase_label, message=:message,
            expected_total=:total, current_index=:total, success=:success, fail=:fail,
            current_app_id=0, current_app='', current_bucket='', finished_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP(),
            result_json=:result_json
            WHERE id=1 AND job_id=:job_id AND status IN ('queued','running')");
        $stmt->execute([
            ':status' => $status,
            ':phase_label' => $status === 'completed' ? '同步完成' : ($status === 'partial_failure' ? '部分失败' : '同步失败'),
            ':message' => mb_substr($message, 0, 255, 'UTF-8'),
            ':total' => $total, ':success' => $success, ':fail' => $fail,
            ':result_json' => $encodedResult, ':job_id' => $jobId,
        ]);
        return configSyncStateRead($pdo);
    }
}

if (!function_exists('configSyncStateScheduleWorker')) {
    /**
     * 合并配置变更并启动全量同步 worker。
     *
     * 所有配置写入口都通过此 helper 进入同一份 ConfigSyncState 快照，避免每个
     * 模块重复实现排队、并发合并和后台进程启动逻辑。返回值中的 scheduled 表示
     * 当前变更已有 worker 接管（包括加入已有任务），snapshot 是可直接返回前端的
     * 非敏感状态摘要。
     */
    function configSyncStateScheduleWorker(PDO $pdo, string $reason = '配置变更'): array
    {
        $before = [];
        $alreadyActive = false;
        try {
            $before = configSyncStateRead($pdo);
            $alreadyActive = in_array((string)($before['status'] ?? ''), ['queued', 'running'], true)
                && (string)($before['job_id'] ?? '') !== '';
        } catch (Throwable $ignored) {
            // 首次迁移时由下面的排队调用补齐状态表。
        }

        $snapshot = configSyncStateMarkQueued($pdo, $reason);
        // 读取旧状态与排队之间任务可能已结束；只有仍属同一 job 才视为加入旧任务。
        if ($alreadyActive) {
            $alreadyActive = in_array((string)($snapshot['status'] ?? ''), ['queued', 'running'], true)
                && (string)($snapshot['job_id'] ?? '') !== ''
                && (string)($snapshot['job_id'] ?? '') === (string)($before['job_id'] ?? '');
        }

        $script = realpath(__DIR__ . '/../../service/push_all_configs.php');
        if ($alreadyActive) {
            return [
                'scheduled' => true,
                'started' => false,
                'joined' => true,
                'snapshot' => configSyncStateRead($pdo),
            ];
        }

        if (!$script || !function_exists('exec')) {
            $failed = configSyncStateMarkFinished(
                $pdo,
                ['total' => 0, 'success' => 0, 'fail' => 0, 'message' => '后台同步脚本未启动'],
                (string)($snapshot['job_id'] ?? ''),
                new RuntimeException('后台同步脚本未启动')
            );
            return [
                'scheduled' => false,
                'started' => false,
                'joined' => false,
                'snapshot' => $failed,
            ];
        }

        $output = [];
        $exitCode = 1;
        $jobId = (string)($snapshot['job_id'] ?? '');
        $command = 'php ' . escapeshellarg($script)
            . ($jobId !== '' ? ' ' . escapeshellarg($jobId) : '')
            . ' > /dev/null 2>&1 & echo $!';
        @exec($command, $output, $exitCode);
        $started = $exitCode === 0
            && !empty($output)
            && ctype_digit(trim((string)end($output)));
        if (!$started) {
            $snapshot = configSyncStateMarkFinished(
                $pdo,
                ['total' => 0, 'success' => 0, 'fail' => 0, 'message' => '后台同步脚本未启动'],
                $jobId,
                new RuntimeException('后台同步脚本未启动')
            );
        } else {
            $snapshot = configSyncStateRead($pdo);
        }
        return [
            'scheduled' => $started,
            'started' => $started,
            'joined' => false,
            'snapshot' => $snapshot,
        ];
    }
}
