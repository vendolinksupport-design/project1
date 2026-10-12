<?php
/**
 * ============================================================
 * VendoLink — Seed 6-Month Demo Transactions & Cash Collections
 * ============================================================
 *
 * Generates realistic 6-month historical dataset for VendoLink History:
 * - 6-month range: April 12, 2026 to October 12, 2026 (~184 days)
 * - Average 30-50 customers/day with realistic variations:
 *   * Weekday vs weekend customer foot traffic
 *   * Slower monsoon/rainy days (July/August)
 *   * Realistic peak hours (morning 07:00-10:30, evening 16:30-19:30)
 * - Service types: Water Service (65%) and Soap Service (35%)
 * - Realistic Philippine peso amounts: ₱5 (45%), ₱10 (40%), ₱15 (10%), ₱20 (5%)
 * - Purchase durations calibrated to rates (120s per ₱5 coin)
 * - Completed statuses (~96% completed, ~2% incomplete, ~1% interrupted, ~1% cancelled)
 * - Realistic power telemetry (current, watts)
 * - Cash collections every 3-6 days logically matching window sales with
 *   occasional realistic minor discrepancies (₱0 match ~80%, +₱5/+₱10 ~14%, -₱5/-₱10 ~6%)
 * - Clearly tagged with client_transaction_id LIKE 'DEMO-%' and notes LIKE '[Demo]%'
 *   to ensure actual customer records are NEVER touched or mixed up.
 *
 * Run with: C:\xampp\php\php.exe database/seed_history_demo_data.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "=== VENDOLINK 6-MONTH HISTORY DEMO SEEDER ===\n\n";

$db = Database::getInstance();
$pdo = $db->getConnection();

// 1. Target Machine and Services
$machineId = 1;
$ownerId = 2; // Business Owner

$machine = $pdo->query("SELECT id, name, owner_id FROM machines WHERE id = {$machineId}")->fetch(PDO::FETCH_ASSOC);
if (!$machine) {
    die("Error: Target machine ID {$machineId} not found.\n");
}
echo "Target Machine: {$machine['name']} (ID: {$machine['id']}, Owner ID: {$machine['owner_id']})\n";

$services = $pdo->query("SELECT id, service_key, name, coin_value, service_seconds FROM services WHERE machine_id = {$machineId}")->fetchAll(PDO::FETCH_ASSOC);
$servicesByKey = [];
foreach ($services as $s) {
    $servicesByKey[$s['service_key']] = $s;
}

if (!isset($servicesByKey['water']) || !isset($servicesByKey['soap'])) {
    die("Error: Water and Soap services not configured for machine ID {$machineId}.\n");
}
$waterService = $servicesByKey['water'];
$soapService = $servicesByKey['soap'];
echo "Services: Water (ID: {$waterService['id']}), Soap (ID: {$soapService['id']})\n";

// 2. Safe Cleanup of Previous Demo Records Only
echo "\nCleaning up previous demo transactions and cash collections...\n";
$deletedTx = $pdo->exec("DELETE FROM service_transactions WHERE client_transaction_id LIKE 'DEMO-%'");
$deletedCc = $pdo->exec("DELETE FROM cash_collections WHERE notes LIKE '[Demo]%'");
echo "Cleaned {$deletedTx} old demo transactions and {$deletedCc} old demo cash collections.\n";

$preservedTx = (int) $pdo->query("SELECT COUNT(*) FROM service_transactions")->fetchColumn();
$preservedCc = (int) $pdo->query("SELECT COUNT(*) FROM cash_collections")->fetchColumn();
echo "Preserved {$preservedTx} non-demo transactions and {$preservedCc} non-demo cash collections.\n\n";

// 3. Define 6-Month Range: 2026-04-12 to 2026-10-12
$startDate = new DateTime('2026-04-12');
$endDate   = new DateTime('2026-10-12');
$interval  = new DateInterval('P1D');
$datePeriod = new DatePeriod($startDate, $interval, (clone $endDate)->modify('+1 day'));

echo "Generating transactions from " . $startDate->format('Y-m-d') . " to " . $endDate->format('Y-m-d') . "...\n";

// Setup random seed for reproducible yet realistic distribution
mt_srand(20261012);

$txBatch = [];
$totalGeneratedTx = 0;
$totalCompletedTx = 0;
$totalWaterTx = 0;
$totalSoapTx = 0;
$totalRevenue = 0.0;
$waterRevenue = 0.0;
$soapRevenue = 0.0;

$completedTransactionsByDate = []; // date => list of [amount, timestamp] for collection matching

$txCounter = 1;
$pdo->beginTransaction();

$insertSql = "INSERT INTO service_transactions (
    machine_id, client_transaction_id, service_id, amount,
    coin_value_at_purchase, service_seconds_at_purchase,
    runtime_generated_seconds, actual_runtime_seconds,
    status, status_reason, avg_current, peak_current, avg_watts, peak_watts,
    completed_at, created_at
) VALUES ";

$params = [];
$valuesClauses = [];

foreach ($datePeriod as $dt) {
    $curDateStr = $dt->format('Y-m-d');
    $dayOfWeek = (int) $dt->format('N'); // 1 = Monday, 7 = Sunday
    $month = (int) $dt->format('n');

    // Natural variation in customer volume
    // Rainy/typhoon days in July (7) and August (8)
    $isRainySeason = ($month === 7 || $month === 8);
    $isSlowRainyDay = $isRainySeason && (mt_rand(1, 100) <= 22);

    if ($isSlowRainyDay) {
        $dailyCustomerCount = mt_rand(18, 25); // rainy slowdown
    } elseif ($dayOfWeek >= 5) {
        $dailyCustomerCount = mt_rand(44, 56); // weekend rush (Fri-Sun)
    } else {
        $dailyCustomerCount = mt_rand(30, 42); // regular weekday
    }

    // Generate timestamps distributed realistically through the day
    $minuteOffsets = [];
    for ($i = 0; $i < $dailyCustomerCount; $i++) {
        // Operational hours: 06:00 (minute 360) to 22:30 (minute 1350)
        // Weightings:
        // Morning peak 07:00 - 10:30 (minutes 420 - 630): weight 35%
        // Midday 10:30 - 15:30 (minutes 630 - 930): weight 20%
        // Afternoon/Evening peak 15:30 - 19:30 (minutes 930 - 1170): weight 35%
        // Night 19:30 - 22:30 (minutes 1170 - 1350): weight 10%
        $bucket = mt_rand(1, 100);
        if ($bucket <= 35) {
            $m = mt_rand(420, 630);
        } elseif ($bucket <= 55) {
            $m = mt_rand(631, 929);
        } elseif ($bucket <= 90) {
            $m = mt_rand(930, 1170);
        } else {
            $m = mt_rand(1171, 1350);
        }
        $minuteOffsets[] = $m;
    }
    sort($minuteOffsets);

    foreach ($minuteOffsets as $mOffset) {
        $hours = intdiv($mOffset, 60);
        $mins = $mOffset % 60;
        $secs = mt_rand(0, 59);
        $createdAtStr = sprintf('%s %02d:%02d:%02d', $curDateStr, $hours, $mins, $secs);

        // Don't generate future transactions if date is today and time > now
        if ($curDateStr === '2026-10-12' && $mOffset > 1080) { // 18:00
            continue;
        }

        // Service distribution: Water 65%, Soap 35%
        $isWater = (mt_rand(1, 100) <= 65);
        $serviceId = $isWater ? (int)$waterService['id'] : (int)$soapService['id'];
        $serviceKey = $isWater ? 'water' : 'soap';

        // Coin value at purchase = 5.00, Service seconds at purchase = 120
        $coinVal = 5.00;
        $svcSecs = 120;

        // Realistic Philippine Peso amounts:
        // ₱5.00 (45%), ₱10.00 (40%), ₱15.00 (10%), ₱20.00 (5%)
        $amtRoll = mt_rand(1, 100);
        if ($amtRoll <= 45) {
            $amount = 5.00;
        } elseif ($amtRoll <= 85) {
            $amount = 10.00;
        } elseif ($amtRoll <= 95) {
            $amount = 15.00;
        } else {
            $amount = 20.00;
        }

        $runtimeGenerated = (int) (($amount / $coinVal) * $svcSecs);

        // Status distribution: ~96% completed, ~2% incomplete, ~1% interrupted, ~1% cancelled
        $statusRoll = mt_rand(1, 1000);
        if ($statusRoll <= 960) {
            $status = 'completed';
            $actualRuntime = $runtimeGenerated;
            $statusReason = null;
        } elseif ($statusRoll <= 980) {
            $status = 'incomplete';
            $actualRuntime = max(20, $runtimeGenerated - mt_rand(25, 60));
            $statusReason = 'Customer ended cycle early';
        } elseif ($statusRoll <= 990) {
            $status = 'interrupted';
            $actualRuntime = max(15, $runtimeGenerated - mt_rand(40, 80));
            $statusReason = 'Hardware safety cutoff triggered';
        } else {
            $status = 'cancelled';
            $actualRuntime = mt_rand(0, 10);
            $statusReason = 'User cancelled before delivery';
        }

        $completedAtStr = date('Y-m-d H:i:s', strtotime($createdAtStr) + $actualRuntime);

        // Realistic power telemetry
        if ($isWater) {
            $avgCurrent  = round(3.40 + (mt_rand(0, 80) / 100), 2); // 3.40 - 4.20 A
            $peakCurrent = round(5.80 + (mt_rand(0, 95) / 100), 2); // 5.80 - 6.75 A
            $avgWatts    = round(740.0 + mt_rand(0, 180), 1);      // 740 - 920 W
            $peakWatts   = round(1350.0 + mt_rand(0, 180), 1);     // 1350 - 1530 W
        } else {
            $avgCurrent  = round(0.95 + (mt_rand(0, 45) / 100), 2); // 0.95 - 1.40 A
            $peakCurrent = round(2.10 + (mt_rand(0, 60) / 100), 2); // 2.10 - 2.70 A
            $avgWatts    = round(210.0 + mt_rand(0, 95), 1);       // 210 - 305 W
            $peakWatts   = round(470.0 + mt_rand(0, 110), 1);      // 470 - 580 W
        }

        $clientTxId = sprintf('DEMO-TX-%s-%05d', $dt->format('Ymd'), $txCounter++);

        $valuesClauses[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params[] = $machineId;
        $params[] = $clientTxId;
        $params[] = $serviceId;
        $params[] = $amount;
        $params[] = $coinVal;
        $params[] = $svcSecs;
        $params[] = $runtimeGenerated;
        $params[] = $actualRuntime;
        $params[] = $status;
        $params[] = $statusReason;
        $params[] = $avgCurrent;
        $params[] = $peakCurrent;
        $params[] = $avgWatts;
        $params[] = $peakWatts;
        $params[] = $completedAtStr;
        $params[] = $createdAtStr;

        $totalGeneratedTx++;
        if ($status === 'completed') {
            $totalCompletedTx++;
            $totalRevenue += $amount;
            if ($isWater) {
                $totalWaterTx++;
                $waterRevenue += $amount;
            } else {
                $totalSoapTx++;
                $soapRevenue += $amount;
            }
            $completedTransactionsByDate[] = [
                'amount' => $amount,
                'created_at' => $createdAtStr,
                'timestamp' => strtotime($createdAtStr),
            ];
        }

        // Insert in batches of 400 rows
        if (count($valuesClauses) >= 400) {
            $stmt = $pdo->prepare($insertSql . implode(', ', $valuesClauses));
            $stmt->execute($params);
            $valuesClauses = [];
            $params = [];
        }
    }
}

// Flush remaining rows
if (!empty($valuesClauses)) {
    $stmt = $pdo->prepare($insertSql . implode(', ', $valuesClauses));
    $stmt->execute($params);
    $valuesClauses = [];
    $params = [];
}

$pdo->commit();

echo "-> Successfully seeded {$totalGeneratedTx} demo transactions!\n";
echo "   Completed: {$totalCompletedTx} (" . round(($totalCompletedTx / $totalGeneratedTx) * 100, 1) . "%)\n";
echo "   Total Completed Revenue: ₱" . number_format($totalRevenue, 2) . "\n";
echo "   Water Sales: ₱" . number_format($waterRevenue, 2) . " ({$totalWaterTx} tx)\n";
echo "   Soap Sales: ₱" . number_format($soapRevenue, 2) . " (" . ($totalCompletedTx - $totalWaterTx) . " tx)\n\n";

// ------------------------------------------------------------
// 4. Generate Realistic Cash Collections Every 3-6 Days
// ------------------------------------------------------------
echo "Generating matching Cash Collection records across the 6 months...\n";

// Sort completed transactions by timestamp
usort($completedTransactionsByDate, function ($a, $b) {
    return $a['timestamp'] <=> $b['timestamp'];
});

// Generate schedule of collection dates (every 3 to 5 days) across the 6-month period
$collectionSchedule = [];
$cDt = new DateTime('2026-04-16 21:00:00'); // first routine collection
$endDt = new DateTime('2026-10-12 18:00:00');
$intervals = [3, 4, 3, 5, 4, 4, 3, 5];
$intIdx = 0;

while ($cDt < $endDt) {
    $collectionSchedule[] = clone $cDt;
    $days = $intervals[$intIdx % count($intervals)];
    $intIdx++;
    $cDt->modify("+{$days} days");
    $collectionHour = (mt_rand(1, 100) <= 75) ? mt_rand(20, 21) : 8;
    $cDt->setTime($collectionHour, mt_rand(10, 50), 0);
}
// Add final collection up to end date
$collectionSchedule[] = clone $endDt;

$txIndex = 0;
$totalTxCount = count($completedTransactionsByDate);

$collectionCounter = 1;
$totalPhysicalCash = 0.0;
$totalSystemSales = 0.0;
$totalDiscrepancies = 0;

$ccInsertStmt = $pdo->prepare("INSERT INTO cash_collections (
    user_id, machine_id, collected_amount, system_sales_amount,
    variance_amount, notes, collected_at, created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($collectionSchedule as $schedDt) {
    $actualCollectionTimestamp = $schedDt->getTimestamp();
    $collectionDatetimeStr = $schedDt->format('Y-m-d H:i:s');

    // Sum all completed transactions in this collection window
    $windowSales = 0.0;
    $txInWindow = 0;
    while ($txIndex < $totalTxCount && $completedTransactionsByDate[$txIndex]['timestamp'] <= $actualCollectionTimestamp) {
        $windowSales += $completedTransactionsByDate[$txIndex]['amount'];
        $txInWindow++;
        $txIndex++;
    }

    if ($txInWindow === 0) {
        continue;
    }

    // Variance calculation:
    // 80% perfect match (₱0.00)
    // 14% small surplus (+₱5.00 or +₱10.00)
    // 6% small shortage (-₱5.00 or -₱10.00)
    $varRoll = mt_rand(1, 100);
    if ($varRoll <= 80) {
        $variance = 0.00;
        $collectedAmount = $windowSales;
        $note = sprintf('[Demo] Routine coin box clearance #%03d. Count matches system sales exactly (₱%s).',
            $collectionCounter, number_format($windowSales, 2));
    } elseif ($varRoll <= 94) {
        $variance = (mt_rand(1, 10) <= 8) ? 5.00 : 10.00;
        $collectedAmount = $windowSales + $variance;
        $totalDiscrepancies++;
        $note = sprintf('[Demo] Routine coin box clearance #%03d. +₱%s surplus from unexpended inserted coins.',
            $collectionCounter, number_format($variance, 2));
    } else {
        $variance = (mt_rand(1, 10) <= 8) ? -5.00 : -10.00;
        $collectedAmount = max(0.00, $windowSales + $variance);
        $totalDiscrepancies++;
        $note = sprintf('[Demo] Routine coin box clearance #%03d. -₱%s shortage (1 damaged coin diverted to reject chute).',
            $collectionCounter, number_format(abs($variance), 2));
    }

    // Recorded by Business Owner (user_id = 2) in 85% of cases, Admin (user_id = 1) in 15%
    $recordedByUserId = (mt_rand(1, 100) <= 85) ? 2 : 1;

    $ccInsertStmt->execute([
        $recordedByUserId,
        $machineId,
        $collectedAmount,
        $windowSales,
        $variance,
        $note,
        $collectionDatetimeStr,
        $collectionDatetimeStr,
    ]);

    $totalPhysicalCash += $collectedAmount;
    $totalSystemSales += $windowSales;
    $collectionCounter++;
}

echo "-> Successfully seeded " . ($collectionCounter - 1) . " cash collection records!\n";
echo "   Total Physical Cash Collected: ₱" . number_format($totalPhysicalCash, 2) . "\n";
echo "   Total System Sales Target: ₱" . number_format($totalSystemSales, 2) . "\n";
echo "   Overall Net Variance: ₱" . number_format($totalPhysicalCash - $totalSystemSales, 2) . "\n";
echo "   Collections with small discrepancy: {$totalDiscrepancies}\n\n";

echo "🎉 6-MONTH HISTORY DEMO SEEDING COMPLETED SUCCESSFULLY!\n";
