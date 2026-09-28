<?php
declare(strict_types=1);

// DFD 7.0 — routine reports generated from the recorded transactions (read-only).
$reports = [
    'daily'   => ['report_daily', 'Daily collection', 'fas fa-cash-register'],
    'loans'   => ['report_loans', 'Loan status', 'fas fa-hand-holding-usd'],
    'aging'   => ['report_aging', 'Delinquency & aging', 'fas fa-exclamation-triangle'],
    'savings' => ['report_savings', 'Savings', 'fas fa-piggy-bank'],
    'share'   => ['report_share', 'Share capital', 'fas fa-landmark'],
    'salary'  => ['report_salary', 'Salary deduction list', 'fas fa-money-check'],
];
$allowed = array_filter($reports, fn ($r) => can($r[0]));
if (!$allowed) {
    throw new ForbiddenException();
}
$type = array_key_exists($_GET['type'] ?? '', $allowed) ? $_GET['type'] : array_key_first($allowed);
$title = 'Reports · ' . $allowed[$type][1];
$subtitle = 'Generated from the same records used for daily transactions. Use Excel/CSV to export or Print for a paper copy.';
$pdo = db();
?>
<div class="card no-print">
  <div class="card-body py-2">
    <ul class="nav nav-pills">
      <?php foreach ($allowed as $k => [, $label, $icon]): ?>
        <li class="nav-item"><a href="dashboard.php?page=reports&type=<?= $k ?>" class="nav-link <?= $type === $k ? 'active bg-primary' : '' ?>"><i class="<?= $icon ?> mr-1"></i><?= e($label) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<?php if ($type === 'daily'):
    $date = get_date('date', date('Y-m-d'));
    $stmt = $pdo->prepare(
        "SELECT p.or_no, p.created_at, 'Loan payment' AS kind, CONCAT('Loan #', p.loan_id) AS ref, p.mode,
                CONCAT(m.last_name, ', ', m.first_name) AS member_name, p.amount_paid AS cash_in, 0 AS cash_out, u.full_name AS cashier
           FROM payments p JOIN loans l ON l.loan_id = p.loan_id JOIN members m ON m.member_id = l.member_id JOIN users u ON u.user_id = p.posted_by
          WHERE p.payment_date = :d1 AND p.status = 'posted' AND p.mode <> 'offset'
         UNION ALL
         SELECT t.or_no, t.created_at, CONCAT(UPPER(LEFT(t.txn_type,1)), SUBSTRING(t.txn_type,2)) AS kind,
                REPLACE(a.account_type, '_', ' ') AS ref, 'cash' AS mode,
                CONCAT(m.last_name, ', ', m.first_name), IF(t.txn_type = 'deposit', t.amount, 0), IF(t.txn_type = 'withdrawal', t.amount, 0), u.full_name
           FROM savings_transactions t JOIN savings_accounts a ON a.savings_id = t.savings_id JOIN members m ON m.member_id = a.member_id JOIN users u ON u.user_id = t.posted_by
          WHERE t.txn_date = :d2 AND t.or_no IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM savings_transactions r WHERE r.reverses_txn_id = t.txn_id)
         UNION ALL
         SELECT m.membership_fee_or, CAST(m.membership_fee_date AS DATETIME), 'Membership fee', m.member_no, 'cash',
                CONCAT(m.last_name, ', ', m.first_name), m.membership_fee, 0, u.full_name
           FROM members m JOIN users u ON u.user_id = m.membership_fee_by
          WHERE m.membership_fee_date = :d3 AND m.membership_fee_or IS NOT NULL
          ORDER BY or_no"
    );
    $stmt->execute([':d1' => $date, ':d2' => $date, ':d3' => $date]);
    $rows = $stmt->fetchAll();
    $in = array_sum(array_column($rows, 'cash_in'));
    $out = array_sum(array_column($rows, 'cash_out'));
    $byCashier = [];
    foreach ($rows as $r) {
        $byCashier[$r['cashier']] = ($byCashier[$r['cashier']] ?? 0) + (float) $r['cash_in'] - (float) $r['cash_out'];
    }
?>
  <div class="card">
    <div class="card-header d-flex flex-wrap align-items-center">
      <form method="get" action="dashboard.php" class="form-inline no-print">
        <input type="hidden" name="page" value="reports"><input type="hidden" name="type" value="daily">
        <label for="date" class="mr-2">Date</label>
        <input type="date" id="date" name="date" class="form-control form-control-sm mr-2" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>">
        <button class="btn btn-sm btn-outline-primary">Show</button>
      </form>
      <h3 class="card-title ml-auto">Daily collection report · <?= e(fmt_date($date, 'F j, Y')) ?></h3>
    </div>
    <div class="card-body">
      <div class="stat-strip mb-3">
        <div class="stat"><div class="l">Cash in</div><div class="v"><?= e(money($in)) ?></div></div>
        <div class="stat"><div class="l">Cash out (withdrawals)</div><div class="v"><?= e(money($out)) ?></div></div>
        <div class="stat"><div class="l">Net for the day</div><div class="v text-primary"><?= e(money($in - $out)) ?></div></div>
        <div class="stat"><div class="l">Receipts issued</div><div class="v"><?= count($rows) ?></div></div>
        <?php foreach ($byCashier as $c => $net): ?><div class="stat"><div class="l"><?= e($c) ?></div><div class="v"><?= e(money($net)) ?></div></div><?php endforeach; ?>
      </div>
      <table class="table table-sm table-hover js-datatable" data-export="true" data-title="FFMPC Daily Collection <?= e($date) ?>" data-order='[[0,"asc"]]' data-page-length="100">
        <thead><tr><th>OR no.</th><th>Time</th><th>Member</th><th>Transaction</th><th>Reference</th><th>Mode</th><th class="num">Cash in</th><th class="num">Cash out</th><th>Posted by</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['or_no']) ?></td><td><?= e(date('g:i A', strtotime($r['created_at']))) ?></td><td><?= e($r['member_name']) ?></td><td><?= e($r['kind']) ?></td>
            <td><?= e(ucwords($r['ref'])) ?></td><td><?= e(label($r['mode'])) ?></td><td class="num"><?= (float) $r['cash_in'] ? e(money($r['cash_in'])) : '' ?></td>
            <td class="num"><?= (float) $r['cash_out'] ? e(money($r['cash_out'])) : '' ?></td><td class="small"><?= e($r['cashier']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="font-weight-bold"><td colspan="6">Totals</td><td class="num"><?= e(money($in)) ?></td><td class="num"><?= e(money($out)) ?></td><td></td></tr></tfoot>
      </table>
      <p class="small text-muted mb-0">Reversed savings entries are excluded. Loan renewals (offsets) are not cash and are excluded.</p>
    </div>
  </div>

<?php elseif ($type === 'loans'):
    $summary = $pdo->query(
        "SELECT p.product_name, l.status, COUNT(*) AS n, SUM(l.principal) AS principal, SUM(l.outstanding_balance) AS outstanding
           FROM loans l JOIN loan_products p ON p.product_id = l.product_id
          GROUP BY p.product_name, l.status ORDER BY p.product_name, FIELD(l.status, 'pending','approved','released','paid','rejected','cancelled')"
    )->fetchAll();
    $releasedLoans = $pdo->query(
        "SELECT l.loan_id, CONCAT(m.last_name, ', ', m.first_name) AS member_name, m.member_no, p.product_name, l.principal, l.date_released,
                l.term_months, l.outstanding_balance,
                (SELECT COUNT(*) FROM amortization_schedule s WHERE s.loan_id = l.loan_id AND s.status = 'paid') AS paid_inst,
                (SELECT MIN(due_date) FROM amortization_schedule s WHERE s.loan_id = l.loan_id AND s.status <> 'paid') AS next_due
           FROM loans l JOIN members m ON m.member_id = l.member_id JOIN loan_products p ON p.product_id = l.product_id
          WHERE l.status = 'released' ORDER BY member_name"
    )->fetchAll();
?>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Loan status by product</h3></div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <thead><tr><th>Product</th><th>Status</th><th class="num">Loans</th><th class="num">Principal</th><th class="num">Outstanding</th></tr></thead>
        <tbody>
        <?php foreach ($summary as $s): ?>
          <tr><td><?= e($s['product_name']) ?></td><td><?= badge($s['status']) ?></td><td class="num"><?= (int) $s['n'] ?></td><td class="num"><?= e(money($s['principal'])) ?></td><td class="num"><?= e(money($s['outstanding'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$summary): ?><tr><td colspan="5" class="text-muted text-center">No loans yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Released (active) loans</h3></div>
    <div class="card-body">
      <table class="table table-sm table-hover js-datatable" data-export="true" data-title="FFMPC Active Loans <?= e(date('Y-m-d')) ?>" data-order='[[1,"asc"]]'>
        <thead><tr><th>Loan</th><th>Member</th><th>Product</th><th>Released</th><th class="num">Principal</th><th>Paid inst.</th><th>Next due</th><th class="num">Outstanding</th></tr></thead>
        <tbody>
        <?php foreach ($releasedLoans as $a): ?>
          <tr><td>#<?= (int) $a['loan_id'] ?></td><td><?= e($a['member_name']) ?> <span class="small text-muted"><?= e($a['member_no']) ?></span></td><td><?= e($a['product_name']) ?></td>
            <td><?= e(fmt_date($a['date_released'])) ?></td><td class="num"><?= e(money($a['principal'])) ?></td><td><?= (int) $a['paid_inst'] ?> / <?= (int) $a['term_months'] ?></td>
            <td class="<?= $a['next_due'] && $a['next_due'] < date('Y-m-d') ? 'text-danger font-weight-bold' : '' ?>"><?= e(fmt_date($a['next_due'])) ?></td><td class="num"><?= e(money($a['outstanding_balance'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="font-weight-bold"><td colspan="7">Total portfolio</td><td class="num"><?= e(money(array_sum(array_column($releasedLoans, 'outstanding_balance')))) ?></td></tr></tfoot>
      </table>
    </div>
  </div>

<?php elseif ($type === 'aging'):
    $rows = overdue_loans(date('Y-m-d'));
    $brackets = parse_brackets(setting('aging_brackets'));
    $groups = [];
    foreach ($rows as $r) {
        $b = aging_bracket($r['days_past_due'], $brackets);
        $groups[$b]['n'] = ($groups[$b]['n'] ?? 0) + 1;
        $groups[$b]['amount'] = ($groups[$b]['amount'] ?? 0) + $r['amount_past_due'];
    }
?>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Aging of past-due loans · live as of <?= e(fmt_date(date('Y-m-d'), 'F j, Y')) ?></h3></div>
    <div class="card-body">
      <div class="stat-strip mb-3">
        <?php foreach ($groups as $b => $g): ?><div class="stat"><div class="l"><?= e($b) ?> days</div><div class="v"><?= (int) $g['n'] ?> · <?= e(money($g['amount'])) ?></div></div><?php endforeach; ?>
        <?php if (!$groups): ?><div class="text-muted">No past-due loans. </div><?php endif; ?>
      </div>
      <table class="table table-sm table-hover js-datatable" data-export="true" data-title="FFMPC Aging Report <?= e(date('Y-m-d')) ?>" data-order='[[3,"desc"]]'>
        <thead><tr><th>Member</th><th>Loan</th><th>Oldest unpaid due</th><th class="num">Days past due</th><th>Bracket</th><th class="num">Installments</th><th class="num">Amount past due</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['member_name']) ?> <span class="small text-muted"><?= e($r['member_no']) ?></span></td><td>#<?= (int) $r['loan_id'] ?></td><td><?= e(fmt_date($r['oldest_due'])) ?></td>
            <td class="num"><?= (int) $r['days_past_due'] ?></td><td><?= e(aging_bracket($r['days_past_due'], $brackets)) ?></td><td class="num"><?= (int) $r['overdue_count'] ?></td><td class="num"><?= e(money($r['amount_past_due'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($type === 'savings'):
    $from = get_date('from', date('Y-m-01'));
    $to = get_date('to', date('Y-m-d'));
    $stmt = $pdo->prepare(
        "SELECT a.account_type, COUNT(DISTINCT a.savings_id) AS accounts, SUM(a.balance) AS balance,
                (SELECT COALESCE(SUM(t.amount),0) FROM savings_transactions t JOIN savings_accounts x ON x.savings_id = t.savings_id
                  WHERE x.account_type = a.account_type AND t.txn_type = 'deposit' AND t.txn_date BETWEEN :f1 AND :t1) AS deposits,
                (SELECT COALESCE(SUM(t.amount),0) FROM savings_transactions t JOIN savings_accounts x ON x.savings_id = t.savings_id
                  WHERE x.account_type = a.account_type AND t.txn_type = 'withdrawal' AND t.txn_date BETWEEN :f2 AND :t2) AS withdrawals
           FROM savings_accounts a WHERE a.account_type <> 'share_capital' GROUP BY a.account_type"
    );
    $stmt->execute([':f1' => $from, ':t1' => $to, ':f2' => $from, ':t2' => $to]);
    $summary = $stmt->fetchAll();
    $members = $pdo->query(
        "SELECT m.member_no, CONCAT(m.last_name, ', ', m.first_name) AS member_name,
                SUM(IF(a.account_type = 'regular_savings', a.balance, 0)) AS regular,
                SUM(IF(a.account_type = 'capital_build_up', a.balance, 0)) AS cbu,
                SUM(IF(a.account_type = 'time_deposit', a.balance, 0)) AS td
           FROM members m JOIN savings_accounts a ON a.member_id = m.member_id
          WHERE a.account_type <> 'share_capital'
          GROUP BY m.member_id, m.member_no, m.last_name, m.first_name ORDER BY member_name"
    )->fetchAll();
?>
  <div class="card">
    <div class="card-header d-flex flex-wrap align-items-center">
      <form method="get" action="dashboard.php" class="form-inline no-print">
        <input type="hidden" name="page" value="reports"><input type="hidden" name="type" value="savings">
        <label for="from" class="mr-2">Movements from</label><input type="date" id="from" name="from" class="form-control form-control-sm mr-2" value="<?= e($from) ?>">
        <label for="to" class="mr-2">to</label><input type="date" id="to" name="to" class="form-control form-control-sm mr-2" value="<?= e($to) ?>">
        <button class="btn btn-sm btn-outline-primary">Show</button>
      </form>
      <h3 class="card-title ml-auto">Savings summary</h3>
    </div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <thead><tr><th>Account type</th><th class="num">Accounts</th><th class="num">Deposits</th><th class="num">Withdrawals</th><th class="num">Current balance</th></tr></thead>
        <tbody>
        <?php foreach ($summary as $s): ?>
          <tr><td><?= e(ACCOUNT_TYPES[$s['account_type']]) ?></td><td class="num"><?= (int) $s['accounts'] ?></td><td class="num"><?= e(money($s['deposits'])) ?></td><td class="num"><?= e(money($s['withdrawals'])) ?></td><td class="num font-weight-bold"><?= e(money($s['balance'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Balances per member</h3></div>
    <div class="card-body">
      <table class="table table-sm table-hover js-datatable" data-export="true" data-title="FFMPC Savings Balances <?= e(date('Y-m-d')) ?>" data-order='[[1,"asc"]]'>
        <thead><tr><th>Member no.</th><th>Member</th><th class="num">Regular savings</th><th class="num">Capital build-up</th><th class="num">Time deposit</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($members as $m): ?>
          <tr><td><?= e($m['member_no']) ?></td><td><?= e($m['member_name']) ?></td><td class="num"><?= e(money($m['regular'])) ?></td><td class="num"><?= e(money($m['cbu'])) ?></td><td class="num"><?= e(money($m['td'])) ?></td>
            <td class="num font-weight-bold"><?= e(money((float) $m['regular'] + (float) $m['cbu'] + (float) $m['td'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($type === 'share'):
    $rows = $pdo->query(
        "SELECT m.member_no, CONCAT(m.last_name, ', ', m.first_name) AS member_name, m.date_of_membership, m.status, a.balance, a.date_opened
           FROM savings_accounts a JOIN members m ON m.member_id = a.member_id
          WHERE a.account_type = 'share_capital' ORDER BY member_name"
    )->fetchAll();
    $total = array_sum(array_column($rows, 'balance'));
    $minShare = (float) setting('min_share_capital');
    $below = count(array_filter($rows, fn ($r) => (float) $r['balance'] < $minShare));
?>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Share capital report · as of <?= e(fmt_date(date('Y-m-d'), 'F j, Y')) ?></h3></div>
    <div class="card-body">
      <div class="stat-strip mb-3">
        <div class="stat"><div class="l">Total paid-up share capital</div><div class="v"><?= e(money($total)) ?></div></div>
        <div class="stat"><div class="l">Members with share capital</div><div class="v"><?= count($rows) ?></div></div>
        <div class="stat"><div class="l">Average per member</div><div class="v"><?= e(money($rows ? $total / count($rows) : 0)) ?></div></div>
        <div class="stat"><div class="l">Below minimum (<?= e(money($minShare)) ?>)</div><div class="v <?= $below ? 'text-danger' : '' ?>"><?= $below ?></div></div>
      </div>
      <table class="table table-sm table-hover js-datatable" data-export="true" data-title="FFMPC Share Capital <?= e(date('Y-m-d')) ?>" data-order='[[1,"asc"]]'>
        <thead><tr><th>Member no.</th><th>Member</th><th>Member since</th><th>Status</th><th class="num">Share capital</th><th class="num">% of total</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['member_no']) ?></td><td><?= e($r['member_name']) ?></td><td><?= e(fmt_date($r['date_of_membership'])) ?></td><td><?= badge($r['status']) ?></td>
            <td class="num <?= (float) $r['balance'] < $minShare ? 'text-danger' : '' ?>"><?= e(money($r['balance'])) ?></td><td class="num"><?= $total > 0 ? e(number_format((float) $r['balance'] / $total * 100, 2)) . '%' : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="font-weight-bold"><td colspan="4">Total</td><td class="num"><?= e(money($total)) ?></td><td class="num">100%</td></tr></tfoot>
      </table>
    </div>
  </div>
<?php elseif ($type === 'salary'):
    // Salary deduction list (questionnaire 6.6): what to deduct on the 15th and the 30th for members who pay by salary deduction.
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    $stmt = $pdo->prepare(
        "SELECT l.loan_id, m.member_no, CONCAT(m.last_name, ', ', m.first_name) AS member_name, m.occupation, p.product_name,
                s.installment_no, s.due_date, s.total_due - s.principal_paid - s.interest_paid AS amount_due
           FROM amortization_schedule s
           JOIN loans l ON l.loan_id = s.loan_id
           JOIN members m ON m.member_id = l.member_id
           JOIN loan_products p ON p.product_id = l.product_id
          WHERE l.status = 'released' AND l.repayment_mode = 'salary_deduction' AND s.status <> 'paid'
            AND s.due_date <= :end
          ORDER BY member_name, s.installment_no"
    );
    $stmt->execute([':end' => $end]);
    $rows = $stmt->fetchAll();
    $total = array_sum(array_column($rows, 'amount_due'));
?>
  <div class="card">
    <div class="card-header d-flex flex-wrap align-items-center">
      <form method="get" action="dashboard.php" class="form-inline no-print">
        <input type="hidden" name="page" value="reports"><input type="hidden" name="type" value="salary">
        <label for="month" class="mr-2">Payroll month</label>
        <input type="month" id="month" name="month" class="form-control form-control-sm mr-2" value="<?= e($month) ?>">
        <button class="btn btn-sm btn-outline-primary">Show</button>
      </form>
      <h3 class="card-title ml-auto">Salary deduction list · <?= e(date('F Y', strtotime($start))) ?> (15th &amp; 30th)</h3>
    </div>
    <div class="card-body">
      <p class="small text-muted">Unpaid installments due on or before <?= e(fmt_date($end)) ?> for loans paid by salary deduction. Overdue installments from earlier months are included.</p>
      <table class="table table-sm table-hover js-datatable" data-export="true" data-title="FFMPC Salary Deduction List <?= e($month) ?>" data-order='[[0,"asc"]]' data-page-length="100">
        <thead><tr><th>Member</th><th>Designation</th><th>Loan</th><th>Inst.</th><th>Due</th><th class="num">Monthly</th><th class="num">15th</th><th class="num">30th</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $half = semi_monthly((float) $r['amount_due']); ?>
          <tr class="<?= $r['due_date'] < $start ? 'text-danger' : '' ?>">
            <td><?= e($r['member_name']) ?> <span class="small text-muted"><?= e($r['member_no']) ?></span></td>
            <td><?= e($r['occupation'] ?? '—') ?></td>
            <td>#<?= (int) $r['loan_id'] ?> · <?= e($r['product_name']) ?></td>
            <td><?= (int) $r['installment_no'] ?></td>
            <td><?= e(fmt_date($r['due_date'])) ?><?= $r['due_date'] < $start ? ' <span class="badge badge-danger">Overdue</span>' : '' ?></td>
            <td class="num"><?= e(money($r['amount_due'])) ?></td>
            <td class="num"><?= e(money($half)) ?></td>
            <td class="num"><?= e(money(money_round((float) $r['amount_due'] - $half))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="font-weight-bold"><td colspan="5">Total to deduct</td><td class="num"><?= e(money($total)) ?></td><td colspan="2"></td></tr></tfoot>
      </table>
    </div>
  </div>
<?php endif; ?>
