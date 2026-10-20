<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Two of the three gaps Security's `[x]` named: throttling a password guess, and binding a session
 * to the browser it was issued to.
 *
 * Both are here because both answer the same question -- *is this sign-in the person it claims to
 * be* -- and both are read on the hottest path in the project. The third gap, HTTPS, is not here
 * and cannot be: it needs a certificate and a server configuration, not PHP.
 *
 * ---------------------------------------------------------------------------------------------
 * 1. RATE LIMITING
 * ---------------------------------------------------------------------------------------------
 *
 * `php/login.php` would check a password as fast as it was asked to. Now it counts.
 *
 * TWO LIMITS, because one of them is always the wrong one on its own:
 *
 *   * **By identifier** -- five wrong tries at one account in fifteen minutes. This is the limit
 *     that stops a dictionary run at a known email address.
 *   * **By address** -- twenty wrong tries from one IP in fifteen minutes, whatever accounts they
 *     were aimed at. This is the limit that stops one password being sprayed across every account
 *     in the shop, which a per-identifier counter never sees.
 *
 * AN IDENTIFIER THAT MATCHES NOBODY IS THROTTLED TOO, and that is deliberate. If a real account
 * were throttled and an invented one were not, the form would answer "is bob@example.com registered
 * here" differently for the two, and the throttle itself would become an account-enumeration
 * oracle. Everything below keys on the string that was typed.
 *
 * **NOTHING IS EVER LOCKED.** There is no `users.locked_until` and a wrong password never disables
 * an account. A lockout would hand anybody who knows an email address the power to lock its owner
 * out -- the defence would become the attack. What is limited is the RATE, and it expires on its
 * own: the window slides, so waiting is always enough and no administrator has to unlock anybody.
 *
 * A SUCCESS CLEARS THE IDENTIFIER'S FAILURES. Somebody who mistypes their password four times and
 * then gets it right should not be one slip away from a wait for the next quarter of an hour.
 *
 * ---------------------------------------------------------------------------------------------
 * 2. SESSION BINDING
 * ---------------------------------------------------------------------------------------------
 *
 * A stolen `PHPSESSID` worked from anywhere. Now a signed-in session carries a fingerprint of the
 * browser it was issued to, checked on every request, and a mismatch ends it.
 *
 * **THE USER AGENT, AND DELIBERATELY NOT THE ADDRESS.** Binding to `REMOTE_ADDR` is the stronger
 * check and the wrong one here: a phone moves between cell towers and Wi-Fi constantly, each move
 * changing its address, and a customer filling a basket on the way home would be signed out
 * repeatedly for no reason they could understand. The security gained is small -- an attacker with
 * a stolen cookie is very often on the same network as the victim -- and the cost is a shop that
 * logs people out. The user agent changes only when the browser updates itself, which is rare and
 * recoverable by signing in again.
 *
 * Hashed rather than stored, because the raw string is fingerprintable personal data and the only
 * question ever asked of it is "is it the same one".
 */

/** Wrong tries at ONE identifier before it has to wait. The 2FA code uses 5 for the same reason. */
const LOGIN_MAX_PER_IDENTIFIER = 5;

/** Wrong tries from ONE address, across every account, before it has to wait. */
const LOGIN_MAX_PER_IP = 20;

/** How far back the counting looks, and therefore how long a wait lasts. */
const LOGIN_WINDOW_SECONDS = 900;

/**
 * The address this request came from, as the server saw it.
 *
 * No `X-Forwarded-For`. There is no reverse proxy in front of this install, so that header is
 * entirely attacker-controlled -- trusting it would let anybody defeat the per-address limit by
 * sending a different one on every attempt, which is worse than having no limit, because it would
 * look like a limit.
 */
function loginRequestIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return $ip === '' ? 'unknown' : mb_substr($ip, 0, 45);
}

/** The typed identifier, normalised so case and padding do not split one account's attempts. */
function loginIdentifierKey(string $identifier): string
{
    return mb_substr(mb_strtolower(trim($identifier)), 0, 255);
}

/**
 * Record one attempt.
 *
 * Also prunes anything older than the window, on the write rather than on a schedule: there is no
 * cron on this project, the table would otherwise grow forever, and the rows are personal data with
 * no use past the window. Doing it here means the prune happens exactly as often as the table is
 * used.
 *
 * Swallows a PDOException and says so in the log. That is the right way round for this one function:
 * a sign-in must not fail because the audit write did, and the throttle's own reads already treat a
 * broken table as "no attempts recorded" -- see loginThrottleState().
 */
function recordLoginAttempt(string $identifier, bool $succeeded): void
{
    try {
        $database = getDatabaseConnection();

        $statement = $database->prepare(
            'INSERT INTO login_attempts (identifier, ip_address, succeeded)
             VALUES (:identifier, :ip, :succeeded)'
        );
        $statement->bindValue('identifier', loginIdentifierKey($identifier));
        $statement->bindValue('ip', loginRequestIp());
        $statement->bindValue('succeeded', $succeeded ? 1 : 0, PDO::PARAM_INT);
        $statement->execute();

        $database->prepare(
            'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL :window SECOND)'
        )->execute(['window' => LOGIN_WINDOW_SECONDS]);
    } catch (PDOException $exception) {
        error_log('login-guard: could not record an attempt (has sql/login-attempts-upgrade.sql been run?) ' . $exception->getMessage());
    }
}

/**
 * Forget this identifier's failures, after a correct password.
 *
 * Scoped to failures only, so the successful attempt just recorded stays in the table as history.
 */
function clearLoginFailures(string $identifier): void
{
    try {
        getDatabaseConnection()->prepare(
            'DELETE FROM login_attempts WHERE identifier = :identifier AND succeeded = 0'
        )->execute(['identifier' => loginIdentifierKey($identifier)]);
    } catch (PDOException $exception) {
        error_log('login-guard: could not clear failures: ' . $exception->getMessage());
    }
}

/**
 * Whether this attempt may proceed, and if not, how long until it may.
 *
 * Returns ['allowed' => bool, 'retryAfter' => int seconds, 'reason' => 'identifier'|'ip'|''].
 *
 * **A MISSING TABLE ALLOWS THE SIGN-IN.** That is a decision rather than an oversight, and it is
 * the uncomfortable direction: if `sql/login-attempts-upgrade.sql` has not been run, this returns
 * `allowed` and logs loudly, rather than refusing everybody. Failing closed here would mean a
 * missing migration locks every customer and every admin out of the shop with no way back in
 * through the web -- a self-inflicted denial of service far more likely than the attack this
 * defends against. The log line is what makes it findable, and `settings-http-test.php`'s
 * equivalent case is handled the same way.
 *
 * `retryAfter` is computed from the OLDEST failure in the window, because that is the one whose
 * expiry brings the count back under the limit.
 */
function loginThrottleState(string $identifier): array
{
    $allow = ['allowed' => true, 'retryAfter' => 0, 'reason' => ''];

    try {
        $database = getDatabaseConnection();

        $statement = $database->prepare(
            'SELECT COUNT(*) AS failures,
                    COALESCE(TIMESTAMPDIFF(SECOND, MIN(attempted_at), NOW()), 0) AS oldest_age
               FROM login_attempts
              WHERE identifier = :identifier
                AND succeeded = 0
                AND attempted_at >= (NOW() - INTERVAL :window SECOND)'
        );
        $statement->bindValue('identifier', loginIdentifierKey($identifier));
        $statement->bindValue('window', LOGIN_WINDOW_SECONDS, PDO::PARAM_INT);
        $statement->execute();
        $byIdentifier = $statement->fetch();

        if ((int) $byIdentifier['failures'] >= LOGIN_MAX_PER_IDENTIFIER) {
            return [
                'allowed' => false,
                'retryAfter' => max(1, LOGIN_WINDOW_SECONDS - (int) $byIdentifier['oldest_age']),
                'reason' => 'identifier',
            ];
        }

        $statement = $database->prepare(
            'SELECT COUNT(*) AS failures,
                    COALESCE(TIMESTAMPDIFF(SECOND, MIN(attempted_at), NOW()), 0) AS oldest_age
               FROM login_attempts
              WHERE ip_address = :ip
                AND succeeded = 0
                AND attempted_at >= (NOW() - INTERVAL :window SECOND)'
        );
        $statement->bindValue('ip', loginRequestIp());
        $statement->bindValue('window', LOGIN_WINDOW_SECONDS, PDO::PARAM_INT);
        $statement->execute();
        $byIp = $statement->fetch();

        if ((int) $byIp['failures'] >= LOGIN_MAX_PER_IP) {
            return [
                'allowed' => false,
                'retryAfter' => max(1, LOGIN_WINDOW_SECONDS - (int) $byIp['oldest_age']),
                'reason' => 'ip',
            ];
        }

        return $allow;
    } catch (PDOException $exception) {
        error_log('login-guard: could not read attempts, allowing the sign-in: ' . $exception->getMessage());

        return $allow;
    }
}

/**
 * The refusal, as a sentence.
 *
 * **It says the same thing whether the limit was hit by identifier or by address**, and it never
 * says how many tries are left or whether the account exists. The `reason` is for the log, not for
 * the page: telling somebody "this ACCOUNT is locked" confirms the account is real, and telling
 * them "2 tries left" is a countdown for a script.
 */
function loginThrottleMessage(int $retryAfter): string
{
    $minutes = (int) ceil(max(1, $retryAfter) / 60);

    return $minutes <= 1
        ? 'Too many sign-in attempts. Wait a minute and try again.'
        : 'Too many sign-in attempts. Wait about ' . $minutes . ' minutes and try again.';
}

/* ------------------------------------------------------------------ *
 * Session binding.
 * ------------------------------------------------------------------ */

/**
 * A fingerprint of the browser making this request.
 *
 * sha256 of the User-Agent. A missing header hashes to a stable value of its own, which matters
 * more than it looks: several of this project's test suites send no User-Agent at all, and they send
 * none *consistently*, so they bind and verify against the same hash and are unaffected.
 */
function sessionFingerprint(): string
{
    return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/**
 * Check a signed-in session still belongs to the browser it was issued to, and end it if not.
 *
 * Called from ensureSession() on every request, immediately after the session starts.
 *
 * THREE CASES, and the middle one is the judgement call:
 *
 *   * No `user_id` -- a guest session. Nothing to protect and nothing to check; a guest's basket is
 *     identified by its own cookie and is not worth ending a session over.
 *   * `user_id` but no fingerprint -- a session that was signed in BEFORE this check existed. It is
 *     adopted rather than destroyed. Destroying it would sign out everybody who is currently
 *     signed in, to defend against a cookie stolen before the feature was written, which is a
 *     smaller threat than the disruption; and `signInUser()` already rotates the session id, so
 *     these age out on their own.
 *   * `user_id` and a fingerprint that does not match -- the session is destroyed. This is the case
 *     the whole thing exists for.
 *
 * Returns true when the session was ended, so the caller can redirect rather than render a page for
 * somebody who is no longer signed in.
 */
function sessionBindingIsBroken(): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    if (!isset($_SESSION['ua_hash'])) {
        $_SESSION['ua_hash'] = sessionFingerprint();

        return false;
    }

    // hash_equals rather than ===, for the same reason csrfTokenIsValid() uses it.
    if (hash_equals((string) $_SESSION['ua_hash'], sessionFingerprint())) {
        return false;
    }

    error_log('login-guard: session fingerprint mismatch, ending the session');

    // Everything goes, not just user_id: a half-emptied session would leave a basket and a flash
    // queue belonging to somebody whose identity has just been rejected.
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => (string) ($params['samesite'] ?? 'Lax'),
        ]);
    }

    session_destroy();

    return true;
}
