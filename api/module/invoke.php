<?php
function getinvoke_user(PDO $pdo, array $input)
{
    // 校验登录（保持你原有逻辑）
    $user = Auth::check($pdo);

    // type: 2 = invoke 特征（methodName 有值）
    //       1 = application 入口（methodName 为空）
    //       0 = 全量特征
    $type = intval($input['type'] ?? 0);

    if ($type == 1) {
        // methodName 为 null 或 空字符串
        $sql = "
            SELECT className, methodName, message, `exit`
            FROM cainiao_invoke
            WHERE methodName IS NULL OR methodName = ''
        ";
        $stmt = $pdo->prepare($sql);
    } else if ($type == 2)  {
        // 默认：methodName 不为 null 且不为空
        $sql = "
            SELECT className, methodName, message, `exit`
            FROM cainiao_invoke
            WHERE methodName IS NOT NULL AND methodName != ''
        ";
        $stmt = $pdo->prepare($sql);
    }else{
        $sql = "
            SELECT className, methodName, message, `exit`
            FROM cainiao_invoke
        ";
        $stmt = $pdo->prepare($sql);
    }

    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'list' => $list
    ];
}


function getinvoke(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限调用此接口');
    }

    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $sql = "SELECT * FROM cainiao_invoke ORDER BY upload_time DESC LIMIT :offset, :limit";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = (int)$pdo->query("SELECT COUNT(*) FROM cainiao_invoke")->fetchColumn();

    return [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ];
}

function addinvoke(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限调用此接口');
    }

    $className = trim($input['className'] ?? '');
    $methodName = trim($input['methodName'] ?? null);
    $remark = trim($input['remark'] ?? '');
    $message = trim($input['message'] ?? '');

    if ($className === '') {
        throw new Exception('类名不能为空');
    }
    $className = str_replace('/', '.', $className);
    
    if ($message === '') {
        throw new Exception('特征名称不能为空');
    }

    $stmt = $pdo->prepare("INSERT INTO cainiao_invoke (className, methodName, remark, message, upload_time)
        VALUES (:className, :methodName, :remark, :message, NOW())");
    $stmt->execute([
        ':className' => $className,
        ':methodName' => $methodName,
        ':remark' => $remark,
        ':message' => $message
    ]);

    return ['message' => '添加成功'];
}

function deleteinvoke(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限调用此接口');
    }

    $id = intval($input['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('无效的ID');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_invoke WHERE id = :id");
    $stmt->execute([':id' => $id]);

    return ['message' => '删除成功'];
}


function updateinvoke(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限调用此接口');
    }

    $id = intval($input['id'] ?? 0);
    $className = trim($input['className'] ?? '');
    $methodName = trim($input['methodName'] ?? null);
    $remark = trim($input['remark'] ?? '');
    $message = trim($input['message'] ?? '');
    $exit = intval($input['exit'] ?? 0); // 1=开，0=关

    if ($id <= 0) {
        throw new Exception('无效的ID');
    }
    if ($className === '') {
        throw new Exception('类名不能为空');
    }

    // 只允许 0 或 1，防止脏数据
    if ($exit !== 0 && $exit !== 1) {
        throw new Exception('exit参数非法');
    }

    $className = str_replace('/', '.', $className);

    $stmt = $pdo->prepare("
        UPDATE cainiao_invoke 
        SET 
            className = :className,
            methodName = :methodName,
            remark = :remark,
            message = :message,
            `exit` = :exit
        WHERE id = :id
    ");

    $stmt->execute([
        ':className' => $className,
        ':methodName' => $methodName,
        ':remark' => $remark,
        ':message' => $message,
        ':exit' => $exit,
        ':id' => $id
    ]);

    return ['message' => '修改成功'];
}

