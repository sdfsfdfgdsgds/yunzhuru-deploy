<?php
// uri_hijack.php（管理员不验证 user_id，普通用户正常校验）

// 获取配置ID（管理员不验证user_id）
function getConfigIdByApk($pdo, $userId, $apkId, $isAdmin = false)
{
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

// 获取列表（管理员不验证user_id）
function getList(PDO $pdo, array $input)
{
    if (empty($input['apk_id'])) throw new Exception('缺少apk_id');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('无效的配置ID');

    $stmt = $pdo->prepare("SELECT * FROM cainiao_uri_hijack WHERE config_id = ? ORDER BY id DESC");
    $stmt->execute([$configId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 新增（管理员不验证user_id）
function add(PDO $pdo, array $input)
{
    if (empty($input['apk_id'])) throw new Exception('缺少apk_id');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('无效的配置ID');

    $stmt = $pdo->prepare("INSERT INTO cainiao_uri_hijack (config_id, remark, class_name, uri_value, created_at)
                           VALUES (:config_id, :remark, :class_name, :uri_value, NOW())");
    $stmt->execute([
        ':config_id'  => $configId,
        ':remark'     => $input['remark'] ?? '',
        ':class_name' => $input['class_name'] ?? '',
        ':uri_value'  => $input['uri_value'] ?? ''
    ]);
    return ['message' => '添加成功'];
}

// 编辑（管理员不验证user_id）
function edit(PDO $pdo, array $input)
{
    if (empty($input['id'])) throw new Exception('缺少id');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        // 非管理员校验归属
        $check = $pdo->prepare("SELECT h.id
            FROM cainiao_uri_hijack h
            JOIN cainiao_apk_config c ON h.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE h.id = :id AND a.user_id = :uid");
        $check->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$check->fetch()) throw new Exception('权限不足或记录不存在');
    }

    $stmt = $pdo->prepare("UPDATE cainiao_uri_hijack 
                           SET remark = :remark, class_name = :class_name, uri_value = :uri_value 
                           WHERE id = :id");
    $stmt->execute([
        ':remark'     => $input['remark'] ?? '',
        ':class_name' => $input['class_name'] ?? '',
        ':uri_value'  => $input['uri_value'] ?? '',
        ':id'         => $input['id']
    ]);
    return ['message' => '更新成功'];
}

// 删除（管理员不验证user_id）
function delete(PDO $pdo, array $input)
{
    if (empty($input['id'])) throw new Exception('缺少id');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        // 非管理员校验归属
        $check = $pdo->prepare("SELECT h.id
            FROM cainiao_uri_hijack h
            JOIN cainiao_apk_config c ON h.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE h.id = :id AND a.user_id = :uid");
        $check->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$check->fetch()) throw new Exception('权限不足或记录不存在');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_uri_hijack WHERE id = ?");
    $stmt->execute([$input['id']]);
    return ['message' => '删除成功'];
}
