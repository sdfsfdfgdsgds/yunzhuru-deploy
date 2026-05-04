<?php
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

// 配置
$maxSize = 5 * 1024 * 1024; // 最大图片大小：5MB
$defaultImage = __DIR__ . '/default.jpg'; // 默认图片路径

// 获取请求参数
$url = isset($_GET['url']) ? trim($_GET['url']) : '';

// 校验 URL
if (!preg_match('/^https?:\/\/.+/i', $url)) {
    returnImage($defaultImage, false);
    exit;
}

// 获取远程图片 headers（仅获取头）
$headers = @get_headers($url, 1);
if (!$headers || !isset($headers['Content-Length'])) {
    returnImage($defaultImage, false);
    exit;
}

// 判断大小
$contentLength = is_array($headers['Content-Length']) ? end($headers['Content-Length']) : $headers['Content-Length'];
if ($contentLength > $maxSize) {
    returnImage($defaultImage, false);
    exit;
}

// 下载图片内容
$imageData = @file_get_contents($url);
if ($imageData === false) {
    returnImage($defaultImage, false);
    exit;
}

// 将图片内容直接输出（保留 header 信息）
returnRawImage($imageData, true, $headers);
exit;

// ==================== 工具函数 ====================
function returnRawImage($imageData, $cache = false, $sourceHeaders = []) {
    $contentType = 'image/jpeg'; // 默认类型

    if (!empty($sourceHeaders)) {
        foreach ($sourceHeaders as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = trim(substr($header, strlen('Content-Type:')));
                break;
            }
        }
    }

    header("Content-Type: {$contentType}");

    if ($cache) {
        header("Cache-Control: public, max-age=" . (7 * 24 * 60 * 60));
        header("Expires: " . gmdate('D, d M Y H:i:s', time() + (7 * 24 * 60 * 60)) . ' GMT');
    } else {
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
    }

    echo $imageData;
}

function returnImage($file, $cache = false) {
    $contentType = 'image/jpeg';

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file);
        finfo_close($finfo);
        if ($mime) {
            $contentType = $mime;
        }
    }

    header("Content-Type: {$contentType}");

    if ($cache) {
        header("Cache-Control: public, max-age=" . (7 * 24 * 60 * 60));
        header("Expires: " . gmdate('D, d M Y H:i:s', time() + (7 * 24 * 60 * 60)) . ' GMT');
    } else {
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
    }

    readfile($file);
}
