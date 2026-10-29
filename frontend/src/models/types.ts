/** أنواع البيانات القادمة من الـ API (مطابقة لـ NestJS). */

export type Role =
  | 'contracts_officer'
  | 'purchasing_officer'
  | 'purchasing_head'
  | 'contracts_manager'
  | 'legal'
  | 'admin';

export interface SessionUser {
  id: number;
  username: string;
  fullName: string;
  role: Role;
  roleLabel: string;
  jobTitle: string;
  departmentId: number | null;
  departmentName: string | null;
  isContractsStaff: boolean;
  isPurchasingStaff: boolean;
  canApproveAsHead: boolean;
  canApproveAsContractsManager: boolean;
  canLegalReview: boolean;
  canManageUsers: boolean;
}

export type ContractStatus =
  | 'draft'
  | 'under_review'
  | 'pending_approval'
  | 'pending_signature'
  | 'active'
  | 'expired'
  | 'cancelled';

export type ContractType = 'supply' | 'shelf_rent' | 'marketing' | 'services';
export type ContractSubtype = 'fresh' | 'food' | 'non_food' | 'account_opening';

export interface ContractRow {
  id: number;
  contractNumber: string;
  createdById: number;
  commercialOwnerId: number;
  vendorId: number;
  vendorName: string;
  vendorNumber: string;
  contractType: ContractType;
  contractTypeLabel: string;
  subtype: string;
  subtypeLabel: string;
  departmentId: number;
  departmentName: string;
  startDate: string;
  endDate: string;
  daysRemaining: number;
  status: ContractStatus;
  statusLabel: string;
  actualValue: number;
  hasNonStandardTerms: boolean;
  isHighValue: boolean;
  requiredApprover: string;
  responsible: string | null;
  waitingDays: number;
  awaitingUser: boolean;
  primaryAction: string | null;
  cancellationReason: string;
}

export interface ContractAction {
  key: string;
  label: string;
  primary: boolean;
}

export interface TargetRow {
  id: number;
  title: string;
  targetValue: number;
  discountPercent: number;
  estimatedDiscount: number;
}

export interface AttachmentRow {
  id: number;
  kind: string;
  kindLabel: string;
  originalName: string;
  uploadedAt: string | null;
  uploadedBy: string | null;
}

export interface ApprovalStepRow {
  id: number;
  role: string;
  roleLabel: string;
  sequence: number;
  decision: 'pending' | 'approved' | 'rejected';
  decidedAt: string | null;
  decidedBy: string | null;
  comment: string;
}

export interface AuditRow {
  id: number;
  action: string;
  fromStatus: string;
  toStatus: string;
  fromStatusLabel: string;
  toStatusLabel: string;
  details: Record<string, unknown>;
  createdAt: string | null;
  actorName: string | null;
  actorRole: string | null;
  contractId?: number;
  contractNumber?: string | null;
  actor?: string | null;
}

export interface AlertRow {
  id: number;
  alertType: string;
  severity: 'info' | 'warning' | 'critical';
  message: string;
  contractId: number;
  contractNumber: string;
  vendorName: string;
  createdAt: string | null;
}

export interface ContractDetail extends ContractRow {
  internalClassification: string;
  notes: string;
  commercialOwner: string | null;
  createdBy: string | null;
  purchasingManagerId: number | null;
  shelfRate: { id: number; name: string; standardPrice: number } | null;
  shelfActualPrice: number | null;
  shelfDeviationPercent: number;
  hasShelfPriceDeviation: boolean;
  isHighValue: boolean;
  lastNoticeDate: string | null;
  autoRenewal: boolean;
  bothPartiesSigned: boolean;
  signedAt: string | null;
  activatedAt: string | null;
  cancelledAt: string | null;
  targets: TargetRow[];
  attachments: AttachmentRow[];
  approvalSteps: ApprovalStepRow[];
  pendingApprover: string | null;
  auditLogs: AuditRow[];
  alerts: { id: number; alertType: string; severity: string; message: string; createdAt: string | null }[];
  actions: ContractAction[];
}

export interface ContractFormValues {
  contractType: ContractType;
  subtype: string;
  internalClassification: string;
  vendorId: number | '';
  departmentId: number | '';
  startDate: string;
  endDate: string;
  actualValue: string;
  hasNonStandardTerms: boolean;
  shelfRateId: number | '';
  shelfActualPrice: string;
  purchasingManagerId: number | '';
  notes: string;
  targets: { title: string; targetValue: string; discountPercent: string }[];
}

export interface Option {
  id: number;
  name: string;
}

export interface ReferenceOptions {
  vendors: { id: number; vendorNumber: string; name: string }[];
  departments: { id: number; name: string; code: string }[];
  shelfRates: { id: number; name: string; unit: string; standardPrice: number }[];
  users: { id: number; name: string; role: string; roleLabel: string }[];
}

export interface DashboardSummary {
  total: number;
  byStatus: { status: ContractStatus; label: string; count: number }[];
  byType: { type: ContractType; label: string; count: number }[];
  expiring: { days: number; count: number }[];
  awaiting: ContractRow[];
  nearest: { id: number; contractNumber: string; vendorName: string; endDate: string; daysRemaining: number; status: string }[];
  departments: { department: string; estimatedDiscount: number; targets: number }[];
  departmentsCount: number;
}

export interface GoalRow {
  id: number;
  contractNumber: string;
  vendorName: string;
  department: string;
  title: string;
  targetValue: number;
  discountPercent: number;
  estimatedDiscount: number;
  progressStatus: 'no_data' | 'achieved' | 'not_achieved' | 'in_progress';
}

export interface UserRow {
  id: number;
  username: string;
  firstName: string;
  lastName: string;
  fullName: string;
  role: Role;
  roleLabel: string;
  departmentId: number | null;
  departmentName: string | null;
  jobTitle: string;
  phone: string;
  isActive: boolean;
  lastLogin: string | null;
}

export interface Department {
  id: number;
  name: string;
  code: string;
  isActive: boolean;
}

export interface VendorRecord {
  id: number;
  vendorNumber: string;
  legalName: string;
  tradeName: string;
  crNumber: string;
  contactName: string;
  phone: string;
  email: string;
  isActive: boolean;
}

export interface ShelfRateRecord {
  id: number;
  code: string;
  name: string;
  unit: string;
  standardPrice: string;
  isActive: boolean;
}

/** شكل الأخطاء من الـ API: رسالة عامة + أخطاء الحقول إن وُجدت. */
export interface ApiErrorBody {
  message?: string | string[];
  errors?: Record<string, string>;
}
