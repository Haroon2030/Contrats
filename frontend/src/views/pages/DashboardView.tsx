'use client';

import Link from 'next/link';
import { useSession } from '@/controllers/session';
import { useDashboard } from '@/controllers/useDashboard';
import { ALERT_LABELS } from '@/models/labels';
import { Empty, ErrorBox, Loading, PageHead, Panel, Stat, fmtDate, fmtMoney } from '../ui';

/** ألوان شرائح الحالات في الشريط (دلالية، وليست زينة). */
const STATUS_COLORS: Record<string, string> = {
  draft: '#9aa1a9',
  under_review: '#7d96b8',
  pending_approval: '#eaa563',
  pending_signature: '#c2570c',
  active: '#2f9e6e',
  expired: '#bdb7ad',
  cancelled: '#cf6a62',
};

/** لوحة التحكم: مؤشرات رئيسية، توزيع الحالات، الانتهاء القريب، ثم التفاصيل. */
export function DashboardView() {
  const { user } = useSession();
  const { summary, alerts, criticalCount, error, loading } = useDashboard();

  if (loading) return <Loading />;
  if (error || !summary) return <ErrorBox>{error ?? 'تعذّر تحميل لوحة التحكم.'}</ErrorBox>;

  const countOf = (status: string) => summary.byStatus.find((s) => s.status === status)?.count ?? 0;
  const expiring = (days: number) => summary.expiring.find((e) => e.days === days)?.count ?? 0;
  const maxExpiring = Math.max(1, ...summary.expiring.map((e) => e.count));
  const maxType = Math.max(1, ...summary.byType.map((t) => t.count));
  const maxDept = Math.max(1, ...summary.departments.map((d) => d.estimatedDiscount));
  const awaiting = summary.awaiting.length;

  return (
    <>
      <PageHead title={`مرحباً ${user?.fullName ?? ''}`} subtitle={user?.roleLabel} />

      {criticalCount > 0 && (
        <ErrorBox>
          لديك {criticalCount} تنبيه حرج يحتاج متابعة. <Link href="/contracts?view=awaiting">عرض العقود</Link>
        </ErrorBox>
      )}

      <div className="kpis">
        <Stat label="إجمالي العقود" value={summary.total} icon="layers" hint={`${countOf('active')} نشط حالياً`} />
        <Stat
          label="بانتظار إجرائي"
          value={awaiting}
          accent
          icon="clock"
          hint={awaiting > 0 ? 'تحتاج قرارك' : 'لا يوجد ما ينتظرك'}
        />
        <Stat
          label="تنبيهات مفتوحة"
          value={alerts.length}
          tone={criticalCount > 0 ? 'danger' : undefined}
          icon="bell"
          hint={criticalCount > 0 ? `${criticalCount} حرج` : 'لا توجد تنبيهات حرجة'}
        />
        <Stat
          label="ينتهي خلال 30 يوماً"
          value={expiring(30)}
          accent={expiring(30) > 0}
          icon="calendar"
          hint={`${expiring(90)} خلال 90 يوماً`}
        />
      </div>

      <div className="grid-2">
        <Panel title="حالة العقود">
          {summary.total === 0 ? (
            <Empty>لا توجد عقود بعد.</Empty>
          ) : (
            <>
              <div className="dist-bar" role="img" aria-label="توزيع العقود حسب الحالة">
                {summary.byStatus
                  .filter((s) => s.count > 0)
                  .map((s) => (
                    <span key={s.status} style={{ flex: s.count, background: STATUS_COLORS[s.status] }} title={`${s.label}: ${s.count}`} />
                  ))}
              </div>
              <ul className="legend">
                {summary.byStatus.map((s) => (
                  <li key={s.status}>
                    <span className="dot" style={{ background: STATUS_COLORS[s.status] }} />
                    <span>{s.label}</span>
                    <strong>{s.count}</strong>
                  </li>
                ))}
              </ul>
            </>
          )}
        </Panel>

        <Panel title="الانتهاء القريب">
          <div className="stack" style={{ gap: 0 }}>
            {summary.expiring.map((e) => (
              <div key={e.days} className="meter">
                <div className="meter-head">
                  <span>خلال {e.days} يوماً</span>
                  <strong>{e.count}</strong>
                </div>
                <div className="meter-track">
                  <div
                    className="meter-fill"
                    style={{
                      width: `${(e.count / maxExpiring) * 100}%`,
                      background: e.days <= 30 ? 'var(--accent)' : e.days <= 60 ? '#eaa563' : '#9aa1a9',
                    }}
                  />
                </div>
              </div>
            ))}
          </div>
        </Panel>
      </div>

      <div className="grid-2">
        <Panel title="بانتظار إجرائي">
          {awaiting === 0 ? (
            <Empty>لا توجد عقود تنتظر إجراءك الآن.</Empty>
          ) : (
            <ul className="list">
              {summary.awaiting.map((c) => (
                <li key={c.id}>
                  <Link href={`/contracts/${c.id}`} className="row" style={{ justifyContent: 'space-between' }}>
                    <span>
                      <span className="ltr">{c.contractNumber}</span> — {c.vendorName}
                    </span>
                    <span className="badge accent">{c.primaryAction ?? 'مراجعة'}</span>
                  </Link>
                  <div className="muted" style={{ fontSize: 13 }}>
                    {c.statusLabel} — منتظر منذ {c.waitingDays} يوم
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel title="الأقرب إلى الانتهاء">
          {summary.nearest.length === 0 ? (
            <Empty>لا توجد عقود قريبة من الانتهاء.</Empty>
          ) : (
            <div className="table-flat">
              <table className="table" style={{ minWidth: 0 }}>
                <thead>
                  <tr>
                    <th>العقد</th>
                    <th>ينتهي</th>
                    <th className="num">متبقٍ</th>
                  </tr>
                </thead>
                <tbody>
                  {summary.nearest.map((n) => (
                    <tr key={n.id}>
                      <td>
                        <Link href={`/contracts/${n.id}`}>
                          <span className="ltr">{n.contractNumber}</span>
                        </Link>
                      </td>
                      <td>{fmtDate(n.endDate)}</td>
                      <td className="num">{n.daysRemaining} يوم</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      </div>

      <div className="grid-2">
        <Panel title="الخصم التقديري حسب القسم">
          {summary.departments.length === 0 ? (
            <Empty>لا توجد أهداف مسجلة بعد.</Empty>
          ) : (
            <div className="stack" style={{ gap: 0 }}>
              {summary.departments.map((d) => (
                <div key={d.department} className="meter">
                  <div className="meter-head">
                    <span>{d.department}</span>
                    <strong>{fmtMoney(d.estimatedDiscount)}</strong>
                  </div>
                  <div className="meter-track">
                    <div className="meter-fill" style={{ width: `${(d.estimatedDiscount / maxDept) * 100}%`, background: 'var(--accent)' }} />
                  </div>
                </div>
              ))}
            </div>
          )}
          <div className="muted" style={{ fontSize: 12, marginTop: 14 }}>
            الخصم التقديري = قيمة الشريحة × نسبة الخصم، لكل العقود غير الملغاة والمنتهية.
          </div>
        </Panel>

        <Panel title="العقود حسب النوع">
          <div className="stack" style={{ gap: 0 }}>
            {summary.byType.map((t) => (
              <div key={t.type} className="meter">
                <div className="meter-head">
                  <span>{t.label}</span>
                  <strong>{t.count}</strong>
                </div>
                <div className="meter-track">
                  <div className="meter-fill" style={{ width: `${(t.count / maxType) * 100}%`, background: 'var(--ink)' }} />
                </div>
              </div>
            ))}
          </div>
        </Panel>
      </div>

      <Panel title="أحدث التنبيهات">
        {alerts.length === 0 ? (
          <Empty>لا توجد تنبيهات مفتوحة.</Empty>
        ) : (
          <ul className="list">
            {alerts.slice(0, 6).map((a) => (
              <li key={a.id}>
                <Link href={`/contracts/${a.contractId}`} className="row" style={{ justifyContent: 'space-between' }}>
                  <span>
                    <span className="ltr">{a.contractNumber}</span> — {a.message}
                  </span>
                  <span className={`badge ${a.severity === 'critical' ? 'danger' : a.severity === 'warning' ? 'accent' : 'neutral'}`}>
                    {ALERT_LABELS[a.alertType] ?? a.alertType}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </>
  );
}
