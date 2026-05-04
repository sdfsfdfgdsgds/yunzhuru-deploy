<?php

/*function getMyAppList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = $user['id'];

    $table = 'cainiao_apk';

    $page = isset($input['page']) && is_numeric($input['page']) ? max(1, (int)$input['page']) : 1;
    $limit = isset($input['limit']) && is_numeric($input['limit']) ? max(1, (int)$input['limit']) : 20;
    $offset = ($page - 1) * $limit;

    //$where = "WHERE user_id = :user_id";
    //$params = [':user_id' => $userId];
    $params = [];
    
    $where = "WHERE 1=1";

    if($user['role'] !== 'admin'){
        $where .= " AND user_id = :user_id";
        $params = [':user_id' => $userId];
    }else if($user['role'] == 'admin'){
        if (!empty($input['uid'])) {
        $where .= " AND user_id LIKE :user_id";
        $params[':user_id'] = $input['uid'];
    }
    }
    

    if (!empty($input['name'])) {
        $where .= " AND name LIKE :name";
        $params[':name'] = '%' . $input['name'] . '%';
    }
    
    if (!empty($input['appid'])) {
        $where .= " AND id LIKE :id";
        $params[':id'] = $input['appid'];
    }

    if (!empty($input['version'])) {
        $where .= " AND version LIKE :version";
        $params[':version'] = '%' . $input['version'] . '%';
    }

    if (!empty($input['package'])) {
        $where .= " AND package LIKE :package";
        $params[':package'] = '%' . $input['package'] . '%';
    }
    //获取上传保留最长天数
    $uploadday = (int)Auth::getSetting($pdo, "uploadday", "3");
    $delete_app = autoClearExpiredAppFile($pdo, $uploadday);//自动删除过期安装包
    
    //获取可用总容量
    $storageMB = (int)Auth::getSetting($pdo, "storage", "500");
    $now = date('Y-m-d H:i:s');
    $isVip = isset($user['vip_expire_time']) && $user['vip_expire_time'] > $now;
    if ($isVip) {
        $storageMB = (int)Auth::getSetting($pdo, "vipstorage", "5120");
    } else {
        $storageMB = (int)Auth::getSetting($pdo, "storage", "512");
    }
    if ($user['role'] == 'admin') {
        // 获取当前目录所在分区的可用空间（单位：字节）
        $freeBytes = disk_free_space(__DIR__);
        // 转换为 MB，向下取整
        $storageMB = (int)($freeBytes / 1024 / 1024);//管理员没用可用总空间，而是返回服务器剩余空间
    }
    $storageGB = round($storageMB / 1024, 2); // 保留2位小数
    $totalBytes = $storageGB * 1024 * 1024 * 1024;
    // 计算已用容量（单位字节）
    if ($user['role'] === 'admin') {
        $stmt = $pdo->query("SELECT SUM(size) FROM `$table`");
        $usedBytes = (int)$stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT SUM(size) FROM `$table` WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $usedBytes = (int)$stmt->fetchColumn();
    }
    // 已用容量 GB，保留两位小数
    $usedGB = round($usedBytes / (1024 * 1024 * 1024), 2);
    $usedPercent = $totalBytes > 0 ? round($usedBytes / $totalBytes * 100, 2) : 0;
    
    // 获取总数
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pages = (int)ceil($total / $limit);

    // 获取数据
    $dataStmt = $pdo->prepare("SELECT * FROM `$table` $where ORDER BY id DESC LIMIT :offset, :limit");
    foreach ($params as $key => $val) {
        $dataStmt->bindValue($key, $val);
    }
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $dataStmt->execute();

    $list = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // 查询被复用应用的信息并附加
    foreach ($list as &$app) {
        if ($app['config_mode'] == 1 && !empty($app['reuse_apk_id'])) {
            $stmt = $pdo->prepare("SELECT name, package FROM `$table` WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $app['reuse_apk_id']]);
            $reuseInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($reuseInfo) {
                $app['reuse_apk_name'] = $reuseInfo['name'];
                $app['reuse_apk_package'] = $reuseInfo['package'];
            } else {
                $app['reuse_apk_name'] = '';
                $app['reuse_apk_package'] = '';
            }
        } else {
            $app['reuse_apk_name'] = '';
            $app['reuse_apk_package'] = '';
        }
        
    }

    return [
        'list'  => $list,
        'total' => $total,
        'pages' => $pages,
        'page'  => $page,
        'used_storage' => $usedGB,
        'total_storage'=> $storageGB,
        'used_percent' => $usedPercent,
        //'delete_app' => $delete_app,
        //'delete_day' => $uploadday
    ];
}*/

//获取我的应用列表
function getMyAppList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = $user['id'];

    $table = 'cainiao_apk';

    $page = isset($input['page']) && is_numeric($input['page']) ? max(1, (int)$input['page']) : 1;
    $limit = isset($input['limit']) && is_numeric($input['limit']) ? max(1, (int)$input['limit']) : 20;
    $offset = ($page - 1) * $limit;

    //$where = "WHERE user_id = :user_id";
    //$params = [':user_id' => $userId];
    $params = [];
    
    $where = "WHERE 1=1";

    if($user['role'] !== 'admin'){
        $where .= " AND a.user_id = :user_id";
        $params = [':user_id' => $userId];
    }else if($user['role'] == 'admin'){
        if (!empty($input['uid'])) {
            $where .= " AND a.user_id LIKE :user_id";
            $params[':user_id'] = $input['uid'];
        }
    }
    
    if (!empty($input['name'])) {
        $where .= " AND a.name LIKE :name";
        $params[':name'] = '%' . $input['name'] . '%';
    }
    
    if (!empty($input['appid'])) {
        $where .= " AND a.id LIKE :id";
        $params[':id'] = $input['appid'];
    }
    
    if (!empty($input['version'])) {
        $where .= " AND a.version LIKE :version";
        $params[':version'] = '%' . $input['version'] . '%';
    }
    
    if (!empty($input['package'])) {
        $where .= " AND a.package LIKE :package";
        $params[':package'] = '%' . $input['package'] . '%';
    }

    // 按配置方式筛选（0=独享, 1=复用中）
    if (isset($input['config_mode']) && $input['config_mode'] !== '') {
        $where .= " AND a.config_mode = :config_mode";
        $params[':config_mode'] = (int)$input['config_mode'];
    }

    // 按复用目标筛选（查找所有复用了指定APPID的应用）
    if (!empty($input['reuse_apk_id'])) {
        $where .= " AND a.config_mode = 1 AND a.reuse_apk_id = :reuse_apk_id";
        $params[':reuse_apk_id'] = (int)$input['reuse_apk_id'];
    }
    
    // 只有管理员才能按 tag 查询
    if ($user['role'] === 'admin' && isset($input['tag']) && $input['tag'] !== '') {
        // 情况 1：前端选择“无标记” → 查询 tag IS NULL
        if ($input['tag'] === '无标记') {
            $where .= " AND a.tag IS NULL";
    
        // 情况 2：其他正常标记 → 模糊搜索
        } else {
            $where .= " AND a.tag LIKE :tag";
            $params[':tag'] = '%' . $input['tag'] . '%';
        }
    }
    
    // 维护任务：用Redis锁控制频率，每10分钟最多执行一次，避免卡住列表请求
    try {
        $redis = getRedisConnection(0);
        $lockKey = 'maintenance_lock';
        if (!$redis->exists($lockKey)) {
            $redis->setex($lockKey, 600, '1'); // 10分钟内不再执行
            $uploadday = (int)Auth::getSetting($pdo, "uploadday", "3");
            autoClearExpiredAppFile($pdo, $uploadday);
            checkVipExpireNotify($pdo);
        }
        $redis->close();
    } catch (Exception $e) {
        // Redis不可用时跳过维护任务，不影响列表查询
    }
    
    //获取可用总容量
    $storageMB = (int)Auth::getSetting($pdo, "storage", "500");
    $now = date('Y-m-d H:i:s');
    $isVip = isset($user['vip_expire_time']) && $user['vip_expire_time'] > $now;
    if ($isVip) {
        $storageMB = (int)Auth::getSetting($pdo, "vipstorage", "5120");
    } else {
        $storageMB = (int)Auth::getSetting($pdo, "storage", "512");
    }
    if ($user['role'] == 'admin') {
        // 获取当前目录所在分区的可用空间（单位：字节）
        $freeBytes = disk_free_space(__DIR__);
        // 转换为 MB，向下取整
        $storageMB = (int)($freeBytes / 1024 / 1024);//管理员没用可用总空间，而是返回服务器剩余空间
    }
    $storageGB = round($storageMB / 1024, 2); // 保留2位小数
    $totalBytes = $storageGB * 1024 * 1024 * 1024;
    // 计算已用容量（单位字节）
    if ($user['role'] === 'admin') {
        $stmt = $pdo->query("SELECT SUM(size) FROM `$table`");
        $usedBytes = (int)$stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("SELECT SUM(size) FROM `$table` WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $usedBytes = (int)$stmt->fetchColumn();
    }
    // 已用容量 GB，保留两位小数
    $usedGB = round($usedBytes / (1024 * 1024 * 1024), 2);
    $usedPercent = $totalBytes > 0 ? round($usedBytes / $totalBytes * 100, 2) : 0;
    
    // 获取总数
    
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` a $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pages = (int)ceil($total / $limit);

    // 获取数据
    $dataStmt = $pdo->prepare("
    SELECT 
            a.*,
            u.vip_expire_time,
            r.apk_id1 AS redirected_apk_id
        FROM `$table` a
        LEFT JOIN `cainiao_user` u ON a.user_id = u.id
        LEFT JOIN `cainiao_redirect` r ON r.apk_id1 = a.id
        $where
        ORDER BY a.id DESC
        LIMIT :offset, :limit
    ");
    foreach ($params as $key => $val) {
        $dataStmt->bindValue($key, $val);
    }
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $dataStmt->execute();

    $list = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // 批量预查询，避免循环内N+1查询
    $now = date('Y-m-d H:i:s');
    $appIds = array_column($list, 'id');

    // ③ 批量查询昨日访问量（一条SQL代替N条）
    $yesterdayVisitsMap = [];
    if (!empty($appIds)) {
        $yesterdayStart = date("Y-m-d 00:00:00", strtotime("-1 day"));
        $yesterdayEnd   = date("Y-m-d 23:59:59", strtotime("-1 day"));
        $placeholders = implode(',', array_fill(0, count($appIds), '?'));
        $visitStmt = $pdo->prepare("
            SELECT apk_id, COUNT(*) AS cnt
            FROM cainiao_request_stat
            WHERE apk_id IN ($placeholders)
              AND visit_time BETWEEN ? AND ?
            GROUP BY apk_id
        ");
        $visitParams = array_merge($appIds, [$yesterdayStart, $yesterdayEnd]);
        $visitStmt->execute($visitParams);
        foreach ($visitStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $yesterdayVisitsMap[$row['apk_id']] = (int)$row['cnt'];
        }
    }

    // 批量查询复用应用信息（一条SQL代替N条）
    $reuseIds = [];
    foreach ($list as $app) {
        if ($app['config_mode'] == 1 && !empty($app['reuse_apk_id'])) {
            $reuseIds[] = $app['reuse_apk_id'];
        }
    }
    $reuseMap = [];
    if (!empty($reuseIds)) {
        $reuseIds = array_unique($reuseIds);
        $placeholders = implode(',', array_fill(0, count($reuseIds), '?'));
        $reuseStmt = $pdo->prepare("SELECT id, name, package FROM `$table` WHERE id IN ($placeholders)");
        $reuseStmt->execute(array_values($reuseIds));
        foreach ($reuseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reuseMap[$row['id']] = $row;
        }
    }

    // 遍历列表，用预查询结果填充字段
    foreach ($list as &$app) {
        // ① 会员判断
        $app['is_vip'] = (!empty($app['vip_expire_time']) && $app['vip_expire_time'] > $now) ? 1 : 0;
        unset($app['vip_expire_time']);

        // ② 接管判断
        $app['is_taken_over'] = !empty($app['redirected_apk_id']) ? 1 : 0;

        // ③ 昨日访问量（从批量结果中取）
        $app['yesterday_visits'] = $yesterdayVisitsMap[$app['id']] ?? 0;

        // ④ 复用配置（从批量结果中取）
        if ($app['config_mode'] == 1 && !empty($app['reuse_apk_id']) && isset($reuseMap[$app['reuse_apk_id']])) {
            $app['reuse_apk_name'] = $reuseMap[$app['reuse_apk_id']]['name'];
            $app['reuse_apk_package'] = $reuseMap[$app['reuse_apk_id']]['package'];
        } else {
            $app['reuse_apk_name'] = '';
            $app['reuse_apk_package'] = '';
        }
    }

    return [
        'list'  => $list,
        'total' => $total,
        'pages' => $pages,
        'page'  => $page,
        'used_storage' => $usedGB,
        'total_storage'=> $storageGB,
        'used_percent' => $usedPercent,
        //'delete_app' => $delete_app,
        //'delete_day' => $uploadday
    ];
}







//获取复用应用的配置列表
function getMyReuseAppList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = $user['id'];

    $table = 'cainiao_apk';

    $page = isset($input['page']) && is_numeric($input['page']) ? max(1, (int)$input['page']) : 1;
    $limit = isset($input['limit']) && is_numeric($input['limit']) ? max(1, (int)$input['limit']) : 20;
    $offset = ($page - 1) * $limit;

    //$where = "WHERE user_id = :user_id";
    //$params = [':user_id' => $userId];
    $params = [];
    
    $where = "WHERE 1=1";

    if($user['role'] !== 'admin'){
        //非管理员,只能查自己uid下的应用
        $where .= " AND a.user_id = :user_id";
        $params = [':user_id' => $userId];
    }else{
        //是管理员，要查应用归属user_id下的应用，即a.id=$input['appid']的user_id
        if (isset($input['appid']) && is_numeric($input['appid'])) {
            // 先根据传入的appid查找该应用对应的user_id
            $targetUserId = null;
            
            $findStmt = $pdo->prepare("SELECT user_id FROM {$table} WHERE id = :appid LIMIT 1");
            $findStmt->execute([':appid' => $input['appid']]);
            $appInfo = $findStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($appInfo) {
                $targetUserId = $appInfo['user_id'];
                $where .= " AND a.user_id = :target_user_id";
                $params[':target_user_id'] = $targetUserId;
            }
        }else{
            // 管理员未传appid时，返回所有用户的应用（用于搜索筛选）
        }
    }

    // 按名称模糊搜索
    if (!empty($input['name'])) {
        $where .= " AND (a.name LIKE :name OR a.package LIKE :name_pkg)";
        $params[':name'] = '%' . $input['name'] . '%';
        $params[':name_pkg'] = '%' . $input['name'] . '%';
    }

    // 获取数据
    $dataStmt = $pdo->prepare("
    SELECT 
            a.id, a.package, a.name
        FROM `$table` a
        LEFT JOIN `cainiao_user` u ON a.user_id = u.id
        $where
        ORDER BY a.id DESC
        LIMIT :offset, :limit
    ");
    foreach ($params as $key => $val) {
        $dataStmt->bindValue($key, $val);
    }
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $dataStmt->execute();
    $list = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
    return [
        'list'  => $list,
        'total' => $total,
        'pages' => $pages,
        'page'  => $page,
    ];
}

function setTag(PDO $pdo, array $input){
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权调用此接口');
    }

    // 参数校验
    if (empty($input['appid'])) {
        throw new Exception('缺少 APPID');
    }
    if (!isset($input['tag'])) {
        throw new Exception('缺少 tag 参数');
    }

    $appid = (int)$input['appid'];
    $tag   = trim($input['tag']);

    // 检查应用是否存在
    $stmt = $pdo->prepare("SELECT id FROM cainiao_apk WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $appid]);
    if (!$stmt->fetch()) {
        throw new Exception('应用不存在');
    }

    // “无标记” → 保存为 NULL
    if ($tag === '无标记' || $tag === '') {
        $update = $pdo->prepare("UPDATE cainiao_apk SET tag = NULL WHERE id = :id");
        $update->execute([':id' => $appid]);

        return ['message' => '标记已清除'];
    }

    // 其他标记 → 正常写入
    $update = $pdo->prepare("UPDATE cainiao_apk SET tag = :tag WHERE id = :id");
    $update->execute([
        ':tag' => $tag,
        ':id'  => $appid
    ]);

    return [ 'message' => '标记成功'];
}


//转存文件到oss
function repost(PDO $pdo, array $input){
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权调用此接口');
    }

    $uploadDir = __DIR__ . "/../../uploads/"; // 本地文件储存目录
    $ossDir = "uploads/";               // OSS目录前缀

    // 参数校验
    if (empty($input['appid'])) {
        throw new Exception('缺少 APPID');
    }

    $appid = (int)$input['appid'];

    // 查询应用
    $stmt = $pdo->prepare("SELECT id, path, osspath FROM cainiao_apk WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $appid]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$app) {
        throw new Exception('应用不存在');
    }

    // 本地文件名（不含路径）
    $localFileName = $app['path'];
    if (empty($localFileName)) {
        throw new Exception('本地文件名为空');
    }

    $localFilePath = $uploadDir . $localFileName;

    if (!is_file($localFilePath)) {
        throw new Exception('本地文件不存在');
    }

    // 生成 OSS 存储路径
    $ossFilePath = $ossDir . $localFileName;

    $oss = new OSS();

    // 上传到 OSS（内网）
    $result = $oss->uploadFile($localFilePath, $ossFilePath);
    if ($result['code'] !== 200) {
        throw new Exception($result['message'] ?? 'OSS 上传失败');
    }

    // 写入 osspath
    $stmt = $pdo->prepare(
        "UPDATE cainiao_apk SET osspath = :osspath WHERE id = :id"
    );
    $stmt->execute([
        ':osspath' => $ossFilePath,
        ':id' => $appid
    ]);

    // 删除本地文件
    if (!unlink($localFilePath)) {
        throw new Exception('OSS 已成功，但本地文件删除失败');
    }

    return [
        'message' => '转存成功',
        'osspath' => $ossFilePath
    ];
}

function restoreFromOss(PDO $pdo, array $input){
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权调用此接口');
    }

    $uploadDir = __DIR__ . "/../../uploads/"; // 本地文件储存目录

    // 参数校验
    if (empty($input['appid'])) {
        throw new Exception('缺少 APPID');
    }

    $appid = (int)$input['appid'];

    // 查询应用
    $stmt = $pdo->prepare("SELECT id, path, osspath FROM cainiao_apk WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $appid]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$app) {
        throw new Exception('应用不存在');
    }

    if (empty($app['osspath'])) {
        throw new Exception('OSS 中不存在该文件');
    }

    // 本地文件名（不含路径）
    $localFileName = $app['path'];
    if (empty($localFileName)) {
        throw new Exception('本地文件名为空');
    }

    $localFilePath = $uploadDir . $localFileName;

    // 若本地已存在，直接阻止，防止覆盖
    if (is_file($localFilePath)) {
        throw new Exception('本地文件已存在，无需还原');
    }

    $oss = new OSS();

    // 从 OSS 下载到本地（内网）
    $result = $oss->downloadToLocal($app['osspath'], $localFilePath);
    if ($result['code'] !== 200) {
        throw new Exception($result['message'] ?? 'OSS 下载失败');
    }

    return [
        'message' => '还原成功',
        'local_path' => $localFilePath,
        'osspath' => $app['osspath']
    ];
}




// 自动删除过期安装包
function autoClearExpiredAppFile($pdo, $uploadday)
{
    $apkTable  = 'cainiao_apk';
    $userTable = 'cainiao_user';

    // 计算截止时间
    $expireTime = date('Y-m-d H:i:s', strtotime("-{$uploadday} days"));

    $sql = "
        SELECT a.id, a.name, a.upload_time, a.path, a.osspath
        FROM `$apkTable` a
        LEFT JOIN `$userTable` u ON u.id = a.user_id
        WHERE a.path <> '' 
          AND a.size > 0
          AND a.upload_time < :expireTime
          AND u.vip_expire_time <= NOW()
    ";
    
    //删除会员过期超过3天的应用
    $sql = "
        SELECT a.id, a.name, a.upload_time, a.path, a.osspath
        FROM `$apkTable` a
        LEFT JOIN `$userTable` u ON u.id = a.user_id
        WHERE a.path <> '' 
          AND a.size > 0
          AND a.upload_time < :expireTime
          AND (
                u.vip_expire_time IS NULL
                OR u.vip_expire_time <= DATE_SUB(NOW(), INTERVAL 3 DAY)
              )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':expireTime' => $expireTime]);
    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deleted = [];
    foreach ($apps as $app) {
        $filePath = __DIR__ . '/../../uploads/' . $app['path'];
        if ($app['path'] && is_file($filePath)) {
            @unlink($filePath);
        }
        $oss = new OSS();
        if(!empty($app['osspath'])){
            $ossdel[] = $oss->deleteFile($app['osspath']);
            $osspath[] = $app['osspath'];
        }
        $update = $pdo->prepare("UPDATE `$apkTable` SET path = '', osspath = NULL , size = 0 WHERE id = :id");
        $update->execute([':id' => $app['id']]);

        $deleted[] = [
            'id'          => (int)$app['id'],
            'name'        => $app['name'],
            'upload_time' => $app['upload_time'],
        ];
    }
    //在这里，自动清理从下载原文件的时候从oss拉到本地的缓存文件，这个缓存文件会被缓存到下载服务器去，如果有下载服的话，这里缓存不用太长时间
    $cacheDir = __DIR__ . '/../../apk_cache_oss_downloads/';
    $expireSeconds = 20 * 60; // 20分钟
    
    if (is_dir($cacheDir)) {
        $now = time();
    
        foreach (glob($cacheDir . '*') as $file) {
            if (!is_file($file)) {
                continue;
            }
    
            $fileTime = filemtime($file);
            if ($fileTime !== false && ($now - $fileTime) > $expireSeconds) {
                @unlink($file);
            }
        }
    }
    return [
        'expire_time'   => $expireTime,  // 调试方便
        'deleted_list'  => $deleted,
        'deleted_count' => count($deleted),
        'apps_raw'      => $apps,         // 原始结果，调试方便
        'ossdel'        => $ossdel,
        'osspath'       => $osspath
    ];
}
//会员过期提醒
function checkVipExpireNotify(PDO $pdo)
{
    $sql = "
        SELECT id, vip_expire_time,
               DATEDIFF(vip_expire_time, CURDATE()) AS days_left
        FROM cainiao_user
        WHERE vip_expire_time IS NOT NULL
        AND DATEDIFF(vip_expire_time, CURDATE()) IN (3,2,1,0)
    ";

    $stmt = $pdo->query($sql);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = [];

    foreach ($users as $u) {

        $userId = (int)$u['id'];
        $days = (int)$u['days_left'];

        // 检查今天是否已经发过
        $check = $pdo->prepare("
            SELECT id FROM cainiao_message
            WHERE send_user_id = 1
            AND receive_user_id = :uid
            AND message LIKE '【会员过期提醒】%'
            AND DATE(upload_time) = CURDATE()
            LIMIT 1
        ");

        $check->execute([':uid' => $userId]);

        if ($check->fetch()) {
            continue;
        }

        if ($days > 0) {

            $message = "【会员过期提醒】您的会员身份将在{$days}天后过期，过期后您的应用底包将不再继续保存，您也将失去会员相关权益";

        } else {

            $message = "【会员过期提醒】您的会员将于今天过期，您的云端应用底包将在3天后被自动清理";
        }

        Auth::sendSystemMessage($pdo, 1, $userId, $message);

        $sent[] = $userId;
    }

    return [
        'sent_count' => count($sent),
        'users' => $sent
    ];
}



//删除应用+配置
function deleteApp(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);

    if (empty($input['id']) || !is_numeric($input['id'])) {
        throw new Exception('参数错误：缺少应用 ID');
    }

    $appId = (int)$input['id'];
    $userId = (int)$user['id'];
    $apkTable = 'cainiao_apk';
    $taskTable = 'cainiao_inject_task';
    $JiagutaskTable = 'cainiao_jiagu_task';
    if($user['role']!=='admin'){
        // 查询应用记录
        $stmt = $pdo->prepare("SELECT * FROM `$apkTable` WHERE id = :id AND user_id = :user_id LIMIT 1");
        $stmt->execute([':id' => $appId, ':user_id' => $userId]);
    }else{
        // 查询应用记录
        $stmt = $pdo->prepare("SELECT * FROM `$apkTable` WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $appId]);
    }
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$app) {
            throw new Exception('未找到对应应用或无权限删除');
        }
    
    // 删除关联的 injected_apk 文件（如果存在）
    $stmt = $pdo->prepare("SELECT injected_apk FROM `$taskTable` WHERE apk_id = :apk_id AND injected_apk IS NOT NULL AND injected_apk != ''");
    $stmt->execute([':apk_id' => $appId]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $releaseDir = rtrim(__DIR__ . '/../../release', '/');

    foreach ($tasks as $task) {
        $relative = trim($task['injected_apk'], '/'); // 文件名
        $fullPath = $releaseDir . '/' . $relative;
    
        // 检查路径是否位于 release 目录下
        if (is_file($fullPath) && strpos(realpath($fullPath), realpath($releaseDir)) === 0) {
            @unlink($fullPath);
        }
    }
    
    // 删除关联的 injected_apk 文件（如果存在）
    $stmt = $pdo->prepare("SELECT injected_apk FROM `$JiagutaskTable` WHERE apk_id = :apk_id AND injected_apk IS NOT NULL AND injected_apk != ''");
    $stmt->execute([':apk_id' => $appId]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $releaseDir = rtrim(__DIR__ . '/../../release', '/');

    foreach ($tasks as $task) {
        $relative = trim($task['injected_apk'], '/'); // 文件名
        $fullPath = $releaseDir . '/' . $relative;
    
        // 检查路径是否位于 release 目录下
        if (is_file($fullPath) && strpos(realpath($fullPath), realpath($releaseDir)) === 0) {
            @unlink($fullPath);
        }
    }


    // 删除原始 APK 文件
    $filePath = __DIR__ . '/../../uploads/' . $app['path'];
    if (is_file($filePath)) {
        @unlink($filePath);
    }
    // 删除应用图标 文件
    if($app['icon'] !== 'android.png'){
        $iconPath = __DIR__ . '/../../icon/' . $app['icon'];
        if (is_file($iconPath)) {
            @unlink($iconPath);
        }
    }
    
    
    //删除oss端储存的旧文件
    $oss = new OSS();
    if(!empty($app['osspath'])){
        $oss->deleteFile($app['osspath']);
    }

    // 删除数据库记录
    if($user['role']!=='admin'){
        $delete = $pdo->prepare("DELETE FROM `$apkTable` WHERE id = :id AND user_id = :user_id");
        $delete->execute([':id' => $appId, ':user_id' => $userId]);
    }else{
        $delete = $pdo->prepare("DELETE FROM `$apkTable` WHERE id = :id");
        $delete->execute([':id' => $appId]);
        Auth::sendSystemMessage($pdo, $user['id'], $app['user_id'], '【应用删除提醒】您的应用“'.$app['name'].'”已被系统自动删除清理,该应用被系统识别到可能存在混淆/加固或违反相关规定等,若被误删或有疑问请联系管理员。QQ群：793107266。检测方式为云端自动多模式注入+自动实机测试');
    }
    
    // 删除桶中的配置文件（让壳回退 API 走重定向/兜底）
    try {
        require_once __DIR__ . '/../utils/S3Client.php';
        $bucketRows = $pdo->query("SELECT * FROM cainiao_s3_bucket WHERE enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
        $objectKey = "config/{$appId}.enc";
        foreach ($bucketRows as $b) {
            try {
                $client = new S3Client($b['access_key'], $b['secret_key'], $b['endpoint'], $b['bucket'], $b['region'] ?: 'auto');
                $client->deleteObject($objectKey);
            } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
        error_log("[BucketClean] 删除桶配置失败 appId={$appId}: " . $e->getMessage());
    }

    //删除redis缓存
    $redis = getRedisConnection(0);
    $redis->del($appId);
    $redis->select(2);//2号库，apk映射关系
    $redis->del($appId);
    $redis->close();
    
    return ['message' => '删除成功'.$mes];
}

//删除应用但不删除配置
function clearAppFile(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if (empty($input['id']) || !is_numeric($input['id'])) {
        throw new Exception('参数错误：缺少应用 ID');
    }
    $appId = (int)$input['id'];
    $userId = (int)$user['id'];
    $apkTable = 'cainiao_apk';
    $userTable = 'cainiao_user';
    // 查询应用记录
    $stmt = $pdo->prepare("SELECT * FROM `$apkTable` WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $appId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) {
        throw new Exception('未找到对应应用记录');
    }
    // 非管理员只能删除自己的应用
    if ($user['role'] !== 'admin' && (int)$app['user_id'] !== $userId) {
        throw new Exception('无权限删除该应用');
    }
    // 如果是管理员，但该应用属于其他用户，需判断该用户是否是VIP
    if ($user['role'] === 'admin' && (int)$app['user_id'] !== $userId) {
        $stmt = $pdo->prepare("SELECT vip_expire_time FROM `$userTable` WHERE id = :uid LIMIT 1");
        $stmt->execute([':uid' => $app['user_id']]);
        $appOwner = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($appOwner && strtotime($appOwner['vip_expire_time']) > time()) {
            throw new Exception('该应用所属用户是VIP，管理员无法删除其安装包文件');
        }
    }
    
    
    // ===== 删除时间限制（上传X小时内不允许删除，管理员除外）=====
    $limitHours = 12; // 限制小时数，可自行调整
    
    if ($user['role'] !== 'admin' && !empty($app['upload_time'])) {
    
        $uploadTime = strtotime($app['upload_time']);
        $expireTime = $uploadTime + ($limitHours * 3600);
        $now = time();
    
        if ($now < $expireTime) {
    
            $remainSeconds = $expireTime - $now;
            $remainHours = ceil($remainSeconds / 3600);
    
            throw new Exception($remainHours . '小时后才可以删除');
        }
    }
    
    
    // 删除原始 APK 文件
    $filePath = __DIR__ . '/../../uploads/' . $app['path'];
    if ($app['path'] && is_file($filePath)) {
        @unlink($filePath);
    }
    //删除oss端储存的旧文件
    $oss = new OSS();
    if(!empty($app['osspath'])){
        $oss->deleteFile($app['osspath']);
    }
    // 清空 path 字段
    $update = $pdo->prepare("UPDATE `$apkTable` SET path = '', osspath = NULL, size = 0 WHERE id = :id");
    $update->execute([':id' => $appId]);

    return ['message' => '安装包文件已删除，路径已清空'];
}



//上传应用
function uploadApk(PDO $pdo, array $input)
{
    set_time_limit(180);
    $free = disk_free_space(__DIR__); // 获取根目录剩余空间（字节）
    $limit = 3 * 1024 * 1024 * 1024; // 3GB
    
    if ($free < $limit) {
        throw new Exception('服务器储存空间超过阈值,请联系管理员：' . $free);
    }
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $apkTable = 'cainiao_apk';
    if(!Auth::getSetting($pdo,"upload","1")){
        throw new Exception('文件上传功能已关闭');
    }
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('文件上传失败');
    }

    $file = $_FILES['file'];
    $originalName = $file['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext !== 'apk') {
        throw new Exception('仅支持 .apk 文件');
    }
    //非管理员，限制最大上传文件
    if($user['role'] !=='admin'){
        
        $maxfile = Auth::getSetting($pdo,"maxfile","150");
        $now = date('Y-m-d H:i:s');
        $isVip = isset($user['vip_expire_time']) && $user['vip_expire_time'] > $now;
        if ($isVip) {
            $maxfile = Auth::getSetting($pdo,"vipmaxfile","256");
        } else {
            $maxfile = Auth::getSetting($pdo,"maxfile","150");
        }
        if ($file['size'] > $maxfile * 1024 * 1024) {
            throw new Exception("文件过大，最大支持 {$maxfile}MB");
        }
    }
    $stmt = $pdo->prepare("SELECT SUM(size) FROM `$apkTable` WHERE user_id = :uid");
    $stmt->execute([':uid' => $userId]);
    $totalSize = (int)$stmt->fetchColumn();
    
    $storage = Auth::getSetting($pdo,"storage","512");//单位M，默认取设置
    //新版根据是否是VIP取不同设置
    $now = date('Y-m-d H:i:s');
    $isVip = isset($user['vip_expire_time']) && $user['vip_expire_time'] > $now;
    if ($isVip) {
        $storage = (int)Auth::getSetting($pdo, "vipstorage", "5120");
    } else {
        $storage = (int)Auth::getSetting($pdo, "storage", "512");
    }
    $maxSize = 1024 * 1024 *  $storage;
    if($user['role'] !=='admin'){
        if (($totalSize + $file['size']) > $maxSize) {
            throw new Exception("您已累计上传超过 {$storage}MB 的 APK 文件，无法继续上传");
        }
    }
    
    if (!$isVip) {
        $stmt = $pdo->prepare(
            "SELECT
                u.app_count,
                COUNT(DISTINCT a.package) AS used_count
             FROM cainiao_user u
             LEFT JOIN cainiao_apk a ON a.user_id = u.id
             WHERE u.id = :uid
             GROUP BY u.id
             LIMIT 1"
        );
        $stmt->execute([':uid' => $user['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
        $appCount  = (int)($row['app_count'] ?? 0);
        $usedCount = (int)($row['used_count'] ?? 0);
    
        // 系统级最大应用数（0 表示不启用）
        $maxapp_count = (int)Auth::getSetting($pdo, "app_count", "0");
    
        // ===== 计算最终允许的最大应用数量 =====
        if ($maxapp_count > 0) {
            // 系统配置与用户配置取较大值
            $finalLimit = max($appCount, $maxapp_count);
        } else {
            // 仅使用用户自己的限制
            $finalLimit = $appCount;
        }
    
        // ===== 执行限制判断 =====
        if ($finalLimit > 0 && $usedCount >= $finalLimit) {
            throw new Exception(
                "您最多允许上传 {$finalLimit} 个应用，如果需要增加应用数量，请联系管理员，小流量用户可免费扩增"
            );
        }
    }



    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$apkTable` 
        WHERE user_id = :uid AND name = '正在解包' AND version = '正在解包' AND package = '正在解包'");
    $stmt->execute([':uid' => $userId]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new Exception('上一个应用正在解包中，请稍后再上传');
    }

    $tmpPath = $file['tmp_name'];
    $md5 = md5_file($tmpPath);
    $fileName = "{$userId}_{$md5}.apk";
    $uploadDir = __DIR__ . '/../../uploads/';
    $savedPath = $uploadDir . $fileName;
    if (!hasLaunchActivity($tmpPath)) {
        throw new Exception("不支持模块类APK文件上传");
    }
    //判断是否是分包应用
    if(isSplitApk($tmpPath)){
        throw new Exception('不支持上传分包应用');
    }
    //混淆检测
    $result = isApkObfuscated($tmpPath, $pdo);
    if ($result['matched']) {
        throw new Exception("不支持的应用:" . $result['message']);
    }
    
    //框架检测
    $framework = detectApkFramework($tmpPath);
    
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$apkTable` WHERE user_id = :user_id AND path = :path");
    $stmt->execute([':user_id' => $userId, ':path' => $fileName]);

    if ((int)$stmt->fetchColumn() === 0) {
        if (!move_uploaded_file($tmpPath, $savedPath)) {
            throw new Exception('保存文件失败');
        }

        $insertStmt = $pdo->prepare("INSERT INTO `$apkTable` 
            (name, version, package, path, user_id, size, upload_time) 
            VALUES ('正在解包', '正在解包', '正在解包', :path, :user_id, :size, NOW())");

        $insertStmt->execute([
            ':path'    => $fileName,
            ':user_id' => $userId,
            ':size'    => $file['size']
        ]);

        $apkId = $pdo->lastInsertId();

        $apkInfo = parseApkInfoWithAapt($savedPath);
        if (!$framework) {
            $framework = '';
        }else{
            $apkInfo['name'] = $apkInfo['name'] . "({$framework})";
        }
        // ❗❗❗ 在此处添加判断逻辑
        if ($apkInfo['package'] === '未知包名') {
            // 删除已保存的 APK 和数据库记录
            @unlink($savedPath);
            $pdo->prepare("DELETE FROM `$apkTable` WHERE id = :id")->execute([':id' => $apkId]);
            throw new Exception("上传失败：未识别到包名");
        }
        
        
        
        //检查包名独家保护
        $stmt = $pdo->prepare("SELECT * FROM cainiao_protect WHERE package = :pkg LIMIT 1");
        $stmt->execute([':pkg' => $apkInfo['package']]);
        $protect = $stmt->fetch(PDO::FETCH_ASSOC);
        if($protect){
            if($user['role1'] !=='admin'){
                $currentTime = new DateTime();
                $uploadTime = new DateTime($protect['upload_time']);
                //保护未过期
                if ($currentTime < $uploadTime) {
                    if($user['id'] !== $protect['user_id']){
                        @unlink($savedPath);
                        $pdo->prepare("DELETE FROM `$apkTable` WHERE id = :id")->execute([':id' => $apkId]);
                        throw new Exception("该应用受保护,本平台拒绝处理该应用");
                    }
                }
            }
        }
        

        // 查询黑名单
        $stmt = $pdo->prepare("
            SELECT message 
            FROM cainiao_backage 
            WHERE package = :pkg
               OR :appName LIKE CONCAT('%', package, '%')
            LIMIT 1
        ");
        
        $stmt->execute([
            ':pkg'     => $apkInfo['package'],  // 包名（精确匹配）
            ':appName' => $apkInfo['name']      // 应用名称（被检测）
        ]);
        $blackRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($blackRow) {
            @unlink($savedPath);
            $pdo->prepare("DELETE FROM `$apkTable` WHERE id = :id")->execute([':id' => $apkId]);
            throw new Exception($blackRow['message']);
        }
        
        
        
        
        
        
        //图标提取（优先ico.jar，失败fallback到aapt2）
        $icon = extractApkIcon($savedPath, __DIR__ . '/../../icon/', $apkId);
        if (!$icon) {
            $icon = extractApkIcon_aapt($savedPath, __DIR__ . '/../../icon/', $apkId);
        }
        if (!$icon) {
            $icon = 'android.png';
        }

        $sign = extractApkSignatureBase64($savedPath);
        
        
        $osspath = null;
        if(Auth::getSetting($pdo,"ossbackup","0") == 1){
            $oss = new OSS();
            $oss_up = $oss->uploadFile($savedPath,'uploads/' . $fileName);
            if($oss_up['code'] == 200){
                $osspath = $oss_up['oss_path'];
                unlink($savedPath);//删除本地文件记录，但是数据库中依旧记录文件名，才能找到下载
                //$fileName = 'oss';//上传到oss之后呢，本地就不存放文件路径了，存放一个oss标记，告诉注入器从oss服务器拉取
            }
        }
        
        
        $update = $pdo->prepare("UPDATE `$apkTable` SET 
            name = :name, version = :version, package = :package , icon = :icon , sign = :sign ,osspath = :osspath, path = :path
            WHERE id = :id");
        $update->execute([
            ':name'    => $apkInfo['name'],
            ':version' => $apkInfo['version'],
            ':package' => $apkInfo['package'],
            ':icon'    => $icon,
            ':sign'    => $sign,
            ':id'      => $apkId,
            ':osspath' => $osspath,
            ':path'    => $fileName
        ]);
        
        
    
        
        return [
            'message' => '上传并解析成功'.$result['message'],
            'oss' => $oss_up,
            'md5'     => $md5
        ];
    } else {
        throw new Exception("应用已存在");
    }
}



//重传应用
function replaceApk(PDO $pdo, array $input)
{
    set_time_limit(180);
    $free = disk_free_space(__DIR__); // 获取根目录剩余空间（字节）
    $limit = 3 * 1024 * 1024 * 1024; // 3GB
    if ($free < $limit) {
        throw new Exception('服务器储存空间超过阈值,请联系管理员：' . $free);
    }
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $apkTable = 'cainiao_apk';

    if (empty($_POST['apk_id'])) {
        throw new Exception('缺少参数：id' . $_POST['apk_id']);
    }

    $apkId = (int)$_POST['apk_id'];

    // 查询原应用信息
    $stmt = $pdo->prepare("SELECT * FROM `$apkTable` WHERE id = :id");
    $stmt->execute([':id' => $apkId]);
    $oldApk = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$oldApk) {
        throw new Exception('应用不存在');
    }

    if ((int)$oldApk['user_id'] !== $userId) {
        if($user['role'] !== 'admin'){
            throw new Exception('无权限操作该应用');
        }else{
            $userId = $oldApk['user_id'];//将管理员id设置为用户id
        }
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('文件上传失败');
    }

    $file = $_FILES['file'];
    $originalName = $file['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext !== 'apk') {
        throw new Exception('仅支持 .apk 文件');
    }
    if($user['role'] !=='admin'){
        $maxfile = Auth::getSetting($pdo, "maxfile", "150");
        $now = date('Y-m-d H:i:s');
        $isVip = isset($user['vip_expire_time']) && $user['vip_expire_time'] > $now;
        if ($isVip) {
            $maxfile = Auth::getSetting($pdo,"vipmaxfile","256");
        } else {
            $maxfile = Auth::getSetting($pdo,"maxfile","150");
        }
        if ($file['size'] > $maxfile * 1024 * 1024) {
            throw new Exception("文件过大，最大支持 {$maxfile}MB");
        }
    }
    
    $stmt = $pdo->prepare("SELECT SUM(size) FROM `$apkTable` WHERE user_id = :uid");
    $stmt->execute([':uid' => $userId]);
    $totalSize = (int)$stmt->fetchColumn();
    $now = date('Y-m-d H:i:s');
    $isVip = isset($user['vip_expire_time']) && $user['vip_expire_time'] > $now;
    if ($isVip) {
        $storage = (int)Auth::getSetting($pdo, "vipstorage", "5120");
    } else {
        $storage = (int)Auth::getSetting($pdo, "storage", "512");
    }
    $maxSize = 1024 * 1024 *  $storage;

    if($user['role'] !=='admin'){
        if (($totalSize + $file['size']) > $maxSize) {
            throw new Exception("您已累计上传超过 {$storage}MB 的 APK 文件，无法继续上传");
        }
    }
    $tmpPath = $file['tmp_name'];
    $newMd5 = md5_file($tmpPath);
    $oldMd5 = pathinfo($oldApk['path'], PATHINFO_FILENAME);
    $oldMd5 = preg_replace('/^\d+_/', '', $oldMd5);

    if ($newMd5 === $oldMd5) {
        throw new Exception("新上传的文件与原文件md5指纹相同，无需替换。newMd5={$newMd5}, oldMd5={$oldMd5}, oldPath={$oldApk['path']}");
    }

    // ✅ 检查该用户是否已上传相同 MD5 的文件
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$apkTable` WHERE user_id = :uid AND path LIKE :md5pattern");
    $stmt->execute([
        ':uid' => $userId,
        ':md5pattern' => "%_{$newMd5}.apk"
    ]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new Exception('该文件您已上传过，不能重复替换');
    }
    
    // 解析 APK 信息
    $apkInfo = parseApkInfoWithAapt($tmpPath);
    if ($apkInfo['package'] !== $oldApk['package']) {
        throw new Exception('包名不一致，不允许替换' . "原包名{$oldApk['package']},新包名{$apkInfo['package']}");
    }
    if (!hasLaunchActivity($tmpPath)) {
        throw new Exception("不支持模块类APK文件上传");
    }
    //判断是否是分包应用
    if(isSplitApk($tmpPath)){
        throw new Exception('不支持上传分包应用');
    }
    // 混淆检测
    $result = isApkObfuscated($tmpPath, $pdo);
    if ($result['matched']) {
        throw new Exception("不支持的应用:" . $result['message']);
    }
    //框架应用检测
    $framework = detectApkFramework($tmpPath);
    if (!$framework) {
        $framework = '';
    }else{
        $apkInfo['name'] = $apkInfo['name'] . "({$framework})";
    }

    $uploadDir = __DIR__ . '/../../uploads/';
    $newFileName = "{$userId}_{$newMd5}.apk";
    $savedPath = $uploadDir . $newFileName;

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (!move_uploaded_file($tmpPath, $savedPath)) {
        throw new Exception('保存文件失败');
    }
    
    // 查询黑名单
    $stmt = $pdo->prepare("
        SELECT message 
        FROM cainiao_backage 
        WHERE package = :pkg
           OR :appName LIKE CONCAT('%', package, '%')
        LIMIT 1
    ");
    
    $stmt->execute([
        ':pkg'     => $apkInfo['package'],  // 包名（精确匹配）
        ':appName' => $apkInfo['name']      // 应用名称（被检测）
    ]);
    $blackRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($blackRow) {
        @unlink($savedPath);
        throw new Exception($blackRow['message']);
    }

    // 删除旧文件
    $oldFilePath = $uploadDir . $oldApk['path'];
    if (is_file($oldFilePath)) {
        @unlink($oldFilePath);
    }
    
    $oss = new OSS();
    //删除oss端储存的旧文件
    if(!empty($oldApk['osspath'])){
        $oss->deleteFile($oldApk['osspath']);
    }
    
    //图标提取（优先ico.jar，失败fallback到aapt2）
    $icon = extractApkIcon($savedPath, __DIR__ . '/../../icon/', $apkId);
    if (!$icon) {
        $icon = extractApkIcon_aapt($savedPath, __DIR__ . '/../../icon/', $apkId);
    }
    if (!$icon) {
        $icon = 'android.png';
    }
    $sign = extractApkSignatureBase64($savedPath);
    
    $osspath = null;
    if(Auth::getSetting($pdo,"ossbackup","0") == 1){
        $oss_up = $oss->uploadFile($savedPath,'uploads/' . $newFileName);
        if($oss_up['code'] == 200){
            $osspath = $oss_up['oss_path'];
            @unlink($savedPath);//重传成功后需要删除本地文件
        }
    }
        
        
    // 更新记录
    $stmt = $pdo->prepare("UPDATE `$apkTable` SET 
        name = :name,
        icon = :icon,
        sign = :sign,
        version = :version,
        size = :size,
        path = :path,
        osspath = :osspath,
        upload_time = NOW()
        WHERE id = :id");

    $stmt->execute([
        ':name'    => $apkInfo['name'],
        ':icon'    => $icon,
        ':sign'    => $sign,
        ':version' => $apkInfo['version'],
        ':size'    => $file['size'],
        ':path'    => $newFileName,
        ':id'      => $apkId,
        ':osspath' => $osspath,
    ]);

    return [
        'message' => '替换成功',
        'md5' => $newMd5
    ];
}


//检查是否有启动窗口
function hasLaunchActivity(string $apkPath): bool
{
    if (!is_file($apkPath)) {
        //echo "APK文件不存在：{$apkPath}\n";
        return false;
    }

    $cmd = "aapt2 dump xmltree {$apkPath} --file AndroidManifest.xml";
    $cmd = 'aapt2 dump xmltree ' . escapeshellarg($apkPath) . ' --file AndroidManifest.xml';
    $output = shell_exec($cmd);
    if (!$output) {
        //echo $cmd;
        //echo "无法解析 APK：{$output}\n";
        return true;//无法解析的时候，返回true，代表有启动窗口，不影响后续的上传
    }

    $hasMain = false;
    $hasLauncher = false;

    $lines = explode("\n", $output);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'android:name') !== false && strpos($line, 'MAIN') !== false) {
            $hasMain = true;
        }
        if (strpos($line, 'android:name') !== false && strpos($line, 'LAUNCHER') !== false) {
            $hasLauncher = true;
        }
        if ($hasMain && $hasLauncher) {
            return true;
        }
    }

    return false;
}



function rrmdir_php($dir){
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $fileinfo) {
        /* @var SplFileInfo $fileinfo */
        if ($fileinfo->isDir()) {
            // 删除子目录
            rmdir($fileinfo->getRealPath());
        } else {
            // 删除文件
            @unlink($fileinfo->getRealPath());
        }
    }
    // 删除最外层目录
    @rmdir($dir);
}
//提取 X.509 证书
function extractApkSignatureBase64($apkPath) {
    if (!file_exists($apkPath)) {
        return false;
    }

    $tmpDir = __DIR__ . '/../../temp/apk_' . uniqid();
    mkdir($tmpDir, 0700, true);

    // 仅列出 META-INF 目录的 .RSA 文件名
    $listCmd = "unzip -Z1 " . escapeshellarg($apkPath) . " | grep '^META-INF/.*\\.RSA\$'";
    $rsaEntry = trim(shell_exec($listCmd));

    if ($rsaEntry === '') {
        // 清理临时目录并返回
        rrmdir_php($tmpDir);
        return false;
    }

    // 提取 .RSA 文件（只提取，不解压全部）
    $extractCmd = "unzip -j " . escapeshellarg($apkPath) . " " . escapeshellarg($rsaEntry) . " -d " . escapeshellarg($tmpDir) . " 2>&1";
    $extractOutput = shell_exec($extractCmd);
    $rsaFilePath = $tmpDir . '/' . basename($rsaEntry);

    if (!file_exists($rsaFilePath)) {
        rrmdir_php($tmpDir);
        return false;
    }

    // 使用 openssl 提取 PEM 格式证书
    $pemFile = $tmpDir . '/cert.pem';
    $opensslCmd = "openssl pkcs7 -inform DER -in " . escapeshellarg($rsaFilePath) . " -print_certs -out " . escapeshellarg($pemFile) . " 2>&1";
    $opensslOutput = shell_exec($opensslCmd);

    if (!file_exists($pemFile)) {
        rrmdir_php($tmpDir);
        return false;
    }

    // 提取 BASE64 证书内容
    $pemContent = file_get_contents($pemFile);
    if (!preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pemContent, $matches)) {
        rrmdir_php($tmpDir);
        return false;
    }

    // 保留证书体的原始换行（如果想去掉换行可改为 str_replace）
    $base64 = trim($matches[1]);

    // 使用 PHP 递归删除临时目录（替代 shell rm -rf）
    rrmdir_php($tmpDir);

    return $base64;
}

//图标提取，jar库方式
function extractApkIcon(string $apkPath, string $outputDir, string $outputName) {
    if (!is_file($apkPath) || !is_dir($outputDir)) {
        return false;
    }

    // ico.jar 路径
    $jarPath = __DIR__ . '/../../bin/ico.jar';

    if (!is_file($jarPath)) {
        return false;
    }

    // 输出图标完整路径
    $outputFile = rtrim($outputDir, '/') . '/' . $outputName . '.png';

    // 构造 shell 命令
    $cmd = 'java -jar ' . escapeshellarg($jarPath) . ' ' . escapeshellarg($apkPath) . ' ' . escapeshellarg($outputFile);

    // 执行命令
    $output = shell_exec($cmd);

    // 检查文件是否成功生成且小于等于256KB
    if (is_file($outputFile) && filesize($outputFile) <= 256 * 1024) {
        return $outputName . '.png';
    }

    return false;
}

//APK图标提取,aapt2的方式
function extractApkIcon_aapt(string $apkPath, string $outputDir, string $outputName) {
    if (!is_file($apkPath) || !is_dir($outputDir)) {
        return false;
    }

    $cmd = "aapt2 dump badging " . escapeshellarg($apkPath);
    $output = shell_exec($cmd);
    if (!$output) {
        return false;
    }

    if (!preg_match("/icon='([^']+)'/", $output, $matches)) {
        return false;
    }

    $iconPath = $matches[1];

    $zip = new ZipArchive();
    if ($zip->open($apkPath) !== true) {
        return false;
    }

    $iconData = $zip->getFromName($iconPath);
    $zip->close();

    if ($iconData === false) {
        return false;
    }

    $iconSize = strlen($iconData);

    // 写入临时文件
    $tmpFile = tempnam(sys_get_temp_dir(), 'icon_');
    file_put_contents($tmpFile, $iconData);
    
    // 获取 MIME 类型
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpFile);
    finfo_close($finfo);

    
    // MIME 映射为真实扩展名
    $mimeToExt = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp'
    ];
    
    if (!isset($mimeToExt[$mimeType])) {
        unlink($tmpFile);
        return false;
    }
    
    // 获取真实扩展名
    $ext = $mimeToExt[$mimeType];
    
    
    // 删除临时文件（后面如果需要再用也可以保留）
    unlink($tmpFile);
    

    $fileName = $outputName . '.' . $ext;
    $outputFile = rtrim($outputDir, '/') . '/' . $fileName;

    if ($iconSize <= 256 * 1024) {
        if (file_put_contents($outputFile, $iconData) === false) {
            return false;
        }
        return $fileName;
    }

    if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
        return false;
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'icon_');
    file_put_contents($tmpFile, $iconData);

    $image = null;
    if ($ext === 'png') {
        $image = @imagecreatefrompng($tmpFile);
    } elseif ($ext === 'jpg' || $ext === 'jpeg') {
        $image = @imagecreatefromjpeg($tmpFile);
    }
    unlink($tmpFile);

    if ($image === false) {
        return false;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $scales = [0.9, 0.8, 0.7, 0.6, 0.5, 0.4, 0.3, 0.2, 0.1];

    foreach ($scales as $scale) {
        $newWidth = intval($width * $scale);
        $newHeight = intval($height * $scale);
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        if ($ext === 'png') {
            imagepng($resized, null, 9);
        } else {
            imagejpeg($resized, null, 50);
        }
        $compressedData = ob_get_clean();
        imagedestroy($resized);

        if (strlen($compressedData) <= 256 * 1024) {
            imagedestroy($image);
            if (file_put_contents($outputFile, $compressedData) === false) {
                return false;
            }
            return $fileName;
        }
    }

    imagedestroy($image);
    return false;
}




//上传加固检测,特征检测
function isApkObfuscated1($apkPath, $pdo) {
    if (!file_exists($apkPath)) {
        throw new Exception("APK 文件不存在: " . $apkPath);
    }
    $startTime = microtime(true); // 开始计时
    // 预定义加固/混淆类型及其关键词和提示语
    $rules = [
        [
            'type' => '大纸片混淆',
            'keywords' => ['大纸片'],
            'message' => '检测到大纸片混淆'
        ],
        [
            'type' => '360加固',
            'keywords' => ['libjiagu.so', 'libprotectClass.so', 'libsecmain.so','libjiagu_a64.so','libjiagu_x64.so'],
            'message' => '检测到 360 加固'
        ],
        [
            'type' => 'Epic加固',
            'keywords' => ['Epic.vmp', 'Epic_dexs'],
            'message' => '检测到 Epic 加固'
        ],
        [
            'type' => '腾讯加固',
            'keywords' => ['libshell-super.so', 'libtencentloc.so'],
            'message' => '检测到 腾讯加固'
        ],
        [
            'type' => '梆梆加固',
            'keywords' => ['libsecexe.so', 'libsecpreload.so'],
            'message' => '检测到 梆梆加固'
        ],
        [
            'type' => '百度加固',
            'keywords' => ['baiduprotect'],
            'message' => '检测到 百度加固'
        ],
        [
            'type' => '通用混淆',
            'keywords' => ['混淆', 'obfuscate', 'libjiagu_x64.so', 'libjiagu_a64.so', 'jiagu', 'oOo0o','OoO0o'],
            'message' => '检测到混淆加固迹象'
        ],
        [
            'type' => '深思数盾',
            'keywords' => ['l********_a32.so','l********_a64.so','l********_x64.so','l********_x86.so'],
            'message' => '检测到深思数盾加固迹象'
        ],
        [
            'type' => '云镜',
            'keywords' => ['by_yunjing'],
            'message' => '检测到云镜加固'
        ]
    ];

    // 调用 unzip -l 获取文件列表
    $cmd = "nice -n 19 ionice -c2 -n7 unzip -l " . escapeshellarg($apkPath). " | grep -viE '^.*res/'";
    $output = shell_exec($cmd);

    if (!$output) {
        throw new Exception("无法解析 APK 文件，unzip 执行失败");
    }

    $apkMd5 = md5_file($apkPath);
    $lines = explode("\n", strtolower($output)); // 全部转小写，统一匹配

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
                if ($allMatched) {
                    $timeUsed = round((microtime(true) - $startTime) * 1000); // 毫秒
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
        'message' => '未检测到已知混淆或加固'. "（耗时 {$timeUsed}ms）"
    ];
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
            'type' => '大纸片混淆',
            'keywords' => ['大纸片'],
            'message' => '检测到大纸片混淆'
        ],
        [
            'type' => '网易易盾',
            'keywords' => ['libnesec.so','libnesec-x86.so'],
            'message' => '检测到网易易盾加固'
        ],
        [
            'type' => 'Epic加固',
            'keywords' => ['Epic.vmp', 'Epic_dexs'],
            'message' => '检测到 Epic 加固'
        ],
        [
            'type' => '爱加密',
            'keywords' => ['libijm_linker.so', 'ijiami.ajm', 'ijiami.dat', 'IJMDal.Data'],
            'message' => '检测到 爱加密 加固'
        ],
        [
            'type' => '360加固',
            'keywords' => ['libjiagu.so', 'libprotectClass.so', 'libsecmain.so','libjiagu_a64.so','libjiagu_x64.so'],
            'message' => '检测到 360 加固'
        ],
        [
            'type' => '梆梆加固',
            'keywords' => ['libDexHelper.so','libDexHelper-x86.so'],
            'message' => '检测到梆梆加固'
        ],
        [
            'type' => '腾讯加固',
            'keywords' => ['libshell-super.so'],
            'message' => '检测到 腾讯加固'
        ],
        [
            'type' => '梆梆加固',
            'keywords' => ['libsecexe.so', 'libsecpreload.so'],
            'message' => '检测到 梆梆加固'
        ],
        [
            'type' => '百度加固',
            'keywords' => ['baiduprotect'],
            'message' => '检测到 百度加固'
        ],
        [
            'type' => '云镜',
            'keywords' => ['by_yunjing','libyj-v3-pt.so', 'yun-jing', 'yj-apk-sign', 'libYJ-Signature.so', 'libyj_dcc_pro_aj_pro.so', 'libyj_dcc_pro_sign.so'],
            'message' => '检测到云镜加固'
        ],
        [
            'type' => 'ShadowSafety',
            'keywords' => ['libShadowSafetyProtect_a64.so','libShadowSafetyProtect_x64.so', 'ShadowSafety'],
            'message' => '检测到ShadowSafety加固'
        ],
        [
            'type' => '枯叶蝶',
            'keywords' => ['libkydtc.so','枯叶云Dex2c.txt'],
            'message' => '检测到枯叶云加固'
        ]
    ];
    if(Auth::getSetting($pdo, 'jiagu', "0")){
        $rules = Auth::getRules($pdo, 0);
    }

    // 使用 ZipArchive 获取文件列表，排除 res/ 路径
    $zip = new ZipArchive();
    if ($zip->open($apkPath) !== true) {
        throw new Exception("无法解析 APK 文件，ZipArchive 打开失败");
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
        /*return [
            'matched' => true,
            'type' => '乱码混淆',
            'message' => "检测到乱码类资源混淆（{$garbledCount} 个异常文件）检测强度{$ascii}（耗时 {$timeUsed}ms）"
        ];*/
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
        'message' => '未检测到已知混淆或加固'. "（耗时 {$timeUsed}ms）"
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

//框架应用特征检测
function detectApkFramework($apkPath) {
    if (!file_exists($apkPath)) {
        throw new Exception("APK 文件不存在: " . $apkPath);
    }

    $frameworks = [
        'flutter框架' => [
            'keywords' => ['libflutter.so', 'assets/flutter_assets', 'flutter_export.dylib']
        ],
        'iapp框架' => [
            'keywords' => ['assets/iapp', 'iAppClasses.dex', 'assets/project.iapp']
        ],
        'react-native框架' => [
            'keywords' => ['assets/index.android.bundle', 'libreactnativejni.so', 'reactnative']
        ],
        'uni-app框架' => [
            'keywords' => ['assets/app-plus', 'libuni.so', 'assets/uni_modules']
        ],
        'cordova框架' => [
            'keywords' => ['assets/www', 'cordova.js', 'cordova_plugins.js']
        ],
        'weex框架' => [
            'keywords' => ['libweexcore.so', 'assets/weex']
        ],
        'hbuilderx框架' => [
            'keywords' => ['libuniapp-v8.so', 'assets/data/dcloud_control.xml']
        ],
        'electron安卓壳' => [
            'keywords' => ['assets/electron.asar', 'assets/electron']
        ],
        'qt框架' => [
            'keywords' => ['libQt5Core.so', 'libQt5Gui.so', 'libQt5Widgets.so']
        ],
        'xamarin框架' => [
            'keywords' => ['libmonodroid.so', 'mono.android.runtime']
        ],
        'lua框架' => [
            'keywords' => ['assets/main.lua', 'libluajava.so', 'libandlua.so']
        ],
        'gamemaker引擎' => [
            'keywords' => ['libyoyo.so', 'assets/yyconfig.json']
        ]
    ];


    $zip = new ZipArchive();
    if ($zip->open($apkPath) !== true) {
        throw new Exception("无法打开 APK 文件");
    }

    $fileList = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = strtolower($zip->getNameIndex($i));
        $fileList[] = $name;
    }
    $zip->close();

    foreach ($frameworks as $frameworkName => $rule) {
        foreach ($rule['keywords'] as $keyword) {
            foreach ($fileList as $file) {
                if (strpos($file, strtolower($keyword)) !== false) {
                    return $frameworkName;
                }
            }
        }
    }

    return false;
}

//判断是否是分包应用
function isSplitApk($apkPath)
{
    if (!is_file($apkPath)) {
        throw new Exception("文件不存在: {$apkPath}");
    }

    // 调用 aapt 获取 AndroidManifest.xml 树结构
    $cmd = "aapt2 dump xmltree " . escapeshellarg($apkPath) . " --file AndroidManifest.xml 2>&1";
    $output = shell_exec($cmd);
    if (!$output) {
        return false; // 获取不到信息，默认非分包
    }

    // 1. 检查 requiredSplitTypes
    if (preg_match('/android:requiredSplitTypes.*?="([^"]*)"/', $output, $m)) {
        if (!empty($m[1])) {
            return true; // 有 requiredSplitTypes 且非空，一定是分包应用
        }
    }

    // 2. 检查 splitTypes 字段
    if (preg_match('/android:splitTypes.*?="([^"]*)"/', $output, $m)) {
        if (!empty($m[1])) {
            return true; // splitTypes 有内容，也属于分包特征
        }
    }

    // 3. 检查 split 标识的文件名（仅 Split APK 才会带有 split_xxx）
    if (preg_match('/split/i', basename($apkPath))) {
        return true;
    }

    // 4. 检查 package 字段是否缺失
    /*if (!preg_match('/package: name=/', shell_exec("aapt2 dump badging " . escapeshellarg($apkPath) . " 2>&1"))) {
        return true; // 没有完整 package 字段，也可能是拆分包
    }*/

    return false; // 通过所有检测，非分包
}


//aapt获取包名方式
function parseApkInfoWithAapt($apkPath)
{
    $appName = '未知应用';
    $package = '未知包名';
    $version = '未知版本';

    $output = '';
    $usedAapt = false;

    /* ======================
     * 1️⃣ 优先使用 aapt
     * ====================== */
    $cmdAapt = "aapt dump badging " . escapeshellarg($apkPath) . " 2>&1";
    $outputAapt = shell_exec($cmdAapt);

    // 判断 aapt 是否真正解析出了 package（而不是只“有输出”）
    if ($outputAapt &&
        stripos($outputAapt, 'error:') === false &&
        preg_match("/package: name='(.*?)'/", $outputAapt)
    ) {
        $output = $outputAapt;
        $usedAapt = true;
        // echo "使用 aapt 解析\n";
    }

    /* ======================
     * 2️⃣ aapt 不可用 → 使用 aapt2
     * ====================== */
    if (!$usedAapt) {
        $cmdAapt2 = "aapt2 dump badging " . escapeshellarg($apkPath) . " 2>&1";
        $outputAapt2 = shell_exec($cmdAapt2);

        if ($outputAapt2 &&
            stripos($outputAapt2, 'error:') === false
        ) {
            $output = $outputAapt2;
            // echo "使用 aapt2 解析\n";
        }
    }

    /* ======================
     * 3️⃣ 两者都失败，直接返回默认值
     * ====================== */
    if (!$output) {
        return [
            'name'    => $appName,
            'package' => $package,
            'version' => $version
        ];
    }

    /* ======================
     * 4️⃣ 解析包名 & 版本
     * ====================== */
    if (preg_match(
        "/package: name='(.*?)'.*?versionName='(.*?)'/",
        $output,
        $matches
    )) {
        $package = $matches[1] ?? $package;
        $version = $matches[2] ?? $version;
    }

    /* ======================
     * 5️⃣ 解析应用名称（优先中文）
     * ====================== */
    $langLabels = [
        "application-label-zh-CN:'(.*?)'",
        "application-label-zh:'(.*?)'",
        "application-label-zh-TW:'(.*?)'",
        "application-label:'(.*?)'"
    ];

    foreach ($langLabels as $pattern) {
        if (preg_match("/{$pattern}/", $output, $matches)) {
            $appName = $matches[1];
            $appName = str_replace(' ', '_', $appName); // 去掉空格
            break;
        }
    }

    return [
        'name'    => $appName,
        'package' => $package,
        'version' => $version
    ];
}

/*function parseApkInfoWithAapt($apkPath)
{
    $appName = '未知应用';
    $package = '未知包名';
    $version = '未知版本';

    // 先尝试使用 aapt2
    $cmd = "aapt2 dump badging " . escapeshellarg($apkPath) . " 2>&1";
    $output = shell_exec($cmd);

    // 如果 aapt2 失败（输出为空或包含 error），改用 aapt
    if (!$output || stripos($output, 'error:') !== false) {
        $cmd = "aapt dump badging " . escapeshellarg($apkPath) . " 2>&1";
        $output = shell_exec($cmd);
    }

    // 如果依然没有输出，返回默认信息
    if (!$output) {
        return [
            'name'    => $appName,
            'package' => $package,
            'version' => $version
        ];
    }

    // 匹配包名和版本号
    if (preg_match("/package: name='(.*?)'.*?versionName='(.*?)'/", $output, $matches)) {
        $package = $matches[1] ?? $package;
        $version = $matches[2] ?? $version;
    }

    // 匹配应用名称（优先中文）
    $langLabels = [
        "application-label-zh:'(.*?)'",        // 通用中文
        "application-label-zh-CN:'(.*?)'",     // 简体中文
        "application-label-zh-TW:'(.*?)'",     // 繁体中文
        "application-label:'(.*?)'"            // 默认语言
    ];
    
    foreach ($langLabels as $pattern) {
        if (preg_match("/{$pattern}/", $output, $matches)) {
            $appName = $matches[1];
            $appName = str_replace(' ', '_', $appName); // 去除空格
            break;
        }
    }

    return [
        'name'    => $appName,
        'package' => $package,
        'version' => $version
    ];
}*/
/*function parseApkInfoWithAapt($apkPath)
{
    $appName = '未知应用';
    $package = '未知包名';
    $version = '未知版本';

    $cmd = "aapt2 dump badging " . escapeshellarg($apkPath);
    $output = shell_exec($cmd);

    if (!$output) {
        return [
            'name'    => $appName,
            'package' => $package,
            'version' => $version
        ];
    }

    // 匹配包名和版本号
    if (preg_match("/package: name='(.*?)'.*?versionName='(.*?)'/", $output, $matches)) {
        $package = $matches[1] ?? $package;
        $version = $matches[2] ?? $version;
    }

    // 匹配应用名称
    $langLabels = [
        "application-label-zh:'(.*?)'",        // 通用中文
        "application-label-zh-CN:'(.*?)'",     // 简体中文
        "application-label-zh-TW:'(.*?)'",     // 繁体中文
        "application-label:'(.*?)'"            // 默认语言
    ];
    
    foreach ($langLabels as $pattern) {
        if (preg_match("/{$pattern}/", $output, $matches)) {
            $appName = $matches[1];
            $appName = str_replace(' ', '_', $appName); // 去除空格
            break;
        }
    }

    return [
        'name'    => $appName,
        'package' => $package,
        'version' => $version
    ];
}*/

//反编译获取包名方式
function parseApkInfo($apktool, $apkPath, $outputDir)
{
    shell_exec("rm -rf " . escapeshellarg($outputDir));

    // 解包命令（降低优先级）
    $cmd = "nice -n 19 ionice -c2 -n7 java -jar " . escapeshellarg($apktool) . " d " . escapeshellarg($apkPath) . " -o " . escapeshellarg($outputDir) . " -f";
    shell_exec($cmd);

    $appName = '未知应用';
    $package = '未知包名';
    $version = '未知版本';

    $manifestPath = $outputDir . '/AndroidManifest.xml';
    if (file_exists($manifestPath)) {
        $xml = @simplexml_load_file($manifestPath);
        if ($xml !== false) {
            $package = (string)($xml['package'] ?? $package);
            $apps = $xml->xpath('//application');
            if ($apps) {
                $ns = 'http://schemas.android.com/apk/res/android';
                $attrs = $apps[0]->attributes($ns);
                if (isset($attrs['label'])) {
                    $label = (string)$attrs['label'];
                    if (strpos($label, '@string/') === 0) {
                        $key = substr($label, 8);
                        $stringsXml = $outputDir . '/res/values/strings.xml';
                        if (file_exists($stringsXml)) {
                            $strXml = @simplexml_load_file($stringsXml);
                            if ($strXml) {
                                foreach ($strXml->string as $s) {
                                    if ((string)$s['name'] === $key) {
                                        $appName = (string)$s;
                                        break;
                                    }
                                }
                            }
                        }
                    } else {
                        $appName = $label;
                    }
                }
            }
        }
    }

    $yml = $outputDir . '/apktool.yml';
    if (file_exists($yml)) {
        $lines = file($yml);
        foreach ($lines as $line) {
            if (strpos($line, 'versionName:') !== false) {
                $version = trim(explode(':', $line, 2)[1] ?? $version);
                break;
            }
        }
    }

    shell_exec("rm -rf " . escapeshellarg($outputDir));

    return [
        'name'    => $appName,
        'package' => $package,
        'version' => $version
    ];
}



//编辑应用信息
function updateAppInfo(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $apkTable = 'cainiao_apk';

    if (empty($input['id']) || !is_numeric($input['id'])) {
        throw new Exception('参数错误：缺少或非法的应用 ID');
    }

    $appId = (int)$input['id'];


    // 验证该应用是否属于当前用户
    if ($user['role'] !== 'admin') {
        $stmt = $pdo->prepare("SELECT user_id FROM `$apkTable` WHERE id = :id AND user_id = :user_id");
        $stmt->execute([':id' => $appId, ':user_id' => $userId]);
    } else {
        $stmt = $pdo->prepare("SELECT user_id FROM `$apkTable` WHERE id = :id");
        $stmt->execute([':id' => $appId]);
    }
    
    $ownerId = $stmt->fetchColumn();  // 获取 user_id 字段的值
    
    if (!$ownerId) {
        throw new Exception('未找到该应用或无权限修改');
    }
    //重新获取$userId 主要是将管理员的id换成应用上传者的id，让管理员可以操作任意应用
    $userId = $ownerId;
    
    $fields = [];
    $params = [':id' => $appId, ':user_id' => $userId];

    // 可选字段
    if (isset($input['name'])) {
        $fields[] = "name = :name";
        $params[':name'] = trim($input['name']);
    }
    //修改app卡密解绑授权码
    if (isset($input['app_key'])) {
        $fields[] = "app_key = :app_key";
        $params[':app_key'] = trim($input['app_key']);
    }
    
    //APP包名和版本不可修改
    /*if (isset($input['version'])) {
        $fields[] = "version = :version";
        $params[':version'] = trim($input['version']);
    }

    if (isset($input['package'])) {
        $fields[] = "package = :package";
        $params[':package'] = trim($input['package']);
    }*/

    // 处理配置方式（独享或复用）
    if (isset($input['config_mode'])) {
        $configMode = (int)$input['config_mode'];
        if (!in_array($configMode, [0, 1])) {
            throw new Exception('非法的配置使用方式');
        }

        $fields[] = "config_mode = :config_mode";
        $params[':config_mode'] = $configMode;

        if ($configMode === 1) {
            if (empty($input['reuse_apk_id']) || !is_numeric($input['reuse_apk_id'])) {
                throw new Exception('请指定要复用的应用');
            }

            $reuseApkId = (int)$input['reuse_apk_id'];

            if ($reuseApkId === $appId) {
                throw new Exception('不能复用自身的配置');
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$apkTable` WHERE id = :id AND user_id = :user_id");
            $stmt->execute([':id' => $reuseApkId, ':user_id' => $userId]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new Exception('要复用的应用不存在或无权限');
            }

            $fields[] = "reuse_apk_id = :reuse_apk_id";
            $params[':reuse_apk_id'] = $reuseApkId;
        } else {
            $fields[] = "reuse_apk_id = NULL";
        }
    }

    // 处理域名配置
    if (isset($input['domain_mode'])) {
        $domainMode = (int)$input['domain_mode'];
        if (!in_array($domainMode, [0, 1])) {
            throw new Exception('非法的域名配置方式');
        }

        $fields[] = "domain_mode = :domain_mode";
        $params[':domain_mode'] = $domainMode;

        if ($domainMode === 1) {
            if (empty($input['custom_domains'])) {
                throw new Exception('自定义域名不能为空');
            }

            $domains = explode("\n", $input['custom_domains']);
            $validDomains = [];

            foreach ($domains as $domain) {
                $domain = trim($domain);
                if ($domain === '') continue;//去除空行
                if (!preg_match('/^https?:\/\/[^\s\/$.?#].[^\s]*$/i', $domain)) {
                    throw new Exception("自定义域名格式错误：{$domain}，请确保以 http:// 或 https:// 开头");
                }
                $validDomains[] = $domain;
            }

            if (empty($validDomains)) {
                throw new Exception('请输入至少一个有效的自定义域名');
            }

            $fields[] = "custom_domains = :custom_domains";
            $params[':custom_domains'] = implode("\n", $validDomains);
        } else {
            $fields[] = "custom_domains = NULL";
        }
    }
    
    // 处理复用选项字段
    if (isset($input['reuse_options'])) {
        if (!is_array($input['reuse_options'])) {
            throw new Exception('复用选项格式错误');
        }
    
        // 编码成 JSON 字符串保存
        $fields[] = "reuse_options = :reuse_options";
        $params[':reuse_options'] = json_encode($input['reuse_options'], JSON_UNESCAPED_UNICODE);
    }


    if (empty($fields)) {
        throw new Exception('没有任何可修改的字段');
    }

    $sql = "UPDATE `$apkTable` SET " . implode(', ', $fields) . " WHERE id = :id AND user_id = :user_id";
    $update = $pdo->prepare($sql);
    $update->execute($params);

    // 清除该应用的Redis缓存，使修改立即生效
    try {
        $redisDb0 = getRedisConnection(0); // 配置响应缓存
        $redisDb2 = getRedisConnection(2); // APK信息缓存
        $redisDb0->del((string)$appId);
        $redisDb2->del((string)$appId);
    } catch (\Throwable $e) {
        // Redis清除失败不影响保存结果
    }

    // 推送配置到 S3/R2/B2 存储桶（最佳努力，失败不影响保存）
    try {
        require_once __DIR__ . '/../utils/BucketPush.php';
        pushConfigWithDependents($pdo, $appId);
    } catch (\Throwable $e) {
        error_log("[BucketPush] 推送失败 appId={$appId}: " . $e->getMessage());
    }

    return ['message' => '修改成功'];
}





function getMyTemplates(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $templateTable = 'cainiao_template';
    $taskTable = 'cainiao_inject_task';

    // 管理员查看所有模板，普通用户只能看启用的模板
    if ($user['role'] == 'admin') {
        $sql = "SELECT t.id, t.name, t.description, t.version, t.enabled, t.extracted, 
                       t.upload_time, t.extract_time, t.file_size, t.price, t.className,
                       COUNT(it.id) AS use_count
                FROM `$templateTable` t
                LEFT JOIN `$taskTable` it ON it.template_id = t.id
                GROUP BY t.id
                ORDER BY t.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    } else {
        $sql = "SELECT t.id, t.name, t.description, t.version, t.enabled, t.extracted, 
                       t.upload_time, t.extract_time, t.file_size, t.price, t.className,
                       COUNT(it.id) AS use_count
                FROM `$templateTable` t
                LEFT JOIN `$taskTable` it ON it.template_id = t.id
                WHERE t.enabled = 1
                GROUP BY t.id
                ORDER BY t.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }

    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $list;
}



function uploadTemplate(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $templateTable = 'cainiao_template';
    if($user['role']!=="admin"){
        throw new Exception('无权调用此方法');
    }
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('壳文件上传失败');
    }
    

    // 接收额外字段
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $version = isset($_POST['version']) ? trim($_POST['version']) : '1.0.0';
    $price = isset($_POST['price']) && is_numeric($_POST['price']) ? (int)$_POST['price'] : 0;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $className = isset($_POST['className']) ? trim($_POST['className']) : Auth::getSetting($pdo, 'shellName', "");

    if(empty($className)){
        throw new Exception('入口类名不得为空');
    }
    
    if ($name === '') {
        throw new Exception('模板名称不能为空');
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($ext !== 'apk') {
        throw new Exception('仅支持 APK 格式的壳文件');
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('壳文件大小不能超过 10MB');
    }

    $tmpPath = $file['tmp_name'];
    $md5 = md5_file($tmpPath);
    $fileName = "{$userId}_{$md5}.apk";
    $targetDir = __DIR__ . '/../../templates/';
    $savedPath = $targetDir . $fileName;

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // 检查是否已上传过
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$templateTable` WHERE user_id = :user_id AND path = :path");
    $stmt->execute([':user_id' => $userId, ':path' => $fileName]);

    if ((int)$stmt->fetchColumn() > 0) {
        return ['message' => '该壳文件已存在'];
    }

    if (!move_uploaded_file($tmpPath, $savedPath)) {
        throw new Exception('保存壳文件失败');
    }

    // 插入记录
    $insert = $pdo->prepare("INSERT INTO `$templateTable`
        (name, description, version, enabled, path, extract_path, extracted, user_id, upload_time, file_size, price, className)
        VALUES (:name, :description, :version, 0, :path, '', 0, :user_id, NOW(), :size, :price, :className)");

    $insert->execute([
        ':name'        => $name,
        ':description' => $description,
        ':version'     => $version,
        ':path'        => $fileName,
        ':user_id'     => $userId,
        ':size'        => $file['size'],
        ':price'       => $price,
        ':className'   => $className
    ]);

    return ['message' => '上传成功', 'file' => $fileName];
}

//覆盖上传壳模板
function overwriteTemplate(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    if ($user['role'] !== "admin") {
        throw new Exception('无权调用此方法');
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('壳文件上传失败');
    }

    $templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
    if ($templateId <= 0) {
        throw new Exception('模板ID不合法');
    }

    $templateTable = 'cainiao_template';

    // 查询模板信息
    $stmt = $pdo->prepare("SELECT * FROM `$templateTable` WHERE id = :id");
    $stmt->execute([':id' => $templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        throw new Exception('模板不存在');
    }

    if ((int)$template['user_id'] !== $userId) {
        throw new Exception('无权覆盖该模板');
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($ext !== 'apk') {
        throw new Exception('仅支持 APK 格式的壳文件');
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('壳文件大小不能超过 10MB');
    }

    $tmpPath = $file['tmp_name'];
    $md5 = md5_file($tmpPath);
    $newFileName = "{$userId}_{$md5}.apk";
    $targetDir = __DIR__ . '/../../templates/';
    $newPath = $targetDir . $newFileName;

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // 新增检测：如果新文件名已存在，则不允许覆盖
    if (file_exists($newPath)) {
        throw new Exception('该文件名已存在，无法覆盖上传');
    }

    // 移动新文件
    if (!move_uploaded_file($tmpPath, $newPath)) {
        throw new Exception('保存壳文件失败');
    }

    // 删除旧文件
    $oldPath = $targetDir . $template['path'];
    if (file_exists($oldPath)) {
        @unlink($oldPath);
    }

    // 更新数据库 path 和 file_size
    $update = $pdo->prepare("UPDATE `$templateTable` SET path = :path, file_size = :size, upload_time = NOW() WHERE id = :id");
    $update->execute([
        ':path' => $newFileName,
        ':size' => $file['size'],
        ':id'   => $templateId
    ]);

    return ['message' => '覆盖上传成功', 'file' => $newFileName];
}




function deleteTemplate(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $templateTable = 'cainiao_template';
    if($user['role']!=="admin"){
        throw new Exception('无权调用此方法');
    }
    if (empty($input['id']) || !is_numeric($input['id'])) {
        throw new Exception('参数错误：缺少模板 ID');
    }

    $id = (int)$input['id'];

    // 查询模板
    $stmt = $pdo->prepare("SELECT path FROM `$templateTable` WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $id, ':user_id' => $userId]);
    $tpl = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tpl) {
        throw new Exception('未找到模板或无权限删除');
    }

    // 删除物理文件
    $filePath = __DIR__ . '/../../templates/' . $tpl['path'];
    if (is_file($filePath)) {
        @unlink($filePath);
    }

    // 删除数据库记录
    $del = $pdo->prepare("DELETE FROM `$templateTable` WHERE id = :id AND user_id = :user_id");
    $del->execute([':id' => $id, ':user_id' => $userId]);

    return ['message' => '模板删除成功'];
}

function updateTemplate(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $templateTable = 'cainiao_template';
    if($user['role']!=="admin"){
        throw new Exception('无权调用此方法');
    }
    if (empty($input['id']) || !is_numeric($input['id'])) {
        throw new Exception('参数错误：缺少模板 ID');
    }
    if (empty($input['className'])) {
        $input['className'] = 'com.example.shell.HookApplication';//默认入口类名
    }
    

    $id = (int)$input['id'];

    $stmt = $pdo->prepare("SELECT id FROM `$templateTable` WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $id, ':user_id' => $userId]);

    if (!$stmt->fetch()) {
        throw new Exception('模板不存在或无权限修改');
    }

    $fields = [];
    $params = [':id' => $id, ':user_id' => $userId];

    if (isset($input['name'])) {
        $fields[] = "name = :name";
        $params[':name'] = trim($input['name']);
    }

    if (isset($input['description'])) {
        $fields[] = "description = :description";
        $params[':description'] = trim($input['description']);
    }
    
    if (isset($input['className'])) {
        $fields[] = "className = :className";
        $params[':className'] = trim($input['className']);
    }

    if (isset($input['version'])) {
        $fields[] = "version = :version";
        $params[':version'] = trim($input['version']);
    }

    if (isset($input['price']) && is_numeric($input['price'])) {
        $fields[] = "price = :price";
        $params[':price'] = (int)$input['price'];
    }

    if (empty($fields)) {
        throw new Exception('没有需要修改的字段');
    }

    $sql = "UPDATE `$templateTable` SET " . implode(', ', $fields) . " WHERE id = :id AND user_id = :user_id";
    $update = $pdo->prepare($sql);
    $update->execute($params);

    return ['message' => '模板信息更新成功'];
}


function updateTemplateEnable(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权调用此方法');
    }

    if (empty($input['id']) || !is_numeric($input['id'])) {
        throw new Exception('缺少模板 ID');
    }

    if (!isset($input['enabled'])) {
        throw new Exception('缺少 enabled 参数');
    }

    // 转换 enabled 为合法值 0 或 1
    $enableRaw = $input['enabled'];
    if ($enableRaw === true || $enableRaw === 'true' || $enableRaw === '1' || $enableRaw === 1) {
        $enabled = 1;
    } elseif ($enableRaw === false || $enableRaw === 'false' || $enableRaw === '0' || $enableRaw === 0) {
        $enabled = 0;
    } else {
        throw new Exception('enable 参数非法');
    }

    $id = (int)$input['id'];
    $templateTable = 'cainiao_template';

    // 确保模板存在
    $stmt = $pdo->prepare("SELECT id FROM `$templateTable` WHERE id = :id");
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetch()) {
        throw new Exception('模板不存在');
    }

    // 更新 enable 字段
    $stmt = $pdo->prepare("UPDATE `$templateTable` SET enabled = :enabled WHERE id = :id");
    $stmt->execute([
        ':enabled' => $enabled,
        ':id' => $id
    ]);

    return ['message' => '模板状态已更新'];
}


function uploadSign(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $signTable = 'cainiao_sign';

    // 表单字段校验
    $name = trim($_POST['name'] ?? '');
    $alias = trim($_POST['alias'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $certPassword = trim($_POST['cert_password'] ?? '');

    if (!$name || !$alias || !$password || !$certPassword) {
        throw new Exception('所有字段均为必填');
    }
    
    // -----------------------------
    // 🚨 必须过滤掉危险字符，防止以后拼接到 shell 命令时触发注入
    // -----------------------------

    // alias 只能包含安全字符
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $alias)) {
        throw new Exception('证书别名只能包含字母、数字、下划线或中划线');
    }

    // 证书密码限制（与 generateSign 保持一致）
    $passwordRule = '/^[A-Za-z0-9!@#$%^*\-_=+.]{6,32}$/';

    if (!preg_match($passwordRule, $password)) {
        throw new Exception('证书密码必须为 6-32 位，只能包含字母、数字和安全符号 !@#$%^*-_+=.');
    }

    if (!preg_match($passwordRule, $certPassword)) {
        throw new Exception('密钥密码必须为 6-32 位，只能包含字母、数字和安全符号 !@#$%^*-_+=.');
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('文件上传失败');
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['keystore', 'jks'])) {
        throw new Exception('仅支持 .keystore 或 .jks 格式文件');
    }

    if ($file['size'] > 10 * 1024) {
        throw new Exception('证书文件大小不能超过 10KB');
    }

    $tmpPath = $file['tmp_name'];
    $md5 = md5_file($tmpPath);
    $fileName = "{$userId}_{$md5}.keystore";
    $targetDir = __DIR__ . '/../../signfile/';
    $savedPath = $targetDir . $fileName;

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // 是否上传过相同文件
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$signTable` WHERE user_id = :user_id AND path = :path");
    $stmt->execute([':user_id' => $userId, ':path' => $fileName]);

    if ((int)$stmt->fetchColumn() > 0) {
        return ['message' => '该证书已存在'];
    }

    if (!move_uploaded_file($tmpPath, $savedPath)) {
        throw new Exception('证书保存失败');
    }

    // 插入记录
    $insert = $pdo->prepare("INSERT INTO `$signTable`
        (name, user_id, alias, password, cert_password, path, upload_time)
        VALUES (:name, :user_id, :alias, :password, :cert_password, :path, NOW())");

    $insert->execute([
        ':name'          => $name,
        ':user_id'       => $userId,
        ':alias'         => $alias,
        ':password'      => $password,
        ':cert_password' => $certPassword,
        ':path'          => $fileName
    ]);

    return ['message' => '上传成功', 'file' => $fileName];
}


//创建证书
function generateSign(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $signTable = 'cainiao_sign';

    // 必填字段
    $requiredFields = ['name', 'alias', 'password', 'cert_password', 'dname_cn', 'dname_ou', 'dname_o', 'dname_l', 'dname_st', 'dname_c'];
    foreach ($requiredFields as $field) {
        $value = $_POST[$field] ?? $input[$field] ?? null;
        if (empty($value)) {
            throw new Exception("字段 {$field} 为必填");
        }
    }

    $name         = trim($_POST['name']         ?? $input['name']);
    $alias        = trim($_POST['alias']        ?? $input['alias']);
    $password     = trim($_POST['password']     ?? $input['password']);
    $certPassword = trim($_POST['cert_password']?? $input['cert_password']);
    $validity     = (int)($_POST['validity']    ?? $input['validity'] ?? 10000);
    if ($validity < 1 || $validity > 36500) {//20260301修复非法证书时间问题
        throw new Exception('有效期范围非法');
    }
    /*if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $alias)) {
        throw new Exception('证书别名不能包含中文');
    }*/
    if (!preg_match('/^[A-Za-z0-9_-]{6,20}$/', $alias)) {
        throw new Exception('证书别名只能包含字母、数字、下划线或中划线，长度 6-20 位');
    }

    if (!preg_match('/^[A-Za-z0-9!@#$%^*\-_=+.]{6,32}$/', $password)) {
        throw new Exception('证书密码必须为 6-32 位，只能包含字母、数字和安全符号 !@#$%^*-_+=.');
    }
    
    if (!preg_match('/^[A-Za-z0-9!@#$%^*\-_=+.]{6,32}$/', $certPassword)) {
        throw new Exception('密钥密码必须为 6-32 位，只能包含字母、数字和安全符号 !@#$%^*-_+=.');
    }
    $dname = [
        'CN' => trim($_POST['dname_cn'] ?? $input['dname_cn']),
        'OU' => trim($_POST['dname_ou'] ?? $input['dname_ou']),
        'O'  => trim($_POST['dname_o']  ?? $input['dname_o']),
        'L'  => trim($_POST['dname_l']  ?? $input['dname_l']),
        'ST' => trim($_POST['dname_st'] ?? $input['dname_st']),
        'C'  => trim($_POST['dname_c']  ?? $input['dname_c'])
    ];
    
    // 合法字符正则（允许中文、字母、数字、常见安全符号）
    $dnPattern = '/^[\x{4e00}-\x{9fa5}a-zA-Z0-9\s\.\-_&()]{1,64}$/u';
    
    // 校验各字段
    foreach ($dname as $key => $value) {
    
        // 必填检查
        if ($value === '') {
            throw new Exception("{$key} 不能为空");
        }
    
        // 长度限制
        if (mb_strlen($value) > 64) {
            throw new Exception("{$key} 长度不能超过 64 字符");
        }
    
        // 国家字段单独校验
        if ($key === 'C') {
            if (!preg_match('/^[A-Z]{2}$/', $value)) {
                throw new Exception("国家代码必须为2位大写字母，例如 CN");
            }
            continue;
        }
    
        // 通用字符校验
        if (!preg_match($dnPattern, $value)) {
            throw new Exception("{$key} 包含非法字符");
        }
    }

    // 检查是否超过30个证书
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$signTable` WHERE user_id = :uid");
    $stmt->execute([':uid' => $userId]);
    if(!$user['isVip']){
        $max = 3;
    }else{
        $max = 10;
    }
    if ((int)$stmt->fetchColumn() >= $max) {
        throw new Exception('证书数量已达上限（' . $max . '个）');
    }

    // 证书文件路径
    $fileName  = "{$userId}_" . md5(uniqid('', true)) . ".jks";
    $targetDir = __DIR__ . '/../../signfile/';
    $filePath  = $targetDir . $fileName;

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    // 拼接 DName 字符串（避免引号问题）
    $dnameStr = sprintf(
        'CN=%s,OU=%s,O=%s,L=%s,ST=%s,C=%s',
        addslashes($dname['CN']),
        addslashes($dname['OU']),
        addslashes($dname['O']),
        addslashes($dname['L']),
        addslashes($dname['ST']),
        addslashes($dname['C'])
    );

    // 生成命令
    $cmd = sprintf(
        'keytool -genkeypair -storetype JKS -alias %s -keyalg RSA -keysize 2048 -validity %d -keystore %s -storepass %s -keypass %s -dname %s',
        escapeshellarg($alias),
        $validity,
        escapeshellarg($filePath),
        escapeshellarg($password),
        escapeshellarg($certPassword),
        $dnameStr
    );

    $output = shell_exec($cmd . ' 2>&1');
    if (!file_exists($filePath)) {
        throw new Exception("证书生成失败: " . $output);
    }

    // 写入数据库
    $stmt = $pdo->prepare("INSERT INTO `$signTable`
        (name, user_id, alias, password, cert_password, path, upload_time)
        VALUES (:name, :user_id, :alias, :password, :cert_password, :path, NOW())");

    $stmt->execute([
        ':name'          => $name,
        ':user_id'       => $userId,
        ':alias'         => $alias,
        ':password'      => $password,
        ':cert_password' => $certPassword,
        ':path'          => $fileName
    ]);

    return ['message' => '证书创建成功', 'file' => $fileName];
}




function getMySigns(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $signTable = 'cainiao_sign';

    $sql = "SELECT id, name, alias, password, cert_password, upload_time FROM `$signTable` WHERE user_id = :user_id ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


//编辑证书
function updateSign(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $signTable = 'cainiao_sign';

    if (empty($input['id']) || !is_numeric($input['id'])) {
        throw new Exception('缺少 ID');
    }

    $id = (int)$input['id'];

    // 只允许修改自己的证书
    $stmt = $pdo->prepare("SELECT id FROM `$signTable` WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $id, ':user_id' => $userId]);
    if (!$stmt->fetch()) {
        throw new Exception('证书不存在或无权限修改');
    }

    // 安全正则（与创建证书、上传证书保持一致）
    $aliasRule    = '/^[A-Za-z0-9_-]+$/';
    $passwordRule = '/^[A-Za-z0-9!@#$%^*\-_=+.]{6,32}$/';

    $fields = [];
    $params = [
        ':id'      => $id,
        ':user_id' => $userId
    ];

    // 名称允许自由输入（不会进入 shell）
    if (isset($input['name'])) {
        $fields[] = "name = :name";
        $params[':name'] = trim($input['name']);
    }

    //不允许修改证书的关键信息
    // 别名 alias（必须过滤）
/*    if (isset($input['alias'])) {
        $alias = trim($input['alias']);
        if (!preg_match($aliasRule, $alias)) {
            throw new Exception('证书别名只能包含字母、数字、下划线或中划线');
        }
        $fields[] = "alias = :alias";
        $params[':alias'] = $alias;
    }

    // keystore 密码
    if (isset($input['password'])) {
        $pwd = trim($input['password']);
        if (!preg_match($passwordRule, $pwd)) {
            throw new Exception('证书密码必须为 6-32 位，只能包含字母、数字和安全符号 !@#$%^*-_+=.');
        }
        $fields[] = "password = :password";
        $params[':password'] = $pwd;
    }

    // 私钥密码
    if (isset($input['cert_password'])) {
        $cpwd = trim($input['cert_password']);
        if (!preg_match($passwordRule, $cpwd)) {
            throw new Exception('密钥密码必须为 6-32 位，只能包含字母、数字和安全符号 !@#$%^*-_+=.');
        }
        $fields[] = "cert_password = :cert_password";
        $params[':cert_password'] = $cpwd;
        
    }*/

    if (empty($fields)) {
        throw new Exception('无任何变更');
    }
    
    // 执行更新
    $sql = "UPDATE `$signTable` SET " . implode(', ', $fields)
         . " WHERE id = :id AND user_id = :user_id";

    $update = $pdo->prepare($sql);
    $update->execute($params);

    return ['message' => '修改成功'];
}



function deleteSign(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $signTable = 'cainiao_sign';

    if (empty($input['id']) || !is_numeric($input['id'])) {
        throw new Exception('缺少 ID');
    }

    $id = (int)$input['id'];

    $stmt = $pdo->prepare("SELECT path FROM `$signTable` WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $id, ':user_id' => $userId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('证书不存在或无权限删除');
    }

    $filePath = __DIR__ . '/../../signfile/' . $row['path'];
    if (is_file($filePath)) {
        @unlink($filePath);
    }

    $del = $pdo->prepare("DELETE FROM `$signTable` WHERE id = :id AND user_id = :user_id");
    $del->execute([':id' => $id, ':user_id' => $userId]);

    return ['message' => '删除成功'];
}


function createInjectTask(PDO $pdo, array $input)
{

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $apkId = isset($input['apk_id']) ? (int)$input['apk_id'] : 0;
    $templateId = isset($input['template_id']) ? (int)$input['template_id'] : 0;
    $signId = isset($input['sign_id']) ? $input['sign_id'] : 0;
    $isRandomSign = ($signId === 'random');
    if ($isRandomSign) {
        $signId = 0; // 数据库存 0，worker 检测到 sign_path 为空时自动生成
    } else {
        $signId = (int)$signId;
    }
    $remark = trim($input['remark'] ?? '');
    $inject_to_top = isset($input['inject_to_top']) ? (int)(bool)$input['inject_to_top'] : 0;
    $debug = isset($input['debug']) ? (int)(bool)$input['debug'] : 0;
    $allowHttp = isset($input['allow_http']) ? (int)(bool)$input['allow_http'] : 0;
    $network = isset($input['network']) ? (int)(bool)$input['network'] : 1;
    $jiagu = isset($input['jiagu']) ? (int)(bool)$input['jiagu'] : 0;
    $fake = isset($input['fake']) ? (int)(bool)$input['fake'] : 0;
    $vpncheck = isset($input['vpncheck']) ? (int)(bool)$input['vpncheck'] : 0;
    $launcher = isset($input['launcher']) ? (int)(bool)$input['launcher'] : 0;
    $kill_Inject = isset($input['kill_Inject']) ? (int)(bool)$input['kill_Inject'] : 0;
    $permissions = isset($input['permissions']) && is_array($input['permissions']) ? $input['permissions'] : [];
    $permissionsJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);
    $mode = isset($input['mode']) ? (int)$input['mode'] : 0;//注入模式
    $killsign = isset($input['killsign']) ? (int)(bool)$input['killsign'] : 0;//去签
    $killpath = isset($input['killpath']) ? (int)(bool)$input['killpath'] : 0;//加强去签
    $Request = isset($input['Request']) ? (int)(bool)$input['Request'] : 0;//全量并发
    $devices = isset($input['devices']) ? (int)(bool)$input['devices'] : 0;//设备码计算方式
    $tv = isset($input['tv']) ? (int)(bool)$input['tv'] : 0;//tv端按钮焦点适配
    $confuse = isset($input['confuse']) ? (int)(bool)$input['confuse'] : 0;//合并包
    $process = isset($input['process']) ? (int)$input['process'] : 0;//进程隔离，默认0，不隔离
    $dexmerge = isset($input['dexmerge']) ? (int)$input['dexmerge'] : 0;//dex重新划分，默认0，不划分
    // 注入桶选择（JSON 数组，如 [1,3,5]）
    $bucketIds = isset($input['bucket_ids']) && is_array($input['bucket_ids']) ? $input['bucket_ids'] : null;
    $bucketIdsJson = $bucketIds !== null ? json_encode(array_map('intval', $bucketIds)) : null;
    
    $vipjiagu  = Auth::getSetting($pdo,"vipjiagu","0");
    if($vipjiagu && $jiagu){//如果开启了会员加固且提交的加固为1
        if(!$user['isVip']){
            throw new Exception('APK加固功能仅会员可用');
        }
    }
    $task_in  = Auth::getSetting($pdo,"task_in","0");
    if(!$task_in && $user['role']!=='admin'){
        throw new Exception('注入服务维护中,暂停任务创建');
    }
    
    if($jiagu && $killpath){
        throw new Exception('加强去签和加固不能同时勾选');
    }
    
    if (!$apkId || !$templateId || (!$signId && !$isRandomSign)) {
        throw new Exception('参数不完整');
    }
    if (!in_array($mode, [0, 1, 2, 3])) {
        throw new Exception('注入模式错误');
    }
    // 验证所有资源是否属于当前用户
    $check = function ($table, $id) use ($pdo, $userId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        return $stmt->fetchColumn() > 0;
    };
    
    //非管理员，检测壳模板
    if($user['role']!=='admin'){
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_template WHERE id = :id AND enabled = 1");
        $stmt->execute([':id' => $templateId]);
        if (!$stmt->fetchColumn()) {
            throw new Exception('壳模板不存在');
        }
    }

    if (
        !$check('cainiao_apk', $apkId) ||
        //!$check('cainiao_template', $templateId) ||
        !$check('cainiao_sign', $signId)
    ) {
        if($user['role']!=='admin'){
            throw new Exception('检测到未授权资源');
        }
    }

    // 检查 path 是否为空
    $stmt = $pdo->prepare("SELECT path FROM cainiao_apk WHERE id = :id");
    $stmt->execute([':id' => $apkId]);
    $apkPath = $stmt->fetchColumn();
    if (empty($apkPath)) {
        throw new Exception('文件不存在,请点击重传安装包');
    }
    
    // 限制：同一用户、同一 APK 不允许重复提交任务
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_inject_task WHERE user_id = :uid AND apk_id = :apk");
    $stmt->execute([':uid' => $userId, ':apk' => $apkId]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('该应用已存在注入任务，不能重复提交');
    }

    
    // 限制：每个用户每天最多 10 条任务
    $inject = Auth::getSetting($pdo,"inject","10");
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_inject_task WHERE user_id = :uid AND created_at >= CURDATE()");
    $stmt->execute([':uid' => $userId]);
    if ($stmt->fetchColumn() >= $inject) {
        throw new Exception("今日创建任务数已达上限（{$inject}条）,请先删除一些已经完成的注入任务");
    }

    // 插入任务记录
    $insert = $pdo->prepare("INSERT INTO `cainiao_inject_task`
        (remark, user_id, template_id, apk_id, sign_id, created_at, status_text, status_info, inject_to_top, allowHttp, permissions, bucket_ids, mode, debug, killsign, killpath, Request, kill_Inject, network, confuse, jiagu, fake, vpncheck, isMainProcess, dexmerge, launcher, devices, tv)
        VALUES (:remark, :user_id, :template_id, :apk_id, :sign_id, NOW(), '等待处理', '请等待任务队列', :inject_to_top, :allowHttp, :permissions, :bucket_ids, :mode, :debug, :killsign, :killpath, :Request, :kill_Inject, :network, :confuse, :jiagu ,:fake, :vpncheck, :isMainProcess, :dexmerge, :launcher, :devices, :tv)");

    $insert->execute([
        ':remark'       => $remark,
        ':user_id'      => $userId,
        ':template_id'  => $templateId,
        ':apk_id'       => $apkId,
        ':sign_id'      => $signId,
        ':inject_to_top'=> $inject_to_top,
        ':allowHttp'    => $allowHttp,
        ':permissions'  => $permissionsJson,
        ':bucket_ids'   => $bucketIdsJson,
        ':mode'  => $mode,
        ':debug'  => $debug,
        ':killsign'  => $killsign,
        ':killpath'  => $killpath,
        ':Request'   => $Request,
        ':kill_Inject' => $kill_Inject,
        ':network' => $network,
        ':confuse' => $confuse,
        ':jiagu' => $jiagu,
        ':fake' => $fake,
        ':vpncheck' => $vpncheck,
        ':isMainProcess' => $process,
        ':dexmerge' => $dexmerge,
        ':launcher' => $launcher,
        ':devices' => $devices,
        ':tv' => $tv,
    ]);

    return ['message' => '注入任务创建成功,若运行闪退,请尝试更换注入模式或先去除签名校验'];
}











//获取任务二合一
function getUnifiedTaskList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $page  = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $status = trim($input['status_text'] ?? '');
    $id     = isset($input['id']) ? intval($input['id']) : null;

    $where = "1=1";
    $params = [];

    if ($user['role'] !== 'admin') {
        $where .= " AND user_id = :uid";
        $params[':uid'] = $userId;
    }

    if ($status !== '') {
        $where .= " AND status_text = :status";
        $params[':status'] = $status;
    }

    if ($id) {
        $where .= " AND id = :id";
        $params[':id'] = $id;
    }
    
    // 可选：自动清理过期加固产物
    $releaseday = (int)Auth::getSetting($pdo, "releaseday", "3");
    autoClearExpiredJiaguTask($pdo, $releaseday);//清理加固任务
    autoClearExpiredInjectTask($pdo, $releaseday);//清理注入任务

    // 总数
    $countSql = "
        SELECT COUNT(*) FROM (
            SELECT id, user_id, status_text FROM cainiao_inject_task
            UNION ALL
            SELECT id, user_id, status_text FROM cainiao_jiagu_task
        ) t
        WHERE $where
    ";

    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // 数据
    $sql = "
    SELECT * FROM (
        /* ================= 注入任务 ================= */
        SELECT
            t.id,
            t.user_id,
            t.apk_id,
            t.sign_id,
    
            t.debugrun,
            t.debugimg,
            t.remark,
    
            NULL        AS type,
            t.encry,
            NULL        AS rules,
    
            t.status_text,
            t.status_info,
            t.created_at,
            t.completed_at,
            t.size,
            t.injected_apk,
            t.network,
            t.vpncheck,
            t.launcher,
            t.debug,
            t.killsign,
            t.killpath,
            t.dexmerge,
            t.confuse,
    
            tpl.name    AS template_name,
            tpl.version AS template_version,
    
            t.permissions,
            t.mode,
            t.devices,
            t.tv,
            t.jiagu,
            t.isMainProcess,
    
            a.name    AS apk_name,
            a.version AS apk_version,
            a.package AS apk_package,
    
            s.name    AS sign_name,
            s.alias   AS sign_alias,
    
            'inject' AS task_type
        FROM cainiao_inject_task t
        LEFT JOIN cainiao_apk a ON t.apk_id = a.id
        LEFT JOIN cainiao_template tpl ON t.template_id = tpl.id
        LEFT JOIN cainiao_sign s ON t.sign_id = s.id
    
        UNION ALL
    
        /* ================= 加固任务 ================= */
        SELECT
            t.id,
            t.user_id,
            t.apk_id,
            t.sign_id,
    
            NULL           AS debugrun,
            NULL        AS debugimg,
            NULL        AS remark,
    
            t.type      AS type,
            t.type      AS encry,
            t.rules     AS rules,
    
            t.status_text,
            t.status_info,
            t.created_at,
            t.completed_at,
            t.size,
            t.injected_apk,
    
            '加固任务' AS template_name,
            t.type      AS template_version,
    
            NULL AS permissions,
            NULL AS mode,
            NULL AS devices,
            NULL AS tv,
            NULL AS jiagu,
            NULL AS isMainProcess,
            NULL AS network,
            NULL AS vpncheck,
            NULL AS launcher,
            NULL AS debug,
            NULL AS killsign,
            NULL AS killpath,
            NULL AS dexmerge,
            NULL AS confuse,
    
            a.name    AS apk_name,
            a.version AS apk_version,
            a.package AS apk_package,
    
            s.name    AS sign_name,
            s.alias   AS sign_alias,
    
            'jiagu' AS task_type
        FROM cainiao_jiagu_task t
        LEFT JOIN cainiao_apk  a ON t.apk_id  = a.id
        LEFT JOIN cainiao_sign s ON t.sign_id = s.id
    
    ) u
    WHERE $where
    ORDER BY created_at DESC
    LIMIT $offset, $limit
    ";


    // 这里把 <注入SQL> <加固SQL> 用上面两段 SQL 字符串替换即可

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    //oss文件清理工作=====================================
    $ossObj = new OSS();
    // 查询超过5分钟的 OSS 下载记录
    $stmt = $pdo->prepare("
        SELECT id, file 
        FROM cainiao_download_record 
        WHERE source = 'oss' 
        AND file IS NOT NULL 
        AND file <> '' 
        AND download_time < (NOW() - INTERVAL 5 MINUTE)
    ");
    $stmt->execute();
    $expiredFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiredFiles as $row) {
        $file = $row['file'];
        if (!$file) continue;

        // 调用OSS删除方法
        $result = $ossObj->deleteFile($file);
        if ($result['code'] == 200) {
            // OSS删除成功，将file字段置为NULL
            $update = $pdo->prepare("UPDATE cainiao_download_record SET file = NULL WHERE id = :id");
            $update->execute([':id' => $row['id']]);
        }
    }

    return [
        'list'  => $list,
        'total' => $total,
        'page'  => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit),
    ];
}






//获取加固任务
function getJiaguTaskList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $page  = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $status = isset($input['status_text']) ? trim($input['status_text']) : '';
    $id = isset($input['id']) && is_numeric($input['id']) ? intval($input['id']) : null;

    // 可选：自动清理过期加固产物
    $releaseday = (int)Auth::getSetting($pdo, "releaseday", "3");
    autoClearExpiredJiaguTask($pdo, $releaseday);

    $where = "1=1";
    $params = [];

    if ($user['role'] !== 'admin') {
        $where .= " AND t.user_id = :user_id";
        $params[':user_id'] = $userId;
    }

    if ($status !== '') {
        $where .= " AND t.status_text = :status_text";
        $params[':status_text'] = $status;
    }

    if ($id !== null) {
        $where .= " AND t.id = :id";
        $params[':id'] = $id;
    }

    // ---------- 总数 ----------
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cainiao_jiagu_task t
        WHERE $where
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // ---------- 数据 ----------
    $sql = "
        SELECT
            t.*,

            a.name     AS apk_name,
            a.version  AS apk_version,
            a.package  AS apk_package,

            s.name     AS sign_name,
            s.alias    AS sign_alias

        FROM cainiao_jiagu_task t
        LEFT JOIN cainiao_apk  a ON t.apk_id  = a.id
        LEFT JOIN cainiao_sign s ON t.sign_id = s.id
        WHERE $where
        ORDER BY t.id DESC
        LIMIT $offset, $limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---------- OSS 清理（与 inject 保持一致） ----------
    $ossObj = new OSS();
    $stmt = $pdo->prepare("
        SELECT id, file
        FROM cainiao_download_record
        WHERE source = 'oss'
          AND file IS NOT NULL
          AND file <> ''
          AND download_time < (NOW() - INTERVAL 5 MINUTE)
    ");
    $stmt->execute();
    $expiredFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiredFiles as $row) {
        if (empty($row['file'])) continue;

        $result = $ossObj->deleteFile($row['file']);
        if ($result['code'] == 200) {
            $update = $pdo->prepare("
                UPDATE cainiao_download_record
                SET file = NULL
                WHERE id = :id
            ");
            $update->execute([':id' => $row['id']]);
        }
    }

    return [
        'list'  => $list,
        'total' => $total,
        'page'  => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit),
        'oss'   => $result ?? null
    ];
}
//自动删除过期加固任务
function autoClearExpiredJiaguTask(PDO $pdo, int $releaseday)
{
    $taskTable = 'cainiao_jiagu_task';

    // 计算过期时间
    $expireTime = date('Y-m-d H:i:s', strtotime("-{$releaseday} days"));

    // 查询已完成且过期的任务
    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            status_text,
            injected_apk
        FROM `$taskTable`
        WHERE completed_at IS NOT NULL
          AND completed_at < :expireTime
    ");
    $stmt->execute([':expireTime' => $expireTime]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deleted = [];

    foreach ($tasks as $task) {
        $status  = $task['status_text'] ?? '';
        $apkFile = trim($task['injected_apk'] ?? '');

        // 允许删除的状态
        // - 等待处理（异常遗留）
        // - 成功
        // - 任意失败状态
        $allowed = ['等待处理', '处理完成', '加固成功'];
        $canDelete = in_array($status, $allowed, true) || stripos($status, '失败') !== false;

        if (!$canDelete) {
            continue;
        }

        // 删除加固产物文件
        if ($apkFile !== '') {
            $releasePath = __DIR__ . '/../../release/' . $apkFile;
            if (is_file($releasePath)) {
                @unlink($releasePath);
            }
        }

        // 删除任务记录
        $del = $pdo->prepare("DELETE FROM `$taskTable` WHERE id = :id");
        $del->execute([':id' => $task['id']]);

        // 记录删除结果
        $deleted[] = [
            'id'           => (int)$task['id'],
            'status'       => $status,
            'injected_apk' => $apkFile,
        ];
    }

    return [
        'deleted_list'  => $deleted,
        'deleted_count' => count($deleted),
    ];
}





//获取注入任务/刷新注入任务/这里对高速下载记录进行查询/然后从oss中删除
function getInjectTaskList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;
    $status = isset($input['status_text']) ? trim($input['status_text']) : '';
    $id = isset($input['id']) && is_numeric($input['id']) ? intval($input['id']) : null;
    
    $releaseday = (int)Auth::getSetting($pdo, "releaseday", "3");
    $autoCleanResult = autoClearExpiredInjectTask($pdo, $releaseday);
    
    $where = "1=1";
    $params = [];

    if ($user['role'] !== 'admin') {
        $where .= " AND t.user_id = :user_id";
        $params[':user_id'] = $userId;
    }

    if ($status !== '') {
        $where .= " AND t.status_text = :status_text";
        $params[':status_text'] = $status;
    }

    if ($id !== null) {
        $where .= " AND t.id = :id";
        $params[':id'] = $id;
    }

    // 查询总数
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_inject_task t WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // 查询数据
    $sql = "
        SELECT 
            t.*, 
            a.name AS apk_name, a.version AS apk_version, a.package AS apk_package,
            tpl.name AS template_name, tpl.version AS template_version,
            s.name AS sign_name, s.alias AS sign_alias
        FROM cainiao_inject_task t
        LEFT JOIN cainiao_apk a ON t.apk_id = a.id
        LEFT JOIN cainiao_template tpl ON t.template_id = tpl.id
        LEFT JOIN cainiao_sign s ON t.sign_id = s.id
        WHERE $where
        ORDER BY t.id DESC
        LIMIT $offset, $limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    
    
    //oss文件清理工作=====================================
    $ossObj = new OSS();
    // 查询超过5分钟的 OSS 下载记录
    $stmt = $pdo->prepare("
        SELECT id, file 
        FROM cainiao_download_record 
        WHERE source = 'oss' 
        AND file IS NOT NULL 
        AND file <> '' 
        AND download_time < (NOW() - INTERVAL 5 MINUTE)
    ");
    $stmt->execute();
    $expiredFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiredFiles as $row) {
        $file = $row['file'];
        if (!$file) continue;

        // 调用OSS删除方法
        $result = $ossObj->deleteFile($file);
        if ($result['code'] == 200) {
            // OSS删除成功，将file字段置为NULL
            $update = $pdo->prepare("UPDATE cainiao_download_record SET file = NULL WHERE id = :id");
            $update->execute([':id' => $row['id']]);
        }
    }

    return [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit),
        'oss' => $result
    ];
}

//自动删除过期任务
function autoClearExpiredInjectTask($pdo, $releaseday)
{
    $taskTable = 'cainiao_inject_task';

    // 计算截止时间
    $expireTime = date('Y-m-d H:i:s', strtotime("-{$releaseday} days"));

    // 查询过期任务
    $sql = "
        SELECT id, user_id, remark, status_text, injected_apk
        FROM `$taskTable`
        WHERE completed_at < :expireTime
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':expireTime' => $expireTime]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deleted = [];

    foreach ($tasks as $task) {
        $status = $task['status_text'];
        $apkFile = trim($task['injected_apk'] ?? '');

        // 删除条件：等待处理 / 编译成功 / 失败
        $allowed = ['等待处理', '编译成功'];
        $canDelete = in_array($status, $allowed) || stripos($status, '失败') !== false;
        if (!$canDelete) {
            continue;
        }

        // 删除 release 文件
        if ($apkFile !== '') {
            $releasePath = __DIR__ . '/../../release/' . $apkFile;
            if (is_file($releasePath)) {
                @unlink($releasePath);
            }
        }

        // 删除任务
        $del = $pdo->prepare("DELETE FROM `$taskTable` WHERE id = :id");
        $del->execute([':id' => $task['id']]);

        // 系统通知（这里默认系统身份为 1，可以改成管理员ID）
        /*Auth::sendSystemMessage(
            $pdo,
            1,
            $task['user_id'],
            '【任务删除提醒】您的注入任务“' . $task['remark'] . '”已因过期自动清理，如需安装包请重新注入'
        );*/

        // 记录删除信息
        $deleted[] = [
            'id'          => (int)$task['id'],
            'remark'      => $task['remark'],
            'status'      => $task['status_text'],
            'injected_apk'=> $apkFile,
        ];
    }

    return [
        'deleted_list'  => $deleted,
        'deleted_count' => count($deleted),
    ];
}


//编辑注入任务 编辑任务
function updateInjectTaskRemark(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $id = (int)($input['id'] ?? 0);
    $mode = (int)($input['mode'] ?? 0);
    $remark = trim($input['remark'] ?? '');

    if (!$id) {
        throw new Exception('缺少任务ID');
    }

    // 权限校验
    $where = $user['role'] === 'admin' ? 'id = :id' : 'id = :id AND user_id = :uid';
    $params = [':id' => $id];
    if ($user['role'] !== 'admin') {
        $params[':uid'] = $userId;
    }

    $stmt = $pdo->prepare("SELECT id FROM cainiao_inject_task WHERE {$where}");
    $stmt->execute($params);

    if (!$stmt->fetch()) {
        throw new Exception('任务不存在或无权限');
    }

    // 布尔字段转整数
    $allowHttp = !empty($input['allowHttp']) ? 1 : 0;
    $debug = !empty($input['debug']) ? 1 : 0;
    $killsign = !empty($input['killsign']) ? 1 : 0;
    $killpath = !empty($input['killpath']) ? 1 : 0;
    $kill_Inject = !empty($input['kill_Inject']) ? 1 : 0;
    $network = !empty($input['network']) ? 1 : 0;
    $jiagu = !empty($input['jiagu']) ? 1 : 0;
    $fake = !empty($input['fake']) ? 1 : 0;
    $vpncheck = !empty($input['vpncheck']) ? 1 : 0;
    $confuse = !empty($input['confuse']) ? 1 : 0;
    $process = !empty($input['process']) ? 1 : 0;
    $dexmerge = !empty($input['dexmerge']) ? 1 : 0;
    $launcher = !empty($input['launcher']) ? 1 : 0;
    // 处理 Request 字段，仅在提交不为空时更新
    $updateRequest = isset($input['Request']) ? true : false;
    $Request = $updateRequest ? (int)$input['Request'] : null;
    
    $updatedevices = isset($input['devices']) ? true : false;
    $devices = $updatedevices ? (int)$input['devices'] : null;
    $tv = !empty($input['tv']) ? 1 : 0;
    $permissions = isset($input['permissions']) && is_array($input['permissions']) ? $input['permissions'] : [];
    $permissionsJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);

    $vipjiagu  = Auth::getSetting($pdo,"vipjiagu","0");
    if($vipjiagu && $jiagu){//如果开启了会员加固且提交的加固为1
        if(!$user['isVip']){
            throw new Exception('APK加固功能仅会员可用');
        }
    }
    
    if($jiagu && $killpath){
        throw new Exception('加强去签和加固不能同时勾选');
    }

    // 可选参数：template_id
    $templateId = isset($input['template_id']) ? (int)$input['template_id'] : 0;
    if ($templateId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_template WHERE id = :id AND enabled = 1");
        $stmt->execute([':id' => $templateId]);
        $exists = $stmt->fetchColumn();
        if (!$exists && $user['role'] !== 'admin') {
            throw new Exception('壳模板不存在');
        }
    }

    // 可选参数：sign_id
    $signId = isset($input['sign_id']) ? (int)$input['sign_id'] : 0;
    if ($signId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_sign WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $signId, ':uid' => $userId]);
        if (!$stmt->fetchColumn()) {
            throw new Exception('签名证书不属于当前用户');
        }
    }

    // 构建更新语句
    $set = [
        'remark = :remark',
        'mode = :mode',
        'allowhttp = :allowHttp',
        'debug = :debug',
        'killsign = :killsign',
        'killpath = :killpath',
        'kill_inject = :killInject',
        'network = :network',
        'confuse = :confuse',
        'jiagu = :jiagu',
        'fake = :fake',
        'vpncheck = :vpncheck',
        'isMainProcess = :isMainProcess',
        'dexmerge = :dexmerge',
        'launcher = :launcher',
        'devices = :devices',
        'tv = :tv',
        'permissions = :permissions'
    ];
    $bind = [
        ':remark' => $remark,
        ':mode' => $mode,
        ':allowHttp' => $allowHttp,
        ':debug' => $debug,
        ':killsign' => $killsign,
        ':killpath' => $killpath,
        ':killInject' => $kill_Inject,
        ':network' => $network,
        ':confuse' => $confuse,
        ':jiagu' => $jiagu,
        ':fake' => $fake,
        ':vpncheck' => $vpncheck,
        ':isMainProcess' => $process,
        ':dexmerge' => $dexmerge,
        ':launcher' => $launcher,
        ':devices' => $devices,
        ':tv' => $tv,
        ':permissions'  => $permissionsJson,
    ];

    // 仅在提交了 Request 时加入更新
    if ($updateRequest) {
        $set[] = 'Request = :Request';
        $bind[':Request'] = $Request;
    }

    if ($templateId) {
        $set[] = 'template_id = :template_id';
        $bind[':template_id'] = $templateId;
    }

    if ($signId) {
        $set[] = 'sign_id = :sign_id';
        $bind[':sign_id'] = $signId;
    }

    $sql = "UPDATE cainiao_inject_task SET " . implode(', ', $set) . " WHERE {$where}";
    $update = $pdo->prepare($sql);
    $update->execute(array_merge($bind, $params));

    return ['message' => '更新成功'];
}




//重试调试任务
function startInjectDebugTask(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        throw new Exception('缺少任务ID');
    }

    // 限制普通用户夜间无法提交调试任务
    if ($user['role'] !== 'admin') {
        date_default_timezone_set('Asia/Shanghai'); // 设置为北京时间
        $now = date('H:i');

        if (
            ($now >= '20:30' && $now <= '23:59') ||
            ($now >= '00:00' && $now <= '03:00')
        ) {
            throw new Exception('当前为夜间高峰时段，禁止提交调试任务');
        }
    }

    // 权限和所属任务校验
    $where = $user['role'] === 'admin' ? 'id = :id' : 'id = :id AND user_id = :uid';
    $params = [':id' => $id];
    if ($user['role'] !== 'admin') {
        $params[':uid'] = $userId;
    }

    $stmt = $pdo->prepare("SELECT id FROM cainiao_inject_task WHERE {$where}");
    $stmt->execute($params);
    if (!$stmt->fetch()) {
        throw new Exception('任务不存在或无权限');
    }

    // 普通用户最多只能有一个调试任务
    if ($user['role'] !== 'admin') {
        $check = $pdo->prepare("SELECT COUNT(*) FROM cainiao_inject_task WHERE user_id = :uid AND debugrun = 0 AND status_text = '编译成功'");
        $check->execute([':uid' => $userId]);
        $count = (int)$check->fetchColumn();
        if ($count > 0) {
            throw new Exception('最多允许1个调试任务');
        }
    }

    // 执行更新
    $update = $pdo->prepare("UPDATE cainiao_inject_task SET debugrun = 0, status_info = '等待云调试' WHERE {$where}");
    $update->execute($params);

    return ['message' => '已提交调试任务'];
}



//重试任务
function retryInjectTask(PDO $pdo, array $input)
{
    //throw new Exception('服务器维护中,暂停任务处理');
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $id = (int)($input['id'] ?? 0);

    if (!$id) {
        throw new Exception('缺少任务ID');
    }
    // 构造条件
    $isAdmin = $user['role'] === 'admin';
    $where = $isAdmin ? 'id = :id' : 'id = :id AND user_id = :uid';
    $params = [':id' => $id];
    if (!$isAdmin) {
        $params[':uid'] = $userId;
    }

    // 查询任务
    $stmt = $pdo->prepare("SELECT * FROM cainiao_inject_task WHERE {$where}");
    $stmt->execute($params);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        throw new Exception('任务不存在或无权限');
    }
    // 查询对应 APK 的路径
    $apkStmt = $pdo->prepare("SELECT path FROM cainiao_apk WHERE id = :apk_id");
    $apkStmt->execute([':apk_id' => (int)$task['apk_id']]);
    $apk = $apkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$apk || empty($apk['path'])) {
        throw new Exception('原始安装包不存在,请先重传安装包');
    }
    // 判断是否可重试
    if (
        stripos($task['status_text'], '失败') === false &&
        stripos($task['status_text'], '成功') === false
    ) {
        throw new Exception('仅失败或成功的任务可重试');
    }

    // 更新任务状态
    $update = $pdo->prepare("UPDATE cainiao_inject_task SET status_text = '等待处理', status_info = '请等待任务队列', completed_at = NULL, debugrun = 0 WHERE {$where}");
    $update->execute($params);

    return ['message' => '任务已重置为等待处理'];
}


//删除任务，删除注入
function deleteInjectTask(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $id = isset($input['id']) ? (int)$input['id'] : 0;

    if (!$id) {
        throw new Exception('缺少任务ID');
    }

    // 查询任务信息
    if($user['role'] !== 'admin'){
        $stmt = $pdo->prepare("SELECT status_text, injected_apk FROM cainiao_inject_task WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $userId]);
    }else{
        $stmt = $pdo->prepare("SELECT status_text, injected_apk, user_id, remark FROM cainiao_inject_task WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
    
    
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        throw new Exception('任务不存在或无权限');
    }

    $status = $task['status_text'];
    $apkFile = trim($task['injected_apk'] ?? '');

    // 状态检查
    $allowed = ['等待处理', '编译成功'];
    $canDelete = in_array($status, $allowed) || stripos($status, '失败') !== false;

    if (!$canDelete) {
        throw new Exception('仅允许删除等待处理、编译成功或失败的任务');
    }

    // 删除 release 中的注入后文件
    if ($apkFile !== '') {
        $releasePath = __DIR__ . '/../../release/' . $apkFile;
        if (is_file($releasePath)) {
            @unlink($releasePath);
        }
    }
    if($user['role'] !== 'admin'){
        // 删除任务记录
        $del = $pdo->prepare("DELETE FROM cainiao_inject_task WHERE id = :id AND user_id = :uid");
        $del->execute([':id' => $id, ':uid' => $userId]);
    }else{
        $del = $pdo->prepare("DELETE FROM cainiao_inject_task WHERE id = :id");
        $del->execute([':id' => $id]);
        Auth::sendSystemMessage($pdo, $user['id'], $task['user_id'], '【任务删除提醒】您的注入任务“'.$task['remark'].'”已被删除清理,如需要安装包,请重新注入');
    }

    return ['message' => '任务和生成文件已删除'];
}


//删除任务二合一
function deleteTask(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];

    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $taskType = trim($input['task_type'] ?? '');

    if (!$id || !in_array($taskType, ['inject', 'jiagu'], true)) {
        throw new Exception('缺少任务ID或任务类型错误');
    }

    // ---------- 根据类型确定表名 ----------
    if ($taskType === 'inject') {
        $table = 'cainiao_inject_task';
        $hasRemark = true;
        $taskName = '注入任务';
    } else {
        $table = 'cainiao_jiagu_task';
        $hasRemark = false;
        $taskName = '加固任务';
    }

    // ---------- 查询任务 ----------
    if ($user['role'] !== 'admin') {
        $stmt = $pdo->prepare("
            SELECT status_text, injected_apk
            FROM `$table`
            WHERE id = :id AND user_id = :uid
        ");
        $stmt->execute([
            ':id'  => $id,
            ':uid' => $userId
        ]);
    } else {
        // admin 多查 user_id + remark（inject 才有）
        if ($hasRemark) {
            $stmt = $pdo->prepare("
                SELECT status_text, injected_apk, user_id, remark
                FROM `$table`
                WHERE id = :id
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT status_text, injected_apk, user_id
                FROM `$table`
                WHERE id = :id
            ");
        }
        $stmt->execute([':id' => $id]);
    }

    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        throw new Exception('任务不存在或无权限');
    }

    // ---------- 状态校验 ----------
    $status = $task['status_text'] ?? '';
    $apkFile = trim($task['injected_apk'] ?? '');

    // 允许删除的状态
    $allowed = ['等待处理', '编译成功', '加固成功', '处理完成'];
    $canDelete = in_array($status, $allowed, true) || stripos($status, '失败') !== false;

    if (!$canDelete) {
        throw new Exception('仅允许删除等待处理、成功或失败的任务');
    }

    // ---------- 删除产物文件 ----------
    if ($apkFile !== '') {
        $releasePath = __DIR__ . '/../../release/' . $apkFile;
        if (is_file($releasePath)) {
            @unlink($releasePath);
        }
    }

    // ---------- 删除数据库记录 ----------
    if ($user['role'] !== 'admin') {
        $del = $pdo->prepare("
            DELETE FROM `$table`
            WHERE id = :id AND user_id = :uid
        ");
        $del->execute([
            ':id'  => $id,
            ':uid' => $userId
        ]);
    } else {
        $del = $pdo->prepare("DELETE FROM `$table` WHERE id = :id");
        $del->execute([':id' => $id]);

        // admin 删除发送系统通知（inject 才有 remark）
        if ($taskType === 'inject') {
            Auth::sendSystemMessage(
                $pdo,
                $user['id'],
                $task['user_id'],
                '【任务删除提醒】您的注入任务“' . ($task['remark'] ?? '') . '”已被删除，如需安装包请重新注入'
            );
        } else {
            Auth::sendSystemMessage(
                $pdo,
                $user['id'],
                $task['user_id'],
                '【任务删除提醒】您的加固任务已被删除，如需安装包请重新创建加固任务'
            );
        }
    }

    return ['message' => '任务及生成文件已删除'];
}


/**
 * 桌面端本地创建应用（不上传APK文件，只提交基本信息）
 * 调用方式：POST /api/?module=app&method=createAppLocal
 * 参数：name(应用名), package(包名), version(版本号), icon(图标base64,可选)
 */
function createAppLocal(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $apkTable = 'cainiao_apk';

    $name = trim($input['name'] ?? '');
    $package = trim($input['package'] ?? '');
    $version = trim($input['version'] ?? '1.0.0');

    if (empty($name) || empty($package)) {
        throw new Exception('应用名和包名不能为空');
    }

    // 检查是否已存在相同包名的应用
    $stmt = $pdo->prepare("SELECT id FROM `$apkTable` WHERE user_id = :uid AND package = :pkg LIMIT 1");
    $stmt->execute([':uid' => $userId, ':pkg' => $package]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        // 已存在，直接返回已有的 apk_id
        return [
            'message' => '应用已存在，复用已有记录',
            'apk_id' => $existing['id']
        ];
    }

    // 处理图标（base64 → 文件）
    $icon = 'android.png'; // 默认图标
    if (!empty($input['icon'])) {
        $iconData = base64_decode($input['icon']);
        if ($iconData !== false) {
            $iconDir = __DIR__ . '/../../icon/';
            if (!is_dir($iconDir)) {
                mkdir($iconDir, 0755, true);
            }
            // 先插入拿 ID，再保存图标
        }
    }

    // 插入记录（path 为空，size 为 0，表示本地注入模式）
    $insertStmt = $pdo->prepare("INSERT INTO `$apkTable`
        (name, version, package, path, user_id, size, upload_time, icon)
        VALUES (:name, :version, :package, '', :user_id, 0, NOW(), :icon)");

    $insertStmt->execute([
        ':name'    => $name,
        ':version' => $version,
        ':package' => $package,
        ':user_id' => $userId,
        ':icon'    => $icon
    ]);

    $apkId = $pdo->lastInsertId();

    // 如果有图标 base64，保存到文件
    if (!empty($input['icon'])) {
        $iconData = base64_decode($input['icon']);
        if ($iconData !== false) {
            $iconDir = __DIR__ . '/../../icon/';
            $iconFile = $iconDir . $apkId . '.png';
            file_put_contents($iconFile, $iconData);
            $icon = $apkId . '.png';
            $pdo->prepare("UPDATE `$apkTable` SET icon = :icon WHERE id = :id")
                ->execute([':icon' => $icon, ':id' => $apkId]);
        }
    }

    return [
        'message' => '应用创建成功',
        'apk_id' => $apkId
    ];
}

/**
 * 从 URL 创建注入任务（仅管理员）
 * API 只创建任务记录，下载由 worker 异步完成
 */
function createTaskFromUrl(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('仅管理员可使用此功能');
    }
    $userId = (int)$user['id'];

    $url = trim($input['url'] ?? '');
    $templateId = isset($input['template_id']) ? (int)$input['template_id'] : 0;
    $signId = isset($input['sign_id']) ? $input['sign_id'] : 0;
    $isRandomSign = ($signId === 'random');
    if ($isRandomSign) {
        $signId = 0;
    } else {
        $signId = (int)$signId;
    }

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception('请输入有效的 APK 下载链接');
    }
    if (!$templateId) {
        throw new Exception('请选择壳模板');
    }
    if (!$signId && !$isRandomSign) {
        throw new Exception('请选择签名证书');
    }

    // 注入选项
    $remark = trim($input['remark'] ?? 'URL注入');
    $inject_to_top = (int)(bool)($input['inject_to_top'] ?? 0);
    $mode = isset($input['mode']) ? (int)$input['mode'] : 3;
    $debug = (int)(bool)($input['debug'] ?? 0);
    $allowHttp = (int)(bool)($input['allow_http'] ?? 0);
    $network = (int)(bool)($input['network'] ?? 1);
    $jiagu = (int)(bool)($input['jiagu'] ?? 0);
    $fake = (int)(bool)($input['fake'] ?? 0);
    $vpncheck = (int)(bool)($input['vpncheck'] ?? 0);
    $launcher = (int)(bool)($input['launcher'] ?? 0);
    $kill_Inject = (int)(bool)($input['kill_Inject'] ?? 0);
    $killsign = (int)(bool)($input['killsign'] ?? 0);
    $killpath = (int)(bool)($input['killpath'] ?? 0);
    $Request = (int)(bool)($input['Request'] ?? 0);
    $devices = (int)(bool)($input['devices'] ?? 0);
    $tv = (int)(bool)($input['tv'] ?? 0);
    $confuse = (int)(bool)($input['confuse'] ?? 0);
    $process = (int)($input['process'] ?? 0);
    $dexmerge = (int)($input['dexmerge'] ?? 0);
    $permissions = isset($input['permissions']) && is_array($input['permissions']) ? $input['permissions'] : [];
    $permissionsJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);

    // ===== 1. 创建占位应用记录（path 为空，worker 下载后填充） =====
    $apkTable = 'cainiao_apk';
    $insertStmt = $pdo->prepare("INSERT INTO `$apkTable`
        (name, version, package, path, user_id, size, upload_time)
        VALUES (:name, :version, :package, '', :user_id, 0, NOW())");
    $insertStmt->execute([
        ':name'    => '等待下载',
        ':version' => '等待下载',
        ':package' => 'url_' . md5($url),
        ':user_id' => $userId,
    ]);
    $apkId = (int)$pdo->lastInsertId();

    // ===== 2. 创建注入任务（状态"等待下载"，source_url 存 URL） =====
    $insert = $pdo->prepare("INSERT INTO `cainiao_inject_task`
        (remark, user_id, template_id, apk_id, sign_id, created_at, status_text, status_info, source_url,
         inject_to_top, allowHttp, permissions, mode, debug, killsign, killpath, Request,
         kill_Inject, network, confuse, jiagu, fake, vpncheck, isMainProcess, dexmerge, launcher, devices, tv)
        VALUES (:remark, :user_id, :template_id, :apk_id, :sign_id, NOW(), '等待下载', '等待下载 APK', :source_url,
         :inject_to_top, :allowHttp, :permissions, :mode, :debug, :killsign, :killpath, :Request,
         :kill_Inject, :network, :confuse, :jiagu, :fake, :vpncheck, :isMainProcess, :dexmerge, :launcher, :devices, :tv)");

    $insert->execute([
        ':remark'        => $remark,
        ':user_id'       => $userId,
        ':template_id'   => $templateId,
        ':apk_id'        => $apkId,
        ':sign_id'       => $signId,
        ':source_url'    => $url,
        ':inject_to_top' => $inject_to_top,
        ':allowHttp'     => $allowHttp,
        ':permissions'   => $permissionsJson,
        ':mode'          => $mode,
        ':debug'         => $debug,
        ':killsign'      => $killsign,
        ':killpath'      => $killpath,
        ':Request'       => $Request,
        ':kill_Inject'   => $kill_Inject,
        ':network'       => $network,
        ':confuse'       => $confuse,
        ':jiagu'         => $jiagu,
        ':fake'          => $fake,
        ':vpncheck'      => $vpncheck,
        ':isMainProcess' => $process,
        ':dexmerge'      => $dexmerge,
        ':launcher'      => $launcher,
        ':devices'       => $devices,
        ':tv'            => $tv,
    ]);

    $taskId = $pdo->lastInsertId();

    return [
        'message' => "注入任务 #{$taskId} 已创建，正在后台下载 APK...",
        'apk_id' => $apkId,
        'task_id' => $taskId,
    ];
}

