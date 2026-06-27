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

function vcDisabledUserHook(VcDb $conn, int $userId, string $title, string $message, string $link = '', string $type = 'items', int $relatedId = 0): void {
    return;
}


function notifyDataEntryUsersForItemsBatch(VcDb $conn, string $batch, array $batchInfo, int $excludeUserId = 0, string $approvedByName = ''): void {
    return;
}

function ensureApprovalWithdrawalsTable(VcDb $conn): void {
    $conn->query("
        CREATE TABLE IF NOT EXISTS approval_withdrawals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            target_type VARCHAR(30) NOT NULL,
            target_id VARCHAR(80) NOT NULL,
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NULL,
            action_type VARCHAR(50) NOT NULL,
            reason TEXT NULL,
            withdrawn_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_target (target_type, target_id),
            INDEX idx_withdrawn_by (withdrawn_by),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function logApprovalWithdrawal(VcDb $conn, string $targetType, string $targetId, string $oldStatus, string $newStatus, string $actionType, string $reason, int $adminId): void {
    ensureApprovalWithdrawalsTable($conn);
    $stmt = $conn->prepare("
        INSERT INTO approval_withdrawals
            (target_type, target_id, old_status, new_status, action_type, reason, withdrawn_by, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) return;
    $stmt->bind_param("ssssssi", $targetType, $targetId, $oldStatus, $newStatus, $actionType, $reason, $adminId);
    $stmt->execute();
    $stmt->close();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$uid = (int)($_SESSION['user_id'] ?? 0);

if ($uid <= 0) {
    header("Location: login.php");
    exit();
}


header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");


if (!vcColumnExists($conn, 'items', 'entry_done')) {
    $conn->query("ALTER TABLE items ADD COLUMN entry_done TINYINT(1) NOT NULL DEFAULT 0");
}

if (!vcColumnExists($conn, 'items', 'entered_by')) {
    $conn->query("ALTER TABLE items ADD COLUMN entered_by INT NULL");
}

if (!vcColumnExists($conn, 'items', 'entered_at')) {
    $conn->query("ALTER TABLE items ADD COLUMN entered_at DATETIME NULL");
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

ensureApprovalWithdrawalsTable($conn);


$stmt = $conn->prepare("SELECT is_admin, username, role, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

$is_admin = !empty($currentUser) && (
    (int)($currentUser['is_admin'] ?? 0) === 1 ||
    ($currentUser['role'] ?? '') === 'admin'
);


$itemsAdminScope = getUserPageScope($conn, $uid, 'items_admin');
$reviewItemsScope = getUserPageScope($conn, $uid, 'review_items');
$underReviewItemsScope = getUserPageScope($conn, $uid, 'under_review_items');
$currentJobRole = (string)($currentUser['job_role'] ?? 'user');
$isSectionManager = ($currentJobRole === 'section_manager' || (int)($currentUser['is_supervisor'] ?? 0) === 1);
$isCommercialManager = ($currentJobRole === 'commercial_manager');


$hasManagedUsers = false;
if (function_exists('vcGetDirectChildrenIds')) {
    $hasManagedUsers = count(vcGetDirectChildrenIds($conn, $uid)) > 0;
}
$hasAnyItemsReviewPermission = (
    $itemsAdminScope !== 'none' ||
    $reviewItemsScope !== 'none' ||
    $underReviewItemsScope !== 'none'
);

$canAccessItemsAdmin = (
    $is_admin ||
    $isCommercialManager ||
    $hasAnyItemsReviewPermission
);

$itemPageScope = 'own';
if ($is_admin || $isCommercialManager || $itemsAdminScope === 'all' || $reviewItemsScope === 'all' || $underReviewItemsScope === 'all') {
    $itemPageScope = 'all';
} elseif ($itemsAdminScope === 'team' || $reviewItemsScope === 'team' || $underReviewItemsScope === 'team' || (($isSectionManager || $hasManagedUsers) && $hasAnyItemsReviewPermission)) {
    $itemPageScope = 'team';
}
$itemScopedUserIds = vcGetScopedUserIds($conn, $uid, $itemPageScope, ($is_admin || $isCommercialManager));
$showUserFilter = ($is_admin || $isCommercialManager || in_array($itemPageScope, ['team','all'], true));


if (!$canAccessItemsAdmin) {
    die("❌ ليس لديك صلاحية");
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete_items_batches') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    if (!$is_admin) {
        http_response_code(403);
        die("❌ ليس لديك صلاحية حذف دفعات الأصناف");
    }

    $batches = $_POST['batch_ids'] ?? [];

    if (!is_array($batches)) {
        $batches = [];
    }

    $cleanBatches = [];

    foreach ($batches as $batchId) {
        $batchId = trim((string)$batchId);

        if ($batchId !== '' && mb_strlen($batchId, 'UTF-8') <= 80) {
            $cleanBatches[] = $batchId;
        }
    }

    $cleanBatches = array_values(array_unique($cleanBatches));

    if (!empty($cleanBatches)) {
        $placeholders = implode(',', array_fill(0, count($cleanBatches), '?'));
        $bindTypes = str_repeat('s', count($cleanBatches));

        $conn->begin_transaction();

        try {
            if (vcColumnExists($conn, 'approval_withdrawals', 'target_type') && vcColumnExists($conn, 'approval_withdrawals', 'target_id')) {
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
            $deletedRows = $stmtItems->affected_rows;
            $stmtItems->close();

            $conn->commit();

            $_SESSION['items_bulk_delete_msg'] = "تم إرسال " . count($cleanBatches) . " دفعة للحذف، وتم حذف " . (int)$deletedRows . " صنف فعليًا.";
        } catch (Throwable $e) {
            $conn->rollback();
            die("ERROR: " . $e->getMessage());
        }
    } else {
        $_SESSION['items_bulk_delete_msg'] = "لم يتم تحديد أي دفعات للحذف.";
    }

    header("Location: items_admin.php");
    exit();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['batch_id'])) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    $action = $_POST['action'];
    $batch = trim($_POST['batch_id'] ?? '');

    if ($batch !== '' && in_array($action, ['approve', 'reject', 'withdraw_return', 'withdraw_delete'], true)) {

        
        $notifyBatchInfo = null;
        $stmtNotifyBatch = $conn->prepare("
            SELECT
                i.batch_id,
                MAX(i.supplier_name) AS supplier_name,
                MAX(i.created_by) AS created_by,
                COUNT(*) AS items_count,
                creator.username AS creator_username
            FROM items i
            LEFT JOIN users creator ON creator.id = i.created_by
            WHERE i.batch_id = ?
            GROUP BY i.batch_id, creator.username
            LIMIT 1
        ");
        if ($stmtNotifyBatch) {
            $stmtNotifyBatch->bind_param("s", $batch);
            $stmtNotifyBatch->execute();
            $notifyBatchInfo = $stmtNotifyBatch->get_result()->fetch_assoc();
            $stmtNotifyBatch->close();
        }

        if ($action === 'approve') {
            $stmt = $conn->prepare("
                UPDATE items 
                SET status = 'approved',
                    approved_at = NOW(),
                    approved_by = ?
                WHERE batch_id = ?
                AND status = 'review'
            ");
            $stmt->bind_param("is", $uid, $batch);
            $stmt->execute();
            $affectedApprove = $stmt->affected_rows;
            $stmt->close();

            if ($affectedApprove > 0) {
            }

            if ($affectedApprove > 0 && !empty($notifyBatchInfo)) {
                $ownerId = (int)($notifyBatchInfo['created_by'] ?? 0);
                if ($ownerId > 0 && $ownerId !== $uid) {
                    vcDisabledUserHook(
                        $conn,
                        $ownerId,
                        'تمت الموافقة على طلب التكويد',
                        'تمت الموافقة على دفعة الأصناف رقم ' . $batch . ' للمورد: ' . ($notifyBatchInfo['supplier_name'] ?? '') . ' — عدد الأصناف: ' . (int)($notifyBatchInfo['items_count'] ?? 0),
                        'view_items.php?batch=' . urlencode((string)$batch),
                        'items_approved',
                        0
                    );
                }

                
                notifyDataEntryUsersForItemsBatch(
                    $conn,
                    (string)$batch,
                    $notifyBatchInfo,
                    (int)$uid,
                    (string)($currentUser['username'] ?? '')
                );

                
                if (function_exists('vcNhNotifyAccountants')) {
                    $supplierForAccountant = trim((string)($notifyBatchInfo['supplier_name'] ?? ''));
                    $itemsCountForAccountant = (int)($notifyBatchInfo['items_count'] ?? 0);
                    $creatorForAccountant = trim((string)($notifyBatchInfo['creator_username'] ?? 'غير محدد'));

                    vcNhNotifyAccountants(
                        $conn,
                        'طلب تكويد معتمد جاهز للخصم المالي',
                        'تم اعتماد طلب تكويد جاهز للمتابعة المالية' .
                            ($supplierForAccountant !== '' ? ' — المورد: ' . $supplierForAccountant : '') .
                            ' — رقم الدفعة: #' . (string)$batch .
                            ' — عدد الأصناف: ' . $itemsCountForAccountant .
                            ' — منشئ الطلب: ' . ($creatorForAccountant !== '' ? $creatorForAccountant : 'غير محدد') .
                            ' — الإجراء المطلوب: مراجعة الخصم من صفحة المالية.',
                        'finance_items.php?search=' . urlencode((string)$batch),
                        'items_approved_for_finance',
                        0,
                        [(int)$uid]
                    );
                }
            }

        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("
                UPDATE items 
                SET status = 'rejected',
                    rejected_at = NOW(),
                    rejected_by = ?
                WHERE batch_id = ?
                AND status = 'review'
            ");
            $stmt->bind_param("is", $uid, $batch);
            $stmt->execute();
            $affectedReject = $stmt->affected_rows;
            $stmt->close();

            if ($affectedReject > 0) {
            }

            if ($affectedReject > 0 && !empty($notifyBatchInfo)) {
                $ownerId = (int)($notifyBatchInfo['created_by'] ?? 0);
                if ($ownerId > 0 && $ownerId !== $uid) {
                    vcDisabledUserHook(
                        $conn,
                        $ownerId,
                        'تم رفض طلب التكويد',
                        'تم رفض دفعة الأصناف رقم ' . $batch . ' للمورد: ' . ($notifyBatchInfo['supplier_name'] ?? '') . ' — عدد الأصناف: ' . (int)($notifyBatchInfo['items_count'] ?? 0),
                        'view_items.php?batch=' . urlencode((string)$batch),
                        'items_rejected',
                        0
                    );
                }
            }

        } elseif (in_array($action, ['withdraw_return', 'withdraw_delete'], true)) {

            
            if (!$is_admin) {
                http_response_code(403);
                die("❌ سحب الاعتماد أو إلغاء دفعة التكويد للأدمن فقط");
            }

            $reason = trim($_POST['withdraw_reason'] ?? '');
            if ($reason === '') {
                $reason = 'بدون سبب مكتوب';
            }

            $stmtInfo = $conn->prepare("
                SELECT
                    batch_id,
                    MAX(supplier_name) AS supplier_name,
                    MAX(created_by) AS created_by,
                    MAX(status) AS current_status,
                    MAX(paid) AS paid
                FROM items
                WHERE batch_id = ?
                GROUP BY batch_id
                LIMIT 1
            ");
            $stmtInfo->bind_param("s", $batch);
            $stmtInfo->execute();
            $batchInfo = $stmtInfo->get_result()->fetch_assoc();
            $stmtInfo->close();

            if (!empty($batchInfo) && ($batchInfo['current_status'] ?? '') === 'approved') {
                $newStatus = ($action === 'withdraw_return') ? 'review' : 'deleted';
                $actionType = ($action === 'withdraw_return') ? 'return' : 'delete';
                $actionText = ($action === 'withdraw_return') ? 'إرجاع للمراجعة' : 'إلغاء الدفعة';

                if ($action === 'withdraw_return') {
                    $stmtWithdraw = $conn->prepare("
                        UPDATE items
                        SET status = 'review',
                            approved_at = NULL,
                            approved_by = NULL,
                            rejected_at = NULL,
                            rejected_by = NULL,
                            entry_done = 0,
                            entered_by = NULL,
                            entered_at = NULL
                        WHERE batch_id = ?
                        AND status = 'approved'
                    ");
                } else {
                    $stmtWithdraw = $conn->prepare("
                        UPDATE items
                        SET status = 'deleted'
                        WHERE batch_id = ?
                        AND status = 'approved'
                    ");
                }

                $stmtWithdraw->bind_param("s", $batch);
                $stmtWithdraw->execute();
                $affectedWithdraw = $stmtWithdraw->affected_rows;
                $stmtWithdraw->close();

                if ($affectedWithdraw > 0) {
                    logApprovalWithdrawal($conn, 'items', (string)$batch, 'approved', $newStatus, $actionType, $reason, $uid);

                    $ownerId = (int)($batchInfo['created_by'] ?? 0);
                    $supplierName = (string)($batchInfo['supplier_name'] ?? '');
                    $paidNote = !empty($batchInfo['paid']) ? ' — ملحوظة: الدفعة كان عليها خصم مالي.' : '';

                    if ($ownerId > 0 && $ownerId !== $uid) {
                        vcDisabledUserHook(
                            $conn,
                            $ownerId,
                            'تم سحب اعتماد تكويد الأصناف',
                            'تم سحب اعتماد دفعة الأصناف رقم ' . $batch . ' للمورد: ' . $supplierName . ' — الإجراء: ' . $actionText . ' — السبب: ' . $reason . $paidNote,
                            'view_items.php?batch=' . urlencode((string)$batch),
                            'items_withdrawn',
                            0
                        );
                    }
                }
            }
        }
    }

    header("Location: items_admin.php?updated=1");
    exit();
}


$search = trim($_GET['search'] ?? '');
$entry_filter = trim($_GET['entry'] ?? '');
$paid_filter = trim($_GET['paid'] ?? '');
$user_filter = trim($_GET['user'] ?? '');

if (!in_array($entry_filter, ['', 'done', 'pending'], true)) {
    $entry_filter = '';
}

if (!in_array($paid_filter, ['', 'paid', 'unpaid'], true)) {
    $paid_filter = '';
}
if (!$showUserFilter || ($user_filter !== '' && !vcIsUserInScope((int)$user_filter, $itemScopedUserIds))) {
    $user_filter = '';
}
$users_result = $showUserFilter ? vcGetVisibleUsersForFilter($conn, $itemScopedUserIds) : [];


$reviewFromWhere = "
    FROM items i
    LEFT JOIN users u ON u.id = i.created_by
    WHERE i.status = 'review'
";

$paramsReview = [];
$typesReview  = "";
$reviewFromWhere .= vcBuildInCondition('i.created_by', $itemScopedUserIds, $paramsReview, $typesReview);
if ($user_filter !== '') {
    $reviewFromWhere .= " AND i.created_by = ?";
    $paramsReview[] = (int)$user_filter;
    $typesReview .= "i";
}

if ($search !== '') {
    $reviewFromWhere .= " AND (
        i.supplier_name LIKE ?
        OR i.batch_id LIKE ?
        OR u.username LIKE ?
    )";
    $like = "%{$search}%";
    $paramsReview[] = $like;
    $paramsReview[] = $like;
    $paramsReview[] = $like;
    $typesReview .= "sss";
}

$reviewSummarySql = "
    SELECT
        COUNT(DISTINCT i.batch_id) AS review_batches,
        COUNT(*) AS review_items,
        COALESCE(SUM(i.fee), 0) AS review_fees
    {$reviewFromWhere}
";
$stmtReviewSummary = $conn->prepare($reviewSummarySql);
if (!empty($paramsReview)) {
    $stmtReviewSummary->bind_param($typesReview, ...$paramsReview);
}
$stmtReviewSummary->execute();
$reviewSummary = $stmtReviewSummary->get_result()->fetch_assoc() ?: [];
$stmtReviewSummary->close();

$reviewBatches = (int)($reviewSummary['review_batches'] ?? 0);
$reviewItems = (int)($reviewSummary['review_items'] ?? 0);
$reviewFees = (float)($reviewSummary['review_fees'] ?? 0);

$reviewGroupCountSql = "
    SELECT i.batch_id
    {$reviewFromWhere}
    GROUP BY 
        i.batch_id,
        i.supplier_name,
        u.username
";

$pg = vcPaginationState();
$reviewTotalRows = vcPaginationCountGrouped($conn, $reviewGroupCountSql, $paramsReview, $typesReview);
$reviewTotalPages = vcPaginationTotalPages($reviewTotalRows, $pg['per_page']);
$reviewPage = min($pg['page'], $reviewTotalPages);

$sqlReview = "
    SELECT 
        i.batch_id,
        i.supplier_name,
        COUNT(*) AS items_count,
        SUM(i.fee) AS total_fees,
        MAX(i.status) AS status,
        MAX(i.created_by) AS created_by,
        MAX(i.created_at) AS created_at,
        u.username AS created_username
    {$reviewFromWhere}
    GROUP BY 
        i.batch_id,
        i.supplier_name,
        u.username
    ORDER BY i.batch_id DESC
    LIMIT ? OFFSET ?
";

[$reviewDataParams, $reviewDataTypes] = vcPaginationBindLimit($paramsReview, $typesReview, $pg['limit'], ($reviewPage - 1) * $pg['per_page']);

$stmtReview = $conn->prepare($sqlReview);

if (!empty($reviewDataParams)) {
    $stmtReview->bind_param($reviewDataTypes, ...$reviewDataParams);
}

$stmtReview->execute();
$resultReview = $stmtReview->get_result();

$reviewRows = [];

while ($row = $resultReview->fetch_assoc()) {
    $reviewRows[] = $row;
}

$stmtReview->close();


$sqlApproved = "
    SELECT
        i.batch_id,
        MAX(i.supplier_name) AS supplier_name,

        i.created_by,
        creator.username AS creator_username,

        COUNT(*) AS approved_items_count,
        COALESCE(SUM(i.fee), 0) AS approved_total_fees,

        MAX(i.created_at) AS created_at,
        MAX(i.approved_at) AS approved_at,

        MAX(i.entry_done) AS entry_done,
        MAX(i.entered_by) AS entered_by,
        MAX(i.entered_at) AS entered_at,
        entry_user.username AS entered_username,

        MAX(i.paid) AS paid,
        MAX(i.deducted_by) AS deducted_by,
        MAX(i.deducted_at) AS deducted_at,
        accountant.username AS deducted_username

    FROM items i
    LEFT JOIN users creator ON creator.id = i.created_by
    LEFT JOIN users entry_user ON entry_user.id = i.entered_by
    LEFT JOIN users accountant ON accountant.id = i.deducted_by

    WHERE i.status = 'approved'
";

$paramsApproved = [];
$typesApproved = "";
$sqlApproved .= vcBuildInCondition('i.created_by', $itemScopedUserIds, $paramsApproved, $typesApproved);
if ($user_filter !== '') {
    $sqlApproved .= " AND i.created_by = ?";
    $paramsApproved[] = (int)$user_filter;
    $typesApproved .= "i";
}

if ($search !== '') {
    $sqlApproved .= " AND (
        i.batch_id LIKE ?
        OR i.supplier_name LIKE ?
        OR creator.username LIKE ?
        OR entry_user.username LIKE ?
        OR accountant.username LIKE ?
    )";

    $like = "%{$search}%";
    $paramsApproved[] = $like;
    $paramsApproved[] = $like;
    $paramsApproved[] = $like;
    $paramsApproved[] = $like;
    $paramsApproved[] = $like;
    $typesApproved .= "sssss";
}

$sqlApproved .= "
    GROUP BY
        i.batch_id,
        i.created_by,
        creator.username,
        entry_user.username,
        accountant.username
";

$having = [];

if ($entry_filter === 'done') {
    $having[] = "MAX(i.entry_done) = 1";
}

if ($entry_filter === 'pending') {
    $having[] = "(MAX(i.entry_done) IS NULL OR MAX(i.entry_done) = 0)";
}

if ($paid_filter === 'paid') {
    $having[] = "MAX(i.paid) = 1";
}

if ($paid_filter === 'unpaid') {
    $having[] = "(MAX(i.paid) IS NULL OR MAX(i.paid) = 0)";
}

if (!empty($having)) {
    $sqlApproved .= " HAVING " . implode(" AND ", $having);
}

$sqlApproved .= " ORDER BY MAX(i.approved_at) DESC, MAX(i.created_at) DESC, i.batch_id DESC LIMIT 60";

$stmtApproved = $conn->prepare($sqlApproved);

if (!empty($paramsApproved)) {
    $stmtApproved->bind_param($typesApproved, ...$paramsApproved);
}

$stmtApproved->execute();
$resultApproved = $stmtApproved->get_result();

$approvedRows = [];
$approvedBatches = 0;
$approvedFees = 0;
$entryDone = 0;
$entryPending = 0;
$paidDone = 0;
$paidPending = 0;

while ($row = $resultApproved->fetch_assoc()) {
    $approvedRows[] = $row;

    $approvedBatches++;
    $approvedFees += (float)($row['approved_total_fees'] ?? 0);

    if (!empty($row['entry_done'])) {
        $entryDone++;
    } else {
        $entryPending++;
    }

    if (!empty($row['paid'])) {
        $paidDone++;
    } else {
        $paidPending++;
    }
}

$stmtApproved->close();

$bulkDeleteMsg = $_SESSION['items_bulk_delete_msg'] ?? '';
unset($_SESSION['items_bulk_delete_msg']);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>مراجعة الأصناف</title>

<?php vcRenderPageAssets(['extra' => ['vc-items-admin.css']]); ?>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">📦 مراجعة الأصناف</h1>
        <p class="page-subtitle">
            مراجعة طلبات الأصناف، ثم متابعة الإدخال والخصم من نفس الصفحة.
        </p>
    </div>

    <?php if(!empty($bulkDeleteMsg)): ?>
        <div class="alert alert-info">
            <?= e($bulkDeleteMsg) ?>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['updated'])): ?>
        <div class="alert alert-success">
            تم تحديث حالة الدفعة بنجاح ✅
        </div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">تحت المراجعة</div>
            <div class="summary-value"><?= (int)$reviewBatches ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">أصناف المراجعة</div>
            <div class="summary-value"><?= (int)$reviewItems ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">طلبات معتمدة</div>
            <div class="summary-value"><?= (int)$approvedBatches ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">تم الإدخال</div>
            <div class="summary-value"><?= (int)$entryDone ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">تم الخصم</div>
            <div class="summary-value"><?= (int)$paidDone ?></div>
        </div>
    </div>

    <form class="filters" method="GET">
        <input type="text"
               id="searchInput"
               name="search"
               placeholder="🔍 بحث بالمورد أو رقم الطلب أو الموظف أو مدخل البيانات أو المحاسب..."
               value="<?= e($search) ?>">

        <?php if($showUserFilter): ?>
            <select name="user" id="userFilter">
                <option value="">بواسطة: كل الفريق</option>
                <?php foreach($users_result as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= ((string)$user_filter === (string)$u['id']) ? 'selected' : '' ?>>
                        <?= e($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <select name="entry" id="entryFilter">
            <option value="" <?= $entry_filter === '' ? 'selected' : '' ?>>كل الإدخال</option>
            <option value="pending" <?= $entry_filter === 'pending' ? 'selected' : '' ?>>لم يتم الإدخال</option>
            <option value="done" <?= $entry_filter === 'done' ? 'selected' : '' ?>>تم الإدخال</option>
        </select>

        <select name="paid" id="paidFilter">
            <option value="" <?= $paid_filter === '' ? 'selected' : '' ?>>كل الخصم</option>
            <option value="unpaid" <?= $paid_filter === 'unpaid' ? 'selected' : '' ?>>لم يتم الخصم</option>
            <option value="paid" <?= $paid_filter === 'paid' ? 'selected' : '' ?>>تم الخصم</option>
        </select>
    </form>

    <?php if($is_admin): ?>
        <form method="POST" id="bulkDeleteItemsForm" onsubmit="return confirmBulkDeleteItems();">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
            <input type="hidden" name="action" value="bulk_delete_items_batches">
        </form>

        <div class="bulk-clean-bar">
            <div class="bulk-clean-text">
                🧹 تنظيف دفعات الأصناف التجريبية: حدد الدفعات المطلوبة من الجدول أو الكروت ثم اضغط حذف المحدد.
                <br>
                <span id="selectedItemsCount">لم يتم تحديد دفعات</span>
            </div>

            <div class="bulk-clean-actions">
                <label class="bulk-select-all">
                    <input type="checkbox" id="checkAllItemsBatches">
                    تحديد كل الظاهر
                </label>

                <button type="submit" form="bulkDeleteItemsForm" class="bulk-delete-btn" id="bulkDeleteItemsBtn" disabled>
                    حذف المحدد
                </button>
            </div>
        </div>
    <?php endif; ?>

    <div class="section-title">
        <span>طلبات تحت المراجعة</span>
        <span class="section-note">موافقة / رفض</span>
    </div>

    <div class="table-box">

        <table class="table">
            <thead>
                <tr>
                    <?php if($is_admin): ?>
                        <th class="select-col">تحديد</th>
                    <?php endif; ?>
                    <th class="col-batch">رقم الطلب</th>
                    <th class="col-supplier">المورد</th>
                    <th class="col-count">عدد الأصناف</th>
                    <th class="col-fee">الرسوم</th>
                    <th class="col-date">تاريخ الطلب</th>
                    <th class="col-user">بواسطة</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-actions">إجراءات</th>
                </tr>
            </thead>

            <tbody>
                <?php if(!empty($reviewRows)): ?>

                    <?php foreach($reviewRows as $row): ?>
                        <?php
                            $createdAt = !empty($row['created_at'])
                                ? date("Y-m-d", strtotime($row['created_at']))
                                : '-';
                        ?>

                        <tr>
                            <?php if($is_admin): ?>
                                <td>
                                    <input type="checkbox"
                                           class="item-batch-check"
                                           form="bulkDeleteItemsForm"
                                           name="batch_ids[]"
                                           value="<?= e($row['batch_id']) ?>">
                                </td>
                            <?php endif; ?>

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

                            <td><?= e($createdAt) ?></td>

                            <td>
                                <span class="user-badge">
                                    <?= e($row['created_username'] ?? '-') ?>
                                </span>
                            </td>

                            <td>
                                <span class="status review">تحت المراجعة</span>
                            </td>

                            <td>
                                <?php
                                $approveRejectExtra = '
                                    <form method="POST" onsubmit="return confirm(\'تأكيد الموافقة على هذه الدفعة؟\')">
                                        <input type="hidden" name="csrf_token" value="' . e($csrf_token) . '">
                                        <input type="hidden" name="batch_id" value="' . e($row['batch_id']) . '">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-approve">موافقة</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm(\'تأكيد رفض هذه الدفعة؟\')">
                                        <input type="hidden" name="csrf_token" value="' . e($csrf_token) . '">
                                        <input type="hidden" name="batch_id" value="' . e($row['batch_id']) . '">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="btn btn-reject">رفض</button>
                                    </form>
                                ';
                                vcRenderRowActions([
                                    'view' => [
                                        'href' => 'view_items.php?batch=' . urlencode((string)$row['batch_id']),
                                        'label' => 'عرض',
                                    ],
                                    'edit' => [
                                        'href' => 'add_items.php?edit_batch=' . urlencode((string)$row['batch_id']),
                                    ],
                                    'delete' => [
                                        'action' => 'bulk_delete_items_batches',
                                        'fields' => ['batch_ids[]' => (string)$row['batch_id']],
                                        'confirm' => 'تأكيد حذف دفعة الأصناف رقم ' . (string)$row['batch_id'] . '؟',
                                    ],
                                    'extra' => $approveRejectExtra,
                                ], $csrf_token, $is_admin);
                                ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="<?= $is_admin ? 9 : 8 ?>" class="empty">لا توجد طلبات أصناف تحت المراجعة</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    </div>

    <?php vcRenderPagination($reviewPage, $reviewTotalPages); ?>

    <div class="section-title">
        <span>متابعة الطلبات المعتمدة</span>
        <span class="section-note">الإدخال والخصم في كروت بدون سكرول</span>
    </div>

    <?php if(!empty($approvedRows)): ?>
        <div class="approved-grid">
            <?php foreach($approvedRows as $row): ?>
                <?php
                    $createdAt = !empty($row['created_at'])
                        ? date("Y-m-d", strtotime($row['created_at']))
                        : '-';

                    $approvedAt = !empty($row['approved_at'])
                        ? date("Y-m-d", strtotime($row['approved_at']))
                        : $createdAt;

                    $isEntryDone = !empty($row['entry_done']);
                    $enteredAt = !empty($row['entered_at'])
                        ? date("Y-m-d", strtotime($row['entered_at']))
                        : '-';

                    $isPaid = !empty($row['paid']);
                    $deductedAt = !empty($row['deducted_at'])
                        ? date("Y-m-d", strtotime($row['deducted_at']))
                        : '-';
                ?>

                <div class="track-card">
                    <div class="track-head">
                        <div class="track-supplier"><?= e($row['supplier_name'] ?? '-') ?></div>
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                            <?php if($is_admin): ?>
                                <label class="bulk-card-check">
                                    <input type="checkbox"
                                           class="item-batch-check"
                                           form="bulkDeleteItemsForm"
                                           name="batch_ids[]"
                                           value="<?= e($row['batch_id']) ?>">
                                    حذف
                                </label>
                            <?php endif; ?>
                            <div class="track-id">#<?= e($row['batch_id']) ?></div>
                        </div>
                    </div>

                    <div class="track-row">
                        <div class="track-label">الموظف</div>
                        <div class="track-value">
                            <span class="user-badge"><?= e($row['creator_username'] ?? '-') ?></span>
                        </div>
                    </div>

                    <div class="track-row">
                        <div class="track-label">التاريخ</div>
                        <div class="track-value">
                            طلب: <?= e($createdAt) ?> — موافقة: <?= e($approvedAt) ?>
                        </div>
                    </div>

                    <div class="track-row">
                        <div class="track-label">الرسوم</div>
                        <div class="track-value">
                            <span class="money"><?= money($row['approved_total_fees'] ?? 0) ?></span>
                        </div>
                    </div>

                    <div class="combo-box">
                        <div class="combo-title">
                            <span class="track-label">الإدخال</span>
                            <?php if($isEntryDone): ?>
                                <span class="status done">تم الإدخال</span>
                            <?php else: ?>
                                <span class="status pending">لم يتم</span>
                            <?php endif; ?>
                        </div>

                        <div class="combo-details">
                            <span>بواسطة: <?= !empty($row['entered_username']) ? e($row['entered_username']) : '-' ?></span>
                            <span>تاريخ: <?= e($enteredAt) ?></span>
                        </div>
                    </div>

                    <div class="combo-box">
                        <div class="combo-title">
                            <span class="track-label">الخصم</span>
                            <?php if($isPaid): ?>
                                <span class="status done">تم الخصم</span>
                            <?php else: ?>
                                <span class="status pending">لم يتم</span>
                            <?php endif; ?>
                        </div>

                        <div class="combo-details">
                            <span>المحاسب: <?= !empty($row['deducted_username']) ? e($row['deducted_username']) : '-' ?></span>
                            <span>تاريخ: <?= e($deductedAt) ?></span>
                        </div>
                    </div>

                    <div class="track-actions">
                        <?php if($is_admin): ?>
                            <form method="POST" onsubmit="return askWithdrawReason(this, 'إرجاع اعتماد التكويد للمراجعة؟')">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="batch_id" value="<?= e($row['batch_id']) ?>">
                                <input type="hidden" name="action" value="withdraw_return">
                                <input type="hidden" name="withdraw_reason" value="">
                                <button type="submit" class="btn btn-withdraw">سحب للمراجعة</button>
                            </form>

                            <form method="POST" onsubmit="return askWithdrawReason(this, 'إلغاء دفعة التكويد المعتمدة؟')">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="batch_id" value="<?= e($row['batch_id']) ?>">
                                <input type="hidden" name="action" value="withdraw_delete">
                                <input type="hidden" name="withdraw_reason" value="">
                                <button type="submit" class="btn btn-delete">إلغاء</button>
                            </form>
                        <?php endif; ?>

                        <a class="btn btn-view" href="view_items.php?batch=<?= urlencode((string)$row['batch_id']) ?>">
                            عرض
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="table-box">
            <div class="empty">لا توجد طلبات معتمدة مطابقة</div>
        </div>
    <?php endif; ?>

</div>

<script>
let timer;
const searchInput = document.getElementById("searchInput");
const entryFilter = document.getElementById("entryFilter");
const paidFilter = document.getElementById("paidFilter");

function applyFilters(){
    let url = new URL(window.location.href);
    const search = document.getElementById("searchInput") ? document.getElementById("searchInput").value : "";
    const user = document.getElementById("userFilter") ? document.getElementById("userFilter").value : "";
    const entry = document.getElementById("entryFilter") ? document.getElementById("entryFilter").value : "";
    const paid = document.getElementById("paidFilter") ? document.getElementById("paidFilter").value : "";

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

    if(entry){
        url.searchParams.set("entry", entry);
    }else{
        url.searchParams.delete("entry");
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

if(entryFilter){
    entryFilter.addEventListener("change", applyFilters);
}

if(paidFilter){
    paidFilter.addEventListener("change", applyFilters);
}

function askWithdrawReason(form, message){
    let reason = prompt(message + "\nاكتب سبب سحب الاعتماد:");

    if(reason === null){
        return false;
    }

    reason = reason.trim();

    if(reason.length < 3){
        alert("لازم تكتب سبب واضح لسحب الاعتماد.");
        return false;
    }

    const input = form.querySelector("input[name='withdraw_reason']");
    if(input){
        input.value = reason;
    }

    return confirm("تأكيد تنفيذ سحب اعتماد التكويد؟");
}

function updateBulkDeleteItemsState(){
    const checks = Array.from(document.querySelectorAll('.item-batch-check'));
    const selected = checks.filter(c => c.checked);
    const countText = document.getElementById('selectedItemsCount');
    const btn = document.getElementById('bulkDeleteItemsBtn');
    const all = document.getElementById('checkAllItemsBatches');

    if(countText){
        countText.textContent = selected.length > 0
            ? 'تم تحديد ' + selected.length + ' دفعة'
            : 'لم يتم تحديد دفعات';
    }

    if(btn){
        btn.disabled = selected.length === 0;
    }

    if(all){
        all.checked = checks.length > 0 && selected.length === checks.length;
        all.indeterminate = selected.length > 0 && selected.length < checks.length;
    }
}

function confirmBulkDeleteItems(){
    const selected = Array.from(document.querySelectorAll('.item-batch-check')).filter(c => c.checked);

    if(selected.length === 0){
        alert('حدد دفعة واحدة على الأقل.');
        return false;
    }

    return confirm(
        'تحذير مهم:\n\n' +
        'سيتم حذف ' + selected.length + ' دفعة أصناف نهائيًا من جدول الأصناف.\n' +
        'هذا مناسب فقط لتنظيف الدفعات التجريبية.\n\n' +
        'هل أنت متأكد من الحذف؟'
    );
}

const checkAllItemsBatches = document.getElementById('checkAllItemsBatches');
if(checkAllItemsBatches){
    checkAllItemsBatches.addEventListener('change', function(){
        document.querySelectorAll('.item-batch-check').forEach(function(c){
            c.checked = checkAllItemsBatches.checked;
        });
        updateBulkDeleteItemsState();
    });
}

document.querySelectorAll('.item-batch-check').forEach(function(c){
    c.addEventListener('change', updateBulkDeleteItemsState);
});

updateBulkDeleteItemsState();

</script>

</body>
</html>
