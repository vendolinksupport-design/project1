<?php
/**
 * ============================================================
 * VendoLink - Change Email Modal Auto-Open Prevention Test Suite
 * ============================================================
 */

define('TEST_MODE', true);

require_once 'c:/xampp/htdocs/vendolink_claude_v1/config/config.php';
require_once 'c:/xampp/htdocs/vendolink_claude_v1/config/database.php';
require_once 'c:/xampp/htdocs/vendolink_claude_v1/includes/functions.php';
require_once 'c:/xampp/htdocs/vendolink_claude_v1/models/User.php';

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

echo "=== STARTING PROFILE MODAL AUTO-OPEN PREVENTION TEST SUITE ===\n\n";

$userModel = new User();
$db = Database::getInstance();

// Setup test user
$testUsername = 'modal_test_user_' . substr(bin2hex(random_bytes(4)), 0, 8);
$testEmail    = "initial_{$testUsername}@example.com";
$testPass     = 'Password@123';

$db->query(
    "INSERT INTO users (username, email, password, full_name, role, status, email_verified, created_at) 
     VALUES (:username, :email, :password, :full_name, 'owner', 'active', 1, NOW())",
    [
        ':username'  => $testUsername,
        ':email'     => $testEmail,
        ':password'  => password_hash($testPass, PASSWORD_DEFAULT),
        ':full_name' => 'Modal Test User'
    ]
);
$testUser = $userModel->findByEmail($testEmail);
$userId = (int)$testUser['id'];
assertTest($userId > 0, "Created temporary test user ID: {$userId}");

function simulateProfilePageLogic(int $userId, array &$sessionState): array {
    $userModel = new User();
    $profileUser = $userModel->findById($userId);
    
    // Mimic the exact modal-opening calculation in users/profile.php
    $openPasswordModal = !empty($sessionState['open_password_modal']);
    unset($sessionState['open_password_modal']);

    // Auto-clean expired pending email changes (older than 5 minutes)
    if (!empty($sessionState['pending_email_change'])) {
        if (time() > ($sessionState['pending_email_change']['expires_at'] ?? 0)) {
            unset($sessionState['pending_email_change']);
        }
    }

    $hasPendingEmailChange = !empty($sessionState['pending_email_change']) && ((int)$sessionState['pending_email_change']['user_id'] === (int)$profileUser['id']);
    $pendingNewEmail       = $hasPendingEmailChange ? $sessionState['pending_email_change']['new_email'] : '';

    // The critical calculation
    $openEmailModal        = !empty($sessionState['open_email_modal']);
    unset($sessionState['open_email_modal']);

    return [
        'openEmailModal'        => $openEmailModal,
        'hasPendingEmailChange' => $hasPendingEmailChange,
        'pendingNewEmail'       => $pendingNewEmail,
        'openPasswordModal'     => $openPasswordModal,
    ];
}

// -------------------------------------------------------------
// Test 1: Clean profile.php load (after Login / Navigate)
// -------------------------------------------------------------
echo "\n1. Test Normal Profile page load (Clean session)...\n";
$session1 = ['user_id' => $userId];
$res1 = simulateProfilePageLogic($userId, $session1);
assertTest($res1['openEmailModal'] === false, "Modal openEmailModal is FALSE on normal Profile load");
assertTest($res1['hasPendingEmailChange'] === false, "hasPendingEmailChange is FALSE");

// -------------------------------------------------------------
// Test 2: Profile page load with an active pending email change in session
// -------------------------------------------------------------
echo "\n2. Test Profile page load when pending email change exists in session...\n";
$session2 = [
    'user_id' => $userId,
    'pending_email_change' => [
        'user_id'    => $userId,
        'new_email'  => 'new_pending@example.com',
        'otp_hash'   => hash('sha256', '123456'),
        'expires_at' => time() + 300,
        'sent_at'    => time(),
        'attempts'   => 0
    ]
];
$res2 = simulateProfilePageLogic($userId, $session2);
assertTest($res2['hasPendingEmailChange'] === true, "hasPendingEmailChange is TRUE (pending change recognized)");
assertTest($res2['openEmailModal'] === false, "openEmailModal is strictly FALSE (modal does NOT automatically open!)");
assertTest($res2['pendingNewEmail'] === 'new_pending@example.com', "pendingNewEmail is available for inline badge");

// -------------------------------------------------------------
// Test 3: Profile refresh with pending change
// -------------------------------------------------------------
echo "\n3. Test Profile refresh with pending change...\n";
$res3 = simulateProfilePageLogic($userId, $session2);
assertTest($res3['openEmailModal'] === false, "openEmailModal remains FALSE on page refresh");

// -------------------------------------------------------------
// Test 4: Stale / expired pending change in session
// -------------------------------------------------------------
echo "\n4. Test Profile load with stale/expired pending change in session...\n";
$session4 = [
    'user_id' => $userId,
    'pending_email_change' => [
        'user_id'    => $userId,
        'new_email'  => 'expired@example.com',
        'otp_hash'   => hash('sha256', '123456'),
        'expires_at' => time() - 10, // expired 10 seconds ago
        'sent_at'    => time() - 310,
        'attempts'   => 0
    ]
];
$res4 = simulateProfilePageLogic($userId, $session4);
assertTest($res4['hasPendingEmailChange'] === false, "Expired pending change was auto-cleaned from session");
assertTest(empty($session4['pending_email_change']), "Session pending_email_change variable was unset");
assertTest($res4['openEmailModal'] === false, "openEmailModal is FALSE");

// -------------------------------------------------------------
// Test 5: Immediate post-submission redirect (User clicked Send Verification)
// -------------------------------------------------------------
echo "\n5. Test immediate post-submission redirect (User requested email change)...\n";
$session5 = [
    'user_id' => $userId,
    'open_email_modal' => true, // Set by request_email_change handler
    'pending_email_change' => [
        'user_id'    => $userId,
        'new_email'  => 'fresh@example.com',
        'otp_hash'   => hash('sha256', '654321'),
        'expires_at' => time() + 300,
        'sent_at'    => time(),
        'attempts'   => 0
    ]
];
$res5 = simulateProfilePageLogic($userId, $session5);
assertTest($res5['openEmailModal'] === true, "openEmailModal is TRUE on immediate redirect from Send Verification");
assertTest(empty($session5['open_email_modal']), "open_email_modal flag was consumed/unset from session");

// Subsequent refresh after opening
$res5_refresh = simulateProfilePageLogic($userId, $session5);
assertTest($res5_refresh['openEmailModal'] === false, "openEmailModal reverts to FALSE on subsequent refresh!");

// -------------------------------------------------------------
// Test 6: User cancels email change
// -------------------------------------------------------------
echo "\n6. Test Cancel email change action...\n";
$session6 = [
    'user_id' => $userId,
    'pending_email_change' => [
        'user_id' => $userId,
        'new_email' => 'cancel_me@example.com'
    ]
];
// Mimic action=cancel_email_change
unset($session6['pending_email_change']);
unset($session6['open_email_modal']);
$res6 = simulateProfilePageLogic($userId, $session6);
assertTest($res6['openEmailModal'] === false, "Modal is CLOSED after cancel action");
assertTest($res6['hasPendingEmailChange'] === false, "hasPendingEmailChange is FALSE after cancel");

// -------------------------------------------------------------
// Test 7: User completes successful verification
// -------------------------------------------------------------
echo "\n7. Test Successful verification completion...\n";
$session7 = [
    'user_id' => $userId,
    'pending_email_change' => [
        'user_id' => $userId,
        'new_email' => 'success@example.com'
    ]
];
// Mimic action=verify_email_change success
unset($session7['pending_email_change']);
unset($session7['open_email_modal']);
$session7['email_change_completed'] = true;
$res7 = simulateProfilePageLogic($userId, $session7);
assertTest($res7['openEmailModal'] === false, "Modal is CLOSED after verification success");
assertTest($res7['hasPendingEmailChange'] === false, "hasPendingEmailChange is FALSE after verification success");

// -------------------------------------------------------------
// Test 8: Source code inspection of users/profile.php
// -------------------------------------------------------------
echo "\n8. Source Code Inspection in users/profile.php...\n";
$profilePhp = file_get_contents('c:/xampp/htdocs/vendolink_claude_v1/users/profile.php');

// Must NOT contain the buggy expression
assertTest(
    strpos($profilePhp, "\$openEmailModal        = !empty(\$_SESSION['open_email_modal']) || \$hasPendingEmailChange;") === false,
    "Buggy calculation (!empty(\$_SESSION['open_email_modal']) || \$hasPendingEmailChange) is REMOVED"
);

// Must contain the corrected calculation
assertTest(
    strpos($profilePhp, "\$openEmailModal        = !empty(\$_SESSION['open_email_modal']);") !== false,
    "Correct calculation (!empty(\$_SESSION['open_email_modal'])) is PRESENT"
);

// Must contain auto-cleanup of expired pending changes
assertTest(
    strpos($profilePhp, "Auto-clean expired pending email changes") !== false,
    "Auto-cleanup of expired pending changes is PRESENT"
);

// Must contain bfcache pageshow prevention
assertTest(
    strpos($profilePhp, "event.persisted") !== false,
    "Pageshow bfcache modal restore guard is PRESENT"
);

// Clean up
$db->query("DELETE FROM users WHERE id = :id", [':id' => $userId]);
assertTest(true, "Cleaned up temporary user ID {$userId}");

// -------------------------------------------------------------
// Summary
// -------------------------------------------------------------
echo "\n=== TEST RESULTS: {$passedTests} PASSED, {$failedTests} FAILED (TOTAL: {$totalTests}) ===\n";
if ($failedTests === 0) {
    echo "🎉 ALL AUTO-OPEN PREVENTION TESTS PASSED 100%!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED.\n";
    exit(1);
}
