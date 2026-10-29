import { Controller, Get, Module, Query } from '@nestjs/common';
import { TypeOrmModule } from '@nestjs/typeorm';
import { DataSource } from 'typeorm';
import { AuthUser } from '../auth/auth.service';
import { CurrentUser } from '../common/auth.guard';
import { STATUS_LABELS, TYPE_LABELS } from '../common/domain';
import { daysBetween, todayIso, addDays } from '../common/dates';
import { ContractEntity, DepartmentEntity, ENTITIES, ContractTargetEntity } from '../database/entities';
import { ContractsService } from '../contracts/contracts.service';
import { ContractsModule } from '../contracts/contracts.module';

@Controller('dashboard')
export class DashboardController {
  constructor(
    private readonly ds: DataSource,
    private readonly contracts: ContractsService,
  ) {}

  /**
   * لوحة التحكم: العقود بانتظار إجراء المستخدم، الحالات، القرب من الانتهاء،
   * الأهداف والخصم التقديري حسب القسم، وتوزيع الأنواع.
   */
  @Get()
  async summary(@CurrentUser() user: AuthUser) {
    const contracts = await this.ds
      .getRepository(ContractEntity)
      .find({ relations: { department: true, targets: true, vendor: true } });
    const today = todayIso();
    const open = contracts.filter((c) => c.status !== 'cancelled' && c.status !== 'expired');

    const byStatus = Object.keys(STATUS_LABELS).map((key) => ({
      status: key,
      label: STATUS_LABELS[key],
      count: contracts.filter((c) => c.status === key).length,
    }));

    const byType = Object.keys(TYPE_LABELS).map((key) => ({
      type: key,
      label: TYPE_LABELS[key],
      count: contracts.filter((c) => c.contractType === key).length,
    }));

    const expiring = [30, 60, 90].map((days) => {
      const limit = addDays(today, days);
      return {
        days,
        count: open.filter((c) => c.endDate >= today && c.endDate <= limit).length,
      };
    });

    // الخصم التقديري = قيمة الهدف × نسبة الخصم، مجمّعاً حسب القسم (للعقود غير الملغاة والمنتهية).
    const byDepartment = new Map<string, { department: string; estimatedDiscount: number; targets: number }>();
    open.forEach((c) => {
      const key = c.department?.name ?? '—';
      const entry = byDepartment.get(key) ?? { department: key, estimatedDiscount: 0, targets: 0 };
      (c.targets ?? []).forEach((t: ContractTargetEntity) => {
        entry.estimatedDiscount += Number(t.targetValue) * (Number(t.discountPercent) / 100);
        entry.targets += 1;
      });
      byDepartment.set(key, entry);
    });

    const awaiting = (await this.contracts.list(user, { view: 'awaiting' } as never)).slice(0, 10);
    const nearest = open
      .filter((c) => c.endDate >= today)
      .sort((a, b) => a.endDate.localeCompare(b.endDate))
      .slice(0, 8)
      .map((c) => ({
        id: c.id,
        contractNumber: c.contractNumber,
        vendorName: c.vendor?.tradeName || c.vendor?.legalName || '',
        endDate: c.endDate,
        daysRemaining: daysBetween(today, c.endDate),
        status: c.status,
      }));

    return {
      total: contracts.length,
      byStatus,
      byType,
      expiring,
      awaiting,
      nearest,
      departments: [...byDepartment.values()].sort((a, b) => b.estimatedDiscount - a.estimatedDiscount),
      departmentsCount: await this.ds.getRepository(DepartmentEntity).count({ where: { isActive: true } }),
    };
  }

  @Get('goals')
  async goals() {
    // أهداف الشرائح من العقود. صافي المشتريات الفعلي غير موجود في قاعدة البيانات الحالية،
    // لذا تبقى الحالة «لا توجد بيانات» حتى يتم ربط مصدر المشتريات.
    const targets = await this.ds.getRepository(ContractTargetEntity).find({ relations: { contract: { vendor: true, department: true } } });
    return targets.map((t) => ({
      id: t.id,
      contractNumber: t.contract?.contractNumber ?? '',
      vendorName: t.contract?.vendor?.tradeName || t.contract?.vendor?.legalName || '',
      department: t.contract?.department?.name ?? '',
      title: t.title,
      targetValue: Number(t.targetValue),
      discountPercent: Number(t.discountPercent),
      estimatedDiscount: Number(t.targetValue) * (Number(t.discountPercent) / 100),
      progressStatus: 'no_data',
    }));
  }
}

@Module({
  imports: [TypeOrmModule.forFeature(ENTITIES), ContractsModule],
  controllers: [DashboardController],
})
export class DashboardModule {}
