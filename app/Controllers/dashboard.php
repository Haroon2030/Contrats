<?php
session_start();
require_once VC_HELPERS . '/scope_helper.php';

/* ================= SECURITY ================= */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$uid = (int)$_SESSION['user_id'];

/* CSRF */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* ================= SAVE SORT ORDER ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json; charset=UTF-8");

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "CSRF"]);
        exit();
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (is_array($data)) {
        foreach ($data as $item) {
            if (!isset($item['name'], $item['position'])) {
                continue;
            }

            $page_name = trim((string)$item['name']);
            $sort_order = (int)$item['position'];

            if ($page_name === '' || $sort_order < 1) {
                continue;
            }

            $stmt = $conn->prepare("
                INSERT INTO user_page_order (user_id, page_name, sort_order)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)
            ");
            $stmt->bind_param("isi", $uid, $page_name, $sort_order);
            $stmt->execute();
            $stmt->close();
        }

        echo json_encode(["success" => true]);
        exit();
    }

    echo json_encode(["success" => false]);
    exit();
}

/* ================= USER ================= */
$stmt = $conn->prepare("SELECT is_admin, role, username, job_role, is_supervisor FROM users WHERE id = ?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$is_admin = ((int)($user['is_admin'] ?? 0) === 1 || ($user['role'] ?? '') === 'admin' || ($user['job_role'] ?? '') === 'admin');
$username = $user['username'] ?? 'User';

$displayRole = 'مستخدم';
try {
    $financeManagerIdForDisplay = 0;
    $foodSectionManagerIdForDisplay = 0;
    $nonFoodSectionManagerIdForDisplay = 0;

    $settingsResForDisplay = $conn->query("
        SELECT setting_key, user_id
        FROM payment_approval_settings
        WHERE setting_key IN ('finance_manager','food_section_manager','non_food_section_manager')
    ");

    if ($settingsResForDisplay) {
        while ($settingRowForDisplay = $settingsResForDisplay->fetch_assoc()) {
            if ((string)$settingRowForDisplay['setting_key'] === 'finance_manager') {
                $financeManagerIdForDisplay = (int)$settingRowForDisplay['user_id'];
            } elseif ((string)$settingRowForDisplay['setting_key'] === 'food_section_manager') {
                $foodSectionManagerIdForDisplay = (int)$settingRowForDisplay['user_id'];
            } elseif ((string)$settingRowForDisplay['setting_key'] === 'non_food_section_manager') {
                $nonFoodSectionManagerIdForDisplay = (int)$settingRowForDisplay['user_id'];
            }
        }
    }

    if ($financeManagerIdForDisplay <= 0) {
        $financeManagerIdForDisplay = 19; // طارق هندي احتياطيًا
    }

    $jobRoleForDisplay = (string)($user['job_role'] ?? 'user');
    $roleForDisplay = (string)($user['role'] ?? 'user');
    $isAdminForDisplay = ((int)($user['is_admin'] ?? 0) === 1) || $roleForDisplay === 'admin' || $jobRoleForDisplay === 'admin';

    if ($jobRoleForDisplay === 'finance_manager' || $uid === $financeManagerIdForDisplay) {
        $displayRole = 'مدير مالي';
    } elseif ($isAdminForDisplay) {
        $displayRole = 'أدمن';
    } elseif ($jobRoleForDisplay === 'commercial_manager') {
        $displayRole = 'مدير تجاري';
    } elseif ($uid === $foodSectionManagerIdForDisplay && $uid === $nonFoodSectionManagerIdForDisplay) {
        $displayRole = 'مدير قسم غذائي ولا غذائي';
    } elseif ($uid === $foodSectionManagerIdForDisplay) {
        $displayRole = 'مدير قسم غذائي';
    } elseif ($uid === $nonFoodSectionManagerIdForDisplay) {
        $displayRole = 'مدير قسم لا غذائي';
    } elseif ($jobRoleForDisplay === 'section_manager') {
        $displayRole = 'مدير قسم';
    } elseif ($jobRoleForDisplay === 'accountant') {
        $displayRole = 'محاسب';
    }
} catch (Throwable $e) {
    error_log("dashboard role display error: " . $e->getMessage());
}

$first_letter = function_exists('mb_substr')
    ? mb_substr($username, 0, 1, 'UTF-8')
    : substr($username, 0, 1);

$dashboardScopedUserIds = vcGetDashboardScopedUserIds($conn, $uid, $user, $is_admin);
$dashboardIsAllScope = empty($dashboardScopedUserIds);
$show_management_analytics = ($is_admin || in_array((string)($user['job_role'] ?? ''), ['section_manager','finance_manager','commercial_manager'], true) || (int)($user['is_supervisor'] ?? 0) === 1);

function vcDashRunCount(mysqli $conn, string $sql, string $types = '', array $params = []): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

function vcDashApplyScopeWhere(string $column, array $scopeIds, &$params, &$types): string {
    if (!is_array($params)) {
        $params = [];
    }

    if (!is_string($types)) {
        $types = '';
    }

    return vcBuildInCondition($column, $scopeIds, $params, $types);
}

/*
    كارت إدخال الأصناف في الداشبورد:
    يظهر فقط لـ:
    - الأدمن
    - المستخدم الذي عنده صفحة data_entry_items
    - المدير المباشر لمستخدم عنده صفحة data_entry_items
*/
function vcDashGetDirectManagedDataEntryUserIds(mysqli $conn, int $managerId): array {
    if ($managerId <= 0 || !vcScopeColumnExists($conn, 'users', 'manager_id')) {
        return [];
    }

    $ids = [];
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id
        FROM users u
        JOIN user_permissions up ON up.user_id = u.id
        JOIN pages p ON p.id = up.page_id
        WHERE u.manager_id = ?
          AND p.name = 'data_entry_items'
          AND p.status = 1
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $managerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $stmt->close();

    return array_values(array_unique($ids));
}


/* ================= HELPERS ================= */
function e($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function countTable($conn, $table){
    $allowed = ['contracts','suppliers','users'];
    if (!in_array($table, $allowed, true)) return 0;

    $q = $conn->query("SELECT COUNT(*) c FROM `$table`");
    return $q ? (int)$q->fetch_assoc()['c'] : 0;
}

function donutGradient($segments){
    $total = 0;
    foreach($segments as $s){
        $total += (int)$s['value'];
    }

    if ($total <= 0) {
        return "rgba(255,255,255,.24) 0deg 360deg";
    }

    $start = 0;
    $parts = [];

    foreach($segments as $s){
        $value = (int)$s['value'];
        if ($value <= 0) continue;

        $deg = round(($value / $total) * 360, 2);
        $end = $start + $deg;
        $parts[] = $s['color']." ".$start."deg ".$end."deg";
        $start = $end;
    }

    if ($start < 360) {
        $parts[] = "rgba(255,255,255,.20) ".$start."deg 360deg";
    }

    return implode(", ", $parts);
}

function donutGradientValue($segments){
    $total = 0.0;
    foreach($segments as $s){
        $total += (float)$s['value'];
    }

    if ($total <= 0) {
        return "rgba(109,74,255,.14) 0deg 360deg";
    }

    $start = 0.0;
    $parts = [];

    foreach($segments as $s){
        $value = (float)$s['value'];
        if ($value <= 0) continue;

        $deg = round(($value / $total) * 360, 2);
        $end = $start + $deg;
        $parts[] = $s['color']." ".$start."deg ".$end."deg";
        $start = $end;
    }

    if ($start < 360) {
        $parts[] = "rgba(109,74,255,.10) ".$start."deg 360deg";
    }

    return implode(", ", $parts);
}

function moneyFull($value): string {
    return number_format((float)$value, 2);
}

function moneyShort($value): string {
    $value = (float)$value;

    if ($value >= 1000000) {
        return number_format($value / 1000000, 1) . "M";
    }

    if ($value >= 1000) {
        return number_format($value / 1000, 1) . "K";
    }

    return number_format($value, 0);
}

function dashboardColors(): array {
    return ['#47e6a1','#6bb7ff','#ffd166','#ff6b8a','#a78bfa','#22d3ee','#f97316','#14b8a6'];
}

function buildDashboardSegments(array $rows, bool $money = false, int $limit = 5): array {
    $colors = dashboardColors();
    $segments = [];
    $others = 0.0;
    $i = 0;

    foreach ($rows as $row) {
        $label = trim((string)($row['label'] ?? 'غير محدد'));
        if ($label === '') {
            $label = 'غير محدد';
        }

        $value = (float)($row['value'] ?? 0);
        if ($value <= 0) {
            continue;
        }

        if ($i < $limit) {
            $segments[] = [
                'label' => $label,
                'value' => $value,
                'display' => $money ? moneyFull($value) . ' ريال' : (string)(int)$value,
                'color' => $colors[$i % count($colors)]
            ];
        } else {
            $others += $value;
        }

        $i++;
    }

    if ($others > 0) {
        $segments[] = [
            'label' => 'أخرى',
            'value' => $others,
            'display' => $money ? moneyFull($others) . ' ريال' : (string)(int)$others,
            'color' => $colors[count($segments) % count($colors)]
        ];
    }

    return $segments;
}

function fetchDashboardRows(mysqli $conn, string $sql): array {
    $rows = [];
    $res = $conn->query($sql);

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}


function fetchDashboardRowsPrepared(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $rows = [];
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $rows;
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function fetchDashboardOneValue(mysqli $conn, string $sql, string $field, string $types = '', array $params = []): float {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0.0;
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($row[$field] ?? 0);
}


function vcDashEnsureNotificationsTable(mysqli $conn): void {
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NULL,
                link VARCHAR(500) NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_read (user_id, is_read),
                INDEX idx_user_created (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log("dashboard notifications table create error: " . $e->getMessage());
    }
}

function vcDashEnsureDismissalsTable(mysqli $conn): void {
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS notification_dismissals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                notif_type VARCHAR(50) NOT NULL,
                ref_id INT NOT NULL,
                dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_type_ref (user_id, notif_type, ref_id),
                INDEX idx_user_type (user_id, notif_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log("dashboard notification_dismissals table create error: " . $e->getMessage());
    }
}


function vcDashColumnExists(mysqli $conn, string $table, string $column): bool {
    try {
        $stmt = $conn->prepare("\n            SELECT COUNT(*) AS c\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n            AND TABLE_NAME = ?\n            AND COLUMN_NAME = ?\n        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("ss", $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return !empty($row) && (int)($row['c'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function vcDashNormalizeNotifText(string $value): string {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    return $value;
}

function vcDashCleanPaymentNotificationText(string $value): string {
    $value = str_replace(["\r\n", "\r"], "\n", trim($value));

    $value = preg_replace('/^.*نسبة\s+السداد\s+المعجل\s*:\s*0(?:\.00)?\s*%?.*$/mu', '', $value);
    $value = preg_replace('/^.*فرق\s+السداد\s+المعجل\s*:\s*0(?:\.00)?.*$/mu', '', $value);
    $value = preg_replace('/\s*بعد\s+خصم\s+السداد\s+المعجل\s+0(?:\.00)?\s*/u', ' ', $value);
    $value = str_replace('المبلغ المعتمد حتى الآن بعد الخصم المعجل', 'المبلغ المعتمد حتى الآن', $value);
    $value = str_replace('المبلغ المعتمد بعد الخصم المعجل', 'المبلغ المعتمد', $value);
    $value = str_replace('بعد الخصم المعجل', '', $value);

    $lines = array_values(array_filter(array_map('trim', explode("\n", $value)), function($line){
        return $line !== '';
    }));

    return trim(implode("\n", $lines));
}

function vcDashClassifyNotification(string $kind, string $type, string $title, string $line, string $href, string $class = ''): array {
    $rawType = vcDashNormalizeNotifText($type);
    $text = vcDashNormalizeNotifText($kind . ' ' . $type . ' ' . $title . ' ' . $line . ' ' . $href . ' ' . $class);

    $group = 'general';
    $status = 'general';
    $badge = 'عام';
    $badgeClass = 'general';
    $icon = '🔔';

    /*
        إشعارات طلبات السداد لازم تبقى ثابتة:
        - التصنيف الأساسي من type أولًا، وليس من كلمات داخل الرسالة.
        - كل السداد يظهر بأيقونة واحدة 💳 حتى لا يختلف بين الحسابات.
        - رفض مدير القسم الذي يذهب للمدير التجاري = يحتاج قرار، وليس إشعار مرفوض.
    */
    if (
        strpos($rawType, 'payment_request') !== false ||
        strpos($text, 'payment_approvals.php') !== false ||
        strpos($text, 'طلب سداد') !== false ||
        strpos($text, 'طلب السداد') !== false
    ) {
        $group = 'payments';
        $status = 'payment_pending';
        $badge = 'سداد للموافقة';
        $badgeClass = 'payments';
        $icon = '💳';

        if (strpos($rawType, 'payment_request_section_rejected') !== false) {
            $status = 'payment_action';
            $badge = 'سداد يحتاج قرار';
            $badgeClass = 'payments';
        } elseif (strpos($rawType, 'payment_request_final_approved') !== false) {
            $status = 'payment_approved';
            $badge = 'سداد معتمد';
            $badgeClass = 'approved';
        } elseif (strpos($rawType, 'payment_request_rejected') !== false) {
            $status = 'payment_rejected';
            $badge = 'سداد مرفوض';
            $badgeClass = 'rejected';
        } elseif (strpos($rawType, 'payment_request_approval') !== false || strpos($rawType, 'payment_request') !== false) {
            $status = 'payment_pending';
            $badge = 'سداد للموافقة';
            $badgeClass = 'payments';
        } elseif (strpos($title, 'اعتماد طلب السداد نهائي') !== false || strpos($line, 'جاهز للطباعة') !== false) {
            $status = 'payment_approved';
            $badge = 'سداد معتمد';
            $badgeClass = 'approved';
        } elseif (strpos($title, 'رفض طلب سداد') !== false || strpos($title, 'تم رفض طلب سداد') !== false) {
            $status = 'payment_rejected';
            $badge = 'سداد مرفوض';
            $badgeClass = 'rejected';
        }
    } elseif (strpos($text, 'deadline') !== false || strpos($text, 'مهلة') !== false || strpos($text, 'reminder') !== false || strpos($text, 'drafts.php') !== false) {
        $group = 'deadline';
        $status = 'deadline';
        $badge = 'مهلة رد';
        $badgeClass = 'deadline';
        $icon = '⏰';
    } elseif (strpos($text, 'message') !== false || strpos($text, 'messages') !== false || strpos($text, 'رسالة') !== false || strpos($text, 'رسائل') !== false) {
        $group = 'messages';
        $badge = 'رسالة';
        $badgeClass = 'message';
        $icon = '💬';
    } elseif (strpos($text, 'items') !== false || strpos($text, 'item') !== false || strpos($text, 'view_items') !== false || strpos($text, 'تكويد') !== false || strpos($text, 'الأصناف') !== false || strpos($text, 'اصناف') !== false || strpos($text, 'صنف') !== false) {
        $group = 'items';
        $badge = 'تكويد';
        $badgeClass = 'items';
        $icon = '📦';
    } elseif (strpos($text, 'rent') !== false || strpos($text, 'rents') !== false || strpos($text, 'إيجار') !== false || strpos($text, 'ايجار') !== false) {
        $group = 'rents';
        $badge = 'إيجار';
        $badgeClass = 'rents';
        $icon = '🏬';
    } elseif (strpos($text, 'contract') !== false || strpos($text, 'view_contract') !== false || strpos($text, 'عقد') !== false || strpos($text, 'العقد') !== false || strpos($text, 'العقود') !== false) {
        $group = 'contracts';
        $badge = 'عقد';
        $badgeClass = 'contracts';
        $icon = '📄';
    }

    if ($group !== 'payments' && (strpos($text, 'approved') !== false || strpos($text, 'approve') !== false || strpos($text, 'موافقة') !== false || strpos($text, 'الموافقة') !== false || strpos($text, 'اعتماد') !== false || strpos($text, 'معتمد') !== false)) {
        $status = 'approved';
        $badge = ($group === 'items') ? 'تكويد موافق' : (($group === 'rents') ? 'إيجار موافق' : (($group === 'contracts') ? 'عقد موافق' : 'موافقة'));
        $badgeClass = 'approved';
        $icon = '✅';
    }

    if ($group !== 'payments' && (strpos($text, 'rejected') !== false || strpos($text, 'reject') !== false || strpos($text, 'رفض') !== false || strpos($text, 'مرفوض') !== false || strpos($text, 'إلغاء') !== false || strpos($text, 'الغاء') !== false || strpos($text, 'سحب') !== false || strpos($text, 'withdraw') !== false)) {
        $status = 'rejected';
        $badge = ($group === 'items') ? 'تكويد مرفوض' : (($group === 'rents') ? 'إيجار مرفوض' : (($group === 'contracts') ? 'عقد مرفوض' : 'رفض'));
        $badgeClass = 'rejected';
        $icon = '❌';
    }

    if ($group === 'deadline') {
        $status = 'deadline';
        $badge = 'مهلة رد';
        $badgeClass = 'deadline';
        $icon = '⏰';
    }

    return [
        'group' => $group,
        'status' => $status,
        'badge' => $badge,
        'badge_class' => $badgeClass,
        'icon' => $icon,
        'type' => $type !== '' ? $type : $group . '_' . $status,
    ];
}

function vcDashGetNotificationsData(mysqli $conn, int $user_id): array {
    $rows = [];
    $unreadCount = 0;

    if ($user_id <= 0) {
        return ["count" => 0, "rows" => []];
    }

    vcDashEnsureNotificationsTable($conn);

    try {
        $hasNotifType = vcDashColumnExists($conn, 'notifications', 'type');
        $hasNotifRelated = vcDashColumnExists($conn, 'notifications', 'related_id');

        $selectExtra = '';
        if ($hasNotifType) {
            $selectExtra .= ", type";
        }
        if ($hasNotifRelated) {
            $selectExtra .= ", related_id";
        }

        $stmtDb = $conn->prepare("
            SELECT id, title, message, link, is_read, created_at" . $selectExtra . "
            FROM notifications
            WHERE user_id = ?
            AND is_read = 0
            ORDER BY created_at DESC, id DESC
            LIMIT 12
        ");

        if ($stmtDb) {
            $stmtDb->bind_param("i", $user_id);
            $stmtDb->execute();
            $resDb = $stmtDb->get_result();

            while ($n = $resDb->fetch_assoc()) {
                $isRead = (int)($n['is_read'] ?? 0) === 1;

                if (!$isRead) {
                    $unreadCount++;
                }

                $link = trim((string)($n['link'] ?? '#'));
                if ($link === '') {
                    $link = '#';
                }

                $title = (string)($n['title'] ?? 'إشعار');
                $line = (string)($n['message'] ?: $title);
                $href = "./notification_open.php?id=" . (int)$n['id'] . "&go=" . urlencode($link);
                $rawType = (string)($n['type'] ?? '');
                $class = $isRead ? "read" : "unread";
                $classInfo = vcDashClassifyNotification('db', $rawType, $title, $line, $href, $class);

                if (($classInfo['group'] ?? '') === 'payments') {
                    $title = vcDashCleanPaymentNotificationText($title);
                    $line = vcDashCleanPaymentNotificationText($line);
                }

                $rows[] = [
                    "kind" => "db",
                    "id" => (int)$n['id'],
                    "title" => $title,
                    "line" => $line,
                    "meta" => !empty($n['created_at']) ? date("Y-m-d H:i", strtotime($n['created_at'])) : '',
                    "class" => $class,
                    "icon" => $classInfo['icon'],
                    "href" => $href,
                    "is_read" => $isRead,
                    "type" => $classInfo['type'],
                    "group" => $classInfo['group'],
                    "status" => $classInfo['status'],
                    "badge" => $classInfo['badge'],
                    "badge_class" => $classInfo['badge_class'],
                ];
            }

            $stmtDb->close();
        }
    } catch (Throwable $e) {
        error_log("dashboard notifications fetch db error: " . $e->getMessage());
    }

    try {
        vcDashEnsureDismissalsTable($conn);

        $today = date("Y-m-d");
        $limitDate = date("Y-m-d", strtotime("+2 days"));

        $stmt = $conn->prepare("
            SELECT c.id, c.supplier_name, c.reminder_date, c.created_at
            FROM contracts c
            LEFT JOIN notification_dismissals nd
                ON nd.user_id = ?
                AND nd.notif_type = 'deadline'
                AND nd.ref_id = c.id
            WHERE c.status = 'draft'
            AND c.created_by = ?
            AND nd.id IS NULL
            AND (
                (
                    c.reminder_date IS NOT NULL
                    AND c.reminder_date != '0000-00-00'
                    AND c.reminder_date <= ?
                )
                OR
                (
                    (c.reminder_date IS NULL OR c.reminder_date = '0000-00-00')
                    AND DATE_ADD(DATE(c.created_at), INTERVAL 4 DAY) <= ?
                )
            )
            ORDER BY 
                COALESCE(NULLIF(c.reminder_date, '0000-00-00'), DATE_ADD(DATE(c.created_at), INTERVAL 4 DAY)) ASC,
                c.id DESC
            LIMIT 8
        ");

        if ($stmt) {
            $stmt->bind_param("iiss", $user_id, $user_id, $limitDate, $limitDate);
            $stmt->execute();
            $res_notif = $stmt->get_result();

            $todayObj = new DateTime($today);

            while ($row = $res_notif->fetch_assoc()) {
                $reminder = $row['reminder_date'] ?? '';

                if (empty($reminder) || $reminder === '0000-00-00') {
                    $reminder = !empty($row['created_at'])
                        ? date("Y-m-d", strtotime($row['created_at'] . " +4 days"))
                        : date("Y-m-d", strtotime("+4 days"));
                }

                $reminderObj = new DateTime($reminder);
                $daysLeft = (int)$todayObj->diff($reminderObj)->format("%r%a");

                if ($daysLeft > 2) {
                    continue;
                }

                if ($daysLeft === 2) {
                    $label = "باقى يومين";
                    $class = "two-days";
                    $icon = "🟡";
                } elseif ($daysLeft === 1) {
                    $label = "باقى يوم واحد";
                    $class = "one-day";
                    $icon = "🟠";
                } elseif ($daysLeft === 0) {
                    $label = "اليوم آخر مهلة";
                    $class = "today";
                    $icon = "🔴";
                } else {
                    $label = "متأخر " . abs($daysLeft) . " يوم";
                    $class = "late";
                    $icon = "🔴";
                }

                $unreadCount++;

                $href = "./notification_open.php?reminder_id=" . (int)$row['id'] . "&go=" . urlencode("drafts.php");
                $line = "عقد #" . (int)$row['id'] . " — " . ($row['supplier_name'] ?? "-");

                $rows[] = [
                    "kind" => "reminder",
                    "id" => (int)$row['id'],
                    "title" => "مهلة الرد",
                    "line" => $line,
                    "meta" => $label . " · " . $reminder,
                    "class" => $class,
                    "icon" => "⏰",
                    "href" => $href,
                    "is_read" => false,
                    "type" => "contract_deadline",
                    "group" => "deadline",
                    "status" => "deadline",
                    "badge" => "مهلة رد",
                    "badge_class" => "deadline",
                ];
            }

            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log("dashboard notifications fetch reminders error: " . $e->getMessage());
    }

    return ["count" => $unreadCount, "rows" => $rows];
}

function vcDashRenderNotificationsHtml(array $rows): string {
    ob_start();

    if (!empty($rows)):
        foreach ($rows as $n):
            $title = trim((string)($n['title'] ?? 'إشعار'));
            if ($title === '') {
                $title = 'إشعار';
            }

            $line = trim((string)($n['line'] ?? ''));
            $meta = trim((string)($n['meta'] ?? ''));
            $badge = trim((string)($n['badge'] ?? 'إشعار'));
            $badgeClass = trim((string)($n['badge_class'] ?? 'general'));
            $icon = trim((string)($n['icon'] ?? '🔔'));
            ?>
            <a class="dash-notif-item <?= e($n['class'] ?? '') ?>" href="<?= e($n['href'] ?? '#') ?>">
                <div class="dash-notif-icon dash-notif-icon-<?= e($badgeClass) ?>"><?= e($icon) ?></div>

                <div class="dash-notif-content">
                    <div class="dash-notif-headline">
                        <div class="dash-notif-title-text"><?= e($title) ?></div>
                        <span class="dash-notif-badge dash-notif-badge-<?= e($badgeClass) ?>"><?= e($badge) ?></span>
                    </div>

                    <?php if($line !== ''): ?>
                        <div class="dash-notif-line"><?= e($line) ?></div>
                    <?php endif; ?>

                    <?php if($meta !== ''): ?>
                        <div class="dash-notif-meta"><?= e($meta) ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php
        endforeach;
    else:
        ?>
        <div class="dash-notif-empty">✅ لا توجد إشعارات</div>
        <?php
    endif;

    return ob_get_clean();
}


$dashNotifData = vcDashGetNotificationsData($conn, $uid);
$dashNotifications = (int)($dashNotifData['count'] ?? 0);
$dashNotifRows = $dashNotifData['rows'] ?? [];

/* ================= MESSAGES UNREAD COUNT ================= */
function vcDashGetMessagesUnreadCount(mysqli $conn, int $user_id): int {
    if ($user_id <= 0) {
        return 0;
    }

    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS vc_message_threads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM('private','broadcast') NOT NULL DEFAULT 'private',
                title VARCHAR(255) NULL,
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                last_message_at DATETIME NULL,
                INDEX idx_thread_type (type),
                INDEX idx_thread_created_by (created_by),
                INDEX idx_thread_last (last_message_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS vc_message_thread_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                thread_id INT NOT NULL,
                user_id INT NOT NULL,
                last_read_at DATETIME NULL,
                joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_thread_user (thread_id, user_id),
                INDEX idx_member_user (user_id),
                INDEX idx_member_thread (thread_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $conn->query("
            CREATE TABLE IF NOT EXISTS vc_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                thread_id INT NOT NULL,
                sender_id INT NOT NULL,
                body TEXT NULL,
                attachment_path VARCHAR(500) NULL,
                attachment_name VARCHAR(255) NULL,
                attachment_type VARCHAR(80) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                INDEX idx_msg_thread (thread_id, id),
                INDEX idx_msg_sender (sender_id),
                INDEX idx_msg_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM vc_messages msg
            INNER JOIN vc_message_thread_members mem
                ON mem.thread_id = msg.thread_id
                AND mem.user_id = ?
            WHERE msg.sender_id <> ?
            AND msg.deleted_at IS NULL
            AND (
                mem.last_read_at IS NULL
                OR msg.created_at > mem.last_read_at
            )
        ");

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['c'] ?? 0);
    } catch (Throwable $e) {
        error_log("dashboard messages unread count error: " . $e->getMessage());
        return 0;
    }
}

$dashMessagesUnread = vcDashGetMessagesUnreadCount($conn, $uid);

function hasPage($pages, $pageName){
    return in_array($pageName, $pages, true);
}

/* ================= COUNTS ================= */
$countParams = [];
$countTypes = '';
$countWhere = vcDashApplyScopeWhere('created_by', $dashboardScopedUserIds, $countParams, $countTypes);
$contracts = vcDashRunCount($conn, "SELECT COUNT(*) c FROM contracts WHERE 1=1 " . $countWhere, $countTypes, $countParams);
$suppliers = countTable($conn,"suppliers");
$users     = $dashboardIsAllScope ? countTable($conn,"users") : count($dashboardScopedUserIds);

/* ================= REVIEW ================= */
$reviewParams = [];
$reviewTypes = '';
$reviewWhere = vcDashApplyScopeWhere('created_by', $dashboardScopedUserIds, $reviewParams, $reviewTypes);
$review_count = vcDashRunCount($conn, "SELECT COUNT(*) c FROM contracts WHERE status='review' " . $reviewWhere, $reviewTypes, $reviewParams);

/* ================= ADMIN MAIN DONUT ANALYTICS ================= */
$admin_marketing_total = 0.0;
$admin_rental_total = 0.0;
$admin_contract_value_total = 0.0;

$admin_value_segments = [];
$admin_value_donut = "rgba(109,74,255,.14) 0deg 360deg";

$admin_branch_rent_segments = [];
$admin_branch_rent_donut = "rgba(109,74,255,.14) 0deg 360deg";
$admin_branch_rent_total = 0.0;

$admin_annual_user_segments = [];
$admin_annual_user_donut = "rgba(109,74,255,.14) 0deg 360deg";
$admin_annual_approved_total = 0;

$admin_rent_user_segments = [];
$admin_rent_user_donut = "rgba(109,74,255,.14) 0deg 360deg";
$admin_rent_approved_total = 0;

if ($show_management_analytics) {

    $mgmtParams = [];
    $mgmtTypes = '';
    $mgmtWhere = vcDashApplyScopeWhere('c.created_by', $dashboardScopedUserIds, $mgmtParams, $mgmtTypes);

    $admin_marketing_total = fetchDashboardOneValue($conn, "
        SELECT COALESCE(SUM(e.value), 0) AS total
        FROM events e
        INNER JOIN contracts c ON c.id = e.contract_id
        WHERE c.status = 'approved'
          AND (c.status IS NULL OR c.status <> 'deleted')
          {$mgmtWhere}
    ", 'total', $mgmtTypes, $mgmtParams);

    $admin_rental_total = fetchDashboardOneValue($conn, "
        SELECT COALESCE(SUM(r.total), 0) AS total
        FROM rents r
        INNER JOIN contracts c ON c.id = r.contract_id
        WHERE c.status = 'approved'
          AND r.contract_id IS NOT NULL
          AND r.contract_id > 0
          {$mgmtWhere}
    ", 'total', $mgmtTypes, $mgmtParams);

    $admin_contract_value_total = $admin_marketing_total + $admin_rental_total;

    $admin_value_segments = [
        [
            'label' => 'البنود التسويقية',
            'value' => $admin_marketing_total,
            'display' => moneyFull($admin_marketing_total) . ' ريال',
            'color' => '#47e6a1'
        ],
        [
            'label' => 'البنود الإيجارية',
            'value' => $admin_rental_total,
            'display' => moneyFull($admin_rental_total) . ' ريال',
            'color' => '#6bb7ff'
        ],
    ];
    $admin_value_donut = donutGradientValue($admin_value_segments);

    $branchRows = fetchDashboardRowsPrepared($conn, "
        SELECT 
            COALESCE(NULLIF(TRIM(r.branch), ''), 'غير محدد') AS label,
            COALESCE(SUM(r.total), 0) AS value
        FROM rents r
        INNER JOIN contracts c ON c.id = r.contract_id
        WHERE c.status = 'approved'
          AND r.contract_id IS NOT NULL
          AND r.contract_id > 0
          {$mgmtWhere}
        GROUP BY COALESCE(NULLIF(TRIM(r.branch), ''), 'غير محدد')
        ORDER BY value DESC
    ", $mgmtTypes, $mgmtParams);

    $admin_branch_rent_segments = buildDashboardSegments($branchRows, true, 5);
    $admin_branch_rent_donut = donutGradientValue($admin_branch_rent_segments);
    foreach ($admin_branch_rent_segments as $seg) {
        $admin_branch_rent_total += (float)$seg['value'];
    }

    $annualUserRows = fetchDashboardRowsPrepared($conn, "
        SELECT 
            COALESCE(u.username, 'غير محدد') AS label,
            COUNT(*) AS value
        FROM contracts c
        LEFT JOIN users u ON u.id = c.created_by
        WHERE c.status = 'approved'
          AND (c.source IS NULL OR c.source <> 'rent')
          {$mgmtWhere}
        GROUP BY c.created_by, u.username
        ORDER BY value DESC
    ", $mgmtTypes, $mgmtParams);

    $admin_annual_user_segments = buildDashboardSegments($annualUserRows, false, 5);
    $admin_annual_user_donut = donutGradientValue($admin_annual_user_segments);
    foreach ($admin_annual_user_segments as $seg) {
        $admin_annual_approved_total += (int)$seg['value'];
    }

    $rentUserRows = fetchDashboardRowsPrepared($conn, "
        SELECT 
            COALESCE(u.username, 'غير محدد') AS label,
            COUNT(*) AS value
        FROM contracts c
        LEFT JOIN users u ON u.id = c.created_by
        WHERE c.status = 'approved'
          AND c.source = 'rent'
          {$mgmtWhere}
        GROUP BY c.created_by, u.username
        ORDER BY value DESC
    ", $mgmtTypes, $mgmtParams);

    $admin_rent_user_segments = buildDashboardSegments($rentUserRows, false, 5);
    $admin_rent_user_donut = donutGradientValue($admin_rent_user_segments);
    foreach ($admin_rent_user_segments as $seg) {
        $admin_rent_approved_total += (int)$seg['value'];
    }
}

/* ================= MY CONTRACTS ================= */
$stmt = $conn->prepare("
SELECT 
SUM(status='approved') approved,
SUM(status='rejected') rejected
FROM contracts
WHERE created_by = ?
");
$stmt->bind_param("i", $uid);
$stmt->execute();
$myContracts = $stmt->get_result()->fetch_assoc();
$stmt->close();

$approved = (int)($myContracts['approved'] ?? 0);
$rejected = (int)($myContracts['rejected'] ?? 0);

/* ================= PAGES ================= */
if($is_admin){
    $stmt = $conn->prepare("
        SELECT 
            p.name, 
            p.title, 
            p.icon, 
            p.section,
            COALESCE(u.sort_order, p.sort_order) AS final_order
        FROM pages p
        LEFT JOIN user_page_order u 
            ON p.name = u.page_name AND u.user_id = ?
        WHERE p.status = 1
        ORDER BY p.section, final_order ASC
    ");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $result = $stmt->get_result();
}else{
    $stmt = $conn->prepare("
        SELECT 
            p.name, 
            p.title, 
            p.icon, 
            p.section,
            COALESCE(u.sort_order, p.sort_order) AS final_order
        FROM user_permissions up
        JOIN pages p ON up.page_id = p.id
        LEFT JOIN user_page_order u 
            ON p.name = u.page_name AND u.user_id = ?
        WHERE up.user_id = ? AND p.status = 1
        ORDER BY p.section, final_order ASC
    ");

    $stmt->bind_param("ii", $uid, $uid);
    $stmt->execute();
    $result = $stmt->get_result();
}

$contracts_group = [];
$rents_group = [];
$items_group = [];
$finance_group = [];
$admin_group = [];

while($row = $result->fetch_assoc()){
    if($row['section'] == 'contracts'){
        $contracts_group[] = $row;
    }
    elseif($row['section'] == 'rents'){
        $rents_group[] = $row;
    }
    elseif($row['section'] == 'items'){
        $items_group[] = $row;
    }
    elseif($row['section'] == 'admin'){
        $admin_group[] = $row;
    }
    else{
        $finance_group[] = $row;
    }
}

$stmt->close();

$erp_modules = array_values(array_filter([
    [
        'id' => 'contracts',
        'title' => 'الموردين والعقود',
        'subtitle' => 'Procurement & Contracts',
        'icon' => 'ri-file-text-line',
        'items' => $contracts_group,
    ],
    [
        'id' => 'rents',
        'title' => 'العقارات والإيجارات',
        'subtitle' => 'Lease Management',
        'icon' => 'ri-building-2-line',
        'items' => $rents_group,
    ],
    [
        'id' => 'items',
        'title' => 'المخزون والأصناف',
        'subtitle' => 'Inventory & SKU',
        'icon' => 'ri-barcode-box-line',
        'items' => $items_group,
    ],
    [
        'id' => 'finance',
        'title' => 'المالية والمحاسبة',
        'subtitle' => 'Finance & AP',
        'icon' => 'ri-bank-card-line',
        'items' => $finance_group,
    ],
    [
        'id' => 'admin',
        'title' => 'النظام والإعدادات',
        'subtitle' => 'System Admin',
        'icon' => 'ri-settings-3-line',
        'items' => $admin_group,
    ],
], static fn(array $module): bool => !empty($module['items'])));

/* ================= USER PERMISSION PAGES ================= */
$user_pages = [];

foreach ([$contracts_group, $rents_group, $items_group, $finance_group, $admin_group] as $group) {
    foreach ($group as $p) {
        if (!empty($p['name'])) {
            $user_pages[] = $p['name'];
        }
    }
}

$can_add_contract = $is_admin || hasPage($user_pages, 'add_contract');
$can_add_rent     = $is_admin || hasPage($user_pages, 'rents') || hasPage($user_pages, 'add_rent') || hasPage($user_pages, 'add_rents');

$has_data_entry_page = hasPage($user_pages, 'data_entry_items');
$direct_data_entry_users = vcDashGetDirectManagedDataEntryUserIds($conn, $uid);

/*
    فصلنا بين كارتين مختلفين:
    1) أصناف للتكويد: يظهر حسب صلاحيات التكويد/طلباتي/المراجعة المعتادة.
    2) إدخال الأصناف: يظهر فقط لمدخل الأصناف نفسه، أو مديره المباشر، أو الأدمن.
*/
$can_items_coding = $is_admin
    || hasPage($user_pages, 'add_items')
    || hasPage($user_pages, 'my_items')
    || hasPage($user_pages, 'items_admin')
    || hasPage($user_pages, 'under_review_items')
    || hasPage($user_pages, 'view_items');

$can_item_entry = $is_admin || $has_data_entry_page || !empty($direct_data_entry_users);

$item_entry_visible_ids = [];
if (!$is_admin) {
    if ($has_data_entry_page) {
        $item_entry_visible_ids[] = $uid;
    }
    foreach ($direct_data_entry_users as $entryUserId) {
        $item_entry_visible_ids[] = (int)$entryUserId;
    }
    $item_entry_visible_ids = array_values(array_unique(array_filter($item_entry_visible_ids, function($v){ return (int)$v > 0; })));
}

/* ================= SIDEBAR DONUT ANALYTICS ================= */

/*
    عقود سنوية:
    - للمستخدم العادي: عقوده هو فقط created_by = ?
    - للأدمن: كل العقود
    - استبعدنا العقود اللي ليها إيجارات في جدول rents عشان دي تبقى في دائرة عقود الإيجار
    - الحالات المطلوبة: تفاوض / مراجعة / مرفوض
    - التفاوض = draft أو الحالة الفاضية القديمة
*/
$annual_total = 0;
$annual_approved = 0;
$annual_negotiation = 0;
$annual_review = 0;
$annual_rejected = 0;

if ($can_add_contract) {
    $annualParams = [];
    $annualTypes = '';
    $annualScopeWhere = vcDashApplyScopeWhere('c.created_by', $dashboardScopedUserIds, $annualParams, $annualTypes);

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) total,
            SUM(status='approved') approved,
            SUM(status='draft' OR status='' OR status IS NULL) negotiation,
            SUM(status='review') review,
            SUM(status='rejected') rejected
        FROM contracts c
        WHERE (c.status IS NULL OR c.status <> 'deleted')
          AND (c.source IS NULL OR c.source <> 'rent')
          {$annualScopeWhere}
    ");
    if ($annualTypes !== '') {
        $stmt->bind_param($annualTypes, ...$annualParams);
    }
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $annual_total       = (int)($row['total'] ?? 0);
    $annual_approved    = (int)($row['approved'] ?? 0);
    $annual_negotiation = (int)($row['negotiation'] ?? 0);
    $annual_review      = (int)($row['review'] ?? 0);
    $annual_rejected    = (int)($row['rejected'] ?? 0);
}

$annual_donut = donutGradient([
    ['value'=>$annual_approved,    'color'=>'#47e6a1'],
    ['value'=>$annual_negotiation, 'color'=>'#ffd166'],
    ['value'=>$annual_review,      'color'=>'#6bb7ff'],
    ['value'=>$annual_rejected,    'color'=>'#ff6b8a'],
]);

/*
    أصناف للتكويد:
    - إجمالي الطلبات من المستخدم = items.created_by
    - موافق عليها / مرفوض / تحت المراجعة من status
*/
$coding_total = 0;
$coding_approved = 0;
$coding_rejected = 0;
$coding_review = 0;

if ($can_items_coding) {
    $codingParams = [];
    $codingTypes = '';
    $codingScopeWhere = vcDashApplyScopeWhere('created_by', $dashboardScopedUserIds, $codingParams, $codingTypes);

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) total,
            SUM(status='approved') approved,
            SUM(status='rejected') rejected,
            SUM(status='review' OR status='' OR status IS NULL) review
        FROM items
        WHERE 1=1
        {$codingScopeWhere}
    ");
    if ($codingTypes !== '') {
        $stmt->bind_param($codingTypes, ...$codingParams);
    }
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $coding_total    = (int)($row['total'] ?? 0);
    $coding_approved = (int)($row['approved'] ?? 0);
    $coding_rejected = (int)($row['rejected'] ?? 0);
    $coding_review   = (int)($row['review'] ?? 0);
}

$coding_donut = donutGradient([
    ['value'=>$coding_approved, 'color'=>'#47e6a1'],
    ['value'=>$coding_rejected, 'color'=>'#ff6b8a'],
    ['value'=>$coding_review,   'color'=>'#6bb7ff'],
]);

/*
    عقود الإيجار:
    - العقود اللي عملها المستخدم وليها صفوف في rents
    - مكتمل = approved
    - مرفوض = rejected
    - مراجعة = review
*/
$rent_total = 0;
$rent_completed = 0;
$rent_rejected = 0;
$rent_review = 0;

if ($can_add_contract && $can_add_rent) {
    $rentParams = [];
    $rentTypes = '';
    $rentScopeWhere = vcDashApplyScopeWhere('c.created_by', $dashboardScopedUserIds, $rentParams, $rentTypes);

    $stmt = $conn->prepare("
        SELECT
            COUNT(DISTINCT c.id) total,
            COUNT(DISTINCT CASE WHEN c.status='approved' THEN c.id END) completed,
            COUNT(DISTINCT CASE WHEN c.status='rejected' THEN c.id END) rejected,
            COUNT(DISTINCT CASE WHEN c.status='review' THEN c.id END) review
        FROM contracts c
        WHERE (c.status IS NULL OR c.status <> 'deleted')
          AND c.source = 'rent'
          {$rentScopeWhere}
    ");
    if ($rentTypes !== '') {
        $stmt->bind_param($rentTypes, ...$rentParams);
    }
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $rent_total     = (int)($row['total'] ?? 0);
    $rent_completed = (int)($row['completed'] ?? 0);
    $rent_rejected  = (int)($row['rejected'] ?? 0);
    $rent_review    = (int)($row['review'] ?? 0);
}

$rent_donut = donutGradient([
    ['value'=>$rent_completed, 'color'=>'#47e6a1'],
    ['value'=>$rent_rejected,  'color'=>'#ff6b8a'],
    ['value'=>$rent_review,    'color'=>'#6bb7ff'],
]);

/*
    إدخال الأصناف:
    - للأدمن / المدير التجاري: تم إدخاله / لم يتم إدخاله
    - لمدخل الأصناف أو مديره: بواسطتي / باقي المدخلين
*/
$item_total_approved = 0;
$item_by_me = 0;
$item_others = 0;

$item_entry_label_1 = 'بواسطتي';
$item_entry_label_2 = 'باقي المدخلين';

if ($can_item_entry) {
    if ($dashboardIsAllScope) {

        $item_entry_label_1 = 'تم إدخاله';
        $item_entry_label_2 = 'لم يتم إدخاله';

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) total_approved,
                SUM(CASE WHEN entry_done=1 AND entered_by IS NOT NULL THEN 1 ELSE 0 END) by_me,
                SUM(CASE WHEN entry_done=0 OR entry_done IS NULL OR entered_by IS NULL THEN 1 ELSE 0 END) others
            FROM items
            WHERE status='approved'
        ");
        $stmt->execute();
    } else {
        $entryIds = !empty($item_entry_visible_ids) ? $item_entry_visible_ids : [$uid];
        $entryParams = [];
        $entryTypes = '';
        $entryWhere = vcDashApplyScopeWhere('entered_by', $entryIds, $entryParams, $entryTypes);
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) total_approved,
                SUM(status='approved' AND entry_done=1 {$entryWhere}) by_me,
                SUM(status='approved' AND entry_done=1 AND entered_by IS NOT NULL AND NOT (entered_by IN (" . implode(',', array_fill(0, count($entryIds), '?')) . "))) others
            FROM items
            WHERE status='approved'
        ");
        $allParams = array_merge($entryParams, $entryIds);
        $allTypes = $entryTypes . str_repeat('i', count($entryIds));
        if ($allTypes !== '') {
            $stmt->bind_param($allTypes, ...$allParams);
        }
        $stmt->execute();
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $item_total_approved = (int)($row['total_approved'] ?? 0);
    $item_by_me          = (int)($row['by_me'] ?? 0);
    $item_others         = (int)($row['others'] ?? 0);
}

$item_donut = donutGradient([
    ['value'=>$item_by_me,  'color'=>'#47e6a1'],
    ['value'=>$item_others, 'color'=>'#6bb7ff'],
]);

/* ================= FINANCE MANAGER DASHBOARD SIDE ANALYTICS ================= */
if (!function_exists('vcDashTableExists')) {
    function vcDashTableExists(mysqli $conn, string $table): bool {
        try {
            $stmt = $conn->prepare("\n                SELECT COUNT(*) AS c\n                FROM INFORMATION_SCHEMA.TABLES\n                WHERE TABLE_SCHEMA = DATABASE()\n                AND TABLE_NAME = ?\n            ");
            if (!$stmt) return false;
            $stmt->bind_param("s", $table);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return !empty($row) && (int)($row['c'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

$isFinanceDashboard = false;
try {
    $isFinanceDashboard = (
        (string)($user['job_role'] ?? '') === 'finance_manager'
        || $displayRole === 'مدير مالي'
        || ((int)$uid === (int)($financeManagerIdForDisplay ?? 0) && (int)$uid > 0)
    );
} catch (Throwable $e) {
    $isFinanceDashboard = false;
}

$finance_payment_total = 0;
$finance_payment_active = 0;
$finance_payment_pending_finance = 0;
$finance_payment_approved = 0;
$finance_payment_rejected = 0;
$finance_payment_ready_print = 0;
$finance_payment_donut = donutGradient([]);

$finance_accountant_segments = [];
$finance_accountant_total = 0;
$finance_accountant_donut = donutGradient([]);

$finance_section_food = 0;
$finance_section_non_food = 0;
$finance_section_other = 0;
$finance_section_total = 0;
$finance_section_donut = donutGradient([]);

$finance_amount_requested = 0.0;
$finance_amount_approved = 0.0;
$finance_amount_discount = 0.0;
$finance_amount_donut = donutGradientValue([]);

if ($isFinanceDashboard && vcDashTableExists($conn, 'payment_requests')) {
    try {
        $hasStatus = vcDashColumnExists($conn, 'payment_requests', 'status');
        $hasCreatedBy = vcDashColumnExists($conn, 'payment_requests', 'created_by');
        $hasCompanyType = vcDashColumnExists($conn, 'payment_requests', 'company_type');
        $hasAmountRequired = vcDashColumnExists($conn, 'payment_requests', 'amount_required');
        $hasFinalAmount = vcDashColumnExists($conn, 'payment_requests', 'final_amount');

        if ($hasStatus) {
            $q = $conn->query("\n                SELECT\n                    COUNT(*) AS total,\n                    SUM(CASE WHEN status IN ('pending_section_manager','pending_commercial_manager','pending_finance_manager') THEN 1 ELSE 0 END) AS active,\n                    SUM(CASE WHEN status = 'pending_finance_manager' THEN 1 ELSE 0 END) AS pending_finance,\n                    SUM(CASE WHEN status = 'approved_final' THEN 1 ELSE 0 END) AS approved_final,\n                    SUM(CASE WHEN status IN ('rejected_section_manager','rejected_commercial_manager','rejected_finance_manager') THEN 1 ELSE 0 END) AS rejected,\n                    SUM(CASE WHEN status = 'approved_final' THEN 1 ELSE 0 END) AS ready_print\n                FROM payment_requests\n            ");
            if ($q) {
                $r = $q->fetch_assoc() ?: [];
                $finance_payment_total = (int)($r['total'] ?? 0);
                $finance_payment_active = (int)($r['active'] ?? 0);
                $finance_payment_pending_finance = (int)($r['pending_finance'] ?? 0);
                $finance_payment_approved = (int)($r['approved_final'] ?? 0);
                $finance_payment_rejected = (int)($r['rejected'] ?? 0);
                $finance_payment_ready_print = (int)($r['ready_print'] ?? 0);
            }
        } else {
            $finance_payment_total = vcDashRunCount($conn, "SELECT COUNT(*) c FROM payment_requests");
        }

        $finance_payment_donut = donutGradient([
            ['value'=>$finance_payment_pending_finance, 'color'=>'#6bb7ff'],
            ['value'=>$finance_payment_approved, 'color'=>'#47e6a1'],
            ['value'=>$finance_payment_rejected, 'color'=>'#ff6b8a'],
            ['value'=>$finance_payment_ready_print, 'color'=>'#a78bfa'],
        ]);

        if ($hasCreatedBy && vcDashTableExists($conn, 'users')) {
            $rows = fetchDashboardRows($conn, "\n                SELECT COALESCE(NULLIF(TRIM(u.username), ''), 'غير محدد') AS label, COUNT(*) AS value\n                FROM payment_requests pr\n                LEFT JOIN users u ON u.id = pr.created_by\n                GROUP BY pr.created_by, u.username\n                ORDER BY value DESC\n                LIMIT 4\n            ");
            $finance_accountant_segments = buildDashboardSegments($rows, false, 4);
            foreach ($finance_accountant_segments as $seg) { $finance_accountant_total += (int)($seg['value'] ?? 0); }
            $finance_accountant_donut = donutGradientValue($finance_accountant_segments);
        }

        if ($hasCompanyType) {
            $q = $conn->query("\n                SELECT\n                    SUM(CASE WHEN company_type='food' THEN 1 ELSE 0 END) AS food,\n                    SUM(CASE WHEN company_type='non_food' THEN 1 ELSE 0 END) AS non_food,\n                    SUM(CASE WHEN company_type NOT IN ('food','non_food') OR company_type IS NULL OR company_type='' THEN 1 ELSE 0 END) AS other,\n                    COUNT(*) AS total\n                FROM payment_requests\n            ");
            if ($q) {
                $r = $q->fetch_assoc() ?: [];
                $finance_section_food = (int)($r['food'] ?? 0);
                $finance_section_non_food = (int)($r['non_food'] ?? 0);
                $finance_section_other = (int)($r['other'] ?? 0);
                $finance_section_total = (int)($r['total'] ?? 0);
            }
        }
        $finance_section_donut = donutGradient([
            ['value'=>$finance_section_food, 'color'=>'#47e6a1'],
            ['value'=>$finance_section_non_food, 'color'=>'#6bb7ff'],
            ['value'=>$finance_section_other, 'color'=>'#ffd166'],
        ]);

        if ($hasAmountRequired) {
            $finalExpr = $hasFinalAmount ? 'COALESCE(final_amount,0)' : '0';
            $q = $conn->query("\n                SELECT\n                    COALESCE(SUM(amount_required),0) AS requested_total,\n                    COALESCE(SUM(CASE WHEN status='approved_final' THEN {$finalExpr} ELSE 0 END),0) AS approved_total,\n                    COALESCE(SUM(CASE WHEN status='approved_final' THEN GREATEST(COALESCE(amount_required,0) - {$finalExpr}, 0) ELSE 0 END),0) AS discount_total\n                FROM payment_requests\n            ");
            if ($q) {
                $r = $q->fetch_assoc() ?: [];
                $finance_amount_requested = (float)($r['requested_total'] ?? 0);
                $finance_amount_approved = (float)($r['approved_total'] ?? 0);
                $finance_amount_discount = (float)($r['discount_total'] ?? 0);
            }
        }
        $finance_amount_donut = donutGradientValue([
            ['label'=>'المطلوب', 'value'=>$finance_amount_requested, 'color'=>'#6bb7ff'],
            ['label'=>'المعتمد', 'value'=>$finance_amount_approved, 'color'=>'#47e6a1'],
            ['label'=>'فرق السداد', 'value'=>$finance_amount_discount, 'color'=>'#a78bfa'],
        ]);
    } catch (Throwable $e) {
        error_log('finance dashboard side analytics error: ' . $e->getMessage());
    }
}

function vcDashFindPageHref(array $group, array $preferred = []): ?string {
    $names = array_column($group, 'name');
    foreach ($preferred as $pageName) {
        if (in_array($pageName, $names, true)) {
            return $pageName . '.php';
        }
    }
    $first = trim((string)($group[0]['name'] ?? ''));
    return $first !== '' ? $first . '.php' : null;
}

$dashboard_kpis = [];

if ($can_add_contract || $is_admin) {
    $dashboard_kpis[] = [
        'icon' => 'ri-file-list-3-line',
        'tone' => 'indigo',
        'value' => number_format($contracts),
        'label' => 'إجمالي العقود',
        'meta' => $annual_review > 0 ? ($annual_review . ' قيد المراجعة') : 'ضمن نطاق صلاحياتك',
        'href' => vcDashFindPageHref($contracts_group, ['contracts', 'my_contracts', 'add_contract']),
    ];

    if ($review_count > 0) {
        $dashboard_kpis[] = [
            'icon' => 'ri-search-eye-line',
            'tone' => 'amber',
            'value' => number_format($review_count),
            'label' => 'عقود للمراجعة',
            'meta' => 'تحتاج إجراء',
            'href' => vcDashFindPageHref($contracts_group, ['under_review_contracts', 'contracts', 'my_contracts']),
        ];
    }
}

if ($can_add_rent && ($rent_total > 0 || $can_add_contract)) {
    $dashboard_kpis[] = [
        'icon' => 'ri-building-2-line',
        'tone' => 'sky',
        'value' => number_format($rent_total),
        'label' => 'عقود الإيجار',
        'meta' => $rent_review > 0 ? ($rent_review . ' قيد المراجعة') : 'عقود إيجار مسجّلة',
        'href' => vcDashFindPageHref($rents_group, ['rents', 'add_rent', 'add_rents']),
    ];
}

if ($can_items_coding) {
    $dashboard_kpis[] = [
        'icon' => 'ri-barcode-box-line',
        'tone' => 'violet',
        'value' => number_format($coding_total),
        'label' => 'طلبات الأصناف',
        'meta' => $coding_review > 0 ? ($coding_review . ' تحت المراجعة') : 'للتكويد والمتابعة',
        'href' => vcDashFindPageHref($items_group, ['my_items', 'add_items', 'items_admin']),
    ];
}

if ($isFinanceDashboard && $finance_payment_total > 0) {
    $dashboard_kpis[] = [
        'icon' => 'ri-bank-card-line',
        'tone' => 'emerald',
        'value' => number_format($finance_payment_total),
        'label' => 'طلبات السداد',
        'meta' => $finance_payment_pending_finance > 0 ? ($finance_payment_pending_finance . ' بانتظار المالي') : 'إجمالي الطلبات',
        'href' => vcDashFindPageHref($finance_group, ['payment_requests', 'payment_approvals', 'payments']),
    ];
}

if ($dashNotifications > 0) {
    $dashboard_kpis[] = [
        'icon' => 'ri-notification-3-line',
        'tone' => 'rose',
        'value' => number_format($dashNotifications),
        'label' => 'إشعارات',
        'meta' => 'غير مقروءة',
        'href' => null,
    ];
}

if (hasPage($user_pages, 'messages')) {
    $dashboard_kpis[] = [
        'icon' => 'ri-message-3-line',
        'tone' => 'cyan',
        'value' => number_format($dashMessagesUnread),
        'label' => 'رسائل',
        'meta' => $dashMessagesUnread > 0 ? 'غير مقروءة' : 'لا رسائل جديدة',
        'href' => 'messages.php',
    ];
}

$dashboard_kpis = array_slice($dashboard_kpis, 0, 6);

$dashboard_today = date('Y/m/d');

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="icon" href="https://vendorcore.online/uploads/vendorcore_favicon.ico?v=31" type="image/x-icon">
<link rel="shortcut icon" href="https://vendorcore.online/uploads/vendorcore_favicon.ico?v=31" type="image/x-icon">
<link rel="apple-touch-icon" href="https://vendorcore.online/uploads/vendorcore_favicon_extra_big_180.png?v=31">

<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">

<style>
*{
    box-sizing:border-box;
    font-family:'Cairo',sans-serif;
}

html, body{
    min-height:100%;
}

body{
    margin:0;
    background:
        radial-gradient(circle at top right, rgba(109,74,255,.12), transparent 35%),
        #eef1f7;
    color:#172033;
}

.container{
    display:flex;
    align-items:stretch;
    min-height:100vh;
}

/* sidebar */
.sidebar{
    width:292px;
    background:linear-gradient(180deg,#5b21b6 0%,#4f46e5 42%,#4338ca 100%);
    color:#fff;
    padding:16px 12px 18px;
    transition:.3s;
    position:relative;
    overflow:visible;
    flex-shrink:0;
    box-shadow:0 0 30px rgba(79,70,229,.25);
    min-height:100vh;
    display:flex;
    flex-direction:column;
}

.sidebar.closed{
    width:78px;
}

.toggle-btn{
    position:absolute;
    left:-15px;
    top:95px;
    transform:none;
    background:#fff;
    color:#4f46e5;
    border-radius:50%;
    width:32px;
    height:32px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    box-shadow:0 5px 12px rgba(0,0,0,.2);
    z-index:10;
}

.sidebar.closed .toggle-btn i{
    transform:rotate(180deg);
}

.logo-box{
    width:46px;
    height:46px;
    border-radius:16px;
    background:rgba(255,255,255,.16);
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:14px;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.25);
}

.logo-box i{
    font-size:24px;
}

.avatar{
    width:76px;
    height:76px;
    border-radius:50%;
    background:
        radial-gradient(circle at 30% 18%, rgba(255,255,255,.55), transparent 22%),
        linear-gradient(145deg,#7c5cff,#4f46e5);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:29px;
    font-weight:900;
    color:#fff;
    box-shadow:
        0 12px 28px rgba(0,0,0,0.25),
        inset 0 2px 5px rgba(255,255,255,0.3),
        inset 0 -3px 6px rgba(0,0,0,0.2);
    border:3px solid rgba(255,255,255,.28);
    flex-shrink:0;
}

.username{
    margin-top:10px;
    font-weight:900;
    font-size:14px;
    color:#fff;
    text-align:center;
    width:100%;
    line-height:1.5;
    word-break:break-word;
}

.role{
    margin-top:4px;
    font-size:11px;
    color:rgba(255,255,255,.75);
    font-weight:700;
}

.logout-btn{
    margin-top:16px;
    width:100%;
    height:38px;
    border-radius:13px;
    background:rgba(255,255,255,.14);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    text-decoration:none;
    font-weight:800;
    font-size:13px;
    transition:.2s;
}

.logout-btn:hover{
    background:rgba(255,255,255,.22);
}

.sidebar.closed .logo-box,
.sidebar.closed .username,
.sidebar.closed .role,
.sidebar.closed .logout-btn span{
    display:none;
}

.sidebar.closed .avatar{
    width:47px;
    height:47px;
    font-size:20px;
    border-width:2px;
}

.sidebar.closed .logout-btn{
    width:47px;
    padding:0;
    margin:auto;
    margin-top:16px;
}

/* content */
.content{
    flex:1;
    padding:26px;
    min-width:0;
}

/* header title */
.topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    margin-bottom:20px;
    flex-wrap:wrap;
}

.title-card{
    background:rgba(255,255,255,.85);
    border:1px solid #e5e7eb;
    padding:15px 18px;
    border-radius:22px;
    box-shadow:0 14px 35px rgba(23,32,51,.08);
}

.title-box{
    display:flex;
    align-items:center;
    gap:11px;
    font-weight:900;
    font-size:20px;
}

.title-box i{
    width:40px;
    height:40px;
    border-radius:14px;
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
}

.page-note{
    margin-top:7px;
    font-size:12px;
    color:#667085;
    font-weight:700;
}

/* top tickets */
.cards{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(190px,1fr));
    gap:16px;
    margin-bottom:24px;
}

.card{
    background:rgba(255,255,255,.78);
    border:1px solid #e5e7eb;
    border-radius:22px;
    padding:17px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    min-height:112px;
    position:relative;
    overflow:hidden;
}

.card::after{
    content:"";
    position:absolute;
    width:90px;
    height:90px;
    border-radius:50%;
    left:-30px;
    bottom:-30px;
    background:rgba(109,74,255,.08);
}

.card-icon{
    width:39px;
    height:39px;
    border-radius:14px;
    background:#f0edff;
    color:#6d4aff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:21px;
    margin-bottom:10px;
}

.card .number{
    font-size:27px;
    font-weight:900;
    line-height:1;
}

.card .label{
    margin-top:8px;
    font-size:13px;
    color:#667085;
    font-weight:800;
}


/* admin dashboard donuts */
.admin-donut-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(280px, 1fr));
    gap:18px;
    margin-bottom:26px;
}

.admin-donut-card{
    background:rgba(255,255,255,.78);
    border:1px solid rgba(226,232,240,.95);
    border-radius:26px;
    padding:18px;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    position:relative;
    overflow:hidden;
    min-height:245px;
}

.admin-donut-card::after{
    content:"";
    position:absolute;
    width:118px;
    height:118px;
    border-radius:50%;
    left:-38px;
    bottom:-38px;
    background:rgba(109,74,255,.08);
    pointer-events:none;
}

.admin-donut-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:14px;
}

.admin-donut-title{
    display:flex;
    align-items:center;
    gap:9px;
    font-size:15px;
    font-weight:900;
    color:#172033;
}

.admin-donut-title i{
    width:38px;
    height:38px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#6d4aff;
    background:#f0edff;
    font-size:20px;
}

.admin-donut-sub{
    color:#667085;
    font-size:11px;
    font-weight:800;
    line-height:1.7;
}

.admin-donut-content{
    display:grid;
    grid-template-columns:150px 1fr;
    gap:16px;
    align-items:center;
    position:relative;
    z-index:2;
}

.admin-donut{
    width:150px;
    height:150px;
    border-radius:50%;
    background:conic-gradient(var(--segments));
    display:flex;
    align-items:center;
    justify-content:center;
    position:relative;
    box-shadow:inset 0 0 12px rgba(255,255,255,.35), 0 16px 32px rgba(79,70,229,.18);
    flex-shrink:0;
}

.admin-donut::before{
    content:"";
    width:96px;
    height:96px;
    border-radius:50%;
    position:absolute;
    background:linear-gradient(145deg,#ffffff,#eef1f7);
    box-shadow:inset 4px 4px 9px #d1d9e6, inset -4px -4px 9px #fff;
}

.admin-donut-center{
    position:relative;
    z-index:2;
    text-align:center;
    max-width:86px;
}

.admin-donut-center strong{
    display:block;
    color:#4f46e5;
    font-size:23px;
    font-weight:900;
    line-height:1.05;
    direction:ltr;
}

.admin-donut-center span{
    display:block;
    margin-top:4px;
    color:#667085;
    font-size:10px;
    font-weight:900;
}

.admin-legend{
    display:flex;
    flex-direction:column;
    gap:8px;
    min-width:0;
}

.admin-legend-row{
    display:grid;
    grid-template-columns:12px 1fr auto;
    gap:7px;
    align-items:center;
    font-size:12px;
    font-weight:900;
    color:#172033;
}

.admin-legend-dot{
    width:10px;
    height:10px;
    border-radius:50%;
    background:var(--c);
    box-shadow:0 0 8px var(--c);
}

.admin-legend-name{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.admin-legend-value{
    color:#4f46e5;
    direction:ltr;
    white-space:nowrap;
}

.admin-empty{
    color:#98a2b3;
    font-size:12px;
    font-weight:800;
    text-align:center;
    padding:20px 0;
}


/* sections */
.section-title{
    margin:26px 0 13px;
    font-size:18px;
    font-weight:900;
    color:#4f46e5;
    display:flex;
    align-items:center;
    gap:8px;
}

.section-title::before{
    content:"";
    width:10px;
    height:24px;
    border-radius:20px;
    background:linear-gradient(180deg,#7c5cff,#4f46e5);
}

/* nav cards grid */
.nav-cards{
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(185px,1fr));
    gap:16px;
    align-items:stretch;
}

.nav-card{
    min-height:166px;
    text-align:center;
    background:rgba(255,255,255,.78);
    border:1px solid #e5e7eb;
    border-radius:22px;
    padding:16px 12px 13px;
    text-decoration:none;
    color:#172033;
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
    transition:.2s;
    cursor:grab;
    user-select:none;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
}

.nav-card:active{
    cursor:grabbing;
}

.nav-card:hover{
    transform:translateY(-4px);
    border-color:rgba(109,74,255,.35);
}

.icon-wrap{
    width:94px;
    height:94px;
    border-radius:24px;
    background:#f7f8fc;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:10px;
    box-shadow:inset 4px 4px 9px #dce2ec, inset -4px -4px 9px #fff;
}

.icon-img{
    width:78px;
    height:78px;
    object-fit:contain;
    display:block;
}

.nav-title{
    font-size:14px;
    font-weight:900;
    line-height:1.55;
    word-break:break-word;
}

.drag-hint{
    margin-top:5px;
    font-size:10px;
    color:#98a2b3;
    font-weight:700;
}

.highlight{
    background:#ddd;
}

.sortable-ghost{
    opacity:.35;
}

.sortable-chosen{
    transform:scale(1.02);
}


@media(max-width:1050px){
    .admin-donut-grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:620px){
    .admin-donut-content{
        grid-template-columns:1fr;
        justify-items:center;
    }

    .admin-legend{
        width:100%;
    }
}


@media(max-width:900px){
    .container{
        display:block;
    }

    .sidebar,
    .sidebar.closed{
        width:100%;
        border-radius:0 0 24px 24px;
        min-height:auto;
    }

    .toggle-btn{
        display:none;
    }

    .sidebar.closed .username,
    .sidebar.closed .role,
    .sidebar.closed .logout-btn span{
        display:block;
    }

    .sidebar.closed .avatar{
        width:70px;
        height:70px;
        font-size:26px;
    }

    .sidebar.closed .logout-btn{
        width:100%;
    }

    .content{
        padding:18px;
    }

    .app-topbar{
        margin:-18px -18px 16px;
        padding:12px 14px;
    }

    .app-topbar-btn-text{
        display:none;
    }

    .app-topbar-account-menu{
        width:min(240px, calc(100vw - 28px));
    }

    .app-topbar-title{
        font-size:15px;
    }
}

@media(max-width:520px){
    .cards{
        grid-template-columns:1fr;
    }

    .nav-cards{
        grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
    }

    .nav-card{
        min-height:150px;
    }

    .icon-wrap{
        width:78px;
        height:78px;
    }

    .icon-img{
        width:64px;
        height:64px;
    }

    .donut-body{
        flex-direction:column;
    }
}

/* collapsible admin analytics */
.admin-analytics-wrap{
    margin-bottom:18px;
}

.admin-analytics-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:12px;
    padding:13px 16px;
    border-radius:20px;
    background:rgba(255,255,255,.62);
    border:1px solid rgba(226,232,240,.95);
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}

.admin-analytics-title{
    display:flex;
    align-items:center;
    gap:9px;
    font-weight:900;
    color:#172033;
    font-size:16px;
}

.admin-analytics-title i{
    width:36px;
    height:36px;
    border-radius:14px;
    background:#f0edff;
    color:#6d4aff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
}

.admin-analytics-toggle{
    border:0;
    min-width:42px;
    height:42px;
    border-radius:15px;
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 12px 22px rgba(79,70,229,.22);
    transition:.2s ease;
}

.admin-analytics-toggle i{
    font-size:22px;
    transition:.25s ease;
}

.admin-analytics-wrap.closed .admin-analytics-toggle i{
    transform:rotate(180deg);
}

.admin-analytics-body{
    overflow:hidden;
    max-height:900px;
    opacity:1;
    transform:translateY(0);
    transition:max-height .35s ease, opacity .25s ease, transform .25s ease;
}

.admin-analytics-wrap.closed .admin-analytics-body{
    max-height:0;
    opacity:0;
    transform:translateY(-8px);
    pointer-events:none;
}



/* dashboard sidebar account + notifications */
.sidebar-top-tools{
    width:100%;
    margin:0 0 12px;
    padding-bottom:12px;
    border-bottom:1px solid rgba(255,255,255,.12);
}

.sidebar-toolbar{
    display:flex;
    align-items:stretch;
    gap:8px;
}

.sidebar-account{
    flex:1;
    min-width:0;
    position:relative;
}

.sidebar-account-card{
    display:flex;
    align-items:center;
    gap:9px;
    padding:8px 10px;
    border-radius:16px;
    background:rgba(255,255,255,.12);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.16);
    border:1px solid rgba(255,255,255,.14);
    cursor:pointer;
    min-height:52px;
    height:100%;
    transition:.18s;
}

.sidebar-account-card:hover{
    background:rgba(255,255,255,.17);
}

.sidebar-mini-avatar{
    width:36px;
    height:36px;
    border-radius:12px;
    background:linear-gradient(145deg,#ffffff,#dfe6ff);
    color:#4f46e5;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
    font-size:14px;
    flex-shrink:0;
    box-shadow:0 6px 14px rgba(0,0,0,.14);
}

.sidebar-account-info{
    min-width:0;
    flex:1;
    text-align:right;
}

.sidebar-account-name{
    font-size:12px;
    font-weight:900;
    color:#fff;
    line-height:1.3;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.sidebar-account-role{
    font-size:10px;
    font-weight:700;
    color:rgba(255,255,255,.68);
    margin-top:2px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.sidebar-account-chevron{
    font-size:16px;
    color:rgba(255,255,255,.55);
    flex-shrink:0;
}

.sidebar-account-menu{
    display:none;
    position:absolute;
    right:0;
    top:calc(100% + 6px);
    width:min(210px, calc(100vw - 24px));
    border-radius:14px;
    background:#eef1f7;
    overflow:hidden;
    z-index:99999;
    border:1px solid rgba(226,232,240,.95);
    box-shadow:0 18px 40px rgba(23,32,51,.18);
}

.sidebar-account-menu a{
    display:flex;
    align-items:center;
    gap:8px;
    padding:11px 12px;
    text-decoration:none;
    color:#172033;
    font-size:12px;
    font-weight:800;
    border-bottom:1px solid #e0e5ec;
}

.sidebar-account-menu a i{
    font-size:16px;
    color:#4f46e5;
}

.sidebar-account-menu a:last-child{
    border-bottom:0;
}

.sidebar-account-menu a:hover{
    background:#f0edff;
    color:#4f46e5;
}

.sidebar-quick-actions{
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:6px;
    flex-shrink:0;
}

.sidebar-notif-wrap,
.sidebar-msg-wrap{
    position:relative;
    width:auto;
    margin:0;
    display:block;
}

.sidebar-notif-btn,
.sidebar-msg-btn{
    position:relative;
    width:44px;
    height:44px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(255,255,255,.12);
    color:#fff;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.14);
    text-decoration:none;
    font-size:19px;
    transition:.18s;
}

.sidebar-notif-btn:hover,
.sidebar-msg-btn:hover{
    background:rgba(255,255,255,.2);
    transform:translateY(-1px);
}

.sidebar-notif-count,
.sidebar-msg-count{
    position:absolute;
    top:-7px;
    left:-7px;
    background:#ef4444;
    color:#fff;
    min-width:22px;
    height:22px;
    padding:0 6px;
    border-radius:999px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:11px;
    font-weight:900;
    box-shadow:0 8px 16px rgba(239,68,68,.25);
}

.dash-notif-box{
    position:absolute;
    top:calc(100% + 8px);
    right:0;
    width:390px;
    max-height:460px;
    overflow-y:auto;
    background:rgba(255,255,255,.96);
    border:1px solid rgba(226,232,240,.98);
    border-radius:24px;
    display:none;
    box-shadow:0 24px 55px rgba(23,32,51,.22);
    padding:12px;
    z-index:99999;
    color:#172033;
    backdrop-filter:blur(12px);
}

.dash-notif-title{
    padding:8px 9px 12px;
    font-size:15px;
    color:#172033;
    font-weight:900;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border-bottom:1px solid rgba(226,232,240,.95);
    margin-bottom:10px;
}

.dash-notif-title-main{
    display:flex;
    align-items:center;
    gap:7px;
}

.dash-notif-refresh{
    font-size:10.5px;
    color:#8a94a6;
    font-weight:800;
    white-space:nowrap;
}

.dash-notif-list{
    display:grid;
    gap:9px;
}

.dash-notif-item,
.dash-notif-list > a{
    display:grid !important;
    grid-template-columns:42px 1fr;
    align-items:flex-start;
    gap:11px;
    padding:12px 13px !important;
    border-radius:18px;
    text-decoration:none !important;
    color:#172033 !important;
    background:#ffffff;
    border:1px solid #e2e8f0;
    box-shadow:0 8px 20px rgba(23,32,51,.055);
    transition:.18s ease;
    position:relative;
    overflow:hidden;
    text-align:right;
}

.dash-notif-item *,
.dash-notif-list > a *{
    text-decoration:none !important;
}

.dash-notif-item::before,
.dash-notif-list > a::before{
    content:"";
    position:absolute;
    right:0;
    top:0;
    bottom:0;
    width:4px;
    background:#6d4aff;
}

.dash-notif-item:hover,
.dash-notif-list > a:hover{
    background:#fbfaff;
    transform:translateY(-1px);
    border-color:rgba(109,74,255,.22);
}

.dash-notif-item.read{opacity:.72;}
.dash-notif-item.two-days::before{background:#facc15;}
.dash-notif-item.one-day::before{background:#fb923c;}
.dash-notif-item.today::before,
.dash-notif-item.late::before{background:#ef4444;}

.dash-notif-icon{
    width:42px;
    height:42px;
    border-radius:16px;
    background:#f0edff;
    color:#4f46e5;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
    font-size:18px;
    box-shadow:inset 2px 2px 5px rgba(209,217,230,.7), inset -2px -2px 5px rgba(255,255,255,.9);
}

.dash-notif-icon-approved{background:#ecfdf3;color:#166534;}
.dash-notif-icon-rejected{background:#fff1f2;color:#b42318;}
.dash-notif-icon-deadline{background:#fffbeb;color:#b45309;}
.dash-notif-icon-items{background:#eef2ff;color:#4f46e5;}
.dash-notif-icon-rents{background:#ecfeff;color:#0e7490;}
.dash-notif-icon-message{background:#f0f9ff;color:#0369a1;}
.dash-notif-icon-contracts{background:#f0edff;color:#4f46e5;}

.dash-notif-content{
    min-width:0;
    display:grid;
    gap:5px;
}

.dash-notif-headline{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
}

.dash-notif-badge{
    min-height:23px;
    padding:2px 9px;
    border-radius:999px;
    font-size:10.5px;
    font-weight:900;
    background:#f1f5f9;
    color:#475569;
    white-space:nowrap;
    flex-shrink:0;
}

.dash-notif-badge-approved{background:#ecfdf3;color:#166534;}
.dash-notif-badge-rejected{background:#fff1f2;color:#b42318;}
.dash-notif-badge-deadline{background:#fffbeb;color:#b45309;}
.dash-notif-badge-items{background:#eef2ff;color:#4f46e5;}
.dash-notif-badge-rents{background:#ecfeff;color:#0e7490;}
.dash-notif-badge-message{background:#f0f9ff;color:#0369a1;}
.dash-notif-badge-contracts{background:#f0edff;color:#4f46e5;}

.dash-notif-title-text{
    font-size:13px;
    font-weight:900;
    line-height:1.45;
    color:#172033 !important;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.dash-notif-line,
.dash-notif-list > a{
    font-size:12.5px;
    font-weight:800;
    line-height:1.75;
    color:#475569 !important;
    overflow-wrap:anywhere;
    word-break:break-word;
}

.dash-notif-meta{
    margin-top:1px;
    font-size:11px;
    color:#98a2b3 !important;
    font-weight:900;
    direction:ltr;
    text-align:left;
}

.dash-notif-empty{
    padding:16px 12px;
    font-size:13px;
    font-weight:900;
    color:#667085;
    text-align:center;
    background:#fff;
    border:1px dashed #cbd5e1;
    border-radius:15px;
}

.sidebar.closed .sidebar-top-tools{
    padding-bottom:10px;
}

.sidebar.closed .sidebar-toolbar{
    flex-direction:column;
    align-items:center;
    gap:8px;
}

.sidebar.closed .sidebar-account{
    width:100%;
}

.sidebar.closed .sidebar-account-card{
    width:44px;
    height:44px;
    min-height:44px;
    justify-content:center;
    padding:6px;
    border-radius:14px;
}

.sidebar.closed .sidebar-account-info,
.sidebar.closed .sidebar-account-chevron{
    display:none;
}

.sidebar.closed .sidebar-quick-actions{
    flex-direction:row;
    gap:6px;
}

.sidebar.closed .sidebar-account-menu{
    right:52px;
    top:0;
}

.sidebar.closed .dash-notif-box{
    right:52px;
    top:0;
}

.sidebar.closed .sidebar-notif-wrap,
.sidebar.closed .sidebar-msg-wrap{
    margin:0;
}



.sidebar-msg-btn.has-unread{
    animation:dashMsgPulse 1.8s ease-in-out infinite;
}

@keyframes dashMsgPulse{
    0%,100%{transform:translateY(0); box-shadow:inset 0 1px 0 rgba(255,255,255,.18), 0 10px 22px rgba(23,32,51,.14);}
    50%{transform:translateY(-2px); box-shadow:inset 0 1px 0 rgba(255,255,255,.20), 0 16px 28px rgba(109,74,255,.28);}
}

.dash-notif-badge-payments{
    background:#e0f2fe;
    color:#075985;
}

.dash-notif-icon-payments{
    background:#e0f2fe;
    color:#075985;
}

/* ERP sidebar navigation */
.sidebar-brand{
    display:flex;
    align-items:center;
    gap:12px;
    padding:8px 10px 14px;
    margin-bottom:4px;
    border-bottom:1px solid rgba(255,255,255,.12);
}
.sidebar-brand-icon{
    width:42px;
    height:42px;
    border-radius:14px;
    background:rgba(255,255,255,.14);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.18);
    flex-shrink:0;
}
.sidebar-brand-text{
    min-width:0;
    line-height:1.25;
}
.sidebar-brand-text strong{
    display:block;
    font-size:15px;
    font-weight:900;
}
.sidebar-brand-text span{
    display:block;
    font-size:10px;
    font-weight:800;
    color:rgba(255,255,255,.72);
    letter-spacing:.6px;
    margin-top:2px;
}

.erp-nav{
    flex:1;
    min-height:0;
    overflow-y:auto;
    overflow-x:hidden;
    padding:6px 2px 10px;
    margin:8px 0 10px;
    scrollbar-width:thin;
    scrollbar-color:rgba(255,255,255,.35) transparent;
}
.erp-nav::-webkit-scrollbar{width:5px;}
.erp-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.28);border-radius:999px;}

.erp-nav-home{
    display:flex;
    align-items:center;
    gap:10px;
    padding:11px 12px;
    margin-bottom:10px;
    border-radius:14px;
    text-decoration:none;
    color:#fff;
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.14);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.12);
    transition:.2s;
}
.erp-nav-home:hover{background:rgba(255,255,255,.18);}
.erp-nav-home-icon{
    width:34px;
    height:34px;
    border-radius:11px;
    background:rgba(255,255,255,.16);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
    flex-shrink:0;
}
.erp-nav-home-text{min-width:0;}
.erp-nav-home-text strong{display:block;font-size:13px;font-weight:900;}
.erp-nav-home-text small{display:block;font-size:10px;font-weight:700;color:rgba(255,255,255,.72);margin-top:2px;}

.erp-module{margin-bottom:8px;}
.erp-module-head{
    width:100%;
    border:0;
    background:rgba(255,255,255,.08);
    color:#fff;
    border-radius:14px;
    padding:10px 11px;
    display:flex;
    align-items:center;
    gap:10px;
    cursor:pointer;
    text-align:right;
    transition:.2s;
    border:1px solid rgba(255,255,255,.08);
}
.erp-module-head:hover{background:rgba(255,255,255,.13);}
.erp-module-icon{
    width:32px;
    height:32px;
    border-radius:10px;
    background:rgba(255,255,255,.14);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:17px;
    flex-shrink:0;
}
.erp-module-label{flex:1;min-width:0;text-align:right;}
.erp-module-label strong{display:block;font-size:12px;font-weight:900;line-height:1.35;}
.erp-module-label small{display:block;font-size:9px;font-weight:700;color:rgba(255,255,255,.68);margin-top:2px;letter-spacing:.3px;}
.erp-module-chevron{font-size:18px;opacity:.85;transition:transform .2s;}
.erp-module.collapsed .erp-module-chevron{transform:rotate(-90deg);}
.erp-module-body{
    overflow:hidden;
    max-height:520px;
    transition:max-height .25s ease, opacity .2s ease;
    opacity:1;
}
.erp-module.collapsed .erp-module-body{max-height:0;opacity:0;}

.erp-module-list{
    display:flex;
    flex-direction:column;
    gap:4px;
    padding:6px 4px 2px;
}
.erp-nav-link{
    display:flex;
    align-items:center;
    gap:9px;
    padding:8px 10px;
    border-radius:12px;
    text-decoration:none;
    color:rgba(255,255,255,.95);
    background:rgba(255,255,255,.06);
    border:1px solid transparent;
    transition:.18s;
}
.erp-nav-link:hover{
    background:rgba(255,255,255,.14);
    border-color:rgba(255,255,255,.12);
    transform:translateX(-2px);
}
.erp-nav-link-icon{
    width:28px;
    height:28px;
    border-radius:9px;
    background:rgba(255,255,255,.92);
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
    overflow:hidden;
}
.erp-nav-link-icon img{width:20px;height:20px;object-fit:contain;}
.erp-nav-link-text{
    flex:1;
    min-width:0;
    font-size:12px;
    font-weight:800;
    line-height:1.35;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.erp-nav-drag{
    font-size:14px;
    opacity:.35;
    flex-shrink:0;
}
.erp-nav-link.sortable-chosen{
    background:rgba(255,255,255,.22);
    box-shadow:0 8px 18px rgba(0,0,0,.18);
}

.sidebar.closed .sidebar-brand-text,
.sidebar.closed .erp-nav-home-text,
.sidebar.closed .erp-module-label,
.sidebar.closed .erp-module-chevron,
.sidebar.closed .erp-nav-link-text,
.sidebar.closed .erp-nav-drag{
    display:none;
}
.sidebar.closed .sidebar-brand{
    justify-content:center;
    padding-inline:0;
}
.sidebar.closed .erp-nav-home,
.sidebar.closed .erp-module-head{
    justify-content:center;
    padding-inline:8px;
}
.sidebar.closed .erp-module-body{display:block!important;max-height:none!important;opacity:1!important;}
.sidebar.closed .erp-module-list{gap:6px;padding:4px 0;}
.sidebar.closed .erp-nav-link{
    justify-content:center;
    padding:8px;
}
.sidebar.closed .erp-nav-link-icon{width:34px;height:34px;}
.dashboard-home{
    display:flex;
    flex-direction:column;
    gap:28px;
}
.dash-section-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:14px;
}
.dash-section-head h3{
    margin:0;
    font-size:16px;
    font-weight:900;
    color:#172033;
    display:flex;
    align-items:center;
    gap:8px;
}
.dash-section-head h3 i{
    color:#4f46e5;
    font-size:18px;
}
.dash-section-note{
    font-size:11px;
    font-weight:800;
    color:#98a2b3;
}
.dash-hero{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:18px;
    flex-wrap:wrap;
    padding:22px 24px;
    border-radius:22px;
    background:linear-gradient(135deg,rgba(255,255,255,.92),rgba(248,247,255,.95));
    border:1px solid rgba(226,232,240,.95);
    box-shadow:8px 8px 18px #d1d9e6,-8px -8px 18px #fff;
}
.dash-hero-main{
    display:flex;
    align-items:center;
    gap:16px;
    min-width:0;
}
.dash-hero-avatar{
    width:58px;
    height:58px;
    border-radius:18px;
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:24px;
    font-weight:900;
    flex-shrink:0;
    box-shadow:0 10px 24px rgba(79,70,229,.28);
}
.dash-hero-text{
    min-width:0;
}
.dash-hero-badge{
    display:inline-flex;
    align-items:center;
    padding:4px 10px;
    border-radius:999px;
    background:#ede9fe;
    color:#5b21b6;
    font-size:11px;
    font-weight:900;
    margin-bottom:8px;
}
.dash-hero-text h2{
    margin:0 0 6px;
    font-size:22px;
    font-weight:900;
    color:#172033;
}
.dash-hero-text p{
    margin:0;
    font-size:13px;
    font-weight:700;
    color:#667085;
    line-height:1.7;
    max-width:620px;
}
.dash-hero-date{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 14px;
    border-radius:14px;
    background:#fff;
    border:1px solid #e5e7eb;
    color:#475467;
    font-size:12px;
    font-weight:800;
    flex-shrink:0;
}
.dash-hero-date i{
    color:#4f46e5;
    font-size:16px;
}
.dash-kpi-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(190px,1fr));
    gap:14px;
}
.dash-kpi-card{
    display:flex;
    align-items:flex-start;
    gap:12px;
    padding:16px;
    border-radius:18px;
    background:rgba(255,255,255,.88);
    border:1px solid #e5e7eb;
    box-shadow:0 8px 20px rgba(23,32,51,.05);
    text-decoration:none;
    color:inherit;
    transition:.18s;
}
a.dash-kpi-card:hover{
    transform:translateY(-2px);
    border-color:#c7d2fe;
    box-shadow:0 14px 28px rgba(79,70,229,.12);
}
.dash-kpi-icon{
    width:42px;
    height:42px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
    flex-shrink:0;
}
.dash-kpi-value{
    font-size:24px;
    font-weight:900;
    line-height:1.1;
    color:#172033;
}
.dash-kpi-label{
    margin-top:4px;
    font-size:13px;
    font-weight:900;
    color:#344054;
}
.dash-kpi-meta{
    margin-top:3px;
    font-size:11px;
    font-weight:700;
    color:#98a2b3;
}
.dash-kpi-card.tone-indigo .dash-kpi-icon{background:#ede9fe;color:#4f46e5;}
.dash-kpi-card.tone-amber .dash-kpi-icon{background:#fef3c7;color:#d97706;}
.dash-kpi-card.tone-sky .dash-kpi-icon{background:#e0f2fe;color:#0284c7;}
.dash-kpi-card.tone-violet .dash-kpi-icon{background:#f3e8ff;color:#7c3aed;}
.dash-kpi-card.tone-emerald .dash-kpi-icon{background:#d1fae5;color:#059669;}
.dash-kpi-card.tone-rose .dash-kpi-icon{background:#ffe4e6;color:#e11d48;}
.dash-kpi-card.tone-cyan .dash-kpi-icon{background:#cffafe;color:#0891b2;}
.dash-modules-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    gap:14px;
}
.dash-module-card{
    border-radius:18px;
    background:rgba(255,255,255,.88);
    border:1px solid #e5e7eb;
    box-shadow:0 8px 20px rgba(23,32,51,.05);
}
.dash-module-head{
    display:flex;
    align-items:center;
    gap:12px;
    padding:16px;
    margin:0;
}
.dash-module-icon{
    width:42px;
    height:42px;
    border-radius:14px;
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
    flex-shrink:0;
}
.dash-module-title{
    min-width:0;
    flex:1;
}
.dash-module-title strong{
    display:block;
    font-size:14px;
    font-weight:900;
    color:#172033;
}
.dash-module-title small{
    display:block;
    margin-top:2px;
    font-size:10px;
    font-weight:700;
    color:#98a2b3;
    direction:ltr;
    text-align:right;
}
.dash-module-count{
    min-width:28px;
    height:28px;
    padding:0 8px;
    border-radius:999px;
    background:#f0edff;
    color:#4f46e5;
    font-size:11px;
    font-weight:900;
    display:flex;
    align-items:center;
    justify-content:center;
}

.dash-analytics-section{
    margin-top:4px;
}
.dash-analytics-section .admin-analytics-wrap{
    margin-bottom:0;
}

@media(max-width:768px){
    .dash-hero{
        padding:18px;
    }
    .dash-hero-date{
        width:100%;
        justify-content:center;
    }
    .dash-kpi-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .dash-modules-grid{
        grid-template-columns:1fr;
    }
}
@media(max-width:480px){
    .dash-kpi-grid{
        grid-template-columns:1fr;
    }
}

/* App top bar */
.app-topbar{
    position:sticky;
    top:0;
    z-index:200;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin:-26px -26px 22px;
    padding:14px 18px;
    background:rgba(255,255,255,.94);
    backdrop-filter:blur(14px);
    border-bottom:1px solid rgba(226,232,240,.95);
    box-shadow:0 10px 30px rgba(23,32,51,.06);
}
.app-topbar-start{
    display:flex;
    align-items:center;
    gap:14px;
    min-width:0;
}
.app-topbar-heading{
    min-width:0;
}
.app-topbar-title{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:18px;
    font-weight:900;
    color:#172033;
    line-height:1.3;
}
.app-topbar-title span{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
.app-topbar-title i{
    width:38px;
    height:38px;
    border-radius:13px;
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
    flex-shrink:0;
}
.app-topbar-actions{
    display:flex;
    align-items:center;
    gap:8px;
    flex-shrink:0;
}
.app-topbar-btn{
    position:relative;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    height:42px;
    min-width:42px;
    padding:0 12px;
    border-radius:14px;
    border:1px solid #e5e7eb;
    background:#fff;
    color:#344054;
    font-size:13px;
    font-weight:800;
    cursor:pointer;
    text-decoration:none;
    box-shadow:0 4px 12px rgba(23,32,51,.05);
    transition:.18s;
    font-family:inherit;
}
.app-topbar-btn:hover{
    border-color:#c7d2fe;
    background:#f8f7ff;
    color:#4f46e5;
    transform:translateY(-1px);
}
.app-topbar-toggle{
    background:linear-gradient(145deg,#7c5cff,#4f46e5);
    border-color:transparent;
    color:#fff;
    box-shadow:0 8px 20px rgba(79,70,229,.28);
    padding:0;
    width:44px;
    height:44px;
    min-width:44px;
}
.app-topbar-toggle i{
    font-size:24px;
    line-height:1;
    display:block;
}
.app-topbar-toggle:hover{
    background:linear-gradient(145deg,#6b4dff,#4338ca);
    color:#fff;
    border-color:transparent;
}
.app-topbar-account-wrap{
    position:relative;
}
.app-topbar-account i{
    font-size:22px;
    line-height:1;
}
.app-topbar-account-menu{
    display:none;
    position:absolute;
    top:calc(100% + 10px);
    left:0;
    min-width:210px;
    border-radius:16px;
    background:#fff;
    overflow:hidden;
    z-index:99999;
    border:1px solid rgba(226,232,240,.98);
    box-shadow:0 18px 40px rgba(23,32,51,.16);
}
.app-topbar-account-menu a{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px 14px;
    text-decoration:none;
    color:#172033;
    font-size:13px;
    font-weight:800;
    border-bottom:1px solid #eef2f6;
    transition:.15s;
}
.app-topbar-account-menu a:last-child{
    border-bottom:0;
}
.app-topbar-account-menu a i{
    font-size:18px;
    color:#4f46e5;
    flex-shrink:0;
}
.app-topbar-account-menu a:hover{
    background:#f8f7ff;
    color:#4f46e5;
}
.app-topbar-account-menu a.is-logout{
    color:#b42318;
}
.app-topbar-account-menu a.is-logout i{
    color:#dc2626;
}
.app-topbar-account-menu a.is-logout:hover{
    background:#fef2f2;
    color:#991b1b;
}
.app-topbar-badge{
    position:absolute;
    top:-6px;
    left:-6px;
    background:#ef4444;
    color:#fff;
    min-width:20px;
    height:20px;
    padding:0 5px;
    border-radius:999px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:10px;
    font-weight:900;
    box-shadow:0 6px 14px rgba(239,68,68,.28);
    line-height:1;
}
.app-topbar-notif-wrap{
    position:relative;
}
.app-topbar .dash-notif-box{
    top:calc(100% + 10px);
    left:0;
    right:auto;
    width:min(390px, calc(100vw - 36px));
}

</style>

</head>

<body>

<div class="container">

    <div class="sidebar" id="sidebar">

        <div class="sidebar-brand">
            <div class="sidebar-brand-icon"><i class="ri-apps-2-line"></i></div>
            <div class="sidebar-brand-text">
                <strong>VendorCore</strong>
                <span>ERP SUITE</span>
            </div>
        </div>

        <?php include VC_VIEWS . '/partials/dashboard_erp_sidebar.php'; ?>

    </div>

    <div class="content">

        <?php include VC_VIEWS . '/partials/dashboard_app_topbar.php'; ?>

        <?php include VC_VIEWS . '/partials/dashboard_home.php'; ?>

        <?php if($show_management_analytics): ?>

        <section class="dash-section dash-analytics-section">
        <div class="admin-analytics-wrap" id="adminAnalyticsWrap">
    <div class="admin-analytics-head">
        <div class="admin-analytics-title">
            <i class="ri-pie-chart-2-line"></i>
            <?php if($isFinanceDashboard): ?><span>تحليلات الإدارة المالية</span><?php else: ?><span>تحليلات الإدارة</span><?php endif; ?>
        </div>

        <button type="button" class="admin-analytics-toggle" onclick="toggleAdminAnalytics()" title="طي / فتح التحليلات">
            <i class="ri-arrow-up-s-line"></i>
        </button>
    </div>

    <div class="admin-analytics-body">
        <div class="admin-donut-grid">

            <?php if($isFinanceDashboard): ?>

            <div class="admin-donut-card">
                <div class="admin-donut-head">
                    <div>
                        <div class="admin-donut-title">
                            <i class="ri-money-dollar-circle-line"></i>
                            <span>مبالغ السداد</span>
                        </div>
                        <div class="admin-donut-sub">إجمالي المطلوب والمعتمد وفرق السداد المسجل في طلبات السداد</div>
                    </div>
                </div>
                <div class="admin-donut-content">
                    <div class="admin-donut" style="--segments:<?= e($finance_amount_donut) ?>">
                        <div class="admin-donut-center">
                            <strong><?= e(moneyShort($finance_amount_requested)) ?></strong>
                            <span>ريال</span>
                        </div>
                    </div>
                    <div class="admin-legend">
                        <div class="admin-legend-row"><span class="admin-legend-dot" style="--c:#6bb7ff"></span><span class="admin-legend-name">المطلوب</span><span class="admin-legend-value"><?= e(moneyFull($finance_amount_requested)) ?> ريال</span></div>
                        <div class="admin-legend-row"><span class="admin-legend-dot" style="--c:#47e6a1"></span><span class="admin-legend-name">المعتمد</span><span class="admin-legend-value"><?= e(moneyFull($finance_amount_approved)) ?> ريال</span></div>
                        <div class="admin-legend-row"><span class="admin-legend-dot" style="--c:#a78bfa"></span><span class="admin-legend-name">فرق السداد</span><span class="admin-legend-value"><?= e(moneyFull($finance_amount_discount)) ?> ريال</span></div>
                    </div>
                </div>
            </div>

            <div class="admin-donut-card">
                <div class="admin-donut-head">
                    <div>
                        <div class="admin-donut-title">
                            <i class="ri-bank-card-line"></i>
                            <span>طلبات السداد حسب الحالة</span>
                        </div>
                        <div class="admin-donut-sub">مراحل طلبات السداد داخل مسار الاعتماد</div>
                    </div>
                </div>
                <div class="admin-donut-content">
                    <div class="admin-donut" style="--segments:<?= e($finance_payment_donut) ?>">
                        <div class="admin-donut-center"><strong><?= (int)$finance_payment_total ?></strong><span>طلب</span></div>
                    </div>
                    <div class="admin-legend">
                        <div class="admin-legend-row"><span class="admin-legend-dot" style="--c:#6bb7ff"></span><span class="admin-legend-name">بانتظار المالي</span><span class="admin-legend-value"><?= (int)$finance_payment_pending_finance ?></span></div>
                        <div class="admin-legend-row"><span class="admin-legend-dot" style="--c:#47e6a1"></span><span class="admin-legend-name">معتمد نهائيًا</span><span class="admin-legend-value"><?= (int)$finance_payment_approved ?></span></div>
                        <div class="admin-legend-row"><span class="admin-legend-dot" style="--c:#ff6b8a"></span><span class="admin-legend-name">مرفوض</span><span class="admin-legend-value"><?= (int)$finance_payment_rejected ?></span></div>
                        <div class="admin-legend-row"><span class="admin-legend-dot" style="--c:#a78bfa"></span><span class="admin-legend-name">جاهز للطباعة</span><span class="admin-legend-value"><?= (int)$finance_payment_ready_print ?></span></div>
                    </div>
                </div>
            </div>

            <div class="admin-donut-card">
                <div class="admin-donut-head">
                    <div>
                        <div class="admin-donut-title">
                            <i class="ri-team-line"></i>
                            <span>طلبات السداد حسب المحاسب</span>
                        </div>
                        <div class="admin-donut-sub">توزيع طلبات السداد التي أنشأها فريق المحاسبين</div>
                    </div>
                </div>
                <div class="admin-donut-content">
                    <div class="admin-donut" style="--segments:<?= e($finance_accountant_donut) ?>">
                        <div class="admin-donut-center"><strong><?= (int)$finance_accountant_total ?></strong><span>طلب</span></div>
                    </div>
                    <div class="admin-legend">
                        <?php if(!empty($finance_accountant_segments)): ?>
                            <?php foreach($finance_accountant_segments as $seg): ?>
                                <div class="admin-legend-row"><span class="admin-legend-dot" style="--c:<?= e($seg['color']) ?>"></span><span class="admin-legend-name"><?= e($seg['label']) ?></span><span class="admin-legend-value"><?= (int)$seg['value'] ?></span></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="admin-empty">لا توجد طلبات مسجلة للمحاسبين</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="admin-donut-card">
                <div class="admin-donut-head">
                    <div>
                        <div class="admin-donut-title">
                            <i class="ri-pie-chart-2-line"></i>
                            <span>طلبات السداد حسب القسم</span>
                        </div>
                        <div class="admin-donut-sub">توزيع طلبات السداد بين غذائي ولا غذائي</div>
                    </div>
                </div>
                <div class="admin-donut-content">
                    <div class="admin-donut" style="--segments:<?= e($finance_section_donut) ?>">
                        <div class="admin-donut-center"><strong><?= (int)$finance_section_total ?></strong><span>طلب</span></div>
                    </div>
                    <div class="admin-legend">
                        <div class="admin-legend-row"><span class="admin-legend-dot" style="--c:#47e6a1"></span><span class="admin-legend-name">غذائي</span><span class="admin-legend-value"><?= (int)$finance_section_food ?></span></div>
                        <div class="admin-legend-row"><span class="admin-legend-dot" style="--c:#6bb7ff"></span><span class="admin-legend-name">لا غذائي</span><span class="admin-legend-value"><?= (int)$finance_section_non_food ?></span></div>
                        <div class="admin-legend-row"><span class="admin-legend-dot" style="--c:#ffd166"></span><span class="admin-legend-name">أخرى</span><span class="admin-legend-value"><?= (int)$finance_section_other ?></span></div>
                    </div>
                </div>
            </div>

            <?php else: ?>

            <div class="admin-donut-card">
                <div class="admin-donut-head">
                    <div>
                        <div class="admin-donut-title">
                            <i class="ri-money-dollar-circle-line"></i>
                            <span>إجمالي قيمة العقود</span>
                        </div>
                        <div class="admin-donut-sub">البنود التسويقية + البنود الإيجارية للعقود المعتمدة</div>
                    </div>
                </div>

                <div class="admin-donut-content">
                    <div class="admin-donut" style="--segments:<?= e($admin_value_donut) ?>">
                        <div class="admin-donut-center">
                            <strong><?= e(moneyShort($admin_contract_value_total)) ?></strong>
                            <span>ريال</span>
                        </div>
                    </div>

                    <div class="admin-legend">
                        <?php foreach($admin_value_segments as $seg): ?>
                            <div class="admin-legend-row">
                                <span class="admin-legend-dot" style="--c:<?= e($seg['color']) ?>"></span>
                                <span class="admin-legend-name"><?= e($seg['label']) ?></span>
                                <span class="admin-legend-value"><?= e($seg['display']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="admin-donut-card">
                <div class="admin-donut-head">
                    <div>
                        <div class="admin-donut-title">
                            <i class="ri-building-2-line"></i>
                            <span>إجمالي الإيجارات حسب الفروع</span>
                        </div>
                        <div class="admin-donut-sub">كل البنود الإيجارية داخل العقود المعتمدة</div>
                    </div>
                </div>

                <div class="admin-donut-content">
                    <div class="admin-donut" style="--segments:<?= e($admin_branch_rent_donut) ?>">
                        <div class="admin-donut-center">
                            <strong><?= e(moneyShort($admin_branch_rent_total)) ?></strong>
                            <span>ريال</span>
                        </div>
                    </div>

                    <div class="admin-legend">
                        <?php if(!empty($admin_branch_rent_segments)): ?>
                            <?php foreach($admin_branch_rent_segments as $seg): ?>
                                <div class="admin-legend-row">
                                    <span class="admin-legend-dot" style="--c:<?= e($seg['color']) ?>"></span>
                                    <span class="admin-legend-name"><?= e($seg['label']) ?></span>
                                    <span class="admin-legend-value"><?= e($seg['display']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="admin-empty">لا توجد إيجارات معتمدة</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="admin-donut-card">
                <div class="admin-donut-head">
                    <div>
                        <div class="admin-donut-title">
                            <i class="ri-file-list-3-line"></i>
                            <span>العقود السنوية المعتمدة</span>
                        </div>
                        <div class="admin-donut-sub">إجمالي العقود السنوية المعتمدة حسب المستخدم</div>
                    </div>
                </div>

                <div class="admin-donut-content">
                    <div class="admin-donut" style="--segments:<?= e($admin_annual_user_donut) ?>">
                        <div class="admin-donut-center">
                            <strong><?= (int)$admin_annual_approved_total ?></strong>
                            <span>عقد سنوي</span>
                        </div>
                    </div>

                    <div class="admin-legend">
                        <?php if(!empty($admin_annual_user_segments)): ?>
                            <?php foreach($admin_annual_user_segments as $seg): ?>
                                <div class="admin-legend-row">
                                    <span class="admin-legend-dot" style="--c:<?= e($seg['color']) ?>"></span>
                                    <span class="admin-legend-name"><?= e($seg['label']) ?></span>
                                    <span class="admin-legend-value"><?= e($seg['display']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="admin-empty">لا توجد عقود سنوية معتمدة</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="admin-donut-card">
                <div class="admin-donut-head">
                    <div>
                        <div class="admin-donut-title">
                            <i class="ri-home-4-line"></i>
                            <span>عقود الإيجار المعتمدة</span>
                        </div>
                        <div class="admin-donut-sub">إجمالي عقود الإيجار المعتمدة حسب المستخدم</div>
                    </div>
                </div>

                <div class="admin-donut-content">
                    <div class="admin-donut" style="--segments:<?= e($admin_rent_user_donut) ?>">
                        <div class="admin-donut-center">
                            <strong><?= (int)$admin_rent_approved_total ?></strong>
                            <span>عقد إيجار</span>
                        </div>
                    </div>

                    <div class="admin-legend">
                        <?php if(!empty($admin_rent_user_segments)): ?>
                            <?php foreach($admin_rent_user_segments as $seg): ?>
                                <div class="admin-legend-row">
                                    <span class="admin-legend-dot" style="--c:<?= e($seg['color']) ?>"></span>
                                    <span class="admin-legend-name"><?= e($seg['label']) ?></span>
                                    <span class="admin-legend-value"><?= e($seg['display']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="admin-empty">لا توجد عقود إيجار معتمدة</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>
        </section>

        <?php endif; ?>

    </div>

</div>

<script>
function toggleSidebar(){
    const sidebar = document.getElementById('sidebar');
    const icon = document.getElementById('sidebarToggleIcon');
    if (!sidebar) return;
    sidebar.classList.toggle('closed');
    const closed = sidebar.classList.contains('closed');
    localStorage.setItem('sidebarClosed', closed ? '1' : '0');
    if (icon) {
        icon.className = closed ? 'ri-menu-unfold-fill' : 'ri-menu-fold-fill';
    }
}

function toggleErpModule(moduleId){
    const section = document.querySelector('.erp-module[data-module="' + moduleId + '"]');
    if (!section) return;
    section.classList.toggle('collapsed');
    const collapsed = section.classList.contains('collapsed');
    section.querySelector('.erp-module-head')?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

    let state = {};
    try { state = JSON.parse(localStorage.getItem('erpModulesCollapsed') || '{}'); } catch (e) { state = {}; }
    state[moduleId] = collapsed;
    localStorage.setItem('erpModulesCollapsed', JSON.stringify(state));
}
</script>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<script>
document.querySelectorAll('.erp-sortable-list').forEach(container => {

    new Sortable(container, {
        animation: 200,
        handle: '.erp-nav-drag',
        draggable: '.erp-nav-link',

        onEnd: function () {

            let order = [];

            container.querySelectorAll('.erp-nav-link').forEach((el, index) => {
                order.push({
                    name: el.dataset.id,
                    position: index + 1
                });
            });

            fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= e($csrf_token) ?>'
                },
                body: JSON.stringify(order)
            });

        }
    });

});
</script>


<script>
function toggleAdminAnalytics(){
    const box = document.getElementById('adminAnalyticsWrap');
    if(!box) return;

    box.classList.toggle('closed');
    localStorage.setItem('adminAnalyticsClosed', box.classList.contains('closed') ? '1' : '0');
}

document.addEventListener('DOMContentLoaded', function(){
    const box = document.getElementById('adminAnalyticsWrap');

    /*
        يبدأ مفتوح افتراضيًا.
        لو المستخدم قفله، نحفظ اختياره في نفس المتصفح.
    */
    if(box && localStorage.getItem('adminAnalyticsClosed') === '1'){
        box.classList.add('closed');
    }

    /*
        السلايدر الجانبي يبدأ مفتوح.
    */
    const sidebar = document.querySelector('.sidebar');
    const toggleIcon = document.getElementById('sidebarToggleIcon');
    if(sidebar && localStorage.getItem('sidebarClosed') !== '1'){
        sidebar.classList.remove('closed');
    } else if (sidebar) {
        sidebar.classList.add('closed');
    }
    if (toggleIcon && sidebar) {
        toggleIcon.className = sidebar.classList.contains('closed') ? 'ri-menu-unfold-fill' : 'ri-menu-fold-fill';
    }

    let erpState = {};
    try { erpState = JSON.parse(localStorage.getItem('erpModulesCollapsed') || '{}'); } catch (e) { erpState = {}; }
    document.querySelectorAll('.erp-module[data-module]').forEach(section => {
        const moduleId = section.getAttribute('data-module');
        if (!moduleId || !erpState[moduleId]) return;
        section.classList.add('collapsed');
        section.querySelector('.erp-module-head')?.setAttribute('aria-expanded', 'false');
    });
});
</script>


<script>
let dashLastNotifCount = null;

function toggleTopbarAccount(e){
    e.stopPropagation();

    const menu = document.getElementById('topbarAccountMenu');
    const notif = document.getElementById('dashNotifBox');
    const btn = document.querySelector('.app-topbar-account');

    if(notif){
        notif.style.display = 'none';
    }

    if(menu){
        const open = menu.style.display !== 'block';
        menu.style.display = open ? 'block' : 'none';
        if(btn){
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }
}

function toggleDashNotif(e){
    e.stopPropagation();

    const box = document.getElementById('dashNotifBox');
    const menu = document.getElementById('topbarAccountMenu');
    const accountBtn = document.querySelector('.app-topbar-account');

    if(menu){
        menu.style.display = 'none';
    }
    if(accountBtn){
        accountBtn.setAttribute('aria-expanded', 'false');
    }

    if(box){
        box.style.display = (box.style.display === 'block') ? 'none' : 'block';
    }
}

function dashPlayNotifSound(){
    try{
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;

        if(!AudioContextClass){
            return;
        }

        const audioCtx = new AudioContextClass();
        const oscillator = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();

        oscillator.type = "sine";
        oscillator.frequency.setValueAtTime(880, audioCtx.currentTime);

        gainNode.gain.setValueAtTime(0.0001, audioCtx.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.16, audioCtx.currentTime + 0.01);
        gainNode.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.18);

        oscillator.connect(gainNode);
        gainNode.connect(audioCtx.destination);

        oscillator.start(audioCtx.currentTime);
        oscillator.stop(audioCtx.currentTime + 0.18);

        setTimeout(function(){
            if(audioCtx && audioCtx.close){
                audioCtx.close();
            }
        }, 350);
    }catch(e){}
}


function dashRefreshNotifications(){
    const countEl = document.getElementById("dashNotifCount");
    const listEl = document.getElementById("dashNotifList");
    const refreshText = document.getElementById("dashNotifRefreshText");

    if(!countEl || !listEl){
        return;
    }

    fetch("notifications_fetch.php", {
        method: "GET",
        headers: {
            "X-Requested-With": "XMLHttpRequest"
        },
        cache: "no-store"
    })
    .then(function(res){
        return res.json();
    })
    .then(function(data){
        if(!data || !data.success){
            return;
        }

        let count = Number(data.count || 0);

        if(dashLastNotifCount !== null && count > dashLastNotifCount){
            dashPlayNotifSound();
        }

        dashLastNotifCount = count;

        if(count > 0){
            countEl.style.display = "flex";
            countEl.textContent = count;
        }else{
            countEl.style.display = "none";
            countEl.textContent = "0";
        }

        listEl.innerHTML = data.html || '<div class="dash-notif-empty">✅ لا توجد إشعارات</div>';
        if(refreshText){
            refreshText.textContent = "آخر تحديث " + new Date().toLocaleTimeString("ar-EG", {
                hour: "2-digit",
                minute: "2-digit",
                second: "2-digit"
            });
        }
    })
    .catch(function(){
        if(refreshText){
            refreshText.textContent = "تعذر التحديث";
        }
    });
}

document.addEventListener('click', function(){
    const box = document.getElementById('dashNotifBox');
    const menu = document.getElementById('topbarAccountMenu');
    const accountBtn = document.querySelector('.app-topbar-account');

    if(box){
        box.style.display = 'none';
    }

    if(menu){
        menu.style.display = 'none';
    }

    if(accountBtn){
        accountBtn.setAttribute('aria-expanded', 'false');
    }
});

const dashNotifBoxEl = document.getElementById('dashNotifBox');
if(dashNotifBoxEl){
    dashNotifBoxEl.addEventListener('click', function(e){
        e.stopPropagation();
    });
}

const topbarAccountMenuEl = document.getElementById('topbarAccountMenu');
if(topbarAccountMenuEl){
    topbarAccountMenuEl.addEventListener('click', function(e){
        e.stopPropagation();
    });
}


document.addEventListener('DOMContentLoaded', function(){
    dashRefreshNotifications();
    setInterval(dashRefreshNotifications, 5000);
});

/* ================= Dashboard Messages Badge ================= */
let dashMsgLastCount = null;

function updateDashMessagesBadge(count){
    const badge = document.getElementById("dashMsgCount");
    const btn = document.querySelector(".sidebar-msg-btn");

    if(!badge){
        return;
    }

    count = Number(count || 0);

    if(count > 0){
        badge.style.display = "";
        badge.textContent = count > 99 ? "99+" : String(count);
        if(btn){ btn.classList.add("has-unread"); }
    }else{
        badge.style.display = "none";
        badge.textContent = "0";
        if(btn){ btn.classList.remove("has-unread"); }
    }
}

function playDashMessageSound(){
    /*
        صوت الرسائل في الداشبورد:
        Pop-Pop مختلف عن صوت جرس الإشعارات العادي.
    */
    try{
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if(!AudioContextClass) return;

        const audioCtx = new AudioContextClass();

        function tone(freq, start, duration, gainValue){
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();

            oscillator.type = "triangle";
            oscillator.frequency.setValueAtTime(freq, audioCtx.currentTime + start);

            gainNode.gain.setValueAtTime(0.0001, audioCtx.currentTime + start);
            gainNode.gain.exponentialRampToValueAtTime(gainValue, audioCtx.currentTime + start + 0.012);
            gainNode.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + start + duration);

            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);

            oscillator.start(audioCtx.currentTime + start);
            oscillator.stop(audioCtx.currentTime + start + duration + 0.02);
        }

        tone(620, 0.00, 0.13, 0.11);
        tone(930, 0.15, 0.16, 0.09);

        setTimeout(function(){
            if(audioCtx && audioCtx.close) audioCtx.close();
        }, 520);
    }catch(e){}
}
function fetchDashMessagesUnread(){
    fetch("messages_unread_count.php", {cache:"no-store"})
        .then(function(r){ return r.json(); })
        .then(function(data){
            if(!data || !data.success){
                return;
            }

            const count = Number(data.count || 0);

            if(dashMsgLastCount !== null && count > dashMsgLastCount){
                playDashMessageSound();
            }

            dashMsgLastCount = count;
            updateDashMessagesBadge(count);
        })
        .catch(function(){});
}

updateDashMessagesBadge(<?= (int)$dashMessagesUnread ?>);
dashMsgLastCount = <?= (int)$dashMessagesUnread ?>;
fetchDashMessagesUnread();
setInterval(fetchDashMessagesUnread, 5000);

</script>

</body>
</html>
