<?php
require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';


date_default_timezone_set('Asia/Riyadh');

function pr_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pr_money_clean($value): float {
    $value = trim((string)$value);
    $value = str_replace([',', ' '], '', $value);
    if ($value === '' || !is_numeric($value)) {
        return 0.0;
    }
    return round((float)$value, 2);
}

function pr_invoice_date_to_db(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    return null;
}

function pr_invoice_date_for_input(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    return '';
}


function pr_due_color_class(?string $dueDate): string {
    $dueDate = trim((string)$dueDate);
    if ($dueDate === '' || $dueDate === '0000-00-00') return '';
    $today = date('Y-m-d');
    return ($dueDate <= $today) ? 'due-passed' : 'due-future';
}

function pr_column_exists(VcDb $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("\n        SELECT COUNT(*) AS c\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = ?\n          AND COLUMN_NAME = ?\n    ");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function pr_table_exists(VcDb $conn, string $table): bool {
    $stmt = $conn->prepare("\n        SELECT COUNT(*) AS c\n        FROM INFORMATION_SCHEMA.TABLES\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = ?\n    ");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function pr_ensure_payment_due_columns(VcDb $conn): void {

    if (!pr_column_exists($conn, 'payment_requests', 'agreed_payment_days')) {
        $conn->query("ALTER TABLE payment_requests ADD COLUMN agreed_payment_days INT NULL DEFAULT NULL AFTER invoice_date");
    }

    if (!pr_column_exists($conn, 'payment_requests', 'payment_due_date')) {
        $conn->query("ALTER TABLE payment_requests ADD COLUMN payment_due_date DATE NULL DEFAULT NULL AFTER agreed_payment_days");
    }
}

function pr_calculate_due_date(?string $invoiceDateDb, int $agreedPaymentDays): ?string {
    if (empty($invoiceDateDb) || $agreedPaymentDays <= 0) {
        return null;
    }

    try {
        $dt = new DateTime($invoiceDateDb);
        $dt->modify('+' . $agreedPaymentDays . ' days');
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function pr_get_setting_user(VcDb $conn, string $key): int {
    $stmt = $conn->prepare("SELECT user_id FROM payment_approval_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['user_id'] ?? 0);
}

function pr_get_user_name(VcDb $conn, int $userId): string {
    if ($userId <= 0) return '-';
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return '-';
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string)($row['username'] ?? '-');
}

function pr_notify_user(VcDb $conn, int $userId, string $title, string $message, string $link, string $type, int $relatedId): void {
    return;
}

function pr_upload_attachment(array $file, string &$error): string {
    if (empty($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'تعذر رفع المرفق. كود الخطأ: ' . (int)$file['error'];
        return '';
    }

    $maxBytes = 10 * 1024 * 1024;
    if ((int)$file['size'] > $maxBytes) {
        $error = 'حجم المرفق كبير. الحد الأقصى 10MB.';
        return '';
    }

    $originalName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['pdf','jpg','jpeg','png','xls','xlsx','doc','docx'];
    if (!in_array($ext, $allowed, true)) {
        $error = 'نوع المرفق غير مسموح. المسموح: PDF, صور, Word, Excel.';
        return '';
    }

    $uploadDir = VC_PUBLIC . '/uploads/payment_requests';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        $error = 'مجلد رفع مرفقات السداد غير قابل للكتابة.';
        return '';
    }

    $htaccess = $uploadDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php[0-9])$\">\nRequire all denied\n</FilesMatch>\n");
    }

    $safeName = 'payment_request_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $target = $uploadDir . '/' . $safeName;

    if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
        $error = 'فشل حفظ المرفق على السيرفر.';
        return '';
    }

    return 'uploads/payment_requests/' . $safeName;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if ($currentUserId <= 0) {
    header('Location: login.php');
    exit();
}

if (!pr_table_exists($conn, 'payment_requests') || !pr_table_exists($conn, 'payment_request_approvals') || !pr_table_exists($conn, 'payment_approval_settings')) {
    http_response_code(500);
    die('❌ جداول طلبات السداد غير موجودة. نفذ SQL الخاص بالمرحلة الأولى أولًا.');
}

pr_ensure_payment_due_columns($conn);

$userStmt = $conn->prepare("SELECT id, username, role, is_admin, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1");
$userStmt->bind_param('i', $currentUserId);
$userStmt->execute();
$currentUser = $userStmt->get_result()->fetch_assoc() ?: [];
$userStmt->close();

$isAdminLike = ((int)($currentUser['is_admin'] ?? 0) === 1)
    || (($currentUser['role'] ?? '') === 'admin')
    || in_array(($currentUser['job_role'] ?? ''), ['admin','commercial_manager'], true);

$pageScope = $isAdminLike ? 'all' : vcGetUserPageScope($conn, $currentUserId, 'add_payment_request');
if (!$isAdminLike && $pageScope === 'none') {
    http_response_code(403);
    die('❌ ليس لديك صلاحية إنشاء طلب سداد');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$success = '';
$error = '';
if (!empty($_SESSION['pr_flash_success'])) {
    $success = (string)$_SESSION['pr_flash_success'];
    unset($_SESSION['pr_flash_success']);
}

$form = [
    'supplier_name' => '',
    'voucher_number' => '',
    'supplier_financial_balance' => '',
    'supplier_branch_balance' => '',
    'amount_required' => '',
    'invoice_date' => '',
    'agreed_payment_days' => '',
    'payment_due_date' => '',
    'company_type' => '',
    'notes' => '',
];

$foodManagerId = pr_get_setting_user($conn, 'food_section_manager');
$nonFoodManagerId = pr_get_setting_user($conn, 'non_food_section_manager');
$foodManagerName = pr_get_user_name($conn, $foodManagerId);
$nonFoodManagerName = pr_get_user_name($conn, $nonFoodManagerId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        die('طلب غير صالح');
    }

    foreach ($form as $k => $v) {
        if (isset($_POST[$k])) {
            $form[$k] = trim((string)$_POST[$k]);
        }
    }

    $supplierName = trim($form['supplier_name']);
    $voucherNumber = trim($form['voucher_number']);
    $supplierFinancialBalance = pr_money_clean($form['supplier_financial_balance']);
    $supplierBranchBalance = pr_money_clean($form['supplier_branch_balance']);
    $amountRequired = pr_money_clean($form['amount_required']);
    $invoiceDateRaw = trim($form['invoice_date']);
    $invoiceDateDb = pr_invoice_date_to_db($invoiceDateRaw);
    $agreedPaymentDays = (int)trim($form['agreed_payment_days']);
    $paymentDueDateDb = pr_calculate_due_date($invoiceDateDb, $agreedPaymentDays);
    $form['payment_due_date'] = $paymentDueDateDb ?? '';
    $companyType = in_array($form['company_type'], ['food','non_food'], true) ? $form['company_type'] : '';
    $notes = trim($form['notes']);

    if ($supplierName === '') {
        $error = 'اسم المورد مطلوب.';
    } elseif ($voucherNumber === '') {
        $error = 'رقم السند مطلوب.';
    } elseif ($amountRequired <= 0) {
        $error = 'المبلغ المطلوب سداده يجب أن يكون أكبر من صفر.';
    } elseif ($invoiceDateRaw === '' || $invoiceDateDb === null) {
        $error = 'تاريخ الفاتورة مطلوب وصحيح لحساب تاريخ السداد المستحق.';
    } elseif ($agreedPaymentDays <= 0) {
        $error = 'فترة السداد المتفق عليها يجب أن تكون رقمًا صحيحًا أكبر من صفر.';
    } elseif ($paymentDueDateDb === null) {
        $error = 'تعذر حساب تاريخ السداد المستحق.';
    } elseif ($companyType === '') {
        $error = 'يجب اختيار قسم المورد: غذائي أو لا غذائي.';
    }

    $sectionManagerId = ($companyType === 'food') ? $foodManagerId : $nonFoodManagerId;
    if ($error === '' && $sectionManagerId <= 0) {
        $error = 'مدير القسم غير مضبوط في إعدادات مسار الاعتماد.';
    }

    if ($error === '') {
        $dupStmt = $conn->prepare("SELECT id FROM payment_requests WHERE voucher_number = ? LIMIT 1");
        if ($dupStmt) {
            $dupStmt->bind_param('s', $voucherNumber);
            $dupStmt->execute();
            $dup = $dupStmt->get_result()->fetch_assoc();
            $dupStmt->close();
            if (!empty($dup)) {
                $error = 'رقم السند موجود بالفعل.';
            }
        }
    }

    $attachmentPath = '';
    if ($error === '') {
        $attachmentPath = pr_upload_attachment($_FILES['attachment_file'] ?? [], $error);
    }

    if ($error === '') {
        $status = 'pending_section_manager';

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("\n                INSERT INTO payment_requests\n                (supplier_name, voucher_number, supplier_financial_balance, supplier_branch_balance, amount_required, final_amount, invoice_date, agreed_payment_days, payment_due_date, company_type, notes, attachment_file, status, created_by, created_at, updated_at)\n                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())\n            ");
            if (!$stmt) {
                throw new Exception('تعذر تجهيز حفظ طلب السداد: ' . $conn->error);
            }

            $stmt->bind_param(
                'ssddddsisssssi',
                $supplierName,
                $voucherNumber,
                $supplierFinancialBalance,
                $supplierBranchBalance,
                $amountRequired,
                $amountRequired,
                $invoiceDateDb,
                $agreedPaymentDays,
                $paymentDueDateDb,
                $companyType,
                $notes,
                $attachmentPath,
                $status,
                $currentUserId
            );
            $stmt->execute();
            $requestId = (int)$stmt->insert_id;
            $stmt->close();

            if ($requestId <= 0) {
                throw new Exception('لم يتم إنشاء طلب السداد.');
            }

            $stepKey = 'section_manager';
            $pending = 'pending';
            $stmt = $conn->prepare("\n                INSERT INTO payment_request_approvals\n                (request_id, step_key, approver_user_id, status, note, acted_at, created_at)\n                VALUES (?, ?, ?, ?, NULL, NULL, NOW())\n            ");
            if (!$stmt) {
                throw new Exception('تعذر تجهيز خطوة الاعتماد: ' . $conn->error);
            }
            $stmt->bind_param('isis', $requestId, $stepKey, $sectionManagerId, $pending);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $companyTypeAr = ($companyType === 'food') ? 'غذائي' : 'لا غذائي';
            $title = 'طلب سداد جديد بانتظار موافقتك';
            $message = "طلب سداد جديد\n"
                . "المورد: " . $supplierName . "\n"
                . "رقم السند: " . $voucherNumber . "\n"
                . "نوع الشركة: " . $companyTypeAr . "\n"
                . "المبلغ المطلوب: " . number_format($amountRequired, 2) . "\n"
                . "فترة السداد المتفق عليها: " . $agreedPaymentDays . " يوم\n"
                . "تاريخ السداد المستحق: " . ($paymentDueDateDb ?? '-') . "\n"
                . "من: " . (string)($currentUser['username'] ?? '') ;
            $link = 'payment_approvals.php?view=' . $requestId;
            pr_notify_user($conn, $sectionManagerId, $title, $message, $link, 'payment_request_approval', $requestId);

            $_SESSION['pr_flash_success'] = 'تم إرسال طلب السداد بنجاح إلى ' . pr_get_user_name($conn, $sectionManagerId) . ' للموافقة.';
            header('Location: add_payment_request.php?saved=1');
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'فشل إنشاء طلب السداد: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>طلب سداد جديد</title>
<link rel="stylesheet" href="public/assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;font-family:'Cairo',Tahoma,Arial,sans-serif}
html,body{direction:rtl;text-align:right}
body{margin:0;background:radial-gradient(circle at top right, rgba(109,74,255,.11), transparent 34%),#eef1f7;color:#172033}
.container{width:min(1120px,calc(100% - 28px));margin:26px auto 46px}
.page-head{text-align:center;margin-bottom:18px}.page-title{margin:0;font-size:28px;font-weight:900;color:#172033}.page-subtitle{margin:8px 0 0;color:#667085;font-weight:800;line-height:1.8}
.panel{background:rgba(255,255,255,.70);border:1px solid #e2e8f0;border-radius:24px;padding:18px;box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;margin-bottom:16px}
.section-title{background:rgba(255,255,255,.82);padding:13px 16px;border-radius:18px;font-weight:900;margin:0 0 15px;color:#4f46e5;border:1px solid #e2e8f0;display:flex;align-items:center;gap:9px}.section-title:before{content:"";width:9px;height:24px;border-radius:999px;background:linear-gradient(180deg,#7c5cff,#4f46e5)}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.full{grid-column:1/-1}
label{display:block;font-size:13px;font-weight:900;color:#172033;margin-bottom:8px}input,select,textarea{width:100%;min-height:48px;padding:0 14px;border-radius:15px;border:1px solid #dfe6f0;background:#eef1f7;color:#172033;box-shadow:inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff;font-size:14px;font-weight:800;outline:none}textarea{min-height:120px;padding:13px 14px;resize:vertical}input:focus,select:focus,textarea:focus{border-color:#6d4aff;box-shadow:0 0 0 3px rgba(109,74,255,.12),inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff}.hint{font-size:12px;color:#64748b;font-weight:800;margin-top:6px;line-height:1.7}
.route-box{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}.route-card{padding:13px;border-radius:18px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-weight:900}.route-card b{color:#4f46e5}.route-card span{display:block;color:#64748b;font-size:12px;margin-top:4px}
.actions{display:flex;gap:10px;justify-content:flex-start;flex-wrap:wrap;margin-top:18px}.btn{min-height:46px;border:none;border-radius:15px;padding:0 22px;font-weight:900;font-size:14px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn-primary{background:linear-gradient(145deg,#7c5cff,#4f46e5);color:#fff}.btn-muted{background:#e2e8f0;color:#172033}
.alert{padding:13px 15px;border-radius:16px;margin-bottom:15px;font-weight:900;line-height:1.8}.alert-success{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}.alert-error{background:#fff1f2;color:#b42318;border:1px solid #fecdd3}.alert-info{background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe}
.due-passed{background:#ecfdf3!important;border-color:#86efac!important;color:#166534!important;box-shadow:0 0 0 3px rgba(22,163,74,.10),inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff!important}.due-future{background:#fff1f2!important;border-color:#fca5a5!important;color:#b42318!important;box-shadow:0 0 0 3px rgba(239,68,68,.10),inset 2px 2px 6px #d1d9e6,inset -2px -2px 6px #fff!important}.due-status-note{font-size:12px;font-weight:900;margin-top:6px;line-height:1.7}.due-status-note.due-passed-text{color:#166534}.due-status-note.due-future-text{color:#b42318}
@media(max-width:850px){.grid,.grid-3,.grid-4,.route-box{grid-template-columns:1fr}.container{width:calc(100% - 16px)}.page-title{font-size:23px}}
</style>
</head>
<body>
<?php include VC_VIEWS . '/layouts/header.php'; ?>
<div class="container">
    <div class="page-head">
        <h1 class="page-title"> طلب سداد جديد</h1>
 
    </div>

    <?php if($success): ?><div class="alert alert-success"><?= pr_e($success) ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-error"><?= pr_e($error) ?></div><?php endif; ?>

    <div class="panel">
        <div class="section-title">بيانات طلب السداد</div>
        <form method="POST" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= pr_e($csrf) ?>">

            <div class="grid-3">
                <div>
                    <label>اسم المورد</label>
                    <input type="text" name="supplier_name" required value="<?= pr_e($form['supplier_name']) ?>" placeholder="مثال: شركة ...">
                </div>
                <div>
                    <label>رقم السند</label>
                    <input type="text" name="voucher_number" required value="<?= pr_e($form['voucher_number']) ?>" placeholder="رقم السند / المرجع">
                </div>
            </div>

            <div class="grid-3" style="margin-top:14px">
                <div>
                    <label>رصيد المورد المالي</label>
                    <input type="number" step="0.01" name="supplier_financial_balance" value="<?= pr_e($form['supplier_financial_balance']) ?>" placeholder="0.00">
                </div>
                <div>
                    <label>رصيد مخزون المورد بالفروع</label>
                    <input type="number" step="0.01" name="supplier_branch_balance" value="<?= pr_e($form['supplier_branch_balance']) ?>" placeholder="0.00">
                </div>
                <div>
                    <label>المطلوب سداده</label>
                    <input type="number" step="0.01" name="amount_required" required value="<?= pr_e($form['amount_required']) ?>" placeholder="0.00">
                </div>
            </div>

            <div class="grid-4" style="margin-top:14px">
                <div>
                    <label>تاريخ الفاتورة المطلوب سدادها</label>
                    <input type="date" name="invoice_date" id="invoice_date" required value="<?= pr_e(pr_invoice_date_for_input($form['invoice_date'])) ?>" onchange="calculatePaymentDueDate()" oninput="calculatePaymentDueDate()">
                </div>
                <div>
                    <label>فترة السداد المتفق عليها <span style="color:#64748b;font-size:12px">(باليوم)</span></label>
                    <input type="number" name="agreed_payment_days" id="agreed_payment_days" min="1" step="1" required value="<?= pr_e($form['agreed_payment_days']) ?>" placeholder="مثال: 45" oninput="calculatePaymentDueDate()">
                </div>
                <div>
                    <label>تاريخ السداد المستحق</label>
                    <input type="date" name="payment_due_date" id="payment_due_date" class="<?= pr_e(pr_due_color_class($form['payment_due_date'])) ?>" value="<?= pr_e(pr_invoice_date_for_input($form['payment_due_date'])) ?>" readonly>
                    <div id="payment_due_status" class="due-status-note"></div>
                </div>
                <div>
                    <label>قسم المورد</label>
                    <select name="company_type" id="company_type" required onchange="updateRouteInfo()">
                        <option value="" <?= $form['company_type']===''?'selected':'' ?> disabled>اختر قسم المورد</option>
                        <option value="food" <?= $form['company_type']==='food'?'selected':'' ?>>غذائي</option>
                        <option value="non_food" <?= $form['company_type']==='non_food'?'selected':'' ?>>لا غذائي</option>
                    </select>
                </div>
            </div>

            <div class="grid" style="margin-top:14px">
                <div>
                    <label>مرفق الفاتورة / كشف الحساب</label>
                    <input type="file" name="attachment_file" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx,.doc,.docx">
                    <div class="hint"> اختيارى</div>
                </div>
                <div>
                    <label>المحاسب  </label>
                    <input type="text" value="<?= pr_e($currentUser['username'] ?? '') ?>" readonly>
                </div>
            </div>

            <div class="full" style="margin-top:14px">
                <label>ملاحظات المحاسب</label>
                <textarea name="notes" placeholder="أي ملاحظة مهمة بخصوص السداد أو الفاتورة"><?= pr_e($form['notes']) ?></textarea>
            </div>

            <div class="alert alert-info" style="margin-top:16px">
                عند الإرسال سيصبح الطلب بانتظار موافقة مدير القسم، ثم المدير التجاري، ثم المدير المالي.
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">إرسال طلب السداد</button>
                <a href="payment_approvals.php" class="btn btn-muted">اعتماد طلبات السداد</a>
            </div>
        </form>
    </div>
</div>
<script>
function calculatePaymentDueDate(){
    const invoiceInput = document.getElementById('invoice_date');
    const daysInput = document.getElementById('agreed_payment_days');
    const dueInput = document.getElementById('payment_due_date');

    if (!invoiceInput || !daysInput || !dueInput) return;

    const invoiceDate = invoiceInput.value;
    const days = parseInt(daysInput.value, 10);

    const statusBox = document.getElementById('payment_due_status');
    dueInput.classList.remove('due-passed', 'due-future');
    if (statusBox) {
        statusBox.textContent = '';
        statusBox.classList.remove('due-passed-text', 'due-future-text');
    }

    if (!invoiceDate || !days || days <= 0) {
        dueInput.value = '';
        return;
    }

    const date = new Date(invoiceDate + 'T00:00:00');
    if (isNaN(date.getTime())) {
        dueInput.value = '';
        return;
    }

    date.setDate(date.getDate() + days);
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    const dueValue = `${yyyy}-${mm}-${dd}`;
    dueInput.value = dueValue;

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const dueDate = new Date(dueValue + 'T00:00:00');

    if (dueDate <= today) {
        dueInput.classList.add('due-passed');
        if (statusBox) {
            statusBox.textContent = 'يستحق السداد';
            statusBox.classList.add('due-passed-text');
        }
    } else {
        dueInput.classList.add('due-future');
        if (statusBox) {
            statusBox.textContent = 'لا يستحق السداد';
            statusBox.classList.add('due-future-text');
        }
    }
}

function updateRouteInfo(){
}

document.addEventListener('DOMContentLoaded', calculatePaymentDueDate);
</script>
</body>
</html>
