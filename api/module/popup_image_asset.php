<?php

/**
 * 图片素材库列表。
 */
function getList(PDO $pdo, array $input)
{
    popupImageAssetEnsureTables($pdo);
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $page = max(1, (int)($input['page'] ?? 1));
    $limit = max(1, min(100, (int)($input['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $keyword = trim((string)($input['keyword'] ?? ''));

    $where = [];
    $params = [];
    if (!$isAdmin) {
        $where[] = 'user_id = :uid';
        $params[':uid'] = $userId;
    }
    if ($keyword !== '') {
        $where[] = '(name LIKE :kw OR url LIKE :kw OR remark LIKE :kw)';
        $params[':kw'] = '%' . $keyword . '%';
    }
    $whereSql = empty($where) ? '1=1' : implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_popup_image_asset WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, user_id, name, url, enabled, remark, created_at, updated_at
        FROM cainiao_popup_image_asset
        WHERE $whereSql
        ORDER BY id DESC
        LIMIT $offset, $limit
    ");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($list as &$row) {
        $row['id'] = (int)$row['id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['enabled'] = (int)$row['enabled'];
    }
    unset($row);

    return [
        'list' => $list,
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => (int)ceil($total / $limit),
    ];
}

/**
 * 新增图片素材。
 */
function addAsset(PDO $pdo, array $input)
{
    popupImageAssetEnsureTables($pdo);
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $name = trim((string)($input['name'] ?? ''));
    $url = popupImageAssetNormalizeUrl((string)($input['url'] ?? ''));
    $remark = trim((string)($input['remark'] ?? ''));
    $enabled = isset($input['enabled']) ? (!empty($input['enabled']) ? 1 : 0) : 1;
    if ($name === '') {
        $name = '未命名图片';
    }

    $stmt = $pdo->prepare("
        INSERT INTO cainiao_popup_image_asset (user_id, name, url, enabled, remark, created_at, updated_at)
        VALUES (:uid, :name, :url, :enabled, :remark, NOW(), NOW())
    ");
    $stmt->execute([
        ':uid' => $userId,
        ':name' => $name,
        ':url' => $url,
        ':enabled' => $enabled,
        ':remark' => $remark,
    ]);

    return [
        'message' => '新增成功',
        'id' => (int)$pdo->lastInsertId(),
    ];
}

/**
 * 编辑图片素材。
 */
function editAsset(PDO $pdo, array $input)
{
    popupImageAssetEnsureTables($pdo);
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('缺少素材ID');
    }

    $name = trim((string)($input['name'] ?? ''));
    $url = popupImageAssetNormalizeUrl((string)($input['url'] ?? ''));
    $remark = trim((string)($input['remark'] ?? ''));
    $enabled = isset($input['enabled']) ? (!empty($input['enabled']) ? 1 : 0) : 1;
    if ($name === '') {
        $name = '未命名图片';
    }

    if ($isAdmin) {
        $check = $pdo->prepare("SELECT id FROM cainiao_popup_image_asset WHERE id = :id");
        $check->execute([':id' => $id]);
    } else {
        $check = $pdo->prepare("SELECT id FROM cainiao_popup_image_asset WHERE id = :id AND user_id = :uid");
        $check->execute([':id' => $id, ':uid' => $userId]);
    }
    if (!$check->fetch()) {
        throw new Exception('图片素材不存在或无权操作');
    }

    $affectedAppIds = popupImageAssetGetAffectedAppIds($pdo, $id);
    $stmt = $pdo->prepare("
        UPDATE cainiao_popup_image_asset
        SET name = :name, url = :url, enabled = :enabled, remark = :remark, updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':name' => $name,
        ':url' => $url,
        ':enabled' => $enabled,
        ':remark' => $remark,
        ':id' => $id,
    ]);

    foreach ($affectedAppIds as $appId) {
        Auth::afterConfigChange($pdo, $appId);
    }

    return ['message' => '更新成功'];
}

/**
 * 设置图片素材启用状态。
 */
function setEnabled(PDO $pdo, array $input)
{
    popupImageAssetEnsureTables($pdo);
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('缺少素材ID');
    }
    $enabled = !empty($input['enabled']) ? 1 : 0;

    if ($isAdmin) {
        $check = $pdo->prepare("SELECT id FROM cainiao_popup_image_asset WHERE id = :id");
        $check->execute([':id' => $id]);
    } else {
        $check = $pdo->prepare("SELECT id FROM cainiao_popup_image_asset WHERE id = :id AND user_id = :uid");
        $check->execute([':id' => $id, ':uid' => $userId]);
    }
    if (!$check->fetch()) {
        throw new Exception('图片素材不存在或无权操作');
    }

    $affectedAppIds = popupImageAssetGetAffectedAppIds($pdo, $id);
    $stmt = $pdo->prepare("UPDATE cainiao_popup_image_asset SET enabled = :enabled, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':enabled' => $enabled, ':id' => $id]);

    foreach ($affectedAppIds as $appId) {
        Auth::afterConfigChange($pdo, $appId);
    }

    return ['message' => $enabled === 1 ? '已启用' : '已停用'];
}

/**
 * 删除图片素材。
 */
function deleteAsset(PDO $pdo, array $input)
{
    popupImageAssetEnsureTables($pdo);
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('缺少素材ID');
    }

    if ($isAdmin) {
        $check = $pdo->prepare("SELECT id FROM cainiao_popup_image_asset WHERE id = :id");
        $check->execute([':id' => $id]);
    } else {
        $check = $pdo->prepare("SELECT id FROM cainiao_popup_image_asset WHERE id = :id AND user_id = :uid");
        $check->execute([':id' => $id, ':uid' => $userId]);
    }
    if (!$check->fetch()) {
        throw new Exception('图片素材不存在或无权操作');
    }

    $affectedAppIds = popupImageAssetGetAffectedAppIds($pdo, $id);
    $pdo->prepare("DELETE FROM cainiao_popup_image_asset_ref WHERE asset_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM cainiao_popup_image_asset WHERE id = ?")->execute([$id]);

    foreach ($affectedAppIds as $appId) {
        Auth::afterConfigChange($pdo, $appId);
    }

    return ['message' => '删除成功'];
}

/**
 * 查询某个素材影响到的应用 ID，用于素材变更后刷新配置缓存和桶。
 */
function popupImageAssetGetAffectedAppIds(PDO $pdo, int $assetId): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.apk_id
        FROM cainiao_popup_image_asset_ref r
        JOIN cainiao_popup_image p ON p.id = r.popup_id
        JOIN cainiao_apk_config c ON c.id = p.config_id
        WHERE r.asset_id = ?
    ");
    $stmt->execute([$assetId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
