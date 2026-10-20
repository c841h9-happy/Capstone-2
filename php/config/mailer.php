<?php

declare(strict_types=1);

require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/settings.php';

/**
 * Sending mail, either for real through Gmail or into a log file.
 *
 * Why this exists instead of PHP's mail(): on Windows, mail() hands the message
 * to the SMTP server named in php.ini and cannot authenticate or start TLS.
 * Gmail requires both. The alternative is XAMPP's bundled sendmail.exe, which
 * means editing php.ini and restarting Apache, and its OpenSSL is old enough
 * that Gmail often refuses it. Talking SMTP directly needs nothing installed and
 * nothing outside this project changed -- the openssl extension is already on.
 *
 * THE SENDER IS NO LONGER READ FROM THE CONSTANTS DIRECTLY, as of 2026-10-11. The three values
 * that say who the mail is from -- the address, its app password and the display name -- come from
 * mailSenderAddress(), mailSenderPassword() and mailFromName() in php/config/settings.php, each of
 * which prefers a row in the `settings` table and falls back to the `const` in
 * php/config/mail.php. So the file remains the documented default and php/admin/settings.php is an
 * override the shop can use without editing code.
 *
 * MAIL_TRANSPORT is deliberately still a plain const read: it decides whether verification codes
 * reach anybody at all, and it is the one mail setting where a wrong value locks every customer out
 * of their own account.
 */

/**
 * Send one plain-text message. Returns false if it could not be sent.
 *
 * The reason for a failure goes to PHP's error log, never to the caller: an SMTP
 * error can quote the recipient address or the server's own wording, and neither
 * belongs on a sign-in page.
 */
function sendMail(string $toAddress, string $subject, string $body): bool
{
    $message = buildMailMessage($toAddress, $subject, $body);

    if (MAIL_TRANSPORT === 'log') {
        return logMailMessage($toAddress, $message);
    }

    if (mailSenderAddress() === '' || mailSenderPassword() === '') {
        error_log('sendMail: MAIL_TRANSPORT is smtp but the sender address or app password is empty -- set them on php/admin/settings.php, or in php/config/mail.php as the fallback');

        return false;
    }

    try {
        smtpSendMessage($toAddress, $message);

        return true;
    } catch (RuntimeException $exception) {
        error_log('sendMail: ' . $exception->getMessage());

        return false;
    }
}

/**
 * Flatten a value that is about to be placed in a header.
 *
 * A carriage return or newline inside a header value ends that header and starts
 * another, which is how a display name like "Bob\r\nBcc: someone@else" turns one
 * message into two. Every header value here goes through this first.
 */
function mailHeaderValue(string $value): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
}

/**
 * Assemble the whole message: headers, blank line, then the body.
 *
 * The To: header carries only the bare address, with no display name. The name
 * on the account is chosen by whoever signed up, so leaving it out avoids both
 * the quoting rules for names containing punctuation and the MIME encoding rules
 * for names containing anything outside ASCII.
 */
function buildMailMessage(string $toAddress, string $subject, string $body): string
{
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mb_encode_mimeheader(mailHeaderValue(mailFromName()), 'UTF-8', 'B')
            . ' <' . mailHeaderValue(mailSenderAddress() !== '' ? mailSenderAddress() : 'no-reply@localhost') . '>',
        'To: ' . mailHeaderValue($toAddress),
        'Subject: ' . mb_encode_mimeheader(mailHeaderValue($subject), 'UTF-8', 'B'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: 8bit',
        // Tells well-behaved mail clients not to send an auto-reply to a code.
        'Auto-Submitted: auto-generated',
    ];

    // Every line in an SMTP message ends CRLF, whatever the file it came from used.
    $normalised = str_replace(["\r\n", "\r"], "\n", $body);
    $normalised = str_replace("\n", "\r\n", $normalised);

    // Dot-stuffing: a line that is just "." is how the body is terminated, so any
    // real line starting with a dot gets a second one, which the server strips.
    $normalised = preg_replace('/^\./m', '..', $normalised);

    return implode("\r\n", $headers) . "\r\n\r\n" . $normalised;
}

/**
 * The 'log' transport. Appends the message to MAIL_LOG_FILE.
 */
function logMailMessage(string $toAddress, string $message): bool
{
    $entry = str_repeat('=', 72) . "\n"
        . 'NOT SENT -- MAIL_TRANSPORT is \'log\' in php/config/mail.php' . "\n"
        . 'would have gone to: ' . mailHeaderValue($toAddress) . "\n"
        . 'at: ' . date(DATE_RFC2822) . "\n"
        . str_repeat('-', 72) . "\n"
        . str_replace("\r\n", "\n", $message) . "\n\n";

    if (file_put_contents(MAIL_LOG_FILE, $entry, FILE_APPEND | LOCK_EX) === false) {
        error_log('logMailMessage: could not write to ' . MAIL_LOG_FILE);

        return false;
    }

    return true;
}

/**
 * Read one reply from the server.
 *
 * A reply can span several lines: the first three characters are the status code,
 * and the fourth is "-" on every line but the last. Reading only one line is the
 * usual way a hand-written SMTP client desynchronises, because the leftover lines
 * then get read as the answer to the *next* command.
 *
 * @return array{0: int, 1: string} status code (0 if nothing came back) and text
 */
function smtpRead($socket): array
{
    $lines = [];

    while (true) {
        $line = fgets($socket, 1024);

        if ($line === false) {
            $info = stream_get_meta_data($socket);

            return [0, $info['timed_out'] ? 'timed out waiting for a reply' : 'connection closed'];
        }

        $lines[] = rtrim($line, "\r\n");

        // Anything shorter than "250-" cannot be a continuation, and treating it
        // as one would loop here forever.
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    $last = $lines[count($lines) - 1];

    return [(int) substr($last, 0, 3), implode(' / ', $lines)];
}

/**
 * Send one command and insist on one of the expected status codes.
 *
 * $label is what appears in an error instead of the command itself, so the
 * base64 of the app password cannot end up in a log file.
 *
 * @param list<int> $expected
 */
function smtpCommand($socket, string $command, array $expected, string $label): void
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException($label . ': could not write to the server');
    }

    [$code, $text] = smtpRead($socket);

    if (!in_array($code, $expected, true)) {
        throw new RuntimeException($label . ': expected ' . implode('/', $expected) . ', got ' . $text);
    }
}

/**
 * Hand the assembled message to Gmail over an authenticated TLS connection.
 *
 * @throws RuntimeException on any step that does not go as expected
 */
function smtpSendMessage(string $toAddress, string $message): void
{
    $socket = @stream_socket_client(
        'tcp://' . SMTP_HOST . ':' . SMTP_PORT,
        $errorNumber,
        $errorMessage,
        SMTP_TIMEOUT
    );

    if ($socket === false) {
        throw new RuntimeException('could not reach ' . SMTP_HOST . ':' . SMTP_PORT . ' -- ' . $errorMessage . ' (' . $errorNumber . ')');
    }

    // Without this a server that stops replying mid-conversation would hang the
    // page until PHP's own execution limit killed it.
    stream_set_timeout($socket, SMTP_TIMEOUT);

    try {
        [$code, $text] = smtpRead($socket);

        if ($code !== 220) {
            throw new RuntimeException('greeting: expected 220, got ' . $text);
        }

        // The name given here is cosmetic for submission on port 587, but it has
        // to be syntactically valid or some servers reject the session.
        smtpCommand($socket, 'EHLO capstone.localhost', [250], 'EHLO');
        smtpCommand($socket, 'STARTTLS', [220], 'STARTTLS');

        // Certificate verification is on by default and is what makes the app
        // password safe to send. If this ever starts failing, the CA bundle in
        // php.ini (curl.cainfo / openssl.cafile) is the thing to look at -- do
        // not "fix" it by switching verification off.
        if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            throw new RuntimeException('TLS handshake with ' . SMTP_HOST . ' failed');
        }

        // Everything the server advertised before the handshake is discarded, so
        // it has to be asked again now the connection is encrypted.
        smtpCommand($socket, 'EHLO capstone.localhost', [250], 'EHLO after STARTTLS');

        // Google prints the app password in four groups for readability; the
        // spaces are not part of it.
        $appPassword = preg_replace('/\s+/', '', mailSenderPassword());

        smtpCommand($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
        smtpCommand($socket, base64_encode(mailSenderAddress()), [334], 'AUTH username');
        smtpCommand($socket, base64_encode((string) $appPassword), [235], 'AUTH password (check the app password on php/admin/settings.php, or GMAIL_APP_PASSWORD in php/config/mail.php)');

        smtpCommand($socket, 'MAIL FROM:<' . mailSenderAddress() . '>', [250], 'MAIL FROM');
        smtpCommand($socket, 'RCPT TO:<' . mailHeaderValue($toAddress) . '>', [250, 251], 'RCPT TO');
        smtpCommand($socket, 'DATA', [354], 'DATA');
        smtpCommand($socket, $message . "\r\n.", [250], 'message body');

        // Best effort: the message is already accepted by this point, so a server
        // that drops the connection instead of answering does not matter.
        @fwrite($socket, "QUIT\r\n");
    } finally {
        fclose($socket);
    }
}
