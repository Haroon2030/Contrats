'use client';

import Link from 'next/link';
import { useAudit } from '@/controllers/useAdmin';
import { Empty, ErrorBox, Loading, PageHead, Panel, fmtDateTime } from '../ui';

/** سجل التدقيق: للقراءة فقط، مع فلاتر بالعقد والتاريخ. */
export function AuditView() {
  const { rows, loading, error, filters, setFilters } = useAudit();

  return (
    <>
      <PageHead title="سجل التدقيق" subtitle="كل إجراء مسجّل بالمستخدم والدور والتغيير والملاحظة" />

      <Panel>
        <div className="form-grid" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))' }}>
          <input
            className="input ltr"
            placeholder="رقم العقد (المعرّف)"
            value={filters.contractId}
            onChange={(e) => setFilters({ ...filters, contractId: e.target.value.replace(/\D/g, '') })}
            aria-label="معرّف العقد"
          />
          <input className="input" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} aria-label="من تاريخ" />
          <input className="input" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} aria-label="إلى تاريخ" />
        </div>
      </Panel>

      {error && <ErrorBox>{error}</ErrorBox>}
      {loading ? (
        <Loading />
      ) : rows.length === 0 ? (
        <Empty>لا توجد سجلات تطابق الفلاتر.</Empty>
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>الوقت</th>
                <th>العقد</th>
                <th>المستخدم</th>
                <th>الإجراء</th>
                <th>التغيير</th>
                <th>التفاصيل</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id}>
                  <td>{fmtDateTime(r.createdAt)}</td>
                  <td>
                    {r.contractId ? (
                      <Link href={`/contracts/${r.contractId}`}>
                        <span className="ltr">{r.contractNumber}</span>
                      </Link>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td>
                    {r.actor ?? '—'}
                    <div className="muted" style={{ fontSize: 12 }}>{r.actorRole ?? ''}</div>
                  </td>
                  <td>{r.action}</td>
                  <td>{r.fromStatusLabel || r.toStatusLabel ? `${r.fromStatusLabel || '—'} ← ${r.toStatusLabel || '—'}` : '—'}</td>
                  <td className="muted">{Object.entries(r.details).map(([k, v]) => `${k}: ${String(v)}`).join(' · ') || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
