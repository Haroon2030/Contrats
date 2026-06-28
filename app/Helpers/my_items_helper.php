<?php

if (!function_exists('mi_e')) {
    function mi_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('mi_money')) {
    function mi_money($value): string
    {
        return number_format((float) $value, 2);
    }
}

if (!function_exists('mi_status_text')) {
    function mi_status_text(string $status): string
    {
        return match ($status) {
            'approved' => 'تمت الموافقة',
            'rejected' => 'مرفوض',
            default => 'غير معروف',
        };
    }
}

if (!function_exists('mi_status_class')) {
    function mi_status_class(string $status): string
    {
        return $status === 'rejected' ? 'rejected' : 'approved';
    }
}

if (!function_exists('mi_format_date')) {
    function mi_format_date($value): string
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '0000-00-00') {
            return '-';
        }

        $ts = strtotime($value);

        return $ts ? date('Y-m-d', $ts) : '-';
    }
}

if (!function_exists('mi_ensure_csrf_token')) {
    function mi_ensure_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('mi_validate_csrf')) {
    function mi_validate_csrf(string $postedToken): bool
    {
        return $postedToken !== ''
            && !empty($_SESSION['csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], $postedToken);
    }
}

if (!function_exists('mi_is_admin_user')) {
    function mi_is_admin_user(?array $user): bool
    {
        if ($user === null || $user === []) {
            return false;
        }

        return (int) ($user['is_admin'] ?? 0) === 1
            || ($user['role'] ?? '') === 'admin';
    }
}

if (!function_exists('mi_resolve_page_scope')) {
    function mi_resolve_page_scope(VcDb $conn, int $uid): string
    {
        $scope = 'own';

        $stmt = $conn->prepare('
            SELECT up.scope
            FROM user_permissions up
            INNER JOIN pages p ON p.id = up.page_id
            WHERE up.user_id = ?
            AND (
                p.name IN (\'my_items\', \'my_items.php\')
                OR p.title IN (\'أصنافي\', \'اصنافي\')
            )
            LIMIT 1
        ');

        if ($stmt) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!empty($row['scope']) && in_array($row['scope'], ['own', 'team', 'all'], true)) {
                $scope = (string) $row['scope'];
            }
        }

        return $scope;
    }
}

if (!function_exists('mi_load_access_context')) {
    /**
     * @return array{
     *   is_admin: bool,
     *   scope: string,
     *   scoped_user_ids: array<int, int>,
     *   can_view_all: bool,
     *   show_user_filter: bool
     * }
     */
    function mi_load_access_context(VcDb $conn, int $uid): array
    {
        $stmtAdmin = $conn->prepare('SELECT is_admin, role, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1');
        $stmtAdmin->bind_param('i', $uid);
        $stmtAdmin->execute();
        $adminRow = $stmtAdmin->get_result()->fetch_assoc();
        $stmtAdmin->close();

        $isAdmin = mi_is_admin_user($adminRow);
        $scope = mi_resolve_page_scope($conn, $uid);
        $scopedUserIds = vcGetScopedUserIds($conn, $uid, $scope, $isAdmin);

        return [
            'is_admin' => $isAdmin,
            'scope' => $scope,
            'scoped_user_ids' => $scopedUserIds,
            'can_view_all' => $scopedUserIds === [],
            'show_user_filter' => $isAdmin || in_array($scope, ['team', 'all'], true),
        ];
    }
}

if (!function_exists('mi_sanitize_user_filter')) {
    function mi_sanitize_user_filter(string $userFilter, bool $showUserFilter, array $scopedUserIds): string
    {
        if (!$showUserFilter || $userFilter === '') {
            return '';
        }

        if (!vcIsUserInScope((int) $userFilter, $scopedUserIds)) {
            return '';
        }

        return $userFilter;
    }
}

if (!function_exists('mi_build_query_parts')) {
    /**
     * @return array{from_where: string, params: array<int, mixed>, types: string}
     */
    function mi_build_query_parts(array $scopedUserIds, string $userFilter, string $search): array
    {
        $fromWhere = '
            FROM items i
            LEFT JOIN users u ON u.id = i.deducted_by
            LEFT JOIN users creator ON creator.id = i.created_by
            WHERE i.status IN (\'approved\', \'rejected\')
        ';

        $params = [];
        $types = '';
        $fromWhere .= vcBuildInCondition('i.created_by', $scopedUserIds, $params, $types);

        if ($userFilter !== '') {
            $fromWhere .= ' AND i.created_by = ?';
            $params[] = (int) $userFilter;
            $types .= 'i';
        }

        if ($search !== '') {
            $fromWhere .= ' AND (
                i.supplier_name LIKE ?
                OR i.batch_id LIKE ?
                OR creator.username LIKE ?
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

if (!function_exists('mi_fetch_summary')) {
    /**
     * @return array{
     *   total_requests: int,
     *   total_approved_items: int,
     *   total_approved_fees: float,
     *   approved_batches: int,
     *   rejected_batches: int
     * }
     */
    function mi_fetch_summary(VcDb $conn, string $fromWhere, array $params, string $types): array
    {
        $sql = '
            SELECT
                COUNT(DISTINCT i.batch_id) AS total_requests,
                SUM(CASE WHEN i.status = \'approved\' THEN 1 ELSE 0 END) AS total_approved_items,
                COALESCE(SUM(CASE WHEN i.status = \'approved\' THEN i.fee ELSE 0 END), 0) AS total_approved_fees,
                COUNT(DISTINCT CASE WHEN i.status = \'approved\' THEN i.batch_id END) AS approved_batches,
                COUNT(DISTINCT CASE WHEN i.status = \'rejected\' THEN i.batch_id END) AS rejected_batches
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
            'total_requests' => (int) ($summary['total_requests'] ?? 0),
            'total_approved_items' => (int) ($summary['total_approved_items'] ?? 0),
            'total_approved_fees' => (float) ($summary['total_approved_fees'] ?? 0),
            'approved_batches' => (int) ($summary['approved_batches'] ?? 0),
            'rejected_batches' => (int) ($summary['rejected_batches'] ?? 0),
        ];
    }
}

if (!function_exists('mi_fetch_batches')) {
    /**
     * @return array{rows: list<array<string, mixed>>, page: int, total_pages: int}
     */
    function mi_fetch_batches(VcDb $conn, string $fromWhere, array $params, string $types): array
    {
        $groupCountSql = '
            SELECT i.batch_id
            ' . $fromWhere . '
            GROUP BY
                i.batch_id,
                i.supplier_name,
                i.created_by,
                creator.username,
                u.username
        ';

        $pg = vcPaginationState();
        $totalRows = vcPaginationCountGrouped($conn, $groupCountSql, $params, $types);
        $totalPages = vcPaginationTotalPages($totalRows, $pg['per_page']);
        $page = min($pg['page'], $totalPages);

        $sql = '
            SELECT
                i.batch_id,
                i.supplier_name,
                COUNT(*) AS request_items_count,
                SUM(CASE WHEN i.status = \'approved\' THEN 1 ELSE 0 END) AS approved_items_count,
                COALESCE(SUM(CASE WHEN i.status = \'approved\' THEN i.fee ELSE 0 END), 0) AS approved_total_fees,
                MAX(i.status) AS status,
                MAX(i.created_at) AS created_at,
                MAX(i.approved_at) AS approved_at,
                MAX(i.rejected_at) AS rejected_at,
                MAX(i.paid) AS paid,
                MAX(i.deducted_by) AS deducted_by,
                MAX(i.deducted_at) AS deducted_at,
                i.created_by,
                creator.username AS creator_username,
                u.username AS deducted_username
            ' . $fromWhere . '
            GROUP BY
                i.batch_id,
                i.supplier_name,
                i.created_by,
                creator.username,
                u.username
            ORDER BY i.batch_id DESC
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

if (!function_exists('mi_handle_bulk_delete')) {
    function mi_handle_bulk_delete(VcDb $conn, bool $isAdmin): void
    {
        if (!mi_validate_csrf((string) ($_POST['csrf_token'] ?? ''))) {
            die('طلب غير صالح');
        }

        if (!$isAdmin) {
            http_response_code(403);
            die('❌ ليس لديك صلاحية حذف دفعات الأصناف');
        }

        $batchIds = $_POST['batch_ids'] ?? [];

        if (!is_array($batchIds)) {
            $batchIds = [];
        }

        $cleanBatches = [];

        foreach ($batchIds as $batchId) {
            $batchId = trim((string) $batchId);

            if ($batchId !== '') {
                $cleanBatches[] = $batchId;
            }
        }

        $cleanBatches = array_values(array_unique($cleanBatches));

        if ($cleanBatches === []) {
            $_SESSION['my_items_bulk_delete_msg'] = 'لم يتم تحديد أي دفعات للحذف.';
            header('Location: my_items.php');
            exit();
        }

        $placeholders = implode(',', array_fill(0, count($cleanBatches), '?'));
        $bindTypes = str_repeat('s', count($cleanBatches));

        $conn->begin_transaction();

        try {
            if (vcTableExists($conn, 'approval_withdrawals')) {
                $sqlWithdrawals = "
                    DELETE FROM approval_withdrawals
                    WHERE target_type = 'items'
                    AND target_id IN ({$placeholders})
                ";
                $stmtWithdrawals = $conn->prepare($sqlWithdrawals);

                if ($stmtWithdrawals) {
                    $stmtWithdrawals->bind_param($bindTypes, ...$cleanBatches);
                    $stmtWithdrawals->execute();
                    $stmtWithdrawals->close();
                }
            }

            $sqlItems = "DELETE FROM items WHERE batch_id IN ({$placeholders})";
            $stmtItems = $conn->prepare($sqlItems);

            if (!$stmtItems) {
                throw new RuntimeException('تعذر تجهيز حذف دفعات الأصناف: ' . $conn->error);
            }

            $stmtItems->bind_param($bindTypes, ...$cleanBatches);
            $stmtItems->execute();
            $deletedItemsCount = $stmtItems->affected_rows;
            $stmtItems->close();

            $conn->commit();

            $_SESSION['my_items_bulk_delete_msg'] =
                'تم إرسال ' . count($cleanBatches) . ' دفعة للحذف، وتم حذف ' . (int) $deletedItemsCount . ' صنف فعليًا.';
        } catch (Throwable $e) {
            $conn->rollback();
            vcFailWithLog('حدث خطأ أثناء حذف دفعات الأصناف.', $e);
        }

        header('Location: my_items.php');
        exit();
    }
}

if (!function_exists('mi_table_colspan')) {
    function mi_table_colspan(bool $showUserFilter): int
    {
        return 8 + ($showUserFilter ? 1 : 0);
    }
}

if (!function_exists('mi_row_actions')) {
    function mi_row_actions(array $row, string $csrfToken, bool $isAdmin): void
    {
        $status = (string) ($row['status'] ?? '');
        $batchId = (string) ($row['batch_id'] ?? '');

        $actions = [
            'view' => [
                'href' => 'view_items.php?batch=' . urlencode($batchId),
            ],
            'delete' => [
                'action' => 'bulk_delete_item_batches',
                'fields' => ['batch_ids[]' => $batchId],
                'confirm' => 'تأكيد حذف دفعة الأصناف رقم ' . $batchId . '؟',
            ],
        ];

        if ($status === 'review') {
            $actions['edit'] = [
                'href' => 'add_items.php?edit_batch=' . urlencode($batchId),
            ];
        }

        vcRenderRowActions($actions, $csrfToken, $isAdmin);
    }
}
