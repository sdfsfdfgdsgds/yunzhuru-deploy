<?php

function getUserList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $where = '1=1';
    $params = [];

    if (!empty($input['id'])) {
        $where .= ' AND id = :id';
        $params[':id'] = intval($input['id']);
    }
    if (!empty($input['nickname'])) {
        $where .= ' AND nickname LIKE :nickname';
        $params[':nickname'] = '%' . $input['nickname'] . '%';
    }
    
    if (!empty($input['login_ip'])) {
        $where .= ' AND login_ip LIKE :login_ip';
        $params[':login_ip'] = '%' . $input['login_ip'] . '%';
    }
    
    
    if (!empty($input['account'])) {
        $where .= ' AND account LIKE :account';
        $params[':account'] = '%' . $input['account'] . '%';
    }
    if (!empty($input['superior'])) {
        $where .= ' AND superior = :superior';
        $params[':superior'] = $input['superior'];
    }
    
    if (!empty($input['role'])) {
        $where .= ' AND role = :role';
        $params[':role'] = $input['role'];
    }
    if (!empty($input['ua'])) {
        $where .= ' AND ua = :ua';
        $params[':ua'] = $input['ua'];
    }
    
    // ===== 新增会员筛选逻辑 =====
    $vipStatus = isset($input['vip_status']) ? intval($input['vip_status']) : 0;
    $now = date('Y-m-d H:i:s');
    if ($vipStatus === 1) {
        // 查询会员：vip_expire_time 大于当前时间
        $where .= ' AND vip_expire_time > :now';
        $params[':now'] = $now;
    } elseif ($vipStatus === 2) {
        // 查询非会员：vip_expire_time 小于等于当前时间 或为空
        $where .= ' AND (vip_expire_time <= :now OR vip_expire_time IS NULL)';
        $params[':now'] = $now;
    }
    // ===== 新增封号筛选逻辑 =====
    $blockStatus = $input['block_status'] ?? 'all';
    if ($blockStatus === 'blocked') {
        // 已封号：解封时间大于当前时间
        $where .= ' AND unblock_time > :block_now';
        $params[':block_now'] = $now;
    } elseif ($blockStatus === 'normal') {
        // 未封号：未设置解封时间 或 已解封
        $where .= ' AND (unblock_time IS NULL OR unblock_time <= :block_now)';
        $params[':block_now'] = $now;
    }
    // ===== Q绑查询 =====
    $openid_qq = $input['openid_qq'] ?? 'all';
    if ($openid_qq == '0') {
        //未绑定
        $where .= ' AND openid_qq IS NULL';
    }else if ($openid_qq == '1') {
        //已绑定
        $where .= ' AND openid_qq IS NOT NULL';
    }
    // ===== 客户端查询 =====
    if (!empty($input['appinfo'])) {
        $where .= ' AND appinfo LIKE :appinfo';
        $params[':appinfo'] = '%' . $input['appinfo'] . '%';
    }

    $userTable = 'cainiao_user';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `$userTable` WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT id, nickname, avatar, account, role, register_time, last_login, last_active, balance, ua ,vip_expire_time, appinfo, superior, unblock_time, app_count, pretty, login_ip, multiple_app, multiple_web
            FROM `$userTable` 
            WHERE $where 
            ORDER BY id DESC 
            LIMIT $offset, $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 时间范围统计
    $start = $input['start_time'] ?? date('Y-m-d 00:00:00');
    $end = $input['end_time'] ?? date('Y-m-d 23:59:59');

    $stats = [
        'register_count' => 0,
        'login_count' => 0,
        'active_count' => 0,
        'register_ip_count' => 0,
        'login_ip_count' => 0
    ];

    if ($start && $end) {
        $rangeParams = [
            ':start' => $start,
            ':end' => $end
        ];

        // 注册用户数量
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$userTable` WHERE register_time BETWEEN :start AND :end");
        $stmt->execute($rangeParams);
        $stats['register_count'] = (int)$stmt->fetchColumn();

        // 登录用户数量
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$userTable` WHERE last_login BETWEEN :start AND :end");
        $stmt->execute($rangeParams);
        $stats['login_count'] = (int)$stmt->fetchColumn();

        // 活动用户数量
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$userTable` WHERE last_active BETWEEN :start AND :end");
        $stmt->execute($rangeParams);
        $stats['active_count'] = (int)$stmt->fetchColumn();

        // 注册 IP 数量（去重）
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT register_ip) FROM `$userTable` WHERE register_time BETWEEN :start AND :end");
        $stmt->execute($rangeParams);
        $stats['register_ip_count'] = (int)$stmt->fetchColumn();

        // 登录 IP 数量（去重）
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT login_ip) FROM `$userTable` WHERE last_login BETWEEN :start AND :end");
        $stmt->execute($rangeParams);
        $stats['login_ip_count'] = (int)$stmt->fetchColumn();
    }

    return [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit),
        'stats' => $stats
    ];
}




function updateUser(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    $id = intval($input['id'] ?? 0);
    if (!$id) {
        throw new Exception('缺少用户ID');
    }

    // 查询被修改用户的角色
    $stmt = $pdo->prepare("SELECT role, id FROM cainiao_user WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        throw new Exception('用户不存在');
    }

    if ($target['role'] === 'admin' && $target['id'] !== $user['id']) {
        throw new Exception('不允许修改其他管理员账户');
    }

    $fields = [];
    $params = [':id' => $id];

    if (isset($input['nickname'])) {
        $fields[] = "nickname = :nickname";
        $params[':nickname'] = trim($input['nickname']);
    }
    if (isset($input['account'])) {
        $fields[] = "account = :account";
        $params[':account'] = trim($input['account']);
    }
    if (isset($input['password'])) {
        $password = trim($input['password']);
        if ($password !== '') {
            $fields[] = "password = :password";
            $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
        }
    }
    if (isset($input['balance'])) {
        $fields[] = "balance = :balance";
        $params[':balance'] = intval($input['balance']);
    }
    
    if (isset($input['app_count'])) {
        $fields[] = "app_count = :app_count";
        $params[':app_count'] = intval($input['app_count']);
    }
    // ===== 新增 靓号显示 开关 =====
    if (isset($input['pretty'])) {
        $fields[] = "pretty = :pretty";
        $params[':pretty'] = intval($input['pretty']) ? 1 : 0;
    }
    // ===== 多设备登录开关 =====
    if (isset($input['multiple_app'])) {
        $fields[] = "multiple_app = :multiple_app";
        $params[':multiple_app'] = intval($input['multiple_app']) ? 1 : 0;
    }
    if (isset($input['multiple_web'])) {
        $fields[] = "multiple_web = :multiple_web";
        $params[':multiple_web'] = intval($input['multiple_web']) ? 1 : 0;
    }
    // ===========================
    // ===== 解封时间处理（新增）=====
    if (array_key_exists('unblock_time', $input)) {
        if ($input['unblock_time'] === '' || $input['unblock_time'] === null) {
            $fields[] = "unblock_time = NULL";
        } else {
            $fields[] = "unblock_time = :unblock_time";
            $params[':unblock_time'] = $input['unblock_time'];
        }
    }
    // ==============================
    if (empty($fields)) {
        throw new Exception('没有需要更新的字段');
    }

    $userTable = 'cainiao_user';
    $sql = "UPDATE `$userTable` SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return ['message' => '用户信息已更新'];
}


function createUser(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    if (empty($input['account']) || empty($input['password'])) {
        throw new Exception('账号或密码不能为空');
    }

    $account = trim($input['account']);
    $password = password_hash(trim($input['password']), PASSWORD_DEFAULT);
    $userTable = 'cainiao_user';

    $check = $pdo->prepare("SELECT COUNT(*) FROM `$userTable` WHERE account = :account");
    $check->execute([':account' => $account]);
    if ($check->fetchColumn() > 0) {
        throw new Exception('该账号已存在');
    }

    $stmt = $pdo->prepare("INSERT INTO `$userTable` (account, password, role, register_time) 
                           VALUES (:account, :password, 'user', NOW())");
    $stmt->execute([
        ':account' => $account,
        ':password' => $password
    ]);

    return ['message' => '用户创建成功'];
}
