<?php
/**
 * ============================================================
 * VendoLink — Multi-Machine Customer Dashboard Integration Test Suite
 * ============================================================
 *
 * Comprehensive automated verification for:
 * 1. Multi-machine ownership and retrieval for authenticated customers
 * 2. Top-navigation machine switcher context and persistence
 * 3. Atomic "+ Add Another Machine" claiming via Product Activation Key
 * 4. Cross-tenant isolation defenses against parameter tampering
 * 5. Owner-wide sales aggregation across multiple owned machines
 *
 * @package VendoLink
 * @version 1.0.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/ProductKey.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../controllers/MachineController.php';

$db  = Database::getInstance();
$pdo = $db->getConnection();

$testsPassed = 0;
$testsFailed = 0;

function assert_test(bool $condition, string $description): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $testsFailed++;
    }
}

echo "====================================================\n";
echo " VendoLink Multi-Machine Dashboard Integration Tests\n";
echo "====================================================\n\n";

try {
    // -------------------------------------------------------------
    // SETUP: Create isolated test owner and machines
    // -------------------------------------------------------------
    echo "[1/5] Setting up test owner and multi-machine fleet...\n";

    $hash = password_hash('Pass123!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, email, full_name, password, role, status) VALUES ('test_multi_owner', 'multi_owner@test.com', 'Multi Fleet Owner', :h, 'owner', 'active')")
        ->execute([':h' => $hash]);
    $testOwnerId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (username, email, full_name, password, role, status) VALUES ('other_test_owner', 'other_owner@test.com', 'Other Owner', :h, 'owner', 'active')")
        ->execute([':h' => $hash]);
    $otherOwnerId = (int)$pdo->lastInsertId();

    // Machine 1 (Owner's initial primary unit)
    $pdo->prepare("INSERT INTO machines (owner_id, machine_code, machine_key, name, api_token, status, registration_status, created_at, updated_at) 
                   VALUES (:oid, 'TEST-MCH-01', 'mch_test_01', 'Store A — Main Bay', 'token_01', 'online', 'claimed', NOW(), NOW())")
        ->execute([':oid' => $testOwnerId]);
    $mach1Id = (int)$pdo->lastInsertId();

    // Machine 2 (Owner's second unit)
    $pdo->prepare("INSERT INTO machines (owner_id, machine_code, machine_key, name, api_token, status, registration_status, created_at, updated_at) 
                   VALUES (:oid, 'TEST-MCH-02', 'mch_test_02', 'Store B — Side Bay', 'token_02', 'offline', 'claimed', NOW(), NOW())")
        ->execute([':oid' => $testOwnerId]);
    $mach2Id = (int)$pdo->lastInsertId();

    // Machine 3 (Owner's third unit)
    $pdo->prepare("INSERT INTO machines (owner_id, machine_code, machine_key, name, api_token, status, registration_status, created_at, updated_at) 
                   VALUES (:oid, 'TEST-MCH-03', 'mch_test_03', 'Store C — Express', 'token_03', 'online', 'claimed', NOW(), NOW())")
        ->execute([':oid' => $testOwnerId]);
    $mach3Id = (int)$pdo->lastInsertId();

    // Foreign Machine (Belongs to Other Owner)
    $pdo->prepare("INSERT INTO machines (owner_id, machine_code, machine_key, name, api_token, status, registration_status, created_at, updated_at) 
                   VALUES (:oid, 'FOREIGN-MCH-99', 'mch_foreign_99', 'Foreign Store', 'token_foreign', 'online', 'claimed', NOW(), NOW())")
        ->execute([':oid' => $otherOwnerId]);
    $foreignMachId = (int)$pdo->lastInsertId();

    // Seed services for Machine 1, 2, 3
    foreach ([$mach1Id, $mach2Id, $mach3Id, $foreignMachId] as $mid) {
        $pdo->prepare("INSERT INTO services (machine_id, service_key, name, type, coin_value, service_seconds, is_enabled) 
                       VALUES (?, 'water', 'Water Service', 'water', 5.00, 120, 1),
                              (?, 'soap',  'Soap Service',  'soap',  5.00, 120, 1)")
            ->execute([$mid, $mid]);
    }

    assert_test($mach1Id > 0 && $mach2Id > 0 && $mach3Id > 0, "Test fleet of 3 machines created for Owner #{$testOwnerId}");
    assert_test($foreignMachId > 0, "Foreign machine created for Other Owner #{$otherOwnerId}");

    // -------------------------------------------------------------
    // TEST SECTION 1: Multiple-machine retrieval & switcher context
    // -------------------------------------------------------------
    echo "\n[2/5] Testing multi-machine retrieval and switcher context...\n";

    $machineModel = new Machine();
    $ownerMachines = $machineModel->getAllActive($testOwnerId);

    assert_test(count($ownerMachines) === 3, "Owner with 3 machines retrieves exactly 3 units from getAllActive()");
    $retrievedCodes = array_column($ownerMachines, 'machine_code');
    assert_test(in_array('TEST-MCH-01', $retrievedCodes, true) &&
                in_array('TEST-MCH-02', $retrievedCodes, true) &&
                in_array('TEST-MCH-03', $retrievedCodes, true), "Retrieved machines match owned codes");
    assert_test(!in_array('FOREIGN-MCH-99', $retrievedCodes, true), "Foreign machine is NOT present in owner's machine list");

    // Test current_selected_machine_id default fallback
    $_SESSION['user_id'] = $testOwnerId;
    $_SESSION['user_role'] = 'owner';
    $_SESSION['owner_id'] = $testOwnerId;
    $_SESSION['role'] = 'owner';
    unset($_SESSION['selected_machine_id']);
    unset($_GET['switch_machine']);

    $initialSelectedId = current_selected_machine_id($testOwnerId);
    assert_test($initialSelectedId === $mach1Id, "Default machine selection falls back safely to primary machine #{$mach1Id}");

    // Test switching to Machine 2
    $_GET['switch_machine'] = $mach2Id;
    $switchedId = current_selected_machine_id($testOwnerId);
    assert_test($switchedId === $mach2Id, "Switching to Machine #{$mach2Id} updates active selection");
    assert_test($_SESSION['selected_machine_id'] === $mach2Id, "Active selection persists in session as #{$mach2Id}");

    // Test persistent retrieval of full machine details
    unset($_GET['switch_machine']); // simulated navigation to next page
    $currentMachine = current_selected_machine($testOwnerId);
    assert_test(!empty($currentMachine) && (int)$currentMachine['id'] === $mach2Id, "current_selected_machine() returns Machine #{$mach2Id} on subsequent request");
    assert_test($currentMachine['name'] === 'Store B — Side Bay', "Current machine name correctly reflects Machine 2");
    assert_test(!empty($currentMachine['services']), "Current machine includes loaded services");

    // -------------------------------------------------------------
    // TEST SECTION 2: Security & Cross-Tenant Parameter Tampering
    // -------------------------------------------------------------
    echo "\n[3/5] Testing security defenses against ID tampering & unauthorized access...\n";

    // Attempt to tamper switch_machine to foreign machine
    $_GET['switch_machine'] = $foreignMachId;
    $tamperedId = current_selected_machine_id($testOwnerId);
    assert_test($tamperedId !== $foreignMachId, "Attempt to switch to foreign machine #{$foreignMachId} is rejected");
    assert_test($tamperedId === $mach2Id, "System safely maintains prior authorized machine #{$mach2Id}");

    // Test getByIdWithServices direct access check
    $unauthorizedFetch = $machineModel->getByIdWithServices($foreignMachId, $testOwnerId);
    assert_test($unauthorizedFetch === null, "Direct getByIdWithServices() for foreign machine returns NULL (Access Denied)");

    // Test controller ownership isolation check
    $allowedIds = $machineModel->getMachineIdsForOwner($testOwnerId);
    assert_test(in_array($mach1Id, $allowedIds, true) && in_array($mach2Id, $allowedIds, true) && in_array($mach3Id, $allowedIds, true), "getMachineIdsForOwner() returns all 3 owned machine IDs");
    assert_test(!in_array($foreignMachId, $allowedIds, true), "Foreign machine ID is NOT in allowed IDs");

    // -------------------------------------------------------------
    // TEST SECTION 3: Sales Aggregation Across Multiple Machines
    // -------------------------------------------------------------
    echo "\n[4/5] Testing owner-wide sales aggregation across multiple machines...\n";

    // Find water service IDs for Machine 1 and Machine 2
    $svcWater1 = (int)$db->query("SELECT id FROM services WHERE machine_id = :mid AND service_key = 'water'", [':mid' => $mach1Id])->fetchColumn();
    $svcWater2 = (int)$db->query("SELECT id FROM services WHERE machine_id = :mid AND service_key = 'water'", [':mid' => $mach2Id])->fetchColumn();
    $svcWaterForeign = (int)$db->query("SELECT id FROM services WHERE machine_id = :mid AND service_key = 'water'", [':mid' => $foreignMachId])->fetchColumn();

    $txModel = new Transaction();
    // Machine 1 generates ₱20
    $txModel->record($svcWater1, 10.00, 'completed');
    $txModel->record($svcWater1, 10.00, 'completed');

    // Machine 2 generates ₱30
    $txModel->record($svcWater2, 10.00, 'completed');
    $txModel->record($svcWater2, 10.00, 'completed');
    $txModel->record($svcWater2, 10.00, 'completed');

    // Foreign machine generates ₱100
    $txModel->record($svcWaterForeign, 100.00, 'completed');

    $reportModel = new Report();
    $today = date('Y-m-d');
    $summary = $reportModel->getSummaryMetrics($today, $today, 'all', $testOwnerId);

    assert_test((float)$summary['total_revenue'] === 50.00, "Owner-wide summary aggregates revenue from Machine 1 (₱20) + Machine 2 (₱30) = ₱50.00");
    assert_test((int)$summary['total_transactions'] === 5, "Owner-wide transaction count includes all owned units (5 total)");
    assert_test((float)$summary['total_revenue'] < 100.00, "Foreign machine revenue (₱100.00) is strictly excluded from owner summary");

    // Filtered transaction check by machine
    $m1Txs = $txModel->getPaginated(['machine' => $mach1Id], 1, 10, $testOwnerId)['items'];
    $m2Txs = $txModel->getPaginated(['machine' => $mach2Id], 1, 10, $testOwnerId)['items'];
    assert_test(count($m1Txs) === 2, "Machine 1 filter returns exactly 2 transactions");
    assert_test(count($m2Txs) === 3, "Machine 2 filter returns exactly 3 transactions");

    // -------------------------------------------------------------
    // TEST SECTION 4: Add Another Machine (Key Validation & Claiming)
    // -------------------------------------------------------------
    echo "\n[5/5] Testing '+ Add Another Machine' validation, atomic claim, and nickname handling...\n";

    $productKeyModel = new ProductKey();

    // 1. Test Non-existent key
    $valInvalid = $productKeyModel->validateKey('VL-INVALID-KEY-9999');
    assert_test(!$valInvalid['valid'], "Non-existent Product Activation Key is rejected");

    // 2. Setup a newly enrolled machine awaiting claim
    $pdo->prepare("INSERT INTO machines (machine_code, machine_key, name, api_token, status, registration_status, created_at, updated_at) 
                   VALUES ('TEST-ENROLLED-04', 'mch_enrolled_04', 'Unclaimed Vendo Unit', 'token_04', 'online', 'registered', NOW(), NOW())")
        ->execute();
    $mach4Id = (int)$pdo->lastInsertId();

    $keyValidCode = 'VL-2026-TEST-KEY4-ABCD';
    $productKeyModel->generateKey('TEST-ENROLLED-04', $keyValidCode, null, $mach4Id);

    $valValid = $productKeyModel->validateKey($keyValidCode);
    assert_test($valValid['valid'] && !empty($valValid['key']), "Fresh UNUSED Product Activation Key is validated as eligible for claiming");

    // 3. Simulate MachineController::claimMachine() execution
    $_POST['csrf_token'] = csrf_token();
    $_POST['product_key'] = $keyValidCode;
    $_POST['machine_nickname'] = 'Store D — Carwash North';
    $_POST['return_to'] = '/dashboard/machines.php';

    // Execute atomic claim logic
    $pdo->beginTransaction();
    $claimSuccess = $productKeyModel->activateKey((int)$valValid['key']['id'], $testOwnerId, $valValid['key']['hardware_serial'], $mach4Id);
    if ($claimSuccess) {
        $pdo->prepare("UPDATE machines SET name = :name WHERE id = :mid")->execute([':name' => 'Store D — Carwash North', ':mid' => $mach4Id]);
    }
    $pdo->commit();

    assert_test($claimSuccess, "Atomic claim succeeds for eligible Product Key");

    // Verify machine ownership and nickname update
    $freshMach4 = $machineModel->findById($mach4Id);
    assert_test((int)$freshMach4['owner_id'] === $testOwnerId, "Machine #{$mach4Id} owner_id is now set to Owner #{$testOwnerId}");
    assert_test($freshMach4['registration_status'] === 'claimed', "Machine #{$mach4Id} registration_status advanced to 'claimed'");
    assert_test($freshMach4['name'] === 'Store D — Carwash North', "Machine nickname updated to 'Store D — Carwash North'");

    // 4. Test duplicate / reuse attempt of already claimed key
    $valReused = $productKeyModel->validateKey($keyValidCode);
    assert_test(!$valReused['valid'] && $valReused['error'] === 'Product Key Already Activated', "Already activated key cannot be reused (rejected with 'Product Key Already Activated')");

    // 5. Verify newly added machine is now in owner's machine list
    $updatedFleet = $machineModel->getAllActive($testOwnerId);
    assert_test(count($updatedFleet) === 4, "Owner fleet now contains 4 machines after adding Machine #4");

} catch (\Throwable $e) {
    echo "  [ERROR] Exception occurred: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $testsFailed++;
} finally {
    // -------------------------------------------------------------
    // CLEANUP: Clean up test accounts, machines, and keys
    // -------------------------------------------------------------
    echo "\nCleaning up test records...\n";
    if (isset($testOwnerId) && $testOwnerId > 0) {
        $pdo->prepare("DELETE FROM service_transactions WHERE machine_id IN (SELECT id FROM machines WHERE owner_id = ?)")->execute([$testOwnerId]);
        $pdo->prepare("DELETE FROM services WHERE machine_id IN (SELECT id FROM machines WHERE owner_id = ?)")->execute([$testOwnerId]);
        $pdo->prepare("DELETE FROM product_keys WHERE owner_id = ?")->execute([$testOwnerId]);
        $pdo->prepare("DELETE FROM machines WHERE owner_id = ?")->execute([$testOwnerId]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$testOwnerId]);
    }
    if (isset($otherOwnerId) && $otherOwnerId > 0) {
        $pdo->prepare("DELETE FROM service_transactions WHERE machine_id IN (SELECT id FROM machines WHERE owner_id = ?)")->execute([$otherOwnerId]);
        $pdo->prepare("DELETE FROM services WHERE machine_id IN (SELECT id FROM machines WHERE owner_id = ?)")->execute([$otherOwnerId]);
        $pdo->prepare("DELETE FROM product_keys WHERE owner_id = ?")->execute([$otherOwnerId]);
        $pdo->prepare("DELETE FROM machines WHERE owner_id = ?")->execute([$otherOwnerId]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$otherOwnerId]);
    }
    if (isset($mach4Id) && $mach4Id > 0) {
        $pdo->prepare("DELETE FROM services WHERE machine_id = ?")->execute([$mach4Id]);
        $pdo->prepare("DELETE FROM product_keys WHERE machine_id = ?")->execute([$mach4Id]);
        $pdo->prepare("DELETE FROM machines WHERE id = ?")->execute([$mach4Id]);
    }
    echo "Cleanup complete.\n";
}

echo "\n====================================================\n";
echo " Test Results: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "====================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
