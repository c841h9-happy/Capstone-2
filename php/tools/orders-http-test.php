<?php

declare(strict_types=1);

/**
 * Checkout, orders and payments, over Apache, with real cookies and real sessions.
 *
 * php/tools/orders-unit.php already proves the arithmetic and the validation in isolation. This is
 * everything that only exists once a real request is involved: the sign-in wall on the checkout,
 * the guard on php/admin/, the redirect a placed order answers with, the basket being emptied in
 * the same transaction as the order being written, one customer being unable to read another
 * customer's order, and -- since the Orders module -- an order being walked from placed to
 * delivered with a history row and a message behind each move.
 *
 *     C:\xampp\php\php.exe php\tools\orders-http-test.php
 *
 * Creates its own products and its own three accounts, all prefixed ZZTest, and deletes them and
 * everything they made at the end. It never writes to a seeded row -- including the seeded admin,
 * which is why this suite makes an admin of its own rather than borrowing that one.
 *
 * MAIL_TRANSPORT is 'log', so the messages this triggers land in MAIL_LOG_FILE. Only the bytes
 * appended after the suite started are read and none of them are ever printed: that file also
 * holds live two-factor codes written by other runs.
 */

// Same guard as the tools beside this one. This file is under the webroot, so without it a stranger
// could run the suite -- which creates accounts, places orders and deletes rows.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/*
 * Apache's conditions, set before the first basePath() call caches its answer -- the same line
 * php/tools/orders-unit.php opens with, and needed here for the mail assertions.
 *
 * Under CLI, DOCUMENT_ROOT is unset and realpath('') on Windows hands back the working directory
 * rather than false, so basePath() would quietly resolve against wherever this was run from: the
 * project root gives "/" and a folder above it gives something else again. siteUrl() is used below
 * to build the link a message is expected to carry, so without this the suite's idea of that link
 * would depend on the shell's current directory.
 */
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';

require_once __DIR__ . '/../config/database.php';
// For ORDER_NOTES_MAX and ORDER_REFERENCE_ALPHABET, so the assertions below track the constants
// rather than restating them. Nothing else here calls into config/ -- everything under test is
// reached over HTTP, because half of what is being tested is the HTTP layer itself.
require_once __DIR__ . '/../config/orders.php';
// Same reason, for the mail assertions: MAIL_LOG_FILE is where 'log' transport writes, and a suite
// that hard-coded that path would keep passing after somebody moved it.
require_once __DIR__ . '/../config/orders-mail.php';

const BASE = 'http://localhost/Capstone/';

$passed = 0;
$failed = 0;
$failures = [];

/*
 * Assertions this run deliberately did not make, and why.
 *
 * Separate from failures on purpose: a skip is not a pass and is not a failure, and the one thing a
 * summary must never do is let an un-made assertion look like a made one. Printed in full beside
 * the counts.
 */
$skipped = 0;
$skips = [];

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
 * it pointed.
 *
 * @param array<string, string> $jar
 * @param array<string, string>|null $post
 * @return array{status:int, headers:array<int, string>, body:string, location:?string}
 */
/**
 * This browser's CSRF token, fetched the way a browser gets one: off a page carrying a form.
 *
 * php/login.php because it is the one page that renders a form for everybody -- signed in or not,
 * admin or customer, it draws both panels, so one address works for every jar in this file.
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
     * than at the call sites below, none of which are about CSRF: this suite asserts what the two
     * admin screens do, and php/tools/csrf-http-test.php asserts what happens without a token.
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

/**
 * Make this browser one the account already trusts.
 *
 * Sign-in is two steps -- the password, then a six-digit code -- unless the browser has been
 * trusted before. That code is emailed, which on this install means written to a log file, so a
 * suite that went through it would have to read a live verification code out of a file to do its
 * job. Minting a device token and storing only its hash reaches the same signed-in state with no
 * secret involved. Copied from php/tools/cart-http-test.php, deliberately unchanged.
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
        'label' => 'ZZTest orders suite',
    ]);

    $jar['capstone_device'] = $token;
}

/**
 * Sign in, carrying the jar.
 *
 * The form field is `identifier`, not `email`: login.php takes either a username or an address in
 * one box.
 *
 * @param array<string, string> $jar
 */
function signIn(array &$jar, string $identifier, string $password): array
{
    // Fetched first so the session exists before it is posted to.
    request('php/login.php', $jar);

    return request('php/login.php', $jar, [
        'form_type' => 'login',
        'identifier' => $identifier,
        'password' => $password,
    ]);
}

/** Put a catalogue product in this browser's basket. */
function addProduct(array &$jar, int $productId, int $quantity): array
{
    return request('php/cart-update.php', $jar, [
        'action' => 'add-product',
        'product_id' => (string) $productId,
        'quantity' => (string) $quantity,
        'back' => '/Capstone/php/cart.php',
    ]);
}

/** Put a made-up bouquet in it, so the order gets a line with parts hanging off it. */
function addDesign(array &$jar, string $design): array
{
    return request('php/cart-update.php', $jar, [
        'action' => 'add-design',
        'design' => $design,
        'back' => '/Capstone/php/cart.php',
    ]);
}

/** The reference out of a Location header, or '' if there is not one in it. */
function refFromLocation(?string $location): string
{
    return $location !== null && preg_match('/ref=([A-Za-z0-9%\-]+)/', $location, $m) === 1
        ? rawurldecode($m[1])
        : '';
}

/** One order row, straight from the database, so the page is never asked to confirm itself. */
function orderRow(string $reference): array|false
{
    global $database;

    $statement = $database->prepare('SELECT * FROM orders WHERE reference = :reference');
    $statement->execute(['reference' => $reference]);

    return $statement->fetch();
}

/**
 * One order's history rows, oldest first, joined through the reference this suite deals in.
 *
 * Read straight from the table rather than scraped off the timeline, for the reason orderRow()
 * exists: a page that drew the right dots from the wrong rows would pass a test that only looked
 * at the page, and the rows are what the shop will still have in a year.
 *
 * @return array<int, array<string, mixed>>
 */
function eventsFor(string $reference): array
{
    global $database;

    $statement = $database->prepare(
        'SELECT e.status, e.actor, e.actor_user_id
           FROM order_events e
           JOIN orders o ON o.id = e.order_id
          WHERE o.reference = :reference
          ORDER BY e.id ASC'
    );
    $statement->execute(['reference' => $reference]);

    return $statement->fetchAll();
}

/** Just the statuses of those rows, for asserting a whole history in one line. */
function eventStatuses(string $reference): array
{
    return array_map(static fn (array $row): string => (string) $row['status'], eventsFor($reference));
}

/**
 * The labels the timeline on a page is drawing, in order.
 *
 * The actor span is stripped out first because it lives *inside* the label span on
 * php/admin/order.php: a non-greedy match for the label would otherwise stop at the actor's
 * closing tag rather than the label's, and every admin assertion would read "Placed by the
 * customer" for reasons that have nothing to do with what the dot says. Whitespace is collapsed
 * for the same reason -- the markup wraps.
 */
function timelineOn(string $html): array
{
    $html = preg_replace('#<span class="order-timeline-who">.*?</span>#s', '', $html) ?? $html;

    preg_match_all('#<span class="order-timeline-label">(.*?)</span>#s', $html, $m);

    return array_map('collapseText', $m[1]);
}

/** Who each dot says moved it. Only php/admin/order.php draws these, which is itself asserted. */
function timelineActorsOn(string $html): array
{
    preg_match_all('#<span class="order-timeline-who">(.*?)</span>#s', $html, $m);

    return array_map('collapseText', $m[1]);
}

/** Markup as a person reads it: tags gone, runs of whitespace down to one space, entities decoded. */
function collapseText(string $fragment): string
{
    return html_entity_decode(
        trim((string) preg_replace('/\s+/', ' ', strip_tags($fragment))),
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * The references a list page is showing, in the order it shows them.
 *
 * Read out of the hrefs, not out of the page text, and that is not fussiness: the admin queue
 * echoes the search term back into its own search box, so a page that found nothing at all still
 * has the reference printed on it in a value attribute. Matching the text would make every "search
 * finds it by reference" assertion pass against an empty table. Both list pages link each row at
 * php/order.php?ref= or php/admin/order.php?ref=, so the links are the rows.
 */
function referencesOn(string $html): array
{
    preg_match_all('/ref=(SLJ-\d{8}-[' . ORDER_REFERENCE_ALPHABET . ']{4})\b/', $html, $m);

    return array_values(array_unique($m[1]));
}

/** The figure in the total row, as printed. */
function totalShown(string $html): ?string
{
    return preg_match('/class="cart-total-figure">([^<]+)</', $html, $m) === 1
        ? html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8')
        : null;
}

/** Everything a toast said on this page, decoded, so a message can be asserted on. */
function toastText(string $html): string
{
    return preg_match_all('/class="toast-message">([^<]*)</', $html, $m) > 0
        ? html_entity_decode(implode(' | ', $m[1]), ENT_QUOTES, 'UTF-8')
        : '';
}

/**
 * Post the checkout form, filling in everything that is not overridden.
 *
 * A base of one valid delivery order, because most of what is being tested is one field at a time
 * being wrong -- and a suite that writes out twelve fields per case hides which one is the subject.
 *
 * @param array<string, string> $jar
 * @param array<string, string> $overrides
 */
function checkout(array &$jar, array $overrides = []): array
{
    return request('php/checkout.php', $jar, $overrides + [
        'fulfilment' => 'delivery',
        'payment_method' => 'cod',
        'payment_reference' => '',
        'contact_name' => 'ZZTest Buyer',
        'contact_phone' => '0917 555 0101',
        'address_line' => '12 ZZTest Street',
        'barangay' => 'Poblacion',
        'city' => 'Cebu City',
        'province' => 'Cebu',
        'postcode' => '6000',
        'notes' => '',
    ]);
}

$database = getDatabaseConnection();

/* ------------------------------------------------------------------ *
 * Fixtures. Two products, four accounts, all this suite's own.
 * ------------------------------------------------------------------ */

$database->exec("DELETE FROM users WHERE email LIKE 'zztest.orders%'");
$database->exec("DELETE FROM products WHERE name LIKE 'ZZTest%'");

$database->prepare(
    "INSERT INTO products (name, description, price, stem_count, colour, is_active)
     VALUES ('ZZTest Order Bouquet', 'Fixture for the orders suite.', 250.00, 6, 'Test', 1)"
)->execute();
$productId = (int) $database->lastInsertId();

$database->prepare(
    "INSERT INTO products (name, description, price, stem_count, colour, is_active)
     VALUES ('ZZTest Doomed Stem', 'Withdrawn mid-suite on purpose.', 130.00, 1, 'Test', 1)"
)->execute();
$doomedId = (int) $database->lastInsertId();

$password = 'ZzTestOrders123!';
$hash = password_hash($password, PASSWORD_DEFAULT);

/*
 * email_verified_at is set on all four: login.php sends an unverified account off to finish
 * signing up rather than signing it in, and every check below would then be testing a visitor who
 * never got in.
 */
$makeAccount = static function (string $name, string $email, string $role) use ($database, $hash): int {
    $statement = $database->prepare(
        'INSERT INTO users (name, email, password_hash, role, email_verified_at)
         VALUES (:name, :email, :hash, :role, NOW())'
    );
    $statement->execute(['name' => $name, 'email' => $email, 'hash' => $hash, 'role' => $role]);

    return (int) $database->lastInsertId();
};

$buyerId = $makeAccount('ZZTest Buyer', 'zztest.orders.buyer@capstone.test', 'customer');
$otherId = $makeAccount('ZZTest Other', 'zztest.orders.other@capstone.test', 'customer');
$adminId = $makeAccount('ZZTest Admin', 'zztest.orders.admin@capstone.test', 'admin');
// A second member of staff, for the one test that needs two people pressing the same button at the
// same instant. Idle until then.
$adminTwoId = $makeAccount('ZZTest Admin Two', 'zztest.orders.admin2@capstone.test', 'admin');

echo "fixtures: product $productId, doomed $doomedId, buyer $buyerId, other $otherId, admin $adminId, admin2 $adminTwoId\n\n";

$ordersBefore = (int) $database->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$eventsBefore = (int) $database->query('SELECT COUNT(*) FROM order_events')->fetchColumn();

/*
 * Where the mail log had got to before this run. Everything the suite asserts about messages is
 * read from the bytes appended after this mark and nowhere else, because that file also holds live
 * two-factor codes from other runs -- reading the whole thing would put somebody's sign-in code in
 * this script's memory for no reason. Nothing from it is ever printed.
 */
$mailLogBefore = is_file(MAIL_LOG_FILE) ? (int) filesize(MAIL_LOG_FILE) : 0;

/* ------------------------------------------------------------------ *
 * The sign-in wall, and the admin folder's only lock.
 * ------------------------------------------------------------------ */

$stranger = [];

/*
 * requireLogin() has no return-to, so all four of these land on login.php flat. That is asserted
 * rather than worked around: it is the behaviour php/cart.php's "Sign in to check out" link exists
 * because of, and if it ever gains a return-to, this is the test that should be updated alongside.
 */
foreach (['php/checkout.php', 'php/orders.php', 'php/order.php?ref=SLJ-20260101-AAAA'] as $path) {
    $response = request($path, $stranger);
    check("guard: $path redirects a stranger", $response['status'], 302);
    checkTrue("guard: $path sends them to sign in", str_contains((string) $response['location'], 'login.php'));
}

$response = request('php/order-cancel.php', $stranger, ['ref' => 'SLJ-20260101-AAAA']);
check('guard: a stranger cannot cancel anything', $response['status'], 302);
checkTrue('guard: and is sent to sign in', str_contains((string) $response['location'], 'login.php'));

/*
 * php/admin/ has no server-side rule of its own -- Apache serves it like any other folder -- so
 * these two are the whole of its protection, and both new pages are checked the way
 * php/admin/components.php was.
 */
foreach (['php/admin/orders.php', 'php/admin/order.php?ref=SLJ-20260101-AAAA'] as $path) {
    $response = request($path, $stranger);
    check("guard: $path redirects a stranger", $response['status'], 302);
    checkTrue("guard: $path sends them to sign in", str_contains((string) $response['location'], 'login.php'));
}

// A signed-in customer is a different refusal: they are logged in, they are simply not allowed.
$buyer = [];
trustDevice($buyer, $buyerId);
$response = signIn($buyer, 'zztest.orders.buyer@capstone.test', $password);
check('sign-in: the buyer gets in', $response['status'], 302);
checkTrue('sign-in: and is not sent back to the login form', !str_contains((string) $response['location'], 'login.php'));

check('guard: a customer is refused the order queue', request('php/admin/orders.php', $buyer)['status'], 403);
check('guard: a customer is refused one order', request('php/admin/order.php?ref=SLJ-20260101-AAAA', $buyer)['status'], 403);

// And the POST side of both, which is where the damage would be. A guard that only covers GET is
// not a guard.
check(
    'guard: a customer cannot post an action to the queue',
    request('php/admin/orders.php', $buyer, ['action' => 'pay', 'ref' => 'SLJ-20260101-AAAA'])['status'],
    403
);
check(
    'guard: a stranger cannot post an action either',
    request('php/admin/orders.php', $stranger, ['action' => 'pay', 'ref' => 'SLJ-20260101-AAAA'])['status'],
    302
);

/* ------------------------------------------------------------------ *
 * An empty basket has nothing to check out.
 * ------------------------------------------------------------------ */

$response = request('php/checkout.php', $buyer);
check('empty: checkout bounces an empty basket', $response['status'], 302);
checkTrue('empty: back to the basket', str_contains((string) $response['location'], 'cart.php'));

check(
    'empty: and posting to it places nothing',
    (int) $database->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    $ordersBefore
);

/* ------------------------------------------------------------------ *
 * Placing one. Delivery, paid by GCash reference.
 * ------------------------------------------------------------------ */

addProduct($buyer, $productId, 2);
addDesign($buyer, 'count=6&arrangement=loose&bloom=1&wire=7');

$cartId = (int) $database->query("SELECT id FROM carts WHERE user_id = $buyerId")->fetchColumn();
checkTrue('place: the buyer has a basket', $cartId > 0);
check(
    'place: with two lines in it',
    (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = $cartId")->fetchColumn(),
    2
);

$cartTotal = totalShown(request('php/cart.php', $buyer)['body']);

$page = request('php/checkout.php', $buyer)['body'];
checkTrue('place: the form is there', str_contains($page, 'id="checkout-form"'));
checkTrue('place: the name box is filled in from the account', str_contains($page, 'value="ZZTest Buyer"'));
check('place: and it shows the same total as the basket', totalShown($page), $cartTotal);

$response = checkout($buyer, [
    'payment_method' => 'gcash',
    'payment_reference' => 'ZZTESTGC0001',
    'notes' => "Leave it with the guard.\nRing first.",
]);

check('place: answers with a redirect', $response['status'], 302);
$reference = refFromLocation($response['location']);
checkTrue('place: to an order page carrying a reference', $reference !== '');
checkTrue(
    'place: and the reference is the shape this shop issues',
    preg_match('/^SLJ-\d{8}-[' . ORDER_REFERENCE_ALPHABET . ']{4}$/', $reference) === 1
);

$order = orderRow($reference);
checkTrue('place: the row is there', $order !== false);
check('place: it belongs to the buyer', (int) $order['user_id'], $buyerId);
check('place: placed, not anything else', $order['status'], 'placed');
check('place: a transfer with a reference is waiting to be checked', $order['payment_status'], 'submitted');
check('place: the method is kept', $order['payment_method'], 'gcash');
check('place: and so is what they typed', $order['payment_reference'], 'ZZTESTGC0001');
check('place: delivery', $order['fulfilment'], 'delivery');
check('place: the address is kept', $order['address_line'], '12 ZZTest Street');
check('place: the note keeps its line break', $order['notes'], "Leave it with the guard.\nRing first.");
// Never from the form. The email the receipt belongs to is the one the shop already knows.
check('place: the email comes from the account', $order['contact_email'], 'zztest.orders.buyer@capstone.test');

$orderId = (int) $order['id'];

check(
    'place: both lines were copied',
    (int) $database->query("SELECT COUNT(*) FROM order_items WHERE order_id = $orderId")->fetchColumn(),
    2
);
check(
    'place: and the designed line kept its parts',
    (int) $database->query(
        "SELECT COUNT(*) FROM order_item_parts p
           JOIN order_items i ON i.id = p.item_id
          WHERE i.order_id = $orderId"
    )->fetchColumn() > 0,
    true
);

/*
 * The basket is emptied in the same transaction. The row itself is kept -- a customer who orders
 * and then adds something else should be adding to the basket they already have, not minting a
 * second one, and the cookie in their browser still names this one.
 */
check(
    'place: the basket was emptied',
    (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = $cartId")->fetchColumn(),
    0
);
check(
    'place: but the basket itself is kept',
    (int) $database->query("SELECT COUNT(*) FROM carts WHERE id = $cartId")->fetchColumn(),
    1
);

// The money. The form carries no total at all, and this is the proof that it does not need to.
$lineSum = (string) $database->query("SELECT SUM(line_total) FROM order_items WHERE order_id = $orderId")->fetchColumn();
check('place: the order total is the sum of its lines', (float) $order['total'], (float) $lineSum);
check('place: and it is what the basket said', totalShown(request('php/order.php?ref=' . rawurlencode($reference), $buyer)['body']), $cartTotal);

/* ------------------------------------------------------------------ *
 * Every way the form can be wrong.
 * ------------------------------------------------------------------ */

/*
 * Each case refills the basket, posts one bad field, and asserts that nothing was written. Counting
 * the orders is what makes these real: a page that redisplays its form while quietly placing the
 * order anyway would pass every "the page says so" assertion on its own.
 */
$badCases = [
    'no name' => [['contact_name' => ''], 'name of the person'],
    'name too long' => [['contact_name' => str_repeat('z', 101)], 'up to 100 characters'],
    'no phone' => [['contact_phone' => ''], 'phone number we can reach'],
    'phone with too few digits' => [['contact_phone' => '0917'], 'phone number we can reach'],
    'phone too long' => [['contact_phone' => str_repeat('9', 31)], 'too long'],
    'no street' => [['address_line' => ''], 'house or unit number'],
    'no barangay' => [['barangay' => ''], 'Enter the barangay'],
    'no city' => [['city' => ''], 'city or municipality'],
    'no province' => [['province' => ''], 'Enter the province'],
    'postcode too long' => [['postcode' => str_repeat('1', 11)], 'postcode is too long'],
    'unknown fulfilment' => [['fulfilment' => 'teleport'], 'deliver your order or you collect'],
    'no fulfilment at all' => [['fulfilment' => ''], 'deliver your order or you collect'],
    'unknown payment method' => [['payment_method' => 'bitcoin'], 'Choose how you would like to pay'],
    'transfer with no reference' => [['payment_method' => 'gcash', 'payment_reference' => ''], 'reference number from your receipt'],
    'bank with no reference' => [['payment_method' => 'bank', 'payment_reference' => ''], 'reference number from your receipt'],
    'reference too long' => [
        ['payment_method' => 'gcash', 'payment_reference' => str_repeat('R', ORDER_REFERENCE_MAX + 1)],
        'reference is too long',
    ],
    'note too long' => [['notes' => str_repeat('n', ORDER_NOTES_MAX + 1)], 'under ' . ORDER_NOTES_MAX],
    // An array where a string belongs. orderTextLine() is what stops this being a fatal, and a
    // fatal here would be a 500 on a page holding somebody's address.
    'an array for a field' => [['contact_name' => ['a', 'b']], 'name of the person'],
];

foreach ($badCases as $label => [$override, $expected]) {
    addProduct($buyer, $productId, 1);
    $countBefore = (int) $database->query('SELECT COUNT(*) FROM orders')->fetchColumn();

    $response = checkout($buyer, $override);

    check("bad ($label): the form comes back rather than redirecting", $response['status'], 200);
    checkTrue("bad ($label): and says what was wrong", str_contains(toastText($response['body']), $expected));
    check(
        "bad ($label): nothing was placed",
        (int) $database->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
        $countBefore
    );
    checkTrue(
        "bad ($label): the basket is untouched",
        (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE cart_id = $cartId")->fetchColumn() > 0
    );

    $database->exec("DELETE FROM cart_items WHERE cart_id = $cartId");
}

/*
 * What was typed comes back in the boxes. Twelve fields is too many to ask anybody to enter twice,
 * and the one exception is deliberate: a pickup has had its address wiped for the order, but the
 * boxes keep what was typed in case the customer changes their mind back.
 */
addProduct($buyer, $productId, 1);
$body = checkout($buyer, [
    'contact_name' => '',
    'contact_phone' => '0917 555 0999',
    'city' => 'ZZTest City',
])['body'];
checkTrue('bad: the phone is still in the box', str_contains($body, 'value="0917 555 0999"'));
checkTrue('bad: and so is the city', str_contains($body, 'value="ZZTest City"'));

$body = checkout($buyer, [
    'fulfilment' => 'pickup',
    'contact_name' => '',
    'address_line' => '99 Changed My Mind Street',
])['body'];
checkTrue('bad: a pickup keeps the typed address in the box', str_contains($body, 'value="99 Changed My Mind Street"'));

/*
 * The form carries no money, so there is nothing to tamper with -- and this is the assertion that
 * says so. A posted total is simply not read: the server adds the lines up again at placement.
 */
$response = checkout($buyer, ['total' => '1.00', 'amount' => '1.00', 'price' => '1.00']);
check('tamper: the order is still placed', $response['status'], 302);
$tampered = orderRow(refFromLocation($response['location']));
checkTrue('tamper: and the total is the real one, not the posted one', (float) $tampered['total'] > 1.0);
check(
    'tamper: it is the sum of the lines',
    (float) $tampered['total'],
    (float) $database->query('SELECT SUM(line_total) FROM order_items WHERE order_id = ' . (int) $tampered['id'])->fetchColumn()
);

/* ------------------------------------------------------------------ *
 * Collection, paid in cash.
 * ------------------------------------------------------------------ */

addProduct($buyer, $productId, 1);
$response = checkout($buyer, [
    'fulfilment' => 'pickup',
    'payment_method' => 'cod',
    // Filled in on purpose. The customer changed their mind after typing them, and the order must
    // not be stored carrying an address nobody is going to use.
    'address_line' => '12 ZZTest Street',
    'barangay' => 'Poblacion',
    'city' => 'Cebu City',
    'province' => 'Cebu',
    'postcode' => '6000',
    'payment_reference' => 'SHOULD-NOT-BE-KEPT',
]);

check('pickup: placed', $response['status'], 302);
$pickupRef = refFromLocation($response['location']);
$pickup = orderRow($pickupRef);
check('pickup: recorded as a collection', $pickup['fulfilment'], 'pickup');
check('pickup: the street was dropped', $pickup['address_line'], '');
check('pickup: the barangay too', $pickup['barangay'], '');
check('pickup: the city too', $pickup['city'], '');
check('pickup: the province too', $pickup['province'], '');
check('pickup: and the postcode', $pickup['postcode'], '');
// Cash has nothing to reference, so a reference left on one is a number nobody will look at sitting
// exactly where the shop expects to find one.
check('pickup: cash keeps no payment reference', $pickup['payment_reference'], '');
check('pickup: cash is simply not paid yet', $pickup['payment_status'], 'unpaid');

$body = request('php/order.php?ref=' . rawurlencode($pickupRef), $buyer)['body'];
checkTrue('pickup: the order page says collection', str_contains($body, 'Collect'));
checkTrue('pickup: and prints no address', str_contains($body, '12 ZZTest Street') === false);

/* ------------------------------------------------------------------ *
 * A line withdrawn between filling the basket and pressing the button.
 * ------------------------------------------------------------------ */

addProduct($buyer, $doomedId, 1);
addProduct($buyer, $productId, 1);
$database->exec("UPDATE products SET is_active = 0 WHERE id = $doomedId");

$countBefore = (int) $database->query('SELECT COUNT(*) FROM orders')->fetchColumn();

/*
 * The checkout refuses before it asks for an address, not after. Somebody who has typed twelve
 * fields and pressed the button should not be the one to find out the shop cannot sell them one of
 * the things in their basket.
 */
$response = request('php/checkout.php', $buyer);
check('withdrawn: the checkout page itself bounces', $response['status'], 302);
checkTrue('withdrawn: back to the basket', str_contains((string) $response['location'], 'cart.php'));

// And posting straight past that page is refused by placeOrder() too, so the guard is not the
// page's alone.
$response = checkout($buyer);
checkTrue('withdrawn: posting anyway is refused', $response['status'] === 200 || $response['status'] === 302);
check(
    'withdrawn: and no order was placed',
    (int) $database->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    $countBefore
);

$database->exec("UPDATE products SET is_active = 1 WHERE id = $doomedId");
$database->exec("DELETE FROM cart_items WHERE cart_id = $cartId");

/* ------------------------------------------------------------------ *
 * A product deleted out from under an order that already exists.
 * ------------------------------------------------------------------ */

/*
 * The case the schema was built for and that no suite had ever run. order_items.product_id is
 * ON DELETE SET NULL precisely so a line outlives its product: the name and unit_price were frozen
 * when the order was placed, and those are what the receipt is made of. The link back is a trace.
 *
 * It is also the case that proved the old CHECK constraint wrong. That constraint read
 * "(kind = 'product' AND product_id IS NOT NULL) OR (kind = 'design')", which the null written
 * below violates -- and MariaDB 10.4 let the delete through anyway, because it does not evaluate
 * CHECK constraints for changes made by a referential action, so the table was left holding a row
 * it claimed could not exist. sql/kind-check-upgrade.sql replaced it with
 * "kind = 'product' OR product_id IS NULL", which this delete satisfies. The customer-facing
 * assertions below would have passed under either one; what changed is that the table is now
 * telling the truth about them, which is the last check in this section.
 *
 * The product is deleted here rather than hidden because hiding is covered by the section above
 * and does not touch product_id at all.
 */
addProduct($buyer, $doomedId, 1);

$response = checkout($buyer);
check('deleted product: an order is placed first', $response['status'], 302);

$doomedRef = refFromLocation($response['location']);
$doomedRow = orderRow($doomedRef);
$doomedOrderId = (int) ($doomedRow['id'] ?? 0);
$doomedTotal = $doomedRow['total'] ?? '';

$lineOf = static function (int $orderId) use (&$database): array|false {
    return $database->query(
        "SELECT kind, product_id, name, unit_price, quantity, line_total
           FROM order_items
          WHERE order_id = $orderId AND name = 'ZZTest Doomed Stem'"
    )->fetch(PDO::FETCH_ASSOC);
};

$before = $lineOf($doomedOrderId);

checkTrue('deleted product: the line is on the order', is_array($before));
check('deleted product: pointing at the product it was sold from', (int) $before['product_id'], $doomedId);

$database->exec("DELETE FROM products WHERE id = $doomedId");

check(
    'deleted product: the product is really gone',
    (int) $database->query("SELECT COUNT(*) FROM products WHERE id = $doomedId")->fetchColumn(),
    0
);

$after = $lineOf($doomedOrderId);

checkTrue('deleted product: the order line survived it', is_array($after));
checkTrue('deleted product: the link back was nulled', $after['product_id'] === null);
checkTrue('deleted product: but it is still a product line', $after['kind'] === 'product');
check('deleted product: with the name it was sold under', $after['name'], $before['name']);
check('deleted product: and the price it was sold at', $after['unit_price'], $before['unit_price']);
check('deleted product: and the same line total', $after['line_total'], $before['line_total']);
check('deleted product: the order total did not move', orderRow($doomedRef)['total'], $doomedTotal);

// The receipt is what the customer comes back to in a year, so it is read over HTTP rather than
// trusted to the columns above -- a page that throws on a null product_id would still have passed
// every check so far.
$response = request('php/order.php?ref=' . rawurlencode($doomedRef), $buyer);
check('deleted product: the order page still loads', $response['status'], 200);
checkTrue('deleted product: and still names the line', str_contains($response['body'], 'ZZTest Doomed Stem'));

// The point of the whole section: the row the referential action just wrote is a row the table
// permits. Asked of every row, not only this one, because a constraint that holds for the case
// under test and not for its neighbours has not been tested.
check(
    'deleted product: no row in order_items violates the constraint it now carries',
    (int) $database->query(
        "SELECT COUNT(*) FROM order_items WHERE NOT (kind = 'product' OR product_id IS NULL)"
    )->fetchColumn(),
    0
);
check(
    'deleted product: nor in cart_items',
    (int) $database->query(
        "SELECT COUNT(*) FROM cart_items WHERE NOT (kind = 'product' OR product_id IS NULL)"
    )->fetchColumn(),
    0
);

/* ------------------------------------------------------------------ *
 * One customer, one set of orders.
 * ------------------------------------------------------------------ */

$other = [];
trustDevice($other, $otherId);
signIn($other, 'zztest.orders.other@capstone.test', $password);

$response = request('php/order.php?ref=' . rawurlencode($reference), $other);
check('privacy: somebody else gets 404, not the order', $response['status'], 404);
checkTrue('privacy: and not a single detail of it', str_contains($response['body'], 'ZZTESTGC0001') === false);

$body = request('php/orders.php', $other)['body'];
check('privacy: their own list does not show it', in_array($reference, referencesOn($body), true), false);

$body = request('php/orders.php', $buyer)['body'];
checkTrue('list: the buyer sees their own order', in_array($reference, referencesOn($body), true));

// A reference that is not a reference. Answered by the same "we could not find that" as a typo,
// with no statement ever run against it.
foreach (['', 'not-a-reference', str_repeat('A', 500), 'SLJ-20260101-OOOO'] as $junk) {
    check(
        'privacy: junk reference "' . substr($junk, 0, 12) . '" is a 404',
        request('php/order.php?ref=' . rawurlencode($junk), $buyer)['status'],
        404
    );
}

/* ------------------------------------------------------------------ *
 * Cancelling, from the customer's side.
 * ------------------------------------------------------------------ */

// Somebody else's order, by reference, straight at the endpoint.
$response = request('php/order-cancel.php', $other, ['ref' => $reference]);
check('cancel: another customer is answered with a redirect', $response['status'], 302);
check('cancel: and the order is untouched', orderRow($reference)['status'], 'placed');

// A GET, which is what a bookmarked or prefetched URL would be. A cancellation must never be
// something a link can do.
$response = request('php/order-cancel.php?ref=' . rawurlencode($reference), $buyer);
check('cancel: a GET does nothing', $response['status'], 302);
checkTrue('cancel: and goes to the order list', str_contains((string) $response['location'], 'orders.php'));
check('cancel: the order is still placed', orderRow($reference)['status'], 'placed');

// The real thing, by its owner.
$response = request('php/order-cancel.php', $buyer, ['ref' => $pickupRef]);
check('cancel: the owner can cancel', $response['status'], 302);
check('cancel: and it is cancelled', orderRow($pickupRef)['status'], 'cancelled');

// Twice. The second is refused by the UPDATE, not by a read beforehand.
$response = request('php/order-cancel.php', $buyer, ['ref' => $pickupRef]);
checkTrue(
    'cancel: cancelling again is refused',
    str_contains(toastText(request('php/order.php?ref=' . rawurlencode($pickupRef), $buyer)['body']), 'could not cancel')
);

$body = request('php/order.php?ref=' . rawurlencode($pickupRef), $buyer)['body'];
checkTrue('cancel: the page says cancelled', str_contains($body, 'Cancelled'));
checkTrue('cancel: and offers no button to do it again', str_contains($body, 'order-cancel.php') === false);

/* ------------------------------------------------------------------ *
 * The queue, from the shop's side.
 * ------------------------------------------------------------------ */

$admin = [];
trustDevice($admin, $adminId);
$response = signIn($admin, 'zztest.orders.admin@capstone.test', $password);
check('admin: signs in', $response['status'], 302);

$queue = request('php/admin/orders.php', $admin);
check('admin: the queue opens', $queue['status'], 200);
checkTrue('admin: and the open order is on it', in_array($reference, referencesOn($queue['body']), true));

/*
 * The default view is a filter, and this is what makes that visible: a cancelled order is not work,
 * so it is not in the queue somebody opens the page to work through. It is still findable, which is
 * the next assertion.
 */
check('admin: a cancelled order is not in the default view', in_array($pickupRef, referencesOn($queue['body']), true), false);
checkTrue(
    'admin: but "all" finds it',
    in_array($pickupRef, referencesOn(request('php/admin/orders.php?status=all', $admin)['body']), true)
);
checkTrue(
    'admin: and so does the cancelled filter',
    in_array($pickupRef, referencesOn(request('php/admin/orders.php?status=cancelled', $admin)['body']), true)
);
check(
    'admin: which then excludes the open one',
    in_array($reference, referencesOn(request('php/admin/orders.php?status=cancelled', $admin)['body']), true),
    false
);

// The payment filter, which is the one the page exists for.
$waiting = referencesOn(request('php/admin/orders.php?status=all&payment=submitted', $admin)['body']);
checkTrue('admin: the GCash order is waiting to be checked', in_array($reference, $waiting, true));
check('admin: the cash one is not', in_array($pickupRef, $waiting, true), false);

// Junk in a filter falls back rather than erroring. These are query strings people edit by hand.
foreach (['status=teleport', 'payment=maybe', 'page=0', 'page=-5', 'page=abc', 'q[]=x', 'status[]=open'] as $junk) {
    check("admin: ?$junk still renders", request('php/admin/orders.php?' . $junk, $admin)['status'], 200);
}

/*
 * Search, over all four columns. The payment reference is the one that matters: matching a transfer
 * in a GCash app to an order in this list is the entire job the box was added for.
 */
$searches = [
    'the reference' => $reference,
    'the customer name' => 'ZZTest Buyer',
    'the phone number' => '555 0101',
    'the payment reference' => 'ZZTESTGC0001',
];

foreach ($searches as $label => $term) {
    $found = referencesOn(request('php/admin/orders.php?status=all&q=' . rawurlencode($term), $admin)['body']);
    checkTrue("admin: search finds it by $label", in_array($reference, $found, true));
}

// A wildcard typed into the box is a character to search for, not a wildcard. Left unescaped, this
// one matches every order in the table.
$found = referencesOn(request('php/admin/orders.php?status=all&q=' . rawurlencode('%'), $admin)['body']);
check('admin: a percent sign is searched for, not obeyed', in_array($reference, $found, true), false);

/* ------------------------------------------------------------------ *
 * Mark paid, take it back, cancel.
 * ------------------------------------------------------------------ */

$response = request('php/admin/orders.php', $admin, ['action' => 'pay', 'ref' => $reference]);
check('pay: answered with a redirect', $response['status'], 302);
checkTrue('pay: back to the queue', str_contains((string) $response['location'], 'admin/orders.php'));
check('pay: the order is paid', orderRow($reference)['payment_status'], 'paid');
checkTrue('pay: and the queue says so', str_contains(toastText(request('php/admin/orders.php', $admin)['body']), 'is marked paid'));

// Twice. The second is refused by the UPDATE's own condition, and the message says which rule.
request('php/admin/orders.php', $admin, ['action' => 'pay', 'ref' => $reference]);
checkTrue(
    'pay: doing it again is refused',
    str_contains(toastText(request('php/admin/orders.php', $admin)['body']), 'already marked paid')
);

/*
 * A paid order takes the customer's own Cancel button away -- that is by design, because after
 * payment a cancellation is a refund conversation. It is also exactly why Mark paid needs an undo:
 * without one, a mis-click would record money that never arrived *and* silently strip a button the
 * customer had a moment ago.
 */
$body = request('php/order.php?ref=' . rawurlencode($reference), $buyer)['body'];
checkTrue('pay: the customer sees it as paid', str_contains($body, 'Paid'));
check('pay: and their cancel button is gone', str_contains($body, 'order-cancel.php'), false);
check(
    'pay: posting to it anyway changes nothing',
    (function () use (&$buyer, $reference) {
        request('php/order-cancel.php', $buyer, ['ref' => $reference]);

        return orderRow($reference)['status'];
    })(),
    'placed'
);

$response = request('php/admin/orders.php', $admin, ['action' => 'unpay', 'ref' => $reference]);
check('unpay: answered with a redirect', $response['status'], 302);
// Back where it was, not blanked: somebody who typed a GCash reference has still done their half.
check('unpay: a transfer goes back to waiting', orderRow($reference)['payment_status'], 'submitted');
checkTrue(
    'unpay: taking back something that is not paid is refused',
    str_contains(
        toastText((function () use (&$admin, $reference) {
            request('php/admin/orders.php', $admin, ['action' => 'unpay', 'ref' => $reference]);

            return request('php/admin/orders.php', $admin)['body'];
        })()),
        'nothing to take back'
    )
);

// The undo on a cash order puts it back to 'unpaid' rather than 'submitted', because cash has
// nothing to submit. The CASE in the statement is what decides that, not the page.
addProduct($buyer, $productId, 1);
$cashRef = refFromLocation(checkout($buyer)['location']);
check('unpay: a fresh cash order is unpaid', orderRow($cashRef)['payment_status'], 'unpaid');
request('php/admin/orders.php', $admin, ['action' => 'pay', 'ref' => $cashRef]);
check('unpay: marking cash paid works', orderRow($cashRef)['payment_status'], 'paid');
request('php/admin/orders.php', $admin, ['action' => 'unpay', 'ref' => $cashRef]);
check('unpay: and cash goes back to unpaid, not submitted', orderRow($cashRef)['payment_status'], 'unpaid');

// Cancelling from the shop's side is wider than the customer's -- it may cancel a paid order.
request('php/admin/orders.php', $admin, ['action' => 'pay', 'ref' => $cashRef]);
$response = request('php/admin/orders.php', $admin, ['action' => 'cancel', 'ref' => $cashRef]);
check('admin cancel: answered with a redirect', $response['status'], 302);
check('admin cancel: a paid order can be called off by the shop', orderRow($cashRef)['status'], 'cancelled');
// And the payment state is kept, because the record still has to say what was taken.
check('admin cancel: the payment state is not rewritten', orderRow($cashRef)['payment_status'], 'paid');
checkTrue(
    'admin cancel: cancelling twice is refused',
    str_contains(
        toastText((function () use (&$admin, $cashRef) {
            request('php/admin/orders.php', $admin, ['action' => 'cancel', 'ref' => $cashRef]);

            return request('php/admin/orders.php', $admin)['body'];
        })()),
        'already cancelled'
    )
);

// Narrower in the other direction: a delivered order is not cancelled, it is returned, and a shop
// that can rewrite a finished order cannot answer what it actually sent out. Walked there with the
// real buttons now that there are some -- an UPDATE straight into the table would leave a delivered
// order with no history, which is a state the site can no longer produce.
addProduct($buyer, $productId, 1);
$deliveredRef = refFromLocation(checkout($buyer)['location']);
request('php/admin/orders.php', $admin, ['action' => 'pack', 'ref' => $deliveredRef]);
request('php/admin/orders.php', $admin, ['action' => 'deliver', 'ref' => $deliveredRef]);
check('admin cancel: the order reached delivered', orderRow($deliveredRef)['status'], 'delivered');
request('php/admin/orders.php', $admin, ['action' => 'cancel', 'ref' => $deliveredRef]);
check('admin cancel: a delivered order is refused', orderRow($deliveredRef)['status'], 'delivered');
checkTrue(
    'admin cancel: and is told why',
    str_contains(toastText(request('php/admin/orders.php', $admin)['body']), 'has been delivered')
);
// A cancelled order cannot be marked paid -- that would be money recorded against something the
// shop is not going to make. $pickupRef rather than $cashRef, because $cashRef is cancelled *and*
// already paid, and "already marked paid" is the truer of the two things to say about it.
request('php/admin/orders.php', $admin, ['action' => 'pay', 'ref' => $pickupRef]);
check('admin: a cancelled order cannot be marked paid', orderRow($pickupRef)['payment_status'], 'unpaid');
checkTrue(
    'admin: and is told why',
    str_contains(toastText(request('php/admin/orders.php', $admin)['body']), 'is cancelled, so it cannot be marked paid')
);
// The other refusal, on the order that is both: paid wins, because that is what the admin most
// needs to know before they go looking for a refund to arrange.
request('php/admin/orders.php', $admin, ['action' => 'pay', 'ref' => $cashRef]);
check('admin: a paid, cancelled order stays paid', orderRow($cashRef)['payment_status'], 'paid');
checkTrue(
    'admin: and says it was already paid',
    str_contains(toastText(request('php/admin/orders.php', $admin)['body']), 'already marked paid')
);

// Junk at the action endpoint. None of it may be a 500, and none of it may change a row.
foreach (['', 'delete', 'DROP', 'pay ', 'unpay;'] as $junkAction) {
    $response = request('php/admin/orders.php', $admin, ['action' => $junkAction, 'ref' => $reference]);
    check("admin: action '$junkAction' is refused", $response['status'], 302);
}
check('admin: and the order is where it was', orderRow($reference)['payment_status'], 'submitted');

$response = request('php/admin/orders.php', $admin, ['action' => 'pay', 'ref' => 'SLJ-20260101-AAAA']);
check('admin: paying an order that is not there redirects', $response['status'], 302);
checkTrue(
    'admin: and says the list is up to date',
    str_contains(toastText(request('php/admin/orders.php', $admin)['body']), 'not there')
);

/* ------------------------------------------------------------------ *
 * Moving an order through the shop, and the history that writes.
 * ------------------------------------------------------------------ */

addProduct($buyer, $productId, 1);
$trackRef = refFromLocation(checkout($buyer)['location']);

/*
 * The three new actions go behind the guard that was already there, not beside it. Asserted rather
 * than assumed, because "the button is not drawn for a customer" is not a lock -- the POST is.
 */
foreach (['pack', 'deliver', 'unpack'] as $locked) {
    check(
        "track: a customer cannot post '$locked'",
        request('php/admin/orders.php', $buyer, ['action' => $locked, 'ref' => $trackRef])['status'],
        403
    );
}
check('track: and the order has not moved', orderRow($trackRef)['status'], 'placed');
check('track: nor is there anything in its history', eventStatuses($trackRef), []);

// Skipping a step. The one refusal here that is not a mistake, so it answers with the button to
// press instead rather than with a flat no.
request('php/admin/orders.php', $admin, ['action' => 'deliver', 'ref' => $trackRef]);
check('track: a placed order cannot be delivered', orderRow($trackRef)['status'], 'placed');
checkTrue(
    'track: and is told which step is missing',
    str_contains(toastText(request('php/admin/orders.php', $admin)['body']), 'Mark it as being made first')
);
check('track: a refusal writes no history', eventStatuses($trackRef), []);

// Starting it, from the queue.
$response = request('php/admin/orders.php', $admin, ['action' => 'pack', 'ref' => $trackRef]);
check('pack: answered with a redirect', $response['status'], 302);
checkTrue('pack: back to the queue', str_contains((string) $response['location'], 'admin/orders.php'));
check('pack: the order is being made', orderRow($trackRef)['status'], 'packed');
check('pack: and one row went into the history', eventStatuses($trackRef), ['packed']);

$packEvent = eventsFor($trackRef)[0];
check('pack: written as the shop', $packEvent['actor'], 'shop');
check('pack: naming the admin who pressed it', (int) $packEvent['actor_user_id'], $adminId);

$said = toastText(request('php/admin/orders.php', $admin)['body']);
checkTrue('pack: the toast says it is being made', str_contains($said, 'is being made'));
checkTrue('pack: and that the customer was told', str_contains($said, 'has been emailed'));

// Twice. Refused by the UPDATE's own condition, and no second row behind it.
request('php/admin/orders.php', $admin, ['action' => 'pack', 'ref' => $trackRef]);
checkTrue(
    'pack: starting it again is refused',
    str_contains(toastText(request('php/admin/orders.php', $admin)['body']), 'already being made')
);
check('pack: and writes no second row', eventStatuses($trackRef), ['packed']);

/*
 * The undo. Only php/admin/order.php draws a button for it -- and this posts it from the queue
 * anyway, on purpose: the rule lives in the WHERE, so an action nobody drew a button for is still
 * answered correctly rather than by a page that assumed nobody would send one.
 */
request('php/admin/orders.php', $admin, ['action' => 'unpack', 'ref' => $trackRef]);
check('unpack: the order goes back to placed', orderRow($trackRef)['status'], 'placed');
check('unpack: and the undo is a row of its own', eventStatuses($trackRef), ['packed', 'placed']);

$said = toastText(request('php/admin/orders.php', $admin)['body']);
checkTrue('unpack: the toast says where it went', str_contains($said, 'is back to Placed'));
checkTrue('unpack: and that nobody was written to', str_contains($said, 'has not been written to'));
check('unpack: no message is claimed', str_contains($said, 'has been emailed'), false);

// Undoing something that was never started.
request('php/admin/orders.php', $admin, ['action' => 'unpack', 'ref' => $trackRef]);
checkTrue(
    'unpack: undoing a placed order is refused',
    str_contains(toastText(request('php/admin/orders.php', $admin)['body']), 'nothing to undo')
);
check('unpack: and writes nothing', eventStatuses($trackRef), ['packed', 'placed']);

/*
 * The customer's own page, which is the whole reason the superseded row is kept rather than
 * deleted: a second "Placed" with no explanation reads as a bug in the page, and a history table
 * that edits history cannot answer what actually happened.
 */
$body = request('php/order.php?ref=' . rawurlencode($trackRef), $buyer)['body'];
check(
    'track: the customer sees the undo for what it was',
    timelineOn($body),
    ['Placed', 'Being made', 'Moved back to Placed', 'Being made', 'Delivered']
);
checkTrue('track: and what is still to come', str_contains($body, 'Still to come'));
check('track: the customer is not told who moved it', timelineActorsOn($body), []);

// Delivering, from the single-order page this time, which lands back on itself rather than on the
// queue -- somebody who opened one order to finish it wants to still be looking at it.
$orderUrl = 'php/admin/order.php?ref=' . rawurlencode($trackRef);
request($orderUrl, $admin, ['action' => 'pack', 'ref' => $trackRef]);
$response = request($orderUrl, $admin, ['action' => 'deliver', 'ref' => $trackRef]);
check('deliver: answered with a redirect', $response['status'], 302);
checkTrue('deliver: back to this order', str_contains((string) $response['location'], 'admin/order.php'));
check('deliver: the order is delivered', orderRow($trackRef)['status'], 'delivered');
check('deliver: and the history is the whole run', eventStatuses($trackRef), ['packed', 'placed', 'packed', 'delivered']);

$said = toastText(request($orderUrl, $admin)['body']);
checkTrue('deliver: the toast says it is the last step', str_contains($said, 'That is the last step'));
checkTrue('deliver: and that the customer was told', str_contains($said, 'has been emailed'));

/*
 * Delivered is final in all four directions. The undo refusing here is the decision the user made
 * on 2026-09-22 written down as a test: back one step, never from delivered.
 */
$finalRefusals = [
    'unpack' => 'cannot be moved back',
    'pack' => 'already been delivered',
    'deliver' => 'was already marked delivered',
    'cancel' => 'cannot be cancelled now',
];

foreach ($finalRefusals as $action => $expected) {
    request('php/admin/orders.php', $admin, ['action' => $action, 'ref' => $trackRef]);
    checkTrue(
        "delivered: '$action' is refused",
        str_contains(toastText(request('php/admin/orders.php', $admin)['body']), $expected)
    );
}
check('delivered: and nothing moved', orderRow($trackRef)['status'], 'delivered');
check('delivered: and nothing was written', eventStatuses($trackRef), ['packed', 'placed', 'packed', 'delivered']);

// What the shop sees, which is the same dots plus who moved each one.
$body = request($orderUrl, $admin)['body'];
check(
    'admin timeline: the shop sees the same dots',
    timelineOn($body),
    ['Placed', 'Being made', 'Moved back to Placed', 'Being made', 'Delivered']
);
check(
    'admin timeline: and who moved each of them',
    timelineActorsOn($body),
    ['by the customer', 'by the shop', 'by the shop', 'by the shop', 'by the shop']
);
check('admin timeline: a finished order promises nothing further', str_contains($body, 'Not yet'), false);

/*
 * A stale page. The button was drawn when the page was rendered and the row moved before it was
 * pressed. What is asserted is the refusal and the absence of a second row -- deliberately not the
 * "changed while you were looking at it" wording, which needs the row to move *between* the read
 * and the UPDATE inside one request and cannot be produced by requests made one at a time. The
 * section after this one stops making them one at a time; the condition is in the WHERE either way,
 * which is what makes that branch safe however the two presses land.
 */
addProduct($buyer, $productId, 1);
$staleRef = refFromLocation(checkout($buyer)['location']);
checkTrue(
    'stale: the page offered to start it',
    str_contains(request('php/admin/order.php?ref=' . rawurlencode($staleRef), $admin)['body'], 'name="action" value="pack"')
);
request('php/admin/orders.php', $admin, ['action' => 'pack', 'ref' => $staleRef]);
request('php/admin/order.php?ref=' . rawurlencode($staleRef), $admin, ['action' => 'pack', 'ref' => $staleRef]);
check('stale: the second press changes nothing', orderRow($staleRef)['status'], 'packed');
check('stale: and writes no second row', eventStatuses($staleRef), ['packed']);

/**
 * Fire several POSTs at once and hand back their replies in the order they were given.
 *
 * curl_multi rather than the stream context request() uses, because this is the one thing in the
 * suite that has to actually be concurrent: file_get_contents() blocks until its reply arrives, so
 * two of those in a row are two requests in a row -- the sequential case already covered above.
 *
 * Each call is [path, jar, post]. Jars go in by value and are not updated from the replies: these
 * requests are aimed at a state the caller has already established, and two Set-Cookie headers
 * racing into one array would be a result nobody could read.
 *
 * @param array<int, array{0:string, 1:array<string, string>, 2:array<string, string>}> $calls
 * @return array<int, array{status:int, body:string}>
 */
function fireTogether(array $calls): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($calls as $index => [$path, $jar, $post]) {
        $pairs = [];

        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $handle = curl_init(BASE . $path);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_COOKIE => implode('; ', $pairs),
            CURLOPT_RETURNTRANSFER => true,
            // Not followed, for request()'s reason: the redirect is the reply being examined.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_multi_add_handle($multi, $handle);
        $handles[$index] = $handle;
    }

    $running = null;

    do {
        curl_multi_exec($multi, $running);

        // curl_multi_select() returns -1 immediately when there is nothing to wait on, which would
        // spin this loop at full speed; the short sleep is the documented answer to that.
        if (curl_multi_select($multi, 1.0) === -1) {
            usleep(100);
        }
    } while ($running > 0);

    $replies = [];

    foreach ($handles as $index => $handle) {
        $replies[$index] = [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'body' => (string) curl_multi_getcontent($handle),
        ];

        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }

    curl_multi_close($multi);

    return $replies;
}

/*
 * The same collision, genuinely at the same instant.
 *
 * Two members of staff with the queue open both press Start on one order. Sequentially -- the case
 * directly above -- the second press reads a row that has already moved and is turned away before
 * it writes anything. Concurrently, both requests can read 'placed' before either has written, and
 * the only thing between that and two "we have started your order" emails to one customer is the
 * condition inside the UPDATE's own WHERE.
 *
 * Two browsers AND two accounts, not one browser pressed twice: PHP holds an exclusive lock on a
 * session file for the whole of a request, so two requests carrying one PHPSESSID cannot overlap at
 * all -- the second waits for the first, and this quietly becomes the sequential test again.
 *
 * What is asserted is the invariant, not the interleaving: the order ends up being made once, with
 * one row of history and one success between the two browsers, whether or not the requests really
 * did overlap on this machine on this morning. Which refusal the losing press got is printed rather
 * than demanded -- both of them are correct, and a test that only passes when it wins the race is a
 * test that fails on a fast morning.
 */
$packerOne = [];
trustDevice($packerOne, $adminId);
signIn($packerOne, 'zztest.orders.admin@capstone.test', $password);

$packerTwo = [];
trustDevice($packerTwo, $adminTwoId);
signIn($packerTwo, 'zztest.orders.admin2@capstone.test', $password);

checkTrue(
    'race: the two presses come from two different sessions',
    ($packerOne['PHPSESSID'] ?? 'one') !== ($packerTwo['PHPSESSID'] ?? 'two')
);

// Taken before the array below is built, not inside it: csrfFor() takes its jar by reference and
// the GET behind it can set cookies, which an array literal would have already copied.
$tokenOne = csrfFor($packerOne);
$tokenTwo = csrfFor($packerTwo);

addProduct($buyer, $productId, 1);
$raceRef = refFromLocation(checkout($buyer)['location']);
check('race: the order starts out placed', orderRow($raceRef)['status'], 'placed');

$replies = fireTogether([
    ['php/admin/orders.php', $packerOne, ['action' => 'pack', 'ref' => $raceRef, 'csrf_token' => $tokenOne]],
    ['php/admin/orders.php', $packerTwo, ['action' => 'pack', 'ref' => $raceRef, 'csrf_token' => $tokenTwo]],
]);

// Both, because a refusal here is a redirect carrying a toast, exactly like a success.
check(
    'race: both presses were answered',
    [$replies[0]['status'], $replies[1]['status']],
    [302, 302]
);
check('race: the order is being made', orderRow($raceRef)['status'], 'packed');
check('race: and exactly one row went into the history', eventStatuses($raceRef), ['packed']);

$raceEvent = eventsFor($raceRef)[0];
check('race: written as the shop', $raceEvent['actor'], 'shop');
checkTrue(
    'race: naming one of the two who pressed it',
    in_array((int) $raceEvent['actor_user_id'], [$adminId, $adminTwoId], true)
);

/*
 * One success between them. Each browser's own queue page is read, because a flash belongs to the
 * session it was given to -- reading one page twice would show one message and swallow the other,
 * and the count below would be 1 no matter what had happened.
 */
$saidOne = toastText(request('php/admin/orders.php', $packerOne)['body']);
$saidTwo = toastText(request('php/admin/orders.php', $packerTwo)['body']);

$successes = (int) str_contains($saidOne, 'has been emailed')
    + (int) str_contains($saidTwo, 'has been emailed');

check('race: exactly one of them was told the customer was emailed', $successes, 1);

$loser = str_contains($saidOne, 'has been emailed') ? $saidTwo : $saidOne;

checkTrue(
    'race: and the other was turned away rather than quietly ignored',
    str_contains($loser, 'already being made') || str_contains($loser, 'changed while you were looking at it')
);

echo '  the press that lost said: ' . $loser . "\n";

// A cancelled order has nothing to start and nothing to deliver.
addProduct($buyer, $productId, 1);
$calledOffRef = refFromLocation(checkout($buyer)['location']);
request('php/admin/orders.php', $admin, ['action' => 'cancel', 'ref' => $calledOffRef]);
check('shop cancel: the order is cancelled', orderRow($calledOffRef)['status'], 'cancelled');
check('shop cancel: and it writes a row of its own', eventStatuses($calledOffRef), ['cancelled']);
check('shop cancel: written as the shop', eventsFor($calledOffRef)[0]['actor'], 'shop');

foreach (['pack' => 'nothing to start', 'deliver' => 'cannot be delivered'] as $action => $expected) {
    request('php/admin/orders.php', $admin, ['action' => $action, 'ref' => $calledOffRef]);
    checkTrue(
        "shop cancel: '$action' on a cancelled order is refused",
        str_contains(toastText(request('php/admin/orders.php', $admin)['body']), $expected)
    );
}
check('shop cancel: and it stays cancelled', orderRow($calledOffRef)['status'], 'cancelled');
check('shop cancel: with nothing added to its history', eventStatuses($calledOffRef), ['cancelled']);

// The customer calling it off themselves, which is the same row with a different actor -- and the
// reason the column exists. The shop needs to know which conversation this is.
addProduct($buyer, $productId, 1);
$ownCancelRef = refFromLocation(checkout($buyer)['location']);
request('php/order-cancel.php', $buyer, ['ref' => $ownCancelRef]);
check('customer cancel: the order is cancelled', orderRow($ownCancelRef)['status'], 'cancelled');
check('customer cancel: and writes one row', eventStatuses($ownCancelRef), ['cancelled']);
check('customer cancel: written as the customer', eventsFor($ownCancelRef)[0]['actor'], 'customer');
check('customer cancel: naming them', (int) eventsFor($ownCancelRef)[0]['actor_user_id'], $buyerId);
check(
    'customer cancel: and the shop can see it was not them',
    timelineActorsOn(request('php/admin/order.php?ref=' . rawurlencode($ownCancelRef), $admin)['body']),
    ['by the customer', 'by the customer']
);

// A reference that is not there, for each of the three. Answered before any statement runs.
foreach (['pack', 'deliver', 'unpack'] as $action) {
    $response = request('php/admin/orders.php', $admin, ['action' => $action, 'ref' => 'SLJ-20260101-AAAA']);
    check("track: '$action' against nothing redirects", $response['status'], 302);
    checkTrue(
        "track: '$action' says the list is up to date",
        str_contains(toastText(request('php/admin/orders.php', $admin)['body']), 'not there')
    );
}
check(
    'track: and no history row was written for a reference that does not exist',
    (int) $database->query("SELECT COUNT(*) FROM order_events e LEFT JOIN orders o ON o.id = e.order_id WHERE o.id IS NULL")->fetchColumn(),
    0
);

/* ------------------------------------------------------------------ *
 * What the customer was actually told.
 * ------------------------------------------------------------------ */

/*
 * What the customer was actually told -- IF the transport writes somewhere this can read.
 *
 * MAIL_TRANSPORT is a setting, not a property of the code under test: sendMail() takes the same path
 * either way, and which events send word is the part that does not change with it. But *reading*
 * what was sent only works for the 'log' transport, because that is the only one that leaves the
 * message on this machine.
 *
 * This block used to assert `MAIL_TRANSPORT === 'log'` outright. On 2026-10-08 the setting was
 * changed to 'smtp' outside this project's tasks and seven checks began failing -- none of which
 * were about orders, all of which read the log. A suite that fails because the environment it
 * assumed has moved is a suite that gets ignored, so it now ASKS the transport and says plainly
 * which assertions it cannot make. The alternative, pinning the setting, would mean this file
 * dictating how the shop sends mail.
 *
 * Only the bytes this run appended are read, and none of them are printed. That file also holds
 * live two-factor codes from other runs.
 */
checkTrue(
    'mail: the transport is one this project knows',
    in_array(MAIL_TRANSPORT, ['log', 'smtp'], true)
);

$appended = '';

if (MAIL_TRANSPORT === 'log' && is_file(MAIL_LOG_FILE)) {
    $handle = fopen(MAIL_LOG_FILE, 'rb');
    fseek($handle, $mailLogBefore);
    $appended = (string) stream_get_contents($handle);
    fclose($handle);
}

if (MAIL_TRANSPORT !== 'log') {
    /*
     * Named loudly rather than quietly omitted. Seven assertions about what the customer was told
     * are NOT being made, and a run that simply printed a smaller number would hide that.
     *
     * Seven and not eight: the transport check above runs either way, so it is not skipped. The
     * count was wrong by one until the round-trip caught it -- 334 passed with 'log' against 327
     * passed plus "8 skipped" with 'smtp' does not add up, and a skip count that cannot be
     * reconciled against the other run is a summary telling a small lie.
     */
    $skipped = 7;
    $skips[] = sprintf(
        "SKIP  mail: MAIL_TRANSPORT is '%s', so nothing is written to %s and the %d assertions about\n"
        . "      what the customer was told cannot be made. Set it to 'log' to test the wording.",
        MAIL_TRANSPORT,
        basename(MAIL_LOG_FILE),
        $skipped
    );
} else {
    checkTrue('mail: this run wrote to it', $appended !== '');

    preg_match_all('/^Subject: (.+)$/m', $appended, $subjectMatches);
    $subjects = array_map('trim', $subjectMatches[1]);

    $sentFor = static fn (string $reference): array => array_values(array_filter(
        $subjects,
        static fn (string $subject): bool => str_contains($subject, $reference)
    ));

    /*
     * The tracked order was started, undone, started again and delivered, and one attempt to start
     * it a second time was refused. Three messages, in that order: the undo writes to nobody and the
     * refusal is not an event, so a fourth line here would be a customer told something untrue.
     */
    check('mail: the tracked order sent exactly what happened', $sentFor($trackRef), [
        orderMailSubject(ORDER_MAIL_PACKED, $trackRef),
        orderMailSubject(ORDER_MAIL_PACKED, $trackRef),
        orderMailSubject(ORDER_MAIL_DELIVERED, $trackRef),
    ]);

    check('mail: the shop cancelling writes to the customer', $sentFor($calledOffRef), [
        orderMailSubject(ORDER_MAIL_CANCELLED, $calledOffRef),
    ]);

    // The one deliberate silence: they pressed the button themselves, they know, and a message
    // confirming somebody's own click is the kind of mail people set up filters to delete.
    check('mail: a customer cancelling their own order is written to by nobody', $sentFor($ownCancelRef), []);

    // The stale-page order was started once and refused once, so it heard once.
    check('mail: a refused action sends nothing', $sentFor($staleRef), [
        orderMailSubject(ORDER_MAIL_PACKED, $staleRef),
    ]);

    checkTrue(
        'mail: the message links somewhere a mail client can resolve',
        str_contains($appended, siteUrl('php/order.php') . '?ref=' . $trackRef)
    );
    checkTrue('mail: and carries the total', str_contains($appended, 'Total:'));
}

/* ------------------------------------------------------------------ *
 * One order, in full, from the shop's side.
 * ------------------------------------------------------------------ */

$body = request('php/admin/order.php?ref=' . rawurlencode($reference), $admin)['body'];
checkTrue('admin order: the reference is on it', str_contains($body, $reference));
checkTrue('admin order: the product line is there', str_contains($body, 'ZZTest Order Bouquet'));
checkTrue('admin order: the address is there', str_contains($body, '12 ZZTest Street'));
checkTrue('admin order: the phone is there', str_contains($body, '0917 555 0101'));
checkTrue('admin order: the payment reference is there', str_contains($body, 'ZZTESTGC0001'));
checkTrue('admin order: the note is there', str_contains($body, 'Leave it with the guard'));
// The account behind the order, which is a different question from the contact details frozen on it.
checkTrue('admin order: the account email is there', str_contains($body, 'zztest.orders.buyer@capstone.test'));
// The build instructions: a designed line's parts, each with the kind it is.
checkTrue('admin order: the parts are listed', str_contains($body, 'cart-parts order-parts'));
checkTrue('admin order: with the kind of each one', str_contains($body, 'cart-part-kind'));
checkTrue('admin order: and it offers the actions', str_contains($body, 'name="action" value="pay"'));
checkTrue('admin order: including starting it', str_contains($body, 'name="action" value="pack"'));
check('admin order: but not delivering something nobody has started', str_contains($body, 'name="action" value="deliver"'), false);
check('admin order: nor undoing something nobody has started', str_contains($body, 'name="action" value="unpack"'), false);

// The history, on an order nothing has happened to yet: placement is derived from the order's own
// placed_at, so it draws correctly with no rows behind it at all.
checkTrue('admin order: the history is on the page', str_contains($body, 'order-timeline'));
check('admin order: an untouched order still draws a timeline', timelineOn($body), ['Placed', 'Being made', 'Delivered']);
checkTrue('admin order: saying what has not happened yet', str_contains($body, 'Not yet'));
check('admin order: and the placeholder note is gone', str_contains($body, 'not switched on yet'), false);

$response = request('php/admin/order.php?ref=SLJ-20260101-AAAA', $admin);
check('admin order: an order that is not there is a 404', $response['status'], 404);
check('admin order: a missing reference is a 404 too', request('php/admin/order.php', $admin)['status'], 404);

// Acting from the single-order page lands back on the single-order page, not on the queue.
$response = request('php/admin/order.php?ref=' . rawurlencode($reference), $admin, [
    'action' => 'pay',
    'ref' => $reference,
]);
check('admin order: the action redirects', $response['status'], 302);
checkTrue('admin order: back to this order', str_contains((string) $response['location'], 'admin/order.php'));
checkTrue('admin order: carrying its reference', str_contains((string) $response['location'], rawurlencode($reference)));
check('admin order: and it did the thing', orderRow($reference)['payment_status'], 'paid');
request('php/admin/order.php?ref=' . rawurlencode($reference), $admin, ['action' => 'unpay', 'ref' => $reference]);

/* ------------------------------------------------------------------ *
 * The admin dashboard.
 *
 * These checks used to read the three-number orders card -- "Still open", "Taken", "Payments to
 * check". That card was replaced when the admin area was restyled on 2026-09-24: the numbers
 * moved into the KPI row and a "Recent orders" panel, and the old labels now survive only in a
 * comment in dashboard.php. So what is asserted here is what the page is for rather than the
 * words it used to use.
 *
 * The last one is the one worth having. dashboard.php wraps its counting in a try/catch and
 * carries on if it throws, so a broken count leaves the page up and merely quiet -- the toast is
 * the only evidence, and a test that only looked for headings would never see it.
 * ------------------------------------------------------------------ */

$body = request('php/admin/dashboard.php', $admin)['body'];
checkTrue('dashboard: it reaches the order queue', str_contains($body, 'admin/orders.php'));
checkTrue('dashboard: it counts the week\'s orders', str_contains($body, 'Weekly Orders'));
checkTrue('dashboard: and lists the recent ones', str_contains($body, 'Recent orders'));
check('dashboard: reading the orders did not fail', str_contains($body, 'Unable to count the orders'), false);

/* ------------------------------------------------------------------ *
 * Tidy up. Only rows this suite made.
 * ------------------------------------------------------------------ */

$mine = "(SELECT id FROM orders WHERE user_id IN ($buyerId, $otherId, $adminId))";

$database->exec("DELETE FROM order_item_parts WHERE item_id IN (SELECT id FROM order_items WHERE order_id IN $mine)");
$database->exec("DELETE FROM order_items WHERE order_id IN $mine");
$database->exec("DELETE FROM orders WHERE user_id IN ($buyerId, $otherId, $adminId)");
$database->exec("DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM carts WHERE user_id IN ($buyerId, $otherId, $adminId))");
$database->exec("DELETE FROM carts WHERE user_id IN ($buyerId, $otherId, $adminId)");
$database->exec("DELETE FROM trusted_devices WHERE label = 'ZZTest orders suite'");
$database->exec("DELETE FROM users WHERE email LIKE 'zztest.orders%'");
$database->exec("DELETE FROM products WHERE name LIKE 'ZZTest%'");

echo implode("\n", $failures) . ($failures === [] ? '' : "\n");
echo implode("\n", $skips) . ($skips === [] ? '' : "\n");
printf("\n%d passed, %d failed, %d skipped\n", $passed, $failed, $skipped);

// The order count is the one that matters: an order that outlived its account would mean the
// teardown hit fk_orders_user's RESTRICT and stopped half way.
$leftover = (int) $database->query('SELECT COUNT(*) FROM orders WHERE total = 0')->fetchColumn()
    + (int) $database->query("SELECT COUNT(*) FROM products WHERE name LIKE 'ZZTest%'")->fetchColumn()
    + (int) $database->query("SELECT COUNT(*) FROM users WHERE email LIKE 'zztest.orders%'")->fetchColumn()
    + (int) $database->query("SELECT COUNT(*) FROM order_items WHERE name LIKE 'ZZTest%'")->fetchColumn();

printf("leftover ZZTest rows: %d\n", $leftover);
printf(
    "orders in the table: %d (was %d before this run)\n",
    (int) $database->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    $ordersBefore
);
// The history rows go with their orders on fk_order_events_order's CASCADE -- nothing above deletes
// them by hand. A count that does not come back to where it started means that is not true.
printf(
    "history rows: %d (was %d before this run)\n",
    (int) $database->query('SELECT COUNT(*) FROM order_events')->fetchColumn(),
    $eventsBefore
);

exit($failed === 0 ? 0 : 1);


