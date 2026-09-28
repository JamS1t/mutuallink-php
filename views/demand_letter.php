<?php
declare(strict_types=1);

// Demand letter for a delinquent loan (questionnaire 7.4–7.5): addressed to the borrower,
// with the co-maker notified because the co-maker is liable when the borrower fails to pay.
$layout = 'print';
$title = 'Demand letter';

$loanId = get_id('loan_id');
$loan = $loanId ? loan_for_payment($loanId) : null;
if ($loan && $loan['status'] !== 'released') {
    $loan = null;
}
if (!$loan) {
    $layout = 'app';
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
$stmt = db()->prepare('SELECT address, first_name, last_name, middle_name FROM members WHERE member_id = :id');
$stmt->execute([':id' => $loan['member_id']]);
$m = $stmt->fetch();

$today = date('Y-m-d');
$dues = loan_dues($loan, unpaid_installments($loanId), $today);
$overdue = array_values(array_filter($dues['remaining'], fn ($r) => $r['due_date'] < $today));
$pastDueAmount = money_round(array_sum(array_map(fn ($r) => $r['interest'] + $r['principal'], $overdue)) + $dues['pd']['penalty'] + $dues['pd']['interest']);
if (!$overdue) {
    flash('info', 'This loan has no overdue installment.');
    redirect('dashboard.php?page=loan_view&id=' . $loanId);
}
audit_log('demand_letter', 'loans', $loanId, 'Printed demand letter · ' . money($pastDueAmount) . ' past due');
?>
<div class="print-actions no-print">
  <a href="dashboard.php?page=delinquency" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Delinquency list</a>
  <button type="button" class="btn btn-primary" data-print><i class="fas fa-print mr-1"></i> Print letter</button>
</div>
<div class="print-doc">
  <div class="print-head">
    <img src="dist/img/logo-96.png" alt="" width="40" height="40" class="mb-1">
    <h2>FRANCISCAN FRIENDS MULTI-PURPOSE COOPERATIVE</h2>
    <p>Andres Bonifacio, Zone 1, Baybay City, Leyte</p>
  </div>
  <p class="text-right"><?= e(fmt_date($today, 'F j, Y')) ?></p>
  <p class="mb-0"><strong><?= e(trim($m['first_name'] . ' ' . ($m['middle_name'] ? mb_substr($m['middle_name'], 0, 1) . '. ' : '') . $m['last_name'])) ?></strong></p>
  <p class="mb-0"><?= e($m['address']) ?></p>
  <p>Member No. <?= e($loan['member_no']) ?></p>

  <p class="font-weight-bold text-center my-4">DEMAND LETTER</p>

  <p>Dear Member,</p>
  <p>Our records show that your <strong><?= e($loan['product_name']) ?> (Loan #<?= (int) $loan['loan_id'] ?>)</strong>, released on
    <?= e(fmt_date($loan['date_released'], 'F j, Y')) ?>, has <strong><?= count($overdue) ?> unpaid installment(s)</strong>, the oldest due on
    <?= e(fmt_date($overdue[0]['due_date'], 'F j, Y')) ?>.</p>

  <table class="table table-sm table-bordered w-auto">
    <?php foreach ($overdue as $o): ?>
      <tr><td>Installment <?= (int) $o['installment_no'] ?> · due <?= e(fmt_date($o['due_date'])) ?></td><td class="num"><?= e(money($o['interest'] + $o['principal'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($dues['pd']['months'] > 0): ?>
      <tr><td>Interest and penalty after the term (<?= (int) $dues['pd']['months'] ?> month(s))</td><td class="num"><?= e(money($dues['pd']['interest'] + $dues['pd']['penalty'])) ?></td></tr>
    <?php endif; ?>
    <tr class="font-weight-bold"><td>Total amount past due</td><td class="num"><?= e(money($pastDueAmount)) ?></td></tr>
  </table>

  <p>We respectfully demand that you settle this amount at the cooperative office within <strong>fifteen (15) days</strong> from receipt of this letter.
    If the loan remains unpaid after its term ends on <?= e(fmt_date($loan['maturity_date'], 'F j, Y')) ?>, the unpaid balance is charged
    <?= e($loan['interest_rate']) ?>% interest plus a <?= e($loan['penalty_rate']) ?>% penalty per month. Failure to pay may lead the cooperative to
    hold or proceed against the collateral submitted<?= $loan['collateral'] ? ' (' . e($loan['collateral']) . ')' : '' ?>.</p>
  <p>Your co-maker, <strong><?= e($loan['co_maker']) ?></strong>, is furnished a copy of this letter, as the co-maker is liable when the borrower fails to meet the loan obligation.</p>
  <p>If you have already paid, please disregard this letter and present your official receipt. Thank you.</p>

  <div class="row mt-5">
    <div class="col-6"><div class="border-top pt-1 small">Manager</div></div>
    <div class="col-6"><div class="border-top pt-1 small">Received by (borrower) / date</div></div>
  </div>
  <p class="small text-muted mt-4 mb-0">cc: <?= e($loan['co_maker']) ?> (co-maker)</p>
</div>
