<?php
/**
 * ============================================================
 * VendoLink — Sales Overlay & History Streamline Test Suite
 * ============================================================
 *
 * Verifies:
 * 1. Sales Dashboard Recent Transactions modal overlay (#allTransactionsModal)
 * 2. Multi-tab modal structure: Transaction Records & Cash Collections
 * 3. api/transactions.php endpoint pagination & owner isolation
 * 4. History repository streamlining: View All History as default tab (tab=all)
 * 5. Mixed Maintenance + Configuration audit feed with badges
 * 6. Redirection from archive.php?tab=transactions to sales.php?open_transactions=1
 * 7. Alerts KPI card label cleanup: TOTAL ALERTS, CRITICAL ALERTS, SYSTEM LOGS
 * 8. Strict zero emojis & zero occurrences of the forbidden word
 *
 * @package VendoLink
 * @version 1.0
 */

declare(strict_types=1);

echo "============================================================\n";
echo " VENDOLINK — SALES OVERLAY & HISTORY STREAMLINE TEST SUITE \n";
echo "============================================================\n\n";

$passCount = 0;
$failCount = 0;

function assert_test(bool $cond, string $desc): void
{
    global $passCount, $failCount;
    if ($cond) {
        $passCount++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$desc}\n";
    }
}

// -------------------------------------------------------------
// 1. Sales Dashboard In-Page Transaction History (No Modal Overlay)
// -------------------------------------------------------------
echo "--- 1. Testing Sales Dashboard In-Page Transaction History (dashboard/sales.php) ---\n";
$salesCode = file_get_contents(__DIR__ . '/../dashboard/sales.php');

assert_test(strpos($salesCode, 'Back to Sales') !== false, "Back to Sales button is present in sales.php");
assert_test(strpos($salesCode, 'href="<?= BASE_URL ?>/dashboard/sales.php"') !== false, "Back to Sales links directly back to sales overview");
assert_test(strpos($salesCode, 'href="<?= BASE_URL ?>/dashboard/sales.php?view=transactions"') !== false, "View All Transactions links to in-page transaction history view");
assert_test(strpos($salesCode, 'id="allTransactionsModal"') === false, "Modal overlay #allTransactionsModal is completely removed");
assert_test(strpos($salesCode, 'data-bs-toggle="modal"') === false, "Modal toggle data-bs-toggle='modal' is removed");
assert_test(strpos($salesCode, 'Transaction Records') !== false, "Sub-tab 'Transaction Records' present");
assert_test(strpos($salesCode, 'Cash Collections') !== false, "Sub-tab 'Cash Collections' present");
assert_test(strpos($salesCode, 'name="search"') !== false, "Live search input present in filter card");
assert_test(strpos($salesCode, 'name="service"') !== false, "Service filter select present in filter card");
assert_test(strpos($salesCode, 'name="status"') !== false, "Status filter select present in filter card");
assert_test(strpos($salesCode, 'name="machine"') !== false, "Machine filter select present in filter card");
assert_test(strpos($salesCode, 'cash_collections.php') !== false, "Direct link to dedicated Cash Collections page present");

// -------------------------------------------------------------
// 2. Transactions API Endpoint
// -------------------------------------------------------------
echo "\n--- 2. Testing Transactions API Endpoint (api/transactions.php) ---\n";
assert_test(file_exists(__DIR__ . '/../api/transactions.php'), "api/transactions.php endpoint file exists");
$apiCode = file_get_contents(__DIR__ . '/../api/transactions.php');
assert_test(strpos($apiCode, 'getPaginated') !== false, "api/transactions.php calls getPaginated()");
assert_test(strpos($apiCode, 'current_owner_id()') !== false, "api/transactions.php enforces owner isolation");
assert_test(strpos($apiCode, 'application/json') !== false, "api/transactions.php sets JSON response header");

// -------------------------------------------------------------
// 3. History Repository Streamlining & Unified Audit Feed
// -------------------------------------------------------------
echo "\n--- 3. Testing History Streamline & View All History (dashboard/archive.php) ---\n";
$archiveCode = file_get_contents(__DIR__ . '/../dashboard/archive.php');

assert_test(strpos($archiveCode, "activeTab = \$_GET['tab'] ?? 'all'") !== false, "Default activeTab is set to 'all'");
assert_test(strpos($archiveCode, "sales.php?view=transactions") !== false, "archive.php redirects legacy tab=transactions to sales.php?view=transactions");
assert_test(strpos($archiveCode, 'View All History') !== false, "archive.php features 'View All History' tab in navigation");
assert_test(strpos($archiveCode, 'Maintenance History') !== false, "archive.php features 'Maintenance History' tab");
assert_test(strpos($archiveCode, 'Configuration History') !== false, "archive.php features 'Configuration History' tab");
assert_test(strpos($archiveCode, 'getAllHistory') !== false, "archive.php calls getAllHistory() for tab=all");

// -------------------------------------------------------------
// 4. Archive Model getAllHistory Method
// -------------------------------------------------------------
echo "\n--- 4. Testing Archive Model (models/Archive.php) ---\n";
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Archive.php';

$archiveModel = new Archive();
assert_test(method_exists($archiveModel, 'getAllHistory'), "Archive model has getAllHistory() method");

$allHistory = $archiveModel->getAllHistory([], 1, 15, null, null);
assert_test(isset($allHistory['items']), "getAllHistory() returns 'items' array");
assert_test(isset($allHistory['total_items']), "getAllHistory() returns 'total_items'");
assert_test(isset($allHistory['page']), "getAllHistory() returns 'page'");

// -------------------------------------------------------------
// 5. Notification KPI Card Labels (dashboard/alerts.php)
// -------------------------------------------------------------
echo "\n--- 5. Testing Alerts KPI Cards (dashboard/alerts.php) ---\n";
$alertsCode = file_get_contents(__DIR__ . '/../dashboard/alerts.php');

assert_test(strpos($alertsCode, '>TOTAL ALERTS</p>') !== false, "Card 1 displays TOTAL ALERTS");
assert_test(strpos($alertsCode, '>CRITICAL ALERTS</p>') !== false, "Card 2 displays CRITICAL ALERTS");
assert_test(strpos($alertsCode, '>SYSTEM LOGS</p>') !== false, "Card 3 displays SYSTEM LOGS");
assert_test(strpos($alertsCode, 'Restorations, connectivity &amp; activity logs') !== false, "Card 3 subtext reflects restorations & activity logs");

// -------------------------------------------------------------
// 6. Dashboard Cards Navigation to Sales Overview
// -------------------------------------------------------------
echo "\n--- 6. Testing Dashboard Cards Navigation (views/dashboard/cards.php) ---\n";
$cardsCode = file_get_contents(__DIR__ . '/../views/dashboard/cards.php');

assert_test(strpos($cardsCode, 'dashboard/sales.php?service=water') !== false, "Water Sales card links to dashboard/sales.php?service=water");
assert_test(strpos($cardsCode, 'dashboard/sales.php?service=soap') !== false, "Soap Sales card links to dashboard/sales.php?service=soap");

// -------------------------------------------------------------
// 7. Strict Project Constraints: Zero Emojis & Zero Forbidden Word
// -------------------------------------------------------------
echo "\n--- 7. Verifying Strict Project Constraints ---\n";
$filesToCheck = [
    'dashboard/sales.php'       => $salesCode,
    'dashboard/archive.php'     => $archiveCode,
    'dashboard/alerts.php'      => $alertsCode,
    'api/transactions.php'      => $apiCode,
    'views/dashboard/cards.php' => $cardsCode,
];

$emojiRegex = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

foreach ($filesToCheck as $fn => $content) {
    $hasEmoji = (preg_match($emojiRegex, $content) === 1);
    assert_test(!$hasEmoji, "{$fn} has strictly ZERO emojis");

    $hasForbidden = (stripos($content, 'fleet') !== false);
    assert_test(!$hasForbidden, "{$fn} has strictly ZERO occurrences of forbidden word");
}

echo "\n============================================================\n";
echo "SUMMARY: {$passCount} Passed, {$failCount} Failed.\n";
if ($failCount === 0) {
    echo "ALL TESTS PASSED 100%!\n";
} else {
    echo "TEST FAILURES DETECTED!\n";
    exit(1);
}
echo "============================================================\n";
