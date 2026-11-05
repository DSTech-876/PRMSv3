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

/**
 * Normalise an SMTP credential read from the environment.
 *
 * Values copied out of a provider UI frequently arrive with stray
 * surrounding whitespace, and Google displays 16-character app passwords
 * in four space-separated groups ("abcd efgh ijkl mnop"). The spaces are
 * presentational only, so they are removed when the value matches that
 * exact shape. Any other password is left untouched because spaces may be
 * significant.
 */
function normalizeMailCredential(?string $value): string
{
    $value = trim((string) $value);

    $stripped = preg_replace('/\s+/', '', $value);
    if ($stripped !== $value && preg_match('/^[A-Za-z0-9]{16}$/', $stripped)) {
        return $stripped;
    }

    return $value;
}

/**
 * Translate a raw PHPMailer failure into operator-facing guidance.
 *
 * PHPMailer reports authentication rejections as "SMTP Error: Could not
 * authenticate." and often appends an unrelated "QUIT command failed"
 * notice from tearing down the already-broken connection, which hides the
 * real cause from whoever is reading the log.
 */
function describeMailFailure(string $errorInfo, string $host, int $port): ?string
{
    if (stripos($errorInfo, 'could not authenticate') !== false) {
        $hint = 'SMTP authentication was rejected by ' . $host . ':' . $port
            . '. Verify MAIL_USER/MAIL_PASS in .env.';

        if (stripos($host, 'gmail') !== false || stripos($host, 'google') !== false) {
            $hint .= ' Google requires a 16-character App Password (not the account password)'
                . ' on an account with 2-Step Verification enabled.';
        }

        $hint .= ' The trailing "QUIT command failed" notice is a side effect of the failed'
            . ' login, not a separate problem.';

        return $hint;
    }

    if (stripos($errorInfo, 'could not connect to smtp host') !== false) {
        return 'Could not reach the SMTP host ' . $host . ':' . $port
            . '. Check MAIL_HOST/MAIL_PORT, outbound firewall rules, and TLS support.';
    }

    return null;
}

function sendMail(string $to, string $subject, string $html): bool
{
    $GLOBALS['__last_mail_error'] = null;

    $smtpUser = normalizeMailCredential(MAIL_USER);
    $smtpPass = normalizeMailCredential(MAIL_PASS);

    error_log("MAIL: Attempting to send email to $to with subject: $subject");
    error_log("MAIL: SMTP Host: " . MAIL_HOST);
    error_log("MAIL: SMTP Port: " . MAIL_PORT);
    error_log("MAIL: SMTP User: " . $smtpUser);

    if (empty(MAIL_HOST) || $smtpUser === '' || $smtpPass === '' || empty(MAIL_FROM)) {

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
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
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

        $hint = describeMailFailure($mail->ErrorInfo . ' ' . $e->getMessage(), (string) MAIL_HOST, (int) MAIL_PORT);
        if ($hint !== null) {
            $errorMsg .= " | How to fix: {$hint}";
        }

        error_log($errorMsg);
        $GLOBALS['__last_mail_error'] = $errorMsg;
        return false;
    }
}
