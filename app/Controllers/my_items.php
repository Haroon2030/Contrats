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
    return vcTableExists($conn, $table);
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

<?php vcRenderPageAssets(); ?>
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
                    <th class="col-actions">إجراءات</th>
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
                                <?php
                                $myItemActions = [
                                    'view' => [
                                        'href' => 'view_items.php?batch=' . urlencode((string)$row['batch_id']),
                                    ],
                                    'delete' => [
                                        'action' => 'bulk_delete_item_batches',
                                        'fields' => ['batch_ids[]' => (string)$row['batch_id']],
                                        'confirm' => 'تأكيد حذف دفعة الأصناف رقم ' . (string)$row['batch_id'] . '؟',
                                    ],
                                ];

                                if ($status === 'review') {
                                    $myItemActions['edit'] = [
                                        'href' => 'add_items.php?edit_batch=' . urlencode((string)$row['batch_id']),
                                    ];
                                }

                                vcRenderRowActions($myItemActions, $csrf_token, $is_admin);
                                ?>
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
