<?php
function getList1(PDO $pdo, array $input) {
    if (empty($input['apk_id'])) {
        throw new Exception('缺少应用ID');
    }

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $stmt = $pdo->prepare("
        SELECT i.*
        FROM cainiao_popup_input i
        JOIN cainiao_apk_config c ON i.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE c.apk_id = :apk_id AND a.user_id = :uid
        ORDER BY i.id DESC
    ");
    $stmt->execute([':apk_id' => $input['apk_id'], ':uid' => $userId]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['list' => $list];
}


function getList(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    $apkId = isset($input['apk_id']) ? intval($input['apk_id']) : 0;
    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $popupTable = 'cainiao_popup_input';
    $configTable = 'cainiao_apk_config';
    $apkTable = 'cainiao_apk';

    $params = [];
    $whereSql = '';

    if ($apkId > 0) {
        // 指定应用
        if ($isAdmin) {
            $stmt = $pdo->prepare("
                SELECT c.id FROM $apkTable a 
                JOIN $configTable c ON a.id = c.apk_id 
                WHERE a.id = :apk_id
            ");
            $stmt->execute([':apk_id' => $apkId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT c.id FROM $apkTable a 
                JOIN $configTable c ON a.id = c.apk_id 
                WHERE a.id = :apk_id AND a.user_id = :uid
            ");
            $stmt->execute([':apk_id' => $apkId, ':uid' => $userId]);
        }

        $configRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$configRow) {
            throw new Exception('配置不存在');
        }

        $whereSql = 'WHERE i.config_id = :cid';
        $params[':cid'] = $configRow['id'];
        Auth::reset_redis($apkId);
    } else {
        // 全部应用
        if ($isAdmin) {
            $stmt = $pdo->query("
                SELECT c.id FROM $apkTable a 
                JOIN $configTable c ON a.id = c.apk_id
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT c.id FROM $apkTable a 
                JOIN $configTable c ON a.id = c.apk_id 
                WHERE a.user_id = :uid
            ");
            $stmt->execute([':uid' => $userId]);
        }

        $configIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($configIds)) {
            return ['list' => [], 'page' => $page, 'limit' => $limit, 'total' => 0, 'pages' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($configIds), '?'));
        $whereSql = "WHERE i.config_id IN ($placeholders)";
        $params = $configIds;
    }

    // 总数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $popupTable i $whereSql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pages = ceil($total / $limit);

    // 数据查询
    $stmt = $pdo->prepare("
        SELECT i.* FROM $popupTable i 
        $whereSql 
        ORDER BY i.id DESC 
        LIMIT $offset, $limit
    ");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 附加 apk_info
    if (!empty($list)) {
        $configIds = array_column($list, 'config_id');
        $placeholders = implode(',', array_fill(0, count($configIds), '?'));
        $stmt = $pdo->prepare("
            SELECT c.id AS config_id, a.name, a.package
            FROM $configTable c
            JOIN $apkTable a ON c.apk_id = a.id
            WHERE c.id IN ($placeholders)
        ");
        $stmt->execute($configIds);
        $apkMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $apkMap[$row['config_id']] = $row['name'] . '|' . $row['package'];
        }

        foreach ($list as &$row) {
            $row['apk_info'] = $apkMap[$row['config_id']] ?? '';
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



function addPopup(PDO $pdo, array $input) {
    // 获取当前登录用户信息
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';
    if (empty($input['apk_id'])) {
        throw new Exception('缺少 apk_id');
    }

    // 获取配置ID
    $configId = getConfigIdByApk($pdo, $userId, (int)$input['apk_id'], $isAdmin);
    if (!$configId) {
        throw new Exception('未找到配置或无权限');
    }

    // 插入弹窗数据
    $sql = "INSERT INTO cainiao_popup_input (config_id, remark, enable, backgroundColor, title, message, hint, maskColor, `lock`, autopost)
            VALUES (:config_id, :remark, :enable, :backgroundColor, :title, :message, :hint, :maskColor, :lock, :autopost)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':config_id' => $configId,
        ':remark' => $input['remark'] ?? '',
        ':enable' => empty($input['enable']) ? 0 : 1,
        ':backgroundColor' => $input['backgroundColor'] ?? '#FAFAFA',
        ':title' => $input['title'] ?? '',
        ':message' => $input['message'] ?? '',
        ':hint' => $input['hint'] ?? '',
        ':maskColor' => $input['maskColor'] ?? '#80000000',
        ':lock' => empty($input['lock']) ? 0 : 1,
        ':autopost' => empty($input['autopost']) ? 0 : 1,
    ]);

    Auth::afterConfigChange($pdo, (int)$input['apk_id']);
    return ['message' => '添加成功,别忘了添加按钮哦'];
}

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

function editPopup(PDO $pdo, array $input) {
    if (empty($input['id'])) {
        throw new Exception('缺少弹窗ID');
    }

    // 获取当前登录用户
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (!$isAdmin) {
        // 非管理员需要校验是否拥有权限
        $check = $pdo->prepare("
            SELECT i.id
            FROM cainiao_popup_input i
            JOIN cainiao_apk_config c ON i.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE i.id = :id AND a.user_id = :uid
        ");
        $check->execute([':id' => (int)$input['id'], ':uid' => $userId]);
        if (!$check->fetch()) {
            throw new Exception('权限不足或弹窗不存在');
        }
    } else {
        // 管理员只校验弹窗是否存在
        $check = $pdo->prepare("SELECT id FROM cainiao_popup_input WHERE id = :id");
        $check->execute([':id' => (int)$input['id']]);
        if (!$check->fetch()) {
            throw new Exception('弹窗不存在');
        }
    }

    // 更新弹窗数据
    $sql = "UPDATE cainiao_popup_input SET
        remark = :remark,
        enable = :enable,
        backgroundColor = :backgroundColor,
        title = :title,
        message = :message,
        hint = :hint,
        maskColor = :maskColor,
        `lock` = :lock,
        autopost = :autopost
        WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => (int)$input['id'],
        ':remark' => $input['remark'] ?? '',
        ':enable' => empty($input['enable']) ? 0 : 1,
        ':backgroundColor' => $input['backgroundColor'] ?? '#FAFAFA',
        ':title' => $input['title'] ?? '',
        ':message' => $input['message'] ?? '',
        ':hint' => $input['hint'] ?? '',
        ':maskColor' => $input['maskColor'] ?? '#80000000',
        ':lock' => empty($input['lock']) ? 0 : 1,
        ':autopost' => empty($input['autopost']) ? 0 : 1,
    ]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT config_id FROM cainiao_popup_input WHERE id = :id LIMIT 1");
    $cfgStmt->execute([':id' => $input['id']]);
    $cfgId = (int)$cfgStmt->fetchColumn();
    if ($cfgId > 0) {
        $apkId = Auth::getApkIdByConfigId($pdo, $cfgId);
        if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);
    }

    return ['message' => '更新成功'];
}



function deletePopup(PDO $pdo, array $input) {
    if (empty($input['id'])) throw new Exception('缺少ID');
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    // 删除前查出 config_id 用于桶推送
    $cfgStmt = $pdo->prepare("SELECT config_id FROM cainiao_popup_input WHERE id = :id LIMIT 1");
    $cfgStmt->execute([':id' => $input['id']]);
    $configId = (int)$cfgStmt->fetchColumn();

    if (!$isAdmin) {
        $check = $pdo->prepare("
            SELECT i.id
            FROM cainiao_popup_input i
            JOIN cainiao_apk_config c ON i.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE i.id = :id AND a.user_id = :uid
        ");
        $check->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$check->fetch()) throw new Exception('权限不足或弹窗不存在');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_popup_input WHERE id = ?");
    $stmt->execute([$input['id']]);

    // 推送配置到桶
    if ($configId > 0) {
        $apkId = Auth::getApkIdByConfigId($pdo, $configId);
        if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);
    }

    return ['message' => '删除成功'];
}

function getButtons(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin && !checkPopupOwner($pdo, $input['popup_id'], $userId)) {
        throw new Exception('无权限');
    }

    $stmt = $pdo->prepare("SELECT * FROM cainiao_popup_input_button WHERE popup_id = ?");
    $stmt->execute([$input['popup_id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function addButton(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin && !checkPopupOwner($pdo, $input['popup_id'], $userId)) {
        throw new Exception('无权限');
    }
    if (isset($input['clickText'])) {
        $input['clickText'] = str_replace('://yunzhuru.', '://api.yunzhuru.', $input['clickText']);
    }
    if (isset($input['clickText'])) {
        $input['clickText'] = str_replace('/？', '/?', $input['clickText']);
    }
    $sql = "INSERT INTO cainiao_popup_input_button (popup_id, title, textcolor, backgroundColor, click, clickText, dismiss)
            VALUES (:popup_id, :title, :textcolor, :backgroundColor, :click, :clickText, :dismiss)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':popup_id' => $input['popup_id'],
        ':title' => $input['title'],
        ':textcolor' => $input['textcolor'],
        ':backgroundColor' => $input['backgroundColor'],
        ':click' => $input['click'],
        ':clickText' => $input['clickText'],
        ':dismiss' => $input['dismiss']
    ]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_input i JOIN cainiao_apk_config c ON i.config_id = c.id WHERE i.id = ? LIMIT 1");
    $cfgStmt->execute([$input['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '添加成功'];
}

function editButton(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $stmt = $pdo->prepare("SELECT popup_id FROM cainiao_popup_input_button WHERE id = :id");
    $stmt->execute([':id' => $input['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('缺少弹窗ID');

    if (!$isAdmin && !checkPopupOwner($pdo, $row['popup_id'], $userId)) {
        throw new Exception('无权限');
    }
    if (isset($input['clickText'])) {
        $input['clickText'] = str_replace('://yunzhuru.', '://api.yunzhuru.', $input['clickText']);
    }
    if (isset($input['clickText'])) {
        $input['clickText'] = str_replace('/？', '/?', $input['clickText']);
    }
    $sql = "UPDATE cainiao_popup_input_button
            SET title = :title, textcolor = :textcolor, backgroundColor = :backgroundColor,
                click = :click, clickText = :clickText, dismiss = :dismiss
            WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $input['id'],
        ':title' => $input['title'],
        ':textcolor' => $input['textcolor'],
        ':backgroundColor' => $input['backgroundColor'],
        ':click' => $input['click'],
        ':clickText' => $input['clickText'],
        ':dismiss' => $input['dismiss']
    ]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_input i JOIN cainiao_apk_config c ON i.config_id = c.id WHERE i.id = ? LIMIT 1");
    $cfgStmt->execute([$row['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '更新成功'];
}



function deleteButton(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $isAdmin = $user['role'] === 'admin';

    // 查询 popup_id
    $stmt = $pdo->prepare("SELECT popup_id FROM cainiao_popup_input_button WHERE id = :id");
    $stmt->execute([':id' => $input['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('缺少弹窗ID');
    }

    if (!$isAdmin && !checkPopupOwner($pdo, $row['popup_id'], $userId)) {
        throw new Exception('无权限');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_popup_input_button WHERE id = ?");
    $stmt->execute([$input['id']]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_input i JOIN cainiao_apk_config c ON i.config_id = c.id WHERE i.id = ? LIMIT 1");
    $cfgStmt->execute([$row['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '删除成功'];
}


function getLists(PDO $pdo, array $input) {
    if (empty($input['popup_id'])) throw new Exception('缺少弹窗ID');
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $check = $pdo->prepare("
        SELECT i.id
        FROM cainiao_popup_input i
        JOIN cainiao_apk_config c ON i.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE i.id = :popup_id AND a.user_id = :uid
    ");
    $check->execute([':popup_id' => $input['popup_id'], ':uid' => $userId]);
    if (!$check->fetch()) throw new Exception('权限不足或弹窗不存在');

    $stmt = $pdo->prepare("SELECT id, class_name, created_at, remark FROM cainiao_popup_input_whitelist WHERE popup_id = ?");
    $stmt->execute([$input['popup_id']]);
    $whitelist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id, class_name, created_at, remark FROM cainiao_popup_input_blacklist WHERE popup_id = ?");
    $stmt->execute([$input['popup_id']]);
    $blacklist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['whitelist' => $whitelist, 'blacklist' => $blacklist];
}

/*function addWhitelist(PDO $pdo, array $input) {
    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_input_whitelist (popup_id, class_name, created_at, remark) VALUES (?, ?, NOW(), ?)");
    $stmt->execute([$input['popup_id'], $input['class_name'], $input['remark']]);
    return ['message' => '添加成功'];
}

function addBlacklist(PDO $pdo, array $input) {
    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_input_blacklist (popup_id, class_name, created_at, remark) VALUES (?, ?, NOW(), ?)");
    $stmt->execute([$input['popup_id'], $input['class_name'], $input['remark']]);
    return ['message' => '添加成功'];
}

function deleteWhitelist(PDO $pdo, array $input) {
    $stmt = $pdo->prepare("DELETE FROM cainiao_popup_input_whitelist WHERE id = ?");
    $stmt->execute([$input['id']]);
    return ['message' => '删除成功'];
}

function deleteBlacklist(PDO $pdo, array $input) {
    $stmt = $pdo->prepare("DELETE FROM cainiao_popup_input_blacklist WHERE id = ?");
    $stmt->execute([$input['id']]);
    return ['message' => '删除成功'];
}*/

//20260227重写4个方法，修复越权漏洞
function addWhitelist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['popup_id']) || empty($input['class_name'])) {
        throw new Exception('缺少参数');
    }

    // 强制校验归属（管理员也不允许跳过）
    if (!checkPopupOwner($pdo, $input['popup_id'], $userId)) {
        throw new Exception('无权操作该弹窗');
    }

    $stmt = $pdo->prepare("
        INSERT INTO cainiao_popup_input_whitelist 
        (popup_id, class_name, created_at, remark)
        VALUES (?, ?, NOW(), ?)
    ");
    $stmt->execute([
        $input['popup_id'],
        $input['class_name'],
        $input['remark'] ?? ''
    ]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_input i JOIN cainiao_apk_config c ON i.config_id = c.id WHERE i.id = ? LIMIT 1");
    $cfgStmt->execute([$input['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '添加成功'];
}
function addBlacklist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['popup_id']) || empty($input['class_name'])) {
        throw new Exception('缺少参数');
    }

    if (!checkPopupOwner($pdo, $input['popup_id'], $userId)) {
        throw new Exception('无权操作该弹窗');
    }

    $stmt = $pdo->prepare("
        INSERT INTO cainiao_popup_input_blacklist
        (popup_id, class_name, created_at, remark)
        VALUES (?, ?, NOW(), ?)
    ");
    $stmt->execute([
        $input['popup_id'],
        $input['class_name'],
        $input['remark'] ?? ''
    ]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_input i JOIN cainiao_apk_config c ON i.config_id = c.id WHERE i.id = ? LIMIT 1");
    $cfgStmt->execute([$input['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '添加成功'];
}
function deleteWhitelist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['id'])) {
        throw new Exception('缺少ID');
    }

    // 先查 popup_id
    $stmt = $pdo->prepare("
        SELECT popup_id 
        FROM cainiao_popup_input_whitelist 
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $input['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('记录不存在');
    }

    if (!checkPopupOwner($pdo, $row['popup_id'], $userId)) {
        throw new Exception('无权操作该记录');
    }

    // 删除前查出 apk_id
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_input i JOIN cainiao_apk_config c ON i.config_id = c.id WHERE i.id = ? LIMIT 1");
    $cfgStmt->execute([$row['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();

    $pdo->prepare("DELETE FROM cainiao_popup_input_whitelist WHERE id = ?")
        ->execute([$input['id']]);

    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '删除成功'];
}
function deleteBlacklist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['id'])) {
        throw new Exception('缺少ID');
    }

    $stmt = $pdo->prepare("
        SELECT popup_id 
        FROM cainiao_popup_input_blacklist
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $input['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('记录不存在');
    }

    if (!checkPopupOwner($pdo, $row['popup_id'], $userId)) {
        throw new Exception('无权操作该记录');
    }

    // 删除前查出 apk_id
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_input i JOIN cainiao_apk_config c ON i.config_id = c.id WHERE i.id = ? LIMIT 1");
    $cfgStmt->execute([$row['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();

    $pdo->prepare("DELETE FROM cainiao_popup_input_blacklist WHERE id = ?")
        ->execute([$input['id']]);

    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '删除成功'];
}



//20250626修复鉴权漏洞新增方法
function checkPopupOwner($pdo, $popupId, $userId) {
    $sql = "
        SELECT 1
        FROM cainiao_popup_input i
        JOIN cainiao_apk_config c ON i.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE i.id = :popup_id AND a.user_id = :user_id
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':popup_id' => $popupId,
        ':user_id' => $userId
    ]);
    return $stmt->fetchColumn() !== false;
}

