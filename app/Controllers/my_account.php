<?php


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once VC_HELPERS . '/scope_helper.php';


if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}


$vcAccountUid = (int)$_SESSION['user_id'];

try {
    $checkSessionVersion = $conn->query("SHOW COLUMNS FROM users LIKE 'session_version'");
    if ($checkSessionVersion && $checkSessionVersion->num_rows > 0) {

        $stmtVersionCheck = $conn->prepare("SELECT session_version FROM users WHERE id = ? LIMIT 1");
        $stmtVersionCheck->bind_param("i", $vcAccountUid);
        $stmtVersionCheck->execute();
        $vcVersionRow = $stmtVersionCheck->get_result()->fetch_assoc();
        $stmtVersionCheck->close();

        if (!$vcVersionRow) {
            $_SESSION = [];
            session_destroy();
            header("Location: login.php");
            exit();
        }

        if (!isset($_SESSION['session_version'])) {
            $_SESSION['session_version'] = (int)($vcVersionRow['session_version'] ?? 1);
        }

        if ((int)$_SESSION['session_version'] !== (int)($vcVersionRow['session_version'] ?? 1)) {
            $_SESSION = [];
            session_destroy();
            header("Location: login.php?session_expired=1");
            exit();
        }
    }
} catch (Throwable $e) {
    error_log("my_account session_version check error: " . $e->getMessage());
}




mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function vcColumnExists(VcDb $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row) && (int)$row['c'] > 0;
}


if (!vcColumnExists($conn, 'users', 'session_version')) {
    $conn->query("ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 1");
}

if (!vcColumnExists($conn, 'users', 'last_password_change')) {
    $conn->query("ALTER TABLE users ADD COLUMN last_password_change DATETIME NULL");
}

$uid = (int)($_SESSION['user_id'] ?? 0);

if ($uid <= 0) {
    header("Location: login.php");
    exit();
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$success = "";
$error = "";


$stmt = $conn->prepare("
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
");
$stmt->bind_param("i", $uid);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$currentUser) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$is_admin = (
    (int)($currentUser['is_admin'] ?? 0) === 1 ||
    ($currentUser['role'] ?? '') === 'admin'
);


$roleText = 'مستخدم';
try {
    $financeManagerId = 0;
    $foodSectionManagerId = 0;
    $nonFoodSectionManagerId = 0;

    $settingsRes = $conn->query("
        SELECT setting_key, user_id
        FROM payment_approval_settings
        WHERE setting_key IN ('finance_manager','food_section_manager','non_food_section_manager')
    ");

    if ($settingsRes) {
        while ($settingRow = $settingsRes->fetch_assoc()) {
            if ((string)$settingRow['setting_key'] === 'finance_manager') {
                $financeManagerId = (int)$settingRow['user_id'];
            } elseif ((string)$settingRow['setting_key'] === 'food_section_manager') {
                $foodSectionManagerId = (int)$settingRow['user_id'];
            } elseif ((string)$settingRow['setting_key'] === 'non_food_section_manager') {
                $nonFoodSectionManagerId = (int)$settingRow['user_id'];
            }
        }
    }

    if ($financeManagerId <= 0) {
        $financeManagerId = 19; 
    }

    $jobRole = (string)($currentUser['job_role'] ?? 'user');
    $role = (string)($currentUser['role'] ?? 'user');
    $isAdminAccount = ((int)($currentUser['is_admin'] ?? 0) === 1) || $role === 'admin' || $jobRole === 'admin';

    if ($jobRole === 'finance_manager' || $uid === $financeManagerId) {
        $roleText = 'مدير مالي';
    } elseif ($isAdminAccount) {
        $roleText = 'أدمن';
    } elseif ($jobRole === 'commercial_manager') {
        $roleText = 'مدير تجاري';
    } elseif ($uid === $foodSectionManagerId && $uid === $nonFoodSectionManagerId) {
        $roleText = 'مدير قسم غذائي ولا غذائي';
    } elseif ($uid === $foodSectionManagerId) {
        $roleText = 'مدير قسم غذائي';
    } elseif ($uid === $nonFoodSectionManagerId) {
        $roleText = 'مدير قسم لا غذائي';
    } elseif ($jobRole === 'section_manager') {
        $roleText = 'مدير قسم';
    } elseif ($jobRole === 'accountant') {
        $roleText = 'محاسب';
    }
} catch (Throwable $e) {
    error_log("my_account role display error: " . $e->getMessage());
}

$username = (string)($currentUser['username'] ?? 'مستخدم');
$firstLetter = function_exists('mb_substr') ? mb_substr($username, 0, 1, 'UTF-8') : substr($username, 0, 1);


$pagesCount = 0;

if ($is_admin) {
    $q = $conn->query("SELECT COUNT(*) AS c FROM pages WHERE status = 1");
    if ($q) {
        $pagesCount = (int)($q->fetch_assoc()['c'] ?? 0);
    }
} else {
    $stmtPages = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM user_permissions up
        JOIN pages p ON p.id = up.page_id
        WHERE up.user_id = ?
        AND p.status = 1
    ");
    $stmtPages->bind_param("i", $uid);
    $stmtPages->execute();
    $pagesCount = (int)($stmtPages->get_result()->fetch_assoc()['c'] ?? 0);
    $stmtPages->close();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {

    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = "الطلب غير صالح، أعد المحاولة.";
    }

    $currentPassword = trim((string)($_POST['current_password'] ?? ''));
    $newPassword     = trim((string)($_POST['new_password'] ?? ''));
    $confirmPassword = trim((string)($_POST['confirm_password'] ?? ''));

    if ($error === '' && $currentPassword === '') {
        $error = "اكتب كلمة المرور الحالية.";
    }

    if ($error === '' && $newPassword === '') {
        $error = "اكتب كلمة المرور الجديدة.";
    }

    if ($error === '' && mb_strlen($newPassword, 'UTF-8') < 6) {
        $error = "كلمة المرور الجديدة لازم تكون 6 أحرف على الأقل.";
    }

    if ($error === '' && $newPassword !== $confirmPassword) {
        $error = "تأكيد كلمة المرور غير مطابق.";
    }

    if ($error === '') {
        $storedPassword = (string)($currentUser['password'] ?? '');

        if (!password_verify($currentPassword, $storedPassword)) {
            $error = "كلمة المرور الحالية غير صحيحة.";
        }
    }

    if ($error === '') {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmtUpdate = $conn->prepare("
            UPDATE users
            SET password = ?,
                session_version = session_version + 1,
                last_password_change = NOW()
            WHERE id = ?
            LIMIT 1
        ");
        $stmtUpdate->bind_param("si", $newHash, $uid);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        
        $stmtVersion = $conn->prepare("SELECT session_version, last_password_change FROM users WHERE id = ? LIMIT 1");
        $stmtVersion->bind_param("i", $uid);
        $stmtVersion->execute();
        $fresh = $stmtVersion->get_result()->fetch_assoc();
        $stmtVersion->close();

        $_SESSION['session_version'] = (int)($fresh['session_version'] ?? 1);

        $currentUser['session_version'] = $_SESSION['session_version'];
        $currentUser['last_password_change'] = $fresh['last_password_change'] ?? date('Y-m-d H:i:s');

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $csrf_token = $_SESSION['csrf_token'];

        $success = "تم تغيير كلمة المرور بنجاح.";
    }
}

$lastPasswordChange = trim((string)($currentUser['last_password_change'] ?? ''));
$lastPasswordText = $lastPasswordChange !== '' ? date('Y-m-d H:i', strtotime($lastPasswordChange)) : 'غير متاح';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>حسابي</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">

<style>
*{
    box-sizing:border-box;
    font-family:'Cairo', Tahoma, Arial, sans-serif;
}

html, body{
    min-height:100%;
}

body{
    margin:0;
    background:
        radial-gradient(circle at top right, rgba(109,74,255,.12), transparent 35%),
        #eef1f7;
    color:#172033;
}

.page-wrap{
    width:min(1180px, calc(100% - 28px));
    margin:0 auto 45px;
}

.account-hero{
    background:rgba(255,255,255,.62);
    border:1px solid rgba(226,232,240,.95);
    border-radius:28px;
    padding:26px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    display:grid;
    grid-template-columns:auto 1fr auto;
    gap:20px;
    align-items:center;
    margin-bottom:18px;
}

.big-avatar{
    width:92px;
    height:92px;
    border-radius:28px;
    background:
        radial-gradient(circle at 30% 18%, rgba(255,255,255,.55), transparent 22%),
        linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:36px;
    font-weight:900;
    box-shadow:0 14px 30px rgba(79,70,229,.25), inset 0 2px 5px rgba(255,255,255,.30);
}

.hero-title{
    font-size:26px;
    font-weight:900;
    color:#172033;
    margin:0 0 6px;
}

.hero-sub{
    margin:0;
    color:#667085;
    font-weight:800;
    line-height:1.8;
}

.hero-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 14px;
    border-radius:999px;
    background:#f0edff;
    color:#4f46e5;
    font-weight:900;
    white-space:nowrap;
}

.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
}

.card{
    background:rgba(255,255,255,.62);
    border:1px solid rgba(226,232,240,.95);
    border-radius:24px;
    padding:22px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}

.card-title{
    display:flex;
    align-items:center;
    gap:9px;
    font-size:18px;
    font-weight:900;
    color:#172033;
    margin-bottom:16px;
}

.card-title i{
    width:38px;
    height:38px;
    border-radius:14px;
    background:#f0edff;
    color:#6d4aff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:21px;
}

.info-list{
    display:grid;
    gap:12px;
}

.info-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    background:#eef1f7;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:13px 14px;
    box-shadow:inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff;
}

.info-label{
    color:#667085;
    font-weight:900;
    font-size:13px;
}

.info-value{
    color:#172033;
    font-weight:900;
    overflow-wrap:anywhere;
    text-align:left;
    direction:ltr;
}

.form-grid{
    display:grid;
    gap:14px;
}

.field label{
    display:block;
    font-size:13px;
    font-weight:900;
    color:#172033;
    margin-bottom:8px;
}

input{
    width:100%;
    min-height:48px;
    padding:0 14px;
    border-radius:15px;
    border:1px solid #dfe6f0;
    background:#eef1f7;
    color:#172033;
    box-shadow:inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff;
    outline:none;
    font-size:14px;
}

input:focus{
    border-color:#6d4aff;
    box-shadow:0 0 0 3px rgba(109,74,255,.12), inset 2px 2px 6px #d1d9e6, inset -2px -2px 6px #fff;
}

.actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:6px;
}

.btn{
    border:0;
    border-radius:15px;
    padding:13px 18px;
    font-weight:900;
    cursor:pointer;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:48px;
}

.btn-primary{
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    box-shadow:0 14px 25px rgba(79,70,229,.22);
}

.btn-soft{
    background:#eef1f7;
    color:#4f46e5;
    box-shadow:inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff;
}

.alert{
    border-radius:17px;
    padding:13px 15px;
    font-weight:900;
    margin-bottom:14px;
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

.security-note{
    margin-top:14px;
    background:#fff7ed;
    border:1px solid #fed7aa;
    color:#9a3412;
    border-radius:16px;
    padding:12px;
    font-size:13px;
    font-weight:800;
    line-height:1.8;
}

#password-section{
    scroll-margin-top:24px;
}

#password-section:target{
    box-shadow:0 0 0 3px rgba(79,70,229,.18), 8px 8px 18px #d1d9e6, -8px -8px 18px #fff;
}

@media(max-width:850px){
    .account-hero{
        grid-template-columns:1fr;
        text-align:center;
        justify-items:center;
    }

    .grid{
        grid-template-columns:1fr;
    }

    .info-row{
        align-items:flex-start;
        flex-direction:column;
    }

    .info-value{
        text-align:right;
        direction:rtl;
    }
}
</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="page-wrap">

    <div class="account-hero">
        <div class="big-avatar"><?= e($firstLetter) ?></div>

        <div>
            <h1 class="hero-title">حسابي</h1>
            <p class="hero-sub">
                إدارة بيانات الحساب وتغيير كلمة المرور الخاصة بك داخل VendorCore.
            </p>
        </div>

        <div class="hero-badge">
            <i class="ri-shield-user-line"></i>
            <?= e($roleText) ?>
        </div>
    </div>

    <?php if($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="grid">

        <div class="card">
            <div class="card-title">
                <i class="ri-user-3-line"></i>
                <span>بيانات الحساب</span>
            </div>

            <div class="info-list">
                <div class="info-row">
                    <div class="info-label">اسم المستخدم</div>
                    <div class="info-value"><?= e($username) ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">نوع الحساب</div>
                    <div class="info-value"><?= e($roleText) ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">الصفحات المتاحة</div>
                    <div class="info-value"><?= (int)$pagesCount ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">آخر تغيير لكلمة المرور</div>
                    <div class="info-value"><?= e($lastPasswordText) ?></div>
                </div>
            </div>

            <div class="actions">
                <a class="btn btn-soft" href="dashboard.php">
                    <i class="ri-dashboard-line"></i>
                    رجوع للداشبورد
                </a>

                <a class="btn btn-soft" href="logout.php">
                    <i class="ri-logout-box-r-line"></i>
                    تسجيل خروج
                </a>
            </div>
        </div>

        <div class="card" id="password-section">
            <div class="card-title">
                <i class="ri-lock-password-line"></i>
                <span>تغيير كلمة المرور</span>
            </div>

            <form method="POST" class="form-grid" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="field">
                    <label>كلمة المرور الحالية</label>
                    <input type="password" name="current_password" required>
                </div>

                <div class="field">
                    <label>كلمة المرور الجديدة</label>
                    <input type="password" name="new_password" minlength="6" required>
                </div>

                <div class="field">
                    <label>تأكيد كلمة المرور الجديدة</label>
                    <input type="password" name="confirm_password" minlength="6" required>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="ri-save-3-line"></i>
                        حفظ كلمة المرور
                    </button>
                </div>
            </form>

            <div class="security-note">
                عند تغيير كلمة المرور، يتم تحديث جلسة الحساب الحالية بأمان. أي جلسات قديمة أخرى سيتم خروجها عند فتح أي صفحة محمية.
            </div>
        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    if (window.location.hash !== '#password-section') return;
    const section = document.getElementById('password-section');
    if (!section) return;
    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
</script>

</body>
</html>
