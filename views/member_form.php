<?php
declare(strict_types=1);

$id = get_id();
$isEdit = $id > 0;
require_permission('members', $isEdit ? 'update' : 'create');

const CIVIL_STATUS = ['single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed', 'separated' => 'Separated'];

$m = [
    'last_name' => '', 'first_name' => '', 'middle_name' => '', 'birthdate' => '', 'civil_status' => 'single',
    'address' => '', 'contact_no' => '', 'email' => '', 'occupation' => '', 'monthly_income' => '', 'tin' => '', 'sss' => '',
    'beneficiaries' => '', 'spouse_name' => '', 'date_of_membership' => date('Y-m-d'), 'member_type' => 'school', 'member_no' => '',
    'pmes_date' => '', 'signature_on_file' => 0, 'status' => 'applicant',
];
if ($isEdit) {
    $stmt = db()->prepare('SELECT * FROM members WHERE member_id = :id');
    $stmt->execute([':id' => $id]);
    $m = $stmt->fetch();
    if (!$m) {
        flash('error', 'Member not found.');
        redirect('dashboard.php?page=members');
    }
}

$title = $isEdit ? 'Edit member profile' : 'Register member';
$subtitle = $isEdit ? $m['member_no'] . ' · ' . $m['last_name'] . ', ' . $m['first_name']
    : 'For applicants who attended the pre-membership seminar. Share capital and regular savings accounts are opened automatically; the Manager approves the membership.';
$headerActions = '<a href="' . ($isEdit ? 'dashboard.php?page=member_view&id=' . $id : 'dashboard.php?page=members') . '" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Back</a>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $data = [
        'last_name'    => req($errors, 'last_name', 'Last name', 80),
        'first_name'   => req($errors, 'first_name', 'First name', 80),
        'middle_name'  => opt($errors, 'middle_name', 'Middle name', 80),
        'birthdate'    => date_in($errors, 'birthdate', 'Birthdate'),
        'civil_status' => enum_in($errors, 'civil_status', 'civil status', array_keys(CIVIL_STATUS)),
        'address'      => req($errors, 'address', 'Address', 255),
        'contact_no'   => pattern_in($errors, 'contact_no', 'Contact number', '/^(09|\+639)\d{9}$/', 'must be a mobile number like 09171234567.'),
        'email'        => email_in($errors, 'email', 'Email'),
        'occupation'   => opt($errors, 'occupation', 'Occupation', 100),
        'monthly_income' => money_in($errors, 'monthly_income', 'Monthly income', false, 0),
        'tin'          => pattern_in($errors, 'tin', 'TIN', '/^\d{3}-?\d{3}-?\d{3}(-?\d{3,5})?$/', 'must look like 123-456-789 or 123-456-789-000.', true),
        'sss'          => pattern_in($errors, 'sss', 'SSS number', '/^\d{2}-?\d{7}-?\d$/', 'must look like 12-3456789-0.'),
        'beneficiaries' => opt($errors, 'beneficiaries', 'Beneficiaries', 1000),
        'spouse_name'  => opt($errors, 'spouse_name', 'Spouse name', 150),
        'date_of_membership' => date_in($errors, 'date_of_membership', 'Date of membership'),
        'member_type'  => enum_in($errors, 'member_type', 'member type', ['school', 'outside']),
        'pmes_date'    => date_in($errors, 'pmes_date', 'Pre-membership seminar (PMES) date'),
        'signature_on_file' => input('signature_on_file') === '1' ? 1 : 0,
    ];

    $today = date('Y-m-d');
    if ($data['pmes_date'] && $data['pmes_date'] > $today) {
        $errors[] = 'PMES date cannot be in the future.';
    }
    if ($data['birthdate'] && $data['birthdate'] > date('Y-m-d', strtotime('-18 years'))) {
        $errors[] = 'Member must be at least 18 years old.';
    }
    if ($data['date_of_membership'] && $data['date_of_membership'] > $today) {
        $errors[] = 'Date of membership cannot be in the future.';
    }
    if ($data['civil_status'] !== 'married') {
        $data['spouse_name'] = null;
    }

    if ($errors) {
        flash_errors($errors);
    } else {
        $pdo = db();
        try {
            $cols = array_keys($data);
            if ($isEdit) {
                $set = implode(', ', array_map(fn ($c) => "$c = :$c", $cols));
                $params = array_combine(array_map(fn ($c) => ":$c", $cols), array_values($data));
                $params[':by'] = current_user_id();
                $params[':id'] = $id;
                $pdo->prepare("UPDATE members SET $set, updated_by = :by WHERE member_id = :id")->execute($params);
                audit_log('update', 'members', $id, 'Profile of ' . $m['member_no']);
                flash('success', 'Member profile updated.');
                redirect('dashboard.php?page=member_view&id=' . $id);
            }

            // Registration: member + share capital + regular savings accounts in ONE transaction.
            $pdo->beginTransaction();
            $memberNo = next_member_no();
            $params = array_combine(array_map(fn ($c) => ":$c", $cols), array_values($data));
            $params[':member_no'] = $memberNo;
            $params[':by'] = current_user_id();
            $pdo->prepare(
                'INSERT INTO members (member_no, ' . implode(', ', $cols) . ', created_by)
                 VALUES (:member_no, ' . implode(', ', array_map(fn ($c) => ":$c", $cols)) . ', :by)'
            )->execute($params);
            $newId = (int) $pdo->lastInsertId();

            $acct = $pdo->prepare('INSERT INTO savings_accounts (member_id, account_type, balance, date_opened) VALUES (:m, :t, 0, :d)');
            foreach (['share_capital', 'regular_savings'] as $type) {
                $acct->execute([':m' => $newId, ':t' => $type, ':d' => $today]);
            }
            audit_log('create', 'members', $newId, "Registered applicant $memberNo; opened share capital and regular savings");
            $pdo->commit();

            flash('success', "Applicant $memberNo registered. Next: membership fee and initial share capital at the cashier, then the Manager's approval.");
            redirect('dashboard.php?page=member_view&id=' . $newId);
        } catch (Throwable $e) {
            db_failure($e);
        }
    }
}

$field = fn (string $k) => e(old($k, $m[$k] ?? ''));
?>
<form method="post" action="" class="ml-form" novalidate>
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-lg-8">
      <div class="card card-primary card-outline">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-id-card mr-2"></i>Personal information</h3></div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group col-md-4">
              <label for="last_name">Last name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="last_name" name="last_name" required maxlength="80" value="<?= $field('last_name') ?>">
            </div>
            <div class="form-group col-md-4">
              <label for="first_name">First name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="first_name" name="first_name" required maxlength="80" value="<?= $field('first_name') ?>">
            </div>
            <div class="form-group col-md-4">
              <label for="middle_name">Middle name</label>
              <input type="text" class="form-control" id="middle_name" name="middle_name" maxlength="80" value="<?= $field('middle_name') ?>">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-4">
              <label for="birthdate">Birthdate <span class="text-danger">*</span></label>
              <input type="date" class="form-control" id="birthdate" name="birthdate" required max="<?= e(date('Y-m-d', strtotime('-18 years'))) ?>" value="<?= $field('birthdate') ?>">
            </div>
            <div class="form-group col-md-4">
              <label for="civil_status">Civil status <span class="text-danger">*</span></label>
              <select class="custom-select" id="civil_status" name="civil_status">
                <?php foreach (CIVIL_STATUS as $k => $v): ?>
                  <option value="<?= $k ?>" <?= old('civil_status', $m['civil_status']) === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-4">
              <label for="spouse_name">Spouse name <small class="text-muted">(if married)</small></label>
              <input type="text" class="form-control" id="spouse_name" name="spouse_name" maxlength="150" value="<?= $field('spouse_name') ?>">
            </div>
          </div>
          <div class="form-group">
            <label for="address">Address <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="address" name="address" required maxlength="255" value="<?= $field('address') ?>">
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="contact_no">Mobile number</label>
              <input type="tel" class="form-control" id="contact_no" name="contact_no" maxlength="13" placeholder="09171234567" value="<?= $field('contact_no') ?>">
            </div>
            <div class="form-group col-md-6">
              <label for="email">Email <small class="text-muted">(for payment reminders)</small></label>
              <input type="email" class="form-control" id="email" name="email" maxlength="150" value="<?= $field('email') ?>">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="occupation">Occupation</label>
              <input type="text" class="form-control" id="occupation" name="occupation" maxlength="100" value="<?= $field('occupation') ?>">
            </div>
            <div class="form-group col-md-6">
              <label for="monthly_income">Monthly income (₱)</label>
              <input type="text" inputmode="decimal" class="form-control" id="monthly_income" name="monthly_income" value="<?= $field('monthly_income') ?>">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="tin">TIN <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="tin" name="tin" maxlength="20" placeholder="123-456-789" value="<?= $field('tin') ?>">
            </div>
            <div class="form-group col-md-6">
              <label for="sss">SSS number</label>
              <input type="text" class="form-control" id="sss" name="sss" maxlength="20" placeholder="12-3456789-0" value="<?= $field('sss') ?>">
            </div>
          </div>
          <div class="form-group mb-0">
            <label for="beneficiaries">Beneficiaries <small class="text-muted">(one per line: name – relationship)</small></label>
            <textarea class="form-control" id="beneficiaries" name="beneficiaries" rows="3" maxlength="1000"><?= $field('beneficiaries') ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-users mr-2"></i>Membership</h3></div>
        <div class="card-body">
          <?php if ($isEdit): ?>
            <div class="form-group">
              <label>Member number</label>
              <input type="text" class="form-control" value="<?= e($m['member_no']) ?>" disabled>
            </div>
          <?php endif; ?>
          <div class="form-group">
            <label for="pmes_date">Pre-membership seminar (PMES) attended <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="pmes_date" name="pmes_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= $field('pmes_date') ?>">
          </div>
          <div class="form-group">
            <label for="date_of_membership"><?= ($m['status'] ?? 'applicant') === 'applicant' ? 'Date of application' : 'Date of membership' ?> <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="date_of_membership" name="date_of_membership" required max="<?= e(date('Y-m-d')) ?>" value="<?= $field('date_of_membership') ?>">
            <?php if (($m['status'] ?? 'applicant') === 'applicant'): ?><small class="form-text text-muted">Replaced by the approval date when the Manager approves the membership.</small><?php endif; ?>
          </div>
          <div class="form-group">
            <div class="custom-control custom-checkbox">
              <input type="checkbox" class="custom-control-input" id="signature_on_file" name="signature_on_file" value="1"
                     <?= ($_SERVER['REQUEST_METHOD'] === 'POST' ? input('signature_on_file') === '1' : (int) $m['signature_on_file'] === 1) ? 'checked' : '' ?>>
              <label class="custom-control-label" for="signature_on_file">Signature specimen card received</label>
            </div>
          </div>
          <div class="form-group mb-0">
            <label for="member_type">Member type <span class="text-danger">*</span></label>
            <select class="custom-select" id="member_type" name="member_type">
              <option value="school" <?= old('member_type', $m['member_type']) === 'school' ? 'selected' : '' ?>>School-based</option>
              <option value="outside" <?= old('member_type', $m['member_type']) === 'outside' ? 'selected' : '' ?>>Outside the school</option>
            </select>
            <small class="form-text text-muted">Members from outside the school must submit collateral for loans.</small>
          </div>
        </div>
        <div class="card-footer bg-white">
          <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-save mr-1"></i> <?= $isEdit ? 'Save changes' : 'Register member' ?></button>
        </div>
      </div>
    </div>
  </div>
</form>
