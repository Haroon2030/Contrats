/** تواريخ التقويم بتوقيت الرياض (المنطقة الزمنية للشركة). */
const TZ = 'Asia/Riyadh';
const DAY_MS = 86_400_000;

/** اليوم الحالي بصيغة YYYY-MM-DD حسب توقيت الرياض. */
export function todayIso(): string {
  return new Date().toLocaleDateString('en-CA', { timeZone: TZ });
}

export function isIsoDate(value: unknown): value is string {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const d = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === value;
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

/** إضافة أشهر مع الحفاظ على يوم البداية (وتقليصه لآخر يوم في الشهر القصير). */
export function addMonths(iso: string, months: number): string {
  const [y, m, d] = iso.split('-').map(Number);
  const total = m - 1 + months;
  const year = y + Math.floor(total / 12);
  const month = ((total % 12) + 12) % 12;
  const last = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
  const day = Math.min(d, last);
  return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/**
 * عدد الفترات الكاملة بين تاريخين، أو null إن لم تكن الفترات كاملة.
 * الشهر = من يوم معين إلى اليوم السابق له في الشهر التالي، والأسبوع = 7 أيام.
 */
export function fullPeriods(start: string, end: string, unit: 'week' | 'month'): number | null {
  if (end < start) return null;
  if (unit === 'week') {
    const days = daysBetween(start, end) + 1;
    return days % 7 === 0 ? days / 7 : null;
  }
  for (let k = 1; k <= 60; k++) {
    const periodEnd = addDays(addMonths(start, k), -1);
    if (periodEnd === end) return k;
    if (periodEnd > end) return null;
  }
  return null;
}

/** قيم datetime كما تُخزَّن: تُقرأ كـ UTC وتُخرج بصيغة ISO. */
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
