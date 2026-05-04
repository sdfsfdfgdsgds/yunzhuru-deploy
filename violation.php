<?php
// violation.php
require_once __DIR__ . '/config/db.php';

if (!$pdo || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// 每页数量
$pageSize = 20;

// 当前页
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;

$offset = ($page - 1) * $pageSize;

// ===== 从数据库读取数据 =====

// 你的前端使用字段：
// icon, name, level, package, time, reason, punishment

$sql = "SELECT 
            icon,
            name,
            level,
            package,
            time,
            reason,
            punishment
        FROM cainiao_violation
        ORDER BY time DESC
        LIMIT :offset, :pagesize";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':pagesize', $pageSize, PDO::PARAM_INT);
$stmt->execute();

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 返回 JSON
echo json_encode([
    'code'    => 0,
    'message' => 'success',
    'data'    => $data
], JSON_UNESCAPED_UNICODE);

?>
