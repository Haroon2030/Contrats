import { addDays, addMonths, fullPeriods, isIsoDate } from '../common/dates';
import { ContractType, TYPE_ORDER, isServicesOnly, primaryType, sortTypes } from '../common/domain';

export interface RefCtx {
  vendorIds: Set<number>;
  departmentIds: Set<number>;
  branchIds: Set<number>;
  shelfIds: Set<number>;
  eventIds: Set<number>;
}

export interface CleanGoalLine {
  fromDate: string;
  toDate: string;
  target: number;
  discountPct: number;
}

export interface CleanRentLine {
  branchId: number;
  shelfTypeId: number;
  qty: number;
  price: number;
  unit: 'week' | 'month';
  startDate: string;
  endDate: string;
  periods: number;
  total: number;
}

export interface CleanMarketingLine {
  eventId: number;
  price: number;
  note: string;
}

export interface CleanContract {
  vendorId: number;
  departmentId: number | null;
  entryPath: 'new' | 'ready';
  types: string[];
  primaryType: string;
  repName: string;
  repTitle: string;
  repMobile: string;
  startDate: string | null;
  endDate: string | null;
  specialTerms: string;
  paymentDays: number | null;
  settlementDiscountPct: number | null;
  fixedDiscountPct: number | null;
  newItemPrice: number | null;
  creditLimit: number | null;
  goalLines: CleanGoalLine[];
  rentLines: CleanRentLine[];
  marketingLines: CleanMarketingLine[];
}

type Raw = Record<string, any>;

const num = (v: unknown): number | null => {
  if (v === null || v === undefined || v === '') return null;
  const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
  return Number.isFinite(n) ? n : null;
};
const int = (v: unknown): number | null => {
  const n = num(v);
  return n !== null && Number.isInteger(n) ? n : null;
};
const str = (v: unknown) => (typeof v === 'string' ? v.trim() : '');
const round2 = (n: number) => Math.round(n * 100) / 100;

/**
 * يتحقق من نموذج العقد ويعيد القيم المنظَّفة + أخطاء الحقول.
 *  - mode = 'draft': مرن (حفظ المسودة في أي وقت) — يلزم المورد ونوع واحد على الأقل، ويُتحقق من الصيغ المُدخلة.
 *  - mode = 'send': كامل الشروط (الإرسال للمراجعة).
 */
export function validateContract(body: Raw, mode: 'draft' | 'send', ctx: RefCtx): { errors: Record<string, string>; clean: CleanContract } {
  const errors: Record<string, string> = {};
  const strict = mode === 'send';
  const need = (key: string, message = 'هذا الحقل مطلوب.') => {
    errors[key] = errors[key] ?? message;
  };

  // ── الأساسيات ──
  const vendorId = int(body.vendorId) ?? 0;
  if (!vendorId) need('vendorId', 'اختر المورد.');
  else if (!ctx.vendorIds.has(vendorId)) errors.vendorId = 'المورد غير موجود أو موقوف.';

  const departmentId = int(body.departmentId);
  if (strict && !departmentId) need('departmentId', 'اختر القسم.');
  else if (departmentId && !ctx.departmentIds.has(departmentId)) errors.departmentId = 'القسم غير موجود أو موقوف.';

  const entryPath: 'new' | 'ready' = body.entryPath === 'ready' ? 'ready' : 'new';

  const rawTypes: string[] = Array.isArray(body.types) ? body.types.filter((t: unknown) => typeof t === 'string') : [];
  const types = sortTypes(rawTypes.filter((t) => TYPE_ORDER.includes(t)));
  if (!types.length) need('types', 'اختر نوعاً واحداً على الأقل.');
  if (types.includes(ContractType.OPEN_ACCOUNT) && types.length > 1) {
    errors.types = '«فتح حساب» عقد مستقل ولا يُجمع مع نوع آخر.';
  }

  // ── الممثل ──
  const repName = str(body.repName);
  const repTitle = str(body.repTitle);
  const repMobile = str(body.repMobile);
  if (strict) {
    if (!repName) need('repName');
    if (!repTitle) need('repTitle');
    if (!repMobile) need('repMobile');
  }
  if (repMobile && !/^05\d{8}$/.test(repMobile)) errors.repMobile = 'الجوال 10 أرقام ويبدأ بـ 05.';

  // ── التواريخ ──
  const servicesOnly = isServicesOnly(types);
  const rentOnly = types.length === 1 && types[0] === ContractType.RENT;
  const startRaw = str(body.startDate);
  let startDate: string | null = null;
  if (!startRaw) {
    if (strict) need('startDate');
  } else if (!isIsoDate(startRaw)) errors.startDate = 'تاريخ البداية غير صالح.';
  else startDate = startRaw;

  let endDate: string | null = null;
  const endRaw = str(body.endDate);
  if (!servicesOnly && !rentOnly) {
    if (!endRaw) {
      if (strict) need('endDate');
    } else if (!isIsoDate(endRaw)) errors.endDate = 'تاريخ النهاية غير صالح.';
    else endDate = endRaw;
  }

  // ── سطور الإيجار ──
  const rentLines: CleanRentLine[] = [];
  if (types.includes(ContractType.RENT)) {
    const rawLines: Raw[] = Array.isArray(body.rentLines) ? body.rentLines : [];
    if (strict && !rawLines.length) need('rentLines', 'أضف سطر إيجار واحداً على الأقل.');
    rawLines.forEach((l, i) => {
      const k = (f: string) => `rentLines.${i}.${f}`;
      const branchId = int(l.branchId) ?? 0;
      const shelfTypeId = int(l.shelfTypeId) ?? 0;
      const qty = int(l.qty);
      const price = num(l.price);
      const unit = l.unit === 'week' ? 'week' : l.unit === 'month' ? 'month' : null;
      const s = str(l.startDate);
      const e = str(l.endDate);
      if (!branchId) need(k('branchId'), 'اختر الفرع.');
      else if (!ctx.branchIds.has(branchId)) errors[k('branchId')] = 'الفرع غير موجود أو موقوف.';
      if (!shelfTypeId) need(k('shelfTypeId'), 'اختر نوع الرف.');
      else if (!ctx.shelfIds.has(shelfTypeId)) errors[k('shelfTypeId')] = 'نوع الرف غير موجود أو موقوف.';
      if (qty === null || qty < 1) errors[k('qty')] = 'العدد رقم صحيح لا يقل عن 1.';
      if (price === null || price < 0) errors[k('price')] = 'السعر رقم لا يقل عن 0.';
      if (!unit) need(k('unit'), 'اختر أسبوع أو شهر.');
      if (!isIsoDate(s)) need(k('startDate'), 'تاريخ البداية مطلوب.');
      if (!isIsoDate(e)) need(k('endDate'), 'تاريخ النهاية مطلوب.');
      if (startDate && isIsoDate(s) && s < startDate) errors[k('startDate')] = 'يبدأ السطر قبل بداية العقد.';

      let periods = 0;
      if (unit && isIsoDate(s) && isIsoDate(e) && !errors[k('startDate')] && !errors[k('endDate')]) {
        const p = fullPeriods(s, e, unit);
        if (p === null) errors[k('endDate')] = unit === 'week' ? 'يجب أن تغطي التواريخ أسابيع كاملة فقط.' : 'يجب أن تغطي التواريخ أشهراً كاملة فقط (من يوم إلى اليوم السابق له).';
        else periods = p;
      }
      if (!Object.keys(errors).some((x) => x.startsWith(`rentLines.${i}.`)) && qty && price !== null && unit) {
        rentLines.push({ branchId, shelfTypeId, qty, price, unit, startDate: s, endDate: e, periods, total: round2(periods * qty * price) });
      }
    });
    if (rentOnly && rentLines.length) {
      endDate = rentLines.reduce((max, l) => (l.endDate > max ? l.endDate : max), rentLines[0].endDate);
    }
  }

  // ── المدة: سنة واحدة كحد أقصى ──
  if (startDate && endDate) {
    if (endDate <= startDate) errors.endDate = errors.endDate ?? 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية.';
    else if (endDate > addMonths(startDate, 12)) errors.endDate = 'أقصى مدة للعقد سنة واحدة.';
  }
  if (rentOnly && endDate && startDate && endDate > addMonths(startDate, 12)) {
    errors.rentLines = 'سطور الإيجار تتجاوز سنة واحدة من بداية العقد.';
  }
  if (endDate && startDate) {
    rentLines.forEach((l, i) => {
      if (l.endDate > endDate!) errors[`rentLines.${i}.endDate`] = 'ينتهي السطر بعد نهاية العقد.';
    });
  }

  // ── التوريد ──
  let paymentDays: number | null = null;
  let settlementDiscountPct: number | null = null;
  let fixedDiscountPct: number | null = null;
  const goalLines: CleanGoalLine[] = [];
  if (types.includes(ContractType.SUPPLY)) {
    const s: Raw = body.supply ?? {};
    paymentDays = int(s.paymentDays);
    if (s.paymentDays === undefined || s.paymentDays === null || s.paymentDays === '') {
      if (strict) need('supply.paymentDays', 'فترة السداد مطلوبة (بالأيام).');
    } else if (paymentDays === null || paymentDays < 0) errors['supply.paymentDays'] = 'فترة السداد رقم صحيح بالأيام.';
    settlementDiscountPct = num(s.settlementDiscountPct);
    fixedDiscountPct = num(s.fixedDiscountPct);
    if (settlementDiscountPct !== null && (settlementDiscountPct < 0 || settlementDiscountPct > 100)) errors['supply.settlementDiscountPct'] = 'النسبة من 0 إلى 100.';
    if (fixedDiscountPct !== null && (fixedDiscountPct < 0 || fixedDiscountPct > 100)) errors['supply.fixedDiscountPct'] = 'النسبة من 0 إلى 100.';

    const rawGoals: Raw[] = Array.isArray(s.goalLines) ? s.goalLines : [];
    const seen = new Set<string>();
    rawGoals.forEach((g, i) => {
      const k = (f: string) => `goalLines.${i}.${f}`;
      const from = str(g.fromDate);
      const to = str(g.toDate);
      const target = num(g.target);
      const pct = num(g.discountPct);
      if (!isIsoDate(from)) need(k('fromDate'), 'تاريخ البداية مطلوب.');
      if (!isIsoDate(to)) need(k('toDate'), 'تاريخ النهاية مطلوب.');
      if (isIsoDate(from) && isIsoDate(to) && to < from) errors[k('toDate')] = 'تاريخ «إلى» قبل «من».';
      if (startDate && isIsoDate(from) && from < startDate) errors[k('fromDate')] = 'الفترة تبدأ قبل بداية العقد.';
      if (endDate && isIsoDate(to) && to > endDate) errors[k('toDate')] = 'الفترة تنتهي بعد نهاية العقد.';
      if (target === null || target <= 0) errors[k('target')] = 'الهدف رقم أكبر من 0.';
      if (pct === null || pct < 0 || pct > 100) errors[k('discountPct')] = 'النسبة من 0 إلى 100.';
      const key = `${from}|${to}|${target}`;
      if (target !== null && seen.has(key)) errors[k('target')] = 'نفس الهدف مكرر لنفس الفترة.';
      seen.add(key);
      if (!Object.keys(errors).some((x) => x.startsWith(`goalLines.${i}.`)) && target !== null && pct !== null) {
        goalLines.push({ fromDate: from, toDate: to, target: round2(target), discountPct: round2(pct) });
      }
    });
  }

  // ── التسويق ──
  const marketingLines: CleanMarketingLine[] = [];
  if (types.includes(ContractType.MARKETING)) {
    const rawLines: Raw[] = Array.isArray(body.marketingLines) ? body.marketingLines : [];
    if (strict && !rawLines.length) need('marketingLines', 'أضف فعالية واحدة على الأقل.');
    rawLines.forEach((l, i) => {
      const k = (f: string) => `marketingLines.${i}.${f}`;
      const eventId = int(l.eventId) ?? 0;
      const price = num(l.price);
      const note = str(l.note);
      if (!eventId) need(k('eventId'), 'اختر الفعالية.');
      else if (!ctx.eventIds.has(eventId)) errors[k('eventId')] = 'الفعالية غير موجودة أو موقوفة.';
      if (price === null || price < 0) errors[k('price')] = 'السعر رقم لا يقل عن 0.';
      else if (price === 0 && !note) errors[k('note')] = 'السعر 0 مقبول مع توضيح السبب في الملاحظة.';
      if (!Object.keys(errors).some((x) => x.startsWith(`marketingLines.${i}.`)) && price !== null) {
        marketingLines.push({ eventId, price: round2(price), note });
      }
    });
  }

  // ── الخدمات ──
  let newItemPrice: number | null = null;
  if (types.includes(ContractType.ADD_ITEM)) {
    newItemPrice = num(body.addItem?.price);
    if (body.addItem?.price === undefined || body.addItem?.price === null || body.addItem?.price === '') {
      if (strict) need('addItem.price', 'سعر الصنف الواحد مطلوب.');
    } else if (newItemPrice === null || newItemPrice < 0) errors['addItem.price'] = 'السعر رقم لا يقل عن 0.';
  }
  let creditLimit: number | null = null;
  if (types.includes(ContractType.OPEN_ACCOUNT)) {
    creditLimit = num(body.openAccount?.creditLimit);
    if (body.openAccount?.creditLimit === undefined || body.openAccount?.creditLimit === null || body.openAccount?.creditLimit === '') {
      if (strict) need('openAccount.creditLimit', 'الحد الائتماني مطلوب.');
    } else if (creditLimit === null || creditLimit < 0) errors['openAccount.creditLimit'] = 'الحد الائتماني رقم لا يقل عن 0.';
  }

  const clean: CleanContract = {
    vendorId,
    departmentId,
    entryPath,
    types,
    primaryType: primaryType(types),
    repName,
    repTitle,
    repMobile,
    startDate,
    endDate,
    specialTerms: typeof body.specialTerms === 'string' ? body.specialTerms.trim() : '',
    paymentDays,
    settlementDiscountPct,
    fixedDiscountPct,
    newItemPrice,
    creditLimit,
    goalLines,
    rentLines,
    marketingLines,
  };
  return { errors, clean };
}

export { addDays };
