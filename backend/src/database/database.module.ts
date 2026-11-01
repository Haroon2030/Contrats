import { Global, Module } from '@nestjs/common';
import { TypeOrmModule } from '@nestjs/typeorm';
import { ENTITIES } from './entities';
import { BootstrapService } from './bootstrap.service';

/**
 * قاعدة البيانات:
 * - DB_DRIVER=mysql  → قاعدة الإنتاج (MYSQL_HOST/PORT/DATABASE/USER/PASSWORD).
 * - DB_DRIVER=sqlite → ملف للتطوير المحلي (DB_SQLITE_PATH).
 * DB_SYNC=1 ينشئ الجداول الناقصة ويطابقها مع الكيانات عند التشغيل.
 *   الافتراضي: مفعّل مع sqlite، ومعطّل مع mysql (فعّله في أول نشر ثم أوقفه).
 */
@Global()
@Module({
  imports: [
    TypeOrmModule.forRootAsync({
      useFactory: () => {
        const driver = (process.env.DB_DRIVER || 'sqlite').toLowerCase();
        const sync = (process.env.DB_SYNC ?? (driver === 'mysql' ? '0' : '1')) === '1';
        const common = { entities: ENTITIES, synchronize: sync, logging: false };

        if (driver === 'mysql') {
          return {
            ...common,
            type: 'mysql' as const,
            host: process.env.MYSQL_HOST || '127.0.0.1',
            port: Number(process.env.MYSQL_PORT || 3306),
            database: process.env.MYSQL_DATABASE || '',
            username: process.env.MYSQL_USER || '',
            password: process.env.MYSQL_PASSWORD || '',
            charset: 'utf8mb4',
            timezone: 'Z', // نخزّن التواريخ والأوقات بتوقيت UTC
            extra: { connectionLimit: 10 },
          };
        }

        return {
          ...common,
          type: 'better-sqlite3' as const,
          database: process.env.DB_SQLITE_PATH || './data/dev.sqlite3',
        };
      },
    }),
    TypeOrmModule.forFeature(ENTITIES),
  ],
  providers: [BootstrapService],
  exports: [TypeOrmModule],
})
export class DatabaseModule {}
