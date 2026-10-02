-- ============================================================
-- MutualLink patch: FFMC clarification answers (October 2026)
-- Apply to an EXISTING mutuallink database with:
--   mysql -u root -p mutuallink < patch_ffmpc_answers.sql
-- (A fresh install of mutuallink.sql already includes all of this.)
-- ============================================================
USE mutuallink;

-- A11: the co-maker must be an FFMPC member
ALTER TABLE loans
  ADD COLUMN co_maker_member_id INT UNSIGNED NULL AFTER co_maker,
  ADD KEY idx_loans_co_maker (co_maker_member_id),
  ADD CONSTRAINT fk_loans_co_maker FOREIGN KEY (co_maker_member_id) REFERENCES members (member_id);

-- A17: savings interest posting (regular savings quarterly, time deposit per term)
ALTER TABLE savings_transactions
  MODIFY txn_type ENUM('deposit','withdrawal','reversal','interest') NOT NULL;

-- A15 + A17 settings (ignored when they already exist)
INSERT IGNORE INTO settings (setting_key, setting_value, label) VALUES
('membership_fee',       '250.00', 'Membership fee (fixed amount, clarification A15)'),
('savings_interest_pct', '1.00',   'Savings interest: 1% per quarter on regular savings; 1% per term on time deposit (clarification A17)');
