<?php
/**
 * ============================================================
 * VendoLink — Product Key Refresh & Persistence Verification Test
 * ============================================================
 *
 * Acceptance Criteria Verified:
 * 1. Product Key generated ONLY when explicit action is triggered.
 * 2. GET requests, page load, constructor, and refresh events do NOT generate keys.
 * 3. 100 consecutive page refreshes cause 0 new keys to be created.
 * 4. Hardware serial deduplication: Re-requesting key for existing hardware returns existing key without duplicates.
 * 5. Owner registration permanently binds the owner to the hardware using that exact key.
 *
 * Run with: C:\xampp\php\php.exe tests/product_key_refresh_persistence_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/AdminDashboard.php';
require_once __DIR__ . '/../models/ProductKey.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../controllers/AuthController.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

echo "====================================================\n";
echo " Product Key Refresh & Persistence Test Suite\n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assert_test(bool $condition, string $testName, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$testName}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$testName}" . ($details ? " -- {$details}" : '') . "\n";
    }
}

// Clean up old test data
$testSerial = 'HW-UNIT-PERSIST-999';
$pdo->exec("DELETE FROM users WHERE username = 'persist_owner_test'");
$pdo->exec("DELETE FROM product_keys WHERE hardware_serial = '{$testSerial}'");
$pdo->exec("DELETE FROM machines WHERE machine_code = '{$testSerial}'");

$adminDashboard = new AdminDashboard();
$productKeyModel = new ProductKey();

// -------------------------------------------------------------
// STEP 1: Verify No Key Generated on Initial Load / Constructor
// -------------------------------------------------------------
echo "[1/5] Testing GET requests & Constructor safety...\n";

$initialCount = (int)$db->query("SELECT COUNT(*) FROM product_keys WHERE hardware_serial = :s", [':s' => $testSerial])->fetchColumn();
assert_test($initialCount === 0, "No key exists initially for {$testSerial}");

// Simulate 10 instantiations and read queries (GET requests)
for ($i = 0; $i < 10; $i++) {
    $tempDash = new AdminDashboard();
    $tempPK = new ProductKey();
    $tempDash->getLicenseKeys();
    $tempPK->getAllKeys();
    $tempDash->getSummaryMetrics();
}

$afterReadsCount = (int)$db->query("SELECT COUNT(*) FROM product_keys WHERE hardware_serial = :s", [':s' => $testSerial])->fetchColumn();
assert_test($afterReadsCount === 0, "10 constructor calls & GET read queries generated 0 new keys");

// -------------------------------------------------------------
// STEP 2: Click Generate -> Product Key Created ONCE
// -------------------------------------------------------------
echo "\n[2/5] Testing Explicit Key Generation...\n";

$generatedKey = $adminDashboard->generateLicenseKey('Test Unit Owner', $testSerial);
assert_test(!empty($generatedKey) && str_starts_with($generatedKey, 'VL-2026-'), "Key generated successfully: {$generatedKey}");

$dbRow = $db->query("SELECT * FROM product_keys WHERE product_key = :k", [':k' => $generatedKey])->fetch();
assert_test(!empty($dbRow) && $dbRow['hardware_serial'] === $testSerial && $dbRow['status'] === 'UNUSED', "Key saved permanently in MySQL product_keys table with status UNUSED");

$countAfterGen = (int)$db->query("SELECT COUNT(*) FROM product_keys WHERE hardware_serial = :s", [':s' => $testSerial])->fetchColumn();
assert_test($countAfterGen === 1, "Exactly 1 product key in database for {$testSerial}");

// -------------------------------------------------------------
// STEP 3: Refresh 100 Times -> Same Product Key Remains (0 New Keys)
// -------------------------------------------------------------
echo "\n[3/5] Testing 100 consecutive page refreshes...\n";

$allSameKey = true;
for ($refresh = 1; $refresh <= 100; $refresh++) {
    // Simulate GET page render of admin/licenses/index.php
    $keysList = $adminDashboard->getLicenseKeys();
    $found = null;
    foreach ($keysList as $k) {
        if (($k['hardware_serial'] ?? '') === $testSerial) {
            $found = $k['product_key'] ?? $k['license_key'];
            break;
        }
    }
    if ($found !== $generatedKey) {
        $allSameKey = false;
        break;
    }
}

$countAfter100Refreshes = (int)$db->query("SELECT COUNT(*) FROM product_keys WHERE hardware_serial = :s", [':s' => $testSerial])->fetchColumn();
assert_test($allSameKey, "Every refresh (100x) reads the exact same product key from MySQL");
assert_test($countAfter100Refreshes === 1, "Database still contains exactly 1 product key after 100 refreshes (0 new keys generated)");

// -------------------------------------------------------------
// STEP 4: Deduplication when Admin Clicks Generate Again for Same Hardware
// -------------------------------------------------------------
echo "\n[4/5] Testing Hardware Serial Deduplication on duplicate Generate action...\n";

// Attempt to generate again for the same hardware serial
$secondKeyResult = $adminDashboard->generateLicenseKey('Another Owner', $testSerial);
$thirdKeyResult = $productKeyModel->generateKey($testSerial);

assert_test($secondKeyResult === $generatedKey, "Second generation for {$testSerial} returns original key ({$generatedKey})");
assert_test($thirdKeyResult === $generatedKey, "ProductKey::generateKey for {$testSerial} returns original key ({$generatedKey})");

$finalCount = (int)$db->query("SELECT COUNT(*) FROM product_keys WHERE hardware_serial = :s", [':s' => $testSerial])->fetchColumn();
assert_test($finalCount === 1, "Total product keys in MySQL for {$testSerial} remains exactly 1 (no duplicates created)");

// -------------------------------------------------------------
// STEP 5: Register Owner with Product Key & Verify Permanent Binding
// -------------------------------------------------------------
echo "\n[5/5] Testing Owner Registration and Permanent Binding...\n";

// Validate key for registration
$validation = $productKeyModel->validateKey($generatedKey);
assert_test($validation['valid'] === true, "Generated key is valid for registration");

// Create user account
$hash = password_hash('SecurePassword123!', PASSWORD_DEFAULT);
$db->query("INSERT INTO users (username, email, full_name, password, role, status) VALUES ('persist_owner_test', 'persist@test.com', 'Persist Owner', :p, 'owner', 'active')", [':p' => $hash]);
$newUserId = (int)$pdo->lastInsertId();

// Activate key
$activated = $productKeyModel->activateKey((int)$validation['key']['id'], $newUserId);
assert_test($activated === true, "Product key activated for new owner ID #{$newUserId}");

// Check key state in MySQL
$updatedKeyRow = $db->query("SELECT * FROM product_keys WHERE product_key = :k", [':k' => $generatedKey])->fetch();
assert_test($updatedKeyRow['status'] === 'USED' && (int)$updatedKeyRow['owner_id'] === $newUserId && !empty($updatedKeyRow['activated_at']), "MySQL product_keys status=USED, owner_id={$newUserId}, activated_at recorded");

// Check machine ownership
$machineModel = new Machine();
$ownerMachine = $machineModel->primaryWithServices($newUserId);
assert_test(!empty($ownerMachine) && $ownerMachine['machine_code'] === $testSerial && (int)$ownerMachine['owner_id'] === $newUserId, "Machine {$testSerial} bound permanently to Owner ID #{$newUserId}");

// Simulate 50 more refreshes after activation
for ($r = 0; $r < 50; $r++) {
    $keys = $adminDashboard->getLicenseKeys();
}
$postActivationCount = (int)$db->query("SELECT COUNT(*) FROM product_keys WHERE hardware_serial = :s", [':s' => $testSerial])->fetchColumn();
assert_test($postActivationCount === 1, "Product key count for {$testSerial} remains 1 after post-activation refreshes");

// Clean up test data
$pdo->exec("DELETE FROM service_transactions WHERE machine_id IN (SELECT id FROM machines WHERE machine_code = '{$testSerial}')");
$pdo->exec("DELETE FROM services WHERE machine_id IN (SELECT id FROM machines WHERE machine_code = '{$testSerial}')");
$pdo->exec("DELETE FROM machines WHERE machine_code = '{$testSerial}'");
$pdo->exec("DELETE FROM product_keys WHERE hardware_serial = '{$testSerial}'");
$pdo->exec("DELETE FROM users WHERE id = {$newUserId}");

echo "\n====================================================\n";
echo " Test Results: {$passCount} Passed, {$failCount} Failed\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
}
