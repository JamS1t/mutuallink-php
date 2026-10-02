<?php
declare(strict_types=1);

$title = 'Members';
$subtitle = 'One record per member: profile, accounts, loans, and payment history.';
if (can('members', 'create')) {
    $headerActions = '<a href="dashboard.php?page=member_form" class="btn btn-primary"><i class="fas fa-user-plus mr-1"></i> Register member</a>';
}

// Deactivate / reactivate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'toggle_status') {
    require_permission('members', 'delete');
    $id = post_id('member_id');
    try {
        $stmt = db()->prepare('SELECT member_id, member_no, status, date_approved FROM members WHERE member_id = :id');
        $stmt->execute([':id' => $id]);
        $m = $stmt->fetch();
        if (!$m) {
            flash('error', 'Member not found.');
        } else {
            // Reactivating never skips the approval step: a never-approved member goes back to applicant.
            $newStatus = $m['status'] !== 'inactive' ? 'inactive' : ($m['date_approved'] ? 'active' : 'applicant');
            $stmt = db()->prepare("SELECT COUNT(*) FROM loans WHERE member_id = :id AND status IN ('pending','approved','released')");
            $stmt->execute([':id' => $id]);
            if ($newStatus === 'inactive' && (int) $stmt->fetchColumn() > 0) {
                flash('error', 'This member still has an active or pending loan and cannot be deactivated.');
            } else {
                $upd = db()->prepare('UPDATE members SET status = :s, updated_by = :u WHERE member_id = :id AND status = :old');
                $upd->execute([':s' => $newStatus, ':u' => current_user_id(), ':id' => $id, ':old' => $m['status']]);
                if ($upd->rowCount() === 1) {
                    audit_log($newStatus === 'active' ? 'activate' : 'deactivate', 'members', $id, 'Member ' . $m['member_no']);
                    flash('success', 'Member ' . $m['member_no'] . ' is now ' . $newStatus . '.');
                }
            }
        }
    } catch (Throwable $e) {
        db_failure($e);
    }
    redirect('dashboard.php?page=members');
}

$status = in_array($_GET['status'] ?? '', ['active', 'applicant', 'inactive', 'all'], true) ? $_GET['status'] : 'active';

$sql = "SELECT m.member_id, m.member_no, m.last_name, m.first_name, m.middle_name, m.contact_no, m.member_type,
               m.date_of_membership, m.status,
               COALESCE(sc.balance, 0) AS share_capital,
               (SELECT COUNT(*) FROM loans l WHERE l.member_id = m.member_id AND l.status = 'released') AS active_loans
          FROM members m
          LEFT JOIN savings_accounts sc ON sc.member_id = m.member_id AND sc.account_type = 'share_capital'";
if ($status !== 'all') {
    $stmt = db()->prepare($sql . ' WHERE m.status = :s ORDER BY m.last_name, m.first_name');
    $stmt->execute([':s' => $status]);
} else {
    $stmt = db()->query($sql . ' ORDER BY m.last_name, m.first_name');
}
$members = $stmt->fetchAll();
?>
<div class="card">
  <div class="card-header d-flex align-items-center flex-wrap">
    <div class="btn-group btn-group-sm" role="group" aria-label="Filter by status">
      <?php foreach (['active' => 'Active', 'applicant' => 'Applicants', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $v): ?>
        <a href="dashboard.php?page=members&status=<?= $k ?>" class="btn <?= $status === $k ? 'btn-primary' : 'btn-light' ?>"><?= $v ?></a>
      <?php endforeach; ?>
    </div>
    <span class="ml-auto text-muted small"><?= count($members) ?> member(s)</span>
  </div>
  <div class="card-body">
    <table class="table table-hover js-datatable" data-export="true" data-title="FFMPC Members" data-order='[[1,"asc"]]'>
      <thead>
        <tr><th>Member no.</th><th>Name</th><th>Type</th><th>Contact</th><th>Member since</th><th class="num">Share capital</th><th>Loans</th><th>Status</th><th class="no-sort text-right">Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($members as $m): ?>
        <tr>
          <td class="text-nowrap"><?= e($m['member_no']) ?></td>
          <td><a href="dashboard.php?page=member_view&id=<?= (int) $m['member_id'] ?>" class="font-weight-bold"><?= e($m['last_name'] . ', ' . $m['first_name'] . ($m['middle_name'] ? ' ' . mb_substr($m['middle_name'], 0, 1) . '.' : '')) ?></a></td>
          <td><?= e($m['member_type'] === 'school' ? 'School-based' : 'Outside') ?></td>
          <td><?= e($m['contact_no'] ?? '—') ?></td>
          <td data-order="<?= e($m['date_of_membership']) ?>"><?= e(fmt_date($m['date_of_membership'])) ?></td>
          <td class="num"><?= e(money($m['share_capital'])) ?></td>
          <td><?= (int) $m['active_loans'] > 0 ? '<span class="badge badge-primary">' . (int) $m['active_loans'] . ' active</span>' : '<span class="text-muted">—</span>' ?></td>
          <td><?= badge($m['status']) ?></td>
          <td class="text-right text-nowrap">
            <a href="dashboard.php?page=member_view&id=<?= (int) $m['member_id'] ?>" class="btn btn-sm btn-light" title="View record" aria-label="View record of <?= e($m['first_name']) ?> <?= e($m['last_name']) ?>"><i class="fas fa-eye" aria-hidden="true"></i></a>
            <?php if (can('members', 'update')): ?>
              <a href="dashboard.php?page=member_form&id=<?= (int) $m['member_id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit" aria-label="Edit profile of <?= e($m['first_name']) ?> <?= e($m['last_name']) ?>"><i class="fas fa-pen" aria-hidden="true"></i></a>
            <?php endif; ?>
            <?php if (can('members', 'delete')): ?>
              <form method="post" action="" class="d-inline ml-form"
                    data-confirm="<?= e(($m['status'] !== 'inactive' ? 'Deactivate' : 'Reactivate') . ' member ' . $m['member_no'] . '?') ?>"
                    data-confirm-button="<?= $m['status'] !== 'inactive' ? 'Deactivate' : 'Reactivate' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="member_id" value="<?= (int) $m['member_id'] ?>">
                <button type="submit" class="btn btn-sm <?= $m['status'] !== 'inactive' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                        title="<?= $m['status'] !== 'inactive' ? 'Deactivate' : 'Reactivate' ?>"
                        aria-label="<?= $m['status'] !== 'inactive' ? 'Deactivate' : 'Reactivate' ?> <?= e($m['first_name']) ?> <?= e($m['last_name']) ?>">
                  <i class="fas <?= $m['status'] !== 'inactive' ? 'fa-user-slash' : 'fa-user-check' ?>" aria-hidden="true"></i>
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
