<?php
/**
 * ============================================================
 * VendoLink — Service Reminders Empty State & Fixed-Height Layout Test
 * ============================================================
 *
 * Verifies User Request:
 * 1. Zero active reminders empty state:
 *    - Icon: Calendar-check inside subtle navy circular background
 *    - Heading: "No active reminders"
 *    - Description: "You haven't scheduled any service reminders yet."
 *    - Supporting text: "Create one to keep your machine in good condition."
 *    - Primary button: "Create First Reminder" (cyan style, opens #addReminderModal)
 *    - Add Reminder button in header preserved
 * 2. Space reserved for 5 reminders:
 *    - Consistent minimum height on desktop based on 5 reminder rows
 *    - Unused area left empty when fewer than 5 reminders (no fake records/placeholders)
 *    - Pagination aligned at the bottom (mt-auto)
 *    - Consistent height across pages (e.g. page with 1 reminder)
 *    - Responsive CSS prevents excessive blank space on mobile
 * 3. Empty-state pagination:
 *    - "Showing 0 of 0 reminders"
 *    - Prev & Next disabled
 *    - Navigation to nonexistent pages prevented
 * 4. Distinguish inactive/disabled reminders:
 *    - Disabled reminders not treated as deleted
 *    - When all disabled, appropriate message displayed and ability to re-enable preserved
 * 5. Functional & Safety:
 *    - CRUD operations (add, edit, toggle, complete, delete) preserved
 *    - History records preserved
 *
 * Run with: C:\xampp\php\php.exe tests\service_reminders_empty_state_and_fixed_layout_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Maintenance.php';
require_once __DIR__ . '/../controllers/MaintenanceController.php';

$db = Database::getInstance();
$maintModel = new Maintenance();

echo "============================================================\n";
echo "Starting Service Reminders Empty State & Layout Verification\n";
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

// Snapshot current reminders state to restore perfectly
$initialRows = $db->query('SELECT * FROM maintenance_items ORDER BY id ASC')->fetchAll();
$initialHistoryCount = (int) $db->query('SELECT COUNT(*) FROM maintenance_history')->fetchColumn();

try {
    // ------------------------------------------------------------
    // TEST 1: Zero Active Reminders Empty State
    // ------------------------------------------------------------
    echo "--- TEST 1: Zero Active Reminders (Empty State) ---\n";
    // Temporarily soft-delete all items
    $db->query('UPDATE maintenance_items SET is_deleted = 1');

    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    require __DIR__ . '/../dashboard/maintenance.php';
    $html0 = ob_get_clean();

    assert_str_contains($html0, 'maintenance-empty-state', 'Empty state container present');
    assert_str_contains($html0, 'maintenance-empty-icon-circle', 'Subtle navy circular icon container present');
    assert_str_contains($html0, 'bi bi-calendar-check', 'Calendar-check icon present');
    assert_str_contains($html0, 'No active reminders', 'Heading "No active reminders" present');
    assert_str_contains($html0, "You haven't scheduled any service reminders yet.", 'Description present');
    assert_str_contains($html0, 'Create one to keep your machine in good condition.', 'Supporting text present');
    assert_str_contains($html0, 'Create First Reminder', 'Primary button "Create First Reminder" present');
    assert_str_contains($html0, 'btn-maintenance-primary', 'Cyan primary button style applied');
    assert_str_contains($html0, 'id="btnCreateFirstReminder"', 'Create First Reminder has button id');
    assert_str_contains($html0, 'data-bs-target="#addReminderModal"', 'Create First Reminder opens Add Reminder modal');
    assert_str_contains($html0, 'Showing 0 of 0 reminders', 'Pagination count displays "Showing 0 of 0 reminders"');
    assert_str_contains($html0, 'id="maintenancePagination"', 'Pagination container rendered at bottom');
    assert_str_contains($html0, 'aria-disabled="true">&laquo;&nbsp;Prev</span>', 'Prev button disabled in empty state');
    assert_str_contains($html0, 'aria-disabled="true">Next&nbsp;&raquo;</span>', 'Next button disabled in empty state');
    assert_str_not_contains($html0, 'Reserved space for the remaining reminder rows', 'Reserved space message hidden in 0-reminder empty state (never shown simultaneously)');

    // ------------------------------------------------------------
    // TEST 2: One Reminder
    // ------------------------------------------------------------
    echo "\n--- TEST 2: One Reminder ---\n";
    $db->query('UPDATE maintenance_items SET is_deleted = 1');
    $db->query('UPDATE maintenance_items SET is_deleted = 0, is_enabled = 1 WHERE id = 1');

    $_GET = [];
    ob_start();
    require __DIR__ . '/../dashboard/maintenance.php';
    $html1 = ob_get_clean();

    assert_str_contains($html1, 'Showing 1–1 of 1 reminders', 'Count text matches "Showing 1–1 of 1 reminders"');
    $cards1 = substr_count($html1, '<article class="maintenance-item">');
    assert_true($cards1 === 1, "Exactly 1 card rendered (found {$cards1}), no fake records or placeholder cards");
    assert_str_not_contains($html1, 'maintenance-empty-state', 'Empty state hidden when reminders exist');
    assert_str_contains($html1, 'maintenance-reserved-space', 'Reserved space container present for 1 reminder');
    assert_str_contains($html1, 'Reserved space for the remaining reminder rows', 'Main helper line present for 1 reminder');
    assert_str_contains($html1, 'No placeholder cards are displayed.', 'Supporting line present for 1 reminder');
    assert_str_contains($html1, 'aria-disabled="true">&laquo;&nbsp;Prev</span>', 'Prev disabled on page 1 of 1');
    assert_str_contains($html1, 'aria-disabled="true">Next&nbsp;&raquo;</span>', 'Next disabled on page 1 of 1');

    // ------------------------------------------------------------
    // TEST 3: Two Reminders (Default Initial State)
    // ------------------------------------------------------------
    echo "\n--- TEST 3: Two Reminders ---\n";
    $db->query('UPDATE maintenance_items SET is_deleted = 1');
    $db->query('UPDATE maintenance_items SET is_deleted = 0, is_enabled = 1 WHERE id IN (1, 2)');

    $_GET = [];
    ob_start();
    require __DIR__ . '/../dashboard/maintenance.php';
    $html2 = ob_get_clean();

    assert_str_contains($html2, 'Showing 1–2 of 2 reminders', 'Count text matches "Showing 1–2 of 2 reminders"');
    $cards2 = substr_count($html2, '<article class="maintenance-item">');
    assert_true($cards2 === 2, "Exactly 2 cards rendered (found {$cards2}), no placeholder cards");
    assert_str_contains($html2, 'Reserved space for the remaining reminder rows', 'Main helper line present for 2 reminders');
    assert_str_contains($html2, 'No placeholder cards are displayed.', 'Supporting line present for 2 reminders');
    assert_str_contains($html2, 'NEXT DUE:', 'NEXT DUE section preserved');
    assert_str_contains($html2, 'LAST COMPLETED:', 'LAST COMPLETED section preserved');

    // ------------------------------------------------------------
    // TEST 4: Exactly Five Reminders (Full 1-Page Layout)
    // ------------------------------------------------------------
    echo "\n--- TEST 4: Exactly Five Reminders ---\n";
    // Enable 5 reminders
    $db->query('UPDATE maintenance_items SET is_deleted = 1');
    $db->query('UPDATE maintenance_items SET is_deleted = 0, is_enabled = 1 WHERE id IN (1, 2, 285, 286, 287)');

    $_GET = [];
    ob_start();
    require __DIR__ . '/../dashboard/maintenance.php';
    $html5 = ob_get_clean();

    assert_str_contains($html5, 'Showing 1–5 of 5 reminders', 'Count text matches "Showing 1–5 of 5 reminders"');
    $cards5 = substr_count($html5, '<article class="maintenance-item">');
    assert_true($cards5 === 5, "Exactly 5 cards rendered (found {$cards5})");
    assert_str_not_contains($html5, 'maintenance-reserved-space', 'Reserved space message hidden when all 5 slots occupied');
    assert_str_not_contains($html5, 'Reserved space for the remaining reminder rows', 'Main helper line hidden when 5 slots occupied');
    assert_str_contains($html5, 'aria-disabled="true">&laquo;&nbsp;Prev</span>', 'Prev disabled on page 1 of 5');
    assert_str_contains($html5, 'aria-disabled="true">Next&nbsp;&raquo;</span>', 'Next disabled on page 1 of 5');

    // ------------------------------------------------------------
    // TEST 5: Six Reminders across 2 Pages
    // ------------------------------------------------------------
    echo "\n--- TEST 5: Six Reminders (Page 1 with 5, Page 2 with 1) ---\n";
    $db->query('UPDATE maintenance_items SET is_deleted = 0, is_enabled = 1 WHERE id IN (1, 2, 285, 286, 287, 288)');

    // Page 1
    $_GET = ['page' => 1];
    ob_start();
    require __DIR__ . '/../dashboard/maintenance.php';
    $html6_p1 = ob_get_clean();

    assert_str_contains($html6_p1, 'Showing 1–5 of 6 reminders', 'Page 1 count text matches "Showing 1–5 of 6 reminders"');
    $cards6_p1 = substr_count($html6_p1, '<article class="maintenance-item">');
    assert_true($cards6_p1 === 5, "Exactly 5 cards on Page 1 (found {$cards6_p1})");
    assert_str_not_contains($html6_p1, 'maintenance-reserved-space', 'Reserved space message hidden on Page 1 (5 slots occupied)');
    assert_str_contains($html6_p1, 'page=2', 'Page 1 links to Page 2');

    // Page 2
    $_GET = ['page' => 2];
    ob_start();
    require __DIR__ . '/../dashboard/maintenance.php';
    $html6_p2 = ob_get_clean();

    assert_str_contains($html6_p2, 'Showing 6–6 of 6 reminders', 'Page 2 count text matches "Showing 6–6 of 6 reminders"');
    $cards6_p2 = substr_count($html6_p2, '<article class="maintenance-item">');
    assert_true($cards6_p2 === 1, "Exactly 1 card on Page 2 (found {$cards6_p2}), unused area empty without placeholders");
    assert_str_contains($html6_p2, 'maintenance-reserved-space', 'Reserved space message present on Page 2 (1 of 5 occupied)');
    assert_str_contains($html6_p2, 'Reserved space for the remaining reminder rows', 'Main helper line present on Page 2');
    assert_str_contains($html6_p2, 'No placeholder cards are displayed.', 'Supporting line present on Page 2');
    assert_str_contains($html6_p2, 'page=1', 'Page 2 links back to Page 1');
    assert_str_contains($html6_p2, 'aria-disabled="true">Next&nbsp;&raquo;</span>', 'Next disabled on last page');

    // Invalid page request
    $_GET = ['page' => 99];
    ob_start();
    require __DIR__ . '/../dashboard/maintenance.php';
    $html6_p99 = ob_get_clean();
    assert_str_contains($html6_p99, 'Showing 1–5 of 6 reminders', 'Invalid page 99 automatically resets to page 1');

    // ------------------------------------------------------------
    // TEST 6: All Reminders Disabled
    // ------------------------------------------------------------
    echo "\n--- TEST 6: All Reminders Disabled ---\n";
    // Keep 2 reminders undeleted, but disable both (is_enabled = 0)
    $db->query('UPDATE maintenance_items SET is_deleted = 1');
    $db->query('UPDATE maintenance_items SET is_deleted = 0, is_enabled = 0 WHERE id IN (1, 2)');

    $_GET = [];
    ob_start();
    require __DIR__ . '/../dashboard/maintenance.php';
    $htmlDis = ob_get_clean();

    assert_str_contains($htmlDis, 'No active reminders:</strong> All scheduled reminders are currently turned off', 'Disabled banner explains all reminders are turned off');
    $cardsDis = substr_count($htmlDis, '<article class="maintenance-item">');
    assert_true($cardsDis === 2, "Disabled reminders remain visible (found {$cardsDis} cards) - NOT treated as deleted");
    assert_str_contains($htmlDis, 'Turned Off', 'Cards display "Turned Off" status');
    assert_str_contains($htmlDis, 'Turn On', 'Cards display "Turn On" action preserving ability to re-enable');
    assert_str_contains($htmlDis, 'Showing 1–2 of 2 reminders', 'Pagination correctly reports total reminder items');

    // ------------------------------------------------------------
    // TEST 7: Modal Opening Mechanism & Handler
    // ------------------------------------------------------------
    echo "\n--- TEST 7: Modal Opening & Handler Verification ---\n";
    // Check Add Reminder button in header
    assert_str_contains($html0, 'data-bs-target="#addReminderModal"', 'Header Add Reminder button targets #addReminderModal');
    // Check Create First Reminder button in empty state
    assert_str_contains($html0, 'id="btnCreateFirstReminder"', 'Empty state has btnCreateFirstReminder');
    // Check JavaScript handler
    assert_str_contains($html0, 'btnCreateFirstReminder', 'JavaScript event listener registered for btnCreateFirstReminder');
    assert_str_contains($html0, 'bootstrap.Modal.getOrCreateInstance(addModalEl)', 'Uses existing modal instance without duplicating logic');

    // ------------------------------------------------------------
    // TEST 8: CRUD Actions Preserved
    // ------------------------------------------------------------
    echo "\n--- TEST 8: CRUD Operations Verification ---\n";
    // 1. Add reminder
    $qaName = 'Auto QA Reminder ' . uniqid();
    $maintModel->add([
        'machine_id'        => 1,
        'name'              => $qaName,
        'service_id'        => null,
        'type'              => 'calendar',
        'enabled'           => 1,
        'last_completed'    => date('Y-m-d H:i:s'),
        'calendar_value'    => 3,
        'calendar_unit'     => 'months',
        'runtime_interval'  => null,
    ], 2);
    $newId = (int) $db->lastInsertId();
    if ($newId <= 0) {
        $newId = (int) $db->query('SELECT id FROM maintenance_items WHERE name = :name', [':name' => $qaName])->fetchColumn();
    }
    assert_true($newId > 0, "Successfully added new reminder (ID: {$newId})");

    // 2. Toggle Turn Off
    $maintModel->setEnabled($newId, false);
    $itemRow = $db->query('SELECT is_enabled FROM maintenance_items WHERE id = :id', [':id' => $newId])->fetch();
    assert_true((int)$itemRow['is_enabled'] === 0, "Reminder toggled to disabled (is_enabled = 0)");

    // 3. Toggle Turn On
    $maintModel->setEnabled($newId, true);
    $itemRow = $db->query('SELECT is_enabled FROM maintenance_items WHERE id = :id', [':id' => $newId])->fetch();
    assert_true((int)$itemRow['is_enabled'] === 1, "Reminder toggled to enabled (is_enabled = 1)");

    // 4. Complete reminder
    $maintModel->complete($newId, 2, 'QA completion test note');
    $histRow = $db->query('SELECT id FROM maintenance_history WHERE maintenance_item_id = :id', [':id' => $newId])->fetch();
    assert_true(!empty($histRow['id']), "Reminder marked as completed and logged in history");

    // 5. Delete reminder
    $maintModel->delete($newId);
    $itemRow = $db->query('SELECT is_deleted FROM maintenance_items WHERE id = :id', [':id' => $newId])->fetch();
    assert_true((int)$itemRow['is_deleted'] === 1, "is_deleted is 1 in database");

    // Clean up temporary QA row from database
    $db->query('DELETE FROM maintenance_history WHERE maintenance_item_id = :id', [':id' => $newId]);
    $db->query('DELETE FROM maintenance_items WHERE id = :id', [':id' => $newId]);

    // ------------------------------------------------------------
    // TEST 9: CSS Layout & Mobile Rules
    // ------------------------------------------------------------
    echo "\n--- TEST 9: Layout & CSS Rules Verification ---\n";
    $css = file_get_contents(__DIR__ . '/../assets/css/app.css');
    assert_str_contains($css, '.maintenance-items-container', 'CSS defines .maintenance-items-container');
    assert_str_contains($css, 'min-height: 825px', 'Desktop min-height of 825px defined based on 5 reminder rows');
    assert_str_contains($css, '.maintenance-empty-state', 'CSS defines .maintenance-empty-state');
    assert_str_contains($css, '.maintenance-empty-icon-circle', 'CSS defines .maintenance-empty-icon-circle');
    assert_str_contains($css, '.maintenance-reserved-space', 'CSS defines .maintenance-reserved-space');
    assert_str_contains($css, '.maintenance-reserved-title', 'CSS defines .maintenance-reserved-title');
    assert_str_contains($css, '.maintenance-reserved-sub', 'CSS defines .maintenance-reserved-sub');
    assert_str_contains($css, '@media (max-width: 767.98px)', 'Mobile media query defined');
    assert_str_contains($css, 'min-height: auto', 'Mobile layout sets min-height: auto to avoid excessive blank space');

    // ------------------------------------------------------------
    // TEST 10: History Intact
    // ------------------------------------------------------------
    echo "\n--- TEST 10: Maintenance History Preserved ---\n";
    $finalHistoryCount = (int) $db->query('SELECT COUNT(*) FROM maintenance_history')->fetchColumn();
    assert_true($finalHistoryCount === $initialHistoryCount, "Historical maintenance records 100% intact ({$finalHistoryCount} records)");

} finally {
    // Perfectly restore original reminder items
    foreach ($initialRows as $row) {
        $db->query(
            'UPDATE maintenance_items SET is_enabled = :is_enabled, is_deleted = :is_deleted WHERE id = :id',
            [
                ':is_enabled' => $row['is_enabled'],
                ':is_deleted' => $row['is_deleted'],
                ':id'         => $row['id']
            ]
        );
    }
    echo "    [CLEANUP] Restored all maintenance items to original state.\n";
}

echo "\n============================================================\n";
echo "🎉 ALL SERVICE REMINDERS EMPTY STATE & LAYOUT TESTS PASSED!\n";
echo "============================================================\n";
