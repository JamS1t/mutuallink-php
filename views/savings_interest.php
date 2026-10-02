<?php
declare(strict_types=1);

// Clarification A17: regular savings earn 1% per quarter; a time deposit earns 1% per term.
// The Bookkeeper posts the interest from this page; it appears in the member's passbook.

$title = 'Savings interest';
$subtitle = 'Regular savings: ' . e(setting('savings_interest_pct')) . '% per quarter · Time deposit: ' . e(setting('savings_interest_pct')) . '% per term (clarification A17)';
$back = 'dashboard.php?page=savings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = input('action');
    require_permission('savings_interest', 'post');
    $errors = [];
    $pdo = db();
    try {
        if ($action === 'post_regular') {
            $period = req($errors, 'period', 'Quarter label (e.g. 2026-Q3)', 20);
            $pct = (float) setting('savings_interest_pct');
            if ($pct <= 0) {
                $errors[] = 'The savings interest rate is not configured. Ask the Manager to set it in Settings.';
            }
            if (!$errors) {
                $pdo->beginTransaction();
                $stmt = $pdo->query("SELECT savings_id, balance FROM savings_accounts WHERE account_type = 'regular_savings' AND status = 'active' AND balance > 0 ORDER BY savings_id");
                $accounts = $stmt->fetchAll();
                $hasPeriod = $pdo->prepare("SELECT COUNT(*) FROM savings_transactions WHERE savings_id = :s AND txn_type = 'interest' AND remarks = :r");
                $posted = $skipped = 0;
                $total = 0.0;
                foreach ($accounts as $a) {
                    $hasPeriod->execute([':s' => $a['savings_id'], ':r' => 'Interest ' . $period]);
                    if ((int) $hasPeriod->fetchColumn() > 0) {
                        $skipped++;
                        continue;
                    }
                    $amount = money_round((float) $a['balance'] * $pct / 100);
                    if ($amount <= 0) {
                        $skipped++;
                        continue;
                    }
                    savings_entry((int) $a['savings_id'], 'interest', $amount, 1, date('Y-m-d'), 'Interest ' . $period, null, false);
                    $posted++;
                    $total = money_round($total + $amount);
                }
                audit_log('interest', 'savings_transactions', null, "Quarterly interest $period: posted to $posted account(s), $skipped skipped, total " . money($total));
                $pdo->commit();
                flash('success', $posted > 0
                    ? "Quarterly interest for $period posted to $posted account(s), total " . money($total) . ($skipped ? "; $skipped skipped (already posted or zero balance)." : '.')
                    : "No account needed posting for $period (all already posted or zero balance).");
            }
        } elseif ($action === 'post_td') {
            $savingsId = post_id('savings_id');
            $term = req($errors, 'term_label', 'Term label (e.g. Jan - Jun 2026)', 40);
            if (!$savingsId) {
                $errors[] = 'Select the time deposit account.';
            }
            if (!$errors) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT savings_id, balance FROM savings_accounts WHERE savings_id = :id AND account_type = 'time_deposit' AND status = 'active' FOR UPDATE");
                $stmt->execute([':id' => $savingsId]);
                $acct = $stmt->fetch();
                if (!$acct) {
                    $errors[] = 'That time deposit account was not found (or is closed).';
                } else {
                    $amount = money_round((float) $acct['balance'] * $pct / 100);
                    if ($amount <= 0) {
                        $errors[] = 'The account has no balance to earn interest on.';
                    } else {
                        savings_entry($savingsId, 'interest', $amount, 1, date('Y-m-d'), 'Interest ' . $term, null, false);
                        audit_log('interest', 'savings_transactions', $savingsId, "Time deposit interest ($term): " . money($amount));
                        $pdo->commit();
                        flash('success', 'Time deposit interest of ' . money($amount) . " posted for the term $term.");
                        redirect('dashboard.php?page=passbook&id=' . $savingsId);
                    }
                }
                if ($errors) {
                    $pdo->rollBack();
                }
            }
        }
    } catch (ForbiddenException $e) {
        throw $e;
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        db_failure($e);
    }
    redirect($back);
}

$pdo = db();
$regular = $pdo->query(
    "SELECT a.savings_id, a.balance, m.member_no, CONCAT(m.last_name, ', ', m.first_name) AS member_name,
            (SELECT MAX(t.txn_date) FROM savings_transactions t WHERE t.savings_id = a.savings_id AND t.txn_type = 'interest') AS last_interest
       FROM savings_accounts a JOIN members m ON m.member_id = a.member_id
      WHERE a.account_type = 'regular_savings' AND a.status = 'active' AND a.balance > 0
      ORDER BY m.last_name, m.first_name"
)->fetchAll();
$timeDeposits = $pdo->query(
    "SELECT a.savings_id, a.balance, m.member_no, CONCAT(m.last_name, ', ', m.first_name) AS member_name,
            (SELECT MAX(t.txn_date) FROM savings_transactions t WHERE t.savings_id = a.savings_id AND t.txn_type = 'interest') AS last_interest
       FROM savings_accounts a JOIN members m ON m.member_id = a.member_id
      WHERE a.account_type = 'time_deposit' AND a.status = 'active' AND a.balance > 0
      ORDER BY m.last_name, m.first_name"
)->fetchAll();
$pct = (float) setting('savings_interest_pct');
?>
<div class="row">
  <div class="col-lg-6">
    <div class="card card-primary card-outline">
      <div class="card-header"><h3 class="card-title"><i class="fas fa-percentage mr-2"></i>Quarterly interest — regular savings</h3></div>
      <div class="card-body">
        <p class="small text-muted">Posts <strong><?= e(setting('savings_interest_pct')) ?>%</strong> of each account's current balance to every active regular savings account (<?= count($regular) ?> account(s) with balance). Accounts that already received interest for the quarter are skipped. Interest entries carry no official receipt.</p>
        <?php if (can('savings_interest', 'post')): ?>
          <form method="post" action="" class="ml-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="post_regular">
            <div class="form-group">
              <label for="period">Quarter label <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="period" name="period" required maxlength="20" placeholder="e.g., 2026-Q3">
            </div>
            <button type="submit" class="btn btn-primary" data-confirm="Post <?= e(setting('savings_interest_pct')) ?>% quarterly interest to all regular savings accounts now?" data-confirm-button="Post interest">
              <i class="fas fa-coins mr-1"></i> Post quarterly interest
            </button>
          </form>
        <?php else: ?>
          <p class="text-muted small">Only the Bookkeeper or Manager can post interest.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="fas fa-lock mr-2"></i>Time deposit — 1% per term</h3></div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>Member</th><th class="num">Balance</th><th class="num">1% of balance</th><th>Last interest</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($timeDeposits as $t): ?>
              <tr>
                <td><?= e($t['member_name']) ?> <span class="text-muted small"><?= e($t['member_no']) ?></span></td>
                <td class="num"><?= e(money($t['balance'])) ?></td>
                <td class="num font-weight-bold"><?= e(money((float) $t['balance'] * $pct / 100)) ?></td>
                <td class="small text-muted"><?= e(fmt_date($t['last_interest'])) ?></td>
                <td class="text-right">
                  <?php if (can('savings_interest', 'post')): ?>
                    <form method="post" action="" class="ml-form d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="post_td">
                      <input type="hidden" name="savings_id" value="<?= (int) $t['savings_id'] ?>">
                      <input type="text" name="term_label" class="form-control form-control-sm d-inline-block w-auto" required maxlength="40" placeholder="Term (e.g., Jan-Jun 2026)">
                      <button type="submit" class="btn btn-sm btn-outline-primary">Post</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$timeDeposits): ?><tr><td colspan="5" class="text-muted small">No active time deposit with balance.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="fas fa-users mr-2"></i>Regular savings accounts (<?= count($regular) ?>)</h3></div>
      <div class="card-body">
        <table class="table table-hover js-datatable" data-page-length="25" data-order='[[0,"asc"]]'>
          <thead><tr><th>Member</th><th class="num">Balance</th><th class="num">Interest this quarter</th><th>Last interest</th></tr></thead>
          <tbody>
            <?php foreach ($regular as $r): ?>
              <tr>
                <td><?= e($r['member_name']) ?> <span class="text-muted small"><?= e($r['member_no']) ?></span></td>
                <td class="num"><?= e(money($r['balance'])) ?></td>
                <td class="num font-weight-bold"><?= e(money((float) $r['balance'] * $pct / 100)) ?></td>
                <td class="small text-muted"><?= e(fmt_date($r['last_interest'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
