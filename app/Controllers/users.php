<?php
require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';

/*
    users.php
    إدارة المستخدمين
    - حماية auth.php
    - Admin only
    - CSRF
    - حذف آمن POST بدل GET
    - Prepared Statements
    - إصلاح الستايل والكروت نفس المقاس
    - حفظ الباسورد Hash
    - عند تغيير باسورد أي مستخدم: يتم زيادة session_version لإجباره على تسجيل خروج عند أول طلب جديد
    - إدارة الصلاحيات خاص / فريقه / الكل
    - ستايل موحد مع VendorCore
*/

date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizeSaudiWhatsappNumber($phone): string {
    $phone = trim((string)$phone);

    // إزالة أي رموز أو مسافات، ونحفظ الرقم بصيغة دولية جاهزة للإرسال عبر واتساب
    $phone = preg_replace('/\D+/', '', $phone);

    if ($phone === '') {
        return '';
    }

    // 009665xxxxxxxx => 9665xxxxxxxx
    if (strpos($phone, '00966') === 0) {
        $phone = '966' . substr($phone, 5);
    }

    // 05xxxxxxxx => 9665xxxxxxxx
    if (preg_match('/^05\d{8}$/', $phone)) {
        return '966' . substr($phone, 1);
    }

    // 5xxxxxxxx => 9665xxxxxxxx
    if (preg_match('/^5\d{8}$/', $phone)) {
        return '966' . $phone;
    }

    // 9665xxxxxxxx كما هي
    if (preg_match('/^9665\d{8}$/', $phone)) {
        return $phone;
    }

    return $phone;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* منع الكاش */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$uid = (int)($_SESSION['user_id'] ?? 0);

if ($uid <= 0) {
    header("Location: login.php");
    exit();
}

/*
    في الإنتاج: التعديلات على الجداول عبر database/migrations/ (تُشغَّل تلقائياً عند النشر).
    محلياً (SQLite): نُبقي ALTER التلقائي للتوافق مع قواعد قديمة.
*/
$vcAppEnv = strtolower(trim((string) (vcEnv('APP_ENV', 'local') ?? 'local')));
$hasIsAdminColumn = vcColumnExists($conn, 'users', 'is_admin');
if ($vcAppEnv !== 'production') {
    if (!vcColumnExists($conn, 'users', 'session_version')) {
        $conn->query("ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 1");
    }

    if (!vcColumnExists($conn, 'users', 'last_password_change')) {
        $conn->query("ALTER TABLE users ADD COLUMN last_password_change DATETIME NULL");
    }

    if (!vcColumnExists($conn, 'users', 'whatsapp_number')) {
        $conn->query("ALTER TABLE users ADD COLUMN whatsapp_number VARCHAR(30) NULL");
    }

    if (!vcColumnExists($conn, 'users', 'whatsapp_enabled')) {
        $conn->query("ALTER TABLE users ADD COLUMN whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 1");
    }

    if (!vcColumnExists($conn, 'users', 'is_active')) {
        $conn->query("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }

    if (!vcColumnExists($conn, 'users', 'manager_id')) {
        $conn->query("ALTER TABLE users ADD COLUMN manager_id INT NULL DEFAULT NULL AFTER role");
    }

    if (!vcColumnExists($conn, 'users', 'is_supervisor')) {
        $conn->query("ALTER TABLE users ADD COLUMN is_supervisor TINYINT(1) NOT NULL DEFAULT 0 AFTER manager_id");
    }

    if (!vcColumnExists($conn, 'users', 'job_role')) {
        $conn->query("ALTER TABLE users ADD COLUMN job_role ENUM('user','section_manager','finance_manager','commercial_manager','accountant','admin') NOT NULL DEFAULT 'user' AFTER role");
        $conn->query("UPDATE users SET job_role = CASE WHEN role = 'admin' OR is_admin = 1 THEN 'admin' WHEN is_supervisor = 1 THEN 'section_manager' ELSE 'user' END");
    } else {
        @$conn->query("ALTER TABLE users MODIFY job_role ENUM('user','section_manager','finance_manager','commercial_manager','accountant','admin') NOT NULL DEFAULT 'user'");
    }

    if ($hasIsAdminColumn) {
        $conn->query("UPDATE users SET role = 'admin', is_admin = 1 WHERE job_role = 'commercial_manager'");
    } else {
        $conn->query("UPDATE users SET role = 'admin' WHERE job_role = 'commercial_manager'");
    }
}

/* CSRF */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* Admin only */
$stmtAdmin = $conn->prepare("SELECT id, username, role, " . ($hasIsAdminColumn ? "is_admin" : "0 AS is_admin") . ", session_version FROM users WHERE id = ? LIMIT 1");
$stmtAdmin->bind_param("i", $uid);
$stmtAdmin->execute();
$currentUser = $stmtAdmin->get_result()->fetch_assoc();
$stmtAdmin->close();

$isAdmin = !empty($currentUser) && (
    (int)($currentUser['is_admin'] ?? 0) === 1 ||
    ($currentUser['role'] ?? '') === 'admin'
);

if (!$isAdmin) {
    http_response_code(403);
    die("❌ ليس لديك صلاحية");
}

/* تثبيت session_version الحالي للأدمن لو مش موجود في السيشن */
if (!isset($_SESSION['session_version'])) {
    $_SESSION['session_version'] = (int)($currentUser['session_version'] ?? 1);
}

$success = "";
$error = "";

/* تعطيل / تفعيل المستخدم بدل الحذف النهائي */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['deactivate_user','activate_user'], true)) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    $targetId = (int)($_POST['user_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');

    if ($targetId <= 0) {
        $error = "رقم المستخدم غير صحيح.";
    } elseif ($targetId === $uid) {
        $error = "لا يمكن تعطيل حسابك الحالي.";
    } else {
        $newActive = ($action === 'activate_user') ? 1 : 0;

        /* نزوّد session_version علشان لو المستخدم المعطل فاتح سيشن يطلع فورًا */
        $stmt = $conn->prepare("UPDATE users SET is_active = ?, session_version = session_version + 1 WHERE id = ? LIMIT 1");
        $stmt->bind_param("ii", $newActive, $targetId);
        $stmt->execute();
        $stmt->close();

        header("Location: users.php?" . ($newActive ? "activated=1" : "deactivated=1"));
        exit();
    }
}

/* إضافة / تعديل */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_user') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    $id = (int)($_POST['id'] ?? 0);
    $username = trim((string)($_POST['username'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));
    $account_type = trim((string)($_POST['account_type'] ?? $_POST['role'] ?? 'user'));
    if (!in_array($account_type, ['user', 'section_manager', 'finance_manager', 'commercial_manager', 'accountant', 'admin'], true)) {
        $account_type = 'user';
    }
    $role = in_array($account_type, ['admin', 'commercial_manager'], true) ? 'admin' : 'user';
    $job_role = $account_type;
    $whatsapp_number = normalizeSaudiWhatsappNumber($_POST['whatsapp_number'] ?? '');
    $whatsapp_enabled = isset($_POST['whatsapp_enabled']) ? 1 : 0;
    $manager_id = (int)($_POST['manager_id'] ?? 0);
    $manager_id = ($manager_id > 0) ? $manager_id : null;
    $is_supervisor = in_array($account_type, ['section_manager', 'finance_manager', 'commercial_manager'], true) ? 1 : 0;
    $permissions = $_POST['permissions'] ?? [];

    if ($username === '') {
        $error = "اسم المستخدم مطلوب.";
    }

    if ($id === 0 && $password === '') {
        $error = "كلمة المرور مطلوبة عند إضافة مستخدم جديد.";
    }

    if ($id > 0 && $manager_id !== null && $manager_id === $id) {
        $error = "لا يمكن أن يكون المستخدم مديرًا لنفسه.";
    }

    if ($error === '' && $manager_id !== null) {
        $stmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND (role = 'admin' OR is_admin = 1 OR job_role IN ('section_manager','finance_manager','commercial_manager','admin') OR is_supervisor = 1)
            LIMIT 1
        ");
        $stmt->bind_param("i", $manager_id);
        $stmt->execute();
        $managerCheck = $stmt->get_result();
        $stmt->close();

        if (!$managerCheck || $managerCheck->num_rows === 0) {
            $error = "المدير المباشر يجب أن يكون مدير قسم أو مدير مالي أو مدير تجاري أو أدمن.";
        }
    }

    if ($error === '') {
        $stmt = $conn->prepare("SELECT id FROM users WHERE TRIM(username) = ? AND id != ? LIMIT 1");
        $stmt->bind_param("si", $username, $id);
        $stmt->execute();
        $dup = $stmt->get_result();
        $stmt->close();

        if ($dup && $dup->num_rows > 0) {
            $error = "اسم المستخدم موجود بالفعل.";
        }
    }

    if ($error === '') {

        $isAdminValue = ($role === 'admin') ? 1 : 0;
        $passwordChanged = false;

        if ($id === 0) {

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $passwordChanged = true;

            if ($hasIsAdminColumn) {
                $stmt = $conn->prepare("
                    INSERT INTO users (username, password, role, job_role, is_admin, manager_id, is_supervisor, session_version, last_password_change, whatsapp_number, whatsapp_enabled)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?)
                ");
                $stmt->bind_param("ssssiiisi", $username, $hashedPassword, $role, $job_role, $isAdminValue, $manager_id, $is_supervisor, $whatsapp_number, $whatsapp_enabled);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO users (username, password, role, job_role, manager_id, is_supervisor, session_version, last_password_change, whatsapp_number, whatsapp_enabled)
                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?)
                ");
                $stmt->bind_param("ssssiisi", $username, $hashedPassword, $role, $job_role, $manager_id, $is_supervisor, $whatsapp_number, $whatsapp_enabled);
            }

            $stmt->execute();
            $userId = (int)$stmt->insert_id;
            $stmt->close();

            /*
                إرسال واتساب للمستخدم الجديد ببيانات الدخول.
                ملحوظة: الإرسال يتم فقط لو رقم الواتساب موجود ومفعل للمستخدم.
            */
            try {
                $whatsappHelperPath = VC_HELPERS . '/whatsapp_helper.php';
                if ($userId > 0 && file_exists($whatsappHelperPath)) {
                    require_once $whatsappHelperPath;
                    if (function_exists('vcSendWhatsappNotification')) {
                        $loginLink = 'login.php';
                        $welcomeTitle = 'تم إنشاء حساب جديد على VendorCore';
                        $welcomeMessage = "مرحبًا " . $username . "
"
                            . "تم إنشاء حساب لك على نظام VendorCore.

"
                            . "اسم المستخدم: " . $username . "
"
                            . "كلمة المرور: " . $password . "

"
                            . "يمكنك تسجيل الدخول ثم تغيير كلمة المرور من صفحتك الشخصية.";

                        vcSendWhatsappNotification(
                            $conn,
                            $userId,
                            $welcomeTitle,
                            $welcomeMessage,
                            $loginLink,
                            'new_user_account',
                            $userId,
                            0
                        );
                    }
                }
            } catch (Throwable $e) {
                error_log('VendorCore new user whatsapp error: ' . $e->getMessage());
            }

        } else {

            $userId = $id;

            if ($password !== '') {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $passwordChanged = true;

                /*
                    session_version = session_version + 1
                    ده اللي بيخلي المستخدم يتعمله logout من أي سيشن قديمة بمجرد ما auth.php يشتغل في أي صفحة.
                */
                if ($hasIsAdminColumn) {
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET username = ?,
                            password = ?,
                            role = ?,
                            job_role = ?,
                            is_admin = ?,
                            manager_id = ?,
                            is_supervisor = ?,
                            whatsapp_number = ?,
                            whatsapp_enabled = ?,
                            session_version = session_version + 1,
                            last_password_change = NOW()
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param("ssssiiisii", $username, $hashedPassword, $role, $job_role, $isAdminValue, $manager_id, $is_supervisor, $whatsapp_number, $whatsapp_enabled, $id);
                } else {
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET username = ?,
                            password = ?,
                            role = ?,
                            job_role = ?,
                            manager_id = ?,
                            is_supervisor = ?,
                            whatsapp_number = ?,
                            whatsapp_enabled = ?,
                            session_version = session_version + 1,
                            last_password_change = NOW()
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param("ssssiisii", $username, $hashedPassword, $role, $job_role, $manager_id, $is_supervisor, $whatsapp_number, $whatsapp_enabled, $id);
                }

            } else {

                if ($hasIsAdminColumn) {
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET username = ?,
                            role = ?,
                            job_role = ?,
                            is_admin = ?,
                            manager_id = ?,
                            is_supervisor = ?,
                            whatsapp_number = ?,
                            whatsapp_enabled = ?
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param("sssiiisii", $username, $role, $job_role, $isAdminValue, $manager_id, $is_supervisor, $whatsapp_number, $whatsapp_enabled, $id);
                } else {
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET username = ?,
                            role = ?,
                            job_role = ?,
                            manager_id = ?,
                            is_supervisor = ?,
                            whatsapp_number = ?,
                            whatsapp_enabled = ?
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param("sssiisii", $username, $role, $job_role, $manager_id, $is_supervisor, $whatsapp_number, $whatsapp_enabled, $id);
                }
            }

            $stmt->execute();
            $stmt->close();

            /* لو الأدمن غير باسورد نفسه، نحدث السيشن الحالية عشان ما يخرجش فورًا */
            if ($passwordChanged && $id === $uid) {
                $stmtCurrent = $conn->prepare("SELECT session_version FROM users WHERE id = ? LIMIT 1");
                $stmtCurrent->bind_param("i", $uid);
                $stmtCurrent->execute();
                $v = $stmtCurrent->get_result()->fetch_assoc();
                $stmtCurrent->close();

                $_SESSION['session_version'] = (int)($v['session_version'] ?? 1);
            }

            $stmt = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }

        /*
            صلاحيات المستخدم:
            حتى لو الحساب أدمن، بنحفظ الصفحات المحددة فقط.
            ده علشان الأدمن مايبقاش مفتوح له كل الصفحات تلقائيًا من شاشة الصلاحيات.
        */
        foreach ($permissions as $pageId => $data) {

            $pageId = (int)$pageId;

            if ($pageId <= 0 || !isset($data['view'])) {
                continue;
            }

            $rawScope = (isset($data['scope']) && in_array($data['scope'], ['own', 'team', 'all'], true)) ? $data['scope'] : 'own';

            /*
                سجل النشاط ليس للمستخدم العادي نهائيًا.
                حتى لو تم تحديده من الواجهة أو بقيت له صلاحية قديمة، لا نحفظها للمستخدم العادي.
            */
            if (in_array($job_role, ['user','accountant'], true)) {
                $stmtPageCheck = $conn->prepare("SELECT name, title FROM pages WHERE id = ? LIMIT 1");
                $stmtPageCheck->bind_param("i", $pageId);
                $stmtPageCheck->execute();
                $pageCheck = $stmtPageCheck->get_result()->fetch_assoc();
                $stmtPageCheck->close();

                $checkName = trim((string)($pageCheck['name'] ?? ''));
                $checkTitle = mb_strtolower(trim((string)($pageCheck['title'] ?? '')), 'UTF-8');

                if ($checkName === 'activity_log' || mb_strpos($checkTitle, 'سجل النشاط') !== false) {
                    continue;
                }
            }

            // ضبط النطاق حسب نوع المستخدم حتى لو المتصفح أرسل قيمة غير مسموحة.
            // تقارير العقود والأصناف تحتاج نطاق حتى للمحاسب: خاص / فريقه / الكل.
            $scopePageName = '';
            $scopePageTitle = '';
            $stmtScopePage = $conn->prepare("SELECT name, title FROM pages WHERE id = ? LIMIT 1");
            if ($stmtScopePage) {
                $stmtScopePage->bind_param("i", $pageId);
                $stmtScopePage->execute();
                $scopePageRow = $stmtScopePage->get_result()->fetch_assoc();
                $stmtScopePage->close();
                $scopePageName = trim((string)($scopePageRow['name'] ?? ''));
                $scopePageTitle = mb_strtolower(trim((string)($scopePageRow['title'] ?? '')), 'UTF-8');
            }
            $isReportScopePageForAccountant = (
                in_array($scopePageName, ['contract_report', 'item_report'], true)
                || mb_strpos($scopePageTitle, 'تقرير كل العقود') !== false
                || mb_strpos($scopePageTitle, 'تقرير الأصناف') !== false
                || mb_strpos($scopePageTitle, 'تقرير الاصناف') !== false
            );

            if ($job_role === 'accountant' && $isReportScopePageForAccountant) {
                $scope = in_array($rawScope, ['own', 'team', 'all'], true) ? $rawScope : 'own';
            } elseif ($job_role === 'user') {
                $scope = 'own';
            } elseif ($job_role === 'accountant') {
                $scope = 'own';
            } elseif ($job_role === 'section_manager') {
                $scope = in_array($rawScope, ['own', 'team', 'all'], true) ? $rawScope : 'own';
            } elseif (in_array($job_role, ['finance_manager', 'commercial_manager', 'admin'], true)) {
                $scope = $rawScope;
            } else {
                $scope = $rawScope;
            }

            $stmt = $conn->prepare("
                INSERT INTO user_permissions (user_id, page_id, scope)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param("iis", $userId, $pageId, $scope);
            $stmt->execute();
            $stmt->close();
        }

        header("Location: users.php?saved=1" . ($passwordChanged ? "&pass_changed=1" : ""));
        exit();
    }
}

/* جلب البيانات */
$usersRes = $conn->query("
    SELECT id, username, role, job_role, " . ($hasIsAdminColumn ? "is_admin" : "0 AS is_admin") . ", manager_id, is_supervisor, session_version, last_password_change, whatsapp_number, whatsapp_enabled, is_active
    FROM users
    ORDER BY is_active DESC,
        CASE COALESCE(NULLIF(job_role, ''), 'user')
            WHEN 'admin' THEN 1
            WHEN 'commercial_manager' THEN 2
            WHEN 'finance_manager' THEN 3
            WHEN 'section_manager' THEN 4
            WHEN 'accountant' THEN 5
            ELSE 6
        END,
        username ASC
");

$pagesRes = $conn->query("
    SELECT id, name, title, description
    FROM pages
    WHERE status = 1
    ORDER BY sort_order ASC, id ASC
");

$users = [];
while ($row = $usersRes->fetch_assoc()) {
    $users[] = $row;
}

$pages = [];
while ($row = $pagesRes->fetch_assoc()) {
    $pages[] = $row;
}

$managerOptions = array_values(array_filter($users, function($oneUser) {
    $jr = (string)($oneUser['job_role'] ?? 'user');
    return $jr === 'section_manager'
        || $jr === 'commercial_manager'
        || $jr === 'finance_manager'
        || $jr === 'admin'
        || (string)($oneUser['role'] ?? '') === 'admin'
        || (int)($oneUser['is_admin'] ?? 0) === 1
        || (int)($oneUser['is_supervisor'] ?? 0) === 1;
}));
$userNamesById = [];
foreach ($users as $oneUser) {
    $userNamesById[(int)$oneUser['id']] = (string)$oneUser['username'];
}

$userStats = [
    'total' => count($users),
    'active' => 0,
    'inactive' => 0,
    'managers' => 0,
];
foreach ($users as $oneUser) {
    if ((int)($oneUser['is_active'] ?? 1) === 1) {
        $userStats['active']++;
    } else {
        $userStats['inactive']++;
    }
    $jr = (string)($oneUser['job_role'] ?? 'user');
    if (in_array($jr, ['admin', 'commercial_manager', 'finance_manager', 'section_manager'], true)
        || (int)($oneUser['is_supervisor'] ?? 0) === 1) {
        $userStats['managers']++;
    }
}

function usersRoleKey(array $u): string {
    $jobRole = (string)($u['job_role'] ?? 'user');
    if (($u['role'] ?? '') === 'admin' || (int)($u['is_admin'] ?? 0) === 1) {
        if ($jobRole !== 'commercial_manager') {
            $jobRole = 'admin';
        }
    }

    return $jobRole;
}

function usersRoleLabel(string $jobRole): string {
    $roleMap = [
        'user' => 'مستخدم',
        'section_manager' => 'مدير قسم',
        'commercial_manager' => 'مدير تجاري',
        'finance_manager' => 'مدير مالي',
        'accountant' => 'محاسب',
        'admin' => 'أدمن',
    ];

    return $roleMap[$jobRole] ?? 'مستخدم';
}

/* بيانات عرض كروت الصلاحيات */
function deptLabel(string $key): string {
    $map = [
        'purchases' => 'المشتريات',
        'accounts' => 'الحسابات',
        'marketing' => 'التسويق',
        'operations' => 'التشغيل',
        'data_entry' => 'إدخال البيانات',
        'admin' => 'الأدمن',
        'reviewers' => 'المراجعين الموثقين',
        'system' => 'ذكاء العقود وصحة النظام',
        'general' => 'عام'
    ];

    return $map[$key] ?? $key;
}

function deptClass(string $key): string {
    $allowed = ['purchases','accounts','marketing','operations','data_entry','admin','reviewers','system','general'];
    return in_array($key, $allowed, true) ? $key : 'general';
}

function pagePermissionMeta(array $p): array {
    $name = trim((string)($p['name'] ?? ''));
    $title = trim((string)($p['title'] ?? ''));

    $meta = [
        'title' => $title,
        'departments' => ['general'],
        'restricted' => false,
        'note' => '',
        'group' => 'general',
        'order' => 999
    ];

    $hay = mb_strtolower($name . ' ' . $title, 'UTF-8');

    /*
        صفحات الإدارة والمتابعة العليا:
        لا تحتاج خاص / الكل، مجرد فتح الصفحة = صلاحية كاملة.
    */
    if ($name === 'activity_log' || mb_strpos($hay, 'سجل النشاط') !== false) {
        $meta['title'] = 'سجل النشاط';
        $meta['departments'] = ['admin'];
        $meta['restricted'] = true;
        $meta['note'] = 'صلاحية كاملة للصفحة';
        $meta['group'] = 'admin';
        $meta['order'] = 60;
        return $meta;
    }

    if (
        in_array($name, ['trusted_reviewers', 'verified_reviewers', 'reviewers'], true) ||
        mb_strpos($hay, 'المراجعين الموثقين') !== false ||
        mb_strpos($hay, 'المراجعين المعتمدين') !== false
    ) {
        $meta['title'] = 'المراجعين الموثقين';
        $meta['departments'] = ['admin', 'reviewers'];
        $meta['restricted'] = true;
        $meta['note'] = 'صلاحية كاملة للصفحة';
        $meta['group'] = 'admin';
        $meta['order'] = 50;
        return $meta;
    }

    if (
        in_array($name, ['system_check', 'contract_ai', 'contracts_ai', 'ai_contracts', 'system_health'], true) ||
        mb_strpos($hay, 'ذكاء العقود') !== false ||
        mb_strpos($hay, 'صحة النظام') !== false ||
        mb_strpos($hay, 'فحص السيستم') !== false ||
        mb_strpos($hay, 'فحص النظام') !== false
    ) {
        $meta['title'] = $title !== '' ? $title : 'ذكاء العقود وصحة النظام';
        $meta['departments'] = ['admin', 'system'];
        $meta['restricted'] = true;
        $meta['note'] = 'صلاحية كاملة للصفحة';
        $meta['group'] = 'admin';
        $meta['order'] = 40;
        return $meta;
    }

    if ($name === 'users' || mb_strpos($hay, 'إدارة المستخدمين') !== false || mb_strpos($hay, 'ادارة المستخدمين') !== false) {
        $meta['title'] = 'إدارة المستخدمين';
        $meta['departments'] = ['admin'];
        $meta['restricted'] = true;
        $meta['group'] = 'admin';
        $meta['order'] = 30;
        return $meta;
    }

    if (
        in_array($name, ['items_admin', 'review_items'], true) ||
        mb_strpos($hay, 'مراجعة الأصناف') !== false ||
        mb_strpos($hay, 'مراجعه الاصناف') !== false ||
        mb_strpos($hay, 'مراجعة الاصناف') !== false
    ) {
        $meta['title'] = 'مراجعة الأصناف';
        $meta['departments'] = ['admin', 'reviewers'];
        $meta['restricted'] = true;
        $meta['note'] = 'صلاحية كاملة للصفحة';
        $meta['group'] = 'admin';
        $meta['order'] = 20;
        return $meta;
    }



    if ($name === 'add_payment_request' || mb_strpos($hay, 'طلب سداد جديد') !== false) {
        $meta['title'] = 'طلب سداد جديد';
        $meta['departments'] = ['accounts'];
        $meta['restricted'] = false;
        $meta['note'] = 'للمحاسب لإنشاء طلبات السداد';
        $meta['group'] = 'finance';
        $meta['order'] = 42;
        return $meta;
    }

    if ($name === 'payment_approvals' || mb_strpos($hay, 'اعتماد طلبات السداد') !== false) {
        $meta['title'] = 'اعتماد طلبات السداد';
        $meta['departments'] = ['accounts', 'purchases', 'admin'];
        $meta['restricted'] = false;
        $meta['note'] = 'المديرين للاعتماد — المحاسب يرى طلباته وحالة الرفض/الاعتماد';
        $meta['group'] = 'finance';
        $meta['order'] = 43;
        return $meta;
    }

    if ($name === 'payment_requests' || mb_strpos($hay, 'متابعة طلبات السداد') !== false) {
        $meta['title'] = 'متابعة طلبات السداد';
        $meta['departments'] = ['accounts'];
        $meta['restricted'] = false;
        $meta['note'] = 'معطلة حاليًا حسب اعتماد مسار الاعتماد والطباعة فقط';
        $meta['group'] = 'finance';
        $meta['order'] = 44;
        return $meta;
    }


    if ($name === 'contract_report' || mb_strpos($hay, 'تقرير كل العقود') !== false) {
        $meta['title'] = 'تقرير كل العقود';
        $meta['departments'] = ['accounts', 'purchases', 'admin'];
        $meta['restricted'] = false;
        $meta['note'] = 'المالية/المحاسب: الكل عند تفعيل الصفحة — مدير القسم: خاص أو فريقه أو الكل حسب الاختيار — المدير التجاري مثل الأدمن';
        $meta['group'] = 'finance';
        $meta['order'] = 30;
        return $meta;
    }

    if ($name === 'item_report' || mb_strpos($hay, 'تقرير الأصناف') !== false || mb_strpos($hay, 'تقرير الاصناف') !== false) {
        $meta['title'] = 'تقرير الأصناف المدخلة';
        $meta['departments'] = ['accounts', 'purchases', 'admin'];
        $meta['restricted'] = false;
        $meta['note'] = 'المالية/المحاسب: الكل عند تفعيل الصفحة — مدير القسم: خاص أو فريقه أو الكل حسب الاختيار — المدير التجاري مثل الأدمن';
        $meta['group'] = 'finance';
        $meta['order'] = 35;
        return $meta;
    }

    if ($name === 'admin_review' || mb_strpos($hay, 'مراجعة العقود') !== false || mb_strpos($hay, 'مراجعه العقود') !== false) {
        $meta['title'] = 'مراجعة العقود';
        $meta['departments'] = ['admin'];
        $meta['restricted'] = true;
        $meta['note'] = 'أدمن فقط';
        $meta['group'] = 'contracts';
        $meta['order'] = 35;
        return $meta;
    }

    if (mb_strpos($hay, 'عقود مكتملة') !== false || mb_strpos($hay, 'العقود المكتملة') !== false || in_array($name, ['completed_contracts','contracts_completed'], true)) {
        $meta['title'] = 'عقود مكتملة';
        $meta['departments'] = ['purchases', 'marketing'];
        $meta['group'] = 'contracts';
        $meta['order'] = 40;
        return $meta;
    }

    if ($name === 'contracts' || mb_strpos($hay, 'سجل العقود') !== false) {
        $meta['title'] = 'سجل العقود';
        $meta['departments'] = ['admin'];
        $meta['restricted'] = true;
        $meta['note'] = 'صلاحية كاملة للصفحة';
        $meta['group'] = 'admin';
        $meta['order'] = 10;
        return $meta;
    }

    if (mb_strpos($hay, 'التفاوض') !== false) {
        $meta['title'] = 'التفاوض';
        $meta['departments'] = ['purchases'];
        $meta['group'] = 'contracts';
        $meta['order'] = 20;
        return $meta;
    }

    if (mb_strpos($hay, 'اضافة عقد ايجار') !== false || mb_strpos($hay, 'إضافة عقد إيجار') !== false || mb_strpos($hay, 'عقد ايجار') !== false || mb_strpos($hay, 'عقد إيجار') !== false) {
        $meta['title'] = 'إضافة عقد إيجار';
        $meta['departments'] = ['purchases', 'marketing'];
        $meta['group'] = 'rents';
        $meta['order'] = 10;
        return $meta;
    }

    if ($name === 'finance_items' || mb_strpos($hay, 'المالية اصناف') !== false || mb_strpos($hay, 'رسوم الأصناف') !== false || mb_strpos($hay, 'مالية الأصناف') !== false) {
        $meta['title'] = 'المالية - الأصناف';
        $meta['departments'] = ['accounts'];
        $meta['group'] = 'finance';
        $meta['order'] = 20;
        return $meta;
    }

    if (
        mb_strpos($hay, 'الادارة المالية') !== false ||
        mb_strpos($hay, 'الإدارة المالية') !== false ||
        mb_strpos($hay, 'ألإدارة المالية') !== false ||
        mb_strpos($hay, 'متابعة المالية للعقود') !== false ||
        $name === 'finance'
    ) {
        $meta['title'] = 'الإدارة المالية';
        $meta['departments'] = ['accounts'];
        $meta['group'] = 'finance';
        $meta['order'] = 10;
        return $meta;
    }

    if ($name === 'add_items' || mb_strpos($hay, 'اضافة اصناف جديدة') !== false || mb_strpos($hay, 'إضافة أصناف جديدة') !== false) {
        $meta['title'] = 'إضافة أصناف جديدة';
        $meta['departments'] = ['purchases'];
        $meta['group'] = 'items';
        $meta['order'] = 10;
        return $meta;
    }

    if ($name === 'add_contract' || mb_strpos($hay, 'اضافة عقد جديد') !== false || mb_strpos($hay, 'إضافة عقد جديد') !== false) {
        $meta['title'] = 'إضافة عقد جديد';
        $meta['departments'] = ['purchases'];
        $meta['group'] = 'contracts';
        $meta['order'] = 10;
        return $meta;
    }

    if (mb_strpos($hay, 'ايجارات مكتملة') !== false || mb_strpos($hay, 'إيجارات مكتملة') !== false || $name === 'completed_rents') {
        $meta['title'] = 'إيجارات مكتملة';
        $meta['departments'] = ['purchases', 'marketing', 'operations'];
        $meta['group'] = 'rents';
        $meta['order'] = 20;
        return $meta;
    }

    if ($name === 'under_review_items' || mb_strpos($hay, 'اصناف تحت المراجعه') !== false || mb_strpos($hay, 'أصناف تحت المراجعة') !== false) {
        $meta['title'] = 'أصناف تحت المراجعة';
        $meta['departments'] = ['purchases'];
        $meta['group'] = 'items';
        $meta['order'] = 20;
        return $meta;
    }

    if (mb_strpos($hay, 'الاصناف المعتمدة') !== false || mb_strpos($hay, 'الأصناف المعتمدة') !== false || $name === 'approved_items') {
        $meta['title'] = 'الأصناف المعتمدة';
        $meta['departments'] = ['purchases'];
        $meta['group'] = 'items';
        $meta['order'] = 30;
        return $meta;
    }

    if ($name === 'under_review' || $title === 'تحت المراجعه' || $title === 'تحت المراجعة') {
        $meta['title'] = 'عقود تحت المراجعة';
        $meta['departments'] = ['purchases'];
        $meta['group'] = 'contracts';
        $meta['order'] = 30;
        return $meta;
    }

    if ($name === 'data_entry_items' || mb_strpos($hay, 'ادخال الاصناف') !== false || mb_strpos($hay, 'إدخال الأصناف') !== false) {
        $meta['title'] = 'إدخال الأصناف';
        $meta['departments'] = ['data_entry'];
        $meta['group'] = 'items';
        $meta['order'] = 40;
        return $meta;
    }

    return $meta;
}

/* ترتيب كروت الصلاحيات حسب الإدارات */
$groupOrder = [
    'contracts' => 1,
    'rents' => 2,
    'items' => 3,
    'finance' => 4,
    'admin' => 5,
    'review' => 6,
    'data_entry' => 7,
    'purchases' => 8,
    'accounts' => 9,
    'marketing' => 10,
    'operations' => 11,
    'general' => 99
];

usort($pages, function($a, $b) use ($groupOrder) {
    $ma = pagePermissionMeta($a);
    $mb = pagePermissionMeta($b);

    $ga = $groupOrder[$ma['group'] ?? 'general'] ?? 99;
    $gb = $groupOrder[$mb['group'] ?? 'general'] ?? 99;

    if ($ga !== $gb) {
        return $ga <=> $gb;
    }

    $oa = (int)($ma['order'] ?? 999);
    $ob = (int)($mb['order'] ?? 999);

    if ($oa !== $ob) {
        return $oa <=> $ob;
    }

    return strcmp((string)($ma['title'] ?? ''), (string)($mb['title'] ?? ''));
});

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>إدارة المستخدمين</title>

<link rel="stylesheet" href="public/assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
*{
    box-sizing:border-box;
    font-family:'Cairo', Tahoma, Arial, sans-serif;
}

html, body{
    direction:rtl;
    text-align:right;
}

body{
    margin:0;
    background:
        radial-gradient(circle at top right, rgba(109,74,255,.11), transparent 34%),
        #eef1f7;
    color:#172033;
}

.container{
    width:min(1320px, calc(100% - 32px));
    margin:28px auto 45px;
}

.page-head{
    margin-bottom:22px;
}

.users-stats{
    display:grid;
    grid-template-columns:repeat(4, minmax(0, 1fr));
    gap:12px;
    margin-bottom:18px;
}

.stat-card{
    background:rgba(255,255,255,.72);
    border:1px solid rgba(226,232,240,.95);
    border-radius:18px;
    padding:14px 16px;
    box-shadow:4px 4px 12px #d1d9e6,-4px -4px 12px #fff;
}

.stat-card strong{
    display:block;
    font-size:24px;
    font-weight:900;
    color:#4f46e5;
    line-height:1.2;
}

.stat-card span{
    display:block;
    margin-top:4px;
    font-size:13px;
    font-weight:800;
    color:#667085;
}

.users-toolbar{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
    justify-content:space-between;
    margin-bottom:14px;
}

.users-toolbar-filters{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
    flex:1;
}

.users-toolbar-filters input,
.users-toolbar-filters select{
    max-width:220px;
    min-height:42px;
}

.users-toolbar-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.user-form-panel{
    margin-bottom:18px;
}

.user-form-panel:not(.is-open){
    display:none;
}

.user-form-panel .panel-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:14px;
}

.user-form-panel .panel-head .section-title{
    margin:0;
}

.form-sections{
    display:grid;
    gap:14px;
}

.form-section{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:18px;
    padding:14px;
}

.form-section-title{
    margin:0 0 12px;
    font-size:14px;
    font-weight:900;
    color:#4f46e5;
}

.status-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:30px;
    padding:4px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
}

.status-pill.active{
    background:#ecfdf3;
    color:#166534;
}

.status-pill.inactive{
    background:#fee2e2;
    color:#991b1b;
}

.user-meta-line{
    margin-top:6px;
    font-size:12px;
    font-weight:700;
    color:#667085;
}

.users-empty-filter{
    display:none;
    text-align:center;
    padding:18px;
    color:#667085;
    font-weight:800;
}

.users-empty-filter.is-visible{
    display:block;
}

.page-title{
    margin:0 0 7px;
    font-size:28px;
    font-weight:900;
    color:#172033;
    letter-spacing:-.3px;
}

.page-subtitle{
    margin:0;
    color:#667085;
    font-size:15px;
    line-height:1.9;
    font-weight:700;
}

/* alerts */
.alert{
    padding:13px 15px;
    border-radius:14px;
    margin-bottom:15px;
    font-weight:800;
    line-height:1.8;
    box-shadow:0 10px 24px rgba(23,32,51,.06);
}

.alert-success{
    background:#ecfdf3;
    color:#166534;
    border:1px solid #bbf7d0;
}

.alert-error{
    background:#fff1f2;
    color:#b42318;
    border:1px solid #fecdd3;
}

.alert-warning{
    background:#fffbeb;
    color:#92400e;
    border:1px solid #fde68a;
}

/* layout */
.panel{
    background:rgba(255,255,255,.62);
    border-radius:22px;
    padding:20px;
    margin-bottom:18px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    border:1px solid rgba(226,232,240,.95);
}

.section-title{
    background:rgba(255,255,255,.74);
    padding:14px 17px;
    border-radius:18px;
    font-weight:900;
    margin:0 0 16px;
    color:#4f46e5;
    display:flex;
    align-items:center;
    gap:10px;
    border:1px solid rgba(226,232,240,.95);
}

.section-title::before{
    content:"";
    width:9px;
    height:24px;
    border-radius:999px;
    background:linear-gradient(180deg,#7c5cff,#4f46e5);
}

/* form */
.form-grid{
    display:grid;
    grid-template-columns:1fr 220px;
    gap:12px;
    margin-bottom:12px;
}

.input-group{
    margin-bottom:12px;
}

.input-group label{
    display:block;
    font-size:13px;
    font-weight:900;
    color:#172033;
    margin-bottom:8px;
}

input,
select{
    width:100%;
    min-height:48px;
    padding:0 14px;
    border-radius:14px;
    border:1px solid #dfe6f0;
    background:#eef1f7;
    color:#172033;
    box-shadow:
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
    font-size:14px;
    outline:none;
    transition:.18s ease;
}

input:focus,
select:focus{
    border-color:#6d4aff;
    box-shadow:
        0 0 0 3px rgba(109,74,255,.12),
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
}

.password-row{
    display:grid;
    grid-template-columns:220px 1fr;
    gap:12px;
    align-items:end;
}

.check-line{
    min-height:48px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:14px;
    font-weight:900;
    color:#475569;
}

.check-line input{
    width:auto;
    min-height:auto;
    box-shadow:none;
}

.form-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    justify-content:flex-start;
    margin-top:16px;
}

.btn{
    min-height:42px;
    padding:0 18px;
    border:none;
    border-radius:13px;
    cursor:pointer;
    font-weight:900;
    font-size:13px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    text-decoration:none;
    transition:.18s ease;
    color:#fff;
}

.btn:hover{
    transform:translateY(-1px);
    filter:brightness(.98);
}

.btn-primary{
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
}

.btn-muted{
    background:#64748b;
}

.btn-delete{
    background:#ef4444;
}

.btn-edit{
    background:#f59e0b;
}


.perm-tools{
    margin:10px 0 10px;
}

.perm-tools input{
    max-width:420px;
}

/* permissions */
.permissions-head{
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:center;
    margin:16px 0 10px;
}

.permissions-note{
    color:#667085;
    font-size:13px;
    font-weight:800;
}

.permissions-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(245px, 1fr));
    gap:12px;
    margin-top:10px;
    align-items:stretch;
}

.perm-group-title{
    grid-column:1 / -1;
    margin:12px 0 2px;
    padding:12px 15px;
    border-radius:16px;
    background:linear-gradient(145deg,#ffffff,#eef1f7);
    border:1px solid #e2e8f0;
    color:#4f46e5;
    font-size:15px;
    font-weight:900;
    box-shadow:3px 3px 9px #d1d9e6,-3px -3px 9px #fff;
}

.perm-card{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:18px;
    padding:14px;
    display:flex;
    flex-direction:column;
    height:100%;
    min-height:260px;
}

.perm-title{
    font-weight:900;
    color:#172033;
    margin-bottom:5px;
    min-height:34px;
    display:flex;
    align-items:center;
}

.perm-desc{
    font-size:12px;
    color:#667085;
    font-weight:700;
    line-height:1.7;
    min-height:38px;
    display:flex;
    align-items:flex-start;
}

.perm-warning{
    color:#b42318;
    background:#fff1f2;
    border:1px solid #fecdd3;
    border-radius:10px;
    padding:6px 8px;
    font-size:11px;
    font-weight:900;
    margin:7px 0;
    min-height:38px;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
}

.perm-warning.is-empty{
    visibility:hidden;
}

.perm-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-top:10px;
}

.perm-top label,
.scope-row label{
    display:flex;
    align-items:center;
    gap:6px;
    font-size:13px;
    font-weight:900;
    color:#475569;
}

.perm-top input,
.scope-row input{
    width:auto;
    min-height:auto;
    box-shadow:none;
}

.scope-row{
    display:flex;
    justify-content:center;
    gap:16px;
    margin-top:auto;
    background:#eef1f7;
    border-radius:12px;
    padding:8px;
}

.scope-disabled{
    margin-top:auto;
    background:#f1f5f9;
    color:#475569;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:9px;
    text-align:center;
    font-size:12px;
    font-weight:900;
}

.scope-disabled.admin-only{
    background:#fff1f2;
    color:#b42318;
    border-color:#fecdd3;
}

.dept-presets{
    display:flex;
    flex-wrap:wrap;
    gap:9px;
    margin:10px 0 14px;
    padding:12px;
    background:#eef1f7;
    border:1px solid #e2e8f0;
    border-radius:16px;
}

.dept-preset{
    border:none;
    min-height:34px;
    padding:0 12px;
    border-radius:999px;
    cursor:pointer;
    font-size:12px;
    font-weight:900;
    color:#172033;
    background:#fff;
    box-shadow:3px 3px 8px #d1d9e6,-3px -3px 8px #fff;
    transition:.18s ease;
}

.dept-preset:hover{
    transform:translateY(-1px);
    color:#4f46e5;
}

.dept-preset.clear{
    background:#64748b;
    color:#fff;
}

.dept-tags{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    margin:9px 0 4px;
    min-height:29px;
    align-items:center;
}

.dept-tag{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:25px;
    padding:4px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
    border:1px solid transparent;
    line-height:1.3;
}

.dept-tag.purchases{
    background:#eff6ff;
    color:#1d4ed8;
    border-color:#bfdbfe;
}

.dept-tag.accounts{
    background:#ecfdf3;
    color:#166534;
    border-color:#bbf7d0;
}

.dept-tag.marketing{
    background:#fff7ed;
    color:#c2410c;
    border-color:#fed7aa;
}

.dept-tag.operations{
    background:#f0f9ff;
    color:#0369a1;
    border-color:#bae6fd;
}

.dept-tag.data_entry{
    background:#f5f3ff;
    color:#6d28d9;
    border-color:#ddd6fe;
}

.dept-tag.admin{
    background:#fef2f2;
    color:#b42318;
    border-color:#fecaca;
}

.dept-tag.reviewers{
    background:#fffbeb;
    color:#92400e;
    border-color:#fde68a;
}


.dept-tag.system{
    background:#eef2ff;
    color:#3730a3;
    border-color:#c7d2fe;
}


.dept-tag.general{
    background:#f1f5f9;
    color:#475569;
    border-color:#e2e8f0;
}

.role-note{
    margin-top:10px;
    padding:10px 12px;
    border-radius:14px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    color:#667085;
    font-size:12px;
    font-weight:800;
    line-height:1.8;
}

.role-note.admin-mode{
    background:#f0edff;
    border-color:#ddd6fe;
    color:#4f46e5;
}

/* table */
.table-box{
    background:rgba(255,255,255,.62);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:14px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    overflow:hidden;
}

.table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    table-layout:fixed;
}

.table th{
    background:#6d4aff;
    color:#fff;
    padding:14px 10px;
    text-align:center;
    font-size:13px;
    line-height:1.45;
    font-weight:900;
    white-space:nowrap;
}

.table th:first-child{
    border-radius:0 14px 14px 0;
}

.table th:last-child{
    border-radius:14px 0 0 14px;
}

.table td{
    padding:13px 10px;
    border-bottom:1px solid #dfe6f0;
    text-align:center;
    vertical-align:middle;
    font-size:14px;
    line-height:1.7;
    color:#172033;
}

.table tr:last-child td{
    border-bottom:none;
}

.table tr:hover td{
    background:#f6f4ff;
}

.col-id{width:60px;}
.col-name{width:22%;}
.col-role{width:120px;}
.col-structure{width:16%;}
.col-whatsapp{width:14%;}
.col-status{width:110px;}
.col-actions{width:180px;}

.user-name{
    font-weight:900;
    color:#172033;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.role-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:90px;
    min-height:34px;
    border-radius:999px;
    padding:6px 11px;
    font-size:12px;
    font-weight:900;
}

.role-admin{
    background:#f0edff;
    color:#4f46e5;
}

.role-user{
    background:#f1f5f9;
    color:#475569;
}

.pass-safe{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:130px;
    min-height:34px;
    border-radius:999px;
    padding:6px 11px;
    background:#ecfdf3;
    color:#166534;
    font-size:12px;
    font-weight:900;
}

.session-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:130px;
    min-height:34px;
    border-radius:999px;
    padding:6px 11px;
    background:#f1f5f9;
    color:#475569;
    font-size:12px;
    font-weight:900;
}

.actions{
    display:flex;
    justify-content:center;
    align-items:center;
    gap:7px;
    flex-wrap:nowrap;
}

.actions form{
    margin:0;
    padding:0;
    display:inline-flex;
}

.empty{
    padding:26px !important;
    text-align:center;
    color:#667085;
    font-weight:900;
}

@media(max-width:1100px){
    .users-stats{
        grid-template-columns:repeat(2, minmax(0, 1fr));
    }

    .form-grid,
    .password-row{
        grid-template-columns:1fr;
    }

    .permissions-head{
        flex-direction:column;
        align-items:flex-start;
    }
}

@media(max-width:1000px){
    .table-box{
        overflow-x:auto;
    }

    .table{
        min-width:760px;
    }
}

@media(max-width:560px){
    .container{
        width:calc(100% - 18px);
        margin-top:18px;
    }

    .users-stats{
        grid-template-columns:1fr 1fr;
    }

    .users-toolbar{
        flex-direction:column;
        align-items:stretch;
    }

    .users-toolbar-filters input,
    .users-toolbar-filters select{
        max-width:none;
        width:100%;
    }

    .page-title{
        font-size:23px;
    }

    .actions{
        flex-wrap:wrap;
    }
}
</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">إدارة المستخدمين</h1>
        <p class="page-subtitle">عرض الحسابات، البحث والتصفية، ثم إضافة أو تعديل المستخدم وصلاحياته.</p>
    </div>

    <?php if(isset($_GET['saved'])): ?>
        <div class="alert alert-success">تم حفظ المستخدم والصلاحيات بنجاح ✅</div>
    <?php endif; ?>

    <?php if(isset($_GET['deactivated'])): ?>
        <div class="alert alert-success">تم تعطيل المستخدم بنجاح ✅</div>
    <?php endif; ?>

    <?php if(isset($_GET['activated'])): ?>
        <div class="alert alert-success">تم تفعيل المستخدم بنجاح ✅</div>
    <?php endif; ?>

    <?php if(isset($_GET['pass_changed'])): ?>
        <div class="alert alert-warning">
            تم تغيير كلمة المرور. سيتم تسجيل خروج المستخدم من أي جلسة مفتوحة عند أول انتقال أو تحديث صفحة.
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="users-stats">
        <div class="stat-card"><strong><?= (int)$userStats['total'] ?></strong><span>إجمالي المستخدمين</span></div>
        <div class="stat-card"><strong><?= (int)$userStats['active'] ?></strong><span>نشط</span></div>
        <div class="stat-card"><strong><?= (int)$userStats['inactive'] ?></strong><span>معطّل</span></div>
        <div class="stat-card"><strong><?= (int)$userStats['managers'] ?></strong><span>مدراء وإشراف</span></div>
    </div>

    <div class="table-box">
        <div class="users-toolbar">
            <div class="users-toolbar-filters">
                <input type="search" id="userSearch" placeholder="بحث بالاسم أو الرقم..." oninput="filterUsersTable()">
                <select id="userRoleFilter" onchange="filterUsersTable()">
                    <option value="">كل الأنواع</option>
                    <option value="admin">أدمن</option>
                    <option value="commercial_manager">مدير تجاري</option>
                    <option value="finance_manager">مدير مالي</option>
                    <option value="section_manager">مدير قسم</option>
                    <option value="accountant">محاسب</option>
                    <option value="user">مستخدم</option>
                </select>
                <select id="userStatusFilter" onchange="filterUsersTable()">
                    <option value="">كل الحالات</option>
                    <option value="1">نشط فقط</option>
                    <option value="0">معطّل فقط</option>
                </select>
            </div>
            <div class="users-toolbar-actions">
                <button type="button" class="btn btn-primary" onclick="openUserForm(true)">+ إضافة مستخدم</button>
            </div>
        </div>

        <div class="section-title">قائمة المستخدمين</div>

        <table class="table" id="usersTable">
            <thead>
                <tr>
                    <th class="col-id">#</th>
                    <th class="col-name">المستخدم</th>
                    <th class="col-role">النوع</th>
                    <th class="col-structure">الهيكل</th>
                    <th class="col-whatsapp">واتساب</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-actions">إجراءات</th>
                </tr>
            </thead>

            <tbody>
                <?php if(!empty($users)): ?>
                    <?php foreach($users as $u): ?>
                        <?php
                            $jobRole = usersRoleKey($u);
                            $roleText = usersRoleLabel($jobRole);
                            $roleClass = in_array($jobRole, ['admin','commercial_manager','finance_manager'], true) ? 'role-admin' : 'role-user';
                            $isActive = (int)($u['is_active'] ?? 1) === 1;
                            $lastChange = !empty($u['last_password_change'])
                                ? date("Y-m-d H:i", strtotime($u['last_password_change']))
                                : '-';
                            $searchText = strtolower(trim(
                                (string)$u['username'] . ' ' .
                                (string)($u['whatsapp_number'] ?? '') . ' ' .
                                (string)($userNamesById[(int)($u['manager_id'] ?? 0)] ?? '')
                            ));
                        ?>

                        <tr class="user-row"
                            data-role="<?= e($jobRole) ?>"
                            data-active="<?= $isActive ? '1' : '0' ?>"
                            data-search="<?= e($searchText) ?>">
                            <td>#<?= (int)$u['id'] ?></td>

                            <td class="user-name">
                                <?= e($u['username']) ?>
                                <div class="user-meta-line">جلسة v<?= (int)($u['session_version'] ?? 1) ?> · آخر تغيير مرور: <?= e($lastChange) ?></div>
                            </td>

                            <td>
                                <span class="role-badge <?= e($roleClass) ?>"><?= e($roleText) ?></span>
                            </td>

                            <td>
                                <?php if(!empty($u['manager_id']) && isset($userNamesById[(int)$u['manager_id']])): ?>
                                    <span class="session-badge">تحت: <?= e($userNamesById[(int)$u['manager_id']]) ?></span>
                                <?php else: ?>
                                    <span class="session-badge">بدون مدير</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if(!empty($u['whatsapp_number'])): ?>
                                    <span class="session-badge" title="<?= ((int)($u['whatsapp_enabled'] ?? 1) === 1) ? 'واتساب مفعل' : 'واتساب موقوف' ?>">
                                        <?= e($u['whatsapp_number']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="session-badge">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="status-pill <?= $isActive ? 'active' : 'inactive' ?>">
                                    <?= $isActive ? 'نشط' : 'معطّل' ?>
                                </span>
                            </td>

                            <td>
                                <div class="actions">
                                    <button type="button"
                                            class="btn btn-edit"
                                            onclick='editUser(<?= json_encode([
                                                "id" => (int)$u["id"],
                                                "username" => $u["username"],
                                                "account_type" => (string)($u["job_role"] ?? "user"),
                                                "whatsapp_number" => $u["whatsapp_number"] ?? "",
                                                "whatsapp_enabled" => (int)($u["whatsapp_enabled"] ?? 1),
                                                "manager_id" => (int)($u["manager_id"] ?? 0),
                                                "is_supervisor" => (int)($u["is_supervisor"] ?? 0),
                                                "is_active" => (int)($u["is_active"] ?? 1)
                                            ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        تعديل
                                    </button>

                                    <?php if((int)$u['id'] !== $uid): ?>
                                        <?php if($isActive): ?>
                                            <form method="POST" onsubmit="return confirm('تعطيل المستخدم؟ لن يتم حذف عقوده أو نشاطاته، فقط سيتم منعه من الدخول.')">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                                <input type="hidden" name="action" value="deactivate_user">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="btn btn-delete">تعطيل</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" onsubmit="return confirm('تفعيل المستخدم مرة أخرى؟')">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                                <input type="hidden" name="action" value="activate_user">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="btn btn-edit">تفعيل</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="empty">لا يوجد مستخدمين</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="users-empty-filter" id="usersEmptyFilter">لا توجد نتائج مطابقة للبحث أو التصفية.</div>
    </div>

    <div class="user-form-panel" id="userFormPanel">
    <div class="panel">
        <div class="panel-head">
            <div class="section-title" id="userFormTitle">إضافة مستخدم</div>
            <button type="button" class="btn btn-muted" onclick="closeUserForm()">إغلاق</button>
        </div>

        <form method="POST" id="userForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
            <input type="hidden" name="action" value="save_user">
            <input type="hidden" name="id" id="id" value="0">

            <div class="form-sections">
            <div class="form-section">
            <h3 class="form-section-title">البيانات الأساسية</h3>
            <div class="form-grid">
                <div class="input-group">
                    <label for="username">اسم المستخدم</label>
                    <input type="text" name="username" id="username" placeholder="مثال: user1" required>
                </div>

                <div class="input-group">
                    <label for="account_type">نوع المستخدم</label>
                    <select name="account_type" id="account_type" onchange="handleAccountTypeChange(this.value)">
                        <option value="user">مستخدم</option>
                        <option value="section_manager">مدير قسم</option>
                        <option value="commercial_manager">مدير تجاري</option>
                        <option value="finance_manager">مدير مالي</option>
                        <option value="accountant">محاسب</option>
                        <option value="admin">أدمن</option>
                    </select>
                </div>
            </div>

            <div class="form-grid">
                <div class="input-group">
                    <label for="whatsapp_number">رقم واتساب</label>
                    <input type="text" name="whatsapp_number" id="whatsapp_number" placeholder="مثال: 0599050028 أو 966599050028">
                    <small class="field-hint">يمكنك كتابة الرقم بصيغة 05، وسيتم حفظه تلقائيًا بصيغة 966 للإرسال عبر واتساب.</small>
                </div>

                <label class="check-line" style="margin-top:21px;">
                    <input type="checkbox" name="whatsapp_enabled" id="whatsapp_enabled" checked>
                    <span>تفعيل إشعارات واتساب</span>
                </label>
            </div>

            <div class="form-grid">
                <div class="input-group">
                    <label for="manager_id">المدير المباشر</label>
                    <select name="manager_id" id="manager_id">
                        <option value="0">بدون مدير مباشر</option>
                        <?php foreach($managerOptions as $managerUser): ?>
                            <option value="<?= (int)$managerUser['id'] ?>">
                                <?= e($managerUser['username']) ?>
                                <?php
                                    $managerJobRole = (string)($managerUser['job_role'] ?? 'user');
                                    if ($managerJobRole === 'commercial_manager') {
                                        echo ' - مدير تجاري';
                                    } elseif ($managerJobRole === 'finance_manager') {
                                        echo ' - مدير مالي';
                                    } elseif ($managerJobRole === 'section_manager' || (int)($managerUser['is_supervisor'] ?? 0) === 1) {
                                        echo ' - مدير قسم';
                                    } elseif ($managerJobRole === 'admin' || (string)($managerUser['role'] ?? '') === 'admin' || (int)($managerUser['is_admin'] ?? 0) === 1) {
                                        echo ' - أدمن';
                                    }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="field-hint">القائمة تعرض مديرين الأقسام والمدير المالي والمدير التجاري والأدمن فقط.</small>
                </div>
            </div>
            </div>

            <div class="form-section">
            <h3 class="form-section-title">كلمة المرور</h3>
            <div class="password-row">
                <label class="check-line">
                    <input type="checkbox" id="changePass" onchange="togglePass()" checked>
                    <span>تغيير كلمة المرور</span>
                </label>

                <div class="input-group" style="margin:0;">
                    <label for="password">كلمة المرور</label>
                    <input type="password" name="password" id="password" placeholder="كلمة المرور الجديدة">
                </div>
            </div>
            </div>

            <div class="form-section" id="permissionsArea">
                <h3 class="form-section-title">الصلاحيات والصفحات</h3>
                <div class="permissions-head">
                    <div class="permissions-note" id="rolePermissionNote">اختار الإدارة، أو فعّل الصفحات يدويًا. بعض الصفحات لا تحتاج صلاحيات أخرى.</div>
                </div>

                <div class="perm-tools">
                    <input type="text" id="permissionSearch" placeholder="🔍 ابحث عن صلاحية أو صفحة..." onkeyup="filterPermissionCards()">
                </div>

                <div class="dept-presets">
                    <button type="button" class="dept-preset" onclick="applyDepartment('purchases')">المشتريات</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('marketing')">التسويق</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('operations')">التشغيل</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('data_entry')">إدخال البيانات</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('accounts')">الحسابات</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('reviewers')">المراجعين الموثقين</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('admin')">الأدمن</button>
                    <button type="button" class="dept-preset clear" onclick="resetPermissions()">مسح الاختيار</button>
                </div>

                <div class="role-note" id="roleNoteBox">
                    نوع الحساب "مستخدم" يعتمد على الصلاحيات المختارة من الكروت.
                </div>

                <div id="permissionsBox" class="permissions-grid">
                    <?php
                        $currentGroup = '';
                        $groupLabels = [
                            'contracts' => 'إدارة ومتابعة العقود',
                            'rents' => 'إدارة ومتابعة الإيجارات',
                            'items' => 'إدخال ومراجعة الأصناف',
                            'finance' => 'الإدارة المالية',
                            'admin' => 'الإدارة',
                            'review' => 'المراجعات والاعتمادات',
                            'data_entry' => 'إدخال البيانات',
                            'purchases' => 'المشتريات',
                            'accounts' => 'الحسابات',
                            'marketing' => 'التسويق',
                            'operations' => 'التشغيل',
                            'general' => 'أخرى'
                        ];
                    ?>

                    <?php foreach($pages as $p): ?>
                        <?php
                            $meta = pagePermissionMeta($p);
                            $isRestricted = (bool)$meta['restricted'];
                            $deptKeys = $meta['departments'];
                            $deptAttr = implode(' ', array_map('deptClass', $deptKeys));
                            $metaGroup = (string)($meta['group'] ?? 'general');
                        ?>

                        <?php if($currentGroup !== $metaGroup): ?>
                            <?php $currentGroup = $metaGroup; ?>
                            <div class="perm-group-title">
                                <?= e($groupLabels[$metaGroup] ?? $metaGroup) ?>
                            </div>
                        <?php endif; ?>

                        <div class="perm-card" data-page="<?= e($p['name'] ?? '') ?>" data-depts="<?= e($deptAttr) ?>" data-search="<?= e($meta['title'] . ' ' . ($p['name'] ?? '') . ' ' . ($p['description'] ?? '')) ?>">
                            <div class="perm-title"><?= e($meta['title']) ?></div>

                            <div class="perm-warning <?= $isRestricted ? '' : 'is-empty' ?>">
                                <?= $isRestricted ? 'صلاحية حساسة — يحددها الأدمن' : '&nbsp;' ?>
                            </div>

                            <div class="dept-tags">
                                <?php foreach($deptKeys as $deptKey): ?>
                                    <span class="dept-tag <?= e(deptClass($deptKey)) ?>">
                                        <?= e(deptLabel($deptKey)) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>

                            <div class="perm-desc"><?= e($p['description'] ?? '') ?></div>

                            <div class="perm-top">
                                <label>
                                    <input type="checkbox" class="perm-check" name="permissions[<?= (int)$p['id'] ?>][view]">
                                    <span>عرض الصفحة</span>
                                </label>
                            </div>

                            <?php
                                $pageNameForScope  = trim((string)($p['name'] ?? ''));
                                $pageTitleForScope = trim((string)($meta['title'] ?? ($p['title'] ?? '')));
                                $scopeHaystack     = mb_strtolower($pageNameForScope . ' ' . $pageTitleForScope, 'UTF-8');

                                /*
                                    كروت لا تحتاج خاص / الكل:
                                    - صفحات الصلاحية الكاملة: سجل العقود / مراجعة الأصناف
                                    - صفحات الأدمن فقط
                                    - صفحات الإضافة / المالية / إدخال الأصناف
                                */
                                $isContractsScopeCard = (
                                    $pageNameForScope === 'contracts' ||
                                    mb_strpos($scopeHaystack, 'سجل العقود') !== false
                                );

                                $isItemsReviewScopeCard = (
                                    $pageNameForScope === 'items_admin' ||
                                    $pageNameForScope === 'review_items' ||
                                    mb_strpos($scopeHaystack, 'مراجعة الأصناف') !== false ||
                                    mb_strpos($scopeHaystack, 'مراجعه الاصناف') !== false ||
                                    mb_strpos($scopeHaystack, 'مراجعة الاصناف') !== false
                                );

                                $isAdminOnlyScopeCard = (
                                    $pageNameForScope === 'users' ||
                                    $pageNameForScope === 'admin_review' ||
                                    mb_strpos($scopeHaystack, 'مراجعة العقود') !== false ||
                                    mb_strpos($scopeHaystack, 'مراجعه العقود') !== false ||
                                    mb_strpos($scopeHaystack, 'إدارة المستخدمين') !== false ||
                                    mb_strpos($scopeHaystack, 'ادارة المستخدمين') !== false
                                );

                                $isFullPageScopeCard = (
                                    in_array($pageNameForScope, ['trusted_reviewers', 'verified_reviewers', 'reviewers'], true) ||
                                    in_array($pageNameForScope, ['system_check', 'contract_ai', 'contracts_ai', 'ai_contracts', 'system_health'], true) ||
                                    mb_strpos($scopeHaystack, 'المراجعين الموثقين') !== false ||
                                    mb_strpos($scopeHaystack, 'ذكاء العقود') !== false ||
                                    mb_strpos($scopeHaystack, 'صحة النظام') !== false ||
                                    mb_strpos($scopeHaystack, 'فحص السيستم') !== false ||
                                    mb_strpos($scopeHaystack, 'فحص النظام') !== false
                                );

                                $hideScopeRow = (
                                    /*
                                        لا نخفي النطاق عن صفحات المتابعة التي تدعم فريقه.
                                        مثال: مراجعة الأصناف items_admin لازم يظهر فيها خاص / فريقه لمدير القسم.
                                    */
                                    $isAdminOnlyScopeCard ||
                                    $isFullPageScopeCard ||

                                    /* صفحات الإضافة */
                                    $pageNameForScope === 'add_contract' ||
                                    $pageNameForScope === 'add_items' ||
                                    $pageNameForScope === 'add_payment_request' ||
                                    $pageNameForScope === 'rents' ||
                                    mb_strpos($scopeHaystack, 'إضافة عقد جديد') !== false ||
                                    mb_strpos($scopeHaystack, 'اضافة عقد جديد') !== false ||
                                    mb_strpos($scopeHaystack, 'إضافة عقد إيجار') !== false ||
                                    mb_strpos($scopeHaystack, 'اضافة عقد ايجار') !== false ||
                                    mb_strpos($scopeHaystack, 'إضافة أصناف جديدة') !== false ||
                                    mb_strpos($scopeHaystack, 'اضافة اصناف جديدة') !== false ||

                                    /* إدخال الأصناف */
                                    $pageNameForScope === 'data_entry_items' ||
                                    mb_strpos($scopeHaystack, 'إدخال الأصناف') !== false ||
                                    mb_strpos($scopeHaystack, 'ادخال الاصناف') !== false ||

                                    /* صفحات مالية ومكتملة */
                                    $pageNameForScope === 'finance' ||
                                    $pageNameForScope === 'finance_items' ||
                                    $pageNameForScope === 'completed_rents' ||
                                    mb_strpos($scopeHaystack, 'متابعة المالية للعقود') !== false ||
                                    mb_strpos($scopeHaystack, 'متابعة المالية للتكويد') !== false ||
                                    mb_strpos($scopeHaystack, 'رسوم الأصناف') !== false ||
                                    mb_strpos($scopeHaystack, 'رسوم الاصناف') !== false ||
                                    mb_strpos($scopeHaystack, 'إيجارات مكتملة') !== false ||
                                    mb_strpos($scopeHaystack, 'ايجارات مكتملة') !== false
                                );
                            ?>

                            <?php if($hideScopeRow): ?>
                                <div class="scope-disabled <?= $isAdminOnlyScopeCard ? 'admin-only' : '' ?>">
                                    <?= ($isContractsScopeCard || $isFullPageScopeCard) ? 'صلاحية كاملة للصفحة' : ($isAdminOnlyScopeCard ? 'أدمن فقط' : 'لا صلاحيات أخرى') ?>
                                </div>
                            <?php else: ?>
                                <div class="scope-row">
                                    <label>
                                        <input type="radio" name="permissions[<?= (int)$p['id'] ?>][scope]" value="own" checked>
                                        <span>خاص</span>
                                    </label>

                                    <label>
                                        <input type="radio" name="permissions[<?= (int)$p['id'] ?>][scope]" value="team">
                                        <span>فريقه</span>
                                    </label>

                                    <label>
                                        <input type="radio" name="permissions[<?= (int)$p['id'] ?>][scope]" value="all">
                                        <span>الكل</span>
                                    </label>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">حفظ المستخدم</button>
                <button type="button" class="btn btn-muted" onclick="resetForm()">تفريغ النموذج</button>
            </div>
        </form>
    </div>
    </div>

</div>

<script>
function filterUsersTable(){
    const q = (document.getElementById("userSearch")?.value || "").trim().toLowerCase();
    const role = document.getElementById("userRoleFilter")?.value || "";
    const status = document.getElementById("userStatusFilter")?.value || "";
    let visible = 0;

    document.querySelectorAll(".user-row").forEach(function(row){
        const hay = (row.getAttribute("data-search") || "").toLowerCase();
        const rowRole = row.getAttribute("data-role") || "";
        const rowActive = row.getAttribute("data-active") || "";

        const matchSearch = !q || hay.includes(q);
        const matchRole = !role || rowRole === role;
        const matchStatus = status === "" || rowActive === status;
        const show = matchSearch && matchRole && matchStatus;

        row.style.display = show ? "" : "none";
        if(show){
            visible++;
        }
    });

    const emptyBox = document.getElementById("usersEmptyFilter");
    if(emptyBox){
        emptyBox.classList.toggle("is-visible", visible === 0 && document.querySelectorAll(".user-row").length > 0);
    }
}

function openUserForm(isNew){
    const panel = document.getElementById("userFormPanel");
    const title = document.getElementById("userFormTitle");
    if(panel){
        panel.classList.add("is-open");
    }
    if(title){
        title.textContent = isNew ? "إضافة مستخدم" : "تعديل مستخدم";
    }
    if(isNew){
        clearUserFormFields();
    }
    panel?.scrollIntoView({behavior:"smooth", block:"start"});
}

function closeUserForm(){
    const panel = document.getElementById("userFormPanel");
    if(panel){
        panel.classList.remove("is-open");
    }
}

function clearUserFormFields(){
    document.getElementById("id").value = "0";
    document.getElementById("username").value = "";
    document.getElementById("account_type").value = "user";
    document.getElementById("whatsapp_number").value = "";
    document.getElementById("whatsapp_enabled").checked = true;
    document.getElementById("manager_id").value = "0";

    document.getElementById("changePass").checked = true;
    document.getElementById("password").disabled = false;
    document.getElementById("password").value = "";

    const permissionSearch = document.getElementById("permissionSearch");
    if(permissionSearch){
        permissionSearch.value = "";
        filterPermissionCards();
    }

    resetPermissions();
    handleAccountTypeChange("user");
    prepareNewUserPassword();
}

function filterPermissionCards(){
    const input = document.getElementById("permissionSearch");
    const q = input ? input.value.trim().toLowerCase() : "";

    document.querySelectorAll(".perm-card").forEach(function(card){
        const hay = (card.getAttribute("data-search") || "").toLowerCase();
        card.style.display = (!q || hay.includes(q)) ? "flex" : "none";
    });

    document.querySelectorAll(".perm-group-title").forEach(function(title){
        let next = title.nextElementSibling;
        let hasVisible = false;

        while(next && !next.classList.contains("perm-group-title")){
            if(next.classList && next.classList.contains("perm-card") && next.style.display !== "none"){
                hasVisible = true;
                break;
            }
            next = next.nextElementSibling;
        }

        title.style.display = hasVisible ? "block" : "none";
    });
}

function handleAccountTypeChange(accountType){
    togglePerms(accountType);
}

function setScopeRowsForAccountType(accountType){
    document.querySelectorAll('.scope-row').forEach(function(row){
        const ownLabel = row.querySelector('input[value="own"]')?.closest('label');
        const teamLabel = row.querySelector('input[value="team"]')?.closest('label');
        const allLabel = row.querySelector('input[value="all"]')?.closest('label');
        const ownInput = row.querySelector('input[value="own"]');
        const teamInput = row.querySelector('input[value="team"]');

        row.style.display = 'flex';
        if(ownLabel) ownLabel.style.display = 'flex';
        if(teamLabel) teamLabel.style.display = 'flex';
        if(allLabel) allLabel.style.display = 'flex';

        if(accountType === 'user'){
            row.style.display = 'none';
            if(ownInput) ownInput.checked = true;
        }else if(accountType === 'accountant'){
            const card = row.closest('.perm-card');
            const pageName = card?.dataset?.page || '';
            const hay = ((card?.dataset?.search || '') + ' ' + pageName).toLowerCase();
            const canAccountantChooseScope = (
                pageName === 'contract_report' ||
                pageName === 'item_report' ||
                hay.includes('تقرير كل العقود') ||
                hay.includes('تقرير الأصناف') ||
                hay.includes('تقرير الاصناف')
            );

            if(canAccountantChooseScope){
                row.style.display = 'flex';
                if(ownLabel) ownLabel.style.display = 'flex';
                if(teamLabel) teamLabel.style.display = 'flex';
                if(allLabel) allLabel.style.display = 'flex';
            }else{
                row.style.display = 'none';
                if(ownInput) ownInput.checked = true;
            }
        }else if(accountType === 'section_manager'){
            const pageName = row.closest('.perm-card')?.dataset?.page || '';
            const canSectionManagerUseAll = (pageName === 'contract_report' || pageName === 'item_report');

            if(allLabel) allLabel.style.display = canSectionManagerUseAll ? 'flex' : 'none';

            const checkedAll = row.querySelector('input[value="all"]:checked');
            if(checkedAll && !canSectionManagerUseAll && teamInput){
                teamInput.checked = true;
            }
        }else if(accountType === 'finance_manager'){
            // مدير مالي يظهر له خاص / فريقه / الكل
        }else if(accountType === 'commercial_manager'){
            // مدير تجاري يظهر له خاص / فريقه / الكل
        }
    });
}

function togglePerms(accountType){
    const area = document.getElementById("permissionsArea");
    const note = document.getElementById("rolePermissionNote");
    const roleNoteBox = document.getElementById("roleNoteBox");

    if(!area){
        return;
    }

    if(accountType === "admin" || accountType === "commercial_manager"){
        area.style.display = "none";

        if(note){
            note.textContent = (accountType === "commercial_manager") ? "المدير التجاري له صلاحية كاملة مثل الأدمن، ولا يحتاج اختيار صفحات من الكروت." : "الأدمن له صلاحية كاملة، ولا يحتاج اختيار صفحات من الكروت.";
        }

        if(roleNoteBox){
            roleNoteBox.classList.add("admin-mode");
            roleNoteBox.textContent = (accountType === "commercial_manager") ? "نوع المستخدم مدير تجاري: صلاحية كاملة مثل الأدمن بدون كروت." : "نوع المستخدم أدمن: صلاحية كاملة بدون كروت.";
        }

        resetPermissions();
        return;
    }

    area.style.display = "block";
    setScopeRowsForAccountType(accountType);

    if(accountType === 'user' || accountType === 'accountant'){
        if(note){
            note.textContent = (accountType === 'accountant')
                ? "المحاسب: يمكن تحديد نطاق تقرير كل العقود وتقرير الأصناف فقط، وباقي الصفحات تكون خاص تلقائيًا."
                : "المستخدم العادي: اختار الصفحات فقط، والنطاق يكون خاص تلقائيًا.";
        }
        if(roleNoteBox){
            roleNoteBox.classList.remove("admin-mode");
            roleNoteBox.textContent = (accountType === 'accountant')
                ? "محاسب: في تقرير العقود وتقرير الأصناف اختر خاص / فريقه / الكل حسب المطلوب، حتى يقدر يراجع ويخصم من الفواتير."
                : "مستخدم: لا يظهر له خاص / فريقه / الكل. أي صفحة يتم تفعيلها تكون على بياناته فقط.";
        }
    }else if(accountType === 'section_manager'){
        if(note){ note.textContent = "مدير القسم: يمكن اختيار خاص أو فريقه، وفي تقارير العقود والأصناف يمكن اختيار الكل أيضًا."; }
        if(roleNoteBox){
            roleNoteBox.classList.remove("admin-mode");
            roleNoteBox.textContent = "مدير قسم: خاص = بياناته فقط، فريقه = بياناته + الموظفين التابعين له، الكل متاح في تقرير العقود وتقرير الأصناف فقط.";
        }
    }else if(accountType === 'finance_manager'){
        if(note){ note.textContent = "مدير مالي: يمكن اختيار خاص أو فريقه أو الكل حسب الصفحة."; }
        if(roleNoteBox){
            roleNoteBox.classList.remove("admin-mode");
            roleNoteBox.textContent = "مدير مالي: فريقه يشمل المحاسبين والتابعين له حسب المدير المباشر.";
        }
    }else if(accountType === 'commercial_manager'){
        if(note){ note.textContent = "مدير تجاري: يمكن اختيار خاص أو فريقه أو الكل حسب الصفحة."; }
        if(roleNoteBox){
            roleNoteBox.classList.remove("admin-mode");
            roleNoteBox.textContent = "مدير تجاري: فريقه يشمل كل التابعين له على كل المستويات.";
        }
    }
}

function togglePass(){
    const pass = document.getElementById("password");
    const check = document.getElementById("changePass");

    pass.disabled = !check.checked;

    if(pass.disabled){
        pass.value = "";
    }else{
        pass.focus();
    }
}

function prepareNewUserPassword(){
    const idField = document.getElementById("id");
    const pass = document.getElementById("password");
    const check = document.getElementById("changePass");

    if(!idField || !pass || !check){
        return;
    }

    if(String(idField.value || "0") === "0"){
        check.checked = true;
        pass.disabled = false;
    }
}

document.addEventListener("DOMContentLoaded", prepareNewUserPassword);

function resetPermissions(){
    document.querySelectorAll(".perm-check").forEach(function(c){
        c.checked = false;
    });

    document.querySelectorAll(".scope-row input[value='own']").forEach(function(r){
        r.checked = true;
    });
}

function applyDepartment(dept){
    resetPermissions();

    const accountType = document.getElementById("account_type")?.value || "user";
    let scopeValue = "own";
    if(accountType === "section_manager" || accountType === "finance_manager" || accountType === "commercial_manager"){
        scopeValue = "team";
    }

    document.querySelectorAll(`.perm-card[data-depts~="${dept}"]`).forEach(function(card){
        const check = card.querySelector(".perm-check");
        if(check){
            check.checked = true;
        }

        const radio = card.querySelector(`.scope-row input[value="${scopeValue}"]`);
        if(radio){
            radio.checked = true;
        }
    });

    setScopeRowsForAccountType(accountType);
}

function resetForm(){
    clearUserFormFields();
    const title = document.getElementById("userFormTitle");
    if(title){
        title.textContent = "إضافة مستخدم";
    }
    openUserForm(true);
}

function editUser(u){
    openUserForm(false);

    document.getElementById("id").value = u.id;
    document.getElementById("username").value = u.username;
    document.getElementById("account_type").value = u.account_type || "user";
    document.getElementById("whatsapp_number").value = u.whatsapp_number || "";
    document.getElementById("whatsapp_enabled").checked = String(u.whatsapp_enabled || 0) === "1";
    document.getElementById("manager_id").value = String(u.manager_id || 0);
    document.getElementById("changePass").checked = false;
    document.getElementById("password").value = "";
    document.getElementById("password").disabled = true;

    resetPermissions();
    handleAccountTypeChange(u.account_type || "user");

    fetch("get_user_permissions.php?user_id=" + encodeURIComponent(u.id))
        .then(function(res){
            return res.json();
        })
        .then(function(data){

            if(!Array.isArray(data)){
                return;
            }

            data.forEach(function(p){

                let check = document.querySelector(`input[name="permissions[${p.page_id}][view]"]`);
                if(check){
                    check.checked = true;
                }

                let scope = (["own", "team", "all"].includes(p.scope)) ? p.scope : "own";
                let radio = document.querySelector(`input[name="permissions[${p.page_id}][scope]"][value="${scope}"]`);
                if(radio){
                    radio.checked = true;
                }
            });

            setScopeRowsForAccountType(u.account_type || "user");
        })
        .catch(function(){
            console.warn("تعذر تحميل صلاحيات المستخدم");
        });
}

document.addEventListener("DOMContentLoaded", function(){
    handleAccountTypeChange("user");
    prepareNewUserPassword();
    filterUsersTable();
});
</script>

</body>
</html>
