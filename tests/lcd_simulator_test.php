<?php
/**
 * ============================================================
 * VendoLink — Real-Time LCD 2004A Simulator Verification Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Machine.php';

echo "=== STARTING 20x4 LCD SIMULATOR & SYNC ENGINE TESTS ===\n\n";

$machineModel = new Machine();
$machine = $machineModel->primaryWithServices();

if (!$machine) {
    throw new RuntimeException("Primary machine not found!");
}

echo "1. Testing Machine::updateLcdLines()...\n";
$line1 = "== KENJI CARWASH ==";
$line2 = "WELCOME CUSTOMER!";
$line3 = "INSERT COIN FIRST!";
$line4 = "WATER:P5 | SOAP:P5";

$updateSuccess = $machineModel->updateLcdLines((int)$machine['id'], $line1, $line2, $line3, $line4);
if (!$updateSuccess) {
    throw new RuntimeException("updateLcdLines() failed!");
}
echo "✅ Saved custom 20x4 LCD lines to database.\n\n";

echo "2. Verifying database state...\n";
$db = Database::getInstance();
$updatedMachine = $db->query("SELECT * FROM machines WHERE id = " . (int)$machine['id'])->fetch();

if ($updatedMachine['lcd_line1'] !== $line1) {
    throw new RuntimeException("lcd_line1 mismatch! Expected: {$line1}, Got: {$updatedMachine['lcd_line1']}");
}
echo "✅ Database state verified: Line 1 = '{$updatedMachine['lcd_line1']}'.\n\n";

echo "3. Testing Heartbeat API JSON Response payload...\n";
$token = $updatedMachine['api_token'];
$machineKey = $updatedMachine['machine_key'];

$ch = curl_init('http://localhost/vendolink_claude_v1/api/machine/heartbeat.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Machine-Token: ' . $token
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'machine_key' => $machineKey,
    'status' => 'online',
    'ip_address' => '192.168.1.100',
    'firmware_version' => '2.2.0'
]));

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
if (empty($data['success']) || empty($data['lcd_custom'])) {
    throw new RuntimeException("Heartbeat API failed to return lcd_custom object! Response: " . $response);
}

if ($data['lcd_custom']['line1'] !== $line1) {
    throw new RuntimeException("Heartbeat JSON lcd_custom.line1 mismatch!");
}

echo "✅ Heartbeat API returned lcd_custom object cleanly:\n";
print_r($data['lcd_custom']);

echo "\n4. Verifying Preset Template options in dashboard/machines.php...\n";
$machinesPhp = file_get_contents(__DIR__ . '/../dashboard/machines.php');
if (strpos($machinesPhp, 'Pure Water Station Template') !== false) {
    throw new RuntimeException("Pure Water Station Template was NOT removed from dashboard/machines.php!");
}
if (strpos($machinesPhp, 'Soap Dispenser Template') !== false) {
    throw new RuntimeException("Soap Dispenser Template was NOT removed from dashboard/machines.php!");
}
if (strpos($machinesPhp, "val === 'water'") !== false) {
    throw new RuntimeException("JavaScript template handler still contains 'water' branch!");
}
if (strpos($machinesPhp, "val === 'soap'") !== false) {
    throw new RuntimeException("JavaScript template handler still contains 'soap' branch!");
}
if (strpos($machinesPhp, 'Carwash Vendo Template') === false) {
    throw new RuntimeException("Carwash Vendo Template is missing from dropdown!");
}
if (strpos($machinesPhp, 'Blank / Custom') === false) {
    throw new RuntimeException("Blank / Custom is missing from dropdown!");
}
echo "✅ Preset Template dropdown options verified: 'Pure Water Station' and 'Soap Dispenser' are completely removed.\n";
echo "✅ Carwash and Blank/Custom remain available.\n";

echo "\n🎉 ALL 20x4 LCD SIMULATOR & SYNC ENGINE TESTS PASSED 100% SUCCESSFULLY!\n";

