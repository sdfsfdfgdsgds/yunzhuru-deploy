<?php

/**
 * S3 桶配置管理（个人设置）
 * 用于注入完成后自动上传 APK 到用户配置的 S3/R2/B2 桶
 */

// 获取当前用户的 S3 配置
function getS3Config(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    return [
        's3_endpoint'    => $user['s3_endpoint'] ?? '',
        's3_access_key'  => $user['s3_access_key'] ?? '',
        's3_secret_key'  => $user['s3_secret_key'] ?? '',
        's3_bucket'      => $user['s3_bucket'] ?? '',
        's3_region'      => $user['s3_region'] ?? 'auto',
        's3_upload_path' => $user['s3_upload_path'] ?? '',
        's3_public_url'  => $user['s3_public_url'] ?? '',
    ];
}

// 保存 S3 配置
function editS3Config(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $endpoint   = trim($input['endpoint'] ?? '');
    $accessKey  = trim($input['access_key'] ?? '');
    $secretKey  = trim($input['secret_key'] ?? '');
    $bucket     = trim($input['bucket'] ?? '');
    $region     = trim($input['region'] ?? 'auto');
    $uploadPath = trim($input['upload_path'] ?? '');
    $publicUrl  = trim($input['public_url'] ?? '');
    // 去掉路径首尾斜杠
    $uploadPath = trim($uploadPath, '/');
    $publicUrl  = rtrim($publicUrl, '/');

    // 如果全部为空，视为清除配置
    if (empty($endpoint) && empty($accessKey) && empty($secretKey) && empty($bucket)) {
        $stmt = $pdo->prepare("UPDATE cainiao_user SET
            s3_endpoint = '', s3_access_key = '', s3_secret_key = '',
            s3_bucket = '', s3_region = 'auto', s3_upload_path = '', s3_public_url = ''
            WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        return '配置已清除';
    }

    // 必填校验
    if (empty($endpoint) || empty($accessKey) || empty($secretKey) || empty($bucket)) {
        throw new Exception('端点、Access Key、Secret Key、桶名不能为空');
    }

    // 连通性测试：上传一个小文件
    require_once __DIR__ . '/../utils/S3Client.php';
    $client = new S3Client($accessKey, $secretKey, $endpoint, $bucket, $region);
    $testKey = ($uploadPath ? $uploadPath . '/' : '') . '.connection_test';
    $result = $client->putObject($testKey, 'ok', 'text/plain');
    if ($result['code'] !== 200) {
        throw new Exception('连接测试失败：' . $result['message']);
    }
    // 测试完删掉
    $client->deleteObject($testKey);

    // 保存到数据库
    $stmt = $pdo->prepare("UPDATE cainiao_user SET
        s3_endpoint = :endpoint,
        s3_access_key = :access_key,
        s3_secret_key = :secret_key,
        s3_bucket = :bucket,
        s3_region = :region,
        s3_upload_path = :upload_path,
        s3_public_url = :public_url
        WHERE id = :id");
    $stmt->execute([
        ':endpoint'    => $endpoint,
        ':access_key'  => $accessKey,
        ':secret_key'  => $secretKey,
        ':bucket'      => $bucket,
        ':region'      => $region,
        ':upload_path' => $uploadPath,
        ':public_url'  => $publicUrl,
        ':id'          => $userId,
    ]);

    return '配置保存成功';
}

/**
 * 手动上传已完成任务的 APK 到 S3（后台异步执行）
 */
function uploadTaskToS3(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $taskId = (int)($input['task_id'] ?? 0);

    if (!$taskId) {
        throw new Exception('缺少 task_id');
    }

    // 检查任务归属和状态
    $stmt = $pdo->prepare("SELECT t.id, t.status_text, t.injected_apk, a.name
        FROM cainiao_inject_task t
        LEFT JOIN cainiao_apk a ON a.id = t.apk_id
        WHERE t.id = :id AND t.user_id = :uid LIMIT 1");
    $stmt->execute([':id' => $taskId, ':uid' => $userId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        // 管理员可以操作任何任务
        if ($user['role'] === 'admin') {
            $stmt = $pdo->prepare("SELECT t.id, t.status_text, t.injected_apk, a.name
                FROM cainiao_inject_task t
                LEFT JOIN cainiao_apk a ON a.id = t.apk_id
                WHERE t.id = :id LIMIT 1");
            $stmt->execute([':id' => $taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$task) {
            throw new Exception('任务不存在或无权限');
        }
    }

    if ($task['status_text'] !== '编译成功') {
        throw new Exception('只有编译成功的任务才能上传');
    }
    if (empty($task['injected_apk'])) {
        throw new Exception('注入文件不存在');
    }

    // 检查 S3 配置
    $stmt = $pdo->prepare("SELECT s3_endpoint FROM cainiao_user WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $s3 = $stmt->fetchColumn();
    if (empty($s3)) {
        throw new Exception('请先配置 S3 桶（右上角 → S3 桶配置）');
    }

    // 更新状态提示
    $pdo->prepare("UPDATE cainiao_inject_task SET status_info = '正在上传到 S3...' WHERE id = :id")
        ->execute([':id' => $taskId]);

    // 在后台执行上传（nohup + php 脚本）
    $scriptDir = dirname(__DIR__, 2) . '/service';
    $configDir = dirname(__DIR__, 2) . '/config';
    $cmd = sprintf(
        'nohup php -d memory_limit=64M -r %s > /dev/null 2>&1 &',
        escapeshellarg(
            "\$cfg = require '{$configDir}/config.php';" .
            "require_once '{$scriptDir}/tool.php';" .
            "require_once '{$scriptDir}/injector.php';" .
            "require_once '" . dirname(__DIR__) . "/utils/S3Client.php';" .
            "\$pdo = new PDO('mysql:host='.\$cfg['host'].';port='.\$cfg['port'].';dbname='.\$cfg['dbname'].';charset=utf8mb4', \$cfg['username'], \$cfg['password']);" .
            "\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);" .
            "autoUploadToS3(\$pdo, {$taskId}, " . var_export($task['name'] ?? '未知应用', true) . ");"
        )
    );
    shell_exec($cmd);

    return '上传任务已启动，请在任务列表查看进度';
}
