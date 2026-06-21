<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once VC_HELPERS . '/scope_helper.php';



function getUserPageScopePrint(VcDb $conn, int $uid, string $pageName): string {
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



date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value): string {
    return number_format((float)$value, 2);
}

function cleanDate($value): string {
    $value = trim((string)$value);

    if ($value === '' || $value === '0000-00-00') {
        return '-';
    }

    return date("Y-m-d", strtotime($value));
}

function supplierStatusArabic($value): string {
    $value = trim((string)$value);

    $map = [
        'new' => 'جديد',
        'registered' => 'مسجل',
        'old' => 'مسجل',
        'exist' => 'مسجل',
        'exists' => 'مسجل',
        'مورد جديد' => 'جديد',
        'جديد' => 'جديد',
        'مسجل' => 'مسجل',
    ];

    return $map[$value] ?? ($value !== '' ? $value : '-');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    die("❌ لازم تسجل دخول");
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die("❌ رقم العقد غير موجود");
}


$logo = "uploads/vendorcore.png";

try {
    $res_logo = $conn->query("SELECT site_logo FROM settings LIMIT 1");

    if ($res_logo && $res_logo->num_rows > 0) {
        $logo_row = $res_logo->fetch_assoc();

        if (!empty($logo_row['site_logo'])) {
            $logo = $logo_row['site_logo'];
        }
    }
} catch (Throwable $e) {
    $logo = "uploads/vendorcore.png";
}


$stmtUser = $conn->prepare("SELECT is_admin, role, job_role, is_supervisor FROM users WHERE id = ? LIMIT 1");
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$userRow = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$is_admin = !empty($userRow) && (
    (int)($userRow['is_admin'] ?? 0) === 1 ||
    ($userRow['role'] ?? '') === 'admin'
);
$currentJobRole = (string)($userRow['job_role'] ?? 'user');
$isCommercialManager = ($currentJobRole === 'commercial_manager');
$isAdminLike = ($is_admin || $isCommercialManager);

$contractsScope = getUserPageScopePrint($conn, $user_id, 'contracts');


$canPrintAllContracts = ($isAdminLike || $contractsScope !== 'none');


$myContractsScope = getUserPageScopePrint($conn, $user_id, 'my_contracts');
$canPrintAllMyContracts = ($isAdminLike || $myContractsScope !== 'none');

$draftsScope = getUserPageScopePrint($conn, $user_id, 'drafts');
$canPrintAllDrafts = ($isAdminLike || $draftsScope !== 'none');

$underReviewScope = getUserPageScopePrint($conn, $user_id, 'under_review');
$canPrintAllUnderReview = ($isAdminLike || $underReviewScope !== 'none');


$financePageNames = [
    'accounting',
    'finance',
    'finance_items',
    'accounting_api',
    'accounts',
    'rents_accounting',
    'contracts_accounting'
];

$canPrintFinanceContracts = false;

foreach ($financePageNames as $financePageName) {
    if (getUserPageScopePrint($conn, $user_id, $financePageName) !== 'none') {
        $canPrintFinanceContracts = true;
        break;
    }
}

$canPrintAnyContract = ($canPrintAllContracts || $canPrintFinanceContracts);


$stmt = $conn->prepare("
    SELECT contracts.*, users.username 
    FROM contracts
    LEFT JOIN users ON users.id = contracts.created_by
    WHERE contracts.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) {
    die("❌ العقد غير موجود أو ليس لديك صلاحية لعرضه");
}


$contractOwnerId = (int)($data['created_by'] ?? 0);
$contractStatus  = (string)($data['status'] ?? '');

$isContractOwner = ($contractOwnerId === $user_id);

$contractsScopedIds   = vcGetScopedUserIds($conn, $user_id, $contractsScope, $isAdminLike);
$myContractsScopedIds = vcGetScopedUserIds($conn, $user_id, $myContractsScope, $isAdminLike);
$draftsScopedIds      = vcGetScopedUserIds($conn, $user_id, $draftsScope, $isAdminLike);
$underReviewScopedIds = vcGetScopedUserIds($conn, $user_id, $underReviewScope, $isAdminLike);

$canPrintByContractsScope = (
    $contractsScope !== 'none' &&
    vcIsUserInScope($contractOwnerId, $contractsScopedIds)
);

$canPrintByMyContractsAll = (
    $canPrintAllMyContracts &&
    vcIsUserInScope($contractOwnerId, $myContractsScopedIds) &&
    in_array($contractStatus, ['approved', 'rejected'], true)
);

$canPrintByDraftsAll = (
    $canPrintAllDrafts &&
    vcIsUserInScope($contractOwnerId, $draftsScopedIds) &&
    in_array($contractStatus, ['draft', 'review'], true)
);

$canPrintByUnderReviewAll = (
    $canPrintAllUnderReview &&
    vcIsUserInScope($contractOwnerId, $underReviewScopedIds) &&
    $contractStatus === 'review'
);

$canPrintThisContract = (
    $isAdminLike ||
    $canPrintFinanceContracts ||
    $isContractOwner ||
    $canPrintByContractsScope ||
    $canPrintByMyContractsAll ||
    $canPrintByDraftsAll ||
    $canPrintByUnderReviewAll
);

if (!$canPrintThisContract) {
    die("❌ العقد غير موجود أو ليس لديك صلاحية لعرضه");
}


$stmtAnnual = $conn->prepare("SELECT percent, target FROM annual_discounts WHERE contract_id = ? ORDER BY id ASC");
$stmtAnnual->bind_param("i", $id);
$stmtAnnual->execute();
$resAnnual = $stmtAnnual->get_result();

$annualRows = [];
while ($row = $resAnnual->fetch_assoc()) {
    $annualRows[] = $row;
}
$stmtAnnual->close();

$stmtEvents = $conn->prepare("SELECT value, name, note, type FROM events WHERE contract_id = ? ORDER BY id ASC");
$stmtEvents->bind_param("i", $id);
$stmtEvents->execute();
$resEvents = $stmtEvents->get_result();

$eventRows = [];
while ($row = $resEvents->fetch_assoc()) {
    $eventRows[] = $row;
}
$stmtEvents->close();

$stmtRents = $conn->prepare("SELECT * FROM rents WHERE contract_id = ? ORDER BY branch ASC, start_date ASC");
$stmtRents->bind_param("i", $id);
$stmtRents->execute();
$resRents = $stmtRents->get_result();

$rentRows = [];
$rentTotal = 0;

while ($row = $resRents->fetch_assoc()) {
    $rentRows[] = $row;
    $rentTotal += (float)($row['total'] ?? 0);
}
$stmtRents->close();


$type = (($data['source'] ?? '') === 'rent') ? "عقد إيجار" : "عقد سنوي";


$status_map = [
    'draft'    => 'تفاوض',
    'review'   => 'تحت المراجعة',
    'approved' => 'تمت الموافقة',
    'rejected' => 'مرفوض'
];

$status = $status_map[$data['status'] ?? ''] ?? 'غير معروف';

$created = cleanDate($data['created_at'] ?? '');
$start   = cleanDate($data['start_date'] ?? '');
$end     = cleanDate($data['end_date'] ?? '');

$hasDiscounts =
    (float)($data['discount_invoice'] ?? 0) > 0 ||
    (float)($data['discount_payment'] ?? 0) > 0 ||
    (float)($data['discount_quarter'] ?? 0) > 0;

$printedAt = date("Y-m-d H:i");
$isSupplierContractModel = (($data['contract_form_type'] ?? 'system') === 'supplier');
$supplierContractFile = trim((string)($data['supplier_contract_file'] ?? ''));
$supplierContractRef = trim((string)($data['supplier_contract_ref'] ?? ''));
$supplierContractNote = trim((string)($data['supplier_contract_note'] ?? ''));


$showTerms = (
    (($data['status'] ?? '') === 'approved') &&
    (($data['source'] ?? '') !== 'rent')
);


$showCover = (
    (($data['status'] ?? '') === 'approved') &&
    (($data['source'] ?? '') !== 'rent')
);

$contractYear = date('Y');
if (!empty($data['start_date']) && $data['start_date'] !== '0000-00-00') {
    $contractYear = date('Y', strtotime($data['start_date']));
}


$qr = null;
try {
    $qr = vc_ensure_contract_qr($conn, $id);
} catch (Throwable $e) {
    $qr = null;
}

$companyOne = "أسواق الرشيد للتجارة";
$companyTwo = trim((string)($data['supplier_name'] ?? '-'));
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>طباعة العقد #<?= (int)$id ?></title>

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
    background:#e8ecf3;
    color:#172033;
    font-size:12px;
    line-height:1.65;
}


.page{
    width:210mm;
    min-height:297mm;
    margin:14px auto;
    background:#fff;
    padding:14mm 13mm;
    box-shadow:0 10px 35px rgba(23,32,51,.14);
    position:relative;
    overflow:hidden;
}

.page > *:not(.page-watermark){
    position:relative;
    z-index:2;
}

.page-watermark{
    position:absolute;
    top:50%;
    left:50%;
    transform:translate(-50%, -50%);
    width:118mm;
    max-height:118mm;
    object-fit:contain;
    opacity:.045;
    filter:grayscale(1);
    z-index:1;
    pointer-events:none;
}

.print-actions{
    width:210mm;
    margin:12px auto;
    display:flex;
    justify-content:flex-end;
    gap:8px;
}

.print-btn{
    border:0;
    background:#6d4aff;
    color:#fff;
    min-height:38px;
    padding:0 16px;
    border-radius:12px;
    cursor:pointer;
    font-weight:900;
}

.close-btn{
    background:#64748b;
}


.top-header{
    display:grid;
    grid-template-columns:120px 1fr 150px;
    align-items:center;
    gap:12px;
    border-bottom:2px solid #6d4aff;
    padding-bottom:12px;
    margin-bottom:12px;
}

.logo-box{
    height:102px;
    border:1px solid #dbe2ec;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#fafbfd;
    padding:8px;
}

.logo-box img{
    max-width:138px;
    max-height:88px;
    object-fit:contain;
    filter:grayscale(100%);
}

.doc-title{
    text-align:center;
}

.doc-title h1{
    margin:0;
    color:#172033;
    font-size:24px;
    font-weight:900;
}

.doc-title .subtitle{
    margin-top:5px;
    color:#4f46e5;
    font-size:14px;
    font-weight:900;
}

.doc-meta{
    border:1px solid #dfe6f0;
    border-radius:14px;
    overflow:hidden;
    font-size:11px;
}

.meta-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    border-bottom:1px solid #dfe6f0;
}

.meta-row:last-child{
    border-bottom:none;
}

.meta-label{
    background:#f1f5f9;
    color:#667085;
    padding:6px;
    font-weight:900;
}

.meta-value{
    padding:6px;
    font-weight:900;
    color:#172033;
}


.badges{
    display:flex;
    justify-content:center;
    gap:8px;
    margin:10px 0 6px;
}

.badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:110px;
    min-height:28px;
    padding:4px 10px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
}

.badge-type{
    background:#f0edff;
    color:#4f46e5;
}

.badge-status{
    background:#ecfdf3;
    color:#166534;
}


.section{
    margin-top:11px;
    break-inside:avoid;
    page-break-inside:avoid;
}

.section-title{
    background:#f1f5f9;
    color:#4f46e5;
    padding:8px 10px;
    font-weight:900;
    border-right:5px solid #6d4aff;
    border-radius:10px;
    margin-bottom:8px;
    font-size:13px;
}

.grid{
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:8px;
}

.box{
    border:1px solid #dfe6f0;
    background:#fff;
    padding:8px;
    border-radius:10px;
    min-height:58px;
}

.label{
    font-size:10.5px;
    color:#667085;
    margin-bottom:4px;
    font-weight:900;
}

.value{
    font-size:12px;
    color:#172033;
    font-weight:900;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.note-box{
    border:1px solid #dfe6f0;
    background:#fff;
    padding:10px;
    border-radius:10px;
    min-height:50px;
    font-weight:800;
    white-space:pre-wrap;
}


table{
    width:100%;
    border-collapse:collapse;
    margin-top:7px;
    page-break-inside:auto;
}

tr{
    page-break-inside:avoid;
    page-break-after:auto;
}

th{
    background:#6d4aff;
    color:#fff;
    padding:7px 6px;
    border:1px solid #6d4aff;
    font-size:11px;
    font-weight:900;
    text-align:center;
}

td{
    border:1px solid #dfe6f0;
    padding:6px;
    text-align:center;
    font-size:11px;
    font-weight:800;
    vertical-align:middle;
}

tfoot td{
    background:#f8fafc;
    font-weight:900;
    color:#166534;
}


.sign{
    margin-top:22px;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
    break-inside:avoid;
    page-break-inside:avoid;
}

.sign-box{
    border:1px solid #dfe6f0;
    border-radius:12px;
    padding:12px;
    min-height:88px;
    text-align:center;
}

.sign-title{
    font-weight:900;
    color:#172033;
    margin-bottom:28px;
}

.sign-line{
    border-top:1px solid #172033;
    width:70%;
    margin:0 auto;
}


.footer-note{
    margin-top:14px;
    padding-top:8px;
    border-top:1px solid #dfe6f0;
    color:#667085;
    font-size:10px;
    display:flex;
    justify-content:space-between;
    gap:10px;
}


.empty-text{
    color:#94a3b8;
}





.terms-page{
    page-break-before:always;
}

.terms-header{
    border-bottom:2px solid #111827;
    padding-bottom:10px;
    margin-bottom:12px;
    text-align:center;
}

.terms-title{
    font-size:22px;
    color:#111827;
    font-weight:900;
    margin-bottom:4px;
}

.terms-subtitle{
    font-size:12px;
    color:#374151;
    font-weight:900;
}

.terms-content{
    font-size:10.2px;
    line-height:1.9;
    color:#111827;
}

.terms-preamble{
    margin:0 0 7px;
    text-align:justify;
    font-weight:800;
}

.terms-main-heading{
    margin:14px 0 8px;
    padding:8px 10px;
    background:#f1f5f9;
    border-right:5px solid #111827;
    border-radius:10px;
    color:#111827;
    font-size:14px;
    font-weight:900;
    break-after:avoid;
    page-break-after:avoid;
}

.terms-clause{
    border:1px solid #d1d5db;
    border-radius:10px;
    padding:8px 9px;
    margin:8px 0;
    background:rgba(255,255,255,.86);
    break-inside:avoid;
    page-break-inside:avoid;
}

.terms-clause-head{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:12px;
    font-weight:900;
    color:#111827;
    margin-bottom:6px;
    padding-bottom:6px;
    border-bottom:1px solid #e5e7eb;
}

.terms-clause-num{
    width:24px;
    height:24px;
    min-width:24px;
    border-radius:50%;
    background:#111827;
    color:#fff;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:11px;
    font-weight:900;
}

.terms-paragraph,
.terms-subitem,
.terms-bullet{
    margin:4px 0;
    text-align:justify;
    font-weight:750;
    color:#111827;
}

.terms-subitem{
    padding-right:9px;
}

.terms-bullet{
    padding-right:12px;
}

.terms-def-table{
    width:100%;
    border-collapse:collapse;
    margin-top:8px;
    font-size:10px;
    break-inside:auto;
    page-break-inside:auto;
}

.terms-def-table th{
    background:#111827;
    color:#fff;
    border:1px solid #111827;
    padding:6px;
    font-weight:900;
}

.terms-def-table td{
    border:1px solid #d1d5db;
    padding:6px;
    vertical-align:top;
    text-align:right;
    font-weight:750;
}

.terms-def-table td:first-child{
    width:26%;
    font-weight:900;
    background:#f8fafc;
}

.terms-final-signatures{
    margin-top:18px;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
    break-inside:avoid;
    page-break-inside:avoid;
}

.terms-final-sign-box{
    border:1px solid #111827;
    border-radius:12px;
    padding:12px;
    min-height:135px;
    background:rgba(255,255,255,.9);
}

.terms-party{
    font-size:12px;
    color:#6b7280;
    font-weight:900;
    text-align:center;
}

.terms-party-name{
    font-size:14px;
    color:#111827;
    font-weight:900;
    text-align:center;
    margin:4px 0 14px;
}

.terms-sign-field{
    display:grid;
    grid-template-columns:58px 1fr;
    gap:8px;
    align-items:end;
    margin-top:16px;
    font-size:12px;
    font-weight:900;
}

.terms-sign-field div{
    border-bottom:1px solid #111827;
    min-height:22px;
}

.terms-sign-only{
    margin-top:26px;
    border-bottom:1px solid #111827;
    min-height:34px;
}

.terms-footer-note{
    margin-top:14px;
    padding-top:8px;
    border-top:1px solid #d1d5db;
    color:#6b7280;
    font-size:10px;
    display:flex;
    justify-content:space-between;
    gap:10px;
    break-inside:avoid;
    page-break-inside:avoid;
}




.cover-page{
    width:210mm;
    min-height:297mm;
    margin:14px auto;
    background:#fff;
    padding:15mm 14mm;
    box-shadow:0 10px 35px rgba(23,32,51,.14);
    position:relative;
    overflow:hidden;
    page-break-after:always;
    display:flex;
    align-items:center;
    justify-content:center;
}

.cover-frame{
    position:absolute;
    inset:7mm;
    border:2px solid #111827;
}

.cover-frame::before{
    content:"";
    position:absolute;
    inset:4mm;
    border:1px solid #6b7280;
}

.cover-corner{
    position:absolute;
    width:34mm;
    height:34mm;
    border-color:#111827;
    opacity:.55;
}

.cover-corner.tl{top:10mm;right:10mm;border-top:1px solid;border-right:1px solid;}
.cover-corner.tr{top:10mm;left:10mm;border-top:1px solid;border-left:1px solid;}
.cover-corner.bl{bottom:10mm;right:10mm;border-bottom:1px solid;border-right:1px solid;}
.cover-corner.br{bottom:10mm;left:10mm;border-bottom:1px solid;border-left:1px solid;}

.cover-watermark{
    position:absolute;
    top:50%;
    left:50%;
    transform:translate(-50%,-50%);
    width:135mm;
    height:135mm;
    object-fit:contain;
    opacity:.035;
    filter:grayscale(1);
}

.cover-content{
    position:relative;
    z-index:2;
    text-align:center;
    width:100%;
    color:#111827;
}

.cover-logo{
    text-align:center;
    margin-top:6mm;
    position:relative;
    z-index:2;
}

.cover-logo img{
    width:210px;
    max-height:170px;
    object-fit:contain;
    filter:grayscale(100%);
}

.cover-title{
    font-size:43px;
    font-weight:900;
    letter-spacing:.5px;
    margin-bottom:10mm;
}

.cover-divider{
    width:90mm;
    height:1px;
    background:#111827;
    margin:0 auto 9mm;
    position:relative;
}

.cover-divider::after{
    content:"◆";
    position:absolute;
    left:50%;
    top:50%;
    transform:translate(-50%,-52%);
    background:#fff;
    padding:0 8px;
    font-size:14px;
}

.cover-company{
    font-size:30px;
    font-weight:900;
    margin-bottom:8mm;
}

.cover-party{
    font-size:22px;
    font-weight:900;
    margin-bottom:14mm;
    line-height:1.8;
}

.cover-period-title{
    font-size:20px;
    font-weight:900;
    margin-bottom:5mm;
}

.cover-period{
    display:inline-grid;
    grid-template-columns:auto auto auto auto;
    gap:8px 14px;
    align-items:center;
    font-size:18px;
    font-weight:900;
}

.cover-date{
    min-width:42mm;
    border-bottom:1px dotted #111827;
    padding:2mm 4mm;
    font-size:17px;
}

.cover-qr-box{
    width:100%;
    margin:10mm auto 0;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    gap:3mm;
    clear:both;
}

.cover-qr{
    display:block;
    width:34mm;
    height:34mm;
    object-fit:contain;
    border:1px solid #d1d5db;
    border-radius:4mm;
    padding:2mm;
    background:#fff;
    margin:0 auto;
}

.cover-qr-text{
    width:100%;
    text-align:center;
    color:#6b7280;
    font-size:10px;
    line-height:1.7;
    font-weight:800;
}

.cover-qr-link{
    direction:ltr;
    text-align:center;
    font-size:8.5px;
    color:#9ca3af;
    max-width:90mm;
    overflow-wrap:anywhere;
    word-break:break-all;
    margin:0 auto;
}

.cover-bottom{
    position:absolute;
    bottom:18mm;
    left:0;
    right:0;
    text-align:center;
    color:#6b7280;
    font-size:12px;
    font-weight:800;
}


.logo-box{
    height:102px;
    border:1px solid #dbe2ec;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#fafbfd;
    padding:8px;
}

.logo-box img{
    max-width:138px;
    max-height:88px;
    object-fit:contain;
    filter:grayscale(100%);
}


.supplier-status-ar{
    font-weight:900;
}


.terms-block{
    margin-top:14px;
    padding-top:12px;
    border-top:2px solid #111827;
    break-before:auto;
    page-break-before:auto;
}


.print-page-number{
    display:none;
}

@page{
    size:A4;
    margin:12mm 10mm 15mm 10mm;

    @bottom-center{
        content:"صفحة " counter(page) " من " counter(pages);
        font-family:"Cairo", Tahoma, Arial, sans-serif;
        font-size:10px;
        color:#111827;
    }
}

@media print{
    .cover-page{
        width:auto;
        min-height:calc(297mm - 27mm);
        margin:0;
        padding:15mm 14mm;
        box-shadow:none;
        page-break-after:always;
    }

    .cover-frame{
        inset:0;
    }

    .print-page-number{
        display:block;
        position:fixed;
        bottom:4mm;
        right:0;
        left:0;
        text-align:center;
        color:#111827;
        font-size:10px;
        font-weight:800;
        z-index:9999;
    }

    .terms-block{
        page-break-before:auto !important;
        break-before:auto !important;
    }
}


@media screen, print{
    .cover-content .cover-qr-box{
        width:100% !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        text-align:center !important;
    }

    .cover-content .cover-qr{
        margin-left:auto !important;
        margin-right:auto !important;
    }
}





@media print{
    body{
        background:#fff;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }

    .print-actions{
        display:none !important;
    }

    .page{
        width:auto;
        min-height:auto;
        margin:0;
        padding:0;
        box-shadow:none;
        overflow:visible;
    }

    .page-watermark{
        opacity:.035 !important;
        filter:grayscale(1) !important;
    }

    .top-header{
        margin-top:0;
    }

    th{
        background:#6d4aff !important;
        color:#fff !important;
    }

    .section-title{
        background:#f1f5f9 !important;
        color:#4f46e5 !important;
    }

    .badge-type{
        background:#f0edff !important;
        color:#4f46e5 !important;
    }

    .badge-status{
        background:#ecfdf3 !important;
        color:#166534 !important;
    }
}


.rent-print-footer{
    margin-top:16px;
    display:grid;
    grid-template-columns: 35mm 1fr;
    gap:14px;
    align-items:end;
    break-inside:avoid;
    page-break-inside:avoid;
}

.rent-inline-qr{
    text-align:center;
}

.rent-inline-qr img{
    width:27mm;
    height:27mm;
    object-fit:contain;
    border:1px solid #d1d5db;
    border-radius:3mm;
    padding:1.5mm;
    background:#fff;
    display:block;
    margin:0 auto 4px;
}

.rent-inline-qr div{
    font-size:8.5px;
    color:#6b7280;
    font-weight:800;
    line-height:1.5;
}

.rent-signatures{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px;
}

.rent-sign-box{
    border:1px solid #dfe6f0;
    border-radius:12px;
    min-height:70px;
    padding:10px;
    text-align:center;
    background:#fff;
}

.rent-sign-title{
    font-weight:900;
    color:#172033;
    margin-bottom:24px;
    font-size:11px;
}

.rent-sign-line{
    border-top:1px solid #172033;
    width:75%;
    margin:0 auto;
}

@media print{
    .rent-print-footer{
        margin-top:9px !important;
        grid-template-columns: 29mm 1fr !important;
        gap:9px !important;
    }

    .rent-inline-qr img{
        width:23mm !important;
        height:23mm !important;
        padding:1mm !important;
        margin-bottom:2px !important;
    }

    .rent-inline-qr div{
        font-size:7.5px !important;
        line-height:1.35 !important;
    }

    .rent-signatures{
        gap:8px !important;
    }

    .rent-sign-box{
        min-height:50px !important;
        padding:6px !important;
        border-radius:8px !important;
    }

    .rent-sign-title{
        font-size:9px !important;
        margin-bottom:17px !important;
    }
}



.supplier-contract-print-alert{
    border:2px solid #f59e0b;
    background:#fffbeb;
    color:#92400e;
    border-radius:14px;
    padding:12px 14px;
    margin:14px 0;
    font-size:13px;
    font-weight:900;
    line-height:1.8;
}
.supplier-contract-print-alert a{color:#4f46e5;text-decoration:none;font-weight:900;}

</style>
</head>

<body>

<div class="print-page-number">صفحة</div>

<div class="print-actions">
    <?php if($isSupplierContractModel && $supplierContractFile !== ''): ?>
        <a class="print-btn" target="_blank" href="<?= e($supplierContractFile) ?>">عرض عقد المورد</a>
    <?php endif; ?>
    <button class="print-btn" onclick="window.print()">طباعة ملخص VendorCore</button>
    <button class="print-btn close-btn" onclick="window.close()">إغلاق</button>
</div>


<?php if($showCover): ?>
<div class="cover-page">

    <div class="cover-frame"></div>
    <div class="cover-corner tl"></div>
    <div class="cover-corner tr"></div>
    <div class="cover-corner bl"></div>
    <div class="cover-corner br"></div>

    <img class="cover-watermark" src="/<?= e($logo) ?>" alt="Watermark">

    <div class="cover-content">

        <div class="cover-logo">
            <img src="/<?= e($logo) ?>" alt="Logo">
        </div>

        <div class="cover-title">عقد <?= e(date("Y", strtotime($start !== '-' ? $start : date("Y-m-d")))) ?></div>

        <div class="cover-divider"></div>

        <div class="cover-company">أسواق الرشيد للتجارة</div>

        <div class="cover-party">
            الطرف الثاني<br>
            <?= e($data['supplier_name'] ?? '-') ?>
        </div>

        <div class="cover-period-title">بداية العقد</div>

        <div class="cover-period">
            <span>من:</span>
            <span class="cover-date"><?= e($start) ?></span>
            <span>إلى:</span>
            <span class="cover-date"><?= e($end) ?></span>
        </div>

        <?php if(!empty($qr) && !empty($qr['public_path'])): ?>
            <div class="cover-qr-box">
                " alt="QR Code">
                <div class="cover-qr-text">
                    امسح الكود لفتح نسخة العقد الإلكترونية كاملة<br>
                    نفس الكود ثابت في كل مرة يتم فيها طباعة العقد
                </div>
            </div>
        <?php endif; ?>

    </div>

    <div class="cover-bottom">
        <?= e($type) ?> رقم #<?= (int)$id ?>
    </div>

</div>
<?php endif; ?>

<div class="page">

    <img class="page-watermark" src="/<?= e($logo) ?>" alt="Watermark">

    <div class="top-header">
        <div class="logo-box">
            <img src="/<?= e($logo) ?>" alt="Logo">
        </div>

        <div class="doc-title">
            <h1>عقد رقم #<?= (int)$id ?></h1>
            <div class="subtitle">نظام VendorCore لإدارة العقود والإيجارات</div>

            <div class="badges">
                <span class="badge badge-type"><?= e($type) ?></span>
                <span class="badge badge-status"><?= e($status) ?></span>
            </div>
        </div>

        <div class="doc-meta">
            <div class="meta-row">
                <div class="meta-label">تاريخ الطباعة</div>
                <div class="meta-value"><?= e($printedAt) ?></div>
            </div>
            <div class="meta-row">
                <div class="meta-label">تاريخ الإنشاء</div>
                <div class="meta-value"><?= e($created) ?></div>
            </div>
            <div class="meta-row">
                <div class="meta-label">بواسطة</div>
                <div class="meta-value"><?= e($data['username'] ?? '-') ?></div>
            </div>
        </div>
    </div>

    
    <div class="section">
        <div class="section-title">بيانات المورد والعقد</div>

        <div class="grid">
            <div class="box">
                <div class="label">اسم المورد</div>
                <div class="value"><?= e($data['supplier_name'] ?? '-') ?></div>
            </div>

            <div class="box">
                <div class="label">اسم المسؤول</div>
                <div class="value"><?= e($data['company_name'] ?? '-') ?></div>
            </div>

            <div class="box">
                <div class="label">رقم الجوال</div>
                <div class="value"><?= e($data['supplier_phone'] ?? '-') ?></div>
            </div>

            <div class="box">
                <div class="label">نوع العقد</div>
                <div class="value"><?= e($type) ?></div>
            </div>

            <?php if(($data['source'] ?? '') !== 'rent'): ?>
            <div class="box">
                <div class="label">فترة السداد</div>
                <div class="value">
                    <?= !empty($data['payment_period']) ? e($data['payment_period']) . ' يوم' : '<span class="empty-text">-</span>' ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="box">
                <div class="label">حالة المورد</div>
                <div class="value"><?= e(supplierStatusArabic($data['supplier_status'] ?? '')) ?></div>
            </div>

            <?php if(($data['source'] ?? '') !== 'rent'): ?>
            <div class="box">
                <div class="label">تاريخ البداية</div>
                <div class="value"><?= e($start) ?></div>
            </div>

            <div class="box">
                <div class="label">تاريخ النهاية</div>
                <div class="value"><?= e($end) ?></div>
            </div>
            <?php endif; ?>

            <div class="box">
                <div class="label">حالة العقد</div>
                <div class="value"><?= e($status) ?></div>
            </div>
        </div>
    </div>

    
    <?php if($hasDiscounts): ?>
        <div class="section">
            <div class="section-title">الخصومات</div>

            <table>
                <thead>
                    <tr>
                        <th>البند</th>
                        <th>النسبة</th>
                        <th>الملاحظات</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if((float)($data['discount_invoice'] ?? 0) > 0): ?>
                        <tr>
                            <td>خصم الفاتورة</td>
                            <td><?= e($data['discount_invoice']) ?>%</td>
                            <td><?= e($data['discount_invoice_note'] ?: '-') ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if((float)($data['discount_payment'] ?? 0) > 0): ?>
                        <tr>
                            <td>خصم السداد</td>
                            <td><?= e($data['discount_payment']) ?>%</td>
                            <td><?= e($data['discount_payment_note'] ?: '-') ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php if((float)($data['discount_quarter'] ?? 0) > 0): ?>
                        <tr>
                            <td>خصم ربع سنوي</td>
                            <td><?= e($data['discount_quarter']) ?>%</td>
                            <td><?= e($data['discount_quarter_note'] ?: '-') ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    
    <?php if(!empty($annualRows)): ?>
        <div class="section">
            <div class="section-title">الخصم السنوي</div>

            <table>
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
    <?php endif; ?>

    
    <?php if(!empty($eventRows)): ?>
        <div class="section">
            <div class="section-title">الفعاليات والرسوم</div>

            <table>
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
                            <td>
                                <?= (($row['type'] ?? '') === 'new_item') ? 'رسوم صنف جديد' : e($row['name'] ?? '-') ?>
                            </td>
                            <td><?= money($row['value'] ?? 0) ?> ريال</td>
                            <td><?= e($row['note'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    
    <?php if(!empty($rentRows)): ?>
        <div class="section">
            <div class="section-title">الإيجارات</div>

            <table>
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
                            <td><?= e(cleanDate($row['start_date'] ?? '')) ?></td>
                            <td><?= e(cleanDate($row['end_date'] ?? '')) ?></td>
                            <td><?= money($row['total'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

                <tfoot>
                    <tr>
                        <td colspan="6">إجمالي الإيجارات</td>
                        <td><?= money($rentTotal) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>

    
    <?php if(!empty($data['notes'])): ?>
        <div class="section">
            <div class="section-title">ملاحظات</div>
            <div class="note-box"><?= e($data['notes']) ?></div>
        </div>
    <?php endif; ?>

    <?php if((($data['source'] ?? '') === 'rent') && (($data['status'] ?? '') === 'approved')): ?>
        <div class="rent-print-footer">

            <?php if(!empty($qr) && !empty($qr['public_path'])): ?>
                <div class="rent-inline-qr">
                    " alt="QR Code">
                    <div>امسح الكود لفتح نسخة العقد الإلكترونية</div>
                </div>
            <?php else: ?>
                <div></div>
            <?php endif; ?>

            <div class="rent-signatures">
                <div class="rent-sign-box">
                    <div class="rent-sign-title">توقيع الشركة</div>
                    <div class="rent-sign-line"></div>
                </div>

                <div class="rent-sign-box">
                    <div class="rent-sign-title">توقيع المورد</div>
                    <div class="rent-sign-line"></div>
                </div>
            </div>

        </div>
    <?php endif; ?>

    <div class="footer-note">
        <span>VendorCore - نسخة طباعة العقد</span>
        <span>رقم العقد: #<?= (int)$id ?></span>
    </div>

</div>

<?php if($showTerms): ?>
    <?php include VC_VIEWS . '/partials/contract_terms.php'; ?>
<?php endif; ?>

<script>
window.addEventListener("load", function(){
    setTimeout(function(){
        window.print();
    }, 900);
});
</script>

<div class="print-footer-fixed"><span class="counter"></span></div>
</body>
</html>
