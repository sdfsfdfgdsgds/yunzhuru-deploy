<?php
// 生产连接信息全部来自 Railway 环境变量，避免镜像或仓库携带外部数据库回退。
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = intval(getenv('DB_PORT') ?: 3306);
$dbName = getenv('DB_NAME') ?: 'yunzhuru';
$dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo json_encode(['code' => 500, 'message' => 'DB error: ' . $e->getMessage()]);
    exit;
}
