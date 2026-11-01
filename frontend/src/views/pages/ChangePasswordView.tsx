'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useState, type FormEvent } from 'react';
import { useSession } from '@/controllers/session';
import { api, ApiError } from '@/models/api';
import { Button, ErrorBox, Field } from '../ui';

/** تغيير كلمة المرور: إلزامي بعد المؤقتة، ويحجب بقية النظام حتى يتم. */
export function ChangePasswordView() {
  const { user, loading, refresh, logout } = useSession();
  const router = useRouter();
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [again, setAgain] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!loading && !user) router.replace('/login');
  }, [loading, user, router]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (next !== again) return setError('كلمتا المرور غير متطابقتين.');
    setBusy(true);
    setError(null);
    try {
      await api.post('/auth/change-password', { current, next });
      await refresh();
      router.replace('/dashboard');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'تعذّر تغيير كلمة المرور.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="login-wrap">
      <form className="login-card" onSubmit={submit} noValidate>
        <div>
          <h1>تغيير كلمة المرور</h1>
          <div className="muted">{user?.mustChangePassword ? 'كلمة مرورك مؤقتة؛ اختر كلمة جديدة قبل المتابعة.' : 'اختر كلمة مرور جديدة.'}</div>
        </div>
        {error && <ErrorBox>{error}</ErrorBox>}
        <Field label="كلمة المرور الحالية">
          <input className="input ltr" type="password" value={current} onChange={(e) => setCurrent(e.target.value)} autoFocus />
        </Field>
        <Field label="كلمة المرور الجديدة" hint="8 أحرف على الأقل، وتضم حروفاً وأرقاماً.">
          <input className="input ltr" type="password" value={next} onChange={(e) => setNext(e.target.value)} />
        </Field>
        <Field label="أعد كتابتها">
          <input className="input ltr" type="password" value={again} onChange={(e) => setAgain(e.target.value)} />
        </Field>
        <Button variant="primary" type="submit" disabled={busy}>
          {busy ? '…' : '«احفظ كلمة المرور»'}
        </Button>
        <Button
          onClick={async () => {
            await logout();
            router.replace('/login');
          }}
        >
          «اخرج»
        </Button>
      </form>
    </div>
  );
}
