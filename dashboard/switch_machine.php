<?php
/**
 * ============================================================
 * VendoLink — Machine Context Switcher
 * ============================================================
 *
 * Securely switches the active machine selection for the authenticated
 * owner context with strict multi-tenant validation.
 *
 * @package VendoLink
 * @version 1.0.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Machine.php';

require_auth();

$ownerId = current_owner_id();
$machineId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$returnTo = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''));

$machineModel = new Machine();
$allowedIds   = $machineModel->getMachineIdsForOwner($ownerId);

if ($machineId > 0 && ($ownerId === null || in_array($machineId, $allowedIds, true))) {
    $_SESSION['selected_machine_id'] = $machineId;
    $targetMachine = $machineModel->findById($machineId);
    $name = !empty($targetMachine['name']) ? $targetMachine['name'] : (!empty($targetMachine['machine_code']) ? $targetMachine['machine_code'] : "Unit #{$machineId}");
    flash('info', "Switched active view to <strong>" . e($name) . "</strong>.");
} else {
    flash('warning', "Unable to switch: Specified machine is not available.");
}

// Determine safe redirect URL
$fallbackUrl = BASE_URL . '/dashboard/machines.php';
$redirectUrl = $fallbackUrl;

if (!empty($returnTo)) {
    // If it's a full URL within this host
    if (str_starts_with($returnTo, BASE_URL)) {
        $redirectUrl = $returnTo;
    } elseif (str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//') && !str_contains($returnTo, ':')) {
        $redirectUrl = $returnTo;
    }
}

redirect($redirectUrl);
