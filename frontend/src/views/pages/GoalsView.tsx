'use client';

import Link from 'next/link';
import { useGoals } from '@/controllers/useDashboard';
import { Empty, ErrorBox, Loading, PageHead, Panel, Stat, fmtMoney } from '../ui';

/** الأهداف والخصومات: شرائح العقود وقيمة الخصم التقديري حسب القسم والمورد. */
export function GoalsView() {
  const { goals, byDepartment, totalDiscount, error, loading } = useGoals();

  if (loading) return <Loading />;
  if (error) return <ErrorBox>{error}</ErrorBox>;

  return (
    <>
      <PageHead title="الأهداف والخصومات" subtitle="شرائح الأهداف في العقود والخصم المتوقع" />

      <div className="stats">
        <Stat label="عدد الشرائح" value={goals.length} />
        <Stat label="الخصم التقديري الإجمالي" value={fmtMoney(totalDiscount)} accent />
        <Stat label="أقسام بشرائح" value={byDepartment.length} />
      </div>

      <div className="notice">
        حالة التحقق (متحقق / غير متحقق) تحتاج صافي المشتريات الفعلي من النظام المحاسبي، وهو غير مربوط بعد. لذلك تظهر الحالة «لا توجد بيانات».
      </div>

      <Panel title="الخصم التقديري حسب القسم">
        {byDepartment.length === 0 ? (
          <Empty>لا توجد شرائح مسجّلة.</Empty>
        ) : (
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>القسم</th>
                  <th className="num">عدد الشرائح</th>
                  <th className="num">الخصم التقديري</th>
                </tr>
              </thead>
              <tbody>
                {byDepartment.map((d) => (
                  <tr key={d.department}>
                    <td>{d.department}</td>
                    <td className="num">{d.count}</td>
                    <td className="num">{fmtMoney(d.discount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Panel title="الشرائح">
        {goals.length === 0 ? (
          <Empty>لا توجد شرائح بعد.</Empty>
        ) : (
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>العقد</th>
                  <th>المورد</th>
                  <th>الشريحة</th>
                  <th className="num">قيمة الهدف</th>
                  <th className="num">الخصم %</th>
                  <th className="num">الخصم التقديري</th>
                  <th>الحالة</th>
                </tr>
              </thead>
              <tbody>
                {goals.map((g) => (
                  <tr key={g.id}>
                    <td>
                      <Link href={`/contracts?q=${encodeURIComponent(g.contractNumber)}`}>
                        <span className="ltr">{g.contractNumber}</span>
                      </Link>
                    </td>
                    <td>{g.vendorName}</td>
                    <td>{g.title}</td>
                    <td className="num">{fmtMoney(g.targetValue)}</td>
                    <td className="num">{g.discountPercent}%</td>
                    <td className="num">{fmtMoney(g.estimatedDiscount)}</td>
                    <td>
                      <span className="badge muted">لا توجد بيانات</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </>
  );
}
