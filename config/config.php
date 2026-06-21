<?php

date_default_timezone_set('Asia/Riyadh');

require_once dirname(__DIR__) . '/app/Core/VcDb.php';

/**
 * قراءة متغير بيئة — Docker/Apache قد لا يعرّضه عبر getenv() فقط.
 */
function vcEnv(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }

    return $default;
}

/**
 * تحميل المتغيرات من .env
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

$vcRoot = dirname(__DIR__);
vcLoadEnv($vcRoot);

$appEnv = strtolower(trim((string) (vcEnv('APP_ENV', 'local') ?? 'local')));

try {
    if ($appEnv === 'production') {
        $databaseUrl = trim((string) (vcEnv('DATABASE_URL', '') ?? ''));
        if ($databaseUrl === '') {
            throw new RuntimeException('DATABASE_URL غير معرّف لبيئة الإنتاج');
        }
        $conn = VcDb::fromDatabaseUrl($databaseUrl);
    } else {
        $sqlitePath = trim((string) (vcEnv('SQLITE_PATH', 'database/local.sqlite') ?? 'database/local.sqlite'));
        if (!preg_match('/^[A-Za-z]:[\\\\\\/]|^\//', $sqlitePath)) {
            $sqlitePath = $vcRoot . '/' . ltrim(str_replace('\\', '/', $sqlitePath), '/');
        }
        $conn = VcDb::fromSqlite($sqlitePath);
    }
} catch (Throwable $e) {
    http_response_code(500);
    die('❌ تعذر الاتصال بقاعدة البيانات: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
