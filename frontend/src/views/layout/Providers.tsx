'use client';

import type { ReactNode } from 'react';
import { SessionProvider } from '@/controllers/session';
import { ToastProvider } from '@/controllers/toast';

export function Providers({ children }: { children: ReactNode }) {
  return (
    <SessionProvider>
      <ToastProvider>{children}</ToastProvider>
    </SessionProvider>
  );
}
