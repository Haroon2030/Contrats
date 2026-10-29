'use client';

import { PageHead, Panel } from '../ui';

/**
 * أقسام المواصفة التي تحتاج جداول غير موجودة في قاعدة البيانات الحالية.
 * تبقى واضحة للمستخدم بدل أن تظهر كشاشات وهمية.
 */
export function PlaceholderView({ title, subtitle, needs }: { title: string; subtitle: string; needs: string[] }) {
  return (
    <>
      <PageHead title={title} subtitle={subtitle} />
      <Panel title="ما الذي ينقص لتفعيل هذه الشاشة">
        <ul style={{ margin: 0, paddingInlineStart: 20 }}>
          {needs.map((n) => (
            <li key={n} style={{ marginBottom: 6 }}>
              {n}
            </li>
          ))}
        </ul>
      </Panel>
      <div className="notice">
        هذه الشاشة مؤجلة عمداً: إضافة جداولها تغيّر قاعدة بيانات الإنتاج الحالية (Django)، ويجب الاتفاق عليها قبل تنفيذها.
      </div>
    </>
  );
}
