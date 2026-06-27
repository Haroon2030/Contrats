<?php require_once VC_HELPERS . '/auth.php'; 
?>

<?php

date_default_timezone_set('Asia/Riyadh');

function vcDisabledManagerHook(VcDb $conn, int $createdByUserId, string $title, string $message, string $link = '', string $type = 'general', int $relatedId = 0, int $excludeUserId = 0): void {
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

            

            header('Location: ' . vcRedirectUrl('rents.php?success=1&id=' . $contract_id));
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

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<?php vcRenderPageAssets(['forms' => true]); ?>
<?php if (vcIsEmbedRequest()) { vcRenderEmbedShell(); } ?>
</head>

<body<?= vcIsEmbedRequest() ? ' class="vc-embed"' : '' ?>>

<?php if (!vcIsEmbedRequest()): ?>
<?php include VC_VIEWS . '/layouts/header.php'; ?>
<?php endif; ?>

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
        <p class="page-subtitle">كل تبويب يحتوي جزءًا من النموذج — انتقل بينها بالترتيب ثم احفظ</p>
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

    <form method="POST" autocomplete="off" class="rent-form">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="supplier_status" value="registered">
        <input type="hidden" name="supplier_name" id="supplier_name" value="<?= e($form['supplier_name']) ?>">

        <nav class="rent-tabs" aria-label="أقسام عقد الإيجار">
            <div class="rent-tabs-head">
                <p class="rent-tabs-title">خطوات عقد الإيجار</p>
                <span class="rent-tab-progress" id="rentTabProgress">الخطوة 1 من 4</span>
            </div>
            <div class="rent-tabs-inner" role="tablist">
                <button type="button" class="rent-tab is-active" role="tab" data-target="sec-supplier" aria-selected="true"><span>1</span> المورد</button>
                <button type="button" class="rent-tab" role="tab" data-target="sec-basic" aria-selected="false"><span>2</span> البيانات الأساسية</button>
                <button type="button" class="rent-tab" role="tab" data-target="sec-rents" aria-selected="false"><span>3</span> البنود الإيجارية</button>
                <button type="button" class="rent-tab" role="tab" data-target="sec-notes" aria-selected="false"><span>4</span> الملاحظات والحفظ</button>
            </div>
        </nav>

        <div class="rent-form-main">

        <section class="form-panel rent-tab-panel is-active" id="sec-supplier" role="tabpanel">
            <div class="section-title"><span class="step-num">1</span> المورد</div>

            <div class="field">
                <div class="option-title">حالة المورد</div>
                <div class="supplier-status">
                    <label class="status-card">
                        <input type="radio" name="supplier_status_view" value="registered" checked>
                        <div class="card">
                            <span class="icon">🏢</span>
                            <div>
                                <strong>مورد مسجل</strong>
                                <small>ابحث باسم المورد ثم اختر من النتائج المطابقة</small>
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="field supplier-field">
                <div id="supplier_search_box">
                    <label for="supplier_search">اسم المورد</label>
                    <div class="supplier-search-row">
                        <input type="text" id="supplier_search" placeholder="اكتب اسم المورد للبحث..." value="<?= e($form['supplier_name']) ?>" autocomplete="off">
                        <button type="button" class="btn-supplier-search" id="supplierSearchBtn">🔍 بحث</button>
                    </div>
                    <div class="supplier-list-hint" id="supplierListHint">اكتب حرفين على الأقل ثم اضغط بحث أو انتظر لحظات لعرض المطابقات</div>
                    <div id="results" role="listbox" aria-label="نتائج البحث عن المورد"></div>
                </div>
            </div>
        </section>

        <section class="form-panel rent-tab-panel" id="sec-basic" role="tabpanel">
            <div class="section-title"><span class="step-num">2</span> البيانات الأساسية وحالة العقد</div>

            <div class="panel-subtitle">بيانات التواصل</div>
            <div class="basic-info-grid">
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

            <div class="panel-divider"></div>

            <div class="panel-subtitle">حالة العقد</div>
            <div class="field">
                <div class="option-buttons">
                    <input type="radio" id="status1" name="status" value="تفاوض" required <?= $status_tafawod_checked ?>>
                    <label for="status1">تفاوض</label>

                    <input type="radio" id="status2" name="status" value="نهائي" <?= $status_final_checked ?>>
                    <label for="status2" class="<?= $is_admin ? 'final-btn' : '' ?>">
                        <?= $is_admin ? 'إجراء نهائي' : 'إرسال للإدارة' ?>
                    </label>
                </div>
            </div>
        </section>

        <section class="form-panel rent-tab-panel" id="sec-rents" role="tabpanel">
            <div class="section-title"><span class="step-num">3</span> البنود الإيجارية</div>

            <div class="box">
                <div class="rent-table-meta">
                    <span>جدول البنود — الفرع، النوع، الفترة، والمبالغ</span>
                    <span>يُحسب الإجمالي تلقائيًا</span>
                </div>

                <div class="table-wrap">
                <table class="table" id="rentTable">
                    <thead>
                        <tr>
                            <th colspan="2" class="th-group th-group--item">بيانات البند</th>
                            <th colspan="2" class="th-group th-group--period">الكمية والسعر</th>
                            <th colspan="2" class="th-group th-group--period">الفترة</th>
                            <th colspan="2" class="th-group">الإجمالي والإجراء</th>
                        </tr>
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

                <div class="rent-table-footer">
                    <button type="button" class="icon-btn add-btn" onclick="addRentRow()">+ إضافة فرع</button>
                    <div class="total-box">
                        إجمالي الإيجارات: <span id="grandTotal">0</span> ريال
                    </div>
                </div>

                <textarea id="rentSummary" style="display:none;"></textarea>
            </div>
        </section>

        <section class="form-panel rent-tab-panel" id="sec-notes" role="tabpanel">
            <div class="section-title"><span class="step-num">4</span> ملاحظات أخرى</div>

            <div class="field">
                <textarea id="notes" name="notes" placeholder="اكتب أي ملاحظات إضافية هنا..."><?= e($form['notes']) ?></textarea>
            </div>
        </section>

        <div class="form-actions-bar">
            <button type="button" class="btn-tab-nav" id="rentTabPrev" disabled>السابق</button>
            <button type="button" class="btn-tab-nav" id="rentTabNext">التالي</button>
            <button type="submit" class="submit" id="rentTabSubmit" hidden>حفظ العقد</button>
        </div>

        </div>

    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ar.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function(){

    initRentTabs();
    initDatePickers();

    calcGrand();
    buildSummary();

    initSupplierSmartSearch();

    let box = document.getElementById("successBox");
    if(box){
        setTimeout(function(){
            box.style.display = "none";
        }, 8000);
    }
});

function initRentTabs(){
    const tabs = Array.from(document.querySelectorAll(".rent-tab[data-target]"));
    const prevBtn = document.getElementById("rentTabPrev");
    const nextBtn = document.getElementById("rentTabNext");
    const submitBtn = document.getElementById("rentTabSubmit");
    const progress = document.getElementById("rentTabProgress");

    if(!tabs.length){
        return;
    }

    let currentIndex = 0;

    function panelForTab(index){
        const targetId = tabs[index] ? tabs[index].getAttribute("data-target") : "";
        return targetId ? document.getElementById(targetId) : null;
    }

    function isFieldVisible(field){
        if(!field || field.type === "hidden" || field.disabled){
            return false;
        }

        let node = field;
        while(node && node !== document.body){
            const style = window.getComputedStyle(node);
            if(style.display === "none" || style.visibility === "hidden"){
                return false;
            }
            node = node.parentElement;
        }

        return true;
    }

    function validatePanel(panel){
        if(!panel){
            return true;
        }

        if(panel.id === "sec-supplier"){
            const supplierHidden = document.getElementById("supplier_name");
            const supplierSearch = document.getElementById("supplier_search");
            if(supplierHidden && !supplierHidden.value.trim()){
                if(supplierSearch){
                    supplierSearch.setCustomValidity("اختر المورد أو اكتب اسمه.");
                    supplierSearch.reportValidity();
                    supplierSearch.setCustomValidity("");
                }
                return false;
            }
        }

        const requiredRadios = {};
        panel.querySelectorAll('input[type="radio"][required]').forEach(function(radio){
            requiredRadios[radio.name] = true;
        });

        for(const name in requiredRadios){
            if(!panel.querySelector('input[type="radio"][name="' + name + '"]:checked')){
                const first = panel.querySelector('input[type="radio"][name="' + name + '"]');
                if(first){
                    first.setCustomValidity("اختر أحد الخيارات.");
                    first.reportValidity();
                    first.setCustomValidity("");
                }
                return false;
            }
        }

        const fields = panel.querySelectorAll("input, select, textarea");
        for(let i = 0; i < fields.length; i++){
            const field = fields[i];
            if(field.type === "radio" || field.type === "hidden" || field.disabled){
                continue;
            }
            if(!isFieldVisible(field)){
                continue;
            }
            if(!field.checkValidity()){
                field.reportValidity();
                return false;
            }
        }

        return true;
    }

    function updateUi(){
        const tab = tabs[currentIndex];
        const targetId = tab ? tab.getAttribute("data-target") : "";

        document.querySelectorAll(".rent-tab-panel").forEach(function(panel){
            panel.classList.toggle("is-active", panel.id === targetId);
        });

        tabs.forEach(function(item, index){
            const active = index === currentIndex;
            item.classList.toggle("is-active", active);
            item.classList.toggle("is-done", index < currentIndex);
            item.setAttribute("aria-selected", active ? "true" : "false");
        });

        if(progress && tab){
            progress.textContent = "الخطوة " + (currentIndex + 1) + " من " + tabs.length + " — " + tab.textContent.trim();
        }

        if(prevBtn){
            prevBtn.disabled = currentIndex === 0;
        }

        const isLast = currentIndex === tabs.length - 1;

        if(nextBtn){
            nextBtn.hidden = isLast;
        }

        if(submitBtn){
            submitBtn.hidden = !isLast;
        }
    }

    function goTo(index, validateCurrent){
        if(index < 0 || index >= tabs.length){
            return;
        }

        if(validateCurrent && index > currentIndex){
            if(!validatePanel(panelForTab(currentIndex))){
                return;
            }
        }

        currentIndex = index;
        updateUi();
    }

    tabs.forEach(function(tab, index){
        tab.addEventListener("click", function(){
            goTo(index, false);
        });
    });

    if(prevBtn){
        prevBtn.addEventListener("click", function(){
            goTo(currentIndex - 1, false);
        });
    }

    if(nextBtn){
        nextBtn.addEventListener("click", function(){
            goTo(currentIndex + 1, true);
        });
    }

    updateUi();
}

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
let supplierResultsBox = document.getElementById("results");
let supplierSearchBtn = document.getElementById("supplierSearchBtn");
let supplierListHint = document.getElementById("supplierListHint");
let supplierLoadTimer = null;

function closeSupplierResults(){
    if(supplierResultsBox){
        supplierResultsBox.innerHTML = "";
        supplierResultsBox.classList.remove("is-open");
    }
}

function openSupplierResults(html){
    if(!supplierResultsBox){
        return;
    }
    supplierResultsBox.innerHTML = html;
    supplierResultsBox.classList.add("is-open");
}

function searchSuppliersByName(query, showMinHint){
    const q = (query || "").trim();

    if(q.length < 2){
        closeSupplierResults();
        if(showMinHint && supplierListHint){
            supplierListHint.textContent = "اكتب حرفين على الأقل للبحث عن المورد";
            supplierListHint.style.color = "#b45309";
        }
        return;
    }

    if(supplierListHint){
        supplierListHint.textContent = "جاري البحث عن المطابقات...";
        supplierListHint.style.color = "#7c6bb8";
    }

    if(supplierSearchBtn){
        supplierSearchBtn.disabled = true;
        supplierSearchBtn.textContent = "جاري البحث...";
    }

    fetch("search_supplier.php?q=" + encodeURIComponent(q))
        .then(function(res){ return res.text(); })
        .then(function(data){
            const html = (data || "").trim();
            if(html){
                openSupplierResults(html);
                if(supplierListHint){
                    supplierListHint.textContent = "اختر المورد من النتائج المطابقة";
                    supplierListHint.style.color = "#166534";
                }
            }else{
                openSupplierResults("<div class='item' style='cursor:default;color:#777;'>لا توجد نتائج مطابقة</div>");
                if(supplierListHint){
                    supplierListHint.textContent = "لم يُعثر على مورد بهذا الاسم";
                    supplierListHint.style.color = "#b42318";
                }
            }
        })
        .catch(function(){
            openSupplierResults("<div class='item' style='cursor:default;color:#b42318;'>تعذر البحث، حاول مرة أخرى</div>");
            if(supplierListHint){
                supplierListHint.textContent = "حدث خطأ أثناء البحث";
                supplierListHint.style.color = "#b42318";
            }
        })
        .finally(function(){
            if(supplierSearchBtn){
                supplierSearchBtn.disabled = false;
                supplierSearchBtn.textContent = "🔍 بحث";
            }
        });
}

function initSupplierSmartSearch(){
    const supplierSearch = document.getElementById("supplier_search");
    const supplierHidden = document.getElementById("supplier_name");
    const searchBtn = document.getElementById("supplierSearchBtn");

    if(!supplierSearch || !supplierHidden){
        return;
    }

    supplierHidden.value = supplierSearch.value;

    supplierSearch.addEventListener("input", function(){
        supplierHidden.value = this.value;

        clearTimeout(supplierLoadTimer);

        const q = this.value.trim();
        if(q.length < 2){
            closeSupplierResults();
            if(supplierListHint){
                supplierListHint.textContent = "اكتب حرفين على الأقل ثم اضغط بحث أو انتظر لحظات لعرض المطابقات";
                supplierListHint.style.color = "#7c6bb8";
            }
            return;
        }

        supplierLoadTimer = setTimeout(function(){
            searchSuppliersByName(q, false);
        }, 400);
    });

    supplierSearch.addEventListener("keydown", function(e){
        if(e.key === "Enter"){
            e.preventDefault();
            clearTimeout(supplierLoadTimer);
            searchSuppliersByName(this.value.trim(), true);
        }
    });

    if(searchBtn){
        searchBtn.addEventListener("click", function(){
            clearTimeout(supplierLoadTimer);
            searchSuppliersByName(supplierSearch.value.trim(), true);
        });
    }

    document.addEventListener("click", function(e){
        const box = document.getElementById("supplier_search_box");
        if(box && !box.contains(e.target)){
            closeSupplierResults();
        }
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

function selectSupplier(name, contact, phone){
    const searchEl = document.getElementById("supplier_search");
    const hiddenEl = document.getElementById("supplier_name");
    const companyEl = document.getElementById("company_name");
    const phoneEl = document.getElementById("supplier_phone");

    if(searchEl){
        searchEl.value = name || "";
    }
    if(hiddenEl){
        hiddenEl.value = name || "";
    }
    if(companyEl && contact){
        companyEl.value = contact;
    }
    if(phoneEl && phone){
        phoneEl.value = phone;
    }
    if(supplierResultsBox){
        supplierResultsBox.innerHTML = "";
        supplierResultsBox.classList.remove("is-open");
    }
    if(supplierListHint){
        supplierListHint.textContent = "تم اختيار المورد — يمكنك المتابعة للخطوة التالية";
        supplierListHint.style.color = "#166534";
    }
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
