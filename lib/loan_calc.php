<?php
declare(strict_types=1);

/**
 * Loan computations (pure functions — no database access).
 * Rules follow FFMC practice as recorded in the design spec §6:
 *  - 3% per month on the diminishing balance, equal principal per month
 *  - 4% per month penalty on the unpaid amount of a late installment
 *  - payments applied to penalty, then interest, then principal
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
 * Penalty still owed on an installment as of a date.
 * months_late = ceil(days_late / 30); penalty = unpaid × rate × months_late − penalty already paid.
 */
function compute_penalty(float $unpaidDue, float $penaltyRatePct, string $dueDate, string $asOf, float $penaltyPaid): float
{
    if ($unpaidDue <= 0) {
        return 0.00;
    }
    $daysLate = (int) (new DateTimeImmutable($dueDate))->diff(new DateTimeImmutable($asOf))->format('%r%a');
    if ($daysLate <= 0) {
        return 0.00;
    }
    $monthsLate = (int) ceil($daysLate / 30);
    $penalty = money_round($unpaidDue * $penaltyRatePct / 100 * $monthsLate) - $penaltyPaid;
    return max(0.00, money_round($penalty));
}

/**
 * Applies an amount to penalty, then interest, then principal.
 * Anything beyond the three is returned as "excess" (the caller rejects it).
 *
 * @return array{penalty:float,interest:float,principal:float,excess:float}
 */
function split_payment(float $amount, float $penaltyDue, float $interestDue, float $principalDue): array
{
    $remaining = money_round($amount);

    $penalty = min($remaining, money_round($penaltyDue));
    $remaining = money_round($remaining - $penalty);

    $interest = min($remaining, money_round($interestDue));
    $remaining = money_round($remaining - $interest);

    $principal = min($remaining, money_round($principalDue));
    $remaining = money_round($remaining - $principal);

    return [
        'penalty'   => money_round($penalty),
        'interest'  => money_round($interest),
        'principal' => money_round($principal),
        'excess'    => $remaining,
    ];
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
 * Deductions withheld before release, and the resulting net proceeds.
 *
 * @param array{service_fee_pct:float,insurance_pct:float,cbu_retention_pct:float,notarial_fee:float} $rates
 * @return array{service_fee:float,insurance:float,cbu_retention:float,notarial_fee:float,previous_loan:float,total:float,net:float}
 */
function compute_deductions(float $principal, array $rates, float $previousLoanBalance): array
{
    $d = [
        'service_fee'   => money_round($principal * $rates['service_fee_pct'] / 100),
        'insurance'     => money_round($principal * $rates['insurance_pct'] / 100),
        'cbu_retention' => money_round($principal * $rates['cbu_retention_pct'] / 100),
        'notarial_fee'  => money_round($rates['notarial_fee']),
        'previous_loan' => money_round($previousLoanBalance),
    ];
    $d['total'] = money_round(array_sum($d));
    $d['net'] = money_round($principal - $d['total']);
    return $d;
}
