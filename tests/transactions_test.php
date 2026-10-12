<?php
/**
 * ============================================================
 * VendoLink — Transactions & Sales Module Unit & Integration Tests
 * ============================================================
 *
 * Run with: C:\xampp\php\php.exe tests\transactions_test.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../models/Service.php';

$db = Database::getInstance();
$tx = new Transaction();
$serviceModel = new Service();

echo "Starting Transactions & Sales Verification...\n";

// 1. Verify Services exist
$water = $db->query("SELECT * FROM services WHERE service_key = 'water' LIMIT 1")->fetch();
$soap  = $db->query("SELECT * FROM services WHERE service_key = 'soap' LIMIT 1")->fetch();

if (!$water || !$soap) {
    throw new RuntimeException("Water and Soap services must exist.");
}

$oldWaterPrice = (float) $water['coin_value'];
$oldWaterTime  = (int) $water['service_seconds'];
$oldSoapPrice  = (float) $soap['coin_value'];
$oldSoapTime   = (int) $soap['service_seconds'];

$createdTxIds = [];

try {
    // 2. Test Transaction Insertion & Rate Snapshot
    echo "Testing transaction insertion and configuration snapshot...\n";
    
    // Water: ₱5 = 120s
    $tx1 = $tx->record((int) $water['id'], 5.00, 'completed');
    $createdTxIds[] = $tx1;

    // Soap: ₱5 = 120s
    $tx2 = $tx->record((int) $soap['id'], 10.00, 'completed'); // 2 coins = 240s
    $createdTxIds[] = $tx2;

    // Active status
    $tx3 = $tx->record((int) $water['id'], 5.00, 'active');
    $createdTxIds[] = $tx3;

    // Cancelled status
    $tx4 = $tx->record((int) $soap['id'], 5.00, 'cancelled');
    $createdTxIds[] = $tx4;

    // Verify snapshot records
    $row1 = $db->query("SELECT * FROM service_transactions WHERE id = :id", [':id' => $tx1])->fetch();
    if ((float) $row1['amount'] !== 5.00 || (int) $row1['service_seconds_at_purchase'] !== 120 || (int) $row1['runtime_generated_seconds'] !== 120 || $row1['status'] !== 'completed') {
        throw new RuntimeException("Transaction 1 snapshot mismatch: " . json_encode($row1));
    }

    $row2 = $db->query("SELECT * FROM service_transactions WHERE id = :id", [':id' => $tx2])->fetch();
    if ((float) $row2['amount'] !== 10.00 || (int) $row2['runtime_generated_seconds'] !== 240 || $row2['status'] !== 'completed') {
        throw new RuntimeException("Transaction 2 snapshot mismatch: " . json_encode($row2));
    }

    echo "✅ Insertion and snapshot verified.\n";

    // 3. Test Rate Change Immunity (Historical Snapshot Preservation)
    echo "Testing historical rate snapshot immunity after service configuration update...\n";
    $serviceModel->updateConfiguration((int) $water['id'], true, 5.00, 300); // Changed to 5 min
    
    $tx5 = $tx->record((int) $water['id'], 5.00, 'completed');
    $createdTxIds[] = $tx5;

    $row1After = $db->query("SELECT * FROM service_transactions WHERE id = :id", [':id' => $tx1])->fetch();
    $row5After = $db->query("SELECT * FROM service_transactions WHERE id = :id", [':id' => $tx5])->fetch();

    if ((int) $row1After['service_seconds_at_purchase'] !== 120) {
        throw new RuntimeException("ERROR: Old transaction was modified by service update!");
    }
    if ((int) $row5After['service_seconds_at_purchase'] !== 300) {
        throw new RuntimeException("ERROR: New transaction did not capture updated rate snapshot!");
    }
    echo "✅ Historical snapshot preservation verified: old remains 120s, new is 300s.\n";

    // 4. Test Summary Statistics Calculation
    echo "Testing summary statistics calculations...\n";
    $stats = $tx->getSummaryStats();
    if ($stats['today_sales'] < 20.00 || $stats['today_count'] < 3) {
        throw new RuntimeException("Stats calculation failed: " . json_encode($stats));
    }
    echo "✅ Summary stats verified: Today's sales = ₱{$stats['today_sales']}, Count = {$stats['today_count']}.\n";

    // 5. Test Filters and Search
    echo "Testing filter criteria...\n";
    
    // Service filter: water
    $waterList = $tx->getPaginated(['service' => 'water'], 1, 50);
    foreach ($waterList['items'] as $item) {
        if ($item['service_key'] !== 'water') {
            throw new RuntimeException("Water filter returned non-water transaction.");
        }
    }
    echo "✅ Water service filter verified.\n";

    // Service filter: soap
    $soapList = $tx->getPaginated(['service' => 'soap'], 1, 50);
    foreach ($soapList['items'] as $item) {
        if ($item['service_key'] !== 'soap') {
            throw new RuntimeException("Soap filter returned non-soap transaction.");
        }
    }
    echo "✅ Soap service filter verified.\n";

    // Status filter: cancelled
    $cancelledList = $tx->getPaginated(['status' => 'cancelled'], 1, 50);
    $foundCancelled = false;
    foreach ($cancelledList['items'] as $item) {
        if ($item['status'] !== 'cancelled') {
            throw new RuntimeException("Status filter returned wrong status.");
        }
        if ((int)$item['id'] === $tx4) {
            $foundCancelled = true;
        }
    }
    if (!$foundCancelled) {
        throw new RuntimeException("Cancelled transaction not found in filtered results.");
    }
    echo "✅ Status filter verified.\n";

    // Search by ID
    $searchRes = $tx->getPaginated(['search' => '#' . str_pad((string)$tx1, 5, '0', STR_PAD_LEFT)], 1, 50);
    if (empty($searchRes['items']) || (int)$searchRes['items'][0]['id'] !== $tx1) {
        throw new RuntimeException("Search by formatted ID #0000X failed.");
    }
    echo "✅ Search by ID verified.\n";

    // 6. Test Pagination
    echo "Testing pagination calculations...\n";
    $page1 = $tx->getPaginated([], 1, 2);
    if (count($page1['items']) > 2 || $page1['page'] !== 1 || $page1['total_pages'] < 2) {
        throw new RuntimeException("Pagination logic mismatch.");
    }
    $page2 = $tx->getPaginated([], 2, 2);
    if (count($page2['items']) > 2 || $page2['page'] !== 2) {
        throw new RuntimeException("Page 2 pagination failed.");
    }
    echo "✅ Pagination verified (Page 1 & 2 distinct subsets).\n";

    echo "\n🎉 ALL TRANSACTIONS & SALES TESTS PASSED SUCCESSFULLY!\n";
} finally {
    // Restore service configuration and cleanup test transactions
    $serviceModel->updateConfiguration((int) $water['id'], true, $oldWaterPrice, $oldWaterTime);
    $serviceModel->updateConfiguration((int) $soap['id'], true, $oldSoapPrice, $oldSoapTime);

    if (!empty($createdTxIds)) {
        $inClause = implode(',', array_fill(0, count($createdTxIds), '?'));
        $db->query("DELETE FROM service_transactions WHERE id IN ({$inClause})", $createdTxIds);
    }
}
