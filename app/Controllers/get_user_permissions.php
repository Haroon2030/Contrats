<?php

require_once VC_HELPERS . '/scope_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

$uid = (int) ($_SESSION['user_id'] ?? 0);

if ($uid <= 0) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}

$stmtAdmin = $conn->prepare('SELECT role, is_admin, job_role, session_version, is_active FROM users WHERE id = ? LIMIT 1');
if (!$stmtAdmin) {
    http_response_code(500);
    echo json_encode(['error' => 'db_prepare_failed']);
    exit();
}

$stmtAdmin->bind_param('i', $uid);
if (!$stmtAdmin->execute()) {
    $stmtAdmin->close();
    http_response_code(500);
    echo json_encode(['error' => 'db_query_failed']);
    exit();
}

$current = $stmtAdmin->get_result()->fetch_assoc();
$stmtAdmin->close();

if (empty($current) || (int) ($current['is_active'] ?? 1) !== 1) {
    http_response_code(401);
    echo json_encode([]);
    exit();
}

if (isset($_SESSION['session_version'])) {
    $dbSessionVersion = (int) ($current['session_version'] ?? 1);
    if ((int) $_SESSION['session_version'] !== $dbSessionVersion) {
        http_response_code(401);
        echo json_encode([]);
        exit();
    }
}

$isAdmin = !empty($current) && (
    (int) ($current['is_admin'] ?? 0) === 1 ||
    ($current['role'] ?? '') === 'admin' ||
    in_array((string) ($current['job_role'] ?? ''), ['admin', 'commercial_manager'], true)
);

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$user_id = (int) ($_GET['user_id'] ?? 0);

if ($user_id <= 0) {
    echo json_encode([]);
    exit();
}

if (!vcTableExists($conn, 'user_permissions')) {
    echo json_encode([]);
    exit();
}

$stmt = $conn->prepare('
    SELECT page_id, scope
    FROM user_permissions
    WHERE user_id = ?
');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'db_prepare_failed']);
    exit();
}

$stmt->bind_param('i', $user_id);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['error' => 'db_query_failed']);
    exit();
}

$res = $stmt->get_result();
$data = [];

while ($row = $res->fetch_assoc()) {
    $data[] = [
        'page_id' => (int) $row['page_id'],
        'scope' => in_array($row['scope'], ['own', 'team', 'all'], true) ? $row['scope'] : 'own',
    ];
}

$stmt->close();

echo json_encode($data, JSON_UNESCAPED_UNICODE);
exit();
