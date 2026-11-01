'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useEffect, useMemo, useState } from 'react';
import { useSession } from '@/controllers/session';
import { useApi } from '@/controllers/useApi';
import { DISCOUNT_KINDS, STATUS_LABELS, TYPE_OPTIONS } from '@/models/labels';
import type { ContractRow, Options } from '@/models/types';
import { Empty, ErrorBox, LinkButton, Loading, PageHead, Panel, StatusBadge, fmtDate } from '../ui';

const EMPTY = { q: '', status: '', type: '', departmentId: '', discountType: '', expiringWithin: '', view: '', alert: '' };
const RECENT_KEY = 'contracts_recent_searches';

/** سجل العقود: بحث (مع آخر 3 عمليات بحث) وفلاتر حسب BRD، وتصدير Excel لنتائج البحث. */
export function ContractsView() {
  const params = useSearchParams();
  const { user } = useSession();
  const [filters, setFilters] = useState(() => ({
    ...EMPTY,
    q: params.get('q') ?? '',
    view: params.get('view') ?? '',
    expiringWithin: params.get('expiringWithin') ?? '',
    status: params.get('status') ?? '',
  }));
  const [debounced, setDebounced] = useState(filters);
  const [recent, setRecent] = useState<string[]>([]);
  const options = useApi<Options>('/reference/options').data;

  useEffect(() => {
    try {
      setRecent(JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]'));
    } catch {
      setRecent([]);
    }
  }, []);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(filters), 300);
    return () => clearTimeout(t);
  }, [filters]);

  const qs = useMemo(() => {
    const p = new URLSearchParams();
    Object.entries(debounced).forEach(([k, v]) => v && p.set(k, v));
    return p.toString();
  }, [debounced]);
  const { data: rows, loading, error } = useApi<ContractRow[]>(`/contracts${qs ? `?${qs}` : ''}`);

  const set = (k: keyof typeof EMPTY, v: string) => setFilters((f) => ({ ...f, [k]: v }));
  const remember = () => {
    const q = filters.q.trim();
    if (!q) return;
    const next = [q, ...recent.filter((r) => r !== q)].slice(0, 3);
    setRecent(next);
    try {
      localStorage.setItem(RECENT_KEY, JSON.stringify(next));
    } catch {
      /* التخزين غير متاح */
    }
  };

  return (
    <>
      <PageHead
        title="سجل العقود"
        subtitle="بحث وفلاتر ومتابعة كل عقد حتى الإغلاق"
        actions={
          <>
            <a className="btn" href={`/api/contracts/export${qs ? `?${qs}` : ''}`}>«صدّر إلى Excel»</a>
            {user?.caps.canCreate && <LinkButton href="/contracts/new" variant="primary">«أنشئ عقداً»</LinkButton>}
          </>
        }
      />

      <Panel>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            remember();
          }}
        >
          <div className="form-grid" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))' }}>
            <input className="input" placeholder="رقم العقد أو اسم المورد" value={filters.q} onChange={(e) => set('q', e.target.value)} onBlur={remember} aria-label="بحث" />
            <select className="select" value={filters.discountType} onChange={(e) => set('discountType', e.target.value)} aria-label="نوع الخصم">
              <option value="">كل أنواع الخصم</option>
              {Object.entries(DISCOUNT_KINDS).map(([k, v]) => (
                <option key={k} value={k}>{v}</option>
              ))}
            </select>
            <select className="select" value={filters.type} onChange={(e) => set('type', e.target.value)} aria-label="النوع">
              <option value="">كل الأنواع</option>
              {TYPE_OPTIONS.map((t) => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </select>
            <select className="select" value={filters.status} onChange={(e) => set('status', e.target.value)} aria-label="الحالة">
              <option value="">كل الحالات</option>
              {Object.entries(STATUS_LABELS).map(([k, v]) => (
                <option key={k} value={k}>{v}</option>
              ))}
            </select>
            <select className="select" value={filters.departmentId} onChange={(e) => set('departmentId', e.target.value)} aria-label="القسم">
              <option value="">كل الأقسام</option>
              {options?.departments.map((d) => (
                <option key={d.id} value={d.id}>{d.name}</option>
              ))}
            </select>
            <select className="select" value={filters.expiringWithin} onChange={(e) => set('expiringWithin', e.target.value)} aria-label="الانتهاء">
              <option value="">كل تواريخ الانتهاء</option>
              {(options?.thresholds.reminderDays ?? [30, 60, 90]).slice().sort((a, b) => a - b).map((d) => (
                <option key={d} value={d}>تنتهي خلال {d} يوماً</option>
              ))}
            </select>
            <select className="select" value={filters.view} onChange={(e) => set('view', e.target.value)} aria-label="العرض">
              <option value="">كل العقود</option>
              <option value="awaiting">بانتظار إجرائي</option>
            </select>
            <select className="select" value={filters.alert} onChange={(e) => set('alert', e.target.value)} aria-label="التنبيه">
              <option value="">بتنبيه وبدونه</option>
              <option value="1">بتنبيه فقط</option>
            </select>
            <button type="button" className="btn" onClick={() => setFilters(EMPTY)}>«أعد الضبط»</button>
          </div>
        </form>
        {recent.length > 0 && (
          <div className="row" style={{ marginTop: 10, flexWrap: 'wrap' }}>
            <span className="muted">آخر ما بحثت عنه:</span>
            {recent.map((r) => (
              <button key={r} className="btn small ghost" onClick={() => set('q', r)}>{r}</button>
            ))}
          </div>
        )}
      </Panel>

      {error && <ErrorBox>{error}</ErrorBox>}
      {loading && !rows ? (
        <Loading />
      ) : !rows || rows.length === 0 ? (
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
                <th>الحالة</th>
                <th>الجهة المسؤولة</th>
                <th>الانتظار</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id}>
                  <td>
                    <Link className="ltr" href={`/contracts/${r.id}`}>{r.number ?? 'مسودة'}</Link>
                    {r.hasAlert && <span className="badge danger" style={{ marginInlineStart: 6 }}>تنبيه</span>}
                  </td>
                  <td>{r.vendorName}</td>
                  <td>{r.typeLabels.join(' + ')}</td>
                  <td>{r.departmentName}</td>
                  <td>
                    {r.endDate ? fmtDate(r.endDate) : '—'}
                    {r.daysRemaining != null && r.status === 'active' && <div className="muted" style={{ fontSize: 12 }}>متبقٍ {r.daysRemaining} يوماً</div>}
                  </td>
                  <td><StatusBadge status={r.status} label={r.statusLabel} /></td>
                  <td>{r.partyLabel ?? '—'}{r.awaitingUser && <strong style={{ color: 'var(--accent)' }}> ◂ دورك</strong>}</td>
                  <td>{r.party ? `${r.waitingDays} يوم` : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
