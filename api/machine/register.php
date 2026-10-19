<?php
/**
 * ============================================================
 * VendoLink — ESP32 Device Automated Self-Enrollment Endpoint
 * ============================================================
 *
 * Authenticates universal ESP32 controllers using the shared enrollment secret,
 * registers newly provisioned hardware using its factory eFuse MAC address,
 * issues unique per-device machine keys and API security tokens, and seeds
 * initial machine services.
 *
 * Request Format (POST JSON):
 * Header: X-Enrollment-Token: <DEVICE_ENROLLMENT_SECRET>
 * Body:
 * {
 *   "device_mac": "24:6F:28:A1:B2:C3",
 *   "firmware_version": "3.0.0",
 *   "model": "ESP32-WROOM-32",
 *   "ip_address": "192.168.1.150"
 * }
 *
 * @package VendoLink
 * @version 3.0.0
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

// 3. Extract Bootstrap / Enrollment Credential
$headers = function_exists('getallheaders') ? getallheaders() : [];
$headerToken = $headers['X-Bootstrap-Token']
    ?? $headers['x-bootstrap-token']
    ?? $headers['X-Enrollment-Token']
    ?? $headers['x-enrollment-token']
    ?? $_SERVER['HTTP_X_BOOTSTRAP_TOKEN']
    ?? $_SERVER['HTTP_X_ENROLLMENT_TOKEN']
    ?? '';

$token = !empty($headerToken) ? trim($headerToken) : trim($data['bootstrap_token'] ?? $data['enrollment_token'] ?? '');

// 4. Validate & Normalize Device MAC Address
$rawMac = trim($data['device_mac'] ?? '');
if (empty($rawMac)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Missing required parameter: device_mac'
    ]);
    exit;
}

// Normalize MAC: strip separators, uppercase, check length, re-format
$cleanedMac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $rawMac));
if (strlen($cleanedMac) !== 12) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid MAC address format. Expected 12 hexadecimal characters.'
    ]);
    exit;
}

$deviceMac = implode(':', str_split($cleanedMac, 2));

// 5. Authenticate Per-Device Bootstrap Credential
$isAuthorized = false;
if (!empty($token) && Machine::verifyBootstrapToken($deviceMac, $token)) {
    $isAuthorized = true;
} elseif (
    defined('APP_ENV') && APP_ENV === 'development' &&
    (
        (!empty($_SERVER['HTTP_USER_AGENT']) && stripos($_SERVER['HTTP_USER_AGENT'], 'ESP32') !== false) ||
        (!empty($_SERVER['REMOTE_ADDR']) && preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $_SERVER['REMOTE_ADDR']))
    ) &&
    ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1')
) {
    // Zero-touch enrollment for physical ESP32 controller on local development network
    $isAuthorized = true;
}

if (!$isAuthorized) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized. Invalid or missing device bootstrap credential.'
    ]);
    exit;
}

$firmwareVersion = trim($data['firmware_version'] ?? '3.0.0');
$ipAddress       = trim($data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

$db  = Database::getInstance();
$pdo = $db->getConnection();

// 5. Check if Device Already Exists in Database
$stmt = $pdo->prepare("SELECT * FROM machines WHERE device_mac = :mac LIMIT 1");
$stmt->execute([':mac' => $deviceMac]);
$existingMachine = $stmt->fetch();

if ($existingMachine) {
    // Device already enrolled
    if ($existingMachine['registration_status'] === 'disabled') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Device has been disabled by administrator.'
        ]);
        exit;
    }

    // Refresh telemetry for existing enrolled device
    $upd = $pdo->prepare(
        "UPDATE machines 
         SET last_seen = NOW(),
             ip_address = COALESCE(NULLIF(:ip, ''), ip_address),
             firmware_version = COALESCE(NULLIF(:fw, ''), firmware_version),
             status = 'online',
             updated_at = NOW()
         WHERE id = :id"
    );
    $upd->execute([
        ':ip' => $ipAddress,
        ':fw' => $firmwareVersion,
        ':id' => $existingMachine['id']
    ]);

    http_response_code(200);
    echo json_encode([
        'success'             => true,
        'enrolled'            => false,
        'message'             => 'Device already enrolled.',
        'machine_id'          => (int)$existingMachine['id'],
        'machine_key'         => $existingMachine['machine_key'],
        'machine_code'        => $existingMachine['machine_code'],
        'api_token'           => $existingMachine['api_token'],
        'registration_status' => $existingMachine['registration_status'],
        'device_mac'          => $deviceMac
    ]);
    exit;
}

// Check if an unlinked machine claimed by an owner is awaiting physical hardware
$unlinkedStmt = $pdo->prepare(
    "SELECT * FROM machines 
     WHERE (device_mac IS NULL OR device_mac = '') 
       AND owner_id IS NOT NULL 
     ORDER BY id ASC 
     LIMIT 1"
);
$unlinkedStmt->execute();
$unlinkedMachine = $unlinkedStmt->fetch();

if ($unlinkedMachine) {
    $machineId   = (int)$unlinkedMachine['id'];
    $machineKey  = $unlinkedMachine['machine_key'];
    $machineCode = $unlinkedMachine['machine_code'];
    $apiToken    = !empty($unlinkedMachine['api_token']) ? $unlinkedMachine['api_token'] : ('vl_dev_sec_' . bin2hex(random_bytes(16)));

    $upd = $pdo->prepare(
        "UPDATE machines 
         SET device_mac = :mac,
             api_token = :token,
             ip_address = COALESCE(NULLIF(:ip, ''), ip_address),
             firmware_version = COALESCE(NULLIF(:fw, ''), firmware_version),
             status = 'online',
             last_seen = NOW(),
             updated_at = NOW()
         WHERE id = :id"
    );
    $upd->execute([
        ':mac'   => $deviceMac,
        ':token' => $apiToken,
        ':ip'    => $ipAddress,
        ':fw'    => $firmwareVersion,
        ':id'    => $machineId
    ]);

    // Ensure default water & soap services exist
    $svcCheck = $pdo->prepare("SELECT COUNT(*) FROM services WHERE machine_id = :mid");
    $svcCheck->execute([':mid' => $machineId]);
    if ((int)$svcCheck->fetchColumn() === 0) {
        $svc = $pdo->prepare(
            "INSERT INTO services (machine_id, service_key, name, type, coin_value, service_seconds, is_enabled, enabled)
             VALUES (?, 'water', 'Water Service', 'water', 5.00, 120, 1, 1),
                    (?, 'soap',  'Soap Service',  'soap',  5.00, 60, 1, 1)"
        );
        $svc->execute([$machineId, $machineId]);
    }

    log_activity('DEVICE_PAIRED', "Universal ESP32 MAC {$deviceMac} paired to machine {$machineCode} (#{$machineId}).", null);

    http_response_code(200);
    echo json_encode([
        'success'             => true,
        'enrolled'            => true,
        'message'             => "Device successfully paired to machine {$machineCode}.",
        'machine_id'          => $machineId,
        'machine_key'         => $machineKey,
        'machine_code'        => $machineCode,
        'api_token'           => $apiToken,
        'registration_status' => $unlinkedMachine['registration_status'],
        'device_mac'          => $deviceMac
    ]);
    exit;
}

// 6. Generate Unique Machine Credentials for New Device
$macSuffix4 = substr($cleanedMac, -4);
$macSuffix6 = strtolower(substr($cleanedMac, -6));

// Generate unique machine_code (e.g. MCH-A1B2)
$machineCode = 'MCH-' . $macSuffix4;
$checkCode = $pdo->prepare("SELECT COUNT(*) FROM machines WHERE machine_code = :code");
$checkCode->execute([':code' => $machineCode]);
if ((int)$checkCode->fetchColumn() > 0) {
    $machineCode = 'MCH-' . $macSuffix4 . '-' . strtoupper(bin2hex(random_bytes(1)));
}

// Generate unique machine_key (e.g. mch_a1b2c3_7f9c)
$machineKey = 'mch_' . $macSuffix6 . '_' . bin2hex(random_bytes(2));

// Generate strong random API token
$apiToken = 'vl_dev_sec_' . bin2hex(random_bytes(16));

$machineName = 'Vendo Machine ' . $macSuffix4;

// 7. Insert New Machine Record Atomically
try {
    $pdo->beginTransaction();

    $ins = $pdo->prepare(
        "INSERT INTO machines (
            owner_id, machine_code, machine_key, name, api_token, device_mac,
            registration_status, status, ip_address, firmware_version,
            last_seen, created_at, updated_at
        ) VALUES (
            NULL, :code, :key, :name, :token, :mac,
            'registered', 'online', :ip, :fw,
            NOW(), NOW(), NOW()
        )"
    );

    $ins->execute([
        ':code'  => $machineCode,
        ':key'   => $machineKey,
        ':name'  => $machineName,
        ':token' => $apiToken,
        ':mac'   => $deviceMac,
        ':ip'    => $ipAddress,
        ':fw'    => $firmwareVersion
    ]);

    $machineId = (int)$pdo->lastInsertId();

    // 8. Seed Default Water & Soap Services
    $svc = $pdo->prepare(
        "INSERT INTO services (machine_id, service_key, name, type, coin_value, service_seconds, is_enabled, enabled)
         VALUES (?, 'water', 'Water Service', 'water', 5.00, 120, 1, 1),
                (?, 'soap',  'Soap Service',  'soap',  5.00, 60, 1, 1)"
    );
    $svc->execute([$machineId, $machineId]);

    $pdo->commit();

    log_activity('DEVICE_ENROLLED', "Universal ESP32 enrolled: MAC {$deviceMac} as {$machineCode} (#{$machineId}).", null);

    http_response_code(201);
    echo json_encode([
        'success'             => true,
        'enrolled'            => true,
        'message'             => 'Device successfully enrolled into VendoLink.',
        'machine_id'          => $machineId,
        'machine_key'         => $machineKey,
        'machine_code'        => $machineCode,
        'api_token'           => $apiToken,
        'registration_status' => 'registered',
        'device_mac'          => $deviceMac
    ]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error during device enrollment: ' . $e->getMessage()
    ]);
    exit;
}
