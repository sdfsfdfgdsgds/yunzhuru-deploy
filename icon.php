<?php
require_once __DIR__ . '/config/db.php'; // 数据库配置文件
require_once __DIR__ . '/api/utils/Auth.php'; // 鉴权中间件

$iconDir = __DIR__ . '/icon/';
$defaultIcon = $iconDir . 'android.png';

// 获取应用 ID 参数
$apkId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '未知UA';
//file_put_contents($apkId.".txt", $ua . PHP_EOL);
// 如果未传 ID，则输出默认图标
if ($apkId <= 0) {
    header('Content-Type: image/png');
    readfile($defaultIcon);
    exit;
}

try {
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] === 'admin');
} catch (Exception $e) {
    header('Content-Type: image/png');
    readfile($defaultIcon);
    exit;
}

// 查询 icon 信息
$stmt = $pdo->prepare("SELECT user_id, icon FROM cainiao_apk WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $apkId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// 未查到或无权限访问
if (!$row || (!$isAdmin && (int)$row['user_id'] !== $userId)) {
    header('Content-Type: image/png');
    readfile($defaultIcon);
    exit;
}

$iconName = $row['icon'] ?: 'android.png';
$iconPath = $iconDir . basename($iconName);

// 文件不存在，使用默认图标
if (!is_file($iconPath)) {
    header('Content-Type: image/png');
    header('message: 404');
    readfile($defaultIcon);
    exit;
}

// 输出图标
$ext = strtolower(pathinfo($iconPath, PATHINFO_EXTENSION));

// 如果是 xml 格式，输出默认图标
if ($ext === 'xml') {
    header('Content-Type: image/png');
    header('message: xml');
    readfile($defaultIcon);
    exit;
}
switch ($ext) {
    case 'png':
        $contentType = 'image/png';
        break;
    case 'jpg':
    case 'jpeg':
        $contentType = 'image/jpeg';
        break;
    case 'webp':
        $contentType = 'image/webp';
        break;
    default:
        $contentType = 'application/octet-stream';
        break;
}
// 启用浏览器缓存（缓存 7 天）
header('Cache-Control: public, max-age=31536000'); // 7天=7*24*60*60=604800秒
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($iconPath)) . ' GMT');

header('message: 200');
header('Content-Type: ' . $contentType);
readfile($iconPath);
exit;
