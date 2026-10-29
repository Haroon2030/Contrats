/**
 * ثوابت النطاق — مطابقة لقيم Django في contracts/models.py و accounts/models.py.
 */

export const Role = {
  CONTRACTS_OFFICER: 'contracts_officer',
  PURCHASING_OFFICER: 'purchasing_officer',
  PURCHASING_HEAD: 'purchasing_head',
  CONTRACTS_MANAGER: 'contracts_manager',
  LEGAL: 'legal',
  ADMIN: 'admin',
} as const;
export type Role = (typeof Role)[keyof typeof Role];
export const ROLES = Object.values(Role);

export const ContractType = {
  SUPPLY: 'supply',
  SHELF_RENT: 'shelf_rent',
  MARKETING: 'marketing',
  SERVICES: 'services',
} as const;

export const ContractSubtype = {
  FRESH: 'fresh',
  FOOD: 'food',
  NON_FOOD: 'non_food',
  ACCOUNT_OPENING: 'account_opening',
} as const;

export const ContractStatus = {
  DRAFT: 'draft',
  UNDER_REVIEW: 'under_review',
  PENDING_APPROVAL: 'pending_approval',
  PENDING_SIGNATURE: 'pending_signature',
  ACTIVE: 'active',
  EXPIRED: 'expired',
  CANCELLED: 'cancelled',
} as const;

export const ApprovalDecision = {
  PENDING: 'pending',
  APPROVED: 'approved',
  REJECTED: 'rejected',
} as const;

export const ApproverRole = {
  DEPARTMENT_HEAD: 'department_head',
  CONTRACTS_MANAGER: 'contracts_manager',
  LEGAL: 'legal',
} as const;

export const AttachmentKind = {
  ORIGINAL: 'original',
  LEGAL_REVIEW: 'legal_review',
  SIGNED: 'signed',
  ADDENDUM: 'addendum',
  RENEWAL_LETTER: 'renewal_letter',
  TERMINATION_LETTER: 'termination_letter',
  OTHER: 'other',
} as const;

export const AlertType = {
  EXPIRY_90: 'expiry_90',
  EXPIRY_60: 'expiry_60',
  EXPIRY_30: 'expiry_30',
  RENEWAL_NOTICE: 'renewal_notice',
  UNSIGNED_APPROVED: 'unsigned_approved',
  EXPIRED_STILL_ACTIVE: 'expired_still_active',
  SHELF_PRICE_DEVIATION: 'shelf_price_deviation',
} as const;

export const AlertSeverity = {
  INFO: 'info',
  WARNING: 'warning',
  CRITICAL: 'critical',
} as const;

export const HIGH_VALUE_THRESHOLD = 15000;

/** مدة العقد القصوى سنة واحدة، ولا تجديد تلقائي (المواصفة). */
export const MAX_CONTRACT_DAYS = 365;

/** مسارات الحالات المسموحة — مطابقة لـ ALLOWED_TRANSITIONS في Django. */
export const ALLOWED_TRANSITIONS: Record<string, string[]> = {
  draft: ['under_review', 'pending_approval', 'cancelled'],
  under_review: ['pending_approval', 'draft', 'cancelled'],
  pending_approval: ['pending_signature', 'draft', 'cancelled'],
  pending_signature: ['active', 'cancelled'],
  active: ['expired', 'cancelled'],
  expired: [],
  cancelled: [],
};

export const STATUS_LABELS: Record<string, string> = {
  draft: 'مسودة',
  under_review: 'تحت المراجعة',
  pending_approval: 'بانتظار الموافقة',
  pending_signature: 'بانتظار التوقيع',
  active: 'نشط',
  expired: 'منتهي',
  cancelled: 'ملغى',
};

export const TYPE_LABELS: Record<string, string> = {
  supply: 'توريد',
  shelf_rent: 'إيجار أرفف',
  marketing: 'تسويق',
  services: 'خدمات',
};

export const SUBTYPE_LABELS: Record<string, string> = {
  fresh: 'فريش',
  food: 'غذائي',
  non_food: 'لا غذائي',
  account_opening: 'فتح حساب',
};

export const ROLE_LABELS: Record<string, string> = {
  contracts_officer: 'موظف قسم العقود',
  purchasing_officer: 'موظف المشتريات',
  purchasing_head: 'رئيس قسم المشتريات',
  contracts_manager: 'مدير إدارة العقود',
  legal: 'الشؤون القانونية',
  admin: 'مدير النظام',
};

/**
 * صلاحيات الدور — مطابقة لخصائص UserProfile في Django.
 */
export interface RoleFlags {
  isContractsStaff: boolean;
  isPurchasingStaff: boolean;
  canApproveAsHead: boolean;
  canApproveAsContractsManager: boolean;
  canLegalReview: boolean;
  canManageUsers: boolean;
}

export function roleFlags(role: string | null | undefined): RoleFlags {
  const r = role ?? '';
  const is = (...roles: Role[]) => (roles as string[]).includes(r);
  return {
    isContractsStaff: is(Role.CONTRACTS_OFFICER, Role.CONTRACTS_MANAGER, Role.ADMIN),
    isPurchasingStaff: is(Role.PURCHASING_OFFICER, Role.PURCHASING_HEAD, Role.ADMIN),
    canApproveAsHead: is(Role.PURCHASING_HEAD, Role.ADMIN),
    canApproveAsContractsManager: is(Role.CONTRACTS_MANAGER, Role.ADMIN),
    canLegalReview: is(Role.LEGAL, Role.ADMIN),
    canManageUsers: r === Role.ADMIN,
  };
}

export function requiredApproverRole(c: {
  hasNonStandardTerms: boolean;
  manuallyEscalated: boolean;
  contractType: string;
  actualValue: number;
  shelfRateStandardPrice?: number | null;
  shelfActualPrice?: number | null;
}): string {
  const hasShelfDeviation =
    c.contractType === ContractType.SHELF_RENT &&
    c.shelfActualPrice != null &&
    c.shelfRateStandardPrice != null &&
    c.shelfActualPrice !== c.shelfRateStandardPrice;
  const standardShelfRent = c.contractType === ContractType.SHELF_RENT && !hasShelfDeviation && !c.hasNonStandardTerms;

  if (c.hasNonStandardTerms || c.manuallyEscalated) return ApproverRole.CONTRACTS_MANAGER;
  if (standardShelfRent) return ApproverRole.DEPARTMENT_HEAD;
  if (c.actualValue > HIGH_VALUE_THRESHOLD) return ApproverRole.CONTRACTS_MANAGER;
  return ApproverRole.DEPARTMENT_HEAD;
}
