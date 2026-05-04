<?php
//拉黑设备
function disable(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] === 'admin');

    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $deviceId = isset($input['device_id']) ? trim($input['device_id']) : '';
    $remark   = isset($input['remark']) && trim($input['remark']) !== ''
        ? trim($input['remark'])
        : '拉黑设备';

    if ($apkId <= 0) {
        throw new Exception('应用ID错误');
    }
    if (empty($deviceId)) {
        throw new Exception('设备ID错误');
    }

    // 表名
    $apkTable     = 'cainiao_apk';
    $disableTable = 'cainiao_disable';

    /* ================== 校验应用归属 ================== */
    if ($isAdmin) {
        // 管理员：只校验 apk 是否存在
        $stmt = $pdo->prepare("SELECT id FROM {$apkTable} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $apkId]);
    } else {
        // 普通用户：校验 apk 是否属于当前用户
        $stmt = $pdo->prepare(
            "SELECT id FROM {$apkTable} WHERE id = :id AND user_id = :uid LIMIT 1"
        );
        $stmt->execute([
            ':id'  => $apkId,
            ':uid' => $userId
        ]);
    }

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('无权限操作该应用');
    }

    /* ================== 判断是否已被拉黑 ================== */
    $stmt = $pdo->prepare(
        "SELECT id FROM {$disableTable} 
         WHERE appid = :appid AND deviceId = :deviceId LIMIT 1"
    );
    $stmt->execute([
        ':appid'   => $apkId,
        ':deviceId'=> $deviceId
    ]);

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('该设备已在黑名单中存在');
    }

    /* ================== 写入拉黑记录 ================== */
    $stmt = $pdo->prepare(
        "INSERT INTO {$disableTable}
         (appid, deviceId, remark, enable, created_at)
         VALUES
         (:appid, :deviceId, :remark, 1, NOW())"
    );
    $stmt->execute([
        ':appid'    => $apkId,
        ':deviceId' => $deviceId,
        ':remark'   => $remark
    ]);

    return [
        'message' => '拉黑成功'
    ];
}


//查询拉黑设备
function get_list(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = ($user['role'] === 'admin');

    // 分页参数
    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = isset($input['pageSize']) ? max(1, (int)$input['pageSize']) : 50;
    $offset   = ($page - 1) * $pageSize;

    // 查询参数
    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $deviceId = isset($input['deviceId']) && trim($input['deviceId']) !== ''
        ? trim($input['deviceId'])
        : null;
    $remark   = isset($input['remark']) && trim($input['remark']) !== ''
        ? trim($input['remark'])
        : null;

    if ($apkId <= 0) {
        throw new Exception('应用ID错误');
    }

    // 表名
    $apkTable     = 'cainiao_apk';
    $disableTable = 'cainiao_disable';

    /* ================== 校验应用权限 ================== */
    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT id FROM {$apkTable} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $apkId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id FROM {$apkTable} WHERE id = :id AND user_id = :uid LIMIT 1"
        );
        $stmt->execute([
            ':id'  => $apkId,
            ':uid' => $userId
        ]);
    }

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('无权限查看该应用');
    }

    /* ================== 动态拼接查询条件 ================== */
    $where  = ['appid = :appid'];
    $params = [':appid' => $apkId];

    if ($deviceId !== null) {
        $where[] = 'deviceId = :deviceId';
        $params[':deviceId'] = $deviceId;
    }

    if ($remark !== null) {
        $where[] = 'remark LIKE :remark';
        $params[':remark'] = '%' . $remark . '%';
    }

    $whereSql = implode(' AND ', $where);

    /* ================== 查询总数 ================== */
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) 
         FROM {$disableTable}
         WHERE {$whereSql}"
    );
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    /* ================== 查询列表 ================== */
    $stmt = $pdo->prepare(
        "SELECT 
            id,
            appid,
            deviceId,
            remark,
            enable,
            created_at
         FROM {$disableTable}
         WHERE {$whereSql}
         ORDER BY created_at DESC
         LIMIT :offset, :limit"
    );

    // 绑定分页参数（必须显式指定类型）
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit',  $pageSize, PDO::PARAM_INT);

    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'total'    => $total,
        'page'     => $page,
        'pageSize' => $pageSize,
        'list'     => $list
    ];
}

//启用/禁用拉黑设备规则
function set_disable_status(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = ($user['role'] === 'admin');

    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $deviceId = isset($input['deviceId']) ? trim($input['deviceId']) : '';
    $enable   = isset($input['enable']) ? (int)$input['enable'] : -1;

    if ($apkId <= 0) {
        throw new Exception('应用ID错误');
    }
    /*if (strlen($deviceId) !== 32 || strlen($deviceId) !== 16) {
        throw new Exception('设备ID错误');
    }*/
    if (!in_array($enable, [0, 1], true)) {
        throw new Exception('enable 参数错误');
    }

    // 表名
    $apkTable     = 'cainiao_apk';
    $disableTable = 'cainiao_disable';

    /* ================== 校验应用权限 ================== */
    if ($isAdmin) {
        // 管理员：只校验 apk 是否存在
        $stmt = $pdo->prepare("SELECT id FROM {$apkTable} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $apkId]);
    } else {
        // 普通用户：校验 apk 是否归属当前用户
        $stmt = $pdo->prepare(
            "SELECT id FROM {$apkTable} WHERE id = :id AND user_id = :uid LIMIT 1"
        );
        $stmt->execute([
            ':id'  => $apkId,
            ':uid' => $userId
        ]);
    }

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('无权限操作该应用');
    }

    /* ================== 校验拉黑记录是否存在 ================== */
    $stmt = $pdo->prepare(
        "SELECT id FROM {$disableTable}
         WHERE appid = :appid AND deviceId = :deviceId
         LIMIT 1"
    );
    $stmt->execute([
        ':appid'    => $apkId,
        ':deviceId' => $deviceId
    ]);

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('拉黑记录不存在');
    }

    /* ================== 更新 enable 状态 ================== */
    $stmt = $pdo->prepare(
        "UPDATE {$disableTable}
         SET enable = :enable
         WHERE appid = :appid AND deviceId = :deviceId"
    );
    $stmt->execute([
        ':enable'   => $enable,
        ':appid'    => $apkId,
        ':deviceId' => $deviceId
    ]);

    return [
        'message' => $enable === 1 ? '已启用规则' : '已解除拉黑'
    ];
}

//删除拉黑规则
function delete_disable(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = ($user['role'] === 'admin');

    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $deviceId = isset($input['deviceId']) ? trim($input['deviceId']) : '';

    if ($apkId <= 0) {
        throw new Exception('应用ID错误');
    }
    /*if (strlen($deviceId) !== 32 || strlen($deviceId) !== 16) {
        throw new Exception('设备ID错误');
    }*/

    // 表名
    $apkTable     = 'cainiao_apk';
    $disableTable = 'cainiao_disable';

    /* ================== 校验应用权限 ================== */
    if ($isAdmin) {
        // 管理员：只校验 apk 是否存在
        $stmt = $pdo->prepare(
            "SELECT id FROM {$apkTable} WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $apkId]);
    } else {
        // 非管理员：校验应用是否归属当前用户
        $stmt = $pdo->prepare(
            "SELECT id FROM {$apkTable} WHERE id = :id AND user_id = :uid LIMIT 1"
        );
        $stmt->execute([
            ':id'  => $apkId,
            ':uid' => $userId
        ]);
    }

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('无权限操作该应用');
    }

    /* ================== 校验拉黑记录是否存在 ================== */
    $stmt = $pdo->prepare(
        "SELECT id FROM {$disableTable}
         WHERE appid = :appid AND deviceId = :deviceId
         LIMIT 1"
    );
    $stmt->execute([
        ':appid'    => $apkId,
        ':deviceId' => $deviceId
    ]);

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception('拉黑记录不存在');
    }

    /* ================== 删除拉黑记录 ================== */
    $stmt = $pdo->prepare(
        "DELETE FROM {$disableTable}
         WHERE appid = :appid AND deviceId = :deviceId"
    );
    $stmt->execute([
        ':appid'    => $apkId,
        ':deviceId' => $deviceId
    ]);

    return [
        'message' => '拉黑规则已删除'
    ];
}

