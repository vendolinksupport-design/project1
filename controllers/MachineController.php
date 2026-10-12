<?php
/** Processes the Phase 2 Machine & Services settings form. */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Service.php';

class MachineController
{
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
                    || (int) $current['service_seconds'] !== $serviceSeconds);

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
                            $userId
                        );
                        $updatedNames[] = $svc['name'];
                    }
                }
                $db->commit();

                $summary = !empty($updatedNames) ? implode(' and ', $updatedNames) : 'Service';
                log_activity('SERVICE_CONFIG_UPDATED', "Updated Machine 01 {$summary} configuration.");
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
}
