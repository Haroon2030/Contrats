import {
  CanActivate,
  ExecutionContext,
  ForbiddenException,
  Injectable,
  SetMetadata,
  UnauthorizedException,
  createParamDecorator,
} from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { AuthService, AuthUser, SESSION_MINUTES } from '../auth/auth.service';

export const COOKIE_NAME = 'contracts_token';
const PUBLIC_KEY = 'isPublic';
const ALLOW_PW_CHANGE_KEY = 'allowPasswordChange';

/** endpoint عام (بدون تسجيل دخول). */
export const Public = () => SetMetadata(PUBLIC_KEY, true);
/** endpoint متاح حتى لو كانت كلمة المرور مؤقتة (تغيير كلمة المرور والخروج). */
export const AllowPasswordChange = () => SetMetadata(ALLOW_PW_CHANGE_KEY, true);

export const CurrentUser = createParamDecorator((_data: unknown, ctx: ExecutionContext): AuthUser => {
  return ctx.switchToHttp().getRequest().user;
});

export function readToken(req: { headers: Record<string, string | string[] | undefined> }): string | null {
  const header = req.headers['authorization'];
  if (typeof header === 'string' && header.startsWith('Bearer ')) return header.slice(7);
  const cookie = typeof req.headers['cookie'] === 'string' ? (req.headers['cookie'] as string) : '';
  const match = cookie.match(new RegExp(`(?:^|;\\s*)${COOKIE_NAME}=([^;]+)`));
  return match ? decodeURIComponent(match[1]) : null;
}

export function cookieOptions() {
  return {
    httpOnly: true,
    sameSite: 'lax' as const,
    secure: process.env.COOKIE_SECURE === '1',
    maxAge: SESSION_MINUTES * 60 * 1000,
    path: '/',
  };
}

/**
 * حارس عام: يتحقق من الرمز ويحمّل المستخدم وصلاحياته، ويجدّد الجلسة (30 دقيقة خمول)،
 * ويمنع كل شيء عدا تغيير كلمة المرور حين تكون كلمة المرور مؤقتة.
 */
@Injectable()
export class AuthGuard implements CanActivate {
  constructor(
    private readonly reflector: Reflector,
    private readonly auth: AuthService,
  ) {}

  async canActivate(ctx: ExecutionContext): Promise<boolean> {
    const isPublic = this.reflector.getAllAndOverride<boolean>(PUBLIC_KEY, [ctx.getHandler(), ctx.getClass()]);
    if (isPublic) return true;

    const http = ctx.switchToHttp();
    const req = http.getRequest();
    const token = readToken(req);
    const verified = token ? await this.auth.verifyToken(token) : null;
    if (!verified) throw new UnauthorizedException('انتهت الجلسة. سجّل الدخول من جديد.');

    const allowPw = this.reflector.getAllAndOverride<boolean>(ALLOW_PW_CHANGE_KEY, [ctx.getHandler(), ctx.getClass()]);
    if (verified.user.mustChangePassword && !allowPw) {
      throw new ForbiddenException({ message: 'يجب تغيير كلمة المرور المؤقتة أولاً.', code: 'PASSWORD_CHANGE_REQUIRED' });
    }

    // جلسة منزلقة: تجديد الكوكي كلما مضى أكثر من نصف مدة الجلسة.
    if (verified.remainingSeconds < (SESSION_MINUTES * 60) / 2) {
      const fresh = await this.auth.sign(verified.user.id);
      http.getResponse().cookie(COOKIE_NAME, fresh, cookieOptions());
    }
    req.user = verified.user;
    return true;
  }
}
