<?php

function getList1(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $apkId = isset($input['apk_id']) ? intval($input['apk_id']) : 0;
    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    // 获取配置ID
    $configId = getConfigIdByApk($pdo, $userId, $apkId);
    if (!$configId) throw new Exception('配置不存在');

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_popup_message WHERE config_id = :cid");
    $stmt->execute([':cid' => $configId]);
    $total = (int)$stmt->fetchColumn();
    $pages = ceil($total / $limit);

    $stmt = $pdo->prepare("SELECT * FROM cainiao_popup_message WHERE config_id = :cid ORDER BY id DESC LIMIT $offset, $limit");
    $stmt->execute([':cid' => $configId]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['list' => $list, 'page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => $pages];
}


function getList(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $apkId = isset($input['apk_id']) ? intval($input['apk_id']) : 0;
    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $popupTable = 'cainiao_popup_message';
    $configTable = 'cainiao_apk_config';
    $apkTable = 'cainiao_apk';

    $whereSql = '';
    $params = [];

    if ($apkId > 0) {
        if ($isAdmin) {
            // 管理员跳过 user_id 检查
            $stmt = $pdo->prepare("
                SELECT c.id FROM $configTable c
                WHERE c.apk_id = :apk_id
            ");
            $stmt->execute([':apk_id' => $apkId]);
        } else {
            // 普通用户检查 user_id
            $stmt = $pdo->prepare("
                SELECT c.id FROM $apkTable a 
                JOIN $configTable c ON a.id = c.apk_id 
                WHERE a.id = :apk_id AND a.user_id = :uid
            ");
            $stmt->execute([':apk_id' => $apkId, ':uid' => $userId]);
        }

        $configRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$configRow) throw new Exception('配置不存在');

        $whereSql = 'WHERE config_id = :cid';
        $params[':cid'] = $configRow['id'];
        Auth::reset_redis($apkId);
    } else {
        if ($isAdmin) {
            // 管理员可查询全部 config_id
            $stmt = $pdo->prepare("SELECT id FROM $configTable");
            $stmt->execute();
        } else {
            // 普通用户限定 user_id
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
        $whereSql = "WHERE config_id IN ($placeholders)";
        $params = $configIds;
    }

    // 查询总数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $popupTable $whereSql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $pages = ceil($total / $limit);

    // 查询分页数据
    $stmt = $pdo->prepare("SELECT * FROM $popupTable $whereSql ORDER BY id DESC LIMIT $offset, $limit");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($list)) {
        $configIds = array_column($list, 'config_id');
        $placeholders = implode(',', array_fill(0, count($configIds), '?'));

        $sql = "
            SELECT c.id AS config_id, a.name, a.package
            FROM $configTable c 
            JOIN $apkTable a ON c.apk_id = a.id 
            WHERE c.id IN ($placeholders)
        ";
        $stmt = $pdo->prepare($sql);
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
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $required = ['apk_id', 'title', 'message'];
    foreach ($required as $f) {
        if (empty($input[$f])) throw new Exception("缺少参数：$f");
    }

    $remark = trim($input['remark'] ?? '');
    if ($remark === '') {
        $remark = '无';
    }

    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception("配置不存在");

    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_message (config_id, remark, enable, backgroundColor, title, message, exitpopus, `lock`)
                           VALUES (:cid, :remark, :enable, :bg, :title, :msg, :exitpopus, :lock)");
    $stmt->execute([
        ':cid' => $configId,
        ':remark' => $remark,
        ':enable' => !empty($input['enable']) ? 1 : 0,
        ':bg' => $input['backgroundColor'] ?? '#FAFAFA',
        ':title' => $input['title'],
        ':msg' => $input['message'],
        ':exitpopus' => !empty($input['exitpopus']) ? 1 : 0,
        ':lock' => !empty($input['lock']) ? 1 : 0,
    ]);

    Auth::afterConfigChange($pdo, (int)$input['apk_id']);
    return ['message' => '创建成功,别忘了添加按钮哦'];
}


function editPopup(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (empty($input['id'])) {
        throw new Exception('缺少弹窗ID');
    }

    if (!$isAdmin) {
        // 非管理员校验用户权限
        $check = $pdo->prepare("SELECT p.id FROM cainiao_popup_message p
                                JOIN cainiao_apk_config c ON p.config_id = c.id
                                JOIN cainiao_apk a ON c.apk_id = a.id
                                WHERE p.id = :id AND a.user_id = :uid");
        $check->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$check->fetch()) {
            throw new Exception('无权限');
        }
    }

    $fields = ['remark', 'enable', 'backgroundColor', 'title', 'message', 'popup_type', 'maskColor', 'interval', 'exitpopus', 'lock'];
    $updates = [];
    $params = [':id' => $input['id']];

    foreach ($fields as $f) {
        if (isset($input[$f])) {
            if (in_array($f, ['enable', 'exitpopus', 'lock'])) {
                $params[":$f"] = !empty($input[$f]) ? 1 : 0;
            } else {
                $params[":$f"] = $input[$f];
            }
            $updates[] = "`$f` = :$f";
        }
    }

    if (empty($updates)) {
        throw new Exception('无更新字段');
    }

    $sql = "UPDATE cainiao_popup_message SET " . implode(',', $updates) . " WHERE id = :id";
    $pdo->prepare($sql)->execute($params);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT config_id FROM cainiao_popup_message WHERE id = :id LIMIT 1");
    $cfgStmt->execute([':id' => $input['id']]);
    $cfgId = (int)$cfgStmt->fetchColumn();
    if ($cfgId > 0) {
        $apkId = Auth::getApkIdByConfigId($pdo, $cfgId);
        if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);
    }

    return ['message' => '更新成功'];
}



function deletePopup(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['id'])) {
        throw new Exception('缺少弹窗ID');
    }

    // 删除前查出 config_id 用于桶推送
    $cfgStmt = $pdo->prepare("SELECT config_id FROM cainiao_popup_message WHERE id = :id LIMIT 1");
    $cfgStmt->execute([':id' => $input['id']]);
    $configId = (int)$cfgStmt->fetchColumn();

    if (!$isAdmin) {
        $stmt = $pdo->prepare("SELECT p.id FROM cainiao_popup_message p
                               JOIN cainiao_apk_config c ON p.config_id = c.id
                               JOIN cainiao_apk a ON c.apk_id = a.id
                               WHERE p.id = :id AND a.user_id = :uid");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('无权限');
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_popup_message WHERE id = :id");
        $stmt->execute([':id' => $input['id']]);
        if (!$stmt->fetch()) {
            throw new Exception('弹窗不存在');
        }
    }

    $pdo->prepare("DELETE FROM cainiao_popup_message_button WHERE popup_id = ?")->execute([$input['id']]);
    $pdo->prepare("DELETE FROM cainiao_popup_message_whitelist WHERE popup_id = ?")->execute([$input['id']]);
    $pdo->prepare("DELETE FROM cainiao_popup_message_blacklist WHERE popup_id = ?")->execute([$input['id']]);
    $pdo->prepare("DELETE FROM cainiao_popup_message WHERE id = ?")->execute([$input['id']]);

    // 推送配置到桶
    if ($configId > 0) {
        $apkId = Auth::getApkIdByConfigId($pdo, $configId);
        if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);
    }

    return ['message' => '删除成功'];
}


// 获取按钮
function getButtons(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (!$isAdmin && !checkPopupOwner($pdo, $input['popup_id'], $userId)) {
        throw new Exception('无权限');
    }

    $stmt = $pdo->prepare("SELECT * FROM cainiao_popup_message_button WHERE popup_id = ?");
    $stmt->execute([$input['popup_id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 添加按钮
function addButton(PDO $pdo, array $input) {
    $user = Auth::check($pdo); //20250626修复,鉴权漏洞
    $userId = $user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (!$isAdmin && !checkPopupOwner($pdo, $input['popup_id'], $userId)) {
        throw new Exception('无权限');
    }

    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_message_button 
        (popup_id, title, textcolor, backgroundColor, click, clickText, dismiss)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $input['popup_id'],
        $input['title'],
        $input['textcolor'] ?? '#FFFFFF',
        $input['backgroundColor'] ?? '#008577',
        intval($input['click']),
        $input['clickText'] ?? '',
        !empty($input['dismiss']) ? 1 : 0
    ]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_message m JOIN cainiao_apk_config c ON m.config_id = c.id WHERE m.id = ? LIMIT 1");
    $cfgStmt->execute([$input['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '按钮添加成功'];
}


// 删除按钮
function deleteButton(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $isAdmin = $user['role'] === 'admin';

    $stmt = $pdo->prepare("SELECT popup_id FROM cainiao_popup_message_button WHERE id = :id");
    $stmt->execute([':id' => $input['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('缺少弹窗ID');
    }

    if (!$isAdmin && !checkPopupOwner($pdo, $row['popup_id'], $userId)) {
        throw new Exception('无权限');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_popup_message_button WHERE id = ?");
    $stmt->execute([$input['id']]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_message m JOIN cainiao_apk_config c ON m.config_id = c.id WHERE m.id = ? LIMIT 1");
    $cfgStmt->execute([$row['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '按钮删除成功'];
}


// 名单方法同图片弹窗
function addWhitelist(PDO $pdo, array $input) { return addClassnameList($pdo, $input, 'cainiao_popup_message_whitelist'); }
function deleteWhitelist(PDO $pdo, array $input) { return deleteClassnameList($pdo, $input, 'cainiao_popup_message_whitelist'); }
function addBlacklist(PDO $pdo, array $input) { return addClassnameList($pdo, $input, 'cainiao_popup_message_blacklist'); }
function deleteBlacklist(PDO $pdo, array $input) { return deleteClassnameList($pdo, $input, 'cainiao_popup_message_blacklist'); }

/*function addClassnameList($pdo, $input, $table) {
    if (empty($input['popup_id']) || empty($input['class_name'])) throw new Exception('缺少参数');
    $stmt = $pdo->prepare("INSERT INTO `$table` (popup_id, class_name, created_at, remark) VALUES (?, ?, NOW(), ?)");
    $stmt->execute([$input['popup_id'], $input['class_name'], $input['remark']]);
    return ['message' => '添加成功'];
}*/
//20260227修复越权漏洞
function addClassnameList($pdo, $input, $table)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['popup_id']) || empty($input['class_name'])) {
        throw new Exception('缺少参数');
    }

    // 权限校验（管理员也不允许跳过）
    $stmt = $pdo->prepare("
        SELECT m.id
        FROM cainiao_popup_message m
        JOIN cainiao_apk_config c ON m.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE m.id = :popup_id AND a.user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([
        ':popup_id' => $input['popup_id'],
        ':uid' => $userId
    ]);

    if (!$stmt->fetch()) {
        throw new Exception('无权操作该弹窗');
    }

    $stmt = $pdo->prepare("
        INSERT INTO `$table` (popup_id, class_name, created_at, remark)
        VALUES (?, ?, NOW(), ?)
    ");
    $stmt->execute([
        $input['popup_id'],
        $input['class_name'],
        $input['remark'] ?? ''
    ]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_message m JOIN cainiao_apk_config c ON m.config_id = c.id WHERE m.id = ? LIMIT 1");
    $cfgStmt->execute([$input['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '添加成功'];
}

/*function deleteClassnameList($pdo, $input, $table) {
    if (empty($input['id'])) throw new Exception('缺少ID');
    $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$input['id']]);
    return ['message' => '删除成功'];
}*/
//20260227修复越权漏洞
function deleteClassnameList($pdo, $input, $table)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['id'])) {
        throw new Exception('缺少ID');
    }

    // 先查该名单记录对应的 popup_id
    $stmt = $pdo->prepare("SELECT popup_id FROM `$table` WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $input['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('记录不存在');
    }

    $popupId = $row['popup_id'];

    // 校验 popup 所有权
    $stmt = $pdo->prepare("
        SELECT m.id
        FROM cainiao_popup_message m
        JOIN cainiao_apk_config c ON m.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE m.id = :popup_id AND a.user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([
        ':popup_id' => $popupId,
        ':uid' => $userId
    ]);

    if (!$stmt->fetch()) {
        throw new Exception('无权操作该记录');
    }

    // 查出 apk_id 用于桶推送（删除前查）
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_message m JOIN cainiao_apk_config c ON m.config_id = c.id WHERE m.id = ? LIMIT 1");
    $cfgStmt->execute([$popupId]);
    $apkId = (int)$cfgStmt->fetchColumn();

    $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$input['id']]);

    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '删除成功'];
}

function editButton(PDO $pdo, array $input) {
    // 获取当前用户
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    // 参数校验
    if (empty($input['id'])) {
        throw new Exception('缺少按钮ID');
    }

    $fields = [
        'title', 'textcolor', 'backgroundColor', 'click',
        'clickText', 'dismiss'
    ];

    $updates = [];
    $params = [':id' => $input['id']];

    foreach ($fields as $field) {
        if (isset($input[$field])) {
            // 转换布尔字段
            if ($field === 'dismiss') {
                $params[":$field"] = !empty($input[$field]) ? 1 : 0;
            } else {
                $params[":$field"] = $input[$field];
            }
            $updates[] = "`$field` = :$field";
        }
    }

    if (empty($updates)) {
        throw new Exception('没有需要更新的字段');
    }

    // 非管理员权限校验
    if (!$isAdmin) {
        $stmt = $pdo->prepare("
            SELECT b.id FROM cainiao_popup_message_button b
            JOIN cainiao_popup_message m ON b.popup_id = m.id
            JOIN cainiao_apk_config c ON m.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE b.id = :id AND a.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足或按钮不存在');
        }
    } else {
        // 管理员确认按钮是否存在
        $stmt = $pdo->prepare("SELECT id FROM cainiao_popup_message_button WHERE id = :id");
        $stmt->execute([':id' => $input['id']]);
        if (!$stmt->fetch()) {
            throw new Exception('按钮不存在');
        }
    }

    // 执行更新
    $sql = "UPDATE cainiao_popup_message_button SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_message_button b JOIN cainiao_popup_message m ON b.popup_id = m.id JOIN cainiao_apk_config c ON m.config_id = c.id WHERE b.id = ? LIMIT 1");
    $cfgStmt->execute([$input['id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '更新成功'];
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

    // 检查权限：确保该用户拥有该消息弹窗对应的应用
    $check = $pdo->prepare("
        SELECT m.id
        FROM cainiao_popup_message m
        JOIN cainiao_apk_config c ON m.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE m.id = :popup_id AND a.user_id = :uid
    ");
    $check->execute([':popup_id' => $popupId, ':uid' => $userId]);
    if (!$check->fetch()) {
        throw new Exception('权限不足或弹窗不存在');
    }

    // 获取白名单
    $stmt = $pdo->prepare("SELECT id, class_name, created_at, remark FROM cainiao_popup_message_whitelist WHERE popup_id = ?");
    $stmt->execute([$popupId]);
    $whitelist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 获取黑名单
    $stmt = $pdo->prepare("SELECT id, class_name, created_at, remark FROM cainiao_popup_message_blacklist WHERE popup_id = ?");
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

    if (!$isAdmin) {
        $check = $pdo->prepare("
            SELECT m.id FROM cainiao_popup_message m
            JOIN cainiao_apk_config c ON m.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE m.id = :id AND a.user_id = :uid
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
        WHERE popup_id = ? AND module = 'popup_message'
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
        WHERE popup_id = ? AND module = 'popup_message' AND type = 'click'
        GROUP BY button_index, click_type, click_text
        ORDER BY count DESC
    ");
    $detailStmt->execute([$popupId]);
    $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

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
    $type        = $input['type'];
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
        VALUES (?, 'popup_message', ?, ?, ?, ?, ?, NOW())
    ")->execute([$popupId, $type, $buttonIndex, $clickType, $clickText, $deviceId]);

    return ['message' => 'ok'];
}

// 通用配置获取
function getConfigIdByApk($pdo, $userId, $apkId, $isAdmin = false) {
    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_apk_config WHERE apk_id = :apk_id LIMIT 1");
        $stmt->execute([':apk_id' => $apkId]);
    } else {
        $stmt = $pdo->prepare("SELECT c.id FROM cainiao_apk_config c 
                               JOIN cainiao_apk a ON c.apk_id = a.id 
                               WHERE c.apk_id = :apk_id AND a.user_id = :user_id LIMIT 1");
        $stmt->execute([':apk_id' => $apkId, ':user_id' => $userId]);
    }
    return $stmt->fetchColumn();
}


//20250626修复鉴权漏洞新增方法
function checkPopupOwner($pdo, $popupId, $userId) {
    $sql = "
        SELECT 1
        FROM cainiao_popup_message i
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
