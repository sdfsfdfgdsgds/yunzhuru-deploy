<?php
/**
 * 配置分发桶管理 API。
 *
 * 支持 B2、AWS S3、Cloudflare R2 的资料管理、按字段查看凭据、连接测试、
 * config/{APPID}.enc 文件盘点、全量同步，以及新版壳成功应用配置后的命中回执。
 */

require_once __DIR__ . '/../utils/BucketFeature.php';
require_once __DIR__ . '/../utils/S3Client.php';
require_once __DIR__ . '/../utils/ConfigSyncState.php';

/** 管理员鉴权并确保桶功能表结构已经就绪。 */
function bucketRequireAdmin(PDO $pdo): array {
    $user = Auth::check($pdo);
    if (($user['role'] ?? '') !== 'admin') throw new Exception('无权限');
    ensureBucketFeatureSchema($pdo);
    return $user;
}

/** 根据主键读取桶的完整数据库记录。 */
function bucketFindById(PDO $pdo, int $bucketId): array {
    if ($bucketId <= 0) throw new InvalidArgumentException('桶 ID 格式错误');
    $stmt = $pdo->prepare('SELECT * FROM cainiao_s3_bucket WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $bucketId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('存储桶不存在');
    return $row;
}

/**
 * 生成同步中心可读的桶连接定位信息。
 * 这里只展示名称、服务商和公开端点/域名，不携带 AccessKey、SecretKey 等敏感字段，
 * 让“触发原因”明确指出本次更新影响了哪一条连接。
 */
function bucketSyncConnectionReason(string $action, array $record, int $bucketId = 0): string {
    $name = trim((string)($record['name'] ?? ''));
    $provider = trim((string)($record['provider_label'] ?? $record['provider'] ?? ''));
    $endpoint = trim((string)($record['endpoint'] ?? ''));
    $domain = trim((string)($record['domain'] ?? ''));
    $bucket = trim((string)($record['bucket'] ?? ''));
    $parts = [];
    if ($bucketId > 0) $parts[] = '桶 #' . $bucketId;
    if ($name !== '') $parts[] = $name;
    if ($provider !== '') $parts[] = $provider;
    // Endpoint、domain、Bucket 是三类不同定位信息，全部保留并分别标注，
    // 避免只改公开域名时同步中心仍显示旧的写入端点。
    if ($endpoint !== '') $parts[] = '端点 ' . $endpoint;
    if ($domain !== '') $parts[] = '域名 ' . $domain;
    if ($bucket !== '') $parts[] = 'Bucket ' . $bucket;
    $reason = trim($action . ($parts ? ' · ' . implode(' · ', $parts) : ''));
    return function_exists('mb_substr') ? mb_substr($reason, 0, 240, 'UTF-8') : substr($reason, 0, 240);
}

/**
 * 返回不含原始敏感值的桶记录。
 * 凭据摘要只有编辑详情需要，列表和文件接口不解密也不返回任何凭据片段。
 */
function bucketSanitizeRow(array $row, bool $includeCredentialDisplays = false): array {
    $safe = $row;
    if ($includeCredentialDisplays) {
        $safe['login_account_display'] = bucketMaskSecret($row['login_account'] ?? '');
        $safe['login_password_display'] = bucketMaskSecret($row['login_password'] ?? '');
        $safe['access_key_display'] = bucketMaskSecret($row['access_key'] ?? '');
        $safe['secret_key_display'] = bucketMaskSecret($row['secret_key'] ?? '');
    }
    $provider = strtolower((string)($row['provider'] ?? ''));
    $safe['provider_label'] = bucketProviderLabel($provider);
    if ($provider === 's3') {
        try {
            bucketValidateEndpoint('s3', (string)($row['endpoint'] ?? ''));
        } catch (Throwable $ignored) {
            $safe['provider_label'] = '旧版 S3 兼容端点';
        }
    }
    $safe['domain'] = bucketSafeStoredPublicUrl((string)($row['domain'] ?? ''));
    $safe['last_push_at_display'] = '';
    if (!empty($row['last_push_at'])) {
        try {
            $time = new DateTimeImmutable((string)$row['last_push_at'], new DateTimeZone('UTC'));
            $safe['last_push_at_display'] = $time->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        } catch (Throwable $ignored) {
            $safe['last_push_at_display'] = (string)$row['last_push_at'];
        }
    }
    unset($safe['login_account'], $safe['login_password'], $safe['access_key'], $safe['secret_key']);
    return $safe;
}

/** 启动全量配置后台同步；启动失败不影响桶资料事务。 */
function bucketScheduleFullSync(PDO $pdo, string $reason = '配置桶变更'): bool {
    // 统一复用状态 helper，保证桶资料、应用配置和全局节点池共用同一任务代次。
    if (function_exists('configSyncStateScheduleWorker')) {
        try {
            $scheduled = configSyncStateScheduleWorker($pdo, $reason);
            if (!empty($scheduled['scheduled'])) return true;
            // helper 已将失败写入状态，继续尝试旧路径，兼容 exec 临时受限的滚动发布窗口。
        } catch (Throwable $ignored) {
            // 状态表/worker 调度异常不阻断桶资料保存；继续走下方兼容路径。
        }
    }
    $script = realpath(__DIR__ . '/../../service/push_all_configs.php');
    $alreadyActive = false;
    $before = [];
    try {
        $before = configSyncStateRead($pdo);
        $alreadyActive = in_array((string)($before['status'] ?? ''), ['queued', 'running'], true)
            && (string)($before['job_id'] ?? '') !== '';
    } catch (Throwable $ignored) {
        // 状态表尚未迁移时继续走原有 worker 调度流程。
    }
    // 先落库排队快照，让右下角同步中心在 worker 启动前即可显示本次变更。
    try {
        $snapshot = configSyncStateMarkQueued($pdo, $reason);
        // 重新核对 job_id，处理“读取旧状态后 worker 恰好完成”的竞态窗口。
        if ($alreadyActive) {
            $alreadyActive = in_array((string)($snapshot['status'] ?? ''), ['queued', 'running'], true)
                && (string)($snapshot['job_id'] ?? '') !== ''
                && (string)($snapshot['job_id'] ?? '') === (string)($before['job_id'] ?? '');
        }
    } catch (Throwable $ignored) {
        // 状态表尚未迁移或只读时不阻断桶资料保存；worker 仍可按旧合同运行。
        $snapshot = [];
    }
    if (!$script || !function_exists('exec')) {
        if (!empty($snapshot)) {
            try {
                configSyncStateMarkFinished(
                    $pdo,
                    ['total' => 0, 'success' => 0, 'fail' => 0, 'message' => '后台同步脚本未启动'],
                    (string)($snapshot['job_id'] ?? ''),
                    new RuntimeException('后台同步脚本未启动')
                );
            } catch (Throwable $ignored) {}
        }
        return false;
    }
    // 已有任务会通过 distribution_dirty 吸收本次变更，不再额外创建等待 GET_LOCK 的进程。
    if ($alreadyActive) return true;
    $output = [];
    $exitCode = 1;
    // 将任务代次传给 worker；旧 worker 即使晚于新任务拿到锁，也不会覆盖新快照。
    $jobId = (string)($snapshot['job_id'] ?? '');
    $command = 'php ' . escapeshellarg($script)
        . ($jobId !== '' ? ' ' . escapeshellarg($jobId) : '')
        . ' > /dev/null 2>&1 & echo $!';
    @exec($command, $output, $exitCode);
    return $exitCode === 0 && !empty($output) && ctype_digit(trim((string)end($output)));
}

/** 尝试读取同步快照；状态表异常时返回空数组，不影响桶管理主流程。 */
function bucketReadSyncStateSafe(PDO $pdo): array {
    try {
        return configSyncStateRead($pdo);
    } catch (Throwable $ignored) {
        return [];
    }
}

/** 对一个具体对象做公开读取检查；该结果不计入壳端命中统计。 */
function bucketCheckPublicObject(string $url): array {
    if ($url === '' || !function_exists('curl_init')) {
        return ['checked' => 0, 'ok' => 0, 'http_code' => 0, 'message' => '没有可检查的公开对象'];
    }
    $safeIps = bucketPublicUrlSafeIps($url);
    if (empty($safeIps)) {
        return ['checked' => 1, 'ok' => 0, 'http_code' => 0, 'message' => '公开地址未解析到安全公网 IP'];
    }
    $host = trim((string)(parse_url($url, PHP_URL_HOST) ?: ''), '[]');
    $port = (int)(parse_url($url, PHP_URL_PORT) ?: 443);
    $pinnedIp = (string)$safeIps[0];
    if (strpos($pinnedIp, ':') !== false) $pinnedIp = '[' . $pinnedIp . ']';
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPGET => true,
        // 使用与壳端一致的 GET，只请求第一个字节，避免 HEAD 在部分 CDN 上产生假故障。
        CURLOPT_RANGE => '0-0',
        CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'Pragma: no-cache'],
        CURLOPT_USERAGENT => 'yunzhuru-bucket-check/1.0',
        // 将本次 cURL 连接固定到上面已校验的公网 IP，关闭 DNS rebinding 窗口。
        CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinnedIp}"],
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($ch, $options);
    curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (string)curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['checked' => 1, 'ok' => 1, 'http_code' => $httpCode, 'message' => '公开 GET 读取正常'];
    }
    return [
        'checked' => 1,
        'ok' => 0,
        'http_code' => $httpCode,
        'message' => $error !== '' ? $error : ($httpCode > 0 ? "HTTP {$httpCode}" : '公开读取失败'),
    ];
}

/** 返回指定桶的文件级聚合统计，以 APPID 为键。 */
function bucketFileStatsMap(PDO $pdo, int $bucketId): array {
    $today = bucketFeatureToday();
    $stmt = $pdo->prepare("SELECT app_id,
        SUM(hit_count) AS total_hits,
        SUM(CASE WHEN stat_date = :today THEN hit_count ELSE 0 END) AS today_hits,
        DATE_FORMAT(DATE_ADD(MAX(last_seen_at), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS last_seen_at
        FROM cainiao_s3_bucket_file_stats
        WHERE bucket_id = :bucket_id
        GROUP BY app_id");
    $stmt->execute([':today' => $today, ':bucket_id' => $bucketId]);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int)$row['app_id']] = [
            'total_hits' => (int)$row['total_hits'],
            'today_hits' => (int)$row['today_hits'],
            'last_seen_at' => (string)($row['last_seen_at'] ?? ''),
        ];
    }
    return $result;
}

/** 严格解析统计筛选中的可选整数 ID，空值表示不限制。 */
function bucketStatsParseOptionalId($value, string $label): int {
    if ($value === null || $value === '') return 0;
    if (is_int($value)) {
        $parsed = $value;
    } elseif (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', $value)) {
        $parsed = (int)$value;
    } else {
        throw new InvalidArgumentException("{$label} 格式错误");
    }
    if ($parsed < 0 || $parsed > 2147483647) {
        throw new InvalidArgumentException("{$label} 超出有效范围");
    }
    return $parsed;
}

/**
 * 解析北京时间统计区间。
 *
 * start_date/end_date 是包含边界的日期；未传日期时可用 range 选择快捷区间，
 * 并默认返回今天在内的最近 7 天。单次最多返回 366 个日聚合点。
 */
function bucketStatsDateRange(array $input): array {
    $timezone = new DateTimeZone('Asia/Shanghai');
    $today = new DateTimeImmutable('today', $timezone);
    $range = strtolower(trim((string)($input['range'] ?? '')));
    $aliases = [
        '' => 'last7',
        '7d' => 'last7',
        'last_7_days' => 'last7',
        '30d' => 'last30',
        'last_30_days' => 'last30',
    ];
    if (isset($aliases[$range])) $range = $aliases[$range];
    if (!in_array($range, ['today', 'yesterday', 'last7', 'last30', 'custom'], true)) {
        throw new InvalidArgumentException('统计时间范围错误');
    }

    $startText = trim((string)($input['start_date'] ?? ''));
    $endText = trim((string)($input['end_date'] ?? ''));
    if (($startText === '') !== ($endText === '')) {
        throw new InvalidArgumentException('自定义统计必须同时提供开始和结束日期');
    }

    $parse = static function (string $text) use ($timezone): DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('日期格式必须为 YYYY-MM-DD');
        }
        return $date;
    };

    if ($startText !== '') {
        $start = $parse($startText);
        $end = $parse($endText);
        // 旧前端曾在预设请求中同时传日期；只有日期与北京时间预设完全一致时保留预设语义。
        $presetDates = [
            'today' => [$today, $today],
            'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
            'last7' => [$today->modify('-6 days'), $today],
            'last30' => [$today->modify('-29 days'), $today],
        ];
        if (!isset($presetDates[$range])
            || $start->format('Y-m-d') !== $presetDates[$range][0]->format('Y-m-d')
            || $end->format('Y-m-d') !== $presetDates[$range][1]->format('Y-m-d')) {
            $range = 'custom';
        }
    } elseif ($range === 'today') {
        $start = $today;
        $end = $today;
    } elseif ($range === 'yesterday') {
        $start = $today->modify('-1 day');
        $end = $start;
    } elseif ($range === 'last30') {
        $start = $today->modify('-29 days');
        $end = $today;
    } elseif ($range === 'custom') {
        throw new InvalidArgumentException('自定义统计必须提供开始和结束日期');
    } else {
        $range = 'last7';
        $start = $today->modify('-6 days');
        $end = $today;
    }

    if ($start > $end) throw new InvalidArgumentException('开始日期不得晚于结束日期');
    $days = (int)$start->diff($end)->format('%a') + 1;
    if ($days > 366) throw new InvalidArgumentException('单次最多查询 366 天');

    $labels = [
        'today' => '今天',
        'yesterday' => '昨天',
        'last7' => '近7天',
        'last30' => '近30天',
        'custom' => '自定义',
    ];
    return [
        'range' => $range,
        'label' => $labels[$range],
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
        'timezone' => 'Asia/Shanghai',
        'days' => $days,
    ];
}

/** 将数据库聚合行转换为前端稳定的整数统计字段。 */
function bucketStatsNormalizeMetricRow(array $row): array {
    foreach ([
        'hits', 'period_hits', 'range_hits', 'today_hits', 'yesterday_hits',
        'last7_hits', 'last30_hits', 'total_hits', 'bucket_count', 'app_count',
        'last7_bucket_count', 'last7_app_count',
        'period_bucket_count', 'period_app_count', 'total_bucket_count', 'total_app_count',
    ] as $field) {
        if (array_key_exists($field, $row)) $row[$field] = (int)$row[$field];
    }
    if (array_key_exists('last_seen_at', $row)) $row['last_seen_at'] = (string)($row['last_seen_at'] ?? '');
    return $row;
}

/** 判断统计请求是否只需顶部总览，避免为首屏计算全部明细维度。 */
function bucketStatsIsSummaryOnly(array $input): bool {
    if (array_key_exists('summary_only', $input)) {
        $value = $input['summary_only'];
        return $value === true || $value === 1 || $value === '1'
            || (is_string($value) && strtolower(trim($value)) === 'true');
    }
    if (array_key_exists('detail', $input)) {
        $value = $input['detail'];
        return $value === false || $value === 0 || $value === '0'
            || (is_string($value) && strtolower(trim($value)) === 'false');
    }
    return false;
}

/**
 * 计算某个桶当前应该具备的 config/{APPID}.enc 集合。
 *
 * 有显式 bucket_ids 的成功任务沿用任务选择；没有显式选择的旧任务沿用 BucketPush
 * 的兼容合同，即当前所有 enabled 桶都应存在该应用配置。
 */
function bucketExpectedApps(PDO $pdo, array $bucketRow, bool $internalOnly = true): array {
    ensureApkDeleteMarkerTable($pdo);
    $stmt = $pdo->query("SELECT a.id, a.name, a.package, t.bucket_ids
        FROM cainiao_apk a
        INNER JOIN cainiao_inject_task t ON t.apk_id = a.id AND t.status_text = '编译成功'
        LEFT JOIN cainiao_apk_deleted d ON d.apk_id = a.id
        WHERE d.apk_id IS NULL
        ORDER BY a.id ASC, t.id ASC");

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $appId = (int)$row['id'];
        if (!isset($grouped[$appId])) {
            $grouped[$appId] = [
                'app_id' => $appId,
                'app_name' => (string)($row['name'] ?? ''),
                'package_name' => (string)($row['package'] ?? ''),
                'selected_ids' => [],
            ];
        }
        $ids = json_decode((string)($row['bucket_ids'] ?? ''), true);
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id > 0) $grouped[$appId]['selected_ids'][$id] = true;
            }
        }
    }

    $bucketId = (int)$bucketRow['id'];
    $enabled = (int)($bucketRow['enabled'] ?? 0) === 1;
    $expected = [];
    foreach ($grouped as $app) {
        $hasExplicitSelection = !empty($app['selected_ids']);
        if (($hasExplicitSelection && isset($app['selected_ids'][$bucketId])) || (!$hasExplicitSelection && $enabled)) {
            $appId = (int)$app['app_id'];
            $expected[$appId] = [
                'key' => "config/{$appId}.enc",
                'app_id' => $appId,
                'app_name' => $app['app_name'],
                'package_name' => $app['package_name'],
            ];
        }
    }
    return $expected;
}

/** 获取全部桶；敏感字段只返回摘要，并带桶级今日/累计统计。 */
function getBuckets(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $today = bucketFeatureToday();
    $stmt = $pdo->prepare("SELECT b.*,
        COALESCE(s.total_hits, 0) AS total_hits,
        COALESCE(s.ok_hits, 0) AS ok_hits,
        COALESCE(s.fail_hits, 0) AS fail_hits,
        COALESCE(s.today_hits, 0) AS today_hits,
        COALESCE(s.today_ok_hits, 0) AS today_ok_hits,
        COALESCE(s.today_fail_hits, 0) AS today_fail_hits,
        COALESCE(s.last_seen_at, '') AS last_seen_at
        FROM cainiao_s3_bucket b
        LEFT JOIN (
            SELECT bucket_id,
                SUM(hit_count) AS total_hits,
                SUM(ok_count) AS ok_hits,
                SUM(fail_count) AS fail_hits,
                SUM(CASE WHEN stat_date = :today THEN hit_count ELSE 0 END) AS today_hits,
                SUM(CASE WHEN stat_date = :today2 THEN ok_count ELSE 0 END) AS today_ok_hits,
                SUM(CASE WHEN stat_date = :today3 THEN fail_count ELSE 0 END) AS today_fail_hits,
                DATE_FORMAT(DATE_ADD(MAX(last_seen_at), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS last_seen_at
            FROM cainiao_s3_bucket_stats
            GROUP BY bucket_id
        ) s ON s.bucket_id = b.id
        ORDER BY b.id ASC");
    $stmt->execute([':today' => $today, ':today2' => $today, ':today3' => $today]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $safe = bucketSanitizeRow($row);
        $safe['stats'] = [
            'total_hits' => (int)$row['total_hits'],
            'ok_hits' => (int)$row['ok_hits'],
            'fail_hits' => (int)$row['fail_hits'],
            'today_hits' => (int)$row['today_hits'],
            'today_ok_hits' => (int)$row['today_ok_hits'],
            'today_fail_hits' => (int)$row['today_fail_hits'],
            'last_seen_at' => (string)$row['last_seen_at'],
        ];
        foreach (['total_hits', 'ok_hits', 'fail_hits', 'today_hits', 'today_ok_hits', 'today_fail_hits', 'last_seen_at'] as $field) {
            unset($safe[$field]);
        }
        $rows[] = $safe;
    }
    return $rows;
}

/**
 * 读取配置桶命中统计详情。
 *
 * 统计来自壳端成功应用 config/{APPID}.enc 后的日聚合回执，不是 B2/S3/R2
 * 控制台的原始 HTTP 请求数。所有日期均按北京时间计算且包含起止日。
 */
function getBucketStatsDetail(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $period = bucketStatsDateRange($input);
    $bucketId = bucketStatsParseOptionalId($input['bucket_id'] ?? null, '桶 ID');
    $appId = bucketStatsParseOptionalId($input['app_id'] ?? null, '应用 ID');
    $summaryOnly = bucketStatsIsSummaryOnly($input);
    $ownsTransaction = !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            // MySQL 的 REPEATABLE READ 会在首个一致性读取时建立快照，后续所有维度复用该快照。
            if ($pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false
                || !$pdo->beginTransaction()) {
                throw new RuntimeException('建立统计一致性快照失败');
            }
        }
        if ($bucketId > 0) bucketFindById($pdo, $bucketId);

    $timezone = new DateTimeZone('Asia/Shanghai');
    $todayDate = new DateTimeImmutable(bucketFeatureToday(), $timezone);
    $today = $todayDate->format('Y-m-d');
    $yesterday = $todayDate->modify('-1 day')->format('Y-m-d');
    $last7Start = $todayDate->modify('-6 days')->format('Y-m-d');
    $last30Start = $todayDate->modify('-29 days')->format('Y-m-d');
    $startDate = (string)$period['start_date'];
    $endDate = (string)$period['end_date'];

    // 日期全部先通过严格 Y-m-d 校验，再由 PDO 转义后用于多个聚合表达式。
    $startQuoted = $pdo->quote($startDate);
    $endQuoted = $pdo->quote($endDate);
    $todayQuoted = $pdo->quote($today);
    $yesterdayQuoted = $pdo->quote($yesterday);
    $last7StartQuoted = $pdo->quote($last7Start);
    $last30StartQuoted = $pdo->quote($last30Start);

    $fileFilters = [];
    if ($bucketId > 0) $fileFilters[] = 'f.bucket_id=' . $bucketId;
    if ($appId > 0) $fileFilters[] = 'f.app_id=' . $appId;
    $fileWhere = $fileFilters ? ' WHERE ' . implode(' AND ', $fileFilters) : '';
    $periodCondition = "f.stat_date BETWEEN {$startQuoted} AND {$endQuoted}";

    $summarySql = "SELECT
        COALESCE(SUM(CASE WHEN {$periodCondition} THEN f.hit_count ELSE 0 END),0) AS period_hits,
        COALESCE(SUM(CASE WHEN f.stat_date={$todayQuoted} THEN f.hit_count ELSE 0 END),0) AS today_hits,
        COALESCE(SUM(CASE WHEN f.stat_date={$yesterdayQuoted} THEN f.hit_count ELSE 0 END),0) AS yesterday_hits,
        COALESCE(SUM(CASE WHEN f.stat_date BETWEEN {$last7StartQuoted} AND {$todayQuoted} THEN f.hit_count ELSE 0 END),0) AS last7_hits,
        COALESCE(SUM(CASE WHEN f.stat_date BETWEEN {$last30StartQuoted} AND {$todayQuoted} THEN f.hit_count ELSE 0 END),0) AS last30_hits,
        COALESCE(SUM(f.hit_count),0) AS total_hits,
        COUNT(DISTINCT CASE WHEN {$periodCondition} AND f.hit_count>0 THEN f.bucket_id END) AS bucket_count,
        COUNT(DISTINCT CASE WHEN {$periodCondition} AND f.hit_count>0 THEN f.app_id END) AS app_count,
        COUNT(DISTINCT CASE WHEN f.stat_date BETWEEN {$last7StartQuoted} AND {$todayQuoted} AND f.hit_count>0 THEN f.bucket_id END) AS last7_bucket_count,
        COUNT(DISTINCT CASE WHEN f.stat_date BETWEEN {$last7StartQuoted} AND {$todayQuoted} AND f.hit_count>0 THEN f.app_id END) AS last7_app_count,
        COUNT(DISTINCT f.bucket_id) AS total_bucket_count,
        COUNT(DISTINCT f.app_id) AS total_app_count,
        DATE_FORMAT(DATE_ADD(MAX(CASE WHEN {$periodCondition} THEN f.last_seen_at END), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS period_last_seen_at,
        DATE_FORMAT(DATE_ADD(MAX(f.last_seen_at), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS last_seen_at
        FROM cainiao_s3_bucket_file_stats f
        INNER JOIN cainiao_s3_bucket valid_bucket ON valid_bucket.id=f.bucket_id{$fileWhere}";
    $summary = $pdo->query($summarySql)->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary['range_hits'] = $summary['period_hits'] ?? 0;
    $summary['hits'] = $summary['period_hits'] ?? 0;
    $summary = bucketStatsNormalizeMetricRow($summary);
    $summary['period_last_seen_at'] = (string)($summary['period_last_seen_at'] ?? '');

    $configuredStmt = $pdo->prepare("SELECT COUNT(*) AS configured_bucket_count,
        COALESCE(SUM(CASE WHEN enabled=1 THEN 1 ELSE 0 END),0) AS active_bucket_count
        FROM cainiao_s3_bucket");
    $configuredStmt->execute();
    $configured = $configuredStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary['configured_bucket_count'] = (int)($configured['configured_bucket_count'] ?? 0);
    $summary['active_bucket_count'] = (int)($configured['active_bucket_count'] ?? 0);

    if ($summaryOnly) {
        $result = [
            'period' => $period,
            'summary' => $summary,
            'summary_only' => 1,
            'notice' => '这里统计壳端成功应用配置后的回执，不是云存储控制台的原始 HTTP 请求数；累计为全部时间，与所选区间分开。',
        ];
        if ($ownsTransaction && !$pdo->commit()) throw new RuntimeException('提交统计读取快照失败');
        return $result;
    }

    $dailySql = "SELECT f.stat_date,
        SUM(f.hit_count) AS hits,
        COUNT(DISTINCT f.bucket_id) AS bucket_count,
        COUNT(DISTINCT f.app_id) AS app_count
        FROM cainiao_s3_bucket_file_stats f
        INNER JOIN cainiao_s3_bucket valid_bucket ON valid_bucket.id=f.bucket_id
        " . ($fileWhere !== '' ? $fileWhere . ' AND ' : ' WHERE ') . "{$periodCondition}
        GROUP BY f.stat_date
        ORDER BY f.stat_date ASC";
    $dailyMap = [];
    foreach ($pdo->query($dailySql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dailyMap[(string)$row['stat_date']] = bucketStatsNormalizeMetricRow($row);
    }
    $daily = [];
    $cursor = new DateTimeImmutable($startDate, $timezone);
    $last = new DateTimeImmutable($endDate, $timezone);
    while ($cursor <= $last) {
        $date = $cursor->format('Y-m-d');
        $row = $dailyMap[$date] ?? ['hits' => 0, 'bucket_count' => 0, 'app_count' => 0];
        $daily[] = [
            'date' => $date,
            'stat_date' => $date,
            'hits' => (int)($row['hits'] ?? 0),
            'bucket_count' => (int)($row['bucket_count'] ?? 0),
            'app_count' => (int)($row['app_count'] ?? 0),
        ];
        $cursor = $cursor->modify('+1 day');
    }

    // 桶维度从桶主表出发，因此新桶或当前区间为 0 的桶也会出现在明细中。
    $bucketJoinFilter = $appId > 0 ? ' AND f.app_id=' . $appId : '';
    $bucketWhere = $bucketId > 0 ? ' WHERE b.id=' . $bucketId : '';
    $byBucketSql = "SELECT b.id AS bucket_id, b.name AS bucket_name, b.provider, b.enabled, b.inject,
        COALESCE(SUM(CASE WHEN {$periodCondition} THEN f.hit_count ELSE 0 END),0) AS period_hits,
        COALESCE(SUM(CASE WHEN f.stat_date={$todayQuoted} THEN f.hit_count ELSE 0 END),0) AS today_hits,
        COALESCE(SUM(CASE WHEN f.stat_date={$yesterdayQuoted} THEN f.hit_count ELSE 0 END),0) AS yesterday_hits,
        COALESCE(SUM(f.hit_count),0) AS total_hits,
        COUNT(DISTINCT CASE WHEN {$periodCondition} AND f.hit_count>0 THEN f.app_id END) AS app_count,
        DATE_FORMAT(DATE_ADD(MAX(CASE WHEN {$periodCondition} THEN f.last_seen_at END), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS period_last_seen_at,
        DATE_FORMAT(DATE_ADD(MAX(f.last_seen_at), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS last_seen_at
        FROM cainiao_s3_bucket b
        LEFT JOIN cainiao_s3_bucket_file_stats f ON f.bucket_id=b.id{$bucketJoinFilter}
        {$bucketWhere}
        GROUP BY b.id,b.name,b.provider,b.enabled,b.inject
        ORDER BY period_hits DESC,total_hits DESC,b.id ASC";
    $byBucket = [];
    foreach ($pdo->query($byBucketSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['bucket_id'] = (int)$row['bucket_id'];
        $row['id'] = (int)$row['bucket_id'];
        $row['name'] = (string)$row['bucket_name'];
        $row['provider'] = strtolower((string)$row['provider']);
        $row['provider_label'] = bucketProviderLabel($row['provider']);
        $row['enabled'] = (int)$row['enabled'];
        $row['inject'] = (int)$row['inject'];
        $row['hits'] = $row['period_hits'];
        $row['range_hits'] = $row['period_hits'];
        $row = bucketStatsNormalizeMetricRow($row);
        $row['period_last_seen_at'] = (string)($row['period_last_seen_at'] ?? '');
        $byBucket[] = $row;
    }

    $byAppSql = "SELECT f.app_id,
        COALESCE(NULLIF(a.name,''), CONCAT('应用 #',f.app_id)) AS app_name,
        COALESCE(a.package,'') AS package_name,
        COALESCE(SUM(CASE WHEN {$periodCondition} THEN f.hit_count ELSE 0 END),0) AS period_hits,
        COALESCE(SUM(CASE WHEN f.stat_date={$todayQuoted} THEN f.hit_count ELSE 0 END),0) AS today_hits,
        COALESCE(SUM(CASE WHEN f.stat_date={$yesterdayQuoted} THEN f.hit_count ELSE 0 END),0) AS yesterday_hits,
        COALESCE(SUM(f.hit_count),0) AS total_hits,
        COUNT(DISTINCT CASE WHEN {$periodCondition} AND f.hit_count>0 THEN f.bucket_id END) AS bucket_count,
        DATE_FORMAT(DATE_ADD(MAX(CASE WHEN {$periodCondition} THEN f.last_seen_at END), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS period_last_seen_at,
        DATE_FORMAT(DATE_ADD(MAX(f.last_seen_at), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS last_seen_at
        FROM cainiao_s3_bucket_file_stats f
        INNER JOIN cainiao_s3_bucket valid_bucket ON valid_bucket.id=f.bucket_id
        LEFT JOIN cainiao_apk a ON a.id=f.app_id
        {$fileWhere}
        GROUP BY f.app_id,a.name,a.package
        HAVING period_hits>0
        ORDER BY period_hits DESC,total_hits DESC,f.app_id ASC";
    $byApp = [];
    foreach ($pdo->query($byAppSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['app_id'] = (int)$row['app_id'];
        $row['id'] = (int)$row['app_id'];
        $row['name'] = (string)$row['app_name'];
        $row['package'] = (string)$row['package_name'];
        $row['hits'] = $row['period_hits'];
        $row['range_hits'] = $row['period_hits'];
        $row = bucketStatsNormalizeMetricRow($row);
        $row['period_last_seen_at'] = (string)($row['period_last_seen_at'] ?? '');
        $byApp[] = $row;
    }

    $bucketAppsSql = "SELECT f.bucket_id, b.name AS bucket_name, b.provider, f.app_id,
        COALESCE(NULLIF(a.name,''), CONCAT('应用 #',f.app_id)) AS app_name,
        COALESCE(a.package,'') AS package_name,
        COALESCE(SUM(CASE WHEN {$periodCondition} THEN f.hit_count ELSE 0 END),0) AS period_hits,
        COALESCE(SUM(CASE WHEN f.stat_date={$todayQuoted} THEN f.hit_count ELSE 0 END),0) AS today_hits,
        COALESCE(SUM(CASE WHEN f.stat_date={$yesterdayQuoted} THEN f.hit_count ELSE 0 END),0) AS yesterday_hits,
        COALESCE(SUM(f.hit_count),0) AS total_hits,
        DATE_FORMAT(DATE_ADD(MAX(CASE WHEN {$periodCondition} THEN f.last_seen_at END), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS period_last_seen_at,
        DATE_FORMAT(DATE_ADD(MAX(f.last_seen_at), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS last_seen_at
        FROM cainiao_s3_bucket_file_stats f
        INNER JOIN cainiao_s3_bucket b ON b.id=f.bucket_id
        LEFT JOIN cainiao_apk a ON a.id=f.app_id
        {$fileWhere}
        GROUP BY f.bucket_id,b.name,b.provider,f.app_id,a.name,a.package
        HAVING period_hits>0
        ORDER BY period_hits DESC,total_hits DESC,f.bucket_id ASC,f.app_id ASC";
    $bucketApps = [];
    foreach ($pdo->query($bucketAppsSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['bucket_id'] = (int)$row['bucket_id'];
        $row['app_id'] = (int)$row['app_id'];
        $row['provider'] = strtolower((string)$row['provider']);
        $row['provider_label'] = bucketProviderLabel($row['provider']);
        $row['package'] = (string)$row['package_name'];
        $row['hits'] = $row['period_hits'];
        $row['range_hits'] = $row['period_hits'];
        $row = bucketStatsNormalizeMetricRow($row);
        $row['period_last_seen_at'] = (string)($row['period_last_seen_at'] ?? '');
        $bucketApps[] = $row;
    }

    $bucketOptions = [];
    $optionRows = $pdo->query('SELECT id,name,provider,enabled,inject FROM cainiao_s3_bucket ORDER BY id ASC')
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($optionRows as $row) {
        $provider = strtolower((string)$row['provider']);
        $bucketOptions[] = [
            'id' => (int)$row['id'],
            'bucket_id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'bucket_name' => (string)$row['name'],
            'provider' => $provider,
            'provider_label' => bucketProviderLabel($provider),
            'enabled' => (int)$row['enabled'],
            'inject' => (int)$row['inject'],
        ];
    }

    // 应用选项基于已经产生回执的 APPID，同时保留已删除或历史 APPID 的可查性。
    $appOptionsSql = "SELECT f.app_id,
        COALESCE(NULLIF(a.name,''), CONCAT('应用 #',f.app_id)) AS app_name,
        COALESCE(a.package,'') AS package_name,
        SUM(f.hit_count) AS total_hits,
        DATE_FORMAT(DATE_ADD(MAX(f.last_seen_at), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS last_seen_at
        FROM cainiao_s3_bucket_file_stats f
        LEFT JOIN cainiao_apk a ON a.id=f.app_id
        GROUP BY f.app_id,a.name,a.package
        ORDER BY MAX(f.last_seen_at) DESC,f.app_id DESC";
    $appOptions = [];
    foreach ($pdo->query($appOptionsSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $appOptions[] = [
            'id' => (int)$row['app_id'],
            'app_id' => (int)$row['app_id'],
            'name' => (string)$row['app_name'],
            'app_name' => (string)$row['app_name'],
            'package' => (string)$row['package_name'],
            'package_name' => (string)$row['package_name'],
            'total_hits' => (int)$row['total_hits'],
            'last_seen_at' => (string)($row['last_seen_at'] ?? ''),
        ];
    }

    $presets = [
        'today' => ['label' => '今天', 'start_date' => $today, 'end_date' => $today],
        'yesterday' => ['label' => '昨天', 'start_date' => $yesterday, 'end_date' => $yesterday],
        'last7' => ['label' => '近7天', 'start_date' => $last7Start, 'end_date' => $today],
        'last30' => ['label' => '近30天', 'start_date' => $last30Start, 'end_date' => $today],
    ];
    $filters = [
        'bucket_id' => $bucketId,
        'app_id' => $appId,
        'buckets' => $bucketOptions,
        'apps' => $appOptions,
        'presets' => $presets,
    ];

    $sumMetric = static function (array $rows, string $field): int {
        $total = 0;
        foreach ($rows as $row) $total += (int)($row[$field] ?? 0);
        return $total;
    };
    $dimensionTotals = [
        'summary_period_hits' => (int)$summary['period_hits'],
        'daily_period_hits' => $sumMetric($daily, 'hits'),
        'bucket_period_hits' => $sumMetric($byBucket, 'period_hits'),
        'app_period_hits' => $sumMetric($byApp, 'period_hits'),
        'matrix_period_hits' => $sumMetric($bucketApps, 'period_hits'),
    ];
    $periodValues = array_values($dimensionTotals);
    $consistency = [
        'matched' => count(array_unique($periodValues, SORT_REGULAR)) === 1 ? 1 : 0,
        'totals' => $dimensionTotals,
        'scope' => '所选日期区间',
    ];
    if ($consistency['matched'] !== 1) {
        throw new RuntimeException('配置桶统计维度汇总不一致');
    }

    $result = [
        'period' => $period,
        'summary' => $summary,
        'filters' => $filters,
        'daily' => $daily,
        'by_bucket' => $byBucket,
        'by_app' => $byApp,
        'bucket_apps' => $bucketApps,
        'consistency' => $consistency,
        // 保留直观的通用别名，便于其他管理页直接复用该合同。
        'buckets' => $byBucket,
        'apps' => $byApp,
        'details' => $bucketApps,
        'options' => $filters,
        'notice' => '这里统计壳端成功应用配置后的回执，不是云存储控制台的原始 HTTP 请求数；累计为全部时间，与所选区间分开。',
    ];
    if ($ownsTransaction && !$pdo->commit()) throw new RuntimeException('提交统计读取快照失败');
    return $result;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $ignored) {
                // 保留导致读取失败的原始异常。
            }
        }
        throw $e;
    }
}

/** 获取单个桶的非敏感详情；编辑页不会一次性得到四项原始凭据。 */
function getBucket(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    return bucketSanitizeRow(bucketFindById($pdo, (int)($input['id'] ?? 0)), true);
}

/** 管理员点击眼睛时一次只查看一个敏感字段。 */
function revealBucketCredential(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $fields = [
        'account' => 'login_account',
        'login_account' => 'login_account',
        'password' => 'login_password',
        'login_password' => 'login_password',
        'access_key' => 'access_key',
        'secret_key' => 'secret_key',
    ];
    $requested = strtolower(trim((string)($input['field'] ?? '')));
    if (!isset($fields[$requested])) throw new InvalidArgumentException('敏感字段类型错误');
    $row = bucketFindById($pdo, (int)($input['id'] ?? 0));
    $column = $fields[$requested];
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header('Expires: 0');
    return ['field' => $requested, 'value' => bucketDecryptSecret((string)($row[$column] ?? ''))];
}

/** 新增桶并用 AES-256-GCM 保存四项敏感字段。 */
function addBucket(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $record = bucketNormalizeRecord($input);
    $stmt = $pdo->prepare("INSERT INTO cainiao_s3_bucket
        (name, provider, login_account, login_password, note, access_key, secret_key,
         endpoint, bucket, region, domain, enabled, inject)
        VALUES (:name, :provider, :login_account, :login_password, :note, :access_key, :secret_key,
         :endpoint, :bucket, :region, :domain, :enabled, :inject)");
    $stmt->execute([
        ':name' => $record['name'],
        ':provider' => $record['provider'],
        ':login_account' => bucketEncryptSecret($record['login_account']),
        ':login_password' => bucketEncryptSecret($record['login_password']),
        ':note' => $record['note'],
        ':access_key' => bucketEncryptSecret($record['access_key']),
        ':secret_key' => bucketEncryptSecret($record['secret_key']),
        ':endpoint' => $record['endpoint'],
        ':bucket' => $record['bucket'],
        ':region' => $record['region'],
        ':domain' => $record['domain'],
        ':enabled' => $record['enabled'],
        ':inject' => $record['inject'],
    ]);
    $id = (int)$pdo->lastInsertId();
    $scheduled = bucketScheduleFullSync($pdo, bucketSyncConnectionReason('新增配置桶连接', $record, $id));
    return [
        'message' => $scheduled
            ? '存储桶已新增，已启动后台同步'
            : '存储桶已新增，后台同步未启动，请使用“同步全部配置”',
        'id' => $id,
        'sync_scheduled' => $scheduled ? 1 : 0,
        'sync_job' => bucketReadSyncStateSafe($pdo),
    ];
}

/** 更新桶；未填写的敏感字段保持原值，真实新值会重新加密。 */
function updateBucket(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $id = (int)($input['id'] ?? 0);
    $existing = bucketFindById($pdo, $id);
    $record = bucketNormalizeRecord($input, $existing);
    $stmt = $pdo->prepare("UPDATE cainiao_s3_bucket SET
        name=:name, provider=:provider, login_account=:login_account, login_password=:login_password,
        note=:note, access_key=:access_key, secret_key=:secret_key, endpoint=:endpoint,
        bucket=:bucket, region=:region, domain=:domain, enabled=:enabled, inject=:inject
        WHERE id=:id");
    $stmt->execute([
        ':id' => $id,
        ':name' => $record['name'],
        ':provider' => $record['provider'],
        ':login_account' => bucketEncryptSecret($record['login_account']),
        ':login_password' => bucketEncryptSecret($record['login_password']),
        ':note' => $record['note'],
        ':access_key' => bucketEncryptSecret($record['access_key']),
        ':secret_key' => bucketEncryptSecret($record['secret_key']),
        ':endpoint' => $record['endpoint'],
        ':bucket' => $record['bucket'],
        ':region' => $record['region'],
        ':domain' => $record['domain'],
        ':enabled' => $record['enabled'],
        ':inject' => $record['inject'],
    ]);
    $scheduled = bucketScheduleFullSync($pdo, bucketSyncConnectionReason('更新配置桶连接', $record, $id));
    return [
        'message' => $scheduled
            ? '存储桶已更新，已启动后台同步'
            : '存储桶已更新，后台同步未启动，请使用“同步全部配置”',
        'sync_scheduled' => $scheduled ? 1 : 0,
        'sync_job' => bucketReadSyncStateSafe($pdo),
    ];
}

/**
 * 原子更新单个开关，避免列表快捷操作用旧快照覆盖并发的资料编辑。
 */
function setBucketStatus(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $id = (int)($input['id'] ?? 0);
    $existing = bucketFindById($pdo, $id);
    $field = strtolower(trim((string)($input['field'] ?? '')));
    if (!in_array($field, ['enabled', 'inject'], true)) {
        throw new InvalidArgumentException('桶开关字段错误');
    }
    $value = (int)($input['value'] ?? 0) === 1 ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE cainiao_s3_bucket SET `{$field}`=:value WHERE id=:id");
    $stmt->execute([':value' => $value, ':id' => $id]);

    $scheduled = $field === 'enabled'
        ? bucketScheduleFullSync($pdo, bucketSyncConnectionReason('更新配置桶推送开关', $existing, $id))
        : false;
    return [
        'message' => $field === 'enabled'
            ? ($scheduled
                ? '推送开关已更新，已启动后台同步'
                : '推送开关已更新，后台同步未启动')
            : 'APK 注入开关已更新',
        'field' => $field,
        'value' => $value,
        'sync_scheduled' => $field === 'enabled' ? ($scheduled ? 1 : 0) : null,
        'sync_job' => $field === 'enabled' ? bucketReadSyncStateSafe($pdo) : null,
    ];
}

/** 删除管理记录与本系统聚合统计；云端 Bucket 和对象保持原状。 */
function deleteBucket(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $id = (int)($input['id'] ?? 0);
    bucketFindById($pdo, $id);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM cainiao_s3_bucket_stats WHERE bucket_id=:id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM cainiao_s3_bucket_file_stats WHERE bucket_id=:id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM cainiao_s3_bucket WHERE id=:id')->execute([':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    // 删除启用桶会改变所有应用的目标桶集合，事务提交后排队一次全量同步，
    // 让旧桶上的配置快照尽快收敛；云端 Bucket 与已有文件仍按原合同保留。
    $scheduled = bucketScheduleFullSync($pdo, bucketSyncConnectionReason('删除配置桶管理记录', $row, $id));
    return [
        'message' => $scheduled
            ? '存储桶管理记录已删除，已启动后台同步；云端 Bucket 和已有文件保持原状'
            : '存储桶管理记录已删除，后台同步未启动；云端 Bucket 和已有文件保持原状',
        'id' => $id,
        'sync_scheduled' => $scheduled ? 1 : 0,
        'sync_job' => bucketReadSyncStateSafe($pdo),
    ];
}

/** 测试已保存桶或保存前表单；测试对象使用随机名并在成功后立即清理。 */
function testBucket(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $savedId = (int)($input['id'] ?? 0);
    if ($savedId > 0) {
        $bucket = bucketFindById($pdo, $savedId);
    } else {
        $record = bucketNormalizeRecord($input);
        $bucket = $record;
    }

    $client = new S3Client(
        $bucket['access_key'],
        $bucket['secret_key'],
        $bucket['endpoint'],
        $bucket['bucket'],
        $bucket['region'] ?: 'auto'
    );
    $objectKey = '_test/yunzhuru-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.txt';
    $result = $client->putObject($objectKey, 'ok ' . gmdate('c'), 'text/plain; charset=utf-8');
    if ((int)($result['code'] ?? 500) !== 200) {
        throw new RuntimeException('连接失败：' . ($result['message'] ?? '未知错误'));
    }

    $cleanup = $client->deleteObject($objectKey);
    $cleanupOk = (int)($cleanup['code'] ?? 500) === 200;
    return [
        'message' => $cleanupOk ? '连接成功，测试对象已清理' : '连接成功；测试对象清理失败，请在桶内手工清理',
        'object_key' => $objectKey,
        'cleanup_ok' => $cleanupOk ? 1 : 0,
    ];
}

/**
 * 读取真实对象清单并与当前应该推送的应用集合对账，同时附加每个 APPID 的命中回执统计。
 */
function getBucketFiles(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $bucket = bucketFindById($pdo, (int)($input['id'] ?? 0));
    $client = new S3Client(
        $bucket['access_key'],
        $bucket['secret_key'],
        $bucket['endpoint'],
        $bucket['bucket'],
        $bucket['region'] ?: 'auto'
    );
    $listed = $client->listObjectsV2('config/');
    if ((int)($listed['code'] ?? 500) !== 200) {
        throw new RuntimeException('读取桶文件失败：' . ($listed['message'] ?? '未知错误'));
    }

    $expected = bucketExpectedApps($pdo, $bucket);
    $fileStats = bucketFileStatsMap($pdo, (int)$bucket['id']);
    $emptyStats = ['total_hits' => 0, 'today_hits' => 0, 'last_seen_at' => ''];
    $actualKeys = [];
    $files = [];
    foreach (($listed['objects'] ?? []) as $object) {
        $key = (string)($object['key'] ?? '');
        $actualKeys[$key] = true;
        $appId = 0;
        if (preg_match('#^config/(\d+)\.enc$#', $key, $match)) $appId = (int)$match[1];
        $expectedItem = $appId > 0 && isset($expected[$appId]) ? $expected[$appId] : null;
        $files[] = array_merge($object, [
            'present' => 1,
            'expected' => $expectedItem ? 1 : 0,
            'app_id' => $appId,
            'app_name' => $expectedItem['app_name'] ?? ($appId > 0 ? '历史或未登记应用' : '其他对象'),
            'package_name' => $expectedItem['package_name'] ?? '',
            'file_scope' => $appId > 0 ? ($expectedItem ? 'app' : 'history') : 'other',
            'stats' => $appId > 0 ? ($fileStats[$appId] ?? $emptyStats) : $emptyStats,
            'public_url' => bucketPublicObjectUrl((string)$bucket['domain'], $key),
        ]);
    }
    usort($files, static function (array $a, array $b): int {
        if ((int)$a['expected'] !== (int)$b['expected']) return (int)$b['expected'] <=> (int)$a['expected'];
        return strcmp((string)$a['key'], (string)$b['key']);
    });

    $missing = [];
    foreach ($expected as $appId => $item) {
        if (isset($actualKeys[$item['key']])) continue;
        $missing[] = array_merge($item, [
            'present' => 0,
            'expected' => 1,
            'size' => 0,
            'last_modified' => '',
            'etag' => '',
            'storage_class' => '',
            'file_scope' => 'app',
            'stats' => $fileStats[$appId] ?? $emptyStats,
            'public_url' => bucketPublicObjectUrl((string)$bucket['domain'], $item['key']),
        ]);
    }

    $checkUrl = '';
    foreach ($files as $file) {
        if (!empty($file['expected'])) {
            $checkUrl = (string)$file['public_url'];
            break;
        }
    }
    if ($checkUrl === '' && !empty($files)) $checkUrl = (string)$files[0]['public_url'];

    $totalSize = 0;
    $historyCount = 0;
    $otherCount = 0;
    foreach ($files as $file) {
        $totalSize += (int)($file['size'] ?? 0);
        if (($file['file_scope'] ?? '') === 'history') $historyCount++;
        if (($file['file_scope'] ?? '') === 'other') $otherCount++;
    }

    return [
        'bucket' => bucketSanitizeRow($bucket),
        'summary' => [
            'expected_count' => count($expected),
            'actual_count' => count($files),
            'missing_count' => count($missing),
            'history_count' => $historyCount,
            'other_count' => $otherCount,
            'total_size' => $totalSize,
            'truncated' => !empty($listed['truncated']) ? 1 : 0,
            'pages' => (int)($listed['pages'] ?? 1),
        ],
        'public_check' => bucketCheckPublicObject($checkUrl),
        'files' => $files,
        'missing_files' => $missing,
    ];
}

/**
 * 读取右下角全局配置同步中心的持久快照。
 *
 * 该方法只返回状态表中的非敏感字段及摘要结果；桶凭据、对象正文和完整密钥
 * 永远不会通过此接口输出。前端可按 status=queued/running 高频轮询，终态降频。
 */
function getConfigSyncStatus(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $snapshot = configSyncStateRead($pdo);
    $snapshot['poll_after_ms'] = in_array((string)($snapshot['status'] ?? ''), ['queued', 'running'], true)
        ? 750 : 5000;
    $snapshotCode = in_array((string)($snapshot['status'] ?? 'idle'), ['partial_failure', 'partial', 'partial_success'], true)
        ? 207 : (in_array((string)($snapshot['status'] ?? 'idle'), ['queued', 'running'], true)
            ? 202 : ((string)($snapshot['status'] ?? 'idle') === 'failed' ? 500 : 200));
    return $snapshot + [
        'code' => 200,
        'result_code' => $snapshotCode,
    ];
}

/** 兼容同步中心旧版前端命名；与 getConfigSyncStatus 共用同一状态合同。 */
function getSyncStatus(PDO $pdo, array $input) {
    return getConfigSyncStatus($pdo, $input);
}

/**
 * 手工启动全局配置同步并立即返回排队快照。
 *
 * 实际写入由独立 worker 执行，避免同步中心按钮长时间占用管理请求；重复点击
 * 会加入当前排队/运行中的代次，worker 通过 job_id CAS 忽略旧任务的晚到状态写入。
 */
function startConfigSync(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $before = configSyncStateRead($pdo);
    $alreadyActive = in_array((string)($before['status'] ?? ''), ['queued', 'running'], true);
    $scheduled = bucketScheduleFullSync($pdo, '管理后台手工同步');
    $snapshot = configSyncStateRead($pdo);
    $snapshot['sync_scheduled'] = $scheduled ? 1 : 0;
    $snapshot['started'] = $scheduled && !$alreadyActive ? 1 : 0;
    $snapshot['joined'] = $scheduled && $alreadyActive ? 1 : 0;
    $snapshot['poll_after_ms'] = $scheduled ? 750 : 5000;
    return $snapshot;
}

/** 兼容同步中心旧版启动命名。 */
function startSync(PDO $pdo, array $input) {
    return startConfigSync($pdo, $input);
}

/** 一键同步所有有成功注入记录的应用配置（保留同步返回合同并更新全局状态）。 */
function pushAllConfigs(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    require_once __DIR__ . '/../utils/BucketPush.php';
    $queued = configSyncStateMarkQueued($pdo, '管理后台同步全部配置');
    $jobId = (string)($queued['job_id'] ?? '');
    configSyncStateMarkRunning($pdo, $jobId);
    try {
        $result = pushAllConfigsToBuckets($pdo);
        $finished = configSyncStateMarkFinished($pdo, is_array($result) ? $result : [], $jobId);
        if (is_array($result)) $result['sync_job'] = $finished;
        return is_array($result) ? $result : ['message' => '同步完成', 'sync_job' => $finished];
    } catch (Throwable $e) {
        try {
            $failedSnapshot = configSyncStateRead($pdo);
            // 保留异步 worker 已经写入的 APP/桶明细，异常响应只补充错误信息，
            // 避免终态把此前成功的 B2 对象覆盖掉。
            $failureResult = is_array($failedSnapshot['result'] ?? null)
                ? $failedSnapshot['result'] : [];
            $failureResult['total'] = (int)($failedSnapshot['expected_total'] ?? 0);
            $failureResult['success'] = (int)($failedSnapshot['success'] ?? 0);
            $failureResult['fail'] = max(1, (int)($failedSnapshot['fail'] ?? 0));
            $failureResult['message'] = $e->getMessage();
            configSyncStateMarkFinished($pdo, $failureResult, $jobId, $e);
        } catch (Throwable $ignored) {
            // 状态固化失败不覆盖真实同步异常。
        }
        throw $e;
    }
}

/** 注入器读取可写入新 APK 的桶公开域名。 */
function getBucketDomains(PDO $pdo, array $input) {
    Auth::check($pdo);
    ensureBucketFeatureSchema($pdo);
    $rows = $pdo->query('SELECT id, name, domain FROM cainiao_s3_bucket WHERE inject=1 ORDER BY id ASC')
        ->fetchAll(PDO::FETCH_ASSOC);
    return ['domains' => $rows];
}

/**
 * 新版壳配置桶命中回执。
 *
 * 只有 APPID、归属用户和注入时生成的 password_hash 密钥均匹配，且域名能归属到已登记桶时
 * 才会写入北京时间日聚合。该接口不要求后台 Cookie，失败也不影响壳端配置主链路。
 */
function recordBucketHit(PDO $pdo, array $input) {
    $appId = (int)($input['app_id'] ?? 0);
    $appKey = (int)($input['app_key'] ?? 0);
    $key = (string)($input['key'] ?? '');
    $domain = rtrim(trim((string)($input['bucket_domain'] ?? '')), '/');
    $packageName = trim((string)($input['package_name'] ?? ''));
    $shellVersion = (int)($input['shell_version'] ?? 0);
    if ($appId <= 0 || $appKey <= 0 || $key === '' || $domain === '' || $packageName === '' || $shellVersion < 152) {
        throw new InvalidArgumentException('桶命中回执参数不完整');
    }

    $stmt = $pdo->prepare("SELECT a.id, a.user_id, a.package
        FROM cainiao_apk a
        INNER JOIN cainiao_apk_config c ON c.apk_id = a.id
        INNER JOIN cainiao_inject_task t ON t.apk_id = a.id AND t.status_text = '编译成功'
        LEFT JOIN cainiao_apk_deleted d ON d.apk_id = a.id
        WHERE a.id=:app_id AND a.user_id=:user_id AND d.apk_id IS NULL
        LIMIT 1");
    $stmt->execute([':app_id' => $appId, ':user_id' => $appKey]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) throw new RuntimeException('应用未登记或已失效');
    if (!hash_equals((string)($app['package'] ?? ''), $packageName)) {
        throw new RuntimeException('应用包名不匹配');
    }

    $plain = (string)$appId . (string)$appKey . md5((string)$appId . (string)$appKey);
    if (!password_verify($plain, $key)) throw new RuntimeException('应用回执密钥不匹配');

    $bucketId = 0;
    $rows = $pdo->query('SELECT id, domain FROM cainiao_s3_bucket')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (hash_equals(rtrim(trim((string)$row['domain']), '/'), $domain)) {
            $bucketId = (int)$row['id'];
            break;
        }
    }
    if ($bucketId <= 0) throw new RuntimeException('桶域名未登记');

    $okRaw = $input['ok'] ?? true;
    $ok = !($okRaw === false || $okRaw === 0 || $okRaw === '0' || strtolower((string)$okRaw) === 'false');
    if (!$ok) throw new InvalidArgumentException('仅接收已成功应用配置的桶命中回执');
    $today = bucketFeatureToday();
    $ownsWriteTransaction = !$pdo->inTransaction();
    $savepointStarted = false;
    $savepoint = '';
    try {
        if ($ownsWriteTransaction) {
            if (!$pdo->beginTransaction()) throw new RuntimeException('建立桶命中写入事务失败');
        } else {
            // 已处于上层事务时使用独立 savepoint，失败只撤销本函数的两次计数。
            $savepoint = 'bucket_hit_' . bin2hex(random_bytes(8));
            $pdo->exec('SAVEPOINT ' . $savepoint);
            $savepointStarted = true;
        }

        // 锁定归属桶到两张统计表写入完成，避免与删除桶并发产生孤立统计。
        $bucketLock = $pdo->prepare('SELECT id FROM cainiao_s3_bucket WHERE id=:bucket_id FOR UPDATE');
        $bucketLock->execute([':bucket_id' => $bucketId]);
        if (!$bucketLock->fetchColumn()) throw new RuntimeException('桶域名未登记');

        $stat = $pdo->prepare("INSERT INTO cainiao_s3_bucket_stats
            (bucket_id, stat_date, hit_count, ok_count, fail_count, last_seen_at)
            VALUES (:bucket_id, :stat_date, 1, :ok_count, :fail_count, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                hit_count=hit_count+1,
                ok_count=ok_count+VALUES(ok_count),
                fail_count=fail_count+VALUES(fail_count),
                last_seen_at=UTC_TIMESTAMP()");
        $stat->execute([
            ':bucket_id' => $bucketId,
            ':stat_date' => $today,
            ':ok_count' => 1,
            ':fail_count' => 0,
        ]);

        $fileStat = $pdo->prepare("INSERT INTO cainiao_s3_bucket_file_stats
            (bucket_id, app_id, stat_date, hit_count, last_seen_at)
            VALUES (:bucket_id, :app_id, :stat_date, 1, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE hit_count=hit_count+1, last_seen_at=UTC_TIMESTAMP()");
        $fileStat->execute([':bucket_id' => $bucketId, ':app_id' => $appId, ':stat_date' => $today]);

        if ($ownsWriteTransaction) {
            if (!$pdo->commit()) throw new RuntimeException('提交桶命中写入事务失败');
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            $savepointStarted = false;
        }
    } catch (Throwable $e) {
        if ($ownsWriteTransaction && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $ignored) {
                // 保留原始写入异常。
            }
        } elseif ($savepointStarted) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } catch (Throwable $ignored) {
                // 保留原始写入异常，由上层决定外层事务的最终去向。
            }
        }
        throw $e;
    }

    return ['message' => 'ok', 'bucket_id' => $bucketId];
}
