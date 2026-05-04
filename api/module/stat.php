<?php
/*function apk_request_stat(PDO $pdo, array $input) {
    // 记录开始时间（毫秒）
    $startTime = microtime(true);
    $user = Auth::check($pdo);
    $userId = $user['id'];

    // 解析参数
    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = isset($input['pageSize']) ? max(1, (int)$input['pageSize']) : 50;
    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $start    = isset($input['start']) ? $input['start'] : '';
    $end      = isset($input['end']) ? $input['end'] : '';
    $name = isset($input['name']) ? trim($input['name']) : '';
    $sort = isset($input['sort']) ? (int)$input['sort'] : 0;
    $uid = isset($input['uid']) ? (int)$input['uid'] : 0;
    // 默认时间范围：当天
    if (!$start || !$end) {
        $start = date('Y-m-d 00:00:00');
        $end   = date('Y-m-d 23:59:59');
    }
    
    switch ($sort) {
        case 1:
            $orderBy = "ip_count DESC";
            break;
        case 2:
            $orderBy = "device_count DESC";
            break;
        case 3:
            $orderBy = "total_visits DESC";
            break;
        default:
            $orderBy = "a.id DESC";
    }

    // 表名
    $apkTable = 'cainiao_apk';
    $statTable = 'cainiao_request_stat';

    // 构建 WHERE 条件
    $where = [];
    $params = [];

    //$where[] = "`a`.`user_id` = :user_id";
    //$params[':user_id'] = $userId;
    
    // UID 查询权限控制
    if ($user['role'] === 'admin') {
        // 管理员可查任意用户
        if ($uid > 0) {
            $where[] = "`a`.`user_id` = :uid";
            $params[':uid'] = $uid;
        }
    } else {
        // 普通用户只能查自己的
        $where[] = "`a`.`user_id` = :user_id";
        $params[':user_id'] = $userId;
    }


    $where[] = "`s`.`visit_time` BETWEEN :start AND :end";
    $params[':start'] = $start;
    $params[':end'] = $end;

    if ($apkId > 0) {
        $where[] = "`a`.`id` = :apk_id";
        $params[':apk_id'] = $apkId;
    }
    if ($name !== '') {
        $where[] = "`a`.`name` LIKE :name";
        $params[':name'] = "%{$name}%";
    }

    $whereSql = implode(' AND ', $where);

    // 统计总数用于分页
    $countSql = "SELECT COUNT(DISTINCT a.id) FROM `$apkTable` a
                 LEFT JOIN `$statTable` s ON a.id = s.apk_id AND s.visit_time BETWEEN :start AND :end
                 WHERE $whereSql";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // 分页
    $offset = ($page - 1) * $pageSize;

    // 主查询
    $sql = "SELECT 
                a.id AS apk_id,
                a.name AS apk_name,
                COUNT(DISTINCT s.ip_address) AS ip_count,
                COUNT(DISTINCT s.device_id) AS device_count,
                SUM(s.visit_count) AS total_visits
            FROM `$apkTable` a
            LEFT JOIN `$statTable` s ON a.id = s.apk_id AND s.visit_time BETWEEN :start AND :end
            WHERE $whereSql
            GROUP BY a.id
            ORDER BY $orderBy
            LIMIT $offset, $pageSize";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $costMs = round((microtime(true) - $startTime) * 1000, 2);
    return [
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'cost_ms'  => $costMs,
            'list' => $list
        ];
}*/
function apk_request_stat(PDO $pdo, array $input) {

    $startTime = microtime(true);

    $user   = Auth::check($pdo);
    $userId = $user['id'];

    // ===== 解析参数 =====
    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = isset($input['pageSize']) ? max(1, (int)$input['pageSize']) : 50;
    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $start    = isset($input['start']) ? $input['start'] : '';
    $end      = isset($input['end']) ? $input['end'] : '';
    $name     = isset($input['name']) ? trim($input['name']) : '';
    $sort     = isset($input['sort']) ? (int)$input['sort'] : 0;
    $uid      = isset($input['uid']) ? (int)$input['uid'] : 0;

    // ===== 默认时间：当天 =====
    if (!$start || !$end) {
        $start = date('Y-m-d');
        $end   = date('Y-m-d');
    } else {
        // 新表是 DATE 类型，截取前10位
        $start = substr($start, 0, 10);
        $end   = substr($end, 0, 10);
    }

    // ===== 排序规则 =====
    switch ($sort) {
        case 1:
            $orderBy = "ip_count DESC";
            break;
        case 2:
            $orderBy = "device_count DESC";
            break;
        case 3:
            $orderBy = "total_visits DESC";
            break;
        default:
            $orderBy = "a.id DESC";
    }

    $apkTable    = 'cainiao_apk';
    $sumTable    = 'cainiao_request_stat_sum';
    $deviceTable = 'cainiao_request_stat_device';
    $ipTable     = 'cainiao_request_stat_ip';

    // ===== 构建 WHERE 条件 =====
    $where  = [];
    $params = [];

    // 权限控制
    if ($user['role'] === 'admin') {
        if ($uid > 0) {
            $where[] = "a.user_id = :uid";
            $params[':uid'] = $uid;
        }
    } else {
        $where[] = "a.user_id = :user_id";
        $params[':user_id'] = $userId;
    }

    if ($apkId > 0) {
        $where[] = "a.id = :apk_id";
        $params[':apk_id'] = $apkId;
    }

    if ($name !== '') {
        $where[] = "a.name LIKE :name";
        $params[':name'] = "%{$name}%";
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // ===== 统计总数（分页）=====
    $countSql = "SELECT COUNT(*) FROM `$apkTable` a $whereSql";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // ===== 分页 =====
    $offset = ($page - 1) * $pageSize;

    // ===== 主查询 =====
    // 单天查询：直接用 sum 表（Redis 保证单日去重准确，且速度快）
    // 多天查询：用明细表 COUNT(DISTINCT) 做真实跨天去重
    $isSingleDay = ($start === $end);

    if ($isSingleDay) {
        // 单天：sum 表即可，设备数/IP数 当天准确
        $sql = "
            SELECT
                a.id AS apk_id,
                a.name AS apk_name,
                COALESCE(SUM(s.request_sum), 0) AS total_visits,
                COALESCE(SUM(s.device_sum), 0) AS device_count,
                COALESCE(SUM(s.ip_sum), 0) AS ip_count
            FROM `$apkTable` a
            LEFT JOIN `$sumTable` s
                ON a.id = s.apk_id
                AND s.visit_time = :start
            $whereSql
            GROUP BY a.id
            ORDER BY $orderBy
            LIMIT $offset, $pageSize
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [':start' => $start]));
    } else {
        // 多天：明细表做真实去重
        $sql = "
            SELECT
                a.id AS apk_id,
                a.name AS apk_name,
                COALESCE(sm.total_visits, 0) AS total_visits,
                COALESCE(dv.device_count, 0) AS device_count,
                COALESCE(ip.ip_count, 0) AS ip_count
            FROM `$apkTable` a
            LEFT JOIN (
                SELECT apk_id, SUM(request_sum) AS total_visits
                FROM `$sumTable`
                WHERE visit_time BETWEEN :s1 AND :e1
                GROUP BY apk_id
            ) sm ON a.id = sm.apk_id
            LEFT JOIN (
                SELECT apk_id, COUNT(DISTINCT device_id) AS device_count
                FROM `$deviceTable`
                WHERE visit_date BETWEEN :s2 AND :e2
                GROUP BY apk_id
            ) dv ON a.id = dv.apk_id
            LEFT JOIN (
                SELECT apk_id, COUNT(DISTINCT ip_address) AS ip_count
                FROM `$ipTable`
                WHERE visit_date BETWEEN :s3 AND :e3
                GROUP BY apk_id
            ) ip ON a.id = ip.apk_id
            $whereSql
            ORDER BY $orderBy
            LIMIT $offset, $pageSize
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [
            ':s1' => $start, ':e1' => $end,
            ':s2' => $start, ':e2' => $end,
            ':s3' => $start, ':e3' => $end,
        ]));
    }

    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $costMs = round((microtime(true) - $startTime) * 1000, 2);

    return [
        'total'     => $total,
        'page'      => $page,
        'pageSize'  => $pageSize,
        'cost_ms'   => $costMs,
        'list'      => $list
    ];
}



/*function apk_region_stat(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $startTime = microtime(true);
    // 解析参数
    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = isset($input['pageSize']) ? max(1, (int)$input['pageSize']) : 50;
    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $start    = isset($input['start']) ? $input['start'] : '';
    $end      = isset($input['end']) ? $input['end'] : '';

    if (!$start || !$end) {
        $start = date('Y-m-d 00:00:00');
        $end   = date('Y-m-d 23:59:59');
    }

    $apkTable = 'cainiao_apk';
    $statTable = 'cainiao_request_stat';

    $where = [];
    $params = [];

    if($user['role'] !== 'admin') {
        $where[] = "`a`.`user_id` = :user_id";
        $params[':user_id'] = $userId;
    }

    $where[] = "`s`.`visit_time` BETWEEN :start AND :end";
    $params[':start'] = $start;
    $params[':end']   = $end;

    if ($apkId > 0) {
        $where[] = "`a`.`id` = :apk_id";
        $params[':apk_id'] = $apkId;
    }

    $whereSql = implode(' AND ', $where);

    // 统计总数用于分页（唯一省份数）
    $countSql = "SELECT COUNT(DISTINCT IFNULL(NULLIF(s.region, ''), '未知')) FROM `$apkTable` a
                 LEFT JOIN `$statTable` s ON a.id = s.apk_id AND s.visit_time BETWEEN :start AND :end
                 WHERE $whereSql";

    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // 分页
    $offset = ($page - 1) * $pageSize;

    // 主查询：按省份聚合
    $sql = "SELECT 
                IFNULL(NULLIF(s.region, ''), '未知') AS region,
                COUNT(DISTINCT s.device_id) AS device_count,
                COUNT(DISTINCT s.ip_address) AS ip_count,
                SUM(s.visit_count) AS total_visits
            FROM `$apkTable` a
            LEFT JOIN `$statTable` s ON a.id = s.apk_id AND s.visit_time BETWEEN :start AND :end
            WHERE $whereSql
            GROUP BY region
            ORDER BY total_visits DESC
            LIMIT $offset, $pageSize";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $costMs = round((microtime(true) - $startTime) * 1000, 2);
    return [
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'cost_ms'  => $costMs,
        'list' => $list
    ];
}*/


function apk_region_stat(PDO $pdo, array $input) {

    $user = Auth::check($pdo);
    $userId = $user['id'];
    $startTime = microtime(true);

    // ===== 解析参数 =====
    $page     = isset($input['page']) ? max(1, (int)$input['page']) : 1;
    $pageSize = isset($input['pageSize']) ? max(1, (int)$input['pageSize']) : 50;
    $apkId    = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $start    = isset($input['start']) ? $input['start'] : '';
    $end      = isset($input['end']) ? $input['end'] : '';

    // 新结构使用 DATETIME，但我们统一按日期处理
    if (!$start || !$end) {
        $start = date('Y-m-d');
        $end   = date('Y-m-d');
    } else {
        $start = substr($start, 0, 10);
        $end   = substr($end, 0, 10);
    }

    $apkTable  = 'cainiao_apk';
    $statTable = 'cainiao_request_stat_ip';

    $where  = [];
    $params = [];

    // 权限控制
    if ($user['role'] !== 'admin') {
        $where[] = "a.user_id = :user_id";
        $params[':user_id'] = $userId;
    }

    $where[] = "s.visit_date BETWEEN :start AND :end";
    $params[':start'] = $start;
    $params[':end']   = $end;

    if ($apkId > 0) {
        $where[] = "a.id = :apk_id";
        $params[':apk_id'] = $apkId;
    }

    $whereSql = implode(' AND ', $where);

    // ===== 统计总省份数（分页）=====
    $countSql = "
        SELECT COUNT(DISTINCT IFNULL(NULLIF(s.region, ''), '未知'))
        FROM `$apkTable` a
        LEFT JOIN `$statTable` s 
            ON a.id = s.apk_id
        WHERE $whereSql
    ";

    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // ===== 分页 =====
    $offset = ($page - 1) * $pageSize;

    // ===== 主查询：按省份聚合 =====
    $sql = "
        SELECT 
            IFNULL(NULLIF(s.region, ''), '未知') AS region,
            COUNT(DISTINCT s.device_id) AS device_count,
            COUNT(DISTINCT s.ip_address) AS ip_count,
            SUM(s.visit_count) AS total_visits
        FROM `$apkTable` a
        LEFT JOIN `$statTable` s 
            ON a.id = s.apk_id
        WHERE $whereSql
        GROUP BY region
        ORDER BY total_visits DESC
        LIMIT $offset, $pageSize
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $costMs = round((microtime(true) - $startTime) * 1000, 2);

    return [
        'total'     => $total,
        'page'      => $page,
        'pageSize'  => $pageSize,
        'cost_ms'   => $costMs,
        'list'      => $list
    ];
}
