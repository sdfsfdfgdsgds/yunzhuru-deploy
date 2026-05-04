<?php
function login(PDO $pdo, array $input)
{
    if (empty($input['openid']) || empty($input['access_token'])) {
        throw new Exception('缺少参数');
    }

    $openid = $input['openid'];
    $accessToken = $input['access_token'];
    $avatar = $input['avatar'] ?? '';
    $userTable = 'cainiao_user';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = Auth::getRealIp();

    // ---------------------------
    // ① 向腾讯服务器验证 access_token 是否真实
    // ---------------------------
    $verifyUrl = "https://graph.qq.com/oauth2.0/me?access_token=" . urlencode($accessToken);

    $resp = @file_get_contents($verifyUrl);

    if (!$resp) {
        throw new Exception('无法验证QQ登录，请重试');
    }

    // 腾讯返回格式： callback( {"client_id":"APPID","openid":"xxx"} );
    if (preg_match('/"openid"\s*:\s*"([^"]+)"/', $resp, $matches)) {
        $realOpenid = $matches[1];
        if ($realOpenid !== $openid) {
            throw new Exception('QQ登录验证失败（疑似伪造）');
        }
    } else {
        throw new Exception('QQ登录验证失败（无openid）');
    }

    // ---------------------------
    // ② 根据 openid 查询用户
    // ---------------------------
    $stmt = $pdo->prepare("SELECT * FROM `$userTable` WHERE openid_qq = :openid LIMIT 1");
    $stmt->execute([':openid' => $openid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('该QQ未绑定账号');
    }

    // ---------------------------
    // ③ 不是 admin → 同步头像
    // ---------------------------
    if ($user['role'] !== 'admin' && !empty($avatar)) {
        $stmt = $pdo->prepare("UPDATE `$userTable` SET avatar = :avatar WHERE id = :id");
        $stmt->execute([
            ':avatar' => $avatar,
            ':id'     => $user['id']
        ]);
        $user['avatar'] = $avatar;
    }
    
    //$user['unblock_time']字段类型是DATETIME 该字段可能是NULL
    if (!empty($user['unblock_time'])) {
        $unblockTs = strtotime($user['unblock_time']);
        if ($unblockTs !== false && $unblockTs > time()) {
            $left = ceil(($unblockTs - time()) / 3600);
            throw new Exception("账号被封禁，剩余 {$left} 小时");
        }
    }

    // ---------------------------
    // ④ 生成 token
    // ---------------------------
    $token = hash('sha256', uniqid(mt_rand(), true));
    $token = bin2hex(random_bytes(32));
    
    setcookie('admin_token', $token, time() + 3600 * 24 * 30, '/', '', false, false);

    // ---------------------------
    // ⑤ 根据客户端类型更新 token
    // ---------------------------
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ua = mb_substr($ua, 0, 512, 'UTF-8');

    if (preg_match('/okhttp\/\d+(?:\.\d+){0,2}/i', $ua)) {
        $update = $pdo->prepare("
            UPDATE `$userTable`
            SET apptoken = :token, last_login = NOW(), login_ip = :ip, last_active = NOW()
            WHERE id = :id
        ");
    } else {
        $update = $pdo->prepare("
            UPDATE `$userTable`
            SET token = :token, last_login = NOW(), login_ip = :ip, last_active = NOW()
            WHERE id = :id
        ");
    }

    $update->execute([
        ':token' => $token,
        ':ip'    => $ip,
        ':id'    => $user['id']
    ]);

    // ---------------------------
    // ⑥ 返回结果
    // ---------------------------
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
    return [
        'logo'        => '/images/logo.png',
        'name'        => $user['nickname'] ?: '未知用户',
        'profile'     => $user['avatar'] ?: 'https://p2.ssl.qhimgs1.com/sdr/400__/t0101487df3d8159898.jpg',
        'role'        => $user['role'] ?: 'user',
        'vip_days'    => $vipDays,
        'admin_token' => $token
    ];
}
