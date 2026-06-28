<?php
/**
 * @var string $csrf_token
 * @var bool $is_admin
 * @var bool $show_user_column
 * @var string $search
 * @var string $user_filter
 * @var string $page_msg
 * @var array $summary
 * @var array $users_result
 * @var list<array<string, mixed>> $rows
 * @var int $page
 * @var int $total_pages
 */
$tableColspan = uri_table_colspan($show_user_column);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>الأصناف تحت المراجعة</title>
<?php vcRenderPageAssets(['extra' => ['vc-under-review-items.css']]); ?>
</head>
<body class="under-review-items-page">

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">📦 الأصناف تحت المراجعة</h1>
        <p class="page-subtitle">
            دفعات الأصناف المرسلة للإدارة ولم يُتخذ قرار عليها بعد.
        </p>
    </div>

    <?php if ($page_msg !== ''): ?>
        <div class="alert alert-info"><?= uri_e($page_msg) ?></div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-value"><?= (int) $summary['total_batches'] ?></div>
            <div class="summary-label">دفعات تحت المراجعة</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= (int) $summary['total_items'] ?></div>
            <div class="summary-label">عدد الأصناف</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= uri_e(uri_money($summary['total_fees'])) ?></div>
            <div class="summary-label">إجمالي الرسوم</div>
        </div>
    </div>

    <form class="search-box" method="GET">
        <input
            type="text"
            id="searchInput"
            name="search"
            placeholder="🔍 بحث باسم المورد أو رقم الطلب أو المستخدم..."
            value="<?= uri_e($search) ?>"
        >
        <?php if ($show_user_column): ?>
            <select name="user" id="userFilter">
                <option value="">بواسطة: كل الفريق</option>
                <?php foreach ($users_result as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= ((string) $user_filter === (string) $u['id']) ? 'selected' : '' ?>>
                        <?= uri_e($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </form>

    <div class="table-box">
        <table class="table uri-items-table <?= $show_user_column ? 'table-team' : 'table-own' ?>">
            <thead>
                <tr>
                    <th class="col-batch">رقم الطلب</th>
                    <th class="col-supplier">المورد</th>
                    <?php if ($show_user_column): ?>
                        <th class="col-user">بواسطة</th>
                    <?php endif; ?>
                    <th class="col-count">عدد الأصناف</th>
                    <th class="col-fee">إجمالي الرسوم</th>
                    <th class="col-created">تاريخ الطلب</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-actions">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows !== []): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $batchId = (string) ($row['batch_id'] ?? ''); ?>
                        <tr>
                            <td><span class="batch-id">#<?= uri_e($batchId) ?></span></td>
                            <td class="supplier-name"><?= uri_e($row['supplier_name'] ?? '-') ?></td>
                            <?php if ($show_user_column): ?>
                                <td><span class="user-badge"><?= uri_e($row['created_username'] ?? '-') ?></span></td>
                            <?php endif; ?>
                            <td><?= (int) ($row['items_count'] ?? 0) ?></td>
                            <td><span class="money"><?= uri_e(uri_money($row['total_fees'] ?? 0)) ?></span></td>
                            <td><?= uri_e(uri_format_date($row['created_at'] ?? '')) ?></td>
                            <td><span class="status review">تحت المراجعة</span></td>
                            <td><?php uri_row_actions($row, $csrf_token, $is_admin); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?= $tableColspan ?>" class="empty">لا توجد طلبات أصناف تحت المراجعة</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php vcRenderPagination($page, $total_pages); ?>

</div>

<script src="<?= uri_e(vc_asset('js/under-review-items.js')) ?>?v=1"></script>

</body>
</html>
