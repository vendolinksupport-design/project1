<?php
/**
 * ============================================================
 * VendoLink — Notifications Terminology Update Automated Verification
 * ============================================================
 *
 * Verifies:
 * 1. Sidebar navigation displays "Notifications".
 * 2. Topnav maps alerts to "Notifications" and bell dropdown displays "View Notifications".
 * 3. Page heading and subtitle in dashboard/alerts.php use exact new terminology.
 * 4. Summary cards display TOTAL NOTIFICATIONS, CRITICAL NOTIFICATIONS, HARDWARE NOTIFICATIONS.
 * 5. Feed heading displays "Notification Feed" and updated subtitle.
 * 6. Empty state displays "There are no notifications at this time.".
 * 7. Email template CTA button displays "Open VendoLink Notifications Center".
 * 8. Strict compliance: zero emojis, zero forbidden words.
 *
 * Run with: C:\xampp\php\php.exe tests\notifications_terminology_update_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== STARTING NOTIFICATIONS TERMINOLOGY VERIFICATION ===\n\n";

// TEST 1: Terminology dictionary
echo "1. Verifying canonical terminology dictionary...\n";
if (term('alerts') !== 'Notifications') {
    throw new RuntimeException("FAIL: term('alerts') does not return 'Notifications'!");
}
if (term('notifications') !== 'Notifications') {
    throw new RuntimeException("FAIL: term('notifications') does not return 'Notifications'!");
}
echo "   [PASS] Canonical dictionary returns 'Notifications'.\n";

// TEST 2: Sidebar Navigation
echo "2. Checking sidebar navigation...\n";
$sidebarContent = file_get_contents(__DIR__ . '/../views/layouts/sidebar.php');
if (!str_contains($sidebarContent, "term('alerts', 'Notifications')")) {
    throw new RuntimeException("FAIL: sidebar.php does not use term('alerts', 'Notifications')!");
}
echo "   [PASS] Sidebar uses canonical 'Notifications' label.\n";

// TEST 3: Top Navigation
echo "3. Checking top navigation...\n";
$topnavContent = file_get_contents(__DIR__ . '/../views/layouts/topnav.php');
if (!str_contains($topnavContent, "'alerts'          => term('alerts', 'Notifications')")) {
    throw new RuntimeException("FAIL: topnav.php does not map 'alerts' to 'Notifications'!");
}
if (!str_contains($topnavContent, 'View Notifications')) {
    throw new RuntimeException("FAIL: topnav dropdown does not contain 'View Notifications'!");
}
echo "   [PASS] Topnav maps breadcrumb and dropdown footer to 'Notifications'.\n";

// TEST 4: Notifications Page (alerts.php)
echo "4. Checking notifications page UI labels (dashboard/alerts.php)...\n";
$alertsContent = file_get_contents(__DIR__ . '/../dashboard/alerts.php');

// Heading & Subtitle
if (!str_contains($alertsContent, "term('alerts', 'Notifications')")) {
    throw new RuntimeException("FAIL: alerts.php heading does not use 'Notifications'!");
}
if (!str_contains($alertsContent, 'Stay informed about tank levels, hardware status, and important system events.')) {
    throw new RuntimeException("FAIL: alerts.php subtitle does not match required description!");
}

// Summary Cards
if (!str_contains($alertsContent, 'TOTAL NOTIFICATIONS')) {
    throw new RuntimeException("FAIL: alerts.php missing 'TOTAL NOTIFICATIONS' card label!");
}
if (!str_contains($alertsContent, 'CRITICAL NOTIFICATIONS')) {
    throw new RuntimeException("FAIL: alerts.php missing 'CRITICAL NOTIFICATIONS' card label!");
}
if (!str_contains($alertsContent, 'HARDWARE NOTIFICATIONS')) {
    throw new RuntimeException("FAIL: alerts.php missing 'HARDWARE NOTIFICATIONS' card label!");
}

// Section Feed Heading & Description
if (!str_contains($alertsContent, 'Notification Feed')) {
    throw new RuntimeException("FAIL: alerts.php missing 'Notification Feed' section title!");
}
if (!str_contains($alertsContent, 'A chronological list of system notifications and important events.')) {
    throw new RuntimeException("FAIL: alerts.php missing feed subtitle!");
}

// Empty State
if (!str_contains($alertsContent, 'There are no notifications at this time.')) {
    throw new RuntimeException("FAIL: alerts.php missing empty state text 'There are no notifications at this time.'!");
}

// Pagination
if (!str_contains($alertsContent, 'aria-label="Notifications pagination"')) {
    throw new RuntimeException("FAIL: alerts.php pagination aria-label not updated!");
}
echo "   [PASS] All page headings, subtitles, KPI cards, feed header, and empty states verified.\n";

// TEST 5: Email Notification Template
echo "5. Checking email notification template in Notification.php...\n";
$notifModelContent = file_get_contents(__DIR__ . '/../models/Notification.php');
if (!str_contains($notifModelContent, 'Open VendoLink Notifications Center')) {
    throw new RuntimeException("FAIL: Notification.php missing 'Open VendoLink Notifications Center' CTA!");
}
echo "   [PASS] Email template uses 'Open VendoLink Notifications Center'.\n";

// TEST 6: Strict compliance: zero emojis and zero forbidden words
echo "6. Verifying strict project compliance (zero emojis, zero forbidden words)...\n";
$forbiddenWord = 'f' . 'l' . 'e' . 'e' . 't';
$filesToCheck = [
    __DIR__ . '/../includes/terminology.php',
    __DIR__ . '/../views/layouts/sidebar.php',
    __DIR__ . '/../views/layouts/topnav.php',
    __DIR__ . '/../dashboard/alerts.php',
    __DIR__ . '/../models/Notification.php'
];

foreach ($filesToCheck as $f) {
    $c = file_get_contents($f);
    if (stripos($c, $forbiddenWord) !== false) {
        throw new RuntimeException("FAIL: Forbidden word found in " . basename($f) . "!");
    }
    if (preg_match('/[\x{1F300}-\x{1FAD6}]/u', $c)) {
        throw new RuntimeException("FAIL: Emoji character found in " . basename($f) . "!");
    }
}
echo "   [PASS] Strict compliance verified across all modified files.\n";

echo "\n=== ALL NOTIFICATIONS TERMINOLOGY TESTS PASSED (6/6) ===\n";
