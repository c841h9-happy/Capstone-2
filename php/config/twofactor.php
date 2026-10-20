<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/mailer.php';

/**
 * Issuing and checking the 6-digit codes emailed for two-step verification.
 *
 * Three purposes share this machinery, because they are the same operation with a
 * different reason for happening:
 *   'signup'       -- prove the address on a new account is real
 *   'login'        -- prove the person with the password also has the mailbox
 *   'email_change' -- prove a new address before it replaces the one on the account
 *
 * The purposes do not interfere. email_codes is keyed on (user_id, purpose), so an
 * email change and a sign-in can each have a code outstanding, each with its own
 * resend throttle.
 */

/** How long a code stays usable. Long enough to find the email, short enough to matter. */
const CODE_TTL_SECONDS = 600;

/** Wrong guesses allowed before the code is thrown away and a new one is needed. */
const CODE_MAX_ATTEMPTS = 5;

/** Minimum gap between sends, so the form cannot be used to flood someone's inbox. */
const CODE_RESEND_SECONDS = 60;

/**
 * A fresh 6-digit code as a string.
 *
 * random_int() is the cryptographically secure generator; rand() and mt_rand()
 * are predictable from previous output, which for a 6-digit secret would be the
 * whole ballgame. Kept as a string because "004182" is a perfectly good code and
 * an int would lose the leading zeros.
 */
function generateCode(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Seconds still to wait before another code may be sent. 0 means go ahead.
 */
function secondsUntilResend(int $userId, string $purpose): int
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT GREATEST(0, :window - TIMESTAMPDIFF(SECOND, sent_at, NOW())) AS wait_seconds
         FROM email_codes
         WHERE user_id = :user_id AND purpose = :purpose'
    );
    $statement->execute([
        'window' => CODE_RESEND_SECONDS,
        'user_id' => $userId,
        'purpose' => $purpose,
    ]);

    $row = $statement->fetch();

    return $row === false ? 0 : (int) $row['wait_seconds'];
}

/**
 * Generate a code, store its hash, and email it.
 *
 * Replaces any code already outstanding for this user and purpose, so only the
 * newest one ever works -- otherwise a resend would widen the window instead of
 * refreshing it.
 *
 * @return array{sent: bool, error: ?string, waitSeconds: int}
 */
function issueEmailCode(int $userId, string $purpose, string $email): array
{
    $wait = secondsUntilResend($userId, $purpose);

    if ($wait > 0) {
        return [
            'sent' => false,
            'error' => 'A code was just sent. Wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before asking for another.',
            'waitSeconds' => $wait,
        ];
    }

    $code = generateCode();

    try {
        $statement = getDatabaseConnection()->prepare(
            'INSERT INTO email_codes (user_id, purpose, code_hash, attempts, sent_at, expires_at)
             VALUES (:user_id, :purpose, :code_hash, 0, NOW(), DATE_ADD(NOW(), INTERVAL :ttl SECOND))
             ON DUPLICATE KEY UPDATE
                 code_hash  = VALUES(code_hash),
                 attempts   = 0,
                 sent_at    = VALUES(sent_at),
                 expires_at = VALUES(expires_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'ttl' => CODE_TTL_SECONDS,
        ]);
    } catch (PDOException $exception) {
        error_log('issueEmailCode: ' . $exception->getMessage());

        return ['sent' => false, 'error' => 'Unable to start verification right now. Check your database connection.', 'waitSeconds' => 0];
    }

    $minutes = (int) round(CODE_TTL_SECONDS / 60);

    // Branching on all three purposes, not on "signup or not". An email change sent
    // through the old two-way test arrived titled "Your sign-in code" and told the
    // reader to finish signing in, which is both wrong and the sort of wrong that
    // makes a real message look like a phishing attempt.
    $subject = match ($purpose) {
        'signup' => 'Confirm your email address',
        'email_change' => 'Confirm your new email address',
        default => 'Your sign-in code',
    };

    $instruction = match ($purpose) {
        'signup' => 'Enter it on the site to finish setting up your account.',
        'email_change' => 'Enter it on the site to finish moving your account to this address.',
        default => 'Enter it on the site to finish signing in.',
    };

    // An email-change code goes to the address being claimed, which may not be the
    // account holder's. Telling a stranger to change their password would be
    // nonsense, so that line is replaced with the one that fits.
    $warning = $purpose === 'email_change'
        ? 'If you did not ask for this, you can ignore it -- nothing will change until this code is entered.'
        : 'If you did not ask for this, someone may know your password -- change it.';

    $body = "Your verification code is:\n\n"
        . '    ' . $code . "\n\n"
        . 'It expires in ' . $minutes . ' minutes and can only be used once.' . "\n\n"
        . $instruction . "\n\n"
        . $warning . "\n";

    if (!sendMail($email, $subject, $body)) {
        // The row stays: they can hit Resend, and leaving it also keeps the
        // throttle honest against a broken mail server being hammered.
        return ['sent' => false, 'error' => 'We could not send the code. Try again in a moment.', 'waitSeconds' => 0];
    }

    return ['sent' => true, 'error' => null, 'waitSeconds' => CODE_RESEND_SECONDS];
}

/**
 * Check a code the user typed in.
 *
 * Order matters: expiry is checked before attempts, so a code that ran out of
 * time says so rather than blaming the guess count.
 *
 * On success the row is deleted, which is what makes a code single-use. On the
 * last allowed failure the row is deleted too -- keeping it would let someone
 * carry on guessing against a code they already exhausted.
 *
 * @return array{ok: bool, error: ?string}
 */
function verifyEmailCode(int $userId, string $purpose, string $code): array
{
    $connection = getDatabaseConnection();

    $statement = $connection->prepare(
        'SELECT code_hash, attempts, expires_at < NOW() AS has_expired
         FROM email_codes
         WHERE user_id = :user_id AND purpose = :purpose'
    );
    $statement->execute(['user_id' => $userId, 'purpose' => $purpose]);
    $row = $statement->fetch();

    if ($row === false) {
        return ['ok' => false, 'error' => 'That code is no longer valid. Ask for a new one.'];
    }

    if ((int) $row['has_expired'] === 1) {
        deleteEmailCode($userId, $purpose);

        return ['ok' => false, 'error' => 'That code has expired. Ask for a new one.'];
    }

    if ((int) $row['attempts'] >= CODE_MAX_ATTEMPTS) {
        deleteEmailCode($userId, $purpose);

        return ['ok' => false, 'error' => 'Too many wrong tries. Ask for a new code.'];
    }

    // password_verify() compares in constant time, so the reply cannot be timed
    // to work out how much of the code was right.
    if (!password_verify($code, $row['code_hash'])) {
        $attempts = (int) $row['attempts'] + 1;

        if ($attempts >= CODE_MAX_ATTEMPTS) {
            deleteEmailCode($userId, $purpose);

            return ['ok' => false, 'error' => 'That code is wrong, and that was the last try. Ask for a new code.'];
        }

        $connection->prepare(
            'UPDATE email_codes SET attempts = :attempts WHERE user_id = :user_id AND purpose = :purpose'
        )->execute(['attempts' => $attempts, 'user_id' => $userId, 'purpose' => $purpose]);

        $left = CODE_MAX_ATTEMPTS - $attempts;

        return ['ok' => false, 'error' => 'That code is wrong. ' . $left . ' more ' . ($left === 1 ? 'try' : 'tries') . ' before it stops working.'];
    }

    deleteEmailCode($userId, $purpose);

    return ['ok' => true, 'error' => null];
}

function deleteEmailCode(int $userId, string $purpose): void
{
    getDatabaseConnection()
        ->prepare('DELETE FROM email_codes WHERE user_id = :user_id AND purpose = :purpose')
        ->execute(['user_id' => $userId, 'purpose' => $purpose]);
}

/**
 * Show an address as "jo•••@gmail.com".
 *
 * The verification page has to say where the code went, or a typo at sign-up is
 * impossible to spot. Printing the whole address would hand it to anyone who got
 * that far with a stolen password, so most of the local part is covered up.
 */
function maskEmail(string $email): string
{
    $at = strrpos($email, '@');

    if ($at === false || $at === 0) {
        return $email;
    }

    $local = substr($email, 0, $at);
    $domain = substr($email, $at);

    // Keep two characters of a name long enough to spare them, one otherwise.
    $keep = mb_strlen($local) > 3 ? 2 : 1;

    return mb_substr($local, 0, $keep) . str_repeat('•', 3) . $domain;
}
