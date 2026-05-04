<?php
function getHtmlPopupList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    $tablePrefix = 'cainiao_';
    $apkTable = $tablePrefix . 'apk';
    $configTable = $tablePrefix . 'apk_config';
    $popupTable = $tablePrefix . 'popup_html';

    $apkId = isset($input['apk_id']) ? intval($input['apk_id']) : 0;
    $enable = isset($input['enable']) ? intval($input['enable']) : null;
    $lock = isset($input['lock']) ? intval($input['lock']) : 0;
    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    $configIdList = [];
    $configApkMap = [];

    if ($apkId > 0) {
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
        if ($isAdmin) {
            $stmt = $pdo->query("SELECT c.id, a.id AS apk_id, a.name, a.package FROM `$apkTable` a JOIN `$configTable` c ON a.id = c.apk_id");
        } else {
            $stmt = $pdo->prepare("SELECT c.id, a.id AS apk_id, a.name, a.package FROM `$apkTable` a JOIN `$configTable` c ON a.id = c.apk_id WHERE a.user_id = :uid");
            $stmt->execute([':uid' => $userId]);
        }
        $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
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
        $in = implode(',', array_fill(0, count($configIdList), '?'));
        $where[] = "config_id IN ($in)";
        $params = array_merge($params, $configIdList);
    }

    if ($enable !== null) {
        $where[] = 'enable = ?';
        $params[] = $enable;
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$popupTable` WHERE $whereSql");
    $stmt->execute($params);
    $total = intval($stmt->fetchColumn());
    $pages = ceil($total / $limit);

    //$stmt = $pdo->prepare("SELECT id, config_id, remark, enable, html, created_at, `lock` FROM `$popupTable` WHERE $whereSql ORDER BY id DESC LIMIT $offset, $limit");
    $stmt = $pdo->prepare("SELECT id, config_id, remark, enable, html, created_at, `lock`, weight FROM `$popupTable` WHERE $whereSql ORDER BY weight DESC, id DESC LIMIT $offset, $limit");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($list as &$item) {
        $cid = $item['config_id'];
        $item['apk_info'] = $configApkMap[$cid]['name'] . '|' . $configApkMap[$cid]['package'] ?? '';
        $item['apk_id'] = $configApkMap[$cid]['apk_id'] ?? 0;
    }

    return [
        'list' => $list,
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => $pages
    ];
}





//弹窗排序
function updateHtmlPopupWeight(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['ids']) || !is_array($input['ids'])) {
        throw new Exception('缺少排序ID列表');
    }

    $ids = array_map('intval', $input['ids']);
    if (count($ids) < 1) {
        throw new Exception('排序数据不能为空');
    }

    $in = implode(',', array_fill(0, count($ids), '?'));

    $apkId = 0;

    // ===== 权限校验 =====
    if (!$isAdmin) {

        $sql = "
        SELECT h.id, a.id AS apk_id
        FROM cainiao_popup_html h
        JOIN cainiao_apk_config c ON h.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE h.id IN ($in) AND a.user_id = ?
        ";

        $stmt = $pdo->prepare($sql);

        $params = $ids;
        $params[] = $userId;

        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) != count($ids)) {
            throw new Exception("弹窗不存在或无权限");
        }

        // 取第一个 apk_id
        $apkId = (int)$rows[0]['apk_id'];

    } else {

        $sql = "
        SELECT h.id, a.id AS apk_id
        FROM cainiao_popup_html h
        JOIN cainiao_apk_config c ON h.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE h.id IN ($in)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) != count($ids)) {
            throw new Exception("弹窗不存在");
        }

        $apkId = (int)$rows[0]['apk_id'];
    }

    // ===== 更新权重 =====

    $pdo->beginTransaction();

    try {

        $weight = count($ids) * 10;

        $stmt = $pdo->prepare("UPDATE cainiao_popup_html SET weight = ? WHERE id = ?");

        foreach ($ids as $id) {

            $stmt->execute([$weight, $id]);

            $weight -= 10;
        }

        $pdo->commit();

    } catch (Exception $e) {

        $pdo->rollBack();
        throw $e;
    }

    // ===== 清理缓存 =====
    if ($apkId > 0) {
        Auth::afterConfigChange($pdo, $apkId);
    }

    return ['message' => '排序更新成功'];
}


function addHtmlPopup(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';
    $required = ['apk_id', 'remark', 'html'];
    foreach ($required as $key) {
        if (empty($input[$key])) throw new Exception("缺少参数：$key");
    }

    $configId = getConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception("配置不存在");
    if(!$user['isVip']){
        $max = Auth::getSetting($pdo,"html","5");
    }else{
        $max = Auth::getSetting($pdo,"viphtml","15");
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_popup_html WHERE config_id = :config_id");
    $stmt->execute([':config_id' => $configId]);
    $count = (int)$stmt->fetchColumn();
    if ($count >= $max) {
        throw new Exception("您最多只能创建{$max}个HTML 弹窗");
    }
    
    if (strlen($input['html']) > 65535) {
        throw new Exception("html代码不能超过64KB");
    }
    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_html (config_id, remark, enable, html, created_at, `lock`) VALUES (:config_id, :remark, :enable, :html, NOW(), :lock)");
    $stmt->execute([
        ':config_id' => $configId,
        ':remark' => $input['remark'],
        ':enable' => !empty($input['enable']) ? 1 : 0,
        ':html' => $input['html'],
        ':lock' => !empty($input['lock']) ? 1 : 0,
    ]);

    Auth::afterConfigChange($pdo, (int)$input['apk_id']);
    return ['message' => '创建成功'];
}


function editHtmlPopup(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['id'])) {
        throw new Exception('缺少弹窗ID');
    }

    $fields = ['remark', 'enable', 'html', 'lock'];
    $updates = [];
    $params = [':id' => $input['id']];

    foreach ($fields as $field) {
        if (isset($input[$field])) {
            if ($field == 'enable' || $field == 'lock') {
                // 转换为整数 0/1
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

    if (!$isAdmin) {
        $stmt = $pdo->prepare("
            SELECT h.id FROM cainiao_popup_html h
            JOIN cainiao_apk_config c ON h.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE h.id = :id AND a.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('权限不足或弹窗不存在');
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_popup_html WHERE id = :id");
        $stmt->execute([':id' => $input['id']]);
        if (!$stmt->fetch()) {
            throw new Exception('弹窗不存在');
        }
    }
    if (strlen($input['html']) > 65535) {
        throw new Exception("html代码不能超过64KB");
    }
    $sql = "UPDATE cainiao_popup_html SET " . implode(',', $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT config_id FROM cainiao_popup_html WHERE id = :id LIMIT 1");
    $cfgStmt->execute([':id' => $input['id']]);
    $cfgId = (int)$cfgStmt->fetchColumn();
    if ($cfgId > 0) {
        $apkId = Auth::getApkIdByConfigId($pdo, $cfgId);
        if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);
    }

    return ['message' => '更新成功'];
}


function deleteHtmlPopup(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (empty($input['id'])) {
        throw new Exception('缺少弹窗ID');
    }

    // 删除前查出 config_id 用于桶推送
    $cfgStmt = $pdo->prepare("SELECT config_id FROM cainiao_popup_html WHERE id = :id LIMIT 1");
    $cfgStmt->execute([':id' => $input['id']]);
    $configId = (int)$cfgStmt->fetchColumn();

    if (!$isAdmin) {
        $stmt = $pdo->prepare("
            SELECT h.id FROM cainiao_popup_html h
            JOIN cainiao_apk_config c ON h.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE h.id = :id AND a.user_id = :uid
        ");
        $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$stmt->fetch()) {
            throw new Exception('无权删除该弹窗');
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_popup_html WHERE id = :id");
        $stmt->execute([':id' => $input['id']]);
        if (!$stmt->fetch()) {
            throw new Exception('弹窗不存在');
        }
    }

    $pdo->prepare("DELETE FROM cainiao_popup_html_whitelist WHERE popup_id = ?")->execute([$input['id']]);
    $pdo->prepare("DELETE FROM cainiao_popup_html_blacklist WHERE popup_id = ?")->execute([$input['id']]);
    $pdo->prepare("DELETE FROM cainiao_popup_html WHERE id = ?")->execute([$input['id']]);

    // 推送配置到桶
    if ($configId > 0) {
        $apkId = Auth::getApkIdByConfigId($pdo, $configId);
        if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);
    }

    return ['message' => '删除成功'];
}


function getHtmlPopupLists(PDO $pdo, array $input)
{
    if (empty($input['popup_id'])) {
        throw new Exception('缺少弹窗ID');
    }

    $popupId = (int)$input['popup_id'];
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';

    if (!$isAdmin) {
        $check = $pdo->prepare("
            SELECT h.id FROM cainiao_popup_html h
            JOIN cainiao_apk_config c ON h.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE h.id = :popup_id AND a.user_id = :uid
        ");
        $check->execute([':popup_id' => $popupId, ':uid' => $userId]);
        if (!$check->fetch()) {
            throw new Exception('权限不足或弹窗不存在');
        }
    } else {
        $check = $pdo->prepare("SELECT id FROM cainiao_popup_html WHERE id = :popup_id");
        $check->execute([':popup_id' => $popupId]);
        if (!$check->fetch()) {
            throw new Exception('弹窗不存在');
        }
    }

    $stmt = $pdo->prepare("SELECT id, class_name, created_at, remark FROM cainiao_popup_html_whitelist WHERE popup_id = ?");
    $stmt->execute([$popupId]);
    $whitelist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT id, class_name, created_at, remark FROM cainiao_popup_html_blacklist WHERE popup_id = ?");
    $stmt->execute([$popupId]);
    $blacklist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['whitelist' => $whitelist, 'blacklist' => $blacklist];
}



function addWhitelist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['popup_id']) || empty($input['class_name'])) {
        throw new Exception('缺少必要参数');
    }

    $stmt = $pdo->prepare("
        SELECT h.id FROM cainiao_popup_html h
        JOIN cainiao_apk_config c ON h.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE h.id = :id AND a.user_id = :uid
    ");
    $stmt->execute([':id' => $input['popup_id'], ':uid' => $userId]);
    if (!$stmt->fetch()) throw new Exception('无权操作该弹窗');

    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_html_whitelist (popup_id, class_name, created_at, remark) VALUES (?, ?, NOW(), ?)");
    $stmt->execute([$input['popup_id'], $input['class_name'], $input['remark']]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_html h JOIN cainiao_apk_config c ON h.config_id = c.id WHERE h.id = ? LIMIT 1");
    $cfgStmt->execute([$input['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '添加成功'];
}


function deleteWhitelist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['id'])) throw new Exception('缺少白名单ID');

    $stmt = $pdo->prepare("
        SELECT w.id FROM cainiao_popup_html_whitelist w
        JOIN cainiao_popup_html h ON w.popup_id = h.id
        JOIN cainiao_apk_config c ON h.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE w.id = :id AND a.user_id = :uid
    ");
    $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
    if (!$stmt->fetch()) throw new Exception('无权操作该白名单');

    // 删除前查出 apk_id
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_html_whitelist w JOIN cainiao_popup_html h ON w.popup_id = h.id JOIN cainiao_apk_config c ON h.config_id = c.id WHERE w.id = ? LIMIT 1");
    $cfgStmt->execute([$input['id']]);
    $apkId = (int)$cfgStmt->fetchColumn();

    $pdo->prepare("DELETE FROM cainiao_popup_html_whitelist WHERE id = ?")->execute([$input['id']]);

    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '删除成功'];
}


function addBlacklist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['popup_id']) || empty($input['class_name'])) {
        throw new Exception('缺少必要参数');
    }

    $stmt = $pdo->prepare("
        SELECT h.id FROM cainiao_popup_html h
        JOIN cainiao_apk_config c ON h.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE h.id = :id AND a.user_id = :uid
    ");
    $stmt->execute([':id' => $input['popup_id'], ':uid' => $userId]);
    if (!$stmt->fetch()) throw new Exception('无权操作该弹窗');

    $stmt = $pdo->prepare("INSERT INTO cainiao_popup_html_blacklist (popup_id, class_name, created_at, remark) VALUES (?, ?, NOW(), ?)");
    $stmt->execute([$input['popup_id'], $input['class_name'], $input['remark']]);

    // 推送配置到桶
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_html h JOIN cainiao_apk_config c ON h.config_id = c.id WHERE h.id = ? LIMIT 1");
    $cfgStmt->execute([$input['popup_id']]);
    $apkId = (int)$cfgStmt->fetchColumn();
    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '添加成功'];
}


function deleteBlacklist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if (empty($input['id'])) throw new Exception('缺少黑名单ID');

    $stmt = $pdo->prepare("
        SELECT b.id FROM cainiao_popup_html_blacklist b
        JOIN cainiao_popup_html h ON b.popup_id = h.id
        JOIN cainiao_apk_config c ON h.config_id = c.id
        JOIN cainiao_apk a ON c.apk_id = a.id
        WHERE b.id = :id AND a.user_id = :uid
    ");
    $stmt->execute([':id' => $input['id'], ':uid' => $userId]);
    if (!$stmt->fetch()) throw new Exception('无权操作该黑名单');

    // 删除前查出 apk_id
    $cfgStmt = $pdo->prepare("SELECT c.apk_id FROM cainiao_popup_html_blacklist b JOIN cainiao_popup_html h ON b.popup_id = h.id JOIN cainiao_apk_config c ON h.config_id = c.id WHERE b.id = ? LIMIT 1");
    $cfgStmt->execute([$input['id']]);
    $apkId = (int)$cfgStmt->fetchColumn();

    $pdo->prepare("DELETE FROM cainiao_popup_html_blacklist WHERE id = ?")->execute([$input['id']]);

    if ($apkId > 0) Auth::afterConfigChange($pdo, $apkId);

    return ['message' => '删除成功'];
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
