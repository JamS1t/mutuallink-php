<?php
declare(strict_types=1);

$id = get_id();
$stmt = db()->prepare('SELECT * FROM members WHERE member_id = :id');
$stmt->execute([':id' => $id]);
$m = $stmt->fetch();
if (!$m) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}

$title = $m['last_name'] . ', ' . $m['first_name'] . ($m['middle_name'] ? ' ' . $m['middle_name'] : '');
$subtitle = $m['member_no'] . ' · ' . ($m['member_type'] === 'school' ? 'School-based' : 'Outside the school') . ' · member since ' . fmt_date($m['date_of_membership']);
$buttons = [];
if (can('members', 'update')) {
    $buttons[] = '<a href="dashboard.php?page=member_form&id=' . $id . '" class="btn btn-outline-primary"><i class="fas fa-pen mr-1"></i> Edit profile</a>';
}
if (can('loans', 'create') && $m['status'] === 'active') {
    $buttons[] = '<a href="dashboard.php?page=loan_form&member_id=' . $id . '" class="btn btn-primary"><i class="fas fa-file-signature mr-1"></i> New loan</a>';
}
$headerActions = implode(' ', $buttons);

$stmt = db()->prepare("SELECT savings_id, account_type, balance, date_opened, status FROM savings_accounts WHERE member_id = :id ORDER BY FIELD(account_type, 'share_capital','capital_build_up','regular_savings','time_deposit')");
$stmt->execute([':id' => $id]);
$accounts = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT l.loan_id, p.product_name, l.principal, l.term_months, l.date_applied, l.date_released, l.outstanding_balance, l.status, l.co_maker, l.collateral
       FROM loans l JOIN loan_products p ON p.product_id = l.product_id
      WHERE l.member_id = :id ORDER BY l.loan_id DESC'
);
$stmt->execute([':id' => $id]);
$loans = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT p.payment_id, p.or_no, p.payment_date, p.amount_paid, p.principal_portion, p.interest_portion, p.penalty_portion,
            p.mode, p.status, p.loan_id, s.installment_no, s.due_date
       FROM payments p
       JOIN loans l ON l.loan_id = p.loan_id
       JOIN amortization_schedule s ON s.schedule_id = p.schedule_id
      WHERE l.member_id = :id
      ORDER BY p.payment_id DESC"
);
$stmt->execute([':id' => $id]);
$payments = $stmt->fetchAll();

$elig = member_eligibility($id);
$totalSavings = array_sum(array_map(fn ($a) => $a['account_type'] !== 'share_capital' ? (float) $a['balance'] : 0, $accounts));
$shareCapital = array_sum(array_map(fn ($a) => $a['account_type'] === 'share_capital' ? (float) $a['balance'] : 0, $accounts));
?>
<div class="card">
  <div class="card-body">
    <div class="stat-strip">
      <div class="stat"><div class="l">Status</div><div class="v"><?= badge($m['status']) ?></div></div>
      <div class="stat"><div class="l">Share capital</div><div class="v"><?= e(money($shareCapital)) ?></div></div>
      <div class="stat"><div class="l">Savings & deposits</div><div class="v"><?= e(money($totalSavings)) ?></div></div>
      <div class="stat"><div class="l">Outstanding loans</div><div class="v"><?= e(money($elig['data']['outstanding'] ?? 0)) ?></div></div>
      <div class="stat"><div class="l">Past-due installments</div><div class="v <?= ($elig['data']['past_due'] ?? 0) > 0 ? 'text-danger' : '' ?>"><?= (int) ($elig['data']['past_due'] ?? 0) ?></div></div>
    </div>
  </div>
</div>

<div class="card card-primary card-outline card-outline-tabs">
  <div class="card-header p-0 border-bottom-0">
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><a class="nav-link active" data-toggle="pill" href="#tab-eligibility" role="tab">Eligibility</a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="pill" href="#tab-profile" role="tab">Profile</a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="pill" href="#tab-accounts" role="tab">Accounts (<?= count($accounts) ?>)</a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="pill" href="#tab-loans" role="tab">Loans (<?= count($loans) ?>)</a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="pill" href="#tab-payments" role="tab">Payment history (<?= count($payments) ?>)</a></li>
    </ul>
  </div>
  <div class="card-body">
    <div class="tab-content">
      <div class="tab-pane fade show active" id="tab-eligibility" role="tabpanel">
        <div class="row">
          <div class="col-lg-7">
            <h2 class="h6 text-muted text-uppercase mb-2">Eligibility summary</h2>
            <?= eligibility_list($elig['items']) ?>
          </div>
          <div class="col-lg-5">
            <div class="callout callout-info small mt-3 mt-lg-0">
              <p class="mb-1"><strong>This summary informs; it does not decide.</strong></p>
              <p class="mb-0">Evaluation stays with the credit committee and final approval with the Manager. The system only records their decision.</p>
            </div>
            <?php $latest = $loans[0] ?? null; if ($latest): ?>
              <h2 class="h6 text-muted text-uppercase mt-3">Latest loan</h2>
              <dl class="dl-grid small">
                <dt>Co-maker</dt><dd><?= e($latest['co_maker']) ?></dd>
                <dt>Collateral</dt><dd><?= e($latest['collateral'] ?: '—') ?></dd>
                <dt>Status</dt><dd><?= badge($latest['status']) ?></dd>
              </dl>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab-profile" role="tabpanel">
        <div class="row">
          <div class="col-lg-6">
            <dl class="dl-grid">
              <dt>Member no.</dt><dd><?= e($m['member_no']) ?></dd>
              <dt>Birthdate</dt><dd><?= e(fmt_date($m['birthdate'])) ?> (<?= (int) (new DateTime($m['birthdate']))->diff(new DateTime())->y ?> yrs)</dd>
              <dt>Civil status</dt><dd><?= e(label($m['civil_status'])) ?></dd>
              <dt>Spouse</dt><dd><?= e($m['spouse_name'] ?: '—') ?></dd>
              <dt>Address</dt><dd><?= e($m['address']) ?></dd>
              <dt>Mobile</dt><dd><?= e($m['contact_no'] ?: '—') ?></dd>
              <dt>Email</dt><dd><?= e($m['email'] ?: '—') ?></dd>
            </dl>
          </div>
          <div class="col-lg-6">
            <dl class="dl-grid">
              <dt>Occupation</dt><dd><?= e($m['occupation'] ?: '—') ?></dd>
              <dt>Monthly income</dt><dd><?= $m['monthly_income'] !== null ? e(money($m['monthly_income'])) : '—' ?></dd>
              <dt>TIN</dt><dd><?= e($m['tin'] ?: '—') ?></dd>
              <dt>SSS</dt><dd><?= e($m['sss'] ?: '—') ?></dd>
              <dt>Beneficiaries</dt><dd><?= $m['beneficiaries'] ? nl2br(e($m['beneficiaries'])) : '—' ?></dd>
              <dt>Member since</dt><dd><?= e(fmt_date($m['date_of_membership'])) ?></dd>
            </dl>
          </div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab-accounts" role="tabpanel">
        <?php if (can('savings', 'create') && count($accounts) < 4 && $m['status'] === 'active'): ?>
          <a href="dashboard.php?page=savings&member_id=<?= $id ?>" class="btn btn-sm btn-outline-primary mb-3"><i class="fas fa-plus mr-1"></i> Open another account</a>
        <?php endif; ?>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead><tr><th>Account</th><th>Opened</th><th>Status</th><th class="num">Balance</th><th class="text-right">Passbook</th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $a): ?>
              <tr>
                <td class="font-weight-bold"><?= e(label($a['account_type'])) ?></td>
                <td><?= e(fmt_date($a['date_opened'])) ?></td>
                <td><?= badge($a['status']) ?></td>
                <td class="num"><?= e(money($a['balance'])) ?></td>
                <td class="text-right"><a href="dashboard.php?page=passbook&id=<?= (int) $a['savings_id'] ?>" class="btn btn-sm btn-light"><i class="fas fa-book-open"></i> Open</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="tab-pane fade" id="tab-loans" role="tabpanel">
        <?php if (!$loans): ?>
          <div class="empty-state"><i class="fas fa-hand-holding-usd"></i>No loans yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover">
              <thead><tr><th>Loan</th><th>Product</th><th>Applied</th><th>Released</th><th class="num">Principal</th><th>Term</th><th class="num">Outstanding</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($loans as $l): ?>
                <tr>
                  <td><a href="dashboard.php?page=loan_view&id=<?= (int) $l['loan_id'] ?>" class="font-weight-bold">#<?= (int) $l['loan_id'] ?></a></td>
                  <td><?= e($l['product_name']) ?></td>
                  <td><?= e(fmt_date($l['date_applied'])) ?></td>
                  <td><?= e(fmt_date($l['date_released'])) ?></td>
                  <td class="num"><?= e(money($l['principal'])) ?></td>
                  <td><?= (int) $l['term_months'] ?> mo</td>
                  <td class="num"><?= e(money($l['outstanding_balance'])) ?></td>
                  <td><?= badge($l['status']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="tab-pane fade" id="tab-payments" role="tabpanel">
        <?php if (!$payments): ?>
          <div class="empty-state"><i class="fas fa-receipt"></i>No loan payments yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover">
              <thead><tr><th>OR no.</th><th>Date</th><th>Loan / inst.</th><th>Due</th><th class="num">Amount</th><th class="num">Penalty</th><th class="num">Interest</th><th class="num">Principal</th><th>Mode</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($payments as $p): $late = $p['payment_date'] > $p['due_date']; ?>
                <tr class="<?= $p['status'] === 'void' ? 'text-muted' : '' ?>">
                  <td><a href="dashboard.php?page=receipt&id=<?= (int) $p['payment_id'] ?>"><?= e($p['or_no']) ?></a></td>
                  <td><?= e(fmt_date($p['payment_date'])) ?></td>
                  <td>#<?= (int) $p['loan_id'] ?> / <?= (int) $p['installment_no'] ?></td>
                  <td><?= e(fmt_date($p['due_date'])) ?><?= $late && $p['status'] === 'posted' ? ' <span class="badge badge-warning">Late</span>' : '' ?></td>
                  <td class="num"><?= e(money($p['amount_paid'])) ?></td>
                  <td class="num"><?= e(money($p['penalty_portion'])) ?></td>
                  <td class="num"><?= e(money($p['interest_portion'])) ?></td>
                  <td class="num"><?= e(money($p['principal_portion'])) ?></td>
                  <td><?= e(label($p['mode'])) ?></td>
                  <td><?= badge($p['status']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
