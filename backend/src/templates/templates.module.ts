import {
  BadRequestException,
  Body,
  Controller,
  ForbiddenException,
  Get,
  Injectable,
  Module,
  NotFoundException,
  Param,
  ParseIntPipe,
  Patch,
  Post,
  Put,
  Query,
  Res,
  UploadedFile,
  UseInterceptors,
} from '@nestjs/common';
import { FileInterceptor } from '@nestjs/platform-express';
import { InjectRepository } from '@nestjs/typeorm';
import { Response } from 'express';
import { memoryStorage } from 'multer';
import { In, Repository } from 'typeorm';
import { AuditService } from '../audit/audit.service';
import { AuthUser } from '../auth/auth.service';
import { CurrentUser } from '../common/auth.guard';
import { isoDateTime } from '../common/dates';
import { TYPE_LABELS, TYPE_ORDER } from '../common/domain';
import { AttachmentStorage, MAX_ATTACHMENT_BYTES } from '../contracts/attachment.storage';
import { DocTemplateEntity, TermClauseEntity, TermClauseVersionEntity } from '../database/entities';
import { PrintService } from './print.service';

const DEFAULT_HEADER = 'شركة محمد رشيد الرشيد للتجارة — قسم العقود';
const DEFAULT_FOOTER = 'هذا المستند صادر عن نظام إدارة العقود.';
const DEFAULT_LAYOUT = { left: 'ممثل المورد', right: 'مدير المشتريات', stamp: 'ختم الشركة' };

@Injectable()
export class TemplatesService {
  constructor(
    @InjectRepository(TermClauseEntity) private readonly clauses: Repository<TermClauseEntity>,
    @InjectRepository(TermClauseVersionEntity) private readonly versions: Repository<TermClauseVersionEntity>,
    @InjectRepository(DocTemplateEntity) private readonly docs: Repository<DocTemplateEntity>,
    private readonly audit: AuditService,
  ) {}

  /** البنود النشطة بإصداراتها الحالية (للإدارة والمعاينة). */
  async listClauses() {
    const clauses = await this.clauses.find({ order: { id: 'ASC' } });
    const rows = [];
    for (const c of clauses) {
      const v = await this.versions.findOne({ where: { clauseId: c.id, version: c.currentVersion } });
      if (v) rows.push(this.row(c, v));
    }
    return rows.sort((a, b) => a.sort - b.sort || a.id - b.id);
  }

  async clauseVersions(clauseId: number) {
    const vs = await this.versions.find({ where: { clauseId }, order: { version: 'DESC' } });
    return vs.map((v) => ({ id: v.id, version: v.version, title: v.title, body: v.body, appliesTo: v.appliesTo, sort: v.sort, status: v.status, createdAt: isoDateTime(v.createdAt) }));
  }

  async createClause(user: AuthUser, body: Record<string, unknown>) {
    const data = this.validateClause(body);
    const clause = await this.clauses.save(this.clauses.create({ status: data.status, currentVersion: 1, createdAt: new Date() }));
    const v = await this.versions.save(this.versions.create({ ...data, clauseId: clause.id, version: 1, createdById: user.id, createdAt: new Date() }));
    await this.audit.log({ user, action: 'إضافة بند شروط وأحكام', entity: 'term_clause', after: { title: data.title, appliesTo: data.appliesTo } });
    return this.row(clause, v);
  }

  /** كل حفظ = إصدار جديد. الإصدارات القديمة تبقى للعقود التي أُنشئت بها. */
  async updateClause(user: AuthUser, id: number, body: Record<string, unknown>) {
    const clause = await this.clauses.findOne({ where: { id } });
    if (!clause) throw new NotFoundException('البند غير موجود.');
    const current = await this.versions.findOne({ where: { clauseId: id, version: clause.currentVersion } });
    const data = this.validateClause({
      title: body.title ?? current?.title,
      body: body.body ?? current?.body,
      appliesTo: body.appliesTo ?? current?.appliesTo,
      sort: body.sort ?? current?.sort,
      status: body.status ?? current?.status,
    });
    const nextVersion = clause.currentVersion + 1;
    const v = await this.versions.save(this.versions.create({ ...data, clauseId: id, version: nextVersion, createdById: user.id, createdAt: new Date() }));
    clause.currentVersion = nextVersion;
    clause.status = data.status;
    await this.clauses.save(clause);
    await this.audit.log({
      user,
      action: data.status === 'stopped' && current?.status !== 'stopped' ? 'إيقاف بند' : 'تعديل بند شروط وأحكام',
      entity: 'term_clause',
      before: current ? { title: current.title, body: current.body, appliesTo: current.appliesTo, status: current.status, version: current.version } : null,
      after: { title: data.title, body: data.body, appliesTo: data.appliesTo, status: data.status, version: nextVersion },
    });
    return this.row(clause, v);
  }

  /** أرقام إصدارات البنود النشطة المنطبقة على أنواع العقد (تُحفظ في العقد عند أول إرسال). */
  async activeVersionIdsFor(types: string[]): Promise<number[]> {
    const rows = await this.listClauses();
    return rows.filter((r) => r.status === 'active' && r.appliesTo.some((t) => types.includes(t))).map((r) => r.versionId);
  }

  async versionsByIds(ids: number[] | null | undefined) {
    if (!ids?.length) return [];
    const vs = await this.versions.find({ where: { id: In(ids) } });
    return vs.sort((a, b) => a.sort - b.sort || a.id - b.id);
  }

  // ───── قالب المستند ─────
  async currentDoc(): Promise<DocTemplateEntity> {
    const latest = await this.docs.find({ order: { version: 'DESC' }, take: 1 });
    if (latest[0]) return latest[0];
    return this.docs.save(
      this.docs.create({ version: 1, logoPath: null, backgroundPath: null, headerText: DEFAULT_HEADER, footerText: DEFAULT_FOOTER, signatureLayout: DEFAULT_LAYOUT, createdById: 0, createdAt: new Date() }),
    );
  }

  async docView() {
    const d = await this.currentDoc();
    return { version: d.version, hasLogo: !!d.logoPath, hasBackground: !!d.backgroundPath, headerText: d.headerText, footerText: d.footerText, signatureLayout: d.signatureLayout, createdAt: isoDateTime(d.createdAt) };
  }

  async saveDoc(user: AuthUser, patch: Partial<{ headerText: string; footerText: string; signatureLayout: Record<string, unknown>; logoPath: string | null; backgroundPath: string | null }>, action: string) {
    const cur = await this.currentDoc();
    const next = await this.docs.save(
      this.docs.create({
        version: cur.version + 1,
        logoPath: patch.logoPath !== undefined ? patch.logoPath : cur.logoPath,
        backgroundPath: patch.backgroundPath !== undefined ? patch.backgroundPath : cur.backgroundPath,
        headerText: patch.headerText ?? cur.headerText,
        footerText: patch.footerText ?? cur.footerText,
        signatureLayout: patch.signatureLayout ?? cur.signatureLayout,
        createdById: user.id,
        createdAt: new Date(),
      }),
    );
    await this.audit.log({
      user,
      action,
      entity: 'doc_template',
      before: { version: cur.version, headerText: cur.headerText, footerText: cur.footerText },
      after: { version: next.version, headerText: next.headerText, footerText: next.footerText },
    });
    return this.docView();
  }

  private validateClause(body: Record<string, unknown>) {
    const errors: Record<string, string> = {};
    const title = String(body.title ?? '').trim();
    const text = String(body.body ?? '').trim();
    const appliesTo = (Array.isArray(body.appliesTo) ? body.appliesTo : []).filter((t): t is string => typeof t === 'string' && TYPE_ORDER.includes(t));
    const sort = Number.isInteger(Number(body.sort)) ? Number(body.sort) : 0;
    const status = body.status === 'stopped' ? 'stopped' : 'active';
    if (!title) errors.title = 'عنوان البند مطلوب.';
    if (!text) errors.body = 'نص البند مطلوب.';
    if (!appliesTo.length) errors.appliesTo = 'اختر نوعاً واحداً على الأقل ينطبق عليه البند.';
    if (Object.keys(errors).length) throw new BadRequestException({ message: 'تحقق من الحقول المعلّمة.', errors });
    return { title, body: text, appliesTo, sort, status };
  }

  private row(c: TermClauseEntity, v: TermClauseVersionEntity) {
    return { id: c.id, versionId: v.id, version: v.version, title: v.title, body: v.body, appliesTo: v.appliesTo, appliesToLabels: v.appliesTo.map((t) => TYPE_LABELS[t] ?? t), sort: v.sort, status: c.status, updatedAt: isoDateTime(v.createdAt) };
  }
}

@Controller('templates')
export class TemplatesController {
  constructor(
    private readonly svc: TemplatesService,
    private readonly print: PrintService,
    private readonly storage: AttachmentStorage,
  ) {}

  private guard(user: AuthUser) {
    if (!user.caps.canManageTemplates) throw new ForbiddenException('القوالب والشروط يديرها مدير قسم العقود.');
  }

  @Get('clauses')
  clauses(@CurrentUser() user: AuthUser) {
    this.guard(user);
    return this.svc.listClauses();
  }

  @Get('clauses/:id/versions')
  versions(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    this.guard(user);
    return this.svc.clauseVersions(id);
  }

  @Post('clauses')
  create(@CurrentUser() user: AuthUser, @Body() body: Record<string, unknown>) {
    this.guard(user);
    return this.svc.createClause(user, body);
  }

  @Patch('clauses/:id')
  update(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() body: Record<string, unknown>) {
    this.guard(user);
    return this.svc.updateClause(user, id, body);
  }

  @Get('doc')
  doc(@CurrentUser() user: AuthUser) {
    this.guard(user);
    return this.svc.docView();
  }

  @Put('doc')
  async saveDoc(@CurrentUser() user: AuthUser, @Body() body: { headerText?: string; footerText?: string; signatureLayout?: Record<string, unknown> }) {
    this.guard(user);
    return this.svc.saveDoc(user, { headerText: body.headerText?.toString(), footerText: body.footerText?.toString(), signatureLayout: body.signatureLayout }, 'تعديل قالب المستند');
  }

  @Post('doc/:slot')
  @UseInterceptors(FileInterceptor('file', { storage: memoryStorage(), limits: { fileSize: MAX_ATTACHMENT_BYTES } }))
  async upload(@CurrentUser() user: AuthUser, @Param('slot') slot: string, @UploadedFile() file: Express.Multer.File) {
    this.guard(user);
    if (slot !== 'logo' && slot !== 'background') throw new NotFoundException();
    if (!file) throw new BadRequestException('اختر صورة.');
    if (!/\.(png|jpe?g)$/i.test(file.originalname)) throw new BadRequestException('الشعار والخلفية صورة (png أو jpg).');
    const stored = await this.storage.save(file, 'templates');
    return this.svc.saveDoc(user, slot === 'logo' ? { logoPath: stored.relativePath } : { backgroundPath: stored.relativePath }, slot === 'logo' ? 'تغيير شعار القالب' : 'تغيير خلفية القالب');
  }

  /** صور القالب (الشعار/الخلفية) لصفحة الطباعة. */
  @Get('assets/:slot')
  async asset(@Param('slot') slot: string, @Res() res: Response) {
    const d = await this.svc.currentDoc();
    const rel = slot === 'logo' ? d.logoPath : slot === 'background' ? d.backgroundPath : null;
    if (!rel) throw new NotFoundException();
    res.sendFile(this.storage.absolutePath(rel));
  }

  /** معاينة عقد نموذجي بالقالب والبنود الحالية. */
  @Get('preview')
  async preview(@CurrentUser() user: AuthUser, @Query('types') types: string | undefined, @Res() res: Response) {
    this.guard(user);
    const list = (types ?? 'supply,rent,marketing,add_item').split(',').filter((t) => TYPE_ORDER.includes(t));
    res.type('html').send(await this.print.renderSample(list.length ? list : ['supply']));
  }
}

@Module({
  controllers: [TemplatesController],
  providers: [TemplatesService, PrintService, AttachmentStorage],
  exports: [TemplatesService, PrintService, AttachmentStorage],
})
export class TemplatesModule {}
