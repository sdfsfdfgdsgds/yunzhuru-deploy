<?php
// 日志查看接口 - 读取 supervisor 日志
header('Content-Type: text/plain; charset=utf-8');

$key = $_GET['key'] ?? '';
if ($key !== 'YunZhuRu2026') {
    http_response_code(403);
    echo "forbidden";
    exit;
}

$type = $_GET['type'] ?? 'worker';
$lines = min((int)($_GET['lines'] ?? 100), 500);

$logFiles = [
    'worker'       => '/var/log/supervisor/worker.log',
    'worker-error' => '/var/log/supervisor/worker-error.log',
    'php'          => '/var/log/supervisor/php.log',
    'php-error'    => '/var/log/supervisor/php-error.log',
    'supervisor'   => '/var/log/supervisor/supervisord.log',
];

if (!isset($logFiles[$type])) {
    echo "可用类型: " . implode(', ', array_keys($logFiles)) . "\n";
    exit;
}

$file = $logFiles[$type];
if (!file_exists($file)) {
    echo "日志文件不存在: $file\n";
    exit;
}

// 读取最后 N 行
$content = file_get_contents($file);
$allLines = explode("\n", $content);
$lastLines = array_slice($allLines, -$lines);
echo implode("\n", $lastLines);
