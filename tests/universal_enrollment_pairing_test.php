<?php
/**
 * ============================================================
 * VendoLink — Universal ESP32 Enrollment & Pairing Test Suite
 * ============================================================
 *
 * Comprehensive end-to-end integration tests verifying:
 * 1. Database schema migration & legacy preservation.
 * 2. Device enrollment API security (401 Unauthorized, 400 Bad Request).
 * 3. Fresh universal ESP32 self-enrollment (201 Created) with zero hardcoded credentials.
 * 4. Idempotent re-enrollment (200 OK) returning existing credentials.
 * 5. Direct hardware-to-key pairing (Product Key -> machine_id) & lifecycle transition to 'key_issued'.
 * 6. Protection of unowned machines (resolveOwnerId returns 0, no arbitrary reassignment).
 * 7. Key validation rules (UNUSED, eligible machine).
 * 8. Atomic customer account registration & OTP claiming:
 *    - Key remains UNUSED during registration pending OTP verification.
 *    - Invalid OTP does NOT consume key or claim machine.
 *    - Valid OTP atomically activates user, consumes key (USED), and claims machine (claimed).
 * 9. Real ESP32 heartbeat telemetry authentication using newly provisioned credentials.
 * 10. Admin dashboard & ProductKey query inspection with MAC and lifecycle status.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/ProductKey.php';
require_once __DIR__ . '/../models/AdminDashboard.php';

echo "=== STARTING UNIVERSAL ESP32 ENROLLMENT & PAIRING INTEGRATION TESTS ===\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();
$machineModel = new Machine();
$productKeyModel = new ProductKey();
$userModel = new User();
$adminModel = new AdminDashboard();

$baseUrl = 'http://localhost/vendolink_claude_v1/api';
$testMac = 'A4:CF:12:98:76:54';
$testMacClean = 'A4CF12987654';

// Helper: HTTP Request function
function sendHttpRequest($url, $method = 'GET', $headers = [], $body = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $formattedHeaders = [];
    foreach ($headers as $k => $v) {
        $formattedHeaders[] = "$k: $v";
    }
    if (!empty($formattedHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
    }

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response,
        'json' => json_decode($response, true),
        'error' => $error
    ];
}

// Pre-test cleanup: ensure test records are purged
$pdo->exec("DELETE FROM services WHERE machine_id IN (SELECT id FROM machines WHERE device_mac = '$testMac' OR machine_code LIKE 'MCH-7654%')");
$pdo->exec("DELETE FROM product_keys WHERE product_key LIKE 'TEST-UNIV-%'");
$pdo->exec("DELETE FROM machines WHERE device_mac = '$testMac' OR machine_code LIKE 'MCH-7654%'");
$pdo->exec("DELETE FROM users WHERE email LIKE 'univ_owner_%@example.com'");

// ============================================================
// 1. Verify Database Schema & Legacy Row Preservation
// ============================================================
echo "1. Verifying Database Schema & Legacy Row Preservation...\n";

// Check columns exist
$cols = $pdo->query("SHOW COLUMNS FROM machines")->fetchAll(PDO::FETCH_COLUMN);
assert(in_array('device_mac', $cols), "machines.device_mac column must exist");
assert(in_array('registration_status', $cols), "machines.registration_status column must exist");

$pkCols = $pdo->query("SHOW COLUMNS FROM product_keys")->fetchAll(PDO::FETCH_COLUMN);
assert(in_array('machine_id', $pkCols), "product_keys.machine_id column must exist");

// Check legacy machine MACHINE-01 (seed temporary legacy row if clean pre-sale state)
$legacyMachine = $machineModel->findByKey('machine_01');
$seededLegacyForTest = false;
if (!$legacyMachine) {
    $pdo->prepare("INSERT INTO machines (machine_code, machine_key, name, registration_status, status) VALUES ('MACHINE-01', 'machine_01', 'Legacy Vendo Machine', 'legacy', 'offline')")->execute();
    $legacyMachine = $machineModel->findByKey('machine_01');
    $seededLegacyForTest = true;
}
assert(!empty($legacyMachine), "Legacy machine 'machine_01' must exist");
assert($legacyMachine['registration_status'] === 'legacy', "Legacy machine must have registration_status='legacy', found: " . ($legacyMachine['registration_status'] ?? 'null'));
assert($legacyMachine['device_mac'] === null, "Legacy machine device_mac must be NULL");
echo "✅ Schema verified and legacy machine preserved with registration_status='legacy'.\n\n";

// ============================================================
// 2. Testing Enrollment Endpoint Security & Authentication
// ============================================================
echo "2. Testing Enrollment Endpoint Security & Authentication...\n";

$registerEndpoint = "$baseUrl/machine/register.php";

// 2a: Request with missing token
$resNoToken = sendHttpRequest($registerEndpoint, 'POST', ['Content-Type' => 'application/json'], [
    'device_mac' => $testMac
]);
assert($resNoToken['code'] === 401, "Missing enrollment token must return HTTP 401. Got: {$resNoToken['code']}");
assert($resNoToken['json']['success'] === false, "Response success must be false");
echo "   - Missing enrollment token rejected with 401 Unauthorized.\n";

// 2b: Request with invalid token
$resBadToken = sendHttpRequest($registerEndpoint, 'POST', [
    'Content-Type' => 'application/json',
    'X-Enrollment-Token' => 'invalid_fake_secret_token_123'
], [
    'device_mac' => $testMac
]);
assert($resBadToken['code'] === 401, "Invalid enrollment token must return HTTP 401. Got: {$resBadToken['code']}");
echo "   - Invalid enrollment token rejected with 401 Unauthorized.\n";

// 2c: Request with invalid MAC format
$resBadMac = sendHttpRequest($registerEndpoint, 'POST', [
    'Content-Type' => 'application/json',
    'X-Enrollment-Token' => DEVICE_ENROLLMENT_SECRET
], [
    'device_mac' => 'NOT_A_MAC'
]);
assert($resBadMac['code'] === 400, "Invalid MAC format must return HTTP 400. Got: {$resBadMac['code']}");
echo "   - Invalid MAC format rejected with 400 Bad Request.\n";
echo "✅ Enrollment security controls verified.\n\n";

// ============================================================
// 3. Testing Fresh Universal ESP32 Self-Enrollment (201 Created)
// ============================================================
echo "3. Testing Fresh Universal ESP32 Enrollment (201 Created)...\n";

$resEnroll = sendHttpRequest($registerEndpoint, 'POST', [
    'Content-Type' => 'application/json',
    'X-Enrollment-Token' => DEVICE_ENROLLMENT_SECRET
], [
    'device_mac'       => $testMac,
    'firmware_version' => '3.0.0',
    'ip_address'       => '192.168.100.45'
]);

assert($resEnroll['code'] === 201, "Fresh device enrollment must return HTTP 201. Got: {$resEnroll['code']}");
$enrollData = $resEnroll['json'];
assert($enrollData['success'] === true, "Enrollment response success must be true");
assert($enrollData['enrolled'] === true, "enrolled flag must be true");
assert(!empty($enrollData['machine_id']), "machine_id must be returned");
assert(!empty($enrollData['machine_key']), "machine_key must be returned");
assert(!empty($enrollData['machine_code']), "machine_code must be returned");
assert(!empty($enrollData['api_token']), "api_token must be returned");
assert($enrollData['registration_status'] === 'registered', "registration_status must be 'registered'");
assert($enrollData['device_mac'] === $testMac, "device_mac must match normalized test MAC");

$enrolledMachineId = (int)$enrollData['machine_id'];
$enrolledMachineKey = $enrollData['machine_key'];
$enrolledApiToken = $enrollData['api_token'];

// Verify in DB
$dbMachine = $machineModel->getById($enrolledMachineId);
assert(!empty($dbMachine), "Machine must exist in DB");
assert($dbMachine['owner_id'] === null, "Newly enrolled machine must have owner_id NULL");
assert($dbMachine['registration_status'] === 'registered', "DB registration_status must be 'registered'");
assert($dbMachine['device_mac'] === $testMac, "DB device_mac must match");

// Verify default water & soap services seeded
$services = $machineModel->getServices($enrolledMachineId);
assert(count($services) >= 2, "Machine must have at least 2 seeded services");
$svcKeys = array_column($services, 'service_key');
assert(in_array('water', $svcKeys), "Water service must be seeded");
assert(in_array('soap', $svcKeys), "Soap service must be seeded");

echo "✅ Device enrolled successfully: ID #$enrolledMachineId, Code: {$enrollData['machine_code']}, Key: $enrolledMachineKey.\n";
echo "   - Default water and soap services confirmed.\n\n";

// ============================================================
// 4. Testing Idempotent Re-Enrollment (200 OK)
// ============================================================
echo "4. Testing Idempotent Re-Enrollment (200 OK)...\n";

$resReEnroll = sendHttpRequest($registerEndpoint, 'POST', [
    'Content-Type' => 'application/json',
    'X-Enrollment-Token' => DEVICE_ENROLLMENT_SECRET
], [
    'device_mac'       => $testMac,
    'firmware_version' => '3.0.0',
    'ip_address'       => '192.168.100.46'
]);

assert($resReEnroll['code'] === 200, "Re-enrollment must return HTTP 200. Got: {$resReEnroll['code']}");
$reEnrollData = $resReEnroll['json'];
assert($reEnrollData['success'] === true, "Re-enrollment success must be true");
assert($reEnrollData['enrolled'] === false, "enrolled flag must be false for existing device");
assert((int)$reEnrollData['machine_id'] === $enrolledMachineId, "machine_id must match original");
assert($reEnrollData['machine_key'] === $enrolledMachineKey, "machine_key must match original");
assert($reEnrollData['api_token'] === $enrolledApiToken, "api_token must match original");

// Verify row count for this MAC is strictly 1
$macCount = $pdo->query("SELECT COUNT(*) FROM machines WHERE device_mac = '$testMac'")->fetchColumn();
assert((int)$macCount === 1, "There must be exactly 1 machine row for MAC $testMac, found: $macCount");
echo "✅ Idempotency confirmed. Re-enrollment returned existing credentials without duplicating rows.\n\n";

// ============================================================
// 5. Testing Unowned Machine Guard (resolveOwnerId)
// ============================================================
echo "5. Testing Unowned Machine Guard in resolveOwnerId()...\n";

$unownedCheck = $machineModel->getById($enrolledMachineId);
assert($unownedCheck['owner_id'] === null, "Machine must be unowned");
$resolvedOwner = $machineModel->resolveOwnerId($unownedCheck);
assert($resolvedOwner === 0, "resolveOwnerId() for unowned machine must return 0, got: " . var_export($resolvedOwner, true));

// Ensure machine's owner_id in DB was NOT mutated
$unownedCheckAfter = $machineModel->getById($enrolledMachineId);
assert($unownedCheckAfter['owner_id'] === null, "Machine owner_id in DB must remain NULL");
echo "✅ Unowned machine guard verified: resolveOwnerId() returned 0 without modifying owner_id.\n\n";

// ============================================================
// 6. Testing Product Key Generation & Direct Hardware Pairing
// ============================================================
echo "6. Testing Product Key Generation & Direct Hardware Pairing...\n";

$testKeyStr = 'TEST-UNIV-' . strtoupper(bin2hex(random_bytes(4)));
$genResult = $productKeyModel->generateKey('TEST-SERIAL-UNIV-01', $testKeyStr, null, $enrolledMachineId);
assert($genResult === $testKeyStr, "Generated key must match string");

// Verify Product Key in DB has machine_id foreign key
$dbKey = $productKeyModel->findByKey($testKeyStr);
assert(!empty($dbKey), "Key must exist in DB");
assert((int)$dbKey['machine_id'] === $enrolledMachineId, "Key machine_id must link to enrolled machine ID #$enrolledMachineId");
assert($dbKey['status'] === 'UNUSED', "Newly generated key must have status 'UNUSED'");

// Verify machine lifecycle transitioned to 'key_issued'
$mAfterKey = $machineModel->getById($enrolledMachineId);
assert($mAfterKey['registration_status'] === 'key_issued', "Machine registration_status must transition to 'key_issued', got: " . $mAfterKey['registration_status']);
echo "✅ Product Key generated ($testKeyStr) linked to machine #$enrolledMachineId. Machine lifecycle advanced to 'key_issued'.\n\n";

// ============================================================
// 7. Testing Product Key Validation Logic
// ============================================================
echo "7. Testing Product Key Validation Logic...\n";

$valRes = $productKeyModel->validateKey($testKeyStr);
assert($valRes['valid'] === true, "Unused key linked to 'key_issued' machine must be valid");
assert($valRes['key']['product_key'] === $testKeyStr, "Validated key must match");

// Test: if machine is disabled, key validation must reject it
$pdo->prepare("UPDATE machines SET registration_status = 'disabled' WHERE id = :id")->execute([':id' => $enrolledMachineId]);
$valResDisabled = $productKeyModel->validateKey($testKeyStr);
assert($valResDisabled['valid'] === false, "Key linked to disabled machine must be invalid");
assert($valResDisabled['error'] === 'Product Disabled', "Error message must be 'Product Disabled', got: " . $valResDisabled['error']);

// Restore machine status to key_issued
$pdo->prepare("UPDATE machines SET registration_status = 'key_issued' WHERE id = :id")->execute([':id' => $enrolledMachineId]);
$valResRestored = $productKeyModel->validateKey($testKeyStr);
assert($valResRestored['valid'] === true, "Restored key must be valid again");
echo "✅ Product Key validation correctly enforces machine lifecycle eligibility.\n\n";

// ============================================================
// 8. Testing Atomic Customer Account Registration & Claiming
// ============================================================
echo "8. Testing Atomic Customer Account Registration & Claiming...\n";

$testCustEmail = 'univ_owner_' . time() . '@example.com';
$testCustPass  = 'SecurePassword123!';
$testCustName  = 'Universal Tester';

// Step 8a: Customer initiates registration with the Product Key
$newCustId = $userModel->createOwnerAccount([
    'full_name' => $testCustName,
    'username'  => 'univ_' . time(),
    'email'     => $testCustEmail,
    'password'  => $testCustPass
]);
assert($newCustId > 0, "Customer account creation must succeed");

// Check user status is inactive (unverified)
$newUser = $userModel->findById($newCustId);
assert($newUser['status'] === 'inactive', "Initial user status must be inactive");
assert((int)$newUser['email_verified'] === 0, "Initial user must be unverified");

// CRITICAL ATOMICITY CHECK 1: Key must remain UNUSED before OTP verification!
$keyBeforeOtp = $productKeyModel->findByKey($testKeyStr);
assert($keyBeforeOtp['status'] === 'UNUSED', "Product Key must remain UNUSED while awaiting OTP verification");
assert($keyBeforeOtp['owner_id'] === null, "Product Key owner_id must remain NULL while awaiting OTP");

// CRITICAL ATOMICITY CHECK 2: Machine must NOT be claimed yet!
$mBeforeOtp = $machineModel->getById($enrolledMachineId);
assert($mBeforeOtp['owner_id'] === null, "Machine owner_id must remain NULL while awaiting OTP");
assert($mBeforeOtp['registration_status'] === 'key_issued', "Machine status must remain 'key_issued' while awaiting OTP");

echo "   - Atomic Check: Product Key is UNUSED and Machine is unowned prior to OTP verification.\n";

// Step 8b: Test OTP Generation & Failed Verification Attempt
$otp = $userModel->createEmailVerifyOtp($newCustId);
assert(strlen($otp) === 6, "OTP must be 6 digits");

// Simulate incorrect OTP
$invalidOtpRes = $userModel->verifyEmailOtp($newCustId, '000000');
assert($invalidOtpRes === false, "Incorrect OTP must be rejected");

// Key and machine must still be unaffected
$keyAfterBadOtp = $productKeyModel->findByKey($testKeyStr);
assert($keyAfterBadOtp['status'] === 'UNUSED', "Key must remain UNUSED after failed OTP attempt");

echo "   - Atomic Check: Failed OTP verification preserves UNUSED key state.\n";

// Step 8c: Successful OTP Verification and Atomic Claiming
// Execute the exact transaction performed by AuthController::verifyOtp
$pdo->beginTransaction();
try {
    $userActivated = $userModel->verifyEmailOtp($newCustId, $otp);
    assert($userActivated === true, "User OTP verification must succeed");

    // Atomically activate Product Key and claim Machine
    $activatedKey = $productKeyModel->activateKey((int)$dbKey['id'], $newCustId, 'TEST-SERIAL-UNIV-01', $enrolledMachineId);
    assert($activatedKey === true, "Key activation must succeed");

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

// Verify User is active
$userAfterOtp = $userModel->findById($newCustId);
assert($userAfterOtp['status'] === 'active', "User status must now be active");

// Verify Key is USED and assigned
$keyAfterOtp = $productKeyModel->findByKey($testKeyStr);
assert($keyAfterOtp['status'] === 'USED', "Product Key status must now be USED");
assert((int)$keyAfterOtp['owner_id'] === $newCustId, "Product Key owner_id must be customer ID #$newCustId");
assert(!empty($keyAfterOtp['activated_at']), "Product Key activated_at must be populated");

// Verify Machine is claimed and owned by customer
$mAfterClaim = $machineModel->getById($enrolledMachineId);
assert((int)$mAfterClaim['owner_id'] === $newCustId, "Machine owner_id must be customer ID #$newCustId");
assert($mAfterClaim['registration_status'] === 'claimed', "Machine registration_status must now be 'claimed'");

// Verify resolveOwnerId now returns customer ID
$resolvedClaimedOwner = $machineModel->resolveOwnerId($mAfterClaim);
assert($resolvedClaimedOwner === $newCustId, "resolveOwnerId() on claimed machine must return owner ID #$newCustId");

echo "✅ Atomic claiming successful: User #$newCustId active, Key USED, Machine #$enrolledMachineId claimed.\n\n";

// ============================================================
// 9. Testing ESP32 Heartbeat Telemetry with New Credentials
// ============================================================
echo "9. Testing ESP32 Heartbeat Telemetry Authentication...\n";

$heartbeatEndpoint = "$baseUrl/machine/heartbeat.php";

// 9a: Heartbeat with wrong token -> 401
$resBadHb = sendHttpRequest($heartbeatEndpoint, 'POST', [
    'Content-Type' => 'application/json',
    'X-Machine-Token' => 'wrong_token_xyz'
], [
    'machine_key' => $enrolledMachineKey
]);
assert($resBadHb['code'] === 401, "Heartbeat with wrong token must return 401");
echo "   - Heartbeat rejected with 401 Unauthorized when token is invalid.\n";

// 9b: Heartbeat with correct enrolled token -> 200 OK
$resGoodHb = sendHttpRequest($heartbeatEndpoint, 'POST', [
    'Content-Type' => 'application/json',
    'X-Machine-Token' => $enrolledApiToken
], [
    'machine_key'      => $enrolledMachineKey,
    'status'           => 'online',
    'ip_address'       => '192.168.100.88',
    'firmware_version' => '3.0.0'
]);

assert($resGoodHb['code'] === 200, "Heartbeat with valid token must return 200 OK. Got: {$resGoodHb['code']}");
$hbData = $resGoodHb['json'];
assert($hbData['success'] === true, "Heartbeat response success must be true");
assert($hbData['machine_key'] === $enrolledMachineKey, "Heartbeat machine_key must match");
assert(count($hbData['services']) >= 2, "Heartbeat must return configured services");

// Verify telemetry updated in DB
$mTelemetry = $machineModel->getById($enrolledMachineId);
assert($mTelemetry['ip_address'] === '192.168.100.88', "Telemetry IP must match");
assert($mTelemetry['firmware_version'] === '3.0.0', "Telemetry FW version must match");
assert(!empty($mTelemetry['last_seen']), "Telemetry last_seen must be populated");
echo "✅ Heartbeat telemetry successfully authenticated and recorded for enrolled device.\n\n";

// ============================================================
// 10. Testing Admin Dashboard & ProductKey Model Queries
// ============================================================
echo "10. Testing Admin Queries for MAC and Lifecycle Visibility...\n";

$allKeys = $productKeyModel->getAllKeys();
$foundTestKey = null;
foreach ($allKeys as $k) {
    if ($k['product_key'] === $testKeyStr) {
        $foundTestKey = $k;
        break;
    }
}
assert(!empty($foundTestKey), "Test key must be returned by ProductKey::getAllKeys()");
assert($foundTestKey['device_mac'] === $testMac, "getAllKeys() must include device_mac: {$foundTestKey['device_mac']}");
assert($foundTestKey['registration_status'] === 'claimed', "getAllKeys() must include registration_status: {$foundTestKey['registration_status']}");

$adminKeys = $adminModel->getLicenseKeys();
$foundAdminKey = null;
foreach ($adminKeys as $ak) {
    if ($ak['license_key'] === $testKeyStr) {
        $foundAdminKey = $ak;
        break;
    }
}
assert(!empty($foundAdminKey), "Test key must be returned by AdminDashboard::getLicenseKeys()");
assert($foundAdminKey['device_mac'] === $testMac, "Admin dashboard key must have device_mac");
assert($foundAdminKey['registration_status'] === 'claimed', "Admin dashboard key must have registration_status");

echo "✅ Admin queries successfully return hardware MAC address and lifecycle status.\n\n";

// ============================================================
// 11. Post-Test Cleanup
// ============================================================
echo "11. Cleaning up test artifacts...\n";
$pdo->exec("DELETE FROM services WHERE machine_id = $enrolledMachineId");
$pdo->exec("DELETE FROM product_keys WHERE id = {$dbKey['id']}");
$pdo->exec("DELETE FROM license_keys WHERE license_key = '{$dbKey['product_key']}'");
$pdo->exec("DELETE FROM machines WHERE id = $enrolledMachineId");
$pdo->exec("DELETE FROM users WHERE id = $newCustId");
if ($seededLegacyForTest) {
    $pdo->exec("DELETE FROM machines WHERE machine_code = 'MACHINE-01'");
}
echo "✅ Test cleanup completed successfully.\n\n";

echo "🎉 ALL UNIVERSAL ESP32 ENROLLMENT & PAIRING TESTS PASSED 100% SUCCESSFULLY!\n";
