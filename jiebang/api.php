<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['code' => 400, 'msg' => '请求方式错误'],320);
    exit;
}

require_once __DIR__ . '/../config/db.php';

if (!$pdo || !($pdo instanceof PDO)) {
    echo json_encode(['code' => 500, 'msg' => '无法连接到数据库'],320);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if (!in_array($action, ['query', 'unbind'])) {
    echo json_encode(['code' => 400, 'msg' => '无效的方法'],320);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['appid'], $data['kami'], $data['auth_code'])) {
    echo json_encode(['code' => 400, 'msg' => '参数错误，必须包含 appid, kami, auth_code'],320);
    exit;
}

$appid = $data['appid'];
$kami = $data['kami'];
$auth_code = $data['auth_code'];

// 获取用户 IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// 日志目录
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/' . $ip . '.log';

// 判断请求间隔（仅限制未通过验证的请求）
if (file_exists($logFile)) {
    $lastTime = intval(file_get_contents($logFile));
    $now = time();
    $interval = $now - $lastTime;
    if ($interval < 10) {
        $wait = 10 - $interval;
        echo json_encode(['code' => 429, 'msg' => "请求过于频繁，请 {$wait} 秒后再试"],320);
        exit;
    }
}

if (empty($auth_code)) {
    file_put_contents($logFile, time());
    echo json_encode(['code' => 401, 'msg' => '授权码不能为空'],320);
    exit;
}

try {
    // 1️⃣ 验证授权码
    $stmt = $pdo->prepare("SELECT app_key FROM cainiao_apk WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $appid]);
    $apkRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$apkRecord) {
        file_put_contents($logFile, time());
        echo json_encode(['code' => 404, 'msg' => '对应应用未找到'],320);
        exit;
    }

    if ($auth_code !== $apkRecord['app_key']) {
        file_put_contents($logFile, time());
        echo json_encode(['code' => 403, 'msg' => '授权码验证失败'],320);
        exit;
    }

    // 2️⃣ 验证卡密是否存在
    $stmt = $pdo->prepare("SELECT * FROM cainiao_kami WHERE app_id = :appid AND kami = :kami LIMIT 1");
    $stmt->execute([':appid' => $appid, ':kami' => $kami]);
    $kamiRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$kamiRecord) {
        file_put_contents($logFile, time());
        echo json_encode(['code' => 404, 'msg' => '卡密未找到'],320);
        exit;
    }

    // ✅ 授权和卡密验证通过后，不受时间限制
    if ($action === 'query') {
        $useAt = $kamiRecord['use_at'] ?? null;
        $expireAt = null;
        if (isset($kamiRecord['time']) && intval($kamiRecord['time']) === 999999999) {
            $expireAt = '永久卡';
        } elseif ($useAt && isset($kamiRecord['time'])) {
            $expireAt = date('Y-m-d H:i:s', strtotime($useAt) + intval($kamiRecord['time']) * 3600);
        }

        echo json_encode([
            'code' => 200,
            'msg' => '查询成功',
            'data' => [
                'kami' => $kamiRecord['kami'],
                'use_at' => $useAt,
                'expire_at' => $expireAt,
                'deviceId' => $kamiRecord['deviceId'],
                'enabled' => intval($kamiRecord['enabled']),
            ]
        ],320);

        @unlink($logFile);
        exit;
    }

    if ($action === 'unbind') {
        $stmt = $pdo->prepare("UPDATE cainiao_kami SET deviceId = '' WHERE app_id = :appid AND kami = :kami");
        $stmt->execute([':appid' => $appid, ':kami' => $kami]);

        echo json_encode(['code' => 200, 'msg' => '解绑设备成功'],320);
        @unlink($logFile);
        exit;
    }

} catch (PDOException $e) {
    file_put_contents($logFile, time());
    echo json_encode(['code' => 500, 'msg' => '数据库错误: ' . $e->getMessage()],320);
    exit;
}
