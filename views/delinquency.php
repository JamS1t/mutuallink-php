<?php
declare(strict_types=1);

// DFD 6.0 — delinquency monitoring by aging bracket
$title = 'Delinquency & aging';
$subtitle = 'Past-due accounts found from the schedules and payments, classified by aging bracket.';

$brackets = parse_brackets(setting('aging_brackets'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'generate') {
    require_permission('delinquency', 'create');
    $pdo = db();
    try {
        $today = date('Y-m-d');
        $rows = overdue_loans($today);
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM delinquency WHERE date_generated = :d')->execute([':d' => $today]); // regenerate today's snapshot
        $ins = $pdo->prepare('INSERT INTO delinquency (loan_id, days_past_due, aging_bracket, amount_past_due, date_generated) VALUES (:l, :dpd, :b, :a, :d)');
        foreach ($rows as $r) {
            $ins->execute([':l' => $r['loan_id'], ':dpd' => $r['days_past_due'], ':b' => aging_bracket($r['days_past_due'], $brackets),
                ':a' => $r['amount_past_due'], ':d' => $today]);
        }
        audit_log('generate', 'delinquency', null, count($rows) . ' past-due loan(s) as of ' . $today);
        $pdo->commit();
        flash('success', 'Delinquency list updated: ' . count($rows) . ' past-due loan(s).');
    } catch (Throwable $e) {
        db_failure($e);
    }
    redirect('dashboard.php?page=delinquency');
}

$dates = db()->query('SELECT DISTINCT date_generated FROM delinquency ORDER BY date_generated DESC LIMIT 30')->fetchAll(PDO::FETCH_COLUMN);
$date = get_date('date', $dates[0] ?? date('Y-m-d'));

$stmt = db()->prepare(
    "SELECT d.*, l.principal, l.outstanding_balance, l.co_maker, p.product_name, m.member_id, m.member_no, m.contact_no,
            CONCAT(m.last_name, ', ', m.first_name) AS member_name
       FROM delinquency d
       JOIN loans l ON l.loan_id = d.loan_id
       JOIN loan_products p ON p.product_id = l.product_id
       JOIN members m ON m.member_id = l.member_id
      WHERE d.date_generated = :d
      ORDER BY d.days_past_due DESC"
);
$stmt->execute([':d' => $date]);
$rows = $stmt->fetchAll();

// Summary per bracket, in bracket order
$labels = [];
$lower = 1;
foreach ($brackets as $upper) { $labels[] = "$lower-$upper"; $lower = $upper + 1; }
$labels[] = 'Over ' . end($brackets);
$summary = array_fill_keys($labels, ['n' => 0, 'amount' => 0.0, 'outstanding' => 0.0]);
foreach ($rows as $r) {
    $summary[$r['aging_bracket']] ??= ['n' => 0, 'amount' => 0.0, 'outstanding' => 0.0];
    $summary[$r['aging_bracket']]['n']++;
    $summary[$r['aging_bracket']]['amount'] += (float) $r['amount_past_due'];
    $summary[$r['aging_bracket']]['outstanding'] += (float) $r['outstanding_balance'];
}
$portfolio = (float) db()->query("SELECT COALESCE(SUM(outstanding_balance), 0) FROM loans WHERE status = 'released'")->fetchColumn();
$atRisk = array_sum(array_column($summary, 'outstanding'));

$btns = ['<button type="button" class="btn btn-light" data-print><i class="fas fa-print mr-1"></i> Print</button>'];
if (can('delinquency', 'create')) {
    $btns[] = '<form method="post" action="" class="d-inline ml-form">' . csrf_field()
        . '<input type="hidden" name="action" value="generate"><button type="submit" class="btn btn-primary"><i class="fas fa-sync-alt mr-1"></i> Update list now</button></form>';
}
$headerActions = implode(' ', $btns);
?>
<div class="row">
  <div class="col-sm-6 col-xl-3 mb-3">
    <div class="kpi"><span class="kpi-icon tone-red"><i class="fas fa-exclamation-circle" aria-hidden="true"></i></span>
      <div><div class="kpi-label">Past-due loans</div><div class="kpi-value"><?= count($rows) ?></div><div class="kpi-hint">As of <?= e(fmt_date($date)) ?></div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3 mb-3">
    <div class="kpi"><span class="kpi-icon tone-amber"><i class="fas fa-coins" aria-hidden="true"></i></span>
      <div><div class="kpi-label">Amount past due</div><div class="kpi-value"><?= e(money(array_sum(array_column($summary, 'amount')))) ?></div><div class="kpi-hint">Unpaid installments</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3 mb-3">
    <div class="kpi"><span class="kpi-icon tone-brown"><i class="fas fa-balance-scale" aria-hidden="true"></i></span>
      <div><div class="kpi-label">Portfolio at risk</div><div class="kpi-value"><?= $portfolio > 0 ? e(number_format($atRisk / $portfolio * 100, 1)) . '%' : '0%' ?></div><div class="kpi-hint"><?= e(money($atRisk)) ?> of <?= e(money($portfolio)) ?></div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3 mb-3">
    <div class="kpi"><span class="kpi-icon tone-blue"><i class="fas fa-calendar-alt" aria-hidden="true"></i></span>
      <div class="w-100">
        <label for="snap" class="kpi-label mb-1">Snapshot date</label>
        <form method="get" action="dashboard.php" class="d-flex">
          <input type="hidden" name="page" value="delinquency">
          <select id="snap" name="date" class="custom-select custom-select-sm">
            <?php foreach ($dates as $d): ?><option value="<?= e($d) ?>" <?= $d === $date ? 'selected' : '' ?>><?= e(fmt_date($d)) ?></option><?php endforeach; ?>
            <?php if (!$dates): ?><option>No snapshot yet</option><?php endif; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-light ml-1">Go</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Aging summary</h3></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead><tr><th>Days past due</th><th class="num">Loans</th><th class="num">Amount past due</th><th class="num">Outstanding principal</th><th style="width: 30%">Share</th></tr></thead>
        <tbody>
        <?php $maxN = max(1, ...array_column($summary, 'n')); foreach ($summary as $label => $s): ?>
          <tr>
            <td><?= e($label) ?> days</td>
            <td class="num"><?= (int) $s['n'] ?></td>
            <td class="num"><?= e(money($s['amount'])) ?></td>
            <td class="num"><?= e(money($s['outstanding'])) ?></td>
            <td><div class="progress progress-xs mt-2" aria-hidden="true"><div class="progress-bar bg-danger" style="width: <?= round($s['n'] / $maxN * 100) ?>%"></div></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3 class="card-title">Delinquency list</h3></div>
  <div class="card-body">
    <?php if (!$dates): ?>
      <div class="empty-state"><i class="fas fa-clipboard-list"></i>No delinquency list has been generated yet.<?= can('delinquency', 'create') ? ' Click “Update list now”.' : '' ?></div>
    <?php else: ?>
      <table class="table table-hover js-datatable" data-export="true" data-title="FFMC Delinquency List <?= e($date) ?>" data-order='[[4,"desc"]]' data-empty="No past-due loans on this date.">
        <thead><tr><th>Member</th><th>Contact</th><th>Loan</th><th>Co-maker</th><th class="num">Days past due</th><th>Bracket</th><th class="num">Amount past due</th><th class="num">Outstanding</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a href="dashboard.php?page=member_view&id=<?= (int) $r['member_id'] ?>" class="font-weight-bold"><?= e($r['member_name']) ?></a><div class="small text-muted"><?= e($r['member_no']) ?></div></td>
            <td><?= e($r['contact_no'] ?? '—') ?></td>
            <td><a href="dashboard.php?page=loan_view&id=<?= (int) $r['loan_id'] ?>">#<?= (int) $r['loan_id'] ?></a> · <?= e($r['product_name']) ?></td>
            <td><?= e($r['co_maker']) ?></td>
            <td class="num" data-order="<?= (int) $r['days_past_due'] ?>"><?= (int) $r['days_past_due'] ?></td>
            <td><span class="badge <?= (int) $r['days_past_due'] > 90 ? 'badge-danger' : ((int) $r['days_past_due'] > 30 ? 'badge-warning' : 'badge-light') ?>"><?= e($r['aging_bracket']) ?></span></td>
            <td class="num"><?= e(money($r['amount_past_due'])) ?></td>
            <td class="num"><?= e(money($r['outstanding_balance'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
