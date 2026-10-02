<?php
declare(strict_types=1);

/**
 * Email reminders via SMTP — the only internet-dependent function of the
 * system. Uses PHPMailer vendored in plugins/phpmailer (no composer).
 * Everything else keeps working offline: when SMTP is not configured the
 * reminders simply stay pending and nothing else breaks.
 */

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../plugins/phpmailer/Exception.php';
require_once __DIR__ . '/../plugins/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../plugins/phpmailer/SMTP.php';

/** True when the Manager has filled in the SMTP settings (Reminders → Email settings). */
function mail_configured(): bool
{
    return setting('smtp_host') !== ''
        && setting('smtp_user') !== ''
        && setting('smtp_pass') !== ''
        && setting('smtp_from') !== '';
}

/**
 * Sends ONE email through the configured SMTP server.
 *
 * @throws RuntimeException with a message that is safe to record in the
 *         notifications row (the raw SMTP transcript never reaches the UI).
 */
function send_mail(string $to, string $subject, string $body): void
{
    if (!mail_configured()) {
        throw new RuntimeException('SMTP is not configured (Reminders → Email settings).');
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = setting('smtp_host');
        $mail->Port       = (int) (setting('smtp_port') ?: 587);
        $mail->SMTPAuth   = true;
        $mail->Username   = setting('smtp_user');
        $mail->Password   = setting('smtp_pass');
        $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;
        $mail->setFrom(setting('smtp_from'), 'FFMPC MutualLink');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
    } catch (Throwable $e) {
        throw new RuntimeException($e->getMessage(), 0, $e);
    }
}
