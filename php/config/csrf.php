<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/flash.php';

/**
 * The token that proves a POST came from this site's own form.
 *
 * THE HOLE THIS FILLS. A browser sends its cookies with every request to this site, whoever asked
 * for it. So a page on another site can hold a form aimed at php/cart-update.php, submit it with
 * script the moment it loads, and the request arrives carrying the visitor's session -- indis-
 * tinguishable, to every line of code here, from the visitor pressing the button themselves. The
 * attacking page cannot read the reply, which is no comfort at all: nothing in this project needs
 * to read a reply to do damage. Empty a basket, change the email an account is recovered through,
 * delete a product, mark an order delivered and email the customer about it.
 *
 * The defence is a secret the form has and the attacking page cannot obtain: it cannot read this
 * site's HTML (that is what the same-origin policy is for) and it cannot read this site's session.
 * So a POST that carries the right token came from a page this site served.
 *
 * ONE TOKEN PER SESSION, not one per form. Rotating per render is stronger on paper and worse in
 * practice here: it breaks the back button, breaks two tabs, and breaks the customizer page that
 * stays open for several minutes while a bouquet is built. None of that buys anything against the
 * threat above, which needs the value and cannot get it however long it lives.
 *
 * It is dropped when a session gains privileges -- see signInUser() in auth.php, which has always
 * regenerated the session id at that moment for the same reason.
 *
 * WHAT THIS IS NOT. It does not authorise anything. requireAdmin() decides who may act; this only
 * decides whether the visitor meant to. Both, always, and in that order.
 */

/** The field name in the form and the key in the session. Same string, one place to change it. */
const CSRF_FIELD = 'csrf_token';

/**
 * This session's token, minted on first use.
 *
 * 32 random bytes as hex. random_bytes() is the cryptographic source -- rand() and uniqid() are
 * predictable enough to guess from a handful of samples, which is the whole ballgame here.
 */
function csrfToken(): string
{
    ensureSession();

    if (!isset($_SESSION[CSRF_FIELD]) || !is_string($_SESSION[CSRF_FIELD])) {
        $_SESSION[CSRF_FIELD] = bin2hex(random_bytes(32));
    }

    return $_SESSION[CSRF_FIELD];
}

/**
 * Throw this session's token away, so the next form to need one gets a fresh secret.
 *
 * Called from signInUser() at the moment a session gains privileges, through function_exists() --
 * that file must not require this one (see the header of this file), and it is the same idiom it
 * already uses to reach mergeGuestCart() across the same gap.
 *
 * The argument is the one auth.php already makes for regenerating the session id in the same
 * breath: a value captured while the session was nobody in particular is worth nothing once the
 * session is somebody. The cost is one refusal in an unusual case -- a guest with the basket open
 * in a second tab signs in in the first, and the stale tab's next press says the form had expired.
 * Reloading fixes it, and the alternative is carrying a pre-authentication secret into an
 * authenticated session.
 */
function csrfForgetToken(): void
{
    ensureSession();

    unset($_SESSION[CSRF_FIELD]);
}

/**
 * The hidden input, ready to echo inside a <form method="post">.
 *
 * Returned rather than printed so it reads like every other escaped value on a page. Every form in
 * this project gets one as its first child; a form without it will be refused, which is the point.
 */
function csrfField(): string
{
    return '<input type="hidden" name="' . CSRF_FIELD . '" value="' . escape(csrfToken()) . '">';
}

/**
 * Did this POST carry the session's token?
 *
 * Deliberately does not mint one. A refusal should not leave state behind, and a request with no
 * session to compare against is a refusal -- minting here would compare a fresh secret against the
 * posted value, reaching the same answer by a more confusing route.
 *
 * hash_equals() rather than === because a plain comparison stops at the first differing byte, and
 * the time that takes is a measurement an attacker can repeat to learn the token one character at
 * a time. That attack is a stretch over a LAN and free to prevent.
 */
function csrfTokenIsValid(): bool
{
    ensureSession();

    $expected = $_SESSION[CSRF_FIELD] ?? null;
    $posted = $_POST[CSRF_FIELD] ?? null;

    if (!is_string($expected) || !is_string($posted)) {
        return false;
    }

    return hash_equals($expected, $posted);
}

/**
 * Stop here unless this POST came from one of our own forms. For a page that renders its own reply.
 *
 * Modelled on requireAdmin(): 403 and a deliberately plain page that reaches for no header, no
 * navbar, no stylesheet and no database, so it still renders when whatever it is guarding is
 * broken. Same reasoning, same shape, one difference -- this one suggests going back, because the
 * overwhelmingly likely cause is not an attack but a form left open until the session expired.
 *
 * Call it as the handler's first statement, with one exception: on the two upload screens it goes
 * after the "was the whole POST discarded for being too big" branch. A POST over post_max_size
 * arrives with $_POST empty, so checking the token first would answer a 60 MB photo with "that
 * form had expired", which is both wrong and unfixable by the person reading it.
 */
function requireCsrfToken(): void
{
    if (csrfTokenIsValid()) {
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
        <link rel="icon" href="<?php echo escape(asset('images/favicon.png')); ?>" type="image/png">
        <title>That form had expired</title>
    </head>
    <body>
        <h1>That form had expired</h1>
        <p>Nothing has been changed. Go back, reload the page and try again.</p>
        <p><a href="<?php echo escape(url('php/index.php')); ?>">Back to the site</a></p>
    </body>
    </html>
    <?php
    exit;
}

/**
 * The same check for an endpoint that only ever answers with a redirect.
 *
 * php/cart-update.php, php/design-save.php, php/order-cancel.php and php/logout.php never render
 * anything: every path through them ends in redirectTo(), and every refusal they already have is
 * reported as a toast on the page the visitor lands on. Printing a 403 page from one of them would
 * make this single failure the only one that behaves differently, and would strand a visitor on a
 * URL that has nothing to show.
 *
 * $destination is where they end up -- the page they were on, chosen by the caller, which already
 * works out that address for its other refusals.
 */
function requireCsrfTokenOrRedirect(string $destination): void
{
    if (csrfTokenIsValid()) {
        return;
    }

    flashToast('error', 'That form had expired, so nothing was changed. Try again.');
    redirectTo($destination);
}
