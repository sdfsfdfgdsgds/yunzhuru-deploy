<?php
/**
 * 配置同步排队恢复守护进程，由 Supervisor 托管。
 *
 * 每 5 秒检查 queued 以及失去生命周期锁的 running。容器重启或临时 worker
 * 退出后沿用原 job_id 恢复；同一代次两次启动尝试至少间隔 30 秒。恢复不重新
 * 登记变更、不延后防抖；running 只有在任务锁和全量写锁都能无等待取得、并由
 * 行锁确认仍是同一 job 时才退回 queued。数据库断线时退出，由 Supervisor 重建。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../api/utils/ConfigSyncWorker.php';
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$booted = false;
// 某些历史数据库引导直接 echo 并 exit；丢弃响应正文并留下固定故障事件，
// 防止账号或连接参数进入后台日志，也让 Supervisor 得到非零退出状态。
ob_start(static function (string $output): string { return ''; }, 1);
register_shutdown_function(static function () use (&$booted): void {
    if (!$booted) {
        configSyncWorkerLog('dispatcher_bootstrap_failed');
        exit(2);
    }
});

try {
    chdir(dirname(__DIR__));
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../api/utils/ConfigSyncState.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('database unavailable');
    ensureConfigSyncStateSchema($pdo);
    $booted = true;
    configSyncWorkerLog('dispatcher_started', '', ['pid' => getmypid()]);
    $lastJobId = '';
    $lastAttemptAt = 0.0;
    while (true) {
        $state = configSyncWorkerReadState($pdo);
        $jobId = (string)($state['job_id'] ?? '');
        $now = microtime(true);
        $status = (string)($state['status'] ?? '');
        $eligible = $status === 'queued'
            ? !empty($state['is_due'])
            : ($status === 'running' && $jobId !== '' && !configSyncWorkerHasWaiter($pdo, $jobId));
        if ($eligible && $jobId !== '' && ($jobId !== $lastJobId || $now - $lastAttemptAt >= 30.0)) {
            $lastJobId = $jobId;
            $lastAttemptAt = $now;
            if ($status === 'running') {
                // 恢复函数在任务锁和全量写锁的同一连接上完成 running -> queued
                // CAS；锁竞争表示存活 worker/旧 worker 仍在执行，本轮绝不抢占。
                $recovered = configSyncWorkerRecoverRunning($pdo, $jobId);
                if (!empty($recovered['recovered'])) {
                    configSyncWorkerLog('running_recovered', $jobId);
                    $result = configSyncWorkerEnsureStarted($pdo, $jobId, false);
                    if (!empty($result['started'])) {
                        configSyncWorkerLog('dispatcher_recovered', $jobId, ['pid' => $result['pid'] ?? 0]);
                    } elseif (empty($result['scheduled'])) {
                        configSyncWorkerLog('dispatcher_retry_pending', $jobId);
                    }
                } elseif (in_array($recovered['reason'] ?? '', ['push_owned', 'recovery_failed'], true)) {
                    configSyncWorkerLog('dispatcher_retry_pending', $jobId);
                }
            } else {
                $result = configSyncWorkerEnsureStarted($pdo, $jobId, true);
                // 正常等待者每次被探测到时保持安静，仅新启动或实际失败记录事件。
                if (!empty($result['started'])) {
                    configSyncWorkerLog('dispatcher_recovered', $jobId, ['pid' => $result['pid'] ?? 0]);
                } elseif (empty($result['scheduled']) && !in_array($result['reason'], ['inactive', 'not_due'], true)) {
                    configSyncWorkerLog('dispatcher_retry_pending', $jobId);
                }
            }
        }
        sleep(5);
    }
} catch (Throwable $error) {
    $booted = true;
    configSyncWorkerLog('dispatcher_failed', '', ['error_code' => $error->getCode(), 'error_line' => $error->getLine()]);
    exit(3);
}
