'use client';

import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useToast } from '@/controllers/toast';
import { errorText, useApi } from '@/controllers/useApi';
import { api, ApiError } from '@/models/api';
import type { Json } from '@/models/types';
import { Button, Empty, ErrorBox, Field, Loading, PageHead, Panel, useModalKeys } from '../ui';

interface Lists {
  labels: Record<string, string>;
  departments: { id: number; name: string; code: string; isActive: boolean }[];
  values: { id: number; list: string; name: string; isActive: boolean }[];
}

const PAGE_SIZE = 8;

/** القوائم المرجعية: إضافة/تعديل/إيقاف فقط (لا حذف)، والموردون، وThreshold التذكير والتصعيد. */
export function ReferenceView() {
  const toast = useToast();
  const lists = useApi<Lists>('/reference/lists');
  const vendors = useApi<Json[]>('/reference/vendors?includeInactive=1');
  const settings = useApi<{ reminderDays: number[]; escalationDays: number }>('/reference/settings');
  const [tab, setTab] = useState('settings');

  const attempt = async (fn: () => Promise<unknown>, done: string, after: () => void) => {
    try {
      await fn();
      toast.success(done);
      after();
    } catch (e) {
      toast.error(errorText(e));
    }
  };

  if (!lists.data) return lists.error ? <ErrorBox>{lists.error}</ErrorBox> : <Loading />;
  const L = lists.data;

  const tabs: { key: string; label: string; count?: number }[] = [
    { key: 'settings', label: 'مدد التذكير' },
    { key: 'departments', label: 'الأقسام', count: L.departments.length },
    ...Object.entries(L.labels).map(([key, label]) => ({ key, label, count: L.values.filter((v) => v.list === key).length })),
    { key: 'vendors', label: 'الموردون', count: vendors.data?.length },
  ];

  return (
    <>
      <PageHead title="القوائم والإعدادات" subtitle="الإيقاف بدل الحذف، والتغييرات في التدقيق" />

      <div className="tabs" role="tablist" aria-label="القوائم والإعدادات">
        {tabs.map((t) => (
          <button key={t.key} type="button" role="tab" aria-selected={tab === t.key} className={`tab${tab === t.key ? ' active' : ''}`} onClick={() => setTab(t.key)}>
            {t.label}
            {t.count !== undefined && <span className="tab-count">{t.count}</span>}
          </button>
        ))}
      </div>

      {tab === 'settings' && <ThresholdTab settings={settings} attempt={attempt} />}

      {tab === 'departments' && (
        <Panel title="الأقسام">
          <PagedTable
            rows={L.departments}
            head={['القسم', 'الرمز', 'الحالة', '']}
            render={(d) => (
              <tr key={d.id}>
                <td><strong>{d.name}</strong></td>
                <td className="ltr muted">{d.code}</td>
                <td><StateBadge on={d.isActive} /></td>
                <td className="cell-actions"><Button small onClick={() => attempt(() => api.patch(`/reference/departments/${d.id}`, { isActive: !d.isActive }), 'تم.', lists.reload)}>{d.isActive ? '«أوقف»' : '«فعّل»'}</Button></td>
              </tr>
            )}
          />
        </Panel>
      )}

      {Object.entries(L.labels).map(([key, title]) =>
        tab === key ? (
          <ListTab
            key={key}
            title={title}
            items={L.values.filter((v) => v.list === key)}
            onAdd={(name) => attempt(() => api.post(`/reference/lists/${key}`, { name }), 'أُضيفت.', lists.reload)}
            onPatch={(id, body) => attempt(() => api.patch(`/reference/lists/${key}/${id}`, body), 'تم.', lists.reload)}
          />
        ) : null,
      )}

      {tab === 'vendors' && <VendorsTab vendors={vendors.data ?? []} loading={!vendors.data} reload={vendors.reload} attempt={attempt} />}
    </>
  );
}

function StateBadge({ on }: { on: boolean }) {
  return <span className={`badge ${on ? 'ok' : 'muted'}`}>{on ? 'نشط' : 'موقوف'}</span>;
}

/** جدول بصفحات: 8 صفوف في الصفحة. */
function PagedTable<T>({ rows, head, render, empty = 'لا توجد بيانات.' }: { rows: T[]; head: string[]; render: (row: T) => ReactNode; empty?: string }) {
  const [page, setPage] = useState(0);
  const pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  const current = Math.min(page, pages - 1);
  const slice = rows.slice(current * PAGE_SIZE, current * PAGE_SIZE + PAGE_SIZE);

  if (!rows.length) return <Empty>{empty}</Empty>;
  return (
    <>
      <div className="table-wrap">
        <table className="table">
          <thead><tr>{head.map((h, i) => <th key={i}>{h}</th>)}</tr></thead>
          <tbody>{slice.map(render)}</tbody>
        </table>
      </div>
      {pages > 1 && (
        <div className="pager">
          <span className="muted">
            {current * PAGE_SIZE + 1}–{current * PAGE_SIZE + slice.length} من {rows.length}
          </span>
          <span className="spacer" />
          <Button small disabled={current === 0} onClick={() => setPage(current - 1)} aria-label="الصفحة السابقة">«السابق»</Button>
          <span className="pager-pos">{current + 1} / {pages}</span>
          <Button small disabled={current >= pages - 1} onClick={() => setPage(current + 1)} aria-label="الصفحة التالية">«التالي»</Button>
        </div>
      )}
    </>
  );
}

function ThresholdTab({ settings, attempt }: { settings: ReturnType<typeof useApi<{ reminderDays: number[]; escalationDays: number }>>; attempt: (fn: () => Promise<unknown>, done: string, after: () => void) => void }) {
  const [reminders, setReminders] = useState('');
  const [escalation, setEscalation] = useState('');

  useEffect(() => {
    if (settings.data) {
      setReminders(settings.data.reminderDays.join(','));
      setEscalation(String(settings.data.escalationDays));
    }
  }, [settings.data]);

  return (
    <Panel title="مدد التذكير والتصعيد (Threshold)">
      <p className="muted">تسري على العقود الجديدة فقط؛ كل عقد يحتفظ بقيمه وقت إنشائه.</p>
      <div className="form-grid">
        <Field label="أيام التذكير قبل الانتهاء (مفصولة بفواصل)"><input className="input ltr" value={reminders} onChange={(e) => setReminders(e.target.value)} /></Field>
        <Field label="أيام التصعيد (البقاء في مرحلة واحدة)"><input className="input ltr" type="number" min={1} value={escalation} onChange={(e) => setEscalation(e.target.value)} /></Field>
      </div>
      <Button variant="primary" onClick={() => attempt(() => api.put('/reference/settings', { reminderDays: reminders.split(',').map((s) => Number(s.trim())).filter((n) => n > 0), escalationDays: Number(escalation) }), 'حُفظت الإعدادات.', settings.reload)}>«احفظ»</Button>
    </Panel>
  );
}

function ListTab({ title, items, onAdd, onPatch }: { title: string; items: Lists['values']; onAdd: (n: string) => void; onPatch: (id: number, b: Json) => void }) {
  const [name, setName] = useState('');
  const add = () => {
    if (!name.trim()) return;
    onAdd(name.trim());
    setName('');
  };
  return (
    <Panel title={title}>
      <div className="row" style={{ marginBottom: 14 }}>
        <input className="input" style={{ maxWidth: 320 }} placeholder="اسم جديد" value={name} onChange={(e) => setName(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && add()} />
        <Button small variant="primary" disabled={!name.trim()} onClick={add}>«أضف»</Button>
      </div>
      <PagedTable
        rows={items}
        head={['الاسم', 'الحالة', '']}
        empty="لا توجد قيم بعد. أضف أول قيمة من الأعلى."
        render={(v) => (
          <tr key={v.id}>
            <td><input className="input" style={{ maxWidth: 320 }} defaultValue={v.name} onBlur={(e) => e.target.value.trim() && e.target.value.trim() !== v.name && onPatch(v.id, { name: e.target.value.trim() })} /></td>
            <td><StateBadge on={v.isActive} /></td>
            <td className="cell-actions"><Button small onClick={() => onPatch(v.id, { isActive: !v.isActive })}>{v.isActive ? '«أوقف»' : '«فعّل»'}</Button></td>
          </tr>
        )}
      />
    </Panel>
  );
}

function VendorsTab({ vendors, loading, reload, attempt }: { vendors: Json[]; loading: boolean; reload: () => void; attempt: (fn: () => Promise<unknown>, done: string, after: () => void) => void }) {
  const [q, setQ] = useState('');
  const [modal, setModal] = useState<Json | 'new' | null>(null);
  const [importing, setImporting] = useState(false);

  const rows = useMemo(() => {
    const t = q.trim().toLowerCase();
    if (!t) return vendors;
    return vendors.filter((v) => [v.vendorNumber, v.name, v.commercialRegister, v.taxNumber, v.mobile, v.email].some((x) => String(x ?? '').toLowerCase().includes(t)));
  }, [vendors, q]);

  return (
    <Panel
      title="الموردون"
      actions={
        <>
          <Button small onClick={() => setImporting(true)}>«استورد»</Button>
          <Button small variant="primary" onClick={() => setModal('new')}>«أضف مورداً»</Button>
        </>
      }
    >
      <input className="input" style={{ maxWidth: 360, marginBottom: 14 }} placeholder="ابحث بالرقم أو الاسم أو السجل أو الجوال" value={q} onChange={(e) => setQ(e.target.value)} />
      {loading ? (
        <Loading />
      ) : (
        <PagedTable
          rows={rows}
          head={['رقم المورد', 'الاسم', 'السجل التجاري', 'الرقم الضريبي', 'الجوال', 'الإيميل', 'الحالة', '']}
          empty={q ? 'لا نتائج مطابقة للبحث.' : 'لا يوجد موردون بعد. أضف أول مورد.'}
          render={(v) => (
            <tr key={v.id}>
              <td className="ltr">{v.vendorNumber}</td>
              <td><strong>{v.name}</strong></td>
              <td className="ltr">{v.commercialRegister || <span className="muted">—</span>}</td>
              <td className="ltr">{v.taxNumber || <span className="muted">—</span>}</td>
              <td className="ltr">{v.mobile || <span className="muted">—</span>}</td>
              <td className="ltr">{v.email || <span className="muted">—</span>}</td>
              <td><StateBadge on={v.isActive} /></td>
              <td className="cell-actions">
                <Button small onClick={() => setModal(v)}>«عدّل»</Button>{' '}
                <Button small onClick={() => attempt(() => api.patch(`/reference/vendors/${v.id}`, { isActive: !v.isActive }), 'تم.', reload)}>{v.isActive ? '«أوقف»' : '«فعّل»'}</Button>
              </td>
            </tr>
          )}
        />
      )}
      {modal && <VendorModal vendor={modal === 'new' ? null : modal} onClose={() => setModal(null)} onSaved={() => { setModal(null); reload(); }} />}
      {importing && <ImportModal onClose={() => setImporting(false)} onDone={() => { setImporting(false); reload(); }} attempt={attempt} />}
    </Panel>
  );
}

function VendorModal({ vendor, onClose, onSaved }: { vendor: Json | null; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [f, setF] = useState({
    vendorNumber: vendor?.vendorNumber ?? '',
    name: vendor?.name ?? '',
    commercialRegister: vendor?.commercialRegister ?? '',
    taxNumber: vendor?.taxNumber ?? '',
    mobile: vendor?.mobile ?? '',
    email: vendor?.email ?? '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  useModalKeys(onClose);

  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value });

  const submit = async () => {
    setBusy(true);
    setErrors({});
    setMsg(null);
    try {
      if (vendor) {
        const { vendorNumber: _n, ...body } = f; // رقم المورد لا يتغيّر بعد الإنشاء
        await api.patch(`/reference/vendors/${vendor.id}`, body);
        toast.success('حُفظ المورد.');
      } else {
        await api.post('/reference/vendors', f);
        toast.success('أُضيف المورد.');
      }
      onSaved();
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.fields);
        setMsg(e.message);
      } else setMsg(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  const ready = f.vendorNumber.trim() && f.name.trim() && f.commercialRegister.trim() && f.mobile.trim();

  return (
    <div className="modal-backdrop" onClick={onClose} role="dialog" aria-modal="true">
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>{vendor ? 'تعديل مورد' : 'مورد جديد'}</h2>
        {msg && <ErrorBox>{msg}</ErrorBox>}
        <Field label="رقم المورد" error={errors.vendorNumber}><input className="input ltr" value={f.vendorNumber} onChange={set('vendorNumber')} disabled={!!vendor} autoFocus={!vendor} /></Field>
        <Field label="الاسم" error={errors.name}><input className="input" value={f.name} onChange={set('name')} /></Field>
        <Field label="السجل التجاري (إجباري)" error={errors.commercialRegister}><input className="input ltr" inputMode="numeric" value={f.commercialRegister} onChange={set('commercialRegister')} /></Field>
        <Field label="الرقم الضريبي (اختياري)" error={errors.taxNumber}><input className="input ltr" inputMode="numeric" value={f.taxNumber} onChange={set('taxNumber')} /></Field>
        <Field label="رقم الجوال (إجباري)" error={errors.mobile}><input className="input ltr" inputMode="tel" value={f.mobile} onChange={set('mobile')} /></Field>
        <Field label="الإيميل (اختياري)" error={errors.email}><input className="input ltr" type="email" value={f.email} onChange={set('email')} /></Field>
        <div className="row">
          <Button variant="primary" disabled={busy || !ready} onClick={submit}>«احفظ»</Button>
          <Button variant="ghost" onClick={onClose}>«ألغِ»</Button>
        </div>
      </div>
    </div>
  );
}

function ImportModal({ onClose, onDone, attempt }: { onClose: () => void; onDone: () => void; attempt: (fn: () => Promise<unknown>, done: string, after: () => void) => void }) {
  const [bulk, setBulk] = useState('');
  useModalKeys(onClose);

  const run = () => {
    const rows = bulk
      .split('\n')
      .map((l) => l.trim())
      .filter(Boolean)
      .map((l) => {
        const [vendorNumber = '', name = '', commercialRegister = '', taxNumber = '', mobile = '', email = ''] = l.split(/[,\t;]/).map((x) => x.trim());
        return { vendorNumber, name, commercialRegister, taxNumber, mobile, email };
      });
    attempt(() => api.post('/reference/vendors/import', { vendors: rows }), 'استُوردت الموردين.', onDone);
  };

  return (
    <div className="modal-backdrop" onClick={onClose} role="dialog" aria-modal="true">
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>استيراد موردين</h2>
        <Field label="سطر لكل مورد" hint="الرقم, الاسم, السجل التجاري, الرقم الضريبي, الجوال, الإيميل — الرقم والاسم فقط إلزاميان هنا، والبقية تُكمَّل لاحقاً.">
          <textarea className="textarea ltr" rows={7} value={bulk} onChange={(e) => setBulk(e.target.value)} />
        </Field>
        <div className="row">
          <Button variant="primary" disabled={!bulk.trim()} onClick={run}>«استورد»</Button>
          <Button variant="ghost" onClick={onClose}>«ألغِ»</Button>
        </div>
      </div>
    </div>
  );
}
