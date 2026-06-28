<?php
/*
    users.php — إدارة المستخدمين
    - حماية auth.php (Admin only)
    - منطق الأعمال في users_helper.php
    - العرض في Views/pages/users.php
*/

require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';
require_once VC_HELPERS . '/users_helper.php';

date_default_timezone_set('Asia/Riyadh');

$pageData = usr_prepare_page($conn, $uid);

$csrf_token = $pageData['csrf_token'];
$isAdmin = $pageData['isAdmin'];
$error = $pageData['error'];
$userStats = $pageData['userStats'];
$displayUsers = $pageData['displayUsers'];
$userNamesById = $pageData['userNamesById'];
$managerOptions = $pageData['managerOptions'];
$pages = $pageData['pages'];
$page = $pageData['page'];
$totalPages = $pageData['totalPages'];

require VC_VIEWS . '/pages/users.php';
