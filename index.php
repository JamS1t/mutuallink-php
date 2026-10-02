<?php
declare(strict_types=1);

/**
 * Login page. The form posts back to this same page (professor's pattern).
 */

require_once __DIR__ . '/config/bootstrap.php';

if (session_is_valid()) {
    redirect('dashboard.php');
}

$error = '';
$loginId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginId = input('login_id');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if (!csrf_is_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (($wait = login_lock_seconds($loginId)) > 0) {
        // DB-backed throttle: survives clearing cookies or opening a new session
        $error = 'Too many failed attempts. Please try again in ' . (int) ceil($wait / 60) . ' more minute(s).';
    } elseif ($loginId === '' || $password === '') {
        $error = 'Please enter both username/email and password.';
    } else {
        // Username OR email, active accounts only (named parameters, native prepared statement).
        $stmt = db()->prepare(
            "SELECT user_id, username, full_name, role, password_hash
               FROM users
              WHERE (username = :login_id OR email = :login_email) AND status = 'active'
              LIMIT 1"
        );
        $stmt->execute([':login_id' => $loginId, ':login_email' => $loginId]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Upgrade the hash automatically if the cost/algorithm changed.
            if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, PASSWORD_OPTIONS)) {
                db()->prepare('UPDATE users SET password_hash = :h WHERE user_id = :id')
                    ->execute([':h' => password_hash($password, PASSWORD_BCRYPT, PASSWORD_OPTIONS), ':id' => $user['user_id']]);
            }
            login_clear_failures($loginId);
            begin_user_session($user);
            db()->prepare('UPDATE users SET last_login = NOW() WHERE user_id = :id')->execute([':id' => $user['user_id']]);
            audit_log('login', 'users', (int) $user['user_id'], 'Signed in');
            flash('success', 'Welcome, ' . $user['full_name'] . '.');
            redirect('dashboard.php');
        }

        // Same message for unknown user and wrong password (no account enumeration).
        $error = 'Invalid username or password.';
        if (login_register_failure($loginId) > 0) {
            $error = 'Too many failed attempts. Try again in ' . LOGIN_LOCK_MINUTES . ' minutes.';
        }
    }
    if ($error !== '') {
        flash('error', $error);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in · MutualLink</title>
  <link rel="icon" type="image/png" href="dist/img/favicon.png">
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="plugins/toastr/toastr.min.css">
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <link rel="stylesheet" href="dist/css/mutuallink.css">
</head>
<body class="hold-transition login-page">
<main class="login-box">
  <div class="login-brand">
    <img src="dist/img/logo-white-256.png" alt="MutualLink logo" class="login-logo" width="96" height="96">
    <h1>MutualLink</h1>
    <p>Franciscan Friends Multi-Purpose Cooperative</p>
  </div>

  <div class="card border-0">
    <div class="card-body login-card-body">
      <p class="login-box-msg text-muted px-0">Sign in with your staff account</p>

      <form action="" method="post" class="ml-form" novalidate>
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="login_id" class="small font-weight-bold">Username or email</label>
          <div class="input-group">
            <input type="text" name="login_id" id="login_id" class="form-control" autocomplete="username"
                   value="<?= e($loginId) ?>" required autofocus maxlength="150">
            <div class="input-group-append"><span class="input-group-text"><i class="fas fa-user"></i></span></div>
          </div>
        </div>
        <div class="form-group">
          <label for="password" class="small font-weight-bold">Password</label>
          <div class="input-group">
            <input type="password" name="password" id="password" class="form-control" autocomplete="current-password" required maxlength="72">
            <div class="input-group-append">
              <button type="button" class="btn btn-outline-secondary" data-toggle-password="#password" aria-label="Show password">
                <i class="fas fa-eye"></i>
              </button>
            </div>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block mt-4">
          <i class="fas fa-sign-in-alt mr-1"></i> Sign in
        </button>
      </form>
    </div>
  </div>
  <p class="login-foot">Authorized cooperative personnel only. Activity is recorded.<br>
    <a href="documentation/" class="text-white"><i class="fas fa-book mr-1"></i>System guide</a></p>
</main>

<?php
$flashJson = json_encode(take_flashes(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div id="ml-flash" data-messages="<?= e($flashJson) ?>" hidden></div>
<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="plugins/toastr/toastr.min.js"></script>
<script src="dist/js/mutuallink.js"></script>
</body>
</html>
