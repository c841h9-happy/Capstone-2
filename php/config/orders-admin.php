<?php

require_once __DIR__ . '/orders.php';
// For notifyOrderEvent() and the four ORDER_MAIL_* events. Required here rather than in
// config/orders.php because only the shop's side sends anything: a page that merely reads a
// customer's order should not drag the mailer in behind it.
require_once __DIR__ . '/orders-mail.php';
/*
 * For consumeOrderStock() and returnOrderStock(): making an order takes its parts out of the
 * drawer, and undoing that puts them back.
 *
 * The edge points this way and only this way. inventory.php requires components-admin.php and
 * nothing from orders, so there is no cycle -- which is also why Stage 1 could be written and
 * tested with the order screens untouched. And only php/admin/orders.php and php/admin/order.php
 * require this file, so nothing customer-facing grows an include out of it.
 */
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/materials.php';

/*
 * Orders from the shop's side.
 *
 * Separate from config/orders.php for the reason products-admin.php is separate from
 * products.php: that file scopes every statement by user_id, so forgetting the owner check is
 * impossible in it, and a $userId argument that may be null is an ownership check somebody will
 * eventually pass null to. Everything in here has been past requireAdmin().
 *
 * **What this file is, and what it deliberately is not.** Commerce owns payment, and a payment
 * nobody can confirm is not a payment: a customer who types a GCash reference has done their half,
 * and the other half is a person opening their own GCash app and agreeing the money arrived. That
 * is markOrderPaid(), and it is why this file exists at all.
 *
 * Moving an order through 'packed' and 'delivered' **is** here as of 2026-09-22, and was not
 * before it. This comment used to say so: order management, tracking and notifications were
 * ROADMAP.md's for the Orders module, and the two columns already held those states while nothing
 * wrote them. The Orders module is what appended the management section further down -- so the
 * module that picked them up did inherit working vocabulary rather than a decision, which was the
 * point of leaving them named.
 *
 * The split inside this file is therefore by owner and not by kind: everything down to
 * cancelOrderAsAdmin() is Commerce's money, everything below the management banner is Orders'
 * states, and applyAdminOrderAction() at the bottom is where both meet a button.
 *
 * Cancelling is here, though, and it belongs with payment rather than with management: a shop that
 * can agree money arrived must be able to refuse an order it cannot fill, and once an order is paid
 * the customer's own Cancel button stops working by design -- so after that point the shop is the
 * only one who can call it off at all.
 */

const ADMIN_ORDERS_PER_PAGE = 20;

/*
 * The state filter, and the one place 'open' means anything.
 *
 * It is not a column value: it is 'placed' or 'packed', the two states an order is still work.
 * It is also the default, which is the difference from the products list -- a catalogue is a thing
 * you browse, and an order list is a queue you work through. Somebody opening this page wants
 * today's orders, not every order the shop has ever taken.
 */
const ADMIN_ORDER_STATUS_FILTERS = ['open', 'all', 'placed', 'packed', 'delivered', 'cancelled'];
const ADMIN_ORDER_STATUS_DEFAULT = 'open';
const ADMIN_ORDER_OPEN_STATUSES = ['placed', 'packed'];

const ADMIN_ORDER_PAYMENT_FILTERS = ['all', 'unpaid', 'submitted', 'paid'];
const ADMIN_ORDER_PAYMENT_DEFAULT = 'all';

const ADMIN_ORDER_SEARCH_MAX = 100;

/**
 * The state filter as a known value, or 'open'.
 *
 * in_array against the list rather than a filter helper that returns '' for junk, matching
 * adminProductStatus(): '' is not a state, and a filter that can hold one is a WHERE clause
 * waiting to be written by accident.
 */
function adminOrderStatusFilter(mixed $value): string
{
    return is_string($value) && in_array($value, ADMIN_ORDER_STATUS_FILTERS, true)
        ? $value
        : ADMIN_ORDER_STATUS_DEFAULT;
}

/**
 * The payment filter as a known value, or 'all'.
 */
function adminOrderPaymentFilter(mixed $value): string
{
    return is_string($value) && in_array($value, ADMIN_ORDER_PAYMENT_FILTERS, true)
        ? $value
        : ADMIN_ORDER_PAYMENT_DEFAULT;
}

/**
 * The search box, reduced to something that can go in a LIKE.
 *
 * is_string first: ?q[]=x makes $_GET['q'] an array, and trim() on an array is a fatal.
 */
function adminOrderSearch(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    $search = trim($value);

    return mb_strlen($search) > ADMIN_ORDER_SEARCH_MAX
        ? mb_substr($search, 0, ADMIN_ORDER_SEARCH_MAX)
        : $search;
}

/**
 * The WHERE clause and its parameters, built once and used by both the count and the fetch.
 *
 * Shared deliberately. Two copies is how a list ends up saying "12 orders" above eleven rows.
 *
 * @param array<string, mixed> $filters
 * @return array{sql: string, params: array<string, string>}
 */
function adminOrderFilterSql(array $filters): array
{
    $where = [];
    $params = [];

    $status = adminOrderStatusFilter($filters['status'] ?? null);

    if ($status === 'open') {
        // Named placeholders rather than an interpolated IN list, even though both values are
        // constants in this file today. A list built by string concatenation is the shape that
        // gets extended with a variable later.
        $where[] = 'o.status IN (:status_open_a, :status_open_b)';
        $params['status_open_a'] = ADMIN_ORDER_OPEN_STATUSES[0];
        $params['status_open_b'] = ADMIN_ORDER_OPEN_STATUSES[1];
    } elseif ($status !== 'all') {
        $where[] = 'o.status = :status';
        $params['status'] = $status;
    }

    $payment = adminOrderPaymentFilter($filters['payment'] ?? null);

    if ($payment !== 'all') {
        $where[] = 'o.payment_status = :payment_status';
        $params['payment_status'] = $payment;
    }

    $search = adminOrderSearch($filters['search'] ?? null);

    if ($search !== '') {
        /*
         * Four columns, because there are four things an admin has in front of them when they come
         * looking: the reference off an email, the name or the phone number of whoever rang up, and
         * the payment reference off their own GCash app. That last one is the whole reason this box
         * exists -- matching a transfer to an order is the job.
         *
         * Same escaping as productFilterSql(): % and _ are wildcards inside a LIKE, so a search for
         * 100% must not match everything, and the escape character is stated rather than assumed.
         *
         * **Four placeholder names for one value, not one name used four times.** That file gets
         * away with `:search` because it searches a single column. With ATTR_EMULATE_PREPARES set
         * to false -- see config/database.php -- the statement goes to MariaDB as real placeholders,
         * and a named one repeated across the query is answered with SQLSTATE[HY093] "Invalid
         * parameter number" rather than with rows. It is the same trap as binding a LIMIT, and it
         * fails the same quiet way: the exception is caught by the page, which then reports that
         * the database is unavailable when in truth somebody typed in a search box.
         */
        $escaped = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

        $columns = [
            'search_reference' => 'o.reference',
            'search_name' => 'o.contact_name',
            'search_phone' => 'o.contact_phone',
            'search_payment' => 'o.payment_reference',
        ];

        $likes = [];

        foreach ($columns as $name => $column) {
            $likes[] = $column . ' LIKE :' . $name . " ESCAPE '!'";
            $params[$name] = $escaped;
        }

        $where[] = '(' . implode(' OR ', $likes) . ')';
    }

    return [
        'sql' => $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
        'params' => $params,
    ];
}

/**
 * One page of orders, newest first, without their lines.
 *
 * No sort option, unlike the products list. An order queue has one useful order and it is the one
 * things happened in; a catalogue has a price and a name to sort by because browsing has no
 * inherent order.
 *
 * Every selected column of `orders` is named in the GROUP BY. MariaDB does not do MySQL 8's
 * functional-dependency reasoning, and this XAMPP's sql_mode happening to leave ONLY_FULL_GROUP_BY
 * off today is not a thing to write a query against.
 *
 * LIMIT and OFFSET are interpolated from ints rather than bound, because they arrive as ints from
 * the caller and ATTR_EMULATE_PREPARES => false sends a bound value as a string, which MySQL will
 * not take in a LIMIT.
 *
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function adminFetchOrders(array $filters = [], int $limit = ADMIN_ORDERS_PER_PAGE, int $offset = 0): array
{
    $filter = adminOrderFilterSql($filters);

    $limit = max(1, $limit);
    $offset = max(0, $offset);

    $statement = getDatabaseConnection()->prepare(
        'SELECT o.id, o.reference, o.status, o.payment_status, o.payment_method, o.payment_reference,
                o.fulfilment, o.contact_name, o.contact_phone, o.total, o.placed_at,
                COUNT(i.id) AS line_count,
                COALESCE(SUM(i.quantity), 0) AS item_count
           FROM orders o
           LEFT JOIN order_items i ON i.order_id = o.id'
        . $filter['sql'] .
        ' GROUP BY o.id, o.reference, o.status, o.payment_status, o.payment_method, o.payment_reference,
                   o.fulfilment, o.contact_name, o.contact_phone, o.total, o.placed_at
          ORDER BY o.placed_at DESC, o.id DESC
          LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $statement->execute($filter['params']);

    $orders = [];

    foreach ($statement->fetchAll() as $row) {
        $orders[] = [
            'id' => (int) $row['id'],
            'reference' => (string) $row['reference'],
            'status' => (string) $row['status'],
            'paymentStatus' => (string) $row['payment_status'],
            'paymentMethod' => (string) $row['payment_method'],
            'paymentReference' => (string) $row['payment_reference'],
            'fulfilment' => (string) $row['fulfilment'],
            'contactName' => (string) $row['contact_name'],
            'contactPhone' => (string) $row['contact_phone'],
            'total' => (string) $row['total'],
            'placedAt' => (string) $row['placed_at'],
            'lineCount' => (int) $row['line_count'],
            'itemCount' => (int) $row['item_count'],
        ];
    }

    return $orders;
}

/**
 * How many orders match the filters, ignoring paging.
 *
 * No join and no GROUP BY: counting orders is counting rows of `orders`, and joining the lines to
 * count them would need a COUNT(DISTINCT) to undo the join it just made.
 *
 * @param array<string, mixed> $filters
 */
function adminCountOrders(array $filters = []): int
{
    $filter = adminOrderFilterSql($filters);

    $statement = getDatabaseConnection()->prepare('SELECT COUNT(*) FROM orders o' . $filter['sql']);
    $statement->execute($filter['params']);

    return (int) $statement->fetchColumn();
}

/**
 * What the shop has taken, for the dashboard.
 *
 * Cancelled orders are left out: a cancelled order is a record of something that did not happen,
 * and a figure that includes it is not money anybody has. Everything else counts, paid or not --
 * this is what has been agreed, not what has cleared.
 */
function adminOrdersTotal(): string
{
    // prepare() and execute() even with nothing to bind, because every other read in this project
    // is written that way and a lone query() is the one somebody later adds a variable to.
    $statement = getDatabaseConnection()->prepare(
        "SELECT COALESCE(SUM(total), 0) FROM orders WHERE status <> 'cancelled'"
    );
    $statement->execute();

    // SUM of a DECIMAL comes back a string already; number_format is what guarantees the shape
    // formatPrice() expects whatever the driver decided to hand back.
    return number_format((float) (string) $statement->fetchColumn(), 2, '.', '');
}

/**
 * Agree that the money for an order arrived.
 *
 * The rule is in the UPDATE rather than read-then-write, like cancelOrderForUser(): between a read
 * and a write the customer could have cancelled, and a tick that lands on a cancelled order is the
 * shop recording money against something it is not going to make.
 *
 * A cancelled order is refused for that reason. An already-paid one is refused too, so a double
 * submit or a refreshed POST reports "that was already paid" rather than claiming to have done
 * something twice -- rowCount() is what says which, and PDO's MySQL driver reports rows *changed*,
 * so an UPDATE that sets paid on a paid row would come back 0 regardless. The condition being
 * explicit is what makes that 0 mean something.
 */
function markOrderPaid(string $reference): bool
{
    $statement = getDatabaseConnection()->prepare(
        "UPDATE orders
            SET payment_status = 'paid'
          WHERE reference = :reference
            AND payment_status <> 'paid'
            AND status <> 'cancelled'"
    );
    $statement->execute(['reference' => $reference]);

    return $statement->rowCount() === 1;
}

/**
 * Take that back.
 *
 * A Mark paid with no undo is a trap rather than a feature: one mis-click would record money that
 * never arrived, and because cancelOrderForUser() refuses a paid order, it would also silently take
 * away the customer's own Cancel button. So this exists, and it can only ever undo -- the WHERE
 * names 'paid', so it cannot be aimed at an order that was never ticked.
 *
 * It puts the row back where it would have been rather than blanking it to 'unpaid': somebody who
 * typed a GCash reference has still done their half, and their order is still waiting for us to
 * check. The CASE is in the statement for the same reason the conditions are -- deciding it in PHP
 * would mean reading the method first, and the method could change underneath the decision.
 */
function markOrderUnpaid(string $reference): bool
{
    $statement = getDatabaseConnection()->prepare(
        "UPDATE orders
            SET payment_status = CASE WHEN payment_method = 'cod' THEN 'unpaid' ELSE 'submitted' END
          WHERE reference = :reference
            AND payment_status = 'paid'"
    );
    $statement->execute(['reference' => $reference]);

    return $statement->rowCount() === 1;
}

/**
 * Call an order off from the shop's side.
 *
 * Wider than cancelOrderForUser() in one way and narrower in another, and both differences are
 * deliberate. Wider: a paid order may be cancelled here, because after payment the customer's own
 * button is gone by design and somebody has to be able to act on the phone call that follows. The
 * refund is a conversation, not a column -- nothing here touches money, and the order keeps its
 * payment state so the record still says what was taken.
 *
 * Narrower: a delivered order may not be. That is not a cancellation, it is a return, and a shop
 * that can rewrite a finished order's history cannot answer what it actually sent out.
 *
 * The lines are left standing, exactly as the customer's own cancel leaves them.
 *
 * Since tracking landed it writes the cancellation into order_events with actor 'shop', in one
 * transaction with the UPDATE. 'cancelled' is the one state both sides can reach, so which side
 * reached it is the fact worth keeping: the shop opening this order in a month needs to be able
 * to tell their own decision from the customer's.
 */
function cancelOrderAsAdmin(string $reference): bool
{
    $database = getDatabaseConnection();

    /*
     * Join the caller's transaction rather than opening a second one. Same three lines
     * recordStockMove() carries, and here for the same reason: cancelOrderAndReturn() has to
     * put the order's parts back inside the transaction that cancels it, and PDO throws on a
     * nested beginTransaction(). Called on its own -- which is every other caller -- $ours is
     * true and this behaves exactly as it did before.
     */
    $ours = !$database->inTransaction();

    if ($ours) {
        $database->beginTransaction();
    }

    try {
        $statement = $database->prepare(
            "UPDATE orders
                SET status = 'cancelled'
              WHERE reference = :reference
                AND status <> 'cancelled'
                AND status <> 'delivered'"
        );
        $statement->execute(['reference' => $reference]);

        $cancelled = $statement->rowCount() === 1;

        if ($cancelled) {
            $adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            recordOrderEvent($reference, 'cancelled', 'shop', $adminId);
        }

        if ($ours) {
            $database->commit();
        }

        return $cancelled;
    } catch (Throwable $exception) {
        if ($ours && $database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }
}

/*
 * ===================================================================
 *   Order management: moving an order through being made and out
 * ===================================================================
 *
 * The Orders module, 2026-09-22. Everything above this line is Commerce's -- money and the
 * refusal of an order -- and the header comment on this file said for two days that the two
 * states below belonged to somebody else. This is that somebody.
 *
 * All three are built exactly like markOrderPaid(): the rule lives in the UPDATE's WHERE and
 * never in a read taken beforehand, and success is rowCount() === 1. That is not caution for its
 * own sake here. PDO's MySQL driver reports rows *changed*, so an UPDATE setting 'packed' on a
 * row that is already packed comes back 0 whether it was refused or was simply a no-op -- the
 * condition being spelt out in the statement is the only thing that makes that 0 mean "no".
 *
 * Each pairs its UPDATE with a row in order_events, inside one transaction, for the reason
 * cancelOrderForUser() now does the same: an order that moved with nothing in its history shows
 * the customer a state with no date beside it, and leaves the shop unable to answer when.
 *
 * None of them sends the customer anything. That is applyAdminOrderAction()'s job, said once at
 * the bottom of this file -- see the argument there.
 */

/**
 * Start making an order: placed -> packed.
 *
 * Only from 'placed'. A cancelled order cannot be started, and neither can a delivered one,
 * both of which fall out of naming the one state this is allowed to move from rather than
 * listing the three it is not.
 */
function markOrderPacked(string $reference): bool
{
    return moveOrderStatus($reference, 'packed', 'placed');
}

/**
 * The undo: packed -> placed.
 *
 * Here for the same reason markOrderUnpaid() is -- a one-way button is a trap. The likeliest
 * mistake on the admin queue is pressing Start on the row above or below the one meant, and
 * without this the only fix is the database.
 *
 * It only ever undoes: the WHERE names 'packed', so it cannot be aimed at an order that was
 * never started. **There is deliberately no undo from 'delivered'**, which was the user's call
 * and matches what cancelOrderAsAdmin() already refuses, for the reason written there: a shop
 * that can rewrite a finished order's history cannot answer what it actually sent out.
 *
 * The superseded 'packed' event is left standing. This writes a new row saying the order came
 * back to 'placed', and orderTimeline() labels it "Moved back to Placed". Deleting the row it
 * undoes would be a history table that edits history.
 */
function markOrderUnpacked(string $reference): bool
{
    return moveOrderStatus($reference, 'placed', 'packed');
}

/**
 * Finish an order: packed -> delivered.
 *
 * From 'packed' only, not straight from 'placed'. Two clicks rather than one, and both of them
 * correspond to a real moment in making something by hand -- but the reason it is worth the
 * extra click is that 'delivered' is the one state with no way back, and this puts it behind a
 * state that has one. A shop that finds it fussy can add `|| $status === 'placed'` to 'deliver'
 * in adminOrderActions() and 'placed' to the WHERE here; nothing else would need to change.
 *
 * "Delivered" covers collection too. The order knows which it is and everything that prints this
 * state -- the pills, the timeline, the email -- says "collected" for a pickup.
 */
function markOrderDelivered(string $reference): bool
{
    return moveOrderStatus($reference, 'delivered', 'packed');
}

/**
 * The one statement behind the three above: move an order from one state to exactly one other.
 *
 * Written once because the three differ only in two words, and three copies of a transaction
 * wrapped around an UPDATE and an INSERT are three places for the transaction to be got wrong.
 * Private by convention -- nothing outside this file should be able to name an arbitrary pair of
 * states, which is the whole safety of the three named functions above.
 *
 * $from is a single state and not a list on purpose. Every legal move in this module has exactly
 * one origin, and a function that accepted several would make it possible to write one that did
 * not -- 'delivered' reachable from anywhere, which is the mistake this module is shaped to
 * prevent.
 *
 * actor 'shop' and the signed-in admin's id. currentUserRole() reads the session, so this reads
 * $_SESSION directly rather than taking an argument: every caller has already been past
 * requireAdmin(), and a $userId parameter is one somebody would eventually pass the customer's.
 */
function moveOrderStatus(string $reference, string $to, string $from): bool
{
    $database = getDatabaseConnection();

    /*
     * Join the caller's transaction rather than opening a second one.
     *
     * Inventory Stage 2 is why: packOrderAndConsume() opens a transaction, calls this, and takes
     * the order's parts out of stock inside it, so that an order cannot end up saying Being made
     * with nothing out of the drawer, or the reverse. PDO throws outright on a nested
     * beginTransaction(), so joining is the only way that works -- and it is the same three lines
     * recordStockMove() and saveProductParts() already carry.
     *
     * Called on its own, $ours is true and every line below runs exactly as it did before. That
     * matters more here than anywhere else in the project: this is the function the orders suites
     * assert hardest against.
     */
    $ours = !$database->inTransaction();

    if ($ours) {
        $database->beginTransaction();
    }

    try {
        $statement = $database->prepare(
            'UPDATE orders
                SET status = :to
              WHERE reference = :reference
                AND status = :from'
        );
        $statement->execute(['to' => $to, 'reference' => $reference, 'from' => $from]);

        $moved = $statement->rowCount() === 1;

        if ($moved) {
            $adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            recordOrderEvent($reference, $to, 'shop', $adminId);
        }

        if ($ours) {
            $database->commit();
        }

        return $moved;
    } catch (Throwable $exception) {
        // The guard is the one placeOrder() explains: a failed COMMIT has already ended the
        // transaction, and rollBack() then throws over the top of the exception worth seeing.
        // Only ever roll back what this call started -- the caller's own catch owns the rest.
        if ($ours && $database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }
}

/*
 * ===================================================================
 *   The three moves that also move stock
 * ===================================================================
 *
 * Inventory Stage 2, 2026-09-29. Above this point nothing in this file had ever heard of stock;
 * these three are the whole join between the two modules, and applyAdminOrderAction() calls them
 * instead of the plain markOrder* functions for exactly three of its six actions.
 *
 * **Why they are wrappers and not edits.** markOrderPacked(), markOrderUnpacked() and
 * cancelOrderAsAdmin() keep their signatures and their bodies untouched, so the rule about which
 * state may move where stays in the WHERE clause where it was written, and the 1,221 existing
 * checks that drive them keep driving the same code. The stock is a second thing that happens in
 * the same transaction, not a change to the first thing.
 *
 * **Why the transaction is here.** An order that says Being made with nothing out of the drawer,
 * or parts out of the drawer for an order still sitting at Placed, are both worse than either
 * failure on its own -- the first makes the stock figures quietly wrong and the second makes them
 * wrong in the direction that stops the shop making something it could. So the status row, its
 * order_events row, and every stock_moves row commit together or not at all. That is the whole
 * reason moveOrderStatus() and cancelOrderAsAdmin() learned to join a transaction instead of
 * opening one.
 *
 * **Why a shortfall still commits.** "Warn the shop, never block the customer" was the user's
 * call, so going short is not a failure and does not roll anything back: the parts come out, the
 * balance goes negative, the ledger says so, and the toast says so. What rolls back is a thrown
 * query -- a real fault, not a shop that has run out of wire.
 */

/**
 * Start making an order and take its parts out of stock: placed -> packed.
 *
 * @return array{moved: bool, stock: array<string, mixed>}
 */
function packOrderAndConsume(string $reference, int $orderId): array
{
    return moveOrderAndMoveStock($reference, $orderId, 'pack');
}

/**
 * Undo that and put the parts back: packed -> placed.
 *
 * @return array{moved: bool, stock: array<string, mixed>}
 */
function unpackOrderAndReturn(string $reference, int $orderId): array
{
    return moveOrderAndMoveStock($reference, $orderId, 'unpack');
}

/**
 * Call the order off and put back whatever was taken for it.
 *
 * There is no "was it packed?" argument, and there was one in the plan. It is not needed and
 * would have been worse than not having it: returnOrderStock() reads the ledger rather than the
 * recipe, so for an order that was never started there is nothing out and it writes nothing at
 * all. A boolean read off the status column beforehand would be a second opinion about a fact
 * stock_moves already holds -- the same reason this stage has no stock_consumed flag on `orders`.
 *
 * It also makes the awkward case right for free. An order packed, moved back to Placed, packed
 * again and then cancelled has four ledger rows saying what is currently out; a status read says
 * only where it ended up.
 *
 * @return array{moved: bool, stock: array<string, mixed>}
 */
function cancelOrderAndReturn(string $reference, int $orderId): array
{
    return moveOrderAndMoveStock($reference, $orderId, 'cancel');
}

/**
 * The one transaction behind the three above.
 *
 * Written once for the reason moveOrderStatus() is written once, said in its docblock: the three
 * differ by one function name, and three copies of the same transaction are three places to get
 * the transaction wrong. Private by convention.
 *
 * $action is a closed set of three, checked by a match with no default -- an unknown value is a
 * typo in this file rather than anything a request can carry, and UnhandledMatchError is the
 * right answer to it. That is not the same freedom moveOrderStatus() refuses to take: the states
 * an order may move between stay pinned inside the three named functions this calls.
 *
 * The stock only moves when the status did. A refused move -- a stale page, a double submit, a
 * row somebody else changed first -- must not take anything out of the drawer, and rowCount() is
 * what says which happened.
 *
 * @return array{moved: bool, stock: array<string, mixed>}
 */
function moveOrderAndMoveStock(string $reference, int $orderId, string $action): array
{
    $database = getDatabaseConnection();

    // Joined rather than opened, like everything else that writes here. Nothing calls this inside
    // a transaction today; the pattern is what stops the day somebody does being a fatal.
    $ours = !$database->inTransaction();

    if ($ours) {
        $database->beginTransaction();
    }

    try {
        $moved = match ($action) {
            'pack' => markOrderPacked($reference),
            'unpack' => markOrderUnpacked($reference),
            'cancel' => cancelOrderAsAdmin($reference),
        };

        $report = emptyStockReport();

        if ($moved) {
            $report = $action === 'pack'
                ? consumeOrderStock($orderId)
                : returnOrderStock($orderId);
        }

        if ($ours) {
            $database->commit();
        }

        return ['moved' => $moved, 'stock' => $report];
    } catch (Throwable $exception) {
        if ($ours && $database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }
}

/**
 * What the shop can still do to this order, as six booleans.
 *
 * The page draws its buttons from this and the actions check the same conditions in SQL, so a
 * stale page cannot do something the current row refuses -- it just gets told no. Written once
 * here rather than twice as markup conditions, which is how the two drift.
 *
 * Three of the six arrived with the Orders module. 'pack' and 'deliver' are the two states this
 * file spent two days saying were somebody else's; 'unpack' is the undo, and the reason there is
 * no matching undo from 'delivered' is written on markOrderUnpacked().
 *
 * Nothing can be started or delivered once it is cancelled, which is not tested for separately:
 * both are pinned to the one status they may move from, and 'cancelled' is not it.
 *
 * @param array<string, mixed> $order
 * @return array{pay: bool, unpay: bool, cancel: bool, pack: bool, unpack: bool, deliver: bool}
 */
function adminOrderActions(array $order): array
{
    $status = (string) $order['status'];
    $paymentStatus = (string) $order['paymentStatus'];

    return [
        'pay' => $paymentStatus !== 'paid' && $status !== 'cancelled',
        'unpay' => $paymentStatus === 'paid',
        'cancel' => $status !== 'cancelled' && $status !== 'delivered',
        'pack' => $status === 'placed',
        'unpack' => $status === 'packed',
        'deliver' => $status === 'packed',
    ];
}

/**
 * The customer behind an order, for the admin's own page.
 *
 * The order froze its own contact details at checkout and those are what the shop should act on --
 * an address changed afterwards is not where this parcel was going. This is the account it was
 * placed from, which is a different question and the one worth asking when something needs
 * chasing up. NULL back means the account has been deleted since; the order survives, because
 * fk_orders_user is ON DELETE RESTRICT, so the page has to be able to say so.
 *
 * @return array<string, mixed>|null
 */
function adminOrderCustomer(int $userId): ?array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT id, name, email, role FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $userId]);

    $account = $statement->fetch();

    return $account === false ? null : $account;
}

/**
 * Do one of the six things, and say what happened.
 *
 * Four moves and two undos: mark paid, start making, mark delivered, cancel -- and back out of
 * either "paid" or "being made". An action that is none of the six is answered rather than
 * ignored, which is what the last line of the function is for.
 *
 * Both admin screens offer the same buttons -- the queue and the single order -- so the branch
 * lives here rather than twice in markup that would drift. It returns the message instead of
 * flashing it, for two reasons: this file stays out of the session, and each page gets to decide
 * where it redirects to afterwards. The queue goes back to the queue with its filters on; the
 * order page goes back to the order.
 *
 * A PDOException is left to the caller. Both pages already have a try around their reads with the
 * one honest thing to say about a database that is down.
 *
 * **Every message to the customer is sent from here**, and nowhere else. The five write functions
 * above do one thing each and tell nobody; this is the single place both admin screens funnel
 * through, so it is the only place where the queue and the order page cannot end up disagreeing
 * about who gets told what. Four of the six actions send word -- being started, being delivered,
 * being cancelled by the shop, and the payment clearing -- which is what the user picked on
 * 2026-09-22. The two undos do not: they are corrections to a mistake the shop made, and mailing
 * somebody to retract a message they only just received is worse than the silence.
 *
 * A message that fails to send does **not** turn the result into an error. The row has already
 * moved by then, so an error would be a lie about what happened; the type stays 'success' and the
 * wording says the customer could not be written to. That matters more than it looks: the whole
 * point of the notification is that the shop no longer has to tell people by hand, so a shop that
 * believes a customer was told when they were not is the one failure worth being loud about.
 *
 * **Every stock movement is started from here too**, as of Inventory Stage 2. Three of the six
 * actions go through the wrappers above instead of the plain markOrder* functions -- start making
 * takes the parts out, and both ways back out of "being made" put them back. Delivered and the
 * two payment actions move no stock at all: by the time an order is delivered its parts left the
 * drawer days ago, and money has nothing to do with wire.
 *
 * What the toast says about it is counts only, never a list. stockReportSentence() writes that
 * sentence, and the detail belongs on the order page -- a flash naming eight materials is a flash
 * nobody reads, and it disappears on the next click besides.
 *
 * @return array{type: string, message: string}
 */
function applyAdminOrderAction(string $action, mixed $referenceInput): array
{
    $reference = orderReferenceFromInput($referenceInput);

    // A malformed reference cannot match a row, so it never reaches a statement.
    $order = $reference === '' ? null : fetchOrderForAdmin($reference);

    if ($order === null) {
        return ['type' => 'error', 'message' => 'That order is not there. The list is up to date.'];
    }

    $allowed = adminOrderActions($order);

    /*
     * Refused before the statement only so the message can name the rule. The statements check
     * their own conditions regardless -- between this read and the write the row can move, which is
     * what the "changed while you were looking at it" answer below is for. A refusal here is a
     * stale page or a request made by hand; the button is not drawn in either case.
     */
    $changed = ['type' => 'error', 'message' => 'Order ' . $reference . ' changed while you were looking at it. Check where it has got to.'];

    if ($action === 'pay') {
        if (!$allowed['pay']) {
            return ['type' => 'error', 'message' => $order['paymentStatus'] === 'paid'
                ? 'Order ' . $reference . ' was already marked paid.'
                : 'Order ' . $reference . ' is cancelled, so it cannot be marked paid.'];
        }

        return markOrderPaid($reference)
            ? orderActionDone($order, ORDER_MAIL_PAID, 'Order ' . $reference . ' is marked paid.')
            : $changed;
    }

    if ($action === 'unpay') {
        if (!$allowed['unpay']) {
            return ['type' => 'error', 'message' => 'Order ' . $reference . ' is not marked paid, so there is nothing to take back.'];
        }

        return markOrderUnpaid($reference)
            ? ['type' => 'success', 'message' => 'Order ' . $reference . ' is no longer marked paid.']
            : $changed;
    }

    if ($action === 'cancel') {
        if (!$allowed['cancel']) {
            return ['type' => 'error', 'message' => $order['status'] === 'cancelled'
                ? 'Order ' . $reference . ' was already cancelled.'
                : 'Order ' . $reference . ' has been delivered, so it cannot be cancelled now.'];
        }

        /*
         * Whatever was out of the drawer for this order goes back, which for an order that was
         * never started is nothing at all -- returnOrderStock() reads the ledger, so it does not
         * need to be told which of those two this is.
         */
        $cancelled = cancelOrderAndReturn($reference, (int) $order['id']);

        if (!$cancelled['moved']) {
            return $changed;
        }

        $message = 'Order ' . $reference . ' is cancelled. Any refund is yours to arrange.';
        $stock = stockReportSentence($cancelled['stock'], false);

        if ($stock !== '') {
            $message .= ' ' . $stock;
        }

        return orderActionDone($order, ORDER_MAIL_CANCELLED, $message);
    }

    if ($action === 'pack') {
        if (!$allowed['pack']) {
            return ['type' => 'error', 'message' => match ($order['status']) {
                'packed' => 'Order ' . $reference . ' is already being made.',
                'delivered' => 'Order ' . $reference . ' has already been delivered.',
                default => 'Order ' . $reference . ' is cancelled, so there is nothing to start.',
            }];
        }

        /*
         * The one moment stock comes out, which was the user's decision of 2026-09-26: not at
         * checkout, where the shop has not yet agreed to make anything, and not at delivery, by
         * which time the parts have been off the shelf for days. "Start making" is the moment
         * somebody actually picks the wire up.
         *
         * It can send the balance negative and it still succeeds -- warn, never block. What the
         * shop gets is a sentence on the toast and a table on the order page.
         */
        $packed = packOrderAndConsume($reference, (int) $order['id']);

        if (!$packed['moved']) {
            return $changed;
        }

        $message = 'Order ' . $reference . ' is being made.';
        $stock = stockReportSentence($packed['stock'], true);

        if ($stock !== '') {
            $message .= ' ' . $stock;
        }

        return orderActionDone($order, ORDER_MAIL_PACKED, $message);
    }

    if ($action === 'deliver') {
        if (!$allowed['deliver']) {
            /*
             * 'placed' gets its own wording rather than falling in with the rest, because it is
             * the one refusal here that is not a mistake -- it is somebody trying to skip a step,
             * and the answer they need is which button to press instead.
             */
            return ['type' => 'error', 'message' => match ($order['status']) {
                'placed' => 'Order ' . $reference . ' has not been started yet. Mark it as being made first.',
                'delivered' => 'Order ' . $reference . ' was already marked delivered.',
                default => 'Order ' . $reference . ' is cancelled, so it cannot be delivered.',
            }];
        }

        return markOrderDelivered($reference)
            ? orderActionDone($order, ORDER_MAIL_DELIVERED, 'Order ' . $reference . ' is marked '
                . ($order['fulfilment'] === 'pickup' ? 'collected' : 'delivered') . '. That is the last step.')
            : $changed;
    }

    if ($action === 'unpack') {
        if (!$allowed['unpack']) {
            return ['type' => 'error', 'message' => $order['status'] === 'delivered'
                ? 'Order ' . $reference . ' has been delivered. A finished order cannot be moved back.'
                : 'Order ' . $reference . ' is not being made, so there is nothing to undo.'];
        }

        $unpacked = unpackOrderAndReturn($reference, (int) $order['id']);

        if (!$unpacked['moved']) {
            return $changed;
        }

        /*
         * The stock sentence goes in the middle, before the note about the email. The two facts
         * the shop needs are what happened to the parts and whether the customer was told, and
         * the second of those is the aside.
         */
        $message = 'Order ' . $reference . ' is back to Placed.';
        $stock = stockReportSentence($unpacked['stock'], false);

        if ($stock !== '') {
            $message .= ' ' . $stock;
        }

        // The one action with no message to the customer and a note saying so. A shop undoing a
        // mis-click needs to know whether the person was already told.
        return ['type' => 'success', 'message' => $message . ' The customer has not been written to.'];
    }

    return ['type' => 'error', 'message' => 'That action is not something this page can do.'];
}

/**
 * A write that worked, plus the attempt to tell the customer about it.
 *
 * The four actions that send word all end here, so the wording of "and they were told" -- and of
 * "and they were not" -- is written once. The type is 'success' either way: the row moved, and
 * reporting an error for something that happened is the one answer that is definitely wrong.
 *
 * $order is the row as it was read *before* the write, which is fine for everything this uses it
 * for: the address, the name, the reference and the total are not what the write changed. Re-
 * reading it to build a message would be a second query for no new fact.
 *
 * @param array<string, mixed> $order
 * @return array{type: string, message: string}
 */
function orderActionDone(array $order, string $event, string $message): array
{
    if (notifyOrderEvent($order, $event)) {
        return ['type' => 'success', 'message' => $message . ' The customer has been emailed.'];
    }

    return [
        'type' => 'success',
        'message' => $message . ' The customer could NOT be emailed -- tell them yourself.',
    ];
}
