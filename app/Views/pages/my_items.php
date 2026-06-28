<?php
/**
 * @var string $csrf_token
 * @var bool $is_admin
 * @var bool $can_view_all
 * @var bool $show_user_filter
 * @var string $search
 * @var string $user_filter
 * @var string $bulk_delete_msg
 * @var array $summary
 * @var array $users_result
 * @var list<array<string, mixed>> $rows
 * @var int $page
 * @var int $total_pages
 */
$tableColspan = mi_table_colspan($show_user_filter);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>أصنافي</title>
<?php vcRenderPageAssets(['extra' => ['vc-my-items.css']]); ?>
</head>
<body class="my-items-page <?= $can_view_all ? 'wide-table-mode' : 'normal-table-mode' ?>">

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">📦 أصنافي</h1>
        <p class="page-subtitle">
            متابعة طلبات إضافة الأصناف المعتمدة أو المرفوضة، مع حالة خصم الرسوم من المالية.
        </p>
    </div>

    <?php if ($bulk_delete_msg !== ''): ?>
        <div class="alert alert-info"><?= mi_e($bulk_delete_msg) ?></div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-value"><?= (int) $summary['total_requests'] ?></div>
            <div class="summary-label">عدد طلبات الإضافة</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= (int) $summary['total_approved_items'] ?></div>
            <div class="summary-label">أصناف معتمدة</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= mi_e(mi_money($summary['total_approved_fees'])) ?></div>
            <div class="summary-label">رسوم معتمدة</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= (int) $summary['approved_batches'] ?></div>
            <div class="summary-label">تمت الموافقة</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= (int) $summary['rejected_batches'] ?></div>
            <div class="summary-label">مرفوض</div>
        </div>
    </div>

    <form class="search-box" method="GET">
        <input
            type="text"
            id="searchInput"
            name="search"
            placeholder="🔍 بحث باسم المورد أو رقم الطلب أو المستخدم..."
            value="<?= mi_e($search) ?>"
        >
        <?php if ($show_user_filter): ?>
            <select name="user" id="userFilter" onchange="applyFilters()">
                <option value="">بواسطة: كل الفريق</option>
                <?php foreach ($users_result as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= ((string) $user_filter === (string) $u['id']) ? 'selected' : '' ?>>
                        <?= mi_e($u['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </form>

    <div class="table-box">
        <table class="table my-items-table <?= $can_view_all ? 'table-all' : 'table-own' ?>">
            <thead>
                <tr>
                    <th class="col-batch">رقم الطلب</th>
                    <th class="col-supplier">المورد</th>
                    <?php if ($show_user_filter): ?>
                        <th class="col-creator">الموظف</th>
                    <?php endif; ?>
                    <th class="col-count">أصناف معتمدة</th>
                    <th class="col-fee">رسوم معتمدة</th>
                    <th class="col-created">تاريخ الطلب</th>
                    <th class="col-status">الحالة</th>
                    <th class="col-paid">الخصم</th>
                    <th class="col-deducted">تم الخصم بواسطة</th>
                    <th class="col-actions">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows !== []): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $status = (string) ($row['status'] ?? '');
                        $isApproved = $status === 'approved';
                        $isPaid = !empty($row['paid']);
                        $batchId = (string) ($row['batch_id'] ?? '');
                        ?>
                        <tr>
                            <td><span class="batch-id">#<?= mi_e($batchId) ?></span></td>
                            <td class="supplier-name"><?= mi_e($row['supplier_name'] ?? '-') ?></td>
                            <?php if ($show_user_filter): ?>
                                <td><span class="user-badge"><?= mi_e($row['creator_username'] ?? '-') ?></span></td>
                            <?php endif; ?>
                            <td><?= (int) ($row['approved_items_count'] ?? 0) ?></td>
                            <td><span class="money"><?= mi_e(mi_money($row['approved_total_fees'] ?? 0)) ?></span></td>
                            <td><?= mi_e(mi_format_date($row['created_at'] ?? '')) ?></td>
                            <td>
                                <span class="status <?= mi_e(mi_status_class($status)) ?>">
                                    <?= mi_e(mi_status_text($status)) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isApproved): ?>
                                    <?php if ($isPaid): ?>
                                        <span class="status paid">تم الخصم</span>
                                    <?php else: ?>
                                        <span class="status unpaid">لم يتم الخصم</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status na">غير مطلوب</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isApproved && !empty($row['deducted_username'])): ?>
                                    <span class="user-badge"><?= mi_e($row['deducted_username']) ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php mi_row_actions($row, $csrf_token, $is_admin); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?= $tableColspan ?>" class="empty">لا توجد دفعات أصناف مطابقة</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php vcRenderPagination($page, $total_pages); ?>

</div>

<script src="<?= mi_e(vc_asset('js/my-items.js')) ?>?v=3"></script>

</body>
</html>
