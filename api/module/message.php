<?php

// 获取我收到的站内信
function getreceivemsg(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $page = isset($input['page']) ? max(1, intval($input['page'])) : 1;
    $limit = 100;
    $offset = ($page - 1) * $limit;

    // 查询总数与未读数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_message WHERE receive_user_id = :uid");
    $stmt->execute([':uid' => $userId]);
    $total = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_message WHERE receive_user_id = :uid AND `read` = 0");
    $stmt->execute([':uid' => $userId]);
    $unread = $stmt->fetchColumn();

    // 查询消息列表
    $stmt = $pdo->prepare("
        SELECT m.*, 
               s.nickname AS send_nickname, s.avatar AS send_avatar,
               r.nickname AS receive_nickname, r.avatar AS receive_avatar
        FROM cainiao_message m
        LEFT JOIN cainiao_user s ON m.send_user_id = s.id
        LEFT JOIN cainiao_user r ON m.receive_user_id = r.id
        WHERE m.receive_user_id = :uid
        ORDER BY m.read ASC, m.upload_time DESC
        LIMIT :offset, :limit
    ");
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 裁剪消息内容为最多7个汉字
    foreach ($list as &$item) {
        $item['message'] = mb_strlen($item['message'], 'UTF-8') > 8
            ? mb_substr($item['message'], 0, 8, 'UTF-8') . '...'
            : $item['message'];
        $item['send_nickname'] = mb_strlen($item['send_nickname'], 'UTF-8') > 8
            ? mb_substr($item['send_nickname'], 0, 8, 'UTF-8') . '...'
            : $item['send_nickname'];
    }

    return [
        'total' => intval($total),
        'unread' => intval($unread),
        'list' => $list
    ];
}


// 获取我发出的站内信
function getsendemsg(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $page = isset($input['page']) ? max(1, intval($input['page'])) : 1;
    $limit = 100;
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_message WHERE send_user_id = :uid");
    $stmt->execute([':uid' => $userId]);
    $total = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT m.*, 
               s.nickname AS send_nickname, s.avatar AS send_avatar,
               r.nickname AS receive_nickname, r.avatar AS receive_avatar
        FROM cainiao_message m
        LEFT JOIN cainiao_user s ON m.send_user_id = s.id
        LEFT JOIN cainiao_user r ON m.receive_user_id = r.id
        WHERE m.send_user_id = :uid
        ORDER BY m.upload_time DESC
        LIMIT :offset, :limit
    ");
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 截断消息内容
    foreach ($list as &$item) {
        $item['message'] = mb_strlen($item['message'], 'UTF-8') > 8
            ? mb_substr($item['message'], 0, 8, 'UTF-8') . '...'
            : $item['message'];
        $item['receive_nickname'] = mb_strlen($item['receive_nickname'], 'UTF-8') > 8
            ? mb_substr($item['receive_nickname'], 0, 8, 'UTF-8') . '...'
            : $item['receive_nickname'];
    }

    return [
        'total' => intval($total),
        'list' => $list
    ];
}


// 发送站内信
function sendmsg(PDO $pdo, array $input) 
{
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $receive_userId = intval($input['receive_user_id']);
    $message = trim($input['message']);

    if (empty($receive_userId)) {
        throw new Exception('接收方不能为空');
    }

    if ($userId == $receive_userId) {
        throw new Exception('不能给自己发送消息');
    }

    if (mb_strlen($message) > 1024) {
        throw new Exception('消息内容不能超过1024字符');
    }

    $stmt = $pdo->prepare("SELECT id FROM cainiao_user WHERE id = :id");
    $stmt->execute([':id' => $receive_userId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        throw new Exception('接收用户不存在');
    }

    // 查询今天已经发送了多少条消息
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total 
        FROM cainiao_message 
        WHERE send_user_id = :uid 
          AND DATE(upload_time) = CURDATE()
    ");
    $stmt->execute([':uid' => $userId]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($count && $count['total'] >= 300) {
        throw new Exception('每天最多只能发送300条消息');
    }

    // 限制发送频率：2秒内只能发送一条
    $stmt = $pdo->prepare("
        SELECT upload_time 
        FROM cainiao_message 
        WHERE send_user_id = :uid 
        ORDER BY upload_time DESC 
        LIMIT 1
    ");
    $stmt->execute([':uid' => $userId]);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($last && strtotime($last['upload_time']) + 2 > time()) {
        throw new Exception('发送太频繁，请稍后再试');
    }

    $stmt = $pdo->prepare("
        INSERT INTO cainiao_message (send_user_id, receive_user_id, message, `read`, upload_time)
        VALUES (:send_user_id, :receive_user_id, :message, 0, NOW())
    ");
    $stmt->execute([
        ':send_user_id' => $userId,
        ':receive_user_id' => $receive_userId,
        ':message' => $message
    ]);

    return ['msg' => '发送成功'];
}



// 获取站内信消息详情
function getmsgdetail(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $messageId = intval($input['messageid']);

    $stmt = $pdo->prepare("
        SELECT m.*, 
               s.nickname AS send_nickname, s.avatar AS send_avatar,
               r.nickname AS receive_nickname, r.avatar AS receive_avatar
        FROM cainiao_message m
        LEFT JOIN cainiao_user s ON m.send_user_id = s.id
        LEFT JOIN cainiao_user r ON m.receive_user_id = r.id
        WHERE m.id = :id
    ");
    $stmt->execute([':id' => $messageId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$msg) {
        throw new Exception('消息不存在');
    }

    if ($msg['send_user_id'] != $userId && $msg['receive_user_id'] != $userId) {
        throw new Exception('无权限查看该消息');
    }

    // 如果是接收方且未读，标记为已读
    if ($msg['receive_user_id'] == $userId && $msg['read'] == 0) {
        $update = $pdo->prepare("UPDATE cainiao_message SET `read` = 1 WHERE id = :id");
        $update->execute([':id' => $messageId]);
        $msg['read'] = 1;
    }

    return $msg;
}
