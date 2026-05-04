<?php
/**
 * 存储桶管理 API
 * 支持 S3/R2/B2 兼容存储桶的增删改查、测试连接、批量推送配置
 */

// 获取所有存储桶（secret_key 脱敏）
function getBuckets(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    $rows = $pdo->query("SELECT * FROM cainiao_s3_bucket ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $sk = $row['secret_key'];
        if (strlen($sk) > 8) {
            $row['secret_key_display'] = substr($sk, 0, 4) . '****' . substr($sk, -4);
        } else {
            $row['secret_key_display'] = '****';
        }
        unset($row['secret_key']); // 不返回完整密钥
    }
    return $rows;
}

// 添加存储桶
function addBucket(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    $required = ['name', 'access_key', 'secret_key', 'endpoint', 'bucket', 'domain'];
    foreach ($required as $field) {
        if (empty($input[$field])) throw new Exception("缺少参数: {$field}");
    }

    $stmt = $pdo->prepare("INSERT INTO cainiao_s3_bucket
        (name, provider, access_key, secret_key, endpoint, bucket, region, domain, enabled, inject)
        VALUES (:name, :provider, :access_key, :secret_key, :endpoint, :bucket, :region, :domain, :enabled, :inject)");
    $stmt->execute([
        ':name'       => trim($input['name']),
        ':provider'   => $input['provider'] ?? 's3',
        ':access_key' => trim($input['access_key']),
        ':secret_key' => trim($input['secret_key']),
        ':endpoint'   => rtrim(trim($input['endpoint']), '/'),
        ':bucket'     => trim($input['bucket']),
        ':region'     => !empty($input['region']) ? trim($input['region']) : 'auto',
        ':domain'     => rtrim(trim($input['domain']), '/'),
        ':enabled'    => isset($input['enabled']) ? (int)$input['enabled'] : 1,
        ':inject'     => isset($input['inject']) ? (int)$input['inject'] : 1,
    ]);

    return ['message' => '添加成功', 'id' => $pdo->lastInsertId()];
}

// 修改存储桶
function updateBucket(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');
    if (empty($input['id'])) throw new Exception('缺少ID');

    $fields = [];
    $params = [':id' => (int)$input['id']];

    $allowedFields = ['name', 'provider', 'access_key', 'endpoint', 'bucket', 'region', 'domain', 'enabled', 'inject'];
    foreach ($allowedFields as $f) {
        if (isset($input[$f])) {
            $fields[] = "`{$f}` = :{$f}";
            $params[":{$f}"] = is_string($input[$f]) ? trim($input[$f]) : $input[$f];
        }
    }
    // secret_key 只在非脱敏值时更新
    if (!empty($input['secret_key']) && strpos($input['secret_key'], '****') === false) {
        $fields[] = "secret_key = :secret_key";
        $params[':secret_key'] = trim($input['secret_key']);
    }

    if (empty($fields)) throw new Exception('无可修改字段');

    $sql = "UPDATE cainiao_s3_bucket SET " . implode(', ', $fields) . " WHERE id = :id";
    $pdo->prepare($sql)->execute($params);

    return ['message' => '修改成功'];
}

// 删除存储桶
function deleteBucket(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');
    if (empty($input['id'])) throw new Exception('缺少ID');

    $pdo->prepare("DELETE FROM cainiao_s3_bucket WHERE id = :id")->execute([':id' => (int)$input['id']]);
    return ['message' => '删除成功'];
}

// 测试存储桶连接
function testBucket(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    require_once __DIR__ . '/../utils/S3Client.php';

    // 支持传 ID（测试已保存的桶）或直接传凭据（保存前测试）
    if (!empty($input['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM cainiao_s3_bucket WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$input['id']]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) throw new Exception('存储桶不存在');
    } else {
        // 直接用前端传来的凭据
        $required = ['access_key', 'secret_key', 'endpoint', 'bucket'];
        foreach ($required as $field) {
            if (empty($input[$field])) throw new Exception("测试需要参数: {$field}");
        }
        $b = $input;
    }

    $client = new S3Client(
        $b['access_key'],
        $b['secret_key'],
        rtrim($b['endpoint'], '/'),
        $b['bucket'],
        !empty($b['region']) ? $b['region'] : 'auto'
    );

    // 上传测试文件
    $testContent = 'ok ' . date('Y-m-d H:i:s');
    $result = $client->putObject('_test/ping.txt', $testContent, 'text/plain');

    if ($result['code'] === 200) {
        return ['code' => 200, 'message' => '连接成功，测试文件已上传'];
    }
    throw new Exception('连接失败: ' . $result['message']);
}

// 一键同步所有应用配置到桶
function pushAllConfigs(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') throw new Exception('无权限');

    require_once __DIR__ . '/../utils/BucketPush.php';
    return pushAllConfigsToBuckets($pdo);
}

// 获取所有启用桶的公开域名列表（注入器调用）
function getBucketDomains(PDO $pdo, array $input) {
    $user = Auth::check($pdo);

    $rows = $pdo->query("SELECT id, name, domain FROM cainiao_s3_bucket WHERE inject = 1 ORDER BY id ASC")
                ->fetchAll(PDO::FETCH_ASSOC);
    return ['domains' => $rows];
}
