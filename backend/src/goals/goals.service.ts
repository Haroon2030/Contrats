import { Injectable } from '@nestjs/common';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository } from 'typeorm';
import { addDays, isoDateTime, todayIso } from '../common/dates';
import { ContractEntity, GoalLineEntity, PurchaseEntity, VendorEntity } from '../database/entities';

export type GoalStatus = 'tracking' | 'achieved' | 'not_achieved' | 'no_data';

export const GOAL_STATUS_LABELS: Record<GoalStatus, string> = {
  tracking: 'قيد المتابعة',
  achieved: 'متحقق',
  not_achieved: 'غير متحقق',
  no_data: 'لا توجد بيانات',
};

export interface GoalProgressLine {
  id: number;
  fromDate: string;
  toDate: string;
  target: number;
  discountPct: number;
  version: number;
  activeFrom: string | null;
  activeTo: string | null;
  net: number;
  achievedPct: number;
  status: GoalStatus;
  statusLabel: string;
  applied: boolean; // هل هي الشريحة المطبّقة (أعلى شريحة متحققة)؟
  estimatedDiscount: number;
  periodEnded: boolean;
}

export interface GoalProgress {
  lines: GoalProgressLine[];
  netPurchases: number;
  estimatedDiscount: number;
  lastUpdate: string | null;
}

const n = (v: string | number | null | undefined) => (v == null ? 0 : Number(v));
const round2 = (x: number) => Math.round(x * 100) / 100;

/**
 * متابعة الأهداف والخصم التقديري (BRD US19/US20):
 *  - صافي المشتريات = المشتريات − المرتجعات بين تاريخي السطر (وضمن سريان إصداره).
 *  - لنفس الفترة تُطبَّق أعلى شريحة متحققة على كامل صافي المشتريات.
 *  - عند انتهاء فترة السطر تثبت الحالة والخصم ولا يتغيران بعد ذلك.
 */
@Injectable()
export class GoalsService {
  constructor(
    @InjectRepository(GoalLineEntity) private readonly lines: Repository<GoalLineEntity>,
    @InjectRepository(PurchaseEntity) private readonly purchases: Repository<PurchaseEntity>,
    @InjectRepository(ContractEntity) private readonly contracts: Repository<ContractEntity>,
    @InjectRepository(VendorEntity) private readonly vendors: Repository<VendorEntity>,
  ) {}

  /** صافي المشتريات لمورد بين تاريخين (شاملين). */
  private async netBetween(vendorNumber: string, from: string, to: string): Promise<{ net: number; rows: number }> {
    const row = await this.purchases
      .createQueryBuilder('p')
      .select('COALESCE(SUM(p.purchases),0)', 'purchases')
      .addSelect('COALESCE(SUM(p.returns),0)', 'returns')
      .addSelect('COUNT(p.id)', 'cnt')
      .where('p.vendorNumber = :v AND p.date >= :from AND p.date <= :to', { v: vendorNumber, from, to })
      .getRawOne();
    return { net: round2(n(row?.purchases) - n(row?.returns)), rows: Number(row?.cnt ?? 0) };
  }

  async lastUpdate(): Promise<string | null> {
    const row = await this.purchases.createQueryBuilder('p').select('MAX(p.importedAt)', 'm').getRawOne();
    return isoDateTime(row?.m ?? null);
  }

  async progressFor(contract: ContractEntity, vendorNumber: string): Promise<GoalProgress> {
    const lines = await this.lines.find({ where: { contractId: contract.id }, order: { version: 'ASC', sort: 'ASC', id: 'ASC' } });
    const today = todayIso();
    const out: GoalProgressLine[] = [];

    for (const l of lines) {
      const winFrom = l.activeFrom && l.activeFrom > l.fromDate ? l.activeFrom : l.fromDate;
      const winTo = l.activeTo && l.activeTo < l.toDate ? l.activeTo : l.toDate;
      const ended = (l.activeTo ?? l.toDate) < today || l.toDate < today;
      const periodEnded = l.toDate < today || (!!l.activeTo && l.activeTo < today);

      let net: number;
      let rows = 1;
      if (periodEnded && l.fixedStatus) {
        net = n(l.fixedNet);
      } else if (winTo < winFrom) {
        net = 0;
        rows = 0;
      } else {
        const r = await this.netBetween(vendorNumber, winFrom, winTo);
        net = r.net;
        rows = r.rows;
      }
      void ended;
      const target = n(l.target);
      out.push({
        id: l.id,
        fromDate: l.fromDate,
        toDate: l.toDate,
        target,
        discountPct: n(l.discountPct),
        version: l.version,
        activeFrom: l.activeFrom,
        activeTo: l.activeTo,
        net,
        achievedPct: target ? round2((net / target) * 100) : 0,
        status: 'tracking',
        statusLabel: '',
        applied: false,
        estimatedDiscount: 0,
        periodEnded,
      });
      (out[out.length - 1] as any)._rows = rows;
      (out[out.length - 1] as any)._fixed = periodEnded && l.fixedStatus ? l : null;
    }

    // مجموعات الشرائح: نفس الإصدار ونفس الفترة.
    const groups = new Map<string, GoalProgressLine[]>();
    for (const l of out) {
      const key = `${l.version}|${l.fromDate}|${l.toDate}`;
      groups.set(key, [...(groups.get(key) ?? []), l]);
    }
    for (const group of groups.values()) {
      const achieved = group.filter((l) => l.net >= l.target);
      const top = achieved.sort((a, b) => b.discountPct - a.discountPct)[0];
      for (const l of group) {
        const fixed = (l as any)._fixed as GoalLineEntity | null;
        const rows = (l as any)._rows as number;
        if (fixed) {
          l.status = fixed.fixedStatus as GoalStatus;
          l.estimatedDiscount = n(fixed.fixedDiscount);
          l.applied = l.estimatedDiscount > 0;
        } else {
          l.applied = !!top && l.id === top.id;
          l.estimatedDiscount = l.applied ? round2(l.net * (l.discountPct / 100)) : 0;
          if (rows === 0) l.status = 'no_data';
          else if (l.periodEnded) l.status = l.net >= l.target ? 'achieved' : 'not_achieved';
          else l.status = 'tracking';
        }
        l.statusLabel = GOAL_STATUS_LABELS[l.status];
        delete (l as any)._rows;
        delete (l as any)._fixed;
      }
    }

    // صافي المشتريات الإجمالي للعقد = أكبر صافٍ بين مجموعات الإصدار الحالي (للعرض فقط).
    const net = out.length ? Math.max(...out.map((l) => l.net)) : 0;
    return {
      lines: out,
      netPurchases: net,
      estimatedDiscount: round2(out.reduce((s, l) => s + l.estimatedDiscount, 0)),
      lastUpdate: await this.lastUpdate(),
    };
  }

  /** يثبّت حالة وخصم كل سطر انتهت فترته (مرة واحدة). تشغّله المهمة اليومية. */
  async fixEnded(): Promise<number> {
    const today = todayIso();
    const open = await this.lines
      .createQueryBuilder('l')
      .where('l.fixedStatus IS NULL AND (l.toDate < :t OR (l.activeTo IS NOT NULL AND l.activeTo < :t))', { t: today })
      .getMany();
    let fixed = 0;
    const byContract = new Map<number, GoalLineEntity[]>();
    open.forEach((l) => byContract.set(l.contractId, [...(byContract.get(l.contractId) ?? []), l]));
    for (const [contractId] of byContract) {
      const contract = await this.contracts.findOne({ where: { id: contractId } });
      if (!contract) continue;
      const vendor = await this.vendors.findOne({ where: { id: contract.vendorId } });
      if (!vendor) continue;
      const progress = await this.progressFor(contract, vendor.vendorNumber);
      for (const pl of progress.lines) {
        const row = open.find((o) => o.id === pl.id);
        if (!row) continue;
        row.fixedStatus = pl.status === 'tracking' ? (pl.net >= pl.target ? 'achieved' : 'not_achieved') : pl.status;
        row.fixedNet = pl.net.toFixed(2);
        row.fixedDiscount = pl.estimatedDiscount.toFixed(2);
        await this.lines.save(row);
        fixed++;
      }
    }
    return fixed;
  }

  /** استيراد سجلات المشتريات من النظام الحالي (تحديث بنفس المورد والتاريخ والقسم). */
  async importPurchases(rows: { vendorNumber: string; date: string; purchases: number; returns?: number; department?: string | null }[]) {
    let created = 0;
    let updated = 0;
    const importedAt = new Date();
    for (const r of rows) {
      const vendorNumber = String(r.vendorNumber).trim();
      const dept = r.department ? String(r.department).trim() : null;
      let row = await this.purchases.findOne({ where: { vendorNumber, date: r.date, department: dept as any } });
      if (!row) row = await this.purchases.findOne({ where: { vendorNumber, date: r.date } });
      if (row) {
        row.purchases = Number(r.purchases).toFixed(2);
        row.returns = Number(r.returns ?? 0).toFixed(2);
        row.department = dept;
        row.importedAt = importedAt;
        await this.purchases.save(row);
        updated++;
      } else {
        await this.purchases.save(
          this.purchases.create({ vendorNumber, date: r.date, purchases: Number(r.purchases).toFixed(2), returns: Number(r.returns ?? 0).toFixed(2), department: dept, importedAt }),
        );
        created++;
      }
    }
    return { created, updated };
  }

  /** أقرب تاريخ مفيد لعرض «لا توجد بيانات». */
  static nextDay(iso: string) {
    return addDays(iso, 1);
  }
}
