<?php

$cmd = $argv[1] ?? '';
$pidFile = __DIR__ . '/.inject_service.pid';
$logFile = __DIR__ . '/inject_service.log';
$workerFile = __DIR__ . '/worker.php';

switch ($cmd) {
    case 'start':
        startService();
        break;

    case 'stop':
        stopService();
        break;

    case 'status':
        checkStatus();
        break;

    case 'foreground':
        runForeground();
        break;

    default:
        echo "用法:\n";
        echo "sudo -u www php run.php start       启动为后台服务\n";
        echo "sudo -u www php run.php stop        停止服务\n";
        echo "sudo -u www php run.php status      查询状态\n";
        echo "sudo -u www php run.php foreground  以前台方式运行服务\n";
        exit(1);
}

function startService()
{
    global $pidFile, $logFile, $workerFile;
    $apktool_jar = __DIR__ . '/../bin/apktool_2.11.1.jar';
    if (!file_exists($apktool_jar)) {
        echo "核心支持文件不存在: $apktool_jar\n";
        exit(1);
    }

    if (file_exists($pidFile)) {
        $pid = (int)trim(file_get_contents($pidFile));
        if ($pid && posix_kill($pid, 0)) {
            echo "服务已在运行中 [PID: $pid]\n";
            exit(0);
        }
    }

    echo "正在启动注入服务...\n";

    $php = PHP_BINARY;
    $cmd = "nohup $php " . escapeshellarg($workerFile) . " > " . escapeshellarg($logFile) . " 2>&1 & echo $!";
    $pid = shell_exec($cmd);

    if ($pid) {
        file_put_contents($pidFile, $pid);
        echo "服务已启动 [PID: $pid]\n";
    } else {
        echo "启动失败\n";
    }
}

function stopService()
{
    global $pidFile;

    if (!file_exists($pidFile)) {
        echo "服务未运行\n";
        return;
    }

    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid && posix_kill($pid, 0)) {
        posix_kill($pid, SIGTERM);
        echo "已发送终止信号 [PID: $pid]\n";
    } else {
        echo "进程不存在或已终止\n";
    }

    @unlink($pidFile);
}

function checkStatus()
{
    global $pidFile;

    if (!file_exists($pidFile)) {
        echo "服务未运行\n";
        return;
    }

    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid && posix_kill($pid, 0)) {
        echo "服务正在运行中 [PID: $pid]\n";
    } else {
        echo "服务未运行，但 PID 文件存在，可能异常退出\n";
    }
}

function runForeground()
{
    global $workerFile;

    if (!file_exists($workerFile)) {
        echo "找不到 worker 文件: $workerFile\n";
        exit(1);
    }

    echo "以前台方式启动服务...\n";
    require $workerFile;
}
