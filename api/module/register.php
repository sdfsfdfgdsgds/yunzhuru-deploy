<?php
function register(PDO $pdo, array $input)
{
    // 参数检查
    if (empty($input['account']) || empty($input['password']) || empty($input['confirm_password'])) {
        throw new Exception('账号、密码和确认密码为必填');
    }

    $nickname = trim($input['nickname'] ?? '');
    $account  = trim($input['account']);
    $password = $input['password'];
    $confirm  = $input['confirm_password'];
    $superior = isset($input['superior']) && $input['superior'] !== '' ? (int)$input['superior'] : null; // 邀请人ID
    $ip       = Auth::getRealIp();
    $userTable = 'cainiao_user';

    // 校验账号格式（5~20位）
    if (!preg_match('/^[a-zA-Z0-9_]{5,20}$/', $account)) {
        throw new Exception('账号格式不合法，长度需为5~20位，仅限字母、数字和下划线');
    }

    // 校验密码格式（5~20位）
    if (mb_strlen($password) < 5 || mb_strlen($password) > 20) {
        throw new Exception('密码长度需为5~20位');
    }

    // 密码一致性校验
    if ($password !== $confirm) {
        throw new Exception('两次输入的密码不一致');
    }

    // 昵称处理
    if ($nickname === '') {
        $nicknames = [
            '小虎','铁蛋','阿强','小灰','菜鸟用户','测试者','注入者','开发喵','调试王','研究员',
            '夜行者','火眼金睛','系统探索者','代码猎人','调试狂人','模块大师','内存漫游者','逆向旅人',
            '数据搬运工','调参高手','协议粉碎者','逻辑追踪者','脱壳者','API观察者','日志清道夫',
            '内核潜行者','工具控','函数追踪者','控制台观察员','异步监听者','口算MD5大神','点火就调试',
            '包名终结者','断点布施者','注入全靠手','反编译祖师爷','变量搬运工','代码搬砖侠','调参老中医',
            '协议我全猜','脱壳练习生','签名不求人','函数推理王','遍历不打断','混淆识别犬','逻辑看破王',
            '控制台仙人','你猜我在哪','抓包抓到手软','Hook全靠蒙','日志一眼通','线程开太多','堆栈翻译官',
            'APK发烧友','类名终结者','静态变量王','重签能手','免杀我最行','点击即炸弹','调试不看日志'
        ];
        $nickname = $nicknames[array_rand($nicknames)] . rand(100000, 999999);
    } else {
        if (mb_strlen($nickname, 'UTF-8') > 20) {
            throw new Exception('昵称长度不能超过20个中文字符');
        }
    }

    // 邀请人校验
    if (!empty($superior)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$userTable` WHERE id = :id");
        $stmt->execute([':id' => $superior]);
        if ($stmt->fetchColumn() < 1) {
            throw new Exception('邀请人不存在');
        }
    }

    // IP 注册频率限制（每天最多 2 个）
    $todayStart = date('Y-m-d 00:00:00');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM `$userTable`
        WHERE register_ip = :ip AND register_time >= :start
    ");
    $stmt->execute([
        ':ip'    => $ip,
        ':start' => $todayStart
    ]);
    if ($stmt->fetchColumn() >= 2) {
        throw new Exception('注册过于频繁,请明天再注册吧');
    }

    // 加密密码
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // 默认头像
    $avatar = 'https://p2.ssl.qhimgs1.com/sdr/400__/t0101487df3d8159898.jpg';

    // 注册赠送会员
    $viptime   = (int) Auth::getSetting($pdo, 'viptime', '0');
    $vipExpire = date('Y-m-d H:i:s');
    if ($viptime > 0) {
        $vipExpire = date('Y-m-d H:i:s', time() + $viptime * 3600);
    }

    // ===== 事务开始 =====
    $pdo->beginTransaction();

    try {
        // 查找可复用账号（3天前注册，且从未登录）
        $stmt = $pdo->prepare("
            SELECT id
            FROM `$userTable`
            WHERE register_time < DATE_SUB(NOW(), INTERVAL 3 DAY)
              AND last_login IS NULL
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute();
        $reuseId = $stmt->fetchColumn();

        // 事务内账号唯一性校验（排除被复用的ID）
        $sql = "SELECT COUNT(*) FROM `$userTable` WHERE account = :account";
        $params = [':account' => $account];
        if ($reuseId) {
            $sql .= " AND id != :id";
            $params[':id'] = $reuseId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('账号已存在');
        }

        if ($reuseId) {
            // 复用老账号，更新信息
            $stmt = $pdo->prepare("
                UPDATE `$userTable`
                SET
                    nickname = :nickname,
                    account = :account,
                    password = :password,
                    avatar = :avatar,
                    role = 'user',
                    token = NULL,
                    register_time = NOW(),
                    register_ip = :register_ip,
                    last_active = NOW(),
                    last_login = NULL,
                    login_ip = :login_ip,
                    balance = 0,
                    vip_expire_time = :vip_expire_time,
                    superior = :superior
                WHERE id = :id
            ");
            $stmt->execute([
                ':nickname'        => $nickname,
                ':account'         => $account,
                ':password'        => $hashedPassword,
                ':avatar'          => $avatar,
                ':register_ip'     => $ip,
                ':login_ip'        => $ip,
                ':vip_expire_time' => $vipExpire,
                ':superior'        => $superior,
                ':id'              => $reuseId
            ]);
        } else {
            // 不存在可复用账号，创建新账号
            if (!empty($superior)) {
                $stmt = $pdo->prepare("
                    INSERT INTO `$userTable` (
                        nickname, account, password, avatar, role, token,
                        register_time, register_ip, last_active,
                        balance, login_ip, vip_expire_time, superior
                    ) VALUES (
                        :nickname, :account, :password, :avatar, 'user', NULL,
                        NOW(), :register_ip, NOW(),
                        0, :login_ip, :vip_expire_time, :superior
                    )
                ");
                $stmt->execute([
                    ':nickname'        => $nickname,
                    ':account'         => $account,
                    ':password'        => $hashedPassword,
                    ':avatar'          => $avatar,
                    ':register_ip'     => $ip,
                    ':login_ip'        => $ip,
                    ':vip_expire_time' => $vipExpire,
                    ':superior'        => $superior
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO `$userTable` (
                        nickname, account, password, avatar, role, token,
                        register_time, register_ip, last_active,
                        balance, login_ip, vip_expire_time
                    ) VALUES (
                        :nickname, :account, :password, :avatar, 'user', NULL,
                        NOW(), :register_ip, NOW(),
                        0, :login_ip, :vip_expire_time
                    )
                ");
                $stmt->execute([
                    ':nickname'        => $nickname,
                    ':account'         => $account,
                    ':password'        => $hashedPassword,
                    ':avatar'          => $avatar,
                    ':register_ip'     => $ip,
                    ':login_ip'        => $ip,
                    ':vip_expire_time' => $vipExpire
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // ===== 事务结束 =====

    return [
        'msg'   => '注册成功',
        'input' => $account
    ];
}


//旧版注册方法，不支持UID复用
function register1(PDO $pdo, array $input)
{
    // 参数检查
    if (empty($input['account']) || empty($input['password']) || empty($input['confirm_password'])) {
        throw new Exception('账号、密码和确认密码为必填');
    }

    $nickname = trim($input['nickname'] ?? '');
    $account  = trim($input['account']);
    $password = $input['password'];
    $confirm  = $input['confirm_password'];
    $superior = $input['superior'];//邀请人id
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip =  Auth::getRealIp();
    $userTable = 'cainiao_user';

    // 校验账号格式（5~20位）
    if (!preg_match('/^[a-zA-Z0-9_]{5,20}$/', $account)) {
        throw new Exception('账号格式不合法，长度需为5~20位，仅限字母、数字和下划线');
    }

    // 校验密码格式（5~20位）
    if (mb_strlen($password) < 5 || mb_strlen($password) > 20) {
        throw new Exception('密码长度需为5~20位');
    }

    // 密码一致性校验
    if ($password !== $confirm) {
        throw new Exception('两次输入的密码不一致');
    }

    // 昵称处理
    if (empty($nickname)) {
        $nicknames = [
            '小虎', '铁蛋', '阿强', '小灰', '菜鸟用户', '测试者', '注入者', '开发喵', '调试王', '研究员',
            '夜行者', '火眼金睛', '系统探索者', '代码猎人', '调试狂人', '模块大师', '内存漫游者', '逆向旅人',
            '数据搬运工', '调参高手', '协议粉碎者', '逻辑追踪者', '脱壳者', 'API观察者', '日志清道夫',
            '内核潜行者', '工具控', '函数追踪者', '控制台观察员', '异步监听者', '口算MD5大神','点火就调试',
            '包名终结者','断点布施者','注入全靠手','反编译祖师爷','变量搬运工','代码搬砖侠','调参老中医','协议我全猜',
            '脱壳练习生','签名不求人','函数推理王','遍历不打断','混淆识别犬','逻辑看破王','控制台仙人','你猜我在哪',
            '抓包抓到手软','Hook全靠蒙','日志一眼通','线程开太多','堆栈翻译官','APK发烧友','类名终结者',
            '静态变量王','重签能手','免杀我最行','点击即炸弹','调试不看日志'
        ];
        $randName = $nicknames[array_rand($nicknames)];
        $nickname = $randName . rand(100000, 999999);
    } else {
        // 限制昵称最多 20 个中文字符（注意 multibyte）
        if (mb_strlen($nickname, 'UTF-8') > 20) {
            throw new Exception('昵称长度不能超过20个中文字符');
        }
    }
    
    if(!empty($superior)){
        // 检查邀请人是否存在
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$userTable` WHERE id = :id");
        $stmt->execute([':id' => $superior]);
        if ($stmt->fetchColumn() < 1 ) {
            throw new Exception('邀请人不存在');
        }
    }

    // 检查账号是否已存在
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$userTable` WHERE account = :account");
    $stmt->execute([':account' => $account]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('账号已存在');
    }

    // 限制每个 IP 每天最多注册 2 个账号
    $todayStart = date('Y-m-d 00:00:00');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM `$userTable` 
        WHERE register_ip = :ip AND register_time >= :start
    ");
    $stmt->execute([
        ':ip'    => $ip,
        ':start' => $todayStart
    ]);
    if ($stmt->fetchColumn() >= 2) {
        throw new Exception('注册过于频繁,请明天再注册吧');
    }

    // 加密密码
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // 默认头像
    $avatar = 'https://p2.ssl.qhimgs1.com/sdr/400__/t0101487df3d8159898.jpg';
    // 读取赠送会员时长（单位：小时），并计算会员到期时间（vip_expire_time，datetime）
    $viptime   = (int) Auth::getSetting($pdo, "viptime", "0"); // 注册赠送的会员时长（小时）
    $vipExpire = date('Y-m-d H:i:s', time()); // 默认为当前时间
    if ($viptime > 0) {
        // 计算到期时间：当前时间 + $viptime 小时
        $vipExpire = date('Y-m-d H:i:s', time() + $viptime * 3600);
    }
    // 插入用户记录（增加 vip_expire_time 字段）
    if(!empty($superior)){
        $stmt = $pdo->prepare("
            INSERT INTO `$userTable` (
                nickname, account, password, avatar, role, token, register_time, register_ip, last_active, balance, login_ip, vip_expire_time, superior
            ) VALUES (
                :nickname, :account, :password, :avatar, 'user', NULL, NOW(), :register_ip, NOW(), 0, :login_ip, :vip_expire_time, :superior
            )
        ");
        $stmt->execute([
            ':nickname'         => $nickname,
            ':account'          => $account,
            ':password'         => $hashedPassword,
            ':avatar'           => $avatar,
            ':register_ip'      => $ip,
            ':login_ip'         => $ip,
            ':vip_expire_time'  => $vipExpire,
            ':superior'         => $superior
        ]);
    }else{
        $stmt = $pdo->prepare("
            INSERT INTO `$userTable` (
                nickname, account, password, avatar, role, token, register_time, register_ip, last_active, balance, login_ip, vip_expire_time
            ) VALUES (
                :nickname, :account, :password, :avatar, 'user', NULL, NOW(), :register_ip, NOW(), 0, :login_ip, :vip_expire_time
            )
        ");
        $stmt->execute([
            ':nickname'         => $nickname,
            ':account'          => $account,
            ':password'         => $hashedPassword,
            ':avatar'           => $avatar,
            ':register_ip'      => $ip,
            ':login_ip'         => $ip,
            ':vip_expire_time'  => $vipExpire,
        ]);
    }

    return [
        'msg' => '注册成功',
        'input' => $account
    ];
}
?>