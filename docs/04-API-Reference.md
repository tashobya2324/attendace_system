# API Reference

All endpoints live under `public/api/`, return **JSON**, and require an
active login session (`require_role()`), except where noted. State-changing
endpoints (`POST`) additionally require a valid **CSRF token**.

## Authentication & CSRF

Every HTML page that contains a form exposes a CSRF token, either as a
hidden `<input name="csrf">` field or as `window.CSRF_TOKEN` for
JavaScript. Every `POST` request must include it as a `csrf` field.

```
POST /public/index.php          # login (public, exempt from role check)
  csrf, username, password
```

A missing/invalid token returns HTTP 419 with `{"error": "Invalid or
expired session token. Please refresh the page."}`.

---

## `GET api/ledger.php`

Roles: `hr`, `admin`. Returns the full attendance ledger for one date.

**Query params:** `date` (`YYYY-MM-DD`, defaults to today)

**Response:**
```json
{
  "date": "2026-06-15",
  "total_staff": 37,
  "not_logged": 0,
  "counts": { "present": 26, "late": 7, "absent": 3, "leave": 1 },
  "rows": [
    {
      "id": 1994, "staff_id": 33, "full_name": "Denis Ninsiima",
      "staff_no": "MB-0033", "designation": "Driver",
      "dept": "Production & Marketing", "status": "present",
      "check_in": "07:50:00", "check_out": "17:16:00",
      "remarks": "", "capture_method": "manual", "hours": 9.4
    }
  ]
}
```

---

## `GET api/staff_status.php`

Roles: `hr`, `admin`. Returns which staff still need to check in / check
out for a given date — this is what powers the Daily Register's
disappearing-list behaviour.

**Query params:** `date` (`YYYY-MM-DD`, defaults to today)

**Response:**
```json
{
  "date": "2026-07-06",
  "need_checkin":  [ { "id": 17, "full_name": "…", "dept": "…" } ],
  "need_checkout": [ { "id": 6,  "full_name": "…", "dept": "…" } ]
}
```
- `need_checkin` — active staff with no `check_in` recorded for the date.
- `need_checkout` — active staff with a `check_in` but no `check_out` yet.

---

## `POST api/record_entry.php`

Roles: `hr`, `admin`. Records a check-in or check-out. Creates the row if
one doesn't exist for that staff/date, otherwise updates it — a staff
member has exactly one row per day (`UNIQUE (staff_id, attendance_date)`).

**Body params:**

| Param | Required | Notes |
|---|---|---|
| `csrf` | yes | |
| `staff_id` | yes | Must be an active staff member |
| `date` | yes | `YYYY-MM-DD` |
| `action` | yes | `in` or `out` |
| `time` | yes | `HH:MM` |
| `method` | no | `manual` \| `biometric` \| `supervisor`, default `manual` |
| `remarks` | no | Free text; only overwrites existing remarks if non-empty |

On `action=in`, status is derived automatically: **Late** if the arrival
time is more than `grace_minutes` past `day_start_time`, otherwise
**Present**.

**Response:** `{"ok": true}` or `{"error": "…"}` (400/404 on validation
failure).

---

## `POST api/delete_entry.php`

Roles: `hr`, `admin`. Permanently deletes one attendance record.

**Body params:** `csrf`, `id` (the `attendance_records.id`)

**Response:** `{"ok": true}`

---

## `POST api/send_report.php`

Roles: `hr`, `admin`. Computes the full monthly report (stats, department
breakdown, flagged staff, forecast, narrative) and persists it to
`monthly_reports` / `report_flags` with `status = 'sent'`. This is what
makes the report visible on the CAO's login. Uses
`INSERT … ON DUPLICATE KEY UPDATE`, so sending the same period twice
overwrites the previous snapshot rather than erroring.

**Body params:** `csrf`, `year`, `month`

**Response:** `{"ok": true, "report_id": 1}` or `{"error": "…"}`

---

## Page-embedded AI functions (not HTTP endpoints)

These live in [`includes/analysis.php`](../includes/analysis.php) and are
called directly by `dashboard.php` and `reports.php` server-side — they
are documented here because they are the system's core "AI" surface, not
because they are separately callable over HTTP.

| Function | Purpose |
|---|---|
| `compute_period_stats($year, $month)` | Attendance/punctuality/absenteeism rates and totals for a month |
| `compute_department_stats($year, $month)` | Per-department breakdown, sorted best → worst |
| `compute_daily_series($year, $month)` | Day-by-day present/late/absent counts, for the trend chart |
| `detect_flagged_staff($year, $month)` | Rule-based anomaly detection against `settings` thresholds |
| `forecast_next_month($year, $month, $lookback = 6)` | Linear-regression forecast of next month's rates, with a confidence label |
| `generate_ai_narrative(...)` | Template-driven NLG — produces the transmittal memo text |
| `generate_ai_insights(...)` | Short bullet list of anomaly/trend observations shown in the report UI |
