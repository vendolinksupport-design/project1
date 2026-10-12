-- VendoLink Phase 2, Step 2: runtime and maintenance.
-- Import once after database/schema_v2_step1.sql. Existing data is preserved.
USE `vendolink_db`;

ALTER TABLE `machines`
    ADD COLUMN IF NOT EXISTS `runtime_seconds` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`;

ALTER TABLE `services`
    ADD COLUMN IF NOT EXISTS `runtime_seconds` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `service_seconds`,
    ADD COLUMN IF NOT EXISTS `current_status` ENUM('idle', 'running', 'fault', 'offline') NOT NULL DEFAULT 'idle' AFTER `runtime_seconds`,
    ADD COLUMN IF NOT EXISTS `remaining_runtime_seconds` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `current_status`;

CREATE TABLE IF NOT EXISTS `service_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `machine_id` INT UNSIGNED NOT NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(10,2) UNSIGNED NOT NULL,
    `coin_value_at_purchase` DECIMAL(10,2) UNSIGNED NOT NULL,
    `service_seconds_at_purchase` INT UNSIGNED NOT NULL,
    `runtime_generated_seconds` INT UNSIGNED NOT NULL,
    `actual_runtime_seconds` INT UNSIGNED NULL DEFAULT 0,
    `status` ENUM('pending', 'active', 'completed', 'failed', 'cancelled', 'interrupted', 'incomplete') NOT NULL DEFAULT 'completed',
    `status_reason` VARCHAR(255) NULL DEFAULT NULL,
    `completed_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_transactions_service_completed` (`service_id`, `status`, `completed_at`),
    INDEX `idx_transactions_machine_completed` (`machine_id`, `status`, `completed_at`),
    CONSTRAINT `fk_service_transactions_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_service_transactions_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maintenance_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `machine_id` INT UNSIGNED NOT NULL,
    `service_id` INT UNSIGNED NULL DEFAULT NULL,
    `name` VARCHAR(120) NOT NULL,
    `reminder_type` ENUM('calendar', 'runtime', 'both') NOT NULL DEFAULT 'calendar',
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `last_completed_at` DATETIME NOT NULL,
    `calendar_interval_value` SMALLINT UNSIGNED NULL DEFAULT NULL,
    `calendar_interval_unit` ENUM('days', 'weeks', 'months') NULL DEFAULT NULL,
    `runtime_interval_seconds` BIGINT UNSIGNED NULL DEFAULT NULL,
    `runtime_baseline_seconds` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_maintenance_machine_enabled` (`machine_id`, `is_enabled`),
    CONSTRAINT `fk_maintenance_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_maintenance_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maintenance_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `maintenance_item_id` INT UNSIGNED NOT NULL,
    `completed_by_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `completed_at` DATETIME NOT NULL,
    `runtime_at_completion_seconds` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_maintenance_history_item_date` (`maintenance_item_id`, `completed_at`),
    CONSTRAINT `fk_maintenance_history_item` FOREIGN KEY (`maintenance_item_id`) REFERENCES `maintenance_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_maintenance_history_user` FOREIGN KEY (`completed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `machine_id` INT UNSIGNED NULL,
    `category` ENUM('critical', 'hardware', 'financial', 'maintenance', 'system') NOT NULL DEFAULT 'system',
    `type` ENUM('danger', 'warning', 'success', 'info') NOT NULL DEFAULT 'info',
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `icon` VARCHAR(100) NOT NULL DEFAULT 'bi-bell',
    `badge` VARCHAR(50) NOT NULL DEFAULT 'Alert',
    `is_critical` TINYINT(1) NOT NULL DEFAULT 0,
    `action_link` VARCHAR(255) NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_notif_user_unread` (`user_id`, `is_read`, `created_at`),
    INDEX `idx_notif_created` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

