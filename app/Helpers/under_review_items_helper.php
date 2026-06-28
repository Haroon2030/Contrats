<?php

if (!function_exists('uri_e')) {
    function uri_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('uri_money')) {
    function uri_money($value): string
    {
        return number_format((float) $value, 2);
    }
}

if (!function_exists('uri_format_date')) {
    function uri_format_date($value): string
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '0000-00-00') {
            return '-';
        }

        $ts = strtotime($value);

        return $ts ? date('Y-m-d', $ts) : '-';
    }
}

if (!function_exists('uri_ensure_csrf_token')) {
    function uri_ensure_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('uri_validate_csrf')) {
    function uri_validate_csrf(string $postedToken): bool
    {
        return $postedToken !== ''
            && !empty($_SESSION['csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], $postedToken);
    }
}

if (!function_exists('uri_is_admin_user')) {
    function uri_is_admin_user(?array $user): bool
    {
        if ($user === null || $user === []) {
            return false;
        }

        return (int) ($user['is_admin'] ?? 0) === 1
            || ($user['role'] ?? '') === 'admin';
    }
}

if (!function_exists('uri_resolve_page_scope')) {
    function uri_resolve_page_scope(VcDb $conn, int $uid): string
    {
        $scope = 'none';

        $stmt = $conn->prepare('
            SELECT up.scope
            FROM user_permissions up
            JOIN pages p ON p.id = up.page_id
            WHERE up.user_id = ?
            AND p.name = ?
            AND p.status = 1
            LIMIT 1
        ');

        if ($stmt) {
            $pageName = 'under_review_items';
            $stmt->bind_param('is', $uid, $pageName);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!empty($row['scope'])) {
                $scope = (string) $row['scope'];
            }
        }

        return in_array($scope, ['own', 'team', 'all'], true) ? $scope : 'own';
    }
}

if (!function_exists('uri_load_access_context')) {
    /**
     * @return array{
     *   is_admin: bool,
     *   scope: string,
     *   scoped_user_ids: array<int, int>,
     *   show_user_column: bool
     * }
     */
    function uri_load_access_context(VcDb $conn, int $uid): array
    {
        $stmtUser = $conn->prepare('SELECT is_admin, role, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1');
        $stmtUser->bind_param('i', $uid);
        $stmtUser->execute();
        $currentUser = $stmtUser->get_result()->fetch_assoc();
        $stmtUser->close();

        $isAdmin = uri_is_admin_user($currentUser);
        $scope = uri_resolve_page_scope($conn, $uid);
        $scopedUserIds = vcGetScopedUserIds($conn, $uid, $scope, $isAdmin);

        return [
            'is_admin' => $isAdmin,
            'scope' => $scope,
            'scoped_user_ids' => $scopedUserIds,
            'show_user_column' => $isAdmin || in_array($scope, ['team', 'all'], true),
        ];
    }
}

if (!function_exists('uri_sanitize_user_filter')) {
    function uri_sanitize_user_filter(string $userFilter, bool $showUserColumn, array $scopedUserIds): string
    {
        if (!$showUserColumn || $userFilter === '') {
            return '';
        }

        if (!vcIsUserInScope((int) $userFilter, $scopedUserIds)) {
            return '';
        }

        return $userFilter;
    }
}

if (!function_exists('uri_build_query_parts')) {
    /**
     * @return array{from_where: string, params: array<int, mixed>, types: string}
     */
    function uri_build_query_parts(array $scopedUserIds, string $userFilter, string $search): array
    {
        $fromWhere = '
            FROM items
            LEFT JOIN users ON users.id = items.created_by
            WHERE items.status = \'review\'
        ';

        $params = [];
        $types = '';
        $fromWhere .= vcBuildInCondition('items.created_by', $scopedUserIds, $params, $types);

        if ($userFilter !== '') {
            $fromWhere .= ' AND items.created_by = ?';
            $params[] = (int) $userFilter;
            $types .= 'i';
        }

        if ($search !== '') {
            $fromWhere .= ' AND (
                items.supplier_name LIKE ?
                OR items.batch_id LIKE ?
                OR users.username LIKE ?
            )';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'sss';
        }

        return [
            'from_where' => $fromWhere,
            'params' => $params,
            'types' => $types,
        ];
    }
}

if (!function_exists('uri_fetch_summary')) {
    /**
     * @return array{total_batches: int, total_items: int, total_fees: float}
     */
    function uri_fetch_summary(VcDb $conn, string $fromWhere, array $params, string $types): array
    {
        $sql = '
            SELECT
                COUNT(DISTINCT items.batch_id) AS total_batches,
                COUNT(*) AS total_items,
                COALESCE(SUM(items.fee), 0) AS total_fees
            ' . $fromWhere;

        $summary = [];
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            if ($params !== []) {
                $stmt->bind_param($types, ...$params);
            }

            $stmt->execute();
            $summary = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }

        return [
            'total_batches' => (int) ($summary['total_batches'] ?? 0),
            'total_items' => (int) ($summary['total_items'] ?? 0),
            'total_fees' => (float) ($summary['total_fees'] ?? 0),
        ];
    }
}

if (!function_exists('uri_fetch_batches')) {
    /**
     * @return array{rows: list<array<string, mixed>>, page: int, total_pages: int}
     */
    function uri_fetch_batches(VcDb $conn, string $fromWhere, array $params, string $types): array
    {
        $groupCountSql = '
            SELECT items.batch_id
            ' . $fromWhere . '
            GROUP BY items.batch_id, items.supplier_name, users.username
        ';

        $pg = vcPaginationState();
        $totalRows = vcPaginationCountGrouped($conn, $groupCountSql, $params, $types);
        $totalPages = vcPaginationTotalPages($totalRows, $pg['per_page']);
        $page = min($pg['page'], $totalPages);

        $sql = '
            SELECT
                items.batch_id,
                items.supplier_name,
                COUNT(*) AS items_count,
                SUM(items.fee) AS total_fees,
                MAX(items.id) AS last_id,
                MAX(items.created_at) AS created_at,
                MAX(items.created_by) AS created_by,
                users.username AS created_username
            ' . $fromWhere . '
            GROUP BY items.batch_id, items.supplier_name, users.username
            ORDER BY last_id DESC
            LIMIT ? OFFSET ?
        ';

        [$dataParams, $dataTypes] = vcPaginationBindLimit($params, $types, $pg['limit'], ($page - 1) * $pg['per_page']);

        $rows = [];
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            if ($dataParams !== []) {
                $stmt->bind_param($dataTypes, ...$dataParams);
            }

            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }

            $stmt->close();
        }

        return [
            'rows' => $rows,
            'page' => $page,
            'total_pages' => $totalPages,
        ];
    }
}

if (!function_exists('uri_handle_delete_batch')) {
    function uri_handle_delete_batch(VcDb $conn, bool $isAdmin): void
    {
        if (!uri_validate_csrf((string) ($_POST['csrf_token'] ?? ''))) {
            die('طلب غير صالح');
        }

        if (!$isAdmin) {
            http_response_code(403);
            die('❌ ليس لديك صلاحية حذف طلبات الأصناف');
        }

        $deleteBatch = trim((string) ($_POST['batch_id'] ?? ''));

        if ($deleteBatch === '') {
            header('Location: under_review_items.php');
            exit();
        }

        $stmtDelete = $conn->prepare('
            DELETE FROM items
            WHERE batch_id = ?
              AND status = \'review\'
        ');

        if (!$stmtDelete) {
            $_SESSION['under_review_items_msg'] = 'تعذر تجهيز حذف الطلب.';
        } else {
            $stmtDelete->bind_param('s', $deleteBatch);
            $stmtDelete->execute();
            $deletedCount = $stmtDelete->affected_rows;
            $stmtDelete->close();

            $_SESSION['under_review_items_msg'] = $deletedCount > 0
                ? 'تم حذف طلب الأصناف رقم ' . $deleteBatch . ' بنجاح.'
                : 'لم يتم حذف الطلب، ربما ليس تحت المراجعة.';
        }

        header('Location: under_review_items.php');
        exit();
    }
}

if (!function_exists('uri_table_colspan')) {
    function uri_table_colspan(bool $showUserColumn): int
    {
        return $showUserColumn ? 8 : 7;
    }
}

if (!function_exists('uri_row_actions')) {
    function uri_row_actions(array $row, string $csrfToken, bool $isAdmin): void
    {
        $batchId = (string) ($row['batch_id'] ?? '');

        vcRenderRowActions([
            'view' => [
                'href' => 'view_items.php?batch=' . urlencode($batchId) . '&mode=view',
                'label' => 'عرض / طباعة',
            ],
            'edit' => [
                'href' => 'add_items.php?edit_batch=' . urlencode($batchId),
            ],
            'delete' => [
                'action' => 'delete_review_item_batch',
                'fields' => ['batch_id' => $batchId],
                'confirm' => 'تأكيد حذف طلب الأصناف رقم ' . $batchId . '؟',
            ],
        ], $csrfToken, $isAdmin);
    }
}
