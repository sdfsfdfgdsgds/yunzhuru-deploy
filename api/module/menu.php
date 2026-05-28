<?php

function getMenu(PDO $pdo, array $input)
{
    // 获取当前用户信息（由 Auth 中间件返回完整字段）
    $user = Auth::check($pdo);
    $role = $user['role'] ?? 'user';

    $menuTable = 'cainiao_menu';
    ensureResourceLibraryMenus($pdo);

    // 获取菜单数据（admin 获取全部）
    if ($role === 'admin') {
        $stmt = $pdo->query("SELECT * FROM `$menuTable` ORDER BY id ASC");
        $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM `$menuTable` WHERE role_id = (
            SELECT id FROM cainiao_role WHERE name = :role LIMIT 1
        ) ORDER BY id ASC");
        $stmt->execute([':role' => $role]);
        $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 转换为树结构
    $tree = buildMenuTree($menus);

    return $tree;
}

/**
 * 补齐资源库菜单，避免老库升级后左侧菜单缺少全局资源入口。
 */
function ensureResourceLibraryMenus(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $stmt = $pdo->prepare("SELECT id FROM cainiao_role WHERE name = 'user' LIMIT 1");
    $stmt->execute();
    $roleId = (int)$stmt->fetchColumn();
    if ($roleId <= 0) {
        return;
    }

    $appConfigId = ensureMenuNode($pdo, $roleId, null, '应用配置', 'Setting', '');
    $resourceId = ensureMenuNode($pdo, $roleId, $appConfigId, '资源库', 'FolderOpened', '');
    ensureMenuNode($pdo, $roleId, $resourceId, '图片资源', 'Picture', 'config/resource/image');
    ensureMenuNode($pdo, $roleId, $resourceId, '链接/事件参数', 'Link', 'config/resource/click_param');
}

/**
 * 按角色和父级补齐一个菜单节点，已存在时不覆盖用户调整过的菜单信息。
 */
function ensureMenuNode(PDO $pdo, int $roleId, ?int $parentId, string $name, string $icon, string $path): int
{
    if ($parentId === null) {
        $check = $pdo->prepare("SELECT id FROM cainiao_menu WHERE role_id = :role_id AND name = :name AND parent_id IS NULL LIMIT 1");
        $check->execute([
            ':role_id' => $roleId,
            ':name' => $name,
        ]);
    } else {
        $check = $pdo->prepare("SELECT id FROM cainiao_menu WHERE role_id = :role_id AND name = :name AND parent_id = :parent_id LIMIT 1");
        $check->execute([
            ':role_id' => $roleId,
            ':name' => $name,
            ':parent_id' => $parentId,
        ]);
    }

    $id = (int)$check->fetchColumn();
    if ($id > 0) {
        return $id;
    }

    $insert = $pdo->prepare("
        INSERT INTO cainiao_menu (parent_id, name, icon, path, hidden, role_id)
        VALUES (:parent_id, :name, :icon, :path, 0, :role_id)
    ");
    $insert->execute([
        ':parent_id' => $parentId,
        ':name' => $name,
        ':icon' => $icon,
        ':path' => $path,
        ':role_id' => $roleId,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * 构建树形菜单结构
 */
function buildMenuTree(array $items, $parentId = null): array
{
    $branch = [];

    foreach ($items as $item) {
        if ($item['parent_id'] == $parentId) {
            $node = [
                'id'     => (string)$item['id'],
                'name'   => $item['name'],
                'icon'   => $item['icon'] ?? '',
                'path'   => $item['path'] ?? '',
                'hidden' => (bool)$item['hidden']
            ];

            $children = buildMenuTree($items, $item['id']);
            if ($children) {
                $node['menu'] = $children;
            }

            $branch[] = $node;
        }
    }

    return $branch;
}
