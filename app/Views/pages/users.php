<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>إدارة المستخدمين</title>

<?php vcRenderPageAssets(['extra' => ['vc-users.css']]); ?>
<link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container vc-users-page">

    <header class="vc-users-hero">
        <div class="vc-users-hero-text">
            <h1>إدارة المستخدمين</h1>
            <p>عرض الحسابات، البحث والتصفية، ثم إضافة أو تعديل المستخدم وصلاحياته من لوحة جانبية.</p>
        </div>
        <div class="vc-users-hero-actions">
            <button type="button" class="vc-btn-icon primary" onclick="openUserForm(true)">
                <i class="ri-user-add-line"></i>
                <span>إضافة مستخدم</span>
            </button>
        </div>
    </header>

    <?php if(isset($_GET['saved'])): ?>
        <div class="alert alert-success">تم حفظ المستخدم والصلاحيات بنجاح</div>
    <?php endif; ?>

    <?php if(isset($_GET['deactivated'])): ?>
        <div class="alert alert-success">تم تعطيل المستخدم بنجاح</div>
    <?php endif; ?>

    <?php if(isset($_GET['activated'])): ?>
        <div class="alert alert-success">تم تفعيل المستخدم بنجاح</div>
    <?php endif; ?>

    <?php if(isset($_GET['pass_changed'])): ?>
        <div class="alert alert-warning">
            تم تغيير كلمة المرور. سيتم تسجيل خروج المستخدم من أي جلسة مفتوحة عند أول انتقال أو تحديث صفحة.
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="vc-users-kpi-grid">
        <div class="vc-users-kpi tone-indigo">
            <div class="vc-users-kpi-icon"><i class="ri-group-line"></i></div>
            <div class="vc-users-kpi-body"><strong><?= (int)$userStats['total'] ?></strong><span>إجمالي المستخدمين</span></div>
        </div>
        <div class="vc-users-kpi tone-emerald">
            <div class="vc-users-kpi-icon"><i class="ri-user-star-line"></i></div>
            <div class="vc-users-kpi-body"><strong><?= (int)$userStats['active'] ?></strong><span>نشط</span></div>
        </div>
        <div class="vc-users-kpi tone-rose">
            <div class="vc-users-kpi-icon"><i class="ri-user-unfollow-line"></i></div>
            <div class="vc-users-kpi-body"><strong><?= (int)$userStats['inactive'] ?></strong><span>معطّل</span></div>
        </div>
        <div class="vc-users-kpi tone-violet">
            <div class="vc-users-kpi-icon"><i class="ri-shield-user-line"></i></div>
            <div class="vc-users-kpi-body"><strong><?= (int)$userStats['managers'] ?></strong><span>مدراء وإشراف</span></div>
        </div>
    </div>

    <section class="vc-users-list-card">
        <div class="vc-users-list-head">
            <div class="vc-users-list-title">
                <i class="ri-team-line"></i>
                <span>قائمة المستخدمين</span>
            </div>
            <span class="vc-users-list-count"><?= (int)$userStats['total'] ?> حساب</span>
        </div>

        <div class="vc-users-toolbar">
            <div class="vc-users-search-wrap">
                <i class="ri-search-line"></i>
                <input type="search" id="userSearch" placeholder="بحث بالاسم أو الرقم أو المدير..." oninput="filterUsersTable()">
            </div>
            <select id="userRoleFilter" onchange="filterUsersTable()">
                <option value="">كل الأنواع</option>
                <option value="admin">أدمن</option>
                <option value="commercial_manager">مدير تجاري</option>
                <option value="finance_manager">مدير مالي</option>
                <option value="section_manager">مدير قسم</option>
                <option value="accountant">محاسب</option>
                <option value="user">مستخدم</option>
            </select>
            <select id="userStatusFilter" onchange="filterUsersTable()">
                <option value="">كل الحالات</option>
                <option value="1">نشط فقط</option>
                <option value="0">معطّل فقط</option>
            </select>
        </div>

        <div class="vc-users-table-wrap">
        <table class="table" id="usersTable">
            <thead>
                <tr>
                    <th class="col-id">#</th>
                    <th class="col-name">المستخدم</th>
                    <th class="col-role">النوع</th>
                    <th class="col-structure">الهيكل</th>
                    <th class="col-whatsapp">واتساب</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-actions">إجراءات</th>
                </tr>
            </thead>

            <tbody>
                <?php if(!empty($displayUsers)): ?>
                    <?php foreach($displayUsers as $u): ?>
                        <?php
                            $jobRole = usersRoleKey($u);
                            $roleText = usersRoleLabel($jobRole);
                            $roleClass = usersRoleBadgeClass($jobRole);
                            $isActive = (int)($u['is_active'] ?? 1) === 1;
                            $lastChange = !empty($u['last_password_change'])
                                ? date("Y-m-d H:i", strtotime($u['last_password_change']))
                                : '-';
                            $userInitial = function_exists('mb_substr')
                                ? mb_substr((string)$u['username'], 0, 1, 'UTF-8')
                                : substr((string)$u['username'], 0, 1);
                            $searchText = strtolower(trim(
                                (string)$u['username'] . ' ' .
                                (string)($u['whatsapp_number'] ?? '') . ' ' .
                                (string)($userNamesById[(int)($u['manager_id'] ?? 0)] ?? '')
                            ));
                        ?>

                        <tr class="user-row<?= $isActive ? '' : ' is-inactive' ?>"
                            data-role="<?= e($jobRole) ?>"
                            data-active="<?= $isActive ? '1' : '0' ?>"
                            data-search="<?= e($searchText) ?>">
                            <td>#<?= (int)$u['id'] ?></td>

                            <td>
                                <div class="vc-user-cell">
                                    <div class="vc-user-avatar<?= $isActive ? '' : ' is-muted' ?>"><?= e(mb_strtoupper($userInitial, 'UTF-8')) ?></div>
                                    <div class="vc-user-name-block">
                                        <strong><?= e($u['username']) ?></strong>
                                        <div class="vc-user-meta-line">جلسة v<?= (int)($u['session_version'] ?? 1) ?> · آخر مرور: <?= e($lastChange) ?></div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <span class="vc-role-badge <?= e($roleClass) ?>"><?= e($roleText) ?></span>
                            </td>

                            <td>
                                <?php if(!empty($u['manager_id']) && isset($userNamesById[(int)$u['manager_id']])): ?>
                                    <span class="vc-chip"><i class="ri-git-branch-line"></i><?= e($userNamesById[(int)$u['manager_id']]) ?></span>
                                <?php else: ?>
                                    <span class="vc-chip">بدون مدير</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if(!empty($u['whatsapp_number'])): ?>
                                    <span class="vc-chip" title="<?= ((int)($u['whatsapp_enabled'] ?? 1) === 1) ? 'واتساب مفعل' : 'واتساب موقوف' ?>">
                                        <i class="ri-whatsapp-line"></i><?= e($u['whatsapp_number']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="vc-chip">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="vc-status-pill <?= $isActive ? 'active' : 'inactive' ?>">
                                    <?= $isActive ? 'نشط' : 'معطّل' ?>
                                </span>
                            </td>

                            <td>
                                <div class="vc-users-actions">
                                    <button type="button"
                                            class="vc-btn-icon edit"
                                            onclick='editUser(<?= json_encode([
                                                "id" => (int)$u["id"],
                                                "username" => $u["username"],
                                                "account_type" => (string)($u["job_role"] ?? "user"),
                                                "whatsapp_number" => $u["whatsapp_number"] ?? "",
                                                "whatsapp_enabled" => (int)($u["whatsapp_enabled"] ?? 1),
                                                "manager_id" => (int)($u["manager_id"] ?? 0),
                                                "is_supervisor" => (int)($u["is_supervisor"] ?? 0),
                                                "is_active" => (int)($u["is_active"] ?? 1)
                                            ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <i class="ri-edit-line"></i>
                                        <span>تعديل</span>
                                    </button>

                                    <?php if((int)$u['id'] !== $uid): ?>
                                        <?php if($isActive): ?>
                                            <form method="POST" onsubmit="return confirm('تعطيل المستخدم؟ لن يتم حذف عقوده أو نشاطاته، فقط سيتم منعه من الدخول.')">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                                <input type="hidden" name="action" value="deactivate_user">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="vc-btn-icon warn"><i class="ri-user-forbid-line"></i><span>تعطيل</span></button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" onsubmit="return confirm('تفعيل المستخدم مرة أخرى؟')">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                                <input type="hidden" name="action" value="activate_user">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="vc-btn-icon ok"><i class="ri-user-follow-line"></i><span>تفعيل</span></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="empty">لا يوجد مستخدمين</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php vcRenderPagination($page, $totalPages); ?>

        <div class="vc-users-empty-filter" id="usersEmptyFilter">لا توجد نتائج مطابقة للبحث أو التصفية.</div>
    </section>

</div>

<div class="vc-users-drawer-overlay" id="userFormOverlay" onclick="if(event.target===this) closeUserForm()">
    <aside class="vc-users-drawer" id="userFormPanel" role="dialog" aria-modal="true" aria-labelledby="userFormTitle">
        <div class="vc-users-drawer-head">
            <h2 id="userFormTitle"><i class="ri-user-settings-line"></i> <span id="userFormTitleText">إضافة مستخدم</span></h2>
            <button type="button" class="vc-users-drawer-close" onclick="closeUserForm()" aria-label="إغلاق">
                <i class="ri-close-line"></i>
            </button>
        </div>

        <form method="POST" id="userForm" class="vc-users-form vc-users-drawer-body" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
            <input type="hidden" name="action" value="save_user">
            <input type="hidden" name="id" id="id" value="0">

            <input type="hidden" name="want_password_change" id="wantPasswordChange" value="0">

            <div class="form-sections">
            <div class="form-section">
            <h3 class="form-section-title"><i class="ri-id-card-line"></i> البيانات الأساسية</h3>
            <div class="form-grid">
                <div class="input-group">
                    <label for="username">اسم المستخدم</label>
                    <input type="text" name="username" id="username" placeholder="مثال: user1" required>
                </div>

                <div class="input-group">
                    <label for="account_type">نوع المستخدم</label>
                    <select name="account_type" id="account_type" onchange="handleAccountTypeChange(this.value)">
                        <option value="user">مستخدم</option>
                        <option value="section_manager">مدير قسم</option>
                        <option value="commercial_manager">مدير تجاري</option>
                        <option value="finance_manager">مدير مالي</option>
                        <option value="accountant">محاسب</option>
                        <option value="admin">أدمن</option>
                    </select>
                </div>
            </div>

            <div class="form-grid">
                <div class="input-group">
                    <label for="whatsapp_number">رقم واتساب</label>
                    <input type="text" name="whatsapp_number" id="whatsapp_number" placeholder="مثال: 0599050028 أو 966599050028">
                    <small class="field-hint">يمكنك كتابة الرقم بصيغة 05، وسيتم حفظه تلقائيًا بصيغة 966 للإرسال عبر واتساب.</small>
                </div>

                <label class="check-line" style="margin-top:21px;">
                    <input type="checkbox" name="whatsapp_enabled" id="whatsapp_enabled" checked>
                    <span>تفعيل إشعارات واتساب</span>
                </label>
            </div>

            <div class="form-grid">
                <div class="input-group">
                    <label for="manager_id">المدير المباشر</label>
                    <select name="manager_id" id="manager_id">
                        <option value="0">بدون مدير مباشر</option>
                        <?php foreach($managerOptions as $managerUser): ?>
                            <option value="<?= (int)$managerUser['id'] ?>">
                                <?= e($managerUser['username']) ?>
                                <?php
                                    $managerJobRole = (string)($managerUser['job_role'] ?? 'user');
                                    if ($managerJobRole === 'commercial_manager') {
                                        echo ' - مدير تجاري';
                                    } elseif ($managerJobRole === 'finance_manager') {
                                        echo ' - مدير مالي';
                                    } elseif ($managerJobRole === 'section_manager' || (int)($managerUser['is_supervisor'] ?? 0) === 1) {
                                        echo ' - مدير قسم';
                                    } elseif ($managerJobRole === 'admin' || (string)($managerUser['role'] ?? '') === 'admin' || (int)($managerUser['is_admin'] ?? 0) === 1) {
                                        echo ' - أدمن';
                                    }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="field-hint">القائمة تعرض مديرين الأقسام والمدير المالي والمدير التجاري والأدمن فقط.</small>
                </div>
            </div>
            </div>

            <div class="form-section">
            <h3 class="form-section-title"><i class="ri-lock-password-line"></i> كلمة المرور</h3>
            <div class="password-row">
                <label class="check-line">
                    <input type="checkbox" id="changePass" onchange="togglePass()" checked>
                    <span>تغيير كلمة المرور</span>
                </label>

                <div class="input-group" style="margin:0;">
                    <label for="password">كلمة المرور</label>
                    <input type="password" name="password" id="password" placeholder="كلمة المرور الجديدة">
                </div>
            </div>
            </div>

            <div class="form-section" id="permissionsArea">
                <h3 class="form-section-title"><i class="ri-shield-keyhole-line"></i> الصلاحيات والصفحات</h3>
                <div class="permissions-head">
                    <div class="permissions-note" id="rolePermissionNote">اختار الإدارة، أو فعّل الصفحات يدويًا. بعض الصفحات لا تحتاج صلاحيات أخرى.</div>
                </div>

                <div class="perm-tools">
                    <input type="text" id="permissionSearch" placeholder="🔍 ابحث عن صلاحية أو صفحة..." onkeyup="filterPermissionCards()">
                </div>

                <div class="dept-presets">
                    <button type="button" class="dept-preset" onclick="applyDepartment('purchases')">المشتريات</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('marketing')">التسويق</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('operations')">التشغيل</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('data_entry')">إدخال البيانات</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('accounts')">الحسابات</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('reviewers')">المراجعين الموثقين</button>
                    <button type="button" class="dept-preset" onclick="applyDepartment('admin')">الأدمن</button>
                    <button type="button" class="dept-preset clear" onclick="resetPermissions()">مسح الاختيار</button>
                </div>

                <div class="role-note" id="roleNoteBox">
                    نوع الحساب "مستخدم" يعتمد على الصلاحيات المختارة من الكروت.
                </div>

                <div id="permissionsBox" class="permissions-grid">
                    <?php
                        $currentGroup = '';
                        $groupLabels = [
                            'contracts' => 'إدارة ومتابعة العقود',
                            'rents' => 'إدارة ومتابعة الإيجارات',
                            'items' => 'إدخال ومراجعة الأصناف',
                            'finance' => 'الإدارة المالية',
                            'admin' => 'الإدارة',
                            'review' => 'المراجعات والاعتمادات',
                            'data_entry' => 'إدخال البيانات',
                            'purchases' => 'المشتريات',
                            'accounts' => 'الحسابات',
                            'marketing' => 'التسويق',
                            'operations' => 'التشغيل',
                            'general' => 'أخرى'
                        ];
                    ?>

                    <?php foreach($pages as $p): ?>
                        <?php
                            $meta = pagePermissionMeta($p);
                            $isRestricted = (bool)$meta['restricted'];
                            $deptKeys = $meta['departments'];
                            $deptAttr = implode(' ', array_map('deptClass', $deptKeys));
                            $metaGroup = (string)($meta['group'] ?? 'general');
                        ?>

                        <?php if($currentGroup !== $metaGroup): ?>
                            <?php $currentGroup = $metaGroup; ?>
                            <div class="perm-group-title">
                                <?= e($groupLabels[$metaGroup] ?? $metaGroup) ?>
                            </div>
                        <?php endif; ?>

                        <div class="perm-card" data-page="<?= e($p['name'] ?? '') ?>" data-depts="<?= e($deptAttr) ?>" data-search="<?= e($meta['title'] . ' ' . ($p['name'] ?? '') . ' ' . ($p['description'] ?? '')) ?>">
                            <div class="perm-title"><?= e($meta['title']) ?></div>

                            <div class="perm-warning <?= $isRestricted ? '' : 'is-empty' ?>">
                                <?= $isRestricted ? 'صلاحية حساسة — يحددها الأدمن' : '&nbsp;' ?>
                            </div>

                            <div class="dept-tags">
                                <?php foreach($deptKeys as $deptKey): ?>
                                    <span class="dept-tag <?= e(deptClass($deptKey)) ?>">
                                        <?= e(deptLabel($deptKey)) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>

                            <div class="perm-desc"><?= e($p['description'] ?? '') ?></div>

                            <div class="perm-top">
                                <label>
                                    <input type="checkbox" class="perm-check" name="permissions[<?= (int)$p['id'] ?>][view]">
                                    <span>عرض الصفحة</span>
                                </label>
                            </div>

                            <?php
                                $pageNameForScope  = trim((string)($p['name'] ?? ''));
                                $pageTitleForScope = trim((string)($meta['title'] ?? ($p['title'] ?? '')));
                                $scopeHaystack     = mb_strtolower($pageNameForScope . ' ' . $pageTitleForScope, 'UTF-8');

                                /*
                                    كروت لا تحتاج خاص / الكل:
                                    - صفحات الصلاحية الكاملة: سجل العقود / مراجعة الأصناف
                                    - صفحات الأدمن فقط
                                    - صفحات الإضافة / المالية / إدخال الأصناف
                                */
                                $isContractsScopeCard = (
                                    $pageNameForScope === 'contracts' ||
                                    mb_strpos($scopeHaystack, 'سجل العقود') !== false
                                );

                                $isItemsReviewScopeCard = (
                                    $pageNameForScope === 'items_admin' ||
                                    $pageNameForScope === 'review_items' ||
                                    mb_strpos($scopeHaystack, 'مراجعة الأصناف') !== false ||
                                    mb_strpos($scopeHaystack, 'مراجعه الاصناف') !== false ||
                                    mb_strpos($scopeHaystack, 'مراجعة الاصناف') !== false
                                );

                                $isAdminOnlyScopeCard = (
                                    $pageNameForScope === 'users' ||
                                    $pageNameForScope === 'admin_review' ||
                                    mb_strpos($scopeHaystack, 'مراجعة العقود') !== false ||
                                    mb_strpos($scopeHaystack, 'مراجعه العقود') !== false ||
                                    mb_strpos($scopeHaystack, 'إدارة المستخدمين') !== false ||
                                    mb_strpos($scopeHaystack, 'ادارة المستخدمين') !== false
                                );

                                $isFullPageScopeCard = (
                                    in_array($pageNameForScope, ['trusted_reviewers', 'verified_reviewers', 'reviewers'], true) ||
                                    in_array($pageNameForScope, ['system_check', 'contract_ai', 'contracts_ai', 'ai_contracts', 'system_health'], true) ||
                                    mb_strpos($scopeHaystack, 'المراجعين الموثقين') !== false ||
                                    mb_strpos($scopeHaystack, 'ذكاء العقود') !== false ||
                                    mb_strpos($scopeHaystack, 'صحة النظام') !== false ||
                                    mb_strpos($scopeHaystack, 'فحص السيستم') !== false ||
                                    mb_strpos($scopeHaystack, 'فحص النظام') !== false
                                );

                                $hideScopeRow = (
                                    /*
                                        لا نخفي النطاق عن صفحات المتابعة التي تدعم فريقه.
                                        مثال: مراجعة الأصناف items_admin لازم يظهر فيها خاص / فريقه لمدير القسم.
                                    */
                                    $isAdminOnlyScopeCard ||
                                    $isFullPageScopeCard ||

                                    /* صفحات الإضافة */
                                    $pageNameForScope === 'add_contract' ||
                                    $pageNameForScope === 'add_items' ||
                                    $pageNameForScope === 'add_payment_request' ||
                                    $pageNameForScope === 'rents' ||
                                    mb_strpos($scopeHaystack, 'إضافة عقد جديد') !== false ||
                                    mb_strpos($scopeHaystack, 'اضافة عقد جديد') !== false ||
                                    mb_strpos($scopeHaystack, 'إضافة عقد إيجار') !== false ||
                                    mb_strpos($scopeHaystack, 'اضافة عقد ايجار') !== false ||
                                    mb_strpos($scopeHaystack, 'إضافة أصناف جديدة') !== false ||
                                    mb_strpos($scopeHaystack, 'اضافة اصناف جديدة') !== false ||

                                    /* إدخال الأصناف */
                                    $pageNameForScope === 'data_entry_items' ||
                                    mb_strpos($scopeHaystack, 'إدخال الأصناف') !== false ||
                                    mb_strpos($scopeHaystack, 'ادخال الاصناف') !== false ||

                                    /* صفحات مالية ومكتملة */
                                    $pageNameForScope === 'finance' ||
                                    $pageNameForScope === 'finance_items' ||
                                    $pageNameForScope === 'completed_rents' ||
                                    mb_strpos($scopeHaystack, 'متابعة المالية للعقود') !== false ||
                                    mb_strpos($scopeHaystack, 'متابعة المالية للتكويد') !== false ||
                                    mb_strpos($scopeHaystack, 'رسوم الأصناف') !== false ||
                                    mb_strpos($scopeHaystack, 'رسوم الاصناف') !== false ||
                                    mb_strpos($scopeHaystack, 'إيجارات مكتملة') !== false ||
                                    mb_strpos($scopeHaystack, 'ايجارات مكتملة') !== false
                                );
                            ?>

                            <?php if($hideScopeRow): ?>
                                <div class="scope-disabled <?= $isAdminOnlyScopeCard ? 'admin-only' : '' ?>">
                                    <?= ($isContractsScopeCard || $isFullPageScopeCard) ? 'صلاحية كاملة للصفحة' : ($isAdminOnlyScopeCard ? 'أدمن فقط' : 'لا صلاحيات أخرى') ?>
                                </div>
                            <?php else: ?>
                                <div class="scope-row">
                                    <label>
                                        <input type="radio" name="permissions[<?= (int)$p['id'] ?>][scope]" value="own" checked>
                                        <span>خاص</span>
                                    </label>

                                    <label>
                                        <input type="radio" name="permissions[<?= (int)$p['id'] ?>][scope]" value="team">
                                        <span>فريقه</span>
                                    </label>

                                    <label>
                                        <input type="radio" name="permissions[<?= (int)$p['id'] ?>][scope]" value="all">
                                        <span>الكل</span>
                                    </label>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            </div>

        </form>

        <div class="vc-users-drawer-foot">
            <button type="submit" form="userForm" class="vc-btn-icon primary">
                <i class="ri-save-line"></i>
                <span>حفظ المستخدم</span>
            </button>
            <button type="button" class="vc-btn-icon edit" onclick="resetForm()">
                <i class="ri-refresh-line"></i>
                <span>تفريغ</span>
            </button>
        </div>
    </aside>
</div>

<script src="<?= e(vc_asset('js/users.js')) ?>?v=1"></script>

</body>
</html>
