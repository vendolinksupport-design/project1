<?php
/**
 * ============================================================
 * VendoLink — Real ESP32 Machine Heartbeat API Endpoint
 * ============================================================
 *
 * Receives periodic telemetry from physical ESP32 controllers.
 * Authenticates requests via X-Machine-Token header or payload token,
 * validates machine_key identity, updates last_seen timestamp, IP address,
 * and firmware version.
 *
 * Request Format (POST JSON):
 * Header: X-Machine-Token: <token> (or "api_token" in JSON body)
 * Body:
 * {
 *   "machine_key": "machine_01",
 *   "status": "online",
 *   "ip_address": "192.168.1.120",
 *   "firmware_version": "1.0.0"
 * }
 *
 * @package VendoLink
 * @version 2.0
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../models/Machine.php';

// 1. Validate HTTP Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Method Not Allowed. Only POST requests are accepted.'
    ]);
    exit;
}

// 2. Parse Raw JSON Input Payload
$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true);

if (!is_array($data)) {
    // Fallback to standard POST data if raw JSON is not provided
    $data = $_POST;
}

$machineKey      = trim($data['machine_key'] ?? '');
$status          = trim($data['status'] ?? 'online');
$ipAddress       = trim($data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
$firmwareVersion = trim($data['firmware_version'] ?? '');

// 3. Extract Security Token (Header priority, fallback to body)
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headerToken = $headers['X-Machine-Token']
    ?? $headers['x-machine-token']
    ?? $_SERVER['HTTP_X_MACHINE_TOKEN']
    ?? '';
$token = !empty($headerToken) ? trim($headerToken) : trim($data['api_token'] ?? '');

// 4. Validate Mandatory Fields
if (empty($machineKey)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Missing required parameter: machine_key'
    ]);
    exit;
}

$machineModel = new Machine();

// 5. Validate Machine Identity Existence
$machine = $machineModel->findByKey($machineKey);
if (!$machine) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error'   => "Machine identity '{$machineKey}' not found."
    ]);
    exit;
}

// 6. Authenticate Security Token
if (empty($token) || !$machineModel->verifyApiToken($machineKey, $token)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid or missing machine authentication token.'
    ]);
    exit;
}

// Sanitize status to allowed database ENUM values
$allowedStatuses = ['online', 'offline', 'unknown', 'pending'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'online';
}

// 7. Update Telemetry & Timestamp in Database
$updated = $machineModel->recordHeartbeat(
    $machineKey,
    $ipAddress,
    $firmwareVersion,
    $status
);

if (!$updated) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to update machine heartbeat record in database.'
    ]);
    exit;
}

// Check for active interrupted session telemetry reported during recovery mode
if (!empty($data['recovery_mode']) && !empty($data['interrupted_session']) && is_array($data['interrupted_session'])) {
    $sess = $data['interrupted_session'];
    $sessTxId   = trim($sess['client_transaction_id'] ?? '');
    $sessKey    = trim($sess['service_key'] ?? 'water');
    $sessCost   = filter_var($sess['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
    $sessTotal  = (int) ($sess['total_seconds'] ?? 120);
    $sessRem    = (int) ($sess['remaining_seconds'] ?? 0);
    $sessActual = max(0, $sessTotal - $sessRem);

    if (!empty($sessTxId) && $sessCost > 0) {
        $db = Database::getInstance();
        $targetSvc = $db->query(
            "SELECT id FROM services WHERE machine_id = :mid AND service_key = :skey LIMIT 1",
            [':mid' => $machine['id'], ':skey' => $sessKey]
        )->fetch();

        if ($targetSvc) {
            require_once ROOT_PATH . '/models/Transaction.php';
            $txModel = new Transaction();
            $txModel->record(
                (int)$targetSvc['id'],
                $sessCost,
                'interrupted',
                $sessTxId,
                'Power interruption — awaiting recovery',
                $sessActual
            );
        }
    }
}

// 8. Fetch Active Services & Rates for Controller Config Sync
$db = Database::getInstance();
$services = $db->query(
    'SELECT id, service_key, name, is_enabled, coin_value, service_seconds FROM services WHERE machine_id = :mid ORDER BY FIELD(service_key, "water", "soap"), id',
    [':mid' => $machine['id']]
)->fetchAll();

// 9. Check for Pending Wireless OTA Firmware Update
require_once ROOT_PATH . '/models/AdminDashboard.php';
$dashModel = new AdminDashboard();
$currentFw = !empty($firmwareVersion) ? $firmwareVersion : ($machine['firmware_version'] ?? '3.0.0');
$pendingOta = $dashModel->checkPendingOta((int)$machine['id'], !empty($machine['owner_id']) ? (int)$machine['owner_id'] : null, $currentFw);

$otaPayload = null;
if ($pendingOta) {
    $otaPayload = [
        'available'      => true,
        'deployment_id'  => (int)$pendingOta['deployment_id'],
        'target_version' => $pendingOta['version'],
        'download_url'   => BASE_URL . '/uploads/firmware/' . rawurlencode($pendingOta['filename']),
        'filesize'       => (int)$pendingOta['filesize'],
        'changelog'      => $pendingOta['changelog'] ?? ''
    ];
}

// 10. Return Successful Telemetry Response
$mName = trim($machine['name'] ?? $machine['machine_code'] ?? 'Vendo Machine');
$cleanName = strtoupper(preg_replace('/[^a-zA-Z0-9\s]/', '', $mName));
$cleanName = trim(substr($cleanName, 0, 14));
$defaultLine1 = '== ' . $cleanName . ' ==';

$line1 = (!empty($machine['lcd_line1']) && $machine['lcd_line1'] !== '== VendoLink v2.2 ==' && $machine['lcd_line1'] !== '== VendoLink v3.0 ==')
    ? $machine['lcd_line1']
    : $defaultLine1;

http_response_code(200);
$now = date('Y-m-d H:i:s');
echo json_encode([
    'success'          => true,
    'message'          => 'Heartbeat recorded successfully',
    'machine_key'      => $machineKey,
    'machine_code'     => $machine['machine_code'],
    'display_name'     => $machine['name'] ?? $machine['machine_code'],
    'machine_type'     => $machine['machine_type'] ?? 'carwash',
    'status'           => $status,
    'ip_address'       => $ipAddress,
    'firmware_version' => $currentFw,
    'last_seen'        => $now,
    'server_time'      => $now,
    'lcd_custom'       => [
        'line1' => $line1,
        'line2' => !empty($machine['lcd_line2']) ? $machine['lcd_line2'] : 'WELCOME CUSTOMER!',
        'line3' => !empty($machine['lcd_line3']) ? $machine['lcd_line3'] : 'INSERT COIN FIRST!',
        'line4' => !empty($machine['lcd_line4']) ? $machine['lcd_line4'] : 'WATER:P5 | SOAP:P5',
    ],
    'services'         => $services,
    'ota_update'       => $otaPayload
], JSON_UNESCAPED_SLASHES);