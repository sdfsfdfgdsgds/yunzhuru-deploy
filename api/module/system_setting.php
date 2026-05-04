<?php

function getSettings(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    $stmt = $pdo->query("SELECT id, key_name, key_value, title, note, type FROM cainiao_system_setting ORDER BY id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updateSetting(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    if (empty($input) || !is_array($input)) throw new Exception('参数错误');

    $stmt = $pdo->prepare("UPDATE cainiao_system_setting SET key_value = :val WHERE key_name = :key");

    foreach ($input as $key => $val) {
        if (!is_string($key)) continue; // 忽略无效键名
        $stmt->execute([
            ':val' => $val,
            ':key' => $key
        ]);
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