<?php

function getblckagepkg(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限调用此接口');
    }

    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $sql = "SELECT * FROM cainiao_backage ORDER BY upload_time DESC LIMIT :offset, :limit";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = (int)$pdo->query("SELECT COUNT(*) FROM cainiao_backage")->fetchColumn();

    return [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ];
}

function addblckagepkg(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限调用此接口');
    }

    $package = trim($input['package'] ?? '');
    $remark = trim($input['remark'] ?? '');
    $message = trim($input['message'] ?? '禁止上传该应用');

    if ($package === '') {
        throw new Exception('包名不能为空');
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_backage WHERE package = :package");
    $stmt->execute([':package' => $package]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('该包名已存在');
    }

    $stmt = $pdo->prepare("INSERT INTO cainiao_backage (package, remark, message, upload_time)
        VALUES (:package, :remark, :message, NOW())");
    $stmt->execute([
        ':package' => $package,
        ':remark' => $remark,
        ':message' => $message
    ]);

    return ['message' => '添加成功'];
}

function deleteblckagepkg(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限调用此接口');
    }

    $id = intval($input['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('无效的ID');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_backage WHERE id = :id");
    $stmt->execute([':id' => $id]);

    return ['message' => '删除成功'];
}


function updateblckagepkg(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权限调用此接口');
    }

    $id = intval($input['id'] ?? 0);
    $remark = trim($input['remark'] ?? '');
    $message = trim($input['message'] ?? '');

    if ($id <= 0) {
        throw new Exception('无效的ID');
    }

    $stmt = $pdo->prepare("UPDATE cainiao_backage SET remark = :remark, message = :message WHERE id = :id");
    $stmt->execute([
        ':remark' => $remark,
        ':message' => $message,
        ':id' => $id
    ]);

    return ['message' => '修改成功'];
}

