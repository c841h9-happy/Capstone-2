<?php

declare(strict_types=1);

/**
 * Fixtures for the layout suite, and the sweep that takes them away.
 *
 *     C:\xampp\php\php.exe php\tools\layout-fixtures.php setup
 *     C:\xampp\php\php.exe php\tools\layout-fixtures.php sweep
 *
 * `setup` prints one line of JSON that php\tools\layout-test.mjs reads. Node drives the browser;
 * SQL stays here, in PHP, where the module layer already is -- so a fixture is built by
 * saveProductParts() and recordStockMove() rather than by a hand-written INSERT that has to be kept
 * in step with them. It is also why there is no second idea of what a valid order looks like.
 *
 * WHY THERE ARE FIXTURES AT ALL. Three of the site's screens only exist when something is in the
 * database: php\cart.php and php\checkout.php need a basket with something in it, and
 * php\order.php?ref= needs an order the SIGNED-IN visitor owns. That last one matters more than it
 * sounds -- it is the customer's copy of the order page, it uses the same .order-main grid as the
 * admin's, and on 2026-10-02 it was carrying the same phone-width fault with nobody ever having
 * looked at it. A suite that skipped it would have missed the thing it exists to find.
 *
 * Everything here is prefixed ZZTest and swept at both ends. **Nothing ever writes to a seeded
 * row**, which is the rule that got broken once on this project and cost twelve product names.
 */

// Same guard as every other file in this folder. php\tools is under the webroot, and this one
// creates accounts and orders.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/inventory.php';

const LAYOUT_EMAIL = 'zztest.layout@capstone.test';
const LAYOUT_PASSWORD = 'ZzTestLayout123!';
const LAYOUT_REFERENCE = 'SLJ-20261002-ZZL2';

/**
 * Everything this file ever creates, deleted in the order the foreign keys allow.
 *
 * orders before users, because fk_orders_user is ON DELETE RESTRICT -- an account with an order
 * still on it cannot be removed, and the sweep would strand every row from there down.
 *
 * order_items, order_item_parts and order_events all CASCADE from orders, and cart_item_parts
 * cascades from cart_items, so those are not listed. carts are matched by owner rather than by a
 * high-water id: carts.id 37 belongs to a real customer and carts.id 12 to the admin, and an
 * id-range delete is how a live basket gets taken out by a test.
 *
 * Each statement runs in its own try. ERRMODE_EXCEPTION means one bad statement ends the request,
 * so a linear block abandons every row after the failure -- which is how a single wrong column name
 * once stranded three users and two products.
 *
 * @return string[] the statements that failed, if any
 */
function sweepLayoutFixtures(PDO $database): array
{
    $broken = [];

    $owner = "(SELECT id FROM users WHERE email = '" . LAYOUT_EMAIL . "')";

    foreach ([
        "DELETE FROM stock_moves WHERE component_name LIKE 'ZZTest Layout%'",
        "DELETE FROM product_parts WHERE product_id IN (SELECT id FROM products WHERE name LIKE 'ZZTest Layout%')",
        "DELETE FROM product_parts WHERE component_id IN (SELECT id FROM product_components WHERE name LIKE 'ZZTest Layout%')",
        "DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM carts WHERE user_id IN $owner)",
        "DELETE FROM cart_items WHERE name LIKE 'ZZTest Layout%'",
        "DELETE FROM carts WHERE user_id IN $owner",
        "DELETE FROM orders WHERE contact_name LIKE 'ZZTest Layout%'",
        "DELETE FROM orders WHERE user_id IN $owner",
        "DELETE FROM saved_designs WHERE user_id IN $owner",
        "DELETE FROM products WHERE name LIKE 'ZZTest Layout%'",
        "DELETE FROM product_components WHERE name LIKE 'ZZTest Layout%'",
        "DELETE FROM trusted_devices WHERE user_id IN $owner",
        "DELETE FROM email_codes WHERE user_id IN $owner",
        "DELETE FROM users WHERE email = '" . LAYOUT_EMAIL . "'",
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
    $broken = sweepLayoutFixtures($database);

    foreach ($broken as $line) {
        fwrite(STDERR, "sweep failed: $line\n");
    }

    echo json_encode(['swept' => true, 'broken' => count($broken)]), "\n";
    exit($broken === [] ? 0 : 1);
}

if ($mode !== 'setup') {
    fwrite(STDERR, "usage: layout-fixtures.php setup|sweep\n");
    exit(2);
}

// Idempotent: a previous run that died mid-way must not stop this one, and the baseline below has
// to be read from a shop with none of this in it.
sweepLayoutFixtures($database);

/* -- A customer to be. ---------------------------------------------- */

/*
 * email_verified_at set, because login.php sends an unverified account off to finish signing up
 * rather than signing it in -- which looks exactly like a broken sign-in form.
 *
 * password_hash() is PHP's own, so PHP makes it. A hash pasted from anywhere else is a hash whose
 * algorithm and cost this installation did not choose.
 */
$statement = $database->prepare(
    "INSERT INTO users (name, email, password_hash, role, email_verified_at)
     VALUES ('ZZTest Layout Shopper', :email, :hash, 'customer', NOW())"
);
$statement->execute([
    'email' => LAYOUT_EMAIL,
    'hash' => password_hash(LAYOUT_PASSWORD, PASSWORD_DEFAULT),
]);
$customerId = (int) $database->lastInsertId();

/* -- Something to buy, with a recipe behind it. --------------------- */

/*
 * is_active = 1, unlike most ZZTest products here: this one has to appear in the public catalogue
 * so the suite can reach it the way a visitor does, through php\products.php.
 */
$database->prepare(
    "INSERT INTO products (name, kind, description, price, colour, stem_count, is_active)
     VALUES ('ZZTest Layout Bouquet', 'bouquet',
             'A fixture for the layout pass. It is not for sale and is deleted when the pass ends.',
             420.00, 'Dusty rose', 9, 1)"
)->execute();
$productId = (int) $database->lastInsertId();

$makePart = $database->prepare(
    "INSERT INTO product_components (name, kind, description, price, colour, colour_hex, sort_order, is_active)
     VALUES (:name, :kind, 'Layout fixture.', 12.00, 'Dusty rose', '#b07f9f', 940, 1)"
);

$makePart->execute(['name' => 'ZZTest Layout Rose Head', 'kind' => 'bloom']);
$bloomId = (int) $database->lastInsertId();

$makePart->execute(['name' => 'ZZTest Layout Stem', 'kind' => 'wire']);
$wireId = (int) $database->lastInsertId();

// Through recordStockMove(), not a stock_on_hand write: a balance with no ledger row behind it is
// exactly what the audit banner on php\admin\stock-moves.php exists to shout about, and it would
// then be in the photograph.
recordStockMove($bloomId, 24, 'opening', ['userId' => null, 'note' => 'ZZTest Layout opening']);
recordStockMove($wireId, 24, 'opening', ['userId' => null, 'note' => 'ZZTest Layout opening']);

saveProductParts($productId, [$bloomId => 9, $wireId => 9]);

/* -- An order the shopper owns. ------------------------------------- */

/*
 * Straight into the tables rather than through checkout, for the reason admin-http-test.php does
 * the same: what is being measured is how the order page LAYS OUT, and building it through the
 * customer journey would make this file a second copy of journey-test.mjs.
 *
 * The reference has to look real. orderReferenceFromInput() accepts nothing but
 * /^SLJ-\d{8}-[23456789A-HJ-NP-Z]{4}$/ and php\order.php answers anything else with a 404, so a
 * ZZ-prefixed reference would quietly turn every assertion on that screen into an assertion about
 * the Not found page. Seventeen characters exactly: orders.reference is CHAR(17).
 */
$database->prepare(
    "INSERT INTO orders
         (reference, user_id, status, payment_status, payment_method, fulfilment,
          contact_name, contact_phone, contact_email, address_line, barangay, city, province, postcode,
          total, placed_at)
     VALUES (:reference, :user, 'packed', 'paid', 'gcash', 'delivery',
             'ZZTest Layout Shopper', '0917 555 1002', :email,
             '14 ZZTest Layout Street', 'Poblacion', 'Cebu City', 'Cebu', '6000', 840.00, NOW())"
)->execute([
    'reference' => LAYOUT_REFERENCE,
    'user' => $customerId,
    'email' => LAYOUT_EMAIL,
]);
$orderId = (int) $database->lastInsertId();

$database->prepare(
    'INSERT INTO order_items (order_id, kind, product_id, name, unit_price, quantity, line_total, arrangement)
     VALUES (:order, :kind, :product, :name, :unit, :quantity, :line, :arrangement)'
)->execute([
    'order' => $orderId,
    'kind' => 'product',
    'product' => $productId,
    'name' => 'ZZTest Layout Bouquet',
    'unit' => '420.00',
    'quantity' => 2,
    'line' => '840.00',
    'arrangement' => 'dome',
]);

/*
 * ONE history row, not two, and the reason is worth writing down because the first version of this
 * file got it wrong and the mistake reached a screenshot.
 *
 * orderTimeline() in php/config/orders.php ALWAYS synthesises its first dot from
 * `orders.placed_at` and then appends every `order_events` row after it. And placeOrder() never
 * writes a 'placed' event -- the only event it records is 'cancelled'. So a real order has exactly
 * one Placed dot, from the column, and `order_events` holds only what happened afterwards.
 *
 * This file used to insert a 'placed' row as well, which no real order ever has, so the customer's
 * order page drew **"Placed" twice** -- once from the column and once from the invented row. It was
 * photographed at 390px during the 2026-10-12 browser pass and read as a site defect until the
 * timeline code was checked. A fixture that does not look like production is a fixture that reports
 * bugs the shop does not have.
 *
 * One 'packed' row is still enough for what the fixture is for: the timeline then draws Placed
 * (done) -> Being made (now) -> Delivered (still to come), which exercises the filled and unfilled
 * states and the "here" marker, and matches what the admin sees after pressing Start making.
 *
 * The column is `happened_at`, not `created_at` -- it is the only timestamp column in the project
 * that is not called created_at, and it is named for what it records rather than for when the row
 * was written.
 */
$database->prepare(
    'INSERT INTO order_events (order_id, status, actor, happened_at)
     VALUES (:order, :status, :actor, NOW())'
)->execute(['order' => $orderId, 'status' => 'packed', 'actor' => 'shop']);

echo json_encode([
    'email' => LAYOUT_EMAIL,
    'password' => LAYOUT_PASSWORD,
    'productId' => $productId,
    'orderRef' => LAYOUT_REFERENCE,
    'bloomId' => $bloomId,
    'wireId' => $wireId,
], JSON_UNESCAPED_SLASHES), "\n";
