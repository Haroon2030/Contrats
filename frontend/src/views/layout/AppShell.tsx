'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useSession } from '@/controllers/session';
import { useToast } from '@/controllers/toast';
import { useDashboard } from '@/controllers/useDashboard';
import { ALERT_LABELS } from '@/models/labels';
import type { SessionUser } from '@/models/types';
import { Button } from '../ui';

interface NavItem {
  href: string;
  label: string;
  visible: (u: SessionUser) => boolean;
}

const NAV: NavItem[] = [
  { href: '/dashboard', label: 'لوحة التحكم', visible: () => true },
  { href: '/contracts', label: 'سجل العقود', visible: () => true },
  { href: '/contracts/new', label: 'إنشاء عقد', visible: (u) => u.isContractsStaff },
  { href: '/goals', label: 'الأهداف والخصومات', visible: () => true },
  { href: '/operations', label: 'التشغيل', visible: () => true },
  { href: '/templates', label: 'القوالب والشروط', visible: (u) => u.canManageUsers || u.role === 'contracts_manager' },
  { href: '/audit', label: 'سجل التدقيق', visible: (u) => u.canManageUsers || u.role === 'contracts_manager' },
  { href: '/users', label: 'المستخدمون', visible: (u) => u.canManageUsers },
  { href: '/reference', label: 'القوائم المرجعية', visible: (u) => u.canManageUsers },
];

/** الهيكل العام: تحقق من الجلسة، القائمة الجانبية حسب الدور، والإشعارات في الشريط العلوي. */
export function AppShell({ children }: { children: ReactNode }) {
  const { user, loading, logout } = useSession();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (!loading && !user) router.replace(`/login?next=${encodeURIComponent(pathname)}`);
  }, [loading, user, router, pathname]);

  if (loading || !user) {
    return <div className="empty" style={{ margin: 40 }}>جارٍ التحقق من الجلسة…</div>;
  }

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          <strong>نظام إدارة العقود</strong>
          <span>أسواق الرشيد — إدارة العقود والمشتريات</span>
        </div>
        <nav>
          {NAV.filter((n) => n.visible(user)).map((n) => (
            <Link key={n.href} href={n.href} className={isActive(pathname, n.href) ? 'active' : ''}>
              {n.label}
            </Link>
          ))}
        </nav>
      </aside>

      <div className="main">
        <header className="topbar">
          <Search />
          <span className="spacer" />
          <Notifications />
          <div style={{ textAlign: 'start', lineHeight: 1.3 }}>
            <strong>{user.fullName}</strong>
            <div className="muted" style={{ fontSize: 12 }}>
              {user.roleLabel}
              {user.departmentName ? ` — ${user.departmentName}` : ''}
            </div>
          </div>
          <Button
            small
            onClick={async () => {
              await logout();
              router.replace('/login');
            }}
          >
            «اخرج»
          </Button>
        </header>
        <main className="content">{children}</main>
        <Toasts />
      </div>
    </div>
  );
}

function isActive(pathname: string, href: string) {
  if (href === '/contracts') return pathname === '/contracts' || (pathname.startsWith('/contracts/') && pathname !== '/contracts/new');
  return pathname === href || pathname.startsWith(`${href}/`);
}

/** بحث عام: يذهب إلى سجل العقود مع الكلمة. */
function Search() {
  const router = useRouter();
  const [q, setQ] = useState('');
  return (
    <form
      className="search-box"
      role="search"
      onSubmit={(e) => {
        e.preventDefault();
        if (q.trim()) router.push(`/contracts?q=${encodeURIComponent(q.trim())}`);
      }}
    >
      <input
        className="input"
        placeholder="ابحث برقم العقد أو اسم المورد"
        value={q}
        onChange={(e) => setQ(e.target.value)}
        aria-label="بحث عام"
      />
    </form>
  );
}

/** الجرس: عدد التنبيهات المفتوحة، والقائمة تعرضها مع رابط للعقد. */
function Notifications() {
  const { alerts, criticalCount } = useDashboard();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, [open]);

  return (
    <div ref={ref} style={{ position: 'relative' }}>
      <button className="bell" aria-label={`الإشعارات: ${alerts.length}`} onClick={() => setOpen((o) => !o)}>
        🔔
        {alerts.length > 0 && <span className="count">{alerts.length}</span>}
      </button>
      {open && (
        <div className="dropdown" role="menu">
          {alerts.length === 0 && <div className="notice" style={{ border: 0 }}>لا توجد تنبيهات مفتوحة.</div>}
          {alerts.map((a) => (
            <Link key={a.id} href={`/contracts/${a.contractId}`} onClick={() => setOpen(false)}>
              <div className="row" style={{ justifyContent: 'space-between' }}>
                <strong className="ltr">{a.contractNumber}</strong>
                <span className={`badge ${a.severity === 'critical' ? 'danger' : a.severity === 'warning' ? 'accent' : 'neutral'}`}>
                  {a.severity === 'critical' ? 'حرج' : a.severity === 'warning' ? 'تحذير' : 'معلومة'}
                </span>
              </div>
              <div style={{ fontSize: 13 }}>{a.message}</div>
              <div className="muted" style={{ fontSize: 12 }}>{ALERT_LABELS[a.alertType] ?? a.alertType}</div>
            </Link>
          ))}
          {criticalCount > 0 && <div className="notice" style={{ border: 0, color: 'var(--danger)', fontWeight: 700 }}>{criticalCount} تنبيه حرج</div>}
        </div>
      )}
    </div>
  );
}

/** شريط رسائل النجاح والأخطاء. */
function Toasts() {
  const { toasts, dismiss } = useToast();
  if (!toasts.length) return null;
  return (
    <div className="toasts" role="status" aria-live="polite">
      {toasts.map((t) => (
        <div key={t.id} className={`toast ${t.kind}`}>
          <span>{t.text}</span>
          <span className="spacer" />
          <button className="btn ghost small" onClick={() => dismiss(t.id)} aria-label="إغلاق">
            ✕
          </button>
        </div>
      ))}
    </div>
  );
}
