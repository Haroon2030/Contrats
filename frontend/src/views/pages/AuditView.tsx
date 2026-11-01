'use client';

import Link from 'next/link';
import { useMemo, useState } from 'react';
import { useApi } from '@/controllers/useApi';
import { Empty, ErrorBox, Loading, PageHead, Panel, fmtDateTime } from '../ui';

interface Entry {
  id: number;
  contractId: number | null;
  contractNumber: string | null;
  userName: string;
  userRole: string;
  action: string;
  entity: string;
  before: unknown;
  after: unknown;
  note: string;
  createdAt: string;
}

const short = (v: unknown) => (v == null ? '' : JSON.stringify(v));

/** سجل التدقيق (إضافة فقط): من فعل ماذا ومتى، مع القيمة القديمة والجديدة. */
export function AuditView() {
  const [f, setF] = useState({ userName: '', action: '', from: '', to: '', contractId: '' });
  const qs = useMemo(() => {
    const p = new URLSearchParams();
    Object.entries(f).forEach(([k, v]) => v && p.set(k, v));
    return p.toString();
  }, [f]);
  const { data, loading, error } = useApi<Entry[]>(`/audit${qs ? `?${qs}` : ''}`);
  const set = (k: keyof typeof f, v: string) => setF((s) => ({ ...s, [k]: v }));

  return (
    <>
      <PageHead title="سجل التدقيق" subtitle="لا يُعدَّل ولا يُحذف" />
      <Panel>
        <div className="form-grid" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))' }}>
          <input className="input" placeholder="المستخدم" value={f.userName} onChange={(e) => set('userName', e.target.value)} />
          <input className="input" placeholder="الإجراء" value={f.action} onChange={(e) => set('action', e.target.value)} />
          <input className="input ltr" type="date" value={f.from} onChange={(e) => set('from', e.target.value)} aria-label="من" />
          <input className="input ltr" type="date" value={f.to} onChange={(e) => set('to', e.target.value)} aria-label="إلى" />
          <input className="input ltr" placeholder="معرّف العقد" value={f.contractId} onChange={(e) => set('contractId', e.target.value)} />
        </div>
      </Panel>
      {error && <ErrorBox>{error}</ErrorBox>}
      {loading && !data && <Loading />}
      {data && data.length === 0 && <Empty>لا سجلات.</Empty>}
      {data && data.length > 0 && (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr><th>الوقت</th><th>المستخدم</th><th>الإجراء</th><th>العقد</th><th>قبل</th><th>بعد</th><th>ملاحظة</th></tr>
            </thead>
            <tbody>
              {data.map((a) => (
                <tr key={a.id}>
                  <td>{fmtDateTime(a.createdAt)}</td>
                  <td>{a.userName}</td>
                  <td>{a.action}</td>
                  <td>{a.contractId ? <Link className="ltr" href={`/contracts/${a.contractId}`}>{a.contractNumber}</Link> : '—'}</td>
                  <td className="ltr" style={{ maxWidth: 220, overflowWrap: 'anywhere', fontSize: 12 }}>{short(a.before)}</td>
                  <td className="ltr" style={{ maxWidth: 220, overflowWrap: 'anywhere', fontSize: 12 }}>{short(a.after)}</td>
                  <td>{a.note}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
