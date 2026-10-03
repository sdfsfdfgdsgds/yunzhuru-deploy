SET @has_download_name_template := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cainiao_apk'
    AND COLUMN_NAME = 'download_name_template'
);

SET @add_download_name_template_sql := IF(
  @has_download_name_template = 0,
  'ALTER TABLE `cainiao_apk` ADD `download_name_template` VARCHAR(255) NOT NULL DEFAULT '''' COMMENT ''APK 下载名称模板'' AFTER `name`',
  'SELECT 1'
);

PREPARE add_download_name_template_stmt FROM @add_download_name_template_sql;
EXECUTE add_download_name_template_stmt;
DEALLOCATE PREPARE add_download_name_template_stmt;
