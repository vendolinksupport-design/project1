<?php
/** Run with: C:\xampp\php\php.exe tests\phase2_step2_smoke.php */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../models/Transaction.php';
require __DIR__ . '/../models/Maintenance.php';

$db = Database::getInstance();
$service = $db->query(
    "SELECT s.*, m.runtime_seconds AS machine_runtime FROM services s INNER JOIN machines m ON m.id = s.machine_id WHERE s.service_key = 'soap' LIMIT 1"
)->fetch();

if (!$service || !(int) $service['is_enabled']) {
    throw new RuntimeException('Soap must be enabled for this isolated runtime test.');
}

$oldServiceRuntime = (int) $service['runtime_seconds'];
$oldMachineRuntime = (int) $service['machine_runtime'];
$oldTime = (int) $service['service_seconds'];
$transactionIds = [];
$itemId = null;

try {
    $transactions = new Transaction();
    for ($i = 0; $i < 5; $i++) {
        $transactionIds[] = $transactions->recordCompleted((int) $service['id'], (float) $service['coin_value']);
    }
    $db->query('UPDATE services SET service_seconds = 300 WHERE id = :id', [':id' => $service['id']]);
    $transactionIds[] = $transactions->recordCompleted((int) $service['id'], (float) $service['coin_value']);

    $placeholders = implode(', ', array_fill(0, count($transactionIds), '?'));
    $snapshots = $db->query(
        'SELECT service_seconds_at_purchase, runtime_generated_seconds FROM service_transactions WHERE id IN (' . $placeholders . ') ORDER BY id',
        $transactionIds
    )->fetchAll();
    $firstFiveRuntime = array_sum(array_map(fn($row) => (int) $row['runtime_generated_seconds'], array_slice($snapshots, 0, 5)));
    if (count($snapshots) !== 6 || $firstFiveRuntime !== 5 * $oldTime || (int) $snapshots[5]['service_seconds_at_purchase'] !== 300) {
        throw new RuntimeException('Transaction rate snapshots were not preserved.');
    }

    $maintenance = new Maintenance();
    $maintenance->add([
        'name' => '__Phase 2 test__', 'type' => 'both', 'service_id' => (int) $service['id'], 'enabled' => 1,
        'last_completed' => '2026-01-10 00:00:00', 'calendar_value' => 6, 'calendar_unit' => 'months', 'runtime_interval' => 500 * 60,
    ]);
    $itemId = (int) $db->lastInsertId();
    $testItem = null;
    foreach ($maintenance->items() as $item) if ((int) $item['id'] === $itemId) $testItem = $item;
    if (!$testItem || $testItem['next_due_date']->format('Y-m-d') !== '2026-07-10' || $testItem['status'] !== 'overdue') {
        throw new RuntimeException('Calendar due-date calculation failed.');
    }

    $maintenance->setEnabled($itemId, false);
    if ((int) $db->query('SELECT is_enabled FROM maintenance_items WHERE id = :id', [':id' => $itemId])->fetchColumn() !== 0) {
        throw new RuntimeException('Maintenance toggle failed.');
    }
    $maintenance->complete($itemId, null, 'Temporary automated verification');
    if ((int) $db->query('SELECT COUNT(*) FROM maintenance_history WHERE maintenance_item_id = :id', [':id' => $itemId])->fetchColumn() !== 1) {
        throw new RuntimeException('Maintenance history was not recorded.');
    }

    echo "Runtime snapshots and maintenance calculations passed.\n";
} finally {
    if ($itemId) {
        $db->query('DELETE FROM maintenance_history WHERE maintenance_item_id = :id', [':id' => $itemId]);
        $db->query('DELETE FROM maintenance_items WHERE id = :id', [':id' => $itemId]);
    }
    if ($transactionIds) {
        $placeholders = implode(', ', array_fill(0, count($transactionIds), '?'));
        $db->query('DELETE FROM service_transactions WHERE id IN (' . $placeholders . ')', $transactionIds);
        $db->query('UPDATE services SET runtime_seconds = :runtime, service_seconds = :time WHERE id = :id', [':runtime' => $oldServiceRuntime, ':time' => $oldTime, ':id' => $service['id']]);
        $db->query('UPDATE machines SET runtime_seconds = :runtime WHERE id = :id', [':runtime' => $oldMachineRuntime, ':id' => $service['machine_id']]);
    }
}
