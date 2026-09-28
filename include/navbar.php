<?php
declare(strict_types=1);
?>
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#" role="button" aria-label="Toggle menu"><i class="fas fa-bars"></i></a>
    </li>
    <li class="nav-item d-none d-md-flex align-items-center ml-2 text-muted small">
      <i class="far fa-calendar-alt mr-2"></i><?= e(date('l, F j, Y')) ?>
    </li>
  </ul>

  <ul class="navbar-nav ml-auto">
    <li class="nav-item dropdown">
      <a class="nav-link d-flex align-items-center" data-toggle="dropdown" href="#" aria-haspopup="true" aria-expanded="false">
        <i class="far fa-user-circle mr-2"></i>
        <span class="d-none d-sm-inline"><?= e($_SESSION['full_name'] ?? '') ?></span>
        <i class="fas fa-angle-down ml-2 small"></i>
      </a>
      <div class="dropdown-menu dropdown-menu-right">
        <span class="dropdown-item-text small text-muted"><?= e(ROLES[$_SESSION['role']] ?? '') ?></span>
        <div class="dropdown-divider"></div>
        <a href="dashboard.php?page=profile" class="dropdown-item"><i class="fas fa-key mr-2"></i>Change password</a>
        <div class="dropdown-divider"></div>
        <form action="logout.php" method="post" class="m-0">
          <?= csrf_field() ?>
          <button type="submit" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt mr-2"></i>Log out</button>
        </form>
      </div>
    </li>
  </ul>
</nav>
