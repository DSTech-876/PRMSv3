<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/app.php';

/** Holds the most recent sendMail() failure reason so callers that only
 *  get a bool back (the vast majority of call sites) can still surface
 *  a human-readable error, e.g. in the email diagnostic tool. */
$GLOBALS['__last_mail_error'] = null;

function getLastMailError(): ?string
{
    return $GLOBALS['__last_mail_error'] ?? null;
}

function sendMail(string $to, string $subject, string $html): bool
{
    $GLOBALS['__last_mail_error'] = null;

    error_log("MAIL: Attempting to send email to $to with subject: $subject");
    error_log("MAIL: SMTP Host: " . MAIL_HOST);
    error_log("MAIL: SMTP Port: " . MAIL_PORT);
    error_log("MAIL: SMTP User: " . MAIL_USER);

    if (empty(MAIL_HOST) || empty(MAIL_USER) || empty(MAIL_PASS) || empty(MAIL_FROM)) {
        $errorMsg = 'Mailer not configured: one or more of MAIL_HOST, MAIL_USER, MAIL_PASS, MAIL_FROM is missing/empty in the environment (.env).';
        error_log("MAIL: $errorMsg");
        $GLOBALS['__last_mail_error'] = $errorMsg;
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        // Port 465 uses implicit TLS (SMTPS); other ports (e.g. 587/25) use STARTTLS.
        $mail->SMTPSecure = (int) MAIL_PORT === 465
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags(preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html));

        error_log("MAIL: Connecting to SMTP server...");
        $mail->send();
        error_log("MAIL: Email sent successfully to $to");
        return true;

    } catch (Exception $e) {
        $errorMsg = "Mailer error: {$mail->ErrorInfo} | Exception: {$e->getMessage()}";
        error_log($errorMsg);
        $GLOBALS['__last_mail_error'] = $errorMsg;
        return false;
    }
}
