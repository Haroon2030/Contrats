'use client';

import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '@/models/api';
import type { ContractDetail } from '@/models/types';

export type TabKey = 'summary' | 'terms' | 'attachments' | 'history' | 'audit';

/** صفحة العقد: تحميل التفاصيل وتنفيذ الإجراءات حسب الدور والمرحلة (يحددها الخادم). */
export function useContractDetail(id: number) {
  const [contract, setContract] = useState<ContractDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const reload = useCallback(async () => {
    setError(null);
    try {
      setContract(await api.get<ContractDetail>(`/contracts/${id}`));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'تعذّر تحميل العقد.');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => {
    reload();
  }, [reload]);

  /** تنفيذ إجراء مع سبب اختياري أو إلزامي حسب نوعه. */
  const run = useCallback(
    async (key: string, reason?: string): Promise<{ ok: true } | { ok: false; message: string }> => {
      setBusy(true);
      try {
        const routes: Record<string, [string, unknown?]> = {
          submit: [`/contracts/${id}/actions/submit`],
          legal_approve: [`/contracts/${id}/actions/legal-review`, { approved: true, comment: reason ?? '' }],
          legal_return: [`/contracts/${id}/actions/legal-review`, { approved: false, comment: reason ?? '' }],
          approve: [`/contracts/${id}/actions/decide`, { approved: true, comment: reason ?? '' }],
          reject: [`/contracts/${id}/actions/decide`, { approved: false, comment: reason ?? '' }],
          escalate: [`/contracts/${id}/actions/escalate`, { reason: reason ?? '' }],
          mark_signed: [`/contracts/${id}/actions/mark-signed`],
          cancel: [`/contracts/${id}/actions/cancel`, { reason: reason ?? '' }],
        };
        if (key === 'delete') {
          await api.delete(`/contracts/${id}`);
          return { ok: true };
        }
        const [path, body] = routes[key] ?? [];
        if (!path) return { ok: false, message: 'إجراء غير معروف.' };
        setContract(await api.post<ContractDetail>(path, body));
        return { ok: true };
      } catch (e) {
        return { ok: false, message: e instanceof ApiError ? e.message : 'تعذّر تنفيذ الإجراء.' };
      } finally {
        setBusy(false);
      }
    },
    [id],
  );

  const addNote = useCallback(
    async (text: string) => {
      setBusy(true);
      try {
        setContract(await api.post<ContractDetail>(`/contracts/${id}/notes`, { text }));
        return { ok: true as const };
      } catch (e) {
        return { ok: false as const, message: e instanceof ApiError ? e.message : 'تعذّر حفظ الملاحظة.' };
      } finally {
        setBusy(false);
      }
    },
    [id],
  );

  const upload = useCallback(
    async (kind: string, file: File) => {
      setBusy(true);
      try {
        const form = new FormData();
        form.append('kind', kind);
        form.append('file', file);
        setContract(await api.upload<ContractDetail>(`/contracts/${id}/attachments`, form));
        return { ok: true as const };
      } catch (e) {
        return { ok: false as const, message: e instanceof ApiError ? e.message : 'تعذّر رفع الملف.' };
      } finally {
        setBusy(false);
      }
    },
    [id],
  );

  return { contract, loading, error, busy, run, addNote, upload, reload };
}
