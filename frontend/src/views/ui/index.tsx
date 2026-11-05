'use client';

import Link from 'next/link';
import { useEffect, useState, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { STATUS_LABELS, STATUS_TONE } from '@/models/labels';
import type { ContractStatus } from '@/models/types';
import { Icon, type IconName } from './icons';

/** نص الزر: أفعال أمر بين «» كما في المواصفة. */
export function ActionLabel({ children }: { children: ReactNode }) {
  return <>«{children}»</>;
}

export function Button({
  children,
  variant,
  small,
  type = 'button',
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'danger' | 'ghost'; small?: boolean }) {
  const cls = ['btn', variant, small && 'small'].filter(Boolean).join(' ');
  return (
    <button type={type} className={cls} {...rest}>
      {children}
    </button>
  );
}

export function LinkButton({ href, children, variant }: { href: string; children: ReactNode; variant?: 'primary' }) {
  return (
    <Link href={href} className={`btn ${variant ?? ''}`}>
      {children}
    </Link>
  );
}

export function Field({
  label,
  error,
  hint,
  children,
  span2,
}: {
  label: string;
  error?: string;
  hint?: string;
  children: ReactNode;
  span2?: boolean;
}) {
  return (
    <div className={`field${error ? ' invalid' : ''}${span2 ? ' span-2' : ''}`}>
      <label>{label}</label>
      {children}
      {hint && !error && <span className="hint">{hint}</span>}
      {error && <span className="field-error">{error}</span>}
    </div>
  );
}

export function StatusBadge({ status, label }: { status: ContractStatus; label?: string }) {
  return <span className={`badge ${STATUS_TONE[status] ?? 'neutral'}`}>{label ?? STATUS_LABELS[status] ?? status}</span>;
}

/** بطاقة إحصاء: عنوان وأيقونة في الأعلى، ثم القيمة الكبيرة، ثم ملاحظة اختيارية. */
export function Stat({
  label,
  value,
  accent,
  tone,
  icon,
  hint,
}: {
  label: string;
  value: ReactNode;
  accent?: boolean;
  tone?: 'danger';
  icon?: IconName;
  hint?: ReactNode;
}) {
  return (
    <div className={`stat${accent ? ' accent' : ''}${tone === 'danger' ? ' danger' : ''}`}>
      <div className="stat-top">
        <span className="stat-label">{label}</span>
        {icon && (
          <span className="icon-chip">
            <Icon name={icon} />
          </span>
        )}
      </div>
      <div className="value">{value}</div>
      {hint && <div className="hint">{hint}</div>}
    </div>
  );
}

export function Panel({ title, children, actions }: { title?: string; children: ReactNode; actions?: ReactNode }) {
  return (
    <section className="panel">
      {(title || actions) && (
        <div className="row" style={{ marginBottom: 14 }}>
          {title && <h2>{title}</h2>}
          <span className="spacer" />
          {actions}
        </div>
      )}
      {children}
    </section>
  );
}

export function Empty({ children }: { children: ReactNode }) {
  return <div className="empty">{children}</div>;
}

export function Loading() {
  return <div className="empty">جارٍ التحميل…</div>;
}

export function ErrorBox({ children }: { children: ReactNode }) {
  return <div className="alert-banner">{children}</div>;
}

export function PageHead({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
  return (
    <div className="page-head">
      <div className="page-title">
        <h1>{title}</h1>
        {subtitle && <div className="muted">{subtitle}</div>}
      </div>
      {actions && <div className="page-actions">{actions}</div>}
    </div>
  );
}

/** نافذة تطلب سبباً (لإجراءات الرفض والإلغاء والتصعيد). */
export function ReasonModal({
  title,
  label,
  required,
  confirmLabel,
  danger,
  busy,
  error,
  onCancel,
  onConfirm,
}: {
  title: string;
  label?: string;
  required?: boolean;
  confirmLabel: string;
  danger?: boolean;
  busy?: boolean;
  error?: string | null;
  onCancel: () => void;
  onConfirm: (reason: string) => void;
}) {
  const [reason, setReason] = useState('');
  useModalKeys(onCancel);
  const missing = required && !reason.trim();
  return (
    <div className="modal-backdrop" onClick={onCancel} role="dialog" aria-modal="true" aria-label={title}>
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>{title}</h2>
        {label ? (
          <Field label={label} error={error && missing ? 'هذا الحقل مطلوب.' : undefined}>
            <textarea className="textarea" autoFocus value={reason} onChange={(e) => setReason(e.target.value)} />
          </Field>
        ) : null}
        {error && !missing && <ErrorBox>{error}</ErrorBox>}
        <div className="row" style={{ justifyContent: 'flex-end' }}>
          <Button onClick={onCancel}>«تراجع»</Button>
          <Button
            variant={danger ? 'danger' : 'primary'}
            disabled={busy || missing}
            onClick={() => onConfirm(reason.trim())}
          >
            {busy ? '…' : `«${confirmLabel}»`}
          </Button>
        </div>
      </div>
    </div>
  );
}

/** يمنع تمرير الصفحة خلف النافذة، ويغلق بمفتاح Escape. */
export function useModalKeys(onClose: () => void) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [onClose]);
}

export function fmtMoney(value: number | null | undefined) {
  if (value == null) return '—';
  return new Intl.NumberFormat('ar-SA-u-nu-latn', { maximumFractionDigits: 2 }).format(value) + ' ر.س';
}

export function fmtDate(value: string | null | undefined) {
  if (!value) return '—';
  const d = new Date(value.length <= 10 ? `${value}T00:00:00` : value);
  if (Number.isNaN(d.getTime())) return value;
  return new Intl.DateTimeFormat('ar-SA-u-nu-latn', { dateStyle: 'medium', timeZone: 'Asia/Riyadh' }).format(d);
}

export function fmtDateTime(value: string | null | undefined) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return new Intl.DateTimeFormat('ar-SA-u-nu-latn', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Riyadh' }).format(d);
}

const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
const pad2 = (n: number) => String(n).padStart(2, '0');

/**
 * حقل تاريخ بثلاث قوائم (يوم / شهر / سنة) يعطي قيمة ISO (yyyy-mm-dd) أو نصاً فارغاً.
 * بديل عن input[type=date] الذي يعرض نصوصه مقلوبة في متصفحات واجهتها عربية.
 */
export function DateInput({
  value,
  onChange,
  'aria-label': ariaLabel,
  yearsBack = 5,
  yearsAhead = 15,
}: {
  value: string | null | undefined;
  onChange: (value: string) => void;
  'aria-label'?: string;
  yearsBack?: number;
  yearsAhead?: number;
}) {
  const parse = (v: string | null | undefined) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(v ?? '');
    return m ? { y: Number(m[1]), m: Number(m[2]), d: Number(m[3]) } : { y: 0, m: 0, d: 0 };
  };
  const [parts, setParts] = useState(() => parse(value));

  // يتبع القيمة القادمة من الأعلى (تحميل عقد، إعادة ضبط) دون مسح اختيار جزئي
  useEffect(() => {
    const next = parse(value);
    if (value) setParts(next);
    else setParts((cur) => (cur.y && cur.m && cur.d ? next : cur));
  }, [value]);

  const thisYear = new Date().getFullYear();
  const years: number[] = [];
  for (let y = thisYear - yearsBack; y <= thisYear + yearsAhead; y++) years.push(y);
  if (parts.y && !years.includes(parts.y)) years.push(parts.y);
  years.sort((a, b) => a - b);

  const daysIn = parts.y && parts.m ? new Date(parts.y, parts.m, 0).getDate() : 31;

  const update = (patch: Partial<typeof parts>) => {
    const next = { ...parts, ...patch };
    const max = next.y && next.m ? new Date(next.y, next.m, 0).getDate() : 31;
    if (next.d > max) next.d = max;
    setParts(next);
    onChange(next.y && next.m && next.d ? `${next.y}-${pad2(next.m)}-${pad2(next.d)}` : '');
  };

  return (
    <div className="date-input" role="group" aria-label={ariaLabel ?? 'التاريخ'}>
      <select className="select" aria-label="اليوم" value={parts.d || ''} onChange={(e) => update({ d: Number(e.target.value) })}>
        <option value="">يوم</option>
        {Array.from({ length: daysIn }, (_, i) => i + 1).map((d) => (
          <option key={d} value={d}>{d}</option>
        ))}
      </select>
      <select className="select" aria-label="الشهر" value={parts.m || ''} onChange={(e) => update({ m: Number(e.target.value) })}>
        <option value="">شهر</option>
        {MONTHS.map((name, i) => (
          <option key={name} value={i + 1}>{`${i + 1} - ${name}`}</option>
        ))}
      </select>
      <select className="select" aria-label="السنة" value={parts.y || ''} onChange={(e) => update({ y: Number(e.target.value) })}>
        <option value="">سنة</option>
        {years.map((y) => (
          <option key={y} value={y}>{y}</option>
        ))}
      </select>
    </div>
  );
}
