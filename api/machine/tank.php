<?php
/**
 * ============================================================
 * VendoLink — Real-Time Low Tank Level API Endpoint
 * ============================================================
 *
 * Receives edge-triggered hardware floater sensor alerts from ESP32.
 * Authenticates requests via X-Machine-Token header or payload token.
 * Updates machine water_tank_low / soap_tank_low status and generates
 * system notifications & dashboard alerts with strict de-duplication.
 *
 * Request Format (POST JSON):
 * Header: X-Machine-Token: <token> (or "api_token" in JSON body)
 * Body:
 * {
 *   "machine_key": "machine_01",
 *   "tank_key": "water",
 *   "status": "low"
 * }
 *
 * @package VendoLink
 * @version 2.2
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

$machineKey = trim($data['machine_key'] ?? '');
$tankKey    = trim($data['tank_key'] ?? '');
$status     = trim($data['status'] ?? 'low');

// 3. Extract Security Token (Header priority, fallback to body)
$headers = getallheaders();
$headerToken = $headers['X-Machine-Token'] ?? $headers['x-machine-token'] ?? '';
$token = !empty($headerToken) ? trim($headerToken) : trim($data['api_token'] ?? '');

// 4. Validate Mandatory Parameters
if (empty($machineKey) || empty($tankKey)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Missing required parameters: machine_key and tank_key are mandatory.'
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

// 7. Update Tank Status in Database & Generate Notifications
$result = $machineModel->updateTankStatus($machineKey, $tankKey, $status);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

http_response_code(200);
echo json_encode($result);
