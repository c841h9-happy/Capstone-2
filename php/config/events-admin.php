<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/products-admin.php';

/**
 * Events and promos, admin side: validation, writes, and the lists the two screens read.
 *
 * The write half of php/config/events.php, split the same way products-admin.php splits from
 * products.php and for the same reason -- a customer-facing read must never be able to see a draft
 * or a finished event, so the function that filters on dates and is_active lives in the other file
 * and nothing here is reachable from a customer page.
 *
 * ONE RULE RUNS THROUGH THIS FILE: the admin sees list price, always. Nothing here calls
 * fetchProduct() or fetchProducts() from products.php, because those two apply the promo. The
 * product picker and the promo checkbox list both read through adminFetchProducts() in
 * products-admin.php, which does not -- so an admin editing a 20%-off event sees ₱38.00 beside a
 * bouquet that the catalogue is currently selling for ₱30.40, which is the number they need in order
 * to reason about the discount at all.
 */

/** A promo list longer than this is a sign somebody ticked "all" by mistake, not a real offer. */
const EVENT_PRODUCTS_MAX = 200;

/**
 * Check what the event form sent.
 *
 * Returns ['errors' => string[], 'values' => array] in the shape createEvent()/updateEvent() take,
 * exactly like validateComponentInput() in components-admin.php.
 *
 * $ignoreId is the row being edited, so an event does not collide with its own name.
 */
function validateEventInput(array $input, ?int $ignoreId = null): array
{
    $errors = [];

    $name = productInputText($input, 'name');
    $tagline = productInputText($input, 'tagline');
    $startInput = productInputText($input, 'starts_on');
    $endInput = productInputText($input, 'ends_on');
    $percentInput = productInputText($input, 'discount_percent');
    $featuredInput = productInputText($input, 'featured_product_id');

    if ($name === '') {
        $errors[] = 'Give the event a name.';
    } elseif (mb_strlen($name) > EVENT_NAME_MAX) {
        $errors[] = 'The name has to be ' . EVENT_NAME_MAX . ' characters or fewer.';
    } elseif (eventNameExists($name, $ignoreId)) {
        $errors[] = 'There is already an event called that.';
    }

    if (mb_strlen($tagline) > EVENT_TAGLINE_MAX) {
        $errors[] = 'The tagline has to be ' . EVENT_TAGLINE_MAX . ' characters or fewer.';
    }

    /*
     * Dates are checked for SHAPE and then for REALITY, which are two different refusals.
     *
     * A browser date input sends YYYY-MM-DD, but a POST is not obliged to have come from one, and
     * '2026-02-30' passes any regex while being a day that does not exist. checkdate() is what
     * catches that -- without it MariaDB would take it as '0000-00-00' or refuse it with a message
     * about a date column that means nothing to the person who typed it.
     */
    $start = eventDateInput($startInput);
    $end = eventDateInput($endInput);

    if ($start === null) {
        $errors[] = 'Give a real start date, as a calendar date.';
    }

    if ($end === null) {
        $errors[] = 'Give a real end date, as a calendar date.';
    }

    /*
     * Checked here as well as by chk_events_range in SQL, deliberately. The constraint is what makes
     * the state impossible; this is what makes the refusal readable. A CHECK violation surfaces as
     * SQLSTATE 23000 naming a constraint, which is not a sentence to show a shopkeeper.
     */
    if ($start !== null && $end !== null && $end < $start) {
        $errors[] = 'The event cannot end before it starts.';
    }

    // Blank means 0 -- an event that features a flower and discounts nothing is a real thing, so it
    // must not be an error. A typed value still has to be a whole number in range, because "20%"
    // silently becoming 20 is the sort of thing that is noticed a month later.
    $percent = 0;

    if ($percentInput !== '') {
        if (preg_match('/^\d+$/', $percentInput) !== 1 || (int) $percentInput > EVENT_PERCENT_MAX) {
            $errors[] = 'The discount has to be a whole number between 0 and ' . EVENT_PERCENT_MAX . '.';
        } else {
            $percent = (int) $percentInput;
        }
    }

    /*
     * The featured product. Blank is fine -- an event can be a promo with no showcase.
     *
     * Checked against adminFetchProduct() rather than against the catalogue, so a product that is
     * currently withdrawn can still be chosen: the shop may well set up next month's event around a
     * bouquet it has not published yet. The banner handles the other side of that by going through
     * fetchProduct(), which filters on is_active -- so a withdrawn featured product is simply not
     * shown rather than being refused here.
     */
    $featuredId = null;

    if ($featuredInput !== '' && $featuredInput !== '0') {
        if (preg_match('/^\d+$/', $featuredInput) !== 1) {
            $errors[] = 'That is not a product.';
        } elseif (adminFetchProduct((int) $featuredInput) === null) {
            $errors[] = 'The flower you picked to feature no longer exists.';
        } else {
            $featuredId = (int) $featuredInput;
        }
    }

    return [
        'errors' => $errors,
        'values' => [
            'name' => $name,
            'tagline' => $tagline,
            'starts_on' => $start ?? '',
            'ends_on' => $end ?? '',
            'discount_percent' => $percent,
            'featured_product_id' => $featuredId,
            // Unticked boxes are not submitted at all, so this is presence, not value. An event can
            // be written now and switched on later.
            'is_active' => isset($input['is_active']) ? 1 : 0,
        ],
    ];
}

/**
 * A posted date as YYYY-MM-DD, or null when it is not a real calendar date.
 *
 * checkdate() is the whole point: '2026-02-30' and '2026-13-01' both match the pattern and neither
 * is a day.
 */
function eventDateInput(string $value): ?string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $found) !== 1) {
        return null;
    }

    [, $year, $month, $day] = $found;

    if (!checkdate((int) $month, (int) $day, (int) $year)) {
        return null;
    }

    return $value;
}

/**
 * Is some other event already called this?
 *
 * Case-insensitively, because the column's collation is utf8mb4_unicode_ci and two events called
 * "Valentine's" and "valentine's" would be a mistake rather than two offers.
 */
function eventNameExists(string $name, ?int $ignoreId = null): bool
{
    $sql = 'SELECT id FROM events WHERE name = :name';
    $params = ['name' => $name];

    if ($ignoreId !== null && $ignoreId > 0) {
        $sql .= ' AND id <> :ignore';
        $params['ignore'] = $ignoreId;
    }

    $statement = getDatabaseConnection()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);

    return $statement->fetch() !== false;
}

/**
 * Add an event. Returns its new id.
 *
 * Takes the 'values' half of validateEventInput() and nothing else -- there is no path into this
 * function that has not been through those checks.
 */
function createEvent(array $values): int
{
    $connection = getDatabaseConnection();

    $statement = $connection->prepare(
        'INSERT INTO events (name, tagline, starts_on, ends_on, featured_product_id, discount_percent, is_active)
         VALUES (:name, :tagline, :starts, :ends, :featured, :percent, :active)'
    );

    bindEventValues($statement, $values);
    $statement->execute();

    return (int) $connection->lastInsertId();
}

/**
 * Change an event.
 */
function updateEvent(int $id, array $values): void
{
    $statement = getDatabaseConnection()->prepare(
        'UPDATE events
            SET name = :name,
                tagline = :tagline,
                starts_on = :starts,
                ends_on = :ends,
                featured_product_id = :featured,
                discount_percent = :percent,
                is_active = :active
          WHERE id = :id'
    );

    bindEventValues($statement, $values);
    $statement->bindValue('id', $id, PDO::PARAM_INT);
    $statement->execute();
}

/**
 * The seven columns both writes share, bound once.
 *
 * featured_product_id is bound with PARAM_NULL when there is nothing featured: binding null through
 * PARAM_INT sends 0, and 0 is not a product id -- it is a foreign key violation waiting for the
 * first event somebody saves without picking a flower.
 */
function bindEventValues(PDOStatement $statement, array $values): void
{
    $statement->bindValue('name', (string) $values['name']);
    $statement->bindValue('tagline', (string) $values['tagline']);
    $statement->bindValue('starts', (string) $values['starts_on']);
    $statement->bindValue('ends', (string) $values['ends_on']);
    $statement->bindValue('percent', (int) $values['discount_percent'], PDO::PARAM_INT);
    $statement->bindValue('active', (int) $values['is_active'], PDO::PARAM_INT);

    if ($values['featured_product_id'] === null) {
        $statement->bindValue('featured', null, PDO::PARAM_NULL);

        return;
    }

    $statement->bindValue('featured', (int) $values['featured_product_id'], PDO::PARAM_INT);
}

/**
 * Set the whole promo list for one event, in one call.
 *
 * $productIds is whatever the checkboxes submitted. The same shape as saveProductParts() in
 * inventory.php: the form posts the entire list every time, this works out the difference, and a
 * double submit is a no-op because UNIQUE (event_id, product_id) makes it one.
 *
 * Returns ['added' => int, 'removed' => int, 'errors' => string[]].
 *
 * ONE TRANSACTION, because a promo that is half-applied is worse than one that failed: the
 * catalogue would discount some of the bouquets on a Valentine's poster and not others, and nothing
 * would say so. Joins a transaction somebody else opened rather than nesting, the same
 * three-line pattern php/config/orders-admin.php uses.
 */
function saveEventProducts(int $eventId, array $productIds): array
{
    $errors = [];

    if ($eventId <= 0 || adminFetchEvent($eventId) === null) {
        return ['added' => 0, 'removed' => 0, 'errors' => ['That event no longer exists.']];
    }

    // Ints, unique, and only ids that are really products. A checkbox list is just a POST array:
    // nothing stops it naming a product that was deleted while the form was open, and the foreign
    // key would refuse the whole transaction over one stale tick.
    $wanted = [];

    foreach ($productIds as $raw) {
        $id = (int) $raw;

        if ($id > 0 && adminFetchProduct($id) !== null) {
            $wanted[$id] = true;
        }
    }

    if (count($wanted) > EVENT_PRODUCTS_MAX) {
        return [
            'added' => 0,
            'removed' => 0,
            'errors' => ['That is more than ' . EVENT_PRODUCTS_MAX . ' products in one promo.'],
        ];
    }

    $database = getDatabaseConnection();
    $ours = !$database->inTransaction();

    if ($ours) {
        $database->beginTransaction();
    }

    try {
        $existingStatement = $database->prepare(
            'SELECT product_id FROM event_products WHERE event_id = :event'
        );
        $existingStatement->execute(['event' => $eventId]);

        $existing = [];

        foreach ($existingStatement->fetchAll() as $row) {
            $existing[(int) $row['product_id']] = true;
        }

        $insert = $database->prepare(
            'INSERT INTO event_products (event_id, product_id) VALUES (:event, :product)'
        );
        $delete = $database->prepare(
            'DELETE FROM event_products WHERE event_id = :event AND product_id = :product'
        );

        $added = 0;
        $removed = 0;

        foreach ($wanted as $id => $unused) {
            if (!isset($existing[$id])) {
                $insert->execute(['event' => $eventId, 'product' => $id]);
                $added++;
            }
        }

        foreach ($existing as $id => $unused) {
            if (!isset($wanted[$id])) {
                $delete->execute(['event' => $eventId, 'product' => $id]);
                $removed++;
            }
        }

        if ($ours) {
            $database->commit();
        }

        return ['added' => $added, 'removed' => $removed, 'errors' => $errors];
    } catch (PDOException $exception) {
        if ($ours && $database->inTransaction()) {
            $database->rollBack();
        }

        error_log('saveEventProducts: ' . $exception->getMessage());

        return ['added' => 0, 'removed' => 0, 'errors' => ['The promo list could not be saved.']];
    }
}

/**
 * Switch one event on or off, touching nothing else.
 *
 * The counterpart of setProductActive() and setComponentActive(), and a separate function from
 * updateEvent() for the reason those two are: a toggle that went through the full update would
 * rewrite the name, the dates, the discount and the featured product from whatever the caller
 * happened to be holding. One stale read and switching an event off would quietly revert an edit
 * somebody made in another tab.
 *
 * TWO PLACEHOLDER NAMES FOR ONE VALUE, which is not a style choice and was a real bug here first.
 * The obvious spelling uses `:active` in both the SET and the WHERE -- and with
 * ATTR_EMULATE_PREPARES off PDO hands placeholders to the server positionally, so a named
 * placeholder used twice is one parameter short and the statement throws SQLSTATE[HY093] every
 * single time. That is the same fault php/config/inventory.php's ledger search shipped with, and
 * adminCustomerFilterSql() in php/config/admin.php is the precedent that gets it right.
 *
 * The `is_active <> :was` clause is what makes the return value mean something: false for an event
 * that was already in that state, rather than a silent success that claims a change was made.
 *
 * Returns false when there was no such event, or when it was already in that state.
 */
function setEventActive(int $id, bool $active): bool
{
    if ($id <= 0) {
        return false;
    }

    $statement = getDatabaseConnection()->prepare(
        'UPDATE events SET is_active = :active WHERE id = :id AND is_active <> :was'
    );
    $statement->bindValue('active', $active ? 1 : 0, PDO::PARAM_INT);
    $statement->bindValue('was', $active ? 1 : 0, PDO::PARAM_INT);
    $statement->bindValue('id', $id, PDO::PARAM_INT);
    $statement->execute();

    return $statement->rowCount() === 1;
}

/**
 * Delete an event, but only one that is switched off.
 *
 * The same guard deleteProduct() has, for the same reason: a live event is on the home page of a
 * shop somebody is looking at right now, and a delete is the one action with no undo. Switching it
 * off first is one extra click and makes the destructive step deliberate.
 *
 * Returns false when the event is still active or does not exist. event_products goes with it by
 * CASCADE -- see sql/events-upgrade.sql for why that costs nothing: what a customer actually paid is
 * frozen in their basket and order lines, not here.
 */
function deleteEvent(int $id): bool
{
    if ($id <= 0) {
        return false;
    }

    $statement = getDatabaseConnection()->prepare(
        'DELETE FROM events WHERE id = :id AND is_active = 0'
    );
    $statement->execute(['id' => $id]);

    return $statement->rowCount() === 1;
}

/**
 * One event for the admin, by id, with no date or is_active filter at all.
 *
 * The counterpart of activeEvent(): that one answers "what is on"; this one answers "show me row
 * 7", which is what an edit form needs for an event that finished last year.
 */
function adminFetchEvent(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $statement = getDatabaseConnection()->prepare(
        'SELECT id, name, tagline, starts_on, ends_on, featured_product_id, discount_percent,
                is_active, created_at
           FROM events
          WHERE id = :id
          LIMIT 1'
    );
    $statement->execute(['id' => $id]);

    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * The list screen's filters, as SQL and parameters.
 *
 * $alias is the table alias the clauses should be written against, '' for an unaliased query. The
 * list read joins products to get the featured name, which makes `name` and `id` ambiguous -- so the
 * alias is a parameter rather than something patched onto the finished string afterwards. The first
 * version of this function did it with str_replace() over the built SQL, which worked and was one
 * careless column name away from rewriting the wrong token.
 *
 * TWO PLACEHOLDER NAMES BOUND TO ONE VALUE for the search, which is not a style choice. With
 * ATTR_EMULATE_PREPARES off PDO hands placeholders to the server positionally, so a named
 * placeholder used twice is one parameter short and every search throws SQLSTATE[HY093]. That bug
 * shipped in php/config/inventory.php's ledger search and was only caught because a test asserted
 * the failure banner was absent. The precedent that gets it right is adminCustomerFilterSql() in
 * php/config/admin.php.
 */
function adminEventFilterSql(array $filters, string $alias = ''): array
{
    $prefix = $alias === '' ? '' : $alias . '.';

    $clauses = [];
    $params = [];

    $state = (string) ($filters['state'] ?? '');

    if ($state === 'on') {
        $clauses[] = $prefix . 'is_active = 1 AND ' . $prefix . 'starts_on <= CURDATE() AND ' . $prefix . 'ends_on >= CURDATE()';
    } elseif ($state === 'upcoming') {
        $clauses[] = $prefix . 'is_active = 1 AND ' . $prefix . 'starts_on > CURDATE()';
    } elseif ($state === 'finished') {
        $clauses[] = $prefix . 'ends_on < CURDATE()';
    } elseif ($state === 'off') {
        $clauses[] = $prefix . 'is_active = 0';
    }

    $search = trim((string) ($filters['q'] ?? ''));

    if ($search !== '') {
        $clauses[] = '(' . $prefix . 'name LIKE :search_name ESCAPE "!"'
            . ' OR ' . $prefix . 'tagline LIKE :search_tagline ESCAPE "!")';

        // % _ and ! are literal when somebody searches for them. ESCAPE "!" is what makes the
        // backslash-free escaping above work, and it has to be declared per LIKE.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $params['search_name'] = $like;
        $params['search_tagline'] = $like;
    }

    return [
        'sql' => $clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses),
        'params' => $params,
    ];
}

/**
 * The sorts the list screen offers, as an allow-list -- never interpolated from input.
 *
 * Written against the `e` alias the list query uses, so the ORDER BY needs no rewriting either.
 */
const EVENT_SORTS = [
    'soonest' => 'e.starts_on DESC, e.id DESC',
    'oldest' => 'e.starts_on ASC, e.id ASC',
    'name' => 'e.name ASC, e.id ASC',
    'discount' => 'e.discount_percent DESC, e.id DESC',
];

/** One page of events for the admin list, each carrying its featured name and promo size. */
function adminFetchEvents(array $filters = [], int $limit = 12, int $offset = 0): array
{
    $filter = adminEventFilterSql($filters, 'e');
    $sortKey = (string) ($filters['sort'] ?? 'soonest');
    $orderBy = EVENT_SORTS[$sortKey] ?? EVENT_SORTS['soonest'];

    $statement = getDatabaseConnection()->prepare(
        'SELECT e.id, e.name, e.tagline, e.starts_on, e.ends_on, e.featured_product_id,
                e.discount_percent, e.is_active,
                p.name AS featured_name,
                (SELECT COUNT(*) FROM event_products ep WHERE ep.event_id = e.id) AS promo_count
           FROM events e
           LEFT JOIN products p ON p.id = e.featured_product_id'
        . $filter['sql']
        . ' ORDER BY ' . $orderBy
        . ' LIMIT :limit OFFSET :offset'
    );

    foreach ($filter['params'] as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    // PARAM_INT rather than through execute(): with emulated prepares off everything in an execute()
    // array is sent as a string, and LIMIT '12' is a syntax error.
    $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/** How many events match the filters, ignoring paging. */
function adminCountEvents(array $filters = []): int
{
    $filter = adminEventFilterSql($filters);

    $statement = getDatabaseConnection()->prepare('SELECT COUNT(*) FROM events' . $filter['sql']);

    foreach ($filter['params'] as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    $statement->execute();

    return (int) $statement->fetchColumn();
}

/**
 * The product ids in one event's promo, as a set, with no date or is_active filter.
 *
 * The admin counterpart of eventProductIds(): that one answers "what is discounted right now" for
 * the catalogue, this one answers "what is ticked on this form" for any event at all.
 */
function adminEventProductIds(int $eventId): array
{
    if ($eventId <= 0) {
        return [];
    }

    $statement = getDatabaseConnection()->prepare(
        'SELECT product_id FROM event_products WHERE event_id = :event'
    );
    $statement->execute(['event' => $eventId]);

    $ids = [];

    foreach ($statement->fetchAll() as $row) {
        $ids[(int) $row['product_id']] = true;
    }

    return $ids;
}

/**
 * Is this event on today?
 *
 * Reads the row it is given rather than querying, so the list screen can draw an "on now" pill for
 * twelve rows without twelve round trips. The comparison is on date strings, which is safe because
 * the column is DATE and so is the format: 'YYYY-MM-DD' sorts lexically the same way it sorts
 * chronologically, which is the one thing ISO 8601 is for.
 */
function eventIsOnNow(array $event): bool
{
    if ((int) ($event['is_active'] ?? 0) !== 1) {
        return false;
    }

    $today = date('Y-m-d');

    return (string) $event['starts_on'] <= $today && (string) $event['ends_on'] >= $today;
}

/**
 * A plain-language state for one event row: "On now", "Upcoming", "Finished", "Switched off".
 */
function eventStateLabel(array $event): string
{
    if ((int) ($event['is_active'] ?? 0) !== 1) {
        return 'Switched off';
    }

    $today = date('Y-m-d');

    if ((string) $event['ends_on'] < $today) {
        return 'Finished';
    }

    if ((string) $event['starts_on'] > $today) {
        return 'Upcoming';
    }

    return 'On now';
}
