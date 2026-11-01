import { Injectable } from '@nestjs/common';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository } from 'typeorm';
import { TYPE_LABELS, TYPE_ORDER, sortTypes } from '../common/domain';
import { DocTemplateEntity, TermClauseEntity, TermClauseVersionEntity } from '../database/entities';

export interface PrintData {
  number: string | null;
  draft: boolean;
  types: string[];
  vendorName: string;
  vendorNumber: string;
  departmentName: string;
  repName: string;
  repTitle: string;
  repMobile: string;
  startDate: string | null;
  endDate: string | null;
  paymentDays: number | null;
  settlementDiscountPct: number | null;
  fixedDiscountPct: number | null;
  newItemPrice: number | null;
  creditLimit: number | null;
  specialTerms: string;
  goalLines: { fromDate: string; toDate: string; target: number; discountPct: number }[];
  rentLines: { branch: string; shelf: string; qty: number; price: number; unit: string; startDate: string; endDate: string; periods: number; total: number }[];
  marketingLines: { event: string; price: number; note: string }[];
  clauses: { title: string; body: string }[];
  template: { headerText: string; footerText: string; signatureLayout: Record<string, any>; hasLogo: boolean; hasBackground: boolean };
}

const esc = (s: unknown) =>
  String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
const nl = (s: string) => esc(s).replace(/\n/g, '<br>');
const money = (n: number | null | undefined) => (n == null ? '—' : `${new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(n)} ريال`);
const date = (iso: string | null) => (iso ? iso.split('-').reverse().join('/') : '—');
const UNIT: Record<string, string> = { week: 'أسبوع', month: 'شهر' };

/** يولّد صفحة طباعة العقد (HTML) بترتيب الوثيقة: الترويسة، الملخص، قسم لكل نوع، الشروط، الشروط الخاصة، التوقيع. */
@Injectable()
export class PrintService {
  constructor(
    @InjectRepository(DocTemplateEntity) private readonly docs: Repository<DocTemplateEntity>,
    @InjectRepository(TermClauseEntity) private readonly clauses: Repository<TermClauseEntity>,
    @InjectRepository(TermClauseVersionEntity) private readonly versions: Repository<TermClauseVersionEntity>,
  ) {}

  render(d: PrintData): string {
    const types = sortTypes(d.types);
    const sections: string[] = [];

    sections.push(`
      <section>
        <h2>ملخص العقد</h2>
        <table class="kv">
          <tr><th>الطرف الأول</th><td>شركة محمد رشيد الرشيد للتجارة</td></tr>
          <tr><th>الطرف الثاني (المورد)</th><td>${esc(d.vendorName)} <span class="ltr">(${esc(d.vendorNumber)})</span></td></tr>
          <tr><th>ممثل المورد</th><td>${esc(d.repName)} — ${esc(d.repTitle)} — <span class="ltr">${esc(d.repMobile)}</span></td></tr>
          <tr><th>القسم</th><td>${esc(d.departmentName)}</td></tr>
          <tr><th>أنواع العقد</th><td>${types.map((t) => esc(TYPE_LABELS[t])).join('، ')}</td></tr>
          <tr><th>تاريخ البداية</th><td>${date(d.startDate)}</td></tr>
          ${d.endDate ? `<tr><th>تاريخ النهاية</th><td>${date(d.endDate)}</td></tr>` : ''}
        </table>
      </section>`);

    for (const t of types) {
      if (t === 'supply') {
        const goals = d.goalLines.length
          ? `<table class="grid"><thead><tr><th>من تاريخ</th><th>إلى تاريخ</th><th>الهدف (صافي المشتريات)</th><th>نسبة الخصم</th></tr></thead><tbody>${d.goalLines
              .map((g) => `<tr><td>${date(g.fromDate)}</td><td>${date(g.toDate)}</td><td>${money(g.target)}</td><td>${g.discountPct}%</td></tr>`)
              .join('')}</tbody></table>`
          : '<p class="muted">لا توجد أهداف.</p>';
        sections.push(`
          <section><h2>بنود التوريد</h2>
            <table class="kv">
              <tr><th>فترة السداد</th><td>${d.paymentDays ?? '—'} يوم</td></tr>
              <tr><th>خصم السداد</th><td>${d.settlementDiscountPct ?? 0}%</td></tr>
              <tr><th>الخصم الثابت</th><td>${d.fixedDiscountPct ?? 0}%</td></tr>
            </table>
            <h3>أهداف المشتريات والخصم</h3>${goals}
          </section>`);
      } else if (t === 'rent') {
        const total = d.rentLines.reduce((s, l) => s + l.total, 0);
        sections.push(`
          <section><h2>بنود إيجار الأرفف</h2>
            <table class="grid"><thead><tr><th>الفرع</th><th>نوع الرف</th><th>العدد</th><th>السعر</th><th>الوحدة</th><th>من</th><th>إلى</th><th>الفترات</th><th>الإجمالي</th></tr></thead><tbody>
            ${d.rentLines.map((l) => `<tr><td>${esc(l.branch)}</td><td>${esc(l.shelf)}</td><td>${l.qty}</td><td>${money(l.price)}</td><td>${UNIT[l.unit] ?? l.unit}</td><td>${date(l.startDate)}</td><td>${date(l.endDate)}</td><td>${l.periods}</td><td>${money(l.total)}</td></tr>`).join('')}
            </tbody><tfoot><tr><th colspan="8">إجمالي الإيجارات</th><th>${money(total)}</th></tr></tfoot></table>
          </section>`);
      } else if (t === 'marketing') {
        const total = d.marketingLines.reduce((s, l) => s + l.price, 0);
        sections.push(`
          <section><h2>بنود التسويق</h2>
            <table class="grid"><thead><tr><th>الفعالية</th><th>السعر</th><th>ملاحظة</th></tr></thead><tbody>
            ${d.marketingLines.map((l) => `<tr><td>${esc(l.event)}</td><td>${money(l.price)}</td><td>${esc(l.note) || '—'}</td></tr>`).join('')}
            </tbody><tfoot><tr><th>إجمالي التسويق</th><th colspan="2">${money(total)}</th></tr></tfoot></table>
          </section>`);
      } else if (t === 'add_item') {
        sections.push(`<section><h2>خدمة إضافة صنف جديد</h2><table class="kv"><tr><th>سعر الصنف الواحد</th><td>${money(d.newItemPrice)}</td></tr></table></section>`);
      } else if (t === 'open_account') {
        sections.push(
          `<section><h2>خدمة فتح حساب</h2><table class="kv"><tr><th>الحد الائتماني</th><td>${d.creditLimit == null ? 'سري' : money(d.creditLimit)}</td></tr></table></section>`,
        );
      }
    }

    if (d.clauses.length) {
      sections.push(`<section><h2>الشروط والأحكام</h2><ol class="terms">${d.clauses.map((c) => `<li><strong>${esc(c.title)}</strong><div>${nl(c.body)}</div></li>`).join('')}</ol></section>`);
    }
    if (d.specialTerms) {
      sections.push(`<section><h2>الشروط الخاصة</h2><p>${nl(d.specialTerms)}</p></section>`);
    }

    const layout = d.template.signatureLayout || {};
    sections.push(`
      <section class="signatures">
        <h2>بيان التوقيع</h2>
        <div class="sig">
          <div><div class="who">${esc(layout.right ?? 'مدير المشتريات')}</div><div class="line"></div></div>
          <div class="stamp">${esc(layout.stamp ?? 'ختم الشركة')}</div>
          <div><div class="who">${esc(layout.left ?? 'ممثل المورد')}<br><small>${esc(d.repName)} — ${esc(d.repTitle)}</small></div><div class="line"></div></div>
        </div>
      </section>`);

    return `<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>${esc(d.number ?? 'معاينة العقد')}</title>
<style>
  @page { size: A4; margin: 18mm 14mm; }
  * { box-sizing: border-box; }
  body { font-family: 'Noto Sans Arabic', 'Segoe UI', Tahoma, sans-serif; color: #222; font-size: 13px; line-height: 1.7; margin: 0; ${d.template.hasBackground ? 'background: url(/api/templates/assets/background) center/cover no-repeat;' : ''} }
  .page { max-width: 190mm; margin: 0 auto; padding: 8mm 0; position: relative; }
  header { display: flex; align-items: center; gap: 16px; border-bottom: 3px solid #c2570c; padding-bottom: 10px; margin-bottom: 14px; }
  header img { max-height: 64px; max-width: 160px; object-fit: contain; }
  header .title { flex: 1; font-weight: 700; font-size: 15px; }
  .no { font-weight: 700; direction: ltr; unicode-bidi: isolate; }
  h2 { font-size: 16px; margin: 18px 0 8px; padding: 4px 10px; background: #f1eee9; border-inline-start: 4px solid #c2570c; }
  h3 { font-size: 14px; margin: 12px 0 6px; }
  table { width: 100%; border-collapse: collapse; }
  table.kv th { width: 32%; text-align: start; background: #faf8f5; }
  th, td { border: 1px solid #cfc9bf; padding: 6px 10px; text-align: start; }
  table.grid thead th, table.grid tfoot th { background: #efebe4; }
  .ltr { direction: ltr; unicode-bidi: isolate; display: inline-block; }
  .muted { color: #777; }
  ol.terms { padding-inline-start: 22px; } ol.terms li { margin-bottom: 8px; }
  .signatures .sig { display: flex; justify-content: space-between; gap: 24px; margin-top: 18px; align-items: flex-end; }
  .sig > div { flex: 1; text-align: center; } .sig .line { border-bottom: 1px solid #333; height: 60px; margin-top: 6px; }
  .sig .stamp { border: 1.5px dashed #999; border-radius: 50%; width: 110px; height: 110px; flex: none; display: grid; place-items: center; color: #999; }
  footer { margin-top: 22px; border-top: 1px solid #cfc9bf; padding-top: 8px; font-size: 11px; color: #666; text-align: center; }
  ${d.draft ? `.watermark { position: fixed; inset: 0; display: grid; place-items: center; font-size: 150px; font-weight: 800; color: rgba(194,87,12,.12); transform: rotate(-24deg); pointer-events: none; z-index: 0; }` : ''}
  @media print { .noprint { display: none; } }
  .noprint { position: fixed; top: 10px; inset-inline-start: 10px; }
  .noprint button { font: inherit; padding: 8px 18px; border: 0; border-radius: 999px; background: #c2570c; color: #fff; cursor: pointer; }
</style></head>
<body>
  ${d.draft ? '<div class="watermark">مسودة</div>' : ''}
  <div class="noprint"><button onclick="window.print()">اطبع العقد</button></div>
  <div class="page">
    <header>
      ${d.template.hasLogo ? '<img src="/api/templates/assets/logo" alt="شعار الشركة">' : ''}
      <div class="title">${nl(d.template.headerText)}</div>
      <div>رقم العقد: <span class="no">${esc(d.number ?? 'بدون رقم (مسودة)')}</span></div>
    </header>
    ${sections.join('\n')}
    <footer>${nl(d.template.footerText)}</footer>
  </div>
</body></html>`;
  }

  /** عقد نموذجي لمعاينة القالب والبنود. */
  async renderSample(types: string[]): Promise<string> {
    const doc = (await this.docs.find({ order: { version: 'DESC' }, take: 1 }))[0];
    const clauseVersions: TermClauseVersionEntity[] = [];
    for (const c of await this.clauses.find({ where: { status: 'active' } })) {
      const v = await this.versions.findOne({ where: { clauseId: c.id, version: c.currentVersion } });
      if (v && v.appliesTo.some((t) => types.includes(t))) clauseVersions.push(v);
    }
    clauseVersions.sort((a, b) => a.sort - b.sort || a.id - b.id);
    return this.render({
      number: '10234-01',
      draft: true,
      types: sortTypes(types.filter((t) => TYPE_ORDER.includes(t))),
      vendorName: 'اسم المورد (نموذج)',
      vendorNumber: '10234',
      departmentName: 'فريش',
      repName: 'محمد أحمد',
      repTitle: 'المدير التجاري',
      repMobile: '0500000000',
      startDate: '2026-01-01',
      endDate: '2026-12-31',
      paymentDays: 60,
      settlementDiscountPct: 2,
      fixedDiscountPct: 1,
      newItemPrice: 500,
      creditLimit: 100000,
      specialTerms: '',
      goalLines: [
        { fromDate: '2026-01-01', toDate: '2026-12-31', target: 1_000_000, discountPct: 3 },
        { fromDate: '2026-01-01', toDate: '2026-12-31', target: 2_000_000, discountPct: 5 },
      ],
      rentLines: [{ branch: 'فرع نموذجي', shelf: 'جندولة', qty: 2, price: 1000, unit: 'month', startDate: '2026-01-01', endDate: '2026-03-31', periods: 3, total: 6000 }],
      marketingLines: [{ event: 'رمضان', price: 5000, note: 'صفحة كاملة' }],
      clauses: clauseVersions.map((v) => ({ title: v.title, body: v.body })),
      template: {
        headerText: doc?.headerText ?? 'شركة محمد رشيد الرشيد للتجارة — قسم العقود',
        footerText: doc?.footerText ?? '',
        signatureLayout: doc?.signatureLayout ?? {},
        hasLogo: !!doc?.logoPath,
        hasBackground: !!doc?.backgroundPath,
      },
    });
  }
}
