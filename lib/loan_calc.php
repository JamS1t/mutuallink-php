<?php
declare(strict_types=1);

/**
 * Loan computations (pure functions — no database access).
 * Rules follow FFMPC practice (questionnaire + the cooperative's own sample computation,
 * as confirmed on the answered clarification sheet, Oct 2026):
 *  - 3% per month on the diminishing balance, equal principal per month
 *  - every monthly amount is rounded DOWN to the nearest ₱0.05, like the Excel sheet
 *  - once the loan term is surpassed, the unpaid principal + unpaid interest is charged
 *    3% interest + 4% penalty computed PER DAY (the monthly rate spread over 30 days)
 *  - payments applied to penalty, then interest, then principal
 *  - advance or early payment: no rebate, nothing changes
 *  - members paying by salary deduction fall due on the payroll dates (the 30th of each
 *    month, clamped to the month's last day); the deduction list splits each month in half
 */

/** Rounds DOWN to the nearest ₱0.05, like FFMPC's Excel sheet (clarification A1). */
function round05(float $value): float
{
    $cents = (int) round($value * 100);
    return floor($cents / 5) * 5 / 100;
}

function money_round(float $value): float
{
    return round($value, 2); // PHP_ROUND_HALF_UP
}

/**
 * Due date of installment $n for a loan released on $releaseDate: same day of month,
 * clamped to the month's last day (Jan 31 + 1 month = Feb 28).
 */
function add_months_clamped(string $date, int $n): string
{
    $base = new DateTimeImmutable($date);
    $day = (int) $base->format('d');
    $firstOfTarget = $base->modify('first day of this month')->modify("+$n months");
    $lastDay = (int) $firstOfTarget->format('t');
    return $firstOfTarget->setDate(
        (int) $firstOfTarget->format('Y'),
        (int) $firstOfTarget->format('m'),
        min($day, $lastDay)
    )->format('Y-m-d');
}

/**
 * Payroll date of installment $n (clarification A14): the 30th of the month,
 * clamped to the month's last day — Feb has no 30th, and the 31st is not a payroll day.
 */
function payroll_due_date(string $releaseDate, int $n): string
{
    $base = new DateTimeImmutable($releaseDate);
    $firstOfTarget = $base->modify('first day of this month')->modify("+$n months");
    $lastDay = (int) $firstOfTarget->format('t');
    return $firstOfTarget->setDate(
        (int) $firstOfTarget->format('Y'),
        (int) $firstOfTarget->format('m'),
        min(30, $lastDay)
    )->format('Y-m-d');
}

/**
 * Amortization schedule: equal principal, interest on the remaining balance.
 * Monthly amounts (principal, interest, total) are rounded DOWN to ₱0.05 like
 * FFMPC's Excel sheet; the rounding remainder of the principal goes to the last
 * installment, which carries the exact balance.
 *
 * @return list<array{installment_no:int,due_date:string,principal_due:float,interest_due:float,total_due:float,balance:float}>
 */
function build_schedule(float $principal, int $months, float $ratePct, string $releaseDate, bool $payrollDue = false): array
{
    $rows = [];
    $balance = money_round($principal);
    $equalPrincipal = round05($principal / $months);

    for ($i = 1; $i <= $months; $i++) {
        $principalDue = ($i === $months) ? $balance : $equalPrincipal;
        $interestDue = round05($balance * $ratePct / 100);
        $balance = money_round($balance - $principalDue);
        $rows[] = [
            'installment_no' => $i,
            'due_date'       => $payrollDue ? payroll_due_date($releaseDate, $i) : add_months_clamped($releaseDate, $i),
            'principal_due'  => $principalDue,
            'interest_due'   => $interestDue,
            'total_due'      => money_round($principalDue + $interestDue),
            'balance'        => $balance,
        ];
    }
    return $rows;
}

/**
 * Charges owed once the loan term has been surpassed (FFMPC: "Past due 3% + Penalty 4%").
 * Per the clarification sheet (A2, A3): after the term ends the charge is computed PER DAY
 * — the monthly rates are spread over 30 days — on the unpaid principal PLUS the unpaid
 * interest. Amounts already collected are subtracted.
 * Note: charged on the balance as of today; if the loan is paid down mid-way the
 * earlier days are recomputed on the lower base (never below what was collected).
 *
 * @return array{days:int, interest:float, penalty:float}
 */
function compute_past_due(float $base, float $interestPct, float $penaltyPct, string $maturityDate,
                          string $asOf, float $interestPaid = 0.0, float $penaltyPaid = 0.0): array
{
    $days = (int) (new DateTimeImmutable($maturityDate))->diff(new DateTimeImmutable($asOf))->format('%r%a');
    if ($days <= 0 || $base <= 0) {
        return ['days' => 0, 'interest' => 0.00, 'penalty' => 0.00];
    }
    return [
        'days'     => $days,
        'interest' => max(0.00, money_round(money_round($base * $interestPct / 100 / 30 * $days) - $interestPaid)),
        'penalty'  => max(0.00, money_round(money_round($base * $penaltyPct / 100 / 30 * $days) - $penaltyPaid)),
    ];
}

/**
 * Applies one payment in FFMPC's order: past-due penalty, past-due interest, then each
 * installment oldest first (its interest, then its principal). An amount larger than one
 * installment rolls into the next ones (advance payment — no rebate, nothing changes).
 * Past-due charges are attached to the first installment line.
 *
 * @param list<array{schedule_id:int, interest:float, principal:float}> $installments  what is still owed, oldest first
 * @return array{lines: list<array{schedule_id:int, penalty:float, pd_interest:float, interest:float, principal:float}>, excess: float}
 */
function allocate_payment(float $amount, float $pdPenalty, float $pdInterest, array $installments): array
{
    $left = money_round($amount);
    $take = function (float $due) use (&$left): float {
        $t = min($left, money_round($due));
        $left = money_round($left - $t);
        return money_round($t);
    };

    $lines = [];
    $penalty = $take($pdPenalty);
    $pdInt = $take($pdInterest);
    foreach ($installments as $i => $inst) {
        if ($left <= 0 && ($i > 0 || ($penalty == 0 && $pdInt == 0))) {
            break;
        }
        $interest = $take((float) $inst['interest']);
        $principal = $take((float) $inst['principal']);
        $lines[] = [
            'schedule_id' => (int) $inst['schedule_id'],
            'penalty'     => $i === 0 ? $penalty : 0.00,
            'pd_interest' => $i === 0 ? $pdInt : 0.00,
            'interest'    => $interest,
            'principal'   => $principal,
        ];
    }
    return ['lines' => $lines, 'excess' => $left];
}

/**
 * Salary Loan limit (FFMPC): a member may borrow only what one month's salary
 * can pay — the first-month installment (equal principal + interest) must not
 * exceed the monthly net pay. Returns the largest principal that satisfies
 * this, in centavos (0.00 when no principal fits the given term).
 */
function max_principal_for_net_pay(float $netPay, int $months, float $ratePct): float
{
    $denom = 1 / $months + $ratePct / 100;
    if ($denom <= 0 || $netPay <= 0) {
        return 0.00;
    }
    $p = floor($netPay / $denom * 100) / 100;
    while ($p > 0 && build_schedule($p, $months, $ratePct, '2000-01-01')[0]['total_due'] > $netPay) {
        $p = money_round($p - 0.01);
    }
    return max(0.00, $p);
}

/** Semi-monthly share of a monthly amount (salary deduction on the 15th and 30th). */
function semi_monthly(float $monthly): float
{
    return money_round($monthly / 2);
}

/**
 * Parses the "30,60,90,180,365" setting into ascending upper bounds.
 * Falls back to the default brackets if the setting is malformed.
 *
 * @return list<int>
 */
function parse_brackets(string $setting): array
{
    $default = [30, 60, 90, 180, 365];
    $parts = array_map('trim', explode(',', $setting));
    $bounds = [];
    foreach ($parts as $p) {
        if (!ctype_digit($p) || (int) $p < 1) {
            return $default;
        }
        $bounds[] = (int) $p;
    }
    $sorted = $bounds;
    sort($sorted);
    return ($sorted === $bounds && count(array_unique($bounds)) === count($bounds)) ? $bounds : $default;
}

/**
 * Aging bracket label for days past due ('' when not past due).
 *
 * @param list<int> $bounds ascending upper limits, e.g. [30,60,90,180,365]
 */
function aging_bracket(int $daysPastDue, array $bounds): string
{
    if ($daysPastDue <= 0) {
        return '';
    }
    $lower = 1;
    foreach ($bounds as $upper) {
        if ($daysPastDue <= $upper) {
            return $lower . '-' . $upper;
        }
        $lower = $upper + 1;
    }
    return 'Over ' . end($bounds);
}

/**
 * Deductions withheld before release, and the resulting net proceeds — the same lines as
 * FFMPC's "Summary of loan computation": loan insurance, service fee, stockshare, notarial
 * fee, others (printing), plus the balance of a previous loan when it is renewed.
 *
 * @param array{service_fee_pct:float,insurance_pct:float,stockshare_pct:float,notarial_fee:float,other_fee:float} $rates
 * @return array{service_fee:float,insurance:float,stockshare:float,notarial_fee:float,other_fee:float,previous_loan:float,total:float,net:float}
 */
function compute_deductions(float $principal, array $rates, float $previousLoanBalance): array
{
    $d = [
        'service_fee'   => money_round($principal * $rates['service_fee_pct'] / 100),
        'insurance'     => money_round($principal * $rates['insurance_pct'] / 100),
        'stockshare'    => money_round($principal * $rates['stockshare_pct'] / 100),
        'notarial_fee'  => money_round($rates['notarial_fee']),
        'other_fee'     => money_round($rates['other_fee']),
        'previous_loan' => money_round($previousLoanBalance),
    ];
    $d['total'] = money_round(array_sum($d));
    $d['net'] = money_round($principal - $d['total']);
    return $d;
}
