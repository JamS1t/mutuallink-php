<?php
declare(strict_types=1);

/**
 * Front controller / router: dashboard.php?page=<name>
 *
 * Every page passes through the route-guard middleware functions before its
 * view runs: require_login() → require_permission() → require_csrf().
 */

require_once __DIR__ . '/config/bootstrap.php';

// page => [module, action, active menu item]
const ROUTES = [
    'home'           => ['dashboard', 'view', 'home'],
    'profile'        => ['profile', 'update', ''],
    'users'          => ['users', 'view', 'users'],
    'user_form'      => ['users', 'view', 'users'],
    'members'        => ['members', 'view', 'members'],
    'member_form'    => ['members', 'view', 'members'],
    'member_view'    => ['members', 'view', 'members'],
    'savings'        => ['savings', 'view', 'savings'],
    'passbook'       => ['savings', 'view', 'savings'],
    'savings_post'   => ['savings_txn', 'create', 'savings'],
    'savings_interest' => ['savings_interest', 'view', 'savings_interest'],
    'products'       => ['products', 'view', 'products'],
    'product_form'   => ['products', 'view', 'products'],
    'loans'          => ['loans', 'view', 'loans'],
    'loan_form'      => ['loans', 'create', 'loans'],
    'loan_view'      => ['loans', 'view', 'loans'],
    'schedule_print' => ['loans', 'view', 'loans'],
    'payments'       => ['payments', 'view', 'payments'],
    'payment_post'   => ['payments', 'create', 'payments'],
    'receipt'        => ['payments', 'view', 'payments'],
    'delinquency'    => ['delinquency', 'view', 'delinquency'],
    'demand_letter'  => ['delinquency', 'view', 'delinquency'],
    'notifications'  => ['notifications', 'view', 'notifications'],
    'reports'        => ['reports', 'view', 'reports'],
    'eod_close'      => ['eod_close', 'view', 'eod_close'],
    'reconcile'      => ['reconcile', 'view', 'reconcile'],
    'audit'          => ['audit', 'view', 'audit'],
    'settings'       => ['settings', 'view', 'settings'],
];

// Guard 1: authentication
require_login();

$page = $_GET['page'] ?? 'home';
if (!is_string($page) || !isset(ROUTES[$page])) {
    $page = '404';
}

// Views may override these.
$title = 'MutualLink';
$subtitle = '';
$headerActions = '';   // pre-escaped HTML for buttons at the top right
$layout = 'app';       // 'app' or 'print'
$active = ROUTES[$page][2] ?? '';

ob_start(); // buffer the view so it can still redirect (PRG) or be replaced by the 403 page
try {
    if ($page === '404') {
        http_response_code(404);
        require __DIR__ . '/views/404.php';
    } else {
        [$module, $action] = ROUTES[$page];
        require_permission($module, $action); // Guard 2: authorization (RBAC)
        require_csrf();                       // Guard 3: CSRF on every POST
        require __DIR__ . '/views/' . $page . '.php';
    }
} catch (ForbiddenException) {
    ob_clean();
    http_response_code(403);
    $title = 'Access denied';
    $subtitle = $headerActions = '';
    $layout = 'app';
    require __DIR__ . '/views/403.php';
}
$content = ob_get_clean();
take_field_errors(); // discard any field errors the rendered page did not consume, so they never leak into another form
?>
<!DOCTYPE html>
<html lang="en">
<?php require __DIR__ . '/include/head.php'; ?>
<?php if ($layout === 'print'): ?>
<body class="bg-light">
  <?= $content ?>
  <?php require __DIR__ . '/include/scripts.php'; ?>
</body>
<?php else: ?>
<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
<a class="skip-link" href="#content-main">Skip to content</a>
<div class="wrapper">
  <?php require __DIR__ . '/include/navbar.php'; ?>
  <?php require __DIR__ . '/include/sidebar.php'; ?>

  <div class="content-wrapper" id="content-main" tabindex="-1">
    <div class="content-header">
      <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-start">
          <div class="mb-2">
            <h1 class="m-0"><?= e($title) ?></h1>
            <?php if ($subtitle !== ''): ?><div class="page-sub"><?= e($subtitle) ?></div><?php endif; ?>
          </div>
          <div class="mb-2 no-print"><?= $headerActions ?></div>
        </div>
      </div>
    </div>
    <section class="content">
      <div class="container-fluid pb-4">
        <?= $content ?>
      </div>
    </section>
  </div>

  <?php require __DIR__ . '/include/footer.php'; ?>
</div>
<?php require __DIR__ . '/include/scripts.php'; ?>
</body>
<?php endif; ?>
</html>
