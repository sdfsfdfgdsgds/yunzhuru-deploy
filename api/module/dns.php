<?php




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