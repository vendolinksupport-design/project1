-- ============================================================
-- VendoLink — Database Schema v3 (Phase 1 Admin)
-- ============================================================

ALTER TABLE `users`
MODIFY COLUMN `role` ENUM(
    'administrator',
    'owner',
    'staff',
    'technician',
    'vendolink_support'
) NOT NULL DEFAULT 'staff';
