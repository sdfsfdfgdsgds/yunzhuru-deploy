<?php
// 临时 S3 上传测试脚本
header('Content-Type: text/plain; charset=utf-8');
$key = $_GET['key'] ?? '';
if ($key !== 'YunZhuRu2026') { echo 'forbidden'; exit; }

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/api/utils/S3Client.php';

$cfg = require __DIR__ . '/config/config.php';
$pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4", $cfg['username'], $cfg['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 读取用户 S3 配置
$stmt = $pdo->query("SELECT s3_endpoint, s3_access_key, s3_secret_key, s3_bucket, s3_region, s3_upload_path, s3_public_url FROM cainiao_user WHERE s3_endpoint != '' LIMIT 1");
$s3 = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$s3) { echo "没有 S3 配置"; exit; }

echo "S3 配置:\n";
echo "  endpoint: {$s3['s3_endpoint']}\n";
echo "  bucket: {$s3['s3_bucket']}\n";
echo "  region: {$s3['s3_region']}\n";
echo "  upload_path: {$s3['s3_upload_path']}\n\n";

// 创建测试文件
$testFile = '/tmp/s3_test.txt';
file_put_contents($testFile, 'S3 upload test from Railway - ' . date('Y-m-d H:i:s'));

$client = new S3Client(
    $s3['s3_access_key'],
    $s3['s3_secret_key'],
    $s3['s3_endpoint'],
    $s3['s3_bucket'],
    $s3['s3_region'] ?: 'auto'
);

$prefix = $s3['s3_upload_path'] ? $s3['s3_upload_path'] . '/' : '';
$objectKey = $prefix . 'test_' . date('Ymd_His') . '.txt';

echo "上传测试: {$objectKey}\n";
$result = $client->putObject($objectKey, file_get_contents($testFile), 'text/plain');
echo "结果: " . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

@unlink($testFile);
