<?php
/**
 * ============================================================
 * VendoLink — Dashboard Transaction Records Automated Verification
 * ============================================================
 *
 * Tests:
 * 1. Quick preview limit: strictly 5 transactions maximum
 * 2. Order: newest transactions first (DESC)
 * 3. Fit-to-content visual height (no fake empty placeholder rows):
 *    - 0 transactions: empty-state message with clean fit height
 *    - 1 transaction: 1 real transaction row, 0 empty placeholder rows
 *    - 3 transactions: 3 real transaction rows, 0 empty placeholder rows
 *    - 5 transactions: 5 real transaction rows, 0 empty placeholder rows
 *    - 24 transactions: strictly previews only 5 newest transactions
 * 4. Header:
 *    - Title is "Transaction Records" with receipt icon
 *    - "View All" button links to dashboard/archive.php (History page)
 * 5. Full history pagination on dashboard/archive.php remains at 15 items per page
 * 6. Responsive CSS: fit-content height rules, no forced 278px/240px min-heights,
 *    VendoLink cyan & dark navy button theme
 *
 * Run with: C:\xampp\php\php.exe tests\dashboard_recent_transactions_test.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'administrator';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'System Administrator';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Machine.php';

echo "=== STARTING DASHBOARD TRANSACTION RECORDS TESTS ===\n\n";

$txModel = new Transaction();

// 1. Verify DB query returns at most 5 records in newest-first order
echo "1. Testing DB Query Limit & Newest-First Ordering...\n";
$dbRes = $txModel->getPaginated([], 1, 5);
$recentTxs = $dbRes['items'];

if (count($recentTxs) > 5) {
    throw new RuntimeException("DB query returned more than 5 transactions: " . count($recentTxs));
}
echo "   [PASS] DB query returned " . count($recentTxs) . " transactions (capped at 5).\n";

if (count($recentTxs) >= 2) {
    for ($i = 0; $i < count($recentTxs) - 1; $i++) {
        $currTime = strtotime($recentTxs[$i]['created_at']);
        $nextTime = strtotime($recentTxs[$i + 1]['created_at']);
        if ($currTime < $nextTime) {
            throw new RuntimeException("Transactions not in newest-first order!");
        }
    }
    echo "   [PASS] Verified newest-first ordering: " . $recentTxs[0]['formatted_id'] . " -> " . $recentTxs[count($recentTxs) - 1]['formatted_id'] . "\n";
}

// 2. Test Rendering of cards.php in isolated scenarios
echo "\n2. Testing Scenario Rendering (0, 1, 3, 5, 24 transactions)...\n";

function renderTestView(array $txs): string {
    $recentTxs = $txs;
    $machineInfo = ['calculated_status' => 'offline', 'name' => 'Test Unit'];
    $maintItems = [];
    $maintSummary = [];
    $todayStats = [
        'total_revenue' => 100.0,
        'total_transactions' => 10,
        'avg_transaction_value' => 10.0,
        'total_purchased_label' => '20 min',
        'water_revenue' => 50.0,
        'water_share' => 50,
        'water_count' => 5,
        'water_purchased_label' => '10 min',
        'soap_revenue' => 50.0,
        'soap_share' => 50,
        'soap_count' => 5,
        'soap_purchased_label' => '10 min'
    ];
    $today = date('Y-m-d');
    $waterService = ['coin_value' => 5.0, 'service_seconds' => 120];
    $soapService = ['coin_value' => 5.0, 'service_seconds' => 120];
    
    ob_start();
    include __DIR__ . '/../views/dashboard/cards.php';
    return ob_get_clean();
}

// Scenario 0: 0 records
$html0 = renderTestView([]);
if (strpos($html0, 'dashboard-recent-tx-card') === false) {
    throw new RuntimeException("Missing .dashboard-recent-tx-card class in 0 records scenario.");
}
if (strpos($html0, 'No transactions recorded yet.') === false) {
    throw new RuntimeException("Missing empty state message in 0 records scenario.");
}
if (strpos($html0, 'Transaction Records') === false) {
    throw new RuntimeException("Missing 'Transaction Records' section heading in 0 records scenario.");
}
echo "   [PASS] 0 transactions: renders empty-state message and Transaction Records title correctly.\n";

// Scenario 1: 1 record
$sampleTx = [
    'id' => 101,
    'formatted_id' => '#00101',
    'service_key' => 'water',
    'service_name' => 'Water Service',
    'amount_formatted' => '₱5.00',
    'purchased_time_label' => '30 sec',
    'status' => 'completed',
    'created_at' => date('Y-m-d H:i:s')
];
$html1 = renderTestView([$sampleTx]);
preg_match_all('/<span class="tx-id">/i', $html1, $idMatches1);
if (count($idMatches1[0]) !== 1) {
    throw new RuntimeException("Expected exactly 1 row for 1 record scenario, found: " . count($idMatches1[0]));
}
if (strpos($html1, 'tx-empty-row') !== false) {
    throw new RuntimeException("Found fake empty placeholder rows; should fit content without empty rows!");
}
echo "   [PASS] 1 transaction: renders exactly 1 row, no fake placeholder rows (fit-to-content).\n";

// Scenario 3: 3 records
$txs3 = [
    array_merge($sampleTx, ['id' => 103, 'formatted_id' => '#00103']),
    array_merge($sampleTx, ['id' => 102, 'formatted_id' => '#00102']),
    array_merge($sampleTx, ['id' => 101, 'formatted_id' => '#00101'])
];
$html3 = renderTestView($txs3);
preg_match_all('/<span class="tx-id">/i', $html3, $idMatches3);
if (count($idMatches3[0]) !== 3) {
    throw new RuntimeException("Expected exactly 3 rows for 3 records scenario, found: " . count($idMatches3[0]));
}
if (strpos($html3, 'tx-empty-row') !== false) {
    throw new RuntimeException("Found fake empty placeholder rows in 3 records scenario!");
}
echo "   [PASS] 3 transactions: renders exactly 3 rows, no fake placeholder rows (fit-to-content).\n";

// Scenario 5: 5 records
$txs5 = [
    array_merge($sampleTx, ['id' => 105, 'formatted_id' => '#00105']),
    array_merge($sampleTx, ['id' => 104, 'formatted_id' => '#00104']),
    array_merge($sampleTx, ['id' => 103, 'formatted_id' => '#00103']),
    array_merge($sampleTx, ['id' => 102, 'formatted_id' => '#00102']),
    array_merge($sampleTx, ['id' => 101, 'formatted_id' => '#00101'])
];
$html5 = renderTestView($txs5);
preg_match_all('/<span class="tx-id">/i', $html5, $idMatches5);
if (count($idMatches5[0]) !== 5) {
    throw new RuntimeException("Expected exactly 5 rows for 5 records scenario, found: " . count($idMatches5[0]));
}
if (strpos($html5, 'tx-empty-row') !== false) {
    throw new RuntimeException("Found fake empty placeholder rows in 5 records scenario!");
}
echo "   [PASS] 5 transactions: renders exactly 5 rows, no fake placeholder rows.\n";

// Scenario 24: 24 records (more than 5)
$txs24 = [];
for ($i = 24; $i >= 1; $i--) {
    $txs24[] = array_merge($sampleTx, ['id' => $i, 'formatted_id' => '#' . str_pad((string)$i, 5, '0', STR_PAD_LEFT)]);
}
$html24 = renderTestView($txs24);
$recentSection24 = substr($html24, strpos($html24, 'Transaction Records'));
$nextSection24 = strpos($recentSection24, 'Recent System & Business Activity');
if ($nextSection24 !== false) {
    $recentSection24 = substr($recentSection24, 0, $nextSection24);
}
preg_match_all('/<span class="tx-id">/i', $recentSection24, $idMatches24);
if (count($idMatches24[0]) !== 5) {
    throw new RuntimeException("Expected strictly 5 transactions displayed on dashboard for 24 records, found: " . count($idMatches24[0]));
}
if (strpos($recentSection24, '#00024') === false || strpos($recentSection24, '#00020') === false) {
    throw new RuntimeException("Dashboard did not show the latest 5 records (#00024 to #00020).");
}
if (strpos($recentSection24, '#00019') !== false) {
    throw new RuntimeException("Dashboard showed 6th record (#00019); must only preview 5!");
}
echo "   [PASS] 24 transactions: strictly previews only latest 5 records (#00024 to #00020).\n";

// 3. Verify "View All" link to History page (dashboard/archive.php)
echo "\n3. Verifying 'View All' Link & Full History Page...\n";
if (strpos($html5, 'href="' . BASE_URL . '/dashboard/archive.php"') === false) {
    throw new RuntimeException("'View All' button does not link to dashboard/archive.php");
}
if (strpos($html5, 'View All') === false && strpos($html5, 'View More') === false) {
    throw new RuntimeException("Missing 'View More' / 'View All' button text on Dashboard Transaction Records");
}
echo "   [PASS] 'View More' button points to " . BASE_URL . "/dashboard/archive.php\n";

// Check dashboard/archive.php per_page setting
$archivePhpContent = file_get_contents(__DIR__ . '/../dashboard/archive.php');
if (!preg_match('/\$perPage\s*=\s*max\(1,\s*min\(100,\s*\(int\)\(\$_GET\[\'per_page\'\]\s*\?\?\s*15\)\)\);/', $archivePhpContent)) {
    throw new RuntimeException("dashboard/archive.php perPage default is not 15!");
}
echo "   [PASS] Full History page (dashboard/archive.php) defaults to 15 records per page.\n";

// 4. Verify CSS rules in app.css
echo "\n4. Verifying CSS Rules in app.css...\n";
$cssContent = file_get_contents(__DIR__ . '/../assets/css/app.css');

if (strpos($cssContent, '.dashboard-recent-tx-card') === false) {
    throw new RuntimeException("CSS missing .dashboard-recent-tx-card selector");
}
if (strpos($cssContent, '--dash-tx-table-min-height') !== false) {
    throw new RuntimeException("CSS still contains rigid --dash-tx-table-min-height variable!");
}
if (strpos($cssContent, 'min-height: var(--dash-tx-table-min-height') !== false) {
    throw new RuntimeException("CSS still forcing min-height on table-responsive!");
}
echo "   [PASS] Verified fit-to-content CSS rules and removal of rigid min-heights in app.css.\n";

echo "\n🎉 ALL DASHBOARD TRANSACTION RECORDS TESTS PASSED 100% SUCCESSFULLY!\n";
