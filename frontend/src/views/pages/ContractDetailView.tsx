'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useToast } from '@/controllers/toast';
import { errorText, useApi } from '@/controllers/useApi';
import { api, ApiError } from '@/models/api';
import { CHANGE_LABELS, STAGES } from '@/models/labels';
import type { ContractAction, Json } from '@/models/types';
import { Button, Empty, ErrorBox, Field, Loading, PageHead, Panel, ReasonModal, StatusBadge, fmtDate, fmtDateTime, fmtMoney, useModalKeys, DateInput } from '../ui';

type Tab = 'summary' | 'terms' | 'files' | 'history' | 'audit';

const TABS: [Tab, string][] = [
  ['summary', 'الملخص'],
  ['terms', 'الشروط'],
  ['files', 'المرفقات'],
  ['history', 'الملاحق والمراحل'],
  ['audit', 'التدقيق'],
];

const pretty = (v: unknown) => (typeof v === 'object' && v !== null ? JSON.stringify(v) : String(v ?? '—'));

/** صفحة العقد: الملخص والإجراءات المتاحة للدور والحالة، وبقية التبويبات للقراءة. */
export function ContractDetailView({ id }: { id: number }) {
  const router = useRouter();
  const toast = useToast();
  const { data: c, error, loading, reload, setData } = useApi<Json>(`/contracts/${id}`);
  const [tab, setTab] = useState<Tab>('summary');
  const [modal, setModal] = useState<ContractAction | 'addendum' | 'signed' | null>(null);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  if (loading && !c) return <Loading />;
  if (error) return <ErrorBox>{error}</ErrorBox>;
  if (!c) return null;

  const run = async (fn: () => Promise<Json>, done: string) => {
    setBusy(true);
    setProblem(null);
    try {
      const r = await fn();
      if (r && r.id === id) setData(r);
      else reload();
      toast.success(done);
      setModal(null);
    } catch (e) {
      const msg = errorText(e);
      setProblem(e instanceof ApiError && Object.keys(e.fields).length ? `${msg} ${Object.values(e.fields).join(' ')}` : msg);
    } finally {
      setBusy(false);
    }
  };

  const slug = (k: string) => k.replace(/_/g, '-');
  const act = (a: ContractAction) => {
    if (a.key === 'edit') return router.push(`/contracts/${id}/edit`);
    if (a.key === 'edit_pending') return router.push(`/contracts/${id}/edit?pending=1`);
    if (a.key === 'print') return void window.open(`/api/contracts/${id}/print`, '_blank');
    if (a.key === 'add_addendum') return setModal('addendum');
    if (a.key === 'upload_signed') return setModal('signed');
    if (a.key === 'delete') {
      if (!confirm('حذف المسودة نهائياً؟')) return;
      return void run(async () => {
        await api.delete(`/contracts/${id}`);
        router.replace('/contracts');
        return {};
      }, 'حُذفت المسودة.');
    }
    if (a.reason) return setModal(a);
    return void run(() => api.post(`/contracts/${id}/actions/${slug(a.key)}`), `تم: ${a.label}`);
  };

  const stageIndex = STAGES.findIndex((s) => s.key === c.status);
  const sup = c.supply;

  return (
    <>
      <PageHead
        title={`عقد ${c.number ?? '(مسودة بلا رقم)'}`}
        subtitle={`${c.vendor.name} — ${c.department.name} — ${c.typeLabels.join(' + ')}`}
        actions={
          <>
            {c.actions.map((a: ContractAction) => (
              <Button key={a.key} variant={a.danger ? 'danger' : a.primary ? 'primary' : undefined} disabled={busy} onClick={() => act(a)}>
                «{a.label}»
              </Button>
            ))}
          </>
        }
      />

      {problem && !modal && <ErrorBox>{problem}</ErrorBox>}
      {c.hasAlert && <div className="alert-banner">⚠ عقد بتنبيه ({c.alertKind === 'auto' ? 'آلي' : 'يدوي'}): {c.alertReason}</div>}
      {c.rejectReason && c.status === 'draft' && <div className="reason-box"><strong>سبب الرفض/الإرجاع:</strong> {c.rejectReason}</div>}
      {c.cancelReason && <div className="reason-box"><strong>سبب الإلغاء:</strong> {c.cancelReason}</div>}
      {c.closeReason && <div className="reason-box"><strong>سبب الإغلاق:</strong> {c.closeReason}</div>}

      <Panel>
        <div className="row" style={{ flexWrap: 'wrap' }}>
          <StatusBadge status={c.status} label={c.statusLabel} />
          {c.partyLabel && <span className="badge accent">بانتظار: {c.partyLabel} — {c.waitingDays} يوم</span>}
          <span className="spacer" />
          {c.status !== 'cancelled' && c.status !== 'ended' && (
            <ol className="stepper" aria-label="مراحل العقد">
              {STAGES.map((s, i) => (
                <li key={s.key} className={`step ${i < stageIndex ? 'done' : ''} ${i === stageIndex ? 'current' : ''}`}>{s.label}</li>
              ))}
            </ol>
          )}
        </div>
      </Panel>

      <div className="tabs" role="tablist">
        {TABS.map(([k, l]) => (
          <button key={k} role="tab" aria-selected={tab === k} className={tab === k ? 'active' : ''} onClick={() => setTab(k)}>{l}</button>
        ))}
      </div>

      {tab === 'summary' && (
        <div className="stack">
          <Panel title="البيانات الأساسية">
            <dl className="info">
              <div className="info-row"><dt>المورد</dt><dd>{c.vendor.vendorNumber} — {c.vendor.name}</dd></div>
              <div className="info-row"><dt>المنشئ</dt><dd>{c.createdBy}</dd></div>
              <div className="info-row"><dt>الممثل</dt><dd>{c.rep.name} — {c.rep.title} — <span className="ltr">{c.rep.mobile}</span></dd></div>
              <div className="info-row"><dt>المدة</dt><dd>{fmtDate(c.startDate)} → {c.endDate ? fmtDate(c.endDate) : 'مفتوحة (خدمات)'}{c.daysRemaining != null && c.status === 'active' ? ` — متبقٍ ${c.daysRemaining} يوماً` : ''}</dd></div>
              <div className="info-row"><dt>المسار</dt><dd>{c.entryPath === 'ready' ? 'عقد جاهز' : 'عقد جديد'}</dd></div>
              {c.specialTerms && <div className="info-row"><dt>الشروط الخاصة</dt><dd>{c.specialTerms}</dd></div>}
            </dl>
          </Panel>

          {sup && (
            <Panel title="التوريد">
              <dl className="info">
                <div className="info-row"><dt>أيام السداد</dt><dd>{sup.paymentDays}</dd></div>
                <div className="info-row"><dt>خصم التسوية</dt><dd>{sup.settlementDiscountPct ?? 0}%</dd></div>
                <div className="info-row"><dt>الخصم الثابت</dt><dd>{sup.fixedDiscountPct ?? 0}%</dd></div>
              </dl>
              {c.goals && c.goals.lines.length > 0 && (
                <>
                  <h3 className="section-title">الأهداف — الخصم التقديري {fmtMoney(c.goals.estimatedDiscount)}</h3>
                  <div className="table-wrap">
                    <table className="table">
                      <thead><tr><th>الفترة</th><th className="num">الهدف</th><th className="num">الصافي</th><th>التحقق</th><th className="num">الخصم</th><th>الحالة</th></tr></thead>
                      <tbody>
                        {c.goals.lines.map((g: Json) => (
                          <tr key={g.id}>
                            <td>{fmtDate(g.fromDate)} — {fmtDate(g.toDate)}</td>
                            <td className="num">{fmtMoney(g.target)}</td>
                            <td className="num">{fmtMoney(g.net)}</td>
                            <td>{Number(g.achievedPct).toFixed(0)}%</td>
                            <td className="num">{g.discountPct}%</td>
                            <td><span className={`badge ${g.applied ? 'ok' : 'neutral'}`}>{g.applied ? `مطبّق: ${fmtMoney(g.estimatedDiscount)}` : g.statusLabel}</span></td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  {c.goals.lastUpdate && <div className="muted" style={{ fontSize: 12 }}>آخر تحديث للمشتريات: {fmtDateTime(c.goals.lastUpdate)}</div>}
                </>
              )}
            </Panel>
          )}

          {c.rentLines.length > 0 && (
            <Panel title={`إيجار الأرفف — الإجمالي ${fmtMoney(c.rentTotal)}`}>
              <div className="table-wrap">
                <table className="table">
                  <thead><tr><th>الفرع</th><th>الرف</th><th>العدد</th><th className="num">السعر</th><th>الفترة</th><th>عدد الفترات</th><th className="num">الإجمالي</th></tr></thead>
                  <tbody>
                    {c.rentLines.map((l: Json) => (
                      <tr key={l.id}>
                        <td>{l.branch}</td><td>{l.shelf}</td><td>{l.qty}</td>
                        <td className="num">{fmtMoney(l.price)} / {l.unit === 'week' ? 'أسبوع' : 'شهر'}</td>
                        <td>{fmtDate(l.startDate)} — {fmtDate(l.endDate)}</td>
                        <td>{l.periods}</td>
                        <td className="num">{fmtMoney(l.total)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          )}

          {c.marketingLines.length > 0 && (
            <Panel title={`التسويق — الإجمالي ${fmtMoney(c.marketingTotal)}`}>
              <div className="table-wrap">
                <table className="table">
                  <thead><tr><th>الفعالية</th><th className="num">السعر</th><th>ملاحظة</th></tr></thead>
                  <tbody>
                    {c.marketingLines.map((l: Json) => (
                      <tr key={l.id}><td>{l.event}</td><td className="num">{fmtMoney(l.price)}</td><td>{l.note || '—'}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          )}

          {c.addItem && <Panel title="إضافة صنف جديد"><dl className="info"><div className="info-row"><dt>سعر الصنف الواحد</dt><dd>{fmtMoney(c.addItem.price)}</dd></div></dl></Panel>}

          {c.openAccount && (
            <Panel title="فتح حساب (سري)">
              {c.openAccount.confidential ? (
                <Empty>بيانات فتح الحساب سرية ولا تظهر إلا لقسم العقد والمعتمدين.</Empty>
              ) : (
                <dl className="info">
                  <div className="info-row"><dt>الحد الائتماني</dt><dd>{fmtMoney(c.openAccount.creditLimit)}</dd></div>
                  {c.openAccount.documents.map((d: Json) => (
                    <div className="info-row" key={d.kind}><dt>{d.label}</dt><dd>{d.uploaded ? 'مرفوع' : 'ناقص'}</dd></div>
                  ))}
                </dl>
              )}
            </Panel>
          )}
        </div>
      )}

      {tab === 'terms' && (
        <Panel title={c.terms.snapshot ? 'البنود المعتمدة (نسخة وقت الإرسال)' : 'البنود الحالية (ستُثبَّت عند الإرسال)'}>
          {c.terms.clauses.length === 0 ? <Empty>لا بنود منطبقة.</Empty> : c.terms.clauses.map((t: Json) => (
            <div key={t.id} style={{ marginBottom: 14 }}>
              <strong>{t.title}</strong> <span className="muted">(الإصدار {t.version})</span>
              <p style={{ whiteSpace: 'pre-wrap' }}>{t.body}</p>
            </div>
          ))}
        </Panel>
      )}

      {tab === 'files' && (
        <Panel title="المرفقات">
          {c.attachments.length === 0 ? <Empty>لا مرفقات.</Empty> : (
            <div className="table-wrap">
              <table className="table">
                <thead><tr><th>النوع</th><th>الملف</th><th>رفعه</th><th>التاريخ</th><th /></tr></thead>
                <tbody>
                  {c.attachments.map((a: Json) => (
                    <tr key={a.id}>
                      <td>{a.kindLabel}</td><td className="ltr">{a.fileName}</td><td>{a.uploadedBy}</td><td>{fmtDateTime(a.uploadedAt)}</td>
                      <td><a className="btn small" href={`/api/contracts/${id}/attachments/${a.id}`}>«نزّل»</a></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      )}

      {tab === 'history' && (
        <div className="stack">
          <Panel title={`الملاحق (${c.addenda.length})`}>
            {c.addenda.length === 0 ? <Empty>لا ملاحق.</Empty> : c.addenda.map((a: Json) => (
              <div key={a.id} className="line-card" style={{ display: 'block' }}>
                <div className="row">
                  <strong>الملحق {a.no ?? '—'}</strong>
                  <span className={`badge ${a.status === 'applied' ? 'ok' : a.status === 'returned' ? 'danger' : 'accent'}`}>{a.status === 'applied' ? 'مطبَّق' : a.status === 'returned' ? 'معاد' : 'بانتظار المطابقة'}</span>
                  <span className="muted">سريان {fmtDate(a.effectiveDate)} — {a.createdBy}</span>
                </div>
                <p>{a.description}</p>
                {a.changes && Object.keys(a.changes.after ?? {}).map((k) => (
                  <div key={k} style={{ fontSize: 13 }}>{CHANGE_LABELS[k] ?? k}: <span className="muted ltr">{pretty(a.changes.before?.[k])}</span> ← <strong className="ltr">{pretty(a.changes.after[k])}</strong></div>
                ))}
                {a.returnReason && <div className="reason-box">سبب الإعادة: {a.returnReason}</div>}
                {a.status === 'pending' && c.actions.some((x: ContractAction) => x.key === 'addendum_review') && (
                  <div className="row" style={{ marginTop: 8 }}>
                    <Button small variant="primary" disabled={busy} onClick={() => run(() => api.post(`/contracts/${id}/addenda/${a.id}/review`, { approve: true }), 'طُبّق الملحق.')}>«طابق وطبّق»</Button>
                    <ReturnAddendum onReturn={(reason) => run(() => api.post(`/contracts/${id}/addenda/${a.id}/review`, { approve: false, reason }), 'أُعيد الملحق.')} />
                  </div>
                )}
              </div>
            ))}
          </Panel>
          <Panel title="المراحل ومدة البقاء">
            <div className="table-wrap">
              <table className="table">
                <thead><tr><th>الحالة</th><th>الجهة</th><th>دخل</th><th>خرج</th></tr></thead>
                <tbody>
                  {c.stays.map((s: Json, i: number) => (
                    <tr key={i}><td>{s.stateLabel}</td><td>{s.partyLabel ?? '—'}</td><td>{fmtDateTime(s.enteredAt)}</td><td>{s.leftAt ? fmtDateTime(s.leftAt) : 'حالياً'}</td></tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
        </div>
      )}

      {tab === 'audit' && (
        <Panel title="سجل التدقيق">
          {c.audit.length === 0 ? <Empty>لا سجلات.</Empty> : (
            <div className="table-wrap">
              <table className="table">
                <thead><tr><th>الوقت</th><th>المستخدم</th><th>الإجراء</th><th>قبل</th><th>بعد</th><th>ملاحظة</th></tr></thead>
                <tbody>
                  {c.audit.map((a: Json) => (
                    <tr key={a.id}>
                      <td>{fmtDateTime(a.createdAt)}</td><td>{a.userName}</td><td>{a.action}</td>
                      <td className="ltr" style={{ fontSize: 12, overflowWrap: 'anywhere' }}>{a.before ? pretty(a.before) : ''}</td>
                      <td className="ltr" style={{ fontSize: 12, overflowWrap: 'anywhere' }}>{a.after ? pretty(a.after) : ''}</td>
                      <td>{a.note}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      )}

      <div style={{ marginTop: 20 }}><Link href="/contracts">← العودة إلى السجل</Link></div>

      {modal && typeof modal === 'object' && (
        <ReasonModal
          title={modal.label}
          label={modal.reason === 'required' ? 'السبب (إلزامي)' : 'السبب'}
          required={modal.reason === 'required'}
          confirmLabel={modal.label}
          danger={modal.danger}
          busy={busy}
          error={problem}
          onCancel={() => { setModal(null); setProblem(null); }}
          onConfirm={(reason) => run(() => api.post(`/contracts/${id}/actions/${slug(modal.key)}`, { reason }), `تم: ${modal.label}`)}
        />
      )}
      {modal === 'signed' && (
        <SignedModal busy={busy} error={problem} onCancel={() => { setModal(null); setProblem(null); }} onSubmit={(file) => {
          const form = new FormData();
          form.append('file', file);
          return run(() => api.upload(`/contracts/${id}/actions/upload-signed`, form), 'رُفعت النسخة الموقعة وأصبح العقد نشطاً.');
        }} />
      )}
      {modal === 'addendum' && (
        <AddendumModal contract={c} busy={busy} error={problem} onCancel={() => { setModal(null); setProblem(null); }} onSubmit={(form) => run(() => api.upload(`/contracts/${id}/addenda`, form), 'سُجّل الملحق بانتظار المطابقة.')} />
      )}
    </>
  );
}

function ReturnAddendum({ onReturn }: { onReturn: (reason: string) => void }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button small variant="danger" onClick={() => setOpen(true)}>«أعد الملحق»</Button>
      {open && <ReasonModal title="إعادة الملحق" label="سبب الإعادة" required confirmLabel="أعد" danger onCancel={() => setOpen(false)} onConfirm={(r) => { setOpen(false); onReturn(r); }} />}
    </>
  );
}

function SignedModal({ busy, error, onCancel, onSubmit }: { busy: boolean; error: string | null; onCancel: () => void; onSubmit: (f: File) => void }) {
  const [file, setFile] = useState<File | null>(null);
  useModalKeys(onCancel);
  return (
    <div className="modal-backdrop" onClick={onCancel} role="dialog" aria-modal="true">
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>رفع النسخة الموقعة</h2>
        <Field label="الملف الموقّع (PDF أو صورة، حتى 10MB)">
          <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>
        {error && <ErrorBox>{error}</ErrorBox>}
        <div className="row" style={{ justifyContent: 'flex-end' }}>
          <Button onClick={onCancel}>«تراجع»</Button>
          <Button variant="primary" disabled={!file || busy} onClick={() => file && onSubmit(file)}>«ارفع وفعّل»</Button>
        </div>
      </div>
    </div>
  );
}

const ADDENDUM_FIELDS: { key: string; label: string; type: 'number' | 'text' }[] = [
  { key: 'paymentDays', label: 'أيام السداد', type: 'number' },
  { key: 'settlementDiscountPct', label: 'خصم التسوية %', type: 'number' },
  { key: 'fixedDiscountPct', label: 'الخصم الثابت %', type: 'number' },
  { key: 'newItemPrice', label: 'سعر الصنف', type: 'number' },
  { key: 'specialTerms', label: 'الشروط الخاصة', type: 'text' },
  { key: 'repName', label: 'اسم الممثل', type: 'text' },
  { key: 'repTitle', label: 'صفة الممثل', type: 'text' },
  { key: 'repMobile', label: 'جوال الممثل', type: 'text' },
];

/** ملحق: يسجّله مسؤول القسم بتاريخ سريان وتغييرات محدودة وملف موقّع، ثم يطابقه مدير القسم. */
function AddendumModal({ contract, busy, error, onCancel, onSubmit }: { contract: Json; busy: boolean; error: string | null; onCancel: () => void; onSubmit: (f: FormData) => void }) {
  const [date, setDate] = useState('');
  const [desc, setDesc] = useState('');
  const [vals, setVals] = useState<Record<string, string>>({});
  const [goals, setGoals] = useState<Json[] | null>(null);
  const [file, setFile] = useState<File | null>(null);
  useModalKeys(onCancel);

  const available = ADDENDUM_FIELDS.filter((f) => {
    if (['paymentDays', 'settlementDiscountPct', 'fixedDiscountPct'].includes(f.key)) return !!contract.supply;
    if (f.key === 'newItemPrice') return !!contract.addItem;
    if (['specialTerms'].includes(f.key)) return !contract.openAccount;
    return true;
  });

  const submit = () => {
    const changes: Json = {};
    for (const [k, v] of Object.entries(vals)) if (v !== '') changes[k] = ADDENDUM_FIELDS.find((f) => f.key === k)?.type === 'number' ? Number(v) : v;
    if (goals) changes.goalLines = goals.map((g) => ({ fromDate: g.fromDate, toDate: g.toDate, target: Number(g.target), discountPct: Number(g.discountPct) }));
    const form = new FormData();
    form.append('effectiveDate', date);
    form.append('description', desc);
    form.append('changes', JSON.stringify(changes));
    if (file) form.append('file', file);
    onSubmit(form);
  };

  return (
    <div className="modal-backdrop" onClick={onCancel} role="dialog" aria-modal="true">
      <div className="modal" style={{ maxWidth: 640, maxHeight: '90vh', overflow: 'auto' }} onClick={(e) => e.stopPropagation()}>
        <h2>إضافة ملحق</h2>
        <div className="form-grid">
          <Field label="تاريخ السريان *" hint={`ضمن مدة العقد ${fmtDate(contract.startDate)} — ${contract.endDate ? fmtDate(contract.endDate) : 'مفتوحة'}`}>
            <DateInput value={date} onChange={(v) => setDate(v)} />
          </Field>
          <Field label="وصف الملحق *"><input className="input" value={desc} onChange={(e) => setDesc(e.target.value)} /></Field>
          {available.map((f) => (
            <Field key={f.key} label={f.label} hint="اتركه فارغاً إن لم يتغيّر">
              <input className={`input ${f.type === 'number' ? 'ltr' : ''}`} type={f.type} value={vals[f.key] ?? ''} onChange={(e) => setVals((s) => ({ ...s, [f.key]: e.target.value }))} />
            </Field>
          ))}
        </div>
        {contract.supply && (
          <>
            <div className="row" style={{ margin: '10px 0' }}>
              <strong>أهداف المشتريات</strong>
              {goals === null ? (
                <Button small onClick={() => setGoals(contract.supply.goalLines.map((g: Json) => ({ ...g })))}>«عدّل الأهداف»</Button>
              ) : (
                <Button small onClick={() => setGoals(null)}>«بلا تغيير»</Button>
              )}
            </div>
            {goals?.map((g, i) => (
              <div className="line-card" key={i}>
                <DateInput value={g.fromDate} onChange={(v) => setGoals(goals.map((x, j) => (j === i ? { ...x, fromDate: v } : x)))} />
                <DateInput value={g.toDate} onChange={(v) => setGoals(goals.map((x, j) => (j === i ? { ...x, toDate: v } : x)))} />
                <input className="input ltr" type="number" value={g.target} onChange={(e) => setGoals(goals.map((x, j) => (j === i ? { ...x, target: e.target.value } : x)))} />
                <input className="input ltr" type="number" value={g.discountPct} onChange={(e) => setGoals(goals.map((x, j) => (j === i ? { ...x, discountPct: e.target.value } : x)))} />
                <Button small variant="danger" onClick={() => setGoals(goals.filter((_, j) => j !== i))}>«احذف»</Button>
              </div>
            ))}
            {goals && <Button small onClick={() => setGoals([...goals, { fromDate: date, toDate: contract.endDate, target: '', discountPct: '' }])}>«أضف هدفاً»</Button>}
          </>
        )}
        <Field label="الملحق الموقّع *">
          <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>
        {error && <ErrorBox>{error}</ErrorBox>}
        <div className="row" style={{ justifyContent: 'flex-end' }}>
          <Button onClick={onCancel}>«تراجع»</Button>
          <Button variant="primary" disabled={busy || !date || !desc || !file} onClick={submit}>«سجّل الملحق»</Button>
        </div>
      </div>
    </div>
  );
}
