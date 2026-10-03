-- 通用 URI 劫持规则迁移（可重复执行）。
-- class_name/uri_value 保留，供旧壳继续按 Activity 类名工作；新壳读取
-- match_type/source_pattern/target_url，规则不再绑定某个浏览器类名。

SET @uri_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `cainiao_uri_hijack` ADD COLUMN `match_type` varchar(16) NOT NULL DEFAULT ''class'' COMMENT ''匹配类型：class/exact/prefix/contains/domain'' AFTER `uri_value`',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_uri_hijack' AND COLUMN_NAME = 'match_type'
);
PREPARE uri_stmt FROM @uri_ddl; EXECUTE uri_stmt; DEALLOCATE PREPARE uri_stmt;

SET @uri_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `cainiao_uri_hijack` ADD COLUMN `source_pattern` varchar(1000) NOT NULL DEFAULT '''' COMMENT ''来源 URL 匹配'' AFTER `match_type`',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_uri_hijack' AND COLUMN_NAME = 'source_pattern'
);
PREPARE uri_stmt FROM @uri_ddl; EXECUTE uri_stmt; DEALLOCATE PREPARE uri_stmt;

SET @uri_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `cainiao_uri_hijack` ADD COLUMN `target_url` varchar(2048) NOT NULL DEFAULT '''' COMMENT ''通用规则目标 URL'' AFTER `source_pattern`',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_uri_hijack' AND COLUMN_NAME = 'target_url'
);
PREPARE uri_stmt FROM @uri_ddl; EXECUTE uri_stmt; DEALLOCATE PREPARE uri_stmt;

SET @uri_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `cainiao_uri_hijack` ADD COLUMN `priority` int NOT NULL DEFAULT 0 COMMENT ''匹配优先级，数值越大越先'' AFTER `target_url`',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_uri_hijack' AND COLUMN_NAME = 'priority'
);
PREPARE uri_stmt FROM @uri_ddl; EXECUTE uri_stmt; DEALLOCATE PREPARE uri_stmt;

SET @uri_ddl = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `cainiao_uri_hijack` ADD COLUMN `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT ''是否启用'' AFTER `priority`',
    'DO 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_uri_hijack' AND COLUMN_NAME = 'enabled'
);
PREPARE uri_stmt FROM @uri_ddl; EXECUTE uri_stmt; DEALLOCATE PREPARE uri_stmt;

-- 历史数据显式标记为 class，避免迁移后被当作通用 URL 规则。
UPDATE `cainiao_uri_hijack`
SET `match_type` = 'class'
WHERE (`match_type` IS NULL OR `match_type` = '') AND `class_name` <> '';

SET @uri_ddl = (
  SELECT IF(COUNT(*) = 0,
    'CREATE INDEX `idx_uri_hijack_config_enabled_priority` ON `cainiao_uri_hijack` (`config_id`, `enabled`, `priority`, `id`)',
    'DO 1')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cainiao_uri_hijack'
    AND INDEX_NAME = 'idx_uri_hijack_config_enabled_priority'
);
PREPARE uri_stmt FROM @uri_ddl; EXECUTE uri_stmt; DEALLOCATE PREPARE uri_stmt;
