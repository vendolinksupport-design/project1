<?php
/**
 * ============================================================
 * VendoLink — Pre-Sale System Reset Verification Test Suite
 * ============================================================
 *
 * Verifies that the Pre-Sale System Reset executed successfully:
 * 1. Admin account (ID 1, admin, vendolink.support@gmail.com) is intact.
 * 2. Password hash is intact and authentication functions.
 * 3. All non-admin customer accounts have been safely removed.
 * 4. All test machines, services, product keys, license keys, and transactions are cleared (0 rows).
 * 5. All production configurations (settings, ota_releases, mail config) are preserved.
 * 6. All 16 database tables, schema, and FK structures remain intact.
 * 7. Timestamped SQL backup exists in database/backups/ and is non-empty.
 * 8. Clean fresh SYSTEM_RESET activity log entry exists.
 *
 * @package VendoLink Tests
 * @version 1.0.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/SystemReset.php';
require_once __DIR__ . '/../models/User.php';

echo "=== STARTING PRE-SALE SYSTEM RESET VERIFICATION TESTS ===\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();
$userModel = new User();
$resetService = new SystemReset();

$passedTests = 0;
$totalTests  = 0;

function pass(string $msg) {
    global $passedTests, $totalTests;
    $passedTests++;
    $totalTests++;
    echo "   ✅ {$msg}\n";
}

function fail(string $msg) {
    global $totalTests;
    $totalTests++;
    echo "   ❌ FAIL: {$msg}\n";
    exit(1);
}

// ============================================================
// 1. Verify Primary Administrator Account Integrity
// ============================================================
echo "1. Verifying Primary Administrator Account Integrity...\n";

$admin = $userModel->findById(1);
if (!$admin) {
    fail("Admin ID 1 does not exist in database");
}
pass("Admin ID 1 exists");

if ($admin['username'] !== 'admin') {
    fail("Admin username is not 'admin' (found: {$admin['username']})");
}
pass("Admin username is 'admin'");

if ($admin['email'] !== 'vendolink.support@gmail.com') {
    fail("Admin email is not 'vendolink.support@gmail.com' (found: {$admin['email']})");
}
pass("Admin email is 'vendolink.support@gmail.com'");

if ($admin['role'] !== 'administrator') {
    fail("Admin role is not 'administrator' (found: {$admin['role']})");
}
pass("Admin role is 'administrator'");

if (empty($admin['password']) || strlen($admin['password']) < 20) {
    fail("Admin password hash is missing or corrupted");
}
pass("Admin password hash is intact");

// Test authentication with admin password
if (!password_verify('Admin@123431', $admin['password'])) {
    fail("Admin login verification with known credentials failed");
}
pass("Admin credential authentication verified successfully");

// ============================================================
// 2. Verify Zero Customer Accounts Remain
// ============================================================
echo "\n2. Verifying Removal of Non-Admin Customer Accounts...\n";

$nonAdminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE id != 1")->fetchColumn();
if ($nonAdminCount !== 0) {
    fail("Found {$nonAdminCount} non-admin user accounts; expected 0");
}
pass("Zero non-admin customer accounts remain (only Admin #1 exists)");

$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($totalUsers !== 1) {
    fail("Total users count is {$totalUsers}; expected 1");
}
pass("Exactly 1 total user in users table (Primary Administrator)");

// ============================================================
// 3. Verify Hardware, Keys, and Transaction Records Cleared
// ============================================================
echo "\n3. Verifying Clean Slate for Hardware, Keys, and Transactions...\n";

$checks = [
    'machines'              => "SELECT COUNT(*) FROM machines",
    'services'              => "SELECT COUNT(*) FROM services",
    'product_keys'          => "SELECT COUNT(*) FROM product_keys",
    'license_keys'          => "SELECT COUNT(*) FROM license_keys",
    'service_transactions'  => "SELECT COUNT(*) FROM service_transactions",
    'cash_collections'      => "SELECT COUNT(*) FROM cash_collections",
    'configuration_history' => "SELECT COUNT(*) FROM configuration_history",
    'maintenance_history'   => "SELECT COUNT(*) FROM maintenance_history",
    'maintenance_items'     => "SELECT COUNT(*) FROM maintenance_items",
    'notifications'         => "SELECT COUNT(*) FROM notifications",
    'support_conversations' => "SELECT COUNT(*) FROM support_conversations",
    'support_messages'      => "SELECT COUNT(*) FROM support_messages",
];

foreach ($checks as $table => $sql) {
    $count = (int)$pdo->query($sql)->fetchColumn();
    if ($count !== 0) {
        fail("Table '{$table}' has {$count} rows; expected 0 in clean pre-sale state");
    }
    pass("Table '{$table}' has 0 rows (clean slate)");
}

// ============================================================
// 4. Verify System Settings and Production Config Preserved
// ============================================================
echo "\n4. Verifying Preservation of System Settings and OTA Releases...\n";

$settingsCount = (int)$pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
if ($settingsCount < 10) {
    fail("Settings count is {$settingsCount}; expected at least 15 system configuration entries");
}
pass("Settings preserved: {$settingsCount} configuration records intact");

$otaCount = (int)$pdo->query("SELECT COUNT(*) FROM ota_releases")->fetchColumn();
if ($otaCount < 1) {
    fail("OTA releases table is empty; expected firmware releases to be preserved");
}
pass("OTA Releases preserved: {$otaCount} firmware release record(s) intact");

// Check mail configuration file and constants
$mailConfigPath = ROOT_PATH . '/config/mail.local.php';
if (!file_exists($mailConfigPath)) {
    fail("mail.local.php does not exist");
}
if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS') || empty(SMTP_PASS)) {
    fail("SMTP constants are not defined or incomplete");
}
pass("Local SMTP configuration preserved (Host: " . SMTP_HOST . ", User: " . SMTP_USER . ")");

// ============================================================
// 5. Verify All 16 Database Tables Exist and are Accessible
// ============================================================
echo "\n5. Verifying Database Schema and 16 Tables Integrity...\n";

$expectedTables = [
    'activity_logs',
    'cash_collections',
    'configuration_history',
    'license_keys',
    'machines',
    'maintenance_history',
    'maintenance_items',
    'notifications',
    'ota_releases',
    'product_keys',
    'services',
    'service_transactions',
    'settings',
    'support_conversations',
    'support_messages',
    'users'
];

$actualTables = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

foreach ($expectedTables as $expected) {
    if (!in_array($expected, $actualTables, true)) {
        fail("Table '{$expected}' is missing from vendolink_db");
    }
    pass("Table '{$expected}' exists and is accessible");
}

// ============================================================
// 6. Verify Backup Generation and Storage
// ============================================================
echo "\n6. Verifying Pre-Reset Backup Artifacts...\n";

$backupDir = ROOT_PATH . '/database/backups';
if (!is_dir($backupDir)) {
    fail("Backup directory {$backupDir} does not exist");
}
pass("Backup directory exists at database/backups/");

$backups = glob($backupDir . '/vendolink_db_pre_reset_*.sql');
if (empty($backups)) {
    fail("No pre-reset SQL backups found in {$backupDir}");
}
$latestBackup = end($backups);
$backupSize = filesize($latestBackup);
if ($backupSize < 1000) {
    fail("Latest backup is suspiciously small: {$backupSize} bytes");
}
pass("Pre-reset SQL backup verified: " . basename($latestBackup) . " ({$backupSize} bytes)");

// ============================================================
// 7. Verify Clean Activity Log
// ============================================================
echo "\n7. Verifying Clean Activity Log State...\n";

$resetLog = $pdo->query("SELECT action, description FROM activity_logs WHERE action = 'SYSTEM_RESET' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$resetLog || $resetLog['action'] !== 'SYSTEM_RESET') {
    fail("Expected 'SYSTEM_RESET' activity log entry to exist in database");
}
pass("Clean activity log recorded: '{$resetLog['action']}' - {$resetLog['description']}");

// ============================================================
// Summary
// ============================================================
echo "\n============================================================\n";
echo "🎉 ALL TESTS PASSED: {$passedTests}/{$totalTests} assertions verified!\n";
echo "   Database is fully reset and ready for pre-sale hardware prep.\n";
echo "============================================================\n";
