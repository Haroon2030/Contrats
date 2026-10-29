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

type IconName = 'dashboard' | 'contracts' | 'new' | 'goals' | 'operations' | 'templates' | 'audit' | 'users' | 'reference';

interface NavItem {
  href: string;
  label: string;
  icon: IconName;
  visible: (u: SessionUser) => boolean;
}

const NAV: NavItem[] = [
  { href: '/dashboard', label: 'لوحة التحكم', icon: 'dashboard', visible: () => true },
  { href: '/contracts', label: 'سجل العقود', icon: 'contracts', visible: () => true },
  { href: '/contracts/new', label: 'إنشاء عقد', icon: 'new', visible: (u) => u.isContractsStaff },
  { href: '/goals', label: 'الأهداف والخصومات', icon: 'goals', visible: () => true },
  { href: '/operations', label: 'التشغيل', icon: 'operations', visible: () => true },
  { href: '/templates', label: 'القوالب والشروط', icon: 'templates', visible: (u) => u.canManageUsers || u.role === 'contracts_manager' },
  { href: '/audit', label: 'سجل التدقيق', icon: 'audit', visible: (u) => u.canManageUsers || u.role === 'contracts_manager' },
  { href: '/users', label: 'المستخدمون', icon: 'users', visible: (u) => u.canManageUsers },
  { href: '/reference', label: 'القوائم المرجعية', icon: 'reference', visible: (u) => u.canManageUsers },
];

/** أيقونات خطية بسيطة (24×24)، تأخذ لون النص من القائمة. */
const ICON_PATHS: Record<IconName, ReactNode> = {
  dashboard: (
    <>
      <rect x="3" y="3" width="7" height="9" rx="1.5" />
      <rect x="14" y="3" width="7" height="5" rx="1.5" />
      <rect x="14" y="12" width="7" height="9" rx="1.5" />
      <rect x="3" y="16" width="7" height="5" rx="1.5" />
    </>
  ),
  contracts: (
    <>
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <path d="M14 2v6h6M8 13h8M8 17h8M8 9h2" />
    </>
  ),
  new: (
    <>
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <path d="M14 2v6h6M12 12v6M9 15h6" />
    </>
  ),
  goals: (
    <>
      <circle cx="12" cy="12" r="9" />
      <circle cx="12" cy="12" r="5" />
      <circle cx="12" cy="12" r="1.2" />
    </>
  ),
  operations: (
    <>
      <path d="M3 9l1.5-5h15L21 9" />
      <path d="M4 9v11h16V9M10 20v-6h4v6" />
      <path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0" />
    </>
  ),
  templates: (
    <>
      <rect x="3" y="3" width="18" height="7" rx="1.5" />
      <rect x="3" y="14" width="9" height="7" rx="1.5" />
      <rect x="16" y="14" width="5" height="7" rx="1.5" />
    </>
  ),
  audit: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </>
  ),
  users: (
    <>
      <circle cx="9" cy="8" r="3.5" />
      <path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6" />
    </>
  ),
  reference: <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />,
};

function NavIcon({ name }: { name: IconName }) {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      {ICON_PATHS[name]}
    </svg>
  );
}

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
      <div className="scroll-edge" aria-hidden="true" />
      <aside className="sidebar">
        <div className="brand">
          <div className="brand-mark" aria-hidden="true">ر</div>
          <div>
            <strong>نظام إدارة العقود</strong>
            <span>أسواق الرشيد</span>
          </div>
        </div>
        <nav aria-label="التنقل الرئيسي">
          {NAV.filter((n) => n.visible(user)).map((n) => (
            <Link
              key={n.href}
              href={n.href}
              className={isActive(pathname, n.href) ? 'active' : ''}
              aria-current={isActive(pathname, n.href) ? 'page' : undefined}
            >
              <NavIcon name={n.icon} />
              <span>{n.label}</span>
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
