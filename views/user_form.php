<?php
declare(strict_types=1);

$id = get_id();
$isEdit = $id > 0;
require_permission('users', $isEdit ? 'update' : 'create');

$user = ['username' => '', 'email' => '', 'full_name' => '', 'role' => 'cashier', 'status' => 'active'];
if ($isEdit) {
    $stmt = db()->prepare('SELECT user_id, username, email, full_name, role, status FROM users WHERE user_id = :id');
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();
    if (!$user) {
        flash('error', 'User not found.');
        redirect('dashboard.php?page=users');
    }
}
$isSelf = $isEdit && $id === current_user_id();

$title = $isEdit ? 'Edit user account' : 'Add user account';
$subtitle = $isEdit ? $user['full_name'] . ' · ' . $user['username'] : 'Create a sign-in account for a cooperative staff member.';
$headerActions = '<a href="dashboard.php?page=users" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Back to users</a>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $fullName = req($errors, 'full_name', 'Full name', 150);
    $username = pattern_in($errors, 'username', 'Username', '/^[A-Za-z0-9_]{3,50}$/', 'must be 3–50 letters, numbers, or underscores.', true) ?? '';
    $email = email_in($errors, 'email', 'Email', true) ?? '';
    $role = enum_in($errors, 'role', 'role', array_keys(ROLES));
    $status = enum_in($errors, 'status', 'status', ['active', 'inactive']);
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if (!$isEdit && $password === '') {
        $errors[] = 'Password is required for a new account.';
    }
    if ($password !== '' && !password_policy_ok($password)) {
        $errors[] = 'Password must be 8–72 characters and contain at least one letter and one number.';
    }
    if ($isSelf && ($role !== 'manager' || $status !== 'active')) {
        $errors[] = 'You cannot change your own role or deactivate yourself.';
    }

    // Keep at least one active manager
    if ($isEdit && !$errors && $user['role'] === 'manager' && $user['status'] === 'active' && ($role !== 'manager' || $status !== 'active')) {
        $managers = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'manager' AND status = 'active'")->fetchColumn();
        if ($managers <= 1) {
            $errors[] = 'At least one active Manager account must remain.';
        }
    }

    // Uniqueness (server-side; the live AJAX check is only a convenience)
    if ($username !== '' && $email !== '') {
        $stmt = db()->prepare('SELECT username, email FROM users WHERE (username = :u OR email = :e) AND user_id <> :id');
        $stmt->execute([':u' => $username, ':e' => $email, ':id' => $id]);
        foreach ($stmt->fetchAll() as $dup) {
            if (strcasecmp($dup['username'], $username) === 0) { $errors[] = 'Username is already taken.'; }
            if (strcasecmp($dup['email'], $email) === 0) { $errors[] = 'Email is already registered.'; }
        }
    }

    if ($errors) {
        flash_errors($errors);
    } else {
        try {
            $pdo = db();
            if ($isEdit) {
                $sql = 'UPDATE users SET full_name = :n, username = :u, email = :e, role = :r, status = :s, updated_by = :by';
                $params = [':n' => $fullName, ':u' => $username, ':e' => $email, ':r' => $role, ':s' => $status, ':by' => current_user_id(), ':id' => $id];
                if ($password !== '') {
                    $sql .= ', password_hash = :h';
                    $params[':h'] = password_hash($password, PASSWORD_BCRYPT, PASSWORD_OPTIONS);
                }
                $pdo->prepare($sql . ' WHERE user_id = :id')->execute($params);
                audit_log('update', 'users', $id, "User $username" . ($password !== '' ? ' (password reset)' : ''));
                flash('success', 'User "' . $username . '" has been updated.');
            } else {
                $pdo->prepare(
                    'INSERT INTO users (username, email, password_hash, full_name, role, status, created_by)
                     VALUES (:u, :e, :h, :n, :r, :s, :by)'
                )->execute([
                    ':u' => $username, ':e' => $email, ':h' => password_hash($password, PASSWORD_BCRYPT, PASSWORD_OPTIONS),
                    ':n' => $fullName, ':r' => $role, ':s' => $status, ':by' => current_user_id(),
                ]);
                $newId = (int) $pdo->lastInsertId();
                audit_log('create', 'users', $newId, "User $username (" . ROLES[$role] . ')');
                flash('success', 'User "' . $username . '" has been created.');
            }
            redirect('dashboard.php?page=users');
        } catch (Throwable $e) {
            db_failure($e);
        }
    }
}
?>
<div class="row">
  <div class="col-lg-8">
    <div class="card card-primary card-outline">
      <form method="post" action="" class="ml-form" novalidate>
        <?= csrf_field() ?>
        <div class="card-body">
          <div class="form-group">
            <label for="full_name">Full name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="full_name" name="full_name" required maxlength="150" value="<?= e(old('full_name', $user['full_name'])) ?>">
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="username">Username <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="username" name="username" required maxlength="50" pattern="[A-Za-z0-9_]{3,50}"
                     autocomplete="off" value="<?= e(old('username', $user['username'])) ?>"
                     data-check="username" data-exclude-id="<?= $id ?>" data-check-message="Username is already taken.">
              <div class="invalid-feedback js-exists"></div>
              <small class="form-text text-muted">3–50 letters, numbers, or underscores.</small>
            </div>
            <div class="form-group col-md-6">
              <label for="email">Email <span class="text-danger">*</span></label>
              <input type="email" class="form-control" id="email" name="email" required maxlength="150" autocomplete="off"
                     value="<?= e(old('email', $user['email'])) ?>"
                     data-check="email" data-exclude-id="<?= $id ?>" data-check-message="Email is already registered.">
              <div class="invalid-feedback js-exists"></div>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="role">Role <span class="text-danger">*</span></label>
              <select class="custom-select" id="role" name="role" <?= $isSelf ? 'disabled' : '' ?>>
                <?php foreach (ROLES as $key => $text): ?>
                  <option value="<?= e($key) ?>" <?= old('role', $user['role']) === $key ? 'selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($isSelf): ?><input type="hidden" name="role" value="manager"><?php endif; ?>
            </div>
            <div class="form-group col-md-6">
              <label for="status">Status <span class="text-danger">*</span></label>
              <select class="custom-select" id="status" name="status" <?= $isSelf ? 'disabled' : '' ?>>
                <option value="active" <?= old('status', $user['status']) === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= old('status', $user['status']) === 'inactive' ? 'selected' : '' ?>>Inactive</option>
              </select>
              <?php if ($isSelf): ?><input type="hidden" name="status" value="active"><?php endif; ?>
            </div>
          </div>
          <div class="form-group mb-0">
            <label for="password"><?= $isEdit ? 'Reset password' : 'Password <span class="text-danger">*</span>' ?></label>
            <div class="input-group">
              <input type="password" class="form-control" id="password" name="password" minlength="8" maxlength="72" autocomplete="new-password"
                     <?= $isEdit ? 'placeholder="Leave blank to keep the current password"' : 'required' ?>>
              <div class="input-group-append">
                <button type="button" class="btn btn-outline-secondary" data-toggle-password="#password" aria-label="Show password"><i class="fas fa-eye"></i></button>
              </div>
            </div>
            <small class="form-text text-muted">At least 8 characters with a letter and a number. Stored as a bcrypt hash.</small>
          </div>
        </div>
        <div class="card-footer bg-white d-flex">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> <?= $isEdit ? 'Save changes' : 'Create account' ?></button>
          <a href="dashboard.php?page=users" class="btn btn-light ml-auto">Cancel</a>
        </div>
      </form>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><h3 class="card-title">What each role can do</h3></div>
      <div class="card-body small">
        <p class="mb-2"><strong>Manager</strong> — approves and releases loans, manages users, products, and settings.</p>
        <p class="mb-2"><strong>Cashier</strong> — registers members, posts deposits, withdrawals, and loan payments, issues receipts.</p>
        <p class="mb-2"><strong>Loan Officer</strong> — encodes loan applications, checks eligibility, runs delinquency and reminders.</p>
        <p class="mb-2"><strong>Bookkeeper</strong> — reviews postings, makes corrections (reversals and voids), prepares reports.</p>
        <p class="mb-0"><strong>Board / Auditor</strong> — read-only access to records, reports, and the audit log.</p>
      </div>
    </div>
  </div>
</div>
