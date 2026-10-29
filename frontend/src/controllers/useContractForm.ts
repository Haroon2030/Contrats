'use client';

import { useEffect, useMemo, useState } from 'react';
import { api, ApiError } from '@/models/api';
import type { ContractFormValues, ReferenceOptions } from '@/models/types';

const EMPTY: ContractFormValues = {
  contractType: 'supply',
  subtype: 'fresh',
  internalClassification: '',
  vendorId: '',
  departmentId: '',
  startDate: '',
  endDate: '',
  actualValue: '',
  hasNonStandardTerms: false,
  shelfRateId: '',
  shelfActualPrice: '',
  purchasingManagerId: '',
  notes: '',
  targets: [],
};

export interface ContractFormState {
  values: ContractFormValues;
  set: <K extends keyof ContractFormValues>(key: K, value: ContractFormValues[K]) => void;
  setTarget: (index: number, key: 'title' | 'targetValue' | 'discountPercent', value: string) => void;
  addTarget: () => void;
  removeTarget: (index: number) => void;
}

/**
 * نموذج إنشاء العقد: الحقول تُرسل للخادم كما هي. التحقق الكامل في الخادم (مصدر الحقيقة)،
 * والواجهة تعرض أخطاءه بجانب كل حقل.
 */
export function useContractForm(initial?: Partial<ContractFormValues>) {
  const [values, setValues] = useState<ContractFormValues>({ ...EMPTY, ...initial });
  const [options, setOptions] = useState<ReferenceOptions | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get<ReferenceOptions>('/reference/options').then(setOptions).catch(() => setOptions(null));
  }, []);

  const form: ContractFormState = useMemo(
    () => ({
      values,
      set: (key, value) => setValues((v) => ({ ...v, [key]: value })),
      setTarget: (index, key, value) =>
        setValues((v) => ({ ...v, targets: v.targets.map((t, i) => (i === index ? { ...t, [key]: value } : t)) })),
      addTarget: () =>
        setValues((v) => ({ ...v, targets: [...v.targets, { title: '', targetValue: '', discountPercent: '' }] })),
      removeTarget: (index) => setValues((v) => ({ ...v, targets: v.targets.filter((_, i) => i !== index) })),
    }),
    [values],
  );

  /** تقدير المعتمد المطلوب للمعاينة الجانبية — الحساب الرسمي يتم في الخادم. */
  const preview = useMemo(() => {
    const value = Number(values.actualValue) || 0;
    const shelfDeviates =
      values.contractType === 'shelf_rent' &&
      values.shelfActualPrice !== '' &&
      options?.shelfRates.find((s) => s.id === Number(values.shelfRateId))?.standardPrice !== Number(values.shelfActualPrice);
    if (values.hasNonStandardTerms) return { approver: 'مدير إدارة العقود', reason: 'شروط غير معيارية' };
    if (values.contractType === 'shelf_rent' && !shelfDeviates && values.shelfRateId) {
      return { approver: 'رئيس القسم', reason: 'إيجار أرفف معياري' };
    }
    if (value > 15000) return { approver: 'مدير إدارة العقود', reason: 'قيمة تتجاوز 15,000 ر.س' };
    return { approver: 'رئيس القسم', reason: 'ضمن الحد' };
  }, [values, options]);

  /** حفظ مسودة أو إرسال مباشر. يعيد الرقم عند النجاح. */
  const save = async (mode: 'draft' | 'submit', contractId?: number): Promise<{ id: number } | null> => {
    setSaving(true);
    setFieldErrors({});
    setFormError(null);
    const payload = {
      contractType: values.contractType,
      subtype: values.contractType === 'supply' || values.contractType === 'services' ? values.subtype : undefined,
      internalClassification: values.internalClassification,
      vendorId: Number(values.vendorId),
      departmentId: Number(values.departmentId),
      startDate: values.startDate,
      endDate: values.endDate,
      actualValue: Number(values.actualValue),
      hasNonStandardTerms: values.hasNonStandardTerms,
      shelfRateId: values.shelfRateId ? Number(values.shelfRateId) : undefined,
      shelfActualPrice: values.shelfActualPrice ? Number(values.shelfActualPrice) : undefined,
      purchasingManagerId: values.purchasingManagerId ? Number(values.purchasingManagerId) : undefined,
      notes: values.notes,
      targets: values.targets
        .filter((t) => t.title || t.targetValue || t.discountPercent)
        .map((t) => ({ title: t.title, targetValue: Number(t.targetValue), discountPercent: Number(t.discountPercent) })),
    };
    try {
      if (contractId) {
        // تعديل مسودة موجودة، ثم إرسالها إن طُلب ذلك.
        await api.put(`/contracts/${contractId}`, payload);
        if (mode === 'submit') await api.post(`/contracts/${contractId}/actions/submit`);
        return { id: contractId };
      }
      const contract = await api.post<{ id: number }>('/contracts', { ...payload, submit: mode === 'submit' });
      return { id: contract.id };
    } catch (e) {
      if (e instanceof ApiError) {
        setFieldErrors(e.fields);
        setFormError(e.message);
      } else {
        setFormError('تعذّر حفظ العقد.');
      }
      return null;
    } finally {
      setSaving(false);
    }
  };

  return { form, options, preview, fieldErrors, formError, saving, save };
}
