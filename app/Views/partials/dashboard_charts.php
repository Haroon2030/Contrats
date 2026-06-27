<?php
/**
 * مخططات لوحة التحكم — دوائر حسب صلاحيات المستخدم
 *
 * @var bool $can_add_contract
 * @var bool $can_add_rent
 * @var bool $can_items_coding
 * @var bool $can_item_entry
 * @var int  $annual_total
 * @var int  $annual_approved
 * @var int  $annual_negotiation
 * @var int  $annual_review
 * @var int  $annual_rejected
 * @var string $annual_donut
 * @var int  $rent_total
 * @var int  $rent_completed
 * @var int  $rent_rejected
 * @var int  $rent_review
 * @var string $rent_donut
 * @var int  $coding_total
 * @var int  $coding_approved
 * @var int  $coding_rejected
 * @var int  $coding_review
 * @var string $coding_donut
 * @var int  $item_total_approved
 * @var int  $item_by_me
 * @var int  $item_others
 * @var string $item_donut
 * @var string $item_entry_label_1
 * @var string $item_entry_label_2
 */

$dashCharts = [];

if (!empty($can_add_contract)) {
    $dashCharts[] = [
        'icon' => 'ri-file-list-3-line',
        'title' => 'العقود السنوية',
        'sub' => 'توزيع العقود حسب الحالة ضمن نطاق صلاحياتك',
        'center_value' => (string) $annual_total,
        'center_label' => 'عقد',
        'donut' => $annual_donut,
        'legend' => [
            ['c' => '#47e6a1', 'name' => 'معتمد', 'value' => $annual_approved],
            ['c' => '#ffd166', 'name' => 'تفاوض', 'value' => $annual_negotiation],
            ['c' => '#6bb7ff', 'name' => 'مراجعة', 'value' => $annual_review],
            ['c' => '#ff6b8a', 'name' => 'مرفوض', 'value' => $annual_rejected],
        ],
    ];
}

if (!empty($can_add_contract) && !empty($can_add_rent)) {
    $dashCharts[] = [
        'icon' => 'ri-building-2-line',
        'title' => 'عقود الإيجار',
        'sub' => 'حالة عقود الإيجار المسجّلة',
        'center_value' => (string) $rent_total,
        'center_label' => 'عقد',
        'donut' => $rent_donut,
        'legend' => [
            ['c' => '#47e6a1', 'name' => 'مكتمل', 'value' => $rent_completed],
            ['c' => '#6bb7ff', 'name' => 'مراجعة', 'value' => $rent_review],
            ['c' => '#ff6b8a', 'name' => 'مرفوض', 'value' => $rent_rejected],
        ],
    ];
}

if (!empty($can_items_coding)) {
    $dashCharts[] = [
        'icon' => 'ri-barcode-box-line',
        'title' => 'طلبات الأصناف',
        'sub' => 'حالة طلبات التكويد والمتابعة',
        'center_value' => (string) $coding_total,
        'center_label' => 'طلب',
        'donut' => $coding_donut,
        'legend' => [
            ['c' => '#47e6a1', 'name' => 'موافق', 'value' => $coding_approved],
            ['c' => '#6bb7ff', 'name' => 'مراجعة', 'value' => $coding_review],
            ['c' => '#ff6b8a', 'name' => 'مرفوض', 'value' => $coding_rejected],
        ],
    ];
}

if (!empty($can_item_entry)) {
    $dashCharts[] = [
        'icon' => 'ri-inbox-archive-line',
        'title' => 'إدخال الأصناف',
        'sub' => 'الأصناف المعتمدة وحالة الإدخال',
        'center_value' => (string) $item_total_approved,
        'center_label' => 'صنف',
        'donut' => $item_donut,
        'legend' => [
            ['c' => '#47e6a1', 'name' => $item_entry_label_1, 'value' => $item_by_me],
            ['c' => '#6bb7ff', 'name' => $item_entry_label_2, 'value' => $item_others],
        ],
    ];
}

if ($dashCharts === []) {
    return;
}
?>
<section class="dash-section dash-charts-section">
    <div class="dash-section-head">
        <h3><i class="ri-pie-chart-2-line"></i> مخططات بيانية</h3>
    </div>
    <div class="admin-donut-grid">
        <?php foreach ($dashCharts as $chart): ?>
            <div class="admin-donut-card">
                <div class="admin-donut-head">
                    <div>
                        <div class="admin-donut-title">
                            <i class="<?= e((string)($chart['icon'] ?? 'ri-pie-chart-line')) ?>"></i>
                            <span><?= e((string)($chart['title'] ?? '')) ?></span>
                        </div>
                        <div class="admin-donut-sub"><?= e((string)($chart['sub'] ?? '')) ?></div>
                    </div>
                </div>
                <div class="admin-donut-content">
                    <div class="admin-donut" style="--segments:<?= e((string)($chart['donut'] ?? '')) ?>">
                        <div class="admin-donut-center">
                            <strong><?= e((string)($chart['center_value'] ?? '0')) ?></strong>
                            <span><?= e((string)($chart['center_label'] ?? '')) ?></span>
                        </div>
                    </div>
                    <div class="admin-legend">
                        <?php foreach ((array)($chart['legend'] ?? []) as $row): ?>
                            <div class="admin-legend-row">
                                <span class="admin-legend-dot" style="--c:<?= e((string)($row['c'] ?? '#6bb7ff')) ?>"></span>
                                <span class="admin-legend-name"><?= e((string)($row['name'] ?? '')) ?></span>
                                <span class="admin-legend-value"><?= (int)($row['value'] ?? 0) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
