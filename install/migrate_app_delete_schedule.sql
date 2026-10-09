CREATE TABLE IF NOT EXISTS `cainiao_apk_delete_schedule` (
    `schedule_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '删除计划 ID',
    `app_id` INT NOT NULL COMMENT '应用 ID',
    `user_id` INT NOT NULL DEFAULT 0 COMMENT '应用所属用户 ID',
    `requested_by` INT NOT NULL DEFAULT 0 COMMENT '创建计划的操作者 ID',
    `apply_at` DATETIME NOT NULL COMMENT 'UTC 执行时间',
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/queued/completed/cancelled/failed',
    `progress_token` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '删除进度 token',
    `app_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '计划创建时的应用名称',
    `app_package` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '计划创建时的包名',
    `created_at` DATETIME NOT NULL COMMENT '创建时间（UTC）',
    `updated_at` DATETIME NOT NULL COMMENT '更新时间（UTC）',
    `applied_at` DATETIME NULL COMMENT '实际开始删除时间（UTC）',
    `error_message` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '失败原因',
    KEY `idx_apk_delete_schedule_app_status` (`app_id`, `status`, `apply_at`),
    KEY `idx_apk_delete_schedule_due` (`status`, `apply_at`),
    KEY `idx_apk_delete_schedule_user` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用延迟删除计划';
