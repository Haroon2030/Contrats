'use client';

import Link from 'next/link';
import { useState } from 'react';
import { useSession } from '@/controllers/session';
import { useToast } from '@/controllers/toast';
import { errorText, useApi } from '@/controllers/useApi';
import { api } from '@/models/api';
import type { GoalRow } from '@/models/types';
import { Button, Empty, ErrorBox, Loading, PageHead, Panel, Stat, fmtDate, fmtDateTime, fmtMoney } from '../ui';

interface GoalsData {
  rows: GoalRow[];
  totals: { estimatedDiscount: number; byDepartment: { name: string; estimatedDiscount: number }[]; byVendor: { name: string; estimatedDiscount: number }[] };
  lastUpdate: string | null;
}

/** الأهداف: صافي المشتريات (مشتريات − مرتجعات) مقابل الأهداف، والخصم التقديري = أعلى شريحة متحققة × الصافي. */
export function GoalsView() {
  const { user } = useSession();
  const toast = useToast();
  const { data, loading, error, reload } = useApi<GoalsData>('/goals');
  const [csv, setCsv] = useState('');
  const [busy, setBusy] = useState(false);

  /** استيراد لصق: كل سطر «رقم المورد,التاريخ,المشتريات,المرتجعات». */
  const importCsv = async () => {
    const records = csv
      .split('\n')
      .map((l) => l.trim())
      .filter(Boolean)
      .map((l) => {
        const [vendorNumber, date, purchases, returns] = l.split(/[,\t;]/).map((s) => s.trim());
        return { vendorNumber, date, purchases: Number(purchases), returns: Number(returns || 0) };
      });
    setBusy(true);
    try {
      const r = await api.post<{ created: number }>('/goals/purchases', { records });
      toast.success(`استُوردت ${r.created} سجلات.`);
      setCsv('');
      reload();
    } catch (e) {
      toast.error(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHead
        title="الأهداف والخصومات"
        subtitle={data?.lastUpdate ? `آخر تحديث للمشتريات: ${fmtDateTime(data.lastUpdate)}` : 'لا مشتريات مستوردة بعد'}
        actions={<a className="btn" href="/api/goals/export">«صدّر إلى Excel»</a>}
      />
      {error && <ErrorBox>{error}</ErrorBox>}
      {loading && <Loading />}
      {data && (
        <>
          <div className="stats">
            <Stat label="إجمالي الخصم التقديري" value={fmtMoney(data.totals.estimatedDiscount)} accent icon="check" />
            <Stat label="سطور الأهداف" value={data.rows.length} icon="layers" />
          </div>
          {data.rows.length === 0 ? (
            <Empty>لا توجد أهداف نشطة.</Empty>
          ) : (
            <div className="table-wrap">
              <table className="table">
                <thead>
                  <tr>
                    <th>العقد</th>
                    <th>المورد</th>
                    <th>القسم</th>
                    <th>الفترة</th>
                    <th className="num">الهدف</th>
                    <th className="num">الصافي</th>
                    <th>التحقق</th>
                    <th className="num">الخصم %</th>
                    <th className="num">التقديري</th>
                    <th>الحالة</th>
                  </tr>
                </thead>
                <tbody>
                  {data.rows.map((g) => (
                    <tr key={g.id}>
                      <td><Link className="ltr" href={`/contracts/${g.contractId}`}>{g.number}</Link></td>
                      <td>{g.vendorName}</td>
                      <td>{g.departmentName}</td>
                      <td>{fmtDate(g.fromDate)} — {fmtDate(g.toDate)}</td>
                      <td className="num">{fmtMoney(g.target)}</td>
                      <td className="num">{fmtMoney(g.net)}</td>
                      <td>{g.achievedPct.toFixed(0)}%</td>
                      <td className="num">{g.discountPct}%</td>
                      <td className="num">{g.applied ? <strong>{fmtMoney(g.estimatedDiscount)}</strong> : '—'}</td>
                      <td><span className={`badge ${g.applied ? 'ok' : 'neutral'}`}>{g.applied ? 'الشريحة المطبّقة' : g.statusLabel}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
      {user?.caps.canManageUsers && (
        <Panel title="استيراد المشتريات والمرتجعات (مسؤول النظام)">
          <p className="muted">كل سطر: رقم المورد, التاريخ (YYYY-MM-DD), المشتريات, المرتجعات. بديل عن الربط الآلي بمفتاح التكامل.</p>
          <textarea className="textarea ltr" rows={5} value={csv} onChange={(e) => setCsv(e.target.value)} placeholder="10234,2026-11-01,250000,10000" />
          <div className="row" style={{ marginTop: 8 }}>
            <Button variant="primary" onClick={importCsv} disabled={busy || !csv.trim()}>«استورد»</Button>
          </div>
        </Panel>
      )}
    </>
  );
}
