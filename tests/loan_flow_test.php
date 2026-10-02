<?php
declare(strict_types=1);

/**
 * Database-level checks of the money paths: release → pay (after term, advance, payoff)
 * → void → renewal offset, plus the savings minimums.
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

// Fixture: active member; ₱10,000 / 3-month Regular Loan approved and released 100 days ago,
// so the 3-month term ended about a week ago.
$pdo->exec("INSERT INTO members (member_no, last_name, first_name, birthdate, civil_status, address, date_of_membership, member_type, status)
            VALUES ('TEST-0001', 'Tester', 'Flow', '1990-01-01', 'single', 'Test', '2020-01-01', 'school', 'active')");
$memberId = (int) $pdo->lastInsertId();
$released = date('Y-m-d', strtotime('-100 days'));
$pdo->exec("INSERT INTO loans (member_id, product_id, principal, term_months, date_applied, date_approved, co_maker, status, created_by, approved_by)
            VALUES ($memberId, 1, 10000, 3, '$released', '$released', 'Co Maker', 'approved', 3, 1)");
$loanId = (int) $pdo->lastInsertId();

// Release: deductions = insurance 56 + service fee 300 + stockshare 200 + notarial 200 + printing 30 = 786
$orBefore = (int) val("SELECT setting_value FROM settings WHERE setting_key = 'or_counter'");
expect_domain_error('check release needs check number', fn () => release_loan($loanId, $released, null, 'check', ''));
$rel = release_loan($loanId, $released, null, 'check', 'CHK-1001');
check('net proceeds', $rel['net'], 9214.00);
check('released by check', val('SELECT release_mode FROM loans WHERE loan_id = ?', [$loanId]), 'check');
check('deductions recorded', val('SELECT SUM(amount) FROM loan_deductions WHERE loan_id = ?', [$loanId]), 786.00);
check('stockshare credited to share capital', val("SELECT balance FROM savings_accounts WHERE member_id = ? AND account_type = 'share_capital'", [$memberId]), 200.00);
check('release uses no OR number', (int) val("SELECT setting_value FROM settings WHERE setting_key = 'or_counter'"), $orBefore);
check('3 installments, total interest 600', val('SELECT SUM(interest_due) FROM amortization_schedule WHERE loan_id = ?', [$loanId]), 600.00);

// Term is surpassed: charges accrue PER DAY (A2) on the unpaid principal + unpaid
// interest (A3) = 10,000 + 600 = 10,600, at 3%/30 and 4%/30 per day
$today = date('Y-m-d');
$maturity = (string) val('SELECT MAX(due_date) FROM amortization_schedule WHERE loan_id = ?', [$loanId]);
$daysPast = (int) (new DateTimeImmutable($maturity))->diff(new DateTimeImmutable($today))->days;
$dues = loan_dues(loan_for_payment($loanId), unpaid_installments($loanId), $today);
check('after-term days', $dues['pd']['days'], $daysPast);
check('after-term interest', $dues['pd']['interest'], money_round(10600 * 3 / 100 / 30 * $daysPast));
check('after-term penalty', $dues['pd']['penalty'], money_round(10600 * 4 / 100 / 30 * $daysPast));
check('payoff = charges + 600 interest + 10,000', $dues['payoff'],
    money_round($dues['pd']['penalty'] + $dues['pd']['interest'] + 600 + 10000));
expect_domain_error('more than payoff refused', fn () => post_loan_payment($loanId, $dues['payoff'] + 0.01, $today, 'cash'));
expect_domain_error('future date refused', fn () => post_loan_payment($loanId, 100, date('Y-m-d', strtotime('+1 day')), 'cash'));

// Partial payment ₱500: penalty first, then after-term interest, then the installment
$pen = (float) $dues['pd']['penalty'];
$pdi = (float) $dues['pd']['interest'];
$p1Pdi = min(money_round(500.00 - $pen), $pdi);
$p1 = post_loan_payment($loanId, 500.00, $today, 'cash');
check('p1: penalty first', $p1['lines'][0]['penalty'], $pen);
check('p1: after-term interest second', $p1['lines'][0]['pd_interest'], $p1Pdi);
check('loan pd_penalty_paid', val('SELECT pd_penalty_paid FROM loans WHERE loan_id = ?', [$loanId]), min(500.00, $pen));
check('loan pd_interest_paid', val('SELECT pd_interest_paid FROM loans WHERE loan_id = ?', [$loanId]), $p1Pdi);

// Full payoff with ONE official receipt covering all 3 installments (no rebate)
$dues = loan_dues(loan_for_payment($loanId), unpaid_installments($loanId), $today);
$sumRem = (float) val('SELECT SUM(total_due - principal_paid - interest_paid) FROM amortization_schedule WHERE loan_id = ?', [$loanId]);
check('remaining payoff', $dues['payoff'], money_round($dues['pd']['penalty'] + $dues['pd']['interest'] + $sumRem));
$p2 = post_loan_payment($loanId, $dues['payoff'], $today, 'cash');
check('p2 covers 3 installments', count($p2['lines']), 3);
check('one OR, three lines', val('SELECT COUNT(*) FROM payments WHERE or_no = ?', [$p2['or_no']]), 3);
check('loan paid', val('SELECT status FROM loans WHERE loan_id = ?', [$loanId]), 'paid');
check('outstanding 0', val('SELECT outstanding_balance FROM loans WHERE loan_id = ?', [$loanId]), 0);
check('all installments paid', val("SELECT COUNT(*) FROM amortization_schedule WHERE loan_id = ? AND status <> 'paid'", [$loanId]), 0);

// Void: only the latest receipt; restores everything it covered. The earlier
// partial payment (p1) is a separate, still-posted receipt — its principal
// stays deducted and its installment stays partial.
$p1Principal = array_sum(array_map(fn ($l) => (float) $l['principal'], $p1['lines']));
expect_domain_error('void older receipt refused', fn () => void_payment($p1['payment_id'], 'test'));
void_payment($p2['payment_id'], 'Encoding error');
check('void restores outstanding (p1 partial payment kept)', val('SELECT outstanding_balance FROM loans WHERE loan_id = ?', [$loanId]), money_round(10000.00 - $p1Principal));
check('void reopens loan', val('SELECT status FROM loans WHERE loan_id = ?', [$loanId]), 'released');
check('void restores installments not covered by p1', val("SELECT COUNT(*) FROM amortization_schedule WHERE loan_id = ? AND status = 'unpaid'", [$loanId]), 2);
check('void keeps p1 partial on installment 1', val("SELECT status FROM amortization_schedule WHERE loan_id = ? AND installment_no = 1", [$loanId]), 'partial');
check('void keeps p1 charges', val('SELECT pd_interest_paid FROM loans WHERE loan_id = ?', [$loanId]), $p1Pdi);
check('all 3 lines void', val("SELECT COUNT(*) FROM payments WHERE or_no = ? AND status = 'void'", [$p2['or_no']]), 3);
expect_domain_error('void twice refused', fn () => void_payment($p2['payment_id'], 'again'));

// Advance payment: more than one installment rolls into the next
$p3 = post_loan_payment($loanId, 4000.00, $today, 'cash'); // 200 pd interest, then inst 1 (300 + 3333.33), then 166.67 of inst 2 interest
check('advance covers 2 installments', count($p3['lines']), 2);
check('installment 1 paid', val('SELECT status FROM amortization_schedule WHERE loan_id = ? AND installment_no = 1', [$loanId]), 'paid');
check('installment 2 partial', val('SELECT status FROM amortization_schedule WHERE loan_id = ? AND installment_no = 2', [$loanId]), 'partial');

// Renewal: the new loan offsets the old loan's remaining principal PLUS its interest due (A7)
$pdo->exec("INSERT INTO loans (member_id, product_id, principal, term_months, date_applied, date_approved, co_maker, status, created_by, approved_by)
            VALUES ($memberId, 1, 20000, 6, CURDATE(), CURDATE(), 'Co Maker', 'approved', 3, 1)");
$renewId = (int) $pdo->lastInsertId();
$prevBalance = (float) val('SELECT outstanding_balance FROM loans WHERE loan_id = ?', [$loanId]);
$prevInterest = (float) val("SELECT COALESCE(SUM(interest_due - interest_paid), 0) FROM amortization_schedule WHERE loan_id = ? AND status <> 'paid'", [$loanId]);
$ren = release_loan($renewId, $today, $loanId);
check('renewal net = 20,000 - 1,342 fees - previous principal - interest due', $ren['net'], money_round(20000 - 1342 - $prevBalance - $prevInterest));
check('old loan paid by offset', val('SELECT status FROM loans WHERE loan_id = ?', [$loanId]), 'paid');
check('old schedule all paid', val("SELECT COUNT(*) FROM amortization_schedule WHERE loan_id = ? AND status <> 'paid'", [$loanId]), 0);
expect_domain_error('void after renewal refused', fn () => void_payment($p3['payment_id'], 'late'));
expect_domain_error('payment on paid loan refused', fn () => post_loan_payment($loanId, 100, $today, 'cash'));

// Savings minimums (regular savings ₱500 maintaining; withdrawals need the passbook)
$pdo->exec("INSERT INTO savings_accounts (member_id, account_type, balance, date_opened) VALUES ($memberId, 'regular_savings', 0, CURDATE())");
$rs = (int) $pdo->lastInsertId();
expect_domain_error('opening deposit below 500 refused', fn () => savings_entry($rs, 'deposit', 400, 1, $today));
savings_entry($rs, 'deposit', 1000, 1, $today);
expect_domain_error('withdrawal without passbook refused', fn () => savings_entry($rs, 'withdrawal', 100, -1, $today));
expect_domain_error('withdrawal below maintaining refused', fn () => savings_entry($rs, 'withdrawal', 600, -1, $today, null, null, true, true));
$w = savings_entry($rs, 'withdrawal', 500, -1, $today, null, null, true, true);
check('withdraw down to maintaining balance', $w['balance'], 500.00);

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
