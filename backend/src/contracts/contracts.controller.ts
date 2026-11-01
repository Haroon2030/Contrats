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
import { AddendaService } from './addenda.service';
import { MAX_ATTACHMENT_BYTES } from './attachment.storage';
import { ContractsService } from './contracts.service';
import { exportContractsXlsx } from './export';
import { WorkflowService } from './workflow.service';

const upload = () => FileInterceptor('file', { storage: memoryStorage(), limits: { fileSize: MAX_ATTACHMENT_BYTES } });

@Controller('contracts')
export class ContractsController {
  constructor(
    private readonly svc: ContractsService,
    private readonly wf: WorkflowService,
    private readonly addenda: AddendaService,
  ) {}

  @Get()
  list(@CurrentUser() user: AuthUser, @Query() query: Record<string, string>) {
    return this.svc.list(user, query);
  }

  /** تصدير السجل إلى Excel ضمن نطاق رؤية المستخدم (دون مستندات فتح الحساب). */
  @Get('export')
  async export(@CurrentUser() user: AuthUser, @Query() query: Record<string, string>, @Res() res: Response) {
    const rows = await this.svc.list(user, query);
    await this.svc.audit.log({ user, action: 'تصدير سجل العقود', entity: 'contract', note: `${rows.length} عقد` });
    const buffer = await exportContractsXlsx(rows);
    res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    res.setHeader('Content-Disposition', `attachment; filename="contracts-${new Date().toISOString().slice(0, 10)}.xlsx"`);
    res.send(buffer);
  }

  /** آخر ممثل مسجّل للمورد، لتعبئة بيانات الممثل تلقائياً. */
  @Get('vendor/:vendorId/last-rep')
  lastRep(@Param('vendorId', ParseIntPipe) vendorId: number) {
    return this.svc.lastRepresentative(vendorId);
  }

  @Post()
  create(@CurrentUser() user: AuthUser, @Body() body: Record<string, any>) {
    return this.svc.createDraft(user, body);
  }

  @Get(':id')
  detail(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    return this.svc.detail(id, user);
  }

  /** القيم بصيغة النموذج (للتعديل). */
  @Get(':id/form')
  form(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    return this.svc.formValues(user, id);
  }

  @Put(':id')
  update(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() body: Record<string, any>) {
    return this.svc.updateDraft(user, id, body);
  }

  @Delete(':id')
  remove(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    return this.svc.deleteDraft(user, id);
  }

  // ───── المرفقات ─────
  @Post(':id/attachments')
  @UseInterceptors(upload())
  attach(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body('kind') kind: string, @UploadedFile() file: Express.Multer.File) {
    return this.svc.addDraftAttachment(user, id, kind, file);
  }

  @Get(':id/attachments/:attachmentId')
  async download(
    @CurrentUser() user: AuthUser,
    @Param('id', ParseIntPipe) id: number,
    @Param('attachmentId', ParseIntPipe) attachmentId: number,
    @Res() res: Response,
  ) {
    const file = await this.svc.attachmentFile(user, id, attachmentId);
    res.download(file.path, file.name, (err) => {
      if (err && !res.headersSent) res.status(404).json({ message: 'الملف غير موجود على الخادم.' });
    });
  }

  // ───── مسار العمل ─────
  @Post(':id/actions/send')
  send(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    return this.wf.send(user, id);
  }

  @Post(':id/actions/dept-approve')
  deptApprove(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body('note') note?: string) {
    return this.wf.deptApprove(user, id, note);
  }

  @Post(':id/actions/dept-reject')
  deptReject(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body('reason') reason?: string) {
    return this.wf.deptReject(user, id, reason);
  }

  @Post(':id/actions/cm-approve')
  cmApprove(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body('note') note?: string) {
    return this.wf.cmApprove(user, id, note);
  }

  @Post(':id/actions/cm-return')
  cmReturn(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body('reason') reason?: string) {
    return this.wf.cmReturn(user, id, reason);
  }

  @Post(':id/actions/note')
  note(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body('reason') reason?: string) {
    return this.wf.addNote(user, id, reason);
  }

  @Post(':id/actions/raise-alert')
  raiseAlert(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body('reason') reason?: string) {
    return this.wf.raiseAlert(user, id, reason);
  }

  @Put(':id/actions/edit-pending')
  editPending(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() body: Record<string, any>) {
    return this.wf.editPending(user, id, body);
  }

  @Post(':id/actions/upload-signed')
  @UseInterceptors(upload())
  uploadSigned(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @UploadedFile() file: Express.Multer.File) {
    return this.wf.uploadSigned(user, id, file);
  }

  @Post(':id/actions/close-services')
  close(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body('reason') reason?: string) {
    return this.wf.closeServices(user, id, reason);
  }

  @Post(':id/actions/cancel')
  cancel(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body('reason') reason?: string) {
    return this.wf.cancel(user, id, reason);
  }

  // ───── الملاحق ─────
  @Post(':id/addenda')
  @UseInterceptors(upload())
  addAddendum(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() body: Record<string, any>, @UploadedFile() file: Express.Multer.File) {
    return this.addenda.create(user, id, body, file);
  }

  @Post(':id/addenda/:addendumId/review')
  reviewAddendum(
    @CurrentUser() user: AuthUser,
    @Param('id', ParseIntPipe) id: number,
    @Param('addendumId', ParseIntPipe) addendumId: number,
    @Body() body: { approve?: boolean; reason?: string },
  ) {
    return this.addenda.review(user, id, addendumId, body);
  }

  // ───── الطباعة ─────
  @Get(':id/print')
  async print(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Res() res: Response) {
    const data = await this.svc.printData(user, id);
    res.type('html').send(this.svc.print.render(data));
  }
}
