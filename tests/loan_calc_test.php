<?php
declare(strict_types=1);

/**
 * Assert-based checks for lib/loan_calc.php.
 * Run: php tests/loan_calc_test.php
 */

require_once __DIR__ . '/../lib/loan_calc.php';

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL: $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

// 1. FFMPC's own sample computation: ₱26,000, 9 months, 3% diminishing.
//    Principal 2,888.89 per month; interest 780.00, 693.33, 606.67, ... 86.67; total interest 3,900.00.
$s = build_schedule(26000.00, 9, 3.00, '2026-07-15');
check('sample: 9 installments', count($s), 9);
check('sample: principal per month', $s[0]['principal_due'], 2888.89);
check('sample: interest month 1', $s[0]['interest_due'], 780.00);
check('sample: interest month 2', $s[1]['interest_due'], 693.33);
check('sample: interest month 3', $s[2]['interest_due'], 606.67);
check('sample: interest month 4', $s[3]['interest_due'], 520.00);
check('sample: interest month 9', $s[8]['interest_due'], 86.67);
check('sample: total month 1', $s[0]['total_due'], 3668.89);
check('sample: total interest', round(array_sum(array_column($s, 'interest_due')), 2), 3900.00);
check('sample: principal sums to loan', round(array_sum(array_column($s, 'principal_due')), 2), 26000.00);
check('sample: last balance zero', $s[8]['balance'], 0.00);
check('sample: semi-monthly of month 2 (3,582.22)', semi_monthly($s[1]['total_due']), 1791.11);

// FFMPC deductions on the same loan: insurance 145.60, service fee 780.00, stockshare 520.00,
// notarial 200.00, printing 30.00 → total 1,675.60, net proceeds 24,324.40
$rates = ['service_fee_pct' => 3.00, 'insurance_pct' => 0.56, 'stockshare_pct' => 2.00, 'notarial_fee' => 200.00, 'other_fee' => 30.00];
$d = compute_deductions(26000.00, $rates, 0.00);
check('sample: insurance', $d['insurance'], 145.60);
check('sample: service fee', $d['service_fee'], 780.00);
check('sample: stockshare', $d['stockshare'], 520.00);
check('sample: notarial', $d['notarial_fee'], 200.00);
check('sample: printing', $d['other_fee'], 30.00);
check('sample: total deductions', $d['total'], 1675.60);
check('sample: net proceeds', $d['net'], 24324.40);
check('deductions with previous loan', compute_deductions(26000.00, $rates, 5000.50)['net'], 19323.90);

// 2. Handwritten example: ₱50,000 / 12 months → 4,166.67 + 1,500 interest = 5,666.67; balance 45,833.33
$h = build_schedule(50000.00, 12, 3.00, '2025-12-22');
check('note: first total', $h[0]['total_due'], 5666.67);
check('note: balance after 1', $h[0]['balance'], 45833.33);
check('note: maturity date', $h[11]['due_date'], '2026-12-22');

// Due dates clamp to month end
$c = build_schedule(10000.00, 3, 3.00, '2026-01-31');
check('due date clamps Feb', $c[0]['due_date'], '2026-02-28');
check('due date back to 31', $c[1]['due_date'], '2026-03-31');
check('rounding remainder on last principal', $c[2]['principal_due'], 3333.34);

// 3. After the term: 3% interest + 4% penalty = 7% per month (partial month counts)
check('not past maturity', compute_past_due(10000, 3, 4, '2026-01-31', '2026-01-31'), ['months' => 0, 'interest' => 0.00, 'penalty' => 0.00]);
check('1 day past = 1 month', compute_past_due(10000, 3, 4, '2026-01-31', '2026-02-01'), ['months' => 1, 'interest' => 300.00, 'penalty' => 400.00]);
check('30 days = 1 month', compute_past_due(10000, 3, 4, '2026-01-31', '2026-03-02'), ['months' => 1, 'interest' => 300.00, 'penalty' => 400.00]);
check('43 days = 2 months', compute_past_due(10000, 3, 4, '2026-01-31', '2026-03-15'), ['months' => 2, 'interest' => 600.00, 'penalty' => 800.00]);
check('already collected is subtracted', compute_past_due(10000, 3, 4, '2026-01-31', '2026-03-15', 600.00, 300.00), ['months' => 2, 'interest' => 0.00, 'penalty' => 500.00]);
check('nothing unpaid, nothing charged', compute_past_due(0, 3, 4, '2026-01-31', '2026-06-01'), ['months' => 0, 'interest' => 0.00, 'penalty' => 0.00]);

// 4. Payment allocation: penalty → past-due interest → each installment (interest, principal)
$inst = [
    ['schedule_id' => 11, 'interest' => 300.00, 'principal' => 3333.33],
    ['schedule_id' => 12, 'interest' => 200.00, 'principal' => 3333.33],
];
$a = allocate_payment(500.00, 0.0, 0.0, $inst);
check('partial: interest then principal', $a['lines'], [['schedule_id' => 11, 'penalty' => 0.00, 'pd_interest' => 0.00, 'interest' => 300.00, 'principal' => 200.00]]);
check('partial: no excess', $a['excess'], 0.00);

$a = allocate_payment(4000.00, 0.0, 0.0, $inst);
check('advance: rolls into next installment', $a['lines'][1], ['schedule_id' => 12, 'penalty' => 0.00, 'pd_interest' => 0.00, 'interest' => 200.00, 'principal' => 166.67]);

$a = allocate_payment(7166.66, 0.0, 0.0, $inst);
check('early full payoff (no rebate): no excess', $a['excess'], 0.00);
check('early full payoff: last principal', $a['lines'][1]['principal'], 3333.33);

$a = allocate_payment(8000.00, 0.0, 0.0, $inst);
check('more than everything owed → excess', $a['excess'], 833.34);

$a = allocate_payment(900.00, 400.00, 300.00, $inst);
check('past-due charges first', $a['lines'][0], ['schedule_id' => 11, 'penalty' => 400.00, 'pd_interest' => 300.00, 'interest' => 200.00, 'principal' => 0.00]);

$a = allocate_payment(250.00, 400.00, 300.00, $inst);
check('only part of the penalty', $a['lines'], [['schedule_id' => 11, 'penalty' => 250.00, 'pd_interest' => 0.00, 'interest' => 0.00, 'principal' => 0.00]]);

// 5. Aging brackets (upper bounds 30,60,90,180,365)
$b = [30, 60, 90, 180, 365];
check('aging 0', aging_bracket(0, $b), '');
check('aging 1', aging_bracket(1, $b), '1-30');
check('aging 30', aging_bracket(30, $b), '1-30');
check('aging 31', aging_bracket(31, $b), '31-60');
check('aging 90', aging_bracket(90, $b), '61-90');
check('aging 91', aging_bracket(91, $b), '91-180');
check('aging 365', aging_bracket(365, $b), '181-365');
check('aging 366', aging_bracket(366, $b), 'Over 365');
check('parse brackets', parse_brackets('30, 60,90,180,365'), $b);
check('parse brackets rejects junk', parse_brackets('abc'), $b);

if ($failures > 0) {
    echo "\n$failures check(s) failed.\n";
    exit(1);
}
echo "All loan_calc tests passed.\n";
