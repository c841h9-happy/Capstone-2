<?php

declare(strict_types=1);

/**
 * Prove the mail settings work, on their own, from the command line.
 *
 *     cd C:\xampp\htdocs\Capstone
 *     php php/tools/mail-test.php you@gmail.com
 *
 * This exists because sending through Gmail is the one part of two-step
 * verification that cannot be checked without a real app password. Testing it by
 * signing up over and over tells you nothing useful: the sign-in page says only
 * "We could not send the code", on purpose, because a page that repeated the
 * server's reply would be handing details to whoever typed the address in.
 *
 * Here the whole reason is printed instead.
 */

// Sending is the only thing this script does, so a browser has no business
// running it -- and it would print the settings file's contents on failure.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/mailer.php';

$to = $argv[1] ?? '';

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php php/tools/mail-test.php you@example.com\n");
    exit(1);
}

echo 'transport: ', MAIL_TRANSPORT, "\n";
echo 'from:      ', GMAIL_ADDRESS === '' ? '(not set)' : GMAIL_ADDRESS, "\n";
echo 'to:        ', $to, "\n";

if (MAIL_TRANSPORT === 'log') {
    echo "log file:  ", MAIL_LOG_FILE, "\n";
} else {
    echo 'server:    ', SMTP_HOST, ':', SMTP_PORT, "\n";
    // Length only. The password itself is never printed, because a terminal
    // scrollback and a screenshot are both easy to share by accident.
    $length = strlen((string) preg_replace('/\s+/', '', GMAIL_APP_PASSWORD));
    echo 'password:  ', $length === 0 ? '(not set)' : $length . ' characters', "\n";

    if ($length !== 0 && $length !== 16) {
        echo "  note:    Google app passwords are 16 characters. Spaces are fine,\n";
        echo "           they get stripped -- but check nothing else came along.\n";
    }
}

echo "\n";

$subject = 'Mail test';
$body = "If you are reading this, php/config/mail.php is set up correctly and\n"
    . "two-step verification can email its codes.\n\n"
    . "Sent by php/tools/mail-test.php at " . date(DATE_RFC2822) . ".\n";

/*
 * sendMail() deliberately keeps failures out of the caller's hands and puts them
 * in the error log, so the reason is fished out of there rather than returned.
 * Nothing was logged before this line runs, so whatever appears after it is ours.
 */
$logBefore = mailErrorLogSnapshot();
$ok = sendMail($to, $subject, $body);
$logAfter = mailErrorLogSnapshot();

if ($ok && MAIL_TRANSPORT === 'log') {
    echo "OK -- written to the log file. Nothing was actually emailed.\n\n";
    echo "MAIL_TRANSPORT is still 'log' in php/config/mail.php. Codes will show up\n";
    echo "in the file above instead of an inbox, which is fine for testing. Set it\n";
    echo "to 'smtp' and fill in your app password to send for real.\n";
    exit(0);
}

if ($ok) {
    echo "OK -- Gmail accepted the message. Check the inbox (and the spam folder).\n";
    exit(0);
}

echo "FAILED.\n\n";

$reason = substr($logAfter, strlen($logBefore));

if (trim($reason) !== '') {
    echo trim($reason), "\n\n";
}

echo "Things worth checking, in the order they usually go wrong:\n";
echo "  1. MAIL_TRANSPORT is 'smtp' and GMAIL_ADDRESS / GMAIL_APP_PASSWORD are\n";
echo "     filled in, in php/config/mail.php.\n";
echo "  2. The password is an App Password from\n";
echo "     https://myaccount.google.com/apppasswords -- not the account password.\n";
echo "     Google stopped accepting account passwords in 2022.\n";
echo "  3. 2-Step Verification is on for that Google account. Google will not\n";
echo "     issue an app password without it.\n";
echo "  4. Nothing local is blocking port 587 outbound -- a firewall, or a\n";
echo "     network that closes it to stop spam.\n";
exit(1);

/**
 * Everything PHP's error log holds right now, or '' if it cannot be read.
 *
 * error_log() with no error_log setting goes to stderr under CLI, in which case
 * the reason has already been printed by the time we look and there is nothing
 * to read back.
 */
function mailErrorLogSnapshot(): string
{
    $path = (string) ini_get('error_log');

    if ($path === '' || !is_readable($path)) {
        return '';
    }

    return (string) file_get_contents($path);
}
