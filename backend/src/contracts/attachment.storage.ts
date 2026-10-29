import { BadRequestException, Injectable } from '@nestjs/common';
import { randomBytes } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';

/** امتدادات مسموحة للمرفقات. */
export const ALLOWED_EXTENSIONS = ['.pdf', '.doc', '.docx', '.jpg', '.jpeg', '.png'];
export const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

/**
 * تخزين المرفقات في مجلد media الخاص بـ Django بنفس التخطيط: contracts/YYYY/MM/<name>.
 * بهذه الطريقة تبقى المرفقات القديمة والجديدة قابلة للقراءة من النظامين.
 */
@Injectable()
export class AttachmentStorage {
  private readonly root = path.resolve(process.env.MEDIA_ROOT || path.join(process.cwd(), '..', 'media'));

  async save(file: Express.Multer.File): Promise<{ relativePath: string; originalName: string }> {
    const ext = path.extname(file.originalname).toLowerCase();
    if (!ALLOWED_EXTENSIONS.includes(ext)) {
      throw new BadRequestException(`نوع الملف غير مسموح. المسموح: ${ALLOWED_EXTENSIONS.join(' ')}`);
    }
    if (file.size > MAX_ATTACHMENT_BYTES) throw new BadRequestException('حجم الملف يتجاوز 10 ميغابايت.');

    const now = new Date();
    const yyyy = String(now.getUTCFullYear());
    const mm = String(now.getUTCMonth() + 1).padStart(2, '0');
    const base = path.basename(file.originalname, path.extname(file.originalname)).replace(/[^\p{L}\p{N}_-]+/gu, '_').slice(0, 80) || 'file';
    const name = `${base}_${randomBytes(4).toString('hex')}${ext}`;

    const relativePath = ['contracts', yyyy, mm, name].join('/');
    const absolute = this.absolutePath(relativePath);
    fs.mkdirSync(path.dirname(absolute), { recursive: true });
    await fs.promises.writeFile(absolute, file.buffer);
    return { relativePath, originalName: file.originalname.slice(0, 255) };
  }

  /** يمنع الخروج من مجلد الميديا عند القراءة أو الحذف. */
  absolutePath(relativePath: string): string {
    const absolute = path.resolve(this.root, relativePath);
    if (!absolute.startsWith(this.root + path.sep)) throw new BadRequestException('مسار ملف غير صالح.');
    return absolute;
  }

  remove(relativePath: string) {
    try {
      fs.unlinkSync(this.absolutePath(relativePath));
    } catch {
      /* الملف غير موجود — لا شيء للتنظيف */
    }
  }
}
