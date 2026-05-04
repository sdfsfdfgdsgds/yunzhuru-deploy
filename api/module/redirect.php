<?php

function getlist(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    $page  = isset($input['page']) ? max(1, intval($input['page'])) : 1;
    $limit = isset($input['limit']) ? max(1, intval($input['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    $where = "1";
    $params = [];

    // 支持按 apk_id1 搜索
    if (!empty($input['apk_id1'])) {
        $where .= " AND r.apk_id1 = :apk_id1";
        $params[':apk_id1'] = intval($input['apk_id1']);
    }

    // 支持按 apk_id2 搜索
    if (!empty($input['apk_id2'])) {
        $where .= " AND r.apk_id2 = :apk_id2";
        $params[':apk_id2'] = intval($input['apk_id2']);
    }

    // 统计总数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_redirect r WHERE $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    // 查询列表 + 关联 apk 信息
    $sql = "
        SELECT 
            r.*,
            a2.user_id AS uid,
            a2.name AS apk_name,
            a2.version AS apk_version,
            a2.package AS apk_package
        FROM cainiao_redirect r
        LEFT JOIN cainiao_apk a2 ON r.apk_id2 = a2.id
        WHERE $where
        ORDER BY r.id DESC
        LIMIT $offset, $limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'list' => $list,
        'total' => intval($total),
        'page' => $page
    ];
}


function add(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    $apk_id1 = intval($input['apk_id1'] ?? 0);
    $apk_id2 = intval($input['apk_id2'] ?? 0);
    $remark  = trim($input['remark'] ?? '');

    if ($apk_id1 <= 0 || $apk_id2 <= 0) throw new Exception('请选择有效的应用');
    if ($apk_id1 === $apk_id2) throw new Exception('不能重定向到自身');

    // apk_id2 是否存在
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_apk WHERE id = ?");
    $stmt->execute([$apk_id2]);
    if ($stmt->fetchColumn() == 0) throw new Exception('重定向目标应用不存在');

    // apk_id1 是否已有映射
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_redirect WHERE apk_id1 = ?");
    $stmt->execute([$apk_id1]);
    if ($stmt->fetchColumn() > 0) throw new Exception('该应用已存在映射，请使用编辑功能修改');

    // 插入映射
    $stmt = $pdo->prepare("
        INSERT INTO cainiao_redirect (apk_id1, apk_id2, remark, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$apk_id1, $apk_id2, $remark]);
    if($apk_id1 > 0){
        Auth::reset_redis($apk_id1);
    }
    return ['message' => '映射创建成功'];
}

function update(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    $id      = intval($input['id'] ?? 0);
    $apk_id1 = intval($input['apk_id1'] ?? 0);
    $apk_id2 = intval($input['apk_id2'] ?? 0);
    $remark  = trim($input['remark'] ?? '');

    if ($id <= 0) throw new Exception('参数错误');
    if ($apk_id1 <= 0 || $apk_id2 <= 0) throw new Exception('应用ID无效');
    if ($apk_id1 === $apk_id2) throw new Exception('不能重定向到自身');

    // apk_id2 是否存在
    $exists = $pdo->prepare("SELECT COUNT(*) FROM cainiao_apk WHERE id = ?");
    $exists->execute([$apk_id2]);
    if ($exists->fetchColumn() == 0) throw new Exception('重定向目标应用不存在');

    // 防止把 apk_id1 修改成重复映射
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_redirect WHERE apk_id1=? AND id!=?");
    $stmt->execute([$apk_id1, $id]);
    if ($stmt->fetchColumn() > 0) throw new Exception('该应用已存在映射，请勿重复创建');

    $stmt = $pdo->prepare("
        UPDATE cainiao_redirect
        SET apk_id1=?, apk_id2=?, remark=?
        WHERE id=?
    ");
    $stmt->execute([$apk_id1, $apk_id2, $remark, $id]);
    if($apk_id1 > 0){
        Auth::reset_redis($apk_id1);
    }
    return ['message' => '映射已更新'];
}

/*function delete(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    $id = intval($input['id'] ?? 0);
    if ($id <= 0) throw new Exception('参数错误');

    $stmt = $pdo->prepare("DELETE FROM cainiao_redirect WHERE id=?");
    $stmt->execute([$id]);
    if($apk_id1 > 0){
        Auth::reset_redis($apk_id1);
    }
    return ['message' => '映射已删除'];
}*/
function delete(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限');
    }

    $id = intval($input['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('参数错误');
    }

    // 删除前先获取 apk_id1
    $stmt = $pdo->prepare("SELECT apk_id1 FROM cainiao_redirect WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('记录不存在');
    }

    $apk_id1 = intval($row['apk_id1']);

    // 执行删除
    $stmt = $pdo->prepare("DELETE FROM cainiao_redirect WHERE id=?");
    $stmt->execute([$id]);

    // 清理 redis
    if ($apk_id1 > 0) {
        Auth::reset_redis($apk_id1);
    }

    return ['message' => '映射已删除'];
}

function getApkInfo(PDO $pdo, array $input) {
    // 1. 检查权限
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception("无权限");
    }

    // 2. 检查参数
    if (empty($input['id'])) {
        throw new Exception("缺少 id 参数");
    }

    $id = intval($input['id']);

    // 3. 查询 APK 信息
    $sql = "
        SELECT 
            id,
            user_id,
            name,
            version,
            package,
            upload_time
        FROM cainiao_apk
        WHERE id = :id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // 4. 处理未找到状态
    if (!$row) {
        throw new Exception("未找到该 APPID 对应的应用");
    }

    // 5. 成功返回
    return $row;
}
