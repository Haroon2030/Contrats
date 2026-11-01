import type { ContractStatus, ContractType } from './types';

export const STATUS_LABELS: Record<ContractStatus, string> = {
  draft: 'مسودة',
  under_review: 'تحت المراجعة',
  awaiting_signature: 'بانتظار التوقيع',
  active: 'نشط',
  ended: 'منتهٍ',
  cancelled: 'ملغي',
};

export const STATUS_TONE: Record<ContractStatus, 'neutral' | 'accent' | 'danger' | 'muted' | 'ok'> = {
  draft: 'neutral',
  under_review: 'accent',
  awaiting_signature: 'accent',
  active: 'ok',
  ended: 'muted',
  cancelled: 'danger',
};

/** مراحل الشريط في صفحة العقد. */
export const STAGES: { key: ContractStatus; label: string }[] = [
  { key: 'draft', label: 'مسودة' },
  { key: 'under_review', label: 'تحت المراجعة' },
  { key: 'awaiting_signature', label: 'بانتظار التوقيع' },
  { key: 'active', label: 'نشط' },
];

export const TYPE_OPTIONS: { value: ContractType; label: string; hint?: string }[] = [
  { value: 'supply', label: 'توريد' },
  { value: 'rent', label: 'إيجار أرفف' },
  { value: 'marketing', label: 'تسويق' },
  { value: 'add_item', label: 'خدمات — إضافة صنف جديد' },
  { value: 'open_account', label: 'خدمات — فتح حساب', hint: 'مستقل ولا يُجمع مع نوع آخر' },
];

export const DISCOUNT_KINDS: Record<string, string> = {
  fixed: 'خصم ثابت',
  goals: 'خصم أهداف',
  settlement: 'خصم تسوية',
  none: 'بلا خصم',
};

export const ACCOUNT_DOCS: { kind: string; label: string }[] = [
  { kind: 'account_license', label: 'رخصة النشاط التجاري' },
  { kind: 'account_address', label: 'وثيقة العنوان الوطني' },
  { kind: 'account_registration', label: 'شهادة تسجيل الشركة' },
  { kind: 'account_tax', label: 'شهادة تسجيل الضريبة' },
  { kind: 'account_owner_id', label: 'هوية المالك' },
];

export const CHANGE_LABELS: Record<string, string> = {
  paymentDays: 'أيام السداد',
  settlementDiscountPct: 'خصم التسوية %',
  fixedDiscountPct: 'الخصم الثابت %',
  goalLines: 'أهداف المشتريات',
  specialTerms: 'الشروط الخاصة',
  repName: 'اسم الممثل',
  repTitle: 'صفة الممثل',
  repMobile: 'جوال الممثل',
  newItemPrice: 'سعر الصنف',
};
