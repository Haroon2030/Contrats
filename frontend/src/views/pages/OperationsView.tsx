'use client';

import { useApi } from '@/controllers/useApi';
import { Empty, ErrorBox, Loading, PageHead, Panel, fmtDate, fmtMoney } from '../ui';

interface Ops {
  rent: { id: number; contractNumber: string; vendor: string; branch: string; shelf: string; qty: number; price: number; unit: string; startDate: string; endDate: string; total?: number }[];
  marketing: { id: number; contractNumber: string; vendor: string; event: string; price: number; note: string }[];
}

/** التشغيل: إيجار الأرفف والفعاليات التسويقية للعقود النشطة فقط، بلا أي بنود توريد أو خصومات. */
export function OperationsView() {
  const { data, loading, error } = useApi<Ops>('/operations');
  return (
    <>
      <PageHead title="التشغيل" subtitle="الإيجار والفعاليات التسويقية في العقود النشطة" />
      {error && <ErrorBox>{error}</ErrorBox>}
      {loading && <Loading />}
      {data && (
        <>
          <Panel title="إيجار الأرفف">
            {data.rent.length === 0 ? (
              <Empty>لا توجد عقود إيجار نشطة.</Empty>
            ) : (
              <div className="table-wrap">
                <table className="table">
                  <thead>
                    <tr><th>العقد</th><th>المورد</th><th>الفرع</th><th>نوع الرف</th><th>العدد</th><th>السعر</th><th>الفترة</th></tr>
                  </thead>
                  <tbody>
                    {data.rent.map((r) => (
                      <tr key={r.id}>
                        <td className="ltr">{r.contractNumber}</td>
                        <td>{r.vendor}</td>
                        <td>{r.branch}</td>
                        <td>{r.shelf}</td>
                        <td>{r.qty}</td>
                        <td>{fmtMoney(r.price)} / {r.unit === 'week' ? 'أسبوع' : 'شهر'}</td>
                        <td>{fmtDate(r.startDate)} — {fmtDate(r.endDate)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
          <Panel title="الفعاليات التسويقية">
            {data.marketing.length === 0 ? (
              <Empty>لا توجد فعاليات نشطة.</Empty>
            ) : (
              <div className="table-wrap">
                <table className="table">
                  <thead>
                    <tr><th>العقد</th><th>المورد</th><th>الفعالية</th><th>السعر</th><th>ملاحظة</th></tr>
                  </thead>
                  <tbody>
                    {data.marketing.map((m) => (
                      <tr key={m.id}>
                        <td className="ltr">{m.contractNumber}</td>
                        <td>{m.vendor}</td>
                        <td>{m.event}</td>
                        <td>{fmtMoney(m.price)}</td>
                        <td>{m.note || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
        </>
      )}
    </>
  );
}
