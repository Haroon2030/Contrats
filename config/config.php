<?php

date_default_timezone_set('Asia/Riyadh');

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'mordun');
define('DB_CHARSET', 'utf8mb4');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset(DB_CHARSET);
    $conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
    $conn->query("SET CHARACTER SET utf8mb4");
} catch (Throwable $e) {
    http_response_code(500);
    die('❌ تعذر الاتصال بقاعدة البيانات: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
