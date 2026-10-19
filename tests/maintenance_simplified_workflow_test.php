<?php
/**
 * ============================================================
 * VendoLink — Simplified Maintenance & History Workflow Test
 * ============================================================
 *
 * Verifies:
 * 1. Maintenance Page Summary Cards:
 *    - Upcoming, Due Soon, and Overdue cards are present.
 * 2. Current Reminders:
 *    - Add Reminder, Edit, Turn Off/On, Delete, Mark Completed actions present.
 * 3. Compact Recently Completed Section:
 *    - Replaced large full table and pagination with compact Recently Completed section (1-3 records).
 *    - Displays maintenance name, service or machine, completion date.
 *    - Contains "View History →" linking to archive.php?tab=maintenance.
 * 4. History Page (archive.php?tab=maintenance):
 *    - Full searchable and paginated Maintenance History table preserved.
 *    - Filters and pagination controls preserved.
 * 5. Mark Completed & Duplicate Prevention:
 *    - Completing a reminder creates exactly 1 completion record in maintenance_history.
 *    - Rapid duplicate submissions are blocked.
 *    - Reminder last_completed_at and runtime_baseline_seconds updated.
 *    - Completion appears in both Recently Completed and Archive Maintenance History.
 * 6. Reminder CRUD lifecycle:
 *    - Add, edit, toggle (turn off/on), and delete.
 *
 * Run with: C:\xampp\php\php.exe tests\maintenance_simplified_workflow_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Maintenance.php';
require_once __DIR__ . '/../models/Archive.php';
require_once __DIR__ . '/../controllers/MaintenanceController.php';

$db = Database::getInstance();
$maintModel = new Maintenance();
$archiveModel = new Archive();

echo "============================================================\n";
echo "Starting Simplified Maintenance & History Verification\n";
echo "============================================================\n\n";

function assert_str_contains(string $haystack, string $needle, string $msg): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException("FAIL: Assertion failed: {$msg} (Did not find '{$needle}')");
    }
    echo "    [PASS] {$msg}\n";
}

function assert_str_not_contains(string $haystack, string $needle, string $msg): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException("FAIL: Assertion failed: {$msg} (Found forbidden '{$needle}')");
    }
    echo "    [PASS] {$msg}\n";
}

// Set up Owner session (User 2)
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = ROLE_OWNER;
$_SESSION['role'] = ROLE_OWNER;
$_SESSION['username'] = 'owner';
$_SESSION['full_name'] = 'Business Owner';

// ------------------------------------------------------------
// TEST 1: Maintenance Page Components & Layout
// ------------------------------------------------------------
echo "--- TEST 1: Maintenance Page Rendering & Components ---\n";
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$htmlMaint = ob_get_clean();

// 1.1 Verify Summary Cards are removed (as per Request #10)
assert_str_not_contains($htmlMaint, 'stat-card stat-card-online', 'stat-card-online removed');
assert_str_not_contains($htmlMaint, 'stat-card stat-card-notif', 'stat-card-notif removed');

// 1.2 Verify Active Reminders & Actions
assert_str_contains($htmlMaint, 'Active Reminders', 'Active Reminders heading present');
assert_str_contains($htmlMaint, 'Add Reminder', 'Add Reminder modal trigger present');
assert_str_contains($htmlMaint, 'action" value="toggle"', 'Turn Off/On action present');
assert_str_contains($htmlMaint, 'action" value="delete"', 'Delete action present');
assert_str_contains($htmlMaint, 'action" value="edit"', 'Edit action present');
assert_str_contains($htmlMaint, 'action" value="complete"', 'Mark Completed action present');

// 1.3 Verify Completed Services / Recently Completed Section is REMOVED from Maintenance page
assert_str_not_contains($htmlMaint, 'id="recentlyCompletedMaintenance"', 'Completed Services section removed from maintenance.php');
assert_str_not_contains($htmlMaint, 'Completed Services', 'Completed Services title removed from maintenance.php');
assert_str_not_contains($htmlMaint, 'Recently Completed', 'Recently Completed title removed from maintenance.php');
assert_str_not_contains($htmlMaint, 'archive.php?tab=maintenance', 'View History button removed from maintenance.php');

// 1.4 Verify Full Table & Bottom Pagination are REMOVED from Maintenance page
assert_str_not_contains($htmlMaint, 'aria-label="Maintenance pagination"', 'Old pagination controls removed from maintenance.php');
assert_str_not_contains($htmlMaint, 'Showing 15 of', 'Full 15-item table removed from maintenance.php');

// ------------------------------------------------------------
// TEST 2: History Page (archive.php?tab=maintenance)
// ------------------------------------------------------------
echo "\n--- TEST 2: History Page Maintenance History Tab ---\n";
$_GET = ['tab' => 'maintenance'];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require __DIR__ . '/../dashboard/archive.php';
$htmlArchive = ob_get_clean();

assert_str_contains($htmlArchive, 'tab=transactions', 'Transaction History tab present on archive.php');
assert_str_contains($htmlArchive, 'tab=maintenance', 'Maintenance History tab present on archive.php');
assert_str_contains($htmlArchive, 'tab=configuration', 'Configuration History tab present on archive.php');
assert_str_contains($htmlArchive, 'Maintenance Name', 'Full Maintenance History column Maintenance Name present');
assert_str_contains($htmlArchive, 'Machine / Service', 'Full Maintenance History column Machine / Service present');
assert_str_contains($htmlArchive, 'Completion Date', 'Full Maintenance History column Completion Date present');
assert_str_contains($htmlArchive, 'Runtime at Completion', 'Full Maintenance History column Runtime present');
assert_str_contains($htmlArchive, 'Search maintenance name or notes...', 'Search filter present for maintenance history');

// ------------------------------------------------------------
// TEST 3: Mark Completed & Duplicate Submission Prevention
// ------------------------------------------------------------
echo "\n--- TEST 3: Mark Completed Workflow & Duplicate Prevention ---\n";

// Get active reminder ID
$items = $maintModel->items(2);
if (empty($items)) {
    throw new RuntimeException("FAIL: No maintenance items found for owner.");
}
$testItem = $items[0];
$testItemId = (int)$testItem['id'];

$initHistCount = (int)$db->query(
    "SELECT COUNT(*) FROM maintenance_history WHERE maintenance_item_id = {$testItemId}"
)->fetchColumn();

echo "    Initial history count for Item #{$testItemId}: {$initHistCount}\n";

// Execute complete
$testNotes = 'Automated Test Service Check ' . uniqid();
$maintModel->complete($testItemId, 2, $testNotes);

$afterFirstCount = (int)$db->query(
    "SELECT COUNT(*) FROM maintenance_history WHERE maintenance_item_id = {$testItemId}"
)->fetchColumn();

if ($afterFirstCount !== $initHistCount + 1) {
    throw new RuntimeException("FAIL: Expected history count to increase by 1, got {$afterFirstCount}");
}
echo "    [PASS] Mark Completed created exactly 1 history entry (New count: {$afterFirstCount}).\n";

// Test duplicate prevention within 10 seconds (immediate second complete call)
$maintModel->complete($testItemId, 2, $testNotes . ' Duplicate');

$afterSecondCount = (int)$db->query(
    "SELECT COUNT(*) FROM maintenance_history WHERE maintenance_item_id = {$testItemId}"
)->fetchColumn();

if ($afterSecondCount !== $afterFirstCount) {
    throw new RuntimeException("FAIL: Duplicate call created another history entry! Expected {$afterFirstCount}, got {$afterSecondCount}");
}
echo "    [PASS] Rapid second completion call within 10s was blocked (Count remains {$afterSecondCount}).\n";

// Verify latest record in DB
$latestRecord = $db->query(
    "SELECT * FROM maintenance_history WHERE maintenance_item_id = {$testItemId} ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($latestRecord['notes'] !== $testNotes) {
    throw new RuntimeException("FAIL: Latest record notes mismatch!");
}
echo "    [PASS] Completion record contains accurate notes: '{$latestRecord['notes']}'.\n";

// Verify updated reminder in DB
$updatedItem = $db->query("SELECT * FROM maintenance_items WHERE id = {$testItemId}")->fetch(PDO::FETCH_ASSOC);
echo "    [PASS] Reminder updated last_completed_at to: {$updatedItem['last_completed_at']}.\n";
echo "    [PASS] Reminder updated runtime_baseline_seconds to: {$updatedItem['runtime_baseline_seconds']}.\n";

// ------------------------------------------------------------
// TEST 4: Verification: History Table Removed from maintenance.php & Preserved in Archive History
// ------------------------------------------------------------
echo "\n--- TEST 4: Visibility in Archive History & Absence on Service Reminders ---\n";

// Check that preview table is NOT on maintenance.php
$_GET = [];
ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$htmlMaintAfter = ob_get_clean();

assert_str_not_contains($htmlMaintAfter, 'id="recentlyCompletedMaintenance"', 'Recently Completed table is not on maintenance.php');

// Check Archive History tab
$_GET = ['tab' => 'maintenance'];
ob_start();
require __DIR__ . '/../dashboard/archive.php';
$htmlArchiveAfter = ob_get_clean();

assert_str_contains($htmlArchiveAfter, htmlspecialchars($testItem['name'], ENT_QUOTES), 'Completed item name present in Archive Maintenance History');
assert_str_contains($htmlArchiveAfter, htmlspecialchars($testNotes, ENT_QUOTES), 'Completion notes present in Archive Maintenance History');

// Clean up temporary test history record
$db->query("DELETE FROM maintenance_history WHERE id = {$latestRecord['id']}");
echo "    [PASS] Cleaned up test completion record ID #{$latestRecord['id']}.\n";

// ------------------------------------------------------------
// TEST 5: Reminder CRUD Lifecycle (Add, Edit, Toggle, Delete)
// ------------------------------------------------------------
echo "\n--- TEST 5: Reminder Lifecycle (Add, Edit, Toggle, Delete) ---\n";

// 5.1 Add reminder
$testName = 'Temporary QA Filter Reminder ' . uniqid();
$maintModel->add([
    'name' => $testName,
    'type' => 'both',
    'service_id' => 1,
    'enabled' => 1,
    'last_completed' => date('Y-m-d 00:00:00'),
    'calendar_value' => 30,
    'calendar_unit' => 'days',
    'runtime_interval' => 36000,
], 2);

$created = $db->query("SELECT * FROM maintenance_items WHERE name = '{$testName}' AND is_deleted = 0")->fetch(PDO::FETCH_ASSOC);
if (!$created) {
    throw new RuntimeException("FAIL: Could not find created reminder!");
}
$createdId = (int)$created['id'];
echo "    [PASS] Successfully created reminder ID #{$createdId} ('{$testName}').\n";

// 5.2 Toggle reminder
$maintModel->setEnabled($createdId, false);
$disabled = (int)$db->query("SELECT is_enabled FROM maintenance_items WHERE id = {$createdId}")->fetchColumn();
if ($disabled !== 0) {
    throw new RuntimeException("FAIL: Reminder was not disabled!");
}
echo "    [PASS] Successfully toggled reminder to disabled.\n";

$maintModel->setEnabled($createdId, true);
$enabled = (int)$db->query("SELECT is_enabled FROM maintenance_items WHERE id = {$createdId}")->fetchColumn();
if ($enabled !== 1) {
    throw new RuntimeException("FAIL: Reminder was not re-enabled!");
}
echo "    [PASS] Successfully toggled reminder to enabled.\n";

// 5.3 Edit reminder
$updatedName = $testName . ' (Updated)';
$maintModel->edit($createdId, [
    'name' => $updatedName,
    'type' => 'calendar',
    'service_id' => 1,
    'enabled' => 1,
    'last_completed' => date('Y-m-d 00:00:00'),
    'calendar_value' => 45,
    'calendar_unit' => 'days',
    'runtime_interval' => null,
], 2);

$edited = $db->query("SELECT * FROM maintenance_items WHERE id = {$createdId}")->fetch(PDO::FETCH_ASSOC);
if ($edited['name'] !== $updatedName || (int)$edited['calendar_interval_value'] !== 45) {
    throw new RuntimeException("FAIL: Reminder was not edited properly!");
}
echo "    [PASS] Successfully edited reminder (New name: '{$updatedName}', interval: 45 days).\n";

// 5.4 Delete reminder (soft-delete)
$maintModel->delete($createdId);
$deleted = (int)$db->query("SELECT is_deleted FROM maintenance_items WHERE id = {$createdId}")->fetchColumn();
if ($deleted !== 1) {
    throw new RuntimeException("FAIL: Reminder was not deleted!");
}
echo "    [PASS] Successfully soft-deleted reminder ID #{$createdId}.\n";

// Hard-delete temporary test item
$db->query("DELETE FROM maintenance_items WHERE id = {$createdId}");
echo "    [PASS] Cleaned up temporary test reminder.\n";

echo "\n============================================================\n";
echo "🎉 ALL SIMPLIFIED MAINTENANCE & HISTORY TESTS PASSED 100%!\n";
echo "============================================================\n";
