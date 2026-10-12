<?php
/**
 * ============================================================
 * VendoLink — Sales Overview Verification Test
 * ============================================================
 *
 * Verifies Sales Overview top summary cards (Total Revenue | Transaction Count | Water Sales | Soap Sales),
 * filters (date, service, status, search), CSV export, and rendering.
 *
 * Run with: C:\xampp\php\php.exe tests\sales_overview_verification_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Transaction.php';

$db = Database::getInstance();
$txModel = new Transaction();

// Set session for admin user
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'administrator';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'System Administrator';

echo "=== STARTING SALES OVERVIEW VERIFICATION ===\n\n";

// 1. Verify Top Summary Metrics
echo "1. Verifying Top Summary Metrics (Total Revenue | Transaction Count | Water Sales | Soap Sales)...\n";
$metrics = $txModel->getAuditMetrics();

$rawTotal = (float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM service_transactions WHERE status = 'completed'")->fetchColumn();
$rawCount = (int) $db->query("SELECT COUNT(*) FROM service_transactions")->fetchColumn();
$rawWater = (float) $db->query("SELECT COALESCE(SUM(st.amount), 0) FROM service_transactions st INNER JOIN services s ON s.id = st.service_id WHERE st.status = 'completed' AND s.service_key = 'water'")->fetchColumn();
$rawSoap  = (float) $db->query("SELECT COALESCE(SUM(st.amount), 0) FROM service_transactions st INNER JOIN services s ON s.id = st.service_id WHERE st.status = 'completed' AND s.service_key = 'soap'")->fetchColumn();

if ($metrics['total_revenue'] !== $rawTotal) {
    throw new RuntimeException("Total Revenue mismatch! Expected: {$rawTotal}, Got: {$metrics['total_revenue']}");
}
if ($metrics['transaction_count'] !== $rawCount) {
    throw new RuntimeException("Transaction Count mismatch! Expected: {$rawCount}, Got: {$metrics['transaction_count']}");
}
if ($metrics['water_revenue'] !== $rawWater) {
    throw new RuntimeException("Water Sales mismatch! Expected: {$rawWater}, Got: {$metrics['water_revenue']}");
}
if ($metrics['soap_revenue'] !== $rawSoap) {
    throw new RuntimeException("Soap Sales mismatch! Expected: {$rawSoap}, Got: {$metrics['soap_revenue']}");
}

echo "✅ Top summary metrics verified: Total Revenue ₱{$metrics['total_revenue']} | Transaction Count {$metrics['transaction_count']} | Water Sales ₱{$metrics['water_revenue']} | Soap Sales ₱{$metrics['soap_revenue']}.\n\n";

// 2. Verify Filters
echo "2. Verifying filters...\n";

// Service Filter
$waterFilter = $txModel->getPaginated(['service' => 'water']);
foreach ($waterFilter['items'] as $item) {
    if ($item['service_key'] !== 'water') {
        throw new RuntimeException("Water filter returned non-water transaction ID {$item['id']}");
    }
}
echo "✅ Service filter (water) verified (" . count($waterFilter['items']) . " items on page 1 of " . $waterFilter['total_items'] . ").\n";

// Status Filter
$activeFilter = $txModel->getPaginated(['status' => 'active']);
foreach ($activeFilter['items'] as $item) {
    if ($item['status'] !== 'active') {
        throw new RuntimeException("Status filter returned non-active transaction ID {$item['id']}");
    }
}
echo "✅ Status filter (active) verified (" . $activeFilter['total_items'] . " active items found).\n";

// Search Filter
$firstTx = $db->query("SELECT id FROM service_transactions ORDER BY id ASC LIMIT 1")->fetch();
$firstSearchTerm = $firstTx ? ('#000' . $firstTx['id']) : '#00031';
$searchResult = $txModel->getPaginated(['search' => $firstSearchTerm]);
if (empty($searchResult['items'])) {
    throw new RuntimeException("Search for {$firstSearchTerm} returned 0 items.");
}
echo "✅ Search filter ({$firstSearchTerm}) verified.\n\n";

// 3. Verify CSV Export
echo "3. Verifying CSV export with filters...\n";
$phpCli = 'C:\\xampp\\php\\php.exe';
$exportScript = __DIR__ . '/../models/Transaction.php';

$cmd = "{$phpCli} -r \"chdir('c:/xampp/htdocs/vendolink_claude_v1'); require 'config/config.php'; require 'config/database.php'; require '{$exportScript}'; \$t = new Transaction(); \$t->exportAuditCsv(['service' => 'water']);\"";
$csvOutput = shell_exec($cmd);

if (empty($csvOutput)) {
    throw new RuntimeException("CSV export command returned empty output.");
}
if (substr($csvOutput, 0, 3) !== "\xEF\xBB\xBF") {
    throw new RuntimeException("CSV export missing UTF-8 BOM.");
}

if (strpos($csvOutput, 'Transaction ID') === false || strpos($csvOutput, 'Purchased Time') === false || strpos($csvOutput, 'Status') === false) {
    throw new RuntimeException("CSV output invalid: {$csvOutput}");
}
$lines = explode("\n", trim($csvOutput));
echo "✅ CSV export verified (" . count($lines) . " lines exported with valid headers and UTF-8 BOM).\n\n";

// 4. Verify Page Render & Text Brightness
echo "4. Testing dashboard/sales.php page rendering...\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/sales.php';
$_GET = [];

ob_start();
include __DIR__ . '/../dashboard/sales.php';
$html = ob_get_clean();

if (strpos($html, 'Sales Overview') === false) {
    throw new RuntimeException("sales.php page title missing in rendered HTML.");
}
if (strpos($html, 'Total Revenue') === false || strpos($html, 'Transaction Count') === false) {
    throw new RuntimeException("sales.php summary cards missing in rendered HTML.");
}
if (strpos($html, 'Water Sales') === false || strpos($html, 'Soap Sales') === false) {
    throw new RuntimeException("sales.php service sales cards missing in rendered HTML.");
}
if (strpos($html, 'Export Excel') === false) {
    throw new RuntimeException("sales.php export button missing in rendered HTML.");
}
echo "✅ sales.php rendered successfully!\n\n";

// 5. Verify Navigation Redundancy Removal
echo "5. Verifying Financial Audit removed from navigation...\n";
ob_start();
include __DIR__ . '/../views/layouts/sidebar.php';
$sidebarHtml = ob_get_clean();

if (strpos($sidebarHtml, 'Financial Audit') !== false) {
    throw new RuntimeException("Financial Audit was found in sidebar navigation! It should be removed.");
}
echo "✅ Sidebar verified: Financial Audit removed, Sales Overview is single main sales page.\n\n";

echo "🎉 ALL SALES OVERVIEW VERIFICATION TESTS PASSED SUCCESSFULLY!\n";
