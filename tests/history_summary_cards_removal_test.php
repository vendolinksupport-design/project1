<?php
/**
 * ============================================================
 * VendoLink — History Summary Cards Removal Test
 * ============================================================
 */

chdir('c:/xampp/htdocs/vendolink_claude_v1');

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'models/Report.php';
require_once 'models/Archive.php';
require_once 'models/Transaction.php';

function assert_test(bool $condition, string $message): void {
    if ($condition) {
        echo "  [PASS] {$message}\n";
    } else {
        echo "  [FAIL] {$message}\n";
        exit(1);
    }
}

echo "===================================================================\n";
echo " VENDOLINK — REMOVE DUPLICATE SALES SUMMARY CARDS TEST \n";
echo "===================================================================\n\n";

// Setup session for in-process renders
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Administrator';
$_SESSION['email'] = 'admin@vendolink.test';
$_SESSION['owner_id'] = null;
$_SESSION['last_activity'] = time();
$_SESSION['created_at'] = time();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// -----------------------------------------------------------------
// 1. Verify Sales Overview Dashboard RETAINS All 4 Summary Cards
// -----------------------------------------------------------------
echo "--- 1. Testing Sales Overview Dashboard (dashboard/sales.php) ---\n";
$_SERVER['PHP_SELF'] = '/dashboard/sales.php';
$_GET = ['period' => 'all'];
ob_start();
require 'dashboard/sales.php';
$salesHtml = ob_get_clean();

assert_test(strpos($salesHtml, 'Total Revenue') !== false, "Total Revenue card present on Sales Overview");
assert_test(strpos($salesHtml, 'Transaction Count') !== false, "Transaction Count card present on Sales Overview");
assert_test(strpos($salesHtml, 'Water Sales') !== false, "Water Sales card present on Sales Overview");
assert_test(strpos($salesHtml, 'Soap Sales') !== false, "Soap Sales card present on Sales Overview");
assert_test(substr_count($salesHtml, 'stat-card') >= 4, "Sales Overview contains 4 lifetime stat-cards");

// -----------------------------------------------------------------
// 2. Verify History → Transaction History ABSENCE of Summary Cards
// -----------------------------------------------------------------
echo "\n--- 2. Testing History → Transaction History (dashboard/archive.php?tab=transactions&section=records) ---\n";
$_SERVER['PHP_SELF'] = '/dashboard/archive.php';
$_GET = ['tab' => 'transactions', 'section' => 'records'];
ob_start();
require 'dashboard/archive.php';
$archiveTxHtml = ob_get_clean();

assert_test(strpos($archiveTxHtml, 'stat-card-label text-white">Total Sales') === false, "Total Sales summary card ABSENT from History → Transaction History");
assert_test(strpos($archiveTxHtml, 'stat-card-label text-white">Completed Transactions') === false, "Completed Transactions summary card ABSENT from History → Transaction History");
assert_test(strpos($archiveTxHtml, 'stat-card-label text-white">Water Sales') === false, "Water Sales summary card ABSENT from History → Transaction History");
assert_test(strpos($archiveTxHtml, 'stat-card-label text-white">Soap Sales') === false, "Soap Sales summary card ABSENT from History → Transaction History");
assert_test(strpos($archiveTxHtml, 'stat-card') === false, "Zero stat-cards rendered on History → Transaction History");

// Verify essential features preserved in Transaction History
assert_test(strpos($archiveTxHtml, 'page-title') !== false && strpos($archiveTxHtml, 'History') !== false, "History heading preserved");
assert_test(strpos($archiveTxHtml, 'archive-tab-nav') !== false, "Main tab navigation preserved");
assert_test(strpos($archiveTxHtml, 'archive-subtab-nav') !== false, "Subtab navigation preserved");
assert_test(strpos($archiveTxHtml, 'tx-filter-card') !== false, "Search/filter toolbar preserved");
assert_test(strpos($archiveTxHtml, 'name="search"') !== false, "Search input field preserved");
assert_test(strpos($archiveTxHtml, 'name="service"') !== false, "Service filter dropdown preserved");
assert_test(strpos($archiveTxHtml, 'Export Excel') !== false, "Export Excel action preserved");
assert_test(strpos($archiveTxHtml, 'Transaction Records') !== false && strpos($archiveTxHtml, 'table-dark') !== false, "Transaction records table preserved");
assert_test(strpos($archiveTxHtml, 'tx-pagination') !== false, "Pagination controls preserved");

// -----------------------------------------------------------------
// 3. Verify History → Cash Collections ABSENCE of Summary Cards
// -----------------------------------------------------------------
echo "\n--- 3. Testing History → Cash Collections (dashboard/archive.php?tab=transactions&section=collections) ---\n";
$_SERVER['PHP_SELF'] = '/dashboard/archive.php';
$_GET = ['tab' => 'transactions', 'section' => 'collections'];
ob_start();
require 'dashboard/archive.php';
$archiveCcHtml = ob_get_clean();

assert_test(strpos($archiveCcHtml, 'stat-card-label text-white">Total Sales') === false, "Total Sales summary card ABSENT from History → Cash Collections");
assert_test(strpos($archiveCcHtml, 'stat-card-label text-white">Completed Transactions') === false, "Completed Transactions summary card ABSENT from History → Cash Collections");
assert_test(strpos($archiveCcHtml, 'stat-card-label text-white">Water Sales') === false, "Water Sales summary card ABSENT from History → Cash Collections");
assert_test(strpos($archiveCcHtml, 'stat-card-label text-white">Soap Sales') === false, "Soap Sales summary card ABSENT from History → Cash Collections");
assert_test(strpos($archiveCcHtml, 'stat-card') === false, "Zero stat-cards rendered on History → Cash Collections");

// Verify essential features preserved in Cash Collections
assert_test(strpos($archiveCcHtml, 'name="search"') !== false, "Cash Collections search preserved");
assert_test(strpos($archiveCcHtml, 'name="date_from"') !== false, "Date From filter preserved");
assert_test(strpos($archiveCcHtml, 'name="date_to"') !== false, "Date To filter preserved");
assert_test(strpos($archiveCcHtml, 'id="cashCollectionsArchive"') !== false, "Cash Collections records table preserved");
assert_test(strpos($archiveCcHtml, 'Export Excel') !== false, "Export Excel action preserved");

// -----------------------------------------------------------------
// 4. Test Search and Filter Functionality in History
// -----------------------------------------------------------------
echo "\n--- 4. Testing Search and Filtering Execution ---\n";
$_GET = ['tab' => 'transactions', 'section' => 'records', 'service' => 'water', 'status' => 'completed'];
ob_start();
require 'dashboard/archive.php';
$filterWaterHtml = ob_get_clean();
assert_test(!empty($filterWaterHtml), "Rendered filtered Transaction History without errors");
assert_test(strpos($filterWaterHtml, 'stat-card') === false, "No stat-cards under filtered view");

// -----------------------------------------------------------------
// 5. Test Export Capabilities
// -----------------------------------------------------------------
echo "\n--- 5. Testing Export Capabilities ---\n";
$phpCli = 'C:\\xampp\\php\\php.exe';

$txExportCmd = "{$phpCli} -r \"chdir('c:/xampp/htdocs/vendolink_claude_v1'); require 'config/config.php'; require 'config/database.php'; require 'includes/functions.php'; require 'models/Transaction.php'; (new Transaction())->exportTransactionsCsv(['status' => 'completed']);\"";
$txExport = shell_exec($txExportCmd);
assert_test(!empty($txExport), "Transaction History CSV export produces data");
assert_test(strpos($txExport, 'Transaction ID') !== false || strpos($txExport, 'ID') !== false, "Transaction History CSV export contains header columns");

$ccExportCmd = "{$phpCli} -r \"chdir('c:/xampp/htdocs/vendolink_claude_v1'); require 'config/config.php'; require 'config/database.php'; require 'includes/functions.php'; require 'models/Transaction.php'; (new Transaction())->exportCashCollectionsCsv();\"";
$ccExport = shell_exec($ccExportCmd);
assert_test(!empty($ccExport), "Cash Collections CSV export produces data");
assert_test(strpos($ccExport, 'Collection Date') !== false || strpos($ccExport, 'Date') !== false, "Cash Collections CSV export contains header columns");

echo "\n===================================================================\n";
echo "🎉 ALL HISTORY SUMMARY CARDS REMOVAL TESTS PASSED 100%!\n";
echo "===================================================================\n";
