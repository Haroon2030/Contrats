import { AppShell } from '@/views/layout/AppShell';
import { ContractFormView } from '@/views/pages/ContractFormView';

export default async function EditContractPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ pending?: string }>;
}) {
  const { id } = await params;
  const { pending } = await searchParams;
  return (
    <AppShell>
      <ContractFormView contractId={Number(id)} pending={pending === '1'} />
    </AppShell>
  );
}
