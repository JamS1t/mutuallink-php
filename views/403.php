<?php
declare(strict_types=1);

$title = 'Access denied';
?>
<div class="card">
  <div class="card-body empty-state">
    <i class="fas fa-lock text-danger"></i>
    <h2 class="h5 text-dark">You do not have permission to open this page.</h2>
    <p class="mb-3">Your role (<?= e(ROLES[$_SESSION['role']] ?? '') ?>) is not allowed to perform this action. Ask the Manager if you believe this is a mistake.</p>
    <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-th-large mr-1"></i> Back to dashboard</a>
  </div>
</div>
