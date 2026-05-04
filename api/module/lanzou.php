<?php

//获取我的蓝奏信息
function getlanzou(PDO $pdo, array $input)
{
    // 获取当前用户信息（由 Auth 中间件返回完整字段）
    $user = Auth::check($pdo);
    $data = Lanzou::checkLogin($user['lanzou_cookie']);
    if(!$data){
        return ['lanzou_account' => $user['lanzou_account'],'lanzou_password' => $user['lanzou_password'],'lanzou_uid' => ''];
    }
    return ['lanzou_account' => $user['lanzou_account'],'lanzou_password' => $user['lanzou_password'],'lanzou_uid' => $user['lanzou_uid']];
}

//保存蓝奏信息
function editlanzou(PDO $pdo, array $input) 
{
    // 获取当前用户信息（由 Auth 中间件返回完整字段）
    $user = Auth::check($pdo);
    $userId = $user['id'];

    $lanzou_account = $input['account'];
    $lanzou_password = $input['password'];
    $login = Lanzou::login($lanzou_account, $lanzou_password);

    if (!$login || empty($login['success'])) {
        throw new Exception($login['error'] ?? '登录失败');
    }

    $lanzou_cookie = $login['cookies'];
    $lanzou_uid = $login['user_id'];

    // 更新 cainiao_user 表
    $sql = "UPDATE cainiao_user 
            SET lanzou_account = :account,
                lanzou_password = :password,
                lanzou_cookie = :cookie,
                lanzou_uid = :uid
            WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':account' => $lanzou_account,
        ':password' => $lanzou_password,
        ':cookie' => $lanzou_cookie,
        ':uid' => $lanzou_uid,
        ':id' => $userId
    ]);

    return '登录成功';
}

//获取蓝奏目录
function getdir(PDO $pdo, array $input){
    $user = Auth::check($pdo);
    $dir_id = $input['dir_id'];
    if(empty($dir_id)){
        $dir_id = '-1';
    }
    return Lanzou::getFolder($user['lanzou_cookie'], $user['lanzou_uid'], $dir_id);
}

//将文件保存到蓝奏
function savefile(PDO $pdo, array $input) {
    $user = Auth::check($pdo);
    $userId = $user['id'];
    $file_id = $input['id'];
    $folderId = $input['folderId'] ?? '-1';
    throw new Exception('该功能已停用');
    // 检查当前用户是否存在“正在上传”的任务
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_inject_task WHERE user_id = :user_id AND status_info = '正在上传'");
    $stmt->execute([':user_id' => $userId]);
    $count = $stmt->fetchColumn();
    if ($count > 0) {
        throw new Exception('您有正在上传的任务，请稍后再试');
    }

    // 查询注入任务
    if ($user['role'] !== 'admin') {
        $stmt = $pdo->prepare("SELECT * FROM cainiao_inject_task WHERE id = :id AND user_id = :user_id LIMIT 1");
        $stmt->execute([
            ':id' => $file_id,
            ':user_id' => $userId
        ]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM cainiao_inject_task WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $file_id]);
    }

    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        throw new Exception('文件不存在或无权限访问');
    }

    if ($task['status_text'] !== '编译成功') {
        throw new Exception('文件未编译完成');
    }

    if (empty($task['injected_apk'])) {
        throw new Exception('文件不存在,可能已被清理,请删除任务重新注入');
    }

    // 查询应用名称
    $stmt = $pdo->prepare("SELECT name FROM cainiao_apk WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $task['apk_id']]);
    $apk = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$apk || empty($apk['name'])) {
        throw new Exception('应用文件名为空,请先填好文件名');
    }

    $name = $apk['name'] . '.apk';

    // 检查蓝奏登录状态
    $data = Lanzou::checkLogin($user['lanzou_cookie']);
    if (!$data) {
        throw new Exception('蓝奏登录无效,请点击右上昵称登录蓝奏网盘账号');
    }

    $file_path = __DIR__ . '/../../release/' . $task['injected_apk'];
    if (!file_exists($file_path)) {
        throw new Exception('文件不存在，可能已被删除');
    }

    $maxSize = 100 * 1024 * 1024 - 100 * 1024; // 100MB - 100KB
    if (filesize($file_path) > $maxSize) {
        throw new Exception('仅能上传小于100MB的文件');
    }
    $originalStatus = $task['status_info'];//保存原状态
    // 设置状态为“正在上传”
    $stmt = $pdo->prepare("UPDATE cainiao_inject_task SET status_info = '正在上传' WHERE id = :id");
    $stmt->execute([':id' => $file_id]);

    // 执行上传
    try {
        $upload = Lanzou::upload($file_path, $name, $user['lanzou_cookie'], $user['lanzou_uid'], $folderId);
        $result = $upload['data']['info'];
    } catch (Exception $e) {
        // 上传异常时恢复状态
        $stmt = $pdo->prepare("UPDATE cainiao_inject_task SET status_info = :msg WHERE id = :id");
        $stmt->execute([
            //':msg' => '上传失败：' . $e->getMessage(),
            ':msg' => $originalStatus,//还原状态
            ':id' => $file_id
        ]);
        throw $e;
    }

    // 上传成功，更新状态为结果信息
    $stmt = $pdo->prepare("UPDATE cainiao_inject_task SET status_info = :msg WHERE id = :id");
    $stmt->execute([
        //':msg' => $result,//上传结果
        ':msg' => $originalStatus,//还原状态
        //':msg' => $result.$upload['data']['text'][0]['is_newd'].'/'.$upload['data']['text'][0]['f_id'],//上传结果带链接的
        ':id' => $file_id
    ]);

    return $upload['data'];
}















