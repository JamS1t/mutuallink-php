<?php
declare(strict_types=1);

$title = 'Loan payments';
$subtitle = 'Every payment with its receipt number, how it was applied, and who posted it.';
if (can('payments', 'create')) {
    $headerActions = '<a href="dashboard.php?page=payment_post" class="btn btn-primary"><i class="fas fa-cash-register mr-1"></i> Post payment</a>';
}

$from = get_date('from', date('Y-m-01'));
$to = get_date('to', date('Y-m-d'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$stmt = db()->prepare(
    "SELECT p.payment_id, p.or_no, p.payment_date, p.amount_paid, p.penalty_portion, p.interest_portion, p.principal_portion,
            p.mode, p.status, p.loan_id, s.installment_no, m.member_id, CONCAT(m.last_name, ', ', m.first_name) AS member_name, u.full_name AS cashier
       FROM payments p
       JOIN amortization_schedule s ON s.schedule_id = p.schedule_id
       JOIN loans l ON l.loan_id = p.loan_id
       JOIN members m ON m.member_id = l.member_id
       JOIN users u ON u.user_id = p.posted_by
      WHERE p.payment_date BETWEEN :f AND :t
      ORDER BY p.payment_id DESC"
);
$stmt->execute([':f' => $from, ':t' => $to]);
$rows = $stmt->fetchAll();

$posted = array_filter($rows, fn ($r) => $r['status'] === 'posted' && $r['mode'] !== 'offset');
$sum = fn (string $k) => array_sum(array_map(fn ($r) => (float) $r[$k], $posted));
?>
<div class="card">
  <div class="card-body">
    <form method="get" action="dashboard.php" class="form-inline flex-wrap">
      <input type="hidden" name="page" value="payments">
      <label for="from" class="mr-2">From</label>
      <input type="date" id="from" name="from" class="form-control mr-3 mb-2" value="<?= e($from) ?>">
      <label for="to" class="mr-2">To</label>
      <input type="date" id="to" name="to" class="form-control mr-3 mb-2" value="<?= e($to) ?>">
      <button type="submit" class="btn btn-outline-primary mb-2"><i class="fas fa-filter mr-1"></i> Apply</button>
    </form>
    <div class="stat-strip mt-2">
      <div class="stat"><div class="l">Collected</div><div class="v"><?= e(money($sum('amount_paid'))) ?></div></div>
      <div class="stat"><div class="l">Principal</div><div class="v"><?= e(money($sum('principal_portion'))) ?></div></div>
      <div class="stat"><div class="l">Interest</div><div class="v"><?= e(money($sum('interest_portion'))) ?></div></div>
      <div class="stat"><div class="l">Penalty</div><div class="v"><?= e(money($sum('penalty_portion'))) ?></div></div>
      <div class="stat"><div class="l">Receipts</div><div class="v"><?= count($posted) ?></div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <table class="table table-hover js-datatable" data-export="true" data-title="FFMC Loan Payments <?= e($from) ?> to <?= e($to) ?>" data-order='[[1,"desc"]]'>
      <thead><tr><th>OR no.</th><th>Date</th><th>Member</th><th>Loan / inst.</th><th class="num">Amount</th><th class="num">Penalty</th><th class="num">Interest</th><th class="num">Principal</th><th>Mode</th><th>Cashier</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['status'] === 'void' ? 'text-muted' : '' ?>">
          <td><?= $r['mode'] === 'offset' ? e($r['or_no']) : '<a href="dashboard.php?page=receipt&id=' . (int) $r['payment_id'] . '">' . e($r['or_no']) . '</a>' ?></td>
          <td data-order="<?= e($r['payment_date']) ?>"><?= e(fmt_date($r['payment_date'])) ?></td>
          <td><a href="dashboard.php?page=member_view&id=<?= (int) $r['member_id'] ?>"><?= e($r['member_name']) ?></a></td>
          <td><a href="dashboard.php?page=loan_view&id=<?= (int) $r['loan_id'] ?>">#<?= (int) $r['loan_id'] ?></a> / <?= (int) $r['installment_no'] ?></td>
          <td class="num"><?= e(money($r['amount_paid'])) ?></td>
          <td class="num"><?= e(money($r['penalty_portion'])) ?></td>
          <td class="num"><?= e(money($r['interest_portion'])) ?></td>
          <td class="num"><?= e(money($r['principal_portion'])) ?></td>
          <td><?= e(label($r['mode'])) ?></td>
          <td class="small"><?= e($r['cashier']) ?></td>
          <td><?= badge($r['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
