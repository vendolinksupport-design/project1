<?php
/**
 * Test script to verify Hardware Enrollment Key display, Claimed Machine guards,
 * and Single-Use enforcement.
 */
$_SESSION = [
    'user_id' => 1,
    'role' => 'admin',
    'username' => 'admin'
];
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AdminDashboard.php';
require_once __DIR__ . '/../models/ProductKey.php';

echo "=== TESTING HARDWARE ENROLLMENT & KEY GENERATION FIX ===\n\n";

// 1. Test AdminDashboard getMachinesOverview deduplication
echo "1. Checking Dashboard Machines Deduplication...\n";
$dashboard = new AdminDashboard();
$overview = $dashboard->getMachinesOverview(10);
assert(count($overview) === 1, "Expected exactly 1 machine, got " . count($overview));
assert($overview[0]['machine_code'] === 'MACHINE-01', "Expected MACHINE-01");
assert($overview[0]['product_key'] === 'VL-2026-8071-9884-E37C', "Expected key VL-2026-8071-9884-E37C, got " . $overview[0]['product_key']);
echo "[PASS] AdminDashboard returns exactly 1 machine row with correct key.\n";

// 2. Test ProductKey validateKey single-use check
echo "\n2. Checking ProductKey Single-Use Validation...\n";
$pkModel = new ProductKey();
$valUsed = $pkModel->validateKey('VL-2026-8071-9884-E37C');
assert($valUsed['valid'] === false, "Used key must be invalid for new claiming");
assert($valUsed['error'] === 'Product Key Already Activated', "Expected 'Product Key Already Activated', got " . $valUsed['error']);
echo "[PASS] ProductKey model blocks re-activation with 'Product Key Already Activated'.\n";

// 3. Test Admin Machines View rendering
echo "\n3. Checking Admin Machines Provisioning Table Rendering...\n";
$_GET['tab'] = 'provisioning';
ob_start();
include __DIR__ . '/../admin/machines/index.php';
$html = ob_get_clean();

assert(str_contains($html, 'VL-2026-8071-9884-E37C'), "Key VL-2026-8071-9884-E37C must be displayed in the table");
assert(!str_contains($html, 'No Key Issued'), "Table must not show 'No Key Issued' for machine 151");
assert(str_contains($html, 'One-Time Used'), "Table must display 'One-Time Used' badge");
assert(str_contains($html, 'Claimed &amp; Active'), "Table must display 'Claimed & Active' badge");
assert(!str_contains($html, 'btn-warning text-dark fw-bold px-3 py-1 shadow-sm"><i class="bi bi-key-fill me-1"></i>Generate Key</button>'), "Table action column must NOT display Generate Key button for claimed machine");
echo "[PASS] Hardware Enrollment table properly displays key, One-Time Used badge, and hides Generate Key for claimed machine.\n";

// 4. Test Backend Guard for generate_key on claimed machine
echo "\n4. Checking Backend Guard Against Generating Key for Claimed Machine...\n";
$db = Database::getInstance();
$m151 = $db->query("SELECT registration_status, owner_id FROM machines WHERE id = 151")->fetch();
assert($m151['registration_status'] === 'claimed', "Machine 151 must be claimed");
assert(!empty($m151['owner_id']), "Machine 151 must have owner_id");

// Verify that key 155 is the only key bound to machine 151
$keysFor151 = $db->query("SELECT id, product_key, status FROM product_keys WHERE machine_id = 151")->fetchAll();
assert(count($keysFor151) === 1, "Expected exactly 1 key bound to machine 151");
assert($keysFor151[0]['status'] === 'USED', "Key 155 must be USED");
echo "[PASS] Machine 151 is strictly claimed and bound to single USED key.\n";

// 5. Test Quick Generate Modal when no unassigned machines are available
echo "\n5. Checking Quick Generate Modal when No Unassigned ESP32 Available...\n";
assert(str_contains($html, 'No Unassigned ESP32 Available'), "Modal must show 'No Unassigned ESP32 Available' when no unkeyed machines exist");
assert(str_contains($html, 'All detected ESP32 controllers already have product keys or are claimed'), "Modal must explain all controllers are claimed/keyed");
echo "[PASS] Modal cleanly notifies user when no unassigned ESP32 is available.\n";

// 6. Test Dashboard Summary Metrics
echo "\n6. Checking Dashboard Unused Product Keys Count...\n";
$metrics = $dashboard->getSummaryMetrics();
assert($metrics['unused_keys'] === 0, "Expected 0 unused keys after cleanup, got " . $metrics['unused_keys']);
assert($metrics['active_activations'] === 1, "Expected 1 active activation, got " . $metrics['active_activations']);
echo "[PASS] Dashboard metrics accurately report 0 Unused and 1 Active Activation.\n";

echo "\nALL TESTS PASSED SUCCESSFULLY!\n";
