<?php
/*
    branch_rents.php — إيجارات الفروع المكتملة
    - حماية auth.php
    - منطق العرض في branch_rents_helper.php
    - الواجهة في Views/pages/branch_rents.php
*/

require_once VC_HELPERS . '/auth.php';
require_once VC_HELPERS . '/branch_rents_helper.php';

date_default_timezone_set('Asia/Riyadh');

$months = br_months();
$currentMonth = date('m');
$currentYear = date('Y');

$month = (string) ($_GET['month'] ?? 'all');
if ($month !== 'all' && !array_key_exists($month, $months)) {
    $month = 'all';
}

$view = (string) ($_GET['view'] ?? 'table');
if (!in_array($view, ['table', 'branches'], true)) {
    $view = 'table';
}

$statusFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'completed', 'active', 'carry', 'upcoming'], true)) {
    $statusFilter = 'all';
}

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$selectedBranchKey = trim((string) ($_GET['branch'] ?? ''));

$sortKey = (string) ($_GET['sort'] ?? 'branch');
$sortDir = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

$monthCounts = br_load_month_counts($conn, $currentYear, $months);
$rentData = br_load_rents($conn, $month, $currentYear);
$rows = $rentData['rows'];
$period = $rentData['period'];

$filteredRows = br_filter_rows($rows, $statusFilter, $searchQuery, $period);
$branchOptions = br_build_branch_options($filteredRows);
$selectedBranchName = br_resolve_branch_name($selectedBranchKey, $branchOptions);

if ($selectedBranchKey !== '' && $selectedBranchName === '') {
    $selectedBranchKey = '';
}

$displayRows = $view === 'branches'
    ? br_filter_by_branch($filteredRows, $selectedBranchName)
    : $filteredRows;

if ($view === 'branches' && $selectedBranchName === '') {
    $stats = [
        'branches' => count($branchOptions),
        'rents' => 0,
        'completed' => 0,
        'total_amount' => 0.0,
    ];
} else {
    $stats = br_build_stats($displayRows, $period);
}

$displayRows = br_sort_rows($displayRows, $sortKey, $sortDir, $period);

$selectedMonthName = ($month === 'all') ? 'كل الشهور' : ($months[$month] ?? 'كل الشهور');

require VC_VIEWS . '/pages/branch_rents.php';
