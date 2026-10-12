<?php
/**
 * ============================================================
 * VendoLink — ESP32 Coin Insertion API Endpoint
 * ============================================================
 *
 * Receives coin insertion pulse/button triggers from physical ESP32.
 * Authenticates request via X-Machine-Token header, records transaction,
 * updates service runtime, and updates sales metrics.
 *
 * Request Format (POST JSON):
 * Header: X-Machine-Token: <token>
 * Body:
 * {
 *   "machine_key": "machine_01",
 *   "service_key": "water",  // "water" or "soap"
 *   "amount": 7.00           // optional, defaults to configured service rate
 * }
 *
 * @package VendoLink IoT
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../models/Machine.php';
require_once __DIR__ . '/../../models/Transaction.php';

// 1. Validate HTTP Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Only POST requests accepted.']);
    exit;
}

// 2. Parse Input Payload
$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true) ?: $_POST;

$machineKey = trim($data['machine_key'] ?? 'machine_01');
$serviceKey = trim($data['service_key'] ?? 'water');
$amount     = filter_var($data['amount'] ?? 0, FILTER_VALIDATE_FLOAT);
$clientTxId = trim($data['client_transaction_id'] ?? $data['client_tx_id'] ?? '');
$status     = strtolower(trim($data['status'] ?? 'completed'));
$reason     = trim($data['reason'] ?? $data['status_reason'] ?? '');
$actualSecs = isset($data['actual_runtime_seconds']) ? (int)$data['actual_runtime_seconds'] : null;

// Normalize status
$validStatuses = ['completed', 'incomplete', 'interrupted', 'cancelled', 'failed', 'active', 'pending'];
if (!in_array($status, $validStatuses, true)) {
    $status = 'completed';
}

if ($status === 'incomplete' && empty($reason)) {
    $reason = 'POWER INTERRUPTION — SESSION CANCELLED';
}

// 3. Extract Security Token
$headers = getallheaders();
$headerToken = $headers['X-Machine-Token'] ?? $headers['x-machine-token'] ?? '';
$token = !empty($headerToken) ? trim($headerToken) : trim($data['api_token'] ?? '');

$machineModel = new Machine();

// 4. Validate Machine Identity
$machine = $machineModel->findByKey($machineKey);
if (!$machine) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => "Machine identity '{$machineKey}' not found."]);
    exit;
}

// 5. Authenticate Security Token
if (empty($token) || !$machineModel->verifyApiToken($machineKey, $token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing machine authentication token.']);
    exit;
}

// 6. Find Target Service
$db = Database::getInstance();
$service = $db->query(
    "SELECT * FROM services WHERE machine_id = :mid AND service_key = :skey LIMIT 1",
    [':mid' => $machine['id'], ':skey' => $serviceKey]
)->fetch();

if (!$service) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => "Service key '{$serviceKey}' not found on machine."]);
    exit;
}

// Allan 12V Coin Slot — Fixed valid coin amounts only:
// 1 pulse = ₱5  | 2 pulses = ₱10 | 4 pulses = ₱20
$validCoinAmounts = [5.00, 10.00, 20.00];

// Use the amount sent by ESP32 if it's a valid coin denomination
$amountCentavos = (int) round(($amount ?: 0) * 100);
$validCentavos  = [500, 1000, 2000]; // ₱5, ₱10, ₱20 in centavos

if ($amount && in_array($amountCentavos, $validCentavos, true)) {
    $coinAmount = (float)$amount;
} else {
    // Fallback to minimum ₱5 if amount is missing or invalid
    $coinAmount = 5.00;
}

// 7. Record Transaction in Database
try {
    $txModel = new Transaction();
    $txId = $txModel->record(
        (int)$service['id'],
        $coinAmount,
        $status,
        !empty($clientTxId) ? $clientTxId : null,
        !empty($reason) ? $reason : null,
        $actualSecs
    );

    if ($status === 'completed') {
        log_activity('SERVICE_COMPLETED', "{$service['name']} completed");
    } elseif ($status === 'incomplete') {
        log_activity('SERVICE_CANCELLED', "{$service['name']} interrupted by power interruption and cancelled during recovery.");
    } elseif ($status === 'interrupted') {
        log_activity('SERVICE_INTERRUPTED', "{$service['name']} interrupted by power interruption. Awaiting recovery.");
    }

    http_response_code(200);
    echo json_encode([
        'success'               => true,
        'message'               => ($status === 'incomplete') ? 'Incomplete transaction recorded successfully' : 'Coin transaction recorded successfully',
        'transaction_id'        => $txId,
        'client_transaction_id' => !empty($clientTxId) ? $clientTxId : null,
        'machine_code'          => $machine['machine_code'],
        'service_name'          => $service['name'],
        'amount_paid'           => $coinAmount,
        'status'                => $status,
        'status_reason'         => !empty($reason) ? $reason : null,
        'actual_runtime_seconds'=> $actualSecs,
        'server_time'           => date('Y-m-d H:i:s')
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
