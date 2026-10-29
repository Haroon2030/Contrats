import { AppShell } from '@/views/layout/AppShell';
import { DashboardView } from '@/views/pages/DashboardView';

export default function DashboardPage() {
  return (
    <AppShell>
      <DashboardView />
    </AppShell>
  );
}
