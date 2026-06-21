<?php require_once VC_HELPERS . '/auth.php'; 
?>

<?php


mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Riyadh');

function vcNotifyColumnExists(mysqli $conn, string $table, string $column): bool {
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

function vcDisabledHookSetup(mysqli $conn): void {
    return;
}

function vcDisabledUserHook(mysqli $conn, int $userId, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0): void {
    return;
}

function vcDisabledAdminsHook(mysqli $conn, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0, int $excludeUserId = 0): void {
    return;
}



function vcDisabledManagerHook(mysqli $conn, int $createdByUserId, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0, int $excludeUserId = 0): void {
    return;
}



function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ensureMinRows(array $arr, int $min = 2): array {
    $arr = array_values($arr);
    while (count($arr) < $min) {
        $arr[] = '';
    }
    return $arr;
}

function defaultForm(): array {
    return [
        'supplier_name'            => '',
        'company_name'             => '',
        'supplier_phone'           => '',
        'supplier_status'          => 'registered',
        'status'                   => '',
        'start_date'               => '',
        'end_date'                 => '',

        'discount_invoice'         => '',
        'discount_invoice_note'    => '',
        'discount_payment'         => '',
        'discount_payment_note'    => '',
        'discount_quarter'         => '',
        'discount_quarter_note'    => '',

        'annual_discount_percents' => ['', ''],
        'annual_discount_targets'  => ['', ''],

        'event_values'             => ['', ''],
        'event_names'              => ['', ''],

        'notes'                    => '',
    ];
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$form = defaultForm();
$rents = [];
$success = '';
$errors = [];
$contract_id = null;
$is_admin = 0;


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($userData && (int)$userData['is_admin'] === 1) {
    $is_admin = 1;
}


if (isset($_GET['id'])) {

    $id = (int)$_GET['id'];

    if ($is_admin) {
        $stmt = $conn->prepare("SELECT * FROM contracts WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM contracts WHERE id=? AND created_by=? LIMIT 1");
        $stmt->bind_param("ii", $id, $user_id);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows) {
        $data = $res->fetch_assoc();

        foreach ($form as $key => $val) {
            if (isset($data[$key])) {
                $form[$key] = $data[$key];
            }
        }

        if (($form['status'] ?? '') === 'draft') {
            $form['status'] = 'تفاوض';
        } elseif (in_array(($form['status'] ?? ''), ['review', 'approved'], true)) {
            $form['status'] = 'نهائي';
        }

        $stmt2 = $conn->prepare("SELECT percent, target FROM annual_discounts WHERE contract_id=?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();

        $form['annual_discount_percents'] = [];
        $form['annual_discount_targets'] = [];

        while ($row = $res2->fetch_assoc()) {
            $form['annual_discount_percents'][] = $row['percent'];
            $form['annual_discount_targets'][]  = $row['target'];
        }

        $form['annual_discount_percents'] = ensureMinRows($form['annual_discount_percents']);
        $form['annual_discount_targets']  = ensureMinRows($form['annual_discount_targets']);
        $stmt2->close();

        $stmt3 = $conn->prepare("SELECT value, name FROM events WHERE contract_id=?");
        $stmt3->bind_param("i", $id);
        $stmt3->execute();
        $res3 = $stmt3->get_result();

        $form['event_values'] = [];
        $form['event_names']  = [];

        while ($row = $res3->fetch_assoc()) {
            $form['event_values'][] = $row['value'];
            $form['event_names'][]  = $row['name'];
        }

        $form['event_values'] = ensureMinRows($form['event_values']);
        $form['event_names']  = ensureMinRows($form['event_names']);
        $stmt3->close();

        $stmt4 = $conn->prepare("SELECT * FROM rents WHERE contract_id=?");
        $stmt4->bind_param("i", $id);
        $stmt4->execute();
        $res4 = $stmt4->get_result();

        while ($row = $res4->fetch_assoc()) {
            $rents[] = $row;
        }
        $stmt4->close();

    } else {
        die("❌ غير مصرح لك بالوصول لهذا العقد");
    }

    $stmt->close();
}


if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $errors[] = "الطلب مش صالح، جرّب تاني.";
    }

    $form['supplier_name']          = trim($_POST['supplier_name'] ?? '');
    $form['company_name']           = trim($_POST['company_name'] ?? '');
    $form['supplier_phone']         = trim($_POST['supplier_phone'] ?? '');
    $form['supplier_status']        = trim($_POST['supplier_status'] ?? 'registered');
    $form['status']                 = trim($_POST['status'] ?? '');
    $form['start_date']             = trim($_POST['start_date'] ?? '');
    $form['end_date']               = trim($_POST['end_date'] ?? '');

    $form['discount_invoice']       = trim($_POST['discount_invoice'] ?? '');
    $form['discount_invoice_note']  = trim($_POST['discount_invoice_note'] ?? '');

    $form['discount_payment']       = trim($_POST['discount_payment'] ?? '');
    $form['discount_payment_note']  = trim($_POST['discount_payment_note'] ?? '');

    $form['discount_quarter']       = trim($_POST['discount_quarter'] ?? '');
    $form['discount_quarter_note']  = trim($_POST['discount_quarter_note'] ?? '');

    $form['annual_discount_percents'] = ensureMinRows($_POST['annual_discount_percent'] ?? []);
    $form['annual_discount_targets']  = ensureMinRows($_POST['annual_discount_target'] ?? []);

    $form['event_values']           = ensureMinRows($_POST['event_value'] ?? []);
    $form['event_names']            = ensureMinRows($_POST['event_name'] ?? []);

    $form['notes']                  = trim($_POST['notes'] ?? '');

    if ($form['supplier_name'] === '') {
        $errors[] = "اسم المورد مطلوب.";
    }

    if ($form['company_name'] === '') {
        $errors[] = "اسم المسؤول مطلوب.";
    }

    if ($form['supplier_phone'] !== '' && !preg_match('/^5\d{8}$/', $form['supplier_phone'])) {
        $errors[] = "رقم الجوال لازم يبدأ بـ 5 ويتكون من 9 أرقام.";
    }

    if (!in_array($form['supplier_status'], ['new', 'registered'], true)) {
        $errors[] = "اختار حالة المورد.";
    }

    if (!in_array($form['status'], ['تفاوض', 'نهائي'], true)) {
        $errors[] = "اختار حالة العقد.";
    }

    foreach ($form['annual_discount_percents'] as $i => $percent) {
        $percent = trim((string)$percent);
        $target  = trim((string)($form['annual_discount_targets'][$i] ?? ''));

        if ($percent !== '' && !is_numeric($percent)) {
            $errors[] = "فيه نسبة غير صحيحة في خصم سنوي.";
            break;
        }

        if ($percent !== '' && ((float)$percent < 0 || (float)$percent > 100)) {
            $errors[] = "نسبة الخصم السنوي لازم تكون بين 0 و 100.";
            break;
        }

        if ($percent !== '' && $target === '') {
            $errors[] = "اكتب الهدف المقابل لكل نسبة في الخصم السنوي.";
            break;
        }
    }

    foreach ($form['event_values'] as $i => $value) {
        $value = trim((string)$value);
        $name  = trim((string)($form['event_names'][$i] ?? ''));

        if ($value !== '' && !is_numeric($value)) {
            $errors[] = "فيه قيمة غير صحيحة في رسوم الفعاليات.";
            break;
        }

        if ($value !== '' && (float)$value < 0) {
            $errors[] = "قيمة رسوم الفعاليات لازم تكون رقم موجب.";
            break;
        }

        if ($value !== '' && $name === '') {
            $errors[] = "اكتب اسم المهرجان أو الفعالية لكل قيمة.";
            break;
        }
    }

    if (empty($errors)) {
        try {

            $created_by = $user_id;

            if ($form['status'] === 'تفاوض') {
                $form['status'] = 'draft';
            } elseif ($form['status'] === 'نهائي') {
                $form['status'] = $is_admin ? 'approved' : 'review';
            }

            if (isset($_GET['id'])) {

                $id = (int)$_GET['id'];

                if ($is_admin) {
                    $stmt = $conn->prepare("
                        UPDATE contracts SET
                            supplier_name=?,
                            company_name=?,
                            supplier_phone=?,
                            supplier_status=?,
                            status=?,
                            start_date=?,
                            end_date=?,
                            discount_invoice=?,
                            discount_payment=?,
                            discount_quarter=?,
                            discount_invoice_note=?,
                            discount_payment_note=?,
                            discount_quarter_note=?,
                            notes=?
                        WHERE id=?
                    ");

                    $stmt->bind_param(
                        "sssssssdddssssi",
                        $form['supplier_name'],
                        $form['company_name'],
                        $form['supplier_phone'],
                        $form['supplier_status'],
                        $form['status'],
                        $form['start_date'],
                        $form['end_date'],
                        $form['discount_invoice'],
                        $form['discount_payment'],
                        $form['discount_quarter'],
                        $form['discount_invoice_note'],
                        $form['discount_payment_note'],
                        $form['discount_quarter_note'],
                        $form['notes'],
                        $id
                    );
                } else {
                    $stmt = $conn->prepare("
                        UPDATE contracts SET
                            supplier_name=?,
                            company_name=?,
                            supplier_phone=?,
                            supplier_status=?,
                            status=?,
                            start_date=?,
                            end_date=?,
                            discount_invoice=?,
                            discount_payment=?,
                            discount_quarter=?,
                            discount_invoice_note=?,
                            discount_payment_note=?,
                            discount_quarter_note=?,
                            notes=?
                        WHERE id=? AND created_by=?
                    ");

                    $stmt->bind_param(
                        "sssssssdddssssii",
                        $form['supplier_name'],
                        $form['company_name'],
                        $form['supplier_phone'],
                        $form['supplier_status'],
                        $form['status'],
                        $form['start_date'],
                        $form['end_date'],
                        $form['discount_invoice'],
                        $form['discount_payment'],
                        $form['discount_quarter'],
                        $form['discount_invoice_note'],
                        $form['discount_payment_note'],
                        $form['discount_quarter_note'],
                        $form['notes'],
                        $id,
                        $user_id
                    );
                }

                $stmt->execute();
                $stmt->close();

                $contract_id = $id;

                $stmtDel = $conn->prepare("DELETE FROM rents WHERE contract_id=?");
                $stmtDel->bind_param("i", $contract_id);
                $stmtDel->execute();
                $stmtDel->close();

                $stmtDel = $conn->prepare("DELETE FROM annual_discounts WHERE contract_id=?");
                $stmtDel->bind_param("i", $contract_id);
                $stmtDel->execute();
                $stmtDel->close();

                $stmtDel = $conn->prepare("DELETE FROM events WHERE contract_id=?");
                $stmtDel->bind_param("i", $contract_id);
                $stmtDel->execute();
                $stmtDel->close();

            } else {

                $source = 'rent';

                $stmt = $conn->prepare("
                    INSERT INTO contracts (
                        supplier_name,
                        company_name,
                        supplier_phone,
                        supplier_status,
                        status,
                        start_date,
                        end_date,
                        discount_invoice,
                        discount_payment,
                        discount_quarter,
                        discount_invoice_note,
                        discount_payment_note,
                        discount_quarter_note,
                        notes,
                        created_by,
                        source
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->bind_param(
                    "sssssssdddssssss",
                    $form['supplier_name'],
                    $form['company_name'],
                    $form['supplier_phone'],
                    $form['supplier_status'],
                    $form['status'],
                    $form['start_date'],
                    $form['end_date'],
                    $form['discount_invoice'],
                    $form['discount_payment'],
                    $form['discount_quarter'],
                    $form['discount_invoice_note'],
                    $form['discount_payment_note'],
                    $form['discount_quarter_note'],
                    $form['notes'],
                    $created_by,
                    $source
                );

                $stmt->execute();
                $contract_id = $conn->insert_id;
                $stmt->close();
            }

            
            $branches = $_POST['rent_branch'] ?? [];
            $types    = $_POST['rent_type'] ?? [];
            $qtys     = $_POST['rent_qty'] ?? [];
            $prices   = $_POST['rent_price'] ?? [];
            $froms    = $_POST['rent_from'] ?? [];
            $tos      = $_POST['rent_to'] ?? [];
            $totals   = $_POST['rent_total'] ?? [];

            $rows = count($branches);

            for ($i = 0; $i < $rows; $i++) {

                $branch = trim((string)($branches[$i] ?? ''));
                $type   = trim((string)($types[$i] ?? ''));
                $qty    = (float)($qtys[$i] ?? 0);
                $price  = (float)($prices[$i] ?? 0);
                $from   = trim((string)($froms[$i] ?? ''));
                $to     = trim((string)($tos[$i] ?? ''));
                $total  = (float)($totals[$i] ?? 0);

                if ($branch === '' && $type === '' && $qty == 0 && $price == 0) {
                    continue;
                }

                $stmtRent = $conn->prepare("
                    INSERT INTO rents
                    (contract_id, branch, type, qty, price, start_date, end_date, total)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmtRent->bind_param(
                    "issidssd",
                    $contract_id,
                    $branch,
                    $type,
                    $qty,
                    $price,
                    $from,
                    $to,
                    $total
                );

                $stmtRent->execute();
                $stmtRent->close();
            }

            
            foreach ($form['annual_discount_percents'] as $i => $percent) {
                $target = $form['annual_discount_targets'][$i] ?? '';

                if ($percent !== '') {
                    $stmt2 = $conn->prepare("
                        INSERT INTO annual_discounts (contract_id, percent, target)
                        VALUES (?, ?, ?)
                    ");
                    $stmt2->bind_param("ids", $contract_id, $percent, $target);
                    $stmt2->execute();
                    $stmt2->close();
                }
            }

            
            foreach ($form['event_values'] as $i => $value) {
                $name = $form['event_names'][$i] ?? '';

                if ($value !== '' && $name !== '') {
                    $stmt3 = $conn->prepare("
                        INSERT INTO events (contract_id, value, name)
                        VALUES (?, ?, ?)
                    ");
                    $stmt3->bind_param("ids", $contract_id, $value, $name);
                    $stmt3->execute();
                    $stmt3->close();
                }
            }

            $_SESSION['success_id'] = $contract_id;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            
            if (!$is_admin && ($form['status'] ?? '') === 'review') {
                vcDisabledManagerHook(
                    $conn,
                    (int)$user_id,
                    'تم إرسال عقد إيجار للمراجعة',
                    'تم إرسال عقد إيجار للمراجعة رقم #' . (int)$contract_id . ' للمورد: ' . ($form['supplier_name'] ?? ''),
                    'view_contract.php?id=' . (int)$contract_id,
                    'rent_sent_review',
                    (int)$contract_id,
                    (int)$user_id
                );
            }

            

            header("Location: rents.php?success=1&id=" . $contract_id);
            exit();

        } catch (Throwable $e) {
            die("ERROR: " . $e->getMessage());
        }
    }
}

$status_tafawod_checked = ($form['status'] === 'تفاوض' || $form['status'] === 'draft' || $form['status'] === '') ? 'checked' : '';
$status_final_checked   = ($form['status'] === 'نهائي' || in_array($form['status'], ['review', 'approved'], true)) ? 'checked' : '';

$success_id = $_SESSION['success_id'] ?? null;
if (isset($_GET['success']) && isset($_GET['id'])) {
    $success_id = (int)$_GET['id'];
}
if (!isset($_GET['success'])) {
    unset($_SESSION['success_id']);
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إضافة عقد إيجار</title>

<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

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
    width:min(1180px, calc(100% - 28px));
    margin:28px auto 45px;
}

.page-head{
    text-align:center;
    margin-bottom:24px;
}

.page-title{
    font-size:28px;
    font-weight:900;
    margin:0 0 8px;
    color:#172033;
    letter-spacing:-.3px;
}

.page-subtitle{
    color:#667085;
    margin:0;
    font-size:15px;
    line-height:1.9;
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

.alert-error{
    background:#fff1f2;
    color:#b42318;
    border:1px solid #fecdd3;
}

.section-title{
    background:rgba(255,255,255,.74);
    padding:15px 18px;
    border-radius:18px;
    font-weight:900;
    margin:26px 0 16px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    border:1px solid rgba(226,232,240,.95);
    color:#4f46e5;
    display:flex;
    align-items:center;
    gap:10px;
}

.section-title::before{
    content:"";
    width:9px;
    height:24px;
    border-radius:999px;
    background:linear-gradient(180deg,#7c5cff,#4f46e5);
}

.box{
    background:rgba(255,255,255,.62);
    border-radius:20px;
    padding:20px;
    margin-bottom:16px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    border:1px solid rgba(226,232,240,.95);
}

.box-title{
    font-weight:900;
    margin-bottom:13px;
    color:#172033;
    font-size:15px;
}

.field{
    margin-bottom:16px;
}

label,
.option-title{
    display:block;
    font-size:14px;
    font-weight:800;
    color:#172033;
    margin-bottom:9px;
    line-height:1.5;
}

.hint{
    color:#8a94a6;
    font-size:12px;
    font-weight:700;
    margin-top:7px;
}

input,
textarea,
select{
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
    direction:rtl;
    text-align:right;
}

textarea{
    min-height:116px;
    padding:14px;
    line-height:1.8;
    resize:vertical;
}

input::placeholder,
textarea::placeholder{
    color:#8a94a6;
}

input:focus,
textarea:focus,
select:focus{
    border-color:#6d4aff;
    box-shadow:
        0 0 0 3px rgba(109,74,255,.12),
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
}

input[type="number"]{
    direction:ltr;
    text-align:center;
}

.basic-info-grid{
    display:grid;
    grid-template-columns:minmax(360px, 1.45fr) minmax(230px, 1fr) minmax(250px, 1fr);
    gap:16px;
    align-items:start;
}

.supplier-field{
    min-width:0;
}

#supplier_search_box{
    position:relative;
}

#supplier_name_box,
#supplier_search_box{
    width:100%;
}

.phone{
    display:flex;
    border-radius:14px;
    overflow:hidden;
    box-shadow:
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
}

.phone span{
    background:#6d4aff;
    color:#fff;
    padding:0 15px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
}

.phone input{
    border:none;
    box-shadow:none;
    border-radius:0;
}

.supplier-status{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:14px;
    margin-bottom:16px;
}

.status-card{
    display:block;
    margin:0;
    cursor:pointer;
}

.status-card input{
    display:none;
}

.status-card .card{
    background:rgba(255,255,255,.70);
    border-radius:18px;
    padding:16px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    border:1px solid rgba(226,232,240,.95);
    display:flex;
    align-items:center;
    gap:12px;
    min-height:88px;
    transition:.18s ease;
}

.status-card .icon{
    width:42px;
    height:42px;
    border-radius:14px;
    background:#f0edff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
}

.status-card strong{
    display:block;
    font-weight:900;
    font-size:15px;
    margin-bottom:4px;
}

.status-card small{
    color:#667085;
    font-weight:700;
    line-height:1.6;
}

.status-card input:checked + .card{
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    border-color:rgba(255,255,255,.25);
}

.status-card input:checked + .card small{
    color:rgba(255,255,255,.82);
}

.status-card input:checked + .card .icon{
    background:rgba(255,255,255,.18);
}

.option-buttons{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:12px;
    margin-bottom:16px;
}

.option-buttons input{
    display:none;
}

.option-buttons label{
    margin:0;
    min-height:50px;
    padding:0 16px;
    text-align:center;
    background:rgba(255,255,255,.72);
    border-radius:16px;
    cursor:pointer;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    border:1px solid rgba(226,232,240,.95);
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
    transition:.18s ease;
}

.option-buttons label:hover{
    transform:translateY(-1px);
}

.option-buttons input:checked + label{
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    box-shadow:0 12px 22px rgba(109,74,255,.22);
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
    margin-top:10px;
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
    padding:9px 7px;
    border-bottom:1px solid #dfe6f0;
    vertical-align:middle;
    text-align:center;
}

.table td input,
.table td select{
    min-height:44px;
    font-size:13px;
    font-weight:800;
    padding-right:8px;
    padding-left:8px;
}

.row-actions{
    width:92px;
    text-align:center;
    white-space:nowrap;
}

#rentTable th:nth-child(1),
#rentTable td:nth-child(1){width:15%;}

#rentTable th:nth-child(2),
#rentTable td:nth-child(2){width:14%;}

#rentTable th:nth-child(3),
#rentTable td:nth-child(3){width:9%;}

#rentTable th:nth-child(4),
#rentTable td:nth-child(4){width:14%;}

#rentTable th:nth-child(5),
#rentTable td:nth-child(5),
#rentTable th:nth-child(6),
#rentTable td:nth-child(6){width:13%;}

#rentTable th:nth-child(7),
#rentTable td:nth-child(7){width:12%;}

#rentTable th:nth-child(8),
#rentTable td:nth-child(8){width:10%;}

.currency-field{
    display:flex;
    border-radius:14px;
    overflow:hidden;
    box-shadow:
        inset 2px 2px 6px #d1d9e6,
        inset -2px -2px 6px #ffffff;
}

.currency-field span{
    background:#6d4aff;
    color:#fff;
    padding:0 10px;
    min-width:50px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
    font-size:12px;
}

.currency-field input{
    border:none;
    box-shadow:none;
    border-radius:0;
}

.icon-btn{
    min-height:38px;
    padding:0 14px;
    border:none;
    border-radius:12px;
    cursor:pointer;
    font-weight:900;
    font-size:13px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    text-decoration:none;
    transition:.18s ease;
}

.icon-btn:hover{
    transform:translateY(-1px);
    filter:brightness(.97);
}

.add-btn{
    background:#6d4aff;
    color:#fff;
    margin-top:10px;
}

.remove-btn{
    background:#8f9399;
    color:#fff;
    min-width:70px;
}

.submit{
    width:100%;
    min-height:54px;
    padding:15px;
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    border:none;
    border-radius:16px;
    margin-top:20px;
    font-size:16px;
    font-weight:900;
    cursor:pointer;
    box-shadow:0 14px 26px rgba(109,74,255,.24);
    transition:.18s ease;
}

.submit:hover{
    transform:translateY(-1px);
    filter:brightness(.98);
}

#results{
    position:absolute;
    top:100%;
    right:0;
    left:0;
    z-index:50;
    background:#fff;
    border-radius:14px;
    box-shadow:0 14px 30px rgba(23,32,51,.13);
    overflow:hidden;
    margin-top:6px;
}

#results .item{
    padding:12px;
    cursor:pointer;
    font-weight:800;
}

#results .item:hover{
    background:#f1f4fa;
}

.total-box{
    margin-top:14px;
    background:#f8fafc;
    border:1px solid #dfe6f0;
    padding:14px;
    border-radius:16px;
    font-weight:900;
    color:#172033;
}

.date-field{
    position:relative;
}

.date-field input{
    padding-left:42px;
    cursor:pointer;
}

.date-icon{
    position:absolute;
    left:13px;
    top:50%;
    transform:translateY(-50%);
    font-size:16px;
    opacity:.75;
    pointer-events:none;
}

.flatpickr-calendar{
    direction:rtl !important;
    border:none !important;
    border-radius:20px !important;
    box-shadow:0 18px 40px rgba(23,32,51,.16) !important;
    overflow:hidden !important;
    z-index:99999 !important;
    font-family:'Cairo', Tahoma, Arial, sans-serif !important;
}

.flatpickr-current-month{
    font-size:16px !important;
    font-weight:900 !important;
}

.flatpickr-day{
    border-radius:12px !important;
    font-weight:800 !important;
}

.flatpickr-day.selected,
.flatpickr-day.startRange,
.flatpickr-day.endRange{
    background:#6d4aff !important;
    border-color:#6d4aff !important;
    color:#fff !important;
}

@media(max-width:1000px){
    .basic-info-grid,
    .supplier-status,
    .option-buttons{
        grid-template-columns:1fr;
    }

    .container{
        width:calc(100% - 18px);
        margin-top:18px;
    }

    .box{
        padding:16px;
    }

    .page-title{
        font-size:23px;
    }

    .table-wrap{
        overflow-x:auto;
    }

    .table{
        min-width:900px;
    }
}



.container{
    width:min(1360px, calc(100% - 28px)) !important;
}

.table-wrap{
    overflow:visible !important;
}

#rentTable{
    table-layout:fixed !important;
    width:100% !important;
    min-width:0 !important;
}

#rentTable th{
    font-size:12px !important;
    padding:11px 5px !important;
    line-height:1.25 !important;
}

#rentTable td{
    padding:8px 5px !important;
}

#rentTable input,
#rentTable select{
    min-height:42px !important;
    height:42px !important;
    font-size:12px !important;
    font-weight:800 !important;
    padding-right:7px !important;
    padding-left:7px !important;
    border-radius:12px !important;
}

#rentTable .currency-field span{
    min-width:42px !important;
    padding:0 7px !important;
    font-size:12px !important;
}


#rentTable th:nth-child(1),
#rentTable td:nth-child(1){width:14% !important;}

#rentTable th:nth-child(2),
#rentTable td:nth-child(2){width:13% !important;}

#rentTable th:nth-child(3),
#rentTable td:nth-child(3){width:8% !important;}

#rentTable th:nth-child(4),
#rentTable td:nth-child(4){width:13% !important;}

#rentTable th:nth-child(5),
#rentTable td:nth-child(5){width:15% !important;}

#rentTable th:nth-child(6),
#rentTable td:nth-child(6){width:15% !important;}

#rentTable th:nth-child(7),
#rentTable td:nth-child(7){width:13% !important;}

#rentTable th:nth-child(8),
#rentTable td:nth-child(8){width:9% !important;}

#rentTable input[type="date"]{
    direction:ltr !important;
    text-align:center !important;
    padding:0 6px !important;
}


#rentTable .date-field{
    position:static !important;
}

#rentTable .date-icon{
    display:none !important;
}


.flatpickr-calendar{
    width:330px !important;
    min-width:330px !important;
    direction:ltr !important;
    border:none !important;
    border-radius:18px !important;
    box-shadow:0 18px 40px rgba(23,32,51,.16) !important;
    overflow:hidden !important;
    font-family:'Cairo', Tahoma, Arial, sans-serif !important;
}

.flatpickr-rContainer,
.flatpickr-days,
.dayContainer{
    width:330px !important;
    min-width:330px !important;
    max-width:330px !important;
}

.flatpickr-current-month{
    left:12.5% !important;
    width:75% !important;
    height:40px !important;
    padding-top:7px !important;
    font-size:15px !important;
    font-weight:900 !important;
}

.flatpickr-month{
    height:46px !important;
}

.flatpickr-weekday,
.flatpickr-day{
    max-width:47px !important;
    width:47px !important;
    height:39px !important;
    line-height:39px !important;
    font-size:13px !important;
    font-weight:800 !important;
}

@media(max-width:1000px){
    .table-wrap{
        overflow-x:auto !important;
    }

    #rentTable{
        min-width:900px !important;
    }
}

</style>
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <?php if(!empty($success_id)): ?>
        <div class="alert alert-success" id="successBox">
            تم حفظ العقد بنجاح - رقم العقد:
            <span style="color:#4f46e5;font-size:22px;font-weight:900;">
                <?= e($success_id) ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="page-head">
        <h1 class="page-title"><?= isset($_GET['id']) ? 'تعديل عقد إيجار' : 'إضافة عقد إيجار جديد' ?></h1>
        <p class="page-subtitle">اختر المورد، أدخل بيانات المسؤول والجوال، ثم أضف بنود الإيجار.</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
                <div>• <?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="supplier_status" value="registered">
        <input type="hidden" name="supplier_name" id="supplier_name" value="<?= e($form['supplier_name']) ?>">

        <div class="field">
            <div class="option-title">حالة المورد</div>

            <div class="supplier-status">
                <label class="status-card">
                    <input type="radio" name="supplier_status_view" value="registered" checked>
                    <div class="card">
                        <span class="icon">🏢</span>
                        <div>
                            <strong>مورد مسجل</strong>
                            <small>اختار مورد موجود بالفعل</small>
                        </div>
                    </div>
                </label>
            </div>
        </div>

        <div class="section-title">البيانات الأساسية</div>

        <div class="basic-info-grid">

            <div class="field supplier-field">
                <div id="supplier_search_box">
                    <label>بحث عن المورد</label>
                    <input type="text" id="supplier_search" placeholder="اكتب اسم المورد..." value="<?= e($form['supplier_name']) ?>">
                    <div id="results"></div>
                </div>
            </div>

            <div class="field">
                <label for="company_name">اسم المسؤول</label>
                <input type="text" id="company_name" name="company_name" placeholder="اكتب اسم المسؤول" value="<?= e($form['company_name']) ?>" required>
            </div>

            <div class="field">
                <label for="supplier_phone">رقم الجوال</label>
                <div class="phone">
                    <span>+966</span>
                    <input
                        type="text"
                        id="supplier_phone"
                        name="supplier_phone"
                        placeholder="5XXXXXXXX"
                        maxlength="9"
                        inputmode="numeric"
                        value="<?= e($form['supplier_phone']) ?>"
                    >
                </div>
                <div class="hint">مثال: 5XXXXXXXX</div>
            </div>

        </div>

        <div class="field">
            <div class="option-title">حالة العقد</div>

            <div class="option-buttons">

                <input type="radio" id="status1" name="status" value="تفاوض" required <?= $status_tafawod_checked ?>>
                <label for="status1">تفاوض</label>

                <input type="radio" id="status2" name="status" value="نهائي" <?= $status_final_checked ?>>
                <label for="status2" class="<?= $is_admin ? 'final-btn' : '' ?>">
                    <?= $is_admin ? 'إجراء نهائي' : 'إرسال للإدارة' ?>
                </label>

            </div>
        </div>

        <div class="section-title">البنود الإيجارية</div>

        <div class="box">

            <div class="table-wrap">
                <table class="table" id="rentTable">

                    <thead>
                        <tr>
                            <th>الفرع</th>
                            <th>الإيجار</th>
                            <th>العدد</th>
                            <th>شهري</th>
                            <th>من</th>
                            <th>إلى</th>
                            <th>الإجمالي</th>
                            <th class="row-actions">إجراء</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if(!empty($rents)): ?>
                        <?php foreach($rents as $r): ?>
                            <tr>
                                <td>
                                    <select name="rent_branch[]">
                                        <option value="">اختار</option>
                                        <option <?= ($r['branch'] ?? '')=='سكاى مول'?'selected':'' ?>>سكاى مول</option>
                                        <option <?= ($r['branch'] ?? '')=='المنصورة'?'selected':'' ?>>المنصورة</option>
                                        <option <?= ($r['branch'] ?? '')=='النسيم'?'selected':'' ?>>النسيم</option>
                                        <option <?= ($r['branch'] ?? '')=='الواحة'?'selected':'' ?>>الواحة</option>
                                        <option <?= ($r['branch'] ?? '')=='بريدة'?'selected':'' ?>>بريدة</option>
                                        <option <?= ($r['branch'] ?? '')=='حائل'?'selected':'' ?>>حائل</option>
                                        <option <?= ($r['branch'] ?? '')=='خميس مشيط'?'selected':'' ?>>خميس مشيط</option>
                                    </select>
                                </td>

                                <td>
                                    <select name="rent_type[]">
                                        <option value="">اختار</option>
                                        <option <?= ($r['type'] ?? '')=='جندولة'?'selected':'' ?>>جندولة</option>
                                        <option <?= ($r['type'] ?? '')=='عرض أرضي'?'selected':'' ?>>عرض أرضي</option>
                                    </select>
                                </td>

                                <td><input type="number" name="rent_qty[]" value="<?= e($r['qty'] ?? '') ?>" oninput="calcRow(this)"></td>

                                <td>
                                    <div class="currency-field">
                                        <span>ريال</span>
                                        <input type="number" name="rent_price[]" value="<?= e($r['price'] ?? '') ?>" oninput="calcRow(this)">
                                    </div>
                                </td>

                                <td>
                                    <div class="date-field">
                                        <input type="date" name="rent_from[]" value="<?= e($r['start_date'] ?? '') ?>" onchange="calcRow(this)">
                                    </div>
                                </td>

                                <td>
                                    <div class="date-field">
                                        <input type="date" name="rent_to[]" value="<?= e($r['end_date'] ?? '') ?>" onchange="calcRow(this)">
                                    </div>
                                </td>

                                <td><input type="text" name="rent_total[]" value="<?= e($r['total'] ?? '') ?>" readonly></td>

                                <td class="row-actions">
                                    <button type="button" class="icon-btn remove-btn" onclick="safeRemoveRow(this)">حذف</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td>
                                <select name="rent_branch[]">
                                    <option value="">اختار</option>
                                    <option>سكاى مول</option>
                                    <option>المنصورة</option>
                                    <option>النسيم</option>
                                    <option>الواحة</option>
                                    <option>بريدة</option>
                                    <option>حائل</option>
                                    <option>خميس مشيط</option>
                                </select>
                            </td>

                            <td>
                                <select name="rent_type[]">
                                    <option value="">اختار</option>
                                    <option>جندولة</option>
                                    <option>عرض أرضي</option>
                                </select>
                            </td>

                            <td><input type="number" name="rent_qty[]" oninput="calcRow(this)"></td>

                            <td>
                                <div class="currency-field">
                                    <span>ريال</span>
                                    <input type="number" name="rent_price[]" oninput="calcRow(this)">
                                </div>
                            </td>

                            <td>
                                <div class="date-field">
                                    <input type="date" name="rent_from[]" onchange="calcRow(this)">
                                </div>
                            </td>

                            <td>
                                <div class="date-field">
                                    <input type="date" name="rent_to[]" onchange="calcRow(this)">
                                </div>
                            </td>

                            <td><input type="text" name="rent_total[]" readonly></td>

                            <td class="row-actions">
                                <button type="button" class="icon-btn remove-btn" onclick="safeRemoveRow(this)">حذف</button>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <button type="button" class="icon-btn add-btn" onclick="addRentRow()">+ إضافة فرع</button>

            <div class="total-box">
                إجمالي الإيجارات: <span id="grandTotal">0</span> ريال
            </div>

            <textarea id="rentSummary" style="display:none;"></textarea>

        </div>

        <div class="section-title">ملاحظات أخرى</div>

        <div class="field">
            <textarea id="notes" name="notes" placeholder="اكتب أي ملاحظات إضافية هنا..."><?= e($form['notes']) ?></textarea>
        </div>

        <button type="submit" class="submit">حفظ العقد</button>

    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ar.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function(){

    initDatePickers();

    calcGrand();
    buildSummary();

    let box = document.getElementById("successBox");
    if(box){
        setTimeout(function(){
            box.style.display = "none";
        }, 8000);
    }

    let supplierSearch = document.getElementById("supplier_search");
    let supplierHidden = document.getElementById("supplier_name");

    if(supplierSearch && supplierHidden){
        supplierHidden.value = supplierSearch.value;

        supplierSearch.addEventListener("input", function(){
            supplierHidden.value = this.value;
        });
    }
});

function initDatePickers(){
    
    return;
}

function addRentRow(){

    let table = document.querySelector("#rentTable tbody");

    let row = document.createElement("tr");

    row.innerHTML = `
<td>
<select name="rent_branch[]">
<option value="">اختار</option>
<option>سكاى مول</option>
<option>المنصورة</option>
<option>النسيم</option>
<option>الواحة</option>
<option>بريدة</option>
<option>حائل</option>
<option>خميس مشيط</option>
</select>
</td>

<td>
<select name="rent_type[]">
<option value="">اختار</option>
<option>جندولة</option>
<option>عرض أرضي</option>
</select>
</td>

<td><input type="number" name="rent_qty[]" oninput="calcRow(this)"></td>

<td>
<div class="currency-field">
<span>ريال</span>
<input type="number" name="rent_price[]" oninput="calcRow(this)">
</div>
</td>

<td>
<div class="date-field">
<input type="date" name="rent_from[]" onchange="calcRow(this)">
</div>
</td>

<td>
<div class="date-field">
<input type="date" name="rent_to[]" onchange="calcRow(this)">
</div>
</td>

<td><input type="text" name="rent_total[]" readonly></td>

<td class="row-actions">
<button type="button" class="icon-btn remove-btn" onclick="safeRemoveRow(this)">حذف</button>
</td>
`;

    table.appendChild(row);
    initDatePickers();
}

function calcRow(el){
    let row = el.closest("tr");

    if(!row){
        return;
    }

    let qty = Number(row.querySelector("[name='rent_qty[]']").value || 0);
    let price = Number(row.querySelector("[name='rent_price[]']").value || 0);

    let fromValue = row.querySelector("[name='rent_from[]']").value;
    let toValue = row.querySelector("[name='rent_to[]']").value;

    let months = 1;

    if(fromValue && toValue){
        let from = new Date(fromValue);
        let to = new Date(toValue);

        if(!isNaN(from) && !isNaN(to)){
            months = (to.getFullYear() - from.getFullYear()) * 12 +
                     (to.getMonth() - from.getMonth()) + 1;

            if(months < 1){
                months = 1;
            }
        }
    }

    let total = qty * price * months;

    row.querySelector("[name='rent_total[]']").value = parseInt(total || 0);

    calcGrand();
    buildSummary();
}

function calcGrand(){
    let totals = document.querySelectorAll("[name='rent_total[]']");
    let sum = 0;

    totals.forEach(t => {
        let val = String(t.value || "").trim();

        if(val !== ""){
            sum += Number(val);
        }
    });

    let grand = document.getElementById("grandTotal");
    if(grand){
        grand.innerText = sum.toLocaleString('en-US');
    }
}

function buildSummary(){
    let rows = document.querySelectorAll("#rentTable tbody tr");
    let text = "";

    rows.forEach(r => {

        let branchEl = r.querySelector("[name='rent_branch[]']");
        let typeEl   = r.querySelector("[name='rent_type[]']");
        let qtyEl    = r.querySelector("[name='rent_qty[]']");
        let priceEl  = r.querySelector("[name='rent_price[]']");
        let totalEl  = r.querySelector("[name='rent_total[]']");

        let branch = branchEl ? branchEl.value : "";
        let type   = typeEl ? typeEl.value : "";
        let qty    = qtyEl ? qtyEl.value : 0;
        let price  = priceEl ? priceEl.value : 0;
        let total  = totalEl ? totalEl.value : 0;

        if(branch === "" && type === "" && qty == 0 && price == 0 && total == 0){
            return;
        }

        text += branch + " - " + type + "\n" +
                "عدد: " + qty + "\n" +
                "السعر: " + price + "\n" +
                "الإجمالي: " + total + " ريال\n\n";

    });

    let box = document.getElementById("rentSummary");
    if(box){
        box.value = text;
    }
}

function safeRemoveRow(btn){
    let row = btn.closest("tr");
    let tbody = btn.closest("tbody");

    if(tbody.children.length > 1){
        row.remove();
    }else{
        let inputs = row.querySelectorAll("input");
        let selects = row.querySelectorAll("select");

        inputs.forEach(i => i.value = "");
        selects.forEach(s => s.selectedIndex = 0);
    }

    calcGrand();
    buildSummary();
}

let supplierSearchInput = document.getElementById("supplier_search");

if(supplierSearchInput){
    supplierSearchInput.addEventListener("keyup", function(){

        let query = this.value;

        if(query.length < 2){
            document.getElementById("results").innerHTML = "";
            return;
        }

        fetch("search_supplier.php?q=" + encodeURIComponent(query))
            .then(res => res.text())
            .then(data => {
                document.getElementById("results").innerHTML = data;
            })
            .catch(() => {
                document.getElementById("results").innerHTML = "<div class='item'>تعذر البحث عن المورد</div>";
            });
    });
}

let resultsBox = document.getElementById("results");
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
    document.getElementById("supplier_search").value = name;
    document.getElementById("supplier_name").value = name;
    document.getElementById("results").innerHTML = "";
}

document.querySelector("form").addEventListener("submit", function(){
    let supplierSearch = document.getElementById("supplier_search");
    let supplierHidden = document.getElementById("supplier_name");

    if(supplierSearch && supplierHidden){
        supplierHidden.value = supplierSearch.value;
    }
});
</script>

</body>
</html>
