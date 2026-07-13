# AI-Assisted Staff Attendance & Monthly Reporting System
### Mbarara District Local Government — Human Resource Management Unit

---

## 1. Introduction

Mbarara District Local Government's Human Resource Management (HR) Unit
maintains a daily staff attendance register: every staff member signs in
before 8:00 a.m. and signs out after 5:00 p.m. At the end of each month, the
HR Unit is required to compile a **Monthly Staff Attendance Analysis Report**
for the Chief Administrative Officer (CAO), summarising attendance,
punctuality, absenteeism, and flagging staff who need supervisory attention.

This process is currently manual: paper registers are tallied by hand, and
the monthly narrative report to the CAO is typed from scratch each time. This
is slow, error-prone, and gives the CAO no visibility into attendance
patterns until the report lands on their desk — by which point a whole month
of a problem has already gone unaddressed.

This document describes an **AI-enabled software solution** designed and
built to replace that manual process: a web-based Staff Attendance & Monthly
Reporting System that digitises the daily register, computes attendance
statistics automatically, flags anomalies, forecasts next month's trend, and
drafts the monthly narrative report for the CAO — while keeping HR in full
control of what actually gets sent.

## 2. Problem Statement

The existing process has four specific weaknesses:

1. **Manual tallying** — HR staff count paper sign-in sheets by hand for
   every department, every month, which is slow and error-prone.
2. **No early warning** — chronic lateness or absenteeism by a specific
   staff member is only visible once someone manually notices it, often
   long after the pattern has established itself.
3. **No forward view** — the CAO only ever sees a rear-view mirror of what
   already happened; there is no projection of where attendance is heading.
4. **Repetitive report writing** — the same kind of narrative memo is
   retyped from scratch every month, with numbers recalculated by hand.

## 3. Objectives

**General objective:** design and build an AI-enabled system that supports
the daily attendance register and automates the monthly analysis and
reporting function for the CAO.

**Specific objectives:**

- Digitise the daily sign-in/sign-out register, enforcing the official
  08:00 start time and grace period automatically.
- Compute attendance, punctuality, and absenteeism rates per department and
  district-wide, without manual tallying.
- Automatically flag staff who exceed policy thresholds for lateness or
  absence (rule-based anomaly detection).
- Forecast next month's attendance and absenteeism trend from historical
  data (statistical forecasting).
- Auto-draft the narrative monthly report to the CAO from the computed
  data (template-driven natural-language generation), while leaving HR the
  final decision to review and send it.
- Give the CAO a self-service, read-only view of the report and flagged
  staff, removing the need to wait for a printed memo.
- Provide full staff and department management so the system reflects the
  real establishment over time.

## 4. Scope

The system covers the full loop from daily attendance capture through to
monthly reporting:

- Daily Register (check-in / check-out, by department, with remarks)
- Staff establishment management (add, deactivate, reactivate, delete)
- Department management (add, rename, delete)
- Monthly report generation, AI insights, and CAO transmittal
- Two user roles: **HR Officer** (full access) and **CAO** (read-only
  report view)

Payroll integration, biometric hardware integration, and leave-application
workflows are out of scope for this iteration; the capture-method field
(`manual` / `biometric` / `supervisor`) is included in the data model so a
biometric kiosk feed could be wired in later without a schema change.

## 5. Why This Counts as "AI-Enabled"

The brief asked for an *AI-enabled* solution. Rather than bolting on a
black-box model that the HR unit couldn't explain to the CAO, the system
uses three concrete, explainable AI techniques, implemented in
[`includes/analysis.php`](../includes/analysis.php):

| Technique | What it does | Why this approach |
|---|---|---|
| **Rule-based anomaly detection** | Flags any staff member who crosses configurable thresholds (default: >3 late arrivals or >2 unexplained absences in a month) | Fully auditable — the CAO can see exactly why a name is flagged, which matters in a public-sector accountability context |
| **Statistical forecasting** | Fits a linear trend across the trailing months of real attendance data to project next month's attendance and absenteeism rate, with an honest confidence label | Gives the CAO a forward-looking signal instead of only a rear-view report, without requiring a training dataset the district doesn't have |
| **Template-driven narrative generation (NLG)** | Turns the computed statistics into the same kind of prose HR currently types by hand — identifying the best/worst department, counting flagged staff, and stating the forecast in a sentence | Produces a first draft in seconds; HR still reviews before it's sent, so the human stays in the loop for what is ultimately an accountability document |

`generate_ai_narrative()` is written as a deliberately swappable function —
if a richer, model-generated narrative is wanted later (e.g. via the Claude
API), that is the one function to change; the statistics, flags, and
forecast stay as structured data either way.

## 6. System Architecture

```
                       ┌─────────────────────────┐
                       │        Browser           │
                       │  (Tailwind CSS UI,        │
                       │   vanilla JS + Chart.js)  │
                       └────────────┬──────────────┘
                                    │ HTTP (session-authenticated)
                       ┌────────────▼──────────────┐
                       │      PHP application       │
                       │  public/*.php  (pages)     │
                       │  public/api/*.php (JSON)   │
                       ├─────────────────────────────┤
                       │  includes/auth.php          │  session, login, CSRF
                       │  includes/analysis.php      │  AI layer (stats, flags,
                       │                              │  forecast, narrative)
                       └────────────┬──────────────┘
                                    │ mysqli (prepared statements)
                       ┌────────────▼──────────────┐
                       │   MySQL — mbarara_attendance│
                       │  staff, departments, users,  │
                       │  attendance_records,          │
                       │  monthly_reports, report_flags│
                       └─────────────────────────────┘
```

The application is a classic server-rendered PHP app (no framework, easy to
host on any shared/XAMPP server) with a small amount of vanilla JavaScript
driving AJAX interactions on the Daily Register and the Chart.js trend chart
on the report page. There is no client-side framework and no build step —
Tailwind is loaded via CDN for the prototype.

## 7. Data Model

Full field-by-field reference: [`03-Database-Dictionary.md`](03-Database-Dictionary.md).
Summary of the six core tables:

- **departments** — the district's organisational units
- **staff** — the establishment register (staff number, designation,
  department, status)
- **users** — system login accounts (`hr`, `cao`, `admin` roles)
- **attendance_records** — one row per staff member per day: check-in,
  check-out, status, late minutes, remarks, capture method
- **monthly_reports** — a persisted snapshot of each report sent to the CAO
  (rates, narrative, AI insights, sent timestamp)
- **report_flags** — which staff were flagged at the time each report was
  sent, for audit trail purposes
- **settings** — the attendance policy as data, not hardcoded (start time,
  grace period, flag thresholds, attendance targets)

```
departments ──1:N── staff ──1:N── attendance_records
                      │
users ──1:N── attendance_records (recorded_by)
users ──1:N── monthly_reports (generated_by)
monthly_reports ──1:N── report_flags ──N:1── staff
```

## 8. Key Features

### 8.1 Daily Register
Two dedicated panels — **Check In** and **Check Out** — each listing only
the staff still eligible for that action on the selected date. A person
disappears from the Check In list the moment they're recorded, and appears
in Check Out until they sign out; this mirrors how a physical register
desk actually works and prevents duplicate/contradictory entries.

### 8.2 Staff & Department Management
Full lifecycle management: add, deactivate, reactivate, and permanently
delete staff (cascading their attendance history, with a confirmation
prompt); add, rename, and delete departments (blocked if staff are still
assigned, to prevent orphaned records).

### 8.3 Monthly Report
For any selected month: attendance/punctuality/absenteeism rate tiles,
department-by-department attendance bars, a daily trend chart, an
AI-assisted insight panel (forecast + anomaly bullets), a flagged-staff
table, and an auto-drafted transmittal memo — with a **Send report to CAO**
action that persists the report and makes it visible on the CAO's own
login.

### 8.4 Role-Based Access
- **HR Officer** — full access: register, staff, departments, reports, send.
- **CAO** — reports only, read-only, including all previously sent reports.

## 9. Technology Stack

| Layer | Choice | Rationale |
|---|---|---|
| Backend | PHP 8 (mysqli, prepared statements) | No framework overhead; runs on any shared host or XAMPP, matches the assignment's required stack |
| Database | MySQL / MariaDB | Required by the assignment; relational model fits the attendance/report structure well |
| Frontend | Tailwind CSS (CDN) + vanilla JS | Fast to build, no build pipeline, easy to hand off |
| Charts | Chart.js (CDN) | Lightweight, no extra backend dependency |
| Auth | PHP sessions + CSRF tokens + `password_hash()` | Standard, framework-free security baseline |

## 10. Security Considerations

- All database queries use **prepared statements** (mysqli `bind_param`) —
  no string-concatenated SQL.
- Every state-changing request (POST) is protected by a **CSRF token**
  bound to the session.
- Passwords are stored with PHP's `password_hash()` (bcrypt), never in
  plain text.
- Role checks (`require_role()`) gate every page and API endpoint — the
  CAO account cannot reach HR-only actions even if it guesses the URL.
- Default demo credentials **must** be changed before any real deployment
  (documented in the installation guide).

## 11. Limitations & Future Work

- The forecast uses a simple linear trend over available months; it is
  intentionally transparent rather than a deep model, since the district
  does not yet have years of digitised history to train on. As more months
  of real data accumulate, the same function will produce a better fit
  with no code changes.
- No biometric hardware is wired in yet — the data model supports it
  (`capture_method`), but the actual device integration is future work.
- The narrative generator is template-based; `generate_ai_narrative()` is
  documented as a swappable seam for a future LLM-backed version.
- Tailwind is loaded via CDN for this prototype; a production rollout
  should compile a static Tailwind bundle to remove the runtime dependency
  on the CDN.

## 12. Conclusion

The system directly answers the assignment brief: it replaces a hectic,
manual, month-end tallying exercise with a system that computes the same
statistics continuously, explains anomalies the moment they cross a
threshold instead of at month-end, and drafts the CAO's report
automatically — while keeping a human in the loop for the parts that
matter (HR reviews and explicitly sends every report). A worked example for
**June 2026** is included as seeded demonstration data; see
[`06-Test-Plan-and-Results.md`](06-Test-Plan-and-Results.md) for the
verification performed against a live MySQL instance.
