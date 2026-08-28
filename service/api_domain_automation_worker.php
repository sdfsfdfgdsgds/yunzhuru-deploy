<?php
/**
 * API 域名池自动化调度 worker。
 *
 * 该进程只负责周期触发到期策略；并发抢占、计划生成、访问观测和
 * 待清理本地隔离由 ApiDomainAutomation 统一处理。任何顶层异常都以非零状态退出，
 * 交给 Supervisor 重建数据库与 Redis 连接，避免断线句柄在循环中常驻。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

/** 从调度结果中读取白名单顶层计数。 */
function apiDomainAutomationWorkerMetric(array $result, string $field): int
{
    if (array_key_exists($field, $result) && is_numeric($result[$field])) {
        return max(0, (int)$result[$field]);
    }
    return 0;
}

/** 只累加单次运行结果中的白名单计数，不读取标识或文本字段。 */
function apiDomainAutomationWorkerResultMetric(array $result, string $field): int
{
    $total = 0;
    $rows = isset($result['results']) && is_array($result['results']) ? $result['results'] : [];
    foreach ($rows as $row) {
        if (is_array($row) && array_key_exists($field, $row) && is_numeric($row[$field])) {
            $total += max(0, (int)$row[$field]);
        }
    }
    return $total;
}

/**
 * 将一次调度结果收敛为固定计数字段。
 *
 * 不把返回数组整体写入日志，避免云账号引用或后续适配器字段
 * 在合同扩展后被意外输出。
 */
function apiDomainAutomationWorkerSummary($result, $cloudResult = []): array
{
    $result = is_array($result) ? $result : [];
    $cloudResult = is_array($cloudResult) ? $cloudResult : [];
    $summary = [
        'checked' => apiDomainAutomationWorkerMetric($result, 'checked'),
        'processed' => apiDomainAutomationWorkerMetric($result, 'processed'),
        'waiting_adapter' => apiDomainAutomationWorkerMetric($result, 'waiting_adapter'),
        'marked' => apiDomainAutomationWorkerResultMetric($result, 'marked_count'),
        'cleanup_pending' => apiDomainAutomationWorkerResultMetric($result, 'cleanup_pending_count'),
        'protected' => apiDomainAutomationWorkerResultMetric($result, 'protected_count'),
        'skipped' => apiDomainAutomationWorkerMetric($result, 'skipped'),
        'failed' => apiDomainAutomationWorkerMetric($result, 'failed'),
        'cloud_checked' => apiDomainAutomationWorkerMetric($cloudResult, 'checked'),
        'cloud_processed' => apiDomainAutomationWorkerMetric($cloudResult, 'processed'),
        'cloud_succeeded' => apiDomainAutomationWorkerMetric($cloudResult, 'succeeded'),
        'cloud_retry_wait' => apiDomainAutomationWorkerMetric($cloudResult, 'retry_wait'),
        'cloud_created' => apiDomainAutomationWorkerMetric($cloudResult, 'created'),
        'cloud_probed' => apiDomainAutomationWorkerMetric($cloudResult, 'probed'),
        'cloud_archived' => apiDomainAutomationWorkerMetric($cloudResult, 'archived'),
        'cloud_failed' => apiDomainAutomationWorkerMetric($cloudResult, 'failed'),
        'cloud_skipped' => apiDomainAutomationWorkerMetric($cloudResult, 'skipped'),
    ];
    $summary['total'] = array_sum($summary);
    return $summary;
}

/** 输出固定字段，日志中不包含运行结果原文或异常消息。 */
function apiDomainAutomationWorkerLog(string $status, array $summary = []): void
{
    $fields = [
        'checked',
        'processed',
        'waiting_adapter',
        'marked',
        'cleanup_pending',
        'protected',
        'skipped',
        'failed',
        'cloud_checked',
        'cloud_processed',
        'cloud_succeeded',
        'cloud_retry_wait',
        'cloud_created',
        'cloud_probed',
        'cloud_archived',
        'cloud_failed',
        'cloud_skipped',
    ];
    $parts = [date('Y-m-d H:i:s'), 'status=' . $status];
    foreach ($fields as $field) {
        $parts[] = $field . '=' . max(0, (int)($summary[$field] ?? 0));
    }
    echo implode(' ', $parts) . PHP_EOL;
}

$root = dirname(__DIR__);
$running = true;
$exitCode = 0;

try {
    chdir($root);
    require_once $root . '/config/db.php';
    require_once $root . '/config/redis.php';
    require_once $root . '/api/utils/ConfigDelivery.php';
    require_once $root . '/api/utils/ApiDomainAutomation.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        apiDomainAutomationWorkerLog('database_unavailable', ['failed' => 1]);
        exit(2);
    }
    if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
        throw new RuntimeException('pcntl extension unavailable');
    }

    // 表结构在首次调度前完成幂等初始化，避免轮询期间遇到半迁移状态。
    ensureApiDomainAutomationSchema($pdo);

    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function (int $signal) use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function (int $signal) use (&$running): void {
        $running = false;
    });

    apiDomainAutomationWorkerLog('started');
    $idleCycles = 0;
    while ($running) {
        $result = apiDomainAutomationProcessDue($pdo, 10);
        // 云写作业每轮严格最多 1 个，避免多个 STS/CloudFront 长请求串行占用停机窗口。
        $cloudResult = apiDomainAutomationProcessCloudJobs($pdo, 1);
        $summary = apiDomainAutomationWorkerSummary($result, $cloudResult);
        if ($summary['total'] > 0) {
            $idleCycles = 0;
            $status = ($summary['failed'] + $summary['cloud_failed']) > 0
                ? 'completed_with_failures'
                : (($summary['processed'] + $summary['cloud_processed']) > 0 ? 'processed' : 'checked');
            apiDomainAutomationWorkerLog($status, $summary);
        } else {
            $idleCycles++;
            // 空闲时每小时最多记录一次心跳，避免 30 秒轮询制造日志噪音。
            if ($idleCycles === 1 || $idleCycles >= 120) {
                apiDomainAutomationWorkerLog('idle', $summary);
                $idleCycles = $idleCycles >= 120 ? 0 : $idleCycles;
            }
        }

        if ($running) {
            sleep(30);
        }
    }
    apiDomainAutomationWorkerLog('stopped');
} catch (Throwable $failure) {
    // 不记录异常原文，以免 DSN、云账号引用等运行信息进入持久日志。
    apiDomainAutomationWorkerLog('failed', ['failed' => 1]);
    $exitCode = 3;
}

exit($exitCode);
