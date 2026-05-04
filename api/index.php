<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
error_reporting(0);      // 不报告任何错误
ini_set('display_errors', '0'); // 不显示错误信息
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}






header('Content-Type: application/json');
ini_set('memory_limit', '2048M'); // 设置最大内存

// 引入数据库和Redis配置
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/redis.php';

// 加载 utils 目录所有 PHP 工具模块
$utilsDir = __DIR__ . '/utils/';
foreach (glob($utilsDir . '*.php') as $file) {
    require_once $file;
}

// 获取 GET 参数并做基本校验
$module = $_GET['module'] ?? '';
$method = $_GET['method'] ?? '';

if (!preg_match('/^[a-zA-Z0-9_]+$/', $module)) {
    echo json_encode(['code' => 400, 'message' => '模块名称不合法'],320);
    exit;
}
if (!preg_match('/^[a-zA-Z0-9_]+$/', $method)) {
    echo json_encode(['code' => 400, 'message' => '方法名称不合法'],320);
    exit;
}

// 模块路径
$modulePath = __DIR__ . "/module/$module.php";
if (!file_exists($modulePath)) {
    echo json_encode(['code' => 404, 'message' => '模块不存在'],320);
    exit;
}

// 引入模块文件（函数定义）
require_once $modulePath;

// 检查函数是否定义
if (!function_exists($method)) {
    echo json_encode(['code' => 404, 'message' => '方法未定义'],320);
    exit;
}
// 使用反射检查参数
$refFunc = new ReflectionFunction($method);
$params = $refFunc->getParameters();

// 参数数量必须为 2
if (count($params) !== 2) {
    echo json_encode(['code' => 403, 'message' => '方法必须接收 2 个参数'], 320);
    exit;
}

// 第一个参数必须是 PDO
$param1Type = $params[0]->getType();
if (!$param1Type || $param1Type->__toString() !== PDO::class) {
    echo json_encode(['code' => 403, 'message' => '禁止调用 代码01'], 320);
    exit;
}

// 第二个参数必须是 array
$param2Type = $params[1]->getType();
if (!$param2Type || $param2Type->getName() !== 'array') {
    echo json_encode(['code' => 403, 'message' => '禁止调用 代码02'], 320);
    exit;
}
// 读取输入数据（文件上传时跳过 php://input 避免内存溢出）
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'multipart/form-data') !== false || !empty($_FILES)) {
    $inputData = $_POST;
} else {
    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true);
}
if (!is_array($inputData)) {
    $inputData = [];
}

// 调用模块方法，传入 PDO 和输入数据
try {
    $result = $method($pdo, $inputData);
    echo json_encode([
        'code' => 200,
        'message' => !empty($result['message']) ? $result['message'] : '成功',
        'data' => $result
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    //http_response_code(500);
    echo json_encode([
        'code' => 500,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
exit;
