<?php
/**
 * ============================================================
 * VendoLink - Live Machine Cash Collection Balance API
 * ============================================================
 *
 * Returns the live uncollected expected cash balance for a machine,
 * taking into account all completed customer transactions, previous
 * collections, and authorized adjustments.
 *
 * @package VendoLink
 * @version 1.0
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Transaction.php';

// Auth Guard
require_auth();

$machineId = isset($_GET['machine_id']) ? (int)$_GET['machine_id'] : (isset($_GET['machine']) ? (int)$_GET['machine'] : 0);

if ($machineId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Please provide a valid machine ID.',
    ]);
    exit;
}

$user = current_user();
$ownerId = is_admin() ? null : current_owner_id();
$userRole = strtolower(trim((string)($user['role'] ?? '')));
$canOverride = in_array($userRole, ['administrator', 'admin', 'owner'], true);

try {
    $txModel = new Transaction();
    $data = $txModel->getExpectedCashBalance($machineId, $ownerId);

    if (!$data['success']) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => $data['error'] ?? 'Machine not found or access denied.',
        ]);
        exit;
    }

    echo json_encode([
        'success'              => true,
        'machine_id'           => $machineId,
        'expected_balance'     => $data['expected_balance'],
        'formatted_expected'   => '₱' . number_format($data['expected_balance'], 2),
        'eligible_sales'       => $data['eligible_sales'],
        'previous_collections' => $data['previous_collections'],
        'authorized_overrides' => $data['authorized_overrides'],
        'last_collected_at'    => $data['last_collected_at'],
        'can_override'         => $canOverride,
        'user_role'            => $userRole,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Internal server error calculating cash balance: ' . $e->getMessage(),
    ]);
}
