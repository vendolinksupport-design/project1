<?php
/**
 * Test: Unified Service Configuration Panel Verification
 * Run with: C:\xampp\php\php.exe tests/unified_service_config_test.php
 */

session_save_path(__DIR__);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Service.php';

echo "=== STARTING UNIFIED SERVICE CONFIGURATION VERIFICATION ===\n\n";

$db = Database::getInstance();
$serviceModel = new Service();

// Fetch initial service states for primary machine
$services = $db->query(
    "SELECT id, service_key, name, is_enabled, coin_value, service_seconds FROM services WHERE service_key IN ('water', 'soap') ORDER BY id ASC"
)->fetchAll();

if (count($services) < 2) {
    throw new RuntimeException("Water and soap services not found in database.");
}

$water = null;
$soap = null;
foreach ($services as $s) {
    if ($s['service_key'] === 'water') $water = $s;
    if ($s['service_key'] === 'soap') $soap = $s;
}

if (!$water || !$soap) {
    throw new RuntimeException("Missing water or soap service.");
}

$origWaterSecs = (int)$water['service_seconds'];
$origSoapSecs = (int)$soap['service_seconds'];
$origWaterEnabled = (int)$water['is_enabled'];
$origSoapEnabled = (int)$soap['is_enabled'];

echo "1. Checking Page Layout & Unified Form Structure...\n";

// Set up mock session for layout rendering in isolated output buffer
ob_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'administrator';
$_SESSION['full_name'] = 'Test Admin';
$_SESSION['csrf_token'] = 'test_token';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/dashboard/machines.php';

require __DIR__ . '/../dashboard/machines.php';
$html = ob_get_clean();

// Check unified container and header
assert_true(strpos($html, 'id="unifiedHardwareForm"') !== false || strpos($html, 'id="unifiedServiceForm"') !== false, "Unified service form present");
assert_true(strpos($html, 'Service Controller') !== false || strpos($html, 'Service Configuration') !== false, "Parent card title present");

// Check service sections
assert_true(strpos($html, 'data-service-section="water"') !== false, "Water service section box present");
assert_true(strpos($html, 'data-service-section="soap"') !== false, "Soap service section box present");
assert_true(strpos($html, 'Water Service') !== false, "Water service title present");
assert_true(strpos($html, 'Soap Service') !== false, "Soap service title present");

// Check toggles, runtime, maintenance, steppers
assert_true(strpos($html, 'name="services[' . $water['id'] . '][is_enabled]"') !== false, "Water enabled toggle input present");
assert_true(strpos($html, 'name="services[' . $soap['id'] . '][is_enabled]"') !== false, "Soap enabled toggle input present");
assert_true(strpos($html, 'name="services[' . $water['id'] . '][service_seconds]"') !== false, "Water service_seconds input present");
assert_true(strpos($html, 'name="services[' . $soap['id'] . '][service_seconds]"') !== false, "Soap service_seconds input present");

assert_true(strpos($html, 'id="unifiedMasterSaveBtn"') !== false || strpos($html, 'id="unifiedSaveBtn"') !== false, "Unified save button present");

echo "  -> Layout & markup verified successfully.\n\n";

function run_controller_post(array $postData): array {
    $script = '<?php
session_save_path("' . addslashes(__DIR__) . '");
session_start();
$_SESSION["user_id"] = 1;
$_SESSION["user_role"] = "administrator";
$_SESSION["csrf_token"] = "valid_token";
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST = ' . var_export($postData, true) . ';
$_POST["csrf_token"] = "valid_token";
require_once "' . addslashes(dirname(__DIR__)) . '/config/config.php";
require_once "' . addslashes(dirname(__DIR__)) . '/config/database.php";
require_once "' . addslashes(dirname(__DIR__)) . '/includes/functions.php";
require_once "' . addslashes(dirname(__DIR__)) . '/controllers/MachineController.php";

register_shutdown_function(function() {
    $flashes = $_SESSION["flash_messages"] ?? [];
    $flash = !empty($flashes) ? end($flashes) : null;
    echo "---FLASH_JSON---" . json_encode($flash);
});

(new MachineController())->saveServiceConfiguration();
';
    $tmp = tempnam(sys_get_temp_dir(), 'test_ctrl_');
    file_put_contents($tmp, $script);
    $out = shell_exec("C:\\xampp\\php\\php.exe " . escapeshellarg($tmp));
    @unlink($tmp);
    if ($out && preg_match('/---FLASH_JSON---(.*)$/', $out, $m)) {
        return json_decode($m[1], true) ?? [];
    }
    return [];
}

echo "2. Testing Validation: Out of Bounds Identification...\n";

// Test Water validation failure (< 10 seconds)
$flash = run_controller_post([
    'action'   => 'save_service_config',
    'services' => [
        $water['id'] => [
            'id'              => $water['id'],
            'name'            => 'Water Service',
            'coin_value'      => 5.00,
            'service_seconds' => 5, // Invalid: < 10
            'is_enabled'      => '1',
        ],
        $soap['id'] => [
            'id'              => $soap['id'],
            'name'            => 'Soap Service',
            'coin_value'      => 5.00,
            'service_seconds' => 120,
            'is_enabled'      => '1',
        ],
    ],
]);

assert_true(!empty($flash) && strpos($flash['message'] ?? '', 'Water Service:') !== false, "Validation error correctly identifies Water Service");
echo "  -> Water Service out-of-bounds detected and named in error.\n";

// Test Soap validation failure (> 600 seconds)
$flash = run_controller_post([
    'action'   => 'save_service_config',
    'services' => [
        $water['id'] => [
            'id'              => $water['id'],
            'name'            => 'Water Service',
            'coin_value'      => 5.00,
            'service_seconds' => 120,
            'is_enabled'      => '1',
        ],
        $soap['id'] => [
            'id'              => $soap['id'],
            'name'            => 'Soap Service',
            'coin_value'      => 5.00,
            'service_seconds' => 700, // Invalid: > 600
            'is_enabled'      => '1',
        ],
    ],
]);

assert_true(!empty($flash) && strpos($flash['message'] ?? '', 'Soap Service:') !== false, "Validation error correctly identifies Soap Service");
echo "  -> Soap Service out-of-bounds detected and named in error.\n\n";

echo "3. Testing Atomic Batch Save (Both Services Changed Together)...\n";

$newWaterSecs = 150;
$newSoapSecs = 210;

$flash = run_controller_post([
    'action'   => 'save_service_config',
    'services' => [
        $water['id'] => [
            'id'              => $water['id'],
            'name'            => 'Water Service',
            'coin_value'      => 5.00,
            'service_seconds' => $newWaterSecs,
            'is_enabled'      => '1',
        ],
        $soap['id'] => [
            'id'              => $soap['id'],
            'name'            => 'Soap Service',
            'coin_value'      => 5.00,
            'service_seconds' => $newSoapSecs,
            'is_enabled'      => '1',
        ],
    ],
]);

assert_true(!empty($flash) && ($flash['type'] ?? '') === 'success', "Batch save returned success flash message");

// Verify database reflects both changes
$updatedWater = $serviceModel->findById((int)$water['id']);
$updatedSoap = $serviceModel->findById((int)$soap['id']);

assert_true((int)$updatedWater['service_seconds'] === $newWaterSecs, "Water service_seconds updated in database to $newWaterSecs");
assert_true((int)$updatedSoap['service_seconds'] === $newSoapSecs, "Soap service_seconds updated in database to $newSoapSecs");
echo "  -> Both services successfully saved and persisted to database together.\n\n";

echo "4. Testing Idempotence / No Changes Detection...\n";

// Submit identical values again
$flash = run_controller_post([
    'action'   => 'save_service_config',
    'services' => [
        $water['id'] => [
            'id'              => $water['id'],
            'name'            => 'Water Service',
            'coin_value'      => 5.00,
            'service_seconds' => $newWaterSecs,
            'is_enabled'      => '1',
        ],
        $soap['id'] => [
            'id'              => $soap['id'],
            'name'            => 'Soap Service',
            'coin_value'      => 5.00,
            'service_seconds' => $newSoapSecs,
            'is_enabled'      => '1',
        ],
    ],
]);

assert_true(!empty($flash) && ($flash['type'] ?? '') === 'info' && strpos($flash['message'] ?? '', 'No configuration changes') !== false, "Identified no changes when submitting identical values");
echo "  -> No changes state correctly detected.\n\n";

echo "5. Reverting Test Values to Original State...\n";
$serviceModel->updateConfiguration((int)$water['id'], (bool)$origWaterEnabled, 5.00, $origWaterSecs, 1);
$serviceModel->updateConfiguration((int)$soap['id'], (bool)$origSoapEnabled, 5.00, $origSoapSecs, 1);

$revertedWater = $serviceModel->findById((int)$water['id']);
$revertedSoap = $serviceModel->findById((int)$soap['id']);
assert_true((int)$revertedWater['service_seconds'] === $origWaterSecs, "Water service_seconds reverted to $origWaterSecs");
assert_true((int)$revertedSoap['service_seconds'] === $origSoapSecs, "Soap service_seconds reverted to $origSoapSecs");
echo "  -> Database restored to original state cleanly.\n\n";

echo "ALL MERGED SERVICE CONFIGURATION TESTS PASSED 100% SUCCESSFULLY!\n";

function assert_true(bool $condition, string $description): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: $description");
    }
    echo "  [PASS] $description\n";
}
