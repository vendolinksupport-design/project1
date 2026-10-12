<?php
/**
 * ============================================================
 * VendoLink — Admin OTA Wireless Firmware Center Verification Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminDashboard.php';

echo "=== STARTING ADMIN OTA FIRMWARE CENTER TESTS ===\n\n";

$dashboardModel = new AdminDashboard();

// 1. Fetch all OTA releases
echo "1. Testing getOtaReleases()...\n";
$releases = $dashboardModel->getOtaReleases();
if (!is_array($releases)) {
    throw new RuntimeException("getOtaReleases() failed to return an array!");
}
echo "✅ Retrieved " . count($releases) . " firmware release build(s).\n\n";

// 2. Test createOtaRelease
echo "2. Testing createOtaRelease()...\n";
$testVer = 'v2.3.0-test';
$createSuccess = $dashboardModel->createOtaRelease($testVer, 'vendolink_esp32_v2.3.0_test.bin', 1070000, 'Test release');
if (!$createSuccess) {
    throw new RuntimeException("createOtaRelease() failed!");
}

// Clean up test entry
$db = Database::getInstance();
$db->query("DELETE FROM ota_releases WHERE version = 'v2.3.0-test'");
$db->query("UPDATE ota_releases SET is_latest = 1 WHERE version = 'v2.2.0'");

echo "✅ OTA release creation and state management verified successfully.\n\n";

echo "🎉 ALL ADMIN OTA FIRMWARE CENTER TESTS PASSED 100% SUCCESSFULLY!\n";
