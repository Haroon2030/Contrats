<?php
require_once VC_HELPERS . '/auth.php';


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


function money($value): string {
    return number_format((float)$value, 2);
}


function vcNotifyColumnExists(mysqli $conn, string $table, string $column): bool {
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

function vcDisabledHookSetup(mysqli $conn): void {
    return;
}

function vcDisabledUserHook(mysqli $conn, int $userId, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0): void {
    return;
}

function vcDisabledAdminsHook(mysqli $conn, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0, int $excludeUserId = 0): void {
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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deduct') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    $batch = trim($_POST['batch_id'] ?? '');

    if ($batch !== '') {

        $stmtInfo = $conn->prepare("
            SELECT
                batch_id,
                MAX(supplier_name) AS supplier_name,
                MAX(created_by) AS created_by,
                COUNT(*) AS items_count,
                COALESCE(SUM(fee), 0) AS total_fees
            FROM items
            WHERE batch_id = ?
            GROUP BY batch_id
            LIMIT 1
        ");
        $deductInfo = null;
        if ($stmtInfo) {
            $stmtInfo->bind_param("s", $batch);
            $stmtInfo->execute();
            $deductInfo = $stmtInfo->get_result()->fetch_assoc();
            $stmtInfo->close();
        }

        $stmt = $conn->prepare("
            UPDATE items 
            SET paid = 1,
                deducted_by = ?,
                deducted_at = NOW()
            WHERE batch_id = ?
            AND status = 'approved'
            AND (paid IS NULL OR paid = 0)
        ");
        $stmt->bind_param("is", $uid, $batch);
        $stmt->execute();
        $affectedDeduct = $stmt->affected_rows;
        $stmt->close();

        if ($affectedDeduct > 0) {
        }

        if ($affectedDeduct > 0 && !empty($deductInfo)) {
            $ownerId = (int)($deductInfo['created_by'] ?? 0);
            if ($ownerId > 0 && $ownerId !== $uid) {
                vcDisabledUserHook(
                    $conn,
                    $ownerId,
                    'تم خصم رسوم التكويد',
                    'تم الخصم المالي لدفعة الأصناف رقم ' . $batch . ' للمورد: ' . ($deductInfo['supplier_name'] ?? '') . ' — إجمالي الرسوم: ' . number_format((float)($deductInfo['total_fees'] ?? 0), 2) . ' ريال',
                    'view_items.php?batch=' . urlencode((string)$batch),
                    'items_fee_deducted',
                    0
                );
            }
        }
    }

    header("Location: finance_items.php?done=1");
    exit();
}


$search = trim($_GET['search'] ?? '');
$paid_filter = trim($_GET['paid'] ?? '');

if (!in_array($paid_filter, ['', 'paid', 'unpaid'], true)) {
    $paid_filter = '';
}


$sql = "
    SELECT 
        i.batch_id,
        i.supplier_name,
        COUNT(*) AS items_count,
        SUM(i.fee) AS total_fees,
        MAX(i.paid) AS paid,
        MAX(i.deducted_by) AS deducted_by,
        MAX(i.deducted_at) AS deducted_at,
        u.username AS deducted_username
    FROM items i
    LEFT JOIN users u ON u.id = i.deducted_by
    WHERE i.status = 'approved'
";

$params = [];
$types = "";

if ($search !== '') {
    $sql .= " AND (
        i.supplier_name LIKE ?
        OR i.batch_id LIKE ?
    )";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

$sql .= "
    GROUP BY 
        i.batch_id,
        i.supplier_name,
        u.username
";

if ($paid_filter === 'paid') {
    $sql .= " HAVING MAX(i.paid) = 1";
}

if ($paid_filter === 'unpaid') {
    $sql .= " HAVING MAX(i.paid) IS NULL OR MAX(i.paid) = 0";
}

$sql .= " ORDER BY i.batch_id DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
$totalBatches = 0;
$totalFees = 0;
$paidCount = 0;
$unpaidCount = 0;

while ($row = $result->fetch_assoc()) {
    $rows[] = $row;

    $totalBatches++;
    $totalFees += (float)($row['total_fees'] ?? 0);

    if (!empty($row['paid'])) {
        $paidCount++;
    } else {
        $unpaidCount++;
    }
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>المالية - رسوم الأصناف</title>

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
    width:min(1280px, calc(100% - 32px));
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
    grid-template-columns:repeat(4, 1fr);
    gap:14px;
    margin-bottom:18px;
}

.summary-card{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:20px;
    padding:16px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}

.summary-label{
    font-size:13px;
    color:#667085;
    font-weight:800;
    margin-bottom:7px;
}

.summary-value{
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

.col-batch{width:120px;}
.col-supplier{width:26%;}
.col-count{width:105px;}
.col-fee{width:135px;}
.col-status{width:130px;}
.col-user{width:130px;}
.col-date{width:125px;}
.col-view{width:85px;}
.col-action{width:120px;}

.batch-id{
    color:#4f46e5;
    font-weight:900;
}

.supplier-name{
    text-align:right;
    font-weight:900;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.money{
    color:#166534;
    font-weight:900;
    direction:ltr;
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


.status{
    min-width:108px;
    min-height:34px;
    padding:6px 11px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.status-ok{
    background:#ecfdf3;
    color:#166534;
}

.status-no{
    background:#fff1f2;
    color:#b42318;
}


.btn{
    min-height:34px;
    padding:0 12px;
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

.btn-view{
    background:#6d4aff;
}

.btn-deduct{
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


@media(max-width:1100px){
    .summary-grid{
        grid-template-columns:repeat(2,1fr);
    }

    .filters{
        grid-template-columns:1fr;
    }

    .table-box{
        overflow-x:auto;
    }

    .table{
        min-width:1050px;
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
        <h1 class="page-title">💰 المالية - رسوم الأصناف</h1>
        <p class="page-subtitle">
            متابعة دفعات الأصناف المعتمدة وتسجيل خصم رسوم الدخول.
        </p>
    </div>

    <?php if(isset($_GET['done'])): ?>
        <div class="alert alert-success">
            تم تسجيل الخصم بنجاح ✅
        </div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">عدد الدفعات</div>
            <div class="summary-value"><?= (int)$totalBatches ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">إجمالي الرسوم</div>
            <div class="summary-value"><?= money($totalFees) ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">تم الخصم</div>
            <div class="summary-value"><?= (int)$paidCount ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">لم يتم الخصم</div>
            <div class="summary-value"><?= (int)$unpaidCount ?></div>
        </div>
    </div>

    <form class="filters" method="GET">
        <input type="text"
               id="searchInput"
               name="search"
               placeholder="🔍 بحث باسم المورد أو رقم الطلب..."
               value="<?= e($search) ?>">

        <select name="paid" id="paidFilter">
            <option value="" <?= $paid_filter === '' ? 'selected' : '' ?>>كل الحالات</option>
            <option value="unpaid" <?= $paid_filter === 'unpaid' ? 'selected' : '' ?>>لم يتم الخصم</option>
            <option value="paid" <?= $paid_filter === 'paid' ? 'selected' : '' ?>>تم الخصم</option>
        </select>
    </form>

    <div class="table-box">

        <table class="table">
            <thead>
                <tr>
                    <th class="col-batch">رقم الطلب</th>
                    <th class="col-supplier">المورد</th>
                    <th class="col-count">عدد الأصناف</th>
                    <th class="col-fee">إجمالي الرسوم</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-user">تم بواسطة</th>
                    <th class="col-date">التاريخ</th>
                    <th class="col-view">عرض</th>
                    <th class="col-action">إجراء</th>
                </tr>
            </thead>

            <tbody>
                <?php if(!empty($rows)): ?>

                    <?php foreach($rows as $row): ?>
                        <?php
                            $isPaid = !empty($row['paid']);
                            $deductedAt = !empty($row['deducted_at'])
                                ? date("Y-m-d", strtotime($row['deducted_at']))
                                : '-';
                        ?>

                        <tr>
                            <td>
                                <span class="batch-id">#<?= e($row['batch_id']) ?></span>
                            </td>

                            <td class="supplier-name">
                                <?= e($row['supplier_name'] ?? '-') ?>
                            </td>

                            <td><?= (int)($row['items_count'] ?? 0) ?></td>

                            <td>
                                <span class="money"><?= money($row['total_fees'] ?? 0) ?></span>
                            </td>

                            <td>
                                <?php if($isPaid): ?>
                                    <span class="status status-ok">تم الخصم</span>
                                <?php else: ?>
                                    <span class="status status-no">لم يتم الخصم</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if(!empty($row['deducted_username'])): ?>
                                    <span class="user-badge"><?= e($row['deducted_username']) ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td><?= e($deductedAt) ?></td>

                            <td>
                                <a class="btn btn-view" href="view_items.php?batch=<?= urlencode((string)$row['batch_id']) ?>">
                                    عرض
                                </a>
                            </td>

                            <td>
                                <?php if(!$isPaid): ?>
                                    <form method="POST" onsubmit="return confirm('هل تم خصم الرسوم بالفعل؟')">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                        <input type="hidden" name="action" value="deduct">
                                        <input type="hidden" name="batch_id" value="<?= e($row['batch_id']) ?>">
                                        <button type="submit" class="btn btn-deduct">الخصم الآن</button>
                                    </form>
                                <?php else: ?>
                                    <span class="done-text">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="9" class="empty">لا توجد دفعات أصناف مطابقة</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    </div>

</div>

<script>
let timer;
const searchInput = document.getElementById("searchInput");
const paidFilter = document.getElementById("paidFilter");

function applyFilters(){
    document.querySelector(".filters").submit();
}

if(searchInput){
    searchInput.addEventListener("keyup", function(){
        clearTimeout(timer);
        timer = setTimeout(applyFilters, 450);
    });
}

if(paidFilter){
    paidFilter.addEventListener("change", applyFilters);
}
</script>

</body>
</html>
