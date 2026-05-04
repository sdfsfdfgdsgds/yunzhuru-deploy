<?php


/*function getCompiledList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $where = "t.status_text = '编译成功' AND t.injected_apk IS NOT NULL AND t.injected_apk != ''";


    $countStmt = $pdo->query("SELECT COUNT(*) FROM cainiao_inject_task t WHERE $where");
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT 
            t.*, 
            a.name AS apk_name, a.version AS apk_version, a.package AS apk_package,
            tpl.name AS template_name, tpl.version AS template_version,
            s.name AS sign_name, s.alias AS sign_alias,
            u.nickname AS user_name,
            u.account AS user_account
        FROM cainiao_inject_task t
        LEFT JOIN cainiao_apk a ON t.apk_id = a.id
        LEFT JOIN cainiao_template tpl ON t.template_id = tpl.id
        LEFT JOIN cainiao_sign s ON t.sign_id = s.id
        LEFT JOIN cainiao_user u ON t.user_id = u.id
        WHERE $where
        ORDER BY t.id ASC
        LIMIT $offset, $limit
    ";

    $stmt = $pdo->query($sql);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ];
}*/
function list_sign_useless(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    $signDir = __DIR__ . '/../../signfile/';
    if (!is_dir($signDir)) {
        throw new Exception('signfile目录不存在');
    }

    $stmt = $pdo->query("SELECT path FROM cainiao_sign");
    $validPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $validSet = array_flip($validPaths);

    $useless = [];

    $handle = opendir($signDir);
    if (!$handle) {
        throw new Exception('无法读取目录');
    }

    while (false !== ($file = readdir($handle))) {
        if ($file === '.' || $file === '..') continue;

        $fullPath = $signDir . $file;
        if (!is_file($fullPath)) continue;

        if (!isset($validSet[$file])) {
            $useless[] = [
                'name' => $file,
                'size' => filesize($fullPath),
                'mtime' => date('Y-m-d H:i:s', filemtime($fullPath))
            ];
        }
    }
    closedir($handle);

    return [
        'total' => count($useless),
        'files' => $useless
    ];
}

function delete_signfile_useless(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    if (empty($input['files']) || !is_array($input['files'])) {
        throw new Exception('参数错误：缺少文件数组');
    }

    $signDir = __DIR__ . '/../../signfile/';
    if (!is_dir($signDir)) {
        throw new Exception('signfile目录不存在');
    }

    $inputFiles = array_unique(array_filter($input['files'], function($file) {
        return preg_match('/^[a-zA-Z0-9_\.\-]+$/', $file); // 安全校验
    }));

    if (empty($inputFiles)) {
        throw new Exception('无效的文件名');
    }

    // 检查数据库中是否存在这些文件
    $placeholders = implode(',', array_fill(0, count($inputFiles), '?'));
    $stmt = $pdo->prepare("SELECT path FROM cainiao_sign WHERE path IN ($placeholders)");
    $stmt->execute($inputFiles);
    $usedPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $usedSet = array_flip($usedPaths);

    $deleted = [];
    $skipped = [];

    foreach ($inputFiles as $file) {
        if (isset($usedSet[$file])) {
            $skipped[] = $file;
            continue;
        }

        $filePath = $signDir . $file;
        if (is_file($filePath)) {
            @unlink($filePath);
            $deleted[] = $file;
        } else {
            $skipped[] = $file;
        }
    }

    return [
        'message' => '删除完成',
        'deleted' => $deleted,
        'skipped' => $skipped
    ];
}


function delete_sign_useless(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    if (empty($input['files']) || !is_array($input['files'])) {
        throw new Exception('参数错误：缺少文件数组');
    }

    $signDir = __DIR__ . '/../../signfile/';
    if (!is_dir($signDir)) {
        throw new Exception('signfile目录不存在');
    }

    $inputFiles = array_unique(array_filter($input['files'], function($file) {
        return preg_match('/^[a-zA-Z0-9_\.\-]+$/', $file); // 安全校验
    }));

    if (empty($inputFiles)) {
        throw new Exception('无效的文件名');
    }

    // 检查数据库中是否存在这些文件
    $placeholders = implode(',', array_fill(0, count($inputFiles), '?'));
    $stmt = $pdo->prepare("SELECT path FROM cainiao_sign WHERE path IN ($placeholders)");
    $stmt->execute($inputFiles);
    $usedPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $usedSet = array_flip($usedPaths);

    $deleted = [];
    $skipped = [];

    foreach ($inputFiles as $file) {
        if (isset($usedSet[$file])) {
            $skipped[] = $file;
            continue;
        }

        $filePath = $signDir . $file;
        if (is_file($filePath)) {
            @unlink($filePath);
            $deleted[] = $file;
        } else {
            $skipped[] = $file;
        }
    }

    return [
        'message' => '删除完成',
        'deleted' => $deleted,
        'skipped' => $skipped
    ];
}

function list_uploads_useless(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    $uploadPath = __DIR__ . '/../../uploads/';
    if (!is_dir($uploadPath)) {
        throw new Exception('uploads目录不存在');
    }

    $stmt = $pdo->query("SELECT path FROM cainiao_apk");
    $validPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $validSet = array_flip($validPaths);

    $useless = [];

    $handle = opendir($uploadPath);
    if (!$handle) {
        throw new Exception('无法读取目录');
    }

    while (false !== ($file = readdir($handle))) {
        if ($file === '.' || $file === '..') continue;

        $fullPath = $uploadPath . $file;
        if (!is_file($fullPath)) continue;

        if (!isset($validSet[$file])) {
            $useless[] = [
                'name' => $file,
                'size' => filesize($fullPath),
                'mtime' => date('Y-m-d H:i:s', filemtime($fullPath))
            ];
        }
    }
    closedir($handle);

    return [
        'total' => count($useless),
        'files' => $useless
    ];
}


function delete_uploads_useless(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    if (empty($input['files']) || !is_array($input['files'])) {
        throw new Exception('参数错误：缺少文件数组');
    }

    $uploadPath = __DIR__ . '/../../uploads/';
    if (!is_dir($uploadPath)) {
        throw new Exception('uploads目录不存在');
    }

    $inputFiles = array_unique(array_filter($input['files'], function($file) {
        return preg_match('/^[a-zA-Z0-9_\.\-]+$/', $file); // 安全校验
    }));

    if (empty($inputFiles)) {
        throw new Exception('无效的文件名');
    }

    // 检查数据库中是否存在这些文件
    $placeholders = implode(',', array_fill(0, count($inputFiles), '?'));
    $stmt = $pdo->prepare("SELECT path FROM cainiao_apk WHERE path IN ($placeholders)");
    $stmt->execute($inputFiles);
    $usedPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $usedSet = array_flip($usedPaths);

    $deleted = [];
    $skipped = [];

    foreach ($inputFiles as $file) {
        if (isset($usedSet[$file])) {
            $skipped[] = $file;
            continue;
        }

        $filePath = $uploadPath . $file;
        if (is_file($filePath)) {
            @unlink($filePath);
            $deleted[] = $file;
        } else {
            $skipped[] = $file;
        }
    }

    return [
        'message' => '删除完成',
        'deleted' => $deleted,
        'skipped' => $skipped
    ];
}

/*function list_release_useless(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    $releasePath = __DIR__ . '/../../release/';
    if (!is_dir($releasePath)) {
        throw new Exception('release目录不存在');
    }

    // 获取数据库中所有 injected_apk 字段的文件名
    $stmt = $pdo->query("SELECT injected_apk FROM cainiao_inject_task WHERE injected_apk IS NOT NULL AND injected_apk != ''");
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $dbFiles = array_flip($rows); // 用于快速判断

    $uselessFiles = [];

    $dir = new FilesystemIterator($releasePath, FilesystemIterator::SKIP_DOTS);
    foreach ($dir as $fileInfo) {
        if (!$fileInfo->isFile()) continue;

        $filename = $fileInfo->getFilename();
        if (!isset($dbFiles[$filename])) {
            $uselessFiles[] = [
                'name' => $filename,
                'size' => $fileInfo->getSize(),
                'mtime' => date('Y-m-d H:i:s', $fileInfo->getMTime())
            ];
        }
    }

    return [
        'total' => count($uselessFiles),
        'files' => $uselessFiles
    ];
}*/
//垃圾任务文件扫描双表版
function list_release_useless(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    $releasePath = __DIR__ . '/../../release/';
    if (!is_dir($releasePath)) {
        throw new Exception('release目录不存在');
    }

    // ---------- 查询 inject 任务的有效文件 ----------
    $stmt = $pdo->query("
        SELECT injected_apk
        FROM cainiao_inject_task
        WHERE injected_apk IS NOT NULL AND injected_apk != ''
    ");
    $injectFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // ---------- 查询 jiagu 任务的有效文件 ----------
    $stmt = $pdo->query("
        SELECT injected_apk
        FROM cainiao_jiagu_task
        WHERE injected_apk IS NOT NULL AND injected_apk != ''
    ");
    $jiaguFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 合并为“有效文件集合”
    $validFiles = [];
    foreach ($injectFiles as $f) {
        $validFiles[$f] = true;
    }
    foreach ($jiaguFiles as $f) {
        $validFiles[$f] = true;
    }

    $uselessFiles = [];

    // ---------- 扫描 release 目录 ----------
    $dir = new FilesystemIterator($releasePath, FilesystemIterator::SKIP_DOTS);
    foreach ($dir as $fileInfo) {
        if (!$fileInfo->isFile()) continue;

        $filename = $fileInfo->getFilename();

        // inject + jiagu 都不存在 ⇒ 垃圾
        if (!isset($validFiles[$filename])) {
            $uselessFiles[] = [
                'name'  => $filename,
                'size'  => $fileInfo->getSize(),
                'mtime' => date('Y-m-d H:i:s', $fileInfo->getMTime())
            ];
        }
    }

    return [
        'total' => count($uselessFiles),
        'files' => $uselessFiles
    ];
}


function delete_release_useless(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    if (empty($input['files']) || !is_array($input['files'])) {
        throw new Exception('参数错误：缺少文件数组');
    }

    $releasePath = __DIR__ . '/../../release/';
    if (!is_dir($releasePath)) {
        throw new Exception('release目录不存在');
    }

    // 只处理不重复的文件名，防止注入
    $inputFiles = array_unique(array_filter($input['files'], function($file) {
        return preg_match('/^[a-zA-Z0-9_\.\-]+$/', $file); // 简单安全校验
    }));

    if (empty($inputFiles)) {
        throw new Exception('无效的文件名');
    }

    // 查找这些文件中哪些已存在于数据库中
    $placeholders = implode(',', array_fill(0, count($inputFiles), '?'));
    $stmt = $pdo->prepare("SELECT injected_apk FROM cainiao_inject_task WHERE injected_apk IN ($placeholders)");
    $stmt->execute($inputFiles);
    $usedFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $usedSet = array_flip($usedFiles);

    $deleted = [];
    $skipped = [];

    foreach ($inputFiles as $file) {
        if (isset($usedSet[$file])) {
            $skipped[] = $file;
            continue;
        }

        $filePath = $releasePath . $file;
        if (is_file($filePath)) {
            @unlink($filePath);
            $deleted[] = $file;
        } else {
            $skipped[] = $file; // 文件不存在也跳过
        }
    }

    return [
        'message' => '删除完成',
        'deleted' => $deleted,
        'skipped' => $skipped
    ];
}



function getCompiledList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $where = "t.status_text = '编译成功' AND t.injected_apk IS NOT NULL AND t.injected_apk != ''";

    $countStmt = $pdo->query("SELECT COUNT(*) FROM cainiao_inject_task t WHERE $where");
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT 
            t.*, 
            a.name AS apk_name, a.version AS apk_version, a.package AS apk_package,
            tpl.name AS template_name, tpl.version AS template_version,
            s.name AS sign_name, s.alias AS sign_alias,
            u.nickname AS user_name,
            u.account AS user_account,
            DATEDIFF(CURRENT_DATE(), t.completed_at) AS days_since_created
        FROM cainiao_inject_task t
        LEFT JOIN cainiao_apk a ON t.apk_id = a.id
        LEFT JOIN cainiao_template tpl ON t.template_id = tpl.id
        LEFT JOIN cainiao_sign s ON t.sign_id = s.id
        LEFT JOIN cainiao_user u ON t.user_id = u.id
        WHERE $where
        ORDER BY t.id ASC
        LIMIT $offset, $limit
    ";

    $stmt = $pdo->query($sql);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ];
}


function deleteCompiledFile(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权操作');
    }

    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if (!$id) {
        throw new Exception('缺少任务ID');
    }

    // 查询任务记录
    $stmt = $pdo->prepare("SELECT injected_apk, status_text, user_id, remark FROM cainiao_inject_task WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        throw new Exception('任务不存在');
    }

    if (trim($task['status_text']) !== '编译成功') {
        throw new Exception('仅允许删除状态为编译成功的任务生成文件');
    }

    $apkFile = trim($task['injected_apk'] ?? '');
    if ($apkFile === '') {
        throw new Exception('未找到生成的 APK 文件名');
    }

    $releasePath = __DIR__ . '/../../release/' . $apkFile;
    if (is_file($releasePath)) {
        if (!unlink($releasePath)) {
            throw new Exception('文件删除失败');
        }
    } else {
        throw new Exception('文件不存在');
    }
    
    // 清空数据库字段
    $update = $pdo->prepare("UPDATE cainiao_inject_task SET injected_apk = '', size = NULL WHERE id = :id");
    $update->execute([':id' => $id]);
    Auth::sendSystemMessage($pdo, $user['id'], $task['user_id'], '【任务删除提醒】您的注入任务“'.$task['remark'].'”的已编译文件已被删除清理,如需要安装包,请重新注入');
    return ['message' => 'APK 文件已删除，任务记录已保留'];
}

