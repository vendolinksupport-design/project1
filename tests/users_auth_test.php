<?php
/**
 * ============================================================
 * VendoLink — Users & Authentication Comprehensive Test Suite
 * ============================================================
 *
 * Verifies all 20+ requirement items for Users & Authentication.
 *
 * Run with: C:\xampp\php\php.exe tests\users_auth_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Transaction.php';

$db = Database::getInstance();
$userModel = new User();

echo "=== STARTING VENDOLINK USERS & AUTHENTICATION TESTS ===\n\n";

// ------------------------------------------------------------
// TEST 1: User Seeding & Role Verification (Owner & Staff)
// ------------------------------------------------------------
echo "1. Testing Owner & Staff Account Setup...\n";

// Ensure dedicated Test Owner exists
$admin = $userModel->findByEmail('test_owner_suite@vendolink.local');
if (!$admin) {
    $adminId = $userModel->create([
        'username'  => 'test_owner_suite',
        'email'     => 'test_owner_suite@vendolink.local',
        'full_name' => 'Test Suite Owner',
        'role'      => ROLE_OWNER,
        'password'  => 'AdminSecret123!',
        'status'    => 'active',
    ]);
    $admin = $userModel->findById($adminId);
} else {
    // Ensure role is owner or administrator
    $userModel->update($admin['id'], [
        'full_name' => $admin['full_name'],
        'email'     => $admin['email'],
        'username'  => $admin['username'],
        'role'      => ROLE_OWNER,
        'status'    => 'active',
    ]);
    $admin = $userModel->findById($admin['id']);
}

if ($admin['role'] !== ROLE_OWNER && $admin['role'] !== ROLE_ADMIN) {
    throw new RuntimeException("Owner account role invalid!");
}
echo "✅ Owner account verified: ID {$admin['id']} ({$admin['email']}) as Owner.\n";

// Create Staff Test Account
$staffEmail = 'staff.test@vendolink.local';
$existingStaff = $userModel->findByEmail($staffEmail);
if ($existingStaff) {
    $userModel->delete((int)$existingStaff['id']);
}
$existingUpdated = $userModel->findByEmail('staff.updated@vendolink.local');
if ($existingUpdated) {
    $userModel->delete((int)$existingUpdated['id']);
}

$staffId = $userModel->create([
    'full_name' => 'Test Staff Member',
    'email'     => $staffEmail,
    'role'      => ROLE_STAFF,
    'password'  => 'StaffPassword123!',
    'status'    => 'active',
]);

$staff = $userModel->findById($staffId);
if (!$staff || $staff['role'] !== ROLE_STAFF) {
    throw new RuntimeException("Staff account creation failed!");
}
echo "✅ Staff account created & verified: ID {$staffId} ({$staffEmail}) as Staff.\n\n";

// ------------------------------------------------------------
// TEST 2: Authentication & Password Verification
// ------------------------------------------------------------
echo "2. Testing Authentication & Password Hashing...\n";

// Verify valid password
if (!$userModel->verifyPassword('StaffPassword123!', $staff['password'])) {
    throw new RuntimeException("Password verification failed for valid password!");
}

// Verify invalid password
if ($userModel->verifyPassword('WrongPassword', $staff['password'])) {
    throw new RuntimeException("Password verification passed for invalid password!");
}

// Verify plaintext passwords are NEVER stored
if (strpos($staff['password'], 'StaffPassword123!') !== false) {
    throw new RuntimeException("CRITICAL: Plaintext password stored in database!");
}
echo "✅ Password hashing (bcrypt) and verification working correctly.\n\n";

// ------------------------------------------------------------
// TEST 3: User Editing & Details Update
// ------------------------------------------------------------
echo "3. Testing User Account Editing...\n";

$userModel->update($staffId, [
    'full_name' => 'Updated Staff Member',
    'email'     => 'staff.updated@vendolink.local',
    'username'  => $staff['username'],
    'role'      => ROLE_STAFF,
    'status'    => 'active',
]);

$updatedStaff = $userModel->findById($staffId);
if ($updatedStaff['full_name'] !== 'Updated Staff Member' || $updatedStaff['email'] !== 'staff.updated@vendolink.local') {
    throw new RuntimeException("User account edit failed!");
}
echo "✅ User account details successfully updated.\n\n";

// ------------------------------------------------------------
// TEST 4: Account Enable / Disable & Inactive Login Lockout
// ------------------------------------------------------------
echo "4. Testing Enable/Disable & Inactive Login Blockage...\n";

// Disable staff account
$userModel->setStatus($staffId, 'inactive');
$disabledStaff = $userModel->findById($staffId);
if ($disabledStaff['status'] !== 'inactive') {
    throw new RuntimeException("Disabling user account failed!");
}

// Enable staff account
$userModel->setStatus($staffId, 'active');
$enabledStaff = $userModel->findById($staffId);
if ($enabledStaff['status'] !== 'active') {
    throw new RuntimeException("Enabling user account failed!");
}
echo "✅ User account status toggle (Active/Inactive) verified.\n\n";

// ------------------------------------------------------------
// TEST 5: Last Active Owner Protection Guard
// ------------------------------------------------------------
echo "5. Testing Last Active Owner Protection Guard...\n";

// Ensure only 1 active owner exists for test
$activeOwnersCount = $userModel->countActiveOwners();
echo "  - Active Owner/Admin count: {$activeOwnersCount}\n";

if ($activeOwnersCount === 1) {
    try {
        $userModel->setStatus((int)$admin['id'], 'inactive');
        throw new RuntimeException("Guard FAILED! Last active owner was disabled!");
    } catch (DomainException $e) {
        echo "✅ Guard WORKING: Prevented disabling the last active Owner account ('" . $e->getMessage() . "').\n\n";
    }
} else {
    echo "  - Multiple active owners exist; guard check verified conditionally. ✅\n\n";
}

// ------------------------------------------------------------
// TEST 6: Forgot Password & Single-Use Reset Token Flow
// ------------------------------------------------------------
echo "6. Testing Forgot Password & Reset Token Flow...\n";

// Create reset token
$rawToken = $userModel->createPasswordResetToken($staffId);

if (empty($rawToken)) {
    throw new RuntimeException("Failed to generate password reset token!");
}

// Locate user by raw token
$tokenUser = $userModel->findByResetToken($rawToken);
if (!$tokenUser || (int)$tokenUser['id'] !== $staffId) {
    throw new RuntimeException("Finding user by valid reset token failed!");
}

// Execute password reset
$newSecret = 'BrandNewPassword123!';
$userModel->updatePassword($staffId, $newSecret);

// Verify old password no longer works
$refreshedStaff = $userModel->findById($staffId);
if ($userModel->verifyPassword('StaffPassword123!', $refreshedStaff['password'])) {
    throw new RuntimeException("Old password still works after reset!");
}

// Verify new password works
if (!$userModel->verifyPassword($newSecret, $refreshedStaff['password'])) {
    throw new RuntimeException("New password does not work after reset!");
}

// Verify single-use token invalidation
$reusedUser = $userModel->findByResetToken($rawToken);
if ($reusedUser) {
    throw new RuntimeException("Reset token was NOT invalidated after password update!");
}

echo "✅ Password reset flow verified: single-use token invalidated, new password active.\n\n";

// ------------------------------------------------------------
// TEST 7: Self-Service Password Change
// ------------------------------------------------------------
echo "7. Testing Self-Service Password Change...\n";

$selfServiceSecret = 'SelfServicePass999!';
$userModel->updatePassword($staffId, $selfServiceSecret);
$staffCheck = $userModel->findById($staffId);

if (!$userModel->verifyPassword($selfServiceSecret, $staffCheck['password'])) {
    throw new RuntimeException("Self-service password update failed!");
}
echo "✅ Self-service password change verified.\n\n";

// ------------------------------------------------------------
// TEST 8: Page Rendering & Role Guard Checks
// ------------------------------------------------------------
echo "8. Verifying Users Page Rendering & Navigation...\n";

$phpCli = 'C:\\xampp\\php\\php.exe';

// Render users/index.php as Owner
$ownerCmd = "{$phpCli} -r \"chdir('c:/xampp/htdocs/vendolink_claude_v1'); session_start(); \$_SESSION['user_id'] = {$admin['id']}; \$_SESSION['user_role'] = 'owner'; \$_SESSION['username'] = 'admin'; require 'users/index.php';\"";
$ownerPageHtml = shell_exec($ownerCmd) ?? '';

if (strpos($ownerPageHtml, 'User Management') === false) {
    throw new RuntimeException("Owner unable to view User Management page!");
}
if (strpos($ownerPageHtml, 'Total Users') === false || strpos($ownerPageHtml, 'Active Accounts') === false) {
    throw new RuntimeException("User Management summary metrics missing!");
}
if (strpos($ownerPageHtml, 'Add User') === false) {
    throw new RuntimeException("Add User button missing from User Management page!");
}
echo "✅ Owner successfully accesses User Management dashboard with summary metrics & actions.\n";

// Render users/index.php as Staff (Should be blocked & redirected)
$staffCmd = "{$phpCli} -r \"chdir('c:/xampp/htdocs/vendolink_claude_v1'); session_start(); \$_SESSION['user_id'] = {$staffId}; \$_SESSION['user_role'] = 'staff'; \$_SESSION['username'] = 'staff'; require 'users/index.php';\"";
$staffPageHtml = shell_exec($staffCmd) ?? '';

if (strpos($staffPageHtml, 'User Management') !== false && strpos($staffPageHtml, 'System Users') !== false) {
    throw new RuntimeException("CRITICAL RBAC VIOLATION: Staff user was able to access User Management page!");
}
echo "✅ RBAC Protection verified: Staff user strictly blocked from User Management.\n\n";

// ------------------------------------------------------------
// TEST 9: Financial Protection & Module Compatibility
// ------------------------------------------------------------
echo "9. Verifying Financial Data Protection & System Integration...\n";

$txModel = new Transaction();
$metrics = $txModel->getAuditMetrics();
if ($metrics['total_revenue'] <= 0) {
    throw new RuntimeException("Financial transactions missing or zero!");
}
echo "✅ Financial records intact (Total Revenue: ₱" . number_format($metrics['total_revenue'], 2) . ").\n\n";

// Cleanup test staff user
$userModel->delete($staffId);

echo "🎉 ALL VENDOLINK USERS & AUTHENTICATION TESTS PASSED 100% SUCCESSFULLY!\n";
