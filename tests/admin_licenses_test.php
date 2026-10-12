<?php
/**
 * ============================================================
 * VendoLink — Admin Product Key Generator Verification Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminDashboard.php';

echo "=== STARTING ADMIN PRODUCT KEY GENERATOR TESTS ===\n\n";

$dashboardModel = new AdminDashboard();

// 1. Fetch all license keys
echo "1. Testing getLicenseKeys()...\n";
$keys = $dashboardModel->getLicenseKeys();
if (!is_array($keys)) {
    throw new RuntimeException("getLicenseKeys() failed to return an array!");
}
echo "✅ Retrieved " . count($keys) . " license key record(s).\n\n";

// 2. Test generateLicenseKey
echo "2. Testing generateLicenseKey()...\n";
$testSerial = 'HW-TEST-GEN-REVOKE-' . time();
$genKey = $dashboardModel->generateLicenseKey('Test Owner', $testSerial);
if (empty($genKey) || strpos($genKey, 'VL-2026-') !== 0) {
    throw new RuntimeException("generateLicenseKey() generated an invalid key format!");
}
echo "✅ Generated product key: {$genKey}.\n\n";

// 3. Test revokeLicenseKey
echo "3. Testing revokeLicenseKey()...\n";
$db = Database::getInstance();
$pkRow = $db->query("SELECT id FROM product_keys WHERE product_key = :k", [':k' => $genKey])->fetch();
$insertedId = (int)($pkRow['id'] ?? 0);

$revokeSuccess = $dashboardModel->revokeLicenseKey($insertedId);
if (!$revokeSuccess) {
    throw new RuntimeException("revokeLicenseKey() failed!");
}

// Clean up test entry
$db->query("DELETE FROM product_keys WHERE id = {$insertedId}");
$db->query("DELETE FROM license_keys WHERE license_key = '{$genKey}'");
echo "✅ Product activation key revocation verified successfully.\n\n";

echo "🎉 ALL ADMIN PRODUCT KEY GENERATOR TESTS PASSED 100% SUCCESSFULLY!\n";
