<?php
require_once VC_HELPERS . '/auth.php';



date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value): string {
    return number_format((float)$value, 2);
}

function cleanValue($value, string $empty = '-'): string {
    $value = trim((string)$value);

    if ($value === '' || $value === '0000-00-00') {
        return $empty;
    }

    return $value;
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


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];


$stmt = $conn->prepare("SELECT is_admin, username FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || (int)$user['is_admin'] !== 1) {
    http_response_code(403);
    die("❌ الصفحة دي للإدارة فقط");
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die("❌ رقم العقد غير صحيح");
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    header("Content-Type: application/json; charset=UTF-8");

    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if (!$isAjax) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "forbidden"
        ]);
        exit();
    }

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "طلب غير صالح"
        ]);
        exit();
    }

    $cid = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($cid <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "بيانات غير صحيحة"
        ]);
        exit();
    }

    if ($action === 'approve') {
        $stmt = $conn->prepare("
            UPDATE contracts 
            SET status = 'approved', approved_at = NOW()
            WHERE id = ? AND status = 'review'
            LIMIT 1
        ");
    } else {
        $stmt = $conn->prepare("
            UPDATE contracts 
            SET status = 'rejected', rejected_at = NOW()
            WHERE id = ? AND status = 'review'
            LIMIT 1
        ");
    }

    $stmt->bind_param("i", $cid);
    $stmt->execute();

    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        echo json_encode([
            "success" => false,
            "message" => "العقد اتراجع قبل كده أو غير موجود"
        ]);
        exit();
    }

    echo json_encode([
        "success" => true,
        "message" => $action === 'approve' ? "تمت الموافقة على العقد" : "تم رفض العقد"
    ]);
    exit();
}


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
    die("❌ العقد غير موجود");
}


$stmt2 = $conn->prepare("SELECT percent, target FROM annual_discounts WHERE contract_id = ? ORDER BY id ASC");
$stmt2->bind_param("i", $id);
$stmt2->execute();
$annual = $stmt2->get_result();

$annualRows = [];
while ($row = $annual->fetch_assoc()) {
    $annualRows[] = $row;
}
$stmt2->close();

$stmt3 = $conn->prepare("SELECT value, name, note, type FROM events WHERE contract_id = ? ORDER BY id ASC");
$stmt3->bind_param("i", $id);
$stmt3->execute();
$events = $stmt3->get_result();

$eventRows = [];
while ($row = $events->fetch_assoc()) {
    $eventRows[] = $row;
}
$stmt3->close();

$stmt4 = $conn->prepare("SELECT * FROM rents WHERE contract_id = ? ORDER BY branch ASC, start_date ASC");
$stmt4->bind_param("i", $id);
$stmt4->execute();
$rents = $stmt4->get_result();

$rentRows = [];
$rentTotal = 0;

while ($row = $rents->fetch_assoc()) {
    $rentRows[] = $row;
    $rentTotal += (float)($row['total'] ?? 0);
}
$stmt4->close();

$isReview = (($data['status'] ?? '') === 'review');
$contractType = (($data['source'] ?? '') === 'rent') ? 'عقد إيجار' : 'عقد سنوي';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>عرض العقد</title>

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

.page-head{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:24px;
    padding:22px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    margin-bottom:20px;
}

.title-line{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
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

.badges{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.status-badge,
.type-badge{
    min-height:36px;
    padding:7px 13px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:13px;
    font-weight:900;
}

.type-badge{
    background:#f0edff;
    color:#4f46e5;
}

.status-badge.review{
    background:#fffbeb;
    color:#b45309;
}

.status-badge.approved{
    background:#ecfdf3;
    color:#166534;
}

.status-badge.rejected{
    background:#fff1f2;
    color:#b42318;
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

.info-grid{
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:12px;
}

.info-item{
    min-height:70px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:12px;
}

.info-label{
    color:#667085;
    font-size:12px;
    font-weight:900;
    margin-bottom:7px;
}

.info-value{
    color:#172033;
    font-size:14px;
    font-weight:900;
    line-height:1.7;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.note-box{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:14px;
    font-weight:800;
    line-height:1.9;
    color:#172033;
    white-space:pre-wrap;
}


.discount-grid{
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:12px;
}

.discount-card{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:14px;
}

.discount-title{
    color:#667085;
    font-weight:900;
    font-size:13px;
    margin-bottom:10px;
}

.discount-value{
    color:#4f46e5;
    font-size:22px;
    font-weight:900;
    margin-bottom:8px;
}

.discount-note{
    color:#172033;
    font-size:13px;
    font-weight:800;
    line-height:1.7;
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

.empty{
    padding:18px !important;
    color:#667085;
    font-weight:900;
}

.event-list{
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(230px, 1fr));
    gap:12px;
}

.event-card{
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:13px;
}

.event-name{
    font-weight:900;
    color:#172033;
    line-height:1.7;
}

.event-value{
    margin-top:8px;
    color:#4f46e5;
    font-size:18px;
    font-weight:900;
}

.event-note{
    margin-top:6px;
    color:#667085;
    font-size:12px;
    font-weight:800;
}


.actions-bar{
    position:sticky;
    bottom:14px;
    background:rgba(238,241,247,.86);
    backdrop-filter:blur(12px);
    border:1px solid rgba(226,232,240,.95);
    border-radius:22px;
    padding:12px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    display:flex;
    justify-content:center;
    gap:12px;
    flex-wrap:wrap;
    z-index:10;
}

.btn{
    min-height:44px;
    padding:0 22px;
    border:none;
    border-radius:14px;
    cursor:pointer;
    font-size:14px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    text-decoration:none;
    color:#fff;
    transition:.18s ease;
    white-space:nowrap;
}

.btn:hover{
    transform:translateY(-1px);
    filter:brightness(.97);
}

.btn:disabled{
    opacity:.55;
    cursor:not-allowed;
    transform:none;
}

.btn-back{
    background:#64748b;
}

.btn-view{
    background:#6d4aff;
}

.btn-approve{
    background:#16a34a;
}

.btn-reject{
    background:#ef4444;
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
    .info-grid,
    .discount-grid{
        grid-template-columns:1fr;
    }

    .table-wrap{
        overflow-x:auto;
    }

    .table{
        min-width:900px;
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

    .actions-bar{
        position:static;
    }

    .btn{
        width:100%;
    }
}


.actions-bar{
    justify-content:space-between !important;
    align-items:center !important;
    gap:12px !important;
}

.actions-bar::before{
    content:"قرار الإدارة";
    color:#667085;
    font-weight:900;
    font-size:13px;
    margin-inline-end:auto;
}

.btn{
    min-width:150px !important;
    height:48px !important;
    padding:0 20px !important;
    border-radius:16px !important;
    font-size:14px !important;
    letter-spacing:0 !important;
    box-shadow:0 12px 22px rgba(23,32,51,.10) !important;
}

.btn-approve{
    background:linear-gradient(145deg,#10b981,#059669) !important;
    color:#fff !important;
    border:1px solid rgba(255,255,255,.22) !important;
}

.btn-reject{
    background:linear-gradient(145deg,#64748b,#475569) !important;
    color:#fff !important;
    border:1px solid rgba(255,255,255,.18) !important;
}

.btn-view{
    background:linear-gradient(145deg,#7c5cff,#4f46e5) !important;
}

.btn-back{
    background:#eef1f7 !important;
    color:#475569 !important;
    border:1px solid #dfe6f0 !important;
    box-shadow:
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff !important;
}

.btn-approve:hover,
.btn-reject:hover,
.btn-view:hover{
    transform:translateY(-2px) !important;
    filter:none !important;
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

.highlight-type{
    background:linear-gradient(145deg,#f0edff,#ffffff) !important;
    border-color:rgba(109,74,255,.18) !important;
}

.contract-type-text{
    color:#4f46e5 !important;
    font-size:17px !important;
}

.type-badge{
    background:linear-gradient(145deg,#7c5cff,#4f46e5) !important;
    color:#fff !important;
    box-shadow:0 10px 18px rgba(109,74,255,.18);
}

@media(max-width:760px){
    .actions-bar{
        justify-content:center !important;
    }

    .actions-bar::before{
        width:100%;
        text-align:center;
        margin:0 0 4px;
    }

    .btn{
        width:100% !important;
        min-width:0 !important;
    }
}

</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <div class="title-line">
            <h1 class="page-title">📄 عرض <?= e($contractType) ?> #<?= (int)$id ?></h1>

            <div class="badges">
                <span class="type-badge"><?= e($contractType) ?></span>
                <span class="status-badge <?= e($data['status'] ?? 'review') ?>">
                    <?= e($data['status'] ?? '-') ?>
                </span>
            </div>
        </div>

        <p class="page-subtitle">
            مراجعة بيانات العقد قبل اتخاذ قرار الموافقة أو الرفض.
        </p>
    </div>

    <div class="section">
        <div class="section-title">البيانات الأساسية</div>

        <div class="info-grid">
            <div class="info-item highlight-type">
                <div class="info-label">نوع العقد</div>
                <div class="info-value contract-type-text"><?= e($contractType) ?></div>
            </div>

            <div class="info-item">
                <div class="info-label">اسم المورد</div>
                <div class="info-value"><?= e($data['supplier_name'] ?? '-') ?></div>
            </div>

            <div class="info-item">
                <div class="info-label">اسم المسؤول</div>
                <div class="info-value"><?= e($data['company_name'] ?? '-') ?></div>
            </div>

            <div class="info-item">
                <div class="info-label">رقم الجوال</div>
                <div class="info-value"><?= e($data['supplier_phone'] ?? '-') ?></div>
            </div>

            <div class="info-item">
                <div class="info-label">حالة المورد</div>
                <div class="info-value"><?= e($data['supplier_status'] ?? '-') ?></div>
            </div>

            <div class="info-item">
                <div class="info-label">بواسطة</div>
                <div class="info-value"><?= e($data['username'] ?? 'غير معروف') ?></div>
            </div>

            <div class="info-item">
                <div class="info-label">فترة السداد</div>
                <div class="info-value">
                    <?= !empty($data['payment_period']) ? e($data['payment_period']) . ' يوم' : '-' ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">تاريخ البداية</div>
                <div class="info-value"><?= e(cleanValue($data['start_date'] ?? '')) ?></div>
            </div>

            <div class="info-item">
                <div class="info-label">تاريخ النهاية</div>
                <div class="info-value"><?= e(cleanValue($data['end_date'] ?? '')) ?></div>
            </div>

            <div class="info-item">
                <div class="info-label">تاريخ الإنشاء</div>
                <div class="info-value"><?= e(cleanValue($data['created_at'] ?? '')) ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">الخصومات</div>

        <div class="discount-grid">
            <div class="discount-card">
                <div class="discount-title">خصم الفاتورة</div>
                <div class="discount-value"><?= e($data['discount_invoice'] ?? 0) ?>%</div>
                <div class="discount-note"><?= e($data['discount_invoice_note'] ?: '-') ?></div>
            </div>

            <div class="discount-card">
                <div class="discount-title">خصم السداد</div>
                <div class="discount-value"><?= e($data['discount_payment'] ?? 0) ?>%</div>
                <div class="discount-note"><?= e($data['discount_payment_note'] ?: '-') ?></div>
            </div>

            <div class="discount-card">
                <div class="discount-title">خصم كل 3 شهور</div>
                <div class="discount-value"><?= e($data['discount_quarter'] ?? 0) ?>%</div>
                <div class="discount-note"><?= e($data['discount_quarter_note'] ?: '-') ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">الخصم السنوي</div>

        <?php if(empty($annualRows)): ?>
            <div class="note-box">لا يوجد خصم سنوي</div>
        <?php else: ?>
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
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-title">رسوم الفعاليات</div>

        <?php if(empty($eventRows)): ?>
            <div class="note-box">لا توجد فعاليات</div>
        <?php else: ?>
            <div class="event-list">
                <?php foreach($eventRows as $row): ?>
                    <div class="event-card">
                        <div class="event-name"><?= e($row['name'] ?? '-') ?></div>
                        <div class="event-value"><?= money($row['value'] ?? 0) ?> ريال</div>

                        <?php if(!empty($row['note'])): ?>
                            <div class="event-note"><?= e($row['note']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-title">الإيجارات</div>

        <?php if(empty($rentRows)): ?>
            <div class="note-box">لا توجد إيجارات</div>
        <?php else: ?>
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
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-title">ملاحظات</div>
        <div class="note-box"><?= e($data['notes'] ?: 'لا يوجد') ?></div>
    </div>

    <div class="actions-bar">
        <a href="admin_review.php" class="btn btn-back">رجوع للمراجعة</a>
        <a href="view_contract.php?id=<?= (int)$id ?>" class="btn btn-view">عرض صفحة العقد</a>

        <?php if($isReview): ?>
            <button class="btn btn-approve action-btn" onclick="updateStatus(<?= (int)$id ?>, 'approve', this)">
                موافقة العقد
            </button>

            <button class="btn btn-reject action-btn" onclick="updateStatus(<?= (int)$id ?>, 'reject', this)">
                رفض العقد
            </button>
        <?php endif; ?>
    </div>

</div>

<div id="toast" class="toast"></div>

<script>
const csrfToken = "<?= e($csrf_token) ?>";

function showToast(message, type){
    const toast = document.getElementById("toast");

    toast.innerText = message;
    toast.className = "toast " + (type || "");
    toast.style.display = "block";

    setTimeout(function(){
        toast.style.display = "none";
    }, 2200);
}

function updateStatus(id, action, btn){

    const confirmText = action === "approve"
        ? "تأكيد الموافقة على العقد؟"
        : "تأكيد رفض العقد؟";

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

    fetch(window.location.href, {
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
                window.location.href = "admin_review.php";
            }, 900);

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
</script>

</body>
</html>
