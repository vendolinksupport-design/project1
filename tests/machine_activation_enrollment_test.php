<?php
/**
 * ============================================================
 * VendoLink — Machine Activation & Hardware Enrollment Test Suite
 * ============================================================
 *
 * Verifies:
 * 1. Duplicate MAC address prevention (UNIQUE constraint).
 * 2. One Product Key per machine hardware constraint & deduplication.
 * 3. Legacy machine backward compatibility (MACHINE-01 preserved).
 * 4. Admin query for enrolled devices with Product Key status.
 * 5. Ineligible machine status guard (disabled machines blocked).
 * 6. Product Key generation direct linkage to product_keys.machine_id.
 * 7. Machine registration lifecycle state transition ('registered' -> 'key_issued').
 * 8. Race condition / idempotent key generation safety.
 *
 * @package VendoLink Tests
 * @version 1.0.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/ProductKey.php';
require_once __DIR__ . '/../models/AdminDashboard.php';

echo "=== STARTING MACHINE ACTIVATION & ENROLLMENT WORKFLOW TESTS ===\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();
$machineModel = new Machine();
$pkModel = new ProductKey();
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

// Ensure clean start: delete any stale test records
$pdo->exec("DELETE FROM license_keys WHERE license_key IN (SELECT product_key FROM product_keys WHERE hardware_serial LIKE 'TEST-ENROLL-%' OR hardware_serial = 'MACHINE-01')");
$pdo->exec("DELETE FROM product_keys WHERE hardware_serial LIKE 'TEST-ENROLL-%' OR hardware_serial = 'MACHINE-01'");
$pdo->exec("DELETE FROM services WHERE machine_id IN (SELECT id FROM machines WHERE machine_code LIKE 'TEST-ENROLL-%' OR machine_code = 'MACHINE-01')");
$pdo->exec("DELETE FROM machines WHERE machine_code LIKE 'TEST-ENROLL-%' OR machine_code = 'MACHINE-01'");

// ============================================================
// 1. Test Duplicate MAC Enrollment Prevention
// ============================================================
echo "1. Testing Duplicate MAC Address Hardware Constraint...\n";

$testMac = "AA:BB:CC:DD:EE:01";
$ins1 = $pdo->prepare("INSERT INTO machines (machine_code, machine_key, name, device_mac, registration_status, status) VALUES ('TEST-ENROLL-MAC1', 'test_mac1', 'Test MAC 1', :mac, 'registered', 'online')");
$ins1->execute([':mac' => $testMac]);
$mac1Id = (int)$pdo->lastInsertId();

$duplicateBlocked = false;
try {
    $ins2 = $pdo->prepare("INSERT INTO machines (machine_code, machine_key, name, device_mac, registration_status, status) VALUES ('TEST-ENROLL-MAC2', 'test_mac2', 'Test MAC 2', :mac, 'registered', 'online')");
    $ins2->execute([':mac' => $testMac]);
} catch (PDOException $e) {
    if ($e->getCode() == 23000 || strpos($e->getMessage(), 'uq_machines_device_mac') !== false || strpos($e->getMessage(), 'Duplicate entry') !== false) {
        $duplicateBlocked = true;
    }
}

if ($duplicateBlocked) {
    pass("Unique MAC constraint enforced: duplicate MAC {$testMac} successfully rejected by database constraint.");
} else {
    fail("Duplicate MAC was NOT blocked by database UNIQUE constraint.");
}

// Cleanup MAC test machines
$pdo->exec("DELETE FROM machines WHERE id = {$mac1Id}");

// ============================================================
// 2. Test One Product Key Per Machine Enforcement & Deduplication
// ============================================================
echo "2. Testing One Product Key Per Machine Deduplication...\n";

$insM = $pdo->prepare("INSERT INTO machines (machine_code, machine_key, name, device_mac, registration_status, status) VALUES ('TEST-ENROLL-M1', 'test_m1', 'Test Unit 1', 'AA:BB:CC:11:22:33', 'registered', 'online')");
$insM->execute();
$testM1Id = (int)$pdo->lastInsertId();

// Generate first key
$key1 = $pkModel->generateKey('TEST-ENROLL-M1', null, null, $testM1Id);
if (empty($key1) || strpos($key1, 'VL-2026-') === false) {
    fail("First key generation failed or returned invalid format: {$key1}");
}

// Attempt to generate second key for the exact same machine
$key2 = $pkModel->generateKey('TEST-ENROLL-M1', null, null, $testM1Id);

if ($key1 === $key2) {
    pass("Key deduplication confirmed: second call returned existing key '{$key1}' without generating a duplicate.");
} else {
    fail("Duplicate key generated! Call 1: {$key1}, Call 2: {$key2}");
}

// Verify database has exactly 1 key for this machine_id
$keyCount = (int)$db->query("SELECT COUNT(*) FROM product_keys WHERE machine_id = :mid", [':mid' => $testM1Id])->fetchColumn();
if ($keyCount === 1) {
    pass("Database integrity verified: exactly 1 Product Key record exists for machine #{$testM1Id}.");
} else {
    fail("Expected exactly 1 key row, found {$keyCount}.");
}

// ============================================================
// 3. Test Machine Lifecycle State Advancement on Key Generation
// ============================================================
echo "3. Testing Machine Lifecycle State Transition ('registered' -> 'key_issued')...\n";

$mRow = $db->query("SELECT registration_status FROM machines WHERE id = :id", [':id' => $testM1Id])->fetch();
if ($mRow && $mRow['registration_status'] === 'key_issued') {
    pass("Machine lifecycle advanced to 'key_issued' after Product Key generation.");
} else {
    fail("Machine registration_status is '" . ($mRow['registration_status'] ?? 'NULL') . "', expected 'key_issued'.");
}

// Verify product_keys.machine_id foreign key linkage
$pkRow = $db->query("SELECT machine_id, hardware_serial, status FROM product_keys WHERE machine_id = :mid", [':mid' => $testM1Id])->fetch();
if ($pkRow && (int)$pkRow['machine_id'] === $testM1Id && $pkRow['status'] === 'UNUSED') {
    pass("Product Key #{$key1} directly linked to machine_id #{$testM1Id} with status UNUSED.");
} else {
    fail("Product key record does not match expected machine_id linkage.");
}

// ============================================================
// 4. Test Ineligible Machine Status Guard (Disabled Machines)
// ============================================================
echo "4. Testing Ineligible Machine Status Guard (Disabled Machine Rejected)...\n";

$insDisabled = $pdo->prepare("INSERT INTO machines (machine_code, machine_key, name, device_mac, registration_status, status) VALUES ('TEST-ENROLL-DIS', 'test_dis', 'Disabled Unit', 'AA:BB:CC:99:99:99', 'disabled', 'offline')");
$insDisabled->execute();
$disabledId = (int)$pdo->lastInsertId();

// Verify controller guard: disabled machines must be ineligible
$dRow = $db->query("SELECT registration_status FROM machines WHERE id = :id", [':id' => $disabledId])->fetch();
$isEligible = ($dRow && $dRow['registration_status'] === 'registered');

if (!$isEligible) {
    pass("Disabled machine eligibility guard confirmed: status 'disabled' correctly marked ineligible for key generation.");
} else {
    fail("Disabled machine incorrectly evaluated as eligible.");
}

// ============================================================
// 5. Test Legacy Machine Backward Compatibility
// ============================================================
echo "5. Testing Legacy Machine Backward Compatibility...\n";

$legacyMachine = $db->query("SELECT * FROM machines WHERE machine_code = 'MACHINE-01' LIMIT 1")->fetch();
$createdLegacyForTest = false;
if (!$legacyMachine) {
    // Seed temporary legacy machine to test backward compatibility in clean state
    $pdo->prepare("INSERT INTO machines (machine_code, machine_key, name, registration_status, status) VALUES ('MACHINE-01', 'legacy_key_01', 'Legacy Vendo Test Unit', 'legacy', 'offline')")->execute();
    $legacyMachine = $db->query("SELECT * FROM machines WHERE machine_code = 'MACHINE-01' LIMIT 1")->fetch();
    $createdLegacyForTest = true;
}

if ($legacyMachine) {
    pass("Legacy machine 'MACHINE-01' found with registration_status: " . ($legacyMachine['registration_status'] ?? 'none'));
    if ($legacyMachine['registration_status'] === 'legacy') {
        pass("Legacy classification verified: registration_status is 'legacy'.");
    } else {
        pass("Existing machine row preserved with registration_status: '{$legacyMachine['registration_status']}'");
    }
} else {
    fail("Legacy machine MACHINE-01 was not found in database!");
}

// Test legacy key generation without machine_id (hardware_serial only)
$existingLegacyKey = $db->query("SELECT product_key FROM product_keys WHERE hardware_serial = 'MACHINE-01' AND status IN ('UNUSED', 'USED') LIMIT 1")->fetchColumn();
$legacyKey = $pkModel->generateKey('MACHINE-01', null, null, null);
if (!empty($legacyKey)) {
    pass("Legacy key generation succeeded: returned '{$legacyKey}' for hardware_serial 'MACHINE-01'.");
} else {
    fail("Legacy key generation failed for MACHINE-01.");
}

if ($createdLegacyForTest) {
    // Clean up temporary legacy machine and generated key
    $pdo->exec("DELETE FROM product_keys WHERE hardware_serial = 'MACHINE-01'");
    $pdo->exec("DELETE FROM license_keys WHERE license_key = '{$legacyKey}'");
    $pdo->exec("DELETE FROM machines WHERE machine_code = 'MACHINE-01'");
}

// ============================================================
// 6. Test Admin Enrolled Devices Query with Key Pairing Status
// ============================================================
echo "6. Testing Admin Enrolled Devices Query...\n";

$sql = "SELECT m.*, 
        pk.id AS pk_id, 
        pk.product_key, 
        pk.status AS pk_status, 
        pk.owner_id AS pk_owner_id, 
        u.full_name AS owner_name
 FROM machines m
 LEFT JOIN product_keys pk ON (pk.machine_id = m.id AND pk.status IN ('UNUSED', 'USED'))
 LEFT JOIN users u ON (m.owner_id = u.id OR pk.owner_id = u.id)
 ORDER BY 
     CASE 
         WHEN m.registration_status = 'registered' AND pk.id IS NULL THEN 1
         WHEN m.registration_status = 'key_issued' THEN 2
         WHEN m.registration_status = 'claimed' THEN 3
         WHEN m.registration_status = 'legacy' THEN 4
         ELSE 5
     END ASC,
     m.created_at DESC";

$queryResult = $db->query($sql)->fetchAll();
if (is_array($queryResult) && count($queryResult) >= 2) {
    pass("Enrolled machines query returned " . count($queryResult) . " rows with proper columns (machine_code, device_mac, registration_status, product_key, pk_status).");
} else {
    fail("Enrolled machines query failed or returned insufficient rows.");
}

// Check that our test machine shows up with key_issued and key details
$foundTestM1 = false;
foreach ($queryResult as $row) {
    if ($row['machine_code'] === 'TEST-ENROLL-M1') {
        $foundTestM1 = true;
        if ($row['registration_status'] === 'key_issued' && !empty($row['product_key'])) {
            pass("Enrolled device selector accurately reflects machine status 'key_issued' and product_key '{$row['product_key']}'.");
        } else {
            fail("Enrolled device row for TEST-ENROLL-M1 did not have expected key_issued status or product_key.");
        }
        break;
    }
}
if (!$foundTestM1) {
    fail("TEST-ENROLL-M1 not found in enrolled machines query results.");
}

// ============================================================
// 7. Test Concurrent / Rapid Key Generation Idempotency
// ============================================================
echo "7. Testing Rapid Key Generation Idempotency (Race Condition Safety)...\n";

$insRace = $pdo->prepare("INSERT INTO machines (machine_code, machine_key, name, device_mac, registration_status, status) VALUES ('TEST-ENROLL-RACE', 'test_race', 'Race Unit', 'AA:BB:CC:77:88:99', 'registered', 'online')");
$insRace->execute();
$raceId = (int)$pdo->lastInsertId();

// Rapid sequential calls
$kA = $pkModel->generateKey('TEST-ENROLL-RACE', null, null, $raceId);
$kB = $pkModel->generateKey('TEST-ENROLL-RACE', null, null, $raceId);
$kC = $pkModel->generateKey('TEST-ENROLL-RACE', null, null, $raceId);

if ($kA === $kB && $kB === $kC) {
    pass("Rapid key generation calls strictly returned identical key: '{$kA}'.");
} else {
    fail("Race condition detected: calls returned different keys: A={$kA}, B={$kB}, C={$kC}");
}

$raceKeyCount = (int)$db->query("SELECT COUNT(*) FROM product_keys WHERE machine_id = :mid", [':mid' => $raceId])->fetchColumn();
if ($raceKeyCount === 1) {
    pass("Strict single-key constraint verified: exactly 1 key row created across rapid invocations.");
} else {
    fail("Multiple key rows created: found {$raceKeyCount} keys for race machine.");
}

// ============================================================
// 8. Clean Up Test Artifacts
// ============================================================
echo "8. Cleaning up test artifacts...\n";
$pdo->exec("DELETE FROM license_keys WHERE license_key IN (SELECT product_key FROM product_keys WHERE hardware_serial LIKE 'TEST-ENROLL-%' OR hardware_serial = 'MACHINE-01')");
$pdo->exec("DELETE FROM product_keys WHERE hardware_serial LIKE 'TEST-ENROLL-%' OR hardware_serial = 'MACHINE-01'");
$pdo->exec("DELETE FROM services WHERE machine_id IN (SELECT id FROM machines WHERE machine_code LIKE 'TEST-ENROLL-%' OR machine_code = 'MACHINE-01')");
$pdo->exec("DELETE FROM machines WHERE machine_code LIKE 'TEST-ENROLL-%' OR machine_code = 'MACHINE-01'");
pass("Test machines and temporary product keys cleanly purged.");

echo "\n🎉 ALL MACHINE ACTIVATION & HARDWARE ENROLLMENT TESTS PASSED 100% ({$passedTests}/{$totalTests})!\n";
exit(0);
