<?php


if (!function_exists('vcColumnExists')) {
    function vcColumnExists(VcDb $conn, string $table, string $column): bool {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }

        try {
            $result = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");

            return $result !== false && $result->num_rows > 0;
        } catch (Throwable) {
            return false;
        }
    }
}

if (!function_exists('vcScopeColumnExists')) {
    function vcScopeColumnExists(VcDb $conn, string $table, string $column): bool {
        return vcColumnExists($conn, $table, $column);
    }
}

if (!function_exists('vcTableExists')) {
    function vcTableExists(VcDb $conn, string $table): bool {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        try {
            if ($conn->driver() === 'sqlite') {
                $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sqlite_master WHERE type = 'table' AND name = ?");
            } else {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS c
                    FROM information_schema.tables
                    WHERE table_schema = current_schema()
                    AND table_name = ?
                ");
            }

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('s', $table);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return !empty($row) && (int) ($row['c'] ?? 0) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}

if (!function_exists('vcGetDirectChildrenIds')) {
    function vcGetDirectChildrenIds(VcDb $conn, int $managerId): array {
        if ($managerId <= 0 || !vcScopeColumnExists($conn, 'users', 'manager_id')) {
            return [];
        }

        $ids = [];
        $stmt = $conn->prepare("SELECT id FROM users WHERE manager_id = ?");
        if (!$stmt) return [];

        $stmt->bind_param("i", $managerId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }

        $stmt->close();
        return $ids;
    }
}

if (!function_exists('vcGetTeamUserIds')) {
    function vcGetTeamUserIds(VcDb $conn, int $managerId): array {
        if ($managerId <= 0) return [];

        $all = [$managerId];
        $queue = [$managerId];
        $seen = [$managerId => true];

        while (!empty($queue)) {
            $current = array_shift($queue);
            $children = vcGetDirectChildrenIds($conn, (int)$current);

            foreach ($children as $childId) {
                if (!isset($seen[$childId])) {
                    $seen[$childId] = true;
                    $all[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return array_values(array_unique(array_map('intval', $all)));
    }
}

if (!function_exists('vcGetScopedUserIds')) {
    function vcGetScopedUserIds(VcDb $conn, int $uid, string $scope, bool $isAdmin = false): array {
        if ($isAdmin || $scope === 'all') {
            return []; 
        }
        if ($scope === 'team') {
            $ids = vcGetTeamUserIds($conn, $uid);
            return !empty($ids) ? $ids : [$uid];
        }
        return [$uid];
    }
}

if (!function_exists('vcGetUserPageScope')) {
    function vcGetUserPageScope(VcDb $conn, int $uid, string $pageName): string {
        $scope = 'none';
        $stmt = $conn->prepare("\n            SELECT up.scope\n            FROM user_permissions up\n            JOIN pages p ON p.id = up.page_id\n            WHERE up.user_id = ?\n            AND p.name = ?\n            AND p.status = 1\n            LIMIT 1\n        ");
        if ($stmt) {
            $stmt->bind_param("is", $uid, $pageName);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['scope'])) {
                $scope = in_array($row['scope'], ['own','team','all'], true) ? $row['scope'] : 'own';
            }
        }
        return $scope;
    }
}

if (!function_exists('vcGetDashboardScopedUserIds')) {
    function vcGetDashboardScopedUserIds(VcDb $conn, int $uid, array $userRow, bool $isAdmin): array {
        if ($isAdmin) return []; 
        $jobRole = (string)($userRow['job_role'] ?? 'user');
        if (in_array($jobRole, ['section_manager', 'finance_manager', 'commercial_manager'], true) || (int)($userRow['is_supervisor'] ?? 0) === 1) {
            $ids = vcGetTeamUserIds($conn, $uid);
            return !empty($ids) ? $ids : [$uid];
        }
        return [$uid];
    }
}

if (!function_exists('vcBuildInCondition')) {
    function vcBuildInCondition(string $columnSql, array $ids, array &$params, string &$types, string $prefix = 'AND'): string {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function($v){ return $v > 0; })));
        if (empty($ids)) return '';
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        foreach ($ids as $id) {
            $params[] = $id;
            $types .= 'i';
        }
        return " {$prefix} {$columnSql} IN ({$placeholders}) ";
    }
}


if (!function_exists('vcGetVisibleUsersForFilter')) {
    function vcGetVisibleUsersForFilter(VcDb $conn, array $scopeIds = []): array {
        $rows = [];
        $scopeIds = array_values(array_unique(array_filter(array_map('intval', $scopeIds), function($v){ return $v > 0; })));
        if (empty($scopeIds)) {
            $res = $conn->query("SELECT id, username FROM users ORDER BY username ASC");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }
        $placeholders = implode(',', array_fill(0, count($scopeIds), '?'));
        $types = str_repeat('i', count($scopeIds));
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE id IN ({$placeholders}) ORDER BY username ASC");
        if ($stmt) {
            $stmt->bind_param($types, ...$scopeIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
        return $rows;
    }
}

if (!function_exists('vcIsUserInScope')) {
    function vcIsUserInScope(int $targetUserId, array $scopeIds = []): bool {
        $targetUserId = (int)$targetUserId;
        if ($targetUserId <= 0) return false;
        if (empty($scopeIds)) return true; 
        return in_array($targetUserId, array_map('intval', $scopeIds), true);
    }
}

if (!function_exists('vcUserHasPagePermission')) {
    function vcUserHasPagePermission(VcDb $conn, int $uid, string $pageName): bool
    {
        if ($uid <= 0 || $pageName === '') {
            return false;
        }

        $stmt = $conn->prepare('
            SELECT COUNT(*) AS c
            FROM user_permissions up
            JOIN pages p ON p.id = up.page_id
            WHERE up.user_id = ?
            AND p.name = ?
            AND p.status = 1
        ');

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('is', $uid, $pageName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0) > 0;
    }
}

if (!function_exists('vcUserHasAnyPagePermission')) {
    function vcUserHasAnyPagePermission(VcDb $conn, int $uid, array $pageNames): bool
    {
        foreach ($pageNames as $pageName) {
            if (vcUserHasPagePermission($conn, $uid, (string) $pageName)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('vcSafeUploadHref')) {
    function vcSafeUploadHref(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '' || preg_match('#^(javascript:|data:|vbscript:)#i', $path)) {
            return '';
        }

        if (preg_match('#^uploads/(supplier_contracts|payment_requests)/[A-Za-z0-9._-]+$#', $path)) {
            return $path;
        }

        return '';
    }
}

if (!function_exists('vcFailWithLog')) {
    function vcFailWithLog(string $userMessage, ?Throwable $e = null, int $code = 500): never
    {
        if ($e !== null) {
            error_log($userMessage . ': ' . $e->getMessage());
        }

        if ($code >= 400) {
            http_response_code($code);
        }

        die($userMessage);
    }
}

if (!function_exists('vcCommercialManagerAllowedPages')) {
    /**
     * صفحات المدير التجاري الافتراضية — ليست صلاحية كاملة مثل الأدمن.
     *
     * @return string[]
     */
    function vcCommercialManagerAllowedPages(): array
    {
        return [
            'dashboard',
            'my_account',
            'add_contract',
            'drafts',
            'under_review',
            'contracts',
            'my_contracts',
            'view_contract',
            'admin_view_contract',
            'admin_review',
            'print_contract',
            'rents',
            'branch_rents',
            'add_items',
            'my_items',
            'under_review_items',
            'view_items',
            'items_admin',
            'data_entry_items',
            'finance_items',
            'accounting',
            'accounting_api',
            'add_payment_request',
            'payment_approvals',
            'print_payment_request',
            'search_supplier',
            'supplier_name_check',
        ];
    }
}

if (!function_exists('vcCommercialManagerPageAllowed')) {
    function vcCommercialManagerPageAllowed(string $pageName): bool
    {
        return in_array($pageName, vcCommercialManagerAllowedPages(), true);
    }
}

if (!function_exists('vcJobRoleArabic')) {
    function vcJobRoleArabic(array $userRow): string {
        $jobRole = (string)($userRow['job_role'] ?? '');
        $isAdmin = ((int)($userRow['is_admin'] ?? 0) === 1 || ($userRow['role'] ?? '') === 'admin' || $jobRole === 'admin');
        if ($isAdmin) return 'أدمن';
        if ($jobRole === 'commercial_manager') return 'مدير تجاري';
        if ($jobRole === 'finance_manager') return 'مدير مالي';
        if ($jobRole === 'section_manager') return 'مدير قسم';
        return 'مستخدم';
    }
}
