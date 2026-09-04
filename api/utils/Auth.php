<?php
class Auth
{
    /**
     * 鉴权并返回当前用户信息数组
     * @param PDO $pdo 数据库句柄
     * @return array 当前登录用户的完整信息
     */
    public static function check(PDO $pdo)
    {
        $tokenKey = 'admin_token';
        $token = $_COOKIE[$tokenKey] ?? '';
        $appPackage = $_SERVER['HTTP_APP_PACKAGE'] ?? '';
        $appVersion = $_SERVER['HTTP_APP_VERSION'] ?? '';
        $appInfo = '';
        if (!empty($appPackage) && !empty($appVersion)) {
            $appInfo = $appPackage . '(' . $appVersion . ')';
        }
        if (!$token) {
            self::deny("01");
        }

        $userTable = 'cainiao_user';
        // 获取UA并裁剪长度
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ua = mb_substr($ua, 0, 512, 'UTF-8');
        
        // 设备类型判定（优先级：APP端 > 安卓 > 苹果 > PC）
        $uaType = 'PC';
        
        // okhttp/*.*.* 视为APP端
        if (preg_match('/(?:okhttp\/\d+(?:\.\d+){0,2}|Dalvik\/\d+(?:\.\d+){0,2})/i', $ua)) {
            $uaType = 'APP端';
        } elseif (stripos($ua, 'Android') !== false) {
            $uaType = '安卓';
        } elseif (
            stripos($ua, 'iPhone') !== false ||
            stripos($ua, 'iPad') !== false ||
            stripos($ua, 'iPod') !== false ||
            stripos($ua, 'Macintosh') !== false
        ) {
            $uaType = '苹果';
        }
        // 查找用户
        if($uaType == 'APP端'){
            $stmt = $pdo->prepare("SELECT * FROM `$userTable` WHERE apptoken = :token LIMIT 1");
        }
        else{
            $stmt = $pdo->prepare("SELECT * FROM `$userTable` WHERE token = :token LIMIT 1");
        }
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            self::deny("02");
        }
        
        //$user['unblock_time']字段类型是DATETIME 该字段可能是NULL
        if (!empty($user['unblock_time'])) {
            $unblockTs = strtotime($user['unblock_time']);
            if ($unblockTs !== false && $unblockTs > time()) {
                $left = ceil(($unblockTs - time()) / 3600);
                throw new Exception("账号被封禁，剩余 {$left} 小时");
            }
        }

        // 更新 last_active 字段
        
        $ip = self::getRealIp();
        /*$update = $pdo->prepare("UPDATE `$userTable` 
            SET last_active = NOW(), ua = :ua, login_ip = :ip 
            WHERE id = :id");
        
        $update->execute([
            ':ua' => $uaType,
            ':ip' => $ip,
            ':id' => $user['id']
        ]);*/
        
        // 仅记录处理后的设备信息（如需保留原始UA，可另存到ua_raw字段）
        /*$update = $pdo->prepare("UPDATE `$userTable` SET last_active = NOW(), ua = :ua WHERE id = :id");
        $update->execute([
            ':ua' => $uaType,
            ':id' => $user['id']
        ]);*/
        if (!empty($appInfo)) {
            $update = $pdo->prepare("
                UPDATE `$userTable`
                SET last_active = NOW(),
                    ua = :ua,
                    appinfo = :appinfo
                WHERE id = :id
            ");
            $update->execute([
                ':ua' => $uaType,
                ':appinfo' => $appInfo,
                ':id' => $user['id']
            ]);
        } else {
            // 兼容旧版本未传请求头的情况
            $update = $pdo->prepare("
                UPDATE `$userTable`
                SET last_active = NOW(),
                    ua = :ua
                WHERE id = :id
            ");
            $update->execute([
                ':ua' => $uaType,
                ':id' => $user['id']
            ]);
        }


        $user['isVip'] = false;
        if (!empty($user['vip_expire_time']) && strtotime($user['vip_expire_time']) > time()) {
            $user['isVip'] = true;
        }
        
        // 返回用户完整信息
        return $user;
    }
    /**
     * 系统发送站内信（不受任何限制）
     * @param PDO $pdo 数据库句柄
     * @param int $fromUserId 发送者ID
     * @param int $toUserId 接收者ID
     * @param string $message 消息内容
     * @throws Exception 异常信息
     * @return bool 是否发送成功
     */
    public static function sendSystemMessage(PDO $pdo, int $fromUserId, int $toUserId, string $message): bool
    {
        $message = trim($message);
    
        if ($fromUserId === $toUserId) {
            return false; // 不发送给自己
        }
    
        if ($message === '') {
            throw new Exception('消息内容不能为空');
        }
    
        if (mb_strlen($message, 'UTF-8') > 1024) {
            throw new Exception('消息内容不能超过1024字符');
        }
    
        $stmt = $pdo->prepare("INSERT INTO cainiao_message (send_user_id, receive_user_id, message, `read`, upload_time) VALUES (:send_user_id, :receive_user_id, :message, 0, NOW())");
    
        return $stmt->execute([
            ':send_user_id' => $fromUserId,
            ':receive_user_id' => $toUserId,
            ':message' => $message
        ]);
    }
    /**
     * 获取系统设置表中的配置值
     * @param PDO $pdo 数据库句柄
     * @param string $keyName 键名
     * @param mixed $default 默认值
     * @return mixed 设置值（如果不存在则返回默认值）
     */
    public static function getSetting(PDO $pdo, string $keyName, $default)
    {
        $stmt = $pdo->prepare("SELECT key_value FROM cainiao_system_setting WHERE key_name = :key LIMIT 1");
        $stmt->execute([':key' => $keyName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || trim($result['key_value']) === '') {
            return $default;
        }

        return $result['key_value'];
    }
    /**
     * 鉴权失败时处理
     */
    private static function deny($code = 0)
    {
        setcookie('admin_token', '', time() - 3600, '/', '', false, true);

        http_response_code(401);
        echo json_encode([
            'code' => 401,
            'message' => "未授权或登录已失效({$code})"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    public static function getRealIp() {
        $keys = [
            'HTTP_X_FORWARDED_FOR', // CDN/代理链，可能是最常见
            'HTTP_CLIENT_IP',
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',        // Nginx/一些CDN
            'HTTP_X_CLIENT_IP',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED'
        ];
    
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ipList = explode(',', $_SERVER[$key]);
                $ip = trim($ipList[0]); // 取第一个真实IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    
        // 最后兜底 REMOTE_ADDR
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    

    /**
     * 获取加固特征规则数组
     * @param PDO $pdo 数据库连接
     * @param int $detection 检测类型(0=上传检测, 1=注入检测)，默认0
     * @return array
     */
    public static function getRules(PDO $pdo, $detection = 0) {
        // 表前缀
        $tablePrefix = 'cainiao_';
        $rulesTable = $tablePrefix . 'rules';
        $keywordsTable = $tablePrefix . 'rule_keywords';
    
        // 查询规则表
        $stmt = $pdo->prepare("SELECT id, type, message FROM `$rulesTable` WHERE detection = :detection ORDER BY id ASC");
        $stmt->execute([':detection' => (int)$detection]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        if (!$rules) return [];
    
        // 查询所有关键字
        $ruleIds = array_column($rules, 'id');
        $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
        $stmt2 = $pdo->prepare("SELECT rule_id, keyword FROM `$keywordsTable` WHERE rule_id IN ($placeholders)");
        $stmt2->execute($ruleIds);
        $keywords = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
        // 按rule_id分组关键字
        $keywordMap = [];
        foreach ($keywords as $kw) {
            $keywordMap[$kw['rule_id']][] = $kw['keyword'];
        }
    
        // 组装返回数组
        $result = [];
        foreach ($rules as $rule) {
            $result[] = [
                'type'     => $rule['type'],
                'keywords' => $keywordMap[$rule['id']] ?? [],
                'message'  => $rule['message']
            ];
        }
    
        return $result;
    }
    
    
    /**
     * 清空指定应用的redis缓存
     * 
     */
    public static function reset_redis($appid){
        $redis = getRedisConnection(0);
        $redis->del($appid);
        $redis->select(2);
        $redis->del($appid);
        $redis->close();
    }

    /**
     * 配置变更后统一处理：清 Redis 缓存 + 进入全局配置同步任务。
     *
     * 以前这里仅启动 push_config.php（单 APPID worker），同步中心看不到该
     * 任务，且连续修改时每个入口各自推送。现在统一写入 ConfigSyncState 并由
     * push_all_configs.php 合并执行，弹窗、链接、跳转等调用方无需复制调度代码。
     * 同步原因由调用模块自动归类，右下角列表可以直接说明是哪一类配置触发了更新。
     */
    public static function afterConfigChange(PDO $pdo, int $apkId, string $reason = '') {
        self::reset_redis($apkId);

        // 老调用方只传 APPID；通过调用文件补齐稳定的模块标签，避免修改几十个
        // 配置入口的函数签名。标签只用于状态摘要，不写入配置正文或敏感字段。
        $reason = trim($reason);
        if ($reason === '') {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $callerFile = basename((string)($trace[1]['file'] ?? ''));
            $moduleLabels = [
                'config.php' => '基础开关',
                'popup_image.php' => '图片弹窗',
                'popup_message.php' => '文字弹窗',
                'popup_input.php' => '输入框弹窗',
                'popup_html.php' => 'HTML 弹窗',
                'popup_kill.php' => '通杀拦截',
                'keyword_block.php' => '关键词拦截',
                'window_block.php' => '窗口拦截',
                'sensitive.php' => '敏感应用',
                'dex.php' => 'DEX 配置',
                'view.php' => 'View 配置',
                'newactivity.php' => 'Activity 配置',
                'uri_hijack.php' => 'URI 劫持',
                'sp_get.php' => 'SP 读取劫持',
                'sp_put.php' => 'SP 写入劫持',
                'sp_override.php' => 'SP 重写',
                'redirect.php' => '跳转规则',
                'click_param_asset.php' => '点击参数资源',
                'popup_image_asset.php' => '图片资源',
                'app.php' => '应用信息或复用关系',
                'system_setting.php' => '系统运行开关',
                'ApiDomainAutomation.php' => 'API 域名池',
                'ConfigDelivery.php' => '全局分发配置',
            ];
            $label = $moduleLabels[$callerFile] ?? '运行时配置';
            $reason = '应用 #' . (int)$apkId . ' · ' . $label;
        }

        // API 总入口通常已经加载该工具；独立脚本/测试环境则按需加载。
        $statePath = __DIR__ . '/ConfigSyncState.php';
        if (!function_exists('configSyncStateScheduleWorker') && file_exists($statePath)) {
            require_once $statePath;
        }
        if (function_exists('configSyncStateScheduleWorker')) {
            try {
                $scheduled = configSyncStateScheduleWorker($pdo, $reason);
                if (!empty($scheduled['scheduled'])) {
                    return $scheduled['snapshot'] ?? [];
                }
                // worker 启动失败时继续走旧单应用兜底，避免升级窗口丢失本次对象推送。
                error_log('[Auth::afterConfigChange] 全局同步未启动 appId=' . (int)$apkId);
            } catch (Throwable $e) {
                // 状态记录异常不阻断配置保存；保留旧单应用 worker 作为兜底。
                error_log('[Auth::afterConfigChange] 全局同步调度失败 appId=' . (int)$apkId . ': ' . $e->getMessage());
            }
        }

        // 兼容未部署 ConfigSyncState 的旧节点，避免升级过程中丢失对象推送。
        $script = realpath(__DIR__ . '/../../service/push_config.php');
        if ($script && function_exists('exec')) {
            @exec('php ' . escapeshellarg($script) . ' ' . (int)$apkId . ' > /dev/null 2>&1 &');
        }
        return [];
    }

    /**
     * 通过 config_id 反查 apk_id
     */
    public static function getApkIdByConfigId(PDO $pdo, int $configId): int {
        $stmt = $pdo->prepare("SELECT apk_id FROM cainiao_apk_config WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $configId]);
        return (int)$stmt->fetchColumn();
    }
    
    
    public function getIpLocation($ip) {
        static $searcher = null;
    
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return [
                'ip' => $ip,
                'country' => '',
                'region' => '',
                'city' => '',
                'isp' => '',
                'location' => '无效IP'
            ];
        }
        
        if (strpos($ip, ':') !== false) {
            return [
                'ip' => $ip,
                'location' => 'IPv6暂不支持'
            ];
        }
    
        if ($searcher === null) {
            $dbFile = __DIR__ . '/../../bin/ip2region.xdb';
            $searcher = XdbSearcher::newWithFileOnly($dbFile);
        }
    
        $region = $searcher->search($ip);
        $parts = explode('|', $region);
    
        return [
            'ip' => $ip,
            'country' => $parts[0] ?? '',
            'region' => $parts[2] ?? '',
            'city' => $parts[3] ?? '',
            'isp' => $parts[4] ?? '',
            'location' => trim(($parts[0] ?? '') . ' ' . ($parts[2] ?? '') . ' ' . ($parts[3] ?? ''))
        ];
    }

}
