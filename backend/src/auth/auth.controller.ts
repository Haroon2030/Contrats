import { Body, Controller, Get, Post, Res } from '@nestjs/common';
import { Response } from 'express';
import { IsString, MaxLength, MinLength } from 'class-validator';
import { AllowPasswordChange, COOKIE_NAME, CurrentUser, Public, cookieOptions } from '../common/auth.guard';
import { AuthService, AuthUser } from './auth.service';

class LoginDto {
  @IsString() @MinLength(1) @MaxLength(150) username: string;
  @IsString() @MinLength(1) @MaxLength(256) password: string;
}

class ChangePasswordDto {
  @IsString() @MaxLength(256) current: string;
  @IsString() @MaxLength(256) next: string;
}

@Controller('auth')
export class AuthController {
  constructor(private readonly auth: AuthService) {}

  @Public()
  @Post('login')
  async login(@Body() body: LoginDto, @Res({ passthrough: true }) res: Response) {
    const { token, user } = await this.auth.login(body.username, body.password);
    res.cookie(COOKIE_NAME, token, cookieOptions());
    return { user };
  }

  @AllowPasswordChange()
  @Post('logout')
  logout(@Res({ passthrough: true }) res: Response) {
    res.clearCookie(COOKIE_NAME, { path: '/' });
    return { ok: true };
  }

  @AllowPasswordChange()
  @Get('me')
  me(@CurrentUser() user: AuthUser) {
    return { user };
  }

  @AllowPasswordChange()
  @Post('change-password')
  async changePassword(@CurrentUser() user: AuthUser, @Body() body: ChangePasswordDto) {
    return { user: await this.auth.changePassword(user.id, body.current, body.next) };
  }
}
