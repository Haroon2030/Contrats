import { AppShell } from '@/views/layout/AppShell';
import { ContractDetailView } from '@/views/pages/ContractDetailView';

export default async function ContractPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return (
    <AppShell>
      <ContractDetailView id={Number(id)} />
    </AppShell>
  );
}
