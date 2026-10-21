<?php
/**
 * ============================================================
 * VendoLink — Transactions Pagination API Endpoint
 * ============================================================
 *
 * Provides paginated JSON response of customer vending transactions
 * with search, service filtering, status filtering, and owner isolation.
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

$ownerId = current_owner_id();
if ($ownerId === null && is_admin()) {
    $ownerId = null; // Admin can view platform-wide or specific machine
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 15)));

$search    = trim($_GET['search'] ?? '');
$service   = strtolower(trim($_GET['service'] ?? 'all'));
$status    = strtolower(trim($_GET['status'] ?? 'all'));
$machine   = trim($_GET['machine'] ?? 'all');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to'] ?? '');

$filters = [
    'search'    => $search,
    'service'   => $service,
    'status'    => $status,
    'machine'   => $machine,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
];

try {
    $transactionModel = new Transaction();
    $result = $transactionModel->getPaginated($filters, $page, $perPage, $ownerId);

    $formattedItems = [];
    foreach ($result['items'] as $tx) {
        $txSvcName = !empty($tx['service_name']) ? preg_replace('/\s+Service$/i', '', $tx['service_name']) : '';
        if (empty($txSvcName)) {
            $txSvcName = match (strtolower($tx['service_key'] ?? '')) {
                'water' => 'Water',
                'soap'  => 'Soap',
                default => 'Service',
            };
        }

        $formattedItems[] = [
            'id'                 => (int)$tx['id'],
            'formatted_id'       => $tx['formatted_id'] ?? ('#' . str_pad((string)$tx['id'], 5, '0', STR_PAD_LEFT)),
            'machine_id'         => (int)$tx['machine_id'],
            'machine_name'       => preg_replace('/^VendoLink\s+/i', '', $tx['machine_name'] ?: 'Machine 01'),
            'service_name'       => $txSvcName,
            'service_key'        => strtolower($tx['service_key'] ?? 'water'),
            'amount'             => (float)$tx['amount'],
            'amount_formatted'   => 'PHP ' . number_format((float)$tx['amount'], 2),
            'duration_seconds'   => (int)($tx['duration_seconds'] ?? 0),
            'duration_formatted' => (int)($tx['duration_seconds'] ?? 0) . 's',
            'status'             => strtolower($tx['status'] ?? 'completed'),
            'status_label'       => ucfirst(strtolower($tx['status'] ?? 'completed')),
            'created_at'         => $tx['created_at'],
            'formatted_time'     => !empty($tx['created_at']) ? date('M d, Y h:i A', strtotime($tx['created_at'])) : '—',
        ];
    }

    echo json_encode([
        'success'     => true,
        'items'       => $formattedItems,
        'total_items' => (int)$result['total_items'],
        'total_pages' => (int)$result['total_pages'],
        'page'        => (int)$result['page'],
        'per_page'    => $perPage,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve transactions: ' . $e->getMessage(),
    ]);
}
