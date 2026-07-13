# Installation & Deployment Guide

## 1. Requirements

- PHP 8.0+ with the `mysqli` extension enabled
- MySQL 5.7+ or MariaDB 10.3+
- A web server (Apache via XAMPP, or PHP's built-in server for a quick demo)

## 2. Get the code onto a server

**Option A — XAMPP (used for this project's own deployment):**

1. Copy the whole `attendace_system_LDG` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** from the XAMPP Control Panel.

**Option B — quick standalone demo (no Apache needed):**

```
php -S 127.0.0.1:8000 -t public
```
then open `http://127.0.0.1:8000`.

In both cases, the **document root must point at the `public/` folder** —
that's the only folder meant to be web-accessible. `config/`, `includes/`,
and `database/` should never be served directly.

## 3. Create and seed the database

From the project root:

```
mysql -u root < database/schema.sql
```

This creates the `mbarara_attendance` database, all six tables, the eight
seed departments, the two default logins, and the default policy settings.

Then seed a realistic three-month attendance history (April–June 2026) so
the AI forecast has data to work from:

```
php database/seed_june2026.php
```

Safe to re-run — it wipes and rebuilds `staff` / `attendance_records` /
`monthly_reports` (not `users` or `settings`).

**To fill in the current, still-open month** without disturbing any real
entries already made through the Daily Register:

```
php database/seed_fill_month.php <year> <month>
```
e.g. `php database/seed_fill_month.php 2026 7`. This only inserts rows for
staff/day combinations that don't already exist — real entries are never
overwritten.

## 4. Configure the database connection

By default the app connects to `127.0.0.1:3306`, database
`mbarara_attendance`, user `root`, no password — the standard XAMPP
defaults. To override, set environment variables before starting PHP:

| Variable | Default |
|---|---|
| `MBR_DB_HOST` | `127.0.0.1` |
| `MBR_DB_NAME` | `mbarara_attendance` |
| `MBR_DB_USER` | `root` |
| `MBR_DB_PASS` | *(empty)* |
| `MBR_DB_PORT` | `3306` |

See [`config/database.php`](../config/database.php).

## 5. First login — and what to change immediately

| Role | Username | Default password |
|---|---|---|
| HR Officer | `hr.officer` | `password123` |
| CAO | `cao` | `password123` |

Before any real deployment:

1. **Change both passwords.** There is no in-app "change password" screen
   yet — update `password_hash` directly, e.g.:
   ```
   php -r "echo password_hash('YourNewPassword', PASSWORD_DEFAULT), PHP_EOL;"
   ```
   then `UPDATE users SET password_hash = '<hash>' WHERE username = '...';`
2. **Serve over HTTPS** and set `session.cookie_secure = 1` in `php.ini`
   once you do.
3. Move `config/database.php` credentials to environment variables (they
   already read from env vars — just make sure production sets them
   rather than relying on the XAMPP-friendly defaults).
4. Review `settings` (attendance policy) for the real district policy if
   it differs from the defaults documented in
   [03-Database-Dictionary.md](03-Database-Dictionary.md).

## 6. Bulk-importing a real attendance register

If HR has an existing spreadsheet of attendance to import, the cleanest
path is a one-off PHP script modeled on `database/seed_june2026.php` that
reads the CSV/Excel export and inserts into `attendance_records`. Ask for
one to be written once you have the export format — the schema
(`staff_id`, `attendance_date`, `check_in`, `check_out`, `status`, …)
is documented in full in [03-Database-Dictionary.md](03-Database-Dictionary.md).

## 7. Keeping a second copy in sync

If you maintain the source in one folder and a deployed copy in
`htdocs` (as this project currently does), remember that edits must be
copied into the `htdocs` copy — or better, replace the `htdocs` copy with
a symlink to the source folder so there is only one place to edit:

```
# from an elevated PowerShell prompt
New-Item -ItemType SymbolicLink -Path "C:\xampp\htdocs\attendace_system_LDG" -Target "C:\Users\Administrator\Desktop\District Local Government\attendace_system_LDG"

///////////////////////////////////////

Direct file: http://localhost/attendace_system_LDG/public/index.php
```
