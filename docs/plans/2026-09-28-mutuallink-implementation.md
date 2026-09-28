# MutualLink Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (chosen: Native, autonomous). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Build the MutualLink CRUD system (RAD V1–V3) in plain PHP 8.2 + PDO + MySQL on AdminLTE 3.2.

**Architecture:** Server-rendered pages routed through `dashboard.php?page=X`, guarded by route-guard middleware functions (login → RBAC permission → CSRF). Each view handles its own POST at the top (professor's pattern), then PRG-redirects. Pure computation lives in `lib/loan_calc.php`, tested by an assert script. Three small JSON endpoints under `api/`.

**Tech Stack:** PHP 8.2.12, PDO MySQL, MySQL 8.3 (tested on MariaDB 10.4), AdminLTE 3.2.0, Bootstrap 4.6.1, jQuery 3.6, DataTables, Toastr.

**Spec:** `docs/specs/2026-09-28-mutuallink-design.md`

## Global Constraints

- `declare(strict_types=1);` first statement of every PHP file.
- All SQL through PDO prepared statements with named params; never interpolate input.
- All output through `e()`; JS renders data with `.text()`/`textContent`.
- Every POST form includes `csrf_field()`; every JSON call sends `X-CSRF-Token`.
- No inline `<script>` blocks (CSP `script-src 'self'`); page data via `data-*` attributes; JS in `dist/js/mutuallink.js`.
- InnoDB, `utf8mb4`, `utf8mb4_unicode_ci`; no vendor-specific SQL.
- Money `DECIMAL(12,2)`; PHP computes with `round(x, 2)` half-up.
- Financial rows are never hard-deleted (void/reversal/deactivate).
- Relative URLs only; runs from any folder name under WAMP `www`.
- Assets are local (copied from AdminLTE 3.2.0 distribution); no CDN.

## Review Focus

1. Double-submit / concurrent posting → OR numbers never duplicate; balance never double-applied (row lock `FOR UPDATE` on counter, savings account, and schedule row inside transaction).
2. Withdrawal larger than balance or from share capital/CBU → rejected with message, no row written.
3. Payment greater than current installment due, or on a non-released/paid loan → rejected, nothing saved.
4. Voiding a payment that is not the latest posted one → refused; voiding the latest restores installment paid amounts, status, and loan outstanding exactly.
5. Session tampering: missing/wrong CSRF token, changed User-Agent, idle > 15 min, deactivated user mid-session → request refused, session ended.

---

## File map

| File | Responsibility |
|---|---|
| `config/db.php` | `db(): PDO` singleton; DB constants |
| `config/session.php` | `start_secure_session()`, headers, fingerprint, idle timeout, `csrf_token()`, `csrf_field()`, `verify_csrf()`, `logout_user()` |
| `config/rbac.php` | `RBAC`, `ROLES`, `can(string $module, string $action): bool`, `require_login()`, `require_permission(string $module, string $action)` |
| `config/helpers.php` | `e()`, `money()`, `flash()`, `get_flash()`, `redirect()`, `audit_log()`, `next_or_no()`, `setting()`, `post_str()`, `post_int()`, `post_money()`, `old()` |
| `lib/loan_calc.php` | `build_schedule()`, `compute_penalty()`, `split_payment()`, `aging_bracket()`, `compute_deductions()` |
| `index.php`, `logout.php`, `dashboard.php` | login, logout, router |
| `include/*.php` | layout parts |
| `views/*.php` | pages |
| `api/*.php` | JSON endpoints + `api/_bootstrap.php` (`json_out()`, `read_json_body()`) |
| `database/mutuallink.sql`, `database/backup.bat` | schema + seed + grant, backup |
| `dist/css/mutuallink.css`, `dist/js/mutuallink.js` | theme + behavior |
| `tests/loan_calc_test.php` | computation asserts |

## Page routing table (`dashboard.php`)

`page` → [module, action] (permission checked before include):
`home`→[dashboard,view] · `profile`→[profile,update] · `users`→[users,view] · `user_form`→[users,create|update] · `members`→[members,view] · `member_form`→[members,create|update] · `member_view`→[members,view] · `savings`→[savings,view] · `passbook`→[savings,view] · `savings_post`→[savings_txn,create] · `products`→[products,view] · `product_form`→[products,create|update] · `loans`→[loans,view] · `loan_form`→[loans,create] · `loan_view`→[loans,view] · `schedule_print`→[loans,view] · `payments`→[payments,view] · `payment_post`→[payments,create] · `receipt`→[payments,view] · `delinquency`→[delinquency,view] · `notifications`→[notifications,view] · `reports`→[reports,view] · `audit`→[audit,view] · `settings`→[settings,view]

Finer actions (approve, release, void, reverse, deactivate) are checked inside the view's POST block with `require_permission()`.

---

### Task 1: Scaffold, assets, schema, config layer

**Files:** Create `.htaccess`, `config/*.php`, `database/mutuallink.sql`, `database/backup.bat`; copy AdminLTE 3.2.0 assets.

- [ ] Copy only needed AdminLTE 3.2.0 assets: `dist/css/adminlte.min.css`, `dist/js/adminlte.min.js`, plugins `jquery`, `bootstrap`, `fontawesome-free`, `datatables`, `datatables-bs4`, `datatables-responsive`, `datatables-buttons`, `jszip`, `pdfmake`, `toastr`, `chart.js`.
- [ ] Write `database/mutuallink.sql` per spec §4 (13 tables, FKs, indexes, seed users — one per role, password `Password123` bcrypt cost 12 — seed products, settings, least-privilege user).
- [ ] Import into test DB → no errors; `SHOW TABLES` lists 13.
- [ ] Write config files; `php -l` each.
- [ ] Commit `feat: scaffold config, schema, and AdminLTE 3.2 assets`.

### Task 2: Loan computation library (TDD)

**Files:** Create `tests/loan_calc_test.php`, `lib/loan_calc.php`.

**Interfaces produced:**
```php
build_schedule(float $principal, int $months, float $ratePct, string $releaseDate): array
  // list of ['installment_no'=>int,'due_date'=>'Y-m-d','principal_due'=>float,'interest_due'=>float,'total_due'=>float,'balance'=>float]
add_months_clamped(string $date, int $n): string
compute_penalty(float $unpaidDue, float $penaltyRatePct, string $dueDate, string $asOf, float $penaltyPaid): float
split_payment(float $amount, float $penaltyDue, float $interestDue, float $principalDue): array
  // ['penalty'=>float,'interest'=>float,'principal'=>float,'excess'=>float]
aging_bracket(int $daysPastDue): string   // '' when 0, else '1-30','31-60','61-90','91-180','181-365','Over 365'
compute_deductions(float $principal, array $rates, float $previousLoanBalance): array
  // ['service_fee','insurance','cbu_retention','notarial_fee','previous_loan','total','net']
```

- [ ] Write test asserting: ₱10,000/3/3% → 3633.33, 3533.33, 3433.34, interest 600.00, last balance 0; due dates from 2026-01-31 → 2026-02-28, 2026-03-31, 2026-04-30; penalty 3633.33 @4%, 40 days → 290.67; not late → 0; exactly 30 days → 1 month; penalty already paid subtracts; split 500 on (100 pen, 300 int, 3333.33 prin) → 100/300/100/0; overpay → excess > 0; bracket boundaries 0,1,30,31,60,61,90,91,180,181,365,366; deductions on 10,000 with 2/1/2/100 and prev 0 → total 600, net 9400.
- [ ] Run `php tests/loan_calc_test.php` → FAIL.
- [ ] Implement `lib/loan_calc.php`.
- [ ] Run → `All loan_calc tests passed`.
- [ ] Commit `feat: loan computation library with assert tests`.

### Task 3: Auth, layout, router, dashboard shell (V1)

**Files:** `index.php`, `logout.php`, `dashboard.php`, `include/*.php`, `views/home.php`, `views/profile.php`, `views/403.php`, `views/404.php`, `dist/css/mutuallink.css`, `dist/js/mutuallink.js`.

- [ ] Login: same-page POST, CSRF, username-or-email, active only, `password_verify`, `password_needs_rehash`, `session_regenerate_id(true)`, fingerprint + last activity, update `last_login`, audit `login`. Generic failure message. After 5 failures in session → 30-second lockout.
- [ ] Router with routing table and guards; unknown → 404; forbidden → 403.
- [ ] Sidebar built from `can()`.
- [ ] Profile: change own password (current password required, policy check).
- [ ] Browser verify: login each role, sidebar differs, 403 on forbidden URL, logout clears session, CSRF-less POST refused.
- [ ] Commit `feat(v1): authentication, RBAC router, and layout`.

### Task 4: Users + Members + Savings (V1)

**Files:** `views/users.php`, `views/user_form.php`, `views/members.php`, `views/member_form.php`, `views/member_view.php`, `views/savings.php`, `views/passbook.php`, `views/savings_post.php`, `api/_bootstrap.php`, `api/check_existence.php`, `api/member_lookup.php`.

- [ ] Users CRUD with self-protection + last-manager rule.
- [ ] Members CRUD; registration transaction creates member + share_capital + regular_savings accounts; auto member_no.
- [ ] Savings: open account, passbook, deposit/withdrawal with `FOR UPDATE`, OR number, withdrawal rules, reversal (bookkeeper), close (balance 0).
- [ ] Member profile tabs + eligibility summary.
- [ ] Browser verify: register → accounts exist; deposit 500 → balance 500 + OR; overdraw refused; share capital withdrawal refused; reversal restores.
- [ ] Commit `feat(v1): users, members, and savings modules`.

### Task 5: Loan products + Loans + Payments (V2)

**Files:** `views/products.php`, `views/product_form.php`, `views/loans.php`, `views/loan_form.php`, `views/loan_view.php`, `views/schedule_print.php`, `views/payments.php`, `views/payment_post.php`, `views/receipt.php`, `api/amortization_preview.php`.

- [ ] Products CRUD.
- [ ] Loan application validated against product; eligibility panel; outside member requires collateral; live preview via fetch.
- [ ] Approve/reject (manager, approver ≠ encoder), cancel pending (loan officer).
- [ ] Release transaction: deductions, CBU deposit, previous-loan offset, schedule rows, status released.
- [ ] Payment posting per spec §6.3 with `FOR UPDATE`; receipt; void latest (bookkeeper).
- [ ] Browser verify: 10,000/3 loan → schedule matches test; pay #1 → outstanding 6666.67; void → restored.
- [ ] Commit `feat(v2): loan products, loan processing, and payment posting`.

### Task 6: Delinquency, notifications, reports, audit, settings, KPIs (V3)

**Files:** `views/delinquency.php`, `views/notifications.php`, `views/reports.php`, `views/audit.php`, `views/settings.php`, update `views/home.php`.

- [ ] Delinquency generate (replace today's snapshot in a transaction), list by bracket.
- [ ] Notifications generate upcoming/overdue (skip existing pending for same loan+type), mark sent/failed/cancel, mailto link.
- [ ] Reports: daily collection, loan status, aging, savings summary, share capital; filters; export/print.
- [ ] Audit viewer with filters (LIMIT 500).
- [ ] Settings edit (manager).
- [ ] Dashboard KPIs per role + Chart.js 7-day collections.
- [ ] Browser verify each.
- [ ] Commit `feat(v3): delinquency, reminders, reports, audit log, settings`.

### Task 7: README, backup, final review

- [ ] README: WAMP install, DB import, app user password, default accounts, defense notes mapping topics → files.
- [ ] Full E2E pass + `php -l` all + test script.
- [ ] Fresh reviewer pass on whole repo; fix findings.
- [ ] Commit `docs: README and deployment notes`.
