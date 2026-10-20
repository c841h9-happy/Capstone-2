<?php

declare(strict_types=1);

/**
 * The Inventory module, over Apache and against the real database.
 *
 *     C:\xampp\php\php.exe php\tools\inventory-http-test.php
 *
 * The seventh suite, written alongside the module rather than after it: each screen's section
 * lands in the same task as the screen. What it covers, in order:
 *
 *   1. The stock screen -- php/admin/inventory.php
 *   2. The recipe screen -- php/admin/product-parts.php        (added with step 4)
 *   3. The ledger screen -- php/admin/stock-moves.php          (added with step 5)
 *   4. Deleting a part that a recipe still names                (added with step 6)
 *   5. An order taking its parts out -- Stage 2, section 6
 *
 * Nothing here is asserted against a seeded row, and nothing here writes to one. Every fixture
 * is created by this file and prefixed ZZTest; where the shop's own rows would move a number,
 * the assertion is on the delta. The shop's books are checked for balance at both ends, which is
 * the one absolute this file does assert -- because a suite that can leave the ledger disagreeing
 * with the balances has broken the thing it was written to protect.
 *
 * The checks that need arranging rather than observing:
 *
 *  - **Two admins receiving at once**, and **two admins starting the same order at once.** Two
 *    accounts, two cookie jars, both POSTing through curl_multi. One jar cannot race itself: PHP
 *    holds the session file lock for the whole request, so the second request would queue behind
 *    the first and pass under a lost update. The first is what `SELECT ... FOR UPDATE` in
 *    recordStockMove() is for; the second is about the status UPDATE's own WHERE and about
 *    consumeOrderStock() running inside the transaction that UPDATE opened.
 *  - **A shortfall.** Deliberately wasting more than the part has, to prove the count is allowed
 *    below zero and shown as a shortfall rather than refused. An UNSIGNED column would have
 *    failed here with SQLSTATE 22003, which is why the column is signed.
 *  - **A recipe edited between packing and unpacking.** The only arrangement that can tell
 *    returnOrderStock() reading the ledger apart from it reading the recipe, and the reason
 *    section 6 must never be rewritten to compare against product_parts.
 */

// Same guard as the suites beside this one. This file is under the webroot, so without it a
// stranger could run it -- and it creates admin accounts and moves stock.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const BASE = 'http://localhost/Capstone/';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/inventory.php';
// adminInventorySummary() lives here, not in config/inventory.php: it is the dashboard's reading
// of the stock figures rather than part of the module, and the dashboard is the only caller.
require_once __DIR__ . '/../config/admin.php';

$database = getDatabaseConnection();

// A notice has to be as loud as a failure: the point is to catch an undefined index that the
// browser would swallow into a blank cell.
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
    printf("  FAIL  %s\n          expected: %s\n          actual:   %s\n", $label, var_export($expected, true), var_export($actual, true));
}

function checkTrue(string $label, mixed $actual): void
{
    check($label, $actual === true, true);
}

function tell(string $label, mixed $value): void
{
    echo sprintf("  %-46s %s\n", $label, is_scalar($value) || $value === null ? var_export($value, true) : gettype($value));
}

/**
 * One request. The jar is passed by reference and updated from Set-Cookie, as in the suites
 * beside this one; a session id is a capability, so it is never printed.
 *
 * @param array<string, string> $jar
 * @param array<string, string>|null $post
 * @return array{status: int, body: string, location: string}
 */
function request(string $path, array &$jar, ?array $post = null): array
{
    $context = ['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        // Not followed, because the redirect IS the assertion on every POST here.
        'follow_location' => 0,
        'ignore_errors' => true,
        'timeout' => 30,
        'header' => [],
    ]];

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
    $location = '';

    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
        }
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
        if (stripos($header, 'Set-Cookie:') === 0
            && preg_match('#^Set-Cookie:\s*([^=]+)=([^;]*)#i', $header, $m) === 1) {
            if ($m[2] === '') {
                unset($jar[trim($m[1])]);
            } else {
                $jar[trim($m[1])] = $m[2];
            }
        }
    }

    return ['status' => $status, 'body' => $body === false ? '' : $body, 'location' => $location];
}

/** The CSRF token off a page this jar is allowed to see. */
function tokenFrom(string $path, array &$jar): string
{
    $body = request($path, $jar)['body'];
    preg_match('#name="csrf_token" value="([a-f0-9]+)"#', $body, $m);

    return $m[1] ?? '';
}

/** What the database says the part is at, read straight rather than through the module. */
function stockOf(int $componentId): int
{
    $statement = getDatabaseConnection()->prepare('SELECT stock_on_hand FROM product_components WHERE id = :id');
    $statement->execute(['id' => $componentId]);

    return (int) $statement->fetchColumn();
}

function materialStockOf(int $materialId): float
{
    $statement = getDatabaseConnection()->prepare('SELECT stock_on_hand FROM materials WHERE id = :id');
    $statement->execute(['id' => $materialId]);
    return (float) $statement->fetchColumn();
}

/** How many ledger rows the part has. */
function movesOf(int $componentId): int
{
    $statement = getDatabaseConnection()->prepare('SELECT COUNT(*) FROM stock_moves WHERE component_id = :id');
    $statement->execute(['id' => $componentId]);

    return (int) $statement->fetchColumn();
}

/** Where an order has got to, read straight. */
function orderStatusOf(string $reference): string
{
    $statement = getDatabaseConnection()->prepare('SELECT status FROM orders WHERE reference = :reference');
    $statement->execute(['reference' => $reference]);

    return (string) $statement->fetchColumn();
}

/**
 * Every movement written for one order, oldest first.
 *
 * Ordered by id and not by created_at, for the reason STOCK_MOVE_SORTS is: two rows written in one
 * transaction share a second, and a DATETIME cannot rank them. The id can, always.
 *
 * @return array<int, array<string, mixed>>
 */
function orderMovesOf(int $orderId): array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT reason, delta, balance_after, order_id, user_id, component_id, component_name, note
           FROM stock_moves
          WHERE order_id = :order
          ORDER BY id ASC'
    );
    $statement->execute(['order' => $orderId]);

    return $statement->fetchAll();
}

/** Everything a toast said on this page, decoded, so a message can be asserted on. */
function toastText(string $html): string
{
    return preg_match_all('/class="toast-message">([^<]*)</', $html, $m) > 0
        ? html_entity_decode(implode(' | ', $m[1]), ENT_QUOTES, 'UTF-8')
        : '';
}

/**
 * Just the rows of the list, so a row assertion cannot be answered by something else on the page.
 *
 * The first failure this file found was exactly that. "The low filter lets it go" searched the
 * whole response for the part's name, and the response still carried the success toast from the
 * POST before it -- *No more low-stock warnings for "ZZTest Inv Bloom"* -- while the table
 * underneath correctly showed nothing at all. The screen was right and the check was wrong.
 *
 * An empty state has no <tbody>, so this returns '' for it, which is the honest answer to "which
 * rows are in the list": none.
 */
function tableBody(string $html): string
{
    if (preg_match('#<tbody>(.*?)</tbody>#s', $html, $m) !== 1) {
        return '';
    }

    return $m[1];
}

/**
 * Just the standing alarm at the top of the ledger, for the same reason as tableBody().
 *
 * The audit banner names the parts whose books do not add up -- and every one of those names
 * is also in the filter dropdown and in the table underneath, so a page-wide search for one
 * would pass whether the banner was drawn or not. Scoped to the banner, it cannot.
 *
 * No banner returns '', which is the honest answer to "which parts is it complaining about":
 * none.
 */
function noticeBlock(string $html): string
{
    if (preg_match('#<div class="notice notice-alarm">(.*?)</div>#s', $html, $m) !== 1) {
        return '';
    }

    return $m[1];
}

/**
 * Just "What it takes out of stock" on php/admin/order.php, for tableBody()'s reason.
 *
 * Scoped to the section and not to the page, because the page carries the flash toast from the
 * POST that got here -- and that toast says "12 parts came out of stock" in so many words. A
 * page-wide search for a shortfall would be answered by the message about the shortfall rather
 * than by the panel, which is the exact failure tableBody()'s docblock records.
 *
 * Non-greedy to the first </section>, which is this panel's own: it has no nested section.
 *
 * '' means the panel is not there at all, which on an order page means something is wrong -- so
 * the checks below assert against a non-empty string rather than trusting a substring search of
 * nothing to fail.
 */
function stockPanel(string $html): string
{
    if (preg_match('#<h2>What it takes out of stock</h2>(.*?)</section>#s', $html, $m) !== 1) {
        return '';
    }

    return $m[1];
}

/**
 * Remove every trace of this file's fixtures, innermost first.
 *
 * Run at both ends: once before the fixtures are made, to clear anything a previous run
 * stranded, and once after. Each statement stands alone and a failure is reported rather than
 * thrown -- with ERRMODE_EXCEPTION one bad statement would abandon every statement after it, and
 * a cleanup that can strand rows is worse than no cleanup at all.
 *
 * product_parts is ON DELETE CASCADE from both its parents, so deleting either end takes the
 * recipe row with it. stock_moves is ON DELETE SET NULL and is therefore listed explicitly: it
 * survives its part on purpose, which is the whole design of the ledger.
 *
 * The orders line is Stage 2's, and it is one statement rather than four because order_items,
 * order_item_parts and order_events all CASCADE from `orders` -- so deleting the order takes its
 * lines, their parts and its history with it. It has to run **before** the users delete, because
 * fk_orders_user is ON DELETE RESTRICT: an account with an order still on it cannot be removed,
 * and the sweep would strand every fixture from the reference down.
 *
 * It matches on contact_name and not on the reference, because a reference cannot be marked. The
 * admin order screen reads its ref through orderReferenceFromInput(), which accepts nothing but
 * /^SLJ-\d{8}-[23456789A-HJ-NP-Z]{4}$/ -- so a fixture reference has to look exactly like a real
 * one, and a LIKE 'ZZ%' on that column would match nothing at all. contact_name is free text this
 * file owns, and 'ZZTest Inv Buyer' cannot be a row the shop wrote.
 *
 * @return string[] the statements that failed, if any
 */
function sweepFixtures(PDO $database): array
{
    $broken = [];

    foreach ([
        "DELETE FROM material_moves WHERE material_name LIKE 'ZZTest%'",
        "DELETE FROM product_materials WHERE product_id IN (SELECT id FROM products WHERE name LIKE 'ZZTest%')",
        "DELETE FROM stock_moves WHERE component_name LIKE 'ZZTest%'",
        "DELETE FROM product_parts WHERE product_id IN (SELECT id FROM products WHERE name LIKE 'ZZTest%')",
        "DELETE FROM product_parts WHERE component_id IN (SELECT id FROM product_components WHERE name LIKE 'ZZTest%')",
        "DELETE FROM orders WHERE contact_name LIKE 'ZZTest%'",
        "DELETE FROM products WHERE name LIKE 'ZZTest%'",
        "DELETE FROM materials WHERE name LIKE 'ZZTest%'",
        "DELETE FROM product_components WHERE name LIKE 'ZZTest%'",
        "DELETE FROM trusted_devices WHERE label LIKE 'ZZTest inventory%'",
        "DELETE FROM email_codes WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'zztest.inv.%@capstone.test')",
        "DELETE FROM users WHERE email LIKE 'zztest.inv.%@capstone.test'",
    ] as $sql) {
        try {
            $database->exec($sql);
        } catch (PDOException $exception) {
            $broken[] = $sql . ' -- ' . explode("\n", $exception->getMessage())[0];
        }
    }

    return $broken;
}

/* ------------------------------------------------------------------ *
 * Baselines, taken before a single fixture exists.
 * ------------------------------------------------------------------ */

heading('the shop, before this file touches anything');

$strandedBefore = sweepFixtures($database);
check('nothing was stranded by a previous run', $strandedBefore, []);

$baseParts = countStockLevels();
$baseMoves = countStockMoves();
$baseSummary = stockSummary();
$baseMaterials = (int) $database->query('SELECT COUNT(*) FROM materials')->fetchColumn();
$baseMaterialMoves = (int) $database->query('SELECT COUNT(*) FROM material_moves')->fetchColumn();
$baseMaterialUnits = (float) $database->query('SELECT COALESCE(SUM(stock_on_hand), 0) FROM materials')->fetchColumn();

tell('parts', $baseParts);
tell('ledger rows', $baseMoves);
tell('units in stock', $baseSummary['units']);
check('the books balance before we start', stockAuditDiscrepancies(), []);

/* ------------------------------------------------------------------ *
 * Fixtures: two admins, one customer, one part, one product.
 * ------------------------------------------------------------------ */

$password = 'ZzTestInv123!';
$hash = password_hash($password, PASSWORD_DEFAULT);

$makeUser = $database->prepare(
    'INSERT INTO users (name, email, password_hash, role, email_verified_at)
     VALUES (:name, :email, :hash, :role, NOW())'
);

$accounts = [];

foreach ([
    'admin-a' => ['ZZTest Inv Admin A', 'zztest.inv.a@capstone.test', 'admin'],
    'admin-b' => ['ZZTest Inv Admin B', 'zztest.inv.b@capstone.test', 'admin'],
    'customer' => ['ZZTest Inv Customer', 'zztest.inv.cust@capstone.test', 'customer'],
] as $key => [$name, $email, $role]) {
    $makeUser->execute(['name' => $name, 'email' => $email, 'hash' => $hash, 'role' => $role]);
    $accounts[$key] = ['id' => (int) $database->lastInsertId(), 'email' => $email];
}

$database->prepare(
    "INSERT INTO product_components (name, kind, description, price, colour, colour_hex, shape, sort_order, is_active)
     VALUES ('ZZTest Inv Bloom', 'bloom', 'Fixture.', 12.00, 'Test', '#c47f8f', 'rose', 900, 1)"
)->execute();
$partId = (int) $database->lastInsertId();

// A second part, withdrawn, so the status filter has something to find that the default view does
// not show.
$database->prepare(
    "INSERT INTO product_components (name, kind, description, price, colour, colour_hex, sort_order, is_active)
     VALUES ('ZZTest Inv Wrap', 'wrap', 'Fixture.', 8.00, 'Test', '#d9d2c4', 901, 0)"
)->execute();
$wrapId = (int) $database->lastInsertId();

$database->prepare(
    "INSERT INTO products (name, kind, description, price, colour, stem_count, is_active)
     VALUES ('ZZTest Inv Bouquet', 'bouquet', 'Fixture.', 400.00, 'Test', 3, 0)"
)->execute();
$productId = (int) $database->lastInsertId();

heading('the fixtures');
tell('part', $partId);
tell('withdrawn part', $wrapId);
tell('product', $productId);
check('the part starts at zero', stockOf($partId), 0);
check('with no history', movesOf($partId), 0);

/* ------------------------------------------------------------------ *
 * Signing in. Both admins get their own jar; the customer gets a third.
 * ------------------------------------------------------------------ */

/**
 * Sign this jar in, skipping the emailed code by trusting the device.
 *
 * The mail log holds live codes for real accounts and this test has no business reading it, so
 * the trusted-device row is written directly and its token put in the jar -- the same approach
 * php/tools/admin-http-test.php takes.
 *
 * @param array<string, string> $jar
 */
function signIn(PDO $database, array &$jar, int $userId, string $email, string $password): void
{
    $token = tokenFrom('php/login.php', $jar);

    $deviceToken = bin2hex(random_bytes(32));
    $database->prepare(
        'INSERT INTO trusted_devices (user_id, token_hash, label, expires_at)
         VALUES (:user, :hash, :label, DATE_ADD(NOW(), INTERVAL 1 DAY))'
    )->execute(['user' => $userId, 'hash' => hash('sha256', $deviceToken), 'label' => 'ZZTest inventory suite']);
    $jar['capstone_device'] = $deviceToken;

    request('php/login.php', $jar, [
        'csrf_token' => $token,
        'form_type' => 'login',
        'identifier' => $email,
        'password' => $password,
    ]);
}

$jarA = [];
$jarB = [];
$jarCustomer = [];
$jarStranger = [];

signIn($database, $jarA, $accounts['admin-a']['id'], $accounts['admin-a']['email'], $password);
signIn($database, $jarB, $accounts['admin-b']['id'], $accounts['admin-b']['email'], $password);
signIn($database, $jarCustomer, $accounts['customer']['id'], $accounts['customer']['email'], $password);

/* ------------------------------------------------------------------ *
 * 1. The stock screen.
 * ------------------------------------------------------------------ */

heading('who may see the stock screen');

$asStranger = request('php/admin/inventory.php', $jarStranger);
checkTrue('a signed-out visitor is sent to log in', $asStranger['status'] === 302 || $asStranger['status'] === 303);
checkTrue('and is shown nothing of the screen', !str_contains($asStranger['body'], 'Move stock'));

$asCustomer = request('php/admin/inventory.php', $jarCustomer);
check('a signed-in customer is refused', $asCustomer['status'], 403);
checkTrue('and is shown nothing of the screen', !str_contains($asCustomer['body'], 'Move stock'));

heading('the screen itself, as an admin');

$page = request('php/admin/inventory.php?q=ZZTest+Inv&sort=name', $jarA);
check('it loads', $page['status'], 200);

$complaints = [];
foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
    if (str_contains($page['body'], $needle)) {
        $complaints[] = $needle;
    }
}
check('with nothing PHP wants to say about it', $complaints, []);

// Scoped to the rows, not the whole response, for the reason tableBody() gives.
$rows = tableBody($page['body']);
checkTrue('the part is listed', str_contains($rows, 'ZZTest Inv Bloom'));
checkTrue('the withdrawn part is listed too', str_contains($rows, 'ZZTest Inv Wrap'));
checkTrue('a withdrawn part is labelled as one', str_contains($rows, 'pill-hidden'));
checkTrue('a part at zero is flagged Out', str_contains($rows, '>Out<'));
checkTrue('the three movement buttons are there', str_contains($rows, 'value="receive"')
    && str_contains($rows, 'value="set"')
    && str_contains($rows, 'value="waste"'));
checkTrue('every row form carries a token', str_contains($rows, 'name="csrf_token"'));

$statusFiltered = tableBody(request('php/admin/inventory.php?q=ZZTest+Inv&status=hidden', $jarA)['body']);
checkTrue('the status filter keeps the withdrawn part', str_contains($statusFiltered, 'ZZTest Inv Wrap'));
checkTrue('and drops the offered one', !str_contains($statusFiltered, 'ZZTest Inv Bloom'));

$kindFiltered = tableBody(request('php/admin/inventory.php?q=ZZTest+Inv&kind=bloom', $jarA)['body']);
checkTrue('the kind filter keeps the bloom', str_contains($kindFiltered, 'ZZTest Inv Bloom'));
checkTrue('and drops the wrap', !str_contains($kindFiltered, 'ZZTest Inv Wrap'));

$noMatch = request('php/admin/inventory.php?q=ZZNothingMatchesThis', $jarA)['body'];
checkTrue('an empty result says nothing matches', str_contains($noMatch, 'Nothing matches that'));
checkTrue('rather than offering to add the first part', !str_contains($noMatch, 'There are no parts yet'));

heading('a forged POST changes nothing');

$before = stockOf($partId);
$forged = request('php/admin/inventory.php', $jarA, ['action' => 'receive', 'id' => (string) $partId, 'qty' => '500']);
check('it is refused', $forged['status'], 403);
checkTrue('and told the admin nothing was changed', str_contains($forged['body'], 'Nothing has been changed'));
check('and the count did not move', stockOf($partId), $before);
check('and nothing was written to the ledger', movesOf($partId), 0);

heading('receiving stock');

$token = tokenFrom('php/admin/inventory.php?q=ZZTest+Inv', $jarA);
checkTrue('the screen gave us a token', $token !== '');

$received = request('php/admin/inventory.php?q=ZZTest+Inv', $jarA, [
    'csrf_token' => $token,
    'action' => 'receive',
    'id' => (string) $partId,
    'qty' => '40',
    'note' => 'ZZTest delivery',
]);
check('the POST answers with a redirect, not a page', $received['status'], 302);
checkTrue('back to the same filtered list', str_contains($received['location'], 'q=ZZTest'));
check('the count went up', stockOf($partId), 40);
check('and one ledger row was written', movesOf($partId), 1);

$row = $database->prepare("SELECT reason, delta, balance_after, note, user_id FROM stock_moves WHERE component_id = :id ORDER BY id DESC LIMIT 1");
$row->execute(['id' => $partId]);
$move = $row->fetch();
check('recorded as received', $move['reason'], 'received');
check('with the right delta', (int) $move['delta'], 40);
check('and the balance it left behind', (int) $move['balance_after'], 40);
check('and the note the admin typed', $move['note'], 'ZZTest delivery');
check('and who did it', (int) $move['user_id'], $accounts['admin-a']['id']);

$afterReceive = request('php/admin/inventory.php?q=ZZTest+Inv', $jarA)['body'];
checkTrue('the new count is on the screen', str_contains(tableBody($afterReceive), '<strong>40</strong>'));
checkTrue('and the summary line counts the units', str_contains($afterReceive, 'in stock'));

heading('a blank quantity box is refused');

$token = tokenFrom('php/admin/inventory.php?q=ZZTest+Inv', $jarA);
request('php/admin/inventory.php?q=ZZTest+Inv', $jarA, [
    'csrf_token' => $token,
    'action' => 'set',
    'id' => (string) $partId,
    'qty' => '',
]);
check('an empty box did not empty the drawer', stockOf($partId), 40);
check('and wrote nothing', movesOf($partId), 1);

$token = tokenFrom('php/admin/inventory.php?q=ZZTest+Inv', $jarA);
request('php/admin/inventory.php?q=ZZTest+Inv', $jarA, [
    'csrf_token' => $token,
    'action' => 'receive',
    'id' => (string) $partId,
    'qty' => 'lots',
]);
check('and neither did a word', stockOf($partId), 40);
check('nor wrote anything', movesOf($partId), 1);

heading('the stock-take');

$token = tokenFrom('php/admin/inventory.php?q=ZZTest+Inv', $jarA);
request('php/admin/inventory.php?q=ZZTest+Inv', $jarA, [
    'csrf_token' => $token,
    'action' => 'set',
    'id' => (string) $partId,
    'qty' => '37',
    'note' => 'ZZTest counted the box',
]);
check('the count is what we counted', stockOf($partId), 37);
check('and the correction is in the ledger', movesOf($partId), 2);

$row->execute(['id' => $partId]);
$move = $row->fetch();
check('recorded as a stock-take', $move['reason'], 'adjusted');
check('carrying the difference, not the total', (int) $move['delta'], -3);
check('and the new balance', (int) $move['balance_after'], 37);

// The same number again. A stock-take that confirms the books is not a movement, and a ledger
// full of zero-delta rows is how a history stops being readable.
$token = tokenFrom('php/admin/inventory.php?q=ZZTest+Inv', $jarA);
request('php/admin/inventory.php?q=ZZTest+Inv', $jarA, [
    'csrf_token' => $token,
    'action' => 'set',
    'id' => (string) $partId,
    'qty' => '37',
]);
check('counting the same number again changes nothing', stockOf($partId), 37);
check('and writes no row', movesOf($partId), 2);

heading('the warning level');

$token = tokenFrom('php/admin/inventory.php?q=ZZTest+Inv', $jarA);
request('php/admin/inventory.php?q=ZZTest+Inv', $jarA, [
    'csrf_token' => $token,
    'action' => 'warn',
    'id' => (string) $partId,
    'qty' => '50',
]);

$threshold = $database->prepare('SELECT low_stock_threshold FROM product_components WHERE id = :id');
$threshold->execute(['id' => $partId]);
check('the threshold is set', (int) $threshold->fetchColumn(), 50);
check('and it wrote no ledger row -- it is not a movement', movesOf($partId), 2);
check('and it did not touch the count', stockOf($partId), 37);

$lowPage = request('php/admin/inventory.php?q=ZZTest+Inv&low=yes', $jarA)['body'];
$lowRows = tableBody($lowPage);
checkTrue('the low filter finds it', str_contains($lowRows, 'ZZTest Inv Bloom'));
checkTrue('and it is flagged Low', str_contains($lowRows, '>Low<'));
checkTrue('and the wrap, with no threshold, is not in the list', !str_contains($lowRows, 'ZZTest Inv Wrap'));

$token = tokenFrom('php/admin/inventory.php?q=ZZTest+Inv', $jarA);
request('php/admin/inventory.php?q=ZZTest+Inv', $jarA, [
    'csrf_token' => $token,
    'action' => 'warn',
    'id' => (string) $partId,
    'qty' => '0',
]);
$threshold->execute(['id' => $partId]);
check('zero turns the warning off', (int) $threshold->fetchColumn(), 0);

$lowPage = request('php/admin/inventory.php?q=ZZTest+Inv&low=yes', $jarA)['body'];
checkTrue('and the low filter lets it go', !str_contains(tableBody($lowPage), 'ZZTest Inv Bloom'));
checkTrue('saying so in its own words', str_contains($lowPage, 'Nothing is low'));

heading('a shortfall is recorded, not refused');

$token = tokenFrom('php/admin/inventory.php?q=ZZTest+Inv', $jarA);
$shortfall = request('php/admin/inventory.php?q=ZZTest+Inv', $jarA, [
    'csrf_token' => $token,
    'action' => 'waste',
    'id' => (string) $partId,
    'qty' => '50',
    'note' => 'ZZTest dropped the lot',
]);
check('the POST still redirects', $shortfall['status'], 302);
check('the count went below zero', stockOf($partId), -13);
check('and the write-off is in the ledger', movesOf($partId), 3);

$row->execute(['id' => $partId]);
$move = $row->fetch();
check('recorded as wasted', $move['reason'], 'wasted');
check('with a negative delta', (int) $move['delta'], -50);
check('and a negative balance', (int) $move['balance_after'], -13);

$shortPage = request('php/admin/inventory.php?q=ZZTest+Inv', $jarA)['body'];
checkTrue('the screen says how short it is', str_contains(tableBody($shortPage), 'Short 13'));
check('and the books still balance', stockAuditDiscrepancies(), []);

// Back to something sane before the concurrency test, so its arithmetic starts from a known number.
$token = tokenFrom('php/admin/inventory.php?q=ZZTest+Inv', $jarA);
request('php/admin/inventory.php?q=ZZTest+Inv', $jarA, [
    'csrf_token' => $token,
    'action' => 'set',
    'id' => (string) $partId,
    'qty' => '0',
    'note' => 'ZZTest reset before the race',
]);
check('reset to zero for the race', stockOf($partId), 0);

/* ------------------------------------------------------------------ *
 * Two admins receiving at the same instant.
 * ------------------------------------------------------------------ */

heading('two admins receiving at once');

if (!function_exists('curl_multi_init')) {
    echo "  SKIP  curl is not loaded in this PHP -- the race cannot be run\n";
} else {
    $tokenA = tokenFrom('php/admin/inventory.php?q=ZZTest+Inv', $jarA);
    $tokenB = tokenFrom('php/admin/inventory.php?q=ZZTest+Inv', $jarB);
    checkTrue('both admins have a token', $tokenA !== '' && $tokenB !== '');

    $movesBefore = movesOf($partId);

    $multi = curl_multi_init();
    $handles = [];

    foreach ([[$jarA, $tokenA, '60'], [$jarB, $tokenB, '25']] as $index => [$jar, $jarToken, $amount]) {
        $pairs = [];
        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $handle = curl_init(BASE . 'php/admin/inventory.php?q=ZZTest+Inv');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'csrf_token' => $jarToken,
                'action' => 'receive',
                'id' => (string) $partId,
                'qty' => $amount,
                'note' => 'ZZTest race ' . $index,
            ]),
            CURLOPT_COOKIE => implode('; ', $pairs),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }

    $running = null;
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 1.0);
    } while ($running > 0);

    $statuses = [];
    foreach ($handles as $handle) {
        $statuses[] = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    tell('both statuses', implode(', ', array_map('strval', $statuses)));
    check('both requests were accepted', $statuses, [302, 302]);

    // The assertion the whole section exists for. Without the FOR UPDATE in recordStockMove(),
    // one of these reads the balance before the other's UPDATE lands and the total comes out at
    // 60 or 25 instead of 85.
    check('the two deliveries added up', stockOf($partId), 85);
    check('and both left a ledger row', movesOf($partId) - $movesBefore, 2);

    $sum = $database->prepare('SELECT COALESCE(SUM(delta), 0) FROM stock_moves WHERE component_id = :id');
    $sum->execute(['id' => $partId]);
    check('the ledger sums to the balance', (int) $sum->fetchColumn(), 85);

    // The two rows' balance_after values must be 60-and-85 or 25-and-85 in id order -- whichever
    // went second has to have seen the other's number. Both reading the same balance is the exact
    // shape of a lost update, even when the totals happen to come out right.
    $ordered = $database->prepare('SELECT balance_after FROM stock_moves WHERE component_id = :id ORDER BY id DESC LIMIT 2');
    $ordered->execute(['id' => $partId]);
    $tail = array_map('intval', $ordered->fetchAll(PDO::FETCH_COLUMN));
    tell('the last two balances, newest first', implode(', ', array_map('strval', $tail)));
    check('the second one saw the first', $tail[0], 85);
    checkTrue('and they are not the same number', $tail[0] !== $tail[1]);
}

check('the books still balance', stockAuditDiscrepancies(), []);

/* ------------------------------------------------------------------ *
 * 2. The recipe screen.
 *
 * Nothing in this section moves stock, and the last two checks in it are
 * the ones that say so: a recipe is what a bouquet is made of, not a
 * withdrawal from the drawer. Taking parts out when an order is made is
 * Stage 2's.
 * ------------------------------------------------------------------ */

heading('who may see the recipe screen');

$stockBeforeRecipe = stockOf($partId);
$movesBeforeRecipe = movesOf($partId);

$recipeUrl = 'php/admin/product-parts.php?id=' . $productId;

$asStranger = request($recipeUrl, $jarStranger);
checkTrue('a signed-out visitor is sent to log in', $asStranger['status'] === 302 || $asStranger['status'] === 303);
checkTrue('and is shown nothing of the screen', !str_contains($asStranger['body'], 'Save the recipe'));

$asCustomer = request($recipeUrl, $jarCustomer);
check('a signed-in customer is refused', $asCustomer['status'], 403);
checkTrue('and is shown nothing of the screen', !str_contains($asCustomer['body'], 'Save the recipe'));

heading('a recipe for a product that is not there');

$missing = request('php/admin/product-parts.php?id=2147483600', $jarA);
check('an unknown id answers not found', $missing['status'], 404);
checkTrue('and says so', str_contains($missing['body'], 'Product not found'));

// No add mode on this screen, so a missing id and a word are the same broken link rather than
// an invitation to make something new.
check('a missing id answers the same', request('php/admin/product-parts.php', $jarA)['status'], 404);
check('and so does a word', request('php/admin/product-parts.php?id=abc', $jarA)['status'], 404);

heading('the screen itself, with nothing in the recipe yet');

$page = request($recipeUrl, $jarA);
check('it loads', $page['status'], 200);

$complaints = [];
foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
    if (str_contains($page['body'], $needle)) {
        $complaints[] = $needle;
    }
}
check('with nothing PHP wants to say about it', $complaints, []);

checkTrue('it names the product', str_contains($page['body'], 'ZZTest Inv Bouquet'));
checkTrue('the offered part has a box', str_contains($page['body'], 'name="qty[' . $partId . ']"'));
// The withdrawn one is not in this recipe, so it is not offered. The next section proves the
// other half of that rule: once it IS in the recipe, it gets a box whether it is offered or not.
checkTrue('the withdrawn part has none', !str_contains($page['body'], 'name="qty[' . $wrapId . ']"'));
checkTrue('an empty recipe is unknown, not zero', str_contains($page['body'], 'Nobody has said what this is made of yet'));
checkTrue('the form carries a token', str_contains($page['body'], 'name="csrf_token"'));

heading('a forged POST changes nothing');

$forged = request($recipeUrl, $jarA, ['qty' => [$partId => '9']]);
check('it is refused', $forged['status'], 403);
checkTrue('and told the admin nothing was changed', str_contains($forged['body'], 'Nothing has been changed'));
check('and the recipe is still empty', count(fetchProductParts($productId)), 0);

heading('a POST with no boxes at all is refused, not obeyed');

// The guard that matters most on this screen. saveProductParts() reads its argument as the whole
// recipe, so an empty submission would mean "made of nothing" -- and a form that lost its field
// would wipe a recipe with nobody having pressed anything that said so.
$token = tokenFrom($recipeUrl, $jarA);
$saveNothing = request($recipeUrl, $jarA, ['csrf_token' => $token]);
check('it redirects rather than saving', $saveNothing['status'], 302);
check('and the recipe is untouched', count(fetchProductParts($productId)), 0);
checkTrue('and it says why', str_contains(request($recipeUrl, $jarA)['body'], 'Nothing was submitted'));

heading('saving a recipe');

$token = tokenFrom($recipeUrl, $jarA);
$saved = request($recipeUrl, $jarA, ['csrf_token' => $token, 'qty' => [$partId => '3']]);
check('the POST answers with a redirect, not a page', $saved['status'], 302);
checkTrue('back to the same recipe', str_contains($saved['location'], 'product-parts.php?id=' . $productId));

$recipe = fetchProductParts($productId);
check('one part is in it', count($recipe), 1);
check('with the quantity that was typed', (int) $recipe[0]['quantity'], 3);
check('and it is the right part', (int) $recipe[0]['component_id'], $partId);

// 85 in stock, 3 to a bouquet. The answer the whole module exists to give.
check('buildableCount() divides the stock', buildableCount($productId), 28);

$afterSave = request($recipeUrl, $jarA)['body'];
checkTrue('the screen says how many could be made', str_contains($afterSave, '<strong>28</strong>'));
checkTrue('and which part decides that', str_contains($afterSave, 'Held up by'));
checkTrue('and the row says it too', str_contains($afterSave, 'enough for 28'));
checkTrue('and the box holds what was saved', str_contains($afterSave, 'value="3"'));

heading('the same recipe twice');

$token = tokenFrom($recipeUrl, $jarA);
request($recipeUrl, $jarA, ['csrf_token' => $token, 'qty' => [$partId => '3']]);
check('still one part', count(fetchProductParts($productId)), 1);
checkTrue('and it says there was nothing to change', str_contains(request($recipeUrl, $jarA)['body'], 'Nothing to change'));

heading('the flower count on the product, against the recipe');

// The product advertises 3 flowers. Three rose heads agrees with that; five does not, and saying
// so is the point -- neither number is corrected, because only a person knows which is wrong.
checkTrue('three blooms agrees with the catalogue', str_contains(request($recipeUrl, $jarA)['body'], 'the recipe agrees'));

$token = tokenFrom($recipeUrl, $jarA);
request($recipeUrl, $jarA, ['csrf_token' => $token, 'qty' => [$partId => '5']]);
$disagreeing = request($recipeUrl, $jarA)['body'];
check('the recipe took the new number', (int) fetchProductParts($productId)[0]['quantity'], 5);
checkTrue('and the disagreement is flagged', str_contains($disagreeing, 'The recipe makes 5'));
checkTrue('without changing the product', (int) $database->query("SELECT stem_count FROM products WHERE id = $productId")->fetchColumn() === 3);

heading('junk is refused whole');

$token = tokenFrom($recipeUrl, $jarA);
$junk = request($recipeUrl, $jarA, ['csrf_token' => $token, 'qty' => [$partId => 'twelve']]);
// A page, not a redirect: the one wrong box is somewhere in a list of them, and a save that came
// back empty would mean typing the rest again.
check('it re-renders instead of redirecting', $junk['status'], 200);
checkTrue('saying what a quantity has to be', str_contains($junk['body'], 'whole number'));
checkTrue('with the typed value still in the box', str_contains($junk['body'], 'value="twelve"'));
check('and the recipe is untouched', (int) fetchProductParts($productId)[0]['quantity'], 5);

$token = tokenFrom($recipeUrl, $jarA);
$overCap = request($recipeUrl, $jarA, ['csrf_token' => $token, 'qty' => [$partId => (string) (PART_QUANTITY_MAX + 1)]]);
check('over the cap is refused too', $overCap['status'], 200);
check('and changes nothing', (int) fetchProductParts($productId)[0]['quantity'], 5);

heading('a withdrawn part that is already in the recipe');

$token = tokenFrom($recipeUrl, $jarA);
request($recipeUrl, $jarA, ['csrf_token' => $token, 'qty' => [$partId => '5', $wrapId => '1']]);
check('both parts are in it', count(fetchProductParts($productId)), 2);

/*
 * The check this whole read exists for.
 *
 * The form is the whole recipe, so a part with no box on the page is a part the next Save
 * deletes. Drawing only the offered parts would strip a withdrawn wrap out of the recipe the
 * first time anybody pressed Save -- silently, and months before anyone noticed.
 */
$withWrap = request($recipeUrl, $jarA)['body'];
checkTrue('it still gets a box', str_contains($withWrap, 'name="qty[' . $wrapId . ']"'));
checkTrue('and is labelled withdrawn', str_contains($withWrap, 'pill-hidden'));

$token = tokenFrom($recipeUrl, $jarA);
request($recipeUrl, $jarA, ['csrf_token' => $token, 'qty' => [$partId => '5', $wrapId => '1']]);
check('and a save that redraws the page keeps it', count(fetchProductParts($productId)), 2);

heading('emptying a box takes the part out');

$token = tokenFrom($recipeUrl, $jarA);
request($recipeUrl, $jarA, ['csrf_token' => $token, 'qty' => [$partId => '', $wrapId => '1']]);
$left = fetchProductParts($productId);
check('one part is left', count($left), 1);
check('and it is the one still in a box', (int) $left[0]['component_id'], $wrapId);

// Zero says the same thing as an empty box, which is what makes a form full of empty boxes
// mean "not in this bouquet" rather than "I have not decided".
$token = tokenFrom($recipeUrl, $jarA);
request($recipeUrl, $jarA, ['csrf_token' => $token, 'qty' => [$partId => '', $wrapId => '0']]);
check('and zero empties the recipe', count(fetchProductParts($productId)), 0);
check('which is unknown again, not none', buildableCount($productId), null);

heading('the links that lead here');

$productRow = tableBody(request('php/admin/products.php?q=ZZTest+Inv', $jarA)['body']);
checkTrue('the products list links to the recipe', str_contains($productRow, 'product-parts.php?id=' . $productId));

$editPage = request('php/admin/product-edit.php?id=' . $productId, $jarA)['body'];
checkTrue('and so does the product itself', str_contains($editPage, 'product-parts.php?id=' . $productId));

$componentRow = tableBody(request('php/admin/components.php?q=ZZTest+Inv+Bloom', $jarA)['body']);
checkTrue('and a part links to its stock', str_contains($componentRow, 'inventory.php?q=ZZTest'));

// The two that say a recipe is not a withdrawal. Everything above edited what this bouquet is
// made of; none of it was allowed to move a single stem out of the drawer. Deleting the
// product is left to section 4, which needs it.
check('nothing in this section moved any stock', stockOf($partId), $stockBeforeRecipe);
check('and nothing wrote to the ledger', movesOf($partId), $movesBeforeRecipe);
check('the books still balance', stockAuditDiscrepancies(), []);

/* ------------------------------------------------------------------ *
 * 3. The ledger screen.
 * ------------------------------------------------------------------ */

heading('who may see the ledger');

$ledgerUrl = 'php/admin/stock-moves.php';

$asStranger = request($ledgerUrl, $jarStranger);
checkTrue('a signed-out visitor is sent to log in', $asStranger['status'] === 302 || $asStranger['status'] === 303);
checkTrue('and is shown none of it', !str_contains($asStranger['body'], 'On hand after'));

$asCustomer = request($ledgerUrl, $jarCustomer);
check('a signed-in customer is refused', $asCustomer['status'], 403);
checkTrue('and is shown none of it', !str_contains($asCustomer['body'], 'On hand after'));

heading('the ledger itself');

$page = request($ledgerUrl, $jarA);
check('it loads', $page['status'], 200);

$complaints = [];
foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
    if (str_contains($page['body'], $needle)) {
        $complaints[] = $needle;
    }
}
check('with nothing PHP wants to say about it', $complaints, []);

checkTrue('the fixture part has moved and is in the list', str_contains(tableBody($page['body']), 'ZZTest Inv Bloom'));
checkTrue('and is offered as a filter', str_contains($page['body'], 'value="' . $partId . '"'));

/*
 * The property that makes it a ledger.
 *
 * Every write in this admin is a submit button called "action", so a page with none of those
 * in it is a page that cannot change anything. Asserting on the absence of a POST form would
 * not do: the sidebar's sign-out is one, on every admin page including this one.
 */
checkTrue('nothing on it offers to change anything', !str_contains($page['body'], 'name="action"'));

heading('one part at a time');

$partLedger = tableBody(request($ledgerUrl . '?component=' . $partId, $jarA)['body']);
check('it shows exactly that part\'s movements', substr_count($partLedger, '<tr>'), movesOf($partId));
checkTrue('all of them its own', !str_contains($partLedger, 'ZZTest Inv Wrap'));
checkTrue('the delivery is there', str_contains($partLedger, 'ZZTest delivery'));
checkTrue('and the write-off', str_contains($partLedger, 'ZZTest dropped the lot'));
checkTrue('and a shortfall is flagged where it happened', str_contains($partLedger, 'pill-danger'));

// Every movement of this part was a delivery, a stock-take or a write-off, so the three
// filters have to add back up to all of it. Counted from the database rather than typed in:
// a hard-coded 6 here would only be asserting that nobody had added a check above.
$byReason = 0;

foreach (['received', 'adjusted', 'wasted'] as $why) {
    $expected = (int) $database->query(
        "SELECT COUNT(*) FROM stock_moves WHERE component_id = $partId AND reason = '$why'"
    )->fetchColumn();

    $filtered = tableBody(request($ledgerUrl . '?component=' . $partId . '&reason=' . $why, $jarA)['body']);
    check("the $why filter shows just those", substr_count($filtered, '<tr>'), $expected);
    $byReason += $expected;
}

check('and the three of them are all of it', $byReason, movesOf($partId));

// A reason this part has never had. An empty ledger is "nothing matches that" and not the
// first-delivery empty state, because the shop's own movements are still there.
$noneOfThose = request($ledgerUrl . '?component=' . $partId . '&reason=returned', $jarA)['body'];
check('a reason it never had shows no rows', tableBody($noneOfThose), '');
checkTrue('and says nothing matches rather than nothing exists', str_contains($noneOfThose, 'Nothing matches that'));

heading('the search box, and the order');

/*
 * The "we could not load it" assertion is here for a reason, and it is not padding.
 *
 * The page wraps its whole load in one try/catch, so a query that throws comes back as a
 * rendered page with no rows in it -- which is indistinguishable from "nothing matched" if
 * all you count is rows. That is exactly how the first version of this got through: the
 * search clause used one placeholder name twice, PDO threw HY093 on every search because
 * emulated prepares are off, and this section read it as an empty result set.
 */
$searchPage = request($ledgerUrl . '?q=ZZTest+dropped', $jarA)['body'];
checkTrue('the search ran at all', !str_contains($searchPage, 'We could not load the history'));
$searched = tableBody($searchPage);
check('a note finds its own movement', substr_count($searched, '<tr>'), 1);
checkTrue('and it is the write-off', str_contains($searched, 'ZZTest dropped the lot'));

// A search nothing answers is still a search that ran, and says so in the other words.
$noMatch = request($ledgerUrl . '?q=ZZTest+nothing+wrote+this', $jarA)['body'];
checkTrue('a search with no matches still ran', !str_contains($noMatch, 'We could not load the history'));
check('and found nothing', tableBody($noMatch), '');
checkTrue('and says nothing matches', str_contains($noMatch, 'Nothing matches that'));

// The escape character earning its keep: a literal % must not turn into "match everything".
// Asserted by what is absent rather than by a row count, because the shop's own notes are in
// range here and one of them could legitimately contain a percent sign one day.
$wildcard = request($ledgerUrl . '?q=%25', $jarA)['body'];
checkTrue('a lone percent sign does not crash it', !str_contains($wildcard, 'We could not load the history'));
checkTrue('and matches literally, not everything', !str_contains(tableBody($wildcard), 'ZZTest dropped the lot'));

/*
 * Ordered by id and not by created_at, which is what this pair is really checking. The race's
 * two deliveries and the stock-takes share a TIMESTAMP to the second, so a created_at sort
 * would put them in an arbitrary order and the balance column would read as nonsense.
 */
$oldestFirst = tableBody(request($ledgerUrl . '?component=' . $partId . '&sort=oldest', $jarA)['body']);
checkTrue('oldest first starts with the first delivery', strpos($oldestFirst, 'ZZTest delivery') < strpos($oldestFirst, 'ZZTest race'));

$newestFirst = tableBody(request($ledgerUrl . '?component=' . $partId . '&sort=newest', $jarA)['body']);
checkTrue('and newest first starts with the race', strpos($newestFirst, 'ZZTest race') < strpos($newestFirst, 'ZZTest delivery'));

heading('a part id that is not a part');

$notAPart = request($ledgerUrl . '?component=2147483600', $jarA);
check('it still loads', $notAPart['status'], 200);
check('with no rows', tableBody($notAPart['body']), '');
checkTrue('and does not invent a name for it', str_contains($notAPart['body'], 'a part that is no longer listed'));
check('and a word is the same as no filter', substr_count(tableBody(request($ledgerUrl . '?component=abc', $jarA)['body']), '<tr>'), substr_count(tableBody(request($ledgerUrl, $jarA)['body']), '<tr>'));

heading('the banner that says the books do not add up');

/*
 * Broken on purpose, on a row this file made, and deliberately NOT through recordStockMove()
 * -- going round it is the only way to break this, which is exactly what the banner is for.
 * Put back two checks later, and the balance is re-asserted at the end of the run.
 */
$database->exec("UPDATE product_components SET stock_on_hand = stock_on_hand + 7 WHERE id = $partId");

check('the audit finds the one part', count(stockAuditDiscrepancies()), 1);

$alarm = noticeBlock(request($ledgerUrl, $jarA)['body']);
checkTrue('the banner is up', $alarm !== '');
checkTrue('and names the part', str_contains($alarm, 'ZZTest Inv Bloom'));
checkTrue('and says which way and by how much', str_contains($alarm, '(+7)'));
checkTrue('and links to that part\'s history', str_contains($alarm, 'component=' . $partId));

$database->exec("UPDATE product_components SET stock_on_hand = stock_on_hand - 7 WHERE id = $partId");

check('putting it right clears the audit', stockAuditDiscrepancies(), []);
check('and takes the banner down with it', noticeBlock(request($ledgerUrl, $jarA)['body']), '');
check('and the count is back where it was', stockOf($partId), $stockBeforeRecipe);

/* ------------------------------------------------------------------ *
 * 4. Deleting a part that a recipe still names.
 *
 * The hazard this task created. product_parts cascades from product_components, so a part
 * deleted while a bouquet is built from it takes a line out of that bouquet with nothing on
 * screen to say so and no way back.
 * ------------------------------------------------------------------ */

heading('a part with some history of its own');

$wrapUrl = 'php/admin/inventory.php?q=ZZTest+Inv+Wrap';
$token = tokenFrom($wrapUrl, $jarA);
request($wrapUrl, $jarA, [
    'csrf_token' => $token,
    'id' => (string) $wrapId,
    'action' => 'receive',
    'qty' => '5',
    'note' => 'ZZTest wrap delivery',
]);
check('the wrap has five of it', stockOf($wrapId), 5);
check('and one movement', movesOf($wrapId), 1);

heading('while a recipe still names it');

$token = tokenFrom($recipeUrl, $jarA);
request($recipeUrl, $jarA, ['csrf_token' => $token, 'qty' => [$partId => '3', $wrapId => '1']]);
check('it is in the bouquet', count(fetchProductParts($productId)), 2);

$wrapRow = tableBody(request('php/admin/components.php?q=ZZTest+Inv+Wrap', $jarA)['body']);
// Not drawn at all rather than drawn and refused: a button whose only outcome is a telling-off
// is worse than no button.
checkTrue('the parts list offers no Delete', !str_contains($wrapRow, 'value="delete"'));
checkTrue('and says why instead', str_contains($wrapRow, 'In 1 recipe'));
checkTrue('put back is still offered', str_contains($wrapRow, 'value="offer"'));

// The part page says the same thing, and links to where it can be undone.
$wrapPage = request('php/admin/component-edit.php?id=' . $wrapId, $jarA)['body'];
checkTrue('the part page names the bouquet', str_contains($wrapPage, 'ZZTest Inv Bouquet'));
checkTrue('and links to its recipe', str_contains($wrapPage, 'product-parts.php?id=' . $productId));

heading('and a delete sent by hand is refused');

$token = tokenFrom('php/admin/components.php?q=ZZTest+Inv+Wrap', $jarA);
$refused = request('php/admin/components.php?q=ZZTest+Inv+Wrap', $jarA, [
    'csrf_token' => $token,
    'id' => (string) $wrapId,
    'action' => 'delete',
]);
check('it redirects, as every action on that page does', $refused['status'], 302);

$afterRefusal = request('php/admin/components.php?q=ZZTest+Inv+Wrap', $jarA)['body'];
checkTrue('and says what is in the way', str_contains($afterRefusal, 'it is still part of'));
checkTrue('naming the bouquet', str_contains($afterRefusal, 'ZZTest Inv Bouquet'));
checkTrue('and what to do about it', str_contains($afterRefusal, 'Take it out of that recipe first'));
check('the part is still there', stockOf($wrapId), 5);
check('and so is the recipe', count(fetchProductParts($productId)), 2);

heading('once it is out of the recipe');

$token = tokenFrom($recipeUrl, $jarA);
request($recipeUrl, $jarA, ['csrf_token' => $token, 'qty' => [$partId => '3', $wrapId => '']]);
check('one part left in the bouquet', count(fetchProductParts($productId)), 1);

$freedRow = tableBody(request('php/admin/components.php?q=ZZTest+Inv+Wrap', $jarA)['body']);
checkTrue('the Delete button is back', str_contains($freedRow, 'value="delete"'));
checkTrue('and the recipe count is gone', !str_contains($freedRow, 'In 1 recipe'));

$token = tokenFrom('php/admin/components.php?q=ZZTest+Inv+Wrap', $jarA);
request('php/admin/components.php?q=ZZTest+Inv+Wrap', $jarA, [
    'csrf_token' => $token,
    'id' => (string) $wrapId,
    'action' => 'delete',
]);
check('and the part is deleted for good', (int) $database->query("SELECT COUNT(*) FROM product_components WHERE id = $wrapId")->fetchColumn(), 0);

heading('but its history outlives it');

/*
 * ON DELETE SET NULL on stock_moves.component_id, and the frozen component_name beside it.
 * The opposite call to product_parts a few lines up, and the reason for both is the same
 * sentence read twice: a recipe is an instruction and must not outlive its part, a movement
 * is history and must.
 */
$orphan = $database->query(
    "SELECT component_id, component_name, delta FROM stock_moves WHERE note = 'ZZTest wrap delivery'"
)->fetchAll();

check('the movement is still there', count($orphan), 1);
check('with no part to point at', $orphan[0]['component_id'], null);
check('but still saying what moved', (string) $orphan[0]['component_name'], 'ZZTest Inv Wrap');
check('and how much of it', (int) $orphan[0]['delta'], 5);

// Reachable only through the search box: the filter dropdown is an INNER join and there is no
// id left to offer. Same "did the query actually run" guard as the search section, and for the
// same reason -- a thrown query and a row that is genuinely missing look identical from here.
$orphanPage = request($ledgerUrl . '?q=ZZTest+wrap+delivery', $jarA)['body'];
checkTrue('the ledger loaded', !str_contains($orphanPage, 'We could not load the history'));
$orphanRow = tableBody($orphanPage);
check('the ledger still lists it', substr_count($orphanRow, '<tr>'), 1);
checkTrue('under the name it had', str_contains($orphanRow, 'ZZTest Inv Wrap'));
checkTrue('and says the part is gone', str_contains($orphanRow, 'Part deleted'));
checkTrue('and offers no link to a part that is not there', !str_contains($orphanRow, 'component=' . $wrapId));

// A movement with no part left has no balance to disagree with, so it is not a discrepancy.
check('and the books still balance without it', stockAuditDiscrepancies(), []);

heading('deleting the product takes its recipe with it');

check('one part to lose', count(fetchProductParts($productId)), 1);

$database->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $productId]);
check('the recipe row is gone with it', (int) $database->query("SELECT COUNT(*) FROM product_parts WHERE product_id = $productId")->fetchColumn(), 0);
check('but the part itself is still there', stockOf($partId), $stockBeforeRecipe);
check('and its ledger is untouched', movesOf($partId), $movesBeforeRecipe);
check('the books still balance', stockAuditDiscrepancies(), []);

/* ------------------------------------------------------------------ *
 * The dashboard panel, which is the one place outside this module that
 * reads its numbers.
 * ------------------------------------------------------------------ */

heading('the dashboard panel');

/**
 * Just the "What to buy" panel, for the same reason tableBody() exists.
 *
 * Every part name in the panel is also in the "Top products" list, the recent-orders table and
 * the page's other panel, so a page-wide str_contains would pass whether the panel drew the row
 * or not. The <section> is matched up to the next one rather than with a lazy .*? over the whole
 * document, so an assertion cannot be satisfied by markup from a neighbouring panel.
 *
 * Returns '' when the panel is absent, which is the honest answer to "what does it list".
 */
function buyPanel(string $html): string
{
    if (preg_match('#<h2>What to buy</h2>(.*?)</section>#s', $html, $m) !== 1) {
        return '';
    }

    return $m[1];
}

/*
 * A part nobody can miss: offered, warn at 5, and 3 owed.
 *
 * Set to a known count first rather than nudged by a delta. This section runs last, after the
 * race, the write-off and the stock-take have all moved this part, so "subtract 3" lands
 * somewhere that depends on every section above it -- and the first version of this test did
 * exactly that, left the part on a comfortable positive number, and reported the panel broken
 * for correctly not listing a part that was not short.
 */
setComponentStock($partId, 0, 'ZZTest panel baseline');
recordStockMove($partId, -3, 'wasted', ['note' => 'ZZTest panel shortfall']);
setComponentLowStock($partId, 5);
check('the fixture really is short', stockOf($partId), -3);

$dashboard = request('php/admin/dashboard.php', $jarA);
check('the dashboard still loads', $dashboard['status'], 200);

$panel = buyPanel($dashboard['body']);
checkTrue('the panel is on the page', $panel !== '');
checkTrue('and it names the part that is short', str_contains($panel, 'ZZTest Inv Bloom'));

/*
 * The negative balance, printed as a negative number.
 *
 * This check used to look for the string "3 owed", which the panel has never said and which the
 * screen is right not to say: the shortfall is shown as a "Short" pill beside an On hand column
 * reading -3, and a column headed "On hand" that printed the magnitude 3 for a drawer holding
 * minus three would be a lie in the one place it matters most. The check was wrong, not the page.
 *
 * What is worth asserting is the thing the wording was reaching for -- that the number is not
 * clamped at zero on the way to the screen. number_format() is what prints it, so the assertion
 * is on its output rather than on a hand-built string.
 */
checkTrue('showing the shortfall as a negative rather than clamping it at zero',
    str_contains($panel, '>' . number_format(-3) . '</td>'));
checkTrue('and flagging it Short rather than merely Out', str_contains($panel, '>Short</span>'));

/*
 * The failure banner, asserted absent. Without this the two checks above could both pass on a
 * page whose panel rendered "The stock figures are unavailable" -- the same trap the ledger's
 * search assertions were rewritten for on 2026-09-27, where a swallowed PDOException read as an
 * empty result set forever.
 */
checkTrue('and the panel did not fall back to its error state',
    !str_contains($panel, 'The stock figures are unavailable'));
checkTrue('no toast about the migration either',
    !str_contains($dashboard['body'], 'Unable to read the stock figures'));

/*
 * The withdrawn control: a part that qualifies on both grounds an offered part could -- at zero
 * AND under its warn level -- and is kept off the list by is_active alone.
 *
 * Its own fixture rather than the ZZTest Inv Wrap one, which this file deleted several sections
 * ago to prove the ledger outlives its part. Reusing it would have made this check pass against
 * a row that does not exist, which is not the same thing as passing.
 */
$database->prepare(
    "INSERT INTO product_components (name, kind, description, price, colour, colour_hex, sort_order, is_active, low_stock_threshold)
     VALUES ('ZZTest Inv Panel Wrap', 'wrap', 'Fixture.', 8.00, 'Test', '#d9d2c4', 902, 0, 10)"
)->execute();
$panelWrapId = (int) $database->lastInsertId();

check('the control is withdrawn', (int) $database->query(
    "SELECT is_active FROM product_components WHERE id = $panelWrapId")->fetchColumn(), 0);
check('and at zero', stockOf($panelWrapId), 0);

$panelAgain = buyPanel(request('php/admin/dashboard.php', $jarA)['body']);
checkTrue('a withdrawn part at zero is not on the buying list',
    !str_contains($panelAgain, 'ZZTest Inv Panel Wrap'));
checkTrue('while the offered one still is', str_contains($panelAgain, 'ZZTest Inv Bloom'));

/*
 * The function's own numbers, against the screen's. Both come from stockSummary() and this is
 * what keeps them that way.
 */
$panelStats = adminInventorySummary();
$screenStats = stockSummary();
check('the panel counts the same parts as the stock screen', $panelStats['parts'], $screenStats['parts']);
check('the same low count', $panelStats['low'], $screenStats['low']);
check('the same out count', $panelStats['out'], $screenStats['out']);
check('the same units', $panelStats['units'], $screenStats['units']);
check('and it agrees with the audit', $panelStats['audit'], count(stockAuditDiscrepancies()));

checkTrue('the shortfall list is capped', count($panelStats['shortfalls']) <= 4);
check('the limit is honoured', count(adminInventorySummary(1)['shortfalls']), 1);

/*
 * What is owed outranks what is merely low. Asserted on the whole list rather than on where the
 * fixture landed: every part at or below zero must come before every part that is only under its
 * warn level, or the buying list is in no useful order. Reading the list once and checking the
 * boundary is also cheaper than the three calls this used to make.
 */
$shortfalls = adminInventorySummary(50)['shortfalls'];
$names = array_map(static fn (array $row): string => (string) $row['name'], $shortfalls);
checkTrue('the part that is owed is on the list', in_array('ZZTest Inv Bloom', $names, true));

$seenMerelyLow = false;
$outOfOrder = 0;

foreach ($shortfalls as $row) {
    if ((int) $row['stock_on_hand'] > 0) {
        $seenMerelyLow = true;
    } elseif ($seenMerelyLow) {
        // A part at or below zero after one that is merely low: the wrong way round.
        $outOfOrder++;
    }
}

check('everything owed is listed above everything merely low', $outOfOrder, 0);

// Guard: the panel is behind the same door as everything else this module draws.
$locked = request('php/admin/dashboard.php', $jarCustomer);
check('a customer cannot read the panel', $locked['status'], 403);

/*
 * No restore of the fixture's count here, deliberately. Nothing after this section reads it, and
 * the sweep below deletes both parts and every ledger row naming them -- so an "adjust it back"
 * move would only be a third row to clean up. What does have to hold is the invariant, and the
 * "as we found it" block at the end proves the shop's own units are untouched either way.
 */
check('the books still balance after the panel section', stockAuditDiscrepancies(), []);

/* ------------------------------------------------------------------ *
 * 6. Stage 2: an order takes its parts out of the drawer.
 * ------------------------------------------------------------------ */

/*
 * The half the module was built for, and the reason it sat at [~] for two days.
 *
 * Everything above this line is a human maintaining a number. Below it, marking an order as being
 * made takes its parts out and moving it back puts them in, which is the one thing the shop cannot
 * be expected to do by hand for every bouquet.
 *
 * Fresh materials rather than $partId, which has been received into, adjusted and stock-taken by
 * five sections of this file and whose balance is therefore an accident of what came before. These
 * two start at a known 10 and 4 against a recipe of 3 and 3 and an order of 2, so the arithmetic is
 * 10 - 6 = 4 and 4 - 6 = -2: one material that copes and one that goes short, in a single press,
 * which is the case the user's "warn, never block" decision exists for.
 *
 * The openings go in through recordStockMove() rather than as a stock_on_hand column write. A
 * balance with no ledger row behind it is exactly the disagreement stockAuditDiscrepancies() is
 * there to catch, and a fixture that starts the books off wrong would make every audit check below
 * meaningless.
 */

heading('6. an order takes its parts out');

$makeS2Part = $database->prepare(
    "INSERT INTO product_components (name, kind, description, price, colour, colour_hex, sort_order, is_active)
     VALUES (:name, :kind, 'Stage 2 fixture.', 10.00, 'Test', '#b07f9f', 910, 1)"
);

$makeS2Part->execute(['name' => 'ZZTest Inv S2 Bloom', 'kind' => 'bloom']);
$s2BloomId = (int) $database->lastInsertId();

$makeS2Part->execute(['name' => 'ZZTest Inv S2 Wire', 'kind' => 'wire']);
$s2WireId = (int) $database->lastInsertId();

// userId null explicitly: this is a machine-written opening count and no person is answerable for
// it. Omitting it would mean "the signed-in admin", and there is no session in a CLI process.
recordStockMove($s2BloomId, 10, 'opening', ['userId' => null, 'note' => 'ZZTest S2 opening']);
recordStockMove($s2WireId, 4, 'opening', ['userId' => null, 'note' => 'ZZTest S2 opening']);

$database->prepare(
    "INSERT INTO products (name, kind, description, price, colour, stem_count, is_active)
     VALUES ('ZZTest Inv S2 Bouquet', 'bouquet', 'Stage 2 fixture.', 500.00, 'Test', 3, 0)"
)->execute();
$s2ProductId = (int) $database->lastInsertId();

// A second product with no recipe at all, which is the seeded Single Calla Lily's situation and
// has to be said out loud rather than passed over. Nothing is ever saved against it.
//
// kind 'flower' and not 'single': products.kind is ENUM('bouquet','flower'), and MariaDB answers
// an ENUM value it does not know with SQLSTATE 01000 "Data truncated" rather than a clear refusal.
// The component kinds are the four in COMPONENT_KINDS and a different list entirely.
$database->prepare(
    "INSERT INTO products (name, kind, description, price, colour, stem_count, is_active)
     VALUES ('ZZTest Inv S2 Plain', 'flower', 'No recipe, on purpose.', 90.00, 'Test', 1, 0)"
)->execute();
$s2PlainId = (int) $database->lastInsertId();

saveProductParts($s2ProductId, [$s2BloomId => 3, $s2WireId => 3]);

$makeS2Order = $database->prepare(
    "INSERT INTO orders
         (reference, user_id, status, payment_status, payment_method, fulfilment,
          contact_name, contact_phone, contact_email, address_line, barangay, city, province, postcode,
          total, placed_at)
     VALUES (:reference, :user, :status, 'unpaid', 'cod', 'delivery',
             'ZZTest Inv Buyer', '0917 555 0199', :email, '11 ZZTest Lane', 'Poblacion', 'Cebu City', 'Cebu', '6000',
             :total, NOW())"
);

$makeS2Item = $database->prepare(
    'INSERT INTO order_items (order_id, kind, product_id, name, unit_price, quantity, line_total, arrangement)
     VALUES (:order, :kind, :product, :name, :unit, :quantity, :line, :arrangement)'
);

/**
 * One order with one line, straight into the tables.
 *
 * Not through checkout, for the reason admin-http-test.php does the same: what is being tested is
 * what the shop's buttons do to an order that exists, and building each one through the customer
 * journey would make this section a second copy of the commerce suite.
 *
 * @return array{0: int, 1: string} the new order's id and its reference
 */
$s2Order = static function (string $suffix, string $status, ?int $productId, string $name, int $quantity, string $kind = 'product') use ($makeS2Order, $makeS2Item, $database, $accounts): array {
    /*
     * A real-looking reference, because the admin order screen will not accept anything else.
     * orderReferenceFromInput() matches /^SLJ-\d{8}-[23456789A-HJ-NP-Z]{4}$/ and returns '' for
     * everything that misses, and order.php answers '' with a 404 -- so a ZZ-prefixed reference
     * made every POST in this section redirect without doing anything and every panel assertion
     * fail against a Not found page. Seventeen characters exactly, which is orders.reference's
     * CHAR(17).
     *
     * The suffixes are all ZZnn, which is both inside ORDER_REFERENCE_ALPHABET (no 0/O/1/I in it)
     * and recognisable as this file's if one is ever seen by a person. What the sweep matches on
     * is contact_name, for the reason written on sweepFixtures().
     */
    $reference = 'SLJ-20260929-' . $suffix;

    $makeS2Order->execute([
        'reference' => $reference,
        'user' => $accounts['customer']['id'],
        'status' => $status,
        'email' => $accounts['customer']['email'],
        'total' => '1000.00',
    ]);
    $orderId = (int) $database->lastInsertId();

    $makeS2Item->execute([
        'order' => $orderId,
        'kind' => $kind,
        'product' => $productId,
        'name' => $name,
        'unit' => '500.00',
        'quantity' => $quantity,
        'line' => '1000.00',
        'arrangement' => 'dome',
    ]);

    return [$orderId, $reference];
};

[$s2OrderId, $s2Ref] = $s2Order('ZZ22', 'placed', $s2ProductId, 'ZZTest Inv S2 Bouquet', 2);

$s2Url = 'php/admin/order.php?ref=' . rawurlencode($s2Ref);

tell('S2 bloom', $s2BloomId);
tell('S2 wire', $s2WireId);
tell('S2 order', $s2Ref);

check('the bloom opens at ten', stockOf($s2BloomId), 10);
check('the wire opens at four', stockOf($s2WireId), 4);
check('the recipe is three and three', array_map(static fn (array $r): int => (int) $r['quantity'], fetchProductParts($s2ProductId)), [3, 3]);

/* -- The forecast, before the button is pressed. -------------------- */

$requirement = orderStockRequirement($s2OrderId);

check('the forecast counts the recipe times the quantity', array_map(static fn (array $p): int => (int) $p['needed'], array_values($requirement['parts'])), [6, 6]);
check('and finds nothing it cannot cost', $requirement['blind'], []);
check('nothing is out of the drawer yet', orderStockOut($s2OrderId), []);

$panel = stockPanel(request($s2Url, $jarA)['body']);

checkTrue('the order page draws the panel', $panel !== '');
checkTrue('the forecast names the bloom', str_contains($panel, 'ZZTest Inv S2 Bloom'));
checkTrue('and the wire', str_contains($panel, 'ZZTest Inv S2 Wire'));
checkTrue('and warns about the shortfall before anything is pressed', str_contains($panel, 'Short by 2'));
checkTrue('and says nothing is out yet', str_contains($panel, 'Nothing is out of the drawer for this order yet'));

check('the forecast moved no stock', [stockOf($s2BloomId), stockOf($s2WireId)], [10, 4]);
check('the books balance before the first press', stockAuditDiscrepancies(), []);

/* -- Start making. -------------------------------------------------- */

$movesBeforePack = countStockMoves();

$token = tokenFrom($s2Url, $jarA);
$packed = request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'pack', 'ref' => $s2Ref]);

check('pack: answered with a redirect', $packed['status'], 302);
check('pack: the order is being made', orderStatusOf($s2Ref), 'packed');
check('pack: the bloom came down by six', stockOf($s2BloomId), 4);
check('pack: and the wire went short', stockOf($s2WireId), -2);
check('pack: two ledger rows, no more', countStockMoves() - $movesBeforePack, 2);
check('pack: and that is what the order says came out', orderStockOut($s2OrderId), [$s2BloomId => 6, $s2WireId => 6]);
check('pack: the books still balance', stockAuditDiscrepancies(), []);

$usedRows = orderMovesOf($s2OrderId);

check('pack: both rows say used', array_column($usedRows, 'reason'), ['used', 'used']);
check('pack: both took six out', array_map('intval', array_column($usedRows, 'delta')), [-6, -6]);
check('pack: with the balance frozen beside each', array_map('intval', array_column($usedRows, 'balance_after')), [4, -2]);
check('pack: both carry the order', array_map('intval', array_column($usedRows, 'order_id')), [$s2OrderId, $s2OrderId]);
check('pack: and the admin who pressed it', array_map('intval', array_column($usedRows, 'user_id')), [$accounts['admin-a']['id'], $accounts['admin-a']['id']]);
check('pack: the part names are frozen in, in recipe order', array_column($usedRows, 'component_name'), ['ZZTest Inv S2 Bloom', 'ZZTest Inv S2 Wire']);
check('pack: and no note, because the reason and the order already say it', array_unique(array_column($usedRows, 'note')), ['']);

$said = toastText(request('php/admin/orders.php', $jarA)['body']);

checkTrue('pack: the toast says it is being made', str_contains($said, 'is being made'));
checkTrue('pack: and how much came out', str_contains($said, '12 parts came out of stock'));
checkTrue('pack: and names the one that went short', str_contains($said, 'Stock has gone short on ZZTest Inv S2 Wire'));
check('pack: naming one material and not two', str_contains($said, '2 materials'), false);

$panel = stockPanel(request($s2Url, $jarA)['body']);

checkTrue('pack: the panel is now the record', str_contains($panel, 'already out of the drawer'));
checkTrue('pack: with the shortfall marked on the row', str_contains($panel, 'pill pill-danger'));
checkTrue('pack: and a way through to the ledger', str_contains($panel, 'stock-moves.php?order=' . $s2OrderId));

/* -- Pressing it twice. --------------------------------------------- */

$movesBeforeSecond = countStockMoves();

$token = tokenFrom($s2Url, $jarA);
request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'pack', 'ref' => $s2Ref]);

check('twice: still being made', orderStatusOf($s2Ref), 'packed');
check('twice: and nothing came out a second time', countStockMoves() - $movesBeforeSecond, 0);
check('twice: the balances are where they were', [stockOf($s2BloomId), stockOf($s2WireId)], [4, -2]);
check('twice: the books still balance', stockAuditDiscrepancies(), []);

/* -- Putting it back, across a recipe edited in between. ------------ */

/*
 * The case that decides whether this was built on the receipt or on the recipe.
 *
 * Six of each went out. The recipe is now changed to nine blooms -- somebody decided the bouquet
 * needs more -- and then the order is moved back to Placed. What must come back is **six**, the
 * number that actually left the drawer, and not nine, the number the recipe would now ask for.
 * returnOrderStock() reads stock_moves and never product_parts, which is the whole reason it can
 * get this right, and it is the fourth time this module has landed on the receipt side of its own
 * distinction.
 *
 * Nine and not four, deliberately: a wrong answer here overshoots rather than undershoots, so the
 * bloom would come back to 13 against an opening of 10 -- a number no arithmetic of the drawer can
 * produce, and one that stockAuditDiscrepancies() alone would not catch because the ledger would
 * agree with it.
 */
saveProductParts($s2ProductId, [$s2BloomId => 9, $s2WireId => 3]);
check('the recipe changed under it', (int) fetchProductParts($s2ProductId)[0]['quantity'], 9);

$token = tokenFrom($s2Url, $jarA);
$unpacked = request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'unpack', 'ref' => $s2Ref]);

check('unpack: answered with a redirect', $unpacked['status'], 302);
check('unpack: the order is back to Placed', orderStatusOf($s2Ref), 'placed');
check('unpack: the bloom is back where it started, not where the new recipe says', stockOf($s2BloomId), 10);
check('unpack: and the wire is out of its shortfall', stockOf($s2WireId), 4);
check('unpack: nothing is out for the order any more', orderStockOut($s2OrderId), []);
check('unpack: the books still balance', stockAuditDiscrepancies(), []);

$returned = array_slice(orderMovesOf($s2OrderId), 2);

check('unpack: two rows say returned', array_column($returned, 'reason'), ['returned', 'returned']);
check('unpack: putting back exactly what was taken', array_map('intval', array_column($returned, 'delta')), [6, 6]);
check('unpack: and the superseded rows are left standing', count(orderMovesOf($s2OrderId)), 4);

$said = toastText(request('php/admin/orders.php', $jarA)['body']);

checkTrue('unpack: the toast says where it went', str_contains($said, 'is back to Placed'));
checkTrue('unpack: and how much went back', str_contains($said, '12 parts went back into stock'));
checkTrue('unpack: and that nobody was written to', str_contains($said, 'has not been written to'));
check('unpack: no message is claimed', str_contains($said, 'has been emailed'), false);

/* -- Making it again nets to one consumption. ----------------------- */

// Back to three, so what comes out the second time is comparable with the first.
saveProductParts($s2ProductId, [$s2BloomId => 3, $s2WireId => 3]);

$token = tokenFrom($s2Url, $jarA);
request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'pack', 'ref' => $s2Ref]);

check('again: being made once more', orderStatusOf($s2Ref), 'packed');
check('again: the balances are where the first press left them', [stockOf($s2BloomId), stockOf($s2WireId)], [4, -2]);
// The assertion the whole pack -> unpack -> pack sequence exists for. Six, not twelve: the used
// and returned rows in between cancel out, so the third press takes the recipe once and not twice.
check('again: and one consumption is out, not two', orderStockOut($s2OrderId), [$s2BloomId => 6, $s2WireId => 6]);
check('again: six rows of history for three presses', count(orderMovesOf($s2OrderId)), 6);
check('again: the books still balance', stockAuditDiscrepancies(), []);

/* -- The ledger, filtered to this one order. ------------------------ */

$ledgerForOrder = request($ledgerUrl . '?order=' . $s2OrderId, $jarA)['body'];
$ledgerRows = tableBody($ledgerForOrder);

check('ledger: the order filter finds its six rows', substr_count($ledgerRows, '<tr>'), 6);
checkTrue('ledger: and names the order', str_contains($ledgerForOrder, 'Order ' . $s2Ref));
checkTrue('ledger: with a way back to every order', str_contains($ledgerForOrder, '>all orders</a>'));
checkTrue('ledger: the opening count is not in it', !str_contains($ledgerRows, 'ZZTest S2 opening'));

// The filters compose, and the hidden field is what keeps the order on when another is applied.
$ledgerBoth = tableBody(request($ledgerUrl . '?order=' . $s2OrderId . '&reason=returned', $jarA)['body']);
check('ledger: order and reason together', substr_count($ledgerBoth, '<tr>'), 2);

$notThisOrder = request($ledgerUrl . '?order=2147483600', $jarA);
check('ledger: an order that never moved anything is answered, not broken', $notThisOrder['status'], 200);
check('ledger: with no rows', tableBody($notThisOrder['body']), '');

/* -- Cancelling something that is being made. ----------------------- */

$token = tokenFrom($s2Url, $jarA);
$cancelled = request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'cancel', 'ref' => $s2Ref]);

check('cancel: answered with a redirect', $cancelled['status'], 302);
check('cancel: the order is off', orderStatusOf($s2Ref), 'cancelled');
check('cancel: everything went back', [stockOf($s2BloomId), stockOf($s2WireId)], [10, 4]);
check('cancel: nothing is out for it', orderStockOut($s2OrderId), []);
check('cancel: the books still balance', stockAuditDiscrepancies(), []);

$said = toastText(request('php/admin/orders.php', $jarA)['body']);
checkTrue('cancel: the toast says it is cancelled', str_contains($said, 'is cancelled'));
checkTrue('cancel: and that the parts came back', str_contains($said, '12 parts went back into stock'));

/* -- Cancelling something that was never started. ------------------- */

[$plainOrderId, $plainRef] = $s2Order('ZZ23', 'placed', $s2ProductId, 'ZZTest Inv S2 Bouquet', 1);

$movesBeforeIdle = countStockMoves();

$token = tokenFrom('php/admin/order.php?ref=' . rawurlencode($plainRef), $jarA);
request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'cancel', 'ref' => $plainRef]);

check('never started: cancelled all the same', orderStatusOf($plainRef), 'cancelled');
check('never started: and nothing moved, because nothing was out', countStockMoves() - $movesBeforeIdle, 0);
check('never started: the balances are untouched', [stockOf($s2BloomId), stockOf($s2WireId)], [10, 4]);

$said = toastText(request('php/admin/orders.php', $jarA)['body']);
check('never started: the toast says nothing about stock', str_contains($said, 'stock'), false);

/* -- Delivering takes nothing more out. ----------------------------- */

[$deliverOrderId, $deliverRef] = $s2Order('ZZ24', 'placed', $s2ProductId, 'ZZTest Inv S2 Bouquet', 1);
$deliverUrl = 'php/admin/order.php?ref=' . rawurlencode($deliverRef);

$token = tokenFrom($deliverUrl, $jarA);
request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'pack', 'ref' => $deliverRef]);
check('deliver: it was made first', [stockOf($s2BloomId), stockOf($s2WireId)], [7, 1]);

$movesBeforeDeliver = countStockMoves();

$token = tokenFrom($deliverUrl, $jarA);
request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'deliver', 'ref' => $deliverRef]);

check('deliver: the order is finished', orderStatusOf($deliverRef), 'delivered');
// The parts left the drawer when making started. Taking them again on delivery would be the
// double-count the whole ledger-derived design exists to make impossible.
check('deliver: and it moved no stock at all', countStockMoves() - $movesBeforeDeliver, 0);
check('deliver: the balances are where packing left them', [stockOf($s2BloomId), stockOf($s2WireId)], [7, 1]);
check('deliver: what is out stays out', orderStockOut($deliverOrderId), [$s2BloomId => 3, $s2WireId => 3]);
check('deliver: the books still balance', stockAuditDiscrepancies(), []);

$panel = stockPanel(request($deliverUrl, $jarA)['body']);
checkTrue('deliver: a finished order still shows what it took', str_contains($panel, 'already out of the drawer'));

/* -- A line with no recipe consumes nothing, and says so. ----------- */

[$blindOrderId, $blindRef] = $s2Order('ZZ25', 'placed', $s2PlainId, 'ZZTest Inv S2 Plain', 2);
$blindUrl = 'php/admin/order.php?ref=' . rawurlencode($blindRef);

$blindPanel = stockPanel(request($blindUrl, $jarA)['body']);
checkTrue('no recipe: the panel says making it takes nothing', str_contains($blindPanel, 'takes nothing out of'));
checkTrue('no recipe: and names the line', str_contains($blindPanel, 'ZZTest Inv S2 Plain'));
checkTrue('no recipe: with the reason', str_contains($blindPanel, 'no recipe yet'));

$movesBeforeBlind = countStockMoves();

$token = tokenFrom($blindUrl, $jarA);
request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'pack', 'ref' => $blindRef]);

check('no recipe: it is still made', orderStatusOf($blindRef), 'packed');
check('no recipe: and took nothing out', countStockMoves() - $movesBeforeBlind, 0);

$said = toastText(request('php/admin/orders.php', $jarA)['body']);
// The one outcome worse than a shortfall is a pack that quietly took nothing, so the toast has
// to say it happened even though there is no number to report.
checkTrue('no recipe: the toast admits it', str_contains($said, '1 line took nothing out of stock'));
check('no recipe: and claims no units', str_contains($said, 'came out of stock'), false);

/* -- A material deleted after it was consumed. ---------------------- */

/*
 * fk_stock_moves_component is ON DELETE SET NULL, so destroying a part leaves its movements
 * standing with a frozen name and no id. Those units cannot go back -- there is nothing left to
 * put them into -- and the order has to say so rather than quietly returning fewer than it took.
 */
$doomedId = 0;
$makeS2Part->execute(['name' => 'ZZTest Inv S2 Doomed', 'kind' => 'accent']);
$doomedId = (int) $database->lastInsertId();
recordStockMove($doomedId, 8, 'opening', ['userId' => null, 'note' => 'ZZTest S2 opening']);

$database->prepare(
    "INSERT INTO products (name, kind, description, price, colour, stem_count, is_active)
     VALUES ('ZZTest Inv S2 Doomed Bouquet', 'bouquet', 'Stage 2 fixture.', 300.00, 'Test', 2, 0)"
)->execute();
$doomedProductId = (int) $database->lastInsertId();
saveProductParts($doomedProductId, [$doomedId => 2]);

[$doomedOrderId, $doomedRef] = $s2Order('ZZ26', 'placed', $doomedProductId, 'ZZTest Inv S2 Doomed Bouquet', 2);
$doomedUrl = 'php/admin/order.php?ref=' . rawurlencode($doomedRef);

$token = tokenFrom($doomedUrl, $jarA);
request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'pack', 'ref' => $doomedRef]);
check('deleted part: four went out', stockOf($doomedId), 4);

$database->prepare('DELETE FROM product_components WHERE id = :id')->execute(['id' => $doomedId]);

check('deleted part: the movement outlived the part', count(orderMovesOf($doomedOrderId)), 1);

/*
 * array_key_exists and not ??, because the value under test *is* null.
 *
 * `$row['component_id'] ?? 'no row'` reads as a safe default and is not one: ?? fires on a null
 * value exactly as it fires on a missing key, so it turned the correct answer into 'no row' and
 * failed a check that should have passed. The guard has to distinguish "there is no row" from
 * "the row says null", which is the whole point of the assertion.
 */
$doomedRow = orderMovesOf($doomedOrderId)[0] ?? null;
check(
    'deleted part: and lost its id',
    is_array($doomedRow) && array_key_exists('component_id', $doomedRow) ? $doomedRow['component_id'] : 'no row',
    null
);
check('deleted part: four units cannot come back', orderStockLostUnits($doomedOrderId), 4);
check('deleted part: and nothing countable is out', orderStockOut($doomedOrderId), []);

$doomedPanel = stockPanel(request($doomedUrl, $jarA)['body']);
checkTrue('deleted part: the order page says the units are unrecoverable', str_contains($doomedPanel, 'cannot be put back'));

$movesBeforeDoomedBack = countStockMoves();

$token = tokenFrom($doomedUrl, $jarA);
request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'unpack', 'ref' => $doomedRef]);

check('deleted part: the order still moves back', orderStatusOf($doomedRef), 'placed');
check('deleted part: and no movement is invented for a part that is gone', countStockMoves() - $movesBeforeDoomedBack, 0);

$said = toastText(request('php/admin/orders.php', $jarA)['body']);
checkTrue('deleted part: the toast says why nothing came back', str_contains($said, 'that material has been deleted'));
check('deleted part: the books still balance', stockAuditDiscrepancies(), []);

/* -- Two admins pressing Start making at the same instant. ---------- */

heading('two admins starting one order at once');

if (!function_exists('curl_multi_init')) {
    echo "  SKIP  curl is not loaded in this PHP -- the race cannot be run\n";
} else {
    /*
     * The invariant, not the interleaving. Both requests can read 'placed' before either writes,
     * and the only thing between that and one order's parts coming out twice is that the UPDATE's
     * own WHERE names the state it moves from -- and that consumeOrderStock() runs inside the same
     * transaction, so the loser's stock write never happens either.
     *
     * Two accounts and two jars, not one jar twice: PHP holds the session file lock for a whole
     * request, so two presses through one cookie cannot overlap and this would quietly become the
     * sequential test done further up.
     */
    [$raceOrderId, $raceRef] = $s2Order('ZZ27', 'placed', $s2ProductId, 'ZZTest Inv S2 Bouquet', 1);
    $raceUrl = 'php/admin/order.php?ref=' . rawurlencode($raceRef);

    $tokenA = tokenFrom($raceUrl, $jarA);
    $tokenB = tokenFrom($raceUrl, $jarB);
    checkTrue('race: both admins have a token', $tokenA !== '' && $tokenB !== '');

    $bloomBeforeRace = stockOf($s2BloomId);
    $wireBeforeRace = stockOf($s2WireId);

    $multi = curl_multi_init();
    $handles = [];

    foreach ([[$jarA, $tokenA], [$jarB, $tokenB]] as [$jar, $jarToken]) {
        $pairs = [];
        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $handle = curl_init(BASE . 'php/admin/orders.php');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'csrf_token' => $jarToken,
                'action' => 'pack',
                'ref' => $raceRef,
            ]),
            CURLOPT_COOKIE => implode('; ', $pairs),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }

    $running = null;
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 1.0);
    } while ($running > 0);

    $statuses = [];
    foreach ($handles as $handle) {
        $statuses[] = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    tell('both statuses', implode(', ', array_map('strval', $statuses)));
    check('race: both requests were accepted', $statuses, [302, 302]);
    check('race: the order is being made', orderStatusOf($raceRef), 'packed');

    // Three and three, once. Six and six would be both presses consuming.
    check('race: and its parts came out exactly once', orderStockOut($raceOrderId), [$s2BloomId => 3, $s2WireId => 3]);
    check('race: two ledger rows for the order, not four', count(orderMovesOf($raceOrderId)), 2);
    check('race: the bloom moved by one recipe', $bloomBeforeRace - stockOf($s2BloomId), 3);
    check('race: and so did the wire', $wireBeforeRace - stockOf($s2WireId), 3);
    check('race: the books still balance', stockAuditDiscrepancies(), []);
}

check('the books still balance after the Stage 2 section', stockAuditDiscrepancies(), []);

/* ------------------------------------------------------------------ *
 * 7. Fractional raw-material stock and product yields.
 * ------------------------------------------------------------------ */

heading('7. fractional raw materials');

check('material input: accepts half a unit', parseMaterialQuantity('0.5'), 0.5);
check('material input: refuses more than three decimal places', parseMaterialQuantity('0.1234'), null);
check('material input: refuses arrays', parseMaterialQuantity(['0.5']), null);

$materialResult = createMaterial('ZZTest Inv Red Fuzzy Wire', 'strand');
check('material: created', $materialResult['ok'], true);
$fractionalMaterialId = (int) $materialResult['id'];
recordMaterialMove($fractionalMaterialId, 5.0, 'opening', ['userId' => null, 'note' => 'ZZTest fractional opening']);
check('material ledger: opening balance agrees with its movement', materialStockAuditDiscrepancies(), []);

$materialsPage = request('php/admin/materials.php', $jarA);
check('materials screen: loads', $materialsPage['status'], 200);
checkTrue('materials screen: shows the new raw material', str_contains($materialsPage['body'], 'ZZTest Inv Red Fuzzy Wire'));
$token = tokenFrom('php/admin/materials.php', $jarA);
$received = request('php/admin/materials.php', $jarA, [
    'csrf_token' => $token,
    'id' => $fractionalMaterialId,
    'qty' => '0.25',
    'action' => 'receive',
    'note' => 'ZZTest fractional receipt',
]);
check('materials screen: fractional receipt redirects', $received['status'], 302);
check('materials screen: fractional receipt is added', materialStockOf($fractionalMaterialId), 5.25);
$token = tokenFrom('php/admin/materials.php', $jarA);
request('php/admin/materials.php', $jarA, [
    'csrf_token' => $token,
    'id' => $fractionalMaterialId,
    'qty' => '5',
    'action' => 'set',
]);
check('materials screen: stock-take resets the opening count', materialStockOf($fractionalMaterialId), 5.0);

$database->prepare(
    "INSERT INTO products (name, kind, description, price, colour, stem_count, is_active)
     VALUES ('ZZTest Inv Fraction Rose', 'flower', 'Fractional material fixture.', 50.00, 'Red', 1, 0)"
)->execute();
$fractionalProductId = (int) $database->lastInsertId();
check('material recipe: one wire yields two roses', saveProductMaterials($fractionalProductId, [$fractionalMaterialId => '1'], '2')['errors'], []);
check('yield: five wires make ten roses', materialBuildableCount($fractionalProductId), 10);
$recipeUrl = 'php/admin/product-materials.php?id=' . $fractionalProductId;
$recipePage = request($recipeUrl, $jarA);
check('product materials screen: loads', $recipePage['status'], 200);
checkTrue('product materials screen: identifies its batch yield', str_contains($recipePage['body'], 'value="2"'));

[$fractionalOrderId, $fractionalRef] = $s2Order('ZZ28', 'placed', $fractionalProductId, 'ZZTest Inv Fraction Rose', 1);
$fractionalUrl = 'php/admin/order.php?ref=' . rawurlencode($fractionalRef);
$fractionalRequirement = orderStockRequirement($fractionalOrderId);
check('forecast: one rose needs half a wire', (float) $fractionalRequirement['materials'][0]['needed'], 0.5);
check('forecast: a material recipe hides the old component recipe', $fractionalRequirement['parts'], []);
$forecastPanel = stockPanel(request($fractionalUrl, $jarA)['body']);
checkTrue('order forecast: shows the fractional amount and unit', str_contains($forecastPanel, '0.5 strand'));

$token = tokenFrom($fractionalUrl, $jarA);
request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'pack', 'ref' => $fractionalRef]);
check('pack: half a wire remains in stock', materialStockOf($fractionalMaterialId), 4.5);
check('pack: the ledger records half a wire out', materialStockOut($fractionalOrderId), [$fractionalMaterialId => 0.5]);
check('pack: fractional balance still agrees with its ledger', materialStockAuditDiscrepancies(), []);
$fractionalPanel = stockPanel(request($fractionalUrl, $jarA)['body']);
checkTrue('order panel: names the raw material', str_contains($fractionalPanel, 'ZZTest Inv Red Fuzzy Wire'));
checkTrue('order history: shows the fractional movement', str_contains($fractionalPanel, '-0.5'));

$token = tokenFrom($fractionalUrl, $jarA);
request('php/admin/orders.php', $jarA, ['csrf_token' => $token, 'action' => 'unpack', 'ref' => $fractionalRef]);
check('unpack: the unused half-wire is returned', materialStockOf($fractionalMaterialId), 5.0);
check('unpack: material movements net to zero', materialStockOut($fractionalOrderId), []);
check('unpack: movement history retains both decimal rows', count(fetchMaterialOrderMoves($fractionalOrderId)), 2);
check('unpack: material ledger still balances', materialStockAuditDiscrepancies(), []);
$materialHistory = request('php/admin/material-moves.php?order=' . $fractionalOrderId, $jarA);
check('material ledger screen: loads for one order', $materialHistory['status'], 200);
checkTrue('material ledger screen: shows the fractional movement', str_contains($materialHistory['body'], '-0.5'));
checkTrue('material ledger screen: names the material', str_contains($materialHistory['body'], 'ZZTest Inv Red Fuzzy Wire'));

/* ------------------------------------------------------------------ *
 * Sweep, and the shop as we found it.
 * ------------------------------------------------------------------ */

heading('sweep');

$stranded = sweepFixtures($database);
check('every cleanup statement ran', $stranded, []);

$left = (int) $database->query(
    "SELECT (SELECT COUNT(*) FROM products WHERE name LIKE 'ZZTest%')
          + (SELECT COUNT(*) FROM product_components WHERE name LIKE 'ZZTest%')
          + (SELECT COUNT(*) FROM stock_moves WHERE component_name LIKE 'ZZTest%')
            + (SELECT COUNT(*) FROM materials WHERE name LIKE 'ZZTest%')
            + (SELECT COUNT(*) FROM material_moves WHERE material_name LIKE 'ZZTest%')
            + (SELECT COUNT(*) FROM product_materials pm JOIN products p ON p.id = pm.product_id WHERE p.name LIKE 'ZZTest%')
          + (SELECT COUNT(*) FROM users WHERE email LIKE 'zztest.inv.%@capstone.test')"
)->fetchColumn();
check('nothing of this run is left behind', $left, 0);

heading('the shop, as we found it');
check('the same number of parts', countStockLevels(), $baseParts);
check('the same number of ledger rows', countStockMoves(), $baseMoves);
check('the same units in stock', stockSummary()['units'], $baseSummary['units']);
check('the same number of materials', (int) $database->query('SELECT COUNT(*) FROM materials')->fetchColumn(), $baseMaterials);
check('the same number of material movements', (int) $database->query('SELECT COUNT(*) FROM material_moves')->fetchColumn(), $baseMaterialMoves);
check('the same fractional material stock', (float) $database->query('SELECT COALESCE(SUM(stock_on_hand), 0) FROM materials')->fetchColumn(), $baseMaterialUnits);
check('and the books balance', stockAuditDiscrepancies(), []);

/* ------------------------------------------------------------------ *
 * Result.
 * ------------------------------------------------------------------ */

heading('result');

if ($notices !== []) {
    echo "  PHP had something to say:\n";
    foreach (array_unique($notices) as $notice) {
        echo "    - $notice\n";
    }
}

check('PHP said nothing', count(array_unique($notices)), 0);

echo "\n$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
