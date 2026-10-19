-- ============================================================
-- VendoLink — Database Schema v5 (Additive Device-Pairing Migration)
-- ============================================================
-- Purpose:
--   Adds schema support for universal ESP32 firmware automatic device
--   registration, hardware lifecycle tracking, and direct relational
--   Product Key pairing without relying on fragile string matching.
--
-- Schema Baseline & Verification Context:
--   - Inferred from repository migration history:
--       `database/schema_v2_step1.sql` (machines table baseline)
--       `scratch/migrate_product_keys.php` and `database/schema_v4_multitenant_hardware.sql`
--   - Verified against local development database:
--       `machines.id`: INT(10) UNSIGNED NOT NULL AUTO_INCREMENT (InnoDB)
--       `product_keys.id`: INT(10) UNSIGNED NOT NULL AUTO_INCREMENT (InnoDB)
--       Charset/Collation: utf8mb4 / utf8mb4_unicode_ci across both tables
--       Engine: InnoDB across both tables
--   - Target database notice: Target environments must undergo read-only verification
--     using the provided diagnostic queries before applying this script.
--
-- Device Registration Lifecycle:
--   - 'legacy':     Default state for all pre-existing machines upon migration.
--                   Ensures existing production machines are never misclassified as
--                   newly enrolled or unissued devices.
--   - 'registered': Device has successfully proved its identity via the secure
--                   enrollment protocol and is automatically registered in the database.
--                   (Future enrollment endpoint must explicitly set this status upon
--                   authenticated device handshake; no manual admin approval required).
--   - 'key_issued': Administrator has generated a unique Product Key tied directly to
--                   this specific device (future implementation requirement).
--   - 'claimed':    Customer has registered an account and redeemed the Product Key,
--                   binding the machine to their customer account (future requirement).
--   - 'disabled':   Device has been administratively revoked or decommissioned.
--
-- Safety & Data Integrity Guarantees:
--   1. Additive-only: Zero tables dropped, recreated, or truncated.
--   2. Non-breaking: Existing columns (hardware_serial, machine_code,
--      machine_key, api_token) remain untouched and fully operational.
--   3. Permissive NULLs: Legacy machines retain device_mac = NULL. Under MySQL/InnoDB,
--      multiple NULL values in a UNIQUE index do not conflict. Legacy Product Keys
--      retain machine_id = NULL without violating foreign key constraints.
--   4. Referentially sound: product_keys.machine_id is INT UNSIGNED NULL, exactly
--      matching machines.id. ON DELETE SET NULL preserves financial/voucher audit
--      history if a machine hardware record is ever purged.
--   5. Zero speculative updates: Does not guess relationships from hardware_serial
--      strings and does not alter existing owner assignments.
--
-- Migration Execution, Idempotency & Partial Failure Notice:
--   In MySQL and MariaDB, DDL statements (ALTER TABLE) trigger an implicit commit
--   and cannot be rolled back within a transaction.
--   - Column additions use IF NOT EXISTS (supported in MariaDB and MySQL 8.0.29+).
--   - Adding indexes/constraints is NOT idempotent in standard MySQL syntax; if this
--     script fails midway, subsequent runs will halt on duplicate key/constraint names.
--   - DO NOT blindly re-run the entire script after a partial failure. Instead, inspect
--     column definitions, index uniqueness, and foreign key rules using the provided
--     recovery diagnostic queries, and resume only the specific missing DDL operations.
-- ============================================================

USE `vendolink_db`;

-- ------------------------------------------------------------
-- 1. Table: machines
-- Add factory hardware MAC address and device registration lifecycle status
-- ------------------------------------------------------------
ALTER TABLE `machines`
    ADD COLUMN IF NOT EXISTS `device_mac` VARCHAR(32) NULL DEFAULT NULL
        COMMENT 'ESP32 factory hardware MAC address (12-char hex e.g. 3485188B67D0 or colon-delimited). NULL for legacy units.'
        AFTER `machine_key`,
    ADD COLUMN IF NOT EXISTS `registration_status` ENUM('legacy', 'registered', 'key_issued', 'claimed', 'disabled') NOT NULL DEFAULT 'legacy'
        COMMENT 'Hardware lifecycle: legacy (pre-v5 machine), registered (auto-enrolled ESP32), key_issued (Product Key generated), claimed (customer redeemed), disabled (revoked).'
        AFTER `status`,
    ADD UNIQUE KEY `uq_machines_device_mac` (`device_mac`),
    ADD INDEX `idx_machines_registration_status` (`registration_status`);

-- ------------------------------------------------------------
-- 2. Table: product_keys
-- Add direct relational machine_id reference and foreign key constraint
-- ------------------------------------------------------------
ALTER TABLE `product_keys`
    ADD COLUMN IF NOT EXISTS `machine_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Direct relational reference to machines.id. Replaces legacy hardware_serial string matching for new devices. NULL for unlinked/legacy keys.'
        AFTER `hardware_serial`,
    ADD INDEX `idx_product_keys_machine_id` (`machine_id`),
    ADD CONSTRAINT `fk_product_keys_machine`
        FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE;
