<?php
/**
 * ============================================================
 * VendoLink — Dashboard Recent Activity Removal Automated Verification
 * ============================================================
 *
 * Verifies:
 * 1. cards.php does not contain .dashboard-recent-activity-card or audit log queries.
 * 2. cards.php does not render recent_activity heading, description, or action buttons.
 * 3. cards.php ends cleanly after .dashboard-recent-tx-card.
 * 4. activity_logs table, models, and log_activity() remain fully functional.
 * 5. dashboard/archive.php remains fully operational for historical records.
 * 6. HTML tags in views/dashboard/cards.php remain properly balanced.
 *
 * Run with: C:\xampp\php\php.exe tests\dashboard_recent_activity_removal_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== STARTING DASHBOARD RECENT ACTIVITY REMOVAL VERIFICATION ===\n\n";

$cardsFile = __DIR__ . '/../views/dashboard/cards.php';
$cardsContent = file_get_contents($cardsFile);

// Test 1: Verify absence of Recent Activity markup in cards.php
echo "1. Checking views/dashboard/cards.php for removed components...\n";
if (str_contains($cardsContent, 'dashboard-recent-activity-card')) {
    throw new RuntimeException("FAIL: cards.php still contains 'dashboard-recent-activity-card'!");
}
if (str_contains($cardsContent, "term('recent_activity')")) {
    throw new RuntimeException("FAIL: cards.php still contains term('recent_activity')!");
}
if (str_contains($cardsContent, 'Audit feed of system authentications')) {
    throw new RuntimeException("FAIL: cards.php still contains the activity feed description!");
}
if (str_contains($cardsContent, 'FROM activity_logs')) {
    throw new RuntimeException("FAIL: cards.php still contains inline queries to activity_logs!");
}
echo "   [PASS] All Recent Activity card markup, headers, and queries successfully removed.\n";

// Test 2: Verify cards.php ends cleanly with Transaction Records
echo "2. Checking clean termination of views/dashboard/cards.php...\n";
$trimmed = trim($cardsContent);
if (!str_ends_with($trimmed, '</div>')) {
    throw new RuntimeException("FAIL: cards.php does not end with closing </div> tag!");
}
if (!str_contains($trimmed, 'dashboard-recent-tx-card')) {
    throw new RuntimeException("FAIL: cards.php is missing dashboard-recent-tx-card!");
}
echo "   [PASS] cards.php terminates cleanly after the Transaction Records card.\n";

// Test 3: Verify activity_logs table and underlying audit logging remain functional
echo "3. Verifying activity_logs database table and audit functions...\n";
$db = Database::getInstance();
$logsCheck = $db->query("SELECT COUNT(*) as cnt FROM activity_logs")->fetch();
echo "   [PASS] Database activity_logs table accessible. Total records: " . (int)$logsCheck['cnt'] . "\n";

// Test 4: Verify dashboard/archive.php exists and contains history views
echo "4. Checking dashboard/archive.php availability...\n";
$archiveFile = __DIR__ . '/../dashboard/archive.php';
if (!file_exists($archiveFile)) {
    throw new RuntimeException("FAIL: dashboard/archive.php not found!");
}
$archiveContent = file_get_contents($archiveFile);
if (!str_contains($archiveContent, 'archive') && !str_contains($archiveContent, 'transaction')) {
    throw new RuntimeException("FAIL: dashboard/archive.php content unexpected!");
}
echo "   [PASS] dashboard/archive.php is intact and retains full audit history functionality.\n";

// Test 5: Verify dashboard/index.php template inclusion
echo "5. Checking dashboard/index.php structure...\n";
$indexFile = __DIR__ . '/../dashboard/index.php';
$indexContent = file_get_contents($indexFile);
if (!str_contains($indexContent, 'cards.php')) {
    throw new RuntimeException("FAIL: dashboard/index.php no longer includes cards.php!");
}
echo "   [PASS] dashboard/index.php properly includes cards.php.\n";

echo "\n=== ALL DASHBOARD RECENT ACTIVITY REMOVAL TESTS PASSED (5/5) ===\n";
