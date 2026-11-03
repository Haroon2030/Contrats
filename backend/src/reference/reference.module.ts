import {
  BadRequestException,
  Body,
  Controller,
  ForbiddenException,
  Get,
  Global,
  Injectable,
  Module,
  NotFoundException,
  Param,
  ParseIntPipe,
  Patch,
  Post,
  Put,
  Query,
} from '@nestjs/common';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository } from 'typeorm';
import { AuditService } from '../audit/audit.service';
import { AuthUser } from '../auth/auth.service';
import { CurrentUser } from '../common/auth.guard';
import { REF_LIST_LABELS, RefList, SETTING_DEFAULTS } from '../common/domain';
import { DepartmentEntity, RefValueEntity, SettingEntity, VendorEntity } from '../database/entities';

interface VendorBody {
  vendorNumber?: string;
  name?: string;
  commercialRegister?: string;
  taxNumber?: string;
  mobile?: string;
  email?: string;
}
type VendorDetails = { commercialRegister: string | null; taxNumber: string | null; mobile: string | null; email: string | null };

export interface Thresholds {
  reminderDays: number[];
  escalationDays: number;
}

/** Threshold: أيام التذكير ومدة التصعيد. يعدّلها مسؤول النظام وحده. */
@Injectable()
export class SettingsService {
  constructor(@InjectRepository(SettingEntity) private readonly repo: Repository<SettingEntity>) {}

  async get(): Promise<Thresholds> {
    const rows = await this.repo.find();
    const map = new Map(rows.map((r) => [r.key, r.value]));
    const reminder = (map.get('reminder_days') ?? SETTING_DEFAULTS.reminder_days)
      .split(',')
      .map((x) => Number(x.trim()))
      .filter((n) => Number.isInteger(n) && n > 0)
      .sort((a, b) => b - a);
    const escalation = Number(map.get('escalation_days') ?? SETTING_DEFAULTS.escalation_days);
    return { reminderDays: reminder, escalationDays: Number.isInteger(escalation) && escalation > 0 ? escalation : 7 };
  }

  async set(t: Thresholds) {
    await this.repo.save([
      this.repo.create({ key: 'reminder_days', value: t.reminderDays.join(',') }),
      this.repo.create({ key: 'escalation_days', value: String(t.escalationDays) }),
    ]);
  }
}

@Controller('reference')
export class ReferenceController {
  constructor(
    @InjectRepository(RefValueEntity) private readonly refs: Repository<RefValueEntity>,
    @InjectRepository(DepartmentEntity) private readonly departments: Repository<DepartmentEntity>,
    @InjectRepository(VendorEntity) private readonly vendors: Repository<VendorEntity>,
    private readonly settings: SettingsService,
    private readonly audit: AuditService,
  ) {}

  private admin(user: AuthUser) {
    if (!user.caps.canManageRefs) throw new ForbiddenException('القوائم والإعدادات يديرها مسؤول النظام.');
  }

  /** بيانات النماذج: كل القيم النشطة فقط (الموقوفة تختفي من العقود الجديدة). */
  @Get('options')
  async options() {
    const [departments, refs, vendors, thresholds] = await Promise.all([
      this.departments.find({ where: { isActive: true }, order: { name: 'ASC' } }),
      this.refs.find({ where: { isActive: true }, order: { name: 'ASC' } }),
      this.vendors.find({ where: { isActive: true }, order: { name: 'ASC' } }),
      this.settings.get(),
    ]);
    const list = (name: string) => refs.filter((r) => r.list === name).map((r) => ({ id: r.id, name: r.name }));
    return {
      departments: departments.map((d) => ({ id: d.id, name: d.name, code: d.code })),
      branches: list(RefList.BRANCH),
      shelfTypes: list(RefList.SHELF_TYPE),
      serviceTypes: list(RefList.SERVICE_TYPE),
      events: list(RefList.EVENT),
      vendors: vendors.map((v) => ({ id: v.id, vendorNumber: v.vendorNumber, name: v.name })),
      thresholds,
    };
  }

  // ───── القوائم ─────
  @Get('lists')
  async lists(@CurrentUser() user: AuthUser) {
    this.admin(user);
    const [departments, refs] = await Promise.all([this.departments.find({ order: { name: 'ASC' } }), this.refs.find({ order: { name: 'ASC' } })]);
    return {
      labels: REF_LIST_LABELS,
      departments,
      values: refs,
    };
  }

  @Post('lists/:list')
  async addValue(@CurrentUser() user: AuthUser, @Param('list') list: string, @Body() body: { name?: string }) {
    this.admin(user);
    if (!Object.values(RefList).includes(list as never)) throw new NotFoundException('قائمة غير معروفة.');
    const name = String(body.name ?? '').trim();
    if (!name) throw this.err('name', 'القيمة مطلوبة.');
    if (await this.refs.findOne({ where: { list, name } })) throw this.err('name', 'القيمة موجودة مسبقاً في القائمة.');
    const saved = await this.refs.save(this.refs.create({ list, name, isActive: true }));
    await this.audit.log({ user, action: 'إضافة قيمة قائمة', entity: 'ref_value', after: { list, name } });
    return saved;
  }

  @Patch('lists/:list/:id')
  async updateValue(
    @CurrentUser() user: AuthUser,
    @Param('list') list: string,
    @Param('id', ParseIntPipe) id: number,
    @Body() body: { name?: string; isActive?: boolean },
  ) {
    this.admin(user);
    const row = await this.refs.findOne({ where: { id, list } });
    if (!row) throw new NotFoundException('القيمة غير موجودة.');
    const before = { name: row.name, isActive: row.isActive };
    if (body.name !== undefined) {
      const name = String(body.name).trim();
      if (!name) throw this.err('name', 'القيمة مطلوبة.');
      const clash = await this.refs.findOne({ where: { list, name } });
      if (clash && clash.id !== id) throw this.err('name', 'القيمة موجودة مسبقاً في القائمة.');
      row.name = name;
    }
    if (body.isActive !== undefined) row.isActive = Boolean(body.isActive); // الإيقاف بدل الحذف
    await this.refs.save(row);
    await this.audit.log({ user, action: row.isActive === before.isActive ? 'تعديل قيمة قائمة' : row.isActive ? 'تفعيل قيمة قائمة' : 'إيقاف قيمة قائمة', entity: 'ref_value', before, after: { name: row.name, isActive: row.isActive } });
    return row;
  }

  @Patch('departments/:id')
  async updateDepartment(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() body: { name?: string; isActive?: boolean }) {
    this.admin(user);
    const row = await this.departments.findOne({ where: { id } });
    if (!row) throw new NotFoundException('القسم غير موجود.');
    const before = { name: row.name, isActive: row.isActive };
    if (body.name !== undefined && String(body.name).trim()) row.name = String(body.name).trim();
    if (body.isActive !== undefined) row.isActive = Boolean(body.isActive);
    await this.departments.save(row);
    await this.audit.log({ user, action: 'تعديل قسم', entity: 'department', before, after: { name: row.name, isActive: row.isActive } });
    return row;
  }

  // ───── الموردون (قراءة من النظام الحالي؛ وللمسؤول إدخال/استيراد) ─────
  @Get('vendors')
  async vendorList(@Query('q') q?: string, @Query('includeInactive') includeInactive?: string) {
    const qb = this.vendors.createQueryBuilder('v').orderBy('v.name', 'ASC').take(5000);
    if (includeInactive !== '1') qb.andWhere('v.isActive = :a', { a: true });
    if (q?.trim()) qb.andWhere('(v.name LIKE :q OR v.vendorNumber LIKE :q OR v.commercialRegister LIKE :q OR v.mobile LIKE :q)', { q: `%${q.trim()}%` });
    return qb.getMany();
  }

  @Post('vendors')
  async addVendor(@CurrentUser() user: AuthUser, @Body() body: VendorBody) {
    this.admin(user);
    const d = this.vendorDetails(body, true);
    return this.upsertVendor(user, String(body.vendorNumber ?? '').trim(), String(body.name ?? '').trim(), false, d);
  }

  /** استيراد جماعي: [{vendorNumber, name}] — يُستخدم لمزامنة الموردين من النظام الحالي. */
  @Post('vendors/import')
  async importVendors(@CurrentUser() user: AuthUser, @Body() body: { vendors?: (VendorBody & { vendorNumber: string; name: string })[] }) {
    this.admin(user);
    const rows = Array.isArray(body.vendors) ? body.vendors : [];
    let created = 0;
    let updated = 0;
    for (const r of rows) {
      const existing = await this.vendors.findOne({ where: { vendorNumber: String(r.vendorNumber).trim() } });
      await this.upsertVendor(user, String(r.vendorNumber).trim(), String(r.name).trim(), true, this.vendorDetails(r, false));
      existing ? updated++ : created++;
    }
    await this.audit.log({ user, action: 'استيراد موردين', entity: 'vendor', note: `${created} جديد، ${updated} محدّث` });
    return { created, updated };
  }

  @Patch('vendors/:id')
  async updateVendor(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() body: VendorBody & { isActive?: boolean }) {
    this.admin(user);
    const v = await this.vendors.findOne({ where: { id } });
    if (!v) throw new NotFoundException('المورد غير موجود.');
    const snap = () => ({ name: v.name, commercialRegister: v.commercialRegister, taxNumber: v.taxNumber, mobile: v.mobile, email: v.email, isActive: v.isActive });
    const before = snap();
    if (body.name !== undefined && String(body.name).trim()) v.name = String(body.name).trim();
    const d = this.vendorDetails(body, false);
    // السجل التجاري والجوال إلزاميان: لا يُسمح بتفريغهما عند التعديل
    if (body.commercialRegister !== undefined && !d.commercialRegister) throw this.err('commercialRegister', 'السجل التجاري مطلوب.');
    if (body.mobile !== undefined && !d.mobile) throw this.err('mobile', 'رقم الجوال مطلوب.');
    if (body.commercialRegister !== undefined) v.commercialRegister = d.commercialRegister;
    if (body.taxNumber !== undefined) v.taxNumber = d.taxNumber;
    if (body.mobile !== undefined) v.mobile = d.mobile;
    if (body.email !== undefined) v.email = d.email;
    if (body.isActive !== undefined) v.isActive = Boolean(body.isActive);
    await this.vendors.save(v);
    await this.audit.log({ user, action: 'تعديل مورد', entity: 'vendor', before, after: snap() });
    return v;
  }

  // ───── Threshold ─────
  @Get('settings')
  getSettings() {
    return this.settings.get();
  }

  @Put('settings')
  async putSettings(@CurrentUser() user: AuthUser, @Body() body: { reminderDays?: unknown; escalationDays?: unknown }) {
    this.admin(user);
    const days = Array.isArray(body.reminderDays) ? body.reminderDays.map(Number) : [];
    if (!days.length || days.some((d) => !Number.isInteger(d) || d <= 0 || d > 365)) {
      throw this.err('reminderDays', 'أيام التذكير أرقام صحيحة موجبة (مثل 90 و60 و30).');
    }
    const esc = Number(body.escalationDays);
    if (!Number.isInteger(esc) || esc <= 0 || esc > 60) throw this.err('escalationDays', 'مدة التصعيد بالأيام (رقم صحيح بين 1 و60).');
    const before = await this.settings.get();
    const next = { reminderDays: [...new Set(days)].sort((a, b) => b - a), escalationDays: esc };
    await this.settings.set(next);
    await this.audit.log({ user, action: 'تعديل Threshold', entity: 'settings', before, after: next, note: 'يسري على العقود الجديدة فقط' });
    return next;
  }

  /** يطبّع حقول المورد؛ strict=true يفرض السجل التجاري والجوال (إدخال يدوي). */
  private vendorDetails(body: VendorBody, strict: boolean): VendorDetails {
    const clean = (x: unknown) => String(x ?? '').trim() || null;
    const d: VendorDetails = {
      commercialRegister: clean(body.commercialRegister),
      taxNumber: clean(body.taxNumber),
      mobile: clean(body.mobile),
      email: clean(body.email),
    };
    if (strict) {
      if (!d.commercialRegister) throw this.err('commercialRegister', 'السجل التجاري مطلوب.');
      if (!d.mobile) throw this.err('mobile', 'رقم الجوال مطلوب.');
    }
    if (d.mobile && !/^[0-9+\s-]{8,20}$/.test(d.mobile)) throw this.err('mobile', 'رقم الجوال غير صحيح.');
    if (d.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email)) throw this.err('email', 'البريد الإلكتروني غير صحيح.');
    return d;
  }

  private async upsertVendor(user: AuthUser, vendorNumber: string, name: string, quiet = false, details?: VendorDetails) {
    if (!vendorNumber) throw this.err('vendorNumber', 'رقم المورد مطلوب.');
    if (!name) throw this.err('name', 'اسم المورد مطلوب.');
    let v = await this.vendors.findOne({ where: { vendorNumber } });
    if (!v) {
      v = await this.vendors.save(this.vendors.create({ vendorNumber, name, isActive: true, ...details }));
      if (!quiet) await this.audit.log({ user, action: 'إضافة مورد', entity: 'vendor', after: { vendorNumber, name, ...details } });
    } else {
      let dirty = v.name !== name;
      v.name = name;
      // الاستيراد يملأ الحقول الفارغة فقط ولا يمسح ما أُدخل يدوياً
      for (const k of ['commercialRegister', 'taxNumber', 'mobile', 'email'] as const) {
        if (details?.[k] && v[k] !== details[k]) { v[k] = details[k]; dirty = true; }
      }
      if (dirty) await this.vendors.save(v);
    }
    return v;
  }

  private err(field: string, message: string) {
    return new BadRequestException({ message, errors: { [field]: message } });
  }
}

@Global()
@Module({
  controllers: [ReferenceController],
  providers: [SettingsService],
  exports: [SettingsService],
})
export class ReferenceModule {}
