<?php
/**
 * Test: Master Admin Protection and Admin Accounts Integrity
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';

$userModel = new User();

echo "=== TESTING MASTER ADMIN & ADMIN ACCOUNTS ===\n\n";

// 1. Check countActiveAdmins
$activeAdmins = $userModel->countActiveAdmins();
echo "1. Active Admins Count: {$activeAdmins}\n";
assert($activeAdmins >= 1, "Must have at least 1 active admin");
echo "[PASS] countActiveAdmins returned {$activeAdmins}.\n\n";

// 2. Check Master Admin cannot be deactivated
echo "2. Testing Master Admin deactivation guard...\n";
$master = $userModel->findByEmail('vendolink.support@gmail.com');
if (!$master) {
    // If master is ID 1
    $master = $userModel->findById(1);
}

if ($master) {
    try {
        $userModel->setStatus((int)$master['id'], 'inactive');
        echo "[FAIL] Expected DomainException when deactivating Master Admin!\n";
        exit(1);
    } catch (DomainException $e) {
        echo "[PASS] Master Admin deactivation properly blocked: " . $e->getMessage() . "\n\n";
    }
} else {
    echo "[SKIP] Master admin user not found by email or ID 1.\n\n";
}

// 3. Check Last Active Admin protection
echo "3. Testing Last Active Admin protection...\n";
if ($activeAdmins === 1 && $master) {
    try {
        $userModel->setStatus((int)$master['id'], 'inactive');
        echo "[FAIL] Expected DomainException for last active admin!\n";
        exit(1);
    } catch (DomainException $e) {
        echo "[PASS] Blocked with message: " . $e->getMessage() . "\n\n";
    }
} else {
    echo "[INFO] More than 1 active admin exists, or tested above.\n\n";
}

echo "ALL MASTER ADMIN PROTECTION TESTS PASSED!\n";
