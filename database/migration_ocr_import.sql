-- ============================================================
-- Migration: AI-assisted paper register backfill (OCR import)
-- Run once against an existing mbarara_attendance database:
--   mysql -u root mbarara_attendance < database/migration_ocr_import.sql
-- ============================================================

USE mbarara_attendance;

-- Let attendance_records note when a row came from a scanned paper
-- register rather than a live check-in/check-out.
ALTER TABLE attendance_records
  MODIFY capture_method ENUM('manual','biometric','supervisor','ocr_import') NOT NULL DEFAULT 'manual';

-- ---------------------------------------------------------
-- One row per uploaded register photo.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_import_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  register_date DATE NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_filename VARCHAR(255) NOT NULL,
  uploaded_by INT UNSIGNED DEFAULT NULL,
  ai_model VARCHAR(60) DEFAULT NULL,
  ai_raw_response LONGTEXT DEFAULT NULL,
  status ENUM('pending_review','committed','rejected') NOT NULL DEFAULT 'pending_review',
  error_message VARCHAR(500) DEFAULT NULL,
  reviewed_by INT UNSIGNED DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- One row per name the AI extracted from that photo — staged for
-- HR review/correction before anything touches attendance_records.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_import_rows (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id INT UNSIGNED NOT NULL,
  row_index SMALLINT UNSIGNED NOT NULL,
  raw_name_text VARCHAR(255) DEFAULT NULL,
  raw_check_in VARCHAR(20) DEFAULT NULL,
  raw_check_out VARCHAR(20) DEFAULT NULL,
  raw_remarks VARCHAR(255) DEFAULT NULL,
  matched_staff_id INT UNSIGNED DEFAULT NULL,
  match_confidence DECIMAL(5,2) DEFAULT NULL,
  final_status ENUM('present','late','absent','leave') DEFAULT NULL,
  final_check_in TIME DEFAULT NULL,
  final_check_out TIME DEFAULT NULL,
  final_remarks VARCHAR(255) DEFAULT NULL,
  row_decision ENUM('pending','accepted','skipped') NOT NULL DEFAULT 'pending',
  FOREIGN KEY (batch_id) REFERENCES attendance_import_batches(id) ON DELETE CASCADE,
  FOREIGN KEY (matched_staff_id) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB;
