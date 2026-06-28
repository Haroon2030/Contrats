<?php

if (!function_exists('dei_e')) {
    function dei_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dei_get_user_page_scope')) {
    function dei_get_user_page_scope(VcDb $conn, int $uid, string $pageName): string
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
            $stmt->bind_param('is', $uid, $pageName);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!empty($row['scope'])) {
                $scope = (string) $row['scope'];
            }
        }

        return $scope;
    }
}

if (!function_exists('dei_is_admin_user')) {
    function dei_is_admin_user(?array $user): bool
    {
        if ($user === null || $user === []) {
            return false;
        }

        return (int) ($user['is_admin'] ?? 0) === 1
            || ($user['role'] ?? '') === 'admin';
    }
}

if (!function_exists('dei_assert_access')) {
    function dei_assert_access(VcDb $conn, int $uid, bool $isAdmin): void
    {
        $scope = dei_get_user_page_scope($conn, $uid, 'data_entry_items');

        if (!$isAdmin && $scope === 'none') {
            http_response_code(403);
            die('❌ ليس لديك صلاحية الدخول إلى إدخال الأصناف');
        }
    }
}

if (!function_exists('dei_ensure_items_columns')) {
    function dei_ensure_items_columns(VcDb $conn): void
    {
        $vcAppEnv = strtolower(trim((string) (vcEnv('APP_ENV', 'local') ?? 'local')));

        if ($vcAppEnv !== 'local') {
            return;
        }

        if (!vcColumnExists($conn, 'items', 'entry_done')) {
            $conn->query('ALTER TABLE items ADD COLUMN entry_done TINYINT(1) NOT NULL DEFAULT 0');
        }

        if (!vcColumnExists($conn, 'items', 'entered_by')) {
            $conn->query('ALTER TABLE items ADD COLUMN entered_by INT NULL');
        }

        if (!vcColumnExists($conn, 'items', 'entered_at')) {
            $conn->query('ALTER TABLE items ADD COLUMN entered_at DATETIME NULL');
        }
    }
}

if (!function_exists('dei_ensure_csrf_token')) {
    function dei_ensure_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('dei_validate_csrf')) {
    function dei_validate_csrf(string $postedToken): bool
    {
        return $postedToken !== ''
            && !empty($_SESSION['csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], $postedToken);
    }
}

if (!function_exists('dei_notify_owner_entry_done')) {
    function dei_notify_owner_entry_done(VcDb $conn, int $actorId, ?array $entryInfo): void
    {
        if ($entryInfo === null || $entryInfo === []) {
            return;
        }

        $ownerId = (int) ($entryInfo['created_by'] ?? 0);

        if ($ownerId <= 0 || $ownerId === $actorId) {
            return;
        }

        // خطاف إشعار — يُفعّل لاحقاً عند ربط نظام الإشعارات
    }
}

if (!function_exists('dei_handle_mark_entered')) {
    function dei_handle_mark_entered(VcDb $conn, int $userId, bool $isAdmin): void
    {
        if (!dei_validate_csrf((string) ($_POST['csrf_token'] ?? ''))) {
            die('طلب غير صالح');
        }

        $batchId = trim((string) ($_POST['batch_id'] ?? ''));

        if ($batchId === '') {
            return;
        }

        $scope = dei_get_user_page_scope($conn, $userId, 'data_entry_items');
        $scopedIds = vcGetScopedUserIds($conn, $userId, $scope, $isAdmin);

        $entryInfo = null;
        $stmtInfo = $conn->prepare('
            SELECT
                batch_id,
                MAX(supplier_name) AS supplier_name,
                MAX(created_by) AS created_by,
                COUNT(*) AS items_count
            FROM items
            WHERE batch_id = ?
            GROUP BY batch_id
            LIMIT 1
        ');

        if ($stmtInfo) {
            $stmtInfo->bind_param('s', $batchId);
            $stmtInfo->execute();
            $entryInfo = $stmtInfo->get_result()->fetch_assoc();
            $stmtInfo->close();
        }

        if ($entryInfo === null || $entryInfo === []) {
            return;
        }

        if (!empty($scopedIds) && !vcIsUserInScope((int) ($entryInfo['created_by'] ?? 0), $scopedIds)) {
            http_response_code(403);
            die('غير مصرح لتسجيل إدخال هذه الدفعة');
        }

        $stmt = $conn->prepare('
            UPDATE items
            SET entry_done = 1,
                entered_by = ?,
                entered_at = NOW()
            WHERE batch_id = ?
            AND status = \'approved\'
            AND (entry_done IS NULL OR entry_done = 0)
        ');

        if (!$stmt) {
            return;
        }

        $stmt->bind_param('is', $userId, $batchId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            dei_notify_owner_entry_done($conn, $userId, $entryInfo);
        }

        header('Location: data_entry_items.php?done=1');
        exit();
    }
}

if (!function_exists('dei_handle_delete_batch')) {
    function dei_handle_delete_batch(VcDb $conn, bool $isAdmin): void
    {
        if (!dei_validate_csrf((string) ($_POST['csrf_token'] ?? ''))) {
            die('طلب غير صالح');
        }

        if (!$isAdmin) {
            http_response_code(403);
            die('❌ ليس لديك صلاحية حذف دفعات الأصناف');
        }

        $batchId = trim((string) ($_POST['batch_id'] ?? ''));

        if ($batchId === '') {
            return;
        }

        $conn->begin_transaction();

        try {
            if (
                vcColumnExists($conn, 'approval_withdrawals', 'target_type')
                && vcColumnExists($conn, 'approval_withdrawals', 'target_id')
            ) {
                $stmtWithdrawals = $conn->prepare('
                    DELETE FROM approval_withdrawals
                    WHERE target_type = \'items\'
                    AND target_id = ?
                ');

                if ($stmtWithdrawals) {
                    $stmtWithdrawals->bind_param('s', $batchId);
                    $stmtWithdrawals->execute();
                    $stmtWithdrawals->close();
                }
            }

            $stmtItems = $conn->prepare('DELETE FROM items WHERE batch_id = ?');

            if (!$stmtItems) {
                throw new RuntimeException('تعذر تجهيز حذف الدفعة');
            }

            $stmtItems->bind_param('s', $batchId);
            $stmtItems->execute();
            $stmtItems->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            vcFailWithLog('حدث خطأ أثناء حذف دفعة الأصناف.', $e);
        }

        header('Location: data_entry_items.php');
        exit();
    }
}

if (!function_exists('dei_parse_entry_filter')) {
    function dei_parse_entry_filter(string $value): string
    {
        $value = trim($value);

        return in_array($value, ['', 'done', 'pending'], true) ? $value : '';
    }
}

if (!function_exists('dei_format_date')) {
    function dei_format_date($primary, $fallback = null): string
    {
        foreach ([$primary, $fallback] as $value) {
            $value = trim((string) $value);

            if ($value === '' || $value === '0000-00-00') {
                continue;
            }

            $ts = strtotime($value);

            if ($ts) {
                return date('Y-m-d', $ts);
            }
        }

        return '-';
    }
}

if (!function_exists('dei_fetch_batches')) {
    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   done_count: int,
     *   pending_count: int,
     *   total_requests: int,
     *   page: int,
     *   total_pages: int
     * }
     */
    function dei_fetch_batches(VcDb $conn, string $search, string $entryFilter): array
    {
        $fromWhere = '
            FROM items i
            LEFT JOIN users creator ON creator.id = i.created_by
            LEFT JOIN users entry_user ON entry_user.id = i.entered_by
            WHERE i.status = \'approved\'
        ';

        $params = [];
        $types = '';

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

        $groupBase = '
            SELECT i.batch_id
            ' . $fromWhere . '
            GROUP BY
                i.batch_id,
                i.created_by,
                creator.username,
                entry_user.username
        ';

        $doneInner = $groupBase . ' HAVING MAX(i.entry_done) = 1';
        $pendingInner = $groupBase . ' HAVING MAX(i.entry_done) IS NULL OR MAX(i.entry_done) = 0';

        $doneCount = vcPaginationCountGrouped($conn, $doneInner, $params, $types);
        $pendingCount = vcPaginationCountGrouped($conn, $pendingInner, $params, $types);
        $totalRequests = $doneCount + $pendingCount;

        $havingSql = '';

        if ($entryFilter === 'done') {
            $havingSql = 'HAVING MAX(i.entry_done) = 1';
        } elseif ($entryFilter === 'pending') {
            $havingSql = 'HAVING MAX(i.entry_done) IS NULL OR MAX(i.entry_done) = 0';
        }

        $totalRows = $totalRequests;

        if ($entryFilter === 'done') {
            $totalRows = $doneCount;
        } elseif ($entryFilter === 'pending') {
            $totalRows = $pendingCount;
        }

        $pg = vcPaginationState();
        $totalPages = vcPaginationTotalPages($totalRows, $pg['per_page']);
        $page = min($pg['page'], $totalPages);

        $sql = '
            SELECT
                i.batch_id,
                MAX(i.supplier_name) AS supplier_name,
                i.created_by,
                creator.username AS creator_username,
                MAX(i.created_at) AS created_at,
                MAX(i.approved_at) AS approved_at,
                MAX(i.entry_done) AS entry_done,
                MAX(i.entered_by) AS entered_by,
                MAX(i.entered_at) AS entered_at,
                entry_user.username AS entered_username
            ' . $fromWhere . '
            GROUP BY
                i.batch_id,
                i.created_by,
                creator.username,
                entry_user.username
            ' . $havingSql . '
            ORDER BY MAX(i.approved_at) DESC, MAX(i.created_at) DESC, i.batch_id DESC
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
            'done_count' => $doneCount,
            'pending_count' => $pendingCount,
            'total_requests' => $totalRequests,
            'page' => $page,
            'total_pages' => $totalPages,
        ];
    }
}
