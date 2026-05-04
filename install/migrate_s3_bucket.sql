-- ============================================
-- S3/R2/B2 存储桶配置分发 - 数据库迁移
-- 执行一次即可，重复执行不会报错
-- ============================================

-- 1. 创建存储桶配置表
CREATE TABLE IF NOT EXISTS `cainiao_s3_bucket` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '显示名称(如 Cloudflare R2)',
  `provider` varchar(20) NOT NULL DEFAULT 's3' COMMENT 's3/r2/b2',
  `access_key` varchar(255) NOT NULL,
  `secret_key` varchar(255) NOT NULL,
  `endpoint` varchar(255) NOT NULL COMMENT 'S3 API endpoint',
  `bucket` varchar(100) NOT NULL COMMENT 'bucket name',
  `region` varchar(50) NOT NULL DEFAULT 'auto' COMMENT '区域(R2填auto)',
  `domain` varchar(255) NOT NULL COMMENT '公开访问域名 https://cdn.example.com',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='S3兼容存储桶配置';

-- 2. 注册菜单（挂在"系统管理"下面）
-- 先查出系统管理的 parent_id，如果找不到就挂顶级
INSERT INTO `cainiao_menu` (`parent_id`, `name`, `icon`, `path`, `hidden`, `role_id`)
SELECT `id`, '存储桶管理', 'Upload', 'admin/bucket', 0, `role_id`
FROM `cainiao_menu`
WHERE `path` = 'admin/system'
LIMIT 1;

-- 3. 添加 inject 字段（推送与注入分离）
-- enabled 控制是否推送配置，inject 控制是否注入到新 APK
ALTER TABLE `cainiao_s3_bucket`
  ADD COLUMN IF NOT EXISTS `inject` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否注入到新APK' AFTER `enabled`;
