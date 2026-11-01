import { BadRequestException, ForbiddenException, Injectable, NotFoundException } from '@nestjs/common';
import { AuthUser } from '../auth/auth.service';
import { addDays, isIsoDate } from '../common/dates';
import { AttachmentKind, ContractType, Role, Status } from '../common/domain';
import { AddendumEntity, AttachmentEntity, ContractEntity as ContractEntityTable, GoalLineEntity } from '../database/entities';
import { NotificationsService } from '../notifications/notifications.module';
import { ContractsService } from './contracts.service';
import { validateContract } from './validation';

/** البنود التي يجوز تعديلها بملحق. إضافة أرفف أو فعاليات = عقد جديد وليست ملحقاً. */
const EDITABLE = ['paymentDays', 'settlementDiscountPct', 'fixedDiscountPct', 'goalLines', 'specialTerms', 'repName', 'repTitle', 'repMobile', 'newItemPrice'] as const;

const same = (a: unknown, b: unknown) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);

@Injectable()
export class AddendaService {
  constructor(
    private readonly core: ContractsService,
    private readonly notify: NotificationsService,
  ) {}

  private canCreate(user: AuthUser, departmentId: number) {
    if (user.caps.createAnyDepartment) return true;
    return (user.role === Role.DEPARTMENT_MANAGER || user.role === Role.DEPARTMENT_OFFICIAL) && user.departmentId === departmentId;
  }

  /** القيم الحالية للبنود القابلة للتعديل. */
  private async currentValues(id: number) {
    const c = await this.core.contracts.findOne({ where: { id } });
    const goals = await this.core.goalLines.find({ where: { contractId: id }, order: { version: 'ASC', sort: 'ASC', id: 'ASC' } });
    return {
      paymentDays: c!.paymentDays,
      settlementDiscountPct: c!.settlementDiscountPct == null ? null : Number(c!.settlementDiscountPct),
      fixedDiscountPct: c!.fixedDiscountPct == null ? null : Number(c!.fixedDiscountPct),
      goalLines: goals.filter((g) => !g.activeTo).map((g) => ({ fromDate: g.fromDate, toDate: g.toDate, target: Number(g.target), discountPct: Number(g.discountPct) })),
      specialTerms: c!.specialTerms,
      repName: c!.repName,
      repTitle: c!.repTitle,
      repMobile: c!.repMobile,
      newItemPrice: c!.newItemPrice == null ? null : Number(c!.newItemPrice),
    } as Record<string, unknown>;
  }

  async create(user: AuthUser, id: number, body: Record<string, any>, file: Express.Multer.File) {
    const c = await this.core.mustGet(id, user);
    if (c.status !== Status.ACTIVE) this.core.fail('الملحق يُسجَّل على عقد نشط فقط.');
    if (!this.canCreate(user, c.departmentId)) throw new ForbiddenException('تسجيل الملحق لمسؤول ومدير قسم العقد.');
    if (!file) throw new BadRequestException({ message: 'ارفع الملحق الموقّع.', errors: { file: 'الملحق الموقّع إلزامي.' } });

    const effectiveDate = String(body.effectiveDate ?? '');
    const errors: Record<string, string> = {};
    if (!isIsoDate(effectiveDate)) errors.effectiveDate = 'تاريخ السريان مطلوب.';
    else if ((c.startDate && effectiveDate < c.startDate) || (c.endDate && effectiveDate > c.endDate)) errors.effectiveDate = 'تاريخ السريان ضمن مدة العقد.';

    let proposed: Record<string, any> = {};
    try {
      proposed = typeof body.changes === 'string' ? JSON.parse(body.changes) : body.changes ?? {};
    } catch {
      errors.changes = 'صيغة التعديلات غير صالحة.';
    }
    const current = await this.currentValues(id);
    const changed: string[] = [];
    for (const k of EDITABLE) {
      if (k in proposed && !same(proposed[k], current[k])) changed.push(k);
    }
    if (!changed.length && !errors.changes) errors.changes = 'عدّل بنداً واحداً على الأقل.';
    if (c.types.includes(ContractType.SUPPLY) === false && (changed.includes('paymentDays') || changed.includes('goalLines') || changed.includes('fixedDiscountPct') || changed.includes('settlementDiscountPct'))) {
      errors.changes = 'بنود التوريد غير موجودة في هذا العقد.';
    }

    // التحقق بنفس قواعد العقد بعد دمج التعديلات.
    if (!Object.keys(errors).length) {
      const merged = { ...current, ...Object.fromEntries(changed.map((k) => [k, proposed[k]])) } as Record<string, any>;
      const form = await this.core.formValues(user, id);
      const probe = {
        ...form,
        repName: merged.repName,
        repTitle: merged.repTitle,
        repMobile: merged.repMobile,
        specialTerms: merged.specialTerms,
        supply: form.supply ? { paymentDays: merged.paymentDays, settlementDiscountPct: merged.settlementDiscountPct, fixedDiscountPct: merged.fixedDiscountPct, goalLines: merged.goalLines } : undefined,
        addItem: form.addItem ? { price: merged.newItemPrice } : undefined,
      };
      const res = validateContract(probe as any, 'send', await this.core.refCtx());
      Object.assign(errors, res.errors);
    }
    if (Object.keys(errors).length) throw new BadRequestException({ message: 'تحقق من الحقول المعلّمة.', errors });

    const stored = await this.core.storage.save(file);
    try {
      const addendum = await this.core.contracts.manager.transaction(async (m) => {
        const row = await m.save(
          m.create(AddendumEntity, {
            contractId: id,
            no: 0,
            effectiveDate,
            description: String(body.description ?? '').trim(),
            changes: { before: Object.fromEntries(changed.map((k) => [k, current[k]])), after: Object.fromEntries(changed.map((k) => [k, proposed[k]])) },
            status: 'pending',
            returnReason: '',
            createdById: user.id,
            createdAt: new Date(),
            decidedById: null,
            decidedAt: null,
          }),
        );
        await m.save(m.create(AttachmentEntity, { contractId: id, addendumId: row.id, kind: AttachmentKind.ADDENDUM_SIGNED, fileName: stored.originalName, path: stored.relativePath, size: stored.size, uploadedById: user.id, uploadedAt: new Date() }));
        await this.core.audit.log({ user, action: 'تسجيل ملحق (بانتظار المطابقة)', entity: 'addendum', contractId: id, before: row.changes.before, after: row.changes.after, note: `سريان ${effectiveDate}` }, m);
        return row;
      });
      await this.notify.notify(c, 'addendum', `أُضيف ملحق (سريان ${effectiveDate}) بانتظار مطابقة مدير القسم.`, { contractsManager: true, contractsOfficer: true, departmentManager: true }, { actorId: user.id });
      void addendum;
    } catch (e) {
      this.core.storage.remove(stored.relativePath);
      throw e;
    }
    return this.core.detail(id, user);
  }

  /** مدير القسم يطابق التعديلات مع الملف الموقع: يطبّق من تاريخ السريان أو يُعيد الملحق بسبب. */
  async review(user: AuthUser, contractId: number, addendumId: number, body: { approve?: boolean; reason?: string }) {
    const c = await this.core.mustGet(contractId, user);
    if (!this.core.isDeptManagerOf(user, c)) throw new ForbiddenException('مطابقة الملحق لمدير قسم العقد.');
    const a = await this.core.addenda.findOne({ where: { id: addendumId, contractId } });
    if (!a) throw new NotFoundException('الملحق غير موجود.');
    if (a.status !== 'pending') this.core.fail('الملحق ليس بانتظار المطابقة.');

    if (!body.approve) {
      const reason = String(body.reason ?? '').trim();
      if (reason.length < 3) throw new BadRequestException({ message: 'سبب الإعادة إلزامي.', errors: { reason: 'سبب الإعادة إلزامي.' } });
      await this.core.contracts.manager.transaction(async (m) => {
        await m.update(AddendumEntity, a.id, { status: 'returned', returnReason: reason, decidedById: user.id, decidedAt: new Date() });
        await this.core.audit.log({ user, action: 'إعادة الملحق للتصحيح', entity: 'addendum', contractId, note: reason }, m);
      });
      await this.notifyAddendumCreator(c.id, a.createdById, `أُعيد الملحق للتصحيح: ${reason}`);
      return this.core.detail(contractId, user);
    }

    const changes = a.changes as { before: Record<string, any>; after: Record<string, any> };
    await this.core.contracts.manager.transaction(async (m) => {
      const no = c.addendumCount + 1;
      const patch: Record<string, unknown> = {};
      for (const [k, v] of Object.entries(changes.after)) {
        if (k === 'goalLines') continue;
        if (k === 'settlementDiscountPct' || k === 'fixedDiscountPct' || k === 'newItemPrice') patch[k] = v == null ? null : Number(v).toFixed(2);
        else patch[k] = v;
      }
      patch.addendumCount = no;
      patch.updatedAt = new Date();
      await m.update(ContractEntityTable, c.id, patch);

      if (changes.after.goalLines) {
        const dayBefore = addDays(a.effectiveDate, -1);
        await m
          .createQueryBuilder()
          .update(GoalLineEntity)
          .set({ activeTo: dayBefore })
          .where('contractId = :id AND activeTo IS NULL', { id: c.id })
          .execute();
        await m.save(
          (changes.after.goalLines as any[]).map((g, i) =>
            m.create(GoalLineEntity, {
              contractId: c.id,
              fromDate: g.fromDate,
              toDate: g.toDate,
              target: Number(g.target).toFixed(2),
              discountPct: Number(g.discountPct).toFixed(2),
              version: no,
              activeFrom: a.effectiveDate,
              activeTo: null,
              sort: i,
              fixedStatus: null,
              fixedNet: null,
              fixedDiscount: null,
            }),
          ),
        );
      }
      await m.update(AddendumEntity, a.id, { status: 'applied', no, decidedById: user.id, decidedAt: new Date() });
      await this.core.audit.log({ user, action: `تطبيق الملحق ${no}`, entity: 'addendum', contractId, before: changes.before, after: changes.after, note: `سريان ${a.effectiveDate}` }, m);
    });
    await this.notifyAddendumCreator(c.id, a.createdById, `طُبّق الملحق (سريان ${a.effectiveDate}).`);
    return this.core.detail(contractId, user);
  }

  private async notifyAddendumCreator(contractId: number, userId: number, text: string) {
    const c = await this.core.contracts.findOne({ where: { id: contractId } });
    if (!c) return;
    const creator = await this.core.users.findOne({ where: { id: userId } });
    if (!creator) return;
    // يصل «منشئ الملحق» نفسه (قد يختلف عن منشئ العقد).
    await this.notify.notify({ ...c, createdById: creator.id } as any, 'addendum', text, { creator: true });
  }
}

