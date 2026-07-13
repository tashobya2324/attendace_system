# Test Plan & Results

Testing was performed against a **live MySQL instance** (not mocked), driving
the actual HTTP endpoints with authenticated sessions — end to end, the same
way a real user's browser would. This document records what was tested and
the outcome, including two defects that were found and fixed during
development.

## 1. Test Environment

- PHP 8.3, MySQL (XAMPP), Windows
- Database seeded via `database/seed_june2026.php`: 37 active staff across
  8 departments, attendance records for April–June 2026

## 2. Authentication & Access Control

| # | Case | Result |
|---|---|---|
| 1 | Login as `hr.officer` / `password123` | Pass — redirects to Dashboard |
| 2 | Login as `cao` / `password123` | Pass — redirects to Monthly Report |
| 3 | POST without a valid CSRF token | Pass — rejected with HTTP 419 |
| 4 | CAO account accessing HR-only pages | Pass — blocked by `require_role()` |

## 3. Daily Register

| # | Case | Result |
|---|---|---|
| 5 | Load ledger for a specific date (`api/ledger.php`) | Pass — correct counts and rows returned |
| 6 | Record a check-in for a staff member with no record | Pass — row created, status derived correctly |
| 7 | Record a check-out for the same staff member | Pass — same row updated, not a duplicate |
| 8 | Staff who checked in disappears from the Check-In list | Pass — verified via `api/staff_status.php` before/after |
| 9 | Staff who checked out disappears from the Check-Out list | Pass — verified via `api/staff_status.php` before/after |
| 10 | Delete an attendance record | Pass — row removed, ledger count decreases by one |

## 4. Staff & Department Management

| # | Case | Result |
|---|---|---|
| 11 | Add a department | Pass |
| 12 | Rename a department | Pass |
| 13 | Delete a department **with** staff assigned | Pass — correctly blocked with a clear error message |
| 14 | Delete an empty department | Pass |
| 15 | Add a staff member | Pass |
| 16 | Deactivate a staff member | Pass — status becomes `retired` |
| 17 | Reactivate a staff member | Pass — status returns to `active` |
| 18 | Permanently delete a staff member | Pass — record and cascaded attendance history removed |

## 5. Monthly Report & AI Layer

| # | Case | Result |
|---|---|---|
| 19 | Default report period (no query params) | Pass — resolves to the most recently completed month |
| 20 | Attendance/punctuality/absenteeism rates for June 2026 | Pass — 84.5% / 85.5% / 8.8% against 37 staff, 22 working days |
| 21 | Flagged-staff detection against policy thresholds | Pass — 14 of 37 staff correctly flagged (>3 late or >2 absent) |
| 22 | Forecast for next month (linear trend, 3 months of history) | Pass — produced a labelled projection with a "declining" trend badge |
| 23 | Auto-drafted narrative memo | Pass — correctly referenced the actual best/worst department and flagged-staff count |
| 24 | Send report to CAO | Pass — persisted to `monthly_reports`, visible immediately on the CAO's own login with a "Sent" banner and timestamp |

## 6. Defects Found & Fixed During Testing

| # | Defect | Root cause | Fix |
|---|---|---|---|
| D1 | `detect_flagged_staff()` threw a `mysqli_sql_exception` ("Reference not supported") | `HAVING` clause referenced column aliases (`late_count`) alongside an `OR`, which MySQL rejected | Rewrote `HAVING`/`ORDER BY` to repeat the full `SUM(...)` expressions instead of aliases |
| D2 | Seed script produced 0% attendance/punctuality — every status column came back blank | A `bind_param()` type-string mismatch (`i` where a string enum value was passed) silently coerced `'present'` etc. to an invalid enum value, which MySQL stored as `''` in non-strict mode | Corrected the type string; re-verified with a direct `GROUP BY status` query showing the expected four statuses |
| D3 | Two further `bind_param()` type-string mismatches in `api/record_entry.php` and `api/send_report.php` (narrative/insights bound as integer types) | Same class of bug as D2, caught by auditing every `bind_param` call against its query's column order after D2 was found | Corrected both type strings; re-tested the check-in and send-report flows end to end |
| D4 | Seed data had unrealistically high lateness (~44% punctuality) | The baseline arrival-time jitter window itself frequently exceeded the grace period, before the department-bias "late" roll was even applied | Tightened the baseline arrival window to stay inside the grace period, with only the bias-weighted roll producing genuine lateness — result: 85.5% punctuality, in line with the configured department bias values |
| D5 | "Daily pattern over the month" chart grew taller every time it redrew | `<canvas>` had no sized parent container while Chart.js was configured with `maintainAspectRatio: false` — the documented Chart.js unbounded-growth feedback loop | Wrapped the canvas in a `div` with a fixed `height: 220px` |

## 7. Manual UI Verification

Beyond the scripted HTTP tests above, the following were confirmed visually
by exercising the running application:

- Coat-of-arms emblem renders correctly on the login page and masthead, in
  both light and dark OS themes.
- Daily Register's Check In / Check Out tab switch and dropdown scoping
  behave correctly as records are added.
- Print/PDF view of the Monthly Report suppresses navigation and action
  buttons via the `@media print` rule, leaving a clean printable memo.

## 8. Known Gaps (not defects — documented scope limitations)

- No automated regression test suite yet (`phpunit` or similar) — all
  testing to date has been manual/scripted HTTP verification against a
  live database, run once per change rather than on every commit.
- No department-reassignment UI for existing staff (see
  [02-User-Manual.md](02-User-Manual.md), "Common Questions").
- No in-app password-change screen (see
  [05-Installation-Guide.md](05-Installation-Guide.md), step 5).
