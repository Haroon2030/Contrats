<?php
/**
 * تشغيل migrations على قاعدة الإنتاج (PostgreSQL / Neon)
 *
 * الاستخدام:
 *   php database/migrate.php           — تطبيق التغييرات الجديدة فقط
 *   php database/migrate.php --status  — عرض المُطبّق والمعلّق
 *   php database/migrate.php --baseline — تسجيل الملفات الحالية دون تنفيذ (مرة واحدة للقواعد الموجودة)
 *
 * ملاحظة: الاستيراد الكامل database/import_neon.php --fresh للإعداد الأولي اليدوي فقط.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Core/VcDb.php';
require_once __DIR__ . '/Migrator.php';

function vcMigrateLoadEnv(string $root): void
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
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function vcMigrateEnv(string $key, ?string $default = null): ?string
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

vcMigrateLoadEnv($root);

$appEnv = strtolower(trim((string) (vcMigrateEnv('APP_ENV', 'local') ?? 'local')));
$argv = $argv ?? [];
$statusOnly = in_array('--status', $argv, true);
$baseline = in_array('--baseline', $argv, true);

if ($appEnv !== 'production') {
    echo "Skip: migrations run only when APP_ENV=production\n";
    exit(0);
}

$url = trim((string) (vcMigrateEnv('DATABASE_URL', '') ?? ''));
if ($url === '') {
    fwrite(STDERR, "DATABASE_URL is required for production migrations.\n");
    exit(1);
}

try {
    $pdo = VcDb::fromDatabaseUrl($url)->pdo();
    $migrator = new Migrator($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($statusOnly) {
    echo "Applied:\n";
    foreach ($migrator->getApplied() as $name) {
        echo "  [x] {$name}\n";
    }
    echo "Pending:\n";
    foreach ($migrator->getPending() as $name) {
        echo "  [ ] {$name}\n";
    }
    exit(0);
}

if ($baseline) {
    $count = $migrator->baseline();
    echo "Baseline recorded {$count} migration(s).\n";
    exit(0);
}

$pending = $migrator->getPending();
if ($pending === []) {
    echo "No pending migrations.\n";
    exit(0);
}

echo "Pending migrations:\n";
foreach ($pending as $name) {
    echo "  - {$name}\n";
}

try {
    $applied = $migrator->runPending();
    echo "Applied {$applied} migration(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
