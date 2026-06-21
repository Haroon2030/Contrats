<?php


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once VC_CONFIG . '/config.php';


if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$uid = (int)$_SESSION['user_id'];


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
    error_log("auth columns check error: " . $e->getMessage());
}


$stmt = $conn->prepare("
    SELECT id, username, role, is_admin, job_role, session_version, is_active
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    die("❌ خطأ في التحقق من المستخدم");
}

$stmt->bind_param("i", $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit();
}


if ((int)($user['is_active'] ?? 1) !== 1) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php?account_disabled=1");
    exit();
}

$dbSessionVersion = (int)($user['session_version'] ?? 1);


if (!isset($_SESSION['session_version'])) {

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    header("Location: login.php?session_expired=1");
    exit();
}


if ((int)$_SESSION['session_version'] !== $dbSessionVersion) {

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    header("Location: login.php?session_expired=1");
    exit();
}


$_SESSION['username'] = $user['username'] ?? ($_SESSION['username'] ?? '');
$_SESSION['is_admin'] = (int)($user['is_admin'] ?? 0);


if (
    (int)($user['is_admin'] ?? 0) === 1 ||
    ($user['role'] ?? '') === 'admin' ||
    in_array((string)($user['job_role'] ?? ''), ['admin', 'commercial_manager'], true)
) {
    return;
}


$page = defined('VC_PAGE') ? VC_PAGE : basename($_SERVER['PHP_SELF'], '.php');


$public_pages = [
    'dashboard',
    'logout',

    
    'view_items'
];

if (in_array($page, $public_pages, true)) {
    return;
}


$stmt = $conn->prepare("
    SELECT COUNT(*) AS c
    FROM user_permissions up
    JOIN pages p ON up.page_id = p.id
    WHERE up.user_id = ?
    AND p.name = ?
    AND p.status = 1
");

if (!$stmt) {
    die("❌ خطأ في التحقق من الصلاحيات");
}

$stmt->bind_param("is", $uid, $page);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res || (int)$res['c'] === 0) {
    die("❌ ليس لديك صلاحية الدخول");
}
