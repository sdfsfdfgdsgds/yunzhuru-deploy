<?php
/**
 * 应用删除后台队列 runner。
 *
 * 多个应用连续删除时，前台只负责把清理任务写入队列；本脚本用文件锁保证
 * 同一时间只有一个 runner 处理队列，避免多个大统计表 DELETE 同时抢占 MySQL。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
$_GET['oss'] = $_GET['oss'] ?? null;

$root = dirname(__DIR__);
chdir($root);

require_once $root . '/config/db.php';
require_once $root . '/config/redis.php';

// 加载 API 工具类，保持和 /api/index.php 一致，确保 OSS/Auth/S3/删除工具可用。
$utilsDir = $root . '/api/utils/';
foreach (glob($utilsDir . '*.php') as $file) {
    require_once $file;
}
require_once $root . '/api/module/app.php';

$queueDir = appDeleteCleanupQueueDir();
$pendingDir = appDeleteCleanupQueueDir('pending');
$runningDir = appDeleteCleanupQueueDir('running');
$doneDir = appDeleteCleanupQueueDir('done');
$failedDir = appDeleteCleanupQueueDir('failed');
$lockFile = $queueDir . '/runner.lock';

$lockHandle = fopen($lockFile, 'c+');
if (!$lockHandle) {
    fwrite(STDERR, "无法打开队列锁文件：{$lockFile}\n");
    exit(2);
}

// 后台进程在此等待现有 runner，而不是立即退出。
// 否则会出现“旧 runner 已扫到空队列、新 job 随后落盘”的交错，导致 pending 无人继续处理。
if (!flock($lockHandle, LOCK_EX)) {
    fwrite(STDERR, "获取删除队列锁失败\n");
    exit(3);
}

ftruncate($lockHandle, 0);
fwrite($lockHandle, json_encode([
    'pid' => getmypid(),
    'started_at' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fflush($lockHandle);

// 能拿到独占锁说明当前没有其他 runner；running 目录中的任务是上次进程中断的遗留，
// 操作本身幂等，安全放回 pending 重放，避免永久卡在 running。
foreach (glob($runningDir . '/app_*.json') ?: [] as $staleRunningFile) {
    $recoveredPendingFile = $pendingDir . '/' . basename($staleRunningFile);
    if (is_file($recoveredPendingFile)) {
        @unlink($staleRunningFile);
        continue;
    }
    @rename($staleRunningFile, $recoveredPendingFile);
}

while (true) {
    $jobs = glob($pendingDir . '/app_*.json') ?: [];
    sort($jobs, SORT_NATURAL);
    if (!$jobs) {
        break;
    }

    $jobFile = $jobs[0];
    $jobName = basename($jobFile);
    $runningFile = $runningDir . '/' . $jobName;
    if (!@rename($jobFile, $runningFile)) {
        // 文件可能被另一个进程移动；虽然有锁，仍做容错。
        usleep(200000);
        continue;
    }

    $raw = (string)@file_get_contents($runningFile);
    $job = json_decode($raw, true);
    if (!is_array($job)) {
        @rename($runningFile, $failedDir . '/' . $jobName . '.bad_' . time());
        continue;
    }

    $appId = (int)($job['app_id'] ?? 0);
    $userId = (int)($job['user_id'] ?? 0);
    $role = (string)($job['role'] ?? '');
    $progressToken = (string)($job['progress_token'] ?? '');

    if ($appId <= 0 || $userId <= 0 || $progressToken === '') {
        @rename($runningFile, $failedDir . '/' . $jobName . '.invalid_' . time());
        continue;
    }

    appDeleteDebugLog($appId, 'cleanup_queue_job_start', [
        'job_file' => $runningFile,
        'queue_pid' => getmypid(),
    ]);

    try {
        appDeleteProgressUpdate($progressToken, $userId, $appId, 'running', '后台清理队列已开始处理该应用...', 23, [
            'async_cleanup' => true,
            'queue_running' => true,
        ]);
        $result = appDeleteRunCleanup($pdo, $appId, $userId, $role, $progressToken);
        $doneFile = $doneDir . '/' . $jobName . '.done_' . date('YmdHis');
        @file_put_contents($runningFile . '.result', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @rename($runningFile, $doneFile);
        @rename($runningFile . '.result', $doneFile . '.result.json');
        appDeleteDebugLog($appId, 'cleanup_queue_job_done', [
            'done_file' => $doneFile,
        ]);
    } catch (Throwable $e) {
        appDeleteDebugLog($appId, 'cleanup_queue_job_failed', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        @file_put_contents($runningFile . '.error', json_encode([
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'failed_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $failedFile = $failedDir . '/' . $jobName . '.failed_' . date('YmdHis');
        @rename($runningFile, $failedFile);
        @rename($runningFile . '.error', $failedFile . '.error.json');
    }
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
echo "删除队列处理完成\n";
