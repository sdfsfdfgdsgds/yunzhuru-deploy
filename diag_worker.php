<?php
header('Content-Type: application/json');
$result = [];

// 1. 检查 config.php
$configFile = __DIR__ . '/config/config.php';
$result['config_exists'] = file_exists($configFile);
if (file_exists($configFile)) {
    $dbConfig = require $configFile;
    $result['db_config'] = $dbConfig;
}

// 2. 尝试连接数据库
try {
    $port = $dbConfig['port'] ?? 3306;
    $dsn = "mysql:host={$dbConfig['host']};port={$port};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $result['db_connect'] = 'OK';
    
    // 查最新任务
    $stmt = $pdo->query("SELECT id, status_text, status_info FROM cainiao_inject_task ORDER BY id DESC LIMIT 3");
    $result['recent_tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $result['db_connect'] = 'FAILED: ' . $e->getMessage();
}

// 3. 检查 OSS 类
try {
    require_once __DIR__ . '/api/utils/OSS.php';
    $oss = new OSS();
    $result['oss_init'] = 'OK';
} catch (Exception $e) {
    $result['oss_init'] = 'FAILED: ' . $e->getMessage();
}

// 4. 模拟 decompile 命令
$apktool_jar = __DIR__ . '/bin/apktool_2.11.1.jar';
$shellFile = __DIR__ . '/templates/1_8db4f7afda84737b12058fef9ae13322.apk';
$tempDir = __DIR__ . '/temp/diag_test_' . time();
@mkdir($tempDir, 0777, true);

$cmd = sprintf(
    '%s -n 19 %s -c2 -n7 %s -jar %s d --no-res --no-src %s -o %s -f 2>&1',
    escapeshellcmd('nice'),
    escapeshellcmd('ionice'),
    escapeshellcmd('java'),
    escapeshellarg($apktool_jar),
    escapeshellarg($shellFile),
    escapeshellarg($tempDir . '/shell_test')
);
$result['decompile_cmd'] = $cmd;
$output = shell_exec($cmd);
$result['decompile_output'] = $output;
$result['decompile_success'] = is_dir($tempDir . '/shell_test');

// 清理
shell_exec("rm -rf " . escapeshellarg($tempDir));

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
