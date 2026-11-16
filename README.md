# Smart Library Management System (QR Code-Based)

A mobile-first web application for school libraries: books, students, QR-based borrowing and returning,
overdue / lost / damaged tracking, inventory verification, reports, audit logs, settings and backup/restore.

**Stack:** Vanilla PHP 8.2+ (PDO), MySQL/MariaDB, HTML5, CSS3, Bootstrap 5, Vanilla JavaScript, Bootstrap Icons.
**Libraries (CDN):** `qrcodejs` (QR generation) and `html5-qrcode` (camera scanning).

## Features
- Roles: **Administrator**, **Librarian**, **Student** (separate portal), enforced on the server for every page.
- Books with multiple physical copies, each with its own accession number and unique QR code; printable labels.
- Students with unique QR IDs, printable ID cards, activate/deactivate, optional portal login.
- QR borrowing (multi-book) and returning with automatic due dates, overdue detection and penalties.
- Overdue, lost and damaged modules; penalties (pay / waive with audit trail).
- Inventory verification by scanning (expected vs scanned vs missing vs unexpected).
- 13 reports with date ranges, search, print and CSV export; activity log; notifications; settings; backup/restore.

## Requirements
Windows/macOS/Linux with **XAMPP** (Apache + MySQL, PHP 8.2+). A modern browser (Chrome/Edge/Safari/Firefox).
Internet access is needed for the Bootstrap/QR libraries loaded from the jsDelivr CDN
(download them into `assets/vendor/` and update the `<script>`/`<link>` URLs for fully offline use).

## Installation (XAMPP)
1. Install XAMPP and start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy the `smart_library` folder to `C:\xampp\htdocs\` (so you have `C:\xampp\htdocs\smart_library\index.php`).
3. Open **http://localhost/phpmyadmin** → *Import* → choose `database/database.sql` → *Go*.
   This creates the `smart_library` database, all tables, default settings and categories.
4. Check `config/database.php` (defaults: host `127.0.0.1`, user `root`, empty password). Change them if yours differ.
5. Check `config/app.php`: `BASE_URL` must match the folder name (`/smart_library`). Keep `APP_DEBUG` = `false` in production.
6. Make sure `uploads/` and `storage/backups/` are writable by Apache (normal on XAMPP/Windows).

## First administrator (no default credentials)
1. Open **http://localhost/smart_library/install.php** and create the administrator (password: 10+ chars, letters and numbers).
2. Log in at **http://localhost/smart_library/**.
3. **Delete `install.php`** from the server immediately afterwards.

## Initial setup
1. *Settings*: school name, library name, borrowing limit, loan duration, penalty per day, optional overdue cap, logo.
2. *Categories*: add or adjust book categories.
3. *Users*: create librarian accounts. *Students*: register students (fill **Portal password** to give them a login; username = Student ID).
4. *Books → Add book*: enter details and number of copies; open the book and **Print labels**, then stick the labels on the books.

## QR codes
- Book copy QR = `BK-XXXXXXXXXX`; student QR = `STU-XXXXXXXXXX`. They are random identifiers, with no personal data.
- Print labels from a book's page (select copies) and ID cards from a student's profile. Print at 100% scale; keep QR codes at least ~2.5 cm.
- Regenerating a copy's QR (admin only) invalidates the old printed label.

## Camera permissions and HTTPS
Browsers allow the camera only on **HTTPS or `localhost`**.
- On the XAMPP PC use `http://localhost/smart_library/` — the camera works.
- From a phone using `http://192.168.x.x/...` the camera is **blocked**. Options:
  1. Enable HTTPS in XAMPP (`https://192.168.x.x/smart_library/`, self-signed certificate; accept the warning), or
  2. Deploy behind a real HTTPS domain, or
  3. For testing only: in Chrome open `chrome://flags/#unsafely-treat-insecure-origin-as-secure`, add your `http://192.168.x.x` address, enable and relaunch.
- Always allow the camera when prompted. If you denied it, reset it from the browser's site settings.
- **Manual entry** (QR code, accession number or student ID) is available on every scanner page as a fallback.

## Using the system
**Borrow:** *Borrow Book* → Start camera → scan the student QR (eligibility is shown) → scan each book → *Confirm borrowing*.
Rules enforced: active account, available copy, borrowing limit, no overdue books / unpaid penalties (switchable in Settings).
**Return:** *Return Book* → scan the book → review borrower, due date, overdue days and penalty → choose Good/Damaged → *Confirm return*.
Admins can adjust a penalty (reason required; recorded in the activity log).
**Penalties:** *Penalties* → mark as paid; admins can waive with a reason.
**Lost / damaged:** *Lost / Damaged* → report by QR/accession; update resolution status.
**Inventory:** *Inventory* → start a session (optionally by shelf/category) → scan books → *Complete*; mark missing copies as lost.
**Reports:** *Reports* → pick a report → set the date range → *Run* → *Print all* or *CSV*. (For PDF, choose "Save as PDF" in the print dialog.)
Reports show the generation date and the user who generated them.

## Backup and restoration
*Settings → Backup & Restore* (admin only):
- **Create backup** writes a `.sql` file to `storage/backups/` (blocked from direct web access). Download copies and keep them off the server.
- **Restore** validates the file, automatically saves a `*_pre_restore.sql` safety copy, then replaces all data. Type `RESTORE` to confirm.
- Recommended: back up before every restore, after each school day/week, and before upgrades. Test a restore on a spare copy periodically.
- You can also use phpMyAdmin → Export/Import as an alternative.

## Security notes
- Passwords use `password_hash()` / `password_verify()`; sessions are regenerated at login; cookies are HttpOnly + SameSite=Strict.
- CSRF tokens on every POST form and API call; all SQL uses PDO prepared statements; output is escaped.
- Login throttling: 5 failed attempts per username+IP → 15-minute lockout. Sessions expire after 1 hour idle.
- `config/`, `includes/`, `storage/`, `database/` are denied by `.htaccess`; PHP execution is disabled in `uploads/`.
- Delete `install.php` after setup; use a strong MySQL password and a dedicated DB user in production; serve over HTTPS.
- Audit logs are read-only and visible to administrators only. Transaction history is never deleted (records are archived).
- Library staff can't view admin-only pages; students can only see their own data.

## Troubleshooting
| Problem | Fix |
|---|---|
| HTTP 500 | Set `APP_DEBUG` to `true` in `config/app.php`, reload, read the message (or `xampp/apache/logs/error.log`). |
| "Database connection failed" | Start MySQL; check `config/database.php`; make sure `database.sql` was imported. |
| Menu item missing | The sidebar hides links whose page file does not exist; check the file is in the right folder. |
| Camera does not start | Use `localhost` or HTTPS, allow permission, close other apps using the camera, or use manual entry. |
| QR codes / icons not showing | The CDN needs internet access; or host the libraries locally in `assets/vendor/`. |
| Can't upload photo/logo | Max 2 MB, JPG/PNG/WEBP; check `uploads/` is writable and `upload_max_filesize` in `php.ini`. |
| Restore rejected | Only files created by this system's backup are accepted. |
| Logged out unexpectedly | Idle for 1 hour, account deactivated, or database was restored without your account. |

## Folder structure
```
smart_library/
├── config/            app.php, database.php
├── includes/          auth, functions, csrf, header/sidebar/navbar/footer, borrowing, incidents, inventory, reports, qr, pagination, scanner_card, student_portal, dashboard_basic (full dashboard)
├── admin/             dashboard, users/, categories/, logs/, reports/, settings/ (index, backup)
├── librarian/         dashboard, api, borrow, return, scanner, overdue, penalties, lost-books, inventory, inventory-session, books/, students/, reports/
├── student/           dashboard, profile, qr-card, borrowed-books, history
├── assets/            css/style.css, js/ (app, scanner, qr), images/, vendor/
├── uploads/           books/, students/, school/
├── storage/backups/   protected .sql backups
├── database/          database.sql
├── index.php  login.php  logout.php  install.php (delete after setup)  notifications.php
└── README.md
```