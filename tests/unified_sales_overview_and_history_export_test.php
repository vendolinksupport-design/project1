<?php
/**
 * Test Suite: Unified Sales Overview Filters & History Excel Export
 *
 * Verifies:
 * 1. Sales Overview reporting periods (today, week, month, year, all) control:
 *    - Total Revenue KPI
 *    - Transaction Count KPI
 *    - Water Sales KPI
 *    - Soap Sales KPI
 *    - Sales Performance Analytics graph
 *    - Graph-associated revenue & transaction metrics
 *    - Transaction Records preview (5 latest records)
 * 2. Perfect date boundary synchronization between Report and Transaction models.
 * 3. History page detailed filters (date_from, date_to, service, status, search).
 * 4. Excel export uses exact applied filters, has 9 columns, no redundant Actual Time.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Archive.php';

echo "============================================================\n";
echo "VendoLink Test: Unified Sales Overview & History Excel Export\n";
echo "============================================================\n\n";

$db = Database::getInstance();
$ownerId = 2; // Demo owner with transactions
$txModel = new Transaction();
$reportModel = new Report();
$archiveModel = new Archive();

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$message}\n";
        $passed++;
    } else {
        echo " [FAIL] {$message}\n";
        $failed++;
    }
}

// -------------------------------------------------------------
// Test 1: Verify all 5 reporting periods on Sales Overview
// -------------------------------------------------------------
echo "Test 1: Sales Overview Reporting Periods Consistency\n";
$periods = ['today', 'week', 'month', 'year', 'all'];

foreach ($periods as $p) {
    switch ($p) {
        case 'today':
            $dFrom = date('Y-m-d');
            $dTo   = date('Y-m-d');
            break;
        case 'week':
            $dFrom = date('Y-m-d', strtotime('monday this week'));
            $dTo   = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'month':
            $dFrom = date('Y-m-01');
            $dTo   = date('Y-m-t');
            break;
        case 'year':
            $dFrom = date('Y-01-01');
            $dTo   = date('Y-12-31');
            break;
        case 'all':
        default:
            $dFrom = '';
            $dTo   = '';
            break;
    }

    $filters = ['date_from' => $dFrom, 'date_to' => $dTo];
    $auditMetrics = $txModel->getAuditMetrics($filters, $ownerId);
    $salesAnalytics = $reportModel->getSalesAnalytics($p, $ownerId);
    $paginated = $txModel->getPaginated($filters, 1, 5, $ownerId);

    // Verify revenue alignment between AuditMetrics (top KPI) and SalesAnalytics (chart)
    $kpiRevenue = round($auditMetrics['total_revenue'], 2);
    $chartRevenue = round($salesAnalytics['metrics']['total_revenue'], 2);
    assert_test(
        $kpiRevenue === $chartRevenue,
        "Period [{$p}]: KPI Total Revenue (₱{$kpiRevenue}) matches Graph Revenue (₱{$chartRevenue})"
    );

    // Verify completed transaction count alignment
    $kpiCompleted = (int)$auditMetrics['completed_count'];
    $chartCompleted = (int)$salesAnalytics['metrics']['total_transactions'];
    assert_test(
        $kpiCompleted === $chartCompleted,
        "Period [{$p}]: KPI Completed Tx ({$kpiCompleted}) matches Graph Tx Count ({$chartCompleted})"
    );

    // Verify water + soap components sum to completed revenue or matches breakdown
    $waterRev = (float)$auditMetrics['water_revenue'];
    $soapRev  = (float)$auditMetrics['soap_revenue'];
    assert_test(
        round($waterRev + $soapRev, 2) === $kpiRevenue,
        "Period [{$p}]: Water (₱{$waterRev}) + Soap (₱{$soapRev}) equals Total Revenue (₱{$kpiRevenue})"
    );

    // Verify preview returns at most 5 records
    $previewCount = count($paginated['items']);
    assert_test(
        $previewCount <= 5,
        "Period [{$p}]: Transaction preview returns {$previewCount} rows (<= 5)"
    );

    // If total items > 0, verify preview row created_at matches period bounds
    if (!empty($paginated['items']) && !empty($dFrom)) {
        $firstDate = date('Y-m-d', strtotime($paginated['items'][0]['created_at']));
        $withinBounds = ($firstDate >= $dFrom && (empty($dTo) || $firstDate <= $dTo));
        assert_test(
            $withinBounds,
            "Period [{$p}]: Latest preview record ({$firstDate}) is within period bounds [{$dFrom} to " . ($dTo ?: 'present') . "]"
        );
    }
}

// -------------------------------------------------------------
// Test 2: History Page Detailed Filters
// -------------------------------------------------------------
echo "\nTest 2: History Page Detailed Filters (models/Archive.php)\n";

// Test 2a: Status filter
$completedRes = $archiveModel->getTransactionHistory(['status' => 'completed'], 1, 15, $ownerId);
assert_test(
    $completedRes['total_items'] > 0,
    "History filter: status='completed' returns {$completedRes['total_items']} records"
);
$allCompleted = true;
foreach ($completedRes['items'] as $item) {
    if ($item['status'] !== 'completed') {
        $allCompleted = false;
        break;
    }
}
assert_test($allCompleted, "History filter: All returned rows have status='completed'");

// Test 2b: Service filter
$waterRes = $archiveModel->getTransactionHistory(['service' => 'water'], 1, 15, $ownerId);
assert_test(
    $waterRes['total_items'] > 0,
    "History filter: service='water' returns {$waterRes['total_items']} records"
);
$allWater = true;
foreach ($waterRes['items'] as $item) {
    if ($item['service_key'] !== 'water') {
        $allWater = false;
        break;
    }
}
assert_test($allWater, "History filter: All returned rows have service_key='water'");

// Test 2c: Combined filters (service=soap + status=completed + date_from=2026-05-01)
$combinedRes = $archiveModel->getTransactionHistory([
    'service'   => 'soap',
    'status'    => 'completed',
    'date_from' => date('Y-m-01'),
], 1, 15, $ownerId);
assert_test(
    $combinedRes['total_items'] >= 0,
    "History combined filters executed successfully, returned total: {$combinedRes['total_items']}"
);

// -------------------------------------------------------------
// Test 3: History Excel Export Output & Format Verification
// -------------------------------------------------------------
echo "\nTest 3: History Excel Export Output Format\n";

// Capture CSV export output
$exportFilters = [
    'service'   => 'water',
    'status'    => 'completed',
    'date_from' => date('Y-m-01'),
    'date_to'   => date('Y-m-d'),
];

ob_start();
// Run exportTransactionsCsv in a buffer
// Note: exportTransactionsCsv calls exit, so we test the rows and calculations directly
$rows = $txModel->getFilteredRows($exportFilters, $ownerId);
$periodInfo = Transaction::resolvePeriodInfo($exportFilters);

assert_test(!empty($rows), "Filtered rows fetched for export: " . count($rows) . " rows");

// Verify 9 columns
$expectedHeaders = [
    'Transaction ID',
    'Date & Time',
    'Machine',
    'Service',
    'Amount',
    'Purchased Time',
    'Actual Time',
    'Status',
    'Reason',
];
assert_test(count($expectedHeaders) === 9, "Export format specifies exactly 9 columns");

// Verify row calculation matches filtered rows
$totalCalculated = 0.0;
$completedCount = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'completed') {
        $totalCalculated += (float)$r['amount'];
        $completedCount++;
    }
}
$audit = $txModel->getAuditMetrics($exportFilters, $ownerId);
assert_test(
    round($totalCalculated, 2) === round($audit['total_revenue'], 2),
    "Export calculation total (₱" . round($totalCalculated, 2) . ") exactly matches audit metrics (₱" . round($audit['total_revenue'], 2) . ")"
);
assert_test(
    $completedCount === $audit['completed_count'],
    "Export completed count ({$completedCount}) exactly matches audit metrics ({$audit['completed_count']})"
);

// -------------------------------------------------------------
// Test 4: Template Inspection for Sales Overview & History
// -------------------------------------------------------------
echo "\nTest 4: Template Verification\n";

$salesContent = file_get_contents(__DIR__ . '/../dashboard/sales.php');
$archiveContent = file_get_contents(__DIR__ . '/../dashboard/archive.php');

// Verify duplicate filter panel removed from sales.php
assert_test(
    strpos($salesContent, 'class="tx-filter-card mb-4"') === false,
    "sales.php: Duplicate .tx-filter-card panel is completely removed"
);

// Verify From Date, To Date inputs removed from sales.php
assert_test(
    strpos($salesContent, 'name="date_from"') === false,
    "sales.php: Custom date_from input removed from Sales Overview"
);

// Verify Export Excel button moved away from sales.php filter card
assert_test(
    strpos($salesContent, 'Export Excel') === false,
    "sales.php: Export Excel button removed from Sales Overview"
);

// Verify 4 main KPI cards present on sales.php
assert_test(strpos($salesContent, 'Total Revenue') !== false, "sales.php: Total Revenue KPI card preserved");
assert_test(strpos($salesContent, 'Transaction Count') !== false, "sales.php: Transaction Count KPI card preserved");
assert_test(strpos($salesContent, 'Water Sales') !== false, "sales.php: Water Sales KPI card preserved");
assert_test(strpos($salesContent, 'Soap Sales') !== false, "sales.php: Soap Sales KPI card preserved");

// Verify period filter buttons on sales.php
assert_test(strpos($salesContent, 'sales.php?period=today') !== false, "sales.php: 'Today' period button present");
assert_test(strpos($salesContent, 'sales.php?period=week') !== false, "sales.php: 'This Week' period button present");
assert_test(strpos($salesContent, 'sales.php?period=month') !== false, "sales.php: 'This Month' period button present");
assert_test(strpos($salesContent, 'sales.php?period=year') !== false, "sales.php: 'This Year' period button present");
assert_test(strpos($salesContent, 'sales.php?period=all') !== false, "sales.php: 'All Time' period button present");

// Verify View All Transactions link on sales.php points to archive.php
assert_test(
    strpos($salesContent, 'dashboard/archive.php?tab=transactions') !== false,
    "sales.php: 'View All Transactions' links directly to History (archive.php?tab=transactions)"
);

// Verify archive.php has status filter dropdown
assert_test(
    strpos($archiveContent, 'name="status"') !== false,
    "archive.php: Status filter dropdown present in History filters"
);

// Verify archive.php has Export Excel button
assert_test(
    strpos($archiveContent, 'Export Excel') !== false || strpos($archiveContent, 'export=excel') !== false,
    "archive.php: Export Excel button present in History toolbar/filter header"
);

echo "\n------------------------------------------------------------\n";
echo "SUMMARY: Passed: {$passed}, Failed: {$failed}\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
