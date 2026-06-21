<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$key = $_GET['key'] ?? '';
if ($key !== 'deldbg_20260621_7f4b8c2a9d1e') {
    http_response_code(403);
    echo json_encode(['code' => 403, 'message' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 120;
$lines = max(20, min(1000, $lines));
$file = __DIR__ . '/temp/delete_progress/delete_debug.log';

if (!is_file($file)) {
    echo json_encode([
        'code' => 200,
        'message' => '暂无删除调试日志',
        'file' => $file,
        'lines' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$content = (string)@file_get_contents($file);
$rows = preg_split('/\R/', trim($content));
if (!is_array($rows)) {
    $rows = [];
}

$rows = array_values(array_filter($rows, function ($row) {
    return trim((string)$row) !== '';
}));

echo json_encode([
    'code' => 200,
    'message' => '读取成功',
    'file' => $file,
    'lines' => array_slice($rows, -$lines),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
