<?php
/*
redis0库为最终请求缓存数据
redis1库为IP归属地
redis2库为apk信息

*/
function debug_echo(){
$json = '{
    "debug": false,
    "disable": false,
    "offline": false,
    "websocket": false,
    "enableHook": false,
    "ban_Root": false,
    "ban_Xposed": false,
    "ban_Emulator": false,
    "ban_VirtualApp": false,
    "ban_DualApp": false,
    "screen_priority": false,
    "blackActivities": [
        "com.didjdk.adbhelper.SettingsActivity1"
    ],
    "black_package": true,
    "black_package_list": [
        
    ],
    "new_black_package_list": [
        
    ],
    "enable_popup_kill_all": false,
    "kill_type": [
        
    ],
    "enable_popup_keywords": false,
    "popup_keywords": [
        "修复版"
    ],
    "popup_newkeywords": [
        
    ],
    "popup_type": [
        "2"
    ],
    "replace": [
        
    ],
    "enable_sp_put": false,
    "sp_put": [
        
    ],
    "enable_sp_get": false,
    "sp_get": [
        
    ],
    "enable_sp": false,
    "sp": [
        
    ],
    "enablePopups": false,
    "popups": [
        
    ],
    "enableImagePopups": false,
    "imagepopups": [],
    "enablehtmlPopups": false,
    "htmlpopups": [],
    "enableMessagePopups": false,
    "Messagepopups": [],
    "enableinputPopups": false,
    "inputpopups": [
        
    ],
    "enabledex": false,
    "dex_list": [
        
    ],
    "newactivity": [],
    "view": []
}';
if(!$_GET['debug']){
    echo encrypt_json($json, '1234567890abcdef');
    http_response_code(200);exit;
}
}
$start = microtime(true);
header('Content-Type: application/json');
//debug_echo();
// 必填参数列表
$requiredParams = ['package', 'version_name', 'version_code', 'appid', 'appkey', 'did'];
foreach ($requiredParams as $param) {
    if (!isset($_POST[$param]) || trim($_POST[$param]) === '') {
        http_response_code(404);
        echo json_encode(['code' => 401, 'message' => "缺少参数: $param"],320);
        //echo json_encode(['code' => 401, 'message' => "缺少参数"],320);
        exit;
    }
}

$package      = $_POST['package'];
$versionName  = $_POST['version_name'];
$versionCode  = $_POST['version_code'];
$appid        = $_POST['appid'];
$appkey       = $_POST['appkey'];
$did          = $_POST['did'];
$keys         = $_POST['key'];


require_once __DIR__ . '/config/db.php';//数据库配置文件
require_once __DIR__ . '/config/redis.php';//Redis统一连接配置
require_once __DIR__ . '/api/utils/Auth.php';//用户鉴权中间件，这里面有个获取系统设置的方法
require_once __DIR__ . '/api/utils/XdbSearcher.php';//IP归属地查询库
require_once __DIR__ . '/api/utils/ConfigHelper.php';//配置生成公共函数（桶推送共用）
if (!$pdo || !($pdo instanceof PDO)) {
    http_response_code(404);
    echo json_encode(['code' => 500, 'message' => '数据库连接失败']);
    exit;
}
//===============================================================链接redis缓存
$redis = getRedisConnection(0);



$redis->select(2);//选择数据库2，作为远程配置临时缓存库
$exists = $redis->get($appid);
if($exists !== false){
    //本次请求的appid有缓存，走缓存
    header('X-Data-Source-apk: redis');
    $apk = json_decode($exists, true);
}else{
    //本次请求的数据无缓存，走查询
    // 查当前 APK 是否存在
    //验证包名
    /*$stmt = $pdo->prepare("SELECT * FROM cainiao_apk WHERE package = :package AND id = :id AND user_id = :user_id LIMIT 1");
    $stmt->execute([':package' => $package, ':id' => $appid, ':user_id' => $appkey]);*/
    //不验证包名
    $stmt = $pdo->prepare("SELECT * FROM cainiao_apk WHERE id = :id AND user_id = :user_id LIMIT 1");
    $stmt->execute([':id' => $appid, ':user_id' => $appkey]);
    $apk = $stmt->fetch(PDO::FETCH_ASSOC);
    $redirect = false;//应用重定向标记
    if (!$apk) {
        //$shell_id = Auth::getSetting($pdo, 'shell', '');//兜底应用配置id
        
        //对于不存在的应用id，先检查是否存在应用id映射，如果存在则使用应用id映射的配置，如果不存在映射关系，则使用后台的最终兜底配置
        $stmt = $pdo->prepare("SELECT apk_id2 FROM cainiao_redirect WHERE apk_id1 = :apk_id1 LIMIT 1");
        $stmt->execute([':apk_id1' => $appid]);
        $redirect = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if ($redirect && !empty($redirect['apk_id2'])) {
            // 如果 redirect 中存在映射，则用 apk_id2 作为兜底 id
            $shell_id = $redirect['apk_id2'];
        } else {
            // 否则进入原来的兜底逻辑（从配置表读取 shell）
            $shell_id = Auth::getSetting($pdo, 'shell', '');
        }
        if(empty($shell_id)){
            //http_response_code(404);
            echo json_encode(['code' => 407, 'message' => '未找到该应用，请先上传应用']);
            exit;
        }
        //检查兜底配置id是否存在
        // 查当前 APK 是否存在 ,不受包名和用户id的限制
        $stmt = $pdo->prepare("SELECT * FROM cainiao_apk WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $shell_id]);
        $apk = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$apk) {
            //http_response_code(404);
            echo json_encode(['code' => 407, 'message' => '未找到该应用，请先上传应用']);
            exit;
        }
        $redirect = true;
    }else{
        //应用存在，也要检查重定向
        $stmt = $pdo->prepare("SELECT apk_id2 FROM cainiao_redirect WHERE apk_id1 = :apk_id1 LIMIT 1");
        $stmt->execute([':apk_id1' => $appid]);
        $redirect = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($redirect && !empty($redirect['apk_id2'])) {
            // 如果 redirect 中存在映射，则用 apk_id2 作为本次应用id
            $apk['id'] = $redirect['apk_id2'];
            $redirect = true;
        }
    }
    $apk['redirect'] = $redirect;
    $apk['sign'] = null;
    $redis->setex($appid, 10800, json_encode($apk,320));//缓存3小时
    header('X-Data-Source-apk: data');
}

$apkId = (int)$apk['id'];
$configMode = (int)$apk['config_mode'];
$reuseApkId = (int)$apk['reuse_apk_id'];
$reuseOptions = json_decode($apk['reuse_options'] ?? '[]', true);
//检查key是否合法以及设备是否被拉黑过
if (!array_key_exists('key', $_POST)) {

    // ① 未提交 key 参数
    $disable  = false;
    $keystate = '未提交key参数';

} else {

    // 已提交 key
    $key = $_POST['key'];

    // ② key 为空（null 或 空字符串）
    if ($key === null || $key === '') {

        $disable  = true;
        $keystate = 'key为空，已禁用';

    }
    // ③ 调试 KEY
    elseif ($key === '[#KEY#]') {

        $disable  = false;
        $keystate = '调试KEY参数';

    }
    // ④ 非调试 KEY，验证合法性 且应用未被重定向
    elseif (!verify_key_redis($redis, $apkId, $appkey, $key) && !$redirect && !$apk['redirect']) {

        $disable  = true;
        $keystate = 'key不合法';

    }
    // ⑤ key 合法，判断设备是否拉黑
    elseif (isDeviceDisabled($pdo, $apkId, $did)) {

        $disable  = true;
        $keystate = '设备被拉黑';

    }
    // ⑥ 全部通过
    else {

        $disable  = false;
        $keystate = '正常通过,不禁用';

    }
}


// 检查复用目标是否合法
if ($configMode === 1) {
    if (!$reuseApkId || $reuseApkId === $apkId) {
        http_response_code(404);
        echo json_encode(['code' => 402, 'message' => '复用配置无效，不能复用自己或为空']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_apk WHERE id = :id");
    $stmt->execute([':id' => $reuseApkId]);
    if ((int)$stmt->fetchColumn() === 0) {
        http_response_code(404);
        echo json_encode(['code' => 400, 'message' => '复用的应用不存在']);
        exit;
    }
}


$deviceId = $_POST['did'];
$ip = $_SERVER['REMOTE_ADDR'];
$ip = getClientIp();//39.128.20.44
header('X-Data-Source-ip: ' . $ip);
$dns_ip = $_POST['system_dns_ip'];
$today = date('Y-m-d');
$cacheTime = Auth::getSetting($pdo, 'cache', 30);

//$deviceId = md5($deviceId.$ip);//将设备id和ip再次md5,得到一个设备id和ip绑定的唯一值

//=========================================================================
//指定IP不记录
$excludeip = Auth::getSetting($pdo, 'excludeip', '');//39.128.*.*,45.76.163.102,172.236.154.193
header('X-Data-Source-excludeip: ' . $excludeip);
// 拆分 IP 列表，逐条模糊匹配
$excluded = false;
$excludeList = explode(',', $excludeip);

foreach ($excludeList as $pattern) {
    $pattern = trim($pattern);
    if ($pattern === '') continue;

    // 将 127.*.*.1 → 127\.\d+\.\d+\.1
    $regex = '/^' . str_replace(['.', '*'], ['\.', '\d+'], $pattern) . '$/';

    if (preg_match($regex, $ip)) {
        $excluded = true;//代表IP在不统计列表中
        break;
    }
}

//=========================================================================IP归属地查询
$ip_location['country'] = '';
$ip_location['region'] = '';
$ip_location['city'] = '';
$ip_location['isp'] = '';
//取IP归属地，永久走redis缓存减少查询压力
$redis->select(1);//选择数据库1，作为IP归属地缓存库
$exists = $redis->get($ip);
if($exists !== false){
    $ip_location = json_decode($exists, true); // 返回结构化数组
    header('X-Data-Source-ip_location: redis');
}else{
    $ip_location = getIpLocation($ip); // 返回结构化数组
    $redis->set($ip, json_encode($ip_location,320));
    header('X-Data-Source-ip_location: data');
}

// =====================================================
// 新版汇总统计（替代旧 cainiao_request_stat 表）
// =====================================================
if(!$excluded){

    /*$todayDate = date('Y-m-d');
    $startTime = $todayDate . ' 00:00:00';
    $endTime   = $todayDate . ' 23:59:59';

    // ================= Redis 首次访问判定（DB5） =================
    $redis->select(5);

    $deviceKey = "stat:device:$apkId:$todayDate:$deviceId";
    $ipKey     = "stat:ip:$apkId:$todayDate:$ip";

    // 原子判断今日是否首次（返回 true 表示是首次）
    $isNewDeviceToday = $redis->setnx($deviceKey, 1);
    if ($isNewDeviceToday) {
        $redis->expire($deviceKey, 86400);
    }
    
    $isNewIpToday = $redis->setnx($ipKey, 1);
    if ($isNewIpToday) {
        $redis->expire($ipKey, 86400);
    }*/
    $todayDate = date('Y-m-d');
    $startTime = $todayDate . ' 00:00:00';
    $endTime   = $todayDate . ' 23:59:59';
    
    // 计算距离明天 00:00 还剩多少秒
    $tomorrowZero = strtotime($todayDate . ' 00:00:00') + 86400;
    $expireSeconds = $tomorrowZero - time();
    if ($expireSeconds <= 0) {
        $expireSeconds = 60; // 兜底，避免极端时间误差
    }
    
    // ================= Redis 首次访问判定（DB5） =================
    $redis->select(5);
    
    $deviceKey = "stat:device:$apkId:$todayDate:$deviceId";
    $ipKey     = "stat:ip:$apkId:$todayDate:$ip";
    
    // 原子判断今日是否首次（同时设置当天剩余过期时间）
    $isNewDeviceToday = $redis->set($deviceKey, 1, ['nx', 'ex' => $expireSeconds]);
    $isNewIpToday     = $redis->set($ipKey, 1, ['nx', 'ex' => $expireSeconds]);


    // ================= 汇总表统计 =================
    $insertSumStmt = $pdo->prepare("
        INSERT INTO cainiao_request_stat_sum
        (apk_id, visit_time, device_sum, ip_sum, request_sum)
        VALUES (:apk_id, :visit_time, :device_inc, :ip_inc, 1)
        ON DUPLICATE KEY UPDATE
            request_sum = request_sum + 1,
            device_sum = device_sum + VALUES(device_sum),
            ip_sum     = ip_sum + VALUES(ip_sum)
    ");

    $insertSumStmt->execute([
        ':apk_id'     => $apkId,
        ':visit_time' => $todayDate,
        ':device_inc' => $isNewDeviceToday ? 1 : 0,
        ':ip_inc'     => $isNewIpToday ? 1 : 0
    ]);


    // ================= 设备统计（无 SELECT） =================
    $deviceStmt = $pdo->prepare("
        INSERT INTO cainiao_request_stat_device
        (apk_id, device_id, visit_count, visit_time,
         ip_address, dns_ip, country, region, city, isp, visit_date)
        VALUES
        (:apk_id, :device_id, 1, :visit_time,
         :ip, :dns_ip, :country, :region, :city, :isp, :visit_date)
        ON DUPLICATE KEY UPDATE
            visit_count = visit_count + 1,
            visit_time  = NOW(),
            ip_address  = :ip,
            dns_ip      = :dns_ip,
            country     = :country,
            region      = :region,
            city        = :city,
            isp         = :isp,
            visit_date  = :visit_date
    ");

    $deviceStmt->execute([
        ':apk_id'    => $apkId,
        ':device_id' => $deviceId,
        ':visit_time'=> $todayDate,
        ':ip'        => $ip,
        ':dns_ip'    => $dns_ip,
        ':country'   => $ip_location['country'],
        ':region'    => $ip_location['region'],
        ':city'      => $ip_location['city'],
        ':isp'       => $ip_location['isp'],
        ':visit_date'=> $todayDate
    ]);


    // ================= IP统计（无 SELECT） =================
    $ipStmt = $pdo->prepare("
        INSERT INTO cainiao_request_stat_ip
        (apk_id, device_id, visit_count, visit_time,
         ip_address, dns_ip, country, region, city, isp, visit_date)
        VALUES
        (:apk_id, :device_id, 1, :visit_time,
         :ip, :dns_ip, :country, :region, :city, :isp, :visit_date)
        ON DUPLICATE KEY UPDATE
            visit_count = visit_count + 1,
            visit_time  = NOW(),
            ip_address  = :ip,
            dns_ip      = :dns_ip,
            country     = :country,
            region      = :region,
            city        = :city,
            isp         = :isp,
            visit_date  = :visit_date
    ");

    $ipStmt->execute([
        ':apk_id'    => $apkId,
        ':device_id' => $deviceId,
        ':visit_time'=> $todayDate,
        ':ip'        => $ip,
        ':dns_ip'    => $dns_ip,
        ':country'   => $ip_location['country'],
        ':region'    => $ip_location['region'],
        ':city'      => $ip_location['city'],
        ':isp'       => $ip_location['isp'],
        ':visit_date'=> $todayDate
    ]);

}
/*if(!$excluded){
    $todayDate = date('Y-m-d');
    
    // 1️⃣ 先确保今日汇总记录存在
    $insertSumStmt = $pdo->prepare("
        INSERT INTO cainiao_request_stat_sum
        (apk_id, visit_time, device_sum, ip_sum, request_sum)
        VALUES (:apk_id, :visit_time, 0, 0, 1)
        ON DUPLICATE KEY UPDATE request_sum = request_sum + 1
    ");
    
    $insertSumStmt->execute([
        ':apk_id'     => $apkId,
        ':visit_time' => $todayDate
    ]);
    
    // 2️⃣ 判断今日是否首次设备访问
    $deviceCheckStmt = $pdo->prepare("
        SELECT id FROM cainiao_request_stat_device
        WHERE apk_id = :apk_id
        AND device_id = :device_id
        AND visit_time BETWEEN :start AND :end
        LIMIT 1
    ");
    
    $startTime = $todayDate . ' 00:00:00';
    $endTime   = $todayDate . ' 23:59:59';
    
    $deviceCheckStmt->execute([
        ':apk_id'    => $apkId,
        ':device_id' => $deviceId,
        ':start'     => $startTime,
        ':end'       => $endTime
    ]);
    
    $isNewDeviceToday = $deviceCheckStmt->fetch(PDO::FETCH_ASSOC) ? false : true;
    
    // 3️⃣ 判断今日是否首次IP访问
    $ipCheckStmt = $pdo->prepare("
        SELECT id FROM cainiao_request_stat_ip
        WHERE apk_id = :apk_id
        AND ip_address = :ip_address
        AND visit_time BETWEEN :start AND :end
        LIMIT 1
    ");
    
    $ipCheckStmt->execute([
        ':apk_id'     => $apkId,
        ':ip_address' => $ip,
        ':start'      => $startTime,
        ':end'        => $endTime
    ]);
    
    $isNewIpToday = $ipCheckStmt->fetch(PDO::FETCH_ASSOC) ? false : true;
    
    
    // 4️⃣ 如果是首次设备/IP访问，更新汇总计数
    if ($isNewDeviceToday || $isNewIpToday) {
    
        $updateFields = [];
        $params = [
            ':apk_id'     => $apkId,
            ':visit_time' => $todayDate
        ];
    
        if ($isNewDeviceToday) {
            $updateFields[] = "device_sum = device_sum + 1";
        }
    
        if ($isNewIpToday) {
            $updateFields[] = "ip_sum = ip_sum + 1";
        }
    
        if (!empty($updateFields)) {
    
            $sql = "
                UPDATE cainiao_request_stat_sum
                SET " . implode(',', $updateFields) . "
                WHERE apk_id = :apk_id
                AND visit_time = :visit_time
            ";
    
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);
        }
    }
    //=========================================================================
    //升级新版统计，先统计设备
    $startTime = $today . ' 00:00:00';
    $endTime = $today . ' 23:59:59';
    
    $stmt = $pdo->prepare("
        SELECT id, visit_count FROM cainiao_request_stat_device 
        WHERE apk_id = :apk_id AND device_id = :device_id 
        AND visit_time BETWEEN :start AND :end
    ");
    $stmt->execute([
        ':apk_id' => $apkId,
        ':device_id' => $deviceId,
        ':start' => $startTime,
        ':end' => $endTime
    ]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $updateStmt = $pdo->prepare("
            UPDATE cainiao_request_stat_device 
            SET visit_count = visit_count + 1, 
                visit_time = NOW(), 
                ip_address = :ip, 
                dns_ip = :dns_ip,
                country = :country,
                region = :region,
                city = :city,
                isp = :isp
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':ip'      => $ip,
            ':dns_ip'  => $dns_ip,
            ':country' => $ip_location['country'],
            ':region'  => $ip_location['region'],
            ':city'    => $ip_location['city'],
            ':isp'     => $ip_location['isp'],
            ':id'      => $existing['id']
        ]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO cainiao_request_stat_device 
            (apk_id, device_id, visit_count, visit_time, ip_address, dns_ip, country, region, city, isp)
            VALUES (:apk_id, :device_id, 1, NOW(), :ip, :dns_ip, :country, :region, :city, :isp)
        ");
        $insertStmt->execute([
            ':apk_id'    => $apkId,
            ':device_id' => $deviceId,
            ':ip'        => $ip,
            ':dns_ip'    => $dns_ip,
            ':country'   => $ip_location['country'],
            ':region'    => $ip_location['region'],
            ':city'      => $ip_location['city'],
            ':isp'       => $ip_location['isp']
        ]);
    }
    
    //升级新版统计，再统计ip
    $startTime = $today . ' 00:00:00';
    $endTime = $today . ' 23:59:59';
    
    $stmt = $pdo->prepare("
        SELECT id, visit_count FROM cainiao_request_stat_ip 
        WHERE apk_id = :apk_id AND ip_address = :ip_address 
        AND visit_time BETWEEN :start AND :end
    ");
    $stmt->execute([
        ':apk_id' => $apkId,
        ':ip_address' => $ip,
        ':start' => $startTime,
        ':end' => $endTime
    ]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $updateStmt = $pdo->prepare("
            UPDATE cainiao_request_stat_ip 
            SET visit_count = visit_count + 1, 
                visit_time = NOW(), 
                ip_address = :ip, 
                dns_ip = :dns_ip,
                country = :country,
                region = :region,
                city = :city,
                isp = :isp
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':ip'      => $ip,
            ':dns_ip'  => $dns_ip,
            ':country' => $ip_location['country'],
            ':region'  => $ip_location['region'],
            ':city'    => $ip_location['city'],
            ':isp'     => $ip_location['isp'],
            ':id'      => $existing['id']
        ]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO cainiao_request_stat_ip 
            (apk_id, device_id, visit_count, visit_time, ip_address, dns_ip, country, region, city, isp)
            VALUES (:apk_id, :device_id, 1, NOW(), :ip, :dns_ip, :country, :region, :city, :isp)
        ");
        $insertStmt->execute([
            ':apk_id'    => $apkId,
            ':device_id' => $deviceId,
            ':ip'        => $ip,
            ':dns_ip'    => $dns_ip,
            ':country'   => $ip_location['country'],
            ':region'    => $ip_location['region'],
            ':city'      => $ip_location['city'],
            ':isp'       => $ip_location['isp']
        ]);
    }

}
*/




//=========================================================================






//=========================================================================

//获取系统设置
/*$stmt = $pdo->query("SELECT key_name, key_value, title, note FROM cainiao_system_setting ORDER BY id ASC");
$settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cacheTime = getSettingValue($settings, 'cache', 30);*/


// 构建缓存文件路径
$cacheDir = __DIR__ . '/temp';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
//$cacheKey = md5($package . $versionName . $versionCode . $appid . $appkey);//不能用设备缓存,否则会生成海量的缓存文件
$cacheKey = $appid;//直接用appid作为键名
//被禁用的设备和未禁用的设备，使用不同的缓存，防止缓存冲突
if($disable){
    $cacheKey = "禁用的设备：" . $appid . "_{$did}";
}

$cacheFile = __DIR__ . "/temp/{$cacheKey}.json";


//检查redis缓存，redis缓存不存在，才会走磁盘缓存
$redis->select(0);//选择数据库0，作为远程配置缓存库
$exists = $redis->get($cacheKey);
if($exists !== false){
    $redis_data = $exists;
    $ttl = $redis->ttl($cacheKey);
    if ($redis_data !== false){
        $end = microtime(true);
        header('X-Data-Source: redis');
        header('X-Data-Source-ttl: ' . $ttl);
        header('X-Data-ttl: ' . ($end - $start));
        echo $redis_data;
        exit;
    }
}
// 检查是否存在有效缓存
if (file_exists($cacheFile)) {
    $fileTime = filemtime($cacheFile);
    if ($fileTime && (time() - $fileTime <= $cacheTime)) {
        header('X-Data-Source: cache');
        echo file_get_contents($cacheFile);exit;//原版逻辑，直接返回缓存文件中的内容
    }
}

//debug_echo();



// 主流程
$response_1 = getResponseData($pdo, $apkId, $did, $disable);
if (!$response_1) {
    http_response_code(405);
    echo json_encode(['code' => 405, 'message' => '未找到配置']);
    exit;
}

$response = $response_1;

if ($configMode === 1) {
    $response_2 = getResponseData($pdo, $reuseApkId, $did, $disable);
    if (!$response_2) {
        http_response_code(406);
        echo json_encode(['code' => 406, 'message' => '复用应用配置不存在']);
        exit;
    }

    // 配置项映射
    $map = [
        '全屏弹窗' => ['enablePopups', 'popups'],
        '图片弹窗' => ['enableImagePopups', 'imagepopups'],
        'HTML弹窗' => ['enablehtmlPopups', 'htmlpopups'],
        '文字弹窗' => ['enableMessagePopups', 'Messagepopups'],
        '输入框弹窗' => ['enableinputPopups', 'inputpopups'],
        '系统文字弹窗' => ['enable_popup_keywords', 'popup_keywords', 'popup_type'],
        'SP写入劫持' => ['enable_sp_put', 'sp_put'],
        'SP读取劫持' => ['enable_sp_get', 'sp_get'],
        'SP重写' => ['enable_sp', 'sp'],
        '通杀拦截' => ['enable_popup_kill_all', 'kill_type'],
        'activity拦截' => ['blackActivities'],
        '关键词拦截' => ['enable_popup_keywords', 'popup_keywords'],
        'URI劫持' => ['replace'],
        '静默配置' => ['black_package', 'black_package_list'],
        '包名检测' => ['black_package', 'new_black_package_list']
    ];

    foreach ($reuseOptions as $key) {
        if (isset($map[$key])) {
            foreach ($map[$key] as $field) {
                if (isset($response_2[$field])) {
                    $response[$field] = $response_2[$field];
                }
            }
        }
    }
}

$response['appid'] = $apkId;
$response['appkey'] = $appkey;
$response['key'] = $keys;
$response['keystate'] = $keystate;

$key = '1234567890abcdef'; // 必须是16字节长度的密钥,必须和壳里的密钥一致
$json = json_encode($response, 320);//明文

//$cacheFile = __DIR__ . "/temp/{$cacheKey}.json";
//file_put_contents($cacheFile.".明文.json", $json);//将明文写入缓存,调试用
if($disable){
    file_put_contents(__DIR__ . "/temp/AAA明文{$cacheKey}.json", $json);//将明文写入缓存,调试用
}

if($_GET['debug'] == 1){
    echo $json;//输出明文
    exit;
}

$json = encrypt_json($json, $key);//密文
//$redis->setex($cacheKey, $cacheTime, $json);//将密文写入redis缓存
//file_put_contents($cacheFile, $json);//将密文写入缓存
// 1. 先尝试写入Redis
$redis->select(0);//选择数据库0，作为远程配置缓存库
$redisResult = $redis->setex($cacheKey, $cacheTime, $json);
if ($redisResult === true) {
    // Redis写入成功，不再写磁盘
    header('X-Data-Source: database-redis');//返回给前端本次是否命中缓存
} else {
    // Redis写入失败，降级到磁盘缓存
    header('X-Data-Source: database-disk');//返回给前端本次是否命中缓存
    file_put_contents($cacheFile, $json);
}

$end = microtime(true);
header('X-Data-ttl: ' . ($end - $start));
echo $json;//输出密文



// 以下函数已提取到 api/utils/ConfigHelper.php：
// isDebugIP, getResponseData, getSpData, getImagePopups, getMessagePopups,
// fetchCol, fetchMap, getInputPopups, getHtmlPopups, getSettingValue, encrypt_json, isAppUserVip

// ---- 以下为 shell.php 独有函数 ----

// （旧函数已删除，见 ConfigHelper.php）
function decrypt_json($cipherText, $key) {
    // base64 解码
    $data = base64_decode($cipherText, true);
    if ($data === false || strlen($data) <= 16) {
        return false; // 数据非法
    }
    // 取前16字节作为 IV
    $iv = substr($data, 0, 16);
    // 剩余部分为密文
    $encrypted = substr($data, 16);
    // 使用 AES-128-CBC 解密
    $json = openssl_decrypt(
        $encrypted,
        'AES-128-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );
    return $json; // 解密失败时返回 false
}


function getIpLocation($ip) {
    static $searcher = null;
    static $cache = [];

    // 检查 IP 是否有效
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return [
            'ip'       => $ip,
            'country'  => '',
            'region'   => '',
            'city'     => '',
            'isp'      => '',
            'location' => '无效IP'
        ];
    }

    // 先检查缓存
    if (isset($cache[$ip])) {
        return $cache[$ip];
    }

    // 初始化搜索器
    if ($searcher === null) {
        $dbFile = __DIR__ . '/bin/ip2region.xdb';
        $buff = XdbSearcher::loadContentFromFile($dbFile);
        if ($buff === null) {
            throw new Exception("无法加载 IP 数据库文件: $dbFile");
        }
        $searcher = XdbSearcher::newWithBuffer($buff);
    }

    // 查询 IP
    $region = $searcher->search($ip);
    if ($region === null) {
        $result = [
            'ip'       => $ip,
            'country'  => '',
            'region'   => '',
            'city'     => '',
            'isp'      => '',
            'location' => '未知'
        ];
        $cache[$ip] = $result;
        return $result;
    }

    // 解析结果
    $parts = explode('|', $region);
    $result = [
        'ip'       => $ip,
        'country'  => $parts[0] ?? '',
        'region'   => $parts[2] ?? '',
        'city'     => $parts[3] ?? '',
        'isp'      => $parts[4] ?? '',
        'location' => trim(($parts[0] ?? '') . ' ' . ($parts[2] ?? '') . ' ' . ($parts[3] ?? ''))
    ];

    // 缓存结果
    $cache[$ip] = $result;

    return $result;
}
/*
function getIpLocation($ip) {
    static $searcher = null;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return [
            'ip' => $ip,
            'country' => '',
            'region' => '',
            'city' => '',
            'isp' => '',
            'location' => '无效IP'
        ];
    }

    if ($searcher === null) {
        $dbFile = __DIR__ . '/bin/ip2region.xdb';
        $searcher = XdbSearcher::newWithFileOnly($dbFile);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        // IPv4，正常查询
        $region = $searcher->search($ip);
    } else {
        // IPv6 或无效 IP，跳过或自定义处理
        $region = "IPV6未知";
    }
    //$region = $searcher->search($ip);
    $parts = explode('|', $region);

    return [
        'ip' => $ip,
        'country' => $parts[0] ?? '',
        'region' => $parts[2] ?? '',
        'city' => $parts[3] ?? '',
        'isp' => $parts[4] ?? '',
        'location' => trim(($parts[0] ?? '') . ' ' . ($parts[2] ?? '') . ' ' . ($parts[3] ?? ''))
    ];
}*/
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // 多级代理会返回多个IP，取第一个
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }

    return $_SERVER['REMOTE_ADDR'];
}


function verify_key($appId, $userId, $key) {
    $secret = md5($appId . $userId);
    $plain  = $appId . $userId . $secret;
    return password_verify($plain, $key);
}
function verify_key_redis($redis, $appId, $userId, $key) {

    // Redis 不可用时，直接走原逻辑
    if (!$redis) {
        $secret = md5($appId . $userId);
        return password_verify($appId . $userId . $secret, $key);
    }

    $cacheKey = 'verify:' . md5($appId . ':' . $userId . ':' . $key);
    $redis->select(4);
    if ($redis->get($cacheKey)) {
        return true;
    }

    $secret = md5($appId . $userId);
    $plain  = $appId . $userId . $secret;

    $ok = password_verify($plain, $key);

    if ($ok) {
        $redis->setex($cacheKey, 60 * 60 * 24, 1);
    }

    return $ok;
}



/**
 * 检测设备是否在黑名单中
 *
 * @param PDO    $pdo
 * @param int    $apkId
 * @param string $deviceId
 * @return bool  true=已拉黑，false=未拉黑
 */
function isDeviceDisabled(PDO $pdo, int $apkId, string $deviceId): bool {
    if ($apkId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM cainiao_disable 
         WHERE appid = :appid 
           AND deviceId = :deviceId 
           AND enable = 1
         LIMIT 1"
    );
    $stmt->execute([
        ':appid'    => $apkId,
        ':deviceId' => $deviceId
    ]);

    return (bool)$stmt->fetchColumn();
}
// isAppUserVip 已提取到 ConfigHelper.php
