<?php

//生成受限内容的html代码
function buildImageOnlyHtml($imageUrl)
{
    // 防止 XSS，确保是安全字符串
    $safeUrl = htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <!-- 本代码需要 VIP 用户才可以复制使用 -->
    <!-- 本代码需要 VIP 用户才可以复制使用 -->
    <!-- 本代码需要 VIP 用户才可以复制使用 -->
    <!-- 您当前复制的是受限制的代码内容 -->
    <!-- 您当前复制的是受限制的代码内容 -->
    <!-- 您当前复制的是受限制的代码内容 -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>内容受限</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
    </style>
</head>
<body>
    <!-- 本代码需要 VIP 用户才可以复制使用 -->
    <!-- 您当前复制的是受限制的代码内容 -->
    
    <img src="{$safeUrl}">
</body>
</html>
HTML;
}



// 新增查询方法：只返回启用的数据，前台查询版本
function getEnabledTemplateHtmlList(PDO $pdo, array $input)
{
    // 验证用户是否已登录（不要求管理员权限）
    $user = Auth::check($pdo);

    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $where = 'enable = 1';
    $params = [];

    // 标题查询
    if (!empty($input['title'])) {
        $where .= ' AND title LIKE :title';
        $params[':title'] = '%' . $input['title'] . '%';
    }

    // HTML代码查询
    if (!empty($input['html'])) {
        $where .= ' AND html LIKE :html';
        $params[':html'] = '%' . $input['html'] . '%';
    }

    $table = 'cainiao_template_html';

    // 统计总数
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // 查询数据
    $sql = "SELECT id, title, remark, enable, html, created_at, imageUrl 
            FROM `$table`
            WHERE $where
            ORDER BY id DESC
            LIMIT $offset, $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $isVip = ($user['isVip'] === true);
    $isVip = true;
    foreach ($list as &$row) {
        if (!$isVip) {
            // 非 VIP：不返回原始 html
            $row['html'] = buildImageOnlyHtml($row['imageUrl']);
        }
    }
    unset($row);
    
    return [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ];
}



// 查询模板列表，支持分页与条件查询,后台查询版本
function getTemplateHtmlList(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    $page = max(1, intval($input['page'] ?? 1));
    $limit = max(1, intval($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $where = '1=1';
    $params = [];

    if (!empty($input['title'])) {
        $where .= ' AND title LIKE :title';
        $params[':title'] = '%' . $input['title'] . '%';
    }
    if (!empty($input['remark'])) {
        $where .= ' AND remark LIKE :remark';
        $params[':remark'] = '%' . $input['remark'] . '%';
    }
    if (!empty($input['html'])) {
        $where .= ' AND html LIKE :html';
        $params[':html'] = '%' . $input['html'] . '%';
    }

    $table = 'cainiao_template_html';

    // 查询总数
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // 查询列表
    $sql = "SELECT id, title, remark, enable, html, created_at, imageUrl 
            FROM `$table` 
            WHERE $where 
            ORDER BY id DESC 
            LIMIT $offset, $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ];
}

// 新增模板
function addTemplateHtml(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    if (empty($input['title']) || empty($input['html'])) {
        throw new Exception('标题和HTML代码不能为空');
    }

    $table = 'cainiao_template_html';
    $stmt = $pdo->prepare("INSERT INTO `$table` (title, remark, enable, html, created_at, imageUrl)
                           VALUES (:title, :remark, :enable, :html, :created_at, :imageUrl)");
    $stmt->execute([
        ':title' => $input['title'],
        ':remark' => $input['remark'] ?? null,
        ':enable' => intval($input['enable'] ?? 0),
        ':html' => $input['html'],
        ':created_at' => date('Y-m-d H:i:s'),
        ':imageUrl' => $input['imageUrl']
    ]);

    return ['msg' => '新增成功', 'id' => $pdo->lastInsertId()];
}

// 修改模板
function updateTemplateHtml(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    if (empty($input['id'])) {
        throw new Exception('缺少ID');
    }

    $table = 'cainiao_template_html';
    $stmt = $pdo->prepare("UPDATE `$table` 
                           SET title = :title, remark = :remark, enable = :enable, html = :html, imageUrl = :imageUrl
                           WHERE id = :id");
    $stmt->execute([
        ':title' => $input['title'] ?? '',
        ':remark' => $input['remark'] ?? null,
        ':enable' => intval($input['enable'] ?? 0),
        ':html' => $input['html'] ?? '',
        ':imageUrl' => $input['imageUrl'],
        ':id' => intval($input['id']),
        
    ]);

    return ['msg' => '修改成功'];
}

// 删除模板
function deleteTemplateHtml(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    if ($user['role'] !== 'admin') {
        throw new Exception('无权访问');
    }

    if (empty($input['id'])) {
        throw new Exception('缺少ID');
    }

    $table = 'cainiao_template_html';
    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id = :id");
    $stmt->execute([':id' => intval($input['id'])]);

    return ['msg' => '删除成功'];
}
