<?php
declare(strict_types=1);

// Audit trail: who entered or approved what, and when (read-only; insert-only table).
$title = 'Audit log';
$subtitle = 'Every sign-in, entry, approval, correction, and change, with the user who made it.';

$from = get_date('from', date('Y-m-d', strtotime('-7 days')));
$to = get_date('to', date('Y-m-d'));
$userId = get_id('user');
$tables = ['users', 'members', 'savings_accounts', 'savings_transactions', 'loan_products', 'loans', 'payments', 'delinquency', 'notifications', 'settings'];
$table = in_array($_GET['table'] ?? '', $tables, true) ? $_GET['table'] : '';

$sql = "SELECT a.log_id, a.timestamp, a.action, a.table_affected, a.record_id, a.details, a.ip_address, u.full_name, u.role
          FROM audit_log a LEFT JOIN users u ON u.user_id = a.user_id
         WHERE a.timestamp >= :f AND a.timestamp < DATE_ADD(:t, INTERVAL 1 DAY)";
$params = [':f' => $from, ':t' => $to];
if ($userId) {
    $sql .= ' AND a.user_id = :u';
    $params[':u'] = $userId;
}
if ($table !== '') {
    $sql .= ' AND a.table_affected = :tb';
    $params[':tb'] = $table;
}
$stmt = db()->prepare($sql . ' ORDER BY a.log_id DESC LIMIT 500');
$stmt->execute($params);
$rows = $stmt->fetchAll();
$users = db()->query('SELECT user_id, full_name FROM users ORDER BY full_name')->fetchAll();
?>
<div class="card">
  <div class="card-body">
    <form method="get" action="dashboard.php" class="form-row align-items-end">
      <input type="hidden" name="page" value="audit">
      <div class="col-sm-6 col-lg-2 form-group"><label for="from">From</label><input type="date" id="from" name="from" class="form-control" value="<?= e($from) ?>"></div>
      <div class="col-sm-6 col-lg-2 form-group"><label for="to">To</label><input type="date" id="to" name="to" class="form-control" value="<?= e($to) ?>"></div>
      <div class="col-sm-6 col-lg-3 form-group"><label for="user">User</label>
        <select id="user" name="user" class="custom-select"><option value="">All users</option>
          <?php foreach ($users as $u): ?><option value="<?= (int) $u['user_id'] ?>" <?= $userId === (int) $u['user_id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-sm-6 col-lg-3 form-group"><label for="table">Record type</label>
        <select id="table" name="table" class="custom-select"><option value="">All</option>
          <?php foreach ($tables as $t): ?><option value="<?= $t ?>" <?= $table === $t ? 'selected' : '' ?>><?= e(label($t)) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-lg-2 form-group"><button class="btn btn-outline-primary btn-block"><i class="fas fa-filter mr-1"></i> Filter</button></div>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-body">
    <?php if (count($rows) === 500): ?><div class="alert alert-light small">Showing the latest 500 entries. Narrow the filters to see older ones.</div><?php endif; ?>
    <table class="table table-sm table-hover js-datatable" data-export="true" data-title="FFMPC Audit Log <?= e($from) ?> to <?= e($to) ?>" data-order='[[0,"desc"]]' data-page-length="50">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td data-order="<?= (int) $r['log_id'] ?>" class="text-nowrap"><?= e(fmt_date($r['timestamp'], 'M d, Y g:i:s A')) ?></td>
          <td><?= e($r['full_name'] ?? 'System') ?><div class="small text-muted"><?= e(ROLES[$r['role']] ?? '') ?></div></td>
          <td><span class="badge badge-light"><?= e(label($r['action'])) ?></span></td>
          <td class="text-nowrap"><?= e(label($r['table_affected'])) ?><?= $r['record_id'] ? ' #' . (int) $r['record_id'] : '' ?></td>
          <td class="small"><?= e($r['details']) ?></td>
          <td class="small text-muted"><?= e($r['ip_address']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
