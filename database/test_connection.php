<?php
require dirname(__DIR__) . '/config/config.php';

echo "Driver: " . $conn->driver() . "\n";
echo "OK: connected\n";

if ($conn->driver() === 'sqlite') {
    $tables = $conn->pdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
} else {
    $tables = $conn->pdo()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema()")->fetchColumn();
}
echo "Tables: {$tables}\n";

try {
    $users = $conn->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo "Users: {$users}\n";
} catch (Throwable $e) {
    echo "Users: " . $e->getMessage() . "\n";
}
