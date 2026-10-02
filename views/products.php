<?php
declare(strict_types=1);

$title = 'Loan products';
$subtitle = 'Each product supplies the amount limits, maximum term, interest rate, and penalty rate.';
if (can('products', 'create')) {
    $headerActions = '<a href="dashboard.php?page=product_form" class="btn btn-primary"><i class="fas fa-plus mr-1"></i> Add product</a>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'toggle_status') {
    require_permission('products', 'delete');
    $id = post_id('product_id');
    try {
        $upd = db()->prepare("UPDATE loan_products SET status = IF(status = 'active', 'inactive', 'active') WHERE product_id = :id");
        $upd->execute([':id' => $id]);
        if ($upd->rowCount() === 1) {
            audit_log('toggle_status', 'loan_products', $id, 'Product status changed');
            flash('success', 'Product status updated.');
        } else {
            flash('error', 'Product not found.');
        }
    } catch (Throwable $e) {
        db_failure($e);
    }
    redirect('dashboard.php?page=products');
}

$products = db()->query(
    "SELECT p.*, (SELECT COUNT(*) FROM loans l WHERE l.product_id = p.product_id AND l.status = 'released') AS active_loans
       FROM loan_products p ORDER BY p.status, p.product_name"
)->fetchAll();
?>
<div class="row">
  <?php foreach ($products as $p): ?>
    <div class="col-md-6 col-xl-4 mb-3">
      <div class="card h-100 <?= $p['status'] !== 'active' ? 'opacity-50' : '' ?>">
        <div class="card-header d-flex align-items-center">
          <h3 class="card-title"><i class="fas fa-tag text-primary mr-2"></i><?= e($p['product_name']) ?></h3>
          <span class="ml-auto"><?= badge($p['status']) ?></span>
        </div>
        <div class="card-body">
          <dl class="dl-grid mb-0" style="grid-template-columns: 130px 1fr">
            <dt>Amount</dt><dd><?= e(money($p['min_amount'])) ?> – <?= e(money($p['max_amount'])) ?></dd>
            <dt>Max term</dt><dd><?= (int) $p['term_months'] ?> months</dd>
            <dt>Interest</dt><dd><?= e($p['interest_rate']) ?>% per month, diminishing</dd>
            <dt>After term</dt><dd><?= e($p['interest_rate']) ?>% + <?= e($p['penalty_rate']) ?>% penalty per month, computed per day, on balance + unpaid interest</dd>
            <dt>Loanable</dt><dd><?= e(LOANABLE_BASIS[$p['loanable_basis']]) ?><?= $p['loanable_basis'] === 'collateral' ? ' (' . e(setting('collateral_loanable_pct')) . '%)' : '' ?></dd>
            <dt>Active loans</dt><dd><?= (int) $p['active_loans'] ?></dd>
          </dl>
        </div>
        <?php if (can('products', 'update')): ?>
          <div class="card-footer bg-white d-flex">
            <a href="dashboard.php?page=product_form&id=<?= (int) $p['product_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-pen mr-1"></i> Edit</a>
            <form method="post" action="" class="ml-auto ml-form"
                  data-confirm="<?= e(($p['status'] === 'active' ? 'Deactivate' : 'Reactivate') . ' ' . $p['product_name'] . '? Existing loans are not affected.') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_status">
              <input type="hidden" name="product_id" value="<?= (int) $p['product_id'] ?>">
              <button type="submit" class="btn btn-sm btn-light"><?= $p['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?></button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
