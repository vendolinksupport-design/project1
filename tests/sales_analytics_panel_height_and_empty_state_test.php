<?php
/**
 * ============================================================
 * VendoLink — Sales Performance Analytics Panel Height & Empty State Test
 * Desktop Reference: 1266 × 498 px
 * ============================================================
 */

chdir('c:/xampp/htdocs/vendolink_claude_v1');

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'models/Report.php';

function assert_test(bool $condition, string $message): void {
    if ($condition) {
        echo "  [PASS] {$message}\n";
    } else {
        echo "  [FAIL] {$message}\n";
        exit(1);
    }
}

echo "===================================================================\n";
echo " VENDOLINK — SALES ANALYTICS PANEL HEIGHT & EMPTY STATE TEST \n";
echo " Reference Viewport: 1266 × 498 px \n";
echo "===================================================================\n\n";

// 1. Inspect CSS Rules in assets/css/app.css
echo "--- 1. Testing CSS Definitions in assets/css/app.css ---\n";
$cssContent = file_get_contents('assets/css/app.css');

assert_test(strpos($cssContent, '#salesAnalyticsCard .chart-canvas-wrapper') !== false, "CSS selector for #salesAnalyticsCard .chart-canvas-wrapper defined");
assert_test(strpos($cssContent, '#salesAnalyticsCard #salesChartEmptyState') !== false, "CSS selector for #salesAnalyticsCard #salesChartEmptyState defined");
assert_test(strpos($cssContent, 'min-height: 498px') !== false, "Desktop min-height: 498px defined for #salesAnalyticsCard");

// Check height is 284px on both
preg_match('/#salesAnalyticsCard\s+\.chart-canvas-wrapper\s*\{([^}]+)\}/', $cssContent, $chartMatch);
assert_test(!empty($chartMatch[1]), "Found #salesAnalyticsCard .chart-canvas-wrapper CSS block");
assert_test(strpos($chartMatch[1], '284px') !== false, "Chart canvas wrapper targets 284px height (yields ~498px outer card)");

preg_match('/#salesAnalyticsCard\s+#salesChartEmptyState\s*\{([^}]+)\}/', $cssContent, $emptyMatch);
assert_test(!empty($emptyMatch[1]), "Found #salesAnalyticsCard #salesChartEmptyState CSS block");
assert_test(strpos($emptyMatch[1], '284px') !== false, "Empty state container targets 284px height (yields ~498px outer card)");
assert_test(strpos($emptyMatch[1], 'display: flex') !== false, "Empty state container uses display: flex");
assert_test(strpos($emptyMatch[1], 'align-items: center') !== false, "Empty state container uses align-items: center");
assert_test(strpos($emptyMatch[1], 'justify-content: center') !== false, "Empty state container uses justify-content: center");

// Check mobile consistency
assert_test(strpos($cssContent, '#salesAnalyticsCard .chart-canvas-wrapper,') !== false, "Mobile media query has salesAnalyticsCard consistency rules");

// Setup session for in-process renders
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Administrator';
$_SESSION['email'] = 'admin@vendolink.test';
$_SESSION['owner_id'] = null;
$_SESSION['last_activity'] = time();
$_SESSION['created_at'] = time();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/sales.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// 2. Test Empty State (period=today where has_data is false)
echo "\n--- 2. Testing Empty-State Rendering (period=today) ---\n";
$_GET = ['period' => 'today'];
ob_start();
require 'dashboard/sales.php';
$emptyHtml = ob_get_clean();

assert_test(!empty($emptyHtml), "Rendered sales.php for period=today");
assert_test(strpos($emptyHtml, 'id="salesAnalyticsCard"') !== false, "salesAnalyticsCard present in empty state");
assert_test(strpos($emptyHtml, 'id="salesChartEmptyState"') !== false, "salesChartEmptyState present");
assert_test(strpos($emptyHtml, 'class="chart-canvas-wrapper d-none"') !== false, "chart-canvas-wrapper has d-none when has_data is false");
assert_test(strpos($emptyHtml, 'No completed sales recorded for this period.') !== false, "Empty state heading matches exact text: 'No completed sales recorded for this period.'");
assert_test(strpos($emptyHtml, 'Sales will populate automatically upon coin insertion.') !== false, "Empty state description matches exact text: 'Sales will populate automatically upon coin insertion.'");
assert_test(strpos($emptyHtml, 'sales-empty-state-icon') !== false, "sales-empty-state-icon class present");
assert_test(strpos($emptyHtml, 'sales-empty-state-group') !== false, "sales-empty-state-group container present for grouping icon, heading, and description");

// Verify preserved elements in empty state
assert_test(strpos($emptyHtml, 'Sales Performance') !== false, "Header preserved in empty state");
assert_test(strpos($emptyHtml, 'sales-period-filter-group') !== false, "Date-range controls preserved in empty state");
assert_test(strpos($emptyHtml, 'salesFilterDropdown') !== false, "Filters button preserved in empty state");
assert_test(strpos($emptyHtml, 'salesExportExcelBtn') !== false, "Export Excel preserved in empty state");
assert_test(strpos($emptyHtml, 'Revenue') !== false && strpos($emptyHtml, 'Transactions') !== false && strpos($emptyHtml, 'Average Ticket') !== false, "Three KPI cards preserved in empty state");

// 3. Test Populated Chart State (period=all where has_data is true)
echo "\n--- 3. Testing Populated-Chart Rendering (period=all) ---\n";
$_GET = ['period' => 'all'];
ob_start();
require 'dashboard/sales.php';
$populatedHtml = ob_get_clean();

assert_test(!empty($populatedHtml), "Rendered sales.php for period=all");
assert_test(strpos($populatedHtml, 'id="salesAnalyticsCard"') !== false, "salesAnalyticsCard present in populated state");
assert_test(strpos($populatedHtml, 'id="salesAnalyticsChart"') !== false, "salesAnalyticsChart canvas present");
assert_test(strpos($populatedHtml, 'class="chart-canvas-wrapper "') !== false || strpos($populatedHtml, 'class="chart-canvas-wrapper"') !== false, "chart-canvas-wrapper visible (no d-none) when has_data is true");
assert_test(strpos($populatedHtml, 'id="salesChartEmptyState" class="d-none"') !== false, "salesChartEmptyState has d-none when has_data is true");
assert_test(strpos($populatedHtml, 'height: 284px') !== false, "chart-canvas-wrapper inline style has height: 284px matching empty-state container");

// Verify preserved elements in populated state
assert_test(strpos($populatedHtml, 'Sales Performance') !== false, "Header preserved in populated state");
assert_test(strpos($populatedHtml, 'sales-period-filter-group') !== false, "Date-range controls preserved in populated state");
assert_test(strpos($populatedHtml, 'salesFilterDropdown') !== false, "Filters button preserved in populated state");
assert_test(strpos($populatedHtml, 'salesExportExcelBtn') !== false, "Export Excel preserved in populated state");
assert_test(strpos($populatedHtml, 'Revenue') !== false && strpos($populatedHtml, 'Transactions') !== false && strpos($populatedHtml, 'Average Ticket') !== false, "Three KPI cards preserved in populated state");

// 4. Test Client-Side Script Toggle Logic
echo "\n--- 4. Testing Client-Side Script in sales.php ---\n";
$salesPhpContent = file_get_contents('dashboard/sales.php');
assert_test(strpos($salesPhpContent, 'chartWrapper.classList.remove(\'d-none\')') !== false, "JS script shows chartWrapper when hasData is true");
assert_test(strpos($salesPhpContent, 'chartWrapper.classList.add(\'d-none\')') !== false, "JS script hides chartWrapper when hasData is false");
assert_test(strpos($salesPhpContent, 'emptyEl.classList.add(\'d-none\')') !== false, "JS script hides emptyEl when hasData is true");
assert_test(strpos($salesPhpContent, 'emptyEl.classList.remove(\'d-none\')') !== false, "JS script shows emptyEl when hasData is false");

echo "\n===================================================================\n";
echo "🎉 ALL PANEL HEIGHT AND EMPTY STATE TESTS PASSED 100% SUCCESSFULLY!\n";
echo "===================================================================\n";
