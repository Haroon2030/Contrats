<?php
/**
 * محتوى لوحة التحكم — ترحيب، مؤشرات، اختصارات الوحدات
 *
 * @var string $username
 * @var string $displayRole
 * @var string $first_letter
 * @var string $dashboard_today
 * @var array<int, array<string, mixed>> $dashboard_kpis
 * @var array<int, array<string, mixed>> $erp_modules
 */
?>
<div class="dashboard-home">

    <section class="dash-section dash-hero">
        <div class="dash-hero-main">
            <div class="dash-hero-avatar"><?= e(strtoupper($first_letter)) ?></div>
            <div class="dash-hero-text">
                <span class="dash-hero-badge"><?= e($displayRole) ?></span>
                <h2>مرحباً، <?= e($username) ?></h2>
                <p>لوحة تحكم VendorCore — راجع المؤشرات السريعة ووحدات النظام المتاحة لك.</p>
            </div>
        </div>
        <div class="dash-hero-date">
            <i class="ri-calendar-line"></i>
            <span><?= e($dashboard_today) ?></span>
        </div>
    </section>

    <?php if (!empty($dashboard_kpis)): ?>
    <section class="dash-section">
        <div class="dash-section-head">
            <h3><i class="ri-pulse-line"></i> مؤشرات سريعة</h3>
        </div>
        <div class="dash-kpi-grid">
            <?php foreach ($dashboard_kpis as $kpi): ?>
                <?php
                $href = $kpi['href'] ?? null;
                $tone = e((string)($kpi['tone'] ?? 'indigo'));
                $icon = e((string)($kpi['icon'] ?? 'ri-dashboard-line'));
                $value = e((string)($kpi['value'] ?? '0'));
                $label = e((string)($kpi['label'] ?? ''));
                $meta = e((string)($kpi['meta'] ?? ''));
                ?>
                <?php if ($href): ?>
                    <a href="<?= e($href) ?>" class="dash-kpi-card tone-<?= $tone ?>">
                        <div class="dash-kpi-icon"><i class="<?= $icon ?>"></i></div>
                        <div class="dash-kpi-body">
                            <div class="dash-kpi-value"><?= $value ?></div>
                            <div class="dash-kpi-label"><?= $label ?></div>
                            <div class="dash-kpi-meta"><?= $meta ?></div>
                        </div>
                    </a>
                <?php else: ?>
                    <div class="dash-kpi-card tone-<?= $tone ?>">
                        <div class="dash-kpi-icon"><i class="<?= $icon ?>"></i></div>
                        <div class="dash-kpi-body">
                            <div class="dash-kpi-value"><?= $value ?></div>
                            <div class="dash-kpi-label"><?= $label ?></div>
                            <div class="dash-kpi-meta"><?= $meta ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($erp_modules)): ?>
    <section class="dash-section">
        <div class="dash-section-head">
            <h3><i class="ri-apps-2-line"></i> وحدات النظام</h3>
            <span class="dash-section-note">حسب صلاحيات حسابك</span>
        </div>
        <div class="dash-modules-grid">
            <?php foreach ($erp_modules as $module): ?>
                <?php
                $moduleItems = (array)($module['items'] ?? []);
                if ($moduleItems === []) {
                    continue;
                }
                ?>
                <article class="dash-module-card">
                    <header class="dash-module-head">
                        <span class="dash-module-icon"><i class="<?= e((string)($module['icon'] ?? 'ri-folder-line')) ?>"></i></span>
                        <div class="dash-module-title">
                            <strong><?= e((string)($module['title'] ?? '')) ?></strong>
                            <small><?= e((string)($module['subtitle'] ?? '')) ?></small>
                        </div>
                        <span class="dash-module-count"><?= count($moduleItems) ?></span>
                    </header>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

</div>
