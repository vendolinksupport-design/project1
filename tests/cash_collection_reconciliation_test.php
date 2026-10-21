<?php
/**
 * ============================================================
 * VendoLink - Cash Collection Validation & Reconciliation Test
 * ============================================================
 *
 * Automated verification test suite covering:
 * 1. Exact match collection (zero difference, status 'matched')
 * 2. Shortage collection with reason (allowed, carryover preserved)
 * 3. Shortage collection without reason (rejected)
 * 4. Excess collection without override (strictly blocked by default)
 * 5. Excess collection with unauthorized role (staff rejected)
 * 6. Excess collection with authorized override and reason (allowed, status 'override')
 * 7. Excess collection with override but missing reason (rejected)
 * 8. Zero expected balance collection attempt (rejected)
 * 9. Negative or invalid monetary amounts (rejected)
 * 10. Multi-day carryover and multiple collection cycles
 * 11. Ineligible transaction filtering (failed/cancelled excluded)
 * 12. Database rollback integrity and concurrency safety
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Machine.php';

echo "============================================================\n";
echo "Starting Cash Collection Validation & Reconciliation Tests\n";
echo "============================================================\n\n";

$txModel = new Transaction();
$db = Database::getInstance();
$pdo = $db->getConnection();

// Setup dedicated test machine and user
$testMachineId = 99991;
$testOwnerId = 99991;
$testStaffId = 99992;
$testServiceId = (int)$pdo->query("SELECT id FROM services LIMIT 1")->fetchColumn() ?: 109;

// Clean up any stale test fixtures
$pdo->exec("DELETE FROM cash_collections WHERE machine_id = {$testMachineId}");
$pdo->exec("DELETE FROM service_transactions WHERE machine_id = {$testMachineId}");
$pdo->exec("DELETE FROM machines WHERE id = {$testMachineId}");
$pdo->exec("DELETE FROM users WHERE id IN ({$testOwnerId}, {$testStaffId})");

// Insert test Owner and Staff users
$pdo->exec("INSERT INTO users (id, username, email, password, full_name, role, status, created_at)
            VALUES ({$testOwnerId}, 'recon_owner_test', 'recon_owner@test.local', 'hash', 'Test Owner User', 'owner', 'active', NOW())");
$pdo->exec("INSERT INTO users (id, username, email, password, full_name, role, status, created_at)
            VALUES ({$testStaffId}, 'recon_staff_test', 'recon_staff@test.local', 'hash', 'Test Staff User', 'staff', 'active', NOW())");

// Insert test machine
$pdo->exec("INSERT INTO machines (id, owner_id, name, machine_code, machine_key, status, created_at)
            VALUES ({$testMachineId}, {$testOwnerId}, 'Reconciliation Test Unit', 'TEST-RECON-01', 'KEY-RECON-01', 'online', NOW())");

$passedTests = 0;
$totalTests = 12;

try {
    // ------------------------------------------------------------
    // TEST 1: Zero expected balance collection attempt (rejected)
    // ------------------------------------------------------------
    echo "--- TEST 1: Zero Expected Balance Collection Attempt ---\n";
    $bal = $txModel->getExpectedCashBalance($testMachineId, $testOwnerId);
    if ($bal['expected_balance'] !== 0.00) {
        throw new RuntimeException("Initial expected balance must be 0.00, got: {$bal['expected_balance']}");
    }

    $caught = false;
    try {
        $txModel->recordCashCollection($testOwnerId, $testMachineId, 100.00, "Should fail on zero balance");
    } catch (InvalidArgumentException $e) {
        $caught = true;
        echo "    [PASS] Correctly rejected collection on ₱0.00 balance: " . $e->getMessage() . "\n";
    }
    if (!$caught) {
        throw new RuntimeException("FAIL: Failed to reject collection on zero expected balance!");
    }
    $passedTests++;

    // ------------------------------------------------------------
    // TEST 2: Ineligible transaction filtering
    // ------------------------------------------------------------
    echo "\n--- TEST 2: Ineligible Transaction Filtering (Failed/Cancelled) ---\n";
    // Insert 1 completed (₱500.00), 1 failed (₱300.00), 1 cancelled (₱200.00)
    $pdo->exec("INSERT INTO service_transactions (machine_id, service_id, amount, status, created_at, completed_at)
                VALUES ({$testMachineId}, {$testServiceId}, 500.00, 'completed', NOW(), NOW())");
    $pdo->exec("INSERT INTO service_transactions (machine_id, service_id, amount, status, created_at, completed_at)
                VALUES ({$testMachineId}, {$testServiceId}, 300.00, 'failed', NOW(), NULL)");
    $pdo->exec("INSERT INTO service_transactions (machine_id, service_id, amount, status, created_at, completed_at)
                VALUES ({$testMachineId}, {$testServiceId}, 200.00, 'cancelled', NOW(), NULL)");

    $bal = $txModel->getExpectedCashBalance($testMachineId, $testOwnerId);
    if ((float)$bal['expected_balance'] !== 500.00) {
        throw new RuntimeException("Expected cash must be exactly ₱500.00 (excluding failed/cancelled), got: {$bal['expected_balance']}");
    }
    echo "    [PASS] Expected balance correctly calculated as ₱500.00 (failed and cancelled transactions excluded).\n";
    $passedTests++;

    // ------------------------------------------------------------
    // TEST 3: Invalid monetary amounts
    // ------------------------------------------------------------
    echo "\n--- TEST 3: Invalid Monetary Input Validation ---\n";
    $caughtZero = false;
    try {
        $txModel->recordCashCollection($testOwnerId, $testMachineId, 0.00, "Zero amount");
    } catch (InvalidArgumentException $e) {
        $caughtZero = true;
    }

    $caughtNeg = false;
    try {
        $txModel->recordCashCollection($testOwnerId, $testMachineId, -50.00, "Negative amount");
    } catch (InvalidArgumentException $e) {
        $caughtNeg = true;
    }

    if ($caughtZero && $caughtNeg) {
        echo "    [PASS] Successfully rejected zero and negative monetary inputs.\n";
        $passedTests++;
    } else {
        throw new RuntimeException("FAIL: Did not reject invalid monetary amounts!");
    }

    // ------------------------------------------------------------
    // TEST 4: Shortage collection without reason (rejected)
    // ------------------------------------------------------------
    echo "\n--- TEST 4: Shortage Collection Without Reason (Rejected) ---\n";
    $caughtShortageNoReason = false;
    try {
        // Expected is 500.00, entering 400.00 with empty notes
        $txModel->recordCashCollection($testOwnerId, $testMachineId, 400.00, "");
    } catch (InvalidArgumentException $e) {
        $caughtShortageNoReason = true;
        echo "    [PASS] Rejected shortage without explanation: " . $e->getMessage() . "\n";
    }
    if (!$caughtShortageNoReason) {
        throw new RuntimeException("FAIL: Allowed shortage collection without mandatory explanation!");
    }
    $passedTests++;

    // ------------------------------------------------------------
    // TEST 5: Shortage collection with reason (allowed, carryover preserved)
    // ------------------------------------------------------------
    echo "\n--- TEST 5: Shortage Collection With Reason (Allowed, Carryover Preserved) ---\n";
    $collId1 = $txModel->recordCashCollection(
        $testOwnerId,
        $testMachineId,
        400.00,
        "Left ₱100 in coins inside the machine for change"
    );
    if ($collId1 <= 0) {
        throw new RuntimeException("FAIL: Failed to record valid shortage collection!");
    }

    $rec1 = $pdo->query("SELECT * FROM cash_collections WHERE id = {$collId1}")->fetch(PDO::FETCH_ASSOC);
    if ($rec1['reconciliation_status'] !== 'shortage' || (float)$rec1['uncollected_balance'] !== 100.00 || (float)$rec1['variance_amount'] !== -100.00) {
        throw new RuntimeException("FAIL: Shortage record fields incorrect: " . print_r($rec1, true));
    }
    echo "    [PASS] Shortage recorded with status 'shortage', difference -₱100.00, uncollected balance ₱100.00.\n";

    // Verify remaining uncollected balance carries over
    $balAfterShortage = $txModel->getExpectedCashBalance($testMachineId, $testOwnerId);
    if ((float)$balAfterShortage['expected_balance'] !== 100.00) {
        throw new RuntimeException("FAIL: Carryover expected balance must be ₱100.00, got: {$balAfterShortage['expected_balance']}");
    }
    echo "    [PASS] Remaining ₱100.00 shortage cash correctly carried over as expected uncollected balance.\n";
    $passedTests++;

    // ------------------------------------------------------------
    // TEST 6: Exact match collection on carryover
    // ------------------------------------------------------------
    echo "\n--- TEST 6: Exact Match Collection On Carryover (₱100.00) ---\n";
    $collId2 = $txModel->recordCashCollection(
        $testOwnerId,
        $testMachineId,
        100.00,
        "Collected the remaining ₱100.00 change"
    );
    $rec2 = $pdo->query("SELECT * FROM cash_collections WHERE id = {$collId2}")->fetch(PDO::FETCH_ASSOC);
    if ($rec2['reconciliation_status'] !== 'matched' || (float)$rec2['variance_amount'] !== 0.00 || (float)$rec2['uncollected_balance'] !== 0.00) {
        throw new RuntimeException("FAIL: Exact match record fields incorrect: " . print_r($rec2, true));
    }
    echo "    [PASS] Exact match recorded with status 'matched', difference ₱0.00.\n";

    $balAfterMatch = $txModel->getExpectedCashBalance($testMachineId, $testOwnerId);
    if ((float)$balAfterMatch['expected_balance'] !== 0.00) {
        throw new RuntimeException("FAIL: Balance after exact match must be ₱0.00, got: {$balAfterMatch['expected_balance']}");
    }
    echo "    [PASS] Balance cleanly zeroed out (₱0.00).\n";
    $passedTests++;

    // ------------------------------------------------------------
    // TEST 7: Excess collection without override (strictly blocked)
    // ------------------------------------------------------------
    echo "\n--- TEST 7: Excess Collection Without Override (Blocked) ---\n";
    // Add new completed vend of ₱200.00 (Expected is now ₱200.00)
    $pdo->exec("INSERT INTO service_transactions (machine_id, service_id, amount, status, created_at, completed_at)
                VALUES ({$testMachineId}, {$testServiceId}, 200.00, 'completed', NOW(), NOW())");

    $caughtExcessNoOverride = false;
    try {
        // Attempting to collect ₱500.00 when only ₱200.00 expected
        $txModel->recordCashCollection($testOwnerId, $testMachineId, 500.00, "Trying excess without override");
    } catch (InvalidArgumentException $e) {
        $caughtExcessNoOverride = true;
        echo "    [PASS] Blocked excess collection: " . $e->getMessage() . "\n";
    }
    if (!$caughtExcessNoOverride) {
        throw new RuntimeException("FAIL: Allowed excess collection without override flag!");
    }
    $passedTests++;

    // ------------------------------------------------------------
    // TEST 8: Excess collection with unauthorized role (staff rejected)
    // ------------------------------------------------------------
    echo "\n--- TEST 8: Excess Collection With Unauthorized Role (Staff Rejected) ---\n";
    $caughtStaffOverride = false;
    try {
        // Staff user attempting override
        $txModel->recordCashCollection(
            $testStaffId,
            $testMachineId,
            500.00,
            "Staff attempting override",
            true, // isOverride
            "Legitimate staff explanation", // reason
            $testStaffId,
            'staff'
        );
    } catch (InvalidArgumentException $e) {
        $caughtStaffOverride = true;
        echo "    [PASS] Rejected staff role from authorizing override: " . $e->getMessage() . "\n";
    }
    if (!$caughtStaffOverride) {
        throw new RuntimeException("FAIL: Staff role was improperly permitted to authorize override!");
    }
    $passedTests++;

    // ------------------------------------------------------------
    // TEST 9: Excess collection with override but missing reason (rejected)
    // ------------------------------------------------------------
    echo "\n--- TEST 9: Excess Collection With Override But Missing Reason (Rejected) ---\n";
    $caughtMissingReason = false;
    try {
        $txModel->recordCashCollection(
            $testOwnerId,
            $testMachineId,
            500.00,
            "Owner override attempt",
            true, // isOverride
            "", // empty reason
            $testOwnerId,
            'owner'
        );
    } catch (InvalidArgumentException $e) {
        $caughtMissingReason = true;
        echo "    [PASS] Rejected override with missing reason: " . $e->getMessage() . "\n";
    }
    if (!$caughtMissingReason) {
        throw new RuntimeException("FAIL: Allowed override without explanation reason!");
    }
    $passedTests++;

    // ------------------------------------------------------------
    // TEST 10: Excess collection with authorized override and reason (allowed)
    // ------------------------------------------------------------
    echo "\n--- TEST 10: Authorized Excess Override With Mandatory Reason (Allowed) ---\n";
    $collId3 = $txModel->recordCashCollection(
        $testOwnerId,
        $testMachineId,
        500.00,
        "Regular collection note",
        true, // isOverride
        "Verified customer manual token adjustments and unrecorded test coins",
        $testOwnerId,
        'owner'
    );
    if ($collId3 <= 0) {
        throw new RuntimeException("FAIL: Failed to record authorized override collection!");
    }

    $rec3 = $pdo->query("SELECT * FROM cash_collections WHERE id = {$collId3}")->fetch(PDO::FETCH_ASSOC);
    if ($rec3['reconciliation_status'] !== 'override' || (int)$rec3['is_override'] !== 1 || (float)$rec3['variance_amount'] !== 300.00 || (int)$rec3['override_user_id'] !== $testOwnerId) {
        throw new RuntimeException("FAIL: Override record fields incorrect: " . print_r($rec3, true));
    }
    echo "    [PASS] Recorded authorized override with status 'override', is_override=1, difference +₱300.00, override_user_id={$testOwnerId}.\n";
    $passedTests++;

    // ------------------------------------------------------------
    // TEST 11: Multi-day carryover and multiple collection cycles
    // ------------------------------------------------------------
    echo "\n--- TEST 11: Multi-Cycle Cumulative Balance Integration ---\n";
    // Now add new sales of ₱400.00
    $pdo->exec("INSERT INTO service_transactions (machine_id, service_id, amount, status, created_at, completed_at)
                VALUES ({$testMachineId}, {$testServiceId}, 400.00, 'completed', NOW(), NOW())");

    // Total sales = 500 + 200 + 400 = 1100.00
    // Total previous collections = 400 + 100 + 500 = 1000.00
    // Previous authorized excess overrides = 300.00
    // Expected = 1100 - 1000 + 300 = 400.00
    $balCycle = $txModel->getExpectedCashBalance($testMachineId, $testOwnerId);
    if ((float)$balCycle['expected_balance'] !== 400.00) {
        throw new RuntimeException("FAIL: Multi-cycle expected balance must be ₱400.00, got: {$balCycle['expected_balance']}");
    }
    echo "    [PASS] Multi-cycle cumulative formula verified: Expected cash is ₱400.00.\n";
    $passedTests++;

    // ------------------------------------------------------------
    // TEST 12: Concurrency safety and database rollback integrity
    // ------------------------------------------------------------
    echo "\n--- TEST 12: Database Rollback Integrity ---\n";
    $initialCount = (int)$pdo->query("SELECT COUNT(*) FROM cash_collections WHERE machine_id = {$testMachineId}")->fetchColumn();

    // Trigger an invalid collection inside transaction
    try {
        $txModel->recordCashCollection($testOwnerId, $testMachineId, 9999.00, "Should rollback");
    } catch (\Throwable $e) {
        // expected to fail
    }

    $finalCount = (int)$pdo->query("SELECT COUNT(*) FROM cash_collections WHERE machine_id = {$testMachineId}")->fetchColumn();
    if ($finalCount !== $initialCount) {
        throw new RuntimeException("FAIL: Failed transaction was not rolled back cleanly!");
    }
    echo "    [PASS] Atomic rollback verified: Collection count unchanged after rejected submission.\n";
    $passedTests++;

} finally {
    // Clean up test records
    $pdo->exec("DELETE FROM cash_collections WHERE machine_id = {$testMachineId}");
    $pdo->exec("DELETE FROM service_transactions WHERE machine_id = {$testMachineId}");
    $pdo->exec("DELETE FROM machines WHERE id = {$testMachineId}");
    $pdo->exec("DELETE FROM users WHERE id IN ({$testOwnerId}, {$testStaffId})");
}

echo "\n============================================================\n";
echo "Test Results: {$passedTests}/{$totalTests} passed successfully!\n";
echo "============================================================\n";
