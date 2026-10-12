<?php
/**
 * ============================================================
 * VendoLink — Multi-Tenant Owner Isolation Test Suite
 * ============================================================
 *
 * Verifies that:
 * 1. Owner A registers with Product Key A -> Machine A -> sees ONLY Machine A data.
 * 2. Owner B registers with Product Key B -> Machine B -> sees ONLY Machine B data.
 * 3. Internal Admin remains unrestricted and views all machines and aggregate data.
 * 4. Isolation spans: Machines, Transactions, Reports, Maintenance, AI Grounded Context, and Archive.
 *
 * Run with: php tests/multi_tenant_isolation_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/ProductKey.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Maintenance.php';
require_once __DIR__ . '/../models/Archive.php';
require_once __DIR__ . '/../models/AiAssistant.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

echo "====================================================\n";
echo " VendoLink Multi-Tenant Isolation Test Suite\n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assert_test(bool $condition, string $testName, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$testName}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$testName}" . ($details ? " -- {$details}" : '') . "\n";
    }
}

// -------------------------------------------------------------
// STEP 1: Setup clean test data for Owner A and Owner B
// -------------------------------------------------------------
echo "[1/6] Setting up test accounts and provisioned hardware...\n";

// Clean up old test data if exists
$pdo->exec("DELETE FROM users WHERE username IN ('test_owner_a', 'test_owner_b')");
$pdo->exec("DELETE FROM product_keys WHERE hardware_serial IN ('HW-TEST-AAA1', 'HW-TEST-BBB2')");
$pdo->exec("DELETE FROM machines WHERE machine_code IN ('HW-TEST-AAA1', 'HW-TEST-BBB2')");

// Create Product Key A & B
$productKeyModel = new ProductKey();
$keyA = 'VL-TEST-AAA1-2026';
$keyB = 'VL-TEST-BBB2-2026';

$db->query("INSERT INTO product_keys (product_key, hardware_serial, status) VALUES (:k, :h, 'UNUSED')", [':k' => $keyA, ':h' => 'HW-TEST-AAA1']);
$db->query("INSERT INTO product_keys (product_key, hardware_serial, status) VALUES (:k, :h, 'UNUSED')", [':k' => $keyB, ':h' => 'HW-TEST-BBB2']);

// Create Owner A & B accounts
$hash = password_hash('Secret123!', PASSWORD_DEFAULT);
$db->query("INSERT INTO users (username, email, full_name, password, role, status) VALUES ('test_owner_a', 'owner_a@test.com', 'Owner Alice', :h, 'owner', 'active')", [':h' => $hash]);
$ownerAId = (int)$pdo->lastInsertId();

$db->query("INSERT INTO users (username, email, full_name, password, role, status) VALUES ('test_owner_b', 'owner_b@test.com', 'Owner Bob', :h, 'owner', 'active')", [':h' => $hash]);
$ownerBId = (int)$pdo->lastInsertId();

// Activate Product Key A for Owner A and Key B for Owner B
$keyObjA = $productKeyModel->findByKey($keyA);
$keyObjB = $productKeyModel->findByKey($keyB);

$productKeyModel->activateKey((int)$keyObjA['id'], $ownerAId);
$productKeyModel->activateKey((int)$keyObjB['id'], $ownerBId);

// Verify machine provisioning
$machineModel = new Machine();
$machineA = $machineModel->primaryWithServices($ownerAId);
$machineB = $machineModel->primaryWithServices($ownerBId);

assert_test(!empty($machineA) && $machineA['machine_code'] === 'HW-TEST-AAA1', "Owner A linked to Machine A (HW-TEST-AAA1)");
assert_test(!empty($machineB) && $machineB['machine_code'] === 'HW-TEST-BBB2', "Owner B linked to Machine B (HW-TEST-BBB2)");
assert_test($machineA['id'] !== $machineB['id'], "Machine A and Machine B have distinct database IDs");

// Find service IDs for Machine A and Machine B
$svcWaterA = (int)$db->query("SELECT id FROM services WHERE machine_id = :mid AND service_key = 'water'", [':mid' => $machineA['id']])->fetchColumn();
$svcSoapA  = (int)$db->query("SELECT id FROM services WHERE machine_id = :mid AND service_key = 'soap'", [':mid' => $machineA['id']])->fetchColumn();

$svcWaterB = (int)$db->query("SELECT id FROM services WHERE machine_id = :mid AND service_key = 'water'", [':mid' => $machineB['id']])->fetchColumn();
$svcSoapB  = (int)$db->query("SELECT id FROM services WHERE machine_id = :mid AND service_key = 'soap'", [':mid' => $machineB['id']])->fetchColumn();

// -------------------------------------------------------------
// STEP 2: Record transactions for Machine A and Machine B
// -------------------------------------------------------------
echo "\n[2/6] Seeding transactions for Machine A (₱45) and Machine B (₱15)...\n";

$txModel = new Transaction();
// Owner A transactions: 3 x ₱10 water, 3 x ₱5 soap = ₱45 total
$txModel->record($svcWaterA, 10.00, 'completed');
$txModel->record($svcWaterA, 10.00, 'completed');
$txModel->record($svcWaterA, 10.00, 'completed');
$txModel->record($svcSoapA, 5.00, 'completed');
$txModel->record($svcSoapA, 5.00, 'completed');
$txModel->record($svcSoapA, 5.00, 'completed');

// Owner B transactions: 1 x ₱10 water, 1 x ₱5 soap = ₱15 total
$txModel->record($svcWaterB, 10.00, 'completed');
$txModel->record($svcSoapB, 5.00, 'completed');

// -------------------------------------------------------------
// STEP 3: Verify Owner A Data Isolation
// -------------------------------------------------------------
echo "\n[3/6] Testing Owner A isolated view...\n";

// Simulate Session for Owner A
$_SESSION['user_id'] = $ownerAId;
$_SESSION['user_role'] = 'owner';
$_SESSION['owner_id'] = $ownerAId;

assert_test(current_owner_id() === $ownerAId, "current_owner_id() resolves Owner A ID ({$ownerAId})");

$statsA = $txModel->getSummaryStats('today', $ownerAId);
assert_test((float)$statsA['today_sales'] === 45.00, "Owner A summary sales is exactly ₱45.00 (got ₱{$statsA['today_sales']})");
assert_test((int)$statsA['today_count'] === 6, "Owner A transaction count is exactly 6 (got {$statsA['today_count']})");
assert_test((float)$statsA['water_sales'] === 30.00, "Owner A water sales is exactly ₱30.00 (got ₱{$statsA['water_sales']})");
assert_test((float)$statsA['soap_sales'] === 15.00, "Owner A soap sales is exactly ₱15.00 (got ₱{$statsA['soap_sales']})");

$paginatedA = $txModel->getPaginated([], 1, 50, $ownerAId);
assert_test($paginatedA['total_items'] === 6, "Owner A paginated transactions contains exactly 6 items");
$allBelongToA = true;
foreach ($paginatedA['items'] as $it) {
    if ((int)$it['machine_id'] !== (int)$machineA['id']) {
        $allBelongToA = false;
    }
}
assert_test($allBelongToA, "All Owner A transactions belong strictly to Machine A (no cross-contamination)");

$reportModel = new Report();
$today = date('Y-m-d');
$reportA = $reportModel->getSummaryMetrics($today, $today, 'all', $ownerAId);
assert_test((float)$reportA['total_revenue'] === 45.00, "Owner A report total revenue is ₱45.00 (got ₱{$reportA['total_revenue']})");

$aiAssistant = new AiAssistant();
$aiContextA = $aiAssistant->getGroundedContext($ownerAId);
assert_test((float)$aiContextA['sales']['today_revenue'] === 45.00, "Owner A Grounded AI sees ₱45.00 today revenue (got ₱{$aiContextA['sales']['today_revenue']})");
assert_test($aiContextA['machine']['code'] === 'HW-TEST-AAA1', "Owner A Grounded AI identifies Machine A (HW-TEST-AAA1)");

// -------------------------------------------------------------
// STEP 4: Verify Owner B Data Isolation
// -------------------------------------------------------------
echo "\n[4/6] Testing Owner B isolated view...\n";

// Simulate Session for Owner B
$_SESSION['user_id'] = $ownerBId;
$_SESSION['user_role'] = 'owner';
$_SESSION['owner_id'] = $ownerBId;

assert_test(current_owner_id() === $ownerBId, "current_owner_id() resolves Owner B ID ({$ownerBId})");

$statsB = $txModel->getSummaryStats('today', $ownerBId);
assert_test((float)$statsB['today_sales'] === 15.00, "Owner B summary sales is exactly ₱15.00 (got ₱{$statsB['today_sales']})");
assert_test((int)$statsB['today_count'] === 2, "Owner B transaction count is exactly 2 (got {$statsB['today_count']})");
assert_test((float)$statsB['water_sales'] === 10.00, "Owner B water sales is exactly ₱10.00 (got ₱{$statsB['water_sales']})");
assert_test((float)$statsB['soap_sales'] === 5.00, "Owner B soap sales is exactly ₱5.00 (got ₱{$statsB['soap_sales']})");

$paginatedB = $txModel->getPaginated([], 1, 50, $ownerBId);
assert_test($paginatedB['total_items'] === 2, "Owner B paginated transactions contains exactly 2 items");
$allBelongToB = true;
foreach ($paginatedB['items'] as $it) {
    if ((int)$it['machine_id'] !== (int)$machineB['id']) {
        $allBelongToB = false;
    }
}
assert_test($allBelongToB, "All Owner B transactions belong strictly to Machine B (no cross-contamination)");

$reportB = $reportModel->getSummaryMetrics($today, $today, 'all', $ownerBId);
assert_test((float)$reportB['total_revenue'] === 15.00, "Owner B report total revenue is ₱15.00 (got ₱{$reportB['total_revenue']})");

$aiContextB = $aiAssistant->getGroundedContext($ownerBId);
assert_test((float)$aiContextB['sales']['today_revenue'] === 15.00, "Owner B Grounded AI sees ₱15.00 today revenue (got ₱{$aiContextB['sales']['today_revenue']})");
assert_test($aiContextB['machine']['code'] === 'HW-TEST-BBB2', "Owner B Grounded AI identifies Machine B (HW-TEST-BBB2)");

// -------------------------------------------------------------
// STEP 5: Verify Maintenance & Archive Isolation
// -------------------------------------------------------------
echo "\n[5/6] Testing Maintenance & Archive isolation...\n";

$maintenanceModel = new Maintenance();
// Add reminder for Machine A
$maintenanceModel->add([
    'name' => 'Pump Filter Check A',
    'type' => 'calendar',
    'service_id' => $svcWaterA,
    'enabled' => 1,
    'last_completed' => date('Y-m-d 00:00:00'),
    'calendar_value' => 30,
    'calendar_unit' => 'days',
    'runtime_interval' => null,
], $ownerAId);

// Add reminder for Machine B
$maintenanceModel->add([
    'name' => 'Soap Nozzle Clean B',
    'type' => 'calendar',
    'service_id' => $svcSoapB,
    'enabled' => 1,
    'last_completed' => date('Y-m-d 00:00:00'),
    'calendar_value' => 14,
    'calendar_unit' => 'days',
    'runtime_interval' => null,
], $ownerBId);

$itemsA = $maintenanceModel->items($ownerAId);
$itemsB = $maintenanceModel->items($ownerBId);

assert_test(count($itemsA) === 1 && $itemsA[0]['name'] === 'Pump Filter Check A', "Owner A sees only Pump Filter Check A");
assert_test(count($itemsB) === 1 && $itemsB[0]['name'] === 'Soap Nozzle Clean B', "Owner B sees only Soap Nozzle Clean B");

$archiveModel = new Archive();
$archiveTxA = $archiveModel->getTransactionHistory([], 1, 50, $ownerAId);
$archiveTxB = $archiveModel->getTransactionHistory([], 1, 50, $ownerBId);

assert_test($archiveTxA['total_items'] === 6, "Archive for Owner A returns 6 transactions");
assert_test($archiveTxB['total_items'] === 2, "Archive for Owner B returns 2 transactions");

// -------------------------------------------------------------
// STEP 6: Verify Admin Global Access
// -------------------------------------------------------------
echo "\n[6/6] Testing Unrestricted Admin Global view...\n";

// Simulate Session for Admin
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['owner_id'] = null;

assert_test(current_owner_id() === null, "current_owner_id() returns null for Admin");

$adminMachines = $machineModel->getAll(null);
assert_test(count($adminMachines) >= 2, "Admin sees all machines (" . count($adminMachines) . " machines found)");

$adminPaginated = $txModel->getPaginated([], 1, 100, null);
assert_test($adminPaginated['total_items'] >= 8, "Admin sees transactions from all owners (total: {$adminPaginated['total_items']})");

// Clean up test data
$pdo->exec("DELETE FROM service_transactions WHERE machine_id IN ({$machineA['id']}, {$machineB['id']})");
$pdo->exec("DELETE FROM maintenance_items WHERE machine_id IN ({$machineA['id']}, {$machineB['id']})");
$pdo->exec("DELETE FROM services WHERE machine_id IN ({$machineA['id']}, {$machineB['id']})");
$pdo->exec("DELETE FROM machines WHERE id IN ({$machineA['id']}, {$machineB['id']})");
$pdo->exec("DELETE FROM product_keys WHERE hardware_serial IN ('HW-TEST-AAA1', 'HW-TEST-BBB2')");
$pdo->exec("DELETE FROM users WHERE id IN ({$ownerAId}, {$ownerBId})");

echo "\n====================================================\n";
echo " Test Results: {$passCount} Passed, {$failCount} Failed\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
}
