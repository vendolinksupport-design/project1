<?php
/**
 * ============================================================
 * VendoLink — OTA Active Deployments & History Separation Test
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminDashboard.php';

echo "=== STARTING OTA ACTIVE & HISTORY SEPARATION TEST ===\n\n";

$db = Database::getInstance();
$dashModel = new AdminDashboard();

// Setup: Ensure a test release exists
$testRel = $db->query("SELECT id, version FROM ota_releases ORDER BY id DESC LIMIT 1")->fetch();
if (!$testRel) {
    $dashModel->createOtaRelease('v3.2.0-test', 'vendolink_test_3.2.bin', 1200000, 'Test 3.2 release');
    $testRel = $db->query("SELECT id, version FROM ota_releases ORDER BY id DESC LIMIT 1")->fetch();
}

$releaseId = (int)$testRel['id'];
$version = $testRel['version'];

echo "1. Creating an active deployment for testing...\n";
$depId = $dashModel->createOtaDeployment($releaseId, 'all');
if ($depId <= 0) {
    throw new RuntimeException("Failed to create active deployment!");
}
echo "   ✅ Created active deployment #{$depId}\n\n";

echo "2. Testing getActiveOtaDeployments()...\n";
$activeDeps = $dashModel->getActiveOtaDeployments();
$found = false;
foreach ($activeDeps as $ad) {
    if ((int)$ad['id'] === $depId) {
        $found = true;
        echo "   ✅ Found active deployment #{$depId} with status '{$ad['status']}'\n";
        echo "   ✅ Target version: {$ad['version']}\n";
        echo "   ✅ Total targets: {$ad['total_targets']}, Updated: {$ad['updated_count']}, Pending: {$ad['pending_count']}\n";
        echo "   ✅ Progress percentage: {$ad['progress_pct']}%\n";
        if (!isset($ad['target_machines']) || !is_array($ad['target_machines'])) {
            throw new RuntimeException("target_machines array missing from active deployment!");
        }
        echo "   ✅ Target machines array populated with " . count($ad['target_machines']) . " machine(s).\n";
        break;
    }
}
if (!$found) {
    throw new RuntimeException("Active deployment #{$depId} not found in getActiveOtaDeployments()!");
}
echo "\n";

echo "3. Testing completeOtaDeployment()...\n";
$completed = $dashModel->completeOtaDeployment($depId);
if (!$completed) {
    throw new RuntimeException("completeOtaDeployment failed!");
}
echo "   ✅ Deployment #{$depId} marked completed.\n\n";

echo "4. Testing getOtaDeploymentHistory()...\n";
$history = $dashModel->getOtaDeploymentHistory(10, 0);
$foundInHist = false;
foreach ($history as $hd) {
    if ((int)$hd['id'] === $depId) {
        $foundInHist = true;
        echo "   ✅ Found deployment #{$depId} in historical deployments with status: '{$hd['status']}'\n";
        break;
    }
}
if (!$foundInHist) {
    throw new RuntimeException("Deployment #{$depId} missing from history!");
}

// Clean up
$db->query("DELETE FROM ota_deployments WHERE id = {$depId}");
echo "\n5. Cleanup test record completed.\n\n";

echo "🎉 ALL OTA SEPARATION & PROGRESS TESTS PASSED SUCCESSFULLY!\n";
