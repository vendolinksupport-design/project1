<?php
/**
 * ============================================================
 * VendoLink — Real-Time Machine & Controller Status API
 * ============================================================
 *
 * Provides lightweight live telemetry status for the Dashboard
 * Machine & Controller Status panel, including ESP32 connectivity,
 * relay outlet operational states (idle vs. running), countdowns,
 * coin slot power status, and today's accumulated hardware runtime.
 *
 * @package VendoLink
 * @version 2.5
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Machine.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Transaction.php';

// Auth Guard
require_auth();

$ownerId = current_owner_id();
$today   = date('Y-m-d');

try {
    $machineModel = new Machine();
    $reportModel  = new Report();

    $machine = current_selected_machine($ownerId) ?: $machineModel->primaryWithServices($ownerId);
    if (!$machine) {
        echo json_encode([
            'success' => false,
            'message' => 'No active machine found.'
        ]);
        exit;
    }

    $mStatus     = $machine['calculated_status'] ?? 'offline';
    $isConnected = ($mStatus === 'online');
    $machType    = $machine['machine_type'] ?? 'carwash';

    // Fetch Today's metrics for hardware runtime
    $todayMetrics = $reportModel->getSummaryMetrics($today, $today, 'all', $ownerId);
    $todayRuntimeSecs = (int)($todayMetrics['total_purchased_seconds'] ?? 0);
    $todayRuntimeFormatted = Transaction::formatDuration($todayRuntimeSecs);

    // Services (Outlet 1 & Outlet 2)
    $servicesByKey = [];
    if (!empty($machine['services'])) {
        foreach ($machine['services'] as $svc) {
            $servicesByKey[$svc['service_key']] = $svc;
        }
    }

    $waterSvc     = $servicesByKey['water'] ?? ($machine['services'][0] ?? null);
    $soapSvc      = $servicesByKey['soap']  ?? ($machine['services'][1] ?? null);
    $waterEnabled = !empty($waterSvc['is_enabled']);
    $soapEnabled  = !empty($soapSvc['is_enabled']);
    $waterTankLow = !empty($machine['water_tank_low']);

    // Coin slot logic
    if ($machType === 'carwash') {
        $coinSlotPowered = $isConnected && $waterEnabled && !$waterTankLow;
    } else {
        $coinSlotPowered = $isConnected && ($waterEnabled || $soapEnabled);
    }

    $outlet1Running = !empty($waterSvc['is_running']);
    $outlet1Secs    = (int)($waterSvc['remaining_runtime_seconds'] ?? 0);

    $outlet2Running = !empty($soapSvc['is_running']);
    $outlet2Secs    = (int)($soapSvc['remaining_runtime_seconds'] ?? 0);

    $isAnyDispensing = ($outlet1Running || $outlet2Running);

    echo json_encode([
        'success'         => true,
        'machine_id'      => (int)$machine['id'],
        'machine_code'    => $machine['machine_code'] ?? 'MACHINE-01',
        'status'          => $mStatus,
        'is_connected'    => $isConnected,
        'is_dispensing'   => $isAnyDispensing,
        'ip_address'      => $machine['ip_address'] ?? '192.168.x.x',
        'firmware'        => $machine['firmware_version'] ?? '1.5.0',
        'last_seen'       => !empty($machine['last_seen']) ? time_ago($machine['last_seen']) : 'Never',
        'coin_slot'       => [
            'powered' => $coinSlotPowered,
            'label'   => $isConnected ? ($coinSlotPowered ? '12V Powered' : 'Disabled') : 'OFF',
            'subtext' => $isConnected 
                ? ($coinSlotPowered ? 'HW-482 relay closed - 12V coin acceptance active' : '12V supply cut off')
                : 'No controller heartbeat - 12V coin power disconnected'
        ],
        'outlet1'         => [
            'key'               => $waterSvc['service_key'] ?? 'water',
            'name'              => $waterSvc['name'] ?? 'Outlet 1',
            'is_running'        => $outlet1Running,
            'state'             => $isConnected ? ($outlet1Running ? 'RUNNING' : 'STANDBY (IDLE)') : 'DE-ENERGIZED (OFF)',
            'remaining_seconds' => $outlet1Secs,
            'remaining_clock'   => sprintf('%02d:%02d', intdiv($outlet1Secs, 60), $outlet1Secs % 60),
        ],
        'outlet2'         => [
            'key'               => $soapSvc['service_key'] ?? 'soap',
            'name'              => $soapSvc['name'] ?? 'Outlet 2',
            'is_running'        => $outlet2Running,
            'state'             => $isConnected ? ($outlet2Running ? 'RUNNING' : 'STANDBY (IDLE)') : 'DE-ENERGIZED (OFF)',
            'remaining_seconds' => $outlet2Secs,
            'remaining_clock'   => sprintf('%02d:%02d', intdiv($outlet2Secs, 60), $outlet2Secs % 60),
        ],
        'runtime'         => [
            'today_seconds'   => $todayRuntimeSecs,
            'today_formatted' => $isConnected ? ($todayRuntimeFormatted ?: '0m 00s') : 'Unavailable (Awaiting ESP32)',
            'is_available'    => $isConnected
        ]
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Status error: ' . $e->getMessage()
    ]);
}
