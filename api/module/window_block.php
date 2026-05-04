<?php
//activity拦截
function getList(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $apkId = (int)($input['apk_id'] ?? 0);
    if ($apkId <= 0) throw new Exception('参数错误');
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $configId = getConfigIdByApk($pdo, $userId, $apkId, $isAdmin);
    if (!$configId) throw new Exception('未找到配置');
    Auth::reset_redis($apkId);
    $stmt = $pdo->prepare("SELECT id, class_name, remark, created_at 
                           FROM cainiao_window_class 
                           WHERE config_id = :cid 
                           ORDER BY id DESC");
    $stmt->execute([':cid' => $configId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function add(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $apkId = (int)($input['apk_id'] ?? 0);
    if ($apkId <= 0 || empty($input['class_name'])) {
        throw new Exception('参数错误');
    }

    $configId = getConfigIdByApk($pdo, $userId, $apkId, $isAdmin);
    if (!$configId) throw new Exception('无权限或配置不存在');

    $stmt = $pdo->prepare("INSERT INTO cainiao_window_class (config_id, class_name, remark, created_at)
                           VALUES (:cid, :class_name, :remark, NOW())");
    $stmt->execute([
        ':cid' => $configId,
        ':class_name' => $input['class_name'],
        ':remark' => $input['remark'] ?? ''
    ]);

    return ['message' => '添加成功'];
}

function edit(PDO $pdo, array $input) {
    if (empty($input['id'])) throw new Exception('缺少ID');
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        $stmt = $pdo->prepare("
            SELECT w.id FROM cainiao_window_class w
            JOIN cainiao_apk_config c ON w.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE w.id = :id AND a.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) throw new Exception('权限不足或记录不存在');
    }

    $stmt = $pdo->prepare("UPDATE cainiao_window_class 
                           SET class_name = :class_name, remark = :remark 
                           WHERE id = :id");
    $stmt->execute([
        ':class_name' => $input['class_name'],
        ':remark' => $input['remark'] ?? '',
        ':id' => $input['id']
    ]);

    return ['message' => '更新成功'];
}

function delete(PDO $pdo, array $input) {
    if (empty($input['id'])) throw new Exception('缺少ID');
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        $stmt = $pdo->prepare("
            SELECT w.id FROM cainiao_window_class w
            JOIN cainiao_apk_config c ON w.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE w.id = :id AND a.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) throw new Exception('权限不足或记录不存在');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_window_class WHERE id = ?");
    $stmt->execute([$input['id']]);

    return ['message' => '删除成功'];
}

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

