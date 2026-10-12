-- VendoLink Phase 2, Step 1: one predefined machine and its services.
-- Import this file once after database/schema.sql.
USE `vendolink_db`;

CREATE TABLE IF NOT EXISTS `machines` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `machine_code` VARCHAR(50) NOT NULL,
    `status` ENUM('online', 'offline', 'unknown') NOT NULL DEFAULT 'unknown',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `services` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `machine_id` INT UNSIGNED NOT NULL,
    `service_key` VARCHAR(30) NOT NULL,
    `name` VARCHAR(80) NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `coin_value` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 5.00,
    `service_seconds` INT UNSIGNED NOT NULL DEFAULT 120,
    `output_channel` VARCHAR(30) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_services_machine`
        FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibility cleanup: an earlier incomplete Phase 2 attempt may have
-- created these tables using different column names. Keep its first Water and
-- Soap records, make the structure complete, and remove only duplicate types.
ALTER TABLE `machines`
    ADD COLUMN IF NOT EXISTS `machine_code` VARCHAR(50) NULL AFTER `name`;
ALTER TABLE `machines`
    MODIFY COLUMN `status` ENUM('online', 'offline', 'unknown', 'pending') NOT NULL DEFAULT 'unknown';

ALTER TABLE `services`
    ADD COLUMN IF NOT EXISTS `service_key` VARCHAR(30) NULL AFTER `machine_id`,
    ADD COLUMN IF NOT EXISTS `is_enabled` TINYINT(1) NULL AFTER `name`,
    ADD COLUMN IF NOT EXISTS `service_seconds` INT UNSIGNED NULL AFTER `coin_value`,
    ADD COLUMN IF NOT EXISTS `output_channel` VARCHAR(30) NULL AFTER `service_seconds`;

UPDATE `machines`
SET `machine_code` = 'MACHINE-01'
WHERE `machine_code` IS NULL AND `id` = (SELECT min_machine_id FROM (SELECT MIN(id) AS min_machine_id FROM `machines`) AS machine_seed);

UPDATE `services`
SET `service_key` = `type`
WHERE `service_key` IS NULL;
UPDATE `services`
SET `is_enabled` = `enabled`
WHERE `is_enabled` IS NULL;
UPDATE `services`
SET `service_seconds` = `seconds_per_coin`
WHERE `service_seconds` IS NULL;
UPDATE `services`
SET `output_channel` = CASE `service_key` WHEN 'water' THEN 'SSR-1' WHEN 'soap' THEN 'SSR-2' END
WHERE `output_channel` IS NULL;

DELETE duplicate_service
FROM `services` AS duplicate_service
INNER JOIN `services` AS kept_service
    ON duplicate_service.machine_id = kept_service.machine_id
   AND duplicate_service.service_key = kept_service.service_key
   AND duplicate_service.id > kept_service.id;

ALTER TABLE `machines`
    MODIFY COLUMN `machine_code` VARCHAR(50) NOT NULL,
    ADD UNIQUE KEY `uq_machines_code` (`machine_code`);
ALTER TABLE `services`
    MODIFY COLUMN `service_key` VARCHAR(30) NOT NULL,
    MODIFY COLUMN `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    MODIFY COLUMN `service_seconds` INT UNSIGNED NOT NULL DEFAULT 120,
    ADD UNIQUE KEY `uq_services_machine_key` (`machine_id`, `service_key`);

INSERT INTO `machines` (`name`, `machine_code`, `status`)
SELECT 'VendoLink Machine 01', 'MACHINE-01', 'unknown'
WHERE NOT EXISTS (SELECT 1 FROM `machines` WHERE `machine_code` = 'MACHINE-01');

INSERT INTO `services` (`machine_id`, `service_key`, `name`, `is_enabled`, `coin_value`, `service_seconds`, `output_channel`)
SELECT m.id, s.service_key, s.name, 1, 5.00, 120, s.output_channel
FROM `machines` m
CROSS JOIN (
    SELECT 'water' AS service_key, 'Water Service' AS name, 'SSR-1' AS output_channel
    UNION ALL SELECT 'soap', 'Soap Service', 'SSR-2'
) s
WHERE m.machine_code = 'MACHINE-01'
  AND NOT EXISTS (
      SELECT 1 FROM `services` existing_service
      WHERE existing_service.machine_id = m.id AND existing_service.service_key = s.service_key
  );
