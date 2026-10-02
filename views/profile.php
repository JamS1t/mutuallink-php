<?php
declare(strict_types=1);

$title = 'Change password';
$subtitle = 'Keep your account secure. Use at least 8 characters with letters and numbers.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE user_id = :id');
    $stmt->execute([':id' => current_user_id()]);
    $hash = (string) $stmt->fetchColumn();

    if (!password_verify($current, $hash)) {
        $errors['current_password'] = 'Current password is incorrect.';
    }
    if (!password_policy_ok($new)) {
        $errors['new_password'] = 'New password must be 8–72 characters and contain at least one letter and one number.';
    }
    if ($new !== $confirm) {
        $errors['confirm_password'] = 'New password and confirmation do not match.';
    }
    if ($new !== '' && $new === $current) {
        $errors['new_password'] = 'New password must be different from the current one.';
    }

    if ($errors) {
        flash_errors($errors);
    } else {
        try {
            db()->prepare('UPDATE users SET password_hash = :h, updated_by = :u WHERE user_id = :id')
                ->execute([':h' => password_hash($new, PASSWORD_BCRYPT, PASSWORD_OPTIONS), ':u' => current_user_id(), ':id' => current_user_id()]);
            session_regenerate_id(true);
            audit_log('change_password', 'users', current_user_id(), 'Changed own password');
            flash('success', 'Your password has been changed.');
            redirect('dashboard.php?page=profile');
        } catch (Throwable $e) {
            db_failure($e);
        }
    }
}
?>
<?php $errors = take_field_errors(); ?>
<div class="row">
  <div class="col-lg-6">
    <div class="card card-primary card-outline">
      <div class="card-header"><h3 class="card-title"><i class="fas fa-key mr-2"></i>Update your password</h3></div>
      <form method="post" action="" class="ml-form" data-dirty-guard>
        <?= csrf_field() ?>
        <?= error_summary($errors) ?>
        <div class="card-body">
          <div class="form-group">
            <label for="current_password">Current password <span class="text-danger">*</span></label>
            <input type="password" class="form-control<?= invalid_class($errors, 'current_password') ?>" id="current_password" name="current_password" required autocomplete="current-password" maxlength="72"<?= invalid_attrs($errors, 'current_password') ?>>
            <?= field_feedback($errors, 'current_password') ?>
          </div>
          <div class="form-group">
            <label for="new_password">New password <span class="text-danger">*</span></label>
            <input type="password" class="form-control<?= invalid_class($errors, 'new_password') ?>" id="new_password" name="new_password" required minlength="8" maxlength="72" autocomplete="new-password"<?= invalid_attrs($errors, 'new_password') ?>>
            <?= field_feedback($errors, 'new_password') ?>
            <small class="form-text text-muted">Stored only as a bcrypt hash; nobody, including the Manager, can read it.</small>
          </div>
          <div class="form-group mb-0">
            <label for="confirm_password">Confirm new password <span class="text-danger">*</span></label>
            <input type="password" class="form-control<?= invalid_class($errors, 'confirm_password') ?>" id="confirm_password" name="confirm_password" required minlength="8" maxlength="72" autocomplete="new-password"<?= invalid_attrs($errors, 'confirm_password') ?>>
            <?= field_feedback($errors, 'confirm_password') ?>
          </div>
        </div>
        <div class="card-footer bg-white">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save new password</button>
        </div>
      </form>
    </div>
  </div>
</div>
