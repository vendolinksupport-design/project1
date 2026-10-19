<?php
/**
 * Test: Clean Add Reminder Modal Implementation Verification
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Maintenance.php';
require_once __DIR__ . '/../controllers/MaintenanceController.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion Failed: " . $message);
    }
    echo "    [PASS] " . $message . "\n";
}

echo "============================================================\n";
echo "Starting Clean Add Reminder Modal Verification\n";
echo "============================================================\n";

$db = Database::getInstance();
$owner = $db->query("SELECT * FROM users WHERE role = 'owner' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$owner) {
    throw new RuntimeException("No owner user found for testing.");
}
$_SESSION['user_id'] = $owner['id'];
$_SESSION['user_role'] = $owner['role'];
$_SESSION['username'] = $owner['username'];
$_SESSION['owner_id'] = $owner['id'];

$maintModel = new Maintenance();

// Record baseline reminder and history counts
$initialReminderCount = (int) $db->query("SELECT COUNT(*) FROM maintenance_items WHERE is_deleted = 0")->fetchColumn();
$initialHistoryCount = (int) $db->query("SELECT COUNT(*) FROM maintenance_history")->fetchColumn();

// --- TEST 1: Page Rendering & Modal Design ---
echo "\n--- TEST 1: Page Rendering & Modal Structure ---\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$html = ob_get_clean();

// 1.1 Modal Title & Trigger
assert_true(strpos($html, 'id="addReminderModal"') !== false, "Modal #addReminderModal exists");
assert_true(strpos($html, 'modal-dialog modal-dialog-centered') !== false, "Modal uses modal-dialog-centered");
assert_true(strpos($html, 'Add Reminder') !== false, "Modal title 'Add Reminder' present");
assert_true(strpos($html, 'data-bs-target="#addReminderModal"') !== false, "Add Reminder button triggers #addReminderModal");

// 1.2 Form Fields
assert_true(strpos($html, 'placeholder="e.g. Clean water filter"') !== false, "Maintenance Name placeholder is 'e.g. Clean water filter'");
assert_true(strpos($html, 'id="modal_rem_name"') !== false, "Maintenance Name input exists");
assert_true(strpos($html, 'id="modal_rem_service"') !== false, "Applies To select exists");
assert_true(strpos($html, 'Entire Machine') !== false, "Applies To includes 'Entire Machine'");
assert_true(strpos($html, 'Water Service') !== false, "Applies To includes 'Water Service'");
assert_true(strpos($html, 'Soap Service') !== false, "Applies To includes 'Soap Service'");

// 1.3 Radio Options for Reminder Type
assert_true(strpos($html, 'type="radio" name="reminder_type" id="type_calendar"') !== false, "Radio option Calendar present");
assert_true(strpos($html, 'type="radio" name="reminder_type" id="type_runtime"') !== false, "Radio option Runtime present");
assert_true(strpos($html, 'type="radio" name="reminder_type" id="type_both"') !== false, "Radio option Both present");

// 1.4 Labels: Repeat Every and Runtime Interval
assert_true(strpos($html, 'Repeat Every') !== false, "Repeat Every label present for Calendar");
assert_true(strpos($html, 'Runtime Interval (minutes)') !== false, "Runtime Interval (minutes) label present for Runtime");

// 1.5 Next-Due Summary Removed Completely
assert_true(strpos($html, 'modalPreviewBox') === false, "Next-Due Summary box completely removed from modal");
assert_true(strpos($html, 'Next-Due Summary') === false, "Next-Due Summary text completely removed from modal");

// 1.6 Cancel and Add Reminder Buttons
assert_true(strpos($html, 'data-bs-dismiss="modal">Cancel</button>') !== false, "Cancel button present with modal dismiss attribute");
assert_true(strpos($html, 'id="modalSubmitBtn"') !== false, "Add Reminder submit button present");

// --- TEST 2: Calendar Reminder Saves with Its Interval ---
echo "\n--- TEST 2: Calendar Reminder Saves with Its Interval ---\n";
$calTestName = "QA Calendar Reminder " . uniqid();
$_POST = [
    'action' => 'add',
    'csrf_token' => csrf_token(),
    'name' => $calTestName,
    'reminder_type' => 'calendar',
    'service_id' => '1',
    'calendar_interval_value' => '3',
    'calendar_interval_unit' => 'weeks',
];

$reflection = new ReflectionClass(MaintenanceController::class);
$addMethod = $reflection->getMethod('add');
$addMethod->setAccessible(true);
$controller = new MaintenanceController();
$addMethod->invoke($controller, $maintModel);

$calItem = $db->query("SELECT * FROM maintenance_items WHERE name = :name", [':name' => $calTestName])->fetch(PDO::FETCH_ASSOC);
assert_true(!empty($calItem), "Calendar reminder created in database");
assert_true($calItem['reminder_type'] === 'calendar', "Stored reminder_type is 'calendar'");
assert_true((int)$calItem['calendar_interval_value'] === 3, "Stored calendar_interval_value is 3");
assert_true($calItem['calendar_interval_unit'] === 'weeks', "Stored calendar_interval_unit is 'weeks'");
assert_true($calItem['runtime_interval_seconds'] === null, "Stored runtime_interval_seconds is null for calendar reminder");

// Clean up
$db->query("DELETE FROM maintenance_items WHERE id = " . (int)$calItem['id']);

// --- TEST 3: Runtime Reminder Saves with Its Interval ---
echo "\n--- TEST 3: Runtime Reminder Saves with Its Interval ---\n";
$runTestName = "QA Runtime Reminder " . uniqid();
$_POST = [
    'action' => 'add',
    'csrf_token' => csrf_token(),
    'name' => $runTestName,
    'reminder_type' => 'runtime',
    'service_id' => '2',
    'runtime_interval_minutes' => '450',
];

$addMethod->invoke($controller, $maintModel);

$runItem = $db->query("SELECT * FROM maintenance_items WHERE name = :name", [':name' => $runTestName])->fetch(PDO::FETCH_ASSOC);
assert_true(!empty($runItem), "Runtime reminder created in database");
assert_true($runItem['reminder_type'] === 'runtime', "Stored reminder_type is 'runtime'");
assert_true((int)$runItem['runtime_interval_seconds'] === (450 * 60), "Stored runtime_interval_seconds is 27000 (450 mins)");
assert_true($runItem['calendar_interval_value'] === null, "Stored calendar_interval_value is null for runtime reminder");
assert_true($runItem['calendar_interval_unit'] === null, "Stored calendar_interval_unit is null for runtime reminder");

// Clean up
$db->query("DELETE FROM maintenance_items WHERE id = " . (int)$runItem['id']);

// --- TEST 4: Both Saves Both Intervals ---
echo "\n--- TEST 4: Both Saves Both Intervals ---\n";
$bothTestName = "QA Both Reminder " . uniqid();
$_POST = [
    'action' => 'add',
    'csrf_token' => csrf_token(),
    'name' => $bothTestName,
    'reminder_type' => 'both',
    'service_id' => '',
    'calendar_interval_value' => '6',
    'calendar_interval_unit' => 'months',
    'runtime_interval_minutes' => '1200',
];

$addMethod->invoke($controller, $maintModel);

$bothItem = $db->query("SELECT * FROM maintenance_items WHERE name = :name", [':name' => $bothTestName])->fetch(PDO::FETCH_ASSOC);
assert_true(!empty($bothItem), "Both reminder created in database");
assert_true($bothItem['reminder_type'] === 'both', "Stored reminder_type is 'both'");
assert_true((int)$bothItem['calendar_interval_value'] === 6, "Stored calendar_interval_value is 6");
assert_true($bothItem['calendar_interval_unit'] === 'months', "Stored calendar_interval_unit is 'months'");
assert_true((int)$bothItem['runtime_interval_seconds'] === (1200 * 60), "Stored runtime_interval_seconds is 72000 (1200 mins)");

// Clean up
$db->query("DELETE FROM maintenance_items WHERE id = " . (int)$bothItem['id']);

// --- TEST 5: Invalid Intervals are Strictly Rejected ---
echo "\n--- TEST 5: Invalid Intervals Rejection ---\n";

// 5.1 Zero calendar interval
$threw = false;
try {
    $_POST = [
        'name' => 'Bad Cal', 'reminder_type' => 'calendar',
        'calendar_interval_value' => '0', 'calendar_interval_unit' => 'months',
    ];
    $addMethod->invoke($controller, $maintModel);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, "Zero calendar interval correctly rejected");

// 5.2 Negative calendar interval
$threw = false;
try {
    $_POST = [
        'name' => 'Bad Cal Neg', 'reminder_type' => 'calendar',
        'calendar_interval_value' => '-5', 'calendar_interval_unit' => 'days',
    ];
    $addMethod->invoke($controller, $maintModel);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, "Negative calendar interval correctly rejected");

// 5.3 Zero runtime interval
$threw = false;
try {
    $_POST = [
        'name' => 'Bad Run Zero', 'reminder_type' => 'runtime',
        'runtime_interval_minutes' => '0',
    ];
    $addMethod->invoke($controller, $maintModel);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, "Zero runtime interval correctly rejected");

// 5.4 Negative runtime interval
$threw = false;
try {
    $_POST = [
        'name' => 'Bad Run Neg', 'reminder_type' => 'runtime',
        'runtime_interval_minutes' => '-10',
    ];
    $addMethod->invoke($controller, $maintModel);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, "Negative runtime interval correctly rejected");

// 5.5 Empty interval
$threw = false;
try {
    $_POST = [
        'name' => 'Bad Empty', 'reminder_type' => 'calendar',
        'calendar_interval_value' => '', 'calendar_interval_unit' => 'months',
    ];
    $addMethod->invoke($controller, $maintModel);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assert_true($threw, "Empty calendar interval correctly rejected");

// --- TEST 6: Successful Save Refreshes Current Reminders ---
echo "\n--- TEST 6: Successful Save Refreshes Current Reminders ---\n";
$refreshTestName = "QA Visible Live Reminder " . uniqid();
$_POST = [
    'action' => 'add',
    'csrf_token' => csrf_token(),
    'name' => $refreshTestName,
    'reminder_type' => 'calendar',
    'service_id' => '1',
    'calendar_interval_value' => '2',
    'calendar_interval_unit' => 'months',
];
$addMethod->invoke($controller, $maintModel);

// Render page again and verify newly saved reminder appears
ob_start();
require __DIR__ . '/../dashboard/maintenance.php';
$htmlReloaded = ob_get_clean();

assert_true(strpos($htmlReloaded, htmlspecialchars($refreshTestName)) !== false, "New reminder is immediately rendered in Current Reminders on page refresh");

// Clean up test reminder
$db->query("DELETE FROM maintenance_items WHERE name = :name", [':name' => $refreshTestName]);

// --- TEST 7: Existing Reminders and Maintenance History Preservation ---
echo "\n--- TEST 7: Integrity of Existing Reminders & Maintenance History ---\n";
$finalReminderCount = (int) $db->query("SELECT COUNT(*) FROM maintenance_items WHERE is_deleted = 0")->fetchColumn();
$finalHistoryCount = (int) $db->query("SELECT COUNT(*) FROM maintenance_history")->fetchColumn();

assert_true($finalReminderCount === $initialReminderCount, "Reminder count perfectly preserved (Initial: {$initialReminderCount}, Final: {$finalReminderCount})");
assert_true($finalHistoryCount === $initialHistoryCount, "Maintenance history records preserved (Initial: {$initialHistoryCount}, Final: {$finalHistoryCount})");

echo "\n============================================================\n";
echo "🎉 ALL CLEAN ADD REMINDER MODAL TESTS PASSED 100%!\n";
echo "============================================================\n";
