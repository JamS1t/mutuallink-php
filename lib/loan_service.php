<?php
declare(strict_types=1);

/**
 * Loan transactions that touch several tables. Each function runs inside ONE
 * database transaction (beginTransaction / commit / rollBack) and locks the
 * rows it changes with SELECT … FOR UPDATE, so a double-click or two cashiers
 * working at once can never apply the same payment twice.
 * Business-rule violations throw DomainException (message is safe to show).
 */

/**
 * What is still owed on one installment as of a date (penalty computed live).
 *
 * @return array{penalty:float,interest:float,principal:float,total:float,days_late:int}
 */
function installment_due(array $inst, float $penaltyRatePct, string $asOf): array
{
    $unpaid = money_round((float) $inst['total_due'] - (float) $inst['principal_paid'] - (float) $inst['interest_paid']);
    $penalty = compute_penalty($unpaid, $penaltyRatePct, $inst['due_date'], $asOf, (float) $inst['penalty_paid']);
    $interest = money_round((float) $inst['interest_due'] - (float) $inst['interest_paid']);
    $principal = money_round((float) $inst['principal_due'] - (float) $inst['principal_paid']);
    $daysLate = max(0, (int) (new DateTimeImmutable($inst['due_date']))->diff(new DateTimeImmutable($asOf))->format('%r%a'));
    return [
        'penalty'   => $penalty,
        'interest'  => $interest,
        'principal' => $principal,
        'total'     => money_round($penalty + $interest + $principal),
        'days_late' => $daysLate,
    ];
}

/** Oldest installment not yet fully paid (optionally locked). */
function current_installment(int $loanId, bool $lock = false): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM amortization_schedule WHERE loan_id = :id AND status <> 'paid' ORDER BY installment_no LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->execute([':id' => $loanId]);
    return $stmt->fetch() ?: null;
}

/**
 * DFD 4.4–4.5: compute interest, generate the schedule, record deductions, release.
 *
 * @return array{net:float, deductions:array}
 */
function release_loan(int $loanId, string $releaseDate, ?int $prevLoanId): array
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
            throw new DomainException('The member is inactive.');
        }
        if ($releaseDate < $loan['date_approved'] || $releaseDate > date('Y-m-d')) {
            throw new DomainException('Release date must be between the approval date and today.');
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
        $d = compute_deductions($principal, [
            'service_fee_pct'   => (float) setting('service_fee_pct'),
            'insurance_pct'     => (float) setting('insurance_pct'),
            'cbu_retention_pct' => (float) setting('cbu_retention_pct'),
            'notarial_fee'      => (float) setting('notarial_fee'),
        ], $prev ? (float) $prev['outstanding_balance'] : 0.0);
        if ($d['net'] <= 0) {
            throw new DomainException('Deductions (' . money($d['total']) . ') leave no net proceeds.');
        }

        // Deductions withheld before release
        $ins = $pdo->prepare('INSERT INTO loan_deductions (loan_id, deduction_type, amount, ref_loan_id) VALUES (:l, :t, :a, :r)');
        foreach (['service_fee', 'insurance', 'cbu_retention', 'notarial_fee', 'previous_loan'] as $type) {
            if ($d[$type] > 0) {
                $ins->execute([':l' => $loanId, ':t' => $type, ':a' => $d[$type], ':r' => $type === 'previous_loan' ? $prev['loan_id'] : null]);
            }
        }

        // CBU retention goes into the member's capital build-up account
        if ($d['cbu_retention'] > 0) {
            $stmt = $pdo->prepare("SELECT savings_id FROM savings_accounts WHERE member_id = :m AND account_type = 'capital_build_up'");
            $stmt->execute([':m' => $loan['member_id']]);
            $cbuId = $stmt->fetchColumn();
            if ($cbuId === false) {
                $pdo->prepare("INSERT INTO savings_accounts (member_id, account_type, balance, date_opened) VALUES (:m, 'capital_build_up', 0, :d)")
                    ->execute([':m' => $loan['member_id'], ':d' => $releaseDate]);
                $cbuId = $pdo->lastInsertId();
            }
            savings_entry((int) $cbuId, 'deposit', $d['cbu_retention'], 1, $releaseDate, "CBU retention from loan #$loanId", null, false);
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
                    $pay->execute([':l' => $prev['loan_id'], ':s' => $inst['schedule_id'], ':or' => 'OFS-' . $loanId . '-' . $inst['installment_no'],
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
                    total_interest = :ti, net_proceeds = :net
              WHERE loan_id = :id AND status = 'approved'"
        );
        $upd->execute([':d' => $releaseDate, ':u' => current_user_id(), ':ti' => $totalInterest, ':net' => $d['net'], ':id' => $loanId]);
        if ($upd->rowCount() !== 1) {
            throw new DomainException('The loan was changed by someone else. Please reload.');
        }
        audit_log('release', 'loans', $loanId, 'Released ' . money($principal) . ' to ' . $loan['member_no'] . '; net proceeds ' . money($d['net']));
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
 * DFD 5.1–5.5: post a payment against the oldest unpaid installment,
 * applied penalty → interest → principal, then issue the OR.
 *
 * @return array{payment_id:int, or_no:string, split:array, remaining:float}
 */
function post_loan_payment(int $loanId, float $amount, string $date, string $mode): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT l.loan_id, l.status, l.outstanding_balance, l.date_released, p.penalty_rate, m.member_no
               FROM loans l JOIN loan_products p ON p.product_id = l.product_id JOIN members m ON m.member_id = l.member_id
              WHERE l.loan_id = :id FOR UPDATE"
        );
        $stmt->execute([':id' => $loanId]);
        $loan = $stmt->fetch();
        if (!$loan || $loan['status'] !== 'released') {
            throw new DomainException('Payments can only be posted to a released loan with a balance.');
        }
        if ($date < $loan['date_released'] || $date > date('Y-m-d')) {
            throw new DomainException('Payment date must be between the release date and today.');
        }
        $inst = current_installment($loanId, true);
        if (!$inst) {
            throw new DomainException('This loan has no unpaid installment.');
        }

        $due = installment_due($inst, (float) $loan['penalty_rate'], $date);
        $split = split_payment($amount, $due['penalty'], $due['interest'], $due['principal']);
        if ($split['excess'] > 0) {
            throw new DomainException('Amount exceeds what is due on installment ' . $inst['installment_no'] . ' (' . money($due['total'])
                . '). Post the extra as a separate payment on the next installment.');
        }

        $fullyPaid = $split['principal'] >= $due['principal'] && $split['interest'] >= $due['interest'];
        $pdo->prepare(
            'UPDATE amortization_schedule
                SET principal_paid = principal_paid + :p, interest_paid = interest_paid + :i, penalty_paid = penalty_paid + :pen, status = :s
              WHERE schedule_id = :id'
        )->execute([':p' => $split['principal'], ':i' => $split['interest'], ':pen' => $split['penalty'],
            ':s' => $fullyPaid ? 'paid' : 'partial', ':id' => $inst['schedule_id']]);

        $remaining = money_round((float) $loan['outstanding_balance'] - $split['principal']);
        $pdo->prepare('UPDATE loans SET outstanding_balance = :b, status = :s WHERE loan_id = :id')
            ->execute([':b' => $remaining, ':s' => $remaining <= 0 ? 'paid' : 'released', ':id' => $loanId]);

        $orNo = next_or_no();
        $pdo->prepare(
            'INSERT INTO payments (loan_id, schedule_id, or_no, payment_date, amount_paid, mode, principal_portion, interest_portion, penalty_portion, remaining_balance, posted_by)
             VALUES (:l, :s, :or, :d, :a, :m, :p, :i, :pen, :rb, :u)'
        )->execute([':l' => $loanId, ':s' => $inst['schedule_id'], ':or' => $orNo, ':d' => $date, ':a' => $amount, ':m' => $mode,
            ':p' => $split['principal'], ':i' => $split['interest'], ':pen' => $split['penalty'], ':rb' => $remaining, ':u' => current_user_id()]);
        $paymentId = (int) $pdo->lastInsertId();

        audit_log('post_payment', 'payments', $paymentId, "$orNo " . money($amount) . " on loan #$loanId inst. " . $inst['installment_no'] . ' (' . $loan['member_no'] . ')');
        $pdo->commit();
        return ['payment_id' => $paymentId, 'or_no' => $orNo, 'split' => $split, 'remaining' => $remaining];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Bookkeeper correction: void the LATEST posted payment of a loan and restore
 * the installment and the outstanding balance exactly.
 */
function void_payment(int $paymentId, string $reason): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE payment_id = :id FOR UPDATE");
        $stmt->execute([':id' => $paymentId]);
        $p = $stmt->fetch();
        if (!$p || $p['status'] !== 'posted' || $p['mode'] === 'offset') {
            throw new DomainException('Only a posted cash payment can be voided.');
        }
        $stmt = $pdo->prepare('SELECT loan_id, status FROM loans WHERE loan_id = :id FOR UPDATE');
        $stmt->execute([':id' => $p['loan_id']]);
        $loan = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT MAX(payment_id) FROM payments WHERE loan_id = :id AND status = 'posted' AND mode <> 'offset'");
        $stmt->execute([':id' => $p['loan_id']]);
        if ((int) $stmt->fetchColumn() !== $paymentId) {
            throw new DomainException('Only the most recent payment of a loan can be voided. Void later payments first.');
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE loan_id = :id AND mode = 'offset'");
        $stmt->execute([':id' => $p['loan_id']]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new DomainException('This loan was settled by a renewal; its payments can no longer be voided.');
        }

        $stmt = $pdo->prepare('SELECT * FROM amortization_schedule WHERE schedule_id = :id FOR UPDATE');
        $stmt->execute([':id' => $p['schedule_id']]);
        $inst = $stmt->fetch();
        $pp = money_round((float) $inst['principal_paid'] - (float) $p['principal_portion']);
        $ip = money_round((float) $inst['interest_paid'] - (float) $p['interest_portion']);
        $pen = money_round((float) $inst['penalty_paid'] - (float) $p['penalty_portion']);
        $status = ($pp <= 0 && $ip <= 0 && $pen <= 0) ? 'unpaid'
            : (($pp >= (float) $inst['principal_due'] && $ip >= (float) $inst['interest_due']) ? 'paid' : 'partial');
        $pdo->prepare('UPDATE amortization_schedule SET principal_paid = :p, interest_paid = :i, penalty_paid = :pen, status = :s WHERE schedule_id = :id')
            ->execute([':p' => max(0, $pp), ':i' => max(0, $ip), ':pen' => max(0, $pen), ':s' => $status, ':id' => $inst['schedule_id']]);

        $pdo->prepare("UPDATE loans SET outstanding_balance = outstanding_balance + :p, status = 'released' WHERE loan_id = :id")
            ->execute([':p' => $p['principal_portion'], ':id' => $p['loan_id']]);

        $pdo->prepare("UPDATE payments SET status = 'void', void_reason = :r, voided_by = :u, voided_at = NOW() WHERE payment_id = :id AND status = 'posted'")
            ->execute([':r' => $reason, ':u' => current_user_id(), ':id' => $paymentId]);

        audit_log('void_payment', 'payments', $paymentId, $p['or_no'] . ' ' . money($p['amount_paid']) . " voided: $reason");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
