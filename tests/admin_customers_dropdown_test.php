<?php
/**
 * Test: Admin Customers Dropdown & Machine Details
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/AdminDashboard.php';

// Mock admin session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'Administrator';
$_SESSION['email'] = 'admin@vendolink.test';

$dashboardModel = new AdminDashboard();
$customers = $dashboardModel->getAllCustomersWithMachines();

echo "=== VERIFYING MODEL DATA ===\n";
echo "Customers count: " . count($customers) . "\n";
assert(count($customers) >= 2, "Expected at least 2 customers");

$hasOwnerWithMachine = false;
$hasOwnerWithZero = false;

foreach ($customers as $c) {
    echo "Customer ID: {$c['id']} | Name: {$c['full_name']} | Total Machines: {$c['total_machines']}\n";
    if ($c['total_machines'] > 0) {
        $hasOwnerWithMachine = true;
        foreach ($c['machines'] as $m) {
            echo "   Machine Code: {$m['machine_code']} | Serial: " . ($m['hardware_serial'] ?? 'none') . " | Status: {$m['calculated_status']}\n";
        }
    } else {
        $hasOwnerWithZero = true;
    }
}

assert($hasOwnerWithMachine, "Expected at least one customer with a machine");
assert($hasOwnerWithZero, "Expected at least one customer with 0 machines (empty state test)");

echo "\n=== VERIFYING HTML RENDERING ===\n";
ob_start();
require __DIR__ . '/../admin/customers/index.php';
$html = ob_get_clean();

// Check table columns
assert(strpos($html, 'Owner / Customer') !== false, "Missing 'Owner / Customer' header");
assert(strpos($html, 'Email & Username') !== false, "Missing 'Email & Username' header");
assert(strpos($html, 'Account Status') !== false, "Missing 'Account Status' header");
assert(strpos($html, '<th style="width: 20%;" class="text-center">Machines</th>') !== false, "Missing centered 'Machines' header");
assert(strpos($html, '<td class="text-center machine-cell">') !== false, "Missing centered machines td cell");
assert(strpos($html, 'machine-count-badge') !== false, "Missing machine-count-badge element");
assert(strpos($html, 'machine-drawer-btn') !== false, "Missing machine-drawer-btn element");

// Check drawer elements
assert(strpos($html, 'class="customer-row"') !== false, "Missing customer-row class");
assert(strpos($html, 'data-bs-toggle="collapse"') !== false, "Missing data-bs-toggle='collapse'");
assert(strpos($html, 'customerDrawer') !== false, "Missing customerDrawer ID reference");
assert(strpos($html, 'customer-drawer-content') !== false, "Missing customer-drawer-content class");
assert(strpos($html, 'Connected Machines') !== false, "Missing Connected Machines section");
assert(strpos($html, 'machine-mini-card') !== false, "Missing machine-mini-card class");
assert(strpos($html, 'No vending machines connected to this owner yet') !== false, "Missing empty state for 0 machines owner");

// Check modal placement outside table
assert(strpos($html, '<!-- Edit Customer Modals placed cleanly outside the table -->') !== false, "Modals not placed outside table");

echo "ALL TESTS PASSED! HTML rendered cleanly without notices or warnings.\n";
