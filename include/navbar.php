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
    <li class="nav-item d-flex align-items-center mr-2">
      <button type="button" class="btn btn-sm btn-light border" data-toggle="modal" data-target="#ml-search-modal"
              aria-label="Search members (Ctrl+K or /)" title="Search members (Ctrl+K or /)">
        <i class="fas fa-search" aria-hidden="true"></i><span class="d-none d-lg-inline ml-1">Find member</span>
        <kbd class="d-none d-xl-inline ml-1 small">Ctrl K</kbd>
      </button>
    </li>
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

<?php // Global member search (Ctrl+K / "/"): reuses api/member_lookup.php; contextual actions follow the same RBAC matrix. ?>
<div class="modal fade" id="ml-search-modal" tabindex="-1" role="dialog" aria-labelledby="ml-search-title" aria-hidden="true"
     data-can-view="1"
     data-can-payment="<?= can('payments', 'create') ? '1' : '' ?>"
     data-can-savings="<?= can('savings', 'view') ? '1' : '' ?>"
     data-can-loan="<?= can('loans', 'create') ? '1' : '' ?>">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="ml-search-title"><i class="fas fa-search mr-2" aria-hidden="true"></i>Find a member</h2>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <label class="sr-only" for="ml-search-input">Member name or number</label>
        <input type="search" id="ml-search-input" class="form-control form-control-lg" placeholder="Last name, first name, or member no." autocomplete="off">
        <div id="ml-search-results" class="list-group lookup-results mt-2" aria-live="polite"></div>
      </div>
      <div class="modal-footer d-block py-1 small text-muted">
        Type at least 2 characters · <kbd>Esc</kbd> closes
      </div>
    </div>
  </div>
</div>
