import { Injectable } from '@nestjs/common';
import { InjectRepository } from '@nestjs/typeorm';
import { EntityManager, Repository } from 'typeorm';
import { AuditLogEntity } from '../database/entities';

export interface AuditActor {
  id: number;
  fullName: string;
  role: string;
}

export interface AuditEntry {
  user: AuditActor | null;
  action: string;
  entity: string;
  contractId?: number | null;
  before?: unknown;
  after?: unknown;
  note?: string;
}

/**
 * سجل التدقيق: إضافة فقط. لا توجد أي دالة تعديل أو حذف في النظام.
 * الاحتفاظ 10 سنوات على الأقل ولا حذف آلي.
 */
@Injectable()
export class AuditService {
  constructor(@InjectRepository(AuditLogEntity) private readonly repo: Repository<AuditLogEntity>) {}

  log(entry: AuditEntry, manager?: EntityManager) {
    const repo = manager ? manager.getRepository(AuditLogEntity) : this.repo;
    return repo.save(
      repo.create({
        contractId: entry.contractId ?? null,
        userId: entry.user?.id ?? null,
        userName: entry.user?.fullName ?? 'النظام',
        userRole: entry.user?.role ?? 'system',
        action: entry.action,
        entity: entry.entity,
        before: entry.before ?? null,
        after: entry.after ?? null,
        note: entry.note ?? '',
        createdAt: new Date(),
      }),
    );
  }
}
