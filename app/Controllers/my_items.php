<?php
require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';



date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value): string {
    return number_format((float)$value, 2);
}

function statusText(string $status): string {
    if ($status === 'approved') {
        return 'تمت الموافقة';
    }

    if ($status === 'rejected') {
        return 'مرفوض';
    }

    return 'غير معروف';
}

function statusClass(string $status): string {
    return $status === 'rejected' ? 'rejected' : 'approved';
}

function tableExists(VcDb $conn, string $table): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("s", $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row) && (int)$row['c'] > 0;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    header("Location: login.php");
    exit();
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


$search = trim($_GET['search'] ?? '');
$user_filter = trim($_GET['user'] ?? '');


$scope = 'own';
$canViewAllItems = false;
$showUserFilter = false;
$scopedUserIds = [$user_id];


$stmtAdmin = $conn->prepare("SELECT is_admin, role, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1");
$stmtAdmin->bind_param("i", $user_id);
$stmtAdmin->execute();
$adminRow = $stmtAdmin->get_result()->fetch_assoc();
$stmtAdmin->close();

$is_admin = !empty($adminRow) && (
    (int)($adminRow['is_admin'] ?? 0) === 1 ||
    ($adminRow['role'] ?? '') === 'admin'
);


$stmtScope = $conn->prepare("
    SELECT up.scope
    FROM user_permissions up
    INNER JOIN pages p ON p.id = up.page_id
    WHERE up.user_id = ?
    AND (
        p.name IN ('my_items', 'my_items.php')
        OR p.title IN ('أصنافي', 'اصنافي')
    )
    LIMIT 1
");
$stmtScope->bind_param("i", $user_id);
$stmtScope->execute();
$scopeRow = $stmtScope->get_result()->fetch_assoc();
$stmtScope->close();

if (!empty($scopeRow) && in_array(($scopeRow['scope'] ?? ''), ['own','team','all'], true)) {
    $scope = (string)$scopeRow['scope'];
}
$scopedUserIds = vcGetScopedUserIds($conn, $user_id, $scope, $is_admin);
$canViewAllItems = empty($scopedUserIds);
$showUserFilter = ($is_admin || in_array($scope, ['team','all'], true));

if (!$showUserFilter || ($user_filter !== '' && !vcIsUserInScope((int)$user_filter, $scopedUserIds))) {
    $user_filter = '';
}

$users_result = $showUserFilter ? vcGetVisibleUsersForFilter($conn, $scopedUserIds) : [];


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete_item_batches') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    if (!$is_admin) {
        http_response_code(403);
        die("❌ ليس لديك صلاحية حذف دفعات الأصناف");
    }

    $batchIds = $_POST['batch_ids'] ?? [];

    if (!is_array($batchIds)) {
        $batchIds = [];
    }

    $cleanBatches = [];

    foreach ($batchIds as $batchId) {
        $batchId = trim((string)$batchId);

        if ($batchId !== '') {
            $cleanBatches[] = $batchId;
        }
    }

    $cleanBatches = array_values(array_unique($cleanBatches));

    if (!empty($cleanBatches)) {
        $placeholders = implode(',', array_fill(0, count($cleanBatches), '?'));
        $bindTypes = str_repeat('s', count($cleanBatches));

        $conn->begin_transaction();

        try {
            if (tableExists($conn, 'approval_withdrawals')) {
                $sqlWithdrawals = "
                    DELETE FROM approval_withdrawals
                    WHERE target_type = 'items'
                    AND target_id IN ({$placeholders})
                ";
                $stmtWithdrawals = $conn->prepare($sqlWithdrawals);
                if ($stmtWithdrawals) {
                    $stmtWithdrawals->bind_param($bindTypes, ...$cleanBatches);
                    $stmtWithdrawals->execute();
                    $stmtWithdrawals->close();
                }
            }

            $sqlItems = "DELETE FROM items WHERE batch_id IN ({$placeholders})";
            $stmtItems = $conn->prepare($sqlItems);

            if (!$stmtItems) {
                throw new Exception("تعذر تجهيز حذف دفعات الأصناف: " . $conn->error);
            }

            $stmtItems->bind_param($bindTypes, ...$cleanBatches);
            $stmtItems->execute();
            $deletedItemsCount = $stmtItems->affected_rows;
            $stmtItems->close();

            $conn->commit();

            $_SESSION['my_items_bulk_delete_msg'] =
                "تم إرسال " . count($cleanBatches) . " دفعة للحذف، وتم حذف " . (int)$deletedItemsCount . " صنف فعليًا.";
        } catch (Throwable $e) {
            $conn->rollback();
            die("ERROR: " . $e->getMessage());
        }
    } else {
        $_SESSION['my_items_bulk_delete_msg'] = "لم يتم تحديد أي دفعات للحذف.";
    }

    header("Location: my_items.php");
    exit();
}

$bulkDeleteMsg = $_SESSION['my_items_bulk_delete_msg'] ?? '';
unset($_SESSION['my_items_bulk_delete_msg']);


$fromWhere = "
    FROM items i
    LEFT JOIN users u ON u.id = i.deducted_by
    LEFT JOIN users creator ON creator.id = i.created_by
    WHERE i.status IN ('approved','rejected')
";

$params = [];
$types  = "";
$fromWhere .= vcBuildInCondition('i.created_by', $scopedUserIds, $params, $types);

if ($user_filter !== '') {
    $fromWhere .= " AND i.created_by = ?";
    $params[] = (int)$user_filter;
    $types .= "i";
}

if ($search !== '') {
    $fromWhere .= " AND (
        i.supplier_name LIKE ?
        OR i.batch_id LIKE ?
        OR creator.username LIKE ?
    )";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

$summarySql = "
    SELECT
        COUNT(DISTINCT i.batch_id) AS total_requests,
        SUM(CASE WHEN i.status = 'approved' THEN 1 ELSE 0 END) AS total_approved_items,
        COALESCE(SUM(CASE WHEN i.status = 'approved' THEN i.fee ELSE 0 END), 0) AS total_approved_fees,
        COUNT(DISTINCT CASE WHEN i.status = 'approved' THEN i.batch_id END) AS approved_batches,
        COUNT(DISTINCT CASE WHEN i.status = 'rejected' THEN i.batch_id END) AS rejected_batches
    {$fromWhere}
";

$stmtSummary = $conn->prepare($summarySql);
if (!empty($params)) {
    $stmtSummary->bind_param($types, ...$params);
}
$stmtSummary->execute();
$summary = $stmtSummary->get_result()->fetch_assoc() ?: [];
$stmtSummary->close();

$totalRequests = (int)($summary['total_requests'] ?? 0);
$totalApprovedItems = (int)($summary['total_approved_items'] ?? 0);
$totalApprovedFees = (float)($summary['total_approved_fees'] ?? 0);
$approvedCount = (int)($summary['approved_batches'] ?? 0);
$rejectedCount = (int)($summary['rejected_batches'] ?? 0);

$groupCountSql = "
    SELECT i.batch_id
    {$fromWhere}
    GROUP BY 
        i.batch_id,
        i.supplier_name,
        i.created_by,
        creator.username,
        u.username
";

$pg = vcPaginationState();
$totalRows = vcPaginationCountGrouped($conn, $groupCountSql, $params, $types);
$totalPages = vcPaginationTotalPages($totalRows, $pg['per_page']);
$page = min($pg['page'], $totalPages);

$sql = "
    SELECT 
        i.batch_id,
        i.supplier_name,
        COUNT(*) AS request_items_count,
        SUM(CASE WHEN i.status = 'approved' THEN 1 ELSE 0 END) AS approved_items_count,
        COALESCE(SUM(CASE WHEN i.status = 'approved' THEN i.fee ELSE 0 END), 0) AS approved_total_fees,
        MAX(i.status) AS status,
        MAX(i.created_at) AS created_at,
        MAX(i.approved_at) AS approved_at,
        MAX(i.rejected_at) AS rejected_at,
        MAX(i.paid) AS paid,
        MAX(i.deducted_by) AS deducted_by,
        MAX(i.deducted_at) AS deducted_at,
        i.created_by,
        creator.username AS creator_username,
        u.username AS deducted_username
    {$fromWhere}
    GROUP BY 
        i.batch_id,
        i.supplier_name,
        i.created_by,
        creator.username,
        u.username
    ORDER BY i.batch_id DESC
    LIMIT ? OFFSET ?
";

[$dataParams, $dataTypes] = vcPaginationBindLimit($params, $types, $pg['limit'], ($page - 1) * $pg['per_page']);

$stmt = $conn->prepare($sql);

if (!empty($dataParams)) {
    $stmt->bind_param($dataTypes, ...$dataParams);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>أصنافي</title>

<link rel="stylesheet" href="public/assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
*{
    box-sizing:border-box;
    font-family:'Cairo', Tahoma, Arial, sans-serif;
}

html, body{
    direction:rtl;
    text-align:right;
}

body{
    margin:0;
    background:
        radial-gradient(circle at top right, rgba(109,74,255,.11), transparent 34%),
        #eef1f7;
    color:#172033;
}

.container{
    width:min(1180px, calc(100% - 32px));
    margin:28px auto 45px;
}


body.wide-table-mode .container{
    width:min(1480px, calc(100% - 14px));
}

.page-head{
    text-align:center;
    margin-bottom:22px;
}

.page-title{
    margin:0 0 7px;
    font-size:28px;
    font-weight:900;
    color:#172033;
    letter-spacing:-.3px;
}

.page-subtitle{
    margin:0;
    color:#667085;
    font-size:15px;
    line-height:1.9;
    font-weight:700;
}


.summary-grid{
    display:grid;
    grid-template-columns:repeat(5, 1fr);
    gap:14px;
    margin-bottom:18px;
}

.summary-card{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:20px;
    padding:16px;
    text-align:center;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}

.summary-value{
    font-size:24px;
    font-weight:900;
    color:#4f46e5;
    margin-bottom:6px;
}

.summary-label{
    font-size:13px;
    color:#667085;
    font-weight:800;
}


.search-box{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:14px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    margin-bottom:18px;
}

.search-box{
    display:grid;
    grid-template-columns:1fr;
    gap:12px;
}

body.wide-table-mode .search-box{
    grid-template-columns:1fr 190px;
}

.search-box input,
.search-box select{
    width:100%;
    min-height:48px;
    padding:0 14px;
    border-radius:14px;
    border:1px solid #dfe6f0;
    background:#eef1f7;
    color:#172033;
    box-shadow:
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
    font-size:14px;
    outline:none;
    transition:.18s ease;
}

.search-box input:focus,
.search-box select:focus{
    border-color:#6d4aff;
    box-shadow:
        0 0 0 3px rgba(109,74,255,.12),
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
}


.table-box{
    background:rgba(255,255,255,.62);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:14px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    overflow:hidden;
}

.table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    table-layout:auto;
}

.table-own,
.table-all{
    min-width:0;
}

.table th{
    background:#6d4aff;
    color:#fff;
    padding:12px 8px;
    text-align:center;
    font-size:11.5px;
    line-height:1.45;
    font-weight:900;
    white-space:nowrap;
}

.table th:first-child{
    border-radius:0 14px 14px 0;
}

.table th:last-child{
    border-radius:14px 0 0 14px;
}

.table td{
    padding:11px 7px;
    border-bottom:1px solid #dfe6f0;
    text-align:center;
    vertical-align:middle;
    font-size:12.5px;
    line-height:1.65;
    color:#172033;
    white-space:nowrap;
}

.table tr:last-child td{
    border-bottom:none;
}

.table tr:hover td{
    background:#f6f4ff;
}

.col-batch{width:90px;}
.col-supplier{width:30%;}
.col-creator{width:115px;}
.col-count{width:105px;}
.col-fee{width:120px;}
.col-created{width:110px;}
.col-status{width:115px;}
.col-paid{width:120px;}
.col-deducted{width:125px;}
.col-view{width:75px;}


body.wide-table-mode .col-supplier{width:260px;}
body.wide-table-mode .col-creator{width:125px;}
body.wide-table-mode .col-deducted{width:130px;}
body.wide-table-mode .col-count{width:110px;}
body.wide-table-mode .col-fee{width:125px;}
body.wide-table-mode .col-paid{width:125px;}

.batch-id{
    color:#4f46e5;
    font-weight:900;
}

.supplier-name{
    text-align:right;
    font-weight:900;
    overflow-wrap:anywhere;
    word-break:break-word;
    line-height:1.55;
    white-space:normal !important;
}

.money{
    color:#166534;
    font-weight:900;
    direction:ltr;
}


.status{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:92px;
    min-height:32px;
    padding:5px 9px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
    white-space:nowrap;
}

.status.approved{
    background:#ecfdf3;
    color:#166534;
}

.status.rejected{
    background:#fff1f2;
    color:#b42318;
}

.status.paid{
    background:#ecfdf3;
    color:#166534;
}

.status.unpaid{
    background:#fff1f2;
    color:#b42318;
}

.status.na{
    background:#f1f5f9;
    color:#667085;
}

.user-badge{
    background:#f0edff;
    color:#4f46e5;
    min-width:82px;
    max-width:105px;
    min-height:32px;
    padding:5px 8px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:11px;
    font-weight:900;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}


body.wide-table-mode .table th,
body.wide-table-mode .table td{
    padding-left:6px;
    padding-right:6px;
}

body.wide-table-mode .status{
    min-width:86px;
}

body.wide-table-mode .user-badge{
    min-width:78px;
}



.btn{
    min-height:32px;
    padding:0 10px;
    border:none;
    border-radius:11px;
    background:#6d4aff;
    color:#fff;
    cursor:pointer;
    text-decoration:none;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    transition:.18s ease;
}

.btn:hover{
    transform:translateY(-1px);
    filter:brightness(.98);
}

.bulk-clean-bar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:18px;
    padding:12px 14px;
    border-radius:18px;
    background:#fff7ed;
    border:1px solid #fed7aa;
    color:#9a3412;
    font-weight:900;
    box-shadow:6px 6px 14px #d1d9e6,-6px -6px 14px #fff;
}

.bulk-clean-text{
    font-size:13px;
    line-height:1.8;
}

.bulk-delete-btn{
    min-height:42px;
    padding:0 16px;
    border-radius:13px;
    border:none;
    background:#ef4444;
    color:#fff;
    font-weight:900;
    cursor:pointer;
    white-space:nowrap;
}

.bulk-delete-btn:disabled{
    opacity:.45;
    cursor:not-allowed;
}

.select-col{
    width:48px;
}

.item-batch-check,
#checkAllItemBatches{
    width:18px;
    height:18px;
    min-height:auto;
    box-shadow:none;
    cursor:pointer;
}

.alert{
    padding:13px 15px;
    border-radius:14px;
    margin-bottom:15px;
    font-weight:800;
    line-height:1.8;
    box-shadow:0 10px 24px rgba(23,32,51,.06);
}

.alert-info{
    background:#eef2ff;
    color:#3730a3;
    border:1px solid #c7d2fe;
}

.empty{
    padding:26px !important;
    text-align:center;
    color:#667085;
    font-weight:900;
}

@media(max-width:1180px){
    .summary-grid{
        grid-template-columns:repeat(3, 1fr);
    }
}

@media(max-width:800px){
    .summary-grid{
        grid-template-columns:repeat(2, 1fr);
    }

    .table-box{
        overflow-x:auto;
    }

    .table-own{
        min-width:980px;
    }

    .table-all{
        min-width:1120px;
    }
}

@media(max-width:560px){
    .container{
        width:calc(100% - 18px);
        margin-top:18px;
    }

    .page-title{
        font-size:23px;
    }

    .summary-grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body class="<?= $canViewAllItems ? 'wide-table-mode' : 'normal-table-mode' ?>">

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">📦 أصنافي</h1>
        <p class="page-subtitle">
            متابعة طلبات إضافة الأصناف التي تم اعتمادها أو رفضها، مع حالة خصم الرسوم من المالية.
        </p>
    </div>

    <?php if(!empty($bulkDeleteMsg)): ?>
        <div class="alert alert-info">
            <?= e($bulkDeleteMsg) ?>
        </div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-value"><?= (int)$totalRequests ?></div>
            <div class="summary-label">عدد طلبات الإضافة</div>
        </div>

        <div class="summary-card">
            <div class="summary-value"><?= (int)$totalApprovedItems ?></div>
            <div class="summary-label">أصناف معتمدة</div>
        </div>

        <div class="summary-card">
            <div class="summary-value"><?= money($totalApprovedFees) ?></div>
            <div class="summary-label">رسوم معتمدة</div>
        </div>

        <div class="summary-card">
            <div class="summary-value"><?= (int)$approvedCount ?></div>
            <div class="summary-label">تمت الموافقة</div>
        </div>

        <div class="summary-card">
            <div class="summary-value"><?= (int)$rejectedCount ?></div>
            <div class="summary-label">مرفوض</div>
        </div>
    </div>

    <form class="search-box" method="GET">
        <input type="text"
               id="searchInput"
               name="search"
               placeholder="🔍 بحث باسم المورد أو رقم الطلب أو المستخدم..."
               value="<?= e($search) ?>">

        <?php if($showUserFilter): ?>
            <select name="user" id="userFilter" onchange="applyFilters()">
                <option value="">بواسطة: كل الفريق</option>
                <?php foreach($users_result as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= ((string)$user_filter === (string)$u['id']) ? 'selected' : '' ?>>
                        <?= e($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </form>

    <?php if($is_admin): ?>
        <form method="POST" id="bulkDeleteItemBatchesForm" onsubmit="return prepareAndConfirmBulkDeleteItemBatches();">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
            <input type="hidden" name="action" value="bulk_delete_item_batches">
            <div id="selectedItemBatchInputs"></div>

            <div class="bulk-clean-bar">
                <div class="bulk-clean-text">
                    🧹 تنظيف دفعات الأصناف التجريبية: حدد الدفعات من الجدول ثم اضغط حذف المحدد.
                    <br>
                    <span id="selectedItemBatchesCount">لم يتم تحديد دفعات</span>
                </div>

                <button type="submit" class="bulk-delete-btn" id="bulkDeleteItemBatchesBtn" disabled>
                    حذف المحدد
                </button>
            </div>
        </form>
    <?php endif; ?>

    <div class="table-box">

        <table class="table <?= $canViewAllItems ? 'table-all' : 'table-own' ?>">
            <thead>
                <tr>
                    <?php if($is_admin): ?>
                        <th class="select-col">
                            <input type="checkbox" id="checkAllItemBatches" title="تحديد الكل">
                        </th>
                    <?php endif; ?>
                    <th class="col-batch">رقم الطلب</th>
                    <th class="col-supplier">المورد</th>
                    <?php if($showUserFilter): ?>
                        <th class="col-creator">الموظف</th>
                    <?php endif; ?>
                    <th class="col-count">أصناف معتمدة</th>
                    <th class="col-fee">رسوم معتمدة</th>
                    <th class="col-created">تاريخ الطلب</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-paid">الخصم</th>
                    <th class="col-deducted">تم الخصم بواسطة</th>
                    <th class="col-view">عرض</th>
                </tr>
            </thead>

            <tbody>

                <?php if(!empty($rows)): ?>

                    <?php foreach($rows as $row): ?>
                        <?php
                        $status = (string)($row['status'] ?? '');
                        $createdAt = !empty($row['created_at'])
                            ? date("Y-m-d", strtotime($row['created_at']))
                            : '-';

                        $isApproved = ($status === 'approved');
                        $isPaid = !empty($row['paid']);
                        ?>

                        <tr>
                            <?php if($is_admin): ?>
                                <td>
                                    <input type="checkbox" class="item-batch-check" value="<?= e($row['batch_id']) ?>">
                                </td>
                            <?php endif; ?>

                            <td>
                                <span class="batch-id">#<?= e($row['batch_id']) ?></span>
                            </td>

                            <td class="supplier-name">
                                <?= e($row['supplier_name'] ?? '-') ?>
                            </td>

                            <?php if($showUserFilter): ?>
                                <td>
                                    <span class="user-badge"><?= e($row['creator_username'] ?? '-') ?></span>
                                </td>
                            <?php endif; ?>

                            <td>
                                <?= (int)($row['approved_items_count'] ?? 0) ?>
                            </td>

                            <td>
                                <span class="money"><?= money($row['approved_total_fees'] ?? 0) ?></span>
                            </td>

                            <td>
                                <?= e($createdAt) ?>
                            </td>

                            <td>
                                <span class="status <?= e(statusClass($status)) ?>">
                                    <?= e(statusText($status)) ?>
                                </span>
                            </td>

                            <td>
                                <?php if($isApproved): ?>
                                    <?php if($isPaid): ?>
                                        <span class="status paid">تم الخصم</span>
                                    <?php else: ?>
                                        <span class="status unpaid">لم يتم الخصم</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status na">غير مطلوب</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($isApproved && !empty($row['deducted_username'])): ?>
                                    <span class="user-badge"><?= e($row['deducted_username']) ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td>
                                <a href="view_items.php?batch=<?= urlencode((string)$row['batch_id']) ?>" class="btn">
                                    عرض
                                </a>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="<?= ($canViewAllItems ? 10 : 9) + ($is_admin ? 1 : 0) ?>" class="empty">لا توجد دفعات أصناف مطابقة</td>
                    </tr>

                <?php endif; ?>

            </tbody>
        </table>

    </div>

    <?php vcRenderPagination($page, $totalPages); ?>

</div>

<script>
let timer;
const searchInput = document.getElementById("searchInput");

function applyFilters(){
    let url = new URL(window.location.href);

    const search = document.getElementById("searchInput") ? document.getElementById("searchInput").value : "";
    const user = document.getElementById("userFilter") ? document.getElementById("userFilter").value : "";

    if(search){
        url.searchParams.set("search", search);
    }else{
        url.searchParams.delete("search");
    }

    if(user){
        url.searchParams.set("user", user);
    }else{
        url.searchParams.delete("user");
    }

    url.searchParams.delete("pg");

    window.location.href = url;
}

if(searchInput){
    searchInput.addEventListener("keyup", function(){
        clearTimeout(timer);
        timer = setTimeout(applyFilters, 400);
    });
}

function updateBulkDeleteItemBatchesState(){
    const checks = Array.from(document.querySelectorAll(".item-batch-check"));
    const selected = checks.filter(c => c.checked);
    const countText = document.getElementById("selectedItemBatchesCount");
    const btn = document.getElementById("bulkDeleteItemBatchesBtn");
    const all = document.getElementById("checkAllItemBatches");

    if(countText){
        countText.textContent = selected.length > 0
            ? "تم تحديد " + selected.length + " دفعة"
            : "لم يتم تحديد دفعات";
    }

    if(btn){
        btn.disabled = selected.length === 0;
    }

    if(all){
        all.checked = checks.length > 0 && selected.length === checks.length;
        all.indeterminate = selected.length > 0 && selected.length < checks.length;
    }
}

function prepareAndConfirmBulkDeleteItemBatches(){
    const selected = Array.from(document.querySelectorAll(".item-batch-check")).filter(c => c.checked);
    const holder = document.getElementById("selectedItemBatchInputs");

    if(selected.length === 0){
        alert("حدد دفعة واحدة على الأقل.");
        return false;
    }

    if(holder){
        holder.innerHTML = "";

        selected.forEach(function(c){
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = "batch_ids[]";
            input.value = c.value;
            holder.appendChild(input);
        });
    }

    return confirm(
        "تحذير مهم:\n\n" +
        "سيتم حذف " + selected.length + " دفعة أصناف نهائيًا بكل الأصناف التابعة لها.\n" +
        "هذا مناسب فقط لتنظيف البيانات التجريبية.\n\n" +
        "هل أنت متأكد من الحذف؟"
    );
}

const checkAllItemBatches = document.getElementById("checkAllItemBatches");
if(checkAllItemBatches){
    checkAllItemBatches.addEventListener("change", function(){
        document.querySelectorAll(".item-batch-check").forEach(function(c){
            c.checked = checkAllItemBatches.checked;
        });
        updateBulkDeleteItemBatchesState();
    });
}

document.querySelectorAll(".item-batch-check").forEach(function(c){
    c.addEventListener("change", updateBulkDeleteItemBatchesState);
});

updateBulkDeleteItemBatchesState();
</script>

</body>
</html>
