<?php

function getMyApps(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $stmt = $pdo->prepare("SELECT id, name, version, package, size, upload_time FROM cainiao_apk WHERE user_id = :uid ORDER BY id DESC");
    $stmt->execute([':uid' => $userId]);
    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $apps;
}

function getAppConfig(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);

    if (empty($input['apk_id'])) {
        throw new Exception('缺少应用 ID');
    }

    $apkId = (int)$input['apk_id'];

    // 验证该 APK 是否属于当前用户
    $stmt = $pdo->prepare("SELECT id FROM cainiao_apk WHERE id = :id AND user_id = :uid LIMIT 1");
    $stmt->execute([':id' => $apkId, ':uid' => $user['id']]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$app) {
        throw new Exception('应用不存在或无权限访问');
    }

    // 查询配置
    $stmt = $pdo->prepare("SELECT * FROM cainiao_apk_config WHERE apk_id = :apk_id LIMIT 1");
    $stmt->execute([':apk_id' => $apkId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        throw new Exception('未找到配置记录');
    }

    return $config;
}



//分页获取应用列表配置
function getMyAppsWithConfig(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $isAdmin = $user['role'] == 'admin';
    $userId = (int)$user['id'];

    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    // 管理员可以查看全部，普通用户限制 user_id
    if (!$isAdmin) {
        $where[] = 'user_id = :uid';
        $params[':uid'] = $userId;
    }

    // 按用户ID查
    if (!empty($input['user_id']) && $isAdmin) {
        $where[] = 'user_id = :filter_uid';
        $params[':filter_uid'] = intval($input['user_id']);
    }

    // 按应用ID查
    if (!empty($input['apk_id'])) {
        $where[] = 'id = :apk_id';
        $params[':apk_id'] = intval($input['apk_id']);
    }

    // 按应用名称模糊查
    if (!empty($input['name'])) {
        $where[] = 'name LIKE :name';
        $params[':name'] = '%' . $input['name'] . '%';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // 获取总数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_apk $whereSql");
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());
    $pages = ceil($total / $limit);

    // 获取当前页数据
    $stmt = $pdo->prepare("
        SELECT id, name, version, package, size, upload_time, user_id
        FROM cainiao_apk
        $whereSql
        ORDER BY id DESC
        LIMIT $offset, $limit
    ");
    $stmt->execute($params);
    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 获取配置
    $apkIds = array_column($apps, 'id');
    $configs = [];

    if (!empty($apkIds)) {
        $placeholders = implode(',', array_fill(0, count($apkIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM cainiao_apk_config WHERE apk_id IN ($placeholders)");
        $stmt->execute($apkIds);
        $configRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($configRows as $cfg) {
            $configs[$cfg['apk_id']] = $cfg;
        }
    }

    // 合并配置
    foreach ($apps as &$app) {
        $app['config'] = $configs[$app['id']] ?? null;
    }

    return [
        'list' => $apps,
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => $pages
    ];
}




function updateAppConfig(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $isAdmin = $user['role'] == 'admin';

    // 参数验证
    if (empty($input['id']) || empty($input['field']) || !isset($input['value'])) {
        throw new Exception('参数不完整');
    }

    $configId = (int)$input['id'];
    $field = trim($input['field']);
    $value = $input['value'];

    // 字段白名单与类型要求
    $allowedFields = [
        'debug', 'ban_Root', 'offline', 'websocket', 'ban_Xposed', 'ban_Emulator', 'ban_VirtualApp',
        'ban_DualApp', 'black_package', 'enable_popup_kill_all', 'enable_popup_keywords',
        'enable_sp_put', 'enable_sp_get', 'enable_sp', 'enablePopups', 'enablehtmlPopups', 'enabledex', 'enableHook',
        'enableImagePopups', 'enableMessagePopups', 'enableinputPopups', 'screen_priority'
    ];

    if (!in_array($field, $allowedFields, true)) {
        throw new Exception('非法字段');
    }

    // 值验证：只能是布尔值或 0/1
    if (!in_array($value, [0, 1, '0', '1', true, false], true)) {
        throw new Exception('值不合法，仅允许布尔值');
    }
    
    $now = date('Y-m-d H:i:s');
    $isVip = isset($user['vip_expire_time']) && $user['vip_expire_time'] > $now;
    $push = (int)Auth::getSetting($pdo, "push", false);
    
    //当开启了仅会员可推送的时候且当前用户不是会员的时候,则需要验证websocket是否为true，如果为true则不允许
    if ($push && !$isVip) {
        if ($field === 'websocket' && (int)(bool)$value === 1) {
            throw new Exception('此功能为会员功能');
        }
    }

    $value = (int)(bool)$value;

    // 如果不是管理员，则验证该配置是否属于当前用户
    if (!$isAdmin) {
        $stmt = $pdo->prepare("
            SELECT c.id FROM cainiao_apk_config c
            INNER JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE c.id = :cid AND a.user_id = :uid
        ");
        $stmt->execute([':cid' => $configId, ':uid' => $user['id']]);
        if (!$stmt->fetch()) {
            throw new Exception('配置不存在或无权限');
        }
    }

    // 执行更新
    $sql = "UPDATE cainiao_apk_config SET `$field` = :val WHERE id = :id";
    $update = $pdo->prepare($sql);
    $update->execute([':val' => $value, ':id' => $configId]);
    
    $stmt = $pdo->prepare("SELECT apk_id FROM cainiao_apk_config WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $configId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    
    Auth::reset_redis($app['apk_id']);

    return ['message' => '更新成功'];
}















