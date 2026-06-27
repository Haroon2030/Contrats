<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>حسابي</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">
<link rel="stylesheet" href="<?= ma_e(vc_asset('css/vc-my-account.css')) ?>">
</head>

<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="page-wrap">

    <div class="account-hero">
        <div class="big-avatar"><?= ma_e($firstLetter) ?></div>

        <div>
            <h1 class="hero-title">حسابي</h1>
            <p class="hero-sub">
                إدارة بيانات الحساب وتغيير كلمة المرور الخاصة بك داخل VendorCore.
            </p>
        </div>

        <div class="hero-badge">
            <i class="ri-shield-user-line"></i>
            <?= ma_e($roleText) ?>
        </div>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= ma_e($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= ma_e($error) ?></div>
    <?php endif; ?>

    <div class="grid">

        <div class="card">
            <div class="card-title">
                <i class="ri-user-3-line"></i>
                <span>بيانات الحساب</span>
            </div>

            <div class="info-list">
                <div class="info-row">
                    <div class="info-label">اسم المستخدم</div>
                    <div class="info-value"><?= ma_e($username) ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">نوع الحساب</div>
                    <div class="info-value"><?= ma_e($roleText) ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">الصفحات المتاحة</div>
                    <div class="info-value"><?= (int) $pagesCount ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">آخر تغيير لكلمة المرور</div>
                    <div class="info-value"><?= ma_e($lastPasswordText) ?></div>
                </div>
            </div>

            <div class="actions">
                <a class="btn btn-soft" href="dashboard.php">
                    <i class="ri-dashboard-line"></i>
                    رجوع للداشبورد
                </a>

                <a class="btn btn-soft" href="logout.php">
                    <i class="ri-logout-box-r-line"></i>
                    تسجيل خروج
                </a>
            </div>
        </div>

        <div class="card" id="password-section">
            <div class="card-title">
                <i class="ri-lock-password-line"></i>
                <span>تغيير كلمة المرور</span>
            </div>

            <form method="POST" class="form-grid" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= ma_e($csrf_token) ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="field">
                    <label>كلمة المرور الحالية</label>
                    <input type="password" name="current_password" required>
                </div>

                <div class="field">
                    <label>كلمة المرور الجديدة</label>
                    <input type="password" name="new_password" minlength="6" required>
                </div>

                <div class="field">
                    <label>تأكيد كلمة المرور الجديدة</label>
                    <input type="password" name="confirm_password" minlength="6" required>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="ri-save-3-line"></i>
                        حفظ كلمة المرور
                    </button>
                </div>
            </form>

            <div class="security-note">
                عند تغيير كلمة المرور، يتم تحديث جلسة الحساب الحالية بأمان. أي جلسات قديمة أخرى سيتم خروجها عند فتح أي صفحة محمية.
            </div>
        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.location.hash !== '#password-section') return;
    const section = document.getElementById('password-section');
    if (!section) return;
    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
</script>

</body>
</html>
