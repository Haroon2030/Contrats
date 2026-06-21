<?php
/**
 * استيراد database.sql إلى PostgreSQL (Neon) — إعداد أولي يدوي فقط
 *
 * ⚠️ لا يُستخدم أثناء النشر التلقائي — استخدم database/migrate.php للتحديثات.
 * ⚠️ --fresh يحذف كل البيانات — للإعداد الأولي على قاعدة فارغة فقط.
 *
 * الاستخدام: php database/import_neon.php --fresh
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Core/VcDb.php';
require_once $root . '/database/import_shared.php';

function vcLoadEnvInline(string $root): void
{
    $envFile = $root . '/.env';
    if (!is_file($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
    }
}

vcLoadEnvInline($root);

$url = getenv('DATABASE_URL') ?: '';
if ($url === '') {
    fwrite(STDERR, "DATABASE_URL missing in .env (set APP_ENV=production)\n");
    exit(1);
}

$source = $root . '/database/database.sql';
if (!is_file($source)) {
    fwrite(STDERR, "Missing database.sql\n");
    exit(1);
}

$raw = file_get_contents($source);
if ($raw === false) {
    exit(1);
}

try {
    $db = VcDb::fromDatabaseUrl($url);
    $pdo = $db->pdo();

    $argv = $argv ?? [];
    if (in_array('--fresh', $argv, true)) {
        $pdo->exec('DROP SCHEMA public CASCADE');
        $pdo->exec('CREATE SCHEMA public');
        $pdo->exec('GRANT ALL ON SCHEMA public TO public');
        echo "Schema reset.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$statements = vcImportExtractStatements($raw);
$ok = 0;
$skip = 0;
$fail = 0;
$tables = [];

foreach ($statements as $stmt) {
    $converted = vcImportConvertMysqlStatement($stmt, 'pgsql');
    if ($converted === null) {
        $skip++;
        continue;
    }

    try {
        $pdo->exec($converted);
        $ok++;
        if (preg_match('/^CREATE\s+TABLE\s+(\w+)/i', $converted, $m)) {
            $tables[$m[1]] = true;
            echo "TABLE: {$m[1]}\n";
        }
    } catch (Throwable $e) {
        $fail++;
        fwrite(STDERR, "FAIL: " . substr(str_replace("\n", ' ', $converted), 0, 120) . "\n  -> " . $e->getMessage() . "\n");
    }
}

foreach (array_keys($tables) as $table) {
    try {
        $pdo->exec("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1), true)");
    } catch (Throwable) {
    }
}

echo "\nDone. ok={$ok} skip={$skip} fail={$fail} tables=" . count($tables) . "\n";
