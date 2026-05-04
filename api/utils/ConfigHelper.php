<?php
/**
 * 配置生成公共函数
 * 从 shell.php 提取，供 shell.php 和 BucketPush.php 共用
 */

if (!function_exists('fetchCol')) {
    // 通用函数：返回单列结果
    function fetchCol($sql, $params) {
        global $pdo;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

if (!function_exists('fetchMap')) {
    // 通用函数：返回键值数组
    function fetchMap($sql, $params) {
        global $pdo;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('isDebugIP')) {
    // 判断是否是调试设备IP
    function isDebugIP(PDO $pdo): bool
    {
        $debugip = Auth::getSetting($pdo, 'debugip', '');
        if (empty($debugip)) {
            return false;
        }
        $ipList = array_map('trim', explode("\n", $debugip));
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        return in_array($clientIp, $ipList, true);
    }
}

if (!function_exists('isAppUserVip')) {
    // 判断应用所属用户是否为 VIP
    function isAppUserVip($pdo, $appid) {
        if ($appid <= 0) {
            return false;
        }
        $sql = "
            SELECT u.vip_expire_time
            FROM cainiao_apk a
            INNER JOIN cainiao_user u ON a.user_id = u.id
            WHERE a.id = :appid
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':appid' => $appid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['vip_expire_time'])) {
            return false;
        }
        $expireTime = strtotime($row['vip_expire_time']);
        if ($expireTime === false) {
            return false;
        }
        return $expireTime > time();
    }
}

if (!function_exists('getSpData')) {
    // SP 配置封装
    function getSpData($pdo, $configId, $type) {
        $tablePrefix = "cainiao_sp_{$type}";
        $stmt = $pdo->prepare("SELECT id, sp_name FROM {$tablePrefix}_name WHERE config_id = :id");
        $stmt->execute([':id' => $configId]);
        $names = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data = [];
        foreach ($names as $row) {
            $id = $row['id'];
            $stmtD = $pdo->prepare("SELECT key_name, key_value, type FROM {$tablePrefix}_detail WHERE name_id = :id");
            $stmtD->execute([':id' => $id]);
            $details = $stmtD->fetchAll(PDO::FETCH_ASSOC);
            $data[$row['sp_name']] = array_map(fn($d) => ['key' => $d['key_name'], 'value' => $d['key_value'], 'type' => $d['type']], $details);
        }
        return $data;
    }
}

if (!function_exists('getImagePopups')) {
    // 图片弹窗 / 全屏弹窗
    function getImagePopups($pdo, $configId, $type) {
        $stmt = $pdo->prepare("SELECT * FROM cainiao_popup_image WHERE config_id = :id AND enable = 1 AND popup_type = :type");
        $stmt->execute([':id' => $configId, ':type' => $type]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $popup) {
            $urls = preg_split('/\r\n|\r|\n/', trim($popup['imageUrl']));
            $urls = array_filter($urls);
            $imageUrl = !empty($urls) ? $urls[array_rand($urls)] : '';

            $clickText = $popup['clickText'];
            if ((int)$popup['clickAction'] == 1) {
                $lines = preg_split('/\r\n|\r|\n/', trim($clickText));
                $lines = array_filter($lines);
                if (!empty($lines)) {
                    $clickText = $lines[array_rand($lines)];
                } else {
                    $clickText = '';
                }
            }

            $id = $popup['id'];
            $white = fetchCol("SELECT class_name FROM cainiao_popup_image_whitelist WHERE popup_id = :id", [':id' => $id]);
            $black = fetchCol("SELECT class_name FROM cainiao_popup_fullscreen_blacklist WHERE popup_id = :id", [':id' => $id]);

            $result[] = [
                "id" => $id,
                "enable" => true,
                "imageUrl" => $imageUrl,
                "clickAction" => (int)$popup['clickAction'],
                "clickText" => $clickText,
                "callback" => $popup['callback'],
                "countdown" => (int)$popup['countdown'],
                "canSkip" => (bool)$popup['canSkip'],
                "autoClose" => (bool)$popup['autoClose'],
                "lock" => (bool)$popup['lock'],
                "white_list" => $white,
                "black_list" => $black
            ];
        }
        return $result;
    }
}

if (!function_exists('getMessagePopups')) {
    // 文字弹窗
    function getMessagePopups($pdo, $configId) {
        $stmt = $pdo->prepare("SELECT * FROM cainiao_popup_message WHERE config_id = :id AND enable = 1");
        $stmt->execute([':id' => $configId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $msg) {
            $popupId = $msg['id'];
            $btns = fetchMap("SELECT title, textcolor, backgroundColor, click, clickText, dismiss FROM cainiao_popup_message_button WHERE popup_id = :id", [':id' => $popupId]);
            $white = fetchCol("SELECT class_name FROM cainiao_popup_message_whitelist WHERE popup_id = :id", [':id' => $popupId]);
            $black = fetchCol("SELECT class_name FROM cainiao_popup_message_blacklist WHERE popup_id = :id", [':id' => $popupId]);
            $result[] = [
                "id" => $popupId,
                "enable" => true,
                "backgroundColor" => $msg['backgroundColor'],
                "title" => $msg['title'],
                "exitpopus" => $msg['exitpopus'],
                "message" => $msg['message'],
                "maskColor" => $msg['maskColor'],
                "popup_type" => $msg['popup_type'],
                "interval" => $msg['interval'],
                "lock" => (bool)$msg['lock'],
                "button" => array_map(fn($b) => [
                    "title" => $b['title'],
                    "textcolor" => $b['textcolor'],
                    "backgroundColor" => $b['backgroundColor'],
                    "click" => (int)$b['click'],
                    "clickText" => $b['clickText'],
                    "dismiss" => (bool)$b['dismiss']
                ], $btns),
                "white_list" => $white,
                "black_list" => $black
            ];
        }
        return $result;
    }
}

if (!function_exists('getInputPopups')) {
    // 输入框弹窗
    function getInputPopups($pdo, $configId) {
        $stmt = $pdo->prepare("SELECT * FROM cainiao_popup_input WHERE config_id = :id AND enable = 1");
        $stmt->execute([':id' => $configId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $input) {
            $popupId = $input['id'];
            $btns = fetchMap("SELECT title, textcolor, backgroundColor, click, clickText, dismiss FROM cainiao_popup_input_button WHERE popup_id = :id", [':id' => $popupId]);
            $white = fetchCol("SELECT class_name FROM cainiao_popup_input_whitelist WHERE popup_id = :id", [':id' => $popupId]);
            $black = fetchCol("SELECT class_name FROM cainiao_popup_input_blacklist WHERE popup_id = :id", [':id' => $popupId]);
            $result[] = [
                "id" => $popupId,
                "enable" => true,
                "backgroundColor" => $input['backgroundColor'],
                "maskColor" => $input['maskColor'],
                "title" => $input['title'],
                "message" => $input['message'],
                "hint" => $input['hint'],
                "lock" => (bool)$input['lock'],
                "autopost" => (bool)$input['autopost'],
                "button" => array_map(fn($b) => [
                    "title" => $b['title'],
                    "textcolor" => $b['textcolor'],
                    "backgroundColor" => $b['backgroundColor'],
                    "click" => (int)$b['click'],
                    "clickText" => $b['clickText'],
                    "dismiss" => (bool)$b['dismiss']
                ], $btns),
                "white_list" => $white,
                "black_list" => $black
            ];
        }
        return $result;
    }
}

if (!function_exists('getHtmlPopups')) {
    // HTML弹窗
    function getHtmlPopups($pdo, $configId) {
        $stmt = $pdo->prepare("SELECT * FROM cainiao_popup_html WHERE config_id = :id AND enable = 1 ORDER BY weight ASC, id DESC");
        $stmt->execute([':id' => $configId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $html) {
            $popupId = $html['id'];
            $white = fetchCol("SELECT class_name FROM cainiao_popup_html_whitelist WHERE popup_id = :id", [':id' => $popupId]);
            $black = fetchCol("SELECT class_name FROM cainiao_popup_html_blacklist WHERE popup_id = :id", [':id' => $popupId]);
            $result[] = [
                "id" => $popupId,
                "enable" => true,
                "html" => $html['html'],
                "lock" => (bool)$html['lock'],
                "white_list" => $white,
                "black_list" => $black
            ];
        }
        return $result;
    }
}

if (!function_exists('getResponseData')) {
    // 获取配置数据（核心方法）
    function getResponseData(PDO $pdo, $apkId, $deviceId, $disable = false) {
        $stmt = $pdo->prepare("SELECT * FROM cainiao_apk_config WHERE apk_id = :apk_id LIMIT 1");
        $stmt->execute([':apk_id' => $apkId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$config) return null;

        $configId = $config['id'];

        $allPopups = getImagePopups($pdo, $configId, '全屏弹窗');
        shuffle($allPopups);
        $popups = array_slice($allPopups, 0, 1);
        if (isDebugIP($pdo)) {
            $config['debug'] = true;
        }
        $push = (int)Auth::getSetting($pdo, "push", false);
        if ($push && !empty($config['websocket']) && !isAppUserVip($pdo, $apkId)) {
            $config['websocket'] = false;
        }

        $response = [
            "debug" => (bool)$config['debug'],
            "disable" => (bool)$disable,
            "offline" => (bool)$config['offline'],
            "websocket" => (bool)$config['websocket'],
            "enableHook" => (bool)$config['enableHook'],
            "ban_Root" => (bool)$config['ban_Root'],
            "ban_Xposed" => (bool)$config['ban_Xposed'],
            "ban_Emulator" => (bool)$config['ban_Emulator'],
            "ban_VirtualApp" => (bool)$config['ban_VirtualApp'],
            "ban_DualApp" => (bool)$config['ban_DualApp'],
            "screen_priority" => (bool)$config['screen_priority'],
            "blackActivities" => fetchCol("SELECT class_name FROM cainiao_window_class WHERE config_id = :id", [':id' => $configId]),
            "black_package" => (bool)$config['black_package'],
            "black_package_list" => fetchCol("SELECT package_name FROM cainiao_sensitive_app WHERE config_id = :id", [':id' => $configId]),
            "new_black_package_list" => fetchMap("SELECT package_name,detect_type,action_type,tip_text FROM cainiao_sensitive_app WHERE config_id = :id", [':id' => $configId]),
            "enable_popup_kill_all" => (bool)$config['enable_popup_kill_all'],
            "kill_type" => fetchCol("SELECT popup_id FROM cainiao_popup_kill_type WHERE config_id = :id", [':id' => $configId]),
            "enable_popup_keywords" => (bool)$config['enable_popup_keywords'],
            "popup_keywords" => fetchCol("SELECT keyword FROM cainiao_keyword WHERE config_id = :id AND type = 0", [':id' => $configId]),
            "popup_newkeywords" => fetchMap("SELECT keyword,new_keyword,clickAction,clickText FROM cainiao_keyword WHERE config_id = :id AND type = 1", [':id' => $configId]),
            "popup_type" => fetchCol("
                SELECT t.popup_id
                FROM cainiao_popup_type t
                JOIN cainiao_popup_block_type b
                  ON b.popup_id = t.id
                WHERE b.config_id = :id
            ", [':id' => $configId]),
            "replace" => array_column(fetchMap("SELECT class_name, uri_value FROM cainiao_uri_hijack WHERE config_id = :id", [':id' => $configId]), 'uri_value', 'class_name'),
            "enable_sp_put" => (bool)$config['enable_sp_put'],
            "sp_put" => getSpData($pdo, $configId, 'put'),
            "enable_sp_get" => (bool)$config['enable_sp_get'],
            "sp_get" => getSpData($pdo, $configId, 'get'),
            "enable_sp" => (bool)$config['enable_sp'],
            "sp" => getSpData($pdo, $configId, 'override'),
            "enablePopups" => (bool)$config['enablePopups'],
            "popups" => $popups,
            "enableImagePopups" => (bool)$config['enableImagePopups'],
            "imagepopups" => getImagePopups($pdo, $configId, '图片弹窗'),
            "enablehtmlPopups" => (bool)$config['enablehtmlPopups'],
            "htmlpopups" => getHtmlPopups($pdo, $configId),
            "enableMessagePopups" => (bool)$config['enableMessagePopups'],
            "Messagepopups" => getMessagePopups($pdo, $configId),
            "enableinputPopups" => (bool)$config['enableinputPopups'],
            "inputpopups" => getInputPopups($pdo, $configId),
            "enabledex" => (bool)$config['enabledex'],
            "dex_list" => fetchMap("SELECT url, class_name, method_name FROM cainiao_remote_dex WHERE config_id = :id AND enabled = 1", [':id' => $configId]),
            "newactivity" => fetchMap("SELECT activity, newactivity FROM cainiao_newactivity WHERE config_id = :id", [':id' => $configId]),
            "view" => fetchMap("SELECT activity,view_class,view_id,visibility,clickable,imageview,textview,clickAction,clickText FROM cainiao_view WHERE config_id = :id AND enabled = 1", [':id' => $configId]),
            "buckets" => fetchCol("SELECT domain FROM cainiao_s3_bucket WHERE enabled = 1 ORDER BY id ASC", []),
            // 弹窗统计上报地址（由壳调用，不需要鉴权）
            "stat_url" => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/api/index.php',
        ];

        return $response;
    }
}

if (!function_exists('encrypt_json')) {
    // AES-128-CBC 加密
    function encrypt_json($json, $key) {
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($json, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
        $output = $iv . $encrypted;
        return base64_encode($output);
    }
}

if (!function_exists('getSettingValue')) {
    function getSettingValue(array $settings, string $keyName, $default = null) {
        foreach ($settings as $item) {
            if ($item['key_name'] === $keyName) {
                return $item['key_value'];
            }
        }
        return $default;
    }
}
