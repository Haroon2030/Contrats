'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useContractDetail, type TabKey } from '@/controllers/useContractDetail';
import { useToast } from '@/controllers/toast';
import { ACTION_CONFIRM, ALERT_LABELS, ATTACHMENT_KINDS, STAGES } from '@/models/labels';
import type { ContractAction, ContractDetail } from '@/models/types';
import {
  Button,
  Empty,
  ErrorBox,
  Field,
  Loading,
  PageHead,
  Panel,
  ReasonModal,
  StatusBadge,
  fmtDate,
  fmtDateTime,
  fmtMoney,
} from '../ui';

const TABS: { key: TabKey; label: string }[] = [
  { key: 'summary', label: 'الملخص' },
  { key: 'terms', label: 'الشروط والأحكام' },
  { key: 'attachments', label: 'المرفقات' },
  { key: 'history', label: 'الملاحق والتاريخ' },
  { key: 'audit', label: 'سجل التدقيق' },
];

/** صفحة العقد: مراحل، إجراءات حسب الدور والمرحلة، وتبويبات. */
export function ContractDetailView({ id }: { id: number }) {
  const { contract, loading, error, busy, run, addNote, upload } = useContractDetail(id);
  const toast = useToast();
  const router = useRouter();
  const [tab, setTab] = useState<TabKey>('summary');
  const [modal, setModal] = useState<{ key: string; error?: string } | null>(null);
  const [noteOpen, setNoteOpen] = useState(false);
  const [uploadOpen, setUploadOpen] = useState(false);

  if (loading) return <Loading />;
  if (error || !contract) return <ErrorBox>{error ?? 'تعذّر تحميل العقد.'}</ErrorBox>;

  const c = contract;
  const primary = c.actions.filter((a) => a.primary);
  const secondary = c.actions.filter((a) => !a.primary && !['note', 'upload'].includes(a.key));

  const execute = async (key: string, reason?: string) => {
    const cfg = ACTION_CONFIRM[key];
    if (cfg?.reasonRequired && !reason) {
      setModal({ key, error: 'هذا الحقل مطلوب.' });
      return;
    }
    const res = await run(key, reason);
    if (res.ok) {
      setModal(null);
      toast.success(key === 'delete' ? 'حُذفت المسودة.' : 'تم تنفيذ الإجراء وتحديث العقد.');
      if (key === 'delete') router.replace('/contracts');
    } else {
      setModal((m) => (m ? { ...m, error: res.message } : null));
      toast.error(res.message);
    }
  };

  const onAction = (a: ContractAction) => {
    if (ACTION_CONFIRM[a.key]) setModal({ key: a.key });
    else if (a.key === 'edit') router.push(`/contracts/${c.id}/edit`);
    else if (a.key === 'upload') setUploadOpen(true);
    else if (a.key === 'note') setNoteOpen(true);
    else execute(a.key);
  };

  const currentIndex = STAGES.findIndex((s) => s.key === c.status);
  const terminal = c.status === 'cancelled' || c.status === 'expired';

  return (
    <>
      <nav className="muted" style={{ fontSize: 13 }}>
        <Link href="/contracts">سجل العقود</Link> / <span className="ltr">{c.contractNumber}</span>
      </nav>

      <PageHead
        title={`${c.vendorName}`}
        subtitle={`${c.contractTypeLabel}${c.subtypeLabel ? ` — ${c.subtypeLabel}` : ''} · ${c.departmentName}`}
        actions={
          <div className="row">
            <span className="ltr" style={{ fontWeight: 700, fontSize: 18 }}>{c.contractNumber}</span>
            <StatusBadge status={c.status} label={c.statusLabel} />
          </div>
        }
      />

      {c.alerts.length > 0 && (
        <div className="stack" style={{ gap: 8 }}>
          {c.alerts.map((a) => (
            <div key={a.id} className={a.severity === 'critical' ? 'alert-banner' : 'notice'}>
              {ALERT_LABELS[a.alertType] ?? a.alertType}: {a.message}
            </div>
          ))}
        </div>
      )}

      {terminal ? (
        <div className="notice">
          {c.status === 'cancelled' ? `العقد ملغى${c.cancellationReason ? ` — السبب: ${c.cancellationReason}` : ''}.` : 'العقد منتهٍ.'}
        </div>
      ) : (
        <div className="stepper" aria-label="مراحل العقد">
          {STAGES.map((s, i) => (
            <div key={s.key} className={`step${i < currentIndex ? ' done' : i === currentIndex ? ' current' : ''}`}>
              {s.label}
            </div>
          ))}
        </div>
      )}

      <Panel>
        <div className="row" style={{ justifyContent: 'space-between' }}>
          <div>
            <div className="muted" style={{ fontSize: 13 }}>الجهة المسؤولة</div>
            <strong>{c.responsible ?? '—'}</strong>
            {c.pendingApprover && <span className="muted"> — المعتمد: {c.pendingApprover}</span>}
            <div className="muted" style={{ fontSize: 13 }}>
              منتظر منذ {c.waitingDays} يوم · المعتمد المطلوب: {c.requiredApprover || '—'}
            </div>
          </div>
          <span className="spacer" />
          <div className="row">
            {primary.map((a) => (
              <Button key={a.key} variant={a.key === 'reject' || a.key === 'cancel' ? 'danger' : 'primary'} disabled={busy} onClick={() => onAction(a)}>
                «{a.label}»
              </Button>
            ))}
            {secondary.map((a) => (
              <Button key={a.key} variant={a.key === 'delete' ? 'danger' : undefined} disabled={busy} onClick={() => onAction(a)}>
                «{a.label}»
              </Button>
            ))}
            {c.actions.some((a) => a.key === 'upload') && <Button onClick={() => setUploadOpen(true)}>«ارفع مرفقاً»</Button>}
            {c.actions.some((a) => a.key === 'note') && <Button onClick={() => setNoteOpen(true)}>«أضف ملاحظة»</Button>}
          </div>
        </div>
      </Panel>

      <div className="tabs" role="tablist">
        {TABS.map((t) => (
          <button key={t.key} role="tab" aria-selected={tab === t.key} className={tab === t.key ? 'active' : ''} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'summary' && <SummaryTab c={c} />}
      {tab === 'terms' && <TermsTab c={c} />}
      {tab === 'attachments' && <AttachmentsTab c={c} onUpload={() => setUploadOpen(true)} />}
      {tab === 'history' && <HistoryTab c={c} />}
      {tab === 'audit' && <AuditTab c={c} />}

      {modal && ACTION_CONFIRM[modal.key] && (
        <ReasonModal
          title={ACTION_CONFIRM[modal.key].title}
          label={ACTION_CONFIRM[modal.key].reasonLabel}
          required={ACTION_CONFIRM[modal.key].reasonRequired}
          confirmLabel={ACTION_CONFIRM[modal.key].confirm}
          danger={ACTION_CONFIRM[modal.key].danger}
          busy={busy}
          error={modal.error}
          onCancel={() => setModal(null)}
          onConfirm={(reason) => execute(modal.key, reason || undefined)}
        />
      )}

      {noteOpen && (
        <ReasonModal
          title="أضف ملاحظة"
          label="نص الملاحظة"
          required
          confirmLabel="أضف"
          busy={busy}
          onCancel={() => setNoteOpen(false)}
          onConfirm={async (text) => {
            const res = await addNote(text);
            if (res.ok) {
              setNoteOpen(false);
              toast.success('أُضيفت الملاحظة إلى سجل التدقيق.');
            } else toast.error(res.message);
          }}
        />
      )}

      {uploadOpen && <UploadModal busy={busy} onCancel={() => setUploadOpen(false)} onUpload={async (kind, file) => {
        const res = await upload(kind, file);
        if (res.ok) {
          setUploadOpen(false);
          toast.success('رُفع المرفق بنجاح.');
        } else toast.error(res.message);
      }} />}
    </>
  );
}

function SummaryTab({ c }: { c: ContractDetail }) {
  return (
    <div className="stack">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: 20 }}>
        <Panel title="بيانات العقد">
          <dl className="info">
            <Info k="المورد" v={`${c.vendorName} (${c.vendorNumber})`} ltr />
            <Info k="القسم المسؤول" v={c.departmentName} />
            <Info k="النوع" v={`${c.contractTypeLabel}${c.subtypeLabel ? ` — ${c.subtypeLabel}` : ''}`} />
            <Info k="التصنيف الداخلي" v={c.internalClassification || '—'} />
            <Info k="تاريخ البدء" v={fmtDate(c.startDate)} />
            <Info k="تاريخ الانتهاء" v={`${fmtDate(c.endDate)} (${c.daysRemaining >= 0 ? `متبقٍ ${c.daysRemaining} يوم` : 'منتهٍ'})`} />
            <Info k="القيمة الفعلية" v={fmtMoney(c.actualValue)} />
            <Info k="مسؤول العقد التجاري" v={c.commercialOwner ?? '—'} />
            <Info k="أُنشئ بواسطة" v={c.createdBy ?? '—'} />
          </dl>
        </Panel>

        <Panel title="الاعتماد والتوقيع">
          <dl className="info">
            <Info k="المعتمد المطلوب" v={c.requiredApprover || '—'} />
            <Info k="قيمة مرتفعة" v={c.isHighValue ? 'نعم (أكثر من 15,000 ر.س)' : 'لا'} />
            <Info k="شروط غير معيارية" v={c.hasNonStandardTerms ? 'نعم — مراجعة قانونية وإدارة العقود' : 'لا'} />
            <Info k="توقيع الطرفين" v={c.bothPartiesSigned ? `مكتمل — ${fmtDateTime(c.signedAt)}` : 'غير مكتمل'} />
            <Info k="التفعيل" v={c.activatedAt ? fmtDateTime(c.activatedAt) : '—'} />
            {c.cancelledAt && <Info k="الإلغاء" v={fmtDateTime(c.cancelledAt)} />}
          </dl>
          {c.shelfRate && (
            <div className="notice" style={{ marginTop: 12 }}>
              إيجار أرفف: {c.shelfRate.name} — المعياري {fmtMoney(c.shelfRate.standardPrice)}
              {c.shelfActualPrice != null && ` · الفعلي ${fmtMoney(c.shelfActualPrice)}`}
              {c.hasShelfPriceDeviation && ` · انحراف ${c.shelfDeviationPercent.toFixed(1)}%`}
            </div>
          )}
        </Panel>
      </div>

      <Panel title="أهداف الشرائح والخصم">
        {c.targets.length === 0 ? (
          <Empty>لا توجد شرائح مسجّلة لهذا العقد.</Empty>
        ) : (
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>الشريحة</th>
                  <th className="num">قيمة الهدف</th>
                  <th className="num">نسبة الخصم</th>
                  <th className="num">الخصم التقديري</th>
                  <th>المتحقق</th>
                </tr>
              </thead>
              <tbody>
                {c.targets.map((t) => (
                  <tr key={t.id}>
                    <td>{t.title}</td>
                    <td className="num">{fmtMoney(t.targetValue)}</td>
                    <td className="num">{t.discountPercent}%</td>
                    <td className="num">{fmtMoney(t.estimatedDiscount)}</td>
                    <td className="muted">لا توجد بيانات مشتريات</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Panel title="سلسلة الاعتماد">
        {c.approvalSteps.length === 0 ? (
          <Empty>لم تبدأ سلسلة الاعتماد بعد.</Empty>
        ) : (
          <ol className="stack" style={{ gap: 8, paddingInlineStart: 20, margin: 0 }}>
            {c.approvalSteps.map((s) => (
              <li key={s.id}>
                <strong>{s.roleLabel}</strong> —{' '}
                <span className={`badge ${s.decision === 'rejected' ? 'danger' : s.decision === 'approved' ? 'neutral' : 'accent'}`}>
                  {s.decision === 'approved' ? 'معتمد' : s.decision === 'rejected' ? 'مرفوض' : 'معلّق'}
                </span>
                {s.decidedBy && <span className="muted"> — {s.decidedBy} · {fmtDateTime(s.decidedAt)}</span>}
                {s.comment && <div className="muted" style={{ fontSize: 13 }}>{s.comment}</div>}
              </li>
            ))}
          </ol>
        )}
      </Panel>
    </div>
  );
}

function TermsTab({ c }: { c: ContractDetail }) {
  return (
    <Panel title="الشروط والأحكام">
      <dl className="info">
        <Info k="شروط غير معيارية" v={c.hasNonStandardTerms ? 'نعم' : 'لا'} />
        <Info k="تجديد تلقائي" v={c.autoRenewal ? `نعم — آخر إشعار ${fmtDate(c.lastNoticeDate)}` : 'لا (المدة سنة كحد أقصى، دون تجديد تلقائي)'} />
      </dl>
      <div className="notice" style={{ marginTop: 14 }}>
        نصوص البنود المعيارية تُدار من قسم «القوالب والشروط»، وقد تُضاف لاحقاً إلى هذه الصفحة.
      </div>
      <h3 style={{ marginTop: 18 }}>ملاحظات العقد</h3>
      <p style={{ whiteSpace: 'pre-wrap' }}>{c.notes || 'لا توجد ملاحظات.'}</p>
    </Panel>
  );
}

function AttachmentsTab({ c, onUpload }: { c: ContractDetail; onUpload: () => void }) {
  return (
    <Panel title="المرفقات" actions={<Button small onClick={onUpload}>«ارفع مرفقاً»</Button>}>
      {c.attachments.length === 0 ? (
        <Empty>لا توجد مرفقات بعد. ابدأ برفع النسخة الأصلية قبل إرسال العقد.</Empty>
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>النوع</th>
                <th>اسم الملف</th>
                <th>رفعه</th>
                <th>التاريخ</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {c.attachments.map((a) => (
                <tr key={a.id}>
                  <td>{a.kindLabel}</td>
                  <td>{a.originalName}</td>
                  <td>{a.uploadedBy ?? '—'}</td>
                  <td>{fmtDateTime(a.uploadedAt)}</td>
                  <td>
                    <a href={`/api/contracts/${c.id}/attachments/${a.id}`} className="badge accent">
                      تنزيل
                    </a>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Panel>
  );
}

function HistoryTab({ c }: { c: ContractDetail }) {
  const amendments = c.attachments.filter((a) => a.kind === 'addendum');
  return (
    <div className="stack">
      <Panel title="الملاحق">
        {amendments.length === 0 ? (
          <Empty>لا توجد ملاحق لهذا العقد.</Empty>
        ) : (
          <ul style={{ margin: 0, paddingInlineStart: 20 }}>
            {amendments.map((a) => (
              <li key={a.id}>
                {a.originalName} — {fmtDateTime(a.uploadedAt)}
              </li>
            ))}
          </ul>
        )}
      </Panel>
      <Panel title="تاريخ الحالة">
        <ol className="stack" style={{ gap: 10, paddingInlineStart: 20, margin: 0 }}>
          {[...c.auditLogs]
            .filter((l) => l.toStatus && l.fromStatus !== l.toStatus)
            .reverse()
            .map((l) => (
              <li key={l.id}>
                <strong>{l.action}</strong> — {l.fromStatusLabel || '—'} ← {l.toStatusLabel} · {l.actorName} · {fmtDateTime(l.createdAt)}
              </li>
            ))}
        </ol>
      </Panel>
    </div>
  );
}

function AuditTab({ c }: { c: ContractDetail }) {
  if (c.auditLogs.length === 0) return <Empty>لا توجد إجراءات مسجّلة.</Empty>;
  return (
    <div className="table-wrap">
      <table className="table">
        <thead>
          <tr>
            <th>الوقت</th>
            <th>المستخدم</th>
            <th>الدور</th>
            <th>الإجراء</th>
            <th>التغيير</th>
            <th>ملاحظة</th>
          </tr>
        </thead>
        <tbody>
          {c.auditLogs.map((l) => (
            <tr key={l.id}>
              <td>{fmtDateTime(l.createdAt)}</td>
              <td>{l.actorName ?? '—'}</td>
              <td>{l.actorRole ?? '—'}</td>
              <td>{l.action}</td>
              <td>
                {l.fromStatus || l.toStatus ? `${l.fromStatusLabel || '—'} ← ${l.toStatusLabel || '—'}` : '—'}
              </td>
              <td className="muted">{String((l.details as { comment?: string; text?: string; reason?: string }).comment ?? (l.details as { text?: string }).text ?? (l.details as { reason?: string }).reason ?? '')}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Info({ k, v, ltr }: { k: string; v: string; ltr?: boolean }) {
  return (
    <div className="info-row">
      <dt className="muted">{k}</dt>
      <dd className={ltr ? 'ltr' : undefined}>{v}</dd>
    </div>
  );
}

function UploadModal({
  busy,
  onCancel,
  onUpload,
}: {
  busy: boolean;
  onCancel: () => void;
  onUpload: (kind: string, file: File) => void;
}) {
  const [kind, setKind] = useState('original');
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  return (
    <div className="modal-backdrop" onClick={onCancel} role="dialog" aria-modal="true" aria-label="رفع مرفق">
      <div className="modal" onClick={(e) => e.stopPropagation()}>
        <h2>رفع مرفق</h2>
        <Field label="نوع المرفق">
          <select className="select" value={kind} onChange={(e) => setKind(e.target.value)}>
            {ATTACHMENT_KINDS.map((k) => (
              <option key={k.value} value={k.value}>
                {k.label}
              </option>
            ))}
          </select>
        </Field>
        <Field label="الملف" hint="PDF أو Word أو صورة — حتى 10 ميغابايت.">
          <input type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>
        {error && <ErrorBox>{error}</ErrorBox>}
        <div className="row" style={{ justifyContent: 'flex-end' }}>
          <Button onClick={onCancel}>«تراجع»</Button>
          <Button
            variant="primary"
            disabled={busy}
            onClick={() => (file ? onUpload(kind, file) : setError('اختر ملفاً أولاً.'))}
          >
            {busy ? '…' : '«ارفع»'}
          </Button>
        </div>
      </div>
    </div>
  );
}

