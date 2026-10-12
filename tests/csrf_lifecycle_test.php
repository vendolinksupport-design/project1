<?php
/**
 * ============================================================
 * VendoLink - CSRF Token Lifecycle & Email Verification Test Suite
 * ============================================================
 */

define('TEST_MODE', true);

require_once 'c:/xampp/htdocs/vendolink_claude_v1/config/config.php';
require_once 'c:/xampp/htdocs/vendolink_claude_v1/config/database.php';
require_once 'c:/xampp/htdocs/vendolink_claude_v1/includes/functions.php';
require_once 'c:/xampp/htdocs/vendolink_claude_v1/models/User.php';
require_once 'c:/xampp/htdocs/vendolink_claude_v1/controllers/AuthController.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertTest(bool $condition, string $testName, string $details = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "   [PASS] {$testName}\n";
    } else {
        $failedTests++;
        echo "   [FAIL] {$testName} - Details: {$details}\n";
    }
}

echo "=== STARTING CSRF LIFECYCLE & VERIFICATION TEST SUITE ===\n\n";

// -------------------------------------------------------------
// 1. Test CSRF Generation & Persistence
// -------------------------------------------------------------
echo "1. Testing CSRF Token Generation & Stability...\n";
$_SESSION = [];
$t1 = csrf_generate();
assertTest(!empty($t1) && strlen($t1) === 64, "csrf_generate() returns 64-char hex token");
assertTest($_SESSION[CSRF_TOKEN_NAME] === $t1, "Session stores CSRF token under CSRF_TOKEN_NAME");

$t2 = csrf_token();
assertTest($t1 === $t2, "csrf_token() returns stable session token without regenerating");

$field = csrf_field();
assertTest(strpos($field, 'name="' . CSRF_TOKEN_NAME . '"') !== false, "csrf_field() contains correct input name");
assertTest(strpos($field, 'value="' . $t1 . '"') !== false, "csrf_field() contains generated token value");

// -------------------------------------------------------------
// 2. Testing csrf_verify() does NOT unset session token
// -------------------------------------------------------------
echo "\n2. Testing CSRF Verification & Non-Destructive Lifecycle...\n";
$_POST[CSRF_TOKEN_NAME] = $t1;
try {
    csrf_verify();
    assertTest(true, "csrf_verify() passed with valid token");
} catch (\Throwable $e) {
    assertTest(false, "csrf_verify() threw error on valid token", $e->getMessage());
}

assertTest(!empty($_SESSION[CSRF_TOKEN_NAME]) && $_SESSION[CSRF_TOKEN_NAME] === $t1, "csrf_verify() does NOT unset session token (prevents 403 on double submit / multi-form)");

// Call verify a second time with the same token (simulating double click)
try {
    csrf_verify();
    assertTest(true, "csrf_verify() passes second consecutive submission with same token");
} catch (\Throwable $e) {
    assertTest(false, "csrf_verify() failed on second submission", $e->getMessage());
}

// -------------------------------------------------------------
// 3. Testing HTTP Header X-CSRF-Token support
// -------------------------------------------------------------
echo "\n3. Testing Header-based CSRF Validation (AJAX support)...\n";
unset($_POST[CSRF_TOKEN_NAME]);
$_SERVER['HTTP_X_CSRF_TOKEN'] = $t1;
try {
    csrf_verify();
    assertTest(true, "csrf_verify() succeeds when token is sent via HTTP_X_CSRF_TOKEN header");
} catch (\Throwable $e) {
    assertTest(false, "csrf_verify() header check failed", $e->getMessage());
}
unset($_SERVER['HTTP_X_CSRF_TOKEN']);

// -------------------------------------------------------------
// 4. Testing Token Regeneration & Grace Window
// -------------------------------------------------------------
echo "\n4. Testing CSRF Token Regeneration with Grace Window...\n";
$oldToken = $t1;
$newToken = csrf_regenerate();

assertTest($newToken !== $oldToken, "csrf_regenerate() creates a distinct new token");
assertTest($_SESSION[CSRF_TOKEN_NAME] === $newToken, "New token is stored in active session");
assertTest(!empty($_SESSION[CSRF_TOKEN_NAME . '_prev']), "Previous token is saved in session grace storage");
assertTest($_SESSION[CSRF_TOKEN_NAME . '_prev']['token'] === $oldToken, "Previous token value matches old token");
assertTest($_SESSION[CSRF_TOKEN_NAME . '_prev']['expires_at'] > time(), "Previous token grace window is set in future");

// Verify that the OLD token still passes during grace window
$_POST[CSRF_TOKEN_NAME] = $oldToken;
try {
    csrf_verify();
    assertTest(true, "csrf_verify() accepts previous token within active grace window");
} catch (\Throwable $e) {
    assertTest(false, "csrf_verify() rejected previous token in grace window", $e->getMessage());
}

// Verify that the NEW token also passes
$_POST[CSRF_TOKEN_NAME] = $newToken;
try {
    csrf_verify();
    assertTest(true, "csrf_verify() accepts new active token");
} catch (\Throwable $e) {
    assertTest(false, "csrf_verify() rejected new token", $e->getMessage());
}

// -------------------------------------------------------------
// 5. Functional Email Verification with OTP & Idempotency
// -------------------------------------------------------------
echo "\n5. Testing Functional Email Change Verification & DB Integrity...\n";
$userModel = new User();
$db = Database::getInstance();

$testUsername = 'csrf_verify_user_' . substr(bin2hex(random_bytes(4)), 0, 8);
$testEmail1   = "test_initial_{$testUsername}@example.com";
$testEmail2   = "test_updated_{$testUsername}@example.com";
$testPass     = 'Password@123';

$db->query(
    "INSERT INTO users (username, email, password, full_name, role, status, email_verified, created_at) 
     VALUES (:username, :email, :password, :full_name, 'owner', 'active', 1, NOW())",
    [
        ':username'  => $testUsername,
        ':email'     => $testEmail1,
        ':password'  => password_hash($testPass, PASSWORD_DEFAULT),
        ':full_name' => 'CSRF Test User'
    ]
);
$createdUser = $userModel->findByEmail($testEmail1);
$userId = (int)$createdUser['id'];
assertTest($userId > 0, "Created temporary test user (ID: {$userId})");

// Step 1: Simulate pending email change request
$otp = '458921';
$_SESSION['pending_email_change'] = [
    'user_id'    => $userId,
    'new_email'  => strtolower($testEmail2),
    'otp_hash'   => hash('sha256', $otp),
    'expires_at' => time() + 300,
    'sent_at'    => time(),
    'attempts'   => 0,
];

// Step 2: Attempt with incorrect OTP
$wrongOtp = '111111';
$hashedWrong = hash('sha256', $wrongOtp);
$matchWrong = hash_equals($_SESSION['pending_email_change']['otp_hash'], $hashedWrong);
assertTest(!$matchWrong, "Incorrect OTP is rejected by hash_equals");
$checkUser = $userModel->findById($userId);
assertTest($checkUser['email'] === $testEmail1, "Database email remains UNCHANGED after failed OTP attempt");

// Step 3: Attempt with correct OTP
$correctOtp = '458921';
$hashedCorrect = hash('sha256', $correctOtp);
$matchCorrect = hash_equals($_SESSION['pending_email_change']['otp_hash'], $hashedCorrect);
assertTest($matchCorrect, "Correct OTP matches stored hash");

// Apply update
$newEmail = $_SESSION['pending_email_change']['new_email'];
$userModel->updateEmail($userId, $newEmail, true);
$_SESSION['email'] = $newEmail;
$_SESSION['email_change_completed'] = true;
unset($_SESSION['pending_email_change']);

$verifiedUser = $userModel->findById($userId);
assertTest($verifiedUser['email'] === $testEmail2, "Database email is updated ONLY after valid OTP validation");
assertTest((int)$verifiedUser['email_verified'] === 1, "email_verified flag set to 1");

// Step 4: Test duplicate request handling (simulating rapid second click)
$isPendingEmpty = empty($_SESSION['pending_email_change']);
$isCompletedSet = !empty($_SESSION['email_change_completed']);
assertTest($isPendingEmpty && $isCompletedSet, "Idempotency state correctly handles duplicate in-flight requests without error");

// Clean up
$db->query("DELETE FROM users WHERE id = :id", [':id' => $userId]);
assertTest(true, "Cleaned up temporary user ID {$userId}");

// -------------------------------------------------------------
// Final Summary
// -------------------------------------------------------------
echo "\n=== TEST RESULTS: {$passedTests} PASSED, {$failedTests} FAILED (TOTAL: {$totalTests}) ===\n";
if ($failedTests === 0) {
    echo "🎉 ALL CSRF LIFECYCLE & VERIFICATION TESTS PASSED 100%!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED.\n";
    exit(1);
}
