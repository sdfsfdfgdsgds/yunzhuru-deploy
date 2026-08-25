<?php

/**
 * 配置分发管理 API。
 *
 * 管理端方法都要求 admin Cookie；recordNetworkPath() 是新壳的公开回执入口，
 * 通过 APPID、归属用户、包名和注入密钥联合校验，不依赖后台登录态。
 */

/** 统一校验管理员身份。 */
function configDeliveryRequireAdmin(PDO $pdo): array
{
    $user = Auth::check($pdo);
    if (($user['role'] ?? '') !== 'admin') {
        throw new RuntimeException('无权限');
    }
    return $user;
}

/** 校验可选的统计日期，默认最近 7 天，最长 366 天。 */
function configDeliveryDateRange(array $input): array
{
    $timezone = new DateTimeZone('Asia/Shanghai');
    $today = new DateTimeImmutable('today', $timezone);
    $defaultStart = $today->modify('-6 days');
    $parse = static function ($value, DateTimeImmutable $fallback) use ($timezone): DateTimeImmutable {
        $text = trim((string)$value);
        if ($text === '') return $fallback;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('日期格式必须为 YYYY-MM-DD');
        }
        return $date;
    };
    $start = $parse($input['start_date'] ?? '', $defaultStart);
    $end = $parse($input['end_date'] ?? '', $today);
    if ($start > $end) throw new InvalidArgumentException('开始日期不得晚于结束日期');
    if ((int)$start->diff($end)->format('%a') > 365) {
        throw new InvalidArgumentException('单次最多查询 366 天');
    }
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

/** 读取 API 域名池及日统计。 */
function configDeliveryDomainRows(PDO $pdo): array
{
    $today = configDeliveryToday();
    $sql = "SELECT p.*,
        COALESCE(SUM(CASE WHEN s.stat_date=" . $pdo->quote($today) . " THEN s.request_count ELSE 0 END),0) AS today_count,
        COALESCE(SUM(s.request_count),0) AS total_count,
        COALESCE(SUM(s.ok_count),0) AS ok_count,
        COALESCE(SUM(s.fail_count),0) AS fail_count,
        MAX(s.last_seen_at) AS last_seen_at
        FROM cainiao_api_domain_pool p
        LEFT JOIN cainiao_api_domain_stats s ON s.domain_pool_id=p.id
        GROUP BY p.id
        ORDER BY p.priority DESC, p.id ASC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** 为 DNS 路径页生成汇总和明细，所有数据都来自新壳回执。 */
function configDeliveryPathStats(PDO $pdo, array $input, bool $internalCall): array
{
    if (!$internalCall) throw new RuntimeException('路径统计只能由管理聚合接口读取');
    [$startDate, $endDate] = configDeliveryDateRange($input);
    $today = configDeliveryToday();
    $appId = max(0, (int)($input['app_id'] ?? 0));
    $where = ' WHERE stat_date BETWEEN :start_date AND :end_date';
    $rowWhere = ' WHERE s.stat_date BETWEEN :start_date AND :end_date';
    $params = [':start_date' => $startDate, ':end_date' => $endDate];
    if ($appId > 0) {
        $where .= ' AND app_id=:app_id';
        $rowWhere .= ' AND s.app_id=:app_id';
        $params[':app_id'] = $appId;
    }

    $todayQuoted = $pdo->quote($today);
    $summarySql = "SELECT dns_mode,
        SUM(request_count) AS range_count,
        SUM(CASE WHEN stat_date={$todayQuoted} THEN request_count ELSE 0 END) AS today_count,
        SUM(CASE WHEN stat_date={$todayQuoted} THEN ok_count ELSE 0 END) AS today_ok,
        SUM(CASE WHEN stat_date={$todayQuoted} THEN fail_count ELSE 0 END) AS today_fail,
        SUM(CASE WHEN stat_date >= DATE_SUB({$todayQuoted}, INTERVAL 6 DAY) THEN request_count ELSE 0 END) AS last7_count,
        SUM(rejected_count) AS rejected_count,
        SUM(rescued_count) AS rescued_count,
        MAX(last_seen_at) AS last_seen_at
        FROM cainiao_dns_path_stats {$where}
        GROUP BY dns_mode
        ORDER BY FIELD(dns_mode,'doh','udp','cache','system','unknown'), dns_mode";
    $stmt = $pdo->prepare($summarySql);
    $stmt->execute($params);
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rowsSql = "SELECT s.dns_mode, s.dns_provider, s.target_host, s.domain_pool_id,
        COALESCE(p.name, IF(s.domain_pool_id=0, '内置/STATIC', CONCAT('已删除 #',s.domain_pool_id))) AS domain_name,
        s.scope, s.app_id, s.package_name, s.carrier, s.network_type,
        SUM(s.request_count) AS range_count,
        SUM(CASE WHEN s.stat_date={$todayQuoted} THEN s.request_count ELSE 0 END) AS today_count,
        SUM(CASE WHEN s.stat_date={$todayQuoted} THEN s.ok_count ELSE 0 END) AS today_ok,
        SUM(CASE WHEN s.stat_date={$todayQuoted} THEN s.fail_count ELSE 0 END) AS today_fail,
        SUM(CASE WHEN s.stat_date >= DATE_SUB({$todayQuoted}, INTERVAL 6 DAY) THEN s.request_count ELSE 0 END) AS last7_count,
        SUM(s.rejected_count) AS rejected_count,
        SUM(s.rescued_count) AS rescued_count,
        MAX(s.last_seen_at) AS last_seen_at
        FROM cainiao_dns_path_stats s
        LEFT JOIN cainiao_api_domain_pool p ON p.id=s.domain_pool_id
        {$rowWhere}
        GROUP BY s.dns_mode,s.dns_provider,s.target_host,s.domain_pool_id,p.name,s.scope,
                 s.app_id,s.package_name,s.carrier,s.network_type
        ORDER BY today_count DESC, range_count DESC, last_seen_at DESC
        LIMIT 500";
    $stmt = $pdo->prepare($rowsSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalsSql = "SELECT
        COALESCE(SUM(request_count),0) AS range_count,
        COALESCE(SUM(CASE WHEN stat_date={$todayQuoted} THEN request_count ELSE 0 END),0) AS today_count,
        COALESCE(SUM(CASE WHEN stat_date={$todayQuoted} THEN ok_count ELSE 0 END),0) AS today_ok,
        COALESCE(SUM(CASE WHEN stat_date={$todayQuoted} THEN fail_count ELSE 0 END),0) AS today_fail,
        COALESCE(SUM(CASE WHEN stat_date >= DATE_SUB({$todayQuoted}, INTERVAL 6 DAY) THEN request_count ELSE 0 END),0) AS last7_count,
        COALESCE(SUM(rejected_count),0) AS rejected_count,
        COALESCE(SUM(rescued_count),0) AS rescued_count,
        MAX(last_seen_at) AS last_seen_at
        FROM cainiao_dns_path_stats {$where}";
    $stmt = $pdo->prepare($totalsSql);
    $stmt->execute($params);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'app_id' => $appId,
        'summary' => $summary,
        'rows' => $rows,
        'totals' => $totals,
        'notice' => '路径统计从壳版本 153 开始产生，旧壳不会补记。',
    ];
}

/** 返回统一页需要的所有配置分发数据。 */
function getConfigDelivery(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    ensureConfigDeliverySchema($pdo);
    $doh = $pdo->query('SELECT * FROM cainiao_doh_pool ORDER BY priority DESC,id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $dns = $pdo->query('SELECT * FROM cainiao_dns_pool ORDER BY priority DESC,id ASC')->fetchAll(PDO::FETCH_ASSOC);

    return [
        'dns_pool_enabled' => (int)Auth::getSetting($pdo, 'dns_pool', 0) === 1,
        'domains' => configDeliveryDomainRows($pdo),
        'doh' => $doh,
        'dns' => $dns,
        'dns_path_stats' => configDeliveryPathStats($pdo, $input, true),
        'presets' => [
            'doh' => configDeliveryDohPresets(),
            'dns' => configDeliveryDnsPresets(),
        ],
    ];
}

/** 新增或更新 API 域名池行。 */
function saveApiDomain(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    ensureConfigDeliverySchema($pdo);
    $id = max(0, (int)($input['id'] ?? 0));
    $row = [
        ':name' => configDeliveryNormalizeName($input['name'] ?? ''),
        ':base_url' => configDeliveryNormalizeApiUrl($input['base_url'] ?? ''),
        ':scope' => configDeliveryNormalizeScope($input['usage_scope'] ?? 'config'),
        ':enabled' => configDeliveryNormalizeEnabled($input['enabled'] ?? 1),
        ':priority' => configDeliveryNormalizePriority($input['priority'] ?? 0),
    ];
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE cainiao_api_domain_pool SET name=:name,base_url=:base_url,
                usage_scope=:scope,enabled=:enabled,priority=:priority WHERE id=:id');
            $stmt->execute($row + [':id' => $id]);
            $check = $pdo->prepare('SELECT id FROM cainiao_api_domain_pool WHERE id=:id');
            $check->execute([':id' => $id]);
            if (!$check->fetchColumn()) throw new RuntimeException('API 域名记录不存在');
        } else {
            $stmt = $pdo->prepare('INSERT INTO cainiao_api_domain_pool
                (name,base_url,usage_scope,enabled,priority) VALUES (:name,:base_url,:scope,:enabled,:priority)');
            $stmt->execute($row);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') throw new RuntimeException('该 API 地址和用途已存在');
        throw $e;
    }
    $invalidate = configDeliveryInvalidateAndSync($pdo);
    return ['message' => '保存成功', 'id' => $id, 'invalidate' => $invalidate];
}

/** 删除 API 域名池行；既有日统计保留用于历史对账。 */
function deleteApiDomain(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    ensureConfigDeliverySchema($pdo);
    $id = max(0, (int)($input['id'] ?? 0));
    if ($id <= 0) throw new InvalidArgumentException('缺少 API 域名 ID');
    $stmt = $pdo->prepare('DELETE FROM cainiao_api_domain_pool WHERE id=:id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() <= 0) throw new RuntimeException('API 域名记录不存在');
    return ['message' => '删除成功', 'invalidate' => configDeliveryInvalidateAndSync($pdo)];
}

/** DoH 保存公共实现。 */
function configDeliverySaveDohRow(PDO $pdo, array $input, bool $internalCall): int
{
    if (!$internalCall) throw new RuntimeException('DoH 内部写入方法不允许直接调用');
    $id = max(0, (int)($input['id'] ?? 0));
    $row = [
        ':name' => configDeliveryNormalizeName($input['name'] ?? ''),
        ':url' => configDeliveryNormalizeDohUrl($input['url'] ?? ''),
        ':enabled' => configDeliveryNormalizeEnabled($input['enabled'] ?? 1),
        ':priority' => configDeliveryNormalizePriority($input['priority'] ?? 0),
    ];
    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE cainiao_doh_pool SET name=:name,url=:url,enabled=:enabled,priority=:priority WHERE id=:id');
        $stmt->execute($row + [':id' => $id]);
        $check = $pdo->prepare('SELECT id FROM cainiao_doh_pool WHERE id=:id');
        $check->execute([':id' => $id]);
        if (!$check->fetchColumn()) throw new RuntimeException('DoH 记录不存在');
        return $id;
    }
    $stmt = $pdo->prepare('INSERT INTO cainiao_doh_pool (name,url,enabled,priority)
        VALUES (:name,:url,:enabled,:priority)');
    $stmt->execute($row);
    return (int)$pdo->lastInsertId();
}

/** 新增或更新 DoH 节点。 */
function saveDoh(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    ensureConfigDeliverySchema($pdo);
    try {
        $id = configDeliverySaveDohRow($pdo, $input, true);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') throw new RuntimeException('该 DoH URL 已存在');
        throw $e;
    }
    return ['message' => '保存成功', 'id' => $id, 'invalidate' => configDeliveryInvalidateAndSync($pdo)];
}

/** 删除 DoH 节点。 */
function deleteDoh(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    ensureConfigDeliverySchema($pdo);
    $id = max(0, (int)($input['id'] ?? 0));
    if ($id <= 0) throw new InvalidArgumentException('缺少 DoH ID');
    $stmt = $pdo->prepare('DELETE FROM cainiao_doh_pool WHERE id=:id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() <= 0) throw new RuntimeException('DoH 记录不存在');
    return ['message' => '删除成功', 'invalidate' => configDeliveryInvalidateAndSync($pdo)];
}

/** DNS 保存公共实现。 */
function configDeliverySaveDnsRow(PDO $pdo, array $input, bool $internalCall): int
{
    if (!$internalCall) throw new RuntimeException('DNS 内部写入方法不允许直接调用');
    $id = max(0, (int)($input['id'] ?? 0));
    $row = [
        ':name' => configDeliveryNormalizeName($input['name'] ?? ''),
        ':ip' => configDeliveryNormalizeDnsIp($input['ip'] ?? ''),
        ':enabled' => configDeliveryNormalizeEnabled($input['enabled'] ?? 1),
        ':priority' => configDeliveryNormalizePriority($input['priority'] ?? 0),
    ];
    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE cainiao_dns_pool SET name=:name,ip=:ip,enabled=:enabled,priority=:priority WHERE id=:id');
        $stmt->execute($row + [':id' => $id]);
        $check = $pdo->prepare('SELECT id FROM cainiao_dns_pool WHERE id=:id');
        $check->execute([':id' => $id]);
        if (!$check->fetchColumn()) throw new RuntimeException('DNS 记录不存在');
        return $id;
    }
    $stmt = $pdo->prepare('INSERT INTO cainiao_dns_pool (name,ip,enabled,priority)
        VALUES (:name,:ip,:enabled,:priority)');
    $stmt->execute($row);
    return (int)$pdo->lastInsertId();
}

/** 新增或更新 UDP DNS 节点。 */
function saveDns(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    ensureConfigDeliverySchema($pdo);
    try {
        $id = configDeliverySaveDnsRow($pdo, $input, true);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') throw new RuntimeException('该 DNS IP 已存在');
        throw $e;
    }
    return ['message' => '保存成功', 'id' => $id, 'invalidate' => configDeliveryInvalidateAndSync($pdo)];
}

/** 删除 UDP DNS 节点。 */
function deleteDns(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    ensureConfigDeliverySchema($pdo);
    $id = max(0, (int)($input['id'] ?? 0));
    if ($id <= 0) throw new InvalidArgumentException('缺少 DNS ID');
    $stmt = $pdo->prepare('DELETE FROM cainiao_dns_pool WHERE id=:id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() <= 0) throw new RuntimeException('DNS 记录不存在');
    return ['message' => '删除成功', 'invalidate' => configDeliveryInvalidateAndSync($pdo)];
}

/** 一次性导入并重新启用全部常用 DoH 或 DNS 节点。 */
function importCommon(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    ensureConfigDeliverySchema($pdo);
    $type = strtolower(trim((string)($input['type'] ?? '')));
    if (!in_array($type, ['doh', 'dns'], true)) {
        throw new InvalidArgumentException('导入类型只允许 doh 或 dns');
    }
    $count = 0;
    if ($type === 'doh') {
        $stmt = $pdo->prepare('INSERT INTO cainiao_doh_pool (name,url,enabled,priority)
            VALUES (:name,:value,1,:priority)
            ON DUPLICATE KEY UPDATE name=VALUES(name),enabled=1,priority=VALUES(priority)');
        $presets = configDeliveryDohPresets();
        $priority = count($presets) * 10;
        foreach ($presets as $row) {
            $stmt->execute([':name' => $row['name'], ':value' => $row['url'], ':priority' => $priority]);
            $priority -= 10;
            $count++;
        }
    } else {
        $stmt = $pdo->prepare('INSERT INTO cainiao_dns_pool (name,ip,enabled,priority)
            VALUES (:name,:value,1,:priority)
            ON DUPLICATE KEY UPDATE name=VALUES(name),enabled=1,priority=VALUES(priority)');
        $presets = configDeliveryDnsPresets();
        $priority = count($presets) * 10;
        foreach ($presets as $row) {
            $stmt->execute([':name' => $row['name'], ':value' => $row['ip'], ':priority' => $priority]);
            $priority -= 10;
            $count++;
        }
    }
    return ['message' => "已导入 {$count} 个常用节点", 'count' => $count, 'invalidate' => configDeliveryInvalidateAndSync($pdo)];
}

/** 在统一页直接维护原有 dns_pool 总开关。 */
function updateDnsPool(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $enabled = configDeliveryNormalizeEnabled($input['enabled'] ?? 0, 0);
    $stmt = $pdo->prepare("INSERT INTO cainiao_system_setting (key_name,key_value,title,note,type)
        VALUES ('dns_pool',:value,'抗污染解析（DoH+DNS池）',
        '开启后壳端优先使用后台 DoH/DNS 池，最终回退系统 DNS','switch')
        ON DUPLICATE KEY UPDATE key_value=VALUES(key_value),title=VALUES(title),note=VALUES(note),type=VALUES(type)");
    $stmt->execute([':value' => (string)$enabled]);
    return [
        'message' => $enabled ? '抗污染解析已开启' : '抗污染解析已关闭',
        'enabled' => (bool)$enabled,
        'invalidate' => configDeliveryInvalidateAndSync($pdo),
    ];
}

/** 预览指定 APPID 最终配置，或留空只预览全局分发字段。 */
function previewConfig(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    ensureConfigDeliverySchema($pdo);
    $appId = max(0, (int)($input['app_id'] ?? 0));
    $publicPools = configDeliveryPublicPools($pdo);
    $base = [
        'generated_at' => gmdate('c'),
        'dns_pool' => (int)Auth::getSetting($pdo, 'dns_pool', 0) === 1,
    ] + $publicPools;

    if ($appId <= 0) {
        $base['buckets'] = $pdo->query('SELECT domain FROM cainiao_s3_bucket WHERE enabled=1 ORDER BY id ASC')
            ->fetchAll(PDO::FETCH_COLUMN);
        if (function_exists('bucketPublicApiUrl')) $base['stat_url'] = bucketPublicApiUrl();
        return ['app_id' => 0, 'scope' => 'global', 'config' => $base];
    }

    $GLOBALS['pdo'] = $pdo;
    if (!function_exists('getResponseData')) {
        require_once __DIR__ . '/../utils/ConfigHelper.php';
    }
    $response = getResponseData($pdo, $appId, 'config_preview', false);
    if (!$response) throw new RuntimeException('应用不存在或尚无配置');
    // 预览不加入注入密钥，也不查询任何桶凭据。
    $response['appid'] = $appId;
    $response['generated_at'] = gmdate('c');
    return ['app_id' => $appId, 'scope' => 'app', 'config' => $response];
}

/**
 * 在一个事务中去重回执并更新两张聚合表。
 *
 * @return bool true 表示首次计入，false 表示同一 receipt_id 的重试已忽略。
 */
function configDeliveryInsertPathReceipt(PDO $pdo, array $row, string $receiptHash): bool
{
    $pdo->beginTransaction();
    try {
        $receipt = $pdo->prepare("INSERT IGNORE INTO cainiao_network_path_receipt
            (receipt_hash,app_id,received_at) VALUES (:receipt_hash,:app_id,UTC_TIMESTAMP())");
        $receipt->execute([':receipt_hash' => $receiptHash, ':app_id' => $row[':app_id']]);
        if ($receipt->rowCount() === 0) {
            $pdo->commit();
            return false;
        }

        $stmt = $pdo->prepare("INSERT INTO cainiao_dns_path_stats
            (stat_date,dimension_hash,domain_pool_id,scope,dns_mode,dns_provider,target_host,
             app_id,package_name,carrier,network_type,request_count,ok_count,fail_count,
             rejected_count,rescued_count,last_seen_at)
            VALUES (:stat_date,:dimension_hash,:domain_pool_id,:scope,:dns_mode,:dns_provider,:target_host,
             :app_id,:package_name,:carrier,:network_type,1,:ok_count,:fail_count,
             :rejected_count,:rescued_count,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 HOUR))
            ON DUPLICATE KEY UPDATE request_count=request_count+1,
             ok_count=ok_count+VALUES(ok_count),fail_count=fail_count+VALUES(fail_count),
             rejected_count=rejected_count+VALUES(rejected_count),rescued_count=rescued_count+VALUES(rescued_count),
             last_seen_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 HOUR)");
        $stmt->execute($row);

        if ((int)$row[':domain_pool_id'] > 0) {
            $domain = $pdo->prepare("INSERT INTO cainiao_api_domain_stats
                (domain_pool_id,scope,stat_date,request_count,ok_count,fail_count,last_seen_at)
                VALUES (:domain_pool_id,:scope,:stat_date,1,:ok_count,:fail_count,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 HOUR))
                ON DUPLICATE KEY UPDATE request_count=request_count+1,
                 ok_count=ok_count+VALUES(ok_count),fail_count=fail_count+VALUES(fail_count),
                 last_seen_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 HOUR)");
            $domain->execute([
                ':domain_pool_id' => $row[':domain_pool_id'], ':scope' => $row[':scope'],
                ':stat_date' => $row[':stat_date'], ':ok_count' => $row[':ok_count'],
                ':fail_count' => $row[':fail_count'],
            ]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 小概率、限量清理已超过重试窗口的回执去重记录。
 *
 * 去重表只用于拦截网络重试，保留 30 天足以覆盖离线、超时和重放场景。
 * 用回执哈希做稳定抽样，避免每次公开 API 请求都执行 DELETE。
 */
function configDeliveryCleanupOldReceipts(PDO $pdo, string $receiptHash): void
{
    if ((hexdec(substr($receiptHash, 0, 4)) % 1024) !== 0) {
        return;
    }
    try {
        $pdo->exec("DELETE FROM cainiao_network_path_receipt
            WHERE received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
            ORDER BY received_at ASC
            LIMIT 1000");
    } catch (Throwable $ignored) {
        // 维护失败不影响本次真实统计的入库结果。
    }
}

/**
 * 新壳网络路径回执。
 *
 * 每一次配置 API 尝试在最终成功或失败确定后只调用一次，避免
 * “先记成功、后记失败”的双计数。回执失败与主配置请求完全隔离。
 */
function recordNetworkPath(PDO $pdo, array $input)
{
    configDeliveryValidateAppReceipt($pdo, $input, 153);
    $receiptId = trim((string)($input['receipt_id'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,95}$/', $receiptId)) {
        throw new InvalidArgumentException('receipt_id 必须是 16至 96 位稳定回执标识');
    }
    $mode = strtolower(trim((string)($input['dns_mode'] ?? 'unknown')));
    if (!in_array($mode, ['doh', 'udp', 'cache', 'system', 'unknown'], true)) $mode = 'unknown';
    $scope = strtolower(trim((string)($input['scope'] ?? 'config')));
    if (!in_array($scope, ['config', 'report', 'click'], true)) $scope = 'config';
    $domainPoolId = max(0, (int)($input['api_pool_id'] ?? 0));
    if ($domainPoolId > 0) {
        $check = $pdo->prepare('SELECT usage_scope FROM cainiao_api_domain_pool WHERE id=:id LIMIT 1');
        try {
            $check->execute([':id' => $domainPoolId]);
        } catch (PDOException $e) {
            // 从旧镜像滚动升级时，首条 v153 回执可以自动补表后继续。
            ensureConfigDeliverySchema($pdo);
            $check->execute([':id' => $domainPoolId]);
        }
        $registeredScope = $check->fetchColumn();
        // APK 可能在管理员删除节点前已缓存该 ID；已通过注入密钥校验的
        // 滞后回执仍应进入历史统计，页面会显示为“已删除 #ID”。
        if ($registeredScope !== false && $registeredScope !== 'all' && $registeredScope !== $scope) {
            throw new RuntimeException('API 域名池用途与回执不匹配');
        }
    }

    $okRaw = $input['ok'] ?? false;
    $ok = !($okRaw === false || $okRaw === 0 || $okRaw === '0' || strtolower((string)$okRaw) === 'false');
    $rejected = max(0, min(999, (int)($input['rejected_count'] ?? 0)));
    $provider = configDeliveryNormalizeStatText($input['dns_provider'] ?? '', 191);
    $targetHost = strtolower(configDeliveryNormalizeStatText($input['target_host'] ?? '', 255));
    $packageName = configDeliveryNormalizeStatText($input['package_name'] ?? '', 255);
    $carrier = configDeliveryNormalizeStatText($input['carrier'] ?? '', 100);
    $networkType = strtolower(configDeliveryNormalizeStatText($input['network_type'] ?? '', 32));
    $appId = (int)$input['app_id'];
    $receiptHash = hash('sha256', $appId . "\n" . $receiptId);
    $rescued = $ok && $rejected > 0 && in_array($mode, ['doh', 'udp', 'cache'], true) ? 1 : 0;
    $dimension = [
        'pool' => $domainPoolId, 'scope' => $scope, 'mode' => $mode,
        'provider' => $provider, 'host' => $targetHost, 'app' => $appId,
        'package' => $packageName, 'carrier' => $carrier, 'network' => $networkType,
    ];
    $row = [
        ':stat_date' => configDeliveryToday(),
        ':dimension_hash' => hash('sha256', json_encode($dimension, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ':domain_pool_id' => $domainPoolId,
        ':scope' => $scope,
        ':dns_mode' => $mode,
        ':dns_provider' => $provider,
        ':target_host' => $targetHost,
        ':app_id' => $appId,
        ':package_name' => $packageName,
        ':carrier' => $carrier,
        ':network_type' => $networkType,
        ':ok_count' => $ok ? 1 : 0,
        ':fail_count' => $ok ? 0 : 1,
        ':rejected_count' => $rejected,
        ':rescued_count' => $rescued,
    ];
    try {
        $inserted = configDeliveryInsertPathReceipt($pdo, $row, $receiptHash);
    } catch (PDOException $e) {
        if (strpos(strtolower($e->getMessage()), 'doesn\'t exist') === false
            && strpos(strtolower($e->getMessage()), 'does not exist') === false) {
            throw $e;
        }
        ensureConfigDeliverySchema($pdo);
        $inserted = configDeliveryInsertPathReceipt($pdo, $row, $receiptHash);
    }
    configDeliveryCleanupOldReceipts($pdo, $receiptHash);
    return [
        'message' => 'ok',
        'dns_mode' => $mode,
        'rescued' => (bool)$rescued,
        'duplicate' => !$inserted,
    ];
}
