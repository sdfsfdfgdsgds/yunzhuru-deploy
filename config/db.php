<?php
$host = getenv("DB_HOST") ?: "143.92.40.164";
$port = intval(getenv("DB_PORT") ?: 33061);
$dsn = "mysql:host={$host};port={$port};dbname=yunzhuru;charset=utf8mb4";
$username = getenv("DB_USER") ?: "root";
$password = getenv("DB_PASS") ?: "Yyf@Mysql2026!";
try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo json_encode(['code' => 500, 'message' => 'DB error: ' . $e->getMessage()]);
    exit;
}
