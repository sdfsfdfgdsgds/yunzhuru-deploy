<?php
/**
 * 异步同步所有已注入应用的桶配置。
 * 用于全局配置变化后刷新 S3/R2/B2 上的 config/*.enc。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../api/utils/ConfigSyncWorker.php';
ini_set('display_errors', '0');
ini_set('log_errors', '0');

$jobId = trim((string)($argv[1] ?? ''));
$bootstrapped = false;
// 历史数据库引导可能直接输出连接错误并退出。丢弃正文，日志只保留固定
// 故障分类；未接管的 queued 原样留下，供常驻 dispatcher 再次尝试。
ob_start(static function (string $output): string { return ''; }, 1);
register_shutdown_function(static function () use (&$bootstrapped, &$jobId): void {
    $error = error_get_last();
    $fatal = $error && in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    if (!$bootstrapped || $fatal) {
        configSyncWorkerLog($fatal ? 'worker_fatal' : 'worker_bootstrap_failed', $jobId,
            ['error_code' => $error['type'] ?? 0, 'error_line' => $error['line'] ?? 0]);
        exit(2);
    }
});
$pdo = null;
$jobLockHeld = false;
$releaseJobLock = static function () use (&$pdo, &$jobId, &$jobLockHeld): void {
    if (!$jobLockHeld || $jobId === '') return;
    configSyncWorkerReleaseJobLock($pdo, $jobId);
    $jobLockHeld = false;
};
try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../config/redis.php';
    require_once __DIR__ . '/../api/utils/Auth.php';
    require_once __DIR__ . '/../api/utils/BucketPush.php';
    require_once __DIR__ . '/../api/utils/ConfigSyncState.php';
    if (!($pdo instanceof PDO)) throw new RuntimeException('database unavailable');
    $bootstrapped = true;
    // 调度器会把 job_id 作为参数传入；没有参数时兼容旧手工执行并接管当前排队任务。
    $state = configSyncStateRead($pdo);
    if ($jobId === '') $jobId = (string)($state['job_id'] ?? '');
    // 同一 job 只允许一个进程从防抖等待到终态持有生命周期锁；调度器看到
    // 任务仍 running 但此锁无人持有时，才有资格进行失主恢复。
    if ($jobId !== '' && (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        if (!configSyncWorkerAcquireJobLock($pdo, $jobId)) {
            configSyncWorkerLog('job_lock_busy', $jobId);
            exit(0);
        }
        $jobLockHeld = true;
        configSyncWorkerLog('job_lock_acquired', $jobId, ['pid' => getmypid()]);
    }
    // 自动触发采用防抖：只有最后一次修改后的截止时间到达，worker 才会
    // 抢占 running。生命周期锁在等待期间保持，避免 dispatcher 将存活 worker
    // 误判为失主并重置同一代次。
    if (function_exists('configSyncStateWaitForDue')) {
        // 首轮只能接管 queued。失主 running 必须先由 dispatcher 在双锁下
        // 恢复并重新置 dirty，避免直接执行时把中断前已消费的 dirty 当成完成。
        $ready = configSyncStateWaitForDue($pdo, $jobId, true);
        if (empty($ready['ready'])) {
            $releaseJobLock();
            configSyncWorkerLog('waiter_not_claimed', $jobId);
            exit(0);
        }
    } else {
        // 旧节点未部署新 helper 时保留原有立即运行兼容行为。
        $runningState = configSyncStateMarkRunning($pdo, $jobId);
        if ($jobId !== '' && ((string)($runningState['job_id'] ?? '') !== $jobId
            || !in_array((string)($runningState['status'] ?? ''), ['queued', 'running'], true))) {
            $releaseJobLock();
            configSyncWorkerLog('stale_job', $jobId);
            exit(0);
        }
    }
    // 全局配置保存可在短时间连续触发，false 表示按 dirty 标记合并重复任务。
    // 每次 push 只持全局锁执行一个快照；新修改由本外层循环在释放锁后等满
    // 新的 60 秒防抖窗口，然后继续同一 job，不限制变更轮数。
    while (true) {
        $result = pushAllConfigsToBuckets($pdo, false, $jobId);
        if (!empty($result['stale_job'])) {
            $releaseJobLock();
            configSyncWorkerLog('stale_job', $jobId);
            exit(0);
        }
        // 上传期间又有修改时先释放 BucketPush 里的 advisory lock，
        // 再在这里等待“最后一次修改 + 防抖窗口”，不把 dirty 遗留到终态。
        if (!empty($result['pending_change'])) {
            $ready = function_exists('configSyncStateWaitForDue')
                ? configSyncStateWaitForDue($pdo, $jobId, false)
                : ['ready' => true];
            if (empty($ready['ready'])) {
                $releaseJobLock();
                configSyncWorkerLog('stale_job', $jobId);
                exit(0);
            }
            continue;
        }

        $finished = configSyncStateMarkFinished($pdo, is_array($result) ? $result : [], $jobId);
        // 终态 UPDATE 同时检查 debounce_until 和 distribution_dirty。
        // 修改与终态竞争时，CAS 未命中就继续等待/补跑；若终态先落库，
        // 后到修改会创建新 job，旧 worker 不会覆盖它。
        if (empty($finished['finish_applied']) && $jobId !== ''
            && (string)($finished['job_id'] ?? '') === $jobId
            && (string)($finished['status'] ?? '') === 'running') {
            $ready = function_exists('configSyncStateWaitForDue')
                ? configSyncStateWaitForDue($pdo, $jobId, false)
                : ['ready' => true];
            if (empty($ready['ready'])) {
                $releaseJobLock();
                configSyncWorkerLog('stale_job', $jobId);
                exit(0);
            }
            continue;
        }
        configSyncWorkerLog('finished', $jobId, is_array($result) ? $result : []);
        $releaseJobLock();
        break;
    }
} catch (Throwable $e) {
    try {
        $failedSnapshot = configSyncStateRead($pdo);
        // 保留 worker 已经逐 APP 写入的公开结果；异常终态不能把此前成功
        // 的桶对象明细覆盖成一条空错误。
        $failureResult = is_array($failedSnapshot['result'] ?? null)
            ? $failedSnapshot['result'] : [];
        $failureResult['total'] = (int)($failedSnapshot['expected_total'] ?? 0);
        $failureResult['success'] = (int)($failedSnapshot['success'] ?? 0);
        $failureResult['fail'] = max(1, (int)($failedSnapshot['fail'] ?? 0));
        $failureResult['message'] = $e->getMessage();
        // 失败终态必须在仍持有生命周期锁时固化，否则 dispatcher 可在此
        // 窗口把 running 恢复 queued，随后被旧进程写成失败。旧 job 不覆盖新任务。
        if ((string)($failedSnapshot['job_id'] ?? '') === $jobId
            && (string)($failedSnapshot['status'] ?? '') === 'running') {
            configSyncStateMarkFinished($pdo, $failureResult, $jobId, $e, true);
        }
    } catch (Throwable $ignored) {
        // 状态固化失败不覆盖原始同步异常；主日志仍保留具体原因。
    } finally {
        $releaseJobLock();
    }
    configSyncWorkerLog('failed', $jobId, ['error_code' => $e->getCode(), 'error_line' => $e->getLine()]);
    exit(1);
}
