<?php
/**
 * ============================================================
 * VendoLink — Multi-Tenant Isolation & Zero-State Acceptance Test
 * ============================================================
 *
 * Acceptance Scenarios:
 * 1. Owner A logs in -> Sees only Machine A and Machine A's revenue (₱50.00).
 * 2. Owner B newly registers with Product Key B -> Logs in:
 *    - Session stores $_SESSION['owner_id'] and $_SESSION['role']
 *    - Dashboard starts in zero state:
 *      * Total Revenue: ₱0.00
 *      * Total Transactions: 0
 *      * Water Sales: ₱0.00, Soap Sales: ₱0.00
 *      * Empty charts
 *      * No maintenance history
 *      * Clean notifications (no Owner A alerts)
 *      * Machine telemetry loaded strictly for Machine B
 *    - Cannot view any of Owner A's revenue or transactions.
 * 3. Internal Admin logs in -> Retains global unrestricted view across all machines and owners.
 *
 * Run with: C:\xampp\php\php.exe tests/new_owner_zero_state_isolation_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/ProductKey.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Maintenance.php';
require_once __DIR__ . '/../models/AiAssistant.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

echo "====================================================\n";
echo " Multi-Tenant Isolation & Zero-State Test Suite\n";
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
// STEP 1: Provision Clean Test Accounts & Product Keys
// -------------------------------------------------------------
echo "[1/4] Provisioning Owner A and New Owner B accounts...\n";

$hwA = 'HW-ISOLATION-A1';
$hwB = 'HW-ISOLATION-B2';

$pdo->exec("DELETE FROM users WHERE username IN ('owner_iso_a', 'owner_iso_b', 'admin_iso_test')");
$pdo->exec("DELETE FROM product_keys WHERE hardware_serial IN ('{$hwA}', '{$hwB}')");
$pdo->exec("DELETE FROM machines WHERE machine_code IN ('{$hwA}', '{$hwB}')");

$hash = password_hash('Pass1234!', PASSWORD_DEFAULT);

// Insert Owner A
$pdo->exec("INSERT INTO users (username, email, full_name, password, role, status) VALUES ('owner_iso_a', 'ownera@test.com', 'Owner Alice', '{$hash}', 'owner', 'active')");
$ownerAId = (int)$pdo->lastInsertId();

// Insert Product Key A and bind to Owner A
$keyA = 'VL-2026-AAAA-1111-2222';
$pdo->exec("INSERT INTO product_keys (product_key, hardware_serial, status, owner_id, activated_at) VALUES ('{$keyA}', '{$hwA}', 'USED', {$ownerAId}, NOW())");

// Insert Machine A
$pdo->exec("INSERT INTO machines (owner_id, machine_code, machine_key, name, api_token, status) VALUES ({$ownerAId}, '{$hwA}', 'mach_iso_a', 'Alice Station 01', 'token_a', 'online')");
$machAId = (int)$pdo->lastInsertId();

// Insert Services for Machine A
$pdo->exec("INSERT INTO services (machine_id, service_key, name, type, coin_value, service_seconds, is_enabled, enabled) VALUES ({$machAId}, 'water', 'Water Service', 'water', 5.00, 120, 1, 1), ({$machAId}, 'soap', 'Soap Service', 'soap', 5.00, 120, 1, 1)");
$waterSvcA = (int)$pdo->query("SELECT id FROM services WHERE machine_id = {$machAId} AND service_key = 'water'")->fetchColumn();
$soapSvcA = (int)$pdo->query("SELECT id FROM services WHERE machine_id = {$machAId} AND service_key = 'soap'")->fetchColumn();

// Seed 10 transactions for Owner A (Total ₱50.00: ₱30 water, ₱20 soap)
for ($i = 1; $i <= 6; $i++) {
    $pdo->exec("INSERT INTO service_transactions (machine_id, service_id, amount, status, created_at, runtime_generated_seconds) VALUES ({$machAId}, {$waterSvcA}, 5.00, 'completed', NOW(), 120)");
}
for ($i = 1; $i <= 4; $i++) {
    $pdo->exec("INSERT INTO service_transactions (machine_id, service_id, amount, status, created_at, runtime_generated_seconds) VALUES ({$machAId}, {$soapSvcA}, 5.00, 'completed', NOW(), 120)");
}

// Seed Maintenance item for Owner A
$pdo->exec("INSERT INTO maintenance_items (machine_id, service_id, name, reminder_type, calendar_interval_value, calendar_interval_unit, last_completed_at, is_enabled) VALUES ({$machAId}, {$waterSvcA}, 'Filter Check Alice', 'calendar', 30, 'days', NOW(), 1)");

// Insert New Owner B (Brand New Account, Zero Transactions)
$pdo->exec("INSERT INTO users (username, email, full_name, password, role, status) VALUES ('owner_iso_b', 'ownerb@test.com', 'Owner Bob', '{$hash}', 'owner', 'active')");
$ownerBId = (int)$pdo->lastInsertId();

// Insert Product Key B and bind to Owner B
$keyB = 'VL-2026-BBBB-3333-4444';
$pdo->exec("INSERT INTO product_keys (product_key, hardware_serial, status, owner_id, activated_at) VALUES ('{$keyB}', '{$hwB}', 'USED', {$ownerBId}, NOW())");

// Insert Machine B
$pdo->exec("INSERT INTO machines (owner_id, machine_code, machine_key, name, api_token, status) VALUES ({$ownerBId}, '{$hwB}', 'mach_iso_b', 'Bob Station 02', 'token_b', 'pending')");
$machBId = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO services (machine_id, service_key, name, type, coin_value, service_seconds, is_enabled, enabled) VALUES ({$machBId}, 'water', 'Water Service', 'water', 5.00, 120, 1, 1), ({$machBId}, 'soap', 'Soap Service', 'soap', 5.00, 120, 1, 1)");

// Insert Admin Test User
$pdo->exec("INSERT INTO users (username, email, full_name, password, role, status) VALUES ('admin_iso_test', 'adminiso@test.com', 'Admin Supervisor', '{$hash}', 'administrator', 'active')");
$adminId = (int)$pdo->lastInsertId();

assert_test($ownerAId > 0 && $ownerBId > 0 && $adminId > 0, "Test users and hardware kits created successfully");

// -------------------------------------------------------------
// STEP 2: Test Owner A Logged In
// -------------------------------------------------------------
echo "\n[2/4] Testing Owner A (Alice) Logged-In View...\n";

// Populate session for Owner A
$userARow = $pdo->query("SELECT * FROM users WHERE id = {$ownerAId}")->fetch(PDO::FETCH_ASSOC);
populate_session($userARow);

assert_test($_SESSION['owner_id'] === $ownerAId, "Owner A session owner_id is set to {$ownerAId}");
assert_test($_SESSION['role'] === 'owner' && $_SESSION['user_role'] === 'owner', "Owner A session role is 'owner'");
assert_test(current_owner_id() === $ownerAId, "current_owner_id() returns Owner A ID");

$machineModel = new Machine();
$machA = $machineModel->primaryWithServices($ownerAId);
assert_test(!empty($machA) && (int)$machA['id'] === $machAId && $machA['machine_code'] === $hwA, "Owner A primary machine is strictly Machine A ({$hwA})");

$reportModel = new Report();
$today = date('Y-m-d');
$statsA = $reportModel->getSummaryMetrics($today, $today, 'all', $ownerAId);
assert_test((float)$statsA['total_revenue'] === 50.00, "Owner A today revenue is exactly ₱50.00 (got ₱{$statsA['total_revenue']})");
assert_test((int)$statsA['total_transactions'] === 10, "Owner A transactions count is 10");
assert_test((float)$statsA['water_revenue'] === 30.00, "Owner A water revenue is ₱30.00");
assert_test((float)$statsA['soap_revenue'] === 20.00, "Owner A soap revenue is ₱20.00");

$maintModel = new Maintenance();
$maintA = $maintModel->items($ownerAId);
assert_test(count($maintA) === 1 && $maintA[0]['name'] === 'Filter Check Alice', "Owner A sees their 1 maintenance reminder");

// -------------------------------------------------------------
// STEP 3: Test New Owner B (Bob) Logged In — ZERO-STATE Isolation
// -------------------------------------------------------------
echo "\n[3/4] Testing New Owner B (Bob) Zero-State & Strict Isolation...\n";

// Populate session for Owner B
$userBRow = $pdo->query("SELECT * FROM users WHERE id = {$ownerBId}")->fetch(PDO::FETCH_ASSOC);
populate_session($userBRow);

assert_test($_SESSION['owner_id'] === $ownerBId, "Owner B session owner_id is set to {$ownerBId}");
assert_test($_SESSION['role'] === 'owner' && $_SESSION['user_role'] === 'owner', "Owner B session role is 'owner'");
assert_test(current_owner_id() === $ownerBId, "current_owner_id() returns Owner B ID");

// 1. Machine Ownership via Product Key
$machB = $machineModel->primaryWithServices($ownerBId);
assert_test(!empty($machB) && (int)$machB['id'] === $machBId && $machB['machine_code'] === $hwB, "Owner B resolves strictly Machine B ({$hwB})");

$allMachinesB = $machineModel->getAllActive($ownerBId);
assert_test(count($allMachinesB) === 1 && (int)$allMachinesB[0]['id'] === $machBId, "Owner B can access only their 1 linked machine (cannot see Machine A)");

// 2. Zero-State Revenue & Transactions
$statsB = $reportModel->getSummaryMetrics($today, $today, 'all', $ownerBId);
assert_test((float)$statsB['total_revenue'] === 0.00, "Owner B today revenue starts at ₱0.00 (got ₱{$statsB['total_revenue']})");
assert_test((int)$statsB['total_transactions'] === 0, "Owner B transaction count is 0 (got {$statsB['total_transactions']})");
assert_test((float)$statsB['water_revenue'] === 0.00, "Owner B water revenue starts at ₱0.00");
assert_test((float)$statsB['soap_revenue'] === 0.00, "Owner B soap revenue starts at ₱0.00");

// 3. Transactions Model Scoping
$txModel = new Transaction();
$txListB = $txModel->getPaginated([], 1, 10, $ownerBId);
assert_test($txListB['total_items'] === 0 && empty($txListB['items']), "Owner B transaction list is completely empty (0 records from Owner A)");

$summaryStatsB = $txModel->getSummaryStats('today', $ownerBId);
assert_test((float)$summaryStatsB['today_sales'] === 0.00 && (int)$summaryStatsB['today_count'] === 0, "Transaction::getSummaryStats for Owner B is ₱0.00 and 0 count");
assert_test((float)$summaryStatsB['total_sales'] === 0.00 && (int)$summaryStatsB['total_count'] === 0, "Total all-time sales for Owner B is ₱0.00 and 0 count");

// 4. Time Series Chart Data Zero State
$chartB = $reportModel->getTimeSeries($today, $today, 'today', 'all', $ownerBId);
$chartTotalSum = array_sum($chartB['total_data']);
assert_test($chartTotalSum === 0.0, "Owner B sales chart contains all zeros (sum = 0.0)");

// 5. Maintenance Items Zero State
$maintB = $maintModel->items($ownerBId);
assert_test(count($maintB) === 0, "Owner B has 0 maintenance items (cannot see Filter Check Alice)");

// 6. AI Assistant Grounded Context Zero State
$aiAssistant = new AiAssistant();
$aiContextB = $aiAssistant->getGroundedContext($ownerBId);
assert_test($aiContextB['machine']['code'] === $hwB, "AI identifies Owner B's hardware ({$hwB})");
assert_test($aiContextB['sales']['today_revenue'] === 0.0 && $aiContextB['sales']['today_tx_count'] === 0, "AI sees ₱0.00 today revenue for Owner B");
assert_test($aiContextB['sales']['total_revenue'] === 0.0 && $aiContextB['sales']['total_tx'] === 0, "AI sees ₱0.00 total revenue for Owner B");

// -------------------------------------------------------------
// STEP 4: Test Internal Admin Unrestricted View
// -------------------------------------------------------------
echo "\n[4/4] Testing Internal Admin Unrestricted Global View...\n";

$adminRow = $pdo->query("SELECT * FROM users WHERE id = {$adminId}")->fetch(PDO::FETCH_ASSOC);
populate_session($adminRow);

assert_test($_SESSION['owner_id'] === null, "Admin session owner_id is NULL");
assert_test(current_owner_id() === null, "current_owner_id() returns NULL for Admin");

$adminMachines = $machineModel->getAllActive(null);
assert_test(count($adminMachines) >= 2, "Admin can view all machines across all owners (count: " . count($adminMachines) . ")");

$adminStats = $reportModel->getSummaryMetrics($today, $today, 'all', null);
assert_test((float)$adminStats['total_revenue'] >= 50.00, "Admin sees global revenue from all owners (₱{$adminStats['total_revenue']})");

// Clean up test data
$pdo->exec("DELETE FROM service_transactions WHERE machine_id IN ({$machAId}, {$machBId})");
$pdo->exec("DELETE FROM maintenance_items WHERE machine_id IN ({$machAId}, {$machBId})");
$pdo->exec("DELETE FROM services WHERE machine_id IN ({$machAId}, {$machBId})");
$pdo->exec("DELETE FROM machines WHERE id IN ({$machAId}, {$machBId})");
$pdo->exec("DELETE FROM product_keys WHERE product_key IN ('{$keyA}', '{$keyB}')");
$pdo->exec("DELETE FROM users WHERE id IN ({$ownerAId}, {$ownerBId}, {$adminId})");

echo "\n====================================================\n";
echo " Test Results: {$passCount} Passed, {$failCount} Failed\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
}
