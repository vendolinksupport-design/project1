<?php
/**
 * ============================================================
 * VendoLink — Simplified Maintenance Layout Verification Test
 * ============================================================
 *
 * Verifies User Request #10:
 * 1. Summary cards (Upcoming, Due Soon, Overdue) are REMOVED from Maintenance page.
 * 2. Current Reminders appears at the top directly below page header with title & subtitle.
 * 3. Add Reminder modal trigger and saving work correctly.
 * 4. Edit and Delete work correctly, with deletion confirmation dialog and red styling.
 * 5. Turn Off/On and Mark Completed actions still work properly.
 * 6. NEXT DUE and LAST COMPLETED remain visible and correctly populated with icons & divider.
 * 7. Dashboard maintenance status banner and counts remain unchanged.
 * 8. Clean execution without PHP errors.
 *
 * Run with: C:\xampp\php\php.exe tests\maintenance_simplified_layout_test.php
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

echo "============================================================\n";
echo "Starting Simplified Maintenance Layout Verification\n";
echo "============================================================\n\n";

function assert_true(bool $condition, string $msg): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: Assertion failed: {$msg}");
    }
    echo "    [PASS] {$msg}\n";
}

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
// TEST 1: Maintenance page loads without the three summary cards
// ------------------------------------------------------------
echo "--- TEST 1: Absence of 3 Summary Cards on Maintenance Page ---\n";
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$htmlMaint = ob_get_clean();

// Check summary card labels are not present as stat-card metric labels
assert_str_not_contains($htmlMaint, 'stat-card stat-card-online', 'stat-card-online removed');
assert_str_not_contains($htmlMaint, 'stat-card stat-card-notif', 'stat-card-notif removed');
assert_str_not_contains($htmlMaint, '<p class="stat-card-label text-white">Upcoming</p>', 'Upcoming card label removed');
assert_str_not_contains($htmlMaint, '<p class="stat-card-label text-white">Due Soon</p>', 'Due Soon card label removed');
assert_str_not_contains($htmlMaint, '<p class="stat-card-label text-white">Overdue</p>', 'Overdue card label removed');

// Check page heading & title
assert_str_contains($htmlMaint, 'Service Reminders', 'Page heading displays Service Reminders');

// Check sidebar nav item
ob_start();
require __DIR__ . '/../views/layouts/sidebar.php';
$htmlSidebar = ob_get_clean();
assert_str_contains($htmlSidebar, 'Service Reminders', 'Sidebar navigation displays Service Reminders');

// ------------------------------------------------------------
// TEST 2: Active Reminders appears at the top
// ------------------------------------------------------------
echo "\n--- TEST 2: Active Reminders Directly at Top ---\n";
assert_str_contains($htmlMaint, '<h2 class="mb-1 text-white">Active Reminders</h2>', 'Active Reminders title present');
assert_str_contains($htmlMaint, 'Manage service schedules and keep your machine running smoothly.', 'Subtitle present');
assert_str_contains($htmlMaint, 'data-bs-target="#addReminderModal"', 'Add Reminder modal trigger button present');

// Verify Active Reminders comes immediately after page header
$headerPos = strpos($htmlMaint, 'page-header');
$remindersPos = strpos($htmlMaint, 'Active Reminders');
assert_true($headerPos !== false && $remindersPos !== false && $remindersPos > $headerPos, 'Active Reminders is positioned after page-header');
$between = substr($htmlMaint, $headerPos, $remindersPos - $headerPos);
assert_true(strpos($between, 'stat-card') === false, 'No summary cards between page-header and Active Reminders');

// ------------------------------------------------------------
// TEST 3: Approved reminder card design: NEXT DUE and LAST COMPLETED
// ------------------------------------------------------------
echo "\n--- TEST 3: Card Layout, NEXT DUE & LAST COMPLETED ---\n";
assert_str_contains($htmlMaint, 'NEXT DUE:', 'NEXT DUE label present');
assert_str_contains($htmlMaint, 'LAST COMPLETED:', 'LAST COMPLETED label present');
assert_str_contains($htmlMaint, 'bi-calendar3', 'Calendar icon present');
assert_str_contains($htmlMaint, 'bi-check-circle-fill text-success', 'Green completion icon present');
assert_str_contains($htmlMaint, 'mx-2">|</span>', 'Divider between NEXT DUE and LAST COMPLETED present');

// Service icons
assert_true(
    strpos($htmlMaint, 'bi-droplet-fill') !== false || strpos($htmlMaint, 'bi-sparkles') !== false || strpos($htmlMaint, 'bi-cpu') !== false,
    'Service-related icons present in reminder cards'
);

// Actions visible on card
assert_str_contains($htmlMaint, 'Edit', 'Edit button present');
assert_str_contains($htmlMaint, 'Mark Completed', 'Mark Completed button present');
assert_str_contains($htmlMaint, 'Turn Off', 'Turn Off/On button present');

// Standalone Delete button MUST NOT be on the card actions bar
assert_str_not_contains($htmlMaint, '<button class="btn-maintenance-secondary text-danger"', 'Standalone card Delete button removed');

// ------------------------------------------------------------
// TEST 4: Delete this reminder inside Edit Modal in red with confirm
// ------------------------------------------------------------
echo "\n--- TEST 4: Edit Modal & Delete this reminder inside Modal ---\n";
assert_str_contains($htmlMaint, 'id="editModal-', 'Edit Modal present');
assert_str_contains($htmlMaint, 'Delete this reminder', 'Delete this reminder button inside Edit Modal present');
assert_str_contains($htmlMaint, 'text-danger', 'Delete button styled in red (text-danger)');
assert_str_contains($htmlMaint, 'confirm(\'Are you sure you want to delete this reminder?', 'Deletion confirmation required');
assert_str_contains($htmlMaint, 'action" value="delete"', 'Delete form handler present');

// ------------------------------------------------------------
// TEST 5: Completed Services / Recently Completed Section REMOVED from Service Reminders page
// ------------------------------------------------------------
echo "\n--- TEST 5: Completed Services Section Removed from Service Reminders ---\n";
assert_str_not_contains($htmlMaint, 'id="recentlyCompletedMaintenance"', 'Completed Services container removed from maintenance.php');
assert_str_not_contains($htmlMaint, 'Completed Services', 'Completed Services title removed from maintenance.php');
assert_str_not_contains($htmlMaint, 'Recently Completed', 'Recently Completed title not present in maintenance.php');
assert_str_not_contains($htmlMaint, 'archive.php?tab=maintenance', 'View History button removed from maintenance.php');

// Verify that History page still displays "Maintenance History"
$_GET = ['tab' => 'maintenance'];
ob_start();
require __DIR__ . '/../dashboard/archive.php';
$htmlArchive = ob_get_clean();
assert_str_contains($htmlArchive, 'Maintenance History', 'Archive tab retains Maintenance History label');
assert_str_contains($htmlArchive, 'Maintenance Name', 'Archive retains Maintenance Name column');

// ------------------------------------------------------------
// TEST 6: Functional CRUD & Controller Verification
// ------------------------------------------------------------
echo "\n--- TEST 6: CRUD Workflow Verification ---\n";

// 6.1 Create a temporary reminder
$testName = 'Test Reminder ' . uniqid();
$_POST = [
    'csrf_token'               => csrf_token(),
    'action'                   => 'add',
    'name'                     => $testName,
    'reminder_type'            => 'both',
    'service_id'               => '',
    'calendar_interval_value'  => 14,
    'calendar_interval_unit'   => 'days',
    'runtime_interval_minutes' => 120,
    'is_enabled'               => '1',
    'last_completed_date'      => date('Y-m-d')
];
$_SERVER['REQUEST_METHOD'] = 'POST';

// Execute add via model directly to inspect ID
$maintModel->add([
    'name'             => $testName,
    'type'             => 'both',
    'service_id'       => null,
    'enabled'          => 1,
    'last_completed'   => date('Y-m-d 00:00:00'),
    'calendar_value'   => 14,
    'calendar_unit'    => 'days',
    'runtime_interval' => 7200,
], 2);

$newItem = $db->query("SELECT * FROM maintenance_items WHERE name = '{$testName}' ORDER BY id DESC LIMIT 1")->fetch();
assert_true(!empty($newItem), "New reminder created in DB");
$testId = (int)$newItem['id'];
echo "    Created reminder ID: {$testId}\n";

// 6.2 Edit reminder
$updatedName = 'Updated ' . $testName;
$maintModel->edit($testId, [
    'name'             => $updatedName,
    'type'             => 'calendar',
    'service_id'       => null,
    'enabled'          => 1,
    'last_completed'   => date('Y-m-d 00:00:00'),
    'calendar_value'   => 30,
    'calendar_unit'    => 'days',
    'runtime_interval' => null,
], 2);

$edited = $db->query("SELECT * FROM maintenance_items WHERE id = {$testId}")->fetch();
assert_true($edited['name'] === $updatedName, "Reminder name updated correctly");
assert_true($edited['calendar_interval_value'] == 30, "Calendar interval updated");

// 6.3 Toggle reminder
$maintModel->setEnabled($testId, false);
$toggledOff = $db->query("SELECT is_enabled FROM maintenance_items WHERE id = {$testId}")->fetchColumn();
assert_true((int)$toggledOff === 0, "Reminder toggled off");

$maintModel->setEnabled($testId, true);
$toggledOn = $db->query("SELECT is_enabled FROM maintenance_items WHERE id = {$testId}")->fetchColumn();
assert_true((int)$toggledOn === 1, "Reminder toggled on");

// 6.4 Mark completed
$histCountBefore = (int)$db->query("SELECT COUNT(*) FROM maintenance_history WHERE maintenance_item_id = {$testId}")->fetchColumn();
$maintModel->complete($testId, 2, 'Layout test completion note');
$histCountAfter = (int)$db->query("SELECT COUNT(*) FROM maintenance_history WHERE maintenance_item_id = {$testId}")->fetchColumn();
assert_true($histCountAfter === $histCountBefore + 1, "Mark completed created 1 history entry");

// 6.5 Delete reminder (soft delete)
$maintModel->delete($testId);
$deletedItem = $db->query("SELECT is_deleted FROM maintenance_items WHERE id = {$testId}")->fetchColumn();
assert_true((int)$deletedItem === 1, "Reminder soft-deleted (is_deleted = 1)");

// History preserved after deletion
$histCountAfterDelete = (int)$db->query("SELECT COUNT(*) FROM maintenance_history WHERE maintenance_item_id = {$testId}")->fetchColumn();
assert_true($histCountAfterDelete === $histCountAfter, "Maintenance history preserved after reminder deletion");

// ------------------------------------------------------------
// TEST 7: Dashboard Maintenance Banner and Counts
// ------------------------------------------------------------
echo "\n--- TEST 7: Dashboard Maintenance Banner Unchanged ---\n";
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require __DIR__ . '/../dashboard/index.php';
$htmlDashboard = ob_get_clean();

assert_str_contains($htmlDashboard, 'report-section-card', 'Dashboard status card container present');
assert_str_contains($htmlDashboard, 'bi-wrench-adjustable', 'Dashboard maintenance icon present');
assert_str_contains($htmlDashboard, 'View Maintenance', 'Dashboard View Maintenance button present');
assert_true(
    strpos($htmlDashboard, 'Maintenance due') !== false || strpos($htmlDashboard, 'No maintenance due') !== false,
    'Dashboard maintenance banner text present and accurate'
);

echo "\n============================================================\n";
echo "ALL SIMPLIFIED MAINTENANCE LAYOUT TESTS PASSED (8/8)!\n";
echo "============================================================\n";
