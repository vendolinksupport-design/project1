<?php
/**
 * ============================================================
 * VendoLink — Export CSV Functionality Test
 * ============================================================
 *
 * Tests the Export CSV feature across all periods and service filters.
 * Run with: C:\xampp\php\php.exe tests\export_csv_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Transaction.php';

$db = Database::getInstance();
$report = new Report();
$txModel = new Transaction();

// Setup session before any output
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'administrator';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'System Administrator';

echo "Starting Export CSV Verification...\n";
echo "1. Testing build_report_url() preservation of export=csv and filters...\n";

$_GET = ['period' => 'month', 'service' => 'water', 'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d')];
require_once __DIR__ . '/../dashboard/reports.php';

$exportUrl = build_report_url(['export' => 'csv']);
if (strpos($exportUrl, 'export=csv') === false) {
    throw new RuntimeException("build_report_url(['export' => 'csv']) stripped the export parameter! Generated: {$exportUrl}");
}
if (strpos($exportUrl, 'period=month') === false || strpos($exportUrl, 'service=water') === false) {
    throw new RuntimeException("build_report_url did not preserve active filters: {$exportUrl}");
}
echo "✅ build_report_url properly preserves filters and triggers export=csv: {$exportUrl}\n";

// 2. Test CSV Stream Output via CLI simulation
echo "2. Testing Report::exportCsv() data generation...\n";
$exportScript = __DIR__ . '/../models/Report.php';
$phpCli = 'C:\\xampp\\php\\php.exe';

$cmd = "{$phpCli} -r \"require '{$exportScript}'; \$r = new Report(); \$r->exportCsv('" . date('Y-m-d') . "', '" . date('Y-m-d') . "', 'all');\"";
$csvOutput = shell_exec($cmd);

if (empty($csvOutput)) {
    throw new RuntimeException("CLI export command returned empty output.");
}

// Check UTF-8 BOM
if (substr($csvOutput, 0, 3) !== "\xEF\xBB\xBF") {
    throw new RuntimeException("CSV stream missing UTF-8 BOM.");
}

// Check Header Row
$lines = explode("\n", trim($csvOutput));
$headerLine = $lines[0];
if (strpos($headerLine, 'Transaction ID') === false || strpos($headerLine, 'Amount (PHP)') === false || strpos($headerLine, 'Purchased Time') === false) {
    throw new RuntimeException("CSV header invalid: {$headerLine}");
}

if (count($lines) < 2) {
    throw new RuntimeException("CSV contains no data rows.");
}
echo "✅ Today's CSV export verified (" . count($lines) . " rows generated).\n";

// 3. Test Service-Filtered CSV Export
echo "3. Testing Service-Filtered CSV Export (Water)...\n";
$cmdWater = "{$phpCli} -r \"require '{$exportScript}'; \$r = new Report(); \$r->exportCsv('" . date('Y-m-d') . "', '" . date('Y-m-d') . "', 'water');\"";
$waterOutput = shell_exec($cmdWater);
$waterLines = explode("\n", trim($waterOutput));

for ($i = 1; $i < count($waterLines); $i++) {
    if (!empty($waterLines[$i]) && strpos($waterLines[$i], 'Soap Service') !== false) {
        throw new RuntimeException("Water CSV export contained Soap transactions: {$waterLines[$i]}");
    }
}
echo "✅ Service-filtered CSV export verified.\n";

echo "\n🎉 ALL EXPORT CSV TESTS PASSED SUCCESSFULLY!\n";
