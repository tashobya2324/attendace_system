# Software Requirements Specification (SRS)
## AI-Assisted Staff Attendance & Monthly Reporting System
### Mbarara District Local Government — Human Resource Management Unit

Version 1.0 — conforms to the structure of IEEE Std 830-1998, scoped to the
system as designed and implemented (not an aspirational future version).

---

## 1. Introduction

### 1.1 Purpose
This document specifies the functional and non-functional requirements of
the AI-Assisted Staff Attendance & Monthly Reporting System. It is intended
to be read alongside [01-Project-Report.md](01-Project-Report.md) (the
rationale and design narrative) and
[03-Database-Dictionary.md](03-Database-Dictionary.md) (the data model);
this document is the authoritative statement of *what the system must do*.

### 1.2 Document Conventions
Each requirement is identified as **FR-n** (functional) or **NFR-n**
(non-functional) and carries a priority:

- **Must** — required for the system to fulfil the assignment brief
- **Should** — materially improves the system but is not strictly required
- **Could** — a nice-to-have, explicitly out of scope for this version

The word **shall** indicates a binding requirement, per standard SRS usage.

### 1.3 Intended Audience
- The HR Unit and CAO's office (Mbarara DLG), as the system's operators
- The assessor(s) evaluating this work against the assignment brief
- Any future developer extending the system (e.g. adding biometric
  hardware integration or a richer LLM-backed narrative generator)

### 1.4 Product Scope
The system digitises the daily staff sign-in/sign-out register and
automates the monthly attendance-analysis reporting function that HR
currently performs by hand for the CAO. It covers attendance capture,
staff/department administration, statistical analysis, rule-based anomaly
flagging, trend forecasting, and report transmittal. It does **not** cover
payroll, leave-application workflow, or biometric hardware integration in
this version (see §2.5).

### 1.5 References
- Assignment brief: *"Study the HR unit's staff daily attendance register…
  design and build an AI-enabled solution… generate a report for June 2026
  to the CAO."*
- [database/schema.sql](../database/schema.sql) — authoritative schema
- [includes/analysis.php](../includes/analysis.php) — authoritative AI logic

---

## 2. Overall Description

### 2.1 Product Perspective
This is a new, standalone web application — it does not replace or
integrate with any existing district IT system (none was in place; the
prior process was a paper register). It is self-contained: PHP backend,
MySQL database, browser-based UI, no external service dependencies beyond
CDN-hosted CSS/JS libraries (Tailwind, Chart.js).

### 2.2 Product Functions (summary)
- User authentication with two roles (HR Officer, CAO)
- Daily attendance capture (check-in / check-out) with automatic
  late-status derivation
- Staff establishment management (create, deactivate, reactivate, delete)
- Department management (create, rename, delete)
- Monthly statistical computation (attendance/punctuality/absenteeism
  rates, department breakdown, daily trend)
- Rule-based anomaly detection (flagged staff)
- Statistical forecasting of next month's rates
- Automated narrative report generation
- Report transmittal to, and read-only review by, the CAO

### 2.3 User Classes and Characteristics

| User class | Description | Technical proficiency assumed |
|---|---|---|
| **HR Officer** | Operates the daily register, manages staff/department records, reviews and sends the monthly report | Basic computer literacy; no programming knowledge required |
| **Chief Administrative Officer (CAO)** | Reviews sent monthly reports and flagged staff | Basic computer literacy |
| **System Administrator** *(role exists in the data model as `admin`, not yet exposed in the UI)* | Would manage user accounts and system settings | Technical proficiency |

### 2.4 Operating Environment
- **Server:** PHP 8.0+ with the `mysqli` extension, any Apache/Nginx host
  or PHP's built-in server; developed and verified against XAMPP on
  Windows.
- **Database:** MySQL 5.7+ or MariaDB 10.3+.
- **Client:** any modern desktop or mobile browser with JavaScript
  enabled; no native app or plugin required.

### 2.5 Design and Implementation Constraints
- Must use **PHP, JavaScript, and Tailwind CSS** for the application layer,
  and **MySQL** for persistence (assignment-mandated stack).
- No client-side framework or build pipeline — a deliberate constraint to
  keep the system deployable on ordinary shared/government hosting without
  a Node.js toolchain.
- Biometric capture hardware is **out of scope**; the data model reserves
  a `capture_method` field (`manual` / `biometric` / `supervisor`) so this
  can be added later without a schema change.
- Payroll and leave-application workflow are **out of scope**.

### 2.6 Assumptions and Dependencies
- Each staff member is assigned to exactly one department at a time.
- The district's working week is Monday–Friday (weekends are excluded from
  working-day counts and report generation).
- The official reporting time (08:00) and grace period (15 minutes) are
  configurable via the `settings` table but assumed constant across all
  departments in this version — a department-specific policy is not
  supported.
- The forecasting function assumes at least one prior month of data exists;
  with zero prior months it reports "not available" rather than guessing.

---

## 3. External Interface Requirements

### 3.1 User Interfaces
- Web-based, responsive layout (desktop and tablet widths verified);
  built with Tailwind CSS utility classes.
- Two navigation contexts by role: the HR Officer sees Dashboard, Daily
  Register, Staff, Departments, and Monthly Report; the CAO sees only
  Monthly Report.
- All destructive actions (delete staff, delete department, delete
  attendance record) require an explicit confirmation dialog.

### 3.2 Hardware Interfaces
None required for this version. The `capture_method` field anticipates a
future biometric kiosk interface but no such interface is implemented.

### 3.3 Software Interfaces
- **MySQL/MariaDB** via the PHP `mysqli` extension, using prepared
  statements exclusively.
- **Tailwind CSS** and **Chart.js**, loaded via CDN `<script>`/`<style>`
  tags — the only external software dependencies.

### 3.4 Communications Interfaces
Standard HTTP(S); all in-page dynamic behaviour (the register's check-in/
check-out panels, the report's Send action) uses `fetch()`-based AJAX calls
returning JSON, over the same origin as the page — no cross-origin requests
are made.

---

## 4. System Features (Functional Requirements)

### 4.1 Authentication & Access Control

| ID | Requirement | Priority |
|---|---|---|
| FR-1 | The system **shall** require a valid username and password before granting access to any page other than the login page. | Must |
| FR-2 | The system **shall** store passwords only as bcrypt hashes (`password_hash()`), never in plain text. | Must |
| FR-3 | The system **shall** restrict each page and API endpoint to the roles authorised for it (`hr`/`admin` for management pages, all roles for the report view). | Must |
| FR-4 | The system **shall** reject any state-changing request that does not carry a valid CSRF token bound to the active session. | Must |
| FR-5 | The system **shall** provide a logout action that destroys the session. | Must |

### 4.2 Daily Attendance Register

| ID | Requirement | Priority |
|---|---|---|
| FR-6 | The system **shall** allow an HR Officer to record a check-in for any active staff member, capturing time, capture method, and an optional remark. | Must |
| FR-7 | The system **shall** allow an HR Officer to record a check-out for any active staff member who has already checked in on the same date. | Must |
| FR-8 | The system **shall** automatically classify a check-in as **Late** if it occurs more than the configured grace period after the configured start time, and **Present** otherwise. | Must |
| FR-9 | The Check-In list **shall** exclude any staff member who has already checked in for the selected date. | Must |
| FR-10 | The Check-Out list **shall** exclude any staff member who has not checked in, or who has already checked out, for the selected date. | Must |
| FR-11 | The system **shall** allow an HR Officer to view the full attendance ledger for any date, filterable by department and status. | Must |
| FR-12 | The system **shall** allow an HR Officer to delete an individual attendance record, with confirmation. | Should |
| FR-13 | The system **shall** enforce at most one attendance record per staff member per date. | Must |

### 4.3 Staff Management

| ID | Requirement | Priority |
|---|---|---|
| FR-14 | The system **shall** allow an HR Officer to add a staff member with a unique staff number, full name, designation, and department. | Must |
| FR-15 | The system **shall** allow an HR Officer to deactivate a staff member (soft delete), excluding them from the register and reports while retaining their history. | Must |
| FR-16 | The system **shall** allow an HR Officer to reactivate a previously deactivated staff member. | Should |
| FR-17 | The system **shall** allow an HR Officer to permanently delete a staff member and their associated attendance history, with explicit confirmation. | Should |

### 4.4 Department Management

| ID | Requirement | Priority |
|---|---|---|
| FR-18 | The system **shall** allow an HR Officer to add a new department. | Must |
| FR-19 | The system **shall** allow an HR Officer to rename an existing department. | Should |
| FR-20 | The system **shall** prevent deletion of a department that still has active staff assigned to it, and **shall** state how many staff are blocking the deletion. | Must |

### 4.5 Monthly Statistical Analysis (AI Feature 1 of 3)

| ID | Requirement | Priority |
|---|---|---|
| FR-21 | The system **shall** compute, for any given month, the district-wide attendance rate, punctuality rate, and absenteeism rate from recorded attendance data. | Must |
| FR-22 | The system **shall** compute the same rates broken down per department, ranked from highest to lowest attendance rate. | Must |
| FR-23 | The system **shall** produce a day-by-day series of present/late/absent counts for the month, suitable for charting. | Must |
| FR-24 | The Monthly Report page **shall** default to the most recently completed calendar month when no period is explicitly selected. | Should |

### 4.6 Anomaly Flagging (AI Feature 2 of 3)

| ID | Requirement | Priority |
|---|---|---|
| FR-25 | The system **shall** flag any staff member whose late-arrival count for the month exceeds a configurable threshold (default 3). | Must |
| FR-26 | The system **shall** flag any staff member whose unexplained-absence count for the month exceeds a configurable threshold (default 2). | Must |
| FR-27 | The system **shall** rank flagged staff by a risk score computed from their late and absence counts. | Should |
| FR-28 | The system **shall** persist the flagged-staff list at the moment a report is sent, independent of later data edits, for audit purposes. | Should |

### 4.7 Forecasting (AI Feature 3 of 3)

| ID | Requirement | Priority |
|---|---|---|
| FR-29 | The system **shall** project next month's attendance and absenteeism rate using a linear trend fitted across the trailing months of available data. | Must |
| FR-30 | The system **shall** label the forecast's trend direction (improving / stable / declining) and a confidence level (low / moderate), rather than presenting a bare number. | Must |
| FR-31 | The system **shall** state clearly when insufficient data exists to forecast, rather than fabricating a projection. | Must |

### 4.8 Report Generation & Transmittal

| ID | Requirement | Priority |
|---|---|---|
| FR-32 | The system **shall** automatically draft a narrative report addressed to the CAO, incorporating the computed rates, the best/worst-performing department, the flagged-staff count, and the forecast. | Must |
| FR-33 | The system **shall** require an explicit HR action ("Send report to CAO") before a report becomes visible to the CAO role — reports are never auto-sent. | Must |
| FR-34 | The system **shall** record when a report was sent and by whom. | Should |
| FR-35 | The system **shall** allow a report for a given period to be re-sent, overwriting the previous snapshot for that period. | Could |
| FR-36 | The system **shall** provide a print-friendly view of the report suitable for saving as a PDF. | Should |
| FR-37 | The system **shall** provide a CSV export of the underlying attendance data for a selected period. | Could |

### 4.9 CAO Review

| ID | Requirement | Priority |
|---|---|---|
| FR-38 | The CAO role **shall** be able to view any previously sent monthly report, including the narrative, statistics, and flagged-staff list. | Must |
| FR-39 | The CAO role **shall not** be able to modify attendance data, staff records, department records, or send reports. | Must |
| FR-40 | The system **shall** display when a viewed report was transmitted, so the CAO can distinguish a sent report from a live, unsent view. | Should |

---

## 5. Non-Functional Requirements

### 5.1 Performance
- **NFR-1 (Should):** A monthly report for a district of up to ~200 staff
  shall render within 2 seconds on typical shared hosting, given indexed
  queries on `attendance_date` and `status`.
- **NFR-2 (Should):** The Daily Register's check-in/check-out list refresh
  shall complete within 1 second of an action being recorded.

### 5.2 Security
- **NFR-3 (Must):** All database access shall use parameterised/prepared
  statements; no user input shall be concatenated into SQL.
- **NFR-4 (Must):** All state-changing HTTP requests shall be protected by
  a CSRF token.
- **NFR-5 (Must):** Session cookies shall be marked secure when served over
  HTTPS in production.
- **NFR-6 (Should):** Default demonstration credentials shall be
  changeable, and documentation shall instruct operators to change them
  before real use.

### 5.3 Reliability
- **NFR-7 (Must):** A unique constraint on `(staff_id, attendance_date)`
  shall guarantee at most one attendance record per person per day, even
  under concurrent requests.
- **NFR-8 (Should):** Deleting a staff member shall not leave orphaned
  attendance records (enforced via `ON DELETE CASCADE`).

### 5.4 Usability
- **NFR-9 (Must):** Destructive actions (delete staff, delete department,
  delete attendance record) shall require explicit user confirmation
  before executing.
- **NFR-10 (Should):** The interface shall clearly distinguish attendance
  statuses (Present/Late/Absent/On leave) using consistent colour coding
  throughout the application.
- **NFR-11 (Should):** Error messages shall state what went wrong and, where
  applicable, what the user needs to do (e.g. "Cannot delete this
  department — 5 staff record(s) are still assigned to it").

### 5.5 Maintainability & Portability
- **NFR-12 (Should):** The system shall run without modification on any
  standard LAMP/WAMP/XAMPP stack meeting the versions in §2.4 — no
  proprietary hosting features required.
- **NFR-13 (Could):** The attendance policy (start time, grace period,
  thresholds, targets) shall be stored as configurable data (`settings`
  table) rather than hardcoded, so operational changes do not require a
  code change.

### 5.6 Business Rules
- **NFR-14 (Must):** The official start time and grace period jointly
  determine Late vs. Present status; this rule shall be applied
  consistently by both the live register and any historical data import.
- **NFR-15 (Must):** Only staff with `status = 'active'` shall appear in
  the register, staff-selection dropdowns, and report calculations.

---

## 6. Data Requirements

Summarised here; full field-level specification in
[03-Database-Dictionary.md](03-Database-Dictionary.md).

- The system shall persist: departments, staff, user accounts, daily
  attendance records, sent monthly reports, and the flagged-staff snapshot
  associated with each sent report.
- Attendance status shall be restricted to one of exactly four values:
  `present`, `late`, `absent`, `leave`.
- User roles shall be restricted to one of exactly three values: `hr`,
  `cao`, `admin`.

---

## 7. Requirements Traceability Matrix

| Requirement(s) | Implemented in |
|---|---|
| FR-1 – FR-5 | `includes/auth.php`, `public/index.php`, `public/logout.php` |
| FR-6 – FR-13 | `public/register.php`, `public/assets/js/register.js`, `public/api/record_entry.php`, `public/api/delete_entry.php`, `public/api/ledger.php`, `public/api/staff_status.php` |
| FR-14 – FR-17 | `public/staff.php` |
| FR-18 – FR-20 | `public/departments.php` |
| FR-21 – FR-24 | `includes/analysis.php` (`compute_period_stats`, `compute_department_stats`, `compute_daily_series`), `public/reports.php` |
| FR-25 – FR-28 | `includes/analysis.php` (`detect_flagged_staff`), `public/api/send_report.php`, `report_flags` table |
| FR-29 – FR-31 | `includes/analysis.php` (`forecast_next_month`) |
| FR-32 – FR-37 | `includes/analysis.php` (`generate_ai_narrative`, `generate_ai_insights`), `public/api/send_report.php`, `public/reports.php` |
| FR-38 – FR-40 | `public/reports.php` (role-gated via `require_role(['hr','cao','admin'])`) |
| NFR-1 – NFR-15 | Cross-cutting — see `database/schema.sql` indexes/constraints, `includes/auth.php` CSRF/session handling, and the confirmation dialogs throughout `public/*.php` |

---

## Appendix A — Glossary

| Term | Definition |
|---|---|
| **Flagged staff** | A staff member whose late-arrival or absence count exceeded the configured policy threshold for the period |
| **Grace period** | Minutes allowed after the official start time before a check-in counts as Late |
| **Narrative report** | The auto-drafted prose memo addressed to the CAO, summarising the period |
| **Capture method** | How an attendance entry was recorded: `manual`, `biometric`, or `supervisor` |
| **Sent report** | A monthly report snapshot persisted via "Send report to CAO," visible on the CAO's login |

## Appendix B — Primary Use Cases

1. **HR Officer records a check-in** — Actor: HR Officer. Trigger: staff
   member arrives. Flow: open Daily Register → Check In tab → select
   staff → confirm time → submit. Postcondition: staff removed from
   Check-In list, added to Check-Out list.
2. **HR Officer sends the monthly report** — Actor: HR Officer. Trigger:
   month-end reporting cycle. Flow: open Monthly Report → select period →
   review AI-drafted memo and flagged staff → Send report to CAO.
   Postcondition: report visible on the CAO's login with a sent timestamp.
3. **CAO reviews a report** — Actor: CAO. Trigger: notified (out of band)
   that a report was sent. Flow: log in → land on Monthly Report → review
   figures, forecast, and flagged staff → optionally print/save as PDF.
4. **HR Officer manages the establishment** — Actor: HR Officer. Trigger:
   staff transfer, new hire, or departmental reorganisation. Flow: Staff
   or Departments page → add/rename/deactivate/delete as needed.
