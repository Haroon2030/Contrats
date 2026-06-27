<?php
/** @var array<int, array<string, mixed>> $erp_modules */
$erp_nav_drawer = !empty($erp_nav_drawer);
$erp_nav_current_page = (string) ($erp_nav_current_page ?? basename((string) ($_SERVER['PHP_SELF'] ?? '')));

if (empty($erp_modules)) {
    return;
}

$navCloseAttr = $erp_nav_drawer ? ' onclick="if(typeof closeAppMenu===\'function\'){closeAppMenu();}"' : '';
$homeActive = $erp_nav_current_page === 'dashboard.php' ? ' is-active' : '';
?>
<nav class="erp-nav" id="erpNav" aria-label="تنقل النظام">
    <a href="dashboard.php" class="erp-nav-home<?= $homeActive ?>" title="لوحة التحكم"<?= $navCloseAttr ?>>
        <span class="erp-nav-home-icon" aria-hidden="true"><i class="ri-dashboard-3-line"></i></span>
        <span class="erp-nav-home-text">
            <strong>لوحة التحكم</strong>
            <small>Dashboard</small>
        </span>
    </a>

    <?php foreach ($erp_modules as $module): ?>
        <?php
        $moduleId = (string) ($module['id'] ?? '');
        $items = (array) ($module['items'] ?? []);
        if ($moduleId === '' || $items === []) {
            continue;
        }
        $moduleCollapsed = $erp_nav_drawer ? '' : ' collapsed';
        $moduleExpanded = $erp_nav_drawer ? 'true' : 'false';
        ?>
        <section class="erp-module<?= $moduleCollapsed ?>" data-module="<?= e($moduleId) ?>">
            <button type="button" class="erp-module-head" onclick="toggleErpModule('<?= e($moduleId) ?>')" aria-expanded="<?= $moduleExpanded ?>">
                <span class="erp-module-icon" aria-hidden="true"><i class="<?= e((string) ($module['icon'] ?? 'ri-folder-line')) ?>"></i></span>
                <span class="erp-module-label">
                    <strong><?= e((string) ($module['title'] ?? '')) ?></strong>
                    <small><?= e((string) ($module['subtitle'] ?? '')) ?></small>
                </span>
                <i class="ri-arrow-down-s-line erp-module-chevron"></i>
            </button>

            <div class="erp-module-body" id="erp-module-<?= e($moduleId) ?>">
                <div class="erp-module-list<?= $erp_nav_drawer ? '' : ' erp-sortable-list' ?>" data-section="<?= e($moduleId) ?>">
                    <?php foreach ($items as $row): ?>
                        <?php
                        $pageName = (string) ($row['name'] ?? '');
                        if ($pageName === '') {
                            continue;
                        }
                        $pageHref = $pageName . '.php';
                        $isActive = $erp_nav_current_page === $pageHref;
                        $isModalLink = str_starts_with($pageName, 'add_') || $pageName === 'rents';
                        ?>
                        <a href="<?= e($pageHref) ?>"
                           class="erp-nav-link<?= $isModalLink ? ' is-modal-link' : '' ?><?= $isActive ? ' is-active' : '' ?>"
                           data-id="<?= e($pageName) ?>"
                           title="<?= e((string) ($row['title'] ?? '')) ?>"<?= $navCloseAttr ?>>
                            <span class="erp-nav-link-icon" aria-hidden="true">
                                <i class="<?= e(vcPageRemixIcon($pageName, (string) ($row['title'] ?? ''))) ?>"></i>
                            </span>
                            <span class="erp-nav-link-text"><?= e((string) ($row['title'] ?? '')) ?></span>
                            <?php if (!$erp_nav_drawer): ?>
                                <i class="ri-drag-move-2-line erp-nav-drag" aria-hidden="true"></i>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</nav>
