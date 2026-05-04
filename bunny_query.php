<?php
header('Content-Type: application/json');
try {
    $dsn = "mysql:host=109.224.230.10;port=3306;dbname=yunzhuru;charset=utf8mb4";
    $pdo = new PDO($dsn, "root", "Yyf@Mysql2026!", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10
    ]);
    $stmt = $pdo->query("SELECT id, name, package, icon, upload_time FROM cainiao_apk ORDER BY id DESC LIMIT 5");
    echo json_encode(["code" => 200, "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(["code" => 500, "message" => $e->getMessage()]);
}
