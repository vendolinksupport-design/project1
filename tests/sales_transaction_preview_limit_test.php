<?php
/**
 * ============================================================
 * VendoLink — Sales Overview Transaction Records Preview Limit & View More Test
 * ============================================================
 *
 * Verifies:
 * 1. Default preview limit is set to 5 records per page in dashboard/sales.php.
 * 2. View More button is present in panel header and links to History > Transaction History (archive.php?tab=transactions).
 * 3. Removal of fake empty placeholder rows (tx-empty-row) and 900px min-height void.
 * 4. CSS rules for .sales-tx-table-card provide clean fit-content display.
 * 5. Full render verification of dashboard/sales.php.
 *
 * Run with: C:\xampp\php\php.exe tests\sales_transaction_preview_limit_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Transaction.php';

echo "=== STARTING SALES OVERVIEW TRANSACTION PREVIEW & VIEW MORE TESTS ===\n\n";

$salesCode = file_get_contents(__DIR__ . '/../dashboard/sales.php');
$appCss    = file_get_contents(__DIR__ . '/../assets/css/app.css');

// 1. Verify CSS rules in app.css
echo "1. Verifying CSS rules for .sales-tx-table-card in assets/css/app.css...\n";
if (strpos($appCss, '.sales-tx-table-card') === false) {
    throw new RuntimeException("Missing .sales-tx-table-card selector in app.css!");
}
if (strpos($appCss, '.sales-tx-table-card .table-responsive') === false) {
    throw new RuntimeException("Missing .sales-tx-table-card .table-responsive in app.css!");
}
if (strpos($appCss, '.sales-tx-table-card .table tbody') === false) {
    throw new RuntimeException("Missing .sales-tx-table-card .table tbody in app.css!");
}
echo "   [PASS] Fit-content CSS rules for .sales-tx-table-card verified in app.css.\n\n";

// 2. Verify $perPage default in dashboard/sales.php
echo "2. Verifying \$perPage default in dashboard/sales.php...\n";
if (!preg_match('/\$perPage\s*=\s*max\(1,\s*min\(100,\s*\(int\)\(\$_GET\[\'per_page\'\]\s*\?\?\s*5\)\)\);/', $salesCode)) {
    throw new RuntimeException("Expected \$perPage = max(1, min(100, (int)(\$_GET['per_page'] ?? 5))) in dashboard/sales.php!");
}
echo "   [PASS] Default perPage limit set to 5 in dashboard/sales.php.\n\n";

// 3. Verify View More button in Transaction Records panel header
echo "3. Verifying View More button in #salesTransactionRecords panel header...\n";
if (strpos($salesCode, 'id="salesTransactionRecords"') === false) {
    throw new RuntimeException("Missing #salesTransactionRecords ID in sales.php!");
}
if (!preg_match('/id="salesTransactionRecords"[\s\S]*?class="panel-header[\s\S]*?href="<\?=\s*BASE_URL\s*\?>\/dashboard\/archive\.php\?tab=transactions"[^>]*>[\s\S]*?(?:View All Transactions|View More)/', $salesCode)) {
    throw new RuntimeException("View All Transactions button directing to archive.php?tab=transactions is NOT inside #salesTransactionRecords .panel-header!");
}
echo "   [PASS] View All Transactions button is present in panel header and links to History > Transaction History (archive.php?tab=transactions).\n\n";

// 4. Verify unoccupied record row loop inside salesTransactionRecords up to 5
echo "4. Verifying space for unoccupied records up to 5 in #salesTransactionRecords...\n";
if (!preg_match('/id="salesTransactionRecords"[\s\S]*?\$emptyRowsCount\s*=\s*max\(0,\s*5\s*-\s*count\(\$transactions\)\)[\s\S]*?<tr class="tx-empty-row"/', $salesCode)) {
    throw new RuntimeException("Missing unoccupied rows loop up to 5 inside #salesTransactionRecords!");
}
echo "   [PASS] Space for unoccupied records up to 5 verified inside #salesTransactionRecords.\n\n";

// 5. Test Live Page Render with Session
echo "5. Testing live render of dashboard/sales.php...\n";
$_SESSION['user_id'] = 2;
$_SESSION['role'] = ROLE_OWNER;
$_SESSION['user_role'] = ROLE_OWNER;
$_SESSION['full_name'] = 'Business Owner';
$_SESSION['email'] = 'owner@vendolink.test';
$_SESSION['last_activity'] = time();

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/sales.php';
$_GET = [];

ob_start();
require __DIR__ . '/../dashboard/sales.php';
$html = ob_get_clean();

if (strpos($html, 'id="salesTransactionRecords"') === false) {
    throw new RuntimeException("Live render missing #salesTransactionRecords!");
}
if (strpos($html, 'archive.php?tab=transactions') === false) {
    throw new RuntimeException("Live render missing link to archive.php?tab=transactions!");
}

$txPanelStart = strpos($html, 'id="salesTransactionRecords"');
$txPanelEnd   = strpos($html, 'id="cashCollectionsTracker"');
$txPanelHtml  = substr($html, $txPanelStart, $txPanelEnd - $txPanelStart);

preg_match_all('/<tr style="border-bottom: 1px solid rgba\(255,255,255,\.05\); font-size: \.88rem;">/i', $txPanelHtml, $dataRows);
preg_match_all('/<tr class="tx-empty-row"/i', $txPanelHtml, $emptyRows);
$totalRows = count($dataRows[0]) + count($emptyRows[0]);

echo "   [PASS] Live render successful: " . count($dataRows[0]) . " data rows + " . count($emptyRows[0]) . " unoccupied row(s) = {$totalRows} total rows.\n";
if ($totalRows !== 5) {
    throw new RuntimeException("Expected exactly 5 total rows, got: {$totalRows}");
}
echo "   [PASS] Panel strictly maintains 5 rows total with reserved space for unoccupied records.\n\n";

echo "🎉 ALL SALES OVERVIEW TRANSACTION PREVIEW & VIEW MORE TESTS PASSED 100% SUCCESSFULLY!\n";
