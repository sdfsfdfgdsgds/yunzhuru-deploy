<?php
/**
 * 配置分发桶管理 API。
 *
 * 支持 B2、AWS S3、Cloudflare R2 的资料管理、按字段查看凭据、连接测试、
 * config/{APPID}.enc 文件盘点、全量同步，以及新版壳成功应用配置后的命中回执。
 */

require_once __DIR__ . '/../utils/BucketFeature.php';
require_once __DIR__ . '/../utils/S3Client.php';

/** 管理员鉴权并确保桶功能表结构已经就绪。 */
function bucketRequireAdmin(PDO $pdo): array {
    $user = Auth::check($pdo);
    if (($user['role'] ?? '') !== 'admin') throw new Exception('无权限');
    ensureBucketFeatureSchema($pdo);
    return $user;
}

/** 根据主键读取桶的完整数据库记录。 */
function bucketFindById(PDO $pdo, int $bucketId): array {
    if ($bucketId <= 0) throw new InvalidArgumentException('桶 ID 格式错误');
    $stmt = $pdo->prepare('SELECT * FROM cainiao_s3_bucket WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $bucketId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('存储桶不存在');
    return $row;
}

/**
 * 返回不含原始敏感值的桶记录。
 * 凭据摘要只有编辑详情需要，列表和文件接口不解密也不返回任何凭据片段。
 */
function bucketSanitizeRow(array $row, bool $includeCredentialDisplays = false): array {
    $safe = $row;
    if ($includeCredentialDisplays) {
        $safe['login_account_display'] = bucketMaskSecret($row['login_account'] ?? '');
        $safe['login_password_display'] = bucketMaskSecret($row['login_password'] ?? '');
        $safe['access_key_display'] = bucketMaskSecret($row['access_key'] ?? '');
        $safe['secret_key_display'] = bucketMaskSecret($row['secret_key'] ?? '');
    }
    $provider = strtolower((string)($row['provider'] ?? ''));
    $safe['provider_label'] = bucketProviderLabel($provider);
    if ($provider === 's3') {
        try {
            bucketValidateEndpoint('s3', (string)($row['endpoint'] ?? ''));
        } catch (Throwable $ignored) {
            $safe['provider_label'] = '旧版 S3 兼容端点';
        }
    }
    $safe['domain'] = bucketSafeStoredPublicUrl((string)($row['domain'] ?? ''));
    $safe['last_push_at_display'] = '';
    if (!empty($row['last_push_at'])) {
        try {
            $time = new DateTimeImmutable((string)$row['last_push_at'], new DateTimeZone('UTC'));
            $safe['last_push_at_display'] = $time->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        } catch (Throwable $ignored) {
            $safe['last_push_at_display'] = (string)$row['last_push_at'];
        }
    }
    unset($safe['login_account'], $safe['login_password'], $safe['access_key'], $safe['secret_key']);
    return $safe;
}

/** 启动全量配置后台同步；启动失败不影响桶资料事务。 */
function bucketScheduleFullSync(): bool {
    $script = realpath(__DIR__ . '/../../service/push_all_configs.php');
    if (!$script || !function_exists('exec')) return false;
    $output = [];
    $exitCode = 1;
    @exec('php ' . escapeshellarg($script) . ' > /dev/null 2>&1 & echo $!', $output, $exitCode);
    return $exitCode === 0 && !empty($output) && ctype_digit(trim((string)end($output)));
}

/** 对一个具体对象做公开读取检查；该结果不计入壳端命中统计。 */
function bucketCheckPublicObject(string $url): array {
    if ($url === '' || !function_exists('curl_init')) {
        return ['checked' => 0, 'ok' => 0, 'http_code' => 0, 'message' => '没有可检查的公开对象'];
    }
    $safeIps = bucketPublicUrlSafeIps($url);
    if (empty($safeIps)) {
        return ['checked' => 1, 'ok' => 0, 'http_code' => 0, 'message' => '公开地址未解析到安全公网 IP'];
    }
    $host = trim((string)(parse_url($url, PHP_URL_HOST) ?: ''), '[]');
    $port = (int)(parse_url($url, PHP_URL_PORT) ?: 443);
    $pinnedIp = (string)$safeIps[0];
    if (strpos($pinnedIp, ':') !== false) $pinnedIp = '[' . $pinnedIp . ']';
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPGET => true,
        // 使用与壳端一致的 GET，只请求第一个字节，避免 HEAD 在部分 CDN 上产生假故障。
        CURLOPT_RANGE => '0-0',
        CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'Pragma: no-cache'],
        CURLOPT_USERAGENT => 'yunzhuru-bucket-check/1.0',
        // 将本次 cURL 连接固定到上面已校验的公网 IP，关闭 DNS rebinding 窗口。
        CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinnedIp}"],
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($ch, $options);
    curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (string)curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['checked' => 1, 'ok' => 1, 'http_code' => $httpCode, 'message' => '公开 GET 读取正常'];
    }
    return [
        'checked' => 1,
        'ok' => 0,
        'http_code' => $httpCode,
        'message' => $error !== '' ? $error : ($httpCode > 0 ? "HTTP {$httpCode}" : '公开读取失败'),
    ];
}

/** 返回指定桶的文件级聚合统计，以 APPID 为键。 */
function bucketFileStatsMap(PDO $pdo, int $bucketId): array {
    $today = bucketFeatureToday();
    $stmt = $pdo->prepare("SELECT app_id,
        SUM(hit_count) AS total_hits,
        SUM(CASE WHEN stat_date = :today THEN hit_count ELSE 0 END) AS today_hits,
        DATE_FORMAT(DATE_ADD(MAX(last_seen_at), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS last_seen_at
        FROM cainiao_s3_bucket_file_stats
        WHERE bucket_id = :bucket_id
        GROUP BY app_id");
    $stmt->execute([':today' => $today, ':bucket_id' => $bucketId]);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int)$row['app_id']] = [
            'total_hits' => (int)$row['total_hits'],
            'today_hits' => (int)$row['today_hits'],
            'last_seen_at' => (string)($row['last_seen_at'] ?? ''),
        ];
    }
    return $result;
}

/**
 * 计算某个桶当前应该具备的 config/{APPID}.enc 集合。
 *
 * 有显式 bucket_ids 的成功任务沿用任务选择；没有显式选择的旧任务沿用 BucketPush
 * 的兼容合同，即当前所有 enabled 桶都应存在该应用配置。
 */
function bucketExpectedApps(PDO $pdo, array $bucketRow, bool $internalOnly = true): array {
    ensureApkDeleteMarkerTable($pdo);
    $stmt = $pdo->query("SELECT a.id, a.name, a.package, t.bucket_ids
        FROM cainiao_apk a
        INNER JOIN cainiao_inject_task t ON t.apk_id = a.id AND t.status_text = '编译成功'
        LEFT JOIN cainiao_apk_deleted d ON d.apk_id = a.id
        WHERE d.apk_id IS NULL
        ORDER BY a.id ASC, t.id ASC");

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $appId = (int)$row['id'];
        if (!isset($grouped[$appId])) {
            $grouped[$appId] = [
                'app_id' => $appId,
                'app_name' => (string)($row['name'] ?? ''),
                'package_name' => (string)($row['package'] ?? ''),
                'selected_ids' => [],
            ];
        }
        $ids = json_decode((string)($row['bucket_ids'] ?? ''), true);
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id > 0) $grouped[$appId]['selected_ids'][$id] = true;
            }
        }
    }

    $bucketId = (int)$bucketRow['id'];
    $enabled = (int)($bucketRow['enabled'] ?? 0) === 1;
    $expected = [];
    foreach ($grouped as $app) {
        $hasExplicitSelection = !empty($app['selected_ids']);
        if (($hasExplicitSelection && isset($app['selected_ids'][$bucketId])) || (!$hasExplicitSelection && $enabled)) {
            $appId = (int)$app['app_id'];
            $expected[$appId] = [
                'key' => "config/{$appId}.enc",
                'app_id' => $appId,
                'app_name' => $app['app_name'],
                'package_name' => $app['package_name'],
            ];
        }
    }
    return $expected;
}

/** 获取全部桶；敏感字段只返回摘要，并带桶级今日/累计统计。 */
function getBuckets(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $today = bucketFeatureToday();
    $stmt = $pdo->prepare("SELECT b.*,
        COALESCE(s.total_hits, 0) AS total_hits,
        COALESCE(s.ok_hits, 0) AS ok_hits,
        COALESCE(s.fail_hits, 0) AS fail_hits,
        COALESCE(s.today_hits, 0) AS today_hits,
        COALESCE(s.today_ok_hits, 0) AS today_ok_hits,
        COALESCE(s.today_fail_hits, 0) AS today_fail_hits,
        COALESCE(s.last_seen_at, '') AS last_seen_at
        FROM cainiao_s3_bucket b
        LEFT JOIN (
            SELECT bucket_id,
                SUM(hit_count) AS total_hits,
                SUM(ok_count) AS ok_hits,
                SUM(fail_count) AS fail_hits,
                SUM(CASE WHEN stat_date = :today THEN hit_count ELSE 0 END) AS today_hits,
                SUM(CASE WHEN stat_date = :today2 THEN ok_count ELSE 0 END) AS today_ok_hits,
                SUM(CASE WHEN stat_date = :today3 THEN fail_count ELSE 0 END) AS today_fail_hits,
                DATE_FORMAT(DATE_ADD(MAX(last_seen_at), INTERVAL 8 HOUR), '%Y-%m-%d %H:%i:%s') AS last_seen_at
            FROM cainiao_s3_bucket_stats
            GROUP BY bucket_id
        ) s ON s.bucket_id = b.id
        ORDER BY b.id ASC");
    $stmt->execute([':today' => $today, ':today2' => $today, ':today3' => $today]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $safe = bucketSanitizeRow($row);
        $safe['stats'] = [
            'total_hits' => (int)$row['total_hits'],
            'ok_hits' => (int)$row['ok_hits'],
            'fail_hits' => (int)$row['fail_hits'],
            'today_hits' => (int)$row['today_hits'],
            'today_ok_hits' => (int)$row['today_ok_hits'],
            'today_fail_hits' => (int)$row['today_fail_hits'],
            'last_seen_at' => (string)$row['last_seen_at'],
        ];
        foreach (['total_hits', 'ok_hits', 'fail_hits', 'today_hits', 'today_ok_hits', 'today_fail_hits', 'last_seen_at'] as $field) {
            unset($safe[$field]);
        }
        $rows[] = $safe;
    }
    return $rows;
}

/** 获取单个桶的非敏感详情；编辑页不会一次性得到四项原始凭据。 */
function getBucket(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    return bucketSanitizeRow(bucketFindById($pdo, (int)($input['id'] ?? 0)), true);
}

/** 管理员点击眼睛时一次只查看一个敏感字段。 */
function revealBucketCredential(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $fields = [
        'account' => 'login_account',
        'login_account' => 'login_account',
        'password' => 'login_password',
        'login_password' => 'login_password',
        'access_key' => 'access_key',
        'secret_key' => 'secret_key',
    ];
    $requested = strtolower(trim((string)($input['field'] ?? '')));
    if (!isset($fields[$requested])) throw new InvalidArgumentException('敏感字段类型错误');
    $row = bucketFindById($pdo, (int)($input['id'] ?? 0));
    $column = $fields[$requested];
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header('Expires: 0');
    return ['field' => $requested, 'value' => bucketDecryptSecret((string)($row[$column] ?? ''))];
}

/** 新增桶并用 AES-256-GCM 保存四项敏感字段。 */
function addBucket(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $record = bucketNormalizeRecord($input);
    $stmt = $pdo->prepare("INSERT INTO cainiao_s3_bucket
        (name, provider, login_account, login_password, note, access_key, secret_key,
         endpoint, bucket, region, domain, enabled, inject)
        VALUES (:name, :provider, :login_account, :login_password, :note, :access_key, :secret_key,
         :endpoint, :bucket, :region, :domain, :enabled, :inject)");
    $stmt->execute([
        ':name' => $record['name'],
        ':provider' => $record['provider'],
        ':login_account' => bucketEncryptSecret($record['login_account']),
        ':login_password' => bucketEncryptSecret($record['login_password']),
        ':note' => $record['note'],
        ':access_key' => bucketEncryptSecret($record['access_key']),
        ':secret_key' => bucketEncryptSecret($record['secret_key']),
        ':endpoint' => $record['endpoint'],
        ':bucket' => $record['bucket'],
        ':region' => $record['region'],
        ':domain' => $record['domain'],
        ':enabled' => $record['enabled'],
        ':inject' => $record['inject'],
    ]);
    $id = (int)$pdo->lastInsertId();
    $scheduled = bucketScheduleFullSync();
    return [
        'message' => $scheduled
            ? '存储桶已新增，已启动后台同步'
            : '存储桶已新增，后台同步未启动，请使用“同步全部配置”',
        'id' => $id,
        'sync_scheduled' => $scheduled ? 1 : 0,
    ];
}

/** 更新桶；未填写的敏感字段保持原值，真实新值会重新加密。 */
function updateBucket(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $id = (int)($input['id'] ?? 0);
    $existing = bucketFindById($pdo, $id);
    $record = bucketNormalizeRecord($input, $existing);
    $stmt = $pdo->prepare("UPDATE cainiao_s3_bucket SET
        name=:name, provider=:provider, login_account=:login_account, login_password=:login_password,
        note=:note, access_key=:access_key, secret_key=:secret_key, endpoint=:endpoint,
        bucket=:bucket, region=:region, domain=:domain, enabled=:enabled, inject=:inject
        WHERE id=:id");
    $stmt->execute([
        ':id' => $id,
        ':name' => $record['name'],
        ':provider' => $record['provider'],
        ':login_account' => bucketEncryptSecret($record['login_account']),
        ':login_password' => bucketEncryptSecret($record['login_password']),
        ':note' => $record['note'],
        ':access_key' => bucketEncryptSecret($record['access_key']),
        ':secret_key' => bucketEncryptSecret($record['secret_key']),
        ':endpoint' => $record['endpoint'],
        ':bucket' => $record['bucket'],
        ':region' => $record['region'],
        ':domain' => $record['domain'],
        ':enabled' => $record['enabled'],
        ':inject' => $record['inject'],
    ]);
    $scheduled = bucketScheduleFullSync();
    return [
        'message' => $scheduled
            ? '存储桶已更新，已启动后台同步'
            : '存储桶已更新，后台同步未启动，请使用“同步全部配置”',
        'sync_scheduled' => $scheduled ? 1 : 0,
    ];
}

/**
 * 原子更新单个开关，避免列表快捷操作用旧快照覆盖并发的资料编辑。
 */
function setBucketStatus(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $id = (int)($input['id'] ?? 0);
    bucketFindById($pdo, $id);
    $field = strtolower(trim((string)($input['field'] ?? '')));
    if (!in_array($field, ['enabled', 'inject'], true)) {
        throw new InvalidArgumentException('桶开关字段错误');
    }
    $value = (int)($input['value'] ?? 0) === 1 ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE cainiao_s3_bucket SET `{$field}`=:value WHERE id=:id");
    $stmt->execute([':value' => $value, ':id' => $id]);

    $scheduled = $field === 'enabled' ? bucketScheduleFullSync() : false;
    return [
        'message' => $field === 'enabled'
            ? ($scheduled
                ? '推送开关已更新，已启动后台同步'
                : '推送开关已更新，后台同步未启动')
            : 'APK 注入开关已更新',
        'field' => $field,
        'value' => $value,
        'sync_scheduled' => $field === 'enabled' ? ($scheduled ? 1 : 0) : null,
    ];
}

/** 删除管理记录与本系统聚合统计；云端 Bucket 和对象保持原状。 */
function deleteBucket(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $id = (int)($input['id'] ?? 0);
    bucketFindById($pdo, $id);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM cainiao_s3_bucket_stats WHERE bucket_id=:id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM cainiao_s3_bucket_file_stats WHERE bucket_id=:id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM cainiao_s3_bucket WHERE id=:id')->execute([':id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['message' => '存储桶管理记录已删除；云端 Bucket 和已有文件保持原状', 'id' => $id];
}

/** 测试已保存桶或保存前表单；测试对象使用随机名并在成功后立即清理。 */
function testBucket(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $savedId = (int)($input['id'] ?? 0);
    if ($savedId > 0) {
        $bucket = bucketFindById($pdo, $savedId);
    } else {
        $record = bucketNormalizeRecord($input);
        $bucket = $record;
    }

    $client = new S3Client(
        $bucket['access_key'],
        $bucket['secret_key'],
        $bucket['endpoint'],
        $bucket['bucket'],
        $bucket['region'] ?: 'auto'
    );
    $objectKey = '_test/yunzhuru-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.txt';
    $result = $client->putObject($objectKey, 'ok ' . gmdate('c'), 'text/plain; charset=utf-8');
    if ((int)($result['code'] ?? 500) !== 200) {
        throw new RuntimeException('连接失败：' . ($result['message'] ?? '未知错误'));
    }

    $cleanup = $client->deleteObject($objectKey);
    $cleanupOk = (int)($cleanup['code'] ?? 500) === 200;
    return [
        'message' => $cleanupOk ? '连接成功，测试对象已清理' : '连接成功；测试对象清理失败，请在桶内手工清理',
        'object_key' => $objectKey,
        'cleanup_ok' => $cleanupOk ? 1 : 0,
    ];
}

/**
 * 读取真实对象清单并与当前应该推送的应用集合对账，同时附加每个 APPID 的命中回执统计。
 */
function getBucketFiles(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    $bucket = bucketFindById($pdo, (int)($input['id'] ?? 0));
    $client = new S3Client(
        $bucket['access_key'],
        $bucket['secret_key'],
        $bucket['endpoint'],
        $bucket['bucket'],
        $bucket['region'] ?: 'auto'
    );
    $listed = $client->listObjectsV2('config/');
    if ((int)($listed['code'] ?? 500) !== 200) {
        throw new RuntimeException('读取桶文件失败：' . ($listed['message'] ?? '未知错误'));
    }

    $expected = bucketExpectedApps($pdo, $bucket);
    $fileStats = bucketFileStatsMap($pdo, (int)$bucket['id']);
    $emptyStats = ['total_hits' => 0, 'today_hits' => 0, 'last_seen_at' => ''];
    $actualKeys = [];
    $files = [];
    foreach (($listed['objects'] ?? []) as $object) {
        $key = (string)($object['key'] ?? '');
        $actualKeys[$key] = true;
        $appId = 0;
        if (preg_match('#^config/(\d+)\.enc$#', $key, $match)) $appId = (int)$match[1];
        $expectedItem = $appId > 0 && isset($expected[$appId]) ? $expected[$appId] : null;
        $files[] = array_merge($object, [
            'present' => 1,
            'expected' => $expectedItem ? 1 : 0,
            'app_id' => $appId,
            'app_name' => $expectedItem['app_name'] ?? ($appId > 0 ? '历史或未登记应用' : '其他对象'),
            'package_name' => $expectedItem['package_name'] ?? '',
            'file_scope' => $appId > 0 ? ($expectedItem ? 'app' : 'history') : 'other',
            'stats' => $appId > 0 ? ($fileStats[$appId] ?? $emptyStats) : $emptyStats,
            'public_url' => bucketPublicObjectUrl((string)$bucket['domain'], $key),
        ]);
    }
    usort($files, static function (array $a, array $b): int {
        if ((int)$a['expected'] !== (int)$b['expected']) return (int)$b['expected'] <=> (int)$a['expected'];
        return strcmp((string)$a['key'], (string)$b['key']);
    });

    $missing = [];
    foreach ($expected as $appId => $item) {
        if (isset($actualKeys[$item['key']])) continue;
        $missing[] = array_merge($item, [
            'present' => 0,
            'expected' => 1,
            'size' => 0,
            'last_modified' => '',
            'etag' => '',
            'storage_class' => '',
            'file_scope' => 'app',
            'stats' => $fileStats[$appId] ?? $emptyStats,
            'public_url' => bucketPublicObjectUrl((string)$bucket['domain'], $item['key']),
        ]);
    }

    $checkUrl = '';
    foreach ($files as $file) {
        if (!empty($file['expected'])) {
            $checkUrl = (string)$file['public_url'];
            break;
        }
    }
    if ($checkUrl === '' && !empty($files)) $checkUrl = (string)$files[0]['public_url'];

    $totalSize = 0;
    $historyCount = 0;
    $otherCount = 0;
    foreach ($files as $file) {
        $totalSize += (int)($file['size'] ?? 0);
        if (($file['file_scope'] ?? '') === 'history') $historyCount++;
        if (($file['file_scope'] ?? '') === 'other') $otherCount++;
    }

    return [
        'bucket' => bucketSanitizeRow($bucket),
        'summary' => [
            'expected_count' => count($expected),
            'actual_count' => count($files),
            'missing_count' => count($missing),
            'history_count' => $historyCount,
            'other_count' => $otherCount,
            'total_size' => $totalSize,
            'truncated' => !empty($listed['truncated']) ? 1 : 0,
            'pages' => (int)($listed['pages'] ?? 1),
        ],
        'public_check' => bucketCheckPublicObject($checkUrl),
        'files' => $files,
        'missing_files' => $missing,
    ];
}

/** 一键同步所有有成功注入记录的应用配置。 */
function pushAllConfigs(PDO $pdo, array $input) {
    bucketRequireAdmin($pdo);
    require_once __DIR__ . '/../utils/BucketPush.php';
    return pushAllConfigsToBuckets($pdo);
}

/** 注入器读取可写入新 APK 的桶公开域名。 */
function getBucketDomains(PDO $pdo, array $input) {
    Auth::check($pdo);
    ensureBucketFeatureSchema($pdo);
    $rows = $pdo->query('SELECT id, name, domain FROM cainiao_s3_bucket WHERE inject=1 ORDER BY id ASC')
        ->fetchAll(PDO::FETCH_ASSOC);
    return ['domains' => $rows];
}

/**
 * 新版壳配置桶命中回执。
 *
 * 只有 APPID、归属用户和注入时生成的 password_hash 密钥均匹配，且域名能归属到已登记桶时
 * 才会写入北京时间日聚合。该接口不要求后台 Cookie，失败也不影响壳端配置主链路。
 */
function recordBucketHit(PDO $pdo, array $input) {
    $appId = (int)($input['app_id'] ?? 0);
    $appKey = (int)($input['app_key'] ?? 0);
    $key = (string)($input['key'] ?? '');
    $domain = rtrim(trim((string)($input['bucket_domain'] ?? '')), '/');
    $packageName = trim((string)($input['package_name'] ?? ''));
    $shellVersion = (int)($input['shell_version'] ?? 0);
    if ($appId <= 0 || $appKey <= 0 || $key === '' || $domain === '' || $packageName === '' || $shellVersion < 152) {
        throw new InvalidArgumentException('桶命中回执参数不完整');
    }

    $stmt = $pdo->prepare("SELECT a.id, a.user_id, a.package
        FROM cainiao_apk a
        INNER JOIN cainiao_apk_config c ON c.apk_id = a.id
        INNER JOIN cainiao_inject_task t ON t.apk_id = a.id AND t.status_text = '编译成功'
        LEFT JOIN cainiao_apk_deleted d ON d.apk_id = a.id
        WHERE a.id=:app_id AND a.user_id=:user_id AND d.apk_id IS NULL
        LIMIT 1");
    $stmt->execute([':app_id' => $appId, ':user_id' => $appKey]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) throw new RuntimeException('应用未登记或已失效');
    if (!hash_equals((string)($app['package'] ?? ''), $packageName)) {
        throw new RuntimeException('应用包名不匹配');
    }

    $plain = (string)$appId . (string)$appKey . md5((string)$appId . (string)$appKey);
    if (!password_verify($plain, $key)) throw new RuntimeException('应用回执密钥不匹配');

    $bucketId = 0;
    $rows = $pdo->query('SELECT id, domain FROM cainiao_s3_bucket')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (hash_equals(rtrim(trim((string)$row['domain']), '/'), $domain)) {
            $bucketId = (int)$row['id'];
            break;
        }
    }
    if ($bucketId <= 0) throw new RuntimeException('桶域名未登记');

    $okRaw = $input['ok'] ?? true;
    $ok = !($okRaw === false || $okRaw === 0 || $okRaw === '0' || strtolower((string)$okRaw) === 'false');
    if (!$ok) throw new InvalidArgumentException('仅接收已成功应用配置的桶命中回执');
    $today = bucketFeatureToday();
    $stat = $pdo->prepare("INSERT INTO cainiao_s3_bucket_stats
        (bucket_id, stat_date, hit_count, ok_count, fail_count, last_seen_at)
        VALUES (:bucket_id, :stat_date, 1, :ok_count, :fail_count, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            hit_count=hit_count+1,
            ok_count=ok_count+VALUES(ok_count),
            fail_count=fail_count+VALUES(fail_count),
            last_seen_at=UTC_TIMESTAMP()");
    $stat->execute([
        ':bucket_id' => $bucketId,
        ':stat_date' => $today,
        ':ok_count' => $ok ? 1 : 0,
        ':fail_count' => $ok ? 0 : 1,
    ]);

    if ($ok) {
        $fileStat = $pdo->prepare("INSERT INTO cainiao_s3_bucket_file_stats
            (bucket_id, app_id, stat_date, hit_count, last_seen_at)
            VALUES (:bucket_id, :app_id, :stat_date, 1, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE hit_count=hit_count+1, last_seen_at=UTC_TIMESTAMP()");
        $fileStat->execute([':bucket_id' => $bucketId, ':app_id' => $appId, ':stat_date' => $today]);
    }

    return ['message' => 'ok', 'bucket_id' => $bucketId];
}
