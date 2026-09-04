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
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : $fallback;
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
        $failedItems = [];
        $resultRows = is_array($result['data'] ?? null) ? $result['data'] : [];
        foreach ($resultRows as $appId => $appResult) {
            if (!is_array($appResult)) continue;
            $items = $appResult['results'] ?? [];
            // 应用级跳过/异常可能没有桶级 results；此时仍保留一条失败明细，
            // 让右下角中心能解释“失败计数”对应的 APPID。
            if ((!is_array($items) || !$items) && (int)($appResult['code'] ?? 200) !== 200) {
                $failedItems[] = array_merge(['app_id' => (int)$appId], $appResult);
                continue;
            }
            if (!is_array($items)) continue;
            foreach ($items as $item) {
                if (!is_array($item) || (int)($item['code'] ?? 500) === 200) continue;
                $failedItems[] = array_merge(['app_id' => (int)$appId], $item);
            }
        }
        return [
            'status' => (string)($row['status'] ?? 'idle'),
            'job_id' => (string)($row['job_id'] ?? ''),
            'phase' => (string)($row['phase'] ?? 'idle'),
            'phase_label' => (string)($row['phase_label'] ?? '待命'),
            'message' => (string)($row['message'] ?? '尚未执行配置桶全量同步'),
            'expected_total' => (int)($row['expected_total'] ?? 0),
            'current_index' => (int)($row['current_index'] ?? 0),
            'success' => (int)($row['success'] ?? 0),
            'fail' => (int)($row['fail'] ?? 0),
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
            'failed_items' => array_slice($failedItems, 0, 100),
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
        $total = max(0, (int)($result['total'] ?? 0));
        $success = max(0, (int)($result['success'] ?? 0));
        $fail = max(0, (int)($result['fail'] ?? 0));
        $resultCode = (int)($result['code'] ?? 200);
        if ($error) {
            $status = 'failed';
            $message = $error->getMessage() ?: '配置桶同步失败';
        } elseif ($resultCode !== 200 && $fail === 0) {
            $status = 'failed';
            $message = (string)($result['message'] ?? '配置桶同步未完成');
        } elseif ($fail > 0) {
            $status = $success > 0 ? 'partial_failure' : 'failed';
            $message = (string)($result['message'] ?? ('同步完成：成功 ' . $success . '，失败 ' . $fail));
        } else {
            $status = 'completed';
            $message = (string)($result['message'] ?? ('同步完成：成功 ' . $success . '，失败 0'));
        }
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
