<?php
declare(strict_types=1);

/**
 * Sends the queued payment reminders by email (Objective 4 / DFD 6.5).
 *
 * Run by Windows Task Scheduler every 10–15 minutes (README §7):
 *   "C:\wamp64\bin\php\php8.2.29\php.exe" "C:\wamp64\www\mutuallink\cron_send_reminders.php"
 *
 * Sends every pending reminder whose member has an email address, records
 * sent (with date_sent) or failed (with the SMTP error in the row), and
 * tolerates failures: one bad address never aborts the batch. Reminders the
 * staff prefer to send by SMS can simply stay pending.
 *
 * CLI ONLY — the web server must never execute this script.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only run from the command line.\n");
}

require_once __DIR__ . '/config/bootstrap.php';

$pdo = db();

$due = $pdo->query(
    "SELECT n.notification_id, n.loan_id, n.message, m.email
       FROM notifications n
       JOIN members m ON m.member_id = n.member_id
      WHERE n.status = 'pending' AND m.email IS NOT NULL AND m.email <> ''
      ORDER BY n.notification_id
      LIMIT 200"
)->fetchAll();

if (!mail_configured()) {
    echo 'SMTP is not configured (' . count($due) . ' reminder(s) remain pending). '
        . "Fill in Reminders → Email settings as the Manager.\n";
    exit(0);
}

$upd = $pdo->prepare(
    'UPDATE notifications SET status = :s, date_sent = :d, send_error = :e WHERE notification_id = :id'
);
$sent = 0;
$failed = 0;

foreach ($due as $n) {
    try {
        send_mail(
            (string) $n['email'],
            'FFMPC loan #' . $n['loan_id'] . ' payment reminder',
            $n['message'] . "\n\n--\nThis is an automated reminder from the MutualLink system of the "
            . "Franciscan Friends Multi-Purpose Cooperative. Please do not reply to this email."
        );
        $upd->execute([':s' => 'sent', ':d' => date('Y-m-d H:i:s'), ':e' => null, ':id' => $n['notification_id']]);
        $sent++;
    } catch (Throwable $e) {
        // Tolerate failures: record the reason and keep going with the batch.
        $upd->execute([':s' => 'failed', ':d' => null, ':e' => mb_substr($e->getMessage(), 0, 255), ':id' => $n['notification_id']]);
        $failed++;
        error_log('MutualLink reminder #' . $n['notification_id'] . ' to ' . $n['email'] . ' failed: ' . $e->getMessage());
        echo 'FAILED reminder #' . $n['notification_id'] . ' (' . $n['email'] . '): ' . $e->getMessage() . "\n";
    }
}

audit_log('send', 'notifications', null, "Reminder emails: $sent sent, $failed failed out of " . count($due) . ' due');
echo date('Y-m-d H:i:s') . " reminder emails: $sent sent, $failed failed (of " . count($due) . " due).\n";
exit($failed > 0 ? 1 : 0);
