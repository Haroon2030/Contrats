<?php
require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';



date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function contractTypeText(array $row): string {
    return (($row['source'] ?? '') === 'rent') ? 'عقد إيجار' : 'عقد سنوي';
}

function contractTypeClass(array $row): string {
    return (($row['source'] ?? '') === 'rent') ? 'rent-type' : 'annual-type';
}

function getUserPageScope(VcDb $conn, int $uid, string $pageName): string {
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

$underReviewScope = getUserPageScope($conn, $user_id, 'under_review');
$canViewAllUnderReview = ($is_admin || $underReviewScope === 'all');
$scopedUserIds = vcGetScopedUserIds($conn, $user_id, $underReviewScope, $is_admin);
$hasLimitedScope = (!$is_admin && $underReviewScope !== 'all');
$showUserColumn = ($canViewAllUnderReview || $underReviewScope === 'team');


$search = trim($_GET['search'] ?? '');
$user_filter = trim($_GET['user'] ?? '');
$contract_type = trim($_GET['contract_type'] ?? '');

$allowedContractTypes = ['', 'annual', 'rent'];
if (!in_array($contract_type, $allowedContractTypes, true)) {
    $contract_type = '';
}

if (!$showUserColumn) {
    $user_filter = '';
}

if ($user_filter !== '' && !vcIsUserInScope((int)$user_filter, $scopedUserIds)) {
    $user_filter = '';
}

$users_for_filter = [];
if ($showUserColumn) {
    $users_for_filter = vcGetVisibleUsersForFilter($conn, $scopedUserIds);
}


$sql = "
    SELECT contracts.*, users.username AS created_username
    FROM contracts
    LEFT JOIN users ON users.id = contracts.created_by
    WHERE contracts.status = 'review'
";

$params = [];
$types  = "";

if ($hasLimitedScope) {
    if (empty($scopedUserIds)) {
        $sql .= " AND 1 = 0";
    } else {
        $sql .= vcBuildInCondition('contracts.created_by', $scopedUserIds, $params, $types, 'AND');
    }
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

if ($search !== '') {
    $sql .= " AND (
        contracts.supplier_name LIKE ?
        OR contracts.company_name LIKE ?
        OR contracts.supplier_phone LIKE ?
        OR contracts.id LIKE ?
        OR users.username LIKE ?
    )";

    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sssss";
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


$totalReview = count($rows);

$totalSql = "
    SELECT COUNT(*) AS total_all
    FROM contracts
    WHERE 1
";

$totalParams = [];
$totalTypes = "";

if ($hasLimitedScope) {
    if (empty($scopedUserIds)) {
        $totalSql .= " AND 1 = 0";
    } else {
        $totalSql .= vcBuildInCondition('created_by', $scopedUserIds, $totalParams, $totalTypes, 'AND');
    }
}

if ($user_filter !== '') {
    $totalSql .= " AND created_by = ?";
    $totalParams[] = (int)$user_filter;
    $totalTypes .= "i";
}

if ($contract_type === 'rent') {
    $totalSql .= " AND source = 'rent'";
} elseif ($contract_type === 'annual') {
    $totalSql .= " AND (source IS NULL OR source <> 'rent')";
}

$stmtCount = $conn->prepare($totalSql);

if (!empty($totalParams)) {
    $stmtCount->bind_param($totalTypes, ...$totalParams);
}

$stmtCount->execute();
$totalAll = (int)($stmtCount->get_result()->fetch_assoc()['total_all'] ?? 0);
$stmtCount->close();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>تحت المراجعة</title>

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
    width:min(1450px, calc(100% - 14px));
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
    grid-template-columns:repeat(2, 1fr);
    gap:14px;
    margin-bottom:18px;
}

.summary-card{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:20px;
    padding:16px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    text-align:center;
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
    grid-template-columns:1fr 180px 180px;
    gap:12px;
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
    white-space:nowrap;
}


body.wide-table-mode .table th,
body.wide-table-mode .table td{
    padding-left:8px;
    padding-right:8px;
}

body.wide-table-mode .user-badge,
body.wide-table-mode .type-badge,
body.wide-table-mode .status,
body.wide-table-mode .action-pill{
    min-width:92px;
}


.table tr:last-child td{
    border-bottom:none;
}

.table tr:hover td{
    background:#f6f4ff;
}

.col-id{width:85px;}
.col-supplier{width:30%;}
.col-user{width:125px;}
.col-type{width:135px;}
.col-created{width:135px;}
.col-action-date{width:135px;}
.col-status{width:140px;}
.col-view{width:85px;}


body.wide-table-mode .col-supplier{width:300px;}
body.wide-table-mode .col-user{width:140px;}
body.wide-table-mode .col-type{width:150px;}
body.wide-table-mode .col-created{width:150px;}
body.wide-table-mode .col-action-date{width:145px;}
body.wide-table-mode .col-status{width:150px;}

.contract-id{
    color:#4f46e5;
    font-weight:900;
}

.supplier-name{
    text-align:right;
    font-weight:900;
    overflow-wrap:anywhere;
    word-break:break-word;
    white-space:normal !important;
    line-height:1.8;
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
    background:#fffbeb;
    color:#b45309;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:120px;
    min-height:34px;
    padding:6px 11px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
}


.action-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:105px;
    min-height:34px;
    padding:6px 11px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    background:#f1f5f9;
    color:#475569;
}


.user-badge,
.type-badge,
.status,
.action-pill{
    max-width:100%;
    white-space:nowrap;
}




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
    .summary-grid{
        grid-template-columns:1fr;
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
}
</style>
</head>

<body class="<?= $showUserColumn ? 'wide-table-mode' : 'normal-table-mode' ?>">

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">⏳ العقود تحت المراجعة</h1>
        <p class="page-subtitle">
            العقود التي تم إرسالها للإدارة ولم يتم اتخاذ قرار عليها بعد، حسب نطاق صلاحيتك.
        </p>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-value"><?= (int)$totalReview ?></div>
            <div class="summary-label">تحت المراجعة الآن</div>
        </div>

        <div class="summary-card">
            <div class="summary-value"><?= (int)$totalAll ?></div>
            <div class="summary-label">إجمالي عقود النطاق</div>
        </div>
    </div>

    <form class="search-box" method="GET">
        <input type="text"
               id="searchInput"
               name="search"
               placeholder="🔍 بحث باسم المورد أو المسؤول أو رقم العقد أو المستخدم..."
               value="<?= e($search) ?>">

        <select name="contract_type" id="contractTypeFilter" onchange="applyFilters()">
            <option value="" <?= $contract_type === '' ? 'selected' : '' ?>>نوع العقد: الكل</option>
            <option value="annual" <?= $contract_type === 'annual' ? 'selected' : '' ?>>عقد سنوي</option>
            <option value="rent" <?= $contract_type === 'rent' ? 'selected' : '' ?>>عقد إيجار</option>
        </select>

        <?php if($showUserColumn): ?>
            <select name="user" id="userFilter" onchange="applyFilters()">
                <option value="">بواسطة: الكل</option>
                <?php foreach($users_for_filter as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= ((string)$user_filter === (string)$u['id']) ? 'selected' : '' ?>>
                        <?= e($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </form>

    <div class="table-box">

        <table class="table">
            <thead>
                <tr>
                    <th class="col-id">رقم العقد</th>
                    <th class="col-supplier">اسم المورد</th>
                    <?php if($showUserColumn): ?>
                        <th class="col-user">بواسطة</th>
                    <?php endif; ?>
                    <th class="col-type">نوع العقد</th>
                    <th class="col-created">تاريخ بدء التفاوض</th>
                    <th class="col-action-date">تاريخ الإجراء</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-view">إجراء</th>
                </tr>
            </thead>

            <tbody>

                <?php if(!empty($rows)): ?>

                    <?php foreach($rows as $row): ?>
                        <?php
                        $createdAt = !empty($row['created_at'])
                            ? date("Y-m-d", strtotime($row['created_at']))
                            : '-';

                        $actionDate = '-';

                        if (!empty($row['approved_at'])) {
                            $actionDate = date("Y-m-d", strtotime($row['approved_at']));
                        } elseif (!empty($row['rejected_at'])) {
                            $actionDate = date("Y-m-d", strtotime($row['rejected_at']));
                        }
                        ?>

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
                                <?= e($createdAt) ?>
                            </td>

                            <td>
                                <span class="action-pill"><?= e($actionDate) ?></span>
                            </td>

                            <td>
                                <span class="status">تحت المراجعة</span>
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
                        <td colspan="<?= $showUserColumn ? 8 : 7 ?>" class="empty">لا يوجد عقود تحت المراجعة</td>
                    </tr>

                <?php endif; ?>

            </tbody>
        </table>

    </div>

</div>

<script>
let timer;
const searchInput = document.getElementById("searchInput");

function applyFilters(){
    let url = new URL(window.location.href);

    const search = document.getElementById("searchInput") ? document.getElementById("searchInput").value : "";
    const contractType = document.getElementById("contractTypeFilter") ? document.getElementById("contractTypeFilter").value : "";
    const user = document.getElementById("userFilter") ? document.getElementById("userFilter").value : "";

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

    if(user){
        url.searchParams.set("user", user);
    }else{
        url.searchParams.delete("user");
    }

    window.location.href = url;
}

if(searchInput){
    searchInput.addEventListener("keyup", function(){
        clearTimeout(timer);
        timer = setTimeout(applyFilters, 400);
    });
}
</script>

</body>
</html>
