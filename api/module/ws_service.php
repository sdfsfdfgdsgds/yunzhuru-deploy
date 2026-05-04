<?php

function checkAdmin($pdo) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限操作，仅管理员可执行');
    }
    return $user;
}

function start(PDO $pdo, array $input) {
    checkAdmin($pdo);

    $serviceDir = dirname(__DIR__, 2) . '/websocket';
    $runFile = $serviceDir . '/ws.ws';     // 可执行文件
    $pidFile = dirname(__DIR__, 1) . '/.ws.pid'; // 与 Go 程序保持一致

    // 判断是否已运行
    if (file_exists($pidFile)) {
        $pid = (int)trim(file_get_contents($pidFile));
        if ($pid > 0 && posix_kill($pid, 0)) {
            return ['message' => "服务已在运行中 [PID: $pid]"];
        }
    }

    // 启动服务（进入目录后执行）
    $cmd = "cd " . escapeshellarg($serviceDir) . " && nohup ./ws > /dev/null 2>&1 &";
    $cmd = "nohup " . escapeshellarg($runFile) . " > /dev/null 2>&1 &";

    shell_exec($cmd);
    sleep(1);

    // 启动后再检查 pid 是否存在且有效
    if (!file_exists($pidFile)) {
        throw new Exception('服务启动失败：未生成 PID 文件');
    }

    $pid = (int)trim(file_get_contents($pidFile));
    if (!$pid || !posix_kill($pid, 0)) {
        throw new Exception('服务启动失败：进程未运行'.$cmd);
    }

    return ['message' => "服务已启动 [PID: $pid]"];
}


function stop(PDO $pdo, array $input) {
    checkAdmin($pdo);

    $serviceDir = dirname(__DIR__, 2) . '/websocket';
    $pidFile = dirname(__DIR__, 1) . '/.ws.pid';
    $runFile = $serviceDir . '/ws.ws';

    $killed = [];

    // 1. 如果有 PID 文件优先处理
    if (file_exists($pidFile)) {
        $pid = (int)trim(file_get_contents($pidFile));

        if ($pid > 0 && posix_kill($pid, 0)) {
            // 正常终止
            posix_kill($pid, SIGTERM);
            usleep(500000);

            // 再检测一次是否成功退出
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
            }
            $killed[] = $pid;
        }

        // 清空 PID 文件
        file_put_contents($pidFile, '');
    }

    // 2. 再次彻底扫描是否有遗留进程
    $list = shell_exec("ps -ef | grep " . escapeshellarg($runFile) . " | grep -v grep | awk '{print $2}'");

    if ($list) {
        $pids = array_filter(array_map('trim', explode("\n", $list)));
        foreach ($pids as $p) {
            if ($p > 0) {
                posix_kill((int)$p, SIGKILL);
                $killed[] = (int)$p;
            }
        }
    }

    if (empty($killed)) {
        return ['message' => "没有发现需要清理的 ws 进程"];
    }

    return ['message' => "已停止并清理 WS 进程", 'killed' => $killed];
}




function status(PDO $pdo, array $input) {
    checkAdmin($pdo);

    $pidFile = dirname(__DIR__, 1) . '/.ws.pid';

    if (!file_exists($pidFile)) {
        return ['running' => false, 'message' => '服务未运行'];
    }

    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid && posix_kill($pid, 0)) {
        return ['running' => true, 'pid' => $pid, 'message' => '服务正在运行中'];
    }

    return ['running' => false, 'message' => '服务未运行，但 PID 文件存在，可能异常退出'];
}


function viewLog(PDO $pdo, array $input) {
    checkAdmin($pdo);

    $logFile = dirname(__DIR__, ) . '/.ws.log';
    if (!file_exists($logFile)) {
        throw new Exception('日志文件不存在');
    }

    $maxRead = 100 * 1024;
    $defaultRead = 20 * 1024;
    $readSize = isset($input['length']) ? (int)$input['length'] : $defaultRead;
    $readSize = max(1024, min($readSize, $maxRead));

    $fp = fopen($logFile, 'rb');
    if (!$fp) {
        throw new Exception('无法打开日志文件');
    }

    $bufferSize = 4096;
    $pos = -1;
    $buffer = '';
    $totalSize = 0;
    $lines = [];

    fseek($fp, 0, SEEK_END);
    $fileSize = ftell($fp);

    while ($fileSize + $pos > 0 && $totalSize < $readSize) {
        $seek = max(0, $fileSize + $pos - $bufferSize + 1);
        $readLen = $fileSize + $pos - $seek + 1;

        fseek($fp, $seek);
        $chunk = fread($fp, $readLen);
        $buffer = $chunk . $buffer;

        $pos -= $bufferSize;

        $parts = explode("\n", $buffer);
        $buffer = array_shift($parts); // 残留部分放回 buffer，继续往前读

        foreach (array_reverse($parts) as $line) {
            // 检查是否包含不可见、不可复制字符（如控制符）
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $line)) {
                fclose($fp);
                return [
                    'size' => $fileSize,
                    'content' => implode("\n", $lines)
                ];
            }

            $lineSize = strlen($line) + 1;
            if ($totalSize + $lineSize > $readSize) {
                break 2;
            }

            array_unshift($lines, $line);
            $totalSize += $lineSize;
        }
    }

    // 添加文件头残留部分（不含特殊字符）
    if ($buffer !== '' && $totalSize < $readSize) {
        if (!preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $buffer)) {
            array_unshift($lines, $buffer);
        }
    }

    fclose($fp);

    return [
        'size' => $fileSize,
        'content' => implode("\n", $lines)
    ];
}



function clearLog(PDO $pdo, array $input) {
    checkAdmin($pdo); // 鉴权：必须是管理员

    $logFile = dirname(__DIR__, ) . '/.ws.log';

    if (!file_exists($logFile)) {
        throw new Exception('日志文件不存在');
    }

    if (!is_writable($logFile)) {
        throw new Exception('日志文件不可写，无法清空');
    }

    $fp = fopen($logFile, 'c');
    if (!$fp) {
        throw new Exception('无法打开日志文件');
    }

    // 清空内容
    ftruncate($fp, 0);
    fclose($fp);

    return ['message' => '日志已清空'];
}


