'use client';

import { useRouter, useSearchParams } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { useSession } from '@/controllers/session';
import { ApiError } from '@/models/api';
import { Button, ErrorBox, Field } from '../ui';

/** تسجيل الدخول: لا يوجد تسجيل ذاتي، والحساب يُنشأ من مدير النظام. */
export function LoginView() {
  const { login } = useSession();
  const router = useRouter();
  const params = useSearchParams();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!username.trim() || !password) {
      setError('أدخل اسم المستخدم وكلمة المرور.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await login(username.trim(), password);
      const next = params.get('next');
      router.replace(next && next.startsWith('/') ? next : '/dashboard');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'تعذّر تسجيل الدخول.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="login-wrap">
      <form className="login-card" onSubmit={submit} noValidate>
        <div>
          <h1>نظام إدارة العقود</h1>
          <div className="muted">قسم العقود — أسواق الرشيد</div>
        </div>
        {error && <ErrorBox>{error}</ErrorBox>}
        <Field label="اسم المستخدم">
          <input
            className="input ltr"
            autoComplete="username"
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            autoFocus
          />
        </Field>
        <Field label="كلمة المرور">
          <input
            className="input ltr"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </Field>
        <Button variant="primary" type="submit" disabled={busy}>
          {busy ? 'جارٍ الدخول…' : '«سجّل الدخول»'}
        </Button>
        <div className="muted" style={{ fontSize: 13 }}>
          لا يوجد تسجيل ذاتي. لطلب حساب أو إعادة تعيين كلمة المرور، راجع مدير النظام.
        </div>
      </form>
    </div>
  );
}
