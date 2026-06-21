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

function vcDisabledHookSetup(VcDb $conn): void {
    return;
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

ensureApprovalWithdrawalsTable($conn);
vcDisabledHookSetup($conn);


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
            if (columnExists($conn, 'approval_withdrawals', 'target_type') && columnExists($conn, 'approval_withdrawals', 'target_id')) {
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
    FROM items i
    LEFT JOIN users u ON u.id = i.created_by
    WHERE i.status = 'review'
";

$paramsReview = [];
$typesReview  = "";
$sqlReview .= vcBuildInCondition('i.created_by', $itemScopedUserIds, $paramsReview, $typesReview);
if ($user_filter !== '') {
    $sqlReview .= " AND i.created_by = ?";
    $paramsReview[] = (int)$user_filter;
    $typesReview .= "i";
}

if ($search !== '') {
    $sqlReview .= " AND (
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

$sqlReview .= "
    GROUP BY 
        i.batch_id,
        i.supplier_name,
        u.username
    ORDER BY i.batch_id DESC
";

$stmtReview = $conn->prepare($sqlReview);

if (!empty($paramsReview)) {
    $stmtReview->bind_param($typesReview, ...$paramsReview);
}

$stmtReview->execute();
$resultReview = $stmtReview->get_result();

$reviewRows = [];
$reviewBatches = 0;
$reviewItems = 0;
$reviewFees = 0;

while ($row = $resultReview->fetch_assoc()) {
    $reviewRows[] = $row;
    $reviewBatches++;
    $reviewItems += (int)($row['items_count'] ?? 0);
    $reviewFees += (float)($row['total_fees'] ?? 0);
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
    width:min(1380px, calc(100% - 22px));
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
.alert-info{
    background:#eef2ff;
    color:#3730a3;
    border:1px solid #c7d2fe;
}



.summary-grid{
    display:grid;
    grid-template-columns:repeat(5, 1fr);
    gap:14px;
    margin-bottom:18px;
}

.summary-card{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:20px;
    padding:15px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}

.summary-label{
    font-size:12px;
    color:#667085;
    font-weight:800;
    margin-bottom:7px;
}

.summary-value{
    font-size:22px;
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
    grid-template-columns:1fr 170px 170px;
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


.section-title{
    margin:22px 0 13px;
    padding:13px 15px;
    background:rgba(255,255,255,.70);
    border:1px solid rgba(226,232,240,.95);
    border-radius:18px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    color:#4f46e5;
    font-size:17px;
    font-weight:900;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
}

.section-note{
    font-size:12px;
    color:#667085;
    font-weight:800;
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
    padding:12px 7px;
    text-align:center;
    font-size:12px;
    line-height:1.45;
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
    padding:11px 7px;
    border-bottom:1px solid #dfe6f0;
    text-align:center;
    vertical-align:middle;
    font-size:12.5px;
    line-height:1.65;
    color:#172033;
}

.table tr:last-child td{
    border-bottom:none;
}

.table tr:hover td{
    background:#f6f4ff;
}

.col-batch{width:9%;}
.col-supplier{width:27%;}
.col-count{width:9%;}
.col-fee{width:10%;}
.col-date{width:11%;}
.col-user{width:11%;}
.col-status{width:11%;}
.col-actions{width:12%;}

.batch-id{
    color:#4f46e5;
    font-weight:900;
    direction:ltr;
    display:inline-block;
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
    display:inline-block;
    white-space:nowrap;
}

.user-badge{
    background:#f0edff;
    color:#4f46e5;
    max-width:100%;
    min-height:30px;
    padding:4px 8px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:11px;
    font-weight:900;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}


.status{
    min-width:82px;
    min-height:30px;
    padding:4px 8px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    white-space:nowrap;
}

.review{
    background:#fffbeb;
    color:#b45309;
}

.done{
    background:#ecfdf3;
    color:#166534;
}

.pending{
    background:#fff1f2;
    color:#b42318;
}


.actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    flex-wrap:wrap;
}

.actions form{
    margin:0;
    padding:0;
    display:inline-flex;
}

.btn{
    min-height:30px;
    padding:0 9px;
    border-radius:10px;
    border:none;
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

.btn-approve{
    background:#16a34a;
}

.btn-reject{
    background:#64748b;
}

.empty{
    padding:26px !important;
    text-align:center;
    color:#667085;
    font-weight:900;
}


.approved-grid{
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:14px;
}

.track-card{
    background:rgba(255,255,255,.72);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:15px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    min-height:245px;
    display:flex;
    flex-direction:column;
    gap:10px;
}

.track-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:10px;
    border-bottom:1px solid #e2e8f0;
    padding-bottom:10px;
}

.track-supplier{
    font-size:14px;
    font-weight:900;
    color:#172033;
    line-height:1.6;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.track-id{
    color:#4f46e5;
    font-weight:900;
    direction:ltr;
    white-space:nowrap;
}

.track-row{
    display:grid;
    grid-template-columns:92px 1fr;
    gap:8px;
    align-items:center;
    font-size:12px;
    line-height:1.7;
}

.track-label{
    color:#667085;
    font-weight:900;
}

.track-value{
    color:#172033;
    font-weight:900;
    overflow-wrap:anywhere;
}

.combo-box{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:15px;
    padding:9px;
    display:grid;
    gap:6px;
}

.combo-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
}

.combo-details{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:6px;
    color:#667085;
    font-size:11px;
    font-weight:800;
}

.track-actions{
    margin-top:auto;
    display:flex;
    justify-content:flex-end;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.track-actions form{
    margin:0;
}

.btn-withdraw{
    background:#b45309;
}

.btn-delete{
    background:#ef4444;
}



.bulk-clean-bar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin:0 0 18px;
    padding:12px 14px;
    border-radius:18px;
    background:#fff7ed;
    border:1px solid #fed7aa;
    color:#9a3412;
    font-weight:900;
    box-shadow:6px 6px 14px #d1d9e6,-6px -6px 14px #fff;
}
.bulk-clean-text{
    font-size:13px;
    line-height:1.8;
}
.bulk-clean-actions{
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}
.bulk-select-all{
    display:inline-flex;
    align-items:center;
    gap:7px;
    white-space:nowrap;
    font-size:12px;
    color:#9a3412;
}
.bulk-delete-btn{
    min-height:42px;
    padding:0 16px;
    border-radius:13px;
    border:none;
    background:#ef4444;
    color:#fff;
    font-weight:900;
    cursor:pointer;
    white-space:nowrap;
}
.bulk-delete-btn:disabled{
    opacity:.45;
    cursor:not-allowed;
}
.select-col{
    width:48px;
}
.item-batch-check,
#checkAllItemsBatches{
    width:18px;
    height:18px;
    min-height:auto;
    box-shadow:none;
    cursor:pointer;
    accent-color:#ef4444;
}
.bulk-card-check{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:5px 8px;
    border-radius:999px;
    background:#fff7ed;
    border:1px solid #fed7aa;
    color:#9a3412;
    font-size:11px;
    font-weight:900;
    white-space:nowrap;
}

@media(max-width:1180px){
    .summary-grid{
        grid-template-columns:repeat(3,1fr);
    }

    .approved-grid{
        grid-template-columns:repeat(2, 1fr);
    }

    .table-box{
        overflow-x:auto;
    }

    .table{
        min-width:1060px;
    }
}

@media(max-width:760px){
    .summary-grid,
    .approved-grid{
        grid-template-columns:1fr;
    }

    .filters{
        grid-template-columns:1fr;
    }

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
                    <th class="col-actions">إجراء</th>
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
                                <div class="actions">

                                    <a class="btn btn-view" href="view_items.php?batch=<?= urlencode((string)$row['batch_id']) ?>">
                                        عرض
                                    </a>

                                    <form method="POST" onsubmit="return confirm('تأكيد الموافقة على هذه الدفعة؟')">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                        <input type="hidden" name="batch_id" value="<?= e($row['batch_id']) ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-approve">موافقة</button>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('تأكيد رفض هذه الدفعة؟')">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                        <input type="hidden" name="batch_id" value="<?= e($row['batch_id']) ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="btn btn-reject">رفض</button>
                                    </form>

                                </div>
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
    document.querySelector(".filters").submit();
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
