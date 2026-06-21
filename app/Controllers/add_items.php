<?php
require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';


date_default_timezone_set('Asia/Riyadh');

$success = '';
$errors = [];
$supplier_fee = 0;
$edit_batch = '';
$is_edit_mode = false;
$edit_items = [];
$edit_original_created_by = 0;
$edit_error = '';

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function ensureItemsTaxRateColumn(VcDb $conn): void {
    if (!vcColumnExists($conn, 'items', 'tax_rate')) {
        @$conn->query("ALTER TABLE items ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 15.00 AFTER shad");
    }
}

function vcNormalizeTaxRate($value, float $default = 15): string {
    $raw = trim((string)$value);

    if ($raw === '') {
        return rtrim(rtrim(number_format($default, 2, '.', ''), '0'), '.');
    }

    $normalized = mb_strtolower($raw, 'UTF-8');
    $normalized = str_replace(['٪', '%', ' '], '', $normalized);
    $normalized = str_replace(['٬', ','], '', $normalized);
    $normalized = str_replace('٫', '.', $normalized);

    if (in_array($normalized, ['-', '—', 'لا', 'لايوجد', 'بدون', 'بدونضريبة', 'معفى', 'معفي', 'exempt', 'no', 'zero'], true)) {
        return '0';
    }

    if (!is_numeric($normalized)) {
        return rtrim(rtrim(number_format($default, 2, '.', ''), '0'), '.');
    }

    $rate = (float)$normalized;
    if ($rate < 0) $rate = 0;
    if ($rate > 100) $rate = 100;

    return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
}

function vcCleanItemNumber($value, float $default = 0): string {
    $value = trim((string)$value);
    if ($value === '') return (string)$default;
    $value = str_replace(['٬', ','], '', $value);
    $value = str_replace('٫', '.', $value);
    if (!is_numeric($value)) return (string)$default;
    return rtrim(rtrim(number_format((float)$value, 4, '.', ''), '0'), '.');
}

function vcCurrentUserCanEditItemsBatch(VcDb $conn, int $userId, int $createdBy): bool {
    if ($userId <= 0) return false;
    if ($userId === $createdBy) return true;

    $stmt = $conn->prepare("SELECT is_admin, role, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$u) return false;

    if ((int)($u['is_admin'] ?? 0) === 1 || ($u['role'] ?? '') === 'admin') return true;
    if (in_array((string)($u['job_role'] ?? ''), ['commercial_manager','section_manager'], true)) return true;
    if ((int)($u['is_supervisor'] ?? 0) === 1) return true;

    return false;
}


function vcNotifyColumnExists(VcDb $conn, string $table, string $column): bool {
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

function vcDisabledHookSetup(VcDb $conn): void {
    return;
}

function vcDisabledUserHook(VcDb $conn, int $userId, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0): void {
    return;
}


function getDirectManagerId(VcDb $conn, int $userId): int {
    if ($userId <= 0) return 0;

    $stmt = $conn->prepare("SELECT manager_id FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return 0;

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['manager_id'] ?? 0);
}

function vcDisabledRecipientHook(array &$sentTo, int $userId): bool {
    if ($userId <= 0) return false;
    if (!empty($sentTo[$userId])) return false;
    $sentTo[$userId] = true;
    return true;
}

function vcGetUsersWithAnyPagePermission(VcDb $conn, array $pageNames): array {
    $pageNames = array_values(array_unique(array_filter(array_map('strval', $pageNames))));
    if (empty($pageNames)) return [];

    $placeholders = implode(',', array_fill(0, count($pageNames), '?'));
    $types = str_repeat('s', count($pageNames));

    $sql = "
        SELECT DISTINCT up.user_id, COALESCE(up.scope, 'own') AS scope
        FROM user_permissions up
        JOIN pages p ON p.id = up.page_id
        WHERE p.name IN ($placeholders)
          AND p.status = 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];

    $stmt->bind_param($types, ...$pageNames);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'user_id' => (int)($row['user_id'] ?? 0),
            'scope' => (string)($row['scope'] ?? 'own'),
        ];
    }
    $stmt->close();
    return $rows;
}

function vcDisabledItemsReviewHook(VcDb $conn, int $createdByUserId, string $title, string $message, string $managerLink = 'under_review_items.php', string $adminLink = 'items_admin.php', string $type = 'items_sent_review', int $relatedId = 0): void {
    return;
}

function vcDisabledManagerHook(VcDb $conn, int $createdByUserId, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0): void {
    return;
}

function vcDisabledAdminsHook(VcDb $conn, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0, int $excludeUserId = 0): void {
    return;
}


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

ensureItemsShadColumn($conn);
ensureItemsTaxRateColumn($conn);

if ($user_id <= 0) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$form_action = $_POST['form_action'] ?? '';
$supplier_from_post = trim($_POST['supplier_name'] ?? '');

if ($supplier_from_post === '') {
    $supplier_from_post = trim($_POST['supplier_search'] ?? '');
}

$edit_batch = trim((string)($_GET['edit_batch'] ?? $_POST['edit_batch'] ?? ''));

if ($edit_batch !== '') {
    $stmtEdit = $conn->prepare("
        SELECT *
        FROM items
        WHERE batch_id = ?
        ORDER BY id ASC
    ");

    if ($stmtEdit) {
        $stmtEdit->bind_param("s", $edit_batch);
        $stmtEdit->execute();
        $resEdit = $stmtEdit->get_result();
        while ($r = $resEdit->fetch_assoc()) {
            $edit_items[] = $r;
        }
        $stmtEdit->close();
    }

    if (empty($edit_items)) {
        $errors[] = "طلب الأصناف غير موجود للتعديل.";
        $edit_batch = '';
    } else {
        $firstEditRow = $edit_items[0];
        $edit_original_created_by = (int)($firstEditRow['created_by'] ?? 0);
        $edit_statuses = array_unique(array_map(fn($r) => (string)($r['status'] ?? ''), $edit_items));

        if (count($edit_statuses) !== 1 || ($edit_statuses[0] ?? '') !== 'review') {
            $errors[] = "التعديل متاح فقط للطلبات تحت المراجعة.";
            $edit_batch = '';
            $edit_items = [];
        } elseif (!vcCurrentUserCanEditItemsBatch($conn, $user_id, $edit_original_created_by)) {
            $errors[] = "ليس لديك صلاحية تعديل هذا الطلب.";
            $edit_batch = '';
            $edit_items = [];
        } else {
            $is_edit_mode = true;
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $supplier_from_post = (string)($firstEditRow['supplier_name'] ?? '');
            }
        }
    }
}


if ($supplier_from_post !== '') {

    $stmt = $conn->prepare("
        SELECT e.value 
        FROM events e
        JOIN contracts c ON e.contract_id = c.id
        WHERE c.supplier_name = ?
        AND e.name = 'رسوم صنف جديد'
        ORDER BY e.id DESC
        LIMIT 1
    ");

    $stmt->bind_param("s", $supplier_from_post);
    $stmt->execute();

    $res = $stmt->get_result();
    $row = $res->fetch_assoc();

    $supplier_fee = $row['value'] ?? 0;

    $stmt->close();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form_action === 'save') {

    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $errors[] = "الطلب مش صالح، جرّب تاني.";
    }

    $supplier = trim($_POST['supplier_name'] ?? '');

    if ($supplier === '') {
        $supplier = trim($_POST['supplier_search'] ?? '');
    }

    if ($supplier === '') {
        $errors[] = "اكتب اسم المورد أو اختاره من البحث.";
    }

    $barcodes = $_POST['barcode'] ?? [];
    $names    = $_POST['item_name'] ?? [];
    $shads    = $_POST['shad'] ?? [];
    $taxRates = $_POST['tax_rate'] ?? [];
    $before   = $_POST['cost_before'] ?? [];
    $after    = $_POST['cost_after'] ?? [];
    $sell     = $_POST['sell_price'] ?? [];
    $profit   = $_POST['profit'] ?? [];
    $fees     = $_POST['fee'] ?? [];

    $has_item = false;

    foreach ($barcodes as $barcode) {
        if (trim((string)$barcode) !== '') {
            $has_item = true;
            break;
        }
    }

    if (!$has_item) {
        $errors[] = "أضف صنف واحد على الأقل.";
    }

    if (empty($errors)) {

        $notes_general = $is_edit_mode ? '' : trim($_POST['notes_general'] ?? '');
        $batch_id = $is_edit_mode ? $edit_batch : (string)time();
        $created_by = $is_edit_mode ? (int)$edit_original_created_by : $user_id;
        $status = 'review';

        try {
            $conn->begin_transaction();

            if ($is_edit_mode) {
                $stmtDelete = $conn->prepare("DELETE FROM items WHERE batch_id = ? AND status = 'review'");
                if (!$stmtDelete) {
                    throw new Exception('تعذر تجهيز حذف الأصناف القديمة.');
                }
                $stmtDelete->bind_param("s", $batch_id);
                $stmtDelete->execute();
                $stmtDelete->close();
            }

            $stmt = $conn->prepare("
                INSERT INTO items 
                (supplier_name, barcode, name, shad, tax_rate, cost_before, cost_after, sell_price, profit, fee, notes, batch_id, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new Exception('تعذر تجهيز حفظ الأصناف.');
            }

            $inserted = 0;

            for ($i = 0; $i < count($barcodes); $i++) {

                $barcode = trim((string)($barcodes[$i] ?? ''));

                if ($barcode === '') {
                    continue;
                }

                $item_name = trim((string)($names[$i] ?? ''));
                $shad_val = trim((string)($shads[$i] ?? ''));
                $shad_val = preg_replace('/\D+/', '', $shad_val);
                $shad_param = ($shad_val === '') ? null : (int)$shad_val;
                $tax_rate = vcNormalizeTaxRate($taxRates[$i] ?? 15);
                $cost_before = vcCleanItemNumber($before[$i] ?? 0);
                $cost_after = vcCleanItemNumber($after[$i] ?? 0);
                $sell_price = vcCleanItemNumber($sell[$i] ?? 0);
                $profit_val = vcCleanItemNumber($profit[$i] ?? 0);
                $fee_val = vcCleanItemNumber($fees[$i] ?? 0);

                if ((float)$cost_after <= 0 && (float)$cost_before > 0) {
                    $cost_after = vcCleanItemNumber(((float)$cost_before) * (1 + ((float)$tax_rate / 100)));
                }

                if ((float)$profit_val == 0 && (float)$cost_after > 0 && (float)$sell_price > 0) {
                    $profit_val = vcCleanItemNumber((((float)$sell_price - (float)$cost_after) / (float)$cost_after) * 100);
                }

                $stmt->bind_param(
                    "sssisssssssssi",
                    $supplier,
                    $barcode,
                    $item_name,
                    $shad_param,
                    $tax_rate,
                    $cost_before,
                    $cost_after,
                    $sell_price,
                    $profit_val,
                    $fee_val,
                    $notes_general,
                    $batch_id,
                    $status,
                    $created_by
                );

                $stmt->execute();
                $inserted++;
            }

            $stmt->close();

            if ($inserted > 0) {
                $conn->commit();

                if ($is_edit_mode) {
                    $success = "تم حفظ تعديل الأصناف بنجاح - رقم الدفعة: " . $batch_id;

                    $stmtReload = $conn->prepare("SELECT * FROM items WHERE batch_id = ? ORDER BY id ASC");
                    if ($stmtReload) {
                        $stmtReload->bind_param("s", $batch_id);
                        $stmtReload->execute();
                        $reloadRes = $stmtReload->get_result();
                        $edit_items = [];
                        while ($rr = $reloadRes->fetch_assoc()) {
                            $edit_items[] = $rr;
                        }
                        $stmtReload->close();
                    }
                } else {
                    $success = "تم حفظ الأصناف بنجاح - رقم الدفعة: " . $batch_id;

                    vcDisabledItemsReviewHook(
                        $conn,
                        (int)$user_id,
                        'طلب تكويد جديد للمراجعة',
                        'تم إرسال طلب تكويد جديد رقم الدفعة ' . $batch_id . ' للمورد: ' . $supplier . ' بعدد أصناف: ' . (int)$inserted,
                        'under_review_items.php',
                        'items_admin.php',
                        'items_sent_review',
                        0
                    );

                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $supplier_from_post = '';
                    $supplier_fee = 0;
                }
            } else {
                $conn->rollback();
                $errors[] = "لم يتم حفظ أي صنف، تأكد من إدخال الباركود.";
            }

        } catch (Throwable $ex) {
            $conn->rollback();
            $errors[] = "حصل خطأ أثناء حفظ الأصناف.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= $is_edit_mode ? 'تعديل أصناف' : 'إضافة أصناف' ?></title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<?php vcRenderPageAssets(['forms' => true]); ?>
</head>

<body>

<div id="savingOverlay" class="saving-overlay">
    <div class="saving-card">
        <div class="saving-title">جاري حفظ الأصناف...</div>
        <div class="saving-text">استنى ثواني، لا تقفل الصفحة ولا تضغط حفظ مرة تانية.</div>
        <div class="upload-progress-track">
            <div id="savingProgressFill" class="upload-progress-fill"></div>
        </div>
        <div id="savingProgressPercent" class="upload-progress-small">0%</div>
    </div>
</div>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">📦 <?= $is_edit_mode ? 'تعديل أصناف' : 'إضافة أصناف' ?></h1>
        <p class="page-subtitle"><?= $is_edit_mode ? 'تعديل بيانات طلب الأصناف بنفس صفحة الإدخال.' : 'اختار المورد، ثم أضف الأصناف والباركود والأسعار ورسوم الدخول.' ?></p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success" id="successBox">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div>• <?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="mainForm" autocomplete="off">

        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="form_action" id="form_action" value="">
        <input type="hidden" name="edit_batch" value="<?= e($edit_batch) ?>">
        <input type="hidden" name="supplier_name" id="supplier_name" value="<?= e($supplier_from_post) ?>">

        <div class="card">
            <div class="section-title">بيانات المورد</div>

            <div class="supplier-grid">
                <div class="field supplier-box">
                    <label for="supplier_search">اسم المورد</label>
                    <input
                        type="text"
                        id="supplier_search"
                        name="supplier_search"
                        value="<?= e($supplier_from_post) ?>"
                        placeholder="اكتب اسم المورد أو اختاره من البحث"
                    >
                    <div id="results"></div>
                </div>

                <div class="field">
                    <label>رسوم الدخول</label>
                    <div class="fee-preview">
                        <span id="supplierFeePreview"><?= e(number_format((float)$supplier_fee, 2)) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="section-title">الأصناف</div>

            <div class="excel-upload-box">
                <div class="field" style="margin-bottom:0;">
                    <label for="excel_file">رفع ملف Excel للأصناف</label>
                    <input type="file" id="excel_file" accept=".xlsx,.xls,.csv">
                    <div class="hint">
                        الأعمدة المطلوبة: باركود، اسم الصنف، الشد، الضريبة، تكلفة قبل، سعر البيع، رسوم الدخول.
                        <br>
                        الضريبة تقبل: 15 أو 15% أو 0 أو - أو بدون أو معفى. تكلفة بعد وهامش الربح يتم حسابهم تلقائيًا حسب ضريبة كل صنف.
                    </div>

                    <div class="import-mode-box">
                        <span class="import-mode-title">طريقة الاستيراد:</span>

                        <label class="import-mode-option">
                            <input type="radio" name="excel_import_mode" value="append" checked>
                            أكمل على الموجود
                        </label>

                        <label class="import-mode-option">
                            <input type="radio" name="excel_import_mode" value="replace">
                            بدل الموجود
                        </label>

                        <div class="import-mode-note">
                            اختار "أكمل على الموجود" لو هترفع الملف على مرتين أو تلاتة. اختار "بدل الموجود" لو عايز تمسح الجدول الحالي وتبدأ من الملف الجديد.
                        </div>
                    </div>

                    <div id="excelStatus" class="excel-status"></div>

                    <div id="excelProgressBox" class="upload-progress-box">
                        <div class="upload-progress-head">
                            <span id="excelProgressText">جاري تجهيز الملف...</span>
                            <span id="excelProgressPercent">0%</span>
                        </div>
                        <div class="upload-progress-track">
                            <div id="excelProgressFill" class="upload-progress-fill"></div>
                        </div>
                        <div id="excelProgressDetails" class="upload-progress-small">لم يبدأ الاستيراد بعد.</div>
                    </div>
                </div>

                <button type="button" class="btn btn-excel" onclick="importExcelItems()">استيراد من Excel</button>
                <button type="button" class="btn btn-clear" onclick="clearItemsTable()">تفريغ الجدول</button>
            </div>

            <div class="table-wrap">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>باركود</th>
                            <th>اسم الصنف</th>
                            <th>الشد</th>
                            <th>الضريبة</th>
                            <th>تكلفة قبل</th>
                            <th>تكلفة بعد</th>
                            <th>سعر البيع</th>
                            <th>هامش %</th>
                            <th>رسوم الدخول</th>
                            <th>حذف</th>
                        </tr>
                    </thead>

                    <tbody id="tableBody"></tbody>
                </table>
            </div>

            <div class="actions-row">
                <button type="button" class="btn btn-primary" onclick="addRow()">+ إضافة صنف</button>

                <div class="total-box">
                    الإجمالي: <span id="total">0.00</span>
                </div>
            </div>
        </div>

        <div class="card">
            <?php if(!$is_edit_mode): ?>
                <div class="section-title">ملاحظات</div>

                <div class="field">
                    <textarea name="notes_general" placeholder="اكتب أي ملاحظات خاصة بهذه الدفعة..."><?= e($_POST['notes_general'] ?? '') ?></textarea>
                </div>
            <?php endif; ?>

            <button type="submit" class="submit" onclick="document.getElementById('form_action').value='save'">
                <?= $is_edit_mode ? 'حفظ التعديل' : 'حفظ الأصناف' ?>
            </button>
        </div>

    </form>

    <div class="card template-download-card">
        <div>
            <div class="section-title" style="margin-bottom:10px;">قالب رفع الأصناف</div>
            <div class="template-download-text">
                حمّل قالب Excel الجاهز، املأ الأصناف، ثم ارفعه من خانة رفع ملف Excel بالأعلى.
            </div>
        </div>

        <a class="btn btn-template" href="items_upload_template.xlsx" download>
            ⬇ تحميل قالب رفع الأصناف
        </a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
let supplierFee = <?= json_encode((float)$supplier_fee) ?>;
let isEditMode = <?= $is_edit_mode ? 'true' : 'false' ?>;
let editItems = <?= json_encode(array_map(function($r){ return [
    'barcode' => (string)($r['barcode'] ?? ''),
    'name' => (string)($r['name'] ?? ''),
    'shad' => (string)($r['shad'] ?? ''),
    'tax_rate' => (string)($r['tax_rate'] ?? '15'),
    'cost_before' => (string)($r['cost_before'] ?? ''),
    'sell_price' => (string)($r['sell_price'] ?? ''),
    'fee' => (string)($r['fee'] ?? '')
]; }, $edit_items), JSON_UNESCAPED_UNICODE) ?>;


function setExcelProgress(percent, text, details){
    const box = document.getElementById("excelProgressBox");
    const fill = document.getElementById("excelProgressFill");
    const pct = document.getElementById("excelProgressPercent");
    const txt = document.getElementById("excelProgressText");
    const det = document.getElementById("excelProgressDetails");

    percent = Math.max(0, Math.min(100, Math.round(percent || 0)));

    if(box) box.style.display = "block";
    if(fill) fill.style.width = percent + "%";
    if(pct) pct.innerText = percent + "%";
    if(txt && text) txt.innerText = text;
    if(det && details) det.innerText = details;
}

function resetExcelProgress(){
    setExcelProgress(0, "جاري تجهيز الملف...", "لم يبدأ الاستيراد بعد.");
}

function showSavingOverlay(){
    const overlay = document.getElementById("savingOverlay");
    const fill = document.getElementById("savingProgressFill");
    const pct = document.getElementById("savingProgressPercent");

    if(!overlay || !fill || !pct) return;

    overlay.style.display = "flex";
    let p = 5;
    fill.style.width = p + "%";
    pct.innerText = p + "%";

    window.vcSavingProgressTimer = setInterval(function(){
        if(p < 92){
            p += p < 55 ? 7 : 3;
            if(p > 92) p = 92;
            fill.style.width = p + "%";
            pct.innerText = p + "%";
        }
    }, 260);
}

function hideSavingOverlay(){
    if(window.vcSavingProgressTimer){
        clearInterval(window.vcSavingProgressTimer);
        window.vcSavingProgressTimer = null;
    }
    const overlay = document.getElementById("savingOverlay");
    if(overlay) overlay.style.display = "none";
}

window.addEventListener("load", function(){
    if(isEditMode && Array.isArray(editItems) && editItems.length){
        document.getElementById("tableBody").innerHTML = '';
        editItems.forEach(item => {
            const row = addRow();
            setRowValues(row, item);
        });
    }else{
        addRow();
    }
    calcTotal();

    let box = document.getElementById("successBox");
    if(box){
        setTimeout(function(){
            box.style.display = "none";
        }, 5000);
    }
});

function parseTaxRate(value){
    let raw = String(value ?? '').trim().toLowerCase();
    raw = raw.replace(/٪|%/g, '').replace(/\s+/g, '').replace(/,/g, '').replace(/٫/g, '.');

    if(raw === '' || raw === '15%') return 15;
    if(['-', '—', 'لا', 'لايوجد', 'بدون', 'بدونضريبة', 'معفى', 'معفي', 'exempt', 'no', 'zero'].includes(raw)) return 0;

    let rate = parseFloat(raw);
    if(isNaN(rate)) rate = 15;
    if(rate < 0) rate = 0;
    if(rate > 100) rate = 100;
    return rate;
}

function taxSelectHtml(selectedValue){
    const rate = parseTaxRate(selectedValue);
    const selected0 = rate === 0 ? 'selected' : '';
    const selected15 = rate !== 0 ? 'selected' : '';
    return `<select name="tax_rate[]" onchange="calcRow(this)">
        <option value="15" ${selected15}>15%</option>
        <option value="0" ${selected0}>0%</option>
    </select>`;
}

function addRow(){
    let row = document.createElement("tr");

    row.innerHTML = `
        <td><input name="barcode[]" type="text" inputmode="text" maxlength="80" placeholder="باركود / كود صنف"></td>

        <td><input name="item_name[]" placeholder="اسم الصنف"></td>

        <td><input name="shad[]" type="number" step="1" min="0" inputmode="numeric" placeholder="رقم"></td>

        <td>${taxSelectHtml(15)}</td>

        <td><input name="cost_before[]" type="number" step="0.01" oninput="calcRow(this)" placeholder="0"></td>

        <td><input name="cost_after[]" readonly placeholder="0"></td>

        <td><input name="sell_price[]" type="number" step="0.01" oninput="calcRow(this)" placeholder="0"></td>

        <td><input name="profit[]" readonly placeholder="0"></td>

        <td><input name="fee[]" class="fee" value="${supplierFee}" oninput="calcTotal()"></td>

        <td>
            <button type="button" class="btn btn-delete" onclick="deleteRow(this)">✖</button>
        </td>
    `;

    document.getElementById("tableBody").appendChild(row);
    calcTotal();
    return row;
}

function setRowValues(row, data){
    row.querySelector("[name='barcode[]']").value = data.barcode || '';
    row.querySelector("[name='item_name[]']").value = data.name || '';
    row.querySelector("[name='shad[]']").value = data.shad || '';
    if(row.querySelector("[name='tax_rate[]']")) row.querySelector("[name='tax_rate[]']").value = String(parseTaxRate(data.tax_rate ?? 15));
    row.querySelector("[name='cost_before[]']").value = data.cost_before || '';
    row.querySelector("[name='sell_price[]']").value = data.sell_price || '';
    row.querySelector("[name='fee[]']").value = data.fee !== '' && data.fee !== null && data.fee !== undefined ? data.fee : supplierFee;
    calcRow(row.querySelector("[name='cost_before[]']"));
}

function clearItemsTable(){
    document.getElementById("tableBody").innerHTML = '';
    addRow();
    calcTotal();

    const status = document.getElementById("excelStatus");
    if(status){
        status.innerText = 'تم تفريغ الجدول.';
    }
    resetExcelProgress();
}

function normalizeHeader(v){
    return String(v || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '')
        .replace(/[ـ_-]/g, '');
}

function mapHeaderToField(header){
    const h = normalizeHeader(header);

    const aliases = {
        barcode: ['باركود','الباركود','barcode','bar code','كود','كودالصنف'],
        name: ['اسمالصنف','الصنف','اسم','itemname','name','description','وصفالصنف'],
        shad: ['الشد','شد','shad','pack','packing','عددبالكرتون'],
        tax_rate: ['الضريبة','ضريبة','نسبةالضريبة','tax','taxrate','vat','vatrate'],
        cost_before: ['تكلفةقبل','التكلفةقبل','costbefore','cost','oldcost','costprice','سعرالتكلفة'],
        sell_price: ['سعرالبيع','بيع','sellprice','sellingprice','price','retailprice'],
        fee: ['رسومالدخول','رسوم','fee','fees','entryfee']
    };

    for(const field in aliases){
        if(aliases[field].some(a => normalizeHeader(a) === h)){
            return field;
        }
    }

    return '';
}

function cellValue(v){
    if(v === null || v === undefined) return '';
    return String(v).trim();
}

function importExcelItems(){
    const fileInput = document.getElementById("excel_file");
    const status = document.getElementById("excelStatus");
    const importButton = document.querySelector(".btn-excel");

    if(!fileInput || !fileInput.files || !fileInput.files[0]){
        if(status) status.innerText = 'اختار ملف Excel الأول.';
        resetExcelProgress();
        return;
    }

    const selectedFile = fileInput.files[0];
    const originalButtonText = importButton ? importButton.innerText : '';

    if(importButton){
        importButton.disabled = true;
        importButton.style.opacity = '.65';
        importButton.style.cursor = 'wait';
        importButton.innerText = 'جاري الاستيراد...';
    }

    setExcelProgress(2, 'بدأ قراءة الملف...', 'الملف: ' + selectedFile.name);

    let fakeProgressTimer = null;

    function finishButton(){
        if(fakeProgressTimer){
            clearInterval(fakeProgressTimer);
            fakeProgressTimer = null;
        }
        if(importButton){
            importButton.disabled = false;
            importButton.style.opacity = '';
            importButton.style.cursor = '';
            importButton.innerText = originalButtonText || 'استيراد من Excel';
        }
    }

    function startFakeProgress(){
        let p = 35;
        fakeProgressTimer = setInterval(function(){
            if(p < 88){
                p += 1;
                setExcelProgress(p, 'جاري تحليل Excel في الخلفية...', 'الصفحة هتفضل شغالة. استنى لحد ما التحليل يخلص.');
            }
        }, 220);
    }

    const workerCode = `
        self.importScripts('https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js');

        function normalizeHeader(v){
            return String(v || '')
                .trim()
                .toLowerCase()
                .replace(/\\s+/g, '')
                .replace(/[ـ_-]/g, '');
        }

        function mapHeaderToField(header){
            const h = normalizeHeader(header);
            const aliases = {
                barcode: ['باركود','الباركود','barcode','barcode','كود','كودالصنف','باركودالصنف'],
                name: ['اسمالصنف','الصنف','اسم','itemname','name','description','وصفالصنف'],
                shad: ['الشد','شد','shad','pack','packing','عددبالكرتون'],
                tax_rate: ['الضريبة','ضريبة','نسبةالضريبة','tax','taxrate','vat','vatrate'],
                cost_before: ['تكلفةقبل','التكلفةقبل','costbefore','cost','oldcost','costprice','سعرالتكلفة'],
                sell_price: ['سعرالبيع','بيع','sellprice','sellingprice','price','retailprice'],
                fee: ['رسومالدخول','رسوم','fee','fees','entryfee']
            };

            for(const field in aliases){
                if(aliases[field].some(a => normalizeHeader(a) === h)){
                    return field;
                }
            }
            return '';
        }

        function cellValue(v){
            if(v === null || v === undefined) return '';
            return String(v).trim();
        }

        self.onmessage = function(ev){
            try{
                const buffer = ev.data.buffer;
                const supplierFee = ev.data.supplierFee;

                const workbook = XLSX.read(buffer, {
                    type: 'array',
                    dense: true,
                    cellDates: false,
                    cellNF: false,
                    cellHTML: false,
                    cellStyles: false
                });

                const sheetName = workbook.SheetNames[0];
                const sheet = workbook.Sheets[sheetName];

                if(!sheet || !sheet['!ref']){
                    self.postMessage({ok:false, error:'الملف فاضي أو الشيت الأول غير صالح.'});
                    return;
                }

                let range = XLSX.utils.decode_range(sheet['!ref']);

                const maxRowsToRead = 3000;
                const maxColsToRead = 20;
                range.e.r = Math.min(range.e.r, range.s.r + maxRowsToRead - 1);
                range.e.c = Math.min(range.e.c, range.s.c + maxColsToRead - 1);

                const rows = XLSX.utils.sheet_to_json(sheet, {
                    header: 1,
                    defval: '',
                    raw: false,
                    range: range,
                    blankrows: false
                });

                if(!rows.length){
                    self.postMessage({ok:false, error:'الملف فاضي.'});
                    return;
                }

                let headerMap = {};
                let startIndex = 0;
                const firstRow = rows[0] || [];
                let matched = 0;

                firstRow.forEach((header, idx) => {
                    const field = mapHeaderToField(header);
                    if(field){
                        headerMap[field] = idx;
                        matched++;
                    }
                });

                if(matched >= 2){
                    startIndex = 1;
                }else{
                    headerMap = {
                        barcode: 0,
                        name: 1,
                        shad: 2,
                        tax_rate: 3,
                        cost_before: 4,
                        sell_price: 5,
                        fee: 6
                    };
                    startIndex = 0;
                }

                const imported = [];

                for(let i = startIndex; i < rows.length; i++){
                    const r = rows[i] || [];
                    const item = {
                        barcode: cellValue(r[headerMap.barcode]),
                        name: cellValue(r[headerMap.name]),
                        shad: cellValue(r[headerMap.shad]),
                        tax_rate: headerMap.tax_rate !== undefined ? cellValue(r[headerMap.tax_rate]) : '15',
                        cost_before: cellValue(r[headerMap.cost_before]),
                        sell_price: cellValue(r[headerMap.sell_price]),
                        fee: headerMap.fee !== undefined ? cellValue(r[headerMap.fee]) : supplierFee
                    };

                    if(item.barcode === '' && item.name === ''){
                        continue;
                    }

                    if(item.fee === ''){
                        item.fee = supplierFee;
                    }

                    if(item.tax_rate === ''){
                        item.tax_rate = '15';
                    }

                    imported.push(item);
                }

                self.postMessage({ok:true, imported: imported, scannedRows: rows.length});
            }catch(err){
                self.postMessage({ok:false, error: err && err.message ? err.message : 'تعذر قراءة ملف Excel.'});
            }
        };
    `;

    let workerUrl = null;
    let worker = null;

    try{
        const blob = new Blob([workerCode], {type:'application/javascript'});
        workerUrl = URL.createObjectURL(blob);
        worker = new Worker(workerUrl);
    }catch(err){
        finishButton();
        if(status) status.innerText = 'المتصفح منع تشغيل التحليل في الخلفية.';
        setExcelProgress(0, 'فشل الاستيراد', 'جرّب Chrome أو احفظ الملف CSV ثم ارفعه.');
        return;
    }

    worker.onmessage = function(ev){
        const data = ev.data || {};

        if(worker){
            worker.terminate();
            worker = null;
        }
        if(workerUrl){
            URL.revokeObjectURL(workerUrl);
            workerUrl = null;
        }

        if(!data.ok){
            finishButton();
            if(status) status.innerText = data.error || 'تعذر قراءة ملف Excel.';
            setExcelProgress(0, 'فشل الاستيراد', data.error || 'تعذر قراءة ملف Excel.');
            return;
        }

        const imported = Array.isArray(data.imported) ? data.imported : [];

        if(!imported.length){
            finishButton();
            if(status) status.innerText = 'لم يتم العثور على أصناف صالحة في الملف.';
            setExcelProgress(0, 'لا توجد أصناف صالحة', 'راجع ترتيب الأعمدة داخل ملف Excel.');
            return;
        }

        const importModeInput = document.querySelector("input[name='excel_import_mode']:checked");
        const importMode = importModeInput ? importModeInput.value : 'append';
        const tableBody = document.getElementById("tableBody");

        if(importMode === 'replace'){
            tableBody.innerHTML = '';
        }else{
            const emptyRows = Array.from(tableBody.querySelectorAll('tr')).filter(function(tr){
                const barcode = (tr.querySelector("[name='barcode[]']")?.value || '').trim();
                const itemName = (tr.querySelector("[name='item_name[]']")?.value || '').trim();
                const shad = (tr.querySelector("[name='shad[]']")?.value || '').trim();
                const taxRate = (tr.querySelector("[name='tax_rate[]']")?.value || '').trim();
                const costBefore = (tr.querySelector("[name='cost_before[]']")?.value || '').trim();
                const sellPrice = (tr.querySelector("[name='sell_price[]']")?.value || '').trim();
                const feeValue = (tr.querySelector("[name='fee[]']")?.value || '').trim();

                return barcode === '' && itemName === '' && shad === '' && (taxRate === '' || parseTaxRate(taxRate) === 15) && costBefore === '' && sellPrice === '' && (feeValue === '' || parseFloat(feeValue || '0') === parseFloat(String(supplierFee || 0)));
            });

            emptyRows.forEach(function(tr){
                if(tableBody.querySelectorAll('tr').length > 0){
                    tr.remove();
                }
            });
        }

        const modeText = importMode === 'replace' ? 'بدل الموجود' : 'مكمل على الموجود';
        setExcelProgress(90, 'جاري إضافة الأصناف للجدول...', 'تم العثور على ' + imported.length + ' صنف صالح - الوضع: ' + modeText + '.');

        let index = 0;
        const chunkSize = 20;

        function addImportedChunk(){
            const end = Math.min(index + chunkSize, imported.length);
            const fragment = document.createDocumentFragment();

            for(; index < end; index++){
                const item = imported[index];
                const row = document.createElement("tr");

                const costBefore = parseFloat(item.cost_before) || 0;
                const sellPrice = parseFloat(item.sell_price) || 0;
                const taxRate = parseTaxRate(item.tax_rate ?? 15);
                const costAfter = costBefore > 0 ? costBefore * (1 + taxRate / 100) : 0;
                const profit = costAfter > 0 && sellPrice > 0 ? ((sellPrice - costAfter) / costAfter) * 100 : 0;
                const feeValue = item.fee !== '' && item.fee !== null && item.fee !== undefined ? item.fee : supplierFee;

                row.innerHTML = `
                    <td><input name="barcode[]" type="text" inputmode="text" maxlength="80" placeholder="باركود / كود صنف" value="${escapeHtml(item.barcode || '')}"></td>
                    <td><input name="item_name[]" placeholder="اسم الصنف" value="${escapeHtml(item.name || '')}"></td>
                    <td><input name="shad[]" type="number" step="1" min="0" inputmode="numeric" placeholder="رقم" value="${escapeHtml(item.shad || '')}"></td>
                    <td>${taxSelectHtml(taxRate)}</td>
                    <td><input name="cost_before[]" type="number" step="0.01" oninput="calcRow(this)" placeholder="0" value="${escapeHtml(item.cost_before || '')}"></td>
                    <td><input name="cost_after[]" readonly placeholder="0" value="${costAfter ? costAfter.toFixed(2) : ''}"></td>
                    <td><input name="sell_price[]" type="number" step="0.01" oninput="calcRow(this)" placeholder="0" value="${escapeHtml(item.sell_price || '')}"></td>
                    <td><input name="profit[]" readonly placeholder="0" value="${profit ? profit.toFixed(2) : ''}"></td>
                    <td><input name="fee[]" class="fee" value="${escapeHtml(feeValue)}" oninput="calcTotal()"></td>
                    <td><button type="button" class="btn btn-delete" onclick="deleteRow(this)">✖</button></td>
                `;

                fragment.appendChild(row);
            }

            tableBody.appendChild(fragment);
            const tablePercent = 90 + ((index / imported.length) * 10);
            setExcelProgress(tablePercent, 'جاري إضافة الأصناف للجدول...', 'تمت إضافة ' + index + ' من ' + imported.length + ' صنف.');

            if(index < imported.length){
                setTimeout(addImportedChunk, 10);
                return;
            }

            calcTotal();
            finishButton();
            setExcelProgress(100, 'تم الاستيراد بنجاح', 'تم استيراد ' + imported.length + ' صنف - الوضع: ' + modeText + '. راجع البيانات ثم اضغط حفظ الأصناف.');

            if(status){
                status.innerText = 'تم استيراد ' + imported.length + ' صنف - الوضع: ' + modeText + '. راجع البيانات ثم اضغط حفظ الأصناف.';
            }
        }

        addImportedChunk();
    };

    worker.onerror = function(err){
        if(worker){
            worker.terminate();
            worker = null;
        }
        if(workerUrl){
            URL.revokeObjectURL(workerUrl);
            workerUrl = null;
        }
        finishButton();
        if(status) status.innerText = 'تعذر تحليل ملف Excel في الخلفية.';
        setExcelProgress(0, 'فشل الاستيراد', 'لو الملف من Excel كبير أو فيه تنسيق كتير، احفظه CSV أو انسخ البيانات في قالب نظيف.');
    };

    const reader = new FileReader();

    reader.onprogress = function(e){
        if(e.lengthComputable){
            const readPercent = Math.round((e.loaded / e.total) * 25);
            setExcelProgress(readPercent, 'جاري قراءة الملف...', 'تمت قراءة ' + Math.round(e.loaded / 1024) + ' KB من ' + Math.round(e.total / 1024) + ' KB');
        }else{
            setExcelProgress(15, 'جاري قراءة الملف...', 'جاري تحميل الملف داخل الصفحة.');
        }
    };

    reader.onload = function(e){
        setExcelProgress(30, 'تمت قراءة الملف...', 'بدأ تحليل Excel في الخلفية.');
        startFakeProgress();

        try{
            worker.postMessage({
                buffer: e.target.result,
                supplierFee: supplierFee
            }, [e.target.result]);
        }catch(err){
            finishButton();
            if(worker){
                worker.terminate();
                worker = null;
            }
            setExcelProgress(0, 'فشل الاستيراد', 'تعذر إرسال الملف للتحليل في الخلفية.');
        }
    };

    reader.onerror = function(){
        finishButton();
        if(worker){
            worker.terminate();
            worker = null;
        }
        if(status) status.innerText = 'تعذر قراءة الملف من الجهاز.';
        setExcelProgress(0, 'فشل قراءة الملف', 'اختار الملف مرة تانية وجرب.');
    };

    reader.readAsArrayBuffer(selectedFile);
}

function escapeHtml(value){
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function deleteRow(btn){
    let rows = document.querySelectorAll("#tableBody tr");

    if(rows.length === 1){
        rows[0].querySelectorAll("input").forEach(i => i.value = '');
        rows[0].querySelectorAll("[name='tax_rate[]']").forEach(i => i.value = '15');
        rows[0].querySelectorAll(".fee").forEach(i => i.value = supplierFee);
        calcTotal();
        return;
    }

    btn.closest("tr").remove();
    calcTotal();
}

function calcRow(input){
    let row = input.closest("tr");

    let before = parseFloat(row.querySelector("[name='cost_before[]']").value) || 0;
    let sell   = parseFloat(row.querySelector("[name='sell_price[]']").value) || 0;
    let taxRate = parseTaxRate(row.querySelector("[name='tax_rate[]']") ? row.querySelector("[name='tax_rate[]']").value : 15);

    let after = before * (1 + taxRate / 100);

    row.querySelector("[name='cost_after[]']").value = after ? after.toFixed(2) : '';

    let profit = 0;

    if(after > 0){
        profit = ((sell - after) / after) * 100;
    }

    row.querySelector("[name='profit[]']").value = profit ? profit.toFixed(2) : '';

    calcTotal();
}

function calcTotal(){
    let total = 0;

    document.querySelectorAll(".fee").forEach(f => {
        total += parseFloat(f.value) || 0;
    });

    document.getElementById("total").innerText = total.toFixed(2);
}

const supplierSearch = document.getElementById("supplier_search");
const supplierHidden = document.getElementById("supplier_name");
const resultsBox = document.getElementById("results");

supplierSearch.addEventListener("input", function(){
    supplierHidden.value = this.value;
});

supplierSearch.addEventListener("keyup", function(){

    let q = this.value;

    if(q.length < 2){
        resultsBox.innerHTML = '';
        return;
    }

    fetch("search_supplier.php?q=" + encodeURIComponent(q))
        .then(r => r.text())
        .then(d => {
            resultsBox.innerHTML = d;
        })
        .catch(() => {
            resultsBox.innerHTML = "<div class='item'>تعذر البحث عن المورد</div>";
        });
});

if(resultsBox){
    resultsBox.addEventListener("click", function(e){
        let item = e.target.closest(".item");

        if(!item){
            return;
        }

        if(!item.getAttribute("onclick")){
            let txt = item.textContent.trim();
            let name = txt.includes(" - ") ? txt.split(" - ").pop().trim() : txt;
            selectSupplier(name);
        }
    });
}

function selectSupplier(name){

    supplierSearch.value = name;
    supplierHidden.value = name;
    resultsBox.innerHTML = "";

    document.getElementById("form_action").value = "fetch_fee";
    document.getElementById("mainForm").submit();
}

document.getElementById("mainForm").addEventListener("submit", function(){
    if(!supplierHidden.value){
        supplierHidden.value = supplierSearch.value;
    }

    const actionValue = document.getElementById("form_action") ? document.getElementById("form_action").value : "";
    if(actionValue === "save"){
        showSavingOverlay();
    }
});
</script>

</body>
</html>
