<?php

if (!function_exists('usr_e')) {
    function usr_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

}

if (!function_exists('e')) {
    function e($value): string
    {
        return usr_e($value);
    }
}

if (!function_exists('usr_normalize_whatsapp')) {
    function usr_normalize_whatsapp($phone): string {
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

}

if (!function_exists('usersRoleKey')) {
    function usersRoleKey(array $u): string {
    $jobRole = (string)($u['job_role'] ?? 'user');
    if (($u['role'] ?? '') === 'admin' || (int)($u['is_admin'] ?? 0) === 1) {
        if ($jobRole !== 'commercial_manager') {
            $jobRole = 'admin';
        }
    }

    return $jobRole;
}
}

if (!function_exists('usersRoleLabel')) {
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
}

if (!function_exists('usersRoleBadgeClass')) {
    function usersRoleBadgeClass(string $jobRole): string {
    return match ($jobRole) {
        'admin', 'commercial_manager' => 'role-admin',
        'section_manager' => 'role-manager',
        'finance_manager' => 'role-finance',
        'accountant' => 'role-accountant',
        default => 'role-user',
    };
}
}

if (!function_exists('deptLabel')) {
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
}

if (!function_exists('deptClass')) {
    function deptClass(string $key): string {
    $allowed = ['purchases','accounts','marketing','operations','data_entry','admin','reviewers','system','general'];
    return in_array($key, $allowed, true) ? $key : 'general';
}
}

if (!function_exists('pagePermissionMeta')) {
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
}

if (!function_exists('usr_prepare_page')) {
    /**
     * @return array<string, mixed>
     */
    function usr_prepare_page(VcDb $conn, int $uid): array
    {$vcAppEnv = strtolower(trim((string) (vcEnv('APP_ENV', 'local') ?? 'local')));
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
    $wantPasswordChange = ($_POST['want_password_change'] ?? '0') === '1';
    $account_type = trim((string)($_POST['account_type'] ?? $_POST['role'] ?? 'user'));
    if (!in_array($account_type, ['user', 'section_manager', 'finance_manager', 'commercial_manager', 'accountant', 'admin'], true)) {
        $account_type = 'user';
    }
    $role = in_array($account_type, ['admin', 'commercial_manager'], true) ? 'admin' : 'user';
    $job_role = $account_type;
    $whatsapp_number = usr_normalize_whatsapp($_POST['whatsapp_number'] ?? '');
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

    if ($id > 0 && $wantPasswordChange && $password === '') {
        $error = "اكتب كلمة المرور الجديدة أو ألغِ خيار تغيير كلمة المرور.";
    }

    if ($error === '' && $password !== '' && mb_strlen($password, 'UTF-8') < 6) {
        $error = "كلمة المرور لازم تكون 6 أحرف على الأقل.";
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

            if (!$stmt->execute()) {
                $error = "تعذر إضافة المستخدم.";
                error_log('VendorCore users insert error: ' . $stmt->error);
            }
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

            if ($wantPasswordChange && $password !== '') {
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
                    ");
                    $stmt->bind_param("sssiisii", $username, $role, $job_role, $manager_id, $is_supervisor, $whatsapp_number, $whatsapp_enabled, $id);
                }
            }

            if (!$stmt || !$stmt->execute()) {
                $error = "تعذر حفظ بيانات المستخدم.";
                error_log('VendorCore users save error: ' . ($stmt ? $stmt->error : $conn->error));
            }
            if ($stmt) {
                $stmt->close();
            }

            /* لو الأدمن غير باسورد نفسه، نحدث السيشن الحالية عشان ما يخرجش فورًا */
            if ($error === '' && $passwordChanged && $id === $uid) {
                $stmtCurrent = $conn->prepare("SELECT session_version FROM users WHERE id = ? LIMIT 1");
                $stmtCurrent->bind_param("i", $uid);
                $stmtCurrent->execute();
                $v = $stmtCurrent->get_result()->fetch_assoc();
                $stmtCurrent->close();

                $_SESSION['session_version'] = (int)($v['session_version'] ?? 1);
            }

            if ($error === '') {
                $stmt = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($error === '') {
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

$pg = vcPaginationState();
$totalUsersRows = count($users);
$totalPages = vcPaginationTotalPages($totalUsersRows, $pg['per_page']);
$page = min($pg['page'], $totalPages);
$displayUsers = array_slice($users, ($page - 1) * $pg['per_page'], $pg['per_page']);

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

        return [
            'csrf_token' => $csrf_token,
            'isAdmin' => $isAdmin,
            'error' => $error,
            'userStats' => $userStats,
            'displayUsers' => $displayUsers,
            'userNamesById' => $userNamesById,
            'managerOptions' => $managerOptions,
            'pages' => $pages,
            'page' => $page,
            'totalPages' => $totalPages,
            'hasIsAdminColumn' => $hasIsAdminColumn,
        ];
    }
}
