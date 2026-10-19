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

        if ($status === 'USED' || !empty($keyRecord['owner_id'])) {
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
            // Verify associated machine status if machine_id is linked
            if (!empty($keyRecord['machine_id'])) {
                $mRow = $this->db->query(
                    "SELECT registration_status, owner_id FROM machines WHERE id = :id LIMIT 1",
                    [':id' => (int)$keyRecord['machine_id']]
                )->fetch();
                if ($mRow && $mRow['registration_status'] === 'disabled') {
                    return [
                        'valid' => false,
                        'error' => 'Product Disabled',
                        'key'   => $keyRecord,
                    ];
                }
                if ($mRow && ($mRow['registration_status'] === 'claimed' || !empty($mRow['owner_id']))) {
                    return [
                        'valid' => false,
                        'error' => 'Product Key Already Activated',
                        'key'   => $keyRecord,
                    ];
                }
            }

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
     * @param int|null $machineId
     * @return bool
     */
    public function activateKey(int $keyId, int $ownerId, ?string $hardwareSerial = null, ?int $machineId = null): bool
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

        // 2. Fetch key details if machineId or hardwareSerial not supplied
        $pkRow = $this->findById($keyId);
        if ($machineId === null && $pkRow && !empty($pkRow['machine_id'])) {
            $machineId = (int)$pkRow['machine_id'];
        }
        if (!$hardwareSerial && $pkRow && !empty($pkRow['hardware_serial'])) {
            $hardwareSerial = $pkRow['hardware_serial'];
        }

        // 3. Link owner to physical machine hardware
        if ($machineId !== null && $machineId > 0) {
            $upd = $pdo->prepare(
                "UPDATE machines 
                 SET owner_id = :owner_id, 
                     registration_status = CASE WHEN registration_status = 'disabled' THEN 'disabled' ELSE 'claimed' END,
                     updated_at = NOW() 
                 WHERE id = :id"
            );
            $upd->execute([':owner_id' => $ownerId, ':id' => $machineId]);
        } elseif ($hardwareSerial) {
            $serial = trim($hardwareSerial);
            $check = $pdo->prepare("SELECT id FROM machines WHERE machine_code = :c OR machine_key = :k LIMIT 1");
            $check->execute([':c' => $serial, ':k' => $serial]);
            $existingId = $check->fetchColumn();

            if ($existingId) {
                $upd = $pdo->prepare(
                    "UPDATE machines 
                     SET owner_id = :owner_id, 
                         registration_status = CASE WHEN registration_status = 'disabled' THEN 'disabled' ELSE 'claimed' END,
                         updated_at = NOW() 
                     WHERE id = :id"
                );
                $upd->execute([':owner_id' => $ownerId, ':id' => $existingId]);

                // Also populate machine_id back into product_keys
                $pdo->prepare("UPDATE product_keys SET machine_id = :mid WHERE id = :id AND machine_id IS NULL")
                    ->execute([':mid' => $existingId, ':id' => $keyId]);
            } else {
                $machineKey = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $serial));
                $apiToken   = 'vl_dev_sec_' . bin2hex(random_bytes(13));
                $ins = $pdo->prepare(
                    "INSERT INTO machines (owner_id, machine_code, machine_key, name, api_token, status, registration_status)
                     VALUES (:owner_id, :code, :key, :name, :token, 'pending', 'claimed')"
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
                            (?, 'soap',  'Soap Service',  'soap',  5.00, 60, 1, 1)"
                );
                $svc->execute([$newMachId, $newMachId]);

                // Populate machine_id back into product_keys
                $pdo->prepare("UPDATE product_keys SET machine_id = :mid WHERE id = :id AND machine_id IS NULL")
                    ->execute([':mid' => $newMachId, ':id' => $keyId]);
            }
        }

        // Auto-seed owner/machine name into lcd_line1 if empty or generic default
        $targetMid = $machineId ?: ($existingId ?? ($newMachId ?? null));
        if ($targetMid) {
            $mRow = $pdo->query("SELECT name, lcd_line1 FROM machines WHERE id = " . (int)$targetMid)->fetch(PDO::FETCH_ASSOC);
            if ($mRow && (empty($mRow['lcd_line1']) || $mRow['lcd_line1'] === '== VendoLink v2.2 ==' || $mRow['lcd_line1'] === '== VendoLink v3.0 ==')) {
                $cleanName = strtoupper(preg_replace('/[^a-zA-Z0-9\s]/', '', $mRow['name'] ?? 'Vendo Machine'));
                $cleanName = trim(substr($cleanName, 0, 14));
                $line1 = '== ' . $cleanName . ' ==';
                $line1 = str_pad($line1, 20, ' ', STR_PAD_BOTH);
                $pdo->prepare("UPDATE machines SET lcd_line1 = :l1 WHERE id = :id")->execute([':l1' => trim($line1), ':id' => $targetMid]);
            }
        }

        return true;
    }

    /**
     * Generate and save a new 16-character Product Key.
     * Format: VL-2026-XXXX-XXXX-XXXX
     *
     * If the hardware serial or machine_id already has an active or unused product key in MySQL,
     * returns the existing key without creating a duplicate.
     *
     * @param string $hardwareSerial
     * @param string|null $customKey
     * @param string|null $assignedOwner
     * @param int|null $machineId
     * @return string
     */
    public function generateKey(string $hardwareSerial, ?string $customKey = null, ?string $assignedOwner = null, ?int $machineId = null): string
    {
        $serial = trim($hardwareSerial) ?: 'MACHINE-01';

        // Deduplication: Check if this machine_id or hardware serial already has an UNUSED or USED key
        if ($machineId !== null && $machineId > 0) {
            $existing = $this->db->query(
                "SELECT product_key FROM product_keys 
                 WHERE (machine_id = :mid OR (hardware_serial = :serial AND hardware_serial != ''))
                   AND status IN ('UNUSED', 'USED')
                 ORDER BY id DESC LIMIT 1",
                [':mid' => $machineId, ':serial' => $serial]
            )->fetch();

            // Fetch actual machine code if available
            $mRow = $this->db->query("SELECT machine_code FROM machines WHERE id = :id", [':id' => $machineId])->fetch();
            if ($mRow && !empty($mRow['machine_code'])) {
                $serial = $mRow['machine_code'];
            }
        } else {
            $existing = $this->db->query(
                "SELECT product_key FROM product_keys 
                 WHERE hardware_serial = :serial AND status IN ('UNUSED', 'USED')
                 ORDER BY id DESC LIMIT 1",
                [':serial' => $serial]
            )->fetch();
        }

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
            "INSERT INTO product_keys (product_key, hardware_serial, machine_id, status) 
             VALUES (:key, :serial, :mid, 'UNUSED')"
        );
        $stmt->execute([
            ':key'    => $key,
            ':serial' => $serial,
            ':mid'    => ($machineId !== null && $machineId > 0) ? $machineId : null,
        ]);

        // Transition machine lifecycle from 'registered' to 'key_issued'
        if ($machineId !== null && $machineId > 0) {
            $this->db->query(
                "UPDATE machines SET registration_status = 'key_issued', updated_at = NOW() 
                 WHERE id = :mid AND registration_status = 'registered'",
                [':mid' => $machineId]
            );
        }

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
                    m.name AS machine_name, m.machine_code, m.device_mac, m.registration_status
             FROM product_keys pk
             LEFT JOIN users u ON pk.owner_id = u.id
             LEFT JOIN machines m ON (pk.machine_id = m.id OR (pk.hardware_serial != '' AND (pk.hardware_serial = m.machine_code OR pk.hardware_serial = m.machine_key)))
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
