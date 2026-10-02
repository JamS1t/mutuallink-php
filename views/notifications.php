<?php
declare(strict_types=1);

// DFD 6.5 — reminders for approaching and overdue payments (recorded; staff send them).
$title = 'Payment reminders';
$subtitle = 'Generated from the schedules. FFMPC prefers text messages (SMS): tap the phone icon to open the SMS with the message ready, or email, then mark it sent.';
$back = 'dashboard.php?page=notifications';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = input('action');
    $pdo = db();
    try {
        if ($action === 'generate') {
            require_permission('notifications', 'create');
            $today = date('Y-m-d');
            $lead = max(1, min(30, (int) setting('reminder_lead_days')));
            $until = date('Y-m-d', strtotime("+$lead days"));

            // Upcoming: next unpaid installment falling due within the lead days
            $stmt = $pdo->prepare(
                "SELECT l.loan_id, m.member_id, m.first_name, s.installment_no, s.due_date,
                        s.total_due - s.principal_paid - s.interest_paid AS amount_due
                   FROM amortization_schedule s JOIN loans l ON l.loan_id = s.loan_id JOIN members m ON m.member_id = l.member_id
                  WHERE l.status = 'released' AND s.status <> 'paid' AND s.due_date BETWEEN :f AND :t"
            );
            $stmt->execute([':f' => $today, ':t' => $until]);
            $upcoming = $stmt->fetchAll();
            $overdue = overdue_loans($today);

            $exists = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE loan_id = :l AND notification_type = :t AND status = 'pending'");
            $ins = $pdo->prepare('INSERT INTO notifications (member_id, loan_id, notification_type, message, created_by) VALUES (:m, :l, :t, :msg, :u)');
            $created = 0;
            $pdo->beginTransaction();
            foreach ($upcoming as $u) {
                $exists->execute([':l' => $u['loan_id'], ':t' => 'upcoming']);
                if ((int) $exists->fetchColumn() > 0) { continue; }
                $msg = 'Good day, ' . $u['first_name'] . '! This is a reminder from FFMPC that installment ' . $u['installment_no']
                    . ' of your loan #' . $u['loan_id'] . ' amounting to ' . money($u['amount_due']) . ' is due on ' . fmt_date($u['due_date'])
                    . '. Thank you for paying on time.';
                $ins->execute([':m' => $u['member_id'], ':l' => $u['loan_id'], ':t' => 'upcoming', ':msg' => $msg, ':u' => current_user_id()]);
                $created++;
            }
            foreach ($overdue as $o) {
                $exists->execute([':l' => $o['loan_id'], ':t' => 'overdue']);
                if ((int) $exists->fetchColumn() > 0) { continue; }
                $msg = 'Good day! FFMPC records show that your loan #' . $o['loan_id'] . ' has ' . money($o['amount_past_due'])
                    . ' past due since ' . fmt_date($o['oldest_due']) . ' (' . $o['days_past_due'] . ' days). A penalty applies to late payments. '
                    . 'Please settle at the cooperative office at your earliest convenience.';
                $ins->execute([':m' => $o['member_id'], ':l' => $o['loan_id'], ':t' => 'overdue', ':msg' => $msg, ':u' => current_user_id()]);
                $created++;
            }
            audit_log('generate', 'notifications', null, "$created reminder(s) created");
            $pdo->commit();
            flash('success', $created > 0 ? "$created new reminder(s) ready to send." : 'No new reminders: everything due is already queued.');
        } elseif (in_array($action, ['mark_sent', 'mark_failed', 'mark_pending', 'delete', 'restore'], true)) {
            // mark_pending/restore exist so the success toast's Undo button can
            // reverse a non-financial action; only reminders (no money) can be undone.
            require_permission('notifications', in_array($action, ['delete', 'restore'], true) ? 'delete' : 'update');
            $id = post_id('notification_id');
            if ($action === 'delete') {
                $get = $pdo->prepare('SELECT member_id, loan_id, notification_type, message, created_by FROM notifications WHERE notification_id = :id AND status <> :sent');
                $get->bindValue(':id', $id, PDO::PARAM_INT);
                $get->bindValue(':sent', 'sent');
                $get->execute();
                $row = $get->fetch();
                if (!$row) {
                    flash('error', 'That reminder cannot be changed (it may already be sent).');
                    redirect($back);
                }
                $st = $pdo->prepare("DELETE FROM notifications WHERE notification_id = :id AND status <> 'sent'");
            } elseif ($action === 'restore') {
                $st = $pdo->prepare('INSERT INTO notifications (member_id, loan_id, notification_type, message, created_by) VALUES (:m, :l, :t, :msg, :u)');
            } elseif ($action === 'mark_pending') {
                $st = $pdo->prepare("UPDATE notifications SET status = 'pending', date_sent = NULL WHERE notification_id = :id AND status IN ('sent','failed')");
            } else {
                $st = $pdo->prepare("UPDATE notifications SET status = :s, date_sent = IF(:s2 = 'sent', NOW(), NULL) WHERE notification_id = :id AND status IN ('pending','failed')");
                $st->bindValue(':s', $action === 'mark_sent' ? 'sent' : 'failed');
                $st->bindValue(':s2', $action === 'mark_sent' ? 'sent' : 'failed');
            }
            if ($action === 'restore') {
                $st->bindValue(':m', (int) input('member_id'), PDO::PARAM_INT);
                $st->bindValue(':l', (int) input('loan_id'), PDO::PARAM_INT);
                $st->bindValue(':t', input('notification_type') === 'overdue' ? 'overdue' : 'upcoming');
                $st->bindValue(':msg', input('message'));
                $st->bindValue(':u', current_user_id(), PDO::PARAM_INT);
            } else {
                $st->bindValue(':id', $id, PDO::PARAM_INT);
            }
            $st->execute();
            if ($st->rowCount() >= 1) {
                audit_log($action, 'notifications', $id ?: null);
                if ($action === 'mark_sent' || $action === 'mark_failed') {
                    flash_undo(['mark_sent' => 'Marked as sent.', 'mark_failed' => 'Marked as failed.'][$action], $back,
                        ['action' => 'mark_pending', 'notification_id' => (string) $id]);
                } elseif ($action === 'delete') {
                    flash_undo('Reminder removed.', $back, [
                        'action' => 'restore', 'member_id' => (string) $row['member_id'], 'loan_id' => (string) $row['loan_id'],
                        'notification_type' => (string) $row['notification_type'], 'message' => (string) $row['message'],
                        'created_by' => (string) $row['created_by'],
                    ]);
                } elseif ($action === 'mark_pending') {
                    flash('success', 'Undo: the reminder is back in the to-send list.');
                } else {
                    flash('success', 'Undo: the reminder is restored to the list.');
                }
            } else {
                flash('error', 'That reminder cannot be changed (it may already be sent).');
            }
        }
    } catch (ForbiddenException $e) {
        throw $e;
    } catch (Throwable $e) {
        db_failure($e);
    }
    redirect($back);
}

$status = in_array($_GET['status'] ?? '', ['pending', 'sent', 'failed', 'all'], true) ? $_GET['status'] : 'pending';
$sql = "SELECT n.*, m.email, m.contact_no, CONCAT(m.last_name, ', ', m.first_name) AS member_name
          FROM notifications n JOIN members m ON m.member_id = n.member_id";
if ($status === 'all') {
    $rows = db()->query($sql . ' ORDER BY n.notification_id DESC LIMIT 500')->fetchAll();
} else {
    $stmt = db()->prepare($sql . ' WHERE n.status = :s ORDER BY n.notification_id DESC LIMIT 500');
    $stmt->execute([':s' => $status]);
    $rows = $stmt->fetchAll();
}
$counts = db()->query('SELECT status, COUNT(*) FROM notifications GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

if (can('notifications', 'create')) {
    $headerActions = '<form method="post" action="" class="d-inline ml-form">' . csrf_field()
        . '<input type="hidden" name="action" value="generate"><button type="submit" class="btn btn-primary"><i class="fas fa-bell mr-1"></i> Generate reminders</button></form>';
}
?>
<div class="card">
  <div class="card-header">
    <ul class="nav nav-pills">
      <?php foreach (['pending' => 'To send', 'sent' => 'Sent', 'failed' => 'Failed', 'all' => 'All'] as $k => $v): ?>
        <li class="nav-item"><a href="dashboard.php?page=notifications&status=<?= $k ?>" class="nav-link py-1 <?= $status === $k ? 'active bg-primary' : '' ?>">
          <?= e($v) ?><?php if ($k !== 'all'): ?> <span class="badge <?= $status === $k ? 'badge-light' : 'badge-secondary' ?> ml-1"><?= (int) ($counts[$k] ?? 0) ?></span><?php endif; ?>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card-body">
    <p class="small text-muted"><i class="fas fa-info-circle mr-1"></i>Reminders due within <?= (int) setting('reminder_lead_days') ?> day(s) and all overdue accounts are generated. Existing unsent reminders are not duplicated.</p>
    <table class="table table-hover js-datatable" data-order='[[0,"desc"]]' data-empty="No reminders here.">
      <thead><tr><th>#</th><th>Member</th><th>Type</th><th style="width: 40%">Message</th><th>Status</th><th class="no-sort text-right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $n):
          $subject = 'FFMPC loan #' . $n['loan_id'] . ' payment reminder';
          $mailto = $n['email'] ? 'mailto:' . rawurlencode($n['email']) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($n['message']) : ''; ?>
        <tr>
          <td data-order="<?= (int) $n['notification_id'] ?>"><?= (int) $n['notification_id'] ?></td>
          <td><a href="dashboard.php?page=member_view&id=<?= (int) $n['member_id'] ?>" class="font-weight-bold"><?= e($n['member_name']) ?></a>
            <div class="small text-muted"><?= e($n['contact_no'] ?: 'no mobile') ?> · <?= e($n['email'] ?: 'no email') ?></div></td>
          <td><?= badge($n['notification_type']) ?><div class="small"><a href="dashboard.php?page=loan_view&id=<?= (int) $n['loan_id'] ?>">Loan #<?= (int) $n['loan_id'] ?></a></div></td>
          <td class="small"><?= e($n['message']) ?></td>
          <td><?= badge($n['status']) ?><?= $n['date_sent'] ? '<div class="small text-muted">' . e(fmt_date($n['date_sent'], 'M d, g:i A')) . '</div>' : '' ?></td>
          <td class="text-right text-nowrap">
            <?php if (can('notifications', 'update') && in_array($n['status'], ['pending', 'failed'], true)): ?>
              <?php if ($n['contact_no']): ?>
                <a href="sms:<?= e(preg_replace('/[^\d+]/', '', $n['contact_no'])) ?>?body=<?= rawurlencode($n['message']) ?>" class="btn btn-sm btn-primary" title="Open in SMS app (preferred)" aria-label="Open in SMS app for <?= e($n['member_name']) ?>">
                  <i class="fas fa-sms" aria-hidden="true"></i></a>
              <?php endif; ?>
              <?php if ($mailto): ?><a href="<?= e($mailto) ?>" class="btn btn-sm btn-light" title="Open in email app" aria-label="Open in email app for <?= e($n['member_name']) ?>"><i class="fas fa-envelope" aria-hidden="true"></i></a><?php endif; ?>
              <?php foreach (['mark_sent' => ['fa-check', 'btn-outline-success', 'Mark sent'], 'mark_failed' => ['fa-times', 'btn-outline-warning', 'Mark failed']] as $act => [$icon, $cls, $tip]): ?>
                <form method="post" action="" class="d-inline ml-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="<?= $act ?>">
                  <input type="hidden" name="notification_id" value="<?= (int) $n['notification_id'] ?>">
                  <button type="submit" class="btn btn-sm <?= $cls ?>" title="<?= $tip ?>" aria-label="<?= e($tip) ?> reminder for <?= e($n['member_name']) ?>"><i class="fas <?= $icon ?>" aria-hidden="true"></i></button>
                </form>
              <?php endforeach; ?>
            <?php endif; ?>
            <?php if (can('notifications', 'delete') && $n['status'] !== 'sent'): ?>
              <form method="post" action="" class="d-inline ml-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="notification_id" value="<?= (int) $n['notification_id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove" aria-label="Remove reminder for <?= e($n['member_name']) ?>"><i class="fas fa-trash" aria-hidden="true"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
