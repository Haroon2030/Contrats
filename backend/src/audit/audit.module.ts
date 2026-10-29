import { Controller, Get, Module, Query } from '@nestjs/common';
import { IsIn, IsInt, IsOptional, IsString, Matches } from 'class-validator';
import { Type } from 'class-transformer';
import { ContractsService } from '../contracts/contracts.service';
import { ContractsModule } from '../contracts/contracts.module';

class AuditQueryDto {
  @IsOptional() @Type(() => Number) @IsInt() contractId?: number;
  @IsOptional() @Type(() => Number) @IsInt() actorId?: number;
  @IsOptional() @Matches(/^\d{4}-\d{2}-\d{2}$/) from?: string;
  @IsOptional() @Matches(/^\d{4}-\d{2}-\d{2}$/) to?: string;
  @IsOptional() @Type(() => Number) @IsInt() limit?: number;
  @IsOptional() @IsString() @IsIn(['contracts', 'users']) scope?: string;
}

/** سجل التدقيق: للقراءة فقط — لا تعديل ولا حذف من الواجهة. */
@Controller('audit')
export class AuditController {
  constructor(private readonly contracts: ContractsService) {}

  @Get()
  list(@Query() query: AuditQueryDto) {
    return this.contracts.auditLogs(query);
  }
}

@Module({
  imports: [ContractsModule],
  controllers: [AuditController],
})
export class AuditModule {}
