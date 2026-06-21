<?php

function ensureApprovalWithdrawalsTable(mysqli $conn): void {
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

function logApprovalWithdrawal(mysqli $conn, string $targetType, string $targetId, string $oldStatus, string $newStatus, string $actionType, string $reason, int $adminId): void {
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

require_once VC_HELPERS . '/scope_helper.php';

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}



date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cleanValue($value, string $empty = '-'): string {
    $value = trim((string)$value);

    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return $empty;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
        return date("Y-m-d", strtotime($value));
    }

    return $value;
}

function money($value): string {
    return number_format((float)$value, 2);
}

function normalizeArabicName(string $value): string {
    $value = trim($value);
    $value = preg_replace('/\s+/u', '', $value);
    $value = str_replace(['أ','إ','آ'], 'ا', $value);
    $value = str_replace('ى', 'ي', $value);
    $value = str_replace('ة', 'ه', $value);
    $value = str_replace(['ـ', '.', ',', '،', '-', '_'], '', $value);

    return $value;
}

function getExistingColumn(mysqli $conn, string $table, array $candidates): ?string {
    foreach ($candidates as $column) {
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

        if (!empty($row) && (int)$row['c'] > 0) {
            return $column;
        }
    }

    return null;
}


function vcColumnExists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row) && (int)$row['c'] > 0;
}

function vcDisabledHookSetup(mysqli $conn): void {
    return;
}


function vcDisabledUserHook(mysqli $conn, int $userId, string $title, string $message, string $link = '', string $type = 'contract', int $relatedId = 0): void {
    return;
}

function vcDisabledAdminsHook(mysqli $conn, string $title, string $message, string $link = '', string $type = 'contract', int $relatedId = 0, int $excludeUserId = 0): void {
    return;
}

function statusText($status): string {
    $map = [
        'draft'    => 'تفاوض',
        'review'   => 'تحت المراجعة',
        'approved' => 'تمت الموافقة',
        'rejected' => 'مرفوض',
        'deleted' => 'ملغي'
    ];

    return $map[$status] ?? 'غير معروف';
}

function statusClass($status): string {
    return in_array($status, ['draft','review','approved','rejected','deleted'], true) ? $status : 'draft';
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

if (empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$uid = (int)$_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

ensureApprovalWithdrawalsTable($conn);

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die("❌ رقم العقد غير صحيح");
}


$stmtUser = $conn->prepare("SELECT is_admin, role, job_role, is_supervisor, username FROM users WHERE id=? LIMIT 1");
$stmtUser->bind_param("i", $uid);
$stmtUser->execute();
$currentUser = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$is_admin = !empty($currentUser) && (
    (int)($currentUser['is_admin'] ?? 0) === 1 ||
    ($currentUser['role'] ?? '') === 'admin'
);
$currentJobRole = (string)($currentUser['job_role'] ?? 'user');
$isCommercialManager = ($currentJobRole === 'commercial_manager');
$isAdminLike = ($is_admin || $isCommercialManager);


$contractsScope = getUserPageScope($conn, $uid, 'contracts');


$canReviewAllContracts = ($is_admin || $contractsScope !== 'none');


$myContractsScope = getUserPageScope($conn, $uid, 'my_contracts');
$canViewAllMyContractsDetails = ($isAdminLike || $myContractsScope !== 'none');

$draftsScope = getUserPageScope($conn, $uid, 'drafts');
$canViewAllDraftsDetails = ($isAdminLike || $draftsScope !== 'none');

$underReviewScope = getUserPageScope($conn, $uid, 'under_review');
$canViewAllUnderReviewDetails = ($isAdminLike || $underReviewScope !== 'none');


$financePageNames = [
    'accounting',
    'finance',
    'finance_items',
    'accounting_api',
    'accounts',
    'rents_accounting',
    'contracts_accounting'
];

$canViewFinanceContracts = false;

foreach ($financePageNames as $financePageName) {
    if (getUserPageScope($conn, $uid, $financePageName) !== 'none') {
        $canViewFinanceContracts = true;
        break;
    }
}


$canViewAnyContract = ($isAdminLike || $canViewFinanceContracts);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {

    header("Content-Type: application/json; charset=UTF-8");

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "طلب غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $postId = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!$canReviewAllContracts && !in_array($action, ['signed_received', 'signed_not_received'], true)) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "غير مصرح"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($postId <= 0 || !in_array($action, ['approve', 'reject', 'withdraw_return', 'withdraw_delete', 'signed_received', 'signed_not_received'], true)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "بيانات غير صحيحة"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (in_array($action, ['withdraw_return', 'withdraw_delete'], true)) {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $reason = 'بدون سبب مكتوب';
        }

        $stmtContract = $conn->prepare("SELECT id, created_by, supplier_name, status FROM contracts WHERE id = ? LIMIT 1");
        $stmtContract->bind_param("i", $postId);
        $stmtContract->execute();
        $contractRow = $stmtContract->get_result()->fetch_assoc();
        $stmtContract->close();

        if (empty($contractRow) || ($contractRow['status'] ?? '') !== 'approved') {
            echo json_encode(["success" => false, "message" => "العقد ليس في حالة موافقة"], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $newStatus = ($action === 'withdraw_return') ? 'draft' : 'deleted';
        $actionType = ($action === 'withdraw_return') ? 'return' : 'delete';
        $actionText = ($action === 'withdraw_return') ? 'إرجاع للتفاوض' : 'إلغاء العقد';
        $editNote = 'سحب اعتماد: ' . $actionText . ' - السبب: ' . $reason;

        if ($action === 'withdraw_return') {
            $stmt = $conn->prepare("\n                UPDATE contracts\n                SET status='draft',\n                    approved_at=NULL,\n                    rejected_at=NULL,\n                    last_edited_by=?,\n                    last_edited_at=NOW(),\n                    edit_note=?\n                WHERE id=?\n                AND status='approved'\n                LIMIT 1\n            ");
        } else {
            $stmt = $conn->prepare("\n                UPDATE contracts\n                SET status='deleted',\n                    last_edited_by=?,\n                    last_edited_at=NOW(),\n                    edit_note=?\n                WHERE id=?\n                AND status='approved'\n                LIMIT 1\n            ");
        }

        $stmt->bind_param("isi", $uid, $editNote, $postId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            echo json_encode(["success" => false, "message" => "لم يتم سحب الاعتماد"], JSON_UNESCAPED_UNICODE);
            exit();
        }

        logApprovalWithdrawal($conn, 'contract', (string)$postId, 'approved', $newStatus, $actionType, $reason, $uid);

        $stmtHist = $conn->prepare("\n            INSERT INTO contract_history (contract_id, user_id, field_name, old_value, new_value, created_at)\n            VALUES (?, ?, 'سحب اعتماد العقد', 'approved', ?, NOW())\n        ");
        if ($stmtHist) {
            $stmtHist->bind_param("iis", $postId, $uid, $newStatus);
            $stmtHist->execute();
            $stmtHist->close();
        }

        $ownerId = (int)($contractRow['created_by'] ?? 0);
        $notifySupplier = (string)($contractRow['supplier_name'] ?? '');

        if ($ownerId > 0 && $ownerId !== $uid) {
            vcDisabledUserHook(
                $conn,
                $ownerId,
                'تم سحب اعتماد العقد',
                'تم سحب اعتماد العقد رقم #' . (int)$postId . ' للمورد: ' . $notifySupplier . ' — الإجراء: ' . $actionText . ' — السبب: ' . $reason,
                'view_contract.php?id=' . (int)$postId,
                'contract_withdrawn',
                (int)$postId
            );
        }

        echo json_encode(["success" => true, "message" => "تم سحب اعتماد العقد"], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (in_array($action, ['signed_received', 'signed_not_received'], true)) {
        if (!vcColumnExists($conn, 'contracts', 'supplier_signed_received')) {
            echo json_encode([
                "success" => false,
                "message" => "لازم تشغيل ملف SQL الخاص بإضافة خانة النسخة الموقعة أولاً"
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $stmtContract = $conn->prepare("SELECT id, created_by, supplier_name, status, supplier_signed_received FROM contracts WHERE id = ? LIMIT 1");
        $stmtContract->bind_param("i", $postId);
        $stmtContract->execute();
        $contractRow = $stmtContract->get_result()->fetch_assoc();
        $stmtContract->close();

        if (empty($contractRow) || ($contractRow['status'] ?? '') !== 'approved') {
            echo json_encode(["success" => false, "message" => "تأكيد النسخة الموقعة متاح للعقود المعتمدة فقط"], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $ownerId = (int)($contractRow['created_by'] ?? 0);
        if (!$canReviewAllContracts && $ownerId !== $uid) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "غير مصرح"], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $receivedValue = ($action === 'signed_received') ? 1 : 0;
        $oldValue = ((int)($contractRow['supplier_signed_received'] ?? 0) === 1) ? 'وصلت' : 'لم تصل';
        $newValue = ($receivedValue === 1) ? 'وصلت' : 'لم تصل';

        $stmt = $conn->prepare("
            UPDATE contracts
            SET supplier_signed_received=?,
                last_edited_by=?,
                last_edited_at=NOW(),
                edit_note=?
            WHERE id=?
            AND status='approved'
            LIMIT 1
        ");
        $editNote = 'تحديث استلام النسخة الموقعة: ' . $newValue;
        $stmt->bind_param("iisi", $receivedValue, $uid, $editNote, $postId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            echo json_encode(["success" => false, "message" => "لم يتم تحديث حالة النسخة الموقعة"], JSON_UNESCAPED_UNICODE);
            exit();
        }

        try {
            $stmtHist = $conn->prepare("
                INSERT INTO contract_history (contract_id, user_id, field_name, old_value, new_value, created_at)
                VALUES (?, ?, 'استلام النسخة الموقعة', ?, ?, NOW())
            ");
            if ($stmtHist) {
                $stmtHist->bind_param("iiss", $postId, $uid, $oldValue, $newValue);
                $stmtHist->execute();
                $stmtHist->close();
            }
        } catch (Throwable $e) {
            error_log("signed received history error: " . $e->getMessage());
        }

        echo json_encode([
            "success" => true,
            "message" => ($receivedValue === 1) ? "تم تأكيد استلام النسخة الموقعة" : "تم إلغاء تأكيد استلام النسخة الموقعة"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($action === 'approve') {
        $stmt = $conn->prepare("
            UPDATE contracts 
            SET status='approved',
                approved_at=NOW(),
                rejected_at=NULL
            WHERE id=?
            AND status='review'
            LIMIT 1
        ");
    } else {
        $stmt = $conn->prepare("
            UPDATE contracts 
            SET status='rejected',
                rejected_at=NOW()
            WHERE id=?
            AND status='review'
            LIMIT 1
        ");
    }

    $stmt->bind_param("i", $postId);
    $stmt->execute();

    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        echo json_encode([
            "success" => false,
            "message" => "العقد اتراجع قبل كده أو مش في حالة مراجعة"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    
    $stmtContractOwner = $conn->prepare("
        SELECT c.created_by, c.supplier_name, c.supplier_phone, c.source, u.username AS creator_name
        FROM contracts c
        LEFT JOIN users u ON u.id = c.created_by
        WHERE c.id = ?
        LIMIT 1
    ");
    if ($stmtContractOwner) {
        $stmtContractOwner->bind_param("i", $postId);
        $stmtContractOwner->execute();
        $notifyContract = $stmtContractOwner->get_result()->fetch_assoc();
        $stmtContractOwner->close();

        $ownerId = (int)($notifyContract['created_by'] ?? 0);
        $notifySupplier = (string)($notifyContract['supplier_name'] ?? '');
        $notifySupplierPhone = (string)($notifyContract['supplier_phone'] ?? '');
        $creatorNameForSupplier = (string)($notifyContract['creator_name'] ?? '');
        

        if ($ownerId > 0 && $ownerId !== $uid) {
            $notifyTitle = ($action === 'approve') ? 'تمت الموافقة على عقدك' : 'تم رفض عقدك';
            $notifyType  = ($action === 'approve') ? 'contract_approved' : 'contract_rejected';
            $notifyMsg   = (($action === 'approve') ? 'تمت الموافقة على' : 'تم رفض') . ' العقد رقم #' . (int)$postId . ' للمورد: ' . $notifySupplier;

            vcDisabledUserHook(
                $conn,
                $ownerId,
                $notifyTitle,
                $notifyMsg,
                'view_contract.php?id=' . (int)$postId,
                $notifyType,
                (int)$postId
            );
        }


        
        if ($action === 'approve' && function_exists('vcNhNotifyAccountants')) {
            $contractSource = (string)($notifyContract['source'] ?? '');
            $isRentContract = ($contractSource === 'rent');
            $financeTitle = $isRentContract
                ? 'عقد إيجار معتمد جاهز للمتابعة المالية'
                : 'عقد معتمد جاهز للمتابعة المالية';
            $financeType = $isRentContract ? 'rent_approved_finance' : 'contract_approved_finance';
            $financeMessage = 'تم اعتماد ' . ($isRentContract ? 'عقد إيجار' : 'عقد') .
                ' رقم #' . (int)$postId .
                ' للمورد: ' . $notifySupplier .
                ' — الإجراء المطلوب: مراجعة البنود المالية.';

            vcNhNotifyAccountants(
                $conn,
                $financeTitle,
                $financeMessage,
                'accounting.php',
                $financeType,
                (int)$postId,
                [(int)$uid]
            );
        }
    }

    
    try {
        $newStatus = ($action === 'approve') ? 'approved' : 'rejected';

        $stmtHist = $conn->prepare("
            INSERT INTO contract_history (contract_id, user_id, field_name, old_value, new_value, created_at)
            VALUES (?, ?, 'status', 'review', ?, NOW())
        ");

        if ($stmtHist) {
            $stmtHist->bind_param("iis", $postId, $uid, $newStatus);
            $stmtHist->execute();
            $stmtHist->close();
        }
    } catch (Throwable $e) {
        error_log("view_contract history status error: " . $e->getMessage());
    }

    echo json_encode([
        "success" => true,
        "message" => $action === 'approve' ? "تمت الموافقة على العقد" : "تم رفض العقد"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}


$stmt = $conn->prepare("SELECT * FROM contracts WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contract) {
    die("❌ العقد غير موجود أو ليس لديك صلاحية لعرضه");
}


$contractOwnerId = (int)($contract['created_by'] ?? 0);
$contractStatus  = (string)($contract['status'] ?? '');

$isContractOwner = ($contractOwnerId === $uid);

$contractsScopedIds   = vcGetScopedUserIds($conn, $uid, $contractsScope, $isAdminLike);
$myContractsScopedIds = vcGetScopedUserIds($conn, $uid, $myContractsScope, $isAdminLike);
$draftsScopedIds      = vcGetScopedUserIds($conn, $uid, $draftsScope, $isAdminLike);
$underReviewScopedIds = vcGetScopedUserIds($conn, $uid, $underReviewScope, $isAdminLike);

$canViewByContractsScope = (
    $contractsScope !== 'none' &&
    vcIsUserInScope($contractOwnerId, $contractsScopedIds)
);

$canViewByMyContractsAll = (
    $canViewAllMyContractsDetails &&
    vcIsUserInScope($contractOwnerId, $myContractsScopedIds) &&
    in_array($contractStatus, ['approved', 'rejected'], true)
);

$canViewByDraftsAll = (
    $canViewAllDraftsDetails &&
    vcIsUserInScope($contractOwnerId, $draftsScopedIds) &&
    in_array($contractStatus, ['draft', 'review'], true)
);

$canViewByUnderReviewAll = (
    $canViewAllUnderReviewDetails &&
    vcIsUserInScope($contractOwnerId, $underReviewScopedIds) &&
    $contractStatus === 'review'
);

$canViewThisContract = (
    $isAdminLike ||
    $canViewFinanceContracts ||
    $isContractOwner ||
    $canViewByContractsScope ||
    $canViewByMyContractsAll ||
    $canViewByDraftsAll ||
    $canViewByUnderReviewAll
);

if (!$canViewThisContract) {
    die("❌ العقد غير موجود أو ليس لديك صلاحية لعرضه");
}


$contractType = (($contract['source'] ?? '') === 'rent') ? 'عقد إيجار' : 'عقد سنوي';
$isSupplierContractModel = (($contract['contract_form_type'] ?? 'system') === 'supplier');
$supplierContractFile = trim((string)($contract['supplier_contract_file'] ?? ''));
$supplierContractRef = trim((string)($contract['supplier_contract_ref'] ?? ''));
$supplierContractNote = trim((string)($contract['supplier_contract_note'] ?? ''));


$edited_text = "";

if (!empty($contract['last_edited_by'])) {

    $editUserId = (int)$contract['last_edited_by'];

    $stmtEditUser = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
    $stmtEditUser->bind_param("i", $editUserId);
    $stmtEditUser->execute();
    $u = $stmtEditUser->get_result()->fetch_assoc();
    $stmtEditUser->close();

    $name = $u['username'] ?? 'غير معروف';
    $date = cleanValue($contract['last_edited_at'] ?? '');
    $note = $contract['edit_note'] ?? '';

    $edited_text = "تم تعديل هذا العقد بواسطة ({$name}) بتاريخ {$date}";

    if ($note) {
        $edited_text .= "\nالسبب: {$note}";
    }
}


$stmtAnnual = $conn->prepare("SELECT * FROM annual_discounts WHERE contract_id=? ORDER BY id ASC");
$stmtAnnual->bind_param("i", $id);
$stmtAnnual->execute();
$annualRes = $stmtAnnual->get_result();
$annualRows = [];
while ($row = $annualRes->fetch_assoc()) {
    $annualRows[] = $row;
}
$stmtAnnual->close();

$stmtEvents = $conn->prepare("SELECT * FROM events WHERE contract_id=? ORDER BY id ASC");
$stmtEvents->bind_param("i", $id);
$stmtEvents->execute();
$eventsRes = $stmtEvents->get_result();
$eventRows = [];
while ($row = $eventsRes->fetch_assoc()) {
    $eventRows[] = $row;
}
$stmtEvents->close();

$stmtRents = $conn->prepare("SELECT * FROM rents WHERE contract_id=? ORDER BY branch ASC, start_date ASC");
$stmtRents->bind_param("i", $id);
$stmtRents->execute();
$rentsRes = $stmtRents->get_result();
$rentRows = [];
$rentTotal = 0;
while ($row = $rentsRes->fetch_assoc()) {
    $rentRows[] = $row;
    $rentTotal += (float)($row['total'] ?? 0);
}
$stmtRents->close();

$stmtHistory = $conn->prepare("
    SELECT h.*, u.username 
    FROM contract_history h
    LEFT JOIN users u ON u.id = h.user_id
    WHERE h.contract_id = ?
    ORDER BY h.id DESC
");
$stmtHistory->bind_param("i", $id);
$stmtHistory->execute();
$historyRes = $stmtHistory->get_result();
$historyRows = [];
while ($row = $historyRes->fetch_assoc()) {
    $historyRows[] = $row;
}
$stmtHistory->close();

$status = statusText($contract['status'] ?? '');

$field_labels = [
    'supplier_name' => 'اسم المورد',
    'company_name' => 'المسؤول',
    'supplier_phone' => 'الجوال',
    'supplier_status' => 'حالة المورد',
    'status' => 'حالة العقد',
    'start_date' => 'تاريخ البداية',
    'end_date' => 'تاريخ النهاية',
    'payment_period' => 'فترة السداد',
    'discount_invoice' => 'خصم الفاتورة',
    'discount_payment' => 'خصم السداد',
    'discount_quarter' => 'خصم 3 شهور',
    'notes' => 'ملاحظات',
    'رسوم صنف جديد' => 'رسوم صنف جديد',
    'الإيجارات' => 'الإيجارات',
    'الخصم السنوي' => 'الخصم السنوي',
    'الفعاليات' => 'الفعاليات'
];

$isReview = (($contract['status'] ?? '') === 'review');


$isAnnualContract = (($contract['source'] ?? '') !== 'rent');
$currentSupplierName = trim((string)($contract['supplier_name'] ?? ''));
$currentSupplierKey = normalizeArabicName($currentSupplierName);

$withdrawAmount2025 = 0.0;
$withdrawMatchedCount = 0;
$withdrawSourceNote = 'من جدول suppliers: company + phone';

if ($is_admin && $isReview && $isAnnualContract && $currentSupplierKey !== '') {

    
    $supplierNameColumn = getExistingColumn($conn, 'suppliers', [
        'company',
        'supplier_name',
        'company_name',
        'title',
        'name'
    ]);

    $supplierAmountColumn = getExistingColumn($conn, 'suppliers', [
        'phone',
        'supplier_phone',
        'fon',
        'foon',
        'mobile'
    ]);

    if ($supplierNameColumn && $supplierAmountColumn) {

        $nameCol = "`" . str_replace("`", "", $supplierNameColumn) . "`";
        $amountCol = "`" . str_replace("`", "", $supplierAmountColumn) . "`";

        
        $sqlWithdraw = "
            SELECT
                COALESCE(SUM(
                    CAST(
                        NULLIF(
                            REPLACE(REPLACE(REPLACE(REPLACE(TRIM($amountCol), ',', ''), '٬', ''), 'ريال', ''), ' ', ''),
                            ''
                        ) AS DECIMAL(15,2)
                    )
                ), 0) AS total_withdraw,
                COUNT(*) AS matched_count
            FROM suppliers
            WHERE
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(TRIM($nameCol), ' ', ''),
                                'أ', 'ا'),
                            'إ', 'ا'),
                        'آ', 'ا'),
                    'ى', 'ي'),
                'ة', 'ه') = ?
        ";

        $stmtWithdraw = $conn->prepare($sqlWithdraw);

        if ($stmtWithdraw) {
            $stmtWithdraw->bind_param("s", $currentSupplierKey);
            $stmtWithdraw->execute();
            $withdrawRow = $stmtWithdraw->get_result()->fetch_assoc();
            $stmtWithdraw->close();

            $withdrawAmount2025 = (float)($withdrawRow['total_withdraw'] ?? 0);
            $withdrawMatchedCount = (int)($withdrawRow['matched_count'] ?? 0);
        }

        
        if ($withdrawMatchedCount === 0) {
            $likeKey = "%" . $currentSupplierKey . "%";

            $sqlWithdrawLike = "
                SELECT
                    COALESCE(SUM(
                        CAST(
                            NULLIF(
                                REPLACE(REPLACE(REPLACE(REPLACE(TRIM($amountCol), ',', ''), '٬', ''), 'ريال', ''), ' ', ''),
                                ''
                            ) AS DECIMAL(15,2)
                        )
                    ), 0) AS total_withdraw,
                    COUNT(*) AS matched_count
                FROM suppliers
                WHERE
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        REPLACE(TRIM($nameCol), ' ', ''),
                                    'أ', 'ا'),
                                'إ', 'ا'),
                            'آ', 'ا'),
                        'ى', 'ي'),
                    'ة', 'ه') LIKE ?
            ";

            $stmtWithdrawLike = $conn->prepare($sqlWithdrawLike);

            if ($stmtWithdrawLike) {
                $stmtWithdrawLike->bind_param("s", $likeKey);
                $stmtWithdrawLike->execute();
                $withdrawLikeRow = $stmtWithdrawLike->get_result()->fetch_assoc();
                $stmtWithdrawLike->close();

                $withdrawAmount2025 = (float)($withdrawLikeRow['total_withdraw'] ?? 0);
                $withdrawMatchedCount = (int)($withdrawLikeRow['matched_count'] ?? 0);
            }
        }

    } else {
        $withdrawSourceNote = 'لم يتم العثور على أعمدة الموردين المطلوبة';
    }
}

$showWithdraw2025 = (
    $is_admin &&
    $isReview &&
    $isAnnualContract
);



$supplierWithdrawList = [];

if ($showWithdraw2025) {
    $supplierCompanyColumn = getExistingColumn($conn, 'suppliers', ['company']);
    $supplierPhoneColumn = getExistingColumn($conn, 'suppliers', ['phone']);

    if ($supplierCompanyColumn && $supplierPhoneColumn) {
        $stmtSupplierList = $conn->prepare("
            SELECT
                id,
                company,
                phone
            FROM suppliers
            WHERE company IS NOT NULL
            AND TRIM(company) <> ''
            ORDER BY company ASC
            LIMIT 3000
        ");

        if ($stmtSupplierList) {
            $stmtSupplierList->execute();
            $supplierListRes = $stmtSupplierList->get_result();

            while ($supplierRow = $supplierListRes->fetch_assoc()) {
                $supplierPhoneValue = (float)str_replace(
                    [' ', ',', '٬', 'ريال'],
                    '',
                    (string)($supplierRow['phone'] ?? 0)
                );

                $supplierWithdrawList[] = [
                    'id' => (int)($supplierRow['id'] ?? 0),
                    'company' => (string)($supplierRow['company'] ?? ''),
                    'phone' => $supplierPhoneValue
                ];
            }

            $stmtSupplierList->close();
        }
    }
}


$hasDiscounts = (
    (float)($contract['discount_invoice'] ?? 0) > 0 ||
    (float)($contract['discount_payment'] ?? 0) > 0 ||
    (float)($contract['discount_quarter'] ?? 0) > 0
);

$supplierSignedReceived = ((int)($contract['supplier_signed_received'] ?? 0) === 1);
$canUpdateSupplierSignedReceived = (
    (($contract['status'] ?? '') === 'approved') &&
    ($canReviewAllContracts || $isContractOwner)
);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>تفاصيل العقد</title>

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

.contract-head{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:24px;
    padding:22px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    margin-bottom:18px;
}

.head-top{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:18px;
    flex-wrap:nowrap;
}

.title-wrap{
    min-width:280px;
    flex:1 1 auto;
}

.page-title{
    margin:0;
    font-size:28px;
    font-weight:900;
    color:#172033;
    letter-spacing:-.3px;
}

.page-subtitle{
    margin:8px 0 0;
    color:#667085;
    font-size:15px;
    line-height:1.9;
    font-weight:700;
}

.head-badges{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:12px;
}

.badge{
    min-height:36px;
    padding:7px 13px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:13px;
    font-weight:900;
}

.badge-type{
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    box-shadow:0 10px 18px rgba(109,74,255,.18);
}

.badge-status{
    background:#f0edff;
    color:#4f46e5;
}

.badge-status.review{
    background:#fffbeb;
    color:#b45309;
}

.badge-status.approved{
    background:#ecfdf3;
    color:#166534;
}

.badge-status.rejected{
    background:#fff1f2;
    color:#b42318;
}

.badge-status.draft{
    background:#f1f5f9;
    color:#475569;
}

.badge-status.deleted{
    background:#f1f5f9;
    color:#334155;
}

.badge-signed-yes{
    background:#ecfdf3;
    color:#166534;
}

.badge-signed-no{
    background:#fff7ed;
    color:#c2410c;
}

.top-actions{
    display:flex;
    flex-direction:row;
    gap:10px;
    align-items:flex-start;
    justify-content:flex-start;
    flex-wrap:wrap;
    flex:0 0 auto;
    max-width:560px;
}

.action-row{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    justify-content:flex-start;
}

.btn{
    min-height:44px;
    min-width:126px;
    padding:0 16px;
    border:none;
    border-radius:15px;
    cursor:pointer;
    font-size:14px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    text-decoration:none;
    transition:.18s ease;
    white-space:nowrap;
}

.btn:hover{
    transform:translateY(-1px);
    filter:brightness(.98);
}

.btn:disabled{
    opacity:.55;
    cursor:not-allowed;
    transform:none;
}

.btn-approve{
    background:linear-gradient(145deg,#10b981,#059669);
    color:#fff;
    box-shadow:0 12px 22px rgba(16,185,129,.18);
}

.btn-reject{
    background:linear-gradient(145deg,#64748b,#475569);
    color:#fff;
    box-shadow:0 12px 22px rgba(71,85,105,.18);
}

.btn-withdraw{
    background:linear-gradient(145deg,#f59e0b,#b45309);
    color:#fff;
    box-shadow:0 12px 22px rgba(180,83,9,.18);
}

.btn-print{
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    border:1px solid rgba(109,74,255,.25);
    box-shadow:0 12px 22px rgba(109,74,255,.18);
}

.btn-signed{
    background:linear-gradient(145deg,#f97316,#c2410c);
    color:#fff;
    box-shadow:0 12px 22px rgba(194,65,12,.18);
}

.btn-signed-done{
    background:linear-gradient(145deg,#10b981,#059669);
    color:#fff;
    box-shadow:0 12px 22px rgba(16,185,129,.18);
}


.btn-approve::before{
    content:"✓";
    width:24px;
    height:24px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(255,255,255,.18);
    font-weight:900;
}

.btn-reject::before{
    content:"×";
    width:24px;
    height:24px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(255,255,255,.18);
    font-weight:900;
    font-size:18px;
}

.section{
    background:rgba(255,255,255,.62);
    border-radius:22px;
    padding:20px;
    margin-bottom:16px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    border:1px solid rgba(226,232,240,.95);
}

.section-title{
    background:rgba(255,255,255,.74);
    padding:14px 17px;
    border-radius:18px;
    font-weight:900;
    margin:0 0 16px;
    color:#4f46e5;
    display:flex;
    align-items:center;
    gap:10px;
    border:1px solid rgba(226,232,240,.95);
}

.section-title::before{
    content:"";
    width:9px;
    height:24px;
    border-radius:999px;
    background:linear-gradient(180deg,#7c5cff,#4f46e5);
}

.grid{
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:12px;
}

.grid.supplier-grid{
    grid-template-columns:2fr 1fr 1fr;
}

.box{
    min-height:70px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:12px;
}

.label{
    color:#667085;
    font-size:12px;
    font-weight:900;
    margin-bottom:7px;
}

.value{
    color:#172033;
    font-size:14px;
    font-weight:900;
    line-height:1.7;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.highlight-type{
    background:linear-gradient(145deg,#f0edff,#ffffff);
    border-color:rgba(109,74,255,.18);
}

.highlight-type .value{
    color:#4f46e5;
    font-size:17px;
}

.withdraw-tools{
    margin-top:18px;
    display:grid;
    grid-template-columns:1fr;
    gap:12px;
    align-items:stretch;
    width:100%;
    max-width:520px;
    margin-left:auto;
    margin-right:0;
    clear:both;
}

.withdraw-tools .withdraw-card{
    min-height:104px;
}

.withdraw-card{
    margin-top:15px;
    background:
        linear-gradient(135deg, rgba(109,74,255,.12), rgba(255,255,255,.92)),
        #fff;
    border:1px solid rgba(109,74,255,.22);
    border-radius:20px;
    padding:13px 15px;
    display:flex;
    align-items:center;
    justify-content:flex-start;
    gap:12px;
    box-shadow:inset 2px 2px 7px rgba(209,217,230,.65), inset -2px -2px 7px #fff;
}

.withdraw-info{
    display:flex;
    align-items:center;
    gap:12px;
}

.withdraw-icon{
    width:52px;
    height:52px;
    border-radius:17px;
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:25px;
    box-shadow:0 12px 22px rgba(109,74,255,.18);
}

.withdraw-title{
    font-size:13px;
    color:#667085;
    font-weight:900;
    margin-bottom:4px;
}

.withdraw-value{
    font-size:24px;
    color:#4f46e5;
    font-weight:900;
    direction:ltr;
    text-align:right;
}

.withdraw-note{
    color:#92400e;
    background:#fffbeb;
    border:1px solid #fde68a;
    min-height:34px;
    padding:6px 10px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    white-space:nowrap;
}

.withdraw-search-card{
    margin-top:0;
    background:
        linear-gradient(135deg, rgba(14,165,233,.10), rgba(255,255,255,.92)),
        #fff;
    border:1px solid rgba(14,165,233,.22);
    padding:11px 14px;
}

.withdraw-search-form{
    display:grid;
    grid-template-columns:1fr 155px;
    grid-template-areas:
        "title title"
        "input result"
        "hint hint";
    gap:6px 8px;
    width:100%;
    align-items:center;
}

.withdraw-search-input{
    grid-area:input;
    width:100%;
    min-height:36px;
    padding:0 10px;
    border-radius:14px;
    border:1px solid #dfe6f0;
    background:#eef1f7;
    color:#172033;
    box-shadow:
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
    font-size:12px;
    font-weight:800;
    outline:none;
}

.withdraw-search-result{
    grid-area:result;
    min-height:36px;
    border-radius:14px;
    padding:6px 8px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    color:#4f46e5;
    font-size:12px;
    font-weight:900;
    direction:ltr;
    text-align:center;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.withdraw-search-hint{
    grid-area:hint;
    color:#667085;
    font-size:10px;
    font-weight:800;
    text-align:center;
    line-height:1.2;
}


.withdraw-search-card .withdraw-icon{
    width:46px;
    height:46px;
    min-width:46px;
    font-size:22px;
}

.withdraw-search-card .withdraw-info{
    width:100%;
}



.withdraw-tools{
    align-items:stretch !important;
}

.withdraw-tools .withdraw-card{
    min-height:104px !important;
    height:104px !important;
}

.withdraw-search-card{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    padding:13px 15px !important;
}

.withdraw-search-card .withdraw-info{
    width:100% !important;
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:12px !important;
}

.withdraw-search-card .withdraw-search-form{
    flex:1 !important;
    display:grid !important;
    grid-template-columns:1fr 145px !important;
    grid-template-areas:
        "title title"
        "input result"
        "hint hint" !important;
    gap:4px 8px !important;
    align-items:center !important;
}

.withdraw-search-card .withdraw-title{
    grid-area:title !important;
    text-align:center !important;
    font-size:12px !important;
    line-height:1.2 !important;
    margin:0 !important;
}

.withdraw-search-card .withdraw-search-input{
    grid-area:input !important;
    min-height:32px !important;
    height:32px !important;
    padding:0 9px !important;
    font-size:11px !important;
    border-radius:12px !important;
}

.withdraw-search-card .withdraw-search-result{
    grid-area:result !important;
    min-height:32px !important;
    height:32px !important;
    padding:5px 7px !important;
    font-size:11px !important;
    border-radius:12px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
}

.withdraw-search-card .withdraw-search-hint{
    grid-area:hint !important;
    font-size:9px !important;
    line-height:1 !important;
    height:12px !important;
    overflow:hidden !important;
}

.withdraw-search-card .withdraw-icon{
    width:48px !important;
    height:48px !important;
    min-width:48px !important;
    font-size:22px !important;
}

@media(max-width:900px){
    .withdraw-search-form{
        grid-template-columns:1fr;
        grid-template-areas:
            "title"
            "input"
            "result"
            "hint";
    }
}

@media(max-width:900px){
    .withdraw-tools{
        grid-template-columns:1fr;
    }
}

.edited-box{
    background:#fffbeb;
    color:#92400e;
    border:1px solid #fde68a;
    border-radius:16px;
    padding:13px 15px;
    margin:14px 0 0;
    line-height:1.9;
    font-weight:900;
    white-space:pre-wrap;
}

.table-wrap{
    width:100%;
    overflow:hidden;
    border-radius:16px;
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
    padding:13px 8px;
    text-align:center;
    font-size:13px;
    white-space:nowrap;
    font-weight:900;
}

.table th:first-child{
    border-radius:0 14px 14px 0;
}

.table th:last-child{
    border-radius:14px 0 0 14px;
}

.table td{
    padding:11px 8px;
    border-bottom:1px solid #dfe6f0;
    vertical-align:middle;
    text-align:center;
    font-size:13px;
    font-weight:800;
    line-height:1.7;
}

.table tr:last-child td{
    border-bottom:none;
}

.note{
    color:#667085;
    font-size:13px;
    font-weight:800;
    line-height:1.8;
}

.history-admin td{
    background:#e0f2fe !important;
    border-bottom:2px solid #bae6fd;
}

.old-value{
    color:#dc2626;
    font-weight:900;
}

.new-value{
    color:#16a34a;
    font-weight:900;
}

.empty-text{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:14px;
    font-weight:900;
    color:#667085;
    line-height:1.9;
}


.toast{
    position:fixed;
    bottom:20px;
    right:20px;
    background:#172033;
    color:#fff;
    padding:12px 16px;
    border-radius:14px;
    font-weight:900;
    display:none;
    z-index:99999;
    box-shadow:0 14px 30px rgba(23,32,51,.20);
}

.toast.success{
    background:#16a34a;
}

.toast.error{
    background:#ef4444;
}

@media(max-width:1000px){
    .grid,
    .grid.supplier-grid{
        grid-template-columns:1fr;
    }

    .table-wrap{
        overflow-x:auto;
    }

    .table{
        min-width:900px;
    }

    .head-top{
        display:block;
    }

    .top-actions{
        align-items:stretch;
        margin-top:15px;
    }

    .action-row{
        justify-content:stretch;
    }

    .btn{
        flex:1;
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

    .action-row{
        display:grid;
        grid-template-columns:1fr;
    }

    .btn{
        width:100%;
    }
}

@page{
    size:A4;
    margin:10mm;
}

@media print{
    body{
        background:#fff;
    }

    .top-actions,
    .toast,
    .btn,
    .withdraw-card{
        display:none !important;
    }

    .container{
        width:100%;
        margin:0;
        padding:0;
    }

    .contract-head,
    .section{
        box-shadow:none;
        border:1px solid #ddd;
        background:#fff;
    }

    .table th{
        background:#ddd !important;
        color:#000 !important;
    }
}

.withdraw-search-card input::-webkit-calendar-picker-indicator{
    opacity:.45;
}



.supplier-contract-alert{
    background:#fff7ed;
    border:1px solid #fed7aa;
    color:#9a3412;
    border-radius:20px;
    padding:16px 18px;
    margin:16px 0 0;
    font-weight:900;
    line-height:1.9;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}
.supplier-contract-alert a{
    display:inline-flex;
    margin-top:8px;
    color:#4f46e5;
    font-weight:900;
    text-decoration:none;
}

</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="contract-head">

        <div class="head-top">

            <div class="title-wrap">
                <h1 class="page-title">📄 <?= e($contractType) ?> رقم #<?= (int)$id ?></h1>

                <p class="page-subtitle">
                    تفاصيل العقد والخصومات والفعاليات والإيجارات وسجل التعديلات.
                </p>

                <div class="head-badges">
                    <span class="badge badge-type"><?= e($contractType) ?></span>
                    <span class="badge badge-status <?= e(statusClass($contract['status'] ?? '')) ?>"><?= e($status) ?></span>
                    <?php if(($contract['status'] ?? '') === 'approved'): ?>
                        <span class="badge <?= $supplierSignedReceived ? 'badge-signed-yes' : 'badge-signed-no' ?>">
                            النسخة الموقعة: <?= $supplierSignedReceived ? 'وصلت' : 'لم تصل' ?>
                        </span>
                    <?php endif; ?>
                </div>

            </div>

            <div class="top-actions">

                <?php if($canReviewAllContracts && $isReview): ?>
                    <div class="action-row">
                        <button class="btn btn-approve action-btn" onclick="updateStatus(<?= (int)$id ?>, 'approve')">
                            موافقة العقد
                        </button>

                        <button class="btn btn-reject action-btn" onclick="updateStatus(<?= (int)$id ?>, 'reject')">
                            رفض العقد
                        </button>
                    </div>
                <?php endif; ?>

                <?php if($is_admin && (($contract['status'] ?? '') === 'approved')): ?>
                    <div class="action-row">
                        <button class="btn btn-withdraw action-btn" onclick="updateStatus(<?= (int)$id ?>, 'withdraw_return')">
                            سحب للتفاوض
                        </button>

                        <button class="btn btn-reject action-btn" onclick="updateStatus(<?= (int)$id ?>, 'withdraw_delete')">
                            إلغاء العقد
                        </button>
                    </div>
                <?php endif; ?>

                <?php if($canUpdateSupplierSignedReceived): ?>
                    <div class="action-row">
                        <?php if(!$supplierSignedReceived): ?>
                            <button class="btn btn-signed action-btn" onclick="updateStatus(<?= (int)$id ?>, 'signed_received')">
                                وصلت النسخة الموقعة
                            </button>
                        <?php else: ?>
                            <button class="btn btn-signed-done action-btn" onclick="updateStatus(<?= (int)$id ?>, 'signed_not_received')">
                                إلغاء استلام النسخة
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="action-row">
                    <button class="btn btn-print" onclick="printFull(<?= (int)$id ?>)">
                        طباعة ملخص VendorCore
                    </button>
                </div>

            </div>

        </div>

                <?php if($showWithdraw2025): ?>
                    <div class="withdraw-tools">

                        <div class="withdraw-card">
                            <div class="withdraw-info">
                                <div class="withdraw-icon">💸</div>
                                <div>
                                    <div class="withdraw-title">مسحوبات عام 2025 من ملف المورد</div>
                                    <div class="withdraw-value"><?= money($withdrawAmount2025) ?> ريال</div>
                                </div>
                            </div>

                        </div>



                    </div>
                <?php endif; ?>

        <?php if($isSupplierContractModel): ?>
            <div class="supplier-contract-alert">
                هذا العقد مسجل على نموذج مورد خارجي
                <?php if($supplierContractFile !== ''): ?>
                    <br><a target="_blank" href="<?= e($supplierContractFile) ?>">عرض / تحميل عقد المورد الرسمي</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if(!empty($edited_text)): ?>
            <div class="edited-box">
                <?= e($edited_text) ?>
            </div>
        <?php endif; ?>

    </div>

    <div class="section">
        <div class="section-title">البيانات الأساسية</div>

        <div class="grid">
            <div class="box highlight-type">
                <div class="label">نوع العقد</div>
                <div class="value"><?= e($contractType) ?></div>
            </div>

            <div class="box">
                <div class="label">نموذج العقد</div>
                <div class="value"><?= $isSupplierContractModel ? 'نموذج مورد خارجي' : 'نموذج VendorCore' ?></div>
            </div>

            <div class="box">
                <div class="label">الحالة</div>
                <div class="value"><?= e($status) ?></div>
            </div>

            <div class="box">
                <div class="label">تاريخ الإنشاء</div>
                <div class="value"><?= e(cleanValue($contract['created_at'] ?? '')) ?></div>
            </div>

            <div class="box">
                <div class="label">فترة السداد</div>
                <div class="value"><?= !empty($contract['payment_period']) ? e($contract['payment_period']) . ' يوم' : '-' ?></div>
            </div>

            <div class="box">
                <div class="label">تاريخ البداية</div>
                <div class="value"><?= e(cleanValue($contract['start_date'] ?? '')) ?></div>
            </div>

            <div class="box">
                <div class="label">تاريخ النهاية</div>
                <div class="value"><?= e(cleanValue($contract['end_date'] ?? '')) ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">بيانات المورد</div>

        <div class="grid supplier-grid">
            <div class="box">
                <div class="label">اسم المورد</div>
                <div class="value"><?= e($contract['supplier_name'] ?? '-') ?></div>
            </div>

            <div class="box">
                <div class="label">المسؤول</div>
                <div class="value"><?= e($contract['company_name'] ?? '-') ?></div>
            </div>

            <div class="box">
                <div class="label">الجوال</div>
                <div class="value"><?= e($contract['supplier_phone'] ?? '-') ?></div>
            </div>
        </div>
    </div>

    <?php if($hasDiscounts): ?>
        <div class="section">
            <div class="section-title">الخصومات</div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>البند</th>
                            <th>القيمة</th>
                            <th>الملاحظات</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if((float)($contract['discount_invoice'] ?? 0) > 0): ?>
                            <tr>
                                <td>خصم الفاتورة</td>
                                <td><?= e($contract['discount_invoice']) ?>%</td>
                                <td><?= e($contract['discount_invoice_note'] ?: '-') ?></td>
                            </tr>
                        <?php endif; ?>

                        <?php if((float)($contract['discount_payment'] ?? 0) > 0): ?>
                            <tr>
                                <td>خصم السداد</td>
                                <td><?= e($contract['discount_payment']) ?>%</td>
                                <td><?= e($contract['discount_payment_note'] ?: '-') ?></td>
                            </tr>
                        <?php endif; ?>

                        <?php if((float)($contract['discount_quarter'] ?? 0) > 0): ?>
                            <tr>
                                <td>خصم 3 شهور</td>
                                <td><?= e($contract['discount_quarter']) ?>%</td>
                                <td><?= e($contract['discount_quarter_note'] ?: '-') ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if(!empty($annualRows)): ?>
        <div class="section">
            <div class="section-title">الخصم السنوي</div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>النسبة</th>
                            <th>الهدف</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach($annualRows as $row): ?>
                            <tr>
                                <td><?= e($row['percent']) ?>%</td>
                                <td><?= e($row['target']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if(!empty($eventRows)): ?>
        <div class="section">
            <div class="section-title">الفعاليات</div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>الاسم</th>
                            <th>القيمة</th>
                            <th>الملاحظات</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach($eventRows as $row): ?>
                            <tr>
                                <td><?= e($row['name'] ?? '-') ?></td>
                                <td><?= money($row['value'] ?? 0) ?> ريال</td>
                                <td class="note"><?= e($row['note'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if(!empty($rentRows)): ?>
        <div class="section">
            <div class="section-title">الإيجارات</div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>الفرع</th>
                            <th>النوع</th>
                            <th>العدد</th>
                            <th>السعر</th>
                            <th>من</th>
                            <th>إلى</th>
                            <th>الإجمالي</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach($rentRows as $row): ?>
                            <tr>
                                <td><?= e($row['branch'] ?? '-') ?></td>
                                <td><?= e($row['type'] ?? '-') ?></td>
                                <td><?= e($row['qty'] ?? '-') ?></td>
                                <td><?= money($row['price'] ?? 0) ?></td>
                                <td><?= e(cleanValue($row['start_date'] ?? '')) ?></td>
                                <td><?= e(cleanValue($row['end_date'] ?? '')) ?></td>
                                <td><?= money($row['total'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr>
                            <td colspan="6" style="font-weight:900;color:#4f46e5;">إجمالي الإيجارات</td>
                            <td style="font-weight:900;color:#166534;"><?= money($rentTotal) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if(!empty($contract['notes'])): ?>
        <div class="section">
            <div class="section-title">ملاحظات</div>
            <div class="empty-text"><?= e($contract['notes']) ?></div>
        </div>
    <?php endif; ?>

    <?php if(!empty($historyRows)): ?>
        <div class="section">
            <div class="section-title">سجل التعديلات</div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>المستخدم</th>
                            <th>التعديل</th>
                            <th>من</th>
                            <th>إلى</th>
                            <th>التاريخ</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach($historyRows as $h): ?>
                            <?php if(($h['field_name'] ?? '') === 'status') continue; ?>

                            <?php
                            
                            $oldRaw = trim((string)($h['old_value'] ?? ''));
                            $newRaw = trim((string)($h['new_value'] ?? ''));

                            $oldIsInitial = in_array($oldRaw, ['', '-', '0', '0.0', '0.00', 'NULL', 'null'], true);

                            
                            $historyTime = !empty($h['created_at']) ? strtotime((string)$h['created_at']) : 0;
                            $contractCreateTime = !empty($contract['created_at']) ? strtotime((string)$contract['created_at']) : 0;

                            $isCreationHistory = (
                                $historyTime > 0 &&
                                $contractCreateTime > 0 &&
                                abs($historyTime - $contractCreateTime) <= 300
                            );

                            if (
                                ($oldIsInitial && $newRaw !== '') ||
                                $isCreationHistory
                            ) {
                                continue;
                            }

                            $is_admin_row = (($h['username'] ?? '') === 'admin');
                            $fieldName = $field_labels[$h['field_name']] ?? $h['field_name'];

                            $old = $h['old_value'];
                            $new = $h['new_value'];

                            if(isset($field_labels[$old])) $old = $field_labels[$old];
                            if(isset($field_labels[$new])) $new = $field_labels[$new];

                            if(in_array($old, ['draft','review','approved','rejected'], true)) $old = statusText($old);
                            if(in_array($new, ['draft','review','approved','rejected'], true)) $new = statusText($new);

                            
                            $oldCompare = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$old)));
                            $newCompare = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$new)));

                            if ($oldCompare === $newCompare) {
                                continue;
                            }
                            ?>

                            <tr class="<?= $is_admin_row ? 'history-admin' : '' ?>">
                                <td><?= e($h['username'] ?? '-') ?></td>
                                <td><?= e($fieldName) ?></td>
                                <td class="old-value"><?= e($old) ?></td>
                                <td class="new-value"><?= e($new) ?></td>
                                <td><?= e(cleanValue($h['created_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<div id="toast" class="toast"></div>

<script>
const supplierWithdrawData = <?= json_encode($supplierWithdrawList, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function formatRiyal(value){
    let num = Number(value || 0);
    return num.toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + " ريال";
}

function setupSupplierWithdrawSearch(){
    const input = document.getElementById("supplierWithdrawSearch");
    const result = document.getElementById("supplierWithdrawResult");

    if(!input || !result){
        return;
    }

    function updateResult(){
        const value = input.value.trim();

        if(!value){
            result.textContent = "اختار مورد";
            return;
        }

        const exact = supplierWithdrawData.find(function(item){
            return String(item.company || "").trim() === value;
        });

        if(exact){
            result.textContent = formatRiyal(exact.phone);
            return;
        }

        const partial = supplierWithdrawData.find(function(item){
            return String(item.company || "").includes(value);
        });

        if(partial){
            result.textContent = formatRiyal(partial.phone) + " — " + partial.company;
            return;
        }

        result.textContent = "غير موجود في ملف الموردين";
    }

    input.addEventListener("input", updateResult);
    input.addEventListener("change", updateResult);
}

document.addEventListener("DOMContentLoaded", setupSupplierWithdrawSearch);

const csrfToken = "<?= e($csrf_token) ?>";

document.addEventListener("keydown", function(e){
    if(e.key === "Escape"){
        window.history.back();
    }
});

function showToast(message, type){
    const toast = document.getElementById("toast");

    toast.innerText = message;
    toast.className = "toast " + (type || "");
    toast.style.display = "block";

    setTimeout(function(){
        toast.style.display = "none";
    }, 2200);
}

function updateStatus(id, action){

    let confirmText = action === "approve"
        ? "تأكيد الموافقة على العقد؟"
        : "تأكيد رفض العقد؟";

    if(action === "signed_received"){
        confirmText = "تأكيد أن المورد رجّع النسخة الموقعة والمختومة؟";
    }

    if(action === "signed_not_received"){
        confirmText = "تأكيد إلغاء علامة استلام النسخة الموقعة؟";
    }

    let reason = "";

    if(action === "withdraw_return" || action === "withdraw_delete"){
        confirmText = action === "withdraw_return"
            ? "تأكيد سحب اعتماد العقد وإرجاعه للتفاوض؟"
            : "تأكيد سحب اعتماد العقد وإلغائه؟";

        reason = prompt(confirmText + "\nاكتب سبب سحب الاعتماد:");

        if(reason === null){
            return;
        }

        reason = reason.trim();

        if(reason.length < 3){
            alert("لازم تكتب سبب واضح لسحب الاعتماد.");
            return;
        }
    }

    if(!confirm(confirmText)){
        return;
    }

    document.querySelectorAll(".action-btn").forEach(function(button){
        button.disabled = true;
    });

    const body = new URLSearchParams();
    body.append("id", id);
    body.append("action", action);
    body.append("csrf_token", csrfToken);
    body.append("reason", reason);

    fetch("view_contract.php?id=" + id, {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Requested-With": "XMLHttpRequest"
        },
        body: body.toString()
    })
    .then(function(res){
        return res.json();
    })
    .then(function(data){

        if(data.success){
            showToast(data.message || "تم التحديث", "success");

            setTimeout(function(){
                location.reload();
            }, 850);
        }else{
            document.querySelectorAll(".action-btn").forEach(function(button){
                button.disabled = false;
            });

            showToast(data.message || "لم يتم التحديث", "error");
        }

    })
    .catch(function(){
        document.querySelectorAll(".action-btn").forEach(function(button){
            button.disabled = false;
        });

        showToast("تعذر الاتصال بالسيرفر", "error");
    });
}

function printFull(id){
    window.open("print_contract.php?id=" + id, "_blank");
}
</script>

</body>
</html>
