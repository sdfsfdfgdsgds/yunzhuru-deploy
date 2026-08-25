<?php

function ensureSystemSetting(PDO $pdo, string $key, string $value, string $title, string $note, string $type) {
    $stmt = $pdo->prepare("SELECT id FROM cainiao_system_setting WHERE key_name = :key LIMIT 1");
    $stmt->execute([':key' => $key]);
    if ($stmt->fetchColumn()) {
        $update = $pdo->prepare("
            UPDATE cainiao_system_setting
            SET title = :title, note = :note, type = :type
            WHERE key_name = :key
        ");
        $update->execute([
            ':key'   => $key,
            ':title' => $title,
            ':note'  => $note,
            ':type'  => $type
        ]);
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO cainiao_system_setting (key_name, key_value, title, note, type)
        VALUES (:key, :value, :title, :note, :type)
    ");
    $insert->execute([
        ':key'   => $key,
        ':value' => $value,
        ':title' => $title,
        ':note'  => $note,
        ':type'  => $type
    ]);
}

function ensureDynamicSettings(PDO $pdo) {
    ensureSystemSetting(
        $pdo,
        'dns_pool',
        '0',
        '抗污染解析（DoH+DNS池）',
        '开启后壳端域名请求优先使用DoH解析，失败后回退DNS池和系统DNS；保存后会清理远程配置文件并异步同步存储桶配置',
        'switch'
    );
}

function clearRemoteConfigCache() {
    $tempDir = __DIR__ . '/../../temp';
    if (!is_dir($tempDir)) {
        return;
    }

    foreach (new DirectoryIterator($tempDir) as $file) {
        if ($file->isDot() || !$file->isFile()) {
            continue;
        }
        if (strtolower($file->getExtension()) === 'json') {
            @unlink($file->getPathname());
        }
    }
}

function pushAllBucketConfigsAsync() {
    $script = realpath(__DIR__ . '/../../service/push_all_configs.php');
    if ($script) {
        exec("php " . escapeshellarg($script) . " > /dev/null 2>&1 &");
    }
}

function getSettings(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    ensureDynamicSettings($pdo);

    $stmt = $pdo->query("SELECT id, key_name, key_value, title, note, type FROM cainiao_system_setting ORDER BY id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updateSetting(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    if (empty($input) || !is_array($input)) throw new Exception('参数错误');

    ensureDynamicSettings($pdo);

    $stmt = $pdo->prepare("UPDATE cainiao_system_setting SET key_value = :val WHERE key_name = :key");
    $dnsPoolChanged = array_key_exists('dns_pool', $input);

    foreach ($input as $key => $val) {
        if (!is_string($key)) continue; // 忽略无效键名
        $stmt->execute([
            ':val' => $val,
            ':key' => $key
        ]);
    }

    if ($dnsPoolChanged) {
        // 总开关与节点池共用同一失效链路：同时清 Redis DB0 配置键、
        // 磁盘 JSON 并去重触发全量桶同步，避免仅删磁盘后 Redis 继续下发旧开关。
        if (function_exists('configDeliveryInvalidateAndSync')) {
            configDeliveryInvalidateAndSync($pdo);
        } else {
            clearRemoteConfigCache();
            pushAllBucketConfigsAsync();
        }
    }

    return ['message' => '设置已保存'];
}

function clearTempFiles(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限');
    }

    $tempDir = __DIR__ . '/../../temp';

    if (!is_dir($tempDir)) {
        throw new Exception('temp 目录不存在');
    }

    // 单次最多处理数量（非常关键）
    $limit = 500;
    $deleted = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($deleted >= $limit) {
            break;
        }

        $path = $file->getRealPath();
        if ($path === false) {
            continue;
        }

        if ($file->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }

        $deleted++;
    }

    return [
        'message' => "本次已清理 {$deleted} 个文件/目录",
        'limit'   => $limit,
        'done'    => ($deleted < $limit)
    ];
}


/*function clearTempFiles(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    $tempDir = __DIR__ . '/../../temp';

    if (!is_dir($tempDir)) {
        throw new Exception('temp 目录不存在');
    }

    $deleted = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
        $deleted++;
    }

    return ['message' => "清除完成，共处理 $deleted 个文件/目录"];
}*/
