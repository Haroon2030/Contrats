import { BadRequestException, Body, Controller, ForbiddenException, Get, Module, Param, ParseIntPipe, Patch, Post, Query } from '@nestjs/common';
import { TypeOrmModule } from '@nestjs/typeorm';
import { Type } from 'class-transformer';
import { IsBoolean, IsNumber, IsOptional, IsString, Matches, MaxLength, Min, MinLength } from 'class-validator';
import { DataSource } from 'typeorm';
import { AuthUser } from '../auth/auth.service';
import { CurrentUser } from '../common/auth.guard';
import { ROLE_LABELS, roleFlags } from '../common/domain';
import { DepartmentEntity, ENTITIES, ShelfRateEntity, UserEntity, VendorEntity } from '../database/entities';

class VendorDto {
  @Matches(/^[\w-]{2,30}$/) vendorNumber: string;
  @IsString() @MinLength(2) @MaxLength(200) legalName: string;
  @IsOptional() @IsString() @MaxLength(200) tradeName?: string;
  @IsOptional() @IsString() @MaxLength(40) crNumber?: string;
  @IsOptional() @IsString() @MaxLength(120) contactName?: string;
  @IsOptional() @IsString() @MaxLength(30) phone?: string;
  @IsOptional() @IsString() @MaxLength(254) email?: string;
  @IsOptional() @IsBoolean() isActive?: boolean;
}

class DepartmentDto {
  @IsString() @MinLength(2) @MaxLength(120) name: string;
  @IsString() @MinLength(1) @MaxLength(20) code: string;
  @IsOptional() @IsBoolean() isActive?: boolean;
}

class ShelfRateDto {
  @IsString() @MinLength(1) @MaxLength(40) code: string;
  @IsString() @MinLength(2) @MaxLength(120) name: string;
  @IsOptional() @IsString() @MaxLength(40) unit?: string;
  @Type(() => Number) @IsNumber() @Min(0) standardPrice: number;
  @IsOptional() @IsBoolean() isActive?: boolean;
}

/**
 * القوائم المرجعية: الإضافة والتعطيل فقط — لا حذف (كما في النظام القديم).
 * القراءة متاحة لكل مستخدم مسجّل، والكتابة لمدير النظام.
 */
@Controller('reference')
export class ReferenceController {
  constructor(private readonly ds: DataSource) {}

  /** بيانات النماذج: الموردون والأقسام وأنواع الأرفف والمستخدمون النشطون. */
  @Get('options')
  async options() {
    const [vendors, departments, shelfRates, users] = await Promise.all([
      this.ds.getRepository(VendorEntity).find({ where: { isActive: true }, order: { legalName: 'ASC' } }),
      this.ds.getRepository(DepartmentEntity).find({ where: { isActive: true }, order: { name: 'ASC' } }),
      this.ds.getRepository(ShelfRateEntity).find({ where: { isActive: true }, order: { name: 'ASC' } }),
      this.ds.getRepository(UserEntity).find({ where: { isActive: true }, relations: { profile: true }, order: { username: 'ASC' } }),
    ]);
    return {
      vendors: vendors.map((v) => ({ id: v.id, vendorNumber: v.vendorNumber, name: v.tradeName || v.legalName })),
      departments: departments.map((d) => ({ id: d.id, name: d.name, code: d.code })),
      shelfRates: shelfRates.map((s) => ({ id: s.id, name: s.name, unit: s.unit, standardPrice: Number(s.standardPrice) })),
      users: users
        .filter((u) => u.profile)
        .map((u) => ({ id: u.id, name: `${u.firstName} ${u.lastName}`.trim() || u.username, role: u.profile.role, roleLabel: ROLE_LABELS[u.profile.role] ?? '' })),
    };
  }

  @Get('vendors')
  vendors(@Query('includeInactive') includeInactive?: string) {
    return this.ds.getRepository(VendorEntity).find({
      where: includeInactive === '1' ? {} : { isActive: true },
      order: { legalName: 'ASC' },
    });
  }

  @Post('vendors')
  async createVendor(@CurrentUser() user: AuthUser, @Body() dto: VendorDto) {
    this.admin(user);
    await this.assertUnique(VendorEntity, 'vendorNumber', dto.vendorNumber, 'رقم المورد مستخدم مسبقاً.');
    return this.ds.getRepository(VendorEntity).save(this.ds.getRepository(VendorEntity).create({ ...this.vendorFields(dto) }));
  }

  @Patch('vendors/:id')
  async updateVendor(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() dto: VendorDto) {
    this.admin(user);
    const repo = this.ds.getRepository(VendorEntity);
    const row = await repo.findOne({ where: { id } });
    if (!row) throw new BadRequestException('المورد غير موجود.');
    await this.assertUnique(VendorEntity, 'vendorNumber', dto.vendorNumber, 'رقم المورد مستخدم مسبقاً.', id);
    return repo.save(Object.assign(row, this.vendorFields(dto)));
  }

  @Get('departments')
  departments(@Query('includeInactive') includeInactive?: string) {
    return this.ds.getRepository(DepartmentEntity).find({
      where: includeInactive === '1' ? {} : { isActive: true },
      order: { name: 'ASC' },
    });
  }

  @Post('departments')
  async createDepartment(@CurrentUser() user: AuthUser, @Body() dto: DepartmentDto) {
    this.admin(user);
    await this.assertUnique(DepartmentEntity, 'code', dto.code, 'رمز القسم مستخدم مسبقاً.');
    const repo = this.ds.getRepository(DepartmentEntity);
    return repo.save(repo.create({ name: dto.name, code: dto.code, isActive: dto.isActive ?? true }));
  }

  @Patch('departments/:id')
  async updateDepartment(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() dto: DepartmentDto) {
    this.admin(user);
    const repo = this.ds.getRepository(DepartmentEntity);
    const row = await repo.findOne({ where: { id } });
    if (!row) throw new BadRequestException('القسم غير موجود.');
    await this.assertUnique(DepartmentEntity, 'code', dto.code, 'رمز القسم مستخدم مسبقاً.', id);
    return repo.save(Object.assign(row, { name: dto.name, code: dto.code, isActive: dto.isActive ?? row.isActive }));
  }

  @Get('shelf-rates')
  shelfRates(@Query('includeInactive') includeInactive?: string) {
    return this.ds.getRepository(ShelfRateEntity).find({
      where: includeInactive === '1' ? {} : { isActive: true },
      order: { name: 'ASC' },
    });
  }

  @Post('shelf-rates')
  async createShelfRate(@CurrentUser() user: AuthUser, @Body() dto: ShelfRateDto) {
    this.admin(user);
    await this.assertUnique(ShelfRateEntity, 'code', dto.code, 'الرمز مستخدم مسبقاً.');
    const repo = this.ds.getRepository(ShelfRateEntity);
    return repo.save(repo.create(this.shelfFields(dto)));
  }

  @Patch('shelf-rates/:id')
  async updateShelfRate(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number, @Body() dto: ShelfRateDto) {
    this.admin(user);
    const repo = this.ds.getRepository(ShelfRateEntity);
    const row = await repo.findOne({ where: { id } });
    if (!row) throw new BadRequestException('نوع الرف غير موجود.');
    await this.assertUnique(ShelfRateEntity, 'code', dto.code, 'الرمز مستخدم مسبقاً.', id);
    return repo.save(Object.assign(row, this.shelfFields(dto)));
  }

  private admin(user: AuthUser) {
    if (!roleFlags(user.role).canManageUsers) throw new ForbiddenException('القوائم المرجعية تُدار من مدير النظام.');
  }

  private async assertUnique(entity: any, field: string, value: string, message: string, exceptId?: number) {
    const repo = this.ds.getRepository(entity);
    const qb = repo.createQueryBuilder('r').where(`r.${field} = :value`, { value });
    if (exceptId) qb.andWhere('r.id <> :id', { id: exceptId });
    if (await qb.getExists()) throw new BadRequestException(message);
  }

  private vendorFields(dto: VendorDto) {
    return {
      vendorNumber: dto.vendorNumber,
      legalName: dto.legalName,
      tradeName: dto.tradeName ?? '',
      crNumber: dto.crNumber ?? '',
      contactName: dto.contactName ?? '',
      phone: dto.phone ?? '',
      email: dto.email ?? '',
      isActive: dto.isActive ?? true,
    };
  }

  private shelfFields(dto: ShelfRateDto) {
    return {
      code: dto.code,
      name: dto.name,
      unit: dto.unit ?? 'شهرياً',
      standardPrice: String(dto.standardPrice),
      isActive: dto.isActive ?? true,
    };
  }
}

@Module({
  imports: [TypeOrmModule.forFeature(ENTITIES)],
  controllers: [ReferenceController],
})
export class ReferenceModule {}
