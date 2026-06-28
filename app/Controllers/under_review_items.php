<?php
/*
    under_review_items.php — الأصناف تحت المراجعة
    - حماية auth.php + نطاق الصلاحيات
    - منطق الأعمال في under_review_items_helper.php
    - العرض في Views/pages/under_review_items.php
*/

require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';
require_once VC_HELPERS . '/under_review_items_helper.php';

date_default_timezone_set('Asia/Riyadh');

$csrf_token = uri_ensure_csrf_token();
$access = uri_load_access_context($conn, $uid);

$is_admin = $access['is_admin'];
$show_user_column = $access['show_user_column'];
$scoped_user_ids = $access['scoped_user_ids'];

$search = trim((string) ($_GET['search'] ?? ''));
$user_filter = uri_sanitize_user_filter(
    trim((string) ($_GET['user'] ?? '')),
    $show_user_column,
    $scoped_user_ids
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_review_item_batch') {
    uri_handle_delete_batch($conn, $is_admin);
}

$page_msg = (string) ($_SESSION['under_review_items_msg'] ?? '');
unset($_SESSION['under_review_items_msg']);

$users_result = $show_user_column
    ? vcGetVisibleUsersForFilter($conn, $scoped_user_ids)
    : [];

$query = uri_build_query_parts($scoped_user_ids, $user_filter, $search);
$summary = uri_fetch_summary($conn, $query['from_where'], $query['params'], $query['types']);
$list = uri_fetch_batches($conn, $query['from_where'], $query['params'], $query['types']);

$rows = $list['rows'];
$page = $list['page'];
$total_pages = $list['total_pages'];

require VC_VIEWS . '/pages/under_review_items.php';
