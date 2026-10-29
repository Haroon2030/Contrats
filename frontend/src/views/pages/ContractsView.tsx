'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { exportRowsToCsv, useContracts } from '@/controllers/useContracts';
import { useSession } from '@/controllers/session';
import { CONTRACT_TYPES, STATUS_LABELS } from '@/models/labels';
import { api } from '@/models/api';
import type { ReferenceOptions } from '@/models/types';
import { Button, Empty, ErrorBox, LinkButton, Loading, PageHead, Panel, Stat, StatusBadge, fmtDate, fmtMoney } from '../ui';

/** سجل العقود: بحث وفلاتر وتصدير، مع إجراء التالي لكل عقد حسب الدور. */
export function ContractsView() {
  const params = useSearchParams();
  const initialView = params.get('view') === 'awaiting' ? 'awaiting' : '';
  const { rows, filters, setFilter, reset, loading, error, stats } = useContracts({
    q: params.get('q') ?? '',
    view: initialView as '' | 'awaiting',
  });
  const { user } = useSession();
  const [options, setOptions] = useState<ReferenceOptions | null>(null);
  useEffect(() => {
    api.get<ReferenceOptions>('/reference/options').then(setOptions).catch(() => setOptions(null));
  }, []);

  return (
    <>
      <PageHead
        title="سجل العقود"
        subtitle="بحث وفلاتر، ومتابعة كل عقد حتى الإغلاق"
        actions={
          <>
            <Button onClick={() => exportRowsToCsv(rows)} disabled={!rows.length}>
              «صدّر إلى Excel»
            </Button>
            {user?.isContractsStaff && <LinkButton href="/contracts/new" variant="primary">«أنشئ عقداً»</LinkButton>}
          </>
        }
      />

      <div className="stats">
        <Stat label="النتائج" value={stats.total} />
        <Stat label="بانتظار إجرائي" value={stats.awaiting} accent />
        <Stat label="تنتهي خلال 90 يوماً" value={stats.expiringSoon} />
      </div>

      <Panel>
        <div className="form-grid" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))' }}>
          <input
            className="input"
            placeholder="بحث برقم العقد أو المورد"
            value={filters.q}
            onChange={(e) => setFilter('q', e.target.value)}
            aria-label="بحث"
          />
          <select className="select" value={filters.status} onChange={(e) => setFilter('status', e.target.value)} aria-label="الحالة">
            <option value="">كل الحالات</option>
            {Object.entries(STATUS_LABELS).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
          <select className="select" value={filters.contractType} onChange={(e) => setFilter('contractType', e.target.value)} aria-label="النوع">
            <option value="">كل الأنواع</option>
            {CONTRACT_TYPES.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
          <select className="select" value={filters.departmentId} onChange={(e) => setFilter('departmentId', e.target.value)} aria-label="القسم">
            <option value="">كل الأقسام</option>
            {options?.departments.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </select>
          <select className="select" value={filters.expiringWithin} onChange={(e) => setFilter('expiringWithin', e.target.value)} aria-label="الانتهاء">
            <option value="">كل تواريخ الانتهاء</option>
            <option value="30">خلال 30 يوماً</option>
            <option value="60">خلال 60 يوماً</option>
            <option value="90">خلال 90 يوماً</option>
          </select>
          <select className="select" value={filters.view} onChange={(e) => setFilter('view', e.target.value)} aria-label="العرض">
            <option value="">كل العقود</option>
            <option value="awaiting">بانتظار إجرائي</option>
          </select>
          <Button onClick={reset}>«أعد الضبط»</Button>
        </div>
      </Panel>

      {error && <ErrorBox>{error}</ErrorBox>}
      {loading ? (
        <Loading />
      ) : rows.length === 0 ? (
        <Empty>لا توجد عقود تطابق البحث.</Empty>
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>رقم العقد</th>
                <th>المورد</th>
                <th>النوع</th>
                <th>القسم</th>
                <th>الانتهاء</th>
                <th className="num">القيمة</th>
                <th>الحالة</th>
                <th>الجهة المسؤولة</th>
                <th>الإجراء التالي</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id}>
                  <td>
                    <Link href={`/contracts/${r.id}`}>
                      <strong className="ltr">{r.contractNumber}</strong>
                    </Link>
                  </td>
                  <td>{r.vendorName}</td>
                  <td>
                    {r.contractTypeLabel}
                    {r.subtypeLabel ? ` — ${r.subtypeLabel}` : ''}
                  </td>
                  <td>{r.departmentName}</td>
                  <td>
                    {fmtDate(r.endDate)}
                    <div className="muted" style={{ fontSize: 12 }}>
                      {r.daysRemaining >= 0 ? `متبقٍ ${r.daysRemaining} يوم` : 'منتهٍ'}
                    </div>
                  </td>
                  <td className="num">{fmtMoney(r.actualValue)}</td>
                  <td>
                    <StatusBadge status={r.status} label={r.statusLabel} />
                    {r.isHighValue && <div className="muted" style={{ fontSize: 12 }}>قيمة مرتفعة</div>}
                  </td>
                  <td>{r.responsible ?? '—'}</td>
                  <td>
                    {r.primaryAction ? (
                      <Link href={`/contracts/${r.id}`} className="badge accent">
                        {r.primaryAction}
                      </Link>
                    ) : (
                      <Link href={`/contracts/${r.id}`} className="muted">
                        عرض التفاصيل
                      </Link>
                    )}
                    {r.waitingDays > 0 && <div className="muted" style={{ fontSize: 12 }}>منذ {r.waitingDays} يوم</div>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
