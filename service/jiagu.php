<?php
/**
 * 加固逻辑核心入口
 * @param PDO $pdo 已连接的 PDO 实例
 */
function handleJiaguTasks(PDO $pdo, $oss)
{
    putenv("LC_ALL=en_US.UTF-8");
    putenv("LANG=en_US.UTF-8");
    setlocale(LC_ALL, 'en_US.UTF-8');
    // 查询等待处理任务（按创建时间优先）
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
    
            -- APK 信息
            a.path       AS apk_path,
            a.osspath    AS apk_osspath,
            a.size       AS apk_size,
            a.domain_mode,
            a.name,
            a.package,
            a.sign,
            a.user_id    AS apk_user_id,
            a.custom_domains,
    
            -- 签名信息
            s.path       AS sign_path,
            s.alias,
            s.password       AS storepass,
            s.cert_password  AS keypass
    
        FROM cainiao_jiagu_task t
        LEFT JOIN cainiao_apk  a ON t.apk_id  = a.id
        LEFT JOIN cainiao_sign s ON t.sign_id = s.id
        WHERE t.status_text = '等待处理'
        ORDER BY t.created_at ASC
        LIMIT 1
    ");

    $stmt->execute();
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        return;
    }
    $ApkDataMultiplexing = __DIR__ . '/../bin/ApkDataMultiplexing.jar';//数据复用优化并签名
    $apksigner_jar = __DIR__ . '/../bin/apksigner.jar';//签名工具
    $jiagujar =  __DIR__ . '/../bin/vm/vm.jar';//vm加固库
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
        $apk_file = $localSavePath;
        $oss_temp = true;
    }else{
        $apk_file = realpath(__DIR__ . '/../uploads/' . $task['apk_path']);
        $oss_temp = false;
    }
    $keystore = realpath(__DIR__ . '/../signfile/' . $task['sign_path']);
    $alias     = $task['alias'];
    $storepass = $task['storepass'];
    $keypass   = $task['keypass'];
    $user_id = $task['user_id'];
    $apk_user_id = $task['apk_user_id'];
    echo "=================================注入前文件预检测====================================\n";
    
    if(empty($task['apk_path'])){
        echo "原始APK包不存在\n";
        updateJiaguTaskStatus($pdo, $task['id'], '任务失败');
        updateJiaguTaskInfo($pdo, $task['id'], "应用安装包不存在,请先重传安装包");
        return;
    }
    if(empty($apk_file)){
        echo "原始APK包不存在\n";
        updateJiaguTaskStatus($pdo, $task['id'], '任务失败');
        updateJiaguTaskInfo($pdo, $task['id'], "应用安装包不存在,请先重传安装包");
        return;
    }
    
    echo "=====================================================================\n";
    updateJiaguTaskStatus($pdo, $task['id'], '开始处理');
    $xmx = Auth::getSetting($pdo,"xmx","512M");
    echo "[" . date('Y-m-d H:i:s') . "] xmx内存分配: ". $xmx ."\n";
    echo "[" . date('Y-m-d H:i:s') . "] 获取到待处理任务 ID: {$task['id']}\n";
    echo "本地应用路径: {$task['apk_path']}\n";
    echo "OSS应用路径: {$task['apk_osspath']}\n";
    echo "应用路径: {$apk_file}\n";
    echo "签名路径: {$keystore}\n";
    echo "应用名称: {$task['name']}\n";
    echo "应用归属: {$apk_user_id}\n";
    echo "任务归属: {$user_id}\n";
    echo "加固规则: {$task['rules']}\n";
    echo "alias: {$alias}\n";
    echo "storepass: {$storepass}\n";
    echo "keypass: {$keypass}\n";
    
    if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $task['alias'])) {
        echo "证书别名包含中文字符\n";
        updateJiaguTaskStatus($pdo, $task['id'], '任务失败');
        updateJiaguTaskInfo($pdo, $task['id'], "证书别名包含中文字符，请更换证书");
        del_osstemp($oss_temp, $localSavePath);//删除缓存底包
        return;
    }
    echo "=================================开始处理校验签名信息====================================\n";
    if($task['sign_type'] == 1){
        $task['hash256'] = get_cert_sha256_from_keystore($keystore, $storepass, $alias);
        echo "该加固使用平台证书hash256进行校验：{$task['hash256']}\n";
        if(empty($task['hash256'])){
            updateJiaguTaskStatus($pdo, $task['id'], '加固失败');
            updateJiaguTaskInfo($pdo, $task['id'], '无法解析出签名证书的hash256');
            remove_dir_recursive($temp_dir);
            del_osstemp($oss_temp, $localSavePath);//删除缓存底包
            return;
        }
    }
    
    if($task['sign_type'] == 2){
        echo "该加固使用自定义证书hash256进行校验：{$task['hash256']}\n";
        if(empty($task['hash256'])){
            updateJiaguTaskStatus($pdo, $task['id'], '加固失败');
            updateJiaguTaskInfo($pdo, $task['id'], '自定义hash256不能为空');
            remove_dir_recursive($temp_dir);
            del_osstemp($oss_temp, $localSavePath);//删除缓存底包
            return;
        }
    }
    
    if($task['sign_type'] == 0){
        echo "该加固使用应用自身hash256进行校验\n";
    }

    
    
    echo "=====================================================================\n";
    updateJiaguTaskInfo($pdo, $task['id'], '正在加固中,请耐心等待');
    $temp_dir = create_unique_temp_subdir(realpath(__DIR__ . '/../temp/'));//为每次任务创建一个子目录
    list($ok, $msg, $outApk) = vm_jiagu($jiagujar, $xmx, $temp_dir, $apk_file, $task);
    if(!$ok){
        updateJiaguTaskStatus($pdo, $task['id'], '加固失败');
        updateJiaguTaskInfo($pdo, $task['id'], $msg);
        remove_dir_recursive($temp_dir);
        del_osstemp($oss_temp, $localSavePath);//删除缓存底包
        return;
    }
    echo "加固结果\n";
    echo "{$msg}\n";
    echo "{$outApk}\n";
    echo "==================================zipalign_apk\n";
    updateJiaguTaskInfo($pdo, $task['id'], 'ZIP对齐');
    echo "正在zip对齐：{$outApk}\n";
    $zipalign = zipalign_apk($outApk);
    print_r($zipalign);
    if(!$zipalign[0]){
        updateJiaguTaskStatus($pdo, $task['id'], '加固失败');
        updateJiaguTaskInfo($pdo, $task['id'], $depth.'zipalign失败');
        remove_dir_recursive($temp_dir);
        del_osstemp($oss_temp, $localSavePath);//删除缓存底包
        return;
    }
    remove_file($outApk);//删除对齐前的文件
    $output_apk = $zipalign[1];//使用对齐后的文件
    
    
    echo "==================================检测签名校验类型，如果是使用平台签名校验则要执行签名反之不执行签名\n";
    if($task['sign_type'] != 1){
        //不为1，代表不使用平台证书校验，因此不执行签名功能

        $release_dir = realpath(__DIR__ . '/../release');
        if (!$release_dir) {
            mkdir(__DIR__ . '/../release', 0755, true);
            $release_dir = realpath(__DIR__ . '/../release');
        }
        $filename = basename($output_apk, '.apk') . '.signed.apk';
        $signed_apk = $release_dir . DIRECTORY_SEPARATOR . $filename;
        //源文件：$output_apk
        //新文件：$signed_apk
        //将源文件复制到新文件
        if (!copy($output_apk, $signed_apk)) {
            echo "复制 APK 文件失败\n";
            remove_dir_recursive($temp_dir);//删除临时目录
            del_osstemp($oss_temp, $localSavePath);//删除缓存底包
            updateJiaguTaskStatus($pdo, $task['id'], '加固失败');
            updateJiaguTaskInfo($pdo, $task['id'], $depth.'复制 APK 文件失败');
            return;
        }
        $result = updateJiaguApkInfo($pdo, $signed_apk, $task['id'] );//将加固后的文件信息更新到任务表
        echo "更新信息到数据库结果：";
        print_r($result);
        remove_dir_recursive($temp_dir);//删除临时目录
        del_osstemp($oss_temp, $localSavePath);//删除缓存底包
        updateJiaguTaskStatus($pdo, $task['id'], '加固成功');
        updateJiaguTaskInfo($pdo, $task['id'], $depth.'加固成功,下载后请自行签名');
        return;
    }
    echo "==================================开始签名\n";
    updateJiaguTaskStatus($pdo, $task['id'], '正在签名');
    $release_dir = realpath(__DIR__ . '/../release');
    if (!$release_dir) {
        mkdir(__DIR__ . '/../release', 0755, true);
        $release_dir = realpath(__DIR__ . '/../release');
    }
    
    $filename = basename($output_apk, '.apk') . '.signed.apk';
    $signed_apk = $release_dir . DIRECTORY_SEPARATOR . $filename;
    
    $result = sign_apk($keystore, $alias, $storepass, $keypass, $output_apk, $signed_apk, null, $apksigner_jar);//集成签名环境
    echo "==================================签名结果\n";
    print_r($result);
    if(!$result[0]){
        updateJiaguTaskStatus($pdo, $task['id'], '签名失败');
        updateJiaguTaskInfo($pdo, $task['id'], '请检查签名证书信息是否正确或尝试更换证书文件');
        remove_dir_recursive($temp_dir);//删除临时目录
        del_osstemp($oss_temp, $localSavePath);//删除缓存底包
        remove_file($output_apk);//删除未签名的文件
        return;
    }
    //exit;
    echo "删除V4签名的文件".$result[2].".idsig\n";
    remove_file($result[2].".idsig");//删除V4签名的文件
    
    $result = updateJiaguApkInfo($pdo, $signed_apk, $task['id'] );//将签名后的文件信息更新到任务表
    print_r($result);
    
    remove_dir_recursive($temp_dir);//删除临时目录
    del_osstemp($oss_temp, $localSavePath);//删除缓存底包
    updateJiaguTaskStatus($pdo, $task['id'], '加固成功');
    updateJiaguTaskInfo($pdo, $task['id'], $depth.'加固成功,请点击任务下载');
    
    echo "=====================================================================\n";
    
    
    
    
    
    
    
    
    
    
    
    
    
}















//=====================================================================

/**
 * 从签名证书文件（JKS / keystore / PKCS12）中提取证书 SHA-256
 *
 * @param string $keystorePath 证书文件路径
 * @param string $storepass    keystore 密码
 * @param string|null $alias   alias（可选，为 null 时自动取第一个）
 * @return string|false        64位 hex 的 sha256，失败返回 false
 */
function get_cert_sha256_from_keystore(string $keystorePath, string $storepass, ?string $alias = null)
{
    if (!is_file($keystorePath)) {
        return false;
    }

    $cmd = 'keytool -list -v '
         . '-keystore ' . escapeshellarg($keystorePath)
         . ' -storepass ' . escapeshellarg($storepass);

    if (!empty($alias)) {
        $cmd .= ' -alias ' . escapeshellarg($alias);
    }

    $cmd .= ' 2>&1';

    $output = shell_exec($cmd);
    if ($output === null || $output === '') {
        return false;
    }

    /*
     * keytool 输出示例：
     * SHA256: D0:21:33:72:B3:A9:36:2F:...
     */
    if (!preg_match('/SHA256:\s*([0-9A-Fa-f:]+)/', $output, $m)) {
        return false;
    }

    // 去掉冒号、转小写
    $hex = strtolower(str_replace(':', '', $m[1]));

    if (strlen($hex) !== 64) {
        return false;
    }

    return $hex;
}

function updateJiaguApkInfo(PDO $pdo, string $filePath, int $taskId)
{
    if ($taskId <= 0) {
        return false;
    }

    if (!is_file($filePath)) {
        return false;
    }

    // 只保存文件名，不保存路径
    $fileName = basename($filePath);

    // 文件大小（字节）
    $size = filesize($filePath);
    if ($size === false) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE cainiao_jiagu_task
        SET injected_apk = :apk,
            size = :size,
            completed_at = NOW()
        WHERE id = :id
    ");

    return $stmt->execute([
        ':apk'  => $fileName,
        ':size' => $size,
        ':id'   => $taskId,
    ]);
}


function vm_jiagu($jiagujar, $xmx, $temp_dir, $apk_file, array $task)
{
    // ---------- 基础校验 ----------
    if (!is_file($jiagujar) || !is_file($apk_file) || !is_dir($temp_dir)) {
        remove_dir_recursive($temp_dir);
        return [false, '基础参数校验失败', null];
    }

    $temp_dir = rtrim($temp_dir, '/');

    $apkName = basename($apk_file);
    $apkNameNoExt = pathinfo($apkName, PATHINFO_FILENAME);

    $tempApkPath = $temp_dir . '/' . $apkName;
    $ruleFile    = $temp_dir . '/convertRules.txt';

    // ---------- 拷贝 APK ----------
    if (!copy($apk_file, $tempApkPath)) {
        remove_dir_recursive($temp_dir);
        return [false, '复制 APK 到临时目录失败', null];
    }

    // ---------- 写规则 ----------
    if (empty($task['rules'])) {
        $task['rules'] = 'class *';
    }

    $rules = trim($task['rules']);
    if ($rules === '' || file_put_contents($ruleFile, $rules) === false) {
        remove_dir_recursive($temp_dir);
        return [false, '生成加固规则失败', null];
    }

    // ---------- 处理 -s 参数 ----------
    $signType = intval($task['sign_type'] ?? 0);
    $hash256  = trim($task['hash256'] ?? '');

    $signArg = '';
    if (($signType === 1 || $signType === 2) && $hash256 !== '') {
        $signArg = ' -s ' . escapeshellarg($hash256);
    }
    //$cpuRange = getTasksetRange();
    $cpuRange = 0;//对于大文件处理，只能分配单核，否则核心+多线程，内存会爆炸
    echo "本次任务调用CPU核心为：{$cpuRange}\n";
    // ---------- 执行加固（shell_exec）----------
    /*$cmd = sprintf(
        'nice -n 19 taskset -c %s java -Xmx%s -jar %s apk -a %s -r %s%s 2>&1; echo "__RET__:$?"',
        $cpuRange,
        escapeshellarg($xmx),
        escapeshellarg($jiagujar),
        escapeshellarg($tempApkPath),
        escapeshellarg($ruleFile),
        $signArg
    );*/
    /*
    -Xmx	堆内存	不限制/自动	768m~1024m
    -XX:MaxMetaspaceSize	类元数据	不限制	256m
    -XX:CompressedClassSpaceSize	类指针空间	1G	128m
    */
    $xmx = '1024m';
    $cmd = sprintf(
        'bash -c \'ulimit -v 3145728; nice -n 19 taskset -c %s java -Xmx%s -XX:MaxMetaspaceSize=256m -XX:CompressedClassSpaceSize=128m -jar %s apk -a %s -r %s%s 2>&1; echo "__RET__:$?"\'',
        $cpuRange,
        escapeshellarg($xmx),
        escapeshellarg($jiagujar),
        escapeshellarg($tempApkPath),
        escapeshellarg($ruleFile),
        $signArg
    );

    echo "执行VMP加固命令：{$cmd}\n";
    $outputStr = shell_exec($cmd);
    if ($outputStr === null) {
        remove_dir_recursive($temp_dir);
        return [false, 'shell_exec 执行失败', null];
    }

    // ---------- 解析输出和返回码 ----------
    $lines = explode("\n", trim($outputStr));
    $lastLine = end($lines);

    $retCode = -1;
    if (preg_match('/__RET__:(\d+)/', $lastLine, $m)) {
        $retCode = intval($m[1]);
        array_pop($lines); // 移除返回码行
    }

    if ($retCode !== 0) {
        remove_dir_recursive($temp_dir);
        echo "VMP加固失败,日志：" . implode("\n", $lines);
        return [false, "VMP 加固失败,请调整加固规则", null];
    }

    // ---------- 校验输出 ----------
    $vmpDir   = $temp_dir . '/vmp';
    $outApk   = $vmpDir . '/' . $apkNameNoExt . '-vmp.apk';
    $finalApk = $temp_dir . '/' . $apkNameNoExt . '-vmp.apk';

    if (!is_file($outApk) || !copy($outApk, $finalApk)) {
        remove_dir_recursive($temp_dir);
        return [false, '加固输出文件处理失败', null];
    }

    // ---------- 成功后的清理 ----------
    remove_file($tempApkPath);
    remove_file($ruleFile);
    remove_dir_recursive($vmpDir);

    return [true, 'VMP 加固完成', $finalApk];
}

/**
 * 获取系统CPU核心数
 */
function getCpuCoreCount(): int
{
    // 优先使用 nproc
    $output = [];
    @exec('nproc 2>/dev/null', $output);

    if (!empty($output) && is_numeric($output[0])) {
        return (int)$output[0];
    }

    // 备用方案：读取 /proc/cpuinfo
    if (is_file('/proc/cpuinfo')) {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        preg_match_all('/^processor/m', $cpuinfo, $matches);
        if (!empty($matches[0])) {
            return count($matches[0]);
        }
    }

    // 最低兜底
    return 1;
}
/**
 * 计算加固任务应该使用的核心数
 */
function getJiaguCpuCount(): int
{
    $total = getCpuCoreCount();

    if ($total <= 2) {
        return 1;
    }

    if ($total == 3) {
        return 2;
    }

    return (int)floor($total / 2);
}
/**
 * 生成 taskset 核心范围字符串
 */
function getTasksetRange(): string
{
    $total = getCpuCoreCount();
    $use   = getJiaguCpuCount();

    // 让网站优先使用前半核
    $start = $total - $use;
    $end   = $total - 1;

    if ($start == $end) {
        return (string)$start;
    }

    return $start . '-' . $end;
}



function remove_dir_recursive(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }

    $files = scandir($dir);
    if ($files === false) {
        return false;
    }

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $path = $dir . '/' . $file;

        if (is_dir($path)) {
            if (!remove_dir_recursive($path)) {
                return false;
            }
        } else {
            if (!@unlink($path)) {
                return false;
            }
        }
    }

    return @rmdir($dir);
}
function remove_file(string $file): bool
{
    if (!file_exists($file)) {
        return true;
    }
    return @unlink($file);
}




function updateJiaguTaskInfo(PDO $pdo, int $taskId, string $status)
{
    $now = null;

    $stmt = $pdo->prepare("
        UPDATE cainiao_jiagu_task 
        SET status_info = :status
        WHERE id = :id
    ");

    $stmt->execute([
        ':status'       => $status,
        ':id'           => $taskId
    ]);

    echo "\n[" . date('Y-m-d H:i:s') . "] 已更新任务 #$taskId 信息为：$status\n";
}
function updateJiaguTaskStatus(PDO $pdo, int $taskId, string $status)
{
    $now = null;

    // 编译成功或失败时更新 completed_at
    if ($status === '加固成功' || stripos($status, '失败') !== false) {
        $now = date('Y-m-d H:i:s');
    }

    $stmt = $pdo->prepare("
        UPDATE cainiao_jiagu_task 
        SET status_text = :status, completed_at = :completed_at 
        WHERE id = :id
    ");

    $stmt->execute([
        ':status'       => $status,
        ':completed_at' => $now,
        ':id'           => $taskId
    ]);

    echo "\n[" . date('Y-m-d H:i:s') . "] 已更新任务 #$taskId 状态为：$status\n";
}