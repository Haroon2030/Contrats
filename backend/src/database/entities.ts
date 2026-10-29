/**
 * كيانات TypeORM مطابقة تماماً لجداول Django الحالية (app_model).
 * لا نُنشئ أو نعدّل الجداول من هنا (synchronize = false) حتى يبقى الإنتاج كما هو.
 */
import { Column, Entity, JoinColumn, ManyToOne, OneToMany, OneToOne, PrimaryGeneratedColumn, Relation } from 'typeorm';

@Entity({ name: 'auth_user' })
export class UserEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ length: 128 }) password: string;
  @Column({ name: 'last_login', type: 'datetime', nullable: true }) lastLogin: Date | null;
  @Column({ name: 'is_superuser', type: 'boolean' }) isSuperuser: boolean;
  @Column({ length: 150 }) username: string;
  @Column({ name: 'first_name', length: 150 }) firstName: string;
  @Column({ name: 'last_name', length: 150 }) lastName: string;
  @Column({ length: 254 }) email: string;
  @Column({ name: 'is_staff', type: 'boolean' }) isStaff: boolean;
  @Column({ name: 'is_active', type: 'boolean' }) isActive: boolean;
  @Column({ name: 'date_joined', type: 'datetime' }) dateJoined: Date;

  @OneToOne(() => UserProfileEntity, (profile) => profile.user)
  profile: Relation<UserProfileEntity>;
}

@Entity({ name: 'accounts_department' })
export class DepartmentEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ length: 120 }) name: string;
  @Column({ length: 20 }) code: string;
  @Column({ name: 'is_active', type: 'boolean' }) isActive: boolean;
}

@Entity({ name: 'accounts_userprofile' })
export class UserProfileEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ length: 32 }) role: string;
  @Column({ name: 'job_title', length: 120 }) jobTitle: string;
  @Column({ length: 20 }) phone: string;

  @Column({ name: 'user_id' }) userId: number;
  @OneToOne(() => UserEntity, (user) => user.profile)
  @JoinColumn({ name: 'user_id' })
  user: Relation<UserEntity>;

  @Column({ name: 'department_id', nullable: true }) departmentId: number | null;
  @ManyToOne(() => DepartmentEntity, { nullable: true })
  @JoinColumn({ name: 'department_id' })
  department: Relation<DepartmentEntity> | null;
}

@Entity({ name: 'contracts_vendor' })
export class VendorEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ name: 'vendor_number', length: 30 }) vendorNumber: string;
  @Column({ name: 'legal_name', length: 200 }) legalName: string;
  @Column({ name: 'trade_name', length: 200 }) tradeName: string;
  @Column({ name: 'cr_number', length: 40 }) crNumber: string;
  @Column({ name: 'contact_name', length: 120 }) contactName: string;
  @Column({ length: 30 }) phone: string;
  @Column({ length: 254 }) email: string;
  @Column({ name: 'is_active', type: 'boolean' }) isActive: boolean;
}

@Entity({ name: 'contracts_shelfrate' })
export class ShelfRateEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ length: 40 }) code: string;
  @Column({ length: 120 }) name: string;
  @Column({ length: 40 }) unit: string;
  @Column({ name: 'standard_price', type: 'decimal', precision: 12, scale: 2 }) standardPrice: string;
  @Column({ name: 'is_active', type: 'boolean' }) isActive: boolean;
}

@Entity({ name: 'contracts_contract' })
export class ContractEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ name: 'contract_number', length: 40 }) contractNumber: string;
  @Column({ name: 'contract_type', length: 20 }) contractType: string;
  @Column({ length: 30 }) subtype: string;
  @Column({ name: 'internal_classification', length: 160 }) internalClassification: string;
  @Column({ name: 'start_date', type: 'date' }) startDate: string;
  @Column({ name: 'end_date', type: 'date' }) endDate: string;
  @Column({ name: 'auto_renewal', type: 'boolean' }) autoRenewal: boolean;
  @Column({ name: 'notice_period_days', type: 'int' }) noticePeriodDays: number;
  @Column({ name: 'actual_value', type: 'decimal', precision: 14, scale: 2 }) actualValue: string;
  @Column({ name: 'has_non_standard_terms', type: 'boolean' }) hasNonStandardTerms: boolean;
  @Column({ length: 24 }) status: string;
  @Column({ name: 'both_parties_signed', type: 'boolean' }) bothPartiesSigned: boolean;
  @Column({ name: 'signed_at', type: 'datetime', nullable: true }) signedAt: Date | null;
  @Column({ name: 'activated_at', type: 'datetime', nullable: true }) activatedAt: Date | null;
  @Column({ name: 'cancelled_at', type: 'datetime', nullable: true }) cancelledAt: Date | null;
  @Column({ name: 'cancellation_reason', type: 'text' }) cancellationReason: string;
  @Column({ type: 'text' }) notes: string;
  @Column({ name: 'shelf_actual_price', type: 'decimal', precision: 12, scale: 2, nullable: true })
  shelfActualPrice: string | null;
  @Column({ name: 'manually_escalated', type: 'boolean' }) manuallyEscalated: boolean;
  @Column({ name: 'created_at', type: 'datetime' }) createdAt: Date;
  @Column({ name: 'updated_at', type: 'datetime' }) updatedAt: Date;

  @Column({ name: 'vendor_id' }) vendorId: number;
  @ManyToOne(() => VendorEntity) @JoinColumn({ name: 'vendor_id' }) vendor: Relation<VendorEntity>;

  @Column({ name: 'department_id' }) departmentId: number;
  @ManyToOne(() => DepartmentEntity) @JoinColumn({ name: 'department_id' }) department: Relation<DepartmentEntity>;

  @Column({ name: 'commercial_owner_id' }) commercialOwnerId: number;
  @ManyToOne(() => UserEntity) @JoinColumn({ name: 'commercial_owner_id' }) commercialOwner: Relation<UserEntity>;

  @Column({ name: 'purchasing_manager_id', nullable: true }) purchasingManagerId: number | null;
  @ManyToOne(() => UserEntity, { nullable: true })
  @JoinColumn({ name: 'purchasing_manager_id' })
  purchasingManager: Relation<UserEntity> | null;

  @Column({ name: 'created_by_id' }) createdById: number;
  @ManyToOne(() => UserEntity) @JoinColumn({ name: 'created_by_id' }) createdBy: Relation<UserEntity>;

  @Column({ name: 'shelf_rate_id', nullable: true }) shelfRateId: number | null;
  @ManyToOne(() => ShelfRateEntity, { nullable: true })
  @JoinColumn({ name: 'shelf_rate_id' })
  shelfRate: Relation<ShelfRateEntity> | null;

  @OneToMany(() => ContractTargetEntity, (t) => t.contract) targets: ContractTargetEntity[];
  @OneToMany(() => ContractAttachmentEntity, (a) => a.contract) attachments: ContractAttachmentEntity[];
  @OneToMany(() => ApprovalStepEntity, (s) => s.contract) approvalSteps: ApprovalStepEntity[];
  @OneToMany(() => AuditLogEntity, (l) => l.contract) auditLogs: AuditLogEntity[];
  @OneToMany(() => ContractAlertEntity, (a) => a.contract) alerts: ContractAlertEntity[];
}

@Entity({ name: 'contracts_contracttarget' })
export class ContractTargetEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ length: 200 }) title: string;
  @Column({ name: 'target_value', type: 'decimal', precision: 14, scale: 2 }) targetValue: string;
  @Column({ name: 'discount_percent', type: 'decimal', precision: 5, scale: 2 }) discountPercent: string;
  @Column({ name: 'sort_order', type: 'int' }) sortOrder: number;
  @Column({ name: 'contract_id' }) contractId: number;
  @ManyToOne(() => ContractEntity, (c) => c.targets) @JoinColumn({ name: 'contract_id' }) contract: Relation<ContractEntity>;
}

@Entity({ name: 'contracts_contractattachment' })
export class ContractAttachmentEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ length: 24 }) kind: string;
  @Column({ length: 100 }) file: string;
  @Column({ name: 'original_name', length: 255 }) originalName: string;
  @Column({ name: 'uploaded_at', type: 'datetime' }) uploadedAt: Date;
  @Column({ name: 'contract_id' }) contractId: number;
  @ManyToOne(() => ContractEntity, (c) => c.attachments) @JoinColumn({ name: 'contract_id' }) contract: Relation<ContractEntity>;
  @Column({ name: 'uploaded_by_id' }) uploadedById: number;
  @ManyToOne(() => UserEntity) @JoinColumn({ name: 'uploaded_by_id' }) uploadedBy: Relation<UserEntity>;
}

@Entity({ name: 'contracts_approvalstep' })
export class ApprovalStepEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ length: 32 }) role: string;
  @Column({ type: 'int' }) sequence: number;
  @Column({ length: 16 }) decision: string;
  @Column({ name: 'decided_at', type: 'datetime', nullable: true }) decidedAt: Date | null;
  @Column({ type: 'text' }) comment: string;
  @Column({ name: 'contract_id' }) contractId: number;
  @ManyToOne(() => ContractEntity, (c) => c.approvalSteps) @JoinColumn({ name: 'contract_id' }) contract: Relation<ContractEntity>;
  @Column({ name: 'decided_by_id', nullable: true }) decidedById: number | null;
  @ManyToOne(() => UserEntity, { nullable: true }) @JoinColumn({ name: 'decided_by_id' }) decidedBy: Relation<UserEntity> | null;
}

@Entity({ name: 'contracts_auditlog' })
export class AuditLogEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ length: 80 }) action: string;
  @Column({ name: 'from_status', length: 24 }) fromStatus: string;
  @Column({ name: 'to_status', length: 24 }) toStatus: string;
  @Column({ type: 'text' }) details: string; // JSON مخزّن كنص
  @Column({ name: 'created_at', type: 'datetime' }) createdAt: Date;
  @Column({ name: 'contract_id' }) contractId: number;
  @ManyToOne(() => ContractEntity, (c) => c.auditLogs) @JoinColumn({ name: 'contract_id' }) contract: Relation<ContractEntity>;
  @Column({ name: 'actor_id' }) actorId: number;
  @ManyToOne(() => UserEntity) @JoinColumn({ name: 'actor_id' }) actor: Relation<UserEntity>;
}

@Entity({ name: 'contracts_contractalert' })
export class ContractAlertEntity {
  @PrimaryGeneratedColumn() id: number;
  @Column({ name: 'alert_type', length: 32 }) alertType: string;
  @Column({ length: 12 }) severity: string;
  @Column({ length: 255 }) message: string;
  @Column({ name: 'is_open', type: 'boolean' }) isOpen: boolean;
  @Column({ name: 'created_at', type: 'datetime' }) createdAt: Date;
  @Column({ name: 'resolved_at', type: 'datetime', nullable: true }) resolvedAt: Date | null;
  @Column({ name: 'contract_id' }) contractId: number;
  @ManyToOne(() => ContractEntity, (c) => c.alerts) @JoinColumn({ name: 'contract_id' }) contract: Relation<ContractEntity>;
}

export const ENTITIES = [
  UserEntity,
  DepartmentEntity,
  UserProfileEntity,
  VendorEntity,
  ShelfRateEntity,
  ContractEntity,
  ContractTargetEntity,
  ContractAttachmentEntity,
  ApprovalStepEntity,
  AuditLogEntity,
  ContractAlertEntity,
];
