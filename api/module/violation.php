<?php
function add(PDO $pdo, array $input) {

    // 权限验证
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限');
    }

    $creatorId = intval($user['id']);

    // 参数校验
    $required = ['name', 'uid', 'appid', 'package', 'level', 'reason', 'punishment'];
    foreach ($required as $k) {
        if (!isset($input[$k]) || $input[$k] === '') {
            throw new Exception("缺少参数：{$k}");
        }
    }

    $name       = trim($input['name']);
    $uid        = intval($input['uid']);
    $appid      = intval($input['appid']);
    $package    = trim($input['package']);
    $level      = intval($input['level']);
    $reason     = trim($input['reason']);
    $punishment = trim($input['punishment']);

    // icon 默认
    $icon = 'images/android.png';

    $check = $pdo->prepare("SELECT id FROM cainiao_violation WHERE appid = :appid LIMIT 1");
    $check->execute([':appid' => $appid]);

    if ($check->fetch()) {
        throw new Exception('该应用已公示过，无需重复提交');
    }

    // 写入时间
    $time = date('Y-m-d H:i:s');

    // 插入
    $sql = "INSERT INTO cainiao_violation
            (icon, name, creator_id, uid, appid, level, package, reason, punishment, time)
            VALUES
            (:icon, :name, :creator_id, :uid, :appid, :level, :package, :reason, :punishment, :time)";
    
    $stmt = $pdo->prepare($sql);

    $ok = $stmt->execute([
        ':icon'        => $icon,
        ':name'        => $name,
        ':creator_id'  => $creatorId,
        ':uid'         => $uid,
        ':appid'       => $appid,
        ':level'       => $level,
        ':package'     => $package,
        ':reason'      => $reason,
        ':punishment'  => $punishment,
        ':time'        => $time,
    ]);

    if (!$ok) {
        throw new Exception('写入失败');
    }

    return ['message' => '内容已公示'];
}


function remove(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限');
    }

    if (empty($input['appid'])) {
        throw new Exception('缺少 appid');
    }

    $appid = intval($input['appid']);

    // 先检查是否有记录
    $stmt = $pdo->prepare("SELECT id FROM cainiao_violation WHERE appid = :appid LIMIT 1");
    $stmt->execute(['appid' => $appid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('该应用没有公示信息');
    }

    // 删除记录
    $stmt = $pdo->prepare("DELETE FROM cainiao_violation WHERE appid = :appid");
    $stmt->execute(['appid' => $appid]);

    return ['message' => '已撤销违规公示'];
}



function getlist(PDO $pdo, array $input) {

    // 权限检查
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限');
    }

    $page  = isset($input['page']) ? max(1, intval($input['page'])) : 1;
    $limit = isset($input['limit']) ? max(1, intval($input['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    $where = " WHERE 1 ";
    $params = [];

    if (!empty($input['appid'])) {
        $where .= " AND appid = :appid ";
        $params['appid'] = intval($input['appid']);
    }
    if (!empty($input['uid'])) {
        $where .= " AND uid = :uid ";
        $params['uid'] = intval($input['uid']);
    }
    if (!empty($input['name'])) {
        $where .= " AND name LIKE :name ";
        $params['name'] = '%' . $input['name'] . '%';
    }

    // 获取总数
    $countSql = "SELECT COUNT(*) FROM cainiao_violation $where";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    // 获取数据列表
    $sql = "SELECT * FROM cainiao_violation 
            $where 
            ORDER BY time DESC 
            LIMIT {$offset}, {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'list'  => $list,
        'total' => intval($total),
        'page'  => $page,
        'limit' => $limit
    ];
}

function update(PDO $pdo, array $input) {

    // 权限检查
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限');
    }

    if (empty($input['id'])) {
        throw new Exception('缺少参数：id');
    }

    $id = intval($input['id']);

    // 可修改字段
    $fields = ['name','uid','appid','package','level','reason','punishment'];

    $set = [];
    $params = ['id' => $id];

    foreach ($fields as $f) {
        if (isset($input[$f])) {
            $set[] = "$f = :$f";
            $params[$f] = $input[$f];
        }
    }

    if (empty($set)) {
        throw new Exception('没有可修改的字段');
    }

    $sql = "UPDATE cainiao_violation SET ".implode(',', $set)." WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return ['message' => '修改成功'];
}


function delete(PDO $pdo, array $input) {

    // 权限检查
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限');
    }

    if (empty($input['id'])) {
        throw new Exception('缺少参数：id');
    }

    $id = intval($input['id']);

    $stmt = $pdo->prepare("DELETE FROM cainiao_violation WHERE id = :id");
    $stmt->execute(['id' => $id]);

    return ['message' => '删除成功'];
}


