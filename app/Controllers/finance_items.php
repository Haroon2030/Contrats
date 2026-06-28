<?php
require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';


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

function vcDisabledUserHook(VcDb $conn, int $userId, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0): void {
    return;
}

$stmtUser = $conn->prepare("SELECT is_admin, role FROM users WHERE id = ? LIMIT 1");
$stmtUser->bind_param("i", $uid);
$stmtUser->execute();
$currentUser = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$is_admin = !empty($currentUser) && (
    (int)($currentUser['is_admin'] ?? 0) === 1 ||
    ($currentUser['role'] ?? '') === 'admin'
);

$financeScope = getUserPageScope($conn, $uid, 'finance_items');
$financeScopedIds = vcGetScopedUserIds($conn, $uid, $financeScope, $is_admin);


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

        if (!empty($deductInfo) && !empty($financeScopedIds)) {
            $ownerId = (int) ($deductInfo['created_by'] ?? 0);
            if (!vcIsUserInScope($ownerId, $financeScopedIds)) {
                http_response_code(403);
                die('غير مصرح لخصم هذه الدفعة');
            }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_items_batch') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }
    if (!$is_admin) {
        http_response_code(403);
        die("❌ ليس لديك صلاحية حذف دفعات الأصناف");
    }

    $batch = trim((string)($_POST['batch_id'] ?? ''));

    if ($batch !== '') {
        $stmtItems = $conn->prepare("DELETE FROM items WHERE batch_id = ?");
        if ($stmtItems) {
            $stmtItems->bind_param("s", $batch);
            $stmtItems->execute();
            $stmtItems->close();
        }
    }

    header("Location: finance_items.php");
    exit();
}


$search = trim($_GET['search'] ?? '');
$paid_filter = trim($_GET['paid'] ?? '');

if (!in_array($paid_filter, ['', 'paid', 'unpaid'], true)) {
    $paid_filter = '';
}


$fromWhere = "
    FROM items i
    LEFT JOIN users u ON u.id = i.deducted_by
    WHERE i.status = 'approved'
";

$params = [];
$types = "";

if ($search !== '') {
    $fromWhere .= " AND (
        i.supplier_name LIKE ?
        OR i.batch_id LIKE ?
    )";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

$groupBase = "
    SELECT i.batch_id
    {$fromWhere}
    GROUP BY 
        i.batch_id,
        i.supplier_name,
        u.username
";

$paidInner = $groupBase . " HAVING MAX(i.paid) = 1";
$unpaidInner = $groupBase . " HAVING MAX(i.paid) IS NULL OR MAX(i.paid) = 0";

$paidCount = vcPaginationCountGrouped($conn, $paidInner, $params, $types);
$unpaidCount = vcPaginationCountGrouped($conn, $unpaidInner, $params, $types);
$totalBatches = $paidCount + $unpaidCount;

$havingSql = '';
if ($paid_filter === 'paid') {
    $havingSql = "HAVING MAX(i.paid) = 1";
}

if ($paid_filter === 'unpaid') {
    $havingSql = "HAVING MAX(i.paid) IS NULL OR MAX(i.paid) = 0";
}

$summarySql = "
    SELECT COALESCE(SUM(t.total_fees), 0) AS total_fees
    FROM (
        SELECT COALESCE(SUM(i.fee), 0) AS total_fees
        {$fromWhere}
        GROUP BY 
            i.batch_id,
            i.supplier_name,
            u.username
        {$havingSql}
    ) t
";

$stmtSummary = $conn->prepare($summarySql);
if (!empty($params)) {
    $stmtSummary->bind_param($types, ...$params);
}
$stmtSummary->execute();
$summaryRow = $stmtSummary->get_result()->fetch_assoc() ?: [];
$stmtSummary->close();
$totalFees = (float)($summaryRow['total_fees'] ?? 0);

$totalRows = $totalBatches;
if ($paid_filter === 'paid') {
    $totalRows = $paidCount;
} elseif ($paid_filter === 'unpaid') {
    $totalRows = $unpaidCount;
}

$pg = vcPaginationState();
$totalPages = vcPaginationTotalPages($totalRows, $pg['per_page']);
$page = min($pg['page'], $totalPages);

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
    {$fromWhere}
    GROUP BY 
        i.batch_id,
        i.supplier_name,
        u.username
    {$havingSql}
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

<title>المالية - رسوم الأصناف</title>

<?php vcRenderPageAssets(); ?>
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
                    <th class="col-actions">إجراءات</th>
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

    <?php vcRenderPagination($page, $totalPages); ?>

</div>

<script>
let timer;
const searchInput = document.getElementById("searchInput");
const paidFilter = document.getElementById("paidFilter");

function applyFilters(){
    let url = new URL(window.location.href);
    const search = document.getElementById("searchInput") ? document.getElementById("searchInput").value : "";
    const paid = document.getElementById("paidFilter") ? document.getElementById("paidFilter").value : "";

    if(search){
        url.searchParams.set("search", search);
    }else{
        url.searchParams.delete("search");
    }

    if(paid){
        url.searchParams.set("paid", paid);
    }else{
        url.searchParams.delete("paid");
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

if(paidFilter){
    paidFilter.addEventListener("change", applyFilters);
}
</script>

</body>
</html>
