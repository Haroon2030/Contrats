<?php

if (!function_exists('ma_e')) {
    function ma_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ma_ensureUserColumns')) {
    function ma_ensureUserColumns(VcDb $conn): void
    {
        $vcAppEnv = strtolower(trim((string) (vcEnv('APP_ENV', 'local') ?? 'local')));

        if ($vcAppEnv !== 'local') {
            return;
        }

        if (!vcColumnExists($conn, 'users', 'session_version')) {
            $conn->query('ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 1');
        }

        if (!vcColumnExists($conn, 'users', 'last_password_change')) {
            $conn->query('ALTER TABLE users ADD COLUMN last_password_change DATETIME NULL');
        }
    }
}

if (!function_exists('ma_fetchCurrentUser')) {
    function ma_fetchCurrentUser(VcDb $conn, int $uid): ?array
    {
        $stmt = $conn->prepare('
            SELECT
                id,
                username,
                password,
                role,
                is_admin,
                job_role,
                is_supervisor,
                manager_id,
                session_version,
                last_password_change
            FROM users
            WHERE id = ?
            LIMIT 1
        ');

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('ma_isAdminAccount')) {
    function ma_isAdminAccount(array $user): bool
    {
        return (int) ($user['is_admin'] ?? 0) === 1
            || ($user['role'] ?? '') === 'admin';
    }
}

if (!function_exists('ma_firstLetter')) {
    function ma_firstLetter(string $username): string
    {
        if ($username === '') {
            return '؟';
        }

        return function_exists('mb_substr')
            ? mb_substr($username, 0, 1, 'UTF-8')
            : substr($username, 0, 1);
    }
}

if (!function_exists('ma_loadPaymentManagerIds')) {
    function ma_loadPaymentManagerIds(VcDb $conn): array
    {
        $ids = [
            'finance_manager' => 0,
            'food_section_manager' => 0,
            'non_food_section_manager' => 0,
        ];

        try {
            $settingsRes = $conn->query("
                SELECT setting_key, user_id
                FROM payment_approval_settings
                WHERE setting_key IN ('finance_manager','food_section_manager','non_food_section_manager')
            ");

            if ($settingsRes) {
                while ($settingRow = $settingsRes->fetch_assoc()) {
                    $key = (string) ($settingRow['setting_key'] ?? '');
                    if (isset($ids[$key])) {
                        $ids[$key] = (int) ($settingRow['user_id'] ?? 0);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('ma_loadPaymentManagerIds: ' . $e->getMessage());
        }

        if ($ids['finance_manager'] <= 0) {
            $ids['finance_manager'] = 19;
        }

        return $ids;
    }
}

if (!function_exists('ma_resolveRoleLabel')) {
    function ma_resolveRoleLabel(VcDb $conn, int $uid, array $user): string
    {
        $roleText = 'مستخدم';

        try {
            $managerIds = ma_loadPaymentManagerIds($conn);
            $financeManagerId = $managerIds['finance_manager'];
            $foodSectionManagerId = $managerIds['food_section_manager'];
            $nonFoodSectionManagerId = $managerIds['non_food_section_manager'];

            $jobRole = (string) ($user['job_role'] ?? 'user');
            $role = (string) ($user['role'] ?? 'user');
            $isAdminAccount = ma_isAdminAccount($user) || $jobRole === 'admin';

            if ($jobRole === 'finance_manager' || $uid === $financeManagerId) {
                return 'مدير مالي';
            }

            if ($isAdminAccount) {
                return 'أدمن';
            }

            if ($jobRole === 'commercial_manager') {
                return 'مدير تجاري';
            }

            if ($uid === $foodSectionManagerId && $uid === $nonFoodSectionManagerId) {
                return 'مدير قسم غذائي ولا غذائي';
            }

            if ($uid === $foodSectionManagerId) {
                return 'مدير قسم غذائي';
            }

            if ($uid === $nonFoodSectionManagerId) {
                return 'مدير قسم لا غذائي';
            }

            if ($jobRole === 'section_manager') {
                return 'مدير قسم';
            }

            if ($jobRole === 'accountant') {
                return 'محاسب';
            }
        } catch (Throwable $e) {
            error_log('ma_resolveRoleLabel: ' . $e->getMessage());
        }

        return $roleText;
    }
}

if (!function_exists('ma_countAccessiblePages')) {
    function ma_countAccessiblePages(VcDb $conn, int $uid, bool $isAdmin): int
    {
        if ($isAdmin) {
            $q = $conn->query('SELECT COUNT(*) AS c FROM pages WHERE status = 1');

            return $q ? (int) ($q->fetch_assoc()['c'] ?? 0) : 0;
        }

        $stmtPages = $conn->prepare('
            SELECT COUNT(*) AS c
            FROM user_permissions up
            JOIN pages p ON p.id = up.page_id
            WHERE up.user_id = ?
            AND p.status = 1
        ');

        if (!$stmtPages) {
            return 0;
        }

        $stmtPages->bind_param('i', $uid);
        $stmtPages->execute();
        $count = (int) ($stmtPages->get_result()->fetch_assoc()['c'] ?? 0);
        $stmtPages->close();

        return $count;
    }
}

if (!function_exists('ma_formatLastPasswordChange')) {
    function ma_formatLastPasswordChange($value): string
    {
        $lastPasswordChange = trim((string) $value);

        if ($lastPasswordChange === '') {
            return 'غير متاح';
        }

        $ts = strtotime($lastPasswordChange);

        return $ts ? date('Y-m-d H:i', $ts) : 'غير متاح';
    }
}

if (!function_exists('ma_ensureCsrfToken')) {
    function ma_ensureCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('ma_rotateCsrfToken')) {
    function ma_rotateCsrfToken(): string
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('ma_validateCsrf')) {
    function ma_validateCsrf(string $postedToken): bool
    {
        return $postedToken !== ''
            && !empty($_SESSION['csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], $postedToken);
    }
}

if (!function_exists('ma_handlePasswordChange')) {
    /**
     * @return array{success: string, error: string, user: array}
     */
    function ma_handlePasswordChange(VcDb $conn, int $uid, array $currentUser): array
    {
        $result = [
            'success' => '',
            'error' => '',
            'user' => $currentUser,
        ];

        if (!ma_validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $result['error'] = 'الطلب غير صالح، أعد المحاولة.';

            return $result;
        }

        $currentPassword = trim((string) ($_POST['current_password'] ?? ''));
        $newPassword = trim((string) ($_POST['new_password'] ?? ''));
        $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));

        if ($currentPassword === '') {
            $result['error'] = 'اكتب كلمة المرور الحالية.';

            return $result;
        }

        if ($newPassword === '') {
            $result['error'] = 'اكتب كلمة المرور الجديدة.';

            return $result;
        }

        if (mb_strlen($newPassword, 'UTF-8') < 6) {
            $result['error'] = 'كلمة المرور الجديدة لازم تكون 6 أحرف على الأقل.';

            return $result;
        }

        if ($newPassword !== $confirmPassword) {
            $result['error'] = 'تأكيد كلمة المرور غير مطابق.';

            return $result;
        }

        $storedPassword = (string) ($currentUser['password'] ?? '');

        if (!password_verify($currentPassword, $storedPassword)) {
            $result['error'] = 'كلمة المرور الحالية غير صحيحة.';

            return $result;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmtUpdate = $conn->prepare('
            UPDATE users
            SET password = ?,
                session_version = session_version + 1,
                last_password_change = NOW()
            WHERE id = ?
        ');

        if (!$stmtUpdate) {
            $result['error'] = 'تعذر تغيير كلمة المرور.';

            return $result;
        }

        $stmtUpdate->bind_param('si', $newHash, $uid);

        if (!$stmtUpdate->execute()) {
            error_log('VendorCore my_account password error: ' . $stmtUpdate->error);
            $stmtUpdate->close();
            $result['error'] = 'تعذر تغيير كلمة المرور.';

            return $result;
        }

        $stmtUpdate->close();

        $stmtVersion = $conn->prepare('SELECT session_version, last_password_change FROM users WHERE id = ? LIMIT 1');

        if ($stmtVersion) {
            $stmtVersion->bind_param('i', $uid);
            $stmtVersion->execute();
            $fresh = $stmtVersion->get_result()->fetch_assoc();
            $stmtVersion->close();

            if ($fresh) {
                $_SESSION['session_version'] = (int) ($fresh['session_version'] ?? 1);
                $currentUser['session_version'] = $_SESSION['session_version'];
                $currentUser['last_password_change'] = $fresh['last_password_change'] ?? date('Y-m-d H:i:s');
                $result['user'] = $currentUser;
            }
        }

        ma_rotateCsrfToken();
        $result['success'] = 'تم تغيير كلمة المرور بنجاح.';

        return $result;
    }
}
