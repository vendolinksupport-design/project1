<?php
/**
 * ============================================================
 * VendoLink — Operational Activity History API Endpoint
 * ============================================================
 *
 * Provides filtered operational activity history logs for the
 * Machine & Services owner dashboard modal.
 *
 * Returns JSON list of non-financial machine operational events.
 *
 * @package VendoLink
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/Machine.php';

require_role([ROLE_ADMIN, ROLE_OWNER]);

$category = trim($_GET['category'] ?? 'all');
$limit    = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT) ?: 20;

if ($category === 'all') {
    $category = null;
}

$ownerId      = current_owner_id();
$machineModel = new Machine();
$events       = $machineModel->getOperationalActivity($limit, $category, $ownerId);

http_response_code(200);
echo json_encode([
    'success' => true,
    'count'   => count($events),
    'events'  => $events
]);
