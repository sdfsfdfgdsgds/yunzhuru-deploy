<?php
//这是部署在下载服务器上的下载接口文件,部署在轻量应用服务器上,带宽大,将运营服务器上的文件,通过内网转存到轻量服务器上,然后用户从轻量服务器下载,不限流量,就省下了oss的流量费用
//下载服和主服务器需要在同一内网，推荐部署方案，以阿里云为例
//主服务器，使用ECS实例
//下载服，使用轻量应用服务器，二者在同一账号，同一区域，然后开通内网互通

//主服务器使用4H8G,但是带宽不用太高
//下载服使用2H1G,带宽是200M共享带宽不限流,此部署方案比主服务器+OSS更省钱


exit;//部署后将此行代码删除或注释掉

?>


<?php
// ==================================
// 自动清理旧文件
// ==================================
$dirsToClean = [
    __DIR__ . '/file/uploads',
    __DIR__ . '/file/release',
];

$expireSeconds = 60 * 60 * 0.25; // 15分钟

foreach ($dirsToClean as $cleanDir) {
    if (!is_dir($cleanDir)) continue;
    foreach (scandir($cleanDir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $fullPath = $cleanDir . '/' . $file;
        if (!is_file($fullPath)) continue;
        $ctime = filectime($fullPath) ?: filemtime($fullPath);
        if (time() - $ctime > $expireSeconds) {
            unlink($fullPath);
        }
    }
}

// ==================================
// 限速设置
// ==================================
$defaultSpeed = 512;
$maxSpeed     = 204800;

$speedKB = isset($_GET['speed']) ? intval($_GET['speed']) : $defaultSpeed;
$speedKB = max(1, min($speedKB, $maxSpeed));
$speedLimit = $speedKB * 1024;

set_time_limit(0);
ini_set("max_execution_time", 0);

// ==================================
// 参数校验
// ==================================
$type     = trim($_GET['type'] ?? '');
$filename = trim($_GET['filename'] ?? '');

if ($type === '' || $filename === '') {
    http_response_code(400);
    die("缺少参数");
}

if (!in_array($type, ['uploads', 'release', 'apk_cache_oss_downloads'], true)) {
    http_response_code(400);
    die("非法目录类型");
}

if (preg_match('/\.\.|^\//', $filename)) {
    http_response_code(400);
    die("非法文件名");
}

// ==================================
// A 服务器信息
// ==================================
$A_base     = "http://172.20.202.175";
$A_file_url = "{$A_base}/{$type}/{$filename}";

// ==================================
// 本地路径
// ==================================
$localDir  = __DIR__ . "/file/{$type}";
if (!is_dir($localDir)) {
    mkdir($localDir, 0777, true);
}

$localFile = "{$localDir}/{$filename}";
$tempFile  = "{$localFile}.download";
$lockFile  = "{$localFile}.lock";

// ==================================
// 获取 A 服务器文件大小（HEAD）
// ==================================
$remoteFileSize = 0;
$ch = curl_init($A_file_url);
curl_setopt_array($ch, [
    CURLOPT_NOBODY         => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10
]);
curl_exec($ch);
if (!curl_errno($ch)) {
    $remoteFileSize = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
}
curl_close($ch);

// ==================================
// 文件锁（核心）
// ==================================
$lockFp = fopen($lockFile, 'c');
if ($lockFp === false) {
    http_response_code(500);
    die("无法创建锁文件");
}

flock($lockFp, LOCK_EX);

// ===== 二次校验（非常关键）=====
if (is_file($localFile) && $remoteFileSize > 0) {
    if (filesize($localFile) === $remoteFileSize) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        goto file_ready;
    }
}

// ==================================
// 下载（只会执行一次）
// ==================================
$fp = fopen($tempFile, 'w');
if (!$fp) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    http_response_code(500);
    die("无法创建临时文件");
}

$ch = curl_init($A_file_url);
curl_setopt_array($ch, [
    CURLOPT_FILE           => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_FAILONERROR    => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 0
]);

$result = curl_exec($ch);
if ($result === false) {
    fclose($fp);
    unlink($tempFile);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    http_response_code(502);
    die("下载失败：" . curl_error($ch));
}

curl_close($ch);
fclose($fp);

// 原子替换
rename($tempFile, $localFile);

// 释放锁
flock($lockFp, LOCK_UN);
fclose($lockFp);

// ==================================
// 文件已就绪
// ==================================
file_ready:

if (!is_file($localFile)) {
    http_response_code(404);
    die("文件不存在");
}

$fileSize = filesize($localFile);
if ($fileSize === false) {
    http_response_code(500);
    die("无法获取文件大小");
}

// ==================================
// 处理 Range
// ==================================
$start = 0;
$end   = $fileSize - 1;
$status = 200;

if (!empty($_SERVER['HTTP_RANGE']) &&
    preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $m)) {

    if ($m[1] !== '') $start = (int) $m[1];
    if ($m[2] !== '') $end   = (int) $m[2];

    if ($start > $end || $start >= $fileSize) {
        header("HTTP/1.1 416 Requested Range Not Satisfiable");
        header("Content-Range: bytes */{$fileSize}");
        exit;
    }

    $end = min($end, $fileSize - 1);
    $status = 206;
}

$length = $end - $start + 1;

// ==================================
// 输出文件
// ==================================
while (ob_get_level()) ob_end_clean();
ignore_user_abort(true);

if ($status === 206) {
    header("HTTP/1.1 206 Partial Content");
}

$downloadName = $_GET['name'] ?? $filename;

header("Content-Type: application/octet-stream");
header("Accept-Ranges: bytes");
header("Content-Disposition: attachment; filename=\"{$downloadName}\"");
header("Content-Length: {$length}");
header("Content-Range: bytes {$start}-{$end}/{$fileSize}");

$fp = fopen($localFile, 'rb');
fseek($fp, $start);

$startTime = microtime(true);
$sent = 0;
$chunk = 1024 * 1024;

while (!feof($fp) && $sent < $length) {
    $read = min($chunk, $length - $sent);
    $buf = fread($fp, $read);
    echo $buf;
    flush();
    $sent += strlen($buf);

    if ($speedKB < $maxSpeed) {
        $elapsed = microtime(true) - $startTime;
        $need = $sent / $speedLimit;
        if ($need > $elapsed) {
            usleep((int)(($need - $elapsed) * 1e6));
        }
    }

    if (connection_aborted()) break;
}

fclose($fp);
exit;
