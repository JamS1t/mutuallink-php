# MutualLink

Integrated Loan, Balance, and Savings Management System for the **Franciscan Friends Multi-Purpose Cooperative (FFMPC)**, Baybay City, Leyte.

Stack (as in the paper, Ch. III): **WampServer** (Apache 2.4, PHP 8.2, MySQL 8.x) · HTML5/CSS3/JavaScript · **AdminLTE 3.2.0 / Bootstrap 4.6.1** · PDO. No frameworks, no build step, works offline.

> Environment note (Oct 2026): installed as **WampServer 3.4.0** — Apache 2.4.65, **PHP 8.2.29 (active)**, **MySQL 8.4.7**, MariaDB 11.4.9 also bundled. Meets the Ch. III requirement of WampServer 3.3.5-class components (Apache 2.4 / PHP 8.2 / MySQL 8).

> Paper note: Chapter III lists "Bootstrap 5.3.8 / AdminLTE 4.9.1". The system (and the class example) uses **AdminLTE 3.2.0 on Bootstrap 4.6.1** — update Chapter III to match.

---

## 1. Install on WampServer

1. Copy this folder to `C:\wamp64\www\mutuallink`.
2. **Create the credentials file**: copy `config/secrets.example.php` to `config/secrets.php`. It holds `DB_USER` / `DB_PASS` and is gitignored — never commit it.
3. Start WampServer (tray icon green).
4. Open **phpMyAdmin** (`http://localhost/phpmyadmin`) as `root` → **Import** → choose `database/mutuallink.sql` → Go.
   This creates the `mutuallink` database, all tables, seed accounts, and the least-privilege app account `mutuallink_app`.
5. **Change the app database password** (required — the password shipped in `mutuallink.sql`, like every password in git history, must be treated as compromised): in phpMyAdmin run
   `ALTER USER 'mutuallink_app'@'localhost' IDENTIFIED BY 'your-new-password';`
   then put the same password in `config/secrets.php` (`DB_PASS`).
6. Open `http://localhost/mutuallink` and sign in.

**Upgrading an existing database**: after pulling changes, apply the patches once, in order (phpMyAdmin → Import, or
`mysql -u root -p mutuallink < database\patch_ffmpc_answers.sql`):
- `database/patch_ffmpc_answers.sql` — the co-maker member link, the savings-interest transaction type, and the new settings;
- `database/patch_integrity.sql` — the one-running-loan-per-product rule as a database index (A12, friendly error on release), structured savings-interest periods (`savings_transactions.interest_period` + unique key, existing remarks migrated), the SMTP reminder settings, `notifications.send_error` for failed sends, and the `login_attempts` table for the sign-in throttle.

A fresh `mutuallink.sql` import already has everything.

Requirements already enabled in WampServer: `pdo_mysql`, `mbstring`. Apache `AllowOverride All` (default) lets `.htaccess` block the internal folders.

### Default accounts (password `Password123` — change after first login)

| Username | Role | What they do |
|---|---|---|
| `manager` | Manager | Approves/rejects and releases loans, manages users, products, settings |
| `cashier` | Cashier | Registers members, posts deposits/withdrawals and loan payments, issues receipts |
| `loanofficer` | Loan Officer | Encodes loan applications, checks eligibility, runs delinquency list and reminders |
| `bookkeeper` | Bookkeeper | Reverses savings entries, voids payments (with reason), reviews reports |
| `auditor` | Board / Auditor | Read-only access to records, reports, and the audit log |

## 2. Modules (RAD versions → CRUD)

| Version | Module | Create | Read | Update | Delete (soft) |
|---|---|---|---|---|---|
| V1 | User accounts | Add user | List | Role, status, reset password | Deactivate |
| V1 | Members | Register applicant (PMES, TIN, signature specimen; auto-opens share capital + regular savings) | List, single member record + eligibility | Edit profile · membership fee (Cashier, OR) · approve membership (Manager) | Deactivate |
| V1 | Savings & share capital | Open account, post deposit/withdrawal (OR issued) | Passbook with running balance | Reopen | Reversal entry, close at ₱0 |
| V2 | Loan products | Add | List | Edit limits/rates/loanable basis | Deactivate |
| V2 | Loans | Application (collateral + appraisal, net pay, mode of payment) | List, loan record, printable schedule | Approve/reject, release in cash or check (schedule + deductions) | Cancel pending |
| V2 | Payments | Post payment → one official receipt (may cover several installments) | History | — | Void latest receipt (restores balances) |
| V3 | Delinquency | Generate aging snapshot · demand letter | List by bracket | — | — |
| V3 | Reminders | Generate upcoming/overdue | List | Send by email (scheduled task) or mark sent/failed | Remove unsent |
| V3 | Reports / audit / settings | — | Daily collection, loan status, aging, savings, share capital, salary deduction list, audit log | Settings (Manager) | — |

## 3. FFMPC rules (from the requirements questionnaire + the answered clarification sheet)

**Loans (`lib/loan_calc.php`, `lib/loan_service.php`)**
- **Interest:** 3% per month on the diminishing balance, **equal principal** each month — checked against FFMPC's own sample: ₱26,000 / 9 months → principal 2,888.85, interest 780.00, 693.30, 606.65 … 86.65, total interest ₱3,899.85.
- **Rounding:** every monthly amount is rounded **down to ₱0.05**, like FFMPC's Excel sheet (clarification A1).
- **Semi-monthly amount** (salary deduction on the 15th and 30th) is shown beside each monthly amount, as in FFMPC's computation sheet.
- **After the term (past due):** once the loan term is surpassed, the **unpaid principal + unpaid interest** is charged **3% interest + 4% penalty per month, computed per day** (the monthly rate spread over 30 days) — clarifications A2 and A3. No penalty while the term is running.
- **Payment order:** penalty → interest → principal, oldest installment first. Paying more than one installment settles the next ones in advance; paying everything closes the loan early. **No rebate** on advance or early payment.
- **Deductions at release** (FFMPC's "Summary of loan computation"): loan insurance 0.56%, service fee 3%, stockshare 2% (credited to share capital), notarial fee ₱200, others/printing ₱30, and on renewal the previous loan's **remaining principal + interest due** (clarification A7). Interest is not deducted in advance. ₱26,000 → deductions ₱1,675.60, net ₱24,324.40.
- **Loanable amount:** Regular Loan up to 30% of the collateral's appraised value (₱3,000,000 title → ₱900,000), up to 1 year; Salary Loan only up to what one month's salary can pay — the **monthly amortization (principal + interest) must not exceed the monthly net pay** (A8); Emergency Loan up to ₱3,000, 3 months.
- **Co-maker:** must be an **active FFMPC member**, picked from the member list (A11).
- **One loan per product:** a member may hold at most one loan of each product at a time (A12).
- **Salary deduction due dates:** loans paid by salary deduction fall due on the **payroll dates** — the 30th of each month, clamped to the month's last day (A14); the deduction list splits each month into the 15th and 30th halves.
- **Eligibility:** good payment record, a co-maker, and collateral for members from outside the school; the member must be an approved (active) member.
- **Release:** cash or check, by the Manager.

**Members and savings**
- Membership: pre-membership seminar (PMES) → application form with TIN and signature specimen → membership fee + initial share capital → Manager's approval (about one month). Applicants cannot borrow. The membership fee is **fixed at ₱250** (A15, editable in Settings).
- Minimums: share capital ₱2,000, regular savings ₱500 maintaining balance, time deposit ₱10,000, CBU ₱100 per month.
- **Interest:** regular savings earn **1% per quarter**; a time deposit earns **1% per term** — posted by the Bookkeeper in **Savings Interest** (A17, rate editable in Settings).
- Withdrawals need the passbook (a lost passbook requires a notarized affidavit of loss); deposits do not. **Share capital** is returned to a resigning member only with a **BOD resolution** recorded on the withdrawal (A18).

**Receipts:** plain numbered OR series like the receipt booklet (e.g. 025952). The Manager sets the last number issued in **Settings**; it can only move forward.

**Aging brackets:** 1–30, 31–60, 61–90, 91–180, 181–365, over 365 days (editable).

All rates and minimums are editable in **Settings**. FFMPC's Excel rounds each monthly total **down to ₱0.05** (e.g. 3,668.89 → 3,668.85); MutualLink keeps exact centavos so the balance reaches exactly zero.

## 4. Tests

```
php tests/loan_calc_test.php     # pure computation checks (no database)
php tests/loan_flow_test.php     # release → pay → void → renewal against the DATABASE
```
Run `loan_flow_test.php` only on a **freshly imported, disposable** database: it inserts and removes its own test records but advances the OR and member counters. Both tests read the database credentials from `config/secrets.php` (README §1, step 2).

## 5. Backup

Edit the MySQL path in `database/backup.bat`, then double-click it (or schedule it in Task Scheduler). Restore with
`mysql -u root -p mutuallink < database\backups\mutuallink_YYYYMMDD_HHMM.sql`.

## 6. Email reminders every 10–15 minutes (Windows Task Scheduler)

Pending reminders whose member has an email address are sent automatically by `cron_send_reminders.php` (CLI only). One run sends **all** due reminders, tolerates failures (the SMTP error is kept on the reminder row, visible in Reminders), and writes one audit entry with the counts. Reminders stay queued when SMTP is not configured.

**One-time setup**

1. As the Manager, open **Reminders → Email settings** and fill in the SMTP account:
   - Host `smtp.gmail.com`, port `587` (STARTTLS) — or `465` (SSL);
   - Username and From address: the cooperative's Gmail address;
   - Password: a Gmail **App password**, never the sign-in password — Google Account → Security → turn on 2-step verification → App passwords → create one (16 letters).
2. Test once by hand, in a command prompt:
   `"C:\wamp64\bin\php\php8.2.29\php.exe" "C:\wamp64\www\mutuallink\cron_send_reminders.php"`
   It prints how many were sent and how many failed.

**Schedule it (every 10 minutes)**

1. Start menu → **Task Scheduler** → **Create Task…**
2. **General** tab: name it `MutualLink email reminders`; choose *Run only when user is logged on* (simplest on a dedicated office PC).
3. **Triggers** → New: *Daily*, start today; tick *Repeat task every* → `10 minutes` → *for a duration of* `Indefinitely`; *Enabled* ✓.
4. **Actions** → New:
   - Program/script: `"C:\wamp64\bin\php\php8.2.29\php.exe"` (the WampServer PHP 8.2 path);
   - Add arguments: `"C:\wamp64\www\mutuallink\cron_send_reminders.php"`;
   - Start in: `C:\wamp64\www\mutuallink`.
5. **Settings** tab: tick *Run task as soon as possible after a scheduled start is missed*; *Stop the task if it runs longer than* `1 hour`. OK — Windows asks for the user's password once.
6. Right-click the task → **Run** and check the Reminders list: sent reminders turn green with a timestamp, failures carry the reason in red.

## 7. Defense notes — where each course topic lives

| Topic | Where |
|---|---|
| PDO DSN, utf8mb4, attributes array, try/catch, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false` | `config/db.php` |
| Named parameters, prepared statements, `lastInsertId()`, `rowCount()`, `fetch` vs `fetchAll` | every view, e.g. `views/member_form.php`, `views/users.php` |
| Transactions (`beginTransaction/commit/rollBack`) + row locks (`FOR UPDATE`) | `lib/loan_service.php`, `config/helpers.php` (`savings_entry`, `next_counter`) |
| `password_hash` (bcrypt, cost 12), `password_verify`, `password_needs_rehash` | `index.php`, `views/user_form.php`, `views/profile.php` |
| Session hardening: HttpOnly, SameSite=Strict, Secure on HTTPS, strict mode, `session_regenerate_id(true)`, UA binding, 15-min idle timeout, full logout | `config/session.php`, `logout.php` |
| AuthN vs AuthZ, RBAC matrix, route guard middleware functions | `config/rbac.php` (`require_login`, `require_permission`, `require_csrf`), `dashboard.php` |
| Anti-CSRF tokens (`random_bytes`, `hash_equals`) | `config/session.php`; every form uses `csrf_field()`; fetch sends `X-CSRF-Token` |
| Validation vs sanitization, `filter_var`, `preg_match`, error accumulator, `strict_types` | `config/helpers.php` (`req`, `money_in`, `pattern_in`, …) |
| Output escaping `htmlspecialchars(ENT_QUOTES, 'UTF-8')` | `e()` in `config/helpers.php`, used on all output |
| DOM XSS: `textContent` not `innerHTML`; no inline scripts | `dist/js/mutuallink.js` |
| Content Security Policy and security headers | `start_secure_session()` in `config/session.php` |
| REST-style JSON endpoints: methods, status codes (200/400/401/403/405/422), `php://input`, `json_last_error`, envelope | `api/_bootstrap.php`, `api/*.php` |
| Fetch API, async/await, `response.ok`, `JSON.stringify`, loading states, toasts | `dist/js/mutuallink.js` |
| Indexes (FKs, composite, unique), prefix `LIKE 'term%'`, JOINs instead of N+1 | `database/mutuallink.sql`, `api/member_lookup.php`, `overdue_loans()` |
| Least privilege DB account (`GRANT SELECT, INSERT, UPDATE, DELETE`) | end of `database/mutuallink.sql`; credentials out of version control: `config/secrets.php` (gitignored) required by `config/db.php` |
| Brute-force defence (DB-backed lockout: 5 failures per identifier+IP → 15 min, audited; survives clearing cookies) | `config/rbac.php` (`login_lock_seconds`, `login_register_failure`), `index.php`, table `login_attempts` |
| Email over SMTP (PHPMailer vendored in `plugins/phpmailer`, no composer; app passwords; scheduled sending) | `config/mail.php`, `cron_send_reminders.php`, Reminders → Email settings |
| Data integrity in the schema: one running loan per product (A12) as a generated-column unique index; interest period uniqueness; reconciliation views | `database/patch_integrity.sql`, `views/reconcile.php`, `views/eod_close.php` |
| Audit trail | `audit_log()` in `config/helpers.php`; viewer `views/audit.php` |
| Backup (mysqldump) | `database/backup.bat` |

Separation of duties built in: the encoder of a loan cannot approve it; the Cashier posts and the Bookkeeper voids; the Manager does not post money; the last active Manager cannot be deactivated; financial records are never deleted (reversals and voids keep the original on record).
