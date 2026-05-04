<?php

//供APP调用的方法
function getAllUploadRules(PDO $pdo, array $input): array
{
    $user = Auth::check($pdo);
    // AES Key
    $AES_KEY = 'yunzhuru12345678'; // 必须与 Java 的 AES_KEY 保持一致

    // AES 加密函数
    $encrypt = function ($value) use ($AES_KEY) {
        $data = openssl_encrypt(
            $value,
            'AES-128-ECB',          // 模式：AES/ECB/PKCS5Padding
            $AES_KEY,
            OPENSSL_RAW_DATA        // 原始二进制数据
        );
        return base64_encode($data); // Base64 输出
    };

    // 查询 detection=0 的上传检测规则
    $sql = "SELECT id, message, type FROM cainiao_rules WHERE detection = 0 ORDER BY id DESC";
    $stmt = $pdo->query($sql);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rules) {
        return [];
    }

    // 获取所有规则 ID
    $ruleIds = array_column($rules, 'id');
    $inPlaceholder = implode(',', array_fill(0, count($ruleIds), '?'));

    // 查询关键字
    $keywordSql = "SELECT rule_id, keyword FROM cainiao_rule_keywords WHERE rule_id IN ($inPlaceholder)";
    $keywordStmt = $pdo->prepare($keywordSql);
    $keywordStmt->execute($ruleIds);
    $keywords = $keywordStmt->fetchAll(PDO::FETCH_ASSOC);

    // 按 rule_id 分组关键字
    $keywordMap = [];
    foreach ($keywords as $kw) {
        $keywordMap[$kw['rule_id']][] = $kw['keyword'];
    }

    // 构建最终加密返回
    $result = [];
    foreach ($rules as $rule) {
        $encMessage = $encrypt($rule['type'] ?? '');
        $encKeywords = [];
        foreach ($keywordMap[$rule['id']] ?? [] as $kw) {
            $encKeywords[] = $encrypt($kw);
        }

        $result[] = [
            'message'  => $encMessage,
            'keywords' => $encKeywords
        ];
    }

    return $result;
}

//批量导入规则
function addRulesBatch(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');
    if (!$isAdmin) {
        throw new Exception('无权限');
    }
    $data = trim($input['data'] ?? '');
    if ($data === '') {
        throw new Exception('规则参数不能为空');
    }

    // 按换行分割每条规则
    $lines = preg_split('/\r\n|\r|\n/', $data);
    if (empty($lines)) {
        throw new Exception('未检测到有效规则');
    }

    $successRules = 0;
    $successKeywords = 0;
    $failRules = 0;
    $failKeywords = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        try {
            // 按 | 分割格式：加固名称|检测类型|返回信息|关键字1,关键字2
            $parts = explode('|', $line);
            if (count($parts) !== 4) {
                $failRules++;
                continue;
            }

            list($type, $detection, $message, $keywordsStr) = $parts;
            $type = trim($type);
            $detection = trim($detection);
            $message = trim($message);

            // 检查检测类型合法性
            if (!in_array($detection, ['0', '1'], true)) {
                $failRules++;
                continue;
            }
            $detection = (int)$detection;

            // 检查加固名称、返回信息
            if ($type === '' || $message === '') {
                $failRules++;
                continue;
            }

            // 检查关键字
            $keywords = array_filter(array_map('trim', explode(',', $keywordsStr)));
            $keywords = array_unique($keywords);
            if (empty($keywords)) {
                $failRules++;
                continue;
            }

            // 检查是否重复规则
            $stmt = $pdo->prepare("SELECT id FROM cainiao_rules WHERE type = :type AND detection = :detection");
            $stmt->execute([
                ':type' => $type,
                ':detection' => $detection
            ]);
            $exists = $stmt->fetchColumn();
            if ($exists) {
                // 已存在，跳过
                $failRules++;
                continue;
            }

            // 插入规则
            $stmtInsert = $pdo->prepare("INSERT INTO cainiao_rules (type, detection, message) VALUES (:type, :detection, :message)");
            $stmtInsert->execute([
                ':type' => $type,
                ':detection' => $detection,
                ':message' => $message
            ]);
            $ruleId = (int)$pdo->lastInsertId();
            $successRules++;

            // 插入关键字
            $stmtKeyword = $pdo->prepare("INSERT INTO cainiao_rule_keywords (rule_id, keyword) VALUES (:rule_id, :keyword)");
            foreach ($keywords as $keyword) {
                if ($keyword === '') continue;
                try {
                    $stmtKeyword->execute([
                        ':rule_id' => $ruleId,
                        ':keyword' => $keyword
                    ]);
                    $successKeywords++;
                } catch (Exception $e) {
                    // 插入关键字失败
                    $failKeywords++;
                }
            }

        } catch (Exception $e) {
            // 当前规则整体失败
            $failRules++;
        }
    }

    return [
        'message' => '批量导入完成',
        'success_rules' => $successRules,
        'success_keywords' => $successKeywords,
        'fail_rules' => $failRules,
        'fail_keywords' => $failKeywords
    ];
}



function listRules(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');
    if (!$isAdmin) {
        throw new Exception('无权限');
    }
    // 分页参数
    $page  = max(1, (int)($input['page'] ?? 1));
    $limit = max(1, (int)($input['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    // 查询条件
    $type = trim($input['type'] ?? '');
    $keyword = trim($input['keyword'] ?? '');
    $detection = $input['detection'] ?? ''; // 检测类型：0/1，空则不筛选

    $where = 'WHERE 1';
    $params = [];

    // 按加固类型名称模糊查
    if ($type !== '') {
        $where .= ' AND type LIKE :type';
        $params[':type'] = '%' . $type . '%';
    }

    // 按关键字模糊查（从关键字表命中 rule_id）
    if ($keyword !== '') {
        $where .= ' AND id IN (SELECT rule_id FROM cainiao_rule_keywords WHERE keyword LIKE :keyword)';
        $params[':keyword'] = '%' . $keyword . '%';
    }

    // 按检测类型精确查（0/1）
    if ($detection !== '' && $detection !== null) {
        $where .= ' AND detection = :detection';
        $params[':detection'] = (int)$detection;
    }

    // 查询列表
    $sql = "SELECT * FROM cainiao_rules $where ORDER BY id DESC LIMIT $offset, $limit";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 统计总数
    $countSql = "SELECT COUNT(*) FROM cainiao_rules $where";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    return [
        'list' => $rows,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => (int)ceil($total / $limit)
    ];
}



function saveRule(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');
    if (!$isAdmin) {
        throw new Exception('无权限');
    }
    $type = trim($input['type'] ?? '');
    $detection = (int)($input['detection'] ?? 0);
    $message = trim($input['message'] ?? '');

    if ($type === '') {
        throw new Exception('加固名称不能为空');
    }

    // 检查是否重复
    if (!empty($input['id'])) {
        // 修改时排除当前 ID
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_rules WHERE type = :type AND detection = :detection AND id != :id");
        $stmt->execute([
            ':type' => $type,
            ':detection' => $detection,
            ':id' => (int)$input['id']
        ]);
    } else {
        // 新增时直接检查
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cainiao_rules WHERE type = :type AND detection = :detection");
        $stmt->execute([
            ':type' => $type,
            ':detection' => $detection
        ]);
    }

    if ($stmt->fetchColumn() > 0) {
        throw new Exception('相同检测类型下已存在该加固名称，请勿重复');
    }

    if (!empty($input['id'])) {
        // 修改
        $stmt = $pdo->prepare("UPDATE cainiao_rules SET type = :type, detection = :detection, message = :message WHERE id = :id");
        $stmt->execute([
            ':type' => $type,
            ':detection' => $detection,
            ':message' => $message,
            ':id' => (int)$input['id']
        ]);
        return ['message' => '修改成功'];
    } else {
        // 新增
        $stmt = $pdo->prepare("INSERT INTO cainiao_rules (type, detection, message) VALUES (:type, :detection, :message)");
        $stmt->execute([
            ':type' => $type,
            ':detection' => $detection,
            ':message' => $message
        ]);
        return ['message' => '添加成功'];
    }
}




function deleteRule(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');
    if (!$isAdmin) {
        throw new Exception('无权限');
    }
    if (empty($input['id']) || !is_numeric($input['id'])) {
        throw new Exception('缺少 ID');
    }
    $id = (int)$input['id'];

    $pdo->prepare("DELETE FROM cainiao_rule_keywords WHERE rule_id = :id")->execute([':id' => $id]);
    $pdo->prepare("DELETE FROM cainiao_rules WHERE id = :id")->execute([':id' => $id]);

    return ['message' => '删除成功'];
}


function listKeywords(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');
    if (!$isAdmin) {
        throw new Exception('无权限');
    }
    if (empty($input['rule_id']) || !is_numeric($input['rule_id'])) {
        throw new Exception('缺少规则ID');
    }

    $stmt = $pdo->prepare("SELECT * FROM cainiao_rule_keywords WHERE rule_id = :rid ORDER BY id DESC");
    $stmt->execute([':rid' => (int)$input['rule_id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function addKeyword(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');
    if (!$isAdmin) {
        throw new Exception('无权限');
    }
    $rule_id = (int)($input['rule_id'] ?? 0);
    $keyword = trim($input['keyword'] ?? '');

    if ($rule_id <= 0 || $keyword === '') {
        throw new Exception('规则ID或关键字不能为空');
    }

    // 检查是否重复关键字
    $check = $pdo->prepare("SELECT COUNT(*) FROM cainiao_rule_keywords WHERE rule_id = :rid AND keyword = :kw");
    $check->execute([
        ':rid' => $rule_id,
        ':kw' => $keyword
    ]);

    if ($check->fetchColumn() > 0) {
        throw new Exception('该关键字已存在，不能重复');
    }

    // 插入数据
    $stmt = $pdo->prepare("INSERT INTO cainiao_rule_keywords (rule_id, keyword) VALUES (:rid, :kw)");
    $stmt->execute([':rid' => $rule_id, ':kw' => $keyword]);

    return ['message' => '添加成功'];
}




function deleteKeyword(PDO $pdo, array $input)
{
    $user = Auth::check($pdo);
    $userId  = (int)$user['id'];
    $isAdmin = (($user['role'] ?? '') === 'admin');
    if (!$isAdmin) {
        throw new Exception('无权限');
    }
    if (empty($input['id']) || !is_numeric($input['id'])) {
        throw new Exception('缺少关键字ID');
    }

    $stmt = $pdo->prepare("DELETE FROM cainiao_rule_keywords WHERE id = :id");
    $stmt->execute([':id' => (int)$input['id']]);

    return ['message' => '删除成功'];
}
