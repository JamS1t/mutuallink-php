<?php
declare(strict_types=1);

/**
 * Loan transactions that touch several tables. Each function runs inside ONE
 * database transaction (beginTransaction / commit / rollBack) and locks the
 * rows it changes with SELECT … FOR UPDATE, so a double-click or two cashiers
 * working at once can never apply the same payment twice.
 * Business-rule violations throw DomainException (message is safe to show).
 */

/** Loan row with the product rates needed for payment computations. */
function loan_for_payment(int $loanId, bool $lock = false): ?array
{
    $stmt = db()->prepare(
        "SELECT l.*, p.product_name, p.interest_rate, p.penalty_rate, m.member_no,
                CONCAT(m.last_name, ', ', m.first_name) AS member_name,
                (SELECT MAX(s.due_date) FROM amortization_schedule s WHERE s.loan_id = l.loan_id) AS maturity_date
           FROM loans l JOIN loan_products p ON p.product_id = l.product_id JOIN members m ON m.member_id = l.member_id
          WHERE l.loan_id = :id" . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([':id' => $loanId]);
    return $stmt->fetch() ?: null;
}

/** Installments not yet fully paid, oldest first (optionally locked). */
function unpaid_installments(int $loanId, bool $lock = false): array
{
    $stmt = db()->prepare(
        "SELECT * FROM amortization_schedule WHERE loan_id = :id AND status <> 'paid' ORDER BY installment_no" . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([':id' => $loanId]);
    return $stmt->fetchAll();
}

/** Oldest installment not yet fully paid. */
function current_installment(int $loanId): ?array
{
    return unpaid_installments($loanId)[0] ?? null;
}

/**
 * Everything owed on a loan as of a date: charges after the term (3% + 4%),
 * the current installment, and the full payoff (no rebate on early payment).
 *
 * @return array{pd:array, remaining:list<array>, current:?array, current_total:float, payoff:float, days_past_maturity:int}
 */
function loan_dues(array $loan, array $unpaid, string $asOf): array
{
    $pd = compute_past_due((float) $loan['outstanding_balance'], (float) $loan['interest_rate'], (float) $loan['penalty_rate'],
        (string) $loan['maturity_date'], $asOf, (float) $loan['pd_interest_paid'], (float) $loan['pd_penalty_paid']);

    $remaining = array_map(fn ($s) => [
        'schedule_id'    => (int) $s['schedule_id'],
        'installment_no' => (int) $s['installment_no'],
        'due_date'       => $s['due_date'],
        'interest'       => money_round((float) $s['interest_due'] - (float) $s['interest_paid']),
        'principal'      => money_round((float) $s['principal_due'] - (float) $s['principal_paid']),
    ], $unpaid);

    $charges = money_round($pd['penalty'] + $pd['interest']);
    $current = $remaining[0] ?? null;
    $sumRemaining = array_sum(array_map(fn ($r) => $r['interest'] + $r['principal'], $remaining));
    $days = $loan['maturity_date']
        ? max(0, (int) (new DateTimeImmutable($loan['maturity_date']))->diff(new DateTimeImmutable($asOf))->format('%r%a'))
        : 0;

    return [
        'pd'                 => $pd,
        'remaining'          => $remaining,
        'current'            => $current,
        'current_total'      => money_round($charges + ($current ? $current['interest'] + $current['principal'] : 0)),
        'payoff'             => money_round($charges + $sumRemaining),
        'days_past_maturity' => $days,
    ];
}

/**
 * DFD 6.1–6.2: scan schedules against payments for unpaid installments past due.
 * One grouped query for all loans (no per-member loop).
 *
 * @return list<array{loan_id:int, member_id:int, member_name:string, member_no:string, email:?string,
 *                    oldest_due:string, overdue_count:int, amount_past_due:float, days_past_due:int, maturity_date:string}>
 */
function overdue_loans(string $asOf): array
{
    $stmt = db()->prepare(
        "SELECT l.loan_id, m.member_id, CONCAT(m.last_name, ', ', m.first_name) AS member_name, m.member_no, m.email,
                MIN(s.due_date) AS oldest_due, COUNT(*) AS overdue_count,
                SUM(s.total_due - s.principal_paid - s.interest_paid) AS amount_past_due,
                (SELECT MAX(x.due_date) FROM amortization_schedule x WHERE x.loan_id = l.loan_id) AS maturity_date
           FROM amortization_schedule s
           JOIN loans l   ON l.loan_id = s.loan_id
           JOIN members m ON m.member_id = l.member_id
          WHERE l.status = 'released' AND s.status <> 'paid' AND s.due_date < :d
          GROUP BY l.loan_id, m.member_id, m.last_name, m.first_name, m.member_no, m.email
          ORDER BY oldest_due"
    );
    $stmt->execute([':d' => $asOf]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['days_past_due'] = (int) (new DateTimeImmutable($r['oldest_due']))->diff(new DateTimeImmutable($asOf))->days;
        $r['amount_past_due'] = money_round((float) $r['amount_past_due']);
    }
    return $rows;
}

/**
 * DFD 4.4–4.5: compute interest, generate the schedule, record deductions, release
 * (cash or check, by the Manager).
 *
 * @return array{net:float, deductions:array}
 */
function release_loan(int $loanId, string $releaseDate, ?int $prevLoanId, string $releaseMode = 'cash', ?string $checkNo = null): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT l.*, p.interest_rate, m.status AS member_status, m.member_no
               FROM loans l JOIN loan_products p ON p.product_id = l.product_id JOIN members m ON m.member_id = l.member_id
              WHERE l.loan_id = :id FOR UPDATE"
        );
        $stmt->execute([':id' => $loanId]);
        $loan = $stmt->fetch();
        if (!$loan || $loan['status'] !== 'approved') {
            throw new DomainException('Only an approved loan can be released.');
        }
        if ($loan['member_status'] !== 'active') {
            throw new DomainException('The member is not an active member.');
        }
        if ($releaseDate < $loan['date_approved'] || $releaseDate > date('Y-m-d')) {
            throw new DomainException('Release date must be between the approval date and today.');
        }
        if (!in_array($releaseMode, ['cash', 'check'], true)) {
            throw new DomainException('Release must be in cash or check.');
        }
        if ($releaseMode === 'check' && ($checkNo === null || $checkNo === '')) {
            throw new DomainException('Enter the check number.');
        }

        // Previous loan to be offset (renewal)
        $prev = null;
        if ($prevLoanId) {
            $stmt = $pdo->prepare("SELECT loan_id, outstanding_balance FROM loans WHERE loan_id = :id AND member_id = :m AND status = 'released' FOR UPDATE");
            $stmt->execute([':id' => $prevLoanId, ':m' => $loan['member_id']]);
            $prev = $stmt->fetch();
            if (!$prev) {
                throw new DomainException('The selected previous loan is not an active loan of this member.');
            }
        }

        $principal = (float) $loan['principal'];
        $d = compute_deductions($principal, deduction_rates(), $prev ? (float) $prev['outstanding_balance'] : 0.0);
        if ($d['net'] <= 0) {
            throw new DomainException('Deductions (' . money($d['total']) . ') leave no net proceeds.');
        }

        // Deductions withheld before release
        $ins = $pdo->prepare('INSERT INTO loan_deductions (loan_id, deduction_type, amount, ref_loan_id) VALUES (:l, :t, :a, :r)');
        foreach (['insurance', 'service_fee', 'stockshare', 'notarial_fee', 'other_fee', 'previous_loan'] as $type) {
            if ($d[$type] > 0) {
                $ins->execute([':l' => $loanId, ':t' => $type, ':a' => $d[$type], ':r' => $type === 'previous_loan' ? $prev['loan_id'] : null]);
            }
        }

        // Stockshare is added to the member's share capital
        if ($d['stockshare'] > 0) {
            $stmt = $pdo->prepare("SELECT savings_id FROM savings_accounts WHERE member_id = :m AND account_type = 'share_capital'");
            $stmt->execute([':m' => $loan['member_id']]);
            $scId = $stmt->fetchColumn();
            if ($scId === false) {
                $pdo->prepare("INSERT INTO savings_accounts (member_id, account_type, balance, date_opened) VALUES (:m, 'share_capital', 0, :d)")
                    ->execute([':m' => $loan['member_id'], ':d' => $releaseDate]);
                $scId = $pdo->lastInsertId();
            }
            savings_entry((int) $scId, 'deposit', $d['stockshare'], 1, $releaseDate, "Stockshare from loan #$loanId", null, false);
        }

        // Offset the previous loan: remaining principal is settled from this loan; unearned future interest is waived.
        if ($prev) {
            $stmt = $pdo->prepare("SELECT * FROM amortization_schedule WHERE loan_id = :id AND status <> 'paid' ORDER BY installment_no FOR UPDATE");
            $stmt->execute([':id' => $prev['loan_id']]);
            $running = (float) $prev['outstanding_balance'];
            $pay = $pdo->prepare(
                "INSERT INTO payments (loan_id, schedule_id, or_no, payment_date, amount_paid, mode, principal_portion, remaining_balance, posted_by)
                 VALUES (:l, :s, :or, :d, :a, 'offset', :a2, :rb, :u)"
            );
            $close = $pdo->prepare("UPDATE amortization_schedule SET principal_paid = principal_due, status = 'paid' WHERE schedule_id = :id");
            foreach ($stmt->fetchAll() as $inst) {
                $rem = money_round((float) $inst['principal_due'] - (float) $inst['principal_paid']);
                if ($rem > 0) {
                    $running = money_round($running - $rem);
                    $pay->execute([':l' => $prev['loan_id'], ':s' => $inst['schedule_id'], ':or' => 'OFS-' . $loanId,
                        ':d' => $releaseDate, ':a' => $rem, ':a2' => $rem, ':rb' => max(0, $running), ':u' => current_user_id()]);
                }
                $close->execute([':id' => $inst['schedule_id']]);
            }
            $pdo->prepare("UPDATE loans SET outstanding_balance = 0, status = 'paid' WHERE loan_id = :id")->execute([':id' => $prev['loan_id']]);
            audit_log('offset', 'loans', (int) $prev['loan_id'], 'Settled by renewal loan #' . $loanId . ' (' . money($d['previous_loan']) . ')');
        }

        // Amortization schedule (equal principal, 3% on the diminishing balance)
        $rows = build_schedule($principal, (int) $loan['term_months'], (float) $loan['interest_rate'], $releaseDate);
        $ins = $pdo->prepare(
            'INSERT INTO amortization_schedule (loan_id, installment_no, due_date, principal_due, interest_due, total_due, balance)
             VALUES (:l, :n, :d, :p, :i, :t, :b)'
        );
        foreach ($rows as $r) {
            $ins->execute([':l' => $loanId, ':n' => $r['installment_no'], ':d' => $r['due_date'], ':p' => $r['principal_due'],
                ':i' => $r['interest_due'], ':t' => $r['total_due'], ':b' => $r['balance']]);
        }
        $totalInterest = money_round(array_sum(array_column($rows, 'interest_due')));

        $upd = $pdo->prepare(
            "UPDATE loans SET status = 'released', date_released = :d, released_by = :u, outstanding_balance = principal,
                    total_interest = :ti, net_proceeds = :net, release_mode = :rm, check_no = :chk
              WHERE loan_id = :id AND status = 'approved'"
        );
        $upd->execute([':d' => $releaseDate, ':u' => current_user_id(), ':ti' => $totalInterest, ':net' => $d['net'],
            ':rm' => $releaseMode, ':chk' => $releaseMode === 'check' ? $checkNo : null, ':id' => $loanId]);
        if ($upd->rowCount() !== 1) {
            throw new DomainException('The loan was changed by someone else. Please reload.');
        }
        audit_log('release', 'loans', $loanId, 'Released ' . money($principal) . ' to ' . $loan['member_no'] . ' by ' . $releaseMode
            . ($releaseMode === 'check' ? " #$checkNo" : '') . '; net proceeds ' . money($d['net']));
        $pdo->commit();
        return ['net' => $d['net'], 'deductions' => $d];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * DFD 5.1–5.5: post one payment and issue ONE official receipt. The amount is applied to
 * charges after the term, then to installments oldest first (interest, then principal).
 * Paying more than one installment settles the next ones in advance; paying everything
 * closes the loan early. There is no rebate.
 *
 * @return array{payment_id:int, or_no:string, lines:array, remaining:float}
 */
function post_loan_payment(int $loanId, float $amount, string $date, string $mode): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $loan = loan_for_payment($loanId, true);
        if (!$loan || $loan['status'] !== 'released') {
            throw new DomainException('Payments can only be posted to a released loan with a balance.');
        }
        if ($date < $loan['date_released'] || $date > date('Y-m-d')) {
            throw new DomainException('Payment date must be between the release date and today.');
        }
        $unpaid = unpaid_installments($loanId, true);
        if (!$unpaid) {
            throw new DomainException('This loan has no unpaid installment.');
        }

        $dues = loan_dues($loan, $unpaid, $date);
        $alloc = allocate_payment($amount, $dues['pd']['penalty'], $dues['pd']['interest'], $dues['remaining']);
        if ($alloc['excess'] > 0) {
            throw new DomainException('Amount is more than the full payoff of ' . money($dues['payoff']) . '.');
        }

        $orNo = next_or_no();
        $byId = array_column($unpaid, null, 'schedule_id');
        $running = (float) $loan['outstanding_balance'];
        $updInst = $pdo->prepare('UPDATE amortization_schedule SET principal_paid = :p, interest_paid = :i, status = :s WHERE schedule_id = :id');
        $insPay = $pdo->prepare(
            'INSERT INTO payments (loan_id, schedule_id, or_no, payment_date, amount_paid, mode, principal_portion, interest_portion,
                                   penalty_portion, past_due_interest, remaining_balance, posted_by)
             VALUES (:l, :s, :or, :d, :a, :m, :p, :i, :pen, :pdi, :rb, :u)'
        );
        $firstId = 0;
        $totPrincipal = $totPdInterest = $totPenalty = 0.0;
        foreach ($alloc['lines'] as $line) {
            $inst = $byId[$line['schedule_id']];
            $pp = money_round((float) $inst['principal_paid'] + $line['principal']);
            $ip = money_round((float) $inst['interest_paid'] + $line['interest']);
            $status = ($pp >= (float) $inst['principal_due'] && $ip >= (float) $inst['interest_due']) ? 'paid' : (($pp > 0 || $ip > 0) ? 'partial' : 'unpaid');
            $updInst->execute([':p' => $pp, ':i' => $ip, ':s' => $status, ':id' => $line['schedule_id']]);

            $running = money_round($running - $line['principal']);
            $lineTotal = money_round($line['penalty'] + $line['pd_interest'] + $line['interest'] + $line['principal']);
            $insPay->execute([':l' => $loanId, ':s' => $line['schedule_id'], ':or' => $orNo, ':d' => $date, ':a' => $lineTotal, ':m' => $mode,
                ':p' => $line['principal'], ':i' => money_round($line['interest'] + $line['pd_interest']), ':pen' => $line['penalty'],
                ':pdi' => $line['pd_interest'], ':rb' => max(0, $running), ':u' => current_user_id()]);
            $firstId = $firstId ?: (int) $pdo->lastInsertId();
            $totPrincipal += $line['principal'];
            $totPdInterest += $line['pd_interest'];
            $totPenalty += $line['penalty'];
        }

        $remaining = money_round((float) $loan['outstanding_balance'] - $totPrincipal);
        $pdo->prepare(
            'UPDATE loans SET outstanding_balance = :b, status = :s, pd_interest_paid = pd_interest_paid + :pdi, pd_penalty_paid = pd_penalty_paid + :pen
              WHERE loan_id = :id'
        )->execute([':b' => $remaining, ':s' => $remaining <= 0 ? 'paid' : 'released', ':pdi' => money_round($totPdInterest),
            ':pen' => money_round($totPenalty), ':id' => $loanId]);

        audit_log('post_payment', 'payments', $firstId, "OR $orNo " . money($amount) . " on loan #$loanId covering " . count($alloc['lines'])
            . ' installment(s) (' . $loan['member_no'] . ')');
        $pdo->commit();
        return ['payment_id' => $firstId, 'or_no' => $orNo, 'lines' => $alloc['lines'], 'remaining' => $remaining];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Bookkeeper correction: void the LATEST official receipt posted on a loan (every
 * installment line it covered) and restore the installments, the balance, and the
 * after-term charges exactly.
 */
function void_payment(int $paymentId, string $reason): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT loan_id, or_no, status, mode FROM payments WHERE payment_id = :id');
        $stmt->execute([':id' => $paymentId]);
        $ref = $stmt->fetch();
        if (!$ref || $ref['status'] !== 'posted' || $ref['mode'] === 'offset') {
            throw new DomainException('Only a posted payment can be voided.');
        }
        $stmt = $pdo->prepare('SELECT loan_id, status FROM loans WHERE loan_id = :id FOR UPDATE'); // blocks new postings meanwhile
        $stmt->execute([':id' => $ref['loan_id']]);
        $loan = $stmt->fetch();
        if (!$loan || !in_array($loan['status'], ['released', 'paid'], true)) {
            throw new DomainException('Payments of this loan can no longer be voided.');
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE loan_id = :id AND mode = 'offset'");
        $stmt->execute([':id' => $ref['loan_id']]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new DomainException('This loan was settled by a renewal; its payments can no longer be voided.');
        }
        $stmt = $pdo->prepare("SELECT or_no FROM payments WHERE loan_id = :id AND status = 'posted' ORDER BY payment_id DESC LIMIT 1");
        $stmt->execute([':id' => $ref['loan_id']]);
        if ($stmt->fetchColumn() !== $ref['or_no']) {
            throw new DomainException('Only the most recent receipt of a loan can be voided. Void later receipts first.');
        }

        $stmt = $pdo->prepare("SELECT * FROM payments WHERE loan_id = :l AND or_no = :or AND status = 'posted' FOR UPDATE");
        $stmt->execute([':l' => $ref['loan_id'], ':or' => $ref['or_no']]);
        $rows = $stmt->fetchAll();

        $getInst = $pdo->prepare('SELECT * FROM amortization_schedule WHERE schedule_id = :id FOR UPDATE');
        $updInst = $pdo->prepare('UPDATE amortization_schedule SET principal_paid = :p, interest_paid = :i, status = :s WHERE schedule_id = :id');
        $principal = $pdInterest = $penalty = 0.0;
        foreach ($rows as $p) {
            $getInst->execute([':id' => $p['schedule_id']]);
            $inst = $getInst->fetch();
            $pp = max(0, money_round((float) $inst['principal_paid'] - (float) $p['principal_portion']));
            $ip = max(0, money_round((float) $inst['interest_paid'] - ((float) $p['interest_portion'] - (float) $p['past_due_interest'])));
            $status = ($pp <= 0 && $ip <= 0) ? 'unpaid'
                : (($pp >= (float) $inst['principal_due'] && $ip >= (float) $inst['interest_due']) ? 'paid' : 'partial');
            $updInst->execute([':p' => $pp, ':i' => $ip, ':s' => $status, ':id' => $inst['schedule_id']]);
            $principal += (float) $p['principal_portion'];
            $pdInterest += (float) $p['past_due_interest'];
            $penalty += (float) $p['penalty_portion'];
        }

        $pdo->prepare(
            "UPDATE loans SET outstanding_balance = outstanding_balance + :p, status = 'released',
                    pd_interest_paid = GREATEST(0, pd_interest_paid - :pdi), pd_penalty_paid = GREATEST(0, pd_penalty_paid - :pen)
              WHERE loan_id = :id"
        )->execute([':p' => money_round($principal), ':pdi' => money_round($pdInterest), ':pen' => money_round($penalty), ':id' => $ref['loan_id']]);

        $pdo->prepare("UPDATE payments SET status = 'void', void_reason = :r, voided_by = :u, voided_at = NOW()
                        WHERE loan_id = :l AND or_no = :or AND status = 'posted'")
            ->execute([':r' => $reason, ':u' => current_user_id(), ':l' => $ref['loan_id'], ':or' => $ref['or_no']]);

        $total = array_sum(array_map(fn ($p) => (float) $p['amount_paid'], $rows));
        audit_log('void_payment', 'payments', $paymentId, 'OR ' . $ref['or_no'] . ' ' . money($total) . " voided: $reason");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
