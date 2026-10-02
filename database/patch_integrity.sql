-- ============================================================
-- MutualLink patch: data integrity + email sending + login throttle
-- (October 2026)
-- Apply ONCE to an EXISTING mutuallink database, as root (phpMyAdmin →
-- Import, or:  mysql -u root -p mutuallink < patch_integrity.sql).
-- (A fresh import of mutuallink.sql already includes all of this.)
--
-- BEFORE applying, check the one-loan-per-product rule can be enforced —
-- the unique index below refuses to build if the data already holds two
-- RUNNING loans of the same product for one member:
--   SELECT member_id, product_id, COUNT(*) FROM loans
--    WHERE status = 'released'
--    GROUP BY member_id, product_id HAVING COUNT(*) > 1;
-- ============================================================
USE mutuallink;

-- ------------------------------------------------------------
-- Clarification A12 in the DATABASE, not only the form: a member holds at
-- most one RUNNING (released) loan of each product. active_loan_flag is 1
-- only while the loan is running and NULL otherwise; unique keys ignore
-- NULL rows, so finished/cancelled loans never conflict. A renewal is safe:
-- the offset marks the previous loan 'paid' before the new loan is released.
-- The violation surfaces in the app as a friendly DomainException
-- (lib/loan_service.php maps the duplicate-key error).
-- ------------------------------------------------------------
ALTER TABLE loans
  ADD COLUMN active_loan_flag TINYINT(1)
    GENERATED ALWAYS AS (IF(status = 'released', 1, NULL)) STORED;
ALTER TABLE loans
  ADD UNIQUE KEY uq_loans_one_active (member_id, product_id, active_loan_flag);

-- ------------------------------------------------------------
-- Structured savings-interest period (clarification A17): replaces the
-- free-text remarks dedup in Savings Interest. Existing 'Interest …'
-- remarks are migrated into the column; the unique key guarantees an
-- account can never be posted twice for the same period/term.
-- ------------------------------------------------------------
ALTER TABLE savings_transactions
  ADD COLUMN interest_period VARCHAR(20) NULL AFTER remarks;
UPDATE savings_transactions
   SET interest_period = SUBSTRING(remarks, 10, 20)
 WHERE txn_type = 'interest'
   AND remarks LIKE 'Interest %';
ALTER TABLE savings_transactions
  ADD UNIQUE KEY uq_savings_txn_period (savings_id, interest_period);

-- ------------------------------------------------------------
-- Reminder emails: where the sender records the SMTP error of a failed
-- send, so one bad address never aborts the batch.
-- ------------------------------------------------------------
ALTER TABLE notifications
  ADD COLUMN send_error VARCHAR(255) NULL AFTER date_sent;

-- ------------------------------------------------------------
-- DB-backed login throttle: 5 failed sign-ins per identifier+IP lock the
-- pair out for 15 minutes. Unlike the old session counter, clearing
-- cookies no longer bypasses it.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  identifier    VARCHAR(150) NOT NULL,          -- username or email as submitted
  ip            VARCHAR(45)  NOT NULL,
  failures      INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until  DATETIME NULL,
  last_attempt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (identifier, ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- SMTP settings for the reminder emails. The Manager fills them in
-- Reminders → Email settings (blank password = keep the saved one).
-- For Gmail: 2-step verification + an App Password (16 letters) —
-- see README §7.
-- ------------------------------------------------------------
INSERT IGNORE INTO settings (setting_key, setting_value, label) VALUES
('smtp_host', '', 'SMTP server for reminder emails (e.g. smtp.gmail.com)'),
('smtp_port', '587', 'SMTP port (587 STARTTLS, or 465 SSL)'),
('smtp_user', '', 'SMTP username (the full Gmail address)'),
('smtp_pass', '', 'SMTP app password — never the sign-in password'),
('smtp_from', '', 'From address for reminder emails (the same Gmail address)');
