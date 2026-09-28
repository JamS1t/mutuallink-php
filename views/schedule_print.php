<?php
declare(strict_types=1);

// DFD 4.5 — the member's copy of the amortization schedule (printable).
$layout = 'print';
$id = get_id();
$stmt = db()->prepare(
    "SELECT l.*, p.product_name, p.interest_rate, p.penalty_rate, m.member_no, m.address,
            CONCAT(m.first_name, ' ', IFNULL(CONCAT(m.middle_name, ' '), ''), m.last_name) AS member_name
       FROM loans l JOIN loan_products p ON p.product_id = l.product_id JOIN members m ON m.member_id = l.member_id
      WHERE l.loan_id = :id AND l.date_released IS NOT NULL"
);
$stmt->execute([':id' => $id]);
$loan = $stmt->fetch();
if (!$loan) {
    $layout = 'app';
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
$title = 'Amortization schedule · Loan #' . $id;

$stmt = db()->prepare('SELECT installment_no, due_date, principal_due, interest_due, total_due, balance FROM amortization_schedule WHERE loan_id = :id ORDER BY installment_no');
$stmt->execute([':id' => $id]);
$rows = $stmt->fetchAll();
$stmt = db()->prepare('SELECT deduction_type, amount FROM loan_deductions WHERE loan_id = :id');
$stmt->execute([':id' => $id]);
$deductions = $stmt->fetchAll();
$totPrincipal = array_sum(array_column($rows, 'principal_due'));
$totInterest = array_sum(array_column($rows, 'interest_due'));
?>
<div class="print-actions no-print">
  <a href="dashboard.php?page=loan_view&id=<?= $id ?>" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Back to loan</a>
  <button type="button" class="btn btn-primary" data-print><i class="fas fa-print mr-1"></i> Print</button>
</div>
<div class="print-doc">
  <div class="print-head">
    <h2>FRANCISCAN FRIENDS MULTIPURPOSE COOPERATIVE</h2>
    <p>Andres Bonifacio, Zone 1, Baybay City, Leyte</p>
    <p class="mt-2 font-weight-bold">LOAN AMORTIZATION SCHEDULE</p>
  </div>
  <div class="row small mb-3">
    <div class="col-6">
      <div><strong>Borrower:</strong> <?= e($loan['member_name']) ?> (<?= e($loan['member_no']) ?>)</div>
      <div><strong>Address:</strong> <?= e($loan['address']) ?></div>
      <div><strong>Co-maker:</strong> <?= e($loan['co_maker']) ?></div>
      <?php if ($loan['collateral']): ?><div><strong>Collateral:</strong> <?= e($loan['collateral']) ?></div><?php endif; ?>
    </div>
    <div class="col-6 text-right">
      <div><strong>Loan #<?= $id ?></strong> · <?= e($loan['product_name']) ?></div>
      <div>Principal <?= e(money($loan['principal'])) ?> · <?= (int) $loan['term_months'] ?> months</div>
      <div><?= e($loan['interest_rate']) ?>% per month on the diminishing balance</div>
      <div>Released <?= e(fmt_date($loan['date_released'])) ?></div>
    </div>
  </div>
  <table class="table table-sm table-bordered">
    <thead class="thead-light"><tr><th>#</th><th>Due date</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Amount due</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int) $r['installment_no'] ?></td>
        <td><?= e(fmt_date($r['due_date'])) ?></td>
        <td class="num"><?= e(number_format((float) $r['principal_due'], 2)) ?></td>
        <td class="num"><?= e(number_format((float) $r['interest_due'], 2)) ?></td>
        <td class="num font-weight-bold"><?= e(number_format((float) $r['total_due'], 2)) ?></td>
        <td class="num"><?= e(number_format((float) $r['balance'], 2)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="font-weight-bold"><td colspan="2">Total</td><td class="num"><?= e(number_format($totPrincipal, 2)) ?></td><td class="num"><?= e(number_format($totInterest, 2)) ?></td><td class="num"><?= e(number_format($totPrincipal + $totInterest, 2)) ?></td><td></td></tr></tfoot>
  </table>
  <div class="row small">
    <div class="col-6">
      <strong>Deductions at release</strong>
      <table class="table table-sm table-borderless mb-0">
        <?php foreach ($deductions as $d): ?><tr><td><?= e(label($d['deduction_type'])) ?></td><td class="num"><?= e(money($d['amount'])) ?></td></tr><?php endforeach; ?>
        <tr class="border-top font-weight-bold"><td>Net proceeds</td><td class="num"><?= e(money($loan['net_proceeds'])) ?></td></tr>
      </table>
    </div>
    <div class="col-6">
      <p class="mb-1">A penalty of <?= e($loan['penalty_rate']) ?>% per month applies to any amount unpaid after its due date. Payments are applied to penalty, then interest, then principal.</p>
    </div>
  </div>
  <div class="row small mt-5 text-center">
    <div class="col-6"><div class="border-top pt-1">Borrower's signature</div></div>
    <div class="col-6"><div class="border-top pt-1">Authorized officer</div></div>
  </div>
</div>
