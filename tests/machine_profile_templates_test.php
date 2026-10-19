<?php
/**
 * Machine Profile Templates & Hardware Floater Isolation Test
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/Service.php';

echo "=== STARTING MACHINE PROFILE TEMPLATE TESTS ===\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();
$machineModel = new Machine();

// 1. Get primary test machine
$machine = $machineModel->primaryWithServices();
if (!$machine) {
    die("[FAIL] No machine found for testing.\n");
}
$machineId = (int)$machine['id'];
$machineKey = $machine['machine_key'];
echo "[1] Using Machine ID #{$machineId} ({$machine['name']})\n";

// 2. Verify machine_type column exists
$cols = $pdo->query("SHOW COLUMNS FROM machines LIKE 'machine_type'")->fetchAll();
if (empty($cols)) {
    die("[FAIL] Column 'machine_type' does not exist in machines table.\n");
}
echo "  [PASS] Column 'machine_type' verified in machines table.\n";

// 3. Test Applying 'air_blower' Template
echo "\n[2] Testing 'air_blower' Template Application...\n";
$applied = $machineModel->applyTemplate($machineId, 'air_blower');
if (!$applied) {
    die("[FAIL] Failed to apply 'air_blower' template.\n");
}
echo "  [PASS] applyTemplate('air_blower') returned true.\n";

// Fetch fresh machine state
$mFresh = $machineModel->getByIdWithServices($machineId);
if (($mFresh['machine_type'] ?? '') !== 'air_blower') {
    die("[FAIL] Expected machine_type 'air_blower', got '" . ($mFresh['machine_type'] ?? '') . "'.\n");
}
echo "  [PASS] machines.machine_type is 'air_blower'.\n";

// Verify services
$services = $mFresh['services'];
$svc1 = $services[0] ?? null;
$svc2 = $services[1] ?? null;

if (!$svc1 || $svc1['name'] !== 'Tire Air' || $svc1['type'] !== 'air') {
    die("[FAIL] Relay 1 service expected 'Tire Air' (air), got '{$svc1['name']}' ({$svc1['type']}).\n");
}
echo "  [PASS] Relay 1 successfully updated to '{$svc1['name']}' (type: {$svc1['type']}).\n";

if (!$svc2 || $svc2['name'] !== 'Car Blower' || $svc2['type'] !== 'blower') {
    die("[FAIL] Relay 2 service expected 'Car Blower' (blower), got '{$svc2['name']}' ({$svc2['type']}).\n");
}
echo "  [PASS] Relay 2 successfully updated to '{$svc2['name']}' (type: {$svc2['type']}).\n";

// Verify LCD preset
if (trim($mFresh['lcd_line1']) !== '== AIR & BLOWER ==' || trim($mFresh['lcd_line4']) !== 'AIR:P5  | BLOWER:P5') {
    die("[FAIL] LCD presets did not match expected air_blower lines.\n");
}
echo "  [PASS] 20x4 LCD presets updated to Air & Blower template.\n";

// 4. Test Floater Suppression for 'air_blower'
echo "\n[3] Testing Hardware Floater Suppression for Air/Blower Units...\n";
$mWithLowTanks = $mFresh;
$mWithLowTanks['water_tank_low'] = 1;
$mWithLowTanks['soap_tank_low'] = 1;

$alerts = $machineModel->getMachineAlerts($mWithLowTanks);
$hasTankAlert = false;
foreach ($alerts as $a) {
    if (stripos($a['title'], 'Tank') !== false || stripos($a['title'], 'Water Level') !== false || stripos($a['title'], 'Soap Level') !== false) {
        $hasTankAlert = true;
        break;
    }
}
if ($hasTankAlert) {
    die("[FAIL] getMachineAlerts() generated tank alert for air_blower machine!\n");
}
echo "  [PASS] Floater tank alerts properly suppressed for air_blower machines.\n";

// Test updateTankStatus() ignored
$tankUpdateRes = $machineModel->updateTankStatus($machineKey, 'water', 'low');
if (($tankUpdateRes['status'] ?? '') !== 'ignored') {
    die("[FAIL] updateTankStatus() did not ignore tank event for air_blower machine.\n");
}
echo "  [PASS] updateTankStatus() safely ignores floater events for air_blower machines.\n";

// 5. Test Applying 'tire_air' (Solo Air) Template
echo "\n[4] Testing 'tire_air' (Solo Inflator) Template Application...\n";
$appliedSolo = $machineModel->applyTemplate($machineId, 'tire_air');
if (!$appliedSolo) {
    die("[FAIL] Failed to apply 'tire_air' template.\n");
}
$mSolo = $machineModel->getByIdWithServices($machineId);
if (($mSolo['machine_type'] ?? '') !== 'tire_air') {
    die("[FAIL] Expected machine_type 'tire_air'.\n");
}
echo "  [PASS] machines.machine_type is 'tire_air'.\n";

// 6. Test Reverting Back to 'carwash' Template
echo "\n[5] Testing Reversion Back to 'carwash' Template...\n";
$appliedCarwash = $machineModel->applyTemplate($machineId, 'carwash');
if (!$appliedCarwash) {
    die("[FAIL] Failed to revert to 'carwash' template.\n");
}
$mCarwash = $machineModel->getByIdWithServices($machineId);
if (($mCarwash['machine_type'] ?? '') !== 'carwash') {
    die("[FAIL] Expected machine_type 'carwash'.\n");
}
echo "  [PASS] machines.machine_type reverted to 'carwash'.\n";

$cwSvc1 = $mCarwash['services'][0] ?? null;
$cwSvc2 = $mCarwash['services'][1] ?? null;
if ($cwSvc1['name'] !== 'Water Service' || $cwSvc2['name'] !== 'Soap Service') {
    die("[FAIL] Services did not revert to Water & Soap services.\n");
}
echo "  [PASS] Services restored to Water Service and Soap Service.\n";

// Verify tank alerts active again for carwash
$mCarwash['water_tank_low'] = 1;
$cwAlerts = $machineModel->getMachineAlerts($mCarwash);
$cwHasTankAlert = false;
foreach ($cwAlerts as $a) {
    if (stripos($a['title'], 'Water Level') !== false) {
        $cwHasTankAlert = true;
        break;
    }
}
if (!$cwHasTankAlert) {
    die("[FAIL] Water Level alert should be active for carwash machine.\n");
}
echo "  [PASS] Tank alerts properly active for carwash machines.\n";

// 7. Verify Machine::getMachineTypeBadge()
echo "\n[6] Verifying Machine::getMachineTypeBadge()...\n";
$badgeAir = Machine::getMachineTypeBadge('air_blower');
if ($badgeAir['has_tanks'] !== false || $badgeAir['icon'] !== 'bi-wind') {
    die("[FAIL] Badge for air_blower incorrect.\n");
}
$badgeCw = Machine::getMachineTypeBadge('carwash');
if ($badgeCw['has_tanks'] !== true || $badgeCw['icon'] !== 'bi-droplet-half') {
    die("[FAIL] Badge for carwash incorrect.\n");
}
echo "  [PASS] Machine type badges and properties verified.\n";

echo "\n🎉 ALL MACHINE PROFILE TEMPLATE TESTS PASSED 100% SUCCESSFULLY!\n";
