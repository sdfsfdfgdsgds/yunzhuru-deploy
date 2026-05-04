<?php
header('Content-Type: application/json');
error_reporting(0);      // 不报告任何错误
ini_set('display_errors', '0'); // 不显示错误信息
// 配置锁路径
$lockFile = __DIR__ . '/../config/config.lock';

// 如果已安装，禁止访问
if (file_exists($lockFile)) {
    //http_response_code(404);
    echo json_encode(['code' => 404, 'message' => '系统已安装，禁止重复安装']);
    exit;
}

// 读取 JSON 输入
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// 必填字段
$requiredFields = ['dbHost', 'dbName', 'dbUser', 'dbPass', 'adminUser', 'adminPass'];

$missing = [];
foreach ($requiredFields as $field) {
    if (!isset($data[$field]) || $data[$field] === '') {
        $missing[] = $field;
    }
}

// 返回缺失字段错误
if (!empty($missing)) {
    echo json_encode([
        'code' => 400,
        'message' => '缺少必要字段',
        'missing' => $missing
    ], JSON_UNESCAPED_UNICODE);
    exit;
}



require_once __DIR__ . '/install_execute.php';

try {
    $dsn = "mysql:host={$data['dbHost']};dbname={$data['dbName']};charset=utf8mb4";
    $pdo = new PDO($dsn, $data['dbUser'], $data['dbPass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $installResult = installDatabase($pdo);
    echo json_encode($installResult, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode([
        'code' => 500,
        'message' => '数据库连接失败：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
exit;


// 模拟成功响应（可用于前端调试）
echo json_encode([
    'code' => 200,
    'message' => '安装信息接收成功',
    'data' => $data
], JSON_UNESCAPED_UNICODE);
exit;
