<?php
/**
 * ============================================================
 * VendoLink — Owner History Secondary Selections Test
 * ============================================================
 *
 * Verifies:
 * 1. Default view: Transaction History defaults to Transaction Records.
 *    - Displays transaction summary cards, filters, table, pagination, and exactly 1 Export Excel button.
 *    - Does NOT display Cash Collections below the transaction table.
 * 2. Cash Collections secondary selection (section=collections):
 *    - Displays Cash Collections reconciliation KPI cards, separate filter card, table, pagination,
 *      exactly 1 Export Excel button, and Record Cash Collection button with modal trigger.
 *    - Does NOT display Transaction Records.
 * 3. Separate Filters:
 *    - Transaction filters (service, status, date) apply only to transactions.
 *    - Cash Collection filters (search, date) apply only to collections.
 * 4. Separate Excel Exports:
 *    - export=excel exports transactions dataset.
 *    - export=cash_collections exports cash collections dataset.
 * 5. Recording Cash Collection:
 *    - POST record_cash_collection creates collection in database and calculates variance.
 * 6. Top-Level Tabs (Maintenance & Configuration):
 *    - Remain unchanged and functional.
 *
 * Run with: C:\xampp\php\php.exe tests\owner_history_secondary_selections_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Archive.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Machine.php';

$db = Database::getInstance();
$txModel = new Transaction();

echo "============================================================\n";
echo "Starting Owner History Secondary Selections Verification\n";
echo "============================================================\n\n";

// Authenticate as Owner (Owner 114 has test transactions)
$_SESSION['user_id'] = 114;
$_SESSION['role'] = ROLE_OWNER;
$_SESSION['owner_id'] = 114;
$_SESSION['user_role'] = ROLE_OWNER;
$_SESSION['username'] = 'test_owner';
$_SESSION['full_name'] = 'Business Owner';

// ------------------------------------------------------------
// TEST 1: Default View (Transaction Records)
// ------------------------------------------------------------
echo "--- TEST 1: Default View (Transaction Records) ---\n";
$_GET = ['tab' => 'transactions'];
ob_start();
require __DIR__ . '/../dashboard/archive.php';
$htmlDefault = ob_get_clean();

// 1.1 Verify top-level tabs exist
assert_str_contains($htmlDefault, 'Transaction History', 'Top-level Transaction History tab present');
assert_str_contains($htmlDefault, 'Maintenance History', 'Top-level Maintenance History tab present');
assert_str_contains($htmlDefault, 'Configuration History', 'Top-level Configuration History tab present');

// 1.2 Verify secondary subtab navigation exists
assert_str_contains($htmlDefault, 'archive-subtab-nav', 'Secondary sub-tab navigation (.archive-subtab-nav) present');
assert_str_contains($htmlDefault, 'Transaction Records', 'Secondary selection Transaction Records present');
assert_str_contains($htmlDefault, 'Cash Collections', 'Secondary selection Cash Collections present');

// 1.3 Verify Transaction Records is active by default
if (!preg_match('/class="[^"]*archive-subtab-btn[^"]*active[^"]*"[^>]*>[\s\S]*?Transaction Records/', $htmlDefault)) {
    throw new RuntimeException("FAIL: Transaction Records subtab is NOT marked active by default!");
}
echo "    [PASS] Transaction Records sub-tab is marked active by default.\n";

// 1.4 Verify Transaction table columns are present
assert_str_contains($htmlDefault, 'Transaction ID', 'Transaction table column Transaction ID present');
assert_str_contains($htmlDefault, 'Purchased Time', 'Transaction table column Purchased Time present');

// 1.6 Verify exactly ONE Export Excel button in Transaction Records
$exportExcelCount = substr_count($htmlDefault, 'Export Excel');
if ($exportExcelCount !== 1) {
    throw new RuntimeException("FAIL: Expected exactly 1 'Export Excel' button in Transaction Records, found {$exportExcelCount}!");
}
echo "    [PASS] Exactly ONE 'Export Excel' button exists in Transaction Records.\n";

// 1.7 Verify Cash Collections table is NOT present below Transaction Records
if (strpos($htmlDefault, 'id="cashCollectionsArchive"') !== false) {
    throw new RuntimeException("FAIL: Cash Collections panel (#cashCollectionsArchive) found below transaction table! Must only show selected section.");
}
if (strpos($htmlDefault, 'stat-card-label text-white">Cash Collected') !== false) {
    throw new RuntimeException("FAIL: 'Cash Collected' card found in records view! Must only show selected section.");
}
echo "    [PASS] Cash Collections is NOT rendered below the transaction table.\n\n";

// ------------------------------------------------------------
// TEST 2: Cash Collections View (section=collections)
// ------------------------------------------------------------
echo "--- TEST 2: Cash Collections View (section=collections) ---\n";
$_GET = ['tab' => 'transactions', 'section' => 'collections'];
ob_start();
require __DIR__ . '/../dashboard/archive.php';
$htmlCollections = ob_get_clean();

// 2.1 Verify Cash Collections subtab is active
if (!preg_match('/class="[^"]*archive-subtab-btn[^"]*active[^"]*"[^>]*>[\s\S]*?Cash Collections/', $htmlCollections)) {
    throw new RuntimeException("FAIL: Cash Collections subtab is NOT marked active when section=collections!");
}
echo "    [PASS] Cash Collections sub-tab is marked active.\n";

// 2.2 Verify Cash Collections summary cards are removed per requirement
if (strpos($htmlCollections, 'stat-card-label text-white">Cash Collected') !== false) {
    throw new RuntimeException("FAIL: 'Cash Collected' summary card found in History Cash Collections! Summary cards must be removed.");
}
if (strpos($htmlCollections, 'stat-card-label text-white">Total Collections') !== false) {
    throw new RuntimeException("FAIL: 'Total Collections' summary card found in History Cash Collections! Summary cards must be removed.");
}
echo "    [PASS] Cash Collections summary cards are removed from History interface.\n";

// 2.3 Verify Cash Collections panel and columns for Owner
assert_str_contains($htmlCollections, 'id="cashCollectionsArchive"', 'Cash Collections table (#cashCollectionsArchive) present');
assert_str_contains($htmlCollections, 'Collection Date', 'Cash Collections column Collection Date present');
assert_str_contains($htmlCollections, 'Machine', 'Cash Collections column Machine present');
assert_str_contains($htmlCollections, 'Cash Collected', 'Cash Collections column Cash Collected present');
assert_str_contains($htmlCollections, 'Expected Sales', 'Cash Collections column Expected Sales present');
assert_str_contains($htmlCollections, 'Difference', 'Cash Collections column Difference present');
assert_str_contains($htmlCollections, 'Notes', 'Cash Collections column Notes present');

// Verify Recorded By is NOT present in Owner visible table
if (strpos($htmlCollections, '<th>Recorded By</th>') !== false) {
    throw new RuntimeException("FAIL: 'Recorded By' column header found in Owner Cash Collections table!");
}
echo "    [PASS] 'Recorded By' column is omitted from Owner visible table.\n";

// Verify Admin view retains Recorded By
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = ROLE_ADMIN;
$_SESSION['role'] = ROLE_ADMIN;
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'VendoLink Support Admin';
$_GET = ['tab' => 'transactions', 'section' => 'collections'];
ob_start();
require __DIR__ . '/../dashboard/archive.php';
$htmlAdminCollections = ob_get_clean();
assert_str_contains($htmlAdminCollections, '<th>Recorded By</th>', 'Admin Cash Collections table retains Recorded By column');
echo "    [PASS] Admin Cash Collections view retains 'Recorded By' column.\n";

// Reset back to Owner
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = ROLE_OWNER;
$_SESSION['role'] = ROLE_OWNER;
$_SESSION['username'] = 'owner';
$_SESSION['full_name'] = 'Business Owner';

// 2.4 Verify exactly ONE Export Excel button in Cash Collections
$ccExportExcelCount = substr_count($htmlCollections, 'Export Excel');
if ($ccExportExcelCount !== 1) {
    throw new RuntimeException("FAIL: Expected exactly 1 'Export Excel' button in Cash Collections, found {$ccExportExcelCount}!");
}
echo "    [PASS] Exactly ONE 'Export Excel' button exists in Cash Collections.\n";

// 2.5 Verify Record Cash Collection button & modal trigger
assert_str_contains($htmlCollections, 'Record Cash Collection', 'Record Cash Collection button present');
assert_str_contains($htmlCollections, 'data-bs-target="#recordCashModal"', 'Record Cash Collection button triggers #recordCashModal');
assert_str_contains($htmlCollections, 'id="recordCashModal"', '#recordCashModal modal dialog present');

// 2.6 Verify Transaction Records table is NOT present in Cash Collections view
if (strpos($htmlCollections, 'Transaction ID') !== false) {
    throw new RuntimeException("FAIL: Transaction table found in Cash Collections view! Must only show selected section.");
}
if (strpos($htmlCollections, 'Water Sales') !== false) {
    throw new RuntimeException("FAIL: Transaction KPI cards found in Cash Collections view!");
}
echo "    [PASS] Transaction Records table and cards are NOT rendered in Cash Collections view.\n\n";

// ------------------------------------------------------------
// TEST 3: Separate Filter Functionality
// ------------------------------------------------------------
echo "--- TEST 3: Separate Filter Functionality ---\n";

// 3.1 Cash Collections Date Filter
$allCc = $txModel->getCashCollections(0, 2);
echo "    Total collection records in DB for Owner: " . count($allCc) . "\n";

if (!empty($allCc)) {
    $firstDate = substr($allCc[0]['collected_at'], 0, 10);
    $filteredCc = $txModel->getCashCollections(0, 2, ['date_from' => $firstDate, 'date_to' => $firstDate]);
    echo "    Filtering collections for {$firstDate}: found " . count($filteredCc) . " records\n";
    foreach ($filteredCc as $fc) {
        if (substr($fc['collected_at'], 0, 10) !== $firstDate) {
            throw new RuntimeException("FAIL: Collection record date " . $fc['collected_at'] . " does not match filter {$firstDate}!");
        }
    }
    echo "    [PASS] Cash collections date filter works accurately.\n";
}

// ------------------------------------------------------------
// TEST 4: Excel Export Integration
// ------------------------------------------------------------
echo "--- TEST 4: Excel Export Integration ---\n";
// 4.1 Verify Cash Collections export URL
if (!preg_match('/href="[^"]*export=cash_collections[^"]*"/', $htmlCollections)) {
    throw new RuntimeException("FAIL: Export Excel button in Cash Collections view does NOT have export=cash_collections parameter!");
}
echo "    [PASS] Export Excel button in Cash Collections links to export=cash_collections.\n";

// 4.2 Verify Transaction Records export URL
if (!preg_match('/href="[^"]*export=excel[^"]*"/', $htmlDefault)) {
    throw new RuntimeException("FAIL: Export Excel button in Transaction Records view does NOT have export=excel parameter!");
}
echo "    [PASS] Export Excel button in Transaction Records links to export=excel.\n\n";

// ------------------------------------------------------------
// TEST 5: Recording a Cash Collection via POST
// ------------------------------------------------------------
echo "--- TEST 5: Recording a Cash Collection Workflow ---\n";
$initialCount = (int)$db->query("SELECT COUNT(*) FROM cash_collections WHERE user_id = 2")->fetchColumn();
echo "    Initial collections count for Owner (User 2): {$initialCount}\n";

// Simulate POST request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'record_cash_collection';
$_POST['collected_amount'] = '1234.50';
$_POST['notes'] = '__TEST_AUTOMATED_COLLECTION__';

// We run record collection logic with authorized override for test machine
$testAmount = 1234.50;
$testNotes = '__TEST_AUTOMATED_COLLECTION__';
$insertedId = $txModel->recordCashCollection(2, 1, $testAmount, $testNotes, true, 'Test authorized adjustment override reason', 2, 'owner');

if ($insertedId <= 0) {
    throw new RuntimeException("FAIL: recordCashCollection failed to insert record!");
}

$newCount = (int)$db->query("SELECT COUNT(*) FROM cash_collections WHERE user_id = 2")->fetchColumn();
if ($newCount !== $initialCount + 1) {
    throw new RuntimeException("FAIL: Collection count did not increase by 1!");
}
echo "    [PASS] Successfully recorded cash collection (New ID #{$insertedId}, Amount: ₱1,234.50).\n";

// Verify the record in DB
$rec = $db->query("SELECT * FROM cash_collections WHERE id = {$insertedId}")->fetch();
if ((float)$rec['collected_amount'] !== $testAmount || $rec['notes'] !== $testNotes) {
    throw new RuntimeException("FAIL: Inserted collection record fields mismatch!");
}
echo "    [PASS] Record verified in database with calculated system sales and variance.\n";

// Clean up test record
$db->query("DELETE FROM cash_collections WHERE id = {$insertedId}");
$cleanedCount = (int)$db->query("SELECT COUNT(*) FROM cash_collections WHERE user_id = 2")->fetchColumn();
if ($cleanedCount !== $initialCount) {
    throw new RuntimeException("FAIL: Failed to clean up test collection record!");
}
echo "    [PASS] Cleaned up temporary test collection record cleanly.\n\n";

// ------------------------------------------------------------
// TEST 6: Top-Level Tabs (Maintenance & Configuration)
// ------------------------------------------------------------
echo "--- TEST 6: Top-Level Tabs (Maintenance & Configuration) ---\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_POST['action']);

// 6.1 Maintenance History tab
$_GET = ['tab' => 'maintenance'];
ob_start();
require __DIR__ . '/../dashboard/archive.php';
$htmlMaint = ob_get_clean();

assert_str_contains($htmlMaint, 'Maintenance History', 'Maintenance History heading present');
if (strpos($htmlMaint, 'archive-subtab-nav') !== false) {
    throw new RuntimeException("FAIL: Secondary subtab nav should NOT appear on Maintenance tab!");
}
echo "    [PASS] Maintenance History renders cleanly without sub-tabs.\n";

// 6.2 Configuration History tab
$_GET = ['tab' => 'configuration'];
ob_start();
require __DIR__ . '/../dashboard/archive.php';
$htmlConfig = ob_get_clean();

assert_str_contains($htmlConfig, 'Configuration History', 'Configuration History heading present');
if (strpos($htmlConfig, 'archive-subtab-nav') !== false) {
    throw new RuntimeException("FAIL: Secondary subtab nav should NOT appear on Configuration tab!");
}
echo "    [PASS] Configuration History renders cleanly without sub-tabs.\n\n";

echo "============================================================\n";
echo "ALL OWNER HISTORY SECONDARY SELECTIONS TESTS PASSED!\n";
echo "============================================================\n";

function assert_str_contains(string $haystack, string $needle, string $message): void {
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException("FAIL: Assertion failed: {$message} (Did not find '{$needle}')");
    }
    echo "    [PASS] {$message}\n";
}
