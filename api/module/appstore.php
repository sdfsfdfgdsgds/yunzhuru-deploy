<?php

/**
 * 应用查询用户应用列表
 * 规则：
 * 1. 只返回 enabled=1
 * 2. 非VIP用户不能看到 vip=1
 * 3. 支持按名称模糊搜索
 * 4. 按 update_time DESC 排序
 */
function getList(PDO $pdo, array $input) {

    $user = Auth::check($pdo);
    $isVip = !empty($user['isVip']) && $user['isVip'] == true;

    $table = 'cainiao_appstore';

    $page  = max(1, (int)($input['page'] ?? 1));
    $limit = (int)($input['limit'] ?? 10);
    if ($limit < 1) $limit = 10;
    if ($limit > 50) $limit = 50;

    $offset = ($page - 1) * $limit;

    $keyword = trim((string)($input['keyword'] ?? ''));

    $where  = [];
    $params = [];

    // 仅已启用
    $where[] = "enabled = 1";

    // 名称模糊搜索
    if ($keyword !== '') {
        $where[] = "name LIKE :keyword";
        $params[':keyword'] = "%{$keyword}%";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    try {

        // 统计总数
        $countSql = "SELECT COUNT(*) FROM `$table` $whereSql";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // 查询数据
        $sql = "SELECT id, name, subtitle, version,
                       download1_text, download1_url,
                       download2_text, download2_url,
                       logoUrl, size, vip, update_time
                FROM `$table`
                $whereSql
                ORDER BY update_time DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($list as &$row) {

            $isItemVip = ((int)$row['vip'] === 1);
            $row['vip'] = $isItemVip;

            // 非VIP用户访问VIP资源 → 清空下载信息
            if ($isItemVip && !$isVip) {
                $row['download1_text'] = '无权限';
                $row['download1_url']  = '';
                $row['download2_text'] = '';
                $row['download2_url']  = '';
            }
        }

        $pages = $limit > 0 ? (int)ceil($total / $limit) : 1;

        return [
            'list'  => $list,
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => $pages
        ];

    } catch (Exception $e) {
        return ['error' => '查询失败：' . $e->getMessage()];
    }
}

/**
 * 新增应用
 */
function appstore_add(PDO $pdo, array $input) {

    try {
        _ensureAdmin($pdo);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }

    $table = 'cainiao_appstore';

    $name           = trim((string)($input['name'] ?? ''));
    $subtitle       = trim((string)($input['subtitle'] ?? ''));
    $version        = trim((string)($input['version'] ?? ''));
    $download1_text = trim((string)($input['download1_text'] ?? ''));
    $download1_url  = trim((string)($input['download1_url'] ?? ''));
    $download2_text = trim((string)($input['download2_text'] ?? ''));
    $download2_url  = trim((string)($input['download2_url'] ?? ''));
    $logoUrl        = trim((string)($input['logoUrl'] ?? ''));
    $size           = trim((string)($input['size'] ?? ''));
    $vip            = _enabledBoolToInt($input['vip'] ?? 0);
    $enabled        = _enabledBoolToInt($input['enabled'] ?? 0);

    if ($name === '' || $download1_text === '' || $download1_url === '' || $size === '') {
        return ['error' => '缺少必填字段'];
    }

    try {
        $pdo->beginTransaction();

        $sql = "INSERT INTO `$table`
                (name, subtitle, version, download1_text, download1_url,
                 download2_text, download2_url, logoUrl, size, vip, enabled, update_time)
                VALUES
                (:name, :subtitle, :version, :download1_text, :download1_url,
                 :download2_text, :download2_url, :logoUrl, :size, :vip, :enabled, NOW())";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':subtitle' => $subtitle,
            ':version' => $version,
            ':download1_text' => $download1_text,
            ':download1_url' => $download1_url,
            ':download2_text' => $download2_text,
            ':download2_url' => $download2_url,
            ':logoUrl' => $logoUrl,
            ':size' => $size,
            ':vip' => $vip,
            ':enabled' => $enabled
        ]);

        $id = (int)$pdo->lastInsertId();

        $row = $pdo->query("SELECT * FROM `$table` WHERE id = {$id}")
                   ->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $row['vip'] = _enabledIntToBool($row['vip']);
            $row['enabled'] = _enabledIntToBool($row['enabled']);
        }

        $pdo->commit();
        return ['msg' => '新增成功', 'data' => $row];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => '新增失败：' . $e->getMessage()];
    }
}


/**
 * 分页查询
 */
function appstore_list(PDO $pdo, array $input) {

    try {
        _ensureAdmin($pdo);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }

    $table = 'cainiao_appstore';

    $page = max(1, (int)($input['page'] ?? 1));
    $pageSize = (int)($input['page_size'] ?? 20);
    if ($pageSize < 1) $pageSize = 20;
    if ($pageSize > 200) $pageSize = 200;

    $offset = ($page - 1) * $pageSize;

    $keyword = trim((string)($input['keyword'] ?? ''));
    $downloadKeyword = trim((string)($input['download_keyword'] ?? ''));
    $startDate = trim((string)($input['start_date'] ?? ''));
    $endDate   = trim((string)($input['end_date'] ?? ''));

    $where = [];
    $params = [];

    // 名称模糊搜索
    if ($keyword !== '') {
        $where[] = "name LIKE :keyword";
        $params[':keyword'] = "%{$keyword}%";
    }

    // 下载地址模糊
    if ($downloadKeyword !== '') {
        $where[] = "(download1_url LIKE :dkw OR download2_url LIKE :dkw)";
        $params[':dkw'] = "%{$downloadKeyword}%";
    }

    // 创建时间区间
    if ($startDate !== '' && $endDate !== '') {
        $where[] = "update_time BETWEEN :start AND :end";
        $params[':start'] = $startDate . " 00:00:00";
        $params[':end']   = $endDate . " 23:59:59";
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

    try {

        // 统计总数
        $countSql = "SELECT COUNT(*) FROM `$table` $whereSql";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // 查询数据
        $sql = "SELECT *
                FROM `$table`
                $whereSql
                ORDER BY update_time DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['vip'] = _enabledIntToBool($r['vip']);
            $r['enabled'] = _enabledIntToBool($r['enabled']);
        }

        return [
            'list' => $rows,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'pages' => (int)ceil($total / $pageSize)
            ]
        ];

    } catch (Exception $e) {
        return ['error' => '查询失败：' . $e->getMessage()];
    }
}


/**
 * 修改
 */
function appstore_update(PDO $pdo, array $input) {

    try {
        _ensureAdmin($pdo);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }

    $table = 'cainiao_appstore';

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) return ['error' => '缺少有效的 id'];

    $allowed = [
        'name','subtitle','version',
        'download1_text','download1_url',
        'download2_text','download2_url',
        'logoUrl','size','vip','enabled'
    ];

    $fields = [];
    $params = [':id' => $id];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $input)) {
            if ($key === 'vip' || $key === 'enabled') {
                $fields[] = "$key = :$key";
                $params[":$key"] = _enabledBoolToInt($input[$key]);
            } else {
                $fields[] = "$key = :$key";
                $params[":$key"] = (string)$input[$key];
            }
        }
    }

    if (empty($fields)) return ['error' => '没有可更新字段'];

    try {

        $pdo->beginTransaction();

        $exists = $pdo->prepare("SELECT id FROM `$table` WHERE id=:id");
        $exists->execute([':id'=>$id]);

        if (!$exists->fetchColumn()) {
            $pdo->rollBack();
            return ['error'=>'记录不存在'];
        }

        $sql = "UPDATE `$table` SET " . implode(',', $fields) . " WHERE id=:id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $row = $pdo->query("SELECT * FROM `$table` WHERE id={$id}")
                   ->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $row['vip'] = _enabledIntToBool($row['vip']);
            $row['enabled'] = _enabledIntToBool($row['enabled']);
        }

        $pdo->commit();
        return ['msg'=>'修改成功','data'=>$row];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error'=>'修改失败：'.$e->getMessage()];
    }
}


/**
 * 删除
 */
function appstore_delete(PDO $pdo, array $input) {

    try {
        _ensureAdmin($pdo);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }

    $table = 'cainiao_appstore';

    $ids = [];

    if (!empty($input['ids']) && is_array($input['ids'])) {
        $ids = array_map('intval', $input['ids']);
    } elseif (!empty($input['id'])) {
        $ids[] = (int)$input['id'];
    }

    $ids = array_filter($ids, function ($v) {
        return $v > 0;
    });

    if (empty($ids)) {
        return ['error' => '缺少有效的 id 或 ids'];
    }

    try {

        $pdo->beginTransaction();

        $deleted = 0;
        $skipped = 0;

        foreach ($ids as $id) {

            // 查询是否启用
            $stmt = $pdo->prepare("SELECT enabled FROM `$table` WHERE id=:id");
            $stmt->execute([':id' => $id]);
            $enabled = $stmt->fetchColumn();

            if ($enabled === false) {
                continue;
            }

            if ((int)$enabled === 1) {
                $skipped++;
                continue; // 跳过已启用
            }

            $del = $pdo->prepare("DELETE FROM `$table` WHERE id=:id");
            $del->execute([':id' => $id]);
            $deleted++;
        }

        $pdo->commit();

        return [
            'msg' => '删除完成',
            'deleted' => $deleted,
            'skipped_enabled' => $skipped
        ];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => '删除失败：' . $e->getMessage()];
    }
}


/**
 * 将前端传来的 enabled 转为 0/1
 * @param mixed $val
 * @return int 0或1
 */
function _enabledBoolToInt($val): int {
    // 接受布尔或字符串"true"/"false"/"1"/"0"
    if (is_bool($val)) return $val ? 1 : 0;
    $normalized = strtolower(trim((string)$val));
    return in_array($normalized, ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
}

/**
 * 将数据库中的 enabled(0/1) 转为 布尔
 * @param mixed $val
 * @return bool
 */
function _enabledIntToBool($val): bool {
    return ((int)$val) === 1;
}

/**
 * 权限校验：必须为管理员
 * @throws Exception
 */
function _ensureAdmin(PDO $pdo): array {
    // 用户校验，非管理员抛出异常
    $user = Auth::check($pdo);
    $isAdmin = (($user['role'] ?? '') === 'admin');
    if (!$isAdmin) {
        throw new Exception('无权限：仅管理员可操作');
    }
    return $user;
}