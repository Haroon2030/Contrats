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
$can_delete_admin = vcUserCanDeleteAsAdmin($currentUser);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_contract') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die('طلب غير صالح');
    }
    if (!$can_delete_admin) {
        http_response_code(403);
        die('❌ ليس لديك صلاحية حذف العقود');
    }

    $contract_id = (int)($_POST['contract_id'] ?? 0);

    if ($contract_id > 0 && vcHardDeleteContractsByIds($conn, [$contract_id])) {
        $_SESSION['my_contracts_delete_msg'] = 'تم حذف العقد رقم #' . $contract_id . ' بنجاح.';
    } else {
        $_SESSION['my_contracts_delete_msg'] = 'تعذر حذف العقد.';
    }

    header('Location: my_contracts.php');
    exit();
}

$deleteMsg = $_SESSION['my_contracts_delete_msg'] ?? '';
unset($_SESSION['my_contracts_delete_msg']);

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

$fromWhere = "
    FROM contracts
    LEFT JOIN users ON users.id = contracts.created_by
    WHERE contracts.status IN ('approved','rejected')
";

$params = [];
$types  = "";

if (!$canViewAllMyContracts && !empty($scopedUserIds)) {
    $fromWhere .= vcBuildInCondition('contracts.created_by', $scopedUserIds, $params, $types);
}

if ($user_filter !== '') {
    $fromWhere .= " AND contracts.created_by = ?";
    $params[] = (int)$user_filter;
    $types .= "i";
}

if ($contract_type === 'rent') {
    $fromWhere .= " AND contracts.source = 'rent'";
} elseif ($contract_type === 'annual') {
    $fromWhere .= " AND (contracts.source IS NULL OR contracts.source <> 'rent')";
}

if ($status !== '') {
    $fromWhere .= " AND contracts.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($search !== '') {
    $fromWhere .= " AND (
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

$pg = vcPaginationState();
$totalRows = vcPaginationCount($conn, $fromWhere, $params, $types);
$totalPages = vcPaginationTotalPages($totalRows, $pg['per_page']);
$page = min($pg['page'], $totalPages);

$sql = "
    SELECT $signedSelect, users.username AS created_username
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

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>عقودي</title>

<?php vcRenderPageAssets(['extra' => ['vc-contracts-list.css']]); ?>
</head>

<body class="contracts-list-page">

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">📄 عقودي</h1>
        <p class="page-subtitle">
            متابعة العقود التي تم اتخاذ إجراء عليها: الموافقة أو الرفض.
        </p>
    </div>

    <?php if($deleteMsg !== ''): ?>
        <div class="alert alert-success"><?= e($deleteMsg) ?></div>
    <?php endif; ?>

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

        <table class="table contracts-table <?= $showUserColumn ? 'table-team' : 'table-own' ?>">
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
                    <th class="col-actions">إجراءات</th>
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
                                <?php
                                vcRenderRowActions([
                                    'view' => [
                                        'href' => 'view_contract.php?id=' . (int)$row['id'],
                                    ],
                                    'delete' => [
                                        'action' => 'delete_contract',
                                        'fields' => ['contract_id' => (string)(int)$row['id']],
                                        'confirm' => 'تأكيد حذف العقد رقم #' . (int)$row['id'] . '؟',
                                    ],
                                ], $csrf_token, $can_delete_admin);
                                ?>
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

    <?php vcRenderPagination($page, $totalPages); ?>

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

    url.searchParams.delete("pg");

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
