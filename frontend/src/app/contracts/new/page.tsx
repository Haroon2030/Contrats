import { AppShell } from '@/views/layout/AppShell';
import { ContractFormView } from '@/views/pages/ContractFormView';

export default function NewContractPage() {
  return (
    <AppShell>
      <ContractFormView />
    </AppShell>
  );
}
