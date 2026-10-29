import { Module } from '@nestjs/common';
import { TypeOrmModule } from '@nestjs/typeorm';
import { AlertsController } from '../alerts/alerts.controller';
import { AlertsModule } from '../alerts/alerts.module';
import { ENTITIES } from '../database/entities';
import { ContractsController } from './contracts.controller';
import { ContractsService } from './contracts.service';
import { AttachmentStorage } from './attachment.storage';

@Module({
  imports: [TypeOrmModule.forFeature(ENTITIES), AlertsModule],
  controllers: [ContractsController, AlertsController],
  providers: [ContractsService, AttachmentStorage],
  exports: [ContractsService],
})
export class ContractsModule {}
