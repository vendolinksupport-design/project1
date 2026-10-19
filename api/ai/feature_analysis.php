<?php
/**
 * ============================================================
 * VendoLink — VendoAI Feature Analysis API Endpoint
 * ============================================================
 *
 * Protected AJAX route.
 * Receives feature key ('sales', 'maintenance', 'refill'),
 * queries AiAssistant model (securely querying Google Gemini or
 * deterministic grounded telemetry), and returns structured JSON.
 *
 * @package VendoLink
 * @version 3.0
 */

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/AiAssistant.php';

// Must be logged in
require_auth();

$inputRaw = file_get_contents('php://input');
$data = json_decode($inputRaw, true) ?? [];
$feature = trim($_POST['feature'] ?? $data['feature'] ?? $_GET['feature'] ?? 'refill');

if (!in_array($feature, ['sales', 'maintenance', 'refill'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid feature. Must be sales, maintenance, or refill.'
    ]);
    exit;
}

$ownerId = current_owner_id();
$ai = new AiAssistant();
$response = $ai->getFeatureAnalysis($feature, $ownerId);

echo json_encode($response);
