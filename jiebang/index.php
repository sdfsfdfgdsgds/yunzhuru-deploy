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



if (empty($deviceId)) {
    echo '未知的设备id';
    exit;
}
if (empty($input)) {
    echo '请输入卡密';
    exit;
}
if (strlen($input) !== 32) {
    // echo '请输入正确的32位卡密';
    // exit;
}

// 查询卡密
$sql = 'SELECT * FROM cainiao_kami WHERE app_id = :app_id AND kami = :kami LIMIT 1';
$stmt = $pdo->prepare($sql);
$stmt->execute([
    'app_id' => $appId,
    'kami' => $input
]);
$kami = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$kami) {
    echo '卡密不存在';
    exit;
}

if (!$kami['enabled']) {
    echo '卡密已被禁用';
    exit;
}

// ===== 验证是否过期 =====
if (!empty($kami['use_at'])) {
    $useTime = strtotime($kami['use_at']);
    $expireTime = $useTime + ($kami['time'] * 3600);
    if (time() > $expireTime) {
        echo '卡密已过期';
        exit;
    }
}

// ===== 验证设备ID并解绑 =====
if (!empty($kami['deviceId'])) {
    // 如果已绑定设备，必须一致才允许解绑
    if ($kami['deviceId'] !== $deviceId) {
        echo '卡密绑定设备不正确';
        exit;
    }

    // 一致，清空设备ID
    $sql = 'UPDATE cainiao_kami SET deviceId = "" WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'id' => $kami['id']
    ]);
}

// 如果本身就没绑定设备，直接返回
echo '卡密已解绑';
exit;
?>
