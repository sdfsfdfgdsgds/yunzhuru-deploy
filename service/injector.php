<?php

/**
 * 注入逻辑核心入口
 * @param PDO $pdo 已连接的 PDO 实例
 */
function handleInjectionTasks(PDO $pdo, $oss)
{
    putenv("LC_ALL=en_US.UTF-8");
    putenv("LANG=en_US.UTF-8");
    setlocale(LC_ALL, 'en_US.UTF-8');

    // ===== 优先处理"等待下载"的 URL 注入任务 =====
    handleUrlDownloadTasks($pdo);

    // 查询等待处理任务（按创建时间优先）
    $stmt = $pdo->prepare("
        SELECT 
            t.*, 
            a.path AS apk_path, 
            a.osspath AS apk_osspath, 
            a.size AS apk_size,
            a.domain_mode, 
            a.name, 
            a.package, 
            a.sign, 
            a.user_id AS apk_user_id,
            a.custom_domains, 
            s.path AS sign_path, 
            s.alias, 
            s.password AS storepass, 
            s.cert_password AS keypass, 
            tmp.path AS shell_path,
            tmp.className,
            u.vip_expire_time
        FROM cainiao_inject_task t
        LEFT JOIN cainiao_apk a ON t.apk_id = a.id
        LEFT JOIN cainiao_sign s ON t.sign_id = s.id
        LEFT JOIN cainiao_template tmp ON t.template_id = tmp.id
        LEFT JOIN cainiao_user u ON u.id = a.user_id
        WHERE t.status_text = '等待处理'
        ORDER BY t.created_at ASC
        LIMIT 1
    ");
    $stmt->execute();
    $task = $stmt->fetch(PDO::FETCH_ASSOC);// AND t.user_id = 1
    
    if (!$task) {
        return;
    }
    

    // 检查必要文件路径
    $aapt =  __DIR__ . '/../bin/aapt';//aapt
    $ApkDataMultiplexing = __DIR__ . '/../bin/ApkDataMultiplexing.jar';//数据复用优化并签名
    $apksigner_jar = __DIR__ . '/../bin/apksigner.jar';//签名工具
    $apktool_jar = __DIR__ . '/../bin/apktool_2.11.1.jar';//apktool
    $AXMLPrinter2 = __DIR__ . '/../bin/AXMLPrinter2.jar';//xml反编译器，主要作用是反编译拿APP入口
    //新版baksmali
    $baksmali =  __DIR__ . '/../bin/baksmali-2.5.2-dev-fat.jar';//dex转smali
    $Editor = __DIR__ . '/../bin/ManifestEditor-2.0.jar';//xml修改器
    $smali =  __DIR__ . '/../bin/smali-2.5.2-dev-fat.jar';//smali转dex
    //$dexedit =   __DIR__ . '/../bin/DexEdit1.1.1.jar';//dex编辑库 DexEdit-2.5.2-dev.jar
    $dexedit =   __DIR__ . '/../bin/DexEdit-2.5.2-dev.jar';//dex编辑库 
    $jiagujar =  __DIR__ . '/../bin/executable/dpt.jar';//luoyesiqiu加固库
    $xml2axml = __DIR__ . '/../bin/xml2axml-2.0.1.jar';//xml和axml互相转换的库
    echo "=====================================================================\n";
    if(!file_exists(__DIR__ . '/../uploads/' . $task['apk_path']) && !empty($task['apk_osspath'])){
        /*if(empty($task['apk_osspath'])){
            echo "原始APK包不存在OSS\n";
            updateTaskStatus($pdo, $task['id'], '任务失败');
            updateTaskInfo($pdo, $task['id'], "应用安装包不存在,请先重传安装包");
            return;
        }*/
        //代表该应用安装包存在在oss中的，需要从oss中拉取
        echo "该应用安装包在OSS服务器,准备拉取原始安装包\n";
        $ossPath = $task['apk_osspath'];
        // 原始文件名
        $fileName = basename($ossPath);
        // 系统临时目录
        $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        // 你的统一缓存子目录（自行定名）
        $appTmpDir = $tmpDir . DIRECTORY_SEPARATOR . 'apk_cache_oss_downloads';
        // 确保目录存在
        if (!is_dir($appTmpDir)) {
            mkdir($appTmpDir, 0755, true);
        }
        // 最终本地缓存路径
        $localSavePath = $appTmpDir . DIRECTORY_SEPARATOR . $fileName;
        
        // 拉取文件
        $ossResult = $oss->downloadToLocal($ossPath, $localSavePath);
        
        echo "拉取状态码：" . $ossResult['code'] . "\n";
        echo "拉取结果：" . $ossResult['message'] . "\n";
        echo "拉取存放路径：" . ($ossResult['local_path'] ?? '') . "\n";
        
    }
    
    //转换为真实路径，在这之前，要检查原始文件储存位置，如果在oss端，要先拉取到缓存目录中
    if($ossResult['code'] == 200 && file_exists($localSavePath)){
        $apk_file = [
            realpath(__DIR__ . '/../templates/' . $task['shell_path']),
            realpath($localSavePath)
        ];
        $oss_temp = true;//代表是从oss端拉的，后续根据这个值决定，要不要删除
    }else{
        $apk_file = [
            realpath(__DIR__ . '/../templates/' . $task['shell_path']),
            realpath(__DIR__ . '/../uploads/' . $task['apk_path'])
        ];
        $oss_temp = false;//代表是在本地的
    }
    
    
    $keystore = realpath(__DIR__ . '/../signfile/' . $task['sign_path']);
    $alias     = $task['alias'];
    $storepass = $task['storepass'];
    $keypass   = $task['keypass'];

    // 随机签名：sign_id 为 'random' 或 sign_path 为空时自动生成
    $randomKeystoreGenerated = false;
    if (empty($task['sign_path']) || $task['sign_id'] === 'random') {
        echo "使用随机签名模式\n";
        $tempSignDir = __DIR__ . '/../temp/random_sign_' . uniqid();
        $randomSign = generateRandomKeystore($tempSignDir);
        if ($randomSign) {
            $keystore  = $randomSign['keystore'];
            $alias     = $randomSign['alias'];
            $storepass = $randomSign['storepass'];
            $keypass   = $randomSign['keypass'];
            $randomKeystoreGenerated = true;
        } else {
            echo "随机签名生成失败，回退到默认签名\n";
        }
    }
    $user_id = $task['user_id'];
    $vip_expire_time = $task['vip_expire_time'];
    $mode = $task['mode'];
    $apk_user_id = $task['apk_user_id'];
    $shellClassName = $task['className'];
    if (!empty($vip_expire_time) && strtotime($vip_expire_time) > time()) {
        $isVip = true;
    } else {
        $isVip = false;
    }
    echo "=================================注入前文件预检测====================================\n";
    
    if(empty($task['apk_path'])){
        echo "原始APK包不存在\n";
        updateTaskStatus($pdo, $task['id'], '任务失败');
        updateTaskInfo($pdo, $task['id'], "应用安装包不存在,请先重传安装包");
        return;
    }
    if(empty($apk_file[1])){
        echo "原始APK包不存在\n";
        updateTaskStatus($pdo, $task['id'], '任务失败');
        updateTaskInfo($pdo, $task['id'], "应用安装包不存在,请先重传安装包");
        return;
    }
    /*
    $releasePath = __DIR__ . '/../release/';//编译后的储存目录
    if($task['injected_apk']){
        safeDeleteFile($releasePath . $task['injected_apk']);//清理已编译的文件先
    }*/
    echo "=====================================================================\n";
    updateTaskStatus($pdo, $task['id'], '开始处理');
    $xmx = Auth::getSetting($pdo,"xmx","512M");
    echo "[" . date('Y-m-d H:i:s') . "] xmx内存分配: ". $xmx ."\n";
    echo "[" . date('Y-m-d H:i:s') . "] 获取到待处理任务 ID: {$task['id']}\n";
    echo "壳路径: {$apk_file[0]}\n";
    echo "壳入口: {$shellClassName}\n";
    echo "本地应用路径: {$task['apk_path']}\n";
    echo "OSS应用路径: {$task['apk_osspath']}\n";
    echo "应用路径: {$apk_file[1]}\n";
    echo "签名路径: {$keystore}\n";
    echo "应用名称: {$task['name']}\n";
    echo "应用归属: {$apk_user_id}\n";
    echo "是否是VIP：{$isVip}\n";
    echo "用户会员到期时间：{$vip_expire_time}\n";
    echo "任务归属: {$user_id}\n";
    echo "注入模式: {$mode}\n";
    echo "alias: {$alias}\n";
    echo "storepass: {$storepass}\n";
    echo "keypass: {$keypass}\n";
    $link = $task['inject_to_top'];//true=壳作为最终父链，false=壳继承父链
    
    if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $task['alias'])) {
        echo "证书别名包含中文字符\n";
        updateTaskStatus($pdo, $task['id'], '任务失败');
        updateTaskInfo($pdo, $task['id'], "证书别名包含中文字符，请更换证书");
        del_osstemp($oss_temp, $localSavePath);
        return;
    }
    if(empty($shellClassName)){
        echo "壳入口类名为空";
        updateTaskStatus($pdo, $task['id'], '任务失败');
        updateTaskInfo($pdo, $task['id'], "壳入口为空，此壳不可用，请联系管理员反馈");
        del_osstemp($oss_temp, $localSavePath);
        return;
    }
    echo "==================================不支持的应用检测\n";
    updateTaskInfo($pdo, $task['id'], 'DEX检测');
    //dex数量检测
    $result = isDexCountExceed($apk_file[1], 160);
    if ($result['exceed']) {
        echo "DEX 文件数为 {$result['count']}，超过限制，请先用MT管理器合并dex";
        updateTaskStatus($pdo, $task['id'], '任务失败');
        updateTaskInfo($pdo, $task['id'], "DEX 文件数为 {$result['count']}，超过限制，可能存在加固或混淆");
        del_osstemp($oss_temp, $localSavePath);
        return;
    }
    
    if($result['count'] > 5){
        $task['dexmerge'] = 0;//当dex数量大于5的时候，强制关闭dex合并
    }
    
    echo "==================================加固混淆检测\n";
    updateTaskInfo($pdo, $task['id'], '正在检测加固情况');
    $result = isApkObfuscated($apk_file[1], $pdo);
    updateencry($pdo, $task['id'], $result['message']);
    
    echo "==================================开始反编译\n";
    
    updateTaskInfo($pdo, $task['id'], '正在反编译');
    $temp_dir = create_unique_temp_subdir(realpath(__DIR__ . '/../temp/'));//为每次任务创建一个子目录
    $decompile = decompile_apks($apktool_jar, $apk_file, $temp_dir);//反编译,此时是不反编译res资源和dex的，包括AndroidManifest
    //壳反编译失败
    if(!$decompile[0][0]){
        updateTaskStatus($pdo, $task['id'], '任务失败');
        $errDetail = $decompile[0][1] ?? '未知错误';
        $errOutput = isset($decompile[0][3]) ? substr($decompile[0][3], -200) : '';
        updateTaskInfo($pdo, $task['id'], '解包壳失败: ' . $errDetail . ($errOutput ? ' | ' . $errOutput : ''));
        safeDeleteDirectory($temp_dir);
        del_osstemp($oss_temp, $localSavePath);
        return;
    }
    //目标APP反编译失败
    if(!$decompile[1][0]){
        updateTaskStatus($pdo, $task['id'], '任务失败');
        $errDetail2 = $decompile[1][1] ?? '未知错误';
        $errOutput2 = isset($decompile[1][3]) ? substr($decompile[1][3], -200) : '';
        updateTaskInfo($pdo, $task['id'], '解包目标应用失败: ' . $errDetail2 . ($errOutput2 ? ' | ' . $errOutput2 : ''));
        safeDeleteDirectory($temp_dir);
        del_osstemp($oss_temp, $localSavePath);
        return;
    }
    
    $de_apk1 = $decompile[0][2];//反编译后的壳目录
    $de_apk2 = $decompile[1][2];//反编译后的应用目录
    
    /*echo "==================================开始注入so库,复制lib目录dex\n";
    $result = overwriteLib($de_apk2, $de_apk1);
    if(!$result){
        echo "注入so库失败\n";
        updateTaskStatus($pdo, $task['id'], '注入失败');
        updateTaskInfo($pdo, $task['id'], '注入so库失败');
        safeDeleteDirectory($temp_dir);
        return;
    }
    echo "so库注入成功\n";*/
    
    
    echo "==================================检查是否注入过本壳\n";
    updateTaskInfo($pdo, $task['id'], '正在检测注入情况');
    /*$found = find_class_in_dex($de_apk2, $shellClassName);//
    if ($found !== false) {
        echo "该APP已经被本平台注入过\n";
        updateTaskStatus($pdo, $task['id'], '任务失败');
        updateTaskInfo($pdo, $task['id'], '该APP已经被本平台注入过');
        safeDeleteDirectory($temp_dir);
        return;
    }*/
    //$result = dexedit_ac($dexedit, $xmx, $apk_file[1], $shellClassName);//使用固定类名检测
    $result = dexedit_ac($dexedit, $xmx, $apk_file[1], 'com.shadow.okhttp3.OkHttp');//使用影子包做特征检查
    if($result !== false){
        if(stripos($result, 'Dex version 040 is not supported') !== false){
            echo "dexedit执行出错：{$result}\n";
            updateTaskStatus($pdo, $task['id'], '任务失败');
            updateTaskInfo($pdo, $task['id'], '不支持的DEX文件,请先用MT管理器将dex修复为035-039版本');
            safeDeleteDirectory($temp_dir);
            del_osstemp($oss_temp, $localSavePath);
            return;
        }
            
        
        echo "该APP已经被本平台注入过\n";
        updateTaskStatus($pdo, $task['id'], '任务失败');
        updateTaskInfo($pdo, $task['id'], '该APP已经被注入过,请重新上传未注入过的底包');
        safeDeleteDirectory($temp_dir);
        del_osstemp($oss_temp, $localSavePath);
        return;
    }else{
        echo "该APP未被{$shellClassName}台注入过\n";
    }
    echo "==================================准备混淆类名所需的参数\n";
    updateTaskInfo($pdo, $task['id'], '正在混淆类名');
    $GLOBALS['App']                      = 'App';
    $GLOBALS['MainActivity']             = 'MainActivity';
    $GLOBALS['ShellAppComponentFactory'] = 'ShellAppComponentFactory';
    $GLOBALS['Config']                   = 'Config';
    
    echo "==================================开始混淆类名\n";
//if($task['user_id'] == 1){
    $parts = explode('.', $shellClassName);
    array_pop($parts); // 去掉类名
    $final_package = implode('.', $parts);
    $dexFile = rtrim($de_apk1, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'classes.dex';
    
    
    
    //生成新的类名
    $GLOBALS['App']                      = generateRandomString(10,20);
    $GLOBALS['MainActivity']             = generateRandomString(10,20);
    $GLOBALS['ShellAppComponentFactory'] = generateRandomString(10,20);
    $GLOBALS['Config']                   = generateRandomString(10,20);
    
    echo "类名混淆结果：" .  dexedit_rc($dexedit, $xmx, $dexFile, $final_package.'.App',                      $final_package. "." .$GLOBALS['App'], $dexFile) . "\n";
    echo "类名混淆结果：" .  dexedit_rc($dexedit, $xmx, $dexFile, $final_package.'.MainActivity',             $final_package. "." .$GLOBALS['MainActivity'], $dexFile) . "\n";
    echo "类名混淆结果：" .  dexedit_rc($dexedit, $xmx, $dexFile, $final_package.'.ShellAppComponentFactory', $final_package. "." .$GLOBALS['ShellAppComponentFactory'], $dexFile) . "\n";
    echo "类名混淆结果：" .  dexedit_rc($dexedit, $xmx, $dexFile, $final_package.'.Config',                   $final_package. "." .$GLOBALS['Config'], $dexFile) . "\n";
    $shellClassName = $final_package.'.'.$GLOBALS['App'];//更换入口类名为混淆后的类名
    
//}
    
    
    echo "==================================开始修改入口包名\n";
    updateTaskInfo($pdo, $task['id'], '正在混淆包名');
        echo "开始修改入口包名\n";
        $dexFile = rtrim($de_apk1, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'classes.dex';

        $charPool = [
            'letters1' => buildArabicLetterCache(100),
            'words1' => buildArabicLetterCache(100),
            // 单字符池：字母 + 数字
            'letters' => [
                // 小写字母 a-z
                'a','b','c','d','e','f','g','h','i','j','k','l','m',
                'n','o','p','q','r','s','t','u','v','w','x','y','z',
        
                // 大写字母 A-Z
                'A','B','C','D','E','F','G','H','I','J','K','L','M',
                'N','O','P','Q','R','S','T','U','V','W','X','Y','Z',
        
                // 数字 0-9
                '0','1','2','3','4','5','6','7','8','9',
            ],
            // 词汇池：整体使用（中文 / 英文词）
            'words' => [
                '你好','哈哈','世界', '操你妈','傻逼','看你妈呢', '小学生','滚犊子','傻狗','呆逼','快点叫爸爸',
                // 品牌 / 项目相关
                'yun','zhu','ru',
                'dev','demo','sample','test',
            
                // 通用应用层
                'app','apps','application','client',
                'core','base','common','shared',
                'cloud','platform','engine','framework',
            
                // 安卓 / 系统
                'android','mobile','system','sys','os',
                'service','services','provider','receiver',
                'activity','fragment','view','widget',
            
                // 架构 / 模块
                'ui','ux','kit','sdk',
                'api','apis','net','network',
                'data','db','database','storage',
                'repo','repository','model','entity',
                'vm','viewmodel','mvvm','mvp','mvc',
            
                // 功能方向
                'auth','login','account','user','profile',
                'pay','payment','order','trade',
                'push','notify','notification',
                'map','location','gps',
                'media','video','audio','player',
                'image','camera','gallery',
            
                // 工具 / 支撑
                'util','utils','helper','tools',
                'log','logger','debug','trace',
                'config','setting','prefs','cache',
                'security','secure','safe','crypto',
            
                // 版本 / 形态
                'plus','pro','max','lite','mini',
                'free','vip','premium',
            
                // 第三方 / 扩展常见
                'third','thirdparty','plugin','extension',
                'bridge','channel','adapter'
            ]

        ];
        $segmentCount = rand(2,5); 
        $segmentLength = rand(2,5);
        //$segmentCount = 1; 
        //$segmentLength = 1;
        //$newPackage = '看你妈逼.臭傻逼.滚';//新包名
        $newPackage = generateRandomPackageName($charPool, $segmentCount, $segmentLength);//生成随机包名
        //$newPackage = maybeAddCommonAndroidPrefix(generateRandomPackageName($charPool, $segmentCount, $segmentLength));//生成随机包名+随机系统包前缀
        $newPackage = $task['package'];//使用APP自身的包名

        $oldClassName = $shellClassName;// 原完整类名，例如 com.dev.demo.shell.MyApplication
        $className = substr($oldClassName, strrpos($oldClassName, '.') + 1);//拆出类名MyApplication
        
        // 生成新的完整类名
        $newClassName = $newPackage . '.' . $className;

        //如有未开启加强去签的，才能混淆包名，否则so库会因为找不到包名方法而闪退
        $rpk = dexedit_rpk($dexedit, $xmx, $dexFile, $shellClassName, $newPackage, $dexFile);//先修改入口所在的类
        
        //再修改固定包名，由于该方法会自动删掉类名，所以这里传入要带类名
        $rpk = dexedit_rpk($dexedit, $xmx, $dexFile, 'com.example.shell.App', generateRandomPackageName($charPool, $segmentCount, $segmentLength), $dexFile);
        $rpk = dexedit_rpk($dexedit, $xmx, $dexFile, 'org.lsposed.hiddenapibypass.HiddenApiBypass', generateRandomPackageName($charPool, $segmentCount, $segmentLength), $dexFile);

        echo $rpk . "\n";
        
        if($rpk == "true\n"){
            $shellClassName = $newClassName;
            $final_package = $newPackage;//最终包名
            echo "包名修改成功\n";
        }else{
            echo "包名修改失败\n";
            if (strpos($shellClassName, '.') !== false) {
                $parts = explode('.', $shellClassName);
                array_pop($parts); // 去掉类名
                $oldPackage = implode('.', $parts);
            } else {
                // 理论上不会出现，没有点就原样用
                $oldPackage = $oldPackageWithClass;
            }
            $final_package = $oldPackage;
        }
    
    
    
    
    
    
    
    
    echo "==================================开始反编译壳dex\n";
    updateTaskInfo($pdo, $task['id'], '反编译壳');
    $result = dexToSmali($baksmali, $de_apk1);
    if ($result) {
        echo "反编译成功\n";
    } else {
        updateTaskStatus($pdo, $task['id'], '任务失败');
        updateTaskInfo($pdo, $task['id'], '反编译注入壳失败');
        safeDeleteDirectory($temp_dir);
        del_osstemp($oss_temp, $localSavePath);
        return;
    }
    echo "==================================去除引流分享界面\n";
    if(!$task['kill_Inject']){
        echo "该任务未选择去除云注入\n";
    }else{
        $setupLauncher = setupLauncherByMetaDataWithXml2Axml($xml2axml,$de_apk2);
    }
    
    echo "==================================插入xml\n";
    if ($task['launcher']) {
        updateTaskInfo($pdo, $task['id'], '注入开屏窗口');
        // ① 先移除原有 LAUNCHER
        $removedActivity = removeLauncherCategoryWithXml2Axml(
            $xml2axml,
            $de_apk2
        );
    
        if ($removedActivity === false) {
            echo "删除原 LAUNCHER 失败\n";
            updateTaskStatus($pdo, $task['id'], '注入失败');
            updateTaskInfo($pdo, $task['id'], '该应用不支持开屏窗口替换,错误01,请不要勾选开屏窗口替换功能');
            safeDeleteDirectory($temp_dir);
            del_osstemp($oss_temp, $localSavePath);
            return;
        }
        
        echo "已删除原 LAUNCHER Activity：{$removedActivity}\n";
    
        // ② 注入新的 LAUNCHER Activity
        $activityXml = '<activity
            android:name="' . $final_package . '.' .$GLOBALS['MainActivity'] .'"
            android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>';
    
        $result = injectActivityWithXml2Axml(
            $xml2axml,
            $de_apk2,
            $activityXml
        );
    
        if ($result) {
            echo "注入新 LAUNCHER 成功\n";
        } else {
            echo "注入新 LAUNCHER 失败\n";
            updateTaskStatus($pdo, $task['id'], '注入失败');
            updateTaskInfo($pdo, $task['id'], '该应用不支持开屏窗口替换,错误02,请不要勾选开屏窗口替换功能');
            safeDeleteDirectory($temp_dir);
            del_osstemp($oss_temp, $localSavePath);
            return;
        }
        
        replace_config_LAUNCHER($de_apk1, encrypt_text($removedActivity));//将原始启动窗口加密存放到壳配置中
    }
    echo "==================================基础检查完成,开始壳配置修改\n";
    updateTaskInfo($pdo, $task['id'], '壳配置修改');
    $appkey = gen_key($task['apk_id'], $apk_user_id);
    echo "写入壳配置->应用ID->{$task['apk_id']}\n";
    echo "写入壳配置->用户ID->{$apk_user_id}\n";
    echo "写入壳配置->应用KEY->{$appkey}\n";
    replace_config_placeholders($de_apk1, $task['apk_id'], $apk_user_id, $appkey);//修改appid和appkey，这是两个自定义参数，用于将壳和用户和应用进行绑定
    if($task['domain_mode']){
        if (!empty($task['domain_mode']) && !empty($task['custom_domains'])) {
            $domains = str_replace("\n", ",", $task['custom_domains']); // 替换为英文逗号
            replace_config_domains($de_apk1, $domains);
        }else{
            updateTaskStatus($pdo, $task['id'], '注入失败');
            updateTaskInfo($pdo, $task['id'], '自定义域名为空');
            safeDeleteDirectory($temp_dir);
            del_osstemp($oss_temp, $localSavePath);
            return;
        }
    }
    echo "==================================存储桶域名注入\n";
    try {
        // 优先使用任务指定的桶，为空则回退到全局 inject=1
        $taskBucketIds = !empty($task['bucket_ids']) ? json_decode($task['bucket_ids'], true) : null;
        if (is_array($taskBucketIds) && !empty($taskBucketIds)) {
            $placeholders = implode(',', array_fill(0, count($taskBucketIds), '?'));
            $bucketStmt = $pdo->prepare("SELECT domain FROM cainiao_s3_bucket WHERE id IN ($placeholders) AND enabled = 1 ORDER BY id ASC");
            $bucketStmt->execute(array_map('intval', $taskBucketIds));
        } else {
            $bucketStmt = $pdo->query("SELECT domain FROM cainiao_s3_bucket WHERE inject = 1 ORDER BY id ASC");
        }
        $bucketDomains = $bucketStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($bucketDomains)) {
            $bucketsStr = implode(',', $bucketDomains);
            replace_config_buckets($de_apk1, $bucketsStr);
            echo "已注入 " . count($bucketDomains) . " 个存储桶域名\n";
        } else {
            echo "无启用的存储桶，跳过\n";
        }
    } catch (\Throwable $e) {
        echo "存储桶域名注入失败（不影响注入流程）: " . $e->getMessage() . "\n";
    }
    echo "==================================设备码计算方式\n";
    if($task['devices']){
        replace_config_info($de_apk1, '[#DEVICES#]', 'AndroidID');
        echo "已更换设备码计算方式为AndroidID\n";
    }
    echo "==================================是否开启适配TV端焦点\n";
    if($task['tv']){
        replace_config_info($de_apk1, '[#TV#]', 'true');
        echo "已开启按钮焦点功能\n";
    }
    echo "==================================强制网络检查\n";
    if(!$task['network']){
        replace_config_NETWORK($de_apk1, '#TRUE#');
        echo "已关闭强制网络检查\n";
    }
    echo "==================================禁用VPN环境\n";
    if($task['vpncheck']){
        replace_config_VPNCHECK($de_apk1, md5(time()));//将时间戳加密后写入这个值，其实为任意值均代表禁用VPN
        echo "已禁用VPN环境\n";
    }
    
    echo "==================================进程隔离\n";
    if(!$task['isMainProcess']){
        replace_config_ISMMAINPROCESS($de_apk1, md5(time()));//将时间戳加密后写入这个值，其实为任意值均代表不隔离进程
        echo "已改成不隔离进程\n";
    }
    
    
    echo "==================================去除签名校验,免写出文件\n";
    if(!file_exists(rtrim($de_apk2, '/\\') . '/assets/SignatureKiller/origin.apk')){
        if($task['killsign']){
            replace_config_SIGN($de_apk1, $task['sign']);
            replace_config_PACKAGE($de_apk1, $task['package']);
            echo "包名：{$task['package']}\n";
            echo "签名：{$task['sign']}\n";
            echo "已写出签名校验信息\n";
            if($task['killpath']){
                $result = processApkAndCopySo($apk_file[1], $de_apk1, $de_apk2);
                if(!$result){
                    updateTaskStatus($pdo, $task['id'], '注入失败');
                    updateTaskInfo($pdo, $task['id'], '加强模式去除签名注入文件失败');
                    safeDeleteDirectory($temp_dir);
                    del_osstemp($oss_temp, $localSavePath);
                    return;
                }
                processAndCopySo($de_apk1, $de_apk2);//注入XHOOK库
                echo "去除签名：加强模式文件和so库注入成功\n";
            }else{
                echo "未使用加强模式去除签名\n";
            }
            
        }else{
            echo "未开启去除签名校验\n";
        }
    }else{
        echo "该应用已经有MT创建的原始文件了,为了不导致冲突,本次不进入签名去除判断\n";
    }
    echo "==================================读取目标应用的application入口类\n";
    updateTaskInfo($pdo, $task['id'], '正在读取入口');
    //$appName = readApplicationName($AXMLPrinter2, $de_apk2);//反编译读取
    $appName = readApplicationName($xml2axml, $de_apk2);//用xml2axml反编译读取，这个库是被我修复过的
    echo "反编译得到的入口{$appName}\n";
    $AAPT_className = getApplicationClassName($aapt, $apk_file[1]);//AAPT读取
    if($appName !== $AAPT_className){
        echo "两种模式得到的入口类名不一致\n";
        if (!empty($AAPT_className) && preg_match('/[^a-zA-Z0-9.]/', $AAPT_className)) {
            echo "以AAPT获取的类名为准使用{$AAPT_className}\n";
            $appName = $AAPT_className;
        }
    }else{
        echo "两种模式得到的入口类名一致，可放心使用\n";
    }
    if(empty($appName)){
        if ($AAPT_className) {
            echo "AAPT得到的入口类名是：$AAPT_className\n";
            $appName = $AAPT_className;
            echo "使用AAPT读取的入口类：{$appName}\n";
        } else {
            echo "未找到 Application 类名，使用默认值：android.app.Application\n";
        }
    }else{
        echo "使用反编译得到的入口类：{$appName}\n";
    }
    
    echo "入口类{$appName}\n";
    if (strpos($appName, '.') === 0) {
        // 如果是相对类名（以.开头），去掉第一个点并补全包名
        $appName = $task['package'] . $appName;
    }
    if($appName === 'Application'){
        $appName = 'android.app.Application';//补全默认类名
    }
$applicationlin=[];
    echo "==================================查找云注入类\n";//在注入前，就先把云注入的dex找出来，用变量 $CloudInject 储存，然后再根据注入任务，来决定是否洗注入链
    //云注入一般是注入到入口或者入口类的父类，属于第二层
    //所以清理的话，只需要看入口是否是云注入类，如果是，则改成云注入的父类
    //如果已经有入口类了，则反编译入口类dex，将其父类改成云注入的父类
    if(!$task['kill_Inject']){
        echo "该任务未选择去除云注入\n";
    }else{
        
        //去除普通云注入
        updateTaskInfo($pdo, $task['id'], '查找云注入dex');
        echo "该任务选择了去除云注入,开始查找云注入dex\n";
        $found = dexedit_ac($dexedit, $xmx, $apk_file[1], "com.sadfxg.fasg.App");
        if($found == false){
            echo "该应用未被 CloudInject 云注入\n";
        }else{
            updateTaskInfo($pdo, $task['id'], '读取云注入父类');
            echo "找到 CloudInject 新版本云注入类{$found}，开始读取父类\n";
            $delete_found = $found;
            $CloudInject = dexedit_ds($dexedit, $xmx, $de_apk2."/".$found, "com.sadfxg.fasg.App");
            if($CloudInject == 'android.app.Application'){
                echo "父类已经是android.app.Application" . PHP_EOL;
            }else{
                echo "父类是：" . $CloudInject . PHP_EOL;
            }
            echo "==================================开始去除云注入\n";
            
            updateTaskInfo($pdo, $task['id'], '开始去除云注入');
            if($appName == 'com.sadfxg.fasg.App'){
                echo "入口类就是云注入类,直接将入口类改成云注入的父类" . PHP_EOL;
                $result = updateManifest($Editor, $de_apk2, $CloudInject);
                if($result){
                    echo "修改启动入口为云注入父类成功,更新入口类{$appName}为{$CloudInject}" . PHP_EOL;
                    //removeDexAndResequence($de_apk2, $delete_found);
                    $appName = $CloudInject;
                }
            }else{
                echo "寻找入口类dex" . PHP_EOL;
                $found = dexedit_ac($dexedit, $xmx, $apk_file[1], $appName);//找目标应用的入口dex
                if (!$found) {
                    echo "未找到入口类dex,本次去除云注入无效,不做处理\n";
                }else{
                    echo "找到入口类dex：{$found},类名：{$appName}开始将其父类修改为云注入父类{$CloudInject}\n";
                    $result = dexedit_ms($dexedit, $xmx, $de_apk2."/".$found, $appName, $CloudInject);
                    if ($result) {
                        echo "修改成功,删除云注入dex:{$delete_found}（删除功能已被注释，因为有些dex是拆分了的，删除可能导致正常代码缺失）\n";
                        $applicationlin[] = "com.sadfxg.fasg.App";//代表已经成功去除了云注入了，后面的追链的时候，就要从链上忽略这个了
                        //removeDexAndResequence($de_apk2, $delete_found);
                    } else {
                        echo "修改失败\n";
                        updateTaskStatus($pdo, $task['id'], '注入失败');
                        updateTaskInfo($pdo, $task['id'], '去除云注入失败');
                        safeDeleteDirectory($temp_dir);
                        del_osstemp($oss_temp, $localSavePath);
                        return;
                    }
                }
            }
            
            
        }
        
        
        
        //去除雀云注入
        updateTaskInfo($pdo, $task['id'], '查找雀云注入dex');
        echo "该任务选择了去除云注入,开始查找雀云注入dex\n";
        $found = dexedit_ac($dexedit, $xmx, $apk_file[1], "com.lark.injector.InjectorApplication");
        if($found == false){
            echo "该应用未被雀云注入\n";
        }else{
            updateTaskInfo($pdo, $task['id'], '读取雀云注入父类');
            echo "找到雀云注入类{$found}，开始读取父类\n";
            $delete_found = $found;
            $CloudInject = dexedit_ds($dexedit, $xmx, $de_apk2."/".$found, "com.lark.injector.InjectorApplication");
            if($CloudInject == 'android.app.Application'){
                echo "父类已经是android.app.Application" . PHP_EOL;
            }else{
                echo "父类是：" . $CloudInject . PHP_EOL;
            }
            echo "==================================开始去除云注入\n";
            
            updateTaskInfo($pdo, $task['id'], '开始去除雀云注入');
            if($appName == 'com.lark.injector.InjectorApplication'){
                echo "入口类就是雀云注入类,直接将入口类改成雀云注入的父类" . PHP_EOL;
                $result = updateManifest($Editor, $de_apk2, $CloudInject);
                if($result){
                    echo "修改启动入口为雀云注入父类成功,更新入口类{$appName}为{$CloudInject}" . PHP_EOL;
                    //removeDexAndResequence($de_apk2, $delete_found);
                    $appName = $CloudInject;
                }
            }else{
                echo "寻找入口类dex" . PHP_EOL;
                $found = dexedit_ac($dexedit, $xmx, $apk_file[1], $appName);//找目标应用的入口dex
                if (!$found) {
                    echo "未找到入口类dex,本次去除雀云注入无效,不做处理\n";
                }else{
                    echo "找到入口类dex：{$found},类名：{$appName}开始将其父类修改为云注入父类{$CloudInject}\n";
                    $result = dexedit_ms($dexedit, $xmx, $de_apk2."/".$found, $appName, $CloudInject);
                    if ($result) {
                        echo "修改成功,删除云注入dex:{$delete_found}（删除功能已被注释，因为有些dex是拆分了的，删除可能导致正常代码缺失）\n";
                        $applicationlin[] = "com.lark.injector.InjectorApplication";//代表已经成功去除了云注入了，后面的追链的时候，就要从链上忽略这个了
                        //removeDexAndResequence($de_apk2, $delete_found);
                    } else {
                        echo "修改失败\n";
                        updateTaskStatus($pdo, $task['id'], '注入失败');
                        updateTaskInfo($pdo, $task['id'], '去除雀云注入失败');
                        safeDeleteDirectory($temp_dir);
                        del_osstemp($oss_temp, $localSavePath);
                        return;
                    }
                }
            }
            
            
        }
    
        
        
    }
    echo "==================================开始修复可能存在的desugar问题\n";
    echo "检测目标应用底包的desugar库是否缺失hashCode方法，如果缺了，则从本平台的库中移植这个方法进去确保兼容，只移植这一个方法即可,不然可能出现其他不可预知的问题\n";
    $found = dexedit_ac($dexedit, $xmx, $apk_file[1], "j$.util.Objects");
    if($found == false){
        echo "该应用无desugar库，无需修复\n";
    }else{
        echo "该应用存在desugar库，需要检修\n";
        $result = dexedit_mergedex($dexedit, $xmx, $de_apk2."/".$found, $de_apk1."/classes2.dex", $de_apk2."/".$found);
        
        if ($result) {
            echo "desugar库融合修复 成功\n";
        } else {
            echo "desugar库融合修复 失败\n";
        }
    }
    //去找入口类所在的dex文件
    echo "==================================寻找入口类所在的dex文件，准备插桩,此功能在部分应用上存在BUG\n";//插桩功能先暂时不启用，因为壳模板还有混用，多迭代几个壳版本后再开启这个功能
    /*if($task['apk_size'] >= 1024 * 1024 * 3 && $task['user_id'] == 1){
        echo "APP大于3M，加桩\n";
        $found = dexedit_ac($dexedit, $xmx, $apk_file[1], $appName);//找目标应用的入口dex
        if(!$found){
            //未找到则不管
            echo "未找到入口类{$appName}所在的dex文件\n";
        }else{
            $result = dexedit_ism($dexedit, $xmx, $de_apk2."/".$found, $appName, "com.example.shell.Utils", "init");
            echo "给原入口{$appName}插桩Utils.init()结果{$result}\n";
        }
    }else{
        echo "APP小于3M，不加桩\n";
    }*/
    echo "==================================保存APP原始入口到壳配置中\n";
    if($mode == 3){
        replace_config_APPLICATION($de_apk1, encrypt_text($appName));//将原始入口加密存放到壳配置中
    }else{
        replace_config_APPLICATION($de_apk1, "null");//非入口继承模式，将此处改为null字符串
    }
    echo "==================================开始注入壳类\n";
    updateTaskInfo($pdo, $task['id'], '开始注入壳');
    if($mode == 0){
        echo "注入模式0,入口注入.继承,保存入口{$appName}\n";
        echo "此模式原理,将壳类注入到application入口，然后由壳继承原入口\n";//通过反射调用，似乎在部分应用中存在问题，需要改成壳继承父入口类
        if(!empty($appName)){
            echo "存在原始入口,修改壳父类为原始入口类\n";
            if(!updateSmaliSuperClass($de_apk1, $shellClassName, $appName)){
                echo "修改壳父类失败,更换为保存入口反射调用\n";
                $result = writeYunzhuru($de_apk2, $appName);
                if(!$result){
                    echo "保存原始入口失败\n";
                    updateTaskStatus($pdo, $task['id'], '注入失败');
                    updateTaskInfo($pdo, $task['id'], '保存原始入口失败');
                    safeDeleteDirectory($temp_dir);
                    del_osstemp($oss_temp, $localSavePath);
                    return;
                }else{
                    echo "保存原始入口成功\n";
                    
                    
                    
                }
            }
        }else{
            echo "无原始入口，不修改壳父类，不保存入口\n";
        }
        
    }else if($mode == 1){
        if(empty($appName) || $appName == 'android.app.Application'){
            echo "该应用无入口类或者已经是application，模式不适用，切换到入口注入模式\n";
            $mode = 0;//更改注入模式为入口注入,后面根据这个值，直接修改入口，所以此处直接跳过不修改
        }else{
            echo "注入模式1,链路注入,将原入口类的父类{$appName}改成壳类{$shellClassName}\n";//同时还要把壳的父类改成目标应用的原始父类
            echo "此模式原理,将原始入口的父类改成壳类，然后将壳父类改成原入口类的父类\n";
            $printappchain = dexedit_printappchain($dexedit, $xmx, $apk_file[1], $appName, true, $applicationlin);
            print_r($printappchain);
            if(!empty($printappchain['dex']) && !empty($printappchain['class']) && !$task['isMainProcess']){
                echo "存在多重继承链路关系,且未开启进程隔离,注入到最深层\n";
                $appName = $printappchain['class'];
                $found = $printappchain['dex'];
                echo "==================================链路注入模式插防去除桩\n";
                /*if($task['apk_size'] >= 1024 * 1024 * 3 && $task['user_id'] == 1){
                    echo "APP大于3M，加桩\n";
                    $result = dexedit_ism($dexedit, $xmx, $de_apk2."/".$found, $appName, "com.example.shell.Utils", "init");
                    echo "给原入口{$appName}插桩Utils.init()结果{$result}\n";
                }else{
                    echo "APP小于3M，不加桩\n";
                }*/
                
                
            }else{
                //未找到链路和dex则找入口dex文件，如果勾选了进程隔离，则只注入到上1层，因为沙盒类应用可能存在复杂的继承关系，会导致分身启动失败
                $found = dexedit_ac($dexedit, $xmx, $apk_file[1], $appName);//找目标应用的入口dex
            }
            
            $found = dexedit_ac($dexedit, $xmx, $apk_file[1], $appName);//找目标应用的入口dex
            
            if(!$found){
                updateTaskStatus($pdo, $task['id'], '注入失败');
                updateTaskInfo($pdo, $task['id'], '未找到入口dex文件,请更换注入模式');
                safeDeleteDirectory($temp_dir);
                del_osstemp($oss_temp, $localSavePath);
                return;
            }
            echo "找到的入口类所在的dex文件：{$found}\n";
            //找到入口dex之后，要读取入口dex的父类,然后壳dex要来继承它
            $class_ds = dexedit_ds($dexedit, $xmx, $de_apk2."/".$found, $appName);
            echo "入口类的父类是{$class_ds}，壳将继承这个类\n";
            if($class_ds == 'android.app.Application'){
                echo "原始父类已经是android.app.Application,无需再改\n";
            }else{
                //修改壳的父类
                $result = update_smali_superclass($de_apk1, $shellClassName, $class_ds);
                echo "修改壳父类,将{$de_apk1}从{$shellClassName}修改为{$class_ds}\n";
                if(!$result){
                    echo "修改壳父类失败\n";
                    updateTaskStatus($pdo, $task['id'], '注入失败');
                    updateTaskInfo($pdo, $task['id'], '修改壳父类失败');
                    safeDeleteDirectory($temp_dir);
                    del_osstemp($oss_temp, $localSavePath);
                    return;
                }
            }
            //壳父类修改完成，然后将入口类的父类换成壳类
            updateTaskInfo($pdo, $task['id'], "正在注入到链路{$appName}");
            $result = dexedit_ms($dexedit, $xmx, $de_apk2."/".$found, $appName, $shellClassName );
            if(!$result){
                updateTaskStatus($pdo, $task['id'], '注入失败');
                updateTaskInfo($pdo, $task['id'], '注入到链路失败,请尝试更换注入模式');
                safeDeleteDirectory($temp_dir);
                del_osstemp($oss_temp, $localSavePath);
                return;
            }

        }
        
        
        
        
        
        
        
    }else if($mode == 2){
        
        $appComponentFactoryClassName = getappComponentFactoryClassName($aapt, $apk_file[1]);
        echo "注入模式2,appComponentFactory 注入,注入工厂{$appComponentFactoryClassName}\n";
        echo "此模式原理,读取原始工厂，将壳类注入到工厂入口，然后由壳appComponentFactory劫持原入口，调用壳入口，然后壳反射调用原工厂\n";
        if(!empty($appComponentFactoryClassName)){
            echo "AAPT得到的工厂类名是：$appComponentFactoryClassName\n";//有原始工厂，需要修改壳配置为原始工厂
            if($appComponentFactoryClassName == 'android.app.AppComponentFactory'){
                echo "原始工厂已经是android.app.AppComponentFactory，无需修改壳配置\n";
            }else{
                echo "开始修改壳配置种的工厂名为{$appComponentFactoryClassName}\n";
                replace_config_originFactoryClassName($de_apk1, $appComponentFactoryClassName);
            }
        }else{
            echo "未读到工厂类名，不用修改壳工厂调用类\n";
        }
        //修改目标应用工厂为壳工厂
        $result = fix_android_http_limit($Editor, $de_apk2, "android-appComponentFactory:{$final_package}.{$GLOBALS['ShellAppComponentFactory']}");
        if(!$result){
            updateTaskStatus($pdo, $task['id'], '注入失败');
            updateTaskInfo($pdo, $task['id'], '注入壳工厂失败,请更换注入模式');
            safeDeleteDirectory($temp_dir);
            del_osstemp($oss_temp, $localSavePath);
            return;
        }else{
            echo "工厂注入完成\n";
        }
        /*updateTaskStatus($pdo, $task['id'], '注入失败');
        updateTaskInfo($pdo, $task['id'], '未启用的注入模式,请更换注入模式');
        safeDeleteDirectory($temp_dir);
        return;*/
    }else if($mode == 3){
        echo "注入模式3,入口注入.反射,保存入口{$appName}\n";
        echo "此模式原理,将原始入口保存，将壳类注入到application入口，然后由壳反射调用原入口\n";//通过反射调用，似乎在部分应用中存在问题，需要改成壳继承父入口类
        
        /*$result = writeYunzhuru($de_apk2, $appName);//写出入口到assets中，138版本以下的旧版本壳需要写出此特征
        
        if(!$result){
            echo "保存原始入口失败\n";
            updateTaskStatus($pdo, $task['id'], '注入失败');
            updateTaskInfo($pdo, $task['id'], '保存原始入口失败');
            safeDeleteDirectory($temp_dir);
            return;
        }
        echo "保存原始入口成功\n";*/
    }else{
        echo "未知的注入模式\n";
        updateTaskStatus($pdo, $task['id'], '注入失败');
        updateTaskInfo($pdo, $task['id'], '未知的注入模式');
        safeDeleteDirectory($temp_dir);
        del_osstemp($oss_temp, $localSavePath);
        return;
    }
    echo "==================================开始编译壳\n";
    updateTaskInfo($pdo, $task['id'], '开始编译壳');
    $result = compileSmaliToNextDex($smali, $de_apk1, $de_apk2);
    if(!$result){
        echo "编译壳失败\n";
        updateTaskStatus($pdo, $task['id'], '注入失败');
        updateTaskInfo($pdo, $task['id'], '编译壳失败');
        safeDeleteDirectory($temp_dir);
        del_osstemp($oss_temp, $localSavePath);
        return;
    }
    echo "编译壳完成\n";
    
    
    
    
    
    
    
    
    
    
    echo "==================================开始复制剩余dex文件\n";
    $result = mergeDexFiles($de_apk2, $de_apk1);
    print_r($result);
    echo "==================================开始修改AndroidManifest入口\n";
    if($mode == 0 || $mode == 3){
        $result = updateManifest($Editor, $de_apk2, $shellClassName);
        if(!$result){
            updateTaskStatus($pdo, $task['id'], '注入失败');
            updateTaskInfo($pdo, $task['id'], '修改入口失败');
            safeDeleteDirectory($temp_dir);
            del_osstemp($oss_temp, $localSavePath);
            return;
        }
        echo "入口修改完成\n";
    }else{
        echo "此模式无需修改入口\n";
    }
    echo "==================================开始注入权限\n";
    /*$permissions = $task['permissions'];//表里的permissions字段
    $perms = [
        'android.permission.INTERNET',//网络权限
        'android.permission.SYSTEM_ALERT_WINDOW'//悬浮窗权限
    ];*/
    // 解析表里的权限，确保是数组
    $permissions = json_decode($task['permissions'], true);
    if (!is_array($permissions)) {
        $permissions = [];
    }
    
    // 要合并的新权限数组
    $perms = [
        'android.permission.INTERNET',//网络
        'android.permission.SYSTEM_ALERT_WINDOW',//悬浮窗
        'android.permission.ACCESS_NETWORK_STATE',//网络检查
        'android.permission.QUERY_ALL_PACKAGES'//包名检测权限
    ];
    
    // 合并数组并去重
    $mergedPermissions = array_values(array_unique(array_merge($permissions, $perms)));
    $result = addPermissions($Editor, $de_apk2, $mergedPermissions);
    if(!$result){
        updateTaskStatus($pdo, $task['id'], '注入失败');
        updateTaskInfo($pdo, $task['id'], '添加权限失败');
        safeDeleteDirectory($temp_dir);
        del_osstemp($oss_temp, $localSavePath);
        return;
    }
    echo "权限注入完成\n";
    echo "==================================开始注入debug模式\n";
    if($task['debug']){
        $result = debuggable($Editor, $de_apk2, $task['debug']);
        if(!$result){
            updateTaskInfo($pdo, $task['id'], '启用debug模式失败');
            echo "debug注入失败\n";
        }else{
            echo "debug注入完成\n";
        }
    }else{
         echo "未启用debug注入\n";
    }
    echo "==================================HTTP限制解除\n";
    $task['allowHttp'] = 1;//必须解除HTTP限制,防止特殊情况域名失效可以用ip
    if($task['allowHttp']){
        $result = fix_android_http_limit($Editor, $de_apk2, "android-usesCleartextTraffic:true");
        if(!$result){
            updateTaskStatus($pdo, $task['id'], '注入失败');
            updateTaskInfo($pdo, $task['id'], '解除HTTP限制失败');
            safeDeleteDirectory($temp_dir);
            del_osstemp($oss_temp, $localSavePath);
            return;
        }
        echo "已解除HTTP限制\n";
    }else{
        echo "未使用解除http限制\n";
    }
    // 强制将 extractNativeLibs 设为 true，兼容 Android 16 安装（二进制 manifest 用 ManifestEditor 修改）
    fix_android_http_limit($Editor, $de_apk2, "android-extractNativeLibs:true");
    echo "已处理extractNativeLibs\n";
    echo "==================================去除签名校验\n";
    if(!file_exists(rtrim($de_apk2, '/\\') . '/assets/SignatureKiller/origin.apk')){
        if($task['killsign']){
            $result = writeSignatureFiles($de_apk2, $task['package'], $task['sign']);
            if(!$result){
                updateTaskStatus($pdo, $task['id'], '注入失败');
                updateTaskInfo($pdo, $task['id'], '注入原应用签名信息失败');
                safeDeleteDirectory($temp_dir);
                return;
            }
            echo "包名：{$task['package']}\n";
            echo "签名：{$task['sign']}\n";
            echo "已写出签名校验信息\n";
            if($task['killpath']){
                $result = processApkAndCopySo($apk_file[1], $de_apk1, $de_apk2);
                if(!$result){
                    updateTaskStatus($pdo, $task['id'], '注入失败');
                    updateTaskInfo($pdo, $task['id'], '加强模式去除签名注入文件失败');
                    safeDeleteDirectory($temp_dir);
                    del_osstemp($oss_temp, $localSavePath);
                    return;
                }
                processAndCopySo($de_apk1, $de_apk2);//注入XHOOK库
                echo "去除签名：加强模式文件和so库注入成功\n";
            }else{
                echo "未使用加强模式去除签名\n";
            }
            
        }else{
            echo "未开启去除签名校验\n";
        }
    }else{
        echo "该应用已经有MT创建的原始文件了,为了不导致冲突,本次不进入签名去除判断\n";
    }
    echo "==================================复制so库\n";
    updateTaskInfo($pdo, $task['id'], '复制so文件');
    processAndCopyNative($de_apk1, $de_apk2, ['libcainiaosockethook.so', 'libshadowhook.so', 'libshadowhook_nothing.so']);//复制shadowhook库
    processAndCopyNative($de_apk1, $de_apk2, ['libtoolChecker.so']);//复制root检测库
    echo "==================================请求模式\n";
    if($task['Request']){
        echo "并发请求\n";
        //writeYunzhuruTypeFile($de_apk2);
    }else{
        echo "轮询请求\n";
    }
    echo "==================================写出特征\n";
    if($isVip){
        writecharacteristic($de_apk2, '声明.txt', "禁止将此APP用于违法用途\n一切由使用此应用造成的后果及连带后果自负\n");//特征随机内容
    }else{
        writecharacteristic($de_apk2, '声明.txt', "此应用经过菜鸟云验证免费处理\n禁止将此APP用于违法用途\n一切由使用此应用造成的后果及连带后果自负\n");//特征随机内容
        //writecharacteristic($de_apk2, 'cainiao_vip.so', getRandomLyric());//特征随机内容
        //writecharacteristic($de_apk2, 'cainiao_vip.so', generate_fake_so(1024*360));//特征随机内容
    }
    if($task['jiagu']){
        //writecharacteristic($de_apk2, 'libjiagu_vip_a64.so', generate_fake_so(1024*360));//特征随机内容
        //writecharacteristic($de_apk2, 'libjiagu_mips.a', generate_fake_so(1024*3));//特征随机内容
    }
    
    
    
    
        
    //$task['confuse'] = 0;//直接停用此功能
    if($task['confuse']){
         echo "==================================合并APK模式\n";
        $temp_apk_file = patchApk($apk_file[1], $temp_dir, $de_apk2);
        if(!$temp_apk_file){
            updateTaskStatus($pdo, $task['id'], '注入失败');
            updateTaskInfo($pdo, $task['id'], 'APK合并失败,可以先关闭此功能');
            safeDeleteDirectory($temp_dir);
            del_osstemp($oss_temp, $localSavePath);
            return;
        }
        $output_apk = $temp_apk_file;//拿到回编译之后的apk路径
        /*updateTaskStatus($pdo, $task['id'], '编译失败');
        updateTaskInfo($pdo, $task['id'], "APK合并功能暂不可用");
        safeDeleteDirectory($temp_dir);
        return;*/
        
    }else{
        echo "==================================回编译模式\n";
        updateTaskStatus($pdo, $task['id'], '正在编译');
        updateTaskInfo($pdo, $task['id'], '开始回编译');
        $result = rebuild_apk($apktool_jar, $de_apk2, null, $xmx);//回编译
        //print_r($result);
        if(!$result[0]){
            print_r($result[4]);
            updateTaskStatus($pdo, $task['id'], '编译失败');
            updateTaskInfo($pdo, $task['id'], $result[4]);
            safeDeleteDirectory($temp_dir);
            del_osstemp($oss_temp, $localSavePath);
            //updateTaskStatus($pdo, $task['id'], '等待处理');exit;
            return;
        }
        $output_apk = $result[2];//拿到回编译之后的apk路径
        
    }
    
    echo "==================================清理解包目录\n";
    updateTaskInfo($pdo, $task['id'], '清理文件');
    
    //回编译成功之后,可以删除解包内容了
    //safeDeleteDirectory($de_apk1);
    //safeDeleteDirectory($de_apk2);
    echo "==================================反编译后,开始清理oss拉取下来的缓存文件\n";
    del_osstemp($oss_temp, $localSavePath);
    echo "==================================调用dex拆分合并\n";
    if($task['dexmerge']){
        updateTaskInfo($pdo, $task['id'], '合并DEX');
        $dexedit_sd = dexedit_sd($dexedit, $xmx, $output_apk, '45000', $output_apk);
        echo "DEX重新划分结果：{$dexedit_sd}\n";
    }else{
        echo "该任务未开启dex重新分配\n";
    }
    
    
    echo "==================================zipalign_apk\n";
    updateTaskInfo($pdo, $task['id'], 'ZIP对齐');
    echo "正在zip对齐：{$output_apk}\n";
    $zipalign = zipalign_apk($output_apk);
    
    print_r($zipalign);
    if(!$zipalign[0]){
        updateTaskStatus($pdo, $task['id'], '编译失败');
        updateTaskInfo($pdo, $task['id'], $depth.'zipalign失败');
        safeDeleteDirectory($temp_dir);
        return;
    }
    // 使用对齐后的文件进行后续签名
    $output_apk = $zipalign[1];
    echo "使用对齐后的APK：{$output_apk}\n";
    echo "==================================在此处调用luoyesiqiu加固\n";
    if(!$task['jiagu']){
        echo "该任务未启用加固功能\n";
    }else{
        if(!file_exists($jiagujar)){
            echo "加固库文件不存在\n";
        }else{
            updateTaskInfo($pdo, $task['id'], '开始加固APK');
            echo "开始加固\n";
            $jiagu_outputApk =  $temp_dir . DIRECTORY_SEPARATOR . basename($output_apk, '.apk') . '.jiagu.apk';//加固后的文件依旧放在缓存目录
            echo "加固前路径{$output_apk}\n";
            echo "加固后路径{$jiagu_outputApk}\n";
            $jiagu_state = jiagu($jiagujar, $xmx, $output_apk, $jiagu_outputApk);//jar路径，限制内存大小，输入文件，加固后的文件
            if(!$jiagu_state){
                echo "加固失败,不处理路径,正常签名未加固的APK\n";
            }else{
                echo "加固成功,后续签名使用加固后的APK{$jiagu_outputApk}\n";
                $output_apk = $jiagu_outputApk;
            }
        }
    }
    
    echo "==================================AndroidManifest伪加密\n";
    //统一加密Androidmanifest
    if($task['fake']){
        echo "正在加密AndroidManifest\n";
        $android_manifest_jiami = editApkManifestHeaderAndOffset($output_apk);
        if(!$android_manifest_jiami){
            echo "加密AndroidManifest失败\n";
        }else{
            echo "加密AndroidManifest成功\n";
        }
    }else{
        echo "该任务未开启加固\n";
    }
    
    
    
    echo "==================================数据复用优化\n";
        $Multiplexing = true;
        if(file_exists(rtrim($de_apk2, '/\\') . '/assets/SignatureKiller/origin.apk')){
            echo "已存在MT管理器的签名原始文件注入,优化这个文件\n";
            $Multiplexing = 'assets/SignatureKiller/origin.apk';
        }else if(file_exists(rtrim($de_apk2, '/\\') . '/assets/yunzhuru/origin.apk')){
            echo "已存在菜鸟云注入的注入文件,优化这个文件\n";
            $Multiplexing = 'assets/yunzhuru/origin.apk';
        }else if(file_exists(rtrim($de_apk2, '/\\') . '/assets/lspatch/origin.apk')){
            echo "已存在LSP的注入文件,优化这个文件\n";
            $Multiplexing = 'assets/lspatch/origin.apk';
        }else{
            echo "不存在任何源文件注入,不优化\n";
        }
        if($Multiplexing !== false){
            if($Multiplexing === true){
                $Multiplexing = '';//如果不是指定的复用包，则这里留空让自动找复用包
            }
            updateTaskInfo($pdo, $task['id'], '正在进行数据复用优化');
            $release_dir = realpath(__DIR__ . '/../release');
            if (!$release_dir) {
                mkdir(__DIR__ . '/../release', 0755, true);
                $release_dir = realpath(__DIR__ . '/../release');
            }
            
            $filename = $task['package'] . '_' . date('Ymd_His') . '.signed.apk';
            $signed_apk = $release_dir . DIRECTORY_SEPARATOR . $filename;
            $result = optimizeApk(
                $ApkDataMultiplexing,
                $output_apk,//输入文件
                $signed_apk,//输出文件
                $Multiplexing,//优化原始包的位置
                $keystore,//签名文件路径
                $storepass, // storepass
                $alias, // alias
                $keypass  // keypass
            );
            
            if ($result == false) {
                echo "优化失败，走正常签名路线\n";
            } else {
                echo "优化成功，输出文件：" . $result . "\n";
                // APK 完整性检测
                $verifyResult = verify_apk_installable($signed_apk);
                if (!$verifyResult[0]) {
                    echo "APK 完整性检测未通过：" . $verifyResult[1] . "\n";
                    updateTaskStatus($pdo, $task['id'], '编译失败');
                    updateTaskInfo($pdo, $task['id'], $depth . 'APK完整性检测失败：' . $verifyResult[1]);
                    safeDeleteFile($signed_apk);
                    safeDeleteDirectory($temp_dir);
                    return;
                }
                updateInjectedApkPath($pdo, $task['id'], $filename);//更新签名后的文件到数据库
                updateTaskStatus($pdo, $task['id'], '编译成功');
                updateTaskInfo($pdo, $task['id'], $depth.'编译成功');
                // 自动上传已禁用，改为手动触发
                // autoUploadToS3($pdo, $task['id'], $task['name']);
                updateSignedApkSize($pdo, $task['id'], $signed_apk);
                safeDeleteDirectory($temp_dir);
                if (!taskExists($pdo, $task['id'])) {
                    //任务已被删除,清理已签名的文件
                    echo "任务不存在\n";
                    safeDeleteFile($result[2]);
                    safeDeleteFile($zipalign[2]);
                }
                echo "=====================================================================\n";
                return;
            }
        }
    echo "==================================开始签名\n";
    updateTaskStatus($pdo, $task['id'], '正在签名');
    $release_dir = realpath(__DIR__ . '/../release');
    if (!$release_dir) {
        mkdir(__DIR__ . '/../release', 0755, true);
        $release_dir = realpath(__DIR__ . '/../release');
    }
    
    $filename = $task['package'] . '_' . date('Ymd_His') . '.signed.apk';
    $signed_apk = $release_dir . DIRECTORY_SEPARATOR . $filename;
    
    $result = sign_apk($keystore, $alias, $storepass, $keypass, $output_apk, $signed_apk, null, $apksigner_jar);//集成签名环境
    //$result = sign_apk($keystore, $alias, $storepass, $keypass, $output_apk, $signed_apk, null);//系统签名环境
    echo "==================================签名结果\n";
    print_r($result);
    if(!$result[0]){
        updateTaskStatus($pdo, $task['id'], '签名失败');
        updateTaskInfo($pdo, $task['id'], $result[1] ?? '请检查签名证书信息是否正确或尝试更换证书文件');
        safeDeleteDirectory($temp_dir);
        safeDeleteFile($output_apk);//删除未签名的文件
        return;
    }
    //exit;
    echo "删除V4签名的文件".$result[2].".idsig\n";
    safeDeleteFile($result[2].".idsig");//删除V4签名的文件

    echo "==================================APK完整性检测\n";
    $verifyResult = verify_apk_installable($signed_apk);
    if (!$verifyResult[0]) {
        echo "APK 完整性检测未通过：" . $verifyResult[1] . "\n";
        updateTaskStatus($pdo, $task['id'], '编译失败');
        updateTaskInfo($pdo, $task['id'], $depth . 'APK完整性检测失败：' . $verifyResult[1]);
        safeDeleteFile($signed_apk);
        safeDeleteDirectory($temp_dir);
        return;
    }

    echo "==================================收尾清理\n";
    
    
    updateInjectedApkPath($pdo, $task['id'], $filename);//更新签名后的文件到数据库
    updateTaskStatus($pdo, $task['id'], '编译成功');
    updateTaskInfo($pdo, $task['id'], $depth.'编译成功');
    // 自动上传已禁用，改为手动触发
    // autoUploadToS3($pdo, $task['id'], $task['name']);
    updateSignedApkSize($pdo, $task['id'], $signed_apk);
    safeDeleteDirectory($temp_dir);
    if (!taskExists($pdo, $task['id'])) {
        //任务已被删除,清理已签名的文件
        echo "任务不存在\n";
        safeDeleteFile($result[2]);
        safeDeleteFile($zipalign[2]);
    }
    echo "=====================================================================\n";
    //updateTaskStatus($pdo, $task['id'], '2');exit;
    //updateTaskStatus($pdo, $task['id'], '等待处理');exit;
}


//===============================================================================================================================
//共享方法，在jiagu.php服务中也有使用
function del_osstemp($oss_temp, $localSavePath){
    if ($oss_temp) {
        echo "底包是从OSS拉取的，正在执行缓存底包删除\n";
    
        if (file_exists($localSavePath)) {
    
            // 尝试删除文件
            if (unlink($localSavePath)) {
                echo "缓存底包删除成功\n";
            } else {
                echo "缓存底包删除失败\n";
    
                // 可选：输出错误原因
                $error = error_get_last();
                if ($error) {
                    echo "错误信息：" . $error['message'] . "\n";
                }
            }
    
        } else {
            echo "缓存底包不存在\n";
        }
    }
}




function dexedit_mergedex($dexedit, $xmx, $dex1, $dex2, $outputDex) {
    // 组装命令
    $cmd = "java "
        . ($xmx ? "-Xmx" . escapeshellarg($xmx) . " " : "")
        . "-jar "
        . escapeshellarg($dexedit)
        . " -mergedex "
        . escapeshellarg($dex1)
        . " "
        . escapeshellarg($dex2)
        . " "
        . escapeshellarg($outputDex)
        . " 2>&1";

    echo "执行命令: {$cmd}\n";
    $output = shell_exec($cmd);
    echo "结果: {$output}\n";

    // jar 内部统一以 true / false + \n 输出
    if ($output === "true\n") {
        echo "执行成功\n";
        return true;
    } else {
        echo "执行失败\n";
        return false;
    }
}







function generateRandomString($minLen = 1, $maxLen = 10)
{
    $length = rand($minLen, $maxLen);
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $result = '';

    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[rand(0, strlen($chars) - 1)];
    }

    return $result;
}

function generateRandomArabicClassName()
{
    // 获取混淆字符缓存
    $cache = buildArabicLetterCache(200);

    // 随机类名长度：1 ~ 10
    $length = rand(1, 10);

    $name = '';

    for ($i = 0; $i < $length; $i++) {
        $name .= $cache[array_rand($cache)];
    }

    return $name;
}



function buildArabicLetterCache($count = 100)
{
    // 作为合法 IdentifierStart 的锚点字符（极不显眼）
    $startPool = ['l'];

    // Java / Android 合法的组合附加符（可编译）
    $combiningPool = [
        "\u{0300}", "\u{0301}", "\u{0302}", "\u{0303}", "\u{0304}",
        "\u{0305}", "\u{0306}", "\u{0307}", "\u{0308}", "\u{0309}",
        "\u{030A}", "\u{030B}", "\u{030C}", "\u{030D}", "\u{030E}",
        "\u{030F}", "\u{0310}", "\u{0311}", "\u{0312}", "\u{0313}",
        "\u{0314}", "\u{0315}", "\u{0316}", "\u{0317}", "\u{0318}",
        "\u{0319}", "\u{031A}"
    ];

    $result = [];
    $used = [];

    while (count($result) < $count) {

        $start = $startPool[array_rand($startPool)];

        $suffix = '';
        for ($i = 0; $i < 100; $i++) {
            $suffix .= $combiningPool[array_rand($combiningPool)];
        }

        $str = $start . $suffix;
        $str = $suffix;

        if (!isset($used[$str])) {
            $used[$str] = true;
            $result[] = $str;
        }
    }

    return $result;
}



function maybeAddCommonAndroidPrefix($package)
{
    // 70% 概率加前缀
    if (mt_rand(1, 100) > 70) {
        return $package;
    }

    // 常见 / 系统 / 高出现率的安卓包前缀
    $commonPrefixes = [
        'android.',
        'androidx.',
        'androidx.multidex.',
        'androidx.core.',
        'androidx.lifecycle.',
        'androidx.appcompat.',
        'com.google.android.',
        'com.google.android.gms.',
        'com.google.firebase.',
        'org.apache.commons.',
        'org.jetbrains.',
        'kotlin.',
        'kotlinx.',
        'javax.',
        'org.json.',
        'okhttp3.',
        'okio.',
        'retrofit2.',
    ];

    // 随机选一个前缀
    $prefix = $commonPrefixes[array_rand($commonPrefixes)];

    return $prefix . $package;
}

/**
 * 去除引流分享界面启动入口
 *
 * @param string $xml2axmlPath xml2axml.jar 路径
 * @param string $dir 包含 AndroidManifest.xml 的目录
 * @return string|false 成功返回 activity 的 android:name，失败返回 false
 */
function setupLauncherByMetaDataWithXml2Axml($xml2axmlPath, $dir)
{
    $manifestBin = rtrim($dir, '/\\') . '/AndroidManifest.xml';
    $manifestXml = rtrim($dir, '/\\') . '/AndroidManifest2.xml';

    if (!is_file($xml2axmlPath) || !is_file($manifestBin)) {
        echo "基础校验失败\n";
        return false;
    }

    $oldMd5 = md5_file($manifestBin);
    if ($oldMd5 === false) {
        echo "读取原始 MD5 失败\n";
        return false;
    }

    // 补齐类名
    $normalizeComponentName = function ($pkg, $name) {
        $pkg = trim((string)$pkg);
        $name = trim((string)$name);

        if ($name === '') return '';

        if (strpos($name, '.') !== 0 && strpos($name, '.') !== false) {
            return $name;
        }

        if (strpos($name, '.') === 0) {
            return $pkg . $name;
        }

        return $pkg . '.' . $name;
    };

    $launcherComponent = null;

    try {
        // 解码
        $cmdDecode = sprintf(
            'java -jar %s d %s %s 2>&1',
            escapeshellarg($xml2axmlPath),
            escapeshellarg($manifestBin),
            escapeshellarg($manifestXml)
        );
        echo "执行解码命令：\n$cmdDecode\n";
        echo shell_exec($cmdDecode);

        if (!is_file($manifestXml)) {
            echo "解码失败\n";
            return false;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->load($manifestXml)) {
            echo "Manifest XML 解析失败\n";
            return false;
        }

        $manifestNode = $dom->getElementsByTagName('manifest')->item(0);
        $packageName = $manifestNode ? $manifestNode->getAttribute('package') : '';

        $applicationNode = $dom->getElementsByTagName('application')->item(0);
        if (!$applicationNode) {
            echo "未找到 application 节点\n";
            return false;
        }

        /* ========= 第一步：读取 meta-data ========= */

        $metaDatas = $applicationNode->getElementsByTagName('meta-data');
        foreach ($metaDatas as $meta) {
            if ($meta->getAttribute('android:name') === 'SETUP_LAUNCHER_ACTIVITY') {
                $launcherComponent = $meta->getAttribute('android:value');
                break;
            }
        }

        if ($launcherComponent === null || $launcherComponent === '') {
            echo "未找到 SETUP_LAUNCHER_ACTIVITY meta-data\n";
            return false;
        }

        if ($packageName !== '') {
            $launcherComponent = $normalizeComponentName($packageName, $launcherComponent);
        }

        /* ========= 第二步：给指定 Activity / Alias 添加 LAUNCHER ========= */

        $addedLauncher = false;

        $addLauncherCategory = function ($componentNode) use ($dom) {
            $intentFilters = $componentNode->getElementsByTagName('intent-filter');
            foreach ($intentFilters as $intentFilter) {
                $categories = $intentFilter->getElementsByTagName('category');
                foreach ($categories as $category) {
                    if ($category->getAttribute('android:name') === 'android.intent.category.LAUNCHER') {
                        return true;
                    }
                }

                $category = $dom->createElement('category');
                $category->setAttribute('android:name', 'android.intent.category.LAUNCHER');
                $intentFilter->appendChild($category);
                return true;
            }

            // 没有 intent-filter，新建一个
            $intentFilter = $dom->createElement('intent-filter');

            $action = $dom->createElement('action');
            $action->setAttribute('android:name', 'android.intent.action.MAIN');

            $category = $dom->createElement('category');
            $category->setAttribute('android:name', 'android.intent.category.LAUNCHER');

            $intentFilter->appendChild($action);
            $intentFilter->appendChild($category);
            $componentNode->appendChild($intentFilter);

            return true;
        };

        foreach (['activity', 'activity-alias'] as $tag) {
            $nodes = $applicationNode->getElementsByTagName($tag);
            foreach ($nodes as $node) {
                $nameAttr = $node->getAttribute('android:name');
                $fullName = ($packageName !== '')
                    ? $normalizeComponentName($packageName, $nameAttr)
                    : $nameAttr;

                if ($fullName === $launcherComponent) {
                    $addLauncherCategory($node);
                    $addedLauncher = true;
                    break 2;
                }
            }
        }

        if (!$addedLauncher) {
            echo "未找到目标启动 Activity：{$launcherComponent}\n";
            return false;
        }

        /* ========= 第三步：移除指定 Activity 的 LAUNCHER ========= */

        $removeTarget = 'com.tencent.a.SetupInfoActivity';

        foreach ($applicationNode->getElementsByTagName('activity') as $activity) {
            $nameAttr = $activity->getAttribute('android:name');
            $fullName = ($packageName !== '')
                ? $normalizeComponentName($packageName, $nameAttr)
                : $nameAttr;

            if ($fullName !== $removeTarget) {
                continue;
            }

            $intentFilters = $activity->getElementsByTagName('intent-filter');
            foreach ($intentFilters as $intentFilter) {
                $categories = $intentFilter->getElementsByTagName('category');
                foreach ($categories as $category) {
                    if ($category->getAttribute('android:name') === 'android.intent.category.LAUNCHER') {
                        $intentFilter->removeChild($category);
                    }
                }
            }
        }

        // 保存 XML
        $dom->formatOutput = true;
        if ($dom->save($manifestXml) === false) {
            echo "保存 XML 失败\n";
            return false;
        }

        // 编码
        $cmdEncode = sprintf(
            'java -jar %s e %s %s 2>&1',
            escapeshellarg($xml2axmlPath),
            escapeshellarg($manifestXml),
            escapeshellarg($manifestBin)
        );
        echo "执行编码命令：\n$cmdEncode\n";
        echo shell_exec($cmdEncode);

        $newMd5 = md5_file($manifestBin);
        if ($newMd5 === false || $newMd5 === $oldMd5) {
            echo "MD5 未变化，修改失败\n";
            return false;
        }

        echo "已设置启动 Activity：{$launcherComponent}\n";
        return $launcherComponent;

    } finally {
        if (is_file($manifestXml)) {
            unlink($manifestXml);
            echo "已清理临时 Manifest\n";
        }
    }
}


/**
 * 移除启动 Activity 的 LAUNCHER 属性
 *
 * @param string $xml2axmlPath xml2axml.jar 路径
 * @param string $dir 包含 AndroidManifest.xml 的目录
 * @return string|false 成功返回 activity 的 android:name，失败返回 false
 */
function removeLauncherCategoryWithXml2Axml($xml2axmlPath, $dir)
{
    $manifestBin = rtrim($dir, '/\\') . '/AndroidManifest.xml';
    $manifestXml = rtrim($dir, '/\\') . '/AndroidManifest2.xml';

    if (!is_file($xml2axmlPath) || !is_file($manifestBin)) {
        echo "基础校验失败\n";
        return false;
    }

    $oldMd5 = md5_file($manifestBin);
    if ($oldMd5 === false) {
        echo "读取原始 MD5 失败\n";
        return false;
    }

    // 将 Activity 类名补齐为完整类名
    $normalizeComponentName = function ($pkg, $name) {
        $pkg = trim((string)$pkg);
        $name = trim((string)$name);

        if ($name === '') return '';

        // 已经是完整类名
        if (strpos($name, '.') !== 0 && strpos($name, '.') !== false) {
            return $name;
        }

        // 形如 ".MainActivity"
        if (strpos($name, '.') === 0) {
            return $pkg . $name;
        }

        // 形如 "MainActivity"（无点）
        return $pkg . '.' . $name;
    };

    $handledComponentName = null; // 最终只返回这一个（补齐后的）

    try {
        // 解码
        $cmdDecode = sprintf(
            'java -jar %s d %s %s 2>&1',
            escapeshellarg($xml2axmlPath),
            escapeshellarg($manifestBin),
            escapeshellarg($manifestXml)
        );
        echo "执行解码命令：\n$cmdDecode\n";
        echo shell_exec($cmdDecode);

        if (!is_file($manifestXml)) {
            echo "解码失败，未生成 AndroidManifest2.xml\n";
            return false;
        }

        // 解析 XML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->load($manifestXml)) {
            echo "AndroidManifest2.xml 不是合法 XML\n";
            return false;
        }

        // 取 manifest package 作为补齐包名依据
        $manifestNode = $dom->getElementsByTagName('manifest')->item(0);
        $packageName = $manifestNode ? $manifestNode->getAttribute('package') : '';
        if ($packageName === '') {
            echo "未获取到 manifest package，无法补齐相对类名\n";
            // 这里不 return，仍然可继续处理，只是无法补齐
        }

        $applications = $dom->getElementsByTagName('application');
        if ($applications->length === 0) {
            echo "未找到 <application> 节点\n";
            return false;
        }

        $applicationNode = $applications->item(0);

        /* ========= ① 优先处理 <activity> ========= */

        $activities = $applicationNode->getElementsByTagName('activity');
        foreach ($activities as $activity) {
            $intentFilters = $activity->getElementsByTagName('intent-filter');
            foreach ($intentFilters as $intentFilter) {
                $categories = $intentFilter->getElementsByTagName('category');
                foreach ($categories as $category) {
                    if ($category->getAttribute('android:name') === 'android.intent.category.LAUNCHER') {

                        $rawName = $activity->getAttribute('android:name');
                        $handledComponentName = ($packageName !== '')
                            ? $normalizeComponentName($packageName, $rawName)
                            : $rawName;

                        // 删除 LAUNCHER
                        $intentFilter->removeChild($category);

                        if (!$intentFilter->hasChildNodes()) {
                            $activity->removeChild($intentFilter);
                        }

                        break 3; // 只处理第一个
                    }
                }
            }
        }

        /* ========= ② 如果未处理 activity，再处理 activity-alias ========= */

        if ($handledComponentName === null) {
            $aliases = $applicationNode->getElementsByTagName('activity-alias');
            foreach ($aliases as $alias) {

                if ($alias->getAttribute('android:enabled') === 'false') {
                    continue;
                }

                $intentFilters = $alias->getElementsByTagName('intent-filter');
                foreach ($intentFilters as $intentFilter) {
                    $categories = $intentFilter->getElementsByTagName('category');
                    foreach ($categories as $category) {
                        if ($category->getAttribute('android:name') === 'android.intent.category.LAUNCHER') {

                            $rawTarget = $alias->getAttribute('android:targetActivity');
                            $handledComponentName = ($packageName !== '')
                                ? $normalizeComponentName($packageName, $rawTarget)
                                : $rawTarget;

                            // 禁用该 alias
                            $alias->setAttribute('android:enabled', 'false');

                            break 3; // 只处理第一个
                        }
                    }
                }
            }
        }

        if ($handledComponentName === null || $handledComponentName === '') {
            echo "未找到任何 LAUNCHER Activity 或 activity-alias\n";
            return false;
        }

        // 保存 XML
        $dom->formatOutput = true;
        if ($dom->save($manifestXml) === false) {
            echo "保存 AndroidManifest2.xml 失败\n";
            return false;
        }

        // 编码
        $cmdEncode = sprintf(
            'java -jar %s e %s %s 2>&1',
            escapeshellarg($xml2axmlPath),
            escapeshellarg($manifestXml),
            escapeshellarg($manifestBin)
        );
        echo "执行编码命令：\n$cmdEncode\n";
        echo shell_exec($cmdEncode);

        // 校验 md5
        $newMd5 = md5_file($manifestBin);
        if ($newMd5 === false || $newMd5 === $oldMd5) {
            echo "MD5 未变化，修改失败\n";
            return false;
        }

        echo "已处理启动入口组件：{$handledComponentName}\n";
        return $handledComponentName;

    } finally {
        if (is_file($manifestXml)) {
            unlink($manifestXml);
            echo "已清理临时文件 AndroidManifest2.xml\n";
        }
    }
}


function removeLauncherCategoryWithXml2Axml2($xml2axmlPath, $dir)
{
    $manifestBin = rtrim($dir, '/\\') . '/AndroidManifest.xml';
    $manifestXml = rtrim($dir, '/\\') . '/AndroidManifest2.xml';

    if (!is_file($xml2axmlPath) || !is_file($manifestBin)) {
        echo "基础校验失败\n";
        return false;
    }

    $oldMd5 = md5_file($manifestBin);
    if ($oldMd5 === false) {
        echo "读取原始 MD5 失败\n";
        return false;
    }

    $removedActivityName = null;

    try {
        // 解码
        $cmdDecode = sprintf(
            'java -jar %s d %s %s 2>&1',
            escapeshellarg($xml2axmlPath),
            escapeshellarg($manifestBin),
            escapeshellarg($manifestXml)
        );

        echo "执行解码命令：\n$cmdDecode\n";
        echo shell_exec($cmdDecode);

        if (!is_file($manifestXml)) {
            echo "解码失败，未生成 AndroidManifest2.xml\n";
            return false;
        }

        // 解析 XML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->load($manifestXml)) {
            echo "AndroidManifest2.xml 不是合法 XML\n";
            return false;
        }

        $applications = $dom->getElementsByTagName('application');
        if ($applications->length === 0) {
            echo "未找到 <application> 节点\n";
            return false;
        }

        $applicationNode = $applications->item(0);
        $activities = $applicationNode->getElementsByTagName('activity');

        foreach ($activities as $activity) {
            $intentFilters = $activity->getElementsByTagName('intent-filter');
            foreach ($intentFilters as $intentFilter) {
                $categories = $intentFilter->getElementsByTagName('category');
                foreach ($categories as $category) {
                    if ($category->getAttribute('android:name') === 'android.intent.category.LAUNCHER') {

                        // 记录 activity 名称
                        $removedActivityName = $activity->getAttribute('android:name');

                        // 删除 category
                        $intentFilter->removeChild($category);

                        // 如果 intent-filter 为空则一并删除
                        if (!$intentFilter->hasChildNodes()) {
                            $activity->removeChild($intentFilter);
                        }

                        break 3;
                    }
                }
            }
        }

        if ($removedActivityName === null) {
            echo "未找到 LAUNCHER Activity\n";
            return false;
        }

        // 保存修改后的 XML
        $dom->formatOutput = true;
        if ($dom->save($manifestXml) === false) {
            echo "保存 AndroidManifest2.xml 失败\n";
            return false;
        }

        // 编码
        $cmdEncode = sprintf(
            'java -jar %s e %s %s 2>&1',
            escapeshellarg($xml2axmlPath),
            escapeshellarg($manifestXml),
            escapeshellarg($manifestBin)
        );

        echo "执行编码命令：\n$cmdEncode\n";
        echo shell_exec($cmdEncode);

        // 校验 md5
        $newMd5 = md5_file($manifestBin);
        if ($newMd5 === false || $newMd5 === $oldMd5) {
            echo "MD5 未变化，修改失败\n";
            return false;
        }

        echo "成功移除 LAUNCHER，Activity：{$removedActivityName}\n";
        return $removedActivityName;

    } finally {
        if (is_file($manifestXml)) {
            unlink($manifestXml);
            echo "已清理临时文件 AndroidManifest2.xml\n";
        }
    }
}


/**
 * 使用 xml2axml 向 AndroidManifest.xml 注入 activity
 *
 * @param string $xml2axmlPath xml2axml.jar 的完整路径
 * @param string $dir 包含 AndroidManifest.xml 的目录
 * @param string $activityXml activity 的 XML 明文（字符串）
 * @return bool 成功返回 true，失败返回 false
 */
function injectActivityWithXml2Axml($xml2axmlPath, $dir, $activityXml)
{
    $manifestBin = rtrim($dir, '/\\') . '/AndroidManifest.xml';
    $manifestXml = rtrim($dir, '/\\') . '/AndroidManifest2.xml';

    // 基础校验
    if (!is_file($xml2axmlPath) || !is_file($manifestBin)) {
        echo "基础校验失败\n";
        return false;
    }

    // 记录原始 md5
    $oldMd5 = md5_file($manifestBin);
    if ($oldMd5 === false) {
        echo "读取原始 MD5 失败\n";
        return false;
    }

    try {
        // 解码 AXML → XML
        $cmdDecode = sprintf(
            'java -jar %s d %s %s 2>&1',
            escapeshellarg($xml2axmlPath),
            escapeshellarg($manifestBin),
            escapeshellarg($manifestXml)
        );

        echo "执行解码命令：\n$cmdDecode\n";
        $decodeOutput = shell_exec($cmdDecode);
        echo "解码输出：\n$decodeOutput\n";

        if (!is_file($manifestXml)) {
            echo "解码失败，未生成 AndroidManifest2.xml\n";
            return false;
        }

        // 校验是否为合法 XML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->load($manifestXml)) {
            echo "AndroidManifest2.xml 不是合法 XML\n";
            return false;
        }

        // 找到 <application>
        $applications = $dom->getElementsByTagName('application');
        if ($applications->length === 0) {
            echo "未找到 <application> 节点\n";
            return false;
        }
        $applicationNode = $applications->item(0);

        // 解析 activity XML
        $activityDom = new DOMDocument();
        if (!$activityDom->loadXML($activityXml)) {
            echo "activity XML 非法\n";
            return false;
        }

        $activityNode = $activityDom->documentElement;
        $importedNode = $dom->importNode($activityNode, true);
        $applicationNode->appendChild($importedNode);

        // 保存修改后的 XML
        $dom->formatOutput = true;
        if ($dom->save($manifestXml) === false) {
            echo "保存 AndroidManifest2.xml 失败\n";
            return false;
        }

        // 编码 XML → AXML（覆盖原文件）
        $cmdEncode = sprintf(
            'java -jar %s e %s %s 2>&1',
            escapeshellarg($xml2axmlPath),
            escapeshellarg($manifestXml),
            escapeshellarg($manifestBin)
        );

        echo "执行编码命令：\n$cmdEncode\n";
        $encodeOutput = shell_exec($cmdEncode);
        echo "编码输出：\n$encodeOutput\n";

        // 校验 md5 是否变化
        $newMd5 = md5_file($manifestBin);
        if ($newMd5 === false || $newMd5 === $oldMd5) {
            echo "MD5 未变化，编码失败\n";
            return false;
        }

        echo "注入成功，MD5 已变化\n";
        return true;

    } finally {
        // 无论成功失败都删除临时 XML
        if (is_file($manifestXml)) {
            unlink($manifestXml);
            echo "已清理临时文件 AndroidManifest2.xml\n";
        }
    }
}


/**
 * 生成随机包名
 *
 * @param array $charPool 字符池，一项=一位字符（可为字母/数字/中文）
 * @return string
 */
function generateRandomPackageName($charPool, $segmentCount = 5, $segmentLength = 3)
{
    $segments = [];

    for ($i = 0; $i < $segmentCount; $i++) {

        // 70% 概率使用词汇段
        $useWord = !empty($charPool['words']) && rand(1, 100) <= 70;

        // ===== 词汇段：单独成段 =====
        if ($useWord) {
            $segments[] = $charPool['words'][array_rand($charPool['words'])];
            continue;
        }

        // ===== 字母 / 数字段（固定长度）=====
        $segment = '';

        for ($j = 0; $j < $segmentLength; $j++) {

            if ($j === 0) {
                // 首位不能是数字
                do {
                    $char = $charPool['letters'][array_rand($charPool['letters'])];
                } while (is_numeric($char));
            } else {
                $char = $charPool['letters'][array_rand($charPool['letters'])];
            }

            $segment .= $char;
        }

        $segments[] = $segment;
    }

    return implode('.', $segments);
}



//DEX中类名修改
function dexedit_rc($dexedit, $xmx, $dexpath, $oldClass, $newClass, $outputdex){
    $cmd = "nice -n 19 java -Xmx{$xmx} -jar "
            . escapeshellarg($dexedit)
            . " -rc "
            . escapeshellarg($dexpath) . " "
            . escapeshellarg($oldClass) . " "
            . escapeshellarg($newClass) . " "
            . escapeshellarg($outputdex)
            . " 2>&1";

    echo "执行命令：{$cmd}\n";
    $output = shell_exec($cmd);
    echo "dex包名重写结果{$output}\n";

    return $output;
}

//DEX中包名修改
function dexedit_rpk($dexedit, $xmx, $dexpath, $oldPackageWithClass, $newPackage, $outputdex){

    // 从旧包名中去掉类名，只保留包名
    // 例如：com.example.shell.App → com.example.shell
    if (strpos($oldPackageWithClass, '.') !== false) {
        $parts = explode('.', $oldPackageWithClass);
        array_pop($parts); // 去掉类名
        $oldPackage = implode('.', $parts);
    } else {
        // 理论上不会出现，没有点就原样用
        $oldPackage = $oldPackageWithClass;
    }

    $cmd = "nice -n 19 java -Xmx{$xmx} -jar "
            . escapeshellarg($dexedit)
            . " -rpk "
            . escapeshellarg($dexpath) . " "
            . escapeshellarg($oldPackage) . " "
            . escapeshellarg($newPackage) . " "
            . escapeshellarg($outputdex)
            . " 2>&1";

    echo "执行命令：{$cmd}\n";
    $output = shell_exec($cmd);
    echo "dex包名重写结果{$output}哦\n";

    return $output;
}


//APK中的dex重新划分
function dexedit_sd($dexedit, $xmx, $inputapk, $max, $outputapk){
    $cmd = "nice -n 19 java -Xmx{$xmx} -jar "
            . escapeshellarg($dexedit)
            . " -sd "
            . escapeshellarg($inputapk) . " "
            . escapeshellarg($max) . " "
            . escapeshellarg($outputapk) . " "
            . " 2>&1";
    echo "执行命令：{$cmd}\n";
    $output = shell_exec($cmd);
    echo "dex重新划分结果：{$output}\n";
    return $output;
}


//AES加密原始入口
function encrypt_text($text, $key = '1234567890abcdef') {
    echo "加密内容：{$text}\n";
    // 生成16字节随机IV
    $iv = openssl_random_pseudo_bytes(16);
    // 使用AES-128-CBC模式加密
    $encrypted = openssl_encrypt($text, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
    // 拼接IV和密文
    $output = $iv . $encrypted;
    // base64编码后输出
    return base64_encode($output);
}


//apk入口链路查询
function dexedit_printappchain($dexedit, $xmx, $inputapk, $className, $show_dex = false, $applicationlin){
    $showDexStr = $show_dex ? 'true' : 'false';

    $cmd = "nice -n 19 java -Xmx{$xmx} -jar "
        . escapeshellarg($dexedit)
        . " -printappchain "
        . escapeshellarg($inputapk) . " "
        . escapeshellarg($className) . " "
        . $showDexStr
        . " 2>&1";

    echo "执行命令：{$cmd}\n";
    $output = shell_exec($cmd);
    echo "原始链路：{$output}\n";

    // ⭐ 新增：过滤 applicationlin
    $filteredChain = filterAppChain($output, $applicationlin);

    if ($filteredChain === null) {
        echo "链路过滤后为空或非法\n";
        return null;
    }

    echo "过滤后的链路：{$filteredChain}\n";

    // 使用原解析逻辑
    return parseAppChainLastBeforeApplication($filteredChain);
}

/**
 * 从链路查询结果中解析
 * 返回 android.app.Application 前一个节点的 dex 和类名
 *
 * @param string $chain 链路字符串
 * @return array|null ['dex' => 'classes2.dex', 'class' => 'com.mx.App']
 */
function parseAppChainLastBeforeApplication(string $chain): ?array {
    // 按 -> 拆分链路
    $parts = explode('->', trim($chain));

    // 至少要有 2 层：xxx -> android.app.Application
    if (count($parts) < 2) {
        return null;
    }

    // 最后一段必须是 android.app.Application
    $last = trim(end($parts));
    if ($last !== 'android.app.Application') {
        return null;
    }

    // 取倒数第二段
    $prev = trim($parts[count($parts) - 2]);

    // 期望格式：classesX.dex:com.xxx.App
    if (strpos($prev, ':') === false) {
        return null;
    }

    [$dex, $class] = explode(':', $prev, 2);

    return [
        'dex'   => $dex,
        'class' => $class,
    ];
}
/**
 * 解析并过滤 Application 链路
 * 会移除 $applicationlin 中的类名节点
 *
 * @param string $chain dexedit 输出链路
 * @param array  $applicationlin 需要移除的类名数组
 * @return string|null 过滤后的新链路字符串
 */
function filterAppChain(string $chain, array $applicationlin): ?string {
    // 拆分链路
    $parts = array_map('trim', explode('->', trim($chain)));

    if (count($parts) < 2) {
        return null;
    }

    // 过滤掉 applicationlin 中的类
    $filtered = [];

    foreach ($parts as $part) {
        // android.app.Application 永远保留
        if ($part === 'android.app.Application') {
            $filtered[] = $part;
            continue;
        }

        // 期望格式：dex:class
        if (strpos($part, ':') === false) {
            continue;
        }

        [, $class] = explode(':', $part, 2);

        // 如果在 applicationlin 中，跳过
        if (in_array($class, $applicationlin, true)) {
            continue;
        }

        $filtered[] = $part;
    }

    // 过滤后仍需至少有 -> android.app.Application
    if (count($filtered) < 2 || end($filtered) !== 'android.app.Application') {
        return null;
    }

    return implode(' -> ', $filtered);
}







function gen_key($appId, $userId) {
    $secret = md5($appId . $userId);
    $plain  = $appId . $userId . $secret;

    return password_hash($plain, PASSWORD_DEFAULT);
}



/**
 * 生成一个伪造的 ELF 开头的二进制字符串（可作为虚假的 android .so 文件）
 *
 * @param int $size  目标字节大小，默认 30720（30 KB）
 * @return string    返回二进制字符串（包含 ELF 魔数）
 */
function generate_fake_so($size = 30720) {
    // 如果传入的大小不合理，回退到默认 30KB
    if (!is_int($size) || $size < 64) {
        $size = 30720;
    }

    // ----- 构造 ELF 识别段（e_ident） -----
    // 0x7F 'E' 'L' 'F'
    // 接着（举例）使用 ELF64 (0x02)、小端序 (0x01)、版本 (0x01)
    // 然后填充 9 字节为 0（与 ELF e_ident 共 16 字节）
    $e_ident = "\x7fELF" . "\x02\x01\x01" . str_repeat("\x00", 9); // 共 16 字节

    // ----- 简单填充其余 ELF header 字段（仅作伪造，不保证真实可执行） -----
    // 为简单起见，这里只补齐到 64 字节的 ELF header 大小（常见 ELF64 header 大小）
    // 真实 ELF header 含有具体字段，这里用 0 填充（足以让文件看起来像 ELF）
    $fake_header_rest = str_repeat("\x00", 64 - strlen($e_ident)); // 填充到 64 字节

    $header = $e_ident . $fake_header_rest; // 现在 header 长度为 64 字节

    // ----- 剩余部分生成“乱码”数据 -----
    $remaining = $size - strlen($header);
    if ($remaining < 0) $remaining = 0;

    // 优先使用 cryptographically secure 的 random_bytes，如果不可用则降级
    try {
        $body = random_bytes($remaining);
    } catch (Throwable $t) {
        // PHP7 以下或 random_bytes 不可用时尝试 openssl_random_pseudo_bytes
        if (function_exists('openssl_random_pseudo_bytes')) {
            $body = openssl_random_pseudo_bytes($remaining);
            if ($body === false) {
                // 最后退回到伪随机填充（不可逆，但能生成长度）
                $body = '';
                for ($i = 0; $i < $remaining; $i++) {
                    // 生成可见/不可见混合的字节
                    $body .= chr(mt_rand(0, 255));
                }
            }
        } else {
            // 兜底：使用 mt_rand 生成
            $body = '';
            for ($i = 0; $i < $remaining; $i++) {
                $body .= chr(mt_rand(0, 255));
            }
        }
    }

    // 返回完整的伪 ELF 二进制字符串
    return $header . $body;
}







/**
 * 将目录2中的dex文件（classes.dex除外）复制到目录1，并根据目录1中现有dex文件递增命名
 *
 * @param string $dir1 目标目录
 * @param string $dir2 源目录
 * @return array 返回复制前后文件名映射，例如 ['classes2.dex' => 'classes5.dex']
 */
function mergeDexFiles($dir1, $dir2) {
    $copyMap = []; // 用于记录复制详情

    // 获取目录1中所有标准命名的dex文件，确定最大数字
    $files1 = scandir($dir1);
    $maxNum = 1; // 默认classes.dex对应1
    foreach ($files1 as $file) {
        if (preg_match('/^classes(\d*)\.dex$/', $file, $matches)) {
            $num = isset($matches[1]) && $matches[1] !== '' ? intval($matches[1]) : 1;
            if ($num > $maxNum) {
                $maxNum = $num;
            }
        }
    }

    // 遍历目录2的标准命名dex文件
    $files2 = scandir($dir2);
    foreach ($files2 as $file) {
        if ($file === 'classes.dex') {
            continue; // 不复制classes.dex
        }
        if (preg_match('/^classes(\d*)\.dex$/', $file)) {
            $maxNum++;
            $newName = 'classes' . $maxNum . '.dex';
            copy($dir2 . DIRECTORY_SEPARATOR . $file, $dir1 . DIRECTORY_SEPARATOR . $newName);
            $copyMap[$file] = $newName; // 记录复制详情
        }
    }

    return $copyMap;
}
//AndroidManifest伪加密
function editApkManifestHeaderAndOffset($apkPath) {
    if (!file_exists($apkPath)) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($apkPath) !== true) {
        return false;
    }

    $index = $zip->locateName('AndroidManifest.xml', ZipArchive::FL_NOCASE);
    if ($index === false) {
        $zip->close();
        return false;
    }

    // 读取原始文件内容
    $content = $zip->getFromIndex($index);
    if ($content === false || strlen($content) < 36) { // 至少要有 36 字节
        $zip->close();
        return false;
    }

    $changed = false;

    // 修改头部前 4 个字节
    $targetHeader = hex2bin('03000800');
    $newHeader = hex2bin('00000800');
    if (substr($content, 0, 4) === $targetHeader) {
        $content = $newHeader . substr($content, 4);
        $changed = true;
    }

    // 修改偏移 0x20 (32) 位置的 4 个字节
    $offset20 = 32; // 0x20
    $targetOffset = hex2bin('00000000');
    $newOffset = hex2bin('00EEFFEE');

    if (substr($content, $offset20, 4) === $targetOffset) {
        $content = substr($content, 0, $offset20) . $newOffset . substr($content, $offset20 + 4);
        $changed = true;
    }

    // 如果有修改，则写回 APK
    if ($changed) {
        $zip->deleteName('AndroidManifest.xml'); // 删除原文件
        $zip->addFromString('AndroidManifest.xml', $content); // 写入修改后的内容
    }

    $zip->close();
    return $changed;
}



/*function jiagu($jiagu, $xmx, $inputApk, $outputApk){
    $cmd = "nice -n 19 java -Xmx{$xmx} -jar " . escapeshellarg($jiagu) . " -f {$inputApk} -o {$outputApk} --no-sign 2>&1";
    echo "执行APK加固命令{$cmd}\n";
    $output = shell_exec($cmd);
    echo "加固结果：\n{$output}\n";
    if(!file_exists($outputApk)){
        return false;
    }else{
        return true;
    }
    
}*/
//20260301修复
function jiagu($jiagu, $xmx, $inputApk, $outputApk){

    // 校验 Xmx 参数
    if (!preg_match('/^\d+M$/', $xmx)) {
        echo "非法Xmx参数\n";
        return false;
    }

    // 规范路径
    $jiagu = realpath($jiagu);
    $inputApk = realpath($inputApk);

    if ($jiagu === false || $inputApk === false) {
        echo "非法路径\n";
        return false;
    }

    // 输出路径单独处理（允许新文件）
    $outputApk = dirname($inputApk) . DIRECTORY_SEPARATOR . basename($outputApk);

    $cmd = sprintf(
        '%s -n 19 %s -Xmx%s -jar %s -f %s -o %s --no-sign 2>&1',
        escapeshellcmd('nice'),
        escapeshellcmd('java'),
        $xmx,
        escapeshellarg($jiagu),
        escapeshellarg($inputApk),
        escapeshellarg($outputApk)
    );

    echo "执行APK加固命令\n";

    $output = shell_exec($cmd);
    echo "加固结果：\n{$output}\n";

    if(!file_exists($outputApk)){
        return false;
    }else{
        return true;
    }
}

function dexedit_ism($dexedit, $xmx, $inputdex, $className, $targetclassName, $method, $outputdex = null) {

    if (empty($outputdex)) {
        $cmd = "nice -n 19 java -Xmx{$xmx} -jar "
            . escapeshellarg($dexedit)
            . " -ism "
            . escapeshellarg($inputdex) . " "
            . escapeshellarg($className) . " "
            . escapeshellarg($targetclassName) . " "
            . escapeshellarg($method)
            . " 2>&1";
    } else {
        $cmd = "nice -n 19 java -Xmx{$xmx} -jar "
            . escapeshellarg($dexedit)
            . " -ism "
            . escapeshellarg($inputdex) . " "
            . escapeshellarg($className) . " "
            . escapeshellarg($targetclassName) . " "
            . escapeshellarg($method) . " "
            . escapeshellarg($outputdex)
            . " 2>&1";
    }

    echo "执行命令：{$cmd}\n";

    $output = shell_exec($cmd);

    echo "注入静态代码结果：{$output}\n";

    return $output;
}


//在apk里找指定的类
function dexedit_ac($dexedit, $xmx, $inputApk, $className){
    if (!preg_match('/^[A-Za-z0-9._]+$/', $className)) {
        //echo "类名格式非法：只能包含字母、数字、点号和下划线\n";
        //return false;
    }
    //$cmd = "java -jar " . escapeshellarg($dexedit) . " -ac {$inputApk} {$className} 2>&1";
    $cmd = "java -jar " . escapeshellarg($dexedit) . " -ac " . escapeshellarg($inputApk) . " " . escapeshellarg($className) . " 2>&1";
    echo "执行命令{$cmd}\n";
    $output = shell_exec($cmd);
    echo "结果:{$output}\n";
    if($output == "false\n"){//因为jar库输出的时候就是以换行结尾，所以这里判断也要带换行
        echo "返回无\n";
        return false;
    }else{
        echo "返回{$output}\n";
        return str_replace("\n","",$output);
    }
}
//查找dex文件的父类
function dexedit_ds($dexedit, $xmx, $inputdex, $className){
    if (!preg_match('/^[A-Za-z0-9._]+$/', $className)) {
        //echo "类名格式非法：只能包含字母、数字、点号和下划线\n";
        //return false;
    }
    //$cmd = "java -jar " . escapeshellarg($dexedit) . " -ds {$inputdex} {$className} 2>&1";
    $cmd = "java -jar " . escapeshellarg($dexedit) . " -ds " . escapeshellarg($inputdex) . " " . escapeshellarg($className) . " 2>&1";

    echo "执行命令{$cmd}\n";
    $output = shell_exec($cmd);
    echo "结果:{$output}\n";
    if($output == "false\n"){//因为jar库输出的时候就是以换行结尾，所以这里判断也要带换行
        echo "返回无\n";
        return false;
    }else{
        echo "返回{$output}\n";
        return str_replace("\n","",$output);
    }
}
//修改指定dex的指定类的父类
function dexedit_ms($dexedit, $xmx, $inputdex, $className, $NewclassName){
    /*if (!preg_match('/^[A-Za-z0-9._]+$/', $className)) {
        echo "类名格式非法：只能包含字母、数字、点号和下划线\n";
        return false;
    }
    if (!preg_match('/^[A-Za-z0-9._]+$/', $NewclassName)) {
        echo "新类名格式非法：只能包含字母、数字、点号和下划线\n";
        return false;
    }*/
    //$cmd = "java -jar " . escapeshellarg($dexedit) . " -ms {$inputdex} {$className} {$NewclassName} 2>&1";
    $cmd = "java -jar " . escapeshellarg($dexedit) . " -ms " . escapeshellarg($inputdex) . " " . escapeshellarg($className) . " " . escapeshellarg($NewclassName) . " 2>&1";

    echo "执行命令{$cmd}\n";
    $output = shell_exec($cmd);
    echo "结果:{$output}\n";
    if($output == "true\n"){//因为jar库输出的时候就是以换行结尾，所以这里判断也要带换行
        echo "返回true\n";
        return true;
    }else{
        echo "返回{$output}\n";
        return $output;
    }
}








//取随机文本内容填充到特征文件中
function getRandomLyric() {
    // 歌词数组，每个元素可以包含多行文本
    $lyrics = [
        "马克思主义的道理千条万续，归根结底就是一句话，造反有理!\n\n出自：毛泽东语录、文革时期大字报",
        "农村包围城市，武装夺取政权。\n\n出自：毛泽东关于中国革命道路论述",
        "榜样的力量是无穷的。\n\n出自：毛泽东讲话",
        "这个军队具有一往无前的精神，它要压倒一切敌人，而决不被敌人所屈服!\n\n出自：毛泽东军事论述",
        "老人知事百事通。\n\n出自：毛泽东引用的民间俗语",
        "农村是一个广阔的天地，在那里是可以大有作为的。\n\n出自：毛泽东关于农村工作的讲话",
        "毛泽东一个人如果他不知道学习的重要，他永远也不会变的聪明!\n\n出自：毛泽东关于学习的论述",
        "党外无党，帝王思想，党内无派，千奇百怪。\n\n出自：毛泽东文稿",
        "忆往昔峥嵘岁月稠。恰同学少年，风华正茂;书生意气，挥斥方遒。\n\n出自：毛泽东《沁园春·长沙》",
        "敌人一天天烂下去，我们一天天好起来毛泽东国共和谈谈拢的希望一丝一毫也没有。\n\n出自：毛泽东讲话",
        "遇事不怒，基本吃素，多多散步，劳逸适度。\n\n出自：毛泽东生活习惯和谈话",
        "人总是要死的，但死的意义有不同……张思德同志是为人民利益而死的，他的死是比泰山还要重。\n\n出自：毛泽东《为人民服务》",
        "只有决战，才能解决两军之间谁胜谁败的问题。\n\n出自：毛泽东军事论述",
        "封锁吧!封锁它十年、八年，中国的一切问题都解决了!\n\n出自：毛泽东谈对外政策",
        "核战争打不起来。\n\n出自：毛泽东战略论述",
        "世界是你们的，也是我们的，但是归根结底是你们的……\n\n出自：毛泽东《给青年人的寄语》",
        "敌进我退，敌驻我扰，敌疲我打，敌退我追。\n\n出自：毛泽东军事策略",
        "不打无准备之战。\n\n出自：毛泽东军事策略",
        "人不犯我，我不犯人。\n\n出自：毛泽东军事思想",
        "一切反动派都是纸老虎!\n\n出自：毛泽东讲话",
        "兵民是胜利之本。\n\n出自：毛泽东军事论述",
        "中国人民从此站起来了!\n\n出自：毛泽东《新民主主义论》",
        "一个人做点好事并不难，难的是一辈子做好事……\n\n出自：毛泽东讲话",
        "不管风吹浪打，胜似闲庭信步。\n\n出自：毛泽东诗句",
        "天要下雨，娘要嫁人，由他去吧!\n\n出自：民间谚语，毛泽东引用",
        "敌人有的，我们要有，敌人没有的，我们也要有……\n\n出自：毛泽东关于核武器发展讲话",
        "数风流人物，还看今朝!\n\n出自：毛泽东《念奴娇·昆仑》",
        "搞一点原子弹、氢弹、洲际导弹，我看十年完全可能。\n\n出自：毛泽东谈核武器发展",
        "戴高乐上台也有好处，他喜欢跟英美闹别扭。\n\n出自：毛泽东谈国际形势",
        "古为今用，洋为中用，百花齐放，推陈出新。\n\n出自：毛泽东文艺方针",
        "指点江山，激扬文字，粪土当年万户侯!\n\n出自：毛泽东《沁园春·长沙》",
        "彻底的唯物主义是无所畏惧的。\n\n出自：毛泽东哲学论述",
        "没有调查就没有发言权。\n\n出自：毛泽东《在延安文艺座谈会上的讲话》",
        "人是要有帮助的……一个好汉要有三个帮。\n\n出自：毛泽东引用民间俗语",
        "毛泽东我们大家要学习他毫无自私自利之心的精神……\n\n出自：毛泽东讲话",
        "文字需须改革，要走世界文字共同的拼音方向。\n\n出自：毛泽东文稿",
        "历史是人民创造的。\n\n出自：毛泽东哲学思想",
        "前途是光明的，道路是曲折的。\n\n出自：毛泽东《实践论》",
        "虚心使人进步，骄傲使人落后，我们应当永远记住这个真理。\n\n出自：毛泽东讲话",
        "人民靠我们去组织，中国的反动分子，靠我们组织起人民去把他打倒……\n\n出自：毛泽东军事策略",
        "打得赢就打，打不赢就走。\n\n出自：毛泽东军事策略",
        "星星之火，可以燎原!\n\n出自：毛泽东《星星之火，可以燎原》",
        "毛泽东获得知识的道路就是要努力学习。\n\n出自：毛泽东学习论述",
        "把别人的经验变成自己的，他的本事就大了。\n\n出自：毛泽东讲话",
        "历史的发展是不以人的意志为转移的。\n\n出自：毛泽东哲学思想",
        "不到长城非好汉。\n\n出自：毛泽东《清平乐·六盘山》",
        "好好学习，天天向上。\n\n出自：毛泽东在少年儿童教育中提出",
        "妇女要顶半边天。\n\n出自：毛泽东关于妇女解放讲话",
        "革命不是请客吃饭……\n\n出自：毛泽东《湖南农民运动考察报告》",
        "自立更生，艰苦奋斗。\n\n出自：毛泽东讲话",
        "一切帝国主义和反动派都是纸老虎。\n\n出自：毛泽东讲话",
        "鸡蛋因适当的温度而变化为鸡，但温度不能使石头变为鸡。\n\n出自：毛泽东哲学比喻",
        "你要知道梨子的味道!你就得变革梨子!亲口吃一吃。\n\n出自：毛泽东比喻讲话",
        "我们一定要解放台湾!\n\n出自：毛泽东对台湾问题讲话",
        "自己动手，丰衣足食!\n\n出自：毛泽东生活与劳动教育",
        "孩儿立志出乡关，学不成名誓不还。\n\n出自：古诗，毛泽东引用",
        "当正确的政策方针制定之后，干部是关键!\n\n出自：毛泽东讲话",
        "牢骚太盛防肠断，风物长宜放眼量。\n\n出自：毛泽东引用古诗",
        "不须放屁，试看天地翻覆!\n\n出自：毛泽东讲话",
        "对于人，伤其十指不如断其一指;对于敌，击溃其十个师不如歼灭其一个师。\n\n出自：毛泽东军事论述",
        "谦虚使人进步，骄傲使人落后。\n\n出自：毛泽东讲话",
        "为有牺牲多壮志，敢教日月换新天。\n\n出自：毛泽东《七律·长征》",
        "毛泽东学习的敌人是自己的满足……\n\n出自：毛泽东关于学习的论述",
        "罗斯福将会使美国参加二战。\n\n出自：毛泽东国际形势判断",
        "袭击是游击战争的基本作战形式。\n\n出自：毛泽东军事论述",
        "你们怎么办，只有天知道。\n\n出自：毛泽东谈国际局势",
        "歼灭战和集中优势兵力、采取包围迂回战术，同一意义……\n\n出自：毛泽东军事论述",
        "调查就象十月怀胎，解决问题就象一朝分娩。\n\n出自：毛泽东《在延安整风运动中的讲话》",
        "没有文化的军队是愚蠢的军队，而愚蠢的军队是不能战胜敌人的!\n\n出自：毛泽东军事思想",
        "你办事，我放心。\n\n出自：毛泽东讲话",
        "为人民服务!\n\n出自：毛泽东《为人民服务》",
        "一个正确的认识，往往需要经过由物质到精神，由精神到物质……\n\n出自：毛泽东哲学论述",
        "决定战争胜负的是人，而不是物。\n\n出自：毛泽东军事论述",
        "核潜艇一万年也要搞出来。\n\n出自：毛泽东军事与国防策略",
        "在战略上要藐视敌人，在战术上要重视敌人!\n\n出自：毛泽东军事策略",
        "中国人不怕原子弹，死一半也没什么，照样接着搞社会主义。\n\n出自：毛泽东对核武器态度",
        "一万年太久，只争朝夕!\n\n出自：毛泽东《忆秦娥·娄山关》",
        "枪杆子里面出政权!\n\n出自：毛泽东《论人民民主专政》",
        "不要枪杆子，需须拿起枪杆子!\n\n出自：毛泽东军事思想",
        "多少事，从来急，天地转，光阴迫，一万年太久，只争朝夕。\n\n出自：毛泽东《忆秦娥·娄山关》",
        "陈毅是个好同志，他对中国革命和世界革命所作的贡献，是已经下了结论的。\n\n出自：毛泽东讲话",
        "世界上怕就怕认真二字，我就最讲认真!\n\n出自：毛泽东谈工作态度",
        "毛泽东外因是变化的条件，内因是变化的根据……\n\n出自：毛泽东哲学论述",
        "苟有恒，何需三更起五更眠;最无益，只怕一日曝十日寒。\n\n出自：毛泽东引用古训",
        "苍山如海，残阳如血!\n\n出自：毛泽东《七律·长征》",
        "教育者需须先受教育。\n\n出自：毛泽东教育理念",
        "基本粒子也是可分的。\n\n出自：毛泽东对科学问题谈话",
        "雄关漫道真如铁，而今迈步从头越!\n\n出自：毛泽东《忆秦娥·娄山关》",
        "加强纪律性，革命无不胜。\n\n出自：毛泽东讲话",
        "什么人站在革命人民方面，他就是革命派……\n\n出自：毛泽东关于阶级和革命派的论述",
        "人民万岁!\n\n出自：毛泽东讲话",
        "路线是个纲，纲举目张。\n\n出自：毛泽东政治论述",
        "春风杨柳万千条，六亿神州尽舜尧。\n\n出自：毛泽东《七律·人民解放军占领南京》",
        "这只是万里长征的第一步!\n\n出自：毛泽东讲话",
        "汽笛一声肠已断，从此天涯孤旅!\n\n出自：毛泽东《浪淘沙·北戴河》",
        "形式主义害死人。\n\n出自：毛泽东谈工作作风",
        "文明其精神，野蛮其体魄!\n\n出自：毛泽东教育理念",
        "军民团结如一人，试看天下谁能敌!\n\n出自：毛泽东军事论述",
        "独立寒秋，湘江北去，橘子洲头。\n\n出自：毛泽东《沁园春·长沙》",
        "愚公移山，人定胜天\n\n出自：毛泽东引用古代寓言"
    ];

    // 随机选择一个索引
    $index = array_rand($lyrics);

    // 返回对应歌词
    return $lyrics[$index];
}





//APK数据复用优化
function optimizeApk($ApkDataMultiplexing, $inputApk, $outputApk, $entryPath, $keystorePath, $storepass, $alias, $keypass) {
    // 构建命令行
    $cmd = escapeshellcmd("java -jar") . ' ' .
        escapeshellarg($ApkDataMultiplexing) . ' ' .
        escapeshellarg($inputApk) . ' ' .
        escapeshellarg($entryPath) . ' ' .
        escapeshellarg($outputApk) . ' ' .
        escapeshellarg($keystorePath) . ' ' .
        escapeshellarg($storepass) . ' ' .
        escapeshellarg($alias) . ' ' .
        escapeshellarg($keypass);
    echo "执行APK数据优化命令：{$cmd}\n";
    // 执行命令
    $output = shell_exec($cmd);
    echo $output;
    // 检查输出文件是否存在，判断是否执行成功
    if (!file_exists($outputApk) || strpos($output, "验证结果") == false) {
        // 清理可能残留的损坏输出文件，防止后续 sign_apk 误判为成功
        if (file_exists($outputApk)) {
            @unlink($outputApk);
            echo "已清理优化失败的残留文件：{$outputApk}\n";
        }
        return false;
    }

    // 删除原始输入文件
    @unlink($inputApk);

    return $outputApk;
}


//复制解密库到lib
function processAndCopyDEXSo($sourceDir, $targetDir) {
    $abiList = ['armeabi-v7a', 'x86', 'arm64-v8a', 'x86_64'];
    $targetLibDir = $targetDir . '/lib';

    $existingAbis = [];
    if (is_dir($targetLibDir)) {
        foreach (scandir($targetLibDir) as $entry) {
            if (in_array($entry, $abiList) && is_dir($targetLibDir . '/' . $entry)) {
                $existingAbis[] = $entry;
            }
        }
    }

    // 如果没有现成的 ABI 子目录，就复制全部
    $abisToCopy = empty($existingAbis) ? $abiList : $existingAbis;

    foreach ($abisToCopy as $abi) {
        $srcSo = $sourceDir . '/lib/' . $abi . '/libnative-cainiao.so';
        $dstDir = $targetLibDir . '/' . $abi;
        $dstSo = $dstDir . '/libnative-cainiao.so';

        if (is_file($srcSo)) {
            if (!is_dir($dstDir) && !mkdir($dstDir, 0777, true)) {
                return false;
            }
            if (!copy($srcSo, $dstSo)) {
                return false;
            }
        }
    }

    return true;
}



// 在 dex 文件头部追加随机字节的加密方法
function DEXEncryptStream($inputFile, $outputFile, $keyString) {
    $in = fopen($inputFile, 'rb');
    $out = fopen($outputFile, 'wb');
    if (!$in || !$out) {
        echo "文件打开失败: $inputFile 或 $outputFile\n";
        return false;
    }

    // 生成随机长度的乱码字节（1000-10000字节）
    $randLen = random_int(1000, 10000);
    $randomBytes = random_bytes($randLen);

    // 将随机长度写入 key 文件
    $keyPath = preg_replace('/\.enc$/', '.key', $outputFile);
    if (file_put_contents($keyPath, $randLen) === false) {
        echo "写入 key 文件失败: $keyPath\n";
        fclose($in);
        fclose($out);
        return false;
    }

    // 先写入随机字节到输出文件
    fwrite($out, $randomBytes);

    // 直接复制原 dex 文件内容到输出文件
    while (!feof($in)) {
        $chunk = fread($in, 1048576); // 1MB
        if ($chunk === false) break;
        fwrite($out, $chunk);
    }

    fclose($in);
    fclose($out);

    echo "加密完成: $outputFile，随机字节长度: $randLen\n";
    return true;
}

// 修改后的目录处理方法
function processDexDirectory($dir, $keyString) {
    echo "开始处理目录: $dir\n";

    if (!is_dir($dir)) {
        echo "目录不存在: $dir\n";
        return false;
    }

    $assetsDir = rtrim($dir, '/\\') . '/assets';
    if (!is_dir($assetsDir)) {
        if (!mkdir($assetsDir, 0755, true)) {
            echo "创建 assets 目录失败: $assetsDir\n";
            return false;
        } else {
            echo "已创建目录: $assetsDir\n";
        }
    }

    $files = glob(rtrim($dir, '/\\') . '/classes*.dex');
    if (empty($files)) {
        echo "未找到任何 classes*.dex 文件\n";
        return false;
    }

    usort($files, function ($a, $b) {
        return strnatcmp($a, $b);
    });

    $total = count($files);
    echo "共找到 $total 个 dex 文件\n";

    // 加密前面的 dex 文件
    for ($i = 0; $i < $total - 1; $i++) {
        $file = $files[$i];
        $basename = basename($file);
        $index = $i + 1;
        $outputFile = $assetsDir . "/cainiao-$index.enc";

        echo "正在加密: $basename\n";
        if (!DEXEncryptStream($file, $outputFile, $keyString)) {
            echo "加密失败: $file\n";
            return false;
        }

        echo "已保存加密文件: $outputFile\n";

        if (!unlink($file)) {
            echo "删除原始 dex 文件失败: $file\n";
            return false;
        } else {
            echo "已删除原始 dex 文件: $file\n";
        }
    }

    // 保留最后一个 dex 文件为 classes.dex
    $lastFile = $files[$total - 1];
    $newPath = rtrim($dir, '/\\') . '/classes.dex';
    if (realpath($lastFile) !== realpath($newPath)) {
        if (!rename($lastFile, $newPath)) {
            echo "重命名失败: $lastFile 到 $newPath\n";
            return false;
        } else {
            echo "已将 $lastFile 重命名为 classes.dex\n";
        }
    } else {
        echo "最后一个文件已是 classes.dex，无需重命名\n";
    }

    echo "处理完成\n";
    return true;
}


/**
 * 解密一个通过 simpleEncrypt 加密的文件，返回原始二进制内容。
 *
 * 调用示例：
 * 
 * try {
 *     $encFile = '/path/to/encrypted.apk';       // 加密后的APK路径
 *     $keyFile = '/path/to/secret.key';           // 对应的密钥文件路径
 *     $rawData = simpleDecrypt($encFile, $keyFile); // 解密为原始数据（如APK文件内容）
 *     file_put_contents('/path/to/decrypted.apk', $rawData); // 保存为解密后的文件
 * } catch (Exception $e) {
 *     echo "解密失败：" . $e->getMessage();
 * }
 *
 * @param string $encFile 加密文件路径
 * @param string $keyFile 密钥文件路径
 * @return string 解密后的二进制数据
 * @throws Exception 如果文件不存在或读取失败
 */
function DEXDecrypt($encFile, $keyFile) {
    // 读取密钥文件
    if (!file_exists($keyFile)) {
        throw new Exception("密钥文件不存在: $keyFile");
    }

    $key = file_get_contents($keyFile);
    if ($key === false || strlen($key) === 0) {
        throw new Exception("密钥文件读取失败或内容为空");
    }

    $keyLen = strlen($key);

    // 读取加密文件
    if (!file_exists($encFile)) {
        throw new Exception("加密文件不存在: $encFile");
    }

    $data = file_get_contents($encFile);
    if ($data === false || strlen($data) === 0) {
        throw new Exception("加密文件读取失败或内容为空");
    }

    $buf = array_values(unpack("C*", $data)); // 转为字节数组
    $rounds = 20;

    // 解密过程（反向 20 轮）
    for ($r = $rounds - 1; $r >= 0; $r--) {
        // 反转数组
        $buf = array_reverse($buf);

        for ($i = 0; $i < count($buf); $i++) {
            $k = ord($key[$i % $keyLen]);
            $b = $buf[$i];

            // 位交换（高4位和低4位互换）
            $b = (($b & 0xF0) >> 4) | (($b & 0x0F) << 4);
            $b = ($b - ($k ^ $r)) & 0xFF;
            $b = $b ^ $k;

            $buf[$i] = $b;
        }
    }

    // 返回二进制数据
    return pack("C*", ...$buf);
}




















//APK合并方法
/**
 * 将解包目录中的 AndroidManifest.xml、所有 dex 文件、lib 目录、assets 下指定文件与指定目录
 * 覆盖写入到临时apk中（先把原始apk复制到临时目录作为临时apk），仅使用 zip 命令实现。
 *
 * @param string $apkPath 原始apk文件路径
 * @param string $tmpDir 临时目录路径（函数会把apk复制到这里，作为临时apk）
 * @param string $unpackDir 解包目录（包含AndroidManifest.xml、*.dex、lib、assets等）
 * @param array $assetFiles 指定要覆盖到 apk 的 assets 下的“文件”列表（相对 assets 的路径，如 ['config.json','sub/a.txt']）
 * @param array $assetDirs 指定要覆盖到 apk 的 assets 下的“目录”列表（相对 assets 的路径，如 ['bundle','packs/extra']）
 * @return string|false 成功返回覆盖后的临时apk完整路径，失败返回false
 */
function patchApk($apkPath, $tmpDir, $unpackDir, array $assetFiles = ['cainiao_vip.so','yunzhuru.com','yunzhuru.pkg','yunzhuru.sig', '声明.txt'], array $assetDirs = ['yunzhuru'])
{
    $runCmd = function (string $cmd) {
        echo "执行命令：{$cmd}\n";
        $output = shell_exec($cmd . ' 2>&1; printf "__EXIT_CODE:%s" $?');
        if ($output === null) {
            echo "命令执行失败：shell_exec返回null\n";
            return [1, ""];
        }

        $outputTrim = rtrim($output, "\r\n\0\x0B\t ");
        $code = 1;
        if (preg_match_all('/__EXIT_CODE:(\d+)/', $outputTrim, $m) && !empty($m[1])) {
            $code = (int) end($m[1]);
            $std = preg_replace('/\s*__EXIT_CODE:\d+\s*$/', '', $outputTrim);
        } else {
            $std = $outputTrim;
        }

        if ($std !== '') {
            echo "命令输出：\n{$std}\n";
        }
        echo "命令退出码：{$code}\n";
        return [$code, $std];
    };

    echo "开始执行patchApk...\n";
    if (!is_file($apkPath)) {
        echo "错误：apk文件不存在：{$apkPath}\n";
        return false;
    }
    if (!is_dir($tmpDir)) {
        echo "临时目录不存在，尝试创建：{$tmpDir}\n";
        if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            echo "错误：无法创建临时目录：{$tmpDir}\n";
            return false;
        }
    }
    if (!is_dir($unpackDir)) {
        echo "错误：解包目录不存在：{$unpackDir}\n";
        return false;
    }

    [$codeZip, $zipStdout] = $runCmd('zip -v');
    if (stripos($zipStdout, 'Zip') === false && stripos($zipStdout, 'Info-ZIP') === false) {
        echo "错误：系统未安装zip或不可用，请安装zip后重试。\n";
        return false;
    }

    $baseName = basename($apkPath);
    $tempApk = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $baseName;

    echo "复制apk到临时目录：{$apkPath} -> {$tempApk}\n";
    if (!@copy($apkPath, $tempApk)) {
        echo "错误：复制apk失败，请检查读写权限。\n";
        return false;
    }

    $entries = [];

    $manifest = rtrim($unpackDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';
    if (is_file($manifest)) {
        echo "发现AndroidManifest.xml，将参与覆盖。\n";
        $entries[] = 'AndroidManifest.xml';
    } else {
        echo "警告：未在解包目录找到AndroidManifest.xml，跳过此项覆盖。\n";
    }

    echo "扫描dex文件...\n";
    $dexRelative = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($unpackDir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if ($file->isFile() && preg_match('/\.dex$/i', $file->getFilename())) {
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($unpackDir, DIRECTORY_SEPARATOR)) + 1)), '/');
            $dexRelative[] = $rel;
        }
    }
    if ($dexRelative) {
        echo "发现dex文件共 " . count($dexRelative) . " 个，将参与覆盖。\n";
        $entries = array_merge($entries, $dexRelative);
    } else {
        echo "警告：未发现任何dex文件，跳过dex覆盖。\n";
    }

    $libDirAbs = rtrim($unpackDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lib';
    if (is_dir($libDirAbs)) {
        echo "发现lib目录，将递归覆盖。\n";
        $entries[] = 'lib';
    } else {
        echo "提示：未发现lib目录，跳过lib覆盖。\n";
    }

    if (!empty($assetFiles)) {
        echo "处理assets指定文件...\n";
        foreach ($assetFiles as $f) {
            $rel = 'assets/' . ltrim(str_replace('\\', '/', $f), '/');
            $abs = rtrim($unpackDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($abs)) {
                echo "确认存在assets文件：{$rel}\n";
                $entries[] = $rel;
            } else {
                echo "警告：指定的assets文件不存在，跳过：{$rel}\n";
            }
        }
    } else {
        echo "提示：未提供assets文件列表，跳过assets文件覆盖。\n";
    }

    if (!empty($assetDirs)) {
        echo "处理assets指定目录...\n";
        foreach ($assetDirs as $d) {
            $rel = 'assets/' . ltrim(str_replace('\\', '/', $d), '/');
            $abs = rtrim($unpackDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_dir($abs)) {
                echo "确认存在assets目录：{$rel}（将递归覆盖）\n";
                $entries[] = $rel;
            } else {
                echo "警告：指定的assets目录不存在，跳过：{$rel}\n";
            }
        }
    } else {
        echo "提示：未提供assets目录列表，跳过assets目录覆盖。\n";
    }

    $entries = array_values(array_unique($entries));

    if (empty($entries)) {
        echo "错误：没有任何可覆盖的条目，操作中止。\n";
        return false;
    }

    $libEntries = [];
    $otherEntries = [];

    foreach ($entries as $entry) {
        if (stripos($entry, 'lib') === 0) {
            $libEntries[] = escapeshellarg($entry);
        } else {
            $otherEntries[] = escapeshellarg($entry);
        }
    }

    $cmdParts = [];
    $cmdParts[] = 'cd ' . escapeshellarg($unpackDir);
    if (!empty($otherEntries)) {
        $cmdParts[] = 'zip -9 -r ' . escapeshellarg($tempApk) . ' ' . implode(' ', $otherEntries);
    }
    if (!empty($libEntries)) {
        $cmdParts[] = 'zip -0 -r ' . escapeshellarg($tempApk) . ' ' . implode(' ', $libEntries);
    }

    $cmd = implode(' && ', $cmdParts);

    echo "开始覆盖写入到临时apk...\n";
    [$zipCode, ] = $runCmd($cmd);
    if ($zipCode !== 0) {
        echo "错误：zip覆盖写入失败。\n";
        return false;
    }

    clearstatcache(true, $tempApk);
    if (!is_file($tempApk) || filesize($tempApk) <= 0) {
        echo "错误：覆盖后的临时apk不存在或大小为0。\n";
        return false;
    }

    echo "覆盖完成，临时apk路径：{$tempApk}\n";
    return $tempApk;
}



//二进制从dex种找类
function find_class_in_dex_strings($dir, $classname) {
    $descriptor = 'L' . str_replace('.', '/', $classname) . ';';
    echo "开始找类 {$descriptor}\n";

    $files = scandir($dir);
    foreach ($files as $file) {
        if (!preg_match('/^classes\d*\.dex$/', $file)) {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $file;

        // 用 hexdump 尝试找类名的原始编码（兼容乱码）
        $cmd = "strings " . escapeshellarg($path) . " | grep " . escapeshellarg($descriptor);
        $output = shell_exec($cmd);

        if (!empty($output)) {
            return $file;
        }
    }

    return false;
}

//删除dex并重新排序
function removeDexAndResequence($dir, $targetDex) {
    $dir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;

    if (!preg_match('/^classes(\d+)\.dex$/', $targetDex, $matches)) {
        return false; // 非标准数字dex命名，不处理
    }

    $targetIndex = (int)$matches[1];

    $targetPath = $dir . $targetDex;
    if (file_exists($targetPath)) {
        //unlink($targetPath);
    }

    // 重新排序后续dex：classes{N}.dex => classes{N-1}.dex
    $maxIndex = 1000; // 防止无限循环
    for ($i = $targetIndex + 1; $i < $targetIndex + $maxIndex; $i++) {
        $current = $dir . "classes{$i}.dex";
        $prev = $dir . "classes" . ($i - 1) . ".dex";

        if (!file_exists($current)) {
            break;
        }

        rename($current, $prev);
    }

    return true;
}




//修改指定dex的父类,成功返回true，失败返回false，失败不影响原APK链路
function modifyDexSuperClass($xmx, $baksmaliPath, $smaliPath, $dir, $dexFilename, $className, $newSuperClass) {
    $dexPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $dexFilename;
    $outputDir = $dexPath . '_smali';
    $smaliFilePath = $outputDir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $className) . '.smali';

    // 反编译 DEX
    $cmdBaksmali = 'java -jar ' . escapeshellarg($baksmaliPath) .
                   ' d ' . escapeshellarg($dexPath) .
                   ' -o ' . escapeshellarg($outputDir) . ' 2>&1';
    echo "执行反编译命令{$cmdBaksmali}\n";
    shell_exec($cmdBaksmali);

    // 检查并修改 smali 文件中的 .super 行
    if (!file_exists($smaliFilePath)) {
        shell_exec('rm -rf ' . escapeshellarg($outputDir));
        echo "反编译入口dex失败\n";
        return false;
    }

    $lines = file($smaliFilePath);
    $modified = false;

    echo "开始修改父类行\n";
    foreach ($lines as &$line) {
        $trimmed = trim($line);
        if (strpos($trimmed, '.super') === 0) {
            $line = '.super L' . str_replace('.', '/', $newSuperClass) . ";\n";
            $modified = true;
            break;
        }
    }

    if (!$modified) {
        shell_exec('rm -rf ' . escapeshellarg($outputDir));
        echo "父类行修改失败\n";
        return false;
    }

    file_put_contents($smaliFilePath, implode('', $lines));

    // 回编译 smali 目录
    $newDexPath = $dir . DIRECTORY_SEPARATOR . 'new_' . $dexFilename;
    $cmdSmali = "java -Xmx{$xmx} -jar " . escapeshellarg($smaliPath) . ' a ' . escapeshellarg($outputDir) . ' -o ' . escapeshellarg($newDexPath) . ' 2>&1';

    echo "执行回编译命令{$cmdSmali}\n";
    shell_exec($cmdSmali);

    // 删除反编译目录
    shell_exec('rm -rf ' . escapeshellarg($outputDir));

    // 检查新 dex 文件是否生成成功
    if (!file_exists($newDexPath)) {
        echo "回编译入口dex失败\n";
        return false;
    }

    // 删除原 dex，替换新 dex
    unlink($dexPath);
    rename($newDexPath, $dexPath);

    return true;
}




//读取云注入的父类
function getDexSuperClass($baksmaliPath, $dir, $dexFilename, $className) {
    $dexPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $dexFilename;
    $outputDir = $dexPath . '_smali';
    $smaliPath = $outputDir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $className) . '.smali';

    // 执行反编译
    $cmd = 'java -jar ' . escapeshellarg($baksmaliPath) .
           ' d ' . escapeshellarg($dexPath) .
           ' -o ' . escapeshellarg($outputDir) . ' 2>&1';
    shell_exec($cmd);

    $superClass = 'android.app.Application';

    if (file_exists($smaliPath)) {
        $lines = file($smaliPath);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '.super') === 0) {
                $parts = preg_split('/\s+/', $line);
                if (isset($parts[1])) {
                    $smaliSuper = trim($parts[1], ' ;');
                    if (substr($smaliSuper, 0, 1) === 'L') {
                        $smaliSuper = substr($smaliSuper, 1); // 去除开头的L
                    }
                    $superClass = str_replace('/', '.', $smaliSuper);
                    echo "找到父类：$superClass\n";
                }
                break;
            }
        }
    }

    // 删除反编译目录
    if (is_dir($outputDir)) {
        shell_exec('rm -rf ' . escapeshellarg($outputDir));
    }

    return $superClass;
}





//壳入口类的父类修改
function updateSmaliSuperClass($baseDir, $oldClass, $newSuperClass) {
    if (strpos($newSuperClass, '.') === 0) {
        echo "错误：非法的新父类类名：不能以点号开头：$newSuperClass\n";
        return false;
    }
    
    // 转换类名为路径
    $relativePath = 'smali/' . str_replace('.', '/', $oldClass) . '.smali';
    $filePath = rtrim($baseDir, '/\\') . '/' . $relativePath;

    if (!file_exists($filePath)) {
        echo "错误：找不到文件：$filePath\n";
        return false;
    }

    $lines = file($filePath);
    if ($lines === false) {
        echo "错误：读取文件失败：$filePath\n";
        return false;
    }

    $found = false;
    $targetSuper = '.super L' . str_replace('.', '/', $newSuperClass) . ';';

    foreach ($lines as $i => $line) {
        if (strpos(trim($line), '.super ') === 0) {
            if (trim($line) === $targetSuper) {
                echo "无需修改，已是目标父类：$targetSuper\n";
                return true;
            }

            echo "原始 .super 行：{$lines[$i]}";
            $lines[$i] = $targetSuper . "\n";
            echo "已替换为：{$lines[$i]}";
            $found = true;
            break;
        }
    }

    if (!$found) {
        echo "错误：未找到 .super 行，无法修改\n";
        return false;
    }

    // 写回文件
    if (file_put_contents($filePath, implode('', $lines)) === false) {
        echo "错误：写入文件失败：$filePath\n";
        return false;
    }

    echo "修改成功：$filePath\n";
    return true;
}



//并发模式请求
function writeYunzhuruTypeFile($dir) {
    $assetsPath = rtrim($dir, '/').'/assets';
    if (!is_dir($assetsPath)) {
        mkdir($assetsPath, 0755, true);
    }

    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $randStr = '';
    for ($i = 0; $i < 128; $i++) {
        $randStr .= $chars[random_int(0, strlen($chars) - 1)];
    }

    $filePath = $assetsPath.'/yunzhuru.type';
    file_put_contents($filePath, $randStr);
}




//复制原始文件和so库
function processApkAndCopySo($apkPath, $sourceDir, $targetDir) {
    if (!is_file($apkPath)) {
        return false;
    }

    // 创建 assets/yunzhuru 目录并复制 origin.apk
    $originApkPath = $targetDir . '/assets/yunzhuru/origin.apk';
    $originApkDir = dirname($originApkPath);
    if (!is_dir($originApkDir) && !mkdir($originApkDir, 0777, true)) {
        return false;
    }
    if (!copy($apkPath, $originApkPath)) {
        return false;
    }

    $abiList = ['armeabi-v7a', 'x86', 'arm64-v8a', 'x86_64'];
    $targetLibDir = $targetDir . '/lib';

    $existingAbis = [];
    if (is_dir($targetLibDir)) {
        foreach (scandir($targetLibDir) as $entry) {
            if (in_array($entry, $abiList) && is_dir($targetLibDir . '/' . $entry)) {
                $existingAbis[] = $entry;
            }
        }
    }

    // 如果没有现成的 ABI 子目录，就复制全部
    $abisToCopy = empty($existingAbis) ? $abiList : $existingAbis;

    foreach ($abisToCopy as $abi) {
        $srcSo = $sourceDir . '/lib/' . $abi . '/libyzrSignatureKiller.so';
        $dstDir = $targetLibDir . '/' . $abi;
        $dstSo = $dstDir . '/libyzrSignatureKiller.so';

        if (is_file($srcSo)) {
            if (!is_dir($dstDir) && !mkdir($dstDir, 0777, true)) {
                return false;
            }
            if (!copy($srcSo, $dstSo)) {
                return false;
            }
        }
    }

    return true;
}

//只复制SO库
function processAndCopySo($sourceDir, $targetDir) {
    $abiList = ['armeabi-v7a', 'x86', 'arm64-v8a', 'x86_64'];
    $targetLibDir = $targetDir . '/lib';

    $existingAbis = [];
    if (is_dir($targetLibDir)) {
        foreach (scandir($targetLibDir) as $entry) {
            if (in_array($entry, $abiList) && is_dir($targetLibDir . '/' . $entry)) {
                $existingAbis[] = $entry;
            }
        }
    }

    // 如果没有现成的 ABI 子目录，就复制全部
    $abisToCopy = empty($existingAbis) ? $abiList : $existingAbis;

    foreach ($abisToCopy as $abi) {
        $srcSo = $sourceDir . '/lib/' . $abi . '/libyzrSignatureKiller.so';
        $dstDir = $targetLibDir . '/' . $abi;
        $dstSo = $dstDir . '/libyzrSignatureKiller.so';

        if (is_file($srcSo)) {
            if (!is_dir($dstDir) && !mkdir($dstDir, 0777, true)) {
                return false;
            }
            if (!copy($srcSo, $dstSo)) {
                return false;
            }
        }
    }

    return true;
}
function processAndCopyNative($sourceDir, $targetDir, $soNames) {
    $abiList = ['armeabi-v7a', 'arm64-v8a', 'x86', 'x86_64', 'armeabi'];
    $targetLibDir = $targetDir . '/lib';

    $existingAbis = [];
    if (is_dir($targetLibDir)) {
        foreach (scandir($targetLibDir) as $entry) {
            if (in_array($entry, $abiList) && is_dir($targetLibDir . '/' . $entry)) {
                $existingAbis[] = $entry;
            }
        }
    }

    $abisToCopy = empty($existingAbis) ? $abiList : $existingAbis;

    foreach ($abisToCopy as $abi) {
        foreach ($soNames as $soName) {
            $srcSo = $sourceDir . '/lib/' . $abi . '/' . $soName;
            $dstDir = $targetLibDir . '/' . $abi;
            $dstSo = $dstDir . '/' . $soName;

            if (is_file($srcSo)) {
                if (!is_dir($dstDir) && !mkdir($dstDir, 0777, true)) {
                    return false;
                }
                if (!copy($srcSo, $dstSo)) {
                    return false;
                }
            }
        }
    }

    return true;
}




//写出签名和包名信息到assets目录
function writeSignatureFiles($dir, $pkg, $sign) {
    // 规范化路径
    $assetsDir = rtrim($dir, '/\\') . '/assets';

    // 创建 assets 目录（如果不存在）
    if (!is_dir($assetsDir)) {
        if (!mkdir($assetsDir, 0700, true)) {
            echo "创建目录失败：$assetsDir\n";
            return false;
        }
    }

    // 写 yunzhuru.pkg（如果有值）
    if (!empty($pkg)) {
        $pkgPath = $assetsDir . '/yunzhuru.pkg';
        if (file_put_contents($pkgPath, $pkg) === false) {
            echo "写入 pkg 文件失败：$pkgPath\n";
            return false;
        } else {
            echo "成功写入 pkg 文件：$pkgPath\n";
        }
    }

    // 写 yunzhuru.sig（如果有值）
    if (!empty($sign)) {
        $sigPath = $assetsDir . '/yunzhuru.sig';
        if (file_put_contents($sigPath, $sign) === false) {
            echo "写入 sig 文件失败：$sigPath\n";
            return false;
        } else {
            echo "成功写入 sig 文件：$sigPath\n";
        }
    }

    return true;
}

//提取 X.509 证书
function extractApkSignatureBase64($apkPath) {
    if (!file_exists($apkPath)) {
        return false;
    }

    $tmpDir = __DIR__ . '/../temp/apk_' . uniqid();
    mkdir($tmpDir, 0700, true);

    // 仅列出 META-INF 目录的 .RSA 文件名
    $listCmd = "unzip -Z1 " . escapeshellarg($apkPath) . " | grep '^META-INF/.*\\.RSA\$'";
    $rsaEntry = trim(shell_exec($listCmd));

    if ($rsaEntry === '') {
        return false;
    }

    // 提取 .RSA 文件（只提取，不解压全部）
    $extractCmd = "unzip -j " . escapeshellarg($apkPath) . " " . escapeshellarg($rsaEntry) . " -d " . escapeshellarg($tmpDir) . " 2>&1";
    $extractOutput = shell_exec($extractCmd);
    $rsaFilePath = $tmpDir . '/' . basename($rsaEntry);

    if (!file_exists($rsaFilePath)) {
        return false;
    }

    // 使用 openssl 提取 PEM 格式证书
    $pemFile = $tmpDir . '/cert.pem';
    $opensslCmd = "openssl pkcs7 -inform DER -in " . escapeshellarg($rsaFilePath) . " -print_certs -out " . escapeshellarg($pemFile) . " 2>&1";
    $opensslOutput = shell_exec($opensslCmd);

    if (!file_exists($pemFile)) {
        return false;
    }

    // 提取 BASE64 证书内容
    $pemContent = file_get_contents($pemFile);
    if (!preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pemContent, $matches)) {
        return false;
    }

    $base64 = trim(str_replace(["\r", "\n"], '', $matches[1]));

    // 清理临时目录
    shell_exec("rm -rf " . escapeshellarg($tmpDir));

    return $base64;
}


//通过AAPT获取APP入口
function getApplicationClassName($aaptPath, $apkPath) {
    $aaptPath = escapeshellarg($aaptPath);
    $apkPath = escapeshellarg($apkPath);

    $cmd = "$aaptPath dump xmltree $apkPath AndroidManifest.xml";
    echo "执行命令：$cmd\n";

    $output = shell_exec($cmd);
    if (!$output) {
        echo "执行失败或未返回任何内容\n";
        return false;
    }

    echo "解析开始：\n";
    $lines = explode("\n", $output);

    $insideApplication = false;

    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        echo "[$i] $trimmed\n";

        if (strpos($trimmed, 'E: application') === 0) {
            $insideApplication = true;
            echo "进入 application 标签\n";
            continue;
        }

        if ($insideApplication) {
            // 如果遇到其他 E: 开头的标签，就退出
            if (preg_match('/^E: /', $trimmed)) {
                echo "遇到其他标签，终止处理\n";
                break;
            }

            if (strpos($trimmed, 'A: android:name') !== false) {
                echo "匹配行：$trimmed\n";
                if (preg_match('/"([^"]+)"/', $trimmed, $matches)) {
                    echo "提取成功：{$matches[1]}\n";
                    return $matches[1];
                }
            }
        }
    }

    echo "未找到 Application 的 android:name\n";
    return false;
}

//
//通过AAPT获取工厂入口
function getappComponentFactoryClassName($aaptPath, $apkPath) {
    $aaptPath = escapeshellarg($aaptPath);
    $apkPath = escapeshellarg($apkPath);

    $cmd = "$aaptPath dump xmltree $apkPath AndroidManifest.xml";
    echo "执行命令：$cmd\n";

    $output = shell_exec($cmd);
    if (!$output) {
        echo "执行失败或未返回任何内容\n";
        return false;
    }

    echo "解析开始：\n";
    $lines = explode("\n", $output);

    $insideApplication = false;

    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        echo "[$i] $trimmed\n";

        if (strpos($trimmed, 'E: application') === 0) {
            $insideApplication = true;
            echo "进入 application 标签\n";
            continue;
        }

        if ($insideApplication) {
            // 如果遇到其他标签，立即退出 application 块
            if (preg_match('/^E: /', $trimmed)) {
                echo "遇到其他标签，终止处理\n";
                break;
            }

            if (strpos($trimmed, 'A: android:appComponentFactory') !== false) {
                echo "匹配行：$trimmed\n";
                if (preg_match('/"([^"]+)"/', $trimmed, $matches)) {
                    echo "提取成功：{$matches[1]}\n";
                    return $matches[1];
                }
            }
        }
    }

    echo "未找到 Application 的 android:appComponentFactory\n";
    return false;
}










/**
 * 修改smali文件中指定类的父类
 *
 * @param string $dir                根目录路径（包含smali目录）
 * @param string $className          类名，如 "com.example.shell.HookApplication"
 * @param string $newSuperClassName  新父类名，如 "com.dex.abc.application"
 *
 * @return bool  成功返回 true，失败或未找到文件返回 false
 */
function update_smali_superclass($dir, $className, $newSuperClassName) {
    // 构造smali文件路径
    $smaliPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                 'smali' . DIRECTORY_SEPARATOR .
                 str_replace('.', DIRECTORY_SEPARATOR, $className) . '.smali';
    echo "smali文件路径：{$smaliPath}\n";

    if (!is_file($smaliPath)) {
        return false;
    }

    // 目标父类描述符
    $newSuperDesc = 'L' . str_replace('.', '/', $newSuperClassName) . ';';

    // 读取文件内容
    $lines = file($smaliPath, FILE_IGNORE_NEW_LINES);
    $modified = false;

    foreach ($lines as &$line) {
        if (preg_match('/^\s*\.super\s+(L.+;)/', $line, $m)) {
            $currSuper = $m[1];
            if ($currSuper === $newSuperDesc) {
                return true;
            }
            $line = '.super ' . $newSuperDesc;
            $modified = true;
            break;
        }
    }
    unset($line);

    if ($modified) {
        file_put_contents($smaliPath, implode(PHP_EOL, $lines));
        return true;
    }

    return false;
}


/**
 * 反编译指定 dex，修改目标类父类后重新回编译
 *
 * @param string $baksmali           baksmali.jar 路径
 * @param string $smali              smali.jar 路径 新版
 * @param string $smali              smali.jar 路径 旧版
 * @param string $dir                存放 dex 的目录
 * @param string $dexFileName        需要处理的 dex 文件名（如 classes.dex）
 * @param string $className          目标类名（如 com.example.shell.HookApplication）
 * @param string $newSuperClassName  新父类名（如 com.dex.abc.application）
 *
 * @return array ['success'=>bool, 'className'=>string]  success 为处理结果，className 为原父类（点分格式）
 */
function patch_dex_superclass($xmx, $baksmali, $smali, $smali_past, $dir, $dexFileName, $className, $newSuperClassName, $pdo, $task) {
    // 1) 生成 smali 输出目录
    $smaliDir = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                pathinfo($dexFileName, PATHINFO_FILENAME) . '_smali';

    // 2) 反编译 dex
    $dexPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $dexFileName;
    //旧版反编译
    $cmdBaksmali = sprintf(
        'java -jar %s disassemble -o %s %s 2>/dev/null',
        escapeshellarg($baksmali),
        escapeshellarg($smaliDir),
        escapeshellarg($dexPath)
    );
    $cmdBaksmali = "java -Xmx{$xmx} -jar " . escapeshellarg($baksmali) . 
               ' d ' . escapeshellarg($dexPath) . 
               ' -o ' . escapeshellarg($smaliDir);
    shell_exec($cmdBaksmali);

    // 3) 目标 smali 文件路径
    $classSmaliPath = $smaliDir . DIRECTORY_SEPARATOR .
                      str_replace('.', DIRECTORY_SEPARATOR, $className) . '.smali';
    if (!is_file($classSmaliPath)) {
        echo "未找到目标文件：{$classSmaliPath}，类名{$className}\n";
        return ['success' => false, 'className' => ''];
    }
    $smali_size = count_smali_files($smaliDir);
    echo "smali 文件数量: " . $smali_size . "\n";
    if($smali_size >=3000){
        $info = "回编译入口dex失败:smali文件数量{$smali_size}，超过阈值3000，可能存在极端的混淆，会卡回编译，可更换注入模式尝试";
        echo $info."\n";
        return ['success' => false, 'className' => $info];
    }
    // 4) 读取并处理 .smali 文件
    $lines = file($classSmaliPath, FILE_IGNORE_NEW_LINES);
    $origParentDotted = '';
    $changed = false;

    // 新父类描述符
    $newSuperDesc = 'L' . str_replace('.', '/', $newSuperClassName) . ';';

    foreach ($lines as &$line) {
        if (preg_match('/^\s*\.super\s+(L.+;)/', $line, $m)) {
            $currSuperDesc = $m[1];
            echo "原始父类名称 {$currSuperDesc}\n";
            //移除开头的 L 和结尾的 ;，保留中间的 L
            $origParentDotted = preg_replace(['/^L/', '/;$/'], '', $currSuperDesc);
            $origParentDotted = str_replace('/', '.', $origParentDotted);
    
            echo "替换后的父类 {$origParentDotted}\n";
            
            if ($currSuperDesc !== $newSuperDesc) {
                $line = preg_replace('/^\s*\.super\s+L.+;/', '.super ' . $newSuperDesc, $line);
                $changed = true;
                echo "已修改目标dex父类为：{$newSuperDesc}\n";
            }
            break;
        }
    }
    unset($line);

    // 未修改则直接返回
    if (!$changed) {
        echo "已经是目标父类{$newSuperDesc},无需修改\n";
        return ['success' => true, 'className' => $origParentDotted];
    }

    // 写回文件
    file_put_contents($classSmaliPath, implode(PHP_EOL, $lines));

    // 5) 删除旧 dex
    if (is_file($dexPath)) {
        echo "删除旧入口dex：{$dexPath}\n";
        unlink($dexPath);
    }
    if(file_exists($dexPath)){
        echo "删除旧入口dex失败\n";
        return ['success' => false, 'className' => $origParentDotted];
    }else
    echo "删除旧入口dex成功\n";
    // 6) 回编译 smali -> dex
    $cmdSmali = 'java -jar ' . escapeshellarg($smali_past) . ' -o ' . escapeshellarg($dexPath) . ' ' . escapeshellarg($smaliDir) . ' 2>&1';//旧版本回编译
    $cmdSmali = "nice -n 19 java -Xmx{$xmx} -jar " . escapeshellarg($smali) . ' a ' . escapeshellarg($smaliDir) . ' -o ' . escapeshellarg($dexPath) . ' 2>&1';//新版本回编译
    echo "smali回编译dex的命令：{$cmdSmali}\n";
    echo "回编译入口dex：{$dexPath}\n";
    updateTaskInfo($pdo, $task['id'], '正在回编译入口dex(比较耗时)');
    $data = shell_exec($cmdSmali);
    echo "回编译入口dex结果：{$data}\n";
    if(!file_exists($dexPath)){
        echo "回编译入口dex失败\n";
        return ['success' => false, 'className' => $origParentDotted];
    }
    echo "回编译入口dex成功\n";
    $success = is_file($dexPath);
    return ['success' => $success, 'className' => $origParentDotted];
}

function count_smali_files($dir) {
    if (!is_dir($dir)) {
        return 0;
    }

    $files = scandir($dir);
    $count = 0;

    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;

        // 忽略子目录，只统计文件
        if (is_file($path) && preg_match('/\.smali$/i', $file)) {
            $count++;
        }
    }

    return $count;
}











//dex文件找类,直接读取
function find_class_in_dex_bak($dir, $classname) {
    // 类名转换为 DEX 描述符格式
    $descriptor = 'L' . str_replace('.', '/', $classname) . ';';
    echo "开始找类 {$descriptor}\n";

    // 扫描目录下所有 .dex 文件
    $files = scandir($dir);

    // 按升序排列：classes.dex、classes2.dex、classes3.dex...
    usort($files, function ($a, $b) {
        // 优先处理 classes.dex，其次 classes2.dex、classes3.dex...
        if ($a === 'classes.dex') return -1;
        if ($b === 'classes.dex') return 1;

        preg_match('/^classes(\d*)\.dex$/', $a, $ma);
        preg_match('/^classes(\d*)\.dex$/', $b, $mb);

        $na = isset($ma[1]) ? intval($ma[1]) : PHP_INT_MAX;
        $nb = isset($mb[1]) ? intval($mb[1]) : PHP_INT_MAX;

        return $na - $nb;
    });

    // 遍历 dex 文件查找目标类
    foreach ($files as $file) {
        if (!preg_match('/^classes(\d*)?\.dex$/', $file)) {
            continue;
        }

        echo "检查DEX文件：{$file}\n";
        $path = $dir . DIRECTORY_SEPARATOR . $file;

        // 执行 dexdump 命令提取类信息
        $cmd = "dexdump -f " . escapeshellarg($path) . " 2>/dev/null";
        $output = shell_exec($cmd);

        if (!is_string($output) || trim($output) === '') {
            continue;
        }

        // 判断类是否存在
        if (strpos($output, "Class descriptor  : '{$descriptor}'") !== false) {
            return $file;
        }
    }

    return false;
}
//流式读取，防止大dex爆内存
function find_class_in_dex_liu($dir, $classname) {
    // 转换类名为 DEX 描述符
    $descriptor = 'L' . str_replace('.', '/', $classname) . ';';
    echo "开始找类 {$descriptor}\n";

    // 扫描目录
    $files = scandir($dir);

    // 排序 dex 文件
    usort($files, function ($a, $b) {
        if ($a === 'classes.dex') return -1;
        if ($b === 'classes.dex') return 1;
        preg_match('/^classes(\d*)\.dex$/', $a, $ma);
        preg_match('/^classes(\d*)\.dex$/', $b, $mb);
        $na = isset($ma[1]) ? intval($ma[1]) : PHP_INT_MAX;
        $nb = isset($mb[1]) ? intval($mb[1]) : PHP_INT_MAX;
        return $na - $nb;
    });

    foreach ($files as $file) {
        if (!preg_match('/^classes(\d*)?\.dex$/', $file)) {
            continue;
        }

        echo "检查DEX文件：{$file}\n";
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        $cmd = "dexdump -f " . escapeshellarg($path) . " 2>/dev/null";

        // 流式读取 dexdump 输出
        $handle = popen($cmd, 'r');
        if (!$handle) continue;

        $found = false;
        while (!feof($handle)) {
            $line = fgets($handle, 4096); // 每次读 4KB
            if (strpos($line, "Class descriptor  : '{$descriptor}'") !== false) {
                $found = true;
                break;
            }
        }
        pclose($handle);

        if ($found) return $file;
    }

    return false;
}
//自写dex类查找解析,比dexdump方法更快
function find_class_in_dex($dir, $classname) {
    $descriptor = 'L' . str_replace('.', '/', $classname) . ';';
    echo "开始查找类: {$descriptor}\n";

    $files = scandir($dir);

    // 优先处理 classes.dex，其次 classes2.dex、classes3.dex...
    usort($files, function ($a, $b) {
        if ($a === 'classes.dex') return -1;
        if ($b === 'classes.dex') return 1;
        preg_match('/^classes(\d*)\.dex$/', $a, $ma);
        preg_match('/^classes(\d*)\.dex$/', $b, $mb);
        $na = isset($ma[1]) && $ma[1] !== '' ? intval($ma[1]) : PHP_INT_MAX;
        $nb = isset($mb[1]) && $mb[1] !== '' ? intval($mb[1]) : PHP_INT_MAX;
        return $na - $nb;
    });

    foreach ($files as $file) {
        if (!preg_match('/^classes(\d*)?\.dex$/', $file)) continue;

        $path = $dir . DIRECTORY_SEPARATOR . $file;
        echo "扫描DEX文件: {$file}\n";

        $fp = fopen($path, 'rb');
        if (!$fp) {
            echo "无法打开文件: {$file}\n";
            continue;
        }

        // 读取 DEX 文件头
        $header = fread($fp, 0x70);
        if (strlen($header) < 0x70) {
            echo "DEX头读取失败: {$file}\n";
            fclose($fp);
            continue;
        }

        $string_ids_size = unpack('V', substr($header, 0x38, 4))[1];
        $string_ids_off  = unpack('V', substr($header, 0x3C, 4))[1];
        echo "DEX文件 {$file} 包含 {$string_ids_size} 个字符串索引\n";

        // 遍历 string_ids，只读取索引和偏移
        for ($i = 0; $i < $string_ids_size; $i++) {
            $pos = $string_ids_off + $i * 4;
            fseek($fp, $pos, SEEK_SET);
            $data = fread($fp, 4);
            if (strlen($data) < 4) continue;
            $string_data_off = unpack('V', $data)[1];

            // 读取字符串内容（ULEB128长度）
            fseek($fp, $string_data_off, SEEK_SET);
            $utf_len = 0;
            $shift = 0;

            while (true) {
                $b = fread($fp, 1);
                if ($b === false || strlen($b) < 1) break;
                $byte = ord($b);
                $utf_len |= ($byte & 0x7F) << $shift;
                if (($byte & 0x80) === 0) break;
                $shift += 7;
            }

            if ($utf_len <= 0) continue;

            $str = fread($fp, $utf_len);
            if ($str === false) continue;
            
            
            if (mb_convert_encoding($str, 'UTF-8', 'UTF-8') == mb_convert_encoding($descriptor, 'UTF-8', 'UTF-8')) {
                echo "找到类 {$descriptor} 在文件 {$file}\n";
                fclose($fp);
                return $file;
            }
            /*if ($str === $descriptor) {
                echo "找到类 {$descriptor} 在文件 {$file}\n";
                fclose($fp);
                return $file;
            }*/
        }

        echo "DEX文件 {$file} 中未找到类 {$descriptor}\n";
        fclose($fp);
    }

    echo "未找到类 {$descriptor} 在任何 DEX 文件中\n";
    return false;
}




//上传加固检测,特征检测 PHP内置方法实现
function isApkObfuscated($apkPath, $pdo) {
    if (!file_exists($apkPath)) {
        throw new Exception("APK 文件不存在: " . $apkPath);
    }
    $startTime = microtime(true); // 开始计时
    // 预定义加固/混淆类型及其关键词和提示语
    $rules = [
        [
            'type' => 'ArmEpic',
            'keywords' => [
                'libArmEpic.so'
            ],
            'message' => '🚨检测到Arm注入'
        ],
        [
            'type' => '腾讯乐固',
            'keywords' => [
                'libtup.so','liblegudb.so','libshella','libshel1x','mix.dex','mixz.dex'
            ],
            'message' => '🚨检测到腾讯乐固加固'
        ],
        [
            'type' => '腾讯御安全',
            'keywords' => [
                'libshell-super.2019.so','libBugly-yaq.so','libzBugly-yaq.so','tosversion',
                'libshellx-super.2019.so','tosprotection','000000111111.dex','000000011111.dex',
                '00000o11111.dex','000001111111','o0ooo000oo0o.dat','t86','libtosprotection.x86.so',
                'libtosprotection.armeabi.so','libtosprotection.armeabi-v7a.so'
            ],
            'message' => '🚨检测到腾讯御安全加固'
        ],
        [
            'type' => '360加固',
            'keywords' => [
                '．appkey','libprotectClass.so','libjiagu.so','libjiagu_ls.so','libjiagu_x86.so',
                'libjiagu_a64.so','libjiagu_x64.so','libjiagu_art.so','1ibjgdtc.so','libjgdtc_x86.so',
                'libjgdtc_a64.so','libjgdtc_x64.so','libjgdtc_art.so'
            ],
            'message' => '🚨检测到360加固'
        ],
        [
            'type' => '梆梆安全',
            'keywords' => [
                'libsecexe.so','libsecmain.so','libSecShel1.so','1ibDexHelper.so','libDexHelper-x86.so'
            ],
            'message' => '🚨检测到梆梆安全加固'
        ],
        [
            'type' => '爱加密',
            'keywords' => [
                'libexec.so','libexecmain.so','ijiami.dat','ijiami.ajm'
            ],
            'message' => '🚨检测到爱加密加固'
        ],
        [
            'type' => '阿里聚安全',
            'keywords' => [
                'libmobisec.so','aliprotect.dat','libsgmain.so','libfakejni.so','libzuma.so','libzumadata.so',
                'libdemolish.so'
            ],
            'message' => '🚨检测到阿里聚安全加固'
        ],
        [
            'type' => '中国移动加固',
            'keywords' => [
                'mogosec_classes','mogosec_data','mogosec_dexinfo','mogosec_march','libcmvmp.so',
                'libmogosec_dex.so','libmogosec_sodecrypt.so','ibmogosecurity.so'
            ],
            'message' => '🚨检测到中国移动加固'
        ],
        [
            'type' => '百度加固',
            'keywords' => [
                'libbaiduprotect.so','libbaiduprotect_x86.so','libbaiduprotect_art.so','baiduprotect1.jar'
            ],
            'message' => '🚨检测到百度加固'
        ],
        [
            'type' => '几维安全',
            'keywords' => ['libkwscmm.so','libkwscr.so','libkwslinker.so'],
            'message' => '🚨检测到几维安全加固'
        ],
        [
            'type' => '通付盾',
            'keywords' => ['libegis.so','libNSaferOnly.so'],
            'message' => '🚨检测到通付盾加固'
        ],
        [
            'type' => 'UU安全',
            'keywords' => ['libuusafe.jar.so','libuusafe.so','libuusafeempty.so'],
            'message' => '🚨检测到UU安全加固'
        ],
        [
            'type' => '瑞星加固',
            'keywords' => ['librsprotect.so'],
            'message' => '🚨检测到瑞星加固'
        ],
        [
            'type' => '盛大加固',
            'keywords' => ['libapssec.so'],
            'message' => '🚨检测到盛大加固'
        ],
        [
            'type' => '海云安加固',
            'keywords' => ['libitsec.so'],
            'message' => '🚨检测到海云安加固'
        ],
        [
            'type' => '国信灵通 / 网秦加固',
            'keywords' => ['libnqshield.so'],
            'message' => '🚨检测到国信灵通/网秦加固'
        ],
        [
            'type' => '娜迦加固',
            'keywords' => ['libchaosvmp.so','libddog.so','libfdog.so','libedog.so'],
            'message' => '🚨检测到娜迦加固'
        ],
        [
            'type' => '顶像科技',
            'keywords' => ['libx3g.so'],
            'message' => '🚨检测到顶像科技加固'
        ],
        [
            'type' => '珊瑚灵御',
            'keywords' => ['libreincp.so','libreincp_x86.so'],
            'message' => '🚨检测到珊瑚灵御加固'
        ],
        [
            'type' => 'apktoolplus',
            'keywords' => ['jiagu_data.bin','sign.bin','libapktoolplus_jiagu.so'],
            'message' => '🚨检测到apktoolplus加固'
        ],
        [
            'type' => '阿重聚安全',
            'keywords' => ['libpreverify1.so','libdemolishdata.so','libsgsecuritybody.so'],
            'message' => '🚨检测到阿重聚安全加固'
        ],
        [
            'type' => '大纸片混淆',
            'keywords' => ['大纸片'],
            'message' => '🚨检测到大纸片混淆'
        ],
        [
            'type' => '网易易盾',
            'keywords' => ['libnesec.so','libnesec-x86.so'],
            'message' => '🚨检测到网易易盾加固'
        ],
        [
            'type' => '未知加固特征',
            'keywords' => ['jiagu'],
            'message' => '🚨检测到可能存在未知加固混淆'
        ],
        [
            'type' => '爱加密',
            'keywords' => ['libijm_linker.so', 'ijiami.ajm', 'ijiami.dat', 'IJMDal.Data'],
            'message' => '🚨检测到 爱加密 加固'
        ],
        [
            'type' => 'LSP',
            'keywords' => ['lspatch', 'liblspatch.so'],
            'message' => '🚨已经被lspatch注入过,可能会导闪退或注入不成功,推荐使用工厂注入模式'
        ],
        [
            'type' => 'Epic加固',
            'keywords' => ['Epic.vmp', 'Epic_so', 'libEpic_so', 'Epic_dexs'],
            'message' => '🚨检测到 Epic 加固,注入后无法运行'
        ],
        [
            'type' => '腾讯手游加密',
            'keywords' => ['libtprt.so'],
            'message' => '🚨疑似存在腾讯手游的通用加固'
        ],
        [
            'type' => '梆梆加固',
            'keywords' => ['libDexHelper.so','libDexHelper-x86.so'],
            'message' => '🚨检测到梆梆加固'
        ],
        [
            'type' => '360加固',
            'keywords' => ['libjiagu.so', 'libprotectClass.so', 'libsecmain.so','libjiagu_a64.so','libjiagu_x64.so'],
            'message' => '🚨检测到360加固'
        ],
        [
            'type' => '腾讯加固',
            'keywords' => ['libshell-super.so', 'libshella.so','libshellx.so'],
            'message' => '🚨检测到腾讯加固'
        ],
        [
            'type' => '梆梆加固',
            'keywords' => ['libsecexe.so', 'libsecpreload.so'],
            'message' => '🚨检测到 梆梆加固'
        ],
        [
            'type' => '百度加固',
            'keywords' => ['baiduprotect'],
            'message' => '🚨检测到百度加固'
        ],
        [
            'type' => '通用混淆',
            'keywords' => ['混淆', 'obfuscate', 'libjiagu_x64.so', 'libjiagu_a64.so', 'jiagu', 'oOo0o','OoO0o'],
            'message' => '🚨检测到混淆加固迹象'
        ],
        [
            'type' => '云镜',
            'keywords' => ['by_yunjing', 'yun-jing', 'yj-apk-sign', 'libYJ-Signature.so', 'libyj_dcc_pro_aj_pro.so', 'libyj_dcc_pro_sign.so'],
            'message' => '检测到云镜加固'
        ],
        [
            'type' => '菜鸟云注入',
            'keywords' => ['libnative-cainiao.so'],
            'message' => '🚨检测到本平台自身注入'
        ],
        [
            'type' => 'ShadowSafety',
            'keywords' => ['libShadowSafetyProtect_a64.so','libShadowSafetyProtect_x64.so', 'ShadowSafety'],
            'message' => '🚨检测到ShadowSafety加固'
        ],
        [
            'type' => '枯叶云',
            'keywords' => ['libkydtc.so','枯叶云Dex2c.txt'],
            'message' => '🚨检测到枯叶云加固'
        ],
        [
            'type' => 'NP管理器',
            'keywords' => ['protected_by_np'],
            'message' => '🚨检测到Dex-2C加固壳'
        ],
        [
            'type' => '深思数盾',
            'keywords' => ['l********_a32.so','l********_a64.so','l********_x64.so','l********_x86.so'],
            'message' => '🚨检测到深思数盾加固迹象'
        ]
    ];
    if(Auth::getSetting($pdo, 'jiagu', "0")){
        $rules = Auth::getRules($pdo, 1);
    }
    // 使用 ZipArchive 获取文件列表，排除 res/ 路径
    $zip = new ZipArchive();
    if ($zip->open($apkPath) !== true) {
        return [
            'matched' => false,
            'type' => '未检测',
            'message' => "检测失败（耗时 {0}ms）"
        ];
    }

    $lines = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = strtolower($zip->getNameIndex($i));
        if (strpos($name, 'res/') !== 0) {
            $lines[] = $name;
        }
    }
    $zip->close();

    $apkMd5 = md5_file($apkPath);


    // 从数据库读取混淆检测强度阈值（默认 0.4）
    $ascii = floatval(Auth::getSetting($pdo, 'ascii', 0.4));
    
    $garbledCount = 0;
    foreach ($lines as $line) {
        if (isGarbledName($line,$ascii)) {
            $garbledCount++;
        }
    }
    if ($garbledCount >= 5) { // 乱码文件数达到阈值
        $timeUsed = round((microtime(true) - $startTime) * 1000);
        return [
            'matched' => true,
            'type' => '乱码混淆',
            'message' => "检测到乱码类资源混淆（{$garbledCount} 个异常文件）检测强度{$ascii}（耗时 {$timeUsed}ms）"
        ];
    }


    foreach ($rules as $rule) {
        foreach ($rule['keywords'] as $keyword) {
            if (is_array($keyword)) {
                $allMatched = true;
                foreach ($keyword as $subKeyword) {
                    $matched = false;
                    $pattern = '/^.*' . str_replace('\*', '.*', preg_quote(strtolower($subKeyword), '/')) . '.*$/';
                    foreach ($lines as $line) {
                        if (preg_match($pattern, $line)) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        $allMatched = false;
                        break;
                    }
                }
                $timeUsed = round((microtime(true) - $startTime) * 1000); // 毫秒
                if ($allMatched) {
                    return [
                        'matched' => true,
                        'type' => $rule['type'],
                        'message' => $rule['message']. "（耗时 {$timeUsed}ms）"
                    ];
                }
            } else {
                // 支持 MD5 直接匹配
                if (strtolower($keyword) === strtolower($apkMd5)) {
                    $timeUsed = round((microtime(true) - $startTime) * 1000); // 毫秒
                    return [
                        'matched' => true,
                        'type' => $rule['type'],
                        'message' => $rule['message']. "（耗时 {$timeUsed}ms）"
                    ];
                }

                // 支持通配匹配（* 转为正则）
                $pattern = '/^.*' . str_replace('\*', '.*', preg_quote(strtolower($keyword), '/')) . '.*$/';
                foreach ($lines as $line) {
                    if (preg_match($pattern, $line)) {
                        $timeUsed = round((microtime(true) - $startTime) * 1000); // 毫秒
                        return [
                            'matched' => true,
                            'type' => $rule['type'],
                            'message' => $rule['message']. "（耗时 {$timeUsed}ms）"
                        ];
                    }
                }
            }
        }
    }
     $timeUsed = round((microtime(true) - $startTime) * 1000); // 毫秒
    return [
        'matched' => false,
        'type' => '',
        'message' => '未知'. "（耗时 {$timeUsed}ms）"
    ];
}
//乱码检测
function isGarbledName($name, $ascii = 0.4) {
    if($ascii<0.4){
        $ascii=0.4;
    }
    if($ascii>1){
        $ascii=1;
    }
    $totalLength = mb_strlen($name, 'UTF-8');
    if ($totalLength === false || $totalLength === 0) return false;
    $asciiCount = preg_match_all('/[\x20-\x7E]/', $name);
    $nonAsciiRatio = 1 - ($asciiCount / $totalLength);
    return $nonAsciiRatio > $ascii;//「非 ASCII 字符比例 > 40%」认为是乱码
}


/**
 * 将 $srcRoot/lib 目录（含所有子目录与文件）覆盖到 $dstRoot/lib。
 * 若目标 lib 不存在则创建；若已存在则整体覆盖（会删除目标中多余文件）。
 *
 * @param string $dstRoot 目标根目录
 * @param string $srcRoot 源根目录
 * @return bool           成功返回 true，失败返回 false
 */
function overwriteLib(string $dstRoot, string $srcRoot): bool
{
    $srcLib = rtrim($srcRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lib';
    $dstLib = rtrim($dstRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lib';

    if (!is_dir($srcLib)) {
        error_log("源目录缺少 lib：{$srcLib}");
        return false;
    }

    if (!is_dir($dstLib) && !mkdir($dstLib, 0777, true)) {
        error_log("创建目标 lib 目录失败：{$dstLib}");
        return false;
    }

    // 递归复制 src → dst，覆盖同名文件，不删除多余文件
    $srcIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcLib, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($srcIterator as $item) {
        $targetPath = str_replace($srcLib, $dstLib, $item->getPathname());
        if ($item->isDir()) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0777, true)) {
                error_log("创建目录失败：{$targetPath}");
                return false;
            }
        } else {
            // 复制覆盖文件
            if (!copy($item->getPathname(), $targetPath)) {
                error_log("复制文件失败：{$targetPath}");
                return false;
            }
        }
    }

    return true;
}


function updateSignedApkSize(PDO $pdo, int $taskId, string $signedApkPath)
{
    /*if (!file_exists($signedApkPath)) {
        throw new Exception('签名文件不存在：' . $signedApkPath);
    }

    $size = filesize($signedApkPath);
    if ($size === false) {
        throw new Exception('无法读取文件大小');
    }

    $stmt = $pdo->prepare("UPDATE cainiao_inject_task SET size = :size WHERE id = :id");
    $stmt->execute([
        ':size' => $size,
        ':id' => $taskId
    ]);*/
}

//写出入口
function writeYunzhuru($dir, $content) {
    $assetsDir = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'assets';

    if (!is_dir($assetsDir)) {
        if (!mkdir($assetsDir, 0777, true)) {
            return false;
        }
    }

    $filePath = $assetsDir . DIRECTORY_SEPARATOR . 'yunzhuru.com';
    $finalContent = ($content === null) ? '' : $content;

    if (file_put_contents($filePath, $finalContent) === false) {
        return false;
    }

    return true;
}
//写出特征
function writecharacteristic($dir, $file, $content) {
    $assetsDir = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'assets';

    if (!is_dir($assetsDir)) {
        if (!mkdir($assetsDir, 0777, true)) {
            return false;
        }
    }

    $filePath = $assetsDir . DIRECTORY_SEPARATOR . $file;
    $finalContent = ($content === null) ? '' : $content;

    if (file_put_contents($filePath, $finalContent) === false) {
        return false;
    }

    return true;
}

function compileSmaliToNextDex($smaliJar, $de_apk1, $de_apk2) {
    $smaliDir = rtrim($de_apk1, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'smali';
    if (!is_dir($smaliDir)) {
        return false;
    }

    // 只统计合法的 dex：classes.dex 视为 index=1，classesN.dex 视为 index=N（且 N>=2）
    $existingIndices = [];
    $targetDir = rtrim($de_apk2, DIRECTORY_SEPARATOR);
    $files = @scandir($targetDir);
    if ($files !== false) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if ($file === 'classes.dex') {
                $existingIndices[] = 1;
            } elseif (preg_match('/^classes(\d+)\.dex$/', $file, $m)) {
                $num = intval($m[1]);
                if ($num >= 2) {
                    $existingIndices[] = $num;
                }
            }
        }
    }

    // 选取第一个未被占用的索引（从1开始）
    $dexIndex = 1;
    while (in_array($dexIndex, $existingIndices, true)) {
        $dexIndex++;
    }
    $dexName = 'classes' . ($dexIndex === 1 ? '' : $dexIndex) . '.dex';

    $outputDexPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('dex_') . '.dex';

    // 使用 smali 新版命令回编译 dex （保留 stderr 到 stdout）
    $cmd = 'java -jar ' . escapeshellarg($smaliJar) . ' a ' . escapeshellarg($smaliDir) . ' -o ' . escapeshellarg($outputDexPath) . ' 2>&1';
    echo "smali回编译dex的命令：{$cmd}\n";
    $smaliOutput = shell_exec($cmd);
    echo "smali回编译输出：{$smaliOutput}\n";

    if (!file_exists($outputDexPath)) {
        echo "回编译失败：未生成临时 dex 文件。\n";
        return false;
    }

    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $dexName;
    if (!rename($outputDexPath, $targetPath)) {
        echo "移动 dex 文件失败：从 {$outputDexPath} 到 {$targetPath}。\n";
        return false;
    }
    echo "壳dex已注入到{$targetPath}\n";
    
    
    
    
    return true;
}


//dex转smali
function dexToSmali($baksmaliPath, $decompiledDir) {
    $dexFile = rtrim($decompiledDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'classes.dex';
    $smaliDir = rtrim($decompiledDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'smali';
    if (!file_exists($dexFile)) {
        return false;
    }
    $cmd = 'java -jar ' . escapeshellarg($baksmaliPath) . ' disassemble ' . escapeshellarg($dexFile) . ' -o ' . escapeshellarg($smaliDir) . ' 2>&1';
    shell_exec($cmd);
    if (!is_dir($smaliDir)) {
        return false;
    }
    return true;
}

//入口读取 xml2axml库
function readApplicationName($xml2axmlPath, $dirPath)
{
    $manifestBin = rtrim($dirPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';
    $manifestXml = $manifestBin . '.xml';

    if (!file_exists($manifestBin)) {
        return false;
    }

    // 使用新的 xml2axml.jar 反编译 AXML
    $cmdDecode = sprintf(
        'java -jar %s d %s %s 2>&1',
        escapeshellarg($xml2axmlPath),
        escapeshellarg($manifestBin),
        escapeshellarg($manifestXml)
    );

    shell_exec($cmdDecode);

    if (!file_exists($manifestXml)) {
        return false;
    }

    $xmlContent = file_get_contents($manifestXml);
    // unlink($manifestXml); // 如不需要保留，可打开

    if ($xmlContent === false) {
        return false;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    if ($xml === false) {
        return false;
    }

    $application = $xml->application;
    if (!$application) {
        return false;
    }

    $attributes = $application->attributes(
        'http://schemas.android.com/apk/res/android'
    );

    if (isset($attributes['name'])) {
        return (string)$attributes['name'];
    }

    return false;
}


//入口读取 axmlPrinter 库
function readApplicationName2($axmlPrinterPath, $dirPath) {
    $manifestPath = rtrim($dirPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';
    $tempXmlPath = $manifestPath . '.xml';

    if (!file_exists($manifestPath)) {
        return false;
    }

    $cmd = 'java -jar ' . escapeshellarg($axmlPrinterPath) . ' ' . escapeshellarg($manifestPath) . ' > ' . escapeshellarg($tempXmlPath);
    shell_exec($cmd);

    if (!file_exists($tempXmlPath)) {
        return false;
    }

    $xmlContent = file_get_contents($tempXmlPath);
    //unlink($tempXmlPath); // 删除临时文件

    if ($xmlContent === false) {
        return false;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    if ($xml === false) {
        return false;
    }

    $application = $xml->application;
    if (!$application) {
        return false;
    }

    $attributes = $application->attributes('http://schemas.android.com/apk/res/android');
    if (isset($attributes['name'])) {
        return (string)$attributes['name'];
    }

    return false;
}

//是否禁用了so压缩，禁用了返回true,反之返回false
function hasExtractNativeLibsFalse($aapt2Path, $apkPath) {
    if (!file_exists($apkPath)) {
        echo "APK文件不存在\n";
        return false;
    }

    // 使用aapt2直接读取清单内容
    $cmd = escapeshellarg($aapt2Path) . ' dump xmltree ' . escapeshellarg($apkPath) . ' AndroidManifest.xml';
    $output = shell_exec($cmd);

    if (!$output) {
        echo "aapt2解析失败，可能不支持该APK\n";
        return false;
    }

    $lines = explode("\n", $output);
    $inApplication = false;

    foreach ($lines as $line) {
        $trim = trim($line);

        // 进入application节点
        if (strpos($trim, 'E: application') === 0) {
            $inApplication = true;
            continue;
        }

        // 离开application节点（遇到下一个E:且不是自身）
        if ($inApplication && strpos($trim, 'E: ') === 0 && strpos($trim, 'E: application') !== 0) {
            break;
        }

        // 在application节点内部查找extractNativeLibs属性
        if ($inApplication && strpos($trim, 'A: android:extractNativeLibs') !== false) {

            // 可能出现的值：true / false / (type 0x12)0xffffffff / (type 0x12)0x0
            $val = strtolower($trim);

            // 视为false的情况
            if (
                strpos($val, 'false') !== false ||
                strpos($val, '0xffffffff') === false && strpos($val, '0x0') !== false ||
                preg_match('/\s0\b/', $val)
            ) {
                echo "禁用了so库压缩\n";
                return true;
            }

            return false;
        }
    }

    echo "未发现extractNativeLibs属性\n";
    return false;
}



//添加权限
function addPermissions($editorPath, $dirPath, $permissions) {
    $manifest = rtrim($dirPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';
    $newManifest = rtrim($dirPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest-new.xml';

    if (!file_exists($manifest) || !is_array($permissions) || empty($permissions)) {
        return false;
    }

    $cmd = 'java -jar ' . escapeshellarg($editorPath) . ' ' . escapeshellarg($manifest);

    foreach ($permissions as $permission) {
        echo "添加权限：{$permission}\n";
        $cmd .= ' -up ' . escapeshellarg($permission);
    }

    shell_exec($cmd);

    if (!file_exists($newManifest)) {
        return false;
    }

    if (!unlink($manifest)) {
        return false;
    }

    if (!rename($newManifest, $manifest)) {
        return false;
    }

    return true;
}


//入口修改
function updateManifest($editorPath, $dirPath, $className) {
    $manifest = rtrim($dirPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';
    $newManifest = rtrim($dirPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest-new.xml';

    if (!file_exists($manifest)) {
        return false;
    }

    $cmd = 'java -jar ' . escapeshellarg($editorPath) . ' ' . escapeshellarg($manifest) . ' -an ' . escapeshellarg($className);
    shell_exec($cmd);

    if (!file_exists($newManifest)) {
        return false;
    }

    if (!unlink($manifest)) {
        return false;
    }

    if (!rename($newManifest, $manifest)) {
        return false;
    }

    return true;
}

//解除HTTP限制
function fix_android_http_limit($editorPath, $dirPath, $className) {
    $manifest = rtrim($dirPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';
    $newManifest = rtrim($dirPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest-new.xml';

    if (!file_exists($manifest)) {
        return false;
    }

    $cmd = 'java -jar ' . escapeshellarg($editorPath) . ' ' . escapeshellarg($manifest) . ' -aa ' . escapeshellarg($className);
    shell_exec($cmd);

    if (!file_exists($newManifest)) {
        return false;
    }

    if (!unlink($manifest)) {
        return false;
    }

    if (!rename($newManifest, $manifest)) {
        return false;
    }

    return true;
}


//设置debug模式
function debuggable($editorPath, $dirPath, $debuggable) {
    $manifest = rtrim($dirPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest.xml';
    $newManifest = rtrim($dirPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'AndroidManifest-new.xml';

    if (!file_exists($manifest)) {
        return false;
    }

    $cmd = 'java -jar ' . escapeshellarg($editorPath) . ' ' . escapeshellarg($manifest) . ' -d ' . escapeshellarg($debuggable);
    shell_exec($cmd);

    if (!file_exists($newManifest)) {
        return false;
    }

    if (!unlink($manifest)) {
        return false;
    }

    if (!rename($newManifest, $manifest)) {
        return false;
    }

    return true;
}












//dex数量检测
function isDexCountExceed2($apkPath, $maxDexCount = 10) {
    if (!file_exists($apkPath)) {
        throw new Exception("APK 文件不存在: $apkPath");
    }
    // 执行 unzip 命令并筛选出 .dex 文件
    $cmd = "unzip -l " . escapeshellarg($apkPath) . " | grep -cE '\\.dex$'";
    $output = shell_exec($cmd);

    // 转为整数
    $dexCount = (int)trim($output);

    // 返回布尔值（是否超过阈值）和实际数量
    return [
        'exceed' => $dexCount > $maxDexCount,
        'count'  => $dexCount
    ];
}
function isDexCountExceed($apkPath, $maxDexCount = 10) {
    if (!file_exists($apkPath)) {
        throw new Exception("APK 文件不存在: $apkPath");
    }
    
    // 使用awk：查找以.dex结尾且路径不包含斜杠的文件
    $cmd = "unzip -l " . escapeshellarg($apkPath) . " | awk '\$NF ~ /\\.dex\$/ && \$NF !~ /\// {count++} END {print count+0}'";
    
    $output = shell_exec($cmd);
    
    // 转为整数
    $dexCount = (int)trim($output);
    
    // 返回布尔值（是否超过阈值）和实际数量
    return [
        'exceed' => $dexCount > $maxDexCount,
        'count'  => $dexCount
    ];
}



function updateTaskStatus(PDO $pdo, int $taskId, string $status)
{
    $now = null;

    // 编译成功或失败时更新 completed_at
    if ($status === '编译成功' || stripos($status, '失败') !== false) {
        $now = date('Y-m-d H:i:s');
    }

    $stmt = $pdo->prepare("
        UPDATE cainiao_inject_task 
        SET status_text = :status, completed_at = :completed_at 
        WHERE id = :id
    ");

    $stmt->execute([
        ':status'       => $status,
        ':completed_at' => $now,
        ':id'           => $taskId
    ]);

    echo "\n[" . date('Y-m-d H:i:s') . "] 已更新任务 #$taskId 状态为：$status\n";

    // 编译成功后自动推送配置到存储桶
    if ($status === '编译成功') {
        try {
            $apkIdStmt = $pdo->prepare("SELECT apk_id FROM cainiao_inject_task WHERE id = :id");
            $apkIdStmt->execute([':id' => $taskId]);
            $apkId = (int) $apkIdStmt->fetchColumn();
            if ($apkId > 0) {
                require_once __DIR__ . '/../api/utils/BucketPush.php';
                $pushResult = pushConfigToBuckets($pdo, $apkId);
                echo "[BucketPush] 应用 {$apkId} 配置推送结果: " . json_encode($pushResult, JSON_UNESCAPED_UNICODE) . "\n";
            }
        } catch (\Throwable $e) {
            echo "[BucketPush] 推送失败（不影响注入流程）: " . $e->getMessage() . "\n";
        }
    }
}

function updateencry(PDO $pdo, int $taskId, string $encry)
{
    $now = null;

    $stmt = $pdo->prepare("
        UPDATE cainiao_inject_task 
        SET encry = :encry
        WHERE id = :id
    ");

    $stmt->execute([
        ':encry'       => $encry,
        ':id'           => $taskId
    ]);

    echo "\n[" . date('Y-m-d H:i:s') . "] 已更新任务 #$taskId 信息为：$status\n";
}

function updateTaskInfo(PDO $pdo, int $taskId, string $status)
{
    $now = null;

    $stmt = $pdo->prepare("
        UPDATE cainiao_inject_task 
        SET status_info = :status
        WHERE id = :id
    ");

    $stmt->execute([
        ':status'       => $status,
        ':id'           => $taskId
    ]);

    echo "\n[" . date('Y-m-d H:i:s') . "] 已更新任务 #$taskId 信息为：$status\n";
}

//安全的删除目录
function safeDeleteDirectory(string $targetPath): bool
{
    $uploadsBase   = realpath(__DIR__ . '/../uploads');
    $templatesBase = realpath(__DIR__ . '/../templates');
    $tempBase = realpath(__DIR__ . '/../temp');
    $realTarget    = realpath($targetPath);

    if (!$realTarget || !is_dir($realTarget)) {
        echo "目标不是有效目录：$targetPath\n";
        return false;
    }

    if (
        strpos($realTarget, $uploadsBase) !== 0 &&
        strpos($realTarget, $templatesBase) !== 0 &&
        strpos($realTarget, $tempBase) !== 0
    ) {
        echo "安全限制：禁止删除非 uploads/templates/temp 子目录\n";
        return false;
    }

    $it = new RecursiveDirectoryIterator($realTarget, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }

    rmdir($realTarget);
    echo "目录已删除：$realTarget\n";
    return true;
}

//安全的删除缓存
function safeDeleteFile(?string $filePath): bool
{
    if (!$filePath) {
        echo "文件路径为空，跳过删除\n";
        return false;
    }
    $uploadsBase   = realpath(__DIR__ . '/../uploads');
    $templatesBase = realpath(__DIR__ . '/../templates');
    $releaseBase = realpath(__DIR__ . '/../release');
    $tempBase = realpath(__DIR__ . '/../temp');
    $realFile      = realpath($filePath);

    if (!$realFile || !is_file($realFile)) {
        echo "无效文件路径或文件不存在：$filePath\n";
        return false;
    }

    if (
        strpos($realFile, $uploadsBase) !== 0 &&
        strpos($realFile, $templatesBase) !== 0 &&
        strpos($realFile, $releaseBase) !== 0 &&
        strpos($realFile, $tempBase) !== 0
        
    ) {
        echo "安全限制：禁止删除非 uploads/templates/release/temp 中的文件\n";
        return false;
    }

    if (@unlink($realFile)) {
        echo "文件已删除：$realFile\n";
        return true;
    } else {
        echo "删除失败：$realFile\n";
        return false;
    }
}


/**
 * 更新注入任务的 injected_apk 路径
 * 
 * @param PDO $pdo 数据库句柄
 * @param int $taskId 注入任务ID
 * @param string $apkPath 编译后APK的相对路径或完整路径
 * @return bool
 */
/*function updateInjectedApkPath($pdo, $taskId, $apkPath)
{
    $dir = __DIR__ . '/../release/';
    $stmt = $pdo->prepare("
        UPDATE cainiao_inject_task
        SET injected_apk = :apk
        WHERE id = :id
    ");

    $success = $stmt->execute([
        ':apk' => $apkPath,
        ':id'  => $taskId
    ]);

    if ($success) {
        echo "[" . date('Y-m-d H:i:s') . "] 注入结果路径已记录：任务 #$taskId => $apkPath\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ 更新 injected_apk 失败：任务 #$taskId\n";
    }

    return $success;
}*/
function updateInjectedApkPath($pdo, $taskId, $apkPath)
{
    $dir = __DIR__ . '/../release/';

    // 查询 user_id 和原 injected_apk
    $stmt = $pdo->prepare("
        SELECT user_id, injected_apk
        FROM cainiao_inject_task
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ 未找到任务记录：{$taskId}\n";
        return false;
    }

    $userId = $row['user_id'];
    $oldInjectedApk = $row['injected_apk'];

    // 原文件完整路径
    $oldFilePath = $dir . $apkPath;
    if (!is_file($oldFilePath)) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ 原 APK 文件不存在：{$oldFilePath}\n";
        return false;
    }

    // 计算 MD5
    $md5 = md5_file($oldFilePath);
    if (!$md5) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ 计算 APK MD5 失败\n";
        return false;
    }

    // 新文件名：user_id_MD5.apk
    $newFileName = $userId . '_' . $md5 . '.build.signed.apk';
    $newFilePath = $dir . $newFileName;

    // 重命名文件
    if (!rename($oldFilePath, $newFilePath)) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ APK 重命名失败\n";
        return false;
    }

    // 获取新文件大小（字节）
    $fileSize = filesize($newFilePath);
    if ($fileSize === false) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ 获取 APK 文件大小失败\n";
        return false;
    }

    // 如果旧 injected_apk 文件存在，先删除
    if (!empty($oldInjectedApk)) {
        $oldInjectedPath = $dir . $oldInjectedApk;
        if (is_file($oldInjectedPath)) {
            unlink($oldInjectedPath);
            echo "[" . date('Y-m-d H:i:s') . "] 已删除旧注入 APK：{$oldInjectedApk}\n";
        }
    }

    // 更新数据库：文件名 + 大小
    $stmt = $pdo->prepare("
        UPDATE cainiao_inject_task
        SET injected_apk = :apk,
            size = :size
        WHERE id = :id
    ");

    $success = $stmt->execute([
        ':apk'  => $newFileName,
        ':size' => $fileSize,
        ':id'   => $taskId
    ]);

    if ($success) {
        echo "[" . date('Y-m-d H:i:s') . "] 注入结果已更新：任务 #{$taskId} => {$newFileName}（{$fileSize} 字节）\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ 更新 injected_apk / size 失败：任务 #{$taskId}\n";
    }

    return $success;
}



/**
 * 检查指定注入任务是否存在
 * 
 * @param PDO $pdo 数据库句柄
 * @param int $taskId 任务ID
 * @return bool 存在返回 true，否则 false
 */
function taskExists(PDO $pdo, int $taskId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_inject_task WHERE id = :id");
    $stmt->execute([':id' => $taskId]);
    return (int)$stmt->fetchColumn() > 0;
}




function create_unique_temp_subdir($base_dir) {
    // 循环直到创建成功为止
    while (true) {
        // 生成唯一ID作为目录名
        $unique_name = uniqid('sub_', true);
        $full_path = $base_dir . DIRECTORY_SEPARATOR . $unique_name;

        // 如果目录不存在则创建
        if (!file_exists($full_path)) {
            if (mkdir($full_path, 0777, true)) {
                return $full_path;
            }
        }
    }
}

//修改网络检测模式
//修改壳配置文件sign
function replace_config_NETWORK($basePath, $domains) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);
    // 将签名变为单行（去除换行符）
    $domains = str_replace(["\r\n", "\r", "\n"], '', $domains);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $GLOBALS['Config'].'.smali') {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    $newContent = str_replace('[#NETWORK#]', $domains, $content);

                    file_put_contents($filePath, $newContent);
                    echo "已替换 NETWORK: $filePath\n";
                }
            }
        }
    }
}


//通用换配置方法
function replace_config_info($basePath, $info, $domains) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);
    // 将签名变为单行（去除换行符）
    $domains = str_replace(["\r\n", "\r", "\n"], '', $domains);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $GLOBALS['Config'].'.smali') {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    $newContent = str_replace($info, $domains, $content);

                    file_put_contents($filePath, $newContent);
                    echo "已替换 {$info}: $filePath\n";
                }
            }
        }
    }
}

function replace_config_LAUNCHER($basePath, $domains) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);
    // 将签名变为单行（去除换行符）
    $domains = str_replace(["\r\n", "\r", "\n"], '', $domains);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $GLOBALS['Config'].'.smali') {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    $newContent = str_replace('[#LAUNCHER#]', $domains, $content);

                    file_put_contents($filePath, $newContent);
                    echo "已替换 LAUNCHER: $filePath\n";
                }
            }
        }
    }
}

function replace_config_APPLICATION($basePath, $domains) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);
    // 将签名变为单行（去除换行符）
    $domains = str_replace(["\r\n", "\r", "\n"], '', $domains);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $GLOBALS['Config'].'.smali') {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    $newContent = str_replace('[#APPLICATION#]', $domains, $content);

                    file_put_contents($filePath, $newContent);
                    echo "已替换 APPLICATION: $filePath\n";
                }
            }
        }
    }
}

function replace_config_ISMMAINPROCESS($basePath, $domains) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);
    // 将签名变为单行（去除换行符）
    $domains = str_replace(["\r\n", "\r", "\n"], '', $domains);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $GLOBALS['Config'].'.smali') {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    $newContent = str_replace('[#ISMMAINPROCESS#]', $domains, $content);

                    file_put_contents($filePath, $newContent);
                    echo "已替换 ISMMAINPROCESS: $filePath\n";
                }
            }
        }
    }
}

function replace_config_VPNCHECK($basePath, $domains) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);
    // 将签名变为单行（去除换行符）
    $domains = str_replace(["\r\n", "\r", "\n"], '', $domains);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $GLOBALS['Config'].'.smali') {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    $newContent = str_replace('[#VPNCHECK#]', $domains, $content);

                    file_put_contents($filePath, $newContent);
                    echo "已替换 VPNCHECK: $filePath\n";
                }
            }
        }
    }
}

//修改壳id和uid
function replace_config_placeholders($basePath, $appId, $userId, $key) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);

    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $GLOBALS['Config'].'.smali') {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    // 增加 [#KEY#] 替换
                    $newContent = str_replace(
                        ['[#APP_ID#]', '[#USER_ID#]', '[#KEY#]'],
                        [$appId, $userId, $key],
                        $content
                    );

                    file_put_contents($filePath, $newContent);
                    echo "已替换: $filePath\n";
                }
            }
        }
    }
}


//修改壳配置文件
function replace_config_domains($basePath, $domains) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);

    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                $configSmali = ($GLOBALS['Config'] ?? 'Config') . '.smali';
                if ($file->getFilename() === $configSmali) {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    $newContent = str_replace('[#DOMAINS#]', $domains, $content);

                    file_put_contents($filePath, $newContent);
                    echo "已替换 DOMAINS: $filePath\n";
                }
            }
        }
    }
}

// 注入存储桶域名到壳配置（替换 [#BUCKETS#] 占位符）
function replace_config_buckets($basePath, $buckets) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);

    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                $configSmali = ($GLOBALS['Config'] ?? 'Config') . '.smali';
                if ($file->getFilename() === $configSmali) {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    $newContent = str_replace('[#BUCKETS#]', $buckets, $content);

                    file_put_contents($filePath, $newContent);
                    echo "已替换 BUCKETS: $filePath\n";
                }
            }
        }
    }
}

//修改壳配置文件sign
function replace_config_SIGN($basePath, $domains) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);
    // 将签名变为单行（去除换行符）
    $domains = str_replace(["\r\n", "\r", "\n"], '', $domains);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $GLOBALS['Config'].'.smali') {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    $newContent = str_replace('[#SIGN#]', $domains, $content);

                    file_put_contents($filePath, $newContent);
                    echo "已替换 SIGN: $filePath\n";
                }
            }
        }
    }
}

//修改壳配置文件package
function replace_config_PACKAGE($basePath, $domains) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);

    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $GLOBALS['Config'].'.smali') {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    $newContent = str_replace('[#PACKAGE#]', $domains, $content);

                    file_put_contents($filePath, $newContent);
                    echo "已替换 PACKAGE: $filePath\n";
                }
            }
        }
    }
}

//修改壳配置入口
function replace_config_originFactoryClassName($basePath, $originFactoryClassName) {
    $basePath = rtrim($basePath, '/');
    $dirs = scandir($basePath);

    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;

        if (preg_match('/^smali/', $dir)) {
            $fullDir = $basePath . '/' . $dir;

            if (!is_dir($fullDir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $GLOBALS['Config'].'.smali') {
                    $filePath = $file->getPathname();
                    $content = file_get_contents($filePath);

                    $newContent = str_replace('android.app.AppComponentFactory', $originFactoryClassName, $content);

                    file_put_contents($filePath, $newContent);
                    echo "已替换 originFactoryClassName: $filePath\n";
                }
            }
        }
    }
}

/**
 * 处理"等待下载"的 URL 注入任务
 * 从 source_url 下载 APK，带进度更新，下载完成后转为"等待处理"
 */
function handleUrlDownloadTasks(PDO $pdo)
{
    $stmt = $pdo->prepare("
        SELECT t.id, t.source_url, t.apk_id, t.user_id
        FROM cainiao_inject_task t
        WHERE t.status_text = '等待下载' AND t.source_url != ''
        ORDER BY t.created_at ASC
        LIMIT 1
    ");
    $stmt->execute();
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        return;
    }

    $taskId = (int)$task['id'];
    $url = $task['source_url'];
    $userId = (int)$task['user_id'];
    $apkId = (int)$task['apk_id'];

    echo "[" . date('Y-m-d H:i:s') . "] URL 下载任务 #{$taskId}：{$url}\n";
    updateTaskStatus($pdo, $taskId, '正在下载');
    updateTaskInfo($pdo, $taskId, '正在下载 APK...');

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $tmpFile = $uploadDir . $userId . '_url_' . md5($url) . '.apk';

    // 用 curl 下载，带进度回调
    $ch = curl_init($url);
    $fp = fopen($tmpFile, 'w');
    if (!$fp) {
        updateTaskStatus($pdo, $taskId, '下载失败');
        updateTaskInfo($pdo, $taskId, '无法创建临时文件');
        return;
    }

    $lastUpdate = 0; // 上次更新进度的时间
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($resource, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($pdo, $taskId, &$lastUpdate) {
            $now = time();
            // 每 2 秒更新一次进度，避免频繁写库
            if ($dlTotal > 0 && $dlNow > 0 && ($now - $lastUpdate) >= 2) {
                $lastUpdate = $now;
                $percent = round($dlNow / $dlTotal * 100, 1);
                $dlMB = round($dlNow / 1048576, 1);
                $totalMB = round($dlTotal / 1048576, 1);
                $info = "下载中 {$dlMB}MB / {$totalMB}MB ({$percent}%)";
                try {
                    $stmt = $pdo->prepare("UPDATE cainiao_inject_task SET status_info = :info WHERE id = :id");
                    $stmt->execute([':info' => $info, ':id' => $taskId]);
                } catch (Exception $e) {}
                echo "[" . date('Y-m-d H:i:s') . "] 任务 #{$taskId} {$info}\n";
            }
            return 0; // 返回非0会中断下载
        },
    ]);

    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$success || $httpCode !== 200 || !file_exists($tmpFile) || filesize($tmpFile) < 1024) {
        @unlink($tmpFile);
        updateTaskStatus($pdo, $taskId, '下载失败');
        updateTaskInfo($pdo, $taskId, '下载失败：' . ($error ?: "HTTP {$httpCode}"));
        echo "[" . date('Y-m-d H:i:s') . "] ❌ 任务 #{$taskId} 下载失败\n";
        return;
    }

    $fileSize = filesize($tmpFile);
    echo "[" . date('Y-m-d H:i:s') . "] 任务 #{$taskId} 下载完成，大小 " . round($fileSize / 1048576, 1) . "MB\n";

    // 解析 APK 信息（aapt → aapt2 fallback）
    $aaptCmd = "aapt dump badging " . escapeshellarg($tmpFile) . " 2>&1";
    $aaptOutput = shell_exec($aaptCmd);
    $appName = '未知应用';
    $package = '未知包名';
    $version = '未知版本';

    // aapt 失败时用 aapt2
    if (!$aaptOutput || stripos($aaptOutput, 'error:') !== false || !preg_match("/package: name='(.*?)'/", $aaptOutput)) {
        $aapt2Cmd = "aapt2 dump badging " . escapeshellarg($tmpFile) . " 2>&1";
        $aaptOutput = shell_exec($aapt2Cmd);
        echo "[" . date('Y-m-d H:i:s') . "] 任务 #{$taskId} aapt 解析失败，使用 aapt2\n";
    }

    if ($aaptOutput && stripos($aaptOutput, 'error:') === false) {
        if (preg_match("/package: name='(.*?)'.*?versionName='(.*?)'/", $aaptOutput, $m)) {
            $package = $m[1];
            $version = $m[2];
        }
        $langLabels = [
            "application-label-zh-CN:'(.*?)'",
            "application-label-zh:'(.*?)'",
            "application-label-zh-TW:'(.*?)'",
            "application-label:'(.*?)'"
        ];
        foreach ($langLabels as $pattern) {
            if (preg_match("/{$pattern}/", $aaptOutput, $m)) {
                $appName = str_replace(' ', '_', $m[1]);
                break;
            }
        }
    }

    if ($package === '未知包名') {
        @unlink($tmpFile);
        updateTaskStatus($pdo, $taskId, '下载失败');
        updateTaskInfo($pdo, $taskId, '无法解析 APK 包名，文件可能不是有效的 APK');
        return;
    }

    // 重命名为标准格式
    $md5 = md5_file($tmpFile);
    $fileName = "{$userId}_{$md5}.apk";
    $savedPath = $uploadDir . $fileName;
    if (file_exists($savedPath)) {
        @unlink($tmpFile);
    } else {
        rename($tmpFile, $savedPath);
    }

    // 更新应用记录：检查是否已有同包名应用，有则复用，删除占位记录
    $stmt = $pdo->prepare("SELECT id FROM cainiao_apk WHERE user_id = :uid AND package = :pkg AND id != :placeholder_id LIMIT 1");
    $stmt->execute([':uid' => $userId, ':pkg' => $package, ':placeholder_id' => $apkId]);
    $existingApk = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingApk) {
        // 已有同包名应用，更新它的文件信息
        $realApkId = (int)$existingApk['id'];
        $pdo->prepare("UPDATE cainiao_apk SET name = :name, version = :ver, path = :path, size = :size WHERE id = :id")
            ->execute([':name' => $appName, ':ver' => $version, ':path' => $fileName, ':size' => $fileSize, ':id' => $realApkId]);
        // 任务指向真实应用
        $pdo->prepare("UPDATE cainiao_inject_task SET apk_id = :apk_id WHERE id = :id")
            ->execute([':apk_id' => $realApkId, ':id' => $taskId]);
        // 删除占位记录
        $pdo->prepare("DELETE FROM cainiao_apk WHERE id = :id")->execute([':id' => $apkId]);
        // 删除该应用的旧注入任务（保留当前任务）
        $pdo->prepare("DELETE FROM cainiao_inject_task WHERE apk_id = :apk_id AND id != :id")
            ->execute([':apk_id' => $realApkId, ':id' => $taskId]);
        echo "[" . date('Y-m-d H:i:s') . "] 复用已有应用 #{$realApkId}，删除占位记录 #{$apkId}\n";
    } else {
        // 没有同包名应用，直接更新占位记录
        $pdo->prepare("UPDATE cainiao_apk SET name = :name, version = :ver, package = :pkg, path = :path, size = :size WHERE id = :id")
            ->execute([':name' => $appName, ':ver' => $version, ':pkg' => $package, ':path' => $fileName, ':size' => $fileSize, ':id' => $apkId]);
    }

    // 下载完成，转为"等待处理"让注入流程接手
    updateTaskStatus($pdo, $taskId, '等待处理');
    updateTaskInfo($pdo, $taskId, "下载完成（{$appName}），等待注入");
    echo "[" . date('Y-m-d H:i:s') . "] ✅ 任务 #{$taskId} 下载完成，转入注入队列\n";
}

/**
 * 注入完成后自动上传 APK 到用户配置的 S3/R2 桶
 * 上传失败不影响任务状态，仅记录日志
 *
 * @param PDO $pdo
 * @param int $taskId 注入任务 ID
 * @param string $apkFilePath 签名后 APK 的完整路径
 * @param string $appName 应用名称
 */
function autoUploadToS3(PDO $pdo, int $taskId, string $appName)
{
    // 从数据库读取 injected_apk 路径（updateInjectedApkPath 会重命名文件）
    $stmt = $pdo->prepare("
        SELECT t.injected_apk, u.s3_endpoint, u.s3_access_key, u.s3_secret_key, u.s3_bucket, u.s3_region, u.s3_upload_path, u.s3_public_url
        FROM cainiao_inject_task t
        JOIN cainiao_user u ON u.id = t.user_id
        WHERE t.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['s3_endpoint'])) {
        return; // 用户未配置 S3，跳过
    }
    if (empty($row['injected_apk'])) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ S3 上传跳过：injected_apk 为空\n";
        return;
    }

    $apkFilePath = __DIR__ . '/../release/' . $row['injected_apk'];
    if (!file_exists($apkFilePath)) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ S3 上传失败：文件不存在 {$apkFilePath}\n";
        return;
    }

    echo "[" . date('Y-m-d H:i:s') . "] 开始自动上传到 S3 桶...\n";

    require_once __DIR__ . '/../api/utils/S3Client.php';

    try {
        $client = new S3Client(
            $row['s3_access_key'],
            $row['s3_secret_key'],
            $row['s3_endpoint'],
            $row['s3_bucket'],
            $row['s3_region'] ?: 'auto'
        );

        // 构建上传路径：前缀/应用名_时间戳.apk
        $prefix = $row['s3_upload_path'] ? $row['s3_upload_path'] . '/' : '';
        $safeName = preg_replace('/[^a-zA-Z0-9\x{4e00}-\x{9fa5}_\-\.]/u', '_', $appName);
        $objectKey = $prefix . $safeName . '_' . date('Ymd_His') . '.apk';

        $result = $client->putObjectFromFile($objectKey, $apkFilePath, 'application/vnd.android.package-archive',
            function ($total, $uploaded) use ($pdo, $taskId, &$lastUploadUpdate) {
                $now = time();
                if (!isset($lastUploadUpdate)) $lastUploadUpdate = 0;
                if ($total > 0 && ($now - $lastUploadUpdate) >= 2) {
                    $lastUploadUpdate = $now;
                    $percent = round($uploaded / $total * 100, 1);
                    $upMB = round($uploaded / 1048576, 1);
                    $totalMB = round($total / 1048576, 1);
                    $info = "上传S3 {$upMB}MB / {$totalMB}MB ({$percent}%)";
                    try {
                        $stmt = $pdo->prepare("UPDATE cainiao_inject_task SET status_info = :info WHERE id = :id");
                        $stmt->execute([':info' => $info, ':id' => $taskId]);
                    } catch (Exception $e) {}
                    echo "[" . date('Y-m-d H:i:s') . "] 任务 #{$taskId} {$info}\n";
                }
            }
        );

        if ($result['code'] === 200) {
            echo "[" . date('Y-m-d H:i:s') . "] ✅ S3 上传成功：{$objectKey}\n";
            // 拼完整下载链接
            $publicUrl = rtrim($row['s3_public_url'] ?? '', '/');
            if ($publicUrl) {
                $downloadUrl = $publicUrl . '/' . $objectKey;
                $s3info = ' | 下载: ' . $downloadUrl;
            } else {
                $s3info = ' | S3: ' . $objectKey;
            }
            $stmt = $pdo->prepare("UPDATE cainiao_inject_task SET status_info = :s3info WHERE id = :id");
            $stmt->execute([
                ':s3info' => $s3info,
                ':id' => $taskId,
            ]);
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] ❌ S3 上传失败：{$result['message']}\n";
        }
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ❌ S3 上传异常：{$e->getMessage()}\n";
    }
}

