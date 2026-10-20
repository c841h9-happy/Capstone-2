<?php

declare(strict_types=1);

/*
 * components-admin.php rather than database.php on its own, and it is the same argument
 * that file makes for requiring products-admin.php: what is needed is genuinely shared
 * rather than copied.
 *
 * From it: adminFetchComponent() and adminFetchProduct(), which are how a write here checks
 * that the thing it is about to move stock for still exists; componentStatus() and
 * componentKindFilter(), so the stock list filters exactly as the parts list does; and the
 * database connection, which it already brings in.
 *
 * It does not pull in anything this file has to work around. The two never write the same
 * column: components-admin.php owns name, price and is_active, and this file owns
 * stock_on_hand and nothing else on that table.
 */
require_once __DIR__ . '/components-admin.php';
require_once __DIR__ . '/materials.php';

/**
 * Inventory: what the shop physically has, what each product is made of, and every
 * movement of stock, ever.
 *
 * One file rather than an inventory.php / inventory-admin.php pair. That split exists
 * elsewhere because a customer-facing read must not be able to see a withdrawn row, and
 * nothing in here is customer-facing at all -- the same reason orders-admin.php stands
 * alone. If a shop-front page ever wants to say "only two left", it gets a narrow read
 * added to components.php, not this whole surface.
 *
 * Three things are worth knowing before reading anything below.
 *
 * 1. recordStockMove() is the ONLY writer of product_components.stock_on_hand anywhere in
 *    the project -- the same single-writer rule setComponentImage() has for image_path.
 *    It moves the balance and writes the ledger row in one transaction, which is what makes
 *    the two agree. Nothing else in this file, or outside it, issues an UPDATE naming that
 *    column.
 *
 * 2. A shortfall warns the shop and never blocks the customer. That decision is why
 *    stock_on_hand is a signed INT and why no function here refuses a movement for taking
 *    the count below zero. A negative balance is the books saying out loud that they
 *    disagree with the drawer, which is the most useful thing a stock system ever says.
 *
 * 3. Both stages are in. Stage 1 is everything down to saveProductParts() -- counting stock,
 *    recording why it moved, and what a bouquet is made of. Stage 2 is the section at the foot
 *    of this file, 2026-09-29: an order being made takes its parts out of the drawer, and
 *    un-starting or cancelling it puts them back.
 *
 *    The 'used' and 'returned' reasons are written by consumeOrderStock() and
 *    returnOrderStock(), which php/config/orders-admin.php calls from inside its own
 *    transaction. That is why every write in this file accepts an order id and copes with
 *    being called inside a transaction somebody else opened -- it was built for this, and the
 *    wiring needed no migration and no change to placeOrder() when it came.
 *
 *    Nothing customer-facing calls anything here, and that has not changed. Stock moves from
 *    the shop's side only: from the stock screen, or from one of the three order buttons.
 */

/**
 * How many parts the stock list shows at once. Matches the components and products lists,
 * because it is the same list with different columns.
 */
const ADMIN_STOCK_PER_PAGE = 20;

/**
 * And how many movements the ledger shows.
 *
 * Deliberately larger. A list of parts is browsed -- you are looking for one row and then
 * you stop -- whereas a ledger is scanned: "when did the rose heads last come in" is
 * answered by running an eye down a column, and doing that twenty rows at a time means
 * pressing Next every few seconds.
 */
const ADMIN_STOCK_MOVES_PER_PAGE = 40;

/** Mirrors stock_moves.note, which is VARCHAR(255). */
const STOCK_NOTE_MAX = 255;

/**
 * The largest count or movement a form will take.
 *
 * stock_on_hand is INT and would hold two billion; product_parts.quantity is SMALLINT
 * UNSIGNED and would hold 65,535. Neither number is a drawer. Six digits is more chenille
 * stems than this shop will ever hold at once, and three is more of one part than any
 * bouquet has ever been built from -- and both limits mean a fat-fingered "1200" instead of
 * "12" is caught by a sentence rather than by the arithmetic quietly working.
 */
const STOCK_COUNT_MAX = 999999;
const PART_QUANTITY_MAX = 999;

/**
 * Why stock moved, and what to call it on screen.
 *
 * The keys are exactly the stock_moves.reason ENUM in sql/inventory-upgrade.sql, in the
 * same order, so a reason the database accepts and one this file knows about cannot drift
 * apart.
 */
const STOCK_REASONS = [
    'opening' => 'Opening count',
    'received' => 'Received',
    'used' => 'Used making an order',
    'returned' => 'Returned to stock',
    'adjusted' => 'Stock-take',
    'wasted' => 'Spoiled or lost',
];

/**
 * The three an admin may choose, and the only three the stock screen offers as buttons.
 *
 * 'opening' is written once per part by the seed or by the first stock-take and would mean
 * nothing pressed a second time. 'used' and 'returned' belong to an order: letting somebody
 * type one by hand would put a movement in the ledger that claims an order caused it while
 * order_id sits empty, which is worse than no record at all.
 *
 * recordStockMove() itself accepts all six. The restriction is at the screen, because the
 * screen is where a person is choosing.
 */
const STOCK_REASONS_ADMIN = ['received', 'adjusted', 'wasted'];

/**
 * The orderings the stock list offers.
 *
 * As with COMPONENT_SORTS, ORDER BY cannot be bound, so what arrives in the query string is
 * only ever a key into this array, and every entry ends in an id tie-breaker so that page 2
 * cannot repeat a row from page 1.
 *
 * The default sorts by kind first, and that is free: kind is an ENUM, so MySQL orders it by
 * the order the values were declared in -- bloom, wire, wrap, accent -- which is the order
 * somebody assembles a bouquet in rather than alphabetical.
 */
const STOCK_SORTS = [
    'order' => 'c.kind ASC, c.sort_order ASC, c.id ASC',
    'low' => 'c.stock_on_hand ASC, c.id ASC',
    'high' => 'c.stock_on_hand DESC, c.id DESC',
    'name' => 'c.name ASC, c.id ASC',
];

const STOCK_SORT_DEFAULT = 'order';

/**
 * The ledger's two orderings.
 *
 * By id and not by created_at, which matters more here than anywhere else in the project: a
 * stock-take writes several rows inside one transaction and they all share a TIMESTAMP to
 * the second. Ordering those by created_at puts them in an arbitrary order, and a ledger
 * whose balance_after column reads 40, 28, 34 looks broken even though every row is right.
 * id is the order they were written in, which is the order they happened in.
 */
const STOCK_MOVE_SORTS = [
    'newest' => 'm.id DESC',
    'oldest' => 'm.id ASC',
];

const STOCK_MOVE_SORT_DEFAULT = 'newest';

/**
 * Where each kind of part sits in a recipe's reading order.
 *
 * A part's own sort_order only orders it within its kind -- every kind starts again at 10 --
 * so laying the four bands a hundred apart turns four independent picker orders into one
 * sequence that reads the way a bouquet is actually built: blooms, then stems, then the
 * wrap, then the tie.
 *
 * The same four numbers are in sql/inventory-seed.sql. They have to match, or a seeded
 * recipe and an edited one would read in different orders on the same screen.
 */
const PART_KIND_SORT_BAND = ['bloom' => 0, 'wire' => 100, 'wrap' => 200, 'accent' => 300];

/**
 * The sort key to actually use for the stock list.
 */
function stockSortKey(mixed $sort): string
{
    return is_string($sort) && isset(STOCK_SORTS[$sort]) ? $sort : STOCK_SORT_DEFAULT;
}

/**
 * The sort key to actually use for the ledger.
 */
function stockMoveSortKey(mixed $sort): string
{
    return is_string($sort) && isset(STOCK_MOVE_SORTS[$sort]) ? $sort : STOCK_MOVE_SORT_DEFAULT;
}

/**
 * A reason to filter the ledger by, or '' for all of them.
 *
 * Returns '' for junk, which means "no filter" to a query -- the same shape as
 * componentKindFilter(), and safe for the same reason: this value only ever reaches a
 * WHERE clause. A write must never reduce a reason this way. See recordStockMove(), which
 * refuses an unknown reason outright rather than defaulting one.
 */
function stockReasonFilter(mixed $reason): string
{
    return is_string($reason) && isset(STOCK_REASONS[$reason]) ? $reason : '';
}

/**
 * What to call a reason on screen.
 *
 * Falls back to the stored value rather than to a guess, so a reason added to the ENUM
 * without being added here shows up as itself instead of silently reading as something else.
 */
function stockReasonLabel(string $reason): string
{
    return STOCK_REASONS[$reason] ?? $reason;
}

/**
 * Is this part at or below the level the shop asked to be warned at?
 *
 * A threshold of 0 means "never warn about this part", so it is not low however little of
 * it there is -- that is the accent nobody minds running out of. Written once here because
 * the list query, the row template and the banner all have to agree about what "low" means.
 *
 * @param array<string, mixed> $component
 */
function isLowStock(array $component): bool
{
    $threshold = (int) ($component['low_stock_threshold'] ?? 0);

    return $threshold > 0 && (int) ($component['stock_on_hand'] ?? 0) <= $threshold;
}

/**
 * Move a part's stock, and write down that it moved.
 *
 * The only writer of product_components.stock_on_hand in the project. Both halves happen in
 * one transaction, so there is no moment at which the balance has changed and the ledger
 * does not say why.
 *
 * $delta is signed: +40 received, -12 used, -4 wasted. Zero is refused, because a movement
 * of nothing is not a movement -- and because chk_stock_moves_delta would refuse it anyway,
 * as SQLSTATE 23000, which is a poor way for an admin to find out they left the box empty.
 *
 * A delta that takes the balance below zero is allowed on purpose and is not a failure. See
 * the note at the top of this file.
 *
 * $options takes:
 *   note    -- free text for the ledger, up to STOCK_NOTE_MAX.
 *   orderId -- which order caused it. Passed by consumeOrderStock() and returnOrderStock(),
 *              and by nothing else: a movement somebody makes on the stock screen is not
 *              about an order.
 *   userId  -- who did it. Omit it and the signed-in admin is used; pass null explicitly
 *              for a movement no person is responsible for, such as a seeded opening count.
 *
 * On being called inside a transaction somebody else opened, this joins it rather than
 * starting a second one -- PDO would throw on the nested beginTransaction(), and
 * packOrderAndConsume() takes stock out inside the same transaction as the status change so
 * that a failed status change cannot leave the parts consumed. In that case a refusal is
 * returned without a rollback: the caller owns the transaction and it is the caller's
 * decision whether one bad line should abandon the rest -- and consumeOrderStock() decides
 * that it should not, because warning the shop beats refusing the order.
 *
 * @param array<string, mixed> $options
 * @return array{ok: bool, error: string, balance: int|null, moveId: int, changed: bool}
 */
function recordStockMove(int $componentId, int $delta, string $reason, array $options = []): array
{
    $failure = static fn (string $message): array => [
        'ok' => false,
        'error' => $message,
        'balance' => null,
        'moveId' => 0,
        'changed' => false,
    ];

    if ($componentId <= 0) {
        return $failure('Choose a part to move stock for.');
    }

    // isset against the map, not a filter helper that returns a default: an unknown reason
    // has to be a refusal. Quietly storing it as 'adjusted' would put a fact in the ledger
    // that nobody wrote down.
    if (!isset(STOCK_REASONS[$reason])) {
        return $failure('That is not a reason stock can move for.');
    }

    if ($delta === 0) {
        return $failure('Enter how many. A movement of nothing is not a movement.');
    }

    if ($delta > STOCK_COUNT_MAX || $delta < -STOCK_COUNT_MAX) {
        return $failure('That is more than ' . number_format(STOCK_COUNT_MAX) . ' at once. Split it up if it is really that many.');
    }

    // mb_strlen, not strlen: VARCHAR(255) in utf8mb4 counts characters, so 255 accented
    // ones fit the column even though they are 510 bytes.
    $note = trim((string) ($options['note'] ?? ''));

    if (mb_strlen($note) > STOCK_NOTE_MAX) {
        return $failure('The note has to be ' . STOCK_NOTE_MAX . ' characters or fewer.');
    }

    $orderId = isset($options['orderId']) ? (int) $options['orderId'] : null;

    if ($orderId !== null && $orderId <= 0) {
        $orderId = null;
    }

    /*
     * Who did it.
     *
     * array_key_exists rather than isset, because passing null explicitly has to mean
     * something different from not passing it at all: null is "no person did this", which
     * is what the seed's opening rows are, and isset() cannot tell those two apart.
     *
     * Reading $_SESSION here rather than taking a required argument is the call
     * moveOrderStatus() already made and explains: every caller has been past
     * requireAdmin(), and a $userId parameter is one somebody eventually passes the wrong
     * value to.
     */
    if (array_key_exists('userId', $options)) {
        $userId = $options['userId'] === null ? null : (int) $options['userId'];
    } else {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    if ($userId !== null && $userId <= 0) {
        $userId = null;
    }

    $database = getDatabaseConnection();
    $ours = !$database->inTransaction();

    if ($ours) {
        $database->beginTransaction();
    }

    try {
        /*
         * FOR UPDATE, and this is the line that makes the module correct.
         *
         * Two admins receiving stock at the same instant both read 40, both work out 50,
         * and both write it -- ten stems vanish, and the ledger has two rows that each look
         * right. Locking the row here means the second request waits for the first to
         * commit and then reads 50, so the two add up. The balance is computed from what
         * this SELECT returned and never from what the page was showing.
         */
        $statement = $database->prepare(
            'SELECT id, name, stock_on_hand
               FROM product_components
              WHERE id = :id
              FOR UPDATE'
        );
        $statement->execute(['id' => $componentId]);

        $component = $statement->fetch();

        if ($component === false) {
            if ($ours) {
                $database->rollBack();
            }

            return $failure('That part no longer exists.');
        }

        $balance = (int) $component['stock_on_hand'] + $delta;

        $update = $database->prepare('UPDATE product_components SET stock_on_hand = :balance WHERE id = :id');
        $update->bindValue(':balance', $balance, PDO::PARAM_INT);
        $update->bindValue(':id', $componentId, PDO::PARAM_INT);
        $update->execute();

        $insert = $database->prepare(
            'INSERT INTO stock_moves (component_id, component_name, reason, delta, balance_after, order_id, note, user_id)
             VALUES (:component_id, :component_name, :reason, :delta, :balance_after, :order_id, :note, :user_id)'
        );
        $insert->bindValue(':component_id', $componentId, PDO::PARAM_INT);
        // Frozen from the row just read, not from the caller. A ledger that names a part
        // the caller thought it was moving would be worse than one that names nothing.
        $insert->bindValue(':component_name', (string) $component['name'], PDO::PARAM_STR);
        $insert->bindValue(':reason', $reason, PDO::PARAM_STR);
        $insert->bindValue(':delta', $delta, PDO::PARAM_INT);
        $insert->bindValue(':balance_after', $balance, PDO::PARAM_INT);
        $insert->bindValue(':order_id', $orderId, $orderId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insert->bindValue(':note', $note, PDO::PARAM_STR);
        $insert->bindValue(':user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insert->execute();

        $moveId = (int) $database->lastInsertId();

        if ($ours) {
            $database->commit();
        }

        return [
            'ok' => true,
            'error' => '',
            'balance' => $balance,
            'moveId' => $moveId,
            'changed' => true,
        ];
    } catch (Throwable $exception) {
        // The guard placeOrder() explains: a failed COMMIT has already ended the
        // transaction, and rollBack() would then throw over the top of the exception that
        // is actually worth seeing. Only ever roll back what this call started.
        if ($ours && $database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }
}

/**
 * A stock-take: "there are forty in the drawer."
 *
 * The count the shop just did is the truth, so this works out the difference and records
 * that as an 'adjusted' movement. Even setting a number by hand leaves a trail -- which is
 * the point, because "it says 40 and I do not know why" is the question the ledger exists
 * to answer.
 *
 * $count cannot be negative. A shortfall is something the books work out; it is not
 * something a person counts in a drawer.
 *
 * The transaction is opened HERE and not left to recordStockMove(), and that is not
 * tidiness. The difference has to be worked out under the same lock that will apply it:
 * read 10 outside a transaction, have somebody receive 5, then write "set to 40" as a delta
 * of 30, and the drawer ends up holding 45. Taking the lock first means the number this
 * reads is the number recordStockMove() then moves from.
 *
 * @return array{ok: bool, error: string, balance: int|null, moveId: int, changed: bool}
 */
function setComponentStock(int $id, int $count, string $note = ''): array
{
    $failure = static fn (string $message): array => [
        'ok' => false,
        'error' => $message,
        'balance' => null,
        'moveId' => 0,
        'changed' => false,
    ];

    if ($id <= 0) {
        return $failure('Choose a part to count.');
    }

    if ($count < 0) {
        return $failure('A stock-take cannot be a negative number. Count what is there.');
    }

    if ($count > STOCK_COUNT_MAX) {
        return $failure('That is more than ' . number_format(STOCK_COUNT_MAX) . '. Check the number.');
    }

    $database = getDatabaseConnection();
    $ours = !$database->inTransaction();

    if ($ours) {
        $database->beginTransaction();
    }

    try {
        $statement = $database->prepare('SELECT stock_on_hand FROM product_components WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);

        $current = $statement->fetchColumn();

        if ($current === false) {
            if ($ours) {
                $database->rollBack();
            }

            return $failure('That part no longer exists.');
        }

        $delta = $count - (int) $current;

        /*
         * Counting forty and finding forty is a successful stock-take that changed nothing,
         * and it must not be an error. No ledger row either: nothing moved, and a movement
         * of zero is refused by the database for exactly that reason. 'changed' is how a
         * caller tells this apart from a real adjustment when it writes its flash message.
         */
        if ($delta === 0) {
            if ($ours) {
                $database->commit();
            }

            return [
                'ok' => true,
                'error' => '',
                'balance' => $count,
                'moveId' => 0,
                'changed' => false,
            ];
        }

        // Inside the transaction and holding the lock, so this re-reads the same row it
        // just locked and moves from the number above.
        $result = recordStockMove($id, $delta, 'adjusted', ['note' => $note]);

        if ($ours) {
            if ($result['ok']) {
                $database->commit();
            } else {
                $database->rollBack();
            }
        }

        return $result;
    } catch (Throwable $exception) {
        if ($ours && $database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }
}

/**
 * Set the level a part should be warned about at. 0 turns the warning off.
 *
 * Deliberately NOT a stock movement, and the only writer of low_stock_threshold -- the same
 * single-writer arrangement recordStockMove() has for stock_on_hand one column along.
 *
 * Nothing physical happened. The drawer holds exactly what it held a moment ago, and the
 * shop has changed its mind about when to worry. Writing a ledger row for that would put a
 * delta of 0 in a table whose CHECK refuses one, and would make "show me every time the
 * rose heads moved" answer with a list of times nobody touched them.
 *
 * @return array{ok: bool, error: string}
 */
function setComponentLowStock(int $id, int $threshold): array
{
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Choose a part.'];
    }

    // The column is SMALLINT UNSIGNED, so a negative would be clamped to 0 by this server's
    // sql_mode rather than refused -- which would read as "the warning turned itself off".
    if ($threshold < 0) {
        return ['ok' => false, 'error' => 'A warning level cannot be negative. Use 0 to turn the warning off.'];
    }

    if ($threshold > PART_QUANTITY_MAX) {
        return ['ok' => false, 'error' => 'Warn at ' . PART_QUANTITY_MAX . ' or fewer.'];
    }

    if (adminFetchComponent($id) === null) {
        return ['ok' => false, 'error' => 'That part no longer exists.'];
    }

    $statement = getDatabaseConnection()->prepare('UPDATE product_components SET low_stock_threshold = :threshold WHERE id = :id');
    $statement->bindValue(':threshold', $threshold, PDO::PARAM_INT);
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();

    return ['ok' => true, 'error' => ''];
}

/**
 * Build the WHERE clause and its bound values for the stock list.
 *
 * Shared by fetchStockLevels() and countStockLevels(), exactly as
 * adminComponentFilterSql() is shared, so a count that does not match the rows cannot make
 * the last page empty or unreachable.
 *
 * Columns are prefixed `c.` because both queries alias the table -- one of them has a
 * correlated subquery in the select list, and an unprefixed column in that position is one
 * rename away from being ambiguous.
 *
 * @return array{sql: string, params: array<string, string>}
 */
function adminStockFilterSql(array $filters): array
{
    $where = [];
    $params = [];

    $status = componentStatus($filters['status'] ?? null);

    if ($status !== 'all') {
        $where[] = 'c.is_active = :is_active';
        $params['is_active'] = $status === 'active' ? '1' : '0';
    }

    $kind = componentKindFilter($filters['kind'] ?? '');

    if ($kind !== '') {
        $where[] = 'c.kind = :kind';
        $params['kind'] = $kind;
    }

    $search = trim((string) ($filters['search'] ?? ''));

    if ($search !== '') {
        // % and _ are LIKE wildcards, escaped with ! and an explicit ESCAPE because the
        // default escape character depends on the server's sql_mode.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);

        $where[] = "c.name LIKE :search ESCAPE '!'";
        $params['search'] = '%' . $escaped . '%';
    }

    /*
     * "Only what's low", the filter the whole screen exists for.
     *
     * Parenthesised because it is two conditions joined into one entry of an AND list, and
     * the threshold test has to travel with the comparison: without it every part with no
     * threshold set would read as low the moment it hit zero, which would bury the two
     * parts the shop actually asked to be told about.
     *
     * No index can satisfy this -- it compares two columns -- which is why
     * idx_components_stock exists to serve the ordering rather than this.
     */
    if (!empty($filters['low'])) {
        $where[] = '(c.low_stock_threshold > 0 AND c.stock_on_hand <= c.low_stock_threshold)';
    }

    return [
        'sql' => $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
        'params' => $params,
    ];
}

/**
 * One page of the stock list.
 *
 * $filters takes 'status', 'kind', 'search', 'low' and 'sort', all of which come from the
 * query string and all of which are reduced to a known value before they reach the SQL.
 *
 * `used_in` is how many recipes call for the part, and it is the column that makes the list
 * worth reading: it is the difference between "we are out of pearl pins" and "we are out of
 * pearl pins and four bouquets need them". A scalar subquery per row rather than a GROUP BY
 * over a join, because the join would drop every part no recipe uses -- which is exactly
 * the set an admin wants to see when deciding what to stop buying.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchStockLevels(array $filters = [], int $limit = ADMIN_STOCK_PER_PAGE, int $offset = 0): array
{
    $filter = adminStockFilterSql($filters);
    $orderBy = STOCK_SORTS[stockSortKey($filters['sort'] ?? null)];

    $statement = getDatabaseConnection()->prepare(
        'SELECT c.id, c.name, c.kind, c.colour, c.colour_hex, c.price, c.sort_order, c.is_active,
                c.stock_on_hand, c.low_stock_threshold,
                (SELECT COUNT(*) FROM product_parts pp WHERE pp.component_id = c.id) AS used_in
           FROM product_components c'
        . $filter['sql']
        . ' ORDER BY ' . $orderBy
        . ' LIMIT :limit OFFSET :offset'
    );

    foreach ($filter['params'] as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    // PARAM_INT rather than through execute(): with ATTR_EMULATE_PREPARES off everything in
    // an execute() array goes as a string, and LIMIT '20' is a syntax error to MySQL.
    $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * How many parts match the stock filters, ignoring paging.
 */
function countStockLevels(array $filters = []): int
{
    $filter = adminStockFilterSql($filters);

    $statement = getDatabaseConnection()->prepare('SELECT COUNT(*) FROM product_components c' . $filter['sql']);
    $statement->execute($filter['params']);

    return (int) $statement->fetchColumn();
}

/**
 * Totals for the stock screen's summary line, over the whole table rather than one page.
 *
 * Its own query and not a sum of the rows on screen, which would say "1 part is low" on
 * page two of three and be wrong every time.
 *
 * Offered parts only. A withdrawn part is not something the customizer will build with, so
 * having none of it is not a problem the shop needs counting on its summary line -- and a
 * banner that says "4 parts are low" about three parts nobody can order would be a banner
 * people learn to ignore. The list itself still shows withdrawn rows, under the status
 * filter, because putting one back is how it starts mattering again.
 *
 * @return array{parts: int, low: int, out: int, units: int}
 */
function stockSummary(): array
{
    $row = getDatabaseConnection()->query(
        "SELECT COUNT(*) AS parts,
                SUM(CASE WHEN low_stock_threshold > 0 AND stock_on_hand <= low_stock_threshold THEN 1 ELSE 0 END) AS low,
                SUM(CASE WHEN stock_on_hand <= 0 THEN 1 ELSE 0 END) AS `out`,
                COALESCE(SUM(stock_on_hand), 0) AS units
           FROM product_components
          WHERE is_active = 1"
    )->fetch();

    return [
        'parts' => (int) ($row['parts'] ?? 0),
        'low' => (int) ($row['low'] ?? 0),
        // "At or below zero", not "below": a part the shop has none of is out, and a part
        // it owes four of is also out. Both need buying, and the ledger holds the detail.
        'out' => (int) ($row['out'] ?? 0),
        'units' => (int) ($row['units'] ?? 0),
    ];
}

/**
 * Build the WHERE clause and its bound values for the ledger.
 *
 * @return array{sql: string, params: array<string, string>}
 */
function adminMoveFilterSql(array $filters): array
{
    $where = [];
    $params = [];

    $componentId = (int) ($filters['component'] ?? 0);

    if ($componentId > 0) {
        $where[] = 'm.component_id = :component';
        $params['component'] = (string) $componentId;
    }

    /*
     * One order's movements, which is what idx_stock_moves_order was created for.
     *
     * By id and not by reference: the id is what the column holds, and a reference would mean
     * a join in a WHERE clause to reach the same row the ledger's own LEFT JOIN already has.
     * php/admin/order.php builds the link, so the reference never has to survive the round trip.
     */
    $orderId = (int) ($filters['order'] ?? 0);

    if ($orderId > 0) {
        $where[] = 'm.order_id = :order';
        $params['order'] = (string) $orderId;
    }

    $reason = stockReasonFilter($filters['reason'] ?? '');

    if ($reason !== '') {
        $where[] = 'm.reason = :reason';
        $params['reason'] = $reason;
    }

    $search = trim((string) ($filters['search'] ?? ''));

    if ($search !== '') {
        $escaped = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

        // The frozen name and the note together. Searching the live component name instead
        // would lose every movement of a part that has since been deleted -- which is the
        // set of rows the frozen column exists for.
        //
        // Two placeholder names for the one value, as adminCustomerFilterSql() does. They
        // cannot share a name: emulated prepares are off, so PDO hands the placeholders to
        // the server positionally and a name used twice is one parameter too few --
        // SQLSTATE[HY093], every time a search is typed.
        $where[] = "(m.component_name LIKE :search_name ESCAPE '!' OR m.note LIKE :search_note ESCAPE '!')";
        $params['search_name'] = $escaped;
        $params['search_note'] = $escaped;
    }

    return [
        'sql' => $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
        'params' => $params,
    ];
}

/**
 * One page of the ledger.
 *
 * The join to users is LEFT and so is the one to orders: a movement outlives both. user_id
 * and order_id are ON DELETE SET NULL for that reason, and an INNER join here would hide
 * exactly the rows that matter -- the ones whose admin account has since been closed.
 *
 * component_name comes from the movement and never from the join, which is the whole point
 * of freezing it.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchStockMoves(array $filters = [], int $limit = ADMIN_STOCK_MOVES_PER_PAGE, int $offset = 0): array
{
    $filter = adminMoveFilterSql($filters);
    $orderBy = STOCK_MOVE_SORTS[stockMoveSortKey($filters['sort'] ?? null)];

    $statement = getDatabaseConnection()->prepare(
        'SELECT m.id, m.component_id, m.component_name, m.reason, m.delta, m.balance_after,
                m.order_id, m.note, m.user_id, m.created_at,
                u.name AS user_name,
                o.reference AS order_reference
           FROM stock_moves m
           LEFT JOIN users u ON u.id = m.user_id
           LEFT JOIN orders o ON o.id = m.order_id'
        . $filter['sql']
        . ' ORDER BY ' . $orderBy
        . ' LIMIT :limit OFFSET :offset'
    );

    foreach ($filter['params'] as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * How many movements match the filters, ignoring paging.
 */
function countStockMoves(array $filters = []): int
{
    $filter = adminMoveFilterSql($filters);

    $statement = getDatabaseConnection()->prepare('SELECT COUNT(*) FROM stock_moves m' . $filter['sql']);
    $statement->execute($filter['params']);

    return (int) $statement->fetchColumn();
}

/**
 * The parts the ledger's filter can offer: every one that has actually moved.
 *
 * Not every part. A dropdown of eighteen names where four of them can only ever return an
 * empty list is a dropdown that wastes somebody's time four times, and the join does the
 * filtering for free.
 *
 * An INNER join, so a movement whose part has since been deleted is not in here -- there is
 * no id left to filter by. Those rows are reached through the search box instead, which
 * looks at the frozen component_name. That is what freezing it was for.
 *
 * @return array<int, array<string, mixed>>
 */
function componentsWithMoves(): array
{
    return getDatabaseConnection()->query(
        'SELECT c.id, c.name, COUNT(*) AS moves
           FROM stock_moves m
           JOIN product_components c ON c.id = m.component_id
          GROUP BY c.id, c.name
          ORDER BY c.name ASC, c.id ASC'
    )->fetchAll();
}

/**
 * Do the books agree with the ledger?
 *
 * Every part whose stored stock_on_hand is not the sum of its movements. An empty array is
 * the healthy answer and the one this should always give: recordStockMove() writes both in
 * one transaction, so the only ways to get a row out of here are a hand-edit in the MySQL
 * shell or a bug in this file.
 *
 * Which is exactly why it is worth running. A stored balance is only trustworthy if
 * something checks it, and this is both a banner on the ledger screen and an assertion in
 * php/tools/inventory-http-test.php.
 *
 * COALESCE, not SUM alone: a part that has never moved has no rows to sum and SUM returns
 * NULL, which is not a mismatch with a balance of 0 -- it is the same fact written two ways.
 * The seed leaves one part in exactly that state on purpose.
 *
 * Movements whose component_id has been nulled by a deletion are not compared against
 * anything, and correctly so: there is no balance left for them to disagree with.
 *
 * @return array<int, array<string, mixed>>
 */
function stockAuditDiscrepancies(): array
{
    return getDatabaseConnection()->query(
        'SELECT c.id, c.name, c.stock_on_hand,
                COALESCE(SUM(m.delta), 0) AS ledger_total,
                c.stock_on_hand - COALESCE(SUM(m.delta), 0) AS difference
           FROM product_components c
           LEFT JOIN stock_moves m ON m.component_id = c.id
          GROUP BY c.id, c.name, c.stock_on_hand
         HAVING c.stock_on_hand <> COALESCE(SUM(m.delta), 0)
          ORDER BY c.name ASC, c.id ASC'
    )->fetchAll();
}

/**
 * What one product is made of, in assembly order.
 *
 * `makes` is how many of the product this part alone could supply -- the count that turns a
 * recipe into an answer. The smallest one across the whole recipe is the binding constraint
 * and is what buildableCount() returns; the screen shows both so that "we can make 4" comes
 * with "because of the tulip heads" beside it.
 *
 * Clamped at zero because MySQL's FLOOR rounds away from zero for negatives: eight stems
 * short of one bouquet is FLOOR(-8 / 10) = -1, and "minus one bouquet" is not a thing. Zero
 * is the truth, and the shortfall itself is on the stock screen.
 *
 * An INNER join, unlike almost everything else here: product_parts.component_id is NOT NULL
 * and cascades, so a row whose part is gone does not exist. That is the recipe-versus-receipt
 * difference sql/inventory-upgrade.sql sets out at length.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchProductParts(int $productId): array
{
    if ($productId <= 0) {
        return [];
    }

    $statement = getDatabaseConnection()->prepare(
        'SELECT pp.id, pp.component_id, pp.quantity, pp.sort_order,
                c.name, c.kind, c.colour, c.colour_hex, c.price, c.is_active,
                c.stock_on_hand, c.low_stock_threshold,
            FLOOR(c.stock_on_hand / pp.quantity) AS makes
           FROM product_parts pp
           JOIN product_components c ON c.id = pp.component_id
          WHERE pp.product_id = :product
          ORDER BY pp.sort_order ASC, pp.id ASC'
    );
    $statement->execute(['product' => $productId]);

    $parts = $statement->fetchAll();

    foreach ($parts as $index => $part) {
        $parts[$index]['makes'] = max(0, (int) $part['makes']);
    }

    return $parts;
}

/**
 * The recipe as componentId => quantity, for drawing the editor's boxes.
 *
 * @return array<int, int>
 */
function productPartQuantities(int $productId): array
{
    $quantities = [];

    foreach (fetchProductParts($productId) as $part) {
        $quantities[(int) $part['component_id']] = (int) $part['quantity'];
    }

    return $quantities;
}

/**
 * Every part the recipe editor draws a box for, grouped by kind.
 *
 * Offered parts, PLUS any withdrawn part this product's recipe already names -- and that
 * second half is not a courtesy. saveProductParts() takes the WHOLE recipe in one POST, so a
 * part with no box on the page is a part the next Save deletes. Listing only the active ones
 * would quietly strip a withdrawn stem out of a bouquet the first time anybody pressed Save
 * on it, and the shop would find out by building the bouquet wrong.
 *
 * Keyed by kind in COMPONENT_KINDS order with every kind present even when empty, so the
 * screen can loop without checking isset() on each one. That is the shape
 * fetchComponentsByKind() returns, which this deliberately does not reuse: that one is the
 * customizer's picker -- it cannot see a withdrawn row at all, which is exactly wrong here --
 * and it does not read the stock columns.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function fetchRecipeChoices(int $productId): array
{
    $statement = getDatabaseConnection()->prepare(
        'SELECT c.id, c.name, c.kind, c.colour, c.colour_hex, c.price, c.sort_order, c.is_active,
                c.stock_on_hand, c.low_stock_threshold
           FROM product_components c
          WHERE c.is_active = 1
             OR EXISTS (
                    SELECT 1
                      FROM product_parts pp
                     WHERE pp.component_id = c.id
                       AND pp.product_id = :product
                )
          ORDER BY c.sort_order ASC, c.id ASC'
    );
    $statement->execute(['product' => max(0, $productId)]);

    $grouped = array_fill_keys(COMPONENT_KINDS, []);

    // No kind in the ORDER BY: the grouping below puts the four bands in COMPONENT_KINDS
    // order, and sorting by an ENUM as well would only be a second opinion about it.
    foreach ($statement->fetchAll() as $component) {
        /*
         * A kind the ENUM has and COMPONENT_KINDS does not gets its own key at the end
         * rather than being dropped, and the screen loops the keys it is given. Losing such a
         * row here would take its box off the page, which is the one failure this whole
         * function exists to prevent.
         */
        $grouped[(string) $component['kind']][] = $component;
    }

    return $grouped;
}

/**
 * How many flowers one of these works out to, from its recipe.
 *
 * The number to hold products.stem_count up against. Blooms only: a dozen roses is twelve
 * rose heads, twelve stems, a sleeve and a tie, and counting the wire as well would answer
 * "24 flowers" for a bouquet of twelve.
 *
 * @param array<int, array<string, mixed>> $parts Rows as fetchProductParts() returns them.
 */
function recipeBloomCount(array $parts): int
{
    $blooms = 0;

    foreach ($parts as $part) {
        if ((string) ($part['kind'] ?? '') === 'bloom') {
            $blooms += (int) ($part['quantity'] ?? 0);
        }
    }

    return $blooms;
}

/**
 * How many of this product the shop could make right now, or null if nobody has said what
 * it is made of.
 *
 * null is "unknown" and is deliberately not 0. A product with no recipe is not a product the
 * shop cannot make -- it is one nobody has written the instructions for yet, and printing
 * "0 buildable" against every product on the day the module ships would be a lie that reads
 * like an emergency.
 *
 * `AND pp.quantity > 0` is belt and braces. saveProductParts() deletes a part rather than
 * storing a zero, so no such row should exist; if one ever did, the division would be by
 * zero, MySQL would return NULL for it, and MIN would skip it -- quietly answering as though
 * that part were not in the recipe at all.
 */
function buildableCount(int $productId): ?int
{
    if ($productId <= 0) {
        return null;
    }

    $statement = getDatabaseConnection()->prepare(
                'SELECT MIN(FLOOR(c.stock_on_hand / pp.quantity))
           FROM product_parts pp
           JOIN product_components c ON c.id = pp.component_id
          WHERE pp.product_id = :product
            AND pp.quantity > 0'
    );
    $statement->execute(['product' => $productId]);

    $value = $statement->fetchColumn();

    // An aggregate with no GROUP BY always returns one row, so an empty recipe arrives here
    // as NULL rather than as no row at all. Both are checked; only one can happen.
    if ($value === false || $value === null) {
        return null;
    }

    return max(0, (int) $value);
}

/**
 * Which products call for this part, and how many each needs.
 *
 * The read behind the warning on the component delete screen. product_parts cascades from
 * product_components, so destroying a part silently empties it out of every recipe that
 * names it -- the cascade is right, and the silence is not.
 *
 * Hidden products are included. A withdrawn bouquet is one the shop may put back, and its
 * recipe going missing while it was off the catalogue is precisely the failure that would
 * not be noticed for months.
 *
 * @return array<int, array<string, mixed>>
 */
function productsUsingComponent(int $componentId): array
{
    if ($componentId <= 0) {
        return [];
    }

    $statement = getDatabaseConnection()->prepare(
        'SELECT p.id, p.name, p.kind, p.is_active, pp.quantity
           FROM product_parts pp
           JOIN products p ON p.id = pp.product_id
          WHERE pp.component_id = :component
          ORDER BY p.name ASC, p.id ASC'
    );
    $statement->execute(['component' => $componentId]);

    return $statement->fetchAll();
}

/**
 * How many recipes each of these parts is in, as componentId => count.
 *
 * One query for a whole page of the parts list, rather than productsUsingComponent() per
 * row: twenty rows would be twenty round trips to answer a question the list only needs a
 * number for. The names are only worth fetching for the one part somebody is about to
 * delete, and that is what productsUsingComponent() is for.
 *
 * Parts in no recipe at all are absent rather than zero. Callers read this with ?? 0, which
 * is the same answer without a row per part that has nothing to say.
 *
 * @param int[] $componentIds
 * @return array<int, int>
 */
function recipeCountsForComponents(array $componentIds): array
{
    $ids = [];

    foreach ($componentIds as $id) {
        if ((int) $id > 0) {
            $ids[] = (int) $id;
        }
    }

    $ids = array_values(array_unique($ids));

    if ($ids === []) {
        return [];
    }

    // Positional placeholders because the list is built at run time. The ids are already
    // integers by the loop above, and are bound rather than interpolated all the same.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $statement = getDatabaseConnection()->prepare(
        "SELECT component_id, COUNT(*) AS recipes
           FROM product_parts
          WHERE component_id IN ($placeholders)
          GROUP BY component_id"
    );
    $statement->execute($ids);

    $counts = [];

    foreach ($statement->fetchAll() as $row) {
        $counts[(int) $row['component_id']] = (int) $row['recipes'];
    }

    return $counts;
}

/**
 * Where a part sits in a recipe's reading order, from its kind and its picker position.
 *
 * Unknown kinds fall to the end rather than to the front. A part whose kind this file has
 * not heard of is one added by a later migration, and the bottom of the list is where an
 * unexplained row is noticed instead of being mistaken for the first bloom.
 */
function partSortOrder(string $kind, int $componentSort): int
{
    $band = PART_KIND_SORT_BAND[$kind] ?? 900;

    return $band + max(0, min(99, $componentSort));
}

/**
 * Replace a product's whole recipe in one write.
 *
 * $quantities is componentId => quantity, straight off the form, and it is the WHOLE recipe:
 * a component that is not in it, or is in it as 0 or as an empty box, is removed.
 * That is what makes the editor a single form with a number beside every part rather than a
 * pile of add-a-row JavaScript, and it is why the contract has to be written down here --
 * a caller that passes a partial array will wipe everything it left out.
 *
 * Idempotent by construction rather than by luck. The existing rows are read inside the
 * transaction and the difference is worked out in PHP, so posting the same form twice does
 * nothing the second time and reports 0, 0, 0. The UNIQUE key on (product_id, component_id)
 * is the backstop for the case PHP cannot see: two admins submitting at the same instant,
 * where both diffs say "add", and ON DUPLICATE KEY UPDATE turns the loser into the same
 * final state instead of into a duplicate-key error.
 *
 * @param array<int|string, mixed> $quantities
 * @return array{errors: array<int, string>, added: int, updated: int, removed: int}
 */
function saveProductParts(int $productId, array $quantities): array
{
    if ($productId <= 0 || adminFetchProduct($productId) === null) {
        return ['errors' => ['That product no longer exists.'], 'added' => 0, 'updated' => 0, 'removed' => 0];
    }

    /*
        * Reduce the submission to whole numbers, refusing junk rather than rounding it.
     *
     * One message however many boxes are wrong, because the form re-renders with what was
     * typed still in them: twelve copies of the same sentence would push the boxes off the
     * screen without saying anything the first copy did not.
     */
    $wanted = [];
    $malformed = false;

    foreach ($quantities as $key => $raw) {
        $componentId = (int) $key;

        if ($componentId <= 0) {
            continue;
        }

        /*
         * An array or an object is refused rather than cast.
         *
         * qty[12][]=x makes $_POST['qty'][12] an array, and (string) on one of those is an
         * "Array to string conversion" warning followed by the literal text "Array" -- which
         * would then fail the digits test anyway, having already written a warning to the log
         * and, with a strict error handler, thrown. Refusing it here says the same thing
         * cleanly.
         */
        if (!is_scalar($raw)) {
            $malformed = true;
            continue;
        }

        $text = trim((string) $raw);

        // An empty box is "not in this bouquet", not a mistake. It is what every part the
        // admin has not thought about looks like, and there are seventeen of those.
        if ($text === '') {
            continue;
        }

        if (preg_match('/^\d+$/', $text) !== 1 || (int) $text > PART_QUANTITY_MAX) {
            $malformed = true;
            continue;
        }

        $quantity = (int) $text;

        if ($quantity > 0) {
            $wanted[$componentId] = $quantity;
        }
    }

    if ($malformed) {
        return [
            'errors' => ['How many of each part has to be a whole number between 0 and ' . PART_QUANTITY_MAX . '. Leave a box empty for a part this one does not use.'],
            'added' => 0,
            'updated' => 0,
            'removed' => 0,
        ];
    }

    $database = getDatabaseConnection();
    $ours = !$database->inTransaction();

    if ($ours) {
        $database->beginTransaction();
    }

    try {
        $statement = $database->prepare('SELECT component_id, quantity FROM product_parts WHERE product_id = :product FOR UPDATE');
        $statement->execute(['product' => $productId]);

        $existing = [];

        foreach ($statement->fetchAll() as $row) {
            $existing[(int) $row['component_id']] = (int) $row['quantity'];
        }

        /*
         * Check the parts exist, and pick up what the new rows' sort_order should be, in one
         * read rather than one per part.
         *
         * Positional placeholders because the list is built at run time; the statement has
         * no named ones, so there is nothing to mix them with. The ids are already integers
         * -- they were cast on the way into $wanted -- but they are bound rather than
         * interpolated all the same, because "it cannot be a string by now" is the kind of
         * thing that stops being true when somebody adds a caller.
         */
        $known = [];

        if ($wanted !== []) {
            $ids = array_keys($wanted);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $lookup = $database->prepare("SELECT id, kind, sort_order FROM product_components WHERE id IN ($placeholders)");
            $lookup->execute($ids);

            foreach ($lookup->fetchAll() as $row) {
                $known[(int) $row['id']] = $row;
            }

            foreach ($ids as $id) {
                if (!isset($known[$id])) {
                    if ($ours) {
                        $database->rollBack();
                    }

                    // No partial save. A recipe half-written from a form that named a part
                    // which has since been deleted is worse than one that was refused.
                    return [
                        'errors' => ['One of those parts has been deleted since this page was opened. Reload and try again.'],
                        'added' => 0,
                        'updated' => 0,
                        'removed' => 0,
                    ];
                }
            }
        }

        $added = 0;
        $updated = 0;
        $removed = 0;

        $delete = $database->prepare('DELETE FROM product_parts WHERE product_id = :product AND component_id = :component');

        foreach ($existing as $componentId => $quantity) {
            if (isset($wanted[$componentId])) {
                continue;
            }

            $delete->bindValue(':product', $productId, PDO::PARAM_INT);
            $delete->bindValue(':component', $componentId, PDO::PARAM_INT);
            $delete->execute();

            $removed++;
        }

        $insert = $database->prepare(
            'INSERT INTO product_parts (product_id, component_id, quantity, sort_order)
             VALUES (:product, :component, :quantity, :sort_order)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), sort_order = VALUES(sort_order)'
        );
        $update = $database->prepare(
            'UPDATE product_parts
                SET quantity = :quantity
              WHERE product_id = :product
                AND component_id = :component'
        );

        foreach ($wanted as $componentId => $quantity) {
            if (!isset($existing[$componentId])) {
                $insert->bindValue(':product', $productId, PDO::PARAM_INT);
                $insert->bindValue(':component', $componentId, PDO::PARAM_INT);
                $insert->bindValue(':quantity', $quantity, PDO::PARAM_INT);
                $insert->bindValue(
                    ':sort_order',
                    partSortOrder((string) $known[$componentId]['kind'], (int) $known[$componentId]['sort_order']),
                    PDO::PARAM_INT
                );
                $insert->execute();

                $added++;

                continue;
            }

            // Unchanged rows are left alone rather than re-written to the same value. It is
            // what makes a second identical submit report "nothing to change" instead of
            // "17 parts updated", and it keeps created_at meaning when the part joined the
            // recipe.
            if ($existing[$componentId] === $quantity) {
                continue;
            }

            $update->bindValue(':quantity', $quantity, PDO::PARAM_INT);
            $update->bindValue(':product', $productId, PDO::PARAM_INT);
            $update->bindValue(':component', $componentId, PDO::PARAM_INT);
            $update->execute();

            $updated++;
        }

        if ($ours) {
            $database->commit();
        }

        return ['errors' => [], 'added' => $added, 'updated' => $updated, 'removed' => $removed];
    } catch (Throwable $exception) {
        if ($ours && $database->inTransaction()) {
            $database->rollBack();
        }

        throw $exception;
    }
}

/*
 * ===================================================================================
 * Stage 2: what an order takes out of stock, and putting it back.
 * ===================================================================================
 *
 * The half the module was built expecting. Everything above this line was written so that
 * nothing below it needed a migration: the 'used' and 'returned' reasons, stock_moves.order_id
 * and idx_stock_moves_order were all in place from the first day, written by nothing.
 *
 * Read the four functions below in pairs. orderStockRequirement() says what making an order
 * SHOULD take -- read live from the recipe, because that is the instruction somebody is about
 * to follow. orderStockOut() says what it HAS taken -- read from the ledger, because that is
 * what actually happened. Consuming is the difference between them; returning is simply what
 * the ledger says is out.
 *
 * That asymmetry is deliberate and it is the receipt-versus-recipe distinction this module
 * turns on, appearing one last time. A recipe edited between pressing Start making and
 * pressing Not started after all must put back what was taken, not what the recipe now says --
 * otherwise the undo invents or destroys stock. Reading the ledger for the return is what makes
 * that impossible rather than merely unlikely.
 *
 * And it is what makes both directions idempotent by construction rather than by a guard. Pack,
 * un-pack, pack again: the 'used' and 'returned' rows cancel, so the second pack sees nothing
 * out and takes the recipe exactly once more. There is no stock_consumed flag anywhere, because
 * a flag would be a second opinion about a fact the ledger already holds -- and the two would
 * eventually disagree.
 */

/**
 * What making this order should take out of the drawer.
 *
 * Two sources, because an order has two kinds of line and they are made of different things:
 *
 *  - A **design** line is a custom bouquet, and what it is made of was frozen into
 *    order_item_parts when the order was placed. That is a receipt, and it is what the person
 *    at the bench is building from, so it is what comes out of stock.
 *  - A **product** line is a catalogue bouquet, which has no frozen parts list -- it has a live
 *    recipe in product_parts, read through order_items.product_id.
 *
 * Both multiplied by order_items.quantity: two of the same bouquet is twice the parts.
 *
 * Filtered by kind on both sides so the two cannot overlap. Nothing writes order_item_parts for
 * a product line today, but a line that had both would otherwise be counted twice, and a
 * double consumption is the one arithmetic error in this module nobody would spot from the
 * screen.
 *
 * 'blind' is the honest half of the answer: the lines that will take nothing out, and why. An
 * empty recipe is a real and seeded state -- Single Calla Lily has none on purpose -- and a
 * deleted product or a deleted material leaves a line whose parts cannot be known. **A pack
 * that quietly took nothing out is worse than a shortfall**, because a shortfall is at least
 * written down. So these are collected and put on screen rather than skipped.
 *
 * @return array{parts: array<int, array<string, mixed>>, materials: array<int, array<string, mixed>>, blind: array<int, array<string, string>>}
 */
function orderStockRequirement(int $orderId): array
{
    if ($orderId <= 0) {
        return ['parts' => [], 'materials' => [], 'blind' => []];
    }

    $database = getDatabaseConnection();
    $parts = [];

    /*
     * The two reads are separate statements rather than one UNION, and that is not laziness:
     * they group different tables by different keys, and a UNION would need the wider of the
     * two shapes in both halves for no gain over adding them up in PHP -- which is four lines
     * and can be read.
     */
    $sources = [
        // Design lines, from their frozen receipt.
        'SELECT p.component_id AS id, c.name, c.kind, c.stock_on_hand, c.low_stock_threshold,
                SUM(p.quantity * i.quantity) AS needed
           FROM order_items i
           JOIN order_item_parts p ON p.item_id = i.id
           JOIN product_components c ON c.id = p.component_id
          WHERE i.order_id = :order
            AND i.kind = \'design\'
          GROUP BY p.component_id, c.name, c.kind, c.stock_on_hand, c.low_stock_threshold',

                // Existing catalog recipes remain a fallback until an admin saves raw materials.
                'SELECT pp.component_id AS id, c.name, c.kind, c.stock_on_hand, c.low_stock_threshold,
                                SUM(pp.quantity * i.quantity) AS needed
                     FROM order_items i
                     JOIN product_parts pp ON pp.product_id = i.product_id
                     JOIN product_components c ON c.id = pp.component_id
                    WHERE i.order_id = :order
                        AND i.kind = \'product\'
                        AND NOT EXISTS (
                                SELECT 1 FROM product_materials pm WHERE pm.product_id = i.product_id
                        )
                    GROUP BY pp.component_id, c.name, c.kind, c.stock_on_hand, c.low_stock_threshold',

    ];

    foreach ($sources as $sql) {
        $statement = $database->prepare($sql);
        $statement->execute(['order' => $orderId]);

        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['id'];

            // One component can be in both a design line and a product line of the same
            // order, so the two reads add rather than overwrite.
            if (isset($parts[$id])) {
                $parts[$id]['needed'] += (int) $row['needed'];

                continue;
            }

            $parts[$id] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'kind' => (string) $row['kind'],
                'needed' => (int) $row['needed'],
                'onHand' => (int) $row['stock_on_hand'],
                'lowAt' => (int) $row['low_stock_threshold'],
            ];
        }
    }

    /*
     * Recipe order -- blooms, then wire, then wrap, then accent -- rather than id order.
     *
     * The same band fetchProductParts() sorts by, and for the same reason: it is the order
     * somebody at the bench works in. It also settles the order the 'used' rows land in the
     * ledger, so one order's movements read like a recipe instead of like the components table.
     */
    uasort($parts, static function (array $a, array $b): int {
        $bandA = PART_KIND_SORT_BAND[$a['kind']] ?? 900;
        $bandB = PART_KIND_SORT_BAND[$b['kind']] ?? 900;

        return $bandA === $bandB ? strcasecmp($a['name'], $b['name']) : $bandA - $bandB;
    });

    /*
     * The lines that will take nothing out.
     *
     * Subqueries rather than joins, because this is asking three independent counting questions
     * about a handful of rows and a join would need a GROUP BY that made each answer depend on
     * the others. An order has a few lines; this is cheap.
     *
     * pp.product_id = i.product_id with a NULL product_id matches nothing, which is the right
     * answer -- a deleted product has no recipe to read -- and it is why that case is told apart
     * by product_id rather than by the count.
     */
    $statement = $database->prepare(
        'SELECT i.id, i.kind, i.name, i.product_id,
                (SELECT COUNT(*) FROM order_item_parts p WHERE p.item_id = i.id) AS part_rows,
                (SELECT COUNT(*) FROM order_item_parts p
                  WHERE p.item_id = i.id AND p.component_id IS NULL) AS lost_parts,
                ((SELECT COUNT(*) FROM product_materials pm WHERE pm.product_id = i.product_id)
                 + (SELECT COUNT(*) FROM product_parts pp WHERE pp.product_id = i.product_id)) AS recipe_rows
           FROM order_items i
          WHERE i.order_id = :order
          ORDER BY i.id ASC'
    );
    $statement->execute(['order' => $orderId]);

    $blind = [];

    foreach ($statement->fetchAll() as $line) {
        $name = (string) $line['name'];
        $lost = (int) $line['lost_parts'];

        if ((string) $line['kind'] === 'design') {
            if ((int) $line['part_rows'] === 0) {
                $blind[] = ['line' => $name, 'why' => 'nothing was recorded about what it is made of, so nothing can come out of stock for it'];
            } elseif ($lost > 0) {
                $blind[] = ['line' => $name, 'why' => $lost === 1
                    ? 'one of its materials has been deleted since, so that one cannot come out of stock'
                    : $lost . ' of its materials have been deleted since, so those cannot come out of stock'];
            }

            continue;
        }

        if ($line['product_id'] === null) {
            $blind[] = ['line' => $name, 'why' => 'the product it was sold from has been deleted, so its recipe is gone'];

            continue;
        }

        if ((int) $line['recipe_rows'] === 0) {
            $blind[] = ['line' => $name, 'why' => 'that product has no recipe yet, so nothing comes out of stock for it'];
        }
    }

    return [
        'parts' => $parts,
        'materials' => productMaterialRequirement($orderId),
        'blind' => $blind,
    ];
}

/**
 * What this order currently has out of stock, per part, from the ledger.
 *
 * -SUM(delta) over the order's 'used' and 'returned' rows. A consumption is negative and a
 * return is positive, so they cancel: an order that was started and un-started has nothing out,
 * and one that was started twice with an undo in between has one order's worth.
 *
 * This is the whole of the idempotency design. It is also what makes the undo correct across a
 * recipe edit, because it describes what happened rather than what should have.
 *
 * Only the two reasons, and not every row carrying the order id, because a future reason that
 * mentioned an order without moving its parts would otherwise be read as a consumption.
 *
 * component_id IS NOT NULL: a part deleted after its movement was recorded leaves the movement
 * behind -- that is what ON DELETE SET NULL is for -- but there is no row left to put anything
 * back into. Those units are counted by orderStockLostUnits() and said out loud rather than
 * quietly dropped.
 *
 * The ORDER BY is not decoration. returnOrderStock() writes one ledger row per key of this array
 * in this order, so without it the order of the shop's own history would rest on whether MariaDB
 * felt like sorting a GROUP BY that day. Cheap here -- the grouping has already sorted -- and it
 * makes the row order a fact rather than a habit.
 *
 * @return array<int, int> componentId => units out, always positive
 */
function orderStockOut(int $orderId): array
{
    if ($orderId <= 0) {
        return [];
    }

    $statement = getDatabaseConnection()->prepare(
        "SELECT component_id, SUM(delta) AS net
           FROM stock_moves
          WHERE order_id = :order
            AND reason IN ('used', 'returned')
            AND component_id IS NOT NULL
          GROUP BY component_id
          ORDER BY component_id"
    );
    $statement->execute(['order' => $orderId]);

    $out = [];

    foreach ($statement->fetchAll() as $row) {
        $units = -(int) $row['net'];

        // Zero is netted out. A negative would mean more was returned than ever taken, which
        // nothing can produce -- and if it ever did, returning it again is the last thing to do.
        if ($units > 0) {
            $out[(int) $row['component_id']] = $units;
        }
    }

    return $out;
}

/**
 * Units this order took out of parts that have since been deleted.
 *
 * They cannot be returned -- there is no row to return them to -- so the number exists to be
 * said on screen. Silently returning less than was taken would leave the books right and the
 * story wrong, and this is the one case where the two come apart.
 */
function orderStockLostUnits(int $orderId): int
{
    if ($orderId <= 0) {
        return 0;
    }

    $statement = getDatabaseConnection()->prepare(
        "SELECT COALESCE(-SUM(delta), 0) AS units
           FROM stock_moves
          WHERE order_id = :order
            AND reason IN ('used', 'returned')
            AND component_id IS NULL"
    );
    $statement->execute(['order' => $orderId]);

    return max(0, (int) $statement->fetchColumn());
}

/**
 * This order's own movements, oldest first, for the order screen to print.
 *
 * fetchStockMoves() with the filter the ledger screen now offers, rather than a fifth query
 * shaped like it: the joins to users and orders, the frozen name and the balance_after column
 * are all things the panel wants and all things that function already selects.
 *
 * Oldest first, which is the opposite of the ledger's default and right for this one screen: on
 * one order these rows are a story -- taken out, put back, taken out again -- and a story reads
 * forwards. The cap is generous rather than paged; an order with more than sixty stock
 * movements against it is one somebody has been fighting with, and the whole fight is the thing
 * worth seeing.
 *
 * @return array<int, array<string, mixed>>
 */
function orderStockLedger(int $orderId): array
{
    if ($orderId <= 0) {
        return [];
    }

    $moves = array_merge(
        fetchStockMoves(['order' => $orderId, 'sort' => 'oldest'], 60, 0),
        fetchMaterialOrderMoves($orderId)
    );
    usort($moves, static fn (array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']) ?: ((int) $a['id'] <=> (int) $b['id']));

    return array_slice($moves, 0, 60);
}

/**
 * The empty report both writes below start from.
 *
 * One shape for both directions so the screen has one thing to read and one helper can turn
 * either into a sentence:
 *
 *  - moves  -- ledger rows written. Zero is a real and common answer.
 *  - units  -- parts moved, which is what a person means by "how much".
 *  - short  -- [name, balance] per part that ended below zero. Only ever set when consuming.
 *  - blind  -- [line, why] per order line that could not be costed. Only ever set when consuming.
 *  - lost   -- units that went out against a part that has since been deleted.
 *  - errors -- refusals from recordStockMove(), collected rather than thrown.
 *
 * @return array{moves: int, units: float, short: array<int, array<string, mixed>>, blind: array<int, array<string, string>>, lost: int, errors: array<int, string>}
 */
function emptyStockReport(): array
{
    return ['moves' => 0, 'units' => 0.0, 'short' => [], 'blind' => [], 'lost' => 0, 'errors' => []];
}

/**
 * Take an order's parts out of stock. Called when making starts.
 *
 * **Does not open a transaction, and that is the contract.** The caller owns one, because the
 * status change and the stock have to commit or fail together: an order that says Being made
 * while nothing came out of the drawer, or parts gone with the order still sitting in Placed,
 * are both worse than either half failing. recordStockMove() joins whatever transaction it
 * finds, which is the property Stage 1 built for exactly this call.
 *
 * $take is the difference between what the recipe says and what the ledger says is already out,
 * so packing an order that is already packed takes nothing. There is no flag and no guard --
 * the arithmetic simply has nothing left to do.
 *
 * A refusal from one part is collected and the rest continue. **Warn, never block** is the
 * shop's rule, and abandoning a whole pack because one deleted material could not be decremented
 * would be the opposite of it: the other eleven parts really are off the bench. A thrown
 * PDOException is a different thing entirely and still unwinds the caller's transaction.
 *
 * A negative balance is allowed through on purpose -- it is why stock_on_hand is INT and not
 * INT UNSIGNED. The shop is told, and the order is not stopped.
 */
function consumeOrderStock(int $orderId): array
{
    $report = emptyStockReport();

    if ($orderId <= 0) {
        return $report;
    }

    $requirement = orderStockRequirement($orderId);
    $report['blind'] = $requirement['blind'];

    $already = orderStockOut($orderId);

    foreach ($requirement['parts'] as $componentId => $part) {
        $take = (int) $part['needed'] - (int) ($already[$componentId] ?? 0);

        // Nothing to do, or more is out than this order needs -- which a recipe edited while
        // the order was packed can produce. Taking a negative amount here would be a return
        // filed under 'used', and the ledger would stop meaning what it says.
        if ($take <= 0) {
            continue;
        }

        $result = recordStockMove($componentId, -$take, 'used', ['orderId' => $orderId]);

        if (!$result['ok']) {
            $report['errors'][] = $part['name'] . ': ' . $result['error'];

            continue;
        }

        $report['moves']++;
        $report['units'] += $take;

        if ((float) $result['balance'] < 0) {
            $report['short'][] = ['name' => $part['name'], 'balance' => (float) $result['balance']];
        }
    }

    $materialReport = consumeOrderMaterials($orderId);
    $report['moves'] += $materialReport['moves'];
    $report['units'] += $materialReport['units'];
    $report['short'] = array_merge($report['short'], $materialReport['short']);
    $report['errors'] = array_merge($report['errors'], $materialReport['errors']);

    return $report;
}

/**
 * Put an order's parts back. Called when making is undone, and when the shop cancels an order
 * that was already being made.
 *
 * **Reads the ledger, never the recipe.** What goes back is what came out, which is the only
 * answer that survives a recipe edited in between -- and editing a recipe while an order is on
 * the bench is an ordinary thing to do, not an edge case. Reading the recipe here would invent
 * stock when a part had been added to it and destroy stock when one had been removed.
 *
 * Same transaction contract as consumeOrderStock(): the caller owns it.
 *
 * Idempotent for the same reason the consume is: the 'returned' rows it writes are counted by
 * the next orderStockOut(), so a second call finds nothing out and puts nothing back.
 *
 * 'lost' is the honest remainder -- units taken out of a part that has since been deleted. There
 * is no row left to return them to, so they are reported rather than quietly forgotten.
 */
function returnOrderStock(int $orderId): array
{
    $report = emptyStockReport();

    if ($orderId <= 0) {
        return $report;
    }

    $report['lost'] = orderStockLostUnits($orderId);

    foreach (orderStockOut($orderId) as $componentId => $units) {
        if ($units <= 0) {
            continue;
        }

        $result = recordStockMove($componentId, $units, 'returned', ['orderId' => $orderId]);

        if (!$result['ok']) {
            $report['errors'][] = $result['error'];

            continue;
        }

        $report['moves']++;
        $report['units'] += $units;
    }

    $materialReport = returnOrderMaterials($orderId);
    $report['moves'] += $materialReport['moves'];
    $report['units'] += $materialReport['units'];
    $report['errors'] = array_merge($report['errors'], $materialReport['errors']);

    return $report;
}

/**
 * One sentence about what just happened to the stock, or '' when there is nothing to say.
 *
 * Appended to the toast the order screen already shows. Counts only: the detail belongs on the
 * order page, under "What it takes out of stock", where it can be read at leisure. A flash
 * naming eight materials is a flash nobody reads.
 *
 * '' when nothing moved and nothing went wrong, because "0 parts came out of stock" on an order
 * whose products have no recipes yet is noise the shop would learn to ignore -- and the whole
 * point of the shortfall line is that it is not ignorable.
 */
function stockReportSentence(array $report, bool $consuming): string
{
    $parts = [];

    $moves = (int) ($report['moves'] ?? 0);
    $units = (float) ($report['units'] ?? 0);
    $short = $report['short'] ?? [];
    $blind = $report['blind'] ?? [];
    $lost = (int) ($report['lost'] ?? 0);
    $errors = $report['errors'] ?? [];

    if ($moves > 0) {
        $quantity = materialQuantity($units);
        $amount = floor($units) === $units
            ? number_format($units) . ' ' . ($units === 1.0 ? 'part' : 'parts')
            : $quantity . ' stock units';
        $parts[] = $consuming
            ? $amount . ' came out of stock.'
            : $amount . ' went back into stock.';
    }

    if ($short !== []) {
        $count = count($short);
        $parts[] = $count === 1
            ? 'Stock has gone short on ' . $short[0]['name'] . '.'
            : 'Stock has gone short on ' . $count . ' materials.';
    }

    if ($blind !== []) {
        $count = count($blind);
        $parts[] = $count === 1
            ? '1 line took nothing out of stock -- see the order for why.'
            : $count . ' lines took nothing out of stock -- see the order for why.';
    }

    if ($lost > 0) {
        $parts[] = number_format($lost) . ' could not go back: that material has been deleted.';
    }

    if ($errors !== []) {
        $count = count($errors);
        $parts[] = $count === 1
            ? 'One part could not be moved: ' . $errors[0]
            : $count . ' parts could not be moved. Check the stock history.';
    }

    return implode(' ', $parts);
}

