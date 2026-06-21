<?php


require_once VC_HELPERS . '/auth.php';
?>

<?php
$uid = (int)$_SESSION['user_id'];

$stmtAdmin = $conn->prepare("SELECT is_admin FROM users WHERE id=? LIMIT 1");
$stmtAdmin->bind_param("i", $uid);
$stmtAdmin->execute();
$is_admin = (int)($stmtAdmin->get_result()->fetch_assoc()['is_admin'] ?? 0);
$stmtAdmin->close();

date_default_timezone_set('Asia/Riyadh');

$currentUsernameForNotif = $_SESSION['username'] ?? 'مستخدم';


function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function defaultForm(): array {
    return [
        'supplier_name'            => '',
        'company_name'             => '',
        'supplier_phone'           => '',
        'supplier_status'          => '',
        'status'                   => '',
        'start_date'               => '',
        'end_date'                 => '',
        'payment_period'           => '',
        'new_item_fee'             => '',

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
        'event_notes'              => ['', ''],

        'notes'                    => '',
        'contract_form_type'       => 'system',
        'supplier_contract_file'   => '',
        'supplier_contract_ref'    => '',
        'supplier_contract_note'   => '',
    ];
}

function ensureMinRows(array $arr, int $min = 2): array {
    $arr = array_values($arr);
    while (count($arr) < $min) {
        $arr[] = '';
    }
    return $arr;
}


function historyNormalizeValue($value): string {
    $value = trim((string)$value);
    $value = strip_tags($value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);

    $value = str_replace(['٫'], '.', $value);
    $value = str_replace(['٬', ','], '', $value);
    $value = str_replace(['ريال', 'ر.س'], '', $value);

    $value = str_replace(['أ','إ','آ'], 'ا', $value);
    $value = str_replace('ى', 'ي', $value);
    $value = str_replace('ة', 'ه', $value);
    $value = str_replace(['ـ'], '', $value);

    $value = preg_replace('/\s*([():٪%|\/-])\s*/u', '$1', $value);
    $value = trim($value);

    if (is_numeric($value)) {
        return 'num:' . rtrim(rtrim(number_format((float)$value, 4, '.', ''), '0'), '.');
    }

    return $value;
}

function historyKey($value): string {
    $value = historyNormalizeValue($value);
    $value = preg_replace('/[^0-9a-zA-Z\p{Arabic}]+/u', '', $value);
    return $value ?: 'empty';
}

function historyMoneyText($value): string {
    if ($value === '' || $value === null) {
        return '0.00';
    }
    return number_format((float)$value, 2);
}

function logHistoryIfChanged(VcDb $conn, int $contract_id, int $uid, string $field, $oldValue, $newValue): void {
    $oldText = trim((string)$oldValue);
    $newText = trim((string)$newValue);

    if (historyNormalizeValue($oldText) === historyNormalizeValue($newText)) {
        return;
    }

    $created_at = date('Y-m-d H:i:s');

    $stmtLog = $conn->prepare("
        INSERT INTO contract_history 
        (contract_id, user_id, field_name, old_value, new_value, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmtLog) {
        return;
    }

    $stmtLog->bind_param(
        "iissss",
        $contract_id,
        $uid,
        $field,
        $oldText,
        $newText,
        $created_at
    );

    $stmtLog->execute();
    $stmtLog->close();
}

function rentHistoryKey(array $row): string {
    return historyKey(($row['branch'] ?? '') . '|' . ($row['type'] ?? ''));
}

function rentHistoryText(array $row): string {
    $branch = trim((string)($row['branch'] ?? ''));
    $type   = trim((string)($row['type'] ?? ''));
    $qty    = trim((string)($row['qty'] ?? ''));
    $price  = trim((string)($row['price'] ?? ''));
    $from   = trim((string)($row['start_date'] ?? ($row['from'] ?? '')));
    $to     = trim((string)($row['end_date'] ?? ($row['to'] ?? '')));

    if ($branch === '' && $type === '' && $qty === '') {
        return '-';
    }

    return "{$branch} / {$type} (عدد: {$qty} - سعر: " . historyMoneyText($price) . " - من: {$from} - إلى: {$to})";
}

function annualHistoryKey(array $row): string {
    $target = trim((string)($row['target'] ?? ''));
    if ($target !== '') {
        return historyKey($target);
    }

    return historyKey((string)($row['percent'] ?? ''));
}

function annualHistoryText(array $row): string {
    $percent = trim((string)($row['percent'] ?? ''));
    $target  = trim((string)($row['target'] ?? ''));

    if ($percent === '' && $target === '') {
        return '-';
    }

    return "{$percent}% (هدف: {$target})";
}

function eventHistoryKey(array $row): string {
    $type = trim((string)($row['type'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));

    if ($type !== '' && $type !== 'custom') {
        return historyKey($type);
    }

    return historyKey($name);
}

function eventHistoryText(array $row): string {
    $name  = trim((string)($row['name'] ?? ''));
    $value = trim((string)($row['value'] ?? ''));

    if ($name === '' && $value === '') {
        return '-';
    }

    return "{$name} (" . historyMoneyText($value) . " ريال)";
}


function vcColumnExists(VcDb $conn, string $table, string $column): bool {
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


function vcSafeSupplierContractFile(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }

    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    if (strpos($path, '..') !== false) {
        return '';
    }

    if (strpos($path, 'uploads/supplier_contracts/') !== 0) {
        return '';
    }

    return $path;
}

function vcHandleSupplierContractUpload(array &$errors, string $currentFile = ''): string {
    $currentFile = vcSafeSupplierContractFile($currentFile);

    if (empty($_FILES['supplier_contract_file']) || (int)($_FILES['supplier_contract_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentFile;
    }

    $file = $_FILES['supplier_contract_file'];

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'تعذر رفع ملف عقد المورد. جرّب تاني.';
        return $currentFile;
    }

    $maxBytes = 15 * 1024 * 1024;
    if ((int)$file['size'] > $maxBytes) {
        $errors[] = 'ملف عقد المورد أكبر من 15 ميجا.';
        return $currentFile;
    }

    $originalName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['pdf','doc','docx','jpg','jpeg','png','webp'];

    if (!in_array($ext, $allowed, true)) {
        $errors[] = 'صيغة ملف عقد المورد غير مسموحة. المسموح: PDF / Word / صور.';
        return $currentFile;
    }

    $uploadDir = VC_PUBLIC . '/uploads/supplier_contracts';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $htaccessPath = $uploadDir . '/.htaccess';
    if (!file_exists($htaccessPath)) {
        @file_put_contents($htaccessPath, "Options -Indexes\n<FilesMatch \"\\.php$\">\n    Require all denied\n</FilesMatch>\n");
    }

    $newName = 'supplier_contract_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = $uploadDir . '/' . $newName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        $errors[] = 'فشل حفظ ملف عقد المورد على السيرفر.';
        return $currentFile;
    }

    return 'uploads/supplier_contracts/' . $newName;
}

function vcDisabledHookSetup(VcDb $conn): void {
    return;
}


function vcDisabledUserHook(VcDb $conn, int $userId, string $title, string $message, string $link = '', string $type = 'contract', int $relatedId = 0): void {
    return;
}

function vcDisabledAdminsHook(VcDb $conn, string $title, string $message, string $link = '', string $type = 'contract', int $relatedId = 0, int $excludeUserId = 0): void {
    return;
}



function vcDisabledManagerHook(VcDb $conn, int $createdByUserId, string $title, string $message, string $link = '', string $type = 'contract', int $relatedId = 0, int $excludeUserId = 0): void {
    return;
}

$form = defaultForm();
$rents = [];
$success = '';
$errors = [];
$show_view_button = false;
$contract_id = null;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


if(isset($_GET['id'])){

    $id = (int)$_GET['id'];

    if($is_admin){
        $stmt = $conn->prepare("SELECT * FROM contracts WHERE id=? LIMIT 1");
        $stmt->bind_param("i",$id);
    }else{
        $stmt = $conn->prepare("SELECT * FROM contracts WHERE id=? AND created_by=? LIMIT 1");
        $stmt->bind_param("ii",$id,$uid);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    if($res->num_rows){

        $data = $res->fetch_assoc();

        foreach($form as $key => $val){
            if(isset($data[$key])){
                $form[$key] = $data[$key];
            }
        }

        
        $form['status'] = $is_admin ? 'تفاوض' : 'نهائي';

        $stmt2 = $conn->prepare("SELECT percent, target FROM annual_discounts WHERE contract_id=?");
        $stmt2->bind_param("i",$id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();

        $form['annual_discount_percents'] = [];
        $form['annual_discount_targets'] = [];

        while($row = $res2->fetch_assoc()){
            $form['annual_discount_percents'][] = $row['percent'];
            $form['annual_discount_targets'][]  = $row['target'];
        }

        $form['annual_discount_percents'] = ensureMinRows($form['annual_discount_percents']);
        $form['annual_discount_targets']  = ensureMinRows($form['annual_discount_targets']);

        $stmt3 = $conn->prepare("SELECT value, name, note, type FROM events WHERE contract_id=?");
        $stmt3->bind_param("i",$id);
        $stmt3->execute();
        $res3 = $stmt3->get_result();

        $form['event_values'] = [];
        $form['event_names']  = [];
        $form['event_notes']  = [];

        while($row = $res3->fetch_assoc()){

            if(($row['type'] ?? '') === 'new_item' || ($row['name'] ?? '') === 'رسوم صنف جديد'){
                $form['new_item_fee'] = $row['value'];
                continue;
            }

            $form['event_values'][] = $row['value'];
            $form['event_names'][]  = $row['name'];
            $form['event_notes'][]  = $row['note'];
        }

        $form['event_values'] = ensureMinRows($form['event_values']);
        $form['event_names']  = ensureMinRows($form['event_names']);
        $form['event_notes']  = ensureMinRows($form['event_notes']);

        $stmt4 = $conn->prepare("SELECT * FROM rents WHERE contract_id=?");
        $stmt4->bind_param("i",$id);
        $stmt4->execute();
        $res4 = $stmt4->get_result();

        while($row = $res4->fetch_assoc()){
            $rents[] = $row;
        }

    } else {
        die("❌ غير مصرح لك بالوصول لهذا العقد");
    }
}


if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        empty($_POST['csrf_token']) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $errors[] = "الطلب مش صالح، جرّب تاني.";
    }

    $form['supplier_name']            = trim($_POST['supplier_name'] ?? '');
    $form['company_name']             = trim($_POST['company_name'] ?? '');
    $form['supplier_phone']           = trim($_POST['supplier_phone'] ?? '');
    $form['supplier_status']          = trim($_POST['supplier_status'] ?? '');
    $form['status']                   = trim($_POST['status'] ?? '');
    $form['start_date']               = trim($_POST['start_date'] ?? '');
    $form['end_date']                 = trim($_POST['end_date'] ?? '');
    $form['payment_period']           = trim($_POST['payment_period'] ?? '');
    $form['new_item_fee']             = trim($_POST['new_item_fee'] ?? '');

    $form['discount_invoice']         = trim($_POST['discount_invoice'] ?? '');
    $form['discount_invoice_note']    = trim($_POST['discount_invoice_note'] ?? '');

    $form['discount_payment']         = trim($_POST['discount_payment'] ?? '');
    $form['discount_payment_note']    = trim($_POST['discount_payment_note'] ?? '');

    $form['discount_quarter']         = trim($_POST['discount_quarter'] ?? '');
    $form['discount_quarter_note']    = trim($_POST['discount_quarter_note'] ?? '');

    $form['annual_discount_percents'] = ensureMinRows($_POST['annual_discount_percent'] ?? []);
    $form['annual_discount_targets']  = ensureMinRows($_POST['annual_discount_target'] ?? []);

    $form['event_values']             = ensureMinRows($_POST['event_value'] ?? []);
    $form['event_names']              = ensureMinRows($_POST['event_name'] ?? []);
    $form['event_notes']              = ensureMinRows($_POST['event_note'] ?? []);

    $form['notes']                    = trim($_POST['notes'] ?? '');
    $form['contract_form_type']       = trim($_POST['contract_form_type'] ?? 'system');
    $form['supplier_contract_file']   = vcHandleSupplierContractUpload($errors, $_POST['current_supplier_contract_file'] ?? '');
    $form['supplier_contract_ref']    = array_key_exists('supplier_contract_ref', $_POST) ? trim($_POST['supplier_contract_ref']) : (string)($form['supplier_contract_ref'] ?? '');
    $form['supplier_contract_note']   = array_key_exists('supplier_contract_note', $_POST) ? trim($_POST['supplier_contract_note']) : (string)($form['supplier_contract_note'] ?? '');

    if (!in_array($form['contract_form_type'], ['system','supplier'], true)) {
        $form['contract_form_type'] = 'system';
    }

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

    if ($form['payment_period'] === '' || !is_numeric($form['payment_period']) || (int)$form['payment_period'] <= 0) {
        $errors[] = "فترة السداد مطلوبة ولازم تكون رقم أكبر من صفر.";
    }

    if (!in_array($form['status'], ['تفاوض', 'نهائي'], true)) {
        $errors[] = "اختار حالة العقد.";
    }

    if ($form['contract_form_type'] === 'supplier' && $form['supplier_contract_file'] === '') {
        $errors[] = "ارفع ملف عقد المورد لأنك اخترت نموذج مورد خارجي.";
    }

    $startDateObj = DateTime::createFromFormat('Y-m-d', $form['start_date']);
    $endDateObj   = DateTime::createFromFormat('Y-m-d', $form['end_date']);

    $startDateValid = $startDateObj && $startDateObj->format('Y-m-d') === $form['start_date'];
    $endDateValid   = $endDateObj && $endDateObj->format('Y-m-d') === $form['end_date'];

    if (!$startDateValid) {
        $errors[] = "تاريخ البداية غير صحيح.";
    }

    if (!$endDateValid) {
        $errors[] = "تاريخ النهاية غير صحيح.";
    }

    if ($startDateValid && $endDateValid && $endDateObj < $startDateObj) {
        $errors[] = "تاريخ النهاية لازم يكون بعد تاريخ البداية.";
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

            $isEditingContract = isset($_GET['id']);
            $oldStatusBeforeSave = '';
            $oldOwnerId = 0;
            $oldSupplierBeforeSave = '';

            if ($form['status'] === 'تفاوض') {
                $form['status'] = 'draft';
            } elseif ($form['status'] === 'نهائي') {
                $form['status'] = $is_admin ? 'approved' : 'review';
            }

            $old = [];
            $old_rents = [];
            $old_annual = [];
            $old_events = [];

            if(isset($_GET['id'])){

                $id = (int)$_GET['id'];
                $old = $conn->query("SELECT * FROM contracts WHERE id=$id")->fetch_assoc() ?: [];
                $oldStatusBeforeSave = (string)($old['status'] ?? '');
                $oldOwnerId = (int)($old['created_by'] ?? 0);
                $oldSupplierBeforeSave = (string)($old['supplier_name'] ?? '');

                if($is_admin){

                    $stmt = $conn->prepare("
                        UPDATE contracts SET
                            supplier_name=?,
                            company_name=?,
                            supplier_phone=?,
                            supplier_status=?,
                            status=?,
                            start_date=?,
                            end_date=?,
                            payment_period=?,
                            discount_invoice=?,
                            discount_payment=?,
                            discount_quarter=?,
                            discount_invoice_note=?,
                            discount_payment_note=?,
                            discount_quarter_note=?,
                            contract_form_type=?,
                            supplier_contract_file=?,
                            supplier_contract_ref=?,
                            supplier_contract_note=?,
                            notes=?
                        WHERE id=?
                    ");

                    $stmt->bind_param(
                        "ssssssssdddssssssssi",
                        $form['supplier_name'],
                        $form['company_name'],
                        $form['supplier_phone'],
                        $form['supplier_status'],
                        $form['status'],
                        $form['start_date'],
                        $form['end_date'],
                        $form['payment_period'],
                        $form['discount_invoice'],
                        $form['discount_payment'],
                        $form['discount_quarter'],
                        $form['discount_invoice_note'],
                        $form['discount_payment_note'],
                        $form['discount_quarter_note'],
                        $form['contract_form_type'],
                        $form['supplier_contract_file'],
                        $form['supplier_contract_ref'],
                        $form['supplier_contract_note'],
                        $form['notes'],
                        $id
                    );

                }else{

                    $stmt = $conn->prepare("
                        UPDATE contracts SET
                            supplier_name=?,
                            company_name=?,
                            supplier_phone=?,
                            supplier_status=?,
                            status=?,
                            start_date=?,
                            end_date=?,
                            payment_period=?,
                            discount_invoice=?,
                            discount_payment=?,
                            discount_quarter=?,
                            discount_invoice_note=?,
                            discount_payment_note=?,
                            discount_quarter_note=?,
                            contract_form_type=?,
                            supplier_contract_file=?,
                            supplier_contract_ref=?,
                            supplier_contract_note=?,
                            notes=?
                        WHERE id=? AND created_by=?
                    ");

                    $stmt->bind_param(
                        "ssssssssdddssssssssii",
                        $form['supplier_name'],
                        $form['company_name'],
                        $form['supplier_phone'],
                        $form['supplier_status'],
                        $form['status'],
                        $form['start_date'],
                        $form['end_date'],
                        $form['payment_period'],
                        $form['discount_invoice'],
                        $form['discount_payment'],
                        $form['discount_quarter'],
                        $form['discount_invoice_note'],
                        $form['discount_payment_note'],
                        $form['discount_quarter_note'],
                        $form['contract_form_type'],
                        $form['supplier_contract_file'],
                        $form['supplier_contract_ref'],
                        $form['supplier_contract_note'],
                        $form['notes'],
                        $id,
                        $uid
                    );
                }

                $stmt->execute();
                $stmt->close();

                $contract_id = $id;

                $fields = [
                    'supplier_name',
                    'company_name',
                    'supplier_phone',
                    'supplier_status',
                    'status',
                    'start_date',
                    'end_date',
                    'payment_period',
                    'discount_invoice',
                    'discount_payment',
                    'discount_quarter',
                    'notes',
                    'contract_form_type',
                    'supplier_contract_file',
                    'supplier_contract_ref',
                    'supplier_contract_note'
                ];

                foreach($fields as $f){

                    $old_val = $old[$f] ?? '';
                    $new_val = $form[$f] ?? '';
                    $field_name = $f;

                    if(empty($old_val) && !empty($new_val)){
                        $field_name = "إضافة " . $f;
                    }elseif(!empty($old_val) && empty($new_val)){
                        $field_name = "حذف " . $f;
                    }

                    if(trim((string)$old_val) !== trim((string)$new_val)){

                        $stmt_h = $conn->prepare("
                            INSERT INTO contract_history 
                            (contract_id, user_id, field_name, old_value, new_value, created_at)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");

                        $created_at = date('Y-m-d H:i:s');

                        $stmt_h->bind_param(
                            "iissss",
                            $contract_id,
                            $uid,
                            $field_name,
                            $old_val,
                            $new_val,
                            $created_at
                        );

                        $stmt_h->execute();
                        $stmt_h->close();
                    }
                }

                $edit_note = $_POST['edit_note'] ?? '';
                $edit_now = date('Y-m-d H:i:s');

                $stmtEdit = $conn->prepare("
                    UPDATE contracts SET 
                        last_edited_by=?,
                        last_edited_at=?,
                        edit_note=?
                    WHERE id=?
                ");
                $stmtEdit->bind_param("issi", $uid, $edit_now, $edit_note, $contract_id);
                $stmtEdit->execute();
                $stmtEdit->close();

                $old_rents = $conn->query("SELECT * FROM rents WHERE contract_id=$id")->fetch_all(MYSQLI_ASSOC);
                $old_annual = $conn->query("SELECT * FROM annual_discounts WHERE contract_id=$id")->fetch_all(MYSQLI_ASSOC);
                $old_events = $conn->query("SELECT * FROM events WHERE contract_id=$id")->fetch_all(MYSQLI_ASSOC);

                $new_fee = $_POST['new_item_fee'] ?? '';
                $old_fee = '';

                foreach($old_events as $ev){
                    if(($ev['type'] ?? '') == 'new_item'){
                        $old_fee = $ev['value'];
                        break;
                    }
                }

                logHistoryIfChanged(
                    $conn,
                    $contract_id,
                    $uid,
                    'رسوم صنف جديد',
                    $old_fee !== '' ? ('رسوم صنف جديد (' . historyMoneyText($old_fee) . ' ريال)') : '-',
                    $new_fee !== '' ? ('رسوم صنف جديد (' . historyMoneyText($new_fee) . ' ريال)') : '-'
                );

                $conn->query("DELETE FROM rents WHERE contract_id=$contract_id");
                $conn->query("DELETE FROM annual_discounts WHERE contract_id=$contract_id");
                $conn->query("DELETE FROM events WHERE contract_id=$contract_id");

            } else {

                $stmt = $conn->prepare("
                    INSERT INTO contracts (
                        supplier_name,
                        company_name,
                        supplier_phone,
                        supplier_status,
                        status,
                        start_date,
                        end_date,
                        payment_period,  
                        discount_invoice,
                        discount_payment,
                        discount_quarter,
                        discount_invoice_note,
                        discount_payment_note,
                        discount_quarter_note,
                        contract_form_type,
                        supplier_contract_file,
                        supplier_contract_ref,
                        supplier_contract_note,
                        notes,
                        created_by,
                        source
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $source = 'contract';

                $stmt->bind_param(
                    "ssssssssdddssssssssis",
                    $form['supplier_name'],
                    $form['company_name'],
                    $form['supplier_phone'],
                    $form['supplier_status'],
                    $form['status'],
                    $form['start_date'],
                    $form['end_date'],
                    $form['payment_period'],
                    $form['discount_invoice'],
                    $form['discount_payment'],
                    $form['discount_quarter'],
                    $form['discount_invoice_note'],
                    $form['discount_payment_note'],
                    $form['discount_quarter_note'],
                    $form['contract_form_type'],
                    $form['supplier_contract_file'],
                    $form['supplier_contract_ref'],
                    $form['supplier_contract_note'],
                    $form['notes'],
                    $uid,
                    $source
                );

                $stmt->execute();
                $contract_id = $conn->insert_id;
                $stmt->close();
            }

            $_SESSION['success_id'] = $contract_id;
            $show_view_button = true;

            $contractLink = "view_contract.php?id=" . (int)$contract_id;
            $contractSupplierForNotify = $form['supplier_name'] !== '' ? $form['supplier_name'] : $oldSupplierBeforeSave;

            
            if ($isEditingContract) {
                if ($is_admin && $oldOwnerId > 0 && $oldOwnerId !== $uid) {
                    if ($oldStatusBeforeSave !== $form['status'] && $form['status'] === 'approved') {
                        vcDisabledUserHook(
                            $conn,
                            $oldOwnerId,
                            'تمت الموافقة على عقدك',
                            'تمت الموافقة على العقد رقم #' . (int)$contract_id . ' للمورد: ' . $contractSupplierForNotify,
                            $contractLink,
                            'contract_approved',
                            (int)$contract_id
                        );
                    } else {
                        vcDisabledUserHook(
                            $conn,
                            $oldOwnerId,
                            'تم تعديل عقدك من الإدارة',
                            'تم تعديل العقد رقم #' . (int)$contract_id . ' للمورد: ' . $contractSupplierForNotify,
                            $contractLink,
                            'contract_admin_edit',
                            (int)$contract_id
                        );
                    }
                }

                if (!$is_admin && $form['status'] === 'review' && $oldStatusBeforeSave !== 'review') {
                    vcDisabledManagerHook(
                        $conn,
                        (int)$uid,
                        'تم إرسال عقد للمراجعة',
                        'تم إرسال عقد للمراجعة من ' . $currentUsernameForNotif . ' رقم #' . (int)$uid . ' للمورد: ' . $contractSupplierForNotify,
                        $contractLink,
                        'contract_sent_review',
                        (int)$contract_id,
                        (int)$uid
                    );
                }
            } else {
                if (!$is_admin && $form['status'] === 'review') {
                    vcDisabledManagerHook(
                        $conn,
                        (int)$uid,
                        'تم إرسال عقد للمراجعة',
                        'تم إرسال عقد للمراجعة من ' . $currentUsernameForNotif . ' رقم #' . (int)$uid . ' للمورد: ' . $contractSupplierForNotify,
                        $contractLink,
                        'contract_sent_review',
                        (int)$contract_id,
                        (int)$uid
                    );
                }
            }

            
$branches = $_POST['rent_branch'] ?? [];
$types    = $_POST['rent_type'] ?? [];
$qtys     = $_POST['rent_qty'] ?? [];
$prices   = $_POST['rent_price'] ?? [];
$froms    = $_POST['rent_from'] ?? [];
$tos      = $_POST['rent_to'] ?? [];
$totals   = $_POST['rent_total'] ?? [];

$rowsCount = max(
    count($branches),
    count($types),
    count($qtys),
    count($prices),
    count($froms),
    count($tos),
    count($totals)
);

$hasAnyRent = false;

for ($i = 0; $i < $rowsCount; $i++) {

    $branch = trim((string)($branches[$i] ?? ''));
    $type   = trim((string)($types[$i] ?? ''));

    $qtyRaw   = trim((string)($qtys[$i] ?? ''));
    $priceRaw = trim((string)($prices[$i] ?? ''));
    $totalRaw = trim((string)($totals[$i] ?? ''));

    $qty   = ($qtyRaw !== '' && is_numeric($qtyRaw)) ? (float)$qtyRaw : 0;
    $price = ($priceRaw !== '' && is_numeric($priceRaw)) ? (float)$priceRaw : 0;
    $total = ($totalRaw !== '' && is_numeric($totalRaw)) ? (float)$totalRaw : 0;

    $fromRaw = trim((string)($froms[$i] ?? ''));
    $toRaw   = trim((string)($tos[$i] ?? ''));

    $from = ($fromRaw !== '') ? $fromRaw : null;
    $to   = ($toRaw !== '') ? $toRaw : null;

    if (
        $branch === '' &&
        $type === '' &&
        $qty <= 0 &&
        $price <= 0 &&
        $from === null &&
        $to === null &&
        $total <= 0
    ) {
        continue;
    }

    if ($branch === '' || $type === '') {
        throw new Exception("فيه صف إيجار ناقص: لازم تختار الفرع ونوع الإيجار.");
    }

    if ($qty <= 0) {
        throw new Exception("فيه صف إيجار ناقص: العدد لازم يكون أكبر من صفر.");
    }

    if ($price <= 0) {
        throw new Exception("فيه صف إيجار ناقص: السعر الشهري لازم يكون أكبر من صفر.");
    }

    if ($from !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        throw new Exception("تاريخ بداية الإيجار غير صحيح.");
    }

    if ($to !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        throw new Exception("تاريخ نهاية الإيجار غير صحيح.");
    }

    if ($total <= 0) {
        $months = 1;

        if ($from !== null && $to !== null) {
            try {
                $d1 = new DateTime($from);
                $d2 = new DateTime($to);

                $months = (($d2->format('Y') - $d1->format('Y')) * 12)
                        + ($d2->format('m') - $d1->format('m'))
                        + 1;

                if ($months < 1) {
                    $months = 1;
                }
            } catch (Throwable $e) {
                $months = 1;
            }
        }

        $total = $qty * $price * $months;
    }

    $stmtRent = $conn->prepare("
        INSERT INTO rents
            (contract_id, branch, type, qty, price, start_date, end_date, total)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmtRent) {
        throw new Exception("فشل تجهيز حفظ الإيجارات: " . $conn->error);
    }

    $stmtRent->bind_param(
        "issddssd",
        $contract_id,
        $branch,
        $type,
        $qty,
        $price,
        $from,
        $to,
        $total
    );

    if (!$stmtRent->execute()) {
        throw new Exception("فشل حفظ الإيجار: " . $stmtRent->error);
    }

    $stmtRent->close();

    $hasAnyRent = true;
}

            
            foreach ($form['annual_discount_percents'] as $i => $percent) {

                $target = $form['annual_discount_targets'][$i] ?? '';

                if($percent !== ''){
                    $stmt2 = $conn->prepare("
                        INSERT INTO annual_discounts (contract_id, percent, target)
                        VALUES (?, ?, ?)
                    ");
                    $stmt2->bind_param("ids", $contract_id, $percent, $target);
                    $stmt2->execute();
                    $stmt2->close();
                }
            }

            
            $typesMap = [
                "رسوم صورة المجلة"     => "magazine",
                "رسوم الصفحة الكاملة"  => "fullpage",
                "رسوم افتتاح فرع جديد" => "opening",
                "عيد الأضحى"           => "adha",
                "عيد الفطر"            => "fitr",
                "يوم التأسيس"          => "foundation",
                "اليوم الوطني"         => "national",
                "رمضان"                => "ramadan",
                "العودة للمدارس"       => "school"
            ];

            if(!empty($_POST['new_item_fee'])){

                $stmt_new = $conn->prepare("
                    INSERT INTO events (contract_id, type, name, value)
                    VALUES (?, 'new_item', 'رسوم صنف جديد', ?)
                ");

                $stmt_new->bind_param("id", $contract_id, $_POST['new_item_fee']);
                $stmt_new->execute();
                $stmt_new->close();
            }

            foreach ($form['event_values'] as $i => $value) {

                $name = trim($form['event_names'][$i] ?? '');
                $note = trim($_POST['event_note'][$i] ?? '');

                if($value == '' || $name == '') continue;

                $type = $typesMap[$name] ?? "custom";

                $stmt3 = $conn->prepare("
                    INSERT INTO events (contract_id, type, name, value, note)
                    VALUES (?, ?, ?, ?, ?)
                ");

                $stmt3->bind_param("issds", $contract_id, $type, $name, $value, $note);
                $stmt3->execute();
                $stmt3->close();
            }

            
            if(isset($_GET['id'])){

                
                $oldRentMap = [];
                foreach($old_rents as $oldRentRow){
                    $key = rentHistoryKey($oldRentRow);
                    if($key !== 'empty'){
                        $oldRentMap[$key] = $oldRentRow;
                    }
                }

                $newRentMap = [];
                if(isset($_POST['rent_branch'])){
                    foreach($_POST['rent_branch'] as $i => $branch){
                        $branch = trim((string)$branch);
                        $type   = trim((string)($_POST['rent_type'][$i] ?? ''));
                        $qty    = trim((string)($_POST['rent_qty'][$i] ?? ''));
                        $price  = trim((string)($_POST['rent_price'][$i] ?? ''));
                        $from   = trim((string)($_POST['rent_from'][$i] ?? ''));
                        $to     = trim((string)($_POST['rent_to'][$i] ?? ''));

                        if($branch === '' && $type === '' && $qty === ''){
                            continue;
                        }

                        $row = [
                            'branch' => $branch,
                            'type' => $type,
                            'qty' => $qty,
                            'price' => $price,
                            'start_date' => $from,
                            'end_date' => $to
                        ];

                        $key = rentHistoryKey($row);
                        if($key !== 'empty'){
                            $newRentMap[$key] = $row;
                        }
                    }
                }

                foreach(array_unique(array_merge(array_keys($oldRentMap), array_keys($newRentMap))) as $key){
                    $old_txt = isset($oldRentMap[$key]) ? rentHistoryText($oldRentMap[$key]) : '-';
                    $new_txt = isset($newRentMap[$key]) ? rentHistoryText($newRentMap[$key]) : '-';

                    logHistoryIfChanged($conn, $contract_id, $uid, 'الإيجارات', $old_txt, $new_txt);
                }

                
                $oldAnnualMap = [];
                foreach($old_annual as $oldAnnualRow){
                    $key = annualHistoryKey($oldAnnualRow);
                    if($key !== 'empty'){
                        $oldAnnualMap[$key] = $oldAnnualRow;
                    }
                }

                $newAnnualMap = [];
                if(isset($_POST['annual_discount_percent'])){
                    foreach($_POST['annual_discount_percent'] as $i => $percent){
                        $percent = trim((string)$percent);
                        $target  = trim((string)($_POST['annual_discount_target'][$i] ?? ''));

                        if($percent === '' && $target === ''){
                            continue;
                        }

                        $row = [
                            'percent' => $percent,
                            'target' => $target
                        ];

                        $key = annualHistoryKey($row);
                        if($key !== 'empty'){
                            $newAnnualMap[$key] = $row;
                        }
                    }
                }

                foreach(array_unique(array_merge(array_keys($oldAnnualMap), array_keys($newAnnualMap))) as $key){
                    $old_txt = isset($oldAnnualMap[$key]) ? annualHistoryText($oldAnnualMap[$key]) : '-';
                    $new_txt = isset($newAnnualMap[$key]) ? annualHistoryText($newAnnualMap[$key]) : '-';

                    logHistoryIfChanged($conn, $contract_id, $uid, 'الخصم السنوي', $old_txt, $new_txt);
                }

                
                $oldEventMap = [];
                foreach($old_events as $oldEventRow){
                    if(($oldEventRow['type'] ?? '') === 'new_item' || ($oldEventRow['name'] ?? '') === 'رسوم صنف جديد'){
                        continue;
                    }

                    $key = eventHistoryKey($oldEventRow);
                    if($key !== 'empty'){
                        $oldEventMap[$key] = $oldEventRow;
                    }
                }

                $newEventMap = [];
                if(isset($_POST['event_name'])){
                    foreach($_POST['event_name'] as $i => $name){
                        $name  = trim((string)$name);
                        $value = trim((string)($_POST['event_value'][$i] ?? ''));

                        if($name === '' && $value === ''){
                            continue;
                        }

                        $type = $typesMap[$name] ?? "custom";

                        $row = [
                            'type' => $type,
                            'name' => $name,
                            'value' => $value
                        ];

                        $key = eventHistoryKey($row);
                        if($key !== 'empty'){
                            $newEventMap[$key] = $row;
                        }
                    }
                }

                foreach(array_unique(array_merge(array_keys($oldEventMap), array_keys($newEventMap))) as $key){
                    $old_txt = isset($oldEventMap[$key]) ? eventHistoryText($oldEventMap[$key]) : '-';
                    $new_txt = isset($newEventMap[$key]) ? eventHistoryText($newEventMap[$key]) : '-';

                    logHistoryIfChanged($conn, $contract_id, $uid, 'الفعاليات', $old_txt, $new_txt);
                }
            }

            
header("Location: add_contract.php?success=1");
            exit();

        } catch (Throwable $e) {
            die("ERROR: " . $e->getMessage());
        }
    }
}

$status_tafawod_checked = ($form['status'] === 'تفاوض' || $form['status'] === 'draft') ? 'checked' : '';
$status_final_checked   = ($form['status'] === 'نهائي' || in_array($form['status'], ['review','approved'], true)) ? 'checked' : '';

$supplier_new_checked        = ($form['supplier_status'] === 'new' || $form['supplier_status'] === '') ? 'checked' : '';
$supplier_registered_checked = ($form['supplier_status'] === 'registered') ? 'checked' : '';


$success_id = $_SESSION['success_id'] ?? null;
unset($_SESSION['success_id']);
?>

<!DOCTYPE html>

<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إضافة عقد</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<?php vcRenderPageAssets(['forms' => true]); ?>

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
    <h1 class="page-title"><?= isset($_GET['id']) ? 'تعديل عقد' : 'إضافة عقد جديد' ?></h1>
    <p class="page-subtitle">املأ البيانات الأساسية والبنود التجارية والتسويقية، وبعدها احفظ العقد.</p>
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

<form method="POST" enctype="multipart/form-data" autocomplete="off" onsubmit="return confirmSupplierDuplicateBeforeSubmit();" id="contractForm">
    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="supplier_name" id="supplier_name" value="<?= e($form['supplier_name']) ?>">

<div class="contract-page-layout">

    <nav class="contract-tabs" aria-label="أقسام النموذج">
        <div class="contract-tabs-head">
            <p class="contract-tabs-title">خطوات إضافة العقد</p>
            <span class="contract-tab-progress" id="contractTabProgress">الخطوة 1 من 6</span>
        </div>
        <div class="contract-tabs-inner" role="tablist">
            <button type="button" class="contract-tab is-active" role="tab" data-target="sec-supplier" aria-selected="true"><span>1</span> المورد</button>
            <button type="button" class="contract-tab" role="tab" data-target="sec-commercial" aria-selected="false"><span>2</span> تجارية</button>
            <button type="button" class="contract-tab" role="tab" data-target="sec-marketing" aria-selected="false"><span>3</span> تسويقية</button>
            <button type="button" class="contract-tab" role="tab" data-target="sec-rent" aria-selected="false"><span>4</span> إيجارية</button>
            <button type="button" class="contract-tab" role="tab" data-target="sec-contract-file" aria-selected="false"><span>5</span> النموذج</button>
            <button type="button" class="contract-tab" role="tab" data-target="sec-notes" aria-selected="false"><span>6</span> ملاحظات</button>
        </div>
    </nav>

    <div class="contract-form-main">

    <section class="form-panel contract-tab-panel is-active" id="sec-supplier" role="tabpanel">
        <div class="section-title"><span class="step-num">1</span> المورد والبيانات الأساسية</div>

        <div class="field">
            <div class="option-title">حالة المورد</div>
            <div class="supplier-status">
                <label class="status-card">
                    <input type="radio" name="supplier_status" value="registered" <?= $supplier_registered_checked ?>>
                    <div class="card">
                        <span class="icon">🏢</span>
                        <div>
                            <strong>مورد مسجل</strong>
                            <small>اختار مورد موجود بالفعل</small>
                        </div>
                    </div>
                </label>
                <label class="status-card">
                    <input type="radio" name="supplier_status" value="new" <?= $supplier_new_checked ?>>
                    <div class="card">
                        <span class="icon">➕</span>
                        <div>
                            <strong>مورد جديد</strong>
                            <small>إضافة مورد جديد للنظام</small>
                        </div>
                    </div>
                </label>
            </div>
        </div>

        <div class="basic-info-grid">
            <div class="field supplier-field">
                <div id="supplier_name_box">
                    <label>اسم المورد</label>
                    <input type="text" id="new_supplier_name" value="<?= e($form['supplier_name']) ?>" placeholder="اكتب اسم المورد">
                </div>
                <div id="supplier_search_box" style="display:none;">
                    <label>بحث عن المورد</label>
                    <input type="text" id="supplier_search" placeholder="اكتب اسم المورد..." value="<?= e($form['supplier_name']) ?>">
                    <div id="results"></div>
                </div>
                <div id="supplierDuplicateWarning" class="supplier-warning-box"></div>
                <input type="hidden" id="supplier_duplicate_found" value="0">
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

        <div class="panel-divider"></div>
        <div class="panel-subtitle">تفاصيل العقد</div>

        <div class="contract-meta-grid">
            <div class="field field--full">
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

            <div class="field">
                <label for="start_date">تاريخ البداية</label>
                <div class="date-field">
                    <input type="text" id="start_date" name="start_date" value="<?= e($form['start_date']) ?>" placeholder="اختر تاريخ البداية">
                    <span class="date-icon">📅</span>
                </div>
            </div>

            <div class="field">
                <label for="end_date">تاريخ النهاية</label>
                <div class="date-field">
                    <input type="text" id="end_date" name="end_date" value="<?= e($form['end_date']) ?>" placeholder="اختر تاريخ النهاية">
                    <span class="date-icon">📅</span>
                </div>
            </div>

            <div class="field field--full">
                <label>فترة السداد <span style="color:#ef4444">*</span></label>
                <div class="currency-field">
                    <span>يوم</span>
                    <input type="number" name="payment_period" value="<?= e($form['payment_period'] ?? '') ?>" placeholder="مثلاً 30" min="1" required>
                </div>
            </div>
        </div>
    </section>

    <section class="form-panel contract-tab-panel" id="sec-commercial" role="tabpanel">
        <div class="section-title"><span class="step-num">2</span> البنود التجارية</div>

        <div class="discounts-grid">
    <div class="box">
        <div class="box-title">خصم الفاتورة</div>
        <div class="discount">
            <div class="percent">
                <input type="number" step="0.01" min="0" max="100" name="discount_invoice" value="<?= e($form['discount_invoice']) ?>" placeholder="النسبة">
            </div>
            <input type="text" name="discount_invoice_note" value="<?= e($form['discount_invoice_note']) ?>" placeholder="ملاحظات">
        </div>
    </div>

    <div class="box">
        <div class="box-title">خصم السداد</div>
        <div class="discount">
            <div class="percent">
                <input type="number" step="0.01" min="0" max="100" name="discount_payment" value="<?= e($form['discount_payment']) ?>" placeholder="النسبة">
            </div>
            <input type="text" name="discount_payment_note" value="<?= e($form['discount_payment_note']) ?>" placeholder="ملاحظات">
        </div>
    </div>

    <div class="box">
        <div class="box-title">خصم كل 3 شهور</div>
        <div class="discount">
            <div class="percent">
                <input type="number" step="0.01" min="0" max="100" name="discount_quarter" value="<?= e($form['discount_quarter']) ?>" placeholder="النسبة">
            </div>
            <input type="text" name="discount_quarter_note" value="<?= e($form['discount_quarter_note']) ?>" placeholder="ملاحظات">
        </div>
    </div>

    <div class="box box--wide">
        <div class="box-title">خصم سنوي</div>

        <div class="table-wrap">
            <table class="table" id="yearTable">
                <thead>
                    <tr>
                        <th style="width:150px;">النسبة %</th>
                        <th>الهدف</th>
                        <th class="row-actions">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($form['annual_discount_percents'] as $i => $percent): ?>
                        <tr>
                            <td class="percent-cell">
                                <input type="number" step="0.01" min="0" max="100" name="annual_discount_percent[]" value="<?= e($percent) ?>" placeholder="النسبة">
                            </td>

                            <td>
                                <input type="text" name="annual_discount_target[]" value="<?= e($form['annual_discount_targets'][$i] ?? '') ?>" placeholder="اكتب الهدف">
                            </td>

                            <td class="row-actions">
                                <button type="button" class="icon-btn remove-btn" onclick="safeRemoveRow(this)">
                                    حذف
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <button type="button" class="icon-btn add-btn" onclick="addYearRow()">+ إضافة صف</button>
    </div>
        </div>
    </section>

    <section class="form-panel contract-tab-panel" id="sec-marketing" role="tabpanel">
        <div class="section-title"><span class="step-num">3</span> البنود التسويقية</div>

    <div class="discounts-grid">
    <div class="box">
        <div class="box-title">رسوم صنف جديد</div>

        <div class="currency-field">
            <span>ريال</span>
            <input type="number" step="0.01" min="0" name="new_item_fee" value="<?= e($form['new_item_fee'] ?? '') ?>" placeholder="القيمة">
        </div>
    </div>

    <div class="box box--wide">
        <div class="box-title">رسوم مهرجان / فعالية</div>

        <div class="table-wrap">
            <table class="table" id="eventTable">
                <thead>
                    <tr>
                        <th style="width:180px;">القيمة</th>
                        <th>المهرجان / الفعالية</th>
                        <th class="row-actions">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($form['event_values'] as $i => $value): ?>
                        <tr>
                            <td>
                                <div class="currency-field">
                                    <span>ريال</span>
                                    <input type="number" step="0.01" min="0" name="event_value[]" value="<?= e($value) ?>" placeholder="القيمة">
                                </div>
                            </td>

                            <td>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                    <select name="event_name[]">
                                        <option value="">اختار</option>
                                        <option <?= ($form['event_names'][$i] ?? '')=='رسوم صورة المجلة'?'selected':'' ?>>رسوم صورة المجلة</option>
                                        <option <?= ($form['event_names'][$i] ?? '')=='رسوم الصفحة الكاملة'?'selected':'' ?>>رسوم الصفحة الكاملة</option>
                                        <option <?= ($form['event_names'][$i] ?? '')=='رسوم افتتاح فرع جديد'?'selected':'' ?>>رسوم افتتاح فرع جديد</option>
                                        <option <?= ($form['event_names'][$i] ?? '')=='عيد الأضحى'?'selected':'' ?>>عيد الأضحى</option>
                                        <option <?= ($form['event_names'][$i] ?? '')=='عيد الفطر'?'selected':'' ?>>عيد الفطر</option>
                                        <option <?= ($form['event_names'][$i] ?? '')=='يوم التأسيس'?'selected':'' ?>>يوم التأسيس</option>
                                        <option <?= ($form['event_names'][$i] ?? '')=='اليوم الوطني'?'selected':'' ?>>اليوم الوطني</option>
                                        <option <?= ($form['event_names'][$i] ?? '')=='رمضان'?'selected':'' ?>>رمضان</option>
                                        <option <?= ($form['event_names'][$i] ?? '')=='العودة للمدارس'?'selected':'' ?>>العودة للمدارس</option>
                                    </select>

                                    <input type="text" name="event_note[]" value="<?= e($form['event_notes'][$i] ?? '') ?>" placeholder="ملاحظة">
                                </div>
                            </td>

                            <td class="row-actions">
                                <button type="button" class="icon-btn remove-btn" onclick="safeRemoveRow(this)">
                                    حذف
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <button type="button" class="icon-btn add-btn" onclick="addEventRow()">+ إضافة صف</button>
    </div>
    </div>
    </section>

    <section class="form-panel contract-tab-panel" id="sec-rent" role="tabpanel">
        <div class="section-title"><span class="step-num">4</span> البنود الإيجارية</div>

    <div class="box">

        <div class="table-wrap">
            <table class="table" id="rentTable">

                <thead>
                    <tr>
                        <th>الفرع</th>
                        <th>الإيجار</th>
                        <th style="width:120px;">العدد</th>
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
                                        <option <?= $r['branch']=='سكاى مول'?'selected':'' ?>>سكاى مول</option>
                                        <option <?= $r['branch']=='المنصورة'?'selected':'' ?>>المنصورة</option>
                                        <option <?= $r['branch']=='النسيم'?'selected':'' ?>>النسيم</option>
                                        <option <?= $r['branch']=='الواحة'?'selected':'' ?>>الواحة</option>
                                        <option <?= $r['branch']=='بريدة'?'selected':'' ?>>بريدة</option>
                                        <option <?= $r['branch']=='حائل'?'selected':'' ?>>حائل</option>
                                        <option <?= $r['branch']=='خميس مشيط'?'selected':'' ?>>خميس مشيط</option>
                                    </select>
                                </td>

                                <td>
                                    <select name="rent_type[]">
                                        <option value="">اختار</option>
                                        <option <?= $r['type']=='جندولة'?'selected':'' ?>>جندولة</option>
                                        <option <?= $r['type']=='عرض أرضي'?'selected':'' ?>>عرض أرضي</option>
                                    </select>
                                </td>

                                <td>
                                    <input type="number" name="rent_qty[]" value="<?= e($r['qty']) ?>" oninput="calcRow(this)" placeholder="0">
                                </td>

                                <td>
                                    <div class="currency-field">
                                        <span>ريال</span>
                                        <input type="number" name="rent_price[]" value="<?= e((intval($r['price']) == $r['price']) ? intval($r['price']) : $r['price']) ?>" oninput="calcRow(this)" placeholder="0">
                                    </div>
                                </td>

                                <td><input type="date" name="rent_from[]" value="<?= e($r['start_date']) ?>" onchange="calcRow(this)"></td>
                                <td><input type="date" name="rent_to[]" value="<?= e($r['end_date']) ?>" onchange="calcRow(this)"></td>

                                <td>
                                    <input type="text" name="rent_total[]" value="<?= e((intval($r['total']) == $r['total']) ? intval($r['total']) : $r['total']) ?>" style="text-align:center;font-weight:900;" readonly>
                                </td>

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

                            <td>
                                <input type="number" name="rent_qty[]" oninput="calcRow(this)" placeholder="0">
                            </td>

                            <td>
                                <div class="currency-field">
                                    <span>ريال</span>
                                    <input type="number" name="rent_price[]" oninput="calcRow(this)" placeholder="0">
                                </div>
                            </td>

                            <td><input type="date" name="rent_from[]" onchange="calcRow(this)"></td>
                            <td><input type="date" name="rent_to[]" onchange="calcRow(this)"></td>

                            <td>
                                <input type="text" name="rent_total[]" style="text-align:center;font-weight:900;" readonly>
                            </td>

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
    </section>

    <section class="form-panel contract-tab-panel" id="sec-contract-file" role="tabpanel">
        <div class="section-title"><span class="step-num">5</span> نموذج العقد الرسمي</div>

    <div class="supplier-contract-mode">
        <div class="mode-options">
            <label class="mode-option">
                <input type="radio" name="contract_form_type" value="system" <?= ($form['contract_form_type'] ?? 'system') !== 'supplier' ? 'checked' : '' ?> onchange="toggleSupplierContractBox()">
                <span>نموذج VendorCore</span>
            </label>

            <label class="mode-option">
                <input type="radio" name="contract_form_type" value="supplier" <?= ($form['contract_form_type'] ?? 'system') === 'supplier' ? 'checked' : '' ?> onchange="toggleSupplierContractBox()">
                <span>نموذج مورد خارجي</span>
            </label>
        </div>

        <div id="supplierContractExtra" class="supplier-contract-extra">
            <input type="hidden" name="current_supplier_contract_file" value="<?= e($form['supplier_contract_file'] ?? '') ?>">

            <div class="field">
                <label>ملف عقد المورد الرسمي</label>
                <input type="file" name="supplier_contract_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp">
                <?php if(!empty($form['supplier_contract_file'])): ?>
                    <a class="current-file-link" target="_blank" href="<?= e($form['supplier_contract_file']) ?>">عرض الملف الحالي</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </section>

    <section class="form-panel contract-tab-panel" id="sec-notes" role="tabpanel">
        <div class="section-title"><span class="step-num">6</span> ملاحظات أخرى</div>

    <div class="field">
        <label>ملاحظات إضافية</label>
        <textarea name="notes" placeholder="ملاحظات إضافية"><?= e($form['notes']) ?></textarea>
    </div>

    <?php if($is_admin): ?>
        <div class="field">
            <label>سبب التعديل (للإدارة فقط)</label>
            <textarea name="edit_note" placeholder="اكتب سبب التعديل..."></textarea>
        </div>
    <?php endif; ?>
    </section>

    <div class="form-actions-bar">
        <button type="button" class="btn-tab-nav" id="contractTabPrev" disabled>السابق</button>
        <button type="button" class="btn-tab-nav" id="contractTabNext">التالي</button>
        <button type="submit" class="submit" id="contractTabSubmit" hidden>حفظ العقد</button>
    </div>

    </div>
</div>
</form>

</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ar.js"></script>

<script>

function initContractTabs(){
    const tabs = Array.from(document.querySelectorAll(".contract-tab[data-target]"));
    const prevBtn = document.getElementById("contractTabPrev");
    const nextBtn = document.getElementById("contractTabNext");
    const submitBtn = document.getElementById("contractTabSubmit");
    const progress = document.getElementById("contractTabProgress");

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

        document.querySelectorAll(".contract-tab-panel").forEach(function(panel){
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

document.addEventListener("DOMContentLoaded", initContractTabs);

function toggleSupplierContractBox(){
    const checked = document.querySelector('input[name="contract_form_type"]:checked');
    const box = document.getElementById('supplierContractExtra');
    if(!box || !checked){return;}
    box.style.display = checked.value === 'supplier' ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', toggleSupplierContractBox);

document.addEventListener("DOMContentLoaded", function(){

    const startInput = document.getElementById("start_date");
    const endInput   = document.getElementById("end_date");

    let startPicker = null;
    let endPicker = null;

    if (typeof flatpickr !== "undefined" && startInput && endInput) {

        endPicker = flatpickr(endInput, {
            locale: flatpickr.l10ns.ar,
            dateFormat: "Y-m-d",
            altInput: false,
            allowInput: false,
            disableMobile: true,
            monthSelectorType: "static",
            position: "auto right",
            onChange: function(selectedDates){
                if(startPicker){
                    if(selectedDates.length){
                        startPicker.set('maxDate', selectedDates[0]);
                    }else{
                        startPicker.set('maxDate', null);
                    }
                }
            }
        });

        startPicker = flatpickr(startInput, {
            locale: flatpickr.l10ns.ar,
            dateFormat: "Y-m-d",
            altInput: false,
            allowInput: false,
            disableMobile: true,
            monthSelectorType: "static",
            position: "auto right",
            onChange: function(selectedDates){
                if(endPicker){
                    if(selectedDates.length){
                        endPicker.set('minDate', selectedDates[0]);
                    }else{
                        endPicker.set('minDate', null);
                    }
                }
            }
        });

        if(startInput.value){
            endPicker.set('minDate', startInput.value);
        }

        if(endInput.value){
            startPicker.set('maxDate', endInput.value);
        }
    }

    setupSupplierStatus();
    calcGrand();
    buildSummary();

    setTimeout(function(){
        var box = document.getElementById("successBox");
        if(box){
            box.style.display = "none";
        }
    }, 8000);
});

function addYearRow() {
    const tbody = document.querySelector("#yearTable tbody");

    const row = document.createElement("tr");

    row.innerHTML = `
        <td class="percent-cell">
            <input type="number" step="0.01" min="0" max="100" name="annual_discount_percent[]" placeholder="النسبة">
        </td>
        <td>
            <input type="text" name="annual_discount_target[]" placeholder="اكتب الهدف">
        </td>
        <td class="row-actions">
            <button type="button" class="icon-btn remove-btn" onclick="safeRemoveRow(this)">
                حذف
            </button>
        </td>
    `;

    tbody.appendChild(row);
}

function addEventRow() {

    let tbody = document.querySelector("#eventTable tbody");

    let row = document.createElement("tr");

    row.innerHTML = `
        <td>
            <div class="currency-field">
                <span>ريال</span>
                <input type="number" step="0.01" min="0" name="event_value[]" placeholder="القيمة">
            </div>
        </td>

        <td>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <select name="event_name[]">
                    <option value="">اختار</option>
                    <option>رسوم صورة المجلة</option>
                    <option>رسوم الصفحة الكاملة</option>
                    <option>رسوم افتتاح فرع جديد</option>
                    <option>عيد الأضحى</option>
                    <option>عيد الفطر</option>
                    <option>يوم التأسيس</option>
                    <option>اليوم الوطني</option>
                    <option>رمضان</option>
                    <option>العودة للمدارس</option>
                </select>

                <input type="text" name="event_note[]" placeholder="ملاحظة">
            </div>
        </td>

        <td class="row-actions">
            <button type="button" class="icon-btn remove-btn" onclick="safeRemoveRow(this)">
                حذف
            </button>
        </td>
    `;

    tbody.appendChild(row);
}

function addRentRow(){
    let table = document.querySelector("#rentTable tbody");
    let firstRow = table.rows[0];
    let row = firstRow.cloneNode(true);

    row.querySelectorAll("input").forEach(i => {
        i.value = "";

        if(i.name === "rent_total[]"){
            i.readOnly = true;
        }

        i.oninput = function(){ calcRow(this); };
        i.onchange = function(){ calcRow(this); };
    });

    row.querySelectorAll("select").forEach(s => s.selectedIndex = 0);

    table.appendChild(row);
    calcGrand();
    buildSummary();
}

function calcRow(el){
    let row = el.closest("tr");

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

    row.querySelector("[name='rent_total[]']").value = parseInt(total);

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
    } else {
        let inputs = row.querySelectorAll("input");
        let selects = row.querySelectorAll("select");

        inputs.forEach(i => i.value = "");
        selects.forEach(s => s.selectedIndex = 0);
    }

    calcGrand();
    buildSummary();
}

function setupSupplierStatus(){

    const radios = document.querySelectorAll('input[name="supplier_status"]');
    const supplierNameBox = document.getElementById("supplier_name_box");
    const supplierSearchBox = document.getElementById("supplier_search_box");
    const newSupplierInput = document.getElementById("new_supplier_name");
    const supplierSearchInput = document.getElementById("supplier_search");
    const supplierHidden = document.getElementById("supplier_name");

    function applyStatus(){
        let status = document.querySelector('input[name="supplier_status"]:checked');

        if(!status){
            return;
        }

        if(status.value === "new"){
            supplierNameBox.style.display = "block";
            supplierSearchBox.style.display = "none";
            supplierHidden.value = newSupplierInput.value;
        }

        if(status.value === "registered"){
            supplierNameBox.style.display = "none";
            supplierSearchBox.style.display = "block";
            supplierHidden.value = supplierSearchInput.value;
        }
    }

    radios.forEach(radio => {
        radio.addEventListener('change', applyStatus);
    });

    if(newSupplierInput){
        newSupplierInput.addEventListener("input", function(){
            let status = document.querySelector('input[name="supplier_status"]:checked');
            if(status && status.value === "new"){
                supplierHidden.value = this.value;
            }
        });
    }

    if(supplierSearchInput){
        supplierSearchInput.addEventListener("input", function(){
            let status = document.querySelector('input[name="supplier_status"]:checked');
            if(status && status.value === "registered"){
                supplierHidden.value = this.value;
            }
        });
    }

    applyStatus();
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

    if(typeof checkSupplierDuplicateNow === "function"){
        setTimeout(checkSupplierDuplicateNow, 100);
    }
}

document.querySelector("form").addEventListener("submit", function(){

    let status = document.querySelector('input[name="supplier_status"]:checked');
    let supplierHidden = document.getElementById("supplier_name");

    if(status && status.value === "new"){
        supplierHidden.value = document.getElementById("new_supplier_name").value;
    }

    if(status && status.value === "registered"){
        supplierHidden.value = document.getElementById("supplier_search").value;
    }

});



let supplierDuplicateTimer = null;
let supplierDuplicateMatches = [];

function escapeSupplierHtml(str){
    return String(str ?? "").replace(/[&<>"']/g, function(m){
        return ({
            '&':'&amp;',
            '<':'&lt;',
            '>':'&gt;',
            '"':'&quot;',
            "'":'&#039;'
        })[m];
    });
}

function getSupplierMode(){
    const checked = document.querySelector("input[name='supplier_status']:checked");
    return checked ? checked.value : "";
}

function isNewSupplierMode(){
    return getSupplierMode() === "new";
}

function isRegisteredSupplierMode(){
    return getSupplierMode() === "registered";
}

function getCurrentSupplierNameForCheck(){
    const newInput = document.getElementById("new_supplier_name");
    const searchInput = document.getElementById("supplier_search");

    if(isRegisteredSupplierMode()){
        return searchInput ? String(searchInput.value || "").trim() : "";
    }

    return newInput ? String(newInput.value || "").trim() : "";
}

function clearSupplierDuplicateWarning(){
    const box = document.getElementById("supplierDuplicateWarning");
    const hidden = document.getElementById("supplier_duplicate_found");

    supplierDuplicateMatches = [];

    if(hidden){
        hidden.value = "0";
    }

    if(box){
        box.style.display = "none";
        box.innerHTML = "";
    }
}

function closeSupplierDuplicateWarning(){
    clearSupplierDuplicateWarning();
}

function renderSupplierDuplicateWarning(data){
    const box = document.getElementById("supplierDuplicateWarning");
    const hidden = document.getElementById("supplier_duplicate_found");

    if(!box || !hidden){
        return;
    }

    supplierDuplicateMatches = [];
    hidden.value = "0";
    box.style.display = "none";
    box.innerHTML = "";

    if(!data || !data.success || !Array.isArray(data.matches) || data.matches.length === 0){
        return;
    }

    supplierDuplicateMatches = data.matches;
    hidden.value = data.strong_match ? "1" : "0";

    const tags = supplierDuplicateMatches.map(function(item){
        const src = item.source_label || (
            item.source === "suppliers"
                ? "اسم موجود في الموردين المسجلين"
                : "اسم موجود في العقود"
        );

        return `<span class="supplier-match">${escapeSupplierHtml(item.name)} - ${escapeSupplierHtml(src)}</span>`;
    }).join("");

    let helpText = "";

    if(isRegisteredSupplierMode()){
        helpText = "هذا المورد موجود أو له سجل سابق. راجع الاسم قبل حفظ العقد.";
    }else{
        helpText = "إذا كان نفس المورد، الأفضل تختاره كمورد مسجل بدل إدخاله كمورد جديد.";
    }

    box.innerHTML =
        `<button type="button" class="supplier-warning-close" onclick="closeSupplierDuplicateWarning()">×</button>` +
        `<strong>⚠️ تنبيه:</strong> يوجد مورد قريب من الاسم أو تم إدخاله من قبل:<br>` +
        tags +
        `<div style="margin-top:6px;">${helpText}</div>`;

    box.style.display = "block";
}

function checkSupplierDuplicateNow(){
    const name = getCurrentSupplierNameForCheck();

    if(name.length < 3){
        clearSupplierDuplicateWarning();
        return;
    }

    fetch("supplier_name_check.php?name=" + encodeURIComponent(name), {
        method: "GET",
        cache: "no-store",
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
    .then(function(response){
        return response.json();
    })
    .then(function(data){
        renderSupplierDuplicateWarning(data);
    })
    .catch(function(){
        clearSupplierDuplicateWarning();
    });
}

function confirmSupplierDuplicateBeforeSubmit(){
    const newInput = document.getElementById("new_supplier_name");
    const searchInput = document.getElementById("supplier_search");
    const hiddenSupplier = document.getElementById("supplier_name");

    if(hiddenSupplier){
        if(isNewSupplierMode() && newInput){
            hiddenSupplier.value = String(newInput.value || "").trim();
        }

        if(isRegisteredSupplierMode() && searchInput){
            hiddenSupplier.value = String(searchInput.value || "").trim();
        }
    }

    
    if(isNewSupplierMode() && supplierDuplicateMatches.length > 0){
        const names = supplierDuplicateMatches.slice(0, 5).map(function(item){
            const src = item.source_label || (
                item.source === "suppliers"
                    ? "مورد مسجل"
                    : "اسم موجود في العقود"
            );

            return "- " + item.name + " (" + src + ")";
        }).join("\n");

        return confirm(
            "يوجد مورد مشابه أو مطابق بالفعل:\n\n" +
            names +
            "\n\nهل تريد الحفظ كمورد جديد؟"
        );
    }

    return true;
}

(function(){
    const newInput = document.getElementById("new_supplier_name");
    const searchInput = document.getElementById("supplier_search");

    if(newInput){
        newInput.addEventListener("input", function(){
            clearTimeout(supplierDuplicateTimer);
            supplierDuplicateTimer = setTimeout(checkSupplierDuplicateNow, 450);
        });

        newInput.addEventListener("blur", checkSupplierDuplicateNow);
    }

    if(searchInput){
        searchInput.addEventListener("input", function(){
            clearTimeout(supplierDuplicateTimer);
            supplierDuplicateTimer = setTimeout(checkSupplierDuplicateNow, 450);
        });

        searchInput.addEventListener("blur", checkSupplierDuplicateNow);
    }

    document.querySelectorAll("input[name='supplier_status']").forEach(function(radio){
        radio.addEventListener("change", function(){
            clearSupplierDuplicateWarning();
            setTimeout(checkSupplierDuplicateNow, 150);
        });
    });

    setTimeout(checkSupplierDuplicateNow, 600);
})();
</script>

</body>
</html>
