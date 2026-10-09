CREATE TABLE IF NOT EXISTS `cainiao_apk_info_schedule` (
    `schedule_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '计划 ID',
    `app_id` INT NOT NULL COMMENT '应用 ID',
    `user_id` INT NOT NULL DEFAULT 0 COMMENT '应用所属用户 ID',
    `payload_json` LONGTEXT NOT NULL COMMENT '字段差异和基线',
    `apply_at` DATETIME NOT NULL COMMENT 'UTC 生效时间',
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/applied/cancelled/failed',
    `created_at` DATETIME NOT NULL COMMENT '创建时间（UTC）',
    `updated_at` DATETIME NOT NULL COMMENT '更新时间（UTC）',
    `applied_at` DATETIME NULL COMMENT '实际应用时间（UTC）',
    `error_message` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '失败原因',
    KEY `idx_app_info_schedule_app_status` (`app_id`, `status`, `apply_at`),
    KEY `idx_app_info_schedule_due` (`status`, `apply_at`),
    KEY `idx_app_info_schedule_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用信息延迟生效计划'
