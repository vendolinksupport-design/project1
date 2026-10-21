-- ============================================================
-- VendoLink - Database Migration v6: Cash Collection Validation & Reconciliation
-- ============================================================

ALTER TABLE `cash_collections`
ADD COLUMN IF NOT EXISTS `expected_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `machine_id`,
ADD COLUMN IF NOT EXISTS `reconciliation_status` ENUM('matched', 'shortage', 'excess', 'override') NOT NULL DEFAULT 'matched' AFTER `variance_amount`,
ADD COLUMN IF NOT EXISTS `is_override` TINYINT(1) NOT NULL DEFAULT 0 AFTER `reconciliation_status`,
ADD COLUMN IF NOT EXISTS `override_reason` TEXT NULL AFTER `is_override`,
ADD COLUMN IF NOT EXISTS `override_user_id` INT UNSIGNED NULL AFTER `override_reason`,
ADD COLUMN IF NOT EXISTS `uncollected_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `override_user_id`;
