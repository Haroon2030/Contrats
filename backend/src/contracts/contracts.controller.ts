import {
  Body,
  Controller,
  Delete,
  Get,
  Param,
  ParseIntPipe,
  Post,
  Put,
  Query,
  Res,
  UploadedFile,
  UseInterceptors,
} from '@nestjs/common';
import { FileInterceptor } from '@nestjs/platform-express';
import { Response } from 'express';
import { memoryStorage } from 'multer';
import { AuthUser } from '../auth/auth.service';
import { CurrentUser } from '../common/auth.guard';
import { MAX_ATTACHMENT_BYTES } from './attachment.storage';
import { AttachmentDto, CancelDto, ContractDto, ContractQueryDto, DecisionDto, EscalateDto, NoteDto } from './dto';
import { ContractsService } from './contracts.service';

@Controller('contracts')
export class ContractsController {
  constructor(private readonly contracts: ContractsService) {}

  @Get()
  list(@CurrentUser() user: AuthUser, @Query() query: ContractQueryDto) {
    return this.contracts.list(user, query);
  }

  @Post()
  create(@CurrentUser() user: AuthUser, @Body() dto: ContractDto) {
    return this.contracts.create(dto, user);
  }

  @Get(':id')
  detail(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    return this.contracts.detail(id, user);
  }

  @Put(':id')
  update(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() dto: ContractDto) {
    return this.contracts.update(id, dto, user);
  }

  @Delete(':id')
  deleteDraft(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    return this.contracts.deleteDraft(id, user);
  }

  @Post(':id/actions/submit')
  submit(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    return this.contracts.submit(id, user);
  }

  @Post(':id/actions/legal-review')
  legalReview(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() dto: DecisionDto) {
    return this.contracts.legalReview(id, user, dto);
  }

  @Post(':id/actions/decide')
  decide(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() dto: DecisionDto) {
    return this.contracts.decide(id, user, dto);
  }

  @Post(':id/actions/escalate')
  escalate(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() dto: EscalateDto) {
    return this.contracts.escalate(id, user, dto.reason);
  }

  @Post(':id/actions/mark-signed')
  markSigned(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    return this.contracts.markSigned(id, user);
  }

  @Post(':id/actions/cancel')
  cancel(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() dto: CancelDto) {
    return this.contracts.cancel(id, user, dto.reason);
  }

  @Post(':id/notes')
  note(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() dto: NoteDto) {
    return this.contracts.note(id, user, dto.text);
  }

  @Post(':id/attachments')
  @UseInterceptors(FileInterceptor('file', { storage: memoryStorage(), limits: { fileSize: MAX_ATTACHMENT_BYTES } }))
  upload(
    @CurrentUser() user: AuthUser,
    @Param('id', ParseIntPipe) id: number,
    @Body() dto: AttachmentDto,
    @UploadedFile() file: Express.Multer.File,
  ) {
    return this.contracts.addAttachment(id, user, dto, file);
  }

  @Get(':id/attachments/:attachmentId')
  async download(
    @Param('id', ParseIntPipe) id: number,
    @Param('attachmentId', ParseIntPipe) attachmentId: number,
    @Res() res: Response,
  ) {
    const file = await this.contracts.attachmentFor(id, attachmentId);
    res.download(file.path, file.name, (err) => {
      if (err && !res.headersSent) res.status(404).json({ message: 'الملف غير موجود على الخادم.' });
    });
  }
}
