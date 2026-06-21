<?php
/** @var array<int, array<string, mixed>> $erp_modules */
if (empty($erp_modules)) {
    return;
}
?>
<nav class="erp-nav" id="erpNav" aria-label="تنقل النظام">
    <a href="dashboard.php" class="erp-nav-home" title="لوحة التحكم">
        <span class="erp-nav-home-icon"><i class="ri-dashboard-3-line"></i></span>
        <span class="erp-nav-home-text">
            <strong>لوحة التحكم</strong>
            <small>Dashboard</small>
        </span>
    </a>

    <?php foreach ($erp_modules as $module): ?>
        <?php
        $moduleId = (string)($module['id'] ?? '');
        $items = (array)($module['items'] ?? []);
        if ($moduleId === '' || $items === []) {
            continue;
        }
        ?>
        <section class="erp-module" data-module="<?= e($moduleId) ?>">
            <button type="button" class="erp-module-head" onclick="toggleErpModule('<?= e($moduleId) ?>')" aria-expanded="true">
                <span class="erp-module-icon"><i class="<?= e((string)($module['icon'] ?? 'ri-folder-line')) ?>"></i></span>
                <span class="erp-module-label">
                    <strong><?= e((string)($module['title'] ?? '')) ?></strong>
                    <small><?= e((string)($module['subtitle'] ?? '')) ?></small>
                </span>
                <i class="ri-arrow-down-s-line erp-module-chevron"></i>
            </button>

            <div class="erp-module-body" id="erp-module-<?= e($moduleId) ?>">
                <div class="erp-module-list erp-sortable-list" data-section="<?= e($moduleId) ?>">
                    <?php foreach ($items as $row): ?>
                        <a href="<?= e((string)($row['name'] ?? '')) ?>.php"
                           class="erp-nav-link"
                           data-id="<?= e((string)($row['name'] ?? '')) ?>"
                           title="<?= e((string)($row['title'] ?? '')) ?>">
                            <span class="erp-nav-link-icon">
                                <img src="<?= e(vc_icon($row['icon'] ?? '')) ?>" alt="">
                            </span>
                            <span class="erp-nav-link-text"><?= e((string)($row['title'] ?? '')) ?></span>
                            <i class="ri-drag-move-2-line erp-nav-drag" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</nav>
