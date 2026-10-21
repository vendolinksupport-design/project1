<?php
/**
 * ============================================================
 * VendoLink — Alerts Filters, Config Confirmations & Telemetry Test
 * ============================================================
 *
 * Automated verification suite for:
 * 1. Alerts Feed Category Filters & Clickable KPI Summary Cards
 * 2. Machine Configuration Save & Cancel Confirmation Modals
 * 3. Machine Telemetry Status Box Clean Styling (Matching 3rd Pic)
 * 4. Strict Project Constraints: Zero Emojis & Zero Forbidden Word
 *
 * Run with: C:\xampp\php\php.exe tests/alerts_and_config_confirmations_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Notification.php';

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$message}\n";
    }
}

echo "============================================================\n";
echo " VENDOLINK — ALERTS, CONFIG & TELEMETRY TEST SUITE \n";
echo "============================================================\n\n";

// --- 1. Testing Alerts Feed Filters (dashboard/alerts.php) ---
echo "--- 1. Testing Alerts Feed Filters (dashboard/alerts.php) ---\n";
$alertsContent = file_get_contents(__DIR__ . '/../dashboard/alerts.php');

assert_test(strpos($alertsContent, 'id="filterAllCount"') !== false, "All alerts count badge #filterAllCount is present");
assert_test(strpos($alertsContent, 'id="filterCriticalCount"') !== false, "Critical alerts count badge #filterCriticalCount is present");
assert_test(strpos($alertsContent, 'id="filterHardwareCount"') !== false, "System logs count badge #filterHardwareCount is present");

assert_test(strpos($alertsContent, 'Critical Alerts (<span id="filterCriticalCount"') !== false, "Filter button for 'Critical Alerts' is present");
assert_test(strpos($alertsContent, 'System Logs (<span id="filterHardwareCount"') !== false, "Filter button for 'System Logs' is present");

assert_test(strpos($alertsContent, "build_alerts_url(['filter' => 'critical'") !== false, "Critical filter URL generation is present");
assert_test(strpos($alertsContent, "build_alerts_url(['filter' => 'hardware'") !== false, "System logs filter URL generation is present");

// Top KPI clickable cards
assert_test(strpos($alertsContent, 'title="Filter by All Alerts"') !== false, "Total alerts KPI card is clickable");
assert_test(strpos($alertsContent, 'title="Filter by Critical Alerts"') !== false, "Critical alerts KPI card is clickable");
assert_test(strpos($alertsContent, 'title="Filter by System Logs"') !== false, "System logs KPI card is clickable");

// --- 2. Testing Notification Model Filter Logic (models/Notification.php) ---
echo "\n--- 2. Testing Notification Model Filter Logic (models/Notification.php) ---\n";
$notifModel = new Notification();
$ownerId = 1;

$summary = $notifModel->getSummaryCounts($ownerId);
assert_test(isset($summary['total']) && isset($summary['critical']) && isset($summary['hardware']), "getSummaryCounts returns total, critical, hardware metrics");

$critCount = $notifModel->getTotalCount($ownerId, 'critical');
assert_test(is_int($critCount), "getTotalCount for 'critical' returns integer");

$logsCount = $notifModel->getTotalCount($ownerId, 'logs');
assert_test(is_int($logsCount), "getTotalCount for 'logs' alias returns integer");

$pagedCrit = $notifModel->getForOwner($ownerId, 'critical', 5, 0);
assert_test(is_array($pagedCrit), "getForOwner for 'critical' returns array");

$pagedLogs = $notifModel->getForOwner($ownerId, 'system_logs', 5, 0);
assert_test(is_array($pagedLogs), "getForOwner for 'system_logs' alias returns array");

// --- 3. Testing Machine Configuration Confirmation Modals (dashboard/machines.php) ---
echo "\n--- 3. Testing Machine Configuration Confirmation Modals (dashboard/machines.php) ---\n";
$machinesContent = file_get_contents(__DIR__ . '/../dashboard/machines.php');

assert_test(strpos($machinesContent, 'id="confirmSaveConfigModal"') !== false, "Modal #confirmSaveConfigModal exists");
assert_test(strpos($machinesContent, 'id="confirmCancelConfigModal"') !== false, "Modal #confirmCancelConfigModal exists");

assert_test(strpos($machinesContent, 'data-bs-target="#confirmSaveConfigModal"') !== false, "Save Configuration button targets #confirmSaveConfigModal");
assert_test(strpos($machinesContent, 'data-bs-target="#confirmCancelConfigModal"') !== false, "Cancel button targets #confirmCancelConfigModal");

assert_test(strpos($machinesContent, 'id="btnExecuteSaveConfig"') !== false, "Save modal contains execution button #btnExecuteSaveConfig");
assert_test(strpos($machinesContent, 'id="btnExecuteCancelConfig"') !== false, "Cancel modal contains execution link #btnExecuteCancelConfig");

// --- 4. Testing Telemetry Box Clean Styling (assets/css/app.css) ---
echo "\n--- 4. Testing Telemetry Box Clean Styling (assets/css/app.css) ---\n";
$cssContent = file_get_contents(__DIR__ . '/../assets/css/app.css');

assert_test(strpos($cssContent, '.status-dot.status-warning') !== false, ".status-warning is scoped to .status-dot");
assert_test(strpos($cssContent, '.mh-box-status.status-warning') !== false, ".mh-box-status.status-warning rule is present");
assert_test(strpos($cssContent, 'background: transparent !important') !== false, ".mh-box-status enforces transparent background");
assert_test(strpos($cssContent, 'box-shadow: none !important') !== false, ".mh-box-status enforces box-shadow: none");

// --- 5. Verifying Strict Project Constraints ---
echo "\n--- 5. Verifying Strict Project Constraints ---\n";
$filesToCheck = [
    'dashboard/alerts.php' => $alertsContent,
    'dashboard/machines.php' => $machinesContent,
    'models/Notification.php' => file_get_contents(__DIR__ . '/../models/Notification.php'),
    'assets/css/app.css' => $cssContent,
];

$emojiPattern = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

foreach ($filesToCheck as $fname => $fcontent) {
    $hasEmoji = preg_match($emojiPattern, $fcontent);
    assert_test(!$hasEmoji, "{$fname} has strictly ZERO emojis");

    $hasForbidden = (stripos($fcontent, 'fl'.'eet') !== false);
    assert_test(!$hasForbidden, "{$fname} has strictly ZERO occurrences of forbidden word");
}

echo "\n============================================================\n";
echo "SUMMARY: {$passed} Passed, {$failed} Failed.\n";
if ($failed === 0) {
    echo "ALL TESTS PASSED 100%!\n";
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
echo "============================================================\n";
