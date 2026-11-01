// اختبار مسار العمل كاملاً (BRD v3.0) على خادم يعمل على قاعدة مؤقتة.
// التشغيل: DEV_SEED=1 BOOTSTRAP_ADMIN_USERNAME=1 BOOTSTRAP_ADMIN_PASSWORD=526400 node dist/main.js
//          ثم: node test/flow.mjs
const BASE = process.env.API || 'http://127.0.0.1:4100/api';
let fails = 0;
let passes = 0;

class Client {
  constructor(name) {
    this.name = name;
    this.cookie = '';
  }
  async req(method, path, body, form) {
    const headers = {};
    if (this.cookie) headers.cookie = this.cookie;
    let payload;
    if (form) payload = form;
    else if (body !== undefined) {
      headers['content-type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    const res = await fetch(BASE + path, { method, headers, body: payload });
    const set = res.headers.getSetCookie?.() ?? [];
    for (const c of set) if (c.startsWith('contracts_token=')) this.cookie = c.split(';')[0];
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      data = text;
    }
    return { status: res.status, data };
  }
  get = (p) => this.req('GET', p);
  post = (p, b) => this.req('POST', p, b ?? {});
  put = (p, b) => this.req('PUT', p, b);
  patch = (p, b) => this.req('PATCH', p, b);
  del = (p) => this.req('DELETE', p);
  upload = (p, fields, filename = 'file.pdf', content = '%PDF-1.4 test') => {
    const form = new FormData();
    for (const [k, v] of Object.entries(fields)) form.append(k, v);
    form.append('file', new Blob([content], { type: 'application/pdf' }), filename);
    return this.req('POST', p, undefined, form);
  };
}

function ok(cond, label, extra) {
  if (cond) {
    passes++;
    console.log(`  PASS  ${label}`);
  } else {
    fails++;
    console.log(`  FAIL  ${label}`, extra !== undefined ? JSON.stringify(extra).slice(0, 400) : '');
  }
}

async function login(username, password = 'Demo12345') {
  const c = new Client(username);
  const r = await c.post('/auth/login', { username, password });
  if (r.status !== 201) throw new Error(`login ${username} failed: ${JSON.stringify(r.data)}`);
  c.user = r.data.user;
  return c;
}

const today = new Date().toISOString().slice(0, 10);
const addDays = (iso, n) => new Date(Date.parse(iso + 'T00:00:00Z') + n * 86400000).toISOString().slice(0, 10);
const yearAhead = addDays(today, 300);

async function main() {
  console.log('== تسجيل الدخول والجلسة ==');
  const admin = await login('1', '526400');
  ok(admin.user.role === 'system_admin', 'مسؤول النظام الأول يعمل');
  const bad = new Client('x');
  for (let i = 0; i < 5; i++) await bad.post('/auth/login', { username: 'cm', password: 'wrong-pass-1' });
  const locked = await bad.post('/auth/login', { username: 'cm', password: 'Demo12345' });
  ok(locked.status === 429, 'القفل بعد 5 محاولات خاطئة (حتى مع كلمة المرور الصحيحة)', locked);

  const cm = await login('co'); // نستخدم co بدل cm المقفل
  const cmgr = admin; // سنبدّل مدير العقود لاحقاً بعد إعادة التعيين
  const reset = await admin.post('/users/' + (await admin.get('/users')).data.find((u) => u.username === 'cm').id + '/reset-password');
  ok(!!reset.data.temporaryPassword, 'إعادة تعيين كلمة المرور تُنتج كلمة مؤقتة', reset.data);
  const cmTmp = new Client('cm');
  const lg = await cmTmp.post('/auth/login', { username: 'cm', password: reset.data.temporaryPassword });
  ok(lg.status === 201 && lg.data.user.mustChangePassword === true, 'الدخول بالمؤقتة يطلب تغيير كلمة المرور');
  const blocked = await cmTmp.get('/contracts');
  ok(blocked.status === 403 && blocked.data.code === 'PASSWORD_CHANGE_REQUIRED', 'كل شيء ممنوع قبل تغيير كلمة المرور', blocked);
  const weak = await cmTmp.post('/auth/change-password', { current: reset.data.temporaryPassword, next: 'abcdefgh' });
  ok(weak.status === 400, 'كلمة مرور بلا أرقام مرفوضة', weak.data);
  const chg = await cmTmp.post('/auth/change-password', { current: reset.data.temporaryPassword, next: 'Manager2026' });
  ok(chg.status === 201, 'تغيير كلمة المرور ينجح');
  const CM = await login('cm', 'Manager2026');

  const co = cm;
  const pm = await login('pm');
  const dmF = await login('dm_fresh');
  const doF = await login('do_fresh');
  const dmFood = await login('dm_food');
  const ops = await login('ops');

  const opts = (await doF.get('/reference/options')).data;
  const vendor = opts.vendors[0];
  const vendor2 = opts.vendors[1];
  const branch = opts.branches[0];
  const shelf = opts.shelfTypes[0];
  const event = opts.events[0];
  const fresh = opts.departments.find((d) => d.code === 'FRESH');

  console.log('== التحقق من النموذج ==');
  const supplyBody = (over = {}) => ({
    vendorId: vendor.id,
    entryPath: 'new',
    types: ['supply'],
    repName: 'محمد أحمد',
    repTitle: 'مدير',
    repMobile: '0501234567',
    startDate: today,
    endDate: yearAhead,
    specialTerms: '',
    supply: {
      paymentDays: 60,
      settlementDiscountPct: 2,
      fixedDiscountPct: 1,
      goalLines: [
        { fromDate: today, toDate: yearAhead, target: 1000000, discountPct: 3 },
        { fromDate: today, toDate: yearAhead, target: 2000000, discountPct: 5 },
      ],
    },
    ...over,
  });

  let r = await doF.post('/contracts', supplyBody());
  ok(r.status === 201 && r.data.status === 'draft' && r.data.number === null, 'إنشاء مسودة بلا رقم', r.data);
  const draftId = r.data.id;
  ok(r.data.department.id === fresh.id, 'القسم مقفل على قسم مسؤول القسم');

  r = await doF.post(`/contracts/${draftId}/actions/send`);
  ok(r.status === 201 && /-01$/.test(r.data.number) && r.data.status === 'under_review' && r.data.party === 'department_manager', 'الإرسال يخصص الرقم ويحوّل لمدير القسم', r.data);
  const number1 = r.data.number;

  // أخطاء الإرسال
  r = await doF.post('/contracts', supplyBody({ repMobile: '123', endDate: addDays(today, 500) }));
  ok(r.status === 400 && r.data.errors.repMobile && r.data.errors.endDate, 'جوال خاطئ ومدة تتجاوز سنة', r.data);
  ok(/سنة واحدة/.test(r.data.errors?.endDate || ''), 'رسالة «أقصى مدة للعقد سنة واحدة»');
  r = await doF.post('/contracts', supplyBody({ vendorId: vendor2.id }));
  const tmpId = r.data.id;
  ok((await doF.del(`/contracts/${tmpId}`)).status === 200, 'حذف مسودة لم تُرسل');

  r = await doF.post('/contracts', supplyBody({ supply: { paymentDays: 30, goalLines: [{ fromDate: today, toDate: yearAhead, target: 1000, discountPct: 120 }] } }));
  const pctId = r.data.id;
  ok(r.status === 400 || (await doF.post(`/contracts/${pctId}/actions/send`)).status === 400, 'نسبة الخصم > 100 مرفوضة');

  console.log('== الرؤية والصلاحيات ==');
  ok((await dmFood.get(`/contracts/${draftId}`)).status === 200, 'مدير قسم آخر يقرأ العقد');
  ok((await dmFood.post(`/contracts/${draftId}/actions/dept-approve`)).status === 403, 'لكن لا يعتمد عقد قسم آخر');
  const doFood = await login('do_food');
  ok((await doFood.get(`/contracts/${draftId}`)).status === 403, 'مسؤول قسم آخر لا يرى العقد');
  ok((await ops.get('/contracts')).status === 403, 'قسم التشغيل لا يرى سجل العقود');
  ok((await pm.post('/contracts', supplyBody())).status === 403, 'مدير المشتريات لا ينشئ عقوداً');

  console.log('== اعتماد مدير القسم → بانتظار التوقيع → نشط ==');
  r = await dmFood.post(`/contracts/${draftId}/actions/dept-reject`, { reason: 'x' });
  ok(r.status === 403, 'رفض من خارج القسم ممنوع');
  r = await dmF.post(`/contracts/${draftId}/actions/dept-reject`, {});
  ok(r.status === 400, 'سبب الرفض إلزامي');
  r = await dmF.post(`/contracts/${draftId}/actions/dept-approve`, {});
  ok(r.data.status === 'awaiting_signature' && r.data.party === 'purchasing_manager', 'اعتماد بلا تنبيه → بانتظار التوقيع', r.data);
  ok(r.data.stays.length >= 3, 'تُسجَّل مدد الإقامة في الحالات');
  r = await dmF.post(`/contracts/${draftId}/actions/dept-approve`, {});
  ok(r.status === 422, 'لا اعتماد مزدوج');
  const notifPm = (await pm.get('/notifications')).data;
  ok(notifPm.unread >= 1 && notifPm.items.some((n) => n.text.includes(number1)), 'يصل مدير المشتريات إشعار الاعتماد', notifPm);

  r = await pm.upload(`/contracts/${draftId}/actions/upload-signed`, {});
  ok(r.data.status === 'active' && r.data.activatedAt, 'رفع الموقعة → نشط', r.data);
  ok((await pm.get(`/contracts/${draftId}`)).data.attachments.some((a) => a.kind === 'signed'), 'النسخة الموقعة محفوظة');

  console.log('== شروط خاصة → تنبيه آلي → قرار مدير العقود ==');
  r = await doF.post('/contracts', supplyBody({ vendorId: vendor2.id, specialTerms: 'يُسمح بإرجاع البضاعة خلال 60 يوماً.' }));
  const altId = r.data.id;
  r = await doF.post(`/contracts/${altId}/actions/send`);
  ok(r.data.hasAlert === true && r.data.alertKind === 'auto', 'الشروط الخاصة ترفع تنبيهاً آلياً', r.data);
  r = await dmF.post(`/contracts/${altId}/actions/dept-approve`, {});
  ok(r.data.status === 'under_review' && r.data.party === 'contracts_manager', 'اعتماد مع تنبيه → لمدير قسم العقود', r.data);
  ok((await co.post(`/contracts/${altId}/actions/cm-approve`, {})).status === 403, 'موظف العقود لا يملك القرار');
  ok((await co.post(`/contracts/${altId}/actions/note`, { reason: 'يلزم مراجعة قانونية خارجية' })).status === 201, 'موظف العقود يضيف ملاحظة');
  r = await CM.post(`/contracts/${altId}/actions/cm-return`, {});
  ok(r.status === 400, 'ملاحظة الإرجاع إلزامية');
  r = await CM.post(`/contracts/${altId}/actions/cm-return`, { reason: 'أعد صياغة البند' });
  ok(r.data.status === 'draft' && r.data.hasAlert === false && r.data.rejectReason, 'الإرجاع → مسودة وإزالة التنبيه', r.data);
  ok(r.data.number === `${vendor2.vendorNumber}-01`, 'الرقم ثابت بعد الإرجاع');
  r = await doF.post(`/contracts/${altId}/actions/send`);
  ok(r.data.number === `${vendor2.vendorNumber}-01` && r.data.hasAlert, 'إعادة الإرسال تحتفظ بالرقم وتعيد التنبيه');
  await dmF.post(`/contracts/${altId}/actions/dept-approve`, {});
  r = await CM.post(`/contracts/${altId}/actions/cm-approve`, {});
  ok(r.data.status === 'awaiting_signature' && r.data.hasAlert === false, 'اعتماد مدير العقود → بانتظار التوقيع وإزالة التنبيه', r.data);
  const audit = (await CM.get(`/audit?contractId=${altId}`)).data;
  ok(audit.some((a) => a.action.includes('إزالة التنبيه')), 'إزالة التنبيه تبقى في التدقيق');

  console.log('== تنبيه يدوي وتعديل بانتظار التوقيع ==');
  r = await pm.post(`/contracts/${altId}/actions/raise-alert`, { reason: 'طلب المورد تعديل السداد' });
  ok(r.data.status === 'under_review' && r.data.party === 'contracts_manager' && r.data.alertKind === 'manual', 'تنبيه يدوي أثناء بانتظار التوقيع يعيده لمدير العقود', r.data);
  r = await CM.post(`/contracts/${altId}/actions/cm-approve`, {});
  ok(r.data.status === 'awaiting_signature', 'اعتماد من جديد');
  const form = (await pm.get(`/contracts/${altId}/form`)).data;
  r = await pm.put(`/contracts/${altId}/actions/edit-pending`, { ...form, supply: { ...form.supply, paymentDays: 45 } });
  ok(r.status === 200 && r.data.supply.paymentDays === 45 && r.data.status === 'awaiting_signature', 'تعديل السداد بلا تغيير الشروط الخاصة يبقى بانتظار التوقيع', r.data);
  const aud2 = (await CM.get(`/audit?contractId=${altId}`)).data.find((a) => a.action === 'تعديل بانتظار التوقيع');
  ok(aud2 && aud2.before?.paymentDays === 60 && aud2.after?.paymentDays === 45, 'التدقيق يحفظ القيمة القديمة والجديدة', aud2);
  r = await pm.put(`/contracts/${altId}/actions/edit-pending`, { ...form, specialTerms: 'شرط جديد' });
  ok(r.data.status === 'under_review' && r.data.hasAlert, 'تغيير الشروط الخاصة يرفع تنبيهاً ويعود لمدير العقود', r.data);

  console.log('== رفض ثم عقد جاهز ==');
  r = await doF.post('/contracts', supplyBody({ vendorId: vendor2.id }));
  const rejId = r.data.id;
  await doF.post(`/contracts/${rejId}/actions/send`);
  r = await dmF.post(`/contracts/${rejId}/actions/dept-reject`, { reason: 'الأهداف غير مطابقة' });
  ok(r.data.status === 'draft' && r.data.party === 'creator' && r.data.rejectReason === 'الأهداف غير مطابقة', 'الرفض بسبب → مسودة', r.data);
  ok(!r.data.actions.some((a) => a.key === 'delete'), 'لا حذف بعد الإرسال');

  r = await doF.post('/contracts', { vendorId: vendor.id, entryPath: 'ready', types: ['marketing'], repName: 'علي', repTitle: 'مدير', repMobile: '0555555555', startDate: today, endDate: addDays(today, 120), marketingLines: [{ eventId: event.id, price: 0, note: '' }] });
  ok(r.status === 400 && r.data.errors['marketingLines.0.note'], 'سعر الفعالية 0 يتطلب ملاحظة', r.data);
  r = await doF.post('/contracts', { vendorId: vendor.id, entryPath: 'ready', types: ['marketing'], repName: 'علي', repTitle: 'مدير', repMobile: '0555555555', startDate: today, endDate: addDays(today, 120), marketingLines: [{ eventId: event.id, price: 5000, note: '' }] });
  const readyId = r.data.id;
  r = await doF.post(`/contracts/${readyId}/actions/send`);
  ok(r.status === 400 && r.data.errors.files, 'العقد الجاهز يتطلب رفع الملف الموقّع', r.data);
  await doF.upload(`/contracts/${readyId}/attachments`, { kind: 'ready_signed' });
  r = await doF.post(`/contracts/${readyId}/actions/send`);
  ok(r.data.status === 'under_review' && !r.data.hasAlert, 'العقد الجاهز لا يُنبَّه آلياً', r.data);
  r = await dmF.post(`/contracts/${readyId}/actions/dept-approve`, {});
  ok(r.data.status === 'active', 'مطابقة العقد الجاهز → نشط مباشرة', r.data);

  console.log('== إيجار وفترات كاملة ==');
  const rentBody = (lines, types = ['rent']) => ({ vendorId: vendor.id, entryPath: 'new', types, repName: 'خالد', repTitle: 'مدير', repMobile: '0500000001', startDate: '2026-09-01', endDate: '2026-12-31', rentLines: lines, ...(types.includes('supply') ? { supply: { paymentDays: 30, goalLines: [] } } : {}) });
  r = await doF.post('/contracts', rentBody([{ branchId: branch.id, shelfTypeId: shelf.id, qty: 1, price: 1000, unit: 'month', startDate: '2026-09-01', endDate: '2026-12-31' }]));
  const rentId = r.data.id;
  r = await doF.post(`/contracts/${rentId}/actions/send`);
  ok(r.status === 201, 'إيجار 4 أشهر كاملة صحيح', r.data);
  r = await doF.post('/contracts', rentBody([{ branchId: branch.id, shelfTypeId: shelf.id, qty: 2, price: 1000, unit: 'month', startDate: '2026-09-01', endDate: '2026-12-20' }]));
  const e1 = r.data.errors?.['rentLines.0.endDate'] ?? '';
  let e2 = '';
  if (r.data.id) e2 = (await doF.post(`/contracts/${r.data.id}/actions/send`)).data.errors?.['rentLines.0.endDate'] ?? '';
  ok(/كاملة/.test(e1 + e2), 'فترة غير كاملة مرفوضة', r.data);
  r = await doF.post('/contracts', rentBody([{ branchId: branch.id, shelfTypeId: shelf.id, qty: 2, price: 500, unit: 'week', startDate: '2026-09-01', endDate: '2026-09-28' }]));
  ok((await doF.get(`/contracts/${r.data.id}`)).data.rentLines[0].total === 4000, 'إجمالي السطر = فترات × عدد × سعر (4×2×500)');
  r = await doF.post('/contracts', rentBody([{ branchId: branch.id, shelfTypeId: shelf.id, qty: 1, price: 100, unit: 'month', startDate: '2026-01-15', endDate: '2026-03-14' }]).constructor === Object ? { ...rentBody([{ branchId: branch.id, shelfTypeId: shelf.id, qty: 1, price: 100, unit: 'month', startDate: '2026-01-15', endDate: '2026-03-14' }]), startDate: '2026-01-15', endDate: '2026-03-14' } : {});
  ok((await doF.get(`/contracts/${r.data.id}`)).data.rentLines[0].periods === 2, 'شهران: 15/01 إلى 14/03');

  console.log('== فتح حساب (سري، مستقل) ==');
  r = await doF.post('/contracts', { vendorId: vendor.id, types: ['open_account', 'supply'], repName: 'س', repTitle: 'م', repMobile: '0500000002', startDate: today });
  ok(r.status === 400 && r.data.errors.types, 'فتح الحساب لا يُجمع مع نوع آخر', r.data);
  r = await doF.post('/contracts', { vendorId: vendor2.id, types: ['open_account'], repName: 'سعد', repTitle: 'مدير', repMobile: '0500000003', startDate: today, openAccount: { creditLimit: 50000 } });
  const accId = r.data.id;
  ok(r.data.servicesOnly && r.data.endDate === null, 'عقد خدمات بلا تاريخ نهاية');
  r = await doF.post(`/contracts/${accId}/actions/send`);
  ok(r.status === 400 && r.data.errors.files, 'يتطلب المستندات الخمسة', r.data);
  for (const k of ['account_license', 'account_address', 'account_registration', 'account_tax', 'account_owner_id']) await doF.upload(`/contracts/${accId}/attachments`, { kind: k });
  r = await doF.post(`/contracts/${accId}/actions/send`);
  ok(r.data.status === 'under_review', 'بعد المستندات الخمسة يُرسل');
  await dmF.post(`/contracts/${accId}/actions/dept-approve`, {});
  await pm.upload(`/contracts/${accId}/actions/upload-signed`, {});
  const accDetail = (await dmFood.get(`/contracts/${accId}`)).data;
  ok(accDetail.openAccount.confidential === true && accDetail.openAccount.creditLimit === null && !accDetail.attachments.some((a) => a.kind.startsWith('account_')), 'مدير قسم آخر لا يرى بيانات فتح الحساب', accDetail.openAccount);
  const attId = (await dmF.get(`/contracts/${accId}`)).data.attachments.find((a) => a.kind === 'account_license').id;
  ok((await dmF.get(`/contracts/${accId}/attachments/${attId}`)).status === 200, 'قسم العقد يحمّل المستند');
  ok((await CM.get(`/audit?contractId=${accId}`)).data.some((a) => a.action.includes('اطلاع على مستند فتح حساب')), 'الاطلاع يُسجَّل في التدقيق');
  r = await dmFood.post(`/contracts/${accId}/actions/close-services`, { reason: 'x'.repeat(5) });
  ok(r.status === 403, 'إغلاق الخدمات من خارج القسم ممنوع');
  r = await CM.post(`/contracts/${accId}/actions/close-services`, { reason: 'انتهت الحاجة للحساب' });
  ok(r.data.status === 'ended', 'مدير العقود يغلق عقد الخدمات', r.data);

  console.log('== الملاحق ==');
  r = await doF.post(`/contracts/${draftId}/addenda`, {});
  ok(r.status === 400, 'الملحق يتطلب بيانات');
  const mk = (extra = {}) => ({ effectiveDate: addDays(today, 30), description: 'رفع الخصم', changes: JSON.stringify({ fixedDiscountPct: 2 }), ...extra });
  r = await doF.upload(`/contracts/${draftId}/addenda`, mk({ effectiveDate: addDays(today, 900) }));
  ok(r.status === 400 && r.data.errors.effectiveDate, 'تاريخ السريان خارج مدة العقد مرفوض', r.data);
  r = await doF.upload(`/contracts/${draftId}/addenda`, mk({ changes: JSON.stringify({ fixedDiscountPct: 150 }) }));
  ok(r.status === 400, 'قيمة ملحق غير صالحة مرفوضة', r.data);
  r = await doF.upload(`/contracts/${draftId}/addenda`, mk());
  ok(r.status === 201 && r.data.addenda[0].status === 'pending', 'تسجيل ملحق بانتظار المطابقة', r.data);
  const addId = r.data.addenda[0].id;
  r = await dmF.post(`/contracts/${draftId}/addenda/${addId}/review`, { approve: false });
  ok(r.status === 400, 'سبب الإعادة إلزامي');
  r = await dmF.post(`/contracts/${draftId}/addenda/${addId}/review`, { approve: true });
  ok(r.data.addenda[0].status === 'applied' && r.data.addenda[0].no === 1 && r.data.supply.fixedDiscountPct === 2 && r.data.number === number1, 'مطابقة الملحق تطبّقه وتبقي الرقم', r.data.addenda);
  ok(r.data.addenda[0].changes.before.fixedDiscountPct === 1, 'القيمة السابقة محفوظة');

  console.log('== الأهداف والخصم التقديري ==');
  const vNum = vendor.vendorNumber;
  const imp = await admin.post('/goals/purchases', { records: [
    { vendorNumber: vNum, date: today, purchases: 2400000, returns: 200000 },
    { vendorNumber: vNum, date: addDays(today, 1), purchases: 50000, returns: 50000 },
  ] });
  ok(imp.status === 201 && imp.data.created === 2, 'استيراد المشتريات', imp.data);
  const goals = (await CM.get('/goals')).data;
  const mine = goals.rows.filter((g) => g.contractId === draftId);
  ok(mine.length === 2 && mine.every((g) => g.net === 2200000), 'صافي المشتريات = المشتريات − المرتجعات (2.2M)', mine.map((g) => g.net));
  const applied = mine.filter((g) => g.applied);
  ok(applied.length === 1 && applied[0].discountPct === 5 && applied[0].estimatedDiscount === 110000, 'أعلى شريحة متحققة على كامل الصافي: 2.2M × 5% = 110,000', applied);
  ok((await doF.get('/goals')).status === 403, 'مسؤول القسم لا يرى أداء الأهداف');
  const dash = (await CM.get('/dashboard')).data;
  ok(dash.goals && dash.goals.estimatedDiscount >= 110000 && dash.fixedDiscount >= 1, 'لوحة التحكم تعرض الخصم التقديري وعقود الخصم الثابت');

  console.log('== التشغيل ==');
  const opsView = (await ops.get('/operations')).data;
  ok(Array.isArray(opsView.rent) && Array.isArray(opsView.marketing) && opsView.marketing.length >= 1, 'صفحة التشغيل تعرض الفعاليات النشطة', opsView);
  ok(!JSON.stringify(opsView).includes('paymentDays'), 'لا بنود توريد في صفحة التشغيل');

  console.log('== الإلغاء والإشعارات والطباعة ==');
  await CM.post(`/contracts/${altId}/actions/cm-approve`, {});
  r = await CM.post(`/contracts/${altId}/actions/cancel`, {});
  ok(r.status === 400, 'سبب الإلغاء إلزامي');
  r = await pm.post(`/contracts/${altId}/actions/cancel`, { reason: 'اتفاق متوقف' });
  ok(r.status === 403, 'مدير المشتريات لا يلغي');
  r = await CM.post(`/contracts/${altId}/actions/cancel`, { reason: 'اتفاق متوقف لسبب مكتوب' });
  ok(r.data.status === 'cancelled', 'مدير العقود يلغي العقد المعتمد');
  const bell = (await dmF.get('/notifications')).data;
  ok(bell.unread > 0, 'الجرس يعرض عدد غير المقروء');
  const all = await dmF.post('/notifications/read-all');
  ok(all.data.unread === 0, 'علّم الكل كمقروء');
  const html = (await dmF.req('GET', `/contracts/${draftId}/print`)).data;
  ok(typeof html === 'string' && html.includes('بيان التوقيع') && html.includes(number1), 'صفحة الطباعة تتضمن الرقم وبيان التوقيع');
  ok((await doF.req('GET', `/contracts/${readyId}/print`)).status === 422, 'العقد الجاهز لا يُولَّد');
  const xl = await CM.req('GET', '/contracts/export');
  ok(xl.status === 200, 'تصدير Excel');

  console.log('== القوالب والشروط ==');
  r = await CM.post('/templates/clauses', { title: 'السرية', body: 'يلتزم الطرفان بسرية بنود العقد.', appliesTo: ['supply', 'rent'], sort: 1 });
  ok(r.status === 201 && r.data.version === 1, 'إضافة بند', r.data);
  const clauseId = r.data.id;
  r = await CM.patch(`/templates/clauses/${clauseId}`, { body: 'يلتزم الطرفان بسرية بنود هذا العقد وملحقاته.' });
  ok(r.data.version === 2, 'التعديل ينشئ إصداراً جديداً');
  ok((await CM.get(`/templates/clauses/${clauseId}/versions`)).data.length === 2, 'الإصدار القديم محفوظ');
  ok((await doF.get('/templates/clauses')).status === 403, 'القوالب لمدير العقود فقط');
  const fresh2 = (await doF.post('/contracts', supplyBody({ vendorId: vendor2.id }))).data.id;
  await doF.post(`/contracts/${fresh2}/actions/send`);
  const terms = (await dmF.get(`/contracts/${fresh2}`)).data.terms;
  ok(terms.snapshot && terms.clauses.some((c) => c.title === 'السرية' && c.version === 2), 'العقد يحتفظ بإصدار البند وقت الإرسال', terms);
  await CM.patch(`/templates/clauses/${clauseId}`, { body: 'نص ثالث' });
  ok((await dmF.get(`/contracts/${fresh2}`)).data.terms.clauses.find((c) => c.title === 'السرية').version === 2, 'تعديل البند لاحقاً لا يغيّر العقود القائمة');
  const prev = await CM.req('GET', '/templates/preview?types=supply,rent');
  ok(prev.status === 200 && String(prev.data).includes('مسودة'), 'معاينة عقد نموذجي');

  console.log('== المهام: التصعيد والتذكير والانتهاء ==');
  const runA = await admin.post('/system/run-jobs');
  ok(runA.status === 201, 'تشغيل المهام الدورية', runA.data);

  console.log('== المستخدمون والقوائم ==');
  ok((await doF.get('/users')).status === 403, 'المستخدمون لمسؤول النظام فقط');
  r = await admin.post('/users', { username: 'dm_x', fullName: 'مدير بلا قسم', role: 'department_manager' });
  ok(r.status === 400 && r.data.errors.departmentId, 'القسم إلزامي لمدير القسم', r.data);
  r = await admin.patch('/users/' + admin.user.id, { username: '1', fullName: 'x', role: 'contracts_officer', isActive: true });
  ok(r.status === 400, 'لا يُنزَّل آخر مسؤول نظام', r.data);
  r = await admin.post('/reference/lists/branch', { name: 'فرع جديد' });
  ok(r.status === 201, 'إضافة فرع');
  r = await admin.patch(`/reference/lists/branch/${r.data.id}`, { isActive: false });
  ok(r.data.isActive === false, 'إيقاف قيمة بدل حذفها');
  r = await admin.put('/reference/settings', { reminderDays: [90, 60, 30], escalationDays: 7 });
  ok(r.status === 200, 'تعديل Threshold');
  ok((await CM.get('/audit?action=Threshold')).data.length >= 1, 'تغيير Threshold في التدقيق');
  ok((await CM.get('/audit')).status === 200 && (await doF.get('/audit')).status === 403, 'سجل التدقيق حسب الدور');

  console.log(`\nالنتيجة: ${passes} نجح، ${fails} فشل`);
  process.exit(fails ? 1 : 0);
}

main().catch((e) => {
  console.error('FATAL', e);
  process.exit(2);
});
