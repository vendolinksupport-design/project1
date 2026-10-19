<?php
/**
 * ============================================================
 * VendoLink — Maintenance Model
 * ============================================================
 *
 * Maintenance configuration, due status, and completion history.
 * Supports multi-tenant scoping by Owner ID.
 *
 * @package VendoLink
 * @version 2.0
 */

require_once __DIR__ . '/../config/database.php';

class Maintenance
{
    public const MAX_ACTIVE_REMINDERS = 50;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function services(?int $ownerId = null): array
    {
        if ($ownerId !== null && $ownerId > 0) {
            return $this->db->query(
                'SELECT DISTINCT s.id, s.name, COALESCE(s.runtime_seconds, 0) AS runtime_seconds, s.service_key 
                 FROM services s 
                 INNER JOIN machines m ON s.machine_id = m.id
                 LEFT JOIN product_keys pk ON (pk.hardware_serial = m.machine_code OR pk.hardware_serial = m.machine_key)
                 WHERE m.owner_id = :owner_id OR pk.owner_id = :owner_id2
                 ORDER BY FIELD(s.service_key, "water", "soap"), s.id',
                [':owner_id' => $ownerId, ':owner_id2' => $ownerId]
            )->fetchAll();
        }

        return $this->db->query(
            'SELECT id, name, COALESCE(runtime_seconds, 0) AS runtime_seconds, service_key FROM services ORDER BY FIELD(service_key, "water", "soap"), id'
        )->fetchAll();
    }

    public function getMachineRuntime(?int $ownerId = null): int
    {
        if ($ownerId !== null && $ownerId > 0) {
            $val = $this->db->query(
                'SELECT runtime_seconds FROM machines 
                 WHERE owner_id = :owner_id OR id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2)
                 ORDER BY id ASC LIMIT 1',
                [':owner_id' => $ownerId, ':owner_id2' => $ownerId]
            )->fetchColumn();
            return (int) ($val ?: 0);
        }

        $val = $this->db->query('SELECT runtime_seconds FROM machines ORDER BY id ASC LIMIT 1')->fetchColumn();
        return (int) ($val ?: 0);
    }

    public function items(?int $ownerId = null): array
    {
        $where = 'mi.is_deleted = 0';
        $params = [];

        if ($ownerId !== null && $ownerId > 0) {
            $where .= ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params = [':owner_id' => $ownerId, ':owner_id2' => $ownerId];
        }

        $items = $this->db->query(
            "SELECT mi.*, s.name AS service_name, m.runtime_seconds AS machine_runtime_seconds,
                    COALESCE(s.runtime_seconds, 0) AS service_runtime_seconds
             FROM maintenance_items mi
             INNER JOIN machines m ON m.id = mi.machine_id
             LEFT JOIN services s ON s.id = mi.service_id
             WHERE {$where}
             ORDER BY mi.is_enabled DESC, mi.updated_at DESC, mi.id DESC",
            $params
        )->fetchAll();

        foreach ($items as &$item) {
            $item['current_runtime_seconds'] = $item['service_id'] ? (int) $item['service_runtime_seconds'] : (int) $item['machine_runtime_seconds'];
            $item['next_due_date'] = $this->nextDueDate($item);
            $item['status'] = $this->status($item);
        }
        unset($item);
        return $items;
    }

    /**
     * Retrieve paginated active/configured reminders for the Active Reminders list.
     *
     * @param int|null $ownerId
     * @param int      $page
     * @param int      $perPage
     * @return array{items: array, total_items: int, page: int, per_page: int, total_pages: int, count_text: string, from: int, to: int}
     */
    public function itemsPaginated(?int $ownerId = null, int $page = 1, int $perPage = 5): array
    {
        $perPage = max(1, min(50, $perPage));
        $allItems = $this->items($ownerId);
        $totalItems = count($allItems);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));

        $page = max(1, $page);
        if ($page > $totalPages) {
            $page = 1;
        }

        $offset = ($page - 1) * $perPage;
        $items = ($totalItems > 0) ? array_slice($allItems, $offset, $perPage) : [];

        $from = ($totalItems > 0) ? ($offset + 1) : 0;
        $to = min($offset + $perPage, $totalItems);
        $countText = ($totalItems === 0)
            ? 'Showing 0 of 0 reminders'
            : "Showing {$from}–{$to} of {$totalItems} reminders";

        return [
            'items'       => $items,
            'total_items' => $totalItems,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'count_text'  => $countText,
            'from'        => $from,
            'to'          => $to,
        ];
    }

    /**
     * Count reminders qualifying as active under system rules (is_deleted = 0 AND is_enabled = 1).
     *
     * @param int|null $ownerId
     * @return int
     */
    public function countActive(?int $ownerId = null): int
    {
        $where = 'mi.is_deleted = 0 AND mi.is_enabled = 1';
        $params = [];
        if ($ownerId !== null && $ownerId > 0) {
            $where .= ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params = [':owner_id' => $ownerId, ':owner_id2' => $ownerId];
        }

        return (int) $this->db->query(
            "SELECT COUNT(*) FROM maintenance_items mi INNER JOIN machines m ON m.id = mi.machine_id WHERE {$where}",
            $params
        )->fetchColumn();
    }

    public function add(array $data, ?int $ownerId = null): void
    {
        $machine = null;
        if ($ownerId !== null && $ownerId > 0) {
            $machine = $this->db->query(
                'SELECT id, runtime_seconds FROM machines 
                 WHERE owner_id = :owner_id OR id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2)
                 ORDER BY id ASC LIMIT 1',
                [':owner_id' => $ownerId, ':owner_id2' => $ownerId]
            )->fetch();
        } else {
            $machine = $this->db->query('SELECT id, runtime_seconds FROM machines ORDER BY id ASC LIMIT 1')->fetch();
        }

        if (!$machine) {
            throw new RuntimeException('No active vending machine found for this account.');
        }

        $machineId = (int) $machine['id'];
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            // Lock machine row to serialize concurrent reminder creation for this machine
            $this->db->query('SELECT id FROM machines WHERE id = :id FOR UPDATE', [':id' => $machineId])->fetch();

            // Count only reminders that qualify as active under system rules (is_deleted = 0 AND is_enabled = 1)
            $activeCount = (int) $this->db->query(
                'SELECT COUNT(*) FROM maintenance_items 
                 WHERE machine_id = :machine_id AND is_deleted = 0 AND is_enabled = 1',
                [':machine_id' => $machineId]
            )->fetchColumn();

            if ($activeCount >= self::MAX_ACTIVE_REMINDERS) {
                throw new InvalidArgumentException(
                    'Maximum active reminder limit reached (' . self::MAX_ACTIVE_REMINDERS . ' active reminders per machine). Please delete or disable an existing reminder before adding a new one.'
                );
            }

            $baseline = (int) $machine['runtime_seconds'];
            if (!empty($data['service_id'])) {
                $baseline = (int) $this->db->query('SELECT runtime_seconds FROM services WHERE id = :id', [':id' => $data['service_id']])->fetchColumn();
            }

            $this->db->query(
                'INSERT INTO maintenance_items
                 (machine_id, service_id, name, reminder_type, is_enabled, last_completed_at,
                  calendar_interval_value, calendar_interval_unit, runtime_interval_seconds, runtime_baseline_seconds)
                 VALUES (:machine_id, :service_id, :name, :type, :enabled, :last_completed,
                         :calendar_value, :calendar_unit, :runtime_interval, :runtime_baseline)',
                [
                    ':machine_id'       => $machineId, 
                    ':service_id'       => $data['service_id'] ?: null,
                    ':name'             => $data['name'], 
                    ':type'             => $data['type'], 
                    ':enabled'          => $data['enabled'],
                    ':last_completed'   => $data['last_completed'], 
                    ':calendar_value'   => $data['calendar_value'],
                    ':calendar_unit'    => $data['calendar_unit'], 
                    ':runtime_interval' => $data['runtime_interval'],
                    ':runtime_baseline' => $baseline,
                ]
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function delete(int $itemId): void
    {
        $this->db->query('UPDATE maintenance_items SET is_deleted = 1 WHERE id = :id', [':id' => $itemId]);
    }

    public function edit(int $itemId, array $data, ?int $ownerId = null): void
    {
        $machine = null;
        if ($ownerId !== null && $ownerId > 0) {
            $machine = $this->db->query(
                'SELECT id, runtime_seconds FROM machines 
                 WHERE owner_id = :owner_id OR id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2)
                 ORDER BY id ASC LIMIT 1',
                [':owner_id' => $ownerId, ':owner_id2' => $ownerId]
            )->fetch();
        } else {
            $machine = $this->db->query('SELECT id, runtime_seconds FROM machines ORDER BY id ASC LIMIT 1')->fetch();
        }

        if (!$machine) {
            throw new RuntimeException('No active vending machine found for this account.');
        }

        $baseline = (int) $machine['runtime_seconds'];
        if ($data['service_id']) {
            $baseline = (int) $this->db->query('SELECT runtime_seconds FROM services WHERE id = :id', [':id' => $data['service_id']])->fetchColumn();
        }

        $this->db->query(
            'UPDATE maintenance_items
             SET service_id = :service_id, name = :name, reminder_type = :type, is_enabled = :enabled, last_completed_at = :last_completed,
                 calendar_interval_value = :calendar_value, calendar_interval_unit = :calendar_unit, runtime_interval_seconds = :runtime_interval, runtime_baseline_seconds = :runtime_baseline
             WHERE id = :id',
            [
                ':id'               => $itemId,
                ':service_id'       => $data['service_id'] ?: null,
                ':name'             => $data['name'], 
                ':type'             => $data['type'], 
                ':enabled'          => $data['enabled'],
                ':last_completed'   => $data['last_completed'], 
                ':calendar_value'   => $data['calendar_value'],
                ':calendar_unit'    => $data['calendar_unit'], 
                ':runtime_interval' => $data['runtime_interval'],
                ':runtime_baseline' => $baseline,
            ]
        );
    }

    public function setEnabled(int $itemId, bool $enabled): void
    {
        $this->db->query('UPDATE maintenance_items SET is_enabled = :enabled WHERE id = :id', [':enabled' => $enabled ? 1 : 0, ':id' => $itemId]);
    }

    public function complete(int $itemId, ?int $userId, string $notes): void
    {
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            $item = $this->db->query(
                'SELECT mi.*, m.runtime_seconds AS machine_runtime_seconds, COALESCE(s.runtime_seconds, 0) AS service_runtime_seconds
                 FROM maintenance_items mi INNER JOIN machines m ON m.id = mi.machine_id
                 LEFT JOIN services s ON s.id = mi.service_id WHERE mi.id = :id FOR UPDATE',
                [':id' => $itemId]
            )->fetch();
            if (!$item) throw new InvalidArgumentException('Maintenance item was not found.');

            // Prevent duplicate records from rapid repeated clicks or page refreshes (within 10 seconds)
            $recentCheck = $this->db->query(
                'SELECT id FROM maintenance_history 
                 WHERE maintenance_item_id = :item_id 
                   AND completed_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
                 ORDER BY id DESC LIMIT 1',
                [':item_id' => $itemId]
            )->fetch();
            if ($recentCheck) {
                $pdo->commit();
                return;
            }

            $runtime = $item['service_id'] ? (int) $item['service_runtime_seconds'] : (int) $item['machine_runtime_seconds'];
            $this->db->query(
                'INSERT INTO maintenance_history (maintenance_item_id, completed_by_user_id, completed_at, runtime_at_completion_seconds, notes)
                 VALUES (:item_id, :user_id, NOW(), :runtime, :notes)',
                [':item_id' => $itemId, ':user_id' => $userId, ':runtime' => $runtime, ':notes' => $notes ?: null]
            );
            $this->db->query('UPDATE maintenance_items SET last_completed_at = NOW(), runtime_baseline_seconds = :runtime WHERE id = :id', [':runtime' => $runtime, ':id' => $itemId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function history(?int $ownerId = null): array
    {
        $result = $this->historyPaginated($ownerId, 1, 20);
        return $result['items'];
    }

    /**
     * Retrieve paginated completed maintenance history.
     *
     * @param int|null $ownerId
     * @param int      $page
     * @param int      $perPage
     * @return array{items: array, total_items: int, page: int, per_page: int, total_pages: int}
     */
    public function historyPaginated(?int $ownerId = null, int $page = 1, int $perPage = 15): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $where = '1=1';
        $params = [];

        if ($ownerId !== null && $ownerId > 0) {
            $where .= ' AND (m.owner_id = :owner_id OR m.id IN (SELECT m2.id FROM machines m2 JOIN product_keys pk ON (pk.hardware_serial = m2.machine_code OR pk.hardware_serial = m2.machine_key) WHERE pk.owner_id = :owner_id2))';
            $params = [':owner_id' => $ownerId, ':owner_id2' => $ownerId];
        }

        $countSql = "SELECT COUNT(*) FROM maintenance_history mh 
                     INNER JOIN maintenance_items mi ON mi.id = mh.maintenance_item_id
                     INNER JOIN machines m ON m.id = mi.machine_id
                     WHERE {$where}";
        $totalItems = (int) $this->db->query($countSql, $params)->fetchColumn();

        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $items = $this->db->query(
            "SELECT mh.*, mi.name AS maintenance_name, mi.reminder_type, s.name AS service_name, s.service_key, m.name AS machine_name, u.full_name
             FROM maintenance_history mh 
             INNER JOIN maintenance_items mi ON mi.id = mh.maintenance_item_id
             INNER JOIN machines m ON m.id = mi.machine_id
             LEFT JOIN services s ON s.id = mi.service_id
             LEFT JOIN users u ON u.id = mh.completed_by_user_id
             WHERE {$where}
             ORDER BY mh.completed_at DESC, mh.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return [
            'items'       => $items,
            'total_items' => $totalItems,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /** Return the most urgent configured maintenance summary for each service. */
    public function summariesByService(?int $ownerId = null): array
    {
        $summaries = [];
        $rank = ['off' => 0, 'ok' => 1, 'soon' => 2, 'due' => 3, 'overdue' => 4];
        foreach ($this->items($ownerId) as $item) {
            if (!$item['service_id']) {
                continue;
            }
            $serviceId = (int) $item['service_id'];
            if (!isset($summaries[$serviceId]) || $rank[$item['status']] > $rank[$summaries[$serviceId]['status']]) {
                $summaries[$serviceId] = [
                    'name'           => $item['name'],
                    'status'         => $item['status'],
                    'next_due_date'  => $item['next_due_date'],
                    'runtime_status' => in_array($item['reminder_type'], ['runtime', 'both'], true) ? $item['status'] : null,
                ];
            }
        }
        return $summaries;
    }

    private function nextDueDate(array $item): ?DateTime
    {
        if (!in_array($item['reminder_type'], ['calendar', 'both'], true) || !$item['calendar_interval_value'] || !$item['calendar_interval_unit']) return null;
        return (new DateTime($item['last_completed_at']))->modify('+' . (int) $item['calendar_interval_value'] . ' ' . $item['calendar_interval_unit']);
    }

    private function status(array $item): string
    {
        if (!(int) $item['is_enabled']) return 'off';
        $rank = 0;
        $nextDate = $item['next_due_date'];
        if ($nextDate) {
            $now = new DateTime();
            if ($nextDate < $now) $rank = 3;
            elseif ($nextDate->format('Y-m-d') === $now->format('Y-m-d')) $rank = max($rank, 2);
            elseif ($nextDate <= (clone $now)->modify('+7 days')) $rank = max($rank, 1);
        }
        if (in_array($item['reminder_type'], ['runtime', 'both'], true) && (int) $item['runtime_interval_seconds'] > 0) {
            $used = max(0, (int) $item['current_runtime_seconds'] - (int) $item['runtime_baseline_seconds']);
            $ratio = $used / (int) $item['runtime_interval_seconds'];
            if ($ratio > 1.10) $rank = max($rank, 3);
            elseif ($ratio >= 1) $rank = max($rank, 2);
            elseif ($ratio >= .8) $rank = max($rank, 1);
        }
        return ['ok', 'soon', 'due', 'overdue'][$rank];
    }
}
