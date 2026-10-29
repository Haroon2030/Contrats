import { BadRequestException, Body, Controller, ForbiddenException, Get, Injectable, Module, NotFoundException, Param, ParseIntPipe, Patch, Post, Query } from '@nestjs/common';
import { TypeOrmModule } from '@nestjs/typeorm';
import { Type } from 'class-transformer';
import { IsBoolean, IsIn, IsInt, IsOptional, IsString, MaxLength, Min, MinLength } from 'class-validator';
import { DataSource } from 'typeorm';
import { AuthUser } from '../auth/auth.service';
import { CurrentUser } from '../common/auth.guard';
import { makeDjangoPassword } from '../common/django-password';
import { ROLE_LABELS, ROLES, Role, roleFlags } from '../common/domain';
import { DepartmentEntity, ENTITIES, UserEntity, UserProfileEntity } from '../database/entities';

class UserDto {
  @IsString() @MinLength(3) @MaxLength(150) username: string;
  @IsString() @MaxLength(150) firstName: string;
  @IsOptional() @IsString() @MaxLength(150) lastName?: string;
  /** عند الإنشاء إلزامية؛ عند التعديل تُترك فارغة للإبقاء على الحالية. */
  @IsOptional() @IsString() @MinLength(6) @MaxLength(128) password?: string;
  @IsIn(ROLES) role: string;
  @IsOptional() @Type(() => Number) @IsInt() @Min(1) departmentId?: number;
  @IsOptional() @IsString() @MaxLength(120) jobTitle?: string;
  @IsOptional() @IsString() @MaxLength(20) phone?: string;
  @IsOptional() @IsBoolean() isActive?: boolean;
}

@Injectable()
export class UsersService {
  constructor(
    private readonly ds: DataSource,
  ) {}

  async list(q?: string, role?: string) {
    const qb = this.ds
      .getRepository(UserEntity)
      .createQueryBuilder('u')
      .leftJoinAndSelect('u.profile', 'p')
      .leftJoinAndSelect('p.department', 'd')
      .orderBy('u.username', 'ASC');
    if (q?.trim()) qb.andWhere('(u.username LIKE :q OR u.firstName LIKE :q OR u.lastName LIKE :q)', { q: `%${q.trim()}%` });
    if (role) qb.andWhere('p.role = :role', { role });
    const users = await qb.getMany();
    return users.map((u) => this.toRow(u));
  }

  async create(dto: UserDto, actor: AuthUser) {
    this.requireAdmin(actor);
    if (!dto.password) throw new BadRequestException('كلمة المرور مطلوبة.');
    if (dto.role === Role.PURCHASING_HEAD && !dto.departmentId) throw new BadRequestException('مسؤول الإدارة يجب ربطه بقسم.');
    const username = dto.username.trim();
    const repo = this.ds.getRepository(UserEntity);
    if (await repo.createQueryBuilder('u').where('LOWER(u.username) = LOWER(:u)', { u: username }).getExists()) {
      throw new BadRequestException('اسم المستخدم مستخدم مسبقاً.');
    }

    return this.ds.transaction(async (m) => {
      const isAdmin = dto.role === Role.ADMIN;
      const user = await m.save(
        m.create(UserEntity, {
          username,
          password: makeDjangoPassword(dto.password!),
          firstName: dto.firstName ?? '',
          lastName: dto.lastName ?? '',
          email: '',
          isActive: dto.isActive ?? true,
          isStaff: isAdmin,
          isSuperuser: isAdmin,
          dateJoined: new Date(),
          lastLogin: null,
        }),
      );
      await m.save(
        m.create(UserProfileEntity, {
          userId: user.id,
          role: dto.role,
          departmentId: dto.departmentId ?? null,
          jobTitle: dto.jobTitle ?? '',
          phone: dto.phone ?? '',
        }),
      );
      return this.getOne(user.id);
    });
  }

  async update(id: number, dto: UserDto, actor: AuthUser) {
    this.requireAdmin(actor);
    const target = await this.ds.getRepository(UserEntity).findOne({ where: { id }, relations: { profile: true } });
    if (!target) throw new NotFoundException('المستخدم غير موجود.');
    if (dto.role === Role.PURCHASING_HEAD && !dto.departmentId) throw new BadRequestException('مسؤول الإدارة يجب ربطه بقسم.');
    if (actor.id === id && dto.isActive === false) throw new BadRequestException('لا يمكنك تعطيل حسابك أثناء الجلسة.');

    const wasAdmin = target.profile?.role === Role.ADMIN;
    const remainsAdmin = dto.role === Role.ADMIN && (dto.isActive ?? true);
    if (wasAdmin && !remainsAdmin) {
      const otherAdmins = await this.ds
        .getRepository(UserEntity)
        .createQueryBuilder('u')
        .innerJoin('u.profile', 'p')
        .where('u.isActive = :a AND p.role = :r AND u.id <> :id', { a: true, r: Role.ADMIN, id })
        .getCount();
      if (!otherAdmins) throw new BadRequestException('لا يمكن تعطيل أو تنزيل آخر مدير نظام نشط.');
    }

    const username = dto.username.trim();
    const clash = await this.ds
      .getRepository(UserEntity)
      .createQueryBuilder('u')
      .where('LOWER(u.username) = LOWER(:u) AND u.id <> :id', { u: username, id })
      .getExists();
    if (clash) throw new BadRequestException('اسم المستخدم مستخدم مسبقاً.');

    await this.ds.transaction(async (m) => {
      const isAdmin = dto.role === Role.ADMIN;
      Object.assign(target, {
        username,
        firstName: dto.firstName ?? '',
        lastName: dto.lastName ?? '',
        isActive: dto.isActive ?? true,
        isStaff: isAdmin,
        isSuperuser: isAdmin,
      });
      if (dto.password) target.password = makeDjangoPassword(dto.password);
      await m.save(target);

      const profile = (await m.findOne(UserProfileEntity, { where: { userId: id } })) ?? m.create(UserProfileEntity, { userId: id });
      Object.assign(profile, {
        role: dto.role,
        departmentId: dto.departmentId ?? null,
        jobTitle: dto.jobTitle ?? '',
        phone: dto.phone ?? '',
      });
      await m.save(profile);
    });
    return this.getOne(id);
  }

  async departmentsOptions() {
    return this.ds.getRepository(DepartmentEntity).find({ where: { isActive: true }, order: { name: 'ASC' } });
  }

  private requireAdmin(actor: AuthUser) {
    if (!roleFlags(actor.role).canManageUsers) throw new ForbiddenException('إدارة المستخدمين مقصورة على مدير النظام.');
  }

  private async getOne(id: number) {
    const u = await this.ds.getRepository(UserEntity).findOne({ where: { id }, relations: { profile: { department: true } } });
    return this.toRow(u!);
  }

  private toRow(u: UserEntity) {
    const role = u.profile?.role ?? '';
    return {
      id: u.id,
      username: u.username,
      firstName: u.firstName,
      lastName: u.lastName,
      fullName: `${u.firstName} ${u.lastName}`.trim() || u.username,
      role,
      roleLabel: ROLE_LABELS[role] ?? role,
      departmentId: u.profile?.departmentId ?? null,
      departmentName: u.profile?.department?.name ?? null,
      jobTitle: u.profile?.jobTitle ?? '',
      phone: u.profile?.phone ?? '',
      isActive: u.isActive,
      lastLogin: u.lastLogin,
    };
  }
}

@Controller('users')
export class UsersController {
  constructor(private readonly users: UsersService) {}

  @Get()
  list(@CurrentUser() user: AuthUser, @Query('q') q?: string, @Query('role') role?: string) {
    if (!user.canManageUsers) throw new ForbiddenException('إدارة المستخدمين مقصورة على مدير النظام.');
    return this.users.list(q, role);
  }

  @Post()
  create(@CurrentUser() user: AuthUser, @Body() dto: UserDto) {
    return this.users.create(dto, user);
  }

  @Patch(':id')
  update(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() dto: UserDto) {
    return this.users.update(id, dto, user);
  }

  @Get('departments')
  departments() {
    return this.users.departmentsOptions();
  }
}

@Module({
  imports: [TypeOrmModule.forFeature(ENTITIES)],
  controllers: [UsersController],
  providers: [UsersService],
  exports: [UsersService],
})
export class UsersModule {}
