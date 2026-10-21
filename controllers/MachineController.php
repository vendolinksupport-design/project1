<?php
/** Processes the Phase 2 Machine & Services settings form. */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Service.php';

class MachineController
{
    /**
     * Unified Single Save: Atomically saves profile template, LCD lines, and Outlet configurations.
     */
    public function saveHardwareUnified(): void
    {
        csrf_verify();

        $machineId = filter_input(INPUT_POST, 'machine_id', FILTER_VALIDATE_INT);
        $template  = trim($_POST['machine_template'] ?? 'carwash');
        $line1     = mb_substr($_POST['lcd_line1'] ?? '', 0, 20);
        $line2     = mb_substr($_POST['lcd_line2'] ?? '', 0, 20);
        $line3     = mb_substr($_POST['lcd_line3'] ?? '', 0, 20);
        $line4     = mb_substr($_POST['lcd_line4'] ?? '', 0, 20);

        if (!$machineId) {
            flash('danger', 'Invalid machine specified.');
            redirect('dashboard/machines.php');
            return;
        }

        $ownerId = current_owner_id();
        require_once ROOT_PATH . '/models/Machine.php';
        $machineModel = new Machine();
        if ($ownerId !== null && $ownerId > 0) {
            $allowedIds = $machineModel->getMachineIdsForOwner($ownerId);
            if (!in_array($machineId, $allowedIds, true)) {
                flash('danger', 'Access Denied: You do not have permission to configure that machine.');
                redirect('dashboard/machines.php');
                return;
            }
        }

        $db = Database::getInstance();
        $userId = current_user()['id'] ?? null;
        $serviceModel = new Service();

        $db->beginTransaction();
        try {
            // 1. Update machine profile type
            // Auto-disable floater sensors: If both outlets are non-liquid (e.g. blower, tire_air) and template was carwash, switch to air_blower
            $hasLiquid = false;
            if (isset($_POST['services']) && is_array($_POST['services'])) {
                foreach ($_POST['services'] as $item) {
                    $icon = trim($item['icon'] ?? '');
                    if ($icon === 'water' || $icon === 'soap') {
                        $hasLiquid = true;
                        break;
                    }
                }
            }
            if (!$hasLiquid && $template === 'carwash') {
                $template = 'air_blower';
            }
            $machineModel->updateMachineType($machineId, $template);

            // 2. Update LCD lines
            $machineModel->updateLcdLines($machineId, $line1, $line2, $line3, $line4);

            // 3. Update Services (Outlet 1 & Outlet 2)
            // Fixed templates enforce standard names and icons; Presets are 100% custom & editable
            $isStandardFixed = in_array($template, ['carwash', 'air_blower'], true);
            $fixedDefs = [
                'carwash' => [
                    1 => ['name' => 'Water Service', 'icon' => 'water', 'type' => 'water'],
                    2 => ['name' => 'Soap Service',  'icon' => 'soap',  'type' => 'soap'],
                ],
                'air_blower' => [
                    1 => ['name' => 'Tire Air Compressor', 'icon' => 'tire_air', 'type' => 'air'],
                    2 => ['name' => 'Car Blower Fan',      'icon' => 'blower',   'type' => 'blower'],
                ]
            ];

            $outletLoopIdx = 1;
            if (isset($_POST['services']) && is_array($_POST['services'])) {
                foreach ($_POST['services'] as $idKey => $item) {
                    $serviceId      = filter_var($item['id'] ?? $idKey, FILTER_VALIDATE_INT);
                    $coinValue      = filter_var($item['coin_value'] ?? 5.00, FILTER_VALIDATE_FLOAT);
                    $serviceSeconds = filter_var($item['service_seconds'] ?? null, FILTER_VALIDATE_INT);
                    $isEnabled      = !empty($item['is_enabled']);
                    $serviceName    = !empty($item['name']) ? trim($item['name']) : 'Service';
                    $serviceIcon    = !empty($item['icon']) ? trim($item['icon']) : null;
                    $serviceType    = !empty($item['type']) ? trim($item['type']) : null;

                    if ($isStandardFixed && isset($fixedDefs[$template][$outletLoopIdx])) {
                        $serviceName = $fixedDefs[$template][$outletLoopIdx]['name'];
                        $serviceIcon = $fixedDefs[$template][$outletLoopIdx]['icon'];
                        $serviceType = $fixedDefs[$template][$outletLoopIdx]['type'];
                    }
                    $outletLoopIdx++;

                    if ($serviceId && $serviceModel->belongsToPrimaryMachine($serviceId, $ownerId)) {
                        $serviceModel->updateConfiguration(
                            $serviceId,
                            $isEnabled,
                            $coinValue !== false ? $coinValue : 5.00,
                            $serviceSeconds !== false ? $serviceSeconds : 120,
                            $userId,
                            $serviceName,
                            $serviceIcon,
                            $serviceType
                        );
                    }
                }
            }

            $db->commit();
            $badge = Machine::getMachineTypeBadge($template);
            log_activity('SERVICE_CONFIG_UPDATED', "Updated machine hardware and services under {$badge['label']}.", $ownerId);
            flash('success', "Hardware configuration saved successfully under " . e($badge['label']) . "! Display lines, outlet rates, and icons updated.");
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Failed to save unified hardware configuration: " . $e->getMessage());
            flash('danger', 'Failed to save configuration: ' . e($e->getMessage()));
        }

        redirect('dashboard/machines.php');
    }

    public function saveServiceConfiguration(): void
    {
        csrf_verify();

        // 1. Unified batch service configuration handling
        if (isset($_POST['services']) && is_array($_POST['services'])) {
            $ownerId = current_owner_id();
            $serviceModel = new Service();
            $db = Database::getInstance();
            $userId = current_user()['id'] ?? null;

            $servicesToUpdate = [];
            $hasAnyChange = false;

            // Validate all services first before any database modifications
            foreach ($_POST['services'] as $idKey => $item) {
                $serviceId      = filter_var($item['id'] ?? $idKey, FILTER_VALIDATE_INT);
                $coinValue      = filter_var($item['coin_value'] ?? 5.00, FILTER_VALIDATE_FLOAT);
                $serviceSeconds = filter_var($item['service_seconds'] ?? null, FILTER_VALIDATE_INT);
                $isEnabled      = !empty($item['is_enabled']);
                $serviceName    = !empty($item['name']) ? trim($item['name']) : 'Service';

                if (!$serviceId || $coinValue === false || $serviceSeconds === false
                    || $coinValue < 1 || $coinValue > 100 || $serviceSeconds < 10 || $serviceSeconds > 600) {
                    flash('danger', $serviceName . ': Please enter a valid service time between 00:10 (10 seconds) and 10:00 (10 minutes).');
                    redirect('dashboard/machines.php');
                }

                if (!$serviceModel->belongsToPrimaryMachine($serviceId, $ownerId)) {
                    flash('danger', $serviceName . ': That service configuration is not available.');
                    redirect('dashboard/machines.php');
                }

                $current = $serviceModel->findById($serviceId);
                if (!$current) {
                    flash('danger', $serviceName . ': Service record not found.');
                    redirect('dashboard/machines.php');
                }

                $isChanged = ((int) $current['is_enabled'] !== ($isEnabled ? 1 : 0)
                    || (float) $current['coin_value'] !== (float) $coinValue
                    || (int) $current['service_seconds'] !== $serviceSeconds
                    || (!empty($serviceName) && ($current['name'] ?? '') !== $serviceName));

                if ($isChanged) {
                    $hasAnyChange = true;
                }

                $servicesToUpdate[] = [
                    'id'              => $serviceId,
                    'name'            => $serviceName,
                    'is_enabled'      => $isEnabled,
                    'coin_value'      => (float) $coinValue,
                    'service_seconds' => $serviceSeconds,
                    'changed'         => $isChanged,
                ];
            }

            if (!$hasAnyChange) {
                flash('info', 'No configuration changes to save.');
                redirect('dashboard/machines.php');
            }

            $db->beginTransaction();
            try {
                $updatedNames = [];
                foreach ($servicesToUpdate as $svc) {
                    if ($svc['changed']) {
                        $serviceModel->updateConfiguration(
                            $svc['id'],
                            $svc['is_enabled'],
                            $svc['coin_value'],
                            $svc['service_seconds'],
                            $userId,
                            $svc['name']
                        );
                        $updatedNames[] = $svc['name'];
                    }
                }
                $db->commit();

                $summary = !empty($updatedNames) ? implode(' and ', $updatedNames) : 'Service';
                log_activity('SERVICE_CONFIG_UPDATED', "Updated machine {$summary} configuration.", $ownerId);
                flash('success', 'Service configuration saved successfully.');
                redirect('dashboard/machines.php');
            } catch (\Throwable $e) {
                $db->rollBack();
                error_log("Failed to save service configurations: " . $e->getMessage());
                flash('danger', 'Failed to save configuration. Please try again.');
                redirect('dashboard/machines.php');
            }
            return;
        }

        // 2. Fallback single-service configuration handling
        $serviceId      = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
        $coinValue      = filter_input(INPUT_POST, 'coin_value', FILTER_VALIDATE_FLOAT);
        $serviceSeconds = filter_input(INPUT_POST, 'service_seconds', FILTER_VALIDATE_INT);
        $isEnabled      = isset($_POST['is_enabled']);

        if (!$serviceId || $coinValue === false || $serviceSeconds === false
            || $coinValue < 1 || $coinValue > 100 || $serviceSeconds < 10 || $serviceSeconds > 600) {
            flash('danger', 'Please enter a valid service time between 00:10 (10 seconds) and 10:00 (10 minutes).');
            redirect('dashboard/machines.php');
        }

        $ownerId = current_owner_id();
        $service = new Service();
        if (!$service->belongsToPrimaryMachine($serviceId, $ownerId)) {
            flash('danger', 'That service configuration is not available.');
            redirect('dashboard/machines.php');
        }

        $current = $service->findById($serviceId);
        if ($current
            && (int) $current['is_enabled'] === ($isEnabled ? 1 : 0)
            && (float) $current['coin_value'] === (float) $coinValue
            && (int) $current['service_seconds'] === $serviceSeconds) {
            flash('info', 'No configuration changes to save.');
            redirect('dashboard/machines.php');
        }

        $service->updateConfiguration($serviceId, $isEnabled, (float) $coinValue, $serviceSeconds, current_user()['id'] ?? null);
        log_activity('SERVICE_CONFIG_UPDATED', 'Updated a Machine 01 service configuration.');
        flash('success', 'Configuration saved successfully.');
        redirect('dashboard/machines.php');
    }

    public function saveLcdConfiguration(): void
    {
        csrf_verify();

        $machineId = filter_input(INPUT_POST, 'machine_id', FILTER_VALIDATE_INT);
        $line1 = mb_substr($_POST['lcd_line1'] ?? '', 0, 20);
        $line2 = mb_substr($_POST['lcd_line2'] ?? '', 0, 20);
        $line3 = mb_substr($_POST['lcd_line3'] ?? '', 0, 20);
        $line4 = mb_substr($_POST['lcd_line4'] ?? '', 0, 20);

        if (!$machineId) {
            flash('danger', 'Invalid machine specified.');
            redirect('dashboard/machines.php');
        }

        $ownerId = current_owner_id();
        $machine = new Machine();
        if ($ownerId !== null && $ownerId > 0) {
            $allowedIds = $machine->getMachineIdsForOwner($ownerId);
            if (!in_array($machineId, $allowedIds, true)) {
                flash('danger', 'Access Denied: You do not have permission to configure that machine.');
                redirect('dashboard/machines.php');
            }
        }

        $machine->updateLcdLines($machineId, $line1, $line2, $line3, $line4);

        log_activity('SERVICE_CONFIG_UPDATED', 'Updated 20x4 LCD screen customizer template.');
        flash('success', '20x4 LCD Screen Template saved successfully! Pushing to physical machine on next heartbeat...');
        redirect('dashboard/machines.php');
    }

    /**
     * Apply a preset machine template (Carwash vs Tire Air & Blower vs Solo Tire Air).
     */
    public function applyMachineTemplate(): void
    {
        csrf_verify();

        $machineId = filter_input(INPUT_POST, 'machine_id', FILTER_VALIDATE_INT);
        $template  = trim($_POST['machine_template'] ?? 'carwash');

        if (!$machineId) {
            flash('danger', 'Invalid machine specified.');
            redirect('dashboard/machines.php');
            return;
        }

        $ownerId = current_owner_id();
        $machine = new Machine();
        if ($ownerId !== null && $ownerId > 0) {
            $allowedIds = $machine->getMachineIdsForOwner($ownerId);
            if (!in_array($machineId, $allowedIds, true)) {
                flash('danger', 'Access Denied: You do not have permission to configure that machine.');
                redirect('dashboard/machines.php');
                return;
            }
        }

        $applied = $machine->applyTemplate($machineId, $template, current_user()['id'] ?? null);
        if ($applied) {
            $badge = Machine::getMachineTypeBadge($template);
            log_activity('TEMPLATE_APPLIED', "Applied {$badge['label']} template to machine #{$machineId}.", $ownerId);
            flash('success', "Template applied successfully! Machine is now configured as <strong>" . e($badge['label']) . "</strong>. Service labels, icons, and LCD presets updated.");
        } else {
            flash('danger', 'Failed to apply machine template.');
        }

        redirect('dashboard/machines.php');
    }

    /**
     * Process claiming an additional vending machine using a Product Activation Key.
     */
    public function claimMachine(): void
    {
        csrf_verify();

        $ownerId = current_owner_id() ?: (int)(current_user()['id'] ?? 0);
        if ($ownerId <= 0) {
            flash('danger', 'Access Denied: You must be an authenticated owner to register machines.');
            redirect('dashboard/index.php');
            return;
        }

        $productKey = strtoupper(trim($_POST['product_key'] ?? ''));
        $nickname   = trim($_POST['machine_nickname'] ?? '');
        $returnTo   = trim($_POST['return_to'] ?? '');

        // Safe redirection target defense
        $redirectTarget = 'dashboard/machines.php';
        if (!empty($returnTo)
            && (str_starts_with($returnTo, '/') || str_starts_with($returnTo, 'dashboard/'))
            && !str_starts_with($returnTo, '//')
            && !str_contains($returnTo, ':')) {
            $redirectTarget = ltrim($returnTo, '/');
        }

        if (empty($productKey)) {
            flash('danger', 'Please enter a valid Product Activation Key.');
            redirect($redirectTarget);
            return;
        }

        require_once ROOT_PATH . '/models/ProductKey.php';
        $productKeyModel = new ProductKey();

        // 1. Validate Product Key existence and state
        $validation = $productKeyModel->validateKey($productKey);
        if (!$validation['valid']) {
            flash('danger', 'Unable to add machine: ' . e($validation['error']) . '.');
            redirect($redirectTarget);
            return;
        }

        $keyRow = $validation['key'];
        $keyId  = (int)$keyRow['id'];
        $db     = Database::getInstance();
        $pdo    = $db->getConnection();

        // 2. Hardware ownership & collision pre-checks
        if (!empty($keyRow['machine_id'])) {
            $stmt = $pdo->prepare("SELECT id, machine_code, name, owner_id, registration_status FROM machines WHERE id = :mid LIMIT 1");
            $stmt->execute([':mid' => (int)$keyRow['machine_id']]);
            $mRow = $stmt->fetch();

            if ($mRow) {
                if ($mRow['registration_status'] === 'disabled') {
                    flash('danger', 'Unable to add machine: This device hardware has been decommissioned.');
                    redirect($redirectTarget);
                    return;
                }

                if (!empty($mRow['owner_id']) && (int)$mRow['owner_id'] !== $ownerId) {
                    flash('danger', 'Security Guard: This machine is already assigned to another customer account.');
                    log_activity('MACHINE_CLAIM_BLOCKED', "User #{$ownerId} attempted to claim machine #{$mRow['id']} already owned by #{$mRow['owner_id']}.");
                    redirect($redirectTarget);
                    return;
                }

                if (!empty($mRow['owner_id']) && (int)$mRow['owner_id'] === $ownerId) {
                    $_SESSION['selected_machine_id'] = (int)$mRow['id'];
                    flash('info', 'Machine "' . e($mRow['name'] ?: $mRow['machine_code']) . '" is already connected to your account.');
                    redirect($redirectTarget);
                    return;
                }
            }
        }

        // 3. Atomic Key Consumption & Hardware Assignment Transaction
        $pdo->beginTransaction();
        try {
            $activated = $productKeyModel->activateKey(
                $keyId,
                $ownerId,
                $keyRow['hardware_serial'] ?? null,
                $keyRow['machine_id'] ?? null
            );

            if (!$activated) {
                $pdo->rollBack();
                flash('danger', 'Unable to add machine: this Product Key has already been claimed or is invalid.');
                redirect($redirectTarget);
                return;
            }

            // Resolve assigned machine ID
            $assignedMachineId = null;
            $assignedMachineCode = $keyRow['hardware_serial'] ?? 'Unit';
            $freshKey = $productKeyModel->findById($keyId);
            if ($freshKey && !empty($freshKey['machine_id'])) {
                $assignedMachineId = (int)$freshKey['machine_id'];
            }

            if ($assignedMachineId && !empty($nickname)) {
                $cleanNick = mb_substr($nickname, 0, 50);
                $pdo->prepare("UPDATE machines SET name = :name, updated_at = NOW() WHERE id = :mid")
                    ->execute([':name' => $cleanNick, ':mid' => $assignedMachineId]);
            }

            $pdo->commit();

            if ($assignedMachineId) {
                $_SESSION['selected_machine_id'] = $assignedMachineId;
            }

            $displayName = !empty($nickname) ? $nickname : $assignedMachineCode;
            log_activity('MACHINE_CLAIMED', "Customer #{$ownerId} registered additional machine {$displayName} with key {$productKey}.", $ownerId);
            flash('success', "Success! Machine <strong>" . e($displayName) . "</strong> has been added to your account.");
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Failed to claim machine key: " . $e->getMessage());
            flash('danger', 'System error while claiming machine. Please try again.');
        }

        redirect($redirectTarget);
    }
}
