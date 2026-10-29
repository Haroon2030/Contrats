import { Body, Controller, Get, Post, Res } from '@nestjs/common';
import { Response } from 'express';
import { IsString, MaxLength, MinLength } from 'class-validator';
import { COOKIE_NAME, CurrentUser, Public } from '../common/auth.guard';
import { AuthService, AuthUser } from './auth.service';

class LoginDto {
  @IsString() @MinLength(1) @MaxLength(150) username: string;
  @IsString() @MinLength(1) @MaxLength(256) password: string;
}

const MAX_AGE_SECONDS = Number(process.env.JWT_EXPIRES_SECONDS || 28800);

@Controller('auth')
export class AuthController {
  constructor(private readonly auth: AuthService) {}

  @Public()
  @Post('login')
  async login(@Body() body: LoginDto, @Res({ passthrough: true }) res: Response) {
    const { token, user } = await this.auth.login(body.username, body.password);
    res.cookie(COOKIE_NAME, token, {
      httpOnly: true,
      sameSite: 'lax',
      secure: process.env.COOKIE_SECURE === '1',
      maxAge: MAX_AGE_SECONDS * 1000,
      path: '/',
    });
    return { user };
  }

  @Post('logout')
  logout(@Res({ passthrough: true }) res: Response) {
    res.clearCookie(COOKIE_NAME, { path: '/' });
    return { ok: true };
  }

  @Get('me')
  me(@CurrentUser() user: AuthUser) {
    return { user };
  }
}
