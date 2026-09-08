<?php
/**
 * 配置同步进程启动与遗留排队任务接管。
 *
 * 本工具只读取既有任务并确保等待进程存活，绝不登记新配置变更，因而恢复
 * 操作保持原 job_id、防抖截止时间、变更原因和 dirty 标记不变。
 */

/** 返回与同步 worker 共用的 MySQL 等待锁名称。 */
function configSyncWorkerWaiterLockName(string $jobId): string
{
    return 'yunzhuru_cfg_wait_' . substr(hash('sha256', $jobId), 0, 32);
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

/** 查询既有等待锁，不占有它；真正所有权只由 worker 持有的 GET_LOCK 决定。 */
function configSyncWorkerHasWaiter(PDO $pdo, string $jobId): bool
{
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return false;
    $statement = $pdo->prepare('SELECT IS_USED_LOCK(:lock_name)');
    $statement->execute([':lock_name' => configSyncWorkerWaiterLockName($jobId)]);
    return (int)$statement->fetchColumn() > 0;
}

/**
 * 确保指定的既有 queued 任务有进程处理；onlyDue 用于常驻恢复调度。
 *
 * running 只承认已有任务，不盲目抢占；queued 已有等待锁时 scheduled=true、
 * started=false、owned=true。并发启动的窄窗口仍由 worker 的同一等待锁和
 * queued→running 条件更新去重，不在本函数内修改任何业务状态。
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
