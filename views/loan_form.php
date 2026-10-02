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
// Co-maker selected on this page load (hidden input value) and the name to display
$coMakerSel = (int) old('co_maker_member_id', $loan['co_maker_member_id'] ?? 0);
$coMakerName = '';
if ($coMakerSel) {
    $stmt = db()->prepare("SELECT CONCAT(last_name, ', ', first_name), member_no FROM members WHERE member_id = :id");
    $stmt->execute([':id' => $coMakerSel]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    if ($row) {
        $coMakerName = $row[0] . ' · ' . $row[1];
    }
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
    $coMakerId = post_id('co_maker_member_id');
    $coMaker = null;
    // Co-maker must be an FFMPC member (clarification A11)
    if ($coMakerId) {
        $stmt = db()->prepare("SELECT CONCAT(last_name, ', ', first_name) AS name, status FROM members WHERE member_id = :id");
        $stmt->execute([':id' => $coMakerId]);
        $coMakerRow = $stmt->fetch();
        if (!$coMakerRow || $coMakerRow['status'] !== 'active') {
            $errors['co_maker_member_id'] = 'The co-maker must be an active FFMPC member.';
        } elseif ($coMakerId === $memberId) {
            $errors['co_maker_member_id'] = 'The member cannot be his or her own co-maker.';
        } else {
            $coMaker = $coMakerRow['name'];
        }
    } else {
        $errors['co_maker_member_id'] = 'Select the co-maker from the list of members.';
    }
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
        $errors['product_id'] = 'Select a loan product.';
    } else {
        if ($principal !== null && ($principal < (float) $product['min_amount'] || $principal > (float) $product['max_amount'])) {
            $errors['principal'] = 'Principal for ' . $product['product_name'] . ' must be between ' . money($product['min_amount']) . ' and ' . money($product['max_amount']) . '.';
        }
        if ($term !== null && $term > (int) $product['term_months']) {
            $errors['term_months'] = 'Maximum term for ' . $product['product_name'] . ' is ' . (int) $product['term_months'] . ' months.';
        }
        // Loanable amount depends on the product (questionnaire 4.1)
        if ($product['loanable_basis'] === 'collateral') {
            if ($collateralType === 'none' || $collateral === null || $collateralValue === null) {
                $errors['collateral_type'] = $product['product_name'] . ' depends on collateral: enter its type, description, and appraised value.';
            } elseif ($principal !== null && $principal > loanable_from_collateral($collateralValue)) {
                $errors['collateral_value'] = 'Loanable amount is ' . setting('collateral_loanable_pct') . '% of the appraised value: at most ' . money(loanable_from_collateral($collateralValue)) . '.';
            }
        }
        if ($product['loanable_basis'] === 'net_pay' && $netPay === null) {
            $errors['net_pay'] = $product['product_name'] . " depends on net pay: enter the member's monthly net pay.";
        }
        // Salary Loan rule (FFMPC): the monthly amortization (principal + interest)
        // must fit within one month's salary — net pay ≥ amortization.
        if ($product['loanable_basis'] === 'net_pay' && $netPay !== null && $principal !== null && $term !== null) {
            $first = build_schedule($principal, $term, (float) $product['interest_rate'], '2000-01-01')[0];
            if ($first['total_due'] > $netPay) {
                $cap = max_principal_for_net_pay($netPay, $term, (float) $product['interest_rate']);
                $errors['principal'] = $product['product_name'] . ': the monthly amortization of ' . money($first['total_due'])
                    . ' exceeds the monthly net pay of ' . money($netPay) . '.'
                    . ($cap > 0 ? ' At most ' . money(min($cap, (float) $product['max_amount'])) . " can be borrowed over $term month(s)."
                        : ' No amount of this product can be repaid within that net pay at this term.');
            }
        }
    }
    if ($member && $member['member_type'] === 'outside' && ($collateralType === 'none' || $collateral === null)) {
        $errors['collateral'] = 'Collateral is required for members from outside the school.';
    }
    if ($collateralType === 'none') {
        $collateral = $collateralValue = null;
    }
    // Only one loan per product (clarification A12): a member may hold one loan of
    // each product at a time — but never two of the same product.
    if ($member && $productId) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM loans WHERE member_id = :m AND product_id = :p AND status IN ('pending','approved','released')" . ($isEdit ? ' AND loan_id <> :id' : ''));
        $stmt->execute($isEdit ? [':m' => $memberId, ':p' => $productId, ':id' => $id] : [':m' => $memberId, ':p' => $productId]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'This member already has a ' . ($productMap[$productId]['product_name'] ?? 'loan of this product')
                . ' (application, approval, or running). FFMPC allows only one loan per product.';
        }
    }

    if ($errors) {
        flash_errors($errors);
    } else {
        try {
            $params = [':p' => $productId, ':pr' => $principal, ':t' => $term, ':c' => $coMaker, ':cm' => $coMakerId,
                ':ct' => $collateralType, ':col' => $collateral, ':cv' => $collateralValue, ':np' => $netPay, ':rm' => $repayment, ':r' => $remarks];
            if ($isEdit) {
                $params[':id'] = $id;
                $upd = db()->prepare("UPDATE loans SET product_id = :p, principal = :pr, term_months = :t, co_maker = :c, co_maker_member_id = :cm,
                                             collateral_type = :ct, collateral = :col, collateral_value = :cv, net_pay = :np, repayment_mode = :rm, remarks = :r
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
                "INSERT INTO loans (member_id, product_id, principal, term_months, date_applied, co_maker, co_maker_member_id, collateral_type, collateral, collateral_value,
                                    net_pay, repayment_mode, remarks, status, created_by)
                 VALUES (:m, :p, :pr, :t, CURDATE(), :c, :cm, :ct, :col, :cv, :np, :rm, :r, 'pending', :u)"
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
<?php if (!$member):
    $errors = take_field_errors(); ?>
  <?= steps_nav(['Find the member', 'Encode the application'], 0) ?>
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
<?php else: $elig = member_eligibility($memberId);
    $errors = take_field_errors(); ?>
  <?= steps_nav(['Find the member', 'Encode the application'], 1) ?>
  <div class="row">
    <div class="col-xl-7">
      <form method="post" action="" id="loan-form" class="ml-form" novalidate data-dirty-guard>
        <?= csrf_field() ?>
        <?= error_summary($errors) ?>
        <input type="hidden" name="member_id" value="<?= (int) $memberId ?>">
        <div class="card card-primary card-outline">
          <div class="card-header"><h3 class="card-title"><i class="fas fa-file-signature mr-2"></i>Application details</h3></div>
          <div class="card-body">
            <div class="form-group">
              <label for="product_id">Loan product <span class="text-danger">*</span></label>
              <select class="custom-select<?= invalid_class($errors, 'product_id') ?>" id="product_id" name="product_id" required<?= invalid_attrs($errors, 'product_id') ?>>
                <option value="">— Select —</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?= (int) $p['product_id'] ?>" <?= (string) old('product_id', $loan['product_id']) === (string) $p['product_id'] ? 'selected' : '' ?>
                          data-min="<?= e($p['min_amount']) ?>" data-max="<?= e($p['max_amount']) ?>" data-term="<?= (int) $p['term_months'] ?>" data-rate="<?= e($p['interest_rate']) ?>" data-basis="<?= e($p['loanable_basis']) ?>">
                    <?= e($p['product_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?= field_feedback($errors, 'product_id') ?>
              <small id="product-hint" class="form-text text-muted"></small>
            </div>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label for="principal">Principal (₱) <span class="text-danger">*</span></label>
                <input type="text" inputmode="decimal" class="form-control<?= invalid_class($errors, 'principal') ?>" id="principal" name="principal" required value="<?= e(old('principal', $loan['principal'])) ?>"<?= invalid_attrs($errors, 'principal') ?>>
                <?= field_feedback($errors, 'principal') ?>
              </div>
              <div class="form-group col-md-6">
                <label for="term_months">Term (months) <span class="text-danger">*</span></label>
                <input type="number" class="form-control<?= invalid_class($errors, 'term_months') ?>" id="term_months" name="term_months" min="1" max="60" required value="<?= e(old('term_months', $loan['term_months'])) ?>"<?= invalid_attrs($errors, 'term_months') ?>>
                <?= field_feedback($errors, 'term_months') ?>
              </div>
            </div>
            <div class="form-group">
              <label for="co-maker-search">Co-maker <span class="text-danger">*</span> <small class="text-muted">(must be an FFMPC member)</small></label>
              <input type="hidden" name="co_maker_member_id" id="co_maker_member_id" value="<?= (int) $coMakerSel ?>">
              <input type="search" id="co-maker-search" class="form-control<?= invalid_class($errors, 'co_maker_member_id') ?>" required placeholder="Type the co-maker's last name, first name, or member no." autocomplete="off"
                     data-member-lookup="#co-maker-results" data-fill-target="#co_maker_member_id" data-fill-name="#co-maker-selected"<?= invalid_attrs($errors, 'co_maker_member_id') ?>>
              <div id="co-maker-results" class="list-group lookup-results mt-1" aria-live="polite"></div>
              <?= field_feedback($errors, 'co_maker_member_id') ?>
              <div id="co-maker-selected" class="small mt-1 <?= $coMakerName === '' ? 'd-none' : '' ?>"><i class="fas fa-user-check mr-1"></i><span class="font-weight-bold"><?= e($coMakerName) ?></span></div>
              <small class="form-text text-muted">The co-maker signs the application together with the borrower. Only active members can co-sign.</small>
            </div>
            <fieldset class="border rounded px-3 pt-2 mb-3">
              <legend class="w-auto px-2 small font-weight-bold mb-0">Collateral
                <?= $member['member_type'] === 'outside' ? '<span class="text-danger">* required (member from outside the school)</span>' : '<span class="text-muted font-weight-normal">(required for Regular Loan)</span>' ?></legend>
              <div class="form-row">
                <div class="form-group col-md-4">
                  <label for="collateral_type">Type</label>
                  <select class="custom-select<?= invalid_class($errors, 'collateral_type') ?>" id="collateral_type" name="collateral_type"<?= invalid_attrs($errors, 'collateral_type') ?>>
                    <?php foreach (COLLATERAL_TYPES as $k => $v): ?>
                      <option value="<?= e($k) ?>" <?= old('collateral_type', $loan['collateral_type']) === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?= field_feedback($errors, 'collateral_type') ?>
                </div>
                <div class="form-group col-md-8">
                  <label for="collateral">Description</label>
                  <input type="text" class="form-control<?= invalid_class($errors, 'collateral') ?>" id="collateral" name="collateral" maxlength="255" placeholder="e.g., TCT No. 12345, Mangufangang, 1,082 sq m" value="<?= e(old('collateral', $loan['collateral'])) ?>"<?= invalid_attrs($errors, 'collateral') ?>>
                  <?= field_feedback($errors, 'collateral') ?>
                </div>
              </div>
              <div class="form-group">
                <label for="collateral_value">Appraised value (₱)</label>
                <input type="text" inputmode="decimal" class="form-control<?= invalid_class($errors, 'collateral_value') ?>" id="collateral_value" name="collateral_value" value="<?= e(old('collateral_value', $loan['collateral_value'])) ?>"<?= invalid_attrs($errors, 'collateral_value') ?>>
                <?= field_feedback($errors, 'collateral_value') ?>
                <small class="form-text text-muted">Regular Loan: loanable amount is <?= e(setting('collateral_loanable_pct')) ?>% of this value.</small>
              </div>
            </fieldset>
            <div class="form-row">
              <div class="form-group col-md-6">
                <label for="net_pay">Monthly net pay (₱) <small class="text-muted">(Salary Loan)</small> <?= glossary_btn('net_pay') ?></label>
                <input type="text" inputmode="decimal" class="form-control<?= invalid_class($errors, 'net_pay') ?>" id="net_pay" name="net_pay" value="<?= e(old('net_pay', $loan['net_pay'])) ?>"<?= invalid_attrs($errors, 'net_pay') ?>>
                <?= field_feedback($errors, 'net_pay') ?>
                <small class="form-text text-muted">Salary Loan: the monthly amortization (principal + interest) must not exceed this amount.</small>
              </div>
              <div class="form-group col-md-6">
                <label for="repayment_mode">How the member will pay</label>
                <select class="custom-select<?= invalid_class($errors, 'repayment_mode') ?>" id="repayment_mode" name="repayment_mode"<?= invalid_attrs($errors, 'repayment_mode') ?>>
                  <?php foreach (REPAYMENT_MODES as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= old('repayment_mode', $loan['repayment_mode']) === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                  <?php endforeach; ?>
                </select>
                <?= field_feedback($errors, 'repayment_mode') ?>
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
        <div class="card-header"><h3 class="card-title"><i class="fas fa-table mr-2"></i>Amortization preview <?= glossary_btn('amortization') ?></h3></div>
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
          <div id="preview-netpay" class="text-danger font-weight-bold" hidden aria-live="polite"></div>
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
