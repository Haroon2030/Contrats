<?php


header("Content-Type: text/html; charset=UTF-8");

$q = trim($_GET['q'] ?? '');

if ($q === '' || mb_strlen($q, 'UTF-8') < 2) {
    exit;
}

$like = "%" . $q . "%";

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function vcColumnExistsSearch(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("\n        SELECT COUNT(*) AS c\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n        AND TABLE_NAME = ?\n        AND COLUMN_NAME = ?\n    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row) && (int)$row['c'] > 0;
}

function vcFirstExistingColumnSearch(mysqli $conn, string $table, array $columns): ?string {
    foreach ($columns as $column) {
        if (vcColumnExistsSearch($conn, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function vcNormalizeKeySearch(string $value): string {
    $value = trim($value);
    $value = preg_replace('/\s+/u', '', $value);
    $value = str_replace(['أ','إ','آ'], 'ا', $value);
    $value = str_replace('ى', 'ي', $value);
    $value = str_replace('ة', 'ه', $value);
    $value = str_replace(['ـ', '.', ',', '،', '-', '_'], '', $value);
    return mb_strtolower($value, 'UTF-8');
}

$rows = [];
$seen = [];


$supplierPhoneCol = vcFirstExistingColumnSearch($conn, 'suppliers', [
    'supplier_phone',
    'phone',
    'mobile',
    'phone_number',
    'tel',
    'contact_phone'
]);

$phoneSelect = $supplierPhoneCol ? "s.`" . str_replace("`", "", $supplierPhoneCol) . "`" : "''";
$phoneWhere  = $supplierPhoneCol ? " OR s.`" . str_replace("`", "", $supplierPhoneCol) . "` LIKE ? " : "";

$sqlSuppliers = "
    SELECT 
        s.name,
        s.company,
        {$phoneSelect} AS phone,
        COALESCE((
            SELECT e.value
            FROM events e
            JOIN contracts c ON c.id = e.contract_id
            WHERE 
                (
                    c.supplier_name = s.company
                    OR c.supplier_name = s.name
                    OR c.company_name = s.company
                    OR c.company_name = s.name
                )
                AND (e.type = 'new_item' OR e.name = 'رسوم صنف جديد')
                AND c.status IN ('approved', 'review', 'draft')
            ORDER BY 
                (c.status = 'approved') DESC,
                c.id DESC,
                e.id DESC
            LIMIT 1
        ), 0) AS new_item_fee
    FROM suppliers s
    WHERE s.name LIKE ?
       OR s.company LIKE ?
       {$phoneWhere}
    ORDER BY s.id DESC
    LIMIT 15
";

$stmt = $conn->prepare($sqlSuppliers);

if ($stmt) {
    if ($supplierPhoneCol) {
        $stmt->bind_param("sss", $like, $like, $like);
    } else {
        $stmt->bind_param("ss", $like, $like);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $company = trim((string)($row['company'] ?? ''));
        $contact = trim((string)($row['name'] ?? ''));
        $phone   = trim((string)($row['phone'] ?? ''));

        $supplierName = $company !== '' ? $company : $contact;

        if ($supplierName === '') {
            continue;
        }

        $key = vcNormalizeKeySearch($supplierName);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;

        $rows[] = [
            'source'   => 'suppliers',
            'label'    => trim($company . ($contact !== '' ? " - " . $contact : "")),
            'supplier' => $supplierName,
            'contact'  => $contact,
            'phone'    => $phone,
            'fee'      => (float)($row['new_item_fee'] ?? 0),
        ];
    }

    $stmt->close();
}


$stmt = $conn->prepare("
    SELECT 
        c.id,
        c.supplier_name,
        c.company_name,
        c.supplier_phone,
        c.status,
        COALESCE((
            SELECT e.value
            FROM events e
            WHERE e.contract_id = c.id
              AND (e.type = 'new_item' OR e.name = 'رسوم صنف جديد')
            ORDER BY e.id DESC
            LIMIT 1
        ), 0) AS new_item_fee
    FROM contracts c
    WHERE 
        c.supplier_name LIKE ?
        OR c.company_name LIKE ?
        OR c.supplier_phone LIKE ?
    ORDER BY 
        (c.status = 'approved') DESC,
        c.id DESC
    LIMIT 15
");

if ($stmt) {
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $supplierName = trim((string)($row['supplier_name'] ?? ''));
        $contact      = trim((string)($row['company_name'] ?? ''));
        $phone        = trim((string)($row['supplier_phone'] ?? ''));

        if ($supplierName === '') {
            continue;
        }

        $key = vcNormalizeKeySearch($supplierName);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;

        $status = (string)($row['status'] ?? '');

        $statusText = [
            'approved' => 'موافق عليه',
            'review'   => 'تحت المراجعة',
            'draft'    => 'تفاوض',
            'rejected' => 'مرفوض',
            'deleted'  => 'ملغي',
        ][$status] ?? $status;

        $rows[] = [
            'source'   => 'contracts',
            'label'    => $supplierName . " - عقد #" . (int)$row['id'] . " - " . $statusText,
            'supplier' => $supplierName,
            'contact'  => $contact,
            'phone'    => $phone,
            'fee'      => (float)($row['new_item_fee'] ?? 0),
        ];
    }

    $stmt->close();
}

if (empty($rows)) {
    echo "<div class='item' style='padding:10px; cursor:default; color:#777;'>لا توجد نتائج</div>";
    exit;
}

foreach ($rows as $row) {
    $supplier = h($row['supplier']);
    $contact  = h($row['contact']);
    $phone    = h($row['phone']);
    $label    = h($row['label']);
    $fee      = number_format((float)$row['fee'], 2, '.', '');
    $source   = $row['source'] === 'contracts' ? 'من العقود' : 'من الموردين';

    echo "
        <div class='item'
             data-name='{$supplier}'
             data-contact='{$contact}'
             data-phone='{$phone}'
             data-fee='{$fee}'
             onclick='selectSupplier(this.dataset.name, this.dataset.contact, this.dataset.phone, this.dataset.fee)'
             style='padding:10px 12px; cursor:pointer; border-bottom:1px solid #eef1f7;'>
            <div style='font-weight:800;'>{$label}</div>
            <div style='font-size:12px; color:#667085; margin-top:3px;'>
                {$source}
                " . ($contact !== '' ? " — المسؤول: {$contact}" : "") . "
                " . ($phone !== '' ? " — الجوال: {$phone}" : "") . "
                " . ((float)$fee > 0 ? " — رسوم الدخول: {$fee}" : "") . "
            </div>
        </div>
    ";
}
?>
