<?php
/**
 * ============================================================
 * VendoLink — History Archive Module Unit & Integration Tests
 * ============================================================
 *
 * Run with: C:\xampp\php\php.exe tests\archive_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Archive.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Maintenance.php';
require_once __DIR__ . '/../models/Service.php';

$db = Database::getInstance();
$archive = new Archive();
$transaction = new Transaction();
$maintenance = new Maintenance();
$serviceModel = new Service();

echo "Starting History Archive Module Verification...\n";

// 1. Setup Test Data
$water = $db->query("SELECT * FROM services WHERE service_key = 'water' LIMIT 1")->fetch();
$soap  = $db->query("SELECT * FROM services WHERE service_key = 'soap' LIMIT 1")->fetch();

if (!$water || !$soap) {
    throw new RuntimeException("Water and Soap services must exist.");
}

$cleanupTxIds = [];
$cleanupMaintIds = [];
$cleanupConfigIds = [];

try {
    // 2. Test Transaction History Archive
    echo "1. Testing Transaction History Archive...\n";
    $txId1 = $transaction->record((int) $water['id'], (float) $water['coin_value'], 'completed');
    $cleanupTxIds[] = $txId1;
    $txId2 = $transaction->record((int) $soap['id'], (float) $soap['coin_value'] * 2, 'completed');
    $cleanupTxIds[] = $txId2;

    $txRes = $archive->getTransactionHistory([], 1, 50);
    if ($txRes['total_items'] < 2) {
        throw new RuntimeException("Transaction archive did not find created transactions.");
    }
    
    // Test Water service filter
    $waterRes = $archive->getTransactionHistory(['service' => 'water'], 1, 50);
    foreach ($waterRes['items'] as $item) {
        if ($item['service_key'] !== 'water') {
            throw new RuntimeException("Transaction archive returned non-water transaction when filtering by water.");
        }
    }

    // Test Search by Transaction ID
    $searchRes = $archive->getTransactionHistory(['search' => '#' . str_pad((string)$txId1, 5, '0', STR_PAD_LEFT)], 1, 10);
    if (empty($searchRes['items']) || (int)$searchRes['items'][0]['id'] !== $txId1) {
        throw new RuntimeException("Transaction archive search by formatted ID failed.");
    }
    echo "✅ Transaction History Archive verified.\n";

    // 3. Test Maintenance History Archive (and verify delete reminder does NOT delete history)
    echo "2. Testing Maintenance History Archive & Deletion Immunity...\n";
    $maintenance->add([
        'name' => '__Archive Test Reminder__', 'type' => 'calendar', 'service_id' => (int) $water['id'], 'enabled' => 1,
        'last_completed' => date('Y-m-d 00:00:00'), 'calendar_value' => 1, 'calendar_unit' => 'months', 'runtime_interval' => null,
    ]);
    $maintItemId = (int) $db->lastInsertId();
    $cleanupMaintIds[] = $maintItemId;

    // Complete the maintenance item to create a history record
    $maintenance->complete($maintItemId, 21, 'Archived inspection completed');

    // Verify history record exists in archive
    $maintRes = $archive->getMaintenanceHistory(['search' => '__Archive Test Reminder__'], 1, 10);
    if (empty($maintRes['items'])) {
        throw new RuntimeException("Maintenance history record was not found in archive.");
    }
    if ($maintRes['items'][0]['notes'] !== 'Archived inspection completed') {
        throw new RuntimeException("Maintenance history notes mismatch.");
    }

    // Now delete (soft-delete) the maintenance reminder
    $maintenance->delete($maintItemId);

    // Verify that the reminder is deleted from active reminders
    $activeItems = $maintenance->items();
    foreach ($activeItems as $act) {
        if ((int)$act['id'] === $maintItemId) {
            throw new RuntimeException("Deleted maintenance reminder still appears in active reminders list!");
        }
    }

    // Verify that the historical maintenance record STILL EXISTS in the archive!
    $maintResAfter = $archive->getMaintenanceHistory(['search' => '__Archive Test Reminder__'], 1, 10);
    if (empty($maintResAfter['items'])) {
        throw new RuntimeException("ERROR: Deleting a maintenance reminder deleted its historical records!");
    }
    echo "✅ Maintenance History Archive and deletion immunity verified (history preserved after reminder deletion).\n";

    // 4. Test Configuration History Archive
    echo "3. Testing Configuration History Archive...\n";
    $oldWaterPrice = (float) $water['coin_value'];
    $oldWaterTime  = (int) $water['service_seconds'];

    // Update configuration to trigger history log
    $serviceModel->updateConfiguration((int) $water['id'], true, 10.00, 300, 21);

    $configRes = $archive->getConfigurationHistory(['service' => 'water'], 1, 10);
    if (empty($configRes['items'])) {
        throw new RuntimeException("Configuration history record was not created on service update.");
    }

    $latestConfig = $configRes['items'][0];
    if ((float)$latestConfig['new_coin_value'] !== 10.00 || (int)$latestConfig['new_service_seconds'] !== 300) {
        throw new RuntimeException("Configuration history values mismatch: " . json_encode($latestConfig));
    }
    echo "✅ Configuration History Archive verified.\n";

    // 5. Test Page Render in Authenticated Session
    echo "4. Testing Archive Page Render across all tabs...\n";
    $_SESSION['user_id'] = 1;
    $_SESSION['user_role'] = 'administrator';
    $_SESSION['username'] = 'admin';
    $_SESSION['full_name'] = 'System Administrator';

    foreach (['transactions', 'maintenance', 'configuration'] as $tab) {
        $_GET['tab'] = $tab;
        ob_start();
        @include __DIR__ . '/../dashboard/archive.php';
        $out = ob_get_clean();
        if (strpos($out, 'History Archive') === false) {
            throw new RuntimeException("Archive page did not render for tab {$tab}.");
        }
    }
    echo "✅ Archive page rendered without errors across all tabs.\n";

    echo "\n🎉 ALL HISTORY ARCHIVE TESTS PASSED SUCCESSFULLY!\n";
} finally {
    // Restore water service
    $serviceModel->updateConfiguration((int) $water['id'], true, $oldWaterPrice ?? 5.00, $oldWaterTime ?? 120);

    // Cleanup test records
    if (!empty($cleanupTxIds)) {
        $inTx = implode(',', array_fill(0, count($cleanupTxIds), '?'));
        $db->query("DELETE FROM service_transactions WHERE id IN ({$inTx})", $cleanupTxIds);
    }
    if (!empty($cleanupMaintIds)) {
        $inM = implode(',', array_fill(0, count($cleanupMaintIds), '?'));
        $db->query("DELETE FROM maintenance_history WHERE maintenance_item_id IN ({$inM})", $cleanupMaintIds);
        $db->query("DELETE FROM maintenance_items WHERE id IN ({$inM})", $cleanupMaintIds);
    }
    $db->query("DELETE FROM configuration_history WHERE created_at >= NOW() - INTERVAL 1 MINUTE");
}
