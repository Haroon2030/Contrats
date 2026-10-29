import {
  CanActivate,
  ExecutionContext,
  Injectable,
  SetMetadata,
  UnauthorizedException,
  createParamDecorator,
} from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { AuthService, AuthUser } from '../auth/auth.service';

export const COOKIE_NAME = 'contracts_token';
const PUBLIC_KEY = 'isPublic';

/** يُعلّم endpoint كعام (بدون تسجيل دخول). */
export const Public = () => SetMetadata(PUBLIC_KEY, true);

/** المستخدم الحالي كما حمّله الحارس. */
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

/** حارس عام: يتحقق من الرمز ويحمّل المستخدم بصلاحياته لكل الطلبات. */
@Injectable()
export class AuthGuard implements CanActivate {
  constructor(
    private readonly reflector: Reflector,
    private readonly auth: AuthService,
  ) {}

  async canActivate(ctx: ExecutionContext): Promise<boolean> {
    const isPublic = this.reflector.getAllAndOverride<boolean>(PUBLIC_KEY, [ctx.getHandler(), ctx.getClass()]);
    if (isPublic) return true;

    const req = ctx.switchToHttp().getRequest();
    const token = readToken(req);
    const user = token ? await this.auth.verifyToken(token) : null;
    if (!user) throw new UnauthorizedException('يجب تسجيل الدخول أولاً.');
    req.user = user;
    return true;
  }
}
