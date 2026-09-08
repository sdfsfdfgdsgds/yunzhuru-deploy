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
$watcherLockName = '';
$watcherLockHeld = false;
$releaseWatcherLock = static function () use (&$pdo, &$watcherLockName, &$watcherLockHeld): void {
    if (!$watcherLockHeld || $watcherLockName === '') return;
    try {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute([':lock_name' => $watcherLockName]);
        $release->fetchColumn();
    } catch (Throwable $ignored) {
        // 数据库连接断开时 MySQL 会自动释放该等待锁。
    }
    $watcherLockHeld = false;
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
    // 排队期的每次修改都可启动一个接替 watcher，用于覆盖首个请求在 exec
    // 前退出的窗口；同一 job 只允许一个进程持有该零等待 advisory lock，
    // 其余进程立即结束，因此不会形成大量驻留进程或重复上传。
    if ($jobId !== '' && (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $watcherLockName = configSyncWorkerWaiterLockName($jobId);
        $watcherLock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $watcherLock->execute([':lock_name' => $watcherLockName]);
        if ((int)$watcherLock->fetchColumn() !== 1) {
            configSyncWorkerLog('waiter_already_owned', $jobId);
            exit(0);
        }
        $watcherLockHeld = true;
        configSyncWorkerLog('waiter_acquired', $jobId, ['pid' => getmypid()]);
    }
    // 自动触发采用防抖：只有最后一次修改后的截止时间到达，worker 才会
    // 抢占 running。等待期间每次重读数据库，因此连续保存会继续向后延迟。
    if (function_exists('configSyncStateWaitForDue')) {
        $ready = configSyncStateWaitForDue($pdo, $jobId, true);
        if (empty($ready['ready'])) {
            $releaseWatcherLock();
            configSyncWorkerLog('waiter_not_claimed', $jobId);
            exit(0);
        }
    } else {
        // 旧节点未部署新 helper 时保留原有立即运行兼容行为。
        $runningState = configSyncStateMarkRunning($pdo, $jobId);
        if ($jobId !== '' && ((string)($runningState['job_id'] ?? '') !== $jobId
            || !in_array((string)($runningState['status'] ?? ''), ['queued', 'running'], true))) {
            configSyncWorkerLog('stale_job', $jobId);
            exit(0);
        }
    }
    // 状态已经由本进程 CAS 切换为 running；之后的新修改会加入当前运行
    // 任务而不是再启动 watcher，此处即可释放排队期专用锁。
    $releaseWatcherLock();

    // 全局配置保存可在短时间连续触发，false 表示按 dirty 标记合并重复任务。
    // 每次 push 只持全局锁执行一个快照；新修改由本外层循环在释放锁后等满
    // 新的 60 秒防抖窗口，然后继续同一 job，不限制变更轮数。
    while (true) {
        $result = pushAllConfigsToBuckets($pdo, false, $jobId);
        if (!empty($result['stale_job'])) {
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
                configSyncWorkerLog('stale_job', $jobId);
                exit(0);
            }
            continue;
        }
        configSyncWorkerLog('finished', $jobId, is_array($result) ? $result : []);
        break;
    }
} catch (Throwable $e) {
    $releaseWatcherLock();
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
        configSyncStateMarkFinished($pdo, $failureResult,
            $jobId, $e, true);
    } catch (Throwable $ignored) {
        // 状态固化失败不覆盖原始同步异常；主日志仍保留具体原因。
    }
    configSyncWorkerLog('failed', $jobId, ['error_code' => $e->getCode(), 'error_line' => $e->getLine()]);
    exit(1);
}
