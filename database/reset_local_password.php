<?php
/**
 * إعادة تعيين كلمة مرور محلياً فقط (SQLite / APP_ENV=local)
 *
 * الاستخدام:
 *   php database/reset_local_password.php admin admin123
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/config.php';

$appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: 'local')));
if ($appEnv !== 'local' || $conn->driver() !== 'sqlite') {
    fwrite(STDERR, "هذا السكربت للبيئة المحلية فقط.\n");
    exit(1);
}

$username = trim((string) ($argv[1] ?? ''));
$newPassword = (string) ($argv[2] ?? '');

if ($username === '' || $newPassword === '') {
    fwrite(STDERR, "الاستخدام: php database/reset_local_password.php <username> <new_password>\n");
    exit(1);
}

if (mb_strlen($newPassword, 'UTF-8') < 6) {
    fwrite(STDERR, "كلمة المرور يجب أن تكون 6 أحرف على الأقل.\n");
    exit(1);
}

$stmt = $conn->prepare('SELECT id, username, COALESCE(is_active, 1) AS is_active FROM users WHERE TRIM(username) = ? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    fwrite(STDERR, "المستخدم غير موجود: {$username}\n");
    exit(1);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$uid = (int) $user['id'];

$upd = $conn->prepare('
    UPDATE users
    SET password = ?,
        session_version = COALESCE(session_version, 1) + 1,
        last_password_change = CURRENT_TIMESTAMP,
        is_active = 1
    WHERE id = ?
');
$upd->bind_param('si', $hash, $uid);

if (!$upd->execute()) {
    fwrite(STDERR, 'فشل التحديث: ' . $upd->error . PHP_EOL);
    exit(1);
}
$upd->close();

echo "تم تعيين كلمة مرور جديدة للمستخدم: {$user['username']}" . PHP_EOL;
echo "يمكنك تسجيل الدخول محلياً الآن." . PHP_EOL;
