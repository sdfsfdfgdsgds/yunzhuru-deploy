<?php
// 设置响应头为图片类型
header('Content-Type: image/png');

// 引入数据库连接
require_once __DIR__ . '/config/db.php'; // 提供 $pdo

// 获取访问者 IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// 生成随机验证码文本（4个字符）
$code = '';
$charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'; // 去除易混淆字符
for ($i = 0; $i < 4; $i++) {
    $code .= $charset[rand(0, strlen($charset) - 1)];
}

// 保存验证码到数据库
try {
    // 查询当前 IP 是否存在记录
    $stmt = $pdo->prepare("SELECT id FROM cainiao_verify WHERE ip_address = :ip LIMIT 1");
    $stmt->execute([':ip' => $ip]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        // 更新现有验证码
        $updateStmt = $pdo->prepare("UPDATE cainiao_verify SET code = :code, time = NOW() WHERE id = :id");
        $updateStmt->execute([':code' => $code, ':id' => $exists['id']]);
    } else {
        // 插入新验证码
        $insertStmt = $pdo->prepare("INSERT INTO cainiao_verify (code, time, ip_address) VALUES (:code, NOW(), :ip)");
        $insertStmt->execute([':code' => $code, ':ip' => $ip]);
    }
} catch (Exception $e) {
    // 出错不阻止输出图像
    error_log("验证码数据库操作失败：" . $e->getMessage());
}

// 将验证码存入 session（供后续验证用）
session_start();
$_SESSION['captcha'] = $code;

// 创建图像
$width = 100;
$height = 40;
$image = imagecreatetruecolor($width, $height);

// 分配颜色
$bgColor     = imagecolorallocate($image, 255, 255, 255);  // 白色背景
$textColor   = imagecolorallocate($image, 50, 50, 50);     // 深灰文字
$noiseColor  = imagecolorallocate($image, 180, 180, 180);  // 灰色噪点

// 填充背景
imagefill($image, 0, 0, $bgColor);

// 添加噪点
for ($i = 0; $i < 100; $i++) {
    imagesetpixel($image, rand(0, $width), rand(0, $height), $noiseColor);
}

// 写入验证码文字
$fontSize = 5; // 字体大小（1~5）
$x = 10;
$y = ($height - imagefontheight($fontSize)) / 2;
imagestring($image, $fontSize, $x, $y, $code, $textColor);

// 输出图像并销毁资源
imagepng($image);
imagedestroy($image);
?>
