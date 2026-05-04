<?php
header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "访问方式错误";
    exit;
}

require_once __DIR__ . '/../config/db.php';

if (!$pdo || !($pdo instanceof PDO)) {
    echo '无法连接到数据库';
    exit;
}

$appid_get = $_GET['appid'];
$input = trim($_POST['input'] ?? '');
$input = preg_replace('/\s+/', '', $input);
$appId = trim($_POST['appId'] ?? '');
$deviceId = trim($_POST['deviceId'] ?? '');
$time = $_GET['time']; // 可以试用的时长,单位分钟

if ($time < 1) {
    echo "无效的试用时长";
    exit;
}

if (!empty($appid_get)) {
    $appId = $appid_get; // GET 参数优先
}else{
    //未指定appid的，需要检测重定向情况，试用也要被重定向
    $stmt = $pdo->prepare("SELECT apk_id2 FROM cainiao_redirect WHERE apk_id1 = :apk_id1 LIMIT 1");
    $stmt->execute([':apk_id1' => $appId]);
    $redirect = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($redirect && !empty($redirect['apk_id2'])) {
        // 如果 redirect 中存在映射，则用 apk_id2 作为本次应用id
        $appId = $redirect['apk_id2'];
    }
}

if (empty($deviceId)) {
    echo '未知的设备id';
    exit;
}

/*
    在cainiao_trial表中，查询是否存在
    apk_id = $appId 且 device_id = $deviceId 的记录
*/
$stmt = $pdo->prepare("SELECT id, visit_time FROM cainiao_trial WHERE apk_id = ? AND device_id = ? LIMIT 1");
$stmt->execute([$appId, $deviceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$currentDatetime = date('Y-m-d H:i:s');

if (!$row) {

    /*
        未试用过：
        插入记录：
        apk_id = $appId
        device_id = $deviceId
        visit_time = 当前时间
    */
    $insert = $pdo->prepare("INSERT INTO cainiao_trial (apk_id, device_id, visit_time) VALUES (?, ?, ?)");
    $insert->execute([$appId, $deviceId, $currentDatetime]);
    header("x-kami-seconds: ". $time * 60);//卡密剩余秒数
    echo "ok";
    exit;
}

/*
    已试用过：
    判断 visit_time + $time 分钟 是否超时
*/
$visitTime = strtotime($row['visit_time']);
$expireTime = $visitTime + ($time * 60);
$now = time();

if ($now <= $expireTime) {
    // 试用未到期
    $remaining = $expireTime - $now;
    header("x-kami-seconds: ".$remaining);
    echo "ok";
    exit;
}

// 已过期
echo "试用已到期";


?>
