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

    if (!$is_admin) {
        http_response_code(403);
        die("❌ ليس لديك صلاحية حذف طلبات الأصناف");
    }

    $deleteBatch = trim((string)($_POST['batch_id'] ?? ''));

    if ($deleteBatch !== '') {
        $stmtDelete = $conn->prepare("
            DELETE FROM items
            WHERE batch_id = ?
              AND status = 'review'
        ");

        if ($stmtDelete) {
            $stmtDelete->bind_param("s", $deleteBatch);
            $stmtDelete->execute();
            $deletedCount = $stmtDelete->affected_rows;
            $stmtDelete->close();

            $_SESSION['under_review_items_msg'] = $deletedCount > 0
                ? 'تم حذف طلب الأصناف رقم ' . $deleteBatch . ' بنجاح.'
                : 'لم يتم حذف الطلب، ربما ليس تحت المراجعة.';
        } else {
            $_SESSION['under_review_items_msg'] = 'تعذر تجهيز حذف الطلب.';
        }
    }

    header("Location: under_review_items.php");
    exit();
}

$pageMsg = $_SESSION['under_review_items_msg'] ?? '';
unset($_SESSION['under_review_items_msg']);


$fromWhere = "
    FROM items
    LEFT JOIN users ON users.id = items.created_by
    WHERE items.status = 'review'
";

$params = [];
$types  = "";

$fromWhere .= vcBuildInCondition('items.created_by', $scopedUserIds, $params, $types);
if ($user_filter !== '') {
    $fromWhere .= " AND items.created_by = ?";
    $params[] = (int)$user_filter;
    $types .= "i";
}

if ($search !== '') {
    $fromWhere .= " AND (
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

$summarySql = "
    SELECT
        COUNT(DISTINCT items.batch_id) AS total_batches,
        COUNT(*) AS total_items,
        COALESCE(SUM(items.fee), 0) AS total_fees
    {$fromWhere}
";

$stmtSummary = $conn->prepare($summarySql);
if (!empty($params)) {
    $stmtSummary->bind_param($types, ...$params);
}
$stmtSummary->execute();
$summary = $stmtSummary->get_result()->fetch_assoc() ?: [];
$stmtSummary->close();

$totalBatches = (int)($summary['total_batches'] ?? 0);
$totalItems = (int)($summary['total_items'] ?? 0);
$totalFees = (float)($summary['total_fees'] ?? 0);

$groupCountSql = "
    SELECT items.batch_id
    {$fromWhere}
    GROUP BY items.batch_id, items.supplier_name, users.username
";

$pg = vcPaginationState();
$totalRows = vcPaginationCountGrouped($conn, $groupCountSql, $params, $types);
$totalPages = vcPaginationTotalPages($totalRows, $pg['per_page']);
$page = min($pg['page'], $totalPages);

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
    {$fromWhere}
    GROUP BY items.batch_id, items.supplier_name, users.username
    ORDER BY last_id DESC
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

<title>الأصناف تحت المراجعة</title>

<?php vcRenderPageAssets(); ?>
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
                    <?php if($showUserColumn): ?>
                        <th class="col-user">بواسطة</th>
                    <?php endif; ?>
                    <th class="col-count">عدد الأصناف</th>
                    <th class="col-fee">إجمالي الرسوم</th>
                    <th class="col-created">تاريخ الطلب</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-actions">إجراءات</th>
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

                            <?php if($showUserColumn): ?>
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
                                <?php
                                vcRenderRowActions([
                                    'view' => [
                                        'href' => 'view_items.php?batch=' . urlencode((string)$row['batch_id']) . '&mode=view',
                                        'label' => 'عرض / طباعة',
                                    ],
                                    'edit' => [
                                        'href' => 'add_items.php?edit_batch=' . urlencode((string)$row['batch_id']),
                                    ],
                                    'delete' => [
                                        'action' => 'delete_review_item_batch',
                                        'fields' => ['batch_id' => (string)$row['batch_id']],
                                        'confirm' => 'تأكيد حذف طلب الأصناف رقم ' . (string)$row['batch_id'] . '؟',
                                    ],
                                ], $csrf_token, $is_admin);
                                ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="<?= $showUserColumn ? 8 : 7 ?>" class="empty">لا توجد طلبات أصناف تحت المراجعة</td>
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
</script>

</body>
</html>
