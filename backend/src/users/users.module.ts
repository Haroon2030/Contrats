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
  Query,
} from '@nestjs/common';
import { InjectRepository } from '@nestjs/typeorm';
import { randomInt } from 'crypto';
import { Repository } from 'typeorm';
import { AuthUser } from '../auth/auth.service';
import { AuditService } from '../audit/audit.service';
import { CurrentUser } from '../common/auth.guard';
import { isoDateTime } from '../common/dates';
import { makeDjangoPassword } from '../common/django-password';
import { ROLES, ROLE_LABELS, Role, normalizeRole } from '../common/domain';
import { DepartmentEntity, UserEntity } from '../database/entities';

const DEPT_ROLES: string[] = [Role.DEPARTMENT_MANAGER, Role.DEPARTMENT_OFFICIAL];

/** كلمة مرور مؤقتة مقروءة: حروف وأرقام بلا أحرف ملتبسة. */
export function temporaryPassword(): string {
  const letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
  const digits = '23456789';
  const all = letters + digits;
  const chars = [letters[randomInt(letters.length)], digits[randomInt(digits.length)]];
  while (chars.length < 10) chars.push(all[randomInt(all.length)]);
  for (let i = chars.length - 1; i > 0; i--) {
    const j = randomInt(i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }
  return chars.join('');
}

@Injectable()
export class UsersService {
  constructor(
    @InjectRepository(UserEntity) private readonly users: Repository<UserEntity>,
    @InjectRepository(DepartmentEntity) private readonly departments: Repository<DepartmentEntity>,
    private readonly audit: AuditService,
  ) {}

  async list(q?: string, role?: string) {
    const qb = this.users.createQueryBuilder('u').orderBy('u.username', 'ASC');
    if (q?.trim()) qb.andWhere('(u.username LIKE :q OR u.fullName LIKE :q OR u.email LIKE :q)', { q: `%${q.trim()}%` });
    if (role) qb.andWhere('u.role = :role', { role });
    const rows = await qb.getMany();
    const depts = new Map((await this.departments.find()).map((d) => [d.id, d.name]));
    return rows.map((u) => this.row(u, depts));
  }

  async create(actor: AuthUser, body: Record<string, unknown>) {
    const data = await this.validate(body, null);
    const password = temporaryPassword();
    const user = await this.users.save(
      this.users.create({
        ...data,
        passwordHash: makeDjangoPassword(password),
        isActive: body.isActive === false ? false : true,
        mustChangePassword: true,
        failedAttempts: 0,
        lockedUntil: null,
        lastLoginAt: null,
        createdAt: new Date(),
      }),
    );
    await this.audit.log({ user: actor, action: 'إنشاء مستخدم', entity: 'user', after: this.safe(user) });
    return { user: await this.one(user.id), temporaryPassword: password };
  }

  async update(actor: AuthUser, id: number, body: Record<string, unknown>) {
    const target = await this.users.findOne({ where: { id } });
    if (!target) throw new NotFoundException('المستخدم غير موجود.');
    const before = this.safe(target);
    const data = await this.validate({ ...body, role: body.role ?? target.role }, target);
    const active = body.isActive === undefined ? target.isActive : Boolean(body.isActive);

    if (actor.id === id && !active) throw this.err('isActive', 'لا يمكنك تعطيل حسابك.');
    const wasAdmin = normalizeRole(target.role) === Role.SYSTEM_ADMIN && target.isActive;
    const staysAdmin = data.role === Role.SYSTEM_ADMIN && active;
    if (wasAdmin && !staysAdmin) {
      const others = await this.users
        .createQueryBuilder('u')
        .where('u.role = :r AND u.isActive = :a AND u.id <> :id', { r: Role.SYSTEM_ADMIN, a: true, id })
        .getCount();
      if (!others) throw this.err('role', 'لا يمكن تعطيل أو تنزيل آخر مسؤول نظام نشط.');
    }

    Object.assign(target, data, { isActive: active });
    await this.users.save(target);
    await this.audit.log({ user: actor, action: 'تعديل مستخدم', entity: 'user', before, after: this.safe(target) });
    return this.one(id);
  }

  async resetPassword(actor: AuthUser, id: number) {
    const target = await this.users.findOne({ where: { id } });
    if (!target) throw new NotFoundException('المستخدم غير موجود.');
    const password = temporaryPassword();
    target.passwordHash = makeDjangoPassword(password);
    target.mustChangePassword = true;
    target.failedAttempts = 0;
    target.lockedUntil = null;
    await this.users.save(target);
    await this.audit.log({ user: actor, action: 'إعادة تعيين كلمة المرور', entity: 'user', note: target.username });
    return { temporaryPassword: password };
  }

  private async validate(body: Record<string, unknown>, existing: UserEntity | null) {
    const errors: Record<string, string> = {};
    const username = String(body.username ?? existing?.username ?? '').trim();
    const fullName = String(body.fullName ?? '').trim();
    const emailRaw = String(body.email ?? '').trim();
    const role = normalizeRole(String(body.role ?? ''));
    const departmentId = body.departmentId ? Number(body.departmentId) : null;

    if (username.length < 1) errors.username = 'اسم المستخدم مطلوب.';
    if (!fullName) errors.fullName = 'الاسم الكامل مطلوب.';
    if (emailRaw && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailRaw)) errors.email = 'البريد الإلكتروني غير صالح.';
    if (!ROLES.includes(role)) errors.role = 'اختر دوراً صحيحاً.';
    if (DEPT_ROLES.includes(role) && !departmentId) errors.departmentId = 'القسم إلزامي لمدير ومسؤول القسم.';
    if (departmentId) {
      const dept = await this.departments.findOne({ where: { id: departmentId } });
      if (!dept) errors.departmentId = 'القسم غير موجود.';
    }
    if (!errors.username) {
      const clash = await this.users
        .createQueryBuilder('u')
        .where('LOWER(u.username) = LOWER(:u)', { u: username })
        .andWhere(existing ? 'u.id <> :id' : '1=1', { id: existing?.id })
        .getExists();
      if (clash) errors.username = 'اسم المستخدم مستخدم مسبقاً.';
    }
    if (Object.keys(errors).length) throw new BadRequestException({ message: 'تحقق من الحقول المعلّمة.', errors });
    return { username, fullName, email: emailRaw || null, role, departmentId: DEPT_ROLES.includes(role) ? departmentId : null };
  }

  private err(field: string, message: string) {
    return new BadRequestException({ message, errors: { [field]: message } });
  }

  private async one(id: number) {
    const u = await this.users.findOne({ where: { id } });
    const depts = new Map((await this.departments.find()).map((d) => [d.id, d.name]));
    return this.row(u!, depts);
  }

  private safe(u: UserEntity) {
    return { username: u.username, fullName: u.fullName, email: u.email, role: u.role, departmentId: u.departmentId, isActive: u.isActive };
  }

  private row(u: UserEntity, depts: Map<number, string>) {
    const role = normalizeRole(u.role);
    return {
      id: u.id,
      username: u.username,
      fullName: u.fullName,
      email: u.email,
      role,
      roleLabel: ROLE_LABELS[role] ?? role,
      departmentId: u.departmentId,
      departmentName: u.departmentId ? depts.get(u.departmentId) ?? null : null,
      isActive: u.isActive,
      mustChangePassword: u.mustChangePassword,
      lockedUntil: isoDateTime(u.lockedUntil),
      lastLoginAt: isoDateTime(u.lastLoginAt),
    };
  }
}

@Controller('users')
export class UsersController {
  constructor(private readonly users: UsersService) {}

  private admin(user: AuthUser) {
    if (!user.caps.canManageUsers) throw new ForbiddenException('إدارة المستخدمين مقصورة على مسؤول النظام.');
  }

  @Get()
  list(@CurrentUser() user: AuthUser, @Query('q') q?: string, @Query('role') role?: string) {
    this.admin(user);
    return this.users.list(q, role);
  }

  @Post()
  create(@CurrentUser() user: AuthUser, @Body() body: Record<string, unknown>) {
    this.admin(user);
    return this.users.create(user, body);
  }

  @Patch(':id')
  update(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() body: Record<string, unknown>) {
    this.admin(user);
    return this.users.update(user, id, body);
  }

  @Post(':id/reset-password')
  reset(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    this.admin(user);
    return this.users.resetPassword(user, id);
  }
}

@Module({
  controllers: [UsersController],
  providers: [UsersService],
  exports: [UsersService],
})
export class UsersModule {}
