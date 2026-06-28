<?php
/**
 * @var list<array<string, mixed>> $tableRows
 * @var array{start: string, end: string}|null $period
 * @var bool $showBranchColumn
 * @var string $sortKey
 * @var string $sortDir
 */
$showBranchColumn = $showBranchColumn ?? true;
$sortKey = $sortKey ?? 'branch';
$sortDir = $sortDir ?? 'asc';
$tableTotals = br_table_totals($tableRows);
?>
<?php if ($tableRows === []): ?>
    <div class="br-empty">لا توجد إيجارات مطابقة للفلتر الحالي</div>
<?php else: ?>
    <div class="br-table-bar">
        <span class="br-table-count"><?= (int) $tableTotals['count'] ?> بند</span>
        <span class="br-table-total">الإجمالي: <strong><?= br_e(br_money($tableTotals['total'])) ?></strong></span>
    </div>

    <div class="br-table-wrap">
        <table class="br-table">
            <thead>
                <tr>
                    <th class="col-index">#</th>
                    <?php if ($showBranchColumn): ?>
                        <th class="col-branch"><?= br_sort_link('branch', 'الفرع', $sortKey, $sortDir) ?></th>
                    <?php endif; ?>
                    <th class="col-supplier"><?= br_sort_link('supplier', 'المورد', $sortKey, $sortDir) ?></th>
                    <th class="col-type"><?= br_sort_link('type', 'النوع', $sortKey, $sortDir) ?></th>
                    <th class="col-qty"><?= br_sort_link('qty', 'عدد', $sortKey, $sortDir) ?></th>
                    <th class="col-period"><?= br_sort_link('start', 'الفترة', $sortKey, $sortDir) ?></th>
                    <th class="col-total"><?= br_sort_link('total', 'المبلغ', $sortKey, $sortDir) ?></th>
                    <th class="col-status"><?= br_sort_link('status', 'الحالة', $sortKey, $sortDir) ?></th>
                    <th class="col-contract">عقد</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tableRows as $index => $row): ?>
                    <?php
                    $status = br_rent_status($row, $period);
                    $supplierName = (string) ($row['supplier_name'] ?? '-');
                    ?>
                    <tr>
                        <td class="col-index"><span class="br-row-num"><?= (int) ($index + 1) ?></span></td>
                        <?php if ($showBranchColumn): ?>
                            <td class="col-branch" title="<?= br_e($row['branch'] ?? 'غير محدد') ?>">
                                <?= br_e($row['branch'] ?? 'غير محدد') ?>
                            </td>
                        <?php endif; ?>
                        <td class="col-supplier" title="<?= br_e($supplierName) ?>"><?= br_e($supplierName) ?></td>
                        <td class="col-type" title="<?= br_e($row['type'] ?? '-') ?>"><?= br_e($row['type'] ?? '-') ?></td>
                        <td class="col-qty num"><?= br_e((string) ($row['qty'] ?? '-')) ?></td>
                        <td class="col-period num">
                            <span class="br-period-stack">
                                <span><?= br_e(br_format_date($row['start_date'] ?? '')) ?></span>
                                <span class="br-period-to"><?= br_e(br_format_date($row['end_date'] ?? '')) ?></span>
                            </span>
                        </td>
                        <td class="col-total num"><span class="br-money"><?= br_e(br_money($row['total'] ?? 0)) ?></span></td>
                        <td class="col-status">
                            <span class="br-status <?= br_e($status['class']) ?>"><?= br_e($status['label']) ?></span>
                        </td>
                        <td class="col-contract">
                            <?php if (!empty($row['contract_ref'])): ?>
                                <a class="br-link" href="view_contract.php?id=<?= (int) $row['contract_ref'] ?>">#<?= (int) $row['contract_ref'] ?></a>
                            <?php else: ?>
                                <span class="br-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="<?= $showBranchColumn ? 4 : 3 ?>" class="br-foot-label">المجموع</td>
                    <td class="col-qty num"><?= (int) $tableTotals['qty'] ?></td>
                    <td></td>
                    <td class="col-total num"><span class="br-money"><?= br_e(br_money($tableTotals['total'])) ?></span></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>
