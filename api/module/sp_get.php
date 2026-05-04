<?php

function getConfigIdByApk($pdo, $userId, $apkId) {
    $stmt = $pdo->prepare("SELECT c.id FROM cainiao_apk_config c
                           JOIN cainiao_apk a ON a.id = c.apk_id
                           WHERE c.apk_id = :apk_id AND a.user_id = :user_id LIMIT 1");
    $stmt->execute([':apk_id' => $apkId, ':user_id' => $userId]);
    return $stmt->fetchColumn();
}

// 获取 SP 读取列表
function getList(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $apkId = (int)$input['apk_id'];

    $configId = getConfigIdByApk($pdo, $userId, $apkId);
    if (!$configId) throw new Exception('未找到配置');

    $stmt = $pdo->prepare("SELECT * FROM cainiao_sp_get_name WHERE config_id = ? ORDER BY id DESC");
    $stmt->execute([$configId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 添加 SP 读取项
function add(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $apkId = (int)$input['apk_id'];
    $spName = trim($input['sp_name']);

    if (!$spName) throw new Exception('SP 名称不能为空');

    $configId = getConfigIdByApk($pdo, $userId, $apkId);
    if (!$configId) throw new Exception('未找到配置');

    $stmt = $pdo->prepare("INSERT INTO cainiao_sp_get_name (config_id, sp_name, created_at) 
                           VALUES (?, ?, NOW())");
    $stmt->execute([$configId, $spName]);

    return ['message' => '添加成功'];
}

// 删除 SP 读取项
function delete(PDO $pdo, array $input) {
    if (empty($input['id'])) throw new Exception('缺少ID');
    $id = (int)$input['id'];

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $check = $pdo->prepare("SELECT n.id FROM cainiao_sp_get_name n
                            JOIN cainiao_apk_config c ON n.config_id = c.id
                            JOIN cainiao_apk a ON a.id = c.apk_id
                            WHERE n.id = :id AND a.user_id = :uid");
    $check->execute([':id' => $id, ':uid' => $userId]);
    if (!$check->fetch()) throw new Exception('无权限');

    $pdo->prepare("DELETE FROM cainiao_sp_get_name WHERE id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM cainiao_sp_get_detail WHERE name_id = ?")->execute([$id]);

    return ['message' => '删除成功'];
}

// 编辑 SP 读取项
function edit(PDO $pdo, array $input) {
    if (empty($input['id'])) throw new Exception('缺少ID');
    $id = (int)$input['id'];
    $spName = trim($input['sp_name']);

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $check = $pdo->prepare("SELECT n.id FROM cainiao_sp_get_name n
                            JOIN cainiao_apk_config c ON n.config_id = c.id
                            JOIN cainiao_apk a ON a.id = c.apk_id
                            WHERE n.id = :id AND a.user_id = :uid");
    $check->execute([':id' => $id, ':uid' => $userId]);
    if (!$check->fetch()) throw new Exception('无权限');

    $stmt = $pdo->prepare("UPDATE cainiao_sp_get_name SET sp_name = ? WHERE id = ?");
    $stmt->execute([$spName, $id]);

    return ['message' => '修改成功'];
}

// 获取键值列表
function getKeys(PDO $pdo, array $input) {
    if (empty($input['name_id'])) throw new Exception('缺少ID');

    $stmt = $pdo->prepare("SELECT * FROM cainiao_sp_get_detail WHERE name_id = ? ORDER BY id DESC");
    $stmt->execute([$input['name_id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 添加键值
function addKey(PDO $pdo, array $input) {
    if (empty($input['name_id']) || empty($input['key_name'])) throw new Exception('缺少参数');

    $stmt = $pdo->prepare("INSERT INTO cainiao_sp_get_detail (name_id, key_name, key_value, type, created_at) 
                           VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([
        $input['name_id'],
        $input['key_name'],
        $input['key_value'],
        $input['type']
    ]);

    return ['message' => '添加成功'];
}

// 修改键值
function editKey(PDO $pdo, array $input) {
    if (empty($input['id'])) throw new Exception('缺少ID');

    $stmt = $pdo->prepare("UPDATE cainiao_sp_get_detail SET key_name = ?, key_value = ?, type = ? WHERE id = ?");
    $stmt->execute([
        $input['key_name'],
        $input['key_value'],
        $input['type'],
        $input['id']
    ]);

    return ['message' => '更新成功'];
}

// 删除键值
function deleteKey(PDO $pdo, array $input) {
    if (empty($input['id'])) throw new Exception('缺少ID');

    $stmt = $pdo->prepare("DELETE FROM cainiao_sp_get_detail WHERE id = ?");
    $stmt->execute([$input['id']]);

    return ['message' => '删除成功'];
}
