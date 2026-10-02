<?php
declare(strict_types=1);

$title = 'Dashboard';
$subtitle = 'Good day, ' . ($_SESSION['full_name'] ?? '') . ' — here is today at FFMPC.';

$pdo = db();
$today = date('Y-m-d');
$weekAhead = date('Y-m-d', strtotime('+7 days'));

$kpi = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM members WHERE status = 'active')                                  AS members,
        (SELECT COUNT(*) FROM loans WHERE status = 'released')                                  AS active_loans,
        (SELECT COALESCE(SUM(outstanding_balance), 0) FROM loans WHERE status = 'released')     AS portfolio,
        (SELECT COUNT(*) FROM loans WHERE status = 'pending')                                   AS pending,
        (SELECT COUNT(*) FROM loans WHERE status = 'approved')                                  AS approved,
        (SELECT COUNT(*) FROM loans WHERE status IN ('pending', 'approved'))                    AS in_process,
        (SELECT COALESCE(SUM(balance), 0) FROM savings_accounts WHERE account_type = 'share_capital') AS share_capital,
        (SELECT COALESCE(SUM(balance), 0) FROM savings_accounts WHERE account_type IN ('regular_savings','time_deposit','capital_build_up')) AS savings"
)->fetch();

$stmt = $pdo->prepare(
    "SELECT
        (SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE status = 'posted' AND payment_date = :d1 AND mode <> 'offset') AS loan_collections,
        (SELECT COALESCE(SUM(amount), 0) FROM savings_transactions WHERE txn_type = 'deposit' AND txn_date = :d2 AND or_no IS NOT NULL) AS deposits,
        (SELECT COUNT(DISTINCT s.loan_id) FROM amortization_schedule s JOIN loans l ON l.loan_id = s.loan_id
          WHERE l.status = 'released' AND s.status <> 'paid' AND s.due_date < :d3) AS past_due_loans"
);
$stmt->execute([':d1' => $today, ':d2' => $today, ':d3' => $today]);
$todayStats = $stmt->fetch();

// Installments due in the next 7 days (the "approaching" list the bookkeeper wanted automated)
$stmt = $pdo->prepare(
    "SELECT s.due_date, s.installment_no, s.total_due - s.principal_paid - s.interest_paid AS amount_due,
            l.loan_id, m.member_id, CONCAT(m.last_name, ', ', m.first_name) AS member_name
       FROM amortization_schedule s
       JOIN loans l   ON l.loan_id = s.loan_id
       JOIN members m ON m.member_id = l.member_id
      WHERE l.status = 'released' AND s.status <> 'paid' AND s.due_date BETWEEN :from AND :to
      ORDER BY s.due_date, member_name
      LIMIT 8"
);
$stmt->execute([':from' => $today, ':to' => $weekAhead]);
$upcoming = $stmt->fetchAll();

// Loan collections for the last 7 days (bar chart)
$stmt = $pdo->prepare(
    "SELECT payment_date, SUM(amount_paid) AS total
       FROM payments
      WHERE status = 'posted' AND mode <> 'offset' AND payment_date >= :from
      GROUP BY payment_date"
);
$stmt->execute([':from' => date('Y-m-d', strtotime('-6 days'))]);
$byDay = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$chartLabels = $chartValues = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('D j', strtotime($d));
    $chartValues[] = round((float) ($byDay[$d] ?? 0), 2);
}

$recent = [];
if (can('audit')) {
    $recent = $pdo->query(
        "SELECT a.action, a.table_affected, a.details, a.timestamp, u.full_name
           FROM audit_log a LEFT JOIN users u ON u.user_id = a.user_id
          ORDER BY a.log_id DESC LIMIT 8"
    )->fetchAll();
}

$quick = [];
if (can('members', 'create'))     { $quick['member_new'] = ['dashboard.php?page=member_form', 'fas fa-user-plus', 'Register member']; }
if (can('savings_txn', 'create')) { $quick['savings_post'] = ['dashboard.php?page=savings', 'fas fa-piggy-bank', t('post') . ' deposit / withdrawal']; }
if (can('payments', 'create'))    { $quick['payment_post'] = ['dashboard.php?page=payment_post', 'fas fa-cash-register', t('post') . ' loan payment']; }
if (can('loans', 'create'))       { $quick['loan_new'] = ['dashboard.php?page=loan_form', 'fas fa-file-signature', 'New loan application']; }
if (can('loans', 'approve'))      { $quick['loan_review'] = ['dashboard.php?page=loans&status=pending', 'fas fa-check-double', 'Review applications']; }
if (can('loans', 'release'))      { $quick['loan_release'] = ['dashboard.php?page=loans&status=approved', 'fas fa-money-check-alt', 'Release approved loans']; }
if (can('delinquency', 'create')) { $quick['delinquency'] = ['dashboard.php?page=delinquency', 'fas fa-exclamation-triangle', 'Update delinquency list']; }
if (can('reports'))               { $quick['reports'] = ['dashboard.php?page=reports', 'fas fa-chart-bar', 'Open reports']; }

// Role-first ordering: the day's work leads the screen, everything else follows.
$role = $_SESSION['role'] ?? '';
$tiles = [
    'collections' => ["Today's loan collections", money((float) $todayStats['loan_collections']), 'fas fa-coins', 'tone-brown', 'Payments posted today (OR issued)'],
    'deposits'    => ["Today's deposits", money((float) $todayStats['deposits']), 'fas fa-piggy-bank', 'tone-blue', 'Cash received over the counter'],
    'members'     => ['Active members', number_format((int) $kpi['members']), 'fas fa-users', 'tone-green', 'Registered and active'],
    'portfolio'   => ['Loan portfolio', money($kpi['portfolio']), 'fas fa-hand-holding-usd', 'tone-blue', $kpi['active_loans'] . ' released loan(s)'],
    'past_due'    => ['Past-due loans', number_format((int) $todayStats['past_due_loans']), 'fas fa-exclamation-circle', 'tone-red', 'With at least one late installment'],
    'share'       => ['Share capital', money($kpi['share_capital']), 'fas fa-landmark', 'tone-green', 'Total paid-up'],
    'savings'     => ['Savings & deposits', money($kpi['savings']), 'fas fa-piggy-bank', 'tone-blue', 'Regular, CBU, time deposit'],
    'in_process'  => ['Applications in process', number_format((int) $kpi['in_process']), 'fas fa-file-alt', 'tone-amber', 'Pending or approved, not released'],
    'releases'    => ['Awaiting release', number_format((int) $kpi['approved']), 'fas fa-money-check-alt', 'tone-amber', 'Approved — ready to release'],
    'due7'        => ['Due in 7 days', number_format(count($upcoming)) . (count($upcoming) === 8 ? '+' : ''), 'fas fa-calendar-check', 'tone-amber', 'Installments approaching'],
];
$tilesOrder = match ($role) {
    'cashier'      => ['collections', 'deposits', 'members', 'savings', 'share', 'portfolio', 'past_due', 'in_process', 'due7', 'releases'],
    'manager'      => ['in_process', 'releases', 'past_due', 'portfolio', 'due7', 'collections', 'deposits', 'members', 'share', 'savings'],
    'loan_officer' => ['in_process', 'past_due', 'due7', 'portfolio', 'collections', 'deposits', 'members', 'share', 'savings', 'releases'],
    default        => ['members', 'portfolio', 'collections', 'deposits', 'past_due', 'share', 'savings', 'in_process', 'releases', 'due7'],
};
$orderedTiles = [];
foreach ($tilesOrder as $k) {
    if (isset($tiles[$k])) {
        $orderedTiles[] = $tiles[$k];
    }
}
$tiles = $orderedTiles;

$quickPriority = match ($role) {
    'cashier'      => ['payment_post', 'savings_post'],
    'manager'      => ['loan_review', 'loan_release'],
    'loan_officer' => ['loan_new', 'delinquency'],
    default        => [],
};
$orderedQuick = [];
foreach ($quickPriority as $k) {
    if (isset($quick[$k])) {
        $orderedQuick[$k] = $quick[$k];
        unset($quick[$k]);
    }
}
$quick = $orderedQuick + $quick;

// Extra queue cards for the roles that work through lists
$releaseQueue = [];
if ($role === 'manager' && can('loans', 'release')) {
    $releaseQueue = $pdo->query(
        "SELECT l.loan_id, l.principal, l.status, p.product_name, m.member_id, m.member_no,
                CONCAT(m.last_name, ', ', m.first_name) AS member_name
           FROM loans l JOIN loan_products p ON p.product_id = l.product_id JOIN members m ON m.member_id = l.member_id
          WHERE l.status IN ('pending', 'approved') ORDER BY l.loan_id LIMIT 6"
    )->fetchAll();
}
$delinquencyTop = [];
$delinquencyTotal = 0.0;
if ($role === 'loan_officer') {
    $overdue = overdue_loans($today);
    $delinquencyTotal = money_round(array_sum(array_column($overdue, 'amount_past_due')));
    $delinquencyTop = array_slice($overdue, 0, 5);
}
?>
<div class="row">
  <?php foreach ($tiles as [$label, $value, $icon, $tone, $hint]): ?>
    <div class="col-sm-6 col-xl-3 mb-3">
      <div class="kpi">
        <span class="kpi-icon <?= e($tone) ?>"><i class="<?= e($icon) ?>" aria-hidden="true"></i></span>
        <div>
          <div class="kpi-label"><?= e($label) ?></div>
          <div class="kpi-value"><?= e($value) ?></div>
          <div class="kpi-hint"><?= e($hint) ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row">
  <div class="col-lg-8 <?= $role === 'cashier' ? 'order-lg-last' : '' ?>">
    <?php if ($releaseQueue): ?>
      <div class="card">
        <div class="card-header d-flex align-items-center">
          <h3 class="card-title">Approvals &amp; releases queue</h3>
          <a href="dashboard.php?page=loans&status=pending" class="ml-auto small">All loans</a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead><tr><th scope="col">Loan</th><th scope="col">Member</th><th scope="col">Product</th><th scope="col" class="num">Principal</th><th scope="col">Status</th></tr></thead>
              <tbody>
              <?php foreach ($releaseQueue as $q): ?>
                <tr>
                  <td><a href="dashboard.php?page=loan_view&id=<?= (int) $q['loan_id'] ?>">#<?= (int) $q['loan_id'] ?></a></td>
                  <td><?= e($q['member_name']) ?> <span class="small text-muted"><?= e($q['member_no']) ?></span></td>
                  <td><?= e($q['product_name']) ?></td>
                  <td class="num"><?= e(money($q['principal'])) ?></td>
                  <td><?= badge($q['status']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($delinquencyTop || $delinquencyTotal > 0): ?>
      <div class="card">
        <div class="card-header d-flex align-items-center">
          <h3 class="card-title">Delinquency summary</h3>
          <a href="dashboard.php?page=delinquency" class="ml-auto small">Full list</a>
        </div>
        <div class="card-body">
          <div class="stat-strip mb-2">
            <div class="stat"><div class="l">Accounts past due</div><div class="v text-danger"><?= count($overdue ?? []) ?></div></div>
            <div class="stat"><div class="l">Amount past due</div><div class="v text-danger"><?= e(money($delinquencyTotal)) ?></div></div>
          </div>
          <ul class="list-group list-group-flush small">
            <?php foreach ($delinquencyTop as $d): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                <span><a href="dashboard.php?page=member_view&id=<?= (int) $d['member_id'] ?>"><?= e($d['member_name']) ?></a>
                  <span class="text-muted">loan #<?= (int) $d['loan_id'] ?> · <?= (int) $d['days_past_due'] ?> day(s) behind</span></span>
                <strong class="num"><?= e(money($d['amount_past_due'])) ?></strong>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Loan collections · last 7 days</h3></div>
      <div class="card-body" style="height: 260px">
        <canvas data-chart="bar" data-label="Collections"
                data-labels="<?= e(json_encode($chartLabels)) ?>"
                data-values="<?= e(json_encode($chartValues)) ?>"
                aria-label="Bar chart of loan collections for the last seven days" role="img"></canvas>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Installments due in the next 7 days</h3></div>
      <div class="card-body p-0">
        <?php if (!$upcoming): ?>
          <div class="empty-state"><i class="far fa-calendar-check"></i>No installments due this week.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead><tr><th scope="col">Due date</th><th scope="col">Member</th><th scope="col">Loan</th><th scope="col" class="num">Amount due</th></tr></thead>
              <tbody>
              <?php foreach ($upcoming as $u): ?>
                <tr>
                  <th scope="row"><?= e(fmt_date($u['due_date'])) ?></th>
                  <td><a href="dashboard.php?page=member_view&id=<?= (int) $u['member_id'] ?>"><?= e($u['member_name']) ?></a></td>
                  <td><a href="dashboard.php?page=loan_view&id=<?= (int) $u['loan_id'] ?>">#<?= (int) $u['loan_id'] ?></a> · inst. <?= (int) $u['installment_no'] ?></td>
                  <td class="num"><?= e(money($u['amount_due'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4 <?= $role === 'cashier' ? 'order-lg-first' : '' ?>">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Quick actions</h3></div>
      <div class="list-group list-group-flush">
        <?php foreach ($quick as [$href, $icon, $text]): ?>
          <a href="<?= e($href) ?>" class="list-group-item list-group-item-action d-flex align-items-center">
            <i class="<?= e($icon) ?> text-primary mr-3" style="width: 18px" aria-hidden="true"></i><?= e($text) ?>
            <i class="fas fa-chevron-right ml-auto text-muted small" aria-hidden="true"></i>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (can('audit')): ?>
      <div class="card">
        <div class="card-header d-flex align-items-center">
          <h3 class="card-title">Recent activity</h3>
          <a href="dashboard.php?page=audit" class="ml-auto small">View all</a>
        </div>
        <ul class="list-group list-group-flush small">
          <?php foreach ($recent as $r): ?>
            <li class="list-group-item">
              <div class="d-flex justify-content-between">
                <strong><?= e($r['full_name'] ?? 'System') ?></strong>
                <span class="text-muted"><?= e(fmt_date($r['timestamp'], 'M d, g:i A')) ?></span>
              </div>
              <div class="text-muted"><?= e(label($r['action'])) ?> · <?= e($r['details']) ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (!$recent): ?><li class="list-group-item text-muted">No activity yet.</li><?php endif; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</div>
