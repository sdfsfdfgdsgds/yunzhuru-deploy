<?php

/**
 * 应用数据库物理删除工具。
 *
 * 删除流程先写 cainiao_apk_deleted 标记，保证前台和壳端立即视为不存在；
 * 文件、桶配置、Redis 清完后，再用本工具按依赖顺序显式删除数据库记录。
 */

if (!function_exists('appPhysicalDeleteTableExists')) {
    function appPhysicalDeleteTableExists(PDO $pdo, string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
            LIMIT 1
        ");
        $stmt->execute([':table' => $table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
        return $cache[$table];
    }
}

if (!function_exists('appPhysicalDeleteColumnExists')) {
    function appPhysicalDeleteColumnExists(PDO $pdo, string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }

        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
              AND COLUMN_NAME = :column
            LIMIT 1
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    }
}

if (!function_exists('appPhysicalDeleteSelectIds')) {
    function appPhysicalDeleteSelectIds(PDO $pdo, string $table, string $column, array $values, string $idColumn = 'id'): array
    {
        if (empty($values) || !appPhysicalDeleteTableExists($pdo, $table) || !appPhysicalDeleteColumnExists($pdo, $table, $column)) {
            return [];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $idColumn) || !appPhysicalDeleteColumnExists($pdo, $table, $idColumn)) {
            return [];
        }

        $values = array_values(array_unique(array_map('intval', $values)));
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $stmt = $pdo->prepare("SELECT `{$idColumn}` FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
        $stmt->execute($values);
        return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }
}

if (!function_exists('appPhysicalDeleteExec')) {
    function appPhysicalDeleteExec(PDO $pdo, string $label, string $table, string $sql, array $params = [], ?callable $onStep = null): int
    {
        if (!appPhysicalDeleteTableExists($pdo, $table)) {
            if ($onStep) {
                $onStep([
                    'label' => $label,
                    'table' => $table,
                    'affected' => 0,
                    'skipped' => true,
                ]);
            }
            return 0;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();
        if ($onStep) {
            $onStep([
                'label' => $label,
                'table' => $table,
                'affected' => $affected,
                'skipped' => false,
            ]);
        }
        return $affected;
    }
}

if (!function_exists('appPhysicalDeleteByIds')) {
    function appPhysicalDeleteByIds(PDO $pdo, string $label, string $table, string $column, array $ids, ?callable $onStep = null): int
    {
        if (empty($ids) || !appPhysicalDeleteTableExists($pdo, $table) || !appPhysicalDeleteColumnExists($pdo, $table, $column)) {
            if ($onStep) {
                $onStep([
                    'label' => $label,
                    'table' => $table,
                    'column' => $column,
                    'affected' => 0,
                    'skipped' => true,
                ]);
            }
            return 0;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return appPhysicalDeleteExec(
            $pdo,
            $label,
            $table,
            "DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})",
            $ids,
            $onStep
        );
    }
}

if (!function_exists('appPhysicalDeleteRows')) {
    function appPhysicalDeleteRows(PDO $pdo, string $label, string $table, string $column, int $value, ?callable $onStep = null): int
    {
        if (!appPhysicalDeleteColumnExists($pdo, $table, $column)) {
            if ($onStep) {
                $onStep([
                    'label' => $label,
                    'table' => $table,
                    'column' => $column,
                    'affected' => 0,
                    'skipped' => true,
                ]);
            }
            return 0;
        }

        return appPhysicalDeleteExec(
            $pdo,
            $label,
            $table,
            "DELETE FROM `{$table}` WHERE `{$column}` = ?",
            [$value],
            $onStep
        );
    }
}

if (!function_exists('appPhysicalDeleteRowsChunked')) {
    function appPhysicalDeleteRowsChunked(PDO $pdo, string $label, string $table, string $column, int $value, int $batchSize = 5000, ?callable $onStep = null): int
    {
        if (!appPhysicalDeleteColumnExists($pdo, $table, $column)) {
            if ($onStep) {
                $onStep([
                    'label' => $label,
                    'table' => $table,
                    'column' => $column,
                    'affected' => 0,
                    'skipped' => true,
                    'chunked' => true,
                ]);
            }
            return 0;
        }

        $batchSize = max(100, min(10000, $batchSize));
        $total = 0;
        $chunk = 0;
        do {
            $chunk++;
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` = ? LIMIT {$batchSize}");
            $stmt->execute([$value]);
            $affected = $stmt->rowCount();
            $total += $affected;

            if ($onStep) {
                $onStep([
                    'label' => $label,
                    'table' => $table,
                    'column' => $column,
                    'affected' => $affected,
                    'total_affected' => $total,
                    'chunk' => $chunk,
                    'chunked' => true,
                    'skipped' => false,
                ]);
            }

            // 大表物理删除分片提交，避免长事务/大回滚锁住 shell.php 的统计外键校验。
            if ($affected >= $batchSize) {
                usleep(50000);
            }
        } while ($affected >= $batchSize);

        return $total;
    }
}

if (!function_exists('appPhysicalDeleteClickParamRefs')) {
    function appPhysicalDeleteClickParamRefs(PDO $pdo, string $targetType, array $targetIds, ?callable $onStep = null): int
    {
        if (empty($targetIds)) {
            return 0;
        }
        $targetIds = array_values(array_unique(array_map('intval', $targetIds)));
        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
        $params = array_merge([$targetType], $targetIds);
        return appPhysicalDeleteExec(
            $pdo,
            '事件参数引用 ' . $targetType,
            'cainiao_click_param_asset_ref',
            "DELETE FROM `cainiao_click_param_asset_ref` WHERE `target_type` = ? AND `target_id` IN ({$placeholders})",
            $params,
            $onStep
        );
    }
}

if (!function_exists('appPhysicalDeletePopupStats')) {
    function appPhysicalDeletePopupStats(PDO $pdo, string $module, array $popupIds, ?callable $onStep = null): int
    {
        if (empty($popupIds)) {
            return 0;
        }
        $popupIds = array_values(array_unique(array_map('intval', $popupIds)));
        $placeholders = implode(',', array_fill(0, count($popupIds), '?'));
        $params = array_merge([$module], $popupIds);
        return appPhysicalDeleteExec(
            $pdo,
            '弹窗统计 ' . $module,
            'cainiao_popup_stat_log',
            "DELETE FROM `cainiao_popup_stat_log` WHERE `module` = ? AND `popup_id` IN ({$placeholders})",
            $params,
            $onStep
        );
    }
}

if (!function_exists('physicallyDeleteAppDatabase')) {
    function physicallyDeleteAppDatabase(PDO $pdo, int $appId, ?callable $onStep = null): array
    {
        if ($appId <= 0) {
            throw new InvalidArgumentException('应用 ID 不合法');
        }

        $summary = [
            'app_id' => $appId,
            'deleted' => [],
            'updated' => [],
            'ids' => [],
        ];

        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $pdo->exec('SET SESSION lock_wait_timeout = 5');

        $configIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_apk_config', 'apk_id', [$appId]);
        $injectTaskIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_inject_task', 'apk_id', [$appId]);
        $imagePopupIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_popup_image', 'config_id', $configIds);
        $htmlPopupIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_popup_html', 'config_id', $configIds);
        $messagePopupIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_popup_message', 'config_id', $configIds);
        $inputPopupIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_popup_input', 'config_id', $configIds);
        $messageButtonIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_popup_message_button', 'popup_id', $messagePopupIds);
        $inputButtonIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_popup_input_button', 'popup_id', $inputPopupIds);
        $spPutNameIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_sp_put_name', 'config_id', $configIds);
        $spGetNameIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_sp_get_name', 'config_id', $configIds);
        $spOverrideNameIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_sp_override_name', 'config_id', $configIds);
        $reuseApkIds = [];
        $redirectSourceIds = [];
        if (appPhysicalDeleteTableExists($pdo, 'cainiao_redirect')
            && appPhysicalDeleteColumnExists($pdo, 'cainiao_redirect', 'apk_id1')
            && appPhysicalDeleteColumnExists($pdo, 'cainiao_redirect', 'apk_id2')) {
            $stmt = $pdo->prepare('SELECT DISTINCT `apk_id1` FROM `cainiao_redirect` WHERE `apk_id2` = ?');
            $stmt->execute([$appId]);
            $redirectSourceIds = array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        }

        $summary['ids'] = [
            'config' => $configIds,
            'inject_task' => $injectTaskIds,
            'popup_image' => $imagePopupIds,
            'popup_html' => $htmlPopupIds,
            'popup_message' => $messagePopupIds,
            'popup_input' => $inputPopupIds,
            'popup_message_button' => $messageButtonIds,
            'popup_input_button' => $inputButtonIds,
            'reuse_dependents' => [],
            'redirect_sources' => $redirectSourceIds,
        ];

        $record = function (array $event) use (&$summary, $onStep): void {
            $key = ($event['label'] ?? $event['table'] ?? 'unknown') . '|' . ($event['table'] ?? '');
            if (isset($event['action']) && $event['action'] === 'update') {
                $summary['updated'][$key] = ($summary['updated'][$key] ?? 0) + (int)($event['affected'] ?? 0);
            } else {
                $summary['deleted'][$key] = ($summary['deleted'][$key] ?? 0) + (int)($event['affected'] ?? 0);
            }
            if ($onStep) {
                $onStep($event);
            }
        };

        try {
            $affected = 0;
            if (appPhysicalDeleteTableExists($pdo, 'cainiao_apk') && appPhysicalDeleteColumnExists($pdo, 'cainiao_apk', 'reuse_apk_id')) {
                // 先用普通 SELECT 找到复用本应用的主键，再按主键更新。
                // 不能直接 `UPDATE ... WHERE reuse_apk_id = ?`：生产表该列可能无索引，会扫描并锁住大量应用行。
                $reuseApkIds = appPhysicalDeleteSelectIds($pdo, 'cainiao_apk', 'reuse_apk_id', [$appId]);
                $summary['ids']['reuse_dependents'] = $reuseApkIds;
                if (!empty($reuseApkIds)) {
                    $placeholders = implode(',', array_fill(0, count($reuseApkIds), '?'));
                    $stmt = $pdo->prepare("
                        UPDATE `cainiao_apk`
                        SET `config_mode` = 0,
                            `reuse_apk_id` = NULL,
                            `reuse_options` = NULL
                        WHERE `id` IN ({$placeholders})
                    ");
                    $stmt->execute($reuseApkIds);
                    $affected = $stmt->rowCount();
                }
            }
            $record([
                'action' => 'update',
                'label' => '解除其他应用复用引用',
                'table' => 'cainiao_apk',
                'affected' => $affected,
            ]);

            appPhysicalDeleteRows($pdo, '重定向来源/目标', 'cainiao_redirect', 'apk_id1', $appId, $record);
            appPhysicalDeleteRows($pdo, '重定向目标', 'cainiao_redirect', 'apk_id2', $appId, $record);
            appPhysicalDeleteRows($pdo, '违规公示', 'cainiao_violation', 'appid', $appId, $record);
            appPhysicalDeleteRows($pdo, '在线设备', 'cainiao_ws', 'apk_id', $appId, $record);
            appPhysicalDeleteRows($pdo, '试用设备', 'cainiao_trial', 'apk_id', $appId, $record);
            appPhysicalDeleteRowsChunked($pdo, '请求统计明细', 'cainiao_request_stat', 'apk_id', $appId, 5000, $record);
            appPhysicalDeleteRowsChunked($pdo, '请求统计汇总', 'cainiao_request_stat_sum', 'apk_id', $appId, 1000, $record);
            appPhysicalDeleteRowsChunked($pdo, '请求统计 IP', 'cainiao_request_stat_ip', 'apk_id', $appId, 5000, $record);
            appPhysicalDeleteRowsChunked($pdo, '请求统计设备', 'cainiao_request_stat_device', 'apk_id', $appId, 5000, $record);
            appPhysicalDeleteRows($pdo, '设备拉黑', 'cainiao_disable', 'appid', $appId, $record);
            appPhysicalDeleteRows($pdo, '卡密', 'cainiao_kami', 'app_id', $appId, $record);

            appPhysicalDeleteByIds($pdo, '高速下载记录', 'cainiao_download_record', 'task_id', $injectTaskIds, $record);
            // 任务过期清理时保留制品桶快照，仅在应用本体物理删除时一并清理。
            appPhysicalDeleteRows($pdo, '注入制品固定桶快照', 'cainiao_inject_bucket_snapshot', 'apk_id', $appId, $record);
            appPhysicalDeleteRows($pdo, '普通注入任务', 'cainiao_inject_task', 'apk_id', $appId, $record);
            appPhysicalDeleteRows($pdo, '加固任务', 'cainiao_jiagu_task', 'apk_id', $appId, $record);

            appPhysicalDeleteClickParamRefs($pdo, 'popup_image', $imagePopupIds, $record);
            appPhysicalDeleteClickParamRefs($pdo, 'popup_message_button', $messageButtonIds, $record);
            appPhysicalDeleteClickParamRefs($pdo, 'popup_input_button', $inputButtonIds, $record);
            appPhysicalDeletePopupStats($pdo, 'popup_image', $imagePopupIds, $record);
            appPhysicalDeletePopupStats($pdo, 'popup_message', $messagePopupIds, $record);
            appPhysicalDeletePopupStats($pdo, 'popup_input', $inputPopupIds, $record);
            appPhysicalDeletePopupStats($pdo, 'popup_html', $htmlPopupIds, $record);

            appPhysicalDeleteByIds($pdo, '图片弹窗素材引用', 'cainiao_popup_image_asset_ref', 'popup_id', $imagePopupIds, $record);
            appPhysicalDeleteByIds($pdo, '图片弹窗白名单', 'cainiao_popup_image_whitelist', 'popup_id', $imagePopupIds, $record);
            appPhysicalDeleteByIds($pdo, '图片弹窗黑名单', 'cainiao_popup_fullscreen_blacklist', 'popup_id', $imagePopupIds, $record);
            appPhysicalDeleteByIds($pdo, '图片弹窗', 'cainiao_popup_image', 'id', $imagePopupIds, $record);

            appPhysicalDeleteByIds($pdo, 'HTML 弹窗白名单', 'cainiao_popup_html_whitelist', 'popup_id', $htmlPopupIds, $record);
            appPhysicalDeleteByIds($pdo, 'HTML 弹窗黑名单', 'cainiao_popup_html_blacklist', 'popup_id', $htmlPopupIds, $record);
            appPhysicalDeleteByIds($pdo, 'HTML 弹窗', 'cainiao_popup_html', 'id', $htmlPopupIds, $record);

            appPhysicalDeleteByIds($pdo, '文字弹窗按钮', 'cainiao_popup_message_button', 'id', $messageButtonIds, $record);
            appPhysicalDeleteByIds($pdo, '文字弹窗白名单', 'cainiao_popup_message_whitelist', 'popup_id', $messagePopupIds, $record);
            appPhysicalDeleteByIds($pdo, '文字弹窗黑名单', 'cainiao_popup_message_blacklist', 'popup_id', $messagePopupIds, $record);
            appPhysicalDeleteByIds($pdo, '文字弹窗', 'cainiao_popup_message', 'id', $messagePopupIds, $record);

            appPhysicalDeleteByIds($pdo, '输入框弹窗按钮', 'cainiao_popup_input_button', 'id', $inputButtonIds, $record);
            appPhysicalDeleteByIds($pdo, '输入框弹窗白名单', 'cainiao_popup_input_whitelist', 'popup_id', $inputPopupIds, $record);
            appPhysicalDeleteByIds($pdo, '输入框弹窗黑名单', 'cainiao_popup_input_blacklist', 'popup_id', $inputPopupIds, $record);
            appPhysicalDeleteByIds($pdo, '输入框弹窗', 'cainiao_popup_input', 'id', $inputPopupIds, $record);

            appPhysicalDeleteByIds($pdo, 'SP 写入详情', 'cainiao_sp_put_detail', 'name_id', $spPutNameIds, $record);
            appPhysicalDeleteByIds($pdo, 'SP 写入名称', 'cainiao_sp_put_name', 'id', $spPutNameIds, $record);
            appPhysicalDeleteByIds($pdo, 'SP 读取详情', 'cainiao_sp_get_detail', 'name_id', $spGetNameIds, $record);
            appPhysicalDeleteByIds($pdo, 'SP 读取名称', 'cainiao_sp_get_name', 'id', $spGetNameIds, $record);
            appPhysicalDeleteByIds($pdo, 'SP 重写详情', 'cainiao_sp_override_detail', 'name_id', $spOverrideNameIds, $record);
            appPhysicalDeleteByIds($pdo, 'SP 重写名称', 'cainiao_sp_override_name', 'id', $spOverrideNameIds, $record);

            foreach ([
                'cainiao_window_class' => '窗口类配置',
                'cainiao_newactivity' => 'Activity 替换配置',
                'cainiao_sensitive_app' => '敏感应用配置',
                'cainiao_popup_kill_type' => '通杀弹窗类型',
                'cainiao_keyword' => '关键词配置',
                'cainiao_view' => '布局重写配置',
                'cainiao_remote_dex' => '远程 DEX 配置',
                'cainiao_popup_block_type' => '拦截弹窗类型',
                'cainiao_uri_hijack' => 'URI 劫持配置',
            ] as $table => $label) {
                appPhysicalDeleteByIds($pdo, $label, $table, 'config_id', $configIds, $record);
            }

            appPhysicalDeleteRows($pdo, '应用配置主表', 'cainiao_apk_config', 'apk_id', $appId, $record);
            appPhysicalDeleteRows($pdo, '应用主表', 'cainiao_apk', 'id', $appId, $record);
            appPhysicalDeleteRows($pdo, '删除标记', 'cainiao_apk_deleted', 'apk_id', $appId, $record);
        } catch (Throwable $e) {
            // 物理清理已改为逐语句自动提交，避免失败时回滚大量统计记录并长时间锁表。
            throw $e;
        }

        return $summary;
    }
}
