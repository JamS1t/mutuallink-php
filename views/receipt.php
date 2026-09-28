<?php
declare(strict_types=1);

// Official receipt for a loan payment (?id=) or a savings transaction (?txn=). Printable.
$layout = 'print';
$title = 'Official receipt';

$paymentId = get_id('id');
$txnId = get_id('txn');
$r = null;

if ($paymentId) {
    $stmt = db()->prepare(
        "SELECT p.*, s.installment_no, s.due_date, l.loan_id, pr.product_name, m.member_no, m.member_id,
                CONCAT(m.first_name, ' ', m.last_name) AS member_name, u.full_name AS cashier
           FROM payments p
           JOIN amortization_schedule s ON s.schedule_id = p.schedule_id
           JOIN loans l ON l.loan_id = p.loan_id
           JOIN loan_products pr ON pr.product_id = l.product_id
           JOIN members m ON m.member_id = l.member_id
           JOIN users u ON u.user_id = p.posted_by
          WHERE p.payment_id = :id"
    );
    $stmt->execute([':id' => $paymentId]);
    $r = $stmt->fetch();
    $back = $r ? 'dashboard.php?page=loan_view&id=' . (int) $r['loan_id'] : 'dashboard.php?page=payments';
} elseif ($txnId) {
    $stmt = db()->prepare(
        "SELECT t.*, a.account_type, m.member_no, m.member_id, CONCAT(m.first_name, ' ', m.last_name) AS member_name, u.full_name AS cashier
           FROM savings_transactions t
           JOIN savings_accounts a ON a.savings_id = t.savings_id
           JOIN members m ON m.member_id = a.member_id
           JOIN users u ON u.user_id = t.posted_by
          WHERE t.txn_id = :id AND t.or_no IS NOT NULL"
    );
    $stmt->execute([':id' => $txnId]);
    $r = $stmt->fetch();
    $back = $r ? 'dashboard.php?page=passbook&id=' . (int) $r['savings_id'] : 'dashboard.php?page=savings';
}

if (!$r) {
    $layout = 'app';
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
$isVoid = ($r['status'] ?? '') === 'void';
?>
<div class="print-actions no-print">
  <a href="<?= e($back) ?>" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Back</a>
  <button type="button" class="btn btn-primary" data-print><i class="fas fa-print mr-1"></i> Print receipt</button>
</div>
<div class="print-doc receipt">
  <div class="print-head">
    <h2>FRANCISCAN FRIENDS MULTIPURPOSE COOPERATIVE</h2>
    <p>Andres Bonifacio, Zone 1, Baybay City, Leyte</p>
    <p class="mt-2 font-weight-bold">OFFICIAL RECEIPT</p>
  </div>
  <?php if ($isVoid): ?>
    <div class="alert alert-danger text-center font-weight-bold py-1">VOID — <?= e($r['void_reason']) ?></div>
  <?php endif; ?>
  <table class="table table-sm table-borderless mb-2">
    <tr><td class="text-muted">OR No.</td><td class="text-right font-weight-bold"><?= e($r['or_no']) ?></td></tr>
    <tr><td class="text-muted">Date</td><td class="text-right"><?= e(fmt_date($r['payment_date'] ?? $r['txn_date'])) ?></td></tr>
    <tr><td class="text-muted">Received from</td><td class="text-right"><?= e($r['member_name']) ?><br><small><?= e($r['member_no']) ?></small></td></tr>
  </table>
  <hr class="my-2">
  <?php if ($paymentId): ?>
    <table class="table table-sm table-borderless mb-2">
      <tr><td>Loan #<?= (int) $r['loan_id'] ?> · <?= e($r['product_name']) ?></td><td></td></tr>
      <tr><td class="text-muted">Installment <?= (int) $r['installment_no'] ?> (due <?= e(fmt_date($r['due_date'])) ?>)</td><td></td></tr>
      <tr><td>Penalty</td><td class="num"><?= e(money($r['penalty_portion'])) ?></td></tr>
      <tr><td>Interest</td><td class="num"><?= e(money($r['interest_portion'])) ?></td></tr>
      <tr><td>Principal</td><td class="num"><?= e(money($r['principal_portion'])) ?></td></tr>
      <tr class="border-top"><td class="font-weight-bold">TOTAL PAID</td><td class="num font-weight-bold h5 mb-0"><?= e(money($r['amount_paid'])) ?></td></tr>
      <tr><td class="text-muted">Mode</td><td class="text-right"><?= e(label($r['mode'])) ?></td></tr>
      <tr><td class="text-muted">Remaining loan balance</td><td class="num"><?= e(money($r['remaining_balance'])) ?></td></tr>
    </table>
  <?php else: ?>
    <table class="table table-sm table-borderless mb-2">
      <tr><td><?= e(ucfirst($r['txn_type'])) ?> · <?= e(ACCOUNT_TYPES[$r['account_type']]) ?></td><td></td></tr>
      <tr class="border-top"><td class="font-weight-bold">AMOUNT</td><td class="num font-weight-bold h5 mb-0"><?= e(money($r['amount'])) ?></td></tr>
      <tr><td class="text-muted">Balance after</td><td class="num"><?= e(money($r['running_balance'])) ?></td></tr>
      <?php if ($r['remarks']): ?><tr><td class="text-muted" colspan="2"><?= e($r['remarks']) ?></td></tr><?php endif; ?>
    </table>
  <?php endif; ?>
  <hr class="my-2">
  <p class="small mb-4">Received by: <strong><?= e($r['cashier']) ?></strong></p>
  <p class="small text-center text-muted mb-0">Printed <?= e(date('M d, Y g:i A')) ?> · MutualLink</p>
</div>
