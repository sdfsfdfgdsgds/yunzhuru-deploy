<?php
function getList(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $apkId = (int)($input['apk_id'] ?? 0);
    if ($apkId <= 0) throw new Exception('参数错误');

    $configId = getConfigIdByApk($pdo, $userId, $apkId, $isAdmin);
    if (!$configId) throw new Exception('未找到配置');

    $stmt = $pdo->prepare("SELECT id, activity, newactivity, remark, created_at 
                           FROM cainiao_newactivity 
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
    $activity = trim($input['activity'] ?? '');
    $newactivity = trim($input['newactivity'] ?? '');

    if ($apkId <= 0 || $activity === '' || $newactivity === '') {
        throw new Exception('参数错误');
    }

    $configId = getConfigIdByApk($pdo, $userId, $apkId, $isAdmin);
    if (!$configId) throw new Exception('无权限或配置不存在');

    $stmt = $pdo->prepare("INSERT INTO cainiao_newactivity (config_id, activity, newactivity, remark, created_at)
                           VALUES (:cid, :activity, :newactivity, :remark, NOW())");
    $stmt->execute([
        ':cid'        => $configId,
        ':activity'   => $activity,
        ':newactivity'=> $newactivity,
        ':remark'     => $input['remark'] ?? ''
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
            SELECT n.id FROM cainiao_newactivity n
            JOIN cainiao_apk_config c ON n.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE n.id = :id AND a.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) throw new Exception('权限不足或记录不存在');
    }

    $stmt = $pdo->prepare("UPDATE cainiao_newactivity 
                           SET activity = :activity, newactivity = :newactivity, remark = :remark 
                           WHERE id = :id");
    $stmt->execute([
        ':activity'    => $input['activity'] ?? '',
        ':newactivity' => $input['newactivity'] ?? '',
        ':remark'      => $input['remark'] ?? '',
        ':id'          => $input['id']
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
            SELECT n.id FROM cainiao_newactivity n
            JOIN cainiao_apk_config c ON n.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE n.id = :id AND a.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) throw new Exception('权限不足或记录不存在');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_newactivity WHERE id = ?");
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

