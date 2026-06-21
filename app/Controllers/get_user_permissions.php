<?php
require_once VC_HELPERS . '/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=UTF-8");

$uid = (int)($_SESSION['user_id'] ?? 0);

if ($uid <= 0) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}


$stmtAdmin = $conn->prepare("SELECT role, is_admin FROM users WHERE id = ? LIMIT 1");
$stmtAdmin->bind_param("i", $uid);
$stmtAdmin->execute();
$current = $stmtAdmin->get_result()->fetch_assoc();
$stmtAdmin->close();

$isAdmin = !empty($current) && (
    (int)($current['is_admin'] ?? 0) === 1 ||
    ($current['role'] ?? '') === 'admin'
);

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$user_id = (int)($_GET['user_id'] ?? 0);

if ($user_id <= 0) {
    echo json_encode([]);
    exit();
}

$stmt = $conn->prepare("
    SELECT page_id, scope
    FROM user_permissions
    WHERE user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

$data = [];

while ($row = $res->fetch_assoc()) {
    $data[] = [
        "page_id" => (int)$row["page_id"],
        "scope" => in_array($row["scope"], ["own", "team", "all"], true) ? $row["scope"] : "own"
    ];
}

$stmt->close();

echo json_encode($data, JSON_UNESCAPED_UNICODE);
exit();
