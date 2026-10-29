import { HttpException, HttpStatus, Injectable, UnauthorizedException } from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import { InjectRepository } from '@nestjs/typeorm';
import { Repository } from 'typeorm';
import { ROLE_LABELS, roleFlags, RoleFlags } from '../common/domain';
import { verifyDjangoPassword } from '../common/django-password';
import { UserEntity } from '../database/entities';

export interface AuthUser extends RoleFlags {
  id: number;
  username: string;
  fullName: string;
  role: string;
  roleLabel: string;
  jobTitle: string;
  departmentId: number | null;
  departmentName: string | null;
  mustChangePassword: false;
}

const LOCK_LIMIT = 5; // المواصفة: قفل بعد 5 محاولات فاشلة
const LOCK_MINUTES = 15;

@Injectable()
export class AuthService {
  /** عدّاد المحاولات الفاشلة لكل اسم مستخدم (في الذاكرة — يُفرَّغ عند إعادة التشغيل). */
  private failures = new Map<string, { count: number; lockedUntil: number }>();

  constructor(
    @InjectRepository(UserEntity) private readonly users: Repository<UserEntity>,
    private readonly jwt: JwtService,
  ) {}

  async login(username: string, password: string) {
    const key = username.trim().toLowerCase();
    const state = this.failures.get(key);
    if (state && state.lockedUntil > Date.now()) {
      throw new HttpException(
        `تم قفل الدخول مؤقتاً بعد تجاوز عدد المحاولات. حاول بعد ${LOCK_MINUTES} دقيقة.`,
        HttpStatus.TOO_MANY_REQUESTS,
      );
    }

    const user = await this.users.findOne({
      where: { username: username.trim() },
      relations: { profile: { department: true } },
    });
    const ok = !!user && user.isActive && !!user.profile && verifyDjangoPassword(password, user.password);
    if (!ok) {
      const count = (state?.count ?? 0) + 1;
      this.failures.set(key, {
        count,
        lockedUntil: count >= LOCK_LIMIT ? Date.now() + LOCK_MINUTES * 60_000 : 0,
      });
      throw new UnauthorizedException('اسم المستخدم أو كلمة المرور غير صحيحة.');
    }

    this.failures.delete(key);
    await this.users.update(user.id, { lastLogin: new Date() });
    const token = await this.jwt.signAsync({ sub: user.id });
    return { token, user: this.toAuthUser(user) };
  }

  async verifyToken(token: string): Promise<AuthUser | null> {
    try {
      const payload = await this.jwt.verifyAsync<{ sub: number }>(token);
      const user = await this.users.findOne({ where: { id: payload.sub }, relations: { profile: { department: true } } });
      if (!user || !user.isActive || !user.profile) return null;
      return this.toAuthUser(user);
    } catch {
      return null;
    }
  }

  toAuthUser(user: UserEntity): AuthUser {
    const profile = user.profile;
    const role = profile?.role ?? '';
    return {
      id: user.id,
      username: user.username,
      fullName: `${user.firstName} ${user.lastName}`.trim() || user.username,
      role,
      roleLabel: ROLE_LABELS[role] ?? role,
      jobTitle: profile?.jobTitle ?? '',
      departmentId: profile?.departmentId ?? null,
      departmentName: profile?.department?.name ?? null,
      mustChangePassword: false,
      ...roleFlags(role),
    };
  }
}
