<?php

function checkAdmin($pdo) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限操作，仅管理员可执行');
    }
    return $user;
}

/**
 * 返回 WebSocket 可执行文件所在目录。
 *
 * Go 进程的日志和 PID 文件都是相对于工作目录创建的，因此启动、停止、
 * 状态查询和日志操作必须共享这一目录，避免后台显示与实际进程脱节。
 */
function websocketRuntimeDir(): string {
    return dirname(__DIR__, 2) . '/websocket';
}

/** 返回 WebSocket 进程 PID 文件的统一路径。 */
function websocketPidFile(): string {
    return websocketRuntimeDir() . '/.ws.pid';
}

/** 返回 WebSocket 运行日志的统一路径。 */
function websocketLogFile(): string {
    return websocketRuntimeDir() . '/.ws.log';
}

/** 读取有效 PID；文件不存在、内容异常或进程已退出时返回 null。 */
function websocketReadPid(string $pidFile): ?int {
    if (!is_file($pidFile)) {
        return null;
    }
    $pid = filter_var(trim((string)@file_get_contents($pidFile)), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($pid === false || !function_exists('posix_kill') || !@posix_kill($pid, 0)) {
        return null;
    }
    return (int)$pid;
}

function start(PDO $pdo, array $input) {
    checkAdmin($pdo);

    $serviceDir = websocketRuntimeDir();
    $runFile = $serviceDir . '/ws.ws';
    $pidFile = websocketPidFile();

    // 判断是否已运行
    $runningPid = websocketReadPid($pidFile);
    if ($runningPid !== null) {
        return ['message' => "服务已在运行中 [PID: $runningPid]"];
    }
    if (!is_file($runFile) || !is_executable($runFile)) {
        throw new Exception('服务启动失败：找不到可执行文件或文件不可执行');
    }
    if (is_file($pidFile)) {
        @file_put_contents($pidFile, '');
    }

    // 先切换到运行目录再用绝对路径后台启动：Go 写入的 PID/日志位置固定，
    // 进程命令行也能被 stop() 的遗留进程扫描准确识别。
    $cmd = 'cd ' . escapeshellarg($serviceDir)
        . ' && nohup ' . escapeshellarg($runFile)
        . ' > /dev/null 2>&1 < /dev/null &';

    shell_exec($cmd);
    usleep(800000);

    // 启动后再检查 pid 是否存在且有效
    $pid = websocketReadPid($pidFile);
    if ($pid === null) {
        throw new Exception('服务启动失败：未生成 PID 文件');
    }

    return ['message' => "服务已启动 [PID: $pid]"];
}


function stop(PDO $pdo, array $input) {
    checkAdmin($pdo);

    $serviceDir = websocketRuntimeDir();
    $pidFile = websocketPidFile();
    $runFile = $serviceDir . '/ws.ws';

    $killed = [];

    // 1. 如果有 PID 文件优先处理
    if (is_file($pidFile)) {
        $pid = filter_var(trim((string)@file_get_contents($pidFile)), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($pid !== false && function_exists('posix_kill') && @posix_kill($pid, 0)) {
            // 正常终止
            posix_kill($pid, SIGTERM);
            usleep(500000);

            // 再检测一次是否成功退出
            if (@posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
            }
            $killed[] = $pid;
        }

        // 清空 PID 文件
        @file_put_contents($pidFile, '');
    }

    // 2. 再次彻底扫描是否有遗留进程
    $list = shell_exec("ps -ef | grep -- " . escapeshellarg($runFile) . " | grep -v grep | awk '{print $2}'");

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

    $pidFile = websocketPidFile();

    if (!is_file($pidFile)) {
        return ['running' => false, 'message' => '服务未运行'];
    }

    $pid = websocketReadPid($pidFile);
    if ($pid !== null) {
        return ['running' => true, 'pid' => $pid, 'message' => '服务正在运行中'];
    }

    return ['running' => false, 'message' => '服务未运行，但 PID 文件存在，可能异常退出'];
}


function viewLog(PDO $pdo, array $input) {
    checkAdmin($pdo);

    $logFile = websocketLogFile();
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

    $logFile = websocketLogFile();

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
