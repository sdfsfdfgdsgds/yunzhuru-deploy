<?php
//关键词拦截
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


// 关键词管理
function getKeywords(PDO $pdo, array $input) {
    if (empty($input['apk_id'])) throw new Exception('缺少应用ID');
    $user = Auth::check($pdo);
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $configId = getConfigIdByApk($pdo, $user['id'], $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('权限不足');
    Auth::reset_redis($input['apk_id']);
    $stmt = $pdo->prepare("SELECT id, keyword, type, new_keyword, clickAction, clickText, created_at FROM cainiao_keyword WHERE config_id = ?");
    $stmt->execute([$configId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function addKeyword(PDO $pdo, array $input) {
    if (empty($input['apk_id']) || empty($input['keyword'])) {
        throw new Exception('参数错误');
    }

    $user = Auth::check($pdo);
    $isAdmin = ($user['role'] ?? '') === 'admin';

    // 管理员不验证用户ID
    $configId = getConfigIdByApk($pdo, $user['id'], $input['apk_id'], $isAdmin);
    if (!$configId) {
        throw new Exception('权限不足');
    }

    $type = isset($input['type']) ? (int)$input['type'] : 0;
    $newKeyword = $input['new_keyword'] ?? '';
    $clickAction = isset($input['clickAction']) ? (int)$input['clickAction'] : 0;
    $clickText = $input['clickText'] ?? '';

    $stmt = $pdo->prepare("INSERT INTO cainiao_keyword (config_id, keyword, type, new_keyword, clickAction, clickText, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$configId, $input['keyword'], $type, $newKeyword, $clickAction, $clickText]);

    return ['message' => '添加成功'];
}




function editKeyword(PDO $pdo, array $input) {
    if (empty($input['id']) || empty($input['keyword'])) {
        throw new Exception('参数错误');
    }

    $user = Auth::check($pdo);
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        // 仅普通用户校验权限
        $stmt = $pdo->prepare("SELECT k.id FROM cainiao_keyword k
                               JOIN cainiao_apk_config c ON k.config_id = c.id
                               JOIN cainiao_apk a ON c.apk_id = a.id
                               WHERE k.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['id'], ':uid' => $user['id']]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足');
        }
    }

    $stmt = $pdo->prepare("UPDATE cainiao_keyword 
                           SET keyword = ?, type = ?, new_keyword = ?, clickaction = ?, clicktext = ? 
                           WHERE id = ?");
    $stmt->execute([
        $input['keyword'],
        isset($input['type']) ? (int)$input['type'] : 0,
        $input['new_keyword'] ?? '',
        isset($input['clickAction']) ? (int)$input['clickAction'] : 0,
        $input['clickText'] ?? '',
        (int)$input['id']
    ]);

    return ['message' => '更新成功'];
}

function deleteKeyword(PDO $pdo, array $input) {
    if (empty($input['id'])) {
        throw new Exception('缺少ID');
    }

    $user = Auth::check($pdo);
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        // 仅普通用户校验权限
        $stmt = $pdo->prepare("SELECT k.id FROM cainiao_keyword k
                               JOIN cainiao_apk_config c ON k.config_id = c.id
                               JOIN cainiao_apk a ON c.apk_id = a.id
                               WHERE k.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['id'], ':uid' => $user['id']]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足');
        }
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_keyword WHERE id = ?");
    $stmt->execute([$input['id']]);

    return ['message' => '删除成功'];
}


// 拦截弹窗类型管理
function getBlockedTypes(PDO $pdo, array $input) {
    if (empty($input['apk_id'])) throw new Exception('缺少应用ID');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('权限不足');
    Auth::reset_redis($input['apk_id']);
    $stmt = $pdo->prepare("
        SELECT b.id, p.popup_id, p.description, b.created_at, p.id AS type_id
        FROM cainiao_popup_block_type b
        JOIN cainiao_popup_type p ON b.popup_id = p.id
        WHERE b.config_id = ?
    ");
    $stmt->execute([$configId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function addBlockedType(PDO $pdo, array $input) {
    if (empty($input['apk_id']) || empty($input['popup_id'])) throw new Exception('参数错误');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('权限不足');

    // 检查是否已有相同记录
    $check = $pdo->prepare("SELECT 1 FROM cainiao_popup_block_type WHERE config_id = ? AND popup_id = ?");
    $check->execute([$configId, $input['popup_id']]);
    if ($check->fetch()) throw new Exception('已存在相同记录');

    // 插入新记录
    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_block_type (config_id, popup_id, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$configId, $input['popup_id']]);

    return ['message' => '添加成功'];
}



function editBlockedType(PDO $pdo, array $input) {
    if (empty($input['id']) || empty($input['popup_id'])) throw new Exception('参数错误');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if ($isAdmin) {
        // 管理员直接查询记录
        $stmt = $pdo->prepare("SELECT config_id, popup_id FROM cainiao_popup_block_type WHERE id = :id");
        $stmt->execute([':id' => $input['id']]);
    } else {
        // 普通用户需校验权限
        $stmt = $pdo->prepare("SELECT b.config_id, b.popup_id FROM cainiao_popup_block_type b
                               JOIN cainiao_apk_config c ON b.config_id = c.id
                               JOIN cainiao_apk a ON c.apk_id = a.id
                               WHERE b.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('权限不足');

    $configId = $row['config_id'];
    $currentPopupId = $row['popup_id'];

    if ((int)$currentPopupId === (int)$input['popup_id']) {
        return ['message' => '无变化，未更新'];
    }

    // 检查重复
    $check = $pdo->prepare("SELECT id FROM cainiao_popup_block_type
                            WHERE config_id = :cfg AND popup_id = :pid AND id != :id");
    $check->execute([
        ':cfg' => $configId,
        ':pid' => $input['popup_id'],
        ':id' => $input['id']
    ]);
    if ($check->fetch()) throw new Exception('该弹窗类型已存在，不能重复');

    // 执行更新
    $stmt = $pdo->prepare("UPDATE cainiao_popup_block_type SET popup_id = ? WHERE id = ?");
    $stmt->execute([$input['popup_id'], $input['id']]);

    return ['message' => '更新成功'];
}




function deleteBlockedType(PDO $pdo, array $input) {
    if (empty($input['id'])) throw new Exception('缺少ID');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        // 普通用户权限校验
        $stmt = $pdo->prepare("SELECT b.id FROM cainiao_popup_block_type b
                               JOIN cainiao_apk_config c ON b.config_id = c.id
                               JOIN cainiao_apk a ON c.apk_id = a.id
                               WHERE b.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) throw new Exception('权限不足');
    }

    // 执行删除
    $stmt = $pdo->prepare("DELETE FROM cainiao_popup_block_type WHERE id = ?");
    $stmt->execute([$input['id']]);

    return ['message' => '删除成功'];
}
