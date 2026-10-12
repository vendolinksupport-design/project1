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
     * Get registered machines overview.
     */
    public function getMachinesOverview(int $limit = 5): array
    {
        return $this->db->query(
            "SELECT * FROM machines ORDER BY updated_at DESC LIMIT {$limit}"
        )->fetchAll();
    }

    /**
     * Get all registered machines.
     */
    public function getAllMachines(): array
    {
        return $this->db->query(
            "SELECT * FROM machines ORDER BY created_at DESC"
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
            "INSERT INTO machines (machine_code, machine_key, name, api_token, status)
             VALUES (:code, :key, :name, :token, 'pending')"
        );
        $stmt->execute([
            ':code'  => $machineCode,
            ':key'   => $machineKey,
            ':name'  => $name,
            ':token' => $apiToken,
        ]);

        $machineId = (int)$pdo->lastInsertId();

        // Seed default water & soap services for the new machine
        $svcStmt = $pdo->prepare(
            "INSERT INTO services (machine_id, service_key, name, type, coin_value, service_seconds, is_enabled, enabled) VALUES
             (?, 'water', 'Water Service', 'water', 5.00, 120, 1, 1),
             (?, 'soap',  'Soap Service',  'soap',  5.00, 120, 1, 1)"
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
     * Get all registered machine owners with total machines count.
     */
    public function getAllCustomers(): array
    {
        return $this->db->query(
            "SELECT u.id, u.full_name, u.username, u.email, u.status, u.email_verified, u.created_at,
                    (SELECT COUNT(*) FROM machines) AS total_machines
             FROM users u
             WHERE u.role = 'owner'
             ORDER BY u.created_at DESC"
        )->fetchAll();
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
     * Get recent platform activity logs.
     */
    public function getRecentActivity(int $limit = 8): array
    {
        return $this->db->query(
            "SELECT al.*, u.full_name, u.username, u.role
             FROM activity_logs al
             LEFT JOIN users u ON al.user_id = u.id
             ORDER BY al.created_at DESC
             LIMIT {$limit}"
        )->fetchAll();
    }

    /**
     * Get all OTA firmware releases.
     */
    public function getOtaReleases(): array
    {
        return $this->db->query(
            "SELECT * FROM ota_releases ORDER BY created_at DESC"
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
     * Get all product license keys from product_keys table (single source of truth).
     */
    public function getLicenseKeys(): array
    {
        try {
            return $this->db->query(
                "SELECT pk.id, pk.product_key, pk.product_key AS license_key, pk.hardware_serial,
                        pk.status AS raw_status,
                        LOWER(pk.status) AS status,
                        COALESCE(u.full_name, lk.assigned_owner, 'Unassigned') AS assigned_owner,
                        pk.created_at, pk.activated_at
                 FROM product_keys pk
                 LEFT JOIN users u ON pk.owner_id = u.id
                 LEFT JOIN license_keys lk ON UPPER(TRIM(lk.license_key)) = UPPER(TRIM(pk.product_key))
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
     * Checks if hardware serial already has an active/unused product key.
     * If existing, returns the existing key (never creates duplicates).
     */
    public function generateLicenseKey(?string $assignedOwner = null, string $hardwareSerial = 'MACHINE-01'): string
    {
        $serial = trim($hardwareSerial) ?: 'MACHINE-01';

        // Deduplication: Return existing key if one is already associated with this hardware serial
        $existing = $this->db->query(
            "SELECT product_key FROM product_keys 
             WHERE hardware_serial = :serial AND status IN ('UNUSED', 'USED')
             ORDER BY id DESC LIMIT 1",
            [':serial' => $serial]
        )->fetch();

        if ($existing && !empty($existing['product_key'])) {
            return $existing['product_key'];
        }

        $part1 = strtoupper(bin2hex(random_bytes(2)));
        $part2 = strtoupper(bin2hex(random_bytes(2)));
        $part3 = strtoupper(bin2hex(random_bytes(2)));
        $key   = "VL-2026-{$part1}-{$part2}-{$part3}";

        $pdo = $this->db->getConnection();

        // 1. Save permanently to product_keys
        $pStmt = $pdo->prepare(
            "INSERT INTO product_keys (product_key, hardware_serial, status) 
             VALUES (:key, :serial, 'UNUSED')"
        );
        $pStmt->execute([':key' => $key, ':serial' => $serial]);

        // 2. Sync to legacy license_keys
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO license_keys (license_key, assigned_owner, status) 
                 VALUES (:key, :owner, 'unused') 
                 ON DUPLICATE KEY UPDATE assigned_owner = VALUES(assigned_owner)"
            );
            $stmt->execute([':key' => $key, ':owner' => $assignedOwner]);
        } catch (Throwable $e) {
            // Ignore legacy table errors
        }

        return $key;
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
