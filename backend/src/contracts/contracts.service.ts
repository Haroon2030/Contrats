import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
  UnprocessableEntityException,
} from '@nestjs/common';
import { InjectRepository } from '@nestjs/typeorm';
import { EntityManager, In, Repository } from 'typeorm';
import { AuditService } from '../audit/audit.service';
import { AuthUser } from '../auth/auth.service';
import { daysBetween, daysSince, isoDateTime, todayIso } from '../common/dates';
import {
  ACCOUNT_DOC_KINDS,
  ATTACHMENT_LABELS,
  AttachmentKind,
  ContractType,
  PARTY_LABELS,
  Party,
  RefList,
  Role,
  STATUS_LABELS,
  Status,
  TYPE_LABELS,
  isServicesOnly,
  normalizeRole,
} from '../common/domain';
import {
  AddendumEntity,
  AttachmentEntity,
  AuditLogEntity,
  ContractEntity,
  ContractStayEntity,
  DepartmentEntity,
  GoalLineEntity,
  MarketingLineEntity,
  RefValueEntity,
  RentLineEntity,
  UserEntity,
  VendorEntity,
} from '../database/entities';
import { GoalsService } from '../goals/goals.service';
import { SettingsService } from '../reference/reference.module';
import { PrintService, PrintData } from '../templates/print.service';
import { TemplatesService } from '../templates/templates.module';
import { AttachmentStorage } from './attachment.storage';
import { CleanContract, RefCtx, validateContract } from './validation';

export interface ContractAction {
  key: string;
  label: string;
  primary?: boolean;
  danger?: boolean;
  /** يحتاج سبباً/ملاحظة إلزامية من المستخدم. */
  reason?: 'required' | 'optional';
}

export class WorkflowError extends UnprocessableEntityException {
  constructor(message: string, errors?: Record<string, string>) {
    super({ message, errors });
  }
}

const dec = (v: number | null | undefined) => (v == null ? null : v.toFixed(2));
const num = (v: string | number | null | undefined) => (v == null ? null : Number(v));

@Injectable()
export class ContractsService {
  constructor(
    @InjectRepository(ContractEntity) readonly contracts: Repository<ContractEntity>,
    @InjectRepository(VendorEntity) readonly vendors: Repository<VendorEntity>,
    @InjectRepository(DepartmentEntity) readonly departments: Repository<DepartmentEntity>,
    @InjectRepository(UserEntity) readonly users: Repository<UserEntity>,
    @InjectRepository(RefValueEntity) readonly refs: Repository<RefValueEntity>,
    @InjectRepository(GoalLineEntity) readonly goalLines: Repository<GoalLineEntity>,
    @InjectRepository(RentLineEntity) readonly rentLines: Repository<RentLineEntity>,
    @InjectRepository(MarketingLineEntity) readonly marketingLines: Repository<MarketingLineEntity>,
    @InjectRepository(AttachmentEntity) readonly attachments: Repository<AttachmentEntity>,
    @InjectRepository(AddendumEntity) readonly addenda: Repository<AddendumEntity>,
    @InjectRepository(ContractStayEntity) readonly stays: Repository<ContractStayEntity>,
    @InjectRepository(AuditLogEntity) readonly auditLogs: Repository<AuditLogEntity>,
    readonly audit: AuditService,
    readonly goals: GoalsService,
    readonly settings: SettingsService,
    readonly templates: TemplatesService,
    readonly print: PrintService,
    readonly storage: AttachmentStorage,
  ) {}

  // ───────────────────────── الصلاحيات والرؤية ─────────────────────────

  /** هل يرى المستخدم هذا العقد؟ (3.2: مسؤول القسم قسمه فقط، التشغيل عبر صفحته). */
  canSee(user: AuthUser, c: ContractEntity): boolean {
    if (user.caps.operationsOnly) return false;
    if (user.role === Role.DEPARTMENT_OFFICIAL) return c.departmentId === user.departmentId;
    return true;
  }

  isOwnDepartment(user: AuthUser, c: ContractEntity) {
    return !!user.departmentId && user.departmentId === c.departmentId;
  }

  isDeptManagerOf(user: AuthUser, c: ContractEntity) {
    return user.role === Role.DEPARTMENT_MANAGER && this.isOwnDepartment(user, c);
  }

  /** من يعتمد العقد أو يرفضه في مرحلة مراجعة القسم: مدير القسم لعقود قسمه، ومدير المشتريات لأي عقد. */
  canReview(user: AuthUser, c: ContractEntity) {
    return user.role === Role.PURCHASING_MANAGER || this.isDeptManagerOf(user, c);
  }

  canSeeAccountData(user: AuthUser, c: ContractEntity): boolean {
    if (!c.types.includes(ContractType.OPEN_ACCOUNT)) return true;
    if (!user.caps.seesAccountDocs) return false;
    if (user.role === Role.DEPARTMENT_MANAGER || user.role === Role.DEPARTMENT_OFFICIAL) return this.isOwnDepartment(user, c);
    return true;
  }

  async mustGet(id: number, user?: AuthUser): Promise<ContractEntity> {
    const c = await this.contracts.findOne({ where: { id } });
    if (!c) throw new NotFoundException('العقد غير موجود.');
    if (user && !this.canSee(user, c)) throw new ForbiddenException('لا تملك صلاحية رؤية هذا العقد.');
    return c;
  }

  // ───────────────────────── القوائم المرجعية للتحقق ─────────────────────────

  async refCtx(): Promise<RefCtx> {
    const [vendors, departments, refs] = await Promise.all([
      this.vendors.find({ where: { isActive: true } }),
      this.departments.find({ where: { isActive: true } }),
      this.refs.find({ where: { isActive: true } }),
    ]);
    const ids = (list: string) => new Set(refs.filter((r) => r.list === list).map((r) => r.id));
    return {
      vendorIds: new Set(vendors.map((v) => v.id)),
      departmentIds: new Set(departments.map((d) => d.id)),
      branchIds: ids(RefList.BRANCH),
      shelfIds: ids(RefList.SHELF_TYPE),
      eventIds: ids(RefList.EVENT),
    };
  }

  /** ممثل المورد في آخر عقد له (لتعبئة النموذج تلقائياً). */
  async lastRepresentative(vendorId: number) {
    const c = await this.contracts.findOne({ where: { vendorId }, order: { id: 'DESC' } });
    return c ? { repName: c.repName, repTitle: c.repTitle, repMobile: c.repMobile } : null;
  }

  // ───────────────────────── المسودات ─────────────────────────

  private resolveDepartment(user: AuthUser, requested: number | null): number {
    if (user.role === Role.DEPARTMENT_MANAGER || user.role === Role.DEPARTMENT_OFFICIAL) {
      if (!user.departmentId) throw new ForbiddenException('حسابك غير مرتبط بقسم.');
      return user.departmentId;
    }
    if (user.caps.pickDepartment) {
      if (!requested) throw new BadRequestException({ message: 'اختر القسم.', errors: { departmentId: 'اختر القسم.' } });
      return requested;
    }
    throw new ForbiddenException('إنشاء العقود لمسؤول القسم ومدير القسم ومدير المشتريات ومسؤول النظام فقط.');
  }

  private checkErrors(errors: Record<string, string>) {
    if (Object.keys(errors).length) throw new BadRequestException({ message: 'تحقق من الحقول المعلّمة.', errors });
  }

  async createDraft(user: AuthUser, body: Record<string, any>) {
    if (!user.caps.canCreate) throw new ForbiddenException('إنشاء العقود لمسؤول القسم ومدير القسم ومدير المشتريات ومسؤول النظام فقط.');
    const departmentId = this.resolveDepartment(user, Number(body.departmentId) || null);
    const { errors, clean } = validateContract({ ...body, departmentId }, 'draft', await this.refCtx());
    this.checkErrors(errors);

    const thresholds = await this.settings.get(); // Threshold السارية وقت الإنشاء
    const id = await this.contracts.manager.transaction(async (m) => {
      const now = new Date();
      const c = await m.save(
        m.create(ContractEntity, {
          ...this.columns(clean),
          departmentId,
          createdById: user.id,
          number: null,
          status: Status.DRAFT,
          party: Party.CREATOR,
          stateEnteredAt: now,
          hasAlert: false,
          alertKind: null,
          alertReason: '',
          rejectReason: '',
          closeReason: '',
          cancelReason: '',
          termVersions: null,
          remindersSent: null,
          lastEscalatedAt: null,
          escalationDays: thresholds.escalationDays,
          reminderDays: thresholds.reminderDays,
          addendumCount: 0,
          sentAt: null,
          activatedAt: null,
          endedAt: null,
          cancelledAt: null,
          createdAt: now,
          updatedAt: now,
        }),
      );
      await this.writeLines(m, c.id, clean);
      await this.openStay(m, c.id, Status.DRAFT, Party.CREATOR);
      await this.audit.log({ user, action: 'إنشاء مسودة عقد', entity: 'contract', contractId: c.id, after: { types: clean.types, vendorId: clean.vendorId } }, m);
      return c.id;
    });
    return this.detail(id, user);
  }

  async updateDraft(user: AuthUser, id: number, body: Record<string, any>) {
    const c = await this.mustGet(id, user);
    if (c.status !== Status.DRAFT) throw new WorkflowError('لا يمكن تعديل العقد إلا وهو مسودة.');
    if (c.createdById !== user.id && !user.caps.createAnyDepartment) throw new ForbiddenException('تعديل المسودة لمنشئها فقط.');
    const { errors, clean } = validateContract({ ...body, departmentId: c.departmentId }, 'draft', await this.refCtx());
    this.checkErrors(errors);
    const before = this.snapshot(c);
    await this.contracts.manager.transaction(async (m) => {
      await m.update(ContractEntity, id, { ...this.columns(clean), updatedAt: new Date() });
      await this.writeLines(m, id, clean);
      await this.audit.log({ user, action: 'تعديل المسودة', entity: 'contract', contractId: id, before, after: { types: clean.types, startDate: clean.startDate, endDate: clean.endDate } }, m);
    });
    return this.detail(id, user);
  }

  /** حذف المسودة قبل أول إرسال فقط وبواسطة منشئها. بعد الإرسال لا يُحذف العقد. */
  async deleteDraft(user: AuthUser, id: number) {
    const c = await this.mustGet(id, user);
    if (c.status !== Status.DRAFT || c.number || c.sentAt) throw new WorkflowError('بعد الإرسال لا يُحذف العقد.');
    if (c.createdById !== user.id && !user.caps.createAnyDepartment) throw new ForbiddenException('حذف المسودة لمنشئها فقط.');
    await this.contracts.manager.transaction(async (m) => {
      const atts = await m.find(AttachmentEntity, { where: { contractId: id } });
      atts.forEach((a) => this.storage.remove(a.path));
      await m.delete(AttachmentEntity, { contractId: id });
      await m.delete(GoalLineEntity, { contractId: id });
      await m.delete(RentLineEntity, { contractId: id });
      await m.delete(MarketingLineEntity, { contractId: id });
      await m.delete(ContractStayEntity, { contractId: id });
      await m.delete(ContractEntity, { id });
      await this.audit.log({ user, action: 'حذف مسودة (قبل أول إرسال)', entity: 'contract', contractId: null, before: this.snapshot(c) }, m);
    });
    return { deleted: true };
  }

  /** ترتيب الأعمدة المخزّنة من القيم المنظَّفة. */
  columns(clean: CleanContract) {
    return {
      vendorId: clean.vendorId,
      entryPath: clean.entryPath,
      types: clean.types,
      primaryType: clean.primaryType,
      repName: clean.repName,
      repTitle: clean.repTitle,
      repMobile: clean.repMobile,
      startDate: clean.startDate,
      endDate: clean.endDate,
      specialTerms: clean.specialTerms,
      paymentDays: clean.paymentDays,
      settlementDiscountPct: dec(clean.settlementDiscountPct),
      fixedDiscountPct: dec(clean.fixedDiscountPct),
      newItemPrice: dec(clean.newItemPrice),
      creditLimit: dec(clean.creditLimit),
    };
  }

  async writeLines(m: EntityManager, contractId: number, clean: CleanContract) {
    await m.delete(GoalLineEntity, { contractId, version: 0 });
    await m.delete(RentLineEntity, { contractId });
    await m.delete(MarketingLineEntity, { contractId });
    if (clean.goalLines.length) {
      await m.save(
        clean.goalLines.map((g, i) =>
          m.create(GoalLineEntity, {
            contractId,
            fromDate: g.fromDate,
            toDate: g.toDate,
            target: g.target.toFixed(2),
            discountPct: g.discountPct.toFixed(2),
            version: 0,
            activeFrom: null,
            activeTo: null,
            sort: i,
            fixedStatus: null,
            fixedNet: null,
            fixedDiscount: null,
          }),
        ),
      );
    }
    if (clean.rentLines.length) {
      await m.save(
        clean.rentLines.map((r) =>
          m.create(RentLineEntity, { contractId, branchId: r.branchId, shelfTypeId: r.shelfTypeId, qty: r.qty, price: r.price.toFixed(2), unit: r.unit, startDate: r.startDate, endDate: r.endDate, periods: r.periods, total: r.total.toFixed(2) }),
        ),
      );
    }
    if (clean.marketingLines.length) {
      await m.save(clean.marketingLines.map((r) => m.create(MarketingLineEntity, { contractId, eventId: r.eventId, price: r.price.toFixed(2), note: r.note })));
    }
  }

  // ───────────────────────── الحالات والإقامة ─────────────────────────

  async openStay(m: EntityManager, contractId: number, state: string, party: string | null) {
    await m.save(m.create(ContractStayEntity, { contractId, state, party, enteredAt: new Date(), leftAt: null }));
  }

  /** ينقل العقد إلى حالة وجهة جديدتين ويسجّل زمن الدخول (لحساب مدة البقاء والتصعيد). */
  async moveTo(m: EntityManager, c: ContractEntity, state: string, party: string | null, extra: Partial<ContractEntity> = {}) {
    const now = new Date();
    await m
      .createQueryBuilder()
      .update(ContractStayEntity)
      .set({ leftAt: now })
      .where('contractId = :id AND leftAt IS NULL', { id: c.id })
      .execute();
    await this.openStay(m, c.id, state, party);
    Object.assign(c, extra, { status: state, party, stateEnteredAt: now, updatedAt: now });
    await m.update(ContractEntity, c.id, { ...extra, status: state, party, stateEnteredAt: now, updatedAt: now });
  }

  /** رقم العقد: رقم المورد-NN، يُخصَّص عند أول إرسال فقط. */
  async assignNumber(m: EntityManager, c: ContractEntity): Promise<string> {
    if (c.number) return c.number;
    const vendor = await m.findOne(VendorEntity, { where: { id: c.vendorId } });
    const prefix = `${vendor!.vendorNumber}-`;
    const existing = await m.find(ContractEntity, { where: { vendorId: c.vendorId }, select: ['number'] });
    const max = existing.reduce((acc, x) => {
      const nn = x.number?.startsWith(prefix) ? Number(x.number.slice(prefix.length)) : NaN;
      return Number.isFinite(nn) ? Math.max(acc, nn) : acc;
    }, 0);
    const number = `${prefix}${String(max + 1).padStart(2, '0')}`;
    await m.update(ContractEntity, c.id, { number });
    c.number = number;
    return number;
  }

  snapshot(c: ContractEntity) {
    return {
      number: c.number,
      status: c.status,
      types: c.types,
      startDate: c.startDate,
      endDate: c.endDate,
      repName: c.repName,
      repTitle: c.repTitle,
      repMobile: c.repMobile,
      paymentDays: c.paymentDays,
      settlementDiscountPct: c.settlementDiscountPct,
      fixedDiscountPct: c.fixedDiscountPct,
      newItemPrice: c.newItemPrice,
      specialTerms: c.specialTerms,
    };
  }

  // ───────────────────────── المرفقات ─────────────────────────

  async addDraftAttachment(user: AuthUser, id: number, kind: string, file: Express.Multer.File) {
    if (!file) throw new BadRequestException('اختر ملفاً للرفع.');
    const c = await this.mustGet(id, user);
    if (c.status !== Status.DRAFT) throw new WorkflowError('تُرفق الملفات والعقد مسودة.');
    if (c.createdById !== user.id && !user.caps.createAnyDepartment) throw new ForbiddenException('رفع الملفات لمنشئ المسودة فقط.');
    const allowed: string[] = [AttachmentKind.OTHER];
    if (c.entryPath === 'ready') allowed.push(AttachmentKind.READY_SIGNED);
    if (c.types.includes(ContractType.OPEN_ACCOUNT)) allowed.push(...ACCOUNT_DOC_KINDS);
    if (!allowed.includes(kind)) throw new BadRequestException({ message: 'نوع المرفق غير مناسب لهذا العقد.', errors: { kind: 'نوع المرفق غير مناسب لهذا العقد.' } });

    const stored = await this.storage.save(file);
    try {
      await this.contracts.manager.transaction(async (m) => {
        if (kind !== AttachmentKind.OTHER) {
          const old = await m.find(AttachmentEntity, { where: { contractId: id, kind } });
          old.forEach((o) => this.storage.remove(o.path));
          await m.delete(AttachmentEntity, { contractId: id, kind });
        }
        await m.save(m.create(AttachmentEntity, { contractId: id, addendumId: null, kind, fileName: stored.originalName, path: stored.relativePath, size: stored.size, uploadedById: user.id, uploadedAt: new Date() }));
        await this.audit.log({ user, action: 'رفع مرفق', entity: 'attachment', contractId: id, after: { kind, name: stored.originalName } }, m);
      });
    } catch (e) {
      this.storage.remove(stored.relativePath);
      throw e;
    }
    return this.detail(id, user);
  }

  async attachmentFile(user: AuthUser, contractId: number, attachmentId: number) {
    const c = await this.mustGet(contractId, user);
    const att = await this.attachments.findOne({ where: { id: attachmentId, contractId } });
    if (!att) throw new NotFoundException('المرفق غير موجود.');
    if (ACCOUNT_DOC_KINDS.includes(att.kind)) {
      if (!this.canSeeAccountData(user, c)) throw new ForbiddenException('مستندات فتح الحساب سرية.');
      await this.audit.log({ user, action: 'اطلاع على مستند فتح حساب', entity: 'attachment', contractId, note: ATTACHMENT_LABELS[att.kind] });
    }
    return { path: this.storage.absolutePath(att.path), name: att.fileName };
  }

  // ───────────────────────── القائمة ─────────────────────────

  /** هل ينتظر العقد إجراءً من هذا المستخدم الآن؟ */
  awaitingUser(user: AuthUser, c: ContractEntity, pendingAddendum: Set<number>): boolean {
    const role = user.role;
    if (c.status === Status.DRAFT && c.sentAt && c.createdById === user.id) return true;
    if (c.status === Status.UNDER_REVIEW) {
      if (c.party === Party.DEPARTMENT_MANAGER) return this.canReview(user, c);
      if (c.party === Party.CONTRACTS_MANAGER) return role === Role.CONTRACTS_MANAGER;
    }
    if (c.status === Status.AWAITING_SIGNATURE) return role === Role.PURCHASING_MANAGER;
    if (c.status === Status.ACTIVE && pendingAddendum.has(c.id)) return this.isDeptManagerOf(user, c);
    return false;
  }

  async list(user: AuthUser, q: Record<string, string | undefined>) {
    if (user.caps.operationsOnly) throw new ForbiddenException('قسم التشغيل يطّلع على صفحة التشغيل فقط.');
    const qb = this.contracts.createQueryBuilder('c').orderBy('c.id', 'DESC');
    if (user.role === Role.DEPARTMENT_OFFICIAL) qb.andWhere('c.departmentId = :d', { d: user.departmentId });
    if (q.status) qb.andWhere('c.status = :s', { s: q.status });
    if (q.departmentId) qb.andWhere('c.departmentId = :dep', { dep: Number(q.departmentId) });
    if (q.type) qb.andWhere('c.types LIKE :t', { t: `%${q.type}%` });
    let rows = await qb.getMany();

    const [vendors, depts, users] = await Promise.all([
      this.vendors.find(),
      this.departments.find(),
      this.users.find(),
    ]);
    const vMap = new Map(vendors.map((v) => [v.id, v]));
    const dMap = new Map(depts.map((d) => [d.id, d.name]));
    const uMap = new Map(users.map((u) => [u.id, u.fullName]));

    if (q.q?.trim()) {
      const needle = q.q.trim().toLowerCase();
      rows = rows.filter((c) => {
        const v = vMap.get(c.vendorId);
        return [c.number, v?.name, v?.vendorNumber].some((x) => x?.toLowerCase().includes(needle));
      });
    }
    const goalCounts = await this.goalCounts(rows.map((c) => c.id));
    if (q.discountType) {
      rows = rows.filter((c) => this.discountKinds(c, goalCounts.get(c.id) ?? 0).includes(q.discountType!));
    }
    const today = todayIso();
    if (q.expiringWithin) {
      const limit = Number(q.expiringWithin);
      rows = rows.filter((c) => c.status === Status.ACTIVE && c.endDate && daysBetween(today, c.endDate) >= 0 && daysBetween(today, c.endDate) <= limit);
    }
    if (q.alert === '1') rows = rows.filter((c) => c.hasAlert);

    const pending = await this.pendingAddendumSet();
    let mapped = rows.map((c) => this.toRow(c, vMap, dMap, uMap, goalCounts, pending, user));
    if (q.view === 'awaiting') mapped = mapped.filter((r) => r.awaitingUser);
    if (q.view === 'mine') mapped = mapped.filter((r) => r.createdById === user.id);
    return mapped;
  }

  private async pendingAddendumSet() {
    const rows = await this.addenda.find({ where: { status: 'pending' } });
    return new Set(rows.map((r) => r.contractId));
  }

  private async goalCounts(ids: number[]) {
    const map = new Map<number, number>();
    if (!ids.length) return map;
    const rows = await this.goalLines.createQueryBuilder('g').select('g.contractId', 'cid').addSelect('COUNT(g.id)', 'cnt').where('g.contractId IN (:...ids)', { ids }).groupBy('g.contractId').getRawMany();
    rows.forEach((r) => map.set(Number(r.cid), Number(r.cnt)));
    return map;
  }

  discountKinds(c: ContractEntity, goalCount: number): string[] {
    const kinds: string[] = [];
    if (num(c.fixedDiscountPct)) kinds.push('fixed');
    if (goalCount > 0) kinds.push('goals');
    if (num(c.settlementDiscountPct)) kinds.push('settlement');
    if (!kinds.length) kinds.push('none');
    return kinds;
  }

  toRow(c: ContractEntity, vMap: Map<number, VendorEntity>, dMap: Map<number, string>, uMap: Map<number, string>, goalCounts: Map<number, number>, pending: Set<number>, user: AuthUser) {
    const v = vMap.get(c.vendorId);
    const today = todayIso();
    return {
      id: c.id,
      number: c.number,
      vendorId: c.vendorId,
      vendorName: v?.name ?? '',
      vendorNumber: v?.vendorNumber ?? '',
      types: c.types,
      typeLabels: c.types.map((t) => TYPE_LABELS[t]),
      primaryType: c.primaryType,
      departmentId: c.departmentId,
      departmentName: dMap.get(c.departmentId) ?? '',
      status: c.status,
      statusLabel: STATUS_LABELS[c.status] ?? c.status,
      party: c.party,
      partyLabel: c.party ? PARTY_LABELS[c.party] : null,
      startDate: c.startDate,
      endDate: c.endDate,
      daysRemaining: c.endDate && [Status.ACTIVE, Status.AWAITING_SIGNATURE].includes(c.status as any) ? daysBetween(today, c.endDate) : null,
      hasAlert: c.hasAlert,
      waitingDays: daysSince(c.stateEnteredAt),
      discountKinds: this.discountKinds(c, goalCounts.get(c.id) ?? 0),
      createdById: c.createdById,
      createdByName: uMap.get(c.createdById) ?? '',
      entryPath: c.entryPath,
      awaitingUser: this.awaitingUser(user, c, pending),
    };
  }

  // ───────────────────────── التفاصيل ─────────────────────────

  async detail(id: number, user: AuthUser) {
    const c = await this.mustGet(id, user);
    const [vendor, dept, creator] = await Promise.all([
      this.vendors.findOne({ where: { id: c.vendorId } }),
      this.departments.findOne({ where: { id: c.departmentId } }),
      this.users.findOne({ where: { id: c.createdById } }),
    ]);
    const [rent, marketing, atts, addenda, stays, refs] = await Promise.all([
      this.rentLines.find({ where: { contractId: id }, order: { id: 'ASC' } }),
      this.marketingLines.find({ where: { contractId: id }, order: { id: 'ASC' } }),
      this.attachments.find({ where: { contractId: id }, order: { id: 'ASC' } }),
      this.addenda.find({ where: { contractId: id }, order: { no: 'ASC' } }),
      this.stays.find({ where: { contractId: id }, order: { id: 'ASC' } }),
      this.refs.find(),
    ]);
    const refName = new Map(refs.map((r) => [r.id, r.name]));
    const accountOk = this.canSeeAccountData(user, c);
    const rentTotal = rent.reduce((s, l) => s + Number(l.total), 0);
    const marketingTotal = marketing.reduce((s, l) => s + Number(l.price), 0);

    const progress = c.types.includes(ContractType.SUPPLY) && user.caps.canSeeGoals ? await this.goals.progressFor(c, vendor?.vendorNumber ?? '') : null;
    const termVersions = c.termVersions?.length
      ? await this.templates.versionsByIds(c.termVersions)
      : await this.templates.versionsByIds(await this.templates.activeVersionIdsFor(c.types));
    const users = new Map((await this.users.find()).map((u) => [u.id, u.fullName]));
    const pending = await this.pendingAddendumSet();

    const goalLinesCurrent = await this.goalLines.find({ where: { contractId: id }, order: { version: 'ASC', sort: 'ASC', id: 'ASC' } });

    return {
      id: c.id,
      number: c.number,
      vendor: vendor ? { id: vendor.id, name: vendor.name, vendorNumber: vendor.vendorNumber } : null,
      department: dept ? { id: dept.id, name: dept.name } : null,
      createdBy: creator?.fullName ?? '',
      createdById: c.createdById,
      entryPath: c.entryPath,
      types: c.types,
      typeLabels: c.types.map((t) => TYPE_LABELS[t]),
      primaryType: c.primaryType,
      servicesOnly: isServicesOnly(c.types),
      status: c.status,
      statusLabel: STATUS_LABELS[c.status] ?? c.status,
      party: c.party,
      partyLabel: c.party ? PARTY_LABELS[c.party] : null,
      waitingDays: daysSince(c.stateEnteredAt),
      stateEnteredAt: isoDateTime(c.stateEnteredAt),
      rep: { name: c.repName, title: c.repTitle, mobile: c.repMobile },
      startDate: c.startDate,
      endDate: c.endDate,
      daysRemaining: c.endDate ? daysBetween(todayIso(), c.endDate) : null,
      specialTerms: c.specialTerms,
      hasAlert: c.hasAlert,
      alertKind: c.alertKind,
      alertReason: c.alertReason,
      rejectReason: c.rejectReason,
      closeReason: c.closeReason,
      cancelReason: c.cancelReason,
      addendumCount: c.addendumCount,
      sentAt: isoDateTime(c.sentAt),
      activatedAt: isoDateTime(c.activatedAt),
      endedAt: isoDateTime(c.endedAt),
      cancelledAt: isoDateTime(c.cancelledAt),
      supply: c.types.includes(ContractType.SUPPLY)
        ? {
            paymentDays: c.paymentDays,
            settlementDiscountPct: num(c.settlementDiscountPct),
            fixedDiscountPct: num(c.fixedDiscountPct),
            goalLines: goalLinesCurrent
              .filter((g) => !g.activeTo)
              .map((g) => ({ id: g.id, fromDate: g.fromDate, toDate: g.toDate, target: Number(g.target), discountPct: Number(g.discountPct), version: g.version })),
          }
        : null,
      goals: progress,
      rentLines: rent.map((l) => ({ id: l.id, branchId: l.branchId, branch: refName.get(l.branchId) ?? '', shelfTypeId: l.shelfTypeId, shelf: refName.get(l.shelfTypeId) ?? '', qty: l.qty, price: Number(l.price), unit: l.unit, startDate: l.startDate, endDate: l.endDate, periods: l.periods, total: Number(l.total) })),
      rentTotal,
      marketingLines: marketing.map((l) => ({ id: l.id, eventId: l.eventId, event: refName.get(l.eventId) ?? '', price: Number(l.price), note: l.note })),
      marketingTotal,
      addItem: c.types.includes(ContractType.ADD_ITEM) ? { price: num(c.newItemPrice) } : null,
      openAccount: c.types.includes(ContractType.OPEN_ACCOUNT)
        ? { creditLimit: accountOk ? num(c.creditLimit) : null, confidential: !accountOk, documents: ACCOUNT_DOC_KINDS.map((k) => ({ kind: k, label: ATTACHMENT_LABELS[k], uploaded: atts.some((a) => a.kind === k) })) }
        : null,
      terms: {
        snapshot: !!c.termVersions?.length,
        clauses: termVersions.map((v) => ({ id: v.id, title: v.title, body: v.body, version: v.version })),
      },
      attachments: atts
        .filter((a) => !ACCOUNT_DOC_KINDS.includes(a.kind) || accountOk)
        .map((a) => ({ id: a.id, kind: a.kind, kindLabel: ATTACHMENT_LABELS[a.kind] ?? a.kind, fileName: a.fileName, size: a.size, uploadedBy: users.get(a.uploadedById) ?? '', uploadedAt: isoDateTime(a.uploadedAt), addendumId: a.addendumId })),
      addenda: addenda.map((a) => ({ id: a.id, no: a.no, effectiveDate: a.effectiveDate, description: a.description, changes: a.changes, status: a.status, returnReason: a.returnReason, createdBy: users.get(a.createdById) ?? '', createdAt: isoDateTime(a.createdAt), decidedBy: a.decidedById ? users.get(a.decidedById) ?? '' : null, decidedAt: isoDateTime(a.decidedAt) })),
      stays: stays.map((s) => ({ state: s.state, stateLabel: STATUS_LABELS[s.state], party: s.party, partyLabel: s.party ? PARTY_LABELS[s.party] : null, enteredAt: isoDateTime(s.enteredAt), leftAt: isoDateTime(s.leftAt) })),
      audit: user.caps.canViewAudit ? await this.contractAudit(id) : [],
      actions: this.actionsFor(user, c, atts, pending.has(c.id)),
    };
  }

  private async contractAudit(id: number) {
    const rows = await this.auditLogs.find({ where: { contractId: id }, order: { id: 'DESC' }, take: 300 });
    return rows.map((r) => ({ id: r.id, action: r.action, userName: r.userName, userRole: r.userRole, entity: r.entity, before: r.before, after: r.after, note: r.note, createdAt: isoDateTime(r.createdAt) }));
  }

  // ───────────────────────── الإجراءات المتاحة ─────────────────────────

  actionsFor(user: AuthUser, c: ContractEntity, atts: AttachmentEntity[], hasPendingAddendum: boolean): ContractAction[] {
    const out: ContractAction[] = [];
    const add = (a: ContractAction) => out.push(a);
    const role = user.role;
    const own = this.isOwnDepartment(user, c);
    const creatorOrAdmin = c.createdById === user.id || user.caps.createAnyDepartment;
    const deptMgr = this.isDeptManagerOf(user, c);
    const closeStates = [Status.ACTIVE, Status.ENDED, Status.CANCELLED] as string[];

    if (c.status === Status.DRAFT && creatorOrAdmin) {
      add({ key: 'edit', label: 'عدّل المسودة' });
      add({ key: 'send', label: 'أرسل للمراجعة', primary: true });
      if (!c.number && !c.sentAt) add({ key: 'delete', label: 'احذف المسودة', danger: true });
    }
    if (c.status === Status.UNDER_REVIEW && c.party === Party.DEPARTMENT_MANAGER && this.canReview(user, c)) {
      add({ key: 'dept_approve', label: c.entryPath === 'ready' ? 'أكّد المطابقة' : 'اعتمد', primary: true, reason: 'optional' });
      add({ key: 'dept_reject', label: c.entryPath === 'ready' ? 'غير مطابق — أعده للمسودة' : 'ارفض', danger: true, reason: 'required' });
    }
    if (c.status === Status.UNDER_REVIEW && c.party === Party.CONTRACTS_MANAGER && role === Role.CONTRACTS_MANAGER) {
      add({ key: 'cm_approve', label: 'اعتمد العقد', primary: true, reason: 'optional' });
      add({ key: 'cm_return', label: 'أرجع العقد', danger: true, reason: 'required' });
    }
    if (c.hasAlert && user.caps.canNoteOnAlert && ([Status.UNDER_REVIEW] as string[]).includes(c.status)) {
      add({ key: 'add_note', label: 'أضف ملاحظة', reason: 'required' });
    }
    const canAlert = user.caps.canRaiseAlert && (role !== Role.DEPARTMENT_MANAGER || own);
    if (canAlert && !c.hasAlert && ((c.status === Status.UNDER_REVIEW && c.party === Party.DEPARTMENT_MANAGER) || c.status === Status.AWAITING_SIGNATURE)) {
      add({ key: 'raise_alert', label: 'ارفع تنبيهاً', reason: 'required' });
    }
    if (c.status === Status.AWAITING_SIGNATURE && (role === Role.PURCHASING_MANAGER || deptMgr)) {
      add({ key: 'edit_pending', label: 'عدّل قبل التوقيع' });
    }
    if (c.status === Status.AWAITING_SIGNATURE && (role === Role.PURCHASING_MANAGER || (own && (role === Role.DEPARTMENT_MANAGER || role === Role.DEPARTMENT_OFFICIAL)))) {
      add({ key: 'upload_signed', label: 'ارفع النسخة الموقعة', primary: true });
    }
    if (c.status === Status.ACTIVE && (user.caps.createAnyDepartment || (own && (role === Role.DEPARTMENT_MANAGER || role === Role.DEPARTMENT_OFFICIAL)))) {
      add({ key: 'add_addendum', label: 'أضف ملحقاً' });
    }
    if (c.status === Status.ACTIVE && hasPendingAddendum && deptMgr) {
      add({ key: 'addendum_review', label: 'طابق الملحق', primary: true });
    }
    if (c.status === Status.ACTIVE && isServicesOnly(c.types) && (role === Role.CONTRACTS_MANAGER || deptMgr)) {
      add({ key: 'close_services', label: 'أغلق عقد الخدمات', danger: true, reason: 'required' });
    }
    if (([Status.AWAITING_SIGNATURE, Status.ACTIVE] as string[]).includes(c.status) && (role === Role.CONTRACTS_MANAGER || user.caps.createAnyDepartment)) {
      add({ key: 'cancel', label: 'ألغِ العقد', danger: true, reason: 'required' });
    }
    if (c.number || creatorOrAdmin) add({ key: 'print', label: 'اطبع العقد' });
    void closeStates;
    void atts;
    return out;
  }

  // ───────────────────────── الطباعة ─────────────────────────

  async printData(user: AuthUser, id: number): Promise<PrintData> {
    const c = await this.mustGet(id, user);
    if (c.entryPath === 'ready') throw new WorkflowError('العقد الجاهز لا يُولَّد؛ ملفه المرفوع هو العقد.');
    const [vendor, dept, rent, marketing, refs, doc] = await Promise.all([
      this.vendors.findOne({ where: { id: c.vendorId } }),
      this.departments.findOne({ where: { id: c.departmentId } }),
      this.rentLines.find({ where: { contractId: id } }),
      this.marketingLines.find({ where: { contractId: id } }),
      this.refs.find(),
      this.templates.currentDoc(),
    ]);
    const name = new Map(refs.map((r) => [r.id, r.name]));
    const goals = await this.goalLines.find({ where: { contractId: id, version: 0 }, order: { sort: 'ASC' } });
    const clauseIds = c.termVersions?.length ? c.termVersions : await this.templates.activeVersionIdsFor(c.types);
    const clauses = await this.templates.versionsByIds(clauseIds);
    await this.audit.log({ user, action: 'طباعة العقد', entity: 'contract', contractId: id });
    return {
      number: c.number,
      draft: c.status === Status.DRAFT,
      types: c.types,
      vendorName: vendor?.name ?? '',
      vendorNumber: vendor?.vendorNumber ?? '',
      departmentName: dept?.name ?? '',
      repName: c.repName,
      repTitle: c.repTitle,
      repMobile: c.repMobile,
      startDate: c.startDate,
      endDate: c.endDate,
      paymentDays: c.paymentDays,
      settlementDiscountPct: num(c.settlementDiscountPct),
      fixedDiscountPct: num(c.fixedDiscountPct),
      newItemPrice: num(c.newItemPrice),
      creditLimit: this.canSeeAccountData(user, c) ? num(c.creditLimit) : null,
      specialTerms: c.specialTerms,
      goalLines: goals.map((g) => ({ fromDate: g.fromDate, toDate: g.toDate, target: Number(g.target), discountPct: Number(g.discountPct) })),
      rentLines: rent.map((l) => ({ branch: name.get(l.branchId) ?? '', shelf: name.get(l.shelfTypeId) ?? '', qty: l.qty, price: Number(l.price), unit: l.unit, startDate: l.startDate, endDate: l.endDate, periods: l.periods, total: Number(l.total) })),
      marketingLines: marketing.map((l) => ({ event: name.get(l.eventId) ?? '', price: Number(l.price), note: l.note })),
      clauses: clauses.map((v) => ({ title: v.title, body: v.body })),
      template: { headerText: doc.headerText, footerText: doc.footerText, signatureLayout: doc.signatureLayout, hasLogo: !!doc.logoPath, hasBackground: !!doc.backgroundPath },
    };
  }

  /** نسخة قابلة للإدخال في النموذج (تحرير المسودة / تعديل بانتظار التوقيع). */
  async formValues(user: AuthUser, id: number) {
    const d = await this.detail(id, user);
    return {
      vendorId: d.vendor?.id,
      departmentId: d.department?.id,
      entryPath: d.entryPath,
      types: d.types,
      repName: d.rep.name,
      repTitle: d.rep.title,
      repMobile: d.rep.mobile,
      startDate: d.startDate,
      endDate: d.endDate,
      specialTerms: d.specialTerms,
      supply: d.supply ? { paymentDays: d.supply.paymentDays, settlementDiscountPct: d.supply.settlementDiscountPct, fixedDiscountPct: d.supply.fixedDiscountPct, goalLines: d.supply.goalLines } : undefined,
      rentLines: d.rentLines,
      marketingLines: d.marketingLines,
      addItem: d.addItem ?? undefined,
      openAccount: d.openAccount ? { creditLimit: d.openAccount.creditLimit } : undefined,
    };
  }

  /** مساعد: إنشاء استثناء إجراء. */
  fail(message: string, errors?: Record<string, string>): never {
    throw new WorkflowError(message, errors);
  }

  ids<T extends { id: number }>(rows: T[]) {
    return rows.map((r) => r.id);
  }

  in<T>(ids: T[]) {
    return In(ids as any);
  }
}
