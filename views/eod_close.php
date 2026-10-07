<?php
declare(strict_types=1);

// End-of-day balancing: every posted official receipt of ONE date, grouped by
// transaction type, with a grand total — replaces the manual end-of-day review
// of the receipt booklet the bookkeeper used to do. Read-only; same receipts
// the Daily collection report prints, but arranged for counting the cash box.
$date = get_date('date', date('Y-m-d'));
$title = 'End-of-day close';
$subtitle = 'All posted official receipts of ' . fmt_date($date, 'F j, Y') . ' grouped by type, with the cash-box total. A reversed savings entry stays listed (the cash really left) and its reversal is booked the day the cash came back. Loan renewals (offsets) are excluded.';
$back = 'dashboard.php?page=reports';

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT p.or_no, MIN(p.payment_id) AS payment_id, NULL AS txn_id, NULL AS member_fee_id,
            'Loan payment' AS kind, CONCAT(m.last_name, ', ', m.first_name) AS member_name, m.member_no,
            SUM(p.amount_paid) AS cash_in, 0.00 AS cash_out, MIN(p.created_at) AS at_time
       FROM payments p
       JOIN loans l ON l.loan_id = p.loan_id
       JOIN members m ON m.member_id = l.member_id
      WHERE p.payment_date = :d1 AND p.status = 'posted' AND p.mode <> 'offset'
      GROUP BY p.or_no, member_name, member_no
UNION ALL
      SELECT t.or_no, NULL, t.txn_id, NULL,
             CONCAT('Savings ', LOWER(t.txn_type)), CONCAT(m.last_name, ', ', m.first_name), m.member_no,
             IF(t.txn_type = 'deposit', t.amount, 0.00), IF(t.txn_type = 'withdrawal', t.amount, 0.00), t.created_at
       FROM savings_transactions t
       JOIN savings_accounts a ON a.savings_id = t.savings_id
       JOIN members m ON m.member_id = a.member_id
       WHERE t.txn_date = :d2 AND t.or_no IS NOT NULL
UNION ALL
      SELECT t0.or_no, NULL, r.txn_id, NULL,
             'Savings reversal', CONCAT(m.last_name, ', ', m.first_name), m.member_no,
             IF(t0.txn_type = 'withdrawal', r.amount, 0.00), IF(t0.txn_type = 'deposit', r.amount, 0.00), r.created_at
        FROM savings_transactions r
        JOIN savings_transactions t0 ON t0.txn_id = r.reverses_txn_id
        JOIN savings_accounts a ON a.savings_id = r.savings_id
        JOIN members m ON m.member_id = a.member_id
       WHERE r.txn_date = :d4 AND t0.or_no IS NOT NULL
UNION ALL
      SELECT m.membership_fee_or, NULL, NULL, m.member_id,
             'Membership fee', CONCAT(m.last_name, ', ', m.first_name), m.member_no,
             m.membership_fee, 0.00, CAST(m.membership_fee_date AS DATETIME)
        FROM members m
       WHERE m.membership_fee_date = :d3 AND m.membership_fee_or IS NOT NULL
       ORDER BY at_time, or_no"
);
$stmt->execute([':d1' => $date, ':d2' => $date, ':d3' => $date, ':d4' => $date]);
$rows = $stmt->fetchAll();

// Summary per type: counts and totals + the grand total for the cash box
$groups = [];
foreach ($rows as $r) {
    $k = $r['kind'];
    $groups[$k] ??= ['n' => 0, 'in' => 0.0, 'out' => 0.0];
    $groups[$k]['n']++;
    $groups[$k]['in'] = money_round($groups[$k]['in'] + (float) $r['cash_in']);
    $groups[$k]['out'] = money_round($groups[$k]['out'] + (float) $r['cash_out']);
}
$totalIn = array_sum(array_column($groups, 'in'));
$totalOut = array_sum(array_column($groups, 'out'));
$grand = money_round($totalIn - $totalOut);

/** Receipt link for a drill-down row (loan payment / savings txn / membership fee). */
$receipt_link = function (array $r): string {
    if ($r['payment_id']) {
        return 'dashboard.php?page=receipt&id=' . (int) $r['payment_id'];
    }
    if ($r['txn_id']) {
        return 'dashboard.php?page=receipt&txn=' . (int) $r['txn_id'];
    }
    return 'dashboard.php?page=receipt&fee=' . (int) $r['member_fee_id'];
};
$kindIcon = ['Loan payment' => 'fas fa-hand-holding-usd', 'Savings deposit' => 'fas fa-arrow-down',
             'Savings withdrawal' => 'fas fa-arrow-up', 'Savings reversal' => 'fas fa-undo', 'Membership fee' => 'fas fa-id-card'];
?>
<div class="card">
  <div class="card-header d-flex flex-wrap align-items-center">
    <form method="get" action="dashboard.php" class="form-inline no-print">
      <input type="hidden" name="page" value="eod_close">
      <label for="date" class="mr-2">Business date</label>
      <input type="date" id="date" name="date" class="form-control form-control-sm mr-2" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>">
      <button class="btn btn-sm btn-outline-primary">Show</button>
    </form>
    <h3 class="card-title ml-auto"><?= e(fmt_date($date, 'l, F j, Y')) ?></h3>
  </div>
  <div class="card-body">
    <div class="stat-strip mb-3">
      <?php foreach ($groups as $k => $g): ?>
        <div class="stat">
          <div class="l"><i class="<?= e($kindIcon[$k] ?? 'fas fa-receipt') ?> mr-1" aria-hidden="true"></i><?= e($k) ?> (<?= (int) $g['n'] ?>)</div>
          <div class="v"><?= e(money($g['in'] - $g['out'])) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$groups): ?><div class="text-muted">No posted receipts for this date.</div><?php endif; ?>
    </div>
    <table class="table table-sm table-hover js-datatable" data-export="true" data-title="FFMPC End-of-day close <?= e($date) ?>" data-order='[[0,"asc"]]' data-page-length="100">
      <thead><tr><th>OR no.</th><th>Time</th><th>Type</th><th>Member</th><th class="num">Cash in</th><th class="num">Cash out</th><th class="no-sort text-right">Receipt</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['or_no']) ?></td>
          <td><?= e($r['at_time'] ? date('g:i A', strtotime($r['at_time'])) : '—') ?></td>
          <td><?= e($r['kind']) ?></td>
          <td><?= e($r['member_name']) ?> <span class="small text-muted"><?= e($r['member_no']) ?></span></td>
          <td class="num"><?= (float) $r['cash_in'] ? e(money($r['cash_in'])) : '' ?></td>
          <td class="num"><?= (float) $r['cash_out'] ? e(money($r['cash_out'])) : '' ?></td>
          <td class="text-right"><a href="<?= e($receipt_link($r)) ?>" class="btn btn-sm btn-light"><i class="fas fa-receipt mr-1" aria-hidden="true"></i>OR <?= e($r['or_no']) ?></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-muted text-center">Nothing was posted on this date.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr class="font-weight-bold">
        <td colspan="4">Grand total (cash box)</td>
        <td class="num"><?= e(money($totalIn)) ?></td>
        <td class="num"><?= e(money($totalOut)) ?></td>
        <td class="num text-primary"><?= e(money($grand)) ?></td>
      </tr></tfoot>
    </table>
    <p class="small text-muted mb-0">Excluded, like the daily collection report: loan renewals settled by offset (not cash) and non-cash system postings such as interest accruals. A reversal shows as its own <strong>Savings reversal</strong> line on the day the cash returned, and links to the receipt it corrected.</p>
  </div>
</div>
