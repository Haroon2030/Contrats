import ExcelJS from 'exceljs';

/** تصدير سجل العقود إلى Excel (واجهة عربية من اليمين لليسار). */
export async function exportContractsXlsx(rows: any[]): Promise<Buffer> {
  const wb = new ExcelJS.Workbook();
  const ws = wb.addWorksheet('العقود', { views: [{ rightToLeft: true, state: 'frozen', ySplit: 1 }] });
  ws.columns = [
    { header: 'رقم العقد', key: 'number', width: 16 },
    { header: 'المورد', key: 'vendor', width: 30 },
    { header: 'رقم المورد', key: 'vendorNumber', width: 14 },
    { header: 'الأنواع', key: 'types', width: 34 },
    { header: 'القسم', key: 'department', width: 16 },
    { header: 'الحالة', key: 'status', width: 16 },
    { header: 'الجهة الحالية', key: 'party', width: 20 },
    { header: 'تاريخ البداية', key: 'start', width: 14 },
    { header: 'تاريخ النهاية', key: 'end', width: 14 },
    { header: 'الأيام المتبقية', key: 'days', width: 14 },
    { header: 'تنبيه', key: 'alert', width: 8 },
  ];
  ws.getRow(1).font = { bold: true };
  ws.getRow(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFEFEBE4' } };
  const fmt = (iso: string | null) => (iso ? iso.split('-').reverse().join('/') : '');
  rows.forEach((r) =>
    ws.addRow({
      number: r.number ?? '',
      vendor: r.vendorName,
      vendorNumber: r.vendorNumber,
      types: (r.typeLabels ?? []).join('، '),
      department: r.departmentName,
      status: r.statusLabel,
      party: r.partyLabel ?? '',
      start: fmt(r.startDate),
      end: fmt(r.endDate),
      days: r.daysRemaining ?? '',
      alert: r.hasAlert ? 'نعم' : '',
    }),
  );
  return Buffer.from(await wb.xlsx.writeBuffer());
}

/** تقرير الأهداف والخصم التقديري. */
export async function exportGoalsXlsx(rows: any[]): Promise<Buffer> {
  const wb = new ExcelJS.Workbook();
  const ws = wb.addWorksheet('الأهداف', { views: [{ rightToLeft: true, state: 'frozen', ySplit: 1 }] });
  ws.columns = [
    { header: 'القسم', key: 'department', width: 16 },
    { header: 'المورد', key: 'vendor', width: 30 },
    { header: 'رقم العقد', key: 'number', width: 16 },
    { header: 'من', key: 'from', width: 12 },
    { header: 'إلى', key: 'to', width: 12 },
    { header: 'الهدف', key: 'target', width: 16 },
    { header: 'صافي المشتريات حتى الآن', key: 'net', width: 22 },
    { header: 'نسبة التحقق %', key: 'pct', width: 14 },
    { header: 'الحالة', key: 'status', width: 16 },
    { header: 'نسبة الخصم %', key: 'disc', width: 14 },
    { header: 'خصم مستحق تقديري', key: 'est', width: 20 },
  ];
  ws.getRow(1).font = { bold: true };
  ws.getRow(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFEFEBE4' } };
  const fmt = (iso: string) => iso.split('-').reverse().join('/');
  rows.forEach((r) =>
    ws.addRow({ department: r.departmentName, vendor: r.vendorName, number: r.number, from: fmt(r.fromDate), to: fmt(r.toDate), target: r.target, net: r.net, pct: r.achievedPct, status: r.statusLabel, disc: r.discountPct, est: r.estimatedDiscount }),
  );
  return Buffer.from(await wb.xlsx.writeBuffer());
}
