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
 * 补齐资源库菜单并统一为一级入口。
 *
 * 老库中的资源库原本挂在“应用配置”下面。迁移时保留原菜单 ID，
 * 只调整 parent_id，这样两个资源子页面、已保存的页面路径和权限合同都不变。
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
    $resourceId = ensureTopLevelMenuNode($pdo, $roleId, $appConfigId, '资源库', 'FolderOpened', '');
    ensureMenuNode($pdo, $roleId, $resourceId, '图片资源', 'Picture', 'config/resource/image');
    ensureMenuNode($pdo, $roleId, $resourceId, '链接/事件参数', 'Link', 'config/resource/click_param');
}

/**
 * 确保指定菜单位于一级，并兼容从旧父级原地迁移。
 *
 * 合同：优先复用已有一级节点；其次复用旧父级下的同名节点并清空 parent_id；
 * 只有两者都不存在时才创建新节点，避免老库出现两个“资源库”入口。
 */
function ensureTopLevelMenuNode(
    PDO $pdo,
    int $roleId,
    int $legacyParentId,
    string $name,
    string $icon,
    string $path
): int {
    $topLevel = $pdo->prepare("
        SELECT id
        FROM cainiao_menu
        WHERE role_id = :role_id AND name = :name AND parent_id IS NULL
        ORDER BY id ASC
        LIMIT 1
    ");
    $topLevel->execute([
        ':role_id' => $roleId,
        ':name' => $name,
    ]);

    $topLevelId = (int)$topLevel->fetchColumn();
    if ($topLevelId > 0) {
        return $topLevelId;
    }

    $legacy = $pdo->prepare("
        SELECT id
        FROM cainiao_menu
        WHERE role_id = :role_id AND name = :name AND parent_id = :parent_id
        ORDER BY id ASC
        LIMIT 1
    ");
    $legacy->execute([
        ':role_id' => $roleId,
        ':name' => $name,
        ':parent_id' => $legacyParentId,
    ]);

    $legacyId = (int)$legacy->fetchColumn();
    if ($legacyId > 0) {
        $move = $pdo->prepare("
            UPDATE cainiao_menu
            SET parent_id = NULL
            WHERE id = :id AND role_id = :role_id AND parent_id = :parent_id
        ");
        $move->execute([
            ':id' => $legacyId,
            ':role_id' => $roleId,
            ':parent_id' => $legacyParentId,
        ]);
        return $legacyId;
    }

    return ensureMenuNode($pdo, $roleId, null, $name, $icon, $path);
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
