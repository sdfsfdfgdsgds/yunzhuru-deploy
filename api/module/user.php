<?php
function getInfo(PDO $pdo, array $input)
{
    // 鉴权检查（含 token 验证 + 更新 last_active）
    $user = Auth::check($pdo);

    $token = $_COOKIE['admin_token'] ?? '';
    $userTable = 'cainiao_user';

    // 计算 VIP 剩余天数（向上取整）
    $vipDays = 0;
    if (!empty($user['vip_expire_time'])) {
        $now = time();
        $expire = strtotime($user['vip_expire_time']);
        if ($expire > $now) {
            $diffSeconds = $expire - $now;
            $vipDays = (int)ceil($diffSeconds / 86400);
        }
    }
    $openid = $user['openid_qq'] ?? '';
    $isBindQQ = !empty($openid);  // 为空代表未绑定
    return [
        'logo' => '/images/logo.png',
        'id' => $user['id'],
        'name' => $user['nickname'] ?: '未知用户',
        'account' => $user['account'] ?: '未知账号',
        'role' => $user['role'] ?: '未知身份',
        'pretty' => $user['pretty'],
        'balance' => $user['balance'],
        'profile' => $user['avatar'] ?: 'https://p2.ssl.qhimgs1.com/sdr/400__/t0101487df3d8159898.jpg',
        'vip_days' => $vipDays,
        'bind_qq'   => $isBindQQ
    ];
}
//获取用户最大能上传的文件大小
function getfilemax(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $maxfile = Auth::getSetting($pdo, "maxfile", "150") * 1024 * 1024;//非会员最大上传大小
    $vipmaxfile = Auth::getSetting($pdo, "vipmaxfile", "256") * 1024 * 1024;//会员最大上传大小
    $vipDays = 0;
    if (!empty($user['vip_expire_time'])) {
        $now = time();
        $expire = strtotime($user['vip_expire_time']);
        if ($expire > $now) {
            $diffSeconds = $expire - $now;
            $vipDays = (int)ceil($diffSeconds / 86400);
        }
    }
    if($vipDays > 0){
        return $vipmaxfile;
    }
    return $maxfile;

}
function getvipprice(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $vipprice = Auth::getSetting($pdo, "vipprice", "10.00");

    // 转为浮点数，并保底为 0.01 元
    $price = floatval($vipprice);
    if ($price <= 0) {
        $price = 0.01;
    }

    return round($price, 2); // 返回保留两位小数
}
function renewvip(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = $user['id'];

    // 参数校验：月份必须在 1 ~ 12 之间
    $months = isset($input['months']) ? (int)$input['months'] : 0;
    if ($months < 1 || $months > 12) {
        throw new Exception("续费月份必须在 1 到 12 之间");
    }

    // 获取 VIP 单月价格（元），并转为分
    $vipprice = Auth::getSetting($pdo, "vipprice", "10.00");
    $vippriceCents = (int)round(floatval($vipprice) * 100);
    $totalCost = $vippriceCents * $months;

    // 检查余额是否足够
    $balance = (int)$user['balance'];
    if ($balance < $totalCost) {
        throw new Exception("余额不足，无法续费 {$months} 个月的 VIP");
    }

    // 判断当前 VIP 是否过期，过期则从当前时间续算
    $now = new DateTime();
    $expireTime = $user['vip_expire_time'] ?? null;
    $baseTime = $now;

    if ($expireTime && $expireTime > $now->format('Y-m-d H:i:s')) {
        $baseTime = new DateTime($expireTime);
    }

    // 每月按 31 天计算
    $baseTime->modify('+' . (31 * $months) . ' days');
    $newVipExpire = $baseTime->format('Y-m-d H:i:s');

    // 扣除余额并更新过期时间
    $stmt = $pdo->prepare("UPDATE cainiao_user SET vip_expire_time = :vip_expire, balance = balance - :cost WHERE id = :id");
    $stmt->execute([
        ':vip_expire' => $newVipExpire,
        ':cost' => $totalCost,
        ':id' => $userId
    ]);
    
    
    //代表有邀请人
    if(!empty($user['superior'])){
        $superiorm = Auth::getSetting($pdo, "superiorm", "0");//奖励上级余额，单位分
        $superiorv = Auth::getSetting($pdo, "superiorv", "0");//奖励上级VIP时长，单位分钟
        if($superiorm > 0){
            $stmt = $pdo->prepare("UPDATE cainiao_user SET balance = balance + :cost WHERE id = :id");
            $stmt->execute([
                ':cost' => $superiorm * $months,//实际奖励 = 奖励金额 * 开通月数
                ':id' => $user['superior']
            ]);
        }
        if($superiorv > 0){
            // 先要查询$user['superior']的vip_expire_time是否超过当前时间
            $querySuperior = $pdo->prepare("SELECT vip_expire_time FROM cainiao_user WHERE id = :superior_id");
            $querySuperior->execute([':superior_id' => $user['superior']]);
            $superiorData = $querySuperior->fetch(PDO::FETCH_ASSOC);
            
            if($superiorData && isset($superiorData['vip_expire_time'])){
                $addtime = $superiorv * $months; // 实际新增的时长 = 奖励时长(分钟) * 开通月数
                
                $now = date('Y-m-d H:i:s'); // 当前时间
                $superiorExpireTime = $superiorData['vip_expire_time'];
                
                // 判断上级会员是否过期
                if(strtotime($superiorExpireTime) > time()){
                    // 如果超过当前时间，则代表是会员，从记录的时间开始增加时长
                    $newExpireTime = date('Y-m-d H:i:s', strtotime($superiorExpireTime . " +{$addtime} minutes"));
                } else {
                    // 如果未超过则代表会员过期，从现在时间开始增加时长
                    $newExpireTime = date('Y-m-d H:i:s', strtotime($now . " +{$addtime} minutes"));
                }
                
                $stmt = $pdo->prepare("UPDATE cainiao_user SET vip_expire_time = :vip_expire WHERE id = :id");
                $stmt->execute([
                    ':vip_expire' => $newExpireTime,
                    ':id' => $user['superior']
                ]);
            }
        }
    }
    return [
        'message' => "成功续费 {$months} 个月 VIP",
        'new_expire_time' => $newVipExpire,
        'balance' => round(($balance - $totalCost) / 100, 2) // 返回剩余元
    ];
}



function changePassword(PDO $pdo, array $input) {
    // 参数校验
    if (empty($input['old_password']) || empty($input['new_password']) || empty($input['confirm_password'])) {
        throw new Exception('缺少必要参数');
    }

    if ($input['new_password'] !== $input['confirm_password']) {
        throw new Exception('两次输入的新密码不一致');
    }

    $user = Auth::check($pdo);
    $userId = $user['id'];

    // 获取当前用户密码
    $stmt = $pdo->prepare("SELECT password FROM cainiao_user WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($input['old_password'], $row['password'])) {
        throw new Exception('原密码错误');
    }

    // 更新新密码
    $newHash = password_hash($input['new_password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE cainiao_user SET password = :pwd WHERE id = :id");
    $stmt->execute([':pwd' => $newHash, ':id' => $userId]);

    return ['message' => '密码修改成功'];
}


function updateNickname(PDO $pdo, array $input) {
    if (empty($input['name'])) throw new Exception('昵称不能为空');
    $user = Auth::check($pdo);
    $stmt = $pdo->prepare("UPDATE cainiao_user SET nickname = :name WHERE id = :id");
    $stmt->execute([':name' => $input['name'], ':id' => $user['id']]);
    return ['message' => '昵称已更新'];
}





//QQ互联绑定
function bind_qq(PDO $pdo, array $input)
{
    if (empty($input['openid']) || empty($input['access_token'])) {
        throw new Exception('缺少 openid 或 access_token');
    }

    $openid = trim($input['openid']);
    $accessToken = trim($input['access_token']);
    $nickname = $input['nickname'] ?? '';
    $avatar = $input['avatar'] ?? '';

    // 当前登录用户
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $userRole = $user['role'];
    $userTable = 'cainiao_user';

    // ---------- 1. 验证 access_token + openid ----------
    $url = "https://graph.qq.com/oauth2.0/me?access_token={$accessToken}";
    $resp = @file_get_contents($url);
    if (!$resp) {
        throw new Exception("无法验证 QQ 登录，请重试");
    }

    $resp = trim($resp);
    $resp = preg_replace('/^callback\(|\);?$/', '', $resp); // 去除 callback()

    $json = json_decode($resp, true);
    if (!$json || empty($json['openid'])) {
        throw new Exception("QQ 登录验证失败");
    }

    // openid 必须一致
    if ($json['openid'] !== $openid) {
        throw new Exception("非法请求：openid 不一致");
    }

    // ---------- 2. 检查 openid 是否绑定别人 ----------
    $stmt = $pdo->prepare("
        SELECT id FROM `$userTable` 
        WHERE openid_qq = :openid AND id <> :id 
        LIMIT 1
    ");
    $stmt->execute([
        ':openid' => $openid,
        ':id' => $userId
    ]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        throw new Exception("该 QQ 已绑定其他账号");
    }

    // ---------- 3. 更新绑定信息 ----------
    if ($userRole === 'admin') {
        // 管理员：只绑定 openid，不修改昵称和头像
        $stmt = $pdo->prepare("
            UPDATE `$userTable`
            SET openid_qq = :openid
            WHERE id = :id
        ");
        $stmt->execute([
            ':openid' => $openid,
            ':id' => $userId
        ]);
    } else {
        // 普通用户：绑定 openid + 更新昵称头像
        $stmt = $pdo->prepare("
            UPDATE `$userTable`
            SET openid_qq = :openid,
                nickname  = :nickname,
                avatar    = :avatar
            WHERE id = :id
        ");
        $stmt->execute([
            ':openid'   => $openid,
            ':nickname' => $nickname,
            ':avatar'   => $avatar,
            ':id'       => $userId
        ]);
    }

    return [
        'message' => '绑定成功',
        'bind_qq' => true,
        'openid'  => $openid,
        'nickname' => ($userRole === 'admin') ? $user['nickname'] : $nickname,
        'avatar'   => ($userRole === 'admin') ? $user['avatar']   : $avatar
    ];
}









