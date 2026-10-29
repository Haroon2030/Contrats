/** تواريخ التقويم بتوقيت الرياض (مطابقة TIME_ZONE في Django). */
const TZ = 'Asia/Riyadh';
const DAY_MS = 86_400_000;

/** اليوم الحالي بصيغة YYYY-MM-DD حسب توقيت الرياض. */
export function todayIso(): string {
  return new Date().toLocaleDateString('en-CA', { timeZone: TZ });
}

/** عدد الأيام من تاريخ إلى آخر (كلاهما YYYY-MM-DD). */
export function daysBetween(fromIso: string, toIso: string): number {
  const a = Date.parse(`${fromIso}T00:00:00Z`);
  const b = Date.parse(`${toIso}T00:00:00Z`);
  return Math.round((b - a) / DAY_MS);
}

export function addDays(iso: string, days: number): string {
  return new Date(Date.parse(`${iso}T00:00:00Z`) + days * DAY_MS).toISOString().slice(0, 10);
}

/**
 * قيم datetime: Django يخزّنها بتوقيت UTC دون معلومات منطقة،
 * لذلك نعاملها كـ UTC عند القراءة ونخرجها بصيغة ISO.
 */
export function isoDateTime(value: Date | string | null | undefined): string | null {
  if (!value) return null;
  if (value instanceof Date) return value.toISOString();
  const normalized = /[zZ]|[+-]\d{2}:?\d{2}$/.test(value) ? value : `${value.replace(' ', 'T')}Z`;
  const parsed = new Date(normalized);
  return Number.isNaN(parsed.getTime()) ? null : parsed.toISOString();
}

export function daysSince(value: Date | string | null | undefined): number {
  const iso = isoDateTime(value);
  if (!iso) return 0;
  return Math.floor((Date.now() - Date.parse(iso)) / DAY_MS);
}
