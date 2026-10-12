<?php
/**
 * ============================================================
 * VendoLink — Real Low Water & Low Soap Notifications Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Machine.php';

echo "=== STARTING LOW TANK SENSOR NOTIFICATION & RECOVERY TESTS ===\n\n";

$machineModel = new Machine();
$machine = $machineModel->primaryWithServices();

if (!$machine) {
    throw new RuntimeException("Primary machine not found!");
}

$machineKey = $machine['machine_key'];
$token      = $machine['api_token'];

// Helper function to call tank API
function callTankApi($machineKey, $token, $tankKey, $status) {
    $ch = curl_init('http://localhost/vendolink_claude_v1/api/machine/tank.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Machine-Token: ' . $token
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'machine_key' => $machineKey,
        'tank_key'    => $tankKey,
        'status'      => $status,
        'api_token'   => $token
    ]));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'data' => json_decode($response, true), 'raw' => $response];
}

// 1. Reset tank statuses to 0
$db = Database::getInstance();
$db->query("UPDATE machines SET water_tank_low = 0, soap_tank_low = 0 WHERE machine_key = '{$machineKey}'");

echo "1. Testing Low Water Level API Trigger...\n";
$res = callTankApi($machineKey, $token, 'water', 'low');
if ($res['code'] !== 200 || empty($res['data']['success']) || $res['data']['water_tank_low'] !== 1) {
    throw new RuntimeException("Low Water API trigger failed! Raw: " . $res['raw']);
}
echo "✅ Low Water API triggered successfully. State changed: " . ($res['data']['state_changed'] ? 'YES' : 'NO') . "\n";

echo "2. Testing Low Water Level De-duplication (Repeat API Call)...\n";
$resDup = callTankApi($machineKey, $token, 'water', 'low');
if ($resDup['code'] !== 200 || $resDup['data']['state_changed'] !== false) {
    throw new RuntimeException("De-duplication failed! Expected state_changed = false. Raw: " . $resDup['raw']);
}
echo "✅ Low Water duplicate alert suppressed cleanly (state_changed = false).\n";

echo "3. Testing Low Soap Level API Trigger...\n";
$resSoap = callTankApi($machineKey, $token, 'soap', 'low');
if ($resSoap['code'] !== 200 || empty($resSoap['data']['success']) || $resSoap['data']['soap_tank_low'] !== 1) {
    throw new RuntimeException("Low Soap API trigger failed! Raw: " . $resSoap['raw']);
}
echo "✅ Low Soap API triggered successfully. State changed: " . ($resSoap['data']['state_changed'] ? 'YES' : 'NO') . "\n";

echo "4. Testing Machine Alert Evaluation...\n";
$updatedMachine = $machineModel->primaryWithServices();
$alerts = $machineModel->getMachineAlerts($updatedMachine);
$waterAlert = false;
$soapAlert = false;

foreach ($alerts as $a) {
    if ($a['title'] === 'Low Water Level') $waterAlert = true;
    if ($a['title'] === 'Low Soap Level') $soapAlert = true;
}

if (!$waterAlert || !$soapAlert) {
    throw new RuntimeException("Machine alerts missing expected tank titles! Found: " . print_r($alerts, true));
}
echo "✅ Both 'Low Water Level' and 'Low Soap Level' alerts present in machine status.\n";

echo "5. Testing Water Tank Recovery Event...\n";
$resRecWater = callTankApi($machineKey, $token, 'water', 'normal');
if ($resRecWater['code'] !== 200 || $resRecWater['data']['water_tank_low'] !== 0) {
    throw new RuntimeException("Water recovery failed! Raw: " . $resRecWater['raw']);
}
echo "✅ Water tank recovery processed cleanly. water_tank_low = 0.\n";

echo "6. Testing Soap Tank Recovery Event...\n";
$resRecSoap = callTankApi($machineKey, $token, 'soap', 'normal');
if ($resRecSoap['code'] !== 200 || $resRecSoap['data']['soap_tank_low'] !== 0) {
    throw new RuntimeException("Soap recovery failed! Raw: " . $resRecSoap['raw']);
}
echo "✅ Soap tank recovery processed cleanly. soap_tank_low = 0.\n";

echo "\n🎉 ALL LOW TANK NOTIFICATION & RECOVERY TESTS PASSED 100% SUCCESSFULLY!\n";
