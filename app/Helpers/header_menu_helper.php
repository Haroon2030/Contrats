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

if (!function_exists('vcNavSectionItems')) {
    function vcNavSectionItems(array $modules, string $section): array
    {
        foreach ($modules as $module) {
            if ((string) ($module['id'] ?? '') === $section) {
                return (array) ($module['items'] ?? []);
            }
        }

        return [];
    }
}

if (!function_exists('vcPageRemixIcon')) {
    /**
     * رمز Remix Icon لصفحة القائمة — بدون صور من المجلد.
     */
    function vcPageRemixIcon(string $pageName, string $title = ''): string
    {
        $map = [
            'drafts' => 'ri-discuss-line',
            'add_contract' => 'ri-file-add-line',
            'under_review' => 'ri-search-eye-line',
            'admin_review' => 'ri-shield-check-line',
            'my_contracts' => 'ri-checkbox-circle-line',
            'contracts' => 'ri-file-list-3-line',
            'users' => 'ri-team-line',
            'rents' => 'ri-building-2-line',
            'admin_view_contract' => 'ri-eye-line',
            'view_contract' => 'ri-eye-line',
            'accounting' => 'ri-money-dollar-circle-line',
            'branch_rents' => 'ri-home-smile-line',
            'add_items' => 'ri-add-box-line',
            'under_review_items' => 'ri-hourglass-line',
            'view_items' => 'ri-list-check-2',
            'items_admin' => 'ri-shield-star-line',
            'my_items' => 'ri-barcode-box-line',
            'finance_items' => 'ri-coins-line',
            'data_entry_items' => 'ri-keyboard-box-line',
            'my_account' => 'ri-user-settings-line',
            'add_payment_request' => 'ri-bank-card-2-line',
            'payment_approvals' => 'ri-check-double-line',
            'print_contract' => 'ri-printer-line',
            'print_payment_request' => 'ri-printer-cloud-line',
        ];

        $pageName = trim($pageName);
        if ($pageName !== '' && isset($map[$pageName])) {
            return $map[$pageName];
        }

        $hay = mb_strtolower($pageName . ' ' . $title, 'UTF-8');
        if (str_contains($hay, 'تفاوض') || str_contains($hay, 'draft')) {
            return 'ri-discuss-line';
        }
        if (str_contains($hay, 'إضافة عقد') || str_contains($hay, 'add_contract')) {
            return 'ri-file-add-line';
        }
        if (str_contains($hay, 'مراجعة') || str_contains($hay, 'review')) {
            return 'ri-search-eye-line';
        }
        if (str_contains($hay, 'إيجار') || str_contains($hay, 'rent')) {
            return 'ri-building-2-line';
        }
        if (str_contains($hay, 'صنف') || str_contains($hay, 'item')) {
            return 'ri-barcode-box-line';
        }
        if (str_contains($hay, 'سداد') || str_contains($hay, 'payment')) {
            return 'ri-bank-card-line';
        }
        if (str_contains($hay, 'مالي') || str_contains($hay, 'finance') || str_contains($hay, 'محاسب')) {
            return 'ri-money-dollar-circle-line';
        }
        if (str_contains($hay, 'مستخدم') || str_contains($hay, 'user')) {
            return 'ri-team-line';
        }

        return 'ri-file-line';
    }
}
