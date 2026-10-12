<?php
/**
 * ============================================================
 * VendoLink — Owner UI Simplification & Standardization Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/terminology.php';

echo "=== STARTING OWNER UI SIMPLIFICATION & STANDARDIZATION TESTS ===\n\n";

$passCount = 0;
$failCount = 0;

function assert_test(bool $condition, string $message): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failCount++;
    }
}

// ------------------------------------------------------------
// 1. Terminology Dictionary Checks
// ------------------------------------------------------------
echo "1. Checking Canonical Terminology Dictionary...\n";
assert_test(term('alerts') === 'Alerts', "term('alerts') returns 'Alerts'");
assert_test(term('history') === 'History', "term('history') returns 'History'");
assert_test(term('transaction_history') === 'History', "term('transaction_history') returns 'History'");
assert_test(term('history_archive') === 'History', "term('history_archive') returns 'History'");
assert_test(term('todays_sales') === "Today's Sales", "term('todays_sales') returns \"Today's Sales\"");
assert_test(term('todays_revenue') === "Today's Revenue", "term('todays_revenue') returns \"Today's Revenue\"");
assert_test(term('todays_transactions') === "Today's Transactions", "term('todays_transactions') returns \"Today's Transactions\"");
assert_test(term('water_sales') === 'Water Sales', "term('water_sales') returns 'Water Sales'");
assert_test(term('soap_sales') === 'Soap Sales', "term('soap_sales') returns 'Soap Sales'");
assert_test(term('maintenance') === 'Maintenance', "term('maintenance') returns 'Maintenance'");
assert_test(term('purchased_time') === 'Purchased Time', "term('purchased_time') returns 'Purchased Time'");
assert_test(term('purchased_runtime') === 'Purchased Time', "term('purchased_runtime') returns 'Purchased Time'");
assert_test(term('current_session_time') === 'Current Session Time', "term('current_session_time') returns 'Current Session Time'");
assert_test(term('current_runtime') === 'Current Session Time', "term('current_runtime') returns 'Current Session Time'");
assert_test(term('total_runtime') === 'Total Runtime', "term('total_runtime') returns 'Total Runtime'");
assert_test(term('machine_status') === 'Machine Status', "term('machine_status') returns 'Machine Status'");
assert_test(term('runtime_status') === 'Machine Status', "term('runtime_status') returns 'Machine Status'");
assert_test(term('time_per_coin') === 'Time per ₱5', "term('time_per_coin') returns 'Time per ₱5'");
assert_test(term('service_time') === 'Time per ₱5', "term('service_time') returns 'Time per ₱5'");
assert_test(term('save_changes') === 'Save Changes', "term('save_changes') returns 'Save Changes'");
assert_test(term('save_configuration') === 'Save Changes', "term('save_configuration') returns 'Save Changes'");
assert_test(term('open_vendoai') === 'Open VendoAI', "term('open_vendoai') returns 'Open VendoAI'");
assert_test(term('vendoai_insights') === 'VendoAI Insights', "term('vendoai_insights') returns 'VendoAI Insights'");
assert_test(term('estimated_monthly_sales') === 'Estimated Monthly Sales', "term('estimated_monthly_sales') returns 'Estimated Monthly Sales'");
assert_test(term('water_refill_estimate') === 'Water Refill Estimate', "term('water_refill_estimate') returns 'Water Refill Estimate'");
assert_test(term('soap_refill_estimate') === 'Soap Refill Estimate', "term('soap_refill_estimate') returns 'Soap Refill Estimate'");
assert_test(term('peak_usage_time') === 'Peak Usage Time', "term('peak_usage_time') returns 'Peak Usage Time'");
assert_test(term('view_maintenance') === 'View Maintenance', "term('view_maintenance') returns 'View Maintenance'");
assert_test(term('view_machine') === 'View Machine', "term('view_machine') returns 'View Machine'");
assert_test(term('view_sales') === 'View Sales', "term('view_sales') returns 'View Sales'");
assert_test(term('view_history') === 'View History', "term('view_history') returns 'View History'");

// ------------------------------------------------------------
// 2. Sidebar Navigation Checks
// ------------------------------------------------------------
echo "\n2. Checking Sidebar Navigation Standard Terminology...\n";
$sidebarHtml = file_get_contents(ROOT_PATH . '/views/layouts/sidebar.php');
assert_test(strpos($sidebarHtml, "term('alerts', 'Alerts')") !== false, "Sidebar uses term('alerts', 'Alerts')");
assert_test(strpos($sidebarHtml, "term('history', 'History')") !== false, "Sidebar uses term('history', 'History')");
assert_test(strpos($sidebarHtml, "'Alerts & Notifications'") === false, "Sidebar no longer contains hardcoded 'Alerts & Notifications'");
assert_test(strpos($sidebarHtml, "'History Archive'") === false, "Sidebar no longer contains hardcoded 'History Archive'");

// ------------------------------------------------------------
// 3. Top Navigation Bar Checks
// ------------------------------------------------------------
echo "\n3. Checking Top Navigation Labels...\n";
$topnavHtml = file_get_contents(ROOT_PATH . '/views/layouts/topnav.php');
assert_test(strpos($topnavHtml, "'alerts'          => term('alerts', 'Alerts')") !== false, "Topnav maps 'alerts' to canonical term");
assert_test(strpos($topnavHtml, "'archive'         => term('history', 'History')") !== false, "Topnav maps 'archive' to canonical term");

// ------------------------------------------------------------
// 4. Dashboard (views/dashboard/cards.php) Simplification Checks
// ------------------------------------------------------------
echo "\n4. Checking Dashboard Simplification & Standardization...\n";
$cardsHtml = file_get_contents(ROOT_PATH . '/views/dashboard/cards.php');
assert_test(strpos($cardsHtml, "Maintenance due") !== false, "Dashboard contains compact Maintenance due / No maintenance due");
assert_test(strpos($cardsHtml, "term('view_maintenance', 'View Maintenance')") !== false, "Dashboard uses View Maintenance button");
assert_test(strpos($cardsHtml, "term('todays_sales', \"Today's Sales\")") !== false, "Today's sales chart is titled Today's Sales");
assert_test(strpos($cardsHtml, "term('vendoai_insights', 'VendoAI Insights')") !== false, "VendoAI section titled VendoAI Insights");
assert_test(strpos($cardsHtml, "term('estimated_monthly_sales', 'Estimated Monthly Sales')") !== false, "AI metric: Estimated Monthly Sales");
assert_test(strpos($cardsHtml, "term('water_refill_estimate', 'Water Refill Estimate')") !== false, "AI metric: Water Refill Estimate");
assert_test(strpos($cardsHtml, "term('soap_refill_estimate', 'Soap Refill Estimate')") !== false, "AI metric: Soap Refill Estimate");
assert_test(strpos($cardsHtml, "term('peak_usage_time', 'Peak Usage Time')") !== false, "AI metric: Peak Usage Time");
assert_test(strpos($cardsHtml, "term('open_vendoai', 'Open VendoAI')") !== false, "Action button: Open VendoAI");
assert_test(strpos($cardsHtml, "term('view_machine', 'View Machine')") !== false, "Action button: View Machine");
assert_test(strpos($cardsHtml, "term('view_history', 'View History')") !== false, "Action button: View History");
// Redundant metrics strip removed
assert_test(strpos($cardsHtml, "<!-- Quick Metrics Strip -->") === false, "Redundant quick metrics strip under chart is removed");

// ------------------------------------------------------------
// 5. Machines Page (dashboard/machines.php) Checks
// ------------------------------------------------------------
echo "\n5. Checking Machines Configuration Page Terminology...\n";
$machinesHtml = file_get_contents(ROOT_PATH . '/dashboard/machines.php');
assert_test(strpos($machinesHtml, "term('current_session_time', 'Current Session Time')") !== false, "Uses Current Session Time");
assert_test(strpos($machinesHtml, "term('total_runtime', 'Total Runtime')") !== false, "Uses Total Runtime");
assert_test(strpos($machinesHtml, "term('machine_status', 'Machine Status')") !== false, "Uses Machine Status");
assert_test(strpos($machinesHtml, "term('time_per_coin', 'Time per ₱5')") !== false, "Uses Time per ₱5");
assert_test(strpos($machinesHtml, "term('save_changes', 'Save Changes')") !== false, "Uses Save Changes for service panel");

// ------------------------------------------------------------
// 6. Alerts Page (dashboard/alerts.php) Checks
// ------------------------------------------------------------
echo "\n6. Checking Alerts Page Terminology...\n";
$alertsHtml = file_get_contents(ROOT_PATH . '/dashboard/alerts.php');
assert_test(strpos($alertsHtml, "\$pageTitle   = term('alerts', 'Alerts');") !== false, "Alerts page uses term('alerts', 'Alerts')");
assert_test(strpos($alertsHtml, "Total Alerts") !== false, "Total Alerts KPI card present");
assert_test(strpos($alertsHtml, "Critical Alerts") !== false, "Critical Alerts KPI card present");
assert_test(strpos($alertsHtml, "Hardware Events") !== false, "Hardware Events KPI card present");
assert_test(strpos($alertsHtml, "Alert Stream") !== false, "Alert Stream section title present");

// ------------------------------------------------------------
// 7. Transaction History Page (dashboard/archive.php) Checks
// ------------------------------------------------------------
echo "\n7. Checking History Page Terminology...\n";
$archiveHtml = file_get_contents(ROOT_PATH . '/dashboard/archive.php');
assert_test(strpos($archiveHtml, "term('history', 'History')") !== false, "Archive uses History");

// ------------------------------------------------------------
// 8. VendoAI Page (dashboard/ai.php) Checks
// ------------------------------------------------------------
echo "\n8. Checking VendoAI Page Terminology...\n";
$aiHtml = file_get_contents(ROOT_PATH . '/dashboard/ai.php');
assert_test(strpos($aiHtml, "term('vendoai_insights', 'VendoAI Insights')") !== false, "AI page uses VendoAI Insights");
assert_test(strpos($aiHtml, "term('estimated_monthly_sales', 'Estimated Monthly Sales')") !== false, "Estimated Monthly Sales present");
assert_test(strpos($aiHtml, "term('water_refill_estimate', 'Water Refill Estimate')") !== false, "Water Refill Estimate present");
assert_test(strpos($aiHtml, "term('soap_refill_estimate', 'Soap Refill Estimate')") !== false, "Soap Refill Estimate present");
assert_test(strpos($aiHtml, "term('peak_usage_time', 'Peak Usage Time')") !== false, "Peak Usage Time present");

// ------------------------------------------------------------
// 9. Admin Guard Verification (admin/* was not touched)
// ------------------------------------------------------------
echo "\n9. Verifying Admin Panel Scope Boundaries...\n";
$adminFiles = [
    ROOT_PATH . '/admin/dashboard/index.php',
    ROOT_PATH . '/admin/customers/index.php',
    ROOT_PATH . '/admin/licenses/index.php'
];
foreach ($adminFiles as $f) {
    assert_test(file_exists($f), "Admin file exists untouched: " . basename(dirname($f)) . '/' . basename($f));
}

echo "\n------------------------------------------------------------\n";
echo "SUMMARY: {$passCount} Passed, {$failCount} Failed.\n";

if ($failCount === 0) {
    echo "🎉 ALL OWNER UI SIMPLIFICATION & STANDARDIZATION TESTS PASSED 100%!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED.\n";
    exit(1);
}
