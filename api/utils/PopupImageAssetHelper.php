<?php
/**
 * 图片弹窗素材库公共函数
 * 这里同时服务后台素材管理、弹窗配置保存和远程配置生成。
 */

if (!function_exists('popupImageAssetTablesReady')) {
    /**
     * 判断图片素材库相关表是否已经存在。
     */
    function popupImageAssetTablesReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ('cainiao_popup_image_asset', 'cainiao_popup_image_asset_ref')
        ");
        $stmt->execute();
        $ready = ((int)$stmt->fetchColumn() === 2);
        return $ready;
    }
}

if (!function_exists('popupImageAssetIndexExists')) {
    /**
     * 判断索引是否存在。
     */
    function popupImageAssetIndexExists(PDO $pdo, string $table, string $indexName): bool
    {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = :name");
        $stmt->execute([':name' => $indexName]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('popupImageAssetAddIndex')) {
    /**
     * 补齐素材库查询需要的索引。
     */
    function popupImageAssetAddIndex(PDO $pdo, string $table, string $indexName, string $definition): void
    {
        if (!popupImageAssetIndexExists($pdo, $table, $indexName)) {
            $pdo->exec("CREATE INDEX `$indexName` ON `$table` ($definition)");
        }
    }
}

if (!function_exists('popupImageAssetEnsureTables')) {
    /**
     * 确保图片素材库表存在。后台管理接口调用，远程配置请求只读不建表。
     */
    function popupImageAssetEnsureTables(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured && popupImageAssetTablesReady($pdo)) {
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_popup_image_asset` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键',
            `user_id` INT NOT NULL COMMENT '所属用户ID',
            `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '图片名称',
            `url` TEXT NOT NULL COMMENT '图片链接',
            `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
            `created_at` DATETIME NOT NULL COMMENT '创建时间',
            `updated_at` DATETIME NOT NULL COMMENT '更新时间'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_popup_image_asset_ref` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键',
            `popup_id` INT NOT NULL COMMENT '图片弹窗ID',
            `asset_id` INT NOT NULL COMMENT '图片素材ID',
            `sort` INT NOT NULL DEFAULT 0 COMMENT '排序值',
            `weight` INT NOT NULL DEFAULT 1 COMMENT '随机权重',
            `created_at` DATETIME NOT NULL COMMENT '创建时间'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        popupImageAssetAddIndex($pdo, 'cainiao_popup_image_asset', 'idx_user_created', '`user_id`, `created_at`');
        popupImageAssetAddIndex($pdo, 'cainiao_popup_image_asset_ref', 'idx_popup_sort', '`popup_id`, `sort`, `id`');
        popupImageAssetAddIndex($pdo, 'cainiao_popup_image_asset_ref', 'idx_asset_id', '`asset_id`');

        if (!popupImageAssetIndexExists($pdo, 'cainiao_popup_image_asset_ref', 'uniq_popup_asset')) {
            $pdo->exec("ALTER TABLE `cainiao_popup_image_asset_ref` ADD UNIQUE KEY `uniq_popup_asset` (`popup_id`, `asset_id`)");
        }

        $ensured = true;
    }
}

if (!function_exists('popupImageAssetNormalizeUrl')) {
    /**
     * 校验并标准化单个图片链接。
     */
    function popupImageAssetNormalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
            throw new Exception('图片链接不正确');
        }
        return $url;
    }
}

if (!function_exists('popupImageAssetNormalizeMultilineUrls')) {
    /**
     * 校验多行图片链接，返回换行拼接后的兼容字段值。
     */
    function popupImageAssetNormalizeMultilineUrls(string $value): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($value));
        $urls = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $urls[] = popupImageAssetNormalizeUrl($line);
        }
        return implode("\n", $urls);
    }
}

if (!function_exists('popupImageAssetNormalizeIdList')) {
    /**
     * 将前端传入的素材 ID 列表清洗成去重整数数组。
     */
    function popupImageAssetNormalizeIdList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }
}

if (!function_exists('popupImageAssetFetchOwnedIds')) {
    /**
     * 返回当前用户有权使用的素材 ID 列表。
     */
    function popupImageAssetFetchOwnedIds(PDO $pdo, array $assetIds, int $userId, bool $isAdmin): array
    {
        popupImageAssetEnsureTables($pdo);
        if (empty($assetIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
        if ($isAdmin) {
            $stmt = $pdo->prepare("SELECT id FROM cainiao_popup_image_asset WHERE id IN ($placeholders)");
            $stmt->execute($assetIds);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM cainiao_popup_image_asset WHERE id IN ($placeholders) AND user_id = ?");
            $stmt->execute([...$assetIds, $userId]);
        }

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('popupImageAssetSyncRefs')) {
    /**
     * 同步一个图片弹窗的素材选择关系。
     */
    function popupImageAssetSyncRefs(PDO $pdo, int $popupId, array $assetIds, int $userId, bool $isAdmin): void
    {
        popupImageAssetEnsureTables($pdo);
        $assetIds = popupImageAssetNormalizeIdList($assetIds);
        $ownedIds = popupImageAssetFetchOwnedIds($pdo, $assetIds, $userId, $isAdmin);

        if (count($ownedIds) !== count($assetIds)) {
            throw new Exception('图片素材不存在或无权使用');
        }

        $pdo->prepare("DELETE FROM cainiao_popup_image_asset_ref WHERE popup_id = ?")->execute([$popupId]);
        if (empty($ownedIds)) {
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO cainiao_popup_image_asset_ref (popup_id, asset_id, sort, weight, created_at)
            VALUES (?, ?, ?, 1, NOW())
        ");
        foreach ($ownedIds as $index => $assetId) {
            $stmt->execute([$popupId, $assetId, $index + 1]);
        }
    }
}

if (!function_exists('popupImageAssetFetchByPopupIds')) {
    /**
     * 按弹窗 ID 批量获取已关联图片素材。
     */
    function popupImageAssetFetchByPopupIds(PDO $pdo, array $popupIds): array
    {
        if (empty($popupIds) || !popupImageAssetTablesReady($pdo)) {
            return [];
        }

        $popupIds = array_values(array_unique(array_map('intval', $popupIds)));
        $placeholders = implode(',', array_fill(0, count($popupIds), '?'));
        $stmt = $pdo->prepare("
            SELECT r.popup_id, r.asset_id AS id, a.name, a.url, a.remark, r.sort, r.weight
            FROM cainiao_popup_image_asset_ref r
            JOIN cainiao_popup_image_asset a ON a.id = r.asset_id
            WHERE r.popup_id IN ($placeholders)
            ORDER BY r.popup_id ASC, r.sort ASC, r.id ASC
        ");
        $stmt->execute($popupIds);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $popupId = (int)$row['popup_id'];
            $map[$popupId][] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'url' => $row['url'],
                'remark' => $row['remark'],
                'sort' => (int)$row['sort'],
                'weight' => max(1, (int)$row['weight']),
            ];
        }
        return $map;
    }
}

if (!function_exists('popupImageAssetPickUrl')) {
    /**
     * 根据权重从素材列表中选出一个图片 URL。
     */
    function popupImageAssetPickUrl(array $assets): string
    {
        if (empty($assets)) {
            return '';
        }

        $total = 0;
        foreach ($assets as $asset) {
            $total += max(1, (int)($asset['weight'] ?? 1));
        }

        $rand = random_int(1, max(1, $total));
        foreach ($assets as $asset) {
            $rand -= max(1, (int)($asset['weight'] ?? 1));
            if ($rand <= 0) {
                return (string)($asset['url'] ?? '');
            }
        }

        return (string)($assets[0]['url'] ?? '');
    }
}

if (!function_exists('popupImageAssetFirstUrlByIds')) {
    /**
     * 取素材列表中的第一个 URL，用于旧字段 fallback。
     */
    function popupImageAssetFirstUrlByIds(PDO $pdo, array $assetIds, int $userId, bool $isAdmin): string
    {
        $assetIds = popupImageAssetNormalizeIdList($assetIds);
        if (empty($assetIds)) {
            return '';
        }

        popupImageAssetEnsureTables($pdo);
        $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
        if ($isAdmin) {
            $stmt = $pdo->prepare("SELECT id, url FROM cainiao_popup_image_asset WHERE id IN ($placeholders)");
            $stmt->execute($assetIds);
        } else {
            $stmt = $pdo->prepare("SELECT id, url FROM cainiao_popup_image_asset WHERE id IN ($placeholders) AND user_id = ?");
            $stmt->execute([...$assetIds, $userId]);
        }

        $urlMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $urlMap[(int)$row['id']] = $row['url'];
        }
        foreach ($assetIds as $id) {
            if (!empty($urlMap[$id])) {
                return $urlMap[$id];
            }
        }
        return '';
    }
}
