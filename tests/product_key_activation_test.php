<?php
/**
 * ============================================================
 * VendoLink — Product Key Hardware Activation Test Suite
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/ProductKey.php';
require_once __DIR__ . '/../controllers/AuthController.php';

echo "=== STARTING PRODUCT KEY HARDWARE ACTIVATION TESTS ===\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();
$productKeyModel = new ProductKey();
$userModel = new User();

// Setup fresh test keys in product_keys table
$pdo->exec("DELETE FROM product_keys WHERE product_key LIKE 'TEST-KEY-%'");
$pdo->exec("DELETE FROM machines WHERE machine_code = 'TEST-UNIT-MCH-01'");
$pdo->exec("INSERT INTO machines (machine_code, machine_key, name, status) VALUES ('TEST-UNIT-MCH-01', 'test_unit_mch_01', 'Test Unit Machine', 'pending')");
$pdo->exec("INSERT INTO product_keys (product_key, hardware_serial, status, owner_id, activated_at) VALUES
    ('TEST-KEY-UNUSED-1', 'TEST-UNIT-MCH-01', 'UNUSED', NULL, NULL),
    ('TEST-KEY-USED-2',   'TEST-UNIT-MCH-01', 'USED', 1, NOW()),
    ('TEST-KEY-DISA-3',   'TEST-UNIT-MCH-01', 'DISABLED', NULL, NULL)
");

// 1. Test: Non-existent product key
echo "1. Testing Non-Existent Product Key Validation...\n";
$res1 = $productKeyModel->validateKey('TEST-KEY-NONEXISTENT');
assert($res1['valid'] === false, "Non-existent key must be invalid");
assert($res1['error'] === 'Invalid Product Key', "Error message must be 'Invalid Product Key'");
echo "✅ Non-existent key rejected with '{$res1['error']}'\n\n";

// 2. Test: Used product key
echo "2. Testing Already Activated Product Key Validation...\n";
$res2 = $productKeyModel->validateKey('TEST-KEY-USED-2');
assert($res2['valid'] === false, "Used key must be invalid");
assert($res2['error'] === 'Product Key Already Activated', "Error message must be 'Product Key Already Activated'");
echo "✅ Used key rejected with '{$res2['error']}'\n\n";

// 3. Test: Disabled product key
echo "3. Testing Disabled Product Key Validation...\n";
$res3 = $productKeyModel->validateKey('TEST-KEY-DISA-3');
assert($res3['valid'] === false, "Disabled key must be invalid");
assert($res3['error'] === 'Product Disabled', "Error message must be 'Product Disabled'");
echo "✅ Disabled key rejected with '{$res3['error']}'\n\n";

// 4. Test: Valid UNUSED product key
echo "4. Testing Valid UNUSED Product Key Validation...\n";
$res4 = $productKeyModel->validateKey('test-key-unused-1'); // testing case insensitivity
assert($res4['valid'] === true, "Unused key must be valid");
assert($res4['error'] === null, "Error must be null for unused key");
assert($res4['key']['product_key'] === 'TEST-KEY-UNUSED-1', "Must return matching key record");
echo "✅ Unused key validated successfully.\n\n";

// 5. Test: Key Activation & Hardware Binding
echo "5. Testing Key Activation & Hardware Binding...\n";
$testOwnerEmail = 'owner_act_' . time() . '@example.com';
$newOwnerId = $userModel->createOwnerAccount([
    'full_name' => 'Hardware Owner Tester',
    'username'  => 'owner_act_' . time(),
    'email'     => $testOwnerEmail,
    'password'  => 'Password123!',
]);
assert($newOwnerId > 0, "Owner user account must be created");

$activated = $productKeyModel->activateKey((int)$res4['key']['id'], $newOwnerId, $res4['key']['hardware_serial']);
assert($activated === true, "Key activation must succeed");

// Verify product_keys record state
$updatedKey = $productKeyModel->findByKey('TEST-KEY-UNUSED-1');
assert($updatedKey['status'] === 'USED', "Status must be updated to USED");
assert((int)$updatedKey['owner_id'] === $newOwnerId, "owner_id must match new owner");
assert(!empty($updatedKey['activated_at']), "activated_at must be populated");
echo "✅ Product Key record updated: status=USED, owner_id={$newOwnerId}, activated_at={$updatedKey['activated_at']}\n";

// Verify machines table owner binding
$linkedMachine = $db->query("SELECT * FROM machines WHERE machine_code = 'TEST-UNIT-MCH-01' LIMIT 1")->fetch();
assert((int)($linkedMachine['owner_id'] ?? 0) === $newOwnerId, "Machine owner_id must be linked to new owner");
echo "✅ Machine TEST-UNIT-MCH-01 successfully bound to owner ID #{$newOwnerId}.\n\n";

// 6. Test: Re-activating the same key must now fail
echo "6. Testing Re-Activation Rejection of the Just-Activated Key...\n";
$reValidation = $productKeyModel->validateKey('TEST-KEY-UNUSED-1');
assert($reValidation['valid'] === false, "Just-activated key must no longer be valid");
assert($reValidation['error'] === 'Product Key Already Activated', "Error must be 'Product Key Already Activated'");
echo "✅ Re-activation blocked with '{$reValidation['error']}'.\n\n";

// 7. Test: Owner Registration Page UI contains Product Key field between Email and Password
echo "7. Verifying auth/register.php HTML Form Structure...\n";
$regHtml = file_get_contents(__DIR__ . '/../auth/register.php');
assert(strpos($regHtml, 'name="product_key"') !== false, "register.php must contain product_key input");
assert(strpos($regHtml, 'Product Key') !== false, "register.php must contain 'Product Key' label");

// Verify ordering: Email is before Product Key, and Product Key is before Password
$posEmail    = strpos($regHtml, 'name="email"');
$posProdKey  = strpos($regHtml, 'name="product_key"');
$posPassword = strpos($regHtml, 'name="password"');

assert($posEmail < $posProdKey, "Email field must appear before Product Key");
assert($posProdKey < $posPassword, "Product Key field must appear before Password");
echo "✅ Form field order verified: [Email Address] -> [Product Key] -> [Password].\n\n";

// Cleanup test records
$pdo->exec("DELETE FROM product_keys WHERE product_key LIKE 'TEST-KEY-%'");
$pdo->exec("DELETE FROM machines WHERE machine_code = 'TEST-UNIT-MCH-01'");
$pdo->exec("DELETE FROM users WHERE id = {$newOwnerId}");

echo "🎉 ALL PRODUCT KEY ACTIVATION TESTS PASSED 100% SUCCESSFULLY!\n";
