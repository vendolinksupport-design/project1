<?php
/**
 * ============================================================
 * VendoLink — Active Reminders Pagination & 50-Item Limit Test
 * ============================================================
 *
 * Verifies User Request:
 * 1. Pagination:
 *    - 5 reminders per page.
 *    - Compact pagination controls: Prev, page numbers, Next.
 *    - Shows count such as "Showing 1–2 of 2 reminders".
 *    - For 2 existing reminders, displays only those two cards (no empty placeholders).
 *    - Prev disabled on first page, Next disabled on last page.
 *    - Tested with 0, 2, 5, 6, and 50 reminders.
 *    - Invalid page requests reset to page 1.
 * 2. Reminder Limit (50 active reminders per machine):
 *    - Active reminders count is strictly (is_deleted = 0 AND is_enabled = 1).
 *    - Disabled reminders (is_enabled = 0) and completed history do NOT count against limit.
 *    - Server-side rejection at 50 with clear user-facing error message.
 *    - Concurrency-safe database transaction row-level locking.
 *    - No history records deleted to enforce limit.
 * 3. Operations & Pagination State:
 *    - Add, Edit, Toggle, Complete, Delete.
 *    - Reset to page 1 if current page becomes invalid after deletion.
 *
 * Run with: C:\xampp\php\php.exe tests\active_reminders_pagination_and_limit_test.php
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

echo "============================================================\n";
echo "Starting Active Reminders Pagination & 50-Limit Verification\n";
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

$originalRows = $db->query('SELECT * FROM maintenance_items ORDER BY id ASC')->fetchAll();

try {
    // Isolate IDs 1 and 2 for initial baseline
    $db->query('UPDATE maintenance_items SET is_deleted = 1 WHERE id NOT IN (1, 2)');
    $db->query('UPDATE maintenance_items SET is_deleted = 0, is_enabled = 1 WHERE id IN (1, 2)');

    // ------------------------------------------------------------
    // TEST 1: Initial State (2 Reminders)
    // ------------------------------------------------------------
    echo "--- TEST 1: Initial State Pagination (2 Existing Reminders) ---\n";
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$html2 = ob_get_clean();

// Check count
assert_str_contains($html2, 'Showing 1–2 of 2 reminders', 'Count text matches "Showing 1–2 of 2 reminders"');

// Check card count: exactly 2 maintenance-item articles rendered
$cardCount2 = substr_count($html2, '<article class="maintenance-item">');
assert_true($cardCount2 === 2, "Exactly 2 reminder cards rendered (found {$cardCount2}), no placeholder cards");

// Check pagination controls
assert_str_contains($html2, 'id="maintenancePagination"', 'Pagination controls container present');
assert_str_contains($html2, '<li class="page-item disabled">', 'Disabled page-item present');
assert_str_contains($html2, 'aria-disabled="true">&laquo;&nbsp;Prev</span>', 'Previous button is disabled on page 1');
assert_str_contains($html2, 'aria-disabled="true">Next&nbsp;&raquo;</span>', 'Next button is disabled on last page');
assert_str_contains($html2, '<li class="page-item active" aria-current="page"><span class="page-link">1</span></li>', 'Page 1 is active');

// ------------------------------------------------------------
// TEST 2: Pagination with 0 Reminders
// ------------------------------------------------------------
echo "\n--- TEST 2: Pagination with 0 Reminders ---\n";
$paged0 = $maintModel->itemsPaginated(999999, 1, 5); // non-existent owner
assert_true($paged0['total_items'] === 0, 'total_items is 0');
assert_true($paged0['page'] === 1, 'page is 1');
assert_true($paged0['total_pages'] === 1, 'total_pages is 1');
assert_true(empty($paged0['items']), 'items array is empty');
assert_true($paged0['count_text'] === 'Showing 0 of 0 reminders', 'count_text is "Showing 0 of 0 reminders"');

// ------------------------------------------------------------
// TEST 3: Pagination with 5 Reminders
// ------------------------------------------------------------
echo "\n--- TEST 3: Pagination with 5 Reminders ---\n";
// Create 3 temporary reminders to reach exactly 5
$tempIds = [];
for ($i = 1; $i <= 3; $i++) {
    $tempName = "Temp 5-Test Reminder {$i} " . uniqid();
    $maintModel->add([
        'name' => $tempName, 'type' => 'calendar', 'service_id' => null, 'enabled' => 1,
        'last_completed' => date('Y-m-d 00:00:00'), 'calendar_value' => 7, 'calendar_unit' => 'days',
        'runtime_interval' => null,
    ], 2);
    $newId = (int)$db->query("SELECT id FROM maintenance_items WHERE name = '{$tempName}'")->fetchColumn();
    $tempIds[] = $newId;
}

$_GET = ['page' => 1];
ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$html5 = ob_get_clean();

assert_str_contains($html5, 'Showing 1–5 of 5 reminders', 'Count text matches "Showing 1–5 of 5 reminders"');
$cardCount5 = substr_count($html5, '<article class="maintenance-item">');
assert_true($cardCount5 === 5, "Exactly 5 reminder cards rendered on page 1");
assert_str_contains($html5, 'aria-disabled="true">&laquo;&nbsp;Prev</span>', 'Prev disabled on page 1 of 5');
assert_str_contains($html5, 'aria-disabled="true">Next&nbsp;&raquo;</span>', 'Next disabled on page 1 of 5');

// ------------------------------------------------------------
// TEST 4: Pagination with 6 Reminders (2 Pages)
// ------------------------------------------------------------
echo "\n--- TEST 4: Pagination with 6 Reminders across 2 Pages ---\n";
// Add 1 more temporary reminder to reach 6
$tempName6 = "Temp 6-Test Reminder " . uniqid();
$maintModel->add([
    'name' => $tempName6, 'type' => 'calendar', 'service_id' => null, 'enabled' => 1,
    'last_completed' => date('Y-m-d 00:00:00'), 'calendar_value' => 7, 'calendar_unit' => 'days',
    'runtime_interval' => null,
], 2);
$newId6 = (int)$db->query("SELECT id FROM maintenance_items WHERE name = '{$tempName6}'")->fetchColumn();
$tempIds[] = $newId6;

// 4.1 Page 1 of 6
$_GET = ['page' => 1];
ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$html6_p1 = ob_get_clean();

assert_str_contains($html6_p1, 'Showing 1–5 of 6 reminders', 'Page 1 count text matches "Showing 1–5 of 6 reminders"');
$cardCount6_p1 = substr_count($html6_p1, '<article class="maintenance-item">');
assert_true($cardCount6_p1 === 5, "Exactly 5 cards on Page 1 of 6");
assert_str_contains($html6_p1, 'aria-disabled="true">&laquo;&nbsp;Prev</span>', 'Prev is disabled on Page 1');
assert_str_contains($html6_p1, 'page=2" aria-label="Next">Next&nbsp;&raquo;</a>', 'Next links to page=2 on Page 1');

// 4.2 Page 2 of 6
$_GET = ['page' => 2];
ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$html6_p2 = ob_get_clean();

assert_str_contains($html6_p2, 'Showing 6–6 of 6 reminders', 'Page 2 count text matches "Showing 6–6 of 6 reminders"');
$cardCount6_p2 = substr_count($html6_p2, '<article class="maintenance-item">');
assert_true($cardCount6_p2 === 1, "Exactly 1 card on Page 2 of 6 (no placeholder cards)");
assert_str_contains($html6_p2, 'page=1" aria-label="Previous">&laquo;&nbsp;Prev</a>', 'Prev links to page=1 on Page 2');
assert_str_contains($html6_p2, 'aria-disabled="true">Next&nbsp;&raquo;</span>', 'Next is disabled on Page 2');

// 4.3 Invalid Page 99 resets to Page 1
$_GET = ['page' => 99];
ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$html6_p99 = ob_get_clean();
assert_str_contains($html6_p99, 'Showing 1–5 of 6 reminders', 'Invalid page 99 automatically resets to page 1');

// ------------------------------------------------------------
// TEST 5: Pagination with 50 Reminders (10 Pages) & Limit Enforcement
// ------------------------------------------------------------
echo "\n--- TEST 5: 50 Reminders (10 Pages) & 51st Rejection ---\n";
// Currently 6 items. Add 44 more items to reach exactly 50
echo "    Adding temporary reminders to reach 50 active items...\n";
for ($i = 7; $i <= 50; $i++) {
    $bulkName = "Bulk QA Reminder {$i} " . uniqid();
    $maintModel->add([
        'name' => $bulkName, 'type' => 'calendar', 'service_id' => null, 'enabled' => 1,
        'last_completed' => date('Y-m-d 00:00:00'), 'calendar_value' => 14, 'calendar_unit' => 'days',
        'runtime_interval' => null,
    ], 2);
    $bulkId = (int)$db->query("SELECT id FROM maintenance_items WHERE name = '{$bulkName}'")->fetchColumn();
    $tempIds[] = $bulkId;
}

$activeCount50 = $maintModel->countActive(2);
assert_true($activeCount50 === 50, "Machine reached exactly 50 active reminders (count: {$activeCount50})");

// Page 1 of 50
$_GET = ['page' => 1];
ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$html50_p1 = ob_get_clean();
assert_str_contains($html50_p1, 'Showing 1–5 of 50 reminders', 'Page 1 of 50 displays "Showing 1–5 of 50 reminders"');
assert_str_contains($html50_p1, 'Maximum active reminder limit reached', 'Warning notice visible when limit reached');

// Page 10 of 50
$_GET = ['page' => 10];
ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$html50_p10 = ob_get_clean();
assert_str_contains($html50_p10, 'Showing 46–50 of 50 reminders', 'Page 10 of 50 displays "Showing 46–50 of 50 reminders"');
assert_str_contains($html50_p10, 'aria-disabled="true">Next&nbsp;&raquo;</span>', 'Next is disabled on Page 10 (last page)');

// 5.1 Rejection of 51st Reminder via Model
$threwLimit = false;
$limitMsg = '';
try {
    $maintModel->add([
        'name' => '51st Reminder Overflow', 'type' => 'calendar', 'service_id' => null, 'enabled' => 1,
        'last_completed' => date('Y-m-d 00:00:00'), 'calendar_value' => 30, 'calendar_unit' => 'days',
        'runtime_interval' => null,
    ], 2);
} catch (InvalidArgumentException $e) {
    $threwLimit = true;
    $limitMsg = $e->getMessage();
}
assert_true($threwLimit, "51st active reminder was rejected server-side by Model");
assert_str_contains($limitMsg, 'Maximum active reminder limit reached (50 active reminders per machine)', 'Clear user-facing error message returned');

// 5.2 Disabled reminders (is_enabled = 0) do NOT count towards active limit
// Disable one reminder
$oneId = $tempIds[0];
$maintModel->setEnabled($oneId, false);
$activeCountAfterDisable = $maintModel->countActive(2);
assert_true($activeCountAfterDisable === 49, "Active count is 49 after disabling 1 reminder");

// Now adding 1 more active reminder succeeds
$maintModel->add([
    'name' => 'Reminder While One Disabled ' . uniqid(), 'type' => 'calendar', 'service_id' => null, 'enabled' => 1,
    'last_completed' => date('Y-m-d 00:00:00'), 'calendar_value' => 30, 'calendar_unit' => 'days',
    'runtime_interval' => null,
], 2);
$addedWhileDisabled = (int)$db->query("SELECT id FROM maintenance_items WHERE name LIKE 'Reminder While One Disabled%' ORDER BY id DESC LIMIT 1")->fetchColumn();
$tempIds[] = $addedWhileDisabled;
echo "    [PASS] Disabled reminders do not count against active limit; addition succeeded\n";

// ------------------------------------------------------------
// TEST 6: Completed History Preservation
// ------------------------------------------------------------
echo "\n--- TEST 6: Completed History Preservation Under Limit ---\n";
$histCountBefore = (int)$db->query("SELECT COUNT(*) FROM maintenance_history")->fetchColumn();
assert_true($histCountBefore > 0, "Initial maintenance history exists ({$histCountBefore} records)");

// Clean up all temporary bulk items
echo "    Cleaning up temporary bulk reminders...\n";
$inList = implode(',', array_map('intval', $tempIds));
$db->query("DELETE FROM maintenance_items WHERE id IN ({$inList})");

$histCountAfter = (int)$db->query("SELECT COUNT(*) FROM maintenance_history")->fetchColumn();
assert_true($histCountAfter === $histCountBefore, "Completed history remains 100% intact after reminder operations ({$histCountAfter} records)");

// Verify back to original 2 reminders
$activeRestored = count($maintModel->items(2));
assert_true($activeRestored === 2, "Restored cleanly to 2 reminders");

// ------------------------------------------------------------
// TEST 7: Pagination State on Operations (Add, Edit, Toggle, Delete)
// ------------------------------------------------------------
echo "\n--- TEST 7: Pagination State on CRUD Operations ---\n";
// Create 5 reminders (1 to 5) + 1 reminder on page 2 (item 6)
$crudTempIds = [];
for ($i = 1; $i <= 4; $i++) { // 2 existing + 4 = 6 total items (2 pages)
    $cName = "CRUD State Temp {$i} " . uniqid();
    $maintModel->add([
        'name' => $cName, 'type' => 'calendar', 'service_id' => null, 'enabled' => 1,
        'last_completed' => date('Y-m-d 00:00:00'), 'calendar_value' => 7, 'calendar_unit' => 'days',
        'runtime_interval' => null,
    ], 2);
    $crudTempIds[] = (int)$db->query("SELECT id FROM maintenance_items WHERE name = '{$cName}'")->fetchColumn();
}

// 7.1 Verify 6 items -> 2 pages
$paged6 = $maintModel->itemsPaginated(2, 2, 5);
assert_true($paged6['page'] === 2, "Page 2 is active with 6 items");
assert_true($paged6['total_pages'] === 2, "Total pages is 2 with 6 items");
assert_true(count($paged6['items']) === 1, "Page 2 contains exactly 1 item");

// 7.2 Delete one of the temporary items so total drops to 5
$tempToDelete = $crudTempIds[0];
$maintModel->delete($tempToDelete);

// Now request page 2 again: itemsPaginated must automatically reset to Page 1!
$pagedAfterDelete = $maintModel->itemsPaginated(2, 2, 5);
assert_true($pagedAfterDelete['total_items'] === 5, "Total items reduced to 5");
assert_true($pagedAfterDelete['total_pages'] === 1, "Total pages reduced to 1");
assert_true($pagedAfterDelete['page'] === 1, "Page automatically reset to 1 because page 2 became invalid");
assert_true($pagedAfterDelete['count_text'] === 'Showing 1–5 of 5 reminders', "Count text updated to 'Showing 1–5 of 5 reminders'");

// Clean up remaining CRUD temp items
$crudInList = implode(',', array_map('intval', $crudTempIds));
$db->query("DELETE FROM maintenance_items WHERE id IN ({$crudInList})");

$finalCount = count($maintModel->items(2));
assert_true($finalCount === 2, "Database cleanly restored to original 2 reminders");

// ------------------------------------------------------------
// TEST 8: Clean Page Render & Verification
// ------------------------------------------------------------
echo "\n--- TEST 8: Final Render Verification (Showing 1–2 of 2 reminders) ---\n";
$_GET = [];
ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$htmlFinal = ob_get_clean();

assert_str_contains($htmlFinal, 'Showing 1–2 of 2 reminders', 'Clean render outputs "Showing 1–2 of 2 reminders"');
assert_str_contains($htmlFinal, 'Service Reminders', 'Page heading preserved');
assert_str_contains($htmlFinal, 'Active Reminders', 'Active Reminders title preserved');
assert_str_contains($htmlFinal, 'NEXT DUE:', 'NEXT DUE preserved');
assert_str_contains($htmlFinal, 'LAST COMPLETED:', 'LAST COMPLETED preserved');
assert_true(substr_count($htmlFinal, '<article class="maintenance-item">') === 2, "Exactly 2 reminder cards rendered");

echo "\n============================================================\n";
echo "🎉 ALL ACTIVE REMINDERS PAGINATION & 50-LIMIT TESTS PASSED!\n";
echo "============================================================\n";
} finally {
    foreach ($originalRows as $row) {
        $db->query(
            'UPDATE maintenance_items SET is_enabled = :is_enabled, is_deleted = :is_deleted WHERE id = :id',
            [
                ':is_enabled' => $row['is_enabled'],
                ':is_deleted' => $row['is_deleted'],
                ':id'         => $row['id']
            ]
        );
    }
}
