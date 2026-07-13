-- ============================================================
-- Mbarara District Local Government
-- Staff Attendance & AI-Assisted Monthly Reporting System
-- Database schema (MySQL 5.7+/MariaDB 10.3+)
-- ============================================================

CREATE DATABASE IF NOT EXISTS mbarara_attendance
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mbarara_attendance;

-- ---------------------------------------------------------
-- Departments
-- ---------------------------------------------------------
CREATE TABLE departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Staff (establishment register)
-- ---------------------------------------------------------
CREATE TABLE staff (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_no VARCHAR(20) NOT NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  designation VARCHAR(120) NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  status ENUM('active','interdicted','retired','deceased') NOT NULL DEFAULT 'active',
  date_joined DATE DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- System users (HR officers who operate the register, CAO who reviews reports)
-- ---------------------------------------------------------
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('hr','cao','admin') NOT NULL DEFAULT 'hr',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Attendance policy settings (editable, drives the AI flags)
-- ---------------------------------------------------------
CREATE TABLE settings (
  setting_key VARCHAR(60) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('day_start_time', '08:00:00'),
  ('grace_minutes', '15'),
  ('day_end_time', '17:00:00'),
  ('late_flag_threshold', '3'),
  ('absence_flag_threshold', '2'),
  ('attendance_target_pct', '85'),
  ('punctuality_target_pct', '90'),
  ('district_name', 'Mbarara District Local Government'),
  ('cao_office', 'Office of the Chief Administrative Officer');

-- ---------------------------------------------------------
-- Daily attendance records (the "register")
-- ---------------------------------------------------------
CREATE TABLE attendance_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id INT UNSIGNED NOT NULL,
  attendance_date DATE NOT NULL,
  check_in TIME DEFAULT NULL,
  check_out TIME DEFAULT NULL,
  status ENUM('present','late','absent','leave') NOT NULL DEFAULT 'present',
  late_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  remarks VARCHAR(255) DEFAULT NULL,
  capture_method ENUM('manual','biometric','supervisor') NOT NULL DEFAULT 'manual',
  recorded_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_staff_day (staff_id, attendance_date),
  KEY idx_date (attendance_date),
  KEY idx_status (status),
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Generated monthly reports (snapshot sent to the CAO)
-- ---------------------------------------------------------
CREATE TABLE monthly_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_year SMALLINT UNSIGNED NOT NULL,
  report_month TINYINT UNSIGNED NOT NULL,
  attendance_rate DECIMAL(5,2) NOT NULL,
  punctuality_rate DECIMAL(5,2) NOT NULL,
  absenteeism_rate DECIMAL(5,2) NOT NULL,
  total_staff INT UNSIGNED NOT NULL,
  narrative_summary TEXT NOT NULL,
  ai_insights TEXT DEFAULT NULL,
  generated_by INT UNSIGNED DEFAULT NULL,
  status ENUM('draft','sent') NOT NULL DEFAULT 'draft',
  generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_period (report_year, report_month),
  FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Flagged staff captured at time of each report (audit trail)
-- ---------------------------------------------------------
CREATE TABLE report_flags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id INT UNSIGNED NOT NULL,
  staff_id INT UNSIGNED NOT NULL,
  late_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  absent_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  risk_score DECIMAL(5,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (report_id) REFERENCES monthly_reports(id) ON DELETE CASCADE,
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Seed: departments
-- ---------------------------------------------------------
INSERT INTO departments (name) VALUES
  ('Finance & Administration'),
  ('Health Services'),
  ('Education'),
  ('Works & Technical Services'),
  ('Community-Based Services'),
  ('Revenue & Planning'),
  ('Production & Marketing'),
  ('Internal Audit');

-- ---------------------------------------------------------
-- Seed: default users (password for both is: password123)
-- Hash generated with PHP password_hash() — change on first login.
-- ---------------------------------------------------------
INSERT INTO users (full_name, username, password_hash, role) VALUES
  ('HR Officer, Mbarara DLG', 'hr.officer', '$2y$10$KXjb0moAZ0q.S.tZrIOyKuMuRazHInuXWqiqZkcElzZugA1yLP1PS', 'hr'),
  ('Chief Administrative Officer', 'cao', '$2y$10$KXjb0moAZ0q.S.tZrIOyKuMuRazHInuXWqiqZkcElzZugA1yLP1PS', 'cao');
-- Default password for both seed accounts: password123 (change immediately after first login)
