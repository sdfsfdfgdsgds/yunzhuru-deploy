<?php
// 获取配置ID（管理员不验证user_id）
function getConfigIdByApk($pdo, $userId, $apkId, $isAdmin = false) {
    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_apk_config WHERE apk_id = :apk_id LIMIT 1");
        $stmt->execute([':apk_id' => $apkId]);
    } else {
        $stmt = $pdo->prepare("SELECT c.id FROM cainiao_apk_config c
                               JOIN cainiao_apk a ON a.id = c.apk_id
                               WHERE c.apk_id = :apk_id AND a.user_id = :user_id LIMIT 1");
        $stmt->execute([':apk_id' => $apkId, ':user_id' => $userId]);
    }
    return $stmt->fetchColumn();
}

// 关键词视图：查询（管理员不验证user_id）
function getViews(PDO $pdo, array $input) {
    if (empty($input['apk_id'])) throw new Exception('缺少应用ID');
    $user = Auth::check($pdo);
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $configId = getConfigIdByApk($pdo, $user['id'], $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('权限不足');
    Auth::reset_redis($input['apk_id']);
    $stmt = $pdo->prepare("SELECT id, activity, view_class, view_id, visibility, clickable, imageview, textview, clickAction, clickText, enabled, created_at
                           FROM cainiao_view
                           WHERE config_id = ?");
    $stmt->execute([$configId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 关键词视图：新增（管理员不验证user_id）
function addView(PDO $pdo, array $input) {
    if (empty($input['apk_id']) || empty($input['activity']) || empty($input['view_id']) || empty($input['view_class'])) {
        throw new Exception('参数错误');
    }

    $user = Auth::check($pdo);
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $configId = getConfigIdByApk($pdo, $user['id'], $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('权限不足');

    $stmt = $pdo->prepare("INSERT INTO cainiao_view 
        (config_id, activity, view_class, view_id, visibility, clickable, imageview, textview, clickAction, clickText, enabled, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    $stmt->execute([
        $configId,
        $input['activity'],
        $input['view_class'],
        $input['view_id'],
        isset($input['visibility']) ? (int)$input['visibility'] : 0,
        isset($input['clickable']) ? (int)$input['clickable'] : 0,
        $input['imageview'] ?? '',
        $input['textview'] ?? '',
        isset($input['clickAction']) ? (int)$input['clickAction'] : 0,
        $input['clickText'] ?? '',
        isset($input['enabled']) ? (int)$input['enabled'] : 1
    ]);

    return ['message' => '添加成功'];
}

// 关键词视图：编辑（管理员不验证user_id）
function editView(PDO $pdo, array $input) {
    if (empty($input['id']) || empty($input['activity']) || empty($input['view_id']) || empty($input['view_class'])) {
        throw new Exception('参数错误');
    }

    $user = Auth::check($pdo);
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        // 非管理员进行所属校验
        $stmt = $pdo->prepare("SELECT v.id FROM cainiao_view v
                               JOIN cainiao_apk_config c ON v.config_id = c.id
                               JOIN cainiao_apk a ON c.apk_id = a.id
                               WHERE v.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['id'], ':uid' => $user['id']]);
        if (!$stmt->fetch()) throw new Exception('权限不足');
    }

    $stmt = $pdo->prepare("UPDATE cainiao_view SET 
        activity = ?, 
        view_class = ?, 
        view_id = ?, 
        visibility = ?, 
        clickable = ?, 
        imageview = ?, 
        textview = ?, 
        clickAction = ?, 
        clickText = ?, 
        enabled = ?
        WHERE id = ?");

    $stmt->execute([
        $input['activity'],
        $input['view_class'],
        $input['view_id'],
        isset($input['visibility']) ? (int)$input['visibility'] : 0,
        isset($input['clickable']) ? (int)$input['clickable'] : 0,
        $input['imageview'] ?? '',
        $input['textview'] ?? '',
        isset($input['clickAction']) ? (int)$input['clickAction'] : 0,
        $input['clickText'] ?? '',
        isset($input['enabled']) ? (int)$input['enabled'] : 1,
        (int)$input['id']
    ]);

    return ['message' => '更新成功'];
}

// 关键词视图：删除（管理员不验证user_id）
function deleteView(PDO $pdo, array $input) {
    if (empty($input['id'])) throw new Exception('缺少ID');

    $user = Auth::check($pdo);
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        // 非管理员进行所属校验
        $stmt = $pdo->prepare("SELECT v.id FROM cainiao_view v
                               JOIN cainiao_apk_config c ON v.config_id = c.id
                               JOIN cainiao_apk a ON c.apk_id = a.id
                               WHERE v.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['id'], ':uid' => $user['id']]);
        if (!$stmt->fetch()) throw new Exception('权限不足');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_view WHERE id = ?");
    $stmt->execute([$input['id']]);

    return ['message' => '删除成功'];
}
