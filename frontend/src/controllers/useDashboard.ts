'use client';

import { useEffect, useState } from 'react';
import { api, ApiError } from '@/models/api';
import type { AlertRow, DashboardSummary, GoalRow } from '@/models/types';

/** لوحة التحكم + الإشعارات (التنبيهات المفتوحة). */
export function useDashboard() {
  const [summary, setSummary] = useState<DashboardSummary | null>(null);
  const [alerts, setAlerts] = useState<AlertRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([api.get<DashboardSummary>('/dashboard'), api.get<AlertRow[]>('/alerts')])
      .then(([s, a]) => {
        setSummary(s);
        setAlerts(a);
      })
      .catch((e) => setError(e instanceof ApiError ? e.message : 'تعذّر تحميل لوحة التحكم.'))
      .finally(() => setLoading(false));
  }, []);

  const criticalCount = alerts.filter((a) => a.severity === 'critical').length;
  return { summary, alerts, criticalCount, error, loading };
}

/** الأهداف والخصومات: الشرائح من العقود، وحالة التحقق تحتاج بيانات المشتريات الفعلية. */
export function useGoals() {
  const [goals, setGoals] = useState<GoalRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .get<GoalRow[]>('/dashboard/goals')
      .then(setGoals)
      .catch((e) => setError(e instanceof ApiError ? e.message : 'تعذّر تحميل الأهداف.'))
      .finally(() => setLoading(false));
  }, []);

  const byDepartment = Object.values(
    goals.reduce<Record<string, { department: string; discount: number; count: number }>>((acc, g) => {
      const entry = acc[g.department] ?? { department: g.department, discount: 0, count: 0 };
      entry.discount += g.estimatedDiscount;
      entry.count += 1;
      acc[g.department] = entry;
      return acc;
    }, {}),
  ).sort((a, b) => b.discount - a.discount);

  return { goals, byDepartment, error, loading, totalDiscount: goals.reduce((s, g) => s + g.estimatedDiscount, 0) };
}
