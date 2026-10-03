<?php

/**
 * 读取应用对应的配置记录。
 *
 * 所有需要按应用读取配置的 API 都通过这个入口完成归属校验：管理员可以
 * 读取任意应用，普通用户必须同时拥有应用记录。返回值为配置主键，不存在
 * 时返回 null；调用方可据此统一返回“无权限或配置不存在”。
 */
function resolveConfigIdByApk(PDO $pdo, int $userId, int $apkId, bool $isAdmin = false): ?int
{
    if ($apkId <= 0) {
        return null;
    }

    if ($isAdmin) {
        $stmt = $pdo->prepare(
            'SELECT id FROM cainiao_apk_config WHERE apk_id = :apk_id LIMIT 1'
        );
        $stmt->execute([':apk_id' => $apkId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT c.id
             FROM cainiao_apk_config c
             INNER JOIN cainiao_apk a ON a.id = c.apk_id
             WHERE c.apk_id = :apk_id AND a.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            ':apk_id' => $apkId,
            ':user_id' => $userId,
        ]);
    }

    $configId = $stmt->fetchColumn();
    return $configId === false ? null : (int)$configId;
}
