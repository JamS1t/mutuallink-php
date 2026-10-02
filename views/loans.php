<?php
declare(strict_types=1);

$title = 'Loans';
$subtitle = 'Applications, approvals, releases, and repayment status.';
if (can('loans', 'create')) {
    $headerActions = '<a href="dashboard.php?page=loan_form" class="btn btn-primary"><i class="fas fa-file-signature mr-1"></i> New application</a>';
}

$filters = ['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'released' => 'Released', 'paid' => 'Paid', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
$status = array_key_exists($_GET['status'] ?? '', $filters) ? $_GET['status'] : 'all';

$counts = db()->query('SELECT status, COUNT(*) FROM loans GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$counts['all'] = array_sum($counts);

$sql = "SELECT l.loan_id, l.principal, l.term_months, l.date_applied, l.date_released, l.outstanding_balance, l.status,
               p.product_name, m.member_id, m.member_no, CONCAT(m.last_name, ', ', m.first_name) AS member_name,
               (SELECT MIN(s.due_date) FROM amortization_schedule s WHERE s.loan_id = l.loan_id AND s.status <> 'paid') AS next_due
          FROM loans l
          JOIN loan_products p ON p.product_id = l.product_id
          JOIN members m ON m.member_id = l.member_id";
if ($status !== 'all') {
    $stmt = db()->prepare($sql . ' WHERE l.status = :s ORDER BY l.loan_id DESC');
    $stmt->execute([':s' => $status]);
} else {
    $stmt = db()->query($sql . ' ORDER BY l.loan_id DESC');
}
$loans = $stmt->fetchAll();
$today = date('Y-m-d');
?>
<div class="card">
  <div class="card-header">
    <ul class="nav nav-pills nav-sm flex-wrap">
      <?php foreach ($filters as $k => $v): ?>
        <li class="nav-item">
          <a href="dashboard.php?page=loans&status=<?= $k ?>" class="nav-link py-1 <?= $status === $k ? 'active bg-primary' : '' ?>">
            <?= e($v) ?> <span class="badge <?= $status === $k ? 'badge-light' : 'badge-secondary' ?> ml-1"><?= (int) ($counts[$k] ?? 0) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card-body">
    <table class="table table-hover js-datatable" data-export="true" data-title="FFMPC Loans" data-order='[[0,"desc"]]'>
      <thead>
        <tr><th>Loan</th><th>Member</th><th>Product</th><th class="num">Principal</th><th>Term</th><th>Applied</th><th>Next due</th><th class="num">Outstanding</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($loans as $l): $overdue = $l['status'] === 'released' && $l['next_due'] && $l['next_due'] < $today; ?>
        <tr data-href="dashboard.php?page=loan_view&id=<?= (int) $l['loan_id'] ?>">
          <td data-order="<?= (int) $l['loan_id'] ?>"><a href="dashboard.php?page=loan_view&id=<?= (int) $l['loan_id'] ?>" class="font-weight-bold">#<?= (int) $l['loan_id'] ?></a></td>
          <td><a href="dashboard.php?page=member_view&id=<?= (int) $l['member_id'] ?>"><?= e($l['member_name']) ?></a><div class="small text-muted"><?= e($l['member_no']) ?></div></td>
          <td><?= e($l['product_name']) ?></td>
          <td class="num" data-order="<?= e($l['principal']) ?>"><?= e(money($l['principal'])) ?></td>
          <td><?= (int) $l['term_months'] ?> mo</td>
          <td data-order="<?= e($l['date_applied']) ?>"><?= e(fmt_date($l['date_applied'])) ?></td>
          <td data-order="<?= e($l['next_due'] ?? '') ?>">
            <?php if ($l['status'] === 'released' && $l['next_due']): ?>
              <span class="<?= $overdue ? 'text-danger font-weight-bold' : '' ?>"><?= e(fmt_date($l['next_due'])) ?></span>
              <?= $overdue ? '<span class="badge badge-danger ml-1">Overdue</span>' : '' ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="num" data-order="<?= e($l['outstanding_balance']) ?>"><?= e(money($l['outstanding_balance'])) ?></td>
          <td><?= badge($l['status']) ?></td>
          <td class="text-right"><a href="dashboard.php?page=loan_view&id=<?= (int) $l['loan_id'] ?>" class="btn btn-sm btn-light" title="View loan #<?= (int) $l['loan_id'] ?>" aria-label="View loan #<?= (int) $l['loan_id'] ?>"><i class="fas fa-eye" aria-hidden="true"></i></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
