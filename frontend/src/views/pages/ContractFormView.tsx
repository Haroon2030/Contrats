'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { useSession } from '@/controllers/session';
import { useToast } from '@/controllers/toast';
import { errorText, useApi } from '@/controllers/useApi';
import { api, ApiError } from '@/models/api';
import { ACCOUNT_DOCS, TYPE_OPTIONS } from '@/models/labels';
import type { ContractType, Json, Options } from '@/models/types';
import { Button, ErrorBox, Field, Loading, PageHead, Panel, DateInput } from '../ui';

const blankForm = (): Json => ({
  vendorId: '',
  departmentId: '',
  entryPath: 'new',
  types: [] as ContractType[],
  repName: '',
  repTitle: '',
  repMobile: '',
  startDate: '',
  endDate: '',
  specialTerms: '',
  supply: { paymentDays: '', settlementDiscountPct: '', fixedDiscountPct: '', goalLines: [] },
  rentLines: [],
  marketingLines: [],
  addItem: { price: '' },
  openAccount: { creditLimit: '' },
});

/** يحوّل null إلى '' كي تبقى حقول الإدخال متحكَّماً بها. */
const normalize = (f: Json): Json => {
  const base = blankForm();
  return {
    ...base,
    ...f,
    vendorId: f.vendorId ?? '',
    departmentId: f.departmentId ?? '',
    endDate: f.endDate ?? '',
    startDate: f.startDate ?? '',
    supply: { ...base.supply, ...(f.supply ?? {}) },
    addItem: { ...base.addItem, ...(f.addItem ?? {}) },
    openAccount: { ...base.openAccount, ...(f.openAccount ?? {}) },
  };
};

/** نموذج العقد: أنواع متعددة بحقول مشتركة وأقسام لكل نوع. يحفظ كمسودة أو يرسل مباشرة. */
export function ContractFormView({ contractId, pending }: { contractId?: number; pending?: boolean }) {
  const router = useRouter();
  const toast = useToast();
  const { user } = useSession();
  const opts = useApi<Options>('/reference/options').data;
  const existing = useApi<Json>(contractId ? `/contracts/${contractId}/form` : null);
  const detail = useApi<Json>(contractId ? `/contracts/${contractId}` : null);

  const [f, setF] = useState<Json>(blankForm);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [files, setFiles] = useState<Record<string, File | null>>({});
  const [loaded, setLoaded] = useState(!contractId);

  useEffect(() => {
    if (existing.data && !loaded) {
      setF(normalize(existing.data));
      setLoaded(true);
    }
  }, [existing.data, loaded]);

  useEffect(() => {
    if (!contractId && user && !user.caps.pickDepartment && user.departmentId) {
      setF((s: Json) => ({ ...s, departmentId: user.departmentId }));
    }
  }, [contractId, user]);

  const set = (patch: Json) => setF((s: Json) => ({ ...s, ...patch }));
  const setIn = (key: string, patch: Json) => setF((s: Json) => ({ ...s, [key]: { ...s[key], ...patch } }));
  const err = (k: string) => errors[k];
  const has = (t: ContractType) => f.types.includes(t);
  const openAccount = has('open_account');
  const rentOnly = f.types.length > 0 && f.types.every((t: string) => t === 'rent');
  const servicesOnly = f.types.length > 0 && f.types.every((t: string) => t === 'add_item' || t === 'open_account');

  const toggleType = (t: ContractType) => {
    setF((s: Json) => {
      let types: ContractType[] = s.types.includes(t) ? s.types.filter((x: string) => x !== t) : [...s.types, t];
      if (t === 'open_account' && !s.types.includes(t)) types = ['open_account'];
      else if (t !== 'open_account') types = types.filter((x) => x !== 'open_account');
      return { ...s, types };
    });
  };

  const onVendor = async (id: string) => {
    set({ vendorId: id });
    if (!id || f.repName) return;
    try {
      const rep = await api.get<{ repName?: string; repTitle?: string; repMobile?: string } | null>(`/contracts/vendor/${id}/last-rep`);
      if (rep?.repName) set({ repName: rep.repName, repTitle: rep.repTitle ?? '', repMobile: rep.repMobile ?? '' });
    } catch {
      /* لا ممثل سابق */
    }
  };

  const payload = () => ({
    ...f,
    vendorId: Number(f.vendorId) || null,
    departmentId: Number(f.departmentId) || null,
    endDate: f.endDate || null,
    startDate: f.startDate || null,
  });

  const save = async (andSend: boolean) => {
    setBusy(true);
    setErrors({});
    setMessage(null);
    let id = contractId;
    try {
      const saved = contractId ? await api.put<Json>(pending ? `/contracts/${contractId}/actions/edit-pending` : `/contracts/${contractId}`, payload()) : await api.post<Json>('/contracts', payload());
      id = saved.id;
      for (const [kind, file] of Object.entries(files)) {
        if (!file) continue;
        const form = new FormData();
        form.append('kind', kind);
        form.append('file', file);
        await api.upload(`/contracts/${id}/attachments`, form);
      }
      if (andSend && !pending) {
        await api.post(`/contracts/${id}/actions/send`);
        toast.success('أُرسل العقد للمراجعة وخُصص رقمه.');
      } else {
        toast.success(pending ? 'حُفظ التعديل.' : 'حُفظت المسودة.');
      }
      router.push(`/contracts/${id}`);
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.fields);
        setMessage(e.message);
      } else setMessage(errorText(e));
      // المسودة حُفظت قبل فشل الإرسال: ننتقل إلى وضع التعديل كي لا تتكرر.
      if (!contractId && id) router.replace(`/contracts/${id}/edit`);
    } finally {
      setBusy(false);
    }
  };

  if (!opts || !loaded) return <Loading />;
  if (existing.error) return <ErrorBox>{existing.error}</ErrorBox>;

  const uploaded = (kind: string) => (detail.data?.attachments ?? []).some((a: Json) => a.kind === kind);
  const fileField = (kind: string, label: string, required = false) => (
    <Field label={`${label}${required ? ' *' : ''}`} error={err('files') && !uploaded(kind) && !files[kind] ? 'مطلوب' : undefined}>
      <div className="file-row">
        <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setFiles((s) => ({ ...s, [kind]: e.target.files?.[0] ?? null }))} />
        {uploaded(kind) && !files[kind] && <span className="badge ok">مرفوع</span>}
      </div>
    </Field>
  );

  const goalLines: Json[] = f.supply.goalLines;
  const setGoal = (i: number, patch: Json) => setIn('supply', { goalLines: goalLines.map((g, j) => (j === i ? { ...g, ...patch } : g)) });
  const setList = (key: 'rentLines' | 'marketingLines', i: number, patch: Json) => set({ [key]: f[key].map((l: Json, j: number) => (j === i ? { ...l, ...patch } : l)) });
  const drop = (key: string, i: number) => set({ [key]: f[key].filter((_: Json, j: number) => j !== i) });

  return (
    <>
      <PageHead title={pending ? 'تعديل عقد بانتظار التوقيع' : contractId ? 'تعديل المسودة' : 'إنشاء عقد'} subtitle="الأقسام تظهر حسب أنواع العقد المختارة" />
      {message && <ErrorBox>{message}</ErrorBox>}

      <Panel title="مسار الإدخال">
        <div className="chips">
          {[['new', 'عقد جديد (يُولَّد من النظام)'], ['ready', 'عقد جاهز (ملف موقّع)']].map(([v, l]) => (
            <label key={v} className={`chip ${f.entryPath === v ? 'on' : ''}`}>
              <input type="radio" name="path" checked={f.entryPath === v} onChange={() => set({ entryPath: v })} />
              {l}
            </label>
          ))}
        </div>
      </Panel>

      <Panel title="البيانات الأساسية">
        <div className="form-grid">
          <Field label="المورد *" error={err('vendorId')}>
            <select className="select" value={f.vendorId} onChange={(e) => onVendor(e.target.value)}>
              <option value="">اختر المورد</option>
              {opts.vendors.map((v) => (
                <option key={v.id} value={v.id}>{v.vendorNumber} — {v.name}</option>
              ))}
            </select>
          </Field>
          <Field label="القسم *" error={err('departmentId')}>
            <select className="select" value={f.departmentId} disabled={!user?.caps.pickDepartment} onChange={(e) => set({ departmentId: e.target.value })}>
              <option value="">اختر القسم</option>
              {opts.departments.map((d) => (
                <option key={d.id} value={d.id}>{d.name}</option>
              ))}
            </select>
          </Field>
          <Field label="اسم الممثل *" error={err('repName')}>
            <input className="input" value={f.repName} onChange={(e) => set({ repName: e.target.value })} />
          </Field>
          <Field label="صفة الممثل *" error={err('repTitle')}>
            <input className="input" value={f.repTitle} onChange={(e) => set({ repTitle: e.target.value })} />
          </Field>
          <Field label="جوال الممثل *" error={err('repMobile')} hint="10 أرقام تبدأ بـ 05">
            <input className="input ltr" inputMode="numeric" value={f.repMobile} onChange={(e) => set({ repMobile: e.target.value })} />
          </Field>
          <Field label="تاريخ البداية *" error={err('startDate')}>
            <DateInput value={f.startDate} onChange={(v) => set({ startDate: v })} />
          </Field>
          {!servicesOnly && !rentOnly && (
            <Field label="تاريخ النهاية *" error={err('endDate')} hint="أقصى مدة للعقد سنة واحدة">
              <DateInput value={f.endDate} onChange={(v) => set({ endDate: v })} />
            </Field>
          )}
          {rentOnly && <Field label="تاريخ النهاية" hint="يُحسب من آخر فترة إيجار"><input className="input ltr" disabled value="تلقائي" readOnly /></Field>}
        </div>
        {err('types') && <div className="field-error">{err('types')}</div>}
        <h3 className="section-title" style={{ marginTop: 16 }}>أنواع العقد *</h3>
        <div className="chips">
          {TYPE_OPTIONS.map((t) => {
            const blocked = (t.value === 'open_account' && f.types.length > 0 && !openAccount) || (openAccount && t.value !== 'open_account');
            return (
              <label key={t.value} className={`chip ${has(t.value) ? 'on' : ''} ${blocked ? 'off' : ''}`} title={t.hint}>
                <input type="checkbox" checked={has(t.value)} disabled={blocked} onChange={() => toggleType(t.value)} />
                {t.label}
              </label>
            );
          })}
        </div>
      </Panel>

      {has('supply') && (
        <Panel title="التوريد">
          <div className="form-grid">
            <Field label="أيام السداد *" error={err('supply.paymentDays')}>
              <input className="input ltr" type="number" min={0} value={f.supply.paymentDays} onChange={(e) => setIn('supply', { paymentDays: e.target.value })} />
            </Field>
            <Field label="خصم التسوية %" error={err('supply.settlementDiscountPct')}>
              <input className="input ltr" type="number" min={0} max={100} step="0.01" value={f.supply.settlementDiscountPct ?? ''} onChange={(e) => setIn('supply', { settlementDiscountPct: e.target.value })} />
            </Field>
            <Field label="الخصم الثابت %" error={err('supply.fixedDiscountPct')}>
              <input className="input ltr" type="number" min={0} max={100} step="0.01" value={f.supply.fixedDiscountPct ?? ''} onChange={(e) => setIn('supply', { fixedDiscountPct: e.target.value })} />
            </Field>
          </div>
          <h3 className="section-title" style={{ marginTop: 16 }}>أهداف المشتريات (شرائح الخصم)</h3>
          {goalLines.map((g, i) => (
            <div className="line-card" key={i}>
              <Field label="من" error={err(`supply.goalLines.${i}.fromDate`)}><DateInput value={g.fromDate ?? ''} onChange={(v) => setGoal(i, { fromDate: v })} /></Field>
              <Field label="إلى" error={err(`supply.goalLines.${i}.toDate`)}><DateInput value={g.toDate ?? ''} onChange={(v) => setGoal(i, { toDate: v })} /></Field>
              <Field label="الهدف (ر.س)" error={err(`supply.goalLines.${i}.target`)}><input className="input ltr" type="number" min={0} value={g.target ?? ''} onChange={(e) => setGoal(i, { target: e.target.value })} /></Field>
              <Field label="الخصم %" error={err(`supply.goalLines.${i}.discountPct`)}><input className="input ltr" type="number" min={0} max={100} step="0.01" value={g.discountPct ?? ''} onChange={(e) => setGoal(i, { discountPct: e.target.value })} /></Field>
              <Button small variant="danger" onClick={() => setIn('supply', { goalLines: goalLines.filter((_, j) => j !== i) })}>«احذف»</Button>
            </div>
          ))}
          <Button small onClick={() => setIn('supply', { goalLines: [...goalLines, { fromDate: f.startDate, toDate: f.endDate, target: '', discountPct: '' }] })}>«أضف هدفاً»</Button>
        </Panel>
      )}

      {has('rent') && (
        <Panel title="إيجار الأرفف">
          <p className="muted">الفترة شهرية أو أسبوعية كاملة فقط؛ من 15/01 تنتهي 14/02 لشهر واحد.</p>
          {f.rentLines.map((l: Json, i: number) => (
            <div className="line-card" key={i}>
              <Field label="الفرع" error={err(`rentLines.${i}.branchId`)}>
                <select className="select" value={l.branchId ?? ''} onChange={(e) => setList('rentLines', i, { branchId: Number(e.target.value) || '' })}>
                  <option value="">—</option>
                  {opts.branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </select>
              </Field>
              <Field label="نوع الرف" error={err(`rentLines.${i}.shelfTypeId`)}>
                <select className="select" value={l.shelfTypeId ?? ''} onChange={(e) => setList('rentLines', i, { shelfTypeId: Number(e.target.value) || '' })}>
                  <option value="">—</option>
                  {opts.shelfTypes.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </select>
              </Field>
              <Field label="العدد" error={err(`rentLines.${i}.qty`)}><input className="input ltr" type="number" min={1} value={l.qty ?? ''} onChange={(e) => setList('rentLines', i, { qty: e.target.value })} /></Field>
              <Field label="السعر للفترة" error={err(`rentLines.${i}.price`)}><input className="input ltr" type="number" min={0} value={l.price ?? ''} onChange={(e) => setList('rentLines', i, { price: e.target.value })} /></Field>
              <Field label="الوحدة">
                <select className="select" value={l.unit ?? 'month'} onChange={(e) => setList('rentLines', i, { unit: e.target.value })}>
                  <option value="month">شهر</option>
                  <option value="week">أسبوع</option>
                </select>
              </Field>
              <Field label="من" error={err(`rentLines.${i}.startDate`)}><DateInput value={l.startDate ?? ''} onChange={(v) => setList('rentLines', i, { startDate: v })} /></Field>
              <Field label="إلى" error={err(`rentLines.${i}.endDate`)}><DateInput value={l.endDate ?? ''} onChange={(v) => setList('rentLines', i, { endDate: v })} /></Field>
              <Button small variant="danger" onClick={() => drop('rentLines', i)}>«احذف»</Button>
            </div>
          ))}
          <Button small onClick={() => set({ rentLines: [...f.rentLines, { unit: 'month', qty: 1, startDate: f.startDate }] })}>«أضف سطر إيجار»</Button>
        </Panel>
      )}

      {has('marketing') && (
        <Panel title="التسويق">
          {f.marketingLines.map((l: Json, i: number) => (
            <div className="line-card" key={i}>
              <Field label="الفعالية" error={err(`marketingLines.${i}.eventId`)}>
                <select className="select" value={l.eventId ?? ''} onChange={(e) => setList('marketingLines', i, { eventId: Number(e.target.value) || '' })}>
                  <option value="">—</option>
                  {opts.events.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </select>
              </Field>
              <Field label="السعر" error={err(`marketingLines.${i}.price`)}><input className="input ltr" type="number" min={0} value={l.price ?? ''} onChange={(e) => setList('marketingLines', i, { price: e.target.value })} /></Field>
              <Field label={Number(l.price) === 0 && l.price !== '' ? 'ملاحظة (إلزامية مع السعر 0)' : 'ملاحظة'} error={err(`marketingLines.${i}.note`)}>
                <input className="input" value={l.note ?? ''} onChange={(e) => setList('marketingLines', i, { note: e.target.value })} />
              </Field>
              <Button small variant="danger" onClick={() => drop('marketingLines', i)}>«احذف»</Button>
            </div>
          ))}
          <Button small onClick={() => set({ marketingLines: [...f.marketingLines, { price: '', note: '' }] })}>«أضف فعالية»</Button>
        </Panel>
      )}

      {has('add_item') && (
        <Panel title="خدمات — إضافة صنف جديد">
          <div className="form-grid">
            <Field label="سعر الصنف الواحد *" error={err('addItem.price')}>
              <input className="input ltr" type="number" min={0} value={f.addItem.price ?? ''} onChange={(e) => setIn('addItem', { price: e.target.value })} />
            </Field>
          </div>
        </Panel>
      )}

      {openAccount && (
        <Panel title="خدمات — فتح حساب (سري)">
          <div className="form-grid">
            <Field label="الحد الائتماني *" error={err('openAccount.creditLimit')}>
              <input className="input ltr" type="number" min={0} value={f.openAccount.creditLimit ?? ''} onChange={(e) => setIn('openAccount', { creditLimit: e.target.value })} />
            </Field>
          </div>
          <h3 className="section-title" style={{ marginTop: 12 }}>المستندات الخمسة (PDF أو صورة، حتى 10MB)</h3>
          <div className="form-grid">{ACCOUNT_DOCS.map((d) => <div key={d.kind}>{fileField(d.kind, d.label, true)}</div>)}</div>
        </Panel>
      )}

      {f.entryPath === 'ready' && (
        <Panel title="العقد الجاهز">{fileField('ready_signed', 'ملف العقد الموقّع', true)}</Panel>
      )}

      {!openAccount && (
        <Panel title="الشروط الخاصة">
          <p className="muted">أي نص هنا يرفع تنبيهاً آلياً لمدير قسم العقود عند الإرسال في مسار العقد الجديد.</p>
          <textarea className="textarea" rows={4} value={f.specialTerms} onChange={(e) => set({ specialTerms: e.target.value })} />
        </Panel>
      )}

      <div className="row" style={{ marginBottom: 40 }}>
        {pending ? (
          <Button variant="primary" onClick={() => save(false)} disabled={busy}>«احفظ التعديل»</Button>
        ) : (
          <>
            <Button variant="primary" onClick={() => save(true)} disabled={busy}>«أرسل للمراجعة»</Button>
            <Button onClick={() => save(false)} disabled={busy}>«احفظ كمسودة»</Button>
          </>
        )}
        <Button variant="ghost" onClick={() => router.back()}>«تراجع»</Button>
      </div>
    </>
  );
}
