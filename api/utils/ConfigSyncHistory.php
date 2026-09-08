<?php
/**
 * 配置同步任务历史：每个同步批次保存一条完整快照。
 *
 * 活动任务以单行状态表为事实来源；终态与状态写入在同一事务内归档。
 * 读取历史仅解码已保存的快照，避免应用重命名或删除改变既有任务证据。
 */

if (!function_exists('ensureConfigSyncHistorySchema')) {
    /** 在事务外创建历史表，并在状态行锁内迁入升级前仅存的当前任务。 */
    function ensureConfigSyncHistorySchema(PDO $pdo): void
    {
        static $ready = [];
        $key = spl_object_id($pdo);
        if (isset($ready[$key]) || $pdo->inTransaction()) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_config_sync_history (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            job_id varchar(80) NOT NULL,
            status varchar(32) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            finished_at datetime NULL,
            summary_json text NOT NULL,
            snapshot_json longtext NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_config_sync_history_job (job_id),
            KEY idx_config_sync_history_created (created_at,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配置桶同步任务历史'");
        $pdo->beginTransaction();
        try {
            $row = configSyncHistoryReadCurrentRow($pdo, true);
            if ((string)($row['job_id'] ?? '') !== '' && empty($row['created_at'])) {
                // 升级前批次缺少创建时间，锁内一次性固定已有时间，后续进度更新保持不变。
                $row['created_at'] = $row['started_at'] ?? $row['updated_at'] ?? gmdate('Y-m-d H:i:s');
                $stmt = $pdo->prepare('UPDATE cainiao_config_sync_state SET created_at=:created_at,updated_at=updated_at
                    WHERE id=1 AND job_id=:job_id AND created_at IS NULL');
                $stmt->execute([':created_at' => $row['created_at'], ':job_id' => $row['job_id']]);
            }
            configSyncHistoryStoreRow($pdo, $row, false);
            $pdo->commit();
            $ready[$key] = true;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}

if (!function_exists('configSyncHistoryReadCurrentRow')) {
    /** 可在调用方事务内锁住唯一状态行，保证状态更新和历史归档具有同一提交边界。 */
    function configSyncHistoryReadCurrentRow(PDO $pdo, bool $lock = false): array
    {
        $sql = 'SELECT * FROM cainiao_config_sync_state WHERE id=1 LIMIT 1';
        if ($lock && (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
        return $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('configSyncHistorySummarize')) {
    /** 列表只返回轻量摘要；对象级结果保留到用户展开详情时再读取。 */
    function configSyncHistorySummarize(array $snapshot): array
    {
        $keys = ['job_id', 'status', 'phase', 'phase_label', 'message', 'reasons',
            'created_at', 'started_at', 'updated_at', 'finished_at', 'expected_total',
            'current_index', 'total', 'current', 'success', 'fail', 'partial', 'skipped',
            'bucket_total', 'bucket_success', 'bucket_fail', 'cleanup_fail', 'result_summary',
            'current_app_id', 'current_app', 'current_bucket', 'debounce_until', 'next_sync_at',
            'debounce_seconds', 'debounce_remaining_seconds', 'is_debouncing'];
        return array_intersect_key($snapshot, array_fill_keys($keys, true));
    }
}

if (!function_exists('configSyncHistoryStoreRow')) {
    /**
     * 保存已锁定的状态行；默认仅首次插入，显式 replace 只供当前任务终态/重试调用。
     * 调用方持有状态行锁，因此旧 worker 或读接口不会覆盖新任务与已冻结历史。
     */
    function configSyncHistoryStoreRow(PDO $pdo, array $row, bool $replace = false): void
    {
        $jobId = trim((string)($row['job_id'] ?? ''));
        if ($jobId === '') return;
        if (!$replace) {
            // 常规状态轮询只确认迁移是否完成，不重复编码大快照或写入已有历史。
            $exists = $pdo->prepare('SELECT status FROM cainiao_config_sync_history WHERE job_id=:job_id LIMIT 1');
            $exists->execute([':job_id' => $jobId]);
            $storedStatus = $exists->fetchColumn();
            if ($storedStatus !== false) {
                // 滚动发布时旧 worker 可能只写状态表终态；新任务替换前补齐这一次终态。
                $wasActive = in_array((string)$storedStatus, ['queued', 'running'], true);
                $nowFinished = !in_array((string)($row['status'] ?? ''), ['queued', 'running', 'idle'], true);
                if (!$wasActive || !$nowFinished) return;
                $replace = true;
            }
        }
        $snapshot = configSyncStateNormalizeRow($pdo, $row);
        // 升级前没有创建时间时，采用已有启动/更新时间；不推测更早的历史记录。
        $createdAt = $row['created_at'] ?? $row['started_at'] ?? $row['updated_at'] ?? gmdate('Y-m-d H:i:s');
        $sql = "INSERT INTO cainiao_config_sync_history
            (job_id,status,created_at,updated_at,finished_at,summary_json,snapshot_json)
            VALUES (:job_id,:status,:created_at,:updated_at,:finished_at,:summary_json,:snapshot_json)
            ON DUPLICATE KEY UPDATE ";
        $sql .= $replace
            ? 'status=VALUES(status),updated_at=VALUES(updated_at),finished_at=VALUES(finished_at),summary_json=VALUES(summary_json),snapshot_json=VALUES(snapshot_json)'
            : 'job_id=VALUES(job_id)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':job_id' => $jobId, ':status' => (string)$snapshot['status'],
            ':created_at' => $createdAt, ':updated_at' => $row['updated_at'] ?? $createdAt,
            ':finished_at' => $row['finished_at'] ?? null,
            ':summary_json' => configSyncStateJson(configSyncHistorySummarize($snapshot), '{}'),
            ':snapshot_json' => configSyncStateJson($snapshot, '{}'),
        ]);
    }
}

if (!function_exists('configSyncHistoryMutateCurrent')) {
    /** 执行当前任务的终态写入并原子归档；只负责自身创建的事务。 */
    function configSyncHistoryMutateCurrent(PDO $pdo, string $jobId, callable $write): array
    {
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) $pdo->beginTransaction();
            configSyncHistoryReadCurrentRow($pdo, true);
            $applied = $write();
            $row = configSyncHistoryReadCurrentRow($pdo);
            if ($applied && (string)($row['job_id'] ?? '') === $jobId
                && !in_array((string)($row['status'] ?? ''), ['queued', 'running', 'idle'], true)) {
                configSyncHistoryStoreRow($pdo, $row, true);
            }
            $snapshot = configSyncStateNormalizeRow($pdo, $row);
            if ($ownsTransaction) $pdo->commit();
            return $snapshot;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}

if (!function_exists('configSyncHistoryReadView')) {
    /** 短事务内读取状态和历史，避免分页查询期间新任务替换造成重复或漏项。 */
    function configSyncHistoryReadView(PDO $pdo, callable $read): array
    {
        ensureConfigSyncStateSchema($pdo);
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) $pdo->beginTransaction();
            $current = configSyncStateNormalizeRow($pdo, configSyncHistoryReadCurrentRow($pdo, true));
            $result = $read($current);
            if ($ownsTransaction) $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}

if (!function_exists('configSyncHistoryList')) {
    /** 按批次创建顺序倒序分页；当前任务用实时状态覆盖摘要，但不回写历史。 */
    function configSyncHistoryList(PDO $pdo, int $page = 1, int $pageSize = 10): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(50, $pageSize));
        return configSyncHistoryReadView($pdo, static function (array $current) use ($pdo, $page, $pageSize): array {
            $total = (int)$pdo->query('SELECT COUNT(*) FROM cainiao_config_sync_history')->fetchColumn();
            $page = min($page, max(1, (int)ceil($total / $pageSize)));
            $offset = ($page - 1) * $pageSize;
            $rows = $pdo->query('SELECT job_id,summary_json FROM cainiao_config_sync_history ORDER BY id DESC LIMIT '
                . $pageSize . ' OFFSET ' . $offset)->fetchAll(PDO::FETCH_ASSOC);
            $items = [];
            foreach ($rows as $row) {
                $isCurrent = (string)$row['job_id'] === (string)($current['job_id'] ?? '');
                $summary = $isCurrent ? configSyncHistorySummarize($current)
                    : json_decode((string)$row['summary_json'], true);
                if (!is_array($summary)) continue;
                $summary['is_current'] = $isCurrent ? 1 : 0;
                $items[] = $summary;
            }
            return ['items' => $items, 'total' => $total, 'page' => $page, 'page_size' => $pageSize,
                'current_job_id' => (string)($current['job_id'] ?? '')];
        });
    }
}

if (!function_exists('configSyncHistoryDetail')) {
    /** 当前详情实时展示；往期仅返回冻结快照，保持已有对象与应用名称证据不变。 */
    function configSyncHistoryDetail(PDO $pdo, string $jobId): array
    {
        $jobId = trim($jobId);
        if ($jobId === '' || strlen($jobId) > 80) throw new InvalidArgumentException('请指定有效同步任务编号');
        return configSyncHistoryReadView($pdo, static function (array $current) use ($pdo, $jobId): array {
            if ((string)($current['job_id'] ?? '') === $jobId) {
                return $current + ['is_current' => 1, 'readonly' => 0];
            }
            $stmt = $pdo->prepare('SELECT snapshot_json FROM cainiao_config_sync_history WHERE job_id=:job_id LIMIT 1');
            $stmt->execute([':job_id' => $jobId]);
            $snapshot = json_decode((string)$stmt->fetchColumn(), true);
            if (!is_array($snapshot)) throw new InvalidArgumentException('同步任务不存在或尚未记录');
            return $snapshot + ['is_current' => 0, 'readonly' => 1];
        });
    }
}
