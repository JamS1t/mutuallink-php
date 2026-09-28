<?php
declare(strict_types=1);

// DFD 5.0 — payment posting (Cashier)
$loanId = $_SERVER['REQUEST_METHOD'] === 'POST' ? post_id('loan_id') : get_id('loan_id');
$memberId = get_id('member_id');

$title = 'Post loan payment';
$subtitle = 'Applied to penalty, then interest, then principal, oldest installment first. Paying ahead settles the next installments; there is no rebate.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $amount = money_in($errors, 'amount', 'Amount', true, 0.01, 10000000.00);
    $date = date_in($errors, 'payment_date', 'Payment date');
    $mode = enum_in($errors, 'mode', 'payment mode', array_keys(REPAYMENT_MODES));
    if ($errors) {
        flash_errors($errors);
    } else {
        try {
            $res = post_loan_payment($loanId, $amount, $date, $mode);
            flash('success', 'Payment posted. OR ' . $res['or_no'] . ' covers ' . count($res['lines']) . ' installment(s) · remaining principal '
                . money($res['remaining']) . ($res['remaining'] <= 0 ? ' — loan fully paid!' : '.'));
            redirect('dashboard.php?page=receipt&id=' . $res['payment_id']);
        } catch (DomainException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            db_failure($e);
        }
    }
}

$loan = $loanId ? loan_for_payment($loanId) : null;

$memberLoans = [];
$member = null;
if (!$loan && $memberId) {
    $stmt = db()->prepare("SELECT member_id, member_no, CONCAT(last_name, ', ', first_name) AS name FROM members WHERE member_id = :id");
    $stmt->execute([':id' => $memberId]);
    $member = $stmt->fetch() ?: null;
    $stmt = db()->prepare(
        "SELECT l.loan_id, p.product_name, l.outstanding_balance,
                (SELECT MIN(s.due_date) FROM amortization_schedule s WHERE s.loan_id = l.loan_id AND s.status <> 'paid') AS next_due
           FROM loans l JOIN loan_products p ON p.product_id = l.product_id
          WHERE l.member_id = :id AND l.status = 'released' ORDER BY l.loan_id"
    );
    $stmt->execute([':id' => $memberId]);
    $memberLoans = $stmt->fetchAll();
}

$dues = null;
$payDate = get_date('as_of', old('payment_date', date('Y-m-d')));
if ($payDate > date('Y-m-d')) {
    $payDate = date('Y-m-d');
}
if ($loan && $loan['status'] === 'released') {
    $unpaid = unpaid_installments((int) $loan['loan_id']);
    $dues = $unpaid ? loan_dues($loan, $unpaid, max($payDate, $loan['date_released'])) : null;
    $subtitle = $loan['member_name'] . ' · ' . $loan['member_no'] . ' · Loan #' . $loan['loan_id'] . ' (' . $loan['product_name'] . ')';
    $headerActions = '<a href="dashboard.php?page=loan_view&id=' . (int) $loan['loan_id'] . '" class="btn btn-light"><i class="fas fa-file-invoice mr-1"></i> Loan record</a>';
}
?>
<?php if (!$loan): ?>
  <div class="row">
    <div class="col-lg-6">
      <div class="card card-primary card-outline">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-search mr-2"></i>1. Find the member</h3></div>
        <div class="card-body">
          <input type="search" class="form-control form-control-lg" placeholder="Last name, first name, or member no." autocomplete="off" autofocus aria-label="Find member"
                 data-member-lookup="#pay-results" data-select-url="dashboard.php?page=payment_post&member_id={id}">
          <div id="pay-results" class="list-group lookup-results mt-2" aria-live="polite"></div>
        </div>
      </div>
    </div>
    <?php if ($member): ?>
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header"><h3 class="card-title">2. Choose the loan · <?= e($member['name']) ?></h3></div>
          <div class="list-group list-group-flush">
            <?php foreach ($memberLoans as $ml): $late = $ml['next_due'] && $ml['next_due'] < date('Y-m-d'); ?>
              <a href="dashboard.php?page=payment_post&loan_id=<?= (int) $ml['loan_id'] ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                <div>
                  <strong>Loan #<?= (int) $ml['loan_id'] ?></strong> · <?= e($ml['product_name']) ?>
                  <div class="small text-muted">Next due <?= e(fmt_date($ml['next_due'])) ?><?= $late ? ' <span class="badge badge-danger">Overdue</span>' : '' ?></div>
                </div>
                <span class="ml-auto num"><?= e(money($ml['outstanding_balance'])) ?></span>
              </a>
            <?php endforeach; ?>
            <?php if (!$memberLoans): ?><div class="list-group-item text-muted">This member has no released loan.</div><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php elseif (!$dues): ?>
  <div class="card"><div class="card-body empty-state"><i class="fas fa-check-circle text-success"></i>Loan #<?= (int) $loan['loan_id'] ?> has no unpaid installment (status: <?= e($loan['status']) ?>).</div></div>
<?php else:
    $cur = $dues['current'];
    $allocData = ['penalty' => $dues['pd']['penalty'], 'pd_interest' => $dues['pd']['interest'],
        'installments' => array_map(fn ($r) => ['no' => $r['installment_no'], 'interest' => $r['interest'], 'principal' => $r['principal']], $dues['remaining'])]; ?>
  <div class="row">
    <div class="col-lg-7">
      <div class="card card-primary card-outline">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-cash-register mr-2"></i>Receive payment · installment <?= (int) $cur['installment_no'] ?> due <?= e(fmt_date($cur['due_date'])) ?></h3></div>
        <form method="post" action="dashboard.php?page=payment_post&loan_id=<?= (int) $loan['loan_id'] ?>&as_of=<?= e($payDate) ?>" class="ml-form" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="loan_id" value="<?= (int) $loan['loan_id'] ?>">
          <input type="hidden" name="payment_date" value="<?= e($payDate) ?>">
          <div class="card-body">
            <div class="form-group">
              <label for="payment-amount">Amount received (₱) <span class="text-danger">*</span></label>
              <input type="text" inputmode="decimal" class="form-control form-control-lg" id="payment-amount" name="amount" required autofocus
                     value="<?= e(old('amount')) ?>" placeholder="0.00" data-alloc="<?= e(json_encode($allocData)) ?>" data-payoff="<?= e($dues['payoff']) ?>">
              <div class="mt-1">
                <button type="button" class="btn btn-sm btn-light" data-fill-amount="<?= e(number_format($dues['current_total'], 2, '.', '')) ?>">Amount due now · <?= e(money($dues['current_total'])) ?></button>
                <?php if ($cur): ?><button type="button" class="btn btn-sm btn-light" data-fill-amount="<?= e(number_format(semi_monthly($dues['current_total']), 2, '.', '')) ?>">Semi-monthly · <?= e(money(semi_monthly($dues['current_total']))) ?></button><?php endif; ?>
                <button type="button" class="btn btn-sm btn-light" data-fill-amount="<?= e(number_format($dues['payoff'], 2, '.', '')) ?>">Full payoff · <?= e(money($dues['payoff'])) ?></button>
              </div>
            </div>
            <div class="form-group">
              <label for="mode">Mode of payment</label>
              <select class="custom-select" id="mode" name="mode">
                <?php foreach (REPAYMENT_MODES as $k => $v): ?><option value="<?= e($k) ?>" <?= old('mode', $loan['repayment_mode']) === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
              </select>
            </div>
            <h4 class="h6 text-muted text-uppercase small mt-3">How this payment will be applied</h4>
            <table class="table table-sm mb-2">
              <tr><td>1. Penalty (after term)</td><td class="num" id="split-penalty">₱ 0.00</td></tr>
              <tr><td>2. Interest</td><td class="num" id="split-interest">₱ 0.00</td></tr>
              <tr><td>3. Principal</td><td class="num" id="split-principal">₱ 0.00</td></tr>
              <tr class="text-muted"><td>Installments covered</td><td class="num" id="split-installments">—</td></tr>
            </table>
            <div id="split-excess" class="alert alert-warning small mb-0" role="alert" hidden></div>
          </div>
          <div class="card-footer bg-white">
            <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-check mr-1"></i> Post payment and issue OR</button>
          </div>
        </form>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Due as of <?= e(fmt_date($payDate)) ?></h3></div>
        <div class="card-body">
          <form method="get" action="dashboard.php" class="form-inline mb-3">
            <input type="hidden" name="page" value="payment_post">
            <input type="hidden" name="loan_id" value="<?= (int) $loan['loan_id'] ?>">
            <label for="as_of" class="small mr-2">Payment received on</label>
            <input type="date" id="as_of" name="as_of" class="form-control form-control-sm mr-2" min="<?= e($loan['date_released']) ?>" max="<?= e(date('Y-m-d')) ?>" value="<?= e($payDate) ?>">
            <button class="btn btn-sm btn-outline-primary">Recompute</button>
          </form>
          <?php if ($dues['pd']['months'] > 0): ?>
            <div class="alert alert-danger small"><i class="fas fa-exclamation-triangle mr-1"></i>The loan term ended <?= e(fmt_date($loan['maturity_date'])) ?> (<?= (int) $dues['pd']['months'] ?> month(s) ago).
              The unpaid balance is charged <?= e($loan['interest_rate']) ?>% interest + <?= e($loan['penalty_rate']) ?>% penalty per month.</div>
          <?php elseif ($cur['due_date'] < $payDate): ?>
            <div class="alert alert-warning small">Installment <?= (int) $cur['installment_no'] ?> is overdue. Penalties apply only after the term ends on <?= e(fmt_date($loan['maturity_date'])) ?>.</div>
          <?php endif; ?>
          <table class="table table-sm mb-0">
            <?php if ($dues['pd']['months'] > 0): ?>
              <tr><td>Penalty (after term)</td><td class="num"><?= e(money($dues['pd']['penalty'])) ?></td></tr>
              <tr><td>Interest (after term)</td><td class="num"><?= e(money($dues['pd']['interest'])) ?></td></tr>
            <?php endif; ?>
            <tr><td>Interest · inst. <?= (int) $cur['installment_no'] ?></td><td class="num"><?= e(money($cur['interest'])) ?></td></tr>
            <tr><td>Principal · inst. <?= (int) $cur['installment_no'] ?></td><td class="num"><?= e(money($cur['principal'])) ?></td></tr>
            <tr class="font-weight-bold border-top"><td>Due now</td><td class="num h5 mb-0"><?= e(money($dues['current_total'])) ?></td></tr>
            <tr><td class="text-muted">Full payoff (no rebate)</td><td class="num text-muted"><?= e(money($dues['payoff'])) ?></td></tr>
            <tr><td class="text-muted">Outstanding principal</td><td class="num text-muted"><?= e(money($loan['outstanding_balance'])) ?></td></tr>
          </table>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
