<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/events.php';

/**
 * Reading the catalogue.
 *
 * Everything that asks the database about products goes through here, the same way
 * twofactor.php and devices.php own their own tables. The catalogue page, the
 * product page and the home page's featured row all call these functions, so the
 * rules about what counts as visible live in one place.
 *
 * The shop sells fuzzy wire handicraft flowers -- wire and chenille, not real ones
 * -- as ready-made bouquets and as single flowers. See sql/products-upgrade.sql.
 *
 * PROMO PRICING HAPPENS HERE, AND ONLY HERE. fetchProduct() and fetchProducts() are the only
 * customer-facing readers of products.price, so both pass their rows through
 * decorateProductPricing() in php/config/events.php before returning them. When an event is on and
 * covers a product, that row comes back with `price` REPLACED by the promo price and `list_price`
 * carrying the original.
 *
 * Every consumer downstream inherits it without knowing promos exist -- the catalogue grid, the
 * product page, and addProductToCart(), which means the DISCOUNTED price is what gets frozen into
 * cart_items.unit_price. That freeze is the whole reason the discount is applied at this depth
 * rather than at checkout: the basket, the order, the receipt and the reports all read the frozen
 * figure through code that did not change.
 *
 * The admin side is deliberately NOT affected: php/admin/products.php reads through
 * adminFetchProduct() in products-admin.php, a separate function on a separate file, so the shop
 * always sees and edits list price.
 */

/**
 * The peso sign, written as an escape rather than typed in.
 *
 * "\u{20B1}" is plain ASCII in the source file, so no editor, terminal or copy-paste
 * between encodings can turn it into mojibake. The pages already declare UTF-8, so
 * what reaches the browser is the real character either way.
 */
const CURRENCY_SYMBOL = "\u{20B1}";

/** How many products a catalogue page shows. */
const PRODUCTS_PER_PAGE = 12;

/** The two kinds, matching the ENUM in products.kind. */
const PRODUCT_KINDS = ['bouquet', 'flower'];

/**
 * The orderings the catalogue offers, as a fixed map.
 *
 * ORDER BY cannot be a bound parameter -- it is part of the statement, not a value
 * -- so a sort chosen by the visitor has to become SQL somehow. It does it by being
 * a key into this array and nothing else: an unknown key falls back to the default
 * and the request's own text never reaches the query.
 *
 * Every entry ends in an id tie-breaker, and that is not tidiness. The seed inserts
 * all twelve rows in one statement, so they share a created_at to the second; with
 * LIMIT and OFFSET on top of an ordering that has ties, the database is free to
 * return them in a different order each time, and page 2 would then repeat rows
 * from page 1 and skip others entirely.
 */
const PRODUCT_SORTS = [
    'newest' => 'created_at DESC, id DESC',
    'price-low' => 'price ASC, id ASC',
    'price-high' => 'price DESC, id DESC',
    'name' => 'name ASC, id ASC',
];

const PRODUCT_SORT_DEFAULT = 'newest';

/**
 * A price as it should appear on the page: "1,150.00" with the peso sign.
 *
 * The cast to float is for display only. Money is stored and added up as
 * DECIMAL(10,2) -- see the comment on that column -- and nothing in this project
 * does arithmetic on the value this function returns.
 */
function formatPrice(string $price): string
{
    return CURRENCY_SYMBOL . number_format((float) $price, 2);
}

/**
 * "Bouquet" or "Single flower", for a value out of products.kind.
 */
function productKindLabel(string $kind): string
{
    return $kind === 'flower' ? 'Single flower' : 'Bouquet';
}

/**
 * The sort key to actually use, given whatever arrived in the query string.
 */
function productSortKey(mixed $sort): string
{
    return is_string($sort) && isset(PRODUCT_SORTS[$sort]) ? $sort : PRODUCT_SORT_DEFAULT;
}

/**
 * The kind filter to actually use: one of PRODUCT_KINDS, or '' for no filter.
 */
function productKindFilter(mixed $kind): string
{
    return is_string($kind) && in_array($kind, PRODUCT_KINDS, true) ? $kind : '';
}

/**
 * Build the WHERE clause and its bound values from the visitor's filters.
 *
 * Shared by fetchProducts() and countProducts() so the two can never disagree about
 * what is being listed -- a count that does not match the rows means the last page
 * of the catalogue is either empty or unreachable.
 *
 * @return array{sql: string, params: array<string, string>}
 */
function productFilterSql(array $filters): array
{
    // Hidden products are excluded here rather than at each call site, so
    // forgetting it is not possible.
    $where = ['is_active = 1'];
    $params = [];

    $kind = productKindFilter($filters['kind'] ?? '');

    if ($kind !== '') {
        $where[] = 'kind = :kind';
        $params['kind'] = $kind;
    }

    $search = trim((string) ($filters['search'] ?? ''));

    if ($search !== '') {
        // % and _ are wildcards to LIKE, so a search for "50%" would otherwise
        // match everything and one for "a_b" would match "axb". They are escaped
        // with ! and ESCAPE says so explicitly: the default escape character
        // depends on the server's sql_mode, and this should not.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);

        $where[] = "name LIKE :search ESCAPE '!'";
        $params['search'] = '%' . $escaped . '%';
    }

    return [
        'sql' => ' WHERE ' . implode(' AND ', $where),
        'params' => $params,
    ];
}

/**
 * One page of the catalogue.
 *
 * $filters takes 'kind', 'search' and 'sort', all of which may be anything at all --
 * they come from the query string. Each is validated above rather than trusted.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchProducts(array $filters = [], int $limit = PRODUCTS_PER_PAGE, int $offset = 0): array
{
    $filter = productFilterSql($filters);
    $orderBy = PRODUCT_SORTS[productSortKey($filters['sort'] ?? null)];

    $statement = getDatabaseConnection()->prepare(
        'SELECT id, name, kind, description, price, colour, stem_count, image_path
         FROM products'
        . $filter['sql']
        . ' ORDER BY ' . $orderBy
        . ' LIMIT :limit OFFSET :offset'
    );

    foreach ($filter['params'] as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    // bindValue with PARAM_INT rather than passing these through execute(): with
    // ATTR_EMULATE_PREPARES off, everything in an execute() array is sent as a
    // string, and LIMIT '12' is a syntax error to MySQL.
    $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $statement->execute();

    // The promo, applied once per row. See this file's docblock: this and fetchProduct() are the two
    // places a customer-facing price is read, so they are the two places it has to happen.
    //
    // NOTE ON SORTING, because it is a real wart rather than an oversight: ORDER BY above runs on
    // products.price in SQL, which is the LIST price. A discounted bouquet therefore still sorts by
    // what it normally costs. Re-sorting here after decoration would be wrong in a worse way -- this
    // function returns one page of rows, so a PHP sort would only reorder within that page and
    // "cheapest first" would be a lie told twelve rows at a time.
    return decorateProductList($statement->fetchAll());
}

/**
 * How many products match the filters, ignoring paging.
 */
function countProducts(array $filters = []): int
{
    $filter = productFilterSql($filters);

    $statement = getDatabaseConnection()->prepare('SELECT COUNT(*) FROM products' . $filter['sql']);
    $statement->execute($filter['params']);

    return (int) $statement->fetchColumn();
}

/**
 * One product, or null if there is no visible product with that id.
 *
 * A hidden product returns null exactly like a missing one. The caller cannot tell
 * the difference, which is the point: the catalogue should not confirm that a
 * product exists but has been taken down.
 */
function fetchProduct(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $statement = getDatabaseConnection()->prepare(
        'SELECT id, name, kind, description, price, colour, stem_count, image_path, created_at
         FROM products
         WHERE id = :id AND is_active = 1
         LIMIT 1'
    );
    $statement->execute(['id' => $id]);

    $product = $statement->fetch();

    if ($product === false) {
        return null;
    }

    // The promo. This is also the call that decides what addProductToCart() freezes, since it reads
    // the price from this function -- so a customer who adds a bouquet during an event locks in the
    // event price and keeps it. That is the freeze rule working, not a hole: see php/config/events.php.
    return decorateProductPricing($product);
}

/**
 * A product photo's URL, or null when there is nothing to show.
 *
 * Checks the file is really on disk rather than trusting the column, so a path left
 * behind by a deleted image draws the placeholder tile instead of a broken-image
 * icon. Nothing has photographs yet -- image_path is empty on every seeded row, and
 * filling it is the Products module's job along with upload.
 */
function productImageUrl(array $product): ?string
{
    $path = trim((string) ($product['image_path'] ?? ''));

    if ($path === '') {
        return null;
    }

    // ltrim so a stored '/uploads/...' cannot climb out via the concatenation, and
    // basename-level containment is enough here because the value is set by an
    // admin upload rather than by a visitor.
    $absolute = dirname(__DIR__, 2) . '/' . ltrim($path, '/\\');

    return is_file($absolute) ? asset($path) : null;
}
