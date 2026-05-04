<?php
$port = 33070;
$dsn = "mysql:host=127.0.0.1;port={$port};dbname=yunzhuru;charset=utf8mb4";
$username = 'root';
$password = 'Yyf@Mysql2026!';
try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo json_encode(['code' => 500, 'message' => 'DB error: ' . $e->getMessage()]);
    exit;
}
