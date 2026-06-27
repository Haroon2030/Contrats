<?php
/**
 * لوحة التحكم — كاردات المؤشرات فقط
 *
 * @var array<int, array<string, mixed>> $dashboard_kpis
 */
?>
<div class="dashboard-board">
    <?php if (!empty($dashboard_kpis)): ?>
    <section class="dash-section dash-kpi-section">
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
