<?php
require_once VC_HELPERS . '/auth.php';

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

function jsonOut(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function normName(string $value): string {
    $value = trim($value);

    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }

    $value = strtr($value, [
        'أ'=>'ا',
        'إ'=>'ا',
        'آ'=>'ا',
        'ى'=>'ي',
        'ة'=>'ه',
        'ؤ'=>'و',
        'ئ'=>'ي',
        'ـ'=>''
    ]);

    $value = preg_replace('/[^\p{Arabic}a-z0-9\s]/iu', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = trim($value);

    $stop = [
        'شركة','شركه',
        'مؤسسة','موسسه','مؤسسه',
        'للتجارة','للتجاره',
        'التجارة','التجاره',
        'مركز','مطعم',
        'مجموعة','مجموعه',
        'فرع',
        'للتموينات','تموينات'
    ];

    foreach ($stop as $word) {
        $value = preg_replace('/(^|\s)' . preg_quote($word, '/') . '(\s|$)/u', ' ', $value);
    }

    $value = preg_replace('/\s+/u', ' ', $value);

    return trim($value);
}

function supplierLabelWithYear(string $createdAt): string {
    $createdAt = trim($createdAt);
    $year = '';

    if ($createdAt !== '') {
        $ts = strtotime($createdAt);
        if ($ts) {
            $year = date('Y', $ts);
        }
    }

    return $year !== ''
        ? ('اسم موجود ' . $year . ' في الموردين المسجلين')
        : 'اسم موجود في الموردين المسجلين';
}

function contractStatusLabel(string $status): string {
    $status = trim($status);

    if ($status === 'draft') {
        return 'مسودة عقد';
    }

    if ($status === 'review') {
        return 'عقد تحت المراجعة';
    }

    if ($status === 'approved') {
        return 'عقد معتمد';
    }

    if ($status === 'rejected') {
        return 'عقد مرفوض';
    }

    return 'اسم موجود في العقود';
}

function addCandidate(
    array &$candidates,
    string $source,
    int $id,
    string $name,
    string $phone = '',
    string $company = '',
    string $status = '',
    string $createdAt = ''
): void {
    $name = trim($name);

    if ($name === '') {
        return;
    }

    $label = ($source === 'suppliers')
        ? supplierLabelWithYear($createdAt)
        : contractStatusLabel($status);

    $candidates[] = [
        "source" => $source,
        "source_label" => $label,
        "id" => $id,
        "name" => $name,
        "phone" => $phone,
        "company" => $company,
        "status" => $status,
        "created_at" => $createdAt
    ];
}

try {
    $name = trim((string)($_GET['name'] ?? ''));

    $nameLen = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

    if ($name === '' || $nameLen < 3) {
        jsonOut([
            "success" => true,
            "matches" => [],
            "strong_match" => false
        ]);
    }

    $needle = normName($name);
    $candidates = [];
    $like = '%' . $name . '%';

    
    $stmt = $conn->prepare("
        SELECT id, name, phone, company, created_at
        FROM suppliers
        WHERE company LIKE ?
           OR name LIKE ?
        ORDER BY id DESC
        LIMIT 80
    ");

    if ($stmt) {
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($r = $res->fetch_assoc()) {
            $supplierDisplayName = trim((string)($r['company'] ?? ''));

            if ($supplierDisplayName === '') {
                $supplierDisplayName = trim((string)($r['name'] ?? ''));
            }

            addCandidate(
                $candidates,
                'suppliers',
                (int)($r['id'] ?? 0),
                $supplierDisplayName,
                (string)($r['phone'] ?? ''),
                (string)($r['name'] ?? ''),
                '',
                (string)($r['created_at'] ?? '')
            );
        }

        $stmt->close();
    }

    
    $stmt = $conn->prepare("
        SELECT id, supplier_name AS name, supplier_phone AS phone, company_name AS company, status
        FROM contracts
        WHERE supplier_name LIKE ?
        ORDER BY id DESC
        LIMIT 80
    ");

    if ($stmt) {
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($r = $res->fetch_assoc()) {
            addCandidate(
                $candidates,
                'contracts',
                (int)($r['id'] ?? 0),
                (string)($r['name'] ?? ''),
                (string)($r['phone'] ?? ''),
                (string)($r['company'] ?? ''),
                (string)($r['status'] ?? '')
            );
        }

        $stmt->close();
    }

    
    $q = $conn->query("
        SELECT id, name, phone, company, created_at
        FROM suppliers
        ORDER BY id DESC
        LIMIT 300
    ");

    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $supplierDisplayName = trim((string)($r['company'] ?? ''));

            if ($supplierDisplayName === '') {
                $supplierDisplayName = trim((string)($r['name'] ?? ''));
            }

            addCandidate(
                $candidates,
                'suppliers',
                (int)($r['id'] ?? 0),
                $supplierDisplayName,
                (string)($r['phone'] ?? ''),
                (string)($r['name'] ?? ''),
                '',
                (string)($r['created_at'] ?? '')
            );
        }
    }

    
    $q = $conn->query("
        SELECT id, supplier_name AS name, supplier_phone AS phone, company_name AS company, status
        FROM contracts
        WHERE supplier_name IS NOT NULL
        AND supplier_name <> ''
        ORDER BY id DESC
        LIMIT 300
    ");

    if ($q) {
        while ($r = $q->fetch_assoc()) {
            addCandidate(
                $candidates,
                'contracts',
                (int)($r['id'] ?? 0),
                (string)($r['name'] ?? ''),
                (string)($r['phone'] ?? ''),
                (string)($r['company'] ?? ''),
                (string)($r['status'] ?? '')
            );
        }
    }

    $merged = [];

    foreach ($candidates as $r) {
        $candidate = normName((string)($r['name'] ?? ''));

        if ($candidate === '' || $needle === '') {
            continue;
        }

        similar_text($needle, $candidate, $percent);

        $contain = false;

        if (function_exists('mb_strpos')) {
            $contain = (
                mb_strpos($candidate, $needle, 0, 'UTF-8') !== false ||
                mb_strpos($needle, $candidate, 0, 'UTF-8') !== false
            );
        } else {
            $contain = (
                strpos($candidate, $needle) !== false ||
                strpos($needle, $candidate) !== false
            );
        }

        
        $strong = ($candidate === $needle || $percent >= 92);
        $near = ($strong || $contain || $percent >= 80);

        if (!$near) {
            continue;
        }

        
        $key = $r['source'] . '_' . ($r['status'] ?? '') . '_' . normName((string)$r['name']);

        if (!isset($merged[$key])) {
            $r['similarity'] = round($percent, 1);
            $r['strong'] = $strong;
            $merged[$key] = $r;
        }
    }

    $matches = array_values($merged);

    usort($matches, function($a, $b) {
        $aStrong = !empty($a['strong']) ? 1 : 0;
        $bStrong = !empty($b['strong']) ? 1 : 0;

        if ($aStrong !== $bStrong) {
            return $bStrong <=> $aStrong;
        }

        return ($b['similarity'] ?? 0) <=> ($a['similarity'] ?? 0);
    });

    $matches = array_slice($matches, 0, 8);

    $strongMatch = false;

    foreach ($matches as $m) {
        if (!empty($m['strong'])) {
            $strongMatch = true;
            break;
        }
    }

    jsonOut([
        "success" => true,
        "matches" => $matches,
        "strong_match" => $strongMatch
    ]);

} catch (Throwable $e) {
    jsonOut([
        "success" => false,
        "matches" => [],
        "strong_match" => false,
        "message" => "تعذر فحص اسم المورد"
    ]);
}
?>
