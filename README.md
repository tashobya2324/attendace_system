# Mbarara District Local Government — AI-Assisted Staff Attendance & Monthly Reporting System

Built for the assignment: *"The HR unit prepares a hectic, manual monthly staff
attendance analysis report to the CAO. Design and build an AI-enabled solution."*

This is a working prototype: PHP + MySQL backend, Tailwind CSS frontend, with an
explainable "AI" layer (anomaly flagging, trend forecasting, auto-drafted memo)
rather than a black box — deliberately, since a public-sector CAO needs to be able
to see *why* the system says what it says.

**Full documentation:** [`docs/`](docs/README.md) — project report, user manual,
database dictionary, API reference, installation guide, and test results.

## What it replaces

Today: HR manually collects paper sign-in/out sheets from every department,
tallies them by hand at month-end, and types a narrative report to the CAO.
This system:

- Digitises the daily sign-in/out register (08:00 start, 15-min grace period).
- Computes attendance/punctuality/absenteeism rates and department breakdowns automatically.
- **Flags** staff who cross policy thresholds for lateness/absence (rule-based anomaly detection).
- **Forecasts** next month's attendance/absenteeism using a linear trend over trailing months.
- **Auto-drafts** the narrative memo to the CAO (template-driven NLG), which HR can still review before sending.
- Lets HR click **"Send report to CAO"**, and the CAO logs in separately to view it — read-only, no manual retyping.

## Stack

- **PHP 8+** (mysqli, no framework — easy to deploy on any shared host or XAMPP)
- **MySQL / MariaDB**
- **Tailwind CSS** (via CDN for this prototype — swap for a compiled build in production)
- **Chart.js** (CDN) for the monthly trend chart

## Project layout

```
config/database.php       Database connection (reads MBR_DB_* env vars, falls back to XAMPP defaults)
database/schema.sql       Full schema + departments/users seed
database/seed_june2026.php Generates a realistic staff establishment + Apr-Jun 2026 attendance history
includes/auth.php         Session auth, login, CSRF helpers
includes/analysis.php     The "AI" layer: stats, flagging, forecasting, narrative generation
includes/header.php / footer.php   Shared Tailwind layout/nav
public/index.php          Login
public/dashboard.php      HR home: today's snapshot + month-to-date
public/register.php       Daily sign-in/out register (AJAX-driven)
public/staff.php          Staff establishment management
public/reports.php        Monthly report — CAO view, AI insights, send-to-CAO
public/api/*.php          JSON endpoints used by the register and report pages
```

## Setup (XAMPP / local MySQL)

1. **Import the schema:**
   ```
   mysql -u root < database/schema.sql
   ```
   This creates the `mbarara_attendance` database, tables, departments, and two
   login accounts.

2. **Seed realistic June 2026 data** (also generates April–May 2026 so the
   forecasting engine has history to learn from):
   ```
   php database/seed_june2026.php
   ```
   Re-runnable any time — it wipes and rebuilds staff/attendance/report data
   (not your `users`/`settings`).

3. **Configure the database connection**, if not using XAMPP defaults
   (`127.0.0.1`, user `root`, no password), via environment variables:
   `MBR_DB_HOST`, `MBR_DB_NAME`, `MBR_DB_USER`, `MBR_DB_PASS`, `MBR_DB_PORT`.

4. **Run it:**
   - Under XAMPP: copy/symlink this folder so `public/` is served by Apache
     (e.g. `htdocs/mbarara-attendance` → point your vhost's document root at
     `public/`), or
   - Standalone for a quick demo:
     ```
     php -S 127.0.0.1:8000 -t public
     ```
     then open http://127.0.0.1:8000

5. **Log in:**
   - HR officer: `hr.officer` / `password123` — daily register, staff management, sends reports.
   - CAO: `cao` / `password123` — read-only monthly report view.
   - **Change both passwords** before any real deployment (`password_hash()` in `includes/auth.php`).

## Entering real attendance data going forward

The June 2026 dataset is realistic *sample* data (the assignment didn't come
with a digitised register to import). From here on, HR enters actual daily
sign-ins/outs through **Daily Register** — each entry is a real MySQL row from
that point forward, and the monthly report, forecast, and flags recompute live
from whatever is in the database. There's no seed/live data distinction in the
schema: seeded rows are just historical rows you can edit or delete like any other.

To bulk-import a real register (e.g. from an existing spreadsheet), the
cleanest path is a one-off script modeled on `database/seed_june2026.php` that
reads your CSV and inserts into `attendance_records` — ask for one and it can
be generated once you have the export format.

## Where the "AI" actually is (`includes/analysis.php`)

1. **Rule-based anomaly detection** — `detect_flagged_staff()`: staff exceeding
   configurable late/absence thresholds (`settings` table), auditable by the CAO.
2. **Statistical forecasting** — `forecast_next_month()`: linear regression
   over the trailing months' attendance/absenteeism rates, with a moving-average
   fallback and an honest confidence label.
3. **Template-driven NLG** — `generate_ai_narrative()`: turns the numbers into
   the same kind of prose HR currently types by hand.

`generate_ai_narrative()` is written as a swappable seam — if you want to route
it through a real LLM for richer prose (e.g. the Claude API), that's the one
function to change; everything else (stats, flags, forecast) stays as
structured data either way.

## Security notes for a real deployment

- Change default passwords immediately; the seed hash is for demo only.
- Serve over HTTPS; set `session.cookie_secure` once you do.
- The `config/database.php` credentials should come from environment
  variables in production, not hardcoded.
Access it here: http://localhost/attendace_system_LDG/public/index.php
