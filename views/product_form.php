<?php
declare(strict_types=1);

$id = get_id();
$isEdit = $id > 0;
require_permission('products', $isEdit ? 'update' : 'create');

$p = ['product_name' => '', 'min_amount' => '', 'max_amount' => '', 'term_months' => '12', 'interest_rate' => '3.00', 'penalty_rate' => '4.00', 'loanable_basis' => 'fixed'];
if ($isEdit) {
    $stmt = db()->prepare('SELECT * FROM loan_products WHERE product_id = :id');
    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();
    if (!$p) {
        flash('error', 'Product not found.');
        redirect('dashboard.php?page=products');
    }
}

$title = $isEdit ? 'Edit loan product' : 'Add loan product';
$subtitle = $isEdit ? $p['product_name'] : 'Define limits and rates for a new loan product.';
$headerActions = '<a href="dashboard.php?page=products" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Back</a>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $name = req($errors, 'product_name', 'Product name', 80);
    $min = money_in($errors, 'min_amount', 'Minimum amount');
    $max = money_in($errors, 'max_amount', 'Maximum amount');
    $term = int_in($errors, 'term_months', 'Maximum term', 1, 60);
    $rate = money_in($errors, 'interest_rate', 'Interest rate', true, 0.01, 10.00);
    $penalty = money_in($errors, 'penalty_rate', 'Penalty rate', true, 0.00, 10.00);
    $basis = enum_in($errors, 'loanable_basis', 'loanable amount basis', array_keys(LOANABLE_BASIS));
    if ($min !== null && $max !== null && $max < $min) {
        $errors[] = 'Maximum amount must be greater than or equal to the minimum.';
    }
    if ($name !== '') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM loan_products WHERE product_name = :n AND product_id <> :id');
        $stmt->execute([':n' => $name, ':id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'A product with this name already exists.';
        }
    }

    if ($errors) {
        flash_errors($errors);
    } else {
        try {
            $params = [':n' => $name, ':mi' => $min, ':ma' => $max, ':t' => $term, ':r' => $rate, ':p' => $penalty, ':b' => $basis];
            if ($isEdit) {
                $params[':id'] = $id;
                db()->prepare('UPDATE loan_products SET product_name = :n, min_amount = :mi, max_amount = :ma, term_months = :t, interest_rate = :r, penalty_rate = :p, loanable_basis = :b WHERE product_id = :id')
                    ->execute($params);
                audit_log('update', 'loan_products', $id, "$name: {$rate}% interest, {$penalty}% penalty");
            } else {
                db()->prepare('INSERT INTO loan_products (product_name, min_amount, max_amount, term_months, interest_rate, penalty_rate, loanable_basis) VALUES (:n, :mi, :ma, :t, :r, :p, :b)')
                    ->execute($params);
                audit_log('create', 'loan_products', (int) db()->lastInsertId(), $name);
            }
            flash('success', "Loan product \"$name\" saved.");
            redirect('dashboard.php?page=products');
        } catch (Throwable $e) {
            db_failure($e);
        }
    }
}
?>
<div class="row">
  <div class="col-lg-6">
    <div class="card card-primary card-outline">
      <form method="post" action="" class="ml-form" novalidate>
        <?= csrf_field() ?>
        <div class="card-body">
          <div class="form-group">
            <label for="product_name">Product name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="product_name" name="product_name" required maxlength="80" value="<?= e(old('product_name', $p['product_name'])) ?>">
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="min_amount">Minimum amount (₱) <span class="text-danger">*</span></label>
              <input type="text" inputmode="decimal" class="form-control" id="min_amount" name="min_amount" required value="<?= e(old('min_amount', $p['min_amount'])) ?>">
            </div>
            <div class="form-group col-md-6">
              <label for="max_amount">Maximum amount (₱) <span class="text-danger">*</span></label>
              <input type="text" inputmode="decimal" class="form-control" id="max_amount" name="max_amount" required value="<?= e(old('max_amount', $p['max_amount'])) ?>">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-4">
              <label for="term_months">Max term (months) <span class="text-danger">*</span></label>
              <input type="number" class="form-control" id="term_months" name="term_months" min="1" max="60" required value="<?= e(old('term_months', $p['term_months'])) ?>">
            </div>
            <div class="form-group col-md-4">
              <label for="interest_rate">Interest (% / month) <span class="text-danger">*</span></label>
              <input type="text" inputmode="decimal" class="form-control" id="interest_rate" name="interest_rate" required value="<?= e(old('interest_rate', $p['interest_rate'])) ?>">
            </div>
            <div class="form-group col-md-4">
              <label for="penalty_rate">Penalty (% / month after term) <span class="text-danger">*</span></label>
              <input type="text" inputmode="decimal" class="form-control" id="penalty_rate" name="penalty_rate" required value="<?= e(old('penalty_rate', $p['penalty_rate'])) ?>">
            </div>
          </div>
          <div class="form-group">
            <label for="loanable_basis">Loanable amount based on</label>
            <select class="custom-select" id="loanable_basis" name="loanable_basis">
              <?php foreach (LOANABLE_BASIS as $k => $v): ?><option value="<?= e($k) ?>" <?= old('loanable_basis', $p['loanable_basis']) === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
            </select>
          </div>
          <p class="small text-muted mb-0">Interest is fixed into each loan's schedule when it is released, so a rate change applies to loans released afterwards. Penalties use the product's current penalty rate.</p>
        </div>
        <div class="card-footer bg-white d-flex">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save product</button>
          <a href="dashboard.php?page=products" class="btn btn-light ml-auto">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
