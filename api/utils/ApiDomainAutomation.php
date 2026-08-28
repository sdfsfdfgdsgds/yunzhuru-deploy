<?php

/**
 * API 域名池 AWS 自动生成与无访问清理。
 *
 * 本文件是自动化能力的单一事实源：账号元数据、稳定池组、
 * 调度批次、容量补齐与节点生命周期都在此处计算。当前 AWS 适配器
 * 保持 waiting_adapter：可以安全保存策略、生成运行批次，并将符合
 * 规则的本地节点隔离到 cleanup_pending，但不创建或删除 AWS 云资源。
 */

if (!function_exists('apiDomainAutomationTimezone')) {
    /** 所有调度与页面统计统一使用北京时间。 */
    function apiDomainAutomationTimezone(): DateTimeZone
    {
        static $timezone = null;
        if (!$timezone) $timezone = new DateTimeZone('Asia/Shanghai');
        return $timezone;
    }
}

if (!function_exists('apiDomainAutomationNow')) {
    function apiDomainAutomationNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', apiDomainAutomationTimezone());
    }
}

if (!function_exists('apiDomainAutomationDateText')) {
    function apiDomainAutomationDateText(DateTimeInterface $value): string
    {
        return $value->setTimezone(apiDomainAutomationTimezone())->format('Y-m-d H:i:s');
    }
}

if (!function_exists('apiDomainAutomationPositiveInt')) {
    /** 严格读取有界正整数，拒绝小数、科学计数和容器类型。 */
    function apiDomainAutomationPositiveInt($value, string $label, int $minimum, int $maximum): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            // 先用字符串长度和字典序拦截溢出，避免超大 ID 转为 PHP_INT_MAX 后被误接受。
            $normalized = ltrim($value, '0');
            if ($normalized === '') $normalized = '0';
            $maximumText = (string)$maximum;
            if (strlen($normalized) > strlen($maximumText)
                || (strlen($normalized) === strlen($maximumText) && strcmp($normalized, $maximumText) > 0)) {
                throw new InvalidArgumentException($label . "必须介于 {$minimum} 至 {$maximum}");
            }
            $parsed = (int)$normalized;
        } else {
            throw new InvalidArgumentException($label . '必须是整数');
        }
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new InvalidArgumentException($label . "必须介于 {$minimum} 至 {$maximum}");
        }
        return $parsed;
    }
}

if (!function_exists('apiDomainAutomationNormalizeFlag')) {
    /** 自动化策略开关只接受明确的布尔值或 0/1，不将任意字符串当作开启。 */
    function apiDomainAutomationNormalizeFlag($value, string $label, int $default = 0): int
    {
        if ($value === null || $value === '') return $default ? 1 : 0;
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') return 1;
        if ($value === false || $value === 0 || $value === '0' || $value === 'false') return 0;
        throw new InvalidArgumentException($label . '必须是布尔值或 0/1');
    }
}

if (!function_exists('apiDomainAutomationLimitText')) {
    /**
     * 按 Unicode 字符限制入库文本。
     *
     * 部署环境未加载 mbstring 时，回退到 UTF-8 安全字节截断，
     * 避免在 JSON 响应中留下断裂的多字节字符。
     */
    function apiDomainAutomationLimitText($value, int $maximumCharacters): string
    {
        $text = (string)$value;
        if ($maximumCharacters <= 0 || $text === '') return '';
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maximumCharacters, 'UTF-8');
        }
        if (strlen($text) <= $maximumCharacters) return $text;
        // UTF-8 最多 4 字节，先保留足够候选字节，再逐字符计数。
        $candidate = substr($text, 0, $maximumCharacters * 4);
        while ($candidate !== '' && preg_match('//u', $candidate) !== 1) {
            $candidate = substr($candidate, 0, -1);
        }
        if ($candidate === '') return '';
        if (preg_match_all('/./us', $candidate, $matches) !== false) {
            return implode('', array_slice($matches[0], 0, $maximumCharacters));
        }
        return substr($candidate, 0, $maximumCharacters);
    }
}

if (!function_exists('apiDomainAutomationNormalizeName')) {
    /** 校验 AWS 账号和池组名称，不依赖可选的 mbstring 扩展。 */
    function apiDomainAutomationNormalizeName($value): string
    {
        $name = trim((string)$value);
        if ($name === '' || preg_match('//u', $name) !== 1) {
            throw new InvalidArgumentException('名称必填且必须是有效 UTF-8');
        }
        $count = preg_match_all('/./us', $name, $matches);
        if ($count === false || $count > 100) {
            throw new InvalidArgumentException('名称必填且不能超过 100 个字符');
        }
        return $name;
    }
}

if (!function_exists('apiDomainAutomationNormalizeUnit')) {
    function apiDomainAutomationNormalizeUnit($value): string
    {
        $unit = strtolower(trim((string)$value));
        if (!in_array($unit, ['minute', 'hour', 'day', 'month'], true)) {
            throw new InvalidArgumentException('生成周期单位必须是 minute、hour、day 或 month');
        }
        return $unit;
    }
}

if (!function_exists('apiDomainAutomationMonthCandidate')) {
    /**
     * 从固定锚点计算指定月数后的时间。
     *
     * 每次都使用原始锚点日，因此 1 月 31 日会对齐 2 月月末、
     * 3 月 31 日，不会因 2 月截断而永久漂移到 28 日。
     */
    function apiDomainAutomationMonthCandidate(DateTimeImmutable $anchor, int $months): DateTimeImmutable
    {
        $timezone = apiDomainAutomationTimezone();
        $local = $anchor->setTimezone($timezone);
        $monthIndex = ((int)$local->format('Y') * 12 + ((int)$local->format('n') - 1)) + $months;
        $year = intdiv($monthIndex, 12);
        $month = ($monthIndex % 12) + 1;
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01 %s', $year, $month, $local->format('H:i:s')), $timezone);
        $lastDay = (int)$first->modify('last day of this month')->format('j');
        $day = min((int)$local->format('j'), $lastDay);
        return $first->setDate($year, $month, $day);
    }
}

if (!function_exists('apiDomainAutomationNextRun')) {
    /** 从固定锚点计算第一个严格晚于 after 的调度时间。 */
    function apiDomainAutomationNextRun(
        DateTimeImmutable $anchor,
        int $intervalValue,
        string $intervalUnit,
        ?DateTimeImmutable $after = null
    ): DateTimeImmutable {
        $intervalValue = max(1, $intervalValue);
        $intervalUnit = apiDomainAutomationNormalizeUnit($intervalUnit);
        $timezone = apiDomainAutomationTimezone();
        $anchor = $anchor->setTimezone($timezone);
        $after = ($after ?: apiDomainAutomationNow())->setTimezone($timezone);

        if ($intervalUnit !== 'month') {
            $secondsByUnit = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
            $stepSeconds = $secondsByUnit[$intervalUnit] * $intervalValue;
            $elapsed = max(0, $after->getTimestamp() - $anchor->getTimestamp());
            $steps = intdiv($elapsed, $stepSeconds) + 1;
            return $anchor->setTimestamp($anchor->getTimestamp() + $steps * $stepSeconds);
        }

        $anchorMonth = (int)$anchor->format('Y') * 12 + (int)$anchor->format('n') - 1;
        $afterMonth = (int)$after->format('Y') * 12 + (int)$after->format('n') - 1;
        $elapsedMonths = max(0, $afterMonth - $anchorMonth);
        $months = max($intervalValue, intdiv($elapsedMonths, $intervalValue) * $intervalValue);
        $candidate = apiDomainAutomationMonthCandidate($anchor, $months);
        while ($candidate <= $after) {
            $months += $intervalValue;
            $candidate = apiDomainAutomationMonthCandidate($anchor, $months);
        }
        return $candidate;
    }
}

if (!function_exists('apiDomainAutomationMysqlColumnExists')) {
    function apiDomainAutomationMysqlColumnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name');
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('apiDomainAutomationEnsureTableColumn')) {
    /** 表名、字段名和定义只由内部常量调用，不接收请求输入。 */
    function apiDomainAutomationEnsureTableColumn(
        PDO $pdo,
        string $table,
        string $column,
        string $definition
    ): void
    {
        if (!apiDomainAutomationMysqlColumnExists($pdo, $table, $column)) {
            try {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            } catch (PDOException $error) {
                // API 请求和 worker 可能在首次上线时并发迁移；只容忍“字段已存在”的竞态。
                if (!apiDomainAutomationMysqlColumnExists($pdo, $table, $column)) {
                    throw $error;
                }
            }
        }
    }
}

if (!function_exists('apiDomainAutomationEnsurePoolColumn')) {
    /** 保留旧调用合同，内部统一转到通用表字段迁移器。 */
    function apiDomainAutomationEnsurePoolColumn(PDO $pdo, string $column, string $definition): void
    {
        apiDomainAutomationEnsureTableColumn($pdo, 'cainiao_api_domain_pool', $column, $definition);
    }
}

if (!function_exists('ensureApiDomainAutomationSchema')) {
    /** 幂等创建账号元数据、池组、批次及节点生命周期字段。 */
    function ensureApiDomainAutomationSchema(PDO $pdo): void
    {
        static $ready = false;
        if ($ready) return;
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        if (function_exists('ensureConfigDeliverySchema')) ensureConfigDeliverySchema($pdo);

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_api_domain_cloud_account (
            id int unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            account_id varchar(32) NOT NULL DEFAULT '',
            region varchar(32) NOT NULL DEFAULT 'us-east-1',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            connection_state varchar(24) NOT NULL DEFAULT 'waiting_adapter',
            deleted_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_cloud_account_active (deleted_at,enabled,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池云账号非敏感元数据'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_api_domain_automation_group (
            id int unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            cloud_account_id int unsigned NOT NULL,
            usage_scope varchar(16) NOT NULL DEFAULT 'config',
            environment varchar(32) NOT NULL DEFAULT 'production',
            region varchar(32) NOT NULL DEFAULT 'us-east-1',
            domain_provider varchar(32) NOT NULL DEFAULT 'route53',
            certificate_provider varchar(32) NOT NULL DEFAULT 'acm',
            enabled tinyint(1) NOT NULL DEFAULT 0,
            generation_enabled tinyint(1) NOT NULL DEFAULT 0,
            target_active_count int unsigned NOT NULL DEFAULT 20,
            minimum_healthy_count int unsigned NOT NULL DEFAULT 4,
            interval_value int unsigned NOT NULL DEFAULT 1,
            interval_unit varchar(16) NOT NULL DEFAULT 'day',
            generate_count int unsigned NOT NULL DEFAULT 1,
            observation_days int unsigned NOT NULL DEFAULT 1,
            idle_mark_days int unsigned NOT NULL DEFAULT 3,
            cleanup_enabled tinyint(1) NOT NULL DEFAULT 0,
            cleanup_no_access_days int unsigned NOT NULL DEFAULT 7,
            adapter_state varchar(24) NOT NULL DEFAULT 'waiting_adapter',
            schedule_anchor_at datetime NOT NULL,
            next_run_at datetime DEFAULT NULL,
            last_run_at datetime DEFAULT NULL,
            last_run_status varchar(24) NOT NULL DEFAULT 'never',
            last_run_message varchar(255) NOT NULL DEFAULT '',
            deleted_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_automation_due (deleted_at,enabled,next_run_at,id),
            KEY idx_automation_account (cloud_account_id,deleted_at,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池自动生成与清理策略'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_api_domain_automation_batch (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            group_id int unsigned NOT NULL,
            batch_code varchar(64) NOT NULL,
            period_key char(7) NOT NULL,
            trigger_type varchar(16) NOT NULL DEFAULT 'scheduled',
            planned_count int unsigned NOT NULL DEFAULT 0,
            created_count int unsigned NOT NULL DEFAULT 0,
            period_sequence bigint unsigned NOT NULL DEFAULT 0,
            dry_run tinyint(1) NOT NULL DEFAULT 0,
            current_eligible_count int unsigned NOT NULL DEFAULT 0,
            capacity_gap int unsigned NOT NULL DEFAULT 0,
            marked_count int unsigned NOT NULL DEFAULT 0,
            archived_count int unsigned NOT NULL DEFAULT 0,
            cleanup_pending_count int unsigned NOT NULL DEFAULT 0,
            protected_count int unsigned NOT NULL DEFAULT 0,
            status varchar(24) NOT NULL DEFAULT 'waiting_adapter',
            message varchar(255) NOT NULL DEFAULT '',
            started_at datetime NOT NULL,
            finished_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_automation_batch_code (batch_code),
            KEY idx_automation_batch_group (group_id,id),
            KEY idx_automation_batch_period (period_key,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池自动化运行批次'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_api_domain_automation_run (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            group_id int unsigned NOT NULL,
            batch_id bigint unsigned DEFAULT NULL,
            retry_of_run_id bigint unsigned DEFAULT NULL,
            trigger_type varchar(16) NOT NULL DEFAULT 'scheduled',
            dry_run tinyint(1) NOT NULL DEFAULT 0,
            status varchar(24) NOT NULL DEFAULT 'planned',
            current_eligible_count int unsigned NOT NULL DEFAULT 0,
            capacity_gap int unsigned NOT NULL DEFAULT 0,
            planned_count int unsigned NOT NULL DEFAULT 0,
            created_count int unsigned NOT NULL DEFAULT 0,
            marked_count int unsigned NOT NULL DEFAULT 0,
            cleanup_pending_count int unsigned NOT NULL DEFAULT 0,
            protected_count int unsigned NOT NULL DEFAULT 0,
            message varchar(255) NOT NULL DEFAULT '',
            started_at datetime NOT NULL,
            finished_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_automation_run_group (group_id,id),
            KEY idx_automation_run_batch (batch_id,id),
            KEY idx_automation_run_status (status,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池自动化独立运行记录'");

        // 先期已上线的表由此幂等补齐，避免要求人工删表重建。
        $groupColumns = [
            'environment' => "varchar(32) NOT NULL DEFAULT 'production' AFTER `usage_scope`",
            'region' => "varchar(32) NOT NULL DEFAULT 'us-east-1' AFTER `environment`",
            'domain_provider' => "varchar(32) NOT NULL DEFAULT 'route53' AFTER `region`",
            'certificate_provider' => "varchar(32) NOT NULL DEFAULT 'acm' AFTER `domain_provider`",
            'generation_enabled' => 'tinyint(1) NOT NULL DEFAULT 0 AFTER `enabled`',
            'observation_days' => 'int unsigned NOT NULL DEFAULT 1 AFTER `generate_count`',
        ];
        foreach ($groupColumns as $column => $definition) {
            apiDomainAutomationEnsureTableColumn(
                $pdo,
                'cainiao_api_domain_automation_group',
                $column,
                $definition
            );
        }

        $batchColumns = [
            'period_sequence' => 'bigint unsigned NOT NULL DEFAULT 0 AFTER `created_count`',
            'dry_run' => 'tinyint(1) NOT NULL DEFAULT 0 AFTER `period_sequence`',
            'current_eligible_count' => 'int unsigned NOT NULL DEFAULT 0 AFTER `dry_run`',
            'capacity_gap' => 'int unsigned NOT NULL DEFAULT 0 AFTER `current_eligible_count`',
            'cleanup_pending_count' => 'int unsigned NOT NULL DEFAULT 0 AFTER `archived_count`',
        ];
        foreach ($batchColumns as $column => $definition) {
            apiDomainAutomationEnsureTableColumn(
                $pdo,
                'cainiao_api_domain_automation_batch',
                $column,
                $definition
            );
        }

        $columns = [
            'origin' => "varchar(16) NOT NULL DEFAULT 'manual' AFTER `priority`",
            'automation_group_id' => 'int unsigned DEFAULT NULL AFTER `origin`',
            'automation_batch_id' => 'bigint unsigned DEFAULT NULL AFTER `automation_group_id`',
            'lifecycle_status' => "varchar(24) NOT NULL DEFAULT 'active' AFTER `automation_batch_id`",
            'cleanup_protected' => 'tinyint(1) NOT NULL DEFAULT 1 AFTER `lifecycle_status`',
            'pinned' => 'tinyint(1) NOT NULL DEFAULT 0 AFTER `cleanup_protected`',
            'reserved' => 'tinyint(1) NOT NULL DEFAULT 0 AFTER `pinned`',
            'verified_at' => 'datetime DEFAULT NULL AFTER `reserved`',
            'observation_until' => 'datetime DEFAULT NULL AFTER `verified_at`',
            'eligible_at' => 'datetime DEFAULT NULL AFTER `observation_until`',
            'last_access_at' => 'datetime DEFAULT NULL AFTER `eligible_at`',
            'access_count' => 'bigint unsigned NOT NULL DEFAULT 0 AFTER `last_access_at`',
            'idle_marked_at' => 'datetime DEFAULT NULL AFTER `access_count`',
            'cleanup_requested_at' => 'datetime DEFAULT NULL AFTER `idle_marked_at`',
            'lifecycle_updated_at' => 'datetime DEFAULT NULL AFTER `cleanup_requested_at`',
            'archived_at' => 'datetime DEFAULT NULL AFTER `lifecycle_updated_at`',
            'cleanup_reason' => "varchar(255) NOT NULL DEFAULT '' AFTER `archived_at`",
            'cloud_resource_ref' => "varchar(255) NOT NULL DEFAULT '' AFTER `cleanup_reason`",
            'cloud_cleanup_state' => "varchar(24) NOT NULL DEFAULT 'not_required' AFTER `cloud_resource_ref`",
        ];
        foreach ($columns as $column => $definition) {
            apiDomainAutomationEnsurePoolColumn($pdo, $column, $definition);
        }

        $pdo->exec("UPDATE cainiao_api_domain_pool
            SET origin='manual',cleanup_protected=1,lifecycle_status='active'
            WHERE origin='' OR origin IS NULL");
        $pdo->exec("UPDATE cainiao_api_domain_pool
            SET lifecycle_status='unused_marked'
            WHERE origin='aws_auto' AND lifecycle_status='no_access'");
        $pdo->exec("UPDATE cainiao_api_domain_pool
            SET verified_at=COALESCE(verified_at,created_at),
                observation_until=COALESCE(observation_until,created_at),
                eligible_at=COALESCE(eligible_at,created_at)
            WHERE origin='aws_auto' AND lifecycle_status IN ('active','unused_marked')");
        $pdo->exec("UPDATE cainiao_api_domain_pool
            SET cloud_cleanup_state='waiting_adapter'
            WHERE origin='aws_auto' AND lifecycle_status='cleanup_pending'");
        $pdo->exec("INSERT INTO cainiao_config_delivery_meta (key_name,key_value)
            VALUES ('api_domain_automation_schema_version','2')
            ON DUPLICATE KEY UPDATE key_value=GREATEST(CAST(key_value AS UNSIGNED),2)");
        $ready = true;
    }
}

if (!function_exists('apiDomainAutomationNormalizeAccount')) {
    function apiDomainAutomationNormalizeAccount(array $input): array
    {
        $accountId = trim((string)($input['account_id'] ?? ''));
        if ($accountId !== '' && preg_match('/^\d{12}$/', $accountId) !== 1) {
            throw new InvalidArgumentException('AWS Account ID 必须留空或填写 12 位数字');
        }
        $region = strtolower(trim((string)($input['region'] ?? '')));
        if (preg_match('/^[a-z]{2}(?:-gov)?-[a-z0-9-]+-\d$/', $region) !== 1) {
            throw new InvalidArgumentException('AWS Region 格式错误');
        }
        return [
            'name' => apiDomainAutomationNormalizeName($input['name'] ?? ''),
            'account_id' => $accountId,
            'region' => $region,
            'enabled' => apiDomainAutomationNormalizeFlag($input['enabled'] ?? 1, 'AWS 账号状态', 1),
        ];
    }
}

if (!function_exists('apiDomainAutomationSaveCloudAccount')) {
    function apiDomainAutomationSaveCloudAccount(PDO $pdo, array $input): array
    {
        ensureApiDomainAutomationSchema($pdo);
        $id = isset($input['id'])
            ? apiDomainAutomationPositiveInt($input['id'], 'AWS 账号 ID', 0, PHP_INT_MAX)
            : 0;
        $row = apiDomainAutomationNormalizeAccount($input);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE cainiao_api_domain_cloud_account
                SET name=:name,account_id=:account_id,region=:region,enabled=:enabled
                WHERE id=:id AND deleted_at IS NULL');
            $stmt->execute([
                ':name' => $row['name'], ':account_id' => $row['account_id'],
                ':region' => $row['region'], ':enabled' => $row['enabled'], ':id' => $id,
            ]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare('SELECT id FROM cainiao_api_domain_cloud_account WHERE id=:id AND deleted_at IS NULL');
                $check->execute([':id' => $id]);
                if (!$check->fetchColumn()) throw new RuntimeException('AWS 账号元数据不存在');
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO cainiao_api_domain_cloud_account
                (name,account_id,region,enabled,connection_state)
                VALUES (:name,:account_id,:region,:enabled,\'waiting_adapter\')');
            $stmt->execute([
                ':name' => $row['name'], ':account_id' => $row['account_id'],
                ':region' => $row['region'], ':enabled' => $row['enabled'],
            ]);
            $id = (int)$pdo->lastInsertId();
        }
        return ['message' => 'AWS 账号元数据已保存，当前等待适配器对接', 'id' => $id];
    }
}

if (!function_exists('apiDomainAutomationDeleteCloudAccount')) {
    function apiDomainAutomationDeleteCloudAccount(PDO $pdo, int $id): array
    {
        ensureApiDomainAutomationSchema($pdo);
        $check = $pdo->prepare('SELECT COUNT(*) FROM cainiao_api_domain_automation_group
            WHERE cloud_account_id=:id AND deleted_at IS NULL');
        $check->execute([':id' => $id]);
        if ((int)$check->fetchColumn() > 0) throw new RuntimeException('该 AWS 账号仍有自动化池组关联，请先删除池组');
        $stmt = $pdo->prepare("UPDATE cainiao_api_domain_cloud_account
            SET enabled=0,deleted_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)
            WHERE id=:id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() <= 0) throw new RuntimeException('AWS 账号元数据不存在');
        return ['message' => 'AWS 账号元数据已归档'];
    }
}

if (!function_exists('apiDomainAutomationNormalizeGroup')) {
    /** 池组枚举类字段只保留可展示的稳定标识。 */
    function apiDomainAutomationNormalizeGroupLabel($value, string $label, string $default): string
    {
        $text = strtolower(trim((string)$value));
        if ($text === '') $text = $default;
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $text) !== 1) {
            throw new InvalidArgumentException($label . '格式错误');
        }
        return $text;
    }

    function apiDomainAutomationNormalizeGroup(array $input): array
    {
        // 当前壳配置合同最多下发 24 个 API 入口，池组活跃容量同步受此约束。
        $target = apiDomainAutomationPositiveInt($input['target_active_count'] ?? 20, '目标活跃数', 1, 24);
        $minimum = apiDomainAutomationPositiveInt($input['minimum_healthy_count'] ?? 4, '最低健康数', 1, 24);
        if ($minimum > $target) throw new InvalidArgumentException('最低健康数不得大于目标活跃数');
        $idleDays = apiDomainAutomationPositiveInt(
            $input['mark_after_days'] ?? ($input['idle_mark_days'] ?? 3),
            '无访问标记天数',
            1,
            3650
        );
        $cleanupDays = apiDomainAutomationPositiveInt(
            $input['cleanup_after_days'] ?? ($input['cleanup_no_access_days'] ?? 7),
            '无访问清理天数',
            1,
            3650
        );
        if ($cleanupDays < $idleDays) throw new InvalidArgumentException('无访问清理天数不得小于标记天数');
        $enabled = apiDomainAutomationNormalizeFlag($input['enabled'] ?? 0, '池组状态', 0);
        $generationEnabled = array_key_exists('generation_enabled', $input)
            ? apiDomainAutomationNormalizeFlag($input['generation_enabled'], '自动生成开关', 0)
            : $enabled;
        return [
            'name' => apiDomainAutomationNormalizeName($input['name'] ?? ''),
            'cloud_account_id' => apiDomainAutomationPositiveInt($input['cloud_account_id'] ?? null, 'AWS 账号', 1, PHP_INT_MAX),
            'usage_scope' => configDeliveryNormalizeScope($input['usage_scope'] ?? 'config'),
            'environment' => apiDomainAutomationNormalizeGroupLabel(
                $input['environment'] ?? 'production',
                '运行环境',
                'production'
            ),
            'region' => apiDomainAutomationNormalizeGroupLabel(
                $input['region'] ?? 'us-east-1',
                'AWS Region',
                'us-east-1'
            ),
            'domain_provider' => apiDomainAutomationNormalizeGroupLabel(
                $input['domain_provider'] ?? 'route53',
                '域名提供方',
                'route53'
            ),
            'certificate_provider' => apiDomainAutomationNormalizeGroupLabel(
                $input['certificate_provider'] ?? 'acm',
                '证书提供方',
                'acm'
            ),
            'enabled' => $enabled,
            'generation_enabled' => $generationEnabled,
            'target_active_count' => $target,
            'minimum_healthy_count' => $minimum,
            'interval_value' => apiDomainAutomationPositiveInt($input['interval_value'] ?? 1, '生成周期数值', 1, 10000),
            'interval_unit' => apiDomainAutomationNormalizeUnit($input['interval_unit'] ?? 'day'),
            'generate_count' => apiDomainAutomationPositiveInt(
                $input['generate_per_run'] ?? ($input['generate_count'] ?? 1),
                '每次生成数量',
                1,
                24
            ),
            'observation_days' => apiDomainAutomationPositiveInt(
                $input['observation_days'] ?? 1,
                '新节点观察天数',
                0,
                3650
            ),
            'idle_mark_days' => $idleDays,
            'cleanup_enabled' => apiDomainAutomationNormalizeFlag($input['cleanup_enabled'] ?? 0, '定时清理开关', 0),
            'cleanup_no_access_days' => $cleanupDays,
        ];
    }
}

if (!function_exists('apiDomainAutomationSaveGroup')) {
    function apiDomainAutomationSaveGroup(PDO $pdo, array $input): array
    {
        ensureApiDomainAutomationSchema($pdo);
        $id = isset($input['id'])
            ? apiDomainAutomationPositiveInt($input['id'], '池组 ID', 0, PHP_INT_MAX)
            : 0;
        $row = apiDomainAutomationNormalizeGroup($input);
        $pdo->beginTransaction();
        try {
            $existing = null;
            if ($id > 0) {
                // 与 worker 共用行锁串行化时钟修改，避免管理员编辑与到期执行互相覆盖。
                $existingStmt = $pdo->prepare('SELECT id,enabled,interval_value,interval_unit,
                        schedule_anchor_at,next_run_at
                    FROM cainiao_api_domain_automation_group
                    WHERE id=:id AND deleted_at IS NULL FOR UPDATE');
                $existingStmt->execute([':id' => $id]);
                $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) throw new RuntimeException('自动化池组不存在');
            }

            $account = $pdo->prepare('SELECT id,region FROM cainiao_api_domain_cloud_account
                WHERE id=:id AND enabled=1 AND deleted_at IS NULL LIMIT 1');
            $account->execute([':id' => $row['cloud_account_id']]);
            $accountRow = $account->fetch(PDO::FETCH_ASSOC);
            if (!$accountRow) throw new RuntimeException('所选 AWS 账号不存在或已停用');

            $now = apiDomainAutomationNow();
            $anchor = $now;
            $next = null;
            if ($existing) {
                try {
                    $anchor = new DateTimeImmutable(
                        (string)$existing['schedule_anchor_at'],
                        apiDomainAutomationTimezone()
                    );
                } catch (Throwable $ignored) {
                    $anchor = $now;
                }
                $scheduleChanged = (int)$existing['interval_value'] !== (int)$row['interval_value']
                    || (string)$existing['interval_unit'] !== (string)$row['interval_unit'];
                if ($scheduleChanged) {
                    // 只有周期参数变更才重建锚点；名称、容量、清理等策略编辑保留原时钟。
                    $anchor = $now;
                }
                if ($row['enabled']) {
                    $canPreserveNext = !$scheduleChanged
                        && (int)$existing['enabled'] === 1
                        && trim((string)($existing['next_run_at'] ?? '')) !== '';
                    if ($canPreserveNext) {
                        $next = new DateTimeImmutable(
                            (string)$existing['next_run_at'],
                            apiDomainAutomationTimezone()
                        );
                    } else {
                        $next = apiDomainAutomationNextRun(
                            $anchor,
                            $row['interval_value'],
                            $row['interval_unit'],
                            $now
                        );
                    }
                }
            } elseif ($row['enabled']) {
                $next = apiDomainAutomationNextRun(
                    $anchor,
                    $row['interval_value'],
                    $row['interval_unit'],
                    $now
                );
            }

            $params = [
                ':name' => $row['name'], ':cloud_account_id' => $row['cloud_account_id'],
                ':usage_scope' => $row['usage_scope'], ':enabled' => $row['enabled'],
                ':environment' => $row['environment'], ':region' => $row['region'],
                ':domain_provider' => $row['domain_provider'],
                ':certificate_provider' => $row['certificate_provider'],
                ':generation_enabled' => $row['generation_enabled'],
                ':target_active_count' => $row['target_active_count'],
                ':minimum_healthy_count' => $row['minimum_healthy_count'],
                ':interval_value' => $row['interval_value'], ':interval_unit' => $row['interval_unit'],
                ':generate_count' => $row['generate_count'], ':observation_days' => $row['observation_days'],
                ':idle_mark_days' => $row['idle_mark_days'],
                ':cleanup_enabled' => $row['cleanup_enabled'],
                ':cleanup_no_access_days' => $row['cleanup_no_access_days'],
                ':schedule_anchor_at' => apiDomainAutomationDateText($anchor),
                ':next_run_at' => $next ? apiDomainAutomationDateText($next) : null,
            ];
            if ($existing) {
                $stmt = $pdo->prepare("UPDATE cainiao_api_domain_automation_group SET
                    name=:name,cloud_account_id=:cloud_account_id,usage_scope=:usage_scope,enabled=:enabled,
                    environment=:environment,region=:region,domain_provider=:domain_provider,
                    certificate_provider=:certificate_provider,generation_enabled=:generation_enabled,
                    target_active_count=:target_active_count,minimum_healthy_count=:minimum_healthy_count,
                    interval_value=:interval_value,interval_unit=:interval_unit,generate_count=:generate_count,
                    observation_days=:observation_days,idle_mark_days=:idle_mark_days,cleanup_enabled=:cleanup_enabled,
                    cleanup_no_access_days=:cleanup_no_access_days,schedule_anchor_at=:schedule_anchor_at,
                    next_run_at=:next_run_at,adapter_state='waiting_adapter'
                    WHERE id=:id AND deleted_at IS NULL");
                $stmt->execute($params + [':id' => $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO cainiao_api_domain_automation_group
                    (name,cloud_account_id,usage_scope,environment,region,domain_provider,certificate_provider,
                     enabled,generation_enabled,target_active_count,minimum_healthy_count,
                     interval_value,interval_unit,generate_count,observation_days,idle_mark_days,cleanup_enabled,
                     cleanup_no_access_days,adapter_state,schedule_anchor_at,next_run_at)
                    VALUES (:name,:cloud_account_id,:usage_scope,:environment,:region,:domain_provider,
                     :certificate_provider,:enabled,:generation_enabled,:target_active_count,:minimum_healthy_count,
                     :interval_value,:interval_unit,:generate_count,:observation_days,:idle_mark_days,:cleanup_enabled,
                     :cleanup_no_access_days,'waiting_adapter',:schedule_anchor_at,:next_run_at)");
                $stmt->execute($params);
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
            return [
                'message' => $row['enabled']
                    ? '池组策略已保存并排程，AWS 实际生成等待适配器对接'
                    : '池组策略已保存，当前未启用调度',
                'id' => $id,
                'next_run_at' => $next ? apiDomainAutomationDateText($next) : null,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}

if (!function_exists('apiDomainAutomationDeleteGroup')) {
    function apiDomainAutomationDeleteGroup(PDO $pdo, int $id): array
    {
        ensureApiDomainAutomationSchema($pdo);
        $stmt = $pdo->prepare("UPDATE cainiao_api_domain_automation_group
            SET enabled=0,next_run_at=NULL,deleted_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)
            WHERE id=:id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() <= 0) throw new RuntimeException('自动化池组不存在');
        return ['message' => '自动化池组已归档，历史批次与节点记录保留'];
    }
}

if (!function_exists('apiDomainAutomationLock')) {
    function apiDomainAutomationLock(PDO $pdo, string $name): bool
    {
        $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
        $stmt->execute([':lock_name' => substr($name, 0, 64)]);
        return (int)$stmt->fetchColumn() === 1;
    }
}

if (!function_exists('apiDomainAutomationUnlock')) {
    function apiDomainAutomationUnlock(PDO $pdo, string $name): void
    {
        try {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $stmt->execute([':lock_name' => substr($name, 0, 64)]);
        } catch (Throwable $ignored) {
        }
    }
}

if (!function_exists('apiDomainAutomationEffectiveIsolationGate')) {
    /**
     * 使用真实下发的前 24 节点评估隔离影响。
     *
     * 候选节点本来就不在当前运行集合时，隔离不会减少壳的当前入口；
     * 在集合内时，才按 config/report/click 用途逐一检查移除后保底数。
     */
    function apiDomainAutomationEffectiveIsolationGate(
        PDO $pdo,
        int $nodeId,
        string $usageScope,
        int $minimum
    ): array {
        $effectiveRows = function_exists('configDeliveryEnabledApiDomainRows')
            ? configDeliveryEnabledApiDomainRows($pdo)
            : $pdo->query("SELECT id,name,base_url,usage_scope,priority,updated_at
                FROM cainiao_api_domain_pool WHERE enabled=1
                ORDER BY priority DESC,id ASC LIMIT 24")->fetchAll(PDO::FETCH_ASSOC);
        $candidateIsEffective = false;
        $remainingRows = [];
        foreach ($effectiveRows as $effectiveRow) {
            if ((int)($effectiveRow['id'] ?? 0) === $nodeId) {
                $candidateIsEffective = true;
                continue;
            }
            $remainingRows[] = $effectiveRow;
        }
        if (!$candidateIsEffective) {
            return ['allowed' => true, 'candidate_effective' => false, 'scope_counts' => []];
        }

        $affectedScopes = $usageScope === 'all'
            ? ['config', 'report', 'click']
            : [$usageScope];
        $scopeCounts = [];
        foreach ($affectedScopes as $affectedScope) {
            if (function_exists('configDeliveryApiDomainRowsForScope')) {
                $scopeRows = configDeliveryApiDomainRowsForScope($remainingRows, $affectedScope);
            } else {
                $scopeRows = array_values(array_filter(
                    $remainingRows,
                    static function (array $effectiveRow) use ($affectedScope): bool {
                        $rowScope = (string)($effectiveRow['usage_scope'] ?? 'config');
                        return $rowScope === 'all' || $rowScope === $affectedScope;
                    }
                ));
            }
            $scopeCounts[$affectedScope] = count($scopeRows);
            if ($scopeCounts[$affectedScope] < $minimum) {
                return [
                    'allowed' => false,
                    'candidate_effective' => true,
                    'scope_counts' => $scopeCounts,
                ];
            }
        }
        return ['allowed' => true, 'candidate_effective' => true, 'scope_counts' => $scopeCounts];
    }
}

if (!function_exists('apiDomainAutomationHealthyEffectiveCount')) {
    /** 健康保底只统计真实前 24 运行集合中已验证且 active 的自动节点。 */
    function apiDomainAutomationHealthyEffectiveCount(PDO $pdo, int $groupId): int
    {
        $effectiveRows = function_exists('configDeliveryEnabledApiDomainRows')
            ? configDeliveryEnabledApiDomainRows($pdo)
            : $pdo->query("SELECT id,name,base_url,usage_scope,priority,updated_at
                FROM cainiao_api_domain_pool WHERE enabled=1
                ORDER BY priority DESC,id ASC LIMIT 24")->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        foreach ($effectiveRows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        if (!$ids) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_pool
            WHERE id IN ({$placeholders}) AND automation_group_id=? AND origin='aws_auto'
              AND enabled=1 AND lifecycle_status='active' AND verified_at IS NOT NULL");
        $params = $ids;
        $params[] = $groupId;
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('apiDomainAutomationRefreshLifecycle')) {
    /**
     * 刷新单个池组的观察、无访问标记与待清理隔离。
     *
     * ok_count 才代表真实成功访问；有任意成功访问、pinned、reserved
     * 或存量 cleanup_protected 标记的节点始终排除在清理外。unused_marked
     * 只标记；进入 cleanup_pending 时才禁用本地分发。当 dryRun=true 时
     * 只返回预计数，全程不修改节点。
     */
    function apiDomainAutomationRefreshLifecycle(
        PDO $pdo,
        array $group,
        DateTimeImmutable $now,
        bool $dryRun = false
    ): array
    {
        $groupId = (int)$group['id'];
        $stmt = $pdo->prepare("SELECT p.*,
                COALESCE(SUM(s.ok_count),0) AS lifetime_access_count,
                MAX(CASE WHEN s.ok_count>0 THEN s.last_seen_at ELSE NULL END) AS aggregated_last_access_at
            FROM cainiao_api_domain_pool p
            LEFT JOIN cainiao_api_domain_stats s ON s.domain_pool_id=p.id
            WHERE p.automation_group_id=:group_id AND p.origin='aws_auto'
            GROUP BY p.id
            ORDER BY p.id ASC");
        $stmt->execute([':group_id' => $groupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 容量统计纳入 pending_verification 和 unused_marked，避免适配器
        // 验证期间或标记期间重复超发。健康保底则另行严格统计。
        $eligibleCount = 0;
        foreach ($rows as $row) {
            $state = (string)($row['lifecycle_status'] ?? 'active');
            if ($state === 'pending_verification'
                || ((int)$row['enabled'] === 1 && in_array($state, ['active', 'unused_marked'], true))) {
                $eligibleCount++;
            }
        }
        $minimum = max(1, (int)$group['minimum_healthy_count']);
        $healthyCount = apiDomainAutomationHealthyEffectiveCount($pdo, $groupId);
        $effectiveRowsForHealth = function_exists('configDeliveryEnabledApiDomainRows')
            ? configDeliveryEnabledApiDomainRows($pdo)
            : [];
        $effectiveNodeIds = [];
        foreach ($effectiveRowsForHealth as $effectiveRowForHealth) {
            $effectiveNodeIds[(int)($effectiveRowForHealth['id'] ?? 0)] = true;
        }
        $marked = 0;
        $cleanupPending = 0;
        $protected = 0;
        $distributionChanged = false;

        $markStmt = $pdo->prepare("UPDATE cainiao_api_domain_pool
            SET lifecycle_status='unused_marked',idle_marked_at=:now_text,
                lifecycle_updated_at=:now_text,cleanup_reason='no_successful_access'
            WHERE id=:id AND origin='aws_auto' AND lifecycle_status='active'
              AND cleanup_protected=0 AND pinned=0 AND reserved=0");
        $restoreStmt = $pdo->prepare("UPDATE cainiao_api_domain_pool
            SET enabled=1,lifecycle_status='active',idle_marked_at=NULL,
                cleanup_requested_at=NULL,lifecycle_updated_at=:now_text,
                cleanup_reason='',cloud_cleanup_state='not_required'
            WHERE id=:id AND origin='aws_auto'
              AND lifecycle_status IN ('unused_marked','cleanup_pending')");
        $activateStmt = $pdo->prepare("UPDATE cainiao_api_domain_pool
            SET enabled=1,lifecycle_status='active',eligible_at=COALESCE(eligible_at,:now_text),
                lifecycle_updated_at=:now_text
            WHERE id=:id AND origin='aws_auto' AND lifecycle_status='pending_verification'");
        $accessStmt = $pdo->prepare("UPDATE cainiao_api_domain_pool
            SET access_count=:access_count,last_access_at=:last_access_at
            WHERE id=:id AND origin='aws_auto'");
        $cleanupStmt = $pdo->prepare("UPDATE cainiao_api_domain_pool AS p SET p.enabled=0,
            lifecycle_status='cleanup_pending',cleanup_requested_at=:now_text,
            lifecycle_updated_at=:now_text,cleanup_reason='no_successful_access',
            cloud_cleanup_state='waiting_adapter'
            WHERE p.id=:id AND p.origin='aws_auto' AND p.cleanup_protected=0
              AND p.pinned=0 AND p.reserved=0 AND p.enabled=1
              AND p.lifecycle_status='unused_marked'
              AND NOT EXISTS (
                SELECT 1 FROM cainiao_api_domain_stats s
                WHERE s.domain_pool_id=p.id AND s.ok_count>0
              )");
        $nowText = apiDomainAutomationDateText($now);
        $parseDate = static function ($value, DateTimeImmutable $fallback): DateTimeImmutable {
            $text = trim((string)$value);
            if ($text === '') return $fallback;
            try {
                return new DateTimeImmutable($text, apiDomainAutomationTimezone());
            } catch (Throwable $ignored) {
                return $fallback;
            }
        };

        foreach ($rows as $row) {
            $nodeId = (int)$row['id'];
            $state = (string)($row['lifecycle_status'] ?? 'active');
            if (in_array($state, ['archived', 'cleanup_failed'], true)) continue;

            $lifetimeAccess = max(
                (int)($row['access_count'] ?? 0),
                (int)($row['lifetime_access_count'] ?? 0)
            );
            $aggregatedLastAccess = $row['aggregated_last_access_at'] ?? null;
            if (!$dryRun && ($lifetimeAccess !== (int)($row['access_count'] ?? 0)
                || ($aggregatedLastAccess && $aggregatedLastAccess !== ($row['last_access_at'] ?? null)))) {
                $accessStmt->execute([
                    ':access_count' => $lifetimeAccess,
                    ':last_access_at' => $aggregatedLastAccess,
                    ':id' => $nodeId,
                ]);
            }

            $hasProtection = (int)($row['cleanup_protected'] ?? 1) === 1
                || (int)($row['pinned'] ?? 0) === 1
                || (int)($row['reserved'] ?? 0) === 1;
            if ($lifetimeAccess > 0 || $hasProtection) {
                $protected++;
                if ($lifetimeAccess > 0 && in_array($state, ['unused_marked', 'cleanup_pending'], true)) {
                    if ($state === 'cleanup_pending') {
                        $eligibleCount++;
                        if (!$dryRun) $distributionChanged = true;
                    }
                    if ($state === 'unused_marked'
                        && !empty($row['verified_at'])
                        && isset($effectiveNodeIds[$nodeId])) {
                        $healthyCount++;
                    }
                    if (!$dryRun) $restoreStmt->execute([':now_text' => $nowText, ':id' => $nodeId]);
                }
                continue;
            }

            $createdAt = $parseDate($row['created_at'] ?? '', $now);
            $observationUntil = $parseDate(
                $row['observation_until'] ?? '',
                $createdAt->modify('+' . max(0, (int)($group['observation_days'] ?? 1)) . ' days')
            );
            if ($state === 'pending_verification') {
                $verified = !empty($row['verified_at']);
                if ($verified && $observationUntil <= $now) {
                    $state = 'active';
                    if (isset($effectiveNodeIds[$nodeId])) $healthyCount++;
                    if (!$dryRun) {
                        $activateStmt->execute([':now_text' => $nowText, ':id' => $nodeId]);
                        if ($activateStmt->rowCount() > 0) $distributionChanged = true;
                    }
                } else {
                    $protected++;
                    continue;
                }
            }

            if ($observationUntil > $now) {
                $protected++;
                continue;
            }

            $eligibleAt = $parseDate($row['eligible_at'] ?? '', $observationUntil);
            $ageDays = max(0, intdiv($now->getTimestamp() - $eligibleAt->getTimestamp(), 86400));
            $wasAlreadyMarked = $state === 'unused_marked' && !empty($row['idle_marked_at']);
            if ($ageDays >= (int)$group['idle_mark_days'] && $state === 'active') {
                $marked++;
                if (!empty($row['verified_at']) && isset($effectiveNodeIds[$nodeId])) {
                    $healthyCount = max(0, $healthyCount - 1);
                }
                if (!$dryRun) {
                    $markStmt->execute([':now_text' => $nowText, ':id' => $nodeId]);
                    if ($markStmt->rowCount() === 0) $marked--;
                }
                // 标记与隔离必须是两个独立阶段，本轮新标记不紧接着隔离。
                continue;
            }

            if (empty($group['cleanup_enabled']) || $ageDays < (int)$group['cleanup_no_access_days']) continue;
            if (!$wasAlreadyMarked || $healthyCount < $minimum) {
                $protected++;
                continue;
            }
            $scope = (string)$group['usage_scope'];
            $globalGate = apiDomainAutomationEffectiveIsolationGate($pdo, $nodeId, $scope, $minimum);
            if (empty($globalGate['allowed'])) {
                $protected++;
                continue;
            }
            if ($dryRun) {
                $cleanupPending++;
                $eligibleCount--;
                continue;
            }
            $cleanupStmt->execute([':now_text' => $nowText, ':id' => $nodeId]);
            if ($cleanupStmt->rowCount() > 0) {
                $cleanupPending++;
                $eligibleCount--;
                $distributionChanged = true;
            } else {
                // 成功访问可能在扫描期间刚好入库，竞态时优先保护节点。
                $protected++;
            }
        }
        return [
            'marked_count' => $marked,
            'cleanup_pending_count' => $cleanupPending,
            'archived_count' => 0,
            'protected_count' => $protected,
            'eligible_count' => $eligibleCount,
            'capacity_count' => $eligibleCount,
            'healthy_count' => $healthyCount,
            'projected_marked_count' => $dryRun ? $marked : 0,
            'projected_cleanup_pending_count' => $dryRun ? $cleanupPending : 0,
            'distribution_changed' => $distributionChanged,
            'dry_run' => $dryRun ? 1 : 0,
        ];
    }
}

if (!function_exists('apiDomainAutomationCreateBatch')) {
    /**
     * 创建不可变的生成计划批次。
     *
     * 批次号固定为 YYYY-MM-全局自增序号；月份只是归档维度，
     * 不会创建新的物理池组。
     */
    function apiDomainAutomationCreateBatch(
        PDO $pdo,
        int $groupId,
        string $triggerType,
        int $plannedCount,
        array $lifecycle,
        string $status,
        string $message,
        DateTimeImmutable $now,
        bool $dryRun = false,
        int $currentEligibleCount = 0,
        int $capacityGap = 0
    ): array {
        $temporaryCode = 'pending-' . bin2hex(random_bytes(12));
        $stmt = $pdo->prepare("INSERT INTO cainiao_api_domain_automation_batch
            (group_id,batch_code,period_key,trigger_type,planned_count,created_count,
             period_sequence,dry_run,current_eligible_count,capacity_gap,
             marked_count,archived_count,cleanup_pending_count,protected_count,
             status,message,started_at,finished_at)
            VALUES (:group_id,:batch_code,:period_key,:trigger_type,:planned_count,0,
             0,:dry_run,:current_eligible_count,:capacity_gap,
             :marked_count,0,:cleanup_pending_count,:protected_count,
             :status,:message,:started_at,:finished_at)");
        $nowText = apiDomainAutomationDateText($now);
        $stmt->execute([
            ':group_id' => $groupId, ':batch_code' => $temporaryCode,
            ':period_key' => $now->format('Y-m'), ':trigger_type' => $triggerType,
            ':planned_count' => $plannedCount,
            ':dry_run' => $dryRun ? 1 : 0,
            ':current_eligible_count' => max(0, $currentEligibleCount),
            ':capacity_gap' => max(0, $capacityGap),
            ':marked_count' => (int)($lifecycle['marked_count'] ?? 0),
            ':cleanup_pending_count' => (int)($lifecycle['cleanup_pending_count'] ?? 0),
            ':protected_count' => (int)($lifecycle['protected_count'] ?? 0),
            ':status' => $status, ':message' => apiDomainAutomationLimitText($message, 255),
            ':started_at' => $nowText, ':finished_at' => $nowText,
        ]);
        $batchId = (int)$pdo->lastInsertId();
        $batchCode = sprintf('%s-%06d', $now->format('Y-m'), $batchId);
        $update = $pdo->prepare('UPDATE cainiao_api_domain_automation_batch
            SET batch_code=:batch_code,period_sequence=:period_sequence WHERE id=:id');
        $update->execute([
            ':batch_code' => $batchCode,
            ':period_sequence' => $batchId,
            ':id' => $batchId,
        ]);
        return [
            'id' => $batchId,
            'batch_code' => $batchCode,
            'period_key' => $now->format('Y-m'),
            'period_sequence' => $batchId,
        ];
    }
}

if (!function_exists('apiDomainAutomationCreateRunRecord')) {
    /** 运行记录与生成批次分离，容量已满、暂停和 dry-run 也有审计事实。 */
    function apiDomainAutomationCreateRunRecord(
        PDO $pdo,
        int $groupId,
        ?int $batchId,
        ?int $retryOfRunId,
        string $triggerType,
        bool $dryRun,
        string $status,
        int $currentEligibleCount,
        int $capacityGap,
        int $plannedCount,
        array $lifecycle,
        string $message,
        DateTimeImmutable $now
    ): int {
        $stmt = $pdo->prepare("INSERT INTO cainiao_api_domain_automation_run
            (group_id,batch_id,retry_of_run_id,trigger_type,dry_run,status,
             current_eligible_count,capacity_gap,planned_count,created_count,
             marked_count,cleanup_pending_count,protected_count,message,started_at,finished_at)
            VALUES (:group_id,:batch_id,:retry_of_run_id,:trigger_type,:dry_run,:status,
             :current_eligible_count,:capacity_gap,:planned_count,0,
             :marked_count,:cleanup_pending_count,:protected_count,:message,:started_at,:finished_at)");
        $nowText = apiDomainAutomationDateText($now);
        $stmt->execute([
            ':group_id' => $groupId,
            ':batch_id' => $batchId,
            ':retry_of_run_id' => $retryOfRunId,
            ':trigger_type' => $triggerType,
            ':dry_run' => $dryRun ? 1 : 0,
            ':status' => $status,
            ':current_eligible_count' => max(0, $currentEligibleCount),
            ':capacity_gap' => max(0, $capacityGap),
            ':planned_count' => max(0, $plannedCount),
            ':marked_count' => (int)($lifecycle['marked_count'] ?? 0),
            ':cleanup_pending_count' => (int)($lifecycle['cleanup_pending_count'] ?? 0),
            ':protected_count' => (int)($lifecycle['protected_count'] ?? 0),
            ':message' => apiDomainAutomationLimitText($message, 255),
            ':started_at' => $nowText,
            ':finished_at' => $nowText,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('apiDomainAutomationRunGroup')) {
    /** 执行一轮容量检查、生命周期刷新和待对接计划。 */
    function apiDomainAutomationRunGroup(
        PDO $pdo,
        int $groupId,
        string $triggerType = 'manual',
        bool $dryRun = false,
        ?int $retryOfRunId = null
    ): array
    {
        ensureApiDomainAutomationSchema($pdo);
        if (!in_array($triggerType, ['manual', 'scheduled', 'retry'], true)) {
            throw new InvalidArgumentException('调度触发类型错误');
        }
        // 所有池组严格按“全局生命周期锁 -> 池组锁”取锁，
        // 防止两个手动运行同时通过全局最低健康门禁。
        $lifecycleLock = 'yunzhuru_api_domain_lifecycle';
        if (!apiDomainAutomationLock($pdo, $lifecycleLock)) {
            return ['status' => 'skipped', 'message' => '全局生命周期计划正在执行', 'skipped' => 1];
        }
        $lockName = 'yunzhuru_api_auto_group_' . $groupId;
        if (!apiDomainAutomationLock($pdo, $lockName)) {
            apiDomainAutomationUnlock($pdo, $lifecycleLock);
            return ['status' => 'skipped', 'message' => '该池组已有调度在执行', 'skipped' => 1];
        }
        $distributionChanged = false;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT g.*,a.enabled AS account_enabled,a.deleted_at AS account_deleted_at
                FROM cainiao_api_domain_automation_group g
                LEFT JOIN cainiao_api_domain_cloud_account a ON a.id=g.cloud_account_id
                WHERE g.id=:id AND g.deleted_at IS NULL FOR UPDATE');
            $stmt->execute([':id' => $groupId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) throw new RuntimeException('自动化池组不存在');
            if ((int)$group['enabled'] !== 1 && !$dryRun) {
                $pdo->commit();
                return [
                    'status' => 'skipped',
                    'message' => '自动化池组已暂停，本轮跳过',
                    'group_id' => $groupId,
                    'skipped' => 1,
                ];
            }
            if ((int)($group['account_enabled'] ?? 0) !== 1 || !empty($group['account_deleted_at'])) {
                throw new RuntimeException('所选 AWS 账号已停用或已归档');
            }

            $now = apiDomainAutomationNow();
            // due worker 只是预选；获取行锁后必须再查时钟，
            // 避免管理员刚编辑计划或暂停后仍提前执行。
            if ($triggerType === 'scheduled') {
                $scheduledFor = trim((string)($group['next_run_at'] ?? ''));
                if ($scheduledFor === ''
                    || new DateTimeImmutable($scheduledFor, apiDomainAutomationTimezone()) > $now) {
                    $pdo->commit();
                    return [
                        'status' => 'skipped',
                        'message' => '调度时间已被更新，本轮不提前执行',
                        'group_id' => $groupId,
                        'skipped' => 1,
                    ];
                }
            }

            $lifecycle = apiDomainAutomationRefreshLifecycle($pdo, $group, $now, $dryRun);
            $distributionChanged = !empty($lifecycle['distribution_changed']);
            $currentEligible = (int)$lifecycle['eligible_count'];
            $gap = max(0, (int)$group['target_active_count'] - $currentEligible);
            $generationEnabled = (int)($group['generation_enabled'] ?? $group['enabled']) === 1;
            $planned = $generationEnabled ? min((int)$group['generate_count'], $gap) : 0;
            if ($dryRun) {
                $status = 'dry_run';
                $message = "Dry-run 预览：当前可用 {$currentEligible} 个，缺口 {$gap} 个，计划补 {$planned} 个";
            } elseif ($planned > 0) {
                $status = 'waiting_adapter';
                $message = "当前缺口 {$gap} 个，本轮计划生成 {$planned} 个；AWS 适配器待对接";
            } elseif ((int)$lifecycle['cleanup_pending_count'] > 0) {
                $status = 'succeeded';
                $message = '无访问节点已进入待清理并从本地分发隔离，云资源等待适配器处理';
            } elseif (!$generationEnabled) {
                $status = 'skipped';
                $message = '自动生成已暂停，本轮仅执行生命周期检查';
            } else {
                $status = 'skipped';
                $message = '当前容量已达目标，本轮跳过生成';
            }
            $batch = null;
            if ($planned > 0) {
                $batch = apiDomainAutomationCreateBatch(
                    $pdo,
                    $groupId,
                    $triggerType,
                    $planned,
                    $lifecycle,
                    $status,
                    $message,
                    $now,
                    $dryRun,
                    $currentEligible,
                    $gap
                );
            }
            $runId = apiDomainAutomationCreateRunRecord(
                $pdo,
                $groupId,
                $batch ? (int)$batch['id'] : null,
                $retryOfRunId,
                $triggerType,
                $dryRun,
                $status,
                $currentEligible,
                $gap,
                $planned,
                $lifecycle,
                $message,
                $now
            );

            $nextRun = null;
            if ($triggerType === 'scheduled') {
                $anchor = new DateTimeImmutable((string)$group['schedule_anchor_at'], apiDomainAutomationTimezone());
                $nextRun = apiDomainAutomationNextRun(
                    $anchor,
                    (int)$group['interval_value'],
                    (string)$group['interval_unit'],
                    $now
                );
            } elseif (!empty($group['next_run_at'])) {
                $nextRun = new DateTimeImmutable((string)$group['next_run_at'], apiDomainAutomationTimezone());
            }
            if (!$dryRun) {
                $update = $pdo->prepare("UPDATE cainiao_api_domain_automation_group SET
                    last_run_at=:last_run_at,last_run_status=:last_run_status,
                    last_run_message=:last_run_message,next_run_at=:next_run_at,
                    adapter_state='waiting_adapter' WHERE id=:id");
                $update->execute([
                    ':last_run_at' => apiDomainAutomationDateText($now),
                    ':last_run_status' => $status,
                    ':last_run_message' => apiDomainAutomationLimitText($message, 255),
                    ':next_run_at' => $nextRun ? apiDomainAutomationDateText($nextRun) : null,
                    ':id' => $groupId,
                ]);
            }
            $pdo->commit();

            if ($distributionChanged && function_exists('configDeliveryInvalidateAndSync')) {
                configDeliveryInvalidateAndSync($pdo);
            }
            return [
                'message' => $message,
                'status' => $status,
                'group_id' => $groupId,
                'run_id' => $runId,
                'batch_id' => $batch ? (int)$batch['id'] : null,
                'batch_code' => $batch ? (string)$batch['batch_code'] : null,
                'dry_run' => $dryRun ? 1 : 0,
                'current_eligible_count' => $currentEligible,
                'capacity_gap' => $gap,
                'planned_count' => $planned,
                'created_count' => 0,
                'marked_count' => (int)$lifecycle['marked_count'],
                'cleanup_pending_count' => (int)$lifecycle['cleanup_pending_count'],
                'archived_count' => 0,
                'protected_count' => (int)$lifecycle['protected_count'],
                'next_run_at' => $nextRun ? apiDomainAutomationDateText($nextRun) : null,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            apiDomainAutomationUnlock($pdo, $lockName);
            apiDomainAutomationUnlock($pdo, $lifecycleLock);
        }
    }
}

if (!function_exists('apiDomainAutomationDryRunGroup')) {
    /** 只生成容量与清理计划，不改节点状态或现有调度时钟。 */
    function apiDomainAutomationDryRunGroup(PDO $pdo, int $groupId): array
    {
        return apiDomainAutomationRunGroup($pdo, $groupId, 'manual', true);
    }
}

if (!function_exists('apiDomainAutomationSetGroupEnabled')) {
    /** 暂停或恢复稳定池组；历史批次、运行和节点不受影响。 */
    function apiDomainAutomationSetGroupEnabled(PDO $pdo, int $groupId, bool $enabled): array
    {
        ensureApiDomainAutomationSchema($pdo);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT schedule_anchor_at,interval_value,interval_unit
                FROM cainiao_api_domain_automation_group
                WHERE id=:id AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([':id' => $groupId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) throw new RuntimeException('自动化池组不存在');
            $nextRun = null;
            if ($enabled) {
                $anchor = new DateTimeImmutable(
                    (string)$group['schedule_anchor_at'],
                    apiDomainAutomationTimezone()
                );
                $nextRun = apiDomainAutomationNextRun(
                    $anchor,
                    (int)$group['interval_value'],
                    (string)$group['interval_unit'],
                    apiDomainAutomationNow()
                );
            }
            $update = $pdo->prepare('UPDATE cainiao_api_domain_automation_group
                SET enabled=:enabled,next_run_at=:next_run_at WHERE id=:id AND deleted_at IS NULL');
            $update->execute([
                ':enabled' => $enabled ? 1 : 0,
                ':next_run_at' => $nextRun ? apiDomainAutomationDateText($nextRun) : null,
                ':id' => $groupId,
            ]);
            $pdo->commit();
            return [
                'message' => $enabled ? '池组已恢复调度' : '池组已暂停调度',
                'id' => $groupId,
                'enabled' => $enabled ? 1 : 0,
                'next_run_at' => $nextRun ? apiDomainAutomationDateText($nextRun) : null,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}

if (!function_exists('apiDomainAutomationRetryRun')) {
    /** 重试会新建 run／batch，旧记录维持不变；dry-run 语义随原运行保留。 */
    function apiDomainAutomationRetryRun(PDO $pdo, int $runId): array
    {
        ensureApiDomainAutomationSchema($pdo);
        $stmt = $pdo->prepare('SELECT group_id,dry_run FROM cainiao_api_domain_automation_run WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) throw new RuntimeException('自动化运行记录不存在');
        return apiDomainAutomationRunGroup(
            $pdo,
            (int)$run['group_id'],
            'retry',
            (int)$run['dry_run'] === 1,
            $runId
        );
    }
}

if (!function_exists('apiDomainAutomationSetNodeProtection')) {
    /** 设置自动节点的钉住／保留标记，手工节点不属于此合同。 */
    function apiDomainAutomationSetNodeProtection(
        PDO $pdo,
        int $nodeId,
        bool $pinned,
        bool $reserved
    ): array {
        ensureApiDomainAutomationSchema($pdo);
        $lifecycleLock = 'yunzhuru_api_domain_lifecycle';
        if (!apiDomainAutomationLock($pdo, $lifecycleLock)) {
            return ['status' => 'skipped', 'message' => '全局生命周期计划正在执行'];
        }
        $lookup = $pdo->prepare('SELECT automation_group_id FROM cainiao_api_domain_pool WHERE id=:id LIMIT 1');
        $lookup->execute([':id' => $nodeId]);
        $groupId = (int)$lookup->fetchColumn();
        if ($groupId <= 0) {
            apiDomainAutomationUnlock($pdo, $lifecycleLock);
            throw new RuntimeException('自动生成节点不存在');
        }
        $groupLock = 'yunzhuru_api_auto_group_' . $groupId;
        if (!apiDomainAutomationLock($pdo, $groupLock)) {
            apiDomainAutomationUnlock($pdo, $lifecycleLock);
            return ['status' => 'skipped', 'message' => '该池组已有计划在执行'];
        }
        $distributionChanged = false;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id,origin,lifecycle_status,enabled
                FROM cainiao_api_domain_pool WHERE id=:id FOR UPDATE');
            $stmt->execute([':id' => $nodeId]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$node || (string)$node['origin'] !== 'aws_auto') {
                throw new RuntimeException('自动生成节点不存在');
            }
            $restore = ($pinned || $reserved)
                && in_array((string)$node['lifecycle_status'], ['unused_marked', 'cleanup_pending'], true);
            $update = $pdo->prepare("UPDATE cainiao_api_domain_pool SET
                pinned=:pinned,reserved=:reserved,
                enabled=IF(:restore_enabled=1,1,enabled),
                lifecycle_status=IF(:restore_state=1,'active',lifecycle_status),
                idle_marked_at=IF(:restore_marker=1,NULL,idle_marked_at),
                cleanup_requested_at=IF(:restore_cleanup=1,NULL,cleanup_requested_at),
                cleanup_reason=IF(:restore_reason=1,'',cleanup_reason),
                cloud_cleanup_state=IF(:restore_cloud=1,'not_required',cloud_cleanup_state),
                lifecycle_updated_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)
                WHERE id=:id AND origin='aws_auto'");
            $update->execute([
                ':pinned' => $pinned ? 1 : 0,
                ':reserved' => $reserved ? 1 : 0,
                ':restore_enabled' => $restore ? 1 : 0,
                ':restore_state' => $restore ? 1 : 0,
                ':restore_marker' => $restore ? 1 : 0,
                ':restore_cleanup' => $restore ? 1 : 0,
                ':restore_reason' => $restore ? 1 : 0,
                ':restore_cloud' => $restore ? 1 : 0,
                ':id' => $nodeId,
            ]);
            $distributionChanged = $restore && (int)$node['enabled'] !== 1;
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        } finally {
            apiDomainAutomationUnlock($pdo, $groupLock);
            apiDomainAutomationUnlock($pdo, $lifecycleLock);
        }
        if ($distributionChanged && function_exists('configDeliveryInvalidateAndSync')) {
            configDeliveryInvalidateAndSync($pdo);
        }
        return [
            'message' => '节点保护状态已更新',
            'id' => $nodeId,
            'pinned' => $pinned ? 1 : 0,
            'reserved' => $reserved ? 1 : 0,
        ];
    }
}

if (!function_exists('apiDomainAutomationRequestNodeCleanup')) {
    /**
     * 将已标记的自动节点转入待清理并从本地分发隔离。
     *
     * 该方法为旧域名表的“删除”操作提供安全转换：只接受
     * origin=aws_auto 且先前已处于 unused_marked 的节点。云资源仅记录
     * waiting_adapter，未来适配器确认后再推进 archived/cleanup_failed。
     */
    function apiDomainAutomationRequestNodeCleanup(
        PDO $pdo,
        int $nodeId,
        string $triggerType = 'manual'
    ): array {
        ensureApiDomainAutomationSchema($pdo);
        if (!in_array($triggerType, ['manual', 'scheduled', 'retry'], true)) {
            throw new InvalidArgumentException('清理触发类型错误');
        }
        $lifecycleLock = 'yunzhuru_api_domain_lifecycle';
        if (!apiDomainAutomationLock($pdo, $lifecycleLock)) {
            return ['status' => 'skipped', 'message' => '全局生命周期计划正在执行'];
        }
        $groupLock = null;
        $distributionChanged = false;
        try {
            $lookup = $pdo->prepare('SELECT automation_group_id FROM cainiao_api_domain_pool WHERE id=:id LIMIT 1');
            $lookup->execute([':id' => $nodeId]);
            $groupId = (int)$lookup->fetchColumn();
            if ($groupId <= 0) throw new RuntimeException('自动生成节点不存在');
            $groupLock = 'yunzhuru_api_auto_group_' . $groupId;
            if (!apiDomainAutomationLock($pdo, $groupLock)) {
                return ['status' => 'skipped', 'message' => '该池组已有计划在执行'];
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT p.*,g.minimum_healthy_count,g.usage_scope,g.target_active_count
                FROM cainiao_api_domain_pool p
                JOIN cainiao_api_domain_automation_group g ON g.id=p.automation_group_id
                WHERE p.id=:id AND g.deleted_at IS NULL FOR UPDATE");
            $stmt->execute([':id' => $nodeId]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$node || (string)$node['origin'] !== 'aws_auto') {
                throw new RuntimeException('自动生成节点不存在');
            }
            if ((string)$node['lifecycle_status'] === 'cleanup_pending') {
                $pdo->commit();
                return [
                    'status' => 'cleanup_pending',
                    'message' => '节点已在待清理队列',
                    'id' => $nodeId,
                ];
            }
            if ((string)$node['lifecycle_status'] !== 'unused_marked') {
                throw new RuntimeException('请先在节点视图标记无访问，再提交清理');
            }
            if ((int)$node['cleanup_protected'] === 1
                || (int)$node['pinned'] === 1
                || (int)$node['reserved'] === 1) {
                throw new RuntimeException('节点已设为保护或保留');
            }
            $access = $pdo->prepare('SELECT COALESCE(SUM(ok_count),0)
                FROM cainiao_api_domain_stats WHERE domain_pool_id=:id');
            $access->execute([':id' => $nodeId]);
            if ((int)$access->fetchColumn() > 0 || (int)$node['access_count'] > 0) {
                throw new RuntimeException('节点已有真实成功访问，继续保留');
            }
            $eligible = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_pool
                WHERE automation_group_id=:group_id AND origin='aws_auto' AND enabled=1
                  AND lifecycle_status IN ('active','unused_marked')");
            $eligible->execute([':group_id' => $groupId]);
            $currentEligible = (int)$eligible->fetchColumn();
            $minimum = max(1, (int)$node['minimum_healthy_count']);
            $healthyCount = apiDomainAutomationHealthyEffectiveCount($pdo, $groupId);
            if ($healthyCount < $minimum) {
                throw new RuntimeException('此节点属于池组最低健康保底集合');
            }
            $globalGate = apiDomainAutomationEffectiveIsolationGate(
                $pdo,
                $nodeId,
                (string)$node['usage_scope'],
                $minimum
            );
            if (empty($globalGate['allowed'])) {
                throw new RuntimeException('隔离后会低于当前运行集合的用途保底数');
            }

            $now = apiDomainAutomationNow();
            $nowText = apiDomainAutomationDateText($now);
            $update = $pdo->prepare("UPDATE cainiao_api_domain_pool AS p SET p.enabled=0,
                lifecycle_status='cleanup_pending',cleanup_requested_at=:now_text,
                lifecycle_updated_at=:now_text,cleanup_reason='manual_cleanup_request',
                cloud_cleanup_state='waiting_adapter'
                WHERE p.id=:id AND p.origin='aws_auto' AND p.lifecycle_status='unused_marked'
                  AND p.cleanup_protected=0 AND p.pinned=0 AND p.reserved=0
                  AND NOT EXISTS (SELECT 1 FROM cainiao_api_domain_stats s
                    WHERE s.domain_pool_id=p.id AND s.ok_count>0)");
            $update->execute([':now_text' => $nowText, ':id' => $nodeId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('节点状态已变化，请刷新后再操作');
            }
            $gap = max(0, (int)$node['target_active_count'] - ($currentEligible - 1));
            $lifecycle = [
                'marked_count' => 0,
                'cleanup_pending_count' => 1,
                'protected_count' => 0,
            ];
            $runId = apiDomainAutomationCreateRunRecord(
                $pdo,
                $groupId,
                null,
                null,
                $triggerType,
                false,
                'cleanup_pending',
                $currentEligible - 1,
                $gap,
                0,
                $lifecycle,
                '手动请求已转为本地隔离和待清理计划',
                $now
            );
            $pdo->commit();
            $distributionChanged = true;
            if ($distributionChanged && function_exists('configDeliveryInvalidateAndSync')) {
                configDeliveryInvalidateAndSync($pdo);
            }
            return [
                'status' => 'cleanup_pending',
                'message' => '节点已从本地分发隔离，云资源等待适配器处理',
                'id' => $nodeId,
                'run_id' => $runId,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        } finally {
            if ($groupLock !== null) apiDomainAutomationUnlock($pdo, $groupLock);
            apiDomainAutomationUnlock($pdo, $lifecycleLock);
        }
    }
}

if (!function_exists('apiDomainAutomationAdvanceFailedGroup')) {
    /**
     * 到期执行失败后仍把时钟推进到下一周期。
     *
     * 这里不保存异常原文，避免 worker 每 30 秒重试同一条坏策略，
     * 也避免连接串或云账号信息进入持久消息。
     */
    function apiDomainAutomationAdvanceFailedGroup(
        PDO $pdo,
        int $groupId,
        ?string $expectedScheduledFor = null
    ): bool {
        // 与正常调度使用相同的“全局生命周期 -> 池组”加锁顺序，
        // 防止失败推进越过同时进行的手动执行或清理门禁。
        $lifecycleLock = 'yunzhuru_api_domain_lifecycle';
        if (!apiDomainAutomationLock($pdo, $lifecycleLock)) return false;
        $groupLock = 'yunzhuru_api_auto_group_' . $groupId;
        if (!apiDomainAutomationLock($pdo, $groupLock)) {
            apiDomainAutomationUnlock($pdo, $lifecycleLock);
            return false;
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT schedule_anchor_at,interval_value,interval_unit,next_run_at
                FROM cainiao_api_domain_automation_group
                WHERE id=:id AND deleted_at IS NULL AND enabled=1 FOR UPDATE');
            $stmt->execute([':id' => $groupId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) {
                $pdo->commit();
                return false;
            }
            $now = apiDomainAutomationNow();
            $scheduledFor = trim((string)($group['next_run_at'] ?? ''));
            $clockWasReplaced = $expectedScheduledFor !== null
                && trim($expectedScheduledFor) !== $scheduledFor;
            $isStillDue = $scheduledFor !== ''
                && new DateTimeImmutable($scheduledFor, apiDomainAutomationTimezone()) <= $now;
            if ($clockWasReplaced || !$isStillDue) {
                // worker 预选后若管理员更新了调度时钟，旧失败结果不覆盖新 next_run_at。
                $pdo->commit();
                return false;
            }
            $anchor = new DateTimeImmutable((string)$group['schedule_anchor_at'], apiDomainAutomationTimezone());
            $nextRun = apiDomainAutomationNextRun(
                $anchor,
                (int)$group['interval_value'],
                (string)$group['interval_unit'],
                $now
            );
            $update = $pdo->prepare("UPDATE cainiao_api_domain_automation_group SET
                last_run_at=:last_run_at,last_run_status='failed',
                last_run_message='本轮调度执行异常，已推进到下一周期',next_run_at=:next_run_at
                WHERE id=:id AND deleted_at IS NULL AND enabled=1");
            $update->execute([
                ':last_run_at' => apiDomainAutomationDateText($now),
                ':next_run_at' => apiDomainAutomationDateText($nextRun),
                ':id' => $groupId,
            ]);
            apiDomainAutomationCreateRunRecord(
                $pdo,
                $groupId,
                null,
                null,
                'scheduled',
                false,
                'failed',
                0,
                0,
                0,
                ['marked_count' => 0, 'cleanup_pending_count' => 0, 'protected_count' => 0],
                '本轮调度执行异常，已推进到下一周期',
                $now
            );
            $pdo->commit();
            return true;
        } catch (Throwable $ignored) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // 数据库本身不可用时交给 Supervisor 的下一轮重建连接处理。
            return false;
        } finally {
            apiDomainAutomationUnlock($pdo, $groupLock);
            apiDomainAutomationUnlock($pdo, $lifecycleLock);
        }
    }
}

if (!function_exists('apiDomainAutomationProcessDue')) {
    /** 由独立 worker 调用，每轮最多处理 limit 个到期池组。 */
    function apiDomainAutomationProcessDue(PDO $pdo, int $limit = 10): array
    {
        ensureApiDomainAutomationSchema($pdo);
        $limit = max(1, min(50, $limit));
        $globalLock = 'yunzhuru_api_domain_auto_due';
        if (!apiDomainAutomationLock($pdo, $globalLock)) {
            return ['checked' => 0, 'processed' => 0, 'waiting_adapter' => 0, 'skipped' => 1, 'failed' => 0, 'results' => []];
        }
        try {
            $stmt = $pdo->query("SELECT id,next_run_at AS scheduled_for
                FROM cainiao_api_domain_automation_group
                WHERE deleted_at IS NULL AND enabled=1 AND next_run_at IS NOT NULL
                  AND next_run_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)
                ORDER BY next_run_at ASC,id ASC LIMIT {$limit}");
            $dueRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [
                'checked' => count($dueRows), 'processed' => 0, 'waiting_adapter' => 0,
                'skipped' => 0, 'failed' => 0, 'results' => [],
            ];
            foreach ($dueRows as $dueRow) {
                $id = (int)$dueRow['id'];
                try {
                    $run = apiDomainAutomationRunGroup($pdo, $id, 'scheduled');
                    $result['processed']++;
                    if (($run['status'] ?? '') === 'waiting_adapter') $result['waiting_adapter']++;
                    if (($run['status'] ?? '') === 'skipped') $result['skipped']++;
                    $result['results'][] = $run;
                } catch (Throwable $ignored) {
                    apiDomainAutomationAdvanceFailedGroup(
                        $pdo,
                        $id,
                        (string)($dueRow['scheduled_for'] ?? '')
                    );
                    $result['failed']++;
                    $result['results'][] = ['status' => 'failed'];
                }
            }
            return $result;
        } finally {
            apiDomainAutomationUnlock($pdo, $globalLock);
        }
    }
}

if (!function_exists('apiDomainAutomationEstimatedRunsPerYear')) {
    /** 返回预计年检查次数，用于提示“调度次数”不等于“新增数量”。 */
    function apiDomainAutomationEstimatedRunsPerYear(int $intervalValue, string $intervalUnit): float
    {
        $intervalValue = max(1, $intervalValue);
        $annualByUnit = [
            'minute' => 365 * 24 * 60,
            'hour' => 365 * 24,
            'day' => 365,
            'month' => 12,
        ];
        $unit = apiDomainAutomationNormalizeUnit($intervalUnit);
        return round($annualByUnit[$unit] / $intervalValue, 2);
    }
}

if (!function_exists('apiDomainAutomationOverview')) {
    /** 返回池组、批次、节点、访问统计、队列和运行记录的后台单一合同。 */
    function apiDomainAutomationOverview(PDO $pdo): array
    {
        ensureApiDomainAutomationSchema($pdo);
        $accounts = $pdo->query("SELECT id,name,account_id,region,enabled,connection_state,created_at,updated_at
            FROM cainiao_api_domain_cloud_account WHERE deleted_at IS NULL ORDER BY id ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
        $groups = $pdo->query("SELECT g.*,a.name AS cloud_account_name,a.region AS cloud_account_region
            FROM cainiao_api_domain_automation_group g
            LEFT JOIN cainiao_api_domain_cloud_account a ON a.id=g.cloud_account_id
            WHERE g.deleted_at IS NULL ORDER BY g.id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $nodes = $pdo->query("SELECT p.id,p.name,p.base_url,p.usage_scope,p.enabled,p.priority,
                p.origin,p.automation_group_id,p.automation_batch_id,p.lifecycle_status,
                p.cleanup_protected,p.pinned,p.reserved,p.verified_at,p.observation_until,
                p.eligible_at,p.last_access_at,p.access_count,p.idle_marked_at,
                p.cleanup_requested_at,p.lifecycle_updated_at,p.archived_at,p.cleanup_reason,
                p.cloud_resource_ref,p.cloud_cleanup_state,p.created_at,p.updated_at,
                g.name AS group_name,
                GREATEST(p.access_count,COALESCE(SUM(s.ok_count),0)) AS effective_access_count,
                MAX(CASE WHEN s.ok_count>0 THEN s.last_seen_at ELSE NULL END) AS aggregated_last_access_at
            FROM cainiao_api_domain_pool p
            LEFT JOIN cainiao_api_domain_automation_group g ON g.id=p.automation_group_id
            LEFT JOIN cainiao_api_domain_stats s ON s.domain_pool_id=p.id
            WHERE p.origin='aws_auto'
            GROUP BY p.id,g.name
            ORDER BY p.id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

        $nodeGroups = [];
        foreach ($nodes as &$node) {
            $node['access_count'] = (int)($node['effective_access_count'] ?? $node['access_count'] ?? 0);
            if (!empty($node['aggregated_last_access_at'])) {
                $node['last_access_at'] = (string)$node['aggregated_last_access_at'];
            }
            unset($node['effective_access_count'], $node['aggregated_last_access_at']);
            $groupId = (int)($node['automation_group_id'] ?? 0);
            if (!isset($nodeGroups[$groupId])) $nodeGroups[$groupId] = [];
            $nodeGroups[$groupId][] = $node;
        }
        unset($node);

        $totalEligible = 0;
        $totalTarget = 0;
        $totalMarked = 0;
        $totalCleanupPending = 0;
        $totalAnnualRuns = 0.0;
        $nextRunAt = null;
        $accessStats = [];
        foreach ($groups as &$group) {
            $groupId = (int)$group['id'];
            $groupNodes = $nodeGroups[$groupId] ?? [];
            $metrics = [
                'managed_count' => count($groupNodes),
                'current_eligible_count' => 0,
                'healthy_count' => apiDomainAutomationHealthyEffectiveCount($pdo, $groupId),
                'pending_verification_count' => 0,
                'unused_marked_count' => 0,
                'cleanup_pending_count' => 0,
                'archived_count' => 0,
                'cleanup_failed_count' => 0,
            ];
            $groupAccessCount = 0;
            $accessedNodeCount = 0;
            $lastAccessAt = null;
            foreach ($groupNodes as $node) {
                $state = (string)($node['lifecycle_status'] ?? 'active');
                if ($state === 'pending_verification'
                    || ((int)$node['enabled'] === 1 && in_array($state, ['active', 'unused_marked'], true))) {
                    $metrics['current_eligible_count']++;
                }
                if ($state === 'pending_verification') $metrics['pending_verification_count']++;
                if ($state === 'unused_marked') $metrics['unused_marked_count']++;
                if ($state === 'cleanup_pending') $metrics['cleanup_pending_count']++;
                if ($state === 'archived') $metrics['archived_count']++;
                if ($state === 'cleanup_failed') $metrics['cleanup_failed_count']++;
                $nodeAccess = (int)($node['access_count'] ?? 0);
                $groupAccessCount += $nodeAccess;
                if ($nodeAccess > 0) $accessedNodeCount++;
                if (!empty($node['last_access_at'])
                    && ($lastAccessAt === null || $node['last_access_at'] > $lastAccessAt)) {
                    $lastAccessAt = (string)$node['last_access_at'];
                }
            }
            foreach ($metrics as $name => $value) $group[$name] = $value;
            // 兼容经典页已使用的字段名，新 Pure Admin 直接读取更明确的别名。
            $group['active_count'] = $metrics['current_eligible_count'];
            $group['idle_count'] = $metrics['unused_marked_count'];
            $group['generate_per_run'] = (int)$group['generate_count'];
            $group['mark_after_days'] = (int)$group['idle_mark_days'];
            $group['cleanup_after_days'] = (int)$group['cleanup_no_access_days'];
            $group['capacity_gap'] = max(
                0,
                (int)$group['target_active_count'] - $metrics['current_eligible_count']
            );
            $group['estimated_next_fill'] = (int)$group['generation_enabled'] === 1
                ? min((int)$group['generate_count'], $group['capacity_gap'])
                : 0;
            $group['estimated_runs_per_year'] = apiDomainAutomationEstimatedRunsPerYear(
                (int)$group['interval_value'],
                (string)$group['interval_unit']
            );
            $group['active_limit'] = (int)$group['target_active_count'];
            $group['state'] = (int)$group['enabled'] === 1
                ? ((string)$group['last_run_status'] === 'never' ? 'scheduled' : (string)$group['last_run_status'])
                : 'disabled';
            $group['last_result_message'] = (string)($group['last_run_message'] ?? '');
            $totalEligible += $metrics['current_eligible_count'];
            $totalTarget += (int)$group['target_active_count'];
            $totalMarked += $metrics['unused_marked_count'];
            $totalCleanupPending += $metrics['cleanup_pending_count'];
            $totalAnnualRuns += $group['estimated_runs_per_year'];
            if (!empty($group['next_run_at']) && ($nextRunAt === null || $group['next_run_at'] < $nextRunAt)) {
                $nextRunAt = (string)$group['next_run_at'];
            }
            $accessStats[] = [
                'group_id' => $groupId,
                'group_name' => (string)$group['name'],
                'node_count' => count($groupNodes),
                'accessed_node_count' => $accessedNodeCount,
                'access_count' => $groupAccessCount,
                'last_access_at' => $lastAccessAt,
            ];
        }
        unset($group);

        $batches = $pdo->query("SELECT b.*,g.name AS group_name
            FROM cainiao_api_domain_automation_batch b
            LEFT JOIN cainiao_api_domain_automation_group g ON g.id=b.group_id
            ORDER BY b.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($batches as &$batch) {
            $batch['month'] = (string)($batch['period_key'] ?? '');
            $batch['batch_no'] = (string)($batch['batch_code'] ?? '');
        }
        unset($batch);
        $runs = $pdo->query("SELECT r.*,g.name AS group_name,b.batch_code
            FROM cainiao_api_domain_automation_run r
            LEFT JOIN cainiao_api_domain_automation_group g ON g.id=r.group_id
            LEFT JOIN cainiao_api_domain_automation_batch b ON b.id=r.batch_id
            ORDER BY r.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $markQueue = [];
        $cleanupQueue = [];
        foreach ($nodes as $node) {
            if ((string)$node['lifecycle_status'] === 'unused_marked') $markQueue[] = $node;
            if ((string)$node['lifecycle_status'] === 'cleanup_pending') $cleanupQueue[] = $node;
        }
        return [
            'adapter_state' => 'waiting_adapter',
            'accounts' => $accounts,
            'groups' => $groups,
            'recent_batches' => $batches,
            'nodes' => $nodes,
            'access_stats' => $accessStats,
            'mark_queue' => $markQueue,
            'cleanup_queue' => $cleanupQueue,
            'recent_runs' => $runs,
            'lifecycle_options' => [
                'pending_verification',
                'active',
                'unused_marked',
                'cleanup_pending',
                'archived',
                'cleanup_failed',
            ],
            'summary' => [
                'state' => 'waiting_adapter',
                'account_count' => count($accounts),
                'group_count' => count($groups),
                'node_count' => count($nodes),
                'active_count' => $totalEligible,
                'eligible_count' => $totalEligible,
                'target_active_count' => $totalTarget,
                'unused_marked_count' => $totalMarked,
                'cleanup_pending_count' => $totalCleanupPending,
                'estimated_runs_per_year' => round($totalAnnualRuns, 2),
                'next_run_at' => $nextRunAt,
            ],
        ];
    }
}
