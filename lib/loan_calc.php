<?php
declare(strict_types=1);

/**
 * Loan computations (pure functions — no database access).
 * Rules follow FFMPC practice (questionnaire + the cooperative's own sample computation):
 *  - 3% per month on the diminishing balance, equal principal per month
 *  - once the loan term is surpassed, the unpaid balance is charged
 *    3% interest + 4% penalty = 7% per month (a partial month counts as one)
 *  - payments applied to penalty, then interest, then principal
 *  - advance or early payment: no rebate, nothing changes
 */

function money_round(float $value): float
{
    return round($value, 2); // PHP_ROUND_HALF_UP
}

/**
 * Adds $n months to a date, keeping the same day of month and clamping to the
 * month's last day (Jan 31 + 1 month = Feb 28).
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
 * Amortization schedule: equal principal, interest on the remaining balance.
 * The rounding remainder of the principal goes to the last installment.
 *
 * @return list<array{installment_no:int,due_date:string,principal_due:float,interest_due:float,total_due:float,balance:float}>
 */
function build_schedule(float $principal, int $months, float $ratePct, string $releaseDate): array
{
    $rows = [];
    $balance = money_round($principal);
    $equalPrincipal = money_round($principal / $months);

    for ($i = 1; $i <= $months; $i++) {
        $principalDue = ($i === $months) ? $balance : $equalPrincipal;
        $interestDue = money_round($balance * $ratePct / 100);
        $balance = money_round($balance - $principalDue);
        $rows[] = [
            'installment_no' => $i,
            'due_date'       => add_months_clamped($releaseDate, $i),
            'principal_due'  => $principalDue,
            'interest_due'   => $interestDue,
            'total_due'      => money_round($principalDue + $interestDue),
            'balance'        => $balance,
        ];
    }
    return $rows;
}

/**
 * Charges owed once the loan term has been surpassed (FFMPC: "Past due 3% + Penalty 4% = 7%").
 * For every month or part of a month after maturity, the unpaid principal is charged
 * interest (3%) and penalty (4%). Amounts already collected are subtracted.
 * Note: charged on the balance as of today; if principal is paid down mid-way the
 * earlier months are recomputed on the lower balance (never below what was collected).
 * Switch to month-by-month accrual rows if FFMPC needs exact historical balances.
 *
 * @return array{months:int, interest:float, penalty:float}
 */
function compute_past_due(float $unpaidPrincipal, float $interestPct, float $penaltyPct, string $maturityDate,
                          string $asOf, float $interestPaid = 0.0, float $penaltyPaid = 0.0): array
{
    $days = (int) (new DateTimeImmutable($maturityDate))->diff(new DateTimeImmutable($asOf))->format('%r%a');
    if ($days <= 0 || $unpaidPrincipal <= 0) {
        return ['months' => 0, 'interest' => 0.00, 'penalty' => 0.00];
    }
    $months = (int) ceil($days / 30);
    return [
        'months'   => $months,
        'interest' => max(0.00, money_round(money_round($unpaidPrincipal * $interestPct / 100 * $months) - $interestPaid)),
        'penalty'  => max(0.00, money_round(money_round($unpaidPrincipal * $penaltyPct / 100 * $months) - $penaltyPaid)),
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
