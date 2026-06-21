<?php
require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';



date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function statusText(string $status): string {
    $map = [
        'draft' => 'تفاوض',
        'review' => 'تحت المراجعة',
        'approved' => 'تمت الموافقة',
        'rejected' => 'مرفوض'
    ];

    return $map[$status] ?? 'غير معروف';
}

function statusClass(string $status): string {
    return in_array($status, ['draft', 'review', 'approved', 'rejected'], true) ? $status : 'draft';
}

function vcColumnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row) && (int)$row['c'] > 0;
}

function signedReceivedBadge(array $row): string {
    $status = (string)($row['status'] ?? '');

    if ($status !== 'approved') {
        return "<span class='signed-pill signed-na'>-</span>";
    }

    $received = (int)($row['supplier_signed_received'] ?? 0) === 1;

    if ($received) {
        return "<span class='signed-pill signed-yes'>وصلت</span>";
    }

    return "<span class='signed-pill signed-no'>لم تصل</span>";
}

function contractTypeText(array $row): string {
    return (($row['source'] ?? '') === 'rent') ? 'عقد إيجار' : 'عقد سنوي';
}

function contractTypeClass(array $row): string {
    return (($row['source'] ?? '') === 'rent') ? 'rent-type' : 'annual-type';
}

function getUserPageScope(mysqli $conn, int $uid, string $pageName): string {
    $scope = 'none';

    $stmt = $conn->prepare("
        SELECT up.scope
        FROM user_permissions up
        JOIN pages p ON p.id = up.page_id
        WHERE up.user_id = ?
        AND p.name = ?
        AND p.status = 1
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("is", $uid, $pageName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($row['scope'])) {
            $scope = $row['scope'];
        }
    }

    return $scope;
}

function actionDateBadge(array $row): string {
    $status = (string)($row['status'] ?? '');

    if ($status === 'approved' && empty($row['approved_at'])) {
        return "<span class='action-pill action-final'>إجراء نهائي</span>";
    }

    if (!empty($row['approved_at'])) {
        return "<span class='action-pill action-approved'>" . e(date("Y-m-d", strtotime($row['approved_at']))) . "</span>";
    }

    if (!empty($row['rejected_at'])) {
        return "<span class='action-pill action-rejected'>" . e(date("Y-m-d", strtotime($row['rejected_at']))) . "</span>";
    }

    if ($status === 'draft') {
        return "<span class='action-pill action-empty'>لم يكتمل</span>";
    }

    return "<span class='action-pill action-empty'>-</span>";
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


$stmtUser = $conn->prepare("SELECT is_admin, role, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1");
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$currentUser = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$is_admin = !empty($currentUser) && (
    (int)($currentUser['is_admin'] ?? 0) === 1 ||
    ($currentUser['role'] ?? '') === 'admin' ||
    ($currentUser['job_role'] ?? '') === 'admin'
);

$myContractsScope = $is_admin ? 'all' : getUserPageScope($conn, $user_id, 'my_contracts');
$scopedUserIds = vcGetScopedUserIds($conn, $user_id, $myContractsScope, $is_admin);
$canViewAllMyContracts = ($is_admin || $myContractsScope === 'all');
$showUserColumn = ($canViewAllMyContracts || $myContractsScope === 'team');

$signedReceivedColumnExists = vcColumnExists($conn, 'contracts', 'supplier_signed_received');


$search        = trim($_GET['search'] ?? '');
$status        = trim($_GET['status'] ?? '');
$contract_type = trim($_GET['contract_type'] ?? '');
$user_filter   = trim($_GET['user'] ?? '');

$allowedStatuses = ['', 'approved', 'rejected'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$allowedContractTypes = ['', 'annual', 'rent'];
if (!in_array($contract_type, $allowedContractTypes, true)) {
    $contract_type = '';
}

if (!$showUserColumn) {
    $user_filter = '';
}

if ($user_filter !== '') {
    $uf = (int)$user_filter;
    if (!$canViewAllMyContracts && !in_array($uf, $scopedUserIds, true)) {
        $user_filter = '';
    }
}

$users_result = null;
if ($showUserColumn) {
    if ($canViewAllMyContracts) {
        $users_result = $conn->query("SELECT id, username FROM users ORDER BY username ASC");
    } elseif (!empty($scopedUserIds)) {
        $phUsers = implode(',', array_fill(0, count($scopedUserIds), '?'));
        $stmtUsers = $conn->prepare("SELECT id, username FROM users WHERE id IN ($phUsers) ORDER BY username ASC");
        $typesUsers = str_repeat('i', count($scopedUserIds));
        $stmtUsers->bind_param($typesUsers, ...$scopedUserIds);
        $stmtUsers->execute();
        $users_result = $stmtUsers->get_result();
    }
}


$signedSelect = $signedReceivedColumnExists ? "contracts.*" : "contracts.*, 0 AS supplier_signed_received";

$sql = "
    SELECT $signedSelect, users.username AS created_username
    FROM contracts
    LEFT JOIN users ON users.id = contracts.created_by
    WHERE contracts.status IN ('approved','rejected')
";

$params = [];
$types  = "";

if (!$canViewAllMyContracts && !empty($scopedUserIds)) {
    $sql .= vcBuildInCondition('contracts.created_by', $scopedUserIds, $params, $types);
}

if ($user_filter !== '') {
    $sql .= " AND contracts.created_by = ?";
    $params[] = (int)$user_filter;
    $types .= "i";
}

if ($contract_type === 'rent') {
    $sql .= " AND contracts.source = 'rent'";
} elseif ($contract_type === 'annual') {
    $sql .= " AND (contracts.source IS NULL OR contracts.source <> 'rent')";
}

if ($status !== '') {
    $sql .= " AND contracts.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($search !== '') {
    $sql .= " AND (
        contracts.supplier_name LIKE ?
        OR contracts.company_name LIKE ?
        OR contracts.supplier_phone LIKE ?
        OR contracts.id LIKE ?
    )";

    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

$sql .= " ORDER BY contracts.id DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();


$signedStatsReceived = $signedReceivedColumnExists
    ? "SUM(CASE WHEN status='approved' AND supplier_signed_received=1 THEN 1 ELSE 0 END)"
    : "0";
$signedStatsNotReceived = $signedReceivedColumnExists
    ? "SUM(CASE WHEN status='approved' AND COALESCE(supplier_signed_received,0)=0 THEN 1 ELSE 0 END)"
    : "SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END)";

$statsSql = "
    SELECT 
        COUNT(*) AS total,
        SUM(status='approved') AS approved,
        SUM(status='rejected') AS rejected,
        $signedStatsReceived AS signed_received,
        $signedStatsNotReceived AS signed_not_received
    FROM contracts
    WHERE status IN ('approved','rejected')
";

$statsParams = [];
$statsTypes  = "";

if (!$canViewAllMyContracts && !empty($scopedUserIds)) {
    $statsSql .= vcBuildInCondition('created_by', $scopedUserIds, $statsParams, $statsTypes);
}

if ($user_filter !== '') {
    $statsSql .= " AND created_by = ?";
    $statsParams[] = (int)$user_filter;
    $statsTypes .= "i";
}

if ($contract_type === 'rent') {
    $statsSql .= " AND source = 'rent'";
} elseif ($contract_type === 'annual') {
    $statsSql .= " AND (source IS NULL OR source <> 'rent')";
}

if ($status !== '') {
    $statsSql .= " AND status = ?";
    $statsParams[] = $status;
    $statsTypes .= "s";
}

if ($search !== '') {
    $statsSql .= " AND (
        supplier_name LIKE ?
        OR company_name LIKE ?
        OR supplier_phone LIKE ?
        OR id LIKE ?
    )";

    $like = "%{$search}%";
    $statsParams[] = $like;
    $statsParams[] = $like;
    $statsParams[] = $like;
    $statsParams[] = $like;
    $statsTypes .= "ssss";
}

$stmtCount = $conn->prepare($statsSql);

if (!empty($statsParams)) {
    $stmtCount->bind_param($statsTypes, ...$statsParams);
}

$stmtCount->execute();
$count = $stmtCount->get_result()->fetch_assoc();
$stmtCount->close();

$totalRows = count($rows);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>عقودي</title>

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
    width:min(1220px, calc(100% - 32px));
    margin:28px auto 45px;
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


.stats{
    display:grid;
    grid-template-columns:repeat(5, 1fr);
    gap:14px;
    margin-bottom:18px;
}

.card{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:20px;
    padding:16px;
    text-align:center;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}

.num{
    font-size:24px;
    font-weight:900;
    color:#4f46e5;
    margin-bottom:6px;
}

.card-label{
    font-size:13px;
    color:#667085;
    font-weight:800;
}


.filters{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:14px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    margin-bottom:18px;
    display:grid;
    grid-template-columns:1fr 170px 170px 170px 120px 110px;
    gap:12px;
    align-items:center;
}

.filter-title{
    grid-column:1 / -1;
    color:#4f46e5;
    font-size:14px;
    font-weight:900;
    padding:0 4px 2px;
    display:flex;
    align-items:center;
    gap:8px;
}

.filters input,
.filters select{
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

.filters input:focus,
.filters select:focus{
    border-color:#6d4aff;
    box-shadow:
        0 0 0 3px rgba(109,74,255,.12),
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
}

.filter-btn,
.reset-btn{
    min-height:48px;
    border-radius:14px;
    border:none;
    cursor:pointer;
    font-weight:900;
    text-decoration:none;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:0 14px;
    white-space:nowrap;
}

.filter-btn{
    background:#6d4aff;
    color:#fff;
    box-shadow:0 8px 18px rgba(109,74,255,.20);
}

.reset-btn{
    background:#eef1f7;
    color:#475569;
    border:1px solid #dfe6f0;
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
    table-layout:fixed;
}

.table th{
    background:#6d4aff;
    color:#fff;
    padding:14px 10px;
    text-align:center;
    font-size:13px;
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
    padding:13px 10px;
    border-bottom:1px solid #dfe6f0;
    text-align:center;
    vertical-align:middle;
    font-size:14px;
    line-height:1.7;
    color:#172033;
}

.table tr:last-child td{
    border-bottom:none;
}

.table tr:hover td{
    background:#f6f4ff;
}

.col-id{width:70px;}
.col-supplier{width:24%;}
.col-user{width:120px;}
.col-type{width:130px;}
.col-created{width:135px;}
.col-action-date{width:145px;}
.col-status{width:135px;}
.col-signed{width:145px;}
.col-view{width:90px;}

.contract-id{
    color:#4f46e5;
    font-weight:900;
}

.supplier-name{
    text-align:right;
    font-weight:900;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.user-badge{
    background:#f0edff;
    color:#4f46e5;
    min-width:96px;
    min-height:34px;
    padding:6px 11px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:900;
}


.type-badge{
    min-width:96px;
    min-height:34px;
    padding:6px 11px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:#fff;
}

.annual-type{
    background:#f59e0b;
}

.rent-type{
    background:#16a34a;
}


.status{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:112px;
    min-height:34px;
    padding:6px 11px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
}

.review{background:#fffbeb;color:#b45309;}
.approved{background:#ecfdf3;color:#166534;}
.rejected{background:#fff1f2;color:#b42318;}
.draft{background:#f1f5f9;color:#475569;}


.action-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:118px;
    min-height:34px;
    padding:6px 11px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
}

.action-approved{background:#ecfdf3;color:#166534;}
.action-rejected{background:#fff1f2;color:#b42318;}
.action-final{background:#ecfdf3;color:#166534;}
.action-empty{background:#f1f5f9;color:#475569;}


.signed-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:104px;
    min-height:34px;
    padding:6px 11px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
}

.signed-yes{background:#ecfdf3;color:#166534;}
.signed-no{background:#fff7ed;color:#c2410c;}
.signed-na{background:#f1f5f9;color:#475569;}


.btn{
    min-height:34px;
    padding:0 12px;
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

.empty{
    padding:26px !important;
    text-align:center;
    color:#667085;
    font-weight:900;
}

@media(max-width:1050px){
    .stats{
        grid-template-columns:repeat(2, 1fr);
    }

    .filters{
        grid-template-columns:1fr;
    }

    .table-box{
        overflow-x:auto;
    }

    .table{
        min-width:1080px;
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

    .stats{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">📄 عقودي</h1>
        <p class="page-subtitle">
            متابعة العقود التي تم اتخاذ إجراء عليها: الموافقة أو الرفض.
        </p>
    </div>

    <div class="stats">
        <div class="card">
            <div class="num"><?= (int)($count['total'] ?? 0) ?></div>
            <div class="card-label">إجمالي المقبول والمرفوض</div>
        </div>

        <div class="card">
            <div class="num"><?= (int)($count['approved'] ?? 0) ?></div>
            <div class="card-label">تمت الموافقة</div>
        </div>

        <div class="card">
            <div class="num"><?= (int)($count['rejected'] ?? 0) ?></div>
            <div class="card-label">مرفوض</div>
        </div>

        <div class="card">
            <div class="num"><?= (int)($count['signed_received'] ?? 0) ?></div>
            <div class="card-label">النسخة الموقعة وصلت</div>
        </div>

        <div class="card">
            <div class="num"><?= (int)($count['signed_not_received'] ?? 0) ?></div>
            <div class="card-label">النسخة الموقعة لم تصل</div>
        </div>
    </div>

    <form class="filters" method="GET">

        <div class="filter-title">
            <span>🔎 فلاتر عقودي</span>
            <span>— نوع العقد وحالة الإجراء والبحث</span>
        </div>

        <input type="text"
               id="searchInput"
               name="search"
               placeholder="🔍 بحث باسم المورد أو المسؤول أو رقم العقد..."
               value="<?= e($search) ?>">

        <select name="contract_type">
            <option value="" <?= $contract_type === '' ? 'selected' : '' ?>>نوع العقد: الكل</option>
            <option value="annual" <?= $contract_type === 'annual' ? 'selected' : '' ?>>نوع العقد: عقود سنوية</option>
            <option value="rent" <?= $contract_type === 'rent' ? 'selected' : '' ?>>نوع العقد: عقود إيجار</option>
        </select>

        <select name="status">
            <option value="" <?= $status === '' ? 'selected' : '' ?>>حالة الإجراء: الكل</option>
            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>حالة الإجراء: موافقة</option>
            <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>حالة الإجراء: مرفوض</option>
        </select>

        <?php if($showUserColumn): ?>
            <select name="user">
                <option value="">بواسطة: الكل</option>
                <?php if($users_result): ?>
                    <?php while($u = $users_result->fetch_assoc()): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= ((string)$user_filter === (string)$u['id']) ? 'selected' : '' ?>>
                            <?= e($u['username']) ?>
                        </option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>
        <?php endif; ?>

        <button type="submit" class="filter-btn">تطبيق</button>
        <a class="reset-btn" href="my_contracts.php">مسح</a>

    </form>

    <div class="table-box">

        <table class="table">
            <thead>
                <tr>
                    <th class="col-id">رقم</th>
                    <th class="col-supplier">المورد</th>
                    <?php if($showUserColumn): ?>
                        <th class="col-user">بواسطة</th>
                    <?php endif; ?>
                    <th class="col-type">نوع العقد</th>
                    <th class="col-created">تاريخ بدء التفاوض</th>
                    <th class="col-action-date">تاريخ الإجراء</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-signed">النسخة الموقعة</th>
                    <th class="col-view">عرض</th>
                </tr>
            </thead>

            <tbody>

                <?php if(!empty($rows)): ?>

                    <?php foreach($rows as $row): ?>

                        <tr>
                            <td>
                                <span class="contract-id">#<?= (int)$row['id'] ?></span>
                            </td>

                            <td class="supplier-name">
                                <?= e($row['supplier_name'] ?? '-') ?>
                            </td>

                            <?php if($showUserColumn): ?>
                                <td>
                                    <span class="user-badge"><?= e($row['created_username'] ?? '-') ?></span>
                                </td>
                            <?php endif; ?>

                            <td>
                                <span class="type-badge <?= e(contractTypeClass($row)) ?>">
                                    <?= e(contractTypeText($row)) ?>
                                </span>
                            </td>

                            <td>
                                <?= !empty($row['created_at']) ? e(date("Y-m-d", strtotime($row['created_at']))) : '-' ?>
                            </td>

                            <td>
                                <?= actionDateBadge($row) ?>
                            </td>

                            <td>
                                <span class="status <?= e(statusClass((string)($row['status'] ?? ''))) ?>">
                                    <?= e(statusText((string)($row['status'] ?? ''))) ?>
                                </span>
                            </td>

                            <td>
                                <?= signedReceivedBadge($row) ?>
                            </td>

                            <td>
                                <a href="view_contract.php?id=<?= (int)$row['id'] ?>" class="btn">
                                    عرض
                                </a>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="<?= $showUserColumn ? 9 : 8 ?>" class="empty">لا يوجد عقود مطابقة</td>
                    </tr>

                <?php endif; ?>

            </tbody>
        </table>

    </div>

</div>

<script>
function applyFilters(){
    const url = new URL(window.location.href);

    const search       = document.getElementById("searchInput").value;
    const contractType = document.querySelector("select[name='contract_type']").value;
    const status       = document.querySelector("select[name='status']").value;
    const userSelect   = document.querySelector("select[name='user']");
    const user         = userSelect ? userSelect.value : "";

    if(search){
        url.searchParams.set("search", search);
    }else{
        url.searchParams.delete("search");
    }

    if(contractType){
        url.searchParams.set("contract_type", contractType);
    }else{
        url.searchParams.delete("contract_type");
    }

    if(status){
        url.searchParams.set("status", status);
    }else{
        url.searchParams.delete("status");
    }

    if(user){
        url.searchParams.set("user", user);
    }else{
        url.searchParams.delete("user");
    }

    window.location.href = url;
}

let timer;
const searchInput = document.getElementById("searchInput");

if(searchInput){
    searchInput.addEventListener("keyup", function(){
        clearTimeout(timer);
        timer = setTimeout(applyFilters, 450);
    });
}

document.querySelector("select[name='contract_type']").addEventListener("change", applyFilters);
document.querySelector("select[name='status']").addEventListener("change", applyFilters);

const userFilterSelect = document.querySelector("select[name='user']");
if(userFilterSelect){
    userFilterSelect.addEventListener("change", applyFilters);
}
</script>

</body>
</html>
