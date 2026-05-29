<?php

$filename = $_GET['file'] ?? '';
$downloadName = $_GET['name'] ?? '';
$isCheck = isset($_GET['check']) && $_GET['check'] === '1';
$debug = $_GET['debug'] ?? '';//如果有值，则不记录高速下载次数
$debug_pass = 'yunzhuru';

if (!defined('OSS_DOWNLOAD_KEEP_MINUTES')) {
    // 大 APK 在限速通道下可能需要几十分钟到数小时，临时 OSS 文件不能 5 分钟就清理。
    define('OSS_DOWNLOAD_KEEP_MINUTES', (int)(getenv('OSS_DOWNLOAD_KEEP_MINUTES') ?: 720));
}
if (!defined('OSS_SIGNED_URL_MIN_SECONDS')) {
    // 签名 URL 有效期至少与临时文件保留时间一致，避免断点续传时链接已经过期。
    define('OSS_SIGNED_URL_MIN_SECONDS', OSS_DOWNLOAD_KEEP_MINUTES * 60);
}



require_once __DIR__ . '/config/redis.php';
$redis = getRedisConnection(5);
$exists = $redis->get($_GET['file']);
if($exists !== false){
    header('X-Download-Source: cached-redirect');
    header("location:{$exists}");
    exit;
}



set_time_limit(0); // 脚本永不超时
require_once __DIR__ . '/config/db.php';
ini_set("max_execution_time", 0);

$uploadDir = __DIR__ . '/uploads/';
$down_type = 'uploads';
// 加载 utils 目录所有 PHP 工具模块
$utilsDir = __DIR__ . '/api/utils/';
foreach (glob($utilsDir . '*.php') as $file) {
    require_once $file;
}
$ossObj = new OSS();
//初始化数据库和OSS对象





//
if (strpos($filename, '.build.aligned.signed.apk') !== false) {
    $uploadDir = __DIR__ . '/release/';
    $down_type = 'release';
}
if (strpos($filename, '.build.signed.apk') !== false) {
    $uploadDir = __DIR__ . '/release/';
    $down_type = 'release';
}
if (strpos($filename, '.signed.apk') !== false) {
    $uploadDir = __DIR__ . '/release/';
    $down_type = 'release';
}
// 校验文件名合法性（只允许 .apk 文件名）
if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.apk$/', $filename)) {
    http_response_code(400);
    exit('非法文件名');
}
// 获取真实路径，防止路径穿透，后续的文件操作都可以走这个下载路径
$filePath = realpath($uploadDir . $filename);



if($down_type == 'uploads'){
    //对于下载底包的时候，要先检查文件位置是否在oss中
    $stmtApp = $pdo->prepare("SELECT * FROM `cainiao_apk` WHERE path = :path LIMIT 1");
    $stmtApp->execute([':path' => $filename]);
    $app = $stmtApp->fetch(PDO::FETCH_ASSOC);
    if(!$app){
        http_response_code(404);
        exit('文件不存在');
    }else{
        //文件存在，检查是否在本地，如果不在本地且有OSS记录
        if(!file_exists(__DIR__ . '/uploads/' . $app['path']) && !empty($app['osspath'])){
            //在OSS
            $ossPath = $app['osspath'];
            // 原始文件名
            $filename = basename($ossPath);
            // 临时缓存目录目录
            $tmpDir = rtrim(__DIR__, DIRECTORY_SEPARATOR);
            // 你的统一缓存子目录（自行定名）
            $appTmpDir = $tmpDir . DIRECTORY_SEPARATOR . 'apk_cache_oss_downloads';
            // 确保目录存在
            if (!is_dir($appTmpDir)) {
                mkdir($appTmpDir, 0755, true);
            }
            // 最终本地缓存路径
            $localSavePath = $appTmpDir . DIRECTORY_SEPARATOR . $filename;
            $ossResult = $ossObj->downloadToLocal($ossPath, $localSavePath);
            if($ossResult['code'] !== 200){
                http_response_code(399);
                exit('从OSS拉取文件失败' . $ossPath . '到' . $localSavePath);
            }
            $filePath = $localSavePath;
            $down_type = 'apk_cache_oss_downloads';
            
            
            
        }
    }
}

if (!$filePath || !file_exists($filePath) || (strpos($filePath, realpath($uploadDir)) !== 0 && strpos($filePath, realpath($appTmpDir)) !== 0)) {
    http_response_code(414);
    exit('文件不存在');
}

// 预检模式：只返回文件信息
if ($isCheck) {
    $info = [
        'filename' => basename($filePath),
        'size' => filesize($filePath),
        'md5' => md5_file($filePath),
        'modified_time' => date('Y-m-d H:i:s', filemtime($filePath))
    ];

    header('Content-Type: application/json');
    echo json_encode($info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
//由于下载地址是不能做任何验证的，所以在此步骤，通过要下载的文件名，去数据库里找该任务的创建者id，然后根据id去用户表找它的信息，如果是会员，则在此处进行文件转载到oss，然后生成一个签名后的下载地址并重定向
header('md5: ' . md5_file($filePath));

if (!$pdo || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo '无法连接到数据库';
    exit;
}else{
    cleanupExpiredOssDownloadFiles($pdo, $ossObj);
}

$oss = Auth::getSetting($pdo,"oss","0");//编译后转存oss,默认0
$ossvip = Auth::getSetting($pdo,"ossvip","0");//限制仅会员可用此功能,默认0关闭
$osshigh = Auth::getSetting($pdo,"osshigh","4");//单任务最多被高速下载多少次，默认4
$ossmini = Auth::getSetting($pdo,"ossmini","5") * 1024 * 1024;//多少M以上的文件，才允许走oss通道，默认5M

$ip = getClientIp();
//$IpLocation = getIpLocation($ip);
$redis->select(1);//选择数据库1，作为IP归属地缓存库
$exists = $redis->get($ip);
if($exists !== false){
    $IpLocation = json_decode($exists, true); // 返回结构化数组
    header('X-Data-Source-ip_location: redis');
}else{
    $IpLocation = getIpLocation($ip); // 返回结构化数组
    $redis->set($ip, json_encode($IpLocation,320));
    header('X-Data-Source-ip_location: data');
}






$IpLocation = $IpLocation['location'];
//只有下载编译后的文件的时候，才会触发转存oss下载功能
if($down_type == 'release'){
    
    header("IP:{$ip}");
    header("IpLocation:" . iconv('UTF-8', 'GBK', $IpLocation));
    $stmtTask = $pdo->prepare("SELECT * FROM `cainiao_inject_task` WHERE injected_apk = :filename LIMIT 1");
    $stmtTask->execute([':filename' => $filename]);
    $task = $stmtTask->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        // 没有找到任务
        $task = [];
        $user = [];
        $user['isVip'] = false;
    } else {
        // 2. 根据 user_id 查询 cainiao_user 表
        $stmtUser = $pdo->prepare("SELECT * FROM `cainiao_user` WHERE id = :uid LIMIT 1");
        $stmtUser->execute([':uid' => $task['user_id']]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
    
        if (!$user) {
            $user = [];
            $user['isVip'] = false;
        } else {
            // 3. 判断 VIP 状态并添加字段
            $user['isVip'] = !empty($user['vip_expire_time']) && strtotime($user['vip_expire_time']) > time();
        }
    }
}
//此时已经拿到了全部参数了
if($_GET['debug'] == 'yunzhuru'){
    $oss = false;//调试机不走oss通道下载
}
if($oss){
    $stmtDownloadCount = $pdo->prepare("SELECT COUNT(*) FROM `cainiao_download_record` WHERE task_id = :task_id AND source = 'oss'");
    $stmtDownloadCount->execute([':task_id' => $task['id']]);
    $ossDownloadCount = $stmtDownloadCount->fetchColumn();
    // 获取当前年月日
    $year = date('Y');
    $month = date('m');
    $day = date('d');
    $ossName = basename($filePath);
    // 拼接新的路径
    $newFilePath = "oss/{$year}{$month}{$day}/{$ossName}";

    if ($ossDownloadCount >= $osshigh) {
        // 下载次数超限，走常规下载流程
        // 下载次数超限，且开启了OSS通道，走限速下载流程
        if($task['size'] > $ossmini){
            $result = $ossObj->uploadFile($filePath, $newFilePath);
            if($result['code'] == 200){
                $speedLimit = 819200 * 3 ;//限制为100kb/秒
                $time = 600;
                //上传成功,开始签名URL
                if($_GET['debug'] == 'yunzhuru'){
                    $speedLimit = 245760000;//调试机器不限速
                }
                //VIP用户下载超出限制次数，依旧限速，不过限高一点
                if($user['isVip']){
                    //VIP用户不限速
                    $speedLimit = 8192000 * 20;//VIP用户，2000KB
                    $time = 600;
                }
                $result = getSignedUrlForLargeDownload($ossObj, $newFilePath, $filePath, $speedLimit, $time);
                if($result['code'] == 200){
                    if($debug !== $debug_pass){
                        recordDownload($pdo, $task['id'], $ip, $IpLocation, $task['size'], $_SERVER['HTTP_USER_AGENT'], 'oss', $newFilePath);//记录下载
                    }
                    $redis->select(5);//选择数据库5，作为下载缓存接口
                    $redis->setex($_GET['file'], 30, $result['url']);
                    header('X-Download-Source: oss');
                    header("location:{$result['url']}");
                    exit;
                }else{
                    //签名url失败，走正常龟速下载
                }
            }else{
                //上传失败，走正常龟速下载
            }
        }
        
    }else{
        //oss下载已启用
        
        if($ossvip){
            //只有vip才能用
            if($user['isVip'] && $task['size'] > $ossmini){
                //VIP用户，转存到oss并生成下载地址
                $result = $ossObj->uploadFile($filePath, $newFilePath);
                if($result['code'] == 200){
                    //上传成功,开始签名URL
                    $result = getSignedUrlForLargeDownload($ossObj, $newFilePath, $filePath);
                    if($result['code'] == 200){
                        if($debug !== $debug_pass){
                            recordDownload($pdo, $task['id'], $ip, $IpLocation, $task['size'], $_SERVER['HTTP_USER_AGENT'], 'oss', $newFilePath);//记录下载
                        }
                        $redis->select(5);//选择数据库5，作为下载缓存接口
                        $redis->setex($_GET['file'], 30, $result['url']);
                        header('X-Download-Source: oss');
                        header("location:{$result['url']}");
                        exit;
                    }else{
                        //签名url失败，走正常龟速下载
                    }
                }else{
                    //上传失败，走正常龟速下载
                }
                
            }else{
                //非VIP用户，走正常龟速下载
            }
        }else{
            //关闭了仅会员使用oss，这里代表所有用户允许使用oss，不过还是要限制非会员用户(限速)
            if($user['isVip']){
                //VIP用户不限速
                $speedLimit = 245760000 * 2;//VIP用户，30MB限速
                $time = 120;
            }else{
                if($task['size'] >= 1024 * 1024 * 80){
                    $speedLimit = 819200 * 10;//非VIP，下载限速1000KB(80M以上文件)
                    $time = 60 * 15;
                }else{
                    $speedLimit = 819200 * 8 ;//小于80M的文件，每秒800KB
                    $time = 600;
                }
                
            }
            if($task['size'] > $ossmini){
                //所有用户允许使用oss通道下载
                $result = $ossObj->uploadFile($filePath, $newFilePath);
                if($result['code'] == 200){
                    //上传成功,开始签名URL
                    if($_GET['debug'] == 'yunzhuru'){
                        $speedLimit = 245760000;//调试机器不限速
                    }
                    $result = getSignedUrlForLargeDownload($ossObj, $newFilePath, $filePath, $speedLimit, $time);
                    if($result['code'] == 200){
                        if($debug !== $debug_pass){
                            recordDownload($pdo, $task['id'], $ip, $IpLocation, $task['size'], $_SERVER['HTTP_USER_AGENT'], 'oss', $newFilePath);//记录下载
                        }
                        $redis->select(5);//选择数据库5，作为下载缓存接口
                        $redis->setex($_GET['file'], 30, $result['url']);
                        header('X-Download-Source: oss');
                        header("location:{$result['url']}");
                        exit;
                    }else{
                        //签名url失败，走正常龟速下载
                    }
                }else{
                    //上传失败，走正常龟速下载
                }
            }else{
                //小文件，不走oss通道
            }
        }
    }
}else{
    
    
}


// 确定最终下载文件名
if (empty($downloadName)) {
    $downloadName = basename($filePath);
} else {
    if (strtolower(substr($downloadName, -4)) !== '.apk') {
        $downloadName .= '.apk';
    }
}


//未开启oss，正常走下面的常规下载速度
$diydown = Auth::getSetting($pdo,"diydown","0");//使用自定义下载服,默认0
$downurl = Auth::getSetting($pdo,"downurl","");//自定义下载服务器url地址

//开启了自定义下载服且有链接
if($diydown && !empty($downurl)){
    
    if($user['isVip']){
       $speed = 204800;//会员不限速
    }else{
         $speed = 4096;//非会员限速
    }
    if($_GET['debug'] == 'yunzhuru'){
        $speed = 204800;//调试机不限速
    }
    if(!empty($task['id'])){
        recordDownload($pdo, $task['id'], $ip, $IpLocation, $task['size'], $_SERVER['HTTP_USER_AGENT'], 'diy', $filename);//记录下载
    }
    $downurl = $downurl . "?type={$down_type}&filename={$filename}&name={$downloadName}&speed={$speed}";//得到下载服务器需要的链接
    $redis->select(5);//选择数据库5，作为下载缓存接口
    $redis->setex($_GET['file'], 30, $downurl);
    header('X-Download-Source: diy');
    header("location:{$downurl}");
    exit;
}

$fileSize = filesize($filePath);
if (tryRedirectRailwayReleaseDownloadViaBuckets($pdo, $redis, $down_type, $filename, $filePath, $downloadName, $task ?? [], $ip, $IpLocation, $debug, $debug_pass)) {
    exit;
}

if(!empty($task['id'])){
    recordDownload($pdo, $task['id'], $ip, $IpLocation, $task['size'], $_SERVER['HTTP_USER_AGENT'], 'ecs', $filename);//记录下载
}

$start = 0;
$end = $fileSize - 1;
$length = $fileSize;
$statusCode = 200;

if (shouldBlockRailwayDirectReleaseDownload($down_type, $fileSize)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Download-Source: blocked-railway-direct');
    exit('APK 下载通道生成失败：当前生产环境不允许通过 Railway/PHP 直连下载 APK，且桶/下载服链接生成失败，请稍后重试或联系管理员检查桶配置。');
}

// 处理断点续传 Range 头
if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $matches)) {
    if ($matches[1] === '' && $matches[2] === '') {
        header("HTTP/1.1 416 Requested Range Not Satisfiable");
        header("Content-Range: bytes */{$fileSize}");
        exit;
    }

    if ($matches[1] === '') {
        $suffixLength = (int)$matches[2];
        $start = max(0, $fileSize - $suffixLength);
    } else {
        $start = (int)$matches[1];
        if ($matches[2] !== '') {
            $end = (int)$matches[2];
        }
    }

    $end = min($end, $fileSize - 1);
    if ($start > $end || $start >= $fileSize) {
        header("HTTP/1.1 416 Requested Range Not Satisfiable");
        header("Content-Range: bytes */{$fileSize}");
        exit;
    }

    $length = $end - $start + 1;
    $statusCode = 206;
}

while (ob_get_level()) {
    ob_end_clean();
}
ignore_user_abort(true);
@ini_set('zlib.output_compression', 'Off');

if ($statusCode === 206) {
    header('HTTP/1.1 206 Partial Content');
    header("Content-Range: bytes $start-$end/$fileSize");
}

// 通用下载头
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . urlencode($downloadName) . '"');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
header('md5: ' . md5_file($filePath));
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Expires: 0');
header('X-Accel-Buffering: no');
header('X-Download-Source: php-direct');

// 打开文件并分段输出（防止爆内存）
$fp = fopen($filePath, 'rb');
if ($fp === false) {
    http_response_code(500);
    exit('文件打开失败');
}

fseek($fp, $start);
$bufferSize = 1024 * 1024; // 每次读取 1MB，降低大文件下载时的 PHP 循环压力。
$bytesSent = 0;

while (!feof($fp) && $bytesSent < $length) {
    $chunkSize = (int)min($bufferSize, $length - $bytesSent);
    $data = fread($fp, $chunkSize);
    if ($data === false || $data === '') {
        break;
    }
    echo $data;
    $bytesSent += strlen($data);
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
    if (connection_aborted()) {
        break;
    }
}

fclose($fp);
exit;







function recordDownload($pdo, $task_id, $ip_address, $ip_location, $size, $user_agent, $source, $file) {
    $stmt = $pdo->prepare("INSERT INTO `cainiao_download_record` (task_id, ip_address, ip_location, size, user_agent, download_time, source, file) 
                           VALUES (:task_id, :ip_address, :ip_location, :size, :user_agent, NOW(), :source, :file)");
    $stmt->execute([
        ':task_id'    => $task_id,
        ':ip_address' => $ip_address,
        ':ip_location'=> $ip_location,
        ':size'       => $size,
        ':user_agent' => $user_agent,
        ':source'     => $source,
        ':file'     => $file
    ]);
}

function cleanupExpiredOssDownloadFiles(PDO $pdo, OSS $ossObj) {
    $keepMinutes = max(60, (int)OSS_DOWNLOAD_KEEP_MINUTES);
    $stmt = $pdo->prepare("
        SELECT id, file
        FROM cainiao_download_record
        WHERE source = 'oss'
        AND file IS NOT NULL
        AND file <> ''
        AND download_time < (NOW() - INTERVAL {$keepMinutes} MINUTE)
    ");
    $stmt->execute();
    $expiredFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiredFiles as $row) {
        $file = $row['file'];
        if (!$file) continue;

        // 清理失败只影响 OSS 临时空间，不应该打断用户当前下载流程。
        $result = $ossObj->deleteFile($file);
        if ($result['code'] == 200) {
            $update = $pdo->prepare("UPDATE cainiao_download_record SET file = NULL WHERE id = :id");
            $update->execute([':id' => $row['id']]);
        }
    }
}

function getSignedUrlForLargeDownload(OSS $ossObj, string $ossPath, string $localFilePath, int $speedLimit = 245760000, int $requestedSeconds = 600) {
    $fileSize = is_file($localFilePath) ? (int)filesize($localFilePath) : 0;
    // OSS 的 x-oss-traffic-limit 使用 bit/s，这里折算成 byte/s 估算下载耗时。
    $bytesPerSecond = max(1, (int)floor(max(1, $speedLimit) / 8));
    $estimatedSeconds = $fileSize > 0 ? (int)ceil($fileSize / $bytesPerSecond) : 0;
    $ttl = max((int)$requestedSeconds, (int)OSS_SIGNED_URL_MIN_SECONDS, $estimatedSeconds + 1800);

    return $ossObj->getSignedUrl($ossPath, $speedLimit, $ttl);
}

function tryRedirectRailwayReleaseDownloadViaBuckets(PDO $pdo, $redis, string $downType, string $filename, string $filePath, string $downloadName, array $task, string $ip, string $ipLocation, string $debug, string $debugPass): bool {
    if ($downType !== 'release' || !isRailwayRuntime() || !is_file($filePath)) {
        return false;
    }

    try {
        require_once __DIR__ . '/api/utils/S3Client.php';
        $stmt = $pdo->query("
            SELECT id, name, provider, access_key, secret_key, endpoint, bucket, region, domain
            FROM cainiao_s3_bucket
            WHERE enabled = 1
            AND inject = 1
            AND access_key <> ''
            AND secret_key <> ''
            AND endpoint <> ''
            AND bucket <> ''
            AND domain <> ''
            ORDER BY
                CASE
                    WHEN provider = 'r2' THEN 0
                    WHEN provider = 's3' THEN 1
                    WHEN provider = 'b2' THEN 2
                    ELSE 3
                END,
                id ASC
        ");
        $buckets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[DownloadBucket] 读取桶配置失败：' . $e->getMessage());
        return false;
    }

    if (!$buckets) {
        return false;
    }

    $objectKey = 'release_downloads/' . date('Ymd') . '/' . basename($filename);
    foreach ($buckets as $bucket) {
        try {
            $client = new S3Client(
                $bucket['access_key'],
                $bucket['secret_key'],
                $bucket['endpoint'],
                $bucket['bucket'],
                $bucket['region'] ?: 'auto'
            );
            $result = $client->putObjectFromFile($objectKey, $filePath, 'application/vnd.android.package-archive');
            if (($result['code'] ?? 500) !== 200) {
                error_log('[DownloadBucket] 上传失败 bucket_id=' . $bucket['id'] . ' message=' . ($result['message'] ?? 'unknown'));
                continue;
            }

            $url = buildBucketPublicUrl($bucket['domain'], $objectKey);
            if (!empty($task['id']) && $debug !== $debugPass) {
                recordDownload($pdo, $task['id'], $ip, $ipLocation, filesize($filePath), $_SERVER['HTTP_USER_AGENT'] ?? '', 'bucket', $bucket['id'] . ':' . $objectKey);
            }

            if ($redis) {
                $redis->select(5);
                $redis->setex($_GET['file'], 3600, $url);
            }

            header('X-Download-Source: bucket');
            header('X-Download-Bucket-Id: ' . $bucket['id']);
            header('Location: ' . $url);
            return true;
        } catch (Throwable $e) {
            error_log('[DownloadBucket] 异常 bucket_id=' . ($bucket['id'] ?? 'unknown') . ' message=' . $e->getMessage());
        }
    }

    return false;
}

function buildBucketPublicUrl(string $domain, string $objectKey): string {
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', ltrim($objectKey, '/'))));
    return rtrim($domain, '/') . '/' . $encodedKey;
}

function shouldBlockRailwayDirectReleaseDownload(string $downType, int $fileSize): bool {
    if ($downType !== 'release' || !isRailwayRuntime()) {
        return false;
    }

    // Railway 下载 release APK 不稳定；桶和下载服都失败时，只允许极小文件直连，避免生成残缺 APK。
    $maxBytes = (int)(getenv('DIRECT_RELEASE_MAX_BYTES') ?: (1 * 1024 * 1024));
    return $fileSize > max(1024 * 1024, $maxBytes);
}

function isRailwayRuntime(): bool {
    foreach (['RAILWAY_ENVIRONMENT', 'RAILWAY_SERVICE_NAME', 'RAILWAY_PROJECT_ID'] as $key) {
        if (getenv($key)) {
            return true;
        }
    }

    return false;
}


function getIpLocation($ip) {
    static $searcher = null;
    
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
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
}


function getClientIp(){
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
