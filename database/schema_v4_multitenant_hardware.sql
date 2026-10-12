-- ============================================================
-- VendoLink — Database Schema v4 (Multi-Tenant & Hardware Extension)
-- ============================================================
-- Adds multi-tenant ownership, device key, hardware tokens,
-- telemetry, tank floaters, LCD customization, and client transaction tracking.
-- ============================================================

USE `vendolink_db`;

-- 1. Table: machines
ALTER TABLE `machines`
    ADD COLUMN IF NOT EXISTS `owner_id` INT UNSIGNED NULL DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `machine_key` VARCHAR(50) NOT NULL DEFAULT 'machine_01' AFTER `machine_code`,
    ADD COLUMN IF NOT EXISTS `api_token` VARCHAR(255) NULL DEFAULT NULL AFTER `machine_key`,
    ADD COLUMN IF NOT EXISTS `last_seen` DATETIME NULL DEFAULT NULL AFTER `runtime_seconds`,
    ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(45) NULL DEFAULT NULL AFTER `last_seen`,
    ADD COLUMN IF NOT EXISTS `firmware_version` VARCHAR(20) DEFAULT '1.5.0' AFTER `ip_address`,
    ADD COLUMN IF NOT EXISTS `location` VARCHAR(100) DEFAULT 'Main Establishment' AFTER `firmware_version`,
    ADD COLUMN IF NOT EXISTS `water_tank_low` TINYINT(1) NOT NULL DEFAULT 0 AFTER `location`,
    ADD COLUMN IF NOT EXISTS `soap_tank_low` TINYINT(1) NOT NULL DEFAULT 0 AFTER `water_tank_low`,
    ADD COLUMN IF NOT EXISTS `water_current` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `soap_tank_low`,
    ADD COLUMN IF NOT EXISTS `water_watts` DECIMAL(8,1) NOT NULL DEFAULT 0.0 AFTER `water_current`,
    ADD COLUMN IF NOT EXISTS `soap_current` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `water_watts`,
    ADD COLUMN IF NOT EXISTS `soap_watts` DECIMAL(8,1) NOT NULL DEFAULT 0.0 AFTER `soap_current`,
    ADD COLUMN IF NOT EXISTS `lcd_line1` VARCHAR(50) NOT NULL DEFAULT '== VendoLink v2.2 ==' AFTER `soap_watts`,
    ADD COLUMN IF NOT EXISTS `lcd_line2` VARCHAR(50) NOT NULL DEFAULT 'WELCOME CUSTOMER!' AFTER `lcd_line1`,
    ADD COLUMN IF NOT EXISTS `lcd_line3` VARCHAR(50) NOT NULL DEFAULT 'INSERT COIN FIRST!' AFTER `lcd_line2`,
    ADD COLUMN IF NOT EXISTS `lcd_line4` VARCHAR(50) NOT NULL DEFAULT 'WATER:P5 | SOAP:P5' AFTER `lcd_line3`;

-- 2. Table: services
ALTER TABLE `services`
    ADD COLUMN IF NOT EXISTS `type` VARCHAR(30) NULL DEFAULT NULL AFTER `service_key`,
    ADD COLUMN IF NOT EXISTS `icon` VARCHAR(50) NULL DEFAULT NULL AFTER `name`,
    ADD COLUMN IF NOT EXISTS `enabled` TINYINT(1) NULL DEFAULT 1 AFTER `is_enabled`,
    ADD COLUMN IF NOT EXISTS `seconds_per_coin` INT UNSIGNED NULL DEFAULT 120 AFTER `service_seconds`;

-- 3. Table: service_transactions
ALTER TABLE `service_transactions`
    ADD COLUMN IF NOT EXISTS `client_transaction_id` VARCHAR(64) NULL DEFAULT NULL AFTER `machine_id`,
    ADD COLUMN IF NOT EXISTS `avg_current` DECIMAL(6,2) NULL DEFAULT NULL AFTER `status_reason`,
    ADD COLUMN IF NOT EXISTS `peak_current` DECIMAL(6,2) NULL DEFAULT NULL AFTER `avg_current`,
    ADD COLUMN IF NOT EXISTS `avg_watts` DECIMAL(8,1) NULL DEFAULT NULL AFTER `peak_current`,
    ADD COLUMN IF NOT EXISTS `peak_watts` DECIMAL(8,1) NULL DEFAULT NULL AFTER `avg_watts`;

-- 4. Table: maintenance_items
ALTER TABLE `maintenance_items`
    ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_enabled`;

-- 5. AUTO_INCREMENT guarantees
ALTER TABLE `configuration_history` MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `license_keys`          MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `ota_releases`          MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `product_keys`          MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `support_conversations` MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `support_messages`      MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
