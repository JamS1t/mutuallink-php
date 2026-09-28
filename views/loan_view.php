<?php
declare(strict_types=1);

$id = get_id();
$load = function () use ($id) {
    $stmt = db()->prepare(
        "SELECT l.*, p.product_name, p.interest_rate, p.penalty_rate, m.member_no, m.member_type, m.status AS member_status,
                CONCAT(m.last_name, ', ', m.first_name) AS member_name,
                uc.full_name AS encoded_by, ua.full_name AS approver, ur.full_name AS releaser
           FROM loans l
           JOIN loan_products p ON p.product_id = l.product_id
           JOIN members m ON m.member_id = l.member_id
           JOIN users uc ON uc.user_id = l.created_by
           LEFT JOIN users ua ON ua.user_id = l.approved_by
           LEFT JOIN users ur ON ur.user_id = l.released_by
          WHERE l.loan_id = :id"
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetch();
};
$loan = $load();
if (!$loan) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
$back = 'dashboard.php?page=loan_view&id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = input('action');
    try {
        switch ($action) {
            // DFD 4.3 — record the credit committee's evaluation and the Manager's approval
            case 'approve':
            case 'reject':
                require_permission('loans', 'approve');
                $note = input('reason');
                if ($action === 'reject' && mb_strlen($note) < 3) {
                    throw new DomainException('A reason is required to reject an application.');
                }
                if ((int) $loan['created_by'] === current_user_id()) {
                    throw new DomainException('Separation of duties: the person who encoded the application cannot approve or reject it.');
                }
                $upd = db()->prepare(
                    "UPDATE loans SET status = :s, approved_by = :u, date_approved = CURDATE(),
                            remarks = LEFT(CONCAT_WS(' | ', NULLIF(remarks, ''), :note), 255)
                      WHERE loan_id = :id AND status = 'pending'"
                );
                $upd->execute([':s' => $action === 'approve' ? 'approved' : 'rejected', ':u' => current_user_id(),
                    ':note' => $note !== '' ? ucfirst($action) . 'd: ' . mb_substr($note, 0, 200) : null, ':id' => $id]);
                if ($upd->rowCount() !== 1) {
                    throw new DomainException('Only a pending application can be ' . $action . 'd.');
                }
                audit_log($action, 'loans', $id, ucfirst($action) . 'd application of ' . $loan['member_no'] . ($note ? ": $note" : ''));
                flash('success', 'Application ' . ($action === 'approve' ? 'approved. It can now be released.' : 'rejected.'));
                break;

            case 'cancel':
                require_permission('loans', 'cancel');
                $upd = db()->prepare("UPDATE loans SET status = 'cancelled' WHERE loan_id = :id AND status = 'pending'");
                $upd->execute([':id' => $id]);
                if ($upd->rowCount() !== 1) {
                    throw new DomainException('Only a pending application can be cancelled.');
                }
                audit_log('cancel', 'loans', $id, 'Cancelled application of ' . $loan['member_no'] . ': ' . input('reason'));
                flash('success', 'Application cancelled.');
                break;

            // DFD 4.4–4.5 — compute schedule, record deductions, release
            case 'release':
                require_permission('loans', 'release');
                $errors = [];
                $releaseDate = date_in($errors, 'release_date', 'Release date');
                $releaseMode = enum_in($errors, 'release_mode', 'release mode', ['cash', 'check']);
                $checkNo = opt($errors, 'check_no', 'Check number', 30);
                $prevId = post_id('prev_loan_id') ?: null;
                if ($errors) {
                    throw new DomainException(implode(' ', $errors));
                }
                $res = release_loan($id, $releaseDate, $prevId, $releaseMode, $checkNo);
                flash('success', 'Loan released by ' . $releaseMode . '. Net proceeds ' . money($res['net']) . '. Give the member a copy of the schedule.');
                break;

            case 'void_payment':
                require_permission('payments', 'void');
                $reason = input('reason');
                if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
                    throw new DomainException('A reason (3–255 characters) is required to void a payment.');
                }
                void_payment(post_id('payment_id'), $reason);
                flash('success', 'Receipt voided. The installments, balance, and charges have been restored.');
                break;
        }
    } catch (ForbiddenException $e) {
        throw $e; // let the router show the 403 page
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
        // A failed release re-renders the form so the typed values (old()) are kept.
        $keepReleaseForm = $action === 'release';
    } catch (Throwable $e) {
        db_failure($e);
    }
    if (empty($keepReleaseForm)) {
        redirect($back);
    }
}

$stmt = db()->prepare('SELECT * FROM amortization_schedule WHERE loan_id = :id ORDER BY installment_no');
$stmt->execute([':id' => $id]);
$schedule = $stmt->fetchAll();

$stmt = db()->prepare('SELECT deduction_type, amount, ref_loan_id FROM loan_deductions WHERE loan_id = :id ORDER BY deduction_id');
$stmt->execute([':id' => $id]);
$deductions = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT p.or_no, MIN(p.payment_id) AS payment_id, p.payment_date, p.mode, p.status, p.void_reason,
            SUM(p.amount_paid) AS amount_paid, SUM(p.penalty_portion) AS penalty_portion, SUM(p.interest_portion) AS interest_portion,
            SUM(p.principal_portion) AS principal_portion, MIN(p.remaining_balance) AS remaining_balance,
            GROUP_CONCAT(s.installment_no ORDER BY s.installment_no SEPARATOR ', ') AS installments, u.full_name AS cashier
       FROM payments p JOIN amortization_schedule s ON s.schedule_id = p.schedule_id JOIN users u ON u.user_id = p.posted_by
      WHERE p.loan_id = :id
      GROUP BY p.or_no, p.payment_date, p.mode, p.status, p.void_reason, u.full_name
      ORDER BY payment_id DESC"
);
$stmt->execute([':id' => $id]);
$payments = $stmt->fetchAll(); // one row per official receipt
$latestVoidable = null;
foreach ($payments as $p) {
    if ($p['status'] === 'posted') { $latestVoidable = $p['mode'] !== 'offset' ? (int) $p['payment_id'] : null; break; }
}
$hasOffset = (bool) array_filter($payments, fn ($p) => $p['mode'] === 'offset');

// Release preview (deductions and renewal options)
$previewDeductions = null;
$prevLoans = [];
if ($loan['status'] === 'approved') {
    $previewDeductions = compute_deductions((float) $loan['principal'], deduction_rates(), 0.0);
    $stmt = db()->prepare("SELECT loan_id, outstanding_balance FROM loans WHERE member_id = :m AND status = 'released' AND loan_id <> :id");
    $stmt->execute([':m' => $loan['member_id'], ':id' => $id]);
    $prevLoans = $stmt->fetchAll();
}

$dues = null;
if ($loan['status'] === 'released') {
    $payLoan = loan_for_payment($id);
    $dues = loan_dues($payLoan, unpaid_installments($id), date('Y-m-d'));
}
$maturity = $schedule ? end($schedule)['due_date'] : null;
$paidInterest = array_sum(array_map(fn ($s) => (float) $s['interest_paid'], $schedule)) + (float) $loan['pd_interest_paid'];
$paidPenalty = (float) $loan['pd_penalty_paid'];

$title = 'Loan #' . $id . ' · ' . $loan['product_name'];
$subtitle = $loan['member_name'] . ' · ' . $loan['member_no'];
$btns = [];
if ($schedule) {
    $btns[] = '<a href="dashboard.php?page=schedule_print&id=' . $id . '" class="btn btn-light"><i class="fas fa-print mr-1"></i> Schedule</a>';
}
if ($loan['status'] === 'released' && can('payments', 'create')) {
    $btns[] = '<a href="dashboard.php?page=payment_post&loan_id=' . $id . '" class="btn btn-primary"><i class="fas fa-cash-register mr-1"></i> Post payment</a>';
}
if ($loan['status'] === 'pending' && can('loans', 'create')) {
    $btns[] = '<a href="dashboard.php?page=loan_form&id=' . $id . '" class="btn btn-outline-primary"><i class="fas fa-pen mr-1"></i> Edit</a>';
}
$headerActions = implode(' ', $btns);
$steps = ['pending' => 'Applied', 'approved' => 'Approved', 'released' => 'Released', 'paid' => 'Fully paid'];
$order = array_keys($steps);
$pos = array_search($loan['status'], $order, true);
?>
<div class="row">
  <div class="col-12 loan-main">
    <div class="card">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center mb-3">
          <div class="mr-3"><?= badge($loan['status']) ?></div>
          <?php if (in_array($loan['status'], ['rejected', 'cancelled'], true)): ?>
            <span class="text-muted small">This application was <?= e($loan['status']) ?>.</span>
          <?php else: ?>
            <ol class="list-inline mb-0 small">
              <?php foreach ($steps as $k => $v): $done = $pos !== false && array_search($k, $order, true) <= $pos; ?>
                <li class="list-inline-item <?= $done ? 'text-primary font-weight-bold' : 'text-muted' ?>">
                  <i class="fas <?= $done ? 'fa-check-circle' : 'fa-circle' ?> mr-1" aria-hidden="true"></i><?= e($v) ?>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>
        <div class="stat-strip">
          <div class="stat"><div class="l">Principal</div><div class="v"><?= e(money($loan['principal'])) ?></div></div>
          <div class="stat"><div class="l">Term</div><div class="v"><?= (int) $loan['term_months'] ?> months</div></div>
          <div class="stat"><div class="l">Rate</div><div class="v"><?= e($loan['interest_rate']) ?>% / mo</div></div>
          <?php if ($schedule): ?>
            <div class="stat"><div class="l">Total interest</div><div class="v"><?= e(money($loan['total_interest'])) ?></div></div>
            <div class="stat"><div class="l">Outstanding principal</div><div class="v text-primary"><?= e(money($loan['outstanding_balance'])) ?></div></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($schedule): ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-calendar-alt mr-2"></i>Amortization schedule</h3></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 text-nowrap">
              <thead><tr><th>#</th><th>Due date</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Total due</th><th class="num">Semi-monthly</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($schedule as $s):
                  $late = $s['status'] !== 'paid' && $s['due_date'] < date('Y-m-d');
                  $paid = (float) $s['principal_paid'] + (float) $s['interest_paid']; ?>
                <tr class="<?= $late ? 'table-danger' : '' ?>">
                  <td><?= (int) $s['installment_no'] ?></td>
                  <td><?= e(fmt_date($s['due_date'])) ?><?= $late ? ' <span class="badge badge-danger">Overdue</span>' : '' ?></td>
                  <td class="num"><?= e(money($s['principal_due'])) ?></td>
                  <td class="num"><?= e(money($s['interest_due'])) ?></td>
                  <td class="num font-weight-bold"><?= e(money($s['total_due'])) ?></td>
                  <td class="num text-muted"><?= e(money(semi_monthly((float) $s['total_due']))) ?></td>
                  <td class="num"><?= e(money($paid)) ?></td>
                  <td class="num"><?= e(money($s['balance'])) ?></td>
                  <td><?= badge($s['status']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-receipt mr-2"></i>Payments</h3></div>
        <div class="card-body p-0">
          <?php if (!$payments): ?>
            <div class="empty-state"><i class="fas fa-receipt"></i>No payments yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0 text-nowrap">
                <thead><tr><th>OR no.</th><th>Date</th><th>Installments</th><th class="num">Amount</th><th class="num">Penalty</th><th class="num">Interest</th><th class="num">Principal</th><th>Mode</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($payments as $p): ?>
                  <tr class="<?= $p['status'] === 'void' ? 'text-muted' : '' ?>">
                    <td><?= $p['mode'] === 'offset' ? e($p['or_no']) : '<a href="dashboard.php?page=receipt&id=' . (int) $p['payment_id'] . '">' . e($p['or_no']) . '</a>' ?></td>
                    <td><?= e(fmt_date($p['payment_date'])) ?></td>
                    <td><?= e($p['installments']) ?></td>
                    <td class="num"><?= e(money($p['amount_paid'])) ?></td>
                    <td class="num"><?= e(money($p['penalty_portion'])) ?></td>
                    <td class="num"><?= e(money($p['interest_portion'])) ?></td>
                    <td class="num"><?= e(money($p['principal_portion'])) ?></td>
                    <td><?= e(label($p['mode'])) ?></td>
                    <td><?= badge($p['status']) ?><?= $p['void_reason'] ? '<div class="small text-muted">' . e($p['void_reason']) . '</div>' : '' ?></td>
                    <td class="text-right">
                      <?php if (can('payments', 'void') && (int) $p['payment_id'] === $latestVoidable && !$hasOffset): ?>
                        <form method="post" action="" class="ml-form" data-confirm-reason data-confirm-button="Void receipt"
                              data-confirm="<?= e('Void OR ' . $p['or_no'] . ' (' . money($p['amount_paid']) . ')? The installments and balance will be restored; the receipt stays on record marked VOID.') ?>">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="void_payment">
                          <input type="hidden" name="payment_id" value="<?= (int) $p['payment_id'] ?>">
                          <button type="submit" class="btn btn-xs btn-outline-danger"><i class="fas fa-ban"></i> Void</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-clipboard-check mr-2"></i>Eligibility summary</h3></div>
        <div class="card-body"><?= eligibility_list(member_eligibility((int) $loan['member_id'])['items']) ?></div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-12 loan-side">
    <?php if ($loan['status'] === 'pending' && (can('loans', 'approve') || can('loans', 'cancel'))): ?>
      <div class="card card-primary card-outline">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-gavel mr-2"></i>Decision</h3></div>
        <div class="card-body">
          <?php if (can('loans', 'approve')): ?>
            <p class="small text-muted">Record the credit committee's evaluation and your approval. The system records the decision; it does not make it.</p>
            <?php if ((int) $loan['created_by'] === current_user_id()): ?>
              <div class="alert alert-warning small">You encoded this application, so another Manager must decide it.</div>
            <?php else: ?>
              <form method="post" action="" class="ml-form mb-2" data-confirm="Approve this loan application?" data-confirm-button="Approve">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <div class="form-group">
                  <label for="approve-note" class="small">Committee note (optional)</label>
                  <input type="text" id="approve-note" name="reason" class="form-control form-control-sm" maxlength="200" placeholder="e.g., Approved in CreCom meeting Oct 3">
                </div>
                <button type="submit" class="btn btn-success btn-block"><i class="fas fa-check mr-1"></i> Approve</button>
              </form>
              <form method="post" action="" class="ml-form" data-confirm="Reject this application?" data-confirm-reason data-confirm-button="Reject">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="btn btn-outline-danger btn-block"><i class="fas fa-times mr-1"></i> Reject</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (can('loans', 'cancel')): ?>
            <form method="post" action="" class="ml-form" data-confirm="Cancel this application (for example, the member withdrew it)?" data-confirm-reason data-confirm-button="Cancel application">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="cancel">
              <button type="submit" class="btn btn-outline-secondary btn-block"><i class="fas fa-ban mr-1"></i> Cancel application</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($loan['status'] === 'approved' && can('loans', 'release')): ?>
      <div class="card card-primary card-outline">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-money-check-alt mr-2"></i>Release loan</h3></div>
        <form method="post" action="" class="ml-form" data-confirm="Release this loan? The amortization schedule will be generated and deductions recorded." data-confirm-button="Release">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="release">
          <div class="card-body">
            <div class="form-group">
              <label for="release_date">Release date</label>
              <input type="date" id="release_date" name="release_date" class="form-control" required
                     min="<?= e($loan['date_approved']) ?>" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('release_date', date('Y-m-d'))) ?>">
            </div>
            <div class="form-row">
              <div class="form-group col-6">
                <label for="release_mode">Released in</label>
                <select id="release_mode" name="release_mode" class="custom-select">
                  <option value="cash">Cash</option>
                  <option value="check" <?= old('release_mode') === 'check' ? 'selected' : '' ?>>Check</option>
                </select>
              </div>
              <div class="form-group col-6">
                <label for="check_no">Check no. <small class="text-muted">(if check)</small></label>
                <input type="text" id="check_no" name="check_no" class="form-control" maxlength="30" value="<?= e(old('check_no')) ?>">
              </div>
            </div>
            <?php if ($prevLoans): ?>
              <div class="form-group">
                <label for="prev_loan_id">Offset previous loan (renewal)</label>
                <select id="prev_loan_id" name="prev_loan_id" class="custom-select">
                  <option value="">None</option>
                  <?php foreach ($prevLoans as $pl): ?>
                    <option value="<?= (int) $pl['loan_id'] ?>" <?= old('prev_loan_id') === (string) $pl['loan_id'] ? 'selected' : '' ?>>Loan #<?= (int) $pl['loan_id'] ?> — <?= e(money($pl['outstanding_balance'])) ?> outstanding</option>
                  <?php endforeach; ?>
                </select>
                <small class="form-text text-muted">Its remaining principal is deducted from this release and the old loan is closed.</small>
              </div>
            <?php endif; ?>
            <table class="table table-sm mb-0">
              <tr><td>Principal</td><td class="num"><?= e(money($loan['principal'])) ?></td></tr>
              <tr><td class="text-muted">Interest (not deducted in advance)</td><td class="num">− <?= e(money(0)) ?></td></tr>
              <tr><td class="text-muted">Loan insurance</td><td class="num">− <?= e(money($previewDeductions['insurance'])) ?></td></tr>
              <tr><td class="text-muted">Service fee</td><td class="num">− <?= e(money($previewDeductions['service_fee'])) ?></td></tr>
              <tr><td class="text-muted">Stockshare <small>(to share capital)</small></td><td class="num">− <?= e(money($previewDeductions['stockshare'])) ?></td></tr>
              <tr><td class="text-muted">Notarial fee</td><td class="num">− <?= e(money($previewDeductions['notarial_fee'])) ?></td></tr>
              <tr><td class="text-muted">Others (printing)</td><td class="num">− <?= e(money($previewDeductions['other_fee'])) ?></td></tr>
              <?php if ($prevLoans): ?><tr><td class="text-muted">Previous loan</td><td class="num">if selected</td></tr><?php endif; ?>
              <tr class="font-weight-bold border-top"><td>Net proceeds</td><td class="num"><?= e(money($previewDeductions['net'])) ?></td></tr>
            </table>
          </div>
          <div class="card-footer bg-white">
            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-check mr-1"></i> Release and generate schedule</button>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($dues && $dues['current']): $cur = $dues['current']; ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-hourglass-half mr-2"></i>Currently due</h3></div>
        <div class="card-body">
          <p class="mb-2">Installment <?= (int) $cur['installment_no'] ?> · due <?= e(fmt_date($cur['due_date'])) ?>
            <?= $cur['due_date'] < date('Y-m-d') ? '<span class="badge badge-danger ml-1">Overdue</span>' : '' ?></p>
          <?php if ($dues['pd']['months'] > 0): ?>
            <div class="alert alert-danger small py-2">Term surpassed <?= (int) $dues['days_past_maturity'] ?> day(s) ago (<?= (int) $dues['pd']['months'] ?> month(s)):
              the unpaid balance is charged <?= e($loan['interest_rate']) ?>% interest + <?= e($loan['penalty_rate']) ?>% penalty per month.</div>
          <?php endif; ?>
          <table class="table table-sm mb-2">
            <?php if ($dues['pd']['months'] > 0): ?>
              <tr><td>Penalty (after term)</td><td class="num"><?= e(money($dues['pd']['penalty'])) ?></td></tr>
              <tr><td>Interest (after term)</td><td class="num"><?= e(money($dues['pd']['interest'])) ?></td></tr>
            <?php endif; ?>
            <tr><td>Interest</td><td class="num"><?= e(money($cur['interest'])) ?></td></tr>
            <tr><td>Principal</td><td class="num"><?= e(money($cur['principal'])) ?></td></tr>
            <tr class="font-weight-bold border-top"><td>Due now</td><td class="num"><?= e(money($dues['current_total'])) ?></td></tr>
          </table>
          <div class="d-flex justify-content-between small text-muted"><span>Full payoff today (no rebate)</span><strong><?= e(money($dues['payoff'])) ?></strong></div>
          <div class="d-flex justify-content-between small text-muted"><span>Term ends</span><span><?= e(fmt_date($maturity)) ?></span></div>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Details</h3></div>
      <div class="card-body">
        <dl class="dl-grid small" style="grid-template-columns: 120px 1fr">
          <dt>Member</dt><dd><a href="dashboard.php?page=member_view&id=<?= (int) $loan['member_id'] ?>"><?= e($loan['member_name']) ?></a></dd>
          <dt>Co-maker</dt><dd><?= e($loan['co_maker']) ?></dd>
          <dt>Collateral</dt><dd><?= $loan['collateral_type'] !== 'none'
              ? e(COLLATERAL_TYPES[$loan['collateral_type']] . ': ' . $loan['collateral']) . ($loan['collateral_value'] !== null ? '<div class="text-muted">Appraised ' . e(money($loan['collateral_value'])) . '</div>' : '')
              : '—' ?></dd>
          <?php if ($loan['net_pay'] !== null): ?><dt>Net pay</dt><dd><?= e(money($loan['net_pay'])) ?> / month</dd><?php endif; ?>
          <dt>Pays by</dt><dd><?= e(REPAYMENT_MODES[$loan['repayment_mode']] ?? label($loan['repayment_mode'])) ?></dd>
          <?php if ($loan['release_mode']): ?><dt>Released in</dt><dd><?= e(ucfirst($loan['release_mode'])) ?><?= $loan['check_no'] ? ' · check no. ' . e($loan['check_no']) : '' ?></dd><?php endif; ?>
          <dt>Applied</dt><dd><?= e(fmt_date($loan['date_applied'])) ?> by <?= e($loan['encoded_by']) ?></dd>
          <dt>Decision</dt><dd><?= $loan['approver'] ? e(fmt_date($loan['date_approved'])) . ' by ' . e($loan['approver']) : '—' ?></dd>
          <dt>Released</dt><dd><?= $loan['releaser'] ? e(fmt_date($loan['date_released'])) . ' by ' . e($loan['releaser']) : '—' ?></dd>
          <?php if ($loan['net_proceeds'] !== null): ?><dt>Net proceeds</dt><dd><?= e(money($loan['net_proceeds'])) ?></dd><?php endif; ?>
          <?php if ($schedule): ?><dt>Collected</dt><dd>Interest <?= e(money($paidInterest)) ?> · Penalty <?= e(money($paidPenalty)) ?></dd><?php endif; ?>
          <dt>Remarks</dt><dd><?= e($loan['remarks'] ?: '—') ?></dd>
        </dl>
        <?php if ($deductions): ?>
          <h4 class="h6 mt-3 text-muted text-uppercase small">Deductions at release</h4>
          <table class="table table-sm mb-0 small">
            <?php foreach ($deductions as $d): ?>
              <tr><td><?= e(label($d['deduction_type'])) ?><?= $d['ref_loan_id'] ? ' (loan #' . (int) $d['ref_loan_id'] . ')' : '' ?></td><td class="num"><?= e(money($d['amount'])) ?></td></tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
