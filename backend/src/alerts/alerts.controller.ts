import { Controller, Get, Post } from '@nestjs/common';
import { ContractsService } from '../contracts/contracts.service';
import { AlertsService } from './alerts.service';

/** إعادة الحساب على الأكثر مرة كل 5 دقائق عند القراءة، أو فوراً عبر POST /alerts/refresh. */
const REFRESH_INTERVAL_MS = 5 * 60_000;

@Controller('alerts')
export class AlertsController {
  private lastRefresh = 0;

  constructor(
    private readonly alerts: AlertsService,
    private readonly contracts: ContractsService,
  ) {}

  @Get()
  async list() {
    if (Date.now() - this.lastRefresh > REFRESH_INTERVAL_MS) {
      this.lastRefresh = Date.now();
      await this.contracts.refreshAllAlerts();
    }
    return this.alerts.listOpen();
  }

  @Post('refresh')
  async refresh() {
    await this.contracts.refreshAllAlerts();
    this.lastRefresh = Date.now();
    return this.alerts.listOpen();
  }
}
