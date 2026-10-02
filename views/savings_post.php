<?php
declare(strict_types=1);

$id = get_id();
$stmt = db()->prepare(
    "SELECT a.savings_id, a.account_type, a.balance, a.status, m.member_id, m.member_no, m.status AS member_status,
            CONCAT(m.last_name, ', ', m.first_name) AS member_name
       FROM savings_accounts a JOIN members m ON m.member_id = a.member_id
      WHERE a.savings_id = :id"
);
$stmt->execute([':id' => $id]);
$acct = $stmt->fetch();
if (!$acct) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return;
}
$canWithdraw = in_array($acct['account_type'], WITHDRAWABLE, true);
// Share capital is withdrawal-locked except with the approval of the Board of
// Directors (clarification A18); capital build-up stays locked.
$isShare = $acct['account_type'] === 'share_capital';
$allowWithdrawal = $canWithdraw || $isShare;

$title = 'Post savings transaction';
$subtitle = ACCOUNT_TYPES[$acct['account_type']] . ' · ' . $acct['member_name'] . ' · ' . $acct['member_no'];
$headerActions = '<a href="dashboard.php?page=passbook&id=' . $id . '" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Passbook</a>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    if (!$allowWithdrawal && input('txn_type') === 'withdrawal') {
        $errors['txn_type'] = 'Withdrawals are not allowed from ' . ACCOUNT_TYPES[$acct['account_type']] . '.';
        $_POST['txn_type'] = 'deposit'; // keep the error list to one clear message
    }
    $type = enum_in($errors, 'txn_type', 'transaction type', $allowWithdrawal ? ['deposit', 'withdrawal'] : ['deposit']);
    $amount = money_in($errors, 'amount', 'Amount', true, 1.00, 1000000.00);
    $date = date_in($errors, 'txn_date', 'Transaction date');
    $remarks = opt($errors, 'remarks', 'Remarks', 255);
    // Share capital withdrawals require the BOD resolution (clarification A18)
    $bodResolution = null;
    if ($type === 'withdrawal' && $isShare) {
        $bodResolution = req($errors, 'bod_resolution', 'BOD resolution (number and date)', 100);
    }
    if ($date && $date > date('Y-m-d')) {
        $errors['txn_date'] = 'Transaction date cannot be in the future.';
    }
    if ($acct['member_status'] === 'inactive') {
        $errors[] = 'The member is inactive.';
    }
    $passbook = input('passbook_presented') === '1';

    if ($errors) {
        flash_errors($errors);
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $finalRemarks = $bodResolution !== null ? ('BOD ' . $bodResolution . ($remarks ? ' · ' . $remarks : '')) : $remarks;
            $res = savings_entry($id, $type, $amount, $type === 'deposit' ? 1 : -1, $date, $finalRemarks, null, true, $passbook);
            audit_log($type, 'savings_transactions', $res['txn_id'], ucfirst($type) . ' ' . money($amount) . ' ' . $res['or_no'] . ' · ' . $acct['member_no']
                . ($bodResolution !== null ? ' (BOD: ' . $bodResolution . ')' : ''));
            $pdo->commit();
            remember_member_served((int) $acct['member_id'], (string) $acct['member_name'], (string) $acct['member_no']);
            flash('success', ucfirst($type) . ' of ' . money($amount) . ' posted. OR ' . $res['or_no'] . '. New balance ' . money($res['balance']) . '.');
            redirect('dashboard.php?page=receipt&txn=' . $res['txn_id']);
        } catch (DomainException $e) {
            $pdo->rollBack();
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            db_failure($e);
        }
    }
}
?>
<div class="row">
<?php $errors = take_field_errors(); ?>
  <div class="col-lg-6">
    <div class="card card-primary card-outline">
      <form method="post" action="" class="ml-form" novalidate data-dirty-guard>
        <?= csrf_field() ?>
        <?= error_summary($errors) ?>
        <div class="card-body">
          <div class="form-group">
            <label class="d-block">Transaction type <span class="text-danger">*</span></label>
            <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
              <label class="btn btn-outline-primary <?= old('txn_type', 'deposit') === 'deposit' ? 'active' : '' ?>">
                <input type="radio" name="txn_type" value="deposit" <?= old('txn_type', 'deposit') === 'deposit' ? 'checked' : '' ?><?= invalid_attrs($errors, 'txn_type') ?>> <i class="fas fa-arrow-down mr-1"></i> <?= e(t('deposit')) ?>
              </label>
              <?php if ($allowWithdrawal): ?>
                <label class="btn btn-outline-primary <?= old('txn_type') === 'withdrawal' ? 'active' : '' ?>">
                  <input type="radio" name="txn_type" value="withdrawal" <?= old('txn_type') === 'withdrawal' ? 'checked' : '' ?><?= invalid_attrs($errors, 'txn_type') ?>> <i class="fas fa-arrow-up mr-1"></i> <?= e(t('withdraw')) ?>
                </label>
              <?php endif; ?>
            </div>
            <?= field_feedback($errors, 'txn_type') ?>
            <?php if ($isShare): ?>
              <small class="form-text text-muted">Share capital is returned to a resigning member only with the approval of the Board of Directors (clarification A18): enter the BOD resolution below.</small>
            <?php elseif (!$allowWithdrawal): ?><small class="form-text text-muted">Withdrawals are not allowed for <?= e(ACCOUNT_TYPES[$acct['account_type']]) ?>.</small><?php endif; ?>
          </div>
          <div class="form-group <?= $isShare ? '' : 'd-none' ?>" id="bod-resolution-group">
            <label for="bod_resolution">BOD resolution (number and date) <span class="text-danger">*</span></label>
            <input type="text" class="form-control<?= invalid_class($errors, 'bod_resolution') ?>" id="bod_resolution" name="bod_resolution" maxlength="100"
                   placeholder="e.g., Resolution No. 2026-15, dated Sep 12, 2026" value="<?= e(old('bod_resolution')) ?>"<?= invalid_attrs($errors, 'bod_resolution') ?>>
            <?= field_feedback($errors, 'bod_resolution') ?>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="amount">Amount (₱) <span class="text-danger">*</span></label>
              <input type="text" inputmode="decimal" class="form-control form-control-lg<?= invalid_class($errors, 'amount') ?>" id="amount" name="amount" required autofocus
                     placeholder="0.00" value="<?= e(old('amount')) ?>"<?= invalid_attrs($errors, 'amount') ?>>
              <?= field_feedback($errors, 'amount') ?>
            </div>
            <div class="form-group col-md-6">
              <label for="txn_date">Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control form-control-lg<?= invalid_class($errors, 'txn_date') ?>" id="txn_date" name="txn_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('txn_date', date('Y-m-d'))) ?>"<?= invalid_attrs($errors, 'txn_date') ?>>
              <?= field_feedback($errors, 'txn_date') ?>
            </div>
          </div>
          <div class="form-group">
            <label for="remarks">Remarks</label>
            <input type="text" class="form-control" id="remarks" name="remarks" maxlength="255" value="<?= e(old('remarks')) ?>" placeholder="Optional (e.g., salary deduction for October)">
          </div>
          <div class="custom-control custom-checkbox">
            <input type="checkbox" class="custom-control-input" id="passbook_presented" name="passbook_presented" value="1" <?= old('passbook_presented') === '1' ? 'checked' : '' ?>>
            <label class="custom-control-label" for="passbook_presented">Member presented the passbook (required for withdrawals)</label>
          </div>
        </div>
        <div class="card-footer bg-white d-flex">
          <button type="submit" class="btn btn-primary"><i class="fas fa-check mr-1"></i> <?= e(t('post')) ?> and issue receipt</button>
          <a href="dashboard.php?page=passbook&id=<?= $id ?>" class="btn btn-light ml-auto">Cancel</a>
        </div>
      </form>
    </div>
  </div>
  <div class="col-lg-3">
    <div class="card">
      <div class="card-body">
        <div class="kpi-label">Current balance</div>
        <div class="kpi-value"><?= e(money($acct['balance'])) ?></div>
        <?php $lim = savings_limits($acct['account_type']); ?>
        <ul class="small text-muted pl-3 mt-2 mb-0">
          <?php if ($lim['maintaining'] > 0): ?>
            <li>Maintaining balance <?= glossary_btn('maintaining') ?> <?= e(money($lim['maintaining'])) ?><?= $lim['full_withdrawal_allowed'] ? ' (or withdraw in full)' : '' ?>.</li>
            <li>Withdrawable now: <strong><?= e(money(max(0, (float) $acct['balance'] - $lim['maintaining']))) ?></strong></li>
          <?php endif; ?>
          <?php if ((float) $acct['balance'] == 0.0 && $lim['opening'] > 0): ?><li>Opening deposit at least <?= e(money($lim['opening'])) ?>.</li><?php endif; ?>
          <?php if ($acct['account_type'] === 'capital_build_up'): ?><li>Expected contribution <?= e(money(setting('cbu_monthly'))) ?> per month <?= glossary_btn('cbu') ?>.</li><?php endif; ?>
          <li>Deposits are fine without the passbook; ask the member to have it updated.</li>
          <li>A receipt number is assigned automatically.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

