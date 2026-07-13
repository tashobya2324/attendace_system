# How the AI Actually Executes

This system contains **two separate AI subsystems**, deliberately kept apart
because they have very different execution characteristics — one runs
entirely on the server with no external calls, the other calls out to a
hosted vision model over the internet. This document traces exactly what
happens, step by step, each time either one runs.

| | Analytical AI (reports/flagging/forecast) | Vision AI (register backfill) |
|---|---|---|
| **Where it runs** | Locally, in PHP, on every Monthly Report page load | On demand, only when HR uploads a register photo |
| **External network call?** | No | Yes — one HTTPS call to the Gemini API per photo |
| **Latency** | Sub-second | A few seconds per photo |
| **Cost** | None | Gemini API usage (free tier) |
| **Can it write to the database on its own?** | Yes, but only when HR explicitly clicks "Send report to CAO" | **No — never.** It only ever writes to a staging table; a human must review and commit |
| **Code** | `includes/analysis.php` | `includes/gemini.php`, `includes/import_matching.php`, `public/api/import_*.php` |

---

## Part 1 — Analytical AI (statistics, flagging, forecasting, narrative)

This is the AI layer used every time someone opens **Monthly Report**
(`public/reports.php`) or the **Dashboard** (`public/dashboard.php`). It is
entirely deterministic PHP running against the local MySQL database — no
API key, no network call, no model inference. "AI" here means *the
technique*, not a hosted model: rule-based reasoning, statistical
forecasting, and template-driven language generation.

### Execution sequence, on every `reports.php` page load

```
1. compute_period_stats($year, $month)
     → one SQL query, GROUP BY status, for the selected month
     → returns attendance/punctuality/absenteeism rates + totals

2. compute_department_stats($year, $month)
     → one SQL query joining staff → departments → attendance_records
     → returns per-department rates, sorted best → worst

3. compute_daily_series($year, $month)
     → one SQL query, GROUP BY attendance_date
     → returns the day-by-day counts that feed the trend chart

4. detect_flagged_staff($year, $month)
     → one SQL query with a HAVING clause against the `settings` thresholds
     → returns every staff member exceeding late/absence limits, ranked
       by risk_score = late_count × 1.0 + absent_count × 2.0

5. forecast_next_month($year, $month)
     → calls compute_period_stats() again for each of the trailing
       months (up to 6) that actually have data
     → fits a linear regression (least-squares) over those points
     → returns a projected rate + a trend label (improving/stable/
       declining) + an honest confidence level (low/moderate) based on
       how many months of history were available

6. generate_ai_narrative(...)
     → takes the structured output of steps 1–5 as plain PHP arrays
     → assembles a handful of template sentences around that data
       (e.g. "X posted the strongest attendance performance at Y%...")
     → returns a single paragraph of prose — no model call, just string
       building from real numbers

7. generate_ai_insights(...)
     → same inputs, produces the short bullet list of anomaly/trend
       observations shown next to the forecast tiles
```

All seven steps run inline in the PHP request that renders the page —
there is no queue, no background job, and nothing here can fail due to a
third-party outage, because nothing here calls a third party.

### Where a human is still required

Steps 1–7 run automatically on every page view — but nothing is written
back to the database until HR clicks **Send report to CAO**
(`public/api/send_report.php`), which re-runs steps 1–5, persists the
result into `monthly_reports` and `report_flags`, and only *then* becomes
visible on the CAO's login. Viewing a report and sending a report are
different actions on purpose.

---

## Part 2 — Vision AI (photographed register → structured data)

This is the newer pipeline, used only when HR uploads a photo of a paper
register to backfill days that were never digitised. Unlike Part 1, this
genuinely calls a hosted multimodal model (Google Gemini) and its output
is treated as a **draft**, never a fact, until a human confirms it.

### Execution sequence, from upload to database

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. HR uploads a photo + the date it covers                       │
│    public/import.php  →  public/api/import_upload.php            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. Image is validated (JPEG/PNG, ≤10MB) and saved to               │
│    storage/attendance_imports/ — OUTSIDE the web root, only        │
│    reachable through an authenticated endpoint (import_image.php)  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. gemini_extract_register($path, $mime)  — includes/gemini.php   │
│    • base64-encodes the image                                     │
│    • POSTs to generativelanguage.googleapis.com                   │
│      model: gemini-flash-lite-latest                              │
│    • sends a strict prompt + a JSON response schema (see below)   │
│    • temperature = 0 (least creative/most literal setting)        │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. Gemini returns structured JSON:                                 │
│    { register_date, rows: [{ name, check_in, check_out,           │
│                               remarks, confidence }, ...] }        │
│    Any field the model isn't confident about comes back null —    │
│    the prompt explicitly forbids guessing (see prompt text below)  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. Each extracted name is scored against every active staff        │
│    member — match_staff_by_name()  — includes/import_matching.php │
│    • normalises both strings (lowercase, strip punctuation)       │
│    • similar_text() percentage + a word-order-independent token-   │
│      overlap bonus (so "Tumusiime Sarah" still matches "Sarah      │
│      Tumusiime")                                                   │
│    • ≥72% → pre-selected as a likely match                        │
│    • 45–72% → shown but NOT pre-selected ("uncertain")             │
│    • <45% → left blank, HR must pick manually                      │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ 6. Everything is staged — NOT written to attendance_records yet.   │
│    attendance_import_batches (1 row per photo)                     │
│    attendance_import_rows    (1 row per extracted name)            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ 7. HR reviews public/import_review.php side-by-side with the       │
│    original photo: every field is editable, every row has an       │
│    "Use" checkbox, unmatched/uncertain rows are visually flagged,  │
│    and rows that would overwrite an existing record are called out │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ 8. Only on explicit "Commit to register" does anything reach the   │
│    real table — public/api/import_commit.php writes each checked  │
│    row to attendance_records with capture_method = 'ocr_import',   │
│    tagged with which HR user approved it and when                 │
└─────────────────────────────────────────────────────────────────┘
```

### The exact prompt sent to the model

```
You are transcribing a single page of a handwritten or printed paper staff
attendance register for a Ugandan district local government office.

Read the page carefully and return every row you can find. For each row,
capture the staff member's name exactly as written (including apparent
spelling variants — do not "correct" or normalise it), and the check-in
time, check-out time, and any remarks column if present.

Rules:
- If a field is illegible, smudged, or simply blank, return null for that
  field. Never invent, guess, or estimate a value you are not confident
  about — a human will review and fill in anything you leave null.
- Normalise times you ARE confident about to 24-hour HH:MM format.
- If the page header shows a date for the register, return it as
  register_date in YYYY-MM-DD format if you can determine the year;
  otherwise null.
- Skip rows that are entirely blank (no name at all).
```

This is paired with a `responseSchema` (structured output constraint) so
Gemini can't return anything other than the shape the code expects — see
`GEMINI_RESPONSE_SCHEMA` in `includes/gemini.php`.

### What happens on failure

| Failure | What the user sees |
|---|---|
| Gemini API unreachable / times out | The batch is saved with `status = 'pending_review'` and an `error_message`; the review page shows "AI extraction failed: …" and HR can retry with a new upload |
| Gemini returns non-JSON / malformed output | Same as above — the raw response is stored in `ai_raw_response` for debugging, nothing is guessed |
| A row's name matches no one confidently | Row is staged with `matched_staff_id = NULL`; HR must pick manually or leave it unchecked (excluded) |
| HR uploads the wrong date's photo | Nothing is auto-corrected — HR edits every field before committing, same as any other row |

### Why the human-in-the-loop gate is not optional

This system produces an official government attendance record. A model
misreading "8:05" as "8:03," or matching "D. Tumusiime" to the wrong one
of three staff with that surname, has real consequences if it goes
straight into the register unchecked. The design principle carried over
from Part 1 — *the AI drafts, a person decides* — is why steps 6–8 exist
as a separate staging → review → commit sequence rather than a single
"upload and done" action. The confidence thresholds in step 5 exist to
make that review fast (obvious matches are pre-filled) without ever
skipping it entirely.

### Real-world verification

This pipeline was run against an actual photographed Mbarara DLG register
page (not a synthetic test) during development: 33 rows were extracted,
correctly parsed including check-in times and one explicit absence, and
matched with 100% confidence against several real staff names already in
the system (e.g. *Ahimbisibwe K. Nicholas*, *Siime Patience*, *Nuwagaba
Edgar*) while correctly leaving unmatched rows blank for names not yet in
the establishment register, rather than guessing.
