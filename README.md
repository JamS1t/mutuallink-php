# MutualLink

Integrated Loan, Balance, and Savings Management System for the **Franciscan Friends Multi-Purpose Cooperative (FFMPC)**, Baybay City, Leyte.

Stack (as in the paper, Ch. III): **WampServer 3.3.5** (Apache 2.4, PHP 8.2, MySQL 8.3) · HTML5/CSS3/JavaScript · **AdminLTE 3.2.0 / Bootstrap 4.6.1** · PDO. No frameworks, no build step, works offline.

> Paper note: Chapter III lists "Bootstrap 5.3.8 / AdminLTE 4.9.1". The system (and the class example) uses **AdminLTE 3.2.0 on Bootstrap 4.6.1** — update Chapter III to match.

---

## 1. Install on WampServer

1. Copy this folder to `C:\wamp64\www\mutuallink`.
2. Start WampServer (tray icon green).
3. Open **phpMyAdmin** (`http://localhost/phpmyadmin`) as `root` → **Import** → choose `database/mutuallink.sql` → Go.
   This creates the `mutuallink` database, all tables, seed accounts, and the least-privilege app account `mutuallink_app`.
4. **Change the app database password** (recommended): in phpMyAdmin run
   `ALTER USER 'mutuallink_app'@'localhost' IDENTIFIED BY 'your-new-password';`
   then put the same password in `config/db.php` (`DB_PASS`).
5. Open `http://localhost/mutuallink` and sign in.

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
| V3 | Reminders | Generate upcoming/overdue | List | Mark sent/failed | Remove unsent |
| V3 | Reports / audit / settings | — | Daily collection, loan status, aging, savings, share capital, salary deduction list, audit log | Settings (Manager) | — |

## 3. FFMPC rules (from the requirements questionnaire, Appendix A)

**Loans (`lib/loan_calc.php`, `lib/loan_service.php`)**
- **Interest:** 3% per month on the diminishing balance, **equal principal** each month — checked against FFMPC's own sample: ₱26,000 / 9 months → principal 2,888.89, interest 780.00, 693.33, 606.67 … 86.67, total interest ₱3,900.00.
- **Semi-monthly amount** (salary deduction on the 15th and 30th) is shown beside each monthly amount, as in FFMPC's computation sheet.
- **After the term (past due):** once the loan term is surpassed, the unpaid balance is charged **3% interest + 4% penalty = 7% per month** (a partial month counts as one). No penalty while the term is running.
- **Payment order:** penalty → interest → principal, oldest installment first. Paying more than one installment settles the next ones in advance; paying everything closes the loan early. **No rebate** on advance or early payment.
- **Deductions at release** (FFMPC's "Summary of loan computation"): loan insurance 0.56%, service fee 3%, stockshare 2% (credited to share capital), notarial fee ₱200, others/printing ₱30, and the balance of a previous loan on renewal. Interest is not deducted in advance. ₱26,000 → deductions ₱1,675.60, net ₱24,324.40.
- **Loanable amount:** Regular Loan up to 30% of the collateral's appraised value (₱3,000,000 title → ₱900,000), up to 1 year; Salary Loan depends on net pay, up to 1 year; Emergency Loan up to ₱3,000, 3 months.
- **Eligibility:** good payment record, a co-maker, and collateral for members from outside the school; the member must be an approved (active) member.
- **Release:** cash or check, by the Manager.

**Members and savings**
- Membership: pre-membership seminar (PMES) → application form with TIN and signature specimen → membership fee + initial share capital → Manager's approval (about one month). Applicants cannot borrow.
- Minimums: share capital ₱2,000, regular savings ₱500 maintaining balance, time deposit ₱10,000, CBU ₱100 per month.
- Withdrawals need the passbook (a lost passbook requires a notarized affidavit of loss); deposits do not.

**Receipts:** plain numbered OR series like the receipt booklet (e.g. 025952). The Manager sets the last number issued in **Settings**; it can only move forward.

**Aging brackets:** 1–30, 31–60, 61–90, 91–180, 181–365, over 365 days (editable).

All rates and minimums are editable in **Settings**. FFMPC's Excel rounds each monthly total **down to ₱0.05** (e.g. 3,668.89 → 3,668.85); MutualLink keeps exact centavos so the balance reaches exactly zero.

## 4. Tests

```
php tests/loan_calc_test.php     # pure computation checks (no database)
php tests/loan_flow_test.php     # release → pay → void → renewal against the DATABASE
```
Run `loan_flow_test.php` only on a **freshly imported, disposable** database: it inserts and removes its own test records but advances the OR and member counters.

## 5. Backup

Edit the MySQL path in `database/backup.bat`, then double-click it (or schedule it in Task Scheduler). Restore with
`mysql -u root -p mutuallink < database\backups\mutuallink_YYYYMMDD_HHMM.sql`.

## 6. Defense notes — where each course topic lives

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
| Least privilege DB account (`GRANT SELECT, INSERT, UPDATE, DELETE`) | end of `database/mutuallink.sql` |
| Audit trail | `audit_log()` in `config/helpers.php`; viewer `views/audit.php` |
| Backup (mysqldump) | `database/backup.bat` |

Separation of duties built in: the encoder of a loan cannot approve it; the Cashier posts and the Bookkeeper voids; the Manager does not post money; the last active Manager cannot be deactivated; financial records are never deleted (reversals and voids keep the original on record).
