'use client';

import { useEffect, useState } from 'react';
import { useToast } from '@/controllers/toast';
import { errorText, useApi } from '@/controllers/useApi';
import { api } from '@/models/api';
import { TYPE_OPTIONS } from '@/models/labels';
import type { Json } from '@/models/types';
import { Button, Empty, ErrorBox, Field, Loading, PageHead, Panel, fmtDateTime } from '../ui';

const CLAUSE_TYPES = TYPE_OPTIONS.filter((t) => t.value !== 'open_account');

/** القوالب: بنود الشروط بإصدارات (التعديل ينشئ إصداراً جديداً والعقود القائمة تحتفظ بإصدارها)، وقالب المستند، ومعاينة. */
export function TemplatesView() {
  const toast = useToast();
  const clauses = useApi<Json[]>('/templates/clauses');
  const doc = useApi<Json>('/templates/doc');
  const [editing, setEditing] = useState<Json | 'new' | null>(null);
  const [versions, setVersions] = useState<{ id: number; versions: Json[] } | null>(null);
  const [d, setD] = useState({ headerText: '', footerText: '', left: '', right: '', stamp: '' });

  useEffect(() => {
    if (doc.data) {
      const s = doc.data.signatureLayout ?? {};
      setD({ headerText: doc.data.headerText ?? '', footerText: doc.data.footerText ?? '', left: s.left ?? '', right: s.right ?? '', stamp: s.stamp ?? '' });
    }
  }, [doc.data]);

  const attempt = async (fn: () => Promise<unknown>, done: string, after?: () => void) => {
    try {
      await fn();
      toast.success(done);
      after?.();
    } catch (e) {
      toast.error(errorText(e));
    }
  };

  const showVersions = async (id: number) => setVersions({ id, versions: await api.get<Json[]>(`/templates/clauses/${id}/versions`) });
  const upload = (slot: 'logo' | 'background', file: File | undefined) => {
    if (!file) return;
    const form = new FormData();
    form.append('file', file);
    attempt(() => api.upload(`/templates/doc/${slot}`, form), 'رُفعت الصورة.', doc.reload);
  };

  if (clauses.error) return <ErrorBox>{clauses.error}</ErrorBox>;
  if (!clauses.data || !doc.data) return <Loading />;

  return (
    <>
      <PageHead
        title="القوالب والشروط"
        subtitle="تعديل البند ينشئ إصداراً جديداً ولا يغيّر العقود القائمة"
        actions={
          <>
            <a className="btn" href="/api/templates/preview" target="_blank" rel="noreferrer">«عاين عقداً نموذجياً»</a>
            <Button variant="primary" onClick={() => setEditing('new')}>«أضف بنداً»</Button>
          </>
        }
      />

      <Panel title="بنود الشروط والأحكام">
        {clauses.data.length === 0 ? <Empty>لا بنود بعد.</Empty> : (
          <div className="table-wrap">
            <table className="table">
              <thead><tr><th>الترتيب</th><th>العنوان</th><th>ينطبق على</th><th>الإصدار</th><th>آخر تحديث</th><th /></tr></thead>
              <tbody>
                {clauses.data.map((c) => (
                  <tr key={c.id}>
                    <td>{c.sort}</td><td>{c.title}</td><td>{c.appliesToLabels.join('، ')}</td><td>{c.version}</td><td>{fmtDateTime(c.updatedAt)}</td>
                    <td className="row">
                      <Button small onClick={() => setEditing(c)}>«عدّل»</Button>
                      <Button small onClick={() => showVersions(c.id)}>«الإصدارات»</Button>
                      <Button small onClick={() => attempt(() => api.patch(`/templates/clauses/${c.id}`, { status: c.status === 'active' ? 'inactive' : 'active' }), 'تم.', clauses.reload)}>{c.status === 'active' ? '«أوقف»' : '«فعّل»'}</Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      {versions && (
        <Panel title="إصدارات البند" actions={<Button small onClick={() => setVersions(null)}>«أغلق»</Button>}>
          {versions.versions.map((v) => (
            <div key={v.id} style={{ marginBottom: 10 }}>
              <strong>الإصدار {v.version}</strong> <span className="muted">{fmtDateTime(v.createdAt)}</span>
              <p style={{ whiteSpace: 'pre-wrap', margin: '4px 0' }}>{v.body}</p>
            </div>
          ))}
        </Panel>
      )}

      <Panel title={`قالب المستند (الإصدار ${doc.data.version})`}>
        <div className="form-grid">
          <Field label="نص الترويسة"><input className="input" value={d.headerText} onChange={(e) => setD({ ...d, headerText: e.target.value })} /></Field>
          <Field label="نص التذييل"><input className="input" value={d.footerText} onChange={(e) => setD({ ...d, footerText: e.target.value })} /></Field>
          <Field label="توقيع اليسار"><input className="input" value={d.left} onChange={(e) => setD({ ...d, left: e.target.value })} /></Field>
          <Field label="توقيع اليمين"><input className="input" value={d.right} onChange={(e) => setD({ ...d, right: e.target.value })} /></Field>
          <Field label="الختم"><input className="input" value={d.stamp} onChange={(e) => setD({ ...d, stamp: e.target.value })} /></Field>
          <Field label={`الشعار ${doc.data.hasLogo ? '(مرفوع)' : ''}`}><input type="file" accept=".png,.jpg,.jpeg" onChange={(e) => upload('logo', e.target.files?.[0])} /></Field>
          <Field label={`الخلفية ${doc.data.hasBackground ? '(مرفوعة)' : ''}`}><input type="file" accept=".png,.jpg,.jpeg" onChange={(e) => upload('background', e.target.files?.[0])} /></Field>
        </div>
        <Button variant="primary" onClick={() => attempt(() => api.put('/templates/doc', { headerText: d.headerText, footerText: d.footerText, signatureLayout: { left: d.left, right: d.right, stamp: d.stamp } }), 'حُفظ القالب (إصدار جديد).', doc.reload)}>«احفظ القالب»</Button>
      </Panel>

      {editing && <ClauseModal clause={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); clauses.reload(); }} />}
    </>
  );
}

function ClauseModal({ clause, onClose, onSaved }: { clause: Json | null; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({ title: clause?.title ?? '', body: clause?.body ?? '', appliesTo: (clause?.appliesTo ?? ['supply']) as string[], sort: clause?.sort ?? 1 });
  const [msg, setMsg] = useState<string | null>(null);
  const toggle = (t: string) => setF((s) => ({ ...s, appliesTo: s.appliesTo.includes(t) ? s.appliesTo.filter((x) => x !== t) : [...s.appliesTo, t] }));
  const save = async () => {
    try {
      const body = { ...f, sort: Number(f.sort) || 1 };
      if (clause) await api.patch(`/templates/clauses/${clause.id}`, body);
      else await api.post('/templates/clauses', body);
      onSaved();
    } catch (e) {
      setMsg(errorText(e));
    }
  };
  return (
    <div className="modal-backdrop" onClick={onClose} role="dialog" aria-modal="true">
      <div className="modal" style={{ maxWidth: 640 }} onClick={(e) => e.stopPropagation()}>
        <h2>{clause ? `تعديل البند (سينتج الإصدار ${clause.version + 1})` : 'بند جديد'}</h2>
        {msg && <ErrorBox>{msg}</ErrorBox>}
        <Field label="العنوان"><input className="input" value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></Field>
        <Field label="النص"><textarea className="textarea" rows={8} value={f.body} onChange={(e) => setF({ ...f, body: e.target.value })} /></Field>
        <Field label="الترتيب"><input className="input ltr" type="number" value={f.sort} onChange={(e) => setF({ ...f, sort: Number(e.target.value) })} /></Field>
        <div className="chips" style={{ marginBottom: 12 }}>
          {CLAUSE_TYPES.map((t) => (
            <label key={t.value} className={`chip ${f.appliesTo.includes(t.value) ? 'on' : ''}`}>
              <input type="checkbox" checked={f.appliesTo.includes(t.value)} onChange={() => toggle(t.value)} /> {t.label}
            </label>
          ))}
        </div>
        <div className="row" style={{ justifyContent: 'flex-end' }}>
          <Button onClick={onClose}>«تراجع»</Button>
          <Button variant="primary" onClick={save}>«احفظ»</Button>
        </div>
      </div>
    </div>
  );
}
