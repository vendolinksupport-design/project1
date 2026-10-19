<?php
/**
 * ============================================================
 * VendoLink — Admin Dashboard Model
 * ============================================================
 *
 * Efficient aggregate queries for VendoLink Internal Admin platform dashboard.
 *
 * @package VendoLink
 * @version 1.0
 */

require_once __DIR__ . '/../config/database.php';

class AdminDashboard
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get platform summary counts.
     */
    public function getSummaryMetrics(): array
    {
        $totalCustomers    = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'owner'")->fetchColumn();
        $activeCustomers   = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'owner' AND status = 'active'")->fetchColumn();
        $inactiveCustomers = $totalCustomers - $activeCustomers;

        $totalMachines     = (int)$this->db->query("SELECT COUNT(*) FROM machines")->fetchColumn();
        $onlineMachines    = (int)$this->db->query("SELECT COUNT(*) FROM machines WHERE status = 'online'")->fetchColumn();
        $offlineMachines   = (int)$this->db->query("SELECT COUNT(*) FROM machines WHERE status = 'offline'")->fetchColumn();
        $unknownMachines   = (int)$this->db->query("SELECT COUNT(*) FROM machines WHERE status NOT IN ('online', 'offline')")->fetchColumn();

        $openSupport       = (int)$this->db->query("SELECT COUNT(*) FROM support_conversations WHERE status IN ('open', 'waiting_support', 'waiting_customer')")->fetchColumn();
        $waitingSupport    = (int)$this->db->query("SELECT COUNT(*) FROM support_conversations WHERE status = 'waiting_support'")->fetchColumn();
        $waitingCustomer   = (int)$this->db->query("SELECT COUNT(*) FROM support_conversations WHERE status = 'waiting_customer'")->fetchColumn();
        $resolvedSupport   = (int)$this->db->query("SELECT COUNT(*) FROM support_conversations WHERE status = 'resolved'")->fetchColumn();

        $activeActivations = 0;
        $unusedKeys        = 0;
        try {
            $activeActivations = (int)$this->db->query("SELECT COUNT(*) FROM product_keys WHERE status = 'USED'")->fetchColumn();
            $unusedKeys        = (int)$this->db->query("SELECT COUNT(*) FROM product_keys WHERE status = 'UNUSED'")->fetchColumn();
        } catch (Throwable $e) {
            // fallback
        }

        return [
            'total_customers'    => $totalCustomers,
            'active_customers'   => $activeCustomers,
            'inactive_customers' => $inactiveCustomers,
            'total_machines'     => $totalMachines,
            'online_machines'    => $onlineMachines,
            'offline_machines'   => $offlineMachines,
            'unknown_machines'   => $unknownMachines,
            'active_activations' => $activeActivations,
            'unused_keys'        => $unusedKeys,
            'open_support'       => $openSupport,
            'waiting_support'    => $waitingSupport,
            'waiting_customer'   => $waitingCustomer,
            'resolved_support'   => $resolvedSupport,
        ];
    }

    /**
     * Get registered machines overview with owner, keys, and live heartbeat status.
     */
    public function getMachinesOverview(int $limit = 6): array
    {
        $machines = $this->db->query(
            "SELECT m.*, 
                    u.full_name AS owner_name, 
                    u.username AS owner_username,
                    pk.product_key,
                    pk.hardware_serial AS pk_hardware_serial
             FROM machines m
             LEFT JOIN users u ON m.owner_id = u.id
             LEFT JOIN product_keys pk ON pk.id = (
                 SELECT pk2.id FROM product_keys pk2 
                 WHERE (pk2.machine_id = m.id OR (pk2.hardware_serial = m.machine_code AND pk2.hardware_serial != ''))
                 ORDER BY (pk2.machine_id = m.id) DESC, (pk2.status = 'USED') DESC, pk2.id DESC 
                 LIMIT 1
             )
             ORDER BY m.updated_at DESC 
             LIMIT {$limit}"
        )->fetchAll();

        $timeout = defined('HEARTBEAT_TIMEOUT_SECONDS') ? HEARTBEAT_TIMEOUT_SECONDS : 60;
        $now = time();

        foreach ($machines as &$m) {
            $lastSeenSec = !empty($m['last_seen']) ? strtotime($m['last_seen']) : 0;
            if ($lastSeenSec <= 0) {
                $m['calculated_status'] = 'offline';
            } else {
                $diff = $now - $lastSeenSec;
                if ($diff <= $timeout) {
                    $m['calculated_status'] = 'online';
                } elseif ($diff <= ($timeout * 3)) {
                    $m['calculated_status'] = 'stale';
                } else {
                    $m['calculated_status'] = 'offline';
                }
            }
        }
        unset($m);

        return $machines;
    }

    /**
     * Get all registered machines with owner, product key, and transaction metrics.
     */
    public function getAllMachines(): array
    {
        return $this->db->query(
            "SELECT m.*, 
                    u.full_name AS owner_name, 
                    u.email AS owner_email,
                    pk.product_key, 
                    COALESCE(NULLIF(pk.status, ''), CASE WHEN m.registration_status = 'claimed' OR m.owner_id IS NOT NULL THEN 'USED' ELSE 'UNUSED' END) AS pk_status,
                    (SELECT COUNT(*) FROM service_transactions st WHERE st.machine_id = m.id AND st.status = 'completed') AS total_transactions,
                    (SELECT COALESCE(SUM(st.amount), 0) FROM service_transactions st WHERE st.machine_id = m.id AND st.status = 'completed') AS total_revenue
             FROM machines m
             LEFT JOIN users u ON m.owner_id = u.id
             LEFT JOIN product_keys pk ON pk.id = (
                 SELECT pk2.id FROM product_keys pk2 
                 WHERE (pk2.machine_id = m.id OR (pk2.hardware_serial = m.machine_code AND pk2.hardware_serial != ''))
                 ORDER BY (pk2.machine_id = m.id) DESC, (pk2.status = 'USED') DESC, pk2.id DESC 
                 LIMIT 1
             )
             ORDER BY m.created_at DESC"
        )->fetchAll();
    }

    /**
     * Regenerate machine security API token.
     */
    public function regenerateMachineToken(int $machineId): string
    {
        $newToken = 'vl_dev_sec_' . bin2hex(random_bytes(13));
        $stmt = $this->db->getConnection()->prepare("UPDATE machines SET api_token = :token WHERE id = :id");
        $stmt->execute([':token' => $newToken, ':id' => $machineId]);
        return $newToken;
    }

    /**
     * Provision and register a new Vending Machine hardware unit.
     */
    public function registerNewMachine(string $name, string $machineCode, string $machineKey, int $userId): array
    {
        $pdo = $this->db->getConnection();

        // Check for duplicate Machine Code or Machine Key
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM machines WHERE machine_code = :code OR machine_key = :key");
        $checkStmt->execute([':code' => $machineCode, ':key' => $machineKey]);
        if ((int)$checkStmt->fetchColumn() > 0) {
            throw new InvalidArgumentException("Machine Code '{$machineCode}' or Machine Key '{$machineKey}' already exists! Hardware IDs must be unique.");
        }

        $apiToken = 'vl_dev_sec_' . bin2hex(random_bytes(13));

        $stmt = $pdo->prepare(
            "INSERT INTO machines (owner_id, machine_code, machine_key, name, api_token, status, registration_status)
             VALUES (:owner_id, :code, :key, :name, :token, 'pending', 'claimed')"
        );
        $stmt->execute([
            ':owner_id' => $userId,
            ':code'     => $machineCode,
            ':key'      => $machineKey,
            ':name'     => $name,
            ':token'    => $apiToken,
        ]);

        $machineId = (int)$pdo->lastInsertId();

        // Seed default water & soap services for the new machine
        $svcStmt = $pdo->prepare(
            "INSERT INTO services (machine_id, service_key, name, type, coin_value, service_seconds, is_enabled, enabled) VALUES
             (?, 'water', 'Water Service', 'water', 5.00, 120, 1, 1),
             (?, 'soap',  'Soap Service',  'soap',  5.00, 60, 1, 1)"
        );
        $svcStmt->execute([$machineId, $machineId]);

        return [
            'id'        => $machineId,
            'api_token' => $apiToken,
        ];
    }

    /**
     * Get recent registered customer accounts.
     */
    public function getRecentCustomers(int $limit = 5): array
    {
        return $this->db->query(
            "SELECT id, full_name, username, email, status, created_at
             FROM users
             WHERE role = 'owner'
             ORDER BY created_at DESC
             LIMIT {$limit}"
        )->fetchAll();
    }

    /**
     * Get recent support conversations snippet.
     */
    public function getRecentSupport(int $limit = 5): array
    {
        return $this->db->query(
            "SELECT c.*, u.full_name AS owner_name, u.email AS owner_email
             FROM support_conversations c
             JOIN users u ON c.user_id = u.id
             ORDER BY c.last_message_at DESC
             LIMIT {$limit}"
        )->fetchAll();
    }

    /**
     * Get all registered machine owners with accurate per-owner total machines count.
     */
    public function getAllCustomers(): array
    {
        return $this->db->query(
            "SELECT u.id, u.full_name, u.username, u.email, u.status, u.email_verified, u.created_at,
                    (
                        SELECT COUNT(DISTINCT m.id)
                        FROM machines m
                        LEFT JOIN product_keys pk ON (pk.machine_id = m.id OR pk.hardware_serial = m.machine_code OR pk.hardware_serial = m.machine_key)
                        WHERE m.owner_id = u.id OR pk.owner_id = u.id
                    ) AS total_machines
             FROM users u
             WHERE u.role = 'owner'
             ORDER BY u.created_at DESC"
        )->fetchAll();
    }

    /**
     * Get all registered machine owners with their connected machines and telemetry.
     */
    public function getAllCustomersWithMachines(): array
    {
        $customers = $this->getAllCustomers();
        if (empty($customers)) {
            return [];
        }

        // Fetch all machines with linked product keys
        $allMachines = $this->db->query(
            "SELECT DISTINCT 
                m.id, m.owner_id, m.machine_code, m.machine_key, m.name, 
                m.status, m.last_seen, m.ip_address, m.firmware_version, m.location, m.registration_status,
                pk.product_key, pk.hardware_serial, pk.owner_id as pk_owner_id
             FROM machines m
             LEFT JOIN product_keys pk ON (pk.machine_id = m.id OR pk.hardware_serial = m.machine_code OR pk.hardware_serial = m.machine_key)
             ORDER BY m.id ASC"
        )->fetchAll();

        $timeout = defined('HEARTBEAT_TIMEOUT_SECONDS') ? HEARTBEAT_TIMEOUT_SECONDS : 60;
        $now = time();

        // Group machines by owner_id
        $machinesByOwner = [];
        $seenOwnerMachine = [];

        foreach ($allMachines as $m) {
            $ownerId = (int)($m['owner_id'] ?? 0);
            if ($ownerId <= 0 && !empty($m['pk_owner_id'])) {
                $ownerId = (int)$m['pk_owner_id'];
            }
            if ($ownerId <= 0) {
                continue;
            }

            $dedupKey = $ownerId . '_' . $m['id'];
            if (isset($seenOwnerMachine[$dedupKey])) {
                continue;
            }
            $seenOwnerMachine[$dedupKey] = true;

            // Compute online status
            $lastSeenTime = !empty($m['last_seen']) ? strtotime($m['last_seen']) : false;
            if ($lastSeenTime === false) {
                $m['calculated_status'] = 'offline';
            } else {
                $diff = $now - $lastSeenTime;
                if ($diff <= $timeout) {
                    $m['calculated_status'] = 'online';
                } elseif ($diff <= ($timeout * 3)) {
                    $m['calculated_status'] = 'stale';
                } else {
                    $m['calculated_status'] = 'offline';
                }
            }

            if (!isset($machinesByOwner[$ownerId])) {
                $machinesByOwner[$ownerId] = [];
            }
            $machinesByOwner[$ownerId][] = $m;
        }

        foreach ($customers as &$c) {
            $uid = (int)$c['id'];
            $c['machines'] = $machinesByOwner[$uid] ?? [];
            $c['total_machines'] = count($c['machines']);
        }
        unset($c);

        return $customers;
    }

    /**
     * Toggle customer account status (active/inactive).
     */
    public function toggleCustomerStatus(int $userId, string $status): bool
    {
        $status = in_array($status, ['active', 'inactive'], true) ? $status : 'inactive';
        $stmt = $this->db->getConnection()->prepare("UPDATE users SET status = :status WHERE id = :id AND role = 'owner'");
        return $stmt->execute([':status' => $status, ':id' => $userId]);
    }

    /**
     * Manually verify a customer's email address.
     */
    public function verifyCustomerEmail(int $userId): bool
    {
        $stmt = $this->db->getConnection()->prepare("UPDATE users SET email_verified = 1, status = 'active' WHERE id = :id AND role = 'owner'");
        return $stmt->execute([':id' => $userId]);
    }

    /**
     * Update customer (machine owner) account details.
     */
    public function updateCustomerAccount(int $userId, string $fullName, string $email, string $username, ?string $password = null): bool
    {
        $pdo = $this->db->getConnection();
        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $stmt = $pdo->prepare("UPDATE users SET full_name = :name, email = :email, username = :username, password = :hash, remember_token = NULL WHERE id = :id AND role = 'owner'");
            return $stmt->execute([':name' => $fullName, ':email' => $email, ':username' => $username, ':hash' => $hash, ':id' => $userId]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name = :name, email = :email, username = :username WHERE id = :id AND role = 'owner'");
            return $stmt->execute([':name' => $fullName, ':email' => $email, ':username' => $username, ':id' => $userId]);
        }
    }

    /**
     * Get recent platform activity logs, optionally filtering out repetitive dev auto-login spam.
     */
    public function getRecentActivity(int $limit = 8, bool $filterAuthNoise = true): array
    {
        $where = $filterAuthNoise ? "WHERE al.action NOT IN ('DEV_AUTO_LOGIN', 'LOGOUT')" : "";
        $results = $this->db->query(
            "SELECT al.*, u.full_name, u.username, u.role
             FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.id
             {$where}
             ORDER BY al.created_at DESC
             LIMIT {$limit}"
        )->fetchAll();

        // If filtering results in empty, fallback to non-filtered so the stream is never empty
        if (empty($results) && $filterAuthNoise) {
            return $this->getRecentActivity($limit, false);
        }

        return $results;
    }

    /**
     * Get the latest OTA firmware release.
     */
    public function getLatestOtaRelease(): ?array
    {
        $res = $this->db->query(
            "SELECT * FROM ota_releases ORDER BY is_latest DESC, id DESC LIMIT 1"
        )->fetch();
        return $res ?: null;
    }

    /**
     * Get all OTA firmware releases.
     */
    public function getOtaReleases(): array
    {
        return $this->db->query(
            "SELECT * FROM ota_releases ORDER BY is_latest DESC, version ASC"
        )->fetchAll();
    }

    /**
     * Create a new OTA firmware release.
     */
    public function createOtaRelease(string $version, string $filename, int $filesize, string $changelog): bool
    {
        $pdo = $this->db->getConnection();
        $pdo->query("UPDATE ota_releases SET is_latest = 0");
        $stmt = $pdo->prepare("INSERT INTO ota_releases (version, filename, filesize, changelog, is_latest) VALUES (:version, :filename, :filesize, :changelog, 1)");
        return $stmt->execute([
            ':version'   => $version,
            ':filename'  => $filename,
            ':filesize'  => $filesize,
            ':changelog' => $changelog
        ]);
    }

    /**
     * Delete an OTA firmware release and clean up associated binary file and deployments.
     */
    public function deleteOtaRelease(int $releaseId): bool
    {
        $pdo = $this->db->getConnection();
        $rel = $this->db->query("SELECT * FROM ota_releases WHERE id = :id", [':id' => $releaseId])->fetch();
        if (!$rel) {
            return false;
        }

        // Delete binary file from uploads directory if present
        $filePath = ROOT_PATH . '/uploads/firmware/' . $rel['filename'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        // Delete related deployments referencing this release
        $pdo->prepare("DELETE FROM ota_deployments WHERE release_id = :id")->execute([':id' => $releaseId]);

        // Delete release record
        $stmt = $pdo->prepare("DELETE FROM ota_releases WHERE id = :id");
        $success = $stmt->execute([':id' => $releaseId]);

        // If deleted build was marked latest, promote newest remaining build
        if (!empty($rel['is_latest'])) {
            $pdo->query("UPDATE ota_releases SET is_latest = 1 ORDER BY id DESC LIMIT 1");
        }

        return $success;
    }

    /**
     * Get active or recent OTA deployments with target details.
     */
    public function getOtaDeployments(int $limit = 20): array
    {
        return $this->db->query(
            "SELECT d.*, r.version, r.filename, r.filesize, r.changelog,
                    u.full_name AS target_customer_name,
                    m.name AS target_machine_name, m.machine_code AS target_machine_code
             FROM ota_deployments d
             JOIN ota_releases r ON d.release_id = r.id
             LEFT JOIN users u ON (d.target_type = 'customer' AND d.target_id = u.id)
             LEFT JOIN machines m ON (d.target_type = 'machine' AND d.target_id = m.id)
             ORDER BY d.created_at DESC
             LIMIT {$limit}"
        )->fetchAll();
    }

    /**
     * Get active OTA deployments enriched with live rollout progress and target machine statuses.
     */
    public function getActiveOtaDeployments(): array
    {
        $deployments = $this->db->query(
            "SELECT d.*, r.version, r.filename, r.filesize, r.changelog,
                    u.full_name AS target_customer_name,
                    m.name AS target_machine_name, m.machine_code AS target_machine_code
             FROM ota_deployments d
             JOIN ota_releases r ON d.release_id = r.id
             LEFT JOIN users u ON (d.target_type = 'customer' AND d.target_id = u.id)
             LEFT JOIN machines m ON (d.target_type = 'machine' AND d.target_id = m.id)
             WHERE d.status = 'active'
             ORDER BY d.created_at DESC"
        )->fetchAll();

        $pdo = $this->db->getConnection();

        foreach ($deployments as &$dep) {
            $cleanTarget = strtolower(ltrim(trim($dep['version']), 'v'));

            if ($dep['target_type'] === 'machine') {
                $mStmt = $pdo->prepare(
                    "SELECT id, machine_code, name, firmware_version, last_seen, registration_status
                     FROM machines WHERE id = :mid LIMIT 1"
                );
                $mStmt->execute([':mid' => $dep['target_id']]);
                $targetMachines = $mStmt->fetchAll();
            } elseif ($dep['target_type'] === 'customer') {
                $mStmt = $pdo->prepare(
                    "SELECT id, machine_code, name, firmware_version, last_seen, registration_status
                     FROM machines WHERE owner_id = :oid AND registration_status != 'disabled'
                     ORDER BY id ASC"
                );
                $mStmt->execute([':oid' => $dep['target_id']]);
                $targetMachines = $mStmt->fetchAll();
            } else {
                // target_type === 'all'
                $targetMachines = $pdo->query(
                    "SELECT id, machine_code, name, firmware_version, last_seen, registration_status
                     FROM machines WHERE registration_status != 'disabled'
                     ORDER BY id ASC"
                )->fetchAll();
            }

            $updatedCount = 0;
            $now = time();
            foreach ($targetMachines as &$tm) {
                $cleanFw = strtolower(ltrim(trim($tm['firmware_version'] ?? ''), 'v'));
                $tm['is_updated'] = (!empty($cleanFw) && $cleanFw === $cleanTarget);
                if ($tm['is_updated']) {
                    $updatedCount++;
                }
                $lastSeenSec = !empty($tm['last_seen']) ? strtotime($tm['last_seen']) : 0;
                $tm['is_online'] = ($lastSeenSec > 0 && ($now - $lastSeenSec) <= 90);
            }
            unset($tm);

            $totalTargets = count($targetMachines);
            $dep['target_machines'] = $targetMachines;
            $dep['total_targets']   = $totalTargets;
            $dep['updated_count']   = $updatedCount;
            $dep['pending_count']   = max(0, $totalTargets - $updatedCount);
            $dep['progress_pct']    = ($totalTargets > 0) ? min(100, (int)round(($updatedCount / $totalTargets) * 100)) : 0;
            $dep['is_finished']     = ($totalTargets > 0 && $updatedCount >= $totalTargets);

            // Auto-complete if all target machines are running the new target version
            if ($dep['is_finished']) {
                $this->completeOtaDeployment((int)$dep['id']);
                $dep['status'] = 'completed';
            }
        }
        unset($dep);

        return array_values(array_filter($deployments, fn($d) => $d['status'] === 'active'));
    }

    /**
     * Get historical (completed / cancelled) OTA deployments.
     */
    public function getOtaDeploymentHistory(int $limit = 10, int $offset = 0, ?string $statusFilter = null): array
    {
        $pdo = $this->db->getConnection();
        
        $whereSql = "WHERE d.status IN ('completed', 'cancelled')";
        $params = [];

        if ($statusFilter === 'completed' || $statusFilter === 'cancelled') {
            $whereSql = "WHERE d.status = :status";
            $params[':status'] = $statusFilter;
        }

        $stmt = $pdo->prepare(
            "SELECT d.*, r.version, r.filename, r.filesize, r.changelog,
                    u.full_name AS target_customer_name,
                    m.name AS target_machine_name, m.machine_code AS target_machine_code
             FROM ota_deployments d
             JOIN ota_releases r ON d.release_id = r.id
             LEFT JOIN users u ON (d.target_type = 'customer' AND d.target_id = u.id)
             LEFT JOIN machines m ON (d.target_type = 'machine' AND d.target_id = m.id)
             {$whereSql}
             ORDER BY d.updated_at DESC, d.id DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count historical OTA deployments.
     */
    public function countOtaDeploymentHistory(?string $statusFilter = null): int
    {
        $pdo = $this->db->getConnection();
        if ($statusFilter === 'completed' || $statusFilter === 'cancelled') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM ota_deployments WHERE status = :status");
            $stmt->execute([':status' => $statusFilter]);
            return (int)$stmt->fetchColumn();
        }
        return (int)$pdo->query(
            "SELECT COUNT(*) FROM ota_deployments WHERE status IN ('completed', 'cancelled')"
        )->fetchColumn();
    }

    /**
     * Get counts breakdown for deployment history (total, completed, cancelled).
     */
    public function getOtaHistoryCounts(): array
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->query(
            "SELECT status, COUNT(*) AS cnt 
             FROM ota_deployments 
             WHERE status IN ('completed', 'cancelled') 
             GROUP BY status"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];

        $completed = (int)($rows['completed'] ?? 0);
        $cancelled = (int)($rows['cancelled'] ?? 0);

        return [
            'all'       => $completed + $cancelled,
            'completed' => $completed,
            'cancelled' => $cancelled
        ];
    }

    /**
     * Mark an active OTA deployment as completed.
     */
    public function completeOtaDeployment(int $deploymentId): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE ota_deployments SET status = 'completed' WHERE id = :id"
        );
        return $stmt->execute([':id' => $deploymentId]);
    }

    /**
     * Create/activate an OTA deployment for a target.
     * Prevents duplicate active deployments or rapid re-submissions.
     */
    public function createOtaDeployment(int $releaseId, string $targetType, ?int $targetId = null): int
    {
        $pdo = $this->db->getConnection();

        // 1. Deduplication Guard: If an identical deployment is already active, reuse it
        if ($targetId === null || $targetId <= 0) {
            $checkActive = $pdo->prepare(
                "SELECT id FROM ota_deployments 
                 WHERE release_id = :rid AND target_type = :type AND target_id IS NULL AND status = 'active'
                 LIMIT 1"
            );
            $checkActive->execute([':rid' => $releaseId, ':type' => $targetType]);
        } else {
            $checkActive = $pdo->prepare(
                "SELECT id FROM ota_deployments 
                 WHERE release_id = :rid AND target_type = :type AND target_id = :tid AND status = 'active'
                 LIMIT 1"
            );
            $checkActive->execute([':rid' => $releaseId, ':type' => $targetType, ':tid' => $targetId]);
        }
        $existing = $checkActive->fetch();
        if ($existing) {
            return (int)$existing['id'];
        }

        // 2. Rapid Resubmission Guard: If an identical deployment completed within the last 60 seconds, reuse it
        if ($targetId === null || $targetId <= 0) {
            $checkRecent = $pdo->prepare(
                "SELECT id FROM ota_deployments 
                 WHERE release_id = :rid AND target_type = :type AND target_id IS NULL 
                   AND status = 'completed' AND updated_at >= NOW() - INTERVAL 60 SECOND
                 ORDER BY id DESC LIMIT 1"
            );
            $checkRecent->execute([':rid' => $releaseId, ':type' => $targetType]);
        } else {
            $checkRecent = $pdo->prepare(
                "SELECT id FROM ota_deployments 
                 WHERE release_id = :rid AND target_type = :type AND target_id = :tid 
                   AND status = 'completed' AND updated_at >= NOW() - INTERVAL 60 SECOND
                 ORDER BY id DESC LIMIT 1"
            );
            $checkRecent->execute([':rid' => $releaseId, ':type' => $targetType, ':tid' => $targetId]);
        }
        $recent = $checkRecent->fetch();
        if ($recent) {
            return (int)$recent['id'];
        }

        $stmt = $pdo->prepare(
            "INSERT INTO ota_deployments (release_id, target_type, target_id, status)
             VALUES (:rid, :type, :tid, 'active')"
        );
        $stmt->execute([
            ':rid'  => $releaseId,
            ':type' => $targetType,
            ':tid'  => $targetId
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Delete an OTA deployment record from history.
     */
    public function deleteOtaDeployment(int $deploymentId): bool
    {
        $stmt = $this->db->getConnection()->prepare("DELETE FROM ota_deployments WHERE id = :id");
        return $stmt->execute([':id' => $deploymentId]);
    }

    /**
     * Automatically clean up duplicate completed OTA deployments within a 5-minute window.
     */
    public function cleanDuplicateOtaHistory(): int
    {
        $pdo = $this->db->getConnection();
        $sql = "DELETE d1 FROM ota_deployments d1
                INNER JOIN ota_deployments d2 
                WHERE d1.id > d2.id 
                  AND d1.release_id = d2.release_id 
                  AND d1.target_type = d2.target_type 
                  AND (d1.target_id = d2.target_id OR (d1.target_id IS NULL AND d2.target_id IS NULL))
                  AND d1.status = 'completed' AND d2.status = 'completed'
                  AND ABS(TIMESTAMPDIFF(SECOND, d2.created_at, d1.created_at)) <= 300";
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->rowCount() : 0;
    }

    /**
     * Cancel an active OTA deployment.
     */
    public function cancelOtaDeployment(int $deploymentId): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE ota_deployments SET status = 'cancelled' WHERE id = :id"
        );
        return $stmt->execute([':id' => $deploymentId]);
    }

    /**
     * Check if a machine has a pending active OTA firmware deployment.
     */
    public function checkPendingOta(int $machineId, ?int $ownerId, string $currentVersion): ?array
    {
        $pdo = $this->db->getConnection();

        if ($ownerId !== null && $ownerId > 0) {
            $stmt = $pdo->prepare(
                "SELECT d.id AS deployment_id, d.target_type, r.id AS release_id, r.version, r.filename, r.filesize, r.changelog
                 FROM ota_deployments d
                 JOIN ota_releases r ON d.release_id = r.id
                 WHERE d.status = 'active'
                   AND (
                       (d.target_type = 'machine' AND d.target_id = :mid)
                       OR (d.target_type = 'customer' AND d.target_id = :oid)
                       OR (d.target_type = 'all')
                   )
                 ORDER BY FIELD(d.target_type, 'machine', 'customer', 'all'), d.id DESC
                 LIMIT 1"
            );
            $stmt->execute([':mid' => $machineId, ':oid' => $ownerId]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT d.id AS deployment_id, d.target_type, r.id AS release_id, r.version, r.filename, r.filesize, r.changelog
                 FROM ota_deployments d
                 JOIN ota_releases r ON d.release_id = r.id
                 WHERE d.status = 'active'
                   AND (
                       (d.target_type = 'machine' AND d.target_id = :mid)
                       OR (d.target_type = 'all')
                   )
                 ORDER BY FIELD(d.target_type, 'machine', 'all'), d.id DESC
                 LIMIT 1"
            );
            $stmt->execute([':mid' => $machineId]);
        }
        $deployment = $stmt->fetch();

        if (!$deployment) {
            return null;
        }

        // Compare versions (e.g. "3.0.0" vs "v3.1.0" or "v3.0.0")
        $cleanCurrent = strtolower(ltrim(trim($currentVersion), 'v'));
        $cleanTarget  = strtolower(ltrim(trim($deployment['version']), 'v'));

        if (!empty($cleanCurrent) && !empty($cleanTarget) && $cleanCurrent === $cleanTarget) {
            // Already running target version -> check if deployment can be marked completed
            if ($deployment['target_type'] === 'machine') {
                $this->completeOtaDeployment((int)$deployment['deployment_id']);
            } elseif ($deployment['target_type'] === 'all') {
                // If all registered active machines now match the target version, mark deployment completed
                $countPending = $pdo->query("SELECT COUNT(*) FROM machines WHERE registration_status != 'disabled' AND LOWER(TRIM(LEADING 'v' FROM COALESCE(firmware_version, '')) ) != " . $pdo->quote($cleanTarget))->fetchColumn();
                if ((int)$countPending === 0) {
                    $this->completeOtaDeployment((int)$deployment['deployment_id']);
                }
            } elseif ($deployment['target_type'] === 'customer') {
                $countPending = $pdo->query("SELECT COUNT(*) FROM machines WHERE owner_id = " . (int)$deployment['target_id'] . " AND registration_status != 'disabled' AND LOWER(TRIM(LEADING 'v' FROM COALESCE(firmware_version, '')) ) != " . $pdo->quote($cleanTarget))->fetchColumn();
                if ((int)$countPending === 0) {
                    $this->completeOtaDeployment((int)$deployment['deployment_id']);
                }
            }
            return null;
        }

        return $deployment;
    }

    /**
     * Get all product license keys from product_keys table (single source of truth).
     */
    public function getLicenseKeys(): array
    {
        try {
            return $this->db->query(
                "SELECT pk.id, pk.product_key, pk.product_key AS license_key, pk.hardware_serial, pk.machine_id,
                        pk.status AS raw_status,
                        LOWER(pk.status) AS status,
                        COALESCE(u.full_name, lk.assigned_owner, 'Unassigned') AS assigned_owner,
                        pk.created_at, pk.activated_at,
                        m.name AS machine_name, m.device_mac, m.registration_status
                 FROM product_keys pk
                 LEFT JOIN users u ON pk.owner_id = u.id
                 LEFT JOIN license_keys lk ON UPPER(TRIM(lk.license_key)) = UPPER(TRIM(pk.product_key))
                 LEFT JOIN machines m ON (pk.machine_id = m.id OR (pk.hardware_serial != '' AND (pk.hardware_serial = m.machine_code OR pk.hardware_serial = m.machine_key)))
                 ORDER BY pk.created_at DESC"
            )->fetchAll();
        } catch (Throwable $e) {
            return $this->db->query(
                "SELECT * FROM license_keys ORDER BY created_at DESC"
            )->fetchAll();
        }
    }

    /**
     * Generate a new 16-character product activation key.
     * Uses ProductKey model to link machine_id, transition registration_status,
     * and prevent duplicate key generation.
     */
    public function generateLicenseKey(?string $assignedOwner = null, string $hardwareSerial = 'MACHINE-01', ?int $machineId = null): string
    {
        require_once __DIR__ . '/ProductKey.php';
        $pkModel = new ProductKey();
        return $pkModel->generateKey($hardwareSerial, null, $assignedOwner, $machineId);
    }

    /**
     * Revoke an active or unused license key.
     */
    public function revokeLicenseKey(int $id): bool
    {
        $pdo = $this->db->getConnection();
        
        $pkRow = $this->db->query("SELECT id, product_key FROM product_keys WHERE id = :id", [':id' => $id])->fetch();
        if ($pkRow) {
            $keyStr = strtoupper(trim($pkRow['product_key']));
            $pStmt = $pdo->prepare("UPDATE product_keys SET status = 'DISABLED' WHERE id = :id");
            $pStmt->execute([':id' => $id]);

            try {
                $lStmt = $pdo->prepare("UPDATE license_keys SET status = 'revoked' WHERE UPPER(TRIM(license_key)) = :key");
                $lStmt->execute([':key' => $keyStr]);
            } catch (Throwable $e) {}

            return true;
        }

        $keyRow = $this->db->query("SELECT license_key FROM license_keys WHERE id = :id", [':id' => $id])->fetch();
        if ($keyRow) {
            $keyStr = strtoupper(trim($keyRow['license_key']));
            $pStmt = $pdo->prepare("UPDATE product_keys SET status = 'DISABLED' WHERE UPPER(TRIM(product_key)) = :key");
            $pStmt->execute([':key' => $keyStr]);

            $stmt = $pdo->prepare("UPDATE license_keys SET status = 'revoked' WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        }

        return false;
    }
}
