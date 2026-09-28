# MutualLink — System Documentation

**MutualLink: Integrated Loan, Balance, and Savings Management System**
Franciscan Friends Multi-Purpose Cooperative (FFMPC), Andres Bonifacio, Zone 1, Baybay City, Leyte

This document has two parts:

- **Part A — User guide:** the complete flow of the system, role by role, with a screenshot of every step.
- **Part B — Development process:** how the system was built, from the requirements to the tests.

All screenshots were taken from the running system (PHP 8.2, MySQL-compatible database, AdminLTE 3.2) on September 28, 2026, using the seed accounts and two sample members. The sample data is described in [A.8](#a8-about-the-sample-data).

---

## Contents

- [Part A — User guide](#part-a--user-guide)
  - [A.1 Signing in and the dashboard](#a1-signing-in-and-the-dashboard)
  - [A.2 Membership (Cashier → Manager)](#a2-membership-cashier--manager)
  - [A.3 Savings and share capital (Cashier)](#a3-savings-and-share-capital-cashier)
  - [A.4 Loan application, approval, and release (Loan Officer → Manager)](#a4-loan-application-approval-and-release-loan-officer--manager)
  - [A.5 Loan payments and corrections (Cashier → Bookkeeper)](#a5-loan-payments-and-corrections-cashier--bookkeeper)
  - [A.6 Delinquency monitoring and reminders (Loan Officer)](#a6-delinquency-monitoring-and-reminders-loan-officer)
  - [A.7 Reports, audit log, settings, and access control](#a7-reports-audit-log-settings-and-access-control)
  - [A.8 About the sample data](#a8-about-the-sample-data)
- [Part B — Development process](#part-b--development-process)

---

# Part A — User guide

## Who does what

| Role | Username | Main work in the system |
|---|---|---|
| Manager | `manager` | Approves memberships, approves/rejects and releases loans, manages user accounts and settings |
| Cashier | `cashier` | Registers applicants, receives membership fees, deposits, withdrawals, and loan payments; issues official receipts |
| Loan Officer | `loanofficer` | Files loan applications, checks eligibility, updates the delinquency list, prepares reminders and demand letters |
| Bookkeeper | `bookkeeper` | Corrects entries (savings reversals, voiding receipts), prepares reports and the salary deduction list |
| Board / Auditor | `auditor` | Read-only access to all records, reports, and the audit log |

Default password for every seed account: `Password123` (change after installation).

---

## A.1 Signing in and the dashboard

**Sign in** with a username or email and password. After five wrong attempts, sign-in is locked for 30 seconds. The session ends after 15 minutes without activity.

![Login page](screenshots/01-login.png)

The **dashboard** shows the cooperative's day at a glance: active members, the loan portfolio, today's collections, past-due loans, share capital, savings, applications in process, and installments due within 7 days. It also shows a collections chart, quick actions for the signed-in role, and (for the Manager, Bookkeeper, and Auditor) the most recent activity.

![Manager dashboard](screenshots/02-dashboard.png)

The sidebar only shows the modules the signed-in role may use.

---

## A.2 Membership (Cashier → Manager)

FFMPC's membership steps (questionnaire, Part II): **pre-membership seminar (PMES) → membership form → membership fee and common shares → Manager's approval (about one month) → active member.**

### Step 1 — Cashier registers the applicant

*Members → Register member.* The TIN and PMES date are required, and the signature specimen card is ticked when received. Members from outside the school must later submit collateral for loans.

![Register member form](screenshots/03-register-member.png)

On saving, the system gives the member a number (**FFMPC-2026-0001**), opens a **Share Capital** and a **Regular Savings** account, and sets the status to **Applicant**. The member record shows a checklist of what is still missing.

![Applicant record with requirements checklist](screenshots/04-applicant-record.png)

### Step 2 — Cashier receives the membership fee

On the member record, enter the fee and click **Receive & issue OR**. The official receipt opens, ready to print.

![Membership fee official receipt](screenshots/05-membership-fee-receipt.png)

### Step 3 — Cashier posts the initial share capital (₱2,000 required)

Open the Share Capital account and post a deposit (see [A.3](#a3-savings-and-share-capital-cashier)).

![Share capital deposit](screenshots/06-share-capital-deposit.png)

### Step 4 — Manager approves the membership

*Members → Applicants.* The list shows everyone waiting for approval.

![Applicants list](screenshots/09-applicants-list.png)

When every requirement is green, the **Approve membership** button is enabled.

![Approve membership](screenshots/10-approve-membership.png)

After approval the member becomes **Active** and the record shows the **eligibility summary** used for loans: good payment record, co-maker, collateral for members outside the school, existing loans, and share capital.

![Active member record with eligibility summary](screenshots/11-member-record-active.png)

> An applicant cannot borrow. Deactivating and reactivating a member never skips this approval step.

---

## A.3 Savings and share capital (Cashier)

Each member can hold one account of each type: **Regular Savings, Share Capital, Capital Build-Up (CBU), Time Deposit.** Every deposit or withdrawal writes one line with its running balance and a receipt number, so the passbook and the office ledger are the same record.

FFMPC's rules (questionnaire 3.1–3.6), enforced by the system:

| Rule | Value |
|---|---|
| Share capital required for membership | ₱2,000 |
| Regular savings maintaining balance (and opening deposit) | ₱500 |
| Time deposit minimum | ₱10,000 (or withdrawn in full) |
| Capital build-up | ₱100 per month (shown as a reminder) |
| Share capital and CBU withdrawals | Not allowed |
| Withdrawals | Need the passbook; a lost passbook needs a notarized affidavit of loss |
| Deposits | Allowed without the passbook (member is reminded to have it updated) |

A withdrawal that would go below the maintaining balance is refused, and the message says how much can be withdrawn:

![Withdrawal refused below maintaining balance](screenshots/07-withdrawal-maintaining-balance.png)

The **passbook** view lists every transaction with its receipt number, running balance, and who posted it. The Bookkeeper can correct an entry with a **reversal** (↺); the original line is never edited or deleted.

![Passbook](screenshots/08-passbook.png)

---

## A.4 Loan application, approval, and release (Loan Officer → Manager)

FFMPC's loan process (questionnaire 4.5–4.10): **application with co-maker → credit committee evaluation → Manager's final approval → release in cash or check.**

| Product | Loanable amount | Term | Interest |
|---|---|---|---|
| Regular Loan | Up to **30% of the collateral's appraised value** | Up to 12 months | 3% per month, diminishing balance |
| Salary Loan | Depends on net pay (net pay is recorded) | Up to 12 months | 3% per month, diminishing balance |
| Emergency Loan | Up to ₱3,000 | Up to 3 months | 3% per month, diminishing balance |

### Step 1 — Loan Officer files the application

*Loans → New application → find the member.* Enter the product, principal, term, co-maker, collateral (type, description, appraised value), net pay for salary loans, and how the member will pay. The **eligibility summary** is shown beside the form.

![Loan application form](screenshots/12-loan-application.png)

The **amortization preview** is computed live as you type (equal principal, 3% on the remaining balance) and includes the **semi-monthly** amount for salary deductions on the 15th and 30th. The example is ₱50,000 over 12 months: 4,166.67 principal + 1,500.00 interest = **5,666.67** for the first month, the same figures as FFMPC's handwritten computation.

![Live amortization preview](screenshots/12b-amortization-preview.png)

After submitting, the loan is **Pending**.

![Pending loan](screenshots/13-loan-pending.png)

### Step 2 — Manager records the decision

The system records the credit committee's evaluation and the Manager's decision; it does not decide the loan. **The person who encoded the application cannot approve it** (separation of duties).

![Loan decision](screenshots/14-loan-decision.png)

### Step 3 — Manager releases the loan

Choose the release date and **cash or check** (a check needs its number). The deductions follow FFMPC's own "Summary of loan computation":

| Deduction | Rate |
|---|---|
| Interest | Not deducted in advance |
| Loan insurance | 0.56% of principal |
| Service fee | 3% of principal |
| Stockshare | 2% of principal — **added to the member's share capital** |
| Notarial fee | ₱200 |
| Others (printing) | ₱30 |
| Previous loan (renewal) | Remaining balance of an old loan, if selected |

![Release loan with deductions](screenshots/15-loan-release.png)

Releasing generates the **amortization schedule**. The loan record shows the schedule, payments, amount currently due, full payoff amount, and details (collateral, co-maker, release mode, deductions).

![Released loan record](screenshots/16-loan-released.png)

**Schedule** prints the member's copy, with signature lines:

![Printed amortization schedule](screenshots/17-schedule-print.png)

---

## A.5 Loan payments and corrections (Cashier → Bookkeeper)

### Posting a payment (Cashier)

*Payments → Post payment → find the member → choose the loan.*

![Choose the loan](screenshots/18-payment-choose-loan.png)

Quick buttons fill in the **amount due now**, the **semi-monthly** amount, or the **full payoff**. The preview shows how the amount will be applied, in FFMPC's order: **penalty → interest → principal, oldest installment first.** An amount larger than one installment settles the next ones in advance, and paying everything closes the loan early. **There is no rebate.**

![Payment preview covering several installments](screenshots/19-payment-advance.png)

One official receipt lists every installment the payment covered:

![Official receipt for a payment](screenshots/20-payment-receipt.png)

### After the loan term (past due)

Once the loan term has ended, the unpaid balance is charged **3% interest + 4% penalty = 7% per month** (a partial month counts as one month). The example is an Emergency Loan of ₱3,000 whose term ended 3 months ago: 3 × 3% = ₱270 interest and 3 × 4% = ₱360 penalty, collected first.

![Payment screen for a loan past its term](screenshots/21-payment-after-term.png)

### Voiding a receipt (Bookkeeper)

Only the **latest** receipt of a loan can be voided, and a reason is required.

![Void receipt confirmation](screenshots/22-void-receipt.png)

Voiding restores the installments, the balance, and the after-term charges exactly. The receipt stays on record marked **VOID** with its reason.

![Voided receipt in the payment history](screenshots/23-voided-payments.png)

---

## A.6 Delinquency monitoring and reminders (Loan Officer)

This is the part the bookkeeper said she would automate first (questionnaire 11.4).

**Delinquency → Update list now** scans every schedule against the payments received, computes the days past due, and classifies each loan by aging bracket (1–30, 31–60, 61–90, 91–180, 181–365, over 365 days). The list shows the member's contact number and co-maker, and the portfolio at risk.

![Delinquency and aging](screenshots/24-delinquency.png)

**Demand letter** prints a letter to the borrower listing the unpaid installments and the after-term charges, with a copy for the co-maker, since the co-maker is liable when the borrower fails to pay (questionnaire 7.4–7.5).

![Demand letter](screenshots/25-demand-letter.png)

**Reminders → Generate reminders** prepares messages for installments due within 3 days and for overdue loans. Each can be opened in the email app (✉) and then marked sent or failed. Only sending the email needs an internet connection.

![Payment reminders](screenshots/27-reminders.png)

---

## A.7 Reports, audit log, settings, and access control

### Reports

Every report can be exported to Excel/CSV or printed. Each role sees only its reports.

**Daily collection** (for end-of-day balancing): every receipt of the day with cash in, cash out, net, and totals per cashier.

![Daily collection report](screenshots/29-report-daily-collection.png)

**Salary deduction list** (prepared by the Bookkeeper): what to deduct on the 15th and the 30th for members who pay by salary deduction.

![Salary deduction list](screenshots/30-report-salary-deduction.png)

**Share capital** (Board / Auditor view), with each member's share of the total:

![Share capital report](screenshots/33-auditor-share-capital.png)

The other reports are **loan status** and **delinquency & aging**.

### Audit log

Every sign-in, entry, approval, correction, and settings change is recorded with the user and time. The log cannot be edited.

![Audit log](screenshots/31-audit-log.png)

### Settings (Manager)

Deduction rates, the collateral loanable percentage, account minimums, reminder timing, aging brackets, and the **official receipt series**. The Manager sets the last number issued to match the receipt booklet, and the series can only move forward. The panel on the right checks the rates against FFMPC's ₱26,000 sample computation.

![Settings](screenshots/26-settings.png)

### User accounts (Manager)

Add, edit, reset passwords, and deactivate staff accounts. Accounts are never deleted, so the audit trail stays intact, and at least one active Manager must always remain.

![User accounts](screenshots/28-user-accounts.png)

### Access control

A role that opens a page it is not allowed to use gets **Access denied**, even when typing the address directly. Shown here is the Board / Auditor trying to add a user.

![Access denied](screenshots/32-access-denied.png)

---

## A.8 About the sample data

- **Juan Dela Cruz** (FFMPC-2026-0001, outside the school) was registered, paid the membership fee, deposited ₱2,000 share capital and ₱1,200 regular savings, withdrew ₱700, and was approved. His **Regular Loan #1** (₱50,000 / 12 months, land title appraised at ₱3,000,000) was released by check. A ₱12,000 salary-deduction payment covered installments 1–3, and a ₱500 duplicate payment was voided by the Bookkeeper.
- **Ana Reyes** (FFMPC-2026-0002, school-based) has an **Emergency Loan #2** of ₱3,000 / 3 months. To show the past-due features, its approval and release dates were set about five months in the past directly in the test database. Everything else went through the normal screens.
- Receipts 000001–000004 were issued before the Manager set the series to the booklet number; later receipts continue from **025952**.

---

# Part B — Development process

## B.1 Requirements sources

1. **Capstone manuscript**, Revisions 21 and 24: objectives, scope, the Chapter III technical background, and Figures 1–11 (architecture, network, RAD model, data schema, context diagram, DFD levels 1–2, ERD).
2. **Requirements-gathering questionnaire** answered by the FFMPC bookkeeper (Appendix A): membership steps, account minimums, loan products, eligibility, deductions, penalty, payment modes, receipts, delinquency, reports, and user roles.
3. **Photos of FFMPC's manual records**: the sample loan computation (₱26,000 / 9 months with its deductions), the handwritten computation (₱50,000 / 12 months, "Past due 3% + Penalty 4% = 7%"), the receipt booklet (numbered series), and the payroll deduction sheet (15th and 30th).
4. **Course materials**: the professor's code snippets (PDO connection, same-page form handling, `dashboard.php?page=` routing, AJAX existence check, Toastr) and the report topics (PDO & CRUD, REST APIs, authentication & authorization, validation & vulnerabilities, front-end integration, indexing, database security, backup).

## B.2 Decisions confirmed with the team

| Question | Decision |
|---|---|
| AdminLTE version | **3.2.0** on Bootstrap 4.6.1, the same as the mid-semester project (Chapter III should be updated from 4.9.1 / 5.3.8) |
| Scope | All three RAD versions, each as CRUD |
| Architecture | Server-rendered pages (professor's pattern) plus a few JSON endpoints |
| Email reminders | Recorded in the system; staff send them from their email app |
| Database account | Least-privilege account instead of `root` |
| Member registration | Cashier and Manager; Manager does not post money |
| Amortization | Equal principal, 3% on the diminishing balance, which matches FFMPC's sample |
| Late payment | 3% interest + 4% penalty = 7% per month on the unpaid balance once the term is surpassed |
| Aging brackets | 1–30, 31–60, 61–90, 91–180, 181–365, over 365 days (editable) |

## B.3 Build order (RAD versions)

| Commit | What was built |
|---|---|
| `f505152` | Design specification (`docs/specs/`) |
| `ca7b002` | Database schema (13 tables from Figure 4 plus settings), PDO and security configuration, loan computation library with tests |
| `dbb9b83` | **Version 1:** sign-in, role-based route guards, layout, dashboard |
| `5c7dd10` | **Version 1:** user accounts, members, savings and share capital, receipts, JSON endpoints |
| `6e36709` | **Version 2:** loan products, applications, approval, release, payments, voids |
| `8147db5` | **Version 3:** delinquency and aging, reminders, reports, audit log, settings |
| `da8dbf4` | README with WAMP installation and defense notes |
| `004d0b8` | Fixes from an independent code review |
| `53346df` | Alignment with Revision 24 and the questionnaire answers (FFMPC name, logo, deductions, after-term penalty, advance payments, membership approval, savings minimums, OR series, salary deduction list, demand letter) |
| `feb30ee` | Logo shown without a box |

## B.4 Security applied (course topics)

| Topic | How it is applied |
|---|---|
| Password storage | `password_hash` (bcrypt, cost 12), `password_verify`, `password_needs_rehash` |
| Sessions | HttpOnly and SameSite cookies, strict mode, new session ID on sign-in, browser binding, 15-minute idle timeout, full logout |
| Authorization | One role-permission matrix used by the route guards, the sidebar, and the buttons |
| Route guards (middleware functions) | `require_login()` → `require_permission()` → `require_csrf()` before every page and endpoint |
| CSRF | Random token on every form and JSON request, checked with `hash_equals` |
| SQL injection | PDO prepared statements with named parameters, native prepares |
| XSS | Every output escaped with `htmlspecialchars(ENT_QUOTES, 'UTF-8')`; JavaScript uses `textContent`; Content Security Policy blocks inline scripts |
| Validation | Server-side validation of every input, with all errors shown together |
| Money integrity | Database transactions with row locks for postings, voids, and releases; receipts are never deleted, only voided or reversed |
| Database | Least-privilege account (SELECT, INSERT, UPDATE, DELETE only), indexes on keys and searches, insert-only audit log, backup script |

## B.5 Testing

| Test | Result |
|---|---|
| `tests/loan_calc_test.php`: schedule, deductions, after-term charges, payment allocation, aging brackets, including FFMPC's ₱26,000 / 9-month sample (interest 780.00, 693.33, … 86.67; deductions ₱1,675.60; net ₱24,324.40) | All checks pass |
| `tests/loan_flow_test.php`: release, after-term charges, partial payment, full payoff on one receipt, void, advance payment, renewal offset, savings minimums (against the database) | All checks pass |
| Role-by-role walkthrough over HTTP and in the browser (this document) | All steps behave as described |
| Forbidden actions for each role (pages and buttons) | Refused with Access denied |
| Independent code review (security and money correctness) | One finding fixed (toast messages rendered as text) |

## B.6 Installation

See the main [README](../README.md): copy the folder to `C:\wamp64\www\mutuallink`, import `database/mutuallink.sql` in phpMyAdmin, and open `http://localhost/mutuallink`.
