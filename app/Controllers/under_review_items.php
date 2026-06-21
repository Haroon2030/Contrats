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


$stmtUser = $conn->prepare("SELECT is_admin, role, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1");
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$currentUser = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$is_admin = !empty($currentUser) && (
    (int)($currentUser['is_admin'] ?? 0) === 1 ||
    ($currentUser['role'] ?? '') === 'admin'
);

$pageScope = getUserPageScope($conn, $user_id, 'under_review_items');
$pageScope = in_array($pageScope, ['own','team','all'], true) ? $pageScope : 'own';
$scopedUserIds = vcGetScopedUserIds($conn, $user_id, $pageScope, $is_admin);
$canViewAllItems = empty($scopedUserIds);
$showUserColumn = ($is_admin || in_array($pageScope, ['team','all'], true));


$search = trim($_GET['search'] ?? '');
$user_filter = trim($_GET['user'] ?? '');

if (!$showUserColumn || ($user_filter !== '' && !vcIsUserInScope((int)$user_filter, $scopedUserIds))) {
    $user_filter = '';
}

$users_result = $showUserColumn ? vcGetVisibleUsersForFilter($conn, $scopedUserIds) : [];


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_review_item_batch') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    $deleteBatch = trim((string)($_POST['batch_id'] ?? ''));

    if ($deleteBatch !== '') {
        $paramsDelete = [$deleteBatch];
        $typesDelete = "s";

        $deleteScope = vcBuildInCondition('created_by', $scopedUserIds, $paramsDelete, $typesDelete);

        $sqlDelete = "
            DELETE FROM items
            WHERE batch_id = ?
              AND status = 'review'
              {$deleteScope}
        ";

        $stmtDelete = $conn->prepare($sqlDelete);

        if ($stmtDelete) {
            $stmtDelete->bind_param($typesDelete, ...$paramsDelete);
            $stmtDelete->execute();
            $deletedCount = $stmtDelete->affected_rows;
            $stmtDelete->close();

            $_SESSION['under_review_items_msg'] = $deletedCount > 0
                ? 'تم حذف طلب الأصناف رقم ' . $deleteBatch . ' بنجاح.'
                : 'لم يتم حذف الطلب، ربما ليس تحت المراجعة أو ليس ضمن صلاحيتك.';
        } else {
            $_SESSION['under_review_items_msg'] = 'تعذر تجهيز حذف الطلب.';
        }
    }

    header("Location: under_review_items.php");
    exit();
}

$pageMsg = $_SESSION['under_review_items_msg'] ?? '';
unset($_SESSION['under_review_items_msg']);


$sql = "
    SELECT 
        items.batch_id,
        items.supplier_name,
        COUNT(*) AS items_count,
        SUM(items.fee) AS total_fees,
        MAX(items.id) AS last_id,
        MAX(items.created_at) AS created_at,
        MAX(items.created_by) AS created_by,
        users.username AS created_username
    FROM items
    LEFT JOIN users ON users.id = items.created_by
    WHERE items.status = 'review'
";

$params = [];
$types  = "";

$sql .= vcBuildInCondition('items.created_by', $scopedUserIds, $params, $types);
if ($user_filter !== '') {
    $sql .= " AND items.created_by = ?";
    $params[] = (int)$user_filter;
    $types .= "i";
}

if ($search !== '') {
    $sql .= " AND (
        items.supplier_name LIKE ?
        OR items.batch_id LIKE ?
        OR users.username LIKE ?
    )";

    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

$sql .= "
    GROUP BY items.batch_id, items.supplier_name, users.username
    ORDER BY last_id DESC
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
$totalBatches = 0;
$totalItems = 0;
$totalFees = 0;

while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
    $totalBatches++;
    $totalItems += (int)($row['items_count'] ?? 0);
    $totalFees += (float)($row['total_fees'] ?? 0);
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>الأصناف تحت المراجعة</title>

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
    grid-template-columns:repeat(3, 1fr);
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
    grid-template-columns:1fr 190px;
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

.col-batch{width:130px;}
.col-supplier{width:27%;}
.col-user{width:120px;}
.col-count{width:115px;}
.col-fee{width:140px;}
.col-created{width:135px;}
.col-status{width:145px;}
.col-view{width:230px;}

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

.row-actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    flex-wrap:nowrap;
}

.row-actions form{
    margin:0;
}

.btn-view{
    background:#6d4aff;
}

.btn-edit{
    background:#16a34a;
}

.btn-delete{
    background:#ef4444;
}

.empty{
    padding:26px !important;
    text-align:center;
    color:#667085;
    font-weight:900;
}

.alert-info-box{
    background:#ecfdf5;
    color:#166534;
    border:1px solid #bbf7d0;
    border-radius:16px;
    padding:12px 14px;
    margin-bottom:15px;
    font-weight:900;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}

@media(max-width:1050px){
    .summary-grid{
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
}
</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">📦 الأصناف تحت المراجعة</h1>
        <p class="page-subtitle">
            دفعات الأصناف التي أرسلتها للإدارة ولم يتم اتخاذ قرار عليها بعد.
        </p>
    </div>

    <?php if(!empty($pageMsg)): ?>
        <div class="alert-info-box">
            <?= e($pageMsg) ?>
        </div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-value"><?= (int)$totalBatches ?></div>
            <div class="summary-label">دفعات تحت المراجعة</div>
        </div>

        <div class="summary-card">
            <div class="summary-value"><?= (int)$totalItems ?></div>
            <div class="summary-label">عدد الأصناف</div>
        </div>

        <div class="summary-card">
            <div class="summary-value"><?= money($totalFees) ?></div>
            <div class="summary-label">إجمالي الرسوم</div>
        </div>
    </div>

    <div class="search-box">
        <input type="text"
               id="searchInput"
               placeholder="🔍 بحث باسم المورد أو رقم الطلب أو المستخدم..."
               value="<?= e($search) ?>">

        <?php if($showUserColumn): ?>
            <select id="userFilter">
                <option value="">كل الفريق</option>
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
                    <th class="col-batch">رقم الطلب</th>
                    <th class="col-supplier">المورد</th>
                    <?php if($canViewAllItems): ?>
                        <th class="col-user">بواسطة</th>
                    <?php endif; ?>
                    <th class="col-count">عدد الأصناف</th>
                    <th class="col-fee">إجمالي الرسوم</th>
                    <th class="col-created">تاريخ الطلب</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-view">عرض</th>
                </tr>
            </thead>

            <tbody>

                <?php if(!empty($rows)): ?>

                    <?php foreach($rows as $row): ?>
                        <?php
                        $createdAt = !empty($row['created_at'])
                            ? date("Y-m-d", strtotime($row['created_at']))
                            : '-';
                        ?>

                        <tr>
                            <td>
                                <span class="batch-id">#<?= e($row['batch_id']) ?></span>
                            </td>

                            <td class="supplier-name">
                                <?= e($row['supplier_name'] ?? '-') ?>
                            </td>

                            <?php if($canViewAllItems): ?>
                                <td>
                                    <span class="user-badge"><?= e($row['created_username'] ?? '-') ?></span>
                                </td>
                            <?php endif; ?>

                            <td>
                                <?= (int)($row['items_count'] ?? 0) ?>
                            </td>

                            <td>
                                <span class="money"><?= money($row['total_fees'] ?? 0) ?></span>
                            </td>

                            <td>
                                <?= e($createdAt) ?>
                            </td>

                            <td>
                                <span class="status">تحت المراجعة</span>
                            </td>

                            <td>
                                <div class="row-actions">
                                    <a href="view_items.php?batch=<?= urlencode((string)$row['batch_id']) ?>&mode=view" class="btn btn-view">
                                        عرض / طباعة
                                    </a>

                                    <a href="add_items.php?edit_batch=<?= urlencode((string)$row['batch_id']) ?>" class="btn btn-edit">
                                        تعديل
                                    </a>

                                    <form method="POST" onsubmit="return confirm('تأكيد حذف طلب الأصناف رقم <?= e($row['batch_id']) ?>؟');">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                        <input type="hidden" name="action" value="delete_review_item_batch">
                                        <input type="hidden" name="batch_id" value="<?= e($row['batch_id']) ?>">
                                        <button type="submit" class="btn btn-delete">حذف</button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="<?= $canViewAllItems ? 8 : 7 ?>" class="empty">لا توجد طلبات أصناف تحت المراجعة</td>
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
