<?php
/**
 * @var string $csrf_token
 * @var bool $is_admin
 * @var string $search
 * @var string $entry_filter
 * @var int $totalRequests
 * @var int $doneCount
 * @var int $pendingCount
 * @var list<array<string, mixed>> $rows
 * @var int $page
 * @var int $totalPages
 */
$showSuccess = isset($_GET['done']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إدخال الأصناف</title>
<?php vcRenderPageAssets(); ?>
</head>
<body>

<?php include VC_VIEWS . '/layouts/header.php'; ?>

<div class="container">

    <div class="page-head">
        <h1 class="page-title">⌨️ إدخال الأصناف</h1>
        <p class="page-subtitle">
            متابعة طلبات إضافة الأصناف المعتمدة وتسجيل من قام بإدخالها في النظام.
        </p>
    </div>

    <?php if ($showSuccess): ?>
        <div class="alert alert-success">تم تسجيل إدخال الأصناف بنجاح ✅</div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-value"><?= (int) $totalRequests ?></div>
            <div class="summary-label">طلبات معتمدة</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= (int) $doneCount ?></div>
            <div class="summary-label">تم الإدخال</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= (int) $pendingCount ?></div>
            <div class="summary-label">لم يتم الإدخال</div>
        </div>
    </div>

    <form class="filters" method="GET">
        <input
            type="text"
            id="searchInput"
            name="search"
            placeholder="🔍 بحث باسم المورد أو رقم الطلب أو اسم الموظف..."
            value="<?= dei_e($search) ?>"
        >
        <select name="entry" id="entryFilter">
            <option value="" <?= $entry_filter === '' ? 'selected' : '' ?>>كل الحالات</option>
            <option value="pending" <?= $entry_filter === 'pending' ? 'selected' : '' ?>>لم يتم الإدخال</option>
            <option value="done" <?= $entry_filter === 'done' ? 'selected' : '' ?>>تم الإدخال</option>
        </select>
    </form>

    <div class="table-box">
        <table class="table">
            <thead>
                <tr>
                    <th class="col-batch">رقم الطلب</th>
                    <th class="col-supplier">المورد</th>
                    <th class="col-creator">الموظف</th>
                    <th class="col-date">تاريخ الموافقة</th>
                    <th class="col-entry">حالة الإدخال</th>
                    <th class="col-entered-at">تاريخ الإدخال</th>
                    <th class="col-actions">إجراءات</th>
                    <th class="col-action">إجراء</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows !== []): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $isDone = !empty($row['entry_done']);
                        $approvedAt = dei_format_date($row['approved_at'] ?? '', $row['created_at'] ?? '');
                        $enteredAt = dei_format_date($row['entered_at'] ?? '');
                        $batchId = (string) ($row['batch_id'] ?? '');
                        ?>
                        <tr>
                            <td><span class="batch-id">#<?= dei_e($batchId) ?></span></td>
                            <td class="supplier-name"><?= dei_e($row['supplier_name'] ?? '-') ?></td>
                            <td><span class="user-badge"><?= dei_e($row['creator_username'] ?? '-') ?></span></td>
                            <td><?= dei_e($approvedAt) ?></td>
                            <td>
                                <?php if ($isDone): ?>
                                    <span class="status done">تم الإدخال</span>
                                <?php else: ?>
                                    <span class="status pending">لم يتم الإدخال</span>
                                <?php endif; ?>
                            </td>
                            <td><?= dei_e($enteredAt) ?></td>
                            <td>
                                <?php
                                vcRenderRowActions([
                                    'view' => [
                                        'href' => 'view_items.php?batch=' . urlencode($batchId),
                                    ],
                                    'edit' => [
                                        'href' => 'add_items.php?edit_batch=' . urlencode($batchId),
                                    ],
                                    'delete' => [
                                        'action' => 'delete_items_batch',
                                        'fields' => ['batch_id' => $batchId],
                                        'confirm' => 'تأكيد حذف دفعة الأصناف رقم ' . $batchId . '؟',
                                    ],
                                ], $csrf_token, $is_admin);
                                ?>
                            </td>
                            <td class="actions">
                                <?php if (!$isDone): ?>
                                    <form method="POST" onsubmit="return confirm('تأكيد أن هذه الأصناف تم إدخالها؟')">
                                        <input type="hidden" name="csrf_token" value="<?= dei_e($csrf_token) ?>">
                                        <input type="hidden" name="action" value="mark_entered">
                                        <input type="hidden" name="batch_id" value="<?= dei_e($batchId) ?>">
                                        <button type="submit" class="btn btn-done">تم الإدخال</button>
                                    </form>
                                <?php else: ?>
                                    <span class="done-text">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty">لا توجد طلبات أصناف معتمدة مطابقة</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php vcRenderPagination($page, $totalPages); ?>

</div>

<script>
let timer;
const searchInput = document.getElementById('searchInput');
const entryFilter = document.getElementById('entryFilter');

function applyFilters() {
    const url = new URL(window.location.href);
    const search = searchInput ? searchInput.value : '';
    const entry = entryFilter ? entryFilter.value : '';

    if (search) {
        url.searchParams.set('search', search);
    } else {
        url.searchParams.delete('search');
    }

    if (entry) {
        url.searchParams.set('entry', entry);
    } else {
        url.searchParams.delete('entry');
    }

    url.searchParams.delete('pg');
    window.location.href = url.toString();
}

if (searchInput) {
    searchInput.addEventListener('keyup', function () {
        clearTimeout(timer);
        timer = setTimeout(applyFilters, 450);
    });
}

if (entryFilter) {
    entryFilter.addEventListener('change', applyFilters);
}
</script>

</body>
</html>
