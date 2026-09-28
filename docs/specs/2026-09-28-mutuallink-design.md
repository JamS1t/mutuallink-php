# MutualLink — CRUD System Design Spec

**Date:** 2026-09-28
**Source of requirements:** *MutualLink: Integrated Loan, Balance, and Savings Management System for the Franciscan Friends Multipurpose Cooperative* (Revision 21), figures 1–11, professor's code snippets and course topics (`context.nd`).
**Status:** Approved in conversation — pending review of this written spec.

---

## 1. Goal

A fully functional, secure CRUD web system for FFMC's lending and savings operations, built strictly on the stack in the paper and the patterns taught in class. No frameworks, no build tools, no React/TypeScript.

**Success criteria**
1. All three RAD versions (paper Ch. IV) work end-to-end as CRUD over the Fig 4 tables.
2. Loan figures match the agreed computation rules (§6) and are covered by an assert-based test.
3. Every course security topic listed in §5 is visibly applied and explainable at defense.
4. Runs unchanged on WampServer 3.3.5 (Apache 2.4, PHP 8.2.12, MySQL 8.3), offline.
5. UI is a polished, consistent AdminLTE 3.2 design (own layout, not copied from samples).

**Out of scope (from the paper's Scope & Limitation):** grocery/inventory, accounting (trial balance, FS, tax), dividends, CDA PESOS rating, deciding loans (system only records decisions), bank/payment gateway, member self-service portal, mobile app, file uploads, actual email sending (see §4.11).

## 2. Stack & runtime

| Layer | Choice |
|---|---|
| Server stack | WampServer 3.3.5 — Apache 2.4.65, PHP 8.2.12, MySQL 8.3.0 |
| UI | AdminLTE **3.2.0**, Bootstrap **4.6.1**, jQuery 3.6, Font Awesome, DataTables (bs4 + buttons), Toastr, Bootstrap modals |
| Assets | Local `plugins/` and `dist/` only (offline; CSP `'self'`). No CDN, no Google Fonts. |
| DB access | **PDO** (per professor's snippet), named parameters |
| JS | jQuery for AdminLTE/DataTables; `fetch()` + `async/await` for the small JSON endpoints |

> Note for the paper: Ch. III lists "Bootstrap 5.3.8 / AdminLTE 4.9.1". Neither matches the class example; the system uses **AdminLTE 3.2.0 / Bootstrap 4.6.1**. Update Ch. III accordingly.

**Portability rules (XAMPP's php.exe used only as a test runtime on the dev PC; nothing placed in htdocs):**
- Relative URLs only; no absolute filesystem paths outside `__DIR__`.
- InnoDB engine (FKs + transactions), charset `utf8mb4`, collation `utf8mb4_unicode_ci` (valid on MySQL 8 and MariaDB).
- No vendor-specific SQL (no `utf8mb4_0900_*`, no JSON columns, no MariaDB-only syntax).
- Deploy = copy folder to `C:\wamp64\www\mutuallink`, import `database/mutuallink.sql`, open `http://localhost/mutuallink`.

## 3. Architecture

Four layers per Fig 1: presentation (browser) → security (guards) → application (PHP modules) → data (MySQL). Server-rendered pages following the professor's pattern (same-page POST handling, `dashboard.php?page=` router, Toastr feedback) plus a few JSON endpoints for AJAX.

### 3.1 Folder structure

```
mutuallink/
├─ index.php                 Login (same-page POST)
├─ logout.php                POST-only logout (CSRF-checked)
├─ dashboard.php             Router: guards → views/<page>.php
├─ .htaccess                 Deny config/, lib/, database/, tests/, docs/; no directory listing
├─ config/
│  ├─ db.php                 PDO connection (DSN utf8mb4, attributes array, try/catch)
│  ├─ session.php            Hardened session, fingerprint, idle timeout, CSRF, logout
│  ├─ rbac.php               RBAC matrix + can()/require_login()/require_permission()
│  └─ helpers.php            e(), validators, flash, audit_log(), next_or_no(), money()
├─ lib/
│  └─ loan_calc.php          Pure functions: schedule, penalty, payment split, aging
├─ include/                  head, navbar, sidebar (role-filtered), footer, scripts
├─ views/                    One file per page (POST block on top, HTML below)
├─ api/                      JSON endpoints (check_existence, amortization_preview, member_lookup)
├─ database/
│  ├─ mutuallink.sql         Schema + indexes + seed + least-privilege user
│  └─ backup.bat             mysqldump backup script
├─ tests/loan_calc_test.php  Assert-based computation checks (CLI)
├─ plugins/, dist/           AdminLTE 3.2.0 assets
└─ README.md                 Install, default accounts, defense notes
```

### 3.2 Request flow

```
Browser → dashboard.php?page=X
   → require_login()          (AuthN: session valid, fingerprint matches, not idle > 15 min)
   → require_permission(X)    (AuthZ: RBAC matrix; fail → views/403.php)
   → POST? verify_csrf()      (hash_equals on session token)
   → views/X.php:
        validate (error accumulator) → PDO prepared stmt(s)
        [beginTransaction … commit / rollBack when >1 table changes]
        → audit_log() → set_flash() → redirect (PRG)
   → render HTML, every dynamic value through e()
```

The three guard calls are the **route-guard middleware functions** (course topic wording). They run in one place before any view, so no page is reachable unguarded.

### 3.3 JSON endpoints (`api/`)

- Same guards (login, permission, CSRF via `X-CSRF-Token` header).
- `Content-Type: application/json`; body parsed from `php://input` with `json_decode` + `json_last_error()` → 400 on malformed JSON.
- Envelope: `{ "success": bool, "data": {...}|null, "errors": [..] }`.
- Status codes: 200, 400, 401, 403, 405 (wrong method), 422 (validation), 500.
- Endpoints: `check_existence` (username/email/member_no), `amortization_preview` (live schedule while encoding a loan), `member_lookup` (search box for posting forms).
- Client renders with `textContent`/`.text()` only.

## 4. Data model

Tables and columns from Fig 4 / Fig 11, plus only the columns CRUD needs (marked **+**). All money `DECIMAL(12,2)`, rates `DECIMAL(5,2)` (percent). Every FK indexed.

### 4.1 `users`
`user_id` PK, `username` UNIQUE, **+`email` UNIQUE** (login by username or email, as in professor's snippet), `password_hash`, `full_name`, `role` ENUM(`manager`,`cashier`,`loan_officer`,`bookkeeper`,`auditor`), `status` ENUM(`active`,`inactive`), **+`last_login`**, **+`created_by`, `created_at`, `updated_by`, `updated_at`**.

### 4.2 `members`
`member_id` PK, `member_no` UNIQUE (auto `FFMC-YYYY-NNNN`), `last_name`, `first_name`, **+`middle_name`**, `birthdate`, `civil_status` ENUM, `address`, `contact_no`, `email`, `occupation`, `monthly_income`, `tin`, `sss`, `beneficiaries` TEXT (multi-valued attribute; one per line), `spouse_name`, `date_of_membership`, `member_type` ENUM(`school`,`outside`) (outside members need collateral), `status` ENUM(`active`,`inactive`), **+audit columns**.
Index: `(last_name, first_name)`.

### 4.3 `savings_accounts`
`savings_id` PK, `member_id` FK, `account_type` ENUM(`regular_savings`,`share_capital`,`capital_build_up`,`time_deposit`), `balance`, `date_opened`, `status` ENUM(`active`,`closed`). **UNIQUE(`member_id`,`account_type`)**, one account per type.

### 4.4 `savings_transactions`
`txn_id` PK, `savings_id` FK, `txn_date`, `txn_type` ENUM(`deposit`,`withdrawal`,`reversal`), `amount`, `running_balance`, `or_no`, `posted_by` FK users, **+`reverses_txn_id`** (self-FK, nullable), **+`remarks`**, **+`created_at`**.

### 4.5 `loan_products`
`product_id` PK, `product_name` UNIQUE, `min_amount`, `max_amount`, `term_months` (maximum), `interest_rate` (3.00), `penalty_rate` (4.00), **+`status`**. Seed: Regular, Salary, Emergency.

### 4.6 `loans`
`loan_id` PK, `member_id` FK, `product_id` FK, `principal`, `term_months`, `date_applied`, **+`date_approved`**, `date_released`, `co_maker`, `collateral`, `outstanding_balance` (remaining principal), `status` ENUM(`pending`,`approved`,`rejected`,`released`,`paid`,`cancelled`), `approved_by` FK, **+`created_by` FK, `released_by` FK, `total_interest`, `net_proceeds`, `remarks`, `created_at`, `updated_at`**.
Index: `(member_id, status)`, `(status)`.

### 4.7 `amortization_schedule`
`schedule_id` PK, `loan_id` FK, `installment_no`, `due_date`, `principal_due`, `interest_due`, `total_due`, `balance` (principal remaining after this installment), `status` ENUM(`unpaid`,`partial`,`paid`), **+`principal_paid`, `interest_paid`, `penalty_paid`**.
**UNIQUE(`loan_id`,`installment_no`)**, composite index `(loan_id, status)`, index `(due_date)`.

### 4.8 `loan_deductions`
`deduction_id` PK, `loan_id` FK, `deduction_type` ENUM(`service_fee`,`insurance`,`cbu_retention`,`previous_loan`,`notarial_fee`), `amount`, **+`ref_loan_id`** (for `previous_loan`).

### 4.9 `payments`
`payment_id` PK, `loan_id` FK, `schedule_id` FK, `or_no`, `payment_date`, `amount_paid`, `mode` ENUM(`cash`,`salary_deduction`,`bank_deposit`,`field_collection`,`e_wallet`,`offset`), `principal_portion`, `interest_portion`, `penalty_portion`, `remaining_balance`, `posted_by` FK, **+`status` ENUM(`posted`,`void`), `void_reason`, `voided_by`, `voided_at`, `created_at`**.

### 4.10 `delinquency`
`delinquency_id` PK, `loan_id` FK, `days_past_due`, `aging_bracket`, `amount_past_due`, `date_generated`. Index `(date_generated)`.

### 4.11 `notifications`
`notification_id` PK, `member_id` FK, `loan_id` FK, `notification_type` ENUM(`upcoming`,`overdue`), `date_sent` (nullable until sent), `status` ENUM(`pending`,`sent`,`failed`,`cancelled`), **+`message`, `created_by`, `created_at`**.
Record-only: the system creates reminder records; staff send them (a `mailto:` link is pre-filled) and mark them sent. No mail library.

### 4.12 `audit_log`
`log_id` PK, `user_id` FK, `action`, `table_affected`, `record_id`, `timestamp`, **+`details` TEXT, `ip_address`**. Insert-only.

### 4.13 **+`settings`** (key/value)
Rates and defaults the Manager can change without code: service fee %, insurance %, CBU retention %, notarial fee, reminder lead days, aging bracket bounds, OR counter, minimum share capital for eligibility.

### 4.14 Database user (least privilege)
```sql
CREATE USER IF NOT EXISTS 'mutuallink_app'@'localhost' IDENTIFIED BY '<set at install>';
GRANT SELECT, INSERT, UPDATE, DELETE ON mutuallink.* TO 'mutuallink_app'@'localhost';
```
No DDL privileges. `config/db.php` connects as this user.

## 5. Security (course topics → implementation)

**Authentication & authorization**
- `password_hash(PASSWORD_BCRYPT, ['cost'=>12])`, `password_verify()`, `password_needs_rehash()` on login. Bcrypt salts automatically. MD5/SHA never used.
- Password policy: min 8 chars, at least one letter and one digit (`preg_match`).
- Login by username or email, `status='active'` only; generic error message ("Invalid username or password").
- Session: `session.use_strict_mode=1`, custom name `MUTUALLINK_SID`, cookie `HttpOnly`, `SameSite=Strict`, `Secure` when HTTPS. `session_regenerate_id(true)` on login.
- Hijacking defense: session stores `hash('sha256', User-Agent)`; mismatch → destroy. Idle timeout 15 minutes.
- Logout: POST + CSRF, clear `$_SESSION`, expire cookie, `session_destroy()`.
- RBAC matrix (§7) in one array, read by route guards, sidebar, and button rendering.

**Validation & vulnerability mitigation**
- `declare(strict_types=1);` in every PHP file.
- Validate input; escape output. No sanitizing-on-input (`stripslashes`/`htmlspecialchars` on input).
- `filter_var` (int IDs, floats, emails), `preg_match` (username, contact no., TIN, SSS), enum whitelists.
- Error accumulator `$errors[]`, all messages shown together.
- `e()` = `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` on all output. JS uses `textContent`/`.text()`.
- Headers: `Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`.
- CSRF: `bin2hex(random_bytes(32))` per session; hidden field on every form, header on fetch; `hash_equals`.
- Inline scripts avoided so the CSP holds; page JS lives in `dist/js/mutuallink.js`, and PHP passes data via `data-*` attributes.

**PDO**
- DSN `mysql:host=…;dbname=mutuallink;charset=utf8mb4`; attributes: `ERRMODE_EXCEPTION`, `DEFAULT_FETCH_MODE=FETCH_ASSOC`, `EMULATE_PREPARES=false`.
- DB errors logged with `error_log`; user sees a generic message.
- `lastInsertId()`, `fetch` vs `fetchAll`, `rowCount()` checks on UPDATE/DELETE, `bindValue(..., PDO::PARAM_INT)` where needed.
- Transactions: member registration (+ auto-open share capital and regular savings accounts), savings post/reversal, loan release, payment post, payment void.

**DB administration**
- Least-privilege app user; indexes per §4; JOINs over N+1; named columns instead of `SELECT *`; `audit_log`; `backup.bat` (mysqldump) + restore steps.

## 6. Computation rules (`lib/loan_calc.php`)

Rates and amounts come from `loan_products` / `settings`. Rounding: 2 decimals, half-up; the rounding remainder goes to the last installment.

1. **Amortization — equal principal, diminishing balance.**
   `principal_due = round(P / n, 2)` (last = remainder); `interest_due = round(balance_before × rate/100, 2)`; `total_due = principal_due + interest_due`. Due dates: monthly from release date (same day of month; clamped to the month's last day).
   Example ₱10,000 × 3 mo × 3%: 3,633.33 / 3,533.33 / 3,433.34; total interest ₱600.00.
2. **Penalty — 4% per month late.** For an installment past its due date:
   `months_late = ceil(days_late / 30)`; `penalty = round(unpaid_due × penalty_rate/100 × months_late, 2) − penalty_paid`, where `unpaid_due = total_due − principal_paid − interest_paid`.
   Example ₱3,633.33 unpaid, 40 days late → 2 months → ₱290.67.
3. **Payment application.** Payment goes to the oldest unpaid installment, applied **penalty → interest → principal**. The amount is capped at that installment's remaining due (penalty + interest + principal); larger amounts are posted as a new payment on the next installment. Installment → `partial`/`paid`; `loans.outstanding_balance` drops by the principal portion; at 0 → loan `paid`.
4. **Deductions at release.** service fee = principal × %, insurance = principal × %, CBU retention = principal × % (credited to the member's capital build-up account as a deposit in the same transaction), notarial = fixed, previous loan = outstanding principal of a selected released loan of the same member (that loan's remaining installments are closed by `offset` payments; unearned future interest is waived). `net_proceeds = principal − Σ deductions`, must be > 0.
5. **Aging.** `days_past_due` = today − oldest unpaid installment's due date. Brackets (settings): 1–30, 31–60, 61–90, 91–180, 181–365, over 365. `amount_past_due` = Σ unpaid due of overdue installments.
6. **Savings.** Deposit adds; withdrawal allowed only on `regular_savings` and `time_deposit`, only up to balance (share capital and CBU are withdrawal-locked). Correction = `reversal` entry restoring the balance, never an edit/delete.
7. **OR numbers.** `OR-YYYY-NNNNNN` from a counter in `settings`, taken with `SELECT … FOR UPDATE` inside the posting transaction (no duplicates).

Default placeholders (editable in Settings; *confirm with FFMC*): service fee 2%, insurance 1%, CBU retention 2%, notarial ₱100, reminder lead 3 days, min share capital ₱1,000.

## 7. RBAC matrix

C create · R read · U update · D deactivate/void/cancel (soft) · — no access (hidden + 403)

| Module | Manager | Cashier | Loan Officer | Bookkeeper | Board/Auditor |
|---|---|---|---|---|---|
| Dashboard | R | R | R | R | R |
| User accounts | CRUD | — | — | — | — |
| Own profile/password | U | U | U | U | U |
| Members | C R U D | C R U | R U | R | R |
| Savings accounts | C R U D | C R | R | R U | R |
| Savings transactions | R | C R | R | R D (reversal) | R |
| Loan products & settings | C R U D | R | R | R | R |
| Loan applications | R U (approve/reject) | R | C R U D (cancel pending) | R | R |
| Loan release | U | R | R | R | R |
| Amortization schedule | R | R | R | R | R |
| Payments | R | C R | R | R D (void) | R |
| Delinquency | R | — | C R | R | R |
| Notifications | R | — | C R U D | — | — |
| Reports | R all | R daily collection | R loan status, aging | R collection, savings, share capital | R all |
| Audit log | R | — | — | R | R |

**Separation-of-duties rules**
1. Loan approver ≠ loan encoder (`approved_by != created_by`).
2. Cashier posts; Bookkeeper voids/reverses (reason required; only the latest posted payment of a loan can be voided, to keep the schedule consistent).
3. Manager cannot deactivate or demote self; at least one active Manager must remain.
4. Manager does not post money.
5. Board/Auditor is read-only.

## 8. Modules & pages (CRUD map by RAD version)

**Version 1**
- Login / logout / change own password.
- Users: list, add, edit (role, status, reset password), deactivate. Live username/email existence check.
- Members: list (DataTable), register (auto member_no; auto-open share capital + regular savings), edit, deactivate. **Member profile** = single record (Objective 2): tabs Profile · Accounts · Loans · Payment history · Eligibility summary.
- Savings: accounts list, open account, passbook view (transactions with running balance), post deposit/withdrawal (receipt), reversal, close account (balance must be 0).

**Version 2**
- Loan products: list, add, edit, deactivate.
- Loans: list with status filters; new application (member lookup, product, principal, term, co-maker, collateral; live amortization preview; eligibility panel); approve/reject (Manager); release (deductions, net proceeds, generates schedule); cancel pending (Loan Officer); loan view with schedule and payments; printable amortization schedule.
- Payments: post payment (loan lookup → current installment, computed penalty, split preview) → printable official receipt; payment history; void (Bookkeeper).

**Version 3**
- Delinquency: generate snapshot (replaces today's snapshot), list by bracket, per-loan detail.
- Notifications: generate reminders (upcoming within lead days; overdue from delinquency), list, mark sent/failed, cancel, `mailto:` helper.
- Reports (read-only, filters + DataTables export/print): daily collection, loan status, delinquency & aging, savings summary, share capital.
- Audit log viewer (filter by user, table, date).
- Settings (Manager): rates, deductions, reminder days, aging brackets.
- Dashboard: role-specific KPI boxes (members, active loans, portfolio outstanding, due today, past due, today's collections) + recent activity.

## 9. UI direction

AdminLTE 3.2 with a custom cooperative identity: deep green primary, neutral light sidebar, system font stack (offline).
- Info-boxes and small-boxes on the dashboard; cards with tabs on the member profile.
- Status badges (loan and installment states).
- Right-aligned tabular money (`₱ 1,234.56`).
- Confirmation modals for destructive actions; Toastr for results.
- Loading/disabled state on submit.
- `@media print` stylesheet for receipts, schedules, and reports.
- Accessible labels on all inputs, focus states, color plus text on badges.

## 10. Error handling

- DB exceptions caught at the page level → rollback if in a transaction → `error_log` → generic Toastr "Something went wrong; nothing was saved."
- Validation failures → re-render the form with values kept and errors listed.
- `rowCount()` mismatch on UPDATE/DELETE → treated as a failure (record changed or missing).
- 403/404 views for unauthorized or unknown pages.

## 11. Testing

- `tests/loan_calc_test.php` (run `php tests/loan_calc_test.php`): asserts the §6 examples — schedule, rounding remainder, penalty, payment split order, aging bracket boundaries, deduction totals.
- Manual E2E per role in the browser after each version: login per role, forbidden-page checks (403), full CRUD per module, a payment then void round-trip that restores balances, CSRF rejection (missing token → refused).
- SQL script imports cleanly on a MySQL-compatible server with zero errors.

## 12. Build order

V1 → checkpoint (user review) → V2 → checkpoint → V3 → final review. Each version is committed separately.
