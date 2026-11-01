import { Module } from '@nestjs/common';
import { GoalsModule } from '../goals/goals.module';
import { TemplatesModule } from '../templates/templates.module';
import { AddendaService } from './addenda.service';
import { ContractsController } from './contracts.controller';
import { ContractsService } from './contracts.service';
import { WorkflowService } from './workflow.service';

@Module({
  imports: [GoalsModule, TemplatesModule],
  controllers: [ContractsController],
  providers: [ContractsService, WorkflowService, AddendaService],
  exports: [ContractsService, WorkflowService, AddendaService],
})
export class ContractsModule {}
