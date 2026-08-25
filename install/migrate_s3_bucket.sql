-- ============================================================
-- 配置分发桶（B2 / AWS S3 / Cloudflare R2）管理、文件盘点与命中统计
-- 可在现有生产库重复执行；不会重复创建菜单或清空现有桶配置。
-- ============================================================

CREATE TABLE IF NOT EXISTS `cainiao_s3_bucket` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '后台显示名称',
  `provider` varchar(20) NOT NULL DEFAULT 's3' COMMENT 'b2/s3/r2',
  `login_account` varchar(1024) NOT NULL DEFAULT '' COMMENT '云厂商网页登录帐号，应用层AES-256-GCM',
  `login_password` varchar(2048) NOT NULL DEFAULT '' COMMENT '云厂商网页登录密码，应用层AES-256-GCM',
  `note` varchar(512) NOT NULL DEFAULT '' COMMENT '管理备注',
  `access_key` varchar(1024) NOT NULL COMMENT 'S3 Access Key，应用层AES-256-GCM',
  `secret_key` varchar(2048) NOT NULL COMMENT 'S3 Secret Key，应用层AES-256-GCM',
  `endpoint` varchar(512) NOT NULL COMMENT 'S3 API Endpoint 根地址',
  `bucket` varchar(255) NOT NULL COMMENT 'Bucket 名称',
  `region` varchar(64) NOT NULL DEFAULT 'auto' COMMENT '签名 Region',
  `domain` varchar(512) NOT NULL COMMENT '壳端公开访问根地址',
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否接收配置推送',
  `inject` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否写入新注入 APK',
  `last_push_at` datetime DEFAULT NULL COMMENT '最近推送时间',
  `last_push_result` text COMMENT '最近推送对象和结果摘要',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配置分发桶';

-- 兼容旧版 cainiao_s3_bucket。MySQL 8 不支持 ADD COLUMN IF NOT EXISTS，
-- 因此先查 information_schema，再通过 PREPARE 执行单列 DDL。
SET @bucket_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `cainiao_s3_bucket` ADD COLUMN `login_account` varchar(1024) NOT NULL DEFAULT '''' COMMENT ''云厂商网页登录帐号，应用层AES-256-GCM'' AFTER `provider`',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'login_account'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `cainiao_s3_bucket` ADD COLUMN `login_password` varchar(2048) NOT NULL DEFAULT '''' COMMENT ''云厂商网页登录密码，应用层AES-256-GCM'' AFTER `login_account`',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'login_password'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `cainiao_s3_bucket` ADD COLUMN `note` varchar(512) NOT NULL DEFAULT '''' COMMENT ''管理备注'' AFTER `login_password`',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'note'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `cainiao_s3_bucket` ADD COLUMN `inject` tinyint(1) NOT NULL DEFAULT 1 COMMENT ''是否写入新注入 APK'' AFTER `enabled`',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'inject'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `cainiao_s3_bucket` ADD COLUMN `last_push_at` datetime DEFAULT NULL COMMENT ''最近推送时间'' AFTER `inject`',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'last_push_at'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `cainiao_s3_bucket` ADD COLUMN `last_push_result` text COMMENT ''最近推送对象和结果摘要'' AFTER `last_push_at`',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'last_push_result'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

-- 只在旧字段容量不足时扩容，避免重复迁移每次都取元数据锁。
SET @bucket_ddl = (
  SELECT IF(COALESCE(MAX(CHARACTER_MAXIMUM_LENGTH), 0) < 1024,
    'ALTER TABLE `cainiao_s3_bucket` MODIFY COLUMN `login_account` varchar(1024) NOT NULL DEFAULT '''' COMMENT ''云厂商网页登录帐号，应用层AES-256-GCM''',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'login_account'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COALESCE(MAX(CHARACTER_MAXIMUM_LENGTH), 0) < 2048,
    'ALTER TABLE `cainiao_s3_bucket` MODIFY COLUMN `login_password` varchar(2048) NOT NULL DEFAULT '''' COMMENT ''云厂商网页登录密码，应用层AES-256-GCM''',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'login_password'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COALESCE(MAX(CHARACTER_MAXIMUM_LENGTH), 0) < 1024,
    'ALTER TABLE `cainiao_s3_bucket` MODIFY COLUMN `access_key` varchar(1024) NOT NULL COMMENT ''S3 Access Key，应用层AES-256-GCM''',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'access_key'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COALESCE(MAX(CHARACTER_MAXIMUM_LENGTH), 0) < 2048,
    'ALTER TABLE `cainiao_s3_bucket` MODIFY COLUMN `secret_key` varchar(2048) NOT NULL COMMENT ''S3 Secret Key，应用层AES-256-GCM''',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'secret_key'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COALESCE(MAX(CHARACTER_MAXIMUM_LENGTH), 0) < 512,
    'ALTER TABLE `cainiao_s3_bucket` MODIFY COLUMN `endpoint` varchar(512) NOT NULL COMMENT ''S3 API Endpoint 根地址''',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'endpoint'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COALESCE(MAX(CHARACTER_MAXIMUM_LENGTH), 0) < 255,
    'ALTER TABLE `cainiao_s3_bucket` MODIFY COLUMN `bucket` varchar(255) NOT NULL COMMENT ''Bucket 名称''',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'bucket'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COALESCE(MAX(CHARACTER_MAXIMUM_LENGTH), 0) < 64,
    'ALTER TABLE `cainiao_s3_bucket` MODIFY COLUMN `region` varchar(64) NOT NULL DEFAULT ''auto'' COMMENT ''签名 Region''',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'region'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

SET @bucket_ddl = (
  SELECT IF(COALESCE(MAX(CHARACTER_MAXIMUM_LENGTH), 0) < 512,
    'ALTER TABLE `cainiao_s3_bucket` MODIFY COLUMN `domain` varchar(512) NOT NULL COMMENT ''壳端公开访问根地址''',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_s3_bucket' AND COLUMN_NAME = 'domain'
);
PREPARE bucket_stmt FROM @bucket_ddl; EXECUTE bucket_stmt; DEALLOCATE PREPARE bucket_stmt;

-- 旧版页面曾将 B2/R2 默认记为 s3；只按可确定的官方 Endpoint 无损归一。
UPDATE `cainiao_s3_bucket`
SET `provider` = 'b2', `updated_at` = `updated_at`
WHERE `provider` = 's3'
  AND LOWER(TRIM(TRAILING '/' FROM `endpoint`)) LIKE 'https://s3.%.backblazeb2.com';

UPDATE `cainiao_s3_bucket`
SET `provider` = 'r2', `updated_at` = `updated_at`
WHERE `provider` = 's3'
  AND LOWER(TRIM(TRAILING '/' FROM `endpoint`)) LIKE 'https://%.r2.cloudflarestorage.com';

CREATE TABLE IF NOT EXISTS `cainiao_s3_bucket_stats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bucket_id` int NOT NULL,
  `stat_date` date NOT NULL COMMENT 'Asia/Shanghai 日期',
  `hit_count` bigint unsigned NOT NULL DEFAULT 0,
  `ok_count` bigint unsigned NOT NULL DEFAULT 0,
  `fail_count` bigint unsigned NOT NULL DEFAULT 0,
  `last_seen_at` datetime NOT NULL COMMENT 'UTC 时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bucket_date` (`bucket_id`,`stat_date`),
  KEY `idx_stat_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='壳端配置桶日命中统计';

CREATE TABLE IF NOT EXISTS `cainiao_s3_bucket_file_stats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bucket_id` int NOT NULL,
  `app_id` int NOT NULL,
  `stat_date` date NOT NULL COMMENT 'Asia/Shanghai 日期',
  `hit_count` bigint unsigned NOT NULL DEFAULT 0,
  `last_seen_at` datetime NOT NULL COMMENT 'UTC 时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_bucket_app_date` (`bucket_id`,`app_id`,`stat_date`),
  KEY `idx_bucket_app_date` (`bucket_id`,`app_id`,`stat_date`),
  KEY `idx_stat_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='壳端配置文件日命中统计';

-- 菜单写入具备唯一性保护，重复执行不会生成第二个“存储桶管理”。
INSERT INTO `cainiao_menu` (`parent_id`, `name`, `icon`, `path`, `hidden`, `role_id`)
SELECT parent.`id`, '存储桶管理', 'Upload', 'admin/bucket', 0, parent.`role_id`
FROM `cainiao_menu` parent
WHERE parent.`path` = 'admin/system'
  AND NOT EXISTS (
    SELECT 1 FROM `cainiao_menu` existing WHERE existing.`path` = 'admin/bucket'
  )
LIMIT 1;
