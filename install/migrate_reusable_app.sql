SET @has_is_reusable := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cainiao_apk'
    AND COLUMN_NAME = 'is_reusable'
);

SET @add_is_reusable_sql := IF(
  @has_is_reusable = 0,
  'ALTER TABLE `cainiao_apk` ADD `is_reusable` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''是否允许作为配置复用目标'' AFTER `reuse_apk_id`',
  'SELECT 1'
);

PREPARE add_is_reusable_stmt FROM @add_is_reusable_sql;
EXECUTE add_is_reusable_stmt;
DEALLOCATE PREPARE add_is_reusable_stmt;
