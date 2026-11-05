import { BadRequestException, ForbiddenException, Injectable } from '@nestjs/common';
import { AuthUser } from '../auth/auth.service';
import { todayIso } from '../common/dates';
import { ACCOUNT_DOC_KINDS, AttachmentKind, ContractType, Party, Role, Status, TYPE_LABELS, isServicesOnly } from '../common/domain';
import { AttachmentEntity, ContractEntity, VendorEntity } from '../database/entities';
import { NotificationsService } from '../notifications/notifications.module';
import { ContractsService, WorkflowError } from './contracts.service';
import { validateContract } from './validation';

const reasonOf = (v: unknown, label = 'السبب') => {
  const text = typeof v === 'string' ? v.trim() : '';
  if (text.length < 3) throw new BadRequestException({ message: `${label} إلزامي (3 أحرف على الأقل).`, errors: { reason: `${label} إلزامي (3 أحرف على الأقل).` } });
  return text;
};

/**
 * مسار العمل (جدول الانتقالات 2.2.2 في الوثيقة). كل انتقال: تحقق من الجهة والصلاحية،
 * ثم تغيير الحالة والجهة الحالية، ثم تدقيق وإشعارات.
 */
@Injectable()
export class WorkflowService {
  constructor(
    private readonly core: ContractsService,
    private readonly notify: NotificationsService,
  ) {}

  private async load(user: AuthUser, id: number, status: string | string[]) {
    const c = await this.core.mustGet(id, user);
    const allowed = Array.isArray(status) ? status : [status];
    if (!allowed.includes(c.status)) {
      this.core.fail(`لا يمكن تنفيذ هذا الإجراء والعقد «${this.label(c.status)}».`);
    }
    return c;
  }

  private label(status: string) {
    return ({ draft: 'مسودة', under_review: 'تحت المراجعة', awaiting_signature: 'بانتظار التوقيع', active: 'نشط', ended: 'منتهٍ', cancelled: 'ملغي' } as Record<string, string>)[status] ?? status;
  }

  private audit(user: AuthUser | null, c: ContractEntity, action: string, extra: { before?: unknown; after?: unknown; note?: string } = {}) {
    return this.core.audit.log({ user, action, entity: 'contract', contractId: c.id, ...extra });
  }

  // ───────────────────────── 1) مسودة → تحت المراجعة ─────────────────────────

  async send(user: AuthUser, id: number) {
    const c = await this.load(user, id, Status.DRAFT);
    if (c.createdById !== user.id && !user.caps.createAnyDepartment) throw new ForbiddenException('إرسال العقد لمنشئه فقط.');

    // تحقق كامل (وضع الإرسال) من القيم المحفوظة.
    const form = await this.core.formValues(user, id);
    const { errors, clean } = validateContract(form as any, 'send', await this.core.refCtx());
    if (Object.keys(errors).length) {
      throw new BadRequestException({ message: 'أكمل الحقول المطلوبة لإرسال العقد.', errors });
    }
    const atts = await this.core.attachments.find({ where: { contractId: id } });
    const hasKind = (k: string) => atts.some((a) => a.kind === k);
    if (c.entryPath === 'ready' && !hasKind(AttachmentKind.READY_SIGNED)) {
      throw new BadRequestException({ message: 'ارفع ملف العقد الموقّع قبل الإرسال.', errors: { files: 'ارفع ملف العقد الموقّع.' } });
    }
    if (c.types.includes(ContractType.OPEN_ACCOUNT) && !ACCOUNT_DOC_KINDS.every(hasKind)) {
      throw new BadRequestException({ message: 'ارفع المستندات الخمسة لإرسال العقد.', errors: { files: 'ارفع المستندات الخمسة المطلوبة لفتح الحساب.' } });
    }

    const firstSend = !c.sentAt;
    const autoAlert = c.entryPath === 'new' && !!clean.specialTerms;
    await this.core.contracts.manager.transaction(async (m) => {
      const number = await this.core.assignNumber(m, c); // يُخصَّص عند أول إرسال فقط
      const termVersions = c.termVersions?.length ? c.termVersions : await this.core.templates.activeVersionIdsFor(c.types);
      await this.core.moveTo(m, c, Status.UNDER_REVIEW, Party.DEPARTMENT_MANAGER, {
        sentAt: c.sentAt ?? new Date(),
        rejectReason: '',
        termVersions,
        hasAlert: autoAlert ? true : c.hasAlert,
        alertKind: autoAlert ? 'auto' : c.alertKind,
        alertReason: autoAlert ? 'وُجد محتوى في «الشروط الخاصة».' : c.alertReason,
      });
      await this.audit(user, c, firstSend ? 'إرسال للمراجعة (وتخصيص الرقم)' : 'إعادة إرسال للمراجعة', { after: { number, alert: autoAlert } });
    });
    await this.notify.notify(c, 'review', 'أُرسل العقد للمراجعة.', { departmentManager: true, purchasingManager: true }, { actorId: user.id });
    return this.core.detail(id, user);
  }

  // ───────────────────────── مراجعة مدير القسم ─────────────────────────

  private assertDeptManager(user: AuthUser, c: ContractEntity) {
    if (!this.core.canReview(user, c)) throw new ForbiddenException('الاعتماد والرفض لمدير قسم هذا العقد أو لمدير المشتريات.');
    if (c.party !== Party.DEPARTMENT_MANAGER) this.core.fail('العقد ليس لدى مدير القسم الآن.');
  }

  async deptApprove(user: AuthUser, id: number, note?: string) {
    const c = await this.load(user, id, Status.UNDER_REVIEW);
    this.assertDeptManager(user, c);
    const goesToContractsManager = c.hasAlert;

    await this.core.contracts.manager.transaction(async (m) => {
      if (goesToContractsManager) {
        await this.core.moveTo(m, c, Status.UNDER_REVIEW, Party.CONTRACTS_MANAGER);
        await this.audit(user, c, 'اعتماد مدير القسم — انتقال لمدير قسم العقود (تنبيه قائم)', { note });
      } else if (c.entryPath === 'ready') {
        await this.core.moveTo(m, c, Status.ACTIVE, null, { activatedAt: new Date() });
        await this.audit(user, c, 'مطابقة العقد الجاهز — نشط', { note });
      } else {
        await this.core.moveTo(m, c, Status.AWAITING_SIGNATURE, Party.PURCHASING_MANAGER);
        await this.audit(user, c, 'اعتماد مدير القسم — بانتظار التوقيع', { note });
      }
    });

    if (goesToContractsManager) {
      await this.notify.notify(c, 'alert', 'عقد عليه تنبيه: ينتظر قرار مدير قسم العقود.', { contractsManager: true, contractsOfficer: true, departmentManager: true }, { actorId: user.id });
    } else if (c.entryPath === 'ready') {
      await this.notify.notify(c, 'activated', 'العقد الجاهز مطابق وأصبح نشطاً.', { creator: true, contractsManager: true, operations: true }, { actorId: user.id });
    } else {
      await this.notify.notify(c, 'approved', 'اعتُمد العقد وهو بانتظار التوقيع.', { purchasingManager: true, creator: true }, { actorId: user.id });
    }
    return this.core.detail(id, user);
  }

  async deptReject(user: AuthUser, id: number, reason: unknown) {
    const c = await this.load(user, id, Status.UNDER_REVIEW);
    this.assertDeptManager(user, c);
    const text = reasonOf(reason, 'سبب الرفض');
    await this.core.contracts.manager.transaction(async (m) => {
      await this.core.moveTo(m, c, Status.DRAFT, Party.CREATOR, { rejectReason: text });
      await this.audit(user, c, c.entryPath === 'ready' ? 'عدم مطابقة العقد الجاهز — مسودة' : 'رفض مدير القسم — مسودة', { note: text });
    });
    await this.notify.notify(c, 'rejected', `رُفض العقد: ${text}`, { creator: true }, { actorId: user.id });
    return this.core.detail(id, user);
  }

  // ───────────────────────── قرار مدير قسم العقود ─────────────────────────

  private assertContractsManager(user: AuthUser, c: ContractEntity) {
    if (user.role !== Role.CONTRACTS_MANAGER) throw new ForbiddenException('القرار في التنبيه لمدير قسم العقود فقط.');
    if (c.party !== Party.CONTRACTS_MANAGER) this.core.fail('العقد ليس لدى مدير قسم العقود الآن.');
  }

  async cmApprove(user: AuthUser, id: number, note?: string) {
    const c = await this.load(user, id, Status.UNDER_REVIEW);
    this.assertContractsManager(user, c);
    await this.core.contracts.manager.transaction(async (m) => {
      const patch = { hasAlert: false, alertKind: null, alertReason: '' };
      if (c.entryPath === 'ready') {
        await this.core.moveTo(m, c, Status.ACTIVE, null, { ...patch, activatedAt: new Date() });
      } else {
        await this.core.moveTo(m, c, Status.AWAITING_SIGNATURE, Party.PURCHASING_MANAGER, patch);
      }
      await this.audit(user, c, 'اعتماد مدير قسم العقود — إزالة التنبيه', { note, before: { alert: true }, after: { alert: false } });
    });
    if (c.entryPath === 'ready') {
      await this.notify.notify(c, 'activated', 'اعتُمد العقد الجاهز وأصبح نشطاً.', { creator: true, departmentManager: true, operations: true }, { actorId: user.id });
    } else {
      await this.notify.notify(c, 'approved', 'اعتُمد العقد وهو بانتظار التوقيع.', { purchasingManager: true, creator: true }, { actorId: user.id });
    }
    return this.core.detail(id, user);
  }

  async cmReturn(user: AuthUser, id: number, note: unknown) {
    const c = await this.load(user, id, Status.UNDER_REVIEW);
    this.assertContractsManager(user, c);
    const text = reasonOf(note, 'ملاحظة الإرجاع');
    await this.core.contracts.manager.transaction(async (m) => {
      await this.core.moveTo(m, c, Status.DRAFT, Party.CREATOR, { hasAlert: false, alertKind: null, alertReason: '', rejectReason: text });
      await this.audit(user, c, 'إرجاع مدير قسم العقود — مسودة (يُزال التنبيه)', { note: text });
    });
    await this.notify.notify(c, 'returned', `أُرجع العقد من مدير قسم العقود: ${text}`, { departmentManager: true, creator: true }, { actorId: user.id });
    return this.core.detail(id, user);
  }

  /** موظف قسم العقود (أو المدير) يضيف ملاحظة على عقد منبّه دون صلاحية القرار. */
  async addNote(user: AuthUser, id: number, text: unknown) {
    const c = await this.core.mustGet(id, user);
    if (!user.caps.canNoteOnAlert) throw new ForbiddenException('إضافة الملاحظات لقسم العقود.');
    if (!c.hasAlert) this.core.fail('الملاحظات على العقود المنبّهة فقط.');
    const note = reasonOf(text, 'الملاحظة');
    await this.audit(user, c, 'ملاحظة على عقد منبّه', { note });
    if (user.role === Role.CONTRACTS_OFFICER) await this.notify.notify(c, 'note', `ملاحظة من موظف قسم العقود: ${note}`, { contractsManager: true }, { actorId: user.id });
    return this.core.detail(id, user);
  }

  // ───────────────────────── التنبيه اليدوي ─────────────────────────

  async raiseAlert(user: AuthUser, id: number, reason: unknown) {
    const c = await this.load(user, id, [Status.UNDER_REVIEW, Status.AWAITING_SIGNATURE]);
    if (!user.caps.canRaiseAlert) throw new ForbiddenException('رفع التنبيه لمدير القسم والمشتريات وقسم العقود.');
    if (user.role === Role.DEPARTMENT_MANAGER && !this.core.isOwnDepartment(user, c)) throw new ForbiddenException('رفع التنبيه لعقود قسمك فقط.');
    if (c.status === Status.UNDER_REVIEW && c.party !== Party.DEPARTMENT_MANAGER) this.core.fail('العقد لدى مدير قسم العقود وعليه تنبيه بالفعل.');
    if (c.hasAlert) this.core.fail('العقد عليه تنبيه بالفعل.');
    const text = reasonOf(reason, 'سبب التنبيه');
    await this.core.contracts.manager.transaction(async (m) => {
      const patch = { hasAlert: true, alertKind: 'manual', alertReason: text };
      if (c.status === Status.AWAITING_SIGNATURE) await this.core.moveTo(m, c, Status.UNDER_REVIEW, Party.CONTRACTS_MANAGER, patch);
      else await m.update(ContractEntity, c.id, { ...patch, updatedAt: new Date() });
      await this.audit(user, c, 'رفع تنبيه يدوي', { note: text });
    });
    await this.notify.notify(c, 'alert', `رُفع تنبيه: ${text}`, { contractsManager: true, contractsOfficer: true, departmentManager: true }, { actorId: user.id });
    return this.core.detail(id, user);
  }

  // ───────────────────────── تعديل بانتظار التوقيع ─────────────────────────

  async editPending(user: AuthUser, id: number, body: Record<string, any>) {
    const c = await this.load(user, id, Status.AWAITING_SIGNATURE);
    if (user.role !== Role.PURCHASING_MANAGER && !this.core.isDeptManagerOf(user, c)) {
      throw new ForbiddenException('التعديل في هذه المرحلة لمدير المشتريات ومدير قسم العقد.');
    }
    const { errors, clean } = validateContract({ ...body, departmentId: c.departmentId, entryPath: c.entryPath }, 'send', await this.core.refCtx());
    if (Object.keys(errors).length) throw new BadRequestException({ message: 'تحقق من الحقول المعلّمة.', errors });

    const before = await this.core.formValues(user, id);
    const termsChanged = clean.specialTerms !== c.specialTerms;
    await this.core.contracts.manager.transaction(async (m) => {
      await m.update(ContractEntity, id, { ...this.core.columns(clean), updatedAt: new Date() });
      await this.core.writeLines(m, id, clean);
      const diff = this.diff(before as any, clean as any);
      await this.audit(user, c, 'تعديل بانتظار التوقيع', { before: diff.before, after: diff.after });
      if (termsChanged) {
        await this.core.moveTo(m, c, Status.UNDER_REVIEW, Party.CONTRACTS_MANAGER, { hasAlert: true, alertKind: 'auto', alertReason: 'تغيّرت «الشروط الخاصة» بعد الاعتماد.' });
        await this.audit(user, c, 'تنبيه آلي — تغيّرت الشروط الخاصة، انتقال لمدير قسم العقود');
      }
    });
    if (termsChanged) {
      await this.notify.notify(c, 'alert', 'تغيّرت الشروط الخاصة: العقد لدى مدير قسم العقود.', { contractsManager: true, contractsOfficer: true, departmentManager: true }, { actorId: user.id });
    }
    return this.core.detail(id, user);
  }

  /** فرق بين القيم القديمة والجديدة للتدقيق (الحقول التي تغيّرت فقط). */
  private diff(oldForm: Record<string, any>, clean: Record<string, any>) {
    const norm = (v: Record<string, any>) => ({
      types: v.types,
      startDate: v.startDate ?? null,
      endDate: v.endDate ?? null,
      repName: v.repName,
      repTitle: v.repTitle,
      repMobile: v.repMobile,
      specialTerms: v.specialTerms ?? '',
      paymentDays: v.supply?.paymentDays ?? null,
      settlementDiscountPct: v.supply?.settlementDiscountPct ?? null,
      fixedDiscountPct: v.supply?.fixedDiscountPct ?? null,
      newItemPrice: v.addItem?.price ?? null,
      creditLimit: v.openAccount?.creditLimit ?? null,
      goalLines: (v.supply?.goalLines ?? []).map((g: any) => [g.fromDate, g.toDate, Number(g.target), Number(g.discountPct)]),
      rentLines: (v.rentLines ?? []).map((l: any) => [l.branchId, l.shelfTypeId, l.qty, Number(l.price), l.unit, l.startDate, l.endDate]),
      marketingLines: (v.marketingLines ?? []).map((l: any) => [l.eventId, Number(l.price), l.note || '']),
    });
    const newForm = {
      ...clean,
      supply: { paymentDays: clean.paymentDays, settlementDiscountPct: clean.settlementDiscountPct, fixedDiscountPct: clean.fixedDiscountPct, goalLines: clean.goalLines },
      addItem: { price: clean.newItemPrice },
      openAccount: { creditLimit: clean.creditLimit },
    };
    const a = norm(oldForm) as Record<string, unknown>;
    const b = norm(newForm) as Record<string, unknown>;
    const before: Record<string, unknown> = {};
    const after: Record<string, unknown> = {};
    for (const k of Object.keys(a)) {
      if (JSON.stringify(a[k]) !== JSON.stringify(b[k])) {
        before[k] = a[k];
        after[k] = b[k];
      }
    }
    return { before, after };
  }

  // ───────────────────────── رفع النسخة الموقعة → نشط ─────────────────────────

  async uploadSigned(user: AuthUser, id: number, file: Express.Multer.File) {
    if (!file) throw new BadRequestException({ message: 'اختر ملف النسخة الموقعة.', errors: { file: 'اختر ملفاً.' } });
    const c = await this.load(user, id, Status.AWAITING_SIGNATURE);
    const own = this.core.isOwnDepartment(user, c) && (user.role === Role.DEPARTMENT_MANAGER || user.role === Role.DEPARTMENT_OFFICIAL);
    if (user.role !== Role.PURCHASING_MANAGER && !own) throw new ForbiddenException('رفع النسخة الموقعة لمدير المشتريات ولقسم العقد.');

    const stored = await this.core.storage.save(file);
    try {
      await this.core.contracts.manager.transaction(async (m) => {
        await m.save(m.create(AttachmentEntity, { contractId: id, addendumId: null, kind: AttachmentKind.SIGNED, fileName: stored.originalName, path: stored.relativePath, size: stored.size, uploadedById: user.id, uploadedAt: new Date() }));
        await this.core.moveTo(m, c, Status.ACTIVE, null, { activatedAt: new Date() });
        await this.audit(user, c, 'رفع النسخة الموقعة — نشط', { after: { file: stored.originalName } });
      });
    } catch (e) {
      this.core.storage.remove(stored.relativePath);
      throw e;
    }
    const notStarted = c.startDate && c.startDate > todayIso();
    await this.notify.notify(c, 'activated', `رُفعت النسخة الموقعة والعقد نشط${notStarted ? ' (لم يبدأ بعد)' : ''}.`, { creator: true, departmentManager: true, contractsManager: true, contractsOfficer: true, operations: true }, { actorId: user.id });
    return this.core.detail(id, user);
  }

  // ───────────────────────── إنهاء / إغلاق / إلغاء ─────────────────────────

  async closeServices(user: AuthUser, id: number, reason: unknown) {
    const c = await this.load(user, id, Status.ACTIVE);
    if (!isServicesOnly(c.types)) this.core.fail('الإغلاق اليدوي لعقود الخدمات فقط.');
    if (user.role !== Role.CONTRACTS_MANAGER && !this.core.isDeptManagerOf(user, c)) throw new ForbiddenException('الإغلاق لمدير قسم العقد أو مدير قسم العقود.');
    const text = reasonOf(reason, 'سبب الإغلاق');
    await this.core.contracts.manager.transaction(async (m) => {
      await this.core.moveTo(m, c, Status.ENDED, null, { endedAt: new Date(), closeReason: text });
      await this.audit(user, c, 'إغلاق عقد خدمات يدوياً', { note: text });
    });
    await this.notify.notify(c, 'ended', `أُغلق عقد الخدمات: ${text}`, { creator: true, departmentManager: true }, { actorId: user.id });
    return this.core.detail(id, user);
  }

  async cancel(user: AuthUser, id: number, reason: unknown) {
    const c = await this.load(user, id, [Status.AWAITING_SIGNATURE, Status.ACTIVE]);
    if (user.role !== Role.CONTRACTS_MANAGER && !user.caps.createAnyDepartment) throw new ForbiddenException('الإلغاء لمدير قسم العقود (ولمسؤول النظام احتياطاً).');
    const text = reasonOf(reason, 'سبب الإلغاء');
    await this.core.contracts.manager.transaction(async (m) => {
      await this.core.moveTo(m, c, Status.CANCELLED, null, { cancelledAt: new Date(), cancelReason: text });
      await this.audit(user, c, user.role === Role.CONTRACTS_MANAGER ? 'إلغاء عقد معتمد' : 'إلغاء عقد معتمد (احتياطاً من مسؤول النظام)', { note: text });
    });
    await this.notify.notify(c, 'cancelled', `أُلغي العقد: ${text}`, { everyone: true }, { actorId: user.id });
    return this.core.detail(id, user);
  }

  // ───────────────────────── الانتهاء التلقائي (يومياً) ─────────────────────────

  /** العقود النشطة التي تجاوزت تاريخ نهايتها تصبح «منتهي». لا ينطبق على عقود الخدمات (لا نهاية لها). */
  async autoEndExpired(): Promise<number> {
    const today = todayIso();
    const rows = await this.core.contracts
      .createQueryBuilder('c')
      .where('c.status = :s AND c.endDate IS NOT NULL AND c.endDate < :t', { s: Status.ACTIVE, t: today })
      .getMany();
    let count = 0;
    for (const c of rows) {
      if (isServicesOnly(c.types)) continue;
      await this.core.contracts.manager.transaction(async (m) => {
        await this.core.moveTo(m, c, Status.ENDED, null, { endedAt: new Date() });
        await this.core.audit.log({ user: null, action: 'انتهاء العقد تلقائياً (تجاوز تاريخ النهاية)', entity: 'contract', contractId: c.id }, m);
      });
      await this.notify.notify(c, 'ended', 'انتهى العقد بتجاوز تاريخ النهاية.', { creator: true, departmentManager: true });
      count++;
    }
    return count;
  }

  vendorOf(c: ContractEntity) {
    return this.core.vendors.findOne({ where: { id: c.vendorId } }) as Promise<VendorEntity | null>;
  }

  typeNames(c: ContractEntity) {
    return c.types.map((t) => TYPE_LABELS[t]).join('، ');
  }

  fail(message: string): never {
    throw new WorkflowError(message);
  }
}

