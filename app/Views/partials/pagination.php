<?php

/** @var int $pagination_page */
/** @var int $pagination_total_pages */
/** @var string $pagination_param */

if (($pagination_total_pages ?? 1) <= 1) {
    return;
}

$param = $pagination_param ?? 'pg';
$current = (int) ($pagination_page ?? 1);
$total = (int) $pagination_total_pages;

if (!function_exists('e') && function_exists('htmlspecialchars')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
?>
<nav class="vc-pagination" aria-label="ترقيم الصفحات">
    <div class="vc-pagination-info">
        صفحة <?= (int) $current ?> من <?= (int) $total ?>
    </div>
    <div class="vc-pagination-links">
        <?php if ($current > 1): ?>
            <a class="vc-page-link" href="<?= e(vcPaginationLink($current - 1, $param)) ?>">السابق</a>
        <?php endif; ?>

        <?php
        $start = max(1, $current - 2);
        $end = min($total, $current + 2);
        if ($start > 1) {
            echo '<a class="vc-page-link" href="' . e(vcPaginationLink(1, $param)) . '">1</a>';
            if ($start > 2) {
                echo '<span class="vc-page-ellipsis">…</span>';
            }
        }
        for ($i = $start; $i <= $end; $i++) {
            $active = $i === $current ? ' is-active' : '';
            echo '<a class="vc-page-link' . $active . '" href="' . e(vcPaginationLink($i, $param)) . '">' . (int) $i . '</a>';
        }
        if ($end < $total) {
            if ($end < $total - 1) {
                echo '<span class="vc-page-ellipsis">…</span>';
            }
            echo '<a class="vc-page-link" href="' . e(vcPaginationLink($total, $param)) . '">' . (int) $total . '</a>';
        }
        ?>

        <?php if ($current < $total): ?>
            <a class="vc-page-link" href="<?= e(vcPaginationLink($current + 1, $param)) ?>">التالي</a>
        <?php endif; ?>
    </div>
</nav>
