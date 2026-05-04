<?php
function reset_redis(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = $user['role'] === 'admin';
    $apkId = isset($input['apk_id']) ? intval($input['apk_id']) : 0;
    if ($apkId < 1) {
        throw new Exception('appid不正确');
    }
    $tablePrefix = 'cainiao_';
    $apkTable = $tablePrefix . 'apk';
    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT * FROM `$apkTable`  WHERE id = :apk_id");
        $stmt->execute([':apk_id' => $apkId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM `$apkTable`  WHERE id = :apk_id AND user_id = :uid");
        $stmt->execute([':apk_id' => $apkId, ':uid' => $userId]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('未找到对应配置');
    }
    $redis = getRedisConnection(0);
    $redis->del($apkId);
    $redis->select(2);//2号库，apk映射关系
    $redis->del($apkId);
    $redis->close();

    // 同步推送配置到存储桶
    try {
        require_once __DIR__ . '/../utils/BucketPush.php';
        pushConfigWithDependents($pdo, $apkId);
    } catch (\Throwable $e) {
        error_log("[BucketPush] 刷新缓存推送失败 appId={$apkId}: " . $e->getMessage());
    }

    return ['message' => '刷新缓存成功'];
}