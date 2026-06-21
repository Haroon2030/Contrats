<?php
/**
 * استيراد database.sql إلى PostgreSQL (Neon)
 * الاستخدام: php database/import_neon.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Core/VcDb.php';

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
    fwrite(STDERR, "DATABASE_URL missing in .env\n");
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

$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
$raw = preg_replace('/\/\*![\s\S]*?\*\//', '', $raw) ?? $raw;
$raw = preg_replace('/^--.*$/m', '', $raw) ?? $raw;

function convertMysqlStatement(string $sql): ?string
{
    $sql = trim($sql);
    if ($sql === '') {
        return null;
    }

    if (preg_match('/^(SET |USE |CREATE DATABASE|START TRANSACTION|COMMIT)/i', $sql)) {
        return null;
    }

    if (preg_match('/^ALTER\s+TABLE/i', $sql)) {
        return convertAlterTable($sql);
    }

    $sql = str_replace('`', '', $sql);

    $sql = preg_replace('/\bint\s*\(\s*\d+\s*\)/i', 'INTEGER', $sql) ?? $sql;
    $sql = preg_replace('/\btinyint\s*\(\s*\d+\s*\)/i', 'SMALLINT', $sql) ?? $sql;
    $sql = preg_replace('/\btinyint\b/i', 'SMALLINT', $sql) ?? $sql;
    $sql = preg_replace('/\bdatetime\b/i', 'TIMESTAMP', $sql) ?? $sql;
    $sql = preg_replace('/\blongtext\b/i', 'TEXT', $sql) ?? $sql;
    $sql = preg_replace('/\bmediumtext\b/i', 'TEXT', $sql) ?? $sql;
    $sql = preg_replace('/\bdouble\b/i', 'DOUBLE PRECISION', $sql) ?? $sql;
    $sql = preg_replace('/\benum\s*\([^)]+\)/i', 'VARCHAR(50)', $sql) ?? $sql;
    $sql = preg_replace('/\s+ENGINE\s*=\s*InnoDB[^;]*/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\s+COLLATE\s*=\s*[\w_]+/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\bcurrent_timestamp\s*\(\s*\)/i', 'CURRENT_TIMESTAMP', $sql) ?? $sql;

    $sql = preg_replace("/'0000-00-00'/", 'NULL', $sql) ?? $sql;
    $sql = preg_replace("/'0000-00-00 00:00:00'/", 'NULL', $sql) ?? $sql;

    if (preg_match('/^CREATE\s+TABLE/i', $sql)) {
        $sql = preg_replace('/,\s*\)/', ')', $sql) ?? $sql;
        $sql = preg_replace('/\bid\s+INTEGER\s+NOT\s+NULL\b/i', 'id SERIAL PRIMARY KEY', $sql) ?? $sql;
    }

    return $sql;
}

function convertAlterTable(string $sql): ?string
{
    if (preg_match('/ADD\s+PRIMARY\s+KEY\s*\(([^)]+)\)/i', $sql, $m)) {
        if (preg_match('/ALTER\s+TABLE\s+(\w+)/i', $sql, $t)) {
            return sprintf('ALTER TABLE %s ADD PRIMARY KEY (%s);', $t[1], $m[1]);
        }
    }

    if (preg_match('/ADD\s+UNIQUE\s+KEY\s+(\w+)\s*\(([^)]+)\)/i', $sql, $m)) {
        if (preg_match('/ALTER\s+TABLE\s+(\w+)/i', $sql, $t)) {
            return sprintf('ALTER TABLE %s ADD CONSTRAINT %s UNIQUE (%s);', $t[1], $m[1], $m[2]);
        }
    }

    if (preg_match('/ADD\s+KEY\s+(\w+)\s*\(([^)]+)\)/i', $sql, $m)) {
        if (preg_match('/ALTER\s+TABLE\s+(\w+)/i', $sql, $t)) {
            return sprintf('CREATE INDEX IF NOT EXISTS %s ON %s (%s);', $m[1], $t[1], $m[2]);
        }
    }

    if (preg_match('/MODIFY.*AUTO_INCREMENT/i', $sql)) {
        return null;
    }

    return null;
}

/** @return list<string> */
function extractSqlStatements(string $raw): array
{
    $statements = [];

    if (preg_match_all('/CREATE\s+TABLE\s+[\s\S]*?;/i', $raw, $m)) {
        $statements = array_merge($statements, $m[0]);
    }

    if (preg_match_all('/ALTER\s+TABLE\s+[\s\S]*?;/i', $raw, $m)) {
        $statements = array_merge($statements, $m[0]);
    }

    if (preg_match_all('/INSERT\s+INTO\s+[\s\S]*?;/i', $raw, $m)) {
        $statements = array_merge($statements, $m[0]);
    }

    return $statements;
}

try {
    $db = VcDb::fromDatabaseUrl($url);
    $pdo = $db->pdo();

    if (in_array('--fresh', $argv ?? [], true)) {
        $pdo->exec('DROP SCHEMA public CASCADE');
        $pdo->exec('CREATE SCHEMA public');
        $pdo->exec('GRANT ALL ON SCHEMA public TO public');
        echo "Schema reset.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$statements = extractSqlStatements($raw);
$ok = 0;
$skip = 0;
$fail = 0;
$tables = [];

foreach ($statements as $stmt) {
    $converted = convertMysqlStatement($stmt);
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
