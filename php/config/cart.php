<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/components.php';
require_once __DIR__ . '/customizer.php';

/**
 * The basket: reads, writes, and the merge that happens when a guest signs in.
 *
 * This file is the first in config/ that a signed-out visitor can write through, which is the
 * one thing to keep in mind while reading it. Everything else that writes -- profile.php, the
 * admin screens, design-save.php -- has an account behind it, so "whose row is this?" has an
 * answer before the query runs. Here the answer is either an account or a cookie, and every
 * statement has to be scoped by whichever it is. cartScope() is the single place that decides,
 * and every read and write below takes its answer rather than working it out again.
 *
 * **Prices freeze on the way in.** php/config/customizer.php's designSnapshot() docblock names
 * this file as the place that happens: "an order is a contract, so the prices in it have to stop
 * tracking product_components, and the copy has to happen exactly once, at a known point". So
 * addProductToCart() and addDesignToCart() copy the price in, and nothing below ever reads a
 * price back out of products or product_components. cartTotal() sums what the basket stored.
 *
 * That is also why the reads here do not join. A basket line carries its own name, price and
 * colour, so it renders after the product is deleted and the part is withdrawn -- and a join
 * would quietly re-introduce the live price the freeze exists to escape.
 */

/**
 * The guest's basket cookie.
 *
 * Long-lived on purpose: a basket that evaporates with the PHP session is a basket that
 * evaporates at sign-in, because signInUser() calls session_regenerate_id(). Thirty days
 * matches TRUSTED_DEVICE_DAYS, which is the only other cookie this project sets.
 */
const CART_COOKIE = 'capstone_cart';
const CART_COOKIE_DAYS = 30;

/** How many of one line somebody may have. A basket, not a wholesale order. */
const CART_QUANTITY_MIN = 1;
const CART_QUANTITY_MAX = 99;

/** How many distinct lines one basket may hold, so a script cannot grow it without bound. */
const CART_LINES_MAX = 50;

/**
 * How many lines the navbar's hover panel lists before it stops and says "and N more".
 *
 * Four, because the panel hangs off a corner of a fixed navbar and a basket may hold fifty lines.
 * It is a glance, not the basket -- the page one click away is where all of them are.
 */
const CART_PREVIEW_LINES = 4;

/** The two shapes a line comes in. */
const CART_LINE_KINDS = ['product', 'design'];

/**
 * Cookie options for the basket token.
 *
 * Identical reasoning to trustedDeviceCookieOptions(): 'secure' is false because this project
 * runs over plain HTTP on localhost and a Secure cookie would be discarded rather than stored,
 * 'httponly' keeps it away from JavaScript, and 'Lax' still sends it on a normal navigation.
 */
function cartCookieOptions(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Who this request's basket belongs to: ['user' => int] or ['token' => string].
 *
 * The single answer to "whose basket is this?", so no statement below has to ask again. A
 * signed-in account always wins over a cookie -- somebody who signs in is working on their
 * account's basket from that moment, and mergeGuestCart() has already moved anything the
 * browser was carrying into it.
 *
 * Returns a token of '' when there is no cookie and nobody is signed in, which means "no basket
 * exists yet and none has been asked for". Reads answer empty; writes call cartTokenForWriting()
 * instead, which mints one.
 *
 * @return array{user?: int, token?: string}
 */
function cartScope(): array
{
    if (isUserLoggedIn()) {
        return ['user' => (int) $_SESSION['user_id']];
    }

    return ['token' => (string) ($_COOKIE[CART_COOKIE] ?? '')];
}

/**
 * The guest token for a request that is about to write, minting and setting one if needed.
 *
 * Separate from cartScope() because reading and writing want different things from a missing
 * cookie. A read with no cookie is an empty basket and must not set anything -- otherwise every
 * visitor to the home page gets a cookie and a row for a basket they never touched. A write with
 * no cookie needs one to write against.
 *
 * Must be called before output, because it may set a cookie.
 */
function cartTokenForWriting(): string
{
    $token = (string) ($_COOKIE[CART_COOKIE] ?? '');

    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        // Set in $_COOKIE as well as in the header, so a second call within this same request
        // finds the token that was just minted rather than making another one.
        $_COOKIE[CART_COOKIE] = $token;
    }

    setcookie(CART_COOKIE, $token, cartCookieOptions(time() + CART_COOKIE_DAYS * 86400));

    return $token;
}

/**
 * Hashed the way devices.php hashes its token, and for the same reason: the value has to be
 * searchable, so sha256 rather than password_hash(). Safe here because the token is 32 bytes
 * from the system random source -- there is no small set of likely values to hash through.
 */
function cartTokenHash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * This request's basket id, or null when there is not one yet.
 *
 * Read-only: it never creates. Pages call this, so viewing a basket that does not exist shows an
 * empty basket rather than creating a row to prove it is empty.
 */
function findCartId(): ?int
{
    $scope = cartScope();

    if (isset($scope['user'])) {
        $statement = getDatabaseConnection()->prepare(
            'SELECT id FROM carts WHERE user_id = :user LIMIT 1'
        );
        $statement->execute(['user' => $scope['user']]);
    } else {
        if (($scope['token'] ?? '') === '') {
            return null;
        }

        $statement = getDatabaseConnection()->prepare(
            'SELECT id FROM carts WHERE token_hash = :hash LIMIT 1'
        );
        $statement->execute(['hash' => cartTokenHash($scope['token'])]);
    }

    $id = $statement->fetchColumn();

    return $id === false ? null : (int) $id;
}

/**
 * This request's basket id, creating the row if there is not one.
 *
 * Called only by the writes. The INSERT is guarded by both unique keys, so two requests racing
 * to create the same basket end with one row and one of them re-reading it rather than a
 * duplicate -- which is why the catch re-reads instead of giving up.
 *
 * Must be called before output when nobody is signed in, because it may set the cookie.
 */
function openCartId(): int
{
    $existing = findCartId();

    if ($existing !== null) {
        return $existing;
    }

    $database = getDatabaseConnection();

    if (isUserLoggedIn()) {
        $column = 'user_id';
        $value = (int) $_SESSION['user_id'];
    } else {
        $column = 'token_hash';
        $value = cartTokenHash(cartTokenForWriting());
    }

    try {
        $database->prepare(
            "INSERT INTO carts ($column) VALUES (:value)"
        )->execute(['value' => $value]);

        return (int) $database->lastInsertId();
    } catch (PDOException $exception) {
        // Lost a race against another request for the same basket. The unique key did its job;
        // the row we wanted now exists, so use it.
        $found = findCartId();

        if ($found !== null) {
            return $found;
        }

        throw $exception;
    }
}

/**
 * Move a guest basket into the account's basket. Called from signInUser(), nowhere else.
 *
 * This is the whole reason a guest basket is a row rather than a session array: sign-in is the
 * moment it has to survive, and session_regenerate_id() runs right there.
 *
 * Three cases, and the third is the one worth reading:
 *
 *   - No guest basket. Nothing to do.
 *   - The account has no basket. The guest row is simply re-labelled: token_hash cleared,
 *     user_id set. Nothing is copied, so nothing can be copied wrong.
 *   - Both exist. The guest's lines are re-parented onto the account's basket and the empty
 *     guest row is deleted. Lines are *added* rather than reconciled: somebody who put two of
 *     something in while signed out and already had one signed in ends with both lines, not a
 *     line of three. Merging them would mean averaging two frozen prices, which would produce a
 *     price neither side was ever shown. Two lines at the prices they were each added at is the
 *     only version that stays honest, and the basket page shows them separately so it is
 *     visible rather than surprising.
 *
 * Deliberately never throws: a database problem here must not stop somebody signing in. The
 * basket is recoverable, the sign-in is what they asked for. Failure is logged and the guest
 * cookie is left alone, so the lines are still there to merge on the next attempt.
 *
 * Must be called before output, because it clears the guest cookie.
 */
function mergeGuestCart(int $userId): void
{
    $token = (string) ($_COOKIE[CART_COOKIE] ?? '');

    if ($token === '') {
        return;
    }

    try {
        $database = getDatabaseConnection();

        $statement = $database->prepare('SELECT id FROM carts WHERE token_hash = :hash LIMIT 1');
        $statement->execute(['hash' => cartTokenHash($token)]);
        $guestId = $statement->fetchColumn();

        if ($guestId === false) {
            clearCartCookie();

            return;
        }

        $guestId = (int) $guestId;

        $statement = $database->prepare('SELECT id FROM carts WHERE user_id = :user LIMIT 1');
        $statement->execute(['user' => $userId]);
        $ownId = $statement->fetchColumn();

        if ($ownId === false) {
            $database->prepare(
                'UPDATE carts SET user_id = :user, token_hash = NULL WHERE id = :id'
            )->execute(['user' => $userId, 'id' => $guestId]);

            clearCartCookie();

            return;
        }

        // Both baskets exist. Re-parent the lines, then drop the empty guest row.
        //
        // One statement rather than a read-then-write loop: the lines carry their own frozen
        // prices and parts hang off them by item_id, so moving the parent moves everything with
        // it and there is nothing to copy.
        $database->prepare(
            'UPDATE cart_items SET cart_id = :own WHERE cart_id = :guest'
        )->execute(['own' => (int) $ownId, 'guest' => $guestId]);

        $database->prepare('DELETE FROM carts WHERE id = :id')->execute(['id' => $guestId]);

        clearCartCookie();
    } catch (PDOException $exception) {
        // Logged, not raised. Signing in matters more than the basket, and leaving the cookie
        // in place means the lines are still findable next time.
        error_log('mergeGuestCart: ' . $exception->getMessage());
    }
}

/**
 * Drop the guest cookie, because the basket it pointed at now belongs to an account.
 *
 * Leaving it would mean a signed-out visit on this browser later finding a token whose row has
 * an owner -- which findCartId() would not match, so it would be harmless, but it would also
 * never be cleaned up. Clearing it is tidier and makes the state say what is true.
 */
function clearCartCookie(): void
{
    unset($_COOKIE[CART_COOKIE]);
    setcookie(CART_COOKIE, '', cartCookieOptions(time() - 42000));
}

/**
 * Clamp anything to a usable quantity.
 *
 * Junk becomes the minimum rather than an error, matching customBloomCount(): a quantity box is
 * something people type in, and the honest answer to "qty=abc" is one of the thing, not a 500.
 * The check is here in PHP and not left to the column, because this server's sql_mode has no
 * STRICT_TRANS_TABLES -- an out-of-range SMALLINT is silently clamped by MariaDB rather than
 * refused, so the column cannot be the guard. Same argument validateProductInput() makes for
 * money.
 */
function cartQuantity(mixed $quantity): int
{
    if (is_array($quantity) || is_bool($quantity)) {
        return CART_QUANTITY_MIN;
    }

    $value = filter_var($quantity, FILTER_VALIDATE_INT);

    if ($value === false) {
        return CART_QUANTITY_MIN;
    }

    return max(CART_QUANTITY_MIN, min(CART_QUANTITY_MAX, $value));
}

/**
 * Is this basket already as big as it is allowed to get?
 *
 * Counted before an add rather than enforced by a column, so the refusal can say something
 * useful instead of surfacing as a failed INSERT.
 */
function cartIsFull(int $cartId): bool
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT COUNT(*) FROM cart_items WHERE cart_id = :cart'
    );
    $statement->execute(['cart' => $cartId]);

    return (int) $statement->fetchColumn() >= CART_LINES_MAX;
}

/**
 * Put a catalogue product in the basket, or add to the line that is already there.
 *
 * Returns the line id, or null when the product cannot be sold -- a hidden or missing row. The
 * caller decides what to say about that; this returns null rather than throwing because "that
 * product is gone" is a normal thing for a stale page to ask, not an exceptional one.
 *
 * fetchProduct() is used rather than a read that can see hidden rows, so a product taken off
 * sale cannot be added by an old form. That is the same reason config/products.php puts
 * is_active = 1 inside the statement instead of offering a flag.
 *
 * **Same product twice adds up**, which is the opposite of what the design lines do below, and
 * the difference is deliberate: two of the same catalogue product at the same price is a
 * quantity of two, with nothing lost by combining them. Two designs are two different bouquets
 * that happen to look alike right now.
 *
 * Must be called before output: it may open a basket, which may set the cookie.
 */
function addProductToCart(int $productId, mixed $quantity = 1): ?int
{
    $product = fetchProduct($productId);

    if ($product === null) {
        return null;
    }

    $wanted = cartQuantity($quantity);
    $cartId = openCartId();
    $database = getDatabaseConnection();

    $statement = $database->prepare(
        'SELECT id, quantity FROM cart_items
          WHERE cart_id = :cart AND kind = :kind AND product_id = :product
          LIMIT 1'
    );
    $statement->execute(['cart' => $cartId, 'kind' => 'product', 'product' => $productId]);
    $existing = $statement->fetch();

    if ($existing !== false) {
        $id = (int) $existing['id'];

        // Clamped after adding, so asking for 90 more of something you have 20 of lands on the
        // ceiling instead of wrapping or being refused outright.
        $database->prepare(
            'UPDATE cart_items SET quantity = :quantity WHERE id = :id'
        )->execute([
            'quantity' => cartQuantity((int) $existing['quantity'] + $wanted),
            'id' => $id,
        ]);

        return $id;
    }

    if (cartIsFull($cartId)) {
        return null;
    }

    $statement = $database->prepare(
        'INSERT INTO cart_items (cart_id, kind, product_id, name, unit_price, quantity)
         VALUES (:cart, :kind, :product, :name, :price, :quantity)'
    );
    $statement->bindValue('cart', $cartId, PDO::PARAM_INT);
    $statement->bindValue('kind', 'product');
    $statement->bindValue('product', $productId, PDO::PARAM_INT);
    $statement->bindValue('name', (string) $product['name']);
    // Bound as a string, like every other price in this project: PARAM_STR keeps the two
    // decimal places the column expects and never goes near a float.
    $statement->bindValue('price', (string) $product['price'], PDO::PARAM_STR);
    $statement->bindValue('quantity', $wanted, PDO::PARAM_INT);
    $statement->execute();

    return (int) $database->lastInsertId();
}

/**
 * Put a designed bouquet in the basket, frozen.
 *
 * $design is the array php/config/customizer.php builds -- readDesign() from a query string, or
 * readSavedDesign() from a saved row. It is passed through designSnapshot() here, and that call
 * is the freeze: from this point the line carries its own prices and never reads
 * product_components again.
 *
 * Returns the line id, or null when the design has nothing in it or the basket is full. A design
 * with no blooms is what a request naming only withdrawn parts produces, and an empty bouquet is
 * not a thing to sell.
 *
 * **Every add is a new line**, even for an identical design. Two bouquets are two bouquets: the
 * customer may be sending them to different people, and combining them would make the basket say
 * something the customer did not. This is the deliberate opposite of the product path above.
 *
 * The line and its parts go in inside a transaction, because a bouquet line with no parts is a
 * receipt with no items on it -- it would render as a price with nothing behind it, and there
 * would be no way to tell it from a design whose parts were all withdrawn.
 *
 * Must be called before output: it may open a basket, which may set the cookie.
 *
 * @param array<string, mixed> $design
 */
function addDesignToCart(array $design, mixed $quantity = 1): ?int
{
    $snapshot = designSnapshot($design);

    if (($snapshot['lines'] ?? []) === [] || (int) ($snapshot['count'] ?? 0) < 1) {
        return null;
    }

    $wanted = cartQuantity($quantity);
    $cartId = openCartId();

    if (cartIsFull($cartId)) {
        return null;
    }

    $database = getDatabaseConnection();
    $database->beginTransaction();

    try {
        $statement = $database->prepare(
            'INSERT INTO cart_items
                 (cart_id, kind, product_id, name, unit_price, quantity, arrangement)
             VALUES (:cart, :kind, NULL, :name, :price, :quantity, :arrangement)'
        );
        $statement->bindValue('cart', $cartId, PDO::PARAM_INT);
        $statement->bindValue('kind', 'design');
        $statement->bindValue('name', cartDesignName($snapshot));
        $statement->bindValue('price', $snapshot['total'], PDO::PARAM_STR);
        $statement->bindValue('quantity', $wanted, PDO::PARAM_INT);
        $statement->bindValue('arrangement', (string) $snapshot['arrangement']);
        $statement->execute();

        $itemId = (int) $database->lastInsertId();

        $parts = $database->prepare(
            'INSERT INTO cart_item_parts
                 (item_id, component_id, kind, name, colour_hex, each_price, quantity, sort_order)
             VALUES (:item, :component, :kind, :name, :colour, :each, :quantity, :sort)'
        );

        $sort = 0;

        foreach ($snapshot['lines'] as $line) {
            $parts->bindValue('item', $itemId, PDO::PARAM_INT);
            $parts->bindValue('component', (int) $line['componentId'], PDO::PARAM_INT);
            $parts->bindValue('kind', (string) $line['kind']);
            $parts->bindValue('name', (string) $line['name']);
            $parts->bindValue('colour', cartPartColour($line));
            $parts->bindValue('each', (string) $line['each'], PDO::PARAM_STR);
            $parts->bindValue('quantity', (int) $line['quantity'], PDO::PARAM_INT);
            $parts->bindValue('sort', $sort, PDO::PARAM_INT);
            $parts->execute();

            $sort += 1;
        }

        $database->commit();

        return $itemId;
    } catch (PDOException $exception) {
        $database->rollBack();

        throw $exception;
    }
}

/**
 * The colour to freeze for one snapshot line.
 *
 * designSnapshot() deliberately does not carry rows through, so the colour is read from the
 * component here -- the last moment it is legitimate to read one, since after this the line is
 * frozen. A part that has vanished between the snapshot and this call falls back to the same
 * neutral COMPONENT_FALLBACK_HEX the admin form uses, rather than to black, because an
 * unexplained black swatch reads as a real colour somebody chose.
 *
 * @param array<string, mixed> $line
 */
function cartPartColour(array $line): string
{
    $component = ((int) $line['componentId']) > 0 ? fetchComponent((int) $line['componentId']) : null;

    return $component === null ? COMPONENT_FALLBACK_HEX : componentColourHex($component);
}

/**
 * The same colour on the way back out, for a swatch on the basket page.
 *
 * cartPartColour() above freezes a colour by looking a component up; this checks the frozen value
 * before it is printed. Both are needed, and they are not the same moment: the value in the row was
 * validated when it was written, but it goes into a style attribute when it is read, and a stored
 * value is only as trustworthy as the last thing that touched the row.
 *
 * Delegates to componentColourHex() rather than repeating its pattern, so there is one definition
 * of what counts as a colour in this project instead of two that can drift apart.
 */
function cartSwatchColour(string $frozenHex): string
{
    return componentColourHex(['colour_hex' => $frozenHex]);
}

/**
 * What to call a designed line in the basket.
 *
 * Built from the snapshot rather than asked for, because the basket is not the place to make
 * somebody name things -- that is what saving a design is for. "12 flowers, round" is enough to
 * tell two lines apart at a glance, which is all this has to do.
 *
 * @param array<string, mixed> $snapshot
 */function cartDesignName(array $snapshot): string
{
    $count = (int) $snapshot['count'];
    $arrangement = strtolower(designArrangementLabel((string) $snapshot['arrangement']));

    return sprintf(
        'Custom bouquet: %d %s, %s',
        $count,
        $count === 1 ? 'flower' : 'flowers',
        $arrangement
    );
}

/**
 * Every line in this request's basket, each with its parts and its own line total.
 *
 * The name, the price and the colours all come out of cart_items and cart_item_parts and nowhere
 * else, and that is the point rather than an oversight: the line froze them when it was added, so
 * reading them back from the live tables would undo the freeze -- quietly, and only for customers
 * whose basket outlived a price change, which is the worst way for a bug like that to behave.
 *
 * The one join below reads no column at all. `p.id IS NOT NULL` answers "is this product still on
 * sale", which is the single thing about a line that must not be frozen: a basket may contain
 * something the shop has since withdrawn, and the page says so and the total leaves it out, which
 * is honest in a way that silently dropping it would not be. The ON clause is fetchProduct()'s
 * WHERE clause, which is what lets cartLineIsOrderable() trust it -- and asking here rather than
 * per line is what keeps a fifty-line basket at two queries instead of fifty-two.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchCartLines(): array
{
    $cartId = findCartId();

    if ($cartId === null) {
        return [];
    }

    $database = getDatabaseConnection();

    $statement = $database->prepare(
        'SELECT i.id, i.kind, i.product_id, i.name, i.unit_price, i.quantity, i.arrangement,
                (p.id IS NOT NULL) AS product_live
           FROM cart_items i
           LEFT JOIN products p ON p.id = i.product_id AND p.is_active = 1
          WHERE i.cart_id = :cart
          ORDER BY i.id ASC'
    );
    $statement->execute(['cart' => $cartId]);
    $rows = $statement->fetchAll();

    if ($rows === []) {
        return [];
    }

    $partsFor = fetchCartParts(array_map(static fn (array $row): int => (int) $row['id'], $rows));

    $lines = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $quantity = (int) $row['quantity'];

        $lines[] = [
            'id' => $id,
            'kind' => (string) $row['kind'],
            'productId' => $row['product_id'] === null ? null : (int) $row['product_id'],
            'name' => (string) $row['name'],
            'unitPrice' => (string) $row['unit_price'],
            'quantity' => $quantity,
            'arrangement' => (string) $row['arrangement'],
            'parts' => $partsFor[$id] ?? [],
            'lineTotal' => cartLineTotal((string) $row['unit_price'], $quantity),
            'orderable' => cartLineIsOrderable($row),
        ];
    }

    return $lines;
}

/**
 * The frozen parts of every designed line, keyed by line id.
 *
 * One statement for the whole basket rather than one per line: a basket of twenty bouquets would
 * otherwise be twenty-one queries to draw one page. The ids come from rows this request has
 * already read out of its own basket, so they are integers from the database rather than input,
 * but they are cast and bound anyway -- an id that reached a query unbound would be a hole
 * regardless of where it came from.
 *
 * @param array<int, int> $itemIds
 * @return array<int, array<int, array<string, mixed>>>
 */
function fetchCartParts(array $itemIds): array
{
    if ($itemIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

    $statement = getDatabaseConnection()->prepare(
        "SELECT item_id, component_id, kind, name, colour_hex, each_price, quantity
           FROM cart_item_parts
          WHERE item_id IN ($placeholders)
          ORDER BY item_id ASC, sort_order ASC, id ASC"
    );

    foreach (array_values($itemIds) as $position => $id) {
        $statement->bindValue($position + 1, (int) $id, PDO::PARAM_INT);
    }

    $statement->execute();

    $parts = [];

    foreach ($statement->fetchAll() as $row) {
        $parts[(int) $row['item_id']][] = [
            'componentId' => $row['component_id'] === null ? null : (int) $row['component_id'],
            'kind' => (string) $row['kind'],
            'name' => (string) $row['name'],
            'colour' => (string) $row['colour_hex'],
            'each' => (string) $row['each_price'],
            'quantity' => (int) $row['quantity'],
        ];
    }

    return $parts;
}

/**
 * Can this line still be bought?
 *
 * A design always can: it is made of frozen parts and nothing about it depends on a row that may
 * have moved. A product line depends on the product still being on sale.
 *
 * Two ways to find that out, one answer. A caller that already joined products hands its result
 * in as `product_live` and this believes it; a caller holding a bare row gets the query. The join
 * the first kind must use is spelled out in fetchCartLines() and is exactly fetchProduct()'s own
 * WHERE clause -- `p.id = i.product_id AND p.is_active = 1` -- so the two paths cannot come to
 * different conclusions about the same line.
 *
 * Deliberately not a price check, either way. A product whose price has changed is still orderable
 * at the price the basket froze; that is what freezing means, and re-checking it here would
 * reintroduce the live price through the back door.
 *
 * @param array<string, mixed> $row
 */
function cartLineIsOrderable(array $row): bool
{
    if ((string) $row['kind'] !== 'product') {
        return true;
    }

    if (array_key_exists('product_live', $row)) {
        return (int) $row['product_live'] === 1;
    }

    $productId = $row['product_id'] === null ? 0 : (int) $row['product_id'];

    return $productId > 0 && fetchProduct($productId) !== null;
}

/**
 * One line's money: the frozen unit price times how many.
 *
 * Integer centavos throughout, the same arithmetic php/config/customizer.php uses and for the
 * same reason -- multiplying a price as a float and rounding at the end produces figures that
 * disagree with the sum of their own parts.
 */
function cartLineTotal(string $unitPrice, int $quantity): string
{
    return designPriceFromCentavos(designPriceCentavos($unitPrice) * max(0, $quantity));
}

/**
 * What the basket comes to, leaving out anything that can no longer be bought.
 *
 * An unorderable line is shown but not charged for: a total that silently included a product the
 * shop has withdrawn would be a figure the customer could never actually pay.
 *
 * @param array<int, array<string, mixed>> $lines
 */
function cartTotal(array $lines): string
{
    $centavos = 0;

    foreach ($lines as $line) {
        if ($line['orderable'] === false) {
            continue;
        }

        $centavos += designPriceCentavos($line['unitPrice']) * (int) $line['quantity'];
    }

    return designPriceFromCentavos($centavos);
}

/**
 * The whole basket in one query: what is in it, what it comes to, and how many things that is.
 *
 * Written for the navbar, which draws on every page and now shows the lines as well as the count,
 * so its cost is the constraint. fetchCartLines() would be wrong for it twice over -- a second
 * query for the frozen parts the panel never lists, and until today a fetchProduct() per line.
 *
 * What it does share is the rule: the LEFT JOIN is the one fetchCartLines() uses, read by the same
 * cartLineIsOrderable(), and the total comes from the same cartTotal(). A badge, a hover panel and
 * a basket page that disagreed about what is in the basket would be worse than any of them being
 * slow.
 *
 * Returns an empty basket rather than raising on a database error, for the reason cartCount() gave
 * before it: a basket nobody can read must not take down every page on the site.
 *
 * @return array{count:int, lines:array<int, array<string, mixed>>, total:string, gone:int}
 */
function cartSummary(): array
{
    $empty = ['count' => 0, 'lines' => [], 'total' => '0.00', 'gone' => 0];

    try {
        $cartId = findCartId();

        if ($cartId === null) {
            return $empty;
        }

        $statement = getDatabaseConnection()->prepare(
            'SELECT i.id, i.kind, i.product_id, i.name, i.unit_price, i.quantity,
                    (p.id IS NOT NULL) AS product_live
               FROM cart_items i
               LEFT JOIN products p ON p.id = i.product_id AND p.is_active = 1
              WHERE i.cart_id = :cart
              ORDER BY i.id ASC'
        );
        $statement->execute(['cart' => $cartId]);

        $lines = [];
        $count = 0;
        $gone = 0;

        foreach ($statement->fetchAll() as $row) {
            $quantity = (int) $row['quantity'];
            $orderable = cartLineIsOrderable($row);

            $count += $quantity;

            if ($orderable === false) {
                $gone++;
            }

            $lines[] = [
                'id' => (int) $row['id'],
                'kind' => (string) $row['kind'],
                'productId' => $row['product_id'] === null ? null : (int) $row['product_id'],
                'name' => (string) $row['name'],
                'unitPrice' => (string) $row['unit_price'],
                'quantity' => $quantity,
                'lineTotal' => cartLineTotal((string) $row['unit_price'], $quantity),
                'orderable' => $orderable,
            ];
        }

        return [
            'count' => $count,
            'lines' => $lines,
            'total' => cartTotal($lines),
            'gone' => $gone,
        ];
    } catch (PDOException $exception) {
        error_log('cartSummary: ' . $exception->getMessage());

        return $empty;
    }
}

/**
 * How many things are in the basket, for the navbar's badge.
 *
 * The sum of the quantities rather than the number of lines: two lines of three is six things,
 * and a badge saying 2 next to a basket holding six reads as a bug.
 *
 * One field of cartSummary() rather than its own SELECT SUM(), so that a caller wanting both the
 * number and the lines asks once. The navbar does exactly that and calls cartSummary() directly;
 * this stays for anything that only ever wants the figure.
 */
function cartCount(): int
{
    return cartSummary()['count'];
}

/**
 * Change how many of one line.
 *
 * Scoped by the basket as well as by the line id, so a guessed id belonging to somebody else's
 * basket matches nothing -- the same shape every write in config/designs.php uses. Returns false
 * when nothing was changed, which the caller reports as "that line is not in your basket" rather
 * than pretending it worked.
 */
function updateCartQuantity(int $itemId, mixed $quantity): bool
{
    $cartId = findCartId();

    if ($cartId === null || $itemId < 1) {
        return false;
    }

    $statement = getDatabaseConnection()->prepare(
        'UPDATE cart_items SET quantity = :quantity WHERE id = :id AND cart_id = :cart'
    );
    $statement->execute([
        'quantity' => cartQuantity($quantity),
        'id' => $itemId,
        'cart' => $cartId,
    ]);

    // rowCount() is not used to decide whether the row existed: PDO's MySQL driver reports rows
    // *changed*, so setting a quantity to what it already was reports 0 and would look identical
    // to a line that is not there. The scoped read is what answers the question.
    return cartLineExists($itemId, $cartId);
}

/**
 * Take a line out of the basket. Its parts go with it, by ON DELETE CASCADE.
 */
function removeCartLine(int $itemId): bool
{
    $cartId = findCartId();

    if ($cartId === null || $itemId < 1) {
        return false;
    }

    $statement = getDatabaseConnection()->prepare(
        'DELETE FROM cart_items WHERE id = :id AND cart_id = :cart'
    );
    $statement->execute(['id' => $itemId, 'cart' => $cartId]);

    return $statement->rowCount() > 0;
}

/**
 * Is this line in this basket? The scoped existence check the writes above lean on.
 */
function cartLineExists(int $itemId, int $cartId): bool
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT 1 FROM cart_items WHERE id = :id AND cart_id = :cart LIMIT 1'
    );
    $statement->execute(['id' => $itemId, 'cart' => $cartId]);

    return $statement->fetchColumn() !== false;
}

/**
 * Empty the basket, keeping the basket itself.
 *
 * The row stays so the cookie and the account keep pointing at something, which means the next
 * add does not have to create one.
 */
function emptyCart(): bool
{
    $cartId = findCartId();

    if ($cartId === null) {
        return false;
    }

    $statement = getDatabaseConnection()->prepare('DELETE FROM cart_items WHERE cart_id = :cart');
    $statement->execute(['cart' => $cartId]);

    return true;
}

/**
 * Where to send somebody after a basket write, given the page they said they came from.
 *
 * Adding from a product page should leave you on that product page; changing a quantity happens on
 * the basket and belongs back there. So php/cart-update.php carries a `back` field -- and a hidden
 * field is the visitor's to edit, which makes it an open redirect unless it is checked. Handing
 * `back=https://evil.test/login` straight to a Location header is how a phishing page gets to be
 * one this site sent you to.
 *
 * Rather than listing what is forbidden, this allows only one shape: a path that starts with this
 * install's own base path, "/Capstone/". Everything else -- an absolute URL, a mailto:, a
 * javascript:, a bare "evil.test" -- fails that test without having to be thought of in advance.
 *
 * The three checks before it are the cases where that test alone is not enough:
 *
 *  - Backslashes, because browsers quietly read "/\evil.test" as "//evil.test" and leave the site.
 *  - CR and LF, because a Location header holding a newline is header injection -- PHP's header()
 *    blocks it today, but this must not depend on that staying true.
 *  - A leading "//", which is protocol-relative and goes to another host. It starts with a slash,
 *    so on an install served from the site root -- where basePath() is "/" -- the prefix test on
 *    its own would wave it through.
 *
 * The length cap is not security, just sanity: nothing this site links to is 512 characters, and a
 * redirect target that long is somebody probing.
 */
function cartSafeDestination(string $back, string $fallback): string
{
    if ($back === '' || strlen($back) > 512) {
        return $fallback;
    }

    if (strpbrk($back, "\\\r\n\t\0") !== false) {
        return $fallback;
    }

    if (str_starts_with($back, '//')) {
        return $fallback;
    }

    return str_starts_with($back, basePath()) ? $back : $fallback;
}
