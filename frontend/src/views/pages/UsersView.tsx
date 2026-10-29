'use client';

import { useState, type FormEvent } from 'react';
import { useUsers } from '@/controllers/useAdmin';
import { useToast } from '@/controllers/toast';
import { ROLE_OPTIONS } from '@/models/labels';
import type { Department, UserRow } from '@/models/types';
import { useEffect } from 'react';
import { api } from '@/models/api';
import { Button, Empty, ErrorBox, Field, Loading, PageHead, Panel, fmtDateTime } from '../ui';

/** إدارة المستخدمين (مدير النظام): إنشاء وتعديل وتعطيل، وكلمة مرور مؤقتة عند الإنشاء. */
export function UsersView() {
  const { users, loading, error, save } = useUsers();
  const toast = useToast();
  const [editing, setEditing] = useState<UserRow | 'new' | null>(null);

  return (
    <>
      <PageHead
        title="المستخدمون"
        subtitle="لا يوجد تسجيل ذاتي. الحسابات تُنشأ وتُعدّل من هنا."
        actions={<Button variant="primary" onClick={() => setEditing('new')}>«أنشئ حساباً»</Button>}
      />

      {error && <ErrorBox>{error}</ErrorBox>}
      {loading ? (
        <Loading />
      ) : users.length === 0 ? (
        <Empty>لا يوجد مستخدمون.</Empty>
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>الاسم</th>
                <th>اسم الدخول</th>
                <th>الدور</th>
                <th>القسم</th>
                <th>آخر دخول</th>
                <th>الحالة</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id}>
                  <td>{u.fullName}</td>
                  <td className="ltr">{u.username}</td>
                  <td>{u.roleLabel}</td>
                  <td>{u.departmentName ?? '—'}</td>
                  <td>{fmtDateTime(u.lastLogin)}</td>
                  <td>
                    <span className={`badge ${u.isActive ? 'neutral' : 'danger'}`}>{u.isActive ? 'نشط' : 'معطّل'}</span>
                  </td>
                  <td>
                    <Button small onClick={() => setEditing(u)}>«عدّل»</Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editing && (
        <UserModal
          user={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSave={async (body) => {
            const res = await save(editing === 'new' ? null : editing.id, body);
            if (res.ok) {
              toast.success(editing === 'new' ? 'أُنشئ الحساب.' : 'حُفظت بيانات المستخدم.');
              setEditing(null);
            }
            return res;
          }}
        />
      )}
    </>
  );
}

type SaveResult = { ok: true } | { ok: false; message: string; fields?: Record<string, string> };

function UserModal({
  user,
  onClose,
  onSave,
}: {
  user: UserRow | null;
  onClose: () => void;
  onSave: (body: Record<string, unknown>) => Promise<SaveResult>;
}) {
  const [departments, setDepartments] = useState<Department[]>([]);
  const [form, setForm] = useState({
    username: user?.username ?? '',
    firstName: user?.firstName ?? '',
    lastName: user?.lastName ?? '',
    password: '',
    role: user?.role ?? 'contracts_officer',
    departmentId: user?.departmentId ? String(user.departmentId) : '',
    jobTitle: user?.jobTitle ?? '',
    phone: user?.phone ?? '',
    isActive: user?.isActive ?? true,
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get<Department[]>('/users/departments').then(setDepartments).catch(() => setDepartments([]));
  }, []);

  const set = (key: keyof typeof form, value: string | boolean) => setForm((f) => ({ ...f, [key]: value }));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage(null);
    const res = await onSave({
      ...form,
      departmentId: form.departmentId ? Number(form.departmentId) : undefined,
      password: form.password || undefined,
    });
    setBusy(false);
    if (!res.ok) {
      setMessage(res.message);
      setErrors(res.fields ?? {});
    }
  };

  return (
    <div className="modal-backdrop" onClick={onClose} role="dialog" aria-modal="true" aria-label={user ? 'تعديل مستخدم' : 'حساب جديد'}>
      <form className="modal" style={{ maxWidth: 680 }} onClick={(e) => e.stopPropagation()} onSubmit={submit} noValidate>
        <h2>{user ? 'تعديل مستخدم' : 'حساب جديد'}</h2>
        {message && <ErrorBox>{message}</ErrorBox>}
        <div className="form-grid">
          <Field label="اسم الدخول" error={errors.username}>
            <input className="input ltr" value={form.username} onChange={(e) => set('username', e.target.value)} />
          </Field>
          <Field label="الاسم الأول" error={errors.firstName}>
            <input className="input" value={form.firstName} onChange={(e) => set('firstName', e.target.value)} />
          </Field>
          <Field label="اسم العائلة">
            <input className="input" value={form.lastName} onChange={(e) => set('lastName', e.target.value)} />
          </Field>
          <Field label={user ? 'كلمة مرور جديدة (اختياري)' : 'كلمة مرور مؤقتة'} error={errors.password} hint="6 أحرف على الأقل.">
            <input className="input ltr" type="password" autoComplete="new-password" value={form.password} onChange={(e) => set('password', e.target.value)} />
          </Field>
          <Field label="الدور" error={errors.role}>
            <select className="select" value={form.role} onChange={(e) => set('role', e.target.value)}>
              {ROLE_OPTIONS.map((r) => (
                <option key={r.value} value={r.value}>
                  {r.label}
                </option>
              ))}
            </select>
          </Field>
          <Field label="القسم" error={errors.departmentId} hint={form.role === 'purchasing_head' ? 'مسؤول الإدارة يجب ربطه بقسم.' : undefined}>
            <select className="select" value={form.departmentId} onChange={(e) => set('departmentId', e.target.value)}>
              <option value="">—</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="المسمى الوظيفي">
            <input className="input" value={form.jobTitle} onChange={(e) => set('jobTitle', e.target.value)} />
          </Field>
          <Field label="الجوال">
            <input className="input ltr" value={form.phone} onChange={(e) => set('phone', e.target.value)} />
          </Field>
          <Field label="الحالة">
            <label className="row" style={{ minHeight: 44 }}>
              <input type="checkbox" checked={form.isActive} onChange={(e) => set('isActive', e.target.checked)} />
              <span>حساب نشط</span>
            </label>
          </Field>
        </div>
        <div className="row" style={{ justifyContent: 'flex-end' }}>
          <Button onClick={onClose}>«تراجع»</Button>
          <Button variant="primary" type="submit" disabled={busy}>
            {busy ? '…' : user ? '«احفظ التعديلات»' : '«أنشئ الحساب»'}
          </Button>
        </div>
      </form>
    </div>
  );
}
