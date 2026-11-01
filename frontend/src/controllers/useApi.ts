'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '@/models/api';

/** جلب GET مع حالة تحميل وخطأ وإعادة تحميل. `path` فارغ = لا جلب. */
export function useApi<T>(path: string | null) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [status, setStatus] = useState<number | null>(null);
  const [loading, setLoading] = useState(!!path);
  const seq = useRef(0);

  const load = useCallback(async () => {
    if (!path) return;
    const my = ++seq.current;
    setLoading(true);
    try {
      const r = await api.get<T>(path);
      if (my === seq.current) {
        setData(r);
        setError(null);
        setStatus(null);
      }
    } catch (e) {
      if (my === seq.current) {
        setError(e instanceof ApiError ? e.message : 'تعذّر التحميل.');
        setStatus(e instanceof ApiError ? e.status : null);
      }
    } finally {
      if (my === seq.current) setLoading(false);
    }
  }, [path]);

  useEffect(() => {
    load();
  }, [load]);

  return { data, error, status, loading, reload: load, setData };
}

export function errorText(e: unknown) {
  return e instanceof ApiError ? e.message : 'تعذّر تنفيذ الطلب.';
}
