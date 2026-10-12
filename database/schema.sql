-- ============================================================
-- VendoLink — Database Schema v1.0
-- Database:  vendolink_db
-- Charset:   utf8mb4 / utf8mb4_unicode_ci
--
-- Import via phpMyAdmin or: mysql -u root -p < schema.sql
-- Then run setup.php to seed the administrator account.
--
-- ============================================================
-- PHASE 1 SCOPE
-- ============================================================
-- VendoLink Version 1 is designed for a single car-wash
-- business operating ONE machine controlled by ONE ESP32.
--
-- Current tables support this scope:
--   users          — application users (RBAC)
--   activity_logs  — audit trail
--   settings       — key-value app configuration
--
-- FUTURE EXPANSION tables (not created in v1):
--   businesses     — multi-business support
--   branches       — branch management
--   machines       — multi-machine registry
--   transactions   — coin/service transaction log
--   sensor_logs    — ESP32 sensor readings
--
-- These will be added in a future version of VendoLink.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `vendolink_db`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `vendolink_db`;

-- ============================================================
-- Table: users
-- Stores all application users (administrator, owner, technician)
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`                    INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `username`              VARCHAR(50)         NOT NULL,
    `email`                 VARCHAR(150)        NOT NULL,
    `password`              VARCHAR(255)        NOT NULL  COMMENT 'bcrypt hashed',
    `full_name`             VARCHAR(100)        NOT NULL,
    `role`                  ENUM(
                                'administrator',
                                'owner',
                                'staff',
                                'technician'
                            )                   NOT NULL DEFAULT 'staff',
    `status`                ENUM(
                                'active',
                                'inactive',
                                'banned'
                            )                   NOT NULL DEFAULT 'active',
    `email_verified`        TINYINT(1)          NOT NULL DEFAULT 0,
    `email_verify_token`    VARCHAR(100)        NULL     DEFAULT NULL,
    `reset_token`           VARCHAR(100)        NULL     DEFAULT NULL  COMMENT 'Password reset token (hashed)',
    `reset_token_expires`   DATETIME            NULL     DEFAULT NULL,
    `remember_token`        VARCHAR(100)        NULL     DEFAULT NULL  COMMENT 'Remember-me token (hashed)',
    `login_attempts`        TINYINT UNSIGNED    NOT NULL DEFAULT 0,
    `locked_until`          DATETIME            NULL     DEFAULT NULL,
    `last_login`            DATETIME            NULL     DEFAULT NULL,
    `profile_picture`       VARCHAR(255)        NULL     DEFAULT NULL,
    `phone`                 VARCHAR(20)         NULL     DEFAULT NULL,
    `created_at`            DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email`    (`email`),
    UNIQUE KEY `uq_username` (`username`),
    INDEX `idx_role`         (`role`),
    INDEX `idx_status`       (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Application users with RBAC support';

-- ============================================================
-- Table: activity_logs
-- Audit trail for all user actions
-- ============================================================
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NULL     DEFAULT NULL   COMMENT 'NULL if action is unauthenticated',
    `action`        VARCHAR(100)    NOT NULL               COMMENT 'e.g. LOGIN, LOGOUT, PASSWORD_RESET',
    `description`   TEXT            NULL     DEFAULT NULL,
    `ip_address`    VARCHAR(45)     NULL     DEFAULT NULL   COMMENT 'Supports IPv4 and IPv6',
    `user_agent`    VARCHAR(500)    NULL     DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user_id`   (`user_id`),
    INDEX `idx_created_at`(`created_at`),
    CONSTRAINT `fk_activity_logs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log for all application events';

-- ============================================================
-- Table: settings
-- Key-value store for application configuration
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100)    NOT NULL,
    `setting_value` TEXT            NULL     DEFAULT NULL,
    `description`   VARCHAR(255)    NULL     DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Application-wide key-value settings';

-- ============================================================
-- Seed: Default Application Settings
-- ============================================================
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('app_name',           'VendoLink',             'Application display name'),
('app_version',        '1.0',                   'Current application version'),
('app_timezone',       'Asia/Manila',            'Default timezone'),
('session_lifetime',   '3600',                   'Session lifetime in seconds'),
('remember_me_days',   '30',                     'Remember-me cookie duration (days)'),
('mail_driver',        'smtp',                   'Mail driver: smtp or mail'),
('mail_host',          'smtp.mailtrap.io',       'SMTP hostname'),
('mail_port',          '2525',                   'SMTP port'),
('mail_username',      '',                       'SMTP username'),
('mail_password',      '',                       'SMTP password'),
('mail_from_address',  'noreply@vendolink.local','Sender email address'),
('mail_from_name',     'VendoLink System',       'Sender display name'),
('max_login_attempts', '5',                      'Max failed logins before lockout'),
('lockout_duration',   '900',                    'Lockout duration in seconds (15 min)'),
('maintenance_mode',   '0',                      '1 = maintenance mode ON');

-- ============================================================
-- NOTE: The administrator account is seeded by setup.php
--       to ensure the password is properly bcrypt-hashed at
--       runtime. Run setup.php after importing this schema.
-- ============================================================
