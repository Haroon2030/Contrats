'use client';

import { useState, type FormEvent } from 'react';
import { useReference } from '@/controllers/useAdmin';
import { useToast } from '@/controllers/toast';
import { Button, Empty, ErrorBox, Field, PageHead, Panel } from '../ui';

type Tab = 'departments' | 'vendors' | 'shelf';

const TABS: { key: Tab; label: string }[] = [
  { key: 'departments', label: 'الأقسام' },
  { key: 'vendors', label: 'الموردون' },
  { key: 'shelf', label: 'أنواع الأرفف' },
];

/** القوائم المرجعية: إضافة وتعطيل (لا حذف) — تبقى الأسماء القديمة في العقود المرتبطة. */
export function ReferenceView() {
  const { departments, vendors, shelfRates, error, write, toggle } = useReference();
  const toast = useToast();
  const [tab, setTab] = useState<Tab>('departments');
  const [adding, setAdding] = useState(false);

  const addLabel = { departments: 'أضف قسماً', vendors: 'أضف مورداً', shelf: 'أضف نوع رف' }[tab];

  return (
    <>
      <PageHead title="القوائم المرجعية" subtitle="تُستخدم في نماذج العقود. التعطيل بدل الحذف للحفاظ على السجلات." actions={<Button variant="primary" onClick={() => setAdding(true)}>«{addLabel}»</Button>} />

      <div className="tabs" role="tablist">
        {TABS.map((t) => (
          <button key={t.key} role="tab" aria-selected={tab === t.key} className={tab === t.key ? 'active' : ''} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      {error && <ErrorBox>{error}</ErrorBox>}

      <Panel>
        {tab === 'departments' && (
          <Rows
            empty="لا توجد أقسام."
            head={['الاسم', 'الرمز', 'الحالة', '']}
            rows={departments.map((d) => ({
              key: d.id,
              cells: [d.name, <span key="c" className="ltr">{d.code}</span>, d.isActive ? 'نشط' : 'معطّل'],
              active: d.isActive,
              onToggle: () => toggle(`/reference/departments/${d.id}`, { name: d.name, code: d.code, isActive: !d.isActive }),
            }))}
          />
        )}
        {tab === 'vendors' && (
          <Rows
            empty="لا يوجد موردون."
            head={['رقم المورد', 'الاسم القانوني', 'الاسم التجاري', 'الجوال', 'الحالة', '']}
            rows={vendors.map((v) => ({
              key: v.id,
              cells: [
                <span key="n" className="ltr">{v.vendorNumber}</span>,
                v.legalName,
                v.tradeName || '—',
                <span key="p" className="ltr">{v.phone || '—'}</span>,
                v.isActive ? 'نشط' : 'معطّل',
              ],
              active: v.isActive,
              onToggle: () =>
                toggle(`/reference/vendors/${v.id}`, {
                  vendorNumber: v.vendorNumber,
                  legalName: v.legalName,
                  tradeName: v.tradeName,
                  crNumber: v.crNumber,
                  contactName: v.contactName,
                  phone: v.phone,
                  email: v.email,
                  isActive: !v.isActive,
                }),
            }))}
          />
        )}
        {tab === 'shelf' && (
          <Rows
            empty="لا توجد أنواع أرفف."
            head={['النوع', 'الرمز', 'السعر المعياري', 'الوحدة', 'الحالة', '']}
            rows={shelfRates.map((s) => ({
              key: s.id,
              cells: [s.name, <span key="c" className="ltr">{s.code}</span>, s.standardPrice, s.unit, s.isActive ? 'نشط' : 'معطّل'],
              active: s.isActive,
              onToggle: () =>
                toggle(`/reference/shelf-rates/${s.id}`, { code: s.code, name: s.name, unit: s.unit, standardPrice: Number(s.standardPrice), isActive: !s.isActive }),
            }))}
          />
        )}
      </Panel>

      {adding && <AddModal tab={tab} onClose={() => setAdding(false)} onSave={async (path, body) => {
        const res = await write(path, body);
        if (res.ok) {
          toast.success('أُضيف السجل.');
          setAdding(false);
        }
        return res;
      }} />}
    </>
  );
}

function Rows({
  head,
  rows,
  empty,
}: {
  head: string[];
  rows: { key: number; cells: React.ReactNode[]; active: boolean; onToggle: () => void }[];
  empty: string;
}) {
  if (!rows.length) return <Empty>{empty}</Empty>;
  return (
    <div className="table-wrap">
      <table className="table">
        <thead>
          <tr>
            {head.map((h, i) => (
              <th key={i}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.key}>
              {r.cells.map((c, i) => (
                <td key={i}>{c}</td>
              ))}
              <td>
                <Button small onClick={r.onToggle}>{r.active ? '«عطّل»' : '«فعّل»'}</Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

type Result = { ok: true } | { ok: false; message: string; fields?: Record<string, string> };

function AddModal({ tab, onClose, onSave }: { tab: Tab; onClose: () => void; onSave: (path: string, body: unknown) => Promise<Result> }) {
  const [f, setF] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const set = (k: string, v: string) => setF((s) => ({ ...s, [k]: v }));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setFields({});
    const res =
      tab === 'departments'
        ? await onSave('/reference/departments', { name: f.name, code: f.code })
        : tab === 'vendors'
          ? await onSave('/reference/vendors', { vendorNumber: f.vendorNumber, legalName: f.legalName, tradeName: f.tradeName, phone: f.phone, email: f.email, crNumber: f.crNumber })
          : await onSave('/reference/shelf-rates', { code: f.code, name: f.name, unit: f.unit || 'شهرياً', standardPrice: Number(f.standardPrice) });
    setBusy(false);
    if (!res.ok) {
      setError(res.message);
      setFields(res.fields ?? {});
    }
  };

  const title = { departments: 'قسم جديد', vendors: 'مورد جديد', shelf: 'نوع رف جديد' }[tab];
  return (
    <div className="modal-backdrop" onClick={onClose} role="dialog" aria-modal="true" aria-label={title}>
      <form className="modal" onClick={(e) => e.stopPropagation()} onSubmit={submit} noValidate>
        <h2>{title}</h2>
        {error && <ErrorBox>{error}</ErrorBox>}
        {tab === 'departments' && (
          <>
            <Field label="الاسم" error={fields.name}><input className="input" value={f.name ?? ''} onChange={(e) => set('name', e.target.value)} /></Field>
            <Field label="الرمز" error={fields.code}><input className="input ltr" value={f.code ?? ''} onChange={(e) => set('code', e.target.value)} /></Field>
          </>
        )}
        {tab === 'vendors' && (
          <>
            <Field label="رقم المورد" error={fields.vendorNumber} hint="مثال: V-2201"><input className="input ltr" value={f.vendorNumber ?? ''} onChange={(e) => set('vendorNumber', e.target.value)} /></Field>
            <Field label="الاسم القانوني" error={fields.legalName}><input className="input" value={f.legalName ?? ''} onChange={(e) => set('legalName', e.target.value)} /></Field>
            <Field label="الاسم التجاري"><input className="input" value={f.tradeName ?? ''} onChange={(e) => set('tradeName', e.target.value)} /></Field>
            <Field label="السجل التجاري"><input className="input ltr" value={f.crNumber ?? ''} onChange={(e) => set('crNumber', e.target.value)} /></Field>
            <Field label="الهاتف"><input className="input ltr" value={f.phone ?? ''} onChange={(e) => set('phone', e.target.value)} /></Field>
            <Field label="البريد"><input className="input ltr" value={f.email ?? ''} onChange={(e) => set('email', e.target.value)} /></Field>
          </>
        )}
        {tab === 'shelf' && (
          <>
            <Field label="الرمز" error={fields.code}><input className="input ltr" value={f.code ?? ''} onChange={(e) => set('code', e.target.value)} /></Field>
            <Field label="نوع الرف" error={fields.name}><input className="input" value={f.name ?? ''} onChange={(e) => set('name', e.target.value)} /></Field>
            <Field label="السعر المعياري (ر.س)" error={fields.standardPrice}><input className="input" type="number" min="0" step="0.01" value={f.standardPrice ?? ''} onChange={(e) => set('standardPrice', e.target.value)} /></Field>
            <Field label="الوحدة"><input className="input" value={f.unit ?? 'شهرياً'} onChange={(e) => set('unit', e.target.value)} /></Field>
          </>
        )}
        <div className="row" style={{ justifyContent: 'flex-end' }}>
          <Button onClick={onClose}>«تراجع»</Button>
          <Button variant="primary" type="submit" disabled={busy}>{busy ? '…' : '«احفظ»'}</Button>
        </div>
      </form>
    </div>
  );
}
