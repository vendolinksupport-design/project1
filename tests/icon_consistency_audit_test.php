<?php
/**
 * ============================================================
 * VendoLink — Icon Consistency Audit & Verification Test Suite
 * ============================================================
 *
 * Comprehensive test verifying:
 * 1. lucide_icon() engine, SVG dictionary, and alias resolution
 * 2. Shared sidebar navigation (views/layouts/sidebar.php) Lucide standardization
 * 3. Shared top navigation (views/layouts/topnav.php) Lucide standardization
 * 4. Representative page rendering and icon integrity:
 *    - Machines (dashboard/machines.php)
 *    - Alerts (dashboard/alerts.php)
 *    - Sales Overview (dashboard/sales.php)
 *    - Cash Collections (dashboard/cash_collections.php)
 *    - History (dashboard/archive.php)
 *    - Settings (settings/index.php)
 *    - Profile (users/profile.php)
 *    - Change Password (users/change_password.php)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

function assert_test(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  [PASS] {$message}\n";
}

echo "===================================================================\n";
echo " VENDOLINK — ICON CONSISTENCY AUDIT & VERIFICATION TEST SUITE \n";
echo "===================================================================\n\n";

// Set up authenticated test session as Business Owner
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = ROLE_OWNER;
$_SESSION['role'] = ROLE_OWNER;
$_SESSION['username'] = 'owner';
$_SESSION['full_name'] = 'Business Owner';
$_SESSION['email'] = 'owner@vendolink.test';
$_SESSION['last_activity'] = time();

// -----------------------------------------------------------------
// 1. TEST CORE LUCIDE ICON ENGINE & ALIAS RESOLUTION
// -----------------------------------------------------------------
echo "--- 1. Testing Core Lucide Icon Engine & Alias Resolution ---\n";

$testIcons = [
    'gauge',
    'layout-dashboard',
    'server',
    'hard-drive',
    'bell',
    'bell-ring',
    'wrench',
    'trending-up',
    'dollar-sign',
    'wallet',
    'history',
    'users',
    'user',
    'settings',
    'log-out',
    'rotate-cw',
    'rotate-ccw',
    'refresh-cw',
    'droplets',
    'droplet',
    'sparkles',
    'check-circle',
    'check-circle-2',
    'calendar',
    'clock',
    'receipt',
    'layers',
    'search',
    'filter',
    'download',
    'file-spreadsheet',
    'plus-circle',
    'cpu',
    'x',
    'chevron-down',
];

foreach ($testIcons as $icon) {
    $html = lucide_icon($icon);
    assert_test(strpos($html, '<svg ') !== false, "Icon '{$icon}' renders as valid SVG");
    assert_test(strpos($html, "data-lucide=\"{$icon}\"") !== false, "Icon '{$icon}' has data-lucide=\"{$icon}\"");
    assert_test(strpos($html, "class=\"lucide lucide-{$icon}") !== false, "Icon '{$icon}' has proper class");
    assert_test(strpos($html, '</svg>') !== false, "Icon '{$icon}' has closed SVG tag");
}

// Test legacy aliases resolve cleanly to Lucide SVGs
$aliasTests = [
    'bi-speedometer2'             => 'gauge',
    'bi-hdd-stack'                => 'server',
    'bi-bell-fill'                => 'bell',
    'bi-wrench-adjustable-circle' => 'wrench',
    'bi-people'                   => 'users',
    'bi-gear'                     => 'settings',
    'bi-box-arrow-right'          => 'log-out',
    'bi-arrow-clockwise'          => 'rotate-cw',
    'bi-arrow-counterclockwise'   => 'rotate-ccw',
    'bi-droplets-fill'            => 'droplets',
    'bi-stars'                    => 'sparkles',
    'bi-clock-history'            => 'clock',
    'bi-check2-circle'            => 'check-circle',
];

foreach ($aliasTests as $legacyName => $expectedCanonical) {
    $html = lucide_icon($legacyName);
    assert_test(strpos($html, '<svg ') !== false, "Legacy alias '{$legacyName}' renders as SVG");
    assert_test(strpos($html, "data-lucide=\"{$expectedCanonical}\"") !== false, "Legacy alias '{$legacyName}' resolved to canonical '{$expectedCanonical}'");
}

echo "  [PASS] Lucide icon engine and alias resolution verified.\n\n";

// -----------------------------------------------------------------
// 2. TEST SHARED SIDEBAR NAVIGATION (views/layouts/sidebar.php)
// -----------------------------------------------------------------
echo "--- 2. Testing Shared Sidebar Navigation (views/layouts/sidebar.php) ---\n";

$sidebarFile = file_get_contents(ROOT_PATH . '/views/layouts/sidebar.php');
assert_test(strpos($sidebarFile, 'lucide_icon($icon, \'sidebar-lucide-icon\')') !== false, "nav_item uses lucide_icon() for navigation items");
assert_test(strpos($sidebarFile, 'lucide_icon(\'cpu\', \'sidebar-logo-lucide-icon\')') !== false, "Sidebar logo uses Lucide cpu icon");
assert_test(strpos($sidebarFile, 'lucide_icon(\'x\')') !== false, "Sidebar mobile close uses Lucide x icon");

// Render sidebar for owner
ob_start();
$currentPage = 'machines';
require ROOT_PATH . '/views/layouts/sidebar.php';
$sidebarHtml = ob_get_clean();

assert_test(strpos($sidebarHtml, 'data-lucide="gauge"') !== false, "Sidebar contains Lucide Dashboard gauge icon");
assert_test(strpos($sidebarHtml, 'data-lucide="server"') !== false, "Sidebar contains Lucide Machines server icon");
assert_test(strpos($sidebarHtml, 'data-lucide="bell"') !== false, "Sidebar contains Lucide Alerts bell icon");
assert_test(strpos($sidebarHtml, 'data-lucide="wrench"') !== false, "Sidebar contains Lucide Service Reminders wrench icon");
assert_test(strpos($sidebarHtml, 'data-lucide="trending-up"') !== false, "Sidebar contains Lucide Sales Overview trending-up icon");
assert_test(strpos($sidebarHtml, 'data-lucide="dollar-sign"') !== false, "Sidebar contains Lucide Sales dollar-sign icon");
assert_test(strpos($sidebarHtml, 'data-lucide="wallet"') !== false, "Sidebar contains Lucide Cash Collections wallet icon");
assert_test(strpos($sidebarHtml, 'data-lucide="history"') !== false, "Sidebar contains Lucide History history icon");
assert_test(strpos($sidebarHtml, 'data-lucide="settings"') !== false, "Sidebar contains Lucide Settings settings icon");

// Confirm NO unrendered empty <i> elements in sidebar navigation
assert_test(strpos($sidebarHtml, '<span class="nav-icon"><i class="bi') === false, "No legacy unrendered bi icons in nav-icon spans");

echo "  [PASS] Shared sidebar navigation Lucide standardization verified.\n\n";

// -----------------------------------------------------------------
// 3. TEST SHARED TOP NAVIGATION (views/layouts/topnav.php)
// -----------------------------------------------------------------
echo "--- 3. Testing Shared Top Navigation (views/layouts/topnav.php) ---\n";

ob_start();
$currentPage = 'machines';
require ROOT_PATH . '/views/layouts/topnav.php';
$topnavHtml = ob_get_clean();

assert_test(strpos($topnavHtml, 'data-lucide="rotate-cw"') !== false, "Topnav refresh uses Lucide rotate-cw icon");
assert_test(strpos($topnavHtml, 'data-lucide="bell"') !== false, "Topnav notifications button uses Lucide bell icon");
assert_test(strpos($topnavHtml, 'data-lucide="chevron-down"') !== false, "Topnav profile chevron uses Lucide chevron-down icon");
assert_test(strpos($topnavHtml, 'data-lucide="user"') !== false, "Topnav My Profile uses Lucide user icon");
assert_test(strpos($topnavHtml, 'data-lucide="log-out"') !== false, "Topnav Sign Out uses Lucide log-out icon");

echo "  [PASS] Shared top navigation Lucide standardization verified.\n\n";

// -----------------------------------------------------------------
// 4. TEST REPRESENTATIVE PAGES RENDERING & ASSET LINKS
// -----------------------------------------------------------------
echo "--- 4. Testing Representative Pages Rendering & Asset Links ---\n";

$pagesToTest = [
    'Machines'         => 'dashboard/machines.php',
    'Alerts'           => 'dashboard/alerts.php',
    'Sales Overview'   => 'dashboard/sales.php',
    'Cash Collections' => 'dashboard/cash_collections.php',
    'History'          => 'dashboard/archive.php',
    'Settings'         => 'settings/index.php',
    'Profile'          => 'users/profile.php',
    'Change Password'  => 'users/change_password.php',
];

$phpCli = 'C:\\xampp\\php\\php.exe';

foreach ($pagesToTest as $pageName => $relPath) {
    echo "  Testing {$pageName} ({$relPath})...\n";
    $testCmd = "{$phpCli} tests/render_runner.php {$relPath} owner";
    $output = shell_exec($testCmd);
    assert_test(!empty($output) && strpos($output, 'HTML_LEN:') !== false, "{$pageName} rendered successfully");
    assert_test(strpos($output, '|HAS_BI') !== false, "{$pageName} has bootstrap-icons stylesheet linked");
    assert_test(strpos($output, '|HAS_LUCIDE') !== false, "{$pageName} contains Lucide icons");
    assert_test(strpos($output, '|HAS_APPCSS') !== false, "{$pageName} links app.css");
    assert_test(strpos($output, '|HAS_SIDEBAR') !== false, "{$pageName} contains sidebar");
    assert_test(strpos($output, '|HAS_TOPNAV') !== false, "{$pageName} contains topnav");
}

echo "\n===================================================================\n";
echo "🎉 ALL ICON CONSISTENCY TESTS PASSED 100% SUCCESSFULLY!\n";
echo "===================================================================\n";
