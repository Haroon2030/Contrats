import { BadRequestException, HttpException, HttpStatus, Injectable, UnauthorizedException } from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository } from 'typeorm';
import { Caps, ROLE_LABELS, capsOf, normalizeRole } from '../common/domain';
import { makeDjangoPassword, verifyDjangoPassword } from '../common/django-password';
import { DepartmentEntity, UserEntity } from '../database/entities';
import { AuditService } from '../audit/audit.service';

export interface AuthUser {
  id: number;
  username: string;
  fullName: string;
  role: string;
  roleLabel: string;
  departmentId: number | null;
  departmentName: string | null;
  mustChangePassword: boolean;
  caps: Caps;
}

export const LOCK_AFTER_FAILURES = 5;
export const LOCK_MINUTES = 15;
export const SESSION_MINUTES = 30;

/** كلمة المرور: 8 خانات على الأقل، تجمع حروفاً وأرقاماً. */
export function passwordProblem(password: string): string | null {
  if (password.length < 8) return 'كلمة المرور 8 خانات على الأقل.';
  if (!/[A-Za-z؀-ۿ]/.test(password) || !/\d/.test(password)) return 'كلمة المرور يجب أن تجمع حروفاً وأرقاماً.';
  return null;
}

@Injectable()
export class AuthService {
  constructor(
    @InjectRepository(UserEntity) private readonly users: Repository<UserEntity>,
    @InjectRepository(DepartmentEntity) private readonly departments: Repository<DepartmentEntity>,
    private readonly jwt: JwtService,
    private readonly audit: AuditService,
  ) {}

  async login(username: string, password: string) {
    const user = await this.users.findOne({ where: { username: username.trim() } });
    const now = new Date();

    if (user?.lockedUntil && user.lockedUntil.getTime() > now.getTime()) {
      throw new HttpException(`تم إيقاف الدخول مؤقتاً. حاول بعد ${LOCK_MINUTES} دقيقة.`, HttpStatus.TOO_MANY_REQUESTS);
    }

    const ok = !!user && user.isActive && verifyDjangoPassword(password, user.passwordHash);
    if (!ok) {
      if (user) {
        user.failedAttempts += 1;
        if (user.failedAttempts >= LOCK_AFTER_FAILURES) {
          user.lockedUntil = new Date(now.getTime() + LOCK_MINUTES * 60_000);
          user.failedAttempts = 0;
        }
        await this.users.save(user);
        await this.audit.log({ user: null, action: 'محاولة دخول فاشلة', entity: 'user', note: user.username });
        if (user.lockedUntil && user.lockedUntil.getTime() > now.getTime()) {
          throw new HttpException(`تم إيقاف الدخول مؤقتاً بعد 5 محاولات خاطئة. حاول بعد ${LOCK_MINUTES} دقيقة.`, HttpStatus.TOO_MANY_REQUESTS);
        }
      }
      throw new UnauthorizedException('اسم المستخدم أو كلمة المرور غير صحيحة.');
    }

    user.failedAttempts = 0;
    user.lockedUntil = null;
    user.lastLoginAt = now;
    await this.users.save(user);
    const authUser = await this.toAuthUser(user);
    await this.audit.log({ user: authUser, action: 'تسجيل دخول', entity: 'user', note: '' });
    return { token: await this.sign(user.id), user: authUser };
  }

  sign(userId: number) {
    return this.jwt.signAsync({ sub: userId }, { expiresIn: SESSION_MINUTES * 60 });
  }

  async verifyToken(token: string): Promise<{ user: AuthUser; remainingSeconds: number } | null> {
    try {
      const payload = await this.jwt.verifyAsync<{ sub: number; exp: number }>(token);
      const user = await this.users.findOne({ where: { id: payload.sub } });
      if (!user || !user.isActive) return null;
      return { user: await this.toAuthUser(user), remainingSeconds: payload.exp - Math.floor(Date.now() / 1000) };
    } catch {
      return null;
    }
  }

  async changePassword(userId: number, current: string, next: string) {
    const user = await this.users.findOne({ where: { id: userId } });
    if (!user || !verifyDjangoPassword(current, user.passwordHash)) throw new BadRequestException({ message: 'كلمة المرور الحالية غير صحيحة.', errors: { current: 'كلمة المرور الحالية غير صحيحة.' } });
    const problem = passwordProblem(next);
    if (problem) throw new BadRequestException({ message: problem, errors: { next: problem } });
    if (current === next) throw new BadRequestException({ message: 'اختر كلمة مرور مختلفة عن الحالية.', errors: { next: 'اختر كلمة مرور مختلفة عن الحالية.' } });
    user.passwordHash = makeDjangoPassword(next);
    user.mustChangePassword = false;
    await this.users.save(user);
    const authUser = await this.toAuthUser(user);
    await this.audit.log({ user: authUser, action: 'تغيير كلمة المرور', entity: 'user', note: '' });
    return authUser;
  }

  async toAuthUser(user: UserEntity): Promise<AuthUser> {
    const role = normalizeRole(user.role);
    const dept = user.departmentId ? await this.departments.findOne({ where: { id: user.departmentId } }) : null;
    return {
      id: user.id,
      username: user.username,
      fullName: user.fullName || user.username,
      role,
      roleLabel: ROLE_LABELS[role] ?? role,
      departmentId: user.departmentId,
      departmentName: dept?.name ?? null,
      mustChangePassword: user.mustChangePassword,
      caps: capsOf(role),
    };
  }
}
