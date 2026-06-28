<?php

date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


try {
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'session_version'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 1");
    }

    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'is_active'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }
} catch (Throwable $e) {
    error_log("login columns check error: " . $e->getMessage());
}


if (!empty($_SESSION['user_id']) && isset($_SESSION['session_version'])) {
    header("Location: dashboard.php");
    exit();
}


ini_set('display_errors', 0);
error_reporting(E_ALL);

$error = "";

if (isset($_GET['session_expired'])) {
    $error = "تم تسجيل خروجك لأن كلمة المرور أو صلاحية الجلسة اتغيرت.";
}

if (isset($_GET['account_disabled'])) {
    $error = "هذا الحساب معطل، تواصل مع الإدارة.";
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = "طلب غير صالح، حدّث الصفحة وجرب مرة أخرى.";
    } else {

        $username = trim((string)($_POST['username'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));

        if ($username === '' || $password === '') {
            $error = "اكتب اسم المستخدم وكلمة المرور.";
        } else {

            try {

                $stmt = $conn->prepare("
                    SELECT id, username, password, role, is_admin, session_version, is_active
                    FROM users
                    WHERE LOWER(TRIM(username)) = LOWER(?)
                    LIMIT 1
                ");
                $stmt->bind_param("s", $username);
                $stmt->execute();

                $result = $stmt->get_result();

                if ($result && $result->num_rows === 1) {

                    $user = $result->fetch_assoc();

                    $storedPassword = (string) ($user['password'] ?? '');
                    $loginOk = $storedPassword !== '' && password_verify($password, $storedPassword);

                    if ($loginOk) {

                        if ((int)($user['is_active'] ?? 1) !== 1) {
                            $error = "هذا الحساب معطل، تواصل مع الإدارة.";
                        } else {

                            if (password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
                                $newHash = password_hash($password, PASSWORD_DEFAULT);
                                $rehashStmt = $conn->prepare('UPDATE users SET password = ?, last_password_change = NOW() WHERE id = ? LIMIT 1');
                                if ($rehashStmt) {
                                    $userId = (int) $user['id'];
                                    $rehashStmt->bind_param('si', $newHash, $userId);
                                    $rehashStmt->execute();
                                    $rehashStmt->close();
                                }
                            }

                            session_regenerate_id(true);

                            $_SESSION['user_id'] = (int)$user['id'];
                            $_SESSION['username'] = trim((string)$user['username']);
                            $_SESSION['is_admin'] = (int)($user['is_admin'] ?? 0);
                            $_SESSION['session_version'] = (int)($user['session_version'] ?? 1);

                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                            header("Location: dashboard.php");
                            exit();
                        }

                    } else {
                        $error = "اسم المستخدم أو كلمة المرور غير صحيحة.";
                    }

                } else {
                    $error = "اسم المستخدم أو كلمة المرور غير صحيحة.";
                }

                $stmt->close();

            } catch (Throwable $e) {
                error_log("Login error: " . $e->getMessage());
                $error = "حدث خطأ مؤقت، حاول مرة أخرى.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تسجيل الدخول | نظام إدارة العقود والإيجارات</title>
<?php vcRenderSiteFavicon(); ?>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(vc_asset('css/vc-login.css')) ?>?v=2">
</head>
<body>

<div class="login-page">
    <div class="login-card">

        <aside class="login-welcome" aria-label="ترحيب">
            <div class="login-welcome-content">
                <h2>مرحباً بعودتك!</h2>
                <p>سجّل دخولك للوصول إلى حسابك وإدارة العقود والإيجارات</p>
                <div class="login-hero">
                    <img
                        src="<?= e(vcSiteLogoUrl()) ?>"
                        alt="إدارة الموردين والعقود"
                        width="260"
                        height="260"
                        loading="lazy"
                    >
                </div>
            </div>
        </aside>

        <section class="login-form-panel">

            <div class="login-form-brand">
                <img src="<?= e(vcSiteLogoUrl()) ?>" alt="نظام إدارة العقود والإيجارات" width="72" height="72" decoding="async">
            </div>

            <h1>تسجيل الدخول</h1>

            <?php if (!empty($error)): ?>
                <div class="login-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off" novalidate>

                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

                <div class="login-field">
                    <label for="username">اسم المستخدم</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        required
                        autofocus
                        value="<?= e($_POST['username'] ?? '') ?>"
                        placeholder="أدخل اسم المستخدم"
                    >
                </div>

                <div class="login-field">
                    <label for="password">كلمة المرور</label>
                    <div class="login-password-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                            placeholder="أدخل كلمة المرور"
                        >
                        <button type="button" class="login-toggle-pass" onclick="togglePassword()">إظهار</button>
                    </div>
                </div>

                <button type="submit" class="login-submit">تسجيل الدخول</button>

            </form>

            <div class="login-footer">
                <strong>نظام إدارة العقود والإيجارات</strong><br>
                © <?= date('Y') ?> جميع الحقوق محفوظة
            </div>

        </section>

    </div>
</div>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    const btn = document.querySelector('.login-toggle-pass');
    if (!input || !btn) return;

    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = 'إخفاء';
    } else {
        input.type = 'password';
        btn.textContent = 'إظهار';
    }
}
</script>

</body>
</html>
