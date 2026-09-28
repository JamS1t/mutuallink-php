<?php
declare(strict_types=1);

$title = 'User accounts';
$subtitle = 'Staff who can sign in. Accounts are deactivated, never deleted, so the audit trail stays intact.';
if (can('users', 'create')) {
    $headerActions = '<a href="dashboard.php?page=user_form" class="btn btn-primary"><i class="fas fa-user-plus mr-1"></i> Add user</a>';
}

// Deactivate / reactivate (the "D" of CRUD for accounts)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'toggle_status') {
    require_permission('users', 'delete');
    $id = post_id('user_id');
    try {
        $stmt = db()->prepare('SELECT user_id, username, role, status FROM users WHERE user_id = :id');
        $stmt->execute([':id' => $id]);
        $target = $stmt->fetch();

        if (!$target) {
            flash('error', 'User not found.');
        } elseif ($id === current_user_id()) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
            $activeManagers = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'manager' AND status = 'active'")->fetchColumn();
            if ($newStatus === 'inactive' && $target['role'] === 'manager' && $activeManagers <= 1) {
                flash('error', 'At least one active Manager account must remain.');
            } else {
                $upd = db()->prepare('UPDATE users SET status = :s, updated_by = :u WHERE user_id = :id AND status = :old');
                $upd->execute([':s' => $newStatus, ':u' => current_user_id(), ':id' => $id, ':old' => $target['status']]);
                if ($upd->rowCount() === 1) {
                    audit_log($newStatus === 'active' ? 'activate' : 'deactivate', 'users', $id, 'User ' . $target['username']);
                    flash('success', 'User "' . $target['username'] . '" is now ' . $newStatus . '.');
                } else {
                    flash('error', 'The account was changed by someone else. Please try again.');
                }
            }
        }
    } catch (Throwable $e) {
        db_failure($e);
    }
    redirect('dashboard.php?page=users');
}

$users = db()->query(
    'SELECT user_id, username, email, full_name, role, status, last_login FROM users ORDER BY status, full_name'
)->fetchAll();
?>
<div class="card">
  <div class="card-body">
    <table class="table table-hover js-datatable" data-order='[[0,"asc"]]'>
      <thead>
        <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th class="no-sort text-right">Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td class="font-weight-bold"><?= e($u['full_name']) ?><?= (int) $u['user_id'] === current_user_id() ? ' <span class="badge badge-light">You</span>' : '' ?></td>
          <td><?= e($u['username']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><?= e(ROLES[$u['role']] ?? $u['role']) ?></td>
          <td><?= badge($u['status']) ?></td>
          <td data-order="<?= e($u['last_login'] ?? '') ?>"><?= e($u['last_login'] ? fmt_date($u['last_login'], 'M d, Y g:i A') : 'Never') ?></td>
          <td class="text-right text-nowrap">
            <?php if (can('users', 'update')): ?>
              <a href="dashboard.php?page=user_form&id=<?= (int) $u['user_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-pen"></i> Edit</a>
            <?php endif; ?>
            <?php if (can('users', 'delete') && (int) $u['user_id'] !== current_user_id()): ?>
              <form method="post" action="" class="d-inline ml-form"
                    data-confirm="<?= e(($u['status'] === 'active' ? 'Deactivate' : 'Reactivate') . ' the account of ' . $u['full_name'] . '?') ?>"
                    data-confirm-button="<?= $u['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                <?php if ($u['status'] === 'active'): ?>
                  <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-user-slash"></i> Deactivate</button>
                <?php else: ?>
                  <button type="submit" class="btn btn-sm btn-outline-success"><i class="fas fa-user-check"></i> Reactivate</button>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
