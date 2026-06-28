<?php
/**
 * @var string $selectedMonthName
 * @var string $month
 * @var string $view
 * @var string $statusFilter
 * @var string $searchQuery
 * @var string $selectedBranchKey
 * @var string $selectedBranchName
 * @var array $months
 * @var array $monthCounts
 * @var array $stats
 * @var list<array<string, mixed>> $displayRows
 * @var list<array{key: string, name: string, count: int, total: float}> $branchOptions
 * @var array{start: string, end: string}|null $period
 * @var string $currentMonth
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إيجارات الفروع المكتملة</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon/fonts/remixicon.css" rel="stylesheet">
<link rel="stylesheet" href="<?= br_e(vc_asset('css/vc-branch-rents.css')) ?>?v=2">
</head>
<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="br-page">

    <div class="br-page-head">
        <h1 class="br-page-title">إيجارات الفروع المكتملة</h1>
        <p class="br-page-subtitle">
            عرض منظم لإيجارات العقود المعتمدة حسب الشهر والفرع، مع جدول تفصيلي للبنود المكتملة والنشطة.
        </p>
    </div>

    <div class="br-summary">
        <div class="br-summary-card">
            <div class="br-summary-label">الشهر المعروض</div>
            <div class="br-summary-value br-summary-value-sm"><?= br_e($selectedMonthName) ?></div>
        </div>
        <div class="br-summary-card">
            <div class="br-summary-label"><?= $view === 'branches' && $selectedBranchName !== '' ? 'الفرع المحدد' : 'عدد الفروع' ?></div>
            <div class="br-summary-value br-summary-value-sm">
                <?= $view === 'branches' && $selectedBranchName !== '' ? br_e($selectedBranchName) : (int) $stats['branches'] ?>
            </div>
        </div>
        <div class="br-summary-card">
            <div class="br-summary-label">عدد البنود</div>
            <div class="br-summary-value"><?= (int) $stats['rents'] ?></div>
        </div>
        <div class="br-summary-card">
            <div class="br-summary-label">إجمالي المبالغ</div>
            <div class="br-summary-value br-summary-value-sm"><?= br_e(br_money($stats['total_amount'])) ?></div>
        </div>
    </div>

    <div class="br-panel">
        <div class="br-panel-title">فلترة الشهور</div>
        <div class="br-months">
            <a href="<?= br_e(br_query_string(['month' => 'all'])) ?>" class="all <?= $month === 'all' ? 'active' : '' ?>">
                الكل
                <span class="br-month-count"><?= (int) array_sum($monthCounts) ?></span>
            </a>

            <?php foreach ($months as $num => $name): ?>
                <?php
                $class = br_month_class($num, $currentMonth);
                $active = ($month === $num) ? 'active' : '';
                $count = $monthCounts[(int) $num] ?? 0;
                ?>
                <a href="<?= br_e(br_query_string(['month' => $num])) ?>" class="<?= br_e(trim($class . ' ' . $active)) ?>">
                    <?= br_e($name) ?>
                    <span class="br-month-count"><?= (int) $count ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="br-panel">
        <form method="get" class="br-toolbar">
            <input type="hidden" name="month" value="<?= br_e($month) ?>">
            <input type="hidden" name="view" value="<?= br_e($view) ?>">
            <?php if ($selectedBranchKey !== ''): ?>
                <input type="hidden" name="branch" value="<?= br_e($selectedBranchKey) ?>">
            <?php endif; ?>

            <div class="br-tabs">
                <a href="<?= br_e(br_query_string(['view' => 'table', 'branch' => null])) ?>" class="br-tab <?= $view === 'table' ? 'is-active' : '' ?>">
                    <i class="ri-table-line"></i> جدول الكل
                </a>
                <a href="<?= br_e(br_query_string(['view' => 'branches'])) ?>" class="br-tab <?= $view === 'branches' ? 'is-active' : '' ?>">
                    <i class="ri-building-2-line"></i> حسب الفرع
                </a>
            </div>

            <div class="br-filters">
                <select name="status" class="br-select" onchange="this.form.submit()">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>كل الحالات</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>مكتمل فقط</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>نشط</option>
                    <option value="carry" <?= $statusFilter === 'carry' ? 'selected' : '' ?>>مرحّل</option>
                    <option value="upcoming" <?= $statusFilter === 'upcoming' ? 'selected' : '' ?>>قادم</option>
                </select>

                <input
                    type="search"
                    name="q"
                    class="br-input"
                    value="<?= br_e($searchQuery) ?>"
                    placeholder="بحث: مورد، نوع..."
                >

                <button type="submit" class="br-tab is-active br-search-btn">
                    <i class="ri-search-line"></i> بحث
                </button>
            </div>
        </form>

        <?php if ($view === 'branches'): ?>
            <div class="br-branch-picker">
                <div class="br-branch-picker-head">
                    <div class="br-panel-title" style="margin-bottom:0;">اختر الفرع</div>
                    <?php if ($selectedBranchName !== ''): ?>
                        <a href="<?= br_e(br_query_string(['branch' => null])) ?>" class="br-clear-branch">
                            <i class="ri-close-circle-line"></i> إلغاء التحديد
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($branchOptions === []): ?>
                    <div class="br-empty">لا توجد فروع في الفترة المحددة</div>
                <?php else: ?>
                    <div class="br-branch-grid">
                        <?php foreach ($branchOptions as $option): ?>
                            <a
                                href="<?= br_e(br_query_string(['branch' => $option['key']])) ?>"
                                class="br-branch-chip <?= $selectedBranchKey === $option['key'] ? 'is-active' : '' ?>"
                            >
                                <span class="br-branch-chip-name"><?= br_e($option['name']) ?></span>
                                <span class="br-branch-chip-meta">
                                    <?= (int) $option['count'] ?> بند · <?= br_e(br_money($option['total'])) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($selectedBranchName === ''): ?>
                <div class="br-empty br-empty-hint">
                    <i class="ri-building-2-line"></i>
                    اختر فرعاً من القائمة أعلاه لعرض إيجاراته في الجدول
                </div>
            <?php else: ?>
                <div class="br-selected-branch-title">
                    <i class="ri-map-pin-line"></i>
                    إيجارات فرع: <strong><?= br_e($selectedBranchName) ?></strong>
                </div>
                <?php
                $tableRows = $displayRows;
                $showBranchColumn = false;
                include VC_VIEWS . '/partials/branch_rents_table.php';
                ?>
            <?php endif; ?>

        <?php else: ?>
            <?php
            $tableRows = $displayRows;
            $showBranchColumn = true;
            include VC_VIEWS . '/partials/branch_rents_table.php';
            ?>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
