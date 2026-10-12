<?php
/**
 * ============================================================
 * VendoLink — Cash Collection & Low Tank Alert Verification Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Machine.php';

echo "=== STARTING CASH COLLECTION & LOW TANK ALERT TESTS ===\n\n";

$txModel      = new Transaction();
$machineModel = new Machine();
$db           = Database::getInstance();

// 1. Test Physical Cash Collection Recording
echo "1. Testing recordCashCollection()...\n";
$user = $db->query("SELECT id FROM users LIMIT 1")->fetch();
$testUserId = $user ? (int)$user['id'] : 1;
$machine = $db->query("SELECT id FROM machines LIMIT 1")->fetch();
$testMachineId = $machine ? (int)$machine['id'] : 1;

$collId = $txModel->recordCashCollection($testUserId, $testMachineId, 1400.00, "Test cash collection");
if ($collId <= 0) {
    throw new RuntimeException("Failed to record cash collection!");
}
echo "✅ Cash collection recorded successfully with ID #{$collId}.\n\n";

// 2. Fetch and verify Cash Collections
echo "2. Testing getCashCollections()...\n";
$collections = $txModel->getCashCollections(1);
if (empty($collections)) {
    throw new RuntimeException("getCashCollections() returned empty!");
}
echo "✅ Retrieved " . count($collections) . " cash collection record(s).\n\n";

// Clean up test entry
$db->query("DELETE FROM cash_collections WHERE id = {$collId}");

// 3. Test Low Tank Floater Alerts
echo "3. Testing Low Tank Floater Alert Evaluation...\n";
$sampleMachine = [
    'calculated_status' => 'online',
    'water_tank_low'    => 1,
    'soap_tank_low'     => 1,
];
$alerts = $machineModel->getMachineAlerts($sampleMachine);
$tankAlertCount = 0;
foreach ($alerts as $a) {
    if (strpos($a['title'], 'Water') !== false || strpos($a['title'], 'Soap') !== false) {
        $tankAlertCount++;
    }
}
if ($tankAlertCount < 2) {
    throw new RuntimeException("Failed to detect both Low Tank Floater Alerts!");
}
echo "✅ Successfully detected {$tankAlertCount} Low Tank Floater Alerts!\n\n";

echo "🎉 ALL CASH COLLECTION & LOW TANK ALERT TESTS PASSED 100% SUCCESSFULLY!\n";
