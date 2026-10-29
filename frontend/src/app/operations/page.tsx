import { AppShell } from '@/views/layout/AppShell';
import { PlaceholderView } from '@/views/pages/PlaceholderView';

export default function OperationsPage() {
  return (
    <AppShell>
      <PlaceholderView
        title="التشغيل"
        subtitle="سطور الإيجار الحالية والقادمة لكل فرع، والفعاليات التسويقية"
        needs={[
          'جدول الفروع والأرفف المرتبطة بعقود الإيجار (فرع، رف، كمية، سعر، فترة شهرية أو أسبوعية).',
          'جدول الفعاليات التسويقية مع تواريخها والفروع المستهدفة.',
          'قاعدة الفترات الكاملة فقط (لا فترات جزئية) في الحساب.',
        ]}
      />
    </AppShell>
  );
}
