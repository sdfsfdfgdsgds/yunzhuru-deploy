<?php
//包名检测
function getList(PDO $pdo, array $input) {
    if (empty($input['apk_id'])) throw new Exception('缺少应用ID');

    $user    = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    // 获取配置ID（管理员不验证user_id）
    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('权限不足或配置不存在');
    Auth::reset_redis($input['apk_id']);
    $stmt = $pdo->prepare("SELECT * FROM cainiao_sensitive_app WHERE config_id = :config_id ORDER BY id DESC");
    $stmt->execute([':config_id' => $configId]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $data;
}

function add(PDO $pdo, array $input) {
    if (empty($input['apk_id']) || empty($input['package_name'])) {
        throw new Exception('缺少必要参数');
    }

    $user    = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) {
        throw new Exception('权限不足或配置不存在');
    }

    $detectType = isset($input['detect_type']) ? (int)$input['detect_type'] : 0;
    $actionType = isset($input['action_type']) ? (int)$input['action_type'] : 0;
    $tipText    = isset($input['tip_text']) ? trim($input['tip_text']) : '';
    $remark     = isset($input['remark']) ? trim($input['remark']) : '';

    $stmt = $pdo->prepare("
        INSERT INTO cainiao_sensitive_app (
            config_id,
            package_name,
            detect_type,
            action_type,
            tip_text,
            remark,
            created_at
        ) VALUES (
            :config_id,
            :package_name,
            :detect_type,
            :action_type,
            :tip_text,
            :remark,
            NOW()
        )
    ");

    $stmt->execute([
        ':config_id'     => $configId,
        ':package_name'  => $input['package_name'],
        ':detect_type'   => $detectType,
        ':action_type'   => $actionType,
        ':tip_text'      => $tipText,
        ':remark'        => $remark
    ]);

    return ['message' => '添加成功'];
}

function edit(PDO $pdo, array $input) {
    if (empty($input['id']) || empty($input['package_name'])) {
        throw new Exception('缺少参数');
    }

    $user    = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    // 校验权限（管理员不验证user_id）
    if (!$isAdmin) {
        $stmt = $pdo->prepare("
            SELECT a.id
            FROM cainiao_sensitive_app a
            JOIN cainiao_apk_config c ON a.config_id = c.id
            JOIN cainiao_apk p ON c.apk_id = p.id
            WHERE a.id = :id AND p.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足');
        }
    }

    $detectType = isset($input['detect_type']) ? (int)$input['detect_type'] : 0;
    $actionType = isset($input['action_type']) ? (int)$input['action_type'] : 0;
    $tipText    = isset($input['tip_text']) ? trim($input['tip_text']) : '';
    $remark     = isset($input['remark']) ? trim($input['remark']) : '';

    $stmt = $pdo->prepare("
        UPDATE cainiao_sensitive_app 
        SET 
            package_name = :package_name,
            remark = :remark,
            detect_type = :detect_type,
            action_type = :action_type,
            tip_text = :tip_text
        WHERE id = :id
    ");

    $stmt->execute([
        ':package_name' => $input['package_name'],
        ':remark'       => $remark,
        ':detect_type'  => $detectType,
        ':action_type'  => $actionType,
        ':tip_text'     => $tipText,
        ':id'           => $input['id']
    ]);

    return ['message' => '更新成功'];
}

function delete(PDO $pdo, array $input) {
    if (empty($input['id'])) throw new Exception('缺少ID');

    $user    = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        $stmt = $pdo->prepare("
            SELECT a.id
            FROM cainiao_sensitive_app a
            JOIN cainiao_apk_config c ON a.config_id = c.id
            JOIN cainiao_apk p ON c.apk_id = p.id
            WHERE a.id = :id AND p.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) throw new Exception('权限不足');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_sensitive_app WHERE id = :id");
    $stmt->execute([':id' => $input['id']]);

    return ['message' => '删除成功'];
}

// 公共方法：根据 apk_id 获取 config_id（管理员不验证user_id）
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
