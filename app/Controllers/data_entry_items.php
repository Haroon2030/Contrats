<?php
require_once VC_HELPERS . '/auth.php';



date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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


function money($value): string {
    return number_format((float)$value, 2);
}

function columnExists(VcDb $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row) && (int)$row['c'] > 0;
}


function vcNotifyColumnExists(VcDb $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    if (!$stmt) return false;
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !empty($row) && (int)$row['c'] > 0;
}

function vcDisabledHookSetup(VcDb $conn): void {
    return;
}

function vcDisabledUserHook(VcDb $conn, int $userId, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0): void {
    return;
}

function vcDisabledAdminsHook(VcDb $conn, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0, int $excludeUserId = 0): void {
    return;
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


$stmtUser = $conn->prepare("SELECT is_admin, role FROM users WHERE id = ? LIMIT 1");
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$currentUser = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$is_admin = !empty($currentUser) && (
    (int)($currentUser['is_admin'] ?? 0) === 1 ||
    ($currentUser['role'] ?? '') === 'admin'
);


$dataEntryItemsScope = getUserPageScope($conn, $user_id, 'data_entry_items');
$canAccessDataEntryItems = (
    $is_admin ||
    $dataEntryItemsScope !== 'none'
);

if (!$canAccessDataEntryItems) {
    http_response_code(403);
    die("❌ ليس لديك صلاحية الدخول إلى إدخال الأصناف");
}




if (!columnExists($conn, 'items', 'entry_done')) {
    $conn->query("ALTER TABLE items ADD COLUMN entry_done TINYINT(1) NOT NULL DEFAULT 0");
}

if (!columnExists($conn, 'items', 'entered_by')) {
    $conn->query("ALTER TABLE items ADD COLUMN entered_by INT NULL");
}

if (!columnExists($conn, 'items', 'entered_at')) {
    $conn->query("ALTER TABLE items ADD COLUMN entered_at DATETIME NULL");
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_entered') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    $batch_id = trim((string)($_POST['batch_id'] ?? ''));

    if ($batch_id !== '') {

        $stmtInfo = $conn->prepare("
            SELECT
                batch_id,
                MAX(supplier_name) AS supplier_name,
                MAX(created_by) AS created_by,
                COUNT(*) AS items_count
            FROM items
            WHERE batch_id = ?
            GROUP BY batch_id
            LIMIT 1
        ");
        $entryInfo = null;
        if ($stmtInfo) {
            $stmtInfo->bind_param("s", $batch_id);
            $stmtInfo->execute();
            $entryInfo = $stmtInfo->get_result()->fetch_assoc();
            $stmtInfo->close();
        }

        $stmt = $conn->prepare("
            UPDATE items
            SET entry_done = 1,
                entered_by = ?,
                entered_at = NOW()
            WHERE batch_id = ?
            AND status = 'approved'
            AND (entry_done IS NULL OR entry_done = 0)
        ");
        $stmt->bind_param("is", $user_id, $batch_id);
        $stmt->execute();
        $affectedEntry = $stmt->affected_rows;
        $stmt->close();

        if ($affectedEntry > 0) {
        }

        if ($affectedEntry > 0 && !empty($entryInfo)) {
            $ownerId = (int)($entryInfo['created_by'] ?? 0);
            if ($ownerId > 0 && $ownerId !== $user_id) {
                vcDisabledUserHook(
                    $conn,
                    $ownerId,
                    'تم إدخال الأصناف',
                    'تم إدخال دفعة الأصناف رقم ' . $batch_id . ' للمورد: ' . ($entryInfo['supplier_name'] ?? '') . ' — عدد الأصناف: ' . (int)($entryInfo['items_count'] ?? 0),
                    'view_items.php?batch=' . urlencode((string)$batch_id),
                    'items_entry_done',
                    0
                );
            }
        }

        header("Location: data_entry_items.php?done=1");
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_items_batch') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }
    if (!$is_admin) {
        http_response_code(403);
        die("❌ ليس لديك صلاحية حذف دفعات الأصناف");
    }

    $batch_id = trim((string)($_POST['batch_id'] ?? ''));

    if ($batch_id !== '') {
        $conn->begin_transaction();

        try {
            if (columnExists($conn, 'approval_withdrawals', 'target_type') && columnExists($conn, 'approval_withdrawals', 'target_id')) {
                $stmtWithdrawals = $conn->prepare("
                    DELETE FROM approval_withdrawals
                    WHERE target_type = 'items'
                    AND target_id = ?
                ");
                if ($stmtWithdrawals) {
                    $stmtWithdrawals->bind_param("s", $batch_id);
                    $stmtWithdrawals->execute();
                    $stmtWithdrawals->close();
                }
            }

            $stmtItems = $conn->prepare("DELETE FROM items WHERE batch_id = ?");
            if (!$stmtItems) {
                throw new Exception("تعذر تجهيز حذف الدفعة");
            }

            $stmtItems->bind_param("s", $batch_id);
            $stmtItems->execute();
            $stmtItems->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            die("ERROR: " . $e->getMessage());
        }
    }

    header("Location: data_entry_items.php");
    exit();
}


$search = trim($_GET['search'] ?? '');
$entry_filter = trim($_GET['entry'] ?? '');

if (!in_array($entry_filter, ['', 'done', 'pending'], true)) {
    $entry_filter = '';
}


$fromWhere = "
    FROM items i
    LEFT JOIN users creator ON creator.id = i.created_by
    LEFT JOIN users entry_user ON entry_user.id = i.entered_by
    WHERE i.status = 'approved'
";

$params = [];
$types  = "";

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

$groupBase = "
    SELECT i.batch_id
    {$fromWhere}
    GROUP BY
        i.batch_id,
        i.created_by,
        creator.username,
        entry_user.username
";

$doneInner = $groupBase . " HAVING MAX(i.entry_done) = 1";
$pendingInner = $groupBase . " HAVING MAX(i.entry_done) IS NULL OR MAX(i.entry_done) = 0";

$doneCount = vcPaginationCountGrouped($conn, $doneInner, $params, $types);
$pendingCount = vcPaginationCountGrouped($conn, $pendingInner, $params, $types);
$totalRequests = $doneCount + $pendingCount;

$havingSql = '';
if ($entry_filter === 'done') {
    $havingSql = "HAVING MAX(i.entry_done) = 1";
}

if ($entry_filter === 'pending') {
    $havingSql = "HAVING MAX(i.entry_done) IS NULL OR MAX(i.entry_done) = 0";
}

$totalRows = $totalRequests;
if ($entry_filter === 'done') {
    $totalRows = $doneCount;
} elseif ($entry_filter === 'pending') {
    $totalRows = $pendingCount;
}

$pg = vcPaginationState();
$totalPages = vcPaginationTotalPages($totalRows, $pg['per_page']);
$page = min($pg['page'], $totalPages);

$sql = "
    SELECT 
        i.batch_id,
        MAX(i.supplier_name) AS supplier_name,
        i.created_by,
        creator.username AS creator_username,
        MAX(i.created_at) AS created_at,
        MAX(i.approved_at) AS approved_at,
        MAX(i.entry_done) AS entry_done,
        MAX(i.entered_by) AS entered_by,
        MAX(i.entered_at) AS entered_at,
        entry_user.username AS entered_username
    {$fromWhere}
    GROUP BY
        i.batch_id,
        i.created_by,
        creator.username,
        entry_user.username
    {$havingSql}
    ORDER BY MAX(i.approved_at) DESC, MAX(i.created_at) DESC, i.batch_id DESC
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

<title>إدخال الأصناف</title>

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
    width:min(1500px, calc(100% - 12px));
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


.alert{
    padding:13px 15px;
    border-radius:14px;
    margin-bottom:15px;
    font-weight:800;
    line-height:1.8;
    box-shadow:0 10px 24px rgba(23,32,51,.06);
}

.alert-success{
    background:#ecfdf3;
    color:#166534;
    border:1px solid #bbf7d0;
}


.summary-grid{
    display:grid;
    grid-template-columns:repeat(3, 1fr);
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


.filters{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:14px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    display:grid;
    grid-template-columns:1fr 190px;
    gap:12px;
    margin-bottom:18px;
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
    padding:10px 5px;
    text-align:center;
    font-size:10.8px;
    line-height:1.3;
    font-weight:900;
    white-space:normal;
}

.table th:first-child{
    border-radius:0 14px 14px 0;
}

.table th:last-child{
    border-radius:14px 0 0 14px;
}

.table td{
    padding:10px 6px;
    border-bottom:1px solid #dfe6f0;
    text-align:center;
    vertical-align:middle;
    font-size:11px;
    line-height:1.55;
    color:#172033;
}

.table tr:last-child td{
    border-bottom:none;
}

.table tr:hover td{
    background:#f6f4ff;
}

.col-batch{width:9%;}
.col-supplier{width:29%;}
.col-creator{width:10%;}
.col-date{width:11%;}
.col-entry{width:12%;}
.col-entered-at{width:11%;}
.col-view{width:7%;}
.col-action{width:11%;}

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
}

.money{
    color:#166534;
    font-weight:900;
    direction:ltr;
}

.user-badge{
    background:#f0edff;
    color:#4f46e5;
    min-width:70px;
    max-width:100%;
    min-height:30px;
    padding:4px 7px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:10.5px;
    font-weight:900;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}


.status{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:72px;
    min-height:29px;
    padding:4px 6px;
    border-radius:999px;
    font-size:10.5px;
    font-weight:900;
    white-space:nowrap;
}

.status.done{
    background:#ecfdf3;
    color:#166534;
}

.status.pending{
    background:#fff1f2;
    color:#b42318;
}


.btn{
    min-height:29px;
    padding:0 6px;
    border:none;
    border-radius:11px;
    cursor:pointer;
    text-decoration:none;
    font-size:10.5px;
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

.btn-view{
    background:#6d4aff;
}

.btn-done{
    background:#16a34a;
}

.done-text{
    color:#667085;
    font-weight:900;
}

.empty{
    padding:26px !important;
    text-align:center;
    color:#667085;
    font-weight:900;
}

.actions{
    white-space:nowrap;
}

.actions form{
    margin:0;
}

@media(max-width:1180px){
    .summary-grid{
        grid-template-columns:repeat(3, 1fr);
    }

    .table-box{
        overflow:hidden;
    }
}

@media(max-width:800px){
    .summary-grid{
        grid-template-columns:repeat(2, 1fr);
    }

    .filters{
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

    .summary-grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">⌨️ إدخال الأصناف</h1>
        <p class="page-subtitle">
            متابعة كل طلبات إضافة الأصناف التي تمت الموافقة عليها من المدير، وتسجيل من قام بإدخالها.
        </p>
    </div>

    <?php if(isset($_GET['done'])): ?>
        <div class="alert alert-success">تم تسجيل إدخال الأصناف بنجاح ✅</div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-value"><?= (int)$totalRequests ?></div>
            <div class="summary-label">طلبات معتمدة</div>
        </div>

        <div class="summary-card">
            <div class="summary-value"><?= (int)$doneCount ?></div>
            <div class="summary-label">تم الإدخال</div>
        </div>

        <div class="summary-card">
            <div class="summary-value"><?= (int)$pendingCount ?></div>
            <div class="summary-label">لم يتم الإدخال</div>
        </div>
    </div>

    <form class="filters" method="GET">
        <input type="text"
               id="searchInput"
               name="search"
               placeholder="🔍 بحث باسم المورد أو رقم الطلب أو اسم الموظف..."
               value="<?= e($search) ?>">

        <select name="entry" id="entryFilter">
            <option value="" <?= $entry_filter === '' ? 'selected' : '' ?>>كل الحالات</option>
            <option value="pending" <?= $entry_filter === 'pending' ? 'selected' : '' ?>>لم يتم الإدخال</option>
            <option value="done" <?= $entry_filter === 'done' ? 'selected' : '' ?>>تم الإدخال</option>
        </select>
    </form>

    <div class="table-box">

        <table class="table">
            <thead>
                <tr>
                    <th class="col-batch">رقم الطلب</th>
                    <th class="col-supplier">المورد</th>
                    <th class="col-creator">الموظف</th>
                    <th class="col-date">تاريخ الموافقة</th>
                    <th class="col-entry">حالة الإدخال</th>
                    <th class="col-entered-at">تاريخ الإدخال</th>
                    <th class="col-actions">إجراءات</th>
                    <th class="col-action">إجراء</th>
                </tr>
            </thead>

            <tbody>
                <?php if(!empty($rows)): ?>

                    <?php foreach($rows as $row): ?>
                        <?php
                            $isDone = !empty($row['entry_done']);

                            $approvedAt = !empty($row['approved_at'])
                                ? date("Y-m-d", strtotime($row['approved_at']))
                                : (
                                    !empty($row['created_at'])
                                    ? date("Y-m-d", strtotime($row['created_at']))
                                    : '-'
                                );

                            $enteredAt = !empty($row['entered_at'])
                                ? date("Y-m-d", strtotime($row['entered_at']))
                                : '-';
                        ?>

                        <tr>
                            <td>
                                <span class="batch-id">#<?= e($row['batch_id']) ?></span>
                            </td>

                            <td class="supplier-name">
                                <?= e($row['supplier_name'] ?? '-') ?>
                            </td>

                            <td>
                                <span class="user-badge"><?= e($row['creator_username'] ?? '-') ?></span>
                            </td>

                            <td><?= e($approvedAt) ?></td>

                            <td>
                                <?php if($isDone): ?>
                                    <span class="status done">تم الإدخال</span>
                                <?php else: ?>
                                    <span class="status pending">لم يتم الإدخال</span>
                                <?php endif; ?>
                            </td>

                            <td><?= e($enteredAt) ?></td>

                            <td>
                                <?php
                                vcRenderRowActions([
                                    'view' => [
                                        'href' => 'view_items.php?batch=' . urlencode((string)$row['batch_id']),
                                    ],
                                    'edit' => [
                                        'href' => 'add_items.php?edit_batch=' . urlencode((string)$row['batch_id']),
                                    ],
                                    'delete' => [
                                        'action' => 'delete_items_batch',
                                        'fields' => ['batch_id' => (string)$row['batch_id']],
                                        'confirm' => 'تأكيد حذف دفعة الأصناف رقم ' . (string)$row['batch_id'] . '؟',
                                    ],
                                ], $csrf_token, $is_admin);
                                ?>
                            </td>

                            <td class="actions">
                                <?php if(!$isDone): ?>
                                    <form method="POST" onsubmit="return confirm('تأكيد أن هذه الأصناف تم إدخالها؟')">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                        <input type="hidden" name="action" value="mark_entered">
                                        <input type="hidden" name="batch_id" value="<?= e($row['batch_id']) ?>">
                                        <button type="submit" class="btn btn-done">تم الإدخال</button>
                                    </form>
                                <?php else: ?>
                                    <span class="done-text">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty">لا توجد طلبات أصناف معتمدة مطابقة</td>
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
const entryFilter = document.getElementById("entryFilter");

function applyFilters(){
    let url = new URL(window.location.href);
    const search = document.getElementById("searchInput") ? document.getElementById("searchInput").value : "";
    const entry = document.getElementById("entryFilter") ? document.getElementById("entryFilter").value : "";

    if(search){
        url.searchParams.set("search", search);
    }else{
        url.searchParams.delete("search");
    }

    if(entry){
        url.searchParams.set("entry", entry);
    }else{
        url.searchParams.delete("entry");
    }

    url.searchParams.delete("pg");

    window.location.href = url;
}

if(searchInput){
    searchInput.addEventListener("keyup", function(){
        clearTimeout(timer);
        timer = setTimeout(applyFilters, 450);
    });
}

if(entryFilter){
    entryFilter.addEventListener("change", applyFilters);
}
</script>

</body>
</html>
