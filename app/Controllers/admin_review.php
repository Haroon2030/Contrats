<?php
require_once VC_HELPERS . '/auth.php';


date_default_timezone_set('Asia/Riyadh');

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_review_contract') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح");
    }

    $contract_id = (int)($_POST['contract_id'] ?? 0);

    if ($contract_id > 0) {
        $stmtDelete = $conn->prepare("
            UPDATE contracts
            SET status = 'deleted'
            WHERE id = ? AND status = 'review'
            LIMIT 1
        ");
        if ($stmtDelete) {
            $stmtDelete->bind_param("i", $contract_id);
            $stmtDelete->execute();
            $stmtDelete->close();
        }
    }

    header("Location: admin_review.php");
    exit();
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

    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
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

    $stmt->bind_param("i", $id);
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

    
    if ($action === 'approve' && function_exists('vcNhNotifyAccountants')) {
        try {
            $stmtFinanceContract = $conn->prepare("SELECT supplier_name, source FROM contracts WHERE id = ? LIMIT 1");
            if ($stmtFinanceContract) {
                $stmtFinanceContract->bind_param("i", $id);
                $stmtFinanceContract->execute();
                $financeContract = $stmtFinanceContract->get_result()->fetch_assoc();
                $stmtFinanceContract->close();

                if (!empty($financeContract)) {
                    $supplierName = (string)($financeContract['supplier_name'] ?? '');
                    $contractSource = (string)($financeContract['source'] ?? '');
                    $isRentContract = ($contractSource === 'rent');
                    $financeTitle = $isRentContract
                        ? 'عقد إيجار معتمد جاهز للمتابعة المالية'
                        : 'عقد معتمد جاهز للمتابعة المالية';
                    $financeType = $isRentContract ? 'rent_approved_finance' : 'contract_approved_finance';
                    $financeMessage = 'تم اعتماد ' . ($isRentContract ? 'عقد إيجار' : 'عقد') .
                        ' رقم #' . (int)$id .
                        ' للمورد: ' . $supplierName .
                        ' — الإجراء المطلوب: مراجعة البنود المالية.';

                    vcNhNotifyAccountants(
                        $conn,
                        $financeTitle,
                        $financeMessage,
                        'accounting.php',
                        $financeType,
                        (int)$id,
                        [(int)$uid]
                    );
                }
            }
        } catch (Throwable $e) {
            error_log('admin_review accountant notify error: ' . $e->getMessage());
        }
    }

    echo json_encode([
        "success" => true,
        "message" => $action === 'approve' ? "تمت الموافقة" : "تم الرفض"
    ]);
    exit();
}


$fromWhere = "
    FROM contracts
    LEFT JOIN users ON users.id = contracts.created_by
    WHERE contracts.status = 'review'
";

$pg = vcPaginationState();
$totalReview = vcPaginationCount($conn, $fromWhere);
$totalPages = vcPaginationTotalPages($totalReview, $pg['per_page']);
$page = min($pg['page'], $totalPages);

$sql = "
    SELECT 
        contracts.id,
        contracts.supplier_name,
        contracts.company_name,
        contracts.source,
        contracts.created_at,
        users.username
    {$fromWhere}
    ORDER BY contracts.id DESC
    LIMIT ? OFFSET ?
";

[$dataParams, $dataTypes] = vcPaginationBindLimit([], '', $pg['limit'], ($page - 1) * $pg['per_page']);

$stmt = $conn->prepare($sql);
$stmt->bind_param($dataTypes, ...$dataParams);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>مراجعة العقود</title>

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


.summary-grid{
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    gap:14px;
    margin-bottom:18px;
}

.summary-card{
    background:rgba(255,255,255,.68);
    border:1px solid rgba(226,232,240,.95);
    border-radius:20px;
    padding:16px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}

.summary-label{
    font-size:13px;
    color:#667085;
    font-weight:800;
    margin-bottom:7px;
}

.summary-value{
    font-size:24px;
    font-weight:900;
    color:#4f46e5;
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
    padding:14px 10px;
    text-align:center;
    font-size:13px;
    line-height:1.45;
    font-weight:900;
    white-space:nowrap;
}

.table th:first-child{
    border-radius:0 14px 14px 0;
}

.table th:last-child{
    border-radius:14px 0 0 14px;
}

.table td{
    padding:13px 10px;
    border-bottom:1px solid #dfe6f0;
    text-align:center;
    vertical-align:middle;
    font-size:14px;
    line-height:1.7;
    color:#172033;
}

.table tr:last-child td{
    border-bottom:none;
}

.table tr:hover td{
    background:#f6f4ff;
}

.col-id{
    width:90px;
}

.col-supplier{
    width:32%;
}

.col-manager{
    width:20%;
}

.col-user{
    width:150px;
}

.col-date{
    width:130px;
}

.col-actions{
    width:220px;
}

.contract-id{
    font-weight:900;
    color:#4f46e5;
}

.supplier-name,
.manager-name{
    font-weight:800;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.user-badge{
    background:#f0edff;
    color:#4f46e5;
    min-width:96px;
    min-height:34px;
    padding:6px 11px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:900;
}

.type-badge{
    background:#eef1f7;
    color:#172033;
    min-width:86px;
    min-height:32px;
    padding:5px 10px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:900;
    box-shadow:
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
}


.actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    flex-wrap:nowrap;
}

.btn{
    min-height:36px;
    padding:0 13px;
    border:none;
    border-radius:12px;
    cursor:pointer;
    font-size:12px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    text-decoration:none;
    color:#fff;
    transition:.18s ease;
    white-space:nowrap;
}

.btn:hover{
    transform:translateY(-1px);
    filter:brightness(.97);
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

.btn:disabled{
    opacity:.55;
    cursor:not-allowed;
    transform:none;
}


.empty{
    text-align:center;
    padding:26px !important;
    color:#667085;
    font-weight:900;
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
    .summary-grid{
        grid-template-columns:1fr;
    }

    .table-box{
        overflow-x:auto;
    }

    .table{
        min-width:920px;
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
}
</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">🛡️ مراجعة العقود</h1>
        <p class="page-subtitle">
            العقود المرسلة للإدارة للموافقة أو الرفض.
        </p>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">بانتظار المراجعة</div>
            <div class="summary-value" id="reviewCount"><?= (int)$totalReview ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">مسؤول الصفحة</div>
            <div class="summary-value" style="font-size:18px;"><?= e($user['username'] ?? 'Admin') ?></div>
        </div>

        <div class="summary-card">
            <div class="summary-label">الحالة</div>
            <div class="summary-value" style="font-size:18px;">إدارة فقط</div>
        </div>
    </div>

    <div class="table-box">

        <table class="table">
            <thead>
                <tr>
                    <th class="col-id">رقم العقد</th>
                    <th class="col-supplier">اسم المورد</th>
                    <th class="col-manager">المسؤول</th>
                    <th class="col-user">بواسطة</th>
                    <th class="col-date">تاريخ الإرسال</th>
                    <th class="col-actions">إجراءات</th>
                </tr>
            </thead>

            <tbody id="reviewBody">

                <?php if(!empty($rows)): ?>

                    <?php foreach($rows as $row): ?>
                        <?php
                            $contractType = (($row['source'] ?? '') === 'rent') ? 'عقد إيجار' : 'عقد سنوي';
                            $created = !empty($row['created_at']) ? date("Y-m-d", strtotime($row['created_at'])) : '-';
                        ?>

                        <tr data-id="<?= (int)$row['id'] ?>">

                            <td>
                                <span class="contract-id">#<?= (int)$row['id'] ?></span>
                                <br>
                                <span class="type-badge"><?= e($contractType) ?></span>
                            </td>

                            <td class="supplier-name">
                                <?= e($row['supplier_name'] ?? '-') ?>
                            </td>

                            <td class="manager-name">
                                <?= e($row['company_name'] ?? '-') ?>
                            </td>

                            <td>
                                <span class="user-badge">
                                    <?= e($row['username'] ?? 'غير معروف') ?>
                                </span>
                            </td>

                            <td>
                                <?= e($created) ?>
                            </td>

                            <td>
                                <?php
                                $approveRejectExtra = '
                                    <button type="button"
                                            class="btn btn-approve"
                                            onclick="updateStatus(' . (int)$row['id'] . ', \'approve\', this)">
                                        موافقة
                                    </button>
                                    <button type="button"
                                            class="btn btn-reject"
                                            onclick="updateStatus(' . (int)$row['id'] . ', \'reject\', this)">
                                        رفض
                                    </button>
                                ';
                                vcRenderRowActions([
                                    'view' => [
                                        'href' => 'view_contract.php?id=' . (int)$row['id'],
                                        'label' => 'معاينة',
                                    ],
                                    'edit' => [
                                        'href' => vcContractEditUrl($row),
                                    ],
                                    'delete' => [
                                        'action' => 'delete_review_contract',
                                        'fields' => ['contract_id' => (string)(int)$row['id']],
                                        'confirm' => 'تأكيد حذف العقد رقم #' . (int)$row['id'] . '؟',
                                    ],
                                    'extra' => $approveRejectExtra,
                                ], $csrf_token, true);
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr id="emptyRow">
                        <td colspan="6" class="empty">لا يوجد عقود للمراجعة</td>
                    </tr>

                <?php endif; ?>

            </tbody>
        </table>

    </div>

    <?php vcRenderPagination($page, $totalPages); ?>

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

function updateReviewCount(){
    const rows = document.querySelectorAll("#reviewBody tr[data-id]");
    const countBox = document.getElementById("reviewCount");

    if(countBox){
        countBox.innerText = rows.length;
    }

    if(rows.length === 0 && !document.getElementById("emptyRow")){
        const tbody = document.getElementById("reviewBody");
        const tr = document.createElement("tr");
        tr.id = "emptyRow";
        tr.innerHTML = "<td colspan='6' class='empty'>لا يوجد عقود للمراجعة</td>";
        tbody.appendChild(tr);
    }
}

function updateStatus(id, action, btn){

    if(!confirm(action === "approve" ? "تأكيد الموافقة على العقد؟" : "تأكيد رفض العقد؟")){
        return;
    }

    const row = btn.closest("tr");
    const buttons = row.querySelectorAll("button");

    buttons.forEach(function(b){
        b.disabled = true;
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
            row.style.transition = ".22s";
            row.style.opacity = "0";
            row.style.transform = "translateY(-6px)";

            setTimeout(function(){
                row.remove();
                updateReviewCount();
            }, 230);

            showToast(data.message || "تم التحديث", "success");
        }else{
            buttons.forEach(function(b){
                b.disabled = false;
            });

            showToast(data.message || "لم يتم التحديث", "error");
        }

    })
    .catch(function(){
        buttons.forEach(function(b){
            b.disabled = false;
        });

        showToast("تعذر الاتصال بالسيرفر", "error");
    });
}
</script>

</body>
</html>
