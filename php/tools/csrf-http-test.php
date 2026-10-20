<?php

declare(strict_types=1);

/**
 * The CSRF token, over Apache: every handler refuses a POST that does not carry one.
 *
 *     C:\xampp\php\php.exe php\tools\csrf-http-test.php
 *
 * WHY THIS IS ITS OWN SUITE. The other two suites now attach the token to every POST automatically,
 * because they are about the basket and about orders and a token in sixty call sites would bury
 * both. That makes them blind to exactly the thing this file is for: they would go on passing if
 * every requireCsrfToken() call in the project were deleted tomorrow.
 *
 * It also reaches five handlers no suite has ever touched -- php/login.php, php/verify.php,
 * php/profile.php, php/admin/products.php and php/admin/components.php. Threading a token through
 * a form nobody watches run is a change that looks finished and is not.
 *
 * Three requests per handler: no token, a wrong token, then the real one -- and after each refusal,
 * the row it aimed at is read back to prove nothing moved. A 403 that still writes is worse than no
 * check at all, because it looks like a pass.
 *
 * Creates its own products, accounts, design and order, all prefixed ZZTest, and deletes them at
 * the end. It never writes to a seeded row.
 */

// Same guard as the two tools beside this one. This file is under the webroot, so without it a
// stranger could run the suite -- which signs up accounts, creates products, and deletes rows.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/*
 * Before the first require, as the two suites beside this one also do. basePath() memoises, and
 * under CLI realpath('') on Windows hands back the working directory -- so the first thing to call
 * url() would freeze a wrong root for the whole run.
 */
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs';

require_once __DIR__ . '/../config/database.php';

const BASE = 'http://localhost/Capstone/';

/** A token of the right shape that was never minted by any session here. */
const WRONG_TOKEN = 'dead0000beef0000dead0000beef0000dead0000beef0000dead0000beef0000';

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
 * Deliberately does NOT add the token, unlike the copy of this function in the other two suites.
 * Here the presence or absence of that field is the whole subject, so every caller says what it
 * wants and nothing is added on its behalf.
 *
 * Redirects are not followed: a refusal from one of the redirect-only endpoints is a 302, and the
 * Location is part of what is being asserted.
 *
 * @param array<string, string> $jar
 * @param array<string, string>|null $post
 * @return array{status:int, headers:array<int, string>, body:string, location:?string}
 */
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

/** The token out of a page that carries a form, or '' if the page has none. */
function tokenFrom(string $body): string
{
    return preg_match('#name="csrf_token" value="([a-f0-9]+)"#', $body, $m) === 1 ? $m[1] : '';
}

/**
 * This browser's token, off php/login.php -- the one page that draws a form for everybody.
 *
 * @param array<string, string> $jar
 */
function tokenFor(array &$jar): string
{
    return tokenFrom(request('php/login.php', $jar)['body']);
}

/** Did this response come back as the plain 403 page rather than doing the work? */
function wasRefused(array $response): bool
{
    return $response['status'] === 403 && str_contains($response['body'], 'That form had expired');
}

/**
 * Every toast on a page, joined up -- what the site actually said.
 *
 * Printed beside a check that went the wrong way. A suite that only reports "expected 302, got 200"
 * costs a whole run to find out which of twelve fields the page was unhappy about, and on this
 * machine a run means starting and stopping two servers.
 */
function messagesIn(string $body): string
{
    preg_match_all('#<p class="toast-message">(.*?)</p>#s', $body, $matches);

    $notices = array_map(static fn (string $text): string => html_entity_decode(trim($text), ENT_QUOTES, 'UTF-8'), $matches[1]);

    return $notices === [] ? '(the page said nothing)' : implode(' | ', $notices);
}

/**
 * Make this browser one the account already trusts, skipping the emailed code.
 *
 * The same trick the other two suites use, and for the same reason: going through 2FA properly
 * would mean reading somebody's live verification code back out of the mail log.
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
        'label' => 'ZZTest csrf suite',
    ]);

    $jar['capstone_device'] = $token;
}

/**
 * Sign in properly, token and all. Returns the response.
 *
 * @param array<string, string> $jar
 */
function signIn(array &$jar, string $identifier, string $password): array
{
    // The form first, so the session and its token exist before anything is posted to it.
    $token = tokenFor($jar);

    return request('php/login.php', $jar, [
        'csrf_token' => $token,
        'form_type' => 'login',
        'identifier' => $identifier,
        'password' => $password,
    ]);
}

/* ------------------------------------------------------------------ *
 * Part one: the source itself, with no server involved.
 *
 * Everything below this block is one handler at a time, which can only ever prove the handlers
 * somebody remembered to write a test for. These three checks are the ones that still hold when
 * the next module adds a form: a POST handler with no guard, an admin page that forgot guard.php,
 * or a form missing its hidden field all fail here without anybody thinking to look.
 * ------------------------------------------------------------------ */

$root = dirname(__DIR__);
$sources = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
    // php/tools/ is skipped: these suites talk *about* the token, and matching on that would make
    // every check below pass on the strength of its own comments.
    if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR)) {
        $sources[str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
    }
}

checkTrue('static: the sweep found the project', count($sources) > 20);

$unguarded = [];
$formless = [];
$adminUnprotected = [];

foreach ($sources as $name => $source) {
    /*
     * Matched on the bare constant rather than on "=== 'POST'": the four endpoints that never
     * render spell it the other way round, as ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST',
     * and they are the ones an attacking page aims at first. config/csrf.php is excused because it
     * is the guard being looked for.
     */
    if ($name !== 'config' . DIRECTORY_SEPARATOR . 'csrf.php'
        && str_contains($source, 'REQUEST_METHOD')
        && !str_contains($source, 'requireCsrfToken(')
        && !str_contains($source, 'requireCsrfTokenOrRedirect(')) {
        $unguarded[] = $name;
    }

    /*
     * 'echo csrfField()' rather than the bare name: two docblocks mention the function in prose --
     * navbar.php explaining why it carries its own require, guard.php explaining what the admin
     * pages get for free -- and a sentence about a field is not a field.
     *
     * config/csrf.php is the one file allowed to say <form method="post"> without drawing one --
     * it is the sentence in its own docblock explaining what the field is for.
     */
    if ($name !== 'config' . DIRECTORY_SEPARATOR . 'csrf.php'
        && preg_match_all('/<form[^>]*method="post"/i', $source) > substr_count($source, 'echo csrfField()')) {
        $formless[] = $name;
    }

    if (str_starts_with($name, 'admin' . DIRECTORY_SEPARATOR)
        && $name !== 'admin' . DIRECTORY_SEPARATOR . 'guard.php'
        && !str_contains($source, "require_once __DIR__ . '/guard.php';")) {
        $adminUnprotected[] = $name;
    }
}

check('static: every POST handler names a token guard', implode(', ', $unguarded), '');
check('static: every form carries a hidden field', implode(', ', $formless), '');
check('static: every admin page requires the guard', implode(', ', $adminUnprotected), '');

// The counts the roadmap quotes, so a form added without a field is visible as a number too.
$forms = 0;
$fields = 0;
$handlers = 0;

foreach ($sources as $name => $source) {
    if ($name === 'config' . DIRECTORY_SEPARATOR . 'csrf.php') {
        continue;
    }

    $forms += preg_match_all('/<form[^>]*method="post"/i', $source);
    $fields += substr_count($source, 'echo csrfField()');
    $handlers += str_contains($source, 'REQUEST_METHOD') ? 1 : 0;
}

check('static: one hidden field per form', $fields, $forms);
printf("source: %d forms, %d hidden fields, %d POST handlers\n", $forms, $fields, $handlers);

/* ------------------------------------------------------------------ *
 * The two shapes a refusal comes in, each proved the same way: fire the POST twice, once with no
 * token at all and once with a token of the right length that no session ever minted, and read the
 * target back after each. The readback is the point. A 403 that still wrote would pass a test that
 * only looked at the status code, and it would be the worst possible outcome -- a hole that reports
 * itself as closed.
 * ------------------------------------------------------------------ */

/**
 * A self-rendering page: refusal is the plain 403.
 *
 * @param array<string, string> $jar
 * @param array<string, string> $post fields WITHOUT a token
 * @param callable():mixed $state reads back whatever this POST would have changed
 */
function refuses(string $label, string $path, array &$jar, array $post, callable $state): void
{
    $before = $state();

    foreach (['no token' => [], 'wrong token' => ['csrf_token' => WRONG_TOKEN]] as $how => $extra) {
        $response = request($path, $jar, array_merge($post, $extra));

        checkTrue("$label: $how is refused", wasRefused($response));
        check("$label: $how changed nothing", $state(), $before);
    }
}

/**
 * One of the four endpoints that never renders: refusal is a toast and a Location.
 *
 * $destination is asserted against the Location and then fetched, which both proves the message
 * reaches a page and drains the flash so it cannot turn up later attached to something else.
 *
 * @param array<string, string> $jar
 * @param array<string, string> $post fields WITHOUT a token
 * @param callable():mixed $state reads back whatever this POST would have changed
 */
function refusesBack(string $label, string $path, array &$jar, array $post, string $destination, callable $state): void
{
    $before = $state();

    foreach (['no token' => [], 'wrong token' => ['csrf_token' => WRONG_TOKEN]] as $how => $extra) {
        $response = request($path, $jar, array_merge($post, $extra));

        check("$label: $how redirects", $response['status'], 302);
        checkTrue("$label: $how goes back where they were", str_contains((string) $response['location'], $destination));
        checkTrue("$label: $how says the form expired", str_contains(request($destination, $jar)['body'], 'That form had expired'));
        check("$label: $how changed nothing", $state(), $before);
    }
}

/* ------------------------------------------------------------------ *
 * Fixtures. One product, one component, three accounts, all this suite's own.
 *
 * Named 'ZZTest Csrf%' rather than the bare 'ZZTest%' the other two suites use, so a crash here
 * cannot take their fixtures with it and vice versa -- while still being counted by their leftover
 * totals, which is the point of the shared prefix.
 * ------------------------------------------------------------------ */

$database = getDatabaseConnection();

/** Wipe whatever a previous crashed run left, in fk order: orders hold users, users hold the rest. */
$scrub = static function () use ($database): void {
    $mine = "(SELECT id FROM users WHERE email LIKE 'zztest.csrf%')";

    $database->exec("DELETE FROM order_item_parts WHERE item_id IN (SELECT id FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE user_id IN $mine))");
    $database->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE user_id IN $mine)");
    $database->exec("DELETE FROM orders WHERE user_id IN $mine");
    $database->exec("DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM carts WHERE user_id IN $mine)");
    $database->exec("DELETE FROM carts WHERE user_id IN $mine");

    /*
     * The visitor's basket, which the two lines above cannot reach.
     *
     * The first refusals in this suite are aimed at a signed-out visitor -- that is who fills a
     * basket here -- so the cart it makes has user_id NULL and no owner to find it by. And the
     * item in it cannot be found by product either: cart_items.product_id is ON DELETE SET NULL,
     * so deleting the ZZTest product below blanks it and leaves the row behind. The frozen name
     * is the only handle left, which is the receipt pattern working exactly as intended.
     *
     * Carts first and items second, not the other way round: cart_items cascades from carts, so
     * deleting the cart takes its rows with it -- whereas deleting the items first would throw
     * away the only thing that identifies which cart to remove. The second statement is the
     * mop-up for an item sitting in a cart that is somebody's and must stay.
     *
     * Scoped to user_id IS NULL so a real visitor's basket, which could be sitting empty in this
     * table while the suite runs, is never in range.
     */
    $database->exec(
        "DELETE FROM carts
          WHERE user_id IS NULL
            AND id IN (SELECT cart_id FROM cart_items WHERE name LIKE 'ZZTest Csrf%')"
    );
    $database->exec("DELETE FROM cart_items WHERE name LIKE 'ZZTest Csrf%'");

    $database->exec("DELETE FROM saved_designs WHERE user_id IN $mine");
    $database->exec("DELETE FROM trusted_devices WHERE label = 'ZZTest csrf suite'");
    $database->exec("DELETE FROM users WHERE email LIKE 'zztest.csrf%'");
    $database->exec("DELETE FROM products WHERE name LIKE 'ZZTest Csrf%'");
    $database->exec("DELETE FROM product_components WHERE name LIKE 'ZZTest Csrf%'");
};

$scrub();

$database->exec(
    "INSERT INTO products (name, description, price, stem_count, colour, is_active)
     VALUES ('ZZTest Csrf Bouquet', 'Fixture for the token suite.', 250.00, 6, 'Test', 1)"
);
$productId = (int) $database->lastInsertId();

$database->exec(
    "INSERT INTO product_components (name, kind, description, price, colour, colour_hex, is_active)
     VALUES ('ZZTest Csrf Ribbon', 'accent', 'Fixture for the token suite.', 40.00, 'Test', '#abcdef', 1)"
);
$componentId = (int) $database->lastInsertId();

$password = 'ZzTestCsrf123!';
$hash = password_hash($password, PASSWORD_DEFAULT);

// Verified, all three: login.php sends an unverified account off to finish signing up instead of
// signing it in, and every check below would then be aimed at a visitor who never got in.
$makeAccount = static function (string $name, string $email, string $role) use ($database, $hash): int {
    $database->prepare(
        'INSERT INTO users (name, email, password_hash, role, email_verified_at)
         VALUES (:name, :email, :hash, :role, NOW())'
    )->execute(['name' => $name, 'email' => $email, 'hash' => $hash, 'role' => $role]);

    return (int) $database->lastInsertId();
};

$buyerId = $makeAccount('ZZTest Csrf Buyer', 'zztest.csrf.buyer@capstone.test', 'customer');
$adminId = $makeAccount('ZZTest Csrf Admin', 'zztest.csrf.admin@capstone.test', 'admin');
// Deliberately never trusted: signing this one in stops at the code page, which is the only way to
// reach verify.php's handler at all -- it redirects away without a pending challenge in session.
$pendingId = $makeAccount('ZZTest Csrf Pending', 'zztest.csrf.pending@capstone.test', 'customer');

printf("fixtures: product %d, component %d, buyer %d, admin %d, pending %d\n\n", $productId, $componentId, $buyerId, $adminId, $pendingId);

/** What the product's visible/hidden flag is right now. */
$productActive = static fn (): string => (string) $database->query("SELECT is_active FROM products WHERE id = $productId")->fetchColumn();
$productName = static fn (): string => (string) $database->query("SELECT name FROM products WHERE id = $productId")->fetchColumn();
$componentActive = static fn (): string => (string) $database->query("SELECT is_active FROM product_components WHERE id = $componentId")->fetchColumn();
$componentName = static fn (): string => (string) $database->query("SELECT name FROM product_components WHERE id = $componentId")->fetchColumn();
$buyerName = static fn (): string => (string) $database->query("SELECT name FROM users WHERE id = $buyerId")->fetchColumn();
$designCount = static fn (): string => (string) $database->query("SELECT COUNT(*) FROM saved_designs WHERE user_id = $buyerId")->fetchColumn();
$orderCount = static fn (): string => (string) $database->query("SELECT COUNT(*) FROM orders WHERE user_id = $buyerId")->fetchColumn();

/* ------------------------------------------------------------------ *
 * php/cart-update.php -- as a signed-out visitor, which is who fills a basket.
 *
 * First because it is the one an attacking page can aim at a stranger with no account at all, and
 * because it proves the redirect shape: nothing may be printed from this file, so the refusal has
 * to arrive as a toast on the page they were already standing on.
 * ------------------------------------------------------------------ */

$guest = [];

$basketHas = static function () use (&$guest): string {
    return str_contains(request('php/cart.php', $guest)['body'], 'ZZTest Csrf Bouquet') ? 'yes' : 'no';
};

// A GET first, so this browser has a session and a basket token before anything is posted at it --
// otherwise the refusal would be indistinguishable from a visitor who simply had no session.
request('php/cart.php', $guest);

refusesBack('cart add', 'php/cart-update.php', $guest, [
    'action' => 'add-product',
    'product_id' => (string) $productId,
    'quantity' => '1',
], 'php/cart.php', $basketHas);

$response = request('php/cart-update.php', $guest, [
    'csrf_token' => tokenFor($guest),
    'action' => 'add-product',
    'product_id' => (string) $productId,
    'quantity' => '1',
]);

check('cart add: the real token is accepted', $response['status'], 302);
check('cart add: and the basket has it', $basketHas(), 'yes');

if ($basketHas() !== 'yes') {
    echo '  the basket page said: ' . messagesIn(request('php/cart.php', $guest)['body']) . "\n";
}

/*
 * Empty is the one an attacker would actually send, because it needs nothing but the address: no
 * id to guess, no quantity, and the damage is the whole basket.
 */
refusesBack('cart empty', 'php/cart-update.php', $guest, ['action' => 'empty'], 'php/cart.php', $basketHas);

/* ------------------------------------------------------------------ *
 * php/login.php -- signing somebody in is a change too.
 *
 * Without a token here, another site can quietly sign a visitor into an account it controls, and
 * everything they do next -- every address they type, every order they place -- is done in it.
 * ------------------------------------------------------------------ */

$stranger = [];
$strangerSignedIn = static function () use (&$stranger): string {
    // profile.php is behind requireLogin(), so its status code answers the question without
    // depending on a word in the navbar.
    return request('php/profile.php', $stranger)['status'] === 200 ? 'in' : 'out';
};

request('php/login.php', $stranger);

refuses('login', 'php/login.php', $stranger, [
    'form_type' => 'login',
    'identifier' => 'zztest.csrf.buyer@capstone.test',
    'password' => $password,
], $strangerSignedIn);

/* ------------------------------------------------------------------ *
 * php/verify.php -- the second step, which no suite has ever reached.
 *
 * Needs a genuine half-finished sign-in: the page redirects to login.php when the session holds no
 * challenge, so the account below is the one deliberately left untrusted.
 * ------------------------------------------------------------------ */

$pending = [];
signIn($pending, 'zztest.csrf.pending@capstone.test', $password);

$stillPending = static function () use (&$pending): string {
    return request('php/verify.php', $pending)['status'] === 200 ? 'pending' : 'gone';
};

check('verify: the account is sitting at the code page', $stillPending(), 'pending');

refuses('verify', 'php/verify.php', $pending, [
    'action' => 'verify',
    'code' => '000000',
], $stillPending);

// The gate, not the code: a wrong code with a real token has to come back as the page saying so,
// which is not a refusal. Deliberately not 'resend', which would append a live code to the mail log.
$response = request('php/verify.php', $pending, [
    'csrf_token' => tokenFor($pending),
    'action' => 'verify',
    'code' => '000000',
]);

check('verify: the real token gets through to the code check', $response['status'], 200);
check('verify: and is not refused as a forgery', wasRefused($response), false);

/* ------------------------------------------------------------------ *
 * The buyer, signed in for the rest of the customer side.
 * ------------------------------------------------------------------ */

$buyer = [];
trustDevice($buyer, $buyerId);
signIn($buyer, 'zztest.csrf.buyer@capstone.test', $password);

$buyerSignedIn = static function () use (&$buyer): string {
    return request('php/profile.php', $buyer)['status'] === 200 ? 'in' : 'out';
};

check('sign-in: the buyer is in', $buyerSignedIn(), 'in');

/* ----------------------------------------------------------- php/profile.php */

/*
 * The email form on this page decides where a password reset would go, which makes a forged one a
 * stolen account. The name form is the one tested because it is the cheapest to read back -- the
 * guard is one line covering all six forms on the page.
 */
refuses('profile', 'php/profile.php', $buyer, [
    'form_type' => 'name',
    'name' => 'ZZTest Csrf Stolen',
], $buyerName);

$response = request('php/profile.php', $buyer, [
    'csrf_token' => tokenFor($buyer),
    'form_type' => 'name',
    'name' => 'ZZTest Csrf Renamed',
]);

check('profile: the real token is accepted', $response['status'], 302);
check('profile: and the name changed', $buyerName(), 'ZZTest Csrf Renamed');

/* -------------------------------------------------------- php/design-save.php */

// Delete is the action that matters here: a bouquet somebody built is gone and nothing puts it
// back. The row is inserted directly because what is being tested is the guard, not the builder.
$database->prepare('INSERT INTO saved_designs (user_id, name) VALUES (:user, :name)')
    ->execute(['user' => $buyerId, 'name' => 'ZZTest Csrf Bouquet']);
$designId = (int) $database->lastInsertId();

check('design: the fixture is saved', $designCount(), '1');

refusesBack('design delete', 'php/design-save.php', $buyer, [
    'action' => 'delete',
    'design_id' => (string) $designId,
], 'php/customize.php', $designCount);

$response = request('php/design-save.php', $buyer, [
    'csrf_token' => tokenFor($buyer),
    'action' => 'delete',
    'design_id' => (string) $designId,
]);

check('design delete: the real token is accepted', $response['status'], 302);
check('design delete: and the bouquet is gone', $designCount(), '0');

/* ----------------------------------------------------------- php/checkout.php */

/**
 * Put the fixture in this browser's basket and post the checkout form.
 *
 * @param array<string, string> $jar
 * @param array<string, string> $overrides
 */
function placeOrder(array &$jar, int $productId, array $overrides = []): array
{
    request('php/cart-update.php', $jar, [
        'csrf_token' => tokenFor($jar),
        'action' => 'add-product',
        'product_id' => (string) $productId,
        'quantity' => '1',
    ]);

    // The token goes in the right-hand array so a caller's $overrides can still replace it: this
    // helper is used for the positive case, but a test that wants a bad token wants its own.
    return request('php/checkout.php', $jar, $overrides + [
        'csrf_token' => tokenFor($jar),
        'fulfilment' => 'delivery',
        'payment_method' => 'cod',
        'payment_reference' => '',
        'contact_name' => 'ZZTest Csrf Buyer',
        'contact_phone' => '0917 555 0101',
        'address_line' => '12 ZZTest Street',
        'barangay' => 'Poblacion',
        'city' => 'Cebu City',
        'province' => 'Cebu',
        'postcode' => '6000',
        'notes' => '',
    ]);
}

// The basket is filled first so the refusals below are refused for the right reason -- an empty
// basket is turned away by checkout.php on its own, which would look identical.
request('php/cart-update.php', $buyer, [
    'csrf_token' => tokenFor($buyer),
    'action' => 'add-product',
    'product_id' => (string) $productId,
    'quantity' => '1',
]);

$buyerBasketHas = static function () use (&$buyer): string {
    return str_contains(request('php/cart.php', $buyer)['body'], 'ZZTest Csrf') ? 'yes' : 'no';
};

check('checkout: the basket is filled first', $buyerBasketHas(), 'yes');

refuses('checkout', 'php/checkout.php', $buyer, [
    'fulfilment' => 'delivery',
    'payment_method' => 'cod',
    'payment_reference' => '',
    'contact_name' => 'ZZTest Csrf Buyer',
    'contact_phone' => '0917 555 0101',
    'address_line' => '12 ZZTest Street',
    'barangay' => 'Poblacion',
    'city' => 'Cebu City',
    'province' => 'Cebu',
    'postcode' => '6000',
    'notes' => '',
], $orderCount);

$response = placeOrder($buyer, $productId);
check('checkout: the real token places the order', $response['status'], 302);
check('checkout: and there is one order', $orderCount(), '1');

if ($response['status'] !== 302) {
    echo '  checkout said: ' . messagesIn($response['body']) . "\n";
}

// A second, so the admin screens and the customer's own cancel are not fighting over one row: an
// order the shop has started cannot be cancelled, which is the rule, not a fault.
placeOrder($buyer, $productId);
check('checkout: and now two', $orderCount(), '2');

$references = $database->query("SELECT reference FROM orders WHERE user_id = $buyerId ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

check('checkout: two references to work with', count($references), 2);

// Falling back to '' rather than letting the list destructure short: one missing order would
// otherwise end the run with a TypeError and take every check below it down unexamined.
$shopOrder = (string) ($references[0] ?? '');
$customerOrder = (string) ($references[1] ?? '');

$statusOf = static fn (string $reference): string => (string) $database
    ->query("SELECT status FROM orders WHERE reference = " . $database->quote($reference))
    ->fetchColumn();

/* ------------------------------------------------------------------ *
 * php/order-cancel.php -- the customer's own undo.
 * ------------------------------------------------------------------ */

$customerOrderUrl = 'php/order.php?ref=' . rawurlencode($customerOrder);
$customerStatus = static fn (): string => $statusOf($customerOrder);

check('cancel: the order starts placed', $customerStatus(), 'placed');

refusesBack('cancel', 'php/order-cancel.php', $buyer, ['ref' => $customerOrder], $customerOrderUrl, $customerStatus);

$response = request('php/order-cancel.php', $buyer, [
    'csrf_token' => tokenFor($buyer),
    'ref' => $customerOrder,
]);

check('cancel: the real token is accepted', $response['status'], 302);
check('cancel: and the order is cancelled', $customerStatus(), 'cancelled');

/* ------------------------------------------------------------------ *
 * php/logout.php -- POST-only was never the whole answer, only the cheap half.
 *
 * A refused logout has to leave them signed in, which is what its toast says. Last of the
 * customer side, because everything above needs this browser still signed in.
 * ------------------------------------------------------------------ */

refusesBack('logout', 'php/logout.php', $buyer, [], 'php/index.php', $buyerSignedIn);

$response = request('php/logout.php', $buyer, ['csrf_token' => tokenFor($buyer)]);

check('logout: the real token is accepted', $response['status'], 302);
check('logout: and they are signed out', $buyerSignedIn(), 'out');

/* ------------------------------------------------------------------ *
 * The admin side. Eight screens behind guard.php, and the three actions Orders added are the
 * reason this whole task exists: deliver cannot be undone from any screen and emails the customer
 * on the way past, so a forged one is not something an apology fixes.
 * ------------------------------------------------------------------ */

$admin = [];
trustDevice($admin, $adminId);
signIn($admin, 'zztest.csrf.admin@capstone.test', $password);

check(
    'sign-in: the admin is in',
    request('php/admin/dashboard.php', $admin)['status'],
    200
);

/* ------------------------------------------------- php/admin/product-edit.php */

/*
 * The row posts its own values back with one field changed, rather than a hand-written field set:
 * this page saves all seven at once, so a guess at an enum would fail validation and the positive
 * would prove nothing about the token.
 */
$row = $database->query("SELECT * FROM products WHERE id = $productId")->fetch(PDO::FETCH_ASSOC);

$productForm = [
    'name' => 'ZZTest Csrf Forged',
    'kind' => (string) $row['kind'],
    'price' => (string) $row['price'],
    'colour' => (string) $row['colour'],
    'description' => (string) $row['description'],
    'stem_count' => (string) $row['stem_count'],
    'is_active' => '1',
];

refuses('product edit', "php/admin/product-edit.php?id=$productId", $admin, $productForm, $productName);

$response = request("php/admin/product-edit.php?id=$productId", $admin, array_merge($productForm, [
    'csrf_token' => tokenFor($admin),
    'name' => 'ZZTest Csrf Renamed',
]));

check('product edit: the real token is accepted', $response['status'], 302);
check('product edit: and the product is renamed', $productName(), 'ZZTest Csrf Renamed');

/* ----------------------------------------------- php/admin/component-edit.php */

$row = $database->query("SELECT * FROM product_components WHERE id = $componentId")->fetch(PDO::FETCH_ASSOC);

$componentForm = [
    'name' => 'ZZTest Csrf Forged',
    'kind' => (string) $row['kind'],
    'price' => (string) $row['price'],
    'colour' => (string) $row['colour'],
    'colour_hex' => (string) $row['colour_hex'],
    'shape' => (string) $row['shape'],
    'description' => (string) $row['description'],
    'sort_order' => (string) $row['sort_order'],
    'is_active' => '1',
];

refuses('component edit', "php/admin/component-edit.php?id=$componentId", $admin, $componentForm, $componentName);

$response = request("php/admin/component-edit.php?id=$componentId", $admin, array_merge($componentForm, [
    'csrf_token' => tokenFor($admin),
    'name' => 'ZZTest Csrf Renamed',
]));

check('component edit: the real token is accepted', $response['status'], 302);
check('component edit: and the part is renamed', $componentName(), 'ZZTest Csrf Renamed');

/* ---------------------------------------------------- php/admin/products.php */

refuses('products', 'php/admin/products.php', $admin, [
    'action' => 'hide',
    'id' => (string) $productId,
], $productActive);

$response = request('php/admin/products.php', $admin, [
    'csrf_token' => tokenFor($admin),
    'action' => 'hide',
    'id' => (string) $productId,
]);

check('products: the real token is accepted', $response['status'], 302);
check('products: and the product is hidden', $productActive(), '0');

/* -------------------------------------------------- php/admin/components.php */

refuses('components', 'php/admin/components.php', $admin, [
    'action' => 'withdraw',
    'id' => (string) $componentId,
], $componentActive);

$response = request('php/admin/components.php', $admin, [
    'csrf_token' => tokenFor($admin),
    'action' => 'withdraw',
    'id' => (string) $componentId,
]);

check('components: the real token is accepted', $response['status'], 302);
check('components: and the part is withdrawn', $componentActive(), '0');

/* ------------------------------------------------------ php/admin/orders.php */

$shopStatus = static fn (): string => $statusOf($shopOrder);

check('orders: the order starts placed', $shopStatus(), 'placed');

refuses('orders', 'php/admin/orders.php', $admin, [
    'action' => 'pack',
    'ref' => $shopOrder,
], $shopStatus);

$response = request('php/admin/orders.php', $admin, [
    'csrf_token' => tokenFor($admin),
    'action' => 'pack',
    'ref' => $shopOrder,
]);

check('orders: the real token is accepted', $response['status'], 302);
check('orders: and the order is being made', $shopStatus(), 'packed');

/* ------------------------------------------------------- php/admin/order.php */

$orderUrl = 'php/admin/order.php?ref=' . rawurlencode($shopOrder);

// deliver, aimed at an order that is ready for it: if the guard were missing, this would go
// through, email the customer, and could not be taken back from any screen.
refuses('order', $orderUrl, $admin, [
    'action' => 'deliver',
    'ref' => $shopOrder,
], $shopStatus);

/*
 * The positive is an action this page does not have, not the delivery itself. Both are answered by
 * applyAdminOrderAction() with a redirect and a flash, so a 302 is proof the guard let the request
 * through -- and it leaves the order where the suite found it. orders.php above already proved a
 * real write goes through with a real token.
 */
$response = request($orderUrl, $admin, [
    'csrf_token' => tokenFor($admin),
    'action' => 'zztest-nonsense',
    'ref' => $shopOrder,
]);

check('order: the real token gets through to the action', $response['status'], 302);
check('order: which had nothing to do, so nothing moved', $shopStatus(), 'packed');

/* ------------------------------------------------------------------ *
 * Every form as a browser actually receives it.
 * ------------------------------------------------------------------ */

/*
 * The static check at the top of this file counts hidden fields per file and cannot tell where they
 * landed. A csrfField() call one line above its <form> instead of one line below it counts exactly
 * the same and reaches the browser as a field belonging to no form -- so the page looks protected,
 * the count balances, and the button does not work. Nothing in the handler sections below would
 * catch it either, because those post their fields directly rather than through the markup.
 *
 * So the rendered HTML is read back and every method="post" form in it is required to contain a
 * csrf_token field between its own tags. This also covers the forms the file count cannot see: the
 * basket, the saved bouquets and the admin queue each write one form per row from a single literal,
 * so one page here is worth several of the 28.
 */
function formsAllCarryTokens(string $label, string $body): void
{
    // Non-greedy, and safe to be: a form inside a form is not valid HTML and does not occur here.
    $found = preg_match_all('#<form\b[^>]*method="post"[^>]*>(.*?)</form>#is', $body, $matches);

    // A page that rendered no form at all is a page that did not render -- signed out, 404, or a
    // fatal. Every page below carries at least the navbar's logout form, so zero is always wrong.
    checkTrue("rendered: $label returned forms to check", $found > 0);

    $missing = 0;

    foreach ($matches[1] as $inner) {
        if (!str_contains($inner, 'name="csrf_token"')) {
            $missing++;
        }
    }

    check("rendered: every post form on $label carries a token", $missing, 0);
}

/*
 * The fixture is put back the way the catalogue expects to find it. The two admin sections above
 * deliberately hid the product and withdrew the component, and a hidden product has no page to
 * render. Both are this suite's own rows, so putting them back writes to nothing seeded.
 */
$database->exec("UPDATE products SET is_active = 1 WHERE id = $productId");
$database->exec("UPDATE product_components SET is_active = 1 WHERE id = $componentId");

// A jar of its own, because $buyer was signed out by the logout section above and the point here is
// the signed-in markup: six of the forms only exist for somebody with an account.
$shopper = [];
trustDevice($shopper, $buyerId);
signIn($shopper, 'zztest.csrf.buyer@capstone.test', $password);

// Checkout redirects an empty basket straight back to the basket, so there would be no form to read.
request('php/cart-update.php', $shopper, [
    'csrf_token' => tokenFor($shopper),
    'action' => 'add-product',
    'product_id' => (string) $productId,
    'quantity' => '1',
]);

/*
 * Jars by value, not by reference: every request below is a GET that only needs the cookies it is
 * given, so nothing here has to collect any back.
 */
$rendered = [
    'index'          => ['php/index.php', $shopper],
    'products'       => ['php/products.php', $shopper],
    'product'        => ["php/product.php?id=$productId", $shopper],
    'cart'           => ['php/cart.php', $shopper],
    'checkout'       => ['php/checkout.php', $shopper],
    'customize'      => ['php/customize.php', $shopper],
    'designs'        => ['php/designs.php', $shopper],
    'profile'        => ['php/profile.php', $shopper],
    'orders'         => ['php/orders.php', $shopper],
    'order'          => ['php/order.php?ref=' . rawurlencode($customerOrder), $shopper],
    'login'          => ['php/login.php', $guest],
    'verify'         => ['php/verify.php', $pending],
    'admin dash'     => ['php/admin/dashboard.php', $admin],
    'admin products' => ['php/admin/products.php', $admin],
    'admin product'  => ["php/admin/product-edit.php?id=$productId", $admin],
    'admin parts'    => ['php/admin/components.php', $admin],
    'admin part'     => ["php/admin/component-edit.php?id=$componentId", $admin],
    'admin queue'    => ['php/admin/orders.php', $admin],
    'admin order'    => ['php/admin/order.php?ref=' . rawurlencode($shopOrder), $admin],

    // Both of these are read-only screens with no form of their own, so what they prove is the
    // navbar's logout form: it is on every page, and a token missing from it would be missing
    // everywhere. They are listed because they are the newest admin pages and were not here when
    // the forms were threaded.
    'admin customers' => ['php/admin/customers.php', $admin],
    'admin reports'   => ['php/admin/reports.php', $admin],
];

foreach ($rendered as $label => [$path, $jar]) {
    formsAllCarryTokens($label, request($path, $jar)['body']);
}

/* ------------------------------------------------------------------ *
 * Teardown.
 * ------------------------------------------------------------------ */

$scrub();

echo implode("\n", $failures) . ($failures === [] ? '' : "\n");
printf("\n%d passed, %d failed\n", $passed, $failed);

$leftover = (int) $database->query("SELECT COUNT(*) FROM users WHERE email LIKE 'zztest.csrf%'")->fetchColumn()
    + (int) $database->query("SELECT COUNT(*) FROM products WHERE name LIKE 'ZZTest Csrf%'")->fetchColumn()
    + (int) $database->query("SELECT COUNT(*) FROM product_components WHERE name LIKE 'ZZTest Csrf%'")->fetchColumn()
    + (int) $database->query("SELECT COUNT(*) FROM order_items WHERE name LIKE 'ZZTest Csrf%'")->fetchColumn()
    // cart_items was missing from this total, and that is how a visitor's basket survived a run
    // undetected: the scrub could not reach it, and the number that would have said so did not
    // count it. A leftover total that does not cover every table the suite writes to is a
    // leftover total that reports 0 and means nothing.
    + (int) $database->query("SELECT COUNT(*) FROM cart_items WHERE name LIKE 'ZZTest Csrf%'")->fetchColumn();

printf("leftover ZZTest rows: %d\n", $leftover);

exit($failed === 0 ? 0 : 1);



