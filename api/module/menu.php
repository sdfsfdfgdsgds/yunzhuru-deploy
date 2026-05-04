<?php

function getMenu(PDO $pdo, array $input)
{
    // 获取当前用户信息（由 Auth 中间件返回完整字段）
    $user = Auth::check($pdo);
    $role = $user['role'] ?? 'user';

    $menuTable = 'cainiao_menu';

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
