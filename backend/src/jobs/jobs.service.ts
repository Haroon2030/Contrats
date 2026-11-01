import { Injectable, Logger, OnApplicationBootstrap, OnModuleDestroy } from '@nestjs/common';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository } from 'typeorm';
import { daysBetween, daysSince, todayIso } from '../common/dates';
import { Party, Status, isServicesOnly } from '../common/domain';
import { WorkflowService } from '../contracts/workflow.service';
import { ContractEntity } from '../database/entities';
import { GoalsService } from '../goals/goals.service';
import { NotificationsService } from '../notifications/notifications.module';

/**
 * المهام الدورية (كل ساعة، وتُشغَّل عند الإقلاع):
 *  - انتهاء العقود تلقائياً بعد تاريخ نهايتها.
 *  - تذكيرات الانتهاء (90/60/30 حسب Threshold العقد) بالجرس والبريد.
 *  - التصعيد بعد مدة الانتظار (7 أيام افتراضياً) لجميع المعنيين بالجرس والبريد، مرة واحدة لكل بقاء.
 *  - تثبيت حالة الأهداف المنتهية.
 */
@Injectable()
export class JobsService implements OnApplicationBootstrap, OnModuleDestroy {
  private readonly log = new Logger('Jobs');
  private timer: NodeJS.Timeout | null = null;
  private running = false;

  constructor(
    @InjectRepository(ContractEntity) private readonly contracts: Repository<ContractEntity>,
    private readonly workflow: WorkflowService,
    private readonly notify: NotificationsService,
    private readonly goals: GoalsService,
  ) {}

  onApplicationBootstrap() {
    if (process.env.JOBS_DISABLED === '1') return;
    setTimeout(() => this.runAll().catch(() => undefined), 20_000).unref();
    this.timer = setInterval(() => this.runAll().catch(() => undefined), 60 * 60_000);
    this.timer.unref();
  }

  onModuleDestroy() {
    if (this.timer) clearInterval(this.timer);
  }

  async runAll() {
    if (this.running) return { skipped: true };
    this.running = true;
    try {
      const ended = await this.workflow.autoEndExpired();
      const reminders = await this.sendReminders();
      const escalations = await this.escalate();
      const fixed = await this.goals.fixEnded();
      const result = { ended, reminders, escalations, fixed };
      this.log.log(`مهام دورية: ${JSON.stringify(result)}`);
      return result;
    } catch (e) {
      this.log.error(`فشلت المهام الدورية: ${(e as Error).message}`);
      throw e;
    } finally {
      this.running = false;
    }
  }

  /** تذكير الانتهاء: يُرسل مرة لكل حد (يُحفظ ما أُرسل في العقد). لا ينطبق على عقود الخدمات. */
  async sendReminders(): Promise<number> {
    const today = todayIso();
    const rows = await this.contracts.find({ where: { status: Status.ACTIVE } });
    let sent = 0;
    for (const c of rows) {
      if (!c.endDate || isServicesOnly(c.types)) continue;
      const left = daysBetween(today, c.endDate);
      if (left < 0) continue;
      const thresholds = (c.reminderDays?.length ? c.reminderDays : [90, 60, 30]).slice().sort((a, b) => a - b);
      const already = new Set(c.remindersSent ?? []);
      const crossed = thresholds.filter((t) => left <= t);
      const due = crossed.filter((t) => !already.has(t));
      if (!due.length) continue;
      const nearest = Math.min(...crossed);
      crossed.forEach((t) => already.add(t));
      await this.contracts.update(c.id, { remindersSent: [...already] });
      await this.notify.notify(c, 'reminder', `يقترب انتهاء العقد: متبقٍ ${left} يوماً (تذكير ${nearest}).`, { creator: true, departmentManager: true, contractsManager: true, contractsOfficer: true }, { email: true });
      sent++;
    }
    return sent;
  }

  /** التصعيد: عقد في نقطة انتظار أكثر من المدة → إشعار جميع المعنيين بالجرس والبريد مرة واحدة. */
  async escalate(): Promise<number> {
    const rows = await this.contracts
      .createQueryBuilder('c')
      .where('(c.status = :ur OR c.status = :as OR (c.status = :dr AND c.sentAt IS NOT NULL))', { ur: Status.UNDER_REVIEW, as: Status.AWAITING_SIGNATURE, dr: Status.DRAFT })
      .getMany();
    let count = 0;
    for (const c of rows) {
      const days = daysSince(c.stateEnteredAt);
      if (days < (c.escalationDays || 7)) continue;
      if (c.lastEscalatedAt && c.lastEscalatedAt.getTime() >= c.stateEnteredAt.getTime()) continue; // مرة واحدة لكل بقاء
      await this.contracts.update(c.id, { lastEscalatedAt: new Date() });
      const where = c.party === Party.CREATOR ? 'لدى المنشئ (مسودة)' : c.party === Party.DEPARTMENT_MANAGER ? 'لدى مدير القسم' : c.party === Party.CONTRACTS_MANAGER ? 'لدى مدير قسم العقود' : 'بانتظار التوقيع لدى مدير المشتريات';
      await this.notify.notify(c, 'escalation', `تأخّر العقد ${days} أيام ${where}.`, { creator: true, departmentManager: true, contractsManager: true, contractsOfficer: true, purchasingManager: c.status === Status.AWAITING_SIGNATURE }, { email: true });
      count++;
    }
    return count;
  }
}
