'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useContractDetail } from '@/controllers/useContractDetail';
import { useContractForm } from '@/controllers/useContractForm';
import { useToast } from '@/controllers/toast';
import { CONTRACT_TYPES, SUPPLY_SUBTYPES } from '@/models/labels';
import type { ContractDetail, ContractFormValues } from '@/models/types';
import { Button, ErrorBox, Field, Loading, PageHead, Panel } from '../ui';

/**
 * إنشاء عقد جديد، أو تعديل مسودة موجودة (contractId).
 * الحفظ كمسودة أو إرسال مباشر للمسار. الأخطاء تظهر بجانب الحقول.
 */
export function ContractFormView({ contractId }: { contractId?: number }) {
  const router = useRouter();
  if (!contractId) return <Form onDone={(id) => router.push(`/contracts/${id}`)} />;
  return <EditFormView contractId={contractId} />;
}

function EditFormView({ contractId }: { contractId: number }) {
  const router = useRouter();
  const existing = useContractDetail(contractId);
  if (existing.loading) return <Loading />;
  if (existing.error || !existing.contract) return <ErrorBox>{existing.error ?? 'تعذّر تحميل المسودة.'}</ErrorBox>;
  if (existing.contract.status !== 'draft') return <ErrorBox>لا يمكن تعديل عقد خرج من حالة المسودة.</ErrorBox>;
  return <Form contractId={contractId} initial={fromDetail(existing.contract)} onDone={(id) => router.push(`/contracts/${id}`)} />;
}

function Form({
  contractId,
  initial,
  onDone,
}: {
  contractId?: number;
  initial?: Partial<ContractFormValues>;
  onDone: (id: number) => void;
}) {
  const toast = useToast();
  const { form, options, preview, fieldErrors, formError, saving, save } = useContractForm(initial);
  const v = form.values;
  const [confirmSubmit, setConfirmSubmit] = useState(false);

  const submit = async (mode: 'draft' | 'submit') => {
    const res = await save(mode, contractId);
    if (res) {
      toast.success(mode === 'submit' ? 'أُرسل العقد إلى مسار الاعتماد.' : 'حُفظت المسودة.');
      onDone(res.id);
    } else {
      toast.error('راجع الحقول المعلّمة قبل الحفظ.');
    }
  };

  const isSupply = v.contractType === 'supply';
  const isShelf = v.contractType === 'shelf_rent';
  const isServices = v.contractType === 'services';

  return (
    <>
      <nav className="muted" style={{ fontSize: 13 }}>
        <Link href="/contracts">سجل العقود</Link> / {contractId ? 'تعديل المسودة' : 'عقد جديد'}
      </nav>
      <PageHead
        title={contractId ? 'تعديل المسودة' : 'إنشاء عقد'}
        subtitle="يُخصَّص رقم العقد عند الحفظ — بصيغة رقم المورد-تسلسل."
      />

      {formError && <ErrorBox>{formError}</ErrorBox>}

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 300px', gap: 20, alignItems: 'start' }} className="form-layout">
        <div className="stack">
          <Panel title="بيانات العقد">
            <div className="form-grid">
              <Field label="نوع العقد" error={fieldErrors.contractType}>
                <select className="select" value={v.contractType} onChange={(e) => form.set('contractType', e.target.value as ContractFormValues['contractType'])}>
                  {CONTRACT_TYPES.map((t) => (
                    <option key={t.value} value={t.value}>
                      {t.label}
                    </option>
                  ))}
                </select>
              </Field>

              {isSupply && (
                <Field label="النوع الفرعي" error={fieldErrors.subtype}>
                  <select className="select" value={v.subtype} onChange={(e) => form.set('subtype', e.target.value)}>
                    {SUPPLY_SUBTYPES.map((s) => (
                      <option key={s.value} value={s.value}>
                        {s.label}
                      </option>
                    ))}
                  </select>
                </Field>
              )}

              {isServices && (
                <Field label="النوع الفرعي" hint="«فتح حساب» عقد مستقل ويتطلب 5 مستندات.">
                  <select className="select" value={v.subtype} onChange={(e) => form.set('subtype', e.target.value)}>
                    <option value="">خدمة عامة</option>
                    <option value="account_opening">فتح حساب</option>
                  </select>
                </Field>
              )}

              <Field label="المورد" error={fieldErrors.vendorId}>
                <select className="select" value={v.vendorId} onChange={(e) => form.set('vendorId', e.target.value ? Number(e.target.value) : '')}>
                  <option value="">اختر المورد</option>
                  {options?.vendors.map((x) => (
                    <option key={x.id} value={x.id}>
                      {x.vendorNumber} — {x.name}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="القسم المسؤول" error={fieldErrors.departmentId}>
                <select className="select" value={v.departmentId} onChange={(e) => form.set('departmentId', e.target.value ? Number(e.target.value) : '')}>
                  <option value="">اختر القسم</option>
                  {options?.departments.map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.name}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="تاريخ البدء" error={fieldErrors.startDate}>
                <input className="input" type="date" value={v.startDate} onChange={(e) => form.set('startDate', e.target.value)} />
              </Field>

              <Field label="تاريخ الانتهاء" error={fieldErrors.endDate} hint="أقصى مدة سنة واحدة، ولا تجديد تلقائي.">
                <input className="input" type="date" value={v.endDate} onChange={(e) => form.set('endDate', e.target.value)} />
              </Field>

              <Field label="القيمة الفعلية (ر.س)" error={fieldErrors.actualValue} hint="أكثر من 15,000 ر.س يُحال لإدارة العقود.">
                <input className="input" type="number" min="0" step="0.01" value={v.actualValue} onChange={(e) => form.set('actualValue', e.target.value)} />
              </Field>

              <Field label="التصنيف الداخلي">
                <input className="input" value={v.internalClassification} onChange={(e) => form.set('internalClassification', e.target.value)} />
              </Field>

              <Field label="مدير المشتريات المسؤول">
                <select className="select" value={v.purchasingManagerId} onChange={(e) => form.set('purchasingManagerId', e.target.value ? Number(e.target.value) : '')}>
                  <option value="">—</option>
                  {options?.users.map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.name} — {u.roleLabel}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="شروط غير معيارية" hint="تُرفع تنبيهاً وتمر على مدير إدارة العقود بعد مدير القسم.">
                <label className="row" style={{ minHeight: 44 }}>
                  <input type="checkbox" checked={v.hasNonStandardTerms} onChange={(e) => form.set('hasNonStandardTerms', e.target.checked)} />
                  <span>يوجد شروط خارج النموذج المعياري</span>
                </label>
              </Field>

              <Field label="ملاحظات" span2>
                <textarea className="textarea" value={v.notes} onChange={(e) => form.set('notes', e.target.value)} />
              </Field>
            </div>
          </Panel>

          {isShelf && (
            <Panel title="إيجار الأرفف">
              <div className="form-grid">
                <Field label="نوع الرف المعياري" error={fieldErrors.shelfRateId}>
                  <select className="select" value={v.shelfRateId} onChange={(e) => form.set('shelfRateId', e.target.value ? Number(e.target.value) : '')}>
                    <option value="">اختر النوع</option>
                    {options?.shelfRates.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.name} — {s.standardPrice} / {s.unit}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="السعر الفعلي المتفق عليه" hint="إن اختلف عن المعياري يُرفع تنبيه انحراف السعر.">
                  <input className="input" type="number" min="0" step="0.01" value={v.shelfActualPrice} onChange={(e) => form.set('shelfActualPrice', e.target.value)} />
                </Field>
              </div>
            </Panel>
          )}

          <Panel
            title="شرائح الأهداف والخصم"
            actions={<Button small onClick={form.addTarget}>«أضف شريحة»</Button>}
          >
            {v.targets.length === 0 && <div className="muted">لا توجد شرائح. أضف شريحة إن كان للعقد خصم مرتبط بالمشتريات.</div>}
            <div className="stack" style={{ gap: 12 }}>
              {v.targets.map((t, i) => (
                <div key={i} className="form-grid" style={{ gridTemplateColumns: 'minmax(0,2fr) 1fr 1fr auto', alignItems: 'end' }}>
                  <Field label={`وصف الشريحة ${i + 1}`}>
                    <input className="input" value={t.title} onChange={(e) => form.setTarget(i, 'title', e.target.value)} />
                  </Field>
                  <Field label="قيمة الهدف (ر.س)">
                    <input className="input" type="number" min="0" value={t.targetValue} onChange={(e) => form.setTarget(i, 'targetValue', e.target.value)} />
                  </Field>
                  <Field label="نسبة الخصم %">
                    <input className="input" type="number" min="0" max="100" step="0.01" value={t.discountPercent} onChange={(e) => form.setTarget(i, 'discountPercent', e.target.value)} />
                  </Field>
                  <Button small onClick={() => form.removeTarget(i)} aria-label="احذف الشريحة">
                    «احذف»
                  </Button>
                </div>
              ))}
            </div>
          </Panel>

          <div className="notice">
            أسطر الإيجار الشهرية والفعاليات التسويقية وخطوط الفروع تحتاج جداول إضافية في قاعدة البيانات، وستُضاف مع مرحلة التشغيل.
          </div>
        </div>

        <aside className="stack">
          <Panel title="ملخص">
            <dl className="info">
              <div className="info-row">
                <dt className="muted">المعتمد المتوقع</dt>
                <dd>{preview.approver}</dd>
              </div>
              <div className="info-row">
                <dt className="muted">السبب</dt>
                <dd>{preview.reason}</dd>
              </div>
            </dl>
            <p className="muted" style={{ fontSize: 12, marginBottom: 0 }}>
              المعاينة تقريبية. المعتمد الرسمي يُحسب في الخادم عند الإرسال.
            </p>
          </Panel>

          <Panel title="الإجراء">
            <div className="stack">
              <Button disabled={saving} onClick={() => submit('draft')}>
                «احفظ مسودة»
              </Button>
              <Button variant="primary" disabled={saving} onClick={() => setConfirmSubmit(true)}>
                «أرسل للاعتماد»
              </Button>
            </div>
          </Panel>
        </aside>
      </div>

      {confirmSubmit && (
        <div className="modal-backdrop" onClick={() => setConfirmSubmit(false)} role="dialog" aria-modal="true" aria-label="تأكيد الإرسال">
          <div className="modal" onClick={(e) => e.stopPropagation()}>
            <h2>إرسال العقد للاعتماد؟</h2>
            <p className="muted" style={{ margin: 0 }}>
              بعد الإرسال تُقفل المسودة، ولا يُرسل العقد دون النسخة الأصلية المرفوعة.
            </p>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <Button onClick={() => setConfirmSubmit(false)}>«تراجع»</Button>
              <Button
                variant="primary"
                disabled={saving}
                onClick={async () => {
                  setConfirmSubmit(false);
                  await submit('submit');
                }}
              >
                «أرسل للاعتماد»
              </Button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function fromDetail(c: ContractDetail): Partial<ContractFormValues> {
  return {
    contractType: c.contractType,
    subtype: c.subtype,
    internalClassification: c.internalClassification,
    vendorId: c.vendorId,
    departmentId: c.departmentId,
    startDate: c.startDate,
    endDate: c.endDate,
    actualValue: String(c.actualValue),
    hasNonStandardTerms: c.hasNonStandardTerms,
    shelfRateId: c.shelfRate?.id ?? '',
    shelfActualPrice: c.shelfActualPrice == null ? '' : String(c.shelfActualPrice),
    purchasingManagerId: c.purchasingManagerId ?? '',
    notes: c.notes,
    targets: c.targets.map((t) => ({ title: t.title, targetValue: String(t.targetValue), discountPercent: String(t.discountPercent) })),
  };
}
