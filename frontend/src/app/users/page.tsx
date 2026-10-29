import { AppShell } from '@/views/layout/AppShell';
import { UsersView } from '@/views/pages/UsersView';

export default function UsersPage() {
  return (
    <AppShell>
      <UsersView />
    </AppShell>
  );
}
