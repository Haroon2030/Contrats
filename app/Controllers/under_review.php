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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_review_contract') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }
    if (!$is_admin) {
        http_response_code(403);
        die("❌ ليس لديك صلاحية حذف العقود");
    }

    $contract_id = (int)($_POST['contract_id'] ?? 0);

    if ($contract_id > 0) {
        $stmtDelete = $conn->prepare("
            UPDATE contracts
            SET status = 'deleted'
            WHERE id = ? AND status = 'review'
            LIMIT 1
        ");
        if ($stmtDelete) {
            $stmtDelete->bind_param("i", $contract_id);
            $stmtDelete->execute();
            $stmtDelete->close();
        }
    }

    header("Location: under_review.php");
    exit();
}

$users_for_filter = [];
if ($showUserColumn) {
    $users_for_filter = vcGetVisibleUsersForFilter($conn, $scopedUserIds);
}


$fromWhere = "
    FROM contracts
    LEFT JOIN users ON users.id = contracts.created_by
    WHERE contracts.status = 'review'
";

$params = [];
$types  = "";

if ($hasLimitedScope) {
    if (empty($scopedUserIds)) {
        $fromWhere .= " AND 1 = 0";
    } else {
        $fromWhere .= vcBuildInCondition('contracts.created_by', $scopedUserIds, $params, $types, 'AND');
    }
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

if ($search !== '') {
    $fromWhere .= " AND (
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

$pg = vcPaginationState();
$totalReview = vcPaginationCount($conn, $fromWhere, $params, $types);
$totalPages = vcPaginationTotalPages($totalReview, $pg['per_page']);
$page = min($pg['page'], $totalPages);

$sql = "
    SELECT contracts.*, users.username AS created_username
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

<?php vcRenderPageAssets(['extra' => ['vc-contracts-list.css']]); ?>
</head>

<body class="contracts-list-page <?= $showUserColumn ? 'wide-table-mode' : 'normal-table-mode' ?>">

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

        <table class="table contracts-table <?= $showUserColumn ? 'table-team' : 'table-own' ?>">
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
                                <?php
                                vcRenderRowActions([
                                    'view' => [
                                        'href' => 'view_contract.php?id=' . (int)$row['id'],
                                    ],
                                    'edit' => [
                                        'href' => vcContractEditUrl($row),
                                    ],
                                    'delete' => [
                                        'action' => 'delete_review_contract',
                                        'fields' => ['contract_id' => (string)(int)$row['id']],
                                        'confirm' => 'تأكيد حذف العقد رقم #' . (int)$row['id'] . '؟',
                                    ],
                                ], $csrf_token, $is_admin);
                                ?>
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

    <?php vcRenderPagination($page, $totalPages); ?>

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
