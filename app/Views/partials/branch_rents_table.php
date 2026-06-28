<?php
/**
 * @var list<array<string, mixed>> $tableRows
 * @var array{start: string, end: string}|null $period
 * @var bool $showBranchColumn
 */
$showBranchColumn = $showBranchColumn ?? true;
?>
<?php if ($tableRows === []): ?>
    <div class="br-empty">لا توجد إيجارات مطابقة للفلتر الحالي</div>
<?php else: ?>
    <div class="br-table-wrap">
        <table class="br-table">
            <thead>
                <tr>
                    <th>#</th>
                    <?php if ($showBranchColumn): ?><th>الفرع</th><?php endif; ?>
                    <th>المورد</th>
                    <th>النوع</th>
                    <th>العدد</th>
                    <th>من</th>
                    <th>إلى</th>
                    <th>الإجمالي</th>
                    <th>الحالة</th>
                    <th>العقد</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tableRows as $index => $row): ?>
                    <?php $status = br_rent_status($row, $period); ?>
                    <tr>
                        <td><?= (int) ($index + 1) ?></td>
                        <?php if ($showBranchColumn): ?>
                            <td><span class="br-branch-pill"><?= br_e($row['branch'] ?? 'غير محدد') ?></span></td>
                        <?php endif; ?>
                        <td><?= br_e($row['supplier_name'] ?? '-') ?></td>
                        <td><?= br_e($row['type'] ?? '-') ?></td>
                        <td class="num"><?= br_e((string) ($row['qty'] ?? '-')) ?></td>
                        <td class="num"><?= br_e(br_format_date($row['start_date'] ?? '')) ?></td>
                        <td class="num"><?= br_e(br_format_date($row['end_date'] ?? '')) ?></td>
                        <td class="num"><?= br_e(br_money($row['total'] ?? 0)) ?></td>
                        <td><span class="br-status <?= br_e($status['class']) ?>"><?= br_e($status['label']) ?></span></td>
                        <td>
                            <?php if (!empty($row['contract_ref'])): ?>
                                <a class="br-link" href="view_contract.php?id=<?= (int) $row['contract_ref'] ?>">#<?= (int) $row['contract_ref'] ?></a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
