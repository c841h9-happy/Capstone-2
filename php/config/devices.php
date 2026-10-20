<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * The "Remember this device for 30 days" checkbox on the verification page.
 *
 * A ticked box stores a random token in a cookie and its hash in the database.
 * On the next sign-in, a browser presenting a token that is still valid skips the
 * emailed code. Every other browser is still challenged, which is the whole point
 * -- the trust is in this one browser, not in the account.
 *
 * Note this deliberately survives logging out. Clearing it on logout would make
 * the checkbox pointless, and it matches how Google and GitHub behave. Undoing it
 * needs a "sign out everywhere" screen, which does not exist yet -- the `label`
 * column is already carrying user agents so that screen has something to list.
 */

const TRUSTED_DEVICE_COOKIE = 'capstone_device';
const TRUSTED_DEVICE_DAYS = 30;

/**
 * Options for the trust cookie.
 *
 * 'secure' is false because this project runs over plain HTTP on localhost, and a
 * Secure cookie would be thrown away by the browser instead of stored. Turn it on
 * the moment the site is served over HTTPS.
 *
 * 'httponly' keeps the token away from JavaScript, so a cross-site scripting bug
 * elsewhere on the site cannot read it and reuse it. 'Lax' still sends it on a
 * normal top-level navigation, which is all that is needed here.
 */
function trustedDeviceCookieOptions(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Is the browser making this request already trusted for this account?
 *
 * The token is looked up by its own value, so it is hashed with sha256 rather
 * than password_hash(): the hash has to come out the same every time to be
 * searchable. That is safe here in a way it would not be for a password, because
 * the token is 32 bytes straight from the system's random source -- there is no
 * small set of likely values for an attacker to hash through.
 */
function deviceIsTrusted(int $userId): bool
{
    $token = (string) ($_COOKIE[TRUSTED_DEVICE_COOKIE] ?? '');

    if ($token === '') {
        return false;
    }

    try {
        $statement = getDatabaseConnection()->prepare(
            'SELECT 1 FROM trusted_devices
             WHERE token_hash = :token_hash
               AND user_id = :user_id
               AND expires_at > NOW()
             LIMIT 1'
        );
        $statement->execute([
            'token_hash' => hash('sha256', $token),
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    } catch (PDOException $exception) {
        // A database problem must not turn into a free pass, so fall back to
        // asking for the code.
        error_log('deviceIsTrusted: ' . $exception->getMessage());

        return false;
    }
}

/**
 * Remember this browser for TRUSTED_DEVICE_DAYS.
 *
 * Must be called before any output, because it sets a cookie.
 */
function trustThisDevice(int $userId): bool
{
    $token = bin2hex(random_bytes(32));
    $expires = time() + TRUSTED_DEVICE_DAYS * 86400;

    // Truncated because the column is 255 and some user agent strings are longer.
    $label = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

    try {
        getDatabaseConnection()->prepare(
            'INSERT INTO trusted_devices (user_id, token_hash, label, expires_at)
             VALUES (:user_id, :token_hash, :label, FROM_UNIXTIME(:expires))'
        )->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'label' => $label,
            'expires' => $expires,
        ]);
    } catch (PDOException $exception) {
        error_log('trustThisDevice: ' . $exception->getMessage());

        return false;
    }

    setcookie(TRUSTED_DEVICE_COOKIE, $token, trustedDeviceCookieOptions($expires));

    pruneExpiredDevices();

    return true;
}

/**
 * Drop this browser's trust and clear its cookie.
 *
 * Nothing calls this yet. It is here so a future "forget this device" control has
 * the matching half of trustThisDevice(), and so the cookie can be cleared during
 * testing.
 */
function forgetThisDevice(): void
{
    $token = (string) ($_COOKIE[TRUSTED_DEVICE_COOKIE] ?? '');

    if ($token !== '') {
        try {
            getDatabaseConnection()
                ->prepare('DELETE FROM trusted_devices WHERE token_hash = :token_hash')
                ->execute(['token_hash' => hash('sha256', $token)]);
        } catch (PDOException $exception) {
            error_log('forgetThisDevice: ' . $exception->getMessage());
        }
    }

    unset($_COOKIE[TRUSTED_DEVICE_COOKIE]);
    setcookie(TRUSTED_DEVICE_COOKIE, '', trustedDeviceCookieOptions(time() - 42000));
}

/**
 * Drop the trust on every browser for this account, including this one.
 *
 * Called when the password changes. Someone changing their password because another
 * person knows it has to actually shut that person out -- and a trusted-device row
 * is a standing permission to skip the emailed code, so leaving one in place would
 * leave the intruder's browser able to sign in with the new password's own second
 * step waived. There is no way to tell that browser's row from the owner's, so they
 * all go and everybody proves themselves once more.
 *
 * Clears this browser's cookie too, so the next sign-in here is challenged rather
 * than presenting a token whose row no longer exists.
 *
 * Must be called before any output, because it clears a cookie.
 */
function forgetAllDevices(int $userId): void
{
    try {
        getDatabaseConnection()
            ->prepare('DELETE FROM trusted_devices WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    } catch (PDOException $exception) {
        error_log('forgetAllDevices: ' . $exception->getMessage());
    }

    unset($_COOKIE[TRUSTED_DEVICE_COOKIE]);
    setcookie(TRUSTED_DEVICE_COOKIE, '', trustedDeviceCookieOptions(time() - 42000));
}

/**
 * Clear out rows nobody can use any more.
 *
 * Called after a successful insert rather than on every request: there is no cron
 * job in a XAMPP project, and expired rows are only dead weight, not a risk.
 */
function pruneExpiredDevices(): void
{
    try {
        getDatabaseConnection()->exec('DELETE FROM trusted_devices WHERE expires_at <= NOW()');
    } catch (PDOException $exception) {
        error_log('pruneExpiredDevices: ' . $exception->getMessage());
    }
}
