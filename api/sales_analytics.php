<?php
/**
 * ============================================================
 * VendoLink — Sales Overview Analytics API Endpoint
 * ============================================================
 *
 * Provides real-time dynamic sales analytics, time-series data,
 * and period-over-period comparison metrics for the Sales Overview graph
 * and InsightAI forecasting models.
 *
 * @package VendoLink
 * @version 1.0
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Report.php';

// Auth Guard
require_auth();

$period    = trim($_GET['period'] ?? 'today');
$machineId = isset($_GET['machine']) && $_GET['machine'] !== 'all' && $_GET['machine'] !== '' ? (int)$_GET['machine'] : (isset($_GET['machine_id']) && $_GET['machine_id'] !== 'all' && $_GET['machine_id'] !== '' ? (int)$_GET['machine_id'] : null);
$service   = trim($_GET['service'] ?? 'all');
$ownerId   = current_owner_id();

try {
    $reportModel = new Report();
    $analytics   = $reportModel->getSalesAnalytics($period, $ownerId, $machineId, $service);

    echo json_encode([
        'success' => true,
        'data'    => $analytics,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to calculate sales analytics: ' . $e->getMessage(),
    ]);
}
