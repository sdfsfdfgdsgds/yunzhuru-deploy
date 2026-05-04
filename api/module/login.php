<?php
function login(PDO $pdo, array $input)
{
    // 参数检查
    if (empty($input['username']) || empty($input['password']) || empty($input['captcha'])) {
        throw new Exception('账号、密码和验证码均不能为空');
    }
    
    

    $username = $input['username'];
    $password = $input['password'];
    $captcha  = $input['captcha'];
    $userTable = 'cainiao_user';
    $verifyTable = 'cainiao_verify';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = Auth::getRealIp();





    // ========= SQL注入检测 =========
    if (hasSqlInjectionRisk($username)) {
        // 返回蜜罐用户
        return [
            'logo'        => '/images/logo.png',
            'SB'          => '什么年代了，还在玩SQL注入',
            'name'        => 'system_admin',
            'role'        => 'super',
            'profile'     => 'https://yourdomain.com/images/fake_avatar.png',
            'vip_days'    => 9999,
            'admin_token' => 'honeypot_token_' . md5(mt_rand())
        ];
    }











    // 查询验证码（本地环境跳过）
    /*$stmt = $pdo->prepare("SELECT id, code, time FROM `$verifyTable` WHERE ip_address = :ip LIMIT 1");
    $stmt->execute([':ip' => $ip]);
    $verify = $stmt->fetch(PDO::FETCH_ASSOC);

    // 无论验证是否成功，立即删除验证码
    if ($verify) {
        $delete = $pdo->prepare("DELETE FROM `$verifyTable` WHERE id = :id");
        $delete->execute([':id' => $verify['id']]);
    }
    //判断是否需要验证码登录
    if(Auth::getSetting($pdo,"code","1")){
        // 验证码不存在或已过期（3分钟）
        if (!$verify) {
            throw new Exception('验证码无效');
        }

        if (strtotime($verify['time']) < time() - 180) {
            throw new Exception('验证码已过期');
        }

        // 验证码不一致（忽略大小写）
        if (strcasecmp($verify['code'], $captcha) !== 0) {
            throw new Exception('验证码错误');
        }
    }*/
    // 查找用户信息
    $stmt = $pdo->prepare("SELECT * FROM `$userTable` WHERE account = :account LIMIT 1");
    $stmt->execute([':account' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('账号不存在');
    }
    //$user['unblock_time']字段类型是DATETIME 该字段可能是NULL
    if (!empty($user['unblock_time'])) {
        $unblockTs = strtotime($user['unblock_time']);
        if ($unblockTs !== false && $unblockTs > time()) {
            $left = ceil(($unblockTs - time()) / 3600);
            throw new Exception("账号被封禁，剩余 {$left} 小时");
        }
    }


    // 密码验证
    if (!password_verify($password, $user['password'])) {
        throw new Exception('密码错误');
    }
    
    // 生成 token
    $token = hash('sha256', uniqid(mt_rand(), true));
    $token = bin2hex(random_bytes(32));
    
    //获取UA头
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ua = mb_substr($ua, 0, 512, 'UTF-8');
    if (preg_match('/okhttp\/\d+(?:\.\d+){0,2}/i', $ua)) {
        // 更新用户信息（token、登录时间、IP、活跃时间）
        //如果开启了APP共享登录且apptoken不为空白则返回数据库中记录的token
        if($user['multiple_app'] && !empty($user['apptoken'])){
            $token = $user['apptoken'];
        }
        $update = $pdo->prepare("
            UPDATE `$userTable` 
            SET apptoken = :token, last_login = NOW(), login_ip = :ip, last_active = NOW()
            WHERE id = :id
        ");
    }else{
        // 更新用户信息（token、登录时间、IP、活跃时间）
        //如果开启了APP共享登录且apptoken不为空白则返回数据库中记录的token
        if($user['multiple_web'] && !empty($user['token'])){
            $token = $user['token'];
        }
        $update = $pdo->prepare("
            UPDATE `$userTable` 
            SET token = :token, last_login = NOW(), login_ip = :ip, last_active = NOW()
            WHERE id = :id
        ");
    }
    
    
    
    // 设置 Cookie
    setcookie('admin_token', $token, time() + 3600 * 24 * 30, '/', '', false, false);
    
    $update->execute([
        ':token' => $token,
        ':ip'    => $ip,
        ':id'    => $user['id']
    ]);
    // 判断是否 VIP
    $vipDays = 0;

    if (!empty($user['vip_expire_time'])) {
        $vipExpireTs = strtotime($user['vip_expire_time']);
        if ($vipExpireTs !== false) {
            $leftSeconds = $vipExpireTs - time();
            if ($leftSeconds > 0) {
                // 不足 1 天按 1 天算
                $vipDays = (int)ceil($leftSeconds / 86400);
            }
        }
    }
    // 返回结构
    return [
        'uid'         => $user['id'],
        'logo'        => '/images/logo.png',
        'name'        => $user['nickname'] ?: '未知用户',
        'role'        => $user['role'] ?: 'user',
        'profile'     => $user['avatar'] ?: 'https://p2.ssl.qhimgs1.com/sdr/400__/t0101487df3d8159898.jpg',
        'vip_days'    => $vipDays,
        'admin_token' => $token
    ];
}


//主动欺骗式蜜罐防御
function hasSqlInjectionRisk(string $str): bool
{
    $str = strtolower($str);

    $patterns = [
        '/\bor\b\s+\d+=\d+/i',
        '/\bunion\b/i',
        '/\bselect\b/i',
        '/--/',
        '/#/',
        '/\/\*/',
        '/\*\//',
        '/\bsleep\s*\(/i',
        '/\bbenchmark\s*\(/i',
        '/information_schema/i',
        '/;/'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $str)) {
            return true;
        }
    }

    return false;
}
