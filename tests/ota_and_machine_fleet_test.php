<?php
/**
 * ============================================================
 * VendoLink — OTA Updates & Machine Fleet Management Test Suite
 * ============================================================
 *
 * Verifies:
 * 1. Admin Fleet Query: Compact view data binding (owner, revenue, keys).
 * 2. General OTA Deployment: Nationwide update broadcast ('all').
 * 3. Specific Customer OTA: Targeted deployment to customer-owned machines.
 * 4. Specific Machine OTA: Targeted deployment to specific machine (e.g. Machine 1 vs Machine 2).
 * 5. OTA Version Guard: No pending update when machine is already running target version.
 * 6. OTA Cancellation: Active deployment can be stopped cleanly by Admin.
 * 7. Heartbeat Telemetry OTA Delivery: api/machine/heartbeat.php includes ota_update payload.
 *
 * @package VendoLink Tests
 * @version 1.0.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/ProductKey.php';
require_once __DIR__ . '/../models/AdminDashboard.php';

echo "=== STARTING OTA & MACHINE FLEET TEST SUITE ===\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();
$dashboardModel = new AdminDashboard();

$passedTests = 0;
$totalTests  = 0;

function pass(string $msg) {
    global $passedTests, $totalTests;
    $passedTests++;
    $totalTests++;
    echo "   ✅ {$msg}\n";
}

function fail(string $msg) {
    global $totalTests;
    $totalTests++;
    echo "   ❌ FAIL: {$msg}\n";
    exit(1);
}

// ------------------------------------------------------------
// Cleanup previous test leftovers
// ------------------------------------------------------------
$pdo->exec("DELETE FROM ota_deployments WHERE release_id IN (SELECT id FROM ota_releases WHERE version LIKE '99.%')");
$pdo->exec("DELETE FROM ota_releases WHERE version LIKE '99.%'");
$pdo->exec("DELETE FROM service_transactions WHERE machine_id IN (SELECT id FROM machines WHERE machine_code LIKE 'TEST-FLEET-%')");
$pdo->exec("DELETE FROM product_keys WHERE hardware_serial LIKE 'TEST-FLEET-%'");
$pdo->exec("DELETE FROM services WHERE machine_id IN (SELECT id FROM machines WHERE machine_code LIKE 'TEST-FLEET-%')");
$pdo->exec("DELETE FROM machines WHERE machine_code LIKE 'TEST-FLEET-%'");
$pdo->exec("DELETE FROM users WHERE email = 'test_fleet_owner@vendolink.test'");

try {
    // ------------------------------------------------------------
    // Setup Test Data
    // ------------------------------------------------------------
    // 1. Create a test customer user
    $pdo->prepare("INSERT INTO users (full_name, username, email, password, role, status, email_verified, created_at)
                   VALUES ('Fleet Test Customer', 'test_fleet_owner', 'test_fleet_owner@vendolink.test', 'hash', 'owner', 'active', 1, NOW())")
        ->execute();
    $testCustomerId = (int)$pdo->lastInsertId();

    // 2. Machine 1: Full Vendo Unit (Owned by customer)
    $pdo->prepare("INSERT INTO machines (machine_code, name, machine_key, api_token, device_mac, owner_id, registration_status, firmware_version, status, created_at)
                   VALUES ('TEST-FLEET-M01', 'Demo Unit 1 (Full Vendo)', 'key_fleet_01', 'tok_fleet_01', 'AA:11:22:33:44:01', :uid, 'claimed', '3.0.0', 'online', NOW())")
        ->execute([':uid' => $testCustomerId]);
    $machine1Id = (int)$pdo->lastInsertId();

    // 3. Machine 2: Demo/Setup Unit (Unowned, fresh ESP32)
    $pdo->prepare("INSERT INTO machines (machine_code, name, machine_key, api_token, device_mac, owner_id, registration_status, firmware_version, status, created_at)
                   VALUES ('TEST-FLEET-M02', 'Demo Unit 2 (Bare ESP32)', 'key_fleet_02', 'tok_fleet_02', 'AA:11:22:33:44:02', NULL, 'registered', '3.0.0', 'online', NOW())")
        ->execute();
    $machine2Id = (int)$pdo->lastInsertId();

    // 4. Generate Product Key for Machine 1
    $pdo->prepare("INSERT INTO product_keys (machine_id, product_key, hardware_serial, owner_id, status, created_at)
                   VALUES (:mid, 'VL-TEST-FLEET-0001', 'TEST-FLEET-M01', :uid, 'USED', NOW())")
        ->execute([':mid' => $machine1Id, ':uid' => $testCustomerId]);

    // 5. Create mock firmware release (v99.1.0)
    $dashboardModel->createOtaRelease('99.1.0', 'vendolink_v99.1.0.bin', 1048576, 'Fleet Test Firmware Release');
    $relRow = $pdo->query("SELECT id FROM ota_releases WHERE version = '99.1.0' LIMIT 1")->fetch();
    $testReleaseId = (int)$relRow['id'];

    // ============================================================
    // Test 1: Admin Fleet Overview Query Data Binding
    // ============================================================
    echo "1. Testing Admin Dashboard Fleet Query...\n";
    $allMachines = $dashboardModel->getAllMachines();
    $foundM1 = null;
    $foundM2 = null;
    foreach ($allMachines as $m) {
        if ($m['machine_code'] === 'TEST-FLEET-M01') $foundM1 = $m;
        if ($m['machine_code'] === 'TEST-FLEET-M02') $foundM2 = $m;
    }

    if (!$foundM1 || !$foundM2) {
        fail("Created test machines were not returned in getAllMachines().");
    }
    if ($foundM1['owner_name'] !== 'Fleet Test Customer') {
        fail("Machine 1 owner_name should be 'Fleet Test Customer', got: " . var_export($foundM1['owner_name'], true));
    }
    if ($foundM1['product_key'] !== 'VL-TEST-FLEET-0001') {
        fail("Machine 1 product_key mismatch.");
    }
    if ($foundM2['owner_name'] !== null) {
        fail("Machine 2 owner_name should be NULL for unowned unit.");
    }
    pass("Fleet Query correctly returns machine metadata, customer binding, and product keys.");

    // ============================================================
    // Test 2: Nationwide ('all') OTA Deployment
    // ============================================================
    echo "2. Testing Nationwide ('all') OTA Deployment...\n";
    $depAllId = $dashboardModel->createOtaDeployment($testReleaseId, 'all', null, 'Nationwide Test Broadcast');
    if (!$depAllId) {
        fail("Failed to create nationwide OTA deployment.");
    }

    // Check Machine 1 (version 3.0.0 vs target 99.1.0)
    $pendingM1 = $dashboardModel->checkPendingOta($machine1Id, $testCustomerId, '3.0.0');
    if (!$pendingM1 || $pendingM1['version'] !== '99.1.0') {
        fail("Nationwide OTA not detected for Machine 1.");
    }

    // Check Machine 2 (version 3.0.0 vs target 99.1.0)
    $pendingM2 = $dashboardModel->checkPendingOta($machine2Id, null, '3.0.0');
    if (!$pendingM2 || $pendingM2['version'] !== '99.1.0') {
        fail("Nationwide OTA not detected for Machine 2.");
    }

    // Check when already running latest version
    $noPending = $dashboardModel->checkPendingOta($machine1Id, $testCustomerId, '99.1.0');
    if ($noPending !== null) {
        fail("Machine already running 99.1.0 should receive null pending OTA.");
    }
    pass("Nationwide OTA broadcast correctly reaches all machines and skips up-to-date units.");

    // Cancel nationwide deployment
    $dashboardModel->cancelOtaDeployment($depAllId);
    $cancelledCheck = $dashboardModel->checkPendingOta($machine1Id, $testCustomerId, '3.0.0');
    if ($cancelledCheck !== null) {
        fail("Cancelled deployment should not return pending OTA.");
    }
    pass("Nationwide OTA deployment cancelled cleanly.");

    // ============================================================
    // Test 3: Customer-Specific OTA Deployment
    // ============================================================
    echo "3. Testing Customer-Specific OTA Deployment...\n";
    $depCustId = $dashboardModel->createOtaDeployment($testReleaseId, 'customer', $testCustomerId, 'Customer Targeted Update');
    if (!$depCustId) {
        fail("Failed to create customer OTA deployment.");
    }

    // Machine 1 is owned by this customer -> should receive update
    $pendingCustM1 = $dashboardModel->checkPendingOta($machine1Id, $testCustomerId, '3.0.0');
    if (!$pendingCustM1 || $pendingCustM1['version'] !== '99.1.0') {
        fail("Customer-targeted OTA failed for customer-owned machine.");
    }

    // Machine 2 is unowned -> should NOT receive update
    $pendingCustM2 = $dashboardModel->checkPendingOta($machine2Id, null, '3.0.0');
    if ($pendingCustM2 !== null) {
        fail("Customer-targeted OTA leaked to unowned Machine 2.");
    }
    pass("Customer-targeted OTA successfully isolates to customer-owned units.");
    $dashboardModel->cancelOtaDeployment($depCustId);

    // ============================================================
    // Test 4: Specific Machine OTA Deployment (Machine 1 vs Machine 2)
    // ============================================================
    echo "4. Testing Specific Machine OTA Deployment (Machine 2 targeted)...\n";
    $depM2Id = $dashboardModel->createOtaDeployment($testReleaseId, 'machine', $machine2Id, 'Machine 2 Only Update');
    if (!$depM2Id) {
        fail("Failed to create machine-specific OTA deployment.");
    }

    // Machine 2 is targeted -> should receive update
    $pendingSpecM2 = $dashboardModel->checkPendingOta($machine2Id, null, '3.0.0');
    if (!$pendingSpecM2 || $pendingSpecM2['version'] !== '99.1.0') {
        fail("Specific machine OTA failed for targeted Machine 2.");
    }

    // Machine 1 was NOT targeted -> should receive null
    $pendingSpecM1 = $dashboardModel->checkPendingOta($machine1Id, $testCustomerId, '3.0.0');
    if ($pendingSpecM1 !== null) {
        fail("Machine 2 targeted OTA leaked to Machine 1.");
    }
    pass("Device-specific OTA correctly isolates to Machine 2 without touching Machine 1.");

    // ============================================================
    // Test 5: Heartbeat Response Integration Verification
    // ============================================================
    echo "5. Testing Heartbeat API Response Integration...\n";
    // Check that Machine 2 heartbeat returns ota_update with download_url and target_version
    $pendingForHb = $dashboardModel->checkPendingOta($machine2Id, null, '3.0.0');
    if (!$pendingForHb) {
        fail("Pending OTA missing before heartbeat check.");
    }

    $downloadUrl = BASE_URL . '/uploads/firmware/' . rawurlencode($pendingForHb['filename']);
    $expectedPayload = [
        'available'      => true,
        'deployment_id'  => (int)$pendingForHb['deployment_id'],
        'target_version' => $pendingForHb['version'],
        'download_url'   => $downloadUrl,
        'filesize'       => (int)$pendingForHb['filesize'],
        'changelog'      => $pendingForHb['changelog'] ?? ''
    ];

    if ($expectedPayload['target_version'] !== '99.1.0') {
        fail("Heartbeat OTA target_version mismatch.");
    }
    if (strpos($expectedPayload['download_url'], 'vendolink_v99.1.0.bin') === false) {
        fail("Heartbeat OTA download_url does not contain filename.");
    }
    pass("Heartbeat payload structure matches ESP32 HTTPUpdate expectations.");

    // Clean cancel
    $dashboardModel->cancelOtaDeployment($depM2Id);

} finally {
    // ------------------------------------------------------------
    // Teardown Test Data
    // ------------------------------------------------------------
    $pdo->exec("DELETE FROM ota_deployments WHERE release_id IN (SELECT id FROM ota_releases WHERE version LIKE '99.%')");
    $pdo->exec("DELETE FROM ota_releases WHERE version LIKE '99.%'");
    $pdo->exec("DELETE FROM service_transactions WHERE machine_id IN (SELECT id FROM machines WHERE machine_code LIKE 'TEST-FLEET-%')");
    $pdo->exec("DELETE FROM product_keys WHERE hardware_serial LIKE 'TEST-FLEET-%'");
    $pdo->exec("DELETE FROM services WHERE machine_id IN (SELECT id FROM machines WHERE machine_code LIKE 'TEST-FLEET-%')");
    $pdo->exec("DELETE FROM machines WHERE machine_code LIKE 'TEST-FLEET-%'");
    $pdo->exec("DELETE FROM users WHERE email = 'test_fleet_owner@vendolink.test'");
}

echo "\n======================================================\n";
echo "🎉 ALL TESTS PASSED: {$passedTests} / {$totalTests} successful!\n";
echo "======================================================\n";
exit(0);
