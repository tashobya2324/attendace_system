# Database Dictionary
## Database: `mbarara_attendance` (MySQL 5.7+ / MariaDB 10.3+)

Full DDL: [`database/schema.sql`](../database/schema.sql).

## Entity-Relationship Overview

```
departments 1───N staff 1───N attendance_records N───1 users (recorded_by)
                    │
                    N
                    │
              report_flags N───1 monthly_reports N───1 users (generated_by)
```

- One department has many staff.
- One staff member has many attendance records (one per day, enforced by a
  unique key).
- One monthly report has many flagged-staff rows (a point-in-time snapshot
  of who was flagged when that report was sent).
- `settings` and `users` stand alone, referenced by other tables but not
  owned by them.

---

## `departments`

The district's organisational units.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `name` | VARCHAR(120) | NOT NULL, UNIQUE | e.g. "Health Services" |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |

---

## `staff`

The establishment register.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `staff_no` | VARCHAR(20) | NOT NULL, UNIQUE | Public service employee number, e.g. `000000001009300` |
| `full_name` | VARCHAR(150) | NOT NULL | |
| `designation` | VARCHAR(120) | NOT NULL | Specific job title, e.g. "Senior Accountant" |
| `department_id` | INT UNSIGNED | NOT NULL, FK → `departments.id` | |
| `job_group` | VARCHAR(60) | NULL | Establishment rank/grade band, e.g. "Senior Officer", "Assistant Commissioner" |
| `salary_scale` | VARCHAR(20) | NULL | e.g. `U3 SC`, `U4 LWR` |
| `staff_category` | ENUM | `traditional` \| `health`, default `traditional` | Distinguishes health-sector cadre staff from the general establishment |
| `phone` | VARCHAR(30) | NULL | |
| `email` | VARCHAR(150) | NULL | |
| `status` | ENUM | `active` \| `interdicted` \| `retired` \| `deceased`, default `active` | Deactivating a staff member sets this to `retired`; only `active` staff appear in the register and reports |
| `date_joined` | DATE | NULL | |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |

Deleting a staff row **cascades** and deletes all of their
`attendance_records` (`ON DELETE CASCADE`) — this is why the UI requires
confirmation before a hard delete, and why **Deactivate** (a soft delete)
is offered as the default, safer action.

---

## `users`

System login accounts.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `full_name` | VARCHAR(150) | NOT NULL | |
| `username` | VARCHAR(60) | NOT NULL, UNIQUE | |
| `password_hash` | VARCHAR(255) | NOT NULL | bcrypt via PHP `password_hash()` |
| `role` | ENUM | `hr` \| `cao` \| `admin`, default `hr` | Drives `require_role()` access control throughout the app |
| `is_active` | TINYINT(1) | NOT NULL, default 1 | Inactive accounts cannot log in |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |

---

## `settings`

The attendance policy, stored as data rather than hardcoded, so it can be
tuned without a code change.

| `setting_key` | Default value | Meaning |
|---|---|---|
| `day_start_time` | `08:00:00` | Official reporting time |
| `grace_minutes` | `15` | Minutes after start time before a check-in is marked **Late** |
| `day_end_time` | `17:00:00` | Official closing time (used for generating realistic checkout times in seed data) |
| `late_flag_threshold` | `3` | Late arrivals per month before a staff member is flagged |
| `absence_flag_threshold` | `2` | Unexplained absences per month before a staff member is flagged |
| `attendance_target_pct` | `85` | District attendance-rate target shown on the report |
| `punctuality_target_pct` | `90` | District punctuality-rate target shown on the report |
| `district_name` | `Mbarara District Local Government` | Used in headers and the transmittal memo |
| `cao_office` | `Office of the Chief Administrative Officer` | Used in headers |

Read via `get_setting($key, $default)` in [`includes/analysis.php`](../includes/analysis.php).

---

## `attendance_records`

The daily register — one row per staff member per day.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| `staff_id` | INT UNSIGNED | NOT NULL, FK → `staff.id`, `ON DELETE CASCADE` | |
| `attendance_date` | DATE | NOT NULL | |
| `check_in` | TIME | NULL | Null until the staff member checks in |
| `check_out` | TIME | NULL | Null until the staff member checks out |
| `status` | ENUM | `present` \| `late` \| `absent` \| `leave`, default `present` | Derived automatically from `check_in` vs. `day_start_time` + `grace_minutes` |
| `late_minutes` | SMALLINT UNSIGNED | NOT NULL, default 0 | Minutes late beyond the grace period |
| `remarks` | VARCHAR(255) | NULL | Reason for lateness/absence/leave |
| `capture_method` | ENUM | `manual` \| `biometric` \| `supervisor`, default `manual` | How the entry was captured — the schema already supports a future biometric feed |
| `recorded_by` | INT UNSIGNED | NULL, FK → `users.id`, `ON DELETE SET NULL` | Which HR user made the entry |
| `created_at` / `updated_at` | DATETIME | auto-managed | |

**Constraints:** `UNIQUE (staff_id, attendance_date)` — a staff member can
have only one register row per day; check-in and check-out both write to
the same row. Indexed on `attendance_date` and `status` for fast monthly
aggregation.

---

## `monthly_reports`

A persisted snapshot of each report sent to the CAO — this is what makes
the CAO's login show "Sent to CAO" and the transmittal date, rather than
always recomputing live.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `report_year` / `report_month` | SMALLINT / TINYINT UNSIGNED | NOT NULL | |
| `attendance_rate` / `punctuality_rate` / `absenteeism_rate` | DECIMAL(5,2) | NOT NULL | Computed at send time |
| `total_staff` | INT UNSIGNED | NOT NULL | |
| `narrative_summary` | TEXT | NOT NULL | The AI-drafted memo text |
| `ai_insights` | TEXT | NULL | JSON-encoded list of insight bullets |
| `generated_by` | INT UNSIGNED | NULL, FK → `users.id`, `ON DELETE SET NULL` | Which HR user sent it |
| `status` | ENUM | `draft` \| `sent`, default `draft` | |
| `generated_at` / `sent_at` | DATETIME | | |

**Constraint:** `UNIQUE (report_year, report_month)` — sending a report for
a period that was already sent overwrites the previous snapshot
(`ON DUPLICATE KEY UPDATE` in `api/send_report.php`).

---

## `report_flags`

Which staff were flagged **at the time** a given report was sent — an
audit trail independent of the live `detect_flagged_staff()` computation
(which would otherwise change retroactively as data is edited later).

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | |
| `report_id` | INT UNSIGNED | NOT NULL, FK → `monthly_reports.id`, `ON DELETE CASCADE` | |
| `staff_id` | INT UNSIGNED | NOT NULL, FK → `staff.id`, `ON DELETE CASCADE` | |
| `late_count` / `absent_count` | SMALLINT UNSIGNED | NOT NULL, default 0 | |
| `risk_score` | DECIMAL(5,2) | NOT NULL, default 0 | `late_count × 1.0 + absent_count × 2.0`, used to rank flagged staff |
