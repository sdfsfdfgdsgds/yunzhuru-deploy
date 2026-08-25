<?php
/**
 * 配置桶管理的共享合同。
 *
 * 该文件是桶结构、凭据存储、Provider 校验、统计口径和公开 API 地址的
 * Single Source of Truth（单一事实来源）。API、后台推送和 S3 客户端都复用这里的合同。
 */

if (!function_exists('bucketFeatureToday')) {
    /** 返回统计所用的北京日期。 */
    function bucketFeatureToday(): string {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
    }
}

if (!function_exists('bucketPublicApiUrl')) {
    /**
     * 返回写入配置文件的稳定 API 地址。
     *
     * CLI worker 没有 HTTP_HOST，因此优先使用部署变量；只在真实 HTTPS 请求中
     * 使用当前 Host，最后回退到当前正式域名。
     */
    function bucketPublicApiUrl(): string {
        $configured = trim((string)(getenv('YUNZHURU_PUBLIC_BASE_URL') ?: ''));
        if ($configured !== '') {
            return rtrim($configured, '/') . '/api/index.php';
        }

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($host !== '' && preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $host)) {
            $scheme = in_array($https, ['on', '1', 'true'], true) ? 'https' : 'http';
            return $scheme . '://' . $host . '/api/index.php';
        }

        return 'https://zkzam9hoby.top/api/index.php';
    }
}

if (!function_exists('bucketCredentialKey')) {
    /** 将部署密钥稳定派生为 AES-256 的 32 字节密钥。 */
    function bucketCredentialKey(): string {
        $secret = (string)(getenv('BUCKET_CREDENTIAL_KEY') ?: '');
        if ($secret === '') {
            throw new RuntimeException('未配置 BUCKET_CREDENTIAL_KEY');
        }
        return hash('sha256', $secret, true);
    }
}

if (!function_exists('bucketEncryptSecret')) {
    /**
     * 使用 AES-256-GCM 加密桶凭据。已加密的值保持不变，空值不生成密文。
     */
    function bucketEncryptSecret(string $plainText): string {
        if ($plainText === '' || strncmp($plainText, 'enc:v1:', 7) === 0) {
            return $plainText;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            bucketCredentialKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'yunzhuru:s3-bucket:v1',
            16
        );
        if ($cipherText === false) {
            throw new RuntimeException('桶凭据加密失败');
        }
        return 'enc:v1:' . base64_encode($nonce . $tag . $cipherText);
    }
}

if (!function_exists('bucketDecryptSecret')) {
    /**
     * 解密桶凭据；旧版明文数据直接返回，便于生产平滑迁移。
     */
    function bucketDecryptSecret(?string $storedValue): string {
        $storedValue = (string)$storedValue;
        if ($storedValue === '' || strncmp($storedValue, 'enc:v1:', 7) !== 0) {
            return $storedValue;
        }
        $payload = base64_decode(substr($storedValue, 7), true);
        if ($payload === false || strlen($payload) < 29) {
            throw new RuntimeException('桶凭据密文格式错误');
        }
        $nonce = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $cipherText = substr($payload, 28);
        $plainText = openssl_decrypt(
            $cipherText,
            'aes-256-gcm',
            bucketCredentialKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'yunzhuru:s3-bucket:v1'
        );
        if ($plainText === false) {
            throw new RuntimeException('桶凭据解密失败，请检查 BUCKET_CREDENTIAL_KEY');
        }
        return $plainText;
    }
}

if (!function_exists('bucketMaskSecret')) {
    /** 用前后四位构造管理页摘要，不暴露完整凭据。 */
    function bucketMaskSecret(?string $storedValue): string {
        $plainText = bucketDecryptSecret($storedValue);
        // 按 Unicode 字符切分，避免在中文或 Emoji 的 UTF-8 字节中间截断，
        // 否则 json_encode() 会因非法 UTF-8 直接返回 false。
        $characters = preg_split('//u', $plainText, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) return '****';
        $length = count($characters);
        if ($length === 0) return '';
        if ($length <= 8) return '****';
        return implode('', array_slice($characters, 0, 4))
            . '****'
            . implode('', array_slice($characters, -4));
    }
}

if (!function_exists('bucketProviderLabel')) {
    /** 返回 Provider 的人类可读名称。 */
    function bucketProviderLabel(string $provider): string {
        $labels = ['b2' => 'Backblaze B2', 's3' => 'AWS S3', 'r2' => 'Cloudflare R2'];
        return $labels[strtolower($provider)] ?? 'Unknown';
    }
}

if (!function_exists('bucketNormalizeProvider')) {
    /** 限定当前支持的三种 S3 兼容 Provider。 */
    function bucketNormalizeProvider($provider): string {
        $provider = strtolower(trim((string)$provider));
        if (!in_array($provider, ['b2', 's3', 'r2'], true)) {
            throw new InvalidArgumentException('桶类型只支持 Backblaze B2、AWS S3、Cloudflare R2');
        }
        return $provider;
    }
}

if (!function_exists('bucketNormalizeHttpsUrl')) {
    /**
     * 校验 Endpoint 或公开访问地址，防止账号信息、query 和 fragment 混入配置。
     */
    function bucketNormalizeHttpsUrl($value, string $label, bool $rootOnly = false): string {
        $value = trim((string)$value);
        if ($value === '') throw new InvalidArgumentException($label . '为空');
        if (preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new InvalidArgumentException($label . '中不应包含空白或控制字符');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException($label . '必须是完整 HTTPS 地址');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException($label . '中不应包含帐号信息');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException($label . '中不应包含查询参数或片段');
        }
        if (isset($parts['port']) && ((int)$parts['port'] <= 0 || (int)$parts['port'] > 65535)) {
            throw new InvalidArgumentException($label . '端口格式错误');
        }
        $host = strtolower(trim((string)$parts['host'], '[]'));
        if ($host === 'localhost'
            || substr($host, -10) === '.localhost'
            || substr($host, -6) === '.local'
            || substr($host, -9) === '.internal') {
            throw new InvalidArgumentException($label . '必须使用公网主机');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException($label . '不应指向私有或保留网络');
        }
        $path = (string)($parts['path'] ?? '');
        if ($rootOnly && $path !== '' && $path !== '/') {
            throw new InvalidArgumentException($label . '只填写服务根地址，不附加 Bucket 或对象路径');
        }
        return rtrim($value, '/');
    }
}

if (!function_exists('bucketSafeStoredPublicUrl')) {
    /**
     * 对旧数据库中未经新合同校验的公开地址做容错过滤。
     * 读接口对不合法旧值返回空链接，而不是把危险 scheme 交给浏览器。
     */
    function bucketSafeStoredPublicUrl(?string $value): string {
        try {
            return bucketNormalizeHttpsUrl((string)$value, '公开访问地址');
        } catch (Throwable $ignored) {
            return '';
        }
    }
}

if (!function_exists('bucketInferRegion')) {
    /** 从官方 Endpoint 推导 B2/S3 Region，R2 固定为 auto。 */
    function bucketInferRegion(string $provider, string $endpoint): string {
        $host = strtolower((string)(parse_url($endpoint, PHP_URL_HOST) ?: ''));
        if ($provider === 'r2') return 'auto';
        if ($provider === 'b2' && preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/', $host, $match)) {
            return $match[1];
        }
        if ($provider === 's3') {
            if ($host === 's3.amazonaws.com') return 'us-east-1';
            if (preg_match('/^s3[.-]([a-z0-9-]+)\.amazonaws\.com(?:\.cn)?$/', $host, $match)) {
                return $match[1];
            }
        }
        return '';
    }
}

if (!function_exists('bucketValidateEndpoint')) {
    /** 校验 Provider 与官方 Endpoint 的对应关系。 */
    function bucketValidateEndpoint(string $provider, string $endpoint): void {
        $host = strtolower((string)(parse_url($endpoint, PHP_URL_HOST) ?: ''));
        if ($provider === 'b2' && !preg_match('/^s3\.[a-z0-9-]+\.backblazeb2\.com$/', $host)) {
            throw new InvalidArgumentException('B2 Endpoint 应类似 https://s3.us-west-004.backblazeb2.com');
        }
        if ($provider === 'r2' && !preg_match('/(^|\.)r2\.cloudflarestorage\.com$/', $host)) {
            throw new InvalidArgumentException('R2 Endpoint 应类似 https://ACCOUNT_ID.r2.cloudflarestorage.com');
        }
        if ($provider === 's3' && !preg_match('/^s3(?:[.-][a-z0-9-]+)?\.amazonaws\.com(?:\.cn)?$/', $host)) {
            throw new InvalidArgumentException('S3 Endpoint 应类似 https://s3.ap-east-1.amazonaws.com');
        }
    }
}

if (!function_exists('bucketNormalizeRecord')) {
    /**
     * 合并新增/编辑输入并执行完整服务端校验。
     * 编辑时敏感字段为空或包含脱敏占位符，表示继续使用数据库原值。
     */
    function bucketNormalizeRecord(array $input, ?array $existing = null): array {
        $existing = $existing ?: [];
        $pick = static function (string $key, $default = '') use ($input, $existing) {
            if (array_key_exists($key, $input)) return $input[$key];
            if (array_key_exists($key, $existing)) return $existing[$key];
            return $default;
        };

        $provider = bucketNormalizeProvider($pick('provider', 's3'));
        $name = mb_substr(trim((string)$pick('name')), 0, 100, 'UTF-8');
        $bucket = trim((string)$pick('bucket'));
        if ($name === '') throw new InvalidArgumentException('桶名称为空');
        if ($bucket === '') throw new InvalidArgumentException('Bucket 为空');
        if (preg_match('~[\\\\/?#]~', $bucket)) throw new InvalidArgumentException('Bucket 名称中不应包含路径字符');

        $endpoint = bucketNormalizeHttpsUrl($pick('endpoint'), 'Endpoint', true);
        $domain = bucketNormalizeHttpsUrl($pick('domain'), '公开访问地址');
        bucketValidateEndpoint($provider, $endpoint);

        $region = strtolower(trim((string)$pick('region')));
        if ($provider === 'r2') {
            $region = 'auto';
        } elseif ($region === '' || $region === 'auto') {
            $region = bucketInferRegion($provider, $endpoint);
        }
        if ($region === '') throw new InvalidArgumentException(bucketProviderLabel($provider) . ' 需要填写 Region');
        if (!preg_match('/^[a-z0-9-]{2,64}$/', $region)) throw new InvalidArgumentException('Region 格式错误');

        $credential = static function (string $field, array $aliases = []) use ($input, $existing): string {
            // 平台登录资料是可选字段，仅通过显式 clear 标记删除，
            // 避免把编辑时的空输入误解为删除。
            $clearField = 'clear_' . $field;
            if (in_array($field, ['login_account', 'login_password'], true)
                && !empty($input[$clearField])) {
                return '';
            }
            $incomingFound = false;
            $incoming = '';
            foreach (array_merge([$field], $aliases) as $key) {
                if (array_key_exists($key, $input)) {
                    $incomingFound = true;
                    $incoming = trim((string)$input[$key]);
                    break;
                }
            }
            // 旧管理页可能会回传纯占位符；只识别完整占位值，
            // 合法新凭据中即使包含星号也能正常轮换。
            if ($incomingFound && $incoming !== '' && $incoming !== '****') return $incoming;
            return bucketDecryptSecret((string)($existing[$field] ?? ''));
        };

        $loginAccount = $credential('login_account', ['account']);
        $loginPassword = $credential('login_password', ['password']);
        $accessKey = $credential('access_key');
        $secretKey = $credential('secret_key');
        if ($accessKey === '') throw new InvalidArgumentException('Access Key 为空');
        if ($secretKey === '') throw new InvalidArgumentException('Secret Key 为空');

        return [
            'name' => $name,
            'provider' => $provider,
            'login_account' => mb_substr($loginAccount, 0, 255, 'UTF-8'),
            'login_password' => mb_substr($loginPassword, 0, 512, 'UTF-8'),
            'note' => mb_substr(trim((string)$pick('note')), 0, 512, 'UTF-8'),
            'access_key' => mb_substr($accessKey, 0, 255, 'UTF-8'),
            'secret_key' => mb_substr($secretKey, 0, 512, 'UTF-8'),
            'endpoint' => $endpoint,
            'bucket' => mb_substr($bucket, 0, 255, 'UTF-8'),
            'region' => $region,
            'domain' => $domain,
            'enabled' => (int)$pick('enabled', 1) === 0 ? 0 : 1,
            'inject' => (int)$pick('inject', 1) === 0 ? 0 : 1,
        ];
    }
}

if (!function_exists('bucketMysqlColumnExists')) {
    /** 检查 MySQL/MariaDB 数据表字段是否存在。 */
    function bucketMysqlColumnExists(PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column");
        $stmt->execute([':column' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('bucketEnsureMysqlColumn')) {
    /** 以幂等方式增加 MySQL/MariaDB 字段。 */
    function bucketEnsureMysqlColumn(PDO $pdo, string $table, string $column, string $definition): void {
        if (!bucketMysqlColumnExists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

if (!function_exists('bucketEnsureMysqlVarcharLength')) {
    /** 仅在现有 VARCHAR 长度不足时执行 ALTER，避免 API 请求反复发起 DDL。 */
    function bucketEnsureMysqlVarcharLength(PDO $pdo, string $table, string $column, int $length, string $nullClause = 'NOT NULL'): void {
        $stmt = $pdo->prepare(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1'
        );
        $stmt->execute([':table' => $table, ':column' => $column]);
        $currentLength = (int)$stmt->fetchColumn();
        if ($currentLength > 0 && $currentLength < $length) {
            $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` varchar({$length}) {$nullClause}");
        }
    }
}

if (!function_exists('bucketMigratePlaintextCredentials')) {
    /**
     * 将旧版明文凭据显式迁移为 AES-256-GCM。
     * 上线时必须先确认所有运行实例都已具备解密能力，再由部署步骤显式调用；
     * 不绑定在普通 API 的结构自检中，避免滚动发布期间旧实例读到密文。
     */
    function bucketMigratePlaintextCredentials(PDO $pdo): void {
        if ((string)(getenv('BUCKET_CREDENTIAL_KEY') ?: '') === '') return;
        $rows = $pdo->query('SELECT id, login_account, login_password, access_key, secret_key FROM cainiao_s3_bucket')
            ->fetchAll(PDO::FETCH_ASSOC);
        $update = $pdo->prepare('UPDATE cainiao_s3_bucket SET login_account=:login_account, login_password=:login_password, access_key=:access_key, secret_key=:secret_key WHERE id=:id');
        foreach ($rows as $row) {
            $changed = false;
            $values = [':id' => (int)$row['id']];
            foreach (['login_account', 'login_password', 'access_key', 'secret_key'] as $field) {
                $value = (string)($row[$field] ?? '');
                if ($value !== '' && strncmp($value, 'enc:v1:', 7) !== 0) $changed = true;
                $values[':' . $field] = bucketEncryptSecret($value);
            }
            if ($changed) $update->execute($values);
        }
    }
}

if (!function_exists('bucketMigrateLegacyProviderLabels')) {
    /**
     * 旧版页面默认把所有 S3 兼容桶写成 s3。仅对可由官方域名确定的
     * B2/R2 记录做无损归一；其他旧端点保留原值，不擅自更改分发链路。
     */
    function bucketMigrateLegacyProviderLabels(PDO $pdo): void {
        $rows = $pdo->query('SELECT id, provider, endpoint FROM cainiao_s3_bucket')->fetchAll(PDO::FETCH_ASSOC);
        $update = $pdo->prepare('UPDATE cainiao_s3_bucket SET provider=:provider WHERE id=:id');
        foreach ($rows as $row) {
            if (strtolower((string)($row['provider'] ?? '')) !== 's3') continue;
            $host = strtolower((string)(parse_url((string)($row['endpoint'] ?? ''), PHP_URL_HOST) ?: ''));
            $provider = '';
            if (preg_match('/^s3\.[a-z0-9-]+\.backblazeb2\.com$/', $host)) $provider = 'b2';
            if (preg_match('/(^|\.)r2\.cloudflarestorage\.com$/', $host)) $provider = 'r2';
            if ($provider !== '') $update->execute([':provider' => $provider, ':id' => (int)$row['id']]);
        }
    }
}

if (!function_exists('ensureBucketFeatureSchema')) {
    /**
     * 初始化桶管理元数据和两张日聚合统计表。
     * 该方法幂等，管理员和 worker 控制路径可在手工迁移遗漏时补齐结构；
     * 公开高频回执不执行 DDL，生产发布仍先执行 migrate_s3_bucket.sql。
     */
    function ensureBucketFeatureSchema(PDO $pdo): void {
        static $ready = [];
        $key = spl_object_hash($pdo);
        if (!empty($ready[$key])) return;
        if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            $ready[$key] = true;
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_s3_bucket` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(100) NOT NULL,
          `provider` varchar(20) NOT NULL DEFAULT 's3',
          `login_account` varchar(1024) NOT NULL DEFAULT '',
          `login_password` varchar(2048) NOT NULL DEFAULT '',
          `note` varchar(512) NOT NULL DEFAULT '',
          `access_key` varchar(1024) NOT NULL,
          `secret_key` varchar(2048) NOT NULL,
          `endpoint` varchar(512) NOT NULL,
          `bucket` varchar(255) NOT NULL,
          `region` varchar(64) NOT NULL DEFAULT 'auto',
          `domain` varchar(512) NOT NULL,
          `enabled` tinyint(1) NOT NULL DEFAULT 1,
          `inject` tinyint(1) NOT NULL DEFAULT 1,
          `last_push_at` datetime DEFAULT NULL,
          `last_push_result` text,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配置分发桶'");

        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'login_account', "varchar(1024) NOT NULL DEFAULT '' AFTER `provider`");
        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'login_password', "varchar(2048) NOT NULL DEFAULT '' AFTER `login_account`");
        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'note', "varchar(512) NOT NULL DEFAULT '' AFTER `login_password`");
        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'inject', "tinyint(1) NOT NULL DEFAULT 1 AFTER `enabled`");
        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'last_push_at', "datetime DEFAULT NULL AFTER `inject`");
        bucketEnsureMysqlColumn($pdo, 'cainiao_s3_bucket', 'last_push_result', "text AFTER `last_push_at`");

        // 旧表的凭据字段较短，按需扩容后才可存放 GCM 密文。
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'login_account', 1024, "NOT NULL DEFAULT ''");
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'login_password', 2048, "NOT NULL DEFAULT ''");
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'access_key', 1024, 'NOT NULL');
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'secret_key', 2048, 'NOT NULL');
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'endpoint', 512, 'NOT NULL');
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'bucket', 255, 'NOT NULL');
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'region', 64, "NOT NULL DEFAULT 'auto'");
        bucketEnsureMysqlVarcharLength($pdo, 'cainiao_s3_bucket', 'domain', 512, 'NOT NULL');

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_s3_bucket_stats` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `bucket_id` int NOT NULL,
          `stat_date` date NOT NULL,
          `hit_count` bigint unsigned NOT NULL DEFAULT 0,
          `ok_count` bigint unsigned NOT NULL DEFAULT 0,
          `fail_count` bigint unsigned NOT NULL DEFAULT 0,
          `last_seen_at` datetime NOT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_bucket_date` (`bucket_id`,`stat_date`),
          KEY `idx_stat_date` (`stat_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='壳端配置桶日命中统计'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cainiao_s3_bucket_file_stats` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `bucket_id` int NOT NULL,
          `app_id` int NOT NULL,
          `stat_date` date NOT NULL,
          `hit_count` bigint unsigned NOT NULL DEFAULT 0,
          `last_seen_at` datetime NOT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_bucket_app_date` (`bucket_id`,`app_id`,`stat_date`),
          KEY `idx_bucket_app_date` (`bucket_id`,`app_id`,`stat_date`),
          KEY `idx_stat_date` (`stat_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='壳端配置文件日命中统计'");

        bucketMigrateLegacyProviderLabels($pdo);
        $ready[$key] = true;
    }
}
