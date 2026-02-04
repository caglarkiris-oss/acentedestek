-- Mutabakat V2 Schema
-- Multi-tenant sigorta mutabakat sistemi

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- 1) DONEMLER (Periods)
-- =============================================
CREATE TABLE IF NOT EXISTS `mutabakat_v2_periods` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ana_acente_id` INT UNSIGNED NOT NULL,
  `year` SMALLINT UNSIGNED NOT NULL,
  `month` TINYINT UNSIGNED NOT NULL,
  `status` ENUM('OPEN','CLOSED','LOCKED') NOT NULL DEFAULT 'OPEN',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_period` (`ana_acente_id`, `year`, `month`),
  KEY `idx_ana_acente` (`ana_acente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 2) ANA TABLO: SATIRLAR (Rows)
-- Tum kaynaklari tek tabloda tutar: ANA_CSV, TALI_CSV, TICKET
-- =============================================
CREATE TABLE IF NOT EXISTS `mutabakat_v2_rows` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_id` INT UNSIGNED NOT NULL,
  `ana_acente_id` INT UNSIGNED NOT NULL,
  `tali_acente_id` INT UNSIGNED NOT NULL DEFAULT 0,
  
  -- Kaynak bilgisi
  `source_type` ENUM('ANA_CSV','TALI_CSV','TICKET') NOT NULL,
  `ticket_id` INT UNSIGNED NULL,
  `import_batch_id` INT UNSIGNED NULL,
  
  -- Sigortali bilgileri
  `tc_vn` VARCHAR(50) NULL,
  `sigortali_adi` VARCHAR(255) NULL,
  `sig_kimlik_no` VARCHAR(50) NULL,
  
  -- Police bilgileri
  `policy_no` VARCHAR(120) NULL,
  `policy_no_norm` VARCHAR(160) NULL,
  `txn_type` ENUM('SATIS','IPTAL','ZEYIL') NOT NULL DEFAULT 'SATIS',
  `zeyil_turu` VARCHAR(100) NULL,
  
  -- Tarihler
  `tanzim_tarihi` DATE NULL,
  `bitis_tarihi` DATE NULL,
  
  -- Sigorta detaylari
  `sigorta_sirketi` VARCHAR(255) NULL,
  `brans` VARCHAR(100) NULL,
  `urun` VARCHAR(255) NULL,
  `plaka` VARCHAR(30) NULL,
  
  -- Finansal
  `brut_prim` DECIMAL(14,2) NULL,
  `net_prim` DECIMAL(14,2) NULL,
  `komisyon_tutari` DECIMAL(14,2) NULL,
  `araci_kom_payi` DECIMAL(14,2) NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'TRY',
  
  -- Durum
  `row_status` ENUM('HAVUZ','ESLESEN','ESLESMEYEN','ITIRAZ') NOT NULL DEFAULT 'HAVUZ',
  `locked` TINYINT(1) NOT NULL DEFAULT 0,
  
  -- Meta
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_period` (`period_id`),
  KEY `idx_ana_acente` (`ana_acente_id`),
  KEY `idx_tali_acente` (`tali_acente_id`),
  KEY `idx_source` (`source_type`),
  KEY `idx_status` (`row_status`),
  KEY `idx_policy` (`policy_no`),
  KEY `idx_policy_norm` (`policy_no_norm`),
  KEY `idx_ticket` (`ticket_id`),
  KEY `idx_batch` (`import_batch_id`),
  KEY `idx_period_ana_source` (`period_id`, `ana_acente_id`, `source_type`),
  KEY `idx_period_ana_tali` (`period_id`, `ana_acente_id`, `tali_acente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 3) IMPORT BATCH (CSV yuklemeleri)
-- =============================================
CREATE TABLE IF NOT EXISTS `mutabakat_v2_import_batches` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_id` INT UNSIGNED NOT NULL,
  `ana_acente_id` INT UNSIGNED NOT NULL,
  `tali_acente_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `source_type` ENUM('ANA_CSV','TALI_CSV') NOT NULL,
  `filename` VARCHAR(255) NULL,
  `file_hash` VARCHAR(64) NULL,
  `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `ok_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_period` (`period_id`),
  KEY `idx_ana_acente` (`ana_acente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 4) IMPORT ERRORS (Satir bazli hatalar)
-- =============================================
CREATE TABLE IF NOT EXISTS `mutabakat_v2_import_errors` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` INT UNSIGNED NOT NULL,
  `row_no` INT UNSIGNED NOT NULL,
  `error_code` VARCHAR(50) NOT NULL,
  `error_message` TEXT NULL,
  `raw_line` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 5) MATCHES (Eslestirme sonuclari)
-- =============================================
CREATE TABLE IF NOT EXISTS `mutabakat_v2_matches` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_id` INT UNSIGNED NOT NULL,
  `policy_no` VARCHAR(120) NULL,
  `tali_row_id` INT UNSIGNED NOT NULL,
  `ana_row_id` INT UNSIGNED NOT NULL,
  `status` ENUM('MATCHED','MISMATCH','DISPUTED') NOT NULL DEFAULT 'MATCHED',
  `mismatch_summary` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_match` (`period_id`, `tali_row_id`, `ana_row_id`),
  KEY `idx_period` (`period_id`),
  KEY `idx_tali_row` (`tali_row_id`),
  KEY `idx_ana_row` (`ana_row_id`),
  KEY `idx_policy` (`policy_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 6) ASSIGNMENTS (Atama basliklari)
-- =============================================
CREATE TABLE IF NOT EXISTS `mutabakat_v2_assignments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_id` INT UNSIGNED NOT NULL,
  `tali_acente_id` INT UNSIGNED NOT NULL,
  `assigned_by` INT UNSIGNED NULL,
  `approved_by` INT UNSIGNED NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` DATETIME NULL,
  `status` ENUM('BEKLEMEDE','ATANDI','ONAYLANDI','REDDEDILDI','ITIRAZDA') NOT NULL DEFAULT 'BEKLEMEDE',
  `summary_satis` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `summary_iptal` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `summary_zeyil` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `summary_hakedis` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `notes` TEXT NULL,
  `rejection_reason` TEXT NULL,
  `tali_notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_period` (`period_id`),
  KEY `idx_tali` (`tali_acente_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 7) ASSIGNMENT ROWS (Atama detaylari)
-- =============================================
CREATE TABLE IF NOT EXISTS `mutabakat_v2_assignment_rows` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id` INT UNSIGNED NOT NULL,
  `row_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_assign_row` (`assignment_id`, `row_id`),
  KEY `idx_assignment` (`assignment_id`),
  KEY `idx_row` (`row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 8) DISPUTES (Itirazlar - opsiyonel)
-- =============================================
CREATE TABLE IF NOT EXISTS `mutabakat_v2_disputes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_id` INT UNSIGNED NOT NULL,
  `row_id` INT UNSIGNED NOT NULL,
  `match_id` INT UNSIGNED NULL,
  `raised_by_agency_id` INT UNSIGNED NOT NULL,
  `raised_by_user_id` INT UNSIGNED NOT NULL,
  `dispute_type` VARCHAR(50) NOT NULL,
  `description` TEXT NULL,
  `status` ENUM('OPEN','RESOLVED','REJECTED') NOT NULL DEFAULT 'OPEN',
  `resolution_notes` TEXT NULL,
  `resolved_by` INT UNSIGNED NULL,
  `resolved_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_period` (`period_id`),
  KEY `idx_row` (`row_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
