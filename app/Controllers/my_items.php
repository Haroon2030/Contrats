<?php
/*
    my_items.php — أصنافي
    - حماية auth.php + نطاق الصلاحيات
    - منطق الأعمال في my_items_helper.php
    - العرض في Views/pages/my_items.php
*/

require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';
require_once VC_HELPERS . '/my_items_helper.php';

date_default_timezone_set('Asia/Riyadh');

$csrf_token = mi_ensure_csrf_token();
$access = mi_load_access_context($conn, $uid);

$is_admin = $access['is_admin'];
$can_view_all = $access['can_view_all'];
$show_user_filter = $access['show_user_filter'];
$scoped_user_ids = $access['scoped_user_ids'];

$search = trim((string) ($_GET['search'] ?? ''));
$user_filter = mi_sanitize_user_filter(
    trim((string) ($_GET['user'] ?? '')),
    $show_user_filter,
    $scoped_user_ids
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete_item_batches') {
    mi_handle_bulk_delete($conn, $is_admin);
}

$bulk_delete_msg = (string) ($_SESSION['my_items_bulk_delete_msg'] ?? '');
unset($_SESSION['my_items_bulk_delete_msg']);

$users_result = $show_user_filter
    ? vcGetVisibleUsersForFilter($conn, $scoped_user_ids)
    : [];

$query = mi_build_query_parts($scoped_user_ids, $user_filter, $search);
$summary = mi_fetch_summary($conn, $query['from_where'], $query['params'], $query['types']);
$list = mi_fetch_batches($conn, $query['from_where'], $query['params'], $query['types']);

$rows = $list['rows'];
$page = $list['page'];
$total_pages = $list['total_pages'];

require VC_VIEWS . '/pages/my_items.php';
