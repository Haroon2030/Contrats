/**
 * كيانات قاعدة البيانات (BRD v3.0).
 * الجداول تُنشأ عند أول تشغيل إن فُعّل DB_SYNC=1 (انظر database.module.ts).
 */
import { Column, Entity, Index, PrimaryGeneratedColumn, PrimaryColumn } from 'typeorm';

@Entity({ name: 'users' })
export class UserEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index({ unique: true }) @Column({ length: 150 }) username: string;
  @Column({ name: 'password_hash', length: 255 }) passwordHash: string;
  @Column({ name: 'full_name', length: 150 }) fullName: string;
  @Column({ length: 190, nullable: true, type: 'varchar' }) email: string | null;
  @Column({ length: 32 }) role: string;
  @Column({ name: 'department_id', type: 'int', nullable: true }) departmentId: number | null;
  @Column({ name: 'is_active', type: 'boolean', default: true }) isActive: boolean;
  @Column({ name: 'must_change_password', type: 'boolean', default: false }) mustChangePassword: boolean;
  @Column({ name: 'failed_attempts', type: 'int', default: 0 }) failedAttempts: number;
  @Column({ name: 'locked_until', type: 'datetime', nullable: true }) lockedUntil: Date | null;
  @Column({ name: 'last_login_at', type: 'datetime', nullable: true }) lastLoginAt: Date | null;
  @Column({ name: 'created_at', type: 'datetime' }) createdAt: Date;
}

@Entity({ name: 'departments' })
export class DepartmentEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index({ unique: true }) @Column({ length: 120 }) name: string;
  @Column({ length: 20 }) code: string;
  @Column({ name: 'is_active', type: 'boolean', default: true }) isActive: boolean;
}

/** قوائم مرجعية: الفروع، أنواع الرفوف، أنواع الخدمات، الفعاليات. */
@Entity({ name: 'ref_values' })
@Index(['list', 'name'], { unique: true })
export class RefValueEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ length: 30 }) list: string;
  @Column({ length: 150 }) name: string;
  @Column({ name: 'is_active', type: 'boolean', default: true }) isActive: boolean;
}

@Entity({ name: 'settings' })
export class SettingEntity {
  @PrimaryColumn({ name: 'setting_key', length: 60 }) key: string;
  @Column({ type: 'text' }) value: string;
}

@Entity({ name: 'vendors' })
export class VendorEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index({ unique: true }) @Column({ name: 'vendor_number', length: 30 }) vendorNumber: string;
  @Column({ length: 200 }) name: string;
  // إلزامية عند الإضافة اليدوية؛ تبقى قابلة للفراغ للموردين المستوردين من النظام الحالي
  @Column({ name: 'commercial_register', type: 'varchar', length: 30, nullable: true }) commercialRegister: string | null;
  @Column({ name: 'tax_number', type: 'varchar', length: 30, nullable: true }) taxNumber: string | null;
  @Column({ type: 'varchar', length: 20, nullable: true }) mobile: string | null;
  @Column({ type: 'varchar', length: 150, nullable: true }) email: string | null;
  @Column({ name: 'is_active', type: 'boolean', default: true }) isActive: boolean;
}

@Entity({ name: 'contracts' })
@Index(['status', 'party'])
export class ContractEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index({ unique: true }) @Column({ type: 'varchar', length: 40, nullable: true }) number: string | null;
  @Column({ name: 'vendor_id', type: 'int' }) vendorId: number;
  @Column({ name: 'department_id', type: 'int' }) departmentId: number;
  @Column({ name: 'created_by_id', type: 'int' }) createdById: number;
  @Column({ name: 'entry_path', length: 10, default: 'new' }) entryPath: string; // new | ready
  @Column({ type: 'simple-array' }) types: string[];
  @Column({ name: 'primary_type', length: 20 }) primaryType: string;
  @Column({ length: 24, default: 'draft' }) status: string;
  @Column({ type: 'varchar', length: 30, nullable: true }) party: string | null;
  @Column({ name: 'state_entered_at', type: 'datetime' }) stateEnteredAt: Date;
  @Column({ name: 'rep_name', length: 150 }) repName: string;
  @Column({ name: 'rep_title', length: 150 }) repTitle: string;
  @Column({ name: 'rep_mobile', length: 20 }) repMobile: string;
  @Column({ name: 'start_date', type: 'date', nullable: true }) startDate: string | null;
  @Column({ name: 'end_date', type: 'date', nullable: true }) endDate: string | null;
  @Column({ name: 'payment_days', type: 'int', nullable: true }) paymentDays: number | null;
  @Column({ name: 'settlement_discount_pct', type: 'decimal', precision: 5, scale: 2, nullable: true }) settlementDiscountPct: string | null;
  @Column({ name: 'fixed_discount_pct', type: 'decimal', precision: 5, scale: 2, nullable: true }) fixedDiscountPct: string | null;
  @Column({ name: 'new_item_price', type: 'decimal', precision: 14, scale: 2, nullable: true }) newItemPrice: string | null;
  @Column({ name: 'credit_limit', type: 'decimal', precision: 14, scale: 2, nullable: true }) creditLimit: string | null;
  @Column({ name: 'special_terms', type: 'text' }) specialTerms: string;
  @Column({ name: 'has_alert', type: 'boolean', default: false }) hasAlert: boolean;
  @Column({ name: 'alert_kind', type: 'varchar', length: 10, nullable: true }) alertKind: string | null; // auto | manual
  @Column({ name: 'alert_reason', type: 'text' }) alertReason: string;
  @Column({ name: 'reject_reason', type: 'text' }) rejectReason: string;
  @Column({ name: 'close_reason', type: 'text' }) closeReason: string;
  @Column({ name: 'cancel_reason', type: 'text' }) cancelReason: string;
  @Column({ name: 'term_versions', type: 'simple-json', nullable: true }) termVersions: number[] | null;
  @Column({ name: 'reminders_sent', type: 'simple-json', nullable: true }) remindersSent: number[] | null;
  @Column({ name: 'last_escalated_at', type: 'datetime', nullable: true }) lastEscalatedAt: Date | null;
  @Column({ name: 'escalation_days', type: 'int', default: 7 }) escalationDays: number;
  @Column({ name: 'reminder_days', type: 'simple-json', nullable: true }) reminderDays: number[] | null;
  @Column({ name: 'addendum_count', type: 'int', default: 0 }) addendumCount: number;
  @Column({ name: 'sent_at', type: 'datetime', nullable: true }) sentAt: Date | null;
  @Column({ name: 'activated_at', type: 'datetime', nullable: true }) activatedAt: Date | null;
  @Column({ name: 'ended_at', type: 'datetime', nullable: true }) endedAt: Date | null;
  @Column({ name: 'cancelled_at', type: 'datetime', nullable: true }) cancelledAt: Date | null;
  @Column({ name: 'created_at', type: 'datetime' }) createdAt: Date;
  @Column({ name: 'updated_at', type: 'datetime' }) updatedAt: Date;
}

/** سطور هدف التوريد. كل ملحق قد يستبدلها: version = رقم الملحق الذي أنشأها. */
@Entity({ name: 'goal_lines' })
export class GoalLineEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index() @Column({ name: 'contract_id', type: 'int' }) contractId: number;
  @Column({ name: 'from_date', type: 'date' }) fromDate: string;
  @Column({ name: 'to_date', type: 'date' }) toDate: string;
  @Column({ type: 'decimal', precision: 16, scale: 2 }) target: string;
  @Column({ name: 'discount_pct', type: 'decimal', precision: 5, scale: 2 }) discountPct: string;
  @Column({ type: 'int', default: 0 }) version: number;
  @Column({ name: 'active_from', type: 'date', nullable: true }) activeFrom: string | null;
  @Column({ name: 'active_to', type: 'date', nullable: true }) activeTo: string | null;
  @Column({ type: 'int', default: 0 }) sort: number;
  @Column({ name: 'fixed_status', type: 'varchar', length: 20, nullable: true }) fixedStatus: string | null;
  @Column({ name: 'fixed_net', type: 'decimal', precision: 16, scale: 2, nullable: true }) fixedNet: string | null;
  @Column({ name: 'fixed_discount', type: 'decimal', precision: 16, scale: 2, nullable: true }) fixedDiscount: string | null;
}

@Entity({ name: 'rent_lines' })
export class RentLineEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index() @Column({ name: 'contract_id', type: 'int' }) contractId: number;
  @Column({ name: 'branch_id', type: 'int' }) branchId: number;
  @Column({ name: 'shelf_type_id', type: 'int' }) shelfTypeId: number;
  @Column({ type: 'int' }) qty: number;
  @Column({ type: 'decimal', precision: 14, scale: 2 }) price: string;
  @Column({ length: 10 }) unit: string; // week | month
  @Column({ name: 'start_date', type: 'date' }) startDate: string;
  @Column({ name: 'end_date', type: 'date' }) endDate: string;
  @Column({ type: 'int' }) periods: number;
  @Column({ type: 'decimal', precision: 16, scale: 2 }) total: string;
}

@Entity({ name: 'marketing_lines' })
export class MarketingLineEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index() @Column({ name: 'contract_id', type: 'int' }) contractId: number;
  @Column({ name: 'event_id', type: 'int' }) eventId: number;
  @Column({ type: 'decimal', precision: 14, scale: 2 }) price: string;
  @Column({ type: 'text' }) note: string;
}

@Entity({ name: 'attachments' })
export class AttachmentEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index() @Column({ name: 'contract_id', type: 'int' }) contractId: number;
  @Column({ name: 'addendum_id', type: 'int', nullable: true }) addendumId: number | null;
  @Column({ length: 30 }) kind: string;
  @Column({ name: 'file_name', length: 255 }) fileName: string;
  @Column({ length: 255 }) path: string;
  @Column({ type: 'int' }) size: number;
  @Column({ name: 'uploaded_by_id', type: 'int' }) uploadedById: number;
  @Column({ name: 'uploaded_at', type: 'datetime' }) uploadedAt: Date;
}

@Entity({ name: 'addenda' })
export class AddendumEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index() @Column({ name: 'contract_id', type: 'int' }) contractId: number;
  @Column({ type: 'int' }) no: number;
  @Column({ name: 'effective_date', type: 'date' }) effectiveDate: string;
  @Column({ type: 'text' }) description: string;
  /** القيم الجديدة المقترحة والقديمة: { before: {...}, after: {...} } */
  @Column({ type: 'simple-json' }) changes: Record<string, unknown>;
  @Column({ length: 20, default: 'pending' }) status: string; // pending | applied | returned
  @Column({ name: 'return_reason', type: 'text' }) returnReason: string;
  @Column({ name: 'created_by_id', type: 'int' }) createdById: number;
  @Column({ name: 'created_at', type: 'datetime' }) createdAt: Date;
  @Column({ name: 'decided_by_id', type: 'int', nullable: true }) decidedById: number | null;
  @Column({ name: 'decided_at', type: 'datetime', nullable: true }) decidedAt: Date | null;
}

/** زمن دخول العقد كل حالة وكل جهة حالية (لحساب مدة البقاء والتصعيد). */
@Entity({ name: 'contract_stays' })
export class ContractStayEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index() @Column({ name: 'contract_id', type: 'int' }) contractId: number;
  @Column({ length: 24 }) state: string;
  @Column({ type: 'varchar', length: 30, nullable: true }) party: string | null;
  @Column({ name: 'entered_at', type: 'datetime' }) enteredAt: Date;
  @Column({ name: 'left_at', type: 'datetime', nullable: true }) leftAt: Date | null;
}

/** سجل التدقيق: لا يُعدَّل ولا يُحذف عبر أي واجهة. */
@Entity({ name: 'audit_log' })
export class AuditLogEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index() @Column({ name: 'contract_id', type: 'int', nullable: true }) contractId: number | null;
  @Column({ name: 'user_id', type: 'int', nullable: true }) userId: number | null;
  @Column({ name: 'user_name', length: 150 }) userName: string;
  @Column({ name: 'user_role', length: 32 }) userRole: string;
  @Column({ length: 100 }) action: string;
  @Column({ length: 40 }) entity: string;
  @Column({ type: 'simple-json', nullable: true }) before: unknown;
  @Column({ type: 'simple-json', nullable: true }) after: unknown;
  @Column({ type: 'text' }) note: string;
  @Index() @Column({ name: 'created_at', type: 'datetime' }) createdAt: Date;
}

@Entity({ name: 'notifications' })
export class NotificationEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index() @Column({ name: 'user_id', type: 'int' }) userId: number;
  @Column({ name: 'contract_id', type: 'int', nullable: true }) contractId: number | null;
  @Column({ length: 40 }) kind: string;
  @Column({ length: 500 }) text: string;
  @Column({ name: 'read_at', type: 'datetime', nullable: true }) readAt: Date | null;
  @Column({ name: 'email_to', type: 'varchar', length: 190, nullable: true }) emailTo: string | null;
  @Column({ name: 'email_status', type: 'varchar', length: 12, nullable: true }) emailStatus: string | null;
  @Column({ name: 'created_at', type: 'datetime' }) createdAt: Date;
}

@Entity({ name: 'term_clauses' })
export class TermClauseEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ length: 12, default: 'active' }) status: string; // active | stopped
  @Column({ name: 'current_version', type: 'int', default: 1 }) currentVersion: number;
  @Column({ name: 'created_at', type: 'datetime' }) createdAt: Date;
}

@Entity({ name: 'term_clause_versions' })
export class TermClauseVersionEntity {
  @PrimaryGeneratedColumn() id: number;
  @Index() @Column({ name: 'clause_id', type: 'int' }) clauseId: number;
  @Column({ type: 'int' }) version: number;
  @Column({ length: 200 }) title: string;
  @Column({ type: 'text' }) body: string;
  @Column({ name: 'applies_to', type: 'simple-array' }) appliesTo: string[];
  @Column({ type: 'int', default: 0 }) sort: number;
  @Column({ length: 12, default: 'active' }) status: string;
  @Column({ name: 'created_by_id', type: 'int' }) createdById: number;
  @Column({ name: 'created_at', type: 'datetime' }) createdAt: Date;
}

@Entity({ name: 'doc_templates' })
export class DocTemplateEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ type: 'int' }) version: number;
  @Column({ name: 'logo_path', type: 'varchar', length: 255, nullable: true }) logoPath: string | null;
  @Column({ name: 'background_path', type: 'varchar', length: 255, nullable: true }) backgroundPath: string | null;
  @Column({ name: 'header_text', type: 'text' }) headerText: string;
  @Column({ name: 'footer_text', type: 'text' }) footerText: string;
  @Column({ name: 'signature_layout', type: 'simple-json' }) signatureLayout: Record<string, unknown>;
  @Column({ name: 'created_by_id', type: 'int' }) createdById: number;
  @Column({ name: 'created_at', type: 'datetime' }) createdAt: Date;
}

/** صافي المشتريات الوارد من النظام الحالي (سجل يومي لكل مورد). */
@Entity({ name: 'purchases' })
@Index(['vendorNumber', 'date'])
export class PurchaseEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ name: 'vendor_number', length: 30 }) vendorNumber: string;
  @Column({ type: 'date' }) date: string;
  @Column({ type: 'decimal', precision: 16, scale: 2 }) purchases: string;
  @Column({ name: 'returns_amount', type: 'decimal', precision: 16, scale: 2, default: 0 }) returns: string;
  @Column({ type: 'varchar', length: 100, nullable: true }) department: string | null;
  @Column({ name: 'imported_at', type: 'datetime' }) importedAt: Date;
}

export const ENTITIES = [
  UserEntity,
  DepartmentEntity,
  RefValueEntity,
  SettingEntity,
  VendorEntity,
  ContractEntity,
  GoalLineEntity,
  RentLineEntity,
  MarketingLineEntity,
  AttachmentEntity,
  AddendumEntity,
  ContractStayEntity,
  AuditLogEntity,
  NotificationEntity,
  TermClauseEntity,
  TermClauseVersionEntity,
  DocTemplateEntity,
  PurchaseEntity,
];
