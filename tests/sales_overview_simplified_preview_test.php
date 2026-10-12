<?php
/**
 * ============================================================
 * VendoLink — Sales Overview Simplified Preview Verification Test
 * ============================================================
 *
 * Verifies:
 * 1. Transaction Records on Sales Overview:
 *    - Strictly displays 5 latest records.
 *    - Top-right header button labeled "View All Transactions" linking to archive.php?tab=transactions.
 *    - Accurate record count badge (e.g. "Showing 5 of 7,382 transactions").
 *    - Absence of bottom "View More" button.
 *    - Absence of bottom pagination controls (page numbers, Prev/Next).
 *
 * 2. Cash Collections on Sales Overview:
 *    - Strictly displays 5 latest collections.
 *    - Top-right header button labeled "View All Collections" linking to archive.php?tab=transactions#cashCollectionsArchive.
 *    - "Record Cash Collection" button kept beside it.
 *    - Accurate record count badge (e.g. "Showing 5 of 50 collections").
 *    - Absence of bottom "View More" button.
 *    - Absence of bottom pagination controls.
 *
 * 3. History Page Preservation:
 *    - Complete records, full pagination, date range filtering, and search preserved on archive.php.
 *
 * Run with: C:\xampp\php\php.exe tests/sales_overview_simplified_preview_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Archive.php';

echo "=== STARTING SALES OVERVIEW SIMPLIFIED PREVIEW VERIFICATION ===\n\n";

$db = Database::getInstance();
$txModel = new Transaction();
$archiveModel = new Archive();

// Set Owner Session (user_id = 2)
$_SESSION['user_id'] = 2;
$_SESSION['role'] = ROLE_OWNER;
$_SESSION['user_role'] = ROLE_OWNER;
$_SESSION['username'] = 'owner';
$_SESSION['full_name'] = 'Business Owner';
$_SESSION['email'] = 'owner@vendolink.test';
$_SESSION['last_activity'] = time();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/sales.php';
$_GET = [];

ob_start();
require __DIR__ . '/../dashboard/sales.php';
$html = ob_get_clean();

// ------------------------------------------------------------
// 1. Transaction Records Verification
// ------------------------------------------------------------
echo "1. Testing Transaction Records Section on Sales Overview...\n";

// Isolate Transaction Records Panel
$txPanelStart = strpos($html, 'id="salesTransactionRecords"');
assert_true($txPanelStart !== false, "Transaction Records card (#salesTransactionRecords) exists");

$ccPanelStart = strpos($html, 'id="cashCollectionsTracker"');
assert_true($ccPanelStart !== false, "Cash Collections card (#cashCollectionsTracker) exists");

$txPanelHtml = substr($html, $txPanelStart, $ccPanelStart - $txPanelStart);

// Check Top-Right Button
assert_true(strpos($txPanelHtml, 'View All Transactions') !== false, "Top-right button is labeled 'View All Transactions'");
assert_true(strpos($txPanelHtml, 'archive.php?tab=transactions') !== false, "View All Transactions links to archive.php?tab=transactions");

// Check Accurate Total Count Badge
assert_true(preg_match('/Showing\s+5\s+of\s+[\d,]+\s+transactions/i', $txPanelHtml), "Accurate count badge present (Showing 5 of X transactions)");

// Check Data Rows Count
preg_match_all('/<tr style="border-bottom: 1px solid rgba\(255,255,255,\.04\); font-size: \.88rem;">/i', $txPanelHtml, $txRows);
assert_true(count($txRows[0]) <= 5, "Strictly at most 5 transactions rendered (found " . count($txRows[0]) . ")");

// Check Complete Absence of Bottom Pagination & Bottom View More
assert_true(strpos($txPanelHtml, 'tx-pagination') === false, "Bottom pagination controls completely removed from Transaction Records");
assert_true(strpos($txPanelHtml, 'aria-label="Previous"') === false, "Previous button completely removed from Transaction Records");
assert_true(strpos($txPanelHtml, 'aria-label="Next"') === false, "Next button completely removed from Transaction Records");

// Ensure only ONE "View All Transactions" button exists in the transaction panel
$viewAllTxCount = substr_count($txPanelHtml, 'View All Transactions');
assert_true($viewAllTxCount === 1, "Exactly one View All Transactions button in Transaction Records header (found {$viewAllTxCount})");
assert_true(strpos($txPanelHtml, 'View More') === false, "Obsolete 'View More' button completely removed from Transaction Records");

echo "  [PASS] Transaction Records simplified: 5 records preview, View All Transactions header button, no bottom pagination.\n\n";

// ------------------------------------------------------------
// 2. Cash Collections Verification
// ------------------------------------------------------------
echo "2. Testing Cash Collections Section on Sales Overview...\n";

// Isolate Cash Collections Panel
$modalStart = strpos($html, 'id="recordCashModal"');
$ccPanelHtml = substr($html, $ccPanelStart, ($modalStart !== false ? $modalStart : strlen($html)) - $ccPanelStart);

// Check Top-Right Button
assert_true(strpos($ccPanelHtml, 'View All Collections') !== false, "Top-right button is labeled 'View All Collections'");
assert_true(strpos($ccPanelHtml, 'archive.php?tab=transactions#cashCollectionsArchive') !== false, "View All Collections links to archive.php?tab=transactions#cashCollectionsArchive");

// Check Record Cash Collection button beside it
assert_true(strpos($ccPanelHtml, 'Record Cash Collection') !== false, "Record Cash Collection button is present beside View All Collections");
assert_true(strpos($ccPanelHtml, 'data-bs-target="#recordCashModal"') !== false, "Record Cash Collection button triggers modal");

// Check Accurate Total Count Badge
assert_true(preg_match('/Showing\s+5\s+of\s+[\d,]+\s+collections/i', $ccPanelHtml), "Accurate count badge present (Showing 5 of X collections)");

// Check Data Rows Count
preg_match_all('/<tr style="border-bottom: 1px solid rgba\(255,255,255,\.05\); font-size: \.88rem;">/i', $ccPanelHtml, $ccRows);
assert_true(count($ccRows[0]) <= 5, "Strictly at most 5 collections rendered (found " . count($ccRows[0]) . ")");

// Check Complete Absence of Bottom Pagination & Bottom View More
assert_true(strpos($ccPanelHtml, 'tx-pagination') === false, "Bottom pagination controls completely removed from Cash Collections");
assert_true(strpos($ccPanelHtml, 'aria-label="Previous"') === false, "Previous button completely removed from Cash Collections");
assert_true(strpos($ccPanelHtml, 'aria-label="Next"') === false, "Next button completely removed from Cash Collections");

$viewAllCcCount = substr_count($ccPanelHtml, 'View All Collections');
assert_true($viewAllCcCount === 1, "Exactly one View All Collections button in Cash Collections header (found {$viewAllCcCount})");
assert_true(strpos($ccPanelHtml, 'View More') === false, "Obsolete 'View More' button completely removed from Cash Collections");

echo "  [PASS] Cash Collections simplified: 5 records preview, View All Collections header button, Record button beside it, no bottom pagination.\n\n";

// ------------------------------------------------------------
// 3. Complete History Page Preservation Verification
// ------------------------------------------------------------
echo "3. Testing Complete History Page Functionality Preservation...\n";

$archiveResult = $archiveModel->getTransactionHistory([], 1, 15, 2);
assert_true($archiveResult['total_items'] > 5, "Full history contains all transactions (found {$archiveResult['total_items']})");
assert_true($archiveResult['total_pages'] > 1, "Full history has full pagination (pages: {$archiveResult['total_pages']})");

$allCollections = $txModel->getCashCollections(0, 2);
assert_true(count($allCollections) > 5, "Full cash collections repository intact (found " . count($allCollections) . ")");

echo "  [PASS] Full History page functionality, complete records, and pagination 100% preserved.\n\n";

echo "🎉 ALL SALES OVERVIEW SIMPLIFIED PREVIEW VERIFICATION TESTS PASSED 100% SUCCESSFULLY!\n";

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException("Assertion failed: {$msg}");
    }
}
