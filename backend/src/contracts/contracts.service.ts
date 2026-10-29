import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
  UnprocessableEntityException,
} from '@nestjs/common';
import { DataSource, EntityManager, In } from 'typeorm';
import { AuthUser } from '../auth/auth.service';
import {
  ALLOWED_TRANSITIONS,
  ApprovalDecision,
  ApproverRole,
  AttachmentKind,
  AlertSeverity,
  AlertType,
  ContractStatus,
  ContractSubtype,
  ContractType,
  MAX_CONTRACT_DAYS,
  STATUS_LABELS,
  SUBTYPE_LABELS,
  TYPE_LABELS,
  requiredApproverRole,
} from '../common/domain';
import { addDays, daysBetween, daysSince, isoDateTime, todayIso } from '../common/dates';
import {
  ApprovalStepEntity,
  AuditLogEntity,
  ContractAlertEntity,
  ContractAttachmentEntity,
  ContractEntity,
  ContractTargetEntity,
  DepartmentEntity,
  ShelfRateEntity,
  UserEntity,
  VendorEntity,
} from '../database/entities';
import { AttachmentDto, ContractDto, ContractQueryDto, DecisionDto, TargetDto } from './dto';
import { AlertsService } from '../alerts/alerts.service';
import { AttachmentStorage } from './attachment.storage';

const APPROVER_LABELS: Record<string, string> = {
  department_head: 'رئيس القسم',
  contracts_manager: 'إدارة العقود',
  legal: 'الشؤون القانونية',
};

const num = (v: string | number | null | undefined) => (v == null ? null : Number(v));

export interface ContractAction {
  key: string;
  label: string;
  primary: boolean;
}

/** أخطاء الإجراءات تُعاد برسائل عربية واضحة (تُعرض بجانب الحقل أو في الشريط). */
export class WorkflowError extends UnprocessableEntityException {
  constructor(message: string) {
    super(message);
  }
}

@Injectable()
export class ContractsService {
  constructor(
    private readonly ds: DataSource,
    private readonly alerts: AlertsService,
    private readonly storage: AttachmentStorage,
  ) {}

  // ───────────────────────── صلاحيات ─────────────────────────

  private requireContractsStaff(user: AuthUser, message = 'هذا الإجراء مقصور على قسم العقود.') {
    if (!user.isContractsStaff) throw new ForbiddenException(message);
  }

  private isClosed(c: ContractEntity) {
    return c.status === ContractStatus.CANCELLED || c.status === ContractStatus.EXPIRED;
  }

  // ───────────────────────── قراءة ─────────────────────────

  async list(user: AuthUser, query: ContractQueryDto) {
    const qb = this.ds
      .getRepository(ContractEntity)
      .createQueryBuilder('c')
      .leftJoinAndSelect('c.vendor', 'v')
      .leftJoinAndSelect('c.department', 'd')
      .leftJoinAndSelect('c.approvalSteps', 's', "s.decision = 'pending'")
      .orderBy('c.id', 'DESC');

    if (query.status) qb.andWhere('c.status = :status', { status: query.status });
    if (query.contractType) qb.andWhere('c.contractType = :type', { type: query.contractType });
    if (query.departmentId) qb.andWhere('c.departmentId = :dep', { dep: query.departmentId });
    if (query.q?.trim()) {
      qb.andWhere('(c.contractNumber LIKE :q OR v.legalName LIKE :q OR v.vendorNumber LIKE :q)', {
        q: `%${query.q.trim()}%`,
      });
    }
    if (query.expiringWithin) {
      const today = todayIso();
      const limit = addDaysIso(today, Number(query.expiringWithin));
      qb.andWhere('c.endDate BETWEEN :today AND :limit', { today, limit })
        .andWhere('c.status NOT IN (:...closed)', { closed: [ContractStatus.CANCELLED, ContractStatus.EXPIRED] });
    }

    const contracts = await qb.getMany();
    const lastActivity = await this.lastActivityMap(contracts.map((c) => c.id));
    const kinds = await this.attachmentKindMap(contracts.map((c) => c.id));

    let rows = contracts.map((c) => this.toRow(c, user, kinds.get(c.id) ?? new Set(), lastActivity.get(c.id)));
    if (query.view === 'awaiting') rows = rows.filter((r) => r.awaitingUser);
    if (query.view === 'mine') rows = rows.filter((r) => r.createdById === user.id || r.commercialOwnerId === user.id);
    return rows;
  }

  async detail(id: number, user: AuthUser) {
    const c = await this.findFull(id);
    const kinds = new Set(c.attachments.map((a) => a.kind));
    const pending = this.pendingStep(c);
    const lastActivity = await this.lastActivityMap([c.id]);
    const row = this.toRow(c, user, kinds, lastActivity.get(c.id));

    return {
      ...row,
      internalClassification: c.internalClassification,
      notes: c.notes,
      cancellationReason: c.cancellationReason,
      commercialOwner: c.commercialOwner ? this.userName(c.commercialOwner) : null,
      createdBy: c.createdBy ? this.userName(c.createdBy) : null,
      purchasingManagerId: c.purchasingManagerId,
      shelfRate: c.shelfRate ? { id: c.shelfRate.id, name: c.shelfRate.name, standardPrice: num(c.shelfRate.standardPrice) } : null,
      shelfActualPrice: num(c.shelfActualPrice),
      shelfDeviationPercent: this.shelfDeviation(c),
      hasShelfPriceDeviation: this.hasShelfDeviation(c),
      isHighValue: num(c.actualValue) > 15000,
      lastNoticeDate: c.autoRenewal && c.endDate ? addDaysIso(c.endDate, -(c.noticePeriodDays || 0)) : null,
      autoRenewal: c.autoRenewal,
      noticePeriodDays: c.noticePeriodDays,
      bothPartiesSigned: c.bothPartiesSigned,
      signedAt: isoDateTime(c.signedAt),
      activatedAt: isoDateTime(c.activatedAt),
      cancelledAt: isoDateTime(c.cancelledAt),
      targets: [...c.targets]
        .sort((a, b) => a.sortOrder - b.sortOrder || a.id - b.id)
        .map((t) => ({
          id: t.id,
          title: t.title,
          targetValue: num(t.targetValue),
          discountPercent: num(t.discountPercent),
          estimatedDiscount: (num(t.targetValue) ?? 0) * ((num(t.discountPercent) ?? 0) / 100),
        })),
      attachments: [...c.attachments]
        .sort((a, b) => b.id - a.id)
        .map((a) => ({
          id: a.id,
          kind: a.kind,
          kindLabel: ATTACHMENT_LABELS[a.kind] ?? a.kind,
          originalName: a.originalName,
          uploadedAt: isoDateTime(a.uploadedAt),
          uploadedBy: a.uploadedBy ? this.userName(a.uploadedBy) : null,
        })),
      approvalSteps: [...c.approvalSteps]
        .sort((a, b) => a.sequence - b.sequence || a.id - b.id)
        .map((s) => ({
          id: s.id,
          role: s.role,
          roleLabel: APPROVER_LABELS[s.role] ?? s.role,
          sequence: s.sequence,
          decision: s.decision,
          decidedAt: isoDateTime(s.decidedAt),
          decidedBy: s.decidedBy ? this.userName(s.decidedBy) : null,
          comment: s.comment,
        })),
      pendingApprover: pending ? APPROVER_LABELS[pending.role] ?? pending.role : null,
      auditLogs: [...c.auditLogs]
        .sort((a, b) => b.id - a.id)
        .slice(0, 200)
        .map((l) => this.toAuditRow(l)),
      alerts: c.alerts
        .filter((a) => a.isOpen)
        .map((a) => ({ id: a.id, alertType: a.alertType, severity: a.severity, message: a.message, createdAt: isoDateTime(a.createdAt) })),
      actions: this.actionsFor(c, user, kinds),
    };
  }

  async auditLogs(filters: { contractId?: number; actorId?: number; from?: string; to?: string; limit?: number }) {
    const qb = this.ds
      .getRepository(AuditLogEntity)
      .createQueryBuilder('l')
      .leftJoinAndSelect('l.actor', 'a')
      .leftJoinAndSelect('a.profile', 'ap')
      .leftJoinAndSelect('l.contract', 'c')
      .orderBy('l.id', 'DESC')
      .take(Math.min(filters.limit ?? 300, 1000));
    if (filters.contractId) qb.andWhere('l.contractId = :cid', { cid: filters.contractId });
    if (filters.actorId) qb.andWhere('l.actorId = :aid', { aid: filters.actorId });
    if (filters.from) qb.andWhere('l.createdAt >= :from', { from: `${filters.from} 00:00:00` });
    if (filters.to) qb.andWhere('l.createdAt <= :to', { to: `${filters.to} 23:59:59` });
    const rows = await qb.getMany();
    return rows.map((l) => ({
      ...this.toAuditRow(l),
      contractId: l.contractId,
      contractNumber: l.contract?.contractNumber ?? null,
      actor: l.actor ? this.userName(l.actor) : null,
    }));
  }

  async attachmentFor(contractId: number, attachmentId: number) {
    const att = await this.ds.getRepository(ContractAttachmentEntity).findOne({ where: { id: attachmentId, contractId } });
    if (!att) throw new NotFoundException('المرفق غير موجود.');
    return { path: this.storage.absolutePath(att.file), name: att.originalName || att.file };
  }

  // ───────────────────────── إنشاء وتعديل المسودة ─────────────────────────

  async create(dto: ContractDto, user: AuthUser) {
    this.requireContractsStaff(user, 'إنشاء العقود مقصور على قسم العقود.');
    const { vendor, department, shelfRate } = await this.validateDraft(dto);

    const id = await this.ds.transaction(async (m) => {
      const now = new Date();
      const contract = m.create(ContractEntity, {
        ...this.draftFields(dto, shelfRate),
        contractNumber: await this.nextContractNumber(m, vendor),
        vendorId: vendor.id,
        departmentId: department.id,
        status: ContractStatus.DRAFT,
        bothPartiesSigned: false,
        cancellationReason: '',
        manuallyEscalated: false,
        createdById: user.id,
        commercialOwnerId: user.id,
        createdAt: now,
        updatedAt: now,
      });
      await m.save(contract);

      await this.replaceTargets(m, contract.id, dto.targets);
      await this.audit(m, contract.id, user, 'إنشاء مسودة', '', ContractStatus.DRAFT, { contract_number: contract.contractNumber });
      return contract.id;
    });

    if (dto.submit) await this.submit(id, user);
    return this.detail(id, user);
  }

  async update(id: number, dto: ContractDto, user: AuthUser) {
    this.requireContractsStaff(user);
    const c = await this.findFull(id);
    if (c.status !== ContractStatus.DRAFT) throw new WorkflowError('لا يمكن تعديل العقد إلا وهو مسودة.');
    const { vendor, department, shelfRate } = await this.validateDraft(dto);

    await this.ds.transaction(async (m) => {
      // رقم العقد يُولد عند الإنشاء فقط (كما في Django) ولا يتغير مع التعديل.
      await m.update(ContractEntity, id, {
        ...this.draftFields(dto, shelfRate),
        vendorId: vendor.id,
        departmentId: department.id,
        updatedAt: new Date(),
      });
      await m.delete(ContractTargetEntity, { contractId: id });
      await this.replaceTargets(m, id, dto.targets);
      await this.audit(m, id, user, 'تعديل المسودة', ContractStatus.DRAFT, ContractStatus.DRAFT);
    });
    return this.detail(id, user);
  }

  /** حذف المسودة نهائياً (لا يُسمح به لغير المسودات — الإلغاء يحفظ السجل). */
  async deleteDraft(id: number, user: AuthUser) {
    this.requireContractsStaff(user, 'حذف المسودات مقصور على قسم العقود.');
    const c = await this.findFull(id);
    if (c.status !== ContractStatus.DRAFT) throw new WorkflowError('لا يُحذف إلا المسودة. استخدم الإلغاء لبقية الحالات.');
    await this.ds.transaction(async (m) => {
      await m.delete(ContractTargetEntity, { contractId: id });
      await m.delete(ApprovalStepEntity, { contractId: id });
      await m.delete(ContractAlertEntity, { contractId: id });
      await m.delete(AuditLogEntity, { contractId: id });
      await m.delete(ContractAttachmentEntity, { contractId: id });
      await m.delete(ContractEntity, { id });
    });
    return { deleted: true };
  }

  // ───────────────────────── مسار العمل ─────────────────────────

  /** إرسال المسودة: مراجعة قانونية إن وُجدت شروط غير معيارية، وإلا إلى الاعتماد. */
  async submit(id: number, user: AuthUser) {
    this.requireContractsStaff(user, 'إرسال العقود مقصور على قسم العقود.');
    return this.ds.transaction(async (m) => {
      const c = await this.findFull(id, m);
      if (c.status !== ContractStatus.DRAFT) throw new WorkflowError('لا يمكن إرسال العقد إلا من حالة المسودة.');
      if (!c.attachments.some((a) => a.kind === AttachmentKind.ORIGINAL)) {
        throw new WorkflowError('ارفق النسخة الأصلية أولاً ثم أرسل العقد في مسار العمل.');
      }
      if (this.hasShelfDeviation(c)) {
        c.hasNonStandardTerms = true;
        c.updatedAt = new Date();
        await this.persist(m, c);
        await this.alerts.upsert(m, id, AlertType.SHELF_PRICE_DEVIATION, AlertSeverity.WARNING, 'السعر الفعلي لإيجار الأرفف يختلف عن السعر المعياري المعتمد.');
      }
      if (c.hasNonStandardTerms) {
        await this.transition(m, c, ContractStatus.UNDER_REVIEW, user, 'إرسال للمراجعة القانونية');
      } else {
        await this.createApprovalSteps(m, c);
        await this.transition(m, c, ContractStatus.PENDING_APPROVAL, user, 'إرسال للاعتماد');
      }
      return { id, status: c.status };
    }).then(() => this.detail(id, user));
  }

  async legalReview(id: number, user: AuthUser, dto: DecisionDto) {
    if (!user.canLegalReview) throw new ForbiddenException('المراجعة القانونية مقصورة على الشؤون القانونية.');
    await this.ds.transaction(async (m) => {
      const c = await this.findFull(id, m);
      if (c.status !== ContractStatus.UNDER_REVIEW) throw new WorkflowError('العقد ليس في قائمة المراجعة القانونية.');
      if (dto.approved && !c.attachments.some((a) => a.kind === AttachmentKind.LEGAL_REVIEW)) {
        throw new WorkflowError('راجع النسخة الأصلية ثم أرفق نسخة المراجعة القانونية قبل الاعتماد.');
      }
      await this.audit(m, id, user, dto.approved ? 'مراجعة قانونية — اعتماد' : 'مراجعة قانونية — إعادة للمسودة', c.status, '', { comment: dto.comment ?? '' });
      if (!dto.approved) {
        await this.transition(m, c, ContractStatus.DRAFT, user, 'إعادة للمسودة بعد المراجعة القانونية', { comment: dto.comment ?? '' });
        return;
      }
      await this.createApprovalSteps(m, c);
      await this.transition(m, c, ContractStatus.PENDING_APPROVAL, user, 'اعتماد قانوني ثم إحالة للموافقة');
    });
    return this.detail(id, user);
  }

  async decide(id: number, user: AuthUser, dto: DecisionDto) {
    await this.ds.transaction(async (m) => {
      const c = await this.findFull(id, m);
      if (c.status !== ContractStatus.PENDING_APPROVAL) throw new WorkflowError('العقد ليس بانتظار الموافقة.');
      const step = this.pendingStep(c);
      if (!step) throw new WorkflowError('لا توجد خطوة موافقة معلّقة.');
      if (step.role === ApproverRole.DEPARTMENT_HEAD && !user.canApproveAsHead) throw new ForbiddenException('هذه الخطوة تتطلب اعتماد رئيس القسم.');
      if (step.role === ApproverRole.CONTRACTS_MANAGER && !user.canApproveAsContractsManager) {
        throw new ForbiddenException('هذه الخطوة تتطلب اعتماد إدارة العقود.');
      }

      step.decision = dto.approved ? ApprovalDecision.APPROVED : ApprovalDecision.REJECTED;
      step.decidedById = user.id;
      step.decidedAt = new Date();
      step.comment = dto.comment ?? '';
      await m.save(step);
      await this.audit(m, id, user, dto.approved ? 'اعتماد العقد' : 'رفض العقد', c.status, '', { role: step.role, comment: step.comment });

      if (!dto.approved) {
        await this.transition(m, c, ContractStatus.DRAFT, user, 'رفض وإعادة للمسودة', { comment: step.comment });
        return;
      }
      const remaining = c.approvalSteps.some((s) => s.id !== step.id && s.decision === ApprovalDecision.PENDING);
      if (!remaining) await this.transition(m, c, ContractStatus.PENDING_SIGNATURE, user, 'اكتمال الاعتماد — بانتظار التوقيع');
    });
    return this.detail(id, user);
  }

  /** تصعيد يدوي لإدارة العقود (تسلسل 99). */
  async escalate(id: number, user: AuthUser, reason: string) {
    if (!(user.isContractsStaff || user.isPurchasingStaff)) throw new ForbiddenException('التصعيد مقصور على العقود أو المشتريات.');
    await this.ds.transaction(async (m) => {
      const c = await this.findFull(id, m);
      if (c.status !== ContractStatus.DRAFT && c.status !== ContractStatus.PENDING_APPROVAL) {
        throw new WorkflowError('لا يمكن التصعيد في الحالة الحالية.');
      }
      c.manuallyEscalated = true;
      c.updatedAt = new Date();
      await this.persist(m, c);
      if (c.status === ContractStatus.PENDING_APPROVAL) {
        await m.delete(ApprovalStepEntity, { contractId: id, decision: ApprovalDecision.PENDING });
        const exists = await m.findOne(ApprovalStepEntity, { where: { contractId: id, role: ApproverRole.CONTRACTS_MANAGER, sequence: 99 } });
        if (!exists) {
          await m.save(m.create(ApprovalStepEntity, { contractId: id, role: ApproverRole.CONTRACTS_MANAGER, sequence: 99, decision: ApprovalDecision.PENDING, comment: '' }));
        }
      }
      await this.audit(m, id, user, 'تصعيد يدوي لإدارة العقود', c.status, '', { reason });
    });
    return this.detail(id, user);
  }

  async markSigned(id: number, user: AuthUser) {
    this.requireContractsStaff(user, 'تثبيت التوقيع مقصور على قسم العقود.');
    await this.ds.transaction(async (m) => {
      const c = await this.findFull(id, m);
      if (c.status !== ContractStatus.PENDING_SIGNATURE) throw new WorkflowError('لا يمكن تثبيت التوقيع إلا في حالة بانتظار التوقيع.');
      if (!c.attachments.some((a) => a.kind === AttachmentKind.SIGNED)) {
        throw new WorkflowError('يجب رفع النسخة الموقعة قبل تحديث حالة التوقيع.');
      }
      c.bothPartiesSigned = true;
      c.signedAt = new Date();
      c.updatedAt = new Date();
      await this.persist(m, c);
      await this.audit(m, id, user, 'اكتمال توقيع الطرفين', c.status);
      await this.refreshActivation(m, id, user);
    });
    return this.detail(id, user);
  }

  async cancel(id: number, user: AuthUser, reason: string) {
    this.requireContractsStaff(user, 'إلغاء العقود مقصور على قسم العقود.');
    await this.ds.transaction(async (m) => {
      const c = await this.findFull(id, m);
      if (c.status === ContractStatus.CANCELLED) throw new WorkflowError('العقد ملغى مسبقاً.');
      if (c.status === ContractStatus.EXPIRED) throw new WorkflowError('لا يمكن إلغاء عقد منتهٍ.');
      c.cancelledAt = new Date();
      c.cancellationReason = reason;
      c.updatedAt = new Date();
      await this.persist(m, c);
      await this.transition(m, c, ContractStatus.CANCELLED, user, 'إلغاء العقد', { reason });
    });
    return this.detail(id, user);
  }

  async note(id: number, user: AuthUser, text: string) {
    await this.ds.transaction(async (m) => {
      const c = await this.findFull(id, m);
      await this.audit(m, id, user, 'ملاحظة', c.status, c.status, { text });
    });
    return this.detail(id, user);
  }

  // ───────────────────────── المرفقات ─────────────────────────

  async addAttachment(id: number, user: AuthUser, dto: AttachmentDto, file: Express.Multer.File) {
    if (!file) throw new BadRequestException('اختر ملفاً للرفع.');
    const legalKind = dto.kind === AttachmentKind.LEGAL_REVIEW;
    if (legalKind ? !user.canLegalReview : !user.isContractsStaff) {
      throw new ForbiddenException(legalKind ? 'نسخة المراجعة القانونية تُرفع من الشؤون القانونية.' : 'رفع المرفقات مقصور على قسم العقود.');
    }
    const stored = await this.storage.save(file);
    try {
      await this.ds.transaction(async (m) => {
        const c = await this.findFull(id, m);
        if (this.isClosed(c)) throw new WorkflowError('لا يمكن رفع مرفقات لعقد ملغى أو منتهٍ.');
        await m.save(
          m.create(ContractAttachmentEntity, {
            contractId: id,
            kind: dto.kind,
            file: stored.relativePath,
            originalName: stored.originalName,
            uploadedById: user.id,
            uploadedAt: new Date(),
          }),
        );
        await this.audit(m, id, user, 'رفع مرفق', c.status, '', { kind: dto.kind, name: stored.originalName });
      });
    } catch (err) {
      this.storage.remove(stored.relativePath);
      throw err;
    }
    return this.detail(id, user);
  }

  /** حساب التنبيهات الآلية لكل العقود (مطابقة refresh_all_alerts في Django). */
  async refreshAllAlerts() {
    const m = this.ds.manager;
    const today = todayIso();
    const keep = new Set<string>();
    const mark = (id: number, type: string) => keep.add(`${id}:${type}`);
    const contracts = await m.getRepository(ContractEntity).find({ relations: { vendor: true, shelfRate: true } });

    for (const c of contracts) {
      if (c.status === ContractStatus.CANCELLED) {
        await m.getRepository(ContractAlertEntity).update({ contractId: c.id, isOpen: true }, { isOpen: false, resolvedAt: new Date() });
        continue;
      }

      const days = daysBetween(today, c.endDate);
      if (c.status === ContractStatus.ACTIVE || c.status === ContractStatus.PENDING_SIGNATURE) {
        if (days >= 0 && days <= 30) {
          await this.alerts.upsert(m, c.id, AlertType.EXPIRY_30, AlertSeverity.CRITICAL, `متبقٍ ${days} يوماً على انتهاء العقد.`);
          mark(c.id, AlertType.EXPIRY_30);
        } else if (days > 30 && days <= 60) {
          await this.alerts.upsert(m, c.id, AlertType.EXPIRY_60, AlertSeverity.WARNING, `متبقٍ ${days} يوماً على انتهاء العقد.`);
          mark(c.id, AlertType.EXPIRY_60);
        } else if (days > 60 && days <= 90) {
          await this.alerts.upsert(m, c.id, AlertType.EXPIRY_90, AlertSeverity.INFO, `متبقٍ ${days} يوماً على انتهاء العقد.`);
          mark(c.id, AlertType.EXPIRY_90);
        }
      }

      if (c.autoRenewal && c.status === ContractStatus.ACTIVE) {
        const notice = addDays(c.endDate, -(c.noticePeriodDays || 0));
        if ((today <= notice && notice <= addDays(today, 14)) || (notice <= today && today < c.endDate)) {
          await this.alerts.upsert(m, c.id, AlertType.RENEWAL_NOTICE, AlertSeverity.WARNING, `آخر يوم للإشعار بعدم التجديد: ${notice}.`);
          mark(c.id, AlertType.RENEWAL_NOTICE);
        }
      }

      if (c.status === ContractStatus.PENDING_SIGNATURE && !c.bothPartiesSigned) {
        await this.alerts.upsert(m, c.id, AlertType.UNSIGNED_APPROVED, AlertSeverity.WARNING, 'العقد معتمد ولم يكتمل توقيع الطرفين.');
        mark(c.id, AlertType.UNSIGNED_APPROVED);
      }

      if (c.status === ContractStatus.ACTIVE && c.endDate < today) {
        await this.alerts.upsert(m, c.id, AlertType.EXPIRED_STILL_ACTIVE, AlertSeverity.CRITICAL, 'خطأ إداري: العقد منتهٍ وحالته ما زالت نشطاً.');
        mark(c.id, AlertType.EXPIRED_STILL_ACTIVE);
      }

      if (this.hasShelfDeviation(c)) {
        await this.alerts.upsert(m, c.id, AlertType.SHELF_PRICE_DEVIATION, AlertSeverity.WARNING, 'فرق بين السعر الفعلي لإيجار الأرفف والسعر المعياري.');
        mark(c.id, AlertType.SHELF_PRICE_DEVIATION);
      }

      await this.refreshActivation(m, c.id, null);
    }

    await this.alerts.closeStale(
      m,
      [
        AlertType.EXPIRY_90,
        AlertType.EXPIRY_60,
        AlertType.EXPIRY_30,
        AlertType.RENEWAL_NOTICE,
        AlertType.UNSIGNED_APPROVED,
        AlertType.EXPIRED_STILL_ACTIVE,
      ],
      keep,
    );
  }

  // ───────────────────────── داخلية ─────────────────────────

  /** تفعيل تلقائي بعد اكتمال التوقيع عند بلوغ تاريخ النفاذ (مطابقة refresh_activation). */
  async refreshActivation(m: EntityManager, id: number, actor: AuthUser | null) {
    const c = await this.findFull(id, m);
    const today = todayIso();
    if (c.status === ContractStatus.PENDING_SIGNATURE && c.bothPartiesSigned && c.startDate <= today) {
      c.activatedAt = new Date();
      c.updatedAt = new Date();
      await this.persist(m, c);
      // بدون مستخدم فاعل (مهمة مجدولة) ننسب الإجراء لأول مدير نظام، كما يفعل Django.
      const systemUser = actor ?? (await m.findOne(UserEntity, { where: { isSuperuser: true } }));
      if (systemUser) {
        await this.transition(
          m,
          c,
          ContractStatus.ACTIVE,
          { id: systemUser.id },
          actor ? 'تفعيل العقد في تاريخ النفاذ' : 'تفعيل تلقائي في تاريخ النفاذ',
        );
      }
    }
    if (c.status === ContractStatus.ACTIVE && c.endDate < today) {
      await this.alerts.upsert(m, id, AlertType.EXPIRED_STILL_ACTIVE, AlertSeverity.CRITICAL, 'العقد تجاوز تاريخ انتهائه وما زالت حالته نشطاً.');
    }
  }

  /** الرقم التالي للمورد: أعلى تسلسل موجود + 1، فلا يتعارض مع أرقام سابقة غير متصلة. */
  private async nextContractNumber(m: EntityManager, vendor: VendorEntity): Promise<string> {
    const existing = await m.find(ContractEntity, { where: { vendorId: vendor.id }, select: ['contractNumber'] });
    const prefix = `${vendor.vendorNumber}-`;
    const max = existing.reduce((acc, c) => {
      const n = c.contractNumber.startsWith(prefix) ? Number(c.contractNumber.slice(prefix.length)) : NaN;
      return Number.isFinite(n) ? Math.max(acc, n) : acc;
    }, 0);
    return `${prefix}${String(max + 1).padStart(2, '0')}`;
  }

  /**
   * حفظ أعمدة العقد فقط. لا نستخدم save() على كيان محمّل مع علاقات الأطفال (المرفقات والسجل...):
   * TypeORM يُفرغ مفاتيح الأطفال الجديدة عند ذلك. لهذا الكتابة بـ UPDATE صريح.
   */
  private async persist(m: EntityManager, c: ContractEntity) {
    c.updatedAt = new Date();
    await m.update(ContractEntity, c.id, {
      status: c.status,
      hasNonStandardTerms: c.hasNonStandardTerms,
      manuallyEscalated: c.manuallyEscalated,
      bothPartiesSigned: c.bothPartiesSigned,
      signedAt: c.signedAt,
      activatedAt: c.activatedAt,
      cancelledAt: c.cancelledAt,
      cancellationReason: c.cancellationReason,
      updatedAt: c.updatedAt,
    });
  }

  private async transition(m: EntityManager, c: ContractEntity, to: string, actor: { id: number } | AuthUser, action: string, details: Record<string, unknown> = {}) {
    const allowed = ALLOWED_TRANSITIONS[c.status] ?? [];
    if (!allowed.includes(to)) {
      throw new WorkflowError(`لا يمكن الانتقال من ${STATUS_LABELS[c.status] ?? c.status} إلى الحالة المطلوبة.`);
    }
    const from = c.status;
    c.status = to;
    c.updatedAt = new Date();
    await this.persist(m, c);
    await this.audit(m, c.id, actor, action, from, to, details);
  }

  private async audit(m: EntityManager, contractId: number, actor: { id: number }, action: string, from = '', to = '', details: Record<string, unknown> = {}) {
    await m.save(
      m.create(AuditLogEntity, {
        contractId,
        actorId: actor.id,
        action,
        fromStatus: from,
        toStatus: to,
        details: JSON.stringify(details),
        createdAt: new Date(),
      }),
    );
  }

  private async createApprovalSteps(m: EntityManager, c: ContractEntity) {
    await m.delete(ApprovalStepEntity, { contractId: c.id });
    const role = requiredApproverRole({
      hasNonStandardTerms: c.hasNonStandardTerms,
      manuallyEscalated: c.manuallyEscalated,
      contractType: c.contractType,
      actualValue: Number(c.actualValue),
      shelfRateStandardPrice: c.shelfRate ? Number(c.shelfRate.standardPrice) : null,
      shelfActualPrice: c.shelfActualPrice == null ? null : Number(c.shelfActualPrice),
    });
    await m.save(m.create(ApprovalStepEntity, { contractId: c.id, role, sequence: 1, decision: ApprovalDecision.PENDING, comment: '' }));
    c.approvalSteps = await m.find(ApprovalStepEntity, { where: { contractId: c.id } });
  }

  private async replaceTargets(m: EntityManager, contractId: number, targets: TargetDto[]) {
    const rows = (targets ?? []).map((t, i) =>
      m.create(ContractTargetEntity, {
        contractId,
        title: t.title,
        targetValue: String(t.targetValue),
        discountPercent: String(t.discountPercent),
        sortOrder: i + 1,
      }),
    );
    if (rows.length) await m.save(rows);
  }

  /** التحقق من قواعد المسودة (مطابقة Contract.clean + قواعد المواصفة). */
  private async validateDraft(dto: ContractDto) {
    const errors: Record<string, string> = {};
    if (dto.endDate <= dto.startDate) errors.endDate = 'تاريخ الانتهاء يجب أن يكون بعد تاريخ البدء.';
    else if (daysBetween(dto.startDate, dto.endDate) > MAX_CONTRACT_DAYS) errors.endDate = 'أقصى مدة للعقد سنة واحدة.';

    if (dto.contractType === ContractType.SUPPLY && ![ContractSubtype.FRESH, ContractSubtype.FOOD, ContractSubtype.NON_FOOD].includes(dto.subtype as never)) {
      errors.subtype = 'عقود التوريد تتطلب نوعاً فرعياً: فريش / غذائي / لا غذائي.';
    }
    if (dto.contractType === ContractType.SERVICES && dto.subtype && dto.subtype !== ContractSubtype.ACCOUNT_OPENING) {
      errors.subtype = 'لعقود الخدمات استخدم «فتح حساب» أو اترك النوع الفرعي فارغاً.';
    }
    if (dto.contractType === ContractType.SHELF_RENT && !dto.shelfRateId) {
      errors.shelfRateId = 'عقود إيجار الأرفف تتطلب نوع الرف المعياري.';
    }
    dto.targets?.forEach((t, i) => {
      if (t.discountPercent > 100) errors[`targets.${i}.discountPercent`] = 'نسبة الخصم لا تتجاوز 100%.';
    });

    const vendor = await this.ds.getRepository(VendorEntity).findOne({ where: { id: dto.vendorId } });
    if (!vendor || !vendor.isActive) errors.vendorId = 'اختر موردًا نشطًا من البيانات الرئيسية.';
    const department = await this.ds.getRepository(DepartmentEntity).findOne({ where: { id: dto.departmentId } });
    if (!department || !department.isActive) errors.departmentId = 'اختر قسماً نشطاً.';
    let shelfRate: ShelfRateEntity | null = null;
    if (dto.shelfRateId) {
      shelfRate = await this.ds.getRepository(ShelfRateEntity).findOne({ where: { id: dto.shelfRateId } });
      if (!shelfRate) errors.shelfRateId = 'نوع الرف غير موجود.';
    }

    if (Object.keys(errors).length) throw new UnprocessableEntityException({ message: 'تحقق من الحقول المعلّمة.', errors });
    return { vendor: vendor!, department: department!, shelfRate };
  }

  private draftFields(dto: ContractDto, shelfRate: ShelfRateEntity | null) {
    const subtype = dto.contractType === ContractType.SHELF_RENT || dto.contractType === ContractType.MARKETING ? '' : dto.subtype ?? '';
    return {
      contractType: dto.contractType,
      subtype,
      internalClassification: dto.internalClassification ?? '',
      startDate: dto.startDate,
      endDate: dto.endDate,
      autoRenewal: false, // المواصفة: لا تجديد تلقائي
      noticePeriodDays: 30,
      actualValue: String(dto.actualValue),
      hasNonStandardTerms: dto.hasNonStandardTerms,
      notes: dto.notes ?? '',
      shelfRateId: shelfRate?.id ?? null,
      shelfActualPrice: dto.shelfActualPrice == null ? null : String(dto.shelfActualPrice),
      purchasingManagerId: dto.purchasingManagerId ?? null,
    };
  }

  private async findFull(id: number, m?: EntityManager): Promise<ContractEntity> {
    const repo = m ? m.getRepository(ContractEntity) : this.ds.getRepository(ContractEntity);
    const c = await repo.findOne({
      where: { id },
      relations: {
        vendor: true,
        department: true,
        shelfRate: true,
        commercialOwner: true,
        createdBy: true,
        targets: true,
        attachments: { uploadedBy: true },
        approvalSteps: { decidedBy: true },
        auditLogs: { actor: { profile: true } },
        alerts: true,
      },
    });
    if (!c) throw new NotFoundException('العقد غير موجود.');
    return c;
  }

  private pendingStep(c: ContractEntity): ApprovalStepEntity | null {
    const steps = c.approvalSteps.filter((s) => s.decision === ApprovalDecision.PENDING).sort((a, b) => a.sequence - b.sequence);
    return steps[0] ?? null;
  }

  private hasShelfDeviation(c: ContractEntity): boolean {
    if (c.contractType !== ContractType.SHELF_RENT || !c.shelfRate || c.shelfActualPrice == null) return false;
    return Number(c.shelfActualPrice) !== Number(c.shelfRate.standardPrice);
  }

  private shelfDeviation(c: ContractEntity): number {
    if (!this.hasShelfDeviation(c) || !Number(c.shelfRate!.standardPrice)) return 0;
    const base = Number(c.shelfRate!.standardPrice);
    return ((Number(c.shelfActualPrice) - base) / base) * 100;
  }

  private actionsFor(c: ContractEntity, user: AuthUser, kinds: Set<string>): ContractAction[] {
    const out: ContractAction[] = [];
    const add = (key: string, label: string, primary = false) => out.push({ key, label, primary });
    const pending = this.pendingStep(c);
    const closed = this.isClosed(c);

    if (c.status === ContractStatus.DRAFT && user.isContractsStaff) {
      add('edit', 'عدّل المسودة', true);
      add('delete', 'احذف المسودة');
      if (kinds.has(AttachmentKind.ORIGINAL)) add('submit', 'أرسل للاعتماد', true);
    }
    if (c.status === ContractStatus.UNDER_REVIEW && user.canLegalReview) {
      add('legal_approve', 'اعتمد قانونياً', true);
      add('legal_return', 'أعِد للمسودة');
    }
    if (c.status === ContractStatus.PENDING_APPROVAL && pending) {
      const canDecide =
        (pending.role === ApproverRole.DEPARTMENT_HEAD && user.canApproveAsHead) ||
        (pending.role === ApproverRole.CONTRACTS_MANAGER && user.canApproveAsContractsManager);
      if (canDecide) {
        add('approve', 'اعتمد', true);
        add('reject', 'ارفض');
      }
    }
    if ((c.status === ContractStatus.DRAFT || c.status === ContractStatus.PENDING_APPROVAL) && (user.isContractsStaff || user.isPurchasingStaff)) {
      add('escalate', 'صعّد لإدارة العقود');
    }
    if (c.status === ContractStatus.PENDING_SIGNATURE && user.isContractsStaff && !c.bothPartiesSigned) {
      add('mark_signed', 'ثبّت التوقيع', true);
    }
    if (!closed && user.isContractsStaff && c.status !== ContractStatus.DRAFT) add('cancel', 'ألغِ العقد');
    if (!closed) add('upload', 'ارفع مرفقاً');
    add('note', 'أضف ملاحظة');
    return out;
  }

  private toRow(c: ContractEntity, user: AuthUser, kinds: Set<string>, lastActivity?: string) {
    const pending = this.pendingStep(c);
    const actions = this.actionsFor(c, user, kinds);
    const awaitingUser = actions.some((a) => ['approve', 'legal_approve', 'mark_signed', 'submit'].includes(a.key));
    const responsible =
      c.status === ContractStatus.PENDING_APPROVAL && pending
        ? APPROVER_LABELS[pending.role] ?? pending.role
        : c.status === ContractStatus.UNDER_REVIEW
          ? 'الشؤون القانونية'
          : c.status === ContractStatus.PENDING_SIGNATURE
            ? 'قسم العقود — التوقيع'
            : c.status === ContractStatus.DRAFT
              ? 'قسم العقود — المسودة'
              : null;
    return {
      id: c.id,
      contractNumber: c.contractNumber,
      createdById: c.createdById,
      commercialOwnerId: c.commercialOwnerId,
      vendorId: c.vendorId,
      vendorName: c.vendor?.tradeName || c.vendor?.legalName || '',
      vendorNumber: c.vendor?.vendorNumber ?? '',
      contractType: c.contractType,
      contractTypeLabel: TYPE_LABELS[c.contractType] ?? c.contractType,
      subtype: c.subtype,
      subtypeLabel: SUBTYPE_LABELS[c.subtype] ?? '',
      departmentId: c.departmentId,
      departmentName: c.department?.name ?? '',
      startDate: c.startDate,
      endDate: c.endDate,
      daysRemaining: daysBetween(todayIso(), c.endDate),
      status: c.status,
      statusLabel: STATUS_LABELS[c.status] ?? c.status,
      actualValue: num(c.actualValue),
      hasNonStandardTerms: c.hasNonStandardTerms,
      isHighValue: num(c.actualValue)! > 15000,
      requiredApprover: APPROVER_LABELS[requiredApproverRole({
        hasNonStandardTerms: c.hasNonStandardTerms,
        manuallyEscalated: c.manuallyEscalated,
        contractType: c.contractType,
        actualValue: Number(c.actualValue),
        shelfRateStandardPrice: c.shelfRate ? Number(c.shelfRate.standardPrice) : null,
        shelfActualPrice: c.shelfActualPrice == null ? null : Number(c.shelfActualPrice),
      })] ?? '',
      responsible,
      waitingDays: daysSince(lastActivity ?? c.updatedAt),
      awaitingUser,
      primaryAction: actions.find((a) => a.primary)?.label ?? null,
      cancellationReason: c.cancellationReason,
    };
  }

  private toAuditRow(l: AuditLogEntity) {
    let details: Record<string, unknown> = {};
    try {
      details = l.details ? JSON.parse(l.details) : {};
    } catch {
      details = {};
    }
    return {
      id: l.id,
      action: l.action,
      fromStatus: l.fromStatus,
      toStatus: l.toStatus,
      fromStatusLabel: STATUS_LABELS[l.fromStatus] ?? '',
      toStatusLabel: STATUS_LABELS[l.toStatus] ?? '',
      details,
      createdAt: isoDateTime(l.createdAt),
      actorName: l.actor ? this.userName(l.actor) : null,
      actorRole: l.actor?.profile?.role ?? null,
    };
  }

  private userName(u: UserEntity) {
    return `${u.firstName} ${u.lastName}`.trim() || u.username;
  }

  /** آخر نشاط (إجراء في السجل) لكل عقد — يُستخدم لحساب مدة الانتظار. */
  private async lastActivityMap(ids: number[]): Promise<Map<number, string>> {
    const map = new Map<number, string>();
    if (!ids.length) return map;
    const rows: { contractId: number; last: string }[] = await this.ds
      .getRepository(AuditLogEntity)
      .createQueryBuilder('l')
      .select('l.contractId', 'contractId')
      .addSelect('MAX(l.createdAt)', 'last')
      .where({ contractId: In(ids) })
      .groupBy('l.contractId')
      .getRawMany();
    rows.forEach((r) => map.set(Number(r.contractId), r.last));
    return map;
  }

  private async attachmentKindMap(ids: number[]): Promise<Map<number, Set<string>>> {
    const map = new Map<number, Set<string>>();
    if (!ids.length) return map;
    const rows = await this.ds.getRepository(ContractAttachmentEntity).find({ where: { contractId: In(ids) }, select: ['contractId', 'kind'] });
    rows.forEach((r) => {
      if (!map.has(r.contractId)) map.set(r.contractId, new Set());
      map.get(r.contractId)!.add(r.kind);
    });
    return map;
  }
}

const ATTACHMENT_LABELS: Record<string, string> = {
  original: 'النسخة الأصلية',
  legal_review: 'نسخة المراجعة القانونية',
  signed: 'النسخة الموقعة',
  addendum: 'ملحق',
  renewal_letter: 'خطاب تجديد',
  termination_letter: 'خطاب إنهاء',
  other: 'أخرى',
};

function addDaysIso(iso: string, days: number): string {
  return addDays(iso, days);
}
