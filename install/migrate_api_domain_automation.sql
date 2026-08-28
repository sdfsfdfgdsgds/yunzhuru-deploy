-- ============================================================
-- API 域名池：稳定池组、不可变批次、CloudFront 资源与有界作业 V4
-- 可在 MySQL 8 生产库重复执行；只变更本地结构和状态，不调用 AWS。
-- AWS 账号表只保存引用元数据，不保存访问密钥、Secret 或网页登录信息。
-- ============================================================

CREATE TABLE IF NOT EXISTS cainiao_api_domain_cloud_account (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `account_id` varchar(32) NOT NULL DEFAULT '',
  `region` varchar(32) NOT NULL DEFAULT 'us-east-1',
  `credential_ref` varchar(64) NOT NULL DEFAULT '',
  `auth_type` varchar(24) NOT NULL DEFAULT 'environment',
  `role_arn` varchar(255) NOT NULL DEFAULT '',
  `external_id_ref` varchar(64) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `connection_state` varchar(24) NOT NULL DEFAULT 'waiting_credentials',
  `verified_account_id` varchar(32) NOT NULL DEFAULT '',
  `connection_last_checked_at` datetime DEFAULT NULL,
  `connection_error_code` varchar(64) NOT NULL DEFAULT '',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cloud_account_active` (`deleted_at`,`enabled`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池云账号非敏感元数据';

CREATE TABLE IF NOT EXISTS cainiao_api_domain_automation_group (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `cloud_account_id` int unsigned NOT NULL,
  `usage_scope` varchar(16) NOT NULL DEFAULT 'config',
  `environment` varchar(32) NOT NULL DEFAULT 'production',
  `region` varchar(32) NOT NULL DEFAULT 'us-east-1',
  `domain_provider` varchar(32) NOT NULL DEFAULT 'cloudfront_default',
  `certificate_provider` varchar(32) NOT NULL DEFAULT 'cloudfront_default',
  `origin_domain` varchar(253) NOT NULL DEFAULT 'yunzhuru-app-production.up.railway.app',
  `public_path` varchar(255) NOT NULL DEFAULT '/shell.php',
  `probe_app_id` int unsigned NOT NULL DEFAULT 0,
  `price_class` varchar(32) NOT NULL DEFAULT 'PriceClass_All',
  `ipv6_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `generation_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `capacity_mode` varchar(24) NOT NULL DEFAULT 'target_replenish',
  `target_active_count` int unsigned NOT NULL DEFAULT 30,
  `minimum_healthy_count` int unsigned NOT NULL DEFAULT 4,
  `interval_value` int unsigned NOT NULL DEFAULT 1,
  `interval_unit` varchar(16) NOT NULL DEFAULT 'minute',
  `generate_count` int unsigned NOT NULL DEFAULT 30,
  `observation_days` int unsigned NOT NULL DEFAULT 1,
  `idle_mark_days` int unsigned NOT NULL DEFAULT 3,
  `cleanup_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `cleanup_no_access_days` int unsigned NOT NULL DEFAULT 7,
  `adapter_state` varchar(24) NOT NULL DEFAULT 'waiting_adapter',
  `schedule_anchor_at` datetime NOT NULL,
  `next_run_at` datetime DEFAULT NULL,
  `last_run_at` datetime DEFAULT NULL,
  `last_run_status` varchar(24) NOT NULL DEFAULT 'never',
  `last_run_message` varchar(255) NOT NULL DEFAULT '',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_automation_due` (`deleted_at`,`enabled`,`next_run_at`,`id`),
  KEY `idx_automation_account` (`cloud_account_id`,`deleted_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池自动生成与清理策略';

CREATE TABLE IF NOT EXISTS cainiao_api_domain_automation_batch (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL,
  `batch_code` varchar(64) NOT NULL,
  `period_key` char(7) NOT NULL,
  `trigger_type` varchar(16) NOT NULL DEFAULT 'scheduled',
  `planned_count` int unsigned NOT NULL DEFAULT 0,
  `created_count` int unsigned NOT NULL DEFAULT 0,
  `period_sequence` bigint unsigned NOT NULL DEFAULT 0,
  `dry_run` tinyint(1) NOT NULL DEFAULT 0,
  `current_eligible_count` int unsigned NOT NULL DEFAULT 0,
  `capacity_gap` int unsigned NOT NULL DEFAULT 0,
  `marked_count` int unsigned NOT NULL DEFAULT 0,
  `archived_count` int unsigned NOT NULL DEFAULT 0,
  `cleanup_pending_count` int unsigned NOT NULL DEFAULT 0,
  `protected_count` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'waiting_adapter',
  `message` varchar(255) NOT NULL DEFAULT '',
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_automation_batch_code` (`batch_code`),
  KEY `idx_automation_batch_group` (`group_id`,`id`),
  KEY `idx_automation_batch_period` (`period_key`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池不可变生成计划批次';

CREATE TABLE IF NOT EXISTS cainiao_api_domain_automation_run (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL,
  `batch_id` bigint unsigned DEFAULT NULL,
  `retry_of_run_id` bigint unsigned DEFAULT NULL,
  `trigger_type` varchar(16) NOT NULL DEFAULT 'scheduled',
  `dry_run` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'planned',
  `current_eligible_count` int unsigned NOT NULL DEFAULT 0,
  `capacity_gap` int unsigned NOT NULL DEFAULT 0,
  `planned_count` int unsigned NOT NULL DEFAULT 0,
  `created_count` int unsigned NOT NULL DEFAULT 0,
  `marked_count` int unsigned NOT NULL DEFAULT 0,
  `cleanup_pending_count` int unsigned NOT NULL DEFAULT 0,
  `protected_count` int unsigned NOT NULL DEFAULT 0,
  `message` varchar(255) NOT NULL DEFAULT '',
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_automation_run_group` (`group_id`,`id`),
  KEY `idx_automation_run_batch` (`batch_id`,`id`),
  KEY `idx_automation_run_status` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池自动化独立运行记录';

CREATE TABLE IF NOT EXISTS cainiao_api_domain_cloud_resource (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL,
  `batch_id` bigint unsigned NOT NULL,
  `run_id` bigint unsigned NOT NULL,
  `cloud_account_id` int unsigned NOT NULL,
  `expected_account_id` varchar(32) NOT NULL,
  `domain_pool_id` int unsigned DEFAULT NULL,
  `slot_index` int unsigned NOT NULL,
  `caller_reference` varchar(128) NOT NULL,
  `resource_type` varchar(32) NOT NULL DEFAULT 'cloudfront_distribution',
  `origin_domain` varchar(253) NOT NULL,
  `public_path` varchar(255) NOT NULL DEFAULT '/shell.php',
  `usage_scope` varchar(16) NOT NULL DEFAULT 'config',
  `price_class` varchar(32) NOT NULL DEFAULT 'PriceClass_All',
  `ipv6_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `distribution_id` varchar(64) NOT NULL DEFAULT '',
  `distribution_arn` varchar(255) NOT NULL DEFAULT '',
  `domain_name` varchar(253) NOT NULL DEFAULT '',
  `public_api_url` varchar(512) NOT NULL DEFAULT '',
  `distribution_etag` varchar(255) NOT NULL DEFAULT '',
  `provider_status` varchar(32) NOT NULL DEFAULT 'not_created',
  `workflow_state` varchar(32) NOT NULL DEFAULT 'pending_create',
  `provider_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `probe_state` varchar(32) NOT NULL DEFAULT 'not_started',
  `probe_http_code` int unsigned NOT NULL DEFAULT 0,
  `retry_count` int unsigned NOT NULL DEFAULT 0,
  `next_action_at` datetime DEFAULT NULL,
  `last_error_code` varchar(64) NOT NULL DEFAULT '',
  `last_aws_request_id` varchar(128) NOT NULL DEFAULT '',
  `cloud_created_at` datetime DEFAULT NULL,
  `deployed_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `disabled_at` datetime DEFAULT NULL,
  `delete_not_before` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cloud_resource_caller` (`caller_reference`),
  UNIQUE KEY `uniq_cloud_resource_slot` (`batch_id`,`slot_index`),
  UNIQUE KEY `uniq_cloud_resource_pool` (`domain_pool_id`),
  KEY `idx_cloud_resource_work` (`workflow_state`,`next_action_at`,`id`),
  KEY `idx_cloud_resource_group` (`group_id`,`id`),
  KEY `idx_cloud_resource_distribution` (`distribution_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池CloudFront资源账本';

CREATE TABLE IF NOT EXISTS cainiao_api_domain_cloud_job (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `resource_id` bigint unsigned NOT NULL,
  `group_id` int unsigned NOT NULL,
  `cloud_account_id` int unsigned NOT NULL,
  `job_type` varchar(32) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `attempt_count` int unsigned NOT NULL DEFAULT 0,
  `max_attempts` int unsigned NOT NULL DEFAULT 12,
  `cancel_requested` tinyint(1) NOT NULL DEFAULT 0,
  `next_attempt_at` datetime NOT NULL,
  `lock_token` varchar(64) NOT NULL DEFAULT '',
  `locked_at` datetime DEFAULT NULL,
  `last_error_code` varchar(64) NOT NULL DEFAULT '',
  `last_aws_request_id` varchar(128) NOT NULL DEFAULT '',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cloud_job_due` (`status`,`next_attempt_at`,`id`),
  KEY `idx_cloud_job_resource` (`resource_id`,`id`),
  KEY `idx_cloud_job_group` (`group_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API域名池CloudFront有界作业队列';

-- MySQL 8 各小版对 ADD COLUMN IF NOT EXISTS 支持不一致，以下逐列查询后迁移。
-- lifecycle_status 合同：pending_verification -> active -> unused_marked
-- -> cleanup_pending -> archived / cleanup_failed。

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `environment` varchar(32) NOT NULL DEFAULT ''production'' AFTER `usage_scope`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'environment'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `region` varchar(32) NOT NULL DEFAULT ''us-east-1'' AFTER `environment`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'region'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `domain_provider` varchar(32) NOT NULL DEFAULT ''cloudfront_default'' AFTER `region`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'domain_provider'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `certificate_provider` varchar(32) NOT NULL DEFAULT ''cloudfront_default'' AFTER `domain_provider`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'certificate_provider'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `generation_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `enabled`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'generation_enabled'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

-- 先记录字段是否来自旧 V3 表，再执行 ALTER；这样可区分新建表默认值与
-- 存量表的旧按周期策略，避免把管理员自定义周期组误标成固定补齐组。
SET @api_auto_had_capacity_mode = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'capacity_mode'
);
SET @api_auto_had_target_active_count = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'target_active_count'
);

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `capacity_mode` varchar(24) NOT NULL DEFAULT ''target_replenish'' AFTER `generation_enabled`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'capacity_mode'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

-- 极早期自动化骨架可能没有容量字段；按无位置约束补齐，保证滚动升级可重复执行。
SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `target_active_count` int unsigned NOT NULL DEFAULT 30', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'target_active_count'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `minimum_healthy_count` int unsigned NOT NULL DEFAULT 4', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'minimum_healthy_count'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `interval_value` int unsigned NOT NULL DEFAULT 1', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'interval_value'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `interval_unit` varchar(16) NOT NULL DEFAULT ''minute''', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'interval_unit'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `generate_count` int unsigned NOT NULL DEFAULT 30', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'generate_count'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

UPDATE `cainiao_api_domain_automation_group`
SET `capacity_mode`=CASE
    WHEN @api_auto_had_target_active_count=0 THEN 'target_replenish'
    WHEN `target_active_count`=20 AND `minimum_healthy_count`=4
         AND `interval_value`=1 AND `interval_unit`='day' AND `generate_count`=1
    THEN 'target_replenish'
    ELSE 'periodic'
END
WHERE @api_auto_had_capacity_mode = 0 AND `deleted_at` IS NULL;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `observation_days` int unsigned NOT NULL DEFAULT 1 AFTER `generate_count`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_group' AND COLUMN_NAME = 'observation_days'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_batch` ADD COLUMN `period_sequence` bigint unsigned NOT NULL DEFAULT 0 AFTER `created_count`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_batch' AND COLUMN_NAME = 'period_sequence'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_batch` ADD COLUMN `dry_run` tinyint(1) NOT NULL DEFAULT 0 AFTER `period_sequence`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_batch' AND COLUMN_NAME = 'dry_run'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_batch` ADD COLUMN `current_eligible_count` int unsigned NOT NULL DEFAULT 0 AFTER `dry_run`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_batch' AND COLUMN_NAME = 'current_eligible_count'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_batch` ADD COLUMN `capacity_gap` int unsigned NOT NULL DEFAULT 0 AFTER `current_eligible_count`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_batch' AND COLUMN_NAME = 'capacity_gap'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_automation_batch` ADD COLUMN `cleanup_pending_count` int unsigned NOT NULL DEFAULT 0 AFTER `archived_count`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_automation_batch' AND COLUMN_NAME = 'cleanup_pending_count'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `origin` varchar(16) NOT NULL DEFAULT ''manual'' AFTER `priority`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'origin'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `automation_group_id` int unsigned DEFAULT NULL AFTER `origin`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'automation_group_id'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `automation_batch_id` bigint unsigned DEFAULT NULL AFTER `automation_group_id`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'automation_batch_id'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `lifecycle_status` varchar(24) NOT NULL DEFAULT ''active'' AFTER `automation_batch_id`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'lifecycle_status'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `cleanup_protected` tinyint(1) NOT NULL DEFAULT 1 AFTER `lifecycle_status`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'cleanup_protected'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `pinned` tinyint(1) NOT NULL DEFAULT 0 AFTER `cleanup_protected`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'pinned'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `reserved` tinyint(1) NOT NULL DEFAULT 0 AFTER `pinned`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'reserved'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `verified_at` datetime DEFAULT NULL AFTER `reserved`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'verified_at'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `observation_until` datetime DEFAULT NULL AFTER `verified_at`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'observation_until'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `eligible_at` datetime DEFAULT NULL AFTER `observation_until`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'eligible_at'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `last_access_at` datetime DEFAULT NULL AFTER `eligible_at`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'last_access_at'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `access_count` bigint unsigned NOT NULL DEFAULT 0 AFTER `last_access_at`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'access_count'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `idle_marked_at` datetime DEFAULT NULL AFTER `access_count`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'idle_marked_at'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `cleanup_requested_at` datetime DEFAULT NULL AFTER `idle_marked_at`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'cleanup_requested_at'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `lifecycle_updated_at` datetime DEFAULT NULL AFTER `cleanup_requested_at`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'lifecycle_updated_at'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `archived_at` datetime DEFAULT NULL AFTER `lifecycle_updated_at`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'archived_at'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `cleanup_reason` varchar(255) NOT NULL DEFAULT '''' AFTER `archived_at`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'cleanup_reason'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `cloud_resource_ref` varchar(255) NOT NULL DEFAULT '''' AFTER `cleanup_reason`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'cloud_resource_ref'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

SET @api_auto_ddl = (
  SELECT IF(COUNT(*) = 0, 'ALTER TABLE `cainiao_api_domain_pool` ADD COLUMN `cloud_cleanup_state` varchar(24) NOT NULL DEFAULT ''not_required'' AFTER `cloud_resource_ref`', 'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_api_domain_pool' AND COLUMN_NAME = 'cloud_cleanup_state'
);
PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;

-- V3 账号、池组及作业字段幂等补齐。
-- 采用 PREPARE + information_schema，兼容 MySQL 8 各小版本。
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_account` ADD COLUMN `credential_ref` varchar(64) NOT NULL DEFAULT '''' AFTER `region`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_account' AND COLUMN_NAME='credential_ref'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_account` ADD COLUMN `auth_type` varchar(24) NOT NULL DEFAULT ''environment'' AFTER `credential_ref`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_account' AND COLUMN_NAME='auth_type'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_account` ADD COLUMN `role_arn` varchar(255) NOT NULL DEFAULT '''' AFTER `auth_type`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_account' AND COLUMN_NAME='role_arn'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_account` ADD COLUMN `external_id_ref` varchar(64) NOT NULL DEFAULT '''' AFTER `role_arn`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_account' AND COLUMN_NAME='external_id_ref'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_account` ADD COLUMN `verified_account_id` varchar(32) NOT NULL DEFAULT '''' AFTER `connection_state`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_account' AND COLUMN_NAME='verified_account_id'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_account` ADD COLUMN `connection_last_checked_at` datetime DEFAULT NULL AFTER `verified_account_id`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_account' AND COLUMN_NAME='connection_last_checked_at'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_account` ADD COLUMN `connection_error_code` varchar(64) NOT NULL DEFAULT '''' AFTER `connection_last_checked_at`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_account' AND COLUMN_NAME='connection_error_code'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `origin_domain` varchar(253) NOT NULL DEFAULT ''yunzhuru-app-production.up.railway.app'' AFTER `certificate_provider`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_automation_group' AND COLUMN_NAME='origin_domain'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `public_path` varchar(255) NOT NULL DEFAULT ''/shell.php'' AFTER `origin_domain`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_automation_group' AND COLUMN_NAME='public_path'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `probe_app_id` int unsigned NOT NULL DEFAULT 0 AFTER `public_path`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_automation_group' AND COLUMN_NAME='probe_app_id'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `price_class` varchar(32) NOT NULL DEFAULT ''PriceClass_All'' AFTER `probe_app_id`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_automation_group' AND COLUMN_NAME='price_class'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_automation_group` ADD COLUMN `ipv6_enabled` tinyint(1) NOT NULL DEFAULT 1 AFTER `price_class`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_automation_group' AND COLUMN_NAME='ipv6_enabled'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_resource` ADD COLUMN `expected_account_id` varchar(32) NOT NULL DEFAULT '''' AFTER `cloud_account_id`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_resource' AND COLUMN_NAME='expected_account_id'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_resource` ADD COLUMN `price_class` varchar(32) NOT NULL DEFAULT ''PriceClass_All'' AFTER `usage_scope`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_resource' AND COLUMN_NAME='price_class'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_resource` ADD COLUMN `ipv6_enabled` tinyint(1) NOT NULL DEFAULT 1 AFTER `price_class`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_resource' AND COLUMN_NAME='ipv6_enabled'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_resource` ADD COLUMN `delete_not_before` datetime DEFAULT NULL AFTER `disabled_at`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_resource' AND COLUMN_NAME='delete_not_before'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
SET @api_auto_ddl = (SELECT IF(COUNT(*)=0, 'ALTER TABLE `cainiao_api_domain_cloud_job` ADD COLUMN `cancel_requested` tinyint(1) NOT NULL DEFAULT 0 AFTER `max_attempts`', 'DO 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cainiao_api_domain_cloud_job' AND COLUMN_NAME='cancel_requested'); PREPARE api_auto_stmt FROM @api_auto_ddl; EXECUTE api_auto_stmt; DEALLOCATE PREPARE api_auto_stmt;
-- 存量节点不属于自动生成，永久保护；旧 no_access 状态只做语义升级。
UPDATE `cainiao_api_domain_pool`
SET `origin`='manual',`cleanup_protected`=1,`lifecycle_status`='active'
WHERE `origin`='' OR `origin` IS NULL;

UPDATE `cainiao_api_domain_pool`
SET `lifecycle_status`='unused_marked',
    `lifecycle_updated_at`=COALESCE(`lifecycle_updated_at`,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR))
WHERE `origin`='aws_auto' AND `lifecycle_status`='no_access';

-- V1 期间已经投放的 active 自动节点视为已完成验证，避免升级后被误判为观察中。
UPDATE `cainiao_api_domain_pool`
SET `verified_at`=COALESCE(`verified_at`,`created_at`),
    `observation_until`=COALESCE(`observation_until`,`created_at`),
    `eligible_at`=COALESCE(`eligible_at`,`created_at`)
WHERE `origin`='aws_auto' AND `lifecycle_status` IN ('active','unused_marked');

-- V4 只迁移旧版的默认组合：固定目标 30 个、缺口补齐、每分钟检查。
-- 读取版本后只执行一次，避免管理员后续主动设置 20/天再次被覆盖。
SET @api_auto_schema_version = COALESCE((
  SELECT CAST(`key_value` AS UNSIGNED)
  FROM `cainiao_config_delivery_meta`
  WHERE `key_name`='api_domain_automation_schema_version'
  LIMIT 1
), 0);
UPDATE `cainiao_api_domain_automation_group`
SET `capacity_mode`='target_replenish',
    `target_active_count`=30,
    `interval_value`=1,
    `interval_unit`='minute',
    `generate_count`=30,
    `next_run_at`=CASE WHEN `enabled`=1
        THEN DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR)
        ELSE `next_run_at` END
WHERE @api_auto_schema_version < 4
  AND (`capacity_mode` IS NULL OR `capacity_mode`=''
       OR (`capacity_mode`='target_replenish'
           AND `target_active_count`=20 AND `minimum_healthy_count`=4
           AND `interval_value`=1 AND `interval_unit`='day' AND `generate_count`=1));

-- 对接 AWS 前，cleanup_pending 只代表本地隔离和云端待处理，不删除任何云资源或数据库行。
UPDATE `cainiao_api_domain_pool`
SET `cloud_cleanup_state`='waiting_adapter'
WHERE `origin`='aws_auto' AND `lifecycle_status`='cleanup_pending';

INSERT INTO `cainiao_config_delivery_meta` (`key_name`,`key_value`)
VALUES ('api_domain_automation_schema_version','4')
ON DUPLICATE KEY UPDATE `key_value`=GREATEST(CAST(`key_value` AS UNSIGNED),4);
