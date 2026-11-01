'use client';

import { useState } from 'react';
import { useToast } from '@/controllers/toast';
import { errorText, useApi } from '@/controllers/useApi';
import { api, ApiError } from '@/models/api';
import type { Json, Options } from '@/models/types';
import { Button, ErrorBox, Field, Loading, PageHead, Panel, fmtDateTime, useModalKeys } from '../ui';

const ROLES: [string, string][] = [
  ['system_admin', 'مسؤول النظام'],
  ['contracts_manager', 'مدير قسم العقود'],
  ['contracts_officer', 'موظف عقود'],
  ['purchasing_manager', 'مدير المشتريات'],
  ['department_manager', 'مدير قسم'],
  ['department_official', 'مسؤول قسم'],
  ['operations', 'قسم التشغيل'],
];
const DEPT_ROLES = ['department_manager', 'department_official'];

/** المستخدمون: إنشاء بكلمة مرور مؤقتة، تعديل، إيقاف، وإعادة تعيين. لا حذف. */
export function UsersView() {
  const toast = useToast();
  const { data, loading, error, reload } = useApi<Json[]>('/users');
  const opts = useApi<Options>('/reference/options').data;
  const [edit, setEdit] = useState<Json | 'new' | null>(null);
  const [secret, setSecret] = useState<{ username: string; password: string } | null>(null);

  const reset = async (u: Json) => {
    if (!confirm(`إعادة تعيين كلمة مرور ${u.fullName}؟`)) return;
    try {
      const r = await api.post<{ temporaryPassword: string }>(`/users/${u.id}/reset-password`);
      setSecret({ username: u.username, password: r.temporaryPassword });
      reload();
    } catch (e) {
      toast.error(errorText(e));
    }
  };

  return (
    <>
      <PageHead title="المستخدمون" subtitle="إنشاء الحسابات وإيقافها وإعادة تعيين كلمات المرور" actions={<Button variant="primary" onClick={() => setEdit('new')}>«أضف مستخدماً»</Button>} />
      {error && <ErrorBox>{error}</ErrorBox>}
      {loading && !data && <Loading />}
      {secret && (
        <div className="notice">
          كلمة المرور المؤقتة للمستخدم <strong className="ltr">{secret.username}</strong>: <strong className="ltr" style={{ userSelect: 'all' }}>{secret.password}</strong> — تظهر مرة واحدة، وسيُطلب منه تغييرها عند أول دخول.
          <Button small onClick={() => setSecret(null)}>«أخفِها»</Button>
        </div>
      )}
      {data && (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>المستخدم</th><th>الاسم</th><th>الدور</th><th>القسم</th><th>الحالة</th><th>آخر دخول</th><th /></tr></thead>
            <tbody>
              {data.map((u) => (
                <tr key={u.id}>
                  <td className="ltr">{u.username}</td>
                  <td>{u.fullName}</td>
                  <td>{u.roleLabel}</td>
                  <td>{u.departmentName ?? '—'}</td>
                  <td>
                    <span className={`badge ${u.isActive ? 'ok' : 'muted'}`}>{u.isActive ? 'نشط' : 'موقوف'}</span>
                    {u.lockedUntil && new Date(u.lockedUntil) > new Date() && <span className="badge danger" style={{ marginInlineStart: 6 }}>مقفل</span>}
                    {u.mustChangePassword && <span className="badge accent" style={{ marginInlineStart: 6 }}>مؤقتة</span>}
                  </td>
                  <td>{u.lastLoginAt ? fmtDateTime(u.lastLoginAt) : '—'}</td>
                  <td className="row">
                    <Button small onClick={() => setEdit(u)}>«عدّل»</Button>
                    <Button small onClick={() => reset(u)}>«أعد التعيين»</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {edit && opts && (
        <UserModal
          user={edit === 'new' ? null : edit}
          departments={opts.departments}
          onClose={() => setEdit(null)}
          onSaved={(pw, username) => {
            setEdit(null);
            if (pw) setSecret({ username, password: pw });
            reload();
          }}
        />
      )}
    </>
  );
}

function UserModal({ user, departments, onClose, onSaved }: { user: Json | null; departments: Options['departments']; onClose: () => void; onSaved: (pw: string | null, username: string) => void }) {
  const [f, setF] = useState({
    username: user?.username ?? '',
    fullName: user?.fullName ?? '',
    email: user?.email ?? '',
    role: user?.role ?? 'department_official',
    departmentId: user?.departmentId ?? '',
    isActive: user?.isActive ?? true,
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  useModalKeys(onClose);

  const submit = async () => {
    setBusy(true);
    setErrors({});
    setMsg(null);
    const body = { ...f, departmentId: DEPT_ROLES.includes(f.role) ? Number(f.departmentId) || null : null };
    try {
      if (user) {
        await api.patch(`/users/${user.id}`, body);
        onSaved(null, f.username);
      } else {
        const r = await api.post<{ temporaryPassword: string }>('/users', body);
        onSaved(r.temporaryPassword, f.username);
      }
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.fields);
        setMsg(e.message);
      } else setMsg(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="modal-backdrop" onClick={onClose} role="dialog" aria-modal="true">
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>{user ? 'تعديل مستخدم' : 'مستخدم جديد'}</h2>
        {msg && <ErrorBox>{msg}</ErrorBox>}
        <Field label="اسم المستخدم" error={errors.username}><input className="input ltr" value={f.username} onChange={(e) => setF({ ...f, username: e.target.value })} /></Field>
        <Field label="الاسم الكامل" error={errors.fullName}><input className="input" value={f.fullName} onChange={(e) => setF({ ...f, fullName: e.target.value })} /></Field>
        <Field label="البريد الإلكتروني (للإشعارات)" error={errors.email}><input className="input ltr" value={f.email ?? ''} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        <Field label="الدور" error={errors.role}>
          <select className="select" value={f.role} onChange={(e) => setF({ ...f, role: e.target.value })}>
            {ROLES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </Field>
        {DEPT_ROLES.includes(f.role) && (
          <Field label="القسم *" error={errors.departmentId}>
            <select className="select" value={f.departmentId ?? ''} onChange={(e) => setF({ ...f, departmentId: e.target.value })}>
              <option value="">اختر القسم</option>
              {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </Field>
        )}
        {user && (
          <label className="chip" style={{ marginBottom: 12 }}>
            <input type="checkbox" checked={f.isActive} onChange={(e) => setF({ ...f, isActive: e.target.checked })} /> الحساب نشط
          </label>
        )}
        <div className="row" style={{ justifyContent: 'flex-end' }}>
          <Button onClick={onClose}>«تراجع»</Button>
          <Button variant="primary" disabled={busy} onClick={submit}>«احفظ»</Button>
        </div>
      </div>
    </div>
  );
}
