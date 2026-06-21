<?php
require_once VC_HELPERS . '/auth.php';



date_default_timezone_set('Asia/Riyadh');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=UTF-8");

function jsonResponse(bool $success, string $message, array $extra = []): void {
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    http_response_code(401);
    jsonResponse(false, "not allowed");
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(false, "method not allowed");
}


if (isset($_POST['csrf_token'])) {
    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        jsonResponse(false, "طلب غير صالح");
    }
}


$id = (int)($_POST['id'] ?? 0);
$date = trim((string)($_POST['date'] ?? ''));

if ($id <= 0 || $date === '') {
    http_response_code(400);
    jsonResponse(false, "missing data");
}


if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    jsonResponse(false, "invalid date");
}


$dt = DateTime::createFromFormat('Y-m-d', $date);
$errors = DateTime::getLastErrors();

if (!$dt || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $dt->format('Y-m-d') !== $date) {
    http_response_code(400);
    jsonResponse(false, "invalid date");
}


$is_admin = false;

$stmtUser = $conn->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");

if (!$stmtUser) {
    http_response_code(500);
    jsonResponse(false, "user query error");
}

$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$userRow = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

if ($userRow && (int)($userRow['is_admin'] ?? 0) === 1) {
    $is_admin = true;
}


$scope = 'own';
$page_name = 'drafts';

$stmtPerm = $conn->prepare("
    SELECT up.scope 
    FROM user_permissions up
    JOIN pages pg ON pg.id = up.page_id
    WHERE up.user_id = ? 
    AND pg.name = ?
    LIMIT 1
");

if ($stmtPerm) {
    $stmtPerm->bind_param("is", $user_id, $page_name);
    $stmtPerm->execute();
    $permRow = $stmtPerm->get_result()->fetch_assoc();
    $stmtPerm->close();

    if (!empty($permRow['scope'])) {
        $scope = $permRow['scope'];
    }
}


if ($is_admin || $scope === 'all') {

    $stmt = $conn->prepare("
        UPDATE contracts
        SET reminder_date = ?
        WHERE id = ?
        AND status = 'draft'
        LIMIT 1
    ");

    if (!$stmt) {
        http_response_code(500);
        jsonResponse(false, "sql error");
    }

    $stmt->bind_param("si", $date, $id);

} else {

    $stmt = $conn->prepare("
        UPDATE contracts
        SET reminder_date = ?
        WHERE id = ?
        AND created_by = ?
        AND status = 'draft'
        LIMIT 1
    ");

    if (!$stmt) {
        http_response_code(500);
        jsonResponse(false, "sql error");
    }

    $stmt->bind_param("sii", $date, $id, $user_id);
}

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    jsonResponse(false, "execute error");
}

$affected = $stmt->affected_rows;
$stmt->close();

if ($affected > 0) {
    jsonResponse(true, "تم حفظ تاريخ التذكير", [
        "date" => $date
    ]);
}


jsonResponse(true, "لا يوجد تغيير أو التاريخ محفوظ بالفعل", [
    "date" => $date,
    "changed" => false
]);
