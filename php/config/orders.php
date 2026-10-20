<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cart.php';

/*
 * Orders: the second half of Commerce.
 *
 * The basket half ended at a disabled Checkout button. This is what happens when it is pressed --
 * the basket's frozen lines are copied onto an order, the basket is emptied, and the customer is
 * handed a reference they can quote.
 *
 * **Everything here reads money from the basket and never from the form.** A checkout form carries
 * a name, an address, a phone number and a choice of how to pay. It carries no prices, no total
 * and no line ids, because every one of those would be the customer's to edit. placeOrder() reads
 * the basket again inside its own transaction and totals it with the same cartTotal() the basket
 * page printed, so the figure agreed to is the figure stored.
 *
 * Read and write in one file, like php/config/cart.php and for the same reason: the admin's side
 * of an order is status changes, which is the Orders module's, not a second CRUD layer here.
 */


/**
 * How the order reaches the customer.
 *
 * Both, because the user asked for both: a shop that only delivers makes the customer next door
 * type an address, and one that only collects is not an online shop.
 */
const ORDER_FULFILMENTS = ['delivery', 'pickup'];

/**
 * How it is paid for.
 *
 * No card and no gateway, which was the user's decision and is also the only honest one here: a
 * payment provider needs an account, live keys and a public HTTPS address to send its confirmation
 * back to, and this project is XAMPP on a laptop. What a small shop actually takes is a GCash or
 * bank transfer whose reference the customer reads off their own app, or cash when the bouquet
 * arrives -- so that is what this records.
 */
const ORDER_PAYMENT_METHODS = ['cod', 'gcash', 'bank'];

/** A transfer the customer has to reference. 'cod' is the one that has nothing to reference. */
const ORDER_TRANSFER_METHODS = ['gcash', 'bank'];

/**
 * The lifecycle, matching the ENUM in orders.status.
 *
 * Commerce wrote 'placed' and 'cancelled'; the Orders module writes 'packed' and 'delivered'.
 * They were all four named here from the start because the vocabulary of a lifecycle is one
 * decision, and a status spelt differently in two places is how an order goes missing.
 */
const ORDER_STATUSES = ['placed', 'packed', 'delivered', 'cancelled'];

/**
 * The same states in the order an order passes through them. Cancelled is not in it.
 *
 * Two things read this and neither can be written without it. orderTimeline() needs to know
 * which steps are still ahead of an order so it can draw them as empty, and it needs to know
 * when a move went *backwards* so it can say so rather than drawing a second Placed dot with
 * no explanation. Cancelled is left out because it is not a step on the way anywhere -- it is
 * where an order stops, and it can happen from either of the first two positions.
 */
const ORDER_STATUS_SEQUENCE = ['placed', 'packed', 'delivered'];

/** Matching the ENUM in orders.payment_status. */
const ORDER_PAYMENT_STATUSES = ['unpaid', 'submitted', 'paid'];

/*
 * What a customer is shown. Separate from the stored values so the wording can be rewritten
 * without an ALTER, and so the two cannot drift: everything that prints a status prints it
 * through orderStatusLabel().
 */
const ORDER_STATUS_LABELS = [
    'placed' => 'Placed',
    'packed' => 'Being made',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
];

const ORDER_PAYMENT_STATUS_LABELS = [
    'unpaid' => 'Not paid yet',
    'submitted' => 'Waiting for us to check',
    'paid' => 'Paid',
];

/** Longest free-text note a customer can leave. Matches orders.notes. */
const ORDER_NOTES_MAX = 300;

/** Matches orders.payment_reference. */
const ORDER_REFERENCE_MAX = 60;

/**
 * How many orders php/orders.php lists.
 *
 * A cap rather than a pager, unlike the catalogue: fifty orders from one customer of a handicraft
 * shop is already an unusual account, and a pager is a lot of machinery to build for a list nobody
 * has yet. The page says so when it is reached rather than silently showing half.
 */
const ORDER_LIST_MAX = 50;

/**
 * The alphabet the four random characters of a reference come from.
 *
 * No 0/O and no 1/I, because a reference exists to be read down a phone or typed off a screenshot,
 * and those are the two pairs people get wrong.
 */
const ORDER_REFERENCE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

/** How many times placeOrder() will try again after a reference collides. */
const ORDER_REFERENCE_TRIES = 5;


/**
 * The label for a payment method, which depends on how the order is being collected.
 *
 * "Cash on delivery" on something the customer is walking in to collect is wrong in the one way
 * that matters -- it tells them to wait at home.
 */
function paymentMethodLabel(string $method, string $fulfilment = 'delivery'): string
{
    if ($method === 'gcash') {
        return 'GCash';
    }

    if ($method === 'bank') {
        return 'Bank transfer';
    }

    return $fulfilment === 'pickup' ? 'Cash on collection' : 'Cash on delivery';
}

function orderStatusLabel(string $status): string
{
    return ORDER_STATUS_LABELS[$status] ?? 'Placed';
}

function orderPaymentStatusLabel(string $status): string
{
    return ORDER_PAYMENT_STATUS_LABELS[$status] ?? 'Not paid yet';
}

function orderFulfilmentLabel(string $fulfilment): string
{
    return $fulfilment === 'pickup' ? 'Collection' : 'Delivery';
}

/**
 * Does this method need a transfer reference?
 */
function paymentNeedsReference(string $method): bool
{
    return in_array($method, ORDER_TRANSFER_METHODS, true);
}

/**
 * Reduce anything to a known fulfilment, or null.
 *
 * Null rather than a default, unlike normaliseRole(): a role has a safe answer to fall back on and
 * this does not. Quietly turning an unreadable choice into 'delivery' would take an order to an
 * address the customer never meant to give, and turning it into 'pickup' would leave them waiting
 * at home for a bouquet sitting on a shelf. Both are worse than asking again.
 */
function orderFulfilment(mixed $value): ?string
{
    return is_string($value) && in_array($value, ORDER_FULFILMENTS, true) ? $value : null;
}

function orderPaymentMethod(mixed $value): ?string
{
    return is_string($value) && in_array($value, ORDER_PAYMENT_METHODS, true) ? $value : null;
}

/**
 * Tidy a line of typed-in text without changing what it says.
 *
 * Collapses runs of whitespace -- including the newlines a paste out of a messaging app brings
 * with it -- because an address line holding a line break is one that prints wrong on every label
 * afterwards. Not applied to the notes field, which is allowed to have lines in it.
 */
function orderTextLine(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $text = preg_replace('/\s+/u', ' ', (string) $value);

    return trim($text === null ? '' : $text);
}

/**
 * Check a checkout form and hand back what to store, or what to say.
 *
 * Returns ['errors' => list of sentences, 'values' => the columns]. `values` is always present and
 * always complete, even when there are errors, because the page re-renders the form from it -- a
 * checkout that empties the boxes when one field is wrong is a checkout people give up on.
 *
 * $account supplies the two things the form does not ask for: the name to put in the box before it
 * has been typed in, and the email the receipt belongs to. The email is taken from the account and
 * never from the form, because it is the address the shop already knows how to reach and a
 * customer typing a different one is a support problem, not a feature.
 *
 * Lengths are checked with strlen rather than mb_strlen, matching php/profile.php: the columns are
 * sized in characters and this counts bytes, so it is the stricter of the two, and two forms
 * writing one column have to agree.
 *
 * @param array<string, mixed> $input  Usually $_POST.
 * @param array<string, mixed> $account The signed-in user's row.
 * @return array{errors: array<int, string>, values: array<string, string>}
 */
function validateCheckout(array $input, array $account): array
{
    $errors = [];

    $fulfilment = orderFulfilment($input['fulfilment'] ?? null);
    $method = orderPaymentMethod($input['payment_method'] ?? null);

    $values = [
        'fulfilment' => $fulfilment ?? '',
        'payment_method' => $method ?? '',
        'payment_reference' => orderTextLine($input['payment_reference'] ?? ''),
        'contact_name' => orderTextLine($input['contact_name'] ?? ''),
        'contact_phone' => orderTextLine($input['contact_phone'] ?? ''),
        'contact_email' => (string) ($account['email'] ?? ''),
        'address_line' => orderTextLine($input['address_line'] ?? ''),
        'barangay' => orderTextLine($input['barangay'] ?? ''),
        'city' => orderTextLine($input['city'] ?? ''),
        'province' => orderTextLine($input['province'] ?? ''),
        'postcode' => orderTextLine($input['postcode'] ?? ''),
        // The one field allowed to keep its line breaks, so a note can be two sentences on two
        // lines the way the customer wrote it. Still trimmed at the ends.
        'notes' => is_scalar($input['notes'] ?? null) ? trim((string) $input['notes']) : '',
    ];

    if ($fulfilment === null) {
        $errors[] = 'Choose whether we deliver your order or you collect it.';
    }

    if ($method === null) {
        $errors[] = 'Choose how you would like to pay.';
    }

    if ($values['contact_name'] === '' || strlen($values['contact_name']) > 100) {
        $errors[] = 'Enter the name of the person receiving the order, up to 100 characters.';
    }

    /*
     * Deliberately lenient. Landlines, mobiles, a +63 prefix, spaces and dashes are all real ways
     * people write a number down, and a regex that insists on 09XXXXXXXXX turns a customer with a
     * perfectly good number into one who cannot check out. Count the digits and move on.
     */
    $digits = preg_replace('/\D/', '', $values['contact_phone']);
    $digits = $digits === null ? '' : $digits;

    if (strlen($values['contact_phone']) > 30) {
        $errors[] = 'That phone number is too long.';
    } elseif (strlen($digits) < 7 || strlen($digits) > 15) {
        $errors[] = 'Enter a phone number we can reach you on.';
    }

    if ($fulfilment === 'delivery') {
        /*
         * Four required fields and one optional. The postcode is optional on purpose: plenty of
         * people do not know their own, and a required box somebody cannot fill is a checkout
         * they abandon. The courier manages without it.
         */
        if ($values['address_line'] === '' || strlen($values['address_line']) > 160) {
            $errors[] = 'Enter the house or unit number and the street, up to 160 characters.';
        }

        if ($values['barangay'] === '' || strlen($values['barangay']) > 80) {
            $errors[] = 'Enter the barangay.';
        }

        if ($values['city'] === '' || strlen($values['city']) > 80) {
            $errors[] = 'Enter the city or municipality.';
        }

        if ($values['province'] === '' || strlen($values['province']) > 80) {
            $errors[] = 'Enter the province.';
        }

        if (strlen($values['postcode']) > 10) {
            $errors[] = 'That postcode is too long.';
        }
    }

    /*
     * A pickup carries no address at all, and this is what makes that true rather than hoping the
     * form did it. The fields are in the markup and a visitor can fill them in, change their mind,
     * and switch to collection with the boxes still full; without this the order would be stored
     * carrying an address nobody is going to use, and chk_orders_address in the schema would
     * refuse the INSERT with an error written for a database and not a person.
     */
    if ($fulfilment === 'pickup') {
        $values['address_line'] = '';
        $values['barangay'] = '';
        $values['city'] = '';
        $values['province'] = '';
        $values['postcode'] = '';
    }

    if ($method !== null && paymentNeedsReference($method)) {
        $label = paymentMethodLabel($method);

        if ($values['payment_reference'] === '') {
            $errors[] = 'Enter the ' . $label . ' reference number from your receipt.';
        } elseif (strlen($values['payment_reference']) > ORDER_REFERENCE_MAX) {
            $errors[] = 'That ' . $label . ' reference is too long.';
        }
    }

    // Same argument as the address: cash has nothing to reference, and a reference left on a cash
    // order is a number nobody will ever look at sitting where the shop expects to find one.
    if ($method === 'cod') {
        $values['payment_reference'] = '';
    }

    if (strlen($values['notes']) > ORDER_NOTES_MAX) {
        $errors[] = 'Keep any note under ' . ORDER_NOTES_MAX . ' characters.';
    }

    return ['errors' => $errors, 'values' => $values];
}

/**
 * A fresh order reference: SLJ-20260917-K4M9.
 *
 * The date is the shop's local date, so a customer reading it out loud and the shop looking it up
 * agree about which day they are talking about.
 *
 * random_int rather than rand or mt_rand. Not because a reference is a secret -- it is not, and an
 * order is found by its owner's id as well -- but because mt_rand seeded per request has produced
 * the same "random" string twice inside one second before, and a UNIQUE index turns that into a
 * failed checkout rather than a duplicate.
 */
function orderReference(): string
{
    $tail = '';
    $alphabet = ORDER_REFERENCE_ALPHABET;
    $last = strlen($alphabet) - 1;

    for ($i = 0; $i < 4; $i++) {
        $tail .= $alphabet[random_int(0, $last)];
    }

    return 'SLJ-' . date('Ymd') . '-' . $tail;
}

/**
 * Turn the signed-in customer's basket into an order.
 *
 * The only write in this module that a customer can reach, and the only one that has to be all or
 * nothing: an order whose lines are missing is worse than no order, and a basket emptied without
 * an order to show for it is worse than both. So everything below happens in one transaction --
 * the order row, every line, every part of every designed line, and the emptying of the basket.
 *
 * **The basket is emptied, not left standing.** Its lines have been copied, so nothing is lost,
 * and the order page is now the record. A basket left full after checkout invites the customer to
 * press the button again on a page that looks unchanged, and the shop would be left with two
 * identical orders and no way to tell which one was meant.
 *
 * **A withdrawn line refuses the whole order rather than being dropped.** cartTotal() already
 * leaves it out of the figure on the page, so silently dropping it here would charge exactly what
 * was shown -- but it would also post an order that is quietly missing something the customer
 * still had in front of them, and they would find out when it arrived. php/cart.php already asks
 * them to remove it, and this says the same thing in the same words.
 *
 * The carts row is locked before anything is read. Two taps on Place order -- a double submit, or
 * a phone retrying a request it thought had timed out -- would otherwise both read the same full
 * basket and both write an order. With the lock the second waits, then finds the basket empty and
 * is told so.
 *
 * @param array<string, string> $values A validated set from validateCheckout().
 * @return array{id: int, reference: string, total: string}
 * @throws RuntimeException with a sentence written for a customer.
 */
function placeOrder(int $userId, array $values): array
{
    $database = getDatabaseConnection();

    $cartId = findCartId();

    if ($cartId === null) {
        throw new RuntimeException('Your basket is empty.');
    }

    $database->beginTransaction();

    try {
        /*
         * The lock. One row, held until this transaction ends, so a second checkout of the same
         * basket queues behind it rather than racing it. Locking the lines instead would mean a
         * LEFT JOIN to products under FOR UPDATE, which would hold the catalogue rows too and
         * block an admin editing an unrelated product for as long as a checkout takes.
         */
        $lock = $database->prepare('SELECT id FROM carts WHERE id = :cart FOR UPDATE');
        $lock->execute(['cart' => $cartId]);

        if ($lock->fetch() === false) {
            throw new RuntimeException('Your basket is empty.');
        }

        // Re-read rather than trusting anything the page was holding. This is the basket as it is
        // now, inside the lock, and it is what the order is made of.
        $lines = fetchCartLines();

        if ($lines === []) {
            throw new RuntimeException('Your basket is empty.');
        }

        $gone = 0;

        foreach ($lines as $line) {
            if ($line['orderable'] === false) {
                $gone++;
            }
        }

        if ($gone > 0) {
            throw new RuntimeException(
                $gone === 1
                    ? 'One item in your basket is no longer on sale. Remove it and try again.'
                    : $gone . ' items in your basket are no longer on sale. Remove them and try again.'
            );
        }

        $total = cartTotal($lines);

        $orderId = insertOrderRow($database, $userId, $values, $total);

        $insertItem = $database->prepare(
            'INSERT INTO order_items
                 (order_id, kind, product_id, name, unit_price, quantity, line_total, arrangement)
             VALUES (:order, :kind, :product, :name, :price, :quantity, :line_total, :arrangement)'
        );

        $insertPart = $database->prepare(
            'INSERT INTO order_item_parts
                 (item_id, component_id, kind, name, colour_hex, each_price, quantity, sort_order)
             VALUES (:item, :component, :kind, :name, :colour, :each, :quantity, :sort)'
        );

        foreach ($lines as $line) {
            $insertItem->execute([
                'order' => $orderId,
                'kind' => $line['kind'],
                'product' => $line['productId'],
                'name' => $line['name'],
                'price' => $line['unitPrice'],
                'quantity' => $line['quantity'],
                // The same helper the basket page printed with, so the stored figure and the one
                // the customer agreed to come out of one piece of arithmetic.
                'line_total' => $line['lineTotal'],
                'arrangement' => $line['arrangement'],
            ]);

            $itemId = (int) $database->lastInsertId();
            $sort = 0;

            foreach ($line['parts'] as $part) {
                $insertPart->execute([
                    'item' => $itemId,
                    'component' => $part['componentId'],
                    'kind' => $part['kind'],
                    'name' => $part['name'],
                    'colour' => $part['colour'],
                    'each' => $part['each'],
                    'quantity' => $part['quantity'],
                    'sort' => $sort++,
                ]);
            }
        }

        // Emptied, not deleted: the row stays so the account keeps pointing at something and the
        // next add does not have to create one. Exactly what emptyCart() does, written out here
        // because it has to run on this connection inside this transaction.
        $database->prepare('DELETE FROM cart_items WHERE cart_id = :cart')
            ->execute(['cart' => $cartId]);

        $reference = $database->prepare('SELECT reference FROM orders WHERE id = :id');
        $reference->execute(['id' => $orderId]);
        $referenceValue = (string) $reference->fetchColumn();

        $database->commit();

        return ['id' => $orderId, 'reference' => $referenceValue, 'total' => $total];
    } catch (Throwable $exception) {
        /*
         * Roll back whatever got as far as the database, then let the original exception carry on
         * to the caller -- a RuntimeException is a sentence for the customer, a PDOException is
         * for the log. Wrapping one in the other here would lose which it was.
         *
         * The guard matters: a failed COMMIT ends the transaction by itself, and rollBack() on a
         * connection with nothing open throws an exception of its own that would replace this one.
         */
        if ($database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }
}

/**
 * Insert the order row, retrying if its reference has already been used today.
 *
 * Split out of placeOrder() because the retry needs its own loop and burying a loop in the middle
 * of a forty-line transaction is how the transaction stops being readable.
 *
 * 23000 is the SQLSTATE class for an integrity constraint -- here, uq_orders_reference. MariaDB
 * does not abort a transaction over a failed statement the way some databases do, so trying again
 * on the same open transaction is safe and everything inserted before this point is still there.
 * Only the unique clash is retried; any other 23000 (a broken CHECK, a missing user) is raised,
 * because retrying it would fail identically five times and then report the wrong cause.
 *
 * @param array<string, string> $values
 */
function insertOrderRow(PDO $database, int $userId, array $values, string $total): int
{
    $statement = $database->prepare(
        'INSERT INTO orders
             (reference, user_id, payment_method, payment_status, payment_reference, fulfilment,
              contact_name, contact_phone, contact_email,
              address_line, barangay, city, province, postcode, notes, total)
         VALUES
             (:reference, :user, :method, :payment_status, :payment_reference, :fulfilment,
              :contact_name, :contact_phone, :contact_email,
              :address_line, :barangay, :city, :province, :postcode, :notes, :total)'
    );

    /*
     * A transfer arrives 'submitted' and cash arrives 'unpaid', and neither arrives 'paid'.
     * Nothing a customer types can settle a payment: the only thing that can is somebody at the
     * shop looking at the receiving account and agreeing the money is there.
     */
    $paymentStatus = paymentNeedsReference($values['payment_method']) ? 'submitted' : 'unpaid';

    for ($try = 1; ; $try++) {
        try {
            $statement->execute([
                'reference' => orderReference(),
                'user' => $userId,
                'method' => $values['payment_method'],
                'payment_status' => $paymentStatus,
                'payment_reference' => $values['payment_reference'],
                'fulfilment' => $values['fulfilment'],
                'contact_name' => $values['contact_name'],
                'contact_phone' => $values['contact_phone'],
                'contact_email' => $values['contact_email'],
                'address_line' => $values['address_line'],
                'barangay' => $values['barangay'],
                'city' => $values['city'],
                'province' => $values['province'],
                'postcode' => $values['postcode'],
                'notes' => $values['notes'],
                'total' => $total,
            ]);

            return (int) $database->lastInsertId();
        } catch (PDOException $exception) {
            $clash = $exception->getCode() === '23000'
                && str_contains($exception->getMessage(), 'uq_orders_reference');

            if (!$clash || $try >= ORDER_REFERENCE_TRIES) {
                throw $exception;
            }
        }
    }
}

/**
 * One order and everything on it, for the customer who placed it.
 *
 * Scoped by user_id in the WHERE rather than fetched and then checked, which is the rule
 * readSavedDesign() set: somebody else's reference comes back null, and null is also what a
 * reference that never existed comes back as. The page cannot then tell a stranger which
 * references are real.
 *
 * Three queries and not one: an order, its lines, and the parts of its designed lines. Joining
 * them would multiply the order row by its lines and each line by its parts, and the totals would
 * have to be un-multiplied afterwards.
 *
 * @return array<string, mixed>|null
 */
function fetchOrderForUser(int $userId, string $reference): ?array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT * FROM orders WHERE reference = :reference AND user_id = :user LIMIT 1'
    );
    $statement->execute(['reference' => $reference, 'user' => $userId]);

    $order = $statement->fetch();

    return $order === false ? null : hydrateOrder($order);
}

/**
 * The same order, for an admin, who is allowed to read one they did not place.
 *
 * A separate function rather than a nullable $userId argument: an ownership check that can be
 * switched off by passing null is one that will eventually be switched off by accident. Every
 * caller of this one has been past requireAdmin().
 *
 * @return array<string, mixed>|null
 */
function fetchOrderForAdmin(string $reference): ?array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT * FROM orders WHERE reference = :reference LIMIT 1'
    );
    $statement->execute(['reference' => $reference]);

    $order = $statement->fetch();

    return $order === false ? null : hydrateOrder($order);
}

/**
 * Attach the lines and their parts to an order row, and normalise its columns.
 *
 * @param array<string, mixed> $order
 * @return array<string, mixed>
 */
function hydrateOrder(array $order): array
{
    $orderId = (int) $order['id'];

    $statement = getDatabaseConnection()->prepare(
        'SELECT id, kind, product_id, name, unit_price, quantity, line_total, arrangement
           FROM order_items WHERE order_id = :order ORDER BY id ASC'
    );
    $statement->execute(['order' => $orderId]);
    $rows = $statement->fetchAll();

    $partsFor = fetchOrderParts(array_map(static fn (array $row): int => (int) $row['id'], $rows));

    $lines = [];
    $itemCount = 0;

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $quantity = (int) $row['quantity'];
        $itemCount += $quantity;

        $lines[] = [
            'id' => $id,
            'kind' => (string) $row['kind'],
            'productId' => $row['product_id'] === null ? null : (int) $row['product_id'],
            'name' => (string) $row['name'],
            'unitPrice' => (string) $row['unit_price'],
            'quantity' => $quantity,
            'lineTotal' => (string) $row['line_total'],
            'arrangement' => (string) $row['arrangement'],
            'parts' => $partsFor[$id] ?? [],
        ];
    }

    return [
        'id' => $orderId,
        'reference' => (string) $order['reference'],
        'userId' => (int) $order['user_id'],
        'status' => (string) $order['status'],
        'paymentStatus' => (string) $order['payment_status'],
        'paymentMethod' => (string) $order['payment_method'],
        'paymentReference' => (string) $order['payment_reference'],
        'fulfilment' => (string) $order['fulfilment'],
        'contactName' => (string) $order['contact_name'],
        'contactPhone' => (string) $order['contact_phone'],
        'contactEmail' => (string) $order['contact_email'],
        'addressLine' => (string) $order['address_line'],
        'barangay' => (string) $order['barangay'],
        'city' => (string) $order['city'],
        'province' => (string) $order['province'],
        'postcode' => (string) $order['postcode'],
        'notes' => (string) $order['notes'],
        'total' => (string) $order['total'],
        'placedAt' => (string) $order['placed_at'],
        'lines' => $lines,
        'itemCount' => $itemCount,
    ];
}

/**
 * The frozen parts of every designed line on an order, keyed by line id.
 *
 * fetchCartParts() with one table name changed, and kept separate for the reason the two tables
 * are separate: a basket line's parts can still change and an order's cannot, so a helper that
 * read either would be one the next person could point at the wrong one.
 *
 * @param array<int, int> $itemIds
 * @return array<int, array<int, array<string, mixed>>>
 */
function fetchOrderParts(array $itemIds): array
{
    if ($itemIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

    $statement = getDatabaseConnection()->prepare(
        "SELECT item_id, component_id, kind, name, colour_hex, each_price, quantity
           FROM order_item_parts
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
 * A customer's orders, newest first, without their lines.
 *
 * The list page shows a reference, a date, a state and a total, so reading every line of every
 * order to draw it would be the whole history to print a summary of it. The counts come from one
 * grouped join instead.
 *
 * Every selected column of `orders` is named in the GROUP BY rather than relying on the primary
 * key to stand in for them. MariaDB's ONLY_FULL_GROUP_BY does not do the functional-dependency
 * reasoning MySQL 8 does, and this XAMPP's sql_mode having it switched off today is not a thing to
 * write a query against -- the whole reason the basket enforces its own caps in PHP.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchOrdersForUser(int $userId): array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT o.id, o.reference, o.status, o.payment_status, o.payment_method, o.fulfilment,
                o.total, o.placed_at,
                COUNT(i.id) AS line_count,
                COALESCE(SUM(i.quantity), 0) AS item_count
           FROM orders o
           LEFT JOIN order_items i ON i.order_id = o.id
          WHERE o.user_id = :user
          GROUP BY o.id, o.reference, o.status, o.payment_status, o.payment_method, o.fulfilment,
                   o.total, o.placed_at
          ORDER BY o.placed_at DESC, o.id DESC
          LIMIT ' . ORDER_LIST_MAX
    );
    $statement->execute(['user' => $userId]);

    $orders = [];

    foreach ($statement->fetchAll() as $row) {
        $orders[] = [
            'id' => (int) $row['id'],
            'reference' => (string) $row['reference'],
            'status' => (string) $row['status'],
            'paymentStatus' => (string) $row['payment_status'],
            'paymentMethod' => (string) $row['payment_method'],
            'fulfilment' => (string) $row['fulfilment'],
            'total' => (string) $row['total'],
            'placedAt' => (string) $row['placed_at'],
            'lineCount' => (int) $row['line_count'],
            'itemCount' => (int) $row['item_count'],
        ];
    }

    return $orders;
}

/**
 * How many orders this account has, for the dashboard card.
 *
 * A count rather than counting what fetchOrdersForUser() returned, because that is capped at
 * ORDER_LIST_MAX and a card saying "50 orders" to somebody with sixty would be wrong in the one
 * direction nobody checks.
 */
function countOrdersForUser(int $userId): int
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT COUNT(*) FROM orders WHERE user_id = :user'
    );
    $statement->execute(['user' => $userId]);

    return (int) $statement->fetchColumn();
}

/**
 * May the customer still call this off themselves?
 *
 * While it is only placed, and while the shop has not agreed the money arrived. After either of
 * those a cancellation is a conversation -- something is half made, or there is a refund to
 * arrange -- and a button that quietly cancels a paid order would be a refund the shop never
 * heard about.
 *
 * @param array<string, mixed> $order
 */
function orderCanBeCancelled(array $order): bool
{
    return (string) $order['status'] === 'placed' && (string) $order['paymentStatus'] !== 'paid';
}

/**
 * Cancel one of this customer's own orders.
 *
 * Scoped by user_id in the UPDATE and guarded by the same rule orderCanBeCancelled() prints with,
 * written out in SQL rather than read-then-write: between a read and a write the shop could have
 * marked it paid, and the check has to be in the statement that changes the row.
 *
 * The lines are left exactly as they are. An order is a record of what was agreed, and a
 * cancelled one is a record of something that was agreed and then called off -- emptying it would
 * leave a row that cannot answer what it was for.
 *
 * Since tracking landed it also writes the cancellation into order_events with actor 'customer'.
 * That is the one state both sides can reach, so which side reached it is worth recording: the
 * shop opening this order later needs to know they did not cancel it themselves.
 */
function cancelOrderForUser(int $userId, string $reference): bool
{
    $database = getDatabaseConnection();

    /*
     * The UPDATE and its history row in one transaction, added with tracking on 2026-09-22.
     *
     * An order that moved with nothing in its history is worse than an order that did not move:
     * the customer sees "Cancelled" with no date beside it and the shop cannot answer when, or
     * who did it. So either both happen or neither does.
     */
    $database->beginTransaction();

    try {
        $statement = $database->prepare(
            "UPDATE orders
                SET status = 'cancelled'
              WHERE reference = :reference
                AND user_id = :user
                AND status = 'placed'
                AND payment_status <> 'paid'"
        );
        $statement->execute(['reference' => $reference, 'user' => $userId]);

        $cancelled = $statement->rowCount() === 1;

        if ($cancelled) {
            recordOrderEvent($reference, 'cancelled', 'customer', $userId);
        }

        $database->commit();

        return $cancelled;
    } catch (Throwable $exception) {
        // The guard matters for the reason placeOrder() gives: a failed COMMIT ends the
        // transaction by itself, and rollBack() on a connection that is no longer in one throws
        // a second exception over the top of the first.
        if ($database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }
}

/**
 * Write one line of an order's history.
 *
 * $status is the state the order arrived *at*, matching orders.status and the ENUM on
 * order_events. $actor is 'customer' or 'shop'; $userId is the account that did it, which is
 * NULL only for something no account was behind.
 *
 * INSERT ... SELECT rather than looking the id up first, for the reason every write in this
 * module is written that way: two statements where one will do is a gap something can change
 * in, and it means no caller has to carry an id around when the whole module deals in
 * references. A reference that matches nothing inserts nothing -- no error, no row -- which is
 * the same answer every other statement here gives a reference that is not there.
 *
 * Each named placeholder appears exactly once. That is not a style choice: the connection runs
 * with ATTR_EMULATE_PREPARES => false, and a name reused twice in one statement throws
 * SQLSTATE[HY093] at execute() time.
 *
 * Returns nothing. Every caller has already decided whether the move happened, from the
 * rowCount() of the UPDATE it is pairing this with, and a second boolean to check would only
 * invite checking the wrong one.
 */
function recordOrderEvent(string $reference, string $status, string $actor, ?int $userId): void
{
    $statement = getDatabaseConnection()->prepare(
        'INSERT INTO order_events (order_id, status, actor, actor_user_id)
         SELECT id, :status, :actor, :actor_user
           FROM orders
          WHERE reference = :reference'
    );
    $statement->execute([
        'status' => $status,
        'actor' => $actor,
        'actor_user' => $userId,
        'reference' => $reference,
    ]);
}

/**
 * One order's history, oldest first.
 *
 * Ordered by id and not by happened_at, which is also why the index is (order_id, id): a
 * TIMESTAMP has one-second resolution, two clicks can land inside one second, and sorting a tie
 * by a column that cannot break it puts the moves in whichever order the storage engine feels
 * like. The id is monotonic, so it always can.
 *
 * Takes the numeric id rather than a reference, unlike the write. The read only ever happens on
 * a page that has already loaded the order -- hydrateOrder() hands back its 'id' -- so asking
 * for a reference here would mean looking the same row up twice to render one page.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchOrderEvents(int $orderId): array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT status, actor, actor_user_id, happened_at
           FROM order_events
          WHERE order_id = :order
          ORDER BY id ASC'
    );
    $statement->execute(['order' => $orderId]);

    return $statement->fetchAll();
}

/**
 * The order's history as a list of dots to draw, oldest first.
 *
 * Both the customer's order page and the admin's render this, from the same function, because a
 * timeline that told the two of them different stories about the same order would be worse than
 * having none.
 *
 * **It is a list, not a fixed four-step ladder**, and that is the one real decision in here. An
 * order can move backwards -- the shop can tick "Not started after all" on a row they opened by
 * mistake -- and a ladder has nowhere to put that. So every event gets its own dot in the order
 * it happened, and a move back down ORDER_STATUS_SEQUENCE is labelled as one rather than drawn
 * as a mysterious second "Placed". The alternative was deleting the superseded row, which is a
 * history table that edits history.
 *
 * The first dot is always placement, read from the order's own placed_at rather than from a
 * row: nothing is stored for it, so orders that existed before this table did still draw a
 * correct timeline, and a derived dot cannot disagree with the column it came from.
 *
 * Steps still ahead are appended as 'todo' -- an order being made shows an empty Delivered dot,
 * because saying what is still coming is half of what a customer opens this page for. Nothing is
 * appended once an order is delivered or cancelled: in both cases nothing further is coming, and
 * a hollow "Delivered" under a cancellation would read as a promise.
 *
 * Pure: it is handed the order and its events and touches no database, which is what lets
 * php/tools/orders-unit.php check every shape of history without one.
 *
 * Each dot carries its actor, which only the admin's page draws. A cancellation the customer
 * made and one the shop made are the same word in the status column and are not the same
 * conversation, and the shop is the side that needs to tell them apart. The derived first dot is
 * the customer's by definition -- an order exists because somebody checked out -- and a step that
 * has not happened has nobody to name, so it carries an empty string rather than a guess.
 *
 * @param array<string, mixed> $order      As hydrateOrder() returns it.
 * @param array<int, array<string, mixed>> $events As fetchOrderEvents() returns it.
 * @return array<int, array{label: string, at: string, state: string, status: string, actor: string}>
 */
function orderTimeline(array $order, array $events): array
{
    $status = (string) $order['status'];

    $dots = [[
        'label' => orderStatusLabel('placed'),
        'at' => (string) $order['placedAt'],
        'state' => 'done',
        'status' => 'placed',
        'actor' => 'customer',
    ]];

    $previous = 'placed';

    foreach ($events as $event) {
        $reached = (string) $event['status'];

        $dots[] = [
            'label' => orderEventLabel($reached, $previous),
            'at' => (string) $event['happened_at'],
            'state' => 'done',
            'status' => $reached,
            'actor' => (string) $event['actor'],
        ];

        $previous = $reached;
    }

    // The last thing that actually happened is where the order is now. Marked separately from
    // the ones before it so the page can draw "here" differently from "been and gone".
    $dots[count($dots) - 1]['state'] = 'now';

    if ($status === 'cancelled' || $status === 'delivered') {
        return $dots;
    }

    /*
     * What is still to come. Measured from the order's own status rather than from the last
     * event, so a row whose status was changed directly in the database -- or by a future
     * feature that does not write events -- still shows the right steps ahead.
     */
    $position = array_search($status, ORDER_STATUS_SEQUENCE, true);
    $ahead = $position === false ? [] : array_slice(ORDER_STATUS_SEQUENCE, $position + 1);

    foreach ($ahead as $step) {
        $dots[] = [
            'label' => orderStatusLabel($step),
            'at' => '',
            'state' => 'todo',
            'status' => $step,
            'actor' => '',
        ];
    }

    return $dots;
}

/**
 * What one dot on the timeline is called.
 *
 * Forward moves and cancellations use the status label everything else on the site uses, so the
 * dot and the pill above it say the same word. A move back down the sequence is the exception
 * and needs its own wording: "Placed" appearing twice with no explanation looks like a bug in
 * the page rather than a correction in the shop.
 */
function orderEventLabel(string $reached, string $previous): string
{
    $to = array_search($reached, ORDER_STATUS_SEQUENCE, true);
    $from = array_search($previous, ORDER_STATUS_SEQUENCE, true);

    if ($to !== false && $from !== false && $to < $from) {
        return 'Moved back to ' . orderStatusLabel($reached);
    }

    return orderStatusLabel($reached);
}

/**
 * A reference as it arrives from a URL or a form, reduced to the shape one can have.
 *
 * Upper-cased, because a reference is read off a screen and typed back in, and somebody typing
 * slj-20260917-k4m9 means the same order. Anything that is not the right shape comes back as an
 * empty string, which every caller treats as "no such order" -- so a probe with 500 characters in
 * it is answered by the same "we could not find that" as a typo.
 */
function orderReferenceFromInput(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $reference = strtoupper(trim((string) $value));

    return preg_match('/^SLJ-\d{8}-[' . ORDER_REFERENCE_ALPHABET . ']{4}$/', $reference) === 1
        ? $reference
        : '';
}

/**
 * A stored timestamp as a person reads it: 17 September 2026, 1:09pm.
 *
 * Returns the raw string if it cannot be parsed, rather than an empty cell or today's date -- a
 * date that failed to parse should look wrong, not look plausible.
 */
function orderDate(string $timestamp): string
{
    $parsed = strtotime($timestamp);

    return $parsed === false ? $timestamp : date('j F Y, g:ia', $parsed);
}

/**
 * The same timestamp with the month abbreviated: 17 Sep 2026, 1:09pm.
 *
 * For the admin queue, where the date shares a row with eight other columns. Spelled out it was
 * the widest column on the page at 215px -- more than the reference and the customer's name put
 * together -- for a value nobody reads word by word. Everywhere a date has a column to itself it
 * stays spelled out, which is why this is a second function rather than a change to the first.
 *
 * Same fallback, and for the same reason: a timestamp that will not parse comes back untouched.
 */
function orderDateShort(string $timestamp): string
{
    $parsed = strtotime($timestamp);

    return $parsed === false ? $timestamp : date('j M Y, g:ia', $parsed);
}
