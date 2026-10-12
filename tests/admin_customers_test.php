<?php
/**
 * ============================================================
 * VendoLink — Admin Customers Directory Unit Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminDashboard.php';

echo "=== STARTING ADMIN CUSTOMERS DIRECTORY TESTS ===\n\n";

$dashboardModel = new AdminDashboard();
$db             = Database::getInstance();
$pdo            = $db->getConnection();

// Create isolated temporary test owner account
$testUsername = 'test_owner_' . time();
$testEmail    = 'test_owner_' . time() . '@example.com';
$hash         = password_hash('password123', PASSWORD_BCRYPT);

$pdo->prepare(
    "INSERT INTO users (full_name, username, email, password, role, status, email_verified)
     VALUES ('Temp Test Owner', :uname, :email, :pass, 'owner', 'active', 1)"
)->execute([':uname' => $testUsername, ':email' => $testEmail, ':pass' => $hash]);

$tempOwnerId = (int)$pdo->lastInsertId();

// 1. Fetch all customers
echo "1. Testing getAllCustomers()...\n";
$customers = $dashboardModel->getAllCustomers();
if (!is_array($customers)) {
    throw new RuntimeException("getAllCustomers() failed to return an array!");
}
echo "✅ Retrieved " . count($customers) . " machine owner account(s).\n\n";

// 2. Test status toggle
echo "2. Testing toggleCustomerStatus()...\n";
$toggleSuccess = $dashboardModel->toggleCustomerStatus($tempOwnerId, 'inactive');
if (!$toggleSuccess) {
    throw new RuntimeException("toggleCustomerStatus() failed!");
}
$dashboardModel->toggleCustomerStatus($tempOwnerId, 'active');
echo "✅ Account status toggle verified successfully.\n\n";

// 3. Test email verification
echo "3. Testing verifyCustomerEmail()...\n";
$verifySuccess = $dashboardModel->verifyCustomerEmail($tempOwnerId);
if (!$verifySuccess) {
    throw new RuntimeException("verifyCustomerEmail() failed!");
}
echo "✅ Manual email verification verified successfully.\n\n";

// 4. Test account detail update
echo "4. Testing updateCustomerAccount()...\n";
$updateSuccess = $dashboardModel->updateCustomerAccount(
    $tempOwnerId,
    'Updated Test Owner',
    $testEmail,
    $testUsername
);
if (!$updateSuccess) {
    throw new RuntimeException("updateCustomerAccount() failed!");
}
echo "✅ Account details update verified successfully.\n\n";

// Clean up temporary test user
$pdo->exec("DELETE FROM users WHERE id = {$tempOwnerId}");

echo "🎉 ALL ADMIN MACHINE OWNERS DIRECTORY TESTS PASSED 100% SUCCESSFULLY!\n";
