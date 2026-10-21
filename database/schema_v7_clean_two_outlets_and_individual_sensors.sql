-- ============================================================
-- VendoLink Database Migration: Schema v7
-- Restore Clean 2 Outlets & Independent Tank Sensors
-- ============================================================

-- 1. Deduplicate any redundant service channels per machine
DELETE duplicate_service
FROM `services` AS duplicate_service
INNER JOIN `services` AS kept_service
    ON duplicate_service.machine_id = kept_service.machine_id
   AND duplicate_service.service_key = kept_service.service_key
   AND duplicate_service.id > kept_service.id;

-- 2. Add Unique Key Constraint to prevent duplicate outlet records
ALTER TABLE `services`
    ADD UNIQUE KEY IF NOT EXISTS `uq_services_machine_key` (`machine_id`, `service_key`);

-- 3. Add independent telemetry timestamp tracking for water and soap tank sensors
ALTER TABLE `machines`
    ADD COLUMN IF NOT EXISTS `water_tank_updated_at` DATETIME NULL AFTER `soap_tank_low`,
    ADD COLUMN IF NOT EXISTS `soap_tank_updated_at` DATETIME NULL AFTER `water_tank_updated_at`;
