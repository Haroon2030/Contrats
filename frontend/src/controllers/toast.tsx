'use client';

import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';

export interface Toast {
  id: number;
  kind: 'success' | 'error';
  text: string;
}

interface ToastState {
  toasts: Toast[];
  success: (text: string) => void;
  error: (text: string) => void;
  dismiss: (id: number) => void;
}

const ToastContext = createContext<ToastState | null>(null);

/** رسائل النجاح تظهر في شريط قابل للإغلاق، وتختفي بعد ٦ ثوانٍ. */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const dismiss = useCallback((id: number) => setToasts((list) => list.filter((t) => t.id !== id)), []);

  const push = useCallback(
    (kind: Toast['kind'], text: string) => {
      const id = Date.now() + Math.random();
      setToasts((list) => [...list, { id, kind, text }]);
      if (kind === 'success') setTimeout(() => dismiss(id), 6000);
    },
    [dismiss],
  );

  const value = useMemo(
    () => ({
      toasts,
      success: (text: string) => push('success', text),
      error: (text: string) => push('error', text),
      dismiss,
    }),
    [toasts, push, dismiss],
  );

  return <ToastContext.Provider value={value}>{children}</ToastContext.Provider>;
}

export function useToast(): ToastState {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used inside ToastProvider');
  return ctx;
}
