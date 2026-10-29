import { Suspense } from 'react';
import { AppShell } from '@/views/layout/AppShell';
import { ContractsView } from '@/views/pages/ContractsView';

export default function ContractsPage() {
  return (
    <AppShell>
      <Suspense fallback={null}>
        <ContractsView />
      </Suspense>
    </AppShell>
  );
}
