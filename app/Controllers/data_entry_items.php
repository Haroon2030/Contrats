<?php
/*
    data_entry_items.php — إدخال الأصناف
    - حماية auth.php + فحص الصلاحية
    - منطق الأعمال في data_entry_items_helper.php
    - العرض في Views/pages/data_entry_items.php
*/

require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';
require_once VC_HELPERS . '/data_entry_items_helper.php';

date_default_timezone_set('Asia/Riyadh');

dei_ensure_items_columns($conn);

$stmtUser = $conn->prepare('SELECT is_admin, role FROM users WHERE id = ? LIMIT 1');
$stmtUser->bind_param('i', $uid);
$stmtUser->execute();
$currentUser = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$is_admin = dei_is_admin_user($currentUser);
dei_assert_access($conn, $uid, $is_admin);

$csrf_token = dei_ensure_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'mark_entered') {
        dei_handle_mark_entered($conn, $uid, $is_admin);
    }

    if ($action === 'delete_items_batch') {
        dei_handle_delete_batch($conn, $is_admin);
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$entry_filter = dei_parse_entry_filter((string) ($_GET['entry'] ?? ''));

$list = dei_fetch_batches($conn, $search, $entry_filter);

$rows = $list['rows'];
$doneCount = $list['done_count'];
$pendingCount = $list['pending_count'];
$totalRequests = $list['total_requests'];
$page = $list['page'];
$totalPages = $list['total_pages'];

require VC_VIEWS . '/pages/data_entry_items.php';
