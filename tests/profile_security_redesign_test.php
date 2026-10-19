<?php
/**
 * ============================================================
 * VendoLink — Profile Account Security UI & Backend Test Suite
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../controllers/AuthController.php';

echo "=== STARTING PROFILE ACCOUNT SECURITY TEST SUITE ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $description) {
    global $passCount, $failCount;
    if ($condition) {
        echo "   [PASS] {$description}\n";
        $passCount++;
    } else {
        echo "   [FAIL] {$description}\n";
        $failCount++;
    }
}

// ------------------------------------------------------------
// 1. CSS Rules Verification
// ------------------------------------------------------------
echo "1. Verifying CSS rules in assets/css/app.css...\n";
$css = file_get_contents(__DIR__ . '/../assets/css/app.css');

$expectedSelectors = [
    '.security-cards-stack',
    '.security-card',
    '.security-card-main',
    '.security-card-icon',
    '.security-card-icon--key',
    '.security-card-icon--mail',
    '.security-card-title',
    '.security-card-meta',
    '.security-card-action',
    '.security-badge--ok',
    '.security-badge--warn',
    '.security-overview-box',
    '.modal-security-dialog',
    '.modal-security-header',
    '.modal-security-footer',
    '.current-email-badge',
    '.otp-target-email',
    '.otp-code-input'
];

foreach ($expectedSelectors as $selector) {
    assertTest(strpos($css, $selector) !== false, "Selector '{$selector}' is present in app.css");
}

// Check responsive media query
assertTest(
    strpos($css, '@media (max-width: 575.98px)') !== false &&
    strpos($css, '.security-card         { flex-direction: column;') !== false,
    "Mobile responsive media query for .security-card is present"
);

// ------------------------------------------------------------
// 2. Profile Page Markup & Modal Verification
// ------------------------------------------------------------
echo "\n2. Verifying Profile page markup in users/profile.php...\n";
$profilePhp = file_get_contents(__DIR__ . '/../users/profile.php');

// Permanent inline form should NOT exist in the page body
assertTest(strpos($profilePhp, '<form method="POST" action="profile.php#security" novalidate id="profilePasswordForm"') === false, "Old inline permanent password form has been removed");

// Security status & action cards
assertTest(strpos($profilePhp, 'class="security-cards-stack"') !== false, "Security cards container .security-cards-stack exists");
assertTest(strpos($profilePhp, 'class="security-card-icon security-card-icon--key"') !== false, "Password card with key icon exists");
assertTest(strpos($profilePhp, 'data-bs-target="#changePasswordModal"') !== false, "Change Password button triggers #changePasswordModal");
assertTest(strpos($profilePhp, 'class="security-card-icon security-card-icon--mail"') !== false, "Email card with mail icon exists");
assertTest(strpos($profilePhp, 'data-bs-target="#changeEmailModal"') !== false, "Change Email button triggers #changeEmailModal");
assertTest(strpos($profilePhp, 'class="security-overview-box"') !== false, "Security Overview / recommendations box exists");

// Change Password Modal checks
assertTest(strpos($profilePhp, 'id="changePasswordModal"') !== false, "Modal #changePasswordModal exists");
assertTest(strpos($profilePhp, 'id="modal_current_password"') !== false, "Current password input in modal exists");
assertTest(strpos($profilePhp, 'id="modal_new_password"') !== false, "New password input in modal exists");
assertTest(strpos($profilePhp, 'id="modal_confirm_password"') !== false, "Confirm password input in modal exists");
assertTest(strpos($profilePhp, 'id="modalPwStrengthBar"') !== false, "Password strength bar in modal exists");
assertTest(strpos($profilePhp, 'id="modal-req-length"') !== false, "Validation indicator: at least 8 characters exists");
assertTest(strpos($profilePhp, 'id="modal-req-different"') !== false, "Validation indicator: different from current exists");
assertTest(strpos($profilePhp, 'id="modal-req-match"') !== false, "Validation indicator: passwords match exists");

// Change Email Modal checks (Step 1 & Step 2)
assertTest(strpos($profilePhp, 'id="changeEmailModal"') !== false, "Modal #changeEmailModal exists");
assertTest(strpos($profilePhp, 'name="action" value="request_email_change"') !== false, "Step 1 action request_email_change exists");
assertTest(strpos($profilePhp, 'name="new_email"') !== false, "New email input exists");
assertTest(strpos($profilePhp, 'name="action" value="verify_email_change"') !== false, "Step 2 action verify_email_change exists");
assertTest(strpos($profilePhp, 'class="form-control dark-input otp-code-input"') !== false, "6-digit OTP code input exists");
assertTest(strpos($profilePhp, 'name="action" value="resend_email_change_otp"') !== false, "Resend OTP action exists");
assertTest(strpos($profilePhp, 'name="action" value="cancel_email_change"') !== false, "Cancel email change action exists");

// ------------------------------------------------------------
// 3. AuthController & User Model Methods Verification
// ------------------------------------------------------------
echo "\n3. Verifying Backend Methods in AuthController & User model...\n";
$authCtrl = new AuthController();
assertTest(method_exists($authCtrl, 'sendEmailChangeOtp'), "AuthController::sendEmailChangeOtp method exists");

$userModel = new User();
assertTest(method_exists($userModel, 'updateEmail'), "User::updateEmail method exists");

// Test sendEmailChangeOtp logging
$testEmail = 'test_security_audit_' . time() . '@example.com';
$testOtp = '849201';
$sent = $authCtrl->sendEmailChangeOtp($testEmail, 'Test User', $testOtp);
assertTest($sent === true, "sendEmailChangeOtp executed successfully");

$mailLog = file_get_contents(LOGS_PATH . '/mail.log');
$maskedEmail = function_exists('mask_email') ? mask_email($testEmail) : $testEmail;
assertTest(strpos($mailLog, $maskedEmail) !== false || strpos($mailLog, $testEmail) !== false, "Email change dispatch logged to logs/mail.log");

// ------------------------------------------------------------
// 4. Functional Testing of Email Update & Database Safety
// ------------------------------------------------------------
echo "\n4. Functional verification of email update in database...\n";
$db = Database::getInstance();
// Find an existing test user or create a temporary dummy record for verification
$testUsername = 'sec_test_' . substr(md5((string)time()), 0, 8);
$tempEmail1 = $testUsername . '_1@example.com';
$tempEmail2 = $testUsername . '_2@example.com';
$tempPass = 'OriginalSecurePass123!';

$userId = $userModel->create([
    'username'  => $testUsername,
    'email'     => $tempEmail1,
    'password'  => $tempPass,
    'full_name' => 'Security Test Dummy',
    'role'      => ROLE_STAFF,
    'status'    => 'active',
]);

assertTest($userId > 0, "Created temporary user with ID {$userId}");

$createdUser = $userModel->findById($userId);
assertTest($createdUser['email'] === $tempEmail1, "User email initialized to {$tempEmail1}");

// Test password verification
assertTest($userModel->verifyPassword($tempPass, $createdUser['password']), "verifyPassword verifies correct password");
assertTest(!$userModel->verifyPassword('WrongPassword!', $createdUser['password']), "verifyPassword rejects wrong password");

// Test User::updateEmail
$userModel->updateEmail($userId, $tempEmail2, true);
$updatedUser = $userModel->findById($userId);
assertTest($updatedUser['email'] === $tempEmail2, "User email updated to {$tempEmail2}");
assertTest((int)$updatedUser['email_verified'] === 1, "User email_verified set to 1");

// Clean up temporary user
$userModel->delete($userId);
assertTest($userModel->findById($userId) === null, "Cleaned up temporary user record safely");

// ------------------------------------------------------------
// 5. Final Report
// ------------------------------------------------------------
echo "\n=== TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED ===\n";
if ($failCount === 0) {
    echo "🎉 ALL TESTS PASSED SUCCESSFULLY 100%!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED.\n";
    exit(1);
}
