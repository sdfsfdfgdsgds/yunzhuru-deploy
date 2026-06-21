CREATE TABLE IF NOT EXISTS `cainiao_apk_deleted` (
  `apk_id` INT NOT NULL COMMENT '已删除应用 ID',
  `user_id` INT NOT NULL DEFAULT 0 COMMENT '应用原所属用户 ID',
  `deleted_by` INT NOT NULL DEFAULT 0 COMMENT '执行删除的用户 ID',
  `deleted_at` DATETIME NOT NULL COMMENT '删除标记时间',
  `reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '删除原因',
  PRIMARY KEY (`apk_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用删除标记表';
