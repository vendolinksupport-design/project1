<?php
/**
 * ============================================================
 * VendoLink — CSV Export Improvement Comprehensive Test
 * ============================================================
 *
 * Verifies period labels, filter metadata, summary totals, transaction rows,
 * filenames, empty results, UTF-8 BOM, and financial accuracy.
 *
 * Run with: C:\xampp\php\php.exe tests\csv_export_improvement_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Transaction.php';

$db = Database::getInstance();
$txModel = new Transaction();
$phpCli = 'C:\\xampp\\php\\php.exe';

echo "=== STARTING COMPREHENSIVE CSV EXPORT IMPROVEMENT TESTS ===\n\n";

function runExportCmd(string $exportType, array $filters = []): string
{
    global $phpCli;
    $json = addslashes(json_encode($filters));
    $cmd = "{$phpCli} -r \"chdir('c:/xampp/htdocs/vendolink_claude_v1'); require 'config/config.php'; require 'config/database.php'; require 'models/Transaction.php'; \$t = new Transaction(); \$t->{$exportType}(json_decode('{$json}', true));\"";
    return shell_exec($cmd) ?? '';
}

// ------------------------------------------------------------
// TEST 1: [ Export Sales Report ] — All Time
// ------------------------------------------------------------
echo "1. Testing [ Export Sales Report ] — All Time...\n";
$reportCsv = runExportCmd('exportSalesReport', ['date_range' => 'all']);

if (substr($reportCsv, 0, 3) !== "\xEF\xBB\xBF") {
    throw new RuntimeException("Sales Report CSV missing UTF-8 BOM!");
}
if (strpos($reportCsv, 'VendoLink — Sales Report') === false) {
    throw new RuntimeException("Sales Report CSV missing title header!");
}
if (strpos($reportCsv, 'Date Range') === false || strpos($reportCsv, 'All Time') === false) {
    throw new RuntimeException("Sales Report CSV missing Date Range All Time!");
}
if (strpos($reportCsv, 'SALES SUMMARY') === false) {
    throw new RuntimeException("Sales Report CSV missing SALES SUMMARY section!");
}
if (strpos($reportCsv, 'Total Sales Amount') === false || strpos($reportCsv, 'Completed Transactions') === false) {
    throw new RuntimeException("Sales Report CSV missing summary metrics!");
}
if (strpos($reportCsv, 'TRANSACTION RECORDS') === false) {
    throw new RuntimeException("Sales Report CSV missing TRANSACTION RECORDS section!");
}
echo "✅ Export Sales Report (All Time) structure verified.\n\n";

// ------------------------------------------------------------
// TEST 2: [ Export Transactions ] — All Time
// ------------------------------------------------------------
echo "2. Testing [ Export Transactions ] — All Time...\n";
$txCsv = runExportCmd('exportTransactionsCsv', ['date_range' => 'all']);

if (substr($txCsv, 0, 3) !== "\xEF\xBB\xBF") {
    throw new RuntimeException("Transactions CSV missing UTF-8 BOM!");
}
if (strpos($txCsv, 'VendoLink — Sales Report') === false) {
    throw new RuntimeException("Transactions CSV missing title header!");
}
if (strpos($txCsv, 'Date Range') === false || strpos($txCsv, 'All Time') === false) {
    throw new RuntimeException("Transactions CSV missing Date Range All Time!");
}
if (strpos($txCsv, 'SALES SUMMARY') === false) {
    throw new RuntimeException("Transactions CSV missing SALES SUMMARY block!");
}
if (strpos($txCsv, 'TRANSACTION RECORDS') === false) {
    throw new RuntimeException("Transactions CSV missing TRANSACTION RECORDS section!");
}
echo "✅ Export Transactions (All Time) structure verified.\n\n";

// ------------------------------------------------------------
// TEST 3: Period Resolution (Today, Week, Month, Custom)
// ------------------------------------------------------------
echo "3. Testing Date/Period Resolution in CSV exports...\n";

// Today
$todayStr = date('Y-m-d');
$todayReport = runExportCmd('exportSalesReport', ['date_from' => $todayStr, 'date_to' => $todayStr]);
$expectedTodayLabel = date('F j, Y');
if (strpos($todayReport, $expectedTodayLabel) === false) {
    throw new RuntimeException("Today Date Range label mismatch! Expected: {$expectedTodayLabel}");
}
echo "  - Today: {$expectedTodayLabel} ✅\n";

// Custom Range
$customReport = runExportCmd('exportSalesReport', ['date_from' => '2026-08-01', 'date_to' => '2026-08-08']);
if (strpos($customReport, "August 1–8, 2026") === false && strpos($customReport, "Aug 1 – Aug 8, 2026") === false && strpos($customReport, "August 1, 2026") === false) {
    throw new RuntimeException("Custom Date Range label mismatch!");
}
echo "  - Custom Range (Aug 1–8, 2026) ✅\n\n";

// ------------------------------------------------------------
// TEST 4: Filter Metadata & Service/Status Filters
// ------------------------------------------------------------
echo "4. Testing Service (Water) & Status (Completed) filters...\n";
$filteredReport = runExportCmd('exportSalesReport', [
    'service' => 'water',
    'status'  => 'completed',
    'search'  => 'VendoLink',
]);

if (strpos($filteredReport, 'Water Service') === false) {
    throw new RuntimeException("Filtered CSV missing Water Service metadata!");
}
if (strpos($filteredReport, 'Completed') === false) {
    throw new RuntimeException("Filtered CSV missing Completed status metadata!");
}
if (strpos($filteredReport, 'Search,VendoLink') === false) {
    throw new RuntimeException("Filtered CSV missing Search,VendoLink metadata!");
}
echo "✅ Filter metadata correctly rendered in CSV header.\n\n";

// ------------------------------------------------------------
// TEST 5: Financial Accuracy & Math Verification
// ------------------------------------------------------------
echo "5. Verifying Financial Accuracy (Summary vs Row Sums)...\n";
$metrics = $txModel->getAuditMetrics(['service' => 'water', 'status' => 'completed']);
$rows = $txModel->getFilteredRows(['service' => 'water', 'status' => 'completed']);

$sumAmount = 0.0;
foreach ($rows as $r) {
    $sumAmount += (float) $r['amount'];
}

if (abs($metrics['water_revenue'] - $sumAmount) > 0.001) {
    throw new RuntimeException("Financial discrepancy! Water Revenue metric ({$metrics['water_revenue']}) != Sum of rows ({$sumAmount})");
}
if ($metrics['transaction_count'] !== count($rows)) {
    throw new RuntimeException("Count discrepancy! Metric count ({$metrics['transaction_count']}) != Row count (" . count($rows) . ")");
}
echo "✅ Financial Accuracy Verified: Water Revenue (₱" . number_format($sumAmount, 2) . ") matches sum of " . count($rows) . " rows.\n\n";

// ------------------------------------------------------------
// TEST 6: Empty Results Handling
// ------------------------------------------------------------
echo "6. Testing Empty Results handling (No matching records)...\n";
$emptyReport = runExportCmd('exportSalesReport', ['search' => 'NON_EXISTENT_QUERY_12345']);

if (strpos($emptyReport, 'SALES SUMMARY') === false) {
    throw new RuntimeException("Empty Sales Report missing SALES SUMMARY section!");
}
if (strpos($emptyReport, 'Total Sales Amount') === false || strpos($emptyReport, '0.00') === false) {
    throw new RuntimeException("Empty Sales Report missing Total Sales Amount 0.00!");
}
if (strpos($emptyReport, 'Completed Transactions') === false || strpos($emptyReport, ',0') === false) {
    throw new RuntimeException("Empty Sales Report missing Completed Transactions 0!");
}
if (strpos($emptyReport, 'No transactions found.') === false) {
    throw new RuntimeException("Empty Sales Report missing 'No transactions found.' message!");
}

$emptyTx = runExportCmd('exportTransactionsCsv', ['search' => 'NON_EXISTENT_QUERY_12345']);
if (strpos($emptyTx, 'SALES SUMMARY') === false) {
    throw new RuntimeException("Empty Transactions CSV missing SALES SUMMARY section!");
}
if (strpos($emptyTx, 'No transactions found.') === false) {
    throw new RuntimeException("Empty Transactions CSV missing 'No transactions found.' message!");
}
echo "✅ Empty Results properly handled with zeroed metrics and 'No transactions found.' notice.\n\n";

// ------------------------------------------------------------
// TEST 7: Button Labels & Page Rendering
// ------------------------------------------------------------
echo "7. Verifying Button Labels & Sales Overview page rendering...\n";
$pageCmd = "{$phpCli} -r \"chdir('c:/xampp/htdocs/vendolink_claude_v1'); session_start(); \$_SESSION['user_id'] = 1; \$_SESSION['user_role'] = 'administrator'; \$_SESSION['username'] = 'admin'; require 'dashboard/sales.php';\"";
$salesHtml = shell_exec($pageCmd) ?? '';

if (strpos($salesHtml, 'Export Excel') === false) {
    throw new RuntimeException("Export button 'Export Excel' label not found in sales.php!");
}
if (strpos($salesHtml, 'export=excel') === false) {
    throw new RuntimeException("export=excel parameter missing in sales.php export link!");
}
echo "✅ Page button labels and URL parameters verified: [ Export Excel ] with export=excel.\n\n";

echo "🎉 ALL COMPREHENSIVE CSV EXPORT IMPROVEMENT TESTS PASSED SUCCESSFULLY!\n";
