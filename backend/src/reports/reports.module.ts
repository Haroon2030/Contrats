import {
  BadRequestException,
  Body,
  Controller,
  ForbiddenException,
  Get,
  Headers,
  Injectable,
  Module,
  Post,
  Query,
  Res,
  ServiceUnavailableException,
  UnauthorizedException,
} from '@nestjs/common';
import { InjectRepository } from '@nestjs/typeorm';
import { Response } from 'express';
import { Repository } from 'typeorm';
import { AuditService } from '../audit/audit.service';
import { AuthUser } from '../auth/auth.service';
import { CurrentUser, Public } from '../common/auth.guard';
import { daysBetween, isIsoDate, todayIso } from '../common/dates';
import { ContractType, Role, STATUS_LABELS, Status, TYPE_LABELS } from '../common/domain';
import { ContractsModule } from '../contracts/contracts.module';
import { ContractsService } from '../contracts/contracts.service';
import { exportGoalsXlsx } from '../contracts/export';
import { ContractEntity, MarketingLineEntity, RefValueEntity, RentLineEntity, VendorEntity } from '../database/entities';
import { GoalsModule } from '../goals/goals.module';
import { GoalsService } from '../goals/goals.service';
import { JobsService } from '../jobs/jobs.service';
import { SettingsService } from '../reference/reference.module';

const periodKind = (from: string, to: string) => {
  const days = daysBetween(from, to) + 1;
  return days <= 32 ? 'month' : days <= 95 ? 'quarter' : 'year';
};

@Injectable()
export class ReportsService {
  constructor(
    private readonly contracts: ContractsService,
    private readonly goals: GoalsService,
    @InjectRepository(ContractEntity) private readonly contractRepo: Repository<ContractEntity>,
    @InjectRepository(VendorEntity) private readonly vendors: Repository<VendorEntity>,
  ) {}

  /** سطور الأهداف لكل عقود توريد مرئية للمستخدم (نشطة أو منتهية). */
  async goalRows(user: AuthUser, f: { departmentId?: string; vendorId?: string; period?: string; status?: string } = {}) {
    if (!user.caps.canSeeGoals) throw new ForbiddenException('أداء الأهداف غير متاح لدورك.');
    const visible = (await this.contracts.list(user, { departmentId: f.departmentId })).filter(
      (r) => r.types.includes(ContractType.SUPPLY) && ([Status.ACTIVE, Status.ENDED] as string[]).includes(r.status) && (!f.vendorId || r.vendorId === Number(f.vendorId)),
    );
    const rows: any[] = [];
    for (const r of visible) {
      const c = await this.contractRepo.findOne({ where: { id: r.id } });
      if (!c) continue;
      const progress = await this.goals.progressFor(c, r.vendorNumber);
      for (const l of progress.lines) {
        if (l.activeTo && l.activeTo < l.fromDate) continue;
        if (f.period && periodKind(l.fromDate, l.toDate) !== f.period) continue;
        if (f.status && l.status !== f.status) continue;
        rows.push({ ...l, contractId: r.id, number: r.number, vendorId: r.vendorId, vendorName: r.vendorName, departmentId: r.departmentId, departmentName: r.departmentName });
      }
    }
    const sum = (key: 'departmentName' | 'vendorName') => {
      const map = new Map<string, number>();
      rows.forEach((x) => map.set(x[key], (map.get(x[key]) ?? 0) + x.estimatedDiscount));
      return [...map.entries()].map(([name, estimatedDiscount]) => ({ name, estimatedDiscount: Math.round(estimatedDiscount * 100) / 100 })).sort((a, b) => b.estimatedDiscount - a.estimatedDiscount);
    };
    return {
      rows,
      totals: { estimatedDiscount: Math.round(rows.reduce((s, x) => s + x.estimatedDiscount, 0) * 100) / 100, byDepartment: sum('departmentName'), byVendor: sum('vendorName') },
      lastUpdate: await this.goals.lastUpdate(),
    };
  }
}

@Controller('goals')
export class GoalsController {
  constructor(
    private readonly reports: ReportsService,
    private readonly goals: GoalsService,
    private readonly audit: AuditService,
  ) {}

  @Get()
  list(@CurrentUser() user: AuthUser, @Query() q: Record<string, string>) {
    return this.reports.goalRows(user, q);
  }

  @Get('export')
  async export(@CurrentUser() user: AuthUser, @Query() q: Record<string, string>, @Res() res: Response) {
    const data = await this.reports.goalRows(user, q);
    await this.audit.log({ user, action: 'تصدير تقرير الأهداف', entity: 'goals', note: `${data.rows.length} سطر` });
    res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    res.setHeader('Content-Disposition', `attachment; filename="goals-${new Date().toISOString().slice(0, 10)}.xlsx"`);
    res.send(await exportGoalsXlsx(data.rows));
  }

  /** استيراد يدوي لسجلات المشتريات (مسؤول النظام). المصدر الدوري هو واجهة التكامل. */
  @Post('purchases')
  async importPurchases(@CurrentUser() user: AuthUser, @Body() body: { records?: any[] }) {
    if (!user.caps.canManageRefs) throw new ForbiddenException('استيراد المشتريات لمسؤول النظام.');
    const records = this.parse(body.records);
    const result = await this.goals.importPurchases(records);
    await this.audit.log({ user, action: 'استيراد سجلات مشتريات', entity: 'purchases', note: `${result.created} جديد، ${result.updated} محدّث` });
    return result;
  }

  private parse(records: any[] | undefined) {
    if (!Array.isArray(records) || !records.length) throw new BadRequestException({ message: 'لا توجد سجلات.', errors: { records: 'لا توجد سجلات.' } });
    return records.map((r, i) => {
      if (!r?.vendorNumber || !isIsoDate(String(r.date)) || !Number.isFinite(Number(r.purchases))) {
        throw new BadRequestException({ message: `سجل غير صالح في السطر ${i + 1} (رقم المورد، التاريخ YYYY-MM-DD، المشتريات).`, errors: { records: `سجل غير صالح في السطر ${i + 1}.` } });
      }
      return { vendorNumber: String(r.vendorNumber), date: String(r.date), purchases: Number(r.purchases), returns: Number(r.returns ?? 0), department: r.department ?? null };
    });
  }
}

/**
 * واجهة التكامل مع النظام الحالي (المصدر الوحيد للموردين والمشتريات).
 * محمية بمفتاح INTEGRATION_KEY في الترويسة x-api-key.
 */
@Controller('integration')
export class IntegrationController {
  constructor(
    private readonly goals: GoalsService,
    @InjectRepository(VendorEntity) private readonly vendors: Repository<VendorEntity>,
    private readonly audit: AuditService,
  ) {}

  private check(key?: string) {
    const expected = process.env.INTEGRATION_KEY;
    if (!expected) throw new ServiceUnavailableException('واجهة التكامل غير مفعّلة (INTEGRATION_KEY).');
    if (key !== expected) throw new UnauthorizedException('مفتاح التكامل غير صحيح.');
  }

  @Public()
  @Post('purchases')
  async purchases(@Headers('x-api-key') key: string, @Body() body: { records?: any[] }) {
    this.check(key);
    const records = (body.records ?? []).filter((r) => r?.vendorNumber && isIsoDate(String(r.date)));
    const result = await this.goals.importPurchases(records.map((r) => ({ vendorNumber: String(r.vendorNumber), date: String(r.date), purchases: Number(r.purchases), returns: Number(r.returns ?? 0), department: r.department ?? null })));
    await this.audit.log({ user: null, action: 'استلام مشتريات من النظام الحالي', entity: 'purchases', note: `${result.created} جديد، ${result.updated} محدّث` });
    return result;
  }

  @Public()
  @Post('vendors')
  async vendorSync(@Headers('x-api-key') key: string, @Body() body: { vendors?: { vendorNumber: string; name: string }[] }) {
    this.check(key);
    let created = 0;
    let updated = 0;
    for (const v of body.vendors ?? []) {
      if (!v?.vendorNumber || !v?.name) continue;
      const existing = await this.vendors.findOne({ where: { vendorNumber: String(v.vendorNumber) } });
      if (existing) {
        if (existing.name !== v.name) {
          existing.name = String(v.name);
          await this.vendors.save(existing);
          updated++;
        }
      } else {
        await this.vendors.save(this.vendors.create({ vendorNumber: String(v.vendorNumber), name: String(v.name), isActive: true }));
        created++;
      }
    }
    await this.audit.log({ user: null, action: 'مزامنة الموردين من النظام الحالي', entity: 'vendor', note: `${created} جديد، ${updated} محدّث` });
    return { created, updated };
  }
}

@Controller('dashboard')
export class DashboardController {
  constructor(
    private readonly contracts: ContractsService,
    private readonly reports: ReportsService,
    private readonly settings: SettingsService,
  ) {}

  /** لوحة التحكم حسب نطاق رؤية المستخدم؛ الضغط على أي رقم يفتح السجل مصفّى. */
  @Get()
  async summary(@CurrentUser() user: AuthUser) {
    if (user.caps.operationsOnly) return { role: user.role, operationsOnly: true };
    const rows = await this.contracts.list(user, {});
    const thresholds = await this.settings.get();
    const today = todayIso();
    const active = rows.filter((r) => r.status === Status.ACTIVE);
    const expiring = [30, 60, 90].map((days) => ({
      days,
      count: active.filter((r) => r.endDate && daysBetween(today, r.endDate) >= 0 && daysBetween(today, r.endDate) <= days).length,
    }));
    const waiting = rows.filter((r) => ([Status.UNDER_REVIEW, Status.AWAITING_SIGNATURE] as string[]).includes(r.status) || (r.status === Status.DRAFT && r.createdByName && r.party === 'creator' && r.awaitingUser));
    const late = waiting.filter((r) => r.waitingDays >= thresholds.escalationDays);

    const byStatus = Object.keys(STATUS_LABELS).map((status) => ({ status, label: STATUS_LABELS[status], count: rows.filter((r) => r.status === status).length }));
    const byType = Object.keys(TYPE_LABELS).map((type) => ({ type, label: TYPE_LABELS[type], count: rows.filter((r) => r.types.includes(type)).length }));

    let goals: any = null;
    if (user.caps.canSeeGoals) {
      const g = await this.reports.goalRows(user, {});
      goals = { estimatedDiscount: g.totals.estimatedDiscount, byDepartment: g.totals.byDepartment, byVendor: g.totals.byVendor.slice(0, 8), lastUpdate: g.lastUpdate, linesCount: g.rows.length };
    }
    return {
      total: rows.length,
      byStatus,
      byType,
      fixedDiscount: rows.filter((r) => r.discountKinds.includes('fixed')).length,
      expiring,
      alerts: rows.filter((r) => r.hasAlert).length,
      late: late.length,
      awaiting: rows.filter((r) => r.awaitingUser).slice(0, 10),
      awaitingCount: rows.filter((r) => r.awaitingUser).length,
      goals,
      thresholds,
    };
  }
}

@Controller('operations')
export class OperationsController {
  constructor(
    @InjectRepository(ContractEntity) private readonly contracts: Repository<ContractEntity>,
    @InjectRepository(RentLineEntity) private readonly rent: Repository<RentLineEntity>,
    @InjectRepository(MarketingLineEntity) private readonly marketing: Repository<MarketingLineEntity>,
    @InjectRepository(RefValueEntity) private readonly refs: Repository<RefValueEntity>,
    @InjectRepository(VendorEntity) private readonly vendors: Repository<VendorEntity>,
  ) {}

  /**
   * صفحة التشغيل: سطور الإيجار الحالية والقادمة (المنتهي يختفي ولو بقي العقد نشطاً)،
   * والفعاليات في العقود النشطة دون تواريخ. لا تظهر بنود التوريد ولا الخدمات.
   */
  @Get()
  async view(@CurrentUser() user: AuthUser, @Query('branchId') branchId?: string, @Query('from') from?: string, @Query('to') to?: string) {
    const allowed: string[] = [Role.OPERATIONS, Role.SYSTEM_ADMIN, Role.CONTRACTS_MANAGER, Role.CONTRACTS_OFFICER, Role.PURCHASING_MANAGER];
    if (!allowed.includes(user.role)) throw new ForbiddenException('صفحة التشغيل لقسم التشغيل.');
    const today = todayIso();
    const active = await this.contracts.find({ where: { status: Status.ACTIVE } });
    const ids = active.map((c) => c.id);
    if (!ids.length) return { rent: [], marketing: [] };
    const vendors = new Map((await this.vendors.find()).map((v) => [v.id, v.name]));
    const refs = new Map((await this.refs.find()).map((r) => [r.id, r.name]));
    const contractOf = new Map(active.map((c) => [c.id, c]));

    const rentRows = (await this.rent.createQueryBuilder('l').where('l.contractId IN (:...ids) AND l.endDate >= :t', { ids, t: today }).orderBy('l.startDate', 'ASC').getMany())
      .filter((l) => (!branchId || l.branchId === Number(branchId)) && (!from || l.endDate >= from) && (!to || l.startDate <= to))
      .map((l) => ({ id: l.id, contractNumber: contractOf.get(l.contractId)?.number ?? '', vendor: vendors.get(contractOf.get(l.contractId)!.vendorId) ?? '', branchId: l.branchId, branch: refs.get(l.branchId) ?? '', shelf: refs.get(l.shelfTypeId) ?? '', qty: l.qty, price: Number(l.price), unit: l.unit, startDate: l.startDate, endDate: l.endDate, upcoming: l.startDate > today }));
    const marketingRows = (await this.marketing.createQueryBuilder('l').where('l.contractId IN (:...ids)', { ids }).getMany()).map((l) => ({
      id: l.id,
      contractNumber: contractOf.get(l.contractId)?.number ?? '',
      vendor: vendors.get(contractOf.get(l.contractId)!.vendorId) ?? '',
      event: refs.get(l.eventId) ?? '',
      price: Number(l.price),
      note: l.note,
    }));
    return { rent: rentRows, marketing: marketingRows };
  }
}

@Controller('system')
export class SystemController {
  constructor(private readonly jobs: JobsService) {}

  /** تشغيل المهام الدورية يدوياً (مسؤول النظام): الانتهاء والتذكير والتصعيد وتثبيت الأهداف. */
  @Post('run-jobs')
  run(@CurrentUser() user: AuthUser) {
    if (!user.caps.canManageUsers) throw new ForbiddenException('لمسؤول النظام.');
    return this.jobs.runAll();
  }
}

@Module({
  imports: [ContractsModule, GoalsModule],
  controllers: [GoalsController, IntegrationController, DashboardController, OperationsController, SystemController],
  providers: [ReportsService, JobsService],
})
export class ReportsModule {}
