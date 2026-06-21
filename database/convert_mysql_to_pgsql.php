<?php
/**
 * تحويل database/database.sql (MySQL) إلى database/database.pgsql.sql
 * الاستخدام: php database/convert_mysql_to_pgsql.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/database/database.sql';
$target = $root . '/database/database.pgsql.sql';

if (!is_file($source)) {
    fwrite(STDERR, "Missing: {$source}\n");
    exit(1);
}

$sql = file_get_contents($source);
if ($sql === false) {
    exit(1);
}

$lines = explode("\n", $sql);
$out = [];
$out[] = '-- PostgreSQL schema + data (converted from MySQL)';
$out[] = 'BEGIN;';
$out[] = "SET client_encoding = 'UTF8';";
$out[] = '';

$skip = static function (string $line): bool {
    $t = trim($line);
    if ($t === '') {
        return false;
    }
    if (str_starts_with($t, '--')) {
        return true;
    }
    if (str_starts_with($t, '/*')) {
        return true;
    }
    if (preg_match('/^(SET |USE |CREATE DATABASE|START TRANSACTION|COMMIT|/\*!)/i', $t)) {
        return true;
    }

    return false;
};

$convertLine = static function (string $line): string {
    $line = str_replace('`', '', $line);

    $line = preg_replace('/\bint\s*\(\s*\d+\s*\)/i', 'INTEGER', $line) ?? $line;
    $line = preg_replace('/\btinyint\s*\(\s*1\s*\)/i', 'SMALLINT', $line) ?? $line;
    $line = preg_replace('/\btinyint\b/i', 'SMALLINT', $line) ?? $line;
    $line = preg_replace('/\bdatetime\b/i', 'TIMESTAMP', $line) ?? $line;
    $line = preg_replace('/\blongtext\b/i', 'TEXT', $line) ?? $line;
    $line = preg_replace('/\bmediumtext\b/i', 'TEXT', $line) ?? $line;
    $line = preg_replace('/\bdouble\b/i', 'DOUBLE PRECISION', $line) ?? $line;
    $line = preg_replace('/\benum\s*\([^)]+\)/i', 'VARCHAR(50)', $line) ?? $line;

    $line = preg_replace('/\s+ENGINE\s*=\s*InnoDB[^;]*/i', '', $line) ?? $line;
    $line = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $line) ?? $line;
    $line = preg_replace('/\s+COLLATE\s*=\s*[\w_]+/i', '', $line) ?? $line;

    $line = preg_replace('/\bADD\s+UNIQUE\s+KEY\s+(\w+)\s*/i', 'ADD CONSTRAINT $1 UNIQUE ', $line) ?? $line;
    $line = preg_replace('/\bADD\s+KEY\s+(\w+)\s*/i', 'CREATE INDEX IF NOT EXISTS $1 ON ', $line) ?? $line;

    if (preg_match('/^\s*ALTER\s+TABLE\s+(\w+)\s*$/i', trim($line))) {
        return '';
    }

    if (preg_match('/^\s*ADD\s+PRIMARY\s+KEY\s*\(([^)]+)\)\s*,?\s*$/i', trim($line), $m)) {
        return '';
    }

    if (preg_match('/^\s*ADD\s+(UNIQUE\s+KEY|KEY)\s+/i', trim($line))) {
        return '-- index: ' . trim($line);
    }

    if (preg_match('/MODIFY\s+`?id`?\s+int\([^)]+\)\s+NOT\s+NULL\s+AUTO_INCREMENT,\s*AUTO_INCREMENT=(\d+)/i', $line, $m)) {
        return '';
    }

    if (stripos($line, 'MODIFY') !== false && stripos($line, 'AUTO_INCREMENT') !== false) {
        return '';
    }

    if (stripos($line, 'AUTO_INCREMENT') !== false) {
        return '';
    }

    return rtrim($line);
};

$inCreate = false;
$createBuffer = '';
$tablesWithId = [];

foreach ($lines as $line) {
    if ($skip($line)) {
        continue;
    }

    if (preg_match('/^CREATE\s+TABLE\s+(\w+)/i', trim($line), $m)) {
        $inCreate = true;
        $createBuffer = $convertLine($line) . "\n";
        $tablesWithId[$m[1]] = true;
        continue;
    }

    if ($inCreate) {
        $createBuffer .= $convertLine($line) . "\n";
        if (str_contains($line, ';')) {
            $createBuffer = preg_replace('/,\s*\)/', "\n)", $createBuffer) ?? $createBuffer;
            $out[] = trim($createBuffer);
            $out[] = '';
            $inCreate = false;
            $createBuffer = '';
        }
        continue;
    }

    if (preg_match('/^INSERT\s+INTO/i', trim($line))) {
        $out[] = $convertLine($line);
        continue;
    }

    if (preg_match('/^ALTER\s+TABLE/i', trim($line))) {
        continue;
    }
}

$out[] = '';
$out[] = '-- Sequences after data import';
foreach (array_keys($tablesWithId) as $table) {
    $out[] = "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1));";
}
$out[] = '';
$out[] = 'COMMIT;';

file_put_contents($target, implode("\n", $out));
echo "Written: {$target}\n";
