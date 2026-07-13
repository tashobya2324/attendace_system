# User Manual
## Mbarara District Local Government — Staff Attendance & Reporting System

---

## 1. Signing In

Open **`/public/index.php`** (e.g. `http://localhost/attendace_system_LDG/public/index.php`).
Enter your username and password and select **Sign in**.

| Role | Username | Default password | Can do |
|---|---|---|---|
| HR Officer | `hr.officer` | `password123` | Everything below |
| CAO | `cao` | `password123` | View the Monthly Report only (read-only) |

> **Change these passwords before real use.** They exist only to let you
> log in for the first time — see [05-Installation-Guide.md](05-Installation-Guide.md).

---

## 2. For the HR Officer

### 2.1 Dashboard
Your home page after login. Shows today's snapshot (present / late / absent
/ on leave / not yet logged) and month-to-date attendance and punctuality
rates, plus a quick department breakdown and this month's flagged staff.

### 2.2 Daily Register — recording attendance

1. Open **Daily Register** from the top navigation.
2. Use the date picker (top right) if you are not recording for today.
3. The panel has two tabs:
   - **Check In** — lists only staff who have **not yet** checked in for
     the selected date. Pick the staff member, confirm/adjust the time,
     choose a capture method, add a remark if they are late, and select
     **Record check-in**. They disappear from this list immediately.
   - **Check Out** — lists only staff who checked in but have **not yet**
     checked out. Select them, confirm the time, and select **Record
     check-out**. They disappear from this list once recorded.
4. The **Ledger** below shows every entry for the selected date, with
   filters by department and status. Use the **Delete** link on a row to
   remove a mis-entered record — this cannot be undone.

The system applies the attendance policy automatically: the official start
time is 08:00 with a 15-minute grace period (configurable in the
`settings` table) — anyone checking in after 08:15 is marked **Late**
rather than **Present**.

### 2.3 Staff — managing the establishment

Open **Staff** from the navigation.

- **Add staff member**: fill in staff number, full name, designation,
  department, and optional phone/email, then **Add staff member**.
- **Deactivate**: marks a staff member inactive (e.g. transferred out) —
  their attendance history is kept, and they no longer appear in the
  register or reports.
- **Reactivate**: brings a deactivated staff member back to active status.
- **Delete**: permanently removes the staff member **and all of their
  attendance history**. You will be asked to confirm — this cannot be
  undone. Use Deactivate instead if you might need the record later.

### 2.4 Departments

Open **Departments** from the navigation.

- **Add department**: type a name and select **Add department**.
- **Rename**: edit the name inline in the table and select **Save**.
- **Delete**: only succeeds if no active staff are currently assigned to
  that department — reassign or remove those staff first, then delete.

### 2.5 Monthly Report — preparing and sending to the CAO

Open **Monthly Report** from the navigation. It opens on the most recently
completed month by default; use the period selector to view any other
month.

The page shows, top to bottom:

1. **Summary tiles** — staff on establishment, attendance rate,
   punctuality rate, absenteeism, and working days, each compared against
   the district's target.
2. **Department attendance bars** and a **daily trend chart** for the
   month.
3. **AI-assisted insight** — the projected attendance/absenteeism rate for
   *next* month, a trend badge (improving / stable / declining), and a
   short list of anomaly observations.
4. **Department detail** and **flagged staff** tables.
5. **Transmittal memo** — the auto-drafted narrative addressed to the CAO.
   Read it over; this is the same text the CAO will see.
6. Actions: **Send report to CAO**, **Export CSV**, **Print / Save PDF**.

Selecting **Send report to CAO** saves this report (with its figures,
narrative, and flagged-staff list) so it becomes visible under the CAO's
own login. You can re-send a period later if the data changes — it
overwrites the previous snapshot for that month.

---

## 3. For the CAO

1. Sign in with the `cao` account.
2. You land directly on **Monthly Report** — this is the only page
   available to your role, and it is read-only (no send/edit controls).
3. Use the period selector to review any month HR has sent a report for.
4. A banner at the top of the page shows **when** the report was
   transmitted, so you always know whether you're looking at a live view
   or a report that was formally sent.
5. Review the flagged-staff table and the AI insight panel for anything
   needing your attention, then use **Print / Save PDF** if you need a
   hard copy for the file.

---

## 4. Common Questions

**A staff member's status looks wrong for today.**
Open Daily Register for that date, find them in the Ledger, and use
**Delete** on the incorrect row, then re-enter it correctly via the
Check In / Check Out panel.

**I need to correct an old month's data.**
Use the date picker on Daily Register to navigate to that date; the same
Check In/Out and Delete tools work for any date, not just today.

**A department has staff I need to move before deleting it.**
Edit each affected staff member's department via **Staff → Add staff
member** is only for new hires — for reassigning existing staff to a
different department, use phpMyAdmin or ask for a "reassign department"
feature to be added; it is not yet exposed in the UI.

**The report looks empty for the current month.**
That's expected mid-month — the report defaults to the most recently
*completed* month specifically so you're not looking at a half-finished
period. Use the period selector to check the current month's progress if
you need it.
