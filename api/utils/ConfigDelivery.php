<?php

require_once __DIR__ . '/ConfigSyncState.php';

/**
 * 配置分发的共享合同。
 *
 * 这个文件集中管理 API 域名池、DoH 池、UDP DNS 池、
 * 壳端公开下发字段和网络路径统计表。管理 API 可以显式调用
 * ensureConfigDeliverySchema()；高频 shell.php 仅读取现有表，表尚未迁移时
 * 自动回退到编译期节点，避免每次壳请求执行 DDL。
 */

if (!function_exists('configDeliveryDohPresets')) {
    /** 返回后端唯一的常用 DoH 预设清单。 */
    function configDeliveryDohPresets(): array
    {
        return [
            ['name' => 'Cloudflare', 'url' => 'https://cloudflare-dns.com/dns-query'],
            ['name' => 'Cloudflare 1.1.1.1', 'url' => 'https://1.1.1.1/dns-query'],
            ['name' => 'Cloudflare 1.0.0.1', 'url' => 'https://1.0.0.1/dns-query'],
            ['name' => 'Google', 'url' => 'https://dns.google/resolve'],
            ['name' => 'Google 8.8.8.8', 'url' => 'https://8.8.8.8/resolve'],
            ['name' => 'Google 8.8.4.4', 'url' => 'https://8.8.4.4/resolve'],
            ['name' => '阿里 DNS', 'url' => 'https://dns.alidns.com/resolve'],
            ['name' => '阿里 223.5.5.5', 'url' => 'https://223.5.5.5/resolve'],
            ['name' => '阿里 223.6.6.6', 'url' => 'https://223.6.6.6/resolve'],
            ['name' => 'DNSPod', 'url' => 'https://doh.pub/dns-query'],
            ['name' => 'DNSPod 1.12.12.12', 'url' => 'https://1.12.12.12/dns-query'],
            ['name' => 'DNSPod 120.53.53.53', 'url' => 'https://120.53.53.53/dns-query'],
            ['name' => 'Quad9', 'url' => 'https://dns.quad9.net/dns-query'],
            ['name' => 'Quad9 9.9.9.9', 'url' => 'https://9.9.9.9/dns-query'],
            ['name' => 'Quad9 149.112.112.112', 'url' => 'https://149.112.112.112/dns-query'],
            ['name' => 'OpenDNS', 'url' => 'https://doh.opendns.com/dns-query'],
            ['name' => 'Cisco Umbrella', 'url' => 'https://doh.umbrella.com/dns-query'],
        ];
    }
}

if (!function_exists('configDeliveryDnsPresets')) {
    /** 返回后端唯一的常用 UDP DNS 预设清单。 */
    function configDeliveryDnsPresets(): array
    {
        return [
            ['name' => '114DNS', 'ip' => '114.114.114.114'],
            ['name' => '114DNS 备用', 'ip' => '114.114.115.115'],
            ['name' => '百度 DNS', 'ip' => '180.76.76.76'],
            ['name' => '阿里 223.5.5.5', 'ip' => '223.5.5.5'],
            ['name' => '阿里 223.6.6.6', 'ip' => '223.6.6.6'],
            ['name' => 'DNSPod 119.29.29.29', 'ip' => '119.29.29.29'],
            ['name' => 'DNSPod 1.12.12.12', 'ip' => '1.12.12.12'],
            ['name' => 'DNSPod 120.53.53.53', 'ip' => '120.53.53.53'],
            ['name' => 'Google 8.8.8.8', 'ip' => '8.8.8.8'],
            ['name' => 'Google 8.8.4.4', 'ip' => '8.8.4.4'],
            ['name' => 'Cloudflare 1.1.1.1', 'ip' => '1.1.1.1'],
            ['name' => 'Cloudflare 1.0.0.1', 'ip' => '1.0.0.1'],
            ['name' => 'Quad9 9.9.9.9', 'ip' => '9.9.9.9'],
            ['name' => 'Quad9 149.112.112.112', 'ip' => '149.112.112.112'],
            ['name' => 'OpenDNS 208.67.222.222', 'ip' => '208.67.222.222'],
            ['name' => 'OpenDNS 208.67.220.220', 'ip' => '208.67.220.220'],
        ];
    }
}

if (!function_exists('configDeliveryApiDefaults')) {
    /**
     * 返回现有壳 POST_URL_LIST 的服务端镜像。
     *
     * 保留两个历史 HTTP IP 是为了上线首日行为不变；后续可在管理页
     * 逐条停用，新壳收到远程池后以数据库启用行为准。
     */
    function configDeliveryApiDefaults(): array
    {
        return [
            ['name' => '新平台 IP', 'base_url' => 'http://143.92.40.164:8090/shell', 'usage_scope' => 'config', 'priority' => 400],
            ['name' => '旧平台 IP 备用', 'base_url' => 'http://143.92.40.191/shell.php', 'usage_scope' => 'config', 'priority' => 300],
            ['name' => '正式域名 .top', 'base_url' => 'https://*.zkzam9hoby.top/shell.php', 'usage_scope' => 'config', 'priority' => 200],
            ['name' => '正式域名 .com', 'base_url' => 'https://*.zkzam9hoby.com/shell.php', 'usage_scope' => 'config', 'priority' => 100],
        ];
    }
}

if (!function_exists('configDeliveryToday')) {
    /** 所有统计日分组固定使用东八区，避免容器 UTC 跨天。 */
    function configDeliveryToday(): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        return $now->format('Y-m-d');
    }
}

if (!function_exists('configDeliveryNormalizeName')) {
    /** 校验节点的管理名称。 */
    function configDeliveryNormalizeName($value): string
    {
        $name = trim((string)$value);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            throw new InvalidArgumentException('名称必填且不能超过 100 个字符');
        }
        return $name;
    }
}

if (!function_exists('configDeliveryNormalizePriority')) {
    /** 优先级数值越大越靠前，只接受有界整数。 */
    function configDeliveryNormalizePriority($value): int
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('优先级必须是整数');
        }
        $priority = (int)$value;
        if ($priority < -10000 || $priority > 10000) {
            throw new InvalidArgumentException('优先级必须介于 -10000 与 10000 之间');
        }
        return $priority;
    }
}

if (!function_exists('configDeliveryNormalizeEnabled')) {
    /** 将页面的布尔值、0/1 字符统一为数据库 tinyint。 */
    function configDeliveryNormalizeEnabled($value, int $default = 1): int
    {
        if ($value === null || $value === '') {
            return $default ? 1 : 0;
        }
        if ($value === false || $value === 0 || $value === '0' || strtolower((string)$value) === 'false') {
            return 0;
        }
        return 1;
    }
}

if (!function_exists('configDeliveryNormalizeScope')) {
    /** 校验 API 域名用途。 */
    function configDeliveryNormalizeScope($value): string
    {
        $scope = strtolower(trim((string)$value));
        if (!in_array($scope, ['all', 'config', 'report', 'click'], true)) {
            throw new InvalidArgumentException('API 用途只允许 all/config/report/click');
        }
        return $scope;
    }
}

if (!function_exists('configDeliveryNormalizeApiUrl')) {
    /**
     * 校验壳端 API 入口。
     *
     * 历史壳使用 `*` 作为随机子域占位符，校验时临时替换为 bootstrap，
     * 保存时则保留原始占位符供壳端随机化。
     */
    function configDeliveryNormalizeApiUrl($value): string
    {
        $url = trim((string)$value);
        if ($url === '' || strlen($url) > 512) {
            throw new InvalidArgumentException('API 地址必填且长度不能超过 512');
        }
        $wildcardCount = substr_count($url, '*');
        if ($wildcardCount > 0 && ($wildcardCount !== 1 || strpos($url, '://*.') === false)) {
            throw new InvalidArgumentException('API 随机占位符只允许出现一次并作为完整子域 `*.`');
        }
        $parseTarget = str_replace('://*.', '://bootstrap.', $url);
        $parts = parse_url($parseTarget);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new InvalidArgumentException('API 地址必须使用 http 或 https');
        }
        if (empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('API 地址不得内嵌凭据或 Fragment');
        }
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
            if (array_key_exists('api_pool_id', $query) || array_key_exists('api_pool_scope', $query)) {
                throw new InvalidArgumentException('api_pool_id/api_pool_scope 由服务端自动生成');
            }
        }
        return rtrim($url, '/');
    }
}

if (!function_exists('configDeliveryNormalizeDohUrl')) {
    /** DoH 必须使用 HTTPS，阻止把解析查询明文发送到第三方。 */
    function configDeliveryNormalizeDohUrl($value): string
    {
        $url = trim((string)$value);
        if ($url === '' || strlen($url) > 512) {
            throw new InvalidArgumentException('DoH URL 必填且长度不能超过 512');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('DoH URL 必须是完整 HTTPS 地址');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('DoH URL 不得内嵌凭据或 Fragment');
        }
        return rtrim($url, '/');
    }
}

if (!function_exists('configDeliveryNormalizeDnsIp')) {
    /** 当前壳的 UDP 查询实现只接受公网 IPv4 DNS 节点。 */
    function configDeliveryNormalizeDnsIp($value): string
    {
        $ip = trim((string)$value);
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            throw new InvalidArgumentException('DNS 节点必须是公网 IPv4 地址');
        }
        return $ip;
    }
}

if (!function_exists('ensureConfigDeliverySchema')) {
    /**
     * 创建配置分发表并仅在首次导入默认节点。
     *
     * 独立 meta 表记录“已初始化”，因此管理员主动删空或停用节点后，
     * 后续请求不会把默认行偷偷加回。
     */
    function ensureConfigDeliverySchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_config_delivery_meta (
            key_name varchar(64) NOT NULL,
            key_value varchar(255) NOT NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (key_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配置分发迁移状态'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_api_domain_pool (
            id int unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            base_url varchar(512) NOT NULL,
            usage_scope varchar(16) NOT NULL DEFAULT 'config',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            priority int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_api_url_scope (base_url, usage_scope),
            KEY idx_api_enabled_priority (enabled, priority, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API 域名池'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_doh_pool (
            id int unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            url varchar(512) NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            priority int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_doh_url (url),
            KEY idx_doh_enabled_priority (enabled, priority, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='DoH 解析池'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_dns_pool (
            id int unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            ip varchar(45) NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            priority int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_dns_ip (ip),
            KEY idx_dns_enabled_priority (enabled, priority, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='UDP DNS 解析池'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_api_domain_stats (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            domain_pool_id int unsigned NOT NULL,
            scope varchar(16) NOT NULL DEFAULT 'config',
            stat_date date NOT NULL,
            request_count bigint unsigned NOT NULL DEFAULT 0,
            ok_count bigint unsigned NOT NULL DEFAULT 0,
            fail_count bigint unsigned NOT NULL DEFAULT 0,
            last_seen_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_api_domain_day (domain_pool_id, scope, stat_date),
            KEY idx_api_domain_stat_date (stat_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API 域名池日请求统计'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_dns_path_stats (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            stat_date date NOT NULL,
            dimension_hash char(64) NOT NULL,
            domain_pool_id int unsigned NOT NULL DEFAULT 0,
            scope varchar(16) NOT NULL DEFAULT 'config',
            dns_mode varchar(16) NOT NULL DEFAULT 'unknown',
            dns_provider varchar(191) NOT NULL DEFAULT '',
            target_host varchar(255) NOT NULL DEFAULT '',
            app_id int unsigned NOT NULL DEFAULT 0,
            package_name varchar(255) NOT NULL DEFAULT '',
            carrier varchar(100) NOT NULL DEFAULT '',
            network_type varchar(32) NOT NULL DEFAULT '',
            request_count bigint unsigned NOT NULL DEFAULT 0,
            ok_count bigint unsigned NOT NULL DEFAULT 0,
            fail_count bigint unsigned NOT NULL DEFAULT 0,
            rejected_count bigint unsigned NOT NULL DEFAULT 0,
            rescued_count bigint unsigned NOT NULL DEFAULT 0,
            last_seen_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_dns_path_day (stat_date, dimension_hash),
            KEY idx_dns_path_date_mode (stat_date, dns_mode),
            KEY idx_dns_path_app_date (app_id, stat_date),
            KEY idx_dns_path_pool_date (domain_pool_id, stat_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='壳端真实 DNS 路径日统计'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cainiao_network_path_receipt (
            receipt_hash char(64) NOT NULL,
            app_id int unsigned NOT NULL,
            received_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (receipt_hash),
            KEY idx_network_receipt_time (received_at),
            KEY idx_network_receipt_app_time (app_id, received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='网络路径回执幂等去重'");

        $pdo->exec("INSERT IGNORE INTO cainiao_config_delivery_meta (key_name, key_value)
            VALUES ('network_config_version', '1')");
        $pdo->exec("INSERT INTO cainiao_config_delivery_meta (key_name, key_value)
            VALUES ('schema_version', '2')
            ON DUPLICATE KEY UPDATE key_value=GREATEST(CAST(key_value AS UNSIGNED), 2)");

        $seedCheck = $pdo->prepare("SELECT key_value FROM cainiao_config_delivery_meta WHERE key_name='pool_seed_v1' LIMIT 1");
        $seedCheck->execute();
        if ((string)$seedCheck->fetchColumn() === '1') {
            return;
        }

        $apiInsert = $pdo->prepare("INSERT IGNORE INTO cainiao_api_domain_pool
            (name, base_url, usage_scope, enabled, priority) VALUES (:name, :base_url, :scope, 1, :priority)");
        foreach (configDeliveryApiDefaults() as $row) {
            $apiInsert->execute([
                ':name' => $row['name'], ':base_url' => $row['base_url'],
                ':scope' => $row['usage_scope'], ':priority' => $row['priority'],
            ]);
        }

        $dohInsert = $pdo->prepare("INSERT IGNORE INTO cainiao_doh_pool
            (name, url, enabled, priority) VALUES (:name, :url, 1, :priority)");
        $priority = count(configDeliveryDohPresets()) * 10;
        foreach (configDeliveryDohPresets() as $row) {
            $dohInsert->execute([':name' => $row['name'], ':url' => $row['url'], ':priority' => $priority]);
            $priority -= 10;
        }

        $dnsInsert = $pdo->prepare("INSERT IGNORE INTO cainiao_dns_pool
            (name, ip, enabled, priority) VALUES (:name, :ip, 1, :priority)");
        $priority = count(configDeliveryDnsPresets()) * 10;
        foreach (configDeliveryDnsPresets() as $row) {
            $dnsInsert->execute([':name' => $row['name'], ':ip' => $row['ip'], ':priority' => $priority]);
            $priority -= 10;
        }

        $pdo->exec("INSERT INTO cainiao_config_delivery_meta (key_name, key_value)
            VALUES ('pool_seed_v1', '1') ON DUPLICATE KEY UPDATE key_value='1'");
    }
}

if (!function_exists('configDeliveryAppendQuery')) {
    /** 在不破坏已有 Query 的情况下加入池 ID 和用途标识。 */
    function configDeliveryAppendQuery(string $url, array $params): string
    {
        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('configDeliveryEnabledApiDomainRows')) {
    /**
     * 读取当前会进入运行时配置的 API 域名池记录。
     *
     * 这是配置负载与管理页展示共用的单一数据源：只返回已启用的
     * 前 30 个节点，排序与壳端最终收到的列表一致。
     */
    function configDeliveryEnabledApiDomainRows(PDO $pdo): array
    {
        return $pdo->query("SELECT id,name,base_url,usage_scope,priority,updated_at
            FROM cainiao_api_domain_pool
            WHERE enabled=1
            ORDER BY priority DESC,id ASC
            LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('configDeliveryApiDomainRowsForScope')) {
    /**
     * 将数据库域名行映射为某一用途的公开下发行。
     *
     * `base_url` 保留管理员填写的入口，`delivery_url` 则是 APK 实际收到的
     * URL，并由服务端追加池 ID 和用途统计参数。
     */
    function configDeliveryApiDomainRowsForScope(array $rows, string $targetScope): array
    {
        $targetScope = strtolower(trim($targetScope));
        if (!in_array($targetScope, ['config', 'report', 'click'], true)) {
            throw new InvalidArgumentException('API 下发用途只允许 config/report/click');
        }

        $result = [];
        foreach ($rows as $row) {
            $scope = configDeliveryNormalizeScope($row['usage_scope'] ?? 'config');
            if ($scope !== 'all' && $scope !== $targetScope) {
                continue;
            }
            $id = max(0, (int)($row['id'] ?? 0));
            if ($id <= 0) {
                continue;
            }
            // 域名在写入管理接口时已完成校验；这里保留数据库原值，
            // 使共享 helper 接入前后的壳端实际下发 URL 字节保持一致。
            $baseUrl = (string)($row['base_url'] ?? '');
            $result[] = [
                'id' => $id,
                'name' => trim((string)($row['name'] ?? '')),
                'base_url' => $baseUrl,
                'usage_scope' => $scope,
                'delivery_scope' => $targetScope,
                'delivery_url' => configDeliveryAppendQuery($baseUrl, [
                    'api_pool_id' => $id,
                    'api_pool_scope' => $targetScope,
                ]),
                'priority' => (int)($row['priority'] ?? 0),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        return $result;
    }
}

if (!function_exists('configDeliveryAlignEffectiveApiRows')) {
    /**
     * 以同次 publicPools 产生的 URL 为权威集合，将管理行只作为展示元数据附加。
     *
     * 详情请求期间管理员可能刚好修改域名池；当两次读取不同步，
     * 或 publicPools 因部分表尚未迁移而启用 bootstrap 时，页面仍严格展示
     * effectiveUrls，不把第二次查询的非生效行标记为“当前有效”。
     */
    function configDeliveryAlignEffectiveApiRows(array $effectiveUrls, array $configuredItems): array
    {
        $byDeliveryUrl = [];
        foreach ($configuredItems as $item) {
            $url = trim((string)($item['delivery_url'] ?? ''));
            if ($url !== '' && !isset($byDeliveryUrl[$url])) {
                $byDeliveryUrl[$url] = $item;
            }
        }

        $items = [];
        foreach ($effectiveUrls as $rawUrl) {
            $url = (string)$rawUrl;
            if (isset($byDeliveryUrl[$url])) {
                $items[] = $byDeliveryUrl[$url];
                continue;
            }
            $items[] = [
                'id' => 0,
                'name' => '运行时有效入口',
                'base_url' => $url,
                'usage_scope' => 'config',
                'delivery_scope' => 'config',
                'delivery_url' => $url,
                'priority' => 0,
                'updated_at' => '',
            ];
        }

        $normalizedEffectiveUrls = array_values(array_map('strval', $effectiveUrls));
        $defaultBootstrapUrls = configDeliveryDefaultPublicPools()['api_urls'];
        // 只有实际集合精确等于编译期紧急入口时才标记 fallback。
        // 管理员并发改池造成的元数据未命中，仍保持 publicPools 已确定的真实来源。
        $effectiveSource = !empty($normalizedEffectiveUrls) && $normalizedEffectiveUrls !== $defaultBootstrapUrls
            ? 'domain_pool'
            : 'bootstrap_fallback';

        return [
            'effective_source' => $effectiveSource,
            'items' => $items,
        ];
    }
}

if (!function_exists('configDeliveryDefaultPublicPools')) {
    /** 数据库迁移前的运行时兜底，保持 v152 时代的节点集合。 */
    function configDeliveryDefaultPublicPools(): array
    {
        return [
            'config_delivery_version' => 1,
            'network_config_version' => 1,
            'config_urls' => array_values(array_map(static function (array $row): string {
                return $row['base_url'];
            }, configDeliveryApiDefaults())),
            'api_urls' => array_values(array_map(static function (array $row): string {
                return $row['base_url'];
            }, configDeliveryApiDefaults())),
            'report_urls' => [],
            'click_urls' => [],
            'doh_urls' => array_values(array_column(configDeliveryDohPresets(), 'url')),
            'dns_ips' => array_values(array_column(configDeliveryDnsPresets(), 'ip')),
            'doh_pool_configured' => false,
            'dns_pool_configured' => false,
        ];
    }
}

if (!function_exists('configDeliveryIsMissingTableException')) {
    /** 只把明确的“表不存在”识别为尚未迁移。 */
    function configDeliveryIsMissingTableException(Throwable $e): bool
    {
        $sqlState = strtoupper((string)$e->getCode());
        $message = strtolower($e->getMessage());
        return $sqlState === '42S02'
            || strpos($message, "doesn't exist") !== false
            || strpos($message, 'does not exist') !== false
            || strpos($message, 'no such table') !== false;
    }
}

if (!function_exists('configDeliveryPublicPools')) {
    /**
     * 读取供壳端使用的启用节点。
     *
     * 表存在时数据库是权威源，即使管理员把 DoH/DNS 全部停用也会
     * 明确下发空数组。只有老库尚无表时才使用编译期默认池。
     */
    function configDeliveryPublicPools(PDO $pdo): array
    {
        try {
            $domains = configDeliveryEnabledApiDomainRows($pdo);
            $dohRows = $pdo->query("SELECT url FROM cainiao_doh_pool WHERE enabled=1 ORDER BY priority DESC, id ASC LIMIT 24")
                ->fetchAll(PDO::FETCH_COLUMN);
            $dnsRows = $pdo->query("SELECT ip FROM cainiao_dns_pool WHERE enabled=1 ORDER BY priority DESC, id ASC LIMIT 24")
                ->fetchAll(PDO::FETCH_COLUMN);
            $versionStmt = $pdo->query("SELECT key_value FROM cainiao_config_delivery_meta
                WHERE key_name='network_config_version' LIMIT 1");
            $networkVersion = max(1, (int)$versionStmt->fetchColumn());

            $result = [
                'config_delivery_version' => 1,
                'network_config_version' => $networkVersion,
                'config_urls' => [],
                'api_urls' => [],
                'report_urls' => [],
                'click_urls' => [],
                'doh_urls' => array_values(array_unique(array_map('strval', $dohRows))),
                'dns_ips' => array_values(array_unique(array_map('strval', $dnsRows))),
                'doh_pool_configured' => true,
                'dns_pool_configured' => true,
            ];
            foreach (['config', 'report', 'click'] as $targetScope) {
                foreach (configDeliveryApiDomainRowsForScope($domains, $targetScope) as $row) {
                    $result[$targetScope . '_urls'][] = (string)$row['delivery_url'];
                }
            }
            foreach (['config_urls', 'report_urls', 'click_urls'] as $field) {
                $result[$field] = array_values(array_unique($result[$field]));
            }
            // API 入口全部停用时仍保留壳的紧急 bootstrap，避免远程配置把自己永久锁死。
            if (empty($result['config_urls'])) {
                $result['config_urls'] = configDeliveryDefaultPublicPools()['config_urls'];
            }
            // api_urls 是云注入 v153 的直观别名，config_urls 则与崩溃系统配置合同对齐。
            $result['api_urls'] = $result['config_urls'];
            return $result;
        } catch (Throwable $e) {
            if (configDeliveryIsMissingTableException($e)) {
                return configDeliveryDefaultPublicPools();
            }
            // 连接、字段或查询异常不得伪装成“未迁移”，避免悄悄重启旧节点。
            error_log('[ConfigDelivery] 读取动态节点池失败: ' . $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('configDeliveryMarkDirty')) {
    /** 标记全局分发配置已变更，供全量桶同步工作者合并连续保存。 */
    function configDeliveryMarkDirty(PDO $pdo): void
    {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO cainiao_config_delivery_meta (key_name,key_value)
                VALUES ('distribution_dirty','1')
                ON DUPLICATE KEY UPDATE key_value='1'");
            $stmt->execute();
            $version = $pdo->prepare("INSERT INTO cainiao_config_delivery_meta (key_name,key_value)
                VALUES ('network_config_version','2')
                ON DUPLICATE KEY UPDATE key_value=CAST(key_value AS UNSIGNED)+1");
            $version->execute();
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!configDeliveryIsMissingTableException($e)) {
                throw $e;
            }
            // 滚动升级的首次保存可能早于显式 SQL 迁移，管理请求中允许补表。
            ensureConfigDeliverySchema($pdo);
            configDeliveryMarkDirty($pdo);
        }
    }
}

if (!function_exists('configDeliveryIsDiskCacheName')) {
    /** 只识别 shell.php 生成的配置缓存，不触碰 temp 中其它 JSON 任务文件。 */
    function configDeliveryIsDiskCacheName(string $name): bool
    {
        return preg_match('/^\d+\.json$/u', $name) === 1
            || preg_match('/^fallback:\d+:\d+\.json$/u', $name) === 1
            || preg_match('/^(?:AAA明文)?禁用的设备：(?:\d+|fallback:\d+:\d+)_.*\.json$/u', $name) === 1;
    }
}

if (!function_exists('configDeliveryInvalidateAndSync')) {
    /**
     * 全局节点池变化后清理 shell.php 密文缓存，并异步刷新所有桶文件。
     * Redis DB0 当前主要存放远程配置，但这里仍只删除纯数字 APPID、
     * fallback 和禁用设备三类配置键，为后续的维护锁等非配置键留出空间。
     * APK 元数据所在 DB2 保持不变。
     */
    function configDeliveryInvalidateAndSync(?PDO $pdo = null): array
    {
        $result = ['redis_cleared' => false, 'disk_deleted' => 0, 'sync_started' => false];
        $syncState = null;
        $alreadyActive = false;
        if ($pdo) {
            // 先读取当前代次；连续保存节点时复用同一 worker，避免重复启动后台进程。
            try {
                $before = configSyncStateRead($pdo);
                $alreadyActive = in_array((string)($before['status'] ?? ''), ['queued', 'running'], true)
                    && (string)($before['job_id'] ?? '') !== '';
            } catch (Throwable $ignored) {
                $before = [];
            }
            configDeliveryMarkDirty($pdo);
            // 先落库排队状态，使右下角同步中心立即感知配置池变化。
            try {
                $syncState = configSyncStateMarkQueued($pdo, '全局配置变更');
                // 重新核对 job_id，处理“读取旧状态后 worker 恰好完成”的竞态窗口。
                if ($alreadyActive) {
                    $alreadyActive = in_array((string)($syncState['status'] ?? ''), ['queued', 'running'], true)
                        && (string)($syncState['job_id'] ?? '') !== ''
                        && (string)($syncState['job_id'] ?? '') === (string)($before['job_id'] ?? '');
                }
            } catch (Throwable $ignored) {
                // 状态表迁移异常不应回滚已保存的节点池配置。
                $syncState = null;
            }
        }
        $redis = null;
        try {
            if (function_exists('getRedisConnection')) {
                $redis = getRedisConnection(0);
                $redis->select(0);
                $keys = [];
                foreach (['[0-9]*', 'fallback:*', '禁用的设备：*'] as $pattern) {
                    $iterator = null;
                    $guard = 0;
                    do {
                        $batch = $redis->scan($iterator, $pattern, 200);
                        if (is_array($batch)) {
                            foreach ($batch as $key) {
                                $key = (string)$key;
                                if (preg_match('/^\d+$/', $key)
                                    || strpos($key, 'fallback:') === 0
                                    || strpos($key, '禁用的设备：') === 0) {
                                    $keys[$key] = true;
                                }
                            }
                        }
                        $guard++;
                    } while ($iterator !== 0 && $iterator !== '0' && $guard < 10000);
                }
                foreach (array_keys($keys) as $key) {
                    $redis->del($key);
                }
                $result['redis_cleared'] = true;
            }
        } catch (Throwable $ignored) {
            // Redis 短暂不可用时仍继续删磁盘缓存和刷新桶。
        } finally {
            if ($redis && method_exists($redis, 'close')) {
                try { $redis->close(); } catch (Throwable $ignored) {}
            }
        }

        $tempDir = dirname(__DIR__, 2) . '/temp';
        if (is_dir($tempDir)) {
            foreach (glob($tempDir . '/*.json') ?: [] as $file) {
                $name = basename($file);
                if (configDeliveryIsDiskCacheName($name)
                    && is_file($file)
                    && (@unlink($file) || !is_file($file))) {
                    $result['disk_deleted']++;
                }
            }
        }

        $script = realpath(dirname(__DIR__, 2) . '/service/push_all_configs.php');
        // 当前任务已在排队或执行时，直接加入同一代次；由 worker 读取 dirty 标记吸收本次变更。
        if ($alreadyActive) {
            $result['sync_started'] = true;
            $result['sync_joined'] = true;
        } elseif ($script && function_exists('exec')) {
            $jobId = (string)($syncState['job_id'] ?? '');
            $command = 'php ' . escapeshellarg($script)
                . ($jobId !== '' ? ' ' . escapeshellarg($jobId) : '')
                . ' > /dev/null 2>&1 & echo $!';
            $output = [];
            $exitCode = 1;
            @exec($command, $output, $exitCode);
            // 只有拿到后台 PID 才报告已启动，避免右下角中心显示“同步中”但实际没有 worker。
            $result['sync_started'] = $exitCode === 0
                && !empty($output)
                && ctype_digit(trim((string)end($output)));
        }
        if ($pdo) {
            if (!$result['sync_started'] && $syncState) {
                try {
                    configSyncStateMarkFinished(
                        $pdo,
                        ['total' => 0, 'success' => 0, 'fail' => 0, 'message' => '后台同步脚本未启动'],
                        (string)($syncState['job_id'] ?? ''),
                        new RuntimeException('后台同步脚本未启动')
                    );
                } catch (Throwable $ignored) {}
            }
            try {
                $result['sync_job'] = configSyncStateRead($pdo);
            } catch (Throwable $ignored) {
                $result['sync_job'] = null;
            }
        }
        return $result;
    }
}

if (!function_exists('configDeliveryValidateAppReceipt')) {
    /** 校验路径回执必须来自已成功注入的存量应用。 */
    function configDeliveryValidateAppReceipt(PDO $pdo, array $input, int $minimumShellVersion = 153): array
    {
        $appId = (int)($input['app_id'] ?? 0);
        $appKey = (int)($input['app_key'] ?? 0);
        $key = (string)($input['key'] ?? '');
        $packageName = trim((string)($input['package_name'] ?? ''));
        $shellVersion = (int)($input['shell_version'] ?? 0);
        if ($appId <= 0 || $appKey <= 0 || $key === '' || $packageName === '' || $shellVersion < $minimumShellVersion) {
            throw new InvalidArgumentException('网络路径回执参数不完整');
        }

        $stmt = $pdo->prepare("SELECT a.id, a.user_id, a.package
            FROM cainiao_apk a
            INNER JOIN cainiao_apk_config c ON c.apk_id=a.id
            INNER JOIN cainiao_inject_task t ON t.apk_id=a.id AND t.status_text='编译成功'
            LEFT JOIN cainiao_apk_deleted d ON d.apk_id=a.id
            WHERE a.id=:app_id AND a.user_id=:user_id AND d.apk_id IS NULL
            LIMIT 1");
        $stmt->execute([':app_id' => $appId, ':user_id' => $appKey]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app || !hash_equals((string)$app['package'], $packageName)) {
            throw new RuntimeException('应用未登记、已失效或包名不匹配');
        }
        $plain = (string)$appId . (string)$appKey . md5((string)$appId . (string)$appKey);
        if (!password_verify($plain, $key)) {
            throw new RuntimeException('应用回执密钥不匹配');
        }
        return $app;
    }
}

if (!function_exists('configDeliveryNormalizeStatText')) {
    /** 限制统计维度长度并清理控制字符，避免高基数脏数据。 */
    function configDeliveryNormalizeStatText($value, int $maxLength): string
    {
        $text = trim((string)$value);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
        return mb_substr((string)$text, 0, $maxLength, 'UTF-8');
    }
}
