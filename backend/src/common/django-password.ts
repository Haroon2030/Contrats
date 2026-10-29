import { createHash, pbkdf2Sync, randomBytes, timingSafeEqual } from 'crypto';

/**
 * التحقق من كلمات مرور Django (pbkdf2_sha256$iterations$salt$hash).
 * بهذه الطريقة يعمل نفس الحساب في النظام القديم والجديد دون إعادة تعيين كلمات المرور.
 */
export function verifyDjangoPassword(password: string, encoded: string): boolean {
  if (!encoded) return false;
  const parts = encoded.split('$');
  if (parts.length !== 4 || parts[0] !== 'pbkdf2_sha256') return false;
  const iterations = Number(parts[1]);
  const salt = parts[2];
  const expected = Buffer.from(parts[3], 'base64');
  const actual = pbkdf2Sync(password, salt, iterations, expected.length, 'sha256');
  return actual.length === expected.length && timingSafeEqual(actual, expected);
}

/** إنشاء hash بصيغة Django حتى يبقى الحساب صالحاً في النظامين. */
export function makeDjangoPassword(password: string, iterations = 1_000_000): string {
  const salt = randomBytes(12).toString('base64').replace(/[^a-zA-Z0-9]/g, '').slice(0, 22);
  const hash = pbkdf2Sync(password, salt, iterations, 32, 'sha256').toString('base64');
  return `pbkdf2_sha256$${iterations}$${salt}$${hash}`;
}

export function sha256(text: string): string {
  return createHash('sha256').update(text).digest('hex');
}
