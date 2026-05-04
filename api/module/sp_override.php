<?php
//SP数据重写
function getConfigIdByApk($pdo, $userId, $apkId, $isAdmin = false)
{
    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT c.id FROM cainiao_apk_config c
                               WHERE c.apk_id = :apk_id LIMIT 1");
        $stmt->execute([':apk_id' => $apkId]);
    } else {
        $stmt = $pdo->prepare("SELECT c.id FROM cainiao_apk_config c
                               JOIN cainiao_apk a ON a.id = c.apk_id
                               WHERE c.apk_id = :apk_id AND a.user_id = :user_id LIMIT 1");
        $stmt->execute([':apk_id' => $apkId, ':user_id' => $userId]);
    }

    return $stmt->fetchColumn();
}


// 获取SP重写配置列表
function getList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $isAdmin = $user['role'] === 'admin';
    $configId = getConfigIdByApk($pdo, $user['id'], $input['apk_id'],$isAdmin);
    if (!$configId) throw new Exception('配置不存在');
    Auth::reset_redis($input['apk_id']);
    $stmt = $pdo->prepare("SELECT id, sp_name, created_at FROM cainiao_sp_override_name WHERE config_id = :cid ORDER BY id DESC");
    $stmt->execute([':cid' => $configId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 添加SP重写配置
function add(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $isAdmin = $user['role'] === 'admin';
    $configId = getConfigIdByApk($pdo, $user['id'], $input['apk_id'],$isAdmin);
    if (!$configId) throw new Exception('配置不存在');

    $stmt = $pdo->prepare("INSERT INTO cainiao_sp_override_name (config_id, sp_name, created_at)
                           VALUES (:config_id, :sp_name, NOW())");
    $stmt->execute([':config_id' => $configId, ':sp_name' => $input['sp_name']]);

    return ['message' => '添加成功'];
}

// 编辑SP重写配置
function edit(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['id'])) {
        throw new Exception('缺少记录ID');
    }

    if (!$isAdmin) {
        $stmt = $pdo->prepare("SELECT c.id FROM cainiao_sp_override_name n
                               JOIN cainiao_apk_config c ON c.id = n.config_id
                               JOIN cainiao_apk a ON c.apk_id = a.id
                               WHERE n.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足或记录不存在');
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_sp_override_name WHERE id = :id");
        $stmt->execute([':id' => $input['id']]);
        if (!$stmt->fetch()) {
            throw new Exception('记录不存在');
        }
    }

    $stmt = $pdo->prepare("UPDATE cainiao_sp_override_name SET sp_name = :sp_name WHERE id = :id");
    $stmt->execute([':sp_name' => $input['sp_name'], ':id' => $input['id']]);

    return ['message' => '更新成功'];
}


// 删除SP重写配置
function delete(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['id'])) {
        throw new Exception('缺少记录ID');
    }

    if (!$isAdmin) {
        $stmt = $pdo->prepare("SELECT c.id FROM cainiao_sp_override_name n
                               JOIN cainiao_apk_config c ON c.id = n.config_id
                               JOIN cainiao_apk a ON c.apk_id = a.id
                               WHERE n.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足或记录不存在');
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_sp_override_name WHERE id = :id");
        $stmt->execute([':id' => $input['id']]);
        if (!$stmt->fetch()) {
            throw new Exception('记录不存在');
        }
    }

    // 删除主表
    $stmt = $pdo->prepare("DELETE FROM cainiao_sp_override_name WHERE id = :id");
    $stmt->execute([':id' => $input['id']]);

    // 删除关联子表
    $stmt = $pdo->prepare("DELETE FROM cainiao_sp_override_detail WHERE name_id = :id");
    $stmt->execute([':id' => $input['id']]);

    return ['message' => '删除成功'];
}


// 获取SP重写键值列表
function getKeys(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['name_id'])) {
        throw new Exception('缺少参数 name_id');
    }

    if (!$isAdmin) {
        $stmt = $pdo->prepare("SELECT n.id FROM cainiao_sp_override_name n
                               JOIN cainiao_apk_config c ON c.id = n.config_id
                               JOIN cainiao_apk a ON a.id = c.apk_id
                               WHERE n.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['name_id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足或记录不存在');
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_sp_override_name WHERE id = :id");
        $stmt->execute([':id' => $input['name_id']]);
        if (!$stmt->fetch()) {
            throw new Exception('记录不存在');
        }
    }

    $stmt = $pdo->prepare("SELECT id, key_name, key_value, type FROM cainiao_sp_override_detail WHERE name_id = :id ORDER BY id DESC");
    $stmt->execute([':id' => $input['name_id']]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// 添加键值
function addKey(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['name_id'])) {
        throw new Exception('缺少参数 name_id');
    }

    if (!$isAdmin) {
        $stmt = $pdo->prepare("SELECT n.id FROM cainiao_sp_override_name n
                               JOIN cainiao_apk_config c ON c.id = n.config_id
                               JOIN cainiao_apk a ON a.id = c.apk_id
                               WHERE n.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['name_id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足或记录不存在');
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_sp_override_name WHERE id = :id");
        $stmt->execute([':id' => $input['name_id']]);
        if (!$stmt->fetch()) {
            throw new Exception('记录不存在');
        }
    }

    $stmt = $pdo->prepare("INSERT INTO cainiao_sp_override_detail (name_id, key_name, key_value, type, created_at)
                           VALUES (:name_id, :key_name, :key_value, :type, NOW())");
    $stmt->execute([
        ':name_id' => $input['name_id'],
        ':key_name' => $input['key_name'],
        ':key_value' => $input['key_value'],
        ':type' => $input['type']
    ]);

    return ['message' => '添加成功'];
}

// 编辑键值
function editKey(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['id'])) {
        throw new Exception('缺少参数 id');
    }

    if (!$isAdmin) {
        $stmt = $pdo->prepare("SELECT d.id FROM cainiao_sp_override_detail d
                               JOIN cainiao_sp_override_name n ON n.id = d.name_id
                               JOIN cainiao_apk_config c ON c.id = n.config_id
                               JOIN cainiao_apk a ON a.id = c.apk_id
                               WHERE d.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足或记录不存在');
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_sp_override_detail WHERE id = :id");
        $stmt->execute([':id' => $input['id']]);
        if (!$stmt->fetch()) {
            throw new Exception('记录不存在');
        }
    }

    $stmt = $pdo->prepare("UPDATE cainiao_sp_override_detail
                           SET key_name = :key_name, key_value = :key_value, type = :type
                           WHERE id = :id");
    $stmt->execute([
        ':key_name' => $input['key_name'],
        ':key_value' => $input['key_value'],
        ':type' => $input['type'],
        ':id' => $input['id']
    ]);

    return ['message' => '更新成功'];
}


// 删除键值
function deleteKey(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['id'])) {
        throw new Exception('缺少参数 id');
    }

    if (!$isAdmin) {
        $stmt = $pdo->prepare("SELECT d.id FROM cainiao_sp_override_detail d
                               JOIN cainiao_sp_override_name n ON n.id = d.name_id
                               JOIN cainiao_apk_config c ON c.id = n.config_id
                               JOIN cainiao_apk a ON a.id = c.apk_id
                               WHERE d.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足或记录不存在');
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_sp_override_detail WHERE id = :id");
        $stmt->execute([':id' => $input['id']]);
        if (!$stmt->fetch()) {
            throw new Exception('记录不存在');
        }
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_sp_override_detail WHERE id = :id");
    $stmt->execute([':id' => $input['id']]);

    return ['message' => '删除成功'];
}

