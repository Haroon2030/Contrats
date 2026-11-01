import { Injectable, Logger, OnApplicationBootstrap } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { makeDjangoPassword } from '../common/django-password';
import { DEFAULT_DEPARTMENTS, DEFAULT_REFS, RefList, Role, SETTING_DEFAULTS } from '../common/domain';
import { DepartmentEntity, RefValueEntity, SettingEntity, UserEntity, VendorEntity } from './entities';

/**
 * تهيئة أولية آمنة (تكرارها لا يضر):
 *  - الأقسام الثلاثة، القوائم الافتراضية، وقيم Threshold.
 *  - أول مسؤول نظام من BOOTSTRAP_ADMIN_USERNAME / BOOTSTRAP_ADMIN_PASSWORD إن لم يوجد مسؤول.
 *  - بيانات تجريبية للتطوير فقط عند DEV_SEED=1.
 */
@Injectable()
export class BootstrapService implements OnApplicationBootstrap {
  private readonly log = new Logger('Bootstrap');

  constructor(private readonly ds: DataSource) {}

  async onApplicationBootstrap() {
    await this.seedDepartments();
    await this.seedRefs();
    await this.seedSettings();
    await this.seedAdmin();
    if (process.env.DEV_SEED === '1') await this.seedDemo();
  }

  private async seedDepartments() {
    const repo = this.ds.getRepository(DepartmentEntity);
    for (const d of DEFAULT_DEPARTMENTS) {
      if (!(await repo.findOne({ where: { code: d.code } }))) await repo.save(repo.create({ ...d, isActive: true }));
    }
  }

  private async seedRefs() {
    const repo = this.ds.getRepository(RefValueEntity);
    for (const [list, names] of Object.entries(DEFAULT_REFS)) {
      for (const name of names) {
        if (!(await repo.findOne({ where: { list, name } }))) await repo.save(repo.create({ list, name, isActive: true }));
      }
    }
  }

  private async seedSettings() {
    const repo = this.ds.getRepository(SettingEntity);
    for (const [key, value] of Object.entries(SETTING_DEFAULTS)) {
      if (!(await repo.findOne({ where: { key } }))) await repo.save(repo.create({ key, value }));
    }
  }

  private async seedAdmin() {
    const users = this.ds.getRepository(UserEntity);
    const hasAdmin = await users.findOne({ where: { role: Role.SYSTEM_ADMIN } });
    if (hasAdmin) return;
    const username = process.env.BOOTSTRAP_ADMIN_USERNAME?.trim();
    const password = process.env.BOOTSTRAP_ADMIN_PASSWORD;
    if (!username || !password) {
      this.log.warn('لا يوجد مسؤول نظام. اضبط BOOTSTRAP_ADMIN_USERNAME و BOOTSTRAP_ADMIN_PASSWORD لإنشائه.');
      return;
    }
    await users.save(
      users.create({
        username,
        passwordHash: makeDjangoPassword(password),
        fullName: process.env.BOOTSTRAP_ADMIN_NAME || 'مسؤول النظام',
        email: process.env.BOOTSTRAP_ADMIN_EMAIL || null,
        role: Role.SYSTEM_ADMIN,
        departmentId: null,
        isActive: true,
        mustChangePassword: false,
        failedAttempts: 0,
        lockedUntil: null,
        lastLoginAt: null,
        createdAt: new Date(),
      }),
    );
    this.log.log(`أُنشئ مسؤول النظام الأول: ${username}`);
  }

  /** حسابات وبيانات تجريبية: لا تُفعَّل في الإنتاج. */
  private async seedDemo() {
    const users = this.ds.getRepository(UserEntity);
    if (await users.findOne({ where: { username: 'dm_fresh' } })) return;
    const depts = await this.ds.getRepository(DepartmentEntity).find();
    const dept = (code: string) => depts.find((d) => d.code === code)!.id;
    const make = async (username: string, fullName: string, role: string, departmentId: number | null) =>
      users.save(
        users.create({
          username,
          passwordHash: makeDjangoPassword('Demo12345', 150_000),
          fullName,
          email: `${username}@example.local`,
          role,
          departmentId,
          isActive: true,
          mustChangePassword: false,
          failedAttempts: 0,
          lockedUntil: null,
          lastLoginAt: null,
          createdAt: new Date(),
        }),
      );
    await make('cm', 'فهد الدوسري', Role.CONTRACTS_MANAGER, null);
    await make('co', 'نورة العتيبي', Role.CONTRACTS_OFFICER, null);
    await make('pm', 'سلطان القحطاني', Role.PURCHASING_MANAGER, null);
    await make('dm_fresh', 'ماجد الشهري', Role.DEPARTMENT_MANAGER, dept('FRESH'));
    await make('do_fresh', 'سارة الحربي', Role.DEPARTMENT_OFFICIAL, dept('FRESH'));
    await make('dm_food', 'خالد المطيري', Role.DEPARTMENT_MANAGER, dept('FOOD'));
    await make('do_food', 'ريم القحطاني', Role.DEPARTMENT_OFFICIAL, dept('FOOD'));
    await make('ops', 'عبدالله الزهراني', Role.OPERATIONS, null);

    const refs = this.ds.getRepository(RefValueEntity);
    for (const name of ['فرع الدمام الشاطئ', 'فرع الخبر', 'فرع الظهران', 'فرع الجبيل']) {
      if (!(await refs.findOne({ where: { list: RefList.BRANCH, name } }))) {
        await refs.save(refs.create({ list: RefList.BRANCH, name, isActive: true }));
      }
    }
    const vendors = this.ds.getRepository(VendorEntity);
    const demoVendors: [string, string][] = [
      ['10234', 'شركة المراعي'],
      ['10880', 'نستله السعودية'],
      ['11005', 'الصافي دانون'],
      ['11420', 'يونيليفر'],
    ];
    for (const [vendorNumber, name] of demoVendors) {
      if (!(await vendors.findOne({ where: { vendorNumber } }))) await vendors.save(vendors.create({ vendorNumber, name, isActive: true }));
    }
    this.log.log('أُنشئت حسابات تجريبية (كلمة المرور Demo12345): cm, co, pm, dm_fresh, do_fresh, dm_food, do_food, ops');
  }
}
