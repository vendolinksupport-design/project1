<?php
/**
 * ============================================================
 * VendoLink — System Pre-Sale Reset Service
 * ============================================================
 *
 * Provides safe, transactional database cleaning to return VendoLink
 * to a clean pre-sale state before deployment or sale to customers.
 *
 * Guarantees:
 * 1. Preserves the Primary Administrator account (ID: 1, admin).
 * 2. Preserves all application configuration, SMTP settings, and OTA releases.
 * 3. Preserves all database tables, columns, indexes, and foreign keys.
 * 4. Safely deletes test machines, customer accounts, transactions, logs,
 *    and product keys in strict foreign-key order.
 * 5. Creates an automatic SQL backup before running any deletions.
 * 6. Never uses DROP TABLE, DROP DATABASE, or TRUNCATE.
 *
 * @package VendoLink
 * @version 1.0.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class SystemReset
{
    private Database $db;
    private PDO $pdo;

    public function __construct()
    {
        $this->db  = Database::getInstance();
        $this->pdo = $this->db->getConnection();
    }

    /**
     * Get summary counts of current data in the database.
     */
    public function getSummary(): array
    {
        return [
            'admin_users'           => (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'administrator' OR id = 1")->fetchColumn(),
            'customer_users'        => (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE id != 1 AND role != 'administrator'")->fetchColumn(),
            'machines'              => (int)$this->pdo->query("SELECT COUNT(*) FROM machines")->fetchColumn(),
            'services'              => (int)$this->pdo->query("SELECT COUNT(*) FROM services")->fetchColumn(),
            'product_keys'          => (int)$this->pdo->query("SELECT COUNT(*) FROM product_keys")->fetchColumn(),
            'license_keys'          => (int)$this->pdo->query("SELECT COUNT(*) FROM license_keys")->fetchColumn(),
            'service_transactions'  => (int)$this->pdo->query("SELECT COUNT(*) FROM service_transactions")->fetchColumn(),
            'cash_collections'      => (int)$this->pdo->query("SELECT COUNT(*) FROM cash_collections")->fetchColumn(),
            'configuration_history' => (int)$this->pdo->query("SELECT COUNT(*) FROM configuration_history")->fetchColumn(),
            'maintenance_history'   => (int)$this->pdo->query("SELECT COUNT(*) FROM maintenance_history")->fetchColumn(),
            'maintenance_items'     => (int)$this->pdo->query("SELECT COUNT(*) FROM maintenance_items")->fetchColumn(),
            'notifications'         => (int)$this->pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn(),
            'activity_logs'         => (int)$this->pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn(),
            'support_conversations' => (int)$this->pdo->query("SELECT COUNT(*) FROM support_conversations")->fetchColumn(),
            'support_messages'      => (int)$this->pdo->query("SELECT COUNT(*) FROM support_messages")->fetchColumn(),
            'settings'              => (int)$this->pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn(),
            'ota_releases'          => (int)$this->pdo->query("SELECT COUNT(*) FROM ota_releases")->fetchColumn(),
        ];
    }

    /**
     * Create a timestamped SQL backup of the database before resetting.
     *
     * @return string Absolute path to backup file
     * @throws RuntimeException If backup creation fails
     */
    public function createBackup(): string
    {
        $backupDir = ROOT_PATH . '/database/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Ymd_His');
        $filename  = "vendolink_db_pre_reset_{$timestamp}.sql";
        $filepath  = $backupDir . '/' . $filename;

        // Try mysqldump binary first
        $mysqldumpPath = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        if (!file_exists($mysqldumpPath)) {
            $mysqldumpPath = 'mysqldump';
        }

        $host = DB_HOST;
        $port = DB_PORT;
        $user = DB_USER;
        $pass = DB_PASS;
        $name = DB_NAME;

        $cmd = sprintf(
            '"%s" -h %s -P %s -u %s %s %s > "%s"',
            $mysqldumpPath,
            escapeshellarg($host),
            escapeshellarg((string)$port),
            escapeshellarg($user),
            !empty($pass) ? '-p' . escapeshellarg($pass) : '',
            escapeshellarg($name),
            $filepath
        );

        @exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($filepath) || filesize($filepath) === 0) {
            // Fallback: Internal PHP PDO SQL exporter
            $this->exportSqlBackup($filepath);
        }

        if (!file_exists($filepath) || filesize($filepath) === 0) {
            throw new RuntimeException("Failed to generate database backup at: {$filepath}");
        }

        return $filepath;
    }

    /**
     * Internal PHP PDO SQL exporter fallback.
     */
    private function exportSqlBackup(string $filepath): void
    {
        $tables = $this->pdo->query("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $sql = "-- VendoLink Database Pre-Reset Backup\n";
        $sql .= "-- Generated at: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $t) {
            $createTable = $this->pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC);
            $sql .= "DROP TABLE IF EXISTS `{$t}`;\n";
            $sql .= $createTable['Create Table'] . ";\n\n";

            $rows = $this->pdo->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $cols = array_map(fn($c) => "`{$c}`", array_keys($row));
                    $vals = array_map(function($v) {
                        if ($v === null) return 'NULL';
                        return $this->pdo->quote($v);
                    }, array_values($row));
                    $sql .= "INSERT INTO `{$t}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
                }
                $sql .= "\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        file_put_contents($filepath, $sql);
    }

    /**
     * Execute safe, transactional database cleaning.
     *
     * @param int $adminUserId The ID of the admin account to preserve (defaults to 1).
     * @return array Detailed report of records deleted and preserved.
     */
    public function executeReset(int $adminUserId = 1): array
    {
        // 1. Verify that Admin ID exists before proceeding
        $checkAdmin = $this->pdo->prepare("SELECT id, username, email, role, password FROM users WHERE id = :id LIMIT 1");
        $checkAdmin->execute([':id' => $adminUserId]);
        $adminAccount = $checkAdmin->fetch(PDO::FETCH_ASSOC);

        if (!$adminAccount) {
            throw new RuntimeException("Integrity check failed: Administrator account ID #{$adminUserId} not found. Aborting reset.");
        }

        // 2. Automatically create pre-reset backup
        $backupFile = $this->createBackup();

        $deletedCounts = [];

        // 3. Execute transactional deletion in strict FK child-to-parent order
        $this->pdo->beginTransaction();

        try {
            // Child tables first
            $deletedCounts['service_transactions']  = $this->pdo->exec("DELETE FROM `service_transactions`");
            $deletedCounts['cash_collections']      = $this->pdo->exec("DELETE FROM `cash_collections`");
            $deletedCounts['configuration_history'] = $this->pdo->exec("DELETE FROM `configuration_history`");
            $deletedCounts['maintenance_history']   = $this->pdo->exec("DELETE FROM `maintenance_history`");
            $deletedCounts['maintenance_items']     = $this->pdo->exec("DELETE FROM `maintenance_items`");
            $deletedCounts['notifications']         = $this->pdo->exec("DELETE FROM `notifications`");
            $deletedCounts['activity_logs']         = $this->pdo->exec("DELETE FROM `activity_logs`");
            $deletedCounts['support_messages']      = $this->pdo->exec("DELETE FROM `support_messages`");
            $deletedCounts['support_conversations'] = $this->pdo->exec("DELETE FROM `support_conversations`");
            $deletedCounts['product_keys']          = $this->pdo->exec("DELETE FROM `product_keys`");
            $deletedCounts['license_keys']          = $this->pdo->exec("DELETE FROM `license_keys`");
            $deletedCounts['services']              = $this->pdo->exec("DELETE FROM `services`");
            $deletedCounts['machines']              = $this->pdo->exec("DELETE FROM `machines`");
            $deletedCounts['ota_deployments']       = $this->pdo->exec("DELETE FROM `ota_deployments`");

            // Delete all non-admin customer/owner accounts
            $delUsers = $this->pdo->prepare("DELETE FROM `users` WHERE `id` != :admin_id");
            $delUsers->execute([':admin_id' => $adminUserId]);
            $deletedCounts['users']                 = $delUsers->rowCount();

            // Set v3.3.0 as the default Latest Build, while preserving v3.4.0 as available update build
            $this->pdo->exec("UPDATE `ota_releases` SET `is_latest` = 0");
            $upd330 = $this->pdo->exec("UPDATE `ota_releases` SET `is_latest` = 1 WHERE `version` LIKE '%3.3.0%'");
            if ($upd330 === 0) {
                // Seed v3.3.0 if missing
                $this->pdo->exec("INSERT INTO `ota_releases` (`version`, `filename`, `filesize`, `changelog`, `is_latest`, `created_at`) 
                                  VALUES ('v3.3.0', 'vendolink_esp32_v3.3.0.bin', 1221147, 'Baseline Production Build', 1, NOW())");
            }
            // Ensure v3.4.0 is preserved as update build
            $has340 = $this->pdo->query("SELECT id FROM `ota_releases` WHERE `version` LIKE '%3.4.0%' LIMIT 1")->fetchColumn();
            if (!$has340) {
                $this->pdo->exec("INSERT INTO `ota_releases` (`version`, `filename`, `filesize`, `changelog`, `is_latest`, `created_at`) 
                                  VALUES ('v3.4.0', 'vendolink_esp32_v3.4.0.bin', 1221296, 'Hardware Diagnostic Screen & Outage Persistence Update', 0, NOW())");
            } else {
                $this->pdo->exec("UPDATE `ota_releases` SET `is_latest` = 0 WHERE `version` LIKE '%3.4.0%'");
            }

            // Commit transaction
            $this->pdo->commit();

            // Reset AUTO_INCREMENT counters to 1 for clean demonstration deployments
            $tablesToReset = [
                'machines',
                'services',
                'product_keys',
                'license_keys',
                'service_transactions',
                'cash_collections',
                'configuration_history',
                'maintenance_history',
                'maintenance_items',
                'notifications',
                'activity_logs',
                'support_messages',
                'support_conversations',
                'ota_deployments',
            ];
            foreach ($tablesToReset as $tbl) {
                try {
                    $this->pdo->exec("ALTER TABLE `{$tbl}` AUTO_INCREMENT = 1");
                } catch (Throwable $t) {
                    // Ignore if table does not support AUTO_INCREMENT reset
                }
            }

            // Log first activity log in the fresh clean system
            $stmtLog = $this->pdo->prepare(
                "INSERT INTO `activity_logs` (user_id, action, description, created_at)
                 VALUES (:user_id, 'SYSTEM_RESET', 'System successfully reset to clean pre-sale state.', NOW())"
            );
            $stmtLog->execute([':user_id' => $adminUserId]);

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Database error during reset: " . $e->getMessage(), 0, $e);
        }

        // Verification counts after reset
        $afterSummary = $this->getSummary();

        return [
            'success'        => true,
            'backup_file'    => basename($backupFile),
            'backup_path'    => $backupFile,
            'deleted'        => $deletedCounts,
            'preserved'      => [
                'admin_id'       => (int)$adminAccount['id'],
                'admin_username' => $adminAccount['username'],
                'admin_email'    => $adminAccount['email'],
                'admin_role'     => $adminAccount['role'],
                'settings_count' => $afterSummary['settings'],
                'ota_count'      => $afterSummary['ota_releases'],
            ],
            'after_counts'   => $afterSummary,
            'timestamp'      => date('Y-m-d H:i:s'),
        ];
    }
}
