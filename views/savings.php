<?php
declare(strict_types=1);

$title = 'Savings & share capital';
$subtitle = 'Passbook and office ledger in one record: every deposit and withdrawal carries its running balance and receipt number.';

// Open a new account for a member (one per type)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'open_account') {
    require_permission('savings', 'create');
    $errors = [];
    $memberId = post_id('member_id');
    $type = enum_in($errors, 'account_type', 'account type', array_keys(ACCOUNT_TYPES));
    $stmt = db()->prepare("SELECT member_no FROM members WHERE member_id = :id AND status = 'active'");
    $stmt->execute([':id' => $memberId]);
    $memberNo = $stmt->fetchColumn();
    if ($memberNo === false) {
        $errors[] = 'Select an active member.';
    }
    if (!$errors) {
        try {
            db()->prepare('INSERT INTO savings_accounts (member_id, account_type, balance, date_opened) VALUES (:m, :t, 0, CURDATE())')
                ->execute([':m' => $memberId, ':t' => $type]);
            $sid = (int) db()->lastInsertId();
            audit_log('create', 'savings_accounts', $sid, ACCOUNT_TYPES[$type] . " for $memberNo");
            flash('success', ACCOUNT_TYPES[$type] . " account opened for $memberNo.");
            redirect('dashboard.php?page=passbook&id=' . $sid);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // duplicate (member, type)
                flash('error', 'This member already has a ' . ACCOUNT_TYPES[$type] . ' account.');
            } else {
                db_failure($e);
            }
        }
    } else {
        flash_errors($errors);
    }
    redirect('dashboard.php?page=savings' . ($memberId ? '&member_id=' . $memberId : ''));
}

// Selected member for opening an account
$memberId = get_id('member_id');
$selected = null;
$openTypes = [];
if ($memberId && can('savings', 'create')) {
    $stmt = db()->prepare("SELECT member_id, member_no, last_name, first_name FROM members WHERE member_id = :id AND status = 'active'");
    $stmt->execute([':id' => $memberId]);
    $selected = $stmt->fetch();
    if ($selected) {
        $stmt = db()->prepare('SELECT account_type FROM savings_accounts WHERE member_id = :id');
        $stmt->execute([':id' => $memberId]);
        $openTypes = array_diff(array_keys(ACCOUNT_TYPES), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

$type = array_key_exists($_GET['type'] ?? '', ACCOUNT_TYPES) ? $_GET['type'] : '';
$sql = "SELECT a.savings_id, a.account_type, a.balance, a.date_opened, a.status,
               m.member_id, m.member_no, CONCAT(m.last_name, ', ', m.first_name) AS member_name
          FROM savings_accounts a JOIN members m ON m.member_id = a.member_id";
if ($type !== '') {
    $stmt = db()->prepare($sql . ' WHERE a.account_type = :t ORDER BY member_name');
    $stmt->execute([':t' => $type]);
} else {
    $stmt = db()->query($sql . ' ORDER BY member_name, a.account_type');
}
$accounts = $stmt->fetchAll();

$totals = db()->query("SELECT account_type, COUNT(*) AS n, COALESCE(SUM(balance),0) AS total FROM savings_accounts WHERE status = 'active' GROUP BY account_type")
    ->fetchAll(PDO::FETCH_UNIQUE);
?>
<div class="row">
  <?php foreach (ACCOUNT_TYPES as $k => $v): $t = $totals[$k] ?? ['n' => 0, 'total' => 0]; ?>
    <div class="col-sm-6 col-xl-3 mb-3">
      <a href="dashboard.php?page=savings&type=<?= $k ?>" class="text-reset d-block h-100">
        <div class="kpi <?= $type === $k ? 'border-success' : '' ?>">
          <span class="kpi-icon <?= $k === 'share_capital' ? 'tone-green' : ($k === 'capital_build_up' ? 'tone-brown' : 'tone-blue') ?>">
            <i class="fas <?= $k === 'share_capital' ? 'fa-landmark' : ($k === 'time_deposit' ? 'fa-hourglass-half' : 'fa-piggy-bank') ?>" aria-hidden="true"></i>
          </span>
          <div>
            <div class="kpi-label"><?= e($v) ?></div>
            <div class="kpi-value"><?= e(money($t['total'])) ?></div>
            <div class="kpi-hint"><?= (int) $t['n'] ?> active account(s)</div>
          </div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="row">
  <div class="<?= can('savings', 'create') ? 'col-xl-8' : 'col-12' ?>">
    <div class="card">
      <div class="card-header d-flex align-items-center">
        <h3 class="card-title"><?= $type ? e(ACCOUNT_TYPES[$type]) . ' accounts' : 'All accounts' ?></h3>
        <?php if ($type): ?><a href="dashboard.php?page=savings" class="ml-auto small">Show all types</a><?php endif; ?>
      </div>
      <div class="card-body">
        <table class="table table-hover js-datatable" data-export="true" data-title="FFMC Savings Accounts" data-order='[[0,"asc"]]'>
          <thead><tr><th>Member</th><th>Account</th><th>Opened</th><th>Status</th><th class="num">Balance</th><th class="no-sort text-right">Actions</th></tr></thead>
          <tbody>
          <?php foreach ($accounts as $a): ?>
            <tr>
              <td><a href="dashboard.php?page=member_view&id=<?= (int) $a['member_id'] ?>" class="font-weight-bold"><?= e($a['member_name']) ?></a><div class="small text-muted"><?= e($a['member_no']) ?></div></td>
              <td><?= e(ACCOUNT_TYPES[$a['account_type']]) ?></td>
              <td data-order="<?= e($a['date_opened']) ?>"><?= e(fmt_date($a['date_opened'])) ?></td>
              <td><?= badge($a['status']) ?></td>
              <td class="num" data-order="<?= e($a['balance']) ?>"><?= e(money($a['balance'])) ?></td>
              <td class="text-right text-nowrap">
                <a href="dashboard.php?page=passbook&id=<?= (int) $a['savings_id'] ?>" class="btn btn-sm btn-light"><i class="fas fa-book-open"></i> Passbook</a>
                <?php if (can('savings_txn', 'create') && $a['status'] === 'active'): ?>
                  <a href="dashboard.php?page=savings_post&id=<?= (int) $a['savings_id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-exchange-alt"></i> Post</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if (can('savings', 'create')): ?>
    <div class="col-xl-4">
      <div class="card card-primary card-outline">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-folder-plus mr-2"></i>Open an account</h3></div>
        <div class="card-body">
          <?php if (!$selected): ?>
            <label for="open-lookup">Find member</label>
            <input type="search" id="open-lookup" class="form-control" placeholder="Last name, first name, or member no." autocomplete="off"
                   data-member-lookup="#open-results" data-select-url="dashboard.php?page=savings&member_id={id}">
            <div id="open-results" class="list-group lookup-results mt-2" aria-live="polite"></div>
          <?php else: ?>
            <p class="mb-2"><strong><?= e($selected['last_name'] . ', ' . $selected['first_name']) ?></strong><br><span class="text-muted small"><?= e($selected['member_no']) ?></span></p>
            <?php if (!$openTypes): ?>
              <div class="alert alert-light mb-0">This member already has all four account types.</div>
            <?php else: ?>
              <form method="post" action="" class="ml-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="open_account">
                <input type="hidden" name="member_id" value="<?= (int) $selected['member_id'] ?>">
                <div class="form-group">
                  <label for="account_type">Account type</label>
                  <select class="custom-select" id="account_type" name="account_type">
                    <?php foreach ($openTypes as $t): ?><option value="<?= e($t) ?>"><?= e(ACCOUNT_TYPES[$t]) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-check mr-1"></i> Open account</button>
              </form>
            <?php endif; ?>
            <a href="dashboard.php?page=savings" class="btn btn-link btn-sm px-0 mt-2">Choose a different member</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
