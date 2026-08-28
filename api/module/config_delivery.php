<?php

/**
 * 配置分发管理 API。
 *
 * 管理端方法都要求 admin Cookie；recordNetworkPath() 是新壳的公开回执入口，
 * 通过 APPID、归属用户、包名和注入密钥联合校验，不依赖后台登录态。
 */

require_once __DIR__ . '/../utils/ConfigDelivery.php';
require_once __DIR__ . '/../utils/ApiDomainAutomation.php';

/** 统一校验管理员身份。 */
function configDeliveryRequireAdmin(PDO $pdo): array
{
    $user = Auth::check($pdo);
    if (($user['role'] ?? '') !== 'admin') {
        throw new RuntimeException('无权限');
    }
    return $user;
}

/** 严格解析数据库无符号整数 ID，不接受小数、科学计数和容器类型。 */
function configDeliveryAutomationParseId($value, string $label, bool $allowZero = false): int
{
    return apiDomainAutomationPositiveInt($value, $label, $allowZero ? 0 : 1, 4294967295);
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
        'automation' => apiDomainAutomationOverview($pdo),
        'presets' => [
            'doh' => configDeliveryDohPresets(),
            'dns' => configDeliveryDnsPresets(),
        ],
    ];
}

/** 新增或更新 AWS 账号元数据与运行环境凭据引用。 */
function saveApiDomainCloudAccount(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = [
        'id', 'name', 'account_id', 'region', 'credential_ref', 'auth_type',
        'role_arn', 'external_id_ref', 'enabled',
    ];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('AWS 账号请求参数不合法');
    }
    if (array_key_exists('id', $input)) {
        $input['id'] = configDeliveryAutomationParseId($input['id'], 'AWS 账号 ID', true);
    }
    return apiDomainAutomationSaveCloudAccount($pdo, $input);
}

/** 只读核对 STS 身份和 CloudFront 列表权限，不触发云资源写操作。 */
function validateApiDomainCloudAccountConnection(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('AWS 账号连接验证请求参数不合法');
    }
    if (!array_key_exists('id', $input)) {
        throw new InvalidArgumentException('AWS 账号连接验证请求参数不完整');
    }
    $id = configDeliveryAutomationParseId($input['id'], 'AWS 账号 ID');
    return apiDomainAutomationValidateCloudAccount($pdo, $id);
}

/** 归档 AWS 账号非敏感元数据。 */
function deleteApiDomainCloudAccount(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('AWS 账号删除请求参数不合法');
    }
    $id = configDeliveryAutomationParseId($input['id'] ?? null, 'AWS 账号 ID');
    return apiDomainAutomationDeleteCloudAccount($pdo, $id);
}

/** 新增或更新稳定 API 域名自动化池组。 */
function saveApiDomainAutomationGroup(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = [
        'id',
        'name',
        'cloud_account_id',
        'usage_scope',
        'environment',
        'region',
        'domain_provider',
        'certificate_provider',
        'origin_domain',
        'public_path',
        'probe_app_id',
        'price_class',
        'ipv6_enabled',
        'enabled',
        'generation_enabled',
        'capacity_mode',
        'target_active_count',
        'minimum_healthy_count',
        'interval_value',
        'interval_unit',
        'generate_count',
        'observation_days',
        'idle_mark_days',
        'cleanup_enabled',
        'cleanup_no_access_days',
    ];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('自动化池组请求参数不合法');
    }
    if (array_key_exists('id', $input)) {
        $input['id'] = configDeliveryAutomationParseId($input['id'], '池组 ID', true);
    }
    if (array_key_exists('cloud_account_id', $input)) {
        $input['cloud_account_id'] = configDeliveryAutomationParseId($input['cloud_account_id'], 'AWS 账号 ID');
    }
    return apiDomainAutomationSaveGroup($pdo, $input);
}

/** 按资源账本状态重建一个有界作业，CallerReference 和历史事实保持不变。 */
function retryApiDomainCloudResource(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['resource_id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('CloudFront 资源重试请求参数不合法');
    }
    if (!array_key_exists('resource_id', $input)) {
        throw new InvalidArgumentException('CloudFront 资源重试请求参数不完整');
    }
    $resourceId = apiDomainAutomationPositiveInt(
        $input['resource_id'],
        'CloudFront 资源 ID',
        1,
        PHP_INT_MAX
    );
    return apiDomainAutomationRetryCloudResource($pdo, $resourceId);
}

/**
 * 单个或批量提交 CloudFront 资源删除。
 *
 * 单个按钮可传 resource_id；批量按钮传 resource_ids 数组。后端会先取消
 * 尚未开始的作业，已创建分配统一交给 Worker 执行禁用、轮询、删除和归档。
 */
function deleteApiDomainCloudResources(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['resource_id', 'resource_ids'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('CloudFront 资源删除请求参数不合法');
    }
    $resourceIds = [];
    if (array_key_exists('resource_ids', $input)) {
        if (!is_array($input['resource_ids'])) {
            throw new InvalidArgumentException('resource_ids 必须是数组');
        }
        $resourceIds = $input['resource_ids'];
    }
    if (array_key_exists('resource_id', $input)) {
        $resourceIds[] = $input['resource_id'];
    }
    if (!$resourceIds) {
        throw new InvalidArgumentException('至少选择一个 CloudFront 资源');
    }
    return apiDomainAutomationRequestCloudResourceDeletions($pdo, $resourceIds, 'manual');
}

/** 单个资源删除别名，便于经典页或外部脚本保持简单参数合同。 */
function deleteApiDomainCloudResource(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['resource_id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('CloudFront 资源删除请求参数不合法');
    }
    if (!array_key_exists('resource_id', $input)) {
        throw new InvalidArgumentException('CloudFront 资源删除请求参数不完整');
    }
    return apiDomainAutomationRequestCloudResourceDeletions(
        $pdo,
        [$input['resource_id']],
        'manual'
    );
}

/** 归档自动化池组，保留历史批次与节点证据。 */
function deleteApiDomainAutomationGroup(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('自动化池组删除请求参数不合法');
    }
    $id = configDeliveryAutomationParseId($input['id'] ?? null, '池组 ID');
    return apiDomainAutomationDeleteGroup($pdo, $id);
}

/** 手动执行一轮容量检查、生命周期刷新与批次记录。 */
function runApiDomainAutomationGroup(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('自动化池组执行请求参数不合法');
    }
    $id = configDeliveryAutomationParseId($input['id'] ?? null, '池组 ID');
    return apiDomainAutomationRunGroup($pdo, $id, 'manual');
}

/** 试运行只返回容量、生成与清理计划，不变更节点或分发状态。 */
function dryRunApiDomainAutomationGroup(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('自动化池组试运行请求参数不合法');
    }
    $id = configDeliveryAutomationParseId($input['id'] ?? null, '池组 ID');
    return apiDomainAutomationDryRunGroup($pdo, $id);
}

/** 暂停池组的定时生成与生命周期执行。 */
function pauseApiDomainAutomationGroup(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('暂停池组请求参数不合法');
    }
    $id = configDeliveryAutomationParseId($input['id'] ?? null, '池组 ID');
    return apiDomainAutomationSetGroupEnabled($pdo, $id, false);
}

/** 恢复池组并以当前时间重新计算下次执行。 */
function resumeApiDomainAutomationGroup(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('恢复池组请求参数不合法');
    }
    $id = configDeliveryAutomationParseId($input['id'] ?? null, '池组 ID');
    return apiDomainAutomationSetGroupEnabled($pdo, $id, true);
}

/** 按历史运行记录重试，旧运行和旧批次始终保持不变。 */
function retryApiDomainAutomationRun(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['run_id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('重试运行请求参数不合法');
    }
    $runId = apiDomainAutomationPositiveInt($input['run_id'] ?? null, '运行记录 ID', 1, PHP_INT_MAX);
    return apiDomainAutomationRetryRun($pdo, $runId);
}

/** 维护 AWS 自动节点的固定／预留保护标记。 */
function setApiDomainAutomationNodeProtection(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['id', 'pinned', 'reserved'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('节点保护请求参数不合法');
    }
    foreach ($allowedKeys as $requiredKey) {
        if (!array_key_exists($requiredKey, $input)) {
            throw new InvalidArgumentException('节点保护请求参数不完整');
        }
    }
    $id = configDeliveryAutomationParseId($input['id'] ?? null, '节点 ID');
    $pinned = apiDomainAutomationNormalizeFlag($input['pinned'] ?? null, '固定保护标记') === 1;
    $reserved = apiDomainAutomationNormalizeFlag($input['reserved'] ?? null, '预留保护标记') === 1;
    return apiDomainAutomationSetNodeProtection($pdo, $id, $pinned, $reserved);
}

/** 将已标记的 AWS 自动节点提交到本地待清理队列。 */
function requestApiDomainAutomationNodeCleanup(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('节点清理请求参数不合法');
    }
    if (!array_key_exists('id', $input)) {
        throw new InvalidArgumentException('节点清理请求参数不完整');
    }
    $id = configDeliveryAutomationParseId($input['id'], '节点 ID');
    return apiDomainAutomationRequestNodeCleanup($pdo, $id, 'manual');
}

/** 新增或更新手工 API 域名池行；AWS 自动节点只由生命周期合同管理。 */
function saveApiDomain(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['id', 'name', 'base_url', 'usage_scope', 'enabled', 'priority'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('API 域名请求参数不合法');
    }
    ensureApiDomainAutomationSchema($pdo);
    $id = configDeliveryAutomationParseId($input['id'] ?? 0, 'API 域名 ID', true);
    $row = [
        ':name' => configDeliveryNormalizeName($input['name'] ?? ''),
        ':base_url' => configDeliveryNormalizeApiUrl($input['base_url'] ?? ''),
        ':scope' => configDeliveryNormalizeScope($input['usage_scope'] ?? 'config'),
        ':enabled' => configDeliveryNormalizeEnabled($input['enabled'] ?? 1),
        ':priority' => configDeliveryNormalizePriority($input['priority'] ?? 0),
    ];
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $current = $pdo->prepare('SELECT id,origin FROM cainiao_api_domain_pool
                WHERE id=:id FOR UPDATE');
            $current->execute([':id' => $id]);
            $currentRow = $current->fetch(PDO::FETCH_ASSOC);
            if (!$currentRow) throw new RuntimeException('API 域名记录不存在');
            if ((string)($currentRow['origin'] ?? 'manual') === 'aws_auto') {
                throw new RuntimeException('AWS 自动节点由生命周期队列管理，请到节点视图操作');
            }
            $stmt = $pdo->prepare('UPDATE cainiao_api_domain_pool SET name=:name,base_url=:base_url,
                usage_scope=:scope,enabled=:enabled,priority=:priority
                WHERE id=:id AND COALESCE(origin,\'manual\')<>\'aws_auto\'');
            $stmt->execute($row + [':id' => $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO cainiao_api_domain_pool
                (name,base_url,usage_scope,enabled,priority,origin,cleanup_protected,lifecycle_status)
                VALUES (:name,:base_url,:scope,:enabled,:priority,\'manual\',1,\'active\')');
            $stmt->execute($row);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((string)$e->getCode() === '23000') throw new RuntimeException('该 API 地址和用途已存在');
        throw $e;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $pdo->commit();
    $invalidate = configDeliveryInvalidateAndSync($pdo);
    return ['message' => '保存成功', 'id' => $id, 'invalidate' => $invalidate];
}

/** 删除手工 API 域名池行；AWS 自动节点须走标记与待清理队列。 */
function deleteApiDomain(PDO $pdo, array $input)
{
    configDeliveryRequireAdmin($pdo);
    $allowedKeys = ['id'];
    if (array_diff(array_keys($input), $allowedKeys)) {
        throw new InvalidArgumentException('API 域名删除请求参数不合法');
    }
    $id = configDeliveryAutomationParseId($input['id'] ?? null, 'API 域名 ID');
    ensureApiDomainAutomationSchema($pdo);
    $pdo->beginTransaction();
    try {
        $current = $pdo->prepare('SELECT id,origin FROM cainiao_api_domain_pool
            WHERE id=:id FOR UPDATE');
        $current->execute([':id' => $id]);
        $currentRow = $current->fetch(PDO::FETCH_ASSOC);
        if (!$currentRow) throw new RuntimeException('API 域名记录不存在');
        if ((string)($currentRow['origin'] ?? 'manual') === 'aws_auto') {
            throw new RuntimeException('AWS 自动节点由生命周期队列管理，请到节点视图提交清理');
        }
        $stmt = $pdo->prepare("DELETE FROM cainiao_api_domain_pool
            WHERE id=:id AND COALESCE(origin,'manual')<>'aws_auto'");
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('API 域名记录状态已变化');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
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
function configDeliveryInsertPathReceipt(
    PDO $pdo,
    array $row,
    string $receiptHash,
    ?bool &$distributionChanged = null
): bool
{
    $distributionChanged = false;
    $pdo->beginTransaction();
    try {
        $receipt = $pdo->prepare("INSERT IGNORE INTO cainiao_network_path_receipt
            (receipt_hash,app_id,received_at) VALUES (:receipt_hash,:app_id,UTC_TIMESTAMP())");
        $receipt->execute([':receipt_hash' => $receiptHash, ':app_id' => $row[':app_id']]);
        if ($receipt->rowCount() === 0) {
            $pdo->commit();
            return false;
        }

        if ((int)$row[':domain_pool_id'] > 0 && (int)($row[':ok_count'] ?? 0) === 1) {
            try {
                // 与自动清理对同一节点加行锁。新的成功回执会刷新访问事实；
                // unused_marked / cleanup_pending 可恢复分发，pending_verification
                // 只标记已验证并继续观察，终态归档事实交给未来 AWS 适配器处理。
                $pool = $pdo->prepare('SELECT origin,lifecycle_status,enabled
                    FROM cainiao_api_domain_pool WHERE id=:id LIMIT 1 FOR UPDATE');
                $pool->execute([':id' => (int)$row[':domain_pool_id']]);
                $poolRow = $pool->fetch(PDO::FETCH_ASSOC);
                if ($poolRow && (string)($poolRow['origin'] ?? '') === 'aws_auto') {
                    $lifecycleStatus = (string)($poolRow['lifecycle_status'] ?? 'active');
                    $restorable = in_array($lifecycleStatus, ['unused_marked', 'cleanup_pending'], true);
                    $cloudRestore = $restorable
                        ? apiDomainAutomationHandleSuccessfulReceipt($pdo, (int)$row[':domain_pool_id'])
                        : ['provider_ready' => 1, 'restore_queued' => 0];
                    $deletionRequested = !empty($cloudRestore['deletion_requested']);
                    $providerReady = !empty($cloudRestore['provider_ready']);
                    $targetEnabled = $providerReady ? 1 : 0;
                    $distributionChanged = $restorable && !$deletionRequested
                        && (int)($poolRow['enabled'] ?? 0) !== $targetEnabled;
                    if ($deletionRequested) {
                        // 显式删除优先级高于后续访问：只记录访问事实，保持节点隔离，
                        // 不清除 cleanup_requested_at，也不把 CloudFront 重新启用。
                        $protect = $pdo->prepare("UPDATE cainiao_api_domain_pool SET
                            access_count=access_count+1,last_access_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),
                            verified_at=COALESCE(verified_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)),
                            lifecycle_status='cleanup_pending',enabled=0,
                            lifecycle_updated_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),
                            cleanup_requested_at=COALESCE(cleanup_requested_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)),
                            cleanup_reason='manual_resource_delete',cloud_cleanup_state='queued'
                            WHERE id=:id AND origin='aws_auto'");
                        $protectParams = [':id' => (int)$row[':domain_pool_id']];
                    } elseif ($restorable) {
                        $protect = $pdo->prepare("UPDATE cainiao_api_domain_pool SET
                            access_count=access_count+1,last_access_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),
                            verified_at=COALESCE(verified_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)),
                            lifecycle_status='active',lifecycle_updated_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),
                            idle_marked_at=NULL,cleanup_requested_at=NULL,
                            enabled=:provider_ready,cleanup_reason='',
                            cloud_cleanup_state=IF(:restore_queued=1,'restore_pending','not_required')
                            WHERE id=:id AND origin='aws_auto'");
                        $protectParams = [
                            ':id' => (int)$row[':domain_pool_id'],
                            ':provider_ready' => $targetEnabled,
                            ':restore_queued' => !empty($cloudRestore['restore_queued']) ? 1 : 0,
                        ];
                    } else {
                        $protect = $pdo->prepare("UPDATE cainiao_api_domain_pool SET
                            access_count=access_count+1,last_access_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),
                            verified_at=COALESCE(verified_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)),
                            lifecycle_updated_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)
                            WHERE id=:id AND origin='aws_auto'");
                        $protectParams = [':id' => (int)$row[':domain_pool_id']];
                    }
                    $protect->execute($protectParams);
                }
            } catch (PDOException $schemaError) {
                // 滚动升级初始数秒内旧表尚无生命周期字段时，真实统计仍照常入库。
                if (stripos($schemaError->getMessage(), 'unknown column') === false) {
                    throw $schemaError;
                }
                $distributionChanged = false;
            }
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
        $distributionChanged = false;
        $inserted = configDeliveryInsertPathReceipt($pdo, $row, $receiptHash, $distributionChanged);
    } catch (PDOException $e) {
        if (strpos(strtolower($e->getMessage()), 'doesn\'t exist') === false
            && strpos(strtolower($e->getMessage()), 'does not exist') === false) {
            throw $e;
        }
        ensureConfigDeliverySchema($pdo);
        $distributionChanged = false;
        $inserted = configDeliveryInsertPathReceipt($pdo, $row, $receiptHash, $distributionChanged);
    }
    if (!empty($distributionChanged)) {
        configDeliveryInvalidateAndSync($pdo);
    }
    configDeliveryCleanupOldReceipts($pdo, $receiptHash);
    return [
        'message' => 'ok',
        'dns_mode' => $mode,
        'rescued' => (bool)$rescued,
        'duplicate' => !$inserted,
    ];
}
