<?php

function getConfigIdByApk($pdo, $userId, $apkId)
{
    $stmt = $pdo->prepare("SELECT c.id FROM cainiao_apk_config c
                           JOIN cainiao_apk a ON a.id = c.apk_id
                           WHERE c.apk_id = :apk_id AND a.user_id = :user_id LIMIT 1");
    $stmt->execute([':apk_id' => $apkId, ':user_id' => $userId]);
    return $stmt->fetchColumn();
}


function add(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['apk_id']) || empty($input['sp_name'])) {
        throw new Exception('缺少参数');
    }

    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id']);
    if (!$configId) throw new Exception('无权限或配置不存在');

    $stmt = $pdo->prepare("INSERT INTO cainiao_sp_put_name (config_id, sp_name, created_at)
                           VALUES (:config_id, :sp_name, NOW())");
    $stmt->execute([
        ':config_id' => $configId,
        ':sp_name'   => $input['sp_name']
    ]);

    return ['message' => '添加成功'];
}


function getList(PDO $pdo, array $input)
{
    if (empty($input['apk_id'])) throw new Exception('缺少应用ID');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id']);
    if (!$configId) throw new Exception('权限不足');

    $stmt = $pdo->prepare("SELECT id, sp_name, created_at FROM cainiao_sp_put_name WHERE config_id = :config_id ORDER BY id DESC");
    $stmt->execute([':config_id' => $configId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function edit(PDO $pdo, array $input)
{
    if (empty($input['id'])) {
        throw new Exception('缺少ID');
    }
    if (empty($input['sp_name'])) {
        throw new Exception('请输入SP名称');
    }

    $id = (int)$input['id'];
    $spName = trim($input['sp_name']);

    // 获取当前用户
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    // 校验该SP数据是否属于当前用户的应用
    $check = $pdo->prepare("
        SELECT s.id
        FROM cainiao_sp_put_name s
        JOIN cainiao_apk_config c ON s.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE s.id = :id AND a.user_id = :uid
    ");
    $check->execute([':id' => $id, ':uid' => $userId]);
    if (!$check->fetch()) {
        throw new Exception('权限不足或数据不存在');
    }

    // 更新
    $stmt = $pdo->prepare("UPDATE cainiao_sp_put_name SET sp_name = :sp_name WHERE id = :id");
    $stmt->execute([
        ':sp_name' => $spName,
        ':id' => $id
    ]);

    return ['message' => '更新成功'];
}


function deleteSpName(PDO $pdo, array $input)
{
    if (empty($input['id'])) throw new Exception('缺少ID');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    // 校验权限
    $stmt = $pdo->prepare("
        SELECT n.config_id FROM cainiao_sp_put_name n
        JOIN cainiao_apk_config c ON n.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE n.id = :id AND a.user_id = :uid
    ");
    $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
    if (!$stmt->fetch()) throw new Exception('权限不足');

    $pdo->prepare("DELETE FROM cainiao_sp_put_detail WHERE name_id = :id")->execute([':id' => $input['id']]);
    $pdo->prepare("DELETE FROM cainiao_sp_put_name WHERE id = :id")->execute([':id' => $input['id']]);

    return ['message' => '删除成功'];
}

function getKeys(PDO $pdo, array $input)
{
    if (empty($input['name_id'])) {
        throw new Exception('缺少劫持名称ID');
    }

    $nameId = (int)$input['name_id'];

    // 获取当前用户
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    // 校验该 name_id 是否属于当前用户的应用
    $check = $pdo->prepare("
        SELECT s.id
        FROM cainiao_sp_put_name s
        JOIN cainiao_apk_config c ON s.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE s.id = :id AND a.user_id = :uid
    ");
    $check->execute([':id' => $nameId, ':uid' => $userId]);
    if (!$check->fetch()) {
        throw new Exception('权限不足或数据不存在');
    }

    // 查询键值列表
    $stmt = $pdo->prepare("
        SELECT id, key_name, key_value, type, created_at
        FROM cainiao_sp_put_detail
        WHERE name_id = :name_id
        ORDER BY id DESC
    ");
    $stmt->execute([':name_id' => $nameId]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $list;
}

function editKey(PDO $pdo, array $input)
{
    if (empty($input['id'])) {
        throw new Exception('缺少键值ID');
    }

    // 校验当前用户是否有权限修改该键值
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $keyId = (int)$input['id'];

    $check = $pdo->prepare("
        SELECT d.id
        FROM cainiao_sp_put_detail d
        JOIN cainiao_sp_put_name n ON d.name_id = n.id
        JOIN cainiao_apk_config c ON n.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE d.id = :id AND a.user_id = :uid
    ");
    $check->execute([':id' => $keyId, ':uid' => $userId]);
    if (!$check->fetch()) {
        throw new Exception('权限不足或键值不存在');
    }

    // 执行更新
    $sql = "UPDATE cainiao_sp_put_detail
            SET key_name = :key_name, key_value = :key_value, type = :type
            WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $keyId,
        ':key_name' => $input['key_name'],
        ':key_value' => $input['key_value'],
        ':type' => $input['type']
    ]);

    return ['message' => '更新成功'];
}


function addKey(PDO $pdo, array $input)
{
    if (empty($input['name_id']) || empty($input['key_name'])) {
        throw new Exception('缺少必要参数');
    }

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    // 校验权限
    $stmt = $pdo->prepare("
        SELECT n.id FROM cainiao_sp_put_name n
        JOIN cainiao_apk_config c ON n.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE n.id = :id AND a.user_id = :uid
    ");
    $stmt->execute([':id' => $input['name_id'], ':uid' => $userId]);
    if (!$stmt->fetch()) throw new Exception('权限不足');

    $stmt = $pdo->prepare("INSERT INTO cainiao_sp_put_detail (name_id, key_name, key_value, type, created_at)
                           VALUES (:name_id, :key_name, :key_value, :type, NOW())");
    $stmt->execute([
        ':name_id'   => $input['name_id'],
        ':key_name'  => $input['key_name'],
        ':key_value' => $input['key_value'] ?? '',
        ':type'      => $input['type'] ?? ''
    ]);

    return ['message' => '添加成功'];
}



function deleteKey(PDO $pdo, array $input)
{
    if (empty($input['id'])) {
        throw new Exception('缺少键值ID');
    }

    $keyId = (int)$input['id'];
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    // 校验权限：确保该键值对应的应用属于当前用户
    $check = $pdo->prepare("
        SELECT d.id
        FROM cainiao_sp_put_detail d
        JOIN cainiao_sp_put_name n ON d.name_id = n.id
        JOIN cainiao_apk_config c ON n.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE d.id = :id AND a.user_id = :uid
    ");
    $check->execute([':id' => $keyId, ':uid' => $userId]);
    if (!$check->fetch()) {
        throw new Exception('权限不足或键值不存在');
    }

    // 执行删除
    $stmt = $pdo->prepare("DELETE FROM cainiao_sp_put_detail WHERE id = :id");
    $stmt->execute([':id' => $keyId]);

    return ['message' => '删除成功'];
}

