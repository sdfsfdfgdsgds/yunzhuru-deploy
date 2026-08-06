<?php

/**
 * 应用远程配置运行面失效工具。
 *
 * 删除应用分为“立即停止配置下发”和“后台物理清理”两个阶段。这里集中处理
 * Redis、磁盘缓存和配置桶对象，避免后台队列尚未执行时继续返回旧配置。
 */

require_once __DIR__ . '/S3Client.php';

if (!function_exists('buildShellConfigCacheKey')) {
    /**
     * 为壳配置生成缓存键。兜底响应必须和原 APPID 的正常响应隔离。
     */
    function buildShellConfigCacheKey(
        int $requestedAppId,
        int $resolvedAppId,
        bool $fallback,
        bool $disable,
        string $deviceId
    ): string {
        $baseKey = $fallback
            ? "fallback:{$requestedAppId}:{$resolvedAppId}"
            : (string)$requestedAppId;

        if ($disable) {
            return '禁用的设备：' . $baseKey . '_' . $deviceId;
        }

        return $baseKey;
    }
}

if (!function_exists('appConfigInvalidationRedisPatterns')) {
    /**
     * 返回删除应用时需要清理的 Redis DB0 派生缓存模式。
     */
    function appConfigInvalidationRedisPatterns(int $appId): array
    {
        return [
            '禁用的设备：' . $appId . '_*',
            // 被删应用可能是兜底请求的“原 APPID”或“解析目标”，两个方向都要清理。
            'fallback:' . $appId . ':*',
            'fallback:*:' . $appId,
            '禁用的设备：fallback:' . $appId . ':*',
            '禁用的设备：fallback:*:' . $appId . '_*',
        ];
    }
}

if (!function_exists('appConfigInvalidationDiskPatterns')) {
    /**
     * 返回删除应用时需要清理的磁盘缓存模式。
     */
    function appConfigInvalidationDiskPatterns(string $tempDir, int $appId): array
    {
        $base = rtrim($tempDir, '/');
        return [
            $base . '/' . $appId . '.json',
            $base . '/禁用的设备：' . $appId . '_*.json',
            $base . '/AAA明文禁用的设备：' . $appId . '_*.json',
            $base . '/fallback:' . $appId . ':*.json',
            $base . '/fallback:*:' . $appId . '.json',
            $base . '/禁用的设备：fallback:' . $appId . ':*.json',
            $base . '/禁用的设备：fallback:*:' . $appId . '_*.json',
            $base . '/AAA明文禁用的设备：fallback:' . $appId . ':*.json',
            $base . '/AAA明文禁用的设备：fallback:*:' . $appId . '_*.json',
        ];
    }
}

if (!function_exists('invalidateAppConfigCaches')) {
    /**
     * 清理指定 APPID 的 Redis DB0/DB2 与磁盘配置缓存。
     *
     * @param callable|null $redisFactory 测试注入点，默认调用 getRedisConnection(0)
     */
    function invalidateAppConfigCaches(
        int $appId,
        ?string $tempDir = null,
        ?callable $redisFactory = null
    ): array {
        if ($appId <= 0) {
            throw new InvalidArgumentException('应用 ID 不合法');
        }

        $result = [
            'app_id' => $appId,
            'redis_ok' => true,
            'redis_deleted' => 0,
            'redis_errors' => [],
            'disk_ok' => true,
            'disk_deleted' => 0,
            'disk_errors' => [],
        ];

        $redis = null;
        try {
            if ($redisFactory) {
                $redis = $redisFactory();
            } else {
                if (!function_exists('getRedisConnection')) {
                    throw new RuntimeException('Redis 连接函数未加载');
                }
                $redis = getRedisConnection(0);
            }

            if (!$redis) {
                throw new RuntimeException('Redis 连接为空');
            }

            $redis->select(0);
            $keys = [(string)$appId];
            foreach (appConfigInvalidationRedisPatterns($appId) as $pattern) {
                try {
                    $iterator = null;
                    $guard = 0;
                    do {
                        $batch = $redis->scan($iterator, $pattern, 200);
                        if (is_array($batch)) {
                            foreach ($batch as $key) {
                                $keys[] = (string)$key;
                            }
                        }
                        $guard++;
                    } while ($iterator !== 0 && $iterator !== '0' && $guard < 10000);
                } catch (Throwable $e) {
                    // 精确键仍会被删除；记录派生键扫描异常供调用方展示。
                    $result['redis_errors'][] = "扫描 {$pattern} 失败：" . $e->getMessage();
                }
            }

            foreach (array_values(array_unique($keys)) as $key) {
                $result['redis_deleted'] += (int)$redis->del($key);
            }

            $redis->select(2);
            $result['redis_deleted'] += (int)$redis->del((string)$appId);
        } catch (Throwable $e) {
            $result['redis_errors'][] = $e->getMessage();
        } finally {
            if ($redis && method_exists($redis, 'close')) {
                try {
                    $redis->close();
                } catch (Throwable $e) {
                    $result['redis_errors'][] = '关闭 Redis 连接失败：' . $e->getMessage();
                }
            }
        }
        $result['redis_ok'] = empty($result['redis_errors']);

        $tempDir = $tempDir ?: dirname(__DIR__, 2) . '/temp';
        if (is_dir($tempDir)) {
            $files = [];
            foreach (appConfigInvalidationDiskPatterns($tempDir, $appId) as $pattern) {
                foreach (glob($pattern) ?: [] as $file) {
                    if (is_file($file)) {
                        $files[] = $file;
                    }
                }
            }

            foreach (array_values(array_unique($files)) as $file) {
                if (@unlink($file) || !is_file($file)) {
                    $result['disk_deleted']++;
                } else {
                    $result['disk_errors'][] = '删除磁盘缓存失败：' . basename($file);
                }
            }
        }
        $result['disk_ok'] = empty($result['disk_errors']);

        return $result;
    }
}

if (!function_exists('appConfigBucketLabel')) {
    function appConfigBucketLabel(array $bucket): string
    {
        $parts = [];
        foreach (['name', 'bucket', 'domain'] as $field) {
            $value = trim((string)($bucket[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts ? implode(' / ', $parts) : ('桶ID ' . ($bucket['id'] ?? '未知'));
    }
}

if (!function_exists('verifyAppConfigObjectUnavailable')) {
    /**
     * DELETE 凭据异常时，用壳端实际访问的公开域名复核对象是否已不可读。
     */
    function verifyAppConfigObjectUnavailable(array $bucket, string $objectKey): array
    {
        $domain = rtrim(trim((string)($bucket['domain'] ?? '')), '/');
        if ($domain === '' || strpos($domain, '*') !== false || !function_exists('curl_init')) {
            return ['checked' => false, 'unavailable' => false, 'http_code' => null];
        }

        $segments = array_map('rawurlencode', explode('/', ltrim($objectKey, '/')));
        // 使用与旧 APK 完全相同的固定 URL；若 CDN 仍缓存 200，就保持 pending 继续重试。
        $url = $domain . '/' . implode('/', $segments);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'checked' => true,
            'unavailable' => in_array($httpCode, [401, 403, 404, 410], true),
            'http_code' => $httpCode,
            'error' => $error,
        ];
    }
}

if (!function_exists('deleteAppConfigObjectsUnlocked')) {
    /**
     * 从所有已登记配置桶删除指定 APPID 的对象，包含当前 enabled=0 的历史桶。
     *
     * @param callable|null $onProgress function(array $result, int $current, int $total): void
     * @param callable|null $clientFactory 测试注入点，参数为桶记录，返回带 deleteObject() 的对象
     * @param callable|null $verificationCallback 公开 URL 可读性复核测试注入点
     */
    function deleteAppConfigObjectsUnlocked(
        PDO $pdo,
        int $appId,
        ?callable $onProgress = null,
        ?callable $clientFactory = null,
        ?callable $verificationCallback = null
    ): array {
        if ($appId <= 0) {
            throw new InvalidArgumentException('应用 ID 不合法');
        }

        $buckets = $pdo->query('SELECT * FROM cainiao_s3_bucket ORDER BY id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
        $objectKey = "config/{$appId}.enc";
        $results = [];
        $total = count($buckets);

        foreach ($buckets as $index => $bucket) {
            $label = appConfigBucketLabel($bucket);
            try {
                $client = $clientFactory
                    ? $clientFactory($bucket)
                    : new S3Client(
                        $bucket['access_key'],
                        $bucket['secret_key'],
                        $bucket['endpoint'],
                        $bucket['bucket'],
                        $bucket['region'] ?: 'auto'
                );
                $deleteResult = $client->deleteObject($objectKey);
                $deleteOk = (int)($deleteResult['code'] ?? 0) === 200;
                // DELETE 2xx 只代表源站接受了操作，CDN 固定 URL 仍可能继续返回旧对象。
                // 只要有可复核的公开域名，必须确认壳端已读不到；200/206 会保持任务 pending。
                $publicVerification = $verificationCallback
                    ? $verificationCallback($bucket, $objectKey)
                    : verifyAppConfigObjectUnavailable($bucket, $objectKey);
                $verificationChecked = !empty($publicVerification['checked']);
                $ok = $verificationChecked
                    ? !empty($publicVerification['unavailable'])
                    : $deleteOk;
                $row = [
                    'bucket_id' => (int)($bucket['id'] ?? 0),
                    'bucket' => $label,
                    'enabled' => (int)($bucket['enabled'] ?? 0),
                    'object_key' => $objectKey,
                    'ok' => $ok,
                    'code' => $ok ? 200 : ($deleteOk && $verificationChecked ? 409 : (int)($deleteResult['code'] ?? 0)),
                    'http_code' => $deleteResult['http_code'] ?? null,
                    'public_http_code' => $publicVerification['http_code'] ?? null,
                    'verified_unavailable' => !empty($publicVerification['unavailable']),
                    'message' => $verificationChecked && !empty($publicVerification['unavailable'])
                        ? ($deleteOk ? '删除成功，壳端公开地址已不可读' : '删除接口返回异常，但壳端公开地址已不可读')
                        : ($verificationChecked
                            ? '壳端公开地址仍可读或未完成收敛'
                            : (string)($deleteResult['message'] ?? ($ok ? '删除成功' : '删除失败'))),
                ];
            } catch (Throwable $e) {
                $row = [
                    'bucket_id' => (int)($bucket['id'] ?? 0),
                    'bucket' => $label,
                    'enabled' => (int)($bucket['enabled'] ?? 0),
                    'object_key' => $objectKey,
                    'ok' => false,
                    'code' => 500,
                    'http_code' => null,
                    'message' => $e->getMessage(),
                ];
            }

            $results[] = $row;
            if ($onProgress) {
                $onProgress($row, $index + 1, $total);
            }
        }

        $success = count(array_filter($results, function (array $row): bool {
            return !empty($row['ok']);
        }));

        return [
            'app_id' => $appId,
            'object_key' => $objectKey,
            'total' => $total,
            'success' => $success,
            'failed' => $total - $success,
            'results' => $results,
        ];
    }
}

if (!function_exists('deleteAppConfigObjects')) {
    /**
     * 在与桶 writer 相同的 APPID 锁内执行终态 DELETE + 公开 URL 复核。
     * 删除任务会等待已起步的旧 writer 结束，之后才删掉对象，
     * 因此 completed 表示不再有更旧的 writer 能在复核后回写。
     */
    function deleteAppConfigObjects(
        PDO $pdo,
        int $appId,
        ?callable $onProgress = null,
        ?callable $clientFactory = null,
        ?callable $verificationCallback = null
    ): array {
        if ($appId <= 0) {
            throw new InvalidArgumentException('应用 ID 不合法');
        }

        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql') {
            return deleteAppConfigObjectsUnlocked(
                $pdo,
                $appId,
                $onProgress,
                $clientFactory,
                $verificationCallback
            );
        }

        $lockName = 'yunzhuru_cfg_push_' . $appId;
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 600)');
        $lockStmt->execute([':lock_name' => $lockName]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            throw new RuntimeException("等待应用 {$appId} 桶 writer 结束超时");
        }

        try {
            return deleteAppConfigObjectsUnlocked(
                $pdo,
                $appId,
                $onProgress,
                $clientFactory,
                $verificationCallback
            );
        } finally {
            try {
                $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releaseStmt->execute([':lock_name' => $lockName]);
            } catch (Throwable $e) {
            }
        }
    }
}

if (!function_exists('ensureAppConfigInvalidationJobTable')) {
    /**
     * 持久化运行面失效任务。该表不依赖应用主表，物理删库后仍可重试失败桶。
     */
    function ensureAppConfigInvalidationJobTable(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `cainiao_app_config_invalidation_job` (
                `app_id` INT NOT NULL,
                `dependent_ids` TEXT NOT NULL,
                `redirect_source_ids` TEXT NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `attempts` INT NOT NULL DEFAULT 0,
                `next_retry_at` DATETIME NULL,
                `last_error` TEXT NULL,
                `last_result` MEDIUMTEXT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`app_id`),
                KEY `idx_status_retry` (`status`, `next_retry_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='已删应用配置运行面失效重试任务'
        ");

        $checked = true;
    }
}

if (!function_exists('decodeAppConfigInvalidationIds')) {
    function decodeAppConfigInvalidationIds($value): array
    {
        $ids = is_array($value) ? $value : json_decode((string)$value, true);
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids), function (int $id): bool {
            return $id > 0;
        })));
    }
}

if (!function_exists('collectAppConfigInvalidationDependencies')) {
    function collectAppConfigInvalidationDependencies(PDO $pdo, int $appId): array
    {
        // 依赖集合和 tombstone 在同一删除事务内持久化。查询异常必须向上抛出，
        // 避免一次锁超时被当成“没有依赖”并永久丢失修复范围。
        $stmt = $pdo->prepare("
            SELECT a.id
            FROM cainiao_apk a
            LEFT JOIN cainiao_apk_deleted d ON d.apk_id = a.id
            WHERE a.config_mode = 1
              AND a.reuse_apk_id = :id
              AND d.apk_id IS NULL
        ");
        $stmt->execute([':id' => $appId]);
        $dependentIds = decodeAppConfigInvalidationIds($stmt->fetchAll(PDO::FETCH_COLUMN));

        $stmt = $pdo->prepare('SELECT DISTINCT apk_id1 FROM cainiao_redirect WHERE apk_id2 = :id');
        $stmt->execute([':id' => $appId]);
        $redirectSourceIds = decodeAppConfigInvalidationIds($stmt->fetchAll(PDO::FETCH_COLUMN));

        return [
            'dependent_ids' => $dependentIds,
            'redirect_source_ids' => $redirectSourceIds,
        ];
    }
}

if (!function_exists('enqueueAppConfigInvalidationJob')) {
    function enqueueAppConfigInvalidationJob(PDO $pdo, int $appId): array
    {
        if ($appId <= 0) {
            throw new InvalidArgumentException('应用 ID 不合法');
        }
        ensureAppConfigInvalidationJobTable($pdo);

        $dependencies = collectAppConfigInvalidationDependencies($pdo, $appId);
        $existingStmt = $pdo->prepare('SELECT dependent_ids, redirect_source_ids FROM cainiao_app_config_invalidation_job WHERE app_id = :id LIMIT 1');
        $existingStmt->execute([':id' => $appId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $dependencies['dependent_ids'] = array_values(array_unique(array_merge(
            decodeAppConfigInvalidationIds($existing['dependent_ids'] ?? []),
            $dependencies['dependent_ids']
        )));
        $dependencies['redirect_source_ids'] = array_values(array_unique(array_merge(
            decodeAppConfigInvalidationIds($existing['redirect_source_ids'] ?? []),
            $dependencies['redirect_source_ids']
        )));

        $stmt = $pdo->prepare("
            INSERT INTO cainiao_app_config_invalidation_job
                (app_id, dependent_ids, redirect_source_ids, status, attempts, next_retry_at, last_error, last_result, created_at, updated_at)
            VALUES
                (:app_id, :dependent_ids, :redirect_source_ids, 'pending', 0, NOW(), NULL, NULL, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                dependent_ids = VALUES(dependent_ids),
                redirect_source_ids = VALUES(redirect_source_ids),
                status = 'pending',
                attempts = 0,
                next_retry_at = NOW(),
                last_error = NULL,
                updated_at = NOW()
        ");
        $stmt->execute([
            ':app_id' => $appId,
            ':dependent_ids' => json_encode($dependencies['dependent_ids']),
            ':redirect_source_ids' => json_encode($dependencies['redirect_source_ids']),
        ]);

        return array_merge(['app_id' => $appId, 'queued' => true], $dependencies);
    }
}

if (!function_exists('appConfigInvalidationPushSucceeded')) {
    function appConfigInvalidationPushSucceeded(array $pushResult): bool
    {
        // 410 表示依赖应用本身也已下线；在旧桶对象删除成功后无需再重建。
        if (!in_array((int)($pushResult['code'] ?? 500), [200, 304, 410], true)) {
            return false;
        }
        foreach (($pushResult['results'] ?? []) as $row) {
            if ((int)($row['code'] ?? 500) !== 200) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('processAppConfigInvalidationJobUnlocked')) {
    /**
     * 在 APPID 级互斥锁内执行一次持久化失效尝试。
     */
    function processAppConfigInvalidationJobUnlocked(PDO $pdo, int $appId): array
    {
        ensureAppConfigInvalidationJobTable($pdo);
        $stmt = $pdo->prepare('SELECT * FROM cainiao_app_config_invalidation_job WHERE app_id = :id LIMIT 1');
        $stmt->execute([':id' => $appId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            enqueueAppConfigInvalidationJob($pdo, $appId);
            $stmt->execute([':id' => $appId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        // 快速进程、物理清理进程和常驻 worker 都走同一入口。
        // 第一个执行者已收敛后，后来者直接复用结果，避免再次删除依赖应用的新桶对象。
        if (($job['status'] ?? '') === 'completed') {
            $completedResult = json_decode((string)($job['last_result'] ?? ''), true);
            if (!is_array($completedResult)) {
                $completedResult = ['app_id' => $appId];
            }
            $completedResult['ok'] = true;
            $completedResult['attempts'] = (int)($job['attempts'] ?? 0);
            $completedResult['next_retry_at'] = null;
            $completedResult['errors'] = [];
            $completedResult['already_completed'] = true;
            return $completedResult;
        }

        $dependentIds = decodeAppConfigInvalidationIds($job['dependent_ids'] ?? []);
        $redirectSourceIds = decodeAppConfigInvalidationIds($job['redirect_source_ids'] ?? []);
        $errors = [];
        $result = [
            'app_id' => $appId,
            'cache' => null,
            'bucket' => null,
            'dependents' => [],
            'redirect_sources' => [],
        ];

        try {
            $result['cache'] = invalidateAppConfigCaches($appId);
            if (empty($result['cache']['redis_ok']) || empty($result['cache']['disk_ok'])) {
                $errors[] = '主应用缓存清理未完全成功';
            }
        } catch (Throwable $e) {
            $errors[] = '主应用缓存：' . $e->getMessage();
        }

        try {
            $result['bucket'] = deleteAppConfigObjects($pdo, $appId);
            foreach (($result['bucket']['results'] ?? []) as $row) {
                if (empty($row['ok'])) {
                    $errors[] = '主应用桶 ' . ($row['bucket'] ?? '') . '：' . ($row['message'] ?? '删除失败');
                }
            }
        } catch (Throwable $e) {
            $errors[] = '主应用配置桶：' . $e->getMessage();
        }

        if ($dependentIds) {
            require_once __DIR__ . '/BucketPush.php';
        }
        foreach ($dependentIds as $dependentId) {
            $dependentResult = ['app_id' => $dependentId];
            try {
                $update = $pdo->prepare("
                    UPDATE cainiao_apk
                    SET config_mode = 0, reuse_apk_id = NULL, reuse_options = NULL
                    WHERE id = :dependent_id AND reuse_apk_id = :target_id
                ");
                $update->execute([':dependent_id' => $dependentId, ':target_id' => $appId]);
                $dependentResult['detached_rows'] = $update->rowCount();
                $dependentResult['cache'] = invalidateAppConfigCaches($dependentId);
                $dependentResult['bucket_delete'] = deleteAppConfigObjects($pdo, $dependentId);
                $dependentResult['push'] = pushConfigToBuckets($pdo, $dependentId);

                $dependentOk = !empty($dependentResult['cache']['redis_ok'])
                    && !empty($dependentResult['cache']['disk_ok'])
                    && (int)($dependentResult['bucket_delete']['failed'] ?? 0) === 0
                    && appConfigInvalidationPushSucceeded($dependentResult['push']);
                if (!$dependentOk) {
                    $errors[] = "复用依赖 APPID {$dependentId} 修复未完全成功";
                }
            } catch (Throwable $e) {
                $dependentResult['error'] = $e->getMessage();
                $errors[] = "复用依赖 APPID {$dependentId}：" . $e->getMessage();
            }
            $result['dependents'][] = $dependentResult;
        }

        foreach ($redirectSourceIds as $sourceId) {
            try {
                $sourceCache = invalidateAppConfigCaches($sourceId);
                $result['redirect_sources'][] = ['app_id' => $sourceId, 'cache' => $sourceCache];
                if (empty($sourceCache['redis_ok']) || empty($sourceCache['disk_ok'])) {
                    $errors[] = "redirect 源 APPID {$sourceId} 缓存清理未完全成功";
                }
            } catch (Throwable $e) {
                $errors[] = "redirect 源 APPID {$sourceId}：" . $e->getMessage();
            }
        }

        $attempts = (int)($job['attempts'] ?? 0) + 1;
        $ok = empty($errors);
        $delay = min(3600, 15 * (2 ** min(max(0, $attempts - 1), 8)));
        // next_retry_at 由 MySQL 会话时间计算，与 worker 的 NOW() 保持同一时区语义。
        // Railway 应用容器和独立 MySQL 可处于不同时区，避免 PHP date() 写入 DATETIME 导致重试偏移。
        $nextRetryAt = null;
        if (!$ok) {
            $retryTimeStmt = $pdo->query(
                "SELECT DATE_FORMAT(DATE_ADD(NOW(), INTERVAL {$delay} SECOND), '%Y-%m-%d %H:%i:%s')"
            );
            $nextRetryAt = (string)$retryTimeStmt->fetchColumn();
        }
        $result['ok'] = $ok;
        $result['attempts'] = $attempts;
        $result['next_retry_at'] = $nextRetryAt;
        $result['errors'] = $errors;
        $encodedResult = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedResult === false) {
            $encodedResult = '{}';
        }

        $update = $pdo->prepare("
            UPDATE cainiao_app_config_invalidation_job
            SET status = :status,
                attempts = :attempts,
                next_retry_at = :next_retry_at,
                last_error = :last_error,
                last_result = :last_result,
                updated_at = NOW()
            WHERE app_id = :app_id
        ");
        $update->execute([
            ':status' => $ok ? 'completed' : 'pending',
            ':attempts' => $attempts,
            ':next_retry_at' => $nextRetryAt,
            ':last_error' => $ok ? null : implode(' | ', $errors),
            ':last_result' => $encodedResult,
            ':app_id' => $appId,
        ]);

        return $result;
    }
}

if (!function_exists('processAppConfigInvalidationJob')) {
    /**
     * 执行一次持久化失效尝试；失败时指数退避，由常驻 worker 继续拉取。
     *
     * MySQL advisory lock 和 PDO 连接同生命周期：同一 APPID 只有一个执行者，
     * 进程崩溃或连接断开时锁会自动释放，pending job 可直接重放。
     */
    function processAppConfigInvalidationJob(PDO $pdo, int $appId): array
    {
        if ($appId <= 0) {
            throw new InvalidArgumentException('应用 ID 不合法');
        }
        ensureAppConfigInvalidationJobTable($pdo);

        $lockName = 'yunzhuru_cfg_inv_' . $appId;
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $lockStmt->execute([':lock_name' => $lockName]);
        $lockAcquired = (int)$lockStmt->fetchColumn() === 1;
        if (!$lockAcquired) {
            $stmt = $pdo->prepare('SELECT status, attempts, next_retry_at FROM cainiao_app_config_invalidation_job WHERE app_id = :id LIMIT 1');
            $stmt->execute([':id' => $appId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'app_id' => $appId,
                'ok' => false,
                'busy' => true,
                'status' => $job['status'] ?? 'pending',
                'attempts' => (int)($job['attempts'] ?? 0),
                'next_retry_at' => $job['next_retry_at'] ?? null,
                'errors' => ['同一应用的配置下线任务正在执行'],
            ];
        }

        try {
            return processAppConfigInvalidationJobUnlocked($pdo, $appId);
        } finally {
            try {
                $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releaseStmt->execute([':lock_name' => $lockName]);
            } catch (Throwable $e) {
                // 连接已断开时 MySQL 会自动释放 advisory lock。
            }
        }
    }
}
