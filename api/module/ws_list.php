<?php

function get_ws_list改版(PDO $pdo, array $input) {

    $user = Auth::check($pdo);
    $userId = $user['id'];
    $isAdmin = $user['role'] === 'admin';

    // ===== 分页参数 =====
    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = isset($input['pageSize']) ? max(1, (int)$input['pageSize']) : 50;
    $pageSize = 10; // 保持原逻辑

    // ===== 查询参数 =====
    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $deviceId = isset($input['deviceId']) ? trim($input['deviceId']) : null;

    // ===== 表名 =====
    $apkTable    = 'cainiao_apk';
    $wsTable     = 'cainiao_ws';
    $kamiTable   = 'cainiao_kami';
    $deviceTable = 'cainiao_request_stat_device';

    $where  = [];
    $params = [];

    // ===== 权限控制 =====
    if (!$isAdmin) {

        if ($apkId <= 0) {
            throw new Exception('缺少参数 apk_id');
        }

        $stmt = $pdo->prepare("
            SELECT id FROM {$apkTable}
            WHERE id = :aid AND user_id = :uid
        ");
        $stmt->execute([
            ':aid' => $apkId,
            ':uid' => $userId
        ]);

        if (!$stmt->fetch()) {
            throw new Exception('应用不存在或无权限');
        }

        $where[] = "ws.apk_id = :apkId";
        $params[':apkId'] = $apkId;

    } else {
        if ($apkId > 0) {
            $where[] = "ws.apk_id = :apkId";
            $params[':apkId'] = $apkId;
        }
    }

    if (!empty($deviceId)) {
        $where[] = "ws.device_id LIKE :deviceId";
        $params[':deviceId'] = '%' . $deviceId . '%';
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : '';

    // ===== 总数 =====
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM {$wsTable} ws {$whereSql}
    ");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $pageSize;
    $todayStart = date('Y-m-d 00:00:00');

    // ===== 主查询 =====
    $sqlList = "
        SELECT 
            ws.id,
            ws.apk_id,
            ws.device_id,
            ws.visit_time,
            a.name,
            a.user_id,
            a.version,
            a.package,
            a.icon,

            -- 今日IP数量（来自 ws 表）
            IFNULL(t.today_ip_count, 0) AS today_ip_count,

            -- 今日启动次数（来自新表 request_stat_device）
            IFNULL(s.today_start_count, 0) AS today_start_count,

            -- 卡密状态
            CASE
                WHEN ku.active_cnt > 0 THEN 1
                WHEN ku.expired_cnt > 0 THEN 2
                ELSE 0
            END AS kami_status,

            IFNULL(ku.remain_hours, 0) AS kami_remain_hours

        FROM {$wsTable} ws

        LEFT JOIN {$apkTable} a 
            ON ws.apk_id = a.id

        -- 今日IP统计
        LEFT JOIN (
            SELECT
                device_id,
                COUNT(DISTINCT ip_address) AS today_ip_count
            FROM {$wsTable}
            WHERE visit_time >= :todayStart
            GROUP BY device_id
        ) t ON ws.device_id = t.device_id

        -- 今日启动次数来自新统计表
        LEFT JOIN (
            SELECT
                device_id,
                SUM(visit_count) AS today_start_count
            FROM {$deviceTable}
            WHERE visit_time >= :todayStart
            GROUP BY device_id
        ) s ON ws.device_id = s.device_id

        -- 卡密状态统计
        LEFT JOIN (
            SELECT
                app_id,
                deviceId,

                SUM(
                    CASE 
                        WHEN DATE_ADD(
                            use_at,
                            INTERVAL
                                CASE
                                    WHEN time >= 99999 THEN 99999
                                    ELSE time
                                END
                            HOUR
                        ) > NOW()
                        THEN 1 ELSE 0
                    END
                ) AS active_cnt,

                SUM(
                    CASE 
                        WHEN DATE_ADD(
                            use_at,
                            INTERVAL
                                CASE
                                    WHEN time >= 99999 THEN 99999
                                    ELSE time
                                END
                            HOUR
                        ) <= NOW()
                        THEN 1 ELSE 0
                    END
                ) AS expired_cnt,

                MAX(
                    CASE
                        WHEN DATE_ADD(
                            use_at,
                            INTERVAL
                                CASE
                                    WHEN time >= 99999 THEN 99999
                                    ELSE time
                                END
                            HOUR
                        ) > NOW()
                        THEN TIMESTAMPDIFF(
                            SECOND,
                            NOW(),
                            DATE_ADD(
                                use_at,
                                INTERVAL
                                    CASE
                                        WHEN time >= 99999 THEN 99999
                                        ELSE time
                                    END
                                HOUR
                            )
                        ) / 3600
                        ELSE 0
                    END
                ) AS remain_hours

            FROM {$kamiTable}
            WHERE enabled = 1
              AND use_at IS NOT NULL
            GROUP BY app_id, deviceId
        ) ku
        ON ku.app_id = ws.apk_id
        AND ku.deviceId = ws.device_id

        {$whereSql}
        ORDER BY ws.visit_time DESC
        LIMIT :offset, :limit
    ";

    $stmt = $pdo->prepare($sqlList);

    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }

    $stmt->bindValue(':todayStart', $todayStart);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);

    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'total'    => $total,
        'page'     => $page,
        'pageSize' => $pageSize,
        'list'     => $list
    ];
}


function get_ws_list不支持卡密复用(PDO $pdo, array $input) {

    $user = Auth::check($pdo);
    $userId = $user['id'];
    $isAdmin = $user['role'] === 'admin';

    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = 10;

    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $deviceId = isset($input['deviceId']) ? trim($input['deviceId']) : null;

    $apkTable    = 'cainiao_apk';
    $wsTable     = 'cainiao_ws';
    $kamiTable   = 'cainiao_kami';
    $deviceTable = 'cainiao_request_stat_device';

    $where  = [];
    $params = [];

    // ===== 权限控制 =====
    if (!$isAdmin) {
        if ($apkId <= 0) {
            throw new Exception('缺少参数 apk_id');
        }

        $stmt = $pdo->prepare("
            SELECT id FROM {$apkTable}
            WHERE id = :aid AND user_id = :uid
        ");
        $stmt->execute([
            ':aid' => $apkId,
            ':uid' => $userId
        ]);

        if (!$stmt->fetch()) {
            throw new Exception('应用不存在或无权限');
        }

        $where[] = "ws.apk_id = :apkId";
        $params[':apkId'] = $apkId;

    } else {
        if ($apkId > 0) {
            $where[] = "ws.apk_id = :apkId";
            $params[':apkId'] = $apkId;
        }
    }

    if (!empty($deviceId)) {
        $where[] = "ws.device_id LIKE :deviceId";
        $params[':deviceId'] = "%{$deviceId}%";
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : '';

    // ===== 总数 =====
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM {$wsTable} ws {$whereSql}
    ");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $pageSize;

    // ===== 先分页取当前页数据（关键优化）=====
    $sqlMain = "
        SELECT 
            ws.id,
            ws.apk_id,
            ws.device_id,
            ws.visit_time,
            a.name,
            a.user_id,
            a.version,
            a.package,
            a.icon
        FROM {$wsTable} ws
        LEFT JOIN {$apkTable} a ON ws.apk_id = a.id
        {$whereSql}
        ORDER BY ws.visit_time DESC
        LIMIT :offset, :limit
    ";

    $stmt = $pdo->prepare($sqlMain);

    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);

    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$list) {
        return [
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'list'     => []
        ];
    }
    
    
    
    
    

    // ===== 当前页 device_id 列表 =====
    $deviceIds = array_column($list, 'device_id');
    $inPlaceholders = implode(',', array_fill(0, count($deviceIds), '?'));

    $todayStart = date('Y-m-d 00:00:00');

    // ===== 今日IP统计（只查当前页设备）=====
    $stmt = $pdo->prepare("
        SELECT device_id,
               COUNT(DISTINCT ip_address) AS today_ip_count
        FROM {$wsTable}
        WHERE visit_time >= ?
          AND device_id IN ($inPlaceholders)
        GROUP BY device_id
    ");
    $stmt->execute(array_merge([$todayStart], $deviceIds));
    $ipStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // ===== 今日启动次数统计（只查当前页设备）=====
    $stmt = $pdo->prepare("
        SELECT device_id,
               SUM(visit_count) AS today_start_count
        FROM {$deviceTable}
        WHERE visit_time >= ?
          AND device_id IN ($inPlaceholders)
        GROUP BY device_id
    ");
    $stmt->execute(array_merge([$todayStart], $deviceIds));
    $startStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // ===== 卡密状态统计（只查当前页设备）=====
    $stmt = $pdo->prepare("
        SELECT app_id, deviceId,
               SUM(CASE WHEN DATE_ADD(use_at, INTERVAL LEAST(time,99999) HOUR) > NOW() THEN 1 ELSE 0 END) AS active_cnt,
               MAX(
                   CASE WHEN DATE_ADD(use_at, INTERVAL LEAST(time,99999) HOUR) > NOW()
                   THEN TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(use_at, INTERVAL LEAST(time,99999) HOUR)) / 3600
                   ELSE 0 END
               ) AS remain_hours
        FROM {$kamiTable}
        WHERE enabled = 1
          AND use_at IS NOT NULL
          AND deviceId IN ($inPlaceholders)
        GROUP BY app_id, deviceId
    ");
    $stmt->execute($deviceIds);
    $kamiStats = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $kamiStats[$row['app_id'].'_'.$row['deviceId']] = $row;
    }

    // ===== 合并数据 =====
    foreach ($list as &$row) {

        $did = $row['device_id'];
        $key = $row['apk_id'].'_'.$did;

        $row['today_ip_count']    = isset($ipStats[$did]) ? (int)$ipStats[$did] : 0;
        $row['today_start_count'] = isset($startStats[$did]) ? (int)$startStats[$did] : 0;

        if (isset($kamiStats[$key])) {
            $row['kami_status']       = $kamiStats[$key]['active_cnt'] > 0 ? 1 : 2;
            $row['kami_remain_hours'] = (float)$kamiStats[$key]['remain_hours'];
        } else {
            $row['kami_status']       = 0;
            $row['kami_remain_hours'] = 0;
        }
    }

    return [
        'total'    => $total,
        'page'     => $page,
        'pageSize' => $pageSize,
        'list'     => $list
    ];
}









function get_ws_list(PDO $pdo, array $input) {

    $user = Auth::check($pdo);
    $userId = $user['id'];
    $isAdmin = $user['role'] === 'admin';

    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = 10;

    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $deviceId = isset($input['deviceId']) ? trim($input['deviceId']) : null;

    $apkTable    = 'cainiao_apk';
    $wsTable     = 'cainiao_ws';
    $kamiTable   = 'cainiao_kami';
    $deviceTable = 'cainiao_request_stat_device';

    $where  = [];
    $params = [];

    // ===== 权限控制 =====
    if (!$isAdmin) {
        if ($apkId <= 0) {
            throw new Exception('缺少参数 apk_id');
        }

        $stmt = $pdo->prepare("
            SELECT id FROM {$apkTable}
            WHERE id = :aid AND user_id = :uid
        ");
        $stmt->execute([
            ':aid' => $apkId,
            ':uid' => $userId
        ]);

        if (!$stmt->fetch()) {
            throw new Exception('应用不存在或无权限');
        }

        $where[] = "ws.apk_id = :apkId";
        $params[':apkId'] = $apkId;

    } else {
        if ($apkId > 0) {
            $where[] = "ws.apk_id = :apkId";
            $params[':apkId'] = $apkId;
        }
    }

    if (!empty($deviceId)) {
        $where[] = "ws.device_id LIKE :deviceId";
        $params[':deviceId'] = "%{$deviceId}%";
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : '';

    // ===== 总数 =====
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM {$wsTable} ws {$whereSql}
    ");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $pageSize;

    // ===== 主查询（注意新增字段）=====
    $sqlMain = "
        SELECT 
            ws.id,
            ws.apk_id,
            ws.device_id,
            ws.visit_time,
            a.name,
            a.user_id,
            a.version,
            a.package,
            a.icon,
            a.config_mode,          -- ===== [新增]
            a.reuse_options,        -- ===== [新增]
            a.reuse_apk_id          -- ===== [新增]
        FROM {$wsTable} ws
        LEFT JOIN {$apkTable} a ON ws.apk_id = a.id
        {$whereSql}
        ORDER BY ws.visit_time DESC
        LIMIT :offset, :limit
    ";

    $stmt = $pdo->prepare($sqlMain);

    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);

    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$list) {
        return [
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'list'     => []
        ];
    }

    $deviceIds = array_column($list, 'device_id');
    $inPlaceholders = implode(',', array_fill(0, count($deviceIds), '?'));
    $todayStart = date('Y-m-d 00:00:00');

    // ===== 今日IP统计 =====
    $stmt = $pdo->prepare("
        SELECT device_id,
               COUNT(DISTINCT ip_address) AS today_ip_count
        FROM {$wsTable}
        WHERE visit_time >= ?
          AND device_id IN ($inPlaceholders)
        GROUP BY device_id
    ");
    $stmt->execute(array_merge([$todayStart], $deviceIds));
    $ipStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // ===== 今日启动次数 =====
    $stmt = $pdo->prepare("
        SELECT device_id,
               SUM(visit_count) AS today_start_count
        FROM {$deviceTable}
        WHERE visit_time >= ?
          AND device_id IN ($inPlaceholders)
        GROUP BY device_id
    ");
    $stmt->execute(array_merge([$todayStart], $deviceIds));
    $startStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);


    // =============================
    // ===== [新增] 计算卡密真实 app_id 映射 =====
    // =============================

    $kamiAppIds = [];              // 需要查询卡密的 app_id
    $kamiMap    = [];              // 原 apk_id => 实际使用的 app_id

    foreach ($list as $row) {

        $realAppId = $row['apk_id']; // 默认查自己
        
        if ($row['config_mode'] == 1 && !empty($row['reuse_options'])) {
            
            $domains = json_decode($row['reuse_options'], true);
            
            if (is_array($domains) && in_array('卡密数据', $domains, true)) {

                if (!empty($row['reuse_apk_id'])) {
                    $realAppId = (int)$row['reuse_apk_id'];
                    
                }
            }
        }

        $kamiMap[$row['apk_id']] = $realAppId;
        $kamiAppIds[] = $realAppId;
    }

    $kamiAppIds = array_unique($kamiAppIds);


    // =============================
    // ===== 卡密统计（使用映射后的 app_id）
    // =============================

    if (!empty($kamiAppIds)) {

        $placeholders = implode(',', array_fill(0, count($kamiAppIds), '?'));

        $stmt = $pdo->prepare("
            SELECT app_id, deviceId,
                   SUM(CASE WHEN DATE_ADD(use_at, INTERVAL LEAST(time,99999) HOUR) > NOW() THEN 1 ELSE 0 END) AS active_cnt,
                   MAX(
                       CASE WHEN DATE_ADD(use_at, INTERVAL LEAST(time,99999) HOUR) > NOW()
                       THEN TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(use_at, INTERVAL LEAST(time,99999) HOUR)) / 3600
                       ELSE 0 END
                   ) AS remain_hours
            FROM {$kamiTable}
            WHERE enabled = 1
              AND use_at IS NOT NULL
              AND app_id IN ($placeholders)
              AND deviceId IN ($inPlaceholders)
            GROUP BY app_id, deviceId
        ");

        $stmt->execute(array_merge($kamiAppIds, $deviceIds));

        $kamiStats = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $kamiStats[$row['app_id'].'_'.$row['deviceId']] = $row;
        }
    } else {
        $kamiStats = [];
    }

    // ===== 合并结果 =====
    foreach ($list as &$row) {

        $did = $row['device_id'];
        $realAppId = $kamiMap[$row['apk_id']] ?? $row['apk_id'];
        $key = $realAppId . '_' . $did;

        $row['today_ip_count']    = isset($ipStats[$did]) ? (int)$ipStats[$did] : 0;
        $row['today_start_count'] = isset($startStats[$did]) ? (int)$startStats[$did] : 0;

        if (isset($kamiStats[$key])) {
            $row['kami_status']       = $kamiStats[$key]['active_cnt'] > 0 ? 1 : 2;
            $row['kami_remain_hours'] = (float)$kamiStats[$key]['remain_hours'];
        } else {
            $row['kami_status']       = 0;
            $row['kami_remain_hours'] = 0;
        }
    }

    return [
        'total'    => $total,
        'page'     => $page,
        'pageSize' => $pageSize,
        'list'     => $list
    ];
}












?>
<?php

//142版本以上新版接口用的
function get_ws_list旧(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $isAdmin = $user['role'] === 'admin';

    // 分页参数
    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = isset($input['pageSize']) ? max(1, (int)$input['pageSize']) : 50;
    $pageSize = 10;

    // 查询参数
    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $deviceId = isset($input['deviceId']) ? trim($input['deviceId']) : null;

    // 表名
    $apkTable  = 'cainiao_apk';
    $wsTable   = 'cainiao_ws';
    $kamiTable = 'cainiao_kami';

    $where = [];
    $params = [];

    // 非管理员校验
    if (!$isAdmin) {
        if ($apkId <= 0) {
            throw new Exception('缺少参数 apk_id');
        }

        $stmt = $pdo->prepare("
            SELECT id FROM {$apkTable}
            WHERE id = :aid AND user_id = :uid
        ");
        $stmt->execute([':aid' => $apkId, ':uid' => $userId]);

        if (!$stmt->fetch()) {
            throw new Exception('应用不存在或无权限');
        }

        $where[] = "ws.apk_id = :apkId";
        $params[':apkId'] = $apkId;
    } else {
        if ($apkId > 0) {
            $where[] = "ws.apk_id = :apkId";
            $params[':apkId'] = $apkId;
        }
    }

    if (!empty($deviceId)) {
        $where[] = "ws.device_id LIKE :deviceId";
        $params[':deviceId'] = '%' . $deviceId . '%';
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : '';

    // 总数
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM {$wsTable} ws {$whereSql}
    ");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $pageSize;
    $todayStart = date('Y-m-d 00:00:00');

    // 主查询
    $sqlList = "
        SELECT 
            ws.id,
            ws.apk_id,
            ws.device_id,
            ws.visit_time,
            a.name,
            a.user_id,
            a.version,
            a.package,
            a.icon,

            IFNULL(t.today_ip_count, 0)    AS today_ip_count,
            IFNULL(t.today_start_count, 0) AS today_start_count,

            CASE
                WHEN ku.active_cnt > 0 THEN 1
                WHEN ku.expired_cnt > 0 THEN 2
                ELSE 0
            END AS kami_status,

            IFNULL(ku.remain_hours, 0) AS kami_remain_hours

        FROM {$wsTable} ws
        LEFT JOIN {$apkTable} a 
            ON ws.apk_id = a.id

        -- 今日设备统计：今日IP数量来自 ws；今日启动次数来自 request_stat(visit_count求和)
        LEFT JOIN (
            SELECT
                w.device_id,
                COUNT(DISTINCT w.ip_address) AS today_ip_count,
                IFNULL(s.today_start_count, 0) AS today_start_count
            FROM {$wsTable} w
            LEFT JOIN (
                SELECT
                    device_id,
                    SUM(visit_count) AS today_start_count
                FROM cainiao_request_stat
                WHERE visit_time >= :todayStart
                GROUP BY device_id
            ) s ON w.device_id = s.device_id
            WHERE w.visit_time >= :todayStart
            GROUP BY w.device_id
        ) t ON ws.device_id = t.device_id


-- 卡密状态与剩余时间（修复：强制 app_id + deviceId 关联）
LEFT JOIN (
    SELECT
        app_id,
        deviceId,

        -- 仍有效的卡密数量
        SUM(
            CASE 
                WHEN DATE_ADD(
                    use_at,
                    INTERVAL
                        CASE
                            WHEN time >= 99999 THEN 99999
                            ELSE time
                        END
                    HOUR
                ) > NOW()
                THEN 1 ELSE 0
            END
        ) AS active_cnt,

        -- 已过期的卡密数量
        SUM(
            CASE 
                WHEN DATE_ADD(
                    use_at,
                    INTERVAL
                        CASE
                            WHEN time >= 99999 THEN 99999
                            ELSE time
                        END
                    HOUR
                ) <= NOW()
                THEN 1 ELSE 0
            END
        ) AS expired_cnt,

        -- 剩余时间（取同一 app + device 下最大剩余小时）
        MAX(
            CASE
                WHEN DATE_ADD(
                    use_at,
                    INTERVAL
                        CASE
                            WHEN time >= 99999 THEN 99999
                            ELSE time
                        END
                    HOUR
                ) > NOW()
                THEN TIMESTAMPDIFF(
                    SECOND,
                    NOW(),
                    DATE_ADD(
                        use_at,
                        INTERVAL
                            CASE
                                WHEN time >= 99999 THEN 99999
                                ELSE time
                            END
                        HOUR
                    )
                ) / 3600
                ELSE 0
            END
        ) AS remain_hours

    FROM {$kamiTable}
    WHERE enabled = 1
      AND use_at IS NOT NULL
    GROUP BY app_id, deviceId
) ku
ON ku.app_id = ws.apk_id
AND ku.deviceId = ws.device_id



        {$whereSql}
        ORDER BY ws.visit_time DESC
        LIMIT :offset, :limit
    ";

    $stmt = $pdo->prepare($sqlList);

    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':todayStart', $todayStart);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);

    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'total'    => $total,
        'page'     => $page,
        'pageSize' => $pageSize,
        'list'     => $list
    ];
}
?>


<?php

//142版本以上新版接口用的
function get_list(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $isAdmin = $user['role'] === 'admin';

    // 分页参数
    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = isset($input['pageSize']) ? max(1, (int)$input['pageSize']) : 50;

    // 查询参数
    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $deviceId = isset($input['deviceId']) ? trim($input['deviceId']) : null;

    // 表名
    $apkTable  = 'cainiao_apk';
    $wsTable   = 'cainiao_ws';
    $kamiTable = 'cainiao_kami';

    $where = [];
    $params = [];

    // 非管理员校验
    if (!$isAdmin) {
        if ($apkId <= 0) {
            throw new Exception('缺少参数 apk_id');
        }

        $stmt = $pdo->prepare("
            SELECT id FROM {$apkTable}
            WHERE id = :aid AND user_id = :uid
        ");
        $stmt->execute([':aid' => $apkId, ':uid' => $userId]);

        if (!$stmt->fetch()) {
            throw new Exception('应用不存在或无权限');
        }

        $where[] = "ws.apk_id = :apkId";
        $params[':apkId'] = $apkId;
    } else {
        if ($apkId > 0) {
            $where[] = "ws.apk_id = :apkId";
            $params[':apkId'] = $apkId;
        }
    }

    if (!empty($deviceId)) {
        $where[] = "ws.device_id LIKE :deviceId";
        $params[':deviceId'] = '%' . $deviceId . '%';
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : '';

    // 总数
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM {$wsTable} ws {$whereSql}
    ");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $pageSize;
    $todayStart = date('Y-m-d 00:00:00');

    // 主查询
    $sqlList = "
        SELECT 
            ws.id,
            ws.apk_id,
            ws.device_id,
            ws.visit_time,
            a.name,
            a.user_id,
            a.version,
            a.package,
            a.icon,

            IFNULL(t.today_ip_count, 0)    AS today_ip_count,
            IFNULL(t.today_start_count, 0) AS today_start_count,

            CASE
                WHEN ku.active_cnt > 0 THEN 1
                WHEN ku.expired_cnt > 0 THEN 2
                ELSE 0
            END AS kami_status,

            IFNULL(ku.remain_hours, 0) AS kami_remain_hours

        FROM {$wsTable} ws
        LEFT JOIN {$apkTable} a 
            ON ws.apk_id = a.id

        -- 今日设备统计：今日IP数量来自 ws；今日启动次数来自 request_stat(visit_count求和)
        LEFT JOIN (
            SELECT
                w.device_id,
                COUNT(DISTINCT w.ip_address) AS today_ip_count,
                IFNULL(s.today_start_count, 0) AS today_start_count
            FROM {$wsTable} w
            LEFT JOIN (
                SELECT
                    device_id,
                    SUM(visit_count) AS today_start_count
                FROM cainiao_request_stat
                WHERE visit_time >= :todayStart
                GROUP BY device_id
            ) s ON w.device_id = s.device_id
            WHERE w.visit_time >= :todayStart
            GROUP BY w.device_id
        ) t ON ws.device_id = t.device_id


        -- 卡密状态与剩余时间
        LEFT JOIN (
            SELECT
                deviceId,
                SUM(
                    CASE 
                        WHEN DATE_ADD(use_at, INTERVAL time HOUR) > NOW()
                        THEN 1 ELSE 0 
                    END
                ) AS active_cnt,
                SUM(
                    CASE 
                        WHEN DATE_ADD(use_at, INTERVAL time HOUR) <= NOW()
                        THEN 1 ELSE 0
                    END
                ) AS expired_cnt,
                MAX(
                    CASE
                        WHEN DATE_ADD(use_at, INTERVAL time HOUR) > NOW()
                        THEN TIMESTAMPDIFF(
                            SECOND,
                            NOW(),
                            DATE_ADD(use_at, INTERVAL time HOUR)
                        ) / 3600
                        ELSE 0
                    END
                ) AS remain_hours
            FROM {$kamiTable}
            WHERE enabled = 1
              AND use_at IS NOT NULL
            GROUP BY deviceId
        ) ku ON ws.device_id = ku.deviceId

        {$whereSql}
        ORDER BY ws.visit_time DESC
        LIMIT :offset, :limit
    ";

    $stmt = $pdo->prepare($sqlList);

    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':todayStart', $todayStart);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);

    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 兼容旧版本：将扩展信息拼到 visit_time
foreach ($list as &$row) {

    $lines = [];

    // 今日次数
    $lines[] = '今日启动次数：' . (int)$row['today_start_count'] . '   今日IP数量：' . (int)$row['today_ip_count'];


    // 卡密状态 + 剩余时间（同一行）
    $remainText = formatRemainTime((float)$row['kami_remain_hours']);

    switch ((int)$row['kami_status']) {
        case 1:
            $statusText = '✅ 有效';
            if ($remainText !== '') {
                $statusText .= '（剩余' . $remainText . '）';
            }
            break;

        case 2:
            $statusText = '⚠️ 卡密已过期';
            break;

        default:
            $statusText = '❌ 未使用过任何卡密';
            break;
    }

    $lines[] = '卡密状态：' . $statusText;

    // 追加到 visit_time
    $row['visit_time'] .= "\n" . implode("\n", $lines);
}

unset($row);

    return [
        'total'    => $total,
        'page'     => $page,
        'pageSize' => $pageSize,
        'list'     => $list
    ];
}


// 小时 → X天X小时X分钟（0 不显示）
function formatRemainTime($hours) {
    if ($hours <= 0) {
        return '';
    }

    $totalMinutes = (int)ceil($hours * 60);
    if ($totalMinutes <= 0) {
        $totalMinutes = 1;
    }

    $days = intdiv($totalMinutes, 1440);
    $remain = $totalMinutes % 1440;
    $h = intdiv($remain, 60);
    $m = $remain % 60;

    $text = '';
    if ($days > 0) $text .= $days . '天';
    if ($h > 0)    $text .= $h . '小时';
    if ($m > 0)    $text .= $m . '分钟';

    return $text;
}

?>





<?php
//142以前的旧版本客户端用的
function get_list2(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $isAdmin = $user['role'] === 'admin';

    // 分页参数
    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = isset($input['pageSize']) ? max(1, (int)$input['pageSize']) : 50;

    // 查询参数
    $apkId      = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;  
    $deviceId   = isset($input['deviceId']) ? trim($input['deviceId']) : null;  

    // 表名
    $apkTable = 'cainiao_apk';
    $wsTable  = 'cainiao_ws';

    // WHERE 条件
    $where = [];
    $params = [];

    // -------------------------------
    // 1）非管理员需验证应用归属
    // -------------------------------
    if (!$isAdmin) {
        if ($apkId <= 0) {
            throw new Exception('缺少参数 apk_id');
        }

        // 验证应用归属
        $stmt = $pdo->prepare("
            SELECT id FROM {$apkTable} 
            WHERE id = :aid AND user_id = :uid
        ");
        $stmt->execute([':aid' => $apkId, ':uid' => $userId]);

        if (!$stmt->fetch()) {
            throw new Exception('应用不存在或无权限');
        }

        // 添加过滤条件
        $where[] = "ws.apk_id = :apkId";
        $params[':apkId'] = $apkId;

    } else {

        // -------------------------------
        // 2）管理员可以不传 apk_id
        // -------------------------------
        if ($apkId > 0) {
            $where[] = "ws.apk_id = :apkId";
            $params[':apkId'] = $apkId;
        }
    }

    // -------------------------------
    // 3）deviceId 筛选（可选）
    // -------------------------------
    if (!empty($deviceId)) {
        $where[] = "ws.device_id LIKE :deviceId";
        $params[':deviceId'] = '%' . $deviceId . '%';
    }

    // WHERE 拼接
    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : '';

    // -------------------------------
    // 4）查询总数
    // -------------------------------
    $sqlTotal = "
        SELECT COUNT(*) 
        FROM {$wsTable} ws
        {$whereSql}
    ";
    $stmt = $pdo->prepare($sqlTotal);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // -------------------------------
    // 5）分页查询数据 + 应用信息
    // -------------------------------
    $offset = ($page - 1) * $pageSize;

    $sqlList = "
        SELECT 
            ws.id,
            ws.apk_id,
            ws.device_id,
            ws.visit_time,
            a.name,
            a.user_id,
            a.version,
            a.package,
            a.icon
        FROM {$wsTable} ws
        LEFT JOIN {$apkTable} a ON ws.apk_id = a.id
        {$whereSql}
        ORDER BY ws.visit_time DESC
        LIMIT :offset, :limit
    ";

    $stmt = $pdo->prepare($sqlList);

    // 绑定动态 where 参数
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }

    // 绑定分页参数
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', (int)$pageSize, PDO::PARAM_INT);

    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------
    // 6）返回
    // -------------------------------
    return [
        'total'     => $total,
        'page'      => $page,
        'pageSize'  => $pageSize,
        'list'      => $list
    ];
}
?>