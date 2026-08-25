-- ============================================================
-- 配置分发：API 域名池、DoH 池、DNS 池与真实路径统计
-- 可在 MySQL 8 生产库重复执行；不会重新导入管理员主动删除的节点。
-- ============================================================

CREATE TABLE IF NOT EXISTS `cainiao_config_delivery_meta` (
  `key_name` varchar(64) NOT NULL,
  `key_value` varchar(255) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配置分发迁移状态';

CREATE TABLE IF NOT EXISTS `cainiao_api_domain_pool` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `base_url` varchar(512) NOT NULL,
  `usage_scope` varchar(16) NOT NULL DEFAULT 'config',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_api_url_scope` (`base_url`,`usage_scope`),
  KEY `idx_api_enabled_priority` (`enabled`,`priority`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API 域名池';

CREATE TABLE IF NOT EXISTS `cainiao_doh_pool` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `url` varchar(512) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doh_url` (`url`),
  KEY `idx_doh_enabled_priority` (`enabled`,`priority`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='DoH 解析池';

CREATE TABLE IF NOT EXISTS `cainiao_dns_pool` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dns_ip` (`ip`),
  KEY `idx_dns_enabled_priority` (`enabled`,`priority`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='UDP DNS 解析池';

CREATE TABLE IF NOT EXISTS `cainiao_api_domain_stats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `domain_pool_id` int unsigned NOT NULL,
  `scope` varchar(16) NOT NULL DEFAULT 'config',
  `stat_date` date NOT NULL,
  `request_count` bigint unsigned NOT NULL DEFAULT 0,
  `ok_count` bigint unsigned NOT NULL DEFAULT 0,
  `fail_count` bigint unsigned NOT NULL DEFAULT 0,
  `last_seen_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_api_domain_day` (`domain_pool_id`,`scope`,`stat_date`),
  KEY `idx_api_domain_stat_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API 域名池日请求统计';

CREATE TABLE IF NOT EXISTS `cainiao_dns_path_stats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `stat_date` date NOT NULL,
  `dimension_hash` char(64) NOT NULL,
  `domain_pool_id` int unsigned NOT NULL DEFAULT 0,
  `scope` varchar(16) NOT NULL DEFAULT 'config',
  `dns_mode` varchar(16) NOT NULL DEFAULT 'unknown',
  `dns_provider` varchar(191) NOT NULL DEFAULT '',
  `target_host` varchar(255) NOT NULL DEFAULT '',
  `app_id` int unsigned NOT NULL DEFAULT 0,
  `package_name` varchar(255) NOT NULL DEFAULT '',
  `carrier` varchar(100) NOT NULL DEFAULT '',
  `network_type` varchar(32) NOT NULL DEFAULT '',
  `request_count` bigint unsigned NOT NULL DEFAULT 0,
  `ok_count` bigint unsigned NOT NULL DEFAULT 0,
  `fail_count` bigint unsigned NOT NULL DEFAULT 0,
  `rejected_count` bigint unsigned NOT NULL DEFAULT 0,
  `rescued_count` bigint unsigned NOT NULL DEFAULT 0,
  `last_seen_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dns_path_day` (`stat_date`,`dimension_hash`),
  KEY `idx_dns_path_date_mode` (`stat_date`,`dns_mode`),
  KEY `idx_dns_path_app_date` (`app_id`,`stat_date`),
  KEY `idx_dns_path_pool_date` (`domain_pool_id`,`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='壳端真实 DNS 路径日统计';

-- 壳端回执遇到超时会重试；去重表保证两张聚合表只累加一次。
CREATE TABLE IF NOT EXISTS `cainiao_network_path_receipt` (
  `receipt_hash` char(64) NOT NULL,
  `app_id` int unsigned NOT NULL,
  `received_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`receipt_hash`),
  KEY `idx_network_receipt_time` (`received_at`),
  KEY `idx_network_receipt_app_time` (`app_id`,`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='网络路径回执幂等去重';

-- 与 v152 内置 POST_URL_LIST 保持一致，减少第一次上线的行为变化。
INSERT IGNORE INTO `cainiao_api_domain_pool` (`name`,`base_url`,`usage_scope`,`enabled`,`priority`)
SELECT '新平台 IP','http://143.92.40.164:8090/shell','config',1,400
WHERE NOT EXISTS (SELECT 1 FROM `cainiao_config_delivery_meta` WHERE `key_name`='pool_seed_v1');
INSERT IGNORE INTO `cainiao_api_domain_pool` (`name`,`base_url`,`usage_scope`,`enabled`,`priority`)
SELECT '旧平台 IP 备用','http://143.92.40.191/shell.php','config',1,300
WHERE NOT EXISTS (SELECT 1 FROM `cainiao_config_delivery_meta` WHERE `key_name`='pool_seed_v1');
INSERT IGNORE INTO `cainiao_api_domain_pool` (`name`,`base_url`,`usage_scope`,`enabled`,`priority`)
SELECT '正式域名 .top','https://*.zkzam9hoby.top/shell.php','config',1,200
WHERE NOT EXISTS (SELECT 1 FROM `cainiao_config_delivery_meta` WHERE `key_name`='pool_seed_v1');
INSERT IGNORE INTO `cainiao_api_domain_pool` (`name`,`base_url`,`usage_scope`,`enabled`,`priority`)
SELECT '正式域名 .com','https://*.zkzam9hoby.com/shell.php','config',1,100
WHERE NOT EXISTS (SELECT 1 FROM `cainiao_config_delivery_meta` WHERE `key_name`='pool_seed_v1');

-- DoH 常用预设，priority 按展示顺序从高到低。
INSERT IGNORE INTO `cainiao_doh_pool` (`name`,`url`,`enabled`,`priority`)
SELECT seed.name,seed.url,1,seed.priority FROM (
  SELECT 'Cloudflare' name,'https://cloudflare-dns.com/dns-query' url,170 priority UNION ALL
  SELECT 'Cloudflare 1.1.1.1','https://1.1.1.1/dns-query',160 UNION ALL
  SELECT 'Cloudflare 1.0.0.1','https://1.0.0.1/dns-query',150 UNION ALL
  SELECT 'Google','https://dns.google/resolve',140 UNION ALL
  SELECT 'Google 8.8.8.8','https://8.8.8.8/resolve',130 UNION ALL
  SELECT 'Google 8.8.4.4','https://8.8.4.4/resolve',120 UNION ALL
  SELECT '阿里 DNS','https://dns.alidns.com/resolve',110 UNION ALL
  SELECT '阿里 223.5.5.5','https://223.5.5.5/resolve',100 UNION ALL
  SELECT '阿里 223.6.6.6','https://223.6.6.6/resolve',90 UNION ALL
  SELECT 'DNSPod','https://doh.pub/dns-query',80 UNION ALL
  SELECT 'DNSPod 1.12.12.12','https://1.12.12.12/dns-query',70 UNION ALL
  SELECT 'DNSPod 120.53.53.53','https://120.53.53.53/dns-query',60 UNION ALL
  SELECT 'Quad9','https://dns.quad9.net/dns-query',50 UNION ALL
  SELECT 'Quad9 9.9.9.9','https://9.9.9.9/dns-query',40 UNION ALL
  SELECT 'Quad9 149.112.112.112','https://149.112.112.112/dns-query',30 UNION ALL
  SELECT 'OpenDNS','https://doh.opendns.com/dns-query',20 UNION ALL
  SELECT 'Cisco Umbrella','https://doh.umbrella.com/dns-query',10
) seed
WHERE NOT EXISTS (SELECT 1 FROM `cainiao_config_delivery_meta` WHERE `key_name`='pool_seed_v1');

-- UDP DNS 常用预设。
INSERT IGNORE INTO `cainiao_dns_pool` (`name`,`ip`,`enabled`,`priority`)
SELECT seed.name,seed.ip,1,seed.priority FROM (
  SELECT '114DNS' name,'114.114.114.114' ip,160 priority UNION ALL
  SELECT '114DNS 备用','114.114.115.115',150 UNION ALL
  SELECT '百度 DNS','180.76.76.76',140 UNION ALL
  SELECT '阿里 223.5.5.5','223.5.5.5',130 UNION ALL
  SELECT '阿里 223.6.6.6','223.6.6.6',120 UNION ALL
  SELECT 'DNSPod 119.29.29.29','119.29.29.29',110 UNION ALL
  SELECT 'DNSPod 1.12.12.12','1.12.12.12',100 UNION ALL
  SELECT 'DNSPod 120.53.53.53','120.53.53.53',90 UNION ALL
  SELECT 'Google 8.8.8.8','8.8.8.8',80 UNION ALL
  SELECT 'Google 8.8.4.4','8.8.4.4',70 UNION ALL
  SELECT 'Cloudflare 1.1.1.1','1.1.1.1',60 UNION ALL
  SELECT 'Cloudflare 1.0.0.1','1.0.0.1',50 UNION ALL
  SELECT 'Quad9 9.9.9.9','9.9.9.9',40 UNION ALL
  SELECT 'Quad9 149.112.112.112','149.112.112.112',30 UNION ALL
  SELECT 'OpenDNS 208.67.222.222','208.67.222.222',20 UNION ALL
  SELECT 'OpenDNS 208.67.220.220','208.67.220.220',10
) seed
WHERE NOT EXISTS (SELECT 1 FROM `cainiao_config_delivery_meta` WHERE `key_name`='pool_seed_v1');

INSERT IGNORE INTO `cainiao_config_delivery_meta` (`key_name`,`key_value`)
VALUES ('network_config_version','1');

INSERT INTO `cainiao_config_delivery_meta` (`key_name`,`key_value`)
VALUES ('schema_version','2')
ON DUPLICATE KEY UPDATE `key_value`=GREATEST(CAST(`key_value` AS UNSIGNED),2);

INSERT INTO `cainiao_config_delivery_meta` (`key_name`,`key_value`)
VALUES ('pool_seed_v1','1')
ON DUPLICATE KEY UPDATE `key_value`=VALUES(`key_value`);

-- 把新入口放在现有“系统设置”分组下，重复执行不生成第二个菜单。
INSERT INTO `cainiao_menu` (`parent_id`,`name`,`icon`,`path`,`hidden`,`role_id`)
SELECT system_menu.`parent_id`,'配置分发','Connection','admin/config_delivery',0,system_menu.`role_id`
FROM `cainiao_menu` system_menu
WHERE system_menu.`path`='admin/system'
  AND NOT EXISTS (SELECT 1 FROM `cainiao_menu` existing WHERE existing.`path`='admin/config_delivery')
LIMIT 1;
