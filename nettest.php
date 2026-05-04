<?php
header('Content-Type: application/json');
$host = getenv("DB_HOST") ?: "143.92.40.164";
$port = intval(getenv("DB_PORT") ?: 33061);
$result = [
    'host' => $host,
    'port' => $port,
    'env_DB_HOST' => getenv("DB_HOST"),
    'env_DB_PORT' => getenv("DB_PORT"),
];

// TCP 连接测试
$fp = @fsockopen($host, $port, $errno, $errstr, 5);
if ($fp) {
    $result['tcp'] = 'OK';
    fclose($fp);
} else {
    $result['tcp'] = "FAIL: $errno $errstr";
}

echo json_encode($result, JSON_PRETTY_PRINT);
