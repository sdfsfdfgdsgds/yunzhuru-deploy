<?php

/**
 * 事件参数资源列表。
 */
function getList(PDO $pdo, array $input)
{
    clickParamAssetEnsureTables($pdo);
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $page = max(1, (int)($input['page'] ?? 1));
    $limit = max(1, min(100, (int)($input['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $keyword = trim((string)($input['keyword'] ?? ''));
    $actionType = clickParamAssetNormalizeFilterAction($input['action_type'] ?? null);

    $where = [];
    $params = [];
    if (!$isAdmin) {
        $where[] = 'user_id = :uid';
        $params[':uid'] = $userId;
    }
    if ($actionType !== null) {
        $where[] = 'action_type = :action_type';
        $params[':action_type'] = $actionType;
    }
    if ($keyword !== '') {
        $where[] = '(name LIKE :kw OR param_text LIKE :kw OR remark LIKE :kw)';
        $params[':kw'] = '%' . $keyword . '%';
    }
    $whereSql = empty($where) ? '1=1' : implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_click_param_asset WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, user_id, name, action_type, param_text, enabled, `sort`, remark, created_at, updated_at
        FROM cainiao_click_param_asset
        WHERE $whereSql
        ORDER BY `sort` DESC, id DESC
        LIMIT $offset, $limit
    ");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $labels = clickParamAssetActionLabels();
    foreach ($list as &$row) {
        $row['id'] = (int)$row['id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['action_type'] = (int)$row['action_type'];
        $row['enabled'] = (int)$row['enabled'];
        $row['sort'] = (int)$row['sort'];
        $row['action_label'] = $labels[$row['action_type']] ?? '未知事件';
    }
    unset($row);

    return [
        'list' => $list,
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => (int)ceil($total / $limit),
        'actions' => $labels,
    ];
}

/**
 * 新增事件参数资源。
 */
function addAsset(PDO $pdo, array $input)
{
    clickParamAssetEnsureTables($pdo);
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $actionType = clickParamAssetNormalizeAction($input['action_type'] ?? 1);
    $paramText = clickParamAssetNormalizeText($actionType, (string)($input['param_text'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $remark = trim((string)($input['remark'] ?? ''));
    $enabled = isset($input['enabled']) ? (!empty($input['enabled']) ? 1 : 0) : 1;
    $sort = clickParamAssetNormalizeSort($input['sort'] ?? 0);
    if ($name === '') {
        $labels = clickParamAssetActionLabels();
        $name = ($labels[$actionType] ?? '事件参数') . '资源';
    }

    $stmt = $pdo->prepare("
        INSERT INTO cainiao_click_param_asset (user_id, name, action_type, param_text, enabled, `sort`, remark, created_at, updated_at)
        VALUES (:uid, :name, :action_type, :param_text, :enabled, :sort, :remark, NOW(), NOW())
    ");
    $stmt->execute([
        ':uid' => $userId,
        ':name' => $name,
        ':action_type' => $actionType,
        ':param_text' => $paramText,
        ':enabled' => $enabled,
        ':sort' => $sort,
        ':remark' => $remark,
    ]);

    return [
        'message' => '新增成功',
        'id' => (int)$pdo->lastInsertId(),
    ];
}

/**
 * 编辑事件参数资源。
 */
function editAsset(PDO $pdo, array $input)
{
    clickParamAssetEnsureTables($pdo);
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('缺少资源ID');
    }

    $actionType = clickParamAssetNormalizeAction($input['action_type'] ?? 1);
    $paramText = clickParamAssetNormalizeText($actionType, (string)($input['param_text'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $remark = trim((string)($input['remark'] ?? ''));
    $enabled = isset($input['enabled']) ? (!empty($input['enabled']) ? 1 : 0) : 1;
    // 编辑请求缺少 sort 表示保留数据库当前值，显式传入 0 才表示归零。
    $hasSort = array_key_exists('sort', $input);
    $sort = $hasSort ? clickParamAssetNormalizeSort($input['sort']) : null;
    if ($name === '') {
        $labels = clickParamAssetActionLabels();
        $name = ($labels[$actionType] ?? '事件参数') . '资源';
    }

    if ($isAdmin) {
        $check = $pdo->prepare("SELECT id, action_type FROM cainiao_click_param_asset WHERE id = :id");
        $check->execute([':id' => $id]);
    } else {
        $check = $pdo->prepare("SELECT id, action_type FROM cainiao_click_param_asset WHERE id = :id AND user_id = :uid");
        $check->execute([':id' => $id, ':uid' => $userId]);
    }
    $current = $check->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        throw new Exception('事件参数资源不存在或无权操作');
    }

    $affectedAppIds = clickParamAssetGetAffectedAppIds($pdo, $id);
    if (!empty($affectedAppIds) && (int)$current['action_type'] !== $actionType) {
        throw new Exception('该事件参数资源已被关联，不能修改事件分类');
    }
    $setParts = [
        'name = :name',
        'action_type = :action_type',
        'param_text = :param_text',
        'enabled = :enabled',
        'remark = :remark',
    ];
    $updateParams = [
        ':name' => $name,
        ':action_type' => $actionType,
        ':param_text' => $paramText,
        ':enabled' => $enabled,
        ':remark' => $remark,
        ':id' => $id,
    ];
    if ($hasSort) {
        $setParts[] = '`sort` = :sort';
        $updateParams[':sort'] = $sort;
    }
    $setParts[] = 'updated_at = NOW()';

    if ($isAdmin) {
        $whereSql = 'id = :id';
    } else {
        // 最终写入再次带上所有者条件，避免权限预检与更新之间的竞态窗口。
        $whereSql = 'id = :id AND user_id = :uid';
        $updateParams[':uid'] = $userId;
    }
    $stmt = $pdo->prepare(
        'UPDATE cainiao_click_param_asset SET ' . implode(', ', $setParts) . ' WHERE ' . $whereSql
    );
    $stmt->execute($updateParams);

    foreach ($affectedAppIds as $appId) {
        Auth::afterConfigChange($pdo, $appId);
    }

    return ['message' => '更新成功'];
}

/**
 * 设置事件参数资源启用状态。
 */
function setEnabled(PDO $pdo, array $input)
{
    clickParamAssetEnsureTables($pdo);
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('缺少资源ID');
    }
    $enabled = !empty($input['enabled']) ? 1 : 0;

    if ($isAdmin) {
        $check = $pdo->prepare("SELECT id FROM cainiao_click_param_asset WHERE id = :id");
        $check->execute([':id' => $id]);
    } else {
        $check = $pdo->prepare("SELECT id FROM cainiao_click_param_asset WHERE id = :id AND user_id = :uid");
        $check->execute([':id' => $id, ':uid' => $userId]);
    }
    if (!$check->fetch()) {
        throw new Exception('事件参数资源不存在或无权操作');
    }

    $affectedAppIds = clickParamAssetGetAffectedAppIds($pdo, $id);
    $stmt = $pdo->prepare("UPDATE cainiao_click_param_asset SET enabled = :enabled, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':enabled' => $enabled, ':id' => $id]);

    foreach ($affectedAppIds as $appId) {
        Auth::afterConfigChange($pdo, $appId);
    }

    return ['message' => $enabled === 1 ? '已启用' : '已停用'];
}

/**
 * 更新事件参数资源的列表排序值。
 *
 * 排序只改变后台列表和资源选择器的展示顺序，不改变已下发的事件参数。
 */
function setSort(PDO $pdo, array $input)
{
    clickParamAssetEnsureTables($pdo);
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('缺少资源ID');
    }
    if (!array_key_exists('sort', $input)) {
        throw new Exception('缺少排序值');
    }
    $sort = clickParamAssetNormalizeSort($input['sort']);

    if ($isAdmin) {
        $check = $pdo->prepare("SELECT id FROM cainiao_click_param_asset WHERE id = :id");
        $check->execute([':id' => $id]);
    } else {
        $check = $pdo->prepare("SELECT id FROM cainiao_click_param_asset WHERE id = :id AND user_id = :uid");
        $check->execute([':id' => $id, ':uid' => $userId]);
    }
    if (!$check->fetch()) {
        throw new Exception('事件参数资源不存在或无权操作');
    }

    if ($isAdmin) {
        $stmt = $pdo->prepare("UPDATE cainiao_click_param_asset SET `sort` = :sort, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':sort' => $sort, ':id' => $id]);
    } else {
        // 写入语句再次带上所有者条件，避免权限预检与更新之间的竞态窗口。
        $stmt = $pdo->prepare("UPDATE cainiao_click_param_asset SET `sort` = :sort, updated_at = NOW() WHERE id = :id AND user_id = :uid");
        $stmt->execute([':sort' => $sort, ':id' => $id, ':uid' => $userId]);
    }

    return [
        'message' => '排序已更新',
        'sort' => $sort,
    ];
}

/**
 * 删除事件参数资源。
 */
function deleteAsset(PDO $pdo, array $input)
{
    clickParamAssetEnsureTables($pdo);
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('缺少资源ID');
    }

    if ($isAdmin) {
        $check = $pdo->prepare("SELECT id FROM cainiao_click_param_asset WHERE id = :id");
        $check->execute([':id' => $id]);
    } else {
        $check = $pdo->prepare("SELECT id FROM cainiao_click_param_asset WHERE id = :id AND user_id = :uid");
        $check->execute([':id' => $id, ':uid' => $userId]);
    }
    if (!$check->fetch()) {
        throw new Exception('事件参数资源不存在或无权操作');
    }

    $affectedAppIds = clickParamAssetGetAffectedAppIds($pdo, $id);
    $pdo->prepare("DELETE FROM cainiao_click_param_asset_ref WHERE asset_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM cainiao_click_param_asset WHERE id = ?")->execute([$id]);

    foreach ($affectedAppIds as $appId) {
        Auth::afterConfigChange($pdo, $appId);
    }
    return ['message' => '删除成功'];
}

/**
 * 查询某个事件参数资源影响到的应用 ID，用于资源变更后刷新配置缓存和桶。
 */
function clickParamAssetGetAffectedAppIds(PDO $pdo, int $assetId): array
{
    if (!clickParamAssetTablesReady($pdo)) {
        return [];
    }

    $appIds = [];
    $queries = [
        "SELECT DISTINCT c.apk_id
         FROM cainiao_click_param_asset_ref r
         JOIN cainiao_popup_image p ON p.id = r.target_id
         JOIN cainiao_apk_config c ON c.id = p.config_id
         WHERE r.asset_id = ? AND r.target_type = 'popup_image'",
        "SELECT DISTINCT c.apk_id
         FROM cainiao_click_param_asset_ref r
         JOIN cainiao_popup_message_button b ON b.id = r.target_id
         JOIN cainiao_popup_message p ON p.id = b.popup_id
         JOIN cainiao_apk_config c ON c.id = p.config_id
         WHERE r.asset_id = ? AND r.target_type = 'popup_message_button'",
        "SELECT DISTINCT c.apk_id
         FROM cainiao_click_param_asset_ref r
         JOIN cainiao_popup_input_button b ON b.id = r.target_id
         JOIN cainiao_popup_input p ON p.id = b.popup_id
         JOIN cainiao_apk_config c ON c.id = p.config_id
         WHERE r.asset_id = ? AND r.target_type = 'popup_input_button'",
    ];

    foreach ($queries as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$assetId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $appId) {
            $appIds[(int)$appId] = (int)$appId;
        }
    }

    return array_values($appIds);
}
