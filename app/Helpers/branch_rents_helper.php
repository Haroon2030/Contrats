<?php

if (!function_exists('br_e')) {
    function br_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('br_months')) {
    function br_months(): array
    {
        return [
            '01' => 'يناير',
            '02' => 'فبراير',
            '03' => 'مارس',
            '04' => 'أبريل',
            '05' => 'مايو',
            '06' => 'يونيو',
            '07' => 'يوليو',
            '08' => 'أغسطس',
            '09' => 'سبتمبر',
            '10' => 'أكتوبر',
            '11' => 'نوفمبر',
            '12' => 'ديسمبر',
        ];
    }
}

if (!function_exists('br_money')) {
    function br_money($value): string
    {
        return number_format((float) $value, 2);
    }
}

if (!function_exists('br_format_date')) {
    function br_format_date($value): string
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '0000-00-00') {
            return '-';
        }

        $ts = strtotime($value);

        return $ts ? date('Y-m-d', $ts) : '-';
    }
}

if (!function_exists('br_month_class')) {
    function br_month_class(string $num, string $currentMonth): string
    {
        if ($num < $currentMonth) {
            return 'past';
        }

        if ($num === $currentMonth) {
            return 'current';
        }

        return 'future';
    }
}

if (!function_exists('br_period_for_month')) {
    /** @return array{start: string, end: string}|null */
    function br_period_for_month(string $month, string $year): ?array
    {
        if ($month === 'all') {
            return null;
        }

        $start = $year . '-' . $month . '-01';

        return [
            'start' => $start,
            'end' => date('Y-m-t', strtotime($start)),
        ];
    }
}

if (!function_exists('br_load_month_counts')) {
    function br_load_month_counts(VcDb $conn, string $year, array $months): array
    {
        $counts = [];
        $stmt = $conn->prepare('
            SELECT COUNT(*) AS total
            FROM rents r
            LEFT JOIN contracts c ON c.id = r.contract_id
            WHERE c.status = \'approved\'
            AND r.start_date <= ?
            AND r.end_date >= ?
        ');

        if (!$stmt) {
            return $counts;
        }

        foreach ($months as $num => $name) {
            $periodStart = $year . '-' . $num . '-01';
            $periodEnd = date('Y-m-t', strtotime($periodStart));
            $stmt->bind_param('ss', $periodEnd, $periodStart);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $counts[(int) $num] = (int) ($row['total'] ?? 0);
        }

        $stmt->close();

        return $counts;
    }
}

if (!function_exists('br_load_rents')) {
    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   branch_data: array<string, list<array<string, mixed>>>,
     *   period: array{start: string, end: string}|null
     * }
     */
    function br_load_rents(VcDb $conn, string $month, string $year): array
    {
        $period = br_period_for_month($month, $year);

        if ($period !== null) {
            $stmt = $conn->prepare('
                SELECT r.*, c.supplier_name, c.id AS contract_ref
                FROM rents r
                LEFT JOIN contracts c ON c.id = r.contract_id
                WHERE c.status = \'approved\'
                AND r.start_date <= ?
                AND r.end_date >= ?
                ORDER BY r.branch ASC, r.start_date ASC, r.id ASC
            ');
            $stmt->bind_param('ss', $period['end'], $period['start']);
        } else {
            $stmt = $conn->prepare('
                SELECT r.*, c.supplier_name, c.id AS contract_ref
                FROM rents r
                LEFT JOIN contracts c ON c.id = r.contract_id
                WHERE c.status = \'approved\'
                ORDER BY r.branch ASC, r.start_date ASC, r.id ASC
            ');
        }

        $rows = [];
        $branchData = [];

        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
                $branch = trim((string) ($row['branch'] ?? ''));

                if ($branch === '') {
                    $branch = 'غير محدد';
                }

                $branchData[$branch][] = $row;
            }

            $stmt->close();
        }

        return [
            'rows' => $rows,
            'branch_data' => $branchData,
            'period' => $period,
        ];
    }
}

if (!function_exists('br_rent_status')) {
    /**
     * @return array{key: string, label: string, class: string}
     */
    function br_rent_status(array $row, ?array $period): array
    {
        $today = date('Y-m-d');
        $start = trim((string) ($row['start_date'] ?? ''));
        $end = trim((string) ($row['end_date'] ?? ''));

        if ($period !== null && $start !== '' && $start < $period['start']) {
            return ['key' => 'carry', 'label' => 'مرحّل', 'class' => 'is-carry'];
        }

        if ($end !== '' && $end !== '0000-00-00' && $end < $today) {
            return ['key' => 'completed', 'label' => 'مكتمل', 'class' => 'is-completed'];
        }

        if ($start !== '' && $start > $today) {
            return ['key' => 'upcoming', 'label' => 'قادم', 'class' => 'is-upcoming'];
        }

        return ['key' => 'active', 'label' => 'نشط', 'class' => 'is-active'];
    }
}

if (!function_exists('br_filter_rows')) {
  /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    function br_filter_rows(array $rows, string $statusFilter, string $branchQuery, ?array $period): array
    {
        $branchQuery = trim(mb_strtolower($branchQuery, 'UTF-8'));

        return array_values(array_filter($rows, static function (array $row) use ($statusFilter, $branchQuery, $period): bool {
            $status = br_rent_status($row, $period);

            if ($statusFilter !== 'all' && $status['key'] !== $statusFilter) {
                return false;
            }

            if ($branchQuery === '') {
                return true;
            }

            $branch = mb_strtolower(trim((string) ($row['branch'] ?? 'غير محدد')), 'UTF-8');
            $supplier = mb_strtolower(trim((string) ($row['supplier_name'] ?? '')), 'UTF-8');
            $type = mb_strtolower(trim((string) ($row['type'] ?? '')), 'UTF-8');

            return str_contains($branch, $branchQuery)
                || str_contains($supplier, $branchQuery)
                || str_contains($type, $branchQuery);
        }));
    }
}

if (!function_exists('br_build_stats')) {
    /**
     * @param list<array<string, mixed>> $rows
     * @return array{branches: int, rents: int, completed: int, total_amount: float}
     */
    function br_build_stats(array $rows, ?array $period): array
    {
        $branches = [];
        $completed = 0;
        $totalAmount = 0.0;

        foreach ($rows as $row) {
            $branch = trim((string) ($row['branch'] ?? 'غير محدد'));

            if ($branch === '') {
                $branch = 'غير محدد';
            }

            $branches[$branch] = true;
            $totalAmount += (float) ($row['total'] ?? 0);

            if (br_rent_status($row, $period)['key'] === 'completed') {
                $completed++;
            }
        }

        return [
            'branches' => count($branches),
            'rents' => count($rows),
            'completed' => $completed,
            'total_amount' => $totalAmount,
        ];
    }
}

if (!function_exists('br_normalize_branch')) {
    function br_normalize_branch($value): string
    {
        $branch = trim((string) $value);

        return $branch === '' ? 'غير محدد' : $branch;
    }
}

if (!function_exists('br_branch_key')) {
    function br_branch_key(string $branch): string
    {
        return md5(mb_strtolower(br_normalize_branch($branch), 'UTF-8'));
    }
}

if (!function_exists('br_filter_by_branch')) {
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    function br_filter_by_branch(array $rows, string $selectedBranch): array
    {
        if ($selectedBranch === '') {
            return $rows;
        }

        $selectedKey = br_branch_key($selectedBranch);

        return array_values(array_filter($rows, static function (array $row) use ($selectedKey): bool {
            return br_branch_key((string) ($row['branch'] ?? '')) === $selectedKey;
        }));
    }
}

if (!function_exists('br_build_branch_options')) {
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{key: string, name: string, count: int, total: float}>
     */
    function br_build_branch_options(array $rows): array
    {
        $options = [];

        foreach ($rows as $row) {
            $name = br_normalize_branch($row['branch'] ?? '');
            $key = br_branch_key($name);

            if (!isset($options[$key])) {
                $options[$key] = [
                    'key' => $key,
                    'name' => $name,
                    'count' => 0,
                    'total' => 0.0,
                ];
            }

            $options[$key]['count']++;
            $options[$key]['total'] += (float) ($row['total'] ?? 0);
        }

        usort($options, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return array_values($options);
    }
}

if (!function_exists('br_resolve_branch_name')) {
    function br_resolve_branch_name(string $branchKey, array $branchOptions): string
    {
        if ($branchKey === '') {
            return '';
        }

        foreach ($branchOptions as $option) {
            if (($option['key'] ?? '') === $branchKey) {
                return (string) ($option['name'] ?? '');
            }
        }

        return '';
    }
}

if (!function_exists('br_query_string')) {
    function br_query_string(array $overrides = []): string
    {
        $params = array_merge($_GET, $overrides);

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                unset($params[$key]);
            }
        }

        unset($params['page']);

        return $params === [] ? '?' : '?' . http_build_query($params);
    }
}

if (!function_exists('br_sort_rows')) {
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    function br_sort_rows(array $rows, string $sortKey, string $sortDir, ?array $period): array
    {
        $allowed = ['branch', 'supplier', 'type', 'qty', 'start', 'total', 'status'];
        if (!in_array($sortKey, $allowed, true)) {
            $sortKey = 'branch';
        }

        $sortDir = $sortDir === 'desc' ? 'desc' : 'asc';
        $multiplier = $sortDir === 'desc' ? -1 : 1;

        usort($rows, static function (array $a, array $b) use ($sortKey, $multiplier, $period): int {
            $cmp = match ($sortKey) {
                'supplier' => strcmp((string) ($a['supplier_name'] ?? ''), (string) ($b['supplier_name'] ?? '')),
                'type' => strcmp((string) ($a['type'] ?? ''), (string) ($b['type'] ?? '')),
                'qty' => ((float) ($a['qty'] ?? 0)) <=> ((float) ($b['qty'] ?? 0)),
                'start' => strcmp((string) ($a['start_date'] ?? ''), (string) ($b['start_date'] ?? '')),
                'total' => ((float) ($a['total'] ?? 0)) <=> ((float) ($b['total'] ?? 0)),
                'status' => strcmp(
                    br_rent_status($a, $period)['key'],
                    br_rent_status($b, $period)['key']
                ),
                default => strcmp(
                    br_normalize_branch((string) ($a['branch'] ?? '')),
                    br_normalize_branch((string) ($b['branch'] ?? ''))
                ),
            };

            if ($cmp === 0) {
                $cmp = strcmp((string) ($a['start_date'] ?? ''), (string) ($b['start_date'] ?? ''));
            }

            if ($cmp === 0) {
                $cmp = ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }

            return $cmp * $multiplier;
        });

        return $rows;
    }
}

if (!function_exists('br_sort_link')) {
    function br_sort_link(string $column, string $label, string $currentSort, string $currentDir): string
    {
        $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
        $href = br_query_string(['sort' => $column, 'dir' => $nextDir]);
        $isActive = $currentSort === $column;
        $iconClass = $isActive
            ? ($currentDir === 'asc' ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line')
            : 'ri-expand-up-down-line';

        return '<a href="' . br_e($href) . '" class="br-th-sort' . ($isActive ? ' is-active' : '') . '">'
            . '<span>' . br_e($label) . '</span>'
            . '<i class="' . br_e($iconClass) . '" aria-hidden="true"></i>'
            . '</a>';
    }
}

if (!function_exists('br_table_totals')) {
    /**
     * @param list<array<string, mixed>> $rows
     * @return array{count: int, qty: float, total: float}
     */
    function br_table_totals(array $rows): array
    {
        $qty = 0.0;
        $total = 0.0;

        foreach ($rows as $row) {
            $qty += (float) ($row['qty'] ?? 0);
            $total += (float) ($row['total'] ?? 0);
        }

        return [
            'count' => count($rows),
            'qty' => $qty,
            'total' => $total,
        ];
    }
}
