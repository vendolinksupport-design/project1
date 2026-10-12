<?php
/**
 * ============================================================
 * VendoLink — Machine Data Model
 * ============================================================
 *
 * Manages machine records, ESP32 telemetry, state-change heartbeats,
 * machine health status, operational activity logs, and machine alerts.
 *
 * @package VendoLink
 * @version 2.1
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/Notification.php';
require_once __DIR__ . '/Setting.php';


class Machine
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get primary machine with associated services for a specific owner or all.
     */
    public function primaryWithServices(?int $ownerId = null): ?array
    {
        $machine = null;

        if ($ownerId !== null && $ownerId > 0) {
            // 1. Direct owner_id lookup on machines table
            $stmt = $this->db->query(
                'SELECT * FROM machines WHERE owner_id = :owner_id ORDER BY id ASC LIMIT 1',
                [':owner_id' => $ownerId]
            );
            $machine = $stmt->fetch();

            // 2. Lookup via product_keys table linkage
            if (!$machine) {
                $stmt = $this->db->query(
                    'SELECT m.* FROM machines m 
                     INNER JOIN product_keys pk ON (pk.hardware_serial = m.machine_code OR pk.hardware_serial = m.machine_key)
                     WHERE pk.owner_id = :owner_id 
                     ORDER BY m.id ASC LIMIT 1',
                    [':owner_id' => $ownerId]
                );
                $machine = $stmt->fetch();

                // If found via product_keys, associate direct owner_id
                if ($machine) {
                    $this->db->query(
                        'UPDATE machines SET owner_id = :owner_id WHERE id = :id',
                        [':owner_id' => $ownerId, ':id' => (int)$machine['id']]
                    );
                }
            }

            // 3. Fallback: Auto-provision machine if owner has activated key
            if (!$machine) {
                $pkRow = $this->db->query(
                    "SELECT * FROM product_keys WHERE owner_id = :owner_id AND status = 'USED' ORDER BY id ASC LIMIT 1",
                    [':owner_id' => $ownerId]
                )->fetch();

                if ($pkRow && !empty($pkRow['hardware_serial'])) {
                    $serial = trim($pkRow['hardware_serial']);
                    $stmt = $this->db->query(
                        'SELECT * FROM machines WHERE machine_code = :c OR machine_key = :k LIMIT 1',
                        [':c' => $serial, ':k' => $serial]
                    );
                    $machine = $stmt->fetch();
                    if ($machine) {
                        $this->db->query('UPDATE machines SET owner_id = :oid WHERE id = :id', [':oid' => $ownerId, ':id' => $machine['id']]);
                    } else {
                        $machineKey = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $serial));
                        $apiToken   = 'vl_dev_sec_' . bin2hex(random_bytes(13));
                        $pdo = $this->db->getConnection();
                        $ins = $pdo->prepare(
                            "INSERT INTO machines (owner_id, machine_code, machine_key, name, api_token, status)
                             VALUES (:owner_id, :code, :key, :name, :token, 'pending')"
                        );
                        $ins->execute([
                            ':owner_id' => $ownerId,
                            ':code'     => $serial,
                            ':key'      => $machineKey,
                            ':name'     => 'Vendo Machine ' . $serial,
                            ':token'    => $apiToken,
                        ]);
                        $newMachId = (int)$pdo->lastInsertId();

                        $svc = $pdo->prepare(
                            "INSERT INTO services (machine_id, service_key, name, type, coin_value, service_seconds, is_enabled, enabled)
                             VALUES (?, 'water', 'Water Service', 'water', 5.00, 120, 1, 1),
                                    (?, 'soap',  'Soap Service',  'soap',  5.00, 120, 1, 1)"
                        );
                        $svc->execute([$newMachId, $newMachId]);

                        $machine = $this->db->query('SELECT * FROM machines WHERE id = :id', [':id' => $newMachId])->fetch();
                    }
                }
            }
        } else {
            // Admin / Internal unrestricted: primary unit
            $machine = $this->db->query(
                'SELECT * FROM machines WHERE machine_code = :code LIMIT 1',
                [':code' => 'MACHINE-01']
            )->fetch();

            if (!$machine) {
                $machine = $this->db->query('SELECT * FROM machines ORDER BY id ASC LIMIT 1')->fetch();
            }
        }

        if (!$machine) {
            return null;
        }

        $machine['calculated_status'] = $this->evaluateOnlineStatus($machine);

        $machine['services'] = $this->db->query(
            'SELECT * FROM services WHERE machine_id = :machine_id ORDER BY FIELD(service_key, "water", "soap"), id',
            [':machine_id' => $machine['id']]
        )->fetchAll();

        return $machine;
    }

    /**
     * Get machine IDs belonging to an owner, or all machine IDs if unrestricted ($ownerId === null).
     */
    public function getMachineIdsForOwner(?int $ownerId = null): array
    {
        if ($ownerId === null || $ownerId <= 0) {
            $rows = $this->db->query('SELECT id FROM machines')->fetchAll();
            return array_map(fn($r) => (int)$r['id'], $rows);
        }

        $rows = $this->db->query(
            'SELECT DISTINCT m.id FROM machines m
             LEFT JOIN product_keys pk ON (pk.hardware_serial = m.machine_code OR pk.hardware_serial = m.machine_key)
             WHERE m.owner_id = :owner_id OR pk.owner_id = :owner_id2',
            [':owner_id' => $ownerId, ':owner_id2' => $ownerId]
        )->fetchAll();

        return array_map(fn($r) => (int)$r['id'], $rows);
    }

    /**
     * Find machine by stable machine_key.
     */
    public function findByKey(string $machineKey): ?array
    {
        $machine = $this->db->query(
            'SELECT * FROM machines WHERE machine_key = :mkey LIMIT 1',
            [':mkey' => $machineKey]
        )->fetch();

        return $machine ?: null;
    }

    /**
     * Verify device authentication token.
     */
    public function verifyApiToken(string $machineKey, string $token): bool
    {
        $machine = $this->findByKey($machineKey);
        if (!$machine || empty($machine['api_token'])) {
            return false;
        }

        return hash_equals((string)$machine['api_token'], (string)$token);
    }

    /**
     * Record ESP32 heartbeat telemetry and update machine record.
     * Only logs operational activity events on meaningful connection state transitions.
     */
    public function recordHeartbeat(
        string $machineKey,
        ?string $ipAddress = null,
        ?string $firmwareVersion = null,
        string $status = 'online'
    ): bool {
        $now = date('Y-m-d H:i:s');
        $machine = $this->findByKey($machineKey);

        if ($machine) {
            $prevStatus = $this->evaluateOnlineStatus($machine);
            $ownerId    = (int)($machine['owner_id'] ?? 0);
            if ($ownerId <= 0) {
                $ownerId = $this->resolveOwnerId($machine);
            }

            // Log connection events ONLY on state transitions from offline
            if ($prevStatus === 'offline') {
                $eventDesc = empty($machine['last_seen']) ? 'Machine connected' : 'Machine reconnected';
                $action    = empty($machine['last_seen']) ? 'MACHINE_CONNECTED' : 'MACHINE_RECONNECTED';
                log_activity($action, $eventDesc . ' (' . ($machine['name'] ?? 'Machine Unit') . ')', $ownerId > 0 ? $ownerId : null);

                // Notify owner if preference enabled
                $settingModel = new Setting();
                if ($ownerId > 0 && $settingModel->get('notif_machine_online', '1') === '1') {
                    (new Notification())->create([
                        'user_id'     => $ownerId,
                        'machine_id'  => (int)$machine['id'],
                        'category'    => 'hardware',
                        'type'        => 'success',
                        'title'       => 'Machine Back Online',
                        'message'     => ($machine['name'] ?? 'Machine') . ' (' . ($machine['machine_code'] ?? 'Unit') . ') reconnected to VendoLink.',
                        'icon'        => 'bi-wifi',
                        'badge'       => 'Connection',
                        'is_critical' => 0,
                        'action_link' => BASE_URL . '/dashboard/machines.php',
                    ]);
                }
            }
        }

        $result = $this->db->query(
            "UPDATE machines
             SET status = :status,
                 last_seen = :last_seen,
                 ip_address = COALESCE(:ip_address, ip_address),
                 firmware_version = COALESCE(:firmware_version, firmware_version),
                 updated_at = :updated_at
             WHERE machine_key = :machine_key",
            [
                ':status'           => $status,
                ':last_seen'        => $now,
                ':ip_address'       => $ipAddress,
                ':firmware_version' => $firmwareVersion,
                ':updated_at'       => $now,
                ':machine_key'      => $machineKey,
            ]
        );

        return $result->rowCount() > 0;
    }

    /**
     * Resolve the owner ID for a given machine using direct owner_id, product_keys, or fallback.
     */
    public function resolveOwnerId(array $machine): int
    {
        $ownerId = (int)($machine['owner_id'] ?? 0);
        if ($ownerId > 0) {
            return $ownerId;
        }

        // 1. Lookup via product_keys table linkage
        $code = $machine['machine_code'] ?? '';
        $key  = $machine['machine_key'] ?? '';
        if (!empty($code) || !empty($key)) {
            $pk = $this->db->query(
                "SELECT owner_id FROM product_keys 
                 WHERE (hardware_serial = :c OR hardware_serial = :k) AND owner_id IS NOT NULL 
                 ORDER BY id ASC LIMIT 1",
                [':c' => $code, ':k' => $key]
            )->fetch();

            if ($pk && !empty($pk['owner_id'])) {
                $ownerId = (int)$pk['owner_id'];
                $this->db->query("UPDATE machines SET owner_id = :oid WHERE id = :id", [':oid' => $ownerId, ':id' => (int)$machine['id']]);
                return $ownerId;
            }
        }

        // 2. Fallback to first active owner user in database
        $firstOwner = $this->db->query("SELECT id FROM users WHERE role = 'owner' ORDER BY id ASC LIMIT 1")->fetch();
        if ($firstOwner && !empty($firstOwner['id'])) {
            $ownerId = (int)$firstOwner['id'];
            $this->db->query("UPDATE machines SET owner_id = :oid WHERE id = :id", [':oid' => $ownerId, ':id' => (int)$machine['id']]);
            return $ownerId;
        }

        return 1; // Fallback to administrator ID 1
    }

    /**
     * Evaluate online status based on last_seen timestamp.
     */
    public function evaluateOnlineStatus(array $machine): string

    {
        if (empty($machine['last_seen'])) {
            return 'offline';
        }

        $lastSeenTime = strtotime($machine['last_seen']);
        if ($lastSeenTime === false) {
            return 'offline';
        }

        $diff = time() - $lastSeenTime;
        $timeout = defined('HEARTBEAT_TIMEOUT_SECONDS') ? HEARTBEAT_TIMEOUT_SECONDS : 60;

        if ($diff <= $timeout) {
            return 'online';
        } elseif ($diff <= ($timeout * 3)) {
            return 'stale';
        } else {
            return 'offline';
        }
    }

    /**
     * Get machine alerts.
     */
    public function getMachineAlerts(array $machine, array $maintenanceSummaries = []): array
    {
        $alerts = [];
        $status = $machine['calculated_status'] ?? 'offline';

        if ($status === 'offline') {
            $alerts[] = [
                'type'        => 'danger',
                'title'       => 'Controller Offline',
                'message'     => 'The machine is no longer communicating with VendoLink.',
                'icon'        => 'bi-exclamation-octagon-fill',
                'is_critical' => true
            ];
        } elseif ($status === 'stale') {
            $alerts[] = [
                'type'        => 'warning',
                'title'       => 'Connection Unstable',
                'message'     => 'Controller heartbeats are delayed. Check Wi-Fi signal strength.',
                'icon'        => 'bi-exclamation-triangle-fill',
                'is_critical' => false
            ];
        }

        // Check Low Tank Floater Alerts
        if (!empty($machine['water_tank_low'])) {
            $alerts[] = [
                'type'        => 'warning',
                'title'       => 'Low Water Level',
                'message'     => 'Detected by ESP32 tank sensor',
                'icon'        => 'bi-droplet-half',
                'is_critical' => true
            ];
        }

        if (!empty($machine['soap_tank_low'])) {
            $alerts[] = [
                'type'        => 'warning',
                'title'       => 'Low Soap Level',
                'message'     => 'Detected by ESP32 tank sensor',
                'icon'        => 'bi-exclamation-circle-fill',
                'is_critical' => true
            ];
        }

        // Check Maintenance Alerts
        foreach ($maintenanceSummaries as $m) {
            if (($m['status'] ?? '') === 'overdue') {
                $alerts[] = [
                    'type'        => 'warning',
                    'title'       => 'Maintenance Overdue',
                    'message'     => e($m['name']) . ' maintenance is overdue.',
                    'icon'        => 'bi-wrench-adjustable-circle-fill',
                    'is_critical' => false
                ];
            }
        }

        return $alerts;
    }

    /**
     * Update machine tank level status (water or soap) from ESP32 sensor report.
     * Enforces de-duplication: logs activity ONLY on state transitions.
     */
    public function updateTankStatus(string $machineKey, string $tankKey, string $status): array
    {
        $machine = $this->findByKey($machineKey);
        if (!$machine) {
            return ['success' => false, 'error' => "Machine identity '{$machineKey}' not found."];
        }

        $tankKey = strtolower(trim($tankKey));
        $isLow = (strtolower(trim($status)) === 'low');
        $column = ($tankKey === 'water') ? 'water_tank_low' : (($tankKey === 'soap') ? 'soap_tank_low' : null);

        if (!$column) {
            return ['success' => false, 'error' => 'Invalid tank_key specified. Allowed: water, soap.'];
        }

        $currentVal = (int) ($machine[$column] ?? 0);
        $newVal = $isLow ? 1 : 0;
        $changed = ($currentVal !== $newVal);

        if ($changed) {
            $this->db->query(
                "UPDATE machines SET {$column} = :val, updated_at = NOW() WHERE machine_key = :mkey",
                [':val' => $newVal, ':mkey' => $machineKey]
            );

            $machineName = $machine['name'] ?? 'Machine 01';
            $tankName    = ($tankKey === 'water') ? 'Water' : 'Soap';
            $ownerId     = $this->resolveOwnerId($machine);
            $notifModel  = new Notification();

            if ($isLow) {
                // 1. Create persistent critical notification for owner
                $gpioPin = ($tankKey === 'water') ? '13' : '12';
                $notifModel->create([
                    'user_id'     => $ownerId,
                    'machine_id'  => (int)$machine['id'],
                    'category'    => 'critical',
                    'type'        => 'danger',
                    'title'       => "CRITICAL: Low {$tankName} Reservoir Level",
                    'message'     => "{$tankName} tank level sensor (GPIO {$gpioPin}) triggered LOW on {$machineName}. Refill {$tankName} immediately.",
                    'icon'        => ($tankKey === 'water') ? 'bi-droplet-half' : 'bi-exclamation-circle-fill',
                    'badge'       => 'Hardware Alert',
                    'is_critical' => 1,
                    'action_link' => BASE_URL . '/dashboard/machines.php',
                ]);

                // 2. Log activity with owner_id
                log_activity(
                    'ALERT_TRIGGERED',
                    "Low {$tankName} Level - {$machineName} (Detected by ESP32 tank sensor)",
                    $ownerId
                );
            } else {
                // 1. Create recovery notification for owner
                $notifModel->create([
                    'user_id'     => $ownerId,
                    'machine_id'  => (int)$machine['id'],
                    'category'    => 'hardware',
                    'type'        => 'success',
                    'title'       => "{$tankName} Reservoir Restored",
                    'message'     => "{$tankName} tank level on {$machineName} returned to normal.",
                    'icon'        => 'bi-check-circle-fill',
                    'badge'       => 'Restored',
                    'is_critical' => 0,
                    'action_link' => BASE_URL . '/dashboard/machines.php',
                ]);

                // 2. Log activity with owner_id
                log_activity(
                    'ALERT_RESOLVED',
                    "{$tankName} Tank Restored - {$machineName} (Level returned to normal)",
                    $ownerId
                );
            }
        }

        return [
            'success'        => true,
            'message'        => 'Tank status updated successfully',
            'machine_key'    => $machineKey,
            'tank_key'       => $tankKey,
            'status'         => $isLow ? 'low' : 'normal',
            'state_changed'  => $changed,
            'water_tank_low' => ($column === 'water_tank_low') ? $newVal : (int)($machine['water_tank_low'] ?? 0),
            'soap_tank_low'  => ($column === 'soap_tank_low') ? $newVal : (int)($machine['soap_tank_low'] ?? 0),
        ];
    }

    /**
     * Get operational activity events (strictly non-financial operational logs).
     *
     * @param int         $limit    Number of events to retrieve
     * @param string|null $category Filter category ('connection', 'errors', 'maintenance', 'events', or null/all)
     * @param int|null    $ownerId  Owner ID filter (null for all/admin)
     * @return array
     */
    public function getOperationalActivity(int $limit = 5, ?string $category = null, ?int $ownerId = null): array
    {
        $categoryActions = [
            'connection'  => ['MACHINE_CONNECTED', 'MACHINE_DISCONNECTED', 'MACHINE_RECONNECTED'],
            'errors'      => ['MACHINE_DISCONNECTED', 'SERVICE_FAULT', 'ALERT_TRIGGERED'],
            'maintenance' => ['MAINTENANCE_COMPLETED', 'MAINTENANCE_UPDATED'],
            'events'      => ['SERVICE_COMPLETED', 'SERVICE_STARTED', 'SERVICE_CONFIG_UPDATED', 'SERVICE_ENABLED', 'SERVICE_DISABLED'],
        ];

        $allowedActions = [
            'MACHINE_CONNECTED',
            'MACHINE_DISCONNECTED',
            'MACHINE_RECONNECTED',
            'SERVICE_COMPLETED',
            'SERVICE_STARTED',
            'MAINTENANCE_COMPLETED',
            'MAINTENANCE_UPDATED',
            'SERVICE_CONFIG_UPDATED',
            'SERVICE_ENABLED',
            'SERVICE_DISABLED'
        ];

        $whereConditions = ["action IN (" . implode(',', array_fill(0, count($allowedActions), '?')) . ")"];
        $params = $allowedActions;

        if ($category && isset($categoryActions[$category])) {
            $catActions = $categoryActions[$category];
            $whereConditions = ["action IN (" . implode(',', array_fill(0, count($catActions), '?')) . ")"];
            $params = $catActions;
        }

        if ($ownerId !== null && $ownerId > 0) {
            $whereConditions[] = "user_id = ?";
            $params[] = $ownerId;
        }

        $whereClause = implode(' AND ', $whereConditions);

        $sql = "SELECT id, action, description, created_at
                FROM activity_logs
                WHERE {$whereClause}
                ORDER BY id DESC
                LIMIT " . (int)$limit;

        $rows = $this->db->query($sql, $params)->fetchAll();

        foreach ($rows as &$r) {
            $r['formatted_time'] = $this->formatEventTime($r['created_at']);
            $r['event_info']     = $this->resolveEventInfo($r['action'], $r['description']);
        }

        return $rows;
    }

    /**
     * Format timestamp cleanly for activity stream.
     */
    private function formatEventTime(string $timestamp): string
    {
        $time = strtotime($timestamp);
        if (!$time) return $timestamp;

        $today = strtotime('today');
        $yesterday = strtotime('yesterday');

        if ($time >= $today) {
            return date('g:i A', $time);
        } elseif ($time >= $yesterday) {
            return 'Yesterday, ' . date('g:i A', $time);
        } else {
            return date('M j, g:i A', $time);
        }
    }

    /**
     * Resolve icon, color, and friendly text for operational events.
     */
    private function resolveEventInfo(string $action, ?string $description): array
    {
        return match ($action) {
            'MACHINE_CONNECTED' => [
                'icon'    => 'bi-circle-fill text-success',
                'title'   => 'Machine connected',
                'badge'   => 'bg-success-subtle text-success',
            ],
            'MACHINE_RECONNECTED' => [
                'icon'    => 'bi-arrow-repeat text-info',
                'title'   => 'Machine reconnected',
                'badge'   => 'bg-info-subtle text-info',
            ],
            'MACHINE_DISCONNECTED' => [
                'icon'    => 'bi-wifi-off text-danger',
                'title'   => 'Machine disconnected',
                'badge'   => 'bg-danger-subtle text-danger',
            ],
            'SERVICE_COMPLETED' => [
                'icon'    => 'bi-play-fill text-primary',
                'title'   => !empty($description) ? e($description) : 'Service completed',
                'badge'   => 'bg-primary-subtle text-primary',
            ],
            'MAINTENANCE_COMPLETED' => [
                'icon'    => 'bi-wrench text-warning',
                'title'   => !empty($description) ? e($description) : 'Maintenance completed',
                'badge'   => 'bg-warning-subtle text-warning',
            ],
            'SERVICE_CONFIG_UPDATED' => [
                'icon'    => 'bi-gear-fill text-secondary',
                'title'   => !empty($description) ? e($description) : 'Configuration updated',
                'badge'   => 'bg-secondary-subtle text-secondary',
            ],
            default => [
                'icon'    => 'bi-info-circle-fill text-info',
                'title'   => !empty($description) ? e($description) : ucfirst(strtolower(str_replace('_', ' ', $action))),
                'badge'   => 'bg-info-subtle text-info',
            ],
        };
    }

    /**
     * Get all active machines, optionally filtered by owner.
     */
    public function getAllActive(?int $ownerId = null): array
    {
        if ($ownerId !== null && $ownerId > 0) {
            $machines = $this->db->query(
                'SELECT m.* FROM machines m
                 LEFT JOIN product_keys pk ON (pk.hardware_serial = m.machine_code OR pk.hardware_serial = m.machine_key)
                 WHERE m.owner_id = :owner_id OR pk.owner_id = :owner_id2
                 ORDER BY m.id ASC',
                [':owner_id' => $ownerId, ':owner_id2' => $ownerId]
            )->fetchAll();
        } else {
            $machines = $this->db->query('SELECT * FROM machines ORDER BY id ASC')->fetchAll();
        }

        foreach ($machines as &$m) {
            $m['calculated_status'] = $this->evaluateOnlineStatus($m);
        }
        return $machines;
    }

    /**
     * Get all machines.
     */
    public function getAll(?int $ownerId = null): array
    {
        return $this->getAllActive($ownerId);
    }

    /**
     * Update 20x4 LCD screen custom lines.
     */
    public function updateLcdLines(int $machineId, string $line1, string $line2, string $line3, string $line4): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE machines SET lcd_line1 = :l1, lcd_line2 = :l2, lcd_line3 = :l3, lcd_line4 = :l4 WHERE id = :id"
        );
        return $stmt->execute([
            ':l1' => mb_substr($line1, 0, 20),
            ':l2' => mb_substr($line2, 0, 20),
            ':l3' => mb_substr($line3, 0, 20),
            ':l4' => mb_substr($line4, 0, 20),
            ':id' => $machineId
        ]);
    }
}
