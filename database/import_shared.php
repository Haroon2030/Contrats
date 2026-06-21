<?php

declare(strict_types=1);

function vcImportExtractStatements(string $raw): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $raw = preg_replace('/\/\*![\s\S]*?\*\//', '', $raw) ?? $raw;
    $raw = preg_replace('/^--.*$/m', '', $raw) ?? $raw;

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

function vcImportConvertAlterTable(string $sql, string $driver): ?string
{
    if (preg_match('/ADD\s+PRIMARY\s+KEY\s*\(([^)]+)\)/i', $sql, $m)) {
        if (preg_match('/ALTER\s+TABLE\s+(\w+)/i', $sql, $t)) {
            return sprintf('ALTER TABLE %s ADD PRIMARY KEY (%s);', $t[1], $m[1]);
        }
    }

    if (preg_match('/ADD\s+UNIQUE\s+KEY\s+(\w+)\s*\(([^)]+)\)/i', $sql, $m)) {
        if (preg_match('/ALTER\s+TABLE\s+(\w+)/i', $sql, $t)) {
            if ($driver === 'sqlite') {
                return sprintf('CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s);', $m[1], $t[1], $m[2]);
            }

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

function vcImportConvertMysqlStatement(string $sql, string $driver): ?string
{
    $sql = trim($sql);
    if ($sql === '') {
        return null;
    }

    if (preg_match('/^(SET |USE |CREATE DATABASE|START TRANSACTION|COMMIT)/i', $sql)) {
        return null;
    }

    if (preg_match('/^ALTER\s+TABLE/i', $sql)) {
        return vcImportConvertAlterTable($sql, $driver);
    }

    $sql = str_replace('`', '', $sql);
    $sql = preg_replace('/\bint\s*\(\s*\d+\s*\)/i', 'INTEGER', $sql) ?? $sql;
    $sql = preg_replace('/\btinyint\s*\(\s*\d+\s*\)/i', 'SMALLINT', $sql) ?? $sql;
    $sql = preg_replace('/\btinyint\b/i', 'SMALLINT', $sql) ?? $sql;
    $sql = preg_replace('/\bdatetime\b/i', 'TIMESTAMP', $sql) ?? $sql;
    $sql = preg_replace('/\blongtext\b/i', 'TEXT', $sql) ?? $sql;
    $sql = preg_replace('/\bmediumtext\b/i', 'TEXT', $sql) ?? $sql;
    $sql = preg_replace('/\benum\s*\([^)]+\)/i', $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(50)', $sql) ?? $sql;
    $sql = preg_replace('/\s+ENGINE\s*=\s*InnoDB[^;]*/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\s+COLLATE\s*=\s*[\w_]+/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\bcurrent_timestamp\s*\(\s*\)/i', 'CURRENT_TIMESTAMP', $sql) ?? $sql;
    $sql = preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\(\))?/i', '', $sql) ?? $sql;
    $sql = preg_replace("/'0000-00-00'/", 'NULL', $sql) ?? $sql;
    $sql = preg_replace("/'0000-00-00 00:00:00'/", 'NULL', $sql) ?? $sql;

    if (preg_match('/^CREATE\s+TABLE/i', $sql)) {
        $sql = preg_replace('/,\s*\)/', ')', $sql) ?? $sql;
        if ($driver === 'sqlite') {
            $sql = preg_replace('/\bid\s+INTEGER\s+NOT\s+NULL\b/i', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $sql) ?? $sql;
        } else {
            $sql = preg_replace('/\bid\s+INTEGER\s+NOT\s+NULL\b/i', 'id SERIAL PRIMARY KEY', $sql) ?? $sql;
        }
    }

    if ($driver === 'pgsql') {
        $sql = preg_replace('/\bdouble\b/i', 'DOUBLE PRECISION', $sql) ?? $sql;
    } else {
        $sql = preg_replace('/\bdouble\b/i', 'REAL', $sql) ?? $sql;
    }

    return $sql;
}
