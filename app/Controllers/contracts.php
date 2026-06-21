<?php
require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';



date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function buildQuery(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);

    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }

    return '?' . http_build_query($params);
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

function contractTypeText(array $row): string {
    return (($row['source'] ?? '') === 'rent') ? 'عقد إيجار' : 'عقد سنوي';
}

function contractTypeClass(array $row): string {
    return (($row['source'] ?? '') === 'rent') ? 'rent-type' : 'annual-type';
}

function highlightText($text, $search): string {
    $safe = e($text);

    if (!$search) {
        return $safe;
    }

    $pattern = '/' . preg_quote($search, '/') . '/iu';
    return preg_replace($pattern, '<mark>$0</mark>', $safe);
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

function vcDisabledHookSetup(mysqli $conn): void {
    return;
}

function vcDisabledUserHook(mysqli $conn, int $userId, string $title, string $message, string $link = '', string $type = 'contract', int $relatedId = 0): void {
    return;
}

function vcDisabledAdminsHook(mysqli $conn, string $title, string $message, string $link = '', string $type = 'contract', int $relatedId = 0, int $excludeUserId = 0): void {
    return;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$uid = (int)($_SESSION['user_id'] ?? 0);

if ($uid <= 0) {
    header("Location: login.php");
    exit();
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


$stmt = $conn->prepare("SELECT is_admin, username FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

$is_admin = !empty($currentUser) && (int)($currentUser['is_admin'] ?? 0) === 1;


$contractsScope = getUserPageScope($conn, $uid, 'contracts');
$canAccessContractsPage = ($is_admin || $contractsScope !== 'none');

if (!$canAccessContractsPage) {
    http_response_code(403);
    die("❌ ليس لديك صلاحية الدخول إلى سجل العقود");
}

$scopedUserIds = vcGetScopedUserIds($conn, $uid, $contractsScope, $is_admin);
$hasLimitedScope = (!$is_admin && $contractsScope !== 'all');


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete_contracts') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }
    if (!$is_admin) {
        http_response_code(403);
        die("❌ ليس لديك صلاحية حذف العقود");
    }
    $ids = $_POST['contract_ids'] ?? [];
    if (!is_array($ids)) { $ids = []; }
    $cleanIds = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!empty($cleanIds)) {
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $bindTypes = str_repeat('i', count($cleanIds));
        $conn->begin_transaction();
        try {
            foreach (['rents'=>'contract_id','annual_discounts'=>'contract_id','events'=>'contract_id','contract_history'=>'contract_id'] as $table=>$column) {
                if (vcColumnExists($conn, $table, $column)) {
                    $stmtDel = $conn->prepare("DELETE FROM {$table} WHERE {$column} IN ({$placeholders})");
                    if ($stmtDel) { $stmtDel->bind_param($bindTypes, ...$cleanIds); $stmtDel->execute(); $stmtDel->close(); }
                }
            }
            $stmtMain = $conn->prepare("DELETE FROM contracts WHERE id IN ({$placeholders})");
            if ($stmtMain) { $stmtMain->bind_param($bindTypes, ...$cleanIds); $stmtMain->execute(); $stmtMain->close(); }
            $conn->commit();
            $_SESSION['contracts_bulk_delete_msg'] = "تم حذف العقود المحددة.";
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['contracts_bulk_delete_msg'] = "تعذر حذف العقود.";
        }
    } else {
        $_SESSION['contracts_bulk_delete_msg'] = "لم يتم تحديد أي عقود للحذف.";
    }
    header("Location: contracts.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['contract_id'])) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    $action = $_POST['action'];
    $contract_id = (int)$_POST['contract_id'];

    if ($contract_id > 0) {

        if ($action === 'back_draft') {

            $ownerBeforeDraft = 0;
            $supplierBeforeDraft = '';
            $stmtOwnerDraft = $conn->prepare("SELECT created_by, supplier_name FROM contracts WHERE id = ? LIMIT 1");
            if ($stmtOwnerDraft) {
                $stmtOwnerDraft->bind_param("i", $contract_id);
                $stmtOwnerDraft->execute();
                $ownerDraftRow = $stmtOwnerDraft->get_result()->fetch_assoc();
                $stmtOwnerDraft->close();

                $ownerBeforeDraft = (int)($ownerDraftRow['created_by'] ?? 0);
                $supplierBeforeDraft = (string)($ownerDraftRow['supplier_name'] ?? '');
            }

            if ($is_admin) {
                $stmt = $conn->prepare("
                    UPDATE contracts 
                    SET status = 'draft'
                    WHERE id = ? AND status = 'review'
                    LIMIT 1
                ");
                $stmt->bind_param("i", $contract_id);
            } else {
                $stmt = $conn->prepare("
                    UPDATE contracts 
                    SET status = 'draft'
                    WHERE id = ? AND created_by = ? AND status = 'review'
                    LIMIT 1
                ");
                $stmt->bind_param("ii", $contract_id, $uid);
            }

            $stmt->execute();
            $affectedDraft = $stmt->affected_rows;
            $stmt->close();

            if ($affectedDraft > 0 && $is_admin && $ownerBeforeDraft > 0 && $ownerBeforeDraft !== $uid) {
                vcDisabledUserHook(
                    $conn,
                    $ownerBeforeDraft,
                    'تم إرجاع العقد للتفاوض',
                    'تم إرجاع العقد رقم #' . (int)$contract_id . ' للمورد: ' . $supplierBeforeDraft . ' إلى التفاوض بواسطة الإدارة.',
                    'view_contract.php?id=' . (int)$contract_id,
                    'contract_back_draft',
                    (int)$contract_id
                );
            }
        }

        if ($action === 'delete_rejected' && $is_admin) {
            $stmt = $conn->prepare("
                DELETE FROM contracts 
                WHERE id = ? AND status = 'rejected'
                LIMIT 1
            ");
            $stmt->bind_param("i", $contract_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    header("Location: contracts.php");
    exit();
}


$employee      = trim($_GET['employee'] ?? '');
$status        = trim($_GET['status'] ?? '');
$contract_type = trim($_GET['contract_type'] ?? '');
$search        = trim($_GET['search'] ?? '');

$allowedStatuses = ['', 'draft', 'review', 'approved', 'rejected'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$allowedContractTypes = ['', 'annual', 'rent'];
if (!in_array($contract_type, $allowedContractTypes, true)) {
    $contract_type = '';
}

$employee_name = "الجميع";

if ($employee !== '') {
    $emp_id = (int)$employee;
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $emp_id);
    $stmt->execute();
    $emp = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($emp) {
        $employee_name = $emp['username'];
    }
}


$limit = 50;
$page  = max((int)($_GET['page'] ?? 1), 1);
$offset = ($page - 1) * $limit;


$where = " WHERE 1 ";
$params = [];
$types = "";

if ($hasLimitedScope) {
    if (empty($scopedUserIds)) {
        $where .= " AND 1 = 0";
    } else {
        $placeholdersScope = implode(',', array_fill(0, count($scopedUserIds), '?'));
        $where .= " AND contracts.created_by IN ($placeholdersScope)";
        foreach ($scopedUserIds as $sid) {
            $params[] = (int)$sid;
            $types .= "i";
        }
    }
}

if ($employee !== '') {
    $where .= " AND contracts.created_by = ?";
    $params[] = (int)$employee;
    $types .= "i";
}

if ($status !== '') {
    $where .= " AND contracts.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($contract_type === 'rent') {
    $where .= " AND contracts.source = 'rent'";
} elseif ($contract_type === 'annual') {
    $where .= " AND (contracts.source IS NULL OR contracts.source <> 'rent')";
}

if ($search !== '') {
    $where .= " AND (
        contracts.supplier_name LIKE ?
        OR users.username LIKE ?
        OR contracts.supplier_phone LIKE ?
        OR contracts.company_name LIKE ?
    )";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

$baseSql = "
    FROM contracts
    LEFT JOIN users ON users.id = contracts.created_by
    {$where}
";


$countSql = "SELECT COUNT(*) AS c " . $baseSql;
$stmtCount = $conn->prepare($countSql);

if (!empty($params)) {
    $stmtCount->bind_param($types, ...$params);
}

$stmtCount->execute();
$totalRows = (int)$stmtCount->get_result()->fetch_assoc()['c'];
$stmtCount->close();

$totalPages = max((int)ceil($totalRows / $limit), 1);


$dataSql = "
    SELECT contracts.*, users.username
    {$baseSql}
    ORDER BY contracts.id DESC
    LIMIT ? OFFSET ?
";

$dataParams = $params;
$dataTypes = $types . "ii";
$dataParams[] = $limit;
$dataParams[] = $offset;

$stmtData = $conn->prepare($dataSql);
$stmtData->bind_param($dataTypes, ...$dataParams);
$stmtData->execute();
$result = $stmtData->get_result();


$statsSql = "
    SELECT 
        COUNT(*) AS total,
        SUM(status='review') AS review,
        SUM(status='approved') AS approved,
        SUM(status='rejected') AS rejected,
        SUM(status='draft') AS draft
    FROM contracts
    WHERE 1
";

$statsParams = [];
$statsTypes = "";

if ($hasLimitedScope) {
    if (empty($scopedUserIds)) {
        $statsSql .= " AND 1 = 0";
    } else {
        $placeholdersScopeStats = implode(',', array_fill(0, count($scopedUserIds), '?'));
        $statsSql .= " AND created_by IN ($placeholdersScopeStats)";
        foreach ($scopedUserIds as $sid) {
            $statsParams[] = (int)$sid;
            $statsTypes .= "i";
        }
    }
}

if ($employee !== '') {
    $statsSql .= " AND created_by = ?";
    $statsParams[] = (int)$employee;
    $statsTypes .= "i";
}

if ($status !== '') {
    $statsSql .= " AND status = ?";
    $statsParams[] = $status;
    $statsTypes .= "s";
}

if ($contract_type === 'rent') {
    $statsSql .= " AND source = 'rent'";
} elseif ($contract_type === 'annual') {
    $statsSql .= " AND (source IS NULL OR source <> 'rent')";
}

if ($search !== '') {
    $statsSql .= " AND (
        supplier_name LIKE ?
        OR supplier_phone LIKE ?
        OR company_name LIKE ?
    )";
    $like = "%{$search}%";
    $statsParams[] = $like;
    $statsParams[] = $like;
    $statsParams[] = $like;
    $statsTypes .= "sss";
}

$stmtStats = $conn->prepare($statsSql);

if (!empty($statsParams)) {
    $stmtStats->bind_param($statsTypes, ...$statsParams);
}

$stmtStats->execute();
$count = $stmtStats->get_result()->fetch_assoc();
$stmtStats->close();


if ($hasLimitedScope && !empty($scopedUserIds)) {
    $usersPlaceholders = implode(',', array_fill(0, count($scopedUserIds), '?'));
    $stmtUsers = $conn->prepare("SELECT id, username FROM users WHERE id IN ($usersPlaceholders) ORDER BY username ASC");
    $userTypes = str_repeat('i', count($scopedUserIds));
    $stmtUsers->bind_param($userTypes, ...$scopedUserIds);
    $stmtUsers->execute();
    $users = $stmtUsers->get_result();
} elseif ($hasLimitedScope) {
    $users = $conn->query("SELECT id, username FROM users WHERE 1 = 0");
} else {
    $users = $conn->query("SELECT id, username FROM users ORDER BY username ASC");
}

$bulkDeleteMsg = $_SESSION['contracts_bulk_delete_msg'] ?? '';
unset($_SESSION['contracts_bulk_delete_msg']);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>العقود</title>

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
    width:min(1320px, calc(100% - 32px));
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

.stat-card{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:20px;
    padding:16px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    text-align:center;
}

.stat-label{
    font-size:13px;
    color:#667085;
    font-weight:800;
    margin-top:6px;
}

.num{
    font-size:24px;
    font-weight:900;
    color:#4f46e5;
}


.filters{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:14px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    display:grid;
    grid-template-columns:1fr 165px 165px 165px 165px 150px;
    gap:12px;
    margin-bottom:18px;
    align-items:center;
}

input,
select{
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

input:focus,
select:focus{
    border-color:#6d4aff;
    box-shadow:
        0 0 0 3px rgba(109,74,255,.12),
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
}

.employee-pill{
    min-height:48px;
    border-radius:14px;
    background:#f0edff;
    color:#4f46e5;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
    border:1px solid rgba(109,74,255,.16);
}

.filter-title{
    grid-column:1 / -1;
    font-size:14px;
    font-weight:900;
    color:#4f46e5;
    display:flex;
    align-items:center;
    gap:8px;
    padding:0 4px 2px;
}

.filter-actions{
    display:flex;
    gap:8px;
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
    padding:0 15px;
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
    overflow-x:auto;
    overflow-y:hidden;
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
.col-supplier{width:42%;}
.col-user{width:120px;}

.type-badge{
    min-width:88px;
    min-height:34px;
    padding:6px 10px;
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

.col-type{width:120px;}

.col-status{width:120px;}
.col-date{width:105px;}
.col-view{width:80px;}

.contract-id{
    color:#4f46e5;
    font-weight:900;
}

.supplier-name{
    text-align:right;
    font-weight:900;
    white-space:normal;
    overflow-wrap:normal;
    word-break:normal;
    line-break:auto;
}

.user-badge{
    background:#f0edff;
    color:#4f46e5;
    min-width:84px;
    min-height:34px;
    padding:6px 11px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:900;
}


.status{
    min-width:100px;
    min-height:34px;
    padding:6px 11px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.approved{
    background:#ecfdf3;
    color:#166534;
}

.rejected{
    background:#fff1f2;
    color:#b42318;
}

.review{
    background:#fffbeb;
    color:#b45309;
}

.draft{
    background:#f1f5f9;
    color:#475569;
}


.actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    flex-wrap:wrap;
}

.actions form{
    margin:0;
    padding:0;
    display:inline-flex;
}

.btn{
    min-height:34px;
    padding:0 11px;
    border-radius:11px;
    border:none;
    cursor:pointer;
    text-decoration:none;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    transition:.18s ease;
    color:#fff;
    white-space:nowrap;
}

.btn:hover{
    transform:translateY(-1px);
    filter:brightness(.98);
}

.btn-edit{
    background:#0ea5e9;
}

.btn-back{
    background:#f59e0b;
}

.btn-pdf{
    background:#6d4aff;
}

.btn-delete{
    background:#ef4444;
}

.btn-view{
    background:#64748b;
}

.empty{
    padding:26px !important;
    text-align:center;
    color:#667085;
    font-weight:900;
}

mark{
    background:#fef3c7;
    color:#b45309;
    border-radius:6px;
    padding:0 3px;
    font-weight:900;
}


.pagination{
    display:flex;
    justify-content:center;
    gap:8px;
    margin-top:18px;
    flex-wrap:wrap;
}

.page-link{
    min-width:38px;
    height:38px;
    border-radius:12px;
    background:#eef1f7;
    color:#172033;
    text-decoration:none;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
    box-shadow:6px 6px 12px #d1d9e6,-6px -6px 12px #fff;
}

.page-link.active{
    background:#6d4aff;
    color:#fff;
}



.bulk-clean-bar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:12px;
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
    width:54px;
}
.contract-check,
#checkAllContracts{
    width:18px;
    height:18px;
    min-height:auto;
    box-shadow:none;
    cursor:pointer;
}
.alert-info{
    background:#eef2ff;
    color:#3730a3;
    border:1px solid #c7d2fe;
}

@media(max-width:1100px){
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
        min-width:920px;
    }

    .filter-actions{
        justify-content:stretch;
    }

    .filter-btn,
    .reset-btn{
        flex:1;
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

    <?php if(!empty($bulkDeleteMsg)): ?>
        <div class="alert alert-info">
            <?= e($bulkDeleteMsg) ?>
        </div>
    <?php endif; ?>

    <div class="page-head">
        <h1 class="page-title">📄 العقود</h1>
        <p class="page-subtitle">
            متابعة كل العقود، البحث، الفلترة، والطباعة من مكان واحد.
        </p>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="num"><?= (int)($count['total'] ?? 0) ?></div>
            <div class="stat-label">كل العقود</div>
        </div>

        <div class="stat-card">
            <div class="num"><?= (int)($count['review'] ?? 0) ?></div>
            <div class="stat-label">تحت المراجعة</div>
        </div>

        <div class="stat-card">
            <div class="num"><?= (int)($count['approved'] ?? 0) ?></div>
            <div class="stat-label">تمت الموافقة</div>
        </div>

        <div class="stat-card">
            <div class="num"><?= (int)($count['rejected'] ?? 0) ?></div>
            <div class="stat-label">مرفوض</div>
        </div>

        <div class="stat-card">
            <div class="num"><?= (int)($count['draft'] ?? 0) ?></div>
            <div class="stat-label">تفاوض</div>
        </div>
    </div>

    <form class="filters" method="GET">

        <div class="filter-title">
            <span>🔎 فلاتر العقود</span>
            <span>— النوع والحالة والموظف والبحث</span>
        </div>

        <input id="searchBox" name="search" placeholder="🔍 بحث بالمورد أو الموظف أو الجوال..." value="<?= e($search) ?>">

        <select name="contract_type">
            <option value="" <?= $contract_type === '' ? 'selected' : '' ?>>نوع العقد: الكل</option>
            <option value="annual" <?= $contract_type === 'annual' ? 'selected' : '' ?>>نوع العقد: عقود سنوية</option>
            <option value="rent" <?= $contract_type === 'rent' ? 'selected' : '' ?>>نوع العقد: عقود إيجار</option>
        </select>

        <select name="status">
            <option value="" <?= $status === '' ? 'selected' : '' ?>>الحالة: الكل</option>
            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>الحالة: موافقة</option>
            <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>الحالة: مرفوض</option>
            <option value="review" <?= $status === 'review' ? 'selected' : '' ?>>الحالة: تحت المراجعة</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>الحالة: تفاوض</option>
        </select>

        <select name="employee">
            <option value="" <?= $employee === '' ? 'selected' : '' ?>>كل الموظفين</option>

            <?php while($u = $users->fetch_assoc()): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (string)$employee === (string)$u['id'] ? 'selected' : '' ?>>
                    <?= e($u['username']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <div class="employee-pill">
            <?= e($employee_name) ?>
        </div>

        <div class="filter-actions">
            <button type="submit" class="filter-btn">تطبيق</button>
            <a class="reset-btn" href="contracts.php">مسح</a>
        </div>
    </form>

    <?php if($is_admin): ?>
        
        <form method="POST" id="bulkDeleteForm" onsubmit="return confirmBulkDeleteContracts();">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
            <input type="hidden" name="action" value="bulk_delete_contracts">
        </form>

        <div class="bulk-clean-bar">
            <div class="bulk-clean-text">
                🧹 تنظيف العقود التجريبية: حدد العقود المطلوبة ثم اضغط حذف المحدد.
                <br>
                <span id="selectedContractsCount">لم يتم تحديد عقود</span>
            </div>

            <button type="submit" form="bulkDeleteForm" class="bulk-delete-btn" id="bulkDeleteBtn" disabled>
                حذف المحدد
            </button>
        </div>
    <?php endif; ?>

    <div class="table-box">

        <table class="table">
            <thead>
                <tr>
                    <?php if($is_admin): ?>
                        <th class="select-col">
                            <input type="checkbox" id="checkAllContracts" title="تحديد الكل">
                        </th>
                    <?php endif; ?>
                    <th class="col-id">ID</th>
                    <th class="col-supplier">المورد</th>
                    <th class="col-type">نوع العقد</th>
                    <th class="col-user">الموظف</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-date">التاريخ</th>
                    <th class="col-view">عرض</th>
                </tr>
            </thead>

            <tbody>
                <?php if($result && $result->num_rows > 0): ?>

                    <?php while($row = $result->fetch_assoc()): ?>
                        <?php
                        $rowStatus = (string)($row['status'] ?? 'draft');

                        if (!empty($row['approved_at'])) {
                            $dateValue = date("Y-m-d", strtotime($row['approved_at']));
                        } elseif (!empty($row['rejected_at'])) {
                            $dateValue = date("Y-m-d", strtotime($row['rejected_at']));
                        } elseif (!empty($row['created_at'])) {
                            $dateValue = date("Y-m-d", strtotime($row['created_at']));
                        } else {
                            $dateValue = "-";
                        }
                        ?>

                        <tr>
                            <?php if($is_admin): ?>
                                <td>
                                    <input type="checkbox" class="contract-check" form="bulkDeleteForm" name="contract_ids[]" value="<?= (int)$row['id'] ?>">
                                </td>
                            <?php endif; ?>

                            <td>
                                <span class="contract-id">#<?= (int)$row['id'] ?></span>
                            </td>

                            <td class="supplier-name">
                                <?= highlightText($row['supplier_name'] ?? '-', $search) ?>
                            </td>

                            <td>
                                <span class="type-badge <?= e(contractTypeClass($row)) ?>">
                                    <?= e(contractTypeText($row)) ?>
                                </span>
                            </td>

                            <td>
                                <span class="user-badge">
                                    <?= e($row['username'] ?? 'غير معروف') ?>
                                </span>
                            </td>

                            <td>
                                <span class="status <?= e(statusClass($rowStatus)) ?>">
                                    <?= e(statusText($rowStatus)) ?>
                                </span>
                            </td>

                            <td><?= e($dateValue) ?></td>

                            <td>
                                <a class="btn btn-view" href="view_contract.php?id=<?= (int)$row['id'] ?>">
                                    عرض
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="<?= $is_admin ? 8 : 7 ?>" class="empty">لا توجد عقود مطابقة للفلاتر الحالية</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    </div>

    <?php if($totalPages > 1): ?>
        <div class="pagination">
            <?php for($i = 1; $i <= $totalPages; $i++): ?>
                <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="<?= e(buildQuery(['page' => $i])) ?>">
                    <?= (int)$i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

</div>

<script>
function applyFilters(){
    let url = new URL(window.location.href);

    let search       = document.getElementById("searchBox").value;
    let contractType = document.querySelector("select[name='contract_type']").value;
    let status       = document.querySelector("select[name='status']").value;
    let employee     = document.querySelector("select[name='employee']").value;

    url.searchParams.delete("page");

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

    if(employee){
        url.searchParams.set("employee", employee);
    }else{
        url.searchParams.delete("employee");
    }

    window.location.href = url;
}

let timer;
const searchBox = document.getElementById("searchBox");

if(searchBox){
    searchBox.addEventListener("keyup", function(){
        clearTimeout(timer);
        timer = setTimeout(applyFilters, 450);
    });
}

document.querySelector("select[name='contract_type']").addEventListener("change", applyFilters);
document.querySelector("select[name='status']").addEventListener("change", applyFilters);
document.querySelector("select[name='employee']").addEventListener("change", applyFilters);


function updateBulkDeleteState(){
    const checks = Array.from(document.querySelectorAll(".contract-check"));
    const selected = checks.filter(c => c.checked);
    const countText = document.getElementById("selectedContractsCount");
    const btn = document.getElementById("bulkDeleteBtn");
    const all = document.getElementById("checkAllContracts");

    if(countText){
        countText.textContent = selected.length > 0
            ? "تم تحديد " + selected.length + " عقد"
            : "لم يتم تحديد عقود";
    }

    if(btn){
        btn.disabled = selected.length === 0;
    }

    if(all){
        all.checked = checks.length > 0 && selected.length === checks.length;
        all.indeterminate = selected.length > 0 && selected.length < checks.length;
    }
}

function confirmBulkDeleteContracts(){
    const selected = Array.from(document.querySelectorAll(".contract-check")).filter(c => c.checked);

    if(selected.length === 0){
        alert("حدد عقد واحد على الأقل.");
        return false;
    }

    return confirm(
        "تحذير مهم:\\n\\n" +
        "سيتم حذف " + selected.length + " عقد نهائيًا مع بياناته التابعة.\\n" +
        "هذا مناسب فقط لتنظيف العقود التجريبية.\\n\\n" +
        "هل أنت متأكد من الحذف؟"
    );
}

const checkAllContracts = document.getElementById("checkAllContracts");
if(checkAllContracts){
    checkAllContracts.addEventListener("change", function(){
        document.querySelectorAll(".contract-check").forEach(function(c){
            c.checked = checkAllContracts.checked;
        });
        updateBulkDeleteState();
    });
}

document.querySelectorAll(".contract-check").forEach(function(c){
    c.addEventListener("change", updateBulkDeleteState);
});

updateBulkDeleteState();

</script>

</body>
</html>
