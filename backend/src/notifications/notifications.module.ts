import { Controller, Get, Global, Injectable, Logger, Module, Param, ParseIntPipe, Post } from '@nestjs/common';
import { InjectRepository } from '@nestjs/typeorm';
import { In, IsNull, Repository } from 'typeorm';
import { AuthUser } from '../auth/auth.service';
import { CurrentUser } from '../common/auth.guard';
import { isoDateTime } from '../common/dates';
import { ContractEntity, NotificationEntity, UserEntity, VendorEntity } from '../database/entities';
import { ContractType, Role, normalizeRole } from '../common/domain';

export interface Recipients {
  creator?: boolean;
  departmentManager?: boolean;
  departmentOfficial?: boolean;
  contractsManager?: boolean;
  contractsOfficer?: boolean;
  purchasingManager?: boolean;
  operations?: boolean; // يُضاف فقط إن تضمن العقد إيجاراً أو تسويقاً
  everyone?: boolean;
}

/**
 * الإشعارات: كل حدث يصل لمن يعنيه في الجرس، وبعضها بالبريد أيضاً (التذكير والتصعيد).
 * لا يصل المستخدم إشعار عن عقد خارج نطاق رؤيته: قسم التشغيل يصله ما يخص الإيجار والتسويق فقط،
 * ومدير/مسؤول القسم يصله ما يخص قسمه فقط.
 */
@Injectable()
export class NotificationsService {
  private readonly log = new Logger('Notifications');

  constructor(
    @InjectRepository(NotificationEntity) private readonly repo: Repository<NotificationEntity>,
    @InjectRepository(UserEntity) private readonly users: Repository<UserEntity>,
    @InjectRepository(VendorEntity) private readonly vendors: Repository<VendorEntity>,
  ) {}

  async recipientsFor(contract: ContractEntity, who: Recipients, actorId?: number): Promise<UserEntity[]> {
    const all = await this.users.find({ where: { isActive: true } });
    const wantOps = (who.operations || who.everyone) && (contract.types.includes(ContractType.RENT) || contract.types.includes(ContractType.MARKETING));
    const picked = all.filter((u) => {
      const role = normalizeRole(u.role);
      if (who.creator && u.id === contract.createdById) return true;
      if ((who.departmentManager || who.everyone) && role === Role.DEPARTMENT_MANAGER && u.departmentId === contract.departmentId) return true;
      if ((who.departmentOfficial || who.everyone) && role === Role.DEPARTMENT_OFFICIAL && u.departmentId === contract.departmentId) return true;
      if ((who.contractsManager || who.everyone) && role === Role.CONTRACTS_MANAGER) return true;
      if ((who.contractsOfficer || who.everyone) && role === Role.CONTRACTS_OFFICER) return true;
      if ((who.purchasingManager || who.everyone) && role === Role.PURCHASING_MANAGER) return true;
      if (wantOps && role === Role.OPERATIONS) return true;
      return false;
    });
    return picked.filter((u) => u.id !== actorId);
  }

  async notify(contract: ContractEntity, kind: string, text: string, who: Recipients, opts: { email?: boolean; actorId?: number } = {}) {
    const recipients = await this.recipientsFor(contract, who, opts.actorId);
    if (!recipients.length) return [];
    const vendor = await this.vendors.findOne({ where: { id: contract.vendorId } });
    const label = `${contract.number ?? `مسودة #${contract.id}`}${vendor ? ` — ${vendor.name}` : ''}`;
    const body = `${label}: ${text}`.slice(0, 500);
    const rows = recipients.map((u) =>
      this.repo.create({
        userId: u.id,
        contractId: contract.id,
        kind,
        text: body,
        readAt: null,
        emailTo: opts.email && u.email ? u.email : null,
        emailStatus: opts.email && u.email ? 'pending' : null,
        createdAt: new Date(),
      }),
    );
    const saved = await this.repo.save(rows);
    if (opts.email) this.sendEmails(saved).catch((e) => this.log.error(`فشل إرسال البريد: ${e?.message ?? e}`));
    return saved;
  }

  /** إرسال البريد عبر SMTP إن كان مضبوطاً (SMTP_HOST...)، وإلا يُعلَّم «skipped» ويبقى إشعار الجرس. */
  private async sendEmails(rows: NotificationEntity[]) {
    const pending = rows.filter((r) => r.emailTo);
    if (!pending.length) return;
    if (!process.env.SMTP_HOST) {
      await this.repo.update({ id: In(pending.map((r) => r.id)) }, { emailStatus: 'skipped' });
      return;
    }
    // eslint-disable-next-line @typescript-eslint/no-var-requires
    const nodemailer = require('nodemailer');
    const transport = nodemailer.createTransport({
      host: process.env.SMTP_HOST,
      port: Number(process.env.SMTP_PORT || 587),
      secure: process.env.SMTP_SECURE === '1',
      auth: process.env.SMTP_USER ? { user: process.env.SMTP_USER, pass: process.env.SMTP_PASSWORD } : undefined,
    });
    for (const r of pending) {
      try {
        await transport.sendMail({
          from: process.env.SMTP_FROM || process.env.SMTP_USER || 'contracts@localhost',
          to: r.emailTo,
          subject: 'نظام إدارة العقود — إشعار',
          text: r.text,
        });
        await this.repo.update(r.id, { emailStatus: 'sent' });
      } catch (e) {
        this.log.error(`بريد ${r.emailTo}: ${(e as Error).message}`);
        await this.repo.update(r.id, { emailStatus: 'failed' });
      }
    }
  }

  async list(userId: number) {
    const [rows, unread] = await Promise.all([
      this.repo.find({ where: { userId }, order: { id: 'DESC' }, take: 60 }),
      this.repo.count({ where: { userId, readAt: IsNull() } }),
    ]);
    return {
      unread,
      items: rows.map((n) => ({ id: n.id, contractId: n.contractId, kind: n.kind, text: n.text, read: !!n.readAt, createdAt: isoDateTime(n.createdAt) })),
    };
  }

  markRead(userId: number, id: number) {
    return this.repo.update({ id, userId }, { readAt: new Date() });
  }

  markAllRead(userId: number) {
    return this.repo.update({ userId, readAt: IsNull() }, { readAt: new Date() });
  }
}

@Controller('notifications')
export class NotificationsController {
  constructor(private readonly svc: NotificationsService) {}

  @Get()
  list(@CurrentUser() user: AuthUser) {
    return this.svc.list(user.id);
  }

  @Post('read-all')
  async readAll(@CurrentUser() user: AuthUser) {
    await this.svc.markAllRead(user.id);
    return this.svc.list(user.id);
  }

  @Post(':id/read')
  async read(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    await this.svc.markRead(user.id, id);
    return this.svc.list(user.id);
  }
}

@Global()
@Module({
  controllers: [NotificationsController],
  providers: [NotificationsService],
  exports: [NotificationsService],
})
export class NotificationsModule {}
