import { AppShell } from '@/views/layout/AppShell';
import { ContractFormView } from '@/views/pages/ContractFormView';

export default async function EditContractPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return (
    <AppShell>
      <ContractFormView contractId={Number(id)} />
    </AppShell>
  );
}
