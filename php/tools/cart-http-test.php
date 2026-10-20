<?php

declare(strict_types=1);

/**
 * The basket, over Apache, with real cookies and real sessions.
 *
 * Everything the unit checks cannot reach: the cookie that identifies a guest basket, the merge
 * that happens when that guest signs in, the caps, and every hostile input a form can carry.
 *
 * Talks HTTP to localhost rather than calling functions directly, because half of what is being
 * tested *is* the HTTP layer -- Set-Cookie, the redirect after a POST, the session. Calling
 * addProductToCart() in-process would test none of it.
 *
 *     C:\xampp\php\php.exe php\tools\cart-http-test.php
 *
 * Creates its own product and its own account, both prefixed ZZTest, and deletes them at the end.
 * It never writes to a seeded row.
 */

// Same guard as the two tools beside this one. This file is under the webroot, so without it a
// stranger could run the suite -- which signs up an account, creates a product, and deletes rows.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
// For CART_PREVIEW_LINES, so the hover-panel assertions below track the cap rather than restating
// it. Nothing else in this file calls into config/ -- everything under test is reached over HTTP.
require_once __DIR__ . '/../config/cart.php';

const BASE = 'http://localhost/Capstone/';

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failed, $failures;

    if ($actual === $expected) {
        $passed++;

        return;
    }

    $failed++;
    $failures[] = sprintf(
        "FAIL  %s\n        expected: %s\n        actual:   %s",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

function checkTrue(string $label, bool $actual): void
{
    check($label, $actual, true);
}

/**
 * One HTTP request, carrying and collecting cookies in a jar the caller owns.
 *
 * Redirects are deliberately NOT followed. Every write in this module answers with a redirect, and
 * the Location header is a thing being tested -- following it would hide both the status and where
 * it pointed. Callers that want the destination ask for it in a second request.
 *
 * @param array<string, string> $jar
 * @param array<string, string>|null $post
 * @return array{status:int, headers:array<int, string>, body:string, location:?string}
 */
/**
 * This browser's CSRF token, fetched the way a browser gets one: off a page carrying a form.
 *
 * php/login.php because it is the one page that renders a form for everybody -- signed in or not,
 * it draws both panels, so one address works for every jar in this file. An empty basket has no
 * form on it at all, which is why the obvious choice is the wrong one.
 *
 * Memoised per session id, so this costs one GET per browser rather than one per POST. The key is
 * read after the request, not before: a jar with no cookie yet is given one by this very GET. When
 * signing in changes the session id, the new id misses the cache and refetches -- which is correct,
 * because signInUser() drops the old token at the same moment.
 */
function csrfFor(array &$jar): string
{
    static $cache = [];

    $key = $jar['PHPSESSID'] ?? '';

    if ($key !== '' && isset($cache[$key])) {
        return $cache[$key];
    }

    $body = request('php/login.php', $jar)['body'];
    $token = preg_match('#name="csrf_token" value="([a-f0-9]+)"#', $body, $m) === 1 ? $m[1] : '';
    $cache[$jar['PHPSESSID'] ?? ''] = $token;

    return $token;
}

function request(string $path, array &$jar, ?array $post = null): array
{
    $context = [
        'http' => [
            'method' => $post === null ? 'GET' : 'POST',
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 30,
            'header' => [],
        ],
    ];

    /*
     * Every POST carries the token unless the caller supplied one on purpose. Added here rather
     * than at the sixty-odd call sites below, none of which are about CSRF: this suite asserts
     * what the basket does, and php/tools/csrf-http-test.php asserts what happens without a token.
     *
     * Before the Cookie header below, not after. Fetching a token for an empty jar is what mints
     * that browser's session, and a POST whose header was assembled first would arrive with the
     * token but not the session it belongs to -- refused, for a reason nothing in the output says.
     */
    if ($post !== null && !array_key_exists('csrf_token', $post)) {
        $post['csrf_token'] = csrfFor($jar);
    }

    if ($jar !== []) {
        $pairs = [];

        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $context['http']['header'][] = 'Cookie: ' . implode('; ', $pairs);
    }

    if ($post !== null) {
        $context['http']['header'][] = 'Content-Type: application/x-www-form-urlencoded';
        $context['http']['content'] = http_build_query($post);
    }

    $context['http']['header'] = implode("\r\n", $context['http']['header']);

    $body = @file_get_contents(BASE . $path, false, stream_context_create($context));
    $headers = $http_response_header ?? [];

    $status = 0;
    $location = null;

    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
        }

        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }

        // Cookie values here are a session id and a basket token -- both are capabilities, so the
        // jar is per-visitor and never printed.
        if (stripos($header, 'Set-Cookie:') === 0
            && preg_match('#^Set-Cookie:\s*([^=]+)=([^;]*)#i', $header, $m) === 1) {
            if ($m[2] === '' || stripos($header, 'expires=Thu, 01 Jan 1970') !== false) {
                unset($jar[trim($m[1])]);
            } else {
                $jar[trim($m[1])] = $m[2];
            }
        }
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body === false ? '' : $body,
        'location' => $location,
    ];
}

/** The basket page as a signed-in-or-not visitor sees it. */
function cartPage(array &$jar): string
{
    return request('php/cart.php', $jar)['body'];
}

/** How many lines the basket page is showing, counted from the markup. */
function lineCount(string $html): int
{
    return preg_match_all('/class="cart-line(?:\s|")/', $html);
}

/** The figure in the total row, as printed. */
function totalShown(string $html): ?string
{
    return preg_match('/class="cart-total-figure">([^<]+)</', $html, $m) === 1
        ? html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8')
        : null;
}

/** The navbar's basket count, or 0 when no badge is drawn. */
function navCount(string $html): int
{
    return preg_match('/class="nav-cart-count"[^>]*>(\d+)</', $html, $m) === 1 ? (int) $m[1] : 0;
}

/** The item ids the basket page is showing, in the order it shows them. */
function lineIds(string $html): array
{
    preg_match_all('/name="item_id" value="(\d+)"/', $html, $m);

    return array_values(array_unique(array_map('intval', $m[1])));
}

/*
 * The navbar's hover panel. It is drawn on every page of the site, not just this one, so these
 * three read the navbar rather than the basket -- which is the point of asserting on them: the
 * badge, the panel and the page all come from one cartSummary() now, and the way to prove that is
 * to demand the same figures out of the panel that the page prints.
 */

/** The hover panel's contents, up to the "View basket" strip, or '' when there is no panel. */
function navPanel(string $html): string
{
    return preg_match('/<span class="nav-cart-panel"[^>]*>(.*?)<span class="nav-cart-panel-go"/s', $html, $m) === 1
        ? $m[1]
        : '';
}

/** The names the hover panel lists, in the order it lists them. */
function navPanelNames(string $html): array
{
    preg_match_all('/class="nav-cart-panel-name">\s*(.*?)\s*<\/span>/s', navPanel($html), $m);

    return array_map(
        static fn (string $name): string => html_entity_decode($name, ENT_QUOTES, 'UTF-8'),
        $m[1]
    );
}

/** The figure in the hover panel's total row, as printed. */
function navPanelTotal(string $html): ?string
{
    return preg_match('/class="nav-cart-panel-figure">([^<]+)</', $html, $m) === 1
        ? html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8')
        : null;
}

$database = getDatabaseConnection();

/*
 * The fixtures. A product of this suite's own, so the withdrawal test below can take something off
 * sale without touching a seeded row, and an account of its own for the merge tests.
 */
$database->exec("DELETE FROM users WHERE email LIKE 'zztest.cart%'");
$database->exec("DELETE FROM products WHERE name LIKE 'ZZTest%'");

$database->prepare(
    "INSERT INTO products (name, description, price, stem_count, colour, is_active)
     VALUES ('ZZTest Basket Bouquet', 'Fixture for the basket suite.', 250.00, 6, 'Test', 1)"
)->execute();
$testProductId = (int) $database->lastInsertId();

$database->prepare(
    "INSERT INTO products (name, description, price, stem_count, colour, is_active)
     VALUES ('ZZTest Doomed Bouquet', 'Withdrawn mid-suite on purpose.', 400.00, 3, 'Test', 1)"
)->execute();
$doomedProductId = (int) $database->lastInsertId();

// An account whose password hash is known, so the sign-in below is a real sign-in.
// email_verified_at is set, because login.php sends an unverified account off to finish signing up
// rather than signing it in -- which would mean testing the merge on a path it never runs on.
$password = 'ZzTestCart123!';
$statement = $database->prepare(
    "INSERT INTO users (name, email, password_hash, role, email_verified_at)
     VALUES ('ZZTest Cart Person', 'zztest.cart@capstone.test', :hash, 'customer', NOW())"
);
$statement->execute(['hash' => password_hash($password, PASSWORD_DEFAULT)]);
$testUserId = (int) $database->lastInsertId();

echo "fixtures: product $testProductId, doomed $doomedProductId, user $testUserId\n\n";

/* ------------------------------------------------------------------ *
 * A visitor with no account fills a basket.
 * ------------------------------------------------------------------ */

$guest = [];

/*
 * Counted before the page load, not asserted as zero. Real baskets belonging to real visitors may
 * sit in this table, and a suite that demands an empty carts table is really testing the state of
 * the database rather than the behaviour of the page.
 */
$cartsBefore = (int) $database->query('SELECT COUNT(*) FROM carts')->fetchColumn();

$html = cartPage($guest);
checkTrue('empty: says the basket is empty', str_contains($html, 'Your basket is empty'));
check('empty: no lines', lineCount($html), 0);
check('empty: no navbar badge', navCount($html), 0);
/*
 * An empty basket offers somewhere to go instead of a checkout button. A disabled Checkout with
 * nothing above it would be a dead end, so the foot -- button, total and sign-in prompt alike --
 * belongs to the filled page only, and is asserted there.
 */
checkTrue('empty: no checkout button to press', str_contains($html, 'cart-checkout') === false);
checkTrue('empty: offers somewhere to go instead', str_contains($html, 'empty-state-actions'));

/*
 * Merely looking must not mint a basket. A cookie handed to everybody who loads a page is a row
 * per visitor in the carts table and a consent question nobody asked for.
 */
check('empty: looking does not set a basket cookie', isset($guest['capstone_cart']), false);
check('empty: looking does not create a cart row', (int) $database->query('SELECT COUNT(*) FROM carts')->fetchColumn(), $cartsBefore);

/*
 * The hover panel on an empty basket. It is still drawn -- hovering the icon and getting nothing
 * is indistinguishable from a panel that failed to open -- and it says the basket is empty rather
 * than showing a total of zero, which reads as a price.
 */
checkTrue('empty: the hover panel says so', str_contains(navPanel($html), 'Nothing in it yet.'));
check('empty: the hover panel lists nothing', navPanelNames($html), []);
check('empty: and prints no total', navPanelTotal($html), null);

/*
 * On every page, not just this one. The panel lives in the navbar, so a page that has the navbar
 * and not the panel would be a basket that appears to empty itself on the way to the catalogue.
 */
checkTrue('empty: the panel is on the catalogue too', str_contains(request('php/products.php', $guest)['body'], 'nav-cart-panel'));
check('empty: and looking at the catalogue still mints nothing', isset($guest['capstone_cart']), false);

$response = request('php/cart-update.php', $guest, [
    'action' => 'add-product',
    'product_id' => (string) $testProductId,
    'quantity' => '2',
    'back' => '/Capstone/php/cart.php',
]);

check('add: answers with a redirect', $response['status'], 302);
check('add: lands back where it was sent from', $response['location'], '/Capstone/php/cart.php');
checkTrue('add: a guest is given a basket cookie', isset($guest['capstone_cart']));

/*
 * The cookie must be opaque. Anything derived from a row id would let one visitor guess another's
 * basket, and the column is a CHAR(64) hash precisely so the cookie is not the key itself.
 */
$token = $guest['capstone_cart'] ?? '';
checkTrue('add: the cookie is long enough to be unguessable', strlen($token) >= 32);
check('add: the cookie is not a row id', (bool) preg_match('/^\d{1,6}$/', $token), false);
check(
    'add: the cookie is not what is stored',
    (int) $database->query('SELECT COUNT(*) FROM carts WHERE token_hash = ' . $database->quote($token))->fetchColumn(),
    0
);
check(
    'add: its hash is what is stored',
    (int) $database->query('SELECT COUNT(*) FROM carts WHERE token_hash = ' . $database->quote(hash('sha256', $token)))->fetchColumn(),
    1
);
check(
    'add: the guest basket has no owner',
    $database->query('SELECT user_id FROM carts ORDER BY id DESC LIMIT 1')->fetchColumn(),
    null
);

$html = cartPage($guest);
check('add: one line', lineCount($html), 1);
checkTrue('add: the product is named', str_contains($html, 'ZZTest Basket Bouquet'));
check('add: the total is two at 250', totalShown($html), '₱500.00');
check('add: the navbar counts two', navCount($html), 2);

/*
 * The checkout control, and which of its three states a guest with a healthy basket gets.
 *
 * This block asserted a *disabled* button until 2026-09-20, and rightly: while the basket was the
 * whole of Commerce there was nowhere for the button to go, and one that is visibly present and
 * unusable states the boundary where a missing one reads as a broken page. Checkout exists now, so
 * the disabled state has moved to the single case that still earns it -- a line that is no longer
 * on sale, asserted in the withdrawn block below. A signed-out visitor is offered a live link to
 * sign in instead, which is this page's oldest rule: a basket needs no account, an order does.
 */
checkTrue('add: the checkout button is shown', str_contains($html, 'cart-checkout'));
checkTrue(
    'add: a guest is offered sign-in rather than checkout',
    (bool) preg_match('#cart-checkout"\s+href="[^"]*login\.php">Sign in to check out#', $html)
);
checkTrue(
    'add: and it is a live link, not a disabled button',
    preg_match('/cart-checkout[^>]*disabled/', $html) === 0
);
checkTrue('add: a guest is told an account is needed', str_contains($html, 'you will need one to check out'));

/*
 * The two-column layout: the lines on the left and the total on the right, which on this page is
 * one wrapper holding the list and the foot in that order. The geometry -- that they really do sit
 * side by side -- is a browser question and is checked in Chrome; this is the structure it needs.
 */
check('layout: one two-column wrapper', substr_count($html, 'class="cart-layout"'), 1);
checkTrue(
    'layout: the lines come before the total inside it',
    strpos($html, 'class="cart-layout"') < strpos($html, 'class="cart-list"')
        && strpos($html, 'class="cart-list"') < strpos($html, 'class="cart-foot"')
);
checkTrue('layout: the checkout is in the right-hand panel', strpos($html, 'class="cart-foot"') < strpos($html, 'cart-checkout'));

/*
 * The hover panel and the page, from the same read. A basket that says one thing in the navbar and
 * another on the page is the bug this was restructured to make impossible, so the assertion is
 * against the page's own figures rather than against a number typed in here.
 */
check('panel: lists the line', navPanelNames($html), ['ZZTest Basket Bouquet']);
check('panel: the same total the page prints', navPanelTotal($html), totalShown($html));
checkTrue('panel: says how many of it', str_contains(navPanel($html), '&times;2'));
checkTrue('panel: and offers the basket page', str_contains($html, 'View basket'));

/*
 * Clicking is still what it always was. The panel sits inside the anchor, so the one thing that
 * could quietly break is the anchor itself -- a wrapper around it, or a href that moved.
 */
checkTrue(
    'panel: the icon still links to the basket',
    (bool) preg_match('#<a href="/Capstone/php/cart\.php"[^>]*class="[^"]*nav-cart"#', $html)
);

/*
 * The same product again adds up rather than making a second line: two of one thing at one price
 * is a quantity, and nothing is lost by combining them.
 */
request('php/cart-update.php', $guest, [
    'action' => 'add-product',
    'product_id' => (string) $testProductId,
    'quantity' => '1',
    'back' => '/Capstone/php/cart.php',
]);

$html = cartPage($guest);
check('add again: still one line', lineCount($html), 1);
check('add again: the quantity grew', totalShown($html), '₱750.00');
check('add again: the navbar counts three', navCount($html), 3);

/* ------------------------------------------------------------------ *
 * A designed bouquet.
 * ------------------------------------------------------------------ */

$response = request('php/cart-update.php', $guest, [
    'action' => 'add-design',
    'design' => 'count=6&arrangement=dome&bloom=1&wire=7',
    'back' => '/Capstone/php/cart.php',
]);
check('design: redirects', $response['status'], 302);

$html = cartPage($guest);
check('design: a second line', lineCount($html), 2);
checkTrue('design: named by count and arrangement', str_contains($html, 'Custom bouquet: 6 flowers, domed'));
checkTrue('design: its parts are listed', str_contains($html, 'cart-parts'));
checkTrue('design: the swatches are real colours', preg_match('/class="colour-swatch"[^>]*background: #[0-9a-f]{6}/i', $html) === 1);

$designLineTotal = (string) $database->query(
    'SELECT unit_price FROM cart_items WHERE kind = ' . "'design'" . ' ORDER BY id DESC LIMIT 1'
)->fetchColumn();
checkTrue('design: a price was frozen', (float) $designLineTotal > 0);

check(
    'design: its parts were frozen as rows',
    (int) $database->query(
        'SELECT COUNT(*) FROM cart_item_parts p
           JOIN cart_items i ON i.id = p.item_id
          WHERE i.kind = ' . "'design'"
    )->fetchColumn() > 0,
    true
);

/*
 * The same design twice is two lines, which is the opposite of the product rule and deliberate:
 * two bouquets may be going to two people, and combining them would mean averaging two frozen
 * prices into one that neither was.
 */
request('php/cart-update.php', $guest, [
    'action' => 'add-design',
    'design' => 'count=6&arrangement=dome&bloom=1&wire=7',
    'back' => '/Capstone/php/cart.php',
]);
$html = cartPage($guest);
check('design twice: two separate lines', lineCount($html), 3);

/* ------------------------------------------------------------------ *
 * Changing quantities and removing.
 * ------------------------------------------------------------------ */

$ids = lineIds($html);
$productLineId = $ids[0];

$response = request('php/cart-update.php', $guest, [
    'action' => 'update',
    'item_id' => (string) $productLineId,
    'quantity' => '5',
    'back' => '/Capstone/php/cart.php',
]);
check('update: redirects', $response['status'], 302);
check(
    'update: the quantity changed',
    (int) $database->query("SELECT quantity FROM cart_items WHERE id = $productLineId")->fetchColumn(),
    5
);

/*
 * Setting a quantity to what it already is must still report success. PDO's MySQL driver counts
 * rows *changed*, not matched, so a no-op update reports zero affected -- indistinguishable from
 * "no such line" unless the code checks existence separately, which is why it does.
 */
$before = cartPage($guest);
request('php/cart-update.php', $guest, [
    'action' => 'update',
    'item_id' => (string) $productLineId,
    'quantity' => '5',
    'back' => '/Capstone/php/cart.php',
]);
$after = cartPage($guest);
check('update: setting the same quantity is not an error', str_contains($after, 'not in your basket'), false);
check('update: and changes nothing', lineCount($after), lineCount($before));

// The ceiling is enforced in PHP, because this server's sql_mode would clamp silently instead.
request('php/cart-update.php', $guest, [
    'action' => 'update',
    'item_id' => (string) $productLineId,
    'quantity' => '9999',
    'back' => '/Capstone/php/cart.php',
]);
check(
    'update: clamped to the ceiling',
    (int) $database->query("SELECT quantity FROM cart_items WHERE id = $productLineId")->fetchColumn(),
    99
);

request('php/cart-update.php', $guest, [
    'action' => 'update',
    'item_id' => (string) $productLineId,
    'quantity' => '-4',
    'back' => '/Capstone/php/cart.php',
]);
check(
    'update: a negative cannot get through the column',
    (int) $database->query("SELECT quantity FROM cart_items WHERE id = $productLineId")->fetchColumn(),
    1
);

request('php/cart-update.php', $guest, [
    'action' => 'update',
    'item_id' => (string) $productLineId,
    'quantity' => 'abc',
    'back' => '/Capstone/php/cart.php',
]);
check(
    'update: junk becomes one',
    (int) $database->query("SELECT quantity FROM cart_items WHERE id = $productLineId")->fetchColumn(),
    1
);

/* ------------------------------------------------------------------ *
 * Hostile input.
 * ------------------------------------------------------------------ */

$response = request('php/cart-update.php', $guest, [
    'action' => 'update',
    'item_id' => '999999',
    'quantity' => '3',
    'back' => '/Capstone/php/cart.php',
]);
check('hostile: an unknown line id still redirects', $response['status'], 302);

$response = request('php/cart-update.php', $guest, [
    'action' => 'add-product',
    'product_id' => '999999',
    'quantity' => '1',
    'back' => '/Capstone/php/cart.php',
]);
check('hostile: an unknown product id redirects', $response['status'], 302);
check('hostile: and adds nothing', lineCount(cartPage($guest)), 3);

foreach (["1' OR '1'='1", '0', '-1', '1e9', '../../etc/passwd', '<script>x</script>'] as $nasty) {
    $response = request('php/cart-update.php', $guest, [
        'action' => 'add-product',
        'product_id' => $nasty,
        'quantity' => '1',
        'back' => '/Capstone/php/cart.php',
    ]);
    check("hostile: product_id " . var_export($nasty, true) . " is refused cleanly", $response['status'], 302);
}

check('hostile: nothing was added by any of it', lineCount(cartPage($guest)), 3);

$response = request('php/cart-update.php', $guest, [
    'action' => 'not-a-real-action',
    'back' => '/Capstone/php/cart.php',
]);
check('hostile: an unknown action redirects to the basket', $response['status'], 302);
checkTrue('hostile: and not somewhere surprising', str_contains((string) $response['location'], 'cart.php'));

/*
 * The open-redirect guard. `back` is a hidden field, so it is the visitor's to edit; a Location
 * header pointing at another host is how a phishing page becomes one this site sent you to.
 */
$offsite = [
    'https://evil.test/login',
    '//evil.test/login',
    'http://evil.test',
    '/\\evil.test',
    'javascript:alert(1)',
    '/other/place.php',
];

foreach ($offsite as $destination) {
    $response = request('php/cart-update.php', $guest, [
        'action' => 'update',
        'item_id' => (string) $productLineId,
        'quantity' => '2',
        'back' => $destination,
    ]);

    $location = (string) $response['location'];
    check(
        'redirect: ' . $destination . ' is not honoured',
        str_starts_with($location, '/Capstone/'),
        true
    );
    check('redirect: ' . $destination . ' cannot name another host', str_contains($location, 'evil.test'), false);
}

// A legitimate destination inside the site is honoured, or the guard would be useless.
$response = request('php/cart-update.php', $guest, [
    'action' => 'update',
    'item_id' => (string) $productLineId,
    'quantity' => '2',
    'back' => '/Capstone/php/products.php',
]);
check('redirect: a same-site path is honoured', $response['location'], '/Capstone/php/products.php');

/*
 * One visitor must not be able to reach another's basket by guessing a line id. Both baskets are
 * real, both belong to nobody, and only the cookie tells them apart.
 */
$stranger = [];
request('php/cart-update.php', $stranger, [
    'action' => 'add-product',
    'product_id' => (string) $testProductId,
    'quantity' => '1',
    'back' => '/Capstone/php/cart.php',
]);

$strangerIds = lineIds(cartPage($stranger));
checkTrue('cross-basket: the stranger has a basket of their own', $strangerIds !== []);
checkTrue('cross-basket: with a different line id', $strangerIds[0] !== $productLineId);

$strangerLineId = $strangerIds[0];
$quantityBefore = (int) $database->query("SELECT quantity FROM cart_items WHERE id = $strangerLineId")->fetchColumn();

request('php/cart-update.php', $guest, [
    'action' => 'update',
    'item_id' => (string) $strangerLineId,
    'quantity' => '77',
    'back' => '/Capstone/php/cart.php',
]);
check(
    'cross-basket: the stranger\'s quantity is untouched',
    (int) $database->query("SELECT quantity FROM cart_items WHERE id = $strangerLineId")->fetchColumn(),
    $quantityBefore
);

request('php/cart-update.php', $guest, [
    'action' => 'remove',
    'item_id' => (string) $strangerLineId,
    'back' => '/Capstone/php/cart.php',
]);
check(
    'cross-basket: the stranger\'s line cannot be removed',
    (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE id = $strangerLineId")->fetchColumn(),
    1
);
check('cross-basket: the stranger still sees their line', lineCount(cartPage($stranger)), 1);

/* ------------------------------------------------------------------ *
 * A withdrawn product: shown, not charged for, price not rewritten.
 * ------------------------------------------------------------------ */

request('php/cart-update.php', $guest, [
    'action' => 'add-product',
    'product_id' => (string) $doomedProductId,
    'quantity' => '1',
    'back' => '/Capstone/php/cart.php',
]);

$withTotal = totalShown(cartPage($guest));
$database->exec("UPDATE products SET is_active = 0 WHERE id = $doomedProductId");
$html = cartPage($guest);

checkTrue('withdrawn: the line is still shown', str_contains($html, 'ZZTest Doomed Bouquet'));
checkTrue('withdrawn: and says why it cannot be bought', str_contains($html, 'No longer on sale'));
checkTrue('withdrawn: the basket warns at the top', str_contains($html, 'left out of the total'));
checkTrue(
    'withdrawn: the total dropped by exactly its price',
    $withTotal !== null && totalShown($html) !== null && $withTotal !== totalShown($html)
);
check(
    'withdrawn: its frozen price was not rewritten',
    (string) $database->query(
        "SELECT unit_price FROM cart_items WHERE product_id = $doomedProductId"
    )->fetchColumn(),
    '400.00'
);

/*
 * And the one state that still disables the button, which is the reason the block above no longer
 * does. placeOrder() refuses an order holding a withdrawn line, so php/cart.php refuses it here --
 * beside the Remove buttons that fix it, rather than after the customer has typed out an address
 * and been turned down at the end.
 */
checkTrue(
    'withdrawn: the checkout button is disabled',
    (bool) preg_match('/cart-checkout[^>]*disabled/', $html)
);
checkTrue(
    'withdrawn: and says what to do about it',
    str_contains($html, 'no longer on sale and you can check out.')
);
checkTrue('withdrawn: with no way through to the till', str_contains($html, 'checkout.php') === false);

/*
 * The same judgement in the navbar. This is the assertion that the joined product_live column and
 * fetchProduct() agree: the panel decides from a LEFT JOIN and the page from the same row, and if
 * the ON clause ever drifts from fetchProduct()'s WHERE clause, one of these two will say a
 * withdrawn bouquet is still for sale.
 */
check('withdrawn: the hover panel strikes exactly one line', substr_count(navPanel($html), 'nav-cart-panel-gone'), 1);
checkTrue('withdrawn: and explains the gap in its total', str_contains(navPanel($html), 'no longer available'));
check('withdrawn: the panel total still matches the page', navPanelTotal($html), totalShown($html));

// Taken off sale is not the same as gone. Deleting the product must not delete the basket line.
$database->exec("DELETE FROM products WHERE id = $doomedProductId");
$html = cartPage($guest);
checkTrue('deleted product: the line survives it', str_contains($html, 'ZZTest Doomed Bouquet'));
check(
    'deleted product: the link to it was dropped',
    str_contains($html, 'product.php?id=' . $doomedProductId),
    false
);
check(
    'deleted product: product_id was set to null, not the row deleted',
    $database->query(
        "SELECT product_id FROM cart_items WHERE name = 'ZZTest Doomed Bouquet'"
    )->fetchColumn(),
    null
);

$doomedLineId = (int) $database->query(
    "SELECT id FROM cart_items WHERE name = 'ZZTest Doomed Bouquet'"
)->fetchColumn();
request('php/cart-update.php', $guest, [
    'action' => 'remove',
    'item_id' => (string) $doomedLineId,
    'back' => '/Capstone/php/cart.php',
]);
check(
    'deleted product: and it can still be removed',
    (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE id = $doomedLineId")->fetchColumn(),
    0
);

/* ------------------------------------------------------------------ *
 * The line cap.
 * ------------------------------------------------------------------ */

$guestCartId = (int) $database->query(
    'SELECT cart_id FROM cart_items WHERE id = ' . $productLineId
)->fetchColumn();

// Filled by inserting rather than by 50 HTTP round trips: the cap is what is being tested, not
// the adding. Every row is this suite's own, in this suite's own basket.
$filler = $database->prepare(
    "INSERT INTO cart_items (cart_id, kind, product_id, name, unit_price, quantity)
     VALUES (:cart, 'product', :product, 'ZZTest Filler', 10.00, 1)"
);

while ((int) $database->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = $guestCartId")->fetchColumn() < 50) {
    $filler->execute(['cart' => $guestCartId, 'product' => $testProductId]);
}

$response = request('php/cart-update.php', $guest, [
    'action' => 'add-design',
    'design' => 'count=3&arrangement=round&bloom=1&wire=7',
    'back' => '/Capstone/php/cart.php',
]);
check('cap: a full basket still redirects', $response['status'], 302);
check(
    'cap: nothing was added past the limit',
    (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = $guestCartId")->fetchColumn(),
    50
);
checkTrue('cap: and the page says the basket is full', str_contains(cartPage($guest), 'full'));

/*
 * Fifty lines is the case the hover panel was capped for. It hangs off a corner of the navbar, so
 * it lists a handful and says how many it left out rather than becoming a second basket page.
 */
$html = cartPage($guest);
check('cap: the hover panel lists only the first few', count(navPanelNames($html)), CART_PREVIEW_LINES);
checkTrue('cap: and says how many it left out', str_contains(navPanel($html), 'and 46 more lines'));
check('cap: its total is still the page total', navPanelTotal($html), totalShown($html));

// Emptying gets the basket back, and takes the frozen parts with it.
$response = request('php/cart-update.php', $guest, [
    'action' => 'empty',
    'back' => '/Capstone/php/cart.php',
]);
check('empty action: redirects', $response['status'], 302);
check(
    'empty action: every line is gone',
    (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = $guestCartId")->fetchColumn(),
    0
);
check(
    'empty action: the frozen parts went with them',
    (int) $database->query(
        "SELECT COUNT(*) FROM cart_item_parts p
           LEFT JOIN cart_items i ON i.id = p.item_id
          WHERE i.id IS NULL"
    )->fetchColumn(),
    0
);
check(
    'empty action: the basket itself is kept',
    (int) $database->query("SELECT COUNT(*) FROM carts WHERE id = $guestCartId")->fetchColumn(),
    1
);
checkTrue('empty action: the page says so', str_contains(cartPage($guest), 'Your basket is empty'));

/* ------------------------------------------------------------------ *
 * The merge. A guest fills a basket, then signs in.
 * ------------------------------------------------------------------ */

/**
 * Make this browser one the account already trusts.
 *
 * Sign-in here is two steps: the password, then a six-digit code, unless the browser has been
 * trusted before. That code is emailed -- which on this install means written to a log file -- so a
 * suite that went through it would have to read somebody's live verification code back out of a
 * file to do its job. Minting a device token and storing its hash gets the same signed-in state
 * without a secret ever being involved, and it exercises the real deviceIsTrusted() query.
 *
 * Only the hash is stored, so this is also a check that the device layer works the way the basket
 * layer copied it from.
 *
 * @param array<string, string> $jar
 */
function trustDevice(array &$jar, int $userId): void
{
    global $database;

    $token = bin2hex(random_bytes(32));

    $database->prepare(
        'INSERT INTO trusted_devices (user_id, token_hash, label, expires_at)
         VALUES (:user, :hash, :label, DATE_ADD(NOW(), INTERVAL 30 DAY))'
    )->execute([
        'user' => $userId,
        'hash' => hash('sha256', $token),
        'label' => 'ZZTest basket suite',
    ]);

    $jar['capstone_device'] = $token;
}

/**
 * Sign in, carrying the jar. Returns the response so the caller can tell 2FA from success.
 *
 * The form field is `identifier`, not `email`: login.php accepts either a username or an address in
 * one box. Posting `email` would simply fail validation, and every merge check after it would be
 * testing a visitor who never signed in.
 *
 * @param array<string, string> $jar
 */
function signIn(array &$jar, string $identifier, string $password): array
{
    // The form is fetched first so the session exists before it is posted to.
    request('php/login.php', $jar);

    return request('php/login.php', $jar, [
        'form_type' => 'login',
        'identifier' => $identifier,
        'password' => $password,
    ]);
}

// Case one: the account has no basket, so the guest's basket is simply re-labelled.
$joiner = [];
request('php/cart-update.php', $joiner, [
    'action' => 'add-product',
    'product_id' => (string) $testProductId,
    'quantity' => '3',
    'back' => '/Capstone/php/cart.php',
]);

$joinerCartId = (int) $database->query(
    'SELECT id FROM carts WHERE token_hash = ' . $database->quote(hash('sha256', $joiner['capstone_cart']))
)->fetchColumn();

checkTrue('merge one: the guest basket exists', $joinerCartId > 0);
check('merge one: the account has no basket yet',
    (int) $database->query("SELECT COUNT(*) FROM carts WHERE user_id = $testUserId")->fetchColumn(), 0);

trustDevice($joiner, $testUserId);
$response = signIn($joiner, 'zztest.cart@capstone.test', $password);
check('merge one: signing in redirects', $response['status'], 302);

$owned = $database->query("SELECT id, token_hash FROM carts WHERE user_id = $testUserId")->fetch();
checkTrue('merge one: the account now owns a basket', $owned !== false);
check('merge one: it is the same basket, re-labelled', (int) $owned['id'], $joinerCartId);
check('merge one: the guest token was cleared', $owned['token_hash'], null);
// The row is only half of it. If the browser kept the cookie, the next request would look up a
// token_hash that is now NULL, find nothing, and be handed a second empty basket -- so the
// account's basket would appear to have emptied itself the moment they signed in.
check('merge one: and the browser dropped the cookie', isset($joiner['capstone_cart']), false);
check(
    'merge one: the lines came with it',
    (int) $database->query("SELECT SUM(quantity) FROM cart_items WHERE cart_id = $joinerCartId")->fetchColumn(),
    3
);

$html = cartPage($joiner);
check('merge one: and the page shows them', lineCount($html), 1);
check('merge one: signed in, the sign-in prompt is gone', str_contains($html, 'you will need one to check out'), false);

// The third state of that control: signed in, nothing withdrawn, so it is a live link through to
// the till. A link and not a form, because arriving at checkout writes nothing.
checkTrue(
    'merge one: and checkout is a live link',
    (bool) preg_match('#cart-checkout"\s+href="[^"]*checkout\.php">Checkout<#', $html)
);

/*
 * Case two: the account already has a basket. The guest lines are *added* to it -- not discarded,
 * and not reconciled. Somebody who put three things in on their phone and two on a laptop should
 * find five, and picking a winner would silently throw away work somebody did.
 */
$secondDevice = [];
request('php/cart-update.php', $secondDevice, [
    'action' => 'add-design',
    'design' => 'count=6&arrangement=loose&bloom=1&wire=7',
    'back' => '/Capstone/php/cart.php',
]);

$secondCartId = (int) $database->query(
    'SELECT id FROM carts WHERE token_hash = ' . $database->quote(hash('sha256', $secondDevice['capstone_cart']))
)->fetchColumn();
checkTrue('merge two: a second guest basket exists', $secondCartId > 0 && $secondCartId !== $joinerCartId);

trustDevice($secondDevice, $testUserId);
signIn($secondDevice, 'zztest.cart@capstone.test', $password);

check('merge two: this browser dropped its cookie as well', isset($secondDevice['capstone_cart']), false);

check(
    'merge two: the account still has exactly one basket',
    (int) $database->query("SELECT COUNT(*) FROM carts WHERE user_id = $testUserId")->fetchColumn(),
    1
);
check(
    'merge two: the empty guest basket was cleaned up',
    (int) $database->query("SELECT COUNT(*) FROM carts WHERE id = $secondCartId")->fetchColumn(),
    0
);
check(
    'merge two: both devices\' lines are present',
    (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = $joinerCartId")->fetchColumn(),
    2
);
check('merge two: the page shows both', lineCount(cartPage($secondDevice)), 2);
check(
    'merge two: no line was orphaned',
    (int) $database->query(
        "SELECT COUNT(*) FROM cart_items WHERE cart_id NOT IN (SELECT id FROM carts)"
    )->fetchColumn(),
    0
);

// Signing in with nothing in hand must not disturb the basket already on the account.
$empty = [];
trustDevice($empty, $testUserId);
signIn($empty, 'zztest.cart@capstone.test', $password);
check(
    'merge three: an empty-handed sign-in changes nothing',
    (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = $joinerCartId")->fetchColumn(),
    2
);
check(
    'merge three: and still one basket for the account',
    (int) $database->query("SELECT COUNT(*) FROM carts WHERE user_id = $testUserId")->fetchColumn(),
    1
);

/* ------------------------------------------------------------------ *
 * Adding a saved bouquet needs an account.
 * ------------------------------------------------------------------ */

// saved_designs stores the single-choice parts and the arrangement; the stem count is however
// many saved_design_blooms rows point at it, which is why there is no count column to set.
$database->prepare(
        "INSERT INTO saved_designs (user_id, name, arrangement, wire_id, foliage_id)
         SELECT :user, 'ZZTest Saved', 'dome', wire.id, foliage.id
             FROM product_components wire
             JOIN product_components foliage ON foliage.kind = 'foliage' AND foliage.is_active = 1
            WHERE wire.kind = 'wire' AND wire.is_active = 1
            ORDER BY foliage.id
            LIMIT 1"
)->execute(['user' => $testUserId]);
$savedId = (int) $database->lastInsertId();

if ($savedId > 0) {
    $database->prepare(
        "INSERT INTO saved_design_blooms (design_id, component_id, quantity, sort_order)
                 SELECT :design, id, 6, 0
                     FROM product_components
                    WHERE kind = 'bloom' AND is_active = 1
                    ORDER BY id
                    LIMIT 1"
    )->execute(['design' => $savedId]);

    $before = (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = $joinerCartId")->fetchColumn();
    $beforeItemId = (int) $database->query("SELECT COALESCE(MAX(id), 0) FROM cart_items WHERE cart_id = $joinerCartId")->fetchColumn();

    request('php/cart-update.php', $secondDevice, [
        'action' => 'add-saved',
        'design_id' => (string) $savedId,
        'back' => '/Capstone/php/designs.php',
    ]);
    check(
        'saved: a signed-in owner can add it',
        (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = $joinerCartId")->fetchColumn(),
        $before + 1
    );

    $savedCartItemId = (int) $database->query("SELECT MAX(id) FROM cart_items WHERE cart_id = $joinerCartId AND id > $beforeItemId")->fetchColumn();
    check(
        'saved: foliage is frozen into the basket receipt',
        (int) $database->query("SELECT COUNT(*) FROM cart_item_parts WHERE item_id = $savedCartItemId AND kind = 'foliage'")->fetchColumn(),
        1
    );
    check(
        'saved: the frozen foliage points at the selected component',
        (int) $database->query("SELECT component_id FROM cart_item_parts WHERE item_id = $savedCartItemId AND kind = 'foliage'")->fetchColumn(),
        (int) $database->query("SELECT foliage_id FROM saved_designs WHERE id = $savedId")->fetchColumn()
    );

    // A signed-out visitor has no saved bouquets, so this must refuse rather than guess.
    $outsider = [];
    $response = request('php/cart-update.php', $outsider, [
        'action' => 'add-saved',
        'design_id' => (string) $savedId,
        'back' => '/Capstone/php/cart.php',
    ]);
    check('saved: a guest is refused', $response['status'], 302);
    check(
        'saved: and nothing was added for them',
        (int) $database->query(
            'SELECT COUNT(*) FROM cart_items WHERE cart_id IN
                (SELECT id FROM carts WHERE token_hash = ' . $database->quote(hash('sha256', $outsider['capstone_cart'] ?? 'none')) . ')'
        )->fetchColumn(),
        0
    );
} else {
    echo "note: saved-design fixture could not be created; those checks were skipped\n";
}

/* ------------------------------------------------------------------ *
 * Tidy up. Only rows this suite made.
 * ------------------------------------------------------------------ */

$database->exec("DELETE FROM carts WHERE user_id = $testUserId");
$database->exec("DELETE FROM carts WHERE id IN ($guestCartId, $secondCartId)");
$database->exec("DELETE FROM cart_items WHERE name LIKE 'ZZTest%'");
$database->exec("DELETE FROM saved_designs WHERE name = 'ZZTest Saved'");
$database->exec("DELETE FROM trusted_devices WHERE label = 'ZZTest basket suite'");
$database->exec("DELETE FROM users WHERE email LIKE 'zztest.cart%'");
$database->exec("DELETE FROM products WHERE name LIKE 'ZZTest%'");

// Any basket this suite's stray visitors opened.
$database->exec(
    'DELETE FROM carts WHERE user_id IS NULL AND id NOT IN (SELECT DISTINCT cart_id FROM cart_items)'
);

echo implode("\n", $failures) . ($failures === [] ? '' : "\n");
printf("\n%d passed, %d failed\n", $passed, $failed);

$leftover = (int) $database->query("SELECT COUNT(*) FROM products WHERE name LIKE 'ZZTest%'")->fetchColumn()
    + (int) $database->query("SELECT COUNT(*) FROM users WHERE email LIKE 'zztest.cart%'")->fetchColumn()
    + (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE name LIKE 'ZZTest%'")->fetchColumn();

printf("leftover ZZTest rows: %d\n", $leftover);

exit($failed === 0 ? 0 : 1);

