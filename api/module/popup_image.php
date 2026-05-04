<?php
function getList2(PDO $pdo, array $input)
{
    // 获取当前登录用户
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $tablePrefix = 'cainiao_';
    $apkTable = $tablePrefix . 'apk';
    $configTable = $tablePrefix . 'apk_config';
    $popupTable = $tablePrefix . 'popup_image';

    // 参数处理
    $apkId = isset($input['apk_id']) ? intval($input['apk_id']) : 0;
    $popupType = isset($input['popup_type']) ? trim($input['popup_type']) : '';
    $enable = isset($input['enable']) ? intval($input['enable']) : null;
    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    // 查找当前用户的 apk 配置ID
    $stmt = $pdo->prepare("SELECT c.id FROM `$apkTable` a JOIN `$configTable` c ON a.id = c.apk_id WHERE a.id = :apk_id AND a.user_id = :uid");
    $stmt->execute([':apk_id' => $apkId, ':uid' => $userId]);
    $configRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$configRow) {
        throw new Exception('未找到对应配置');
    }
    $configId = $configRow['id'];

    // 构建查询条件
    $where = ['config_id = :config_id'];
    $params = [':config_id' => $configId];

    if ($popupType !== '') {
        $where[] = 'popup_type = :popup_type';
        $params[':popup_type'] = $popupType;
    }

    if ($enable !== null) {
        $where[] = 'enable = :enable';
        $params[':enable'] = $enable;
    }

    $whereSql = implode(' AND ', $where);

    // 获取总数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$popupTable` WHERE $whereSql");
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());
    $pages = ceil($total / $limit);

    // 获取数据
    $stmt = $pdo->prepare("SELECT id, config_id, popup_type, remark, enable, imageUrl, clickAction, clickText, callback, countdown, canSkip, autoClose, created_at FROM `$popupTable` WHERE $whereSql ORDER BY id DESC LIMIT $offset, $limit");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
            'list' => $list,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => $pages
        ];
}

function getList(PDO $pdo, array $input)
{
    // 获取当前登录用户
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    $tablePrefix = 'cainiao_';
    $apkTable = $tablePrefix . 'apk';
    $configTable = $tablePrefix . 'apk_config';
    $popupTable = $tablePrefix . 'popup_image';

    // 参数处理
    $apkId = isset($input['apk_id']) ? intval($input['apk_id']) : 0;
    $popupType = isset($input['popup_type']) ? trim($input['popup_type']) : '';
    $enable = isset($input['enable']) ? intval($input['enable']) : null;
    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    $configIdList = [];
    $configApkMap = [];

    if ($apkId > 0) {
        // 指定 apk_id 时，只查该配置
        if ($isAdmin) {
            $stmt = $pdo->prepare("SELECT c.id, a.id AS apk_id, a.name, a.package FROM `$apkTable` a JOIN `$configTable` c ON a.id = c.apk_id WHERE a.id = :apk_id");
            $stmt->execute([':apk_id' => $apkId]);
        } else {
            $stmt = $pdo->prepare("SELECT c.id, a.id AS apk_id, a.name, a.package FROM `$apkTable` a JOIN `$configTable` c ON a.id = c.apk_id WHERE a.id = :apk_id AND a.user_id = :uid");
            $stmt->execute([':apk_id' => $apkId, ':uid' => $userId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('未找到对应配置');
        }
        $configIdList[] = $row['id'];
        $configApkMap[$row['id']] = [
            'name' => $row['name'],
            'package' => $row['package'],
            'apk_id' => $row['apk_id']
        ];
        $where[] = 'config_id = ?';
        $params[] = $row['id'];
        Auth::reset_redis($apkId);
    } else {
        // 不指定 apk_id，查用户所有配置
        if ($isAdmin) {
            $stmt = $pdo->prepare("SELECT c.id, a.id AS apk_id, a.name, a.package FROM `$apkTable` a JOIN `$configTable` c ON a.id = c.apk_id");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("SELECT c.id, a.id AS apk_id, a.name, a.package FROM `$apkTable` a JOIN `$configTable` c ON a.id = c.apk_id WHERE a.user_id = :uid");
            $stmt->execute([':uid' => $userId]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return ['list' => [], 'page' => $page, 'limit' => $limit, 'total' => 0, 'pages' => 0];
        }
        foreach ($rows as $row) {
            $configIdList[] = $row['id'];
            $configApkMap[$row['id']] = [
                'name' => $row['name'],
                'package' => $row['package'],
                'apk_id' => $row['apk_id']
            ];
        }
        $inPlaceholders = implode(',', array_fill(0, count($configIdList), '?'));
        $where[] = 'config_id IN (' . $inPlaceholders . ')';
        $params = array_merge($params, $configIdList);
    }

    if ($popupType !== '') {
        $where[] = 'popup_type = ?';
        $params[] = $popupType;
    }

    if ($enable !== null) {
        $where[] = 'enable = ?';
        $params[] = $enable;
    }

    $whereSql = $where ? implode(' AND ', $where) : '1';

    // 获取总数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$popupTable` WHERE $whereSql");
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());
    $pages = ceil($total / $limit);

    // 获取数据
    $stmt = $pdo->prepare("SELECT id, config_id, popup_type, remark, enable, imageUrl, clickAction, clickText, callback, countdown, canSkip, autoClose, created_at, `lock` FROM `$popupTable` WHERE $whereSql ORDER BY id DESC LIMIT $offset, $limit");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 添加 apk_info 和 apk_id 字段
    foreach ($list as &$item) {
        $cid = $item['config_id'];
        if (isset($configApkMap[$cid])) {
            $item['apk_info'] = $configApkMap[$cid]['name'] . '|' . $configApkMap[$cid]['package'];
            $item['apk_id'] = $configApkMap[$cid]['apk_id'];
        } else {
            $item['apk_info'] = '';
            $item['apk_id'] = 0;
        }
    }

    return [
        'list' => $list,
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => $pages
    ];
}

// 添加图片弹窗
function addPopup(PDO $pdo, array $input)
{
    // 获取当前用户信息
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';
    // 检查必要参数
    $required = ['apk_id', 'popup_type', 'remark', 'imageUrl'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("缺少参数：$field");
        }
    }
    
    // 注释：必须以 http:// 或 https:// 开头
    if (!preg_match('/^https?:\/\//i', $input['imageUrl'])) {
        throw new Exception("图片链接不正确");
    }

    // 根据 apk_id 和当前用户ID 获取配置ID
    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) {
        throw new Exception("配置不存在");
    }

    // 插入记录
    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_image (
        config_id, popup_type, remark, enable, imageUrl,
        clickAction, clickText, callback, countdown, canSkip, autoClose, created_at, `lock`
    ) VALUES (
        :config_id, :popup_type, :remark, :enable, :imageUrl,
        :clickAction, :clickText, :callback, :countdown, :canSkip, :autoClose, NOW(), :lock
    )");

    $stmt->execute([
        ':config_id'   => $configId,
        ':popup_type'  => $input['popup_type'],
        ':remark'      => $input['remark'],
        ':enable'      => !empty($input['enable']) ? 1 : 0,
        ':imageUrl'    => $input['imageUrl'],
        ':clickAction' => intval($input['clickAction'] ?? 0),
        ':clickText'   => $input['clickText'] ?? '',
        ':callback'    => $input['callback'] ?? '',
        ':countdown'   => intval($input['countdown'] ?? 3),
        ':canSkip'     => !empty($input['canSkip']) ? 1 : 0,
        ':autoClose'   => !empty($input['autoClose']) ? 1 : 0,
        ':lock'   => !empty($input['lock']) ? 1 : 0,
    ]);

    Auth::afterConfigChange($pdo, (int)$input['apk_id']);
    return ['message' => '创建成功'];
}


// 编辑弹窗
function editPopup(PDO $pdo, array $input)
{
    // 获取当前登录用户
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['id'])) {
        throw new Exception('缺少弹窗ID');
    }

    // 可更新字段
    $fields = [
        'popup_type', 'remark', 'enable', 'imageUrl',
        'clickAction', 'clickText', 'callback',
        'countdown', 'canSkip', 'autoClose', 'lock'
    ];

    $updates = [];
    $params = [':id' => $input['id']];
    
    foreach ($fields as $field) {
        if (isset($input[$field])) {
            // 注释：必须以 http:// 或 https:// 开头
            if ($field === 'imageUrl') {
                if (!preg_match('/^https?:\/\//i', $input[$field])) {
                    throw new Exception("图片链接不正确");
                }
            }
            
            // 对布尔值字段做转换
            if (in_array($field, ['enable', 'canSkip', 'autoClose', 'lock'])) {
                $params[":$field"] = !empty($input[$field]) ? 1 : 0;
            } else {
                $params[":$field"] = $input[$field];
            }
            $updates[] = "`$field` = :$field";
        }
    }

    if (empty($updates)) {
        throw new Exception('没有可更新字段');
    }

    // 非管理员需验证是否拥有该弹窗
    if (!$isAdmin) {
        $check = $pdo->prepare("
            SELECT i.id
            FROM cainiao_popup_image i
            JOIN cainiao_apk_config c ON i.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE i.id = :id AND a.user_id = :uid
        ");
        $check->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$check->fetch()) {
            throw new Exception('权限不足或弹窗不存在');
        }
    } else {
        // 管理员也需确认记录是否存在
        $check = $pdo->prepare("SELECT id FROM cainiao_popup_image WHERE id = :id");
        $check->execute([':id' => $input['id']]);
        if (!$check->fetch()) {
            throw new Exception('弹窗不存在');
        }
    }

    // 执行更新
    $sql = "UPDATE cainiao_popup_image SET " . implode(',', $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT config_id FROM cainiao_popup_image WHERE id = :id LIMIT 1");
    $cfgStmt->execute([':id' => $input['id']]);
    $cfgId = (int)$cfgStmt->fetchColumn();
    if ($cfgId > 0) {
        $apkId = Auth::getApkIdByConfigId($pdo, $cfgId);
        if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);
    }

    return ['message' => '更新成功'];
}



//批量改弹窗
function batchUpdate(PDO $pdo, array $input)
{
    // 获取当前登录用户
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['ids']) || !is_array($input['ids'])) {
        throw new Exception('缺少弹窗ID列表');
    }

    $idList = array_filter(array_map('intval', $input['ids']));
    if (empty($idList)) {
        throw new Exception('弹窗ID列表无效');
    }

    // 可更新字段
    $fields = [
        'popup_type', 'remark', 'enable', 'imageUrl',
        'clickAction', 'clickText', 'callback',
        'countdown', 'canSkip', 'autoClose'
    ];

    $updates = [];
    $params = [];

    foreach ($fields as $field) {
        if (isset($input[$field])) {
            // 对布尔字段处理
            if (in_array($field, ['enable', 'canSkip', 'autoClose'])) {
                $params[":$field"] = !empty($input[$field]) ? 1 : 0;
            } else {
                $params[":$field"] = $input[$field];
            }
            $updates[] = "`$field` = :$field";
        }
    }

    if (empty($updates)) {
        throw new Exception('没有可更新字段');
    }

    // 权限校验：只允许更新当前用户所属的弹窗记录
    $inPlaceholders = implode(',', array_fill(0, count($idList), '?'));
    $checkSql = "
        SELECT i.id
        FROM cainiao_popup_image i
        JOIN cainiao_apk_config c ON i.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE i.id IN ($inPlaceholders) AND a.user_id = ?
    ";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([...$idList, $userId]);
    $rows = $checkStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($rows)) {
        throw new Exception('没有权限更新这些弹窗');
    }

    // 构建更新语句
    $updateSql = "UPDATE cainiao_popup_image SET " . implode(',', $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($updateSql);

    // 依次更新每条数据
    foreach ($rows as $popupId) {
        $params[':id'] = $popupId;
        $stmt->execute($params);
    }

    // 推送受影响的应用配置到桶
    $cfgStmt = $pdo->prepare("SELECT DISTINCT c.apk_id FROM cainiao_popup_image i JOIN cainiao_apk_config c ON i.config_id = c.id WHERE i.id IN ($inPlaceholders)");
    $cfgStmt->execute($idList);
    foreach ($cfgStmt->fetchAll(PDO::FETCH_COLUMN) as $affectedApkId) {
        Auth::afterConfigChange($pdo, (int)$affectedApkId);
    }

    return ['message' => '批量更新成功'];
}


// 删除弹窗
function deletePopup(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['id'])) {
        throw new Exception('缺少弹窗ID');
    }

    // 删除前查出 config_id 用于桶推送
    $cfgStmt = $pdo->prepare("SELECT config_id FROM cainiao_popup_image WHERE id = :id LIMIT 1");
    $cfgStmt->execute([':id' => $input['id']]);
    $configId = (int)$cfgStmt->fetchColumn();

    if (!$isAdmin) {
        // 非管理员时进行权限校验
        $stmt = $pdo->prepare("
            SELECT i.id FROM cainiao_popup_image i
            JOIN cainiao_apk_config c ON i.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE i.id = :id AND a.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('无权删除该弹窗');
        }
    }

    // 删除相关记录
    $pdo->prepare("DELETE FROM cainiao_popup_image_whitelist WHERE popup_id = ?")->execute([$input['id']]);
    $pdo->prepare("DELETE FROM cainiao_popup_fullscreen_blacklist WHERE popup_id = ?")->execute([$input['id']]);
    $pdo->prepare("DELETE FROM cainiao_popup_image WHERE id = ?")->execute([$input['id']]);

    // 推送配置到桶
    if ($configId > 0) {
        $apkId = Auth::getApkIdByConfigId($pdo, $configId);
        if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);
    }

    return ['message' => '删除成功'];
}



// 添加白名单
function addWhitelist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['popup_id']) || empty($input['class_name'])) {
        throw new Exception('缺少必要参数');
    }

    // 权限校验
    $stmt = $pdo->prepare("
        SELECT i.id FROM cainiao_popup_image i
        JOIN cainiao_apk_config c ON i.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE i.id = :id AND a.user_id = :uid
    ");
    $stmt->execute([':id' => $input['popup_id'], ':uid' => $userId]);
    if (!$stmt->fetch()) {
        throw new Exception('无权操作该弹窗');
    }

    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_image_whitelist (popup_id, class_name, created_at, remark) VALUES (?, ?, NOW(), ?)");
    $stmt->execute([$input['popup_id'], $input['class_name'], $input['remark']]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_image i JOIN cainiao_apk_config c ON i.config_id = c.id WHERE i.id = ? LIMIT 1");
    $cfgStmt->execute([$input['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '添加成功'];
}


// 删除白名单
function deleteWhitelist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['id'])) {
        throw new Exception('缺少白名单ID');
    }

    $stmt = $pdo->prepare("
        SELECT w.id FROM cainiao_popup_image_whitelist w
        JOIN cainiao_popup_image i ON w.popup_id = i.id
        JOIN cainiao_apk_config c ON i.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE w.id = :id AND a.user_id = :uid
    ");
    $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
    if (!$stmt->fetch()) {
        throw new Exception('无权操作该白名单');
    }

    // 删除前查出 apk_id 用于桶推送
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_image_whitelist w JOIN cainiao_popup_image i ON w.popup_id = i.id JOIN cainiao_apk_config c ON i.config_id = c.id WHERE w.id = ? LIMIT 1");
    $cfgStmt->execute([$input['id']]);
    $apkId = (int)$cfgStmt->fetchColumn();

    $pdo->prepare("DELETE FROM cainiao_popup_image_whitelist WHERE id = ?")->execute([$input['id']]);

    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '删除成功'];
}


// 添加黑名单
function addBlacklist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['popup_id']) || empty($input['class_name'])) {
        throw new Exception('缺少必要参数');
    }

    $stmt = $pdo->prepare("
        SELECT i.id FROM cainiao_popup_image i
        JOIN cainiao_apk_config c ON i.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE i.id = :id AND a.user_id = :uid
    ");
    $stmt->execute([':id' => $input['popup_id'], ':uid' => $userId]);
    if (!$stmt->fetch()) {
        throw new Exception('无权操作该弹窗');
    }

    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_fullscreen_blacklist (popup_id, class_name, created_at, remark) VALUES (?, ?, NOW(), ?)");
    $stmt->execute([$input['popup_id'], $input['class_name'], $input['remark']]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_image i JOIN cainiao_apk_config c ON i.config_id = c.id WHERE i.id = ? LIMIT 1");
    $cfgStmt->execute([$input['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '添加成功'];
}


// 删除黑名单
function deleteBlacklist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['id'])) {
        throw new Exception('缺少黑名单ID');
    }

    $stmt = $pdo->prepare("
        SELECT b.id FROM cainiao_popup_fullscreen_blacklist b
        JOIN cainiao_popup_image i ON b.popup_id = i.id
        JOIN cainiao_apk_config c ON i.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE b.id = :id AND a.user_id = :uid
    ");
    $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
    if (!$stmt->fetch()) {
        throw new Exception('无权操作该黑名单');
    }

    // 删除前查出 apk_id 用于桶推送
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_fullscreen_blacklist b JOIN cainiao_popup_image i ON b.popup_id = i.id JOIN cainiao_apk_config c ON i.config_id = c.id WHERE b.id = ? LIMIT 1");
    $cfgStmt->execute([$input['id']]);
    $apkId = (int)$cfgStmt->fetchColumn();

    $pdo->prepare("DELETE FROM cainiao_popup_fullscreen_blacklist WHERE id = ?")->execute([$input['id']]);

    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '删除成功'];
}


function getPopupLists(PDO $pdo, array $input)
{
    if (empty($input['popup_id'])) {
        throw new Exception('缺少弹窗ID');
    }

    $popupId = (int)$input['popup_id'];

    // 获取当前登录用户
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    // 检查权限：确保该用户拥有该弹窗对应的应用
    $check = $pdo->prepare("
        SELECT i.id
        FROM cainiao_popup_image i
        JOIN cainiao_apk_config c ON i.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE i.id = :popup_id AND a.user_id = :uid
    ");
    $check->execute([':popup_id' => $popupId, ':uid' => $userId]);
    if (!$check->fetch()) {
        throw new Exception('权限不足或弹窗不存在');
    }

    // 获取白名单
    $stmt = $pdo->prepare("SELECT id, class_name, created_at, remark FROM cainiao_popup_image_whitelist WHERE popup_id = ?");
    $stmt->execute([$popupId]);
    $whitelist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 获取黑名单
    $stmt = $pdo->prepare("SELECT id, class_name, created_at, remark FROM cainiao_popup_fullscreen_blacklist WHERE popup_id = ?");
    $stmt->execute([$popupId]);
    $blacklist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
            'whitelist' => $whitelist,
            'blacklist' => $blacklist
        ];
}


// 查询弹窗统计数据（汇总 + 按钮明细）
function getStats(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['popup_id'])) {
        throw new Exception('缺少弹窗ID');
    }

    $popupId = (int)$input['popup_id'];

    // 权限校验
    if (!$isAdmin) {
        $check = $pdo->prepare("
            SELECT i.id FROM cainiao_popup_image i
            JOIN cainiao_apk_config c ON i.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE i.id = :id AND a.user_id = :uid
        ");
        $check->execute([':id' => $popupId, ':uid' => $userId]);
        if (!$check->fetch()) {
            throw new Exception('权限不足或弹窗不存在');
        }
    }

    // 汇总展示次数和点击次数
    $stmt = $pdo->prepare("
        SELECT
            SUM(type = 'show') AS show_count,
            SUM(type = 'click') AS click_count
        FROM cainiao_popup_stat_log
        WHERE popup_id = ? AND module = 'popup_image'
    ");
    $stmt->execute([$popupId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // 按按钮维度聚合点击明细
    $detailStmt = $pdo->prepare("
        SELECT
            button_index,
            click_type,
            click_text,
            COUNT(*) AS count
        FROM cainiao_popup_stat_log
        WHERE popup_id = ? AND module = 'popup_image' AND type = 'click'
        GROUP BY button_index, click_type, click_text
        ORDER BY count DESC
    ");
    $detailStmt->execute([$popupId]);
    $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    // 格式化明细，加上可读的点击类型名称
    $clickTypeNames = [
        0 => '无动作', 1 => '打开链接', 2 => '加QQ群',
        3 => '退出APP', 4 => '分享文字', 6 => '复制文字', 7 => '打开窗口',
    ];
    foreach ($details as &$d) {
        $d['button_index'] = (int)$d['button_index'];
        $d['click_type']   = (int)$d['click_type'];
        $d['count']        = (int)$d['count'];
        $d['click_type_name'] = $clickTypeNames[$d['click_type']] ?? '未知';
    }
    unset($d);

    return [
        'show_count'  => (int)($row['show_count'] ?? 0),
        'click_count' => (int)($row['click_count'] ?? 0),
        'details'     => $details,
    ];
}

// 上报弹窗展示/点击（由注入DEX调用，不需要登录鉴权）
function recordStat(PDO $pdo, array $input)
{
    if (empty($input['popup_id']) || empty($input['type'])) {
        throw new Exception('缺少参数');
    }

    $popupId     = (int)$input['popup_id'];
    $type        = $input['type'];           // show 或 click
    $buttonIndex = isset($input['button_index']) ? (int)$input['button_index'] : -1;
    $clickType   = isset($input['click_type'])   ? (int)$input['click_type']   : 0;
    $clickText   = isset($input['click_text'])   ? (string)$input['click_text'] : '';
    $deviceId    = isset($input['device_id'])    ? (string)$input['device_id']  : '';

    if (!in_array($type, ['show', 'click'], true)) {
        throw new Exception('type 参数无效，只支持 show 或 click');
    }

    $pdo->prepare("
        INSERT INTO cainiao_popup_stat_log
            (popup_id, module, type, button_index, click_type, click_text, device_id, created_at)
        VALUES (?, 'popup_image', ?, ?, ?, ?, ?, NOW())
    ")->execute([$popupId, $type, $buttonIndex, $clickType, $clickText, $deviceId]);

    return ['message' => 'ok'];
}

// 工具函数：通过应用 ID 获取配置 ID
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




