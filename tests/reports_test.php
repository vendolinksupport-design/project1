<?php
/**
 * ============================================================
 * VendoLink — Reports Module Unit & Integration Tests
 * ============================================================
 *
 * Run with: C:\xampp\php\php.exe tests\reports_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Service.php';

$db = Database::getInstance();
$report = new Report();
$txModel = new Transaction();
$svcModel = new Service();

echo "Starting Reports Module Verification...\n";

// 1. Setup Test Data
$water = $db->query("SELECT * FROM services WHERE service_key = 'water' LIMIT 1")->fetch();
$soap  = $db->query("SELECT * FROM services WHERE service_key = 'soap' LIMIT 1")->fetch();

if (!$water || !$soap) {
    throw new RuntimeException("Water and Soap services must exist.");
}

$cleanupTxIds = [];

try {
    // 2. Test Period Bounds Resolution
    echo "1. Testing Report Period Resolution...\n";
    $todayRes = $report->resolvePeriod('today');
    if ($todayRes['date_from'] !== date('Y-m-d') || $todayRes['date_to'] !== date('Y-m-d')) {
        throw new RuntimeException("Today period resolution failed: " . json_encode($todayRes));
    }

    $weekRes = $report->resolvePeriod('week');
    if (empty($weekRes['date_from']) || empty($weekRes['date_to'])) {
        throw new RuntimeException("Week period resolution failed: " . json_encode($weekRes));
    }

    $monthRes = $report->resolvePeriod('month');
    if ($monthRes['date_from'] !== date('Y-m-01')) {
        throw new RuntimeException("Month period resolution failed: " . json_encode($monthRes));
    }

    $customRes = $report->resolvePeriod('custom', '2026-08-01', '2026-08-08');
    if ($customRes['date_from'] !== '2026-08-01' || $customRes['date_to'] !== '2026-08-08') {
        throw new RuntimeException("Custom period resolution failed: " . json_encode($customRes));
    }
    echo "✅ Period resolution verified.\n";

    // 3. Test Summary Metrics Calculation with Configuration Snapshot Accuracy
    echo "2. Testing Report Summary Metrics & Snapshot Accuracy...\n";
    $oldWaterCoin = (float) $water['coin_value'];
    $oldWaterSecs = (int) $water['service_seconds'];

    // Insert transaction under old rate (e.g. ₱5 = 120s)
    $txId1 = $txModel->record((int) $water['id'], $oldWaterCoin, 'completed');
    $cleanupTxIds[] = $txId1;

    // Update water configuration (e.g. ₱10 = 300s)
    $svcModel->updateConfiguration((int) $water['id'], true, 10.00, 300);

    // Insert transaction under new rate (₱10 = 300s)
    $txId2 = $txModel->record((int) $water['id'], 10.00, 'completed');
    $cleanupTxIds[] = $txId2;

    // Insert soap transaction
    $txId3 = $txModel->record((int) $soap['id'], (float)$soap['coin_value'], 'completed');
    $cleanupTxIds[] = $txId3;

    $metrics = $report->getSummaryMetrics(date('Y-m-d'), date('Y-m-d'));
    if ($metrics['total_revenue'] <= 0 || $metrics['total_transactions'] < 3) {
        throw new RuntimeException("Summary metrics total revenue or count calculation error: " . json_encode($metrics));
    }

    if ($metrics['water_revenue'] <= 0 || $metrics['soap_revenue'] <= 0) {
        throw new RuntimeException("Water/Soap revenue breakdown calculation error: " . json_encode($metrics));
    }

    // Verify snapshot preservation: check that the first transaction retained its original purchased seconds
    $firstTx = $db->query("SELECT * FROM service_transactions WHERE id = :id", [':id' => $txId1])->fetch();
    if ((int)$firstTx['service_seconds_at_purchase'] !== $oldWaterSecs) {
        throw new RuntimeException("Historical snapshot was corrupted after service update: {$firstTx['service_seconds_at_purchase']}");
    }
    echo "✅ Summary metrics and snapshot preservation verified.\n";

    // 4. Test Time-Series Chart Data Generation
    echo "3. Testing Time-Series Chart Data...\n";
    $hourlySeries = $report->getTimeSeries(date('Y-m-d'), date('Y-m-d'), 'today');
    if ($hourlySeries['interval'] !== 'hourly' || count($hourlySeries['labels']) !== 24) {
        throw new RuntimeException("Hourly time-series did not return 24 hours: " . count($hourlySeries['labels']));
    }

    $dailySeries = $report->getTimeSeries(date('Y-m-01'), date('Y-m-d'), 'month');
    if ($dailySeries['interval'] !== 'daily' || count($dailySeries['labels']) < 1) {
        throw new RuntimeException("Daily time-series generation failed.");
    }
    echo "✅ Time-series chart datasets verified.\n";

    // 5. Test Maintenance Summary in Reports
    echo "4. Testing Maintenance Summary in Reports...\n";
    $maintSum = $report->getMaintenanceSummary(date('Y-m-01'), date('Y-m-d'));
    if (!isset($maintSum['period_completed_count']) || !isset($maintSum['active_reminders_count'])) {
        throw new RuntimeException("Maintenance summary structure error.");
    }
    echo "✅ Maintenance summary verified.\n";

    // 6. Test Reports Page Render across all filters and views
    echo "5. Testing Reports Page Render in Authenticated Session...\n";
    $_SESSION['user_id'] = 1;
    $_SESSION['user_role'] = 'administrator';
    $_SESSION['username'] = 'admin';
    $_SESSION['full_name'] = 'System Administrator';

    foreach (['today', 'week', 'month', 'custom'] as $p) {
        $_GET = ['period' => $p, 'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d')];
        ob_start();
        @include __DIR__ . '/../dashboard/reports.php';
        $out = ob_get_clean();
        if (strpos($out, 'Performance Reports') === false || strpos($out, 'Sales Overview') === false) {
            throw new RuntimeException("Reports page did not render for period {$p}.");
        }
    }

    // Test with Service filter
    $_GET = ['period' => 'today', 'service' => 'water'];
    ob_start();
    @include __DIR__ . '/../dashboard/reports.php';
    $out = ob_get_clean();
    if (strpos($out, 'Water Service') === false) {
        throw new RuntimeException("Reports page did not render with service=water filter.");
    }

    echo "✅ Reports page rendered successfully across all periods and service filters.\n";

    echo "\n🎉 ALL REPORTS MODULE TESTS PASSED SUCCESSFULLY!\n";
} finally {
    // Restore water service
    $svcModel->updateConfiguration((int) $water['id'], true, $oldWaterCoin ?? 5.00, $oldWaterSecs ?? 120);

    // Cleanup test transactions
    if (!empty($cleanupTxIds)) {
        $inTx = implode(',', array_fill(0, count($cleanupTxIds), '?'));
        $db->query("DELETE FROM service_transactions WHERE id IN ({$inTx})", $cleanupTxIds);
    }
}
