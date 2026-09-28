<?php
declare(strict_types=1);

// DFD 4.1 — record the application with co-maker and collateral (Loan Officer).
$id = get_id();
$isEdit = $id > 0;

$loan = ['member_id' => get_id('member_id'), 'product_id' => '', 'principal' => '', 'term_months' => '', 'co_maker' => '', 'collateral' => '', 'remarks' => '',
    'collateral_type' => 'none', 'collateral_value' => '', 'net_pay' => '', 'repayment_mode' => 'cash'];
if ($isEdit) {
    $stmt = db()->prepare("SELECT * FROM loans WHERE loan_id = :id AND status = 'pending'");
    $stmt->execute([':id' => $id]);
    $loan = $stmt->fetch();
    if (!$loan) {
        flash('error', 'Only pending applications can be edited.');
        redirect('dashboard.php?page=loans');
    }
}
$memberId = (int) ($_SERVER['REQUEST_METHOD'] === 'POST' ? post_id('member_id') : $loan['member_id']);

$member = null;
if ($memberId) {
    $stmt = db()->prepare("SELECT member_id, member_no, last_name, first_name, member_type, status FROM members WHERE member_id = :id");
    $stmt->execute([':id' => $memberId]);
    $member = $stmt->fetch() ?: null;
}
$products = db()->query("SELECT product_id, product_name, min_amount, max_amount, term_months, interest_rate, loanable_basis FROM loan_products WHERE status = 'active' ORDER BY product_name")->fetchAll();
$productMap = array_column($products, null, 'product_id');

$title = $isEdit ? 'Edit loan application #' . $id : 'New loan application';
$subtitle = $member ? $member['last_name'] . ', ' . $member['first_name'] . ' · ' . $member['member_no'] : 'Choose the member, then encode the application.';
$headerActions = '<a href="' . ($isEdit ? 'dashboard.php?page=loan_view&id=' . $id : 'dashboard.php?page=loans') . '" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Back</a>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $productId = post_id('product_id');
    $principal = money_in($errors, 'principal', 'Principal');
    $term = int_in($errors, 'term_months', 'Term', 1, 60);
    $coMaker = req($errors, 'co_maker', 'Co-maker', 150);
    $collateralType = enum_in($errors, 'collateral_type', 'collateral type', array_keys(COLLATERAL_TYPES));
    $collateral = opt($errors, 'collateral', 'Collateral description', 255);
    $collateralValue = money_in($errors, 'collateral_value', 'Appraised value of collateral', false, 1.00, 999999999.99);
    $netPay = money_in($errors, 'net_pay', 'Monthly net pay', false, 1.00, 10000000.00);
    $repayment = enum_in($errors, 'repayment_mode', 'mode of payment', array_keys(REPAYMENT_MODES));
    $remarks = opt($errors, 'remarks', 'Remarks', 255);

    if (!$member || $member['status'] !== 'active') {
        $errors[] = 'Select an active member (applicants must be approved first).';
    }
    $product = $productMap[$productId] ?? null;
    if (!$product) {
        $errors[] = 'Select a loan product.';
    } else {
        if ($principal !== null && ($principal < (float) $product['min_amount'] || $principal > (float) $product['max_amount'])) {
            $errors[] = 'Principal for ' . $product['product_name'] . ' must be between ' . money($product['min_amount']) . ' and ' . money($product['max_amount']) . '.';
        }
        if ($term !== null && $term > (int) $product['term_months']) {
            $errors[] = 'Maximum term for ' . $product['product_name'] . ' is ' . (int) $product['term_months'] . ' months.';
        }
        // Loanable amount depends on the product (questionnaire 4.1)
        if ($product['loanable_basis'] === 'collateral') {
            if ($collateralType === 'none' || $collateral === null || $collateralValue === null) {
                $errors[] = $product['product_name'] . ' depends on collateral: enter its type, description, and appraised value.';
            } elseif ($principal !== null && $principal > loanable_from_collateral($collateralValue)) {
                $errors[] = 'Loanable amount is ' . setting('collateral_loanable_pct') . '% of the appraised value: at most ' . money(loanable_from_collateral($collateralValue)) . '.';
            }
        }
        if ($product['loanable_basis'] === 'net_pay' && $netPay === null) {
            $errors[] = $product['product_name'] . " depends on net pay: enter the member's monthly net pay.";
        }
    }
    if ($member && $member['member_type'] === 'outside' && ($collateralType === 'none' || $collateral === null)) {
        $errors[] = 'Collateral is required for members from outside the school.';
    }
    if ($collateralType === 'none') {
        $collateral = $collateralValue = null;
    }
    if ($member && !$isEdit) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM loans WHERE member_id = :m AND status IN ('pending','approved')");
        $stmt->execute([':m' => $memberId]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'This member already has an application in process.';
        }
    }

    if ($errors) {
        flash_errors($errors);
    } else {
        try {
            $params = [':p' => $productId, ':pr' => $principal, ':t' => $term, ':c' => $coMaker, ':ct' => $collateralType, ':col' => $collateral,
                ':cv' => $collateralValue, ':np' => $netPay, ':rm' => $repayment, ':r' => $remarks];
            if ($isEdit) {
                $params[':id'] = $id;
                $upd = db()->prepare("UPDATE loans SET product_id = :p, principal = :pr, term_months = :t, co_maker = :c, collateral_type = :ct,
                                             collateral = :col, collateral_value = :cv, net_pay = :np, repayment_mode = :rm, remarks = :r
                                      WHERE loan_id = :id AND status = 'pending'");
                $upd->execute($params);
                if ($upd->rowCount() === 0) {
                    throw new DomainException('The application is no longer pending.');
                }
                audit_log('update', 'loans', $id, 'Edited application: ' . money($principal) . " / $term mo");
                flash('success', "Application #$id updated.");
                redirect('dashboard.php?page=loan_view&id=' . $id);
            }
            $params[':m'] = $memberId;
            $params[':u'] = current_user_id();
            db()->prepare(
                "INSERT INTO loans (member_id, product_id, principal, term_months, date_applied, co_maker, collateral_type, collateral, collateral_value,
                                    net_pay, repayment_mode, remarks, status, created_by)
                 VALUES (:m, :p, :pr, :t, CURDATE(), :c, :ct, :col, :cv, :np, :rm, :r, 'pending', :u)"
            )->execute($params);
            $newId = (int) db()->lastInsertId();
            audit_log('create', 'loans', $newId, 'Application ' . $product['product_name'] . ' ' . money($principal) . " / $term mo for " . $member['member_no']);
            flash('success', "Loan application #$newId recorded. It now awaits the credit committee and Manager's approval.");
            redirect('dashboard.php?page=loan_view&id=' . $newId);
        } catch (DomainException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            db_failure($e);
        }
    }
}
?>
<?php if (!$member): ?>
  <div class="row">
    <div class="col-lg-6">
      <div class="card card-primary card-outline">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-search mr-2"></i>Find the borrowing member</h3></div>
        <div class="card-body">
          <label for="loan-lookup">Member</label>
          <input type="search" id="loan-lookup" class="form-control form-control-lg" placeholder="Last name, first name, or member no." autocomplete="off" autofocus
                 data-member-lookup="#loan-results" data-select-url="dashboard.php?page=loan_form&member_id={id}">
          <div id="loan-results" class="list-group lookup-results mt-2" aria-live="polite"></div>
        </div>
      </div>
    </div>
  </div>
<?php else: $elig = member_eligibility($memberId); ?>
  <div class="row">
    <div class="col-xl-7">
      <form method="post" action="" id="loan-form" class="ml-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="member_id" value="<?= (int) $memberId ?>">
        <div class="card card-primary card-outline">
          <div class="card-header"><h3 class="card-title"><i class="fas fa-file-signature mr-2"></i>Application details</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label for="product_id">Loan product <span class="text-danger">*</span></label>
              <select class="custom-select" id="product_id" name="product_id" required>
                <option value="">— Select —</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?= (int) $p['product_id'] ?>" <?= (string) old('product_id', $loan['product_id']) === (string) $p['product_id'] ? 'selected' : '' ?>
                          data-min="<?= e($p['min_amount']) ?>" data-max="<?= e($p['max_amount']) ?>" data-term="<?= (int) $p['term_months'] ?>" data-rate="<?= e($p['interest_rate']) ?>">
                    <?= e($p['product_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small id="product-hint" class="form-text text-muted"></small>
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label for="principal">Principal (₱) <span class="text-danger">*</span></label>
                <input type="text" inputmode="decimal" class="form-control" id="principal" name="principal" required value="<?= e(old('principal', $loan['principal'])) ?>">
              </div>
              <div class="form-group col-md-6">
                <label for="term_months">Term (months) <span class="text-danger">*</span></label>
                <input type="number" class="form-control" id="term_months" name="term_months" min="1" max="60" required value="<?= e(old('term_months', $loan['term_months'])) ?>">
              </div>
            </div>
            <div class="form-group">
              <label for="co_maker">Co-maker <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="co_maker" name="co_maker" required maxlength="150" placeholder="Full name of the co-maker who signed" value="<?= e(old('co_maker', $loan['co_maker'])) ?>">
            </div>
            <fieldset class="border rounded px-3 pt-2 mb-3">
              <legend class="w-auto px-2 small font-weight-bold mb-0">Collateral
                <?= $member['member_type'] === 'outside' ? '<span class="text-danger">* required (member from outside the school)</span>' : '<span class="text-muted font-weight-normal">(required for Regular Loan)</span>' ?></legend>
              <div class="form-row">
                <div class="form-group col-md-4">
                  <label for="collateral_type">Type</label>
                  <select class="custom-select" id="collateral_type" name="collateral_type">
                    <?php foreach (COLLATERAL_TYPES as $k => $v): ?>
                      <option value="<?= e($k) ?>" <?= old('collateral_type', $loan['collateral_type']) === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group col-md-8">
                  <label for="collateral">Description</label>
                  <input type="text" class="form-control" id="collateral" name="collateral" maxlength="255" placeholder="e.g., TCT No. 12345, Mangufangang, 1,082 sq m" value="<?= e(old('collateral', $loan['collateral'])) ?>">
                </div>
              </div>
              <div class="form-group">
                <label for="collateral_value">Appraised value (₱)</label>
                <input type="text" inputmode="decimal" class="form-control" id="collateral_value" name="collateral_value" value="<?= e(old('collateral_value', $loan['collateral_value'])) ?>">
                <small class="form-text text-muted">Regular Loan: loanable amount is <?= e(setting('collateral_loanable_pct')) ?>% of this value.</small>
              </div>
            </fieldset>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label for="net_pay">Monthly net pay (₱) <small class="text-muted">(Salary Loan)</small></label>
                <input type="text" inputmode="decimal" class="form-control" id="net_pay" name="net_pay" value="<?= e(old('net_pay', $loan['net_pay'])) ?>">
              </div>
              <div class="form-group col-md-6">
                <label for="repayment_mode">How the member will pay</label>
                <select class="custom-select" id="repayment_mode" name="repayment_mode">
                  <?php foreach (REPAYMENT_MODES as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= old('repayment_mode', $loan['repayment_mode']) === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-group mb-0">
              <label for="remarks">Remarks</label>
              <input type="text" class="form-control" id="remarks" name="remarks" maxlength="255" value="<?= e(old('remarks', $loan['remarks'])) ?>">
            </div>
          </div>
          <div class="card-footer bg-white d-flex">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> <?= $isEdit ? 'Save changes' : 'Submit application' ?></button>
            <a href="dashboard.php?page=member_view&id=<?= (int) $memberId ?>" class="btn btn-light ml-auto">Member record</a>
          </div>
        </div>
      </form>

      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-table mr-2"></i>Amortization preview</h3></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>#</th><th>Due date*</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Total due</th><th class="num">Semi-monthly</th><th class="num">Balance</th></tr></thead>
              <tbody id="preview-body"></tbody>
            </table>
          </div>
        </div>
        <div class="card-footer bg-white small">
          <div id="preview-totals" class="font-weight-bold" aria-live="polite"></div>
          <div class="text-muted">*Due dates are finalized from the actual release date.</div>
        </div>
      </div>
    </div>

    <div class="col-xl-5">
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-clipboard-check mr-2"></i>Eligibility summary</h3></div>
        <div class="card-body">
          <?= eligibility_list($elig['items']) ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
