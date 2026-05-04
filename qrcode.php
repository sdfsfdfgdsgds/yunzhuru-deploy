<?php
error_reporting(0);
require_once 'phpqrcode.php'; // 确保你已引入 QRcode 类

// 优先 POST，其次 GET，最后默认值
$value = $_POST['text'] ?? $_GET['text'] ?? '';
if (empty($value)) {
    $value = '地址为空';
}

$errorCorrectionLevel = 'L'; // 容错级别
$matrixPointSize = 6;        // 图片大小

header("Content-type: image/png");
QRcode::png($value, false, $errorCorrectionLevel, $matrixPointSize, 2);
?>
