<?php
declare(strict_types=1);

$id = get_id();
$stmt = db()->prepare(
    "SELECT a.*, m.member_no, m.member_id, CONCAT(m.last_name, ', ', m.first_name) AS member_name
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
$back = 'dashboard.php?page=passbook&id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = input('action');
    $pdo = db();

    // Correction by reversing entry (Bookkeeper). The original line is never edited or deleted.
    if ($action === 'reverse') {
        require_permission('savings_txn', 'delete');
        $txnId = post_id('txn_id');
        $reason = input('reason');
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) {
            flash('error', 'A reason (3–200 characters) is required for a reversal.');
            redirect($back);
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT t.txn_id, t.txn_type, t.amount, t.or_no
                   FROM savings_transactions t
                  WHERE t.txn_id = :t AND t.savings_id = :s AND t.txn_type <> 'reversal'
                    AND t.or_no IS NOT NULL -- entries without an OR are system postings (e.g. stockshare at loan release)
                    AND NOT EXISTS (SELECT 1 FROM savings_transactions r WHERE r.reverses_txn_id = t.txn_id)
                  FOR UPDATE"
            );
            $stmt->execute([':t' => $txnId, ':s' => $id]);
            $orig = $stmt->fetch();
            if (!$orig) {
                throw new DomainException('That entry cannot be reversed: it was already reversed, or it is a system entry such as the stockshare from a loan release.');
            }
            $direction = $orig['txn_type'] === 'deposit' ? -1 : 1;
            $res = savings_entry($id, 'reversal', (float) $orig['amount'], $direction, date('Y-m-d'),
                'Reversal of ' . ($orig['or_no'] ?: 'entry #' . $orig['txn_id']) . ': ' . $reason, (int) $orig['txn_id'], false);
            audit_log('reverse', 'savings_transactions', $res['txn_id'], 'Reversed txn #' . $orig['txn_id'] . ' (' . money($orig['amount']) . "): $reason");
            $pdo->commit();
            flash('success', 'Entry reversed. New balance ' . money($res['balance']) . '.');
        } catch (DomainException $e) {
            $pdo->rollBack();
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            db_failure($e);
        }
        redirect($back);
    }

    // Close (Manager) — only at zero balance; reopen (Manager or Bookkeeper)
    if ($action === 'close' || $action === 'reopen') {
        require_permission('savings', $action === 'close' ? 'delete' : 'update');
        try {
            if ($action === 'close') {
                $upd = $pdo->prepare("UPDATE savings_accounts SET status = 'closed' WHERE savings_id = :id AND status = 'active' AND balance = 0");
            } else {
                $upd = $pdo->prepare("UPDATE savings_accounts SET status = 'active' WHERE savings_id = :id AND status = 'closed'");
            }
            $upd->execute([':id' => $id]);
            if ($upd->rowCount() === 1) {
                audit_log($action, 'savings_accounts', $id, label($acct['account_type']) . ' of ' . $acct['member_no']);
                flash('success', 'Account ' . ($action === 'close' ? 'closed.' : 'reopened.'));
            } else {
                flash('error', $action === 'close' ? 'Only an active account with a zero balance can be closed.' : 'Account is not closed.');
            }
        } catch (Throwable $e) {
            db_failure($e);
        }
        redirect($back);
    }
}

// Ledger lines; for a reversal, "orig_type" tells which column it belongs in.
$stmt = db()->prepare(
    "SELECT t.txn_id, t.txn_date, t.txn_type, t.amount, t.running_balance, t.or_no, t.remarks, u.full_name AS posted_by,
            o.txn_type AS orig_type,
            (SELECT COUNT(*) FROM savings_transactions r WHERE r.reverses_txn_id = t.txn_id) AS reversed
       FROM savings_transactions t
       JOIN users u ON u.user_id = t.posted_by
       LEFT JOIN savings_transactions o ON o.txn_id = t.reverses_txn_id
      WHERE t.savings_id = :id
      ORDER BY t.txn_id"
);
$stmt->execute([':id' => $id]);
$txns = $stmt->fetchAll();

$title = ACCOUNT_TYPES[$acct['account_type']] . ' passbook';
$subtitle = $acct['member_name'] . ' · ' . $acct['member_no'] . ' · opened ' . fmt_date($acct['date_opened']);
$buttons = ['<button type="button" class="btn btn-light" data-print><i class="fas fa-print mr-1"></i> Print</button>'];
if (can('savings_txn', 'create') && $acct['status'] === 'active') {
    $buttons[] = '<a href="dashboard.php?page=savings_post&id=' . $id . '" class="btn btn-primary"><i class="fas fa-exchange-alt mr-1"></i> Post transaction</a>';
}
$headerActions = implode(' ', $buttons);
?>
<div class="row">
  <div class="col-lg-9">
    <div class="card">
      <div class="card-body p-0">
        <?php if (!$txns): ?>
          <div class="empty-state"><i class="fas fa-book-open"></i>No transactions yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead><tr><th>Date</th><th>OR no.</th><th>Type</th><th class="num">Deposit</th><th class="num">Withdrawal</th><th class="num">Balance</th><th>Posted by</th><th class="no-print"></th></tr></thead>
              <tbody>
              <?php foreach ($txns as $t):
                  $isCredit = $t['txn_type'] === 'deposit' || ($t['txn_type'] === 'reversal' && $t['orig_type'] === 'withdrawal'); ?>
                <tr class="<?= $t['reversed'] ? 'text-muted' : '' ?>">
                  <td class="text-nowrap"><?= e(fmt_date($t['txn_date'])) ?></td>
                  <td class="text-nowrap">
                    <?php if ($t['or_no']): ?><a href="dashboard.php?page=receipt&txn=<?= (int) $t['txn_id'] ?>"><?= e($t['or_no']) ?></a><?php else: ?>—<?php endif; ?>
                  </td>
                  <td><?= badge($t['txn_type']) ?><?= $t['reversed'] ? ' <span class="badge badge-light">Reversed</span>' : '' ?>
                    <?php if ($t['remarks']): ?><div class="small text-muted"><?= e($t['remarks']) ?></div><?php endif; ?>
                  </td>
                  <td class="num"><?= $isCredit ? e(money($t['amount'])) : '' ?></td>
                  <td class="num"><?= !$isCredit ? e(money($t['amount'])) : '' ?></td>
                  <td class="num font-weight-bold"><?= e(money($t['running_balance'])) ?></td>
                  <td class="small"><?= e($t['posted_by']) ?></td>
                  <td class="text-right no-print">
                    <?php if (can('savings_txn', 'delete') && $t['txn_type'] !== 'reversal' && $t['or_no'] && !$t['reversed'] && $acct['status'] === 'active'): ?>
                      <form method="post" action="" class="d-inline ml-form" data-confirm-reason
                            data-confirm="<?= e('Reverse this ' . $t['txn_type'] . ' of ' . money($t['amount']) . '? A reversing entry will be added; the original stays on record.') ?>"
                            data-confirm-button="Reverse entry">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reverse">
                        <input type="hidden" name="txn_id" value="<?= (int) $t['txn_id'] ?>">
                        <button type="submit" class="btn btn-xs btn-outline-danger" title="Reverse"><i class="fas fa-undo"></i></button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-3">
    <div class="card">
      <div class="card-body">
        <div class="kpi-label">Current balance</div>
        <div class="kpi-value mb-2"><?= e(money($acct['balance'])) ?></div>
        <div class="mb-3"><?= badge($acct['status']) ?></div>
        <p class="small text-muted mb-3">
          <?= in_array($acct['account_type'], WITHDRAWABLE, true)
              ? 'Deposits and withdrawals allowed.'
              : 'Deposits only. Withdrawals are not allowed for this account type.' ?>
        </p>
        <a href="dashboard.php?page=member_view&id=<?= (int) $acct['member_id'] ?>" class="btn btn-sm btn-light btn-block no-print"><i class="fas fa-user mr-1"></i> Member record</a>
        <?php if (can('savings', 'delete') && $acct['status'] === 'active' && (float) $acct['balance'] === 0.0): ?>
          <form method="post" action="" class="mt-2 ml-form no-print" data-confirm="Close this account? It will no longer accept transactions." data-confirm-button="Close account">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="close">
            <button type="submit" class="btn btn-sm btn-outline-danger btn-block"><i class="fas fa-lock mr-1"></i> Close account</button>
          </form>
        <?php endif; ?>
        <?php if (can('savings', 'update') && $acct['status'] === 'closed'): ?>
          <form method="post" action="" class="mt-2 ml-form no-print">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reopen">
            <button type="submit" class="btn btn-sm btn-outline-success btn-block"><i class="fas fa-lock-open mr-1"></i> Reopen account</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
