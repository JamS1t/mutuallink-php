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

$title = 'Post savings transaction';
$subtitle = ACCOUNT_TYPES[$acct['account_type']] . ' · ' . $acct['member_name'] . ' · ' . $acct['member_no'];
$headerActions = '<a href="dashboard.php?page=passbook&id=' . $id . '" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i> Passbook</a>';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    if (!$canWithdraw && input('txn_type') === 'withdrawal') {
        $errors[] = 'Withdrawals are not allowed from ' . ACCOUNT_TYPES[$acct['account_type']] . '.';
        $_POST['txn_type'] = 'deposit'; // keep the error list to one clear message
    }
    $type = enum_in($errors, 'txn_type', 'transaction type', $canWithdraw ? ['deposit', 'withdrawal'] : ['deposit']);
    $amount = money_in($errors, 'amount', 'Amount', true, 1.00, 1000000.00);
    $date = date_in($errors, 'txn_date', 'Transaction date');
    $remarks = opt($errors, 'remarks', 'Remarks', 255);
    if ($date && $date > date('Y-m-d')) {
        $errors[] = 'Transaction date cannot be in the future.';
    }
    if ($acct['member_status'] !== 'active') {
        $errors[] = 'The member is inactive.';
    }

    if ($errors) {
        flash_errors($errors);
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $res = savings_entry($id, $type, $amount, $type === 'deposit' ? 1 : -1, $date, $remarks);
            audit_log($type, 'savings_transactions', $res['txn_id'], ucfirst($type) . ' ' . money($amount) . ' ' . $res['or_no'] . ' · ' . $acct['member_no']);
            $pdo->commit();
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
  <div class="col-lg-6">
    <div class="card card-primary card-outline">
      <form method="post" action="" class="ml-form" novalidate>
        <?= csrf_field() ?>
        <div class="card-body">
          <div class="form-group">
            <label class="d-block">Transaction type <span class="text-danger">*</span></label>
            <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
              <label class="btn btn-outline-primary <?= old('txn_type', 'deposit') === 'deposit' ? 'active' : '' ?>">
                <input type="radio" name="txn_type" value="deposit" <?= old('txn_type', 'deposit') === 'deposit' ? 'checked' : '' ?>> <i class="fas fa-arrow-down mr-1"></i> Deposit
              </label>
              <?php if ($canWithdraw): ?>
                <label class="btn btn-outline-primary <?= old('txn_type') === 'withdrawal' ? 'active' : '' ?>">
                  <input type="radio" name="txn_type" value="withdrawal" <?= old('txn_type') === 'withdrawal' ? 'checked' : '' ?>> <i class="fas fa-arrow-up mr-1"></i> Withdrawal
                </label>
              <?php endif; ?>
            </div>
            <?php if (!$canWithdraw): ?><small class="form-text text-muted">Withdrawals are not allowed for <?= e(ACCOUNT_TYPES[$acct['account_type']]) ?>.</small><?php endif; ?>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6">
              <label for="amount">Amount (₱) <span class="text-danger">*</span></label>
              <input type="text" inputmode="decimal" class="form-control form-control-lg" id="amount" name="amount" required autofocus
                     placeholder="0.00" value="<?= e(old('amount')) ?>">
            </div>
            <div class="form-group col-md-6">
              <label for="txn_date">Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control form-control-lg" id="txn_date" name="txn_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('txn_date', date('Y-m-d'))) ?>">
            </div>
          </div>
          <div class="form-group mb-0">
            <label for="remarks">Remarks</label>
            <input type="text" class="form-control" id="remarks" name="remarks" maxlength="255" value="<?= e(old('remarks')) ?>" placeholder="Optional (e.g., salary deduction for October)">
          </div>
        </div>
        <div class="card-footer bg-white d-flex">
          <button type="submit" class="btn btn-primary"><i class="fas fa-check mr-1"></i> Post and issue receipt</button>
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
        <div class="small text-muted mt-2">A receipt number is assigned automatically when you post.</div>
      </div>
    </div>
  </div>
</div>
