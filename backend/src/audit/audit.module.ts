import { Controller, ForbiddenException, Get, Global, Module, Query } from '@nestjs/common';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository } from 'typeorm';
import { AuthUser } from '../auth/auth.service';
import { CurrentUser } from '../common/auth.guard';
import { isoDateTime } from '../common/dates';
import { AuditLogEntity, ContractEntity } from '../database/entities';
import { AuditService } from './audit.service';

/** سجل التدقيق: للقراءة فقط، حسب نطاق رؤية المستخدم (المصفوفة 3.2). */
@Controller('audit')
export class AuditController {
  constructor(
    @InjectRepository(AuditLogEntity) private readonly repo: Repository<AuditLogEntity>,
    @InjectRepository(ContractEntity) private readonly contracts: Repository<ContractEntity>,
  ) {}

  @Get()
  async list(
    @CurrentUser() user: AuthUser,
    @Query('contractId') contractId?: string,
    @Query('userName') userName?: string,
    @Query('action') action?: string,
    @Query('from') from?: string,
    @Query('to') to?: string,
    @Query('limit') limit?: string,
  ) {
    if (!user.caps.canViewAudit) throw new ForbiddenException('سجل التدقيق غير متاح لدورك.');
    const qb = this.repo.createQueryBuilder('l').orderBy('l.id', 'DESC').take(Math.min(Number(limit) || 300, 1000));
    if (contractId) qb.andWhere('l.contractId = :cid', { cid: Number(contractId) });
    if (userName) qb.andWhere('l.userName LIKE :un', { un: `%${userName}%` });
    if (action) qb.andWhere('l.action LIKE :ac', { ac: `%${action}%` });
    if (from) qb.andWhere('l.createdAt >= :from', { from: `${from} 00:00:00` });
    if (to) qb.andWhere('l.createdAt <= :to', { to: `${to} 23:59:59` });
    const rows = await qb.getMany();

    const ids = [...new Set(rows.map((r) => r.contractId).filter((x): x is number => !!x))];
    const numbers = new Map<number, string>();
    if (ids.length) (await this.contracts.findByIds(ids)).forEach((c) => numbers.set(c.id, c.number ?? `مسودة #${c.id}`));
    return rows.map((r) => ({
      id: r.id,
      contractId: r.contractId,
      contractNumber: r.contractId ? numbers.get(r.contractId) ?? null : null,
      userName: r.userName,
      userRole: r.userRole,
      action: r.action,
      entity: r.entity,
      before: r.before,
      after: r.after,
      note: r.note,
      createdAt: isoDateTime(r.createdAt),
    }));
  }
}

@Global()
@Module({
  controllers: [AuditController],
  providers: [AuditService],
  exports: [AuditService],
})
export class AuditModule {}
