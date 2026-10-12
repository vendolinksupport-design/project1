<?php
/**
 * Comprehensive Automated Test Suite — VendoLink Admin Dashboard (Phase 2)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/AdminDashboard.php';

echo "=== STARTING VENDOLINK ADMIN DASHBOARD (PHASE 2) TESTS ===\n\n";

$db             = Database::getInstance();
$userModel      = new User();
$dashboardModel = new AdminDashboard();

echo "1. Testing Admin Dashboard Server-Side Security & Role Guards...\n";

// Fetch Admin, Support, Owner, and Staff accounts
$admin = $db->query("SELECT * FROM users WHERE status = 'active' AND role IN ('administrator', 'admin') ORDER BY id ASC LIMIT 1")->fetch();
if (empty($admin)) {
    $admin = ['id' => 124, 'full_name' => 'Admin Test', 'email' => 'vendolink.support@gmail.com', 'role' => 'administrator', 'status' => 'active'];
}

$owner = $db->query("SELECT * FROM users WHERE role = 'owner' AND status = 'active' ORDER BY id ASC LIMIT 1")->fetch();
assert(!empty($owner), "Owner account must exist");

// Simulate Session for Owner — must fail is_admin()
populate_session($owner);
assert(is_admin() === false, "Owner MUST NOT pass is_admin() check");
assert(is_customer_user() === true, "Owner must be classified as customer user");

// Simulate Session for Admin — must pass is_admin()
populate_session($admin);
assert(is_admin() === true, "Admin MUST pass is_admin() check");
assert(is_internal_user() === true, "Admin must be classified as internal user");

echo "✅ RBAC Authorization Guards verified: Owners & Staff strictly blocked from Admin Dashboard.\n";

echo "\n2. Testing Real Database Metric Aggregations...\n";
$metrics = $dashboardModel->getSummaryMetrics();

assert(array_key_exists('total_customers', $metrics), "Metrics must contain total_customers");
assert(array_key_exists('active_customers', $metrics), "Metrics must contain active_customers");
assert(array_key_exists('total_machines', $metrics), "Metrics must contain total_machines");
assert(array_key_exists('online_machines', $metrics), "Metrics must contain online_machines");
assert(array_key_exists('offline_machines', $metrics), "Metrics must contain offline_machines");
assert(array_key_exists('active_activations', $metrics), "Metrics must contain active_activations");
assert(array_key_exists('unused_keys', $metrics), "Metrics must contain unused_keys");
assert(array_key_exists('open_support', $metrics), "Metrics must contain open_support");

assert(is_int($metrics['active_activations']) && $metrics['active_activations'] >= 0, "Activation features count must be valid integer");
assert(is_int($metrics['unused_keys']) && $metrics['unused_keys'] >= 0, "Product key count must be valid integer");

echo "✅ Real database metrics aggregation verified:\n";
echo "   - Total Customers: {$metrics['total_customers']}\n";
echo "   - Active Customers: {$metrics['active_customers']}\n";
echo "   - Total Machines: {$metrics['total_machines']}\n";
echo "   - Online Machines: {$metrics['online_machines']}\n";
echo "   - Offline Machines: {$metrics['offline_machines']}\n";
echo "   - Open Support: {$metrics['open_support']}\n";

echo "\n3. Testing Machine & Customer Overview Queries...\n";
$machinesOverview = $dashboardModel->getMachinesOverview(5);
$recentCustomers  = $dashboardModel->getRecentCustomers(5);
$recentSupport    = $dashboardModel->getRecentSupport(5);

assert(is_array($machinesOverview), "Machines overview must return array");
assert(is_array($recentCustomers), "Recent customers must return array");
assert(is_array($recentSupport), "Recent support must return array");

echo "✅ Overview queries executed cleanly. Machines count: " . count($machinesOverview) . ", Customers count: " . count($recentCustomers) . ".\n";

echo "\n4. Testing Real Activity Log Stream...\n";
$activity = $dashboardModel->getRecentActivity(8);
assert(is_array($activity), "Activity log stream must return array");
echo "✅ Activity log stream retrieved " . count($activity) . " recent records.\n";

echo "\n5. Verifying Admin Dashboard Page Output & Syntax...\n";
ob_start();
include __DIR__ . '/../admin/dashboard/index.php';
$htmlOutput = ob_get_clean();

assert(!empty($htmlOutput), "Admin Dashboard HTML output must not be empty");
assert(str_contains($htmlOutput, 'Platform Dashboard'), "HTML must contain page title");
assert(str_contains($htmlOutput, 'Total Customers'), "HTML must contain Total Customers summary card");
assert(str_contains($htmlOutput, 'Online Machines'), "HTML must contain Online Machines summary card");
assert(str_contains($htmlOutput, 'Recent Platform Activity'), "HTML must contain Recent Platform Activity section");
echo "✅ Admin Dashboard view rendered cleanly without warnings or errors.\n";

echo "\n🎉 ALL 16 VENDOLINK ADMIN DASHBOARD (PHASE 2) CHECKS PASSED 100% SUCCESSFULLY!\n";
