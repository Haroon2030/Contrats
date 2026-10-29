'use client';

import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '@/models/api';
import type { AuditRow, Department, ShelfRateRecord, UserRow, VendorRecord } from '@/models/types';

/** إدارة المستخدمين (مدير النظام). */
export function useUsers() {
  const [users, setUsers] = useState<UserRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const reload = useCallback(async () => {
    setLoading(true);
    try {
      setUsers(await api.get<UserRow[]>('/users'));
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'تعذّر تحميل المستخدمين.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    reload();
  }, [reload]);

  const save = async (id: number | null, body: Record<string, unknown>) => {
    try {
      if (id) await api.patch(`/users/${id}`, body);
      else await api.post('/users', body);
      await reload();
      return { ok: true as const };
    } catch (e) {
      return { ok: false as const, message: e instanceof ApiError ? e.message : 'تعذّر الحفظ.', fields: e instanceof ApiError ? e.fields : {} };
    }
  };

  return { users, loading, error, save, reload };
}

/** سجل التدقيق مع فلاتر بالعقد والتاريخ. */
export function useAudit() {
  const [rows, setRows] = useState<AuditRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filters, setFilters] = useState({ contractId: '', from: '', to: '' });

  useEffect(() => {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([k, v]) => v && params.set(k, v));
    setLoading(true);
    api
      .get<AuditRow[]>(`/audit?${params.toString()}`)
      .then((r) => {
        setRows(r);
        setError(null);
      })
      .catch((e) => setError(e instanceof ApiError ? e.message : 'تعذّر تحميل السجل.'))
      .finally(() => setLoading(false));
  }, [filters]);

  return { rows, loading, error, filters, setFilters };
}

/** القوائم المرجعية: الأقسام والموردون وأنواع الأرفف (الإضافة والتعطيل فقط). */
export function useReference() {
  const [departments, setDepartments] = useState<Department[]>([]);
  const [vendors, setVendors] = useState<VendorRecord[]>([]);
  const [shelfRates, setShelfRates] = useState<ShelfRateRecord[]>([]);
  const [error, setError] = useState<string | null>(null);

  const reload = useCallback(async () => {
    try {
      const [d, v, s] = await Promise.all([
        api.get<Department[]>('/reference/departments?includeInactive=1'),
        api.get<VendorRecord[]>('/reference/vendors?includeInactive=1'),
        api.get<ShelfRateRecord[]>('/reference/shelf-rates?includeInactive=1'),
      ]);
      setDepartments(d);
      setVendors(v);
      setShelfRates(s);
      setError(null);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'تعذّر تحميل القوائم.');
    }
  }, []);

  useEffect(() => {
    reload();
  }, [reload]);

  const write = async (path: string, body: unknown) => {
    try {
      await api.post(path, body);
      await reload();
      return { ok: true as const };
    } catch (e) {
      return { ok: false as const, message: e instanceof ApiError ? e.message : 'تعذّر الحفظ.', fields: e instanceof ApiError ? e.fields : {} };
    }
  };

  const toggle = async (path: string, body: Record<string, unknown>) => {
    try {
      await api.patch(path, body);
      await reload();
      return { ok: true as const };
    } catch (e) {
      return { ok: false as const, message: e instanceof ApiError ? e.message : 'تعذّر الحفظ.' };
    }
  };

  return { departments, vendors, shelfRates, error, write, toggle };
}
