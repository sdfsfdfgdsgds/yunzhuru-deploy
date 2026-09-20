<?php
/**
 * 配置同步进程启动、生命周期所有权与失主任务恢复。
 *
 * 启动器只读取既有任务；失主恢复在双锁下沿用原 job_id、防抖截止时间、
 * 变更原因和历史结果，并重新标记 dirty，保证未完成批次真正重跑。
 */

/** 返回与同步 worker 共用的 MySQL 生命周期锁名称，沿用旧等待锁命名。 */
function configSyncWorkerWaiterLockName(string $jobId): string
{
    return 'yunzhuru_cfg_wait_' . substr(hash('sha256', $jobId), 0, 32);
}

/** 返回全量对象写入所用的 MySQL advisory lock 名称。 */
function configSyncWorkerPushLockName(): string
{
    return 'yunzhuru_cfg_push_all';
}

/**
 * 取得同步任务生命周期锁。
 *
 * 新 worker 从防抖等待、running、重试到终态都持有同一把任务锁；调度器
 * 只有在此锁无人持有时才会进入失主恢复。非 MySQL 环境保留原有无锁兼容行为。
 */
function configSyncWorkerAcquireJobLock(PDO $pdo, string $jobId): bool
{
    if ($jobId === '') return false;
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return true;
    $statement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
    $statement->execute([':lock_name' => configSyncWorkerWaiterLockName($jobId)]);
    return (int)$statement->fetchColumn() === 1;
}

/** 释放同步任务生命周期锁；数据库断开时 MySQL 会自动释放。 */
function configSyncWorkerReleaseJobLock(PDO $pdo, string $jobId): void
{
    if ($jobId === '' || (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    try {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute([':lock_name' => configSyncWorkerWaiterLockName($jobId)]);
        $statement->fetchColumn();
    } catch (Throwable $ignored) {
        // 连接断开时锁已由 MySQL 自动回收。
    }
}

/** 尝试无等待取得全量对象写入锁，供失主恢复先完成并发安全核验。 */
function configSyncWorkerAcquirePushLock(PDO $pdo): bool
{
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return true;
    $statement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
    $statement->execute([':lock_name' => configSyncWorkerPushLockName()]);
    return (int)$statement->fetchColumn() === 1;
}

/** 释放失主恢复临时持有的全量对象写入锁。 */
function configSyncWorkerReleasePushLock(PDO $pdo): void
{
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    try {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute([':lock_name' => configSyncWorkerPushLockName()]);
        $statement->fetchColumn();
    } catch (Throwable $ignored) {
        // 连接断开时锁已由 MySQL 自动回收。
    }
}

/** 返回运行日志路径；默认复用受路由保护的 temp 目录。 */
function configSyncWorkerLogPath(): string
{
    $configured = trim((string)getenv('YUNZHURU_CONFIG_SYNC_WORKER_LOG'));
    return $configured !== '' ? $configured : dirname(__DIR__, 2) . '/temp/config-sync-worker.log';
}

/**
 * 只记录固定状态、任务代次与计数，不接收异常原文、桶配置或云账号材料。
 * 该方法也用于启动失败诊断，日志目录异常时回退到现有 PHP 错误通道。
 */
function configSyncWorkerLog(string $event, string $jobId = '', array $metrics = []): void
{
    $parts = [gmdate('Y-m-d\TH:i:s\Z'), '[config-sync-worker]',
        'event=' . preg_replace('/[^a-zA-Z0-9_-]/', '', $event),
        'job=' . preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId)];
    foreach (['pid', 'success', 'fail', 'total', 'error_code', 'error_line'] as $key) {
        if (isset($metrics[$key]) && is_numeric($metrics[$key])) {
            $parts[] = $key . '=' . (int)$metrics[$key];
        }
    }
    $line = implode(' ', $parts) . PHP_EOL;
    $path = configSyncWorkerLogPath();
    $directory = dirname($path);
    if ((!is_dir($directory) && !@mkdir($directory, 0770, true))
        || @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log(rtrim($line));
    }
}

/**
 * 启动独立 PHP CLI 进程并核对短暂存活，而非把 shell 返回的 PID 当作成功。
 * started 只表示本次新进程仍存活，不表示已经越过防抖或完成对象写入；
 * 后续进程退出由常驻 dispatcher 再次接管原 queued 任务。
 */
function configSyncWorkerStartProcess(string $script, string $jobId): array
{
    $result = ['scheduled' => false, 'started' => false, 'owned' => false,
        'reason' => 'launch_failed', 'pid' => 0];
    $binary = PHP_BINARY;
    if (!function_exists('exec') || !is_file($script) || !is_executable($binary)) {
        configSyncWorkerLog('launch_unavailable', $jobId);
        return $result;
    }
    $log = configSyncWorkerLogPath();
    $directory = dirname($log);
    if ((!is_dir($directory) && !@mkdir($directory, 0770, true))
        || (!is_file($log) && @file_put_contents($log, '') === false)
        || !is_writable($log)) {
        configSyncWorkerLog('launch_log_unavailable', $jobId);
        return $result;
    }
    $output = [];
    $exitCode = 1;
    $command = escapeshellarg($binary) . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($jobId)
        . ' < /dev/null >> ' . escapeshellarg($log) . ' 2>&1 & echo $!';
    @exec($command, $output, $exitCode);
    $pid = $exitCode === 0 && !empty($output) && ctype_digit(trim((string)end($output)))
        ? (int)trim((string)end($output)) : 0;
    if ($pid <= 0) {
        configSyncWorkerLog('launch_failed', $jobId);
        return $result;
    }
    // 给脚本引导一个短窗口，捕获立即退出或 PHP 解析失败；本机生产含 posix。
    usleep(100000);
    if (function_exists('posix_kill')) {
        $alive = @posix_kill($pid, 0);
    } else {
        $probe = [];
        $probeCode = 1;
        @exec('kill -0 ' . $pid . ' 2>/dev/null', $probe, $probeCode);
        $alive = $probeCode === 0;
    }
    // Linux 容器中刚退出的孤儿进程可能暂时处于 Z 状态，kill -0 仍返回成功。
    // 明确排除僵尸/死亡状态，避免再次把一个已经结束的脚本当成有效执行者。
    if ($alive && is_readable('/proc/' . $pid . '/stat')) {
        $stat = (string)@file_get_contents('/proc/' . $pid . '/stat');
        $endName = strrpos($stat, ')');
        $state = $endName === false ? '' : trim(substr($stat, $endName + 1, 3));
        if (in_array($state, ['Z', 'X'], true)) $alive = false;
    }
    if (!$alive) {
        configSyncWorkerLog('process_exited_early', $jobId, ['pid' => $pid]);
        return $result;
    }
    configSyncWorkerLog('process_started', $jobId, ['pid' => $pid]);
    return ['scheduled' => true, 'started' => true, 'owned' => false,
        'reason' => 'process_started', 'pid' => $pid];
}

/** 读取已有任务及到期门闩；UTC 时间判断交给数据库，避免主机时钟差异。 */
function configSyncWorkerReadState(PDO $pdo): array
{
    return $pdo->query("SELECT job_id,status,debounce_until,
        CASE WHEN debounce_until IS NULL OR debounce_until <= UTC_TIMESTAMP()
            THEN 1 ELSE 0 END AS is_due
        FROM cainiao_config_sync_state WHERE id=1 LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** 查询既有生命周期锁，不占有它；恢复前仍须实际取得 GET_LOCK 确认所有权。 */
function configSyncWorkerHasWaiter(PDO $pdo, string $jobId): bool
{
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return false;
    $statement = $pdo->prepare('SELECT IS_USED_LOCK(:lock_name)');
    $statement->execute([':lock_name' => configSyncWorkerWaiterLockName($jobId)]);
    return (int)$statement->fetchColumn() > 0;
}

/**
 * 在持有任务锁和全量写锁时，把失去执行者的 running 任务安全退回 queued。
 *
 * 只有两把锁都由当前 dispatcher 连接持有，且状态行仍是同一 job 的 running，
 * 才允许恢复；因此不会抢占仍在上传的 worker，也不会凭 updated_at 猜测失主。
 * 原 job_id、原因、防抖截止时间、进度和 result_json 全部保留，只把 dirty 置回
 * 1，强制下一轮真正重跑未完成批次而不是被 pushAllConfigsToBuckets 合并掉。
 */
function configSyncWorkerRecoverRunning(PDO $pdo, string $jobId): array
{
    $result = ['recovered' => false, 'reason' => 'unsupported', 'job_id' => $jobId];
    if ($jobId === '' || (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return $result;
    }
    if (!configSyncWorkerAcquireJobLock($pdo, $jobId)) {
        $result['reason'] = 'job_owned';
        return $result;
    }
    $pushLockHeld = false;
    $transaction = false;
    try {
        // 与 worker 的 job -> push 锁顺序一致，避免恢复与正常上传互相死锁。
        if (!configSyncWorkerAcquirePushLock($pdo)) {
            $result['reason'] = 'push_owned';
            return $result;
        }
        $pushLockHeld = true;
        $pdo->beginTransaction();
        $transaction = true;
        $stateStmt = $pdo->prepare("SELECT job_id,status,debounce_until,
            CASE WHEN debounce_until IS NOT NULL AND debounce_until > UTC_TIMESTAMP()
                THEN 1 ELSE 0 END AS debounce_pending
            FROM cainiao_config_sync_state WHERE id=1 LIMIT 1 FOR UPDATE");
        $stateStmt->execute();
        $state = $stateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((string)($state['job_id'] ?? '') !== $jobId
            || (string)($state['status'] ?? '') !== 'running') {
            $pdo->rollBack();
            $transaction = false;
            $result['reason'] = 'state_changed';
            return $result;
        }
        // 与 markQueued/pushAllConfigsToBuckets 使用相同的状态行 -> dirty 行顺序。
        $dirtyStmt = $pdo->prepare("SELECT key_value FROM cainiao_config_delivery_meta
            WHERE key_name='distribution_dirty' LIMIT 1 FOR UPDATE");
        $dirtyStmt->execute();
        $hasDebounce = !empty($state['debounce_pending']);
        $recover = $pdo->prepare("UPDATE cainiao_config_sync_state SET
            status='queued', phase=:phase, phase_label=:phase_label,
            message='同步执行进程已退出，正在恢复未完成批次', finished_at=NULL,
            updated_at=UTC_TIMESTAMP()
            WHERE id=1 AND job_id=:job_id AND status='running'");
        $recover->execute([
            ':phase' => $hasDebounce ? 'debounce' : 'queued',
            ':phase_label' => $hasDebounce ? '等待修改稳定' : '等待同步',
            ':job_id' => $jobId,
        ]);
        if ($recover->rowCount() !== 1) {
            $pdo->rollBack();
            $transaction = false;
            $result['reason'] = 'state_changed';
            return $result;
        }
        $dirtyUpsert = $pdo->prepare("INSERT INTO cainiao_config_delivery_meta (key_name,key_value)
            VALUES ('distribution_dirty','1')
            ON DUPLICATE KEY UPDATE key_value='1'");
        $dirtyUpsert->execute();
        $pdo->commit();
        $transaction = false;
        $result['recovered'] = true;
        $result['reason'] = 'owner_missing';
        return $result;
    } catch (Throwable $error) {
        if ($transaction && $pdo->inTransaction()) $pdo->rollBack();
        $result['reason'] = 'recovery_failed';
        return $result;
    } finally {
        if ($pushLockHeld) configSyncWorkerReleasePushLock($pdo);
        configSyncWorkerReleaseJobLock($pdo, $jobId);
    }
}

/**
 * 确保指定的既有 queued 任务有进程处理；onlyDue 用于常驻恢复调度。
 *
 * running 只承认已有任务，不盲目抢占；running 的失主恢复由 dispatcher 在
 * 双锁和行锁保护下单独完成。queued 已有等待锁时 scheduled=true、started=false、
 * owned=true。并发启动的窄窗口仍由 worker 的同一任务锁和 queued→running 条件
 * 更新去重，不在本函数内修改任何业务状态。
 */
function configSyncWorkerEnsureStarted(PDO $pdo, string $jobId, bool $onlyDue = false): array
{
    $inactive = ['scheduled' => false, 'started' => false, 'owned' => false,
        'reason' => 'inactive', 'pid' => 0];
    if ($jobId === '') return $inactive;
    $state = configSyncWorkerReadState($pdo);
    if ((string)($state['job_id'] ?? '') !== $jobId) return $inactive;
    $status = (string)($state['status'] ?? '');
    if ($status === 'running') {
        return ['scheduled' => true, 'started' => false, 'owned' => true,
            'reason' => 'already_running', 'pid' => 0];
    }
    if ($status !== 'queued') return $inactive;
    if ($onlyDue && empty($state['is_due'])) {
        $inactive['reason'] = 'not_due';
        return $inactive;
    }
    if (configSyncWorkerHasWaiter($pdo, $jobId)) {
        return ['scheduled' => true, 'started' => false, 'owned' => true,
            'reason' => 'waiter_owned', 'pid' => 0];
    }
    $result = configSyncWorkerStartProcess(dirname(__DIR__, 2) . '/service/push_all_configs.php', $jobId);
    if (empty($result['scheduled'])) {
        // 极快完成或并发 worker 获锁后，刚启动的去重进程可能正常退出。
        // 再核对权威状态，避免把正常的去重退出呈现为后台启动失败。
        $latest = configSyncWorkerReadState($pdo);
        if ((string)($latest['job_id'] ?? '') === $jobId
            && ((string)($latest['status'] ?? '') !== 'queued'
                || configSyncWorkerHasWaiter($pdo, $jobId))) {
            return ['scheduled' => true, 'started' => false, 'owned' => true,
                'reason' => 'job_progressed', 'pid' => 0];
        }
    }
    return $result;
}
