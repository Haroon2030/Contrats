import { Module } from '@nestjs/common';
import { TypeOrmModule } from '@nestjs/typeorm';
import { ENTITIES } from './entities';

/**
 * اختيار قاعدة البيانات من البيئة:
 * - DB_DRIVER=mysql  → نفس قاعدة الإنتاج الحالية (متغيرات MYSQL_* كما في Django).
 * - DB_DRIVER=sqlite → ملف db.sqlite3 الموجود للتطوير المحلي.
 * synchronize مُعطّل دائماً: لا يلمس الجداول التي يديرها Django.
 */
@Module({
  imports: [
    TypeOrmModule.forRootAsync({
      useFactory: () => {
        const driver = (process.env.DB_DRIVER || 'sqlite').toLowerCase();
        const common = { entities: ENTITIES, synchronize: false, logging: false };

        if (driver === 'mysql') {
          return {
            ...common,
            type: 'mysql',
            host: process.env.MYSQL_HOST || '127.0.0.1',
            port: Number(process.env.MYSQL_PORT || 3306),
            database: process.env.MYSQL_DATABASE || '',
            username: process.env.MYSQL_USER || '',
            password: process.env.MYSQL_PASSWORD || '',
            charset: 'utf8mb4',
            // Django يكتب datetime بتوقيت UTC دون منطقة (USE_TZ=True)؛ نطابقه تماماً.
            timezone: 'Z',
            extra: { connectionLimit: 10 },
          };
        }

        return {
          ...common,
          type: 'better-sqlite3',
          database: process.env.DB_SQLITE_PATH || '../db.sqlite3',
          readonly: false,
          fileMustExist: true,
        };
      },
    }),
  ],
})
export class DatabaseModule {}
