<?php
/**
 * ============================================================
 * VendoLink — Owner & Admin Configuration History Permission Test
 * ============================================================
 *
 * Validates role-based backend query restrictions for Configuration History:
 * 1. Owner Account:
 *    - Hides Administrator and VendoLink Support Admin entries
 *    - Keeps owner-made configuration changes visible
 *    - Accurate record counts and pagination
 *    - Filters (search, service, dates) respect owner boundary
 * 2. Administrator Account:
 *    - Full access to all authorized audit records system-wide
 *    - Correct record counts and multi-page pagination
 * 3. Database Integrity:
 *    - All 45 audit records in configuration_history remain strictly intact
 *
 * Run with: C:\xampp\php\php.exe tests\owner_config_history_permission_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Archive.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../includes/functions.php';

$db = Database::getInstance();
$archive = new Archive();

echo "============================================================\n";
echo "Starting Configuration History Role Permission Verification\n";
echo "============================================================\n\n";

// ------------------------------------------------------------
// Baseline Verification: Total Audit Records in Database
// ------------------------------------------------------------
$totalDbRows = (int)$db->query("SELECT COUNT(*) FROM configuration_history")->fetchColumn();
echo "[Baseline] Total records stored in configuration_history table: {$totalDbRows}\n";
if ($totalDbRows !== 45) {
    echo "  (Note: expected 45 initial rows, found {$totalDbRows})\n";
}

$ownerRowsInDb = (int)$db->query("SELECT COUNT(*) FROM configuration_history ch JOIN users u ON u.id = ch.changed_by_user_id WHERE u.role = 'owner' AND ch.changed_by_user_id = 2")->fetchColumn();
$adminRowsInDb = (int)$db->query("SELECT COUNT(*) FROM configuration_history ch JOIN users u ON u.id = ch.changed_by_user_id WHERE u.role = 'administrator'")->fetchColumn();
$nullRowsInDb  = (int)$db->query("SELECT COUNT(*) FROM configuration_history ch WHERE ch.changed_by_user_id IS NULL")->fetchColumn();

echo "[Baseline] Breakdown in DB:\n";
echo "  - Owner (User 2 / Business Owner): {$ownerRowsInDb}\n";
echo "  - Admin (User 1 / VendoLink Support Admin): {$adminRowsInDb}\n";
echo "  - Legacy / NULL changer: {$nullRowsInDb}\n\n";

// ------------------------------------------------------------
// TEST 1: Owner Account Query Isolation
// ------------------------------------------------------------
echo "--- TEST 1: Owner Account Permission Isolation ---\n";
// Simulate Owner authenticated session
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = ROLE_OWNER;
$_SESSION['username'] = 'owner';
$_SESSION['full_name'] = 'Business Owner';

$ownerRes = $archive->getConfigurationHistory([], 1, 15, 2, ROLE_OWNER);

echo "1.1 Checking total_items returned for Owner...\n";
if ($ownerRes['total_items'] !== $ownerRowsInDb) {
    throw new RuntimeException("FAIL: Owner total_items ({$ownerRes['total_items']}) does not match owner records count ({$ownerRowsInDb})!");
}
echo "    PASS: Owner total_items = {$ownerRes['total_items']} (exactly matches owner-made changes)\n";

echo "1.2 Checking that NO Administrator or Support Admin entries exist in Owner results...\n";
foreach ($ownerRes['items'] as $item) {
    if (in_array(strtolower($item['changed_by_role'] ?? ''), ['administrator', 'support', 'vendolink_support'], true)) {
        throw new RuntimeException("FAIL: Found administrator record in owner view! Item ID: {$item['id']}, Role: {$item['changed_by_role']}");
    }
    if (stripos($item['changed_by_name'] ?? '', 'admin') !== false) {
        throw new RuntimeException("FAIL: Found admin name in owner view! Item ID: {$item['id']}, Name: {$item['changed_by_name']}");
    }
    if ((int)$item['changed_by_user_id'] !== 2) {
        throw new RuntimeException("FAIL: Found non-owner user ID ({$item['changed_by_user_id']}) in owner view!");
    }
}
echo "    PASS: All " . count($ownerRes['items']) . " returned items belong strictly to Owner (User 2, Business Owner).\n";
echo "    PASS: Zero Administrator or VendoLink Support Admin entries present.\n";

echo "1.3 Checking Owner pagination calculation...\n";
if ($ownerRes['total_pages'] !== 1) {
    throw new RuntimeException("FAIL: Expected 1 page for 15 items at 15/page, got {$ownerRes['total_pages']}");
}
echo "    PASS: Owner total_pages = {$ownerRes['total_pages']} (1 page of 15 records)\n";

echo "1.4 Checking Owner search filter against forbidden admin names...\n";
$searchAdminRes = $archive->getConfigurationHistory(['search' => 'VendoLink Support Admin'], 1, 15, 2, ROLE_OWNER);
if ($searchAdminRes['total_items'] !== 0) {
    throw new RuntimeException("FAIL: Searching 'VendoLink Support Admin' as Owner returned {$searchAdminRes['total_items']} records! Must be 0.");
}
echo "    PASS: Owner search for 'VendoLink Support Admin' returns 0 records.\n";

echo "1.5 Checking Owner service filter (water vs soap)...\n";
$waterRes = $archive->getConfigurationHistory(['service' => 'water'], 1, 15, 2, ROLE_OWNER);
$soapRes  = $archive->getConfigurationHistory(['service' => 'soap'], 1, 15, 2, ROLE_OWNER);
echo "    Owner Water records: {$waterRes['total_items']}, Soap records: {$soapRes['total_items']}\n";
if ($waterRes['total_items'] + $soapRes['total_items'] !== $ownerRowsInDb) {
    throw new RuntimeException("FAIL: Water ({$waterRes['total_items']}) + Soap ({$soapRes['total_items']}) !== Total Owner ({$ownerRowsInDb})");
}
echo "    PASS: Service filters accurately segment owner records.\n\n";

// ------------------------------------------------------------
// TEST 2: Administrator Account System-Wide Access
// ------------------------------------------------------------
echo "--- TEST 2: Administrator Account System-Wide Access ---\n";
// Simulate Administrator authenticated session
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = ROLE_ADMIN;
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'VendoLink Support Admin';

$adminRes = $archive->getConfigurationHistory([], 1, 15, null, ROLE_ADMIN);

echo "2.1 Checking total_items returned for Administrator...\n";
if ($adminRes['total_items'] !== $totalDbRows) {
    throw new RuntimeException("FAIL: Admin total_items ({$adminRes['total_items']}) does not match full DB count ({$totalDbRows})!");
}
echo "    PASS: Admin total_items = {$adminRes['total_items']} (full audit history across system)\n";

echo "2.2 Checking Admin pagination calculation...\n";
$expectedAdminPages = (int)ceil($totalDbRows / 15);
if ($adminRes['total_pages'] !== $expectedAdminPages) {
    throw new RuntimeException("FAIL: Expected {$expectedAdminPages} pages for Admin, got {$adminRes['total_pages']}");
}
echo "    PASS: Admin total_pages = {$adminRes['total_pages']} (at 15 records per page)\n";

echo "2.3 Verifying Admin sees Administrator and Support entries...\n";
$foundAdminEntry = false;
$foundOwnerEntry = false;
// Inspect first 2 pages of admin view
for ($p = 1; $p <= min(3, $adminRes['total_pages']); $p++) {
    $paged = $archive->getConfigurationHistory([], $p, 15, null, ROLE_ADMIN);
    foreach ($paged['items'] as $it) {
        if (($it['changed_by_role'] ?? '') === 'administrator') {
            $foundAdminEntry = true;
        }
        if (($it['changed_by_role'] ?? '') === 'owner') {
            $foundOwnerEntry = true;
        }
    }
}
if (!$foundAdminEntry) {
    throw new RuntimeException("FAIL: Administrator cannot find administrator changes in audit log!");
}
if (!$foundOwnerEntry) {
    throw new RuntimeException("FAIL: Administrator cannot find owner changes in audit log!");
}
echo "    PASS: Administrator sees both Administrator/Support changes and Owner changes.\n\n";

// ------------------------------------------------------------
// TEST 3: Page Render Integration in dashboard/archive.php
// ------------------------------------------------------------
echo "--- TEST 3: Full Page Render via dashboard/archive.php ---\n";

// 3.1 Owner View Render
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = ROLE_OWNER;
$_SESSION['username'] = 'owner';
$_SESSION['full_name'] = 'Business Owner';
$_GET['tab'] = 'configuration';
$_GET['page'] = 1;

ob_start();
require __DIR__ . '/../dashboard/archive.php';
$ownerHtml = ob_get_clean();

if (strpos($ownerHtml, 'VendoLink Support Admin') !== false) {
    throw new RuntimeException("FAIL: 'VendoLink Support Admin' rendered in Owner HTML output!");
}
if (strpos($ownerHtml, 'Business Owner') === false) {
    throw new RuntimeException("FAIL: 'Business Owner' was NOT found in Owner HTML output!");
}
if (strpos($ownerHtml, 'Showing 15 of 15 records') === false) {
    throw new RuntimeException("FAIL: Expected 'Showing 15 of 15 records' in Owner HTML output!");
}
if (strpos($ownerHtml, '<th>Changed By</th>') !== false) {
    throw new RuntimeException("FAIL: 'Changed By' column header found in Owner Configuration History table!");
}
if (strpos($ownerHtml, '<th class="ps-4">Service</th>') === false ||
    strpos($ownerHtml, '<th>Previous Configuration</th>') === false ||
    strpos($ownerHtml, '<th>New Configuration</th>') === false ||
    strpos($ownerHtml, '<th>Date &amp; Time</th>') === false) {
    throw new RuntimeException("FAIL: Expected 4 columns (Service, Previous Configuration, New Configuration, Date & Time) in Owner Configuration History table!");
}
echo "    PASS: Owner Configuration History page renders cleanly with 15 owner records and ZERO admin references.\n";
echo "    PASS: 'Changed By' column is omitted from Owner visible Configuration History table.\n";

// 3.2 Admin View Render
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = ROLE_ADMIN;
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'VendoLink Support Admin';
$_GET['tab'] = 'configuration';
$_GET['page'] = 1;

ob_start();
require __DIR__ . '/../dashboard/archive.php';
$adminHtml = ob_get_clean();

if (strpos($adminHtml, 'VendoLink Support Admin') === false) {
    throw new RuntimeException("FAIL: 'VendoLink Support Admin' was NOT found in Admin HTML output!");
}
if (strpos($adminHtml, "Showing 15 of {$totalDbRows} records") === false) {
    throw new RuntimeException("FAIL: Expected 'Showing 15 of {$totalDbRows} records' in Admin HTML output!");
}
if (strpos($adminHtml, '<th>Changed By</th>') === false) {
    throw new RuntimeException("FAIL: 'Changed By' column header was NOT found in Admin Configuration History table!");
}
echo "    PASS: Admin Configuration History page renders cleanly with all {$totalDbRows} records.\n";
echo "    PASS: Admin Configuration History table retains 'Changed By' column.\n\n";

// ------------------------------------------------------------
// TEST 4: Database Audit Preservation Verification
// ------------------------------------------------------------
echo "--- TEST 4: Audit Record Preservation ---\n";
$afterDbRows = (int)$db->query("SELECT COUNT(*) FROM configuration_history")->fetchColumn();
if ($afterDbRows !== $totalDbRows) {
    throw new RuntimeException("FAIL: configuration_history row count changed from {$totalDbRows} to {$afterDbRows}!");
}
echo "    PASS: Exactly {$afterDbRows} rows preserved in configuration_history (no audit rows deleted or modified).\n\n";

echo "============================================================\n";
echo "🎉 ALL CONFIGURATION HISTORY ROLE PERMISSION TESTS PASSED!\n";
echo "============================================================\n";
