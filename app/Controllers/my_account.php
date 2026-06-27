<?php
/*
    my_account.php — حسابي
    - حماية عبر auth.php
    - CSRF لتغيير كلمة المرور
    - منطق الأعمال في user_account_helper.php
    - العرض في Views/pages/my_account.php
*/

require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';
require_once VC_HELPERS . '/user_account_helper.php';

date_default_timezone_set('Asia/Riyadh');

ma_ensureUserColumns($conn);

$csrf_token = ma_ensureCsrfToken();
$success = '';
$error = '';

$currentUser = ma_fetchCurrentUser($conn, $uid);

if (!$currentUser) {
    session_destroy();
    header('Location: login.php');
    exit();
}

$is_admin = ma_isAdminAccount($currentUser);
$roleText = ma_resolveRoleLabel($conn, $uid, $currentUser);
$username = (string) ($currentUser['username'] ?? 'مستخدم');
$firstLetter = ma_firstLetter($username);
$pagesCount = ma_countAccessiblePages($conn, $uid, $is_admin);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $changeResult = ma_handlePasswordChange($conn, $uid, $currentUser);
    $success = $changeResult['success'];
    $error = $changeResult['error'];
    $currentUser = $changeResult['user'];
    $csrf_token = (string) ($_SESSION['csrf_token'] ?? $csrf_token);
}

$lastPasswordText = ma_formatLastPasswordChange($currentUser['last_password_change'] ?? '');

require VC_VIEWS . '/pages/my_account.php';
