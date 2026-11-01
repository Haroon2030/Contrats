'use client';

import { useEffect, useState } from 'react';
import { useToast } from '@/controllers/toast';
import { errorText, useApi } from '@/controllers/useApi';
import { api } from '@/models/api';
import type { Json } from '@/models/types';
import { Button, ErrorBox, Field, Loading, PageHead, Panel } from '../ui';

interface Lists {
  labels: Record<string, string>;
  departments: { id: number; name: string; code: string; isActive: boolean }[];
  values: { id: number; list: string; name: string; isActive: boolean }[];
}

/** القوائم المرجعية: إضافة/تعديل/إيقاف فقط (لا حذف)، والموردون، وThreshold التذكير والتصعيد. */
export function ReferenceView() {
  const toast = useToast();
  const lists = useApi<Lists>('/reference/lists');
  const vendors = useApi<Json[]>('/reference/vendors');
  const settings = useApi<{ reminderDays: number[]; escalationDays: number }>('/reference/settings');
  const [reminders, setReminders] = useState('');
  const [escalation, setEscalation] = useState('');
  const [vend, setVend] = useState({ vendorNumber: '', name: '' });
  const [bulk, setBulk] = useState('');

  useEffect(() => {
    if (settings.data) {
      setReminders(settings.data.reminderDays.join(','));
      setEscalation(String(settings.data.escalationDays));
    }
  }, [settings.data]);

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

  const importVendors = () => {
    const rows = bulk
      .split('\n')
      .map((l) => l.trim())
      .filter(Boolean)
      .map((l) => {
        const [vendorNumber, ...rest] = l.split(/[,\t;]/);
        return { vendorNumber: vendorNumber.trim(), name: rest.join(',').trim() };
      });
    attempt(() => api.post('/reference/vendors/import', { vendors: rows }), 'استُوردت الموردين.', () => { setBulk(''); vendors.reload(); });
  };

  return (
    <>
      <PageHead title="القوائم والإعدادات" subtitle="الإيقاف بدل الحذف، والتغييرات في التدقيق" />

      <Panel title="مدد التذكير والتصعيد (Threshold)">
        <p className="muted">تسري على العقود الجديدة فقط؛ كل عقد يحتفظ بقيمه وقت إنشائه.</p>
        <div className="form-grid">
          <Field label="أيام التذكير قبل الانتهاء (مفصولة بفواصل)"><input className="input ltr" value={reminders} onChange={(e) => setReminders(e.target.value)} /></Field>
          <Field label="أيام التصعيد (البقاء في مرحلة واحدة)"><input className="input ltr" type="number" min={1} value={escalation} onChange={(e) => setEscalation(e.target.value)} /></Field>
        </div>
        <Button variant="primary" onClick={() => attempt(() => api.put('/reference/settings', { reminderDays: reminders.split(',').map((s) => Number(s.trim())).filter((n) => n > 0), escalationDays: Number(escalation) }), 'حُفظت الإعدادات.', settings.reload)}>«احفظ»</Button>
      </Panel>

      <Panel title="الأقسام">
        {L.departments.map((d) => (
          <div className="row" key={d.id} style={{ marginBottom: 6 }}>
            <strong>{d.name}</strong> <span className="muted ltr">{d.code}</span>
            <span className={`badge ${d.isActive ? 'ok' : 'muted'}`}>{d.isActive ? 'نشط' : 'موقوف'}</span>
            <Button small onClick={() => attempt(() => api.patch(`/reference/departments/${d.id}`, { isActive: !d.isActive }), 'تم.', lists.reload)}>{d.isActive ? '«أوقف»' : '«فعّل»'}</Button>
          </div>
        ))}
      </Panel>

      {Object.entries(L.labels).map(([key, title]) => (
        <ListEditor key={key} title={title} items={L.values.filter((v) => v.list === key)} onAdd={(name) => attempt(() => api.post(`/reference/lists/${key}`, { name }), 'أُضيفت.', lists.reload)} onPatch={(id, body) => attempt(() => api.patch(`/reference/lists/${key}/${id}`, body), 'تم.', lists.reload)} />
      ))}

      <Panel title="الموردون">
        <div className="form-grid">
          <Field label="رقم المورد"><input className="input ltr" value={vend.vendorNumber} onChange={(e) => setVend({ ...vend, vendorNumber: e.target.value })} /></Field>
          <Field label="الاسم"><input className="input" value={vend.name} onChange={(e) => setVend({ ...vend, name: e.target.value })} /></Field>
        </div>
        <Button variant="primary" disabled={!vend.vendorNumber || !vend.name} onClick={() => attempt(() => api.post('/reference/vendors', vend), 'أُضيف المورد.', () => { setVend({ vendorNumber: '', name: '' }); vendors.reload(); })}>«أضف مورداً»</Button>
        <Field label="استيراد جماعي (سطر لكل مورد: الرقم, الاسم)">
          <textarea className="textarea ltr" rows={3} value={bulk} onChange={(e) => setBulk(e.target.value)} />
        </Field>
        <Button disabled={!bulk.trim()} onClick={importVendors}>«استورد»</Button>
        <div className="table-wrap" style={{ marginTop: 12 }}>
          <table className="table">
            <thead><tr><th>الرقم</th><th>الاسم</th><th>الحالة</th><th /></tr></thead>
            <tbody>
              {(vendors.data ?? []).map((v) => (
                <tr key={v.id}>
                  <td className="ltr">{v.vendorNumber}</td><td>{v.name}</td>
                  <td><span className={`badge ${v.isActive ? 'ok' : 'muted'}`}>{v.isActive ? 'نشط' : 'موقوف'}</span></td>
                  <td><Button small onClick={() => attempt(() => api.patch(`/reference/vendors/${v.id}`, { isActive: !v.isActive }), 'تم.', vendors.reload)}>{v.isActive ? '«أوقف»' : '«فعّل»'}</Button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </>
  );
}

function ListEditor({ title, items, onAdd, onPatch }: { title: string; items: Lists['values']; onAdd: (n: string) => void; onPatch: (id: number, b: Json) => void }) {
  const [name, setName] = useState('');
  return (
    <Panel title={title}>
      <div className="row" style={{ marginBottom: 10 }}>
        <input className="input" style={{ maxWidth: 280 }} placeholder="اسم جديد" value={name} onChange={(e) => setName(e.target.value)} />
        <Button small variant="primary" disabled={!name.trim()} onClick={() => { onAdd(name.trim()); setName(''); }}>«أضف»</Button>
      </div>
      {items.map((v) => (
        <div className="row" key={v.id} style={{ marginBottom: 6 }}>
          <input className="input" style={{ maxWidth: 280 }} defaultValue={v.name} onBlur={(e) => e.target.value.trim() && e.target.value !== v.name && onPatch(v.id, { name: e.target.value.trim() })} />
          <span className={`badge ${v.isActive ? 'ok' : 'muted'}`}>{v.isActive ? 'نشط' : 'موقوف'}</span>
          <Button small onClick={() => onPatch(v.id, { isActive: !v.isActive })}>{v.isActive ? '«أوقف»' : '«فعّل»'}</Button>
        </div>
      ))}
    </Panel>
  );
}
