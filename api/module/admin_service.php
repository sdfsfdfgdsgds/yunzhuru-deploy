<?php

require_once __DIR__ . '/../utils/ServiceLog.php';

function start(PDO $pdo, array $input) {
    Auth::requireAdmin($pdo);

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

function stop(PDO $pdo, array $input) {
    Auth::requireAdmin($pdo);

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
    Auth::requireAdmin($pdo);

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
function viewLog(PDO $pdo, array $input) {
    Auth::requireAdmin($pdo);
    return readServiceLogTail('/var/log/supervisor/worker.log', $input);
}



function clearLog(PDO $pdo, array $input) {
    Auth::requireAdmin($pdo); // 鉴权：必须是管理员
    return clearServiceLog('/var/log/supervisor/worker.log');
}


function getSystemInfo(PDO $pdo, array $input): array
{
    Auth::requireAdmin($pdo); // 鉴权：必须是管理员
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

