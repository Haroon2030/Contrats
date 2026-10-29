import { Type } from 'class-transformer';
import {
  ArrayMaxSize,
  IsArray,
  IsBoolean,
  IsIn,
  IsInt,
  IsNumber,
  IsOptional,
  IsString,
  Matches,
  MaxLength,
  Min,
  MinLength,
  ValidateNested,
} from 'class-validator';
import { AttachmentKind, ContractSubtype, ContractType } from '../common/domain';

const DATE = /^\d{4}-\d{2}-\d{2}$/;

export class TargetDto {
  @IsString() @MinLength(1) @MaxLength(200) title: string;
  @Type(() => Number) @IsNumber() @Min(0) targetValue: number;
  @Type(() => Number) @IsNumber() @Min(0) discountPercent: number;
}

/** إنشاء العقد وتحديث المسودة يستخدمان نفس الشكل (التحديث يرسل النموذج كاملاً). */
export class ContractDto {
  @IsIn(Object.values(ContractType)) contractType: string;

  @IsOptional() @IsIn(Object.values(ContractSubtype)) subtype?: string;

  @IsOptional() @IsString() @MaxLength(160) internalClassification?: string;

  @Type(() => Number) @IsInt() @Min(1) vendorId: number;

  @Type(() => Number) @IsInt() @Min(1) departmentId: number;

  @Matches(DATE) startDate: string;

  @Matches(DATE) endDate: string;

  @Type(() => Number) @IsNumber() @Min(0) actualValue: number;

  @IsBoolean() hasNonStandardTerms: boolean;

  @IsOptional() @Type(() => Number) @IsInt() @Min(1) shelfRateId?: number;

  @IsOptional() @Type(() => Number) @IsNumber() @Min(0) shelfActualPrice?: number;

  @IsOptional() @IsString() @MaxLength(5000) notes?: string;

  @IsOptional() @Type(() => Number) @IsInt() @Min(1) purchasingManagerId?: number;

  @IsArray() @ArrayMaxSize(50) @ValidateNested({ each: true }) @Type(() => TargetDto) targets: TargetDto[];

  /** إرسال مباشر بعد الحفظ (بدل حفظ المسودة فقط). */
  @IsOptional() @IsBoolean() submit?: boolean;
}

export class ReasonDto {
  @IsOptional() @IsString() @MaxLength(2000) comment?: string;
}

export class CancelDto {
  @IsString() @MinLength(3) @MaxLength(2000) reason: string;
}

export class EscalateDto {
  @IsString() @MinLength(3) @MaxLength(2000) reason: string;
}

export class NoteDto {
  @IsString() @MinLength(1) @MaxLength(2000) text: string;
}

export class DecisionDto {
  @IsBoolean() approved: boolean;
  @IsOptional() @IsString() @MaxLength(2000) comment?: string;
}

export class AttachmentDto {
  @IsIn(Object.values(AttachmentKind)) kind: string;
}

export class ContractQueryDto {
  @IsOptional() @IsString() q?: string;
  @IsOptional() @IsString() status?: string;
  @IsOptional() @IsString() contractType?: string;
  @IsOptional() @Type(() => Number) @IsInt() departmentId?: number;
  @IsOptional() @IsIn(['30', '60', '90']) expiringWithin?: string;
  @IsOptional() @IsIn(['mine', 'awaiting']) view?: string;
}
