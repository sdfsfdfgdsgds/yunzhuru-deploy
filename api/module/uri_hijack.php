<?php

require_once __DIR__ . '/../utils/ConfigAccess.php';

/**
 * 规范化 URL 劫持规则类型。
 *
 * `class` 是历史规则，按目标 Activity 类名匹配；其余类型按原始 URL
 * 匹配，与浏览器品牌无关。数据库只保存白名单中的值，避免壳端收到
 * 未知类型后产生不一致的兜底行为。
 */
function uriHijackNormalizeMatchType($value, bool $hasSource, bool $hasClass): string
{
    $type = strtolower(trim((string)$value));
    if ($type === '') {
        $type = $hasSource && !$hasClass ? 'domain' : 'class';
    }
    if (!in_array($type, ['class', 'exact', 'prefix', 'contains', 'domain'], true)) {
        throw new InvalidArgumentException('匹配类型只允许 class、exact、prefix、contains 或 domain');
    }
    return $type;
}

/** 统一校验并读取后台提交的 URL 劫持规则。 */
function uriHijackNormalizeInput(array $input): array
{
    $className = trim((string)($input['class_name'] ?? ''));
    $uriValue = trim((string)($input['uri_value'] ?? ''));
    $source = trim((string)($input['source_pattern'] ?? ''));
    $target = trim((string)($input['target_url'] ?? ''));
    $type = uriHijackNormalizeMatchType($input['match_type'] ?? '', $source !== '', $className !== '');

    if ($type === 'class') {
        if ($className === '' || $uriValue === '') {
            throw new InvalidArgumentException('类名规则必须填写类名和 URI 值');
        }
        if (mb_strlen($className, 'UTF-8') > 200 || mb_strlen($uriValue, 'UTF-8') > 300) {
            throw new InvalidArgumentException('类名或 URI 值超出长度限制');
        }
        // 旧客户端只认识 class_name/uri_value；结构化字段保留空值。
        $source = '';
        $target = '';
    } else {
        if ($source === '' || $target === '') {
            throw new InvalidArgumentException('通用 URL 规则必须填写来源匹配和目标 URL');
        }
        if (mb_strlen($source, 'UTF-8') > 1000 || mb_strlen($target, 'UTF-8') > 2048) {
            throw new InvalidArgumentException('来源匹配或目标 URL 超出长度限制');
        }
        if (preg_match('/\s/u', $source)) {
            throw new InvalidArgumentException('来源匹配不能包含空白字符');
        }
        $parts = parse_url($target);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new InvalidArgumentException('目标 URL 必须是完整的 http 或 https 地址');
        }
        // 通用规则不再依赖浏览器类名；class_name 为空，避免误触发旧匹配。
        $className = '';
        $uriValue = '';
    }

    $priority = $input['priority'] ?? 0;
    if ($priority === '' || $priority === null) {
        $priority = 0;
    }
    if (filter_var($priority, FILTER_VALIDATE_INT) === false || (int)$priority < -10000 || (int)$priority > 10000) {
        throw new InvalidArgumentException('优先级必须是 -10000 到 10000 的整数');
    }
    $enabled = $input['enabled'] ?? 1;
    $enabled = ($enabled === false || $enabled === 0 || $enabled === '0' || strtolower((string)$enabled) === 'false') ? 0 : 1;

    return [
        'class_name' => $className,
        'uri_value' => $uriValue,
        'match_type' => $type,
        'source_pattern' => $source,
        'target_url' => $target,
        'priority' => (int)$priority,
        'enabled' => $enabled,
        'remark' => trim((string)($input['remark'] ?? '')),
    ];
}

/**
 * 判断结构化字段是否已经完成迁移。
 *
 * 发布期间可能先更新代码再执行数据库迁移；旧类名规则仍可继续编辑，
 * 新 URL 规则则明确提示迁移缺失，避免把 SQL 错误直接暴露给后台用户。
 */
function uriHijackStructuredColumnsAvailable(PDO $pdo): bool
{
    static $available;
    if ($available !== null) {
        return $available;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM cainiao_uri_hijack");
        $columns = array_map(static function ($row) {
            return (string)($row['Field'] ?? '');
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        $required = ['match_type', 'source_pattern', 'target_url', 'priority', 'enabled'];
        $available = count(array_intersect($required, $columns)) === count($required);
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

// 获取列表（管理员不验证user_id）
function getList(PDO $pdo, array $input)
{
    if (empty($input['apk_id'])) throw new Exception('缺少apk_id');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $configId = resolveConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('无效的配置ID');

    $stmt = $pdo->prepare("SELECT * FROM cainiao_uri_hijack WHERE config_id = ? ORDER BY id DESC");
    $stmt->execute([$configId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 新增（管理员不验证user_id）
function add(PDO $pdo, array $input)
{
    if (empty($input['apk_id'])) throw new Exception('缺少apk_id');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $configId = resolveConfigIdByApk($pdo, $userId, $input['apk_id'], $isAdmin);
    if (!$configId) throw new Exception('无效的配置ID');

    $rule = uriHijackNormalizeInput($input);
    $hasStructuredColumns = uriHijackStructuredColumnsAvailable($pdo);
    if (!$hasStructuredColumns && $rule['match_type'] !== 'class') {
        throw new RuntimeException('数据库尚未完成 URI 通用规则迁移，请先执行 migrate_uri_hijack_rules.sql');
    }
    if ($hasStructuredColumns) {
        $stmt = $pdo->prepare("INSERT INTO cainiao_uri_hijack
            (config_id, remark, class_name, uri_value, match_type, source_pattern, target_url, priority, enabled, created_at)
            VALUES (:config_id, :remark, :class_name, :uri_value, :match_type, :source_pattern, :target_url, :priority, :enabled, NOW())");
    } else {
        $stmt = $pdo->prepare("INSERT INTO cainiao_uri_hijack (config_id, remark, class_name, uri_value, created_at)
                               VALUES (:config_id, :remark, :class_name, :uri_value, NOW())");
    }
    $params = [
        ':config_id'  => $configId,
        ':remark'     => $rule['remark'],
        ':class_name' => $rule['class_name'],
        ':uri_value'  => $rule['uri_value'],
    ];
    if ($hasStructuredColumns) {
        $params[':match_type'] = $rule['match_type'];
        $params[':source_pattern'] = $rule['source_pattern'];
        $params[':target_url'] = $rule['target_url'];
        $params[':priority'] = $rule['priority'];
        $params[':enabled'] = $rule['enabled'];
    }
    $stmt->execute($params);
    $apkId = (int)$input['apk_id'];
    Auth::afterConfigChange($pdo, $apkId);
    return ['message' => '添加成功'];
}

// 编辑（管理员不验证user_id）
function edit(PDO $pdo, array $input)
{
    if (empty($input['id'])) throw new Exception('缺少id');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        // 非管理员校验归属
        $check = $pdo->prepare("SELECT h.id
            FROM cainiao_uri_hijack h
            JOIN cainiao_apk_config c ON h.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE h.id = :id AND a.user_id = :uid");
        $check->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$check->fetch()) throw new Exception('权限不足或记录不存在');
    }

    // 更新前读取归属 APP，供全局同步与 Redis 失效使用。
    $appStmt = $pdo->prepare("SELECT c.apk_id
        FROM cainiao_uri_hijack h
        JOIN cainiao_apk_config c ON c.id = h.config_id
        WHERE h.id = :id LIMIT 1");
    $appStmt->execute([':id' => $input['id']]);
    $apkId = (int)$appStmt->fetchColumn();
    if ($apkId <= 0) throw new Exception('记录不存在');

    $rule = uriHijackNormalizeInput($input);
    $hasStructuredColumns = uriHijackStructuredColumnsAvailable($pdo);
    if (!$hasStructuredColumns && $rule['match_type'] !== 'class') {
        throw new RuntimeException('数据库尚未完成 URI 通用规则迁移，请先执行 migrate_uri_hijack_rules.sql');
    }
    if ($hasStructuredColumns) {
        $stmt = $pdo->prepare("UPDATE cainiao_uri_hijack
                               SET remark = :remark, class_name = :class_name, uri_value = :uri_value,
                                   match_type = :match_type, source_pattern = :source_pattern,
                                   target_url = :target_url, priority = :priority, enabled = :enabled
                               WHERE id = :id");
    } else {
        $stmt = $pdo->prepare("UPDATE cainiao_uri_hijack
                               SET remark = :remark, class_name = :class_name, uri_value = :uri_value
                               WHERE id = :id");
    }
    $params = [
        ':remark' => $rule['remark'],
        ':class_name' => $rule['class_name'],
        ':uri_value' => $rule['uri_value'],
        ':id' => $input['id'],
    ];
    if ($hasStructuredColumns) {
        $params[':match_type'] = $rule['match_type'];
        $params[':source_pattern'] = $rule['source_pattern'];
        $params[':target_url'] = $rule['target_url'];
        $params[':priority'] = $rule['priority'];
        $params[':enabled'] = $rule['enabled'];
    }
    $stmt->execute($params);
    Auth::afterConfigChange($pdo, $apkId);
    return ['message' => '更新成功'];
}

// 删除（管理员不验证user_id）
function delete(PDO $pdo, array $input)
{
    if (empty($input['id'])) throw new Exception('缺少id');

    $user = Auth::check($pdo);
    $userId = (int)$user['id'];
    $isAdmin = ($user['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        // 非管理员校验归属
        $check = $pdo->prepare("SELECT h.id
            FROM cainiao_uri_hijack h
            JOIN cainiao_apk_config c ON h.config_id = c.id
            JOIN cainiao_apk a ON c.apk_id = a.id
            WHERE h.id = :id AND a.user_id = :uid");
        $check->execute([':id' => $input['id'], ':uid' => $userId]);
        if (!$check->fetch()) throw new Exception('权限不足或记录不存在');
    }

    $appStmt = $pdo->prepare("SELECT c.apk_id
        FROM cainiao_uri_hijack h
        JOIN cainiao_apk_config c ON c.id = h.config_id
        WHERE h.id = :id LIMIT 1");
    $appStmt->execute([':id' => $input['id']]);
    $apkId = (int)$appStmt->fetchColumn();
    if ($apkId <= 0) throw new Exception('记录不存在');

    $stmt = $pdo->prepare("DELETE FROM cainiao_uri_hijack WHERE id = ?");
    $stmt->execute([$input['id']]);
    Auth::afterConfigChange($pdo, $apkId);
    return ['message' => '删除成功'];
}
