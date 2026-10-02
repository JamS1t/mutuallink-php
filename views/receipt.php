<?php
declare(strict_types=1);

// Official receipt (printable): a loan payment (?id=), a savings transaction (?txn=), or a membership fee (?fee=member_id).
$layout = 'print';
$title = 'Official receipt';

$paymentId = get_id('id');
$txnId = get_id('txn');
$feeMemberId = get_id('fee');
$r = null;
$lines = [];
$back = 'dashboard.php';

if ($paymentId) {
    $stmt = db()->prepare('SELECT loan_id, or_no FROM payments WHERE payment_id = :id');
    $stmt->execute([':id' => $paymentId]);
    $ref = $stmt->fetch();
    if ($ref) {
        // Every installment line settled by this official receipt
        $stmt = db()->prepare(
            "SELECT p.*, s.installment_no, s.due_date, pr.product_name, m.member_no,
                    CONCAT(m.first_name, ' ', m.last_name) AS member_name, u.full_name AS cashier
               FROM payments p
               JOIN amortization_schedule s ON s.schedule_id = p.schedule_id
               JOIN loans l ON l.loan_id = p.loan_id
               JOIN loan_products pr ON pr.product_id = l.product_id
               JOIN members m ON m.member_id = l.member_id
               JOIN users u ON u.user_id = p.posted_by
              WHERE p.loan_id = :l AND p.or_no = :or
              ORDER BY s.installment_no"
        );
        $stmt->execute([':l' => $ref['loan_id'], ':or' => $ref['or_no']]);
        $lines = $stmt->fetchAll();
        $r = $lines[0] ?? null;
        $back = 'dashboard.php?page=loan_view&id=' . (int) $ref['loan_id'];
    }
} elseif ($txnId) {
    $stmt = db()->prepare(
        "SELECT t.*, a.account_type, m.member_no, CONCAT(m.first_name, ' ', m.last_name) AS member_name, u.full_name AS cashier
           FROM savings_transactions t
           JOIN savings_accounts a ON a.savings_id = t.savings_id
           JOIN members m ON m.member_id = a.member_id
           JOIN users u ON u.user_id = t.posted_by
          WHERE t.txn_id = :id AND t.or_no IS NOT NULL"
    );
    $stmt->execute([':id' => $txnId]);
    $r = $stmt->fetch() ?: null;
    $back = $r ? 'dashboard.php?page=passbook&id=' . (int) $r['savings_id'] : 'dashboard.php?page=savings';
} elseif ($feeMemberId) {
    $stmt = db()->prepare(
        "SELECT m.member_id, m.member_no, CONCAT(m.first_name, ' ', m.last_name) AS member_name, m.membership_fee, m.membership_fee_or AS or_no,
                m.membership_fee_date, u.full_name AS cashier
           FROM members m JOIN users u ON u.user_id = m.membership_fee_by
          WHERE m.member_id = :id AND m.membership_fee_or IS NOT NULL"
    );
    $stmt->execute([':id' => $feeMemberId]);
    $r = $stmt->fetch() ?: null;
    $back = 'dashboard.php?page=member_view&id=' . $feeMemberId;
}

if (!$r) {
    $layout = 'app';
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
$isVoid = ($r['status'] ?? '') === 'void';
$date = $r['payment_date'] ?? $r['txn_date'] ?? $r['membership_fee_date'];
?>
<div class="print-actions no-print">
  <a href="<?= e($back) ?>" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Back</a>
  <button type="button" class="btn btn-primary" data-print><i class="fas fa-print mr-1"></i> Print receipt</button>
</div>
<div class="print-doc receipt">
  <div class="print-head">
    <img src="dist/img/logo-96.png" alt="" width="40" height="40" class="mb-1">
    <h2 id="receipt-heading" tabindex="-1">FRANCISCAN FRIENDS MULTI-PURPOSE COOPERATIVE</h2>
    <p>Andres Bonifacio, Zone 1, Baybay City, Leyte</p>
    <p class="mt-2 font-weight-bold">OFFICIAL RECEIPT</p>
  </div>
  <?php if ($isVoid): ?>
    <div class="alert alert-danger text-center font-weight-bold py-1">VOID — <?= e($r['void_reason']) ?></div>
  <?php endif; ?>
  <table class="table table-sm table-borderless mb-2">
    <tr><td class="text-muted">No.</td><td class="text-right font-weight-bold h5 mb-0"><?= e($r['or_no']) ?></td></tr>
    <tr><td class="text-muted">Date</td><td class="text-right"><?= e(fmt_date($date)) ?></td></tr>
    <tr><td class="text-muted">Received from</td><td class="text-right"><?= e($r['member_name']) ?><br><small><?= e($r['member_no']) ?></small></td></tr>
  </table>
  <hr class="my-2">
  <?php if ($paymentId):
      $sum = fn (string $k) => array_sum(array_map(fn ($l) => (float) $l[$k], $lines));
      $total = $sum('amount_paid'); ?>
    <p class="mb-1">Loan #<?= (int) $r['loan_id'] ?> · <?= e($r['product_name']) ?></p>
    <table class="table table-sm table-borderless mb-2">
      <?php foreach ($lines as $l): ?>
        <tr class="text-muted"><td>Installment <?= (int) $l['installment_no'] ?> (due <?= e(fmt_date($l['due_date'])) ?>)</td><td class="num"><?= e(money($l['amount_paid'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($sum('penalty_portion') > 0): ?><tr><td>Penalty (after term)</td><td class="num"><?= e(money($sum('penalty_portion'))) ?></td></tr><?php endif; ?>
      <tr><td>Interest</td><td class="num"><?= e(money($sum('interest_portion'))) ?></td></tr>
      <tr><td>Principal</td><td class="num"><?= e(money($sum('principal_portion'))) ?></td></tr>
      <tr class="border-top"><td class="font-weight-bold">TOTAL PAID</td><td class="num font-weight-bold h5 mb-0"><?= e(money($total)) ?></td></tr>
      <tr><td class="text-muted">Mode</td><td class="text-right"><?= e(REPAYMENT_MODES[$r['mode']] ?? label($r['mode'])) ?></td></tr>
      <tr><td class="text-muted">Remaining loan balance</td><td class="num"><?= e(money(end($lines)['remaining_balance'])) ?></td></tr>
    </table>
  <?php elseif ($txnId): ?>
    <table class="table table-sm table-borderless mb-2">
      <tr><td><?= e(ucfirst($r['txn_type'])) ?> · <?= e(ACCOUNT_TYPES[$r['account_type']]) ?></td><td></td></tr>
      <tr class="border-top"><td class="font-weight-bold">AMOUNT</td><td class="num font-weight-bold h5 mb-0"><?= e(money($r['amount'])) ?></td></tr>
      <tr><td class="text-muted">Balance after</td><td class="num"><?= e(money($r['running_balance'])) ?></td></tr>
      <tr><td class="text-muted">Passbook</td><td class="text-right"><?= (int) $r['passbook_presented'] === 1 ? 'Presented' : 'Not presented — please update' ?></td></tr>
      <?php if ($r['remarks']): ?><tr><td class="text-muted" colspan="2"><?= e($r['remarks']) ?></td></tr><?php endif; ?>
    </table>
  <?php else: ?>
    <table class="table table-sm table-borderless mb-2">
      <tr><td>Membership fee</td><td></td></tr>
      <tr class="border-top"><td class="font-weight-bold">AMOUNT</td><td class="num font-weight-bold h5 mb-0"><?= e(money($r['membership_fee'])) ?></td></tr>
    </table>
  <?php endif; ?>
  <hr class="my-2">
  <p class="small mb-4">Received by: <strong><?= e($r['cashier']) ?></strong></p>
  <p class="small text-center text-muted mb-0">Printed <?= e(date('M d, Y g:i A')) ?> · MutualLink</p>
</div>
