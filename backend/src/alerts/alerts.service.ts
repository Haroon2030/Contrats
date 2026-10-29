import { Injectable } from '@nestjs/common';
import { DataSource, EntityManager } from 'typeorm';
import { ContractAlertEntity } from '../database/entities';

const SEVERITY_RANK: Record<string, number> = { critical: 0, warning: 1, info: 2 };

@Injectable()
export class AlertsService {
  constructor(private readonly ds: DataSource) {}

  /** إنشاء التنبيه أو تحديثه (زوج العقد + النوع فريد، كما في Django). */
  async upsert(m: EntityManager, contractId: number, alertType: string, severity: string, message: string) {
    const repo = m.getRepository(ContractAlertEntity);
    const existing = await repo.findOne({ where: { contractId, alertType } });
    if (existing) {
      Object.assign(existing, { severity, message, isOpen: true, resolvedAt: null });
      return repo.save(existing);
    }
    return repo.save(
      repo.create({ contractId, alertType, severity, message, isOpen: true, createdAt: new Date(), resolvedAt: null }),
    );
  }

  /** التنبيهات المفتوحة — تُستخدم كإشعارات الجرس في الشريط العلوي. */
  async listOpen() {
    const rows = await this.ds
      .getRepository(ContractAlertEntity)
      .createQueryBuilder('a')
      .leftJoinAndSelect('a.contract', 'c')
      .leftJoinAndSelect('c.vendor', 'v')
      .where('a.isOpen = :open', { open: true })
      .getMany();

    return rows
      .sort((a, b) => (SEVERITY_RANK[a.severity] ?? 9) - (SEVERITY_RANK[b.severity] ?? 9) || b.id - a.id)
      .map((a) => ({
        id: a.id,
        alertType: a.alertType,
        severity: a.severity,
        message: a.message,
        contractId: a.contractId,
        contractNumber: a.contract?.contractNumber ?? '',
        vendorName: a.contract?.vendor?.tradeName || a.contract?.vendor?.legalName || '',
        createdAt: a.createdAt,
      }));
  }

  /** إغلاق التنبيهات الآلية التي لم تعد صحيحة. */
  async closeStale(m: EntityManager, autoTypes: string[], keep: Set<string>) {
    const open = await m.getRepository(ContractAlertEntity).find({ where: { isOpen: true } });
    for (const alert of open) {
      if (!autoTypes.includes(alert.alertType)) continue;
      if (keep.has(`${alert.contractId}:${alert.alertType}`)) continue;
      alert.isOpen = false;
      alert.resolvedAt = new Date();
      await m.save(alert);
    }
  }
}
