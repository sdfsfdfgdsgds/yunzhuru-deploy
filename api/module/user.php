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
    $vippriceFloat = floatval($vipprice);
    if (!is_finite($vippriceFloat) || $vippriceFloat <= 0) {
        // 与 getvipprice 保持一致：异常配置不能让 VIP 变成免费。
        $vippriceFloat = 0.01;
    }
    if ($vippriceFloat > PHP_INT_MAX / (100 * $months)) {
        throw new Exception("VIP价格配置无效");
    }
    $vippriceCents = max(1, (int)round($vippriceFloat * 100));
    $totalCost = $vippriceCents * $months;

    // 余额、VIP 到期时间以及上级奖励必须在同一短事务内完成。先按 id 顺序
    // 锁住涉及的用户，避免两个并发续费重复消费，也避免互为上下级时死锁。
    $ownsTransaction = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        }

        $superiorHint = (int)($user['superior'] ?? 0);
        $lockIds = [$userId];
        if ($superiorHint > 0) {
            $lockIds[] = $superiorHint;
        }
        $lockIds = array_values(array_unique(array_map('intval', $lockIds)));
        sort($lockIds, SORT_NUMERIC);
        $lockPlaceholders = [];
        $lockParams = [];
        foreach ($lockIds as $index => $lockId) {
            $placeholder = ':lock_id_' . $index;
            $lockPlaceholders[] = $placeholder;
            $lockParams[$placeholder] = $lockId;
        }
        $lockStmt = $pdo->prepare(
            'SELECT id, balance, vip_expire_time, superior FROM cainiao_user '
            . 'WHERE id IN (' . implode(',', $lockPlaceholders) . ') ORDER BY id FOR UPDATE'
        );
        $lockStmt->execute($lockParams);
        $lockedUsers = [];
        while ($row = $lockStmt->fetch(PDO::FETCH_ASSOC)) {
            $lockedUsers[(int)$row['id']] = $row;
        }
        if (!isset($lockedUsers[(int)$userId])) {
            throw new Exception('用户不存在');
        }

        $freshUser = $lockedUsers[(int)$userId];
        // Auth::check 的快照可能早于上级关系变更；若锁定后发现新的上级不在
        // 初始锁集内，立即补锁该行，确保奖励不会因为旧快照而静默丢失。
        $freshSuperiorId = (int)($freshUser['superior'] ?? 0);
        if ($freshSuperiorId > 0 && !isset($lockedUsers[$freshSuperiorId])) {
            $freshSuperiorStmt = $pdo->prepare(
                'SELECT id, balance, vip_expire_time, superior FROM cainiao_user '
                . 'WHERE id = :superior_id FOR UPDATE'
            );
            $freshSuperiorStmt->execute([':superior_id' => $freshSuperiorId]);
            $freshSuperior = $freshSuperiorStmt->fetch(PDO::FETCH_ASSOC);
            if ($freshSuperior) {
                $lockedUsers[$freshSuperiorId] = $freshSuperior;
            }
        }
        $balance = (int)$freshUser['balance'];
        if ($balance < $totalCost) {
            throw new Exception("余额不足，无法续费 {$months} 个月的 VIP");
        }

        // 判断当前 VIP 是否过期，过期则从当前时间续算。
        $now = new DateTime();
        $expireTime = $freshUser['vip_expire_time'] ?? null;
        $baseTime = $now;
        if ($expireTime && strtotime($expireTime) > $now->getTimestamp()) {
            $baseTime = new DateTime($expireTime);
        }

        // 每月按 31 天计算。
        $baseTime->modify('+' . (31 * $months) . ' days');
        $newVipExpire = $baseTime->format('Y-m-d H:i:s');

        // 原子余额校验是并发场景下的最后一道保护，即使调用方在事务外
        // 修改了余额，也不能把账户扣成负数。
        $stmt = $pdo->prepare(
            'UPDATE cainiao_user SET vip_expire_time = :vip_expire, '
            . 'balance = balance - :cost WHERE id = :id AND balance >= :cost_check'
        );
        $stmt->execute([
            ':vip_expire' => $newVipExpire,
            ':cost' => $totalCost,
            ':cost_check' => $totalCost,
            ':id' => $userId
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new Exception("余额不足，无法续费 {$months} 个月的 VIP");
        }

        // 代表有邀请人。上级行已在同一事务中锁住，奖励与扣款一并提交。
        $superiorId = (int)($freshUser['superior'] ?? 0);
        if ($superiorId > 0 && isset($lockedUsers[$superiorId])) {
            $superiorm = (int)Auth::getSetting($pdo, "superiorm", "0"); // 奖励上级余额，单位分
            $superiorv = (int)Auth::getSetting($pdo, "superiorv", "0"); // 奖励上级 VIP 时长，单位分钟
            if ($superiorm > 0) {
                $stmt = $pdo->prepare('UPDATE cainiao_user SET balance = balance + :cost WHERE id = :id');
                $stmt->execute([
                    ':cost' => $superiorm * $months, // 实际奖励 = 奖励金额 * 开通月数
                    ':id' => $superiorId
                ]);
            }
            if ($superiorv > 0) {
                $addtime = $superiorv * $months; // 实际新增的时长 = 奖励时长(分钟) * 开通月数
                $nowTimestamp = time();
                $superiorExpireTime = $lockedUsers[$superiorId]['vip_expire_time'] ?? null;
                $superiorExpireTimestamp = $superiorExpireTime ? strtotime($superiorExpireTime) : false;
                if ($superiorExpireTimestamp !== false && $superiorExpireTimestamp > $nowTimestamp) {
                    $newExpireTime = date('Y-m-d H:i:s', strtotime($superiorExpireTime . " +{$addtime} minutes"));
                } else {
                    $newExpireTime = date('Y-m-d H:i:s', $nowTimestamp + ($addtime * 60));
                }

                $stmt = $pdo->prepare('UPDATE cainiao_user SET vip_expire_time = :vip_expire WHERE id = :id');
                $stmt->execute([
                    ':vip_expire' => $newExpireTime,
                    ':id' => $superiorId
                ]);
            }
        }

        if ($ownsTransaction && !$pdo->commit()) {
            throw new Exception('续费事务提交失败');
        }
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
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









