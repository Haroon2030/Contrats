'use client';

import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '@/models/api';
import type { ContractRow } from '@/models/types';

export interface ContractFilters {
  q: string;
  status: string;
  contractType: string;
  departmentId: string;
  expiringWithin: string;
  view: '' | 'awaiting' | 'mine';
}

const EMPTY: ContractFilters = { q: '', status: '', contractType: '', departmentId: '', expiringWithin: '', view: '' };

/** سجل العقود: الفلاتر تُرسل للخادم، والتصدير يتم من الصفوف المعروضة. */
export function useContracts(initial: Partial<ContractFilters> = {}) {
  const [filters, setFilters] = useState<ContractFilters>({ ...EMPTY, ...initial });
  const [rows, setRows] = useState<ContractRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (f: ContractFilters) => {
    setLoading(true);
    setError(null);
    const params = new URLSearchParams();
    Object.entries(f).forEach(([k, v]) => v && params.set(k, v));
    try {
      setRows(await api.get<ContractRow[]>(`/contracts?${params.toString()}`));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'تعذّر تحميل العقود.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const t = setTimeout(() => load(filters), filters.q ? 250 : 0);
    return () => clearTimeout(t);
  }, [filters, load]);

  const setFilter = (key: keyof ContractFilters, value: string) => setFilters((f) => ({ ...f, [key]: value }));
  const reset = () => setFilters({ ...EMPTY });

  const stats = {
    total: rows.length,
    awaiting: rows.filter((r) => r.awaitingUser).length,
    expiringSoon: rows.filter((r) => r.daysRemaining >= 0 && r.daysRemaining <= 90 && !['cancelled', 'expired'].includes(r.status)).length,
  };

  return { rows, filters, setFilter, reset, loading, error, stats, reload: () => load(filters) };
}

/** تصدير CSV للصفوف الظاهرة (يفتح في Excel مباشرة مع دعم UTF-8). */
export function exportRowsToCsv(rows: ContractRow[]) {
  const header = ['رقم العقد', 'المورد', 'النوع', 'القسم', 'تاريخ الانتهاء', 'الحالة', 'القيمة الفعلية', 'المعتمد المطلوب'];
  const lines = rows.map((r) =>
    [r.contractNumber, r.vendorName, r.contractTypeLabel, r.departmentName, r.endDate, r.statusLabel, r.actualValue, r.requiredApprover]
      .map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`)
      .join(','),
  );
  const blob = new Blob(['﻿' + [header.join(','), ...lines].join('\n')], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `contracts-${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}
