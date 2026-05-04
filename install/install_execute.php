<?php
// 表前缀配置
$tablePrefix = 'cainiao_';

/**
 * 执行数据库安装
 * @param PDO $pdo
 * @return array
 */
function installDatabase(PDO $pdo)
{
    global $tablePrefix;

    try {
        $pdo->beginTransaction();
        
        //创建应用底包库表
        $appStoreTable = $tablePrefix . 'appstore';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$appStoreTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $appStoreFields = [
            'name'           => "VARCHAR(100) NOT NULL DEFAULT '' COMMENT '应用名称'",
            'subtitle'       => "VARCHAR(100) DEFAULT NULL DEFAULT '' COMMENT '应用说明，用于副标题显示'",
            'version'        => "VARCHAR(100) DEFAULT NULL COMMENT '底包版本'",
            'download1_text' => "VARCHAR(1024) NOT NULL COMMENT '下载地址1名称'",
            'download1_url'  => "VARCHAR(1024) NOT NULL COMMENT '下载地址1url'",
            'download2_text' => "VARCHAR(1024) DEFAULT NULL COMMENT '下载地址2名称'",
            'download2_url'  => "VARCHAR(1024) DEFAULT NULL COMMENT '下载地址2url'",
            'logoUrl'        => "TEXT DEFAULT NULL COMMENT '应用图标地址'",
            'size'           => "VARCHAR(100) NOT NULL COMMENT '应用大小'",
            'vip'            => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否是vip资源'",
            'enabled'        => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用'",
            'update_time'    => "DATETIME NOT NULL COMMENT '创建时间'",
        ];
        addFieldsIfNotExist($pdo, $appStoreTable, $appStoreFields);
        // --------------------
        // 1. 安卓APP客户端
        // --------------------
        $versionTable = $tablePrefix . 'version';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$versionTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $versionFields = [
            'versionname'    => "VARCHAR(100) NOT NULL DEFAULT '' COMMENT '版本名称'",
            'versioncode'    => "VARCHAR(100) NOT NULL COMMENT '版本编号,这是判断版本的标准'",
            'download'       => "VARCHAR(1024) NOT NULL COMMENT '新版下载地址直连'",
            'imageUrl'       => "TEXT DEFAULT NULL COMMENT '底部导航栏背景图'",
            'up_imageUrl'    => "TEXT DEFAULT NULL COMMENT '顶部通知图标'",
            'up_title'       => "TEXT DEFAULT NULL COMMENT '顶部通知标题'",
            'up_desc'        => "TEXT DEFAULT NULL COMMENT '顶部通知说明'",
            'up_actionType'  => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '顶部通知点击执行事件1=打开网页，2=打开activity，3=加QQ，4=加QQ群'",
            'up_actionArg'   => "TEXT DEFAULT NULL COMMENT '顶部通知点击执行事件参数'",
            'newnotice'      => "TEXT DEFAULT NULL COMMENT '更新说明'",
            'enabled'        => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用'",
            'notice'         => "TEXT DEFAULT NULL COMMENT '公告通知'",
            'update_time'    => "DATETIME NOT NULL COMMENT '创建时间'",
        ];
        addFieldsIfNotExist($pdo, $versionTable, $versionFields);
        $indexCheck = $pdo->prepare("SHOW INDEX FROM `$versionTable` WHERE Key_name = 'uniq_versioncode'");
        $indexCheck->execute();
        if ($indexCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$versionTable` ADD UNIQUE KEY `uniq_versioncode` (`versioncode`)");
        }
        
        // --------------------
        // 20. html模板表
        // --------------------
        $htmlPopupTable = $tablePrefix . 'template_html';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$htmlPopupTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $htmlPopupTable, [
            'title'    => "TEXT NOT NULL COMMENT '标题'",
            'remark'       => "TEXT DEFAULT NULL COMMENT '备注'",
            'enable'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用'",
            'html'         => "TEXT NOT NULL COMMENT 'HTML代码'",
            'imageUrl'     => "TEXT COMMENT '预览图地址'",
            'created_at'   => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        
        
        //加固特征名称规则表
        $rulesTable = $tablePrefix . 'rules';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$rulesTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $rulesFields = [
            'type'           => "VARCHAR(255) NOT NULL COMMENT '加固壳名称'",
            'detection'      => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '检测类型,0=上传检测，1=注入检测'",
            'message'        => "TEXT DEFAULT NULL COMMENT '命中规则后返回的内容'",
        ];
        addFieldsIfNotExist($pdo, $rulesTable, $rulesFields);
        
        //加固特征关键字表
        $rule_keywordsTable = $tablePrefix . 'rule_keywords';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$rule_keywordsTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $upload_keywordsFields = [
            'rule_id'  => "INT NOT NULL COMMENT '特征名称id'",
            'keyword'  => "VARCHAR(255) NOT NULL COMMENT '特征关键字'",
        ];
        addFieldsIfNotExist($pdo, $rule_keywordsTable, $upload_keywordsFields);
        addForeignKeyIfNotExist($pdo, $rule_keywordsTable, 'rule_id', $rulesTable, 'id');

        // --------------------
        // 1. 用户账号表
        // --------------------
        $userTable = $tablePrefix . 'user';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$userTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $userFields = [
            'nickname'       => "VARCHAR(100) NOT NULL DEFAULT '' COMMENT '昵称'",
            'avatar'         => "VARCHAR(255) NOT NULL DEFAULT 'https://p2.ssl.qhimgs1.com/sdr/400__/t0101487df3d8159898.jpg' COMMENT '头像URL'",
            'account'        => "VARCHAR(100) NOT NULL COMMENT '账号'",
            'password'       => "VARCHAR(100) NOT NULL COMMENT '密码'",
            'token'          => "VARCHAR(255) DEFAULT NULL COMMENT '登录Token'",
            'apptoken'       => "VARCHAR(255) DEFAULT NULL COMMENT 'APP端登录Token'",
            'multiple_web'   => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否允许网页多设备登录'",
            'multiple_app'   => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否允许APP多设备登录'",
            'role'           => "VARCHAR(20) NOT NULL DEFAULT 'user' COMMENT '权限'",
            'login_ip'       => "VARCHAR(45) NOT NULL DEFAULT '' COMMENT '登录IP地址'",
            'register_time'  => "DATETIME NOT NULL COMMENT '注册时间'",
            'unblock_time'   => "DATETIME DEFAULT NULL COMMENT '账号封禁到期时间'",
            'register_ip'    => "VARCHAR(45) NOT NULL DEFAULT '' COMMENT '注册IP地址'",
            'openid_qq'      => "VARCHAR(255) DEFAULT NULL COMMENT '绑定的QQ的openid'",
            'last_login'     => "DATETIME DEFAULT NULL COMMENT '最后登录时间'",
            'last_active'    => "DATETIME DEFAULT NULL COMMENT '最后活动时间'",
            'superior'       => "INT DEFAULT NULL COMMENT '邀请人uid'",
            'balance'        => "INT NOT NULL DEFAULT 0 COMMENT '余额'",
            'app_count'      => "INT NOT NULL DEFAULT 20 COMMENT 'APP数量'",
            'lanzou_account' => "VARCHAR(100) DEFAULT NULL COMMENT '蓝奏账号'",
            'lanzou_password'=> "VARCHAR(100) DEFAULT NULL COMMENT '蓝奏密码'",
            'lanzou_cookie'  => "TEXT DEFAULT NULL COMMENT '蓝奏cookie'",
            'UA'             => "TEXT DEFAULT NULL COMMENT '活动UA头'",
            'appinfo'        => "TEXT DEFAULT NULL COMMENT 'APP客户端信息'",
            'lanzou_uid'     => "VARCHAR(20) DEFAULT NULL COMMENT '蓝奏uid'",
            'vip_expire_time'=> "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'VIP到期时间'",
            'pretty'         => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否显示靓UID标'",
        ];
        addFieldsIfNotExist($pdo, $userTable, $userFields);
        $indexCheck = $pdo->prepare("SHOW INDEX FROM `$userTable` WHERE Key_name = 'uniq_account'");
        $indexCheck->execute();
        if ($indexCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$userTable` ADD UNIQUE KEY `uniq_account` (`account`)");
        }
        // --------------------
        // 2. 模板表
        // --------------------
        $templateTable = $tablePrefix . 'template';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$templateTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $templateFields = [
            'name'           => "VARCHAR(100) NOT NULL COMMENT '模板名称'",
            'description'    => "TEXT NOT NULL COMMENT '模板介绍'",
            'version'        => "VARCHAR(20) NOT NULL COMMENT '模板版本'",
            'enabled'        => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用'",
            'className'      => "VARCHAR(255) NOT NULL DEFAULT 'cn.shell.App' COMMENT '壳入口Application类名'",
            'path'           => "VARCHAR(255) NOT NULL COMMENT '储存路径'",
            'extract_path'   => "VARCHAR(255) NOT NULL COMMENT '解压后路径'",
            'extracted'      => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已解压'",
            'user_id'        => "INT NOT NULL COMMENT '上传用户ID'",
            'upload_time'    => "DATETIME NOT NULL COMMENT '上传时间'",
            'extract_time'   => "DATETIME DEFAULT NULL COMMENT '解压时间'",
            'file_size'      => "INT NOT NULL DEFAULT 0 COMMENT '文件大小'",
            'price'          => "INT NOT NULL DEFAULT 0 COMMENT '模板收费金额'"
        ];
        addFieldsIfNotExist($pdo, $templateTable, $templateFields);
        addForeignKeyIfNotExist($pdo, $templateTable, 'user_id', $userTable, 'id');

        // --------------------
        // 3. 签名文件表
        // --------------------
        $signTable = $tablePrefix . 'sign';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$signTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $signFields = [
            'name'           => "VARCHAR(100) NOT NULL COMMENT '签名名称'",
            'user_id'        => "INT NOT NULL COMMENT '上传用户ID'",
            'alias'          => "VARCHAR(100) NOT NULL COMMENT '别名'",
            'password'       => "VARCHAR(100) NOT NULL COMMENT '密码'",
            'cert_password'  => "VARCHAR(100) NOT NULL COMMENT '证书密码'",
            'path'           => "VARCHAR(255) NOT NULL COMMENT '储存路径'",
            'upload_time'    => "DATETIME NOT NULL COMMENT '上传时间'"
        ];
        addFieldsIfNotExist($pdo, $signTable, $signFields);
        addForeignKeyIfNotExist($pdo, $signTable, 'user_id', $userTable, 'id');
        
        
        // --------------------
        // 3. 应用包名保护表
        // --------------------
        $protectTable = $tablePrefix . 'protect';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$protectTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $protectFields = [
            'package'        => "VARCHAR(255) NOT NULL COMMENT '要保护的包名'",
            'user_id'        => "INT NOT NULL COMMENT '独家用户ID'",
            'remark'         => "VARCHAR(100) DEFAULT NULL COMMENT '备注'",
            'upload_time'    => "DATETIME NOT NULL COMMENT '保护到期时间'"
        ];
        addFieldsIfNotExist($pdo, $protectTable, $protectFields);
        addForeignKeyIfNotExist($pdo, $protectTable, 'user_id', $userTable, 'id');
        
        // --------------------
        // 3. 站内信表
        // --------------------
        $messageTable = $tablePrefix . 'message';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$messageTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $messageFields = [
            'send_user_id'   => "INT NOT NULL COMMENT '发送方账户id'",
            'receive_user_id'=> "INT NOT NULL COMMENT '接收方账户id'",
            'message'        => "TEXT DEFAULT NULL COMMENT '消息内容'",
            'read'           => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已读'",
            'upload_time'    => "DATETIME NOT NULL COMMENT '消息时间'"
        ];
        addFieldsIfNotExist($pdo, $messageTable, $messageFields);
        addForeignKeyIfNotExist($pdo, $messageTable, 'send_user_id', $userTable, 'id');
        
        
        // --------------------
        // 3. 禁止上传的应用包名
        // --------------------
        $blackPkgTable = $tablePrefix . 'backage';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$blackPkgTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $blackPkgFields = [
            'package'        => "VARCHAR(100) NOT NULL COMMENT '包名'",
            'remark'         => "VARCHAR(100) DEFAULT NULL COMMENT '备注'",
            'message'        => "VARCHAR(100) DEFAULT '禁止上传该应用' COMMENT '返回的禁止原因'",
            'upload_time'    => "DATETIME NOT NULL COMMENT '创建时间'"
        ];
        addFieldsIfNotExist($pdo, $blackPkgTable, $blackPkgFields);
        
        
        // --------------------
        // 3. 可疑注入特征
        // --------------------
        $blackPkgTable = $tablePrefix . 'invoke';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$blackPkgTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $blackPkgFields = [
            'className'      => "VARCHAR(100) NOT NULL COMMENT '类名'",
            'methodName'     => "VARCHAR(100) DEFAULT NULL COMMENT '方法名,留空则代表是入口链路注入,不留空则代表是invoke注入'",
            'message'        => "VARCHAR(100) NOT NULL COMMENT '特征名称,对前端展示'",
            'exit'           => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否nop之后的exit方法调用'",
            'remark'         => "VARCHAR(100) DEFAULT NULL COMMENT '备注'",
            
            'upload_time'    => "DATETIME NOT NULL COMMENT '创建时间'"
        ];
        addFieldsIfNotExist($pdo, $blackPkgTable, $blackPkgFields);
        
        
        // --------------------
        // 4. APK表
        // --------------------
        $apkTable = $tablePrefix . 'apk';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$apkTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $apkFields = [
            'name'           => "VARCHAR(100) NOT NULL COMMENT 'APK 名称'",
            'version'        => "VARCHAR(50) NOT NULL COMMENT '版本号'",
            'icon'           => "VARCHAR(50) DEFAULT 'android.png' COMMENT '应用图标文件名'",
            'package'        => "VARCHAR(100) NOT NULL COMMENT '包名'",
            'path'           => "VARCHAR(255) NOT NULL COMMENT '储存路径'",
            'osspath'        => "VARCHAR(255) DEFAULT NULL COMMENT 'OSS端储存路径'",
            'app_key'        => "VARCHAR(255) DEFAULT NULL COMMENT 'APP卡密解绑授权码,用于解绑卡密,而无需验证设备码'",
            'tag'            => "VARCHAR(255) DEFAULT NULL COMMENT '应用标记'",
            'user_id'        => "INT NOT NULL COMMENT '上传用户ID'",
            'size'           => "INT DEFAULT NULL COMMENT '文件大小'",
            'upload_time'    => "DATETIME NOT NULL COMMENT '上传时间'",
            'config_mode'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '配置使用方式：0=独用一套配置，1=复用其他应用配置'",
            'reuse_apk_id'   => "INT DEFAULT NULL COMMENT '复用的应用ID'",
            'domain_mode'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '接口域名配置方式：0=壳内置，1=自定义'",
            'custom_domains' => "TEXT DEFAULT NULL COMMENT '自定义域名列表，换行分隔，需带协议前缀'",
            'reuse_options'  => "TEXT DEFAULT NULL COMMENT '复用选项，JSON数组格式存储勾选的功能项'", // 新增字段
            'sign'           => "TEXT DEFAULT NULL COMMENT 'X509证书信息'", // 过签名校验的时候需要
            
        ];
        addFieldsIfNotExist($pdo, $apkTable, $apkFields);
        addForeignKeyIfNotExist($pdo, $apkTable, 'user_id', $userTable, 'id');
        
        
        // --------------------
        // 4. 拉黑设备表
        // --------------------
        $disableTable = $tablePrefix . 'disable';
        $apkTable    = $tablePrefix . 'apk';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$disableTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $disableFields = [
            'appid'          => "INT NOT NULL COMMENT '被拉黑的应用id'",
            'deviceId'       => "TEXT DEFAULT NULL COMMENT '被拉黑的设备id'",
            'created_at'     => "DATETIME NOT NULL COMMENT '拉黑时间'",
            'remark'         => "TEXT DEFAULT NULL COMMENT '拉黑备注说明'", // 新增字段
            'enable'         => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用规则，启用=1，不启用=0'",
            
        ];
        addFieldsIfNotExist($pdo, $disableTable, $disableFields);
        addForeignKeyIfNotExist($pdo, $disableTable, 'appid', $apkTable, 'id');
        
        // --------------------
        // 5. APK 配置表
        // --------------------
        $configTable = $tablePrefix . 'apk_config';
        $apkTable    = $tablePrefix . 'apk';
        
        // 创建表（如果不存在）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$configTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // 配置字段定义
        $configFields = [
            'apk_id'                   => "INT NOT NULL COMMENT '应用ID'",
            'debug'                    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '调试模式'",
            'offline'                  => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '离线模式(优先使用上次缓存的远程配置)'",
            'websocket'                => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ws实时连接管理'",
            'ban_Root'                 => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '禁止 Root'",
            'ban_Xposed'               => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '禁止 Xposed 框架'",
            'ban_Emulator'             => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '禁止模拟器'",
            'ban_VirtualApp'           => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '禁止沙盒环境'",
            'ban_DualApp'              => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '禁止双开环境'",
            'black_package'            => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '黑名单应用检测'",
            'enable_popup_kill_all'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '全局通杀弹窗'",
            'enable_popup_keywords'    => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '关键词弹窗拦截'",
            'enable_sp_put'            => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'SP写入劫持'",
            'enable_sp_get'            => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'SP读取劫持'",
            'enable_sp'                => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'SP整体替换'",
            'enablePopups'             => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '全屏弹窗支持'",
            'enableImagePopups'        => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '图片弹窗支持'",
            'enableMessagePopups'      => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '文字弹窗支持'",
            'enableinputPopups'        => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '输入框弹窗支持'",
            'enablehtmlPopups'         => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'HTML弹窗支持'",
            'screen_priority'          => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '全屏弹窗优先,开启后,小窗只能在关闭全屏窗之后显示'",
            'enabledex'                => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '远程dex开关'",
            'enableHook'               => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '启用HOOK高级功能'",
        ];
        addFieldsIfNotExist($pdo, $configTable, $configFields);
        
        
        // 添加唯一索引前先检查是否已存在,20250703修复重复创建索引的问题
        $stmt = $pdo->prepare("
            SELECT COUNT(1) 
            FROM information_schema.STATISTICS 
            WHERE table_schema = DATABASE() 
              AND table_name = :table 
              AND index_name = 'uniq_apk_id'
        ");
        $stmt->execute([':table' => $configTable]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `$configTable` ADD UNIQUE KEY `uniq_apk_id` (`apk_id`)");
        }
        
        // 添加外键（如不存在）
        addForeignKeyIfNotExist($pdo, $configTable, 'apk_id', $apkTable, 'id');
        
        // 创建触发器（不存在时）
        $triggerInsert = "trg_after_apk_insert";
        $triggerDelete = "trg_after_apk_delete";
        
        // 插入触发器是否存在
        $stmt = $pdo->prepare("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_NAME = :name");
        $stmt->execute([':name' => $triggerInsert]);
        if ($stmt->rowCount() === 0) {
            $pdo->exec("
                CREATE TRIGGER `$triggerInsert`
                AFTER INSERT ON `$apkTable`
                FOR EACH ROW
                INSERT INTO `$configTable` (`apk_id`) VALUES (NEW.id)
            ");
        }
        
        // 删除触发器是否存在
        $stmt = $pdo->prepare("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_NAME = :name");
        $stmt->execute([':name' => $triggerDelete]);
        if ($stmt->rowCount() === 0) {
            $pdo->exec("
                CREATE TRIGGER `$triggerDelete`
                AFTER DELETE ON `$apkTable`
                FOR EACH ROW
                DELETE FROM `$configTable` WHERE `apk_id` = OLD.id
            ");
        }

        // --------------------
        // 6. 注入任务表
        // --------------------
        $taskTable     = $tablePrefix . 'inject_task';
        $userTable     = $tablePrefix . 'user';
        $templateTable = $tablePrefix . 'template';
        $apkTable      = $tablePrefix . 'apk';
        $signTable     = $tablePrefix . 'sign';
        
        // 创建表（如果不存在）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$taskTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // 字段定义
        $taskFields = [
            'remark'         => "TEXT NOT NULL COMMENT '备注信息'",
            'user_id'        => "INT NOT NULL COMMENT '用户ID'",
            'template_id'    => "INT NOT NULL COMMENT '模板ID'",
            'apk_id'         => "INT NOT NULL COMMENT '应用ID'",
            'sign_id'        => "INT NOT NULL COMMENT '签名ID'",//confuse
            'confuse'        => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '合并包，用于混淆对抗，0=不合并，1合并'",
            'inject_to_top'  => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否注入到顶级父类，0=基础子类，1=顶级父类'",
            'kill_Inject'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否清除云注入链，0=不处理，1=清除云注入'",
            'allowHttp'      => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否解除http限制，0=不处理，1=处理'",
            'network'        => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否检测网络，0=不检测网络情况，1=无网络自动退出'",
            'jiagu'          => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否加固，默认0不加固，非0为加固'",
            'dexmerge'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否dex合并，默认0不合并，非0则合并为每个dex64000方法'",
            'fake'           => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否伪加密Android manifest，默认0不加固，非0为加固，加密后不支持安卓15'",
            'vpncheck'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否禁止VPN，默认0，不禁止，1代表检测到VPN后会结束APP'",
            'mode'           => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '注入模式，0=application入口注入，1=application链注入，2=appComponentFactory注入'",
            'debug'          => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '注入debuggable，0=false，1=true'",
            'killsign'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '注入过签名验证，0=false，1=true'",
            'killpath'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '注入文件到/assets/yunzhuru/origin.apk,等同于MT管理器去签的加强模式，0=false，1=true'",
            'Request'        => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '注入文件到/assets/yunzhuru.type,是否启用并发请求模式，0=false，1=true'",
            'debugrun'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否被通过真机测试,0=待调试，1=调试通过，2=调试不通过'",
            'isMainProcess'  => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否进程隔离，0代表不隔离，1代表隔离，默认1，进程隔离后，只有主进程会有弹窗，沙盒类应用需要隔离'",
            'launcher'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '替换启动窗口，0=false，1=true'",
            'tv'             => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否开启按钮焦点以适配TV端，0=false，1=true'",
            'devices'        => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '设备码计算方式，0=硬件信息，1=AndroidID'",
            'created_at'     => "DATETIME NOT NULL COMMENT '任务创建时间'",
            'status_text'    => "VARCHAR(50) NOT NULL DEFAULT '等待处理' COMMENT '任务状态文本'",
            'status_info'    => "TEXT DEFAULT NULL COMMENT '其他文字说明'",
            'completed_at'   => "DATETIME DEFAULT NULL COMMENT '任务完成时间'",
            'size'           => "INT DEFAULT NULL COMMENT '注入后的文件大小'",
            'injected_apk'   => "VARCHAR(255) DEFAULT NULL COMMENT '注入后APK路径'",
            'permissions'    => "TEXT DEFAULT NULL COMMENT '添加的额外权限'",
            'encry'          => "VARCHAR(255) DEFAULT '无' COMMENT '应用加固情况'",
            'debugimg'       => "VARCHAR(1024) DEFAULT NULL COMMENT '云检测截图'"
        ];
        
        addFieldsIfNotExist($pdo, $taskTable, $taskFields);
        
        // 添加外键（如不存在）
        addForeignKeyIfNotExist($pdo, $taskTable, 'user_id', $userTable, 'id');
        addForeignKeyIfNotExist($pdo, $taskTable, 'template_id', $templateTable, 'id');
        addForeignKeyIfNotExist($pdo, $taskTable, 'apk_id', $apkTable, 'id');
        addForeignKeyIfNotExist($pdo, $taskTable, 'sign_id', $signTable, 'id');
        
        
        
        // --------------------
        // 6. 加固任务表
        // --------------------
        $taskTable     = $tablePrefix . 'jiagu_task';
        $userTable     = $tablePrefix . 'user';
        $apkTable      = $tablePrefix . 'apk';
        $signTable     = $tablePrefix . 'sign';
        
        // 创建表（如果不存在）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$taskTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // 字段定义
        $taskFields = [
            'user_id'        => "INT NOT NULL COMMENT '用户ID'",
            'apk_id'         => "INT NOT NULL COMMENT '应用ID'",
            'sign_id'        => "INT NOT NULL COMMENT '签名ID'",
            'sign_type'      => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '签名校验类型，0=应用自身hash256，1=平台证书hash256，2=自定义hash256'",
            'hash256'        => "VARCHAR(64) DEFAULT NULL COMMENT '证书hash256'",
            'rules'          => "TEXT DEFAULT NULL COMMENT '加固规则表'",
            'type'           => "VARCHAR(255) DEFAULT 'vmp' COMMENT '加固类型，暂时vmp和dpt两种类型'",
            'created_at'     => "DATETIME NOT NULL COMMENT '任务创建时间'",
            'status_text'    => "VARCHAR(50) NOT NULL DEFAULT '等待处理' COMMENT '任务状态文本'",
            'status_info'    => "TEXT DEFAULT NULL COMMENT '其他文字说明'",
            'completed_at'   => "DATETIME DEFAULT NULL COMMENT '任务完成时间'",
            'size'           => "INT DEFAULT NULL COMMENT '加固后的文件大小'",
            'injected_apk'   => "VARCHAR(255) DEFAULT NULL COMMENT '加固后APK路径'",
            
        ];
        addFieldsIfNotExist($pdo, $taskTable, $taskFields);
        addForeignKeyIfNotExist($pdo, $taskTable, 'user_id', $userTable, 'id');
        addForeignKeyIfNotExist($pdo, $taskTable, 'apk_id', $apkTable, 'id');
        addForeignKeyIfNotExist($pdo, $taskTable, 'sign_id', $signTable, 'id');
        
        
        
        
        
        // --------------------
        // 7. 拦截窗口类表
        // --------------------
        $windowTable  = $tablePrefix . 'window_class';
        $configTable  = $tablePrefix . 'apk_config';
        
        // 创建表（如果不存在）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$windowTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // 字段定义
        $windowFields = [
            'config_id'   => "INT NOT NULL COMMENT '配置ID'",
            'class_name'  => "VARCHAR(200) NOT NULL COMMENT '窗口类名'",
            'remark'      => "TEXT NOT NULL COMMENT '备注信息'",
            'created_at'  => "DATETIME NOT NULL COMMENT '创建时间'"
        ];
        
        addFieldsIfNotExist($pdo, $windowTable, $windowFields);
        
        // 添加外键（如不存在），配置删除时级联删除
        addForeignKeyIfNotExist($pdo, $windowTable, 'config_id', $configTable, 'id');
        
        // --------------------
        // 7. 替换窗口类表
        // --------------------
        $newactivity  = $tablePrefix . 'newactivity';
        $configTable  = $tablePrefix . 'apk_config';
        
        // 创建表（如果不存在）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$newactivity` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // 字段定义
        $newactivityFields = [
            'config_id'   => "INT NOT NULL COMMENT '配置ID'",
            'activity'  => "VARCHAR(200) NOT NULL COMMENT '原窗口类名'",
            'newactivity'  => "VARCHAR(200) NOT NULL COMMENT '新窗口类名'",
            'remark'      => "TEXT NOT NULL COMMENT '备注信息'",
            'created_at'  => "DATETIME NOT NULL COMMENT '创建时间'"
        ];
        
        addFieldsIfNotExist($pdo, $newactivity, $newactivityFields);
        
        // 添加外键（如不存在），配置删除时级联删除
        addForeignKeyIfNotExist($pdo, $newactivity, 'config_id', $configTable, 'id');




        // --------------------
        // 8. 敏感应用表
        // --------------------
        $sensitiveTable = $tablePrefix . 'sensitive_app';
        $configTable    = $tablePrefix . 'apk_config';
        
        // 创建表（如果不存在）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$sensitiveTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // 字段定义
        $sensitiveFields = [
            'config_id'     => "INT NOT NULL COMMENT '配置ID'",
            'package_name'  => "VARCHAR(200) NOT NULL COMMENT '敏感应用包名'",
            'detect_type'   => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '检测类型：0=包名存在，1=包名不存在'",
            'action_type'   => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '执行事件：0=壳静默，1=结束运行，2=弹出提示，3=弹出提示并结束运行'",
            'tip_text'      => "TEXT NOT NULL COMMENT '弹出提示内容'",
            'remark'        => "TEXT NOT NULL COMMENT '备注信息'",
            'created_at'    => "DATETIME NOT NULL COMMENT '创建时间'"
        ];
        
        addFieldsIfNotExist($pdo, $sensitiveTable, $sensitiveFields);
        
        // 添加外键（如不存在）
        addForeignKeyIfNotExist($pdo, $sensitiveTable, 'config_id', $configTable, 'id');

        // --------------------
        // 9. 弹窗类型表
        // --------------------
        $popupTable = $tablePrefix . 'popup_type';
        
        // 创建表（如果不存在）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$popupTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // 字段定义
        $popupFields = [
            'popup_id'    => "INT NOT NULL UNIQUE COMMENT '弹窗ID'",
            'description' => "VARCHAR(100) NOT NULL COMMENT '弹窗说明'"
        ];
        addFieldsIfNotExist($pdo, $popupTable, $popupFields);
        
        // 插入预设数据（不存在才插入）
        $predefinedPopups = [
            1     => '窗口(Activity)-1',
            2     => '弹窗(Dialog)-2',
            1002  => '菜单-1002',
            2002  => '老版悬浮窗-2002',
            2003  => '系统提示-2003',
            2005  => 'Toast提示-2005',
            2006  => '输入法-2006',
            2007  => '壁纸窗口-2007',
            2008  => '状态栏-2008',
            2038  => '悬浮窗-2038'
        ];
        
        foreach ($predefinedPopups as $id => $desc) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$popupTable` WHERE `popup_id` = :id");
            $stmt->execute([':id' => $id]);
            if ($stmt->fetchColumn() == 0) {
                $insert = $pdo->prepare("INSERT INTO `$popupTable` (`popup_id`, `description`) VALUES (:id, :desc)");
                $insert->execute([':id' => $id, ':desc' => $desc]);
            }
        }



        // --------------------
        // 10. 杀窗口类型表
        // --------------------
        $killTypeTable = $tablePrefix . 'popup_kill_type';
        $configTable   = $tablePrefix . 'apk_config';
        $popupTypeTable = $tablePrefix . 'popup_type';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$killTypeTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $killFields = [
            'config_id'     => "INT NOT NULL COMMENT '配置ID'",
            'popup_id'      => "INT NOT NULL COMMENT '弹窗类型ID'",
            'created_at'    => "DATETIME NOT NULL COMMENT '创建时间'"
        ];
        addFieldsIfNotExist($pdo, $killTypeTable, $killFields);
        addForeignKeyIfNotExist($pdo, $killTypeTable, 'config_id', $configTable, 'id');
        addForeignKeyIfNotExist($pdo, $killTypeTable, 'popup_id', $popupTypeTable, 'id');
        
        
        // --------------------
        // 11. 关键词表
        // --------------------
        $keywordTable = $tablePrefix . 'keyword';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$keywordTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $keywordFields = [
            'config_id'     => "INT NOT NULL COMMENT '配置ID'",
            'keyword'       => "VARCHAR(100) NOT NULL COMMENT '关键词'",
            'type'          => "TINYINT(0) NOT NULL DEFAULT 0 COMMENT '拦截类型，0=关闭，1=替换'",
            'new_keyword'   => "VARCHAR(100) NOT NULL DEFAULT '' COMMENT '新关键词'",
            'clickAction'   => "INT NOT NULL DEFAULT 0 COMMENT '点击事件类型0=不重写，1=打开网址，2=禁止点击'",
            'clickText'     => "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '点击事件参数文本'",
            'created_at'    => "DATETIME NOT NULL COMMENT '创建时间'"
        ];
        addFieldsIfNotExist($pdo, $keywordTable, $keywordFields);
        addForeignKeyIfNotExist($pdo, $keywordTable, 'config_id', $configTable, 'id');
        
        
        
        // --------------------
        // 11. 布局重写表
        // --------------------
        
        $viewTable = $tablePrefix . 'view';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$viewTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $viewFields = [
            'config_id'     => "INT NOT NULL COMMENT '配置ID'",
            'activity'      => "VARCHAR(100) NOT NULL COMMENT 'activity窗口类名'",
            'view_class'    => "VARCHAR(100) NOT NULL COMMENT '布局类名'",
            'view_id'       => "VARCHAR(100) NOT NULL COMMENT '布局id'",
            'visibility'    => "INT NOT NULL DEFAULT 0 COMMENT '可见属性：0=显示，1=隐藏不占位，2=占位隐藏'",
            'clickable'     => "INT NOT NULL DEFAULT 0 COMMENT '允许点击属性，0=允许，1=不允许'",
            'imageview'     => "VARCHAR(1024) NOT NULL DEFAULT '' COMMENT '重写图片控件的图片地址'",
            'textview'      => "VARCHAR(1024) NOT NULL DEFAULT '' COMMENT '重写文字控件的文字内容'",
            'clickAction'   => "INT NOT NULL DEFAULT 0 COMMENT '点击事件类型0=不重写，1=打开网址，2=禁止点击，3=打开窗口类'",
            'clickText'     => "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '点击事件参数文本'",
            'enabled'       => "INT NOT NULL DEFAULT 1 COMMENT '是否启用此项重写，1=启用，0=禁用'",
            'created_at'    => "DATETIME NOT NULL COMMENT '创建时间'"
        ];
        addFieldsIfNotExist($pdo, $viewTable, $viewFields);
        addForeignKeyIfNotExist($pdo, $viewTable, 'config_id', $configTable, 'id');
        
        
        
        
        // --------------------
        // 11. 远程dex表
        // --------------------
        $remoteDexTable = $tablePrefix . 'remote_dex';
        // 创建表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$remoteDexTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $remoteDexFields = [
            'config_id'     => "INT NOT NULL COMMENT '关联配置ID'",
            'url'           => "VARCHAR(255) NOT NULL COMMENT '远程DEX地址'",
            'class_name'    => "VARCHAR(200) NOT NULL COMMENT '类名（全限定名）'",
            'method_name'   => "VARCHAR(100) NOT NULL COMMENT '方法名'",
            'enabled'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用（0=否，1=是）'",
            'created_at'    => "DATETIME NOT NULL COMMENT '创建时间'",
            'remark'        => "TEXT COMMENT '备注'"
        ];
        addFieldsIfNotExist($pdo, $remoteDexTable, $remoteDexFields);
        addForeignKeyIfNotExist($pdo, $remoteDexTable, 'config_id', $configTable, 'id');
        
        // --------------------
        // 11. 卡密表
        // --------------------
        $kamiTable = $tablePrefix . 'kami';
        // 创建表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$kamiTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $kamiFields = [
            'app_id'     => "INT NOT NULL COMMENT '应用ID'",
            'kami'       => "VARCHAR(255) NOT NULL COMMENT '卡密字符串'",
            'created_at' => "DATETIME NOT NULL COMMENT '卡密创建时间'",
            'use_at'     => "DATETIME DEFAULT NULL COMMENT '卡密使用时间'",
            'name'       => "VARCHAR(200) NOT NULL COMMENT '卡密名称'",
            'time'       => "DOUBLE NOT NULL DEFAULT 1 COMMENT '卡密有效时长,单位小时'",
            'deviceId'   => "VARCHAR(200) NOT NULL COMMENT '使用者设备ID'",
            'package'    => "VARCHAR(200) NOT NULL COMMENT '使用应用包名'",
            'version'    => "VARCHAR(200) NOT NULL COMMENT '使用应用版本'",
            'enabled'    => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用（0=否，1=是）'",
            'bind'       => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '一机一码（0=否，1=是）'",
            'remark'     => "TEXT COMMENT '备注'"
        ];
        addFieldsIfNotExist($pdo, $kamiTable, $kamiFields);
        addForeignKeyIfNotExist($pdo, $kamiTable, 'app_id', $apkTable, 'id');
        // kami 表核心索引
        addIndexIfNotExist(
            $pdo,
            $kamiTable,
            'idx_kami_device_enabled_use',
            'deviceId, enabled, use_at'
        );

        
        // --------------------
        // 12. 拦截弹窗类型表
        // --------------------
        $blockPopupTable = $tablePrefix . 'popup_block_type';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$blockPopupTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $blockFields = [
            'config_id'     => "INT NOT NULL COMMENT '配置ID'",
            'popup_id'      => "INT NOT NULL COMMENT '弹窗类型ID'",
            'created_at'    => "DATETIME NOT NULL COMMENT '创建时间'"
        ];
        addFieldsIfNotExist($pdo, $blockPopupTable, $blockFields);
        addForeignKeyIfNotExist($pdo, $blockPopupTable, 'config_id', $configTable, 'id');
        addForeignKeyIfNotExist($pdo, $blockPopupTable, 'popup_id', $popupTypeTable, 'id');
        
        // --------------------
        // 13. URI 劫持表
        // --------------------
        $uriHijackTable = $tablePrefix . 'uri_hijack';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$uriHijackTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $uriFields = [
            'config_id'     => "INT NOT NULL COMMENT '配置ID'",
            'remark'        => "TEXT NOT NULL COMMENT '备注'",
            'class_name'    => "VARCHAR(200) NOT NULL COMMENT '类名'",
            'uri_value'     => "VARCHAR(300) NOT NULL COMMENT 'URI值'",
            'created_at'    => "DATETIME NOT NULL COMMENT '创建时间'"
        ];
        addFieldsIfNotExist($pdo, $uriHijackTable, $uriFields);
        addForeignKeyIfNotExist($pdo, $uriHijackTable, 'config_id', $configTable, 'id');

        // --------------------
        // 14. SP 写入劫持表
        // --------------------
        $spPutNameTable = $tablePrefix . 'sp_put_name';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$spPutNameTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $spPutNameTable, [
            'config_id'   => "INT NOT NULL COMMENT '配置ID'",
            'sp_name'     => "VARCHAR(200) NOT NULL COMMENT 'SP名称'",
            'created_at'  => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $spPutNameTable, 'config_id', $configTable, 'id');
        
        // --------------------
        // 15. SP 写入劫持详情表
        // --------------------
        $spPutDetailTable = $tablePrefix . 'sp_put_detail';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$spPutDetailTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $spPutDetailTable, [
            'name_id'     => "INT NOT NULL COMMENT '劫持名称ID'",
            'key_name'    => "VARCHAR(200) NOT NULL COMMENT '键名'",
            'key_value'   => "TEXT NOT NULL COMMENT '键值'",
            'type'        => "VARCHAR(200) DEFAULT NULL COMMENT '数据类型'",
            'created_at'  => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $spPutDetailTable, 'name_id', $spPutNameTable, 'id');
        
        // --------------------
        // 16. SP 读取劫持表
        // --------------------
        $spGetNameTable = $tablePrefix . 'sp_get_name';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$spGetNameTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $spGetNameTable, [
            'config_id'   => "INT NOT NULL COMMENT '配置ID'",
            'sp_name'     => "VARCHAR(200) NOT NULL COMMENT 'SP名称'",
            'created_at'  => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $spGetNameTable, 'config_id', $configTable, 'id');
        
        // --------------------
        // 17. SP 读取劫持详情表
        // --------------------
        $spGetDetailTable = $tablePrefix . 'sp_get_detail';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$spGetDetailTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $spGetDetailTable, [
            'name_id'     => "INT NOT NULL COMMENT '劫持名称ID'",
            'key_name'    => "VARCHAR(200) NOT NULL COMMENT '键名'",
            'key_value'   => "TEXT NOT NULL COMMENT '键值'",
            'type'        => "VARCHAR(200) DEFAULT NULL COMMENT '数据类型'",
            'created_at'  => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $spGetDetailTable, 'name_id', $spGetNameTable, 'id');
        
        // --------------------
        // 18. SP 重写表
        // --------------------
        $spOverrideNameTable = $tablePrefix . 'sp_override_name';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$spOverrideNameTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $spOverrideNameTable, [
            'config_id'   => "INT NOT NULL COMMENT '配置ID'",
            'sp_name'     => "VARCHAR(200) NOT NULL COMMENT 'SP名称'",
            'created_at'  => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $spOverrideNameTable, 'config_id', $configTable, 'id');
        
        // --------------------
        // 19. SP 重写详情表
        // --------------------
        $spOverrideDetailTable = $tablePrefix . 'sp_override_detail';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$spOverrideDetailTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $spOverrideDetailTable, [
            'name_id'     => "INT NOT NULL COMMENT '劫持名称ID'",
            'key_name'    => "VARCHAR(200) NOT NULL COMMENT '键名'",
            'key_value'   => "TEXT NOT NULL COMMENT '键值'",
            'type'        => "VARCHAR(200) DEFAULT NULL COMMENT '数据类型'",
            'created_at'  => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $spOverrideDetailTable, 'name_id', $spOverrideNameTable, 'id');


        // --------------------
        // 20. 图片弹窗表
        // --------------------
        $imagePopupTable = $tablePrefix . 'popup_image';
        $configTable = $tablePrefix . 'apk_config';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$imagePopupTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $imagePopupTable, [
            'config_id'    => "INT NOT NULL COMMENT '配置ID'",
            'popup_type'   => "VARCHAR(50) NOT NULL COMMENT '弹窗类型（全屏弹窗/图片弹窗）'",
            'remark'       => "TEXT NOT NULL COMMENT '备注'",
            'enable'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用'",
            'imageUrl'     => "TEXT NOT NULL COMMENT '图片地址'",
            'clickAction'  => "INT NOT NULL DEFAULT 0 COMMENT '点击事件类型'",
            'clickText'    => "TEXT NOT NULL COMMENT '点击事件参数文本'",
            'callback'     => "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '内部回调地址'",
            'countdown'    => "INT NOT NULL DEFAULT 3 COMMENT '倒计时秒数'",
            'canSkip'      => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否允许跳过'",
            'autoClose'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '倒计时结束自动关闭'",
            'created_at'   => "DATETIME NOT NULL COMMENT '创建时间'",
            'lock'         => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '弹窗保护,0代表不开启,1代表开启,开启后弹窗将不可被隐藏,且弹出的时候会锁定activity不可操作'",
            'show_count'   => "INT NOT NULL DEFAULT 0 COMMENT '展示次数'",
            'click_count'  => "INT NOT NULL DEFAULT 0 COMMENT '点击次数'"
        ]);
        addForeignKeyIfNotExist($pdo, $imagePopupTable, 'config_id', $configTable, 'id');
        
        // --------------------
        // 21. 图片弹窗白名单表
        // --------------------
        $imageWhitelistTable = $tablePrefix . 'popup_image_whitelist';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$imageWhitelistTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $imageWhitelistTable, [
            'popup_id'     => "INT NOT NULL COMMENT '图片弹窗ID'",
            'class_name'   => "VARCHAR(200) NOT NULL COMMENT '类名'",
            'remark'       => "VARCHAR(255) DEFAULT NULL COMMENT '备注'",
            'created_at'   => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $imageWhitelistTable, 'popup_id', $imagePopupTable, 'id');
        
        // --------------------
        // 22. 图片弹窗黑名单表
        // --------------------
        $fullscreenBlacklistTable = $tablePrefix . 'popup_fullscreen_blacklist';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$fullscreenBlacklistTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $fullscreenBlacklistTable, [
            'popup_id'     => "INT NOT NULL COMMENT '图片弹窗ID'",
            'class_name'   => "VARCHAR(200) NOT NULL COMMENT '类名'",
            'remark'       => "VARCHAR(255) DEFAULT NULL COMMENT '备注'",
            'created_at'   => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $fullscreenBlacklistTable, 'popup_id', $imagePopupTable, 'id');
        
        
        // --------------------
        // 20. html弹窗表
        // --------------------
        $htmlPopupTable = $tablePrefix . 'popup_html';
        $configTable = $tablePrefix . 'apk_config';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$htmlPopupTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $htmlPopupTable, [
            'config_id'    => "INT NOT NULL COMMENT '配置ID'",
            'remark'       => "TEXT NOT NULL COMMENT '备注'",
            'enable'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用'",
            'weight'        => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '弹窗权重，越大越靠前'",
            'html'         => "TEXT NOT NULL COMMENT 'HTML代码'",
            'created_at'   => "DATETIME NOT NULL COMMENT '创建时间'",
            'lock'         => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '弹窗保护,0代表不开启,1代表开启,开启后弹窗将不可被隐藏,且弹出的时候会锁定activity不可操作'"
        ]);
        addForeignKeyIfNotExist($pdo, $htmlPopupTable, 'config_id', $configTable, 'id');
        
        // --------------------
        // 21. 图片弹窗白名单表
        // --------------------
        $htmlWhitelistTable = $tablePrefix . 'popup_html_whitelist';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$htmlWhitelistTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $htmlWhitelistTable, [
            'popup_id'     => "INT NOT NULL COMMENT '图片弹窗ID'",
            'class_name'   => "VARCHAR(200) NOT NULL COMMENT '类名'",
            'remark'       => "VARCHAR(255) DEFAULT NULL COMMENT '备注'",
            'created_at'   => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $htmlWhitelistTable, 'popup_id', $htmlPopupTable, 'id');
        
        // --------------------
        // 22. 图片弹窗黑名单表
        // --------------------
        $htmlBlacklistTable = $tablePrefix . 'popup_html_blacklist';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$htmlBlacklistTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $htmlBlacklistTable, [
            'popup_id'     => "INT NOT NULL COMMENT '图片弹窗ID'",
            'class_name'   => "VARCHAR(200) NOT NULL COMMENT '类名'",
            'remark'       => "VARCHAR(255) DEFAULT NULL COMMENT '备注'",
            'created_at'   => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $htmlBlacklistTable, 'popup_id', $htmlPopupTable, 'id');
        
        
        

        // --------------------
        // 23. 文字弹窗信息表
        // --------------------
        $messagePopupTable = $tablePrefix . 'popup_message';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$messagePopupTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $messagePopupTable, [
            'config_id'       => "INT NOT NULL COMMENT '配置ID'",
            'remark'          => "TEXT DEFAULT NULL COMMENT '备注信息'",
            'interval'        => "INT(11) NOT NULL DEFAULT 0 COMMENT '间隔时间,单位小时,0=每次启动都弹,如果有值则有间隔限制'",
            'enable'          => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用'",
            'exitpopus'       => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '点击空白处关闭'",
            'backgroundColor' => "VARCHAR(20) NOT NULL DEFAULT '#FAFAFA' COMMENT '背景颜色'",
            'maskColor'       => "VARCHAR(20) NOT NULL DEFAULT '#80000000' COMMENT '遮罩颜色'",
            'title'           => "VARCHAR(100) NOT NULL COMMENT '标题'",
            'message'         => "TEXT NOT NULL COMMENT '弹窗信息'",
            'popup_type'      => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '弹窗类型'",//默认0，代表UI布局，否则为系统弹窗,系统弹窗则最多只支持3个按钮
            'lock'            => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '弹窗保护,0代表不开启,1代表开启,开启后弹窗将不可被隐藏,且弹出的时候会锁定activity不可操作'",
            'show_count'      => "INT NOT NULL DEFAULT 0 COMMENT '展示次数'",
            'click_count'     => "INT NOT NULL DEFAULT 0 COMMENT '点击次数'"
        ]);
        addForeignKeyIfNotExist($pdo, $messagePopupTable, 'config_id', $configTable, 'id');
        
        // --------------------
        // 24. 文字弹窗按钮表
        // --------------------
        $messageButtonTable = $tablePrefix . 'popup_message_button';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$messageButtonTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $messageButtonTable, [
            'popup_id'        => "INT NOT NULL COMMENT '弹窗ID'",
            'title'           => "VARCHAR(100) NOT NULL COMMENT '按钮标题'",
            'textcolor'       => "VARCHAR(20) NOT NULL DEFAULT '#FFFFFF' COMMENT '文字颜色'",
            'backgroundColor' => "VARCHAR(20) NOT NULL DEFAULT '#008577' COMMENT '背景颜色'",
            'click'           => "INT NOT NULL DEFAULT 0 COMMENT '点击事件ID'",
            'clickText'       => "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '事件参数文本'",
            'dismiss'         => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '点击后关闭弹窗'"
        ]);
        addForeignKeyIfNotExist($pdo, $messageButtonTable, 'popup_id', $messagePopupTable, 'id');
        
        // --------------------
        // 25. 文字弹窗白名单表
        // --------------------
        $messageWhitelistTable = $tablePrefix . 'popup_message_whitelist';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$messageWhitelistTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $messageWhitelistTable, [
            'popup_id'     => "INT NOT NULL COMMENT '弹窗ID'",
            'class_name'   => "VARCHAR(200) NOT NULL COMMENT '类名'",
            'remark'       => "VARCHAR(255) DEFAULT NULL COMMENT '备注'",
            'created_at'   => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $messageWhitelistTable, 'popup_id', $messagePopupTable, 'id');
        
        // --------------------
        // 26. 文字弹窗黑名单表
        // --------------------
        $messageBlacklistTable = $tablePrefix . 'popup_message_blacklist';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$messageBlacklistTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $messageBlacklistTable, [
            'popup_id'     => "INT NOT NULL COMMENT '弹窗ID'",
            'class_name'   => "VARCHAR(200) NOT NULL COMMENT '类名'",
            'remark'       => "VARCHAR(255) DEFAULT NULL COMMENT '备注'",
            'created_at'   => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $messageBlacklistTable, 'popup_id', $messagePopupTable, 'id');

        // --------------------
        // 27. 弹窗统计日志表（图片弹窗+文字弹窗共用）
        // --------------------
        $popupStatLogTable = $tablePrefix . 'popup_stat_log';

        $pdo->exec("CREATE TABLE IF NOT EXISTS `$popupStatLogTable` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        addFieldsIfNotExist($pdo, $popupStatLogTable, [
            'popup_id'     => "INT NOT NULL COMMENT '弹窗ID'",
            'module'       => "VARCHAR(50) NOT NULL COMMENT '模块名:popup_image或popup_message'",
            'type'         => "VARCHAR(10) NOT NULL COMMENT '事件类型:show或click'",
            'button_index' => "TINYINT NOT NULL DEFAULT -1 COMMENT '按钮索引,-1表示图片点击'",
            'click_type'   => "TINYINT NOT NULL DEFAULT 0 COMMENT '点击动作类型'",
            'click_text'   => "VARCHAR(1024) NOT NULL DEFAULT '' COMMENT '点击目标内容(链接/QQ群key等)'",
            'device_id'    => "VARCHAR(64) NOT NULL DEFAULT '' COMMENT '设备ID'",
            'created_at'   => "DATETIME NOT NULL COMMENT '记录时间'",
        ]);

        // --------------------
        // 28. 输入框弹窗信息表
        // --------------------
        $inputPopupTable = $tablePrefix . 'popup_input';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$inputPopupTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $inputPopupTable, [
            'config_id'       => "INT NOT NULL COMMENT '配置ID'",
            'remark'          => "TEXT NOT NULL COMMENT '备注信息'",
            'enable'          => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用'",
            'backgroundColor' => "VARCHAR(20) NOT NULL DEFAULT '#FAFAFA' COMMENT '背景颜色'",
            'maskColor'       => "VARCHAR(20) NOT NULL DEFAULT '#80000000' COMMENT '遮罩颜色'",
            'title'           => "VARCHAR(100) NOT NULL COMMENT '标题'",
            'message'         => "TEXT NOT NULL COMMENT '弹窗信息'",
            'hint'            => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '输入框提示内容'",
            'lock'            => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '弹窗保护,0代表不开启,1代表开启,开启后弹窗将不可被隐藏,且弹出的时候会锁定activity不可操作'",
            'autopost'        => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '自动提交,0代表不开启,1代表开启,开启后如果缓存中有内容,则会执行一次自动提交'"
        ]);
        addForeignKeyIfNotExist($pdo, $inputPopupTable, 'config_id', $configTable, 'id');
        
        // --------------------
        // 28. 输入框弹窗按钮表
        // --------------------
        $inputButtonTable = $tablePrefix . 'popup_input_button';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$inputButtonTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $inputButtonTable, [
            'popup_id'        => "INT NOT NULL COMMENT '输入框弹窗ID'",
            'title'           => "VARCHAR(100) NOT NULL COMMENT '按钮标题'",
            'textcolor'       => "VARCHAR(20) NOT NULL DEFAULT '#FFFFFF' COMMENT '文字颜色'",
            'backgroundColor' => "VARCHAR(20) NOT NULL DEFAULT '#008577' COMMENT '背景颜色'",
            'click'           => "INT NOT NULL DEFAULT 0 COMMENT '点击事件ID'",
            'clickText'       => "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '点击事件参数文本'",
            'dismiss'         => "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '点击后关闭弹窗'"
        ]);
        addForeignKeyIfNotExist($pdo, $inputButtonTable, 'popup_id', $inputPopupTable, 'id');
        
        // --------------------
        // 29. 输入框弹窗白名单表
        // --------------------
        $inputWhitelistTable = $tablePrefix . 'popup_input_whitelist';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$inputWhitelistTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $inputWhitelistTable, [
            'popup_id'     => "INT NOT NULL COMMENT '输入框弹窗ID'",
            'class_name'   => "VARCHAR(200) NOT NULL COMMENT '类名'",
            'remark'       => "VARCHAR(255) DEFAULT NULL COMMENT '备注'",
            'created_at'   => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $inputWhitelistTable, 'popup_id', $inputPopupTable, 'id');
        
        // --------------------
        // 30. 输入框弹窗黑名单表
        // --------------------
        $inputBlacklistTable = $tablePrefix . 'popup_input_blacklist';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$inputBlacklistTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $inputBlacklistTable, [
            'popup_id'     => "INT NOT NULL COMMENT '输入框弹窗ID'",
            'class_name'   => "VARCHAR(200) NOT NULL COMMENT '类名'",
            'remark'       => "VARCHAR(255) DEFAULT NULL COMMENT '备注'",
            'created_at'   => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $inputBlacklistTable, 'popup_id', $inputPopupTable, 'id');

        // --------------------
        // 31. 请求统计表
        // --------------------
        $requestStatTable = $tablePrefix . 'request_stat';
        $apkTable         = $tablePrefix . 'apk';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$requestStatTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $requestStatTable, [
            'apk_id'        => "INT NOT NULL COMMENT '应用ID'",
            'device_id'     => "VARCHAR(100) NOT NULL COMMENT '设备ID'",
            'visit_count'   => "INT NOT NULL DEFAULT 0 COMMENT '今日访问次数'",
            'visit_time'    => "DATETIME NOT NULL COMMENT '访问时间'",
            'ip_address'    => "VARCHAR(45) NOT NULL COMMENT '访问IP'",
            'country'       => "VARCHAR(255) DEFAULT NULL COMMENT '归属地国家'",
            'region'        => "VARCHAR(255) DEFAULT NULL COMMENT '归属地省份'",
            'city'          => "VARCHAR(255) DEFAULT NULL COMMENT '归属地城市'",
            'isp'           => "VARCHAR(255) DEFAULT NULL COMMENT '网络运营商'",
            'dns_ip'        => "VARCHAR(45) DEFAULT NULL COMMENT '该用户所在地区解析出来的服务器IP,可用于查看是否被DNS劫持'"
        ]);
        
        addForeignKeyIfNotExist($pdo, $requestStatTable, 'apk_id', $apkTable, 'id');
        //addIndexIfNotExist($pdo, $requestStatTable, 'idx_apk_device_time', '`apk_id`, `device_id`, `visit_time`');//20251224删除
        // JOIN + 时间范围（最重要）
        addIndexIfNotExist($pdo, $requestStatTable, 'idx_apk_time', '`apk_id`, `visit_time`');
        // IP 去重统计
        addIndexIfNotExist($pdo, $requestStatTable, 'idx_apk_time_ip', '`apk_id`, `visit_time`, `ip_address`');
        // Device 去重统计
        addIndexIfNotExist($pdo, $requestStatTable, 'idx_apk_time_device', '`apk_id`, `visit_time`, `device_id`');
        // region 聚合主索引
        addIndexIfNotExist(
            $pdo,
            $requestStatTable,
            'idx_apk_time_region',
            '`apk_id`, `visit_time`, `region`'
        );
        // region + device 去重
        addIndexIfNotExist(
            $pdo,
            $requestStatTable,
            'idx_apk_time_region_device',
            '`apk_id`, `visit_time`, `region`, `device_id`'
        );
        // region + ip 去重
        addIndexIfNotExist(
            $pdo,
            $requestStatTable,
            'idx_apk_time_region_ip',
            '`apk_id`, `visit_time`, `region`, `ip_address`'
        );
        
        
        
        
        //汇总表，该表只记录每日请求的设备总数和IP总数
        $requestStatTable = $tablePrefix . 'request_stat_sum';
        $apkTable         = $tablePrefix . 'apk';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$requestStatTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $requestStatTable, [
            'apk_id'        => "INT NOT NULL COMMENT '应用ID'",
            'device_sum'    => "INT DEFAULT 0 COMMENT '设备总数'",
            'ip_sum'        => "INT DEFAULT 0 COMMENT 'IP总数'",
            'request_sum'   => "INT DEFAULT 0 COMMENT '累计请求总数'",
            'visit_time'    => "DATE NOT NULL COMMENT '统计时间'",
        ]);
        addForeignKeyIfNotExist($pdo, $requestStatTable, 'apk_id', $apkTable, 'id');
        // ================== 检查并创建唯一索引 uk_apk_date ==================
        $indexCheck = $pdo->prepare("SHOW INDEX FROM `$requestStatTable` WHERE Key_name = 'uk_apk_date'");
        $indexCheck->execute();
        if ($indexCheck->rowCount() === 0) {
            $pdo->exec("
                ALTER TABLE `$requestStatTable`
                ADD UNIQUE KEY `uk_apk_date` (`apk_id`,`visit_time`)
            ");
        }
        
        // ================== 检查并创建 visit_time 普通索引 ==================
        $indexCheck = $pdo->prepare("SHOW INDEX FROM `$requestStatTable` WHERE Key_name = 'idx_visit_time'");
        $indexCheck->execute();
        if ($indexCheck->rowCount() === 0) {
            $pdo->exec("
                ALTER TABLE `$requestStatTable`
                ADD KEY `idx_visit_time` (`visit_time`)
            ");
        }
        
        // ================== 检查并创建 apk_id 普通索引 ==================
        $indexCheck = $pdo->prepare("SHOW INDEX FROM `$requestStatTable` WHERE Key_name = 'idx_apk_id'");
        $indexCheck->execute();
        if ($indexCheck->rowCount() === 0) {
            $pdo->exec("
                ALTER TABLE `$requestStatTable`
                ADD KEY `idx_apk_id` (`apk_id`)
            ");
        }
        // --------------------
        // 31. 请求统计表新,将设备和IP访问记录单独一个表存放，方便去重，而无需联合索引
        // --------------------
        $requestStatTable = $tablePrefix . 'request_stat_ip';
        $apkTable         = $tablePrefix . 'apk';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$requestStatTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        addFieldsIfNotExist($pdo, $requestStatTable, [
            'apk_id'        => "INT NOT NULL COMMENT '应用ID'",
            'ip_address'    => "VARCHAR(45) NOT NULL COMMENT '访问IP'",
            'device_id'     => "VARCHAR(100) NOT NULL COMMENT '设备ID'",
            'visit_count'   => "INT NOT NULL DEFAULT 0 COMMENT '今日访问次数'",
            'visit_time'    => "DATETIME NOT NULL COMMENT '访问时间'",
            'visit_date'    => "DATE NOT NULL COMMENT '统计时间'",
            'country'       => "VARCHAR(255) DEFAULT NULL COMMENT '归属地国家'",
            'region'        => "VARCHAR(255) DEFAULT NULL COMMENT '归属地省份'",
            'city'          => "VARCHAR(255) DEFAULT NULL COMMENT '归属地城市'",
            'isp'           => "VARCHAR(255) DEFAULT NULL COMMENT '网络运营商'",
            'dns_ip'        => "VARCHAR(45) DEFAULT NULL COMMENT '该用户所在地区解析出来的服务器IP,可用于查看是否被DNS劫持'"
        ]);
        addForeignKeyIfNotExist($pdo, $requestStatTable, 'apk_id', $apkTable, 'id');
        //addIndexIfNotExist($pdo, $requestStatTable, 'idx_apk_time_ip', '`apk_id`, `ip_address`, `visit_time`');
        // ================== 检查并创建 IP 表唯一索引 ==================
        $indexCheck = $pdo->prepare("SHOW INDEX FROM `$requestStatTable` WHERE Key_name = 'uniq_apk_ip_time'");
        $indexCheck->execute();
        if ($indexCheck->rowCount() === 0) {
            $pdo->exec("
                ALTER TABLE `$requestStatTable`
                ADD UNIQUE KEY `uniq_apk_ip_time` (`apk_id`,`ip_address`,`visit_date`)
            ");
        }
        // ================== 检查并创建 DNS 查询索引 ==================
        $indexCheck = $pdo->prepare("SHOW INDEX FROM `$requestStatTable` WHERE Key_name = 'idx_visit_time_dns'");
        $indexCheck->execute();
        if ($indexCheck->rowCount() === 0) {
            $pdo->exec("
                ALTER TABLE `$requestStatTable`
                ADD INDEX `idx_visit_time_dns` (`visit_time`, `dns_ip`)
            ");
        }
        
        
        
        $requestStatTable = $tablePrefix . 'request_stat_device';
        $apkTable         = $tablePrefix . 'apk';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$requestStatTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $requestStatTable, [
            'apk_id'        => "INT NOT NULL COMMENT '应用ID'",
            'device_id'     => "VARCHAR(100) NOT NULL COMMENT '设备ID'",
            'ip_address'    => "VARCHAR(45) NOT NULL COMMENT '访问IP'",
            'visit_count'   => "INT NOT NULL DEFAULT 0 COMMENT '今日访问次数'",
            'visit_time'    => "DATETIME NOT NULL COMMENT '访问时间'",
            'visit_date'    => "DATE NOT NULL COMMENT '统计时间'",
            'country'       => "VARCHAR(255) DEFAULT NULL COMMENT '归属地国家'",
            'region'        => "VARCHAR(255) DEFAULT NULL COMMENT '归属地省份'",
            'city'          => "VARCHAR(255) DEFAULT NULL COMMENT '归属地城市'",
            'isp'           => "VARCHAR(255) DEFAULT NULL COMMENT '网络运营商'",
            'dns_ip'        => "VARCHAR(45) DEFAULT NULL COMMENT '该用户所在地区解析出来的服务器IP,可用于查看是否被DNS劫持'"
        ]);
        addForeignKeyIfNotExist($pdo, $requestStatTable, 'apk_id', $apkTable, 'id');
        //addIndexIfNotExist($pdo, $requestStatTable, 'idx_apk_time_device', '`apk_id`, `device_id`, `visit_time`');
        // ================== 检查并创建 设备表唯一索引 ==================
        $indexCheck = $pdo->prepare("SHOW INDEX FROM `$requestStatTable` WHERE Key_name = 'uniq_apk_device_time'");
        $indexCheck->execute();
        if ($indexCheck->rowCount() === 0) {
            $pdo->exec("
                ALTER TABLE `$requestStatTable`
                ADD UNIQUE KEY `uniq_apk_device_time` (`apk_id`,`device_id`,`visit_date`)
            ");
        }
        
        
        
        
        
        // --------------------
        // 31. 试用设备统计表
        // --------------------
        $trialTable = $tablePrefix . 'trial';
        $apkTable         = $tablePrefix . 'apk';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$trialTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $trialTable, [
            'apk_id'        => "INT NOT NULL COMMENT '应用ID'",
            'device_id'     => "VARCHAR(100) NOT NULL COMMENT '设备ID'",
            'visit_time'    => "DATETIME NOT NULL COMMENT '首次访问时间'",
        ]);
        
        addForeignKeyIfNotExist($pdo, $trialTable, 'apk_id', $apkTable, 'id');
        
        
        
        // --------------------
        // 31. ws在线设备连接表
        // --------------------
        $requestStatTable = $tablePrefix . 'ws';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$requestStatTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $requestStatTable, [
            'apk_id'        => "INT NOT NULL COMMENT '应用ID'",
            'device_id'     => "VARCHAR(100) NOT NULL COMMENT '设备ID'",
            'visit_time'    => "DATETIME NOT NULL COMMENT '上线时间'",
            'ip_address'    => "VARCHAR(255) NOT NULL COMMENT '用户IP'",
        ]);
        // ws 表索引
        addIndexIfNotExist(
            $pdo,
            $requestStatTable,
            'idx_ws_apk_visit',
            'apk_id, visit_time'
        );
        
        addIndexIfNotExist(
            $pdo,
            $requestStatTable,
            'idx_ws_device_visit',
            'device_id, visit_time'
        );
        
        addIndexIfNotExist(
            $pdo,
            $requestStatTable,
            'idx_ws_device',
            'device_id'
        );

        
        // --------------------
        // 31. 高速下载记录统计表
        // --------------------
        $downTable = $tablePrefix . 'download_record';//高速下载记录表
        $taskTable = $tablePrefix . 'inject_task';//任务表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$downTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        addFieldsIfNotExist($pdo, $downTable, [
            'task_id'       => "INT NOT NULL COMMENT '任务ID'",
            'ip_address'   => "VARCHAR(255) NOT NULL COMMENT 'IP地址'",
            'ip_location'   => "VARCHAR(100) DEFAULT NULL COMMENT 'IP归属地'",
            'size'          => "INT NOT NULL COMMENT '文件大小'",
            'user_agent'    => "VARCHAR(1024) DEFAULT NULL COMMENT 'UA头'",
            'download_time' => "DATETIME NOT NULL COMMENT '下载时间'",
            'source'    => "VARCHAR(1024) DEFAULT NULL COMMENT '下载来源'",
            'file'   => "VARCHAR(1024) DEFAULT NULL COMMENT '文件路径'",
        ]);
        addForeignKeyIfNotExist($pdo, $downTable, 'task_id', $taskTable, 'id');//外键关联
        
        
        // --------------------
        // 31. 应用id重定向表，让一个应用的远程配置完全指向另一个应用，用于恢复被删除的应用
        // --------------------
        $redirectTable = $tablePrefix . 'redirect';//应用id重定向表
        $apkTable         = $tablePrefix . 'apk';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$redirectTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        addFieldsIfNotExist($pdo, $redirectTable, [
            'apk_id1'       => "INT NOT NULL COMMENT '被重定向的应用id'",
            'apk_id2'       => "INT NOT NULL COMMENT '重定向到的应用id'",
            'remark'        => "VARCHAR(100) DEFAULT NULL COMMENT '备注'",
            'created_at'    => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $redirectTable, 'apk_id2', $apkTable, 'id');//外键关联
        
        
        // --------------------
        // 违规应用公示表
        // --------------------
        $violationTable = $tablePrefix . 'violation';
        $userTable     = $tablePrefix . 'user';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$violationTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        addFieldsIfNotExist($pdo, $violationTable, [
            'icon'        => "VARCHAR(255) DEFAULT 'images/android.png' COMMENT '图标'",
            'name'        => "VARCHAR(255) DEFAULT NULL COMMENT '应用名称'",
            'creator_id'  => "INT NOT NULL COMMENT '消息创建人'",
            'uid'         => "INT NOT NULL COMMENT '应用归属人id'",
            'appid'       => "INT NOT NULL COMMENT '应用id'",
            'level'       => "INT NOT NULL COMMENT '违规等级，1严重，2一般，3普通'",
            'package'     => "VARCHAR(255) DEFAULT NULL COMMENT '包名'",
            'reason'      => "VARCHAR(255) DEFAULT NULL COMMENT '违规类型说明'",
            'punishment'  => "VARCHAR(255) DEFAULT NULL COMMENT '处理结果'",
            'time'    => "DATETIME NOT NULL COMMENT '创建时间'"
        ]);
        addForeignKeyIfNotExist($pdo, $violationTable, 'creator_id', $userTable, 'id');
        
        
        // --------------------
        // 32. 权限组表
        // --------------------
        $roleTable = $tablePrefix . 'role';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$roleTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $roleTable, [
            'name'  => "VARCHAR(50) NOT NULL UNIQUE COMMENT '权限组名称'",
            'remark'=> "VARCHAR(200) NOT NULL COMMENT '权限组备注'"
        ]);
        
        // 插入默认权限组（如不存在）
        $defaultRoles = [
            'user'  => '普通用户权限',
            'admin' => '管理员权限'
        ];
        foreach ($defaultRoles as $name => $remark) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$roleTable` WHERE name = :name");
            $stmt->execute([':name' => $name]);
            if ($stmt->fetchColumn() == 0) {
                $insert = $pdo->prepare("INSERT INTO `$roleTable` (`name`, `remark`) VALUES (:name, :remark)");
                $insert->execute([':name' => $name, ':remark' => $remark]);
            }
        }
        
        // --------------------
        // 33. 菜单表
        // --------------------
        $menuTable = $tablePrefix . 'menu';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$menuTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $menuTable, [
            'name'      => "VARCHAR(100) NOT NULL COMMENT '菜单名称'",
            'icon'      => "VARCHAR(50) NOT NULL DEFAULT '' COMMENT '菜单图标'",
            'path'      => "VARCHAR(200) NOT NULL DEFAULT '' COMMENT '页面路径'",
            'hidden'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否隐藏'",
            'parent_id' => "INT DEFAULT NULL COMMENT '父级菜单ID（null为顶级）'",
            'role_id'   => "INT NOT NULL COMMENT '权限组ID'"
        ]);
        addForeignKeyIfNotExist($pdo, $menuTable, 'role_id', $roleTable, 'id');


        //插入普通用户菜单
        $menus = [
            '首页' => [
                '_icon' => 'House',
                '_path' => 'dashboard'
            ],
            '应用管理' => [
                '_icon' => 'Grid',
                '我的应用' => 'app/my_apps',
                '注入管理' => 'app/injected',
                '证书管理' => 'app/cert'
            ],
            '应用配置' => [
                '_icon' => 'Setting',
                '全局开关' => 'config/base',
                '弹窗配置' => [
                    '图片弹窗' => 'config/popup/image',
                    '文字弹窗' => 'config/popup/message',
                    '卡密/输入弹窗' => 'config/popup/input',
                    'HTML弹窗(DIY)' => 'config/popup/html'
                ],
                'SP数据' => [
                    '写入劫持' => 'config/sp/put',
                    '读取劫持' => 'config/sp/get',
                    '重写'     => 'config/sp/override'
                ],
                '窗口拦截' => [
                    '通杀拦截' => 'config/window/kill',
                    'activity拦截' => 'config/window/class',
                    'activity替换' => 'config/window/activity',
                    '关键词拦截/替换' => 'config/keyword',
                    '界面/布局修改' => 'config/view'
                ],
                'URI劫持' => 'config/uri',
                '远程dex' => 'config/dex',
                '卡密列表' => 'config/kami',
                '包名检测' => 'config/silent'
            ],
            '应用统计' => [
                '_icon' => 'DataAnalysis',
                '统计详情' => 'stat/detail',
                '用户地理分布' => 'stat/region'
            ],
            '使用教程' => [
                '_icon' => 'QuestionFilled',
                '常见问题' => 'stat/problem'
            ]
        ];
        // 获取 user 权限组 ID
        $stmt = $pdo->prepare("SELECT id FROM `$roleTable` WHERE name = 'user'");
        $stmt->execute();
        $userRoleId = $stmt->fetchColumn();
        insertMenuRecursive($pdo, $menuTable, $menus, $userRoleId);
        
        
        // 插入管理员菜单
        $adminMenus = [
            '模板管理' => [
                '_icon' => 'Grid',
                '壳模板管理' => 'app/shell',
                'HTML模板管理' => 'app/template_html'
            ],
            '服务管理' => [
                '_icon' => 'Cpu',
                '注入服务' => 'admin/service',
                '推送服务' => 'admin/ws'
            ],
            '文件管理' => [
                '_icon' => 'Document',
                '已编译文件管理' => 'admin/release',
                '孤儿文件清理' => 'admin/useless',
                '应用底包库' => 'admin/appstore',
            ],
            '违规特征' => [
                '_icon' => 'Warning',
                '违规应用管理' => 'admin/user_apps',
                '违规公示管理' => 'admin/violation',
                '应用重定向' => 'admin/redirect',
                '禁止上传的应用' => 'admin/blackpkg',
                '加固特征检测' => 'admin/jiagu',
                '可疑注入特征' => 'admin/invoke'
            ],
            '用户管理' => [
                '_icon' => 'User',
                '用户列表' => 'admin/users',
                '运营商DNS劫持' => 'admin/dns'
            ],
            '系统设置' => [
                '_icon' => 'Tools',
                '系统信息' => 'admin/system',
                '安卓客户端' => 'admin/android'
            ],
        ];
        
        
        // 获取 admin 权限组 ID
        $stmt = $pdo->prepare("SELECT id FROM `$roleTable` WHERE name = 'admin'");
        $stmt->execute();
        $adminRoleId = $stmt->fetchColumn();
        
        // 插入 admin 菜单
        insertMenuRecursive($pdo, $menuTable, $adminMenus, $adminRoleId);
                
        
        $settingTable = $tablePrefix . 'system_setting';
        
        // 创建表（如果不存在）
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$settingTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // 字段定义
        $settingFields = [
            'key_name'   => "VARCHAR(100) NOT NULL UNIQUE COMMENT '键名（英文标识）'",
            'key_value'  => "TEXT NOT NULL COMMENT '键值内容'",
            'title'      => "VARCHAR(100) NOT NULL COMMENT '中文标题'",
            'note'       => "VARCHAR(255) DEFAULT NULL COMMENT '中文备注'",
            'type'       => "VARCHAR(255) DEFAULT NULL COMMENT '控件类型'"
        ];
        addFieldsIfNotExist($pdo, $settingTable, $settingFields);
        
        // 预设数据
        $defaultSettings = [
            'serviceip' => [
                'key_value' => '',
                'title'     => '服务器公网IP',
                'note'      => '如果壳解析IP与此不匹配,则会视为被DNS劫持,会显示在DNS劫持功能数据中',
                'type'      => 'edit'
            ],
            'cache' => [
                'key_value' => '60',
                'title'     => '缓存',
                'note'      => 'APP请求远程配置时的缓存有效时长',
                'type'      => 'edit'
            ],
            'ascii' => [
                'key_value' => '0.4',
                'title'     => '混淆检测强度',
                'note'      => '推荐值0.4,如果用户上传的APK中,乱码长度超过这个比例,则视为乱码混淆,取值0.1-1',
                'type'      => 'edit'
            ],
            'excludeip' => [
                'key_value' => '',
                'title'     => '排除统计IP',
                'note'      => '这些IP发起的壳配置访问不会纳入请求统计,每个IP用英文逗号分隔',
                'type'      => 'edit'
            ],
            'debugip' => [
                'key_value' => '',
                'title'     => '调试设备IP',
                'note'      => '这些IP获取远程配置时将会自动启用调试模式悬浮窗,推荐设置云调试机的IP,每个IP用英文逗号分隔',
                'type'      => 'edit'
            ],
            'xmx' => [
                'key_value' => '512M',
                'title'     => '服务内存分配',
                'note'      => 'APKTOOL回编译时候的内存分配,后面必须带M单位,示例：512M 1024M 2048M,根据自己的服务器内存而定',
                'type'      => 'edit'
            ],
            'inject' => [
                'key_value' => '10',
                'title'     => '单日任务上限',
                'note'      => '每个用户，每天同时能创建的注入任务上限，达到上限后要删除已有任务才能开始新的注入',
                'type'      => 'edit'
            ],
            'maxfile' => [
                'key_value' => '150',
                'title'     => '最大上传文件',
                'note'      => '单位M(兆)，填数字即可，单个文件允许的最大限制',
                'type'      => 'edit'
            ],
            'storage' => [
                'key_value' => '512',
                'title'     => '单用户文件空间',
                'note'      => '单位M(兆)，填数字即可，单个用户允许储存的文件总量，防止用户把本系统当网盘存东西',
                'type'      => 'edit'
            ],
            'vipmaxfile' => [
                'key_value' => '256',
                'title'     => 'VIP用户最大上传文件',
                'note'      => '单位M(兆)，填数字即可，单个文件允许的最大限制',
                'type'      => 'edit'
            ],
            'vipstorage' => [
                'key_value' => '5120',
                'title'     => 'VIP用户文件空间',
                'note'      => '单位M(兆)，填数字即可，VIP用户的可用上传空间大小',
                'type'      => 'edit'
            ],
            'app_count' => [
                'key_value' => '0',
                'title'     => '单用户最大应用数',
                'note'      => '若设置为0，则使用账号自身的规则，若不为0，则和用户规则比对，哪个大用哪个',
                'type'      => 'edit'
            ],
            'vipprice' => [
                'key_value' => '10',
                'title'     => 'VIP续费价格',
                'note'      => '单位元，会从账户余额里扣除',
                'type'      => 'edit'
            ],
            'superiorm' => [
                'key_value' => '0',
                'title'     => '开会员奖励邀请人余额',
                'note'      => '单位分，1元=100分，当用户使用余额开通会员后，奖励邀请人一定的余额，奖励*月数',
                'type'      => 'edit'
            ],
            'superiorv' => [
                'key_value' => '0',
                'title'     => '开会员奖励邀请人会员',
                'note'      => '单位分钟，1天=1440分钟，当用户使用余额开通会员后，奖励邀请人一定的会员时长，奖励*月数',
                'type'      => 'edit'
            ],
            'viptime' => [
                'key_value' => '0',
                'title'     => '注册赠送会员时长',
                'note'      => '单位小时,以方便用户测试大文件上传',
                'type'      => 'edit'
            ],
            'shell' => [
                'key_value' => '',
                'title'     => '兜底壳配置id',
                'note'      => '如果被注入的应用已经被删除，则该应用会获取这里设置的应用id的远程配置,留空则不设置',
                'type'      => 'edit'
            ],
            'shellName' => [
                'key_value' => 'com.example.shell.App',
                'title'     => '默认壳入口类名',
                'note'      => '每个壳上传的时候都可以指定入口,如果不指定,则上传的时候会使用这个类名,修改此处不会影响已上传的壳模板入口',
                'type'      => 'edit'
            ],
            'code' => [
                'key_value' => '0',
                'title'     => '登录验证码',
                'note'      => '关闭后，登录时候可以任意输入验证码',
                'type'      => 'switch'
            ],
            'upload' => [
                'key_value' => '1',
                'title'     => '允许上传文件',
                'note'      => '关闭后，用户不能再上传新的APK文件',
                'type'      => 'switch'
            ],
            'task_in' => [
                'key_value' => '1',
                'title'     => '允许创建注入',
                'note'      => '关闭后，用户不能再创建新的注入任务',
                'type'      => 'switch'
            ],
            'task_vmp' => [
                'key_value' => '1',
                'title'     => '允许创建加固',
                'note'      => '关闭后，用户不能再创建新的加固任务',
                'type'      => 'switch'
            ],
            'jiagu' => [
                'key_value' => '0',
                'title'     => '自定义加固检测规则',
                'note'      => '开启后将使用自定义的加固特征检测规则,系统内置的规则将失效',
                'type'      => 'switch'
            ],
            'uploadday' => [
                'key_value' => '3',
                'title'     => '上传保留天数',
                'note'      => '上传的原始安装包的最长保留天数,超过这个天数会被自动删除安装包',
                'type'      => 'edit'
            ],
            'releaseday' => [
                'key_value' => '3',
                'title'     => '编译保留天数',
                'note'      => '注入的安装包的最长保留天数,超过这个天数会被自动删任务',
                'type'      => 'edit'
            ],
            'vipjiagu' => [
                'key_value' => '0',
                'title'     => '付费使用注入加固',
                'note'      => '开启后只有VIP用户可以使用加固功能',
                'type'      => 'switch'
            ],
            'vmpjiagu' => [
                'key_value' => '0',
                'title'     => '付费使用vmp加固',
                'note'      => '开启后只有VIP用户可以使用加固底包的功能',
                'type'      => 'switch'
            ],
            'html' => [
                'key_value' => '5',
                'title'     => '普通用户HTML窗数量',
                'note'      => '普通用户单个应用可以创建的HTML弹窗数量(防止无限创建html弹窗撑爆数据库)',
                'type'      => 'edit'
            ],
            'viphtml' => [
                'key_value' => '15',
                'title'     => '会员用户HTML窗数量',
                'note'      => '会员用户单个应用可以创建的HTML弹窗数量(防止无限创建html弹窗撑爆数据库)',
                'type'      => 'edit'
            ],
            
            
            //阿里云OSS参数配置
            'ossKeyId' => [
                'key_value' => '',
                'title'     => 'accessKeyId',
                'note'      => 'OSS_accessKeyId',
                'type'      => 'edit'
            ],
            'ossKeySecret' => [
                'key_value' => '',
                'title'     => 'accessKeySecret',
                'note'      => 'OSS_accessKeySecret',
                'type'      => 'edit'
            ],
            'ossendpoint' => [
                'key_value' => '',
                'title'     => '外网地址',
                'note'      => 'OSS_外网地址，例如：oss-cn-guangzhou.aliyuncs.com',
                'type'      => 'edit'
            ],
            'ossinternalEndpoint' => [
                'key_value' => '',
                'title'     => '内网地址',
                'note'      => 'OSS_内网地址，例如：oss-cn-guangzhou-internal.aliyuncs.com',
                'type'      => 'edit'
            ],
            'ossbucket' => [
                'key_value' => '',
                'title'     => '桶名称',
                'note'      => '桶名称，例如：yunzhuru',
                'type'      => 'edit'
            ],
            'ossDomain' => [
                'key_value' => '',
                'title'     => '自定义域名',
                'note'      => '桶名称，例如：https://oss.yunzhuru.cn',
                'type'      => 'edit'
            ],
            
            'ossbackup' => [
                'key_value' => '0',
                'title'     => '上传文件备份到oss',
                'note'      => '开启后用户上传的文件会储存到oss，而不储存到服务器上，请使用和服务器同一内网的oss',
                'type'      => 'switch'
            ],
            
            
            'oss' => [
                'key_value' => '0',
                'title'     => 'oss高速下载通道开关',
                'note'      => '开启后下载的时候走oss高速通道(需配置OSS的相关参数,接口模板位于api/utils/OSS.php)',
                'type'      => 'switch'
            ],
            'ossvip' => [
                'key_value' => '0',
                'title'     => 'oss高速下载通道需要付费',
                'note'      => '开启后只有VIP用户才可以使用oss高速下载通道',
                'type'      => 'switch'
            ],
            'osshigh' => [
                'key_value' => '10',
                'title'     => '单任务高速oss下载数',
                'note'      => '允许每个任务最多可被高速下载多少次，可有效防止一个任务被刷流量',
                'type'      => 'edit'
            ],
            'ossmini' => [
                'key_value' => '10',
                'title'     => 'oss通道最低文件大小',
                'note'      => '单位M，填数字即可，只有超过这个文件体积的，才会走oss通道',
                'type'      => 'edit'
            ],
            'push' => [
                'key_value' => '1',
                'title'     => '仅会员可推送',
                'note'      => '开启后只有会员可以使用push实时推送功能',
                'type'      => 'switch'
            ],
            'diydown' => [
                'key_value' => '0',
                'title'     => '自定义下载服',
                'note'      => '将文件下载服务器和主服务器隔离,用户下载注入后的文件,将通过下载服下载,需要在下载服部署一个转存脚本,下载服和正式服需要在同一内网。主服务器高性能，下载服务器使用共享大带宽，以节省成本，此功能不和oss功能共用,优先使用oss,未开启oss,也未开启自定义下载服,才会从主服务器直接下载',
                'type'      => 'switch'
            ],
            'downurl' => [
                'key_value' => 'https://release.yunzhuru.com/index.php',
                'title'     => '下载服接口',
                'note'      => '通常只更换域名即可,下载服部署的接口文件位于网站根目录的release.php,将其部署到下载服上,这里填写的url不要加自定义get参数',
                'type'      => 'edit'
            ],
        ];
        
        foreach ($defaultSettings as $key => $data) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$settingTable` WHERE `key_name` = :key");
            $stmt->execute([':key' => $key]);
            if ($stmt->fetchColumn() == 0) {
                $insert = $pdo->prepare("INSERT INTO `$settingTable` (`key_name`, `key_value`, `title`, `note`, `type`) 
                                         VALUES (:key, :value, :title, :note, :type)");
                $insert->execute([
                    ':key'   => $key,
                    ':value' => $data['key_value'],
                    ':title' => $data['title'],
                    ':note'  => $data['note'],
                    ':type'  => $data['type']
                ]);
            }
        }

        
        
        // --------------------
        //  登录验证码表
        // --------------------
        $requestStatTable = $tablePrefix . 'verify';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$requestStatTable` (
            `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '自增主键'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        addFieldsIfNotExist($pdo, $requestStatTable, [
            'code'     => "VARCHAR(100) NOT NULL COMMENT '生成的验证码'",
            'time'     => "DATETIME NOT NULL COMMENT '生成时间'",
            'ip_address'    => "VARCHAR(45) NOT NULL COMMENT 'IP'"
        ]);
        
        
        
        
        
        
        
        
        // 读取 JSON 输入
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        

        // 插入管理员账号
        $adminAccount = trim($data['adminUser']);
        $adminPassword = trim($data['adminPass']);
        $userTable = $tablePrefix . 'user';
        
        // 检查是否已有管理员存在
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$userTable` WHERE role = 'admin'");
        $adminCount = $stmt->fetchColumn();
        
        if ($adminCount == 0) {
            // 检查账号是否已存在（防止用户自定义的 adminUser 和已有账号重复）
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$userTable` WHERE account = :account");
            $stmt->execute([':account' => $adminAccount]);
        
            if ($stmt->fetchColumn() == 0) {
                $insert = $pdo->prepare("INSERT INTO `$userTable` 
                    (account, password, role, register_time) 
                    VALUES (:account, :password, 'admin', NOW())");
        
                $insert->execute([
                    ':account'  => $adminAccount,
                    ':password' => password_hash($adminPassword, PASSWORD_DEFAULT)
                ]);
            }
        }

        
        
        // 生成数据库配置文件路径
        $configDir  = __DIR__ . '/../config';
        $configFile = $configDir . '/config.php';
        
        // 数据库配置信息
        $configArray = [
            'host'     => $data['dbHost'],
            'dbname'   => $data['dbName'],
            'username' => $data['dbUser'],
            'password' => $data['dbPass'],
            'charset'  => 'utf8mb4'
        ];
        
        // 构建 PHP 配置文件内容
        $configContent = "<?php\nreturn " . var_export($configArray, true) . ";\n";
        
        // 写入配置文件
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        file_put_contents($configFile, $configContent);
        $lockFile = $configDir . '/config.lock';
        file_put_contents($lockFile, 'installed:' . date('Y-m-d H:i:s'));
        $pdo->commit();
        return ['code' => 200, 'message' => '数据库结构安装成功'];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['code' => 500, 'message' => '安装失败：' . $e->getMessage()];
    }
}



// 递归插入菜单
function insertMenuRecursive(PDO $pdo, $table, $data, $roleId, $parentId = null) {
    foreach ($data as $name => $value) {
        if (strpos($name, '_') === 0) continue;
        $icon = $value['_icon'] ?? '';
        $path = is_array($value) ? ($value['_path'] ?? '') : $value;
        // 判断是否已存在
        $check = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE name = :name AND role_id = :role_id AND parent_id " . ($parentId ? '= :parent_id' : 'IS NULL'));
                $params = ['name' => $name, 'role_id' => $roleId];
        if ($parentId) $params['parent_id'] = $parentId;
        $check->execute($params);
        if ($check->fetchColumn() == 0) {
            // 插入
           $insert = $pdo->prepare("INSERT INTO `$table` (`name`, `icon`, `path`, `parent_id`, `role_id`) VALUES (:name, :icon, :path, :parent_id, :role_id)");
            $insert->execute([
                'name'      => $name,
                'icon'      => $icon ?? '',
                'path'      => $path ?? '',
                'parent_id'=> $parentId,
                   'role_id'  => $roleId
            ]);
            $newId = $pdo->lastInsertId();
        } else {
            // 获取已有 ID 继续递归
            $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE name = :name AND role_id = :role_id AND parent_id " . ($parentId ? '= :parent_id' : 'IS NULL'));
            $params = ['name' => $name, 'role_id' => $roleId];
            if ($parentId) $params['parent_id'] = $parentId;
            $stmt->execute($params);
            $newId = $stmt->fetchColumn();
        }
        if (is_array($value)) {
            insertMenuRecursive($pdo, $table, $value, $roleId, $newId);
        }
    }
}

/**
 * 添加字段（如果不存在）
 */
function addFieldsIfNotExist(PDO $pdo, string $table, array $fields)
{
    foreach ($fields as $field => $definition) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :field");
        $stmt->execute([':field' => $field]);
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD `$field` $definition");
        }
    }
}

/**
 * 添加外键（如果不存在）
 */
function addForeignKeyIfNotExist(PDO $pdo, string $table, string $column, string $refTable, string $refColumn)
{
    $constraintName = "fk_{$table}_{$column}";
    $exists = $pdo->prepare("
        SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_NAME = :table AND COLUMN_NAME = :column AND CONSTRAINT_NAME = :name
    ");
    $exists->execute([
        ':table' => $table,
        ':column' => $column,
        ':name' => $constraintName
    ]);

    if ($exists->rowCount() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$constraintName` FOREIGN KEY (`$column`) REFERENCES `$refTable`(`$refColumn`) ON DELETE CASCADE");
    }
}
function indexExists(PDO $pdo, string $table, string $indexName): bool {
    $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = :key");
    $stmt->execute([':key' => $indexName]);
    return (bool)$stmt->fetch();
}

function addIndexIfNotExist(PDO $pdo, string $table, string $indexName, string $indexDefinition): void {
    if (!indexExists($pdo, $table, $indexName)) {
        $pdo->exec("CREATE INDEX `$indexName` ON `$table` ($indexDefinition)");
    }
}

/*function addIndexIfNotExist(
    PDO $pdo,
    string $table,
    string $indexName,
    string $indexDefinition
): void {
    if (indexExists($pdo, $table, $indexName)) {
        return;
    }

    try {
        $sql = "CREATE INDEX `$indexName` ON `$table` ($indexDefinition)
                ALGORITHM=INPLACE, LOCK=NONE";
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // 安装并发或重复执行时允许忽略
        if (stripos($e->getMessage(), 'Duplicate key name') === false) {
            throw $e;
        }
    }
}
*/
