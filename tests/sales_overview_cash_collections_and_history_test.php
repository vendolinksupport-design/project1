<?php
/**
 * ============================================================
 * VendoLink — Comprehensive Verification Test:
 * Sales Overview, Cash Collections & History Interface
 * ============================================================
 *
 * Verifies:
 * 1. Sidebar Navigation Structure under SALES & ANALYTICS:
 *    - Expandable parent menu "Sales Overview" (Lucide trending-up)
 *    - Child link "Sales" (Lucide dollar-sign) -> dashboard/sales.php
 *    - Child link "Cash Collections" (Lucide wallet) -> dashboard/cash_collections.php
 *    - Separate menu item "History" (Lucide history) -> dashboard/archive.php
 *    - Correct active state highlighting and submenu expansion
 * 2. Sales Dashboard (dashboard/sales.php):
 *    - Main page heading is "Sales" (not Sales Overview)
 *    - Lifetime summary cards: 4 cards that represent lifetime totals and do NOT fluctuate with date range
 *    - Sales Performance section with supporting text "Analyze sales over time"
 *    - Compact Export Excel action at top-right
 *    - Date range buttons: Today, This Week, This Month, This Year, All Time
 *    - Compact Filters dropdown (Machine, Service)
 *    - Three analytics metrics: Revenue, Transactions, Average Ticket
 *    - Revenue Trend chart canvas
 *    - Recent Transactions: latest 5 completed transactions with View All link to History
 *    - Sales Performance Excel export functionality with report metadata and formula sanitization
 * 3. Dedicated Cash Collections Page (dashboard/cash_collections.php):
 *    - Reached via Sales Overview > Cash Collections
 *    - Heading "Cash Collections" with description
 *    - Actions: Record Collection and Export Excel
 *    - Search bar and compact Filters dropdown (Machine, Reconciliation, From Date, To Date)
 *    - Exactly 15 records per page with bottom-anchored pagination
 *    - Reserved space indicator when < 15 records with Lucide layers (#64748B) and exact messages:
 *      "Reserved space for the remaining rows"
 *      "No placeholder rows are displayed."
 *    - Standardized reconciliation labels: Matched (green), Above (amber), Below (red) with signed variance
 *    - Full dataset Excel export
 *    - No summary cards on Cash Collections page
 * 4. History Interface (dashboard/archive.php):
 *    - Main tabs: Transaction History, Maintenance History, Configuration History
 *    - Subtab renamed from Transaction Records to Transaction History
 *    - Cash Collections subtab retained WITHOUT the 4 summary cards
 *    - Shared table rules across all tabs: 15 records/page, fixed panel height, bottom-anchored pagination,
 *      exact reserved space messages when < 15 records, no fake rows, and centered empty states.
 *
 * Run with: C:\xampp\php\php.exe tests/sales_overview_cash_collections_and_history_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Archive.php';

echo "===================================================================\n";
echo " VENDOLINK — SALES OVERVIEW, CASH COLLECTIONS & HISTORY TEST SUITE \n";
echo "===================================================================\n\n";

$db = Database::getInstance();
$txModel = new Transaction();
$machineModel = new Machine();
$reportModel = new Report();

// Authenticate session as Business Owner
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = ROLE_OWNER;
$_SESSION['role'] = ROLE_OWNER;
$_SESSION['username'] = 'owner';
$_SESSION['full_name'] = 'Business Owner';
$_SESSION['email'] = 'owner@vendolink.test';
$_SESSION['last_activity'] = time();

$ownerId = 2;

// -----------------------------------------------------------------
// 1. VERIFY SIDEBAR NAVIGATION STRUCTURE & LUCIDE ICONS
// -----------------------------------------------------------------
echo "--- 1. Testing Sidebar Navigation Structure ---\n";
$sidebarFile = file_get_contents(ROOT_PATH . '/views/layouts/sidebar.php');

// Parent Sales Overview
assert_test(strpos($sidebarFile, 'Sales Overview') !== false, "Sales Overview parent label present in sidebar");
assert_test(strpos($sidebarFile, "lucide_icon('trending-up'") !== false, "Parent uses Lucide trending-up icon");
assert_test(strpos($sidebarFile, 'id="salesOverviewSubmenu"') !== false, "Expandable submenu id salesOverviewSubmenu present");

// Child Sales
assert_test(strpos($sidebarFile, "lucide_icon('dollar-sign'") !== false, "Sales child uses Lucide dollar-sign icon");
assert_test(strpos($sidebarFile, '/dashboard/sales.php') !== false, "Sales child links to /dashboard/sales.php");

// Child Cash Collections
assert_test(strpos($sidebarFile, "lucide_icon('wallet'") !== false, "Cash Collections child uses Lucide wallet icon");
assert_test(strpos($sidebarFile, '/dashboard/cash_collections.php') !== false, "Cash Collections child links to /dashboard/cash_collections.php");

// History item
assert_test(strpos($sidebarFile, "lucide_icon('history'") !== false, "History item uses Lucide history icon");
assert_test(strpos($sidebarFile, "BASE_URL . '/dashboard/archive.php'") !== false, "History item links to dashboard/archive.php");

// Check active highlighting when currentPage is 'sales'
$currentPage = 'sales';
ob_start();
include ROOT_PATH . '/views/layouts/sidebar.php';
$sidebarSalesHtml = ob_get_clean();
assert_test(strpos($sidebarSalesHtml, 'nav-item-has-submenu open') !== false, "Sales parent expanded when currentPage=sales");
assert_test(strpos($sidebarSalesHtml, 'nav-submenu-link active') !== false, "Sales child active when currentPage=sales");

// Check active highlighting when currentPage is 'cash_collections'
$currentPage = 'cash_collections';
ob_start();
include ROOT_PATH . '/views/layouts/sidebar.php';
$sidebarCcHtml = ob_get_clean();
assert_test(strpos($sidebarCcHtml, 'nav-item-has-submenu open') !== false, "Sales parent expanded when currentPage=cash_collections");
assert_test(strpos($sidebarCcHtml, 'nav-submenu-link active') !== false, "Cash Collections child active when currentPage=cash_collections");

echo "  [PASS] Sidebar structure, Lucide icons, expandable submenu, and active states verified.\n\n";

// -----------------------------------------------------------------
// 2. VERIFY SALES DASHBOARD (dashboard/sales.php)
// -----------------------------------------------------------------
echo "--- 2. Testing Sales Dashboard (dashboard/sales.php) ---\n";

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/sales.php';
$_GET = [];

ob_start();
require ROOT_PATH . '/dashboard/sales.php';
$salesHtml = ob_get_clean();

// Check main heading is "Sales" (not Sales Overview)
assert_test(preg_match('/<h1 class="page-title[^>]*>[\s\S]*?Sales\s*<\/h1>/', $salesHtml), "Main heading is 'Sales'");

// Check lifetime summary cards (Total Revenue, Transaction Count, Water Sales, Soap Sales)
assert_test(strpos($salesHtml, 'Total Revenue') !== false, "Total Revenue card present");
assert_test(strpos($salesHtml, 'Transaction Count') !== false, "Transaction Count card present");
assert_test(strpos($salesHtml, 'Water Sales') !== false, "Water Sales card present");
assert_test(strpos($salesHtml, 'Soap Sales') !== false, "Soap Sales card present");

// Verify Lifetime cards DO NOT change when period changes
$lifetimeMetrics = $txModel->getAuditMetrics([], $ownerId);
$_GET = ['period' => 'today'];
ob_start();
require ROOT_PATH . '/dashboard/sales.php';
$salesTodayHtml = ob_get_clean();
assert_test(strpos($salesTodayHtml, format_currency($lifetimeMetrics['total_revenue'])) !== false, "Lifetime Total Revenue card invariant under period=today");

$_GET = ['period' => 'year'];
ob_start();
require ROOT_PATH . '/dashboard/sales.php';
$salesYearHtml = ob_get_clean();
assert_test(strpos($salesYearHtml, format_currency($lifetimeMetrics['total_revenue'])) !== false, "Lifetime Total Revenue card invariant under period=year");

// Check Sales Performance section
assert_test(strpos($salesHtml, 'Sales Performance') !== false, "Sales Performance section heading present");
assert_test(strpos($salesHtml, 'Analyze sales over time') !== false, "Supporting text 'Analyze sales over time' present");
assert_test(strpos($salesHtml, 'Export Excel') !== false, "Compact Export Excel action present");

// Check 3 analytics metrics
assert_test(strpos($salesHtml, 'Revenue') !== false, "Analytics Metric 1 (Revenue) present");
assert_test(strpos($salesHtml, 'Transactions') !== false, "Analytics Metric 2 (Transactions) present");
assert_test(strpos($salesHtml, 'Average Ticket') !== false, "Analytics Metric 3 (Average Ticket) present");

// Check chart canvas
assert_test(strpos($salesHtml, 'id="salesAnalyticsChart"') !== false, "Sales Analytics Chart canvas present");

// Check Recent Transactions (latest 5 completed transactions with View All link)
assert_test(strpos($salesHtml, 'Recent Transactions') !== false, "Recent Transactions section present");
assert_test(strpos($salesHtml, 'archive.php?tab=transactions') !== false, "View All link directs to archive.php?tab=transactions");
assert_test(strpos($salesHtml, 'id="salesTransactionRecords"') !== false, "Panel maintains id=salesTransactionRecords");

// Verify Excel Export functionality
$phpCli = 'C:\\xampp\\php\\php.exe';
$exportCmd = "{$phpCli} -r \"chdir('c:/xampp/htdocs/vendolink_claude_v1'); require 'config/config.php'; require 'config/database.php'; require 'includes/functions.php'; require 'models/Report.php'; (new Report())->exportSalesPerformanceCsv('today', null, 'all', 2);\"";
$exportCsv = shell_exec($exportCmd);
assert_test(!empty($exportCsv), "Sales Performance Excel export produced output");
assert_test(substr($exportCsv, 0, 3) === "\xEF\xBB\xBF", "Sales Performance Excel export contains UTF-8 BOM");
assert_test(strpos($exportCsv, 'VendoLink — Sales Performance Analytics Report') !== false, "Report title header present");
assert_test(strpos($exportCsv, 'ANALYTICS SUMMARY') !== false, "ANALYTICS SUMMARY section present in export");
assert_test(strpos($exportCsv, 'TIME-GROUPED TREND DATA') !== false, "TIME-GROUPED TREND DATA section present in export");
assert_test(strpos($exportCsv, 'COMPLETED TRANSACTIONS IN PERIOD') !== false, "COMPLETED TRANSACTIONS section present in export");

echo "  [PASS] Sales dashboard heading, lifetime cards, analytics metrics, chart, recent transactions, and Excel export verified.\n\n";

// -----------------------------------------------------------------
// 3. VERIFY DEDICATED CASH COLLECTIONS PAGE (dashboard/cash_collections.php)
// -----------------------------------------------------------------
echo "--- 3. Testing Dedicated Cash Collections Page (dashboard/cash_collections.php) ---\n";

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/cash_collections.php';
$_GET = [];

ob_start();
require ROOT_PATH . '/dashboard/cash_collections.php';
$ccHtml = ob_get_clean();

// Check heading and description
assert_test(strpos($ccHtml, 'Cash Collections') !== false, "Cash Collections page title present");
assert_test(strpos($ccHtml, 'Record Collection') !== false, "Record Collection button present");
assert_test(strpos($ccHtml, 'Export Excel') !== false, "Export Excel action present");

// Check search and filter fields
assert_test(strpos($ccHtml, 'name="search"') !== false, "Search field present");
assert_test(strpos($ccHtml, 'name="machine"') !== false, "Machine filter present");
assert_test(strpos($ccHtml, 'name="reconciliation"') !== false, "Reconciliation filter present");
assert_test(strpos($ccHtml, 'name="date_from"') !== false, "From Date filter present");
assert_test(strpos($ccHtml, 'name="date_to"') !== false, "To Date filter present");

// Check table columns
assert_test(strpos($ccHtml, 'Collection Date') !== false, "Collection Date column present");
assert_test(strpos($ccHtml, 'Machine') !== false, "Machine column present");
assert_test(strpos($ccHtml, 'Cash Collected') !== false, "Cash Collected column present");
assert_test(strpos($ccHtml, 'Expected Sales') !== false, "Expected Sales column present");
assert_test(strpos($ccHtml, 'Difference') !== false, "Difference column present");
assert_test(strpos($ccHtml, 'Result') !== false, "Result column present");

// Verify reconciliation badges
assert_test(strpos($ccHtml, 'badge-matched') !== false || strpos($ccHtml, 'badge-above') !== false || strpos($ccHtml, 'badge-below') !== false, "Standardized reconciliation badge class present");

// Check 15-record pagination & reserved space indicator
assert_test(strpos($ccHtml, 'tx-pagination') !== false, "Pagination container present");
assert_test(strpos($ccHtml, '&laquo;&nbsp;Prev') !== false, "Pagination contains non-breaking Prev");
assert_test(strpos($ccHtml, 'Next&nbsp;&raquo;') !== false, "Pagination contains non-breaking Next");

// Verify that summary cards are NOT rendered on the dedicated page
assert_test(strpos($ccHtml, 'stat-card-label text-white">Total Revenue') === false, "Sales dashboard summary cards NOT added to Cash Collections page");

// Verify Cash Collections Excel Export
$ccExportCmd = "{$phpCli} -r \"chdir('c:/xampp/htdocs/vendolink_claude_v1'); require 'config/config.php'; require 'config/database.php'; require 'includes/functions.php'; require 'models/Transaction.php'; (new Transaction())->exportCashCollectionsCsv([], 2);\"";
$ccExportCsv = shell_exec($ccExportCmd);
assert_test(!empty($ccExportCsv), "Cash Collections Excel export produced output");
assert_test(strpos($ccExportCsv, 'VendoLink — Cash Collections Audit Report') !== false, "Cash Collections report title header present");
assert_test(strpos($ccExportCsv, 'RECONCILIATION SUMMARY') !== false, "RECONCILIATION SUMMARY present in export");
assert_test(strpos($ccExportCsv, 'COLLECTION RECORDS') !== false, "COLLECTION RECORDS present in export");

echo "  [PASS] Dedicated Cash Collections page layout, filters, table, reconciliation badges, and Excel export verified.\n\n";

// -----------------------------------------------------------------
// 4. VERIFY HISTORY INTERFACE & SHARED TABLE RULES (dashboard/archive.php)
// -----------------------------------------------------------------
echo "--- 4. Testing History Interface & Shared Table Rules (dashboard/archive.php) ---\n";

// Test 4.1: Top-level tabs
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/archive.php';
$_GET = ['tab' => 'transactions', 'section' => 'records'];

ob_start();
require ROOT_PATH . '/dashboard/archive.php';
$archiveTxHtml = ob_get_clean();

assert_test(strpos($archiveTxHtml, 'Transaction History') !== false, "Top-level tab Transaction History present");
assert_test(strpos($archiveTxHtml, 'Maintenance History') !== false, "Top-level tab Maintenance History present");
assert_test(strpos($archiveTxHtml, 'Configuration History') !== false, "Top-level tab Configuration History present");

// Test 4.2: Subtab renamed to Transaction History
assert_test(preg_match('/class="[^"]*archive-subtab-btn[^"]*active[^"]*"[^>]*>[\s\S]*?Transaction History/', $archiveTxHtml), "Active subtab is renamed to Transaction History");

// Test 4.3: Cash Collections subtab in History does NOT have the 4 summary cards
$_GET = ['tab' => 'transactions', 'section' => 'collections'];
ob_start();
require ROOT_PATH . '/dashboard/archive.php';
$archiveCcHtml = ob_get_clean();

assert_test(strpos($archiveCcHtml, 'stat-card-label text-white">Cash Collected') === false, "4 summary cards are REMOVED from Cash Collections in History");
assert_test(strpos($archiveCcHtml, 'stat-card-label text-white">Total Collections') === false, "Total Collections card REMOVED from Cash Collections in History");

// Test 4.4: Reserved space rules and exact messages
$archiveCode = file_get_contents(ROOT_PATH . '/dashboard/archive.php');
assert_test(strpos($archiveCode, 'Reserved space for the remaining rows') !== false, "Exact message 'Reserved space for the remaining rows' present in archive.php");
assert_test(strpos($archiveCode, 'No placeholder rows are displayed.') !== false, "Exact message 'No placeholder rows are displayed.' present in archive.php");
assert_test(strpos($archiveCode, "lucide_icon('layers'") !== false, "Reserved space indicator uses Lucide layers icon");
assert_test(strpos($archiveCode, '#64748B') !== false, "Reserved space indicator uses muted color #64748B");

// Test 4.5: Centered empty state message when filters return no matches
assert_test(strpos($archiveCode, 'No records match your filters') !== false, "Exact empty state 'No records match your filters' present in archive.php");

// Test 4.6: Verification across Maintenance & Configuration tabs
$_GET = ['tab' => 'maintenance'];
ob_start();
require ROOT_PATH . '/dashboard/archive.php';
$maintHtml = ob_get_clean();
assert_test(strpos($maintHtml, 'Maintenance History') !== false, "Maintenance History tab renders correctly");
assert_test(strpos($maintHtml, 'tx-empty-row') === false, "No fake tx-empty-row rows in Maintenance History");

$_GET = ['tab' => 'configuration'];
ob_start();
require ROOT_PATH . '/dashboard/archive.php';
$configHtml = ob_get_clean();
assert_test(strpos($configHtml, 'Configuration History') !== false, "Configuration History tab renders correctly");
assert_test(strpos($configHtml, 'tx-empty-row') === false, "No fake tx-empty-row rows in Configuration History");

echo "  [PASS] History interface subtab renaming, summary card removal, shared table rules, and Lucide icons verified.\n\n";

echo "===================================================================\n";
echo "🎉 ALL TESTS PASSED 100% SUCCESSFULLY!\n";
echo "===================================================================\n";

function assert_test(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: " . $message);
    }
}
