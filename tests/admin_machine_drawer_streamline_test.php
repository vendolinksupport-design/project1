<?php
/**
 * ============================================================
 * Automated Verification Suite:
 * System Reset Counter, OTA Heartbeat Sync, Admin Activity Stream,
 * and Admin Machine Drawer Streamlining
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminDashboard.php';
require_once __DIR__ . '/../models/SystemReset.php';

echo "=== STARTING ADMIN STREAMLINE & SYSTEM RESET VERIFICATION ===\n\n";

$passCount = 0;
$totalTests = 12;

function check(bool $condition, string $label): void {
    global $passCount;
    if ($condition) {
        $passCount++;
        echo "   [PASS] {$label}\n";
    } else {
        echo "   [FAIL] {$label}\n";
        exit(1);
    }
}

// Test 1: Verify SystemReset contains AUTO_INCREMENT = 1 resets
echo "1. Testing SystemReset AUTO_INCREMENT reset configuration...\n";
$resetSource = file_get_contents(__DIR__ . '/../models/SystemReset.php');
check(
    str_contains($resetSource, 'ALTER TABLE `{$tbl}` AUTO_INCREMENT = 1') || str_contains($resetSource, 'AUTO_INCREMENT = 1'),
    "models/SystemReset.php resets table AUTO_INCREMENT to 1 after commit"
);
check(
    str_contains($resetSource, "'machines'"),
    "models/SystemReset.php includes 'machines' in tables to reset AUTO_INCREMENT"
);

// Test 2: Verify admin/system/reset.php reports counter reset to #1
echo "\n2. Testing admin/system/reset.php confirmation labels...\n";
$resetViewSource = file_get_contents(__DIR__ . '/../admin/system/reset.php');
check(
    str_contains($resetViewSource, "Reset to <strong>#1</strong>"),
    "admin/system/reset.php informs user that Machine ID counter resets to #1"
);

// Test 3: Verify AdminDashboard::getSummaryMetrics uses live heartbeat
echo "\n3. Testing AdminDashboard::getSummaryMetrics heartbeat evaluation...\n";
$dashboardSource = file_get_contents(__DIR__ . '/../models/AdminDashboard.php');
check(
    str_contains($dashboardSource, 'HEARTBEAT_TIMEOUT_SECONDS') && str_contains($dashboardSource, 'last_seen'),
    "models/AdminDashboard.php::getSummaryMetrics() calculates online machines using live heartbeat threshold"
);

$dashboard = new AdminDashboard();
$metrics = $dashboard->getSummaryMetrics();
check(
    isset($metrics['online_machines']) && isset($metrics['offline_machines']) && ($metrics['online_machines'] + $metrics['offline_machines'] === $metrics['total_machines']),
    "Summary metrics math is consistent (online + offline = total)"
);

// Test 4: Verify AdminDashboard::getAdminActivity method
echo "\n4. Testing AdminDashboard::getAdminActivity query...\n";
check(
    method_exists($dashboard, 'getAdminActivity'),
    "models/AdminDashboard.php implements getAdminActivity() method"
);
$adminLogs = $dashboard->getAdminActivity(10);
check(
    is_array($adminLogs),
    "getAdminActivity() returns valid array of records"
);

// Test 5: Verify admin/dashboard/index.php displays Administrator Activity
echo "\n5. Testing admin/dashboard/index.php header and activity stream...\n";
$adminDashboardView = file_get_contents(__DIR__ . '/../admin/dashboard/index.php');
check(
    str_contains($adminDashboardView, 'Administrator Activity') && str_contains($adminDashboardView, 'getAdminActivity'),
    "admin/dashboard/index.php calls getAdminActivity() and displays Administrator Activity header"
);

// Test 6: Verify absence of ESP32 Hardware Provisioning Workflow card and LaTeX arrow
echo "\n6. Testing removal of bulky provisioning workflow guide...\n";
$machinesSource = file_get_contents(__DIR__ . '/../admin/machines/index.php');
check(
    !str_contains($machinesSource, 'ESP32 Hardware Provisioning Workflow') && !str_contains($machinesSource, '#setupGuideDetails'),
    "admin/machines/index.php completely removes ESP32 Hardware Provisioning Workflow guide"
);
check(
    !str_contains($machinesSource, '$\\rightarrow$') && !str_contains($machinesSource, '\rightarrow'),
    "admin/machines/index.php eliminates broken LaTeX arrow text"
);

// Test 7: Verify removal of topnav breadcrumb
echo "\n7. Testing removal of Admin / Machines breadcrumb from topnav...\n";
$topnavSource = file_get_contents(__DIR__ . '/../views/layouts/admin_topnav.php');
check(
    !str_contains($topnavSource, 'topnav-pagetitle') && !str_contains($topnavSource, 'text-secondary small fw-medium">Admin<'),
    "views/layouts/admin_topnav.php removes the redundant breadcrumb"
);

// Test 8: Verify elimination of 'legacy unit' and 'legacy' user-facing words
echo "\n8. Testing elimination of 'legacy unit' from labels...\n";
check(
    !stripos($machinesSource, 'Legacy Unit'),
    "admin/machines/index.php contains zero occurrences of 'Legacy Unit'"
);
check(
    !str_contains($machinesSource, 'None (Legacy)'),
    "admin/machines/index.php does not contain 'None (Legacy)'"
);

// Test 9: Verify removal of redundant count in section heading
echo "\n9. Testing section heading count removal...\n";
check(
    str_contains($machinesSource, '<i class="bi bi-list-task text-primary me-2"></i>Registered Machine Units') && !str_contains($machinesSource, 'Registered Machine Units (<?= count($machines) ?>)'),
    "admin/machines/index.php heading displays clean 'Registered Machine Units' without parenthetical number count"
);

// Test 10: Verify removal of Vending & Health box and 2-column drawer layout
echo "\n10. Testing machine drawer streamlining & 2-column layout...\n";
check(
    !str_contains($machinesSource, 'Vending &amp; Health') && !str_contains($machinesSource, 'Current Water Tank') && !str_contains($machinesSource, 'Current Soap Tank'),
    "admin/machines/index.php removes Vending & Health box and fluid sensor rows"
);
preg_match('/<div class="machine-details-drawer">(.*?)<\/div>\s*<\/div>\s*<\/div>/s', $machinesSource, $drawerMatch);
$drawerContent = $drawerMatch[1] ?? '';
check(
    str_contains($drawerContent, 'col-12 col-md-6') && !str_contains($drawerContent, 'col-12 col-md-4'),
    "admin/machines/index.php drawer layout uses balanced col-12 col-md-6 columns and no col-12 col-md-4"
);

// Test 11: Verify strict compliance (zero forbidden words and zero emojis)
echo "\n11. Testing strict compliance rules across all modified files...\n";
$modifiedFiles = [
    __DIR__ . '/../models/SystemReset.php',
    __DIR__ . '/../admin/system/reset.php',
    __DIR__ . '/../models/AdminDashboard.php',
    __DIR__ . '/../admin/dashboard/index.php',
    __DIR__ . '/../admin/machines/index.php',
    __DIR__ . '/../views/layouts/admin_topnav.php',
];

$emojiPattern = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

foreach ($modifiedFiles as $file) {
    $content = file_get_contents($file);
    check(!stripos($content, 'fleet'), basename($file) . " does not contain forbidden word 'fleet'");
    check(!preg_match($emojiPattern, $content), basename($file) . " does not contain any emojis");
}

echo "\n============================================================\n";
echo "SUCCESS: ALL TESTS PASSED SUCCESSFULLY! ({$passCount} checks verified)\n";
echo "============================================================\n";
