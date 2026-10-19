-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: vendolink_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL if action is unauthenticated',
  `action` varchar(100) NOT NULL COMMENT 'e.g. LOGIN, LOGOUT, PASSWORD_RESET',
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'Supports IPv4 and IPv6',
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_activity_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=573 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for all application events';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (569,1,'SYSTEM_RESET','System successfully reset to clean pre-sale state.',NULL,NULL,'2026-10-17 22:00:02'),(570,NULL,'DEVICE_ENROLLED','Universal ESP32 enrolled: MAC 20:9B:A9:87:EA:28 as MCH-EA28 (#262).','192.168.100.4','ESP32HTTPClient','2026-10-17 22:00:14'),(571,1,'ADMIN_ACTION','Removed unassigned machine MCH-EA28 (#262)','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36','2026-10-17 22:01:38'),(572,NULL,'DEVICE_ENROLLED','Universal ESP32 enrolled: MAC 20:9B:A9:87:EA:28 as MCH-EA28 (#263).','192.168.100.4','ESP32HTTPClient','2026-10-17 22:01:49');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cash_collections`
--

DROP TABLE IF EXISTS `cash_collections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cash_collections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `machine_id` int(10) unsigned NOT NULL,
  `collected_amount` decimal(10,2) NOT NULL,
  `system_sales_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `variance_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `collected_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_cash_user` (`user_id`),
  KEY `fk_cash_machine` (`machine_id`)
) ENGINE=InnoDB AUTO_INCREMENT=124 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_collections`
--

LOCK TABLES `cash_collections` WRITE;
/*!40000 ALTER TABLE `cash_collections` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_collections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `configuration_history`
--

DROP TABLE IF EXISTS `configuration_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `configuration_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` int(10) unsigned NOT NULL,
  `previous_coin_value` decimal(10,2) DEFAULT NULL,
  `new_coin_value` decimal(10,2) DEFAULT NULL,
  `previous_service_seconds` int(10) unsigned DEFAULT NULL,
  `new_service_seconds` int(10) unsigned DEFAULT NULL,
  `previous_is_enabled` tinyint(1) DEFAULT NULL,
  `new_is_enabled` tinyint(1) DEFAULT NULL,
  `changed_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_config_hist_service` (`service_id`)
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuration_history`
--

LOCK TABLES `configuration_history` WRITE;
/*!40000 ALTER TABLE `configuration_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `configuration_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `license_keys`
--

DROP TABLE IF EXISTS `license_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `license_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `license_key` varchar(100) NOT NULL,
  `assigned_owner` varchar(150) DEFAULT NULL,
  `status` enum('active','unused','revoked','expired') NOT NULL DEFAULT 'unused',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `license_key` (`license_key`)
) ENGINE=InnoDB AUTO_INCREMENT=117 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `license_keys`
--

LOCK TABLES `license_keys` WRITE;
/*!40000 ALTER TABLE `license_keys` DISABLE KEYS */;
/*!40000 ALTER TABLE `license_keys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `machine_sequences`
--

DROP TABLE IF EXISTS `machine_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `machine_sequences` (
  `sequence_name` varchar(50) NOT NULL,
  `current_val` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`sequence_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `machine_sequences`
--

LOCK TABLES `machine_sequences` WRITE;
/*!40000 ALTER TABLE `machine_sequences` DISABLE KEYS */;
INSERT INTO `machine_sequences` VALUES ('machine_code',17,'2026-10-17 14:20:22');
/*!40000 ALTER TABLE `machine_sequences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `machines`
--

DROP TABLE IF EXISTS `machines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `machines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `owner_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `machine_code` varchar(50) NOT NULL,
  `machine_key` varchar(50) NOT NULL DEFAULT 'machine_01',
  `device_mac` varchar(32) DEFAULT NULL COMMENT 'ESP32 factory hardware MAC address (12-char hex e.g. 3485188B67D0 or colon-delimited). NULL for legacy units.',
  `api_token` varchar(255) DEFAULT NULL,
  `status` enum('online','offline','unknown','pending') NOT NULL DEFAULT 'unknown',
  `registration_status` enum('legacy','registered','key_issued','claimed','disabled') NOT NULL DEFAULT 'legacy' COMMENT 'Hardware lifecycle: legacy (pre-v5 machine), registered (auto-enrolled ESP32), key_issued (Product Key generated), claimed (customer redeemed), disabled (revoked).',
  `runtime_seconds` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_seen` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `firmware_version` varchar(20) DEFAULT '1.5.0',
  `location` varchar(100) DEFAULT 'Main Establishment',
  `water_tank_low` tinyint(1) NOT NULL DEFAULT 0,
  `soap_tank_low` tinyint(1) NOT NULL DEFAULT 0,
  `water_current` decimal(6,2) NOT NULL DEFAULT 0.00,
  `water_watts` decimal(8,1) NOT NULL DEFAULT 0.0,
  `soap_current` decimal(6,2) NOT NULL DEFAULT 0.00,
  `soap_watts` decimal(8,1) NOT NULL DEFAULT 0.0,
  `lcd_line1` varchar(50) NOT NULL DEFAULT '== VendoLink v3.0 ==',
  `lcd_line2` varchar(50) NOT NULL DEFAULT 'WELCOME CUSTOMER!',
  `lcd_line3` varchar(50) NOT NULL DEFAULT 'INSERT COIN FIRST!',
  `lcd_line4` varchar(50) NOT NULL DEFAULT 'WATER:P5 | SOAP:P5',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_machines_machine_key` (`machine_key`),
  UNIQUE KEY `uq_machines_machine_code` (`machine_code`),
  UNIQUE KEY `uq_machines_device_mac` (`device_mac`),
  KEY `idx_machines_registration_status` (`registration_status`)
) ENGINE=InnoDB AUTO_INCREMENT=264 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `machines`
--

LOCK TABLES `machines` WRITE;
/*!40000 ALTER TABLE `machines` DISABLE KEYS */;
INSERT INTO `machines` VALUES (263,NULL,'Vendo Machine EA28','MCH-EA28','mch_87ea28_d269','20:9B:A9:87:EA:28','vl_dev_sec_1bf8d1661e561f86cb2b87f1a8c60f06','online','registered',0,'2026-10-17 22:03:24','192.168.100.4','3.0.0','Main Establishment',0,0,0.00,0.0,0.00,0.0,'== VendoLink v3.0 ==','WELCOME CUSTOMER!','INSERT COIN FIRST!','WATER:P5 | SOAP:P5','2026-10-17 22:01:49','2026-10-17 22:03:24');
/*!40000 ALTER TABLE `machines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `maintenance_history`
--

DROP TABLE IF EXISTS `maintenance_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maintenance_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maintenance_item_id` int(10) unsigned NOT NULL,
  `completed_by_user_id` int(10) unsigned DEFAULT NULL,
  `completed_at` datetime NOT NULL,
  `runtime_at_completion_seconds` bigint(20) unsigned NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_maintenance_history_item_date` (`maintenance_item_id`,`completed_at`),
  KEY `fk_maintenance_history_user` (`completed_by_user_id`),
  CONSTRAINT `fk_maintenance_history_item` FOREIGN KEY (`maintenance_item_id`) REFERENCES `maintenance_items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_maintenance_history_user` FOREIGN KEY (`completed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance_history`
--

LOCK TABLES `maintenance_history` WRITE;
/*!40000 ALTER TABLE `maintenance_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `maintenance_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `maintenance_items`
--

DROP TABLE IF EXISTS `maintenance_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maintenance_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `machine_id` int(10) unsigned NOT NULL,
  `service_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `reminder_type` enum('calendar','runtime','both') NOT NULL DEFAULT 'calendar',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `last_completed_at` datetime NOT NULL,
  `calendar_interval_value` smallint(5) unsigned DEFAULT NULL,
  `calendar_interval_unit` enum('days','weeks','months') DEFAULT NULL,
  `runtime_interval_seconds` bigint(20) unsigned DEFAULT NULL,
  `runtime_baseline_seconds` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_maintenance_machine_enabled` (`machine_id`,`is_enabled`),
  KEY `fk_maintenance_service` (`service_id`),
  CONSTRAINT `fk_maintenance_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_maintenance_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=554 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance_items`
--

LOCK TABLES `maintenance_items` WRITE;
/*!40000 ALTER TABLE `maintenance_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `maintenance_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `machine_id` int(10) unsigned DEFAULT NULL,
  `category` enum('critical','hardware','financial','maintenance','system') NOT NULL DEFAULT 'system',
  `type` enum('danger','warning','success','info') NOT NULL DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `icon` varchar(100) NOT NULL DEFAULT 'bi-bell',
  `badge` varchar(50) NOT NULL DEFAULT 'Alert',
  `is_critical` tinyint(1) NOT NULL DEFAULT 0,
  `action_link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_unread` (`user_id`,`is_read`,`created_at`),
  KEY `idx_notif_created` (`created_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ota_deployments`
--

DROP TABLE IF EXISTS `ota_deployments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ota_deployments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `release_id` int(10) unsigned NOT NULL,
  `target_type` enum('all','customer','machine') NOT NULL DEFAULT 'all',
  `target_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ota_target` (`target_type`,`target_id`),
  KEY `idx_ota_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ota_deployments`
--

LOCK TABLES `ota_deployments` WRITE;
/*!40000 ALTER TABLE `ota_deployments` DISABLE KEYS */;
INSERT INTO `ota_deployments` VALUES (14,10,'machine',119,'cancelled','2026-10-16 21:43:50','2026-10-17 21:00:23'),(15,10,'all',NULL,'cancelled','2026-10-16 21:44:23','2026-10-16 21:44:39'),(22,15,'machine',261,'cancelled','2026-10-17 20:58:29','2026-10-17 21:00:18'),(23,15,'machine',261,'cancelled','2026-10-17 21:27:54','2026-10-17 21:28:17'),(24,15,'all',NULL,'cancelled','2026-10-17 21:34:02','2026-10-17 21:34:54');
/*!40000 ALTER TABLE `ota_deployments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ota_releases`
--

DROP TABLE IF EXISTS `ota_releases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ota_releases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(50) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `filesize` bigint(20) unsigned NOT NULL DEFAULT 0,
  `changelog` text DEFAULT NULL,
  `is_latest` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `version` (`version`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ota_releases`
--

LOCK TABLES `ota_releases` WRITE;
/*!40000 ALTER TABLE `ota_releases` DISABLE KEYS */;
INSERT INTO `ota_releases` VALUES (1,'v1.5.0','vendolink_esp32_v1.5.0.bin',1048576,'Standard factory firmware release with Dual ACS712 monitoring and 20x4 LCD support.',0,'2026-08-22 13:40:12'),(10,'v3.1.0','vendolink_esp32_v3.1.0.bin',1211296,'First OTA update test screen update',0,'2026-10-16 21:43:40'),(15,'3.1','vendolink_esp32_3.1.bin',1212144,'',1,'2026-10-17 20:58:14');
/*!40000 ALTER TABLE `ota_releases` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_keys`
--

DROP TABLE IF EXISTS `product_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_key` varchar(100) NOT NULL,
  `hardware_serial` varchar(100) NOT NULL,
  `machine_id` int(10) unsigned DEFAULT NULL COMMENT 'Direct relational reference to machines.id. Replaces legacy hardware_serial string matching for new devices. NULL for unlinked/legacy keys.',
  `status` enum('UNUSED','USED','DISABLED') NOT NULL DEFAULT 'UNUSED',
  `owner_id` int(10) unsigned DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_key` (`product_key`),
  KEY `idx_product_key_status` (`product_key`,`status`),
  KEY `idx_hw_serial` (`hardware_serial`),
  KEY `idx_owner_id` (`owner_id`),
  KEY `idx_product_keys_machine_id` (`machine_id`),
  CONSTRAINT `fk_product_keys_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=252 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_keys`
--

LOCK TABLES `product_keys` WRITE;
/*!40000 ALTER TABLE `product_keys` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_keys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_transactions`
--

DROP TABLE IF EXISTS `service_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `machine_id` int(10) unsigned NOT NULL,
  `client_transaction_id` varchar(64) DEFAULT NULL,
  `service_id` int(10) unsigned NOT NULL,
  `amount` decimal(10,2) unsigned NOT NULL,
  `coin_value_at_purchase` decimal(10,2) unsigned NOT NULL,
  `service_seconds_at_purchase` int(10) unsigned NOT NULL,
  `runtime_generated_seconds` int(10) unsigned NOT NULL,
  `actual_runtime_seconds` int(10) unsigned DEFAULT 0,
  `status` enum('pending','active','completed','failed','cancelled','interrupted','incomplete') NOT NULL DEFAULT 'completed',
  `status_reason` varchar(255) DEFAULT NULL,
  `avg_current` decimal(6,2) DEFAULT NULL,
  `peak_current` decimal(6,2) DEFAULT NULL,
  `avg_watts` decimal(8,1) DEFAULT NULL,
  `peak_watts` decimal(8,1) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_transactions_service_completed` (`service_id`,`status`,`completed_at`),
  KEY `idx_transactions_machine_completed` (`machine_id`,`status`,`completed_at`),
  CONSTRAINT `fk_service_transactions_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_service_transactions_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15039 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_transactions`
--

LOCK TABLES `service_transactions` WRITE;
/*!40000 ALTER TABLE `service_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `services` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `machine_id` int(10) unsigned NOT NULL,
  `service_key` varchar(30) NOT NULL,
  `type` varchar(30) DEFAULT NULL,
  `name` varchar(80) NOT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `enabled` tinyint(1) DEFAULT 1,
  `coin_value` decimal(10,2) unsigned NOT NULL DEFAULT 5.00,
  `service_seconds` int(10) unsigned NOT NULL DEFAULT 120,
  `seconds_per_coin` int(10) unsigned DEFAULT 120,
  `runtime_seconds` bigint(20) unsigned NOT NULL DEFAULT 0,
  `current_status` enum('idle','running','fault','offline') NOT NULL DEFAULT 'idle',
  `remaining_runtime_seconds` int(10) unsigned NOT NULL DEFAULT 0,
  `output_channel` varchar(30) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_services_machine` (`machine_id`),
  CONSTRAINT `fk_services_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=207 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `services`
--

LOCK TABLES `services` WRITE;
/*!40000 ALTER TABLE `services` DISABLE KEYS */;
INSERT INTO `services` VALUES (205,263,'water','water','Water Service',NULL,1,1,5.00,120,120,0,'idle',0,NULL,'2026-10-17 22:01:49','2026-10-17 22:01:49'),(206,263,'soap','soap','Soap Service',NULL,1,1,5.00,120,120,0,'idle',0,NULL,'2026-10-17 22:01:49','2026-10-17 22:01:49');
/*!40000 ALTER TABLE `services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Application-wide key-value settings';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'app_name','VendoLink','Application display name','2026-10-10 10:19:51','2026-10-10 10:19:51'),(2,'app_version','1.0','Current application version','2026-10-10 10:19:51','2026-10-10 10:19:51'),(3,'app_timezone','Asia/Manila','Default timezone','2026-10-10 10:19:51','2026-10-10 10:19:51'),(4,'session_lifetime','3600','Session lifetime in seconds','2026-10-10 10:19:51','2026-10-10 10:19:51'),(5,'remember_me_days','30','Remember-me cookie duration (days)','2026-10-10 10:19:51','2026-10-10 10:19:51'),(6,'mail_driver','smtp','Mail driver: smtp or mail','2026-10-10 10:19:51','2026-10-10 10:19:51'),(7,'mail_host','smtp.mailtrap.io','SMTP hostname','2026-10-10 10:19:51','2026-10-10 10:19:51'),(8,'mail_port','2525','SMTP port','2026-10-10 10:19:51','2026-10-10 10:19:51'),(9,'mail_username','','SMTP username','2026-10-10 10:19:51','2026-10-10 10:19:51'),(10,'mail_password','','SMTP password','2026-10-10 10:19:51','2026-10-10 10:19:51'),(11,'mail_from_address','noreply@vendolink.local','Sender email address','2026-10-10 10:19:51','2026-10-10 10:19:51'),(12,'mail_from_name','VendoLink System','Sender display name','2026-10-10 10:19:51','2026-10-10 10:19:51'),(13,'max_login_attempts','5','Max failed logins before lockout','2026-10-10 10:19:51','2026-10-10 10:19:51'),(14,'lockout_duration','900','Lockout duration in seconds (15 min)','2026-10-10 10:19:51','2026-10-10 10:19:51'),(15,'maintenance_mode','0','1 = maintenance mode ON','2026-10-10 10:19:51','2026-10-10 10:19:51'),(16,'inapp_notif_enabled','1','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46'),(17,'email_notif_enabled','1','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46'),(18,'notif_maint_reminders','1','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46'),(19,'notif_machine_offline','1','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46'),(20,'notif_machine_online','1','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46'),(21,'notif_daily_sales','1','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46'),(22,'notif_weekly_sales','1','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46'),(23,'notif_security_notices','1','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46'),(24,'business_name','Metro VendoLink Express','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46'),(25,'business_email','owner.express@vendolink.local','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46'),(26,'business_phone','+63 999 888 7777','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46'),(27,'business_address','456 Innovation Blvd, Quezon City, Philippines','System preference','2026-10-16 22:48:44','2026-10-17 15:48:46');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_conversations`
--

DROP TABLE IF EXISTS `support_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_conversations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `subject` varchar(200) NOT NULL DEFAULT 'General Support',
  `status` enum('open','waiting_support','waiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
  `last_message_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_support_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_conversations`
--

LOCK TABLES `support_conversations` WRITE;
/*!40000 ALTER TABLE `support_conversations` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_conversations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `support_messages`
--

DROP TABLE IF EXISTS `support_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `sender_user_id` int(10) unsigned NOT NULL,
  `message` text NOT NULL,
  `is_internal_note` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_msg_conv` (`conversation_id`),
  KEY `fk_msg_user` (`sender_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `support_messages`
--

LOCK TABLES `support_messages` WRITE;
/*!40000 ALTER TABLE `support_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `support_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'bcrypt hashed',
  `full_name` varchar(100) NOT NULL,
  `role` enum('administrator','owner','staff','technician','vendolink_support') NOT NULL DEFAULT 'staff',
  `status` enum('active','inactive','banned') NOT NULL DEFAULT 'active',
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `email_verify_token` varchar(100) DEFAULT NULL,
  `reset_token` varchar(100) DEFAULT NULL COMMENT 'Password reset token (hashed)',
  `reset_token_expires` datetime DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL COMMENT 'Remember-me token (hashed)',
  `login_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`),
  UNIQUE KEY `uq_username` (`username`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=203 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Application users with RBAC support';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','vendolink.support@gmail.com','$2y$12$O8qS.U9V8wsz75Rnww6u3uqr913imoCOTg9PEI.yGs1oaCmIQf3RK','VendoLink Support Admin','administrator','active',1,NULL,NULL,NULL,NULL,0,NULL,'2026-10-17 21:59:32',NULL,NULL,'2026-08-22 12:03:08','2026-10-17 21:59:32');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-17 22:03:35
