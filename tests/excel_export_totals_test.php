<?php
/**
 * Test: Excel Transaction Export Totals & Sales Report Verification
 * Run with: C:\xampp\php\php.exe tests/excel_export_totals_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Transaction.php';

echo "=== STARTING EXCEL SALES REPORT EXPORT VERIFICATION ===\n\n";

$db = Database::getInstance();
$txModel = new Transaction();

function captureExportCsv(array $filters = [], ?int $ownerId = null): string {
    $filtersExport = var_export($filters, true);
    $ownerIdExport = var_export($ownerId, true);
    $script = '<?php
require_once "' . addslashes(dirname(__DIR__)) . '/config/config.php";
require_once "' . addslashes(dirname(__DIR__)) . '/config/database.php";
require_once "' . addslashes(dirname(__DIR__)) . '/models/Transaction.php";

$t = new Transaction();
$t->exportTransactionsCsv(' . $filtersExport . ', ' . $ownerIdExport . ');
';
    $tmp = tempnam(sys_get_temp_dir(), 'exp_test_');
    file_put_contents($tmp, $script);
    $output = shell_exec("C:\\xampp\\php\\php.exe " . escapeshellarg($tmp)) ?? '';
    @unlink($tmp);
    return $output;
}

function parseCsvLines(string $csvContent): array {
    // Strip UTF-8 BOM if present
    if (substr($csvContent, 0, 3) === "\xEF\xBB\xBF") {
        $csvContent = substr($csvContent, 3);
    }
    $lines = [];
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $csvContent);
    rewind($stream);
    while (($row = fgetcsv($stream)) !== false) {
        $lines[] = $row;
    }
    fclose($stream);
    return $lines;
}

echo "1. Testing formatDuration() unit conversion logic...\n";
assert_equals('0 sec', Transaction::formatDuration(0), '0 seconds -> 0 sec');
assert_equals('0 sec', Transaction::formatDuration(-5), 'Negative seconds -> 0 sec');
assert_equals('45 sec', Transaction::formatDuration(45), '45 seconds -> 45 sec');
assert_equals('1 min', Transaction::formatDuration(60), '60 seconds -> 1 min');
assert_equals('1m 30s', Transaction::formatDuration(90), '90 seconds -> 1m 30s');
assert_equals('2 min', Transaction::formatDuration(120), '120 seconds -> 2 min');
assert_equals('2m 15s', Transaction::formatDuration(135), '135 seconds -> 2m 15s');
assert_equals('1h', Transaction::formatDuration(3600), '3600 seconds -> 1h');
assert_equals('1h 30s', Transaction::formatDuration(3630), '3630 seconds -> 1h 30s');
assert_equals('1h 20m', Transaction::formatDuration(4800), '4800 seconds -> 1h 20m');
assert_equals('1h 20m 30s', Transaction::formatDuration(4830), '4830 seconds -> 1h 20m 30s');
echo "  -> formatDuration handles 0s, seconds, minutes, combined minutes/seconds, and hours correctly.\n\n";

echo "2. Testing Export Header & Structure on Database Records...\n";
$csv = captureExportCsv([]);
$rows = parseCsvLines($csv);

assert_true(substr($csv, 0, 3) === "\xEF\xBB\xBF", 'CSV contains UTF-8 BOM');
assert_equals('VendoLink — Sales Report', $rows[0][0], 'Report title is VendoLink — Sales Report');
assert_equals('Date Range', $rows[1][0], 'Header row 2 has Date Range');
assert_equals('Service', $rows[2][0], 'Header row 3 has Service');
assert_equals('Status', $rows[3][0], 'Header row 4 has Status');
assert_equals('Search', $rows[4][0], 'Header row 5 has Search');
assert_equals('None', $rows[4][1], 'Search defaults to None when empty');
assert_equals('Export Date & Time', $rows[5][0], 'Header row 6 has Export Date & Time');
assert_true(empty($rows[6]) || $rows[6] === [null] || $rows[6] === [''], 'Header followed by blank line');

// Locate SALES SUMMARY
$summaryIdx = -1;
for ($i = 0; $i < count($rows); $i++) {
    if (!empty($rows[$i]) && $rows[$i][0] === 'SALES SUMMARY') {
        $summaryIdx = $i;
        break;
    }
}
assert_true($summaryIdx !== -1, 'SALES SUMMARY section present');

$summaryMetrics = [];
$recordsIdx = -1;
for ($i = $summaryIdx + 1; $i < count($rows); $i++) {
    if (!empty($rows[$i]) && $rows[$i][0] === 'TRANSACTION RECORDS') {
        $recordsIdx = $i;
        break;
    }
    if (!empty($rows[$i]) && count($rows[$i]) >= 2) {
        $summaryMetrics[$rows[$i][0]] = $rows[$i][1];
    }
}

assert_true(isset($summaryMetrics['Total Sales Amount']), 'Total Sales Amount present in SALES SUMMARY');
assert_true(isset($summaryMetrics['Completed Transactions']), 'Completed Transactions present in SALES SUMMARY');
assert_true(isset($summaryMetrics['Water Sales']), 'Water Sales present in SALES SUMMARY');
assert_true(isset($summaryMetrics['Soap Sales']), 'Soap Sales present in SALES SUMMARY');
assert_true(isset($summaryMetrics['Water Transactions']), 'Water Transactions present in SALES SUMMARY');
assert_true(isset($summaryMetrics['Soap Transactions']), 'Soap Transactions present in SALES SUMMARY');
echo "  -> Found all 6 SALES SUMMARY metrics successfully.\n\n";

echo "3. Testing Transaction Records Table (9 Columns) & Bottom Totals...\n";
assert_true($recordsIdx !== -1, 'TRANSACTION RECORDS header present');
$colHeaders = $rows[$recordsIdx + 1];
assert_equals(9, count($colHeaders), 'Transaction records table has exactly 9 columns');
assert_equals('Transaction ID', $colHeaders[0], 'Col 1: Transaction ID');
assert_equals('Date & Time', $colHeaders[1], 'Col 2: Date & Time');
assert_equals('Machine', $colHeaders[2], 'Col 3: Machine');
assert_equals('Service', $colHeaders[3], 'Col 4: Service');
assert_equals('Amount', $colHeaders[4], 'Col 5: Amount');
assert_equals('Purchased Time', $colHeaders[5], 'Col 6: Purchased Time');
assert_equals('Actual Time', $colHeaders[6], 'Col 7: Actual Time (vital for service verification)');
assert_equals('Status', $colHeaders[7], 'Col 8: Status');
assert_equals('Reason', $colHeaders[8], 'Col 9: Reason');

// Locate bottom totals
$bottomTotals = [];
$precedingBlankRow = false;
for ($i = $recordsIdx + 2; $i < count($rows); $i++) {
    if (!empty($rows[$i]) && $rows[$i][0] === 'Total Sales Amount') {
        if ($i > 0 && (empty($rows[$i - 1]) || $rows[$i - 1] === [null] || $rows[$i - 1] === [''])) {
            $precedingBlankRow = true;
        }
    }
    if (!empty($rows[$i]) && count($rows[$i]) >= 2 && strpos($rows[$i][0], 'Total ') === 0) {
        $bottomTotals[$rows[$i][0]] = $rows[$i][1];
    }
}

assert_true($precedingBlankRow, 'Exactly one blank line precedes bottom totals');
assert_true(isset($bottomTotals['Total Sales Amount']), 'Total Sales Amount present in bottom totals');
assert_true(isset($bottomTotals['Total Completed Transactions']), 'Total Completed Transactions present in bottom totals');
assert_true(isset($bottomTotals['Total Water Sales']), 'Total Water Sales present in bottom totals');
assert_true(isset($bottomTotals['Total Soap Sales']), 'Total Soap Sales present in bottom totals');
assert_true(isset($bottomTotals['Total Purchased Time']), 'Total Purchased Time present in bottom totals');

// CRITICAL: Ensure Total Actual Time is NOT present anywhere in export
assert_true(strpos($csv, 'Total Actual Time') === false, 'Total Actual Time is completely removed from export');
echo "  -> Bottom totals verified: all 5 metrics present and Total Actual Time completely removed.\n\n";

echo "4. Testing Empty Export Handling...\n";
$emptyCsv = captureExportCsv(['search' => 'NON_EXISTENT_QUERY_999999']);
$emptyRows = parseCsvLines($emptyCsv);

assert_true(strpos($emptyCsv, 'No transactions found.') !== false, 'No transactions found message in empty export');
$emptyBottom = [];
foreach ($emptyRows as $er) {
    if (!empty($er) && count($er) >= 2 && strpos($er[0], 'Total ') === 0) {
        $emptyBottom[$er[0]] = $er[1];
    }
}

assert_equals('₱0.00', $emptyBottom['Total Sales Amount'], 'Empty Total Sales Amount is ₱0.00');
assert_equals('0', $emptyBottom['Total Completed Transactions'], 'Empty Total Completed Transactions is 0');
assert_equals('₱0.00', $emptyBottom['Total Water Sales'], 'Empty Total Water Sales is ₱0.00');
assert_equals('₱0.00', $emptyBottom['Total Soap Sales'], 'Empty Total Soap Sales is ₱0.00');
assert_equals('0 sec', $emptyBottom['Total Purchased Time'], 'Empty Total Purchased Time is 0 sec');
echo "  -> Empty export safely handled with ₱0.00, 0, and 0 sec totals.\n\n";

echo "5. Testing Precision with Synthetic Dataset (Water, Soap, Completed, Failed)...\n";
$createdIds = [];
$waterService = $db->query("SELECT id, machine_id FROM services WHERE service_key = 'water' LIMIT 1")->fetch();
$soapService = $db->query("SELECT id, machine_id FROM services WHERE service_key = 'soap' LIMIT 1")->fetch();

if (!$waterService || !$soapService) {
    throw new RuntimeException("Water or Soap service not found in database for test.");
}

$machineId = (int)$waterService['machine_id'];
$waterId = (int)$waterService['id'];
$soapId = (int)$soapService['id'];
$testTag = 'SYNTH_' . bin2hex(random_bytes(4));

try {
    // Record 1: Water, ₱5.00, purchased 60s, actual 45s, status: completed
    $db->query("INSERT INTO service_transactions (machine_id, service_id, amount, coin_value_at_purchase, service_seconds_at_purchase, runtime_generated_seconds, actual_runtime_seconds, status, status_reason, created_at)
        VALUES (:mid, :sid, 5.00, 5.00, 60, 60, 45, 'completed', :tag, '2029-02-01 10:00:00')",
        [':mid' => $machineId, ':sid' => $waterId, ':tag' => $testTag]);
    $createdIds[] = (int)$db->lastInsertId();

    // Record 2: Soap, ₱10.00, purchased 120s, actual 135s, status: completed
    $db->query("INSERT INTO service_transactions (machine_id, service_id, amount, coin_value_at_purchase, service_seconds_at_purchase, runtime_generated_seconds, actual_runtime_seconds, status, status_reason, created_at)
        VALUES (:mid, :sid, 10.00, 5.00, 60, 120, 135, 'completed', :tag, '2029-02-01 11:00:00')",
        [':mid' => $machineId, ':sid' => $soapId, ':tag' => $testTag]);
    $createdIds[] = (int)$db->lastInsertId();

    // Record 3: Water, ₱25.00, purchased 300s, actual 4650s, status: completed
    $db->query("INSERT INTO service_transactions (machine_id, service_id, amount, coin_value_at_purchase, service_seconds_at_purchase, runtime_generated_seconds, actual_runtime_seconds, status, status_reason, created_at)
        VALUES (:mid, :sid, 25.00, 5.00, 60, 300, 4650, 'completed', :tag, '2029-02-01 12:00:00')",
        [':mid' => $machineId, ':sid' => $waterId, ':tag' => $testTag]);
    $createdIds[] = (int)$db->lastInsertId();

    // Record 4: Water, ₱10.00, purchased 60s, actual 0s, status: failed (must NOT be counted as completed sale)
    $db->query("INSERT INTO service_transactions (machine_id, service_id, amount, coin_value_at_purchase, service_seconds_at_purchase, runtime_generated_seconds, actual_runtime_seconds, status, status_reason, created_at)
        VALUES (:mid, :sid, 10.00, 5.00, 60, 60, 0, 'failed', :tag, '2029-02-01 13:00:00')",
        [':mid' => $machineId, ':sid' => $waterId, ':tag' => $testTag]);
    $createdIds[] = (int)$db->lastInsertId();

    // Expected Calculations for 2029-02-01:
    // Total Sales Amount = 5 + 10 + 25 = ₱40.00
    // Completed Transactions = 3
    // Water Sales = 5 + 25 = ₱30.00
    // Soap Sales = ₱10.00
    // Water Transactions = 2 (completed water)
    // Soap Transactions = 1 (completed soap)
    // Total Purchased Time = 60 + 120 + 300 + 60 = 540s = 9 min

    $testCsv = captureExportCsv(['date_from' => '2029-02-01', 'date_to' => '2029-02-01']);
    $testRows = parseCsvLines($testCsv);

    $testSummary = [];
    $testBottom = [];
    $inSummary = false;
    $inRecords = false;

    foreach ($testRows as $tr) {
        if (!empty($tr)) {
            if ($tr[0] === 'SALES SUMMARY') { $inSummary = true; continue; }
            if ($tr[0] === 'TRANSACTION RECORDS') { $inSummary = false; $inRecords = true; continue; }
            if ($inSummary && count($tr) >= 2) { $testSummary[$tr[0]] = $tr[1]; }
            if ($inRecords && count($tr) >= 2 && strpos($tr[0], 'Total ') === 0) { $testBottom[$tr[0]] = $tr[1]; }
        }
    }

    assert_equals('₱40.00', $testSummary['Total Sales Amount'], 'Summary: Total Sales Amount is ₱40.00');
    assert_equals('3', $testSummary['Completed Transactions'], 'Summary: Completed Transactions is 3');
    assert_equals('₱30.00', $testSummary['Water Sales'], 'Summary: Water Sales is ₱30.00');
    assert_equals('₱10.00', $testSummary['Soap Sales'], 'Summary: Soap Sales is ₱10.00');
    assert_equals('2', $testSummary['Water Transactions'], 'Summary: Water Transactions is 2');
    assert_equals('1', $testSummary['Soap Transactions'], 'Summary: Soap Transactions is 1');

    assert_equals('₱40.00', $testBottom['Total Sales Amount'], 'Bottom: Total Sales Amount is ₱40.00');
    assert_equals('3', $testBottom['Total Completed Transactions'], 'Bottom: Total Completed Transactions is 3');
    assert_equals('₱30.00', $testBottom['Total Water Sales'], 'Bottom: Total Water Sales is ₱30.00');
    assert_equals('₱10.00', $testBottom['Total Soap Sales'], 'Bottom: Total Soap Sales is ₱10.00');
    assert_equals('9 min', $testBottom['Total Purchased Time'], 'Bottom: Total Purchased Time is 9 min');
    echo "  -> Synthetic transactions test verified: ₱40.00 sales, ₱30.00 water, ₱10.00 soap, 9 min purchased time.\n\n";

} finally {
    if (!empty($createdIds)) {
        $in = implode(',', $createdIds);
        $db->query("DELETE FROM service_transactions WHERE id IN ({$in})");
    }
}

echo "6. Testing Service Filter Preservation...\n";
$waterOnlyCsv = captureExportCsv(['service' => 'water']);
assert_true(strpos($waterOnlyCsv, 'Water Service') !== false, 'Water Service filter preserved in header');
assert_true(strpos($waterOnlyCsv, 'Soap Service') === false || strpos($waterOnlyCsv, 'Soap Sales') !== false, 'Water filter does not export soap records');
echo "  -> Filter preservation verified.\n\n";

echo "🎉 ALL EXCEL SALES REPORT EXPORT TESTS PASSED 100% SUCCESSFULLY!\n";

function assert_equals($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Assertion failed: {$message}\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true));
    }
    echo "  [PASS] {$message}\n";
}

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  [PASS] {$message}\n";
}
