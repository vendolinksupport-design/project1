<?php
/**
 * ============================================================
 * VendoLink — Admin Machines Fleet Control Verification Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminDashboard.php';

echo "=== STARTING ADMIN MACHINES FLEET TESTS ===\n\n";

$dashboardModel = new AdminDashboard();

// 1. Fetch all machines
echo "1. Testing getAllMachines()...\n";
$machines = $dashboardModel->getAllMachines();
if (!is_array($machines)) {
    throw new RuntimeException("getAllMachines() failed to return an array!");
}
echo "✅ Retrieved " . count($machines) . " machine unit(s).\n\n";

if (!empty($machines)) {
    $targetMachine = $machines[0];
    $machineId     = (int)$targetMachine['id'];

    // 2. Test token regeneration
    echo "2. Testing regenerateMachineToken()...\n";
    $oldToken = $targetMachine['api_token'] ?? '';
    $newToken = $dashboardModel->regenerateMachineToken($machineId);

    if (empty($newToken) || strpos($newToken, 'vl_dev_sec_') !== 0) {
        throw new RuntimeException("regenerateMachineToken() generated an invalid token format!");
    }

    // Restore token back
    if (!empty($oldToken)) {
        $db = Database::getInstance();
        $db->getConnection()->prepare("UPDATE machines SET api_token = :token WHERE id = :id")->execute([':token' => $oldToken, ':id' => $machineId]);
    }
    echo "✅ Token regeneration verified successfully (New token: {$newToken}).\n\n";
}

// 3. Test registerNewMachine with unique code
echo "3. Testing registerNewMachine()...\n";
$randSuffix = time() . '_' . rand(100, 999);
$testCode   = "MCH-" . $randSuffix;
$testKey    = "key_" . $randSuffix;

$testRes = $dashboardModel->registerNewMachine("Test Vendo 2", $testCode, $testKey, 21);
if (empty($testRes['id']) || empty($testRes['api_token'])) {
    throw new RuntimeException("registerNewMachine() failed to provision machine!");
}
echo "✅ Registered new machine unit ID #{$testRes['id']} ({$testCode}) with token: {$testRes['api_token']}.\n\n";

// Clean up test entry
$db = Database::getInstance();
$db->query("DELETE FROM services WHERE machine_id = {$testRes['id']}");
$db->query("DELETE FROM machines WHERE id = {$testRes['id']}");

echo "🎉 ALL ADMIN MACHINES FLEET TESTS PASSED 100% SUCCESSFULLY!\n";
