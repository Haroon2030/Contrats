/**
 * ثوابت المجال — مطابقة لوثيقة المتطلبات BRD v3.0 (انظر docs/brd-v3-digest.md).
 */

export const Role = {
  SYSTEM_ADMIN: 'system_admin',
  CONTRACTS_MANAGER: 'contracts_manager',
  CONTRACTS_OFFICER: 'contracts_officer',
  PURCHASING_MANAGER: 'purchasing_manager',
  DEPARTMENT_MANAGER: 'department_manager',
  DEPARTMENT_OFFICIAL: 'department_official',
  OPERATIONS: 'operations',
} as const;
export type RoleName = (typeof Role)[keyof typeof Role];
export const ROLES: string[] = Object.values(Role);

export const ROLE_LABELS: Record<string, string> = {
  system_admin: 'مسؤول النظام',
  contracts_manager: 'مدير قسم العقود',
  contracts_officer: 'موظف قسم العقود',
  purchasing_manager: 'مدير المشتريات',
  department_manager: 'مدير قسم',
  department_official: 'مسؤول قسم',
  operations: 'قسم التشغيل',
};

/** قيم قديمة في قواعد البيانات السابقة تُقرأ بمعناها الجديد. */
export function normalizeRole(role: string | null | undefined): string {
  if (role === 'admin') return Role.SYSTEM_ADMIN;
  if (role === 'purchasing_head') return Role.PURCHASING_MANAGER;
  if (role === 'purchasing_officer') return Role.PURCHASING_MANAGER;
  if (role === 'legal') return Role.CONTRACTS_OFFICER;
  return role ?? '';
}

export const Status = {
  DRAFT: 'draft',
  UNDER_REVIEW: 'under_review',
  AWAITING_SIGNATURE: 'awaiting_signature',
  ACTIVE: 'active',
  ENDED: 'ended',
  CANCELLED: 'cancelled',
} as const;
export type StatusName = (typeof Status)[keyof typeof Status];

export const STATUS_LABELS: Record<string, string> = {
  draft: 'مسودة',
  under_review: 'تحت المراجعة',
  awaiting_signature: 'بانتظار التوقيع',
  active: 'نشط',
  ended: 'منتهي',
  cancelled: 'ملغي',
};

/** الجهة الحالية المطلوب منها اتخاذ إجراء. */
export const Party = {
  CREATOR: 'creator',
  DEPARTMENT_MANAGER: 'department_manager',
  CONTRACTS_MANAGER: 'contracts_manager',
  PURCHASING_MANAGER: 'purchasing_manager',
} as const;

export const PARTY_LABELS: Record<string, string> = {
  creator: 'المنشئ',
  department_manager: 'مدير القسم',
  contracts_manager: 'مدير قسم العقود',
  purchasing_manager: 'مدير المشتريات',
};

/** ترتيب الأولوية: توريد ثم إيجار ثم تسويق ثم خدمات. */
export const ContractType = {
  SUPPLY: 'supply',
  RENT: 'rent',
  MARKETING: 'marketing',
  ADD_ITEM: 'add_item',
  OPEN_ACCOUNT: 'open_account',
} as const;
export const TYPE_ORDER: string[] = ['supply', 'rent', 'marketing', 'add_item', 'open_account'];

export const TYPE_LABELS: Record<string, string> = {
  supply: 'توريد',
  rent: 'إيجار أرفف',
  marketing: 'تسويق',
  add_item: 'خدمات — إضافة صنف جديد',
  open_account: 'خدمات — فتح حساب',
};

export const SERVICE_TYPES = ['add_item', 'open_account'];

export function sortTypes(types: string[]): string[] {
  return [...new Set(types)].sort((a, b) => TYPE_ORDER.indexOf(a) - TYPE_ORDER.indexOf(b));
}

export function primaryType(types: string[]): string {
  return sortTypes(types)[0] ?? '';
}

export function isServicesOnly(types: string[]): boolean {
  return types.length > 0 && types.every((t) => SERVICE_TYPES.includes(t));
}

export const AttachmentKind = {
  READY_SIGNED: 'ready_signed', // ملف العقد الجاهز الموقّع
  SIGNED: 'signed', // النسخة الموقعة بعد التوقيع
  ADDENDUM_SIGNED: 'addendum_signed',
  ACCOUNT_LICENSE: 'account_license',
  ACCOUNT_ADDRESS: 'account_address',
  ACCOUNT_REGISTRATION: 'account_registration',
  ACCOUNT_TAX: 'account_tax',
  ACCOUNT_OWNER_ID: 'account_owner_id',
  OTHER: 'other',
} as const;

export const ACCOUNT_DOC_KINDS = [
  'account_license',
  'account_address',
  'account_registration',
  'account_tax',
  'account_owner_id',
];

export const ATTACHMENT_LABELS: Record<string, string> = {
  ready_signed: 'العقد الجاهز الموقّع',
  signed: 'النسخة الموقعة',
  addendum_signed: 'الملحق الموقّع',
  account_license: 'رخصة النشاط التجاري',
  account_address: 'وثيقة العنوان الوطني',
  account_registration: 'شهادة تسجيل الشركة',
  account_tax: 'شهادة تسجيل الضريبة',
  account_owner_id: 'هوية المالك',
  other: 'مرفق آخر',
};

export const RefList = {
  BRANCH: 'branch',
  SHELF_TYPE: 'shelf_type',
  SERVICE_TYPE: 'service_type',
  EVENT: 'event',
} as const;

export const REF_LIST_LABELS: Record<string, string> = {
  branch: 'الفروع',
  shelf_type: 'أنواع الرفوف',
  service_type: 'أنواع الخدمات',
  event: 'الفعاليات التسويقية',
};

export const DEFAULT_REFS: Record<string, string[]> = {
  shelf_type: ['جندولة', 'ستاند', 'عرض أرضي'],
  service_type: ['إضافة صنف جديد', 'فتح حساب'],
  event: [
    'صورة في المجلة',
    'صفحة كاملة في المجلة',
    'افتتاح فرع جديد',
    'عيد الأضحى',
    'عيد الفطر',
    'يوم التأسيس',
    'اليوم الوطني',
    'رمضان',
    'العودة للمدارس',
  ],
};

export const DEFAULT_DEPARTMENTS = [
  { name: 'فريش', code: 'FRESH' },
  { name: 'غذائي', code: 'FOOD' },
  { name: 'لا غذائي', code: 'NONFOOD' },
];

/** Threshold: الصفحة الرقمية القابلة للتعديل من مسؤول النظام. */
export const SETTING_DEFAULTS = {
  reminder_days: '90,60,30',
  escalation_days: '7',
};

export const MAX_CONTRACT_MONTHS = 12;
export const MAX_FILE_BYTES = 10 * 1024 * 1024;

/** صلاحيات الدور (مصفوفة 3.2 في الوثيقة). */
export interface Caps {
  canViewAllDepartments: boolean; // يرى كل الأقسام (مع قراءة فقط لمدير القسم)
  operationsOnly: boolean;
  createAnyDepartment: boolean;
  pickDepartment: boolean; // يختار قسم العقد عند الإنشاء (مسؤول النظام ومدير المشتريات)
  canCreate: boolean;
  canRaiseAlert: boolean;
  canNoteOnAlert: boolean;
  canViewAudit: boolean;
  canManageUsers: boolean;
  canManageRefs: boolean;
  canManageTemplates: boolean;
  isPurchasingManager: boolean;
  isContractsManager: boolean;
  seesAccountDocs: boolean; // يرى مستندات فتح الحساب (القسم صاحب العقد دائماً)
  canSeeGoals: boolean;
}

export function capsOf(rawRole: string | null | undefined): Caps {
  const role = normalizeRole(rawRole);
  const is = (...r: string[]) => r.includes(role);
  return {
    canViewAllDepartments: is(Role.SYSTEM_ADMIN, Role.CONTRACTS_MANAGER, Role.CONTRACTS_OFFICER, Role.PURCHASING_MANAGER, Role.DEPARTMENT_MANAGER),
    operationsOnly: is(Role.OPERATIONS),
    createAnyDepartment: is(Role.SYSTEM_ADMIN),
    pickDepartment: is(Role.SYSTEM_ADMIN, Role.PURCHASING_MANAGER),
    canCreate: is(Role.SYSTEM_ADMIN, Role.PURCHASING_MANAGER, Role.DEPARTMENT_MANAGER, Role.DEPARTMENT_OFFICIAL),
    canRaiseAlert: is(Role.CONTRACTS_MANAGER, Role.CONTRACTS_OFFICER, Role.PURCHASING_MANAGER, Role.DEPARTMENT_MANAGER),
    canNoteOnAlert: is(Role.CONTRACTS_MANAGER, Role.CONTRACTS_OFFICER),
    canViewAudit: is(Role.SYSTEM_ADMIN, Role.CONTRACTS_MANAGER, Role.CONTRACTS_OFFICER, Role.PURCHASING_MANAGER, Role.DEPARTMENT_MANAGER),
    canManageUsers: is(Role.SYSTEM_ADMIN),
    canManageRefs: is(Role.SYSTEM_ADMIN),
    canManageTemplates: is(Role.CONTRACTS_MANAGER, Role.SYSTEM_ADMIN),
    isPurchasingManager: is(Role.PURCHASING_MANAGER),
    isContractsManager: is(Role.CONTRACTS_MANAGER),
    seesAccountDocs: is(Role.SYSTEM_ADMIN, Role.CONTRACTS_MANAGER, Role.CONTRACTS_OFFICER, Role.PURCHASING_MANAGER, Role.DEPARTMENT_MANAGER, Role.DEPARTMENT_OFFICIAL),
    canSeeGoals: !is(Role.DEPARTMENT_OFFICIAL, Role.OPERATIONS),
  };
}
