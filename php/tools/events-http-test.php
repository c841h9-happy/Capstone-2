<?php

declare(strict_types=1);

/**
 * Events and promos: the arithmetic, the chokepoint, the freeze, and the two admin screens.
 *
 *     C:\xampp\php\php.exe php\tools\events-http-test.php
 *
 * THE ASSERTION THIS SUITE EXISTS FOR is the freeze. The whole feature rests on one decision --
 * that the discount is applied in php/config/products.php's two readers and nowhere else, so that
 * addProductToCart() freezes the DISCOUNTED price and the basket, the order, the receipt and the
 * reports all inherit it through code that did not change. If that is wrong, it is wrong quietly:
 * the catalogue would show one price and the till would charge another, and nothing would say so.
 *
 * So the suite adds a discounted product to a basket, ENDS THE EVENT, and then asserts that
 * cart_items.unit_price and the order made from it still hold the promo price. That is the test
 * that would have caught the alternative designs.
 *
 * Nothing here is asserted against a seeded row. Every fixture is created by this file, prefixed
 * ZZTest, and swept at both ends -- before the baseline is read, not just after, so a run that died
 * half way cannot make the next one fail.
 *
 * Two things worth knowing about how it is written:
 *
 *  - activeEvent() and eventProductIds() cache per request. A PHP process that creates an event,
 *    reads a price, then ends the event and reads again would otherwise see the first answer twice.
 *    forgetActiveEvent() exists for exactly this and is called between phases.
 *  - The HTTP half uses its own cookie jars and scrapes its own CSRF tokens, the same harness shape
 *    the other suites use. Admin screens are checked as a stranger, as a customer and as an admin,
 *    because php/admin/guard.php is the only thing protecting that folder.
 */

// Same guard as every other file in this folder. php\tools is under the webroot, and this one
// creates accounts, products, events and orders.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const BASE = 'http://localhost/Capstone/';

/*
 * Apache's conditions, reproduced before the first basePath() call caches its answer. Under CLI
 * there is no DOCUMENT_ROOT and paths.php would conclude the site is served from "/", which is
 * correct for a root-served install and wrong for this one. Same line cart-unit.php opens with.
 */
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/events.php';
require_once __DIR__ . '/../config/events-admin.php';
require_once __DIR__ . '/../config/products.php';
require_once __DIR__ . '/../config/products-admin.php';
// For cartLineTotal() only -- pure arithmetic, and the one basket function this suite calls
// directly. Every basket WRITE here goes over HTTP, because those need a session and a cookie.
require_once __DIR__ . '/../config/cart.php';

$database = getDatabaseConnection();

// A notice has to be as loud as a failure: the point is to catch an undefined index the browser
// would swallow.
$notices = [];
set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$notices): bool {
    $notices[] = sprintf('%s in %s:%d', $message, basename($file), $line);
    return true;
});
error_reporting(E_ALL);

$failures = 0;
$checks = 0;

function heading(string $text): void
{
    echo "\n--- $text ---\n";
}

function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures, $checks;
    $checks++;

    if ($actual === $expected) {
        echo "  ok    $label\n";
        return;
    }

    $failures++;
    printf(
        "  FAIL  %s\n          expected: %s\n          actual:   %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

function checkTrue(string $label, mixed $actual): void
{
    check($label, $actual === true, true);
}

/* ------------------------------------------------------------------ *
 * The HTTP harness: cookie jars, CSRF tokens, and a table-body scraper.
 * ------------------------------------------------------------------ */

/**
 * One request. Returns ['status' => int, 'body' => string, 'location' => string].
 *
 * A POST with no explicit token gets one scraped from a cheap GET and memoised per jar, which is the
 * same edit the other suites took when CSRF landed: every existing call site kept working and the
 * deliberate omissions live in the sections that test refusals.
 */
function request(string $path, string $jar, ?array $post = null, bool $withToken = true): array
{
    static $tokens = [];

    if ($post !== null && $withToken && !isset($post['csrf_token'])) {
        $key = $jar;

        if (!isset($tokens[$key])) {
            $tokens[$key] = tokenFrom('php/admin/events.php', $jar);
        }

        $post['csrf_token'] = $tokens[$key];
    }

    $handle = curl_init(BASE . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_USERAGENT => 'events-http-test',
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($post !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);

    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    $location = '';

    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $found) === 1) {
        $location = trim($found[1]);
    }

    return ['status' => $status, 'body' => $body, 'location' => $location];
}

/** A CSRF token scraped from a page's own form. */
function tokenFrom(string $path, string $jar): string
{
    $body = request($path, $jar)['body'];

    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $found) === 1 ? $found[1] : '';
}

/**
 * Just the rows of the first table on a page.
 *
 * Every admin screen here redirects after POST and renders a success message naming the row it just
 * changed, so a page-wide str_contains() can be satisfied by the TOAST rather than by the list --
 * "the filter dropped it" then reads as a pass while the table underneath is correctly empty.
 * Returns '' for a page with no table, which is the honest answer to "which rows are listed".
 */
function tableBody(string $html): string
{
    return preg_match('#<tbody>(.*?)</tbody>#s', $html, $found) === 1 ? $found[1] : '';
}

/**
 * Sign one jar in as an existing account, through the real form and the real 2FA step.
 *
 * THE FIELD NAMES ARE NOT THE OBVIOUS ONES and guessing them costs a whole run. php/login.php reads
 * `form_type` (anything but 'signup' is a sign-in) and `identifier` -- not `action` and not `email`.
 * A POST with the wrong names is not an error: the page simply re-renders with an empty form and
 * answers 200, which is indistinguishable from a wrong password unless the Location header is
 * checked. php/verify.php does use `action` and `code`.
 *
 * AND THE VERIFICATION HAS TO BE A 200, not the absence of a word. The first version of this
 * function asked whether php/dashboard.php's body contained "Sign in" -- but a signed-OUT visitor
 * is REDIRECTED there, a 302 has an empty body, and `!str_contains('', 'Sign in')` is true. It
 * reported success for a jar that was never signed in, and eighteen later checks failed in ways
 * that looked like broken pages. A login-only page answering 200 is the honest test.
 */
function signIn(string $jar, string $email, string $password): bool
{
    $token = tokenFrom('php/login.php', $jar);

    if ($token === '') {
        return false;
    }

    $result = request('php/login.php', $jar, [
        'csrf_token' => $token,
        'form_type' => 'login',
        'identifier' => $email,
        'password' => $password,
    ], false);

    // A trusted-device cookie may let the sign-in through without a code; otherwise a code was
    // mailed and has to be read out of the log.
    if (str_contains($result['location'], 'verify.php')) {
        $code = readCodeFor($email);

        if ($code === '') {
            return false;
        }

        $verifyToken = tokenFrom('php/verify.php', $jar);
        request('php/verify.php', $jar, [
            'csrf_token' => $verifyToken,
            'action' => 'verify',
            'code' => $code,
        ], false);
    }

    // php/profile.php renders only for a signed-in account and redirects everyone else.
    return request('php/profile.php', $jar)['status'] === 200;
}

/**
 * The newest sign-in code mailed to one address, from the mail log.
 *
 * MAIL_TRANSPORT is 'log', so every message this project sends is appended to
 * %TEMP%\capstone-mail.log. The log holds live codes for real accounts and is treated as a secret:
 * this reads it, takes the one code it needs, and never prints it.
 */
function readCodeFor(string $address): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'capstone-mail.log';

    if (!is_file($path)) {
        return '';
    }

    $log = (string) file_get_contents($path);
    $chunks = preg_split('/(?=To:\s)/', $log) ?: [];
    $found = '';

    foreach ($chunks as $chunk) {
        if (!str_contains($chunk, $address)) {
            continue;
        }

        if (preg_match_all('/\b(\d{6})\b/', $chunk, $codes) === 1 || $codes[1] !== []) {
            $found = (string) end($codes[1]);
        }
    }

    return $found;
}

/* ------------------------------------------------------------------ *
 * Sweep, then baseline.
 * ------------------------------------------------------------------ */

/**
 * Everything this file ever creates, removed in the order the foreign keys allow.
 *
 * Each statement in its own try: ERRMODE_EXCEPTION means one bad statement ends the request, so a
 * linear block abandons every row after the failure -- which is how one wrong column name once
 * stranded three users and two products on this project.
 *
 * Orders before users, because fk_orders_user is ON DELETE RESTRICT. event_products and
 * cart_item_parts are not listed: both cascade from their parents.
 *
 * THE GUEST CART IS THE AWKWARD ONE and it needs its own step. The freeze section adds to the
 * basket as a signed-OUT visitor, so the row it creates has user_id NULL and there is no owner to
 * find it by -- the same trap that left csrf-http-test.php stranding rows until 2026-09-27. Its
 * cart_items can be found by their frozen name, so the cart is found THROUGH them and deleted
 * first, letting the cascade take the items; the delete is scoped to `user_id IS NULL` so a real
 * signed-in customer's basket can never be in range.
 */
function sweep(PDO $database): void
{
    $owner = "(SELECT id FROM users WHERE email LIKE 'zztest.promo%')";

    foreach ([
        // Guest carts first, found through the items that name themselves, and only ownerless ones.
        "DELETE FROM carts
          WHERE user_id IS NULL
            AND id IN (SELECT cart_id FROM (SELECT cart_id FROM cart_items WHERE name LIKE 'ZZTest Promo%') AS doomed)",
        "DELETE FROM order_items WHERE name LIKE 'ZZTest Promo%'",
        "DELETE FROM orders WHERE contact_name LIKE 'ZZTest Promo%'",
        "DELETE FROM orders WHERE user_id IN $owner",
        "DELETE FROM cart_items WHERE name LIKE 'ZZTest Promo%'",
        "DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM carts WHERE user_id IN $owner)",
        "DELETE FROM carts WHERE user_id IN $owner",
        "DELETE FROM events WHERE name LIKE 'ZZTest Promo%'",
        "DELETE FROM products WHERE name LIKE 'ZZTest Promo%'",
        "DELETE FROM trusted_devices WHERE user_id IN $owner",
        "DELETE FROM email_codes WHERE user_id IN $owner",
        "DELETE FROM users WHERE email LIKE 'zztest.promo%'",
    ] as $sql) {
        try {
            $database->exec($sql);
        } catch (PDOException $exception) {
            echo '  sweep failed: ', explode("\n", $exception->getMessage())[0], "\n";
        }
    }
}

sweep($database);

$before = [
    'products' => (int) $database->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'events' => (int) $database->query('SELECT COUNT(*) FROM events')->fetchColumn(),
    'event_products' => (int) $database->query('SELECT COUNT(*) FROM event_products')->fetchColumn(),
    'carts' => (int) $database->query('SELECT COUNT(*) FROM carts')->fetchColumn(),
    'cart_items' => (int) $database->query('SELECT COUNT(*) FROM cart_items')->fetchColumn(),
    'orders' => (int) $database->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'users' => (int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn(),
];

/* ------------------------------------------------------------------ *
 * 1. The arithmetic.
 * ------------------------------------------------------------------ */

heading('eventPromoPrice(): centavos in, centavos out');

check('20% off 38.00', eventPromoPrice('38.00', 20), '30.40');
check('15% off 38.00', eventPromoPrice('38.00', 15), '32.30');
check('50% off 110.00', eventPromoPrice('110.00', 50), '55.00');
check('90% off 420.00 -- the ceiling', eventPromoPrice('420.00', 90), '42.00');

/*
 * The rounding case, which is the one a float would get wrong.
 *
 * 15% off 38.05 is 3805 * 85 / 100 = 3234.25 centavos, which rounds to 3234 -- 32.34. Done in
 * floats, 38.05 * 0.85 is 32.342499999999994 and a naive number_format gives 32.34 by luck; the
 * value worth pinning is that the integer path gives the same answer deliberately.
 */
check('15% off 38.05 rounds to the centavo', eventPromoPrice('38.05', 15), '32.34');
check('33% off 0.01 cannot go below zero', eventPromoPrice('0.01', 33), '0.01');
check('1% off 1.00', eventPromoPrice('1.00', 1), '0.99');

heading('eventPromoPrice() never invents a discount');

check('0% is unchanged', eventPromoPrice('38.00', 0), '38.00');
check('a negative percent is unchanged', eventPromoPrice('38.00', -10), '38.00');
check('over the ceiling is unchanged', eventPromoPrice('38.00', 95), '38.00');
check('100% is unchanged, not free', eventPromoPrice('38.00', 100), '38.00');

/* ------------------------------------------------------------------ *
 * 2. Fixtures: two products, one event covering only one of them.
 * ------------------------------------------------------------------ */

heading('fixtures');

$inPromo = createProduct([
    'name' => 'ZZTest Promo In',
    'kind' => 'bouquet',
    'description' => 'A fixture for the events suite. Deleted when the suite ends.',
    'price' => '38.00',
    'colour' => 'Dusty rose',
    'stem_count' => 9,
    'is_active' => 1,
]);

$outOfPromo = createProduct([
    'name' => 'ZZTest Promo Out',
    'kind' => 'bouquet',
    'description' => 'A fixture that is deliberately NOT in the promo.',
    'price' => '38.00',
    'colour' => 'Ivory',
    'stem_count' => 9,
    'is_active' => 1,
]);

checkTrue('two fixture products created', $inPromo > 0 && $outOfPromo > 0);

$eventValues = [
    'name' => 'ZZTest Promo Event',
    'tagline' => 'A fixture occasion.',
    'starts_on' => date('Y-m-d', strtotime('-1 day')),
    'ends_on' => date('Y-m-d', strtotime('+1 day')),
    'discount_percent' => 20,
    'featured_product_id' => $inPromo,
    'is_active' => 1,
];

$eventId = createEvent($eventValues);
checkTrue('event created', $eventId > 0);

$saved = saveEventProducts($eventId, [$inPromo]);
check('one product in the promo', $saved['added'], 1);
check('and no errors saving it', $saved['errors'], []);

/* ------------------------------------------------------------------ *
 * 3. The chokepoint.
 * ------------------------------------------------------------------ */

heading('the discount reaches fetchProduct() and only the right product');

forgetActiveEvent();

$onNow = activeEvent();
checkTrue('the event is on', $onNow !== null);
check('and it is ours', (int) ($onNow['id'] ?? 0), $eventId);

$discounted = fetchProduct($inPromo);
check('the covered product is discounted', (string) $discounted['price'], '30.40');
check('and keeps its list price alongside', (string) $discounted['list_price'], '38.00');
check('and says what percent', (int) $discounted['promo_percent'], 20);

$untouched = fetchProduct($outOfPromo);
check('the product not in the promo is unchanged', (string) $untouched['price'], '38.00');
check('its list price equals its price', (string) $untouched['list_price'], '38.00');
check('and it reports no promo', (int) $untouched['promo_percent'], 0);

/*
 * THE ADMIN MUST STILL SEE LIST PRICE. Two readers, one product, two answers -- which is the whole
 * reason products-admin.php is a separate file. An admin editing a 20%-off event needs the number
 * the discount is computed FROM.
 */
$asAdmin = adminFetchProduct($inPromo);
check('adminFetchProduct() returns LIST price on the same product', (string) $asAdmin['price'], '38.00');

heading('fetchProducts() decorates a list the same way');

$list = fetchProducts(['q' => 'ZZTest Promo'], 20);
$byId = [];

foreach ($list as $row) {
    $byId[(int) $row['id']] = $row;
}

checkTrue('both fixtures are in the catalogue read', isset($byId[$inPromo], $byId[$outOfPromo]));
check('the covered one is discounted in the list', (string) $byId[$inPromo]['price'], '30.40');
check('the other one is not', (string) $byId[$outOfPromo]['price'], '38.00');

/* ------------------------------------------------------------------ *
 * 4. THE FREEZE. The assertion the whole design rests on.
 * ------------------------------------------------------------------ */

heading('the freeze: a price taken during a promo survives the promo ending');

/*
 * Over HTTP and through the real form, not by calling addProductToCart() directly.
 *
 * That function needs a session and a cart cookie, which is why php/tools/cart-unit.php stays on
 * pure arithmetic and leaves every basket write to cart-http-test.php. Going through the real POST
 * is also the stronger evidence: it proves the price that lands in the column is the one the page
 * the customer was looking at had read, rather than the one this suite happened to ask for.
 *
 * A GUEST jar deliberately -- no account. That is the hardest case, since the cart cookie is the
 * only identity, and it is also the common one for a promo: somebody arriving from a Valentine's
 * post is not signed in.
 */
$jarGuest = tempnam(sys_get_temp_dir(), 'zzevt');

$addToken = tokenFrom('php/product.php?id=' . $inPromo, $jarGuest);
checkTrue('the product page gave us a token', $addToken !== '');

$added = request('php/cart-update.php', $jarGuest, [
    'csrf_token' => $addToken,
    'action' => 'add-product',
    'product_id' => (string) $inPromo,
    'quantity' => '2',
    'back' => 'php/cart.php',
], false);

checkTrue('the basket accepted it', $added['status'] === 302 || $added['status'] === 303);

$frozen = $database->prepare(
    "SELECT name, unit_price, quantity FROM cart_items WHERE name LIKE 'ZZTest Promo In%' ORDER BY id DESC LIMIT 1"
);
$frozen->execute();
$line = $frozen->fetch();

checkTrue('and a line exists for it', $line !== false);
check('the basket froze the PROMO price, not the list price', (string) $line['unit_price'], '30.40');
check('and the quantity it was given', (int) $line['quantity'], 2);

// The basket page itself must agree with the column.
$basket = request('php/cart.php', $jarGuest);
check('the basket page answers 200', $basket['status'], 200);
checkTrue('and shows the promo price', str_contains($basket['body'], '30.40'));

// Now end the event -- exactly what happens on the 15th of February.
setEventActive($eventId, false);
forgetActiveEvent();

check('the event is over', activeEvent(), null);
check('and the catalogue is back to list price', (string) fetchProduct($inPromo)['price'], '38.00');
check('with no promo reported', (int) fetchProduct($inPromo)['promo_percent'], 0);

$frozen->execute();
$stillFrozen = $frozen->fetch();

/*
 * The one that matters. A design that discounted at checkout, or that stored the discount against
 * the order, would fail here -- and would fail quietly in production.
 */
check('THE BASKET STILL HOLDS THE PROMO PRICE', (string) $stillFrozen['unit_price'], '30.40');
check('and its line total is computed from the frozen figure', cartLineTotal((string) $stillFrozen['unit_price'], (int) $stillFrozen['quantity']), '60.80');

// And the page the customer sees still says so, with the event long gone.
$basketAfter = request('php/cart.php', $jarGuest);
checkTrue('the basket page still shows the held price', str_contains($basketAfter['body'], '30.40'));
checkTrue('and does not quietly show list price', !str_contains($basketAfter['body'], '76.00'));

// Put the event back on for the rest of the suite.
setEventActive($eventId, true);
forgetActiveEvent();
check('the event is on again', (int) (activeEvent()['id'] ?? 0), $eventId);

/* ------------------------------------------------------------------ *
 * 5. Which event is on: dates, is_active, and overlap.
 * ------------------------------------------------------------------ */

heading('activeEvent() picks exactly one event');

$finished = createEvent([
    'name' => 'ZZTest Promo Finished',
    'tagline' => '',
    'starts_on' => date('Y-m-d', strtotime('-30 days')),
    'ends_on' => date('Y-m-d', strtotime('-20 days')),
    'discount_percent' => 50,
    'featured_product_id' => null,
    'is_active' => 1,
]);
saveEventProducts($finished, [$inPromo, $outOfPromo]);
forgetActiveEvent();

check('a finished event is not on', (int) (activeEvent()['id'] ?? 0), $eventId);
check('and discounts nothing', (string) fetchProduct($outOfPromo)['price'], '38.00');

$switchedOff = createEvent([
    'name' => 'ZZTest Promo Switched Off',
    'tagline' => '',
    'starts_on' => date('Y-m-d'),
    'ends_on' => date('Y-m-d', strtotime('+2 days')),
    'discount_percent' => 90,
    'featured_product_id' => null,
    'is_active' => 0,
]);
saveEventProducts($switchedOff, [$outOfPromo]);
forgetActiveEvent();

check('a switched-off event is not on even inside its dates', (int) (activeEvent()['id'] ?? 0), $eventId);
check('and discounts nothing', (string) fetchProduct($outOfPromo)['price'], '38.00');

/*
 * Overlap. A second live event that started TODAY, where ours started yesterday -- so the newer one
 * must win. This is the rule activeEvent()'s docblock states, and the reason it is stated is that
 * two promos silently fighting over one price is the failure worth refusing.
 */
$newer = createEvent([
    'name' => 'ZZTest Promo Newer',
    'tagline' => '',
    'starts_on' => date('Y-m-d'),
    'ends_on' => date('Y-m-d', strtotime('+3 days')),
    'discount_percent' => 50,
    'featured_product_id' => null,
    'is_active' => 1,
]);
saveEventProducts($newer, [$outOfPromo]);
forgetActiveEvent();

check('the event that started most recently wins', (int) (activeEvent()['id'] ?? 0), $newer);
check('so its product is the one discounted', (string) fetchProduct($outOfPromo)['price'], '19.00');
check('and the older event stops discounting', (string) fetchProduct($inPromo)['price'], '38.00');

// Back to one event, so the HTTP half has a predictable shop.
$database->exec('DELETE FROM events WHERE id = ' . (int) $newer);
$database->exec('DELETE FROM events WHERE id = ' . (int) $switchedOff);
$database->exec('DELETE FROM events WHERE id = ' . (int) $finished);
forgetActiveEvent();
check('one event left and it is ours', (int) (activeEvent()['id'] ?? 0), $eventId);

/* ------------------------------------------------------------------ *
 * 6. A 0% event features without discounting.
 * ------------------------------------------------------------------ */

heading('a 0% event features a flower and changes no price');

updateEvent($eventId, ['discount_percent' => 0] + $eventValues);
forgetActiveEvent();

check('the event is still on', (int) (activeEvent()['id'] ?? 0), $eventId);
check('and nothing is discounted', (string) fetchProduct($inPromo)['price'], '38.00');
check('with no promo reported', (int) fetchProduct($inPromo)['promo_percent'], 0);
checkTrue('but the featured flower is still there', eventFeaturedProduct() !== null);
check('and it is the right one', (int) eventFeaturedProduct()['id'], $inPromo);

updateEvent($eventId, $eventValues);
forgetActiveEvent();
check('back to 20%', (string) fetchProduct($inPromo)['price'], '30.40');

/* ------------------------------------------------------------------ *
 * 7. A withdrawn featured product disappears rather than being advertised.
 * ------------------------------------------------------------------ */

heading('a withdrawn featured flower is not advertised');

setProductActive($inPromo, false);
forgetActiveEvent();

check('the banner has no flower to show', eventFeaturedProduct(), null);
check('and the event itself is still on', (int) (activeEvent()['id'] ?? 0), $eventId);

setProductActive($inPromo, true);
forgetActiveEvent();
checkTrue('and it comes back when the product does', eventFeaturedProduct() !== null);

/* ------------------------------------------------------------------ *
 * 8. Validation.
 * ------------------------------------------------------------------ */

heading('validateEventInput()');

$good = [
    'name' => 'ZZTest Promo Valid',
    'tagline' => 'Fine.',
    'starts_on' => '2026-02-10',
    'ends_on' => '2026-02-14',
    'discount_percent' => '20',
    'featured_product_id' => (string) $inPromo,
    'is_active' => '1',
];

check('a good event validates', validateEventInput($good)['errors'], []);

$backwards = validateEventInput(['ends_on' => '2026-02-09'] + $good);
checkTrue('an event ending before it starts is refused', $backwards['errors'] !== []);

$notADay = validateEventInput(['starts_on' => '2026-02-30'] + $good);
checkTrue('the 30th of February is refused', $notADay['errors'] !== []);

$tooMuch = validateEventInput(['discount_percent' => '95'] + $good);
checkTrue('95% is refused', $tooMuch['errors'] !== []);

$notANumber = validateEventInput(['discount_percent' => '20%'] + $good);
checkTrue('"20%" is refused rather than read as 20', $notANumber['errors'] !== []);

$blankPercent = validateEventInput(['discount_percent' => ''] + $good);
check('a blank discount is 0 and not an error', $blankPercent['errors'], []);
check('and lands as 0', $blankPercent['values']['discount_percent'], 0);

$noFlower = validateEventInput(['featured_product_id' => ''] + $good);
check('no featured flower is fine', $noFlower['errors'], []);
check('and stores null, not 0', $noFlower['values']['featured_product_id'], null);

$ghostFlower = validateEventInput(['featured_product_id' => '99999999'] + $good);
checkTrue('a featured product that does not exist is refused', $ghostFlower['errors'] !== []);

$dupe = validateEventInput(['name' => 'ZZTest Promo Event'] + $good);
checkTrue('a duplicate name is refused', $dupe['errors'] !== []);

$sameRow = validateEventInput(['name' => 'ZZTest Promo Event'] + $good, $eventId);
check('but an event may keep its own name', $sameRow['errors'], []);

/* ------------------------------------------------------------------ *
 * 9. saveEventProducts() is idempotent and refuses rubbish.
 * ------------------------------------------------------------------ */

heading('saveEventProducts()');

$again = saveEventProducts($eventId, [$inPromo]);
check('re-saving the same list adds nothing', $again['added'], 0);
check('and removes nothing', $again['removed'], 0);

$widened = saveEventProducts($eventId, [$inPromo, $outOfPromo]);
check('adding one adds exactly one', $widened['added'], 1);

$narrowed = saveEventProducts($eventId, [$inPromo]);
check('and removing it removes exactly one', $narrowed['removed'], 1);

$withGhost = saveEventProducts($eventId, [$inPromo, 99999999]);
check('a product id that does not exist is dropped, not fatal', $withGhost['errors'], []);
check('and the real one stays', count(adminEventProductIds($eventId)), 1);

/* ------------------------------------------------------------------ *
 * 10. The cascade.
 * ------------------------------------------------------------------ */

heading('deleting a product takes its promo row and leaves the event');

$doomed = createProduct([
    'name' => 'ZZTest Promo Doomed',
    'kind' => 'flower',
    'description' => 'Created to be deleted.',
    'price' => '20.00',
    'colour' => 'Red',
    'stem_count' => 1,
    'is_active' => 1,
]);
saveEventProducts($eventId, [$inPromo, $doomed]);
check('two products in the promo', count(adminEventProductIds($eventId)), 2);

/*
 * Withdrawn first, because deleteProduct() only accepts a product that is already hidden -- the
 * same guard components and events have, so that no single mis-click and no single forged request
 * can destroy something an order might reference. Forgetting it here made this check fail with the
 * product still present, which looked like a broken cascade and was a refused delete.
 */
setProductActive($doomed, false);
checkTrue('the doomed product deletes once withdrawn', deleteProduct($doomed));

check('the promo row went with the product', count(adminEventProductIds($eventId)), 1);
checkTrue('and the event is still there', adminFetchEvent($eventId) !== null);

/* ------------------------------------------------------------------ *
 * 11. deleteEvent() refuses a live event.
 * ------------------------------------------------------------------ */

heading('deleteEvent() only takes a switched-off event');

check('a live event is refused', deleteEvent($eventId), false);
checkTrue('and is still there', adminFetchEvent($eventId) !== null);

/* ------------------------------------------------------------------ *
 * 12. Over HTTP: the pages, the guard, and the badge.
 * ------------------------------------------------------------------ */

heading('the admin screens, over Apache');

$jarAdmin = tempnam(sys_get_temp_dir(), 'zzevt');
$jarStranger = tempnam(sys_get_temp_dir(), 'zzevt');

$signedIn = signIn($jarAdmin, 'admin@capstone.test', 'Admin123!');
checkTrue('signed in as the shop', $signedIn);

if (!$signedIn) {
    echo "  SKIP  the HTTP section needs the admin account; the rest is skipped\n";
} else {
    $list = request('php/admin/events.php?q=ZZTest+Promo', $jarAdmin);
    check('the events list answers 200', $list['status'], 200);
    checkTrue('and lists our event', str_contains(tableBody($list['body']), 'ZZTest Promo Event'));
    checkTrue('with an On now pill', str_contains(tableBody($list['body']), 'On now'));

    // Scoped to the table body: a page-wide check here could be answered by the filter dropdown.
    $noMatch = request('php/admin/events.php?q=ZZNothingMatchesThisAtAll', $jarAdmin);
    check('a search with no matches still answers 200', $noMatch['status'], 200);
    check('and lists nothing', trim(tableBody($noMatch['body'])), '');
    checkTrue(
        'and does not claim it failed to load',
        !str_contains($noMatch['body'], 'could not load the events')
    );

    // A lone % must match literally rather than matching everything -- the ESCAPE "!" clause.
    $percentSearch = request('php/admin/events.php?q=%25', $jarAdmin);
    check('a lone % answers 200', $percentSearch['status'], 200);
    check('and matches nothing literally', trim(tableBody($percentSearch['body'])), '');

    foreach (['on', 'upcoming', 'finished', 'off'] as $stateFilter) {
        $filtered = request('php/admin/events.php?state=' . $stateFilter, $jarAdmin);
        check("state=$stateFilter answers 200", $filtered['status'], 200);
        checkTrue(
            "state=$stateFilter did not fail to load",
            !str_contains($filtered['body'], 'could not load the events')
        );
    }

    $editForm = request('php/admin/event-edit.php?id=' . $eventId, $jarAdmin);
    check('the edit form answers 200', $editForm['status'], 200);
    checkTrue('carries a CSRF token', str_contains($editForm['body'], 'name="csrf_token"'));
    checkTrue('offers the promo checkboxes', str_contains($editForm['body'], 'name="products[]"'));
    checkTrue('and previews the promo price', str_contains($editForm['body'], '30.40'));
    checkTrue('while still showing the list price', str_contains($editForm['body'], '38.00'));

    $missing = request('php/admin/event-edit.php?id=99999999', $jarAdmin);
    check('an event that does not exist is a real 404', $missing['status'], 404);

    heading('the guard and the token');

    $asStranger = request('php/admin/events.php', $jarStranger);
    checkTrue('a signed-out stranger is turned away', $asStranger['status'] !== 200 || str_contains($asStranger['body'], 'Sign in'));

    $forged = request('php/admin/events.php', $jarAdmin, ['action' => 'off', 'id' => (string) $eventId], false);
    checkTrue('a POST with no token is refused', $forged['status'] !== 200 || str_contains($forged['body'], 'expired'));

    // And the thing it would have changed is unchanged, which is the failure worth catching: a 403
    // that still writes is worse than no 403 at all.
    check('and the event is still switched on', (int) adminFetchEvent($eventId)['is_active'], 1);

    $wrongToken = request('php/admin/events.php', $jarAdmin, [
        'csrf_token' => 'nonsense',
        'action' => 'off',
        'id' => (string) $eventId,
    ], false);
    checkTrue('a POST with a wrong token is refused', $wrongToken['status'] !== 200 || str_contains($wrongToken['body'], 'expired'));
    check('and the event is STILL switched on', (int) adminFetchEvent($eventId)['is_active'], 1);

    heading('the customer-facing badge and banner');

    $catalogue = request('php/products.php?q=ZZTest+Promo', $jarStranger);
    check('the catalogue answers 200', $catalogue['status'], 200);
    checkTrue('shows the promo badge', str_contains($catalogue['body'], '20% off'));
    checkTrue('shows the discounted price', str_contains($catalogue['body'], '30.40'));
    checkTrue('and strikes through the old one', str_contains($catalogue['body'], '<s class="product-card-was"'));

    $productPage = request('php/product.php?id=' . $inPromo, $jarStranger);
    check('the product page answers 200', $productPage['status'], 200);
    checkTrue('names the event', str_contains($productPage['body'], 'ZZTest Promo Event'));
    checkTrue('says the price is held', str_contains($productPage['body'], 'price is held'));

    $otherProductPage = request('php/product.php?id=' . $outOfPromo, $jarStranger);
    checkTrue(
        'a product outside the promo shows no badge',
        !str_contains($otherProductPage['body'], 'promo-pill')
    );

    $home = request('php/index.php', $jarStranger);
    check('the home page answers 200', $home['status'], 200);
    checkTrue('draws the event banner', str_contains($home['body'], 'event-banner'));
    checkTrue('names the event on it', str_contains($home['body'], 'ZZTest Promo Event'));
    checkTrue('and its tagline', str_contains($home['body'], 'A fixture occasion.'));
}

/* ------------------------------------------------------------------ *
 * Put the shop back.
 * ------------------------------------------------------------------ */

heading('cleanup');

sweep($database);

foreach ($before as $table => $expected) {
    $actual = (int) $database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    check("$table is back to $expected", $actual, $expected);
}

$leftover = (int) $database->query(
    "SELECT (SELECT COUNT(*) FROM products WHERE name LIKE 'ZZTest%')
          + (SELECT COUNT(*) FROM events WHERE name LIKE 'ZZTest%')
          + (SELECT COUNT(*) FROM cart_items WHERE name LIKE 'ZZTest%')
          + (SELECT COUNT(*) FROM orders WHERE contact_name LIKE 'ZZTest%')"
)->fetchColumn();
check('nothing ZZTest left anywhere this suite writes', $leftover, 0);

check('no PHP notices or warnings', $notices, []);

printf("\n%d checks, %d FAILED\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
