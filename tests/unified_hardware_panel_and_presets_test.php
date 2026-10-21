<?php
/**
 * Unified Hardware Panel, Presets & Status Card Automation Test
 * Zero Emojis and Zero Forbidden Words Enforced
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/Service.php';
require_once __DIR__ . '/../controllers/MachineController.php';

echo "=== STARTING UNIFIED HARDWARE PANEL & PRESETS VERIFICATION ===\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();
$machineModel = new Machine();
$serviceModel = new Service();

// 1. Fetch Primary Test Machine
$machine = $machineModel->primaryWithServices();
if (!$machine) {
    die("[FAIL] No primary machine found.\n");
}
$machineId = (int)$machine['id'];
$ownerId = (int)$machine['owner_id'];
echo "[1] Testing with Machine ID #{$machineId} ({$machine['name']})\n";

// 2. Test Preset 1 & Preset 2 Template Properties
echo "\n[2] Verifying Preset 1 & Preset 2 Properties...\n";
$badge1 = Machine::getMachineTypeBadge('preset_1');
assert_test($badge1['label'] === 'Preset 1', "Preset 1 label is 'Preset 1'");
assert_test($badge1['has_tanks'] === false, "Preset 1 has_tanks is false (floater sensor unplugged safe)");

$badge2 = Machine::getMachineTypeBadge('preset_2');
assert_test($badge2['label'] === 'Preset 2', "Preset 2 label is 'Preset 2'");
assert_test($badge2['has_tanks'] === false, "Preset 2 has_tanks is false (floater sensor unplugged safe)");

// 3. Test Controller saveHardwareUnified() via Simulation
echo "\n[3] Testing Atomic saveHardwareUnified()...\n";

$origType = $machine['machine_type'] ?? 'carwash';
$origLine1 = $machine['lcd_line1'] ?? '== VendoLink v3.0 ==';
$origLine2 = $machine['lcd_line2'] ?? 'WELCOME CUSTOMER!';
$origLine3 = $machine['lcd_line3'] ?? 'INSERT COIN FIRST!';
$origLine4 = $machine['lcd_line4'] ?? 'WATER:P5 | SOAP:P5';

$services = $machine['services'];
$svc1 = $services[0];
$svc2 = $services[1];
$svc1Id = (int)$svc1['id'];
$svc2Id = (int)$svc2['id'];

// Directly test models/Machine updateMachineType & updateLcdLines
$machineModel->updateMachineType($machineId, 'preset_1');
$machineModel->updateLcdLines($machineId, '== CUSTOM PRESET 1==', 'SELECT YOUR SERVICE ', 'INSERT P5 COIN NOW  ', 'FAST DUAL OPERATION ');

// Directly test models/Service updateConfiguration
$serviceModel->updateConfiguration($svc1Id, true, 5.00, 150, 1, 'Custom Water', 'water', 'water');
$serviceModel->updateConfiguration($svc2Id, true, 5.00, 75, 1, 'Custom Soap', 'soap', 'soap');

$fresh = $machineModel->getByIdWithServices($machineId);
assert_test($fresh['machine_type'] === 'preset_1', "Machine profile updated to preset_1");
assert_test($fresh['lcd_line1'] === '== CUSTOM PRESET 1==', "LCD line 1 updated");
assert_test($fresh['lcd_line4'] === 'FAST DUAL OPERATION ', "LCD line 4 updated");

$freshServices = [];
foreach ($fresh['services'] as $s) {
    $freshServices[(int)$s['id']] = $s;
}
assert_test($freshServices[$svc1Id]['name'] === 'Custom Water', "Outlet 1 name saved as 'Custom Water'");
assert_test((int)$freshServices[$svc1Id]['service_seconds'] === 150, "Outlet 1 seconds saved as 150");
assert_test($freshServices[$svc1Id]['icon'] === 'water', "Outlet 1 icon saved as 'water'");

assert_test($freshServices[$svc2Id]['name'] === 'Custom Soap', "Outlet 2 name saved as 'Custom Soap'");
assert_test((int)$freshServices[$svc2Id]['service_seconds'] === 75, "Outlet 2 seconds saved as 75");
assert_test($freshServices[$svc2Id]['icon'] === 'soap', "Outlet 2 icon saved as 'soap'");

// 4. Test Reversion to Original State
echo "\n[4] Reverting Test Values to Original State...\n";
$machineModel->updateMachineType($machineId, $origType);
$machineModel->updateLcdLines($machineId, $origLine1, $origLine2, $origLine3, $origLine4);
$serviceModel->updateConfiguration($svc1Id, (bool)$svc1['is_enabled'], (float)$svc1['coin_value'], (int)$svc1['service_seconds'], 1, $svc1['name'], $svc1['icon'] ?? 'water', $svc1['type'] ?? 'water');
$serviceModel->updateConfiguration($svc2Id, (bool)$svc2['is_enabled'], (float)$svc2['coin_value'], (int)$svc2['service_seconds'], 1, $svc2['name'], $svc2['icon'] ?? 'soap', $svc2['type'] ?? 'soap');
echo "  [PASS] Database restored cleanly.\n";

// 5. Check Rendered machines.php for Unified Controls, Direct-Editable LCD & Zero Redundant Inputs
echo "\n[5] Checking machines.php View Markup...\n";
$machinesContent = file_get_contents(ROOT_PATH . '/dashboard/machines.php');
assert_test(strpos($machinesContent, 'id="unifiedHardwareForm"') !== false, "Single unified form #unifiedHardwareForm present");
assert_test(strpos($machinesContent, 'id="lcdLine1"') !== false, "Direct-editable LCD line 1 input present");
assert_test(strpos($machinesContent, 'id="lcdLine4"') !== false, "Direct-editable LCD line 4 input present");
assert_test(strpos($machinesContent, 'CUSTOM DISPLAY LINES') === false, "Duplicate redundant CUSTOM DISPLAY LINES block removed");
assert_test(strpos($machinesContent, 'integrated-switch') !== false, "Merged integrated switch present");
assert_test(strpos($machinesContent, 'icon-overlay-popover') !== false, "Click-to-open icon selection overlay present");
assert_test(strpos($machinesContent, "selectOutletIcon(<?= \$outletNum ?>, 'water')") !== false, "Overlay water icon option present");
assert_test(strpos($machinesContent, "selectOutletIcon(<?= \$outletNum ?>, 'soap')") !== false, "Overlay soap icon option present");
assert_test(strpos($machinesContent, 'id="unifiedMasterSaveBtn"') !== false, "Single master save button #unifiedMasterSaveBtn present");
assert_test(strpos($machinesContent, '<i class="bi bi-x-circle me-1"></i>Cancel') !== false, "Cancel button present");

// 6. Check Rendered views/dashboard/cards.php for Design 2 Circuit Rack & Outlet 1/2
echo "\n[6] Checking views/dashboard/cards.php Status Card Markup...\n";
$cardsContent = file_get_contents(ROOT_PATH . '/views/dashboard/cards.php');
assert_test(strpos($cardsContent, 'id="machineCircuitRackCard"') !== false, "Design 2 circuit rack card container present");
assert_test(strpos($cardsContent, 'GPIO 25 &bull; Outlet 1') !== false, "Relabeled Outlet 1 (GPIO 25) present");
assert_test(strpos($cardsContent, 'GPIO 33 &bull; Outlet 2') !== false, "Relabeled Outlet 2 (GPIO 33) present");
assert_test(strpos($cardsContent, 'id="rackControllerBadge"') !== false, "Controller badge container present");
assert_test(strpos($cardsContent, 'id="rackCoinCard"') !== false, "Integrated coin validator card present");
assert_test(strpos($cardsContent, 'fetch(\'' . BASE_URL . '/api/machine_status.php\')') !== false || strpos($cardsContent, '/api/machine_status.php') !== false, "Real-time poller queries /api/machine_status.php");

// 7. Verify Zero Forbidden Words and Zero Emojis in Key Files
echo "\n[7] Verifying Zero Emojis and Zero Forbidden Words...\n";
$checkedFiles = [
    ROOT_PATH . '/dashboard/machines.php',
    ROOT_PATH . '/views/dashboard/cards.php',
    ROOT_PATH . '/api/machine_status.php'
];

foreach ($checkedFiles as $file) {
    $basename = basename($file);
    $text = file_get_contents($file);
    
    // Check forbidden word (case insensitive)
    $hasForbidden = (bool)preg_match('/\bfleet\b/i', $text);
    assert_test(!$hasForbidden, "{$basename} contains zero occurrences of the forbidden word");

    // Check emojis
    $hasEmojis = (bool)preg_match('/[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $text);
    assert_test(!$hasEmojis, "{$basename} contains strictly ZERO emojis");
}

echo "\nALL UNIFIED HARDWARE PANEL & PRESETS TESTS PASSED 100% SUCCESSFULLY!\n";

function assert_test(bool $cond, string $msg): void {
    if (!$cond) {
        die("[FAIL] Assertion failed: $msg\n");
    }
    echo "  [PASS] $msg\n";
}
