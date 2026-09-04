<?php
/**
 * 异步同步所有已注入应用的桶配置。
 * 用于全局配置变化后刷新 S3/R2/B2 上的 config/*.enc。
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/redis.php';
require_once __DIR__ . '/../api/utils/Auth.php';
require_once __DIR__ . '/../api/utils/BucketPush.php';
require_once __DIR__ . '/../api/utils/ConfigSyncState.php';

$jobId = trim((string)($argv[1] ?? ''));
try {
    // 调度器会把 job_id 作为参数传入；没有参数时兼容旧手工执行并接管当前排队任务。
    $state = configSyncStateRead($pdo);
    if ($jobId === '') $jobId = (string)($state['job_id'] ?? '');
    $runningState = configSyncStateMarkRunning($pdo, $jobId);
    // 旧 worker 可能在新一轮任务创建后才拿到锁；CAS 失败时直接退出，
    // 防止它读取并回填新任务的进度快照。
    if ($jobId !== '' && ((string)($runningState['job_id'] ?? '') !== $jobId
        || !in_array((string)($runningState['status'] ?? ''), ['queued', 'running'], true))) {
        error_log('[push_all_configs.php] 检测到过期任务，跳过旧 worker: ' . $jobId);
        exit(0);
    }
    // 全局配置保存可在短时间连续触发，false 表示按 dirty 标记合并重复任务。
    $result = pushAllConfigsToBuckets($pdo, false);
    configSyncStateMarkFinished($pdo, is_array($result) ? $result : [], $jobId);
    error_log('[push_all_configs.php] 推送完成: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    try {
        $failedSnapshot = configSyncStateRead($pdo);
        configSyncStateMarkFinished($pdo, [
            'total' => (int)($failedSnapshot['expected_total'] ?? 0),
            'success' => (int)($failedSnapshot['success'] ?? 0),
            'fail' => max(1, (int)($failedSnapshot['fail'] ?? 0)),
            'message' => $e->getMessage(),
        ], $jobId, $e);
    } catch (Throwable $ignored) {
        // 状态固化失败不覆盖原始同步异常；主日志仍保留具体原因。
    }
    error_log('[push_all_configs.php] 推送失败: ' . $e->getMessage());
    exit(1);
}
