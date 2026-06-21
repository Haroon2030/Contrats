<?php
require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';



date_default_timezone_set('Asia/Riyadh');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function reminderText(int $daysLeft): string {
    if ($daysLeft > 2) {
        return "متبقي {$daysLeft} أيام";
    }

    if ($daysLeft === 2) {
        return "باقى يومين";
    }

    if ($daysLeft === 1) {
        return "باقى يوم واحد";
    }

    if ($daysLeft === 0) {
        return "اليوم آخر مهلة";
    }

    return "متأخر " . abs($daysLeft) . " يوم";
}

function reminderClass(int $daysLeft): string {
    if ($daysLeft === 2) {
        return "warn-2";
    }

    if ($daysLeft === 1) {
        return "warn-1";
    }

    if ($daysLeft <= 0) {
        return "warn-now";
    }

    return "";
}

function defaultReminderDate(?string $createdAt, ?string $reminderDate): string {
    if (!empty($reminderDate) && $reminderDate !== '0000-00-00') {
        return date("Y-m-d", strtotime($reminderDate));
    }

    if (!empty($createdAt)) {
        return date("Y-m-d", strtotime($createdAt . " +4 days"));
    }

    return date("Y-m-d", strtotime("+4 days"));
}


function vcDraftColumnExists(VcDb $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->prepare("\n            SELECT COUNT(*) AS c\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = ?\n              AND COLUMN_NAME = ?\n        ");
        if (!$stmt) return false;
        $stmt->bind_param("ss", $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['c'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function vcDisabledDraftHookSetup(VcDb $conn): void {
    return;
}

function vcDraftGetUserJobRole(VcDb $conn, int $userId): string {
    if ($userId <= 0 || !vcDraftColumnExists($conn, 'users', 'job_role')) return 'user';
    $stmt = $conn->prepare("SELECT job_role FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return 'user';
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string)($row['job_role'] ?? 'user');
}

function vcDraftGetDirectSectionManagerId(VcDb $conn, int $ownerId): int {
    if ($ownerId <= 0 || !vcDraftColumnExists($conn, 'users', 'manager_id')) return 0;

    $stmt = $conn->prepare("SELECT manager_id FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param("i", $ownerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $managerId = (int)($row['manager_id'] ?? 0);
    if ($managerId <= 0 || $managerId === $ownerId) return 0;

    $managerRole = vcDraftGetUserJobRole($conn, $managerId);
    if ($managerRole !== 'section_manager') {
        return 0;
    }

    return $managerId;
}

function vcDisabledDraftHookOnce(VcDb $conn, int $recipientId, int $contractId, string $title, string $message, string $link): void {
    return;
}

function vcDisabledDraftDeadlineHooks(VcDb $conn, array $row, int $daysLeft): void {
    return;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    header("Location: login.php");
    exit();
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


$stmt = $conn->prepare("SELECT is_admin, role, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$job_role = (string)($user['job_role'] ?? 'user');
$is_admin = !empty($user) && ((int)($user['is_admin'] ?? 0) === 1 || ($user['role'] ?? '') === 'admin' || $job_role === 'admin' || $job_role === 'commercial_manager');
$is_section_manager = ($job_role === 'section_manager');
$is_normal_user = ($job_role === 'user');
$show_deadline_alerts = ($is_normal_user || $is_section_manager);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_reminder') {

    header("Content-Type: application/json; charset=UTF-8");

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "طلب غير صالح"
        ]);
        exit();
    }

    $contract_id = (int)($_POST['id'] ?? 0);
    $date = trim($_POST['date'] ?? '');

    if ($contract_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "بيانات غير صحيحة"
        ]);
        exit();
    }

    if ($is_admin) {
        $stmt = $conn->prepare("
            UPDATE contracts 
            SET reminder_date = ?
            WHERE id = ? AND status = 'draft'
            LIMIT 1
        ");
        $stmt->bind_param("si", $date, $contract_id);
    } else {
        $stmt = $conn->prepare("
            UPDATE contracts 
            SET reminder_date = ?
            WHERE id = ? AND created_by = ? AND status = 'draft'
            LIMIT 1
        ");
        $stmt->bind_param("sii", $date, $contract_id, $user_id);
    }

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        echo json_encode([
            "success" => false,
            "message" => "لم يتم حفظ التذكير"
        ]);
        exit();
    }

    echo json_encode([
        "success" => true,
        "message" => "تم حفظ التذكير"
    ]);
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && (
    (($_POST['action'] ?? '') === 'delete_draft') || isset($_POST['delete_id'])
)) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    if (!$is_admin) {
        http_response_code(403);
        die("❌ ليس لديك صلاحية حذف المسودات");
    }

    $delete_id = (int)($_POST['delete_id'] ?? 0);

    if ($delete_id > 0) {
        $stmt = $conn->prepare("
            UPDATE contracts 
            SET status = 'deleted' 
            WHERE id = ? AND status = 'draft'
            LIMIT 1
        ");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: drafts.php?deleted=1");
    exit();
}


$search = trim($_GET['search'] ?? '');
$user_filter = trim($_GET['user'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$page_name = 'drafts';


$scope = 'own';

$stmt = $conn->prepare("
    SELECT up.scope 
    FROM user_permissions up
    JOIN pages pg ON pg.id = up.page_id
    WHERE up.user_id = ? AND pg.name = ?
    LIMIT 1
");
$stmt->bind_param("is", $user_id, $page_name);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows > 0) {
    $perm = $res->fetch_assoc();
    $scope = $perm['scope'] ?? 'own';
}
$stmt->close();

$scope = in_array($scope, ['own','team','all'], true) ? $scope : 'own';
$scopedUserIds = vcGetScopedUserIds($conn, $user_id, $scope, $is_admin);


$show_user_column = ($is_admin || in_array($scope, ['team','all'], true));


$can_view_all_drafts = empty($scopedUserIds);
if (!$show_user_column || ($user_filter !== '' && !vcIsUserInScope((int)$user_filter, $scopedUserIds))) {
    $user_filter = '';
}


$fromWhere = "
    FROM contracts
    LEFT JOIN users ON users.id = contracts.created_by
    WHERE contracts.status IN ('draft', 'review')
";

$params = [];
$types = "";

$fromWhere .= vcBuildInCondition('contracts.created_by', $scopedUserIds, $params, $types);
if ($show_user_column && $user_filter !== '') {
    $fromWhere .= " AND contracts.created_by = ?";
    $params[] = (int)$user_filter;
    $types .= "i";
}

if ($status_filter === 'new') {
    $fromWhere .= " AND contracts.last_edited_by IS NULL";
}

if ($status_filter === 'edited') {
    $fromWhere .= " AND contracts.last_edited_by IS NOT NULL";
}

if ($search !== '') {
    $fromWhere .= " AND contracts.supplier_name LIKE ?";
    $params[] = "%{$search}%";
    $types .= "s";
}

$pg = vcPaginationState();
$totalRows = vcPaginationCount($conn, $fromWhere, $params, $types);
$totalPages = vcPaginationTotalPages($totalRows, $pg['per_page']);
$page = min($pg['page'], $totalPages);

$sql = "
    SELECT contracts.*, users.username
    {$fromWhere}
    ORDER BY contracts.id DESC
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
$draftRowsNotice = [];
$todayObj = new DateTime(date("Y-m-d"));

while ($row = $result->fetch_assoc()) {

    $reminder = defaultReminderDate($row['created_at'] ?? '', $row['reminder_date'] ?? '');
    $reminderObj = new DateTime($reminder);
    $daysLeft = (int)$todayObj->diff($reminderObj)->format("%r%a");

    $row['_reminder'] = $reminder;
    $row['_days_left'] = $daysLeft;
    $row['_warning_class'] = reminderClass($daysLeft);
    $row['_reminder_text'] = reminderText($daysLeft);

    
    $rowOwnerId = (int)($row['created_by'] ?? 0);
    $rowInsideCurrentScope = empty($scopedUserIds) || in_array($rowOwnerId, $scopedUserIds, true);

    if ($show_deadline_alerts && $daysLeft <= 2) {
        if (($is_normal_user && $rowOwnerId === $user_id) || ($is_section_manager && $rowInsideCurrentScope)) {
            $draftRowsNotice[] = $row;
        }
    }

    
    vcDisabledDraftDeadlineHooks($conn, $row, $daysLeft);

    $rows[] = $row;
}
$stmt->close();


$users_result = $show_user_column ? vcGetVisibleUsersForFilter($conn, $scopedUserIds) : [];

function statusBadge($isEdited, string $contractStatus = 'draft'): string {
    if ($contractStatus === 'review') {
        return '<span class="badge badge-edited">تحت المراجعة</span>';
    }

    if ($isEdited) {
        return '<span class="badge badge-edited">معدل من الإدارة</span>';
    }

    return '<span class="badge badge-new">تفاوض جديد</span>';
}

$colspan = 5 + ($is_admin ? 1 : 0) + ($show_user_column ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>مسودات العقود</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="public/assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
*{
    box-sizing:border-box;
    font-family:'Cairo', Tahoma, Arial, sans-serif;
}

body{
    margin:0;
    background:
        radial-gradient(circle at top right, rgba(109,74,255,.10), transparent 32%),
        #eef1f7;
    color:#172033;
}

.container{
    width:min(1380px, calc(100% - 64px));
    margin:24px auto 40px;
}

.page-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    margin-bottom:16px;
}

.title{
    margin:0;
    font-size:25px;
    font-weight:900;
    color:#172033;
}

.subtitle{
    margin-top:5px;
    font-size:13px;
    color:#667085;
    font-weight:700;
}


.alert{
    padding:12px 14px;
    border-radius:14px;
    margin-bottom:14px;
    font-weight:800;
    box-shadow:0 10px 24px rgba(23,32,51,.06);
}

.alert-success{
    background:#ecfdf3;
    color:#166534;
    border:1px solid #bbf7d0;
}


.draft-rows-notice{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));
    gap:12px;
    margin-bottom:16px;
}

.notify-card{
    background:rgba(255,255,255,.74);
    border:1px solid rgba(226,232,240,.95);
    border-radius:18px;
    padding:14px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
}

.notify-card.warn-2{
    border-right:6px solid #facc15;
    background:#fffbeb;
}

.notify-card.warn-1{
    border-right:6px solid #fb923c;
    background:#fff7ed;
}

.notify-card.warn-now{
    border-right:6px solid #ef4444;
    background:#fff1f2;
}

.notify-title{
    font-size:13px;
    color:#172033;
    font-weight:900;
    line-height:1.8;
}

.notify-sub{
    font-size:12px;
    color:#667085;
    font-weight:800;
    margin-top:4px;
}

.notify-tag{
    min-width:92px;
    min-height:34px;
    border-radius:999px;
    padding:6px 10px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:900;
    white-space:nowrap;
}

.warn-2 .notify-tag{
    background:#facc15;
    color:#172033;
}

.warn-1 .notify-tag{
    background:#fb923c;
    color:#fff;
}

.warn-now .notify-tag{
    background:#ef4444;
    color:#fff;
}


.search-box{
    background:rgba(255,255,255,.72);
    border:1px solid rgba(226,232,240,.95);
    border-radius:20px;
    padding:14px;
    display:grid;
    grid-template-columns:1fr 160px 160px;
    gap:12px;
    margin-bottom:16px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}

.search-box input,
.search-box select{
    width:100%;
    min-height:46px;
    padding:0 14px;
    border-radius:13px;
    border:1px solid #dfe6f0;
    background:#eef1f7;
    color:#172033;
    font-size:14px;
    outline:none;
    box-shadow:
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
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
    background:rgba(255,255,255,.60);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:0;
    overflow:hidden;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}

.table{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
    background:#eef1f7;
}

.table th{
    background:#6d4aff;
    color:#fff;
    padding:12px 7px;
    text-align:center;
    font-size:13px;
    line-height:1.35;
    font-weight:900;
    white-space:normal;
}

.table td{
    padding:11px 7px;
    border-top:1px solid #dfe6f0;
    text-align:center;
    vertical-align:middle;
    font-size:14px;
    line-height:1.6;
    color:#172033;
}

.table tr:hover td{
    background:#f6f4ff;
}


.col-id{width:58px;}
.col-supplier{width:21%;}
.col-manager{width:11%;}
.col-user{width:8%;}
.col-created{width:105px;}
.col-reminder{width:130px;}
.col-status{width:120px;}
.col-deadline{width:115px;}
.col-actions{width:min(220px, 22%);}

.supplier-name{
    font-weight:800;
    white-space:normal;
    overflow-wrap:anywhere;
    word-break:break-word;
    text-align:right;
}

.manager-name,
.user-name{
    white-space:normal;
    overflow-wrap:anywhere;
    word-break:break-word;
}


.reminder-input{
    width:130px;
    min-height:34px;
    border-radius:8px;
    border:1px solid #cfd8e3;
    background:#fff;
    padding:0 8px;
    font-size:13px;
    text-align:center;
}


.deadline-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:104px;
    min-height:34px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    padding:6px 10px;
    background:#eef1f7;
    color:#475569;
}

.deadline-pill.warn-2{
    background:#facc15;
    color:#172033;
}

.deadline-pill.warn-1{
    background:#fb923c;
    color:#fff;
}

.deadline-pill.warn-now{
    background:#ef4444;
    color:#fff;
}


.badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:116px;
    min-height:36px;
    padding:5px 10px;
    border-radius:999px;
    font-size:12px;
    line-height:1.35;
    color:#fff;
    font-weight:900;
    text-align:center;
}

.badge-new{
    background:#3b82f6;
}

.badge-edited{
    background:#f59e0b;
}


tr.warn-2 td{
    background:#fffbeb !important;
}

tr.warn-1 td{
    background:#fff7ed !important;
}

tr.warn-now td{
    background:#fff1f2 !important;
}


.actions-cell{
    text-align:center;
    vertical-align:middle;
    padding-left:6px !important;
    padding-right:6px !important;
}

.action-buttons,
.vc-row-actions{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    flex-wrap:wrap;
    white-space:normal;
}

.action-buttons form,
.vc-row-actions form{
    margin:0;
    padding:0;
    display:inline-flex;
}

.btn{
    min-width:38px;
    height:32px;
    padding:0 7px;
    border-radius:10px;
    text-decoration:none;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:0;
    cursor:pointer;
    transition:.18s ease;
    color:#fff;
}

.btn:hover{
    transform:translateY(-1px);
    filter:brightness(.97);
}

.btn-view{
    background:#6d4aff;
}

.btn-edit{
    background:#f59e0b;
}

.btn-delete{
    background:#ef4444;
}


.empty{
    padding:26px !important;
    font-weight:900;
    color:#667085;
}


#toast{
    position:fixed;
    bottom:20px;
    right:20px;
    background:#16a34a;
    color:#fff;
    padding:11px 16px;
    border-radius:12px;
    display:none;
    box-shadow:0 12px 28px rgba(0,0,0,.16);
    z-index:9999;
    font-weight:900;
}

#toast.error{
    background:#ef4444;
}


@media(max-width:1100px){
    .container{
        width:calc(100% - 28px);
    }

    .table th,
    .table td{
        padding:10px 6px;
        font-size:12px;
    }

    .badge{
        width:104px;
        font-size:11px;
    }

    .btn{
        min-width:36px;
        padding:0 6px;
        font-size:11px;
    }

    .action-buttons{
        gap:4px;
    }

    .reminder-input{
        width:112px;
    }
}

@media(max-width:850px){
    .search-box{
        grid-template-columns:1fr;
    }

    .page-head{
        display:block;
    }

    .table{
        table-layout:auto;
    }

    .table-box{
        overflow-x:auto;
    }

    .table{
        min-width:1040px;
    }
}


.table{
    width:100% !important;
    max-width:100% !important;
}

.table-box{
    overflow:hidden !important;
}

.actions-cell{
    min-width:150px !important;
    overflow:visible !important;
}

.action-buttons{
    width:100%;
    justify-content:center;
    overflow:visible !important;
}

.btn-delete,
.btn-edit,
.btn-view{
    flex:0 0 auto;
}

@media(max-width:1100px){
    .table-box{
        overflow-x:auto !important;
    }

    .table{
        min-width:1040px !important;
    }
}

</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <div>
            <h1 class="title">📄 مسودات العقود</h1>
            <div class="subtitle">متابعة عقود التفاوض والعقود تحت المراجعة حسب الصلاحية</div>
        </div>
    </div>

    <?php if(isset($_GET['deleted'])): ?>
        <div class="alert alert-success">تم حذف المسودة بنجاح ✅</div>
    <?php endif; ?>

    <?php if(!empty($draftRowsNotice)): ?>
        <div class="draft-rows-notice">
            <?php foreach($draftRowsNotice as $n): ?>
                <div class="notify-card <?= e($n['_warning_class']) ?>">
                    <div>
                        <div class="notify-title">
                            العقد رقم #<?= (int)$n['id'] ?> - <?= e($n['supplier_name'] ?? '-') ?>
                        </div>
                        <div class="notify-sub">
                            مهلة الرد: <?= e($n['_reminder']) ?>
                        </div>
                    </div>

                    <div class="notify-tag">
                        <?= e($n['_reminder_text']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="search-box">

        <input type="text"
               id="searchInput"
               placeholder="🔍 بحث باسم المورد..."
               value="<?= e($search) ?>">

        <select id="statusFilter" onchange="applyFilters()">
            <option value="">الحالة</option>
            <option value="new" <?= ($status_filter === 'new') ? 'selected' : '' ?>>تفاوض جديد</option>
            <option value="edited" <?= ($status_filter === 'edited') ? 'selected' : '' ?>>معدل من الإدارة</option>
        </select>

        <?php if($show_user_column): ?>
            <select id="userFilter" onchange="applyFilters()">
                <option value="">بواسطة: كل الفريق</option>
                <?php foreach($users_result as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= ((string)$user_filter === (string)$u['id']) ? 'selected' : '' ?>>
                        <?= e($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

    </div>

    <div class="table-box">

        <table class="table">

            <thead>
                <tr>
                    <th class="col-id">رقم</th>
                    <th class="col-supplier">المورد</th>

                    <?php if($is_admin): ?>
                        <th class="col-manager">المسؤول</th>
                    <?php endif; ?>

                    <?php if($show_user_column): ?>
                        <th class="col-user">بواسطة</th>
                    <?php endif; ?>

                    <th class="col-created">تاريخ التفاوض</th>
                    <th class="col-reminder">تاريخ التذكير</th>
                    <th class="col-deadline">مهلة الرد</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-actions">إجراءات</th>
                </tr>
            </thead>

            <tbody>

            <?php if(!empty($rows)): ?>

                <?php foreach($rows as $row): ?>

                    <?php
                    $created = $row['created_at'] ?? '';
                    $isEdited = !empty($row['last_edited_by']);

                    $edit_link = (($row['source'] ?? '') === 'rent')
                        ? "rents.php?id=" . (int)$row['id']
                        : "add_contract.php?id=" . (int)$row['id'];

                    $view_link = "view_contract.php?id=" . (int)$row['id'];

                    $rowClass = $row['_warning_class'] ?? '';

                    
                    // أي مسودة تظهر في الجدول فالمستخدم لديه صلاحية ضمن نطاقه (own/team/all)
                    $canTakeDraftAction = true;
                    ?>

                    <tr class="<?= e($rowClass) ?>" data-id="<?= (int)$row['id'] ?>">

                        <td>#<?= (int)$row['id'] ?></td>

                        <td class="supplier-name"><?= e($row['supplier_name'] ?? '-') ?></td>

                        <?php if($is_admin): ?>
                            <td class="manager-name"><?= e($row['company_name'] ?? '-') ?></td>
                        <?php endif; ?>

                        <?php if($show_user_column): ?>
                            <td class="user-name"><?= e($row['username'] ?? '-') ?></td>
                        <?php endif; ?>

                        <td><?= $created ? e(date("Y-m-d", strtotime($created))) : '-' ?></td>

                        <td>
                            <?php if($canTakeDraftAction): ?>
                                <input type="date"
                                       class="reminder-input"
                                       value="<?= e($row['_reminder']) ?>"
                                       onchange="updateReminder(<?= (int)$row['id'] ?>, this.value)">
                            <?php else: ?>
                                <span class="deadline-pill">
                                    <?= e($row['_reminder']) ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="deadline-pill <?= e($rowClass) ?>">
                                <?= e($row['_reminder_text']) ?>
                            </span>
                        </td>

                        <td>
                            <?= statusBadge($isEdited, (string)($row['status'] ?? 'draft')) ?>
                        </td>

                        <td class="actions-cell">
                            <?php
                            $draftActions = [
                                'view' => [
                                    'href' => $view_link,
                                ],
                            ];

                            $draftActions['edit'] = ['href' => $edit_link];

                            if ($is_admin) {
                                $draftActions['delete'] = [
                                    'action' => 'delete_draft',
                                    'fields' => ['delete_id' => (string)(int)$row['id']],
                                    'confirm' => 'هل أنت متأكد من حذف هذه المسودة؟',
                                ];
                            }

                            vcRenderRowActions($draftActions, $csrf_token, $is_admin);
                            ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td class="empty" colspan="<?= (int)$colspan + 1 ?>">لا توجد مسودات</td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

    <?php vcRenderPagination($page, $totalPages); ?>

</div>

<div id="toast"></div>

<script>
const csrfToken = "<?= e($csrf_token) ?>";

let timer;
const searchInput = document.getElementById("searchInput");

if(searchInput){
    searchInput.addEventListener("keyup", function(){
        clearTimeout(timer);

        let value = this.value;

        timer = setTimeout(function(){
            let url = new URL(window.location.href);

            if(value){
                url.searchParams.set("search", value);
            }else{
                url.searchParams.delete("search");
            }

            url.searchParams.delete("pg");

            window.location.href = url;
        }, 400);
    });
}

function updateReminder(id, date){

    const body = new URLSearchParams();
    body.append("action", "update_reminder");
    body.append("id", id);
    body.append("date", date);
    body.append("csrf_token", csrfToken);

    fetch("drafts.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Requested-With": "XMLHttpRequest"
        },
        body: body.toString()
    })
    .then(res => res.json())
    .then(data => {

        if(data.success){

            showToast("تم حفظ التذكير ✅");

            let row = document.querySelector("tr[data-id='" + id + "']");

            if(row){
                row.style.transition = ".2s";
                row.style.background = "#dcfce7";

                setTimeout(() => {
                    location.reload();
                }, 700);
            }

        }else{
            showToast(data.message || "لم يتم حفظ التذكير", "error");
        }

    })
    .catch(() => {
        showToast("تعذر حفظ التذكير", "error");
    });

}

function showToast(msg, type){
    let t = document.getElementById("toast");
    t.innerText = msg;

    if(type === "error"){
        t.classList.add("error");
    }else{
        t.classList.remove("error");
    }

    t.style.display = "block";

    setTimeout(() => {
        t.style.display = "none";
    }, 2000);
}

function applyFilters(){

    let url = new URL(window.location.href);

    let search = document.getElementById("searchInput")
        ? document.getElementById("searchInput").value
        : "";

    let status = document.getElementById("statusFilter")
        ? document.getElementById("statusFilter").value
        : "";

    let userSelect = document.getElementById("userFilter");
    let user = userSelect ? userSelect.value : "";

    if(search){
        url.searchParams.set("search", search);
    }else{
        url.searchParams.delete("search");
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

    url.searchParams.delete("pg");

    window.location.href = url;
}
</script>

</body>
</html>
