<?php


session_start();

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
                    WHERE TRIM(username) = ?
                    LIMIT 1
                ");
                $stmt->bind_param("s", $username);
                $stmt->execute();

                $result = $stmt->get_result();

                if ($result && $result->num_rows === 1) {

                    $user = $result->fetch_assoc();

                    $storedPassword = trim((string)($user['password'] ?? ''));
                    $loginOk = false;

                    if (hash_equals($storedPassword, $password)) {
                        $loginOk = true;
                    }

                    if (!$loginOk && password_verify($password, $storedPassword)) {
                        $loginOk = true;
                    }

                    if (!$loginOk && hash_equals($storedPassword, md5($password))) {
                        $loginOk = true;
                    }

                    if (!$loginOk && hash_equals($storedPassword, sha1($password))) {
                        $loginOk = true;
                    }

                    if ($loginOk) {

                        if ((int)($user['is_active'] ?? 1) !== 1) {
                            $error = "هذا الحساب معطل، تواصل مع الإدارة.";
                        } else {

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
<title>VendorCore | تسجيل الدخول</title>

<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
*{
    box-sizing:border-box;
    font-family:'Cairo', Tahoma, Arial, sans-serif;
}

html, body{
    direction:rtl;
}

body{
    margin:0;
    min-height:100vh;
    color:#172033;
    background:
        radial-gradient(circle at 20% 18%, rgba(109,74,255,.14), transparent 30%),
        radial-gradient(circle at 82% 78%, rgba(14,165,233,.10), transparent 33%),
        linear-gradient(135deg,#f8fafc 0%,#eef1f7 100%);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
}

.login-shell{
    width:min(980px, 100%);
    min-height:560px;
    display:grid;
    grid-template-columns:1fr 430px;
    background:rgba(255,255,255,.58);
    border:1px solid rgba(226,232,240,.95);
    border-radius:34px;
    overflow:hidden;
    box-shadow:0 28px 70px rgba(23,32,51,.13);
    backdrop-filter:blur(10px);
}

.brand-panel{
    position:relative;
    overflow:hidden;
    background:
        radial-gradient(circle at 18% 82%, rgba(14,165,233,.18), transparent 35%),
        linear-gradient(145deg,#111b3f 0%,#342f86 52%,#5547e7 100%);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:42px;
}

.brand-panel::before{
    content:"";
    position:absolute;
    width:430px;
    height:430px;
    border-radius:50%;
    background:rgba(255,255,255,.07);
    left:-150px;
    top:-130px;
}

.brand-panel::after{
    content:"";
    position:absolute;
    width:300px;
    height:300px;
    border-radius:50%;
    border:1px solid rgba(255,255,255,.12);
    right:-105px;
    bottom:-105px;
}

.brand-logo-card{
    position:relative;
    z-index:1;
    width:min(360px, 100%);
    min-height:245px;
    border-radius:34px;
    background:rgba(255,255,255,.93);
    border:1px solid rgba(255,255,255,.65);
    box-shadow:0 24px 52px rgba(0,0,0,.20);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:30px;
}

.brand-logo-card img{
    width:min(285px, 100%);
    max-height:175px;
    object-fit:contain;
    display:block;
}

.login-card{
    background:rgba(255,255,255,.86);
    padding:48px 42px;
    display:flex;
    flex-direction:column;
    justify-content:center;
}

.card-head{
    margin-bottom:26px;
}

.card-title{
    margin:0;
    font-size:30px;
    font-weight:900;
    color:#172033;
    letter-spacing:-.3px;
}

.card-line{
    width:54px;
    height:5px;
    border-radius:999px;
    background:linear-gradient(90deg,#4f46e5,#0ea5e9);
    margin-top:13px;
}

.error{
    background:#fff1f2;
    color:#b42318;
    border:1px solid #fecdd3;
    padding:12px 14px;
    border-radius:14px;
    margin-bottom:16px;
    font-size:13px;
    font-weight:900;
    line-height:1.7;
}

.input-group{
    margin-bottom:17px;
}

.input-group label{
    display:block;
    font-size:13px;
    color:#172033;
    font-weight:900;
    margin-bottom:8px;
}

.input-wrap{
    position:relative;
}

.input-wrap input{
    width:100%;
    min-height:54px;
    padding:0 44px 0 14px;
    border-radius:16px;
    border:1px solid #dfe6f0;
    background:#f3f6fb;
    color:#172033;
    box-shadow:inset 2px 2px 6px rgba(209,217,230,.8), inset -2px -2px 6px #fff;
    font-size:15px;
    font-weight:800;
    outline:none;
    transition:.18s ease;
}

.input-wrap input:focus{
    border-color:#6d4aff;
    background:#fff;
    box-shadow:0 0 0 4px rgba(109,74,255,.11);
}

.input-icon{
    position:absolute;
    right:14px;
    top:50%;
    transform:translateY(-50%);
    font-size:17px;
    opacity:.72;
}

.toggle-pass{
    position:absolute;
    left:10px;
    top:50%;
    transform:translateY(-50%);
    border:0;
    background:transparent;
    cursor:pointer;
    font-weight:900;
    color:#4f46e5;
    padding:5px 8px;
}

.login-btn{
    width:100%;
    min-height:56px;
    padding:0 18px;
    background:linear-gradient(145deg,#6d4aff,#4f46e5);
    color:#fff;
    border:none;
    border-radius:17px;
    cursor:pointer;
    font-size:16px;
    font-weight:900;
    margin-top:10px;
    transition:.18s ease;
    box-shadow:0 16px 28px rgba(79,70,229,.24);
}

.login-btn:hover{
    transform:translateY(-1px);
    filter:brightness(.98);
}

.login-footer{
    margin-top:22px;
    text-align:center;
    font-size:12px;
    color:#8a94a6;
    font-weight:800;
}

@media(max-width:880px){
    .login-shell{
        grid-template-columns:1fr;
        min-height:auto;
    }

    .brand-panel{
        min-height:290px;
    }

    .brand-logo-card{
        min-height:190px;
        width:min(330px, 100%);
    }

    .brand-logo-card img{
        width:min(260px, 100%);
    }
}

@media(max-width:520px){
    body{
        padding:12px;
    }

    .login-shell{
        border-radius:24px;
    }

    .brand-panel,
    .login-card{
        padding:26px;
    }

    .card-title{
        font-size:24px;
    }
}
</style>
</head>
<body>

<div class="login-shell">

    <section class="brand-panel" aria-label="VendorCore">
        <div class="brand-logo-card">
            <img src="/uploads/vendorcore_header.png?v=30" alt="VendorCore">
        </div>
    </section>

    <section class="login-card">

        <div class="card-head">
            <h1 class="card-title">تسجيل الدخول</h1>
            <div class="card-line"></div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">

            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="input-group">
                <label for="username">اسم المستخدم</label>
                <div class="input-wrap">
                    <span class="input-icon">👤</span>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        required
                        autofocus
                        value="<?= e($_POST['username'] ?? '') ?>"
                        placeholder="اسم المستخدم"
                    >
                </div>
            </div>

            <div class="input-group">
                <label for="password">كلمة المرور</label>
                <div class="input-wrap">
                    <span class="input-icon">🔐</span>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        placeholder="كلمة المرور"
                    >
                    <button type="button" class="toggle-pass" onclick="togglePassword()">إظهار</button>
                </div>
            </div>

            <button type="submit" class="login-btn">دخول</button>

        </form>

        <div class="login-footer">
            © <?= date("Y") ?> VendorCore
        </div>

    </section>

</div>

<script>
function togglePassword(){
    const input = document.getElementById("password");
    const btn = document.querySelector(".toggle-pass");

    if(input.type === "password"){
        input.type = "text";
        btn.innerText = "إخفاء";
    }else{
        input.type = "password";
        btn.innerText = "إظهار";
    }
}
</script>

</body>
</html>
