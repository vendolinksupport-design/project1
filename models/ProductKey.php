<?php
/**
 * ============================================================
 * VendoLink — Product Key Model
 * ============================================================
 *
 * Manages physical vending hardware product keys, activation states,
 * and owner hardware binding during registration.
 *
 * @package VendoLink
 * @version 1.0.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class ProductKey
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find product key record by key string (case-insensitive).
     *
     * @param string $key
     * @return array|null
     */
    public function findByKey(string $key): ?array
    {
        $normalizedKey = strtoupper(trim($key));
        if (empty($normalizedKey)) {
            return null;
        }

        $row = $this->db->query(
            "SELECT * FROM product_keys WHERE UPPER(TRIM(product_key)) = :key LIMIT 1",
            [':key' => $normalizedKey]
        )->fetch();

        if ($row) {
            return $row;
        }

        // Fallback: Check if key exists in legacy license_keys table and auto-sync
        try {
            $legacy = $this->db->query(
                "SELECT * FROM license_keys WHERE UPPER(TRIM(license_key)) = :key LIMIT 1",
                [':key' => $normalizedKey]
            )->fetch();

            if ($legacy) {
                $status = 'UNUSED';
                if ($legacy['status'] === 'active') {
                    $status = 'USED';
                } elseif ($legacy['status'] === 'revoked') {
                    $status = 'DISABLED';
                }

                $stmt = $this->db->getConnection()->prepare(
                    "INSERT INTO product_keys (product_key, hardware_serial, status) 
                     VALUES (:key, 'MACHINE-01', :status)
                     ON DUPLICATE KEY UPDATE status = VALUES(status)"
                );
                $stmt->execute([':key' => $normalizedKey, ':status' => $status]);

                return $this->findByKey($normalizedKey);
            }
        } catch (Throwable $e) {
            // Ignore legacy table errors
        }

        return null;
    }

    /**
     * Find product key record by primary ID.
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->query(
            "SELECT * FROM product_keys WHERE id = :id LIMIT 1",
            [':id' => $id]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Validate a product key for owner registration.
     * Returns status and exact required error message if invalid.
     *
     * @param string $key
     * @return array ['valid' => bool, 'error' => ?string, 'key' => ?array]
     */
    public function validateKey(string $key): array
    {
        $keyRecord = $this->findByKey($key);

        if (!$keyRecord) {
            return [
                'valid' => false,
                'error' => 'Invalid Product Key',
                'key'   => null,
            ];
        }

        $status = strtoupper((string)$keyRecord['status']);

        if ($status === 'USED') {
            return [
                'valid' => false,
                'error' => 'Product Key Already Activated',
                'key'   => $keyRecord,
            ];
        }

        if ($status === 'DISABLED') {
            return [
                'valid' => false,
                'error' => 'Product Disabled',
                'key'   => $keyRecord,
            ];
        }

        if ($status === 'UNUSED') {
            return [
                'valid' => true,
                'error' => null,
                'key'   => $keyRecord,
            ];
        }

        return [
            'valid' => false,
            'error' => 'Invalid Product Key',
            'key'   => $keyRecord,
        ];
    }

    /**
     * Activate a product key, link it to the owner, and bind the owner to hardware machine.
     *
     * @param int $keyId
     * @param int $ownerId
     * @param string|null $hardwareSerial
     * @return bool
     */
    public function activateKey(int $keyId, int $ownerId, ?string $hardwareSerial = null): bool
    {
        $pdo = $this->db->getConnection();

        // 1. Update product_keys status to USED and set owner_id + activated_at
        $stmt = $pdo->prepare(
            "UPDATE product_keys 
             SET status = 'USED', 
                 owner_id = :owner_id, 
                 activated_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id AND status = 'UNUSED'"
        );
        $updated = $stmt->execute([
            ':owner_id' => $ownerId,
            ':id'       => $keyId,
        ]);

        if (!$updated || $stmt->rowCount() === 0) {
            return false;
        }

        // 2. Link owner to physical machine hardware if serial provided or found
        if (!$hardwareSerial) {
            $pkRow = $this->findById($keyId);
            $hardwareSerial = $pkRow['hardware_serial'] ?? null;
        }

        if ($hardwareSerial) {
            $serial = trim($hardwareSerial);
            $check = $pdo->prepare("SELECT id FROM machines WHERE machine_code = :c OR machine_key = :k LIMIT 1");
            $check->execute([':c' => $serial, ':k' => $serial]);
            $existingId = $check->fetchColumn();

            if ($existingId) {
                $upd = $pdo->prepare("UPDATE machines SET owner_id = :owner_id WHERE id = :id");
                $upd->execute([':owner_id' => $ownerId, ':id' => $existingId]);
            } else {
                $machineKey = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $serial));
                $apiToken   = 'vl_dev_sec_' . bin2hex(random_bytes(13));
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

                // Seed default services for the newly provisioned machine
                $svc = $pdo->prepare(
                    "INSERT INTO services (machine_id, service_key, name, type, coin_value, service_seconds, is_enabled, enabled)
                     VALUES (?, 'water', 'Water Service', 'water', 5.00, 120, 1, 1),
                            (?, 'soap',  'Soap Service',  'soap',  5.00, 120, 1, 1)"
                );
                $svc->execute([$newMachId, $newMachId]);
            }
        }

        return true;
    }

    /**
     * Generate and save a new 16-character Product Key.
     * Format: VL-2026-XXXX-XXXX-XXXX
     *
     * If the hardware serial already has an active or unused product key in MySQL,
     * returns the existing key without creating a duplicate.
     *
     * @param string $hardwareSerial
     * @param string|null $customKey
     * @param string|null $assignedOwner
     * @return string
     */
    public function generateKey(string $hardwareSerial, ?string $customKey = null, ?string $assignedOwner = null): string
    {
        $serial = trim($hardwareSerial) ?: 'MACHINE-01';

        // Check if this hardware serial already has an existing UNUSED or USED key
        $existing = $this->db->query(
            "SELECT product_key FROM product_keys 
             WHERE hardware_serial = :serial AND status IN ('UNUSED', 'USED')
             ORDER BY id DESC LIMIT 1",
            [':serial' => $serial]
        )->fetch();

        if ($existing && !empty($existing['product_key'])) {
            return $existing['product_key'];
        }

        if ($customKey) {
            $key = strtoupper(trim($customKey));
        } else {
            $part1 = strtoupper(bin2hex(random_bytes(2)));
            $part2 = strtoupper(bin2hex(random_bytes(2)));
            $part3 = strtoupper(bin2hex(random_bytes(2)));
            $key   = "VL-2026-{$part1}-{$part2}-{$part3}";
        }

        $stmt = $this->db->getConnection()->prepare(
            "INSERT INTO product_keys (product_key, hardware_serial, status) 
             VALUES (:key, :serial, 'UNUSED')"
        );
        $stmt->execute([
            ':key'    => $key,
            ':serial' => $serial,
        ]);

        // Sync to legacy license_keys if present
        try {
            $lStmt = $this->db->getConnection()->prepare(
                "INSERT INTO license_keys (license_key, assigned_owner, status) 
                 VALUES (:key, :owner, 'unused')
                 ON DUPLICATE KEY UPDATE assigned_owner = VALUES(assigned_owner)"
            );
            $lStmt->execute([':key' => $key, ':owner' => $assignedOwner]);
        } catch (Throwable $e) {
            // Ignore legacy table errors
        }

        return $key;
    }

    /**
     * Get all product keys with owner information.
     *
     * @return array
     */
    public function getAllKeys(): array
    {
        return $this->db->query(
            "SELECT pk.*, u.full_name AS owner_name, u.email AS owner_email, u.username AS owner_username,
                    m.name AS machine_name, m.machine_code
             FROM product_keys pk
             LEFT JOIN users u ON pk.owner_id = u.id
             LEFT JOIN machines m ON pk.hardware_serial = m.machine_code OR pk.hardware_serial = m.machine_key
             ORDER BY pk.created_at DESC"
        )->fetchAll();
    }

    /**
     * Disable / Revoke a product key.
     *
     * @param int $id
     * @return bool
     */
    public function disableKey(int $id): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE product_keys SET status = 'DISABLED' WHERE id = :id"
        );
        return $stmt->execute([':id' => $id]);
    }
}
