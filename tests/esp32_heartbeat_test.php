<?php
/**
 * Automated Integration Test Suite — VendoLink ESP32 Machine Heartbeat System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Machine.php';

echo "=== STARTING VENDOLINK ESP32 HEARTBEAT INTEGRATION TESTS ===\n\n";

$db           = Database::getInstance();
$machineModel = new Machine();

// 1. Verify Database Migration
echo "1. Verifying Database Schema for ESP32 Telemetry...\n";
$m = $machineModel->findByKey('machine_01');
assert(!empty($m), "Machine 01 must exist");
assert(array_key_exists('last_seen', $m), "last_seen column must exist");
assert(array_key_exists('ip_address', $m), "ip_address column must exist");
assert(array_key_exists('firmware_version', $m), "firmware_version column must exist");
assert(array_key_exists('api_token', $m), "api_token column must exist");
echo "✅ Schema columns verified.\n";

// 2. Verify Token Authentication
echo "\n2. Testing Device Security Token Authentication...\n";
$validToken = 'vl_dev_sec_9a8f3b2e7c1d4e5f6a7b8c9d0e';
assert($machineModel->verifyApiToken('machine_01', $validToken) === true, "Valid token must be accepted");
assert($machineModel->verifyApiToken('machine_01', 'wrong_token_123') === false, "Invalid token must be rejected");
echo "✅ Security token verification working correctly.\n";

// 3. Testing Real Heartbeat Telemetry Update
echo "\n3. Testing Heartbeat Telemetry Update...\n";
$testIp = '192.168.1.150';
$testFw = '1.0.0';

$updated = $machineModel->recordHeartbeat('machine_01', $testIp, $testFw, 'online');
assert($updated === true, "recordHeartbeat must return true");

$updatedMachine = $machineModel->findByKey('machine_01');
assert($updatedMachine['ip_address'] === $testIp, "IP address must match payload");
assert($updatedMachine['firmware_version'] === $testFw, "Firmware version must match payload");
assert(!empty($updatedMachine['last_seen']), "last_seen timestamp must be set");
assert($machineModel->evaluateOnlineStatus($updatedMachine) === 'online', "Fresh heartbeat must evaluate to online");
echo "✅ Telemetry updated. Machine status evaluated: ONLINE (Last seen: {$updatedMachine['last_seen']}).\n";

// 4. Testing Stale / Offline Timeout Evaluation
echo "\n4. Testing Online / Stale / Offline Timeout Evaluation...\n";

// Simulate heartbeat 90 seconds ago (stale)
$staleTime = date('Y-m-d H:i:s', time() - 90);
$updatedMachine['last_seen'] = $staleTime;
assert($machineModel->evaluateOnlineStatus($updatedMachine) === 'stale', "Heartbeat 90s ago must evaluate to stale");

// Simulate heartbeat 300 seconds ago (offline)
$offlineTime = date('Y-m-d H:i:s', time() - 300);
$updatedMachine['last_seen'] = $offlineTime;
assert($machineModel->evaluateOnlineStatus($updatedMachine) === 'offline', "Heartbeat 300s ago must evaluate to offline");
echo "✅ Dynamic online/stale/offline evaluation working correctly.\n";

// 5. Restore Fresh Online Heartbeat for UI Display
$machineModel->recordHeartbeat('machine_01', '192.168.1.120', '1.0.0', 'online');
echo "\n✅ Restored fresh ESP32 online heartbeat state.\n";

echo "\n🎉 ALL ESP32 MACHINE HEARTBEAT TESTS PASSED 100% SUCCESSFULLY!\n";
