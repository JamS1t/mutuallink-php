<?php
declare(strict_types=1);

// Menu items are shown only when the RBAC matrix allows the module (same check the router uses).
$menu = [
    ['header' => null, 'items' => [
        ['home', 'Dashboard', 'fas fa-th-large', 'dashboard'],
    ]],
    ['header' => 'Members & Savings', 'items' => [
        ['members', 'Members', 'fas fa-users', 'members'],
        ['savings', 'Savings & Share Capital', 'fas fa-piggy-bank', 'savings'],
    ]],
    ['header' => 'Lending', 'items' => [
        ['loans', 'Loans', 'fas fa-hand-holding-usd', 'loans'],
        ['payments', 'Payments', 'fas fa-receipt', 'payments'],
        ['delinquency', 'Delinquency', 'fas fa-exclamation-triangle', 'delinquency'],
        ['notifications', 'Reminders', 'fas fa-bell', 'notifications'],
        ['products', 'Loan Products', 'fas fa-tags', 'products'],
    ]],
    ['header' => 'Reports', 'items' => [
        ['reports', 'Reports', 'fas fa-chart-bar', 'reports'],
        ['audit', 'Audit Log', 'fas fa-history', 'audit'],
    ]],
    ['header' => 'Administration', 'items' => [
        ['users', 'User Accounts', 'fas fa-user-shield', 'users'],
        ['settings', 'Settings', 'fas fa-sliders-h', 'settings'],
    ]],
];
$initials = strtoupper(implode('', array_map(fn ($w) => $w[0] ?? '', array_slice(explode(' ', $_SESSION['full_name'] ?? 'U'), 0, 2))));
?>
<aside class="main-sidebar sidebar-light-success elevation-1">
  <a href="dashboard.php" class="brand-link">
    <img src="dist/img/logo-white-96.png" alt="" class="brand-logo" width="34" height="34">
    <span class="brand-text">MutualLink<small>FFMPC Lending &amp; Savings</small></span>
  </a>

  <div class="sidebar">
    <div class="user-panel mt-3 pb-3 mb-2 d-flex align-items-center">
      <span class="avatar ml-2" aria-hidden="true"><?= e($initials) ?></span>
      <div class="info">
        <span class="d-block font-weight-bold text-dark"><?= e($_SESSION['full_name'] ?? '') ?></span>
        <span class="role-chip"><?= e(ROLES[$_SESSION['role']] ?? '') ?></span>
      </div>
    </div>

    <nav class="mt-2" aria-label="Main menu">
      <ul class="nav nav-pills nav-sidebar flex-column nav-child-indent" data-widget="treeview" role="menu">
        <?php foreach ($menu as $group):
            $visible = array_filter($group['items'], fn ($i) => can($i[3], 'view'));
            if (!$visible) {
                continue;
            } ?>
          <?php if ($group['header']): ?>
            <li class="nav-header"><?= e($group['header']) ?></li>
          <?php endif; ?>
          <?php foreach ($visible as [$key, $text, $icon]): ?>
            <li class="nav-item">
              <a href="dashboard.php?page=<?= e($key) ?>" class="nav-link <?= ($active ?? '') === $key ? 'active' : '' ?>">
                <i class="nav-icon <?= e($icon) ?>"></i>
                <p><?= e($text) ?></p>
              </a>
            </li>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </ul>
    </nav>
  </div>
</aside>
