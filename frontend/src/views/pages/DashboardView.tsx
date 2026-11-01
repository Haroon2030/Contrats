'use client';

import Link from 'next/link';
import { useSession } from '@/controllers/session';
import { useApi } from '@/controllers/useApi';
import { STATUS_LABELS } from '@/models/labels';
import type { ContractRow, ContractStatus } from '@/models/types';
import { Empty, ErrorBox, Loading, PageHead, Panel, Stat, StatusBadge, fmtDate, fmtDateTime, fmtMoney } from '../ui';

interface Dash {
  operationsOnly?: boolean;
  total: number;
  byStatus: { status: ContractStatus; label: string; count: number }[];
  byType: { type: string; label: string; count: number }[];
  fixedDiscount: number;
  expiring: { days: number; count: number }[];
  alerts: number;
  late: number;
  awaiting: ContractRow[];
  awaitingCount: number;
  goals?: {
    estimatedDiscount: number;
    byDepartment: { name: string; estimatedDiscount: number }[];
    byVendor: { name: string; estimatedDiscount: number }[];
    lastUpdate: string | null;
    linesCount: number;
  };
  thresholds: { reminderDays: number[]; escalationDays: number };
}

function Bars({ items }: { items: { name: string; value: number; text: string }[] }) {
  const max = Math.max(1, ...items.map((i) => i.value));
  return (
    <div>
      {items.map((i) => (
        <div className="bar-line" key={i.name}>
          <span>{i.name}</span>
          <div className="track">
            <div className="fill" style={{ width: `${(i.value / max) * 100}%` }} />
          </div>
          <strong className="ltr">{i.text}</strong>
        </div>
      ))}
    </div>
  );
}

/** لوحة التحكم حسب الدور: التشغيل يذهب إلى صفحته، والبقية يرون ما يخصّهم. */
export function DashboardView() {
  const { user } = useSession();
  const { data, error, loading } = useApi<Dash>('/dashboard');

  if (loading) return <Loading />;
  if (error) return <ErrorBox>{error}</ErrorBox>;
  if (!data) return null;

  if (data.operationsOnly) {
    return (
      <>
        <PageHead title="لوحة التحكم" subtitle="قسم التشغيل" />
        <Panel>
          <p>يقتصر عملك على الإيجار والفعاليات التسويقية النشطة.</p>
          <Link className="btn primary" href="/operations">«افتح صفحة التشغيل»</Link>
        </Panel>
      </>
    );
  }

  const status = (s: ContractStatus) => data.byStatus.find((x) => x.status === s)?.count ?? 0;

  return (
    <>
      <PageHead title={`مرحباً ${user?.fullName ?? ''}`} subtitle="ملخص العقود ومهامك الحالية" />

      <div className="stats">
        <Stat label="بانتظار إجرائك" value={data.awaitingCount} accent icon="clock" />
        <Stat label="عقود نشطة" value={status('active')} icon="check" />
        <Stat label="تحت المراجعة" value={status('under_review')} icon="layers" />
        <Stat label="بانتظار التوقيع" value={status('awaiting_signature')} icon="file" />
        <Stat label="عقود متأخرة" value={data.late} tone={data.late ? 'danger' : undefined} icon="bell" hint={`تجاوزت ${data.thresholds.escalationDays} أيام في مرحلة واحدة`} />
        <Stat label="عقود بتنبيه" value={data.alerts} tone={data.alerts ? 'danger' : undefined} icon="bell" />
        <Stat label="عقود بخصم ثابت" value={data.fixedDiscount} icon="calendar" />
        {data.goals && <Stat label="الخصم التقديري (الأهداف)" value={fmtMoney(data.goals.estimatedDiscount)} accent icon="check" hint={data.goals.lastUpdate ? `آخر تحديث للمشتريات: ${fmtDateTime(data.goals.lastUpdate)}` : 'لا مشتريات مستوردة بعد'} />}
      </div>

      <div className="split two">
        <Panel title="بانتظار إجرائك">
          {data.awaiting.length === 0 ? (
            <Empty>لا شيء ينتظرك الآن.</Empty>
          ) : (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th>الرقم</th>
                    <th>المورد</th>
                    <th>الحالة</th>
                    <th>انتظار</th>
                  </tr>
                </thead>
                <tbody>
                  {data.awaiting.map((r) => (
                    <tr key={r.id}>
                      <td>
                        <Link className="ltr" href={`/contracts/${r.id}`}>{r.number ?? 'مسودة'}</Link>
                      </td>
                      <td>{r.vendorName}</td>
                      <td>
                        <StatusBadge status={r.status} label={r.statusLabel} />
                        {r.hasAlert && <span className="badge danger" style={{ marginInlineStart: 6 }}>تنبيه</span>}
                      </td>
                      <td>{r.waitingDays} يوم</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <div className="stack">
          <Panel title="تنتهي قريباً">
            <Bars items={data.expiring.map((e) => ({ name: `خلال ${e.days} يوماً`, value: e.count, text: String(e.count) }))} />
            <Link href="/contracts?expiringWithin=90">اعرض العقود المنتهية قريباً</Link>
          </Panel>
          <Panel title="حسب النوع">
            <Bars items={data.byType.map((t) => ({ name: t.label, value: t.count, text: String(t.count) }))} />
          </Panel>
        </div>
      </div>

      {data.goals && (
        <div className="grid-2">
          <Panel title="الخصم التقديري حسب القسم">
            {data.goals.byDepartment.length === 0 ? <Empty>لا بيانات.</Empty> : <Bars items={data.goals.byDepartment.map((d) => ({ name: d.name, value: d.estimatedDiscount, text: fmtMoney(d.estimatedDiscount) }))} />}
          </Panel>
          <Panel title="الخصم التقديري حسب المورد">
            {data.goals.byVendor.length === 0 ? <Empty>لا بيانات.</Empty> : <Bars items={data.goals.byVendor.slice(0, 8).map((d) => ({ name: d.name, value: d.estimatedDiscount, text: fmtMoney(d.estimatedDiscount) }))} />}
          </Panel>
        </div>
      )}
      <div className="muted" style={{ fontSize: 12 }}>
        {Object.entries(STATUS_LABELS).map(([k, v]) => `${v}: ${status(k as ContractStatus)}`).join(' · ')} — تحديث {fmtDate(new Date().toISOString())}
      </div>
    </>
  );
}
