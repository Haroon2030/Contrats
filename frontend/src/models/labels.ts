import type { ContractStatus } from './types';

export const STATUS_LABELS: Record<string, string> = {
  draft: 'مسودة',
  under_review: 'تحت المراجعة',
  pending_approval: 'بانتظار الموافقة',
  pending_signature: 'بانتظار التوقيع',
  active: 'نشط',
  expired: 'منتهي',
  cancelled: 'ملغى',
};

/** ألوان الحالات — رمادي داكن للأساس، برتقالي للتمييز، أحمر للأخطاء. */
export const STATUS_TONE: Record<ContractStatus, 'neutral' | 'accent' | 'danger' | 'muted'> = {
  draft: 'neutral',
  under_review: 'accent',
  pending_approval: 'accent',
  pending_signature: 'accent',
  active: 'neutral',
  expired: 'muted',
  cancelled: 'danger',
};

/** مراحل شريط التقدم في صفحة العقد. */
export const STAGES: { key: ContractStatus; label: string }[] = [
  { key: 'draft', label: 'مسودة' },
  { key: 'under_review', label: 'مراجعة قانونية' },
  { key: 'pending_approval', label: 'اعتماد' },
  { key: 'pending_signature', label: 'توقيع' },
  { key: 'active', label: 'نشط' },
];

export const ATTACHMENT_KINDS: { value: string; label: string }[] = [
  { value: 'original', label: 'النسخة الأصلية' },
  { value: 'legal_review', label: 'نسخة المراجعة القانونية' },
  { value: 'signed', label: 'النسخة الموقعة' },
  { value: 'addendum', label: 'ملحق' },
  { value: 'renewal_letter', label: 'خطاب تجديد' },
  { value: 'termination_letter', label: 'خطاب إنهاء' },
  { value: 'other', label: 'أخرى' },
];

export const CONTRACT_TYPES: { value: string; label: string }[] = [
  { value: 'supply', label: 'توريد' },
  { value: 'shelf_rent', label: 'إيجار أرفف' },
  { value: 'marketing', label: 'تسويق' },
  { value: 'services', label: 'خدمات' },
];

export const SUPPLY_SUBTYPES: { value: string; label: string }[] = [
  { value: 'fresh', label: 'فريش' },
  { value: 'food', label: 'غذائي' },
  { value: 'non_food', label: 'لا غذائي' },
];

export const ROLE_OPTIONS: { value: string; label: string }[] = [
  { value: 'contracts_officer', label: 'موظف قسم العقود' },
  { value: 'purchasing_officer', label: 'موظف المشتريات' },
  { value: 'purchasing_head', label: 'رئيس قسم المشتريات' },
  { value: 'contracts_manager', label: 'مدير إدارة العقود' },
  { value: 'legal', label: 'الشؤون القانونية' },
  { value: 'admin', label: 'مدير النظام' },
];

export const ALERT_LABELS: Record<string, string> = {
  expiry_90: 'انتهاء خلال 90 يوماً',
  expiry_60: 'انتهاء خلال 60 يوماً',
  expiry_30: 'انتهاء خلال 30 يوماً',
  renewal_notice: 'آخر يوم للإشعار بعدم التجديد',
  unsigned_approved: 'معتمد بدون توقيع',
  expired_still_active: 'منتهٍ وما زال نشطاً',
  shelf_price_deviation: 'انحراف سعر إيجار الأرفف',
};

export const ACTION_CONFIRM: Record<string, { title: string; reasonLabel?: string; reasonRequired?: boolean; confirm: string; danger?: boolean }> = {
  submit: { title: 'إرسال العقد للاعتماد', confirm: 'أرسل للاعتماد' },
  legal_approve: { title: 'اعتماد المراجعة القانونية', reasonLabel: 'ملاحظة (اختياري)', confirm: 'اعتمد قانونياً' },
  legal_return: { title: 'إعادة العقد للمسودة', reasonLabel: 'سبب الإعادة', reasonRequired: true, confirm: 'أعِد للمسودة', danger: true },
  approve: { title: 'اعتماد العقد', reasonLabel: 'ملاحظة (اختياري)', confirm: 'اعتمد' },
  reject: { title: 'رفض العقد', reasonLabel: 'سبب الرفض', reasonRequired: true, confirm: 'ارفض', danger: true },
  escalate: { title: 'تصعيد إلى إدارة العقود', reasonLabel: 'سبب التصعيد', reasonRequired: true, confirm: 'صعّد' },
  mark_signed: { title: 'تثبيت توقيع الطرفين', confirm: 'ثبّت التوقيع' },
  cancel: { title: 'إلغاء العقد', reasonLabel: 'سبب الإلغاء', reasonRequired: true, confirm: 'ألغِ العقد', danger: true },
  delete: { title: 'حذف المسودة نهائياً', confirm: 'احذف المسودة', danger: true },
};
