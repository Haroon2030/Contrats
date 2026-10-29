import { AppShell } from '@/views/layout/AppShell';
import { PlaceholderView } from '@/views/pages/PlaceholderView';

export default function TemplatesPage() {
  return (
    <AppShell>
      <PlaceholderView
        title="القوالب والشروط"
        subtitle="بنود الشروط والأحكام بإصدارات وسجل تعديلات، وقالب PDF للعقد"
        needs={[
          'جدول بنود الشروط والأحكام بإصدارات (البند، النص، الإصدار، تاريخ النشر، من عدّله).',
          'ربط كل بند بأنواع العقود التي ينطبق عليها ومعاينته.',
          'إعداد قالب PDF: الشعار والترويسة والتذييل ومكان التوقيع.',
        ]}
      />
    </AppShell>
  );
}
