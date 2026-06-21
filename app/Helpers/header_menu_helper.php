<?php

if (!function_exists('vcBuildHeaderNavModules')) {
    function vcBuildHeaderNavModules(VcDb $conn, int $userId, bool $isAdmin): array
    {
        if ($userId <= 0) {
            return [];
        }

        if ($isAdmin) {
            $stmt = $conn->prepare("
                SELECT
                    p.name,
                    p.title,
                    p.icon,
                    p.section,
                    COALESCE(u.sort_order, p.sort_order) AS final_order
                FROM pages p
                LEFT JOIN user_page_order u
                    ON p.name = u.page_name AND u.user_id = ?
                WHERE p.status = 1
                ORDER BY p.section, final_order ASC
            ");
            $stmt->bind_param('i', $userId);
        } else {
            $stmt = $conn->prepare("
                SELECT
                    p.name,
                    p.title,
                    p.icon,
                    p.section,
                    COALESCE(u.sort_order, p.sort_order) AS final_order
                FROM user_permissions up
                JOIN pages p ON up.page_id = p.id
                LEFT JOIN user_page_order u
                    ON p.name = u.page_name AND u.user_id = ?
                WHERE up.user_id = ? AND p.status = 1
                ORDER BY p.section, final_order ASC
            ");
            $stmt->bind_param('ii', $userId, $userId);
        }

        if (!$stmt) {
            return [];
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $groups = [
            'contracts' => [],
            'rents' => [],
            'items' => [],
            'finance' => [],
            'admin' => [],
        ];

        while ($row = $result->fetch_assoc()) {
            $section = (string)($row['section'] ?? 'finance');
            if (!isset($groups[$section])) {
                $groups['finance'][] = $row;
                continue;
            }
            $groups[$section][] = $row;
        }

        $stmt->close();

        $labels = [
            'contracts' => ['title' => 'الموردين والعقود', 'subtitle' => 'Procurement & Contracts', 'icon' => 'ri-file-text-line'],
            'rents' => ['title' => 'العقارات والإيجارات', 'subtitle' => 'Lease Management', 'icon' => 'ri-building-2-line'],
            'items' => ['title' => 'المخزون والأصناف', 'subtitle' => 'Inventory & SKU', 'icon' => 'ri-barcode-box-line'],
            'finance' => ['title' => 'المالية والمحاسبة', 'subtitle' => 'Finance & AP', 'icon' => 'ri-bank-card-line'],
            'admin' => ['title' => 'النظام والإعدادات', 'subtitle' => 'System Admin', 'icon' => 'ri-settings-3-line'],
        ];

        $modules = [];
        foreach ($groups as $id => $items) {
            if ($items === []) {
                continue;
            }
            $meta = $labels[$id] ?? ['title' => $id, 'subtitle' => '', 'icon' => 'ri-folder-line'];
            $modules[] = [
                'id' => $id,
                'title' => $meta['title'],
                'subtitle' => $meta['subtitle'],
                'icon' => $meta['icon'],
                'items' => $items,
            ];
        }

        return $modules;
    }
}
