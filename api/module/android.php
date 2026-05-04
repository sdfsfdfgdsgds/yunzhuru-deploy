<?php
// android.php

/**
 * 获取更新历史日志（分页）
 * 每页固定 50 条
 * 排序：update_time DESC
 * 返回字段：
 *   versionname
 *   versioncode
 *   notice
 *   enabled(布尔)
 *   update_time
 */
function android_history(PDO $pdo, array $input) {

    $table = 'cainiao_version';

    $page = (int)($input['page'] ?? 1);
    if ($page < 1) $page = 1;

    $pageSize = 50;
    $offset = ($page - 1) * $pageSize;

    try {

        // 获取总数
        $total = (int)$pdo
            ->query("SELECT COUNT(*) FROM `$table`")
            ->fetchColumn();

        // 查询分页数据
        $sql = "SELECT 
                    versionname,
                    versioncode,
                    newnotice,
                    enabled,
                    update_time
                FROM `$table`
                ORDER BY update_time DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // enabled 转布尔
        foreach ($rows as &$row) {
            $row['enabled'] = ((int)$row['enabled'] === 1);
        }

        $pages = (int)ceil($total / $pageSize);

        return [
            'msg' => '查询成功',
            'data' => [
                'list' => $rows,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'pages' => $pages
                ]
            ]
        ];

    } catch (Exception $e) {
        return ['error' => '查询失败：' . $e->getMessage()];
    }
}



function android_check_version(PDO $pdo, array $input) {
    $table = 'cainiao_version';

    $versionname = trim((string)($input['versionname'] ?? ''));
    $versioncode = trim((string)($input['versioncode'] ?? ''));

    if ($versionname === '' || $versioncode === '') {
        return ['error' => '缺少参数：versionname 或 versioncode'];
    }

    try {
        // 查询当前版本信息
        $sql = "SELECT id, versionname, versioncode, download, newnotice, enabled, notice, update_time, imageUrl ,up_imageUrl ,up_title ,up_desc ,up_actionType ,up_actionArg
                FROM `$table` 
                WHERE versionname = :versionname AND versioncode = :versioncode 
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':versionname', $versionname);
        $stmt->bindValue(':versioncode', $versioncode);
        $stmt->execute();
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($current) {
            $current['enabled'] = ((int)$current['enabled'] === 1);
        }

        // 查询最新版本信息（按 update_time 降序，第一个即为最新版本）
        // 仅查询已启用(enabled=1)的最新版本
        $latestSql = "SELECT id, versionname, versioncode, download, newnotice, enabled, notice, update_time, imageUrl 
                      FROM `$table` 
                      WHERE enabled = 1
                      ORDER BY update_time DESC 
                      LIMIT 1";
        $latestStmt = $pdo->query($latestSql);
        $latest = $latestStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($latest) {
            $latest['enabled'] = ((int)$latest['enabled'] === 1);
        }

        return [
            'msg' => '查询成功',
            'data' => [
                'current' => $current ?: null,
                'latest' => $latest ?: null
            ]
        ];
    } catch (Exception $e) {
        return ['error' => '查询失败：' . $e->getMessage()];
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

/**
 * 新增版本
 * 必填字段：versionname, versioncode, download
 * 可选字段：newnotice, enabled(true/false), notice
 * @param PDO $pdo
 * @param array $input
 * @return array
 */
function android_add(PDO $pdo, array $input) {
    // 日志：开始新增
    // 校验管理员
    try {
        _ensureAdmin($pdo);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }

    $table = 'cainiao_version';

    // 参数校验
    $versionname = trim((string)($input['versionname'] ?? ''));
    $versioncode = trim((string)($input['versioncode'] ?? ''));
    $download    = trim((string)($input['download'] ?? ''));
    $imageUrl    = trim((string)($input['imageUrl'] ?? ''));//导航背景图
    $newnotice   = array_key_exists('newnotice', $input) ? (string)$input['newnotice'] : null;
    $notice      = array_key_exists('notice', $input) ? (string)$input['notice'] : null;
    $enabledRaw  = $input['enabled'] ?? 0;
    $enabled     = _enabledBoolToInt($enabledRaw);

    if ($versionname === '' || $versioncode === '' || $download === '') {
        return ['error' => '缺少必填字段：versionname/versioncode/download'];
    }

    try {
        $pdo->beginTransaction();

        $sql = "INSERT INTO `$table`
                (versionname, versioncode, download, newnotice, enabled, notice, update_time, imageUrl)
                VALUES (:versionname, :versioncode, :download, :newnotice, :enabled, :notice, NOW(), :imageUrl)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':versionname', $versionname);
        $stmt->bindValue(':versioncode', $versioncode);
        $stmt->bindValue(':download', $download);
        $stmt->bindValue(':newnotice', $newnotice);
        $stmt->bindValue(':enabled', $enabled, PDO::PARAM_INT);
        $stmt->bindValue(':notice', $notice);
        $stmt->bindValue(':imageUrl', $imageUrl);
        $stmt->execute();

        $id = (int)$pdo->lastInsertId();

        // 查询新记录返回（含 enabled 布尔转换）
        $q = $pdo->prepare("SELECT * FROM `$table` WHERE id=:id");
        $q->bindValue(':id', $id, PDO::PARAM_INT);
        $q->execute();
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
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
 * 分页查询版本列表
 * 入参：page(>=1)、page_size(1-200)
 * 排序：update_time DESC
 * 返回：列表中 enabled 转为布尔
 * @param PDO $pdo
 * @param array $input
 * @return array
 */
function android_list(PDO $pdo, array $input) {
    // 日志：开始查询
    // 校验管理员
    try {
        _ensureAdmin($pdo);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }

    $table = 'cainiao_version';

    $page = (int)($input['page'] ?? 1);
    $page = $page >= 1 ? $page : 1;

    $pageSize = (int)($input['page_size'] ?? 10);
    if ($pageSize < 1) $pageSize = 10;
    if ($pageSize > 200) $pageSize = 200;

    $offset = ($page - 1) * $pageSize;

    try {
        // 统计总数
        $total = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();

        // 查询分页数据
        $sql = "SELECT *
                FROM `$table`
                ORDER BY update_time DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['enabled'] = _enabledIntToBool($r['enabled']);
        }

        $pages = (int)ceil($total / ($pageSize ?: 1));

        return [
            'list' => $rows,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'pages' => $pages
            ]
        ];
    } catch (Exception $e) {
        return ['error' => '查询失败：' . $e->getMessage()];
    }
}

/**
 * 修改版本信息
 * 说明：支持除 id、update_time 外的字段：versionname, versioncode, download, newnotice, enabled(true/false), notice
 * 入参：id 必填；其它字段可选
 * @param PDO $pdo
 * @param array $input
 * @return array
 */
function android_update(PDO $pdo, array $input) {
    // 日志：开始修改
    // 校验管理员
    try {
        _ensureAdmin($pdo);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }

    $table = 'cainiao_version';

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        return ['error' => '参数错误：缺少有效的 id'];
    }

    // 允许更新的字段白名单
    //$allowed = ['versionname', 'versioncode', 'download', 'newnotice', 'enabled', 'notice', 'imageUrl'];
    $allowed = ['versionname', 'versioncode', 'download', 'newnotice', 'enabled', 'notice', 'imageUrl', 'up_imageUrl', 'up_title', 'up_desc', 'up_actionType', 'up_actionArg'];


    // 收集要更新的字段
    $fields = [];
    $params = [':id' => $id];

    foreach ($allowed as $key) {
        if (array_key_exists($key, $input)) {
            if ($key === 'enabled') {
                $val = _enabledBoolToInt($input[$key]);
                $fields[] = "enabled = :enabled";
                $params[':enabled'] = $val;
            } else {
                $fields[] = "$key = :$key";
                $params[":$key"] = (string)$input[$key];
            }
        }
    }

    if (empty($fields)) {
        return ['error' => '没有可更新的字段'];
    }

    try {
        $pdo->beginTransaction();

        // 确认记录存在
        $existsStmt = $pdo->prepare("SELECT id FROM `$table` WHERE id=:id");
        $existsStmt->bindValue(':id', $id, PDO::PARAM_INT);
        $existsStmt->execute();
        if (!$existsStmt->fetchColumn()) {
            $pdo->rollBack();
            return ['error' => '记录不存在'];
        }

        $sql = "UPDATE `$table` SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            if ($k === ':enabled') {
                $stmt->bindValue($k, $v, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($k, $v);
            }
        }
        $stmt->execute();

        // 返回更新后的数据（enabled 转布尔）
        $q = $pdo->prepare("SELECT * FROM `$table` WHERE id=:id");
        $q->bindValue(':id', $id, PDO::PARAM_INT);
        $q->execute();
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['enabled'] = _enabledIntToBool($row['enabled']);
        }

        $pdo->commit();
        return ['msg' => '修改成功', 'data' => $row];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => '修改失败：' . $e->getMessage()];
    }
}

/**
 * 删除版本
 * 说明：不允许删除 enabled=1 的记录
 * 入参：id 必填
 * @param PDO $pdo
 * @param array $input
 * @return array
 */
function android_delete(PDO $pdo, array $input) {
    // 日志：开始删除
    // 校验管理员
    try {
        _ensureAdmin($pdo);
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }

    $table = 'cainiao_version';

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        return ['error' => '参数错误：缺少有效的 id'];
    }

    try {
        $pdo->beginTransaction();

        // 校验 enabled 状态
        $q = $pdo->prepare("SELECT enabled FROM `$table` WHERE id=:id");
        $q->bindValue(':id', $id, PDO::PARAM_INT);
        $q->execute();
        $enabled = $q->fetchColumn();

        if ($enabled === false) {
            $pdo->rollBack();
            return ['error' => '记录不存在'];
        }

        if ((int)$enabled === 1) {
            $pdo->rollBack();
            return ['error' => '禁止删除：该记录已启用(enabled=1)' ];
        }

        // 执行删除
        $del = $pdo->prepare("DELETE FROM `$table` WHERE id=:id");
        $del->bindValue(':id', $id, PDO::PARAM_INT);
        $del->execute();

        $pdo->commit();
        return ['msg' => '删除成功', 'id' => $id];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => '删除失败：' . $e->getMessage()];
    }
}
