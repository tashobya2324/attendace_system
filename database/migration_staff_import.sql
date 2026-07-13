-- ============================================================
-- Migration: AI-assisted mass staff entry from a document
-- (Excel, Word, PDF, or a photo of a nominal roll)
-- Run once: mysql -u root mbarara_attendance < database/migration_staff_import.sql
-- ============================================================

USE mbarara_attendance;

CREATE TABLE IF NOT EXISTS staff_import_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_filename VARCHAR(255) NOT NULL,
  stored_filename VARCHAR(255) NOT NULL,
  file_type ENUM('csv','xlsx','docx','pdf','image') NOT NULL,
  extraction_method ENUM('direct_parse','ai') NOT NULL DEFAULT 'ai',
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

CREATE TABLE IF NOT EXISTS staff_import_rows (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id INT UNSIGNED NOT NULL,
  row_index SMALLINT UNSIGNED NOT NULL,
  raw_full_name VARCHAR(255) DEFAULT NULL,
  raw_department VARCHAR(255) DEFAULT NULL,
  raw_designation VARCHAR(255) DEFAULT NULL,
  raw_staff_no VARCHAR(50) DEFAULT NULL,
  matched_department_id INT UNSIGNED DEFAULT NULL,
  department_match_confidence DECIMAL(5,2) DEFAULT NULL,
  FOREIGN KEY (batch_id) REFERENCES staff_import_batches(id) ON DELETE CASCADE,
  FOREIGN KEY (matched_department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;
