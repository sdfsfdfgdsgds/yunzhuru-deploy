<?php
//通杀拦截
function getPopupTypes(PDO $pdo, array $input = null) {
    // 获取所有弹窗类型，用于下拉选择
    $stmt = $pdo->query("SELECT id, popup_id, description FROM cainiao_popup_type ORDER BY popup_id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getKillList(PDO $pdo, array $input) {
    if (empty($input['apk_id'])) {
        throw new Exception('缺少应用ID');
    }

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';
    $apkId = (int)$input['apk_id'];

    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_apk_config WHERE apk_id = :apk_id LIMIT 1");
        $stmt->execute([':apk_id' => $apkId]);
    } else {
        $stmt = $pdo->prepare("SELECT c.id FROM cainiao_apk_config c
                               JOIN cainiao_apk a ON c.apk_id = a.id
                               WHERE c.apk_id = :apk_id AND a.user_id = :uid LIMIT 1");
        $stmt->execute([':apk_id' => $apkId, ':uid' => $userId]);
    }

    $configId = $stmt->fetchColumn();
    if (!$configId) {
        throw new Exception('配置不存在或无权限');
    }

    $stmt = $pdo->prepare("
        SELECT k.id, k.popup_id, p.popup_id AS popup_code, p.description, k.created_at
        FROM cainiao_popup_kill_type k
        JOIN cainiao_popup_type p ON k.popup_id = p.id
        WHERE k.config_id = :cid
        ORDER BY k.id DESC
    ");
    $stmt->execute([':cid' => $configId]);
    Auth::reset_redis($apkId);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function addKill(PDO $pdo, array $input) {
    if (empty($input['apk_id']) || empty($input['popup_id'])) {
        throw new Exception('缺少参数');
    }

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $apkId = (int)$input['apk_id'];
    $isAdmin = $user['role'] === 'admin';
    $configId = getConfigIdByApk($pdo, $userId, $apkId, $isAdmin);
    if (!$configId) throw new Exception('配置不存在或无权限');
    //throw new Exception('功能暂时不可用');
    //throw new Exception('测试:' . $configId .'|'. $input['popup_id']);
    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_kill_type (config_id, popup_id, created_at) VALUES (:cid, :popup_id, NOW())");
    $stmt->execute([
        ':cid' => $configId,
        ':popup_id' => $input['popup_id']
    ]);

    Auth::afterConfigChange($pdo, $apkId);
    return ['message' => '添加成功'];
}

function deleteKill(PDO $pdo, array $input) {
    if (empty($input['id'])) {
        throw new Exception('缺少记录ID');
    }

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    // 先查出 config_id 用于后续桶推送
    $lookupStmt = $pdo->prepare("SELECT k.config_id FROM cainiao_popup_kill_type k WHERE k.id = :id LIMIT 1");
    $lookupStmt->execute([':id' => $input['id']]);
    $configId = (int)$lookupStmt->fetchColumn();

    if (!$isAdmin) {
        $stmt = $pdo->prepare("
            SELECT k.id
            FROM cainiao_popup_kill_type k
            JOIN cainiao_apk_config c ON k.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE k.id = :id AND a.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足');
        }
    } else {
        if (!$configId) {
            throw new Exception('记录不存在');
        }
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_popup_kill_type WHERE id = :id");
    $stmt->execute([':id' => $input['id']]);

    // 推送配置到桶
    if ($configId > 0) {
        $apkId = Auth::getApkIdByConfigId($pdo, $configId);
        if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);
    }

    return ['message' => '删除成功'];
}


// 工具方法：通过 apk_id 获取 config_id
function getConfigIdByApk($pdo, $userId, $apkId, $isAdmin = false) {
    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_apk_config WHERE apk_id = :apk_id LIMIT 1");
        $stmt->execute([':apk_id' => $apkId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT c.id FROM cainiao_apk_config c
            JOIN cainiao_apk a ON a.id = c.apk_id
            WHERE c.apk_id = :apk_id AND a.user_id = :user_id LIMIT 1
        ");
        $stmt->execute([':apk_id' => $apkId, ':user_id' => $userId]);
    }

    return $stmt->fetchColumn();
}

