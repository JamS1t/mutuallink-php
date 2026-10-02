-- ============================================================
-- MutualLink: Integrated Loan, Balance, and Savings Management System
-- Franciscan Friends Multi-Purpose Cooperative (FFMPC)
-- Target: MySQL 8.3 (WampServer 3.3.5). Also runs on MariaDB 10.4+.
-- Import as root (phpMyAdmin > Import, or: mysql -u root -p < mutuallink.sql)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP DATABASE IF EXISTS mutuallink;
CREATE DATABASE mutuallink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mutuallink;

-- ------------------------------------------------------------
-- users: staff accounts (Manager, Cashier, Loan Officer, Bookkeeper, Board/Auditor)
-- ------------------------------------------------------------
CREATE TABLE users (
  user_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(50)  NOT NULL,
  email          VARCHAR(150) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  full_name      VARCHAR(150) NOT NULL,
  role           ENUM('manager','cashier','loan_officer','bookkeeper','auditor') NOT NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login     DATETIME NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by     INT UNSIGNED NULL,
  updated_at     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_created_by (created_by),
  KEY idx_users_updated_by (updated_by),
  CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
  CONSTRAINT fk_users_updated_by FOREIGN KEY (updated_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- members: profile formerly split between index card and Excel file
-- ------------------------------------------------------------
CREATE TABLE members (
  member_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_no           VARCHAR(20)  NOT NULL,
  last_name           VARCHAR(80)  NOT NULL,
  first_name          VARCHAR(80)  NOT NULL,
  middle_name         VARCHAR(80)  NULL,
  birthdate           DATE NOT NULL,
  civil_status        ENUM('single','married','widowed','separated') NOT NULL,
  address             VARCHAR(255) NOT NULL,
  contact_no          VARCHAR(20)  NULL,
  email               VARCHAR(150) NULL,
  occupation          VARCHAR(100) NULL,
  monthly_income      DECIMAL(12,2) NULL,
  tin                 VARCHAR(20)  NULL,
  sss                 VARCHAR(20)  NULL,
  beneficiaries       TEXT NULL,
  spouse_name         VARCHAR(150) NULL,
  date_of_membership  DATE NOT NULL,
  member_type         ENUM('school','outside') NOT NULL DEFAULT 'school',
  -- Membership steps (questionnaire 2.1–2.3): PMES → form → fee + common shares → approval
  pmes_date           DATE NULL,
  signature_on_file   TINYINT(1) NOT NULL DEFAULT 0,
  membership_fee      DECIMAL(12,2) NULL,
  membership_fee_or   VARCHAR(20) NULL,
  membership_fee_date DATE NULL,
  membership_fee_by   INT UNSIGNED NULL,
  status              ENUM('applicant','active','inactive') NOT NULL DEFAULT 'applicant',
  approved_by         INT UNSIGNED NULL,
  date_approved       DATE NULL,
  created_by          INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by          INT UNSIGNED NULL,
  updated_at          DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (member_id),
  UNIQUE KEY uq_members_member_no (member_no),
  UNIQUE KEY uq_members_fee_or (membership_fee_or),
  KEY idx_members_name (last_name, first_name),
  KEY idx_members_status (status),
  KEY idx_members_fee_date (membership_fee_date),
  KEY idx_members_created_by (created_by),
  KEY idx_members_updated_by (updated_by),
  KEY idx_members_approved_by (approved_by),
  KEY idx_members_fee_by (membership_fee_by),
  CONSTRAINT fk_members_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
  CONSTRAINT fk_members_updated_by FOREIGN KEY (updated_by) REFERENCES users (user_id),
  CONSTRAINT fk_members_approved_by FOREIGN KEY (approved_by) REFERENCES users (user_id),
  CONSTRAINT fk_members_fee_by FOREIGN KEY (membership_fee_by) REFERENCES users (user_id),
  CONSTRAINT chk_members_income CHECK (monthly_income IS NULL OR monthly_income >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- savings_accounts: one per account type per member
-- ------------------------------------------------------------
CREATE TABLE savings_accounts (
  savings_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id     INT UNSIGNED NOT NULL,
  account_type  ENUM('regular_savings','share_capital','capital_build_up','time_deposit') NOT NULL,
  balance       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  date_opened   DATE NOT NULL,
  status        ENUM('active','closed') NOT NULL DEFAULT 'active',
  PRIMARY KEY (savings_id),
  UNIQUE KEY uq_savings_member_type (member_id, account_type),
  KEY idx_savings_type (account_type),
  CONSTRAINT fk_savings_member FOREIGN KEY (member_id) REFERENCES members (member_id),
  CONSTRAINT chk_savings_balance CHECK (balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- savings_transactions: passbook + office ledger in one record
-- ------------------------------------------------------------
CREATE TABLE savings_transactions (
  txn_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  savings_id       INT UNSIGNED NOT NULL,
  txn_date         DATE NOT NULL,
  txn_type         ENUM('deposit','withdrawal','reversal','interest') NOT NULL,
  amount           DECIMAL(12,2) NOT NULL,
  running_balance  DECIMAL(12,2) NOT NULL,
  or_no            VARCHAR(20) NULL,
  posted_by        INT UNSIGNED NOT NULL,
  reverses_txn_id  INT UNSIGNED NULL,
  passbook_presented TINYINT(1) NOT NULL DEFAULT 0,  -- withdrawals require the passbook (questionnaire 3.6)
  remarks          VARCHAR(255) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (txn_id),
  UNIQUE KEY uq_savings_txn_or (or_no),
  UNIQUE KEY uq_savings_txn_reverses (reverses_txn_id),
  KEY idx_savings_txn_account (savings_id, txn_id),
  KEY idx_savings_txn_date (txn_date),
  KEY idx_savings_txn_posted_by (posted_by),
  CONSTRAINT fk_savings_txn_account FOREIGN KEY (savings_id) REFERENCES savings_accounts (savings_id),
  CONSTRAINT fk_savings_txn_user FOREIGN KEY (posted_by) REFERENCES users (user_id),
  CONSTRAINT fk_savings_txn_reverses FOREIGN KEY (reverses_txn_id) REFERENCES savings_transactions (txn_id),
  CONSTRAINT chk_savings_txn_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- loan_products: Regular, Salary, Emergency
-- ------------------------------------------------------------
CREATE TABLE loan_products (
  product_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_name   VARCHAR(80) NOT NULL,
  min_amount     DECIMAL(12,2) NOT NULL,
  max_amount     DECIMAL(12,2) NOT NULL,
  term_months    TINYINT UNSIGNED NOT NULL,
  interest_rate  DECIMAL(5,2) NOT NULL DEFAULT 3.00,
  penalty_rate   DECIMAL(5,2) NOT NULL DEFAULT 4.00,
  -- What limits the loanable amount: a fixed maximum, the collateral's appraised value, or the member's net pay
  loanable_basis ENUM('fixed','collateral','net_pay') NOT NULL DEFAULT 'fixed',
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (product_id),
  UNIQUE KEY uq_products_name (product_name),
  CONSTRAINT chk_products_amounts CHECK (min_amount > 0 AND max_amount >= min_amount),
  CONSTRAINT chk_products_term CHECK (term_months BETWEEN 1 AND 60)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- loans
-- ------------------------------------------------------------
CREATE TABLE loans (
  loan_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id            INT UNSIGNED NOT NULL,
  product_id           INT UNSIGNED NOT NULL,
  principal            DECIMAL(12,2) NOT NULL,
  term_months          TINYINT UNSIGNED NOT NULL,
  date_applied         DATE NOT NULL,
  date_approved        DATE NULL,
  date_released        DATE NULL,
  co_maker             VARCHAR(150) NOT NULL,
  -- Co-maker must be an FFMPC member (clarification A11)
  co_maker_member_id   INT UNSIGNED NULL,
  collateral           VARCHAR(255) NULL,
  collateral_type      ENUM('none','real_estate','vehicle','other') NOT NULL DEFAULT 'none',
  collateral_value     DECIMAL(14,2) NULL,
  net_pay              DECIMAL(12,2) NULL,
  repayment_mode       ENUM('cash','salary_deduction','bank_deposit','field_collection','e_wallet') NOT NULL DEFAULT 'cash',
  outstanding_balance  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_interest       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  net_proceeds         DECIMAL(12,2) NULL,
  release_mode         ENUM('cash','check') NULL,
  check_no             VARCHAR(30) NULL,
  -- Charges collected after the term was surpassed: 3% interest + 4% penalty per month,
  -- computed per day, on the unpaid principal + unpaid interest (clarifications A2, A3)
  pd_interest_paid     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pd_penalty_paid      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status               ENUM('pending','approved','rejected','released','paid','cancelled') NOT NULL DEFAULT 'pending',
  remarks              VARCHAR(255) NULL,
  created_by           INT UNSIGNED NOT NULL,
  approved_by          INT UNSIGNED NULL,
  released_by          INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (loan_id),
  KEY idx_loans_member_status (member_id, status),
  KEY idx_loans_status (status),
  KEY idx_loans_product (product_id),
  KEY idx_loans_created_by (created_by),
  KEY idx_loans_approved_by (approved_by),
  KEY idx_loans_released_by (released_by),
  KEY idx_loans_co_maker (co_maker_member_id),
  CONSTRAINT fk_loans_member FOREIGN KEY (member_id) REFERENCES members (member_id),
  CONSTRAINT fk_loans_product FOREIGN KEY (product_id) REFERENCES loan_products (product_id),
  CONSTRAINT fk_loans_co_maker FOREIGN KEY (co_maker_member_id) REFERENCES members (member_id),
  CONSTRAINT fk_loans_created_by FOREIGN KEY (created_by) REFERENCES users (user_id),
  CONSTRAINT fk_loans_approved_by FOREIGN KEY (approved_by) REFERENCES users (user_id),
  CONSTRAINT fk_loans_released_by FOREIGN KEY (released_by) REFERENCES users (user_id),
  CONSTRAINT chk_loans_principal CHECK (principal > 0),
  CONSTRAINT chk_loans_outstanding CHECK (outstanding_balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- amortization_schedule: one row per installment
-- ------------------------------------------------------------
CREATE TABLE amortization_schedule (
  schedule_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_id         INT UNSIGNED NOT NULL,
  installment_no  TINYINT UNSIGNED NOT NULL,
  due_date        DATE NOT NULL,
  principal_due   DECIMAL(12,2) NOT NULL,
  interest_due    DECIMAL(12,2) NOT NULL,
  total_due       DECIMAL(12,2) NOT NULL,
  balance         DECIMAL(12,2) NOT NULL,
  principal_paid  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  interest_paid   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  penalty_paid    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status          ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  PRIMARY KEY (schedule_id),
  UNIQUE KEY uq_schedule_loan_no (loan_id, installment_no),
  KEY idx_schedule_loan_status (loan_id, status),
  KEY idx_schedule_due (due_date),
  CONSTRAINT fk_schedule_loan FOREIGN KEY (loan_id) REFERENCES loans (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- loan_deductions: withheld before release
-- ------------------------------------------------------------
CREATE TABLE loan_deductions (
  deduction_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_id         INT UNSIGNED NOT NULL,
  deduction_type  ENUM('service_fee','insurance','stockshare','notarial_fee','other_fee','previous_loan') NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  ref_loan_id     INT UNSIGNED NULL,
  PRIMARY KEY (deduction_id),
  KEY idx_deductions_loan (loan_id),
  KEY idx_deductions_ref (ref_loan_id),
  CONSTRAINT fk_deductions_loan FOREIGN KEY (loan_id) REFERENCES loans (loan_id),
  CONSTRAINT fk_deductions_ref FOREIGN KEY (ref_loan_id) REFERENCES loans (loan_id),
  CONSTRAINT chk_deductions_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- payments: each recorded against the installment it settles.
-- One official receipt can settle several installments (advance payment),
-- so the same or_no appears once per installment it covers.
-- ------------------------------------------------------------
CREATE TABLE payments (
  payment_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_id            INT UNSIGNED NOT NULL,
  schedule_id        INT UNSIGNED NOT NULL,
  or_no              VARCHAR(20) NOT NULL,
  payment_date       DATE NOT NULL,
  amount_paid        DECIMAL(12,2) NOT NULL,
  mode               ENUM('cash','salary_deduction','bank_deposit','field_collection','e_wallet','offset') NOT NULL DEFAULT 'cash',
  principal_portion  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  interest_portion   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  penalty_portion    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  past_due_interest  DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- part of interest_portion charged after the term (3%)
  remaining_balance  DECIMAL(12,2) NOT NULL,
  status             ENUM('posted','void') NOT NULL DEFAULT 'posted',
  void_reason        VARCHAR(255) NULL,
  posted_by          INT UNSIGNED NOT NULL,
  voided_by          INT UNSIGNED NULL,
  voided_at          DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (payment_id),
  UNIQUE KEY uq_payments_or_schedule (or_no, schedule_id),
  KEY idx_payments_loan_status (loan_id, status),
  KEY idx_payments_schedule (schedule_id),
  KEY idx_payments_date (payment_date),
  KEY idx_payments_posted_by (posted_by),
  KEY idx_payments_voided_by (voided_by),
  CONSTRAINT fk_payments_loan FOREIGN KEY (loan_id) REFERENCES loans (loan_id),
  CONSTRAINT fk_payments_schedule FOREIGN KEY (schedule_id) REFERENCES amortization_schedule (schedule_id),
  CONSTRAINT fk_payments_posted_by FOREIGN KEY (posted_by) REFERENCES users (user_id),
  CONSTRAINT fk_payments_voided_by FOREIGN KEY (voided_by) REFERENCES users (user_id),
  CONSTRAINT chk_payments_amount CHECK (amount_paid > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- delinquency: aging snapshot computed from unpaid installments
-- ------------------------------------------------------------
CREATE TABLE delinquency (
  delinquency_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_id          INT UNSIGNED NOT NULL,
  days_past_due    INT UNSIGNED NOT NULL,
  aging_bracket    VARCHAR(20) NOT NULL,
  amount_past_due  DECIMAL(12,2) NOT NULL,
  date_generated   DATE NOT NULL,
  PRIMARY KEY (delinquency_id),
  UNIQUE KEY uq_delinquency_loan_date (loan_id, date_generated),
  KEY idx_delinquency_date (date_generated),
  CONSTRAINT fk_delinquency_loan FOREIGN KEY (loan_id) REFERENCES loans (loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- notifications: reminders for approaching/overdue payments
-- ------------------------------------------------------------
CREATE TABLE notifications (
  notification_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id          INT UNSIGNED NOT NULL,
  loan_id            INT UNSIGNED NOT NULL,
  notification_type  ENUM('upcoming','overdue') NOT NULL,
  message            TEXT NOT NULL,
  date_sent          DATETIME NULL,
  status             ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  created_by         INT UNSIGNED NOT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id),
  KEY idx_notifications_member (member_id),
  KEY idx_notifications_loan_type (loan_id, notification_type, status),
  KEY idx_notifications_created_by (created_by),
  CONSTRAINT fk_notifications_member FOREIGN KEY (member_id) REFERENCES members (member_id),
  CONSTRAINT fk_notifications_loan FOREIGN KEY (loan_id) REFERENCES loans (loan_id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- audit_log: insert-only trail of entries and approvals
-- ------------------------------------------------------------
CREATE TABLE audit_log (
  log_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NULL,
  action          VARCHAR(50) NOT NULL,
  table_affected  VARCHAR(50) NOT NULL,
  record_id       INT UNSIGNED NULL,
  details         VARCHAR(500) NULL,
  ip_address      VARCHAR(45) NULL,
  timestamp       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_table (table_affected, record_id),
  KEY idx_audit_timestamp (timestamp),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- settings: rates and defaults editable by the Manager
-- ------------------------------------------------------------
CREATE TABLE settings (
  setting_key    VARCHAR(50)  NOT NULL,
  setting_value  VARCHAR(100) NOT NULL,
  label          VARCHAR(150) NOT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Seed data
-- Default password for every seed account: Password123
-- (bcrypt, cost 12). Change these after the first login.
-- ============================================================
INSERT INTO users (user_id, username, email, password_hash, full_name, role, status) VALUES
(1, 'manager',     'manager@ffmpc.local',     '$2y$12$VC.F3smnNWB/eRMgM3KqouCHJmfQ5Y/yT/BZmptBaJ0RLFvqhKYMa', 'Maria Santos',    'manager',      'active'),
(2, 'cashier',     'cashier@ffmpc.local',     '$2y$12$VC.F3smnNWB/eRMgM3KqouCHJmfQ5Y/yT/BZmptBaJ0RLFvqhKYMa', 'Jose Reyes',      'cashier',      'active'),
(3, 'loanofficer', 'loanofficer@ffmpc.local', '$2y$12$VC.F3smnNWB/eRMgM3KqouCHJmfQ5Y/yT/BZmptBaJ0RLFvqhKYMa', 'Ana Dela Cruz',   'loan_officer', 'active'),
(4, 'bookkeeper',  'bookkeeper@ffmpc.local',  '$2y$12$VC.F3smnNWB/eRMgM3KqouCHJmfQ5Y/yT/BZmptBaJ0RLFvqhKYMa', 'Liza Villanueva', 'bookkeeper',   'active'),
(5, 'auditor',     'auditor@ffmpc.local',     '$2y$12$VC.F3smnNWB/eRMgM3KqouCHJmfQ5Y/yT/BZmptBaJ0RLFvqhKYMa', 'Ramon Bautista',  'auditor',      'active');

-- Products per questionnaire 4.1: Regular (depends on collateral, up to 1 yr), Salary (depends on net pay,
-- up to 1 yr), Emergency (₱3,000, 3 months). All 3% per month diminishing; 4% penalty after the term.
INSERT INTO loan_products (product_name, min_amount, max_amount, term_months, interest_rate, penalty_rate, loanable_basis) VALUES
('Regular Loan',   5000.00, 1000000.00, 12, 3.00, 4.00, 'collateral'),
('Salary Loan',    1000.00,  100000.00, 12, 3.00, 4.00, 'net_pay'),
('Emergency Loan', 1000.00,    3000.00,  3, 3.00, 4.00, 'fixed');

-- Deduction rates are taken from FFMPC's own sample loan computation (₱26,000 loan:
-- service fee 780.00, insurance 145.60, stockshare 520.00, notarial 200.00, printing 30.00).
-- Account minimums are from questionnaire 3.1.
INSERT INTO settings (setting_key, setting_value, label) VALUES
('service_fee_pct',        '3.00',     'Service fee (% of principal)'),
('insurance_pct',          '0.56',     'Loan insurance (% of principal)'),
('stockshare_pct',         '2.00',     'Stockshare retention, credited to share capital (% of principal)'),
('notarial_fee',           '200.00',   'Notarial fee (fixed amount)'),
('other_fee',              '30.00',    'Other fees (printing, fixed amount)'),
('collateral_loanable_pct','30.00',    'Loanable amount as % of the collateral''s appraised value'),
('min_share_capital',      '2000.00',  'Required share capital for membership'),
('min_regular_savings',    '500.00',   'Maintaining balance of regular savings'),
('min_time_deposit',       '10000.00', 'Minimum time deposit'),
('cbu_monthly',            '100.00',   'Capital build-up contribution per month'),
('membership_fee',         '250.00',   'Membership fee (fixed amount, clarification A15)'),
('savings_interest_pct',   '1.00',     'Savings interest: 1% per quarter on regular savings; 1% per term on time deposit (clarification A17)'),
('reminder_lead_days',     '3',        'Send upcoming-payment reminders this many days before due'),
('aging_brackets',         '30,60,90,180,365', 'Aging bracket upper limits in days (comma-separated)'),
('or_counter',             '0',        'Last official receipt number issued (Manager controls the OR series)'),
('member_counter',         '0',        'Last member number issued');

-- ============================================================
-- Least-privilege application account (Database Security topic).
-- The app never connects as root. Change the password here AND in config/db.php.
-- ============================================================
CREATE USER IF NOT EXISTS 'mutuallink_app'@'localhost' IDENTIFIED BY 'Ml!nk_App_2026';
GRANT SELECT, INSERT, UPDATE, DELETE ON mutuallink.* TO 'mutuallink_app'@'localhost';
FLUSH PRIVILEGES;
