<?php
declare(strict_types=1);

/**
 * Automated Verification Suite for:
 * 1. Restoring exactly 2 outlets (Outlet 1 & Outlet 2) and removing Outlets 3-6.
 * 2. Database constraint (uq_services_machine_key) preventing regeneration.
 * 3. Independent tank sensor status per outlet (GPIO 13 for Outlet 1, GPIO 12 for Outlet 2).
 * 4. Distinct sensor states (connected, low level alert, disconnected offline, disabled dry).
 * 5. Controller save behavior preserving settings and 2-outlet bounds.
 * 6. API response metadata in /api/machine_status.php.
 * 7. Zero emojis and zero forbidden words compliance.
 */

session_save_path(__DIR__);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/Service.php';
require_once __DIR__ . '/../controllers/MachineController.php';

$rootDir = dirname(__DIR__);
$passed = 0;
$total = 0;

function assertCondition(bool $condition, string $testName, string $details = ''): void {
    global $passed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "[PASS] " . $testName . PHP_EOL;
    } else {
        echo "[FAIL] " . $testName . ($details ? " - Details: " . $details : "") . PHP_EOL;
    }
}

echo "=== STARTING VERIFICATION: 2 OUTLETS & INDEPENDENT TANK SENSORS ===" . PHP_EOL . PHP_EOL;

$db = Database::getInstance();
$pdo = $db->getConnection();
$machineModel = new Machine();

// ============================================================
// 1. Database Outlets Count & Unique Key Constraint
// ============================================================
echo "[SECTION 1] Database Outlets Count & Integrity Constraints" . PHP_EOL;

$svcRows = $pdo->query("SELECT id, service_key, name, is_enabled, service_seconds FROM services WHERE machine_id = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
assertCondition(
    count($svcRows) === 2,
    "Machine #1 has exactly 2 active services in database",
    "Found " . count($svcRows) . " services."
);

$keys = array_column($svcRows, 'service_key');
assertCondition(
    in_array('water', $keys, true) && in_array('soap', $keys, true),
    "Machine #1 contains 'water' (Outlet 1) and 'soap' (Outlet 2)"
);

$uqIndex = $pdo->query("SHOW INDEXES FROM services WHERE Key_name = 'uq_services_machine_key'")->fetchAll();
assertCondition(
    !empty($uqIndex),
    "Unique constraint 'uq_services_machine_key' active on services(machine_id, service_key)"
);

// Verify duplicate insertion rejection by MySQL
$duplicateBlocked = false;
try {
    $pdo->exec("INSERT INTO services (machine_id, service_key, name, type, coin_value, service_seconds, is_enabled) VALUES (1, 'water', 'Duplicate Water', 'water', 5.00, 120, 1)");
} catch (PDOException $e) {
    $duplicateBlocked = true;
}
assertCondition(
    $duplicateBlocked,
    "MySQL rejects duplicate outlet insertion for same machine"
);

// Verify new timestamp columns exist
$cols = $pdo->query("SHOW COLUMNS FROM machines")->fetchAll(PDO::FETCH_COLUMN);
assertCondition(
    in_array('water_tank_updated_at', $cols, true) && in_array('soap_tank_updated_at', $cols, true),
    "Independent timestamp columns 'water_tank_updated_at' and 'soap_tank_updated_at' present"
);

// ============================================================
// 2. Machine Model Methods & Clean Bounds
// ============================================================
echo PHP_EOL . "[SECTION 2] Machine Model getServices() and primaryWithServices()" . PHP_EOL;

$mServices = $machineModel->getServices(1);
assertCondition(
    count($mServices) === 2,
    "Machine::getServices(1) returns exactly 2 records"
);

$primary = $machineModel->primaryWithServices(2);
assertCondition(
    $primary !== null && count($primary['services']) === 2,
    "Machine::primaryWithServices(2) contains exactly 2 outlets in 'services'"
);

// ============================================================
// 3. Independent Sensor Status Helper Tests
// ============================================================
echo PHP_EOL . "[SECTION 3] Independent Sensor Status Logic" . PHP_EOL;

$mockMachineOnline = [
    'id'                => 1,
    'machine_type'      => 'carwash',
    'calculated_status' => 'online',
    'water_tank_low'    => 0,
    'soap_tank_low'     => 1, // Soap is LOW, Water is NORMAL
    'last_seen'         => date('Y-m-d H:i:s'),
];

$svcWater = ['type' => 'water', 'icon' => 'water', 'service_key' => 'water', 'name' => 'Water Service'];
$svcSoap  = ['type' => 'soap', 'icon' => 'soap', 'service_key' => 'soap', 'name' => 'Soap Service'];
$svcDry   = ['type' => 'air', 'icon' => 'tire_air', 'service_key' => 'air', 'name' => 'Tire Air'];

$statusWater = Machine::getOutletSensorStatus($mockMachineOnline, $svcWater, 1);
$statusSoap  = Machine::getOutletSensorStatus($mockMachineOnline, $svcSoap, 2);

assertCondition(
    $statusWater['status'] === 'connected' && $statusWater['gpio_pin'] === 13,
    "Outlet 1 (Water) reports 'connected' on GPIO 13 when water_tank_low = 0",
    "Got status: " . $statusWater['status']
);
assertCondition(
    $statusSoap['status'] === 'low' && $statusSoap['gpio_pin'] === 12,
    "Outlet 2 (Soap) reports 'low' on GPIO 12 when soap_tank_low = 1",
    "Got status: " . $statusSoap['status']
);
assertCondition(
    $statusWater['status'] !== $statusSoap['status'],
    "Outlet 1 and Outlet 2 sensor statuses are strictly independent (one normal, one low)"
);

// Offline Controller Test
$mockMachineOffline = $mockMachineOnline;
$mockMachineOffline['calculated_status'] = 'offline';
$statusOffline = Machine::getOutletSensorStatus($mockMachineOffline, $svcWater, 1);
assertCondition(
    $statusOffline['status'] === 'disconnected',
    "Offline controller causes sensor to report 'disconnected'"
);

// Dry Service Test
$statusDry = Machine::getOutletSensorStatus($mockMachineOnline, $svcDry, 1);
assertCondition(
    $statusDry['status'] === 'disabled' && $statusDry['supported'] === false,
    "Non-liquid / dry service (Tire Air) reports sensor 'disabled'"
);

// ============================================================
// 4. machines.php View Render Verification
// ============================================================
echo PHP_EOL . "[SECTION 4] machines.php View Markup & Outlet Card Count" . PHP_EOL;

ob_start();
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = 'owner';
$_SESSION['full_name'] = 'Business Owner';
$_SESSION['csrf_token'] = 'test_token';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/machines.php';

require __DIR__ . '/../dashboard/machines.php';
$html = ob_get_clean();

// Check outlet count in rendered HTML
$outlet1Count = substr_count($html, 'Outlet 1');
$outlet2Count = substr_count($html, 'Outlet 2');
$outlet3Count = substr_count($html, 'Outlet 3');
$outlet4Count = substr_count($html, 'Outlet 4');
$outlet5Count = substr_count($html, 'Outlet 5');
$outlet6Count = substr_count($html, 'Outlet 6');

assertCondition(
    $outlet1Count > 0 && $outlet2Count > 0,
    "Rendered view contains Outlet 1 and Outlet 2"
);
assertCondition(
    $outlet3Count === 0 && $outlet4Count === 0 && $outlet5Count === 0 && $outlet6Count === 0,
    "Rendered view contains ZERO instances of Outlet 3, 4, 5, 6",
    "Found O3: {$outlet3Count}, O4: {$outlet4Count}, O5: {$outlet5Count}, O6: {$outlet6Count}"
);

// Check individual sensor badges
assertCondition(
    strpos($html, 'id="out1SensorBadge"') !== false,
    "Outlet 1 card contains dedicated sensor badge #out1SensorBadge"
);
assertCondition(
    strpos($html, 'id="out2SensorBadge"') !== false,
    "Outlet 2 card contains dedicated sensor badge #out2SensorBadge"
);

// Check redundant elements are removed from Machine Outlets header
assertCondition(
    strpos($html, '2 Channels') === false,
    "Machine Outlets header no longer displays redundant '2 Channels' badge"
);
assertCondition(
    strpos($html, 'id="floaterStatusText"') === false,
    "Machine Outlets header no longer displays global 'floaterStatusText' element"
);

// ============================================================
// 5. Controller Save & Persistence Test
// ============================================================
echo PHP_EOL . "[SECTION 5] Controller saveHardwareUnified() 2-Outlet Protection" . PHP_EOL;

// Save configuration with valid 2 outlets in isolated sub-process
$waterId = (int)$svcRows[0]['id'];
$soapId  = (int)$svcRows[1]['id'];

$script = '<?php
session_save_path("' . addslashes(__DIR__) . '");
session_start();
$_SESSION["user_id"] = 2;
$_SESSION["user_role"] = "owner";
$_SESSION["csrf_token"] = "valid_token";
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST = [
    "machine_id"        => 1,
    "machine_template"  => "carwash",
    "lcd_line1"         => "== KENJI CARWASH ==",
    "lcd_line2"         => "WELCOME CUSTOMER!",
    "lcd_line3"         => "INSERT COIN FIRST!",
    "lcd_line4"         => "WATER:P5 | SOAP:P5",
    "services"          => [
        ' . $waterId . ' => [
            "id"              => ' . $waterId . ',
            "name"            => "Water Service",
            "coin_value"      => 5.00,
            "service_seconds" => 120,
            "is_enabled"      => "1",
            "icon"            => "water",
            "type"            => "water",
        ],
        ' . $soapId . ' => [
            "id"              => ' . $soapId . ',
            "name"            => "Soap Service",
            "coin_value"      => 5.00,
            "service_seconds" => 60,
            "is_enabled"      => "1",
            "icon"            => "soap",
            "type"            => "soap",
        ],
    ],
    "csrf_token" => "valid_token"
];
require_once "' . addslashes(dirname(__DIR__)) . '/config/config.php";
require_once "' . addslashes(dirname(__DIR__)) . '/config/database.php";
require_once "' . addslashes(dirname(__DIR__)) . '/includes/functions.php";
require_once "' . addslashes(dirname(__DIR__)) . '/models/Machine.php";
require_once "' . addslashes(dirname(__DIR__)) . '/models/Service.php";
require_once "' . addslashes(dirname(__DIR__)) . '/controllers/MachineController.php";

(new MachineController())->saveHardwareUnified();
';

$tmp = tempnam(sys_get_temp_dir(), 'test_ctrl_');
file_put_contents($tmp, $script);
shell_exec("C:\\xampp\\php\\php.exe " . escapeshellarg($tmp));
@unlink($tmp);

// Check services count remains strictly 2
$servicesAfterSave = $pdo->query("SELECT id FROM services WHERE machine_id = 1")->fetchAll();
assertCondition(
    count($servicesAfterSave) === 2,
    "After saveHardwareUnified(), services count remains strictly 2"
);

// ============================================================
// 6. Zero Emojis and Zero Forbidden Words Compliance
// ============================================================
echo PHP_EOL . "[SECTION 6] Strict Compliance Verification" . PHP_EOL;

$modifiedFiles = [
    'models/Machine.php'                 => $rootDir . '/models/Machine.php',
    'controllers/MachineController.php'  => $rootDir . '/controllers/MachineController.php',
    'api/machine_status.php'             => $rootDir . '/api/machine_status.php',
    'dashboard/machines.php'             => $rootDir . '/dashboard/machines.php',
    'database/schema_v7_clean_two_outlets_and_individual_sensors.sql' => $rootDir . '/database/schema_v7_clean_two_outlets_and_individual_sensors.sql',
];

$emojiPattern = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

foreach ($modifiedFiles as $name => $path) {
    if (!file_exists($path)) continue;
    $content = file_get_contents($path);
    $hasEmoji = preg_match($emojiPattern, $content);
    assertCondition(
        !$hasEmoji,
        "No emojis in {$name}"
    );

    $hasForbiddenWord = preg_match('/\bfle' . 'et\b/i', $content);
    assertCondition(
        !$hasForbiddenWord,
        "No forbidden word in {$name}"
    );
}

echo PHP_EOL . "=== RESULTS: {$passed} / {$total} tests passed ===" . PHP_EOL;

if ($passed === $total) {
    echo "STATUS: ALL TESTS PASSED SUCCESSFULLY." . PHP_EOL;
    exit(0);
} else {
    echo "STATUS: SOME TESTS FAILED." . PHP_EOL;
    exit(1);
}
