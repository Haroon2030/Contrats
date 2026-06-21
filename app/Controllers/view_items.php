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

function vcColumnExists(VcDb $conn, string $table, string $column): bool {
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

function ensureItemsShadColumn(VcDb $conn): void {
    if (!vcColumnExists($conn, 'items', 'shad')) {
        @$conn->query("ALTER TABLE items ADD COLUMN shad INT NULL DEFAULT NULL AFTER name");
    }
}

function statusText(string $status): string {
    $map = [
        'review'   => 'تحت المراجعة',
        'approved' => 'تمت الموافقة',
        'rejected' => 'مرفوض',
        'draft'    => 'مسودة'
    ];

    return $map[$status] ?? 'غير معروف';
}

function statusClass(string $status): string {
    return in_array($status, ['review','approved','rejected','draft'], true) ? $status : 'draft';
}

function cleanDate($value): string {
    $value = trim((string)$value);

    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '-';
    }

    return date("Y-m-d", strtotime($value));
}


function itemNumberValue($value, float $default = 0): string {
    $value = trim((string)$value);
    if ($value === '') {
        return (string)$default;
    }
    $value = str_replace(['٬', ','], '', $value);
    $value = str_replace('٫', '.', $value);
    if (!is_numeric($value)) {
        return (string)$default;
    }
    return rtrim(rtrim(number_format((float)$value, 4, '.', ''), '0'), '.');
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

$uid = (int)($_SESSION['user_id'] ?? 0);

ensureItemsShadColumn($conn);

if ($uid <= 0) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$mode = ($_GET['mode'] ?? 'view') === 'edit' ? 'edit' : 'view';
$errors = [];
$success = '';

$batch = trim((string)($_GET['batch'] ?? ''));

if ($batch === '') {
    die("❌ رقم الطلب غير موجود");
}


$stmtUser = $conn->prepare("SELECT is_admin, role, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1");
$stmtUser->bind_param("i", $uid);
$stmtUser->execute();
$currentUser = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$is_admin = !empty($currentUser) && (
    (int)($currentUser['is_admin'] ?? 0) === 1 ||
    ($currentUser['role'] ?? '') === 'admin'
);
$currentJobRole = (string)($currentUser['job_role'] ?? 'user');
$isSectionManager = ($currentJobRole === 'section_manager' || (int)($currentUser['is_supervisor'] ?? 0) === 1);
$isCommercialManager = ($currentJobRole === 'commercial_manager');


$itemsAdminScope = getUserPageScope($conn, $uid, 'items_admin');
$underReviewItemsScope = getUserPageScope($conn, $uid, 'under_review_items');
$myItemsScope = getUserPageScope($conn, $uid, 'my_items');
$dataEntryItemsScope = getUserPageScope($conn, $uid, 'data_entry_items');

$hasManagedUsers = false;
if (function_exists('vcGetDirectChildrenIds')) {
    $hasManagedUsers = count(vcGetDirectChildrenIds($conn, $uid)) > 0;
}
$hasAnyItemsViewPermission = (
    $itemsAdminScope !== 'none' ||
    $underReviewItemsScope !== 'none' ||
    $myItemsScope !== 'none'
);

$itemPageScope = 'own';
if ($is_admin || $isCommercialManager || $itemsAdminScope === 'all' || $underReviewItemsScope === 'all' || $myItemsScope === 'all') {
    $itemPageScope = 'all';
} elseif ($itemsAdminScope === 'team' || $underReviewItemsScope === 'team' || $myItemsScope === 'team' || (($isSectionManager || $hasManagedUsers) && $hasAnyItemsViewPermission)) {
    $itemPageScope = 'team';
}
$itemScopedUserIds = vcGetScopedUserIds($conn, $uid, $itemPageScope, ($is_admin || $isCommercialManager));


$canViewDataEntryApprovedItems = ($dataEntryItemsScope !== 'none');

$canViewAllItemsByItemsPermission = ($itemPageScope === 'all');
$canViewTeamItemsByItemsPermission = ($itemPageScope === 'team');


$financePageNames = [
    'accounting',
    'finance',
    'finance_items',
    'accounting_api',
    'accounts',
    'items_accounting',
    'contracts_accounting'
];

$canViewFinanceItems = false;

foreach ($financePageNames as $financePageName) {
    if (getUserPageScope($conn, $uid, $financePageName) !== 'none') {
        $canViewFinanceItems = true;
        break;
    }
}

$canViewAll = (
    $canViewAllItemsByItemsPermission ||
    $canViewFinanceItems
);
$canViewTeam = ($canViewTeamItemsByItemsPermission && !$canViewAll);


if ($canViewAll) {
    $summary = $conn->prepare("
        SELECT 
            i.batch_id,
            i.supplier_name,
            COUNT(*) AS items_count,
            SUM(i.fee) AS total_fees,
            MAX(i.status) AS status,
            MAX(i.created_at) AS created_at,
            MAX(i.created_by) AS created_by,
            MAX(i.approved_at) AS approved_at,
            MAX(i.rejected_at) AS rejected_at,
            MAX(i.entered_by) AS entered_by,
            creator.username AS created_username,
            entry_user.username AS entered_username
        FROM items i
        LEFT JOIN users creator ON creator.id = i.created_by
        LEFT JOIN users entry_user ON entry_user.id = i.entered_by
        WHERE i.batch_id = ?
        GROUP BY i.batch_id, i.supplier_name, creator.username, entry_user.username
        LIMIT 1
    ");
    $summary->bind_param("s", $batch);
} elseif ($canViewTeam) {
    $paramsSummary = [$batch];
    $typesSummary = "s";
    $scopeWhereSummary = vcBuildInCondition('i.created_by', $itemScopedUserIds, $paramsSummary, $typesSummary);
    $summarySql = "
        SELECT 
            i.batch_id,
            i.supplier_name,
            COUNT(*) AS items_count,
            SUM(i.fee) AS total_fees,
            MAX(i.status) AS status,
            MAX(i.created_at) AS created_at,
            MAX(i.created_by) AS created_by,
            MAX(i.approved_at) AS approved_at,
            MAX(i.rejected_at) AS rejected_at,
            MAX(i.entered_by) AS entered_by,
            creator.username AS created_username,
            entry_user.username AS entered_username
        FROM items i
        LEFT JOIN users creator ON creator.id = i.created_by
        LEFT JOIN users entry_user ON entry_user.id = i.entered_by
        WHERE i.batch_id = ?
        {$scopeWhereSummary}
        GROUP BY i.batch_id, i.supplier_name, creator.username, entry_user.username
        LIMIT 1
    ";
    $summary = $conn->prepare($summarySql);
    $summary->bind_param($typesSummary, ...$paramsSummary);
} elseif ($canViewDataEntryApprovedItems) {
    $summary = $conn->prepare("
        SELECT 
            i.batch_id,
            i.supplier_name,
            COUNT(*) AS items_count,
            SUM(i.fee) AS total_fees,
            MAX(i.status) AS status,
            MAX(i.created_at) AS created_at,
            MAX(i.created_by) AS created_by,
            MAX(i.approved_at) AS approved_at,
            MAX(i.rejected_at) AS rejected_at,
            MAX(i.entered_by) AS entered_by,
            creator.username AS created_username,
            entry_user.username AS entered_username
        FROM items i
        LEFT JOIN users creator ON creator.id = i.created_by
        LEFT JOIN users entry_user ON entry_user.id = i.entered_by
        WHERE i.batch_id = ?
        AND (
            i.status = 'approved'
            OR i.entered_by = ?
        )
        GROUP BY i.batch_id, i.supplier_name, creator.username, entry_user.username
        LIMIT 1
    ");
    $summary->bind_param("si", $batch, $uid);
} else {
    $summary = $conn->prepare("
        SELECT 
            i.batch_id,
            i.supplier_name,
            COUNT(*) AS items_count,
            SUM(i.fee) AS total_fees,
            MAX(i.status) AS status,
            MAX(i.created_at) AS created_at,
            MAX(i.created_by) AS created_by,
            MAX(i.approved_at) AS approved_at,
            MAX(i.rejected_at) AS rejected_at,
            MAX(i.entered_by) AS entered_by,
            creator.username AS created_username,
            entry_user.username AS entered_username
        FROM items i
        LEFT JOIN users creator ON creator.id = i.created_by
        LEFT JOIN users entry_user ON entry_user.id = i.entered_by
        WHERE i.batch_id = ?
        AND i.created_by = ?
        GROUP BY i.batch_id, i.supplier_name, creator.username, entry_user.username
        LIMIT 1
    ");
    $summary->bind_param("si", $batch, $uid);
}

$summary->execute();
$info = $summary->get_result()->fetch_assoc();
$summary->close();

if (!$info) {
    die("❌ الطلب غير موجود أو ليس لديك صلاحية لعرضه");
}

$currentBatchStatus = (string)($info['status'] ?? '');
$isBatchOwner = ((int)($info['created_by'] ?? 0) === $uid);
$canEditAnyItems = ($is_admin || $itemsAdminScope !== 'none' || in_array($itemPageScope, ['team','all'], true));
$canEditBatch = ($currentBatchStatus === 'review' && ($isBatchOwner || $canEditAnyItems));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_batch_items') {

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'الطلب غير صالح، حدّث الصفحة وجرب تاني.';
    }

    if (!$canEditBatch) {
        $errors[] = 'لا يمكن تعديل هذا الطلب. التعديل متاح فقط وهو تحت المراجعة، أو بعد أن يرجعه الأدمن للمراجعة.';
    }

    $itemIds     = $_POST['item_id'] ?? [];
    $barcodes    = $_POST['barcode'] ?? [];
    $names       = $_POST['item_name'] ?? [];
    $shads       = $_POST['shad'] ?? [];
    $before      = $_POST['cost_before'] ?? [];
    $after       = $_POST['cost_after'] ?? [];
    $sell        = $_POST['sell_price'] ?? [];
    $profits     = $_POST['profit'] ?? [];
    $fees        = $_POST['fee'] ?? [];
    $notes       = $_POST['notes'] ?? [];

    if (empty($itemIds) || !is_array($itemIds)) {
        $errors[] = 'لا توجد أصناف للحفظ.';
    }

    if (empty($errors)) {
        $updated = 0;

        if ($canEditAnyItems) {
            $stmtUpdate = $conn->prepare("
                UPDATE items
                SET barcode = ?,
                    name = ?,
                    shad = ?,
                    cost_before = ?,
                    cost_after = ?,
                    sell_price = ?,
                    profit = ?,
                    fee = ?,
                    notes = ?
                WHERE id = ?
                AND batch_id = ?
                AND status = 'review'
                LIMIT 1
            ");
        } else {
            $stmtUpdate = $conn->prepare("
                UPDATE items
                SET barcode = ?,
                    name = ?,
                    shad = ?,
                    cost_before = ?,
                    cost_after = ?,
                    sell_price = ?,
                    profit = ?,
                    fee = ?,
                    notes = ?
                WHERE id = ?
                AND batch_id = ?
                AND created_by = ?
                AND status = 'review'
                LIMIT 1
            ");
        }

        if (!$stmtUpdate) {
            $errors[] = 'تعذر تجهيز حفظ التعديلات.';
        } else {
            foreach ($itemIds as $i => $itemIdRaw) {
                $itemId = (int)$itemIdRaw;
                if ($itemId <= 0) {
                    continue;
                }

                $barcode = trim((string)($barcodes[$i] ?? ''));
                $itemName = trim((string)($names[$i] ?? ''));
                $shadRaw = trim((string)($shads[$i] ?? ''));
                $shadRaw = preg_replace('/\D+/', '', $shadRaw);
                $shadVal = ($shadRaw === '') ? null : (int)$shadRaw;

                if ($barcode === '' && $itemName === '') {
                    continue;
                }

                $costBefore = itemNumberValue($before[$i] ?? 0);
                $costAfter  = itemNumberValue($after[$i] ?? 0);
                $sellPrice  = itemNumberValue($sell[$i] ?? 0);
                $profitVal  = itemNumberValue($profits[$i] ?? 0);
                $feeVal     = itemNumberValue($fees[$i] ?? 0);
                $noteVal    = trim((string)($notes[$i] ?? ''));

                if ((float)$costAfter <= 0 && (float)$costBefore > 0) {
                    $costAfter = itemNumberValue(((float)$costBefore) * 1.15);
                }

                if ((float)$profitVal == 0 && (float)$costAfter > 0 && (float)$sellPrice > 0) {
                    $profitVal = itemNumberValue((((float)$sellPrice - (float)$costAfter) / (float)$costAfter) * 100);
                }

                if ($canEditAnyItems) {
                    $stmtUpdate->bind_param(
                        "ssissssssis",
                        $barcode,
                        $itemName,
                        $shadVal,
                        $costBefore,
                        $costAfter,
                        $sellPrice,
                        $profitVal,
                        $feeVal,
                        $noteVal,
                        $itemId,
                        $batch
                    );
                } else {
                    $stmtUpdate->bind_param(
                        "ssissssssisi",
                        $barcode,
                        $itemName,
                        $shadVal,
                        $costBefore,
                        $costAfter,
                        $sellPrice,
                        $profitVal,
                        $feeVal,
                        $noteVal,
                        $itemId,
                        $batch,
                        $uid
                    );
                }

                $stmtUpdate->execute();
                if ($stmtUpdate->affected_rows >= 0) {
                    $updated++;
                }
            }

            $stmtUpdate->close();
        }

        if (empty($errors)) {
            $_SESSION['items_edit_success'] = 'تم حفظ تعديلات الطلب بنجاح.';
            header('Location: view_items.php?batch=' . urlencode($batch) . '&mode=edit&updated=1');
            exit();
        }
    }
}


if ($canViewAll) {
    $stmt = $conn->prepare("
        SELECT *
        FROM items
        WHERE batch_id = ?
        ORDER BY id ASC
    ");
    $stmt->bind_param("s", $batch);
} elseif ($canViewTeam) {
    $paramsItems = [$batch];
    $typesItems = "s";
    $scopeWhereItems = vcBuildInCondition('created_by', $itemScopedUserIds, $paramsItems, $typesItems);
    $itemsSql = "
        SELECT *
        FROM items
        WHERE batch_id = ?
        {$scopeWhereItems}
        ORDER BY id ASC
    ";
    $stmt = $conn->prepare($itemsSql);
    $stmt->bind_param($typesItems, ...$paramsItems);
} elseif ($canViewDataEntryApprovedItems) {
    $stmt = $conn->prepare("
        SELECT *
        FROM items
        WHERE batch_id = ?
        AND (
            status = 'approved'
            OR entered_by = ?
        )
        ORDER BY id ASC
    ");
    $stmt->bind_param("si", $batch, $uid);
} else {
    $stmt = $conn->prepare("
        SELECT *
        FROM items
        WHERE batch_id = ?
        AND created_by = ?
        ORDER BY id ASC
    ");
    $stmt->bind_param("si", $batch, $uid);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = 'items_batch_' . preg_replace('/[^0-9A-Za-z_-]/', '', (string)$batch) . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"></head><body dir="rtl">';
    echo '<h3>تفاصيل طلب الأصناف رقم #' . e($batch) . '</h3>';
    echo '<table border="1">';
    echo '<tr>';
    echo '<th>باركود</th>';
    echo '<th>اسم الصنف</th>';
    echo '<th>الشد</th>';
    echo '<th>تكلفة قبل</th>';
    echo '<th>تكلفة بعد</th>';
    echo '<th>سعر البيع</th>';
    echo '<th>الهامش %</th>';
    echo '<th>الرسوم</th>';
    echo '<th>ملاحظات</th>';
    echo '</tr>';

    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . e($row['barcode'] ?? '') . '</td>';
        echo '<td>' . e($row['name'] ?? '') . '</td>';
        echo '<td>' . e($row['shad'] ?? '') . '</td>';
        echo '<td>' . e($row['cost_before'] ?? '') . '</td>';
        echo '<td>' . e($row['cost_after'] ?? '') . '</td>';
        echo '<td>' . e($row['sell_price'] ?? '') . '</td>';
        echo '<td>' . e($row['profit'] ?? '') . '</td>';
        echo '<td>' . e($row['fee'] ?? '') . '</td>';
        echo '<td>' . e($row['notes'] ?? '') . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '</body></html>';
    exit;
}

$status = (string)($info['status'] ?? '');
$status_text = statusText($status);
$status_class = statusClass($status);
$isApproved = ($status === 'approved');

$createdAt = cleanDate($info['created_at'] ?? '');
$actionDate = '-';

if (!empty($info['approved_at'])) {
    $actionDate = cleanDate($info['approved_at']);
} elseif (!empty($info['rejected_at'])) {
    $actionDate = cleanDate($info['rejected_at']);
}

$totalFees = (float)($info['total_fees'] ?? 0);

$supplierSignatureName = trim((string)($info['supplier_name'] ?? '-'));
$entrySignatureName = trim((string)($info['entered_username'] ?? ''));
if ($entrySignatureName === '') {
    $entrySignatureName = '-';
}
$purchaseSignatureName = trim((string)($info['created_username'] ?? ''));
if ($purchaseSignatureName === '') {
    $purchaseSignatureName = '-';
}
$commercialManagerName = 'سلطان السالمى';

$hasNotes = false;
foreach ($rows as $r) {
    if (trim((string)($r['notes'] ?? '')) !== '') {
        $hasNotes = true;
        break;
    }
}

if ($mode === 'edit' && !$canEditBatch) {
    $errors[] = 'وضع التعديل غير متاح لهذا الطلب. لو الطلب معتمد لازم الأدمن يرجعه للمراجعة أولاً.';
    $mode = 'view';
}

$isEditMode = ($mode === 'edit' && $canEditBatch);

if (!empty($_SESSION['items_edit_success'])) {
    $success = $_SESSION['items_edit_success'];
    unset($_SESSION['items_edit_success']);
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>تفاصيل الأصناف</title>

<?php vcRenderPageAssets(); ?>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <?php if($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if(!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach($errors as $error): ?>
                <div>• <?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="page-head">

        <div class="title-wrap">
            <h1 class="page-title">
                <img src="uploads/vendorcore.png" class="inline-print-logo" alt="أسواق الرشيد">
                <span>📦 تفاصيل الطلب رقم #<?= e($batch) ?></span>
            </h1>

            <p class="page-subtitle">
                تفاصيل دفعة الأصناف والرسوم وحالة المراجعة.
            </p>

            <div class="head-badges">
                <span class="badge badge-batch">طلب أصناف</span>
                <span class="badge badge-status <?= e($status_class) ?>"><?= e($status_text) ?></span>
            </div>
        </div>

        <div class="head-actions">
            <button type="button" class="btn btn-print" onclick="window.print()">عرض / طباعة</button>
            <a class="btn" href="view_items.php?batch=<?= urlencode((string)$batch) ?>&export=excel">تصدير Excel</a>


            <a href="javascript:history.back()" class="btn btn-muted">رجوع</a>
        </div>

    </div>

    <div class="summary-grid">

        <div class="summary-card">
            <div class="summary-label">المورد</div>
            <div class="summary-value"><?= e($info['supplier_name'] ?? '-') ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">عدد الأصناف</div>
            <div class="summary-value"><?= (int)($info['items_count'] ?? 0) ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">إجمالي الرسوم</div>
            <div class="summary-value money"><?= money($totalFees) ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">تاريخ الطلب</div>
            <div class="summary-value"><?= e($createdAt) ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">تاريخ الإجراء</div>
            <div class="summary-value"><?= e($actionDate) ?></div>
        </div>

    </div>

    <div class="table-box">
        <div class="section-title">
            <?= $isEditMode ? 'تعديل الأصناف داخل الطلب' : 'الأصناف داخل الطلب' ?>
        </div>

        <?php if($isEditMode): ?>
            <form method="POST" id="editItemsForm">
                <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                <input type="hidden" name="action" value="update_batch_items">
        <?php endif; ?>

        <table class="table">
            <thead>
                <tr>
                    <th class="col-barcode">باركود</th>
                    <th class="col-name">الاسم</th>
                    <th class="col-shad">الشد</th>
                    <th class="col-cost">تكلفة قبل</th>
                    <th class="col-cost">تكلفة بعد</th>
                    <th class="col-sell">سعر البيع</th>
                    <th class="col-profit">الهامش</th>
                    <th class="col-fee">الرسوم</th>
                    <?php if($hasNotes || $isEditMode): ?>
                    <th class="col-notes">ملاحظات</th>
                    <?php endif; ?>
                </tr>
            </thead>

            <tbody>
                <?php if(!empty($rows)): ?>

                    <?php foreach($rows as $row): ?>
                        <tr>
                            <td>
                                <?php if($isEditMode): ?>
                                    <input type="hidden" name="item_id[]" value="<?= (int)$row['id'] ?>">
                                    <input class="edit-input" name="barcode[]" value="<?= e($row['barcode'] ?? '') ?>">
                                <?php else: ?>
                                    <span class="barcode"><?= e($row['barcode'] ?? '-') ?></span>
                                <?php endif; ?>
                            </td>

                            <td class="item-name">
                                <?php if($isEditMode): ?>
                                    <input class="edit-input name-input" name="item_name[]" value="<?= e($row['name'] ?? '') ?>">
                                <?php else: ?>
                                    <?= e($row['name'] ?? '-') ?>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($isEditMode): ?>
                                    <input class="edit-input" name="shad[]" type="number" step="1" min="0" value="<?= e($row['shad'] ?? '') ?>">
                                <?php else: ?>
                                    <?= trim((string)($row['shad'] ?? '')) !== '' ? e($row['shad']) : '-' ?>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($isEditMode): ?>
                                    <input class="edit-input cost-before" name="cost_before[]" type="number" step="0.01" value="<?= e($row['cost_before'] ?? '0') ?>" oninput="calcEditRow(this)">
                                <?php else: ?>
                                    <span class="money-cell"><?= money($row['cost_before'] ?? 0) ?></span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($isEditMode): ?>
                                    <input class="edit-input cost-after" name="cost_after[]" type="number" step="0.01" value="<?= e($row['cost_after'] ?? '0') ?>" oninput="calcEditRow(this)">
                                <?php else: ?>
                                    <span class="money-cell"><?= money($row['cost_after'] ?? 0) ?></span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($isEditMode): ?>
                                    <input class="edit-input sell-price" name="sell_price[]" type="number" step="0.01" value="<?= e($row['sell_price'] ?? '0') ?>" oninput="calcEditRow(this)">
                                <?php else: ?>
                                    <span class="money-cell"><?= money($row['sell_price'] ?? 0) ?></span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($isEditMode): ?>
                                    <input class="edit-input profit-input" name="profit[]" type="number" step="0.01" value="<?= e($row['profit'] ?? '0') ?>">
                                <?php else: ?>
                                    <span class="profit"><?= e($row['profit'] ?? 0) ?>%</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($isEditMode): ?>
                                    <input class="edit-input" name="fee[]" type="number" step="0.01" value="<?= e($row['fee'] ?? '0') ?>">
                                <?php else: ?>
                                    <span class="money-cell"><?= money($row['fee'] ?? 0) ?></span>
                                <?php endif; ?>
                            </td>

                            <?php if($hasNotes || $isEditMode): ?>
                            <td class="note">
                                <?php if($isEditMode): ?>
                                    <input class="edit-input notes-input" name="notes[]" value="<?= e($row['notes'] ?? '') ?>">
                                <?php else: ?>
                                    <?= trim((string)($row['notes'] ?? '')) !== '' ? e($row['notes']) : '-' ?>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="<?= ($hasNotes || $isEditMode) ? 9 : 8 ?>" class="empty">لا توجد أصناف داخل هذا الطلب</td>
                    </tr>

                <?php endif; ?>
            </tbody>
        </table>

        <?php if($isEditMode): ?>
                <div class="edit-actions-bar">
                    <button type="submit" class="btn btn-save" onclick="return confirm('حفظ تعديلات الأصناف؟')">حفظ التعديلات</button>
                    <a href="view_items.php?batch=<?= urlencode((string)$batch) ?>&mode=view" class="btn btn-muted">إلغاء والعودة للعرض</a>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <?php if($isApproved): ?>
        <div class="signatures-box">
            <div class="signatures-title">اعتماد وتوقيعات الطلب</div>

            <div class="signatures-grid">
                <div class="signature-card">
                    <div class="signature-role">توقيع المورد</div>
                    <div class="signature-line"></div>
                    <div class="signature-name"><?= e($supplierSignatureName) ?></div>
                </div>

                <div class="signature-card">
                    <div class="signature-role">توقيع مدخل البيانات</div>
                    <div class="signature-line"></div>
                    <div class="signature-name"><?= e($entrySignatureName) ?></div>
                </div>

                <div class="signature-card">
                    <div class="signature-role">توقيع المشتريات</div>
                    <div class="signature-line"></div>
                    <div class="signature-name"><?= e($purchaseSignatureName) ?></div>
                </div>

                <div class="signature-card">
                    <div class="signature-role">توقيع المدير التجاري</div>
                    <div class="signature-line"></div>
                    <div class="signature-name"><?= e($commercialManagerName) ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>


<?php if($isEditMode): ?>
<script>
function calcEditRow(el){
    const row = el.closest('tr');
    if(!row) return;

    const beforeInput = row.querySelector('.cost-before');
    const afterInput  = row.querySelector('.cost-after');
    const sellInput   = row.querySelector('.sell-price');
    const profitInput = row.querySelector('.profit-input');

    const before = parseFloat(beforeInput ? beforeInput.value : 0) || 0;
    let after = parseFloat(afterInput ? afterInput.value : 0) || 0;
    const sell = parseFloat(sellInput ? sellInput.value : 0) || 0;

    if(el === beforeInput && before > 0 && afterInput){
        after = before * 1.15;
        afterInput.value = after.toFixed(2);
    }

    if(profitInput && after > 0 && sell > 0){
        const profit = ((sell - after) / after) * 100;
        profitInput.value = profit.toFixed(2);
    }
}
</script>
<?php endif; ?>

</body>
</html>
