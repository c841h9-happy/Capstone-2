<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/paths.php';

/**
 * The two kinds of account, matching the ENUM in the users.role column.
 *
 * Named rather than typed out at each use: a misspelt 'admin' in a comparison
 * fails open or closed silently, while a misspelt constant is a fatal error the
 * first time the line runs.
 */
const ROLE_CUSTOMER = 'customer';
const ROLE_ADMIN = 'admin';


function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Start the session if it is not running yet.
 *
 * Call this at the very top of a page, before any HTML is echoed -- starting a
 * session sends a Set-Cookie header, which fails once output has begun.
 *
 * SAMESITE AND HTTPONLY ARE SET HERE, as of 2026-10-11.
 *
 * Until then `session.cookie_samesite` was empty, so `PHPSESSID` carried no SameSite attribute at
 * all -- while `php/config/cart.php` and `php/config/devices.php` both set `'samesite' => 'Lax'` on
 * their own cookies explicitly. That inconsistency was recorded under OPEN ISSUES on 2026-09-24 and
 * left alone deliberately, as a two-line change nobody wanted to make on a demo day. This is that
 * change.
 *
 * `Lax` and not `Strict`: Strict withholds the cookie on any cross-site navigation, including
 * somebody arriving from a link in the order email this shop sends -- they would land signed out
 * and have to sign in again to read their own order. Lax sends it on a top-level GET, which is
 * exactly that case, and withholds it on a cross-site POST, which is the attack.
 *
 * AN HONEST NOTE ON WHAT THIS BUYS. Less than it sounds, and the CSRF token is still the thing
 * doing the work. SameSite is about *site*, not origin, and a port is not part of a site -- so a
 * page served on `localhost:8099` is same-site to `localhost:80` and `Lax` cookies are sent to it
 * regardless. That is why `php/tools/csrf-browser-test.mjs` serves its attacker page on another
 * port: the cookies really are sent, and the token really is what refuses the request. This makes
 * the session cookie consistent with the other two and removes a gap worth closing, not a
 * substitute for the token.
 *
 * Only the keys given are overridden; `lifetime`, `path`, `domain` and `secure` keep their php.ini
 * values. `secure` is deliberately not forced: there is no HTTPS on this install, and a cookie
 * marked secure over plain HTTP is a cookie the browser never sends, which would lock everybody
 * out. It belongs with the HTTPS work, which is its own job -- see OPEN ISSUES.
 */
function ensureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Before session_start(), necessarily: these describe the cookie that call sends.
        session_set_cookie_params([
            'samesite' => 'Lax',
            // Nothing in this project reads PHPSESSID from JavaScript, so there is no reason for
            // document.cookie to be able to.
            'httponly' => true,
        ]);

        session_start();

        /*
         * Is this still the browser the session was issued to?
         *
         * Checked here because ensureSession() is the one call every page makes, and checked
         * immediately after the start so no code can read $_SESSION['user_id'] before the session
         * has been judged. A mismatch destroys the session, so what follows sees a signed-out
         * visitor and the page renders for one -- which is the correct outcome and needs no
         * redirect from here. See sessionBindingIsBroken() in config/login-guard.php for why the
         * fingerprint is the user agent and deliberately not the address.
         *
         * Required at call time rather than at the top of this file: login-guard.php requires
         * database.php, and auth.php is loaded by pages that have no database to talk to. The
         * require_once is free after the first.
         */
        require_once __DIR__ . '/login-guard.php';
        sessionBindingIsBroken();
    }
}

function isUserLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Grant the session to an account. The only place that does.
 *
 * $user is a row from `users` carrying at least id, name, email and role.
 *
 * Two paths sign somebody in -- the trusted-device shortcut in php/login.php and
 * the code page in php/verify.php -- and they used to each set the session keys
 * themselves. That was survivable while there were three keys and no privileges
 * attached to them. It is not survivable now: forgetting user_role in one of the
 * two branches would produce a signed-in admin whose session says otherwise, and
 * the bug would only show up on whichever path is used less.
 *
 * session_regenerate_id() is here for the same reason it was in both callers --
 * the session id changes at the moment the session gains privileges, so an id
 * captured beforehand is worth nothing afterwards.
 *
 * The basket merge is here for a related reason. A guest basket is found by a cookie, and this
 * is the moment that browser stops being a guest -- so this is the moment its lines have to move
 * onto the account, before anything reads the basket as the signed-in user and finds it empty.
 * mergeGuestCart() never throws: a basket problem must not stop somebody signing in.
 *
 * It is called through function_exists() rather than by requiring config/cart.php, and that is
 * deliberate. cart.php requires this file -- it needs isUserLoggedIn() and escape() -- so a
 * require back would be a cycle. require_once would not loop forever, but it would leave this
 * function undefined at the moment signInUser() runs on whichever file happened to load first,
 * which is a fatal error on the sign-in path and the worst place in the project to put one.
 * None of the three callers loads cart.php today, so without the guard every sign-in would break.
 * The basket is an optional extra on top of signing in; this says so in code.
 *
 * The CSRF token is dropped here, through the same guard and across the same gap: config/csrf.php
 * requires this file, so a require back would be the same cycle. It goes for the same reason the
 * session id is regenerated one line above -- a token captured while this session belonged to
 * nobody in particular is worth nothing now that it belongs to somebody. Both files that call this
 * function load csrf.php, since both of them render forms.
 */
function signInUser(array $user): void
{
    session_regenerate_id(true);

    if (function_exists('csrfForgetToken')) {
        csrfForgetToken();
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['user_role'] = normaliseRole($user['role'] ?? null);

    /*
     * Bind the session to this browser, beside the id regeneration and the token drop and for the
     * same reason as both: this is the moment the session gains privileges, so this is the moment to
     * record what it was granted to. Every later request compares against this -- see
     * sessionBindingIsBroken() in config/login-guard.php.
     *
     * Required at call time, like login-guard's other entry point, because that file needs
     * database.php and auth.php is loaded by pages that have none.
     */
    require_once __DIR__ . '/login-guard.php';
    $_SESSION['ua_hash'] = sessionFingerprint();

    if (function_exists('mergeGuestCart')) {
        mergeGuestCart((int) $user['id']);
    }
}

/**
 * Reduce anything to one of the two known roles.
 *
 * Only an exact 'admin' is an admin. A null, a missing column, a value from a
 * database that has not had sql/roles-upgrade.sql run against it -- all land on
 * customer, because the failure has to be the one that grants nothing.
 */
function normaliseRole(mixed $role): string
{
    return $role === ROLE_ADMIN ? ROLE_ADMIN : ROLE_CUSTOMER;
}

/**
 * The signed-in account's role, or customer when nobody is signed in.
 *
 * Reads the session rather than the database, so a role change takes effect at
 * the next sign-in and not mid-session. That is the intended behaviour: it keeps
 * every page from running an extra query, and demoting somebody who is already
 * signed in is a "sign out everywhere" feature, not this one.
 */
function currentUserRole(): string
{
    return normaliseRole($_SESSION['user_role'] ?? null);
}

function isAdmin(): bool
{
    return isUserLoggedIn() && currentUserRole() === ROLE_ADMIN;
}

/**
 * Where a role belongs after signing in.
 */
function homeUrlForRole(string $role): string
{
    return normaliseRole($role) === ROLE_ADMIN
        ? url('php/admin/dashboard.php')
        : url('php/index.php');
}

/**
 * Stop here unless somebody is signed in.
 *
 * Call it before any output -- it can redirect, which needs the headers unsent.
 */
function requireLogin(): void
{
    if (!isUserLoggedIn()) {
        redirectTo(url('php/login.php'));
    }
}

/**
 * Stop here unless an admin is signed in.
 *
 * A visitor who is not signed in gets the sign-in page, because that is a step
 * they can act on. Somebody already signed in as a customer gets 403 rather than
 * a redirect: a redirect makes an authorisation failure look identical to a
 * working link, which during development turns a missing role into a phantom
 * routing bug. The page is deliberately plain -- it reaches for no header, no
 * navbar, no stylesheet and no database, so it still renders when whatever it is
 * guarding is broken. That is a rule about dependencies that can fail, not a vow
 * of poverty: it carries a viewport meta and a favicon, both of which resolve
 * from paths.php, which is already loaded before this file can be called.
 */
function requireAdmin(): void
{
    requireLogin();

    if (isAdmin()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <?php
        /*
         * The icon and the viewport, added 2026-09-21. "Plain" above is about what this page does
         * not reach for -- no header, no navbar, no database, no query -- not about going without
         * a viewport meta, which is why this was the one page in the site that rendered zoomed out
         * on a phone. asset() is the same paths.php this function already calls url() and escape()
         * from, and it guards its own filemtime() with is_file(), so a deleted icon costs this page
         * a cache-buster and nothing else. The claim above still holds.
         */
        ?>
        <link rel="icon" href="<?php echo escape(asset('images/favicon.png')); ?>" type="image/png">
        <title>Not allowed</title>
    </head>
    <body>
        <h1>Not allowed</h1>
        <p>This page is for administrators. You are signed in as a customer.</p>
        <p><a href="<?php echo escape(url('php/index.php')); ?>">Back to the site</a></p>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Check a new password against the strength rules and return what it is missing.
 *
 * An empty array means the password passed. This runs when a password is chosen
 * (sign-up), never at sign-in: a returning user cannot fix a password that no
 * longer meets the rules from the sign-in form, so rejecting it there would only
 * lock them out of their own account.
 *
 * The rules are duplicated in js/script.js for the live checklist. That copy is
 * only a hint -- this one decides, since anything in the browser can be skipped.
 */
function passwordStrengthErrors(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    // password_hash() uses bcrypt, which silently ignores everything past 72
    // bytes. Reject the overflow instead of storing a password whose tail never
    // gets checked. strlen() counts bytes, which is exactly the limit bcrypt has.
    if (strlen($password) > 72) {
        $errors[] = 'Password must be 72 characters or fewer.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must include a lowercase letter.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must include a capital letter.';
    }

    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must include a number.';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must include a symbol, such as ! ? @ or #.';
    }

    return $errors;
}

function redirectTo(string $location): never
{
    header('Location: ' . $location);
    exit;
}
