/** أشكال البيانات القادمة من الـ API (BRD v3.0). الحقول التفصيلية للعقد تبقى مرنة لأنها تتبع أنواع العقد. */

export type Role =
  | 'system_admin'
  | 'contracts_manager'
  | 'contracts_officer'
  | 'purchasing_manager'
  | 'department_manager'
  | 'department_official'
  | 'operations';

export type ContractStatus = 'draft' | 'under_review' | 'awaiting_signature' | 'active' | 'ended' | 'cancelled';
export type ContractType = 'supply' | 'rent' | 'marketing' | 'add_item' | 'open_account';

export interface Caps {
  canViewAllDepartments: boolean;
  operationsOnly: boolean;
  createAnyDepartment: boolean;
  canCreate: boolean;
  canRaiseAlert: boolean;
  canNoteOnAlert: boolean;
  canViewAudit: boolean;
  canManageUsers: boolean;
  canManageRefs: boolean;
  canManageTemplates: boolean;
  isPurchasingManager: boolean;
  isContractsManager: boolean;
  seesAccountDocs: boolean;
  canSeeGoals: boolean;
}

export interface SessionUser {
  id: number;
  username: string;
  fullName: string;
  role: Role;
  roleLabel: string;
  departmentId: number | null;
  departmentName: string | null;
  mustChangePassword: boolean;
  caps: Caps;
}

export interface ApiErrorBody {
  message?: string | string[];
  errors?: Record<string, string>;
  code?: string;
}

export interface Named {
  id: number;
  name: string;
}

export interface Options {
  departments: (Named & { code: string })[];
  branches: Named[];
  shelfTypes: Named[];
  serviceTypes: Named[];
  events: Named[];
  vendors: { id: number; vendorNumber: string; name: string }[];
  thresholds: { reminderDays: number[]; escalationDays: number };
}

export interface ContractRow {
  id: number;
  number: string | null;
  vendorId: number;
  vendorName: string;
  vendorNumber: string;
  types: ContractType[];
  typeLabels: string[];
  departmentId: number;
  departmentName: string;
  status: ContractStatus;
  statusLabel: string;
  party: string | null;
  partyLabel: string | null;
  startDate: string | null;
  endDate: string | null;
  daysRemaining: number | null;
  hasAlert: boolean;
  waitingDays: number;
  discountKinds: string[];
  createdByName: string;
  entryPath: 'new' | 'ready';
  awaitingUser: boolean;
}

export interface ContractAction {
  key: string;
  label: string;
  primary?: boolean;
  danger?: boolean;
  reason?: 'required' | 'optional';
}

export interface Notification {
  id: number;
  kind: string;
  text: string;
  contractId: number | null;
  contractNumber?: string | null;
  read: boolean;
  createdAt: string;
}

export interface GoalRow {
  id: number;
  fromDate: string;
  toDate: string;
  target: number;
  discountPct: number;
  net: number;
  achievedPct: number;
  status: string;
  statusLabel: string;
  applied: boolean;
  estimatedDiscount: number;
  contractId: number;
  number: string;
  vendorName: string;
  departmentName: string;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type Json = any;
