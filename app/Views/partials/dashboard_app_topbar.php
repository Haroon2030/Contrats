<?php
/**
 * شريط علوي للوحة التحكم — طي القائمة، إشعارات، كلمة المرور، تسجيل الخروج
 *
 * @var bool  $isFinanceDashboard
 * @var int   $dashNotifications
 * @var array $dashNotifRows
 */
?>
<header class="app-topbar" id="appTopbar">
    <div class="app-topbar-start">
        <button type="button" class="app-topbar-btn app-topbar-toggle" onclick="toggleSidebar()" title="طي / فتح القائمة" aria-label="طي القائمة الجانبية">
            <i class="ri-menu-fold-fill" id="sidebarToggleIcon" aria-hidden="true"></i>
        </button>

        <div class="app-topbar-heading">
            <div class="app-topbar-title">
                <i class="ri-building-4-line"></i>
                <?php if ($isFinanceDashboard): ?>
                    <span>لوحة المدير المالي</span>
                <?php else: ?>
                    <span>نظام إدارة العقود والإيجارات</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="app-topbar-actions">
        <div class="app-topbar-notif-wrap">
            <button type="button" class="app-topbar-btn app-topbar-notif" onclick="toggleDashNotif(event)" title="الإشعارات" aria-label="الإشعارات">
                <i class="ri-notification-3-line"></i>
                <span class="app-topbar-badge" id="dashNotifCount" style="<?= $dashNotifications > 0 ? '' : 'display:none;' ?>">
                    <?= (int) $dashNotifications ?>
                </span>
            </button>

            <div class="dash-notif-box" id="dashNotifBox">
                <div class="dash-notif-title">
                    <span class="dash-notif-title-main"><i class="ri-notification-3-line"></i> الإشعارات</span>
                    <span class="dash-notif-refresh" id="dashNotifRefreshText">تحديث تلقائي</span>
                </div>
                <div class="dash-notif-list" id="dashNotifList">
                    <?= vcDashRenderNotificationsHtml($dashNotifRows) ?>
                </div>
                <div class="dash-notif-empty-filter" id="dashNotifEmptyFilter">لا توجد إشعارات في هذا التصنيف</div>
            </div>
        </div>

        <div class="app-topbar-account-wrap">
            <button type="button" class="app-topbar-btn app-topbar-account" onclick="toggleTopbarAccount(event)" title="الحساب" aria-label="قائمة الحساب" aria-expanded="false" aria-controls="topbarAccountMenu">
                <i class="ri-user-settings-line" aria-hidden="true"></i>
            </button>

            <div class="app-topbar-account-menu" id="topbarAccountMenu">
                <a href="my_account.php#password-section">
                    <i class="ri-lock-password-line"></i>
                    <span>تغيير كلمة المرور</span>
                </a>
                <a href="logout.php" class="is-logout">
                    <i class="ri-logout-box-r-line"></i>
                    <span>تسجيل خروج</span>
                </a>
            </div>
        </div>
    </div>
</header>
