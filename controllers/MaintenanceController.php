<?php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Maintenance.php';

class MaintenanceController
{
    public function handle(): void
    {
        csrf_verify();
        $action = $_POST['action'] ?? '';
        $maintenance = new Maintenance();

        try {
            if ($action === 'add') {
                $this->add($maintenance);
                flash('success', 'Maintenance reminder created.');
            } elseif ($action === 'edit') {
                $itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
                if (!$itemId) throw new InvalidArgumentException('Maintenance item not found.');
                $this->edit($maintenance, $itemId);
                flash('success', 'Maintenance reminder updated.');
            } elseif ($action === 'delete') {
                $itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
                if (!$itemId) throw new InvalidArgumentException('Maintenance item not found.');
                $maintenance->delete($itemId);
                flash('success', 'Maintenance reminder deleted.');
            } elseif ($action === 'toggle') {
                $itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
                if (!$itemId) throw new InvalidArgumentException('Maintenance item not found.');
                $maintenance->setEnabled($itemId, ($_POST['enabled'] ?? '') === '1');
                flash('success', 'Maintenance reminder updated.');
            } elseif ($action === 'complete') {
                $itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
                if (!$itemId) throw new InvalidArgumentException('Maintenance item not found.');
                $maintenance->complete($itemId, current_user()['id'] ?? null, trim($_POST['notes'] ?? ''));
                flash('success', 'Maintenance recorded and reminder reset.');
            } else {
                throw new InvalidArgumentException('Invalid maintenance action.');
            }
            log_activity('MAINTENANCE_UPDATED', 'Updated a Machine 01 maintenance reminder.');
        } catch (InvalidArgumentException $e) {
            flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[VendoLink][Maintenance] ' . $e->getMessage());
            flash('danger', 'Could not save the maintenance change.');
        }
        redirect('dashboard/maintenance.php');
    }

    private function add(Maintenance $maintenance): void
    {
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['reminder_type'] ?? '';
        $date = $_POST['last_completed_date'] ?? '';
        $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT) ?: null;
        $calendarValue = filter_input(INPUT_POST, 'calendar_interval_value', FILTER_VALIDATE_INT) ?: null;
        $calendarUnit = $_POST['calendar_interval_unit'] ?? null;
        $runtimeMinutes = filter_input(INPUT_POST, 'runtime_interval_minutes', FILTER_VALIDATE_INT) ?: null;

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new InvalidArgumentException('Enter a maintenance name from 2 to 120 characters.');
        if (!in_array($type, ['calendar', 'runtime', 'both'], true)) throw new InvalidArgumentException('Choose a valid reminder type.');
        $lastDate = DateTime::createFromFormat('Y-m-d', $date);
        if (!$lastDate || $lastDate->format('Y-m-d') !== $date) throw new InvalidArgumentException('Choose a valid last maintenance date.');
        if (in_array($type, ['calendar', 'both'], true) && (!$calendarValue || !in_array($calendarUnit, ['days', 'weeks', 'months', 'years'], true))) throw new InvalidArgumentException('Set a valid calendar interval.');
        if (in_array($type, ['runtime', 'both'], true) && (!$runtimeMinutes || $runtimeMinutes < 1)) throw new InvalidArgumentException('Set a runtime interval in minutes.');

        $ownerId = current_owner_id();

        $maintenance->add([
            'name' => $name, 'type' => $type, 'service_id' => $serviceId, 'enabled' => isset($_POST['is_enabled']) ? 1 : 0,
            'last_completed' => $lastDate->format('Y-m-d 00:00:00'), 'calendar_value' => in_array($type, ['calendar', 'both'], true) ? $calendarValue : null,
            'calendar_unit' => in_array($type, ['calendar', 'both'], true) ? $calendarUnit : null,
            'runtime_interval' => in_array($type, ['runtime', 'both'], true) ? $runtimeMinutes * 60 : null,
        ], $ownerId);
    }

    private function edit(Maintenance $maintenance, int $itemId): void
    {
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['reminder_type'] ?? '';
        $date = $_POST['last_completed_date'] ?? '';
        $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT) ?: null;
        $calendarValue = filter_input(INPUT_POST, 'calendar_interval_value', FILTER_VALIDATE_INT) ?: null;
        $calendarUnit = $_POST['calendar_interval_unit'] ?? null;
        $runtimeMinutes = filter_input(INPUT_POST, 'runtime_interval_minutes', FILTER_VALIDATE_INT) ?: null;

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new InvalidArgumentException('Enter a maintenance name from 2 to 120 characters.');
        if (!in_array($type, ['calendar', 'runtime', 'both'], true)) throw new InvalidArgumentException('Choose a valid reminder type.');
        $lastDate = DateTime::createFromFormat('Y-m-d', $date);
        if (!$lastDate || $lastDate->format('Y-m-d') !== $date) throw new InvalidArgumentException('Choose a valid last maintenance date.');
        if (in_array($type, ['calendar', 'both'], true) && (!$calendarValue || !in_array($calendarUnit, ['days', 'weeks', 'months', 'years'], true))) throw new InvalidArgumentException('Set a valid calendar interval.');
        if (in_array($type, ['runtime', 'both'], true) && (!$runtimeMinutes || $runtimeMinutes < 1)) throw new InvalidArgumentException('Set a runtime interval in minutes.');

        $ownerId = current_owner_id();

        $maintenance->edit($itemId, [
            'name' => $name, 'type' => $type, 'service_id' => $serviceId, 'enabled' => isset($_POST['is_enabled']) ? 1 : 0,
            'last_completed' => $lastDate->format('Y-m-d 00:00:00'), 'calendar_value' => in_array($type, ['calendar', 'both'], true) ? $calendarValue : null,
            'calendar_unit' => in_array($type, ['calendar', 'both'], true) ? $calendarUnit : null,
            'runtime_interval' => in_array($type, ['runtime', 'both'], true) ? $runtimeMinutes * 60 : null,
        ], $ownerId);
    }
}
