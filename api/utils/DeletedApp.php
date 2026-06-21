<?php

/**
 * 应用删除标记工具。
 *
 * 删除入口不再同步硬删 cainiao_apk，避免外键/索引/锁等待把用户请求卡死。
 * 已删除应用写入独立标记表；列表、配置下发、桶同步等入口看到标记后按不存在处理。
 */

if (!function_exists('ensureApkDeleteMarkerTable')) {
    function ensureApkDeleteMarkerTable(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `cainiao_apk_deleted` (
                `apk_id` INT NOT NULL COMMENT '已删除应用 ID',
                `user_id` INT NOT NULL DEFAULT 0 COMMENT '应用原所属用户 ID',
                `deleted_by` INT NOT NULL DEFAULT 0 COMMENT '执行删除的用户 ID',
                `deleted_at` DATETIME NOT NULL COMMENT '删除标记时间',
                `reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '删除原因',
                PRIMARY KEY (`apk_id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_deleted_at` (`deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用删除标记表'
        ");

        $checked = true;
    }
}

if (!function_exists('markApkDeleted')) {
    function markApkDeleted(PDO $pdo, int $appId, int $userId, int $deletedBy, string $reason = ''): int
    {
        ensureApkDeleteMarkerTable($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO `cainiao_apk_deleted`
                (`apk_id`, `user_id`, `deleted_by`, `deleted_at`, `reason`)
            VALUES
                (:apk_id, :user_id, :deleted_by, NOW(), :reason)
            ON DUPLICATE KEY UPDATE
                `user_id` = VALUES(`user_id`),
                `deleted_by` = VALUES(`deleted_by`),
                `deleted_at` = VALUES(`deleted_at`),
                `reason` = VALUES(`reason`)
        ");
        $stmt->execute([
            ':apk_id' => $appId,
            ':user_id' => $userId,
            ':deleted_by' => $deletedBy,
            ':reason' => substr($reason, 0, 255),
        ]);

        return $stmt->rowCount();
    }
}

if (!function_exists('isApkDeleted')) {
    function isApkDeleted(PDO $pdo, int $appId): bool
    {
        if ($appId <= 0) {
            return false;
        }

        ensureApkDeleteMarkerTable($pdo);
        $stmt = $pdo->prepare("SELECT 1 FROM `cainiao_apk_deleted` WHERE `apk_id` = :apk_id LIMIT 1");
        $stmt->execute([':apk_id' => $appId]);
        return (bool)$stmt->fetchColumn();
    }
}
