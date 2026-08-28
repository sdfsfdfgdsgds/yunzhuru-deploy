<?php

/**
 * API 域名池 AWS 自动生成与无访问清理。
 *
 * 本文件是自动化能力的单一事实源：账号元数据、稳定池组、
 * 调度批次、容量补齐、CloudFront 资源账本、有界作业队列与节点
 * 生命周期都在此处计算。AWS 凭据只通过 credential_ref 引用运行环境，
 * 数据库与管理接口始终不保存、返回访问密钥或 Secret。
 */

require_once __DIR__ . '/DeletedApp.php';
require_once __DIR__ . '/ApiConfigProbe.php';
if (is_file(__DIR__ . '/AwsCloudFrontAdapter.php')) {
    require_once __DIR__ . '/AwsCloudFrontAdapter.php';
}

if (!class_exists('ApiDomainAutomationException')) {
    /** 自动化状态机只携带固定 reason_code，不携带上游响应原文。 */
    final class ApiDomainAutomationException extends RuntimeException
    {
        /** @var string */
        private $reasonCode;

        public function __construct(string $reasonCode)
        {
            $this->reasonCode = preg_match('/^[a-z0-9_]{1,64}$/', $reasonCode) === 1
                ? $reasonCode
                : 'execution_failed';
            parent::__construct('API 域名自动化执行失败（' . $this->reasonCode . '）');
        }

        public function getReasonCode(): string
        {
            return $this->reasonCode;
        }
    }
}

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

if (!function_exists('apiDomainAutomationSeedPrimaryAccountMetadata')) {
    /**
     * 按 Railway 的非敏感元数据变量创建一个幂等的 PRIMARY 账号行。
     * 这里只读取 Account ID 与 Region；Access Key、Secret Key、Session Token
     * 仍由 CloudFront 适配器在验证/作业执行时按 credential_ref 读取，绝不落库。
     */
    function apiDomainAutomationSeedPrimaryAccountMetadata(PDO $pdo): void
    {
        $accountId = trim((string)(getenv('AWS_CDN_PRIMARY_ACCOUNT_ID') ?: ''));
        if ($accountId === '' || preg_match('/^\d{12}$/', $accountId) !== 1) {
            return;
        }
        $region = strtolower(trim((string)(getenv('AWS_CDN_PRIMARY_REGION') ?: 'us-east-1')));
        if (preg_match('/^[a-z]{2}(?:-gov)?-[a-z0-9-]+-\d$/', $region) !== 1) {
            $region = 'us-east-1';
        }
        $exists = $pdo->prepare("SELECT id FROM cainiao_api_domain_cloud_account
            WHERE deleted_at IS NULL AND credential_ref='PRIMARY' LIMIT 1");
        $exists->execute();
        if ($exists->fetchColumn()) return;
        $insert = $pdo->prepare("INSERT INTO cainiao_api_domain_cloud_account
            (name,account_id,region,credential_ref,auth_type,enabled,connection_state)
            VALUES ('aws-cdn',:account_id,:region,'PRIMARY','environment',1,'waiting_credentials')");
        $insert->execute([':account_id' => $accountId, ':region' => $region]);
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
            credential_ref varchar(64) NOT NULL DEFAULT '',
            auth_type varchar(24) NOT NULL DEFAULT 'environment',
            role_arn varchar(255) NOT NULL DEFAULT '',
            external_id_ref varchar(64) NOT NULL DEFAULT '',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            connection_state varchar(24) NOT NULL DEFAULT 'waiting_credentials',
            verified_account_id varchar(32) NOT NULL DEFAULT '',
            connection_last_checked_at datetime DEFAULT NULL,
            connection_error_code varchar(64) NOT NULL DEFAULT '',
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
            domain_provider varchar(32) NOT NULL DEFAULT 'cloudfront_default',
            certificate_provider varchar(32) NOT NULL DEFAULT 'cloudfront_default',
            origin_domain varchar(253) NOT NULL DEFAULT 'yunzhuru-app-production.up.railway.app',
            public_path varchar(255) NOT NULL DEFAULT '/shell.php',
            probe_app_id int unsigned NOT NULL DEFAULT 0,
            price_class varchar(32) NOT NULL DEFAULT 'PriceClass_All',
            ipv6_enabled tinyint(1) NOT NULL DEFAULT 1,
            enabled tinyint(1) NOT NULL DEFAULT 0,
            generation_enabled tinyint(1) NOT NULL DEFAULT 0,
            capacity_mode varchar(24) NOT NULL DEFAULT 'target_replenish',
            target_active_count int unsigned NOT NULL DEFAULT 30,
            minimum_healthy_count int unsigned NOT NULL DEFAULT 4,
            interval_value int unsigned NOT NULL DEFAULT 1,
            interval_unit varchar(16) NOT NULL DEFAULT 'minute',
            generate_count int unsigned NOT NULL DEFAULT 30,
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_api_domain_cloud_resource (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            group_id int unsigned NOT NULL,
            batch_id bigint unsigned NOT NULL,
            run_id bigint unsigned NOT NULL,
            cloud_account_id int unsigned NOT NULL,
            expected_account_id varchar(32) NOT NULL,
            domain_pool_id int unsigned DEFAULT NULL,
            slot_index int unsigned NOT NULL,
            caller_reference varchar(128) NOT NULL,
            resource_type varchar(32) NOT NULL DEFAULT 'cloudfront_distribution',
            origin_domain varchar(253) NOT NULL,
            public_path varchar(255) NOT NULL DEFAULT '/shell.php',
            usage_scope varchar(16) NOT NULL DEFAULT 'config',
            price_class varchar(32) NOT NULL DEFAULT 'PriceClass_All',
            ipv6_enabled tinyint(1) NOT NULL DEFAULT 1,
            distribution_id varchar(64) NOT NULL DEFAULT '',
            distribution_arn varchar(255) NOT NULL DEFAULT '',
            domain_name varchar(253) NOT NULL DEFAULT '',
            public_api_url varchar(512) NOT NULL DEFAULT '',
            distribution_etag varchar(255) NOT NULL DEFAULT '',
            provider_status varchar(32) NOT NULL DEFAULT 'not_created',
            workflow_state varchar(32) NOT NULL DEFAULT 'pending_create',
            provider_enabled tinyint(1) NOT NULL DEFAULT 0,
            probe_state varchar(32) NOT NULL DEFAULT 'not_started',
            probe_http_code int unsigned NOT NULL DEFAULT 0,
            retry_count int unsigned NOT NULL DEFAULT 0,
            next_action_at datetime DEFAULT NULL,
            last_error_code varchar(64) NOT NULL DEFAULT '',
            last_aws_request_id varchar(128) NOT NULL DEFAULT '',
            cloud_created_at datetime DEFAULT NULL,
            deployed_at datetime DEFAULT NULL,
            verified_at datetime DEFAULT NULL,
            disabled_at datetime DEFAULT NULL,
            delete_not_before datetime DEFAULT NULL,
            archived_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_cloud_resource_caller (caller_reference),
            UNIQUE KEY uniq_cloud_resource_slot (batch_id,slot_index),
            UNIQUE KEY uniq_cloud_resource_pool (domain_pool_id),
            KEY idx_cloud_resource_work (workflow_state,next_action_at,id),
            KEY idx_cloud_resource_group (group_id,id),
            KEY idx_cloud_resource_distribution (distribution_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池CloudFront资源账本'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_api_domain_cloud_job (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            resource_id bigint unsigned NOT NULL,
            group_id int unsigned NOT NULL,
            cloud_account_id int unsigned NOT NULL,
            job_type varchar(32) NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'pending',
            attempt_count int unsigned NOT NULL DEFAULT 0,
            max_attempts int unsigned NOT NULL DEFAULT 12,
            cancel_requested tinyint(1) NOT NULL DEFAULT 0,
            next_attempt_at datetime NOT NULL,
            lock_token varchar(64) NOT NULL DEFAULT '',
            locked_at datetime DEFAULT NULL,
            last_error_code varchar(64) NOT NULL DEFAULT '',
            last_aws_request_id varchar(128) NOT NULL DEFAULT '',
            started_at datetime DEFAULT NULL,
            finished_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_cloud_job_due (status,next_attempt_at,id),
            KEY idx_cloud_job_resource (resource_id,id),
            KEY idx_cloud_job_group (group_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池CloudFront有界作业队列'");

        // 先期已上线的表由此幂等补齐，避免要求人工删表重建。
        $accountColumns = [
            'credential_ref' => "varchar(64) NOT NULL DEFAULT '' AFTER `region`",
            'auth_type' => "varchar(24) NOT NULL DEFAULT 'environment' AFTER `credential_ref`",
            'role_arn' => "varchar(255) NOT NULL DEFAULT '' AFTER `auth_type`",
            'external_id_ref' => "varchar(64) NOT NULL DEFAULT '' AFTER `role_arn`",
            'verified_account_id' => "varchar(32) NOT NULL DEFAULT '' AFTER `connection_state`",
            'connection_last_checked_at' => 'datetime DEFAULT NULL AFTER `verified_account_id`',
            'connection_error_code' => "varchar(64) NOT NULL DEFAULT '' AFTER `connection_last_checked_at`",
        ];
        foreach ($accountColumns as $column => $definition) {
            apiDomainAutomationEnsureTableColumn(
                $pdo,
                'cainiao_api_domain_cloud_account',
                $column,
                $definition
            );
        }
        // 仅在部署环境显式提供非敏感 AWS_CDN_PRIMARY_ACCOUNT_ID 时显示账号草稿；
        // 不设变量则保持空态，不凭空创建不可验证的账号记录。
        apiDomainAutomationSeedPrimaryAccountMetadata($pdo);

        // 记录 V3 表是否已经有容量模式字段。若字段缺失，说明存量组仍
        // 使用“按周期”旧合同；先保留自定义周期组，再只把旧默认组合迁移为固定目标。
        $hadCapacityModeColumn = apiDomainAutomationMysqlColumnExists(
            $pdo,
            'cainiao_api_domain_automation_group',
            'capacity_mode'
        );
        $hadTargetActiveCountColumn = apiDomainAutomationMysqlColumnExists(
            $pdo,
            'cainiao_api_domain_automation_group',
            'target_active_count'
        );
        $groupColumns = [
            'environment' => "varchar(32) NOT NULL DEFAULT 'production' AFTER `usage_scope`",
            'region' => "varchar(32) NOT NULL DEFAULT 'us-east-1' AFTER `environment`",
            'domain_provider' => "varchar(32) NOT NULL DEFAULT 'cloudfront_default' AFTER `region`",
            'certificate_provider' => "varchar(32) NOT NULL DEFAULT 'cloudfront_default' AFTER `domain_provider`",
            'origin_domain' => "varchar(253) NOT NULL DEFAULT 'yunzhuru-app-production.up.railway.app' AFTER `certificate_provider`",
            'public_path' => "varchar(255) NOT NULL DEFAULT '/shell.php' AFTER `origin_domain`",
            'probe_app_id' => 'int unsigned NOT NULL DEFAULT 0 AFTER `public_path`',
            'price_class' => "varchar(32) NOT NULL DEFAULT 'PriceClass_All' AFTER `probe_app_id`",
            'ipv6_enabled' => 'tinyint(1) NOT NULL DEFAULT 1 AFTER `price_class`',
            'generation_enabled' => 'tinyint(1) NOT NULL DEFAULT 0 AFTER `enabled`',
            'capacity_mode' => "varchar(24) NOT NULL DEFAULT 'target_replenish' AFTER `generation_enabled`",
            // 兼容早期只建了自动化骨架、尚未带容量字段的存量库；不依赖
            // AFTER，避免旧表缺少相邻字段时整次迁移失败。
            'target_active_count' => 'int unsigned NOT NULL DEFAULT 30',
            'minimum_healthy_count' => 'int unsigned NOT NULL DEFAULT 4',
            'interval_value' => 'int unsigned NOT NULL DEFAULT 1',
            'interval_unit' => "varchar(16) NOT NULL DEFAULT 'minute'",
            'generate_count' => 'int unsigned NOT NULL DEFAULT 30',
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

        if (!$hadCapacityModeColumn) {
            $targetColumnMissing = $hadTargetActiveCountColumn ? 0 : 1;
            $pdo->exec("UPDATE cainiao_api_domain_automation_group
                SET capacity_mode=CASE
                    WHEN {$targetColumnMissing}=1 THEN 'target_replenish'
                    WHEN target_active_count=20 AND minimum_healthy_count=4
                         AND interval_value=1 AND interval_unit='day' AND generate_count=1
                    THEN 'target_replenish'
                    ELSE 'periodic'
                END
                WHERE deleted_at IS NULL");
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

        $resourceColumns = [
            'expected_account_id' => "varchar(32) NOT NULL DEFAULT '' AFTER `cloud_account_id`",
            'price_class' => "varchar(32) NOT NULL DEFAULT 'PriceClass_All' AFTER `usage_scope`",
            'ipv6_enabled' => 'tinyint(1) NOT NULL DEFAULT 1 AFTER `price_class`',
            'delete_not_before' => 'datetime DEFAULT NULL AFTER `disabled_at`',
        ];
        foreach ($resourceColumns as $column => $definition) {
            apiDomainAutomationEnsureTableColumn(
                $pdo,
                'cainiao_api_domain_cloud_resource',
                $column,
                $definition
            );
        }

        $jobColumns = [
            'cancel_requested' => 'tinyint(1) NOT NULL DEFAULT 0 AFTER `max_attempts`',
        ];
        foreach ($jobColumns as $column => $definition) {
            apiDomainAutomationEnsureTableColumn(
                $pdo,
                'cainiao_api_domain_cloud_job',
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
        $pdo->exec("UPDATE cainiao_api_domain_cloud_account
            SET connection_state=CASE
                WHEN enabled=0 THEN 'disabled'
                WHEN credential_ref='' THEN 'waiting_credentials'
                WHEN connection_state='waiting_adapter' THEN 'pending_validation'
                ELSE connection_state END");
        $pdo->exec("UPDATE cainiao_api_domain_automation_group SET
                domain_provider='cloudfront_default',certificate_provider='cloudfront_default',
                origin_domain=IF(origin_domain='', 'yunzhuru-app-production.up.railway.app', origin_domain),
                public_path=IF(public_path='', '/shell.php', public_path)
            WHERE domain_provider IN ('route53','cloudfront_default')
              AND certificate_provider IN ('acm','cloudfront_default')");

        // V4 将旧版“每天生成 1 个、目标 20 个”的默认合同一次性迁移为
        // “固定目标 30 个、缺口补齐、每分钟检查”。只匹配旧默认组合，
        // 保留管理员已经保存的其它自定义策略；版本写入后不重复覆盖。
        $schemaVersionStmt = $pdo->query("SELECT key_value FROM cainiao_config_delivery_meta
            WHERE key_name='api_domain_automation_schema_version' LIMIT 1");
        $previousSchemaVersion = (int)$schemaVersionStmt->fetchColumn();
        if ($previousSchemaVersion < 4) {
            $pdo->exec("UPDATE cainiao_api_domain_automation_group
                SET capacity_mode='target_replenish',target_active_count=30,
                    interval_value=1,interval_unit='minute',generate_count=30,
                    next_run_at=CASE WHEN enabled=1
                        THEN DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)
                        ELSE next_run_at END
                WHERE capacity_mode IS NULL OR capacity_mode=''
                   OR (capacity_mode='target_replenish'
                       AND target_active_count=20 AND minimum_healthy_count=4
                       AND interval_value=1 AND interval_unit='day' AND generate_count=1)");
        }
        // 版本 4 对应 CloudFront 默认域名模板与固定目标补齐合同；
        // 使用 GREATEST 保留线上已登记的更高版本，避免旧代码倒退标记。
        $pdo->exec("INSERT INTO cainiao_config_delivery_meta (key_name,key_value)
            VALUES ('api_domain_automation_schema_version','4')
            ON DUPLICATE KEY UPDATE key_value=GREATEST(CAST(key_value AS UNSIGNED),4)");
        $ready = true;
    }
}

if (!function_exists('apiDomainAutomationNormalizeReference')) {
    /** 密钥和 External ID 只保存环境变量引用名，不接受密钥本体。 */
    function apiDomainAutomationNormalizeReference($value, string $label, bool $required = false): string
    {
        $reference = strtoupper(trim((string)$value));
        if ($reference === '') {
            if ($required) throw new InvalidArgumentException($label . '必填');
            return '';
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $reference) !== 1) {
            throw new InvalidArgumentException($label . '只允许 1-64 位大写字母、数字与下划线');
        }
        return $reference;
    }
}

if (!function_exists('apiDomainAutomationNormalizeOriginDomain')) {
    /** CloudFront Origin 只允许公网 DNS 主机名，不带协议、端口或路径。 */
    function apiDomainAutomationNormalizeOriginDomain($value, bool $required = false): string
    {
        $domain = strtolower(rtrim(trim((string)$value), '.'));
        if ($domain === '') {
            if ($required) throw new InvalidArgumentException('CloudFront 回源域名必填');
            return '';
        }
        if (strlen($domain) > 253
            || strpos($domain, '://') !== false
            || strpos($domain, '/') !== false
            || strpos($domain, ':') !== false
            || strpos($domain, '*') !== false
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain) !== 1) {
            throw new InvalidArgumentException('CloudFront 回源域名格式错误');
        }
        if (filter_var($domain, FILTER_VALIDATE_IP) !== false
            || $domain === 'localhost'
            || substr($domain, -6) === '.local'
            || substr($domain, -9) === '.internal') {
            throw new InvalidArgumentException('CloudFront 回源域名必须是登记的公网主机');
        }
        $allowed = ['yunzhuru-app-production.up.railway.app' => true];
        $extraOrigins = explode(',', (string)(getenv('AWS_CDN_ALLOWED_ORIGINS') ?: ''));
        foreach ($extraOrigins as $extraOrigin) {
            $candidate = strtolower(rtrim(trim($extraOrigin), '.'));
            if ($candidate !== ''
                && strlen($candidate) <= 253
                && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $candidate) === 1
                && filter_var($candidate, FILTER_VALIDATE_IP) === false
                && substr($candidate, -6) !== '.local'
                && substr($candidate, -9) !== '.internal') {
                $allowed[$candidate] = true;
            }
        }
        if (!isset($allowed[$domain])) {
            throw new InvalidArgumentException('CloudFront 回源域名未在 AWS_CDN_ALLOWED_ORIGINS 白名单');
        }
        return $domain;
    }
}

if (!function_exists('apiDomainAutomationNormalizePublicPath')) {
    /** 公开 Shell 路径只保存绝对路径，Query 由配置分发层追加。 */
    function apiDomainAutomationNormalizePublicPath($value): string
    {
        $path = trim((string)$value);
        if ($path === '') $path = '/shell.php';
        if (strlen($path) > 255
            || $path[0] !== '/'
            || strpos($path, '?') !== false
            || strpos($path, '#') !== false
            || strpos($path, "\0") !== false
            || strpos($path, '..') !== false
            || strpos($path, '//') !== false
            // 分隔符为 ~，字符类中的 ~ 必须转义，否则 PHP 会把后续 ! 当成修饰符。
            || preg_match("~^/[A-Za-z0-9._\\~!$&'()*+,;=:@%/-]+$~", $path) !== 1) {
            throw new InvalidArgumentException('CloudFront 公开路径格式错误');
        }
        return $path;
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
        $authType = strtolower(trim((string)($input['auth_type'] ?? 'environment')));
        if (!in_array($authType, ['environment', 'assume_role'], true)) {
            throw new InvalidArgumentException('AWS 身份类型只允许 environment 或 assume_role');
        }
        $credentialRef = apiDomainAutomationNormalizeReference(
            $input['credential_ref'] ?? '',
            'AWS 凭据引用',
            false
        );
        $roleArn = trim((string)($input['role_arn'] ?? ''));
        $externalIdRef = apiDomainAutomationNormalizeReference(
            $input['external_id_ref'] ?? '',
            'External ID 引用',
            false
        );
        if ($authType === 'assume_role') {
            if (preg_match('~^arn:(?:aws|aws-us-gov|aws-cn):iam::\d{12}:role/[A-Za-z0-9+=,.@_/-]{1,200}$~', $roleArn) !== 1) {
                throw new InvalidArgumentException('AssumeRole 身份必须填写有效 role_arn');
            }
        } elseif ($roleArn !== '' || $externalIdRef !== '') {
            throw new InvalidArgumentException('environment 身份不使用 role_arn 或 external_id_ref');
        }
        return [
            'name' => apiDomainAutomationNormalizeName($input['name'] ?? ''),
            'account_id' => $accountId,
            'region' => $region,
            'credential_ref' => $credentialRef,
            'auth_type' => $authType,
            'role_arn' => $roleArn,
            'external_id_ref' => $externalIdRef,
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
        // 账号 ID 是资源账本的身份锁。读取、检查资源占用和更新必须在同一行锁事务中完成，
        // 防止 RunGroup 恰好在编辑时为旧身份创建新 CloudFront 资源。
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $currentStmt = $pdo->prepare('SELECT credential_ref,auth_type,role_arn,external_id_ref,
                        account_id,region,connection_state
                    FROM cainiao_api_domain_cloud_account
                    WHERE id=:id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
                $currentStmt->execute([':id' => $id]);
                $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) throw new RuntimeException('AWS 账号元数据不存在');
                if ((string)$current['account_id'] !== (string)$row['account_id']) {
                    $resourceCheck = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_cloud_resource
                        WHERE cloud_account_id=:id AND workflow_state<>'archived'");
                    $resourceCheck->execute([':id' => $id]);
                    if ((int)$resourceCheck->fetchColumn() > 0) {
                        throw new RuntimeException('该 AWS 账号仍有未归档 CloudFront 资源，Account ID 保持锁定');
                    }
                }
                $identityChanged = false;
                foreach (['credential_ref', 'auth_type', 'role_arn', 'external_id_ref', 'account_id', 'region'] as $field) {
                    if ((string)($current[$field] ?? '') !== (string)$row[$field]) {
                        $identityChanged = true;
                        break;
                    }
                }
                $connectionState = !$row['enabled']
                    ? 'disabled'
                    : ($row['account_id'] === ''
                        ? 'waiting_account_id'
                        : ($row['credential_ref'] === ''
                            ? 'waiting_credentials'
                            : ($identityChanged ? 'pending_validation' : (string)$current['connection_state'])));
                if ($row['enabled'] && (string)$current['connection_state'] === 'disabled') {
                    $connectionState = 'pending_validation';
                }
                $stmt = $pdo->prepare('UPDATE cainiao_api_domain_cloud_account
                    SET name=:name,account_id=:account_id,region=:region,credential_ref=:credential_ref,
                        auth_type=:auth_type,role_arn=:role_arn,external_id_ref=:external_id_ref,
                        enabled=:enabled,connection_state=:connection_state,
                        verified_account_id=IF(:identity_changed_verified=1,\'\',verified_account_id),
                        connection_last_checked_at=IF(:identity_changed_checked=1,NULL,connection_last_checked_at),
                        connection_error_code=IF(:identity_changed_error=1,\'\',connection_error_code)
                    WHERE id=:id AND deleted_at IS NULL');
                $stmt->execute([
                    ':name' => $row['name'], ':account_id' => $row['account_id'],
                    ':region' => $row['region'], ':credential_ref' => $row['credential_ref'],
                    ':auth_type' => $row['auth_type'], ':role_arn' => $row['role_arn'],
                    ':external_id_ref' => $row['external_id_ref'], ':enabled' => $row['enabled'],
                    ':connection_state' => $connectionState,
                    ':identity_changed_verified' => $identityChanged ? 1 : 0,
                    ':identity_changed_checked' => $identityChanged ? 1 : 0,
                    ':identity_changed_error' => $identityChanged ? 1 : 0,
                    ':id' => $id,
                ]);
                // MySQL 默认只返回实际变更行数；同值保存仍然是成功，只需确认行依旧存在。
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT id FROM cainiao_api_domain_cloud_account WHERE id=:id AND deleted_at IS NULL');
                    $check->execute([':id' => $id]);
                    if (!$check->fetchColumn()) throw new RuntimeException('AWS 账号元数据不存在');
                }
            } else {
                $connectionState = !$row['enabled']
                    ? 'disabled'
                    : ($row['account_id'] === ''
                        ? 'waiting_account_id'
                        : ($row['credential_ref'] === '' ? 'waiting_credentials' : 'pending_validation'));
                $stmt = $pdo->prepare('INSERT INTO cainiao_api_domain_cloud_account
                    (name,account_id,region,credential_ref,auth_type,role_arn,external_id_ref,
                     enabled,connection_state)
                    VALUES (:name,:account_id,:region,:credential_ref,:auth_type,:role_arn,:external_id_ref,
                     :enabled,:connection_state)');
                $stmt->execute([
                    ':name' => $row['name'], ':account_id' => $row['account_id'],
                    ':region' => $row['region'], ':credential_ref' => $row['credential_ref'],
                    ':auth_type' => $row['auth_type'], ':role_arn' => $row['role_arn'],
                    ':external_id_ref' => $row['external_id_ref'], ':enabled' => $row['enabled'],
                    ':connection_state' => $connectionState,
                ]);
                $id = (int)$pdo->lastInsertId();
            }
            if ($ownTransaction) $pdo->commit();
        } catch (Throwable $failure) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
        }
        return [
            'message' => $row['account_id'] === ''
                ? 'AWS 账号已保存，请填写 12 位 Account ID'
                : ($row['credential_ref'] === ''
                    ? 'AWS 账号已保存，请填写运行环境凭据引用'
                    : 'AWS 账号已保存，请执行连接验证'),
            'id' => $id,
            'connection_state' => $connectionState ?? 'pending_validation',
        ];
    }
}

if (!function_exists('apiDomainAutomationDeleteCloudAccount')) {
    function apiDomainAutomationDeleteCloudAccount(PDO $pdo, int $id): array
    {
        ensureApiDomainAutomationSchema($pdo);
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            // 先锁账号，再检查组、资源和作业，避免并发 RunGroup 在归档瞬间继续创建资源。
            $accountLock = $pdo->prepare('SELECT id FROM cainiao_api_domain_cloud_account
                WHERE id=:id AND deleted_at IS NULL FOR UPDATE');
            $accountLock->execute([':id' => $id]);
            if (!$accountLock->fetchColumn()) throw new RuntimeException('AWS 账号元数据不存在');
            $check = $pdo->prepare('SELECT COUNT(*) FROM cainiao_api_domain_automation_group
                WHERE cloud_account_id=:id AND deleted_at IS NULL');
            $check->execute([':id' => $id]);
            if ((int)$check->fetchColumn() > 0) throw new RuntimeException('该 AWS 账号仍有自动化池组关联，请先删除池组');
            $resourceCheck = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_cloud_resource
                WHERE cloud_account_id=:id AND workflow_state<>'archived'");
            $resourceCheck->execute([':id' => $id]);
            if ((int)$resourceCheck->fetchColumn() > 0) {
                throw new RuntimeException('该 AWS 账号仍有未归档 CloudFront 资源，请先完成资源清理');
            }
            $jobCheck = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_cloud_job
                WHERE cloud_account_id=:id AND status IN ('pending','running','retry_wait')");
            $jobCheck->execute([':id' => $id]);
            if ((int)$jobCheck->fetchColumn() > 0) {
                throw new RuntimeException('该 AWS 账号仍有 CloudFront 作业在处理');
            }
            $stmt = $pdo->prepare("UPDATE cainiao_api_domain_cloud_account
                SET enabled=0,connection_state='disabled',deleted_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)
                WHERE id=:id AND deleted_at IS NULL");
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() <= 0) throw new RuntimeException('AWS 账号元数据不存在');
            if ($ownTransaction) $pdo->commit();
        } catch (Throwable $failure) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
        }
        return ['message' => 'AWS 账号元数据已归档'];
    }
}

if (!function_exists('apiDomainAutomationAdapterForAccount')) {
    /** 由账号引用构建适配器；测试可注入 factory，默认路径不注入任何凭据值。 */
    function apiDomainAutomationAdapterForAccount(array $account, ?callable $factory = null)
    {
        if ($factory !== null) return $factory($account);
        if (!class_exists('AwsCloudFrontAdapter')) {
            throw new RuntimeException('CloudFront 执行适配器未加载');
        }
        return AwsCloudFrontAdapter::fromCredentialReference(
            (string)$account['credential_ref'],
            [
                'region' => (string)$account['region'],
                'auth_type' => (string)$account['auth_type'],
                'role_arn' => (string)$account['role_arn'],
                'external_id_ref' => (string)$account['external_id_ref'],
            ]
        );
    }
}

if (!function_exists('apiDomainAutomationFailureInfo')) {
    /** 只从结构化异常中取白名单字段，不保存 AWS 响应原文或 PHP 异常文本。 */
    function apiDomainAutomationFailureInfo(Throwable $failure): array
    {
        $reason = 'execution_failed';
        $requestId = '';
        if ($failure instanceof ApiDomainAutomationException) {
            $reason = $failure->getReasonCode();
        } elseif (class_exists('AwsCloudFrontAdapterException') && $failure instanceof AwsCloudFrontAdapterException) {
            $reason = (string)$failure->getReasonCode();
            $requestId = (string)$failure->getAwsRequestId();
        } elseif ($failure instanceof InvalidArgumentException) {
            $reason = 'invalid_configuration';
        }
        if (preg_match('/^[a-z0-9_]{1,64}$/', $reason) !== 1) $reason = 'execution_failed';
        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId) !== 1) $requestId = '';
        $terminalReasons = [
            'account_id_mismatch', 'aws_access_denied', 'aws_credentials_invalid',
            'aws_signature_mismatch', 'missing_or_invalid_access_key_id',
            'missing_or_invalid_secret_access_key', 'missing_role_arn', 'invalid_role_arn',
            'invalid_configuration', 'invalid_cloudfront_domain', 'invalid_probe_payload',
            'probe_app_mismatch', 'ownership_mismatch', 'probe_app_missing',
            'missing_expected_account_id', 'group_archived', 'duplicate_public_api_url',
        ];
        return [
            'reason_code' => $reason,
            'request_id' => $requestId,
            'retryable' => !in_array($reason, $terminalReasons, true),
            'not_found' => $reason === 'distribution_not_found',
        ];
    }
}

if (!function_exists('apiDomainAutomationValidateCloudAccount')) {
    /** 账号验证使用 Redis 账号锁、短节流和全局单槽，避免后台请求长时占满 PHP worker。 */
    function apiDomainAutomationAcquireValidationGuard(int $accountId): array
    {
        if ($accountId <= 0 || !function_exists('getRedisConnection')) {
            throw new RuntimeException('AWS 连接验证保护服务未就绪');
        }
        $redis = getRedisConnection(0);
        $token = bin2hex(random_bytes(16));
        $namespace = 'console:aws_cdn_validate:';
        $rateKey = $namespace . 'rate:' . $accountId;
        $accountKey = $namespace . 'account:' . $accountId;
        $globalKey = $namespace . 'global';
        $keys = [];
        try {
            if (!$redis->set($rateKey, '1', ['nx', 'ex' => 3])) {
                throw new RuntimeException('AWS 连接验证操作过于频繁');
            }
            if (!$redis->set($accountKey, $token, ['nx', 'ex' => 45])) {
                throw new RuntimeException('该 AWS 账号已有连接验证在执行');
            }
            $keys[] = $accountKey;
            if (!$redis->set($globalKey, $token, ['nx', 'ex' => 45])) {
                throw new RuntimeException('AWS 连接验证服务当前繁忙');
            }
            $keys[] = $globalKey;
            return ['redis' => $redis, 'keys' => $keys, 'token' => $token];
        } catch (Throwable $failure) {
            if (function_exists('apiConfigProbeReleaseRedisLocks')) {
                apiConfigProbeReleaseRedisLocks($redis, $keys, $token);
            }
            try { $redis->close(); } catch (Throwable $ignored) {}
            throw $failure;
        }
    }

    function apiDomainAutomationReleaseValidationGuard(array $guard): void
    {
        $redis = $guard['redis'] ?? null;
        if ($redis && function_exists('apiConfigProbeReleaseRedisLocks')) {
            apiConfigProbeReleaseRedisLocks(
                $redis,
                is_array($guard['keys'] ?? null) ? $guard['keys'] : [],
                (string)($guard['token'] ?? '')
            );
        }
        if ($redis) {
            try { $redis->close(); } catch (Throwable $ignored) {}
        }
    }

    /** 调用 STS GetCallerIdentity 核对引用身份，并使用原配置条件更新防止覆盖并发编辑。 */
    function apiDomainAutomationValidateCloudAccount(
        PDO $pdo,
        int $accountId,
        ?callable $adapterFactory = null
    ): array {
        ensureApiDomainAutomationSchema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM cainiao_api_domain_cloud_account
            WHERE id=:id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) throw new RuntimeException('AWS 账号元数据不存在');
        if ((int)$account['enabled'] !== 1) throw new RuntimeException('AWS 账号已停用');
        if (trim((string)$account['credential_ref']) === '') {
            return ['id' => $accountId, 'connection_state' => 'waiting_credentials', 'connected' => 0];
        }
        if (preg_match('/^\d{12}$/', (string)$account['account_id']) !== 1) {
            return ['id' => $accountId, 'connection_state' => 'waiting_account_id', 'connected' => 0];
        }

        $nowText = apiDomainAutomationDateText(apiDomainAutomationNow());
        $guard = apiDomainAutomationAcquireValidationGuard($accountId);
        try {
            $adapter = apiDomainAutomationAdapterForAccount($account, $adapterFactory);
            $expected = trim((string)$account['account_id']);
            $identity = method_exists($adapter, 'verifyControlPlane')
                ? $adapter->verifyControlPlane($expected)
                : $adapter->verifyIdentity($expected);
            $verifiedId = (string)($identity['account_id'] ?? '');
            if (preg_match('/^\d{12}$/', $verifiedId) !== 1) {
                throw new RuntimeException('AWS 身份响应格式错误');
            }
            $update = $pdo->prepare("UPDATE cainiao_api_domain_cloud_account SET
                    connection_state='connected',verified_account_id=:verified_account_id,
                    connection_last_checked_at=:checked_at,connection_error_code=''
                WHERE id=:id AND deleted_at IS NULL AND enabled=1
                  AND credential_ref=:credential_ref AND auth_type=:auth_type
                  AND role_arn=:role_arn AND external_id_ref=:external_id_ref
                  AND account_id=:account_id AND region=:region");
            $update->execute([
                ':verified_account_id' => $verifiedId, ':checked_at' => $nowText, ':id' => $accountId,
                ':credential_ref' => (string)$account['credential_ref'], ':auth_type' => (string)$account['auth_type'],
                ':role_arn' => (string)$account['role_arn'], ':external_id_ref' => (string)$account['external_id_ref'],
                ':account_id' => (string)$account['account_id'], ':region' => (string)$account['region'],
            ]);
            // MySQL 在值未变化时可能返回 affected_rows=0；以条件查询确认仍是同一份配置，
            // 不把重复验证误报成 configuration_changed。
            $saved = $pdo->prepare('SELECT id,connection_state,verified_account_id
                FROM cainiao_api_domain_cloud_account
                WHERE id=:id AND deleted_at IS NULL AND enabled=1
                  AND credential_ref=:credential_ref AND auth_type=:auth_type
                  AND role_arn=:role_arn AND external_id_ref=:external_id_ref
                  AND account_id=:account_id AND region=:region LIMIT 1');
            $saved->execute([
                ':id' => $accountId,
                ':credential_ref' => (string)$account['credential_ref'], ':auth_type' => (string)$account['auth_type'],
                ':role_arn' => (string)$account['role_arn'], ':external_id_ref' => (string)$account['external_id_ref'],
                ':account_id' => (string)$account['account_id'], ':region' => (string)$account['region'],
            ]);
            $savedRow = $saved->fetch(PDO::FETCH_ASSOC);
            if (!$savedRow || (string)$savedRow['connection_state'] !== 'connected'
                || (string)$savedRow['verified_account_id'] !== $verifiedId) {
                return ['id' => $accountId, 'connection_state' => 'configuration_changed', 'connected' => 0];
            }
            $pdo->prepare("UPDATE cainiao_api_domain_automation_group SET adapter_state='connected'
                WHERE cloud_account_id=:id AND deleted_at IS NULL")->execute([':id' => $accountId]);
            return [
                'id' => $accountId,
                'connection_state' => 'connected',
                'connected' => 1,
                'verified_account_id' => $verifiedId,
                'checked_at' => $nowText,
            ];
        } catch (Throwable $failure) {
            $info = apiDomainAutomationFailureInfo($failure);
            $state = $info['reason_code'] === 'account_id_mismatch'
                ? 'identity_mismatch'
                : 'validation_failed';
            $update = $pdo->prepare('UPDATE cainiao_api_domain_cloud_account SET
                    connection_state=:connection_state,verified_account_id=\'\',
                    connection_last_checked_at=:checked_at,connection_error_code=:error_code
                WHERE id=:id AND deleted_at IS NULL AND enabled=1
                  AND credential_ref=:credential_ref AND auth_type=:auth_type
                  AND role_arn=:role_arn AND external_id_ref=:external_id_ref
                  AND account_id=:account_id AND region=:region');
            $update->execute([
                ':connection_state' => $state, ':checked_at' => $nowText,
                ':error_code' => $info['reason_code'], ':id' => $accountId,
                ':credential_ref' => (string)$account['credential_ref'], ':auth_type' => (string)$account['auth_type'],
                ':role_arn' => (string)$account['role_arn'], ':external_id_ref' => (string)$account['external_id_ref'],
                ':account_id' => (string)$account['account_id'], ':region' => (string)$account['region'],
            ]);
            // 同值失败状态也可能返回 0 行；读取条件快照确认更新仍归属于本次验证。
            $saved = $pdo->prepare('SELECT id,connection_state,connection_error_code
                FROM cainiao_api_domain_cloud_account
                WHERE id=:id AND deleted_at IS NULL AND enabled=1
                  AND credential_ref=:credential_ref AND auth_type=:auth_type
                  AND role_arn=:role_arn AND external_id_ref=:external_id_ref
                  AND account_id=:account_id AND region=:region LIMIT 1');
            $saved->execute([
                ':id' => $accountId,
                ':credential_ref' => (string)$account['credential_ref'], ':auth_type' => (string)$account['auth_type'],
                ':role_arn' => (string)$account['role_arn'], ':external_id_ref' => (string)$account['external_id_ref'],
                ':account_id' => (string)$account['account_id'], ':region' => (string)$account['region'],
            ]);
            $savedRow = $saved->fetch(PDO::FETCH_ASSOC);
            $savedState = $savedRow ? (string)$savedRow['connection_state'] : '';
            if ($savedRow && $savedState === $state
                && (string)$savedRow['connection_error_code'] === (string)$info['reason_code']) {
                $pdo->prepare('UPDATE cainiao_api_domain_automation_group SET adapter_state=:state
                    WHERE cloud_account_id=:id AND deleted_at IS NULL')
                    ->execute([':state' => $state, ':id' => $accountId]);
            }
            return [
                'id' => $accountId,
                'connection_state' => ($savedRow && $savedState === $state)
                    ? $state
                    : 'configuration_changed',
                'connected' => 0,
                'error_code' => $info['reason_code'],
                'checked_at' => $nowText,
            ];
        } finally {
            apiDomainAutomationReleaseValidationGuard($guard);
        }
    }
}

if (!function_exists('apiDomainAutomationNormalizeCapacityMode')) {
    /**
     * 统一池组容量策略：target_replenish 表示固定目标补齐，
     * periodic 仅保留旧版按周期触发的兼容合同。
     */
    function apiDomainAutomationNormalizeCapacityMode($value): string
    {
        $mode = strtolower(trim((string)$value));
        if ($mode === '' || in_array($mode, ['target_replenish', 'fixed_target', 'replenish', 'fixed'], true)) {
            return 'target_replenish';
        }
        if (in_array($mode, ['periodic', 'scheduled', 'interval'], true)) {
            return 'periodic';
        }
        throw new InvalidArgumentException('容量模式只允许 target_replenish 或 periodic');
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
        // API 域名池最多向壳端下发 30 个入口，固定目标模式按缺口补齐而不是按天累加。
        $capacityMode = apiDomainAutomationNormalizeCapacityMode($input['capacity_mode'] ?? 'target_replenish');
        $target = apiDomainAutomationPositiveInt($input['target_active_count'] ?? 30, '目标活跃数', 1, 30);
        $minimum = apiDomainAutomationPositiveInt($input['minimum_healthy_count'] ?? 4, '最低健康数', 1, 30);
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
        $usageScope = configDeliveryNormalizeScope($input['usage_scope'] ?? 'config');
        if ($usageScope !== 'config') {
            throw new InvalidArgumentException('CloudFront 自动池组当前只允许 config 用途');
        }
        $domainProvider = apiDomainAutomationNormalizeGroupLabel(
            $input['domain_provider'] ?? 'cloudfront_default',
            '域名提供方',
            'cloudfront_default'
        );
        $certificateProvider = apiDomainAutomationNormalizeGroupLabel(
            $input['certificate_provider'] ?? 'cloudfront_default',
            '证书提供方',
            'cloudfront_default'
        );
        if ($domainProvider !== 'cloudfront_default' || $certificateProvider !== 'cloudfront_default') {
            throw new InvalidArgumentException('本期只使用 CloudFront 默认域名与默认证书');
        }
        $originDomain = apiDomainAutomationNormalizeOriginDomain(
            $input['origin_domain'] ?? 'yunzhuru-app-production.up.railway.app',
            $generationEnabled === 1
        );
        $publicPath = apiDomainAutomationNormalizePublicPath($input['public_path'] ?? '/shell.php');
        if ($publicPath !== '/shell.php') {
            throw new InvalidArgumentException('CloudFront 自动节点当前只服务 /shell.php');
        }
        $probeAppId = apiDomainAutomationPositiveInt(
            $input['probe_app_id'] ?? 0,
            '探针应用 APPID',
            0,
            4294967295
        );
        if ($generationEnabled === 1 && $probeAppId <= 0) {
            throw new InvalidArgumentException('启用自动生成前必须选择探针应用 APPID');
        }
        $priceClass = trim((string)($input['price_class'] ?? 'PriceClass_All'));
        if (!in_array($priceClass, ['PriceClass_100', 'PriceClass_200', 'PriceClass_All'], true)) {
            throw new InvalidArgumentException('CloudFront Price Class 参数错误');
        }
        return [
            'name' => apiDomainAutomationNormalizeName($input['name'] ?? ''),
            'cloud_account_id' => apiDomainAutomationPositiveInt($input['cloud_account_id'] ?? null, 'AWS 账号', 1, PHP_INT_MAX),
            'usage_scope' => $usageScope,
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
            'domain_provider' => $domainProvider,
            'certificate_provider' => $certificateProvider,
            'origin_domain' => $originDomain,
            'public_path' => $publicPath,
            'probe_app_id' => $probeAppId,
            'price_class' => $priceClass,
            'ipv6_enabled' => apiDomainAutomationNormalizeFlag(
                $input['ipv6_enabled'] ?? 1,
                'CloudFront IPv6 开关',
                1
            ),
            'enabled' => $enabled,
            'generation_enabled' => $generationEnabled,
            'capacity_mode' => $capacityMode,
            'target_active_count' => $target,
            'minimum_healthy_count' => $minimum,
            'interval_value' => apiDomainAutomationPositiveInt($input['interval_value'] ?? 1, '生成周期数值', 1, 10000),
            'interval_unit' => apiDomainAutomationNormalizeUnit($input['interval_unit'] ?? 'minute'),
            'generate_count' => apiDomainAutomationPositiveInt(
                $input['generate_per_run'] ?? ($input['generate_count'] ?? 30),
                '每次生成数量',
                1,
                30
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

if (!function_exists('apiDomainAutomationLoadProbeApp')) {
    /** 从数据库重新读取 Shell 探针所需应用身份，不接收前端 appkey 或表单字段。 */
    function apiDomainAutomationLoadProbeApp(PDO $pdo, int $appId): array
    {
        if ($appId <= 0) throw new RuntimeException('自动池组未配置探针应用 APPID');
        if (function_exists('ensureApkDeleteMarkerTable')) ensureApkDeleteMarkerTable($pdo);
        $stmt = $pdo->prepare("SELECT a.id,a.user_id,a.package,a.version
            FROM cainiao_apk a
            INNER JOIN cainiao_apk_config c ON c.apk_id=a.id
            LEFT JOIN cainiao_apk_deleted d ON d.apk_id=a.id
            WHERE a.id=:id AND d.apk_id IS NULL
            LIMIT 1");
        $stmt->execute([':id' => $appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app || (int)($app['user_id'] ?? 0) <= 0) {
            throw new RuntimeException('探针应用不存在、已删除或缺少配置');
        }
        return $app;
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
                $existingStmt = $pdo->prepare('SELECT id,enabled,capacity_mode,interval_value,interval_unit,
                        schedule_anchor_at,next_run_at
                    FROM cainiao_api_domain_automation_group
                    WHERE id=:id AND deleted_at IS NULL FOR UPDATE');
                $existingStmt->execute([':id' => $id]);
                $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) throw new RuntimeException('自动化池组不存在');
            }

            $account = $pdo->prepare('SELECT id,region,connection_state FROM cainiao_api_domain_cloud_account
                WHERE id=:id AND enabled=1 AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
            $account->execute([':id' => $row['cloud_account_id']]);
            $accountRow = $account->fetch(PDO::FETCH_ASSOC);
            if (!$accountRow) throw new RuntimeException('所选 AWS 账号不存在或已停用');
            // Region 是账号级资源边界；池组与账号必须使用同一 Region，避免页面保存值与
            // CloudFront 执行上下文分裂。比较前统一小写，入库仍保留规范化后的池组值。
            $accountRegion = strtolower(trim((string)($accountRow['region'] ?? '')));
            if ($accountRegion === '' || $accountRegion !== (string)$row['region']) {
                throw new InvalidArgumentException('池组 AWS Region 必须与所选账号 Region 一致');
            }
            $row['region'] = $accountRegion;
            if ((int)$row['probe_app_id'] > 0) {
                apiDomainAutomationLoadProbeApp($pdo, (int)$row['probe_app_id']);
            }

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
                $existingMode = apiDomainAutomationNormalizeCapacityMode($existing['capacity_mode'] ?? 'target_replenish');
                $scheduleChanged = $existingMode !== $row['capacity_mode']
                    || (int)$existing['interval_value'] !== (int)$row['interval_value']
                    || (string)$existing['interval_unit'] !== (string)$row['interval_unit'];
                if ($scheduleChanged) {
                    // 容量模式或旧周期参数变更时重建锚点；其它策略编辑保留原时钟。
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
                    } elseif ($row['capacity_mode'] === 'target_replenish') {
                        // 固定目标模式保存/启用后立即检查一次缺口，不等待“下一天”。
                        $next = $now;
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
                $next = $row['capacity_mode'] === 'target_replenish'
                    ? $now
                    : apiDomainAutomationNextRun(
                        $anchor,
                        $row['interval_value'],
                        $row['interval_unit'],
                        $now
                    );
            }

            $params = [
                ':name' => $row['name'], ':cloud_account_id' => $row['cloud_account_id'],
                ':usage_scope' => $row['usage_scope'], ':enabled' => $row['enabled'],
                ':capacity_mode' => $row['capacity_mode'],
                ':environment' => $row['environment'], ':region' => $row['region'],
                ':domain_provider' => $row['domain_provider'],
                ':certificate_provider' => $row['certificate_provider'],
                ':origin_domain' => $row['origin_domain'], ':public_path' => $row['public_path'],
                ':probe_app_id' => $row['probe_app_id'],
                ':price_class' => $row['price_class'], ':ipv6_enabled' => $row['ipv6_enabled'],
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
                    capacity_mode=:capacity_mode,environment=:environment,region=:region,domain_provider=:domain_provider,
                    certificate_provider=:certificate_provider,origin_domain=:origin_domain,
                    public_path=:public_path,probe_app_id=:probe_app_id,price_class=:price_class,
                    ipv6_enabled=:ipv6_enabled,generation_enabled=:generation_enabled,
                    target_active_count=:target_active_count,minimum_healthy_count=:minimum_healthy_count,
                    interval_value=:interval_value,interval_unit=:interval_unit,generate_count=:generate_count,
                    observation_days=:observation_days,idle_mark_days=:idle_mark_days,cleanup_enabled=:cleanup_enabled,
                    cleanup_no_access_days=:cleanup_no_access_days,schedule_anchor_at=:schedule_anchor_at,
                    next_run_at=:next_run_at,adapter_state=:adapter_state
                    WHERE id=:id AND deleted_at IS NULL");
                $stmt->execute($params + [
                    ':adapter_state' => (string)($accountRow['connection_state'] ?? 'pending_validation'),
                    ':id' => $id,
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO cainiao_api_domain_automation_group
                    (name,cloud_account_id,usage_scope,environment,region,domain_provider,certificate_provider,
                     origin_domain,public_path,probe_app_id,
                     price_class,ipv6_enabled,
                     enabled,generation_enabled,capacity_mode,target_active_count,minimum_healthy_count,
                     interval_value,interval_unit,generate_count,observation_days,idle_mark_days,cleanup_enabled,
                     cleanup_no_access_days,adapter_state,schedule_anchor_at,next_run_at)
                    VALUES (:name,:cloud_account_id,:usage_scope,:environment,:region,:domain_provider,
                     :certificate_provider,:origin_domain,:public_path,:probe_app_id,
                     :price_class,:ipv6_enabled,
                     :enabled,:generation_enabled,:capacity_mode,:target_active_count,:minimum_healthy_count,
                     :interval_value,:interval_unit,:generate_count,:observation_days,:idle_mark_days,:cleanup_enabled,
                     :cleanup_no_access_days,:adapter_state,:schedule_anchor_at,:next_run_at)");
                $stmt->execute($params + [
                    ':adapter_state' => (string)($accountRow['connection_state'] ?? 'pending_validation'),
                ]);
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
            return [
                'message' => $row['enabled']
                    ? ((string)($accountRow['connection_state'] ?? '') === 'connected'
                        ? '池组策略已保存并排程'
                        : '池组策略已保存，AWS 账号连接验证后开始生成')
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
        $pdo->beginTransaction();
        try {
            $group = $pdo->prepare('SELECT id FROM cainiao_api_domain_automation_group
                WHERE id=:id AND deleted_at IS NULL FOR UPDATE');
            $group->execute([':id' => $id]);
            if (!$group->fetchColumn()) throw new RuntimeException('自动化池组不存在');
            $resourceCheck = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_cloud_resource
                WHERE group_id=:id AND workflow_state<>'archived'");
            $resourceCheck->execute([':id' => $id]);
            if ((int)$resourceCheck->fetchColumn() > 0) {
                throw new RuntimeException('池组仍有未归档 CloudFront 资源，请先暂停并完成节点清理');
            }
            $jobCheck = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_cloud_job
                WHERE group_id=:id AND status IN ('pending','running','retry_wait')");
            $jobCheck->execute([':id' => $id]);
            if ((int)$jobCheck->fetchColumn() > 0) {
                throw new RuntimeException('池组仍有 CloudFront 作业在处理');
            }
            $stmt = $pdo->prepare("UPDATE cainiao_api_domain_automation_group
                SET enabled=0,next_run_at=NULL,deleted_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)
                WHERE id=:id AND deleted_at IS NULL");
            $stmt->execute([':id' => $id]);
            $pdo->commit();
            return ['message' => '自动化池组已归档，历史批次与节点记录保留'];
        } catch (Throwable $failure) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
        }
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
     * 使用真实下发的前 30 节点评估隔离影响。
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
                ORDER BY priority DESC,id ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
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
    /** 健康保底只统计真实前 30 运行集合中已验证且 active 的自动节点。 */
    function apiDomainAutomationHealthyEffectiveCount(PDO $pdo, int $groupId): int
    {
        $effectiveRows = function_exists('configDeliveryEnabledApiDomainRows')
            ? configDeliveryEnabledApiDomainRows($pdo)
            : $pdo->query("SELECT id,name,base_url,usage_scope,priority,updated_at
                FROM cainiao_api_domain_pool WHERE enabled=1
                ORDER BY priority DESC,id ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
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

if (!function_exists('apiDomainAutomationReservedResourceCount')) {
    /**
     * 尚未入池的 CloudFront slot 也占用目标容量。只有真实在途状态占位；
     * create_failed/probe_failed/cleanup_failed 是终态故障，必须释放容量，
     * 这样固定目标模式会在下一轮为失效节点补新入口，同时保留旧账本供人工重试。
     */
    function apiDomainAutomationReservedResourceCount(PDO $pdo, int $groupId): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_cloud_resource
            WHERE group_id=:group_id AND domain_pool_id IS NULL
              AND workflow_state IN (
                'pending_create','deploying','verifying',
                'disable_pending','disabling','delete_pending','restore_pending','restoring'
              )");
        $stmt->execute([':group_id' => $groupId]);
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

if (!function_exists('apiDomainAutomationQueueCloudJob')) {
    /** 入队一个有界作业；同一资源的未完成作业由调用方在行锁下去重。 */
    function apiDomainAutomationQueueCloudJob(
        PDO $pdo,
        int $resourceId,
        int $groupId,
        int $accountId,
        string $jobType,
        DateTimeImmutable $when,
        ?int $maximumAttempts = null
    ): int {
        $maximumByType = [
            'create' => 12,
            'poll_deploy' => 240,
            'probe' => 12,
            'disable' => 12,
            'poll_disable' => 240,
            'delete' => 12,
            'restore_enable' => 12,
            'poll_restore' => 240,
        ];
        if (!isset($maximumByType[$jobType])) throw new InvalidArgumentException('CloudFront 作业类型错误');
        $maximum = $maximumAttempts === null
            ? $maximumByType[$jobType]
            : max(1, min(1000, $maximumAttempts));
        $stmt = $pdo->prepare("INSERT INTO cainiao_api_domain_cloud_job
            (resource_id,group_id,cloud_account_id,job_type,status,attempt_count,max_attempts,
             next_attempt_at,lock_token,last_error_code,last_aws_request_id)
            VALUES (:resource_id,:group_id,:cloud_account_id,:job_type,'pending',0,:max_attempts,
             :next_attempt_at,'','','')");
        $stmt->execute([
            ':resource_id' => $resourceId, ':group_id' => $groupId,
            ':cloud_account_id' => $accountId, ':job_type' => $jobType,
            ':max_attempts' => $maximum, ':next_attempt_at' => apiDomainAutomationDateText($when),
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('apiDomainAutomationCreateResourceSlots')) {
    /** 将不可变批次展开成稳定 slot，CallerReference 在所有重试中保持不变。 */
    function apiDomainAutomationCreateResourceSlots(
        PDO $pdo,
        array $group,
        int $batchId,
        int $runId,
        int $plannedCount,
        DateTimeImmutable $now
    ): array {
        $resourceIds = [];
        $stmt = $pdo->prepare("INSERT INTO cainiao_api_domain_cloud_resource
            (group_id,batch_id,run_id,cloud_account_id,slot_index,caller_reference,
             expected_account_id,
             origin_domain,public_path,usage_scope,price_class,ipv6_enabled,workflow_state,next_action_at)
            VALUES (:group_id,:batch_id,:run_id,:cloud_account_id,:slot_index,:caller_reference,
             :expected_account_id,
             :origin_domain,:public_path,:usage_scope,:price_class,:ipv6_enabled,'pending_create',:next_action_at)");
        for ($slot = 1; $slot <= $plannedCount; $slot++) {
            $callerReference = sprintf(
                'yunzhuru-api-cdn:g%d:b%d:s%d',
                (int)$group['id'],
                $batchId,
                $slot
            );
            $stmt->execute([
                ':group_id' => (int)$group['id'], ':batch_id' => $batchId,
                ':run_id' => $runId, ':cloud_account_id' => (int)$group['cloud_account_id'],
                ':slot_index' => $slot, ':caller_reference' => $callerReference,
                ':expected_account_id' => (string)($group['aws_account_id'] ?? ''),
                ':origin_domain' => (string)$group['origin_domain'],
                ':public_path' => (string)$group['public_path'],
                ':usage_scope' => (string)$group['usage_scope'],
                ':price_class' => (string)($group['price_class'] ?? 'PriceClass_All'),
                ':ipv6_enabled' => (int)($group['ipv6_enabled'] ?? 1),
                ':next_action_at' => apiDomainAutomationDateText($now),
            ]);
            $resourceId = (int)$pdo->lastInsertId();
            apiDomainAutomationQueueCloudJob(
                $pdo,
                $resourceId,
                (int)$group['id'],
                (int)$group['cloud_account_id'],
                'create',
                $now
            );
            $resourceIds[] = $resourceId;
        }
        return $resourceIds;
    }
}

if (!function_exists('apiDomainAutomationNormalizeCloudFrontDomain')) {
    /** 只接受 AWS CloudFront 默认分配域名，防止云响应被当作任意探针目标。 */
    function apiDomainAutomationNormalizeCloudFrontDomain($value): string
    {
        $domain = strtolower(rtrim(trim((string)$value), '.'));
        if (preg_match('/^[a-z0-9-]{1,63}\.cloudfront\.net$/', $domain) !== 1) {
            throw new InvalidArgumentException('CloudFront 返回域名格式错误');
        }
        return $domain;
    }
}

if (!function_exists('apiDomainAutomationProbeCloudResource')) {
    /**
     * 使用服务端应用数据向账本 CloudFront 域名发起一次受限 Shell POST。
     * transport 仅供无网络 fixture 测试；默认复用 ApiConfigProbe 的 DNS 锁定、TLS、
     * 禁止跳转、超时和 1 MiB 流式限大合同。
     */
    function apiDomainAutomationProbeCloudResource(
        array $resource,
        array $app,
        ?callable $transport = null
    ): array {
        $domain = apiDomainAutomationNormalizeCloudFrontDomain($resource['domain_name'] ?? '');
        $path = apiDomainAutomationNormalizePublicPath($resource['public_path'] ?? '/shell.php');
        if ($path !== '/shell.php') throw new InvalidArgumentException('CloudFront 探针路径不在白名单');
        $appId = (int)($app['id'] ?? 0);
        $userId = (int)($app['user_id'] ?? 0);
        if ($appId <= 0 || $userId <= 0) throw new InvalidArgumentException('CloudFront 探针应用数据不完整');
        $package = trim((string)($app['package'] ?? ''));
        $version = trim((string)($app['version'] ?? ''));
        $url = 'https://' . $domain . $path;
        $postFields = [
            'package' => $package !== '' ? $package : ('cloudfront.probe.' . $appId),
            'version_name' => $version !== '' ? $version : '0',
            'version_code' => '0',
            'appid' => (string)$appId,
            'appkey' => (string)$userId,
            'did' => 'cloudfront_probe_' . $appId,
            'system_dns_ip' => '',
            'shell_version' => '153',
            'brand' => 'yunzhuru-worker',
            'model' => 'cloudfront-probe',
            'android_version' => '0',
            'sdk_int' => '0',
            'abi' => 'server-probe',
        ];
        $response = $transport !== null
            ? $transport($url, $postFields, 1048576)
            : apiConfigProbePost($url, $postFields, 1048576);
        if (!is_array($response)
            || (string)($response['state'] ?? '') !== 'received'
            || (int)($response['http_code'] ?? 0) !== 200
            || empty($response['body_complete'])
            || (string)($response['raw_body'] ?? '') === '') {
            throw new ApiDomainAutomationException('probe_request_failed');
        }
        $payload = bucketDecryptAppConfigPayload((string)$response['raw_body']);
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        $payloadAppId = $config['appid'] ?? ($config['app_id'] ?? null);
        if (!((is_int($payloadAppId) && $payloadAppId > 0)
            || (is_string($payloadAppId) && preg_match('/^[1-9]\d*$/', $payloadAppId) === 1))
            || (int)$payloadAppId !== $appId) {
            throw new ApiDomainAutomationException('probe_app_mismatch');
        }
        return [
            'state' => 'succeeded',
            'http_code' => 200,
            'elapsed_ms' => max(0, (int)($response['elapsed_ms'] ?? 0)),
            'cipher_sha256' => (string)($response['sha256'] ?? ''),
            'plain_sha256' => (string)($payload['plain_sha256'] ?? ''),
            'payload_app_id' => (string)$appId,
            'public_api_url' => $url,
        ];
    }
}

if (!function_exists('apiDomainAutomationAssertOwnedDistribution')) {
    /** 清理前同时核对 CallerReference 和适配器 Comment 标记，避免操作外部资源。 */
    function apiDomainAutomationAssertOwnedDistribution(array $resource, array $config): void
    {
        $callerReference = (string)($resource['caller_reference'] ?? '');
        if ($callerReference === ''
            || !hash_equals($callerReference, (string)($config['caller_reference'] ?? ''))) {
            throw new ApiDomainAutomationException('ownership_mismatch');
        }
        $token = substr(hash('sha256', $callerReference), 0, 32);
        $comment = (string)($config['comment'] ?? '');
        if (strpos($comment, 'caller_hash=' . $token) === false
            || strpos($comment, 'resource_token=' . $token) === false) {
            throw new ApiDomainAutomationException('ownership_mismatch');
        }
    }
}

if (!function_exists('apiDomainAutomationResourceToken')) {
    /**
     * 资源账本当前以 CallerReference 作为唯一稳定主键；resource_token 不单独入表时，
     * 由同一 CallerReference 派生，确保清理和恢复使用与创建完全一致的标记。
     */
    function apiDomainAutomationResourceToken(array $resource): string
    {
        $token = trim((string)($resource['resource_token'] ?? ''));
        if ($token === '') {
            $token = substr(hash('sha256', (string)($resource['caller_reference'] ?? '')), 0, 32);
        }
        if (preg_match('/^[a-f0-9]{32,48}$/', $token) !== 1) {
            throw new ApiDomainAutomationException('ownership_mismatch');
        }
        return $token;
    }
}

if (!function_exists('apiDomainAutomationAssertOwnedDistributionResult')) {
    /**
     * 创建或 CallerReference 恢复的摘要也必须带有同一所有权标记，
     * 防止把扫描到的外部分配误认成本地资源。完整配置校验由 owned 适配器继续负责。
     */
    function apiDomainAutomationAssertOwnedDistributionResult(array $resource, array $result): void
    {
        $caller = (string)($resource['caller_reference'] ?? '');
        $actualCaller = (string)($result['caller_reference'] ?? '');
        $expectedToken = apiDomainAutomationResourceToken($resource);
        $actualToken = strtolower(trim((string)($result['resource_token'] ?? '')));
        if ($caller === '' || $actualCaller === '' || !hash_equals($caller, $actualCaller)
            || $actualToken === '' || !hash_equals(strtolower($expectedToken), $actualToken)) {
            throw new ApiDomainAutomationException('ownership_mismatch');
        }
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
            $stmt = $pdo->prepare('SELECT g.*,a.enabled AS account_enabled,a.deleted_at AS account_deleted_at,
                    a.account_id AS aws_account_id,a.region AS account_region,
                    a.credential_ref AS account_credential_ref,a.auth_type AS account_auth_type,
                    a.role_arn AS account_role_arn,a.external_id_ref AS account_external_id_ref,
                    a.connection_state AS account_connection_state
                FROM cainiao_api_domain_automation_group g
                LEFT JOIN cainiao_api_domain_cloud_account a ON a.id=g.cloud_account_id
                WHERE g.id=:id AND g.deleted_at IS NULL FOR UPDATE');
            $stmt->execute([':id' => $groupId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) throw new RuntimeException('自动化池组不存在');
            $capacityMode = apiDomainAutomationNormalizeCapacityMode($group['capacity_mode'] ?? 'target_replenish');
            // 与账号编辑使用同一身份锁：先持有池组，再锁所选账号行，
            // 确保下面写入资源账本时的 expected_account_id 来自未被并发修改的快照。
            $accountLock = $pdo->prepare('SELECT id,enabled,deleted_at,account_id,region,
                    credential_ref,auth_type,role_arn,external_id_ref,connection_state
                FROM cainiao_api_domain_cloud_account
                WHERE id=:id LIMIT 1 FOR UPDATE');
            $accountLock->execute([':id' => (int)$group['cloud_account_id']]);
            $lockedAccount = $accountLock->fetch(PDO::FETCH_ASSOC);
            if (!$lockedAccount || (int)$lockedAccount['enabled'] !== 1 || !empty($lockedAccount['deleted_at'])) {
                throw new RuntimeException('所选 AWS 账号已停用或已归档');
            }
            // 用行锁后的值覆盖 JOIN 快照，避免优化器或旧连接返回过期身份字段。
            $group['account_enabled'] = $lockedAccount['enabled'];
            $group['account_deleted_at'] = $lockedAccount['deleted_at'];
            $group['aws_account_id'] = $lockedAccount['account_id'];
            $group['account_region'] = $lockedAccount['region'];
            $group['account_credential_ref'] = $lockedAccount['credential_ref'];
            $group['account_auth_type'] = $lockedAccount['auth_type'];
            $group['account_role_arn'] = $lockedAccount['role_arn'];
            $group['account_external_id_ref'] = $lockedAccount['external_id_ref'];
            $group['account_connection_state'] = $lockedAccount['connection_state'];
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
                $scheduleDue = $scheduledFor !== ''
                    && new DateTimeImmutable($scheduledFor, apiDomainAutomationTimezone()) <= $now;
                // 固定目标模式允许 next_run_at 为空时立即补齐；周期兼容模式仍要求明确到期时间。
                if (($capacityMode === 'periodic' && !$scheduleDue)
                    || ($capacityMode === 'target_replenish' && $scheduledFor !== '' && !$scheduleDue)) {
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
            $reservedCapacity = apiDomainAutomationReservedResourceCount($pdo, $groupId);
            $capacityCount = $currentEligible + $reservedCapacity;
            $gap = max(0, (int)$group['target_active_count'] - $capacityCount);
            $generationEnabled = (int)($group['generation_enabled'] ?? $group['enabled']) === 1;
            $planned = $generationEnabled ? min((int)$group['generate_count'], $gap) : 0;
            $configurationReady = trim((string)($group['origin_domain'] ?? '')) !== ''
                && (string)($group['public_path'] ?? '') === '/shell.php'
                && (int)($group['probe_app_id'] ?? 0) > 0;
            $connectionReady = (string)($group['account_connection_state'] ?? '') === 'connected'
                && trim((string)($group['account_credential_ref'] ?? '')) !== '';
            if ($dryRun) {
                $status = 'dry_run';
                $message = $capacityMode === 'target_replenish'
                    ? "固定目标预览：当前可用 {$currentEligible} 个，在途占位 {$reservedCapacity} 个，目标缺口 {$gap} 个，本轮补 {$planned} 个；达到目标后停止生成"
                    : "周期 Dry-run 预览：当前可用 {$currentEligible} 个，在途占位 {$reservedCapacity} 个，缺口 {$gap} 个，计划补 {$planned} 个";
                // 两种模式都显示配置与连接门禁，避免固定目标预览隐藏未就绪原因。
                if (!$configurationReady && $planned > 0) $message .= '；尚未配置有效回源与探针应用';
                if (!$connectionReady && $planned > 0) $message .= '；AWS 账号尚未通过连接验证';
            } elseif ($planned > 0) {
                if (!$configurationReady) {
                    $status = 'invalid_config';
                    $message = "当前缺口 {$gap} 个，本轮未入队：请配置回源域名与探针应用";
                } elseif (!$connectionReady) {
                    $status = 'waiting_connection';
                    $message = "当前缺口 {$gap} 个，本轮未入队：请先完成 AWS 连接验证";
                } else {
                    $status = 'queued';
                    $message = $capacityMode === 'target_replenish'
                        ? "固定目标缺口 {$gap} 个，已入队 {$planned} 个 CloudFront 生成作业"
                        : "当前缺口 {$gap} 个，已入队 {$planned} 个 CloudFront 生成作业";
                }
            } elseif ((int)$lifecycle['cleanup_pending_count'] > 0) {
                $status = 'succeeded';
                $message = '无访问节点已进入待清理并从本地分发隔离，云资源等待适配器处理';
            } elseif (!$generationEnabled) {
                $status = 'skipped';
                $message = '自动生成已暂停，本轮仅执行生命周期检查';
            } else {
                $status = 'skipped';
                $message = $capacityMode === 'target_replenish'
                    ? '固定目标容量已满，本轮不生成'
                    : '当前容量已达目标，本轮跳过生成';
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
            $resourceIds = [];
            if (!$dryRun && $status === 'queued' && $batch) {
                $resourceIds = apiDomainAutomationCreateResourceSlots(
                    $pdo,
                    $group,
                    (int)$batch['id'],
                    $runId,
                    $planned,
                    $now
                );
            }

            $nextRun = null;
            if ($capacityMode === 'target_replenish' && (int)$group['enabled'] === 1) {
                // 固定目标模式只负责监控缺口；每分钟再检查一次，不按天制造新域名。
                $nextRun = $now->modify('+60 seconds');
            } elseif ($triggerType === 'scheduled') {
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
                    adapter_state=:adapter_state WHERE id=:id");
                $update->execute([
                    ':last_run_at' => apiDomainAutomationDateText($now),
                    ':last_run_status' => $status,
                    ':last_run_message' => apiDomainAutomationLimitText($message, 255),
                    ':next_run_at' => $nextRun ? apiDomainAutomationDateText($nextRun) : null,
                    ':adapter_state' => $connectionReady ? 'connected' : (string)($group['account_connection_state'] ?? 'waiting_connection'),
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
                'pending_resource_count' => $reservedCapacity,
                'reserved_capacity_count' => $reservedCapacity,
                'capacity_count' => $capacityCount,
                'capacity_gap' => $gap,
                'planned_count' => $planned,
                'created_count' => 0,
                'queued_resource_count' => count($resourceIds),
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
            $stmt = $pdo->prepare('SELECT schedule_anchor_at,capacity_mode,interval_value,interval_unit
                FROM cainiao_api_domain_automation_group
                WHERE id=:id AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([':id' => $groupId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$group) throw new RuntimeException('自动化池组不存在');
            $nextRun = null;
            if ($enabled) {
                $now = apiDomainAutomationNow();
                $capacityMode = apiDomainAutomationNormalizeCapacityMode($group['capacity_mode'] ?? 'target_replenish');
                if ($capacityMode === 'target_replenish') {
                    // 恢复固定目标组后立即触发一次缺口检查。
                    $nextRun = $now;
                } else {
                    $anchor = new DateTimeImmutable(
                        (string)$group['schedule_anchor_at'],
                        apiDomainAutomationTimezone()
                    );
                    $nextRun = apiDomainAutomationNextRun(
                        $anchor,
                        (int)$group['interval_value'],
                        (string)$group['interval_unit'],
                        $now
                    );
                }
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
            $stmt = $pdo->prepare('SELECT schedule_anchor_at,capacity_mode,interval_value,interval_unit,next_run_at
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
            $capacityMode = apiDomainAutomationNormalizeCapacityMode($group['capacity_mode'] ?? 'target_replenish');
            $clockWasReplaced = $expectedScheduledFor !== null
                && trim($expectedScheduledFor) !== $scheduledFor;
            $isStillDue = $capacityMode === 'target_replenish'
                ? ($scheduledFor === '' || new DateTimeImmutable($scheduledFor, apiDomainAutomationTimezone()) <= $now)
                : ($scheduledFor !== '' && new DateTimeImmutable($scheduledFor, apiDomainAutomationTimezone()) <= $now);
            if ($clockWasReplaced || !$isStillDue) {
                // worker 预选后若管理员更新了调度时钟，旧失败结果不覆盖新 next_run_at。
                $pdo->commit();
                return false;
            }
            if ($capacityMode === 'target_replenish') {
                $nextRun = $now->modify('+60 seconds');
            } else {
                $anchor = new DateTimeImmutable((string)$group['schedule_anchor_at'], apiDomainAutomationTimezone());
                $nextRun = apiDomainAutomationNextRun(
                    $anchor,
                    (int)$group['interval_value'],
                    (string)$group['interval_unit'],
                    $now
                );
            }
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
                WHERE deleted_at IS NULL AND enabled=1
                  AND ((capacity_mode='target_replenish' AND (next_run_at IS NULL
                        OR next_run_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)))
                    OR (capacity_mode<>'target_replenish' AND next_run_at IS NOT NULL
                        AND next_run_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)))
                ORDER BY COALESCE(next_run_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)),id ASC LIMIT {$limit}");
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

if (!function_exists('apiDomainAutomationQueueCleanupJobs')) {
    /** 把已本地隔离的节点连接到资源账本，再入队禁用作业。 */
    function apiDomainAutomationQueueCleanupJobs(PDO $pdo, int $limit = 20): array
    {
        ensureApiDomainAutomationSchema($pdo);
        $limit = max(1, min(100, $limit));
        $stmt = $pdo->query("SELECT p.id AS node_id,r.id AS resource_id,r.group_id,r.cloud_account_id,
                r.workflow_state
            FROM cainiao_api_domain_pool p
            INNER JOIN cainiao_api_domain_cloud_resource r
                ON r.domain_pool_id=p.id
            WHERE p.origin='aws_auto' AND p.lifecycle_status='cleanup_pending'
              AND p.cleanup_protected=0 AND p.pinned=0 AND p.reserved=0
              AND r.workflow_state IN ('ready','cleanup_failed')
            ORDER BY p.cleanup_requested_at ASC,p.id ASC
            LIMIT {$limit}");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $queued = 0;
        foreach ($rows as $row) {
            $pdo->beginTransaction();
            try {
                // 显式按 pool -> resource 顺序取锁，避免 JOIN 优化器改变锁顺序。
                $poolLock = $pdo->prepare('SELECT lifecycle_status,cleanup_protected,pinned,reserved
                    FROM cainiao_api_domain_pool WHERE id=:id FOR UPDATE');
                $poolLock->execute([':id' => (int)$row['node_id']]);
                $poolCurrent = $poolLock->fetch(PDO::FETCH_ASSOC);
                $resourceLock = $pdo->prepare('SELECT workflow_state,domain_pool_id
                    FROM cainiao_api_domain_cloud_resource WHERE id=:id FOR UPDATE');
                $resourceLock->execute([':id' => (int)$row['resource_id']]);
                $resourceCurrent = $resourceLock->fetch(PDO::FETCH_ASSOC);
                $current = $poolCurrent && $resourceCurrent
                    && (int)$resourceCurrent['domain_pool_id'] === (int)$row['node_id']
                    ? $poolCurrent + ['workflow_state' => $resourceCurrent['workflow_state']]
                    : null;
                if (!$current
                    || (string)$current['lifecycle_status'] !== 'cleanup_pending'
                    || (int)$current['cleanup_protected'] === 1
                    || (int)$current['pinned'] === 1
                    || (int)$current['reserved'] === 1
                    || !in_array((string)$current['workflow_state'], ['ready', 'cleanup_failed'], true)) {
                    $pdo->commit();
                    continue;
                }
                $active = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_cloud_job
                    WHERE resource_id=:resource_id AND status IN ('pending','running','retry_wait')");
                $active->execute([':resource_id' => (int)$row['resource_id']]);
                if ((int)$active->fetchColumn() > 0) {
                    $pdo->commit();
                    continue;
                }
                $now = apiDomainAutomationNow();
                apiDomainAutomationQueueCloudJob(
                    $pdo,
                    (int)$row['resource_id'],
                    (int)$row['group_id'],
                    (int)$row['cloud_account_id'],
                    'disable',
                    $now
                );
                $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                        workflow_state='disable_pending',next_action_at=:next_action_at,last_error_code=''
                    WHERE id=:id")
                    ->execute([
                        ':next_action_at' => apiDomainAutomationDateText($now),
                        ':id' => (int)$row['resource_id'],
                    ]);
                $pdo->prepare("UPDATE cainiao_api_domain_pool SET cloud_cleanup_state='queued'
                    WHERE id=:id AND lifecycle_status='cleanup_pending'")
                    ->execute([':id' => (int)$row['node_id']]);
                $pdo->commit();
                $queued++;
            } catch (Throwable $failure) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $failure;
            }
        }
        return ['checked' => count($rows), 'queued' => $queued];
    }
}

if (!function_exists('apiDomainAutomationCleanupGuard')) {
    /** 每次云端清理前后都重查成功访问和保护位，不依赖 worker 旧快照。 */
    function apiDomainAutomationCleanupGuard(PDO $pdo, int $resourceId): array
    {
        $stmt = $pdo->prepare("SELECT p.id AS domain_pool_id,p.lifecycle_status,p.cleanup_protected,
                p.pinned,p.reserved,p.access_count,
                COALESCE(SUM(s.ok_count),0) AS stats_ok_count
            FROM cainiao_api_domain_cloud_resource r
            INNER JOIN cainiao_api_domain_pool p ON p.id=r.domain_pool_id
            LEFT JOIN cainiao_api_domain_stats s ON s.domain_pool_id=p.id
            WHERE r.id=:resource_id
            GROUP BY p.id,p.lifecycle_status,p.cleanup_protected,p.pinned,p.reserved,p.access_count");
        $stmt->execute([':resource_id' => $resourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $lifetimeAccess = $row
            ? max((int)$row['access_count'], (int)$row['stats_ok_count'])
            : 0;
        $allowed = $row
            && (string)$row['lifecycle_status'] === 'cleanup_pending'
            && (int)$row['cleanup_protected'] === 0
            && (int)$row['pinned'] === 0
            && (int)$row['reserved'] === 0
            && $lifetimeAccess === 0;
        return [
            'allowed' => $allowed ? 1 : 0,
            'domain_pool_id' => (int)($row['domain_pool_id'] ?? 0),
            'lifecycle_status' => (string)($row['lifecycle_status'] ?? ''),
            'lifetime_access_count' => $lifetimeAccess,
        ];
    }
}

if (!function_exists('apiDomainAutomationLockCloudLedger')) {
    /**
     * 在同一事务中按 pool -> resource -> job 锁定云资源账本。
     * 未绑定 pool 的创建阶段从 resource 开始；返回值只含状态快照，调用方仍需做业务门禁。
     */
    function apiDomainAutomationLockCloudLedger(PDO $pdo, array $context, array $job): ?array
    {
        $poolId = (int)($context['domain_pool_id'] ?? 0);
        if ($poolId <= 0) {
            // 先做非锁定提示读取，随后仍以 FOR UPDATE 重新确认；这样正常路径始终 pool -> resource。
            $hintStmt = $pdo->prepare('SELECT domain_pool_id FROM cainiao_api_domain_cloud_resource
                WHERE id=:id LIMIT 1');
            $hintStmt->execute([':id' => (int)$job['resource_id']]);
            $poolId = (int)$hintStmt->fetchColumn();
        }
        $pool = null;
        if ($poolId > 0) {
            $poolLock = $pdo->prepare('SELECT * FROM cainiao_api_domain_pool WHERE id=:id FOR UPDATE');
            $poolLock->execute([':id' => $poolId]);
            $pool = $poolLock->fetch(PDO::FETCH_ASSOC);
            if (!$pool) return null;
        }
        $resourceLock = $pdo->prepare('SELECT * FROM cainiao_api_domain_cloud_resource
            WHERE id=:id FOR UPDATE');
        $resourceLock->execute([':id' => (int)$job['resource_id']]);
        $resource = $resourceLock->fetch(PDO::FETCH_ASSOC);
        if (!$resource) return null;
        $actualPoolId = (int)($resource['domain_pool_id'] ?? 0);
        if ($actualPoolId !== $poolId) {
            // 绑定关系在提示读取后发生变化时交给上层重试，避免 resource -> pool 反向加锁。
            return null;
        }
        $jobLock = $pdo->prepare("SELECT id,status,lock_token,cancel_requested
            FROM cainiao_api_domain_cloud_job
            WHERE id=:id AND status='running' AND lock_token=:lock_token FOR UPDATE");
        $jobLock->execute([':id' => (int)$job['id'], ':lock_token' => (string)$job['lock_token']]);
        $lockedJob = $jobLock->fetch(PDO::FETCH_ASSOC);
        if (!$lockedJob) return null;
        return ['pool_id' => $poolId, 'pool' => $pool, 'resource' => $resource, 'job' => $lockedJob];
    }
}

if (!function_exists('apiDomainAutomationLockedCleanupAllowed')) {
    /** 已持有 pool 行锁时再次核对访问、保护和生命周期，供 AWS 回执落账前使用。 */
    function apiDomainAutomationLockedCleanupAllowed(PDO $pdo, int $poolId, array $pool): bool
    {
        if ($poolId <= 0
            || (string)($pool['lifecycle_status'] ?? '') !== 'cleanup_pending'
            || (int)($pool['cleanup_protected'] ?? 1) === 1
            || (int)($pool['pinned'] ?? 1) === 1
            || (int)($pool['reserved'] ?? 1) === 1) {
            return false;
        }
        $stats = $pdo->prepare('SELECT COALESCE(SUM(ok_count),0)
            FROM cainiao_api_domain_stats WHERE domain_pool_id=:id');
        $stats->execute([':id' => $poolId]);
        return max((int)($pool['access_count'] ?? 0), (int)$stats->fetchColumn()) === 0;
    }
}

if (!function_exists('apiDomainAutomationHandleSuccessfulReceipt')) {
    /**
     * 在成功回执已持有 pool 行锁的事务内取消未开始清理；
     * 若分配已禁用，将恢复作业入队后再重新对外分发。
     */
    function apiDomainAutomationHandleSuccessfulReceipt(PDO $pdo, int $domainPoolId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM cainiao_api_domain_cloud_resource
            WHERE domain_pool_id=:domain_pool_id LIMIT 1 FOR UPDATE');
        $stmt->execute([':domain_pool_id' => $domainPoolId]);
        $resource = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$resource) return ['resource_found' => 0, 'provider_ready' => 1, 'restore_queued' => 0];
        $resourceId = (int)$resource['id'];
        $pdo->prepare("UPDATE cainiao_api_domain_cloud_job SET
                status='cancelled',cancel_requested=1,lock_token='',locked_at=NULL,
                finished_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),
                last_error_code='successful_access_restored'
            WHERE resource_id=:resource_id
              AND job_type IN ('disable','poll_disable','delete')
              AND status IN ('pending','retry_wait')")
            ->execute([':resource_id' => $resourceId]);
        $pdo->prepare("UPDATE cainiao_api_domain_cloud_job SET cancel_requested=1,
                last_error_code='successful_access_restored'
            WHERE resource_id=:resource_id
              AND job_type IN ('disable','poll_disable','delete') AND status='running'")
            ->execute([':resource_id' => $resourceId]);

        $providerReady = (int)$resource['provider_enabled'] === 1
            && !in_array((string)$resource['workflow_state'], ['disabling', 'delete_pending', 'restore_pending', 'restoring'], true);
        if ($providerReady) {
            $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                    workflow_state='ready',delete_not_before=NULL,next_action_at=NULL,last_error_code=''
                WHERE id=:id")->execute([':id' => $resourceId]);
            return ['resource_found' => 1, 'provider_ready' => 1, 'restore_queued' => 0];
        }

        $active = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_cloud_job
            WHERE resource_id=:resource_id AND job_type IN ('restore_enable','poll_restore')
              AND status IN ('pending','running','retry_wait')");
        $active->execute([':resource_id' => $resourceId]);
        $restoreQueued = 0;
        if ((int)$active->fetchColumn() === 0) {
            $now = apiDomainAutomationNow();
            apiDomainAutomationQueueCloudJob(
                $pdo,
                $resourceId,
                (int)$resource['group_id'],
                (int)$resource['cloud_account_id'],
                'restore_enable',
                $now
            );
            $restoreQueued = 1;
        }
        $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                workflow_state='restore_pending',delete_not_before=NULL,
                next_action_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),last_error_code=''
            WHERE id=:id")->execute([':id' => $resourceId]);
        return ['resource_found' => 1, 'provider_ready' => 0, 'restore_queued' => $restoreQueued];
    }
}

if (!function_exists('apiDomainAutomationCancelCleanupJob')) {
    /** 清理条件变化时结束当前租约，并按云端启用状态恢复或入队恢复作业。 */
    function apiDomainAutomationCancelCleanupJob(
        PDO $pdo,
        array $job,
        array $context,
        bool $providerStillEnabled
    ): bool {
        $poolId = (int)($context['domain_pool_id'] ?? 0);
        if ($poolId <= 0) {
            $hintStmt = $pdo->prepare('SELECT domain_pool_id FROM cainiao_api_domain_cloud_resource
                WHERE id=:id LIMIT 1');
            $hintStmt->execute([':id' => (int)$job['resource_id']]);
            $poolId = (int)$hintStmt->fetchColumn();
        }
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            // 成功回执固定使用 pool -> resource -> job；取消路径保持同一顺序。
            if ($poolId > 0) {
                $poolLock = $pdo->prepare('SELECT id FROM cainiao_api_domain_pool WHERE id=:id FOR UPDATE');
                $poolLock->execute([':id' => $poolId]);
                if (!$poolLock->fetchColumn()) {
                    if ($ownTransaction) $pdo->rollBack();
                    return false;
                }
            }
            $resourceLock = $pdo->prepare('SELECT domain_pool_id FROM cainiao_api_domain_cloud_resource
                WHERE id=:id FOR UPDATE');
            $resourceLock->execute([':id' => (int)$job['resource_id']]);
            $resource = $resourceLock->fetch(PDO::FETCH_ASSOC);
            if (!$resource) {
                if ($ownTransaction) $pdo->rollBack();
                return false;
            }
            $actualPoolId = (int)($resource['domain_pool_id'] ?? 0);
            if ($actualPoolId !== $poolId) {
                // 关联关系在提示读取后发生变化时交给上层重试，避免反向加锁。
                if ($ownTransaction) $pdo->rollBack();
                return false;
            }
            $stmt = $pdo->prepare("UPDATE cainiao_api_domain_cloud_job SET
                    status='cancelled',cancel_requested=1,lock_token='',locked_at=NULL,
                    finished_at=:finished_at,last_error_code='successful_access_restored'
                WHERE id=:id AND status='running' AND lock_token=:lock_token");
            $stmt->execute([
                ':finished_at' => apiDomainAutomationDateText(apiDomainAutomationNow()),
                ':id' => (int)$job['id'], ':lock_token' => (string)$job['lock_token'],
            ]);
            if ($stmt->rowCount() !== 1) {
                if ($ownTransaction) $pdo->rollBack();
                return false;
            }
            if ($providerStillEnabled) {
                $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                        workflow_state='ready',provider_enabled=1,delete_not_before=NULL,
                        next_action_at=NULL,last_error_code=''
                    WHERE id=:id")->execute([':id' => (int)$job['resource_id']]);
                if ($poolId > 0) {
                    $pdo->prepare("UPDATE cainiao_api_domain_pool SET enabled=1,
                            lifecycle_status='active',cloud_cleanup_state='not_required',
                            lifecycle_updated_at=:updated_at,cleanup_requested_at=NULL,
                            idle_marked_at=NULL,cleanup_reason=''
                        WHERE id=:id AND origin='aws_auto'")->execute([
                            ':updated_at' => apiDomainAutomationDateText(apiDomainAutomationNow()),
                            ':id' => $poolId,
                        ]);
                }
            } else {
                $active = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_cloud_job
                    WHERE resource_id=:id AND job_type IN ('restore_enable','poll_restore')
                      AND status IN ('pending','running','retry_wait')");
                $active->execute([':id' => (int)$job['resource_id']]);
                if ((int)$active->fetchColumn() === 0) {
                    apiDomainAutomationQueueCloudJob(
                        $pdo,
                        (int)$job['resource_id'],
                        (int)$context['group_id'],
                        (int)$context['cloud_account_id'],
                        'restore_enable',
                        apiDomainAutomationNow()
                    );
                }
                $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                        workflow_state='restore_pending',provider_enabled=0,delete_not_before=NULL,
                        next_action_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)
                    WHERE id=:id")->execute([':id' => (int)$job['resource_id']]);
                if ($poolId > 0) {
                    $pdo->prepare("UPDATE cainiao_api_domain_pool SET
                            enabled=0,lifecycle_status='active',cloud_cleanup_state='restoring',
                            lifecycle_updated_at=:updated_at
                        WHERE id=:id AND origin='aws_auto'")->execute([
                            ':updated_at' => apiDomainAutomationDateText(apiDomainAutomationNow()),
                            ':id' => $poolId,
                        ]);
                }
            }
            if ($ownTransaction) $pdo->commit();
            if ($ownTransaction && function_exists('configDeliveryInvalidateAndSync')) {
                configDeliveryInvalidateAndSync($pdo);
            }
            return true;
        } catch (Throwable $failure) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
        }
    }
}

if (!function_exists('apiDomainAutomationClaimCloudJob')) {
    /** 领取一个到期作业；超过 10 分钟的旧 running 租约先回到 retry_wait。 */
    function apiDomainAutomationClaimCloudJob(PDO $pdo): ?array
    {
        $now = apiDomainAutomationNow();
        $nowText = apiDomainAutomationDateText($now);
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE cainiao_api_domain_cloud_job SET
                    status='retry_wait',lock_token='',locked_at=NULL,
                    next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),
                    last_error_code='lease_expired'
                WHERE status='running'
                  AND locked_at<DATE_SUB(DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),INTERVAL 10 MINUTE)");
            $stmt = $pdo->query("SELECT j.* FROM cainiao_api_domain_cloud_job j
                WHERE j.status IN ('pending','retry_wait')
                  AND j.next_attempt_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)
                ORDER BY j.next_attempt_at ASC,j.id ASC
                LIMIT 1 FOR UPDATE");
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $pdo->commit();
                return null;
            }
            $token = bin2hex(random_bytes(16));
            $update = $pdo->prepare("UPDATE cainiao_api_domain_cloud_job SET
                    status='running',attempt_count=attempt_count+1,lock_token=:lock_token,
                    locked_at=:locked_at,started_at=COALESCE(started_at,:started_at)
                WHERE id=:id AND status IN ('pending','retry_wait')");
            $update->execute([
                ':lock_token' => $token, ':locked_at' => $nowText,
                ':started_at' => $nowText, ':id' => (int)$job['id'],
            ]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $pdo->commit();
            $job['lock_token'] = $token;
            $job['attempt_count'] = (int)$job['attempt_count'] + 1;
            return $job;
        } catch (Throwable $failure) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
        }
    }
}

if (!function_exists('apiDomainAutomationLoadCloudExecutionContext')) {
    /** 每次执行都重新读取资源、池组和账号，不使用入队时的身份快照。 */
    function apiDomainAutomationLoadCloudExecutionContext(PDO $pdo, int $resourceId): array
    {
        $stmt = $pdo->prepare('SELECT r.*,
                g.deleted_at AS group_deleted_at,g.probe_app_id,g.observation_days,g.name AS group_name,
                a.account_id AS aws_account_id,a.region AS account_region,
                a.credential_ref,a.auth_type,a.role_arn,a.external_id_ref,
                a.enabled AS account_enabled,a.deleted_at AS account_deleted_at,
                a.connection_state
            FROM cainiao_api_domain_cloud_resource r
            LEFT JOIN cainiao_api_domain_automation_group g ON g.id=r.group_id
            LEFT JOIN cainiao_api_domain_cloud_account a ON a.id=r.cloud_account_id
            WHERE r.id=:id LIMIT 1');
        $stmt->execute([':id' => $resourceId]);
        $context = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$context) throw new ApiDomainAutomationException('resource_not_found');
        return $context;
    }
}

if (!function_exists('apiDomainAutomationFinishCloudJob')) {
    /** 只有持有当前租约令牌的 worker 才能将作业置为成功。 */
    function apiDomainAutomationFinishCloudJob(PDO $pdo, array $job): bool
    {
        $stmt = $pdo->prepare("UPDATE cainiao_api_domain_cloud_job SET
                status='succeeded',lock_token='',locked_at=NULL,last_error_code='',
                finished_at=:finished_at
            WHERE id=:id AND status='running' AND lock_token=:lock_token");
        $stmt->execute([
            ':finished_at' => apiDomainAutomationDateText(apiDomainAutomationNow()),
            ':id' => (int)$job['id'], ':lock_token' => (string)$job['lock_token'],
        ]);
        return $stmt->rowCount() === 1;
    }
}

if (!function_exists('apiDomainAutomationRescheduleCloudJob')) {
    /** 部署轮询和短暂错误都在原作业上指数退避，受 max_attempts 硬上限约束。 */
    function apiDomainAutomationRescheduleCloudJob(
        PDO $pdo,
        array $job,
        string $reasonCode,
        string $requestId = '',
        ?int $delaySeconds = null
    ): bool {
        $attempt = max(1, (int)$job['attempt_count']);
        $delay = $delaySeconds === null
            ? min(900, 15 * (2 ** min(6, $attempt - 1)))
            : max(15, min(3600, $delaySeconds));
        $next = apiDomainAutomationNow()->modify('+' . $delay . ' seconds');
        $poolHintStmt = $pdo->prepare('SELECT domain_pool_id FROM cainiao_api_domain_cloud_resource
            WHERE id=:id LIMIT 1');
        $poolHintStmt->execute([':id' => (int)$job['resource_id']]);
        $poolHint = (int)$poolHintStmt->fetchColumn();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            // 统一资源账本锁顺序：pool -> resource -> job（未绑定 pool 时从 resource 开始）。
            if ($poolHint > 0) {
                $poolLock = $pdo->prepare('SELECT id FROM cainiao_api_domain_pool WHERE id=:id FOR UPDATE');
                $poolLock->execute([':id' => $poolHint]);
                if (!$poolLock->fetchColumn()) {
                    if ($ownTransaction) $pdo->rollBack();
                    return false;
                }
            }
            $resourceLock = $pdo->prepare('SELECT id,domain_pool_id FROM cainiao_api_domain_cloud_resource
                WHERE id=:id FOR UPDATE');
            $resourceLock->execute([':id' => (int)$job['resource_id']]);
            $resource = $resourceLock->fetch(PDO::FETCH_ASSOC);
            if (!$resource || (int)($resource['domain_pool_id'] ?? 0) !== $poolHint) {
                if ($ownTransaction) $pdo->rollBack();
                return false;
            }
            $stmt = $pdo->prepare("UPDATE cainiao_api_domain_cloud_job SET
                    status='retry_wait',lock_token='',locked_at=NULL,
                    next_attempt_at=:next_attempt_at,last_error_code=:error_code,
                    last_aws_request_id=:request_id
                WHERE id=:id AND status='running' AND lock_token=:lock_token
                  AND attempt_count<max_attempts");
            $stmt->execute([
                ':next_attempt_at' => apiDomainAutomationDateText($next),
                ':error_code' => apiDomainAutomationLimitText($reasonCode, 64),
                ':request_id' => apiDomainAutomationLimitText($requestId, 128),
                ':id' => (int)$job['id'], ':lock_token' => (string)$job['lock_token'],
            ]);
            if ($stmt->rowCount() !== 1) {
                if ($ownTransaction) $pdo->rollBack();
                return false;
            }
            $pdo->prepare('UPDATE cainiao_api_domain_cloud_resource SET
                    retry_count=retry_count+1,next_action_at=:next_action_at,
                    last_error_code=:error_code,last_aws_request_id=:request_id
                WHERE id=:id')->execute([
                    ':next_action_at' => apiDomainAutomationDateText($next),
                    ':error_code' => apiDomainAutomationLimitText($reasonCode, 64),
                    ':request_id' => apiDomainAutomationLimitText($requestId, 128),
                    ':id' => (int)$job['resource_id'],
                ]);
            if ($ownTransaction) $pdo->commit();
            return true;
        } catch (Throwable $failure) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
        }
    }
}

if (!function_exists('apiDomainAutomationFailCloudJob')) {
    /** 超过上限或终止性错误进入可人工重试终态，数据库账本始终保留。 */
    function apiDomainAutomationFailCloudJob(
        PDO $pdo,
        array $job,
        array $context,
        string $reasonCode,
        string $requestId = ''
    ): bool {
        $poolId = (int)($context['domain_pool_id'] ?? 0);
        if ($poolId <= 0) {
            $hintStmt = $pdo->prepare('SELECT domain_pool_id FROM cainiao_api_domain_cloud_resource
                WHERE id=:id LIMIT 1');
            $hintStmt->execute([':id' => (int)$job['resource_id']]);
            $poolId = (int)$hintStmt->fetchColumn();
        }
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            // 清理竞态统一按 pool -> resource -> job 加锁，和回执/最终删除保持一致。
            if ($poolId > 0) {
                $poolLock = $pdo->prepare('SELECT id FROM cainiao_api_domain_pool WHERE id=:id FOR UPDATE');
                $poolLock->execute([':id' => $poolId]);
                if (!$poolLock->fetchColumn()) {
                    if ($ownTransaction) $pdo->rollBack();
                    return false;
                }
            }
            $resourceLock = $pdo->prepare('SELECT id,domain_pool_id FROM cainiao_api_domain_cloud_resource
                WHERE id=:id FOR UPDATE');
            $resourceLock->execute([':id' => (int)$job['resource_id']]);
            $resourceRow = $resourceLock->fetch(PDO::FETCH_ASSOC);
            if (!$resourceRow || (int)($resourceRow['domain_pool_id'] ?? 0) !== $poolId) {
                if ($ownTransaction) $pdo->rollBack();
                return false;
            }
            $stmt = $pdo->prepare("UPDATE cainiao_api_domain_cloud_job SET
                    status='failed',lock_token='',locked_at=NULL,last_error_code=:error_code,
                    last_aws_request_id=:request_id,finished_at=:finished_at
                WHERE id=:id AND status='running' AND lock_token=:lock_token");
            $stmt->execute([
                ':error_code' => apiDomainAutomationLimitText($reasonCode, 64),
                ':request_id' => apiDomainAutomationLimitText($requestId, 128),
                ':finished_at' => apiDomainAutomationDateText(apiDomainAutomationNow()),
                ':id' => (int)$job['id'], ':lock_token' => (string)$job['lock_token'],
            ]);
            if ($stmt->rowCount() !== 1) {
                if ($ownTransaction) $pdo->rollBack();
                return false;
            }
            $cleanupJob = in_array((string)$job['job_type'], ['disable', 'poll_disable', 'delete'], true);
            $restoreJob = in_array((string)$job['job_type'], ['restore_enable', 'poll_restore'], true);
            $workflow = ($cleanupJob || $restoreJob)
                ? 'cleanup_failed'
                : ((string)$job['job_type'] === 'probe' ? 'probe_failed' : 'create_failed');
            $pdo->prepare('UPDATE cainiao_api_domain_cloud_resource SET
                    workflow_state=:workflow_state,next_action_at=NULL,last_error_code=:error_code,
                    last_aws_request_id=:request_id,retry_count=retry_count+1
                WHERE id=:id')->execute([
                    ':workflow_state' => $workflow,
                    ':error_code' => apiDomainAutomationLimitText($reasonCode, 64),
                    ':request_id' => apiDomainAutomationLimitText($requestId, 128),
                    ':id' => (int)$job['resource_id'],
                ]);
            if ($cleanupJob && $poolId > 0) {
                $pdo->prepare("UPDATE cainiao_api_domain_pool SET
                        lifecycle_status='cleanup_failed',cloud_cleanup_state='failed',
                        lifecycle_updated_at=:updated_at,cleanup_reason=:reason
                    WHERE id=:id AND origin='aws_auto' AND lifecycle_status='cleanup_pending'")
                    ->execute([
                        ':updated_at' => apiDomainAutomationDateText(apiDomainAutomationNow()),
                        ':reason' => apiDomainAutomationLimitText($reasonCode, 255),
                        ':id' => $poolId,
                    ]);
            } elseif ($restoreJob && $poolId > 0) {
                $pdo->prepare("UPDATE cainiao_api_domain_pool SET
                        enabled=0,lifecycle_status='active',cloud_cleanup_state='restore_failed',
                        lifecycle_updated_at=:updated_at,cleanup_reason=:reason
                    WHERE id=:id AND origin='aws_auto'")->execute([
                        ':updated_at' => apiDomainAutomationDateText(apiDomainAutomationNow()),
                        ':reason' => apiDomainAutomationLimitText($reasonCode, 255),
                        ':id' => $poolId,
                    ]);
            }
            $pdo->prepare("UPDATE cainiao_api_domain_automation_group SET
                    last_run_status='failed',last_run_message='CloudFront 作业失败，可在资源账本重试'
                WHERE id=:id")->execute([':id' => (int)$context['group_id']]);
            if ($ownTransaction) $pdo->commit();
            return true;
        } catch (Throwable $failure) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
        }
    }
}

if (!function_exists('apiDomainAutomationStoreDistributionResult')) {
    /** 将适配器响应裁剪为账本白名单字段。 */
    function apiDomainAutomationStoreDistributionResult(PDO $pdo, int $resourceId, array $result): array
    {
        $distributionId = trim((string)($result['distribution_id'] ?? ''));
        if (preg_match('/^[A-Z0-9]{5,64}$/', $distributionId) !== 1) {
            throw new ApiDomainAutomationException('invalid_distribution_id');
        }
        $domain = apiDomainAutomationNormalizeCloudFrontDomain($result['domain_name'] ?? '');
        $status = apiDomainAutomationLimitText((string)($result['status'] ?? ''), 32);
        $enabled = !empty($result['enabled']) ? 1 : 0;
        $etag = trim((string)($result['etag'] ?? ''));
        if ($etag !== '' && preg_match('/^[A-Za-z0-9._:-]{1,255}$/', $etag) !== 1) $etag = '';
        $requestId = trim((string)($result['request_id'] ?? ''));
        if ($requestId !== '' && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId) !== 1) $requestId = '';
        $resourceStmt = $pdo->prepare('SELECT public_path FROM cainiao_api_domain_cloud_resource WHERE id=:id');
        $resourceStmt->execute([':id' => $resourceId]);
        $path = apiDomainAutomationNormalizePublicPath((string)$resourceStmt->fetchColumn());
        $publicUrl = 'https://' . $domain . $path;
        $pdo->prepare('UPDATE cainiao_api_domain_cloud_resource SET
                distribution_id=:distribution_id,distribution_arn=:distribution_arn,
                domain_name=:domain_name,public_api_url=:public_api_url,
                distribution_etag=:distribution_etag,provider_status=:provider_status,
                provider_enabled=:provider_enabled,last_aws_request_id=:request_id,
                cloud_created_at=COALESCE(cloud_created_at,:created_at),last_error_code=\'\'
            WHERE id=:id')->execute([
                ':distribution_id' => $distributionId,
                ':distribution_arn' => apiDomainAutomationLimitText((string)($result['distribution_arn'] ?? ''), 255),
                ':domain_name' => $domain, ':public_api_url' => $publicUrl,
                ':distribution_etag' => $etag, ':provider_status' => $status !== '' ? $status : 'Unknown',
                ':provider_enabled' => $enabled, ':request_id' => $requestId,
                ':created_at' => apiDomainAutomationDateText(apiDomainAutomationNow()), ':id' => $resourceId,
            ]);
        return [
            'distribution_id' => $distributionId, 'domain_name' => $domain,
            'public_api_url' => $publicUrl, 'provider_status' => $status,
            'provider_enabled' => $enabled, 'etag' => $etag, 'request_id' => $requestId,
        ];
    }
}

if (!function_exists('apiDomainAutomationCompleteProbe')) {
    /** 探针成功后幂等写入域名池，绑定 resource_id 与 domain_pool_id 双向账本。 */
    function apiDomainAutomationCompleteProbe(PDO $pdo, array $job, array $context, array $probe): bool
    {
        $now = apiDomainAutomationNow();
        $nowText = apiDomainAutomationDateText($now);
        $observationDays = max(0, (int)($context['observation_days'] ?? 1));
        $observationUntil = $now->modify('+' . $observationDays . ' days');
        $activateNow = $observationDays === 0;
        $pdo->beginTransaction();
        try {
            // 已绑定节点时先锁 pool；未绑定时 resource 是第一把锁。
            // 这样成功回执（pool -> resource -> job）不会与探针回写反向互等。
            $poolHintStmt = $pdo->prepare('SELECT domain_pool_id
                FROM cainiao_api_domain_cloud_resource WHERE id=:id LIMIT 1');
            $poolHintStmt->execute([':id' => (int)$job['resource_id']]);
            $poolHint = (int)$poolHintStmt->fetchColumn();
            if ($poolHint > 0) {
                $poolLock = $pdo->prepare('SELECT id FROM cainiao_api_domain_pool WHERE id=:id FOR UPDATE');
                $poolLock->execute([':id' => $poolHint]);
                if (!$poolLock->fetchColumn()) {
                    $pdo->rollBack();
                    return false;
                }
            }
            $resourceLock = $pdo->prepare('SELECT * FROM cainiao_api_domain_cloud_resource WHERE id=:id FOR UPDATE');
            $resourceLock->execute([':id' => (int)$job['resource_id']]);
            $resource = $resourceLock->fetch(PDO::FETCH_ASSOC);
            if (!$resource) {
                $pdo->rollBack();
                return false;
            }
            // 资源可能在提示读取后才绑定 pool；补锁实际绑定行，随后继续持有 resource 锁。
            $domainPoolId = (int)($resource['domain_pool_id'] ?? 0);
            if ($domainPoolId > 0 && $domainPoolId !== $poolHint) {
                $poolLock = $pdo->prepare('SELECT id FROM cainiao_api_domain_pool WHERE id=:id FOR UPDATE');
                $poolLock->execute([':id' => $domainPoolId]);
                if (!$poolLock->fetchColumn()) throw new ApiDomainAutomationException('resource_not_found');
            }
            $jobLock = $pdo->prepare("SELECT id FROM cainiao_api_domain_cloud_job
                WHERE id=:id AND status='running' AND lock_token=:lock_token FOR UPDATE");
            $jobLock->execute([':id' => (int)$job['id'], ':lock_token' => (string)$job['lock_token']]);
            if (!$jobLock->fetchColumn()) {
                $pdo->rollBack();
                return false;
            }
            if ($domainPoolId <= 0) {
                $duplicate = $pdo->prepare('SELECT id,origin FROM cainiao_api_domain_pool WHERE base_url=:base_url LIMIT 1 FOR UPDATE');
                $duplicate->execute([':base_url' => (string)$resource['public_api_url']]);
                $duplicateRow = $duplicate->fetch(PDO::FETCH_ASSOC);
                if ($duplicateRow) throw new ApiDomainAutomationException('duplicate_public_api_url');
                $batchStmt = $pdo->prepare('SELECT batch_code FROM cainiao_api_domain_automation_batch WHERE id=:id');
                $batchStmt->execute([':id' => (int)$resource['batch_id']]);
                $batchCode = (string)($batchStmt->fetchColumn() ?: ('batch-' . (int)$resource['batch_id']));
                $insert = $pdo->prepare("INSERT INTO cainiao_api_domain_pool
                    (name,base_url,usage_scope,enabled,priority,origin,automation_group_id,
                     automation_batch_id,lifecycle_status,cleanup_protected,pinned,reserved,
                     verified_at,observation_until,eligible_at,lifecycle_updated_at,
                     cloud_resource_ref,cloud_cleanup_state)
                    VALUES (:name,:base_url,:usage_scope,:enabled,150,'aws_auto',:group_id,
                     :batch_id,:lifecycle_status,0,0,0,:verified_at,:observation_until,:eligible_at,
                     :lifecycle_updated_at,:cloud_resource_ref,'not_required')");
                $insert->execute([
                    ':name' => apiDomainAutomationLimitText('AWS CloudFront ' . $batchCode . ' #' . (int)$resource['slot_index'], 100),
                    ':base_url' => (string)$resource['public_api_url'],
                    ':usage_scope' => (string)$resource['usage_scope'],
                    ':enabled' => $activateNow ? 1 : 0, ':group_id' => (int)$resource['group_id'],
                    ':batch_id' => (int)$resource['batch_id'],
                    ':lifecycle_status' => $activateNow ? 'active' : 'pending_verification',
                    ':verified_at' => $nowText,
                    ':observation_until' => apiDomainAutomationDateText($observationUntil),
                    ':eligible_at' => apiDomainAutomationDateText($observationUntil),
                    ':lifecycle_updated_at' => $nowText,
                    ':cloud_resource_ref' => (string)$resource['distribution_id'],
                ]);
                $domainPoolId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE cainiao_api_domain_automation_batch SET
                        created_count=LEAST(planned_count,created_count+1),
                        status=IF(created_count+1>=planned_count,\'succeeded\',\'running\')
                    WHERE id=:id')->execute([':id' => (int)$resource['batch_id']]);
                $pdo->prepare('UPDATE cainiao_api_domain_automation_run SET
                        created_count=LEAST(planned_count,created_count+1),
                        status=IF(created_count+1>=planned_count,\'succeeded\',\'running\')
                    WHERE id=:id')->execute([':id' => (int)$resource['run_id']]);
            }
            $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                    domain_pool_id=:domain_pool_id,workflow_state='ready',probe_state='succeeded',
                    probe_http_code=:probe_http_code,verified_at=:verified_at,
                    next_action_at=NULL,last_error_code=''
                WHERE id=:id")->execute([
                    ':domain_pool_id' => $domainPoolId,
                    ':probe_http_code' => (int)($probe['http_code'] ?? 200),
                    ':verified_at' => $nowText, ':id' => (int)$resource['id'],
                ]);
            $finished = apiDomainAutomationFinishCloudJob($pdo, $job);
            if (!$finished) {
                $pdo->rollBack();
                return false;
            }
            $pdo->commit();
            if (function_exists('configDeliveryInvalidateAndSync')) configDeliveryInvalidateAndSync($pdo);
            return true;
        } catch (Throwable $failure) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
        }
    }
}

if (!function_exists('apiDomainAutomationArchiveCloudResource')) {
    /** CloudFront 已删除或确认不存在后，只归档本地事实，不删除数据库行。 */
    function apiDomainAutomationArchiveCloudResource(
        PDO $pdo,
        array $job,
        array $context,
        string $requestId = ''
    ): bool {
        $nowText = apiDomainAutomationDateText(apiDomainAutomationNow());
        $poolId = (int)($context['domain_pool_id'] ?? 0);
        if ($poolId <= 0) {
            $hint = $pdo->prepare('SELECT domain_pool_id FROM cainiao_api_domain_cloud_resource
                WHERE id=:id LIMIT 1');
            $hint->execute([':id' => (int)$job['resource_id']]);
            $poolId = (int)$hint->fetchColumn();
        }
        $pdo->beginTransaction();
        try {
            // 与成功回执、最终 Delete 使用同一锁顺序：pool -> resource -> job。
            // 归档“资源不存在”也必须重新检查访问和保护位，不能用旧快照越过清理门禁。
            $pool = null;
            if ($poolId > 0) {
                $poolLock = $pdo->prepare('SELECT lifecycle_status,cleanup_protected,pinned,reserved,access_count
                    FROM cainiao_api_domain_pool WHERE id=:id FOR UPDATE');
                $poolLock->execute([':id' => $poolId]);
                $pool = $poolLock->fetch(PDO::FETCH_ASSOC);
                $stats = $pdo->prepare('SELECT COALESCE(SUM(ok_count),0)
                    FROM cainiao_api_domain_stats WHERE domain_pool_id=:id');
                $stats->execute([':id' => $poolId]);
                $lifetimeAccess = max((int)($pool['access_count'] ?? 0), (int)$stats->fetchColumn());
                $allowed = $pool
                    && (string)$pool['lifecycle_status'] === 'cleanup_pending'
                    && (int)$pool['cleanup_protected'] === 0
                    && (int)$pool['pinned'] === 0
                    && (int)$pool['reserved'] === 0
                    && $lifetimeAccess === 0;
                if (!$allowed) {
                    // 云端已不存在时不能恢复分配；保留失败事实并释放当前租约，
                    // 由管理员决定重试或补建，不把有访问节点误记成 archived。
                    $resourceLock = $pdo->prepare('SELECT id FROM cainiao_api_domain_cloud_resource
                        WHERE id=:id FOR UPDATE');
                    $resourceLock->execute([':id' => (int)$job['resource_id']]);
                    $jobLock = $pdo->prepare("SELECT id FROM cainiao_api_domain_cloud_job
                        WHERE id=:id AND status='running' AND lock_token=:lock_token FOR UPDATE");
                    $jobLock->execute([':id' => (int)$job['id'], ':lock_token' => (string)$job['lock_token']]);
                    if (!$jobLock->fetchColumn()) {
                        $pdo->rollBack();
                        return false;
                    }
                    $pdo->prepare("UPDATE cainiao_api_domain_cloud_job SET
                            status='failed',lock_token='',locked_at=NULL,last_error_code='cleanup_guard_changed',
                            last_aws_request_id=:request_id,finished_at=:finished_at
                        WHERE id=:id")->execute([
                            ':request_id' => apiDomainAutomationLimitText($requestId, 128),
                            ':finished_at' => $nowText, ':id' => (int)$job['id'],
                        ]);
                    $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                            workflow_state='cleanup_failed',next_action_at=NULL,
                            last_error_code='cleanup_guard_changed',last_aws_request_id=:request_id,
                            retry_count=retry_count+1
                        WHERE id=:id")->execute([
                            ':request_id' => apiDomainAutomationLimitText($requestId, 128),
                            ':id' => (int)$job['resource_id'],
                        ]);
                    $pdo->prepare("UPDATE cainiao_api_domain_pool SET
                            lifecycle_status='cleanup_failed',cloud_cleanup_state='failed',
                            lifecycle_updated_at=:updated_at,cleanup_reason='cleanup_guard_changed'
                        WHERE id=:id AND origin='aws_auto' AND lifecycle_status='cleanup_pending'")->execute([
                            ':updated_at' => $nowText, ':id' => $poolId,
                        ]);
                    $pdo->commit();
                    return false;
                }
            } else {
                // 尚未绑定池节点时没有 pool 行，resource 作为第一把锁。
                $resourceLock = $pdo->prepare('SELECT id FROM cainiao_api_domain_cloud_resource
                    WHERE id=:id FOR UPDATE');
                $resourceLock->execute([':id' => (int)$job['resource_id']]);
                if (!$resourceLock->fetchColumn()) {
                    $pdo->rollBack();
                    return false;
                }
            }
            if ($poolId > 0) {
                $resourceLock = $pdo->prepare('SELECT id FROM cainiao_api_domain_cloud_resource
                    WHERE id=:id FOR UPDATE');
                $resourceLock->execute([':id' => (int)$job['resource_id']]);
                if (!$resourceLock->fetchColumn()) {
                    $pdo->rollBack();
                    return false;
                }
            }
            $jobLock = $pdo->prepare("SELECT id FROM cainiao_api_domain_cloud_job
                WHERE id=:id AND status='running' AND lock_token=:lock_token FOR UPDATE");
            $jobLock->execute([':id' => (int)$job['id'], ':lock_token' => (string)$job['lock_token']]);
            if (!$jobLock->fetchColumn()) {
                $pdo->rollBack();
                return false;
            }
            $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                    workflow_state='archived',provider_status='Deleted',provider_enabled=0,
                    distribution_etag='',next_action_at=NULL,last_error_code='',
                    last_aws_request_id=:request_id,archived_at=:archived_at
                WHERE id=:id")->execute([
                    ':request_id' => apiDomainAutomationLimitText($requestId, 128),
                    ':archived_at' => $nowText, ':id' => (int)$job['resource_id'],
                ]);
            if ($poolId > 0) {
                $pdo->prepare("UPDATE cainiao_api_domain_pool SET enabled=0,
                        lifecycle_status='archived',cloud_cleanup_state='deleted',
                        lifecycle_updated_at=:updated_at,archived_at=:archived_at
                    WHERE id=:id AND origin='aws_auto'")->execute([
                        ':updated_at' => $nowText, ':archived_at' => $nowText,
                        ':id' => $poolId,
                    ]);
            }
            $pdo->prepare('UPDATE cainiao_api_domain_automation_batch SET archived_count=archived_count+1
                WHERE id=:id')->execute([':id' => (int)$context['batch_id']]);
            if (!apiDomainAutomationFinishCloudJob($pdo, $job)) {
                $pdo->rollBack();
                return false;
            }
            $pdo->commit();
            if (function_exists('configDeliveryInvalidateAndSync')) configDeliveryInvalidateAndSync($pdo);
            return true;
        } catch (Throwable $failure) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
        }
    }
}

if (!function_exists('apiDomainAutomationExecuteCloudJob')) {
    /** 执行一个已领取作业；每个分支最多发起固定数量的 AWS/Probe 请求。 */
    function apiDomainAutomationExecuteCloudJob(
        PDO $pdo,
        array $job,
        ?callable $adapterFactory = null,
        ?callable $probeTransport = null
    ): array {
        $context = apiDomainAutomationLoadCloudExecutionContext($pdo, (int)$job['resource_id']);
        $cleanupJob = in_array((string)$job['job_type'], ['disable', 'poll_disable', 'delete'], true);
        if (!$cleanupJob && !empty($context['group_deleted_at'])) {
            throw new ApiDomainAutomationException('group_archived');
        }
        if ((int)($context['account_enabled'] ?? 0) !== 1
            || !empty($context['account_deleted_at'])
            || trim((string)($context['credential_ref'] ?? '')) === ''
            || (string)($context['connection_state'] ?? '') !== 'connected') {
            throw new ApiDomainAutomationException('connection_not_ready');
        }
        $account = [
            'account_id' => (string)($context['aws_account_id'] ?? ''),
            'region' => (string)($context['account_region'] ?? 'us-east-1'),
            'credential_ref' => (string)$context['credential_ref'],
            'auth_type' => (string)$context['auth_type'],
            'role_arn' => (string)$context['role_arn'],
            'external_id_ref' => (string)$context['external_id_ref'],
        ];
        $jobType = (string)$job['job_type'];
        if ($cleanupJob) {
            $guard = apiDomainAutomationCleanupGuard($pdo, (int)$job['resource_id']);
            if (empty($guard['allowed'])) {
                $providerStillEnabled = (int)($context['provider_enabled'] ?? 0) === 1
                    && !in_array((string)($context['workflow_state'] ?? ''), ['disabling', 'delete_pending'], true);
                $cancelled = apiDomainAutomationCancelCleanupJob(
                    $pdo,
                    $job,
                    $context,
                    $providerStillEnabled
                );
                return [
                    'status' => $cancelled ? 'cancelled_for_access' : 'lease_lost',
                    'cleanup_cancelled' => $cancelled ? 1 : 0,
                ];
            }
        }

        if ($jobType === 'probe') {
            try {
                $app = apiDomainAutomationLoadProbeApp($pdo, (int)$context['probe_app_id']);
            } catch (Throwable $failure) {
                throw new ApiDomainAutomationException('probe_app_missing');
            }
            $probe = apiDomainAutomationProbeCloudResource($context, $app, $probeTransport);
            $completed = apiDomainAutomationCompleteProbe($pdo, $job, $context, $probe);
            return ['status' => $completed ? 'succeeded' : 'lease_lost', 'probe_succeeded' => $completed ? 1 : 0];
        }

        $adapter = apiDomainAutomationAdapterForAccount($account, $adapterFactory);
        if ($jobType === 'create') {
            if (preg_match('/^\d{12}$/', (string)$context['expected_account_id']) !== 1) {
                throw new ApiDomainAutomationException('missing_expected_account_id');
            }
            $adapter->verifyIdentity((string)$context['expected_account_id']);
            $result = null;
            // 首次直接创建；重试前先按稳定 CallerReference 恢复已创建资源。
            if ((int)$job['attempt_count'] > 1 || trim((string)$context['distribution_id']) !== '') {
                $result = $adapter->findDistributionByCallerReference((string)$context['caller_reference']);
            }
            if (!is_array($result)) {
                $result = $adapter->createDistribution([
                    'caller_reference' => (string)$context['caller_reference'],
                    'resource_token' => substr(hash('sha256', (string)$context['caller_reference']), 0, 32),
                    'origin_domain' => (string)$context['origin_domain'],
                    'public_path' => (string)$context['public_path'],
                    'comment' => 'group=' . (int)$context['group_id'] . '|batch=' . (int)$context['batch_id'],
                    'price_class' => (string)($context['price_class'] ?? 'PriceClass_All'),
                    'ipv6_enabled' => (int)($context['ipv6_enabled'] ?? 1) === 1,
                ]);
            }
            apiDomainAutomationAssertOwnedDistributionResult($context, $result);
            $pdo->beginTransaction();
            try {
                if (!apiDomainAutomationLockCloudLedger($pdo, $context, $job)) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                $stored = apiDomainAutomationStoreDistributionResult($pdo, (int)$job['resource_id'], $result);
                $next = apiDomainAutomationNow()->modify('+60 seconds');
                $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                        workflow_state='deploying',next_action_at=:next_action_at
                    WHERE id=:id")->execute([
                        ':next_action_at' => apiDomainAutomationDateText($next), ':id' => (int)$job['resource_id'],
                    ]);
                if (!apiDomainAutomationFinishCloudJob($pdo, $job)) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                apiDomainAutomationQueueCloudJob(
                    $pdo,
                    (int)$job['resource_id'],
                    (int)$context['group_id'],
                    (int)$context['cloud_account_id'],
                    'poll_deploy',
                    $next
                );
                $pdo->commit();
                return ['status' => 'succeeded', 'created' => 1, 'request_id' => $stored['request_id']];
            } catch (Throwable $failure) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $failure;
            }
        }

        if ($jobType === 'poll_deploy') {
            $result = $adapter->getDistribution((string)$context['distribution_id']);
            $pdo->beginTransaction();
            try {
                if (!apiDomainAutomationLockCloudLedger($pdo, $context, $job)) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                $stored = apiDomainAutomationStoreDistributionResult($pdo, (int)$job['resource_id'], $result);
                $deployed = strcasecmp((string)$stored['provider_status'], 'Deployed') === 0
                    && (int)$stored['provider_enabled'] === 1;
                if (!$deployed) {
                    $rescheduled = apiDomainAutomationRescheduleCloudJob(
                        $pdo,
                        $job,
                        'distribution_deploying',
                        (string)$stored['request_id'],
                        60
                    );
                    $pdo->commit();
                    return ['status' => $rescheduled ? 'retry_wait' : 'attempts_exhausted'];
                }
                $now = apiDomainAutomationNow();
                $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                        workflow_state='verifying',deployed_at=:deployed_at,next_action_at=:next_action_at
                    WHERE id=:id")->execute([
                        ':deployed_at' => apiDomainAutomationDateText($now),
                        ':next_action_at' => apiDomainAutomationDateText($now),
                        ':id' => (int)$job['resource_id'],
                    ]);
                if (!apiDomainAutomationFinishCloudJob($pdo, $job)) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                apiDomainAutomationQueueCloudJob(
                    $pdo,
                    (int)$job['resource_id'],
                    (int)$context['group_id'],
                    (int)$context['cloud_account_id'],
                    'probe',
                    $now
                );
                $pdo->commit();
                return ['status' => 'succeeded', 'deployed' => 1];
            } catch (Throwable $failure) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $failure;
            }
        }

        if ($jobType === 'disable') {
            if (preg_match('/^\d{12}$/', (string)$context['expected_account_id']) !== 1) {
                throw new ApiDomainAutomationException('missing_expected_account_id');
            }
            $adapter->verifyIdentity((string)$context['expected_account_id']);
            $resourceToken = apiDomainAutomationResourceToken($context);
            if (method_exists($adapter, 'disableOwnedDistribution')) {
                // 适配器在一次 GetDistributionConfig 内完成所有权、ETag 和禁用写入，
                // 避免外层读取后再次读取造成竞态。
                $result = $adapter->disableOwnedDistribution(
                    (string)$context['distribution_id'],
                    (string)$context['caller_reference'],
                    $resourceToken
                );
                $config = [
                    'etag' => (string)($result['etag'] ?? ''),
                    'enabled' => !empty($result['enabled']),
                ];
            } else {
                // 测试替身兼容旧适配器合同；真实 AwsCloudFrontAdapter 始终走上面的 owned API。
                $config = $adapter->getDistributionConfig((string)$context['distribution_id']);
                apiDomainAutomationAssertOwnedDistribution($context, $config);
                $result = !empty($config['enabled'])
                    ? $adapter->disableDistribution((string)$context['distribution_id'])
                    : $config;
            }
            $guardAfterDisable = apiDomainAutomationCleanupGuard($pdo, (int)$job['resource_id']);
            if (empty($guardAfterDisable['allowed'])) {
                $cancelled = apiDomainAutomationCancelCleanupJob($pdo, $job, $context, false);
                return ['status' => $cancelled ? 'cancelled_for_access' : 'lease_lost'];
            }
            $next = apiDomainAutomationNow()->modify('+60 seconds');
            $pdo->beginTransaction();
            try {
                $ledger = apiDomainAutomationLockCloudLedger($pdo, $context, $job);
                if (!$ledger) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                if (!apiDomainAutomationLockedCleanupAllowed(
                    $pdo,
                    (int)$ledger['pool_id'],
                    is_array($ledger['pool'] ?? null) ? $ledger['pool'] : []
                )) {
                    $pdo->rollBack();
                    $cancelled = apiDomainAutomationCancelCleanupJob($pdo, $job, $context, false);
                    return ['status' => $cancelled ? 'cancelled_for_access' : 'lease_lost'];
                }
                $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                        workflow_state='disabling',provider_status=:provider_status,
                        provider_enabled=0,distribution_etag=:etag,next_action_at=:next_action_at,
                        last_aws_request_id=:request_id,last_error_code=''
                    WHERE id=:id")->execute([
                        ':provider_status' => apiDomainAutomationLimitText((string)($result['status'] ?? 'InProgress'), 32),
                        ':etag' => apiDomainAutomationLimitText((string)($result['etag'] ?? $config['etag'] ?? ''), 255),
                        ':next_action_at' => apiDomainAutomationDateText($next),
                        ':request_id' => apiDomainAutomationLimitText((string)($result['request_id'] ?? ''), 128),
                        ':id' => (int)$job['resource_id'],
                    ]);
                $pdo->prepare("UPDATE cainiao_api_domain_pool SET cloud_cleanup_state='disabling'
                    WHERE id=:id AND lifecycle_status='cleanup_pending'")
                    ->execute([':id' => (int)$ledger['pool_id']]);
                if (!apiDomainAutomationFinishCloudJob($pdo, $job)) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                apiDomainAutomationQueueCloudJob(
                    $pdo,
                    (int)$job['resource_id'],
                    (int)$context['group_id'],
                    (int)$context['cloud_account_id'],
                    'poll_disable',
                    $next
                );
                $pdo->commit();
                return ['status' => 'succeeded', 'disable_started' => 1];
            } catch (Throwable $failure) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $failure;
            }
        }

        if ($jobType === 'poll_disable') {
            $result = $adapter->getDistribution((string)$context['distribution_id']);
            $deployedAndDisabled = strcasecmp((string)($result['status'] ?? ''), 'Deployed') === 0
                && empty($result['enabled']);
            $guardAfterPoll = apiDomainAutomationCleanupGuard($pdo, (int)$job['resource_id']);
            if (empty($guardAfterPoll['allowed'])) {
                $cancelled = apiDomainAutomationCancelCleanupJob(
                    $pdo,
                    $job,
                    $context,
                    !empty($result['enabled'])
                );
                return ['status' => $cancelled ? 'cancelled_for_access' : 'lease_lost'];
            }
            if (!$deployedAndDisabled) {
                $pdo->beginTransaction();
                try {
                    $ledger = apiDomainAutomationLockCloudLedger($pdo, $context, $job);
                    if (!$ledger) {
                        $pdo->rollBack();
                        return ['status' => 'lease_lost'];
                    }
                    if (!apiDomainAutomationLockedCleanupAllowed(
                        $pdo,
                        (int)$ledger['pool_id'],
                        is_array($ledger['pool'] ?? null) ? $ledger['pool'] : []
                    )) {
                        $pdo->rollBack();
                        $cancelled = apiDomainAutomationCancelCleanupJob(
                            $pdo,
                            $job,
                            $context,
                            !empty($result['enabled'])
                        );
                        return ['status' => $cancelled ? 'cancelled_for_access' : 'lease_lost'];
                    }
                    apiDomainAutomationStoreDistributionResult($pdo, (int)$job['resource_id'], $result);
                    $rescheduled = apiDomainAutomationRescheduleCloudJob(
                        $pdo,
                        $job,
                        'distribution_disabling',
                        (string)($result['request_id'] ?? ''),
                        60
                    );
                    $pdo->commit();
                    return ['status' => $rescheduled ? 'retry_wait' : 'attempts_exhausted'];
                } catch (Throwable $failure) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $failure;
                }
            }
            $config = $adapter->getDistributionConfig((string)$context['distribution_id']);
            apiDomainAutomationAssertOwnedDistribution($context, $config);
            $now = apiDomainAutomationNow();
            $deleteNotBefore = $now->modify('+24 hours');
            $pdo->beginTransaction();
            try {
                $ledger = apiDomainAutomationLockCloudLedger($pdo, $context, $job);
                if (!$ledger) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                if (!apiDomainAutomationLockedCleanupAllowed(
                    $pdo,
                    (int)$ledger['pool_id'],
                    is_array($ledger['pool'] ?? null) ? $ledger['pool'] : []
                )) {
                    $pdo->rollBack();
                    $cancelled = apiDomainAutomationCancelCleanupJob($pdo, $job, $context, false);
                    return ['status' => $cancelled ? 'cancelled_for_access' : 'lease_lost'];
                }
                apiDomainAutomationStoreDistributionResult($pdo, (int)$job['resource_id'], $result);
                $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                        workflow_state='delete_pending',provider_enabled=0,
                        distribution_etag=:etag,disabled_at=:disabled_at,
                        delete_not_before=:delete_not_before,next_action_at=:next_action_at
                    WHERE id=:id")->execute([
                        ':etag' => apiDomainAutomationLimitText((string)$config['etag'], 255),
                        ':disabled_at' => apiDomainAutomationDateText($now),
                        ':delete_not_before' => apiDomainAutomationDateText($deleteNotBefore),
                        ':next_action_at' => apiDomainAutomationDateText($deleteNotBefore),
                        ':id' => (int)$job['resource_id'],
                    ]);
                $pdo->prepare("UPDATE cainiao_api_domain_pool SET cloud_cleanup_state='delete_pending'
                    WHERE id=:id AND lifecycle_status='cleanup_pending'")
                    ->execute([':id' => (int)$ledger['pool_id']]);
                if (!apiDomainAutomationFinishCloudJob($pdo, $job)) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                apiDomainAutomationQueueCloudJob(
                    $pdo,
                    (int)$job['resource_id'],
                    (int)$context['group_id'],
                    (int)$context['cloud_account_id'],
                    'delete',
                    $deleteNotBefore
                );
                $pdo->commit();
                return ['status' => 'succeeded', 'disabled' => 1];
            } catch (Throwable $failure) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $failure;
            }
        }

        if ($jobType === 'restore_enable') {
            if (preg_match('/^\d{12}$/', (string)$context['expected_account_id']) !== 1) {
                throw new ApiDomainAutomationException('missing_expected_account_id');
            }
            $adapter->verifyIdentity((string)$context['expected_account_id']);
            $resourceToken = apiDomainAutomationResourceToken($context);
            if (method_exists($adapter, 'enableOwnedDistribution')) {
                $result = $adapter->enableOwnedDistribution(
                    (string)$context['distribution_id'],
                    (string)$context['caller_reference'],
                    $resourceToken
                );
                $config = [
                    'etag' => (string)($result['etag'] ?? ''),
                    'enabled' => !empty($result['enabled']),
                ];
            } else {
                $config = $adapter->getDistributionConfig((string)$context['distribution_id']);
                apiDomainAutomationAssertOwnedDistribution($context, $config);
                $result = !empty($config['enabled'])
                    ? $config
                    : $adapter->enableDistribution((string)$context['distribution_id']);
            }
            $next = apiDomainAutomationNow()->modify('+60 seconds');
            $pdo->beginTransaction();
            try {
                $ledger = apiDomainAutomationLockCloudLedger($pdo, $context, $job);
                if (!$ledger) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                        workflow_state='restoring',provider_status=:provider_status,
                        provider_enabled=1,distribution_etag=:etag,delete_not_before=NULL,
                        next_action_at=:next_action_at,last_aws_request_id=:request_id,last_error_code=''
                    WHERE id=:id")->execute([
                        ':provider_status' => apiDomainAutomationLimitText((string)($result['status'] ?? 'InProgress'), 32),
                        ':etag' => apiDomainAutomationLimitText((string)($result['etag'] ?? $config['etag'] ?? ''), 255),
                        ':next_action_at' => apiDomainAutomationDateText($next),
                        ':request_id' => apiDomainAutomationLimitText((string)($result['request_id'] ?? ''), 128),
                        ':id' => (int)$job['resource_id'],
                    ]);
                if ((int)($ledger['pool_id'] ?? 0) > 0) {
                    $pdo->prepare("UPDATE cainiao_api_domain_pool SET
                            enabled=0,lifecycle_status='active',cloud_cleanup_state='restoring'
                        WHERE id=:id AND origin='aws_auto'")
                        ->execute([':id' => (int)$ledger['pool_id']]);
                }
                if (!apiDomainAutomationFinishCloudJob($pdo, $job)) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                apiDomainAutomationQueueCloudJob(
                    $pdo,
                    (int)$job['resource_id'],
                    (int)$context['group_id'],
                    (int)$context['cloud_account_id'],
                    'poll_restore',
                    $next
                );
                $pdo->commit();
                return ['status' => 'succeeded', 'restore_started' => 1];
            } catch (Throwable $failure) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $failure;
            }
        }

        if ($jobType === 'poll_restore') {
            $result = $adapter->getDistribution((string)$context['distribution_id']);
            $restored = strcasecmp((string)($result['status'] ?? ''), 'Deployed') === 0
                && !empty($result['enabled']);
            if (!$restored) {
                $pdo->beginTransaction();
                try {
                    $ledger = apiDomainAutomationLockCloudLedger($pdo, $context, $job);
                    if (!$ledger) {
                        $pdo->rollBack();
                        return ['status' => 'lease_lost'];
                    }
                    apiDomainAutomationStoreDistributionResult($pdo, (int)$job['resource_id'], $result);
                    $rescheduled = apiDomainAutomationRescheduleCloudJob(
                        $pdo,
                        $job,
                        'distribution_restoring',
                        (string)($result['request_id'] ?? ''),
                        60
                    );
                    $pdo->commit();
                    return ['status' => $rescheduled ? 'retry_wait' : 'attempts_exhausted'];
                } catch (Throwable $failure) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $failure;
                }
            }
            $config = $adapter->getDistributionConfig((string)$context['distribution_id']);
            apiDomainAutomationAssertOwnedDistribution($context, $config);
            $pdo->beginTransaction();
            try {
                $ledger = apiDomainAutomationLockCloudLedger($pdo, $context, $job);
                if (!$ledger) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                apiDomainAutomationStoreDistributionResult($pdo, (int)$job['resource_id'], $result);
                $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                        workflow_state='ready',provider_enabled=1,delete_not_before=NULL,
                        next_action_at=NULL,last_error_code=''
                    WHERE id=:id")->execute([':id' => (int)$job['resource_id']]);
                if ((int)($ledger['pool_id'] ?? 0) > 0) {
                    $pdo->prepare("UPDATE cainiao_api_domain_pool SET enabled=1,
                            lifecycle_status='active',cloud_cleanup_state='not_required',
                            lifecycle_updated_at=:updated_at,cleanup_requested_at=NULL,
                            idle_marked_at=NULL,cleanup_reason=''
                        WHERE id=:id AND origin='aws_auto'")->execute([
                            ':updated_at' => apiDomainAutomationDateText(apiDomainAutomationNow()),
                            ':id' => (int)$ledger['pool_id'],
                        ]);
                }
                if (!apiDomainAutomationFinishCloudJob($pdo, $job)) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                $pdo->commit();
                if (function_exists('configDeliveryInvalidateAndSync')) configDeliveryInvalidateAndSync($pdo);
                return ['status' => 'succeeded', 'restored' => 1];
            } catch (Throwable $failure) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $failure;
            }
        }

        if ($jobType === 'delete') {
            $deleteNotBeforeText = trim((string)($context['delete_not_before'] ?? ''));
            if ($deleteNotBeforeText === '') throw new ApiDomainAutomationException('delete_grace_missing');
            $deleteNotBefore = new DateTimeImmutable($deleteNotBeforeText, apiDomainAutomationTimezone());
            if ($deleteNotBefore > apiDomainAutomationNow()) {
                $seconds = max(15, $deleteNotBefore->getTimestamp() - apiDomainAutomationNow()->getTimestamp());
                $rescheduled = apiDomainAutomationRescheduleCloudJob(
                    $pdo,
                    $job,
                    'delete_grace_active',
                    '',
                    min(3600, $seconds)
                );
                return ['status' => $rescheduled ? 'retry_wait' : 'attempts_exhausted'];
            }
            if (preg_match('/^\d{12}$/', (string)$context['expected_account_id']) !== 1) {
                throw new ApiDomainAutomationException('missing_expected_account_id');
            }
            $adapter->verifyIdentity((string)$context['expected_account_id']);
            $resourceToken = apiDomainAutomationResourceToken($context);
            $config = null;
            if (method_exists($adapter, 'deleteOwnedDistribution')) {
                // owned delete 会在 AWS 端重新读取最新配置、核对所有权和禁用状态，
                // 并自动使用最新 ETag；数据库锁只负责并发回执门禁。
                $result = null;
            } else {
                $config = $adapter->getDistributionConfig((string)$context['distribution_id']);
                apiDomainAutomationAssertOwnedDistribution($context, $config);
                if (!empty($config['enabled'])) throw new ApiDomainAutomationException('distribution_still_enabled');
            }
            $pdo->beginTransaction();
            try {
                // 最终 Delete 期间持有 pool 行锁：成功回执会等待本事务，
                // 从而只会发生“先回执取消”或“先删除归档”两种确定顺序。
                $ledger = apiDomainAutomationLockCloudLedger($pdo, $context, $job);
                if (!$ledger) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                $poolId = (int)$ledger['pool_id'];
                $pool = is_array($ledger['pool'] ?? null) ? $ledger['pool'] : [];
                if (!apiDomainAutomationLockedCleanupAllowed($pdo, $poolId, $pool)) {
                    $pdo->rollBack();
                    $cancelled = apiDomainAutomationCancelCleanupJob($pdo, $job, $context, false);
                    return ['status' => $cancelled ? 'cancelled_for_access' : 'lease_lost'];
                }
                if (method_exists($adapter, 'deleteOwnedDistribution')) {
                    $result = $adapter->deleteOwnedDistribution(
                        (string)$context['distribution_id'],
                        (string)$context['caller_reference'],
                        $resourceToken,
                        null
                    );
                } else {
                    $result = $adapter->deleteDistribution(
                        (string)$context['distribution_id'],
                        (string)$config['etag']
                    );
                }
                $nowText = apiDomainAutomationDateText(apiDomainAutomationNow());
                $pdo->prepare("UPDATE cainiao_api_domain_cloud_resource SET
                        workflow_state='archived',provider_status='Deleted',provider_enabled=0,
                        distribution_etag='',delete_not_before=NULL,next_action_at=NULL,
                        last_error_code='',last_aws_request_id=:request_id,archived_at=:archived_at
                    WHERE id=:id")->execute([
                        ':request_id' => apiDomainAutomationLimitText((string)($result['request_id'] ?? ''), 128),
                        ':archived_at' => $nowText, ':id' => (int)$job['resource_id'],
                    ]);
                $pdo->prepare("UPDATE cainiao_api_domain_pool SET enabled=0,
                        lifecycle_status='archived',cloud_cleanup_state='deleted',
                        lifecycle_updated_at=:updated_at,archived_at=:archived_at
                    WHERE id=:id AND origin='aws_auto'")->execute([
                        ':updated_at' => $nowText, ':archived_at' => $nowText,
                        ':id' => $poolId,
                    ]);
                $pdo->prepare('UPDATE cainiao_api_domain_automation_batch SET archived_count=archived_count+1
                    WHERE id=:id')->execute([':id' => (int)$context['batch_id']]);
                if (!apiDomainAutomationFinishCloudJob($pdo, $job)) {
                    $pdo->rollBack();
                    return ['status' => 'lease_lost'];
                }
                $pdo->commit();
                if (function_exists('configDeliveryInvalidateAndSync')) configDeliveryInvalidateAndSync($pdo);
                return ['status' => 'succeeded', 'archived' => 1];
            } catch (Throwable $failure) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $failure;
            }
        }

        throw new ApiDomainAutomationException('unknown_job_type');
    }
}

if (!function_exists('apiDomainAutomationProcessCloudJobs')) {
    /** 常驻 worker 每轮最多执行 limit 个云作业，空队列与未验证账号都不发起 AWS 请求。 */
    function apiDomainAutomationProcessCloudJobs(
        PDO $pdo,
        int $limit = 1,
        ?callable $adapterFactory = null,
        ?callable $probeTransport = null
    ): array {
        ensureApiDomainAutomationSchema($pdo);
        $limit = max(1, min(20, $limit));
        $lockName = 'yunzhuru_api_cloud_jobs';
        if (!apiDomainAutomationLock($pdo, $lockName)) {
            return [
                'checked' => 0, 'processed' => 0, 'succeeded' => 0, 'retry_wait' => 0,
                'failed' => 0, 'created' => 0, 'probed' => 0, 'archived' => 0, 'skipped' => 1,
            ];
        }
        $summary = [
            'checked' => 0, 'processed' => 0, 'succeeded' => 0, 'retry_wait' => 0,
            'failed' => 0, 'created' => 0, 'probed' => 0, 'archived' => 0, 'skipped' => 0,
        ];
        try {
            apiDomainAutomationQueueCleanupJobs($pdo, 20);
            for ($index = 0; $index < $limit; $index++) {
                $job = apiDomainAutomationClaimCloudJob($pdo);
                if (!$job) break;
                $summary['checked']++;
                $summary['processed']++;
                $context = [];
                try {
                    $context = apiDomainAutomationLoadCloudExecutionContext($pdo, (int)$job['resource_id']);
                    $result = apiDomainAutomationExecuteCloudJob(
                        $pdo,
                        $job,
                        $adapterFactory,
                        $probeTransport
                    );
                    $status = (string)($result['status'] ?? 'succeeded');
                    if ($status === 'attempts_exhausted') {
                        apiDomainAutomationFailCloudJob(
                            $pdo,
                            $job,
                            $context,
                            (string)$job['job_type'] . '_attempts_exhausted'
                        );
                        $summary['failed']++;
                    } elseif ($status === 'retry_wait') {
                        $summary['retry_wait']++;
                    } elseif ($status === 'lease_lost') {
                        $summary['skipped']++;
                    } elseif (in_array($status, ['cancelled_for_access', 'cancelled'], true)) {
                        // 访问回执或保护位变化主动取消清理，不计作成功云操作。
                        $summary['skipped']++;
                    } else {
                        $summary['succeeded']++;
                        $summary['created'] += max(0, (int)($result['created'] ?? 0));
                        $summary['probed'] += max(0, (int)($result['probe_succeeded'] ?? 0));
                        $summary['archived'] += max(0, (int)($result['archived'] ?? 0));
                    }
                } catch (Throwable $failure) {
                    if (!$context) {
                        try {
                            $context = apiDomainAutomationLoadCloudExecutionContext($pdo, (int)$job['resource_id']);
                        } catch (Throwable $ignored) {
                            $context = [
                                'id' => (int)$job['resource_id'],
                                'group_id' => (int)$job['group_id'],
                                'domain_pool_id' => 0,
                                'batch_id' => 0,
                            ];
                        }
                    }
                    $info = apiDomainAutomationFailureInfo($failure);
                    if (!empty($info['not_found'])
                        && in_array((string)$job['job_type'], ['disable', 'poll_disable', 'delete'], true)) {
                        $archived = apiDomainAutomationArchiveCloudResource(
                            $pdo,
                            $job,
                            $context,
                            (string)$info['request_id']
                        );
                        if ($archived) {
                            $summary['succeeded']++;
                            $summary['archived']++;
                        } else {
                            $summary['skipped']++;
                        }
                        continue;
                    }
                    $retryable = !empty($info['retryable'])
                        && (int)$job['attempt_count'] < (int)$job['max_attempts'];
                    if ($retryable) {
                        $delay = $info['reason_code'] === 'connection_not_ready' ? 300 : null;
                        $rescheduled = apiDomainAutomationRescheduleCloudJob(
                            $pdo,
                            $job,
                            (string)$info['reason_code'],
                            (string)$info['request_id'],
                            $delay
                        );
                        if ($rescheduled) {
                            $summary['retry_wait']++;
                            continue;
                        }
                    }
                    apiDomainAutomationFailCloudJob(
                        $pdo,
                        $job,
                        $context,
                        (string)$info['reason_code'],
                        (string)$info['request_id']
                    );
                    if (in_array((string)$info['reason_code'], [
                        'aws_access_denied', 'aws_credentials_invalid', 'aws_signature_mismatch',
                        'missing_or_invalid_access_key_id', 'missing_or_invalid_secret_access_key',
                        'aws_session_expired', 'account_id_mismatch',
                    ], true)) {
                        $connectionState = $info['reason_code'] === 'account_id_mismatch'
                            ? 'identity_mismatch'
                            : 'validation_failed';
                        $pdo->prepare("UPDATE cainiao_api_domain_cloud_account SET
                                connection_state=:connection_state,connection_error_code=:error_code,
                                connection_last_checked_at=:checked_at
                            WHERE id=:id AND deleted_at IS NULL")
                            ->execute([
                                ':connection_state' => $connectionState,
                                ':error_code' => (string)$info['reason_code'],
                                ':checked_at' => apiDomainAutomationDateText(apiDomainAutomationNow()),
                                ':id' => (int)$job['cloud_account_id'],
                            ]);
                        $pdo->prepare('UPDATE cainiao_api_domain_automation_group SET adapter_state=:state
                            WHERE cloud_account_id=:id AND deleted_at IS NULL')
                            ->execute([':state' => $connectionState, ':id' => (int)$job['cloud_account_id']]);
                    }
                    $summary['failed']++;
                }
            }
            return $summary;
        } finally {
            apiDomainAutomationUnlock($pdo, $lockName);
        }
    }
}

if (!function_exists('apiDomainAutomationRetryCloudResource')) {
    /** 人工重试只重建当前状态所需的下一个作业，不新建分配或改写 CallerReference。 */
    function apiDomainAutomationRetryCloudResource(PDO $pdo, int $resourceId): array
    {
        ensureApiDomainAutomationSchema($pdo);
        $poolHintStmt = $pdo->prepare('SELECT domain_pool_id FROM cainiao_api_domain_cloud_resource
            WHERE id=:id LIMIT 1');
        $poolHintStmt->execute([':id' => $resourceId]);
        $poolHint = (int)$poolHintStmt->fetchColumn();
        $pdo->beginTransaction();
        try {
            $poolState = null;
            if ($poolHint > 0) {
                $poolLock = $pdo->prepare('SELECT lifecycle_status,cloud_cleanup_state
                    FROM cainiao_api_domain_pool WHERE id=:id FOR UPDATE');
                $poolLock->execute([':id' => $poolHint]);
                $poolState = $poolLock->fetch(PDO::FETCH_ASSOC);
                if (!$poolState) throw new RuntimeException('关联域名池节点不存在');
            }
            $stmt = $pdo->prepare('SELECT * FROM cainiao_api_domain_cloud_resource WHERE id=:id FOR UPDATE');
            $stmt->execute([':id' => $resourceId]);
            $resource = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$resource) throw new RuntimeException('CloudFront 资源账本不存在');
            if ((int)($resource['domain_pool_id'] ?? 0) !== $poolHint) {
                throw new RuntimeException('资源关联状态已变化，请稍后重试');
            }
            $state = (string)$resource['workflow_state'];
            if (!in_array($state, ['create_failed', 'probe_failed', 'cleanup_failed'], true)) {
                throw new RuntimeException('当前 CloudFront 资源状态不需要重试');
            }
            $active = $pdo->prepare("SELECT COUNT(*) FROM cainiao_api_domain_cloud_job
                WHERE resource_id=:id AND status IN ('pending','running','retry_wait')");
            $active->execute([':id' => $resourceId]);
            if ((int)$active->fetchColumn() > 0) throw new RuntimeException('该资源已有作业在处理');
            if ($state === 'probe_failed') {
                $jobType = 'probe';
                $nextState = 'verifying';
            } elseif ($state === 'cleanup_failed') {
                $restoreRetry = $poolState
                    && (string)$poolState['lifecycle_status'] === 'active'
                    && (string)$poolState['cloud_cleanup_state'] === 'restore_failed';
                if ($restoreRetry) {
                    $jobType = 'restore_enable';
                    $nextState = 'restore_pending';
                } else {
                    $jobType = (int)$resource['provider_enabled'] === 1 ? 'disable' : 'poll_disable';
                    $nextState = $jobType === 'disable' ? 'disable_pending' : 'disabling';
                }
                if ((int)($resource['domain_pool_id'] ?? 0) > 0) {
                    if ($restoreRetry) {
                        $pdo->prepare("UPDATE cainiao_api_domain_pool SET
                                enabled=0,cloud_cleanup_state='restore_pending',
                                lifecycle_updated_at=:updated_at
                            WHERE id=:id AND origin='aws_auto' AND lifecycle_status='active'")
                            ->execute([
                                ':updated_at' => apiDomainAutomationDateText(apiDomainAutomationNow()),
                                ':id' => (int)$resource['domain_pool_id'],
                            ]);
                    } else {
                        $pdo->prepare("UPDATE cainiao_api_domain_pool SET
                                lifecycle_status='cleanup_pending',cloud_cleanup_state='queued',
                                lifecycle_updated_at=:updated_at
                            WHERE id=:id AND origin='aws_auto' AND lifecycle_status='cleanup_failed'")
                            ->execute([
                                ':updated_at' => apiDomainAutomationDateText(apiDomainAutomationNow()),
                                ':id' => (int)$resource['domain_pool_id'],
                            ]);
                    }
                }
            } elseif (trim((string)$resource['distribution_id']) === '') {
                $jobType = 'create';
                $nextState = 'pending_create';
            } else {
                $jobType = 'poll_deploy';
                $nextState = 'deploying';
            }
            $now = apiDomainAutomationNow();
            $jobId = apiDomainAutomationQueueCloudJob(
                $pdo,
                $resourceId,
                (int)$resource['group_id'],
                (int)$resource['cloud_account_id'],
                $jobType,
                $now
            );
            $pdo->prepare('UPDATE cainiao_api_domain_cloud_resource SET
                    workflow_state=:workflow_state,next_action_at=:next_action_at,last_error_code=\'\'
                WHERE id=:id')->execute([
                    ':workflow_state' => $nextState,
                    ':next_action_at' => apiDomainAutomationDateText($now),
                    ':id' => $resourceId,
                ]);
            $pdo->commit();
            return [
                'message' => 'CloudFront 资源已重新入队',
                'resource_id' => $resourceId,
                'job_id' => $jobId,
                'workflow_state' => $nextState,
            ];
        } catch (Throwable $failure) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $failure;
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
        $accounts = $pdo->query("SELECT id,name,account_id,region,credential_ref,auth_type,
                role_arn,external_id_ref,enabled,connection_state,verified_account_id,
                connection_last_checked_at,connection_error_code,created_at,updated_at
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
                g.name AS group_name,cr.id AS cloud_resource_id,cr.workflow_state,
                GREATEST(p.access_count,COALESCE(SUM(s.ok_count),0)) AS effective_access_count,
                MAX(CASE WHEN s.ok_count>0 THEN s.last_seen_at ELSE NULL END) AS aggregated_last_access_at
            FROM cainiao_api_domain_pool p
            LEFT JOIN cainiao_api_domain_automation_group g ON g.id=p.automation_group_id
            LEFT JOIN cainiao_api_domain_cloud_resource cr ON cr.domain_pool_id=p.id
            LEFT JOIN cainiao_api_domain_stats s ON s.domain_pool_id=p.id
            WHERE p.origin='aws_auto'
            GROUP BY p.id,g.name,cr.id,cr.workflow_state
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

        // 资源视图只返回管理页需要的状态字段；ARN、ETag 原文、请求体和账号凭据引用都不进入该合同。
        $cloudResources = $pdo->query("SELECT
                r.id,r.id AS resource_id,r.group_id,r.batch_id,r.domain_pool_id,
                r.slot_index,RIGHT(r.caller_reference,32) AS caller_reference,
                r.distribution_id,r.domain_name,r.public_api_url,r.provider_status,
                r.workflow_state,r.provider_enabled AS enabled,
                IF(r.distribution_etag='',0,1) AS etag_present,
                r.probe_state,r.probe_http_code,r.last_error_code,r.retry_count,
                r.next_action_at,r.delete_not_before,r.created_at,r.updated_at,
                g.name AS group_name,b.batch_code
            FROM cainiao_api_domain_cloud_resource r
            LEFT JOIN cainiao_api_domain_automation_group g ON g.id=r.group_id
            LEFT JOIN cainiao_api_domain_automation_batch b ON b.id=r.batch_id
            ORDER BY r.id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

        $totalEligible = 0;
        $totalReservedCapacity = 0;
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
            $reservedCapacity = apiDomainAutomationReservedResourceCount($pdo, $groupId);
            $group['pending_resource_count'] = $reservedCapacity;
            $group['reserved_capacity_count'] = $reservedCapacity;
            $group['capacity_count'] = $metrics['current_eligible_count'] + $reservedCapacity;
            $group['capacity_mode'] = apiDomainAutomationNormalizeCapacityMode($group['capacity_mode'] ?? 'target_replenish');
            $group['capacity_mode_label'] = $group['capacity_mode'] === 'target_replenish'
                ? '固定目标补齐'
                : '兼容周期生成';
            $group['replenish_interval_seconds'] = $group['capacity_mode'] === 'target_replenish' ? 60 : null;
            // 兼容经典页已使用的字段名，新 Pure Admin 直接读取更明确的别名。
            $group['active_count'] = $metrics['current_eligible_count'];
            $group['idle_count'] = $metrics['unused_marked_count'];
            $group['generate_per_run'] = (int)$group['generate_count'];
            $group['mark_after_days'] = (int)$group['idle_mark_days'];
            $group['cleanup_after_days'] = (int)$group['cleanup_no_access_days'];
            $group['capacity_gap'] = max(
                0,
                (int)$group['target_active_count'] - $group['capacity_count']
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
            $totalReservedCapacity += $reservedCapacity;
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
        $connectedAccountCount = 0;
        foreach ($accounts as $account) {
            if ((string)($account['connection_state'] ?? '') === 'connected') $connectedAccountCount++;
        }
        $adapterState = $connectedAccountCount > 0 ? 'connected' : 'waiting_connection';
        return [
            'adapter_state' => $adapterState,
            'accounts' => $accounts,
            'groups' => $groups,
            'recent_batches' => $batches,
            'nodes' => $nodes,
            'cloud_resources' => $cloudResources,
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
                'state' => $adapterState,
                'adapter_state' => $adapterState,
                'connected_account_count' => $connectedAccountCount,
                'account_count' => count($accounts),
                'group_count' => count($groups),
                'node_count' => count($nodes),
                'active_count' => $totalEligible,
                'eligible_count' => $totalEligible,
                'pending_resource_count' => $totalReservedCapacity,
                'reserved_capacity_count' => $totalReservedCapacity,
                'capacity_count' => $totalEligible + $totalReservedCapacity,
                'target_active_count' => $totalTarget,
                'unused_marked_count' => $totalMarked,
                'cleanup_pending_count' => $totalCleanupPending,
                'estimated_runs_per_year' => round($totalAnnualRuns, 2),
                'next_run_at' => $nextRunAt,
            ],
        ];
    }
}
