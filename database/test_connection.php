<?php
require dirname(__DIR__) . '/config/config.php';
echo "OK: connected to PostgreSQL\n";
$r = $conn->pdo()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()")->fetchColumn();
echo "Tables: {$r}\n";
try {
    $users = $conn->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo "Users: {$users}\n";
} catch (Throwable $e) {
    echo "Users table: " . $e->getMessage() . "\n";
}
