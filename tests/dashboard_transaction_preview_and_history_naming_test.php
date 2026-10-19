<?php
/**
 * Test: Dashboard Transaction Preview Limit (5 Records) and Standardized History Naming Verification
 * Run with: C:\xampp\php\php.exe tests/dashboard_transaction_preview_and_history_naming_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/terminology.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Archive.php';
require_once __DIR__ . '/../models/Machine.php';

echo "=== STARTING DASHBOARD PREVIEW & HISTORY NAMING VERIFICATION ===\n\n";

$db = Database::getInstance();
$txModel = new Transaction();
$archiveModel = new Archive();

// ------------------------------------------------------------
// 1. Dashboard Query Limits & Ordering Verification
// ------------------------------------------------------------
echo "1. Testing Dashboard Recent Transactions Query (Limit 5, Newest-First)...\n";
$dashTxs = $txModel->getPaginated([], 1, 5)['items'];
assert_true(count($dashTxs) <= 5, "Dashboard query strictly returns at most 5 records (found " . count($dashTxs) . ")");

if (count($dashTxs) >= 2) {
    for ($i = 0; $i < count($dashTxs) - 1; $i++) {
        $currTime = strtotime($dashTxs[$i]['created_at']);
        $nextTime = strtotime($dashTxs[$i + 1]['created_at']);
        assert_true($currTime >= $nextTime, "Transactions ordered newest first: {$dashTxs[$i]['id']} (time: {$currTime}) >= {$dashTxs[$i + 1]['id']} (time: {$nextTime})");
    }
}
echo "  [PASS] Dashboard transactions query limit and newest-first ordering verified.\n\n";

// ------------------------------------------------------------
// 2. Dashboard Render Verification (View All Button & Preview)
// ------------------------------------------------------------
echo "2. Testing Dashboard Transaction Records Header & View All Button...\n";
$cardsHtml = file_get_contents(ROOT_PATH . '/views/dashboard/cards.php');

assert_true(strpos($cardsHtml, 'dashboard-recent-tx-card') !== false, "Transaction Records section present on dashboard");
assert_true(strpos($cardsHtml, 'Transaction Records') !== false, "Section heading contains 'Transaction Records'");

// Verify View More button
assert_true(strpos($cardsHtml, 'View All') !== false || strpos($cardsHtml, 'View More') !== false, "View More button text present in Transaction Records header");
assert_true(strpos($cardsHtml, 'href="<?= BASE_URL ?>/dashboard/archive.php"') !== false, "View All button links to dashboard/archive.php (History page)");
assert_true(strpos($cardsHtml, 'btn-outline-primary') !== false, "View All button styled with VendoLink cyan outline theme");

// Verify flex header for horizontal alignment with section title
assert_true(strpos($cardsHtml, 'breakdown-header d-flex justify-content-between align-items-center') !== false, "Header uses flexbox for horizontal alignment with section title");

// Verify 5-record limit presentation logic
assert_true(strpos($cardsHtml, 'array_slice($recentTxs ?? [], 0, 5)') !== false, "Presentation layer strictly caps preview to 5 items");

// Verify absence of fake empty placeholder rows
assert_true(strpos($cardsHtml, 'tx-empty-row') === false, "No fake empty placeholder rows injected in cards.php");
echo "  [PASS] View All button, Transaction Records title, and fit-to-content preview verified on dashboard.\n\n";

// ------------------------------------------------------------
// 3. Standardized History Naming Verification
// ------------------------------------------------------------
echo "3. Testing Standardized 'History' Naming Across Owner Interface...\n";

// A. Terminology dictionary
assert_true(term('history') === 'History', "term('history') returns 'History'");
assert_true(term('transaction_history') === 'History', "term('transaction_history') returns 'History'");
assert_true(term('archive') === 'History', "term('archive') returns 'History'");
assert_true(term('history_archive') === 'History', "term('history_archive') returns 'History'");

// B. Sidebar navigation
$sidebarHtml = file_get_contents(ROOT_PATH . '/views/layouts/sidebar.php');
assert_true(strpos($sidebarHtml, "term('history', 'History')") !== false, "Sidebar uses term('history', 'History')");
assert_true(strpos($sidebarHtml, "BASE_URL . '/dashboard/archive.php'") !== false, "Sidebar points to /dashboard/archive.php");

// C. Top navigation / breadcrumbs
$topnavHtml = file_get_contents(ROOT_PATH . '/views/layouts/topnav.php');
assert_true(strpos($topnavHtml, "'archive'         => term('history', 'History')") !== false, "Topnav maps 'archive' to term('history', 'History')");

// D. History page (dashboard/archive.php)
$archivePhp = file_get_contents(ROOT_PATH . '/dashboard/archive.php');
assert_true(strpos($archivePhp, "\$pageTitle   = term('history', 'History');") !== false, "archive.php sets \$pageTitle to term('history', 'History')");
assert_true(strpos($archivePhp, "<i class=\"bi bi-clock-history me-2\"></i><?= term('history', 'History') ?>") !== false, "archive.php main heading is term('history', 'History')");
assert_true(strpos($archivePhp, "meta name=\"description\" content=\"<?= APP_NAME ?> — <?= term('history', 'History') ?>\"") !== false, "archive.php meta description uses History");

echo "  [PASS] Standardized History naming verified in sidebar, breadcrumbs, page title, and main heading.\n\n";

// ------------------------------------------------------------
// 4. History Page Functional Preservation
// ------------------------------------------------------------
echo "4. Testing History Page Tabs, Filters, and Records Functionality...\n";

// Tabs
assert_true(strpos($archivePhp, "'tab' => 'transactions'") !== false, "Transaction records tab present");
assert_true(strpos($archivePhp, "'tab' => 'maintenance'") !== false, "Maintenance History tab present");
assert_true(strpos($archivePhp, "'tab' => 'configuration'") !== false, "Configuration History tab present");
assert_true(strpos($archivePhp, "Audit Records") !== false, "Audit Records indicator preserved");

// Filters
assert_true(strpos($archivePhp, 'name="search"') !== false, "Search filter preserved");
assert_true(strpos($archivePhp, 'name="service"') !== false, "Service filter preserved");
assert_true(strpos($archivePhp, 'name="date_from"') !== false, "Date From filter preserved");
assert_true(strpos($archivePhp, 'name="date_to"') !== false, "Date To filter preserved");

// Verify database records are intact and queryable through History page model
$fullHistory = $archiveModel->getTransactionHistory([], 1, 15);
assert_true(isset($fullHistory['items']), "Full History returns transaction items");
assert_true(isset($fullHistory['total_items']), "Full History returns total_items count");
assert_true($fullHistory['total_items'] >= count($dashTxs), "Full History total items ({$fullHistory['total_items']}) >= Dashboard preview count (" . count($dashTxs) . ")");

// Verify database table has not had records deleted
$dbCount = (int)$db->query("SELECT COUNT(*) FROM service_transactions")->fetchColumn();
assert_true($dbCount >= 4, "Database service_transactions records preserved (count: {$dbCount})");

echo "  [PASS] Full History page functionality, filters, tabs, and database integrity verified.\n\n";

echo "🎉 ALL DASHBOARD PREVIEW & HISTORY NAMING TESTS PASSED 100% SUCCESSFULLY!\n";

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  [PASS] {$message}\n";
}
