<?php


//疑似存在跨用户生成的问题
function add(PDO $pdo, array $input) {
    // 参数校验
    if (empty($input['app_id']) || empty($input['count']) || empty($input['time'])) {
        throw new Exception('缺少必要参数');
    }

    $count  = (int)$input['count'];
    $time   = (float)$input['time'];
    $bind   = (int)($input['bind'] ?? 1);
    $name   = $input['name']   ?? '卡密';
    $remark = $input['remark'] ?? '无备注';

    if ($count < 1 || $count > 300) {
        throw new Exception('生成数量应在1~300之间');
    }
    if ($time < 0.01 || $time > 999999999) {
        throw new Exception('有效时长非法');
    }

    // 鉴权，管理员不验证 user_id
    $user    = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    // 应用归属/存在校验：管理员仅校验存在；普通用户校验归属
    if ($isAdmin) {
        // 管理员：不校验 user_id，但需校验 app 是否存在
        $stmt = $pdo->prepare("SELECT id FROM cainiao_apk WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $input['app_id']]);
    } else {
        // 普通用户：校验归属
        $stmt = $pdo->prepare("SELECT id FROM cainiao_apk WHERE id = :id AND user_id = :uid LIMIT 1");
        $stmt->execute([':id' => $input['app_id'], ':uid' => $userId]);
    }
    if (!$stmt->fetch()) {
        throw new Exception('权限不足或应用不存在');
    }

    // 未使用卡密数量限制
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_kami WHERE app_id = :app_id AND use_at IS NULL");
    $stmt->execute([':app_id' => $input['app_id']]);
    $unusedCount = (int)$stmt->fetchColumn();
    $max = 1000;
    if($user['isVip']){
        $max = 3000;
    }
    if ($unusedCount > $max) {
        throw new Exception("未使用卡密超过{$max}，禁止继续生成");
    }

    // 插入卡密
    $stmt = $pdo->prepare("INSERT INTO cainiao_kami 
        (app_id, kami, created_at, name, time, deviceId, package, version, enabled, remark, bind) 
        VALUES (:app_id, :kami, NOW(), :name, :time, '', '', '', 1, :remark, :bind)");

    for ($i = 0; $i < $count; $i++) {
        $uuidStr = '';
        for ($j = 0; $j < 8; $j++) {
            // uuid() 为我项目中的函数，这里直接调用
            $uuidStr .= uuid();
        }
        $raw      = $uuidStr . substr((string)(microtime(true) * 1000), 0, 13);
        $kamiCode = md5($raw);
        $kamiCode = generateSecureKami();
        $stmt->execute([
            ':app_id' => $input['app_id'],
            ':kami'   => $kamiCode,
            ':name'   => $name,
            ':time'   => $time,
            ':remark' => $remark,
            ':bind'   => $bind
        ]);
    }

    return ['message' => '卡密生成成功'];
}


//导入卡密
function import(PDO $pdo, array $input) {
    // 参数校验
    if (empty($input['app_id']) || empty($input['time'])) {
        throw new Exception('缺少必要参数');
    }
    if(empty($input['name'])){
        throw new Exception('卡密名称不能为空');
    }
    $time   = (float)$input['time'];
    $bind   = (int)($input['bind'] ?? 1);
    $name   = $input['name'] ?? '外部导入卡密';
    $remark = $input['remark'] ?? '无备注';
    $kamiText = trim($input['kami_text'] ?? '');

    if ($time < 0.01 || $time > 999999999) {
        throw new Exception('有效时长非法');
    }

    if ($kamiText === '') {
        throw new Exception('请提交卡密文本');
    }

    // 鉴权
    $user    = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    // 应用校验：管理员仅校验存在；普通用户校验归属
    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_apk WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $input['app_id']]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_apk WHERE id = :id AND user_id = :uid LIMIT 1");
        $stmt->execute([':id' => $input['app_id'], ':uid' => $userId]);
    }
    if (!$stmt->fetch()) {
        throw new Exception('权限不足或应用不存在');
    }

    // 拆分多行卡密
    $kamiList = preg_split('/\r\n|\r|\n/', $kamiText);
    $kamiList = array_filter(array_map('trim', $kamiList), fn($v) => $v !== '');

    if (count($kamiList) === 0) {
        throw new Exception('未检测到有效卡密');
    }
    if (count($kamiList) > 300) {
        throw new Exception('单次最多导入300条');
    }

    // 卡密有效性检查
    foreach ($kamiList as $k) {
        $len = strlen($k);
        if ($len < 1 || $len > 32) {
            throw new Exception('卡密长度非法：' . htmlspecialchars($k));
        }
    }
    
    
     // 未使用卡密数量限制
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_kami WHERE app_id = :app_id AND use_at IS NULL");
    $stmt->execute([':app_id' => $input['app_id']]);
    $unusedCount = (int)$stmt->fetchColumn();
    $max = 1000;
    if($user['isVip']){
        $max = 3000;
    }
    if ($unusedCount > $max) {
        throw new Exception("未使用卡密超过{$max}，禁止继续导入");
    }

    // 插入准备
    $stmtInsert = $pdo->prepare("INSERT INTO cainiao_kami 
        (app_id, kami, created_at, name, time, deviceId, package, version, enabled, remark, bind)
        VALUES (:app_id, :kami, NOW(), :name, :time, '', '', '', 1, :remark, :bind)");

    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM cainiao_kami WHERE app_id = :app_id AND kami = :kami");

    $success = 0;
    $fail = 0;

    foreach ($kamiList as $kamiCode) {
        // 检查是否已存在
        $stmtCheck->execute([
            ':app_id' => $input['app_id'],
            ':kami' => $kamiCode
        ]);
        if ($stmtCheck->fetchColumn() > 0) {
            $fail++;
            continue;
        }

        // 插入
        try {
            $stmtInsert->execute([
                ':app_id' => $input['app_id'],
                ':kami'   => $kamiCode,
                ':name'   => $name,
                ':time'   => $time,
                ':remark' => $remark,
                ':bind'   => $bind
            ]);
            $success++;
        } catch (Throwable $e) {
            $fail++;
        }
    }

    return [
        'message' => "导入完成，成功{$success}，失败{$fail}"
    ];
}


// 批量删除卡密
function delete(PDO $pdo, array $input) {
    // 参数校验
    if (empty($input['ids']) || !is_array($input['ids'])) {
        throw new Exception('参数错误');
    }

    // 统一转为整型，移除无效值
    $ids = array_values(array_filter(array_map('intval', $input['ids']), fn($v) => $v > 0));
    if (empty($ids)) {
        throw new Exception('参数错误：无有效ID');
    }

    // 鉴权，管理员不验证user_id
    $user    = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    if ($isAdmin) {
        // 管理员：直接按ID批量删除
        $inQuery = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM cainiao_kami WHERE id IN ($inQuery)");
        $stmt->execute($ids);

        return ['message' => '卡密删除成功'];
    }

    // 普通用户：只允许删除自己名下应用生成的卡密
    $inQuery = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT k.id 
            FROM cainiao_kami k 
            JOIN cainiao_apk a ON k.app_id = a.id 
            WHERE a.user_id = ? AND k.id IN ($inQuery)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$userId], $ids));
    $validIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($validIds)) {
        throw new Exception('没有可删除的卡密');
    }

    $delQuery = implode(',', array_fill(0, count($validIds), '?'));
    $stmt = $pdo->prepare("DELETE FROM cainiao_kami WHERE id IN ($delQuery)");
    $stmt->execute($validIds);

    return ['message' => '卡密删除成功'];
}

//批量解绑卡密
function unbind(PDO $pdo, array $input) {
    // 参数校验
    if (empty($input['ids']) || !is_array($input['ids'])) {
        throw new Exception('参数错误');
    }

    // 统一转为整型，移除无效值
    $ids = array_values(array_filter(array_map('intval', $input['ids']), fn($v) => $v > 0));
    if (empty($ids)) {
        throw new Exception('参数错误：无有效ID');
    }

    // 鉴权，管理员不验证 user_id
    $user    = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    $inQuery = implode(',', array_fill(0, count($ids), '?'));

    if ($isAdmin) {
        // 管理员：直接按ID批量解绑
        $stmt = $pdo->prepare(
            "UPDATE cainiao_kami 
             SET deviceId = '' 
             WHERE id IN ($inQuery)"
        );
        $stmt->execute($ids);

        return ['message' => '卡密解绑成功'];
    }

    // 普通用户：只能解绑自己名下应用生成的卡密
    $sql = "SELECT k.id
            FROM cainiao_kami k
            JOIN cainiao_apk a ON k.app_id = a.id
            WHERE a.user_id = ?
              AND k.id IN ($inQuery)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$userId], $ids));
    $validIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($validIds)) {
        throw new Exception('没有可解绑的卡密');
    }

    $updateQuery = implode(',', array_fill(0, count($validIds), '?'));
    $stmt = $pdo->prepare(
        "UPDATE cainiao_kami 
         SET deviceId = '' 
         WHERE id IN ($updateQuery)"
    );
    $stmt->execute($validIds);

    return ['message' => '卡密解绑成功'];
}



function getList(PDO $pdo, array $input) {
    if (empty($input['app_id'])) {
        throw new Exception('缺少应用ID');
    }

    $user    = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    // 管理员不验证user_id，普通用户需校验归属
    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_apk WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $input['app_id']]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cainiao_apk WHERE id = :id AND user_id = :uid LIMIT 1");
        $stmt->execute([':id' => $input['app_id'], ':uid' => $userId]);
    }
    if (!$stmt->fetch()) throw new Exception('权限不足或应用不存在');

    $page     = max((int)($input['page'] ?? 1), 1);
    $pageSize = max(min((int)($input['page_size'] ?? 20), 100), 1);
    $offset   = ($page - 1) * $pageSize;

    $where   = ['k.app_id = :app_id'];
    $params  = [':app_id' => $input['app_id']];

    // 是否使用筛选
    if (!empty($input['used']) || $input['used'] === '0') {
        if ((int)$input['used'] === 1) {
            $where[] = 'k.use_at IS NOT NULL';
        } elseif ((int)$input['used'] === 0) {
            $where[] = 'k.use_at IS NULL';
        }
    }

    // 卡密模糊搜索
    if (!empty($input['kami'])) {
        $where[] = 'k.kami LIKE :kami';
        $params[':kami'] = '%' . $input['kami'] . '%';
    }

    // 设备ID模糊搜索
    if (!empty($input['deviceId'])) {
        $where[] = 'k.deviceId LIKE :deviceId';
        $params[':deviceId'] = '%' . $input['deviceId'] . '%';
    }
    
    // 卡密名称模糊查找
    /*if (!empty($input['name'])) {
        $where[] = 'k.name LIKE :name';
        $params[':name'] = '%' . $input['name'] . '%';
    }*/
    
    if (!empty($input['name'])) {
        $where[] = '(k.name LIKE :name OR k.remark LIKE :name)';
        $params[':name'] = '%' . $input['name'] . '%';
    }


    // 启用状态筛选
    if (!empty($input['enabled']) || $input['enabled'] === '0') {
        $where[] = 'k.enabled = :enabled';
        $params[':enabled'] = (int)$input['enabled'];
    }
    
    //筛选已使用但是过期/未过期的卡密
    if (isset($input['expire']) && $input['expire'] !== '') {
        if ((int)$input['expire'] === 1) {
            $where[] = "(k.use_at IS NOT NULL AND (UNIX_TIMESTAMP(k.use_at) + (k.time * 3600)) < UNIX_TIMESTAMP())";
        } elseif ((int)$input['expire'] === 0) {
            $where[] = "(k.use_at IS NOT NULL AND (UNIX_TIMESTAMP(k.use_at) + (k.time * 3600)) >= UNIX_TIMESTAMP())";
        }
    }

    $whereSql = implode(' AND ', $where);

    // 查询总数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_kami k WHERE $whereSql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $totalPage = (int)ceil($total / $pageSize);

    // 查询数据
    $stmt = $pdo->prepare("SELECT 
            k.id, k.kami, k.created_at, k.use_at, k.name, k.time, 
            k.deviceId, k.package, k.version, k.enabled, k.remark, k.bind 
        FROM cainiao_kami k 
        WHERE $whereSql 
        ORDER BY k.id DESC 
        LIMIT $offset, $pageSize");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 类型转换
    foreach ($list as &$row) {
        $row['enabled'] = (bool)$row['enabled'];
        $row['bind'] = (bool)$row['bind'];
        // 如果有使用时间和有效期，则判断是否过期
        if (!empty($row['use_at']) && !empty($row['time'])) {
            // 使用时间转为时间戳
            $useTime = strtotime($row['use_at']);
            // 计算过期时间 = 使用时间 + 有效期小时
            $expireTime = $useTime + ((float)$row['time'] * 3600);
            // 当前时间
            $now = time();
    
            // 如果当前时间超过过期时间，则标记为已过期
            if ($now > $expireTime) {
                $row['kami'] .= '(已过期)';
            }
        }
    }

    return [
        'data'       => $list,
        'total'      => $total,
        'page'       => $page,
        'total_page' => $totalPage
    ];
}


function edit(PDO $pdo, array $input) {
    // 参数校验
    if (empty($input['ids']) || !is_array($input['ids'])) {
        throw new Exception('参数错误：缺少ID列表');
    }

    // 规范化ID列表（仅保留正整数）
    $ids = array_values(array_filter(array_map('intval', $input['ids']), function($v){ return $v > 0; }));
    if (empty($ids)) {
        throw new Exception('参数错误：无有效ID');
    }

    $user    = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');

    // 构造可更新字段与参数
    $fields = [];
    $params = [];

    if (isset($input['deviceId'])) {
        $fields[] = 'deviceId = :deviceId';
        $params[':deviceId'] = (String)$input['deviceId'];
    }
    
    if (isset($input['bind'])) {
        $fields[] = 'bind = :bind';
        $params[':bind'] = (int)$input['bind'];
    }
    
    if (isset($input['time'])) {
        if ((float)$input['time'] < 0.01 || (float)$input['time'] > 999999999) {
            $input['time'] = 0.01;
        }
        if ((float)$input['time'] > 999999999) {
            $input['time'] = 999999999;
        }
        $fields[] = 'time = :time';
        $params[':time'] = (float)$input['time'];
    }
    if (isset($input['enabled'])) {
        $fields[] = 'enabled = :enabled';
        $params[':enabled'] = (int)$input['enabled'];
    }
    if (isset($input['name'])) {
        $fields[] = 'name = :name';
        $params[':name'] = $input['name'];
    }
    if (isset($input['remark'])) {
        $fields[] = 'remark = :remark';
        $params[':remark'] = $input['remark'];
    }
    if (empty($fields)) {
        throw new Exception('未提交可更新字段');
    }

    // 管理员：不验证user_id，直接按ID更新
    if ($isAdmin) {
        $updateIdParams = [];
        foreach ($ids as $i => $id) {
            $updateIdParams[":id{$i}"] = $id;
        }
        $sql = "UPDATE cainiao_kami SET " . implode(', ', $fields) . " 
                WHERE id IN (" . implode(',', array_keys($updateIdParams)) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $updateIdParams));

        return ['message' => '批量更新成功'];
    }

    // 普通用户：校验归属后再更新
    // 1) 先查出当前用户名下可操作的ID
    $checkIdParams = [];
    foreach ($ids as $i => $id) {
        $checkIdParams[":id{$i}"] = $id;
    }
    $checkSql = "SELECT k.id FROM cainiao_kami k 
                 JOIN cainiao_apk a ON k.app_id = a.id 
                 WHERE a.user_id = :uid AND k.id IN (" . implode(',', array_keys($checkIdParams)) . ")";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute(array_merge([':uid' => $userId], $checkIdParams));
    $validIds = $checkStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($validIds)) {
        throw new Exception('无可修改的卡密记录');
    }

    // 2) 仅对可操作ID执行更新
    $updateIdParams = [];
    foreach ($validIds as $i => $id) {
        $updateIdParams[":uid{$i}"] = (int)$id;
    }
    $sql = "UPDATE cainiao_kami SET " . implode(', ', $fields) . " 
            WHERE id IN (" . implode(',', array_keys($updateIdParams)) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $updateIdParams));

    return ['message' => '批量更新成功'];
}

function generateSecureKami(): string {
    $charset = 'ABCDEFGHJKLMNOPQRSTUVWXYZ023456789'; // 34 个字符，排除 I 和 1
    $base = strlen($charset);

    // 使用时间戳 + 随机数，hash 后转 10 进制字符串
    $seed = microtime(true) . mt_rand(100000, 999999);
    $hash = hash('sha256', $seed); // 64位 hex
    $decimalStr = base_convert($hash, 16, 10); // 转十进制字符串（兼容）

    // 自定义 base34 编码（兼容大数字）
    $result = '';
    $num = $decimalStr;

    while (strlen($result) < 10 && $num !== '0') {
        $remainder = bcmod($num, $base);
        $result .= $charset[$remainder];
        $num = bcdiv($num, $base);
    }

    // 长度不足补随机字符
    while (strlen($result) < 10) {
        $result .= $charset[random_int(0, $base - 1)];
    }

    return $result;
}


// 生成UUID方法
function uuid() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
    return vsprintf('%s%s%s%s%s%s%s%s', str_split(bin2hex($data), 4));
}
