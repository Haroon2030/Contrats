'use client';

import Link from 'next/link';
import { useEffect, useState, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { STATUS_TONE } from '@/models/labels';
import type { ContractStatus } from '@/models/types';

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

export function StatusBadge({ status, label }: { status: ContractStatus; label: string }) {
  return <span className={`badge ${STATUS_TONE[status] ?? 'neutral'}`}>{label}</span>;
}

export function Stat({ label, value, accent }: { label: string; value: ReactNode; accent?: boolean }) {
  return (
    <div className={`stat${accent ? ' accent' : ''}`}>
      <div className="value">{value}</div>
      <div className="label">{label}</div>
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
      <div>
        <h1>{title}</h1>
        {subtitle && <div className="muted">{subtitle}</div>}
      </div>
      <span className="spacer" />
      {actions}
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
