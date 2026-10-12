<?php
/**
 * ============================================================
 * VendoLink — VendoAI Floating Chat API Endpoint
 * ============================================================
 *
 * Protected AJAX route.
 * Receives user prompt, queries AiAssistant model, and returns
 * structured JSON response.
 *
 * @package VendoLink
 * @version 2.5
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../models/AiAssistant.php';

// Must be logged in
require_auth();

$inputRaw = file_get_contents('php://input');
$data = json_decode($inputRaw, true) ?? $_POST;

$prompt = trim($data['prompt'] ?? '');
if (empty($prompt)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Prompt cannot be empty.']);
    exit;
}

$ownerId = current_owner_id();
$ai = new AiAssistant();
$response = $ai->ask($prompt, $ownerId);

echo json_encode([
    'success'  => true,
    'answer'   => $response['answer'],
    'category' => $response['category'],
    'timestamp'=> date('g:i A')
]);
