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

// 1. Equal-principal diminishing-balance schedule (spec §6.1 example)
$s = build_schedule(10000.00, 3, 3.00, '2026-01-31');
check('schedule length', count($s), 3);
check('inst 1 total', $s[0]['total_due'], 3633.33);
check('inst 2 total', $s[1]['total_due'], 3533.33);
check('inst 3 total', $s[2]['total_due'], 3433.34);
check('inst 1 interest', $s[0]['interest_due'], 300.00);
check('inst 3 principal (remainder)', $s[2]['principal_due'], 3333.34);
check('total interest', round(array_sum(array_column($s, 'interest_due')), 2), 600.00);
check('principal sums to loan', round(array_sum(array_column($s, 'principal_due')), 2), 10000.00);
check('last balance zero', $s[2]['balance'], 0.00);
check('balance after 1', $s[0]['balance'], 6666.67);
check('due date clamps Feb', $s[0]['due_date'], '2026-02-28');
check('due date back to 31', $s[1]['due_date'], '2026-03-31');
check('due date Apr 30', $s[2]['due_date'], '2026-04-30');
check('installment numbers', array_column($s, 'installment_no'), [1, 2, 3]);

// 1-month loan: all principal in one installment
$one = build_schedule(5000.00, 1, 3.00, '2026-05-15');
check('1-month total', $one[0]['total_due'], 5150.00);
check('1-month due', $one[0]['due_date'], '2026-06-15');

// 2. Penalty: 4% per month late, partial month counts as a month
check('penalty 40 days', compute_penalty(3633.33, 4.00, '2026-02-28', '2026-04-09', 0.00), 290.67);
check('penalty not yet due', compute_penalty(3633.33, 4.00, '2026-02-28', '2026-02-28', 0.00), 0.00);
check('penalty exactly 30 days = 1 month', compute_penalty(1000.00, 4.00, '2026-03-01', '2026-03-31', 0.00), 40.00);
check('penalty 31 days = 2 months', compute_penalty(1000.00, 4.00, '2026-03-01', '2026-04-01', 0.00), 80.00);
check('penalty minus already paid', compute_penalty(1000.00, 4.00, '2026-03-01', '2026-04-01', 50.00), 30.00);
check('penalty never negative', compute_penalty(1000.00, 4.00, '2026-03-01', '2026-03-05', 90.00), 0.00);
check('penalty zero when nothing unpaid', compute_penalty(0.00, 4.00, '2026-03-01', '2026-06-01', 0.00), 0.00);

// 3. Payment application: penalty -> interest -> principal
check('split partial', split_payment(500.00, 100.00, 300.00, 3333.33),
    ['penalty' => 100.00, 'interest' => 300.00, 'principal' => 100.00, 'excess' => 0.00]);
check('split only covers penalty', split_payment(60.00, 100.00, 300.00, 3333.33),
    ['penalty' => 60.00, 'interest' => 0.00, 'principal' => 0.00, 'excess' => 0.00]);
check('split overpay', split_payment(4000.00, 100.00, 300.00, 3333.33),
    ['penalty' => 100.00, 'interest' => 300.00, 'principal' => 3333.33, 'excess' => 266.67]);
check('split exact', split_payment(3733.33, 100.00, 300.00, 3333.33),
    ['penalty' => 100.00, 'interest' => 300.00, 'principal' => 3333.33, 'excess' => 0.00]);

// 4. Aging brackets (upper bounds 30,60,90,180,365)
$b = [30, 60, 90, 180, 365];
check('aging 0', aging_bracket(0, $b), '');
check('aging 1', aging_bracket(1, $b), '1-30');
check('aging 30', aging_bracket(30, $b), '1-30');
check('aging 31', aging_bracket(31, $b), '31-60');
check('aging 60', aging_bracket(60, $b), '31-60');
check('aging 61', aging_bracket(61, $b), '61-90');
check('aging 90', aging_bracket(90, $b), '61-90');
check('aging 91', aging_bracket(91, $b), '91-180');
check('aging 180', aging_bracket(180, $b), '91-180');
check('aging 181', aging_bracket(181, $b), '181-365');
check('aging 365', aging_bracket(365, $b), '181-365');
check('aging 366', aging_bracket(366, $b), 'Over 365');
check('parse brackets', parse_brackets('30, 60,90,180,365'), $b);
check('parse brackets rejects junk', parse_brackets('abc'), $b);

// 5. Deductions at release
$rates = ['service_fee_pct' => 2.00, 'insurance_pct' => 1.00, 'cbu_retention_pct' => 2.00, 'notarial_fee' => 100.00];
$d = compute_deductions(10000.00, $rates, 0.00);
check('deduction service fee', $d['service_fee'], 200.00);
check('deduction insurance', $d['insurance'], 100.00);
check('deduction cbu', $d['cbu_retention'], 200.00);
check('deduction notarial', $d['notarial_fee'], 100.00);
check('deduction total', $d['total'], 600.00);
check('deduction net', $d['net'], 9400.00);
$d2 = compute_deductions(10000.00, $rates, 2500.50);
check('deduction with previous loan', $d2['net'], 6899.50);

if ($failures > 0) {
    echo "\n$failures check(s) failed.\n";
    exit(1);
}
echo "All loan_calc tests passed.\n";
