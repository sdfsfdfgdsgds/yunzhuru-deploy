<?php
// remote_dex.php（管理员不验证user_id，普通用户正常校验）

// 公共方法：根据 apk_id 获取 config_id（管理员不校验 user_id）
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

// 列表查询（管理员不校验 user_id）
function getList(PDO $pdo, array $input) {
    if (empty($input['apk_id'])) throw new Exception('缺少应用ID');

    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('权限不足或配置不存在');
    Auth::reset_redis($input['apk_id']);
    $stmt = $pdo->prepare("SELECT id, url, class_name, method_name, enabled, remark, created_at 
                           FROM cainiao_remote_dex 
                           WHERE config_id = :config_id 
                           ORDER BY id DESC");
    $stmt->execute([':config_id' => $configId]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 将 enabled 转为布尔值（前端更易用）
    foreach ($data as &$item) {
        $item['enabled'] = (bool)$item['enabled'];
    }
    Auth::reset_redis($input['apk_id']);
    return $data;
}

// 新增（管理员不校验 user_id）
function add(PDO $pdo, array $input) {
    if (empty($input['apk_id']) || empty($input['url']) || empty($input['class_name']) || empty($input['method_name'])) {
        throw new Exception('缺少必要参数');
    }
    
    $url        = trim($input['url']);
    $className  = trim($input['class_name']);
    $methodName = trim($input['method_name']);
    /* ===========================
       URL 合法性校验
       =========================== */

    if (!preg_match('#^https?://#i', $url)) {
        throw new Exception('URL格式不合法(错误代码01)');
    }

    if (substr_count($url, '/') < 3) {
        throw new Exception('URL格式不合法(错误代码02)');
    }

    if (substr_count($url, '.') < 1) {
        throw new Exception('URL格式不合法(错误代码03)');
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception('URL格式不合法(错误代码04)');
    }
    
    /* ===========================
       类名合法性校验
       =========================== */

    if (strpos($className, '.') === false) {
        throw new Exception('类名必须包含包名');
    }

    // Java全路径类名匹配
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)+$/', $className)) {
        throw new Exception('类名格式不合法');
    }
    
    /* ===========================
       方法名合法性校验
       =========================== */

    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $methodName)) {
        throw new Exception('方法名格式不合法');
    }
    
    
    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('权限不足或配置不存在');

    $stmt = $pdo->prepare("INSERT INTO cainiao_remote_dex 
        (config_id, url, class_name, method_name, enabled, remark, created_at) 
        VALUES (:config_id, :url, :class_name, :method_name, :enabled, :remark, NOW())");
    $stmt->execute([
        ':config_id'   => $configId,
        ':url'         => $input['url'],
        ':class_name'  => $input['class_name'],
        ':method_name' => $input['method_name'],
        ':enabled'     => empty($input['enabled']) ? 0 : 1,
        ':remark'      => $input['remark'] ?? ''
    ]);
    
    return ['message' => '添加成功'];
}

// 编辑（管理员不校验 user_id）
function edit(PDO $pdo, array $input) {
    if (empty($input['id']) || empty($input['url']) || empty($input['class_name']) || empty($input['method_name'])) {
        throw new Exception('缺少参数');
    }
    
    
    $url        = trim($input['url']);
    $className  = trim($input['class_name']);
    $methodName = trim($input['method_name']);
    /* ===========================
       URL 合法性校验
       =========================== */
    
    if (!preg_match('#^https?://#i', $url)) {
        throw new Exception('DEX文件直链不合法(错误代码01)');
    }

    if (substr_count($url, '/') < 3) {
        throw new Exception('DEX文件直链不合法(错误代码02)');
    }

    if (substr_count($url, '.') < 1) {
        throw new Exception('DEX文件直链不合法(错误代码03)');
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception('DEX文件直链不合法(错误代码04)');
    }
    
    /* ===========================
       类名合法性校验
       =========================== */

    if (strpos($className, '.') === false) {
        throw new Exception('类名必须包含包名');
    }

    // Java全路径类名匹配
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)+$/', $className)) {
        throw new Exception('类名格式不合法');
    }
    
    /* ===========================
       方法名合法性校验
       =========================== */

    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $methodName)) {
        throw new Exception('方法名格式不合法');
    }
    
    
    
    
    
    
    
    
    
    
    
    
    
    

    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    if (!$isAdmin) {
        // 非管理员校验归属
        $stmt = $pdo->prepare("
            SELECT a.id
            FROM cainiao_remote_dex a
            JOIN cainiao_apk_config c ON a.config_id = c.id
            JOIN cainiao_apk p ON c.apk_id = p.id
            WHERE a.id = :id AND p.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) throw new Exception('权限不足');
    }

    $stmt = $pdo->prepare("UPDATE cainiao_remote_dex 
        SET url = :url, class_name = :class_name, method_name = :method_name, enabled = :enabled, remark = :remark 
        WHERE id = :id");
    $stmt->execute([
        ':url'         => $input['url'],
        ':class_name'  => $input['class_name'],
        ':method_name' => $input['method_name'],
        ':enabled'     => empty($input['enabled']) ? 0 : 1,
        ':remark'      => $input['remark'] ?? '',
        ':id'          => $input['id']
    ]);

    return ['message' => '更新成功'];
}

// 删除（管理员不校验 user_id）
function delete(PDO $pdo, array $input) {
    if (empty($input['id'])) throw new Exception('缺少ID');

    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    if (!$isAdmin) {
        // 非管理员校验归属
        $stmt = $pdo->prepare("
            SELECT a.id
            FROM cainiao_remote_dex a
            JOIN cainiao_apk_config c ON a.config_id = c.id
            JOIN cainiao_apk p ON c.apk_id = p.id
            WHERE a.id = :id AND p.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) throw new Exception('权限不足');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_remote_dex WHERE id = :id");
    $stmt->execute([':id' => $input['id']]);

    return ['message' => '删除成功'];
}
