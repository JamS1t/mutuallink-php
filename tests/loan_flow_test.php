<?php
declare(strict_types=1);

/**
 * Database-level checks of the money paths: release → pay → void → renewal offset.
 * Runs against a DISPOSABLE copy of the database (never production data):
 *   mysql -u root -p < database/mutuallink.sql      (fresh import)
 *   php tests/loan_flow_test.php
 * Set ML_DB_PORT if MySQL is not on 3306.
 */

require_once __DIR__ . '/../config/bootstrap.php';

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual != $expected) { // loose: DECIMAL columns come back as strings
        $failures++;
        echo "FAIL: $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}
function expect_domain_error(string $name, callable $fn): void
{
    global $failures;
    try {
        $fn();
        $failures++;
        echo "FAIL: $name (no error thrown)\n";
    } catch (DomainException) {
        // expected
    }
}
function val(string $sql, array $p = []): mixed
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
}

$pdo = db();
$_SESSION['user_id'] = 1; // act as the seeded manager for audit columns

// Fixture: member with share capital, a 10,000 / 3-month Regular Loan approved 5 months ago
$pdo->exec("INSERT INTO members (member_no, last_name, first_name, birthdate, civil_status, address, date_of_membership, member_type)
            VALUES ('TEST-0001', 'Tester', 'Flow', '1990-01-01', 'single', 'Test', '2020-01-01', 'school')");
$memberId = (int) $pdo->lastInsertId();
$approved = date('Y-m-d', strtotime('-100 days'));
$pdo->exec("INSERT INTO loans (member_id, product_id, principal, term_months, date_applied, date_approved, co_maker, status, created_by, approved_by)
            VALUES ($memberId, 1, 10000, 3, '$approved', '$approved', 'Co Maker', 'approved', 3, 1)");
$loanId = (int) $pdo->lastInsertId();

// Release 100 days ago → installment 1 is ~70 days late
$orBefore = (int) val("SELECT setting_value FROM settings WHERE setting_key = 'or_counter'");
$rel = release_loan($loanId, $approved, null);
check('net proceeds (10,000 - 600)', $rel['net'], 9400.00);
check('loan released', val('SELECT status FROM loans WHERE loan_id = ?', [$loanId]), 'released');
check('outstanding = principal', val('SELECT outstanding_balance FROM loans WHERE loan_id = ?', [$loanId]), 10000.00);
check('3 installments', val('SELECT COUNT(*) FROM amortization_schedule WHERE loan_id = ?', [$loanId]), 3);
check('total interest', val('SELECT total_interest FROM loans WHERE loan_id = ?', [$loanId]), 600.00);
check('deductions recorded', val('SELECT SUM(amount) FROM loan_deductions WHERE loan_id = ?', [$loanId]), 600.00);
check('CBU credited 200', val("SELECT balance FROM savings_accounts WHERE member_id = ? AND account_type = 'capital_build_up'", [$memberId]), 200.00);
check('release uses no OR number', (int) val("SELECT setting_value FROM settings WHERE setting_key = 'or_counter'"), $orBefore);
expect_domain_error('cannot release twice', fn () => release_loan($loanId, $approved, null));

// Late payment: penalty first. Installment 1 total 3,633.33, ~70 days late → 3 months × 4%
$inst = current_installment($loanId);
$due = installment_due($inst, 4.00, date('Y-m-d'));
$expectedPenalty = compute_penalty(3633.33, 4.00, $inst['due_date'], date('Y-m-d'), 0.0);
check('live penalty', $due['penalty'], $expectedPenalty);
expect_domain_error('overpayment refused', fn () => post_loan_payment($loanId, $due['total'] + 1, date('Y-m-d'), 'cash'));
expect_domain_error('future date refused', fn () => post_loan_payment($loanId, 100, date('Y-m-d', strtotime('+1 day')), 'cash'));

$p1 = post_loan_payment($loanId, 500.00, date('Y-m-d'), 'cash');
check('partial: penalty portion first', $p1['split']['penalty'], min(500.00, $expectedPenalty));
check('installment partial', val('SELECT status FROM amortization_schedule WHERE schedule_id = ?', [$inst['schedule_id']]), 'partial');

$inst = current_installment($loanId);
$due2 = installment_due($inst, 4.00, date('Y-m-d'));
$p2 = post_loan_payment($loanId, $due2['total'], date('Y-m-d'), 'cash');
check('installment 1 paid', val('SELECT status FROM amortization_schedule WHERE schedule_id = ?', [$inst['schedule_id']]), 'paid');
check('outstanding after inst 1', val('SELECT outstanding_balance FROM loans WHERE loan_id = ?', [$loanId]), 6666.67);
check('OR numbers are unique', val('SELECT COUNT(DISTINCT or_no) FROM payments WHERE loan_id = ?', [$loanId]), 2);

// Void: only the latest; restores exactly
expect_domain_error('void older payment refused', fn () => void_payment($p1['payment_id'], 'test'));
void_payment($p2['payment_id'], 'Encoding error');
check('void restores outstanding', val('SELECT outstanding_balance FROM loans WHERE loan_id = ?', [$loanId]), 10000.00);
check('void restores installment to partial', val('SELECT status FROM amortization_schedule WHERE schedule_id = ?', [$inst['schedule_id']]), 'partial');
check('payment marked void', val('SELECT status FROM payments WHERE payment_id = ?', [$p2['payment_id']]), 'void');
expect_domain_error('void twice refused', fn () => void_payment($p2['payment_id'], 'again'));

// Renewal: a new loan offsets the old loan's remaining principal
$pdo->exec("INSERT INTO savings_accounts (member_id, account_type, balance, date_opened) VALUES ($memberId, 'share_capital', 0, CURDATE())");
$pdo->exec("INSERT INTO loans (member_id, product_id, principal, term_months, date_applied, date_approved, co_maker, status, created_by, approved_by)
            VALUES ($memberId, 1, 20000, 6, CURDATE(), CURDATE(), 'Co Maker', 'approved', 3, 1)");
$renewId = (int) $pdo->lastInsertId();
$ren = release_loan($renewId, date('Y-m-d'), $loanId);
check('renewal net = 20,000 - 1,100 fees - 10,000 previous', $ren['net'], 8900.00);
check('old loan paid by offset', val('SELECT status FROM loans WHERE loan_id = ?', [$loanId]), 'paid');
check('old loan outstanding 0', val('SELECT outstanding_balance FROM loans WHERE loan_id = ?', [$loanId]), 0);
check('old schedule all paid', val("SELECT COUNT(*) FROM amortization_schedule WHERE loan_id = ? AND status <> 'paid'", [$loanId]), 0);
expect_domain_error('void after renewal refused', fn () => void_payment($p1['payment_id'], 'late'));
expect_domain_error('payment on paid loan refused', fn () => post_loan_payment($loanId, 100, date('Y-m-d'), 'cash'));

// Clean up fixtures (children first)
$pdo->exec("DELETE p FROM payments p JOIN loans l ON l.loan_id = p.loan_id WHERE l.member_id = $memberId");
$pdo->exec("DELETE s FROM amortization_schedule s JOIN loans l ON l.loan_id = s.loan_id WHERE l.member_id = $memberId");
$pdo->exec("DELETE d FROM loan_deductions d JOIN loans l ON l.loan_id = d.loan_id WHERE l.member_id = $memberId");
$pdo->exec("DELETE t FROM savings_transactions t JOIN savings_accounts a ON a.savings_id = t.savings_id WHERE a.member_id = $memberId");
$pdo->exec("DELETE FROM savings_accounts WHERE member_id = $memberId");
$pdo->exec("DELETE FROM loans WHERE member_id = $memberId");
$pdo->exec("DELETE FROM members WHERE member_id = $memberId");

if ($failures > 0) {
    echo "\n$failures check(s) failed.\n";
    exit(1);
}
echo "All loan flow tests passed.\n";
