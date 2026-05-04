#!/usr/bin/env php
<?php

declare(ticks = 1);

pcntl_signal(SIGTERM, function () {
    echo "[" . date('Y-m-d H:i:s') . "] 收到终止信号，服务退出\n";

    // 查找并终止所有apktool相关进程
    $output = shell_exec("ps aux | grep apktool | grep java | grep -v grep | awk '{print $2}'");
    $pids = preg_split('/\s+/', trim($output));
    foreach ($pids as $pid) {
        if (is_numeric($pid)) {
            shell_exec("kill -9 $pid");
            echo "[" . date('Y-m-d H:i:s') . "] 已终止进程 PID：$pid\n";
        }
    }

    exit;
});


echo "[" . date('Y-m-d H:i:s') . "] 注入服务已启动...\n";
echo "[" . date('Y-m-d H:i:s') . "] 此版本为免解包资源版本...\n";
// 配置文件路径
$configFile = __DIR__ . '/../config/config.php';
$logFile = __DIR__ . '/inject_service.log';

require_once __DIR__ . '/injector.php';
require_once __DIR__ . '/jiagu.php';
require_once __DIR__ . '/tool.php';
require_once __DIR__ . '/../api/utils/Auth.php';
require_once __DIR__ . '/../api/utils/OSS.php';


if (!file_exists($configFile)) {
    echo "配置文件不存在: $configFile\n";
    exit(1);
}

$dbConfig = require $configFile;
ini_set('memory_limit', '512M');

// 预设的需要重置的状态
$resetStatuses = ['开始处理', '正在编译', '正在签名'];

try {
    // 初始连接数据库用于执行状态更新,主要是在服务启动的时候，将之前数据库里滞留的未完成任务重置状
    $port = $dbConfig['port'] ?? 3306;
    $dsn = "mysql:host={$dbConfig['host']};port={$port};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    if (!empty($resetStatuses)) {
        $placeholders = implode(',', array_fill(0, count($resetStatuses), '?'));
        $sql = "UPDATE cainiao_inject_task SET status_text = '等待处理', status_info = '' WHERE status_text IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($resetStatuses);
        echo "[" . date('Y-m-d H:i:s') . "] 已重置注入任务状态: " . implode('、', $resetStatuses) . " -> 等待处理\n";

        // "正在下载"的任务重置回"等待下载"（不能重置为"等待处理"，否则跳过下载）
        $pdo->exec("UPDATE cainiao_inject_task SET status_text = '等待下载', status_info = '等待下载 APK' WHERE status_text = '正在下载'");
        echo "[" . date('Y-m-d H:i:s') . "] 已重置下载任务状态: 正在下载 -> 等待下载\n";

        $sql = "UPDATE cainiao_jiagu_task SET status_text = '等待处理', status_info = '' WHERE status_text IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($resetStatuses);
        echo "[" . date('Y-m-d H:i:s') . "] 已重置加固任务状态: " . implode('、', $resetStatuses) . " -> 等待处理\n";
    }

    $pdo = null;
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] 初始数据库连接失败: " . $e->getMessage() . "\n";
    exit(1);
}
try{
    $oss = new OSS();
} catch(Exception $e){
    echo "[" . date('Y-m-d H:i:s') . "] OSS类初始化失败: " . $e->getMessage() . "\n";
    exit(1);
}

while (true) {
    try {
        $port = $dbConfig['port'] ?? 3306;
    $dsn = "mysql:host={$dbConfig['host']};port={$port};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] 数据库连接失败: " . $e->getMessage() . "\n";
        sleep(1);
        continue;
    }

    try {
        handleInjectionTasks($pdo, $oss);  //调用注入任务处理
        //handleJiaguTasks($pdo, $oss);  // 调用加固任务处理
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] 任务执行异常: " . $e->getMessage() . "\n";
    }

    $pdo = null;
    sleep(1);
}
