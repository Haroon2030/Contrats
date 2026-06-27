<?php



$__originalPhpSelf = $_SERVER['PHP_SELF'] ?? '';
$_SERVER['PHP_SELF'] = 'accounting.php';
require_once VC_HELPERS . '/auth.php';
$_SERVER['PHP_SELF'] = $__originalPhpSelf;

header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('Asia/Riyadh');

function api_json($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function api_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function api_money($value): string {
    return number_format((float)$value, 2);
}

function api_forbidden_readonly(): void {
    api_json([
        'success' => false,
        'message' => 'متابعة المالية للعقود عرض فقط'
    ], 403);
}

function api_fetch_all(VcDbStmt $stmt): array {
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawAction = $_POST['action'] ?? $_POST['status'] ?? $_POST['type'] ?? '';
    $rawActionText = mb_strtolower((string)$rawAction, 'UTF-8');

    if (
        $rawActionText !== '' ||
        strpos($rawActionText, 'deduct') !== false ||
        strpos($rawActionText, 'discount') !== false ||
        strpos($rawActionText, 'approve') !== false ||
        strpos($rawActionText, 'reject') !== false ||
        strpos($rawActionText, 'delete') !== false ||
        strpos($rawActionText, 'خصم') !== false ||
        strpos($rawActionText, 'اعتماد') !== false ||
        strpos($rawActionText, 'رفض') !== false ||
        strpos($rawActionText, 'حذف') !== false
    ) {
        api_forbidden_readonly();
    }
}

$allowedTabs = ['contracts', 'events', 'rents', 'discounts'];
$allowedFiltersRequest = ['events', 'rents', 'discounts'];


if (isset($_GET['filters'])) {
    $tab = trim((string)$_GET['filters']);

    if (!in_array($tab, $allowedFiltersRequest, true)) {
        api_json([]);
    }

    if ($tab === 'events') {
        $stmt = $conn->prepare("SELECT DISTINCT name FROM events WHERE name IS NOT NULL AND name != '' ORDER BY name ASC");
        if (!$stmt) {
            api_json(['success' => false, 'message' => 'تعذر تحميل فلاتر الفعاليات'], 500);
        }

        $out = [];
        foreach (api_fetch_all($stmt) as $r) {
            $out[] = ['val' => $r['name'], 'name' => $r['name']];
        }
        api_json($out);
    }

    if ($tab === 'rents') {
        $stmt = $conn->prepare("
            SELECT branch, COUNT(DISTINCT contract_id) AS cnt
            FROM rents
            WHERE branch IS NOT NULL AND branch != ''
            GROUP BY branch
            ORDER BY branch ASC
        ");
        if (!$stmt) {
            api_json(['success' => false, 'message' => 'تعذر تحميل فلاتر الإيجارات'], 500);
        }

        $out = [];
        foreach (api_fetch_all($stmt) as $r) {
            $branch = (string)$r['branch'];
            $out[] = [
                'val' => $branch,
                'name' => $branch . ' (' . (int)$r['cnt'] . ')'
            ];
        }
        api_json($out);
    }

    if ($tab === 'discounts') {
        api_json([
            ['val' => 'invoice', 'name' => 'خصم فاتورة'],
            ['val' => 'payment', 'name' => 'خصم سداد'],
            ['val' => 'annual',  'name' => 'خصم سنوي']
        ]);
    }
}


$tab = trim((string)($_POST['tab'] ?? $_GET['tab'] ?? 'contracts'));
$search = trim((string)($_POST['search'] ?? $_GET['search'] ?? ''));
$filter = trim((string)($_POST['filter'] ?? $_GET['filter'] ?? ''));

if (!in_array($tab, $allowedTabs, true)) {
    api_json([
        'html' => "<div class='card'>طلب غير صحيح</div>",
        'chart' => ['labels' => [], 'values' => []]
    ], 400);
}

$html = '';
$labels = [];
$values = [];


if ($tab === 'contracts') {
    $sql = "
        SELECT c.supplier_name, u.username AS created_by
        FROM contracts c
        LEFT JOIN users u ON u.id = c.created_by
        WHERE c.status = 'approved'
    ";

    $params = [];
    $types = '';

    if ($search !== '') {
        $sql .= " AND c.supplier_name LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    $sql .= " ORDER BY c.id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        api_json(['success' => false, 'message' => 'تعذر تحميل العقود'], 500);
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }

    foreach (api_fetch_all($stmt) as $r) {
        $html .= "<div class='card'>";
        $html .= "<b>" . api_e($r['supplier_name']) . "</b><br>";
        $html .= "👤 " . api_e($r['created_by'] ?: 'غير محدد');
        $html .= "</div>";
    }
}


if ($tab === 'events') {
    $sql = "
        SELECT c.supplier_name, SUM(COALESCE(e.value, 0)) AS total
        FROM events e
        JOIN contracts c ON c.id = e.contract_id
        WHERE c.status = 'approved'
    ";

    $params = [];
    $types = '';

    if ($filter !== '') {
        $sql .= " AND e.name = ?";
        $params[] = $filter;
        $types .= 's';
    }

    if ($search !== '') {
        $sql .= " AND c.supplier_name LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    $sql .= " GROUP BY c.id, c.supplier_name ORDER BY total DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        api_json(['success' => false, 'message' => 'تعذر تحميل الفعاليات'], 500);
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }

    foreach (api_fetch_all($stmt) as $r) {
        $supplier = (string)$r['supplier_name'];
        $total = (float)$r['total'];

        $html .= "<div class='card'>" . api_e($supplier) . " - " . api_money($total) . "</div>";
        $labels[] = $supplier;
        $values[] = $total;
    }
}


if ($tab === 'rents') {
    $sql = "
        SELECT c.supplier_name, SUM(COALESCE(r.total, 0)) AS total
        FROM rents r
        JOIN contracts c ON c.id = r.contract_id
        WHERE c.status = 'approved'
    ";

    $params = [];
    $types = '';

    if ($filter !== '') {
        $sql .= " AND r.branch = ?";
        $params[] = $filter;
        $types .= 's';
    }

    if ($search !== '') {
        $sql .= " AND c.supplier_name LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    $sql .= " GROUP BY c.id, c.supplier_name ORDER BY total DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        api_json(['success' => false, 'message' => 'تعذر تحميل الإيجارات'], 500);
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }

    foreach (api_fetch_all($stmt) as $r) {
        $html .= "<div class='card'>" . api_e($r['supplier_name']) . " - " . api_money($r['total']) . "</div>";
    }
}


if ($tab === 'discounts') {
    $allowedDiscountFilters = ['invoice', 'payment', 'annual', ''];
    if (!in_array($filter, $allowedDiscountFilters, true)) {
        $filter = '';
    }

    $fieldExpression = "(COALESCE(c.discount_invoice, 0) + COALESCE(c.discount_payment, 0) + COALESCE(c.discount_quarter, 0))";

    if ($filter === 'invoice') {
        $fieldExpression = "COALESCE(c.discount_invoice, 0)";
    } elseif ($filter === 'payment') {
        $fieldExpression = "COALESCE(c.discount_payment, 0)";
    } elseif ($filter === 'annual') {
        $fieldExpression = "COALESCE(c.discount_quarter, 0)";
    }

    $sql = "
        SELECT c.supplier_name, {$fieldExpression} AS total
        FROM contracts c
        WHERE c.status = 'approved'
    ";

    $params = [];
    $types = '';

    if ($search !== '') {
        $sql .= " AND c.supplier_name LIKE ?";
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    $sql .= " ORDER BY total DESC, c.id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        api_json(['success' => false, 'message' => 'تعذر تحميل الخصومات'], 500);
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }

    foreach (api_fetch_all($stmt) as $r) {
        $html .= "<div class='card'>" . api_e($r['supplier_name']) . " - " . api_money($r['total']) . "</div>";
    }
}

api_json([
    'html' => $html,
    'chart' => [
        'labels' => $labels,
        'values' => $values
    ]
]);
