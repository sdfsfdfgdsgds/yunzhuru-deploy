<?php

/*function fix_missing_ip_location(PDO $pdo, array $input) {
    $table = 'cainiao_request_stat';

    // 查询最多30条归属地字段缺失的记录
    $sql = "
        SELECT id, ip_address 
        FROM `$table`
        WHERE 
            (`country` IS NULL OR `country` = '') OR
            (`region` IS NULL OR `region` = '') OR
            (`city` IS NULL OR `city` = '') OR
            (`isp` IS NULL OR `isp` = '')
        LIMIT 300
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    foreach ($rows as $row) {
        $ip = $row['ip_address'];
        $location = Auth::getIpLocation($ip);

        // 确保返回结构完整
        if (!is_array($location)) continue;

        $update = $pdo->prepare("
            UPDATE `$table`
            SET 
                country = :country,
                region  = :region,
                city    = :city,
                isp     = :isp
            WHERE id = :id
        ");
        $update->execute([
            ':country' => $location['country'],
            ':region'  => $location['region'],
            ':city'    => $location['city'],
            ':isp'     => $location['isp'],
            ':id'      => $row['id']
        ]);

        $updated++;
    }

    return [
        'processed' => count($rows),
        'updated'   => $updated
    ];
}*/


function dns_anomaly_list(PDO $pdo, array $input) {

    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = isset($input['pageSize']) ? max(1, (int)$input['pageSize']) : 50;
    $start    = isset($input['start']) ? $input['start'] : date('Y-m-d 00:00:00', strtotime('-1 days'));
    $end      = isset($input['end'])   ? $input['end']   : date('Y-m-d 23:59:59');

    $apkTable  = 'cainiao_apk';
    $statTable = 'cainiao_request_stat_ip'; // ✅ 使用新表

    $serverIp = $_SERVER['SERVER_ADDR'] ?? '';
    $serverIp = getPublicIP();
    $serverIp = Auth::getSetting($pdo, "serviceip", $serverIp);

    $params = [
        ':start'     => $start,
        ':end'       => $end,
        ':server_ip' => $serverIp
    ];

    // ================= 统计总数（使用索引字段） =================
    $countSql = "
        SELECT COUNT(*)
        FROM `$statTable` s
        WHERE 
            s.visit_time BETWEEN :start AND :end
            AND s.dns_ip IS NOT NULL
            AND s.dns_ip <> :server_ip
    ";

    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $pageSize;

    // ================= 主查询（无子查询，无GROUP） =================
    $sql = "
        SELECT 
            s.*,
            a.name AS apk_name
        FROM `$statTable` s
        LEFT JOIN `$apkTable` a ON s.apk_id = a.id
        WHERE 
            s.visit_time BETWEEN :start AND :end
            AND s.dns_ip IS NOT NULL
            AND s.dns_ip <> :server_ip
        ORDER BY s.id DESC
        LIMIT $offset, $pageSize
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ================= 归属地修复 =================
    foreach ($list as &$row) {

        $needUpdate = empty($row['country']) || empty($row['region']) 
                   || empty($row['city']) || empty($row['isp']);

        if ($needUpdate) {

            $location = Auth::getIpLocation($row['ip_address']);

            $row['country'] = $location['country'];
            $row['region']  = $location['region'];
            $row['city']    = $location['city'];
            $row['isp']     = $location['isp'];

            $update = $pdo->prepare("
                UPDATE `$statTable`
                SET country = :country, region = :region, city = :city, isp = :isp
                WHERE id = :id
            ");

            $update->execute([
                ':country' => $location['country'],
                ':region'  => $location['region'],
                ':city'    => $location['city'],
                ':isp'     => $location['isp'],
                ':id'      => $row['id']
            ]);
        }

        $row['ip_location'] = [
            'ip'       => $row['ip_address'],
            'country'  => $row['country'],
            'region'   => $row['region'],
            'city'     => $row['city'],
            'isp'      => $row['isp'],
            'location' => trim($row['country'] . ' ' . $row['region'] . ' ' . $row['city'])
        ];
    }

    return [
        'total'    => $total,
        'page'     => $page,
        'pageSize' => $pageSize,
        'list'     => $list,
        'serverIp' => $serverIp
    ];
}


function dns_anomaly_list旧版(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = $user['id'];
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = isset($input['pageSize']) ? max(1, (int)$input['pageSize']) : 50;
    $start    = isset($input['start']) ? $input['start'] : date('Y-m-d 00:00:00', strtotime('-1 days'));
    $end      = isset($input['end']) ? $input['end'] : date('Y-m-d 23:59:59');

    $apkTable  = 'cainiao_apk';
    $statTable = 'cainiao_request_stat';

    $serverIp = $_SERVER['SERVER_ADDR'];
    $serverIp = getPublicIP();
    $serverIp = Auth::getSetting($pdo,"serviceip",$serverIp);//若未设置服务器IP则自动获取服务器IP

    $where = [];
    $params = [];

    $where[] = "`s`.`visit_time` BETWEEN :start AND :end";
    $params[':start'] = $start;
    $params[':end']   = $end;

    $where[] = "`s`.`dns_ip` IS NOT NULL AND `s`.`dns_ip` <> :server_ip";
    $params[':server_ip'] = $serverIp;

    $whereSql = implode(' AND ', $where);

    // 统计去重后记录总数
    $countSql = "
        SELECT COUNT(*) FROM (
            SELECT MAX(s.id) AS id
            FROM `$statTable` s
            LEFT JOIN `$apkTable` a ON s.apk_id = a.id
            WHERE $whereSql
            GROUP BY s.device_id, s.ip_address
        ) AS temp
    ";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $pageSize;

    // 主查询：取每组最新记录
    $sql = "
        SELECT 
            s.*,
            a.name AS apk_name
        FROM `$statTable` s
        LEFT JOIN `$apkTable` a ON s.apk_id = a.id
        INNER JOIN (
            SELECT MAX(id) AS id
            FROM `$statTable`
            WHERE visit_time BETWEEN :start AND :end
              AND dns_ip IS NOT NULL AND dns_ip <> :server_ip
            GROUP BY device_id, ip_address
            ORDER BY NULL
            LIMIT $offset, $pageSize
        ) latest ON s.id = latest.id
        ORDER BY s.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 归属地字段缺失时，调用查询并更新
    foreach ($list as &$row) {
        $needUpdate = empty($row['country']) || empty($row['region']) || empty($row['city']) || empty($row['isp']);
        if ($needUpdate) {
            $location = Auth::getIpLocation($row['ip_address']);
            $row['country'] = $location['country'];
            $row['region']  = $location['region'];
            $row['city']    = $location['city'];
            $row['isp']     = $location['isp'];

            // 更新数据库
            $update = $pdo->prepare("
                UPDATE `$statTable`
                SET country = :country, region = :region, city = :city, isp = :isp
                WHERE id = :id
            ");
            $update->execute([
                ':country' => $location['country'],
                ':region'  => $location['region'],
                ':city'    => $location['city'],
                ':isp'     => $location['isp'],
                ':id'      => $row['id']
            ]);
        }

        $row['ip_location'] = [
            'ip'       => $row['ip_address'],
            'country'  => $row['country'],
            'region'   => $row['region'],
            'city'     => $row['city'],
            'isp'      => $row['isp'],
            'location' => trim($row['country'] . ' ' . $row['region'] . ' ' . $row['city'])
        ];
    }

    return [
        'total'    => $total,
        'page'     => $page,
        'pageSize' => $pageSize,
        'list'     => $list,
        'serverIp' => $serverIp
    ];
}

function getPublicIP() {
    static $ip = null;
    if ($ip !== null) return $ip;

    // 查询主机的公网IP
    $ip = gethostbyname(gethostname());

    // 若返回仍是内网IP，可尝试访问外部服务（仅当必要时）
    if (filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^(10\.|172\.1[6-9]|172\.2[0-9]|172\.3[01]|192\.168\.)/', $ip)) {
        return $ip;
    }

    // 备选方案（需服务器允许访问外网）
    $response = @file_get_contents('https://api.ipify.org');
    if ($response && filter_var($response, FILTER_VALIDATE_IP)) {
        $ip = $response;
    }

    return $ip ?: '127.0.0.1';
}