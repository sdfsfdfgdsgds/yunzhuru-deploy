<?php
/**
 * 事件参数资源库公共函数
 * 后台保存资源关联，下发配置时再解析成旧 clickText 字段，保持壳端兼容。
 */

if (!function_exists('clickParamAssetTablesReady')) {
    /**
     * 判断事件参数资源表是否已经存在。
     */
    function clickParamAssetTablesReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ('cainiao_click_param_asset', 'cainiao_click_param_asset_ref')
        ");
        $stmt->execute();
        $ready = ((int)$stmt->fetchColumn() === 2);
        return $ready;
    }
}

if (!function_exists('clickParamAssetIndexExists')) {
    /**
     * 判断索引是否存在。
     */
    function clickParamAssetIndexExists(PDO $pdo, string $table, string $indexName): bool
    {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = :name");
        $stmt->execute([':name' => $indexName]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('clickParamAssetAddIndex')) {
    /**
     * 补齐资源库查询索引。
     */
    function clickParamAssetAddIndex(PDO $pdo, string $table, string $indexName, string $definition): void
    {
        if (!clickParamAssetIndexExists($pdo, $table, $indexName)) {
            try {
                $pdo->exec("CREATE INDEX `$indexName` ON `$table` ($definition)");
            } catch (Throwable $e) {
                // 多实例可能同时通过 SHOW 检查，先完成的请求已建好同名索引时可安全忽略。
                $message = $e->getMessage();
                $isDuplicate = stripos($message, 'Duplicate key name') !== false || strpos($message, '1061') !== false;
                if (!$isDuplicate || !clickParamAssetIndexExists($pdo, $table, $indexName)) {
                    throw $e;
                }
            }
        }
    }
}

if (!function_exists('clickParamAssetColumnExists')) {
    /**
     * 判断事件参数资源表字段是否存在。
     */
    function clickParamAssetColumnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :column");
        $stmt->execute([':column' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('clickParamAssetAddColumnIfMissing')) {
    /**
     * 补齐事件参数资源表字段，兼容已上线的旧表结构。
     */
    function clickParamAssetAddColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!clickParamAssetColumnExists($pdo, $table, $column)) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD `$column` $definition");
            } catch (Throwable $e) {
                // 多实例首次请求并发补列时，只忽略 MySQL 明确的重复字段错误。
                $message = $e->getMessage();
                $isDuplicate = stripos($message, 'Duplicate column') !== false || strpos($message, '1060') !== false;
                if (!$isDuplicate || !clickParamAssetColumnExists($pdo, $table, $column)) {
                    throw $e;
                }
            }
        }
    }
}

if (!function_exists('clickParamAssetDropIndexIfExists')) {
    /**
     * 存量表从单资源关联升级为多资源关联时，移除旧唯一索引。
     */
    function clickParamAssetDropIndexIfExists(PDO $pdo, string $table, string $indexName): void
    {
        if (clickParamAssetIndexExists($pdo, $table, $indexName)) {
            $pdo->exec("ALTER TABLE `$table` DROP INDEX `$indexName`");
        }
    }
}

if (!function_exists('clickParamAssetAddUniqueIndex')) {
    /**
     * 补齐资源关联唯一索引，避免同一目标重复挂同一资源。
     */
    function clickParamAssetAddUniqueIndex(PDO $pdo, string $table, string $indexName, string $definition): void
    {
        if (!clickParamAssetIndexExists($pdo, $table, $indexName)) {
            $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$indexName` ($definition)");
        }
    }
}

if (!function_exists('clickParamAssetEnsureTables')) {
    /**
     * 确保事件参数资源表存在。
     */
    function clickParamAssetEnsureTables(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured && clickParamAssetTablesReady($pdo)) {
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_click_param_asset` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键',
            `user_id` INT NOT NULL COMMENT '所属用户ID',
            `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '参数资源名称',
            `action_type` TINYINT NOT NULL DEFAULT 1 COMMENT '事件类型',
            `param_text` TEXT NOT NULL COMMENT '事件参数内容',
            `enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
            `sort` INT NOT NULL DEFAULT 0 COMMENT '排序值，数值越大越靠前',
            `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
            `created_at` DATETIME NOT NULL COMMENT '创建时间',
            `updated_at` DATETIME NOT NULL COMMENT '更新时间'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_click_param_asset_ref` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键',
            `target_type` VARCHAR(50) NOT NULL COMMENT '关联目标类型',
            `target_id` INT NOT NULL COMMENT '关联目标ID',
            `asset_id` INT NOT NULL COMMENT '事件参数资源ID',
            `created_at` DATETIME NOT NULL COMMENT '创建时间'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        clickParamAssetAddColumnIfMissing($pdo, 'cainiao_click_param_asset', 'enabled', "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用'");
        clickParamAssetAddColumnIfMissing($pdo, 'cainiao_click_param_asset', 'sort', "INT NOT NULL DEFAULT 0 COMMENT '排序值，数值越大越靠前'");
        clickParamAssetAddIndex($pdo, 'cainiao_click_param_asset', 'idx_user_action_created', '`user_id`, `action_type`, `created_at`');
        clickParamAssetAddIndex($pdo, 'cainiao_click_param_asset', 'idx_user_action_sort', '`user_id`, `action_type`, `sort`, `id`');
        clickParamAssetAddIndex($pdo, 'cainiao_click_param_asset_ref', 'idx_target', '`target_type`, `target_id`');
        clickParamAssetAddIndex($pdo, 'cainiao_click_param_asset_ref', 'idx_asset_id', '`asset_id`');
        clickParamAssetDropIndexIfExists($pdo, 'cainiao_click_param_asset_ref', 'uniq_target');
        clickParamAssetAddUniqueIndex($pdo, 'cainiao_click_param_asset_ref', 'uniq_target_asset', '`target_type`, `target_id`, `asset_id`');
        $ensured = true;
    }
}

if (!function_exists('clickParamAssetNormalizeSort')) {
    /**
     * 清洗资源排序值，限制边界以避免异常大整数污染列表顺序。
     */
    function clickParamAssetNormalizeSort($value): int
    {
        return max(-10000, min(10000, (int)$value));
    }
}

if (!function_exists('clickParamAssetActionLabels')) {
    /**
     * 后台支持的点击事件类型名称。
     */
    function clickParamAssetActionLabels(): array
    {
        return [
            1 => '打开链接',
            2 => '添加QQ群',
            4 => '分享内容',
            5 => '提交输入内容',
            6 => '复制内容',
            7 => '打开窗口类',
        ];
    }
}

if (!function_exists('clickParamAssetNormalizeId')) {
    /**
     * 清洗前端传入的事件参数资源 ID。
     */
    function clickParamAssetNormalizeId($value): int
    {
        return max(0, (int)$value);
    }
}

if (!function_exists('clickParamAssetNormalizeIdList')) {
    /**
     * 清洗前端传入的事件参数资源 ID 列表。
     */
    function clickParamAssetNormalizeIdList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            $value = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $trimmed);
        }
        if (!is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = clickParamAssetNormalizeId($item);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }
}

if (!function_exists('clickParamAssetReadIdsFromInput')) {
    /**
     * 兼容前端 camelCase / snake_case，以及旧单 ID 字段。
     */
    function clickParamAssetReadIdsFromInput(array $input): array
    {
        $fields = [
            'clickParamAssetIds',
            'click_param_asset_ids',
            'clickParamAssetId',
            'click_param_asset_id',
        ];
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                // 管理页当前编辑态使用 camelCase；优先读它，避免旧回显字段覆盖刚选择的多资源列表。
                return clickParamAssetNormalizeIdList($input[$field]);
            }
        }
        return [];
    }
}

if (!function_exists('clickParamAssetReadIdFromInput')) {
    /**
     * 兼容前端 camelCase 和后端 snake_case 字段名。
     */
    function clickParamAssetReadIdFromInput(array $input): int
    {
        $ids = clickParamAssetReadIdsFromInput($input);
        return $ids[0] ?? 0;
    }
}

if (!function_exists('clickParamAssetHasIdInInput')) {
    /**
     * 判断请求是否显式携带资源关联字段。
     */
    function clickParamAssetHasIdInInput(array $input): bool
    {
        return array_key_exists('click_param_asset_id', $input)
            || array_key_exists('clickParamAssetId', $input)
            || array_key_exists('click_param_asset_ids', $input)
            || array_key_exists('clickParamAssetIds', $input);
    }
}

if (!function_exists('clickParamAssetFetchOne')) {
    /**
     * 获取当前用户可使用的单个事件参数资源。
     */
    function clickParamAssetFetchOne(PDO $pdo, int $assetId, int $userId, bool $isAdmin): ?array
    {
        clickParamAssetEnsureTables($pdo);
        if ($assetId <= 0) {
            return null;
        }

        if ($isAdmin) {
            $stmt = $pdo->prepare("SELECT id, user_id, name, action_type, param_text, enabled, remark FROM cainiao_click_param_asset WHERE id = :id");
            $stmt->execute([':id' => $assetId]);
        } else {
            $stmt = $pdo->prepare("SELECT id, user_id, name, action_type, param_text, enabled, remark FROM cainiao_click_param_asset WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => $assetId, ':uid' => $userId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['action_type'] = (int)$row['action_type'];
        $row['enabled'] = (int)$row['enabled'];
        return $row;
    }
}

if (!function_exists('clickParamAssetSyncRef')) {
    /**
     * 同步一个按钮或弹窗点击事件与事件参数资源的关联。
     */
    function clickParamAssetSyncRef(PDO $pdo, string $targetType, int $targetId, int $assetId, int $actionType, int $userId, bool $isAdmin): void
    {
        clickParamAssetSyncRefs($pdo, $targetType, $targetId, [$assetId], $actionType, $userId, $isAdmin);
    }
}

if (!function_exists('clickParamAssetSyncRefs')) {
    /**
     * 同步一个按钮或弹窗点击事件与多个事件参数资源的关联。
     */
    function clickParamAssetSyncRefs(PDO $pdo, string $targetType, int $targetId, array $assetIds, int $actionType, int $userId, bool $isAdmin): void
    {
        clickParamAssetEnsureTables($pdo);
        $pdo->prepare("DELETE FROM cainiao_click_param_asset_ref WHERE target_type = ? AND target_id = ?")->execute([$targetType, $targetId]);

        $assetIds = clickParamAssetNormalizeIdList($assetIds);
        if (empty($assetIds) || !array_key_exists($actionType, clickParamAssetActionLabels())) {
            return;
        }

        $assets = [];
        foreach ($assetIds as $assetId) {
            $asset = clickParamAssetFetchOne($pdo, $assetId, $userId, $isAdmin);
            if (!$asset) {
                throw new Exception('事件参数资源不存在或无权使用');
            }
            if ((int)$asset['action_type'] !== $actionType) {
                throw new Exception('事件参数资源分类与点击事件不一致');
            }
            $assets[] = $asset;
        }

        $stmt = $pdo->prepare("
            INSERT INTO cainiao_click_param_asset_ref (target_type, target_id, asset_id, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        foreach ($assets as $asset) {
            $stmt->execute([$targetType, $targetId, (int)$asset['id']]);
        }
    }
}

if (!function_exists('clickParamAssetDeleteRef')) {
    /**
     * 删除指定目标的事件参数资源关联。
     */
    function clickParamAssetDeleteRef(PDO $pdo, string $targetType, int $targetId): void
    {
        if (!clickParamAssetTablesReady($pdo)) {
            return;
        }
        $pdo->prepare("DELETE FROM cainiao_click_param_asset_ref WHERE target_type = ? AND target_id = ?")->execute([$targetType, $targetId]);
    }
}

if (!function_exists('clickParamAssetDeleteRefsByTargets')) {
    /**
     * 批量删除指定目标列表的事件参数资源关联。
     */
    function clickParamAssetDeleteRefsByTargets(PDO $pdo, string $targetType, array $targetIds): void
    {
        if (empty($targetIds) || !clickParamAssetTablesReady($pdo)) {
            return;
        }

        $targetIds = array_values(array_unique(array_filter(array_map('intval', $targetIds))));
        if (empty($targetIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM cainiao_click_param_asset_ref WHERE target_type = ? AND target_id IN ($placeholders)");
        $stmt->execute(array_merge([$targetType], $targetIds));
    }
}

if (!function_exists('clickParamAssetFetchByTargets')) {
    /**
     * 按目标 ID 批量获取已关联的第一个事件参数资源，兼容旧调用。
     */
    function clickParamAssetFetchByTargets(PDO $pdo, string $targetType, array $targetIds): array
    {
        $listMap = clickParamAssetFetchListByTargets($pdo, $targetType, $targetIds);
        $map = [];
        foreach ($listMap as $targetId => $assets) {
            $map[$targetId] = $assets[0] ?? null;
        }
        return $map;
    }
}

if (!function_exists('clickParamAssetFetchListByTargets')) {
    /**
     * 按目标 ID 批量获取已关联的全部事件参数资源。
     */
    function clickParamAssetFetchListByTargets(PDO $pdo, string $targetType, array $targetIds): array
    {
        if (empty($targetIds) || !clickParamAssetTablesReady($pdo)) {
            return [];
        }

        $targetIds = array_values(array_unique(array_map('intval', $targetIds)));
        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
        $enabledSelect = clickParamAssetColumnExists($pdo, 'cainiao_click_param_asset', 'enabled')
            ? 'a.enabled'
            : '1';
        $sortSelect = clickParamAssetColumnExists($pdo, 'cainiao_click_param_asset', 'sort')
            ? 'a.`sort`'
            : '0';
        $stmt = $pdo->prepare("
            SELECT r.target_id, r.asset_id AS id, a.name, a.action_type, a.param_text,
                   $enabledSelect AS enabled, $sortSelect AS `sort`, a.remark
            FROM cainiao_click_param_asset_ref r
            JOIN cainiao_click_param_asset a ON a.id = r.asset_id
            WHERE r.target_type = ? AND r.target_id IN ($placeholders)
            ORDER BY r.id ASC
        ");
        $stmt->execute(array_merge([$targetType], $targetIds));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $targetId = (int)$row['target_id'];
            $map[$targetId][] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'action_type' => (int)$row['action_type'],
                'param_text' => $row['param_text'],
                'enabled' => (int)$row['enabled'],
                'sort' => (int)$row['sort'],
                'remark' => $row['remark'],
            ];
        }
        return $map;
    }
}

if (!function_exists('clickParamAssetNormalizeAssetRows')) {
    /**
     * 兼容单资源和多资源两种 map 值。
     */
    function clickParamAssetNormalizeAssetRows($value): array
    {
        if (empty($value) || !is_array($value)) {
            return [];
        }
        if (isset($value['id'])) {
            return [$value];
        }
        return array_values(array_filter($value, 'is_array'));
    }
}

if (!function_exists('clickParamAssetApplyText')) {
    /**
     * 从关联资源中取出真实下发参数；没有资源时回退旧 clickText。
     */
    function clickParamAssetApplyText(array $assetMap, int $targetId, int $actionType, string $fallback): string
    {
        $assets = clickParamAssetNormalizeAssetRows($assetMap[$targetId] ?? []);
        $text = $fallback;
        $texts = [];
        if (!empty($assets)) {
            // 只要目标已关联资源，就完全以资源启用状态为准，避免禁用后回退旧 clickText。
            $text = '';
            foreach ($assets as $asset) {
                if ((int)($asset['enabled'] ?? 1) !== 1) {
                    continue;
                }
                if ((int)($asset['action_type'] ?? 0) === $actionType) {
                    $paramText = trim((string)($asset['param_text'] ?? ''));
                    if ($paramText !== '') {
                        $texts[] = $paramText;
                    }
                }
            }
            if (!empty($texts)) {
                $text = implode("\n", $texts);
            }
        }

        if ($actionType === 1) {
            $lines = preg_split('/\r\n|\r|\n/', trim($text));
            $lines = array_values(array_filter(array_map('trim', $lines)));
            return !empty($lines) ? $lines[array_rand($lines)] : '';
        }

        return $text;
    }
}

if (!function_exists('clickParamAssetNormalizeAction')) {
    /**
     * 校验并标准化事件类型。
     */
    function clickParamAssetNormalizeAction($value): int
    {
        $actionType = (int)$value;
        if (!array_key_exists($actionType, clickParamAssetActionLabels())) {
            throw new Exception('事件类型不支持');
        }
        return $actionType;
    }
}

if (!function_exists('clickParamAssetNormalizeFilterAction')) {
    /**
     * 列表筛选用的事件类型，空值表示全部。
     */
    function clickParamAssetNormalizeFilterAction($value): ?int
    {
        if ($value === null || $value === '' || $value === 'all') {
            return null;
        }

        $actionType = (int)$value;
        return array_key_exists($actionType, clickParamAssetActionLabels()) ? $actionType : null;
    }
}

if (!function_exists('clickParamAssetNormalizeText')) {
    /**
     * 校验事件参数内容。链接类支持多行，一行一个地址。
     */
    function clickParamAssetNormalizeText(int $actionType, string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new Exception('事件参数不能为空');
        }

        if (in_array($actionType, [1, 5], true)) {
            $lines = preg_split('/\r\n|\r|\n/', $text);
            $urls = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (!preg_match('/^https?:\/\//i', $line)) {
                    throw new Exception('链接参数需要以 http:// 或 https:// 开头');
                }
                $urls[] = $line;
            }
            if (empty($urls)) {
                throw new Exception('链接参数不能为空');
            }
            return implode("\n", $urls);
        }

        return $text;
    }
}
