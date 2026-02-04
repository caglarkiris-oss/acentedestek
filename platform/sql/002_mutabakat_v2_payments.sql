-- Mutabakat V2 - Odeme adimi icin ek kolonlar
-- Not: 001_mutabakat_v2.sql calistiktan sonra uygulanir.

SET NAMES utf8mb4;

-- 1) Status enum'una ODENDI ekle (varsa hata vermesin diye try-catch yok; MySQL'de yok)
-- Eger mevcut ENUM farkliysa, bu satiri kendi DB'ne gore duzenle.
ALTER TABLE `mutabakat_v2_assignments`
  MODIFY `status` ENUM('BEKLEMEDE','ATANDI','ONAYLANDI','REDDEDILDI','ITIRAZDA','ODENDI') NOT NULL DEFAULT 'BEKLEMEDE';

-- 2) Odeme meta alanlari
-- Not: Bu kolonlar zaten varsa bu ALTER hata verebilir. O durumda ilgili ADD COLUMN satirlarini DB'nden silip tekrar calistir.
ALTER TABLE `mutabakat_v2_assignments` ADD COLUMN `paid_by` INT UNSIGNED NULL AFTER `approved_by`;
ALTER TABLE `mutabakat_v2_assignments` ADD COLUMN `paid_at` DATETIME NULL AFTER `approved_at`;
ALTER TABLE `mutabakat_v2_assignments` ADD COLUMN `paid_note` TEXT NULL AFTER `tali_notes`;
