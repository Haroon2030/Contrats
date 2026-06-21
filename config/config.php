<?php

date_default_timezone_set('Asia/Riyadh');

require_once dirname(__DIR__) . '/app/Core/VcDb.php';

/**
 * تحميل DATABASE_URL من .env أو متغير البيئة.
 */
function vcLoadEnv(string $root): void
{
    $envFile = $root . '/.env';
    if (!is_file($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

vcLoadEnv(dirname(__DIR__));

$databaseUrl = getenv('DATABASE_URL') ?: '';

if ($databaseUrl === '') {
    http_response_code(500);
    die('❌ DATABASE_URL غير معرّف. أنشئ ملف .env من .env.example');
}

try {
    $conn = VcDb::fromDatabaseUrl($databaseUrl);
} catch (Throwable $e) {
    http_response_code(500);
    die('❌ تعذر الاتصال بقاعدة البيانات: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
