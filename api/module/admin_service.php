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

    // worker 由 supervisor 管理，始终自动运行，此接口仅做状态确认
    $output = shell_exec("supervisorctl status worker 2>&1");
    if ($output && strpos($output, 'RUNNING') !== false) {
        return ['message' => '服务正在运行中（由 supervisor 管理）'];
    }

    // 尝试让 supervisor 启动
    shell_exec("supervisorctl start worker 2>&1");
    sleep(2);

    $output = shell_exec("supervisorctl status worker 2>&1");
    if ($output && strpos($output, 'RUNNING') !== false) {
        return ['message' => '服务已启动'];
    }

    throw new Exception('服务启动失败，请检查 supervisor 日志');
}

function stop2(PDO $pdo, array $input) {
    checkAdmin($pdo);

    shell_exec("supervisorctl stop worker 2>&1");

    return ['message' => '停止命令已发送'];
}

function stop(PDO $pdo, array $input) {
    checkAdmin($pdo);

    // 1. 通过 supervisor 停止 worker
    shell_exec("supervisorctl stop worker 2>&1");
    sleep(1);

    // 2. 同时终止 apktool 相关进程
    $apktoolPids = shell_exec("ps aux | grep apktool | grep java | grep -v grep | awk '{print $2}'");
    $pids = preg_split('/\s+/', trim($apktoolPids));
    foreach ($pids as $pid) {
        if (is_numeric($pid)) {
            shell_exec("kill -9 " . escapeshellarg($pid));
        }
    }

    // 3. 终止 smali 相关 java 进程
    $smaliPids = shell_exec("ps aux | grep smali | grep java | grep -v grep | awk '{print $2}'");
    $smaliPids = preg_split('/\s+/', trim($smaliPids));
    foreach ($smaliPids as $pid) {
        if (is_numeric($pid)) {
            shell_exec("kill -9 " . escapeshellarg($pid));
        }
    }

    return ['message' => 'worker 已停止，相关进程已处理'];
}

function status(PDO $pdo, array $input) {
    checkAdmin($pdo);

    $output = shell_exec("supervisorctl status worker 2>&1");
    $running = $output && strpos($output, 'RUNNING') !== false;

    // 从 supervisor 输出中提取 PID，格式：worker  RUNNING   pid 38486, uptime ...
    $pid = null;
    if ($running && preg_match('/pid\s+(\d+)/', $output, $m)) {
        $pid = (int)$m[1];
    }

    return [
        'running' => $running,
        'pid'     => $pid,
        'message' => $running ? "服务正在运行中（supervisor 管理）" : '服务未运行',
    ];
}
//优化后的读日志方法,非常快
function viewLog2(PDO $pdo, array $input) {
    checkAdmin($pdo);

    // supervisor 管理的 worker 日志
    $logFile = '/var/log/supervisor/worker.log';
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
            $lineSize = strlen($line) + 1;
            if ($totalSize + $lineSize > $readSize) {
                break 2;
            }
            array_unshift($lines, $line);
            $totalSize += $lineSize;
        }
    }

    // 添加最后一段可能剩下的完整行（文件头）
    if ($buffer !== '' && $totalSize < $readSize) {
        array_unshift($lines, $buffer);
    }

    fclose($fp);

    return [
        'size' => $fileSize,
        'content' => implode("\n", $lines)
    ];
}

function viewLog(PDO $pdo, array $input) {
    checkAdmin($pdo);

    // supervisor 管理的 worker 日志
    $logFile = '/var/log/supervisor/worker.log';
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

    // supervisor 管理的 worker 日志
    $logFile = '/var/log/supervisor/worker.log';

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


function getSystemInfo(PDO $pdo, array $input): array
{
    checkAdmin($pdo); // 鉴权：必须是管理员
    // CPU核心数
    $cpu_cores = (int)trim(shell_exec("nproc"));

    // CPU负载（1分钟平均）百分比
    $load_avg = trim(shell_exec("cat /proc/loadavg | awk '{print $1}'"));
    $load_percent = (is_numeric($load_avg) && $cpu_cores > 0)
        ? round($load_avg / $cpu_cores * 100, 2)
        : 0;

    // 内存信息（单位MB）
    $meminfo = shell_exec("free -m");
    $mem_total = 0;
    $mem_used = 0;
    if ($meminfo !== false) {
        $lines = explode("\n", trim($meminfo));
        foreach ($lines as $line) {
            if (strpos($line, 'Mem:') === 0) {
                $parts = preg_split('/\s+/', $line);
                $mem_total = isset($parts[1]) ? (int)$parts[1] : 0;
                $mem_used = isset($parts[2]) ? (int)$parts[2] : 0;
                break;
            }
        }
    }

    // 磁盘信息（单位GB）
    $disk = shell_exec("df -BG / | tail -1");
    $disk_total = 0;
    $disk_used = 0;
    if ($disk !== false) {
        $parts = preg_split('/\s+/', $disk);
        $disk_total = isset($parts[1]) ? (int)rtrim($parts[1], 'G') : 0;
        $disk_used = isset($parts[2]) ? (int)rtrim($parts[2], 'G') : 0;
    }
    $load_percent2 = getCpuUsagePercent();

    return [
        'cpu_cores'   => $cpu_cores,
        'cpu_percent' => $load_percent2,
        'mem_total'   => $mem_total,
        'mem_used'    => $mem_used,
        'disk_total'  => $disk_total,
        'disk_used'   => $disk_used,
        'load_percent'=> $load_percent
    ];
}


function getCpuUsagePercent(): float {
    $stat1 = shell_exec("head -n 1 /proc/stat");
    usleep(500000); // 延迟0.5秒
    $stat2 = shell_exec("head -n 1 /proc/stat");

    if (!$stat1 || !$stat2) return 0;

    $cpu1 = preg_split('/\s+/', trim($stat1));
    $cpu2 = preg_split('/\s+/', trim($stat2));

    $idle1 = (int)$cpu1[4];
    $total1 = array_sum(array_slice($cpu1, 1, 8));

    $idle2 = (int)$cpu2[4];
    $total2 = array_sum(array_slice($cpu2, 1, 8));

    $total = $total2 - $total1;
    $idle = $idle2 - $idle1;

    if ($total === 0) return 0;

    return round(100 * ($total - $idle) / $total, 2);
}

