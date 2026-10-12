<?php
/**
 * ============================================================
 * VendoLink — Financial Audit Page Verification Test
 * ============================================================
 *
 * Verifies Financial Audit metrics, filters, CSV export, permissions,
 * and layout consistency.
 *
 * Run with: C:\xampp\php\php.exe tests\financial_audit_test.php
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

echo "=== STARTING FINANCIAL AUDIT VERIFICATION ===\n\n";

// 1. Verify getAuditMetrics matching DB
echo "1. Verifying getAuditMetrics() calculations...\n";
$metrics = $txModel->getAuditMetrics();

$rawTotal = (float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM service_transactions WHERE status = 'completed'")->fetchColumn();
$rawCount = (int) $db->query("SELECT COUNT(*) FROM service_transactions")->fetchColumn();
$rawWater = (float) $db->query("SELECT COALESCE(SUM(st.amount), 0) FROM service_transactions st INNER JOIN services s ON s.id = st.service_id WHERE st.status = 'completed' AND s.service_key = 'water'")->fetchColumn();
$rawSoap = (float) $db->query("SELECT COALESCE(SUM(st.amount), 0) FROM service_transactions st INNER JOIN services s ON s.id = st.service_id WHERE st.status = 'completed' AND s.service_key = 'soap'")->fetchColumn();

if ($metrics['total_revenue'] !== $rawTotal) {
    throw new RuntimeException("Total Revenue mismatch! Expected: {$rawTotal}, Got: {$metrics['total_revenue']}");
}
if ($metrics['transaction_count'] !== $rawCount) {
    throw new RuntimeException("Transaction Count mismatch! Expected: {$rawCount}, Got: {$metrics['transaction_count']}");
}
if ($metrics['water_revenue'] !== $rawWater) {
    throw new RuntimeException("Water Revenue mismatch! Expected: {$rawWater}, Got: {$metrics['water_revenue']}");
}
if ($metrics['soap_revenue'] !== $rawSoap) {
    throw new RuntimeException("Soap Revenue mismatch! Expected: {$rawSoap}, Got: {$metrics['soap_revenue']}");
}

echo "✅ All-time summary metrics verified: Total ₱{$metrics['total_revenue']} ({$metrics['transaction_count']} tx), Water ₱{$metrics['water_revenue']}, Soap ₱{$metrics['soap_revenue']}.\n\n";

// 2. Verify Service & Status Filtering
echo "2. Verifying filters...\n";

// Water service filter
$waterFilter = $txModel->getPaginated(['service' => 'water']);
foreach ($waterFilter['items'] as $item) {
    if ($item['service_key'] !== 'water') {
        throw new RuntimeException("Water filter returned non-water transaction ID {$item['id']}");
    }
}
echo "✅ Water service filter verified (" . count($waterFilter['items']) . " records on page 1 of " . $waterFilter['total_items'] . ").\n";

// Active status filter
$activeFilter = $txModel->getPaginated(['status' => 'active']);
foreach ($activeFilter['items'] as $item) {
    if ($item['status'] !== 'active') {
        throw new RuntimeException("Status filter returned non-active transaction ID {$item['id']}");
    }
}
echo "✅ Status filter (active) verified (" . $activeFilter['total_items'] . " active records found).\n";

// ID Search filter
$searchResult = $txModel->getPaginated(['search' => '#00039']);
if (empty($searchResult['items'])) {
    throw new RuntimeException("ID Search for #00039 returned 0 items.");
}
echo "✅ ID Search filter (#00039) verified.\n\n";

// 3. Verify CSV Export Data Generation
echo "3. Verifying CSV Export generation...\n";
$phpCli = 'C:\\xampp\\php\\php.exe';
$exportScript = __DIR__ . '/../models/Transaction.php';

$cmd = "{$phpCli} -r \"require 'config/config.php'; require 'config/database.php'; require '{$exportScript}'; \$t = new Transaction(); \$t->exportAuditCsv(['service' => 'water']);\"";
$csvOutput = shell_exec($cmd);

if (empty($csvOutput)) {
    throw new RuntimeException("CSV Export command returned empty output.");
}

// Check UTF-8 BOM
if (substr($csvOutput, 0, 3) !== "\xEF\xBB\xBF") {
    throw new RuntimeException("CSV export missing UTF-8 BOM.");
}

$lines = explode("\n", trim($csvOutput));
$headerLine = $lines[0];
if (strpos($headerLine, 'Transaction ID') === false || strpos($headerLine, 'Purchased Time') === false || strpos($headerLine, 'Status') === false) {
    throw new RuntimeException("CSV header line invalid: {$headerLine}");
}
echo "✅ CSV export verified (" . (count($lines) - 1) . " rows exported with valid headers and UTF-8 BOM).\n\n";

// 4. Test page render via ob_start
echo "4. Testing dashboard/audit.php page rendering...\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/audit.php';
$_GET = [];

ob_start();
include __DIR__ . '/../dashboard/audit.php';
$html = ob_get_clean();

if (strpos($html, 'Financial Audit') === false) {
    throw new RuntimeException("audit.php page title missing in rendered HTML.");
}
if (strpos($html, 'Review and verify VendoLink financial records.') === false) {
    throw new RuntimeException("audit.php subtitle missing in rendered HTML.");
}
if (strpos($html, 'Total Revenue') === false || strpos($html, 'Transaction Count') === false) {
    throw new RuntimeException("audit.php summary cards missing in rendered HTML.");
}
if (strpos($html, 'Apply Filter') === false || strpos($html, 'Export CSV') === false) {
    throw new RuntimeException("audit.php filter buttons missing in rendered HTML.");
}
echo "✅ audit.php rendered successfully!\n\n";

// 5. Test alias dashboard/financial_audit.php via sub-process
echo "5. Testing dashboard/financial_audit.php alias...\n";
$aliasCmd = "{$phpCli} -r \"chdir('c:/xampp/htdocs/vendolink_claude_v1'); session_start(); \$_SESSION['user_id'] = 1; \$_SESSION['user_role'] = 'administrator'; \$_SESSION['username'] = 'admin'; require 'dashboard/financial_audit.php';\"";
$aliasHtml = shell_exec($aliasCmd);

if (strpos($aliasHtml, 'Financial Audit') === false) {
    throw new RuntimeException("financial_audit.php alias failed to render.");
}
echo "✅ financial_audit.php alias verified successfully!\n\n";

echo "🎉 ALL FINANCIAL AUDIT VERIFICATION TESTS PASSED SUCCESSFULLY!\n";
