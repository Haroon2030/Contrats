'use client';

import Link from 'next/link';
import { useSession } from '@/controllers/session';
import { useDashboard } from '@/controllers/useDashboard';
import { ALERT_LABELS } from '@/models/labels';
import { Empty, ErrorBox, Loading, PageHead, Panel, Stat, fmtDate, fmtMoney } from '../ui';

/** لوحة التحكم: ما ينتظر إجراءك، الحالات، القرب من الانتهاء، والخصم التقديري. */
export function DashboardView() {
  const { user } = useSession();
  const { summary, alerts, criticalCount, error, loading } = useDashboard();

  if (loading) return <Loading />;
  if (error || !summary) return <ErrorBox>{error ?? 'تعذّر تحميل لوحة التحكم.'}</ErrorBox>;

  const maxType = Math.max(1, ...summary.byType.map((t) => t.count));
  const maxDept = Math.max(1, ...summary.departments.map((d) => d.estimatedDiscount));

  return (
    <>
      <PageHead title={`مرحباً ${user?.fullName ?? ''}`} subtitle={user?.roleLabel} />

      {criticalCount > 0 && (
        <ErrorBox>
          لديك {criticalCount} تنبيه حرج يحتاج متابعة. <Link href="/contracts?view=awaiting">عرض العقود</Link>
        </ErrorBox>
      )}

      <div className="stats">
        <Stat label="إجمالي العقود" value={summary.total} />
        <Stat label="بانتظار إجرائي" value={summary.awaiting.length} accent />
        <Stat label="تنبيهات مفتوحة" value={alerts.length} />
        <Stat label="ينتهي خلال 30 يوماً" value={summary.expiring.find((e) => e.days === 30)?.count ?? 0} accent />
      </div>

      <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))' }}>
        {summary.expiring.map((e) => (
          <Stat key={e.days} label={`تنتهي خلال ${e.days} يوماً`} value={e.count} />
        ))}
      </div>

      <div className="stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))' }}>
        {summary.byStatus.map((s) => (
          <Stat key={s.status} label={s.label} value={s.count} />
        ))}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(340px, 1fr))', gap: 20 }}>
        <Panel title="بانتظار إجرائي">
          {summary.awaiting.length === 0 ? (
            <Empty>لا توجد عقود تنتظر إجراءك الآن.</Empty>
          ) : (
            <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
              {summary.awaiting.map((c) => (
                <li key={c.id} style={{ borderBottom: '0.5px solid var(--hair)', padding: '10px 0' }}>
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
          )}
        </Panel>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(340px, 1fr))', gap: 20 }}>
        <Panel title="الخصم التقديري حسب القسم">
          {summary.departments.length === 0 ? (
            <Empty>لا توجد أهداف مسجلة بعد.</Empty>
          ) : (
            <div className="stack" style={{ gap: 10 }}>
              {summary.departments.map((d) => (
                <div key={d.department}>
                  <div className="row" style={{ justifyContent: 'space-between' }}>
                    <span>{d.department}</span>
                    <strong>{fmtMoney(d.estimatedDiscount)}</strong>
                  </div>
                  <div style={{ height: 8, borderRadius: 999, overflow: 'hidden', background: 'rgba(47, 52, 58, 0.1)' }}>
                    <div style={{ height: 8, width: `${(d.estimatedDiscount / maxDept) * 100}%`, background: 'var(--accent)', borderRadius: 999 }} />
                  </div>
                </div>
              ))}
            </div>
          )}
          <div className="muted" style={{ fontSize: 12, marginTop: 12 }}>
            الخصم التقديري = قيمة الشريحة × نسبة الخصم، لكل العقود غير الملغاة والمنتهية.
          </div>
        </Panel>

        <Panel title="العقود حسب النوع">
          <div className="stack" style={{ gap: 10 }}>
            {summary.byType.map((t) => (
              <div key={t.type}>
                <div className="row" style={{ justifyContent: 'space-between' }}>
                  <span>{t.label}</span>
                  <strong>{t.count}</strong>
                </div>
                <div style={{ height: 8, borderRadius: 999, overflow: 'hidden', background: 'rgba(47, 52, 58, 0.1)' }}>
                  <div style={{ height: 8, width: `${(t.count / maxType) * 100}%`, background: 'var(--ink)', borderRadius: 999 }} />
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
          <div className="stack" style={{ gap: 8 }}>
            {alerts.slice(0, 6).map((a) => (
              <Link key={a.id} href={`/contracts/${a.contractId}`} className="row" style={{ justifyContent: 'space-between' }}>
                <span>
                  <span className="ltr">{a.contractNumber}</span> — {a.message}
                </span>
                <span className={`badge ${a.severity === 'critical' ? 'danger' : a.severity === 'warning' ? 'accent' : 'neutral'}`}>
                  {ALERT_LABELS[a.alertType] ?? a.alertType}
                </span>
              </Link>
            ))}
          </div>
        )}
      </Panel>

    </>
  );
}
