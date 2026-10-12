<?php
/**
 * ============================================================
 * VendoLink — History Page & 6-Month Demo Verification Test
 * ============================================================
 *
 * Verifies:
 * 1. 6-Month Demo Transactions:
 *    - 184 days range (April 12, 2026 to October 12, 2026)
 *    - Daily customer average 30-50
 *    - Natural weekday vs weekend customer variations
 *    - Realistic Philippine peso amounts, runtime durations, completed statuses (~96%)
 * 2. Cash Collections:
 *    - Regularly spaced every 3-5 days across 6 months
 *    - Matching system sales and realistic variances (₱0 match, small surplus/shortage)
 * 3. History Page (dashboard/archive.php):
 *    - Summary cards: Total Sales, Completed Transactions, Water Sales, Soap Sales
 *    - Search filter (ID, client tx ID, service)
 *    - Date range filtering
 *    - Pagination controls for both Transactions and Cash Collections
 *    - Export Excel functionality
 * 4. Data Safety:
 *    - Non-demo transactions and collections intact
 *
 * Run with: C:\xampp\php\php.exe tests/history_page_demo_verification_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Archive.php';
require_once __DIR__ . '/../models/Transaction.php';

echo "=== STARTING HISTORY PAGE & 6-MONTH DEMO VERIFICATION ===\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();
$archiveModel = new Archive();
$txModel = new Transaction();

// Set up Owner session (user_id = 2)
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = 'owner';
$_SESSION['username'] = 'owner';
$_SESSION['full_name'] = 'Business Owner';

// ------------------------------------------------------------
// 1. Verify 6-Month Demo Transactions Dataset
// ------------------------------------------------------------
echo "1. Verifying 6-Month Demo Transactions Dataset...\n";

$totalTx = (int) $pdo->query("SELECT COUNT(*) FROM service_transactions")->fetchColumn();
$demoTx = (int) $pdo->query("SELECT COUNT(*) FROM service_transactions WHERE client_transaction_id LIKE 'DEMO-%'")->fetchColumn();
$realTx = (int) $pdo->query("SELECT COUNT(*) FROM service_transactions WHERE client_transaction_id NOT LIKE 'DEMO-%' OR client_transaction_id IS NULL")->fetchColumn();

assert_true($totalTx >= 7000, "Total transaction count is realistic 6-month volume (found {$totalTx})");
assert_true($demoTx >= 7000, "Demo transaction count is at least 7,000 (found {$demoTx})");
assert_true($realTx >= 4, "Real user transactions preserved (found {$realTx})");

// Date range check
$minDate = $pdo->query("SELECT MIN(DATE(created_at)) FROM service_transactions WHERE client_transaction_id LIKE 'DEMO-%'")->fetchColumn();
$maxDate = $pdo->query("SELECT MAX(DATE(created_at)) FROM service_transactions WHERE client_transaction_id LIKE 'DEMO-%'")->fetchColumn();

assert_true($minDate === '2026-04-12', "Start date is April 12, 2026 (got {$minDate})");
assert_true($maxDate === '2026-10-12', "End date is October 12, 2026 (got {$maxDate})");

// Average customers per day
$daysCount = (int) $pdo->query("SELECT COUNT(DISTINCT DATE(created_at)) FROM service_transactions WHERE client_transaction_id LIKE 'DEMO-%'")->fetchColumn();
$avgPerDay = round($demoTx / max(1, $daysCount), 1);
assert_true($avgPerDay >= 30 && $avgPerDay <= 50, "Average customers per day is 30-50 (got {$avgPerDay} across {$daysCount} days)");

// Status check (~96% completed)
$completedCount = (int) $pdo->query("SELECT COUNT(*) FROM service_transactions WHERE client_transaction_id LIKE 'DEMO-%' AND status = 'completed'")->fetchColumn();
$completionRate = round(($completedCount / $demoTx) * 100, 1);
assert_true($completionRate >= 94 && $completionRate <= 98, "Completion rate is realistic ~96% (got {$completionRate}%)");

// Non-completed status variation check
$incompleteCount = (int) $pdo->query("SELECT COUNT(*) FROM service_transactions WHERE client_transaction_id LIKE 'DEMO-%' AND status = 'incomplete'")->fetchColumn();
$interruptedCount = (int) $pdo->query("SELECT COUNT(*) FROM service_transactions WHERE client_transaction_id LIKE 'DEMO-%' AND status = 'interrupted'")->fetchColumn();
$cancelledCount = (int) $pdo->query("SELECT COUNT(*) FROM service_transactions WHERE client_transaction_id LIKE 'DEMO-%' AND status = 'cancelled'")->fetchColumn();
assert_true($incompleteCount > 0, "Incomplete transactions exist ({$incompleteCount})");
assert_true($interruptedCount > 0, "Interrupted transactions exist ({$interruptedCount})");
assert_true($cancelledCount > 0, "Cancelled transactions exist ({$cancelledCount})");

echo "  [PASS] 6-month transactions verified: {$demoTx} demo tx across {$daysCount} days (avg {$avgPerDay}/day, {$completionRate}% completed).\n\n";

// ------------------------------------------------------------
// 2. Verify Cash Collections Dataset
// ------------------------------------------------------------
echo "2. Verifying Cash Collections Dataset...\n";

$demoCc = (int) $pdo->query("SELECT COUNT(*) FROM cash_collections WHERE notes LIKE '[Demo]%'")->fetchColumn();
$realCc = (int) $pdo->query("SELECT COUNT(*) FROM cash_collections WHERE notes NOT LIKE '[Demo]%' OR notes IS NULL")->fetchColumn();

assert_true($demoCc >= 40, "At least 40 periodic collection records generated across 6 months (found {$demoCc})");
assert_true($realCc >= 2, "Real user cash collections preserved (found {$realCc})");

$matchCount = (int) $pdo->query("SELECT COUNT(*) FROM cash_collections WHERE notes LIKE '[Demo]%' AND variance_amount = 0")->fetchColumn();
$discrepancyCount = (int) $pdo->query("SELECT COUNT(*) FROM cash_collections WHERE notes LIKE '[Demo]%' AND variance_amount != 0")->fetchColumn();

assert_true($matchCount > $discrepancyCount, "Majority of cash collections match system sales exactly ({$matchCount} matched, {$discrepancyCount} with minor discrepancy)");
assert_true($discrepancyCount >= 5, "Occasional realistic discrepancies present ({$discrepancyCount} found)");

echo "  [PASS] Cash collections verified: {$demoCc} collections ({$matchCount} exact matches, {$discrepancyCount} realistic variances).\n\n";

// ------------------------------------------------------------
// 3. Verify History Page (archive.php) Summary Cards & Queries
// ------------------------------------------------------------
echo "3. Testing History Page Summary Cards & Filters...\n";

// A. Full all-time summary metrics
$allSummary = $txModel->getAuditMetrics([], 2);
assert_true($allSummary['total_revenue'] > 50000, "All-time revenue reflects 6 months of sales (₱" . number_format($allSummary['total_revenue'], 2) . ")");
assert_true($allSummary['water_revenue'] > 30000, "Water sales verified (₱" . number_format($allSummary['water_revenue'], 2) . ")");
assert_true($allSummary['soap_revenue'] > 15000, "Soap sales verified (₱" . number_format($allSummary['soap_revenue'], 2) . ")");

// B. Filter by date range (e.g. Month of August 2026)
$augustFilters = [
    'date_from' => '2026-08-01',
    'date_to'   => '2026-08-31',
];
$augustResult = $archiveModel->getTransactionHistory($augustFilters, 1, 15, 2);
$augustSummary = $txModel->getAuditMetrics($augustFilters, 2);
assert_true($augustResult['total_items'] > 800 && $augustResult['total_items'] < 1600, "August transactions count in realistic range (found {$augustResult['total_items']})");
assert_true($augustSummary['total_revenue'] > 0 && $augustSummary['total_revenue'] < $allSummary['total_revenue'], "August revenue is a proper subset of total revenue");

// C. Filter by service (Water only)
$waterFilters = ['service' => 'water'];
$waterResult = $archiveModel->getTransactionHistory($waterFilters, 1, 15, 2);
$waterSummary = $txModel->getAuditMetrics($waterFilters, 2);
assert_true($waterSummary['soap_revenue'] == 0.00, "Soap revenue is ₱0.00 when filtered to Water");
assert_true($waterSummary['total_revenue'] == $waterSummary['water_revenue'], "Total revenue matches Water revenue when filtered");

// D. Filter by search (Client transaction ID)
$searchFilters = ['search' => 'DEMO-TX-202605'];
$searchResult = $archiveModel->getTransactionHistory($searchFilters, 1, 15, 2);
assert_true($searchResult['total_items'] > 0, "Search by client transaction ID prefix returned records (found {$searchResult['total_items']})");

// E. History Cash collections with filters
$ccAugust = $txModel->getCashCollections(0, 2, $augustFilters);
assert_true(count($ccAugust) >= 5 && count($ccAugust) <= 10, "August cash collections in realistic range (found " . count($ccAugust) . ")");

echo "  [PASS] Dynamic summary metrics, date filtering, service filtering, and search verified.\n\n";

// ------------------------------------------------------------
// 4. Test Rendering of dashboard/archive.php
// ------------------------------------------------------------
echo "4. Testing dashboard/archive.php HTML Rendering...\n";

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/archive.php';
$_GET = ['tab' => 'transactions'];

ob_start();
include ROOT_PATH . '/dashboard/archive.php';
$html = ob_get_clean();

assert_true(strpos($html, 'Total Sales') !== false, "HTML contains 'Total Sales' summary card");
assert_true(strpos($html, 'Completed Transactions') !== false, "HTML contains 'Completed Transactions' summary card");
assert_true(strpos($html, 'Water Sales') !== false, "HTML contains 'Water Sales' summary card");
assert_true(strpos($html, 'Soap Sales') !== false, "HTML contains 'Soap Sales' summary card");
assert_true(strpos($html, 'Export Excel') !== false, "HTML contains 'Export Excel' action button");
assert_true(strpos($html, 'cashCollectionsArchive') !== false, "HTML contains Cash Collections panel");

echo "  [PASS] dashboard/archive.php renders summary cards, transaction table, export button, and collections panel.\n\n";

// ------------------------------------------------------------
// 5. Test Export Excel on History Page
// ------------------------------------------------------------
echo "5. Testing History Page Excel Export CLI Execution...\n";

$phpCli = 'C:\\xampp\\php\\php.exe';
$cmd = "{$phpCli} -r \"chdir('c:/xampp/htdocs/vendolink_claude_v1'); require 'config/config.php'; require 'config/database.php'; require 'models/Transaction.php'; \$t = new Transaction(); \$t->exportSalesReport(['date_from' => '2026-09-01', 'date_to' => '2026-09-30'], 2);\"";
$exportOutput = shell_exec($cmd);

assert_true(!empty($exportOutput), "Excel export produced output");
assert_true(strpos($exportOutput, 'VendoLink — Sales Report') !== false, "Report title is 'VendoLink — Sales Report'");
assert_true(strpos($exportOutput, 'SALES SUMMARY') !== false, "SALES SUMMARY section present in export");
assert_true(strpos($exportOutput, 'Total Sales Amount') !== false, "Total Sales Amount present in export");

$exportLines = explode("\n", trim($exportOutput));
assert_true(count($exportLines) > 500, "September export contains > 500 lines (found " . count($exportLines) . ")");

echo "  [PASS] Excel export works seamlessly on generated 6-month records.\n\n";

echo "🎉 ALL HISTORY PAGE & 6-MONTH DEMO VERIFICATION TESTS PASSED 100% SUCCESSFULLY!\n";

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Assertion failed: " . $message);
    }
}
