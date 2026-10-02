<?php
declare(strict_types=1);

// Reconciliation: prove the stored balances against the ledgers that must add
// up to them. Per released/paid loan, the remaining principal left in the
// amortization schedule is compared with loans.outstanding_balance; per savings
// account, the signed sum of every transaction is compared with the account
// balance. Zero mismatches = the books reconcile. Read-only.
$title = 'Reconciliation';
$subtitle = 'Stored balances checked against their ledgers — schedule dues vs loan balances, transaction sums vs account balances. Any mismatch is listed in red.';
$back = 'dashboard.php?page=reports';
$pdo = db();

$loanRows = $pdo->query(
    "SELECT l.loan_id, l.status, l.outstanding_balance,
            CONCAT(m.last_name, ', ', m.first_name) AS member_name, m.member_no,
            COALESCE(SUM(s.principal_due - s.principal_paid), 0) AS schedule_outstanding
       FROM loans l
       JOIN members m ON m.member_id = l.member_id
       LEFT JOIN amortization_schedule s ON s.loan_id = l.loan_id
      WHERE l.status IN ('released', 'paid')
      GROUP BY l.loan_id, l.status, l.outstanding_balance, m.last_name, m.first_name, m.member_no
      ORDER BY l.loan_id"
)->fetchAll();

$savingsRows = $pdo->query(
    "SELECT a.savings_id, a.account_type, a.balance, a.status,
            CONCAT(m.last_name, ', ', m.first_name) AS member_name, m.member_no,
            COALESCE(SUM(CASE t.txn_type
                            WHEN 'deposit'    THEN t.amount
                            WHEN 'withdrawal' THEN -t.amount
                            WHEN 'interest'   THEN t.amount
                            WHEN 'reversal'   THEN CASE o.txn_type WHEN 'deposit' THEN -t.amount ELSE t.amount END
                         END), 0) AS ledger_sum
       FROM savings_accounts a
       JOIN members m ON m.member_id = a.member_id
       LEFT JOIN savings_transactions t ON t.savings_id = a.savings_id
       LEFT JOIN savings_transactions o ON o.txn_id = t.reverses_txn_id
      GROUP BY a.savings_id, a.account_type, a.balance, a.status, m.last_name, m.first_name, m.member_no
      ORDER BY a.savings_id"
)->fetchAll();

/** A stored balance and its ledger disagree by at least one centavo. */
$mismatch = fn (float $stored, float $ledger): bool => money_round($stored - $ledger) != 0.0;

$loanMismatches = [];
foreach ($loanRows as $r) {
    if ($mismatch((float) $r['outstanding_balance'], (float) $r['schedule_outstanding'])) {
        $r['diff'] = money_round((float) $r['outstanding_balance'] - (float) $r['schedule_outstanding']);
        $loanMismatches[] = $r;
    }
}
$savingsMismatches = [];
foreach ($savingsRows as $r) {
    if ($mismatch((float) $r['balance'], (float) $r['ledger_sum'])) {
        $r['diff'] = money_round((float) $r['balance'] - (float) $r['ledger_sum']);
        $savingsMismatches[] = $r;
    }
}
$allGreen = !$loanMismatches && !$savingsMismatches;
?>
<?php if ($allGreen): ?>
  <div class="alert alert-success" role="alert">
    <i class="fas fa-check-circle mr-1" aria-hidden="true"></i>
    <strong>All records reconcile.</strong>
    <?= count($loanRows) ?> loan(s) and <?= count($savingsRows) ?> savings account(s) checked — every stored balance matches its ledger.
  </div>
<?php else: ?>
  <div class="alert alert-danger" role="alert">
    <i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i>
    <strong><?= count($loanMismatches) ?> loan(s) and <?= count($savingsMismatches) ?> savings account(s) DO NOT reconcile.</strong>
    Show them to the Bookkeeper and the Manager — do not post corrections until the cause is known.
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h3 class="card-title"><i class="fas fa-hand-holding-usd mr-2"></i>Loans — remaining schedule dues vs stored outstanding balance</h3></div>
  <div class="card-body">
    <table class="table table-sm table-hover js-datatable" data-export="true" data-title="FFMPC Loan Reconciliation <?= e(date('Y-m-d')) ?>" data-order='[[0,"asc"]]' data-empty="No released loans yet.">
      <thead><tr><th>Loan</th><th>Member</th><th>Status</th><th class="num">Schedule dues left</th><th class="num">Outstanding stored</th><th class="num">Difference</th></tr></thead>
      <tbody>
      <?php if ($allGreen): ?>
        <?php foreach ($loanRows as $r): ?>
          <tr>
            <td><a href="dashboard.php?page=loan_view&id=<?= (int) $r['loan_id'] ?>">#<?= (int) $r['loan_id'] ?></a></td>
            <td><?= e($r['member_name']) ?> <span class="small text-muted"><?= e($r['member_no']) ?></span></td>
            <td><?= badge($r['status']) ?></td>
            <td class="num"><?= e(money($r['schedule_outstanding'])) ?></td>
            <td class="num"><?= e(money($r['outstanding_balance'])) ?></td>
            <td class="num text-success">—</td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <?php foreach ($loanMismatches as $r): ?>
          <tr class="table-danger">
            <td><a href="dashboard.php?page=loan_view&id=<?= (int) $r['loan_id'] ?>">#<?= (int) $r['loan_id'] ?></a></td>
            <td><?= e($r['member_name']) ?> <span class="small text-muted"><?= e($r['member_no']) ?></span></td>
            <td><?= badge($r['status']) ?></td>
            <td class="num"><?= e(money($r['schedule_outstanding'])) ?></td>
            <td class="num"><?= e(money($r['outstanding_balance'])) ?></td>
            <td class="num font-weight-bold text-danger"><?= e(money($r['diff'])) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title"><i class="fas fa-piggy-bank mr-2"></i>Savings — transaction sums vs stored account balances</h3></div>
  <div class="card-body">
    <table class="table table-sm table-hover js-datatable" data-export="true" data-title="FFMPC Savings Reconciliation <?= e(date('Y-m-d')) ?>" data-order='[[0,"asc"]]' data-empty="No savings accounts yet.">
      <thead><tr><th>Account</th><th>Member</th><th>Type</th><th>Status</th><th class="num">Ledger sum</th><th class="num">Balance stored</th><th class="num">Difference</th></tr></thead>
      <tbody>
      <?php if ($allGreen): ?>
        <?php foreach ($savingsRows as $r): ?>
          <tr>
            <td><a href="dashboard.php?page=passbook&id=<?= (int) $r['savings_id'] ?>">#<?= (int) $r['savings_id'] ?></a></td>
            <td><?= e($r['member_name']) ?> <span class="small text-muted"><?= e($r['member_no']) ?></span></td>
            <td><?= e(ACCOUNT_TYPES[$r['account_type']]) ?></td>
            <td><?= badge($r['status']) ?></td>
            <td class="num"><?= e(money($r['ledger_sum'])) ?></td>
            <td class="num"><?= e(money($r['balance'])) ?></td>
            <td class="num text-success">—</td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <?php foreach ($savingsMismatches as $r): ?>
          <tr class="table-danger">
            <td><a href="dashboard.php?page=passbook&id=<?= (int) $r['savings_id'] ?>">#<?= (int) $r['savings_id'] ?></a></td>
            <td><?= e($r['member_name']) ?> <span class="small text-muted"><?= e($r['member_no']) ?></span></td>
            <td><?= e(ACCOUNT_TYPES[$r['account_type']]) ?></td>
            <td><?= badge($r['status']) ?></td>
            <td class="num"><?= e(money($r['ledger_sum'])) ?></td>
            <td class="num"><?= e(money($r['balance'])) ?></td>
            <td class="num font-weight-bold text-danger"><?= e(money($r['diff'])) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
    <p class="small text-muted mb-0">The ledger sum signs deposits and interest in, withdrawals out, and each reversal against the entry it reversed. Both tables export with the buttons above.</p>
  </div>
</div>
