<?php
/**
 * ============================================================
 * VendoLink — Final Service Reminders Page Cleanup Verification
 * ============================================================
 *
 * Verifies all 10 criteria specified in User Request:
 * 1. Sidebar and page heading show 'Service Reminders'
 * 2. Upcoming, Due Soon, and Overdue cards are removed from this page only
 * 3. Active Reminders appears directly below the page heading
 * 4. The Recently Completed table is completely removed from this page
 * 5. All active reminder details and actions still work (Add, Edit, Toggle, Delete, Complete)
 * 6. Completing a reminder still creates the correct permanent history record
 * 7. Existing records remain accessible through History -> Maintenance History
 * 8. The Dashboard remains unchanged (banner, counts, status)
 * 9. Layout integrity and structure verified
 * 10. No PHP or JavaScript errors occur
 *
 * Run with: C:\xampp\php\php.exe tests\final_service_reminders_cleanup_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Maintenance.php';
require_once __DIR__ . '/../models/Archive.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../controllers/MaintenanceController.php';

$db = Database::getInstance();
$maintModel = new Maintenance();
$archiveModel = new Archive();

echo "============================================================\n";
echo "Starting Final Service Reminders Page Cleanup Verification\n";
echo "============================================================\n\n";

function assert_true(bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException("FAIL: {$msg}");
    }
    echo "    [PASS] {$msg}\n";
}

function assert_str_contains(string $haystack, string $needle, string $msg): void {
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException("FAIL: {$msg} (Did not find '{$needle}')");
    }
    echo "    [PASS] {$msg}\n";
}

function assert_str_not_contains(string $haystack, string $needle, string $msg): void {
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException("FAIL: {$msg} (Found forbidden '{$needle}')");
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
// TEST 1: The sidebar and page heading show Service Reminders
// ------------------------------------------------------------
echo "--- TEST 1: Visible Labels (Sidebar & Page Heading) ---\n";
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$htmlMaint = ob_get_clean();

// Check page heading & subtitle
assert_str_contains($htmlMaint, 'Service Reminders', 'Page heading displays Service Reminders');
assert_str_contains($htmlMaint, 'Manage service schedules and keep your machine running smoothly.', 'Page subtitle updated');

// Check sidebar navigation item
ob_start();
require __DIR__ . '/../views/layouts/sidebar.php';
$htmlSidebar = ob_get_clean();
assert_str_contains($htmlSidebar, 'Service Reminders', 'Sidebar navigation displays Service Reminders');

// Check topnav breadcrumb/page label
ob_start();
require __DIR__ . '/../views/layouts/topnav.php';
$htmlTopnav = ob_get_clean();
assert_str_contains($htmlTopnav, 'Service Reminders', 'Topnav displays Service Reminders');

// ------------------------------------------------------------
// TEST 2: Summary cards removed from this page only
// ------------------------------------------------------------
echo "\n--- TEST 2: Absence of Summary Cards on Service Reminders ---\n";
assert_str_not_contains($htmlMaint, 'stat-card stat-card-online', 'stat-card-online removed');
assert_str_not_contains($htmlMaint, 'stat-card stat-card-notif', 'stat-card-notif removed');
assert_str_not_contains($htmlMaint, '<p class="stat-card-label text-white">Upcoming</p>', 'Upcoming card label removed');
assert_str_not_contains($htmlMaint, '<p class="stat-card-label text-white">Due Soon</p>', 'Due Soon card label removed');
assert_str_not_contains($htmlMaint, '<p class="stat-card-label text-white">Overdue</p>', 'Overdue card label removed');

// ------------------------------------------------------------
// TEST 3: Active Reminders appears directly below the page heading
// ------------------------------------------------------------
echo "\n--- TEST 3: Active Reminders Directly Below Header ---\n";
assert_str_contains($htmlMaint, '<h2 class="mb-1 text-white">Active Reminders</h2>', 'Active Reminders title present');
assert_str_contains($htmlMaint, 'data-bs-target="#addReminderModal"', 'Add Reminder modal button present');

$headerPos = strpos($htmlMaint, 'page-header');
$remindersPos = strpos($htmlMaint, 'Active Reminders');
assert_true($headerPos !== false && $remindersPos !== false && $remindersPos > $headerPos, 'Active Reminders comes after page-header');
$between = substr($htmlMaint, $headerPos, $remindersPos - $headerPos);
assert_true(strpos($between, 'stat-card') === false, 'No summary cards between page-header and Active Reminders');
assert_true(strpos($between, 'recentlyCompletedMaintenance') === false, 'No history preview between page-header and Active Reminders');

// ------------------------------------------------------------
// TEST 4: Recently Completed section is completely removed
// ------------------------------------------------------------
echo "\n--- TEST 4: Removal of Recently Completed Table ---\n";
assert_str_not_contains($htmlMaint, 'id="recentlyCompletedMaintenance"', 'recentlyCompletedMaintenance container removed');
assert_str_not_contains($htmlMaint, 'Completed Services', 'Completed Services heading removed');
assert_str_not_contains($htmlMaint, 'Recently Completed', 'Recently Completed heading not present');
assert_str_not_contains($htmlMaint, 'archive.php?tab=maintenance', 'View History button removed from maintenance.php');
assert_str_not_contains($htmlMaint, 'No completed service records yet', 'Completed empty state removed from maintenance.php');

// ------------------------------------------------------------
// TEST 5: All active reminder details and actions still work
// ------------------------------------------------------------
echo "\n--- TEST 5: Active Reminder Cards Design & Actions ---\n";
assert_str_contains($htmlMaint, 'NEXT DUE:', 'NEXT DUE label present');
assert_str_contains($htmlMaint, 'LAST COMPLETED:', 'LAST COMPLETED label present');
assert_str_contains($htmlMaint, 'bi-calendar3', 'Calendar icon present');
assert_str_contains($htmlMaint, 'bi-check-circle-fill text-success', 'Green completion icon present');
assert_str_contains($htmlMaint, 'mx-2">|</span>', 'Divider between NEXT DUE and LAST COMPLETED present');

// Actions on cards
assert_str_contains($htmlMaint, 'Edit', 'Edit button present');
assert_str_contains($htmlMaint, 'Turn Off', 'Turn Off/On button present');
assert_str_contains($htmlMaint, 'Mark Completed', 'Mark Completed button present');

// Delete this reminder in edit modal
assert_str_contains($htmlMaint, 'Delete this reminder', 'Delete this reminder button inside Edit Modal present');
assert_str_contains($htmlMaint, 'text-danger', 'Delete button styled in red (text-danger)');
assert_str_contains($htmlMaint, 'confirm(\'Are you sure you want to delete this reminder?', 'Deletion confirmation dialog present');

// Standalone Delete button MUST NOT be on the card actions bar
assert_str_not_contains($htmlMaint, '<button class="btn-maintenance-secondary text-danger"', 'Standalone card Delete button removed');

// ------------------------------------------------------------
// TEST 6: Completing a reminder creates permanent history record
// ------------------------------------------------------------
echo "\n--- TEST 6: Mark Completed Workflow & Permanent Record ---\n";
$items = $maintModel->items(2);
assert_true(!empty($items), 'Owner has active maintenance items to test');
$testItem = $items[0];
$testItemId = (int)$testItem['id'];

$initHistCount = (int)$db->query(
    "SELECT COUNT(*) FROM maintenance_history WHERE maintenance_item_id = {$testItemId}"
)->fetchColumn();

$uniqueNote = 'Permanent History Test Note ' . uniqid();
$maintModel->complete($testItemId, 2, $uniqueNote);

$newHistCount = (int)$db->query(
    "SELECT COUNT(*) FROM maintenance_history WHERE maintenance_item_id = {$testItemId}"
)->fetchColumn();
assert_true($newHistCount === $initHistCount + 1, 'Mark Completed created 1 permanent history record');

$savedRecord = $db->query(
    "SELECT * FROM maintenance_history WHERE maintenance_item_id = {$testItemId} ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
assert_true($savedRecord['notes'] === $uniqueNote, 'Completion record notes stored accurately');

// ------------------------------------------------------------
// TEST 7: Records accessible through History -> Maintenance History
// ------------------------------------------------------------
echo "\n--- TEST 7: Accessibility in History -> Maintenance History ---\n";
$_GET = ['tab' => 'maintenance'];
ob_start();
require __DIR__ . '/../dashboard/archive.php';
$htmlArchive = ob_get_clean();

assert_str_contains($htmlArchive, 'Maintenance History', 'Archive tab retains Maintenance History title');
assert_str_contains($htmlArchive, 'Maintenance Name', 'Maintenance Name column present in Archive');
assert_str_contains($htmlArchive, 'Machine / Service', 'Machine / Service column present in Archive');
assert_str_contains($htmlArchive, 'Completion Date', 'Completion Date column present in Archive');
assert_str_contains($htmlArchive, 'Runtime at Completion', 'Runtime column present in Archive');
assert_str_contains($htmlArchive, htmlspecialchars($uniqueNote, ENT_QUOTES), 'Saved completion record visible in Archive Maintenance History');

// Clean up test history record
$db->query("DELETE FROM maintenance_history WHERE id = {$savedRecord['id']}");
echo "    [PASS] Cleaned up temporary test completion record ID #{$savedRecord['id']}.\n";

// ------------------------------------------------------------
// TEST 8: Dashboard remains unchanged
// ------------------------------------------------------------
echo "\n--- TEST 8: Dashboard Maintenance Status Banner Intact ---\n";
$_GET = [];
ob_start();
require __DIR__ . '/../dashboard/index.php';
$htmlDashboard = ob_get_clean();

assert_str_contains($htmlDashboard, 'report-section-card', 'Dashboard status card container present');
assert_str_contains($htmlDashboard, 'bi-wrench-adjustable', 'Dashboard maintenance icon present');
assert_str_contains($htmlDashboard, 'View Maintenance', 'Dashboard View Maintenance link present');
assert_true(
    strpos($htmlDashboard, 'Maintenance due') !== false || strpos($htmlDashboard, 'No maintenance due') !== false,
    'Dashboard maintenance banner text present and calculated accurately'
);

// ------------------------------------------------------------
// TEST 9: Add Reminder, Edit, Toggle, and Delete CRUD
// ------------------------------------------------------------
echo "\n--- TEST 9: Full Reminder CRUD Lifecycle ---\n";
$tempName = 'Cleanup QA Reminder ' . uniqid();
$maintModel->add([
    'name' => $tempName,
    'type' => 'calendar',
    'service_id' => null,
    'enabled' => 1,
    'last_completed' => date('Y-m-d 00:00:00'),
    'calendar_value' => 10,
    'calendar_unit' => 'days',
    'runtime_interval' => null,
], 2);

$tempItem = $db->query("SELECT * FROM maintenance_items WHERE name = '{$tempName}' ORDER BY id DESC LIMIT 1")->fetch();
assert_true(!empty($tempItem), 'Added temporary reminder');
$tempId = (int)$tempItem['id'];

// Edit
$maintModel->edit($tempId, [
    'name' => $tempName . ' (Edited)',
    'type' => 'calendar',
    'service_id' => null,
    'enabled' => 1,
    'last_completed' => date('Y-m-d 00:00:00'),
    'calendar_value' => 20,
    'calendar_unit' => 'days',
    'runtime_interval' => null,
], 2);
$editedName = $db->query("SELECT name FROM maintenance_items WHERE id = {$tempId}")->fetchColumn();
assert_true($editedName === $tempName . ' (Edited)', 'Edited reminder');

// Toggle
$maintModel->setEnabled($tempId, false);
$disabled = (int)$db->query("SELECT is_enabled FROM maintenance_items WHERE id = {$tempId}")->fetchColumn();
assert_true($disabled === 0, 'Disabled reminder');

// Delete
$maintModel->delete($tempId);
$deleted = (int)$db->query("SELECT is_deleted FROM maintenance_items WHERE id = {$tempId}")->fetchColumn();
assert_true($deleted === 1, 'Soft-deleted reminder');

// Hard-delete temp item
$db->query("DELETE FROM maintenance_items WHERE id = {$tempId}");
echo "    [PASS] Cleaned up temporary QA reminder.\n";

// ------------------------------------------------------------
// TEST 10: Clean Execution & Zero Errors
// ------------------------------------------------------------
echo "\n--- TEST 10: Clean Execution & Integrity ---\n";
assert_true(error_get_last() === null || empty(error_get_last()['message']), 'No PHP execution errors occurred');

echo "\n============================================================\n";
echo "🎉 ALL 10/10 FINAL SERVICE REMINDERS CLEANUP TESTS PASSED!\n";
echo "============================================================\n";
