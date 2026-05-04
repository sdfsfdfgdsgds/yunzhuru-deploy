<?php
header('Content-Type: text/plain; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    echo "访问方式错误";
    exit;
}


require_once __DIR__ . '/../config/db.php';
if (!$pdo || !($pdo instanceof PDO)) {
    echo '无法连接到数据库';
    exit;
}

// ===== 获取 GET 参数 data =====
$dataType = trim($_GET['data'] ?? '');
$display = $_GET['display'];

function sendResponse($statusCode, $msg, $data = null) {
    global $dataType;
    if ($dataType === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => $statusCode,
            'message' => $msg,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo $msg;
    }
    exit;
}

$input = trim($_POST['input'] ?? '');
$input = preg_replace('/\s+/', '', $input);
$package = trim($_POST['package'] ?? '');
$version = trim($_POST['version_name'] ?? '');
$appId = trim($_POST['appId'] ?? '');
$deviceId = trim($_POST['deviceId'] ?? '');
$version_shell = trim($_POST['version_shell'] ?? '');





$appid_get = $_GET['appid'];
if(!empty($appid_get)){
    $appId = $appid_get;//由接口get参数指定appid,实现卡密互通
}else{
    //未指定appid的，需要检测重定向情况，卡密也要被重定向
    $stmt = $pdo->prepare("SELECT apk_id2 FROM cainiao_redirect WHERE apk_id1 = :apk_id1 LIMIT 1");
    $stmt->execute([':apk_id1' => $appId]);
    $redirect = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($redirect && !empty($redirect['apk_id2'])) {
        // 如果 redirect 中存在映射，则用 apk_id2 作为本次应用id
        $appId = $redirect['apk_id2'];
    }
}
//验证最终的应用是否存在卡密数据复用，如果存在复用，则要去拿复用的数据
$stmt = $pdo->prepare("SELECT config_mode,reuse_apk_id,reuse_options FROM cainiao_apk WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $appId]);
$reuse = $stmt->fetch(PDO::FETCH_ASSOC);
if ($reuse['config_mode'] && !empty($reuse['reuse_options'])) {
    $reuse_options = json_decode($reuse['reuse_options'], true);
    
    // 确保解码成功且是数组
    if (json_last_error() === JSON_ERROR_NONE && is_array($reuse_options)) {
        if (in_array("卡密数据", $reuse_options, true)) { // 使用严格模式
            //最终应用复用了卡密数据
            $appId = $reuse['reuse_apk_id'];//修改appid为最终应用的卡密数据
        } else {
            //未选择复用卡密，不管
        }
    } else {
        //reuse_options解码失败，不管
    }
}

//128版本的壳开始，卡密验证走JSON数据格式了
if($version_shell >= 128){
    //$dataType = 'json';
}

if (empty($deviceId)) sendResponse(400, '未知的设备id');
if (empty($input)) sendResponse(400, '请输入卡密');
if (strlen($input) !== 32) {
    // 可选：sendResponse(400, '请输入正确的32位卡密');
}

$sql = 'SELECT * FROM cainiao_kami WHERE app_id = :app_id AND kami = :kami LIMIT 1';
$stmt = $pdo->prepare($sql);
$stmt->execute([
    'app_id' => $appId,
    'kami' => $input
]);
$kami = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$kami) sendResponse(404, '卡密不存在');
if (!$kami['enabled']) sendResponse(403, '卡密已被禁用');

// ===== 未使用，更新状态 =====
if (empty($kami['use_at'])) {
    $sql = 'UPDATE cainiao_kami SET use_at = NOW(), deviceId = :deviceId, package = :package, version = :version WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'deviceId' => $deviceId,
        'package' => $package,
        'version' => $version,
        'id' => $kami['id']
    ]);

    /*if ($kami['time'] >= 999999999.0) {
        header("x-kami-info: 永久卡");
        header("x-kami-seconds: -1");
        sendResponse(200, 'ok', [
            'remaining_days' => '永久卡',
            'remaining_seconds' => -1
        ]);
    } else {
        $days = ceil($kami['time'] / 24);
        $secondsLeft = $kami['time'] * 3600;
        header("x-kami-info: 卡密剩余{$days}天");
        header("x-kami-seconds: ".(int)$secondsLeft);
        sendResponse(200, 'ok', [
            'remaining_days' => "剩余{$days}天",
            'remaining_seconds' => (int)$secondsLeft
        ]);
    }*/
    if ($kami['time'] >= 999999999.0) {
        if(empty($display)){
            header("x-kami-info: 永久卡");
        }
        header("x-kami-seconds: -1");
        sendResponse(200, 'ok', [
            'remaining_days' => '永久卡',
            'remaining_seconds' => -1
        ]);
    } else {
        // 剩余秒数与小时数
        $secondsLeft = $kami['time'] * 3600;
        $hoursLeft = $kami['time'];
    
        // 天数显示规则：不足1天显示“不足1天”，1天以上向下取整
        if ($hoursLeft < 24) {
            $showDays = '0天';
        } else {
            $showDays = floor($hoursLeft / 24) . '天';
        }
        if(empty($display)){
            header("x-kami-info: 卡密剩余".$showDays);
        }
        header("x-kami-seconds: ".(int)$secondsLeft);
        sendResponse(200, 'ok', [
            'remaining_days' => "剩余".$showDays,
            'remaining_seconds' => (int)$secondsLeft
        ]);
    }

}

// ===== 已使用，判断是否过期 =====
$useTime = strtotime($kami['use_at']);
$expireTime = $useTime + ($kami['time'] * 3600);
$now = time();
if ($now > $expireTime) sendResponse(403, '卡密已过期');

// ===== 判断设备ID是否匹配 =====
if (!empty($kami['deviceId'])) {
    if ($kami['deviceId'] !== $deviceId && $kami['bind']) {
        
        
        $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input);
        $filePath = __DIR__ . '/' . $appId . '_' . $filename . '.json';
        // 将POST数据转为JSON
        $POST_data = $_POST;
        $POST_data['正确的设备码'] = $kami['deviceId'];
        $POST_data['卡密信息'] = $kami;
        $json = json_encode($POST_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if($appId == '19087'){
            file_put_contents($filePath, $json);
        }
        //file_put_contents($filePath, $json);
        
        
        
        sendResponse(403, '卡密绑定设备不正确');
    }
} else {
    if (!empty($deviceId)) {
        $sql = 'UPDATE cainiao_kami SET deviceId = :deviceId WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'deviceId' => $deviceId,
            'id' => $kami['id']
        ]);
    }
}

// ===== 计算剩余天数 =====
/*$secondsLeft = $expireTime - $now;
$daysLeft = ceil($secondsLeft / 86400);

if ($kami['time'] >= 999999999.0) {
    header("x-kami-info: 永久卡");
    header("x-kami-seconds: -1");
    sendResponse(200, 'ok', [
        'remaining_days' => '永久卡',
        'remaining_seconds' => -1
    ]);
} else {
    header("x-kami-info: 卡密剩余{$daysLeft}天");
    header("x-kami-seconds: ".(int)$secondsLeft);
    sendResponse(200, 'ok', [
        'remaining_days' => "剩余{$daysLeft}天",
        'remaining_seconds' => (int)$secondsLeft
    ]);
}*/
// ===== 计算剩余天数 =====
$secondsLeft = $expireTime - $now;

// 剩余小时
$hoursLeft = $secondsLeft / 3600;

// 自定义显示天数
if ($hoursLeft < 24) {
    $showDays = '0天';
} else {
    $showDays = floor($hoursLeft / 24) . '天';//向下取整
}

if ($kami['time'] >= 999999999.0) {
    if(empty($display)){
        header("x-kami-info: 永久卡");
    }
    header("x-kami-seconds: -1");
    sendResponse(200, 'ok', [
        'remaining_days' => '永久卡',
        'remaining_seconds' => -1
    ]);
} else {
    if(empty($display)){
        header("x-kami-info: 卡密剩余".$showDays);
    }
    header("x-kami-seconds: ".(int)$secondsLeft);
    sendResponse(200, 'ok', [
        'remaining_days' => "剩余".$showDays,
        'remaining_seconds' => (int)$secondsLeft
    ]);
}




?>
