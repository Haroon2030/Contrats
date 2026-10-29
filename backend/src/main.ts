import 'reflect-metadata';
import { BadRequestException, ValidationPipe } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import { NestExpressApplication } from '@nestjs/platform-express';
import { AppModule } from './app.module';

async function bootstrap() {
  const app = await NestFactory.create<NestExpressApplication>(AppModule, { bufferLogs: false });

  app.setGlobalPrefix('api');
  app.useGlobalPipes(
    new ValidationPipe({
      whitelist: true,
      transform: true,
      // أخطاء الحقول بصيغة { field: "رسالة عربية" } لتظهر بجانب كل حقل في الواجهة.
      exceptionFactory: (errors) => {
        const fields: Record<string, string> = {};
        const walk = (list: any[], prefix = '') => {
          list.forEach((e) => {
            const key = prefix ? `${prefix}.${e.property}` : e.property;
            if (e.constraints) fields[key] = Object.values(e.constraints)[0] as string;
            if (e.children?.length) walk(e.children, key);
          });
        };
        walk(errors);
        return new BadRequestException({ message: 'تحقق من الحقول المعلّمة.', errors: fields });
      },
    }),
  );

  app.enableCors({
    origin: (process.env.CORS_ORIGIN || 'http://localhost:3000').split(',').map((s) => s.trim()),
    credentials: true,
  });
  app.set('trust proxy', 1);

  const port = Number(process.env.PORT || 4000);
  await app.listen(port);
  console.log(`Contracts API running on http://localhost:${port}/api`);
}

bootstrap();
