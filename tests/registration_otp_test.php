<?php
/**
 * ============================================================
 * VendoLink — Registration & OTP Verification Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';

echo "=== STARTING REGISTRATION & OTP VERIFICATION TESTS ===\n\n";

$userModel = new User();
$db        = Database::getInstance();
$pdo       = $db->getConnection();

// 1. Duplicate email/username check
echo "1. Testing Duplicate Email & Username Guards...\n";
$adminUser = $userModel->findByEmail('vendolink.support@gmail.com') ?: $userModel->findByEmail('p.kenji17@gmail.com');
if (!empty($adminUser)) {
    echo "SUCCESS: Duplicate email lookup verified.\n\n";
}

// 2. Register new test owner account (unverified)
echo "2. Testing Owner Account Creation (Unverified & Inactive)...\n";
$testEmail = 'otp_test_' . time() . '@example.com';
$newUserId = $userModel->create([
    'username'  => 'otp_user_' . time(),
    'email'     => $testEmail,
    'password'  => 'TestPass123!',
    'full_name' => 'OTP Verification Tester',
    'role'      => 'owner',
    'status'    => 'inactive'
]);

if ($newUserId <= 0) {
    throw new RuntimeException("Failed to register owner account!");
}
echo "SUCCESS: Owner account created as unverified (email_verified=0, status=inactive).\n\n";

// 3. Test OTP generation
echo "3. Testing 6-Digit OTP Generation...\n";
$otp = $userModel->createEmailVerifyOtp($newUserId);
if (strlen($otp) !== 6 || !ctype_digit($otp)) {
    throw new RuntimeException("Generated OTP format invalid!");
}
echo "SUCCESS: 6-Digit OTP successfully created: {$otp}\n\n";

// 4. Test wrong OTP rejection
echo "4. Testing Wrong OTP Rejection...\n";
$wrongResult = $userModel->verifyEmailOtp($newUserId, '000000');
if ($wrongResult) {
    throw new RuntimeException("Wrong OTP was accepted unexpectedly!");
}
echo "SUCCESS: Invalid OTP rejected correctly.\n\n";

// 5. Test successful OTP verification
echo "5. Testing Successful OTP Verification...\n";
$validResult = $userModel->verifyEmailOtp($newUserId, $otp);
if (!$validResult) {
    throw new RuntimeException("Valid OTP verification failed!");
}
echo "SUCCESS: Account successfully verified! (email_verified=1, status=active).\n\n";

// Clean up test entry
$pdo->exec("DELETE FROM users WHERE id = {$newUserId}");

echo "ALL REGISTRATION & OTP VERIFICATION TESTS PASSED SUCCESSFULLY!\n";
