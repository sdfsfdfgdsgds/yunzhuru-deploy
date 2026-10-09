<?php
/**
 * 应用“全部删除（含远程配置）”的延迟计划。
 *
 * 延迟期间只保存计划，不写删除标记、不隐藏应用，也不触碰远程配置；到期后才
 * 按现有删除队列执行。应用主行优先于计划行加锁，和应用信息延迟计划保持同一
 * 锁顺序，避免删除与其它修改互相覆盖或死锁。
 */

if (!defined('APP_DELETE_SCHEDULE_MAX_DELAY_SECONDS')) {
    define('APP_DELETE_SCHEDULE_MAX_DELAY_SECONDS', 7 * 24 * 3600);
}

/** 确保旧库具备延迟删除计划表。 */
function ensureAppDeleteScheduleSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_apk_delete_schedule` (
        `schedule_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '删除计划 ID',
        `app_id` INT NOT NULL COMMENT '应用 ID',
        `user_id` INT NOT NULL DEFAULT 0 COMMENT '应用所属用户 ID',
        `apply_at` DATETIME NOT NULL COMMENT 'UTC 执行时间',
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/queued/completed/cancelled/failed',
        `progress_token` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '删除进度 token',
        `app_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '计划创建时的应用名称',
        `app_package` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '计划创建时的包名',
        `created_at` DATETIME NOT NULL COMMENT '创建时间（UTC）',
        `updated_at` DATETIME NOT NULL COMMENT '更新时间（UTC）',
        `applied_at` DATETIME NULL COMMENT '实际开始删除时间（UTC）',
        `error_message` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '失败原因',
        KEY `idx_apk_delete_schedule_app_status` (`app_id`, `status`, `apply_at`),
        KEY `idx_apk_delete_schedule_due` (`status`, `apply_at`),
        KEY `idx_apk_delete_schedule_user` (`user_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用延迟删除计划'");
    $checked = true;
}

/** 规范化延迟秒数，零表示立即删除。 */
function appDeleteScheduleNormalizeDelay($value): int
{
    if ($value === null || $value === '') return 0;
    if (!is_numeric($value)) throw new InvalidArgumentException('删除延迟时间不合法');
    $seconds = (int)$value;
    if ($seconds < 0 || $seconds > APP_DELETE_SCHEDULE_MAX_DELAY_SECONDS) {
        throw new InvalidArgumentException('删除延迟最多只能设置 7 天');
    }
    return $seconds;
}

/** 生成前端倒计时快照。 */
function appDeleteScheduleSnapshot(array $row, ?DateTimeImmutable $now = null): array
{
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $applyAt = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        trim((string)($row['apply_at'] ?? '')),
        new DateTimeZone('UTC')
    );
    if (!$applyAt) {
        return [
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'status' => (string)($row['status'] ?? 'pending'),
            'apply_at' => '',
            'remaining_seconds' => 0,
        ];
    }
    return [
        'schedule_id' => (int)($row['schedule_id'] ?? 0),
        'status' => (string)($row['status'] ?? 'pending'),
        'apply_at' => $applyAt->setTimezone(new DateTimeZone('Asia/Shanghai'))->format(DateTimeInterface::ATOM),
        'remaining_seconds' => max(0, $applyAt->getTimestamp() - $now->getTimestamp()),
    ];
}

/** 以应用主行优先加锁创建计划，避免重复排队。 */
function appDeleteScheduleCreate(PDO $pdo, int $appId, array $user, int $delaySeconds, string $progressToken = ''): array
{
    if ($appId <= 0 || $delaySeconds <= 0 || $delaySeconds > APP_DELETE_SCHEDULE_MAX_DELAY_SECONDS) {
        throw new InvalidArgumentException('删除计划参数错误');
    }
    ensureAppDeleteScheduleSchema($pdo);
    if ($pdo->inTransaction()) throw new RuntimeException('删除计划需要独立事务边界');

    $userId = (int)($user['id'] ?? 0);
    $isAdmin = (string)($user['role'] ?? '') === 'admin';
    $pdo->beginTransaction();
    try {
        $sql = "SELECT a.id, a.user_id, a.name, a.package
            FROM cainiao_apk a
            LEFT JOIN cainiao_apk_deleted d ON d.apk_id = a.id
            WHERE a.id = :id AND d.apk_id IS NULL";
        $params = [':id' => $appId];
        if (!$isAdmin) {
            $sql .= ' AND a.user_id = :user_id';
            $params[':user_id'] = $userId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $appStmt = $pdo->prepare($sql);
        $appStmt->execute($params);
        $app = $appStmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) throw new RuntimeException('未找到对应应用或无权限删除');

        $existing = $pdo->prepare("SELECT schedule_id FROM cainiao_apk_delete_schedule
            WHERE app_id=:app_id AND status IN ('pending','queued') ORDER BY schedule_id DESC LIMIT 1 FOR UPDATE");
        $existing->execute([':app_id' => $appId]);
        if ($existing->fetchColumn()) throw new RuntimeException('该应用已有待删除计划，请等待计划完成');

        $progressToken = $progressToken !== '' ? $progressToken : 'delplan_' . $appId . '_' . time() . '_' . bin2hex(random_bytes(4));
        $applyAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $delaySeconds . ' seconds')->format('Y-m-d H:i:s');
        $insert = $pdo->prepare("INSERT INTO cainiao_apk_delete_schedule
            (app_id,user_id,apply_at,status,progress_token,app_name,app_package,created_at,updated_at,applied_at,error_message)
            VALUES (:app_id,:user_id,:apply_at,'pending',:progress_token,:app_name,:app_package,UTC_TIMESTAMP(),UTC_TIMESTAMP(),NULL,'')");
        $insert->execute([
            ':app_id' => $appId,
            ':user_id' => (int)$app['user_id'],
            ':apply_at' => $applyAt,
            ':progress_token' => $progressToken,
            ':app_name' => (string)($app['name'] ?? ''),
            ':app_package' => (string)($app['package'] ?? ''),
        ]);
        $scheduleId = (int)$pdo->lastInsertId();
        $pdo->commit();
        return array_merge(appDeleteScheduleSnapshot([
            'schedule_id' => $scheduleId,
            'status' => 'pending',
            'apply_at' => $applyAt,
        ]), [
            'app_id' => $appId,
            'app_name' => (string)($app['name'] ?? ''),
            'app_package' => (string)($app['package'] ?? ''),
        ]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** 立即删除或到期删除前取消同一应用仍未执行的计划。 */
function appDeleteScheduleCancelPending(PDO $pdo, int $appId, string $reason = ''): void
{
    ensureAppDeleteScheduleSchema($pdo);
    $stmt = $pdo->prepare("UPDATE cainiao_apk_delete_schedule
        SET status='cancelled', updated_at=UTC_TIMESTAMP(), error_message=:reason
        WHERE app_id=:app_id AND status IN ('pending','queued')");
    $stmt->execute([':app_id' => $appId, ':reason' => substr($reason, 0, 500)]);
}

/** 给应用列表附加当前账号可见的待删除倒计时。 */
function appDeleteScheduleAttachRows(PDO $pdo, array &$list): void
{
    ensureAppDeleteScheduleSchema($pdo);
    foreach ($list as &$row) {
        $row['pending_delete_count'] = 0;
        $row['pending_delete_status'] = '';
        $row['pending_delete_schedule_id'] = 0;
        $row['pending_delete_apply_at'] = '';
        $row['pending_delete_remaining_seconds'] = 0;
    }
    unset($row);
    $ids = array_values(array_filter(array_map('intval', array_column($list, 'id')), static function (int $id): bool { return $id > 0; }));
    if (!$ids) return;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT schedule_id,app_id,status,apply_at FROM cainiao_apk_delete_schedule
        WHERE app_id IN ({$placeholders}) AND status IN ('pending','queued')
        ORDER BY apply_at ASC, schedule_id ASC");
    $stmt->execute($ids);
    $snapshots = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $schedule) {
        $appId = (int)$schedule['app_id'];
        if (isset($snapshots[$appId])) continue;
        $snapshots[$appId] = appDeleteScheduleSnapshot($schedule);
    }
    foreach ($list as &$row) {
        $snapshot = $snapshots[(int)$row['id']] ?? null;
        if (!$snapshot) continue;
        $row['pending_delete_count'] = 1;
        $row['pending_delete_status'] = $snapshot['status'];
        $row['pending_delete_schedule_id'] = $snapshot['schedule_id'];
        $row['pending_delete_apply_at'] = $snapshot['apply_at'];
        $row['pending_delete_remaining_seconds'] = $snapshot['remaining_seconds'];
    }
    unset($row);
}

/** 将到期计划转入现有删除标记、配置失效和后台物理清理链路。 */
function appDeleteScheduleProcessDue(PDO $pdo, int $limit = 1): int
{
    ensureAppDeleteScheduleSchema($pdo);
    if (!function_exists('appDeleteStartBackgroundCleanup')) return 0;
    ensureApkDeleteMarkerTable($pdo);
    ensureAppConfigInvalidationJobTable($pdo);
    $processed = 0;
    for ($i = 0; $i < max(1, $limit); $i++) {
        $candidate = $pdo->query("SELECT schedule_id,app_id,user_id FROM cainiao_apk_delete_schedule
            WHERE status IN ('pending','queued') AND apply_at<=UTC_TIMESTAMP()
            ORDER BY apply_at ASC,schedule_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$candidate) break;
        $scheduleId = (int)$candidate['schedule_id'];
        $appId = (int)$candidate['app_id'];
        try {
            $pdo->beginTransaction();
            $appStmt = $pdo->prepare("SELECT a.* FROM cainiao_apk a
                LEFT JOIN cainiao_apk_deleted d ON d.apk_id=a.id
                WHERE a.id=:id LIMIT 1 FOR UPDATE");
            $appStmt->execute([':id' => $appId]);
            $app = $appStmt->fetch(PDO::FETCH_ASSOC);
            $scheduleStmt = $pdo->prepare("SELECT * FROM cainiao_apk_delete_schedule
                WHERE schedule_id=:schedule_id AND status IN ('pending','queued') AND apply_at<=UTC_TIMESTAMP() FOR UPDATE");
            $scheduleStmt->execute([':schedule_id' => $scheduleId]);
            $schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);
            if (!$schedule) {
                $pdo->rollBack();
                continue;
            }
            if (!$app) {
                // 已经写入删除标记但进程在提交后崩溃时，保留 queued 计划并补跑
                // 后台清理；只有仍处于 pending 的计划才表示应用被其它流程先删除。
                if ((string)$schedule['status'] === 'queued') {
                    $pdo->commit();
                    $cleanup = appDeleteStartBackgroundCleanup($appId, (int)$schedule['user_id'], 'scheduled-retry', (string)($schedule['progress_token'] ?? ''));
                    appDeleteStartRuntimeInvalidation($appId);
                    $done = $pdo->prepare("UPDATE cainiao_apk_delete_schedule SET status=:status,updated_at=UTC_TIMESTAMP(),error_message=:error WHERE schedule_id=:id AND status='queued'");
                    $done->execute([
                        ':status' => !empty($cleanup['queued']) ? 'completed' : 'failed',
                        ':error' => !empty($cleanup['queued']) ? '' : substr((string)($cleanup['message'] ?? '后台清理队列落盘失败'), 0, 500),
                        ':id' => $scheduleId,
                    ]);
                } else {
                    $cancel = $pdo->prepare("UPDATE cainiao_apk_delete_schedule SET status='cancelled',updated_at=UTC_TIMESTAMP(),error_message='应用已不存在' WHERE schedule_id=:id");
                    $cancel->execute([':id' => $scheduleId]);
                    $pdo->commit();
                }
                $processed++;
                continue;
            }
            appInfoScheduleCancel($pdo, $appId);
            markApkDeleted($pdo, $appId, (int)$app['user_id'], (int)$schedule['user_id'], '延迟删除到期');
            enqueueAppConfigInvalidationJob($pdo, $appId);
            $queued = $pdo->prepare("UPDATE cainiao_apk_delete_schedule SET status='queued',applied_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),error_message='' WHERE schedule_id=:id");
            $queued->execute([':id' => $scheduleId]);
            $pdo->commit();

            $token = (string)($schedule['progress_token'] ?? '');
            $cleanup = appDeleteStartBackgroundCleanup($appId, (int)$app['user_id'], 'scheduled', $token);
            appDeleteStartRuntimeInvalidation($appId);
            $done = $pdo->prepare("UPDATE cainiao_apk_delete_schedule SET status=:status,updated_at=UTC_TIMESTAMP(),error_message=:error WHERE schedule_id=:id AND status='queued'");
            $done->execute([
                ':status' => !empty($cleanup['queued']) ? 'completed' : 'failed',
                ':error' => !empty($cleanup['queued']) ? '' : substr((string)($cleanup['message'] ?? '后台清理队列落盘失败'), 0, 500),
                ':id' => $scheduleId,
            ]);
            $processed++;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $fail = $pdo->prepare("UPDATE cainiao_apk_delete_schedule SET status='failed',updated_at=UTC_TIMESTAMP(),error_message=:error WHERE schedule_id=:id AND status IN ('pending','queued')");
            $fail->execute([':error' => substr($error->getMessage(), 0, 500), ':id' => $scheduleId]);
            error_log('[AppDeleteSchedule] 到期删除失败 appId=' . $appId . '：' . $error->getMessage());
            $processed++;
        }
    }
    return $processed;
}
