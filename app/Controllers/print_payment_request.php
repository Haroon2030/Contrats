<?php

require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/scope_helper.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");


date_default_timezone_set('Asia/Riyadh');

function pp_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pp_money($value): string {
    return number_format((float)$value, 2);
}

function pp_money_dash($value): string {
    if ($value === null || $value === '') return '-';
    return number_format((float)$value, 2);
}

function pp_percent_dash($value): string {
    if ($value === null || $value === '') return '-';
    return rtrim(rtrim(number_format((float)$value, 2), '0'), '.') . '%';
}

function pp_date($value): string {
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') return '-';
    $ts = strtotime((string)$value);
    return $ts ? date('Y-m-d', $ts) : '-';
}

function pp_datetime($value): string {
    if (empty($value) || $value === '0000-00-00 00:00:00') return '-';
    $ts = strtotime((string)$value);
    return $ts ? date('Y-m-d H:i', $ts) : '-';
}

function pp_company_type_ar($type): string {
    return ((string)$type === 'food') ? 'غذائي' : 'لا غذائي';
}

function pp_approval_ar($status): string {
    return [
        'pending' => 'لم يعتمد بعد',
        'approved' => 'موافق',
        'rejected' => 'رافض'
    ][$status] ?? '-';
}

function pp_column_exists(VcDb $conn, string $table, string $column): bool {
    return vcColumnExists($conn, $table, $column);
}

function pp_get_settings(VcDb $conn): array {
    $settings = [];
    $res = $conn->query("SELECT setting_key, user_id FROM payment_approval_settings");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $settings[(string)$row['setting_key']] = (int)$row['user_id'];
        }
    }
    return $settings;
}

function pp_find_logo(): string {
    $path = 'uploads/vendorcore.png';
    return is_file(__DIR__ . '/' . $path) ? $path : '';
}

$requestId = (int)($_GET['id'] ?? 0);
if ($requestId <= 0) {
    http_response_code(400);
    die('رقم طلب السداد غير صحيح');
}

$stmtUser = $conn->prepare("SELECT id, username, role, is_admin, job_role FROM users WHERE id = ? LIMIT 1");
$stmtUser->bind_param("i", $uid);
$stmtUser->execute();
$currentUser = $stmtUser->get_result()->fetch_assoc() ?: [];
$stmtUser->close();

$settings = pp_get_settings($conn);
$financeManagerId = (int)($settings['finance_manager'] ?? 0);

$currentRole = (string)($currentUser['role'] ?? '');
$currentJobRole = (string)($currentUser['job_role'] ?? '');
$isAdmin = ((int)($currentUser['is_admin'] ?? 0) === 1) || $currentRole === 'admin' || $currentJobRole === 'admin';
$isCommercialManager = ($currentJobRole === 'commercial_manager');
$isAccountant = ($currentJobRole === 'accountant');
$isFinanceManager = ($financeManagerId > 0 && $uid === $financeManagerId)
    || in_array($currentJobRole, ['finance_manager', 'financial_manager', 'finance', 'accounts_manager', 'accounting_manager'], true);

$canPrintPaymentRequest = $isAdmin || $isCommercialManager || $isFinanceManager || $isAccountant;
if (!$canPrintPaymentRequest) {
    http_response_code(403);
    die('❌ ليس لديك صلاحية طباعة سند الصرف');
}

$hasBeforeEarlyDiscount = pp_column_exists($conn, 'payment_request_approvals', 'amount_before_early_discount');
$hasEarlyDiscountPercent = pp_column_exists($conn, 'payment_request_approvals', 'early_payment_discount_percent');
$hasEarlyDiscountAmount = pp_column_exists($conn, 'payment_request_approvals', 'early_payment_discount_amount');

$sectionBeforeSelect = $hasBeforeEarlyDiscount ? 'section_ap.amount_before_early_discount' : 'NULL';
$commercialBeforeSelect = $hasBeforeEarlyDiscount ? 'commercial_ap.amount_before_early_discount' : 'NULL';
$financeBeforeSelect = $hasBeforeEarlyDiscount ? 'finance_ap.amount_before_early_discount' : 'NULL';

$sectionPercentSelect = $hasEarlyDiscountPercent ? 'section_ap.early_payment_discount_percent' : 'NULL';
$commercialPercentSelect = $hasEarlyDiscountPercent ? 'commercial_ap.early_payment_discount_percent' : 'NULL';
$financePercentSelect = $hasEarlyDiscountPercent ? 'finance_ap.early_payment_discount_percent' : 'NULL';

$sectionDiscountSelect = $hasEarlyDiscountAmount ? 'section_ap.early_payment_discount_amount' : 'NULL';
$commercialDiscountSelect = $hasEarlyDiscountAmount ? 'commercial_ap.early_payment_discount_amount' : 'NULL';
$financeDiscountSelect = $hasEarlyDiscountAmount ? 'finance_ap.early_payment_discount_amount' : 'NULL';

$sql = "
SELECT
    pr.*,
    creator.username AS created_by_name,
    final_user.username AS final_approved_by_name,

    section_ap.status AS section_status,
    section_ap.note AS section_note,
    section_ap.approved_amount AS section_approved_amount,
    {$sectionBeforeSelect} AS section_before_early_discount,
    {$sectionPercentSelect} AS section_early_discount_percent,
    {$sectionDiscountSelect} AS section_early_discount_amount,
    section_ap.acted_at AS section_acted_at,
    section_user.username AS section_user_name,

    commercial_ap.status AS commercial_status,
    commercial_ap.note AS commercial_note,
    commercial_ap.approved_amount AS commercial_approved_amount,
    {$commercialBeforeSelect} AS commercial_before_early_discount,
    {$commercialPercentSelect} AS commercial_early_discount_percent,
    {$commercialDiscountSelect} AS commercial_early_discount_amount,
    commercial_ap.acted_at AS commercial_acted_at,
    commercial_user.username AS commercial_user_name,

    finance_ap.status AS finance_status,
    finance_ap.note AS finance_note,
    finance_ap.approved_amount AS finance_approved_amount,
    {$financeBeforeSelect} AS finance_before_early_discount,
    {$financePercentSelect} AS finance_early_discount_percent,
    {$financeDiscountSelect} AS finance_early_discount_amount,
    finance_ap.acted_at AS finance_acted_at,
    finance_user.username AS finance_user_name
FROM payment_requests pr
LEFT JOIN users creator ON creator.id = pr.created_by
LEFT JOIN users final_user ON final_user.id = pr.final_approved_by
LEFT JOIN payment_request_approvals section_ap ON section_ap.request_id = pr.id AND section_ap.step_key = 'section_manager'
LEFT JOIN users section_user ON section_user.id = section_ap.approver_user_id
LEFT JOIN payment_request_approvals commercial_ap ON commercial_ap.request_id = pr.id AND commercial_ap.step_key = 'commercial_manager'
LEFT JOIN users commercial_user ON commercial_user.id = commercial_ap.approver_user_id
LEFT JOIN payment_request_approvals finance_ap ON finance_ap.request_id = pr.id AND finance_ap.step_key = 'finance_manager'
LEFT JOIN users finance_user ON finance_user.id = finance_ap.approver_user_id
WHERE pr.id = ?
LIMIT 1
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('خطأ في تجهيز سند الصرف: ' . pp_e($conn->error));
}
$stmt->bind_param("i", $requestId);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    http_response_code(404);
    die('طلب السداد غير موجود');
}

if ((string)$request['status'] !== 'approved_final') {
    http_response_code(403);
    die('لا يمكن طباعة سند الصرف قبل الاعتماد النهائي من المدير المالي');
}

$requestedAmount = (float)($request['amount_required'] ?? 0);
$finalAmount = (float)($request['final_amount'] ?? 0);
if ($finalAmount <= 0) {
    $finalAmount = $requestedAmount;
}
$totalSaving = round($requestedAmount - $finalAmount, 2);
if ($totalSaving < 0) {
    $totalSaving = 0;
}

$latestEarlyDiscountPercent = null;
$latestEarlyDiscountAmount = null;
foreach (['finance', 'commercial', 'section'] as $stage) {
    if ($latestEarlyDiscountAmount === null && ($request[$stage . '_early_discount_amount'] ?? null) !== null) {
        $latestEarlyDiscountAmount = (float)$request[$stage . '_early_discount_amount'];
    }
    if ($latestEarlyDiscountPercent === null && ($request[$stage . '_early_discount_percent'] ?? null) !== null) {
        $latestEarlyDiscountPercent = (float)$request[$stage . '_early_discount_percent'];
    }
}
$hasEarlyDiscountSummary = (
    ($latestEarlyDiscountPercent !== null && (float)$latestEarlyDiscountPercent > 0)
    || ($latestEarlyDiscountAmount !== null && (float)$latestEarlyDiscountAmount > 0)
    || ((float)$totalSaving > 0)
);

$accountantNotes = trim((string)($request['notes'] ?? ''));
$logoPath = pp_find_logo();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>طباعة سند صرف #<?= (int)$request['id'] ?></title>
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0;color:#111827;font-family:Tahoma,Arial,sans-serif;background:#eef1f7;font-size:12px}
.page{position:relative;width:190mm;min-height:270mm;margin:10px auto;background:#fff;padding:8mm 9mm;border:1px solid #d6deeb;box-shadow:0 8px 24px rgba(15,23,42,.12);overflow:hidden}
.top-actions{display:flex;gap:8px;justify-content:flex-start;margin-bottom:8px;position:relative;z-index:3}
.btn{border:0;border-radius:8px;padding:7px 15px;font-weight:800;cursor:pointer;text-decoration:none;background:#4f46e5;color:#fff;font-size:12px}.btn.gray{background:#64748b}
.watermark{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:0;opacity:.055;transform:rotate(-18deg)}
.watermark img{width:125mm;max-height:88mm;object-fit:contain}
.content{position:relative;z-index:1}
.header{display:table;width:100%;border-bottom:2px solid #111827;padding-bottom:4mm;margin-bottom:5mm;direction:rtl;table-layout:fixed}
.hcol{display:table-cell;vertical-align:middle}.logo-col{width:34%;text-align:right}.logo-col img{max-width:45mm;max-height:22mm;object-fit:contain;display:inline-block}
.title-col{width:32%;text-align:center}.title-col h1{margin:0;font-size:24px;font-weight:900;color:#111827}.title-col .subtitle{margin-top:1.5mm;font-size:11px;font-weight:800;color:#475569}.title-col .request-no{margin-top:1.7mm;font-size:16px;font-weight:900;color:#4f46e5}
.meta-col{width:34%;text-align:left}.meta-box{display:inline-block;text-align:right;font-size:10px;font-weight:800;line-height:1.9;color:#111827}.meta-box b{color:#475569;font-weight:900}
.company-text{font-size:18px;font-weight:900;color:#111827}.company-sub{font-size:11px;font-weight:800;color:#475569;margin-top:1mm}
.info-table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid #cbd5e1;border-radius:10px;overflow:hidden;background:#fff;margin-bottom:4mm;table-layout:fixed}
.info-table th{background:#eef4ff;color:#334155;font-size:10px;font-weight:900;border-left:1px solid #d7e0ec;border-bottom:1px solid #d7e0ec;padding:2mm;text-align:right;white-space:nowrap}.info-table td{font-size:12px;font-weight:900;border-left:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;padding:2.3mm;text-align:right;background:rgba(255,255,255,.9);vertical-align:middle}.info-table tr:last-child td{border-bottom:0}.info-table th:last-child,.info-table td:last-child{border-left:0}.supplier-value{font-size:18px!important;color:#111827}.amount-value{font-size:16px!important;color:#4f46e5!important}.final-amount-head{text-align:center!important;font-size:12px!important;background:#eef2ff!important}.final-amount-row{text-align:center!important;font-size:24px!important;font-weight:900!important;padding:3.4mm 2mm!important;background:#fafaff!important}.saving-value{font-size:15px!important;color:#16a34a!important}.danger-value{font-size:15px!important;color:#b42318!important}
.section-title{font-size:13px;font-weight:900;color:#111827;border-right:5px solid #4f46e5;padding-right:2mm;margin:4mm 0 2mm}.notes{border:1px solid #cbd5e1;border-radius:8px;background:rgba(248,250,252,.88);padding:2mm 3mm;min-height:9mm;font-size:11px;font-weight:700;line-height:1.6;color:#334155;margin-bottom:3mm}.link{color:#4f46e5;font-weight:900;text-decoration:none}
.approvals{width:100%;border-collapse:collapse;margin-bottom:4mm;background:rgba(255,255,255,.92);table-layout:fixed}.approvals th,.approvals td{border:1px solid #cbd5e1;padding:1.35mm;text-align:center;font-size:9px;vertical-align:middle;line-height:1.45;word-break:break-word}.approvals th{background:#eaf2ff;color:#111827;font-weight:900}.approvals .stage{font-weight:900;color:#4f46e5}.approvals .note-cell{text-align:right}
.payment-letter{border:1px solid #cbd5e1;border-radius:10px;background:rgba(248,250,252,.92);padding:3mm 4mm;margin:4mm 0 3mm;font-size:12px;font-weight:800;line-height:1.9;color:#111827;break-inside:avoid}.payment-letter-title{font-size:13px;font-weight:900;color:#4f46e5;margin-bottom:1.5mm}.payment-letter p{margin:1.2mm 0}.payment-letter .final-pay{font-weight:900;color:#4f46e5}.payment-letter .supplier-pay{font-weight:900;color:#111827}.signatures{display:flex;justify-content:flex-end;margin-top:5mm}.sig{width:68mm;height:22mm;border:1px dashed #64748b;border-radius:8px;display:flex;align-items:center;justify-content:center;text-align:center;background:rgba(255,255,255,.82)}.sig b{font-size:14px;color:#111827}
@media print{
    @page{size:A4 portrait;margin:7mm}
    html,body{background:#fff;font-size:11px}.page{width:196mm;min-height:auto;margin:0;border:0;box-shadow:none;padding:0;overflow:hidden}.top-actions{display:none}.header{display:table!important;table-layout:fixed!important;padding-bottom:3.2mm;margin-bottom:3.8mm;break-inside:avoid}.hcol{display:table-cell!important}.logo-col img{max-width:42mm;max-height:19mm}.title-col h1{font-size:21px}.title-col .subtitle{font-size:9.5px}.title-col .request-no{font-size:14px}.meta-box{font-size:8.8px;line-height:1.75}.info-table{table-layout:fixed!important;margin-bottom:3mm;break-inside:avoid}.info-table th{font-size:8.4px;padding:1.35mm}.info-table td{font-size:9.8px;padding:1.5mm}.supplier-value{font-size:14px!important}.amount-value{font-size:13.5px!important}.final-amount-row{font-size:20px!important;padding:2.4mm 1.5mm!important}.section-title{font-size:11px;margin:3mm 0 1.4mm}.notes{padding:1.5mm 2mm;min-height:7mm;font-size:9.4px;margin-bottom:2.2mm}.approvals th,.approvals td{font-size:7.5px;padding:.9mm}.payment-letter{font-size:9.5px;padding:1.8mm 2.2mm;margin:2.4mm 0 2mm;line-height:1.65}.payment-letter-title{font-size:10.5px;margin-bottom:.7mm}.signatures{margin-top:3.5mm}.sig{width:60mm;height:17mm}.sig b{font-size:12px}.watermark img{width:120mm}
}
@media screen and (max-width:760px){.page{width:calc(100% - 12px);padding:12px}.header{display:block}.hcol{display:block;width:100%!important;text-align:center!important;margin-bottom:8px}.meta-col{text-align:center}.meta-box{text-align:center}.logo-col img{margin:auto}.top-actions{justify-content:center}}
</style>
</head>
<body>
<div class="page">
    <div class="top-actions">
        <button class="btn" onclick="window.print()">طباعة</button>
        <a class="btn gray" href="payment_approvals.php">رجوع</a>
    </div>

    <div class="watermark">
        <?php if ($logoPath !== ''): ?>
            <img src="<?= pp_e($logoPath) ?>" alt="علامة مائية">
        <?php else: ?>
            <span>أسواق الرشيد</span>
        <?php endif; ?>
    </div>

    <div class="content">
    <div class="header">
        <div class="hcol logo-col">
            <?php if ($logoPath !== ''): ?>
                <img src="<?= pp_e($logoPath) ?>" alt="لوجو أسواق الرشيد">
            <?php else: ?>
                <div class="company-text">أسواق الرشيد</div>
                <div class="company-sub">للتجارة</div>
            <?php endif; ?>
        </div>

        <div class="hcol title-col">
            <h1>سند صرف</h1>
            <div class="subtitle">طلب سداد مورد معتمد نهائيًا</div>
            <div class="request-no">رقم الطلب #<?= (int)$request['id'] ?></div>
        </div>

        <div class="hcol meta-col">
            <div class="meta-box">
                <div><b>تاريخ الطباعة:</b> <?= pp_datetime(date('Y-m-d H:i:s')) ?></div>
                <div><b>تاريخ إنشاء الطلب:</b> <?= pp_datetime($request['created_at']) ?></div>
                <div><b>أنشأ الطلب:</b> <?= pp_e($request['created_by_name'] ?? '-') ?></div>
            </div>
        </div>
    </div>

    <table class="info-table">
        <tr><th colspan="3">اسم المورد</th></tr>
        <tr><td colspan="3" class="supplier-value"><?= pp_e($request['supplier_name']) ?></td></tr>
        <tr>
            <th>رقم السند</th>
            <th>تاريخ الفاتورة المراد سدادها</th>
            <th>القسم</th>
        </tr>
        <tr>
            <td><?= pp_e($request['voucher_number']) ?></td>
            <td><?= pp_e(pp_date($request['invoice_date'])) ?></td>
            <td><?= pp_e(pp_company_type_ar($request['company_type'])) ?></td>
        </tr>
        <tr>
            <th>أنشأ طلب السداد</th>
            <th>فترة السداد المتفق عليها</th>
            <th>تاريخ السداد المستحق</th>
        </tr>
        <tr>
            <td><?= pp_e($request['created_by_name'] ?? '-') ?></td>
            <td><?= isset($request['agreed_payment_days']) && $request['agreed_payment_days'] !== null && $request['agreed_payment_days'] !== '' ? pp_e($request['agreed_payment_days']) . ' يوم' : '-' ?></td>
            <td><?= pp_e(pp_date($request['payment_due_date'] ?? '')) ?></td>
        </tr>
        <tr>
            <th>رصيد المورد المالي</th>
            <th>تكلفة مخزون المورد بالفروع</th>
            <th>المبلغ المطلوب من المحاسب</th>
        </tr>
        <tr>
            <td><?= pp_money($request['supplier_financial_balance']) ?></td>
            <td><?= pp_money($request['supplier_branch_balance']) ?></td>
            <td><?= pp_money($requestedAmount) ?></td>
        </tr>
        <?php if ($hasEarlyDiscountSummary): ?>
        <tr>
            <th colspan="2">نسبة السداد المعجل</th>
            <th>إجمالي فرق السداد / التخفيض</th>
        </tr>
        <tr>
            <td colspan="2"><?= $latestEarlyDiscountPercent !== null ? pp_percent_dash($latestEarlyDiscountPercent) : '-' ?></td>
            <td class="<?= $totalSaving > 0 ? 'saving-value' : '' ?>"><?= pp_money($totalSaving) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th colspan="3" class="final-amount-head">آخر مبلغ معتمد بعد أي تعديل أو خصم</th>
        </tr>
        <tr>
            <td colspan="3" class="amount-value final-amount-row"><?= pp_money($finalAmount) ?></td>
        </tr>
    </table>

    <?php if($accountantNotes !== ''): ?>
        <div class="section-title">ملاحظات المحاسب</div>
        <div class="notes"><?= nl2br(pp_e($accountantNotes)) ?></div>
    <?php endif; ?>

    <div class="section-title">سجل الاعتماد والتعديلات</div>
    <table class="approvals">
        <thead>
            <tr>
                <th>المرحلة</th>
                <th>المستخدم</th>
                <th>الحالة</th>
                <th>قبل الخصم</th>
                <th>نسبة السداد المعجل</th>
                <th>فرق السداد</th>
                <th>المعتمد بعد الخصم</th>
                <th>التاريخ</th>
                <th>الملاحظة</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="stage">المحاسب</td>
                <td><?= pp_e($request['created_by_name'] ?? '-') ?></td>
                <td>طلب جديد</td>
                <td>-</td>
                <td>-</td>
                <td>-</td>
                <td><?= pp_money($requestedAmount) ?></td>
                <td><?= pp_datetime($request['created_at']) ?></td>
                <td class="note-cell"><?= $accountantNotes !== '' ? nl2br(pp_e($accountantNotes)) : '-' ?></td>
            </tr>
            <tr>
                <td class="stage">مدير القسم</td>
                <td><?= pp_e($request['section_user_name'] ?? '-') ?></td>
                <td><?= pp_e(pp_approval_ar($request['section_status'] ?? 'pending')) ?></td>
                <td><?= pp_money_dash($request['section_before_early_discount'] ?? null) ?></td>
                <td><?= pp_percent_dash($request['section_early_discount_percent'] ?? null) ?></td>
                <td><?= pp_money_dash($request['section_early_discount_amount'] ?? null) ?></td>
                <td><?= pp_money_dash($request['section_approved_amount'] ?? null) ?></td>
                <td><?= pp_datetime($request['section_acted_at'] ?? '') ?></td>
                <td class="note-cell"><?= !empty($request['section_note']) ? pp_e($request['section_note']) : '-' ?></td>
            </tr>
            <tr>
                <td class="stage">المدير التجاري</td>
                <td><?= pp_e($request['commercial_user_name'] ?? '-') ?></td>
                <td><?= pp_e(pp_approval_ar($request['commercial_status'] ?? 'pending')) ?></td>
                <td><?= pp_money_dash($request['commercial_before_early_discount'] ?? null) ?></td>
                <td><?= pp_percent_dash($request['commercial_early_discount_percent'] ?? null) ?></td>
                <td><?= pp_money_dash($request['commercial_early_discount_amount'] ?? null) ?></td>
                <td><?= pp_money_dash($request['commercial_approved_amount'] ?? null) ?></td>
                <td><?= pp_datetime($request['commercial_acted_at'] ?? '') ?></td>
                <td class="note-cell"><?= !empty($request['commercial_note']) ? pp_e($request['commercial_note']) : '-' ?></td>
            </tr>
            <tr>
                <td class="stage">المدير المالي</td>
                <td><?= pp_e($request['finance_user_name'] ?? '-') ?></td>
                <td><?= pp_e(pp_approval_ar($request['finance_status'] ?? 'pending')) ?></td>
                <td><?= pp_money_dash($request['finance_before_early_discount'] ?? null) ?></td>
                <td><?= pp_percent_dash($request['finance_early_discount_percent'] ?? null) ?></td>
                <td><?= pp_money_dash($request['finance_early_discount_amount'] ?? null) ?></td>
                <td><?= pp_money_dash($request['finance_approved_amount'] ?? null) ?></td>
                <td><?= pp_datetime($request['finance_acted_at'] ?? '') ?></td>
                <td class="note-cell"><?= !empty($request['finance_note']) ? pp_e($request['finance_note']) : '-' ?></td>
            </tr>
        </tbody>
    </table>

    <div class="payment-letter">
        <div class="payment-letter-title">السادة الأفاضل / المسؤولون عن السداد</div>
        <p>تحية طيبة وبعد،</p>
        <p>
            بعد المراجعة واعتماد طلب السداد، نرجو التكرم باتخاذ اللازم نحو سداد مبلغ وقدره
            <span class="final-pay"><?= pp_money($finalAmount) ?></span>
            ريال سعودي إلى المورد
            <span class="supplier-pay"><?= pp_e($request['supplier_name']) ?></span>،
            وذلك وفقًا للمبلغ النهائي المعتمد في سجل الاعتماد أعلاه.
        </p>
        <p>وتفضلوا بقبول فائق الاحترام.</p>
    </div>

    <div class="signatures">
        <div class="sig"><b>اعتماد المدير المالي</b></div>
    </div>
    </div>
</div>
</body>
</html>
