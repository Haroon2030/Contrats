<?php
require_once VC_HELPERS . '/auth.php';
if (file_exists(VC_HELPERS . '/disabled_helper.php')) {
    require_once VC_HELPERS . '/disabled_helper.php';
}



date_default_timezone_set('Asia/Riyadh');
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function pa_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function pa_money($value): string { return number_format((float)$value, 2); }
function pa_date($value): string {
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') return '-';
    $ts = strtotime((string)$value);
    return $ts ? date('Y-m-d', $ts) : '-';
}

function pa_due_class($value): string {
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') return 'due-empty';
    $due = date('Y-m-d', strtotime((string)$value));
    $today = date('Y-m-d');
    return ($due <= $today) ? 'due-passed' : 'due-future';
}
function pa_due_label($value): string {
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') return '-';
    $due = date('Y-m-d', strtotime((string)$value));
    $today = date('Y-m-d');
    return ($due <= $today) ? 'يستحق السداد' : 'لا يستحق السداد';
}

function pa_datetime($value): string {
    if (empty($value) || $value === '0000-00-00 00:00:00') return '-';
    $ts = strtotime((string)$value);
    return $ts ? date('H:i d-m-Y', $ts) : '-';
}
function pa_company_type_ar($type): string { return ((string)$type === 'food') ? 'غذائي' : 'لا غذائي'; }
function pa_status_ar($status): string {
    $map = [
        'pending_section_manager' => 'بانتظار مدير القسم',
        'rejected_section_manager' => 'مرفوض من مدير القسم',
        'pending_commercial_manager' => 'بانتظار المدير التجاري',
        'rejected_commercial_manager' => 'مرفوض من المدير التجاري',
        'pending_finance_manager' => 'بانتظار المدير المالي',
        'rejected_finance_manager' => 'مرفوض من المدير المالي',
        'approved_final' => 'معتمد نهائيًا',
        'paid' => 'تم السداد',
        'cancelled' => 'ملغي'
    ];
    return $map[$status] ?? (string)$status;
}
function pa_status_short_ar($status): string {
    $map = [
        'pending_section_manager' => '⏳ قسم',
        'rejected_section_manager' => 'قسم',
        'pending_commercial_manager' => '⏳ تجاري',
        'rejected_commercial_manager' => 'تجاري',
        'pending_finance_manager' => '⏳ مالي',
        'rejected_finance_manager' => 'مالي',
        'approved_final' => '✅ معتمد'
    ];
    return $map[$status] ?? (string)$status;
}
function pa_status_class($status): string {
    if (strpos((string)$status, 'rejected') === 0) return 'rejected';
    if ((string)$status === 'approved_final') return 'approved';
    if ((string)$status === 'paid') return 'paid';
    if ((string)$status === 'cancelled') return 'cancelled';
    return 'pending';
}
function pa_approval_ar($status): string {
    return ['pending'=>'لم يعتمد بعد','approved'=>'موافق','rejected'=>'رافض'][$status] ?? '-';
}
function pa_step_ar($step): string {
    return ['section_manager'=>'مدير القسم','commercial_manager'=>'المدير التجاري','finance_manager'=>'المدير المالي'][$step] ?? (string)$step;
}
function pa_get_settings(VcDb $conn): array {
    $settings = [];
    $res = $conn->query("SELECT setting_key, user_id FROM payment_approval_settings");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $settings[(string)$row['setting_key']] = (int)$row['user_id'];
        }
    }
    if (empty($settings['finance_manager'])) {
        $settings['finance_manager'] = 19; 
    }
    return $settings;
}

function pa_column_exists(VcDb $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function pa_ensure_payment_due_columns(VcDb $conn): void {

    if (!pa_column_exists($conn, 'payment_requests', 'agreed_payment_days')) {
        $conn->query("ALTER TABLE payment_requests ADD COLUMN agreed_payment_days INT NULL DEFAULT NULL AFTER invoice_date");
    }

    if (!pa_column_exists($conn, 'payment_requests', 'payment_due_date')) {
        $conn->query("ALTER TABLE payment_requests ADD COLUMN payment_due_date DATE NULL DEFAULT NULL AFTER agreed_payment_days");
    }

    if (!pa_column_exists($conn, 'payment_request_approvals', 'amount_before_early_discount')) {
        $conn->query("ALTER TABLE payment_request_approvals ADD COLUMN amount_before_early_discount DECIMAL(12,2) NULL DEFAULT NULL AFTER approved_amount");
    }

    if (!pa_column_exists($conn, 'payment_request_approvals', 'early_payment_discount_percent')) {
        $conn->query("ALTER TABLE payment_request_approvals ADD COLUMN early_payment_discount_percent DECIMAL(5,2) NULL DEFAULT NULL AFTER amount_before_early_discount");
    }

    if (!pa_column_exists($conn, 'payment_request_approvals', 'early_payment_discount_amount')) {
        $conn->query("ALTER TABLE payment_request_approvals ADD COLUMN early_payment_discount_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER early_payment_discount_percent");
    }
}

function pa_percent($value): string {
    if ($value === null || $value === '') return '-';
    $n = (float)$value;
    if ($n == 0.0) return '0%';
    return rtrim(rtrim(number_format($n, 2), '0'), '.') . '%';
}

function pa_early_discount_values(float $amountBeforeDiscount, float $discountPercent): array {
    if ($discountPercent < 0) $discountPercent = 0;
    if ($discountPercent > 100) $discountPercent = 100;

    $discountAmount = round($amountBeforeDiscount * ($discountPercent / 100), 2);
    $netAmount = round($amountBeforeDiscount - $discountAmount, 2);
    if ($netAmount < 0) $netAmount = 0;

    return [$discountPercent, $discountAmount, $netAmount];
}

function pa_payment_amount_lines(float $approvedAmount, float $discountPercent = 0.0, float $discountAmount = 0.0): string {
    $lines = "المبلغ المعتمد حتى الآن: " . pa_money($approvedAmount);
    if ($discountPercent > 0 || $discountAmount > 0) {
        $lines .= "\nنسبة السداد المعجل: " . rtrim(rtrim(number_format($discountPercent, 2), '0'), '.') . "%";
        $lines .= "\nإجمالي التخفيض: " . pa_money($discountAmount);
    }
    return $lines;
}


function pa_get_previous_financials(VcDb $conn, int $requestId): array {
    $defaults = [
        'amount_before' => null,
        'percent' => null,
        'discount_amount' => null,
        'approved_amount' => null,
    ];

    $stmt = $conn->prepare("\n        SELECT amount_before_early_discount, early_payment_discount_percent, early_payment_discount_amount, approved_amount\n        FROM payment_request_approvals\n        WHERE request_id = ?\n          AND status = 'approved'\n          AND acted_at IS NOT NULL\n        ORDER BY CASE step_key\n            WHEN 'finance_manager' THEN 3\n            WHEN 'commercial_manager' THEN 2\n            WHEN 'section_manager' THEN 1\n            ELSE 0\n        END DESC\n        LIMIT 1\n    ");
    if (!$stmt) {
        return $defaults;
    }

    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return $defaults;
    }

    $amountBefore = $row['amount_before_early_discount'] ?? null;
    if ($amountBefore === null || $amountBefore === '' || (float)$amountBefore <= 0) {
        $amountBefore = $row['approved_amount'] ?? null;
    }

    $defaults['amount_before'] = ($amountBefore !== null && $amountBefore !== '') ? (float)$amountBefore : null;
    $defaults['percent'] = ($row['early_payment_discount_percent'] !== null && $row['early_payment_discount_percent'] !== '') ? (float)$row['early_payment_discount_percent'] : null;
    $defaults['discount_amount'] = ($row['early_payment_discount_amount'] !== null && $row['early_payment_discount_amount'] !== '') ? (float)$row['early_payment_discount_amount'] : null;
    $defaults['approved_amount'] = ($row['approved_amount'] !== null && $row['approved_amount'] !== '') ? (float)$row['approved_amount'] : null;

    return $defaults;
}
function pa_section_manager_for_type(array $settings, string $companyType): int {
    return $companyType === 'food'
        ? (int)($settings['food_section_manager'] ?? 0)
        : (int)($settings['non_food_section_manager'] ?? 0);
}
function pa_user_has_page(VcDb $conn, int $userId, string $pageName): bool {
    $stmt = $conn->prepare("SELECT 1 FROM user_permissions up JOIN pages p ON p.id=up.page_id WHERE up.user_id=? AND p.name=? AND p.status=1 LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param("is", $userId, $pageName);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok;
}
function pa_disabled_hook(VcDb $conn, int $userId, string $title, string $message, string $link, string $type, int $relatedId): void {
    return;
}
function pa_upsert_approval(
    VcDb $conn,
    int $requestId,
    string $step,
    int $approverId,
    string $status='pending',
    string $note='',
    ?float $approvedAmount=null,
    bool $acted=false,
    ?float $amountBeforeEarlyDiscount=null,
    ?float $earlyDiscountPercent=null,
    ?float $earlyDiscountAmount=null
): void {
    $actedSql = $acted ? "NOW()" : "NULL";
    $stmt = $conn->prepare("INSERT INTO payment_request_approvals
        (request_id, step_key, approver_user_id, status, note, approved_amount, amount_before_early_discount, early_payment_discount_percent, early_payment_discount_amount, acted_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, {$actedSql}, NOW())
        ON DUPLICATE KEY UPDATE
            approver_user_id = VALUES(approver_user_id),
            status = VALUES(status),
            note = VALUES(note),
            approved_amount = VALUES(approved_amount),
            amount_before_early_discount = VALUES(amount_before_early_discount),
            early_payment_discount_percent = VALUES(early_payment_discount_percent),
            early_payment_discount_amount = VALUES(early_payment_discount_amount),
            acted_at = VALUES(acted_at)");
    if (!$stmt) { throw new Exception('فشل تجهيز حفظ الموافقة: ' . $conn->error); }
    $stmt->bind_param(
        "isissdddd",
        $requestId,
        $step,
        $approverId,
        $status,
        $note,
        $approvedAmount,
        $amountBeforeEarlyDiscount,
        $earlyDiscountPercent,
        $earlyDiscountAmount
    );
    $stmt->execute();
    $stmt->close();
}
function pa_can_act(array $row, int $uid, array $settings, bool $isFinanceManager = false, bool $isCommercialManager = false): bool {
    $status = (string)($row['status'] ?? '');
    $companyType = (string)($row['company_type'] ?? '');

    if ($status === 'pending_section_manager' && $uid === pa_section_manager_for_type($settings, $companyType)) return true;
    if ($status === 'pending_commercial_manager' && ($uid === (int)($settings['commercial_manager'] ?? 0) || $isCommercialManager)) return true;

    
    if ($status === 'pending_finance_manager' && ($uid === (int)($settings['finance_manager'] ?? 19) || $isFinanceManager)) return true;

    return false;
}
function pa_current_step(array $row, int $uid, array $settings, bool $isFinanceManager = false, bool $isCommercialManager = false): array {
    $status = (string)$row['status'];
    $companyType = (string)$row['company_type'];
    if ($status === 'pending_section_manager' && $uid === pa_section_manager_for_type($settings, $companyType)) {
        return ['section_manager', 'pending_commercial_manager', 'rejected_section_manager', (int)$settings['commercial_manager'], 'commercial_manager'];
    }
    if ($status === 'pending_commercial_manager' && ($uid === (int)$settings['commercial_manager'] || $isCommercialManager)) {
        return ['commercial_manager', 'pending_finance_manager', 'rejected_commercial_manager', (int)$settings['finance_manager'], 'finance_manager'];
    }
    if ($status === 'pending_finance_manager' && ($uid === (int)$settings['finance_manager'] || $isFinanceManager)) {
        return ['finance_manager', 'approved_final', 'rejected_finance_manager', 0, ''];
    }
    return ['', '', '', 0, ''];
}
function pa_action_hint(array $row, int $uid, array $settings, bool $isFinanceManager = false, bool $isCommercialManager = false): string {
    $status = (string)$row['status'];
    if (pa_can_act($row, $uid, $settings, $isFinanceManager, $isCommercialManager)) return 'مطلوب منك إجراء على هذا الطلب';
    if ($status === 'approved_final') return 'معتمد نهائيًا وجاهز للطباعة';
    if (strpos($status, 'rejected') === 0) return 'الطلب مرفوض';
    return 'للمتابعة فقط، الإجراء عند وصول الطلب لمرحلتك';
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];

$stmtUser = $conn->prepare("SELECT id, username, role, is_admin, job_role FROM users WHERE id=? LIMIT 1");
$stmtUser->bind_param("i", $uid);
$stmtUser->execute();
$currentUser = $stmtUser->get_result()->fetch_assoc() ?: [];
$stmtUser->close();

pa_ensure_payment_due_columns($conn);

$settings = pa_get_settings($conn);
$foodManagerId = (int)($settings['food_section_manager'] ?? 0);
$nonFoodManagerId = (int)($settings['non_food_section_manager'] ?? 0);
$commercialManagerId = (int)($settings['commercial_manager'] ?? 0);
$financeManagerId = (int)($settings['finance_manager'] ?? 19);
if ($financeManagerId <= 0) {
    $financeManagerId = 19;
}

$currentJobRole = (string)($currentUser['job_role'] ?? '');
$isAdmin = ((int)($currentUser['is_admin'] ?? 0) === 1) || (($currentUser['role'] ?? '') === 'admin') || ($currentJobRole === 'admin');
$isSectionManager = in_array($uid, [$foodManagerId, $nonFoodManagerId], true);
$isCommercialManager = ($uid === $commercialManagerId) || ($currentJobRole === 'commercial_manager');
$isFinanceManager = ($uid === $financeManagerId)
    || ($uid === 19)
    || in_array($currentJobRole, ['finance_manager','financial_manager','finance','accounts_manager','accounting_manager'], true);
if ($isFinanceManager) {
    
    $settings['finance_manager'] = $uid;
    $financeManagerId = $uid;
} else {
    $settings['finance_manager'] = $financeManagerId;
}
$isAccountant = ($currentJobRole === 'accountant');
$canAccess = $isAdmin || $isSectionManager || $isCommercialManager || $isFinanceManager || pa_user_has_page($conn, $uid, 'payment_approvals');
if (!$canAccess) { http_response_code(403); die('❌ ليس لديك صلاحية الدخول إلى اعتماد طلبات السداد'); }

$success = '';
$error = '';
if (!empty($_SESSION['pa_flash_success'])) {
    $success = (string)$_SESSION['pa_flash_success'];
    unset($_SESSION['pa_flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_request') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { die('طلب غير صالح'); }

    $deleteRequestId = (int)($_POST['request_id'] ?? 0);

    if (!$isAdmin) {
        $error = 'الحذف متاح للأدمن فقط.';
    } elseif ($deleteRequestId <= 0) {
        $error = 'رقم طلب السداد غير صحيح.';
    } else {
        $stmtCheck = $conn->prepare("SELECT id, supplier_name, voucher_number FROM payment_requests WHERE id=? LIMIT 1");
        if (!$stmtCheck) {
            $error = 'تعذر تجهيز الحذف: ' . $conn->error;
        } else {
            $stmtCheck->bind_param("i", $deleteRequestId);
            $stmtCheck->execute();
            $deleteRow = $stmtCheck->get_result()->fetch_assoc();
            $stmtCheck->close();

            if (!$deleteRow) {
                $error = 'طلب السداد غير موجود أو تم حذفه بالفعل.';
            } else {
                $conn->begin_transaction();
                try {
                    $stmtDelApprovals = $conn->prepare("DELETE FROM payment_request_approvals WHERE request_id=?");
                    if (!$stmtDelApprovals) { throw new Exception('تعذر حذف سجل الاعتماد: ' . $conn->error); }
                    $stmtDelApprovals->bind_param("i", $deleteRequestId);
                    $stmtDelApprovals->execute();
                    $stmtDelApprovals->close();

                    $stmtDelRequest = $conn->prepare("DELETE FROM payment_requests WHERE id=? LIMIT 1");
                    if (!$stmtDelRequest) { throw new Exception('تعذر حذف طلب السداد: ' . $conn->error); }
                    $stmtDelRequest->bind_param("i", $deleteRequestId);
                    $stmtDelRequest->execute();
                    $stmtDelRequest->close();

                    $conn->commit();
                    $_SESSION['pa_flash_success'] = 'تم حذف طلب السداد رقم ' . (string)($deleteRow['voucher_number'] ?? $deleteRequestId) . ' بنجاح.';
                    $redirectStatus = urlencode((string)($_GET['status'] ?? 'all'));
                    $redirectQ = urlencode((string)($_GET['q'] ?? ''));
                    header('Location: payment_approvals.php?status=' . $redirectStatus . '&q=' . $redirectQ);
                    exit;
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = 'حدث خطأ أثناء الحذف: ' . $e->getMessage();
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approval_action') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { die('طلب غير صالح'); }
    $requestId = (int)($_POST['request_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $approvedAmountRaw = trim((string)($_POST['approved_amount'] ?? ''));
    $approvedAmountInput = str_replace(',', '', $approvedAmountRaw);
    $earlyDiscountPercentRaw = trim((string)($_POST['early_payment_discount_percent'] ?? ''));
    $earlyDiscountPercentInput = str_replace(',', '', $earlyDiscountPercentRaw);

    $amountBeforeEarlyDiscount = 0.0;
    $earlyDiscountPercent = 0.0;
    $earlyDiscountAmount = 0.0;
    $approvedAmount = 0.0;

    if ($requestId <= 0 || !in_array($decision, ['approve','reject'], true)) {
        $error = 'طلب غير صحيح.';
    } else {
        $stmtReq = $conn->prepare("SELECT * FROM payment_requests WHERE id=? LIMIT 1");
        $stmtReq->bind_param("i", $requestId);
        $stmtReq->execute();
        $request = $stmtReq->get_result()->fetch_assoc();
        $stmtReq->close();

        if (!$request) {
            $error = 'طلب السداد غير موجود.';
        } else {
            [$currentStep, $nextStatus, $rejectStatus, $nextApproverId, $nextStep] = pa_current_step($request, $uid, $settings, $isFinanceManager, $isCommercialManager);

            if ($decision === 'approve') {
                $previousFinancials = pa_get_previous_financials($conn, $requestId);
                $defaultAmountBefore = (float)($previousFinancials['amount_before'] ?? $request['final_amount'] ?? $request['amount_required'] ?? 0);
                if ($defaultAmountBefore <= 0) {
                    $defaultAmountBefore = (float)($request['amount_required'] ?? 0);
                }

                $defaultPercent = (float)($previousFinancials['percent'] ?? 0);

                $amountBeforeEarlyDiscount = is_numeric($approvedAmountInput)
                    ? round((float)$approvedAmountInput, 2)
                    : round($defaultAmountBefore, 2);

                $earlyDiscountPercent = ($earlyDiscountPercentRaw === '')
                    ? $defaultPercent
                    : (is_numeric($earlyDiscountPercentInput) ? (float)$earlyDiscountPercentInput : 0.0);

                [$earlyDiscountPercent, $earlyDiscountAmount, $approvedAmount] = pa_early_discount_values($amountBeforeEarlyDiscount, $earlyDiscountPercent);
            }

            if ($currentStep === '') {
                $error = 'لا يمكن تنفيذ هذا الإجراء في المرحلة الحالية أو ليس الطلب مطلوبًا منك الآن.';
            } elseif ($decision === 'approve' && $amountBeforeEarlyDiscount <= 0) {
                $error = 'اكتب المبلغ قبل الخصم بشكل صحيح.';
            } elseif ($decision === 'approve' && ($earlyDiscountPercent < 0 || $earlyDiscountPercent > 100)) {
                $error = 'نسبة السداد المعجل يجب أن تكون من 0 إلى 100.';
            } elseif ($decision === 'approve' && $approvedAmount <= 0) {
                $error = 'المبلغ بعد الخصم يجب أن يكون أكبر من صفر.';
            } else {
                $supplierName = (string)$request['supplier_name'];
                $createdBy = (int)$request['created_by'];
                $conn->begin_transaction();
                try {
                    if ($decision === 'reject') {
                        pa_upsert_approval($conn, $requestId, $currentStep, $uid, 'rejected', $note, null, true);

                        
                        if ($currentStep === 'section_manager') {
                            if ($nextApproverId <= 0 || $nextStep === '') {
                                throw new Exception('إعدادات المدير التجاري غير مكتملة.');
                            }

                            pa_upsert_approval($conn, $requestId, $nextStep, $nextApproverId, 'pending', '', null, false);

                            $currentFinal = (float)($request['final_amount'] ?? $request['amount_required']);
                            if ($currentFinal <= 0) {
                                $currentFinal = (float)$request['amount_required'];
                            }

                            $stmt = $conn->prepare("UPDATE payment_requests SET status=?, final_amount=?, updated_at=NOW() WHERE id=? LIMIT 1");
                            $stmt->bind_param("sdi", $nextStatus, $currentFinal, $requestId);
                            $stmt->execute();
                            $stmt->close();

                            pa_disabled_hook(
                                $conn,
                                $nextApproverId,
                                'طلب سداد يحتاج قرارك',
                                'طلب سداد رقم #' . $requestId . "\n" .
                                'المورد: ' . $supplierName . "\n" .
                                'ملاحظة مدير القسم: ' . ($note !== '' ? $note : '-') . "\n" .
                                'يرجى الدخول لاتخاذ قرارك كمدير تجاري.',
                                'payment_approvals.php?view=' . $requestId,
                                'payment_request_section_rejected',
                                $requestId
                            );

                            $conn->commit();
                            $success = 'تم تسجيل رفض مدير القسم وتم تحويل الطلب للمدير التجاري.';
                        } else {
                            $stmt = $conn->prepare("UPDATE payment_requests SET status=?, updated_at=NOW() WHERE id=? LIMIT 1");
                            $stmt->bind_param("si", $rejectStatus, $requestId);
                            $stmt->execute();
                            $stmt->close();

                            $rejectMessage = 'تم رفض طلب السداد رقم #' . $requestId . "\n"
                                . 'المورد: ' . $supplierName . "\n"
                                . 'ملاحظة الرفض: ' . ($note !== '' ? $note : '-');
                            pa_disabled_hook($conn, $createdBy, 'تم رفض طلب سداد', $rejectMessage, 'payment_approvals.php?view=' . $requestId, 'payment_request_rejected', $requestId);
                            $conn->commit();
                            $success = 'تم رفض الطلب وتسجيل الملاحظة.';
                        }
                    } else {
                        pa_upsert_approval($conn, $requestId, $currentStep, $uid, 'approved', $note, $approvedAmount, true, $amountBeforeEarlyDiscount, $earlyDiscountPercent, $earlyDiscountAmount);
                        if ($currentStep === 'finance_manager') {
                            $stmt = $conn->prepare("UPDATE payment_requests SET status='approved_final', final_amount=?, final_approved_by=?, final_approved_at=NOW(), updated_at=NOW() WHERE id=? LIMIT 1");
                            $stmt->bind_param("dii", $approvedAmount, $uid, $requestId);
                            $stmt->execute();
                            $stmt->close();
                            $finalApprovedMessage = 'تم اعتماد طلب السداد رقم #' . $requestId . "\n"
                                . 'المورد: ' . $supplierName . "\n"
                                . 'المبلغ النهائي المعتمد: ' . pa_money($approvedAmount);
                            if ($earlyDiscountPercent > 0 || $earlyDiscountAmount > 0) {
                                $finalApprovedMessage .= "\n" . 'نسبة السداد المعجل: ' . rtrim(rtrim(number_format($earlyDiscountPercent, 2), '0'), '.') . '%';
                                $finalApprovedMessage .= "\n" . 'إجمالي التخفيض: ' . pa_money($earlyDiscountAmount);
                            }
                            $finalApprovedMessage .= "\n" . 'جاهز للطباعة.';
                            pa_disabled_hook($conn, $createdBy, 'تم اعتماد طلب السداد نهائيًا', $finalApprovedMessage, 'payment_approvals.php?view=' . $requestId . '&status=approved_final', 'payment_request_final_approved', $requestId);
                            $conn->commit();
                            $success = 'تم الاعتماد النهائي. الطلب جاهز للطباعة.';
                        } else {
                            if ($nextApproverId <= 0 || $nextStep === '') { throw new Exception('إعدادات مسار الاعتماد غير مكتملة.'); }
                            pa_upsert_approval($conn, $requestId, $nextStep, $nextApproverId, 'pending', '', null, false);
                            $stmt = $conn->prepare("UPDATE payment_requests SET status=?, final_amount=?, updated_at=NOW() WHERE id=? LIMIT 1");
                            $stmt->bind_param("sdi", $nextStatus, $approvedAmount, $requestId);
                            $stmt->execute();
                            $stmt->close();
                            $nextApprovalMessage = 'طلب سداد رقم #' . $requestId . "\n"
                                . 'المورد: ' . $supplierName . "\n"
                                . pa_payment_amount_lines($approvedAmount, $earlyDiscountPercent, $earlyDiscountAmount) . "\n"
                                . 'يرجى الدخول للموافقة أو الرفض.';
                            pa_disabled_hook($conn, $nextApproverId, 'طلب سداد بانتظار موافقتك', $nextApprovalMessage, 'payment_approvals.php?view=' . $requestId, 'payment_request_approval', $requestId);
                            $conn->commit();
                            $success = 'تمت الموافقة وتم تحويل الطلب للمرحلة التالية.';
                        }
                    }
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = 'حدث خطأ أثناء حفظ الإجراء: ' . $e->getMessage();
                }
            }
        }
    }
    if ($success !== '' && $error === '') {
        $_SESSION['pa_flash_success'] = $success;
        $redirectStatus = urlencode((string)($_GET['status'] ?? 'active'));
        $redirectQ = urlencode((string)($_GET['q'] ?? ''));
        header('Location: payment_approvals.php?view=' . (int)$requestId . '&status=' . $redirectStatus . '&q=' . $redirectQ);
        exit;
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'active');
if (in_array($statusFilter, ['rejected_commercial_manager','rejected_finance_manager'], true)) {
    $statusFilter = 'rejected';
}
$viewId = (int)($_GET['view'] ?? 0);
$pg = vcPaginationState();
$totalRows = 0;
$totalPages = 1;
$page = 1;
$where = [];
$params = [];
$types = '';


if ($isAdmin || $isCommercialManager || $isFinanceManager) {
    
} elseif ($uid === $foodManagerId && $uid === $nonFoodManagerId) {
    $where[] = "pr.company_type IN ('food','non_food')";
} elseif ($uid === $foodManagerId) {
    $where[] = "pr.company_type = 'food'";
} elseif ($uid === $nonFoodManagerId) {
    $where[] = "pr.company_type = 'non_food'";
} else {
    $where[] = "pr.created_by = ?";
    $params[] = $uid;
    $types .= 'i';
}

if ($viewId > 0) {
    
    $where[] = "pr.id = ?";
    $params[] = $viewId;
    $types .= 'i';
} else {
    if ($statusFilter === 'active' || $statusFilter === '') {
        
        $where[] = "pr.status IN ('pending_section_manager','pending_commercial_manager','pending_finance_manager')";
    } elseif ($statusFilter === 'approved_final') {
        $where[] = "pr.status = 'approved_final'";
    } elseif ($statusFilter === 'rejected') {
        
        $where[] = "pr.status IN ('rejected_section_manager','rejected_commercial_manager','rejected_finance_manager')";
    } elseif ($statusFilter !== 'all') {
        
        $where[] = "pr.status IN ('pending_section_manager','pending_commercial_manager','pending_finance_manager')";
    }

    if ($q !== '') {
        $where[] = "(pr.supplier_name LIKE ? OR pr.voucher_number LIKE ? OR CAST(pr.id AS CHAR) LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= 'sss';
    }
}

$whereSql = $where ? " WHERE " . implode(" AND ", $where) : "";

if ($viewId <= 0) {
    $countSql = "SELECT COUNT(*) AS c FROM payment_requests pr" . $whereSql;
    $stmtCount = $conn->prepare($countSql);
    if ($params) {
        $stmtCount->bind_param($types, ...$params);
    }
    $stmtCount->execute();
    $totalRows = (int)($stmtCount->get_result()->fetch_assoc()['c'] ?? 0);
    $stmtCount->close();

    $totalPages = vcPaginationTotalPages($totalRows, $pg['per_page']);
    $page = min($pg['page'], $totalPages);
}

$sql = "SELECT pr.*, u.username AS created_by_name,
    sm.username AS section_user_name, s.status AS section_status, s.note AS section_note, s.approved_amount AS section_amount, s.amount_before_early_discount AS section_before_early_discount, s.early_payment_discount_percent AS section_early_discount_percent, s.early_payment_discount_amount AS section_early_discount_amount, s.acted_at AS section_acted_at,
    cm.username AS commercial_user_name, c.status AS commercial_status, c.note AS commercial_note, c.approved_amount AS commercial_amount, c.amount_before_early_discount AS commercial_before_early_discount, c.early_payment_discount_percent AS commercial_early_discount_percent, c.early_payment_discount_amount AS commercial_early_discount_amount, c.acted_at AS commercial_acted_at,
    fm.username AS finance_user_name, f.status AS finance_status, f.note AS finance_note, f.approved_amount AS finance_amount, f.amount_before_early_discount AS finance_before_early_discount, f.early_payment_discount_percent AS finance_early_discount_percent, f.early_payment_discount_amount AS finance_early_discount_amount, f.acted_at AS finance_acted_at
FROM payment_requests pr
LEFT JOIN users u ON u.id = pr.created_by
LEFT JOIN payment_request_approvals s ON s.request_id = pr.id AND s.step_key='section_manager'
LEFT JOIN users sm ON sm.id = s.approver_user_id
LEFT JOIN payment_request_approvals c ON c.request_id = pr.id AND c.step_key='commercial_manager'
LEFT JOIN users cm ON cm.id = c.approver_user_id
LEFT JOIN payment_request_approvals f ON f.request_id = pr.id AND f.step_key='finance_manager'
LEFT JOIN users fm ON fm.id = f.approver_user_id";

$sql .= $whereSql;
$sql .= " ORDER BY pr.id DESC";

$dataParams = $params;
$dataTypes = $types;
if ($viewId <= 0) {
    [$dataParams, $dataTypes] = vcPaginationBindLimit($params, $types, $pg['limit'], ($page - 1) * $pg['per_page']);
    $sql .= " LIMIT ? OFFSET ?";
}

$stmt = $conn->prepare($sql);
if ($dataParams) { $stmt->bind_param($dataTypes, ...$dataParams); }
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function pa_print_allowed(array $currentUser, int $uid, int $financeManagerId, bool $isAdmin): bool {
    $job = (string)($currentUser['job_role'] ?? '');
    $role = (string)($currentUser['role'] ?? '');
    return $isAdmin || $role === 'admin' || in_array($job, ['admin','accountant','commercial_manager'], true) || $uid === $financeManagerId;
}
$canPrintRole = pa_print_allowed($currentUser, $uid, $financeManagerId, $isAdmin);

function pa_can_print_request(array $row, array $currentUser, int $uid, int $financeManagerId, bool $isAdmin, bool $isCommercialManager, bool $isFinanceManager): bool {
    if ((string)($row['status'] ?? '') !== 'approved_final') {
        return false;
    }

    $job = (string)($currentUser['job_role'] ?? '');
    $role = (string)($currentUser['role'] ?? '');

    if ($isAdmin || $role === 'admin' || $isCommercialManager || $isFinanceManager || $uid === $financeManagerId) {
        return true;
    }

    if ($job === 'accountant' && (int)($row['created_by'] ?? 0) === $uid) {
        return true;
    }

    return false;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#6d4aff">
<meta property="og:site_name" content="VendorCore">
<meta property="og:title" content="اعتماد طلبات السداد | VendorCore">
<meta property="og:description" content="طلب سداد">
<meta property="og:type" content="website">

<meta property="og:image:alt" content="VendorCore">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="اعتماد طلبات السداد | VendorCore">
<meta name="twitter:description" content="طلب سداد">
<title>اعتماد طلبات السداد</title>
<style>
body{margin:0;background:#eef2f7;font-family:"Tahoma",Arial,sans-serif;color:#172033}.container{width:min(1360px,calc(100% - 32px));margin:22px auto}.page-head{background:#fff;border-radius:22px;padding:24px;text-align:center;box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;margin-bottom:18px}.page-title{margin:0 0 8px;font-size:26px;font-weight:900}.page-subtitle{margin:0;color:#667085;font-weight:800}.alert{padding:13px 16px;border-radius:14px;margin-bottom:12px;font-weight:900}.alert-success{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}.alert-error{background:#fff1f2;color:#b42318;border:1px solid #fecdd3}.filters{background:#fff;border-radius:18px;padding:12px;display:grid;grid-template-columns:1fr 230px 110px 110px;gap:10px;margin-bottom:14px}.filters input,.filters select,.field input,.field textarea{border:1px solid #cbd5e1;background:#f8fafc;border-radius:13px;padding:0 14px;height:46px;font-weight:800;box-sizing:border-box;width:100%}.field textarea{height:46px;padding-top:12px;resize:none;line-height:1.4}.btn{height:42px;border:0;border-radius:12px;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap}.primary{background:#4f46e5;color:#fff}.muted{background:#e2e8f0;color:#172033}.approve{background:#16a34a;color:#fff}.reject{background:#ef4444;color:#fff}.print{background:#0ea5e9;color:#fff}.danger{background:#ef4444;color:#fff}.table-wrap{background:#fff;border-radius:20px;overflow:visible;box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff}.table{width:100%;border-collapse:separate;border-spacing:0;min-width:0;table-layout:fixed}.table th{background:#f1f5f9;color:#334155;padding:10px 6px;text-align:center;font-size:12px;border-bottom:1px solid #dbe3ef;white-space:normal;line-height:1.45}.table td{padding:10px 6px;border-bottom:1px solid #edf2f7;font-size:12px;font-weight:800;vertical-align:middle;text-align:center;white-space:normal;line-height:1.55;word-break:break-word}.table tr.row-rejected td{background:#fff7f7}.table tr.row-approved td{background:#f7fff9}.table td:nth-child(2){text-align:right;white-space:normal}.badge{display:inline-flex;border-radius:999px;padding:7px 11px;font-weight:900;font-size:12px;white-space:nowrap;align-items:center;justify-content:center}.badge.pending{background:#fffbeb;color:#92400e}.badge.approved{background:#ecfdf3;color:#166534}.badge.rejected{background:#fff1f2;color:#b42318}.badge.paid{background:#eff6ff;color:#1d4ed8}.badge.cancelled{background:#f1f5f9;color:#475569}.view-card{background:#fff;border-radius:22px;padding:18px;margin-bottom:16px;box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff}.view-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;border-bottom:1px solid #e2e8f0;padding-bottom:14px;margin-bottom:14px}.view-title{font-size:22px;font-weight:900}.meta{font-size:12px;color:#64748b;font-weight:900;margin-top:5px}.grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}.amount-highlight{margin:12px 0;background:#f5f3ff;border:2px solid #8b5cf6;border-radius:16px;padding:14px;text-align:center}.amount-highlight span{display:block;font-size:12px;color:#4c1d95;font-weight:900;margin-bottom:6px}.amount-highlight b{display:block;font-size:24px;color:#4f46e5;font-weight:900}.action-final-highlight{margin:12px 0 10px;background:#f5f3ff}.two-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:10px 0}.balances-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:10px 0}.suggested-positive b{color:#16a34a!important}.suggested-negative b{color:#dc2626!important}.suggested-zero b{color:#334155!important}.info{background:#f8fafc;border:1px solid #e2e8f0;border-radius:15px;padding:11px}.info span{display:block;font-size:11px;color:#64748b;font-weight:900;margin-bottom:6px}.info b{display:block;font-size:14px;color:#172033;line-height:1.6}.important{background:#f5f3ff;border-color:#c4b5fd}.important b{color:#4f46e5;font-size:18px}.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px}.step{background:#f8fafc;border:1px solid #e2e8f0;border-radius:15px;padding:11px}.step h4{margin:0 0 8px;font-size:14px;font-weight:900;color:#4f46e5}.step p{margin:4px 0;color:#475569;font-weight:800;font-size:12px;line-height:1.7}.note-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:10px;margin-top:10px;color:#9a3412;font-weight:900;line-height:1.8}.action-box{margin-top:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:12px}.rejected-status-banner{margin-top:14px;background:#fff1f2;border:2px solid #ef4444;color:#991b1b;border-radius:16px;padding:18px 20px;text-align:center;font-weight:900;font-size:18px;line-height:1.8;box-shadow:0 8px 18px rgba(239,68,68,.10)}.rejected-status-banner small{display:block;font-size:13px;color:#b42318;margin-top:4px;font-weight:900}.action-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;align-items:end}
.action-row .note-field{grid-column:1 / -1}
.action-row .note-field textarea{height:46px;min-height:46px;resize:none;padding:12px 14px;line-height:1.4;font-size:13px;font-weight:800}.action-buttons{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.empty{padding:28px;text-align:center;font-weight:900;color:#667085;background:#fff;border-radius:20px;border:1px solid #e2e8f0}.small{font-size:11px;color:#64748b;font-weight:900}.due-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:7px 11px;font-weight:900;font-size:12px;line-height:1.4}.due-passed{background:#ecfdf3!important;color:#166534!important;border:1px solid #86efac!important}.due-future{background:#fff1f2!important;color:#b42318!important;border:1px solid #fca5a5!important}.due-empty{background:#f1f5f9!important;color:#475569!important;border:1px solid #e2e8f0!important}.due-note{display:block;margin-top:5px;font-size:11px;font-weight:900}.discount-preview{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:10px}.discount-preview .info b{font-size:16px}.discount-net{background:#ecfdf3;border-color:#86efac}.discount-net b{color:#166534!important}.discount-save{background:#fff7ed;border-color:#fed7aa}.discount-save b{color:#c2410c!important}
@media(max-width:900px){.filters{grid-template-columns:1fr}.grid{grid-template-columns:1fr 1fr}.two-grid{grid-template-columns:1fr}.balances-grid{grid-template-columns:1fr}.steps{grid-template-columns:1fr}.action-row{grid-template-columns:1fr}.discount-preview{grid-template-columns:1fr}}@media(max-width:560px){.grid{grid-template-columns:1fr}.view-head{flex-direction:column}}
</style>
</head>
<body>
<?php include VC_VIEWS . '/layouts/header.php'; ?>
<div class="container">
    <div class="page-head">
        <h1 class="page-title">✅ اعتماد طلبات السداد</h1>
    </div>

    <?php if($success): ?><div class="alert alert-success"><?= pa_e($success) ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-error"><?= pa_e($error) ?></div><?php endif; ?>

    <form class="filters" method="GET">
        <input type="text" name="q" value="<?= pa_e($q) ?>" placeholder="بحث بالمورد / رقم السند / رقم الطلب">
        <select name="status">
            <option value="all" <?= $statusFilter==='all'?'selected':'' ?>>الكل</option>
            <option value="active" <?= ($statusFilter==='active' || $statusFilter==='')?'selected':'' ?>>لم تنته من التعميد</option>
            <option value="approved_final" <?= $statusFilter==='approved_final'?'selected':'' ?>>المعتمدة نهائيًا</option>
            <option value="rejected" <?= $statusFilter==='rejected'?'selected':'' ?>>المرفوضة</option>
        </select>
        <button class="btn primary" type="submit">تطبيق</button>
        <a class="btn muted" href="payment_approvals.php">مسح</a>
    </form>

    <?php if($viewId > 0 && !empty($requests)): $row = $requests[0]; ?>
        <?php
            $canAct = pa_can_act($row, $uid, $settings, $isFinanceManager, $isCommercialManager);
            $hint = pa_action_hint($row, $uid, $settings, $isFinanceManager, $isCommercialManager);
            $status = (string)$row['status'];
            $isFinanceStepForCurrentUser = ($canAct && $status === 'pending_finance_manager' && ($isFinanceManager || $uid === (int)($settings['finance_manager'] ?? 19)));
            $currentFinal = (float)($row['final_amount'] ?? $row['amount_required']);
            if ($currentFinal <= 0) $currentFinal = (float)$row['amount_required'];
            $suggestedAmount = (float)($row['supplier_financial_balance'] ?? 0) - (float)($row['supplier_branch_balance'] ?? 0);
            $suggestedClass = $suggestedAmount > 0 ? 'suggested-positive' : ($suggestedAmount < 0 ? 'suggested-negative' : 'suggested-zero');
            $latestEarlyDiscount = null;
            $latestEarlyDiscountPercent = null;
            $latestAmountBeforeEarlyDiscount = null;
            foreach (['finance', 'commercial', 'section'] as $stageKey) {
                if ($latestAmountBeforeEarlyDiscount === null && ($row[$stageKey . '_before_early_discount'] ?? null) !== null && $row[$stageKey . '_before_early_discount'] !== '') {
                    $latestAmountBeforeEarlyDiscount = (float)$row[$stageKey . '_before_early_discount'];
                }
                if ($latestEarlyDiscountPercent === null && ($row[$stageKey . '_early_discount_percent'] ?? null) !== null && $row[$stageKey . '_early_discount_percent'] !== '') {
                    $latestEarlyDiscountPercent = (float)$row[$stageKey . '_early_discount_percent'];
                }
                if ($latestEarlyDiscount === null && ($row[$stageKey . '_early_discount_amount'] ?? null) !== null && $row[$stageKey . '_early_discount_amount'] !== '') {
                    $latestEarlyDiscount = (float)$row[$stageKey . '_early_discount_amount'];
                }
            }
            $latestEarlyDiscount = $latestEarlyDiscount ?? 0.0;
            $latestEarlyDiscountPercent = $latestEarlyDiscountPercent ?? 0.0;
            $actionAmountBeforeEarlyDiscount = ($latestAmountBeforeEarlyDiscount !== null && $latestAmountBeforeEarlyDiscount > 0) ? $latestAmountBeforeEarlyDiscount : $currentFinal;
            [$previewPercent, $previewDiscountAmount, $previewNetAmount] = pa_early_discount_values((float)$actionAmountBeforeEarlyDiscount, (float)$latestEarlyDiscountPercent);
        ?>
        <div class="view-card">
            <div class="view-head">
                <div>
                    <div class="view-title">#<?= (int)$row['id'] ?> — <?= pa_e($row['supplier_name']) ?></div>
                    <div class="meta">رقم السند: <?= pa_e($row['voucher_number']) ?> | أنشئ بواسطة: <?= pa_e($row['created_by_name'] ?? '-') ?> | <?= pa_datetime($row['created_at']) ?></div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
                    <span class="badge <?= pa_e(pa_status_class($status)) ?>" title="<?= pa_e(pa_status_ar($status)) ?>"><?= pa_e(pa_status_short_ar($status)) ?></span>
                    <a class="btn muted" href="payment_approvals.php?status=<?= pa_e($statusFilter) ?>&q=<?= urlencode($q) ?>">رجوع</a>
                    <?php if($isAdmin): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('تأكيد حذف طلب السداد نهائيًا؟ لا يمكن التراجع عن الحذف.');">
                            <input type="hidden" name="csrf_token" value="<?= pa_e($csrf_token) ?>">
                            <input type="hidden" name="action" value="delete_request">
                            <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
                            <button class="btn danger" type="submit">حذف</button>
                        </form>
                    <?php endif; ?>
                    <?php if(pa_can_print_request($row, $currentUser, $uid, $financeManagerId, $isAdmin, $isCommercialManager, $isFinanceManager)): ?>
                        <a class="btn print" target="_blank" href="print_payment_request.php?id=<?= (int)$row['id'] ?>">طباعة سند الصرف</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="grid">
                <div class="info"><span>القسم</span><b><?= pa_e(pa_company_type_ar($row['company_type'])) ?></b></div>
                <div class="info"><span>طلب السداد من المحاسب</span><b><?= pa_money($row['amount_required']) ?></b></div>
                <div class="info"><span>تاريخ الفاتورة</span><b><?= pa_date($row['invoice_date']) ?></b></div>
                <div class="info"><span>فترة السداد المتفق عليها</span><b><?= !empty($row['agreed_payment_days']) ? (int)$row['agreed_payment_days'] . ' يوم' : '-' ?></b></div>
                <div class="info"><span>تاريخ السداد المستحق</span><b><span class="due-pill <?= pa_e(pa_due_class($row['payment_due_date'] ?? '')) ?>"><?= pa_date($row['payment_due_date'] ?? '') ?></span><span class="due-note <?= pa_e(pa_due_class($row['payment_due_date'] ?? '')) ?>"><?= pa_e(pa_due_label($row['payment_due_date'] ?? '')) ?></span></b></div>
                <div class="info"><span>المرفق</span><b><?php if(!empty($row['attachment_file'])): ?><a href="<?= pa_e($row['attachment_file']) ?>" target="_blank">عرض المرفق</a><?php else: ?>-<?php endif; ?></b></div>
            </div>

            <div class="balances-grid">
                <div class="info"><span>رصيد المورد المالي</span><b><?= pa_money($row['supplier_financial_balance']) ?></b></div>
                <div class="info"><span>تكلفة مخزون المورد بالفروع</span><b><?= pa_money($row['supplier_branch_balance']) ?></b></div>
                <div class="info <?= pa_e($suggestedClass) ?>"><span>مقترح مبلغ السداد</span><b><?= pa_money($suggestedAmount) ?></b></div>
            </div>

            <?php if(!empty($row['notes'])): ?><div class="note-box">ملاحظات المحاسب: <?= nl2br(pa_e($row['notes'])) ?></div><?php endif; ?>

            <div class="steps">
                <div class="step"><h4>مدير القسم</h4><p>المستخدم: <?= pa_e($row['section_user_name'] ?? '-') ?></p><p>الحالة: <?= pa_e(pa_approval_ar($row['section_status'] ?? 'pending')) ?></p><p>قبل الخصم: <?= $row['section_before_early_discount'] !== null ? pa_money($row['section_before_early_discount']) : '-' ?></p><p>نسبة السداد المعجل: <?= $row['section_early_discount_percent'] !== null ? pa_percent($row['section_early_discount_percent']) : '-' ?></p><p>فرق السداد: <?= $row['section_early_discount_amount'] !== null ? pa_money($row['section_early_discount_amount']) : '-' ?></p><p>المعتمد بعد الخصم: <?= $row['section_amount'] !== null ? pa_money($row['section_amount']) : '-' ?></p><p>التاريخ: <?= pa_datetime($row['section_acted_at'] ?? '') ?></p><?php if(!empty($row['section_note'])): ?><p>الملاحظة: <?= pa_e($row['section_note']) ?></p><?php endif; ?></div>
                <div class="step"><h4>المدير التجاري</h4><p>المستخدم: <?= pa_e($row['commercial_user_name'] ?? '-') ?></p><p>الحالة: <?= pa_e(pa_approval_ar($row['commercial_status'] ?? 'pending')) ?></p><p>قبل الخصم: <?= $row['commercial_before_early_discount'] !== null ? pa_money($row['commercial_before_early_discount']) : '-' ?></p><p>نسبة السداد المعجل: <?= $row['commercial_early_discount_percent'] !== null ? pa_percent($row['commercial_early_discount_percent']) : '-' ?></p><p>فرق السداد: <?= $row['commercial_early_discount_amount'] !== null ? pa_money($row['commercial_early_discount_amount']) : '-' ?></p><p>المعتمد بعد الخصم: <?= $row['commercial_amount'] !== null ? pa_money($row['commercial_amount']) : '-' ?></p><p>التاريخ: <?= pa_datetime($row['commercial_acted_at'] ?? '') ?></p><?php if(!empty($row['commercial_note'])): ?><p>الملاحظة: <?= pa_e($row['commercial_note']) ?></p><?php endif; ?></div>
                <div class="step"><h4>المدير المالي</h4><p>المستخدم: <?= pa_e($row['finance_user_name'] ?? '-') ?></p><p>الحالة: <?= pa_e(pa_approval_ar($row['finance_status'] ?? 'pending')) ?></p><p>قبل الخصم: <?= $row['finance_before_early_discount'] !== null ? pa_money($row['finance_before_early_discount']) : '-' ?></p><p>نسبة السداد المعجل: <?= $row['finance_early_discount_percent'] !== null ? pa_percent($row['finance_early_discount_percent']) : '-' ?></p><p>فرق السداد: <?= $row['finance_early_discount_amount'] !== null ? pa_money($row['finance_early_discount_amount']) : '-' ?></p><p>المعتمد بعد الخصم: <?= $row['finance_amount'] !== null ? pa_money($row['finance_amount']) : '-' ?></p><p>التاريخ: <?= pa_datetime($row['finance_acted_at'] ?? '') ?></p><?php if(!empty($row['finance_note'])): ?><p>الملاحظة: <?= pa_e($row['finance_note']) ?></p><?php endif; ?></div>
            </div>

            <?php
                $rejectedBannerText = '';
                if ($status === 'rejected_commercial_manager') {
                    $rejectedBannerText = 'الطلب مرفوض — نرجو توجيه المورد إلى الإدارة التجارية';
                } elseif ($status === 'rejected_finance_manager') {
                    $rejectedBannerText = 'الطلب مرفوض من المدير المالي';
                } elseif ($status === 'rejected_section_manager') {
                    $rejectedBannerText = 'الطلب مرفوض من مدير القسم';
                }
            ?>
            <?php if($rejectedBannerText !== ''): ?>
                <div class="rejected-status-banner">
                    <?= pa_e($rejectedBannerText) ?>
                    <?php if($status === 'rejected_commercial_manager' && !empty($row['commercial_note'])): ?>
                        <small>ملاحظة الإدارة التجارية: <?= pa_e($row['commercial_note']) ?></small>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="action-box">
                    <b><?= pa_e($hint) ?></b>
                    <?php if($canAct): ?>
                        <form method="POST" style="margin-top:10px;">
                            <input type="hidden" name="csrf_token" value="<?= pa_e($csrf_token) ?>">
                            <input type="hidden" name="action" value="approval_action">
                            <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
                            <div class="action-row">
                                <div class="field">
                                    <label>المبلغ قبل الخصم المعجل</label>
                                    <input type="number" step="0.01" min="0" name="approved_amount" id="approved_amount" value="<?= pa_e($actionAmountBeforeEarlyDiscount) ?>" placeholder="المبلغ قبل الخصم">
                                </div>
                                <div class="field">
                                    <label>نسبة السداد المعجل %</label>
                                    <input type="number" step="0.01" min="0" max="100" name="early_payment_discount_percent" id="early_payment_discount_percent" value="<?= ((float)$latestEarlyDiscountPercent > 0) ? pa_e($latestEarlyDiscountPercent) : '' ?>" placeholder="مثال: 2.5">
                                </div>
                                <div class="field note-field">
                                    <label>ملاحظة الموافقة أو سبب الرفض</label>
                                    <textarea name="note" placeholder="اكتب ملاحظة اختيارية للموافقة أو سبب الرفض"></textarea>
                                </div>
                            </div>
                            <div class="discount-preview" id="early_discount_preview_box" style="<?= ((float)$latestEarlyDiscountPercent > 0) ? '' : 'display:none;' ?>">
                                <div class="info discount-save"><span>فرق السداد المعجل</span><b id="early_discount_amount_view"><?= pa_money($previewDiscountAmount) ?></b></div>
                                <div class="info discount-net"><span>المبلغ بعد الخصم المعجل</span><b id="net_amount_after_discount_view"><?= pa_money($previewNetAmount) ?></b></div>
                            </div>
                            <div class="amount-highlight action-final-highlight">
                                <span>المبلغ الذي سيتم اعتماده</span>
                                <b id="final_approved_amount_view"><?= pa_money($previewNetAmount) ?></b>
                            </div>
                            <div class="action-buttons">
                                <button class="btn approve" type="submit" name="decision" value="approve" onclick="return confirm('تأكيد الموافقة بالمبلغ الذي سيتم اعتماده؟')">موافقة</button>
                                <button class="btn reject" type="submit" name="decision" value="reject" onclick="return confirm('تأكيد الرفض؟')">رفض</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if(empty($requests)): ?>
        <div class="empty">لا توجد طلبات سداد مطابقة حاليًا</div>
    <?php elseif($viewId <= 0): ?>
        <div class="table-wrap">
            <table class="table">
                <colgroup>
                    <col style="width:9%">
                    <col style="width:18%">
                    <col style="width:8%">
                    <col style="width:9%">
                    <col style="width:8%">
                    <col style="width:11%">
                    <col style="width:10%">
                    <col style="width:10%">
                    <col style="width:7%">
                    <col style="width:10%">
                </colgroup>
                <thead><tr><th>رقم السند</th><th>المورد</th><th>القسم</th><th>طلب السداد</th><th>فترة السداد</th><th>تاريخ الاستحقاق</th><th>آخر مبلغ معتمد</th><th>الحالة</th><th>تاريخ الطلب</th><th>إجراء</th></tr></thead>
                <tbody>
                <?php foreach($requests as $row): ?>
                    <?php $status=(string)$row['status']; $currentFinal=(float)($row['final_amount'] ?? $row['amount_required']); if($currentFinal<=0)$currentFinal=(float)$row['amount_required']; ?>
                    <tr class="<?= (strpos($status, 'rejected') === 0) ? 'row-rejected' : (($status === 'approved_final') ? 'row-approved' : '') ?>">
                        <td><?= pa_e($row['voucher_number']) ?></td>
                        <td><?= pa_e($row['supplier_name']) ?></td>
                        <td><?= pa_e(pa_company_type_ar($row['company_type'])) ?></td>
                        <td><?= pa_money($row['amount_required']) ?></td>
                        <td><?= !empty($row['agreed_payment_days']) ? (int)$row['agreed_payment_days'] . ' يوم' : '-' ?></td>
                        <td><span class="due-pill <?= pa_e(pa_due_class($row['payment_due_date'] ?? '')) ?>"><?= pa_date($row['payment_due_date'] ?? '') ?></span></td>
                        <td><b><?= pa_money($currentFinal) ?></b></td>
                        <td><span class="badge <?= pa_e(pa_status_class($status)) ?>" title="<?= pa_e(pa_status_ar($status)) ?>"><?= pa_e(pa_status_short_ar($status)) ?></span></td>
                        <td><?= pa_date($row['created_at']) ?></td>
                        <td>
                            <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
                                <a class="btn primary" href="payment_approvals.php?view=<?= (int)$row['id'] ?>&status=<?= pa_e($statusFilter) ?>&q=<?= urlencode($q) ?>">عرض</a>
                                <?php if(pa_can_print_request($row, $currentUser, $uid, $financeManagerId, $isAdmin, $isCommercialManager, $isFinanceManager)): ?>
                                    <a class="btn print" target="_blank" href="print_payment_request.php?id=<?= (int)$row['id'] ?>">طباعة</a>
                                <?php endif; ?>
                                <?php if($isAdmin): ?>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('تأكيد حذف طلب السداد نهائيًا؟ لا يمكن التراجع عن الحذف.');">
                                        <input type="hidden" name="csrf_token" value="<?= pa_e($csrf_token) ?>">
                                        <input type="hidden" name="action" value="delete_request">
                                        <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
                                        <button class="btn danger" type="submit">حذف</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php vcRenderPagination($page, $totalPages); ?>
    <?php endif; ?>
</div>

<script>
function paFormatMoney(value) {
    value = Number(value || 0);
    return value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
function paUpdateEarlyDiscountPreview() {
    const amountEl = document.getElementById('approved_amount');
    const percentEl = document.getElementById('early_payment_discount_percent');
    if (!amountEl || !percentEl) return;

    const rawPercent = (percentEl.value || '').trim();
    const hasTypedPercent = rawPercent !== '';

    let amount = parseFloat(amountEl.value || '0');
    let percent = hasTypedPercent ? parseFloat(rawPercent) : 0;
    if (isNaN(amount) || amount < 0) amount = 0;
    if (isNaN(percent) || percent < 0) percent = 0;
    if (percent > 100) percent = 100;

    const discount = hasTypedPercent ? Math.round((amount * percent / 100) * 100) / 100 : 0;
    const net = Math.max(0, Math.round((amount - discount) * 100) / 100);

    const previewBox = document.getElementById('early_discount_preview_box');
    const discountView = document.getElementById('early_discount_amount_view');
    const netView = document.getElementById('net_amount_after_discount_view');
    const finalView = document.getElementById('final_approved_amount_view');

    if (previewBox) previewBox.style.display = hasTypedPercent ? 'grid' : 'none';
    if (discountView) discountView.textContent = paFormatMoney(discount);
    if (netView) netView.textContent = paFormatMoney(net);
    if (finalView) finalView.textContent = paFormatMoney(net);
}
document.addEventListener('input', function(e) {
    if (e.target && (e.target.id === 'approved_amount' || e.target.id === 'early_payment_discount_percent')) {
        paUpdateEarlyDiscountPreview();
    }
});
document.addEventListener('DOMContentLoaded', paUpdateEarlyDiscountPreview);
</script>
</body>
</html>
