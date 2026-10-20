<?php

declare(strict_types=1);

/**
 * Fixtures for the journey suite, the database half of its assertions, and the sweep.
 *
 *     C:\xampp\php\php.exe php\tools\journey-fixtures.php setup
 *     C:\xampp\php\php.exe php\tools\journey-fixtures.php check <reference>
 *     C:\xampp\php\php.exe php\tools\journey-fixtures.php sweep
 *
 * Each mode prints one line of JSON for php\tools\journey-test.mjs to read.
 *
 * `check` deliberately does NOT assert anything. It reports what the database says and lets the
 * suite in Node do the comparing, so one run has one set of counters and one verdict -- the old
 * %TEMP% version split its checks between a PowerShell wrapper and a Node script and printed two
 * independent tallies that nothing added up.
 *
 * Everything is prefixed ZZTest Journey and swept at both ends. **Nothing writes to a seeded row.**
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/inventory.php';

const JOURNEY_EMAIL = 'zztest.journey@capstone.test';
const JOURNEY_PASSWORD = 'ZzTestJourney123!';
const JOURNEY_BUYER = 'ZZTest Journey Shopper';

/**
 * @return string[] the statements that failed, if any
 */
function sweepJourneyFixtures(PDO $database): array
{
    $broken = [];
    $owner = "(SELECT id FROM users WHERE email = '" . JOURNEY_EMAIL . "')";

    // orders before users: fk_orders_user is ON DELETE RESTRICT. order_items, order_item_parts and
    // order_events all CASCADE from orders, so they are not listed.
    //
    // stock_moves is NOT cascaded away -- fk_stock_moves_order is ON DELETE SET NULL, on purpose,
    // because the parts really did leave the drawer. It is matched by component name instead.
    foreach ([
        "DELETE FROM stock_moves WHERE component_name LIKE 'ZZTest Journey%'",
        "DELETE FROM product_parts WHERE product_id IN (SELECT id FROM products WHERE name LIKE 'ZZTest Journey%')",
        "DELETE FROM product_parts WHERE component_id IN (SELECT id FROM product_components WHERE name LIKE 'ZZTest Journey%')",
        "DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM carts WHERE user_id IN $owner)",
        "DELETE FROM cart_items WHERE name LIKE 'ZZTest Journey%'",
        "DELETE FROM carts WHERE user_id IN $owner",
        "DELETE FROM orders WHERE contact_name = '" . JOURNEY_BUYER . "'",
        "DELETE FROM orders WHERE user_id IN $owner",
        "DELETE FROM saved_designs WHERE user_id IN $owner",
        "DELETE FROM products WHERE name LIKE 'ZZTest Journey%'",
        "DELETE FROM product_components WHERE name LIKE 'ZZTest Journey%'",
        "DELETE FROM trusted_devices WHERE user_id IN $owner",
        "DELETE FROM email_codes WHERE user_id IN $owner",
        "DELETE FROM users WHERE email = '" . JOURNEY_EMAIL . "'",
    ] as $sql) {
        try {
            $database->exec($sql);
        } catch (PDOException $exception) {
            $broken[] = $sql . ' -- ' . explode("\n", $exception->getMessage())[0];
        }
    }

    return $broken;
}

$database = getDatabaseConnection();
$mode = $argv[1] ?? '';

if ($mode === 'sweep') {
    $broken = sweepJourneyFixtures($database);

    foreach ($broken as $line) {
        fwrite(STDERR, "sweep failed: $line\n");
    }

    echo json_encode(['swept' => true, 'broken' => count($broken)]), "\n";
    exit($broken === [] ? 0 : 1);
}

if ($mode === 'check') {
    $reference = (string) ($argv[2] ?? '');

    $order = $database->prepare(
        'SELECT o.id, o.user_id, o.status, o.payment_status, o.total, u.email
           FROM orders o
           LEFT JOIN users u ON u.id = o.user_id
          WHERE o.reference = :reference'
    );
    $order->execute(['reference' => $reference]);
    $row = $order->fetch();

    if ($row === false) {
        echo json_encode(['found' => false]), "\n";
        exit(0);
    }

    $orderId = (int) $row['id'];

    $one = static function (string $sql, array $params = []) use ($database): string {
        $statement = $database->prepare($sql);
        $statement->execute($params);

        return (string) $statement->fetchColumn();
    };

    echo json_encode([
        'found' => true,
        'email' => (string) $row['email'],
        'status' => (string) $row['status'],
        'payment' => (string) $row['payment_status'],
        'lines' => (int) $one('SELECT COUNT(*) FROM order_items WHERE order_id = :o', ['o' => $orderId]),
        'lineName' => $one('SELECT name FROM order_items WHERE order_id = :o LIMIT 1', ['o' => $orderId]),
        // The basket must be empty afterwards -- checkout moves it, it does not copy it.
        'basketLeft' => (int) $one("SELECT COUNT(*) FROM cart_items WHERE name LIKE 'ZZTest Journey%'"),
        'deliveredByShop' => (int) $one(
            "SELECT COUNT(*) FROM order_events WHERE order_id = :o AND status = 'delivered' AND actor = 'shop'",
            ['o' => $orderId]
        ),
        'history' => $one(
            "SELECT GROUP_CONCAT(CONCAT(status, ':', actor) ORDER BY id SEPARATOR ' -> ')
               FROM order_events WHERE order_id = :o",
            ['o' => $orderId]
        ),
        /*
         * The stock half, which did not exist when this walk was first written.
         *
         * The bouquet has a recipe, so pressing Start making must take its parts out -- two rows,
         * both 'used'. Pressing Mark delivered must take nothing more: the parts left the drawer
         * when making started, and a second consumption there is the double-count the whole
         * ledger-derived design exists to make impossible. Four rows here would be that bug.
         */
        'moves' => (int) $one('SELECT COUNT(*) FROM stock_moves WHERE order_id = :o', ['o' => $orderId]),
        'used' => (int) $one("SELECT COUNT(*) FROM stock_moves WHERE order_id = :o AND reason = 'used'", ['o' => $orderId]),
        'unitsOut' => (int) $one(
            "SELECT COALESCE(-SUM(delta), 0) FROM stock_moves WHERE order_id = :o AND reason IN ('used','returned')",
            ['o' => $orderId]
        ),
        'bloomLeft' => (int) $one("SELECT stock_on_hand FROM product_components WHERE name = 'ZZTest Journey Rose'"),
        'audit' => count(stockAuditDiscrepancies()),
    ], JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

if ($mode !== 'setup') {
    fwrite(STDERR, "usage: journey-fixtures.php setup|check <reference>|sweep\n");
    exit(2);
}

sweepJourneyFixtures($database);

// email_verified_at set: login.php sends an unverified account off to finish signing up rather
// than signing it in, which looks exactly like a broken sign-in form.
$statement = $database->prepare(
    "INSERT INTO users (name, email, password_hash, role, email_verified_at)
     VALUES (:name, :email, :hash, 'customer', NOW())"
);
$statement->execute([
    'name' => JOURNEY_BUYER,
    'email' => JOURNEY_EMAIL,
    'hash' => password_hash(JOURNEY_PASSWORD, PASSWORD_DEFAULT),
]);

// is_active = 1: the walk reaches this product the way a customer does, through the catalogue.
$database->prepare(
    "INSERT INTO products (name, kind, description, price, colour, stem_count, is_active)
     VALUES ('ZZTest Journey Bouquet', 'bouquet',
             'A fixture for the journey pass. Deleted when the pass ends.', 275.00, 'Test', 6, 1)"
)->execute();
$productId = (int) $database->lastInsertId();

$makePart = $database->prepare(
    "INSERT INTO product_components (name, kind, description, price, colour, colour_hex, sort_order, is_active)
     VALUES (:name, :kind, 'Journey fixture.', 11.00, 'Test', '#b07f9f', 950, 1)"
);

$makePart->execute(['name' => 'ZZTest Journey Rose', 'kind' => 'bloom']);
$bloomId = (int) $database->lastInsertId();

$makePart->execute(['name' => 'ZZTest Journey Stem', 'kind' => 'wire']);
$wireId = (int) $database->lastInsertId();

// Openings through recordStockMove(), so the books balance and the audit banner stays down.
recordStockMove($bloomId, 20, 'opening', ['userId' => null, 'note' => 'ZZTest Journey opening']);
recordStockMove($wireId, 20, 'opening', ['userId' => null, 'note' => 'ZZTest Journey opening']);

/*
 * A recipe of 3 and 3, against a walk that buys one. Start making therefore takes 3 of each and
 * leaves the bloom on 17 -- a number that is wrong in a different way for each of the mistakes
 * worth catching: 20 if the press consumed nothing, 14 if delivering consumed a second time.
 */
saveProductParts($productId, [$bloomId => 3, $wireId => 3]);

echo json_encode([
    'email' => JOURNEY_EMAIL,
    'password' => JOURNEY_PASSWORD,
    'buyer' => JOURNEY_BUYER,
    'productId' => $productId,
    'bloomId' => $bloomId,
    'wireId' => $wireId,
], JSON_UNESCAPED_SLASHES), "\n";
