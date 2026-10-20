<?php

declare(strict_types=1);

require_once __DIR__ . '/products.php';
require_once __DIR__ . '/uploads.php';

/**
 * Managing the catalogue: the reads an admin is allowed to make, and every write.
 *
 * Separate from config/products.php on purpose. That file's productFilterSql() puts
 * `is_active = 1` into the WHERE clause itself "so forgetting it is not possible", and
 * an $includeHidden flag bolted onto it would hand every future caller the ability to
 * forget -- one wrong default and hidden products are back on sale. So the public read
 * path is left exactly as it is, and the admin path is its own file whose name says out
 * loud that it can see and change everything.
 *
 * image_path is written by setProductImage() and by nothing else. It stayed out of
 * createProduct() and updateProduct() while there was no upload, and it stays out of them
 * now there is: a photo and the seven text fields are saved by two different forms
 * pressing the same button, and an UPDATE that listed image_path would blank the picture
 * every time somebody corrected a price. See php/config/uploads.php for what is allowed
 * to become a photo in the first place.
 */

/** How many rows the admin list shows at once. A table is denser than a card grid. */
const ADMIN_PRODUCTS_PER_PAGE = 20;

/** The three views of the list. Anything else means 'all'. */
const PRODUCT_STATUSES = ['all', 'active', 'hidden'];

/*
 * The limits, named so the form's maxlength, the validation and the error messages
 * cannot drift apart.
 *
 * The first two mirror sql/products-upgrade.sql exactly -- VARCHAR(120) and
 * VARCHAR(40). The other two are ours: description is a TEXT column and would take far
 * more than 2000 characters, and stem_count is SMALLINT UNSIGNED and would take 65,535,
 * but a description that long is a mistake rather than a description and a bouquet of
 * sixty thousand flowers is not a thing this shop sells.
 */
const PRODUCT_NAME_MAX = 120;
const PRODUCT_COLOUR_MAX = 40;
const PRODUCT_DESCRIPTION_MAX = 2000;
const PRODUCT_STEM_COUNT_MAX = 999;

/**
 * One field out of a submitted form, trimmed.
 *
 * is_string first, because name[]=x makes $_POST['name'] an array: casting that to
 * string is a warning and puts the literal text "Array" into the column.
 */
function productInputText(array $input, string $key): string
{
    $value = $input[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

/**
 * The status filter to actually use: one of PRODUCT_STATUSES.
 *
 * Falls back to 'all' rather than erroring, the same way productSortKey() falls back to
 * the default sort -- this is a URL an admin edits by hand.
 */
function adminProductStatus(mixed $status): string
{
    return is_string($status) && in_array($status, PRODUCT_STATUSES, true) ? $status : 'all';
}

/**
 * "Visible" or "Hidden", for a value out of products.is_active.
 */
function productStatusLabel(int $isActive): string
{
    return $isActive === 1 ? 'Visible' : 'Hidden';
}

/**
 * Build the WHERE clause and its bound values for the admin list.
 *
 * Shared by adminFetchProducts() and adminCountProducts() for the same reason the
 * public pair share theirs: a count that does not match the rows makes the last page of
 * the list either empty or unreachable.
 *
 * @return array{sql: string, params: array<string, string>}
 */
function adminProductFilterSql(array $filters): array
{
    // No is_active here by default -- that is the whole difference from
    // productFilterSql(), and why this is a separate function rather than a flag.
    $where = [];
    $params = [];

    $status = adminProductStatus($filters['status'] ?? null);

    if ($status !== 'all') {
        $where[] = 'is_active = :is_active';
        $params['is_active'] = $status === 'active' ? '1' : '0';
    }

    $kind = productKindFilter($filters['kind'] ?? '');

    if ($kind !== '') {
        $where[] = 'kind = :kind';
        $params['kind'] = $kind;
    }

    $search = trim((string) ($filters['search'] ?? ''));

    if ($search !== '') {
        // Same escaping as productFilterSql() -- see the comment there for why % and _
        // have to be escaped and why the escape character is stated explicitly.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);

        $where[] = "name LIKE :search ESCAPE '!'";
        $params['search'] = '%' . $escaped . '%';
    }

    return [
        'sql' => $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
        'params' => $params,
    ];
}

/**
 * One page of the admin list, hidden products included.
 *
 * $filters takes 'status', 'kind', 'search' and 'sort'. All four come from the query
 * string and all four are reduced to a known value before they reach the SQL.
 *
 * @return array<int, array<string, mixed>>
 */
function adminFetchProducts(array $filters = [], int $limit = ADMIN_PRODUCTS_PER_PAGE, int $offset = 0): array
{
    $filter = adminProductFilterSql($filters);
    $orderBy = PRODUCT_SORTS[productSortKey($filters['sort'] ?? null)];

    $statement = getDatabaseConnection()->prepare(
        'SELECT id, name, kind, description, price, colour, stem_count, image_path, is_active, created_at
         FROM products'
        . $filter['sql']
        . ' ORDER BY ' . $orderBy
        . ' LIMIT :limit OFFSET :offset'
    );

    foreach ($filter['params'] as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    // PARAM_INT rather than execute(), for the reason given in fetchProducts().
    $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * How many products match the filters, ignoring paging.
 */
function adminCountProducts(array $filters = []): int
{
    $filter = adminProductFilterSql($filters);

    $statement = getDatabaseConnection()->prepare('SELECT COUNT(*) FROM products' . $filter['sql']);
    $statement->execute($filter['params']);

    return (int) $statement->fetchColumn();
}

/**
 * One product, hidden or not, or null if there is no product with that id.
 *
 * The opposite of fetchProduct(), which cannot see a hidden row at all. An admin has to
 * be able to open the thing they just took down -- that is how it goes back up.
 */
function adminFetchProduct(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $statement = getDatabaseConnection()->prepare(
        'SELECT id, name, kind, description, price, colour, stem_count, image_path, is_active, created_at
         FROM products
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $id]);

    $product = $statement->fetch();

    return $product === false ? null : $product;
}

/**
 * Is some other product already called this?
 *
 * $ignoreId is the product being edited, which is allowed to keep its own name.
 *
 * The column collates utf8mb4_unicode_ci, so this comparison ignores case and
 * "Pink Rose" is found by a search for "pink rose". That is what is wanted here: two
 * products differing only in capitalisation are the same product entered twice.
 */
function productNameExists(string $name, ?int $ignoreId = null): bool
{
    $sql = 'SELECT 1 FROM products WHERE name = :name';
    $params = ['name' => $name];

    if ($ignoreId !== null) {
        $sql .= ' AND id <> :id';
        $params['id'] = $ignoreId;
    }

    $statement = getDatabaseConnection()->prepare($sql . ' LIMIT 1');
    $statement->execute($params);

    return $statement->fetchColumn() !== false;
}

/**
 * Check a submitted product, and turn it into the values to store.
 *
 * One function for both the create form and the edit form, so the two can never
 * disagree about what a valid product is. $ignoreId is the id being edited.
 *
 * 'values' is only meaningful when 'errors' is empty -- the pages re-render a failed
 * form from the raw submission, so that what is in the boxes is what was typed.
 *
 * @return array{errors: array<int, string>, values: array<string, mixed>}
 */
function validateProductInput(array $input, ?int $ignoreId = null): array
{
    $errors = [];

    $name = productInputText($input, 'name');
    $kindInput = productInputText($input, 'kind');
    $priceInput = productInputText($input, 'price');
    $colour = productInputText($input, 'colour');
    $description = productInputText($input, 'description');
    $stemInput = productInputText($input, 'stem_count');

    /*
     * mb_strlen, not strlen: VARCHAR(120) in utf8mb4 counts characters, so a name of
     * 120 accented letters fits the column even though it is 240 bytes.
     *
     * That is deliberately unlike the check on users.name in php/profile.php, which
     * uses strlen to agree byte-for-byte with the one in php/login.php. Two forms
     * writing one column have to match each other; this is the only form writing this
     * one, so it can simply match the column.
     */
    if ($name === '') {
        $errors[] = 'Enter a name for the product.';
    } elseif (mb_strlen($name) > PRODUCT_NAME_MAX) {
        $errors[] = 'The name has to be ' . PRODUCT_NAME_MAX . ' characters or fewer.';
    } elseif (productNameExists($name, $ignoreId)) {
        // A courtesy, not a constraint. There is no unique index behind it, so two
        // admins saving the same name in the same second would both get through -- but
        // one admin cannot race themselves, and what this actually catches is a
        // product added twice by someone who forgot they had already added it.
        $errors[] = 'There is already a product called "' . $name . '".';
    }

    // in_array, not productKindFilter(): that returns '' for junk, which means "no
    // filter" to a query and would quietly mean "bouquet" to a write.
    $kind = in_array($kindInput, PRODUCT_KINDS, true) ? $kindInput : '';

    if ($kind === '') {
        $errors[] = 'Choose whether this is a bouquet or a single flower.';
    }

    /*
     * The price.
     *
     * Checked here rather than left to the column. DECIMAL(10,2) UNSIGNED answers a
     * negative with SQLSTATE 22003, which would reach the admin as "unable to save" --
     * a database error for something they can fix in the box.
     *
     * Eight digits with up to two decimals is exactly the column's range, 0.00 to
     * 99,999,999.99, and refusing a sign, a third decimal and 1e5 falls out of the same
     * pattern. Spaces and thousands separators are stripped first, because a price
     * copied off the catalogue reads "1,150.00".
     */
    $priceDigits = str_replace([' ', ','], '', $priceInput);
    $price = '';

    if ($priceDigits === '') {
        $errors[] = 'Enter a price.';
    } elseif (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $priceDigits) !== 1) {
        $errors[] = 'Enter the price as digits, like 450 or 450.50 -- no more than two decimal places, and no more than 99,999,999.99.';
    } else {
        // Stored with both decimal places whatever was typed, so "450" and "450.00" go
        // into the column identically and read identically back out.
        $price = number_format((float) $priceDigits, 2, '.', '');
    }

    if (mb_strlen($colour) > PRODUCT_COLOUR_MAX) {
        $errors[] = 'The colour has to be ' . PRODUCT_COLOUR_MAX . ' characters or fewer.';
    }

    if ($description === '') {
        $errors[] = 'Write a short description.';
    } elseif (mb_strlen($description) > PRODUCT_DESCRIPTION_MAX) {
        $errors[] = 'The description has to be ' . PRODUCT_DESCRIPTION_MAX . ' characters or fewer.';
    }

    /*
     * How many flowers are in it -- and NULL for a single flower, whatever was
     * submitted.
     *
     * The schema's rule is that stem_count is NULL for kind = 'flower', where the
     * answer is always one. The form hides the field when the kind is switched to
     * flower, but a value left in it, or one posted by hand, must not be able to write
     * a 12 into a single stem.
     */
    $stemCount = null;

    if ($kind === 'bouquet') {
        if ($stemInput === '') {
            $errors[] = 'Say how many flowers are in the bouquet.';
        } elseif (
            preg_match('/^\d+$/', $stemInput) !== 1
            || (int) $stemInput < 1
            || (int) $stemInput > PRODUCT_STEM_COUNT_MAX
        ) {
            $errors[] = 'The number of flowers has to be a whole number between 1 and ' . PRODUCT_STEM_COUNT_MAX . '.';
        } else {
            $stemCount = (int) $stemInput;
        }
    }

    return [
        'errors' => $errors,
        'values' => [
            'name' => $name,
            'kind' => $kind,
            'description' => $description,
            'price' => $price,
            'colour' => $colour,
            'stem_count' => $stemCount,
            // Unticked boxes are not submitted at all, so this is presence, not value.
            // A product can be written as a draft and published later.
            'is_active' => isset($input['is_active']) ? 1 : 0,
        ],
    ];
}

/**
 * Add a product. Returns its new id.
 *
 * Takes the 'values' half of validateProductInput() and nothing else -- there is no
 * path into this function that has not been through those checks.
 *
 * image_path is left out, so the column takes its '' default: a photo is attached by
 * setProductImage() once the row exists and has an id to attach it to. created_at takes
 * CURRENT_TIMESTAMP.
 */
function createProduct(array $values): int
{
    $connection = getDatabaseConnection();

    $statement = $connection->prepare(
        'INSERT INTO products (name, kind, description, price, colour, stem_count, is_active)
         VALUES (:name, :kind, :description, :price, :colour, :stem_count, :is_active)'
    );

    bindProductValues($statement, $values);
    $statement->execute();

    return (int) $connection->lastInsertId();
}

/**
 * Change a product.
 *
 * image_path and created_at are not in the statement. The first because a photo is its
 * own form field saved by setProductImage(), and an UPDATE naming the column would blank
 * the picture every time somebody corrected a price; the second because when a product
 * was added is a fact about the past.
 */
function updateProduct(int $id, array $values): void
{
    $statement = getDatabaseConnection()->prepare(
        'UPDATE products
            SET name = :name,
                kind = :kind,
                description = :description,
                price = :price,
                colour = :colour,
                stem_count = :stem_count,
                is_active = :is_active
          WHERE id = :id'
    );

    bindProductValues($statement, $values);
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();
}

/**
 * The seven columns both writes have in common.
 *
 * Its own function so the INSERT and the UPDATE cannot end up binding the same value
 * two different ways -- stem_count in particular, which is the one that needs care.
 */
function bindProductValues(PDOStatement $statement, array $values): void
{
    $statement->bindValue(':name', (string) $values['name'], PDO::PARAM_STR);
    $statement->bindValue(':kind', (string) $values['kind'], PDO::PARAM_STR);
    $statement->bindValue(':description', (string) $values['description'], PDO::PARAM_STR);
    // PARAM_STR for the price: it is a DECIMAL, and handing it over as text is what
    // keeps it out of a float on the way to a column chosen specifically to avoid one.
    $statement->bindValue(':price', (string) $values['price'], PDO::PARAM_STR);
    $statement->bindValue(':colour', (string) $values['colour'], PDO::PARAM_STR);

    // PARAM_NULL, not an empty string. With ATTR_EMULATE_PREPARES off, '' sent to a
    // SMALLINT is a truncation error in strict mode and a 0 without it -- and a
    // bouquet of nought flowers is not what "a single flower has no count" means.
    $stemCount = $values['stem_count'];
    $statement->bindValue(
        ':stem_count',
        $stemCount === null ? null : (int) $stemCount,
        $stemCount === null ? PDO::PARAM_NULL : PDO::PARAM_INT
    );

    $statement->bindValue(':is_active', (int) $values['is_active'] === 1 ? 1 : 0, PDO::PARAM_INT);
}

/**
 * Point a product at a photo, or at nothing.
 *
 * The only writer of image_path. $path is a project-relative value that
 * storeUploadedImage() has already returned -- this function does not check it, because
 * the check that matters happened when the bytes were read, and re-deriving it from the
 * string here would be checking the wrong thing.
 *
 * The old file is removed after the column is written, never before. If the removal
 * fails, the worst outcome is a file nobody references; doing it the other way round
 * would leave the column pointing at a photo that is no longer on disk, and a product
 * whose picture has vanished looks broken in a way an orphaned file does not.
 */
function setProductImage(int $id, string $path): void
{
    if ($id <= 0) {
        return;
    }

    $existing = adminFetchProduct($id);

    if ($existing === null) {
        return;
    }

    $previous = trim((string) $existing['image_path']);

    $statement = getDatabaseConnection()->prepare('UPDATE products SET image_path = :image_path WHERE id = :id');
    $statement->bindValue(':image_path', $path, PDO::PARAM_STR);
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();

    // Replacing a photo with the same photo is not a thing the form can do, but a
    // caller could, and deleting the file we just pointed at would be quite a bug.
    if ($previous !== '' && $previous !== $path) {
        deleteStoredImage($previous);
    }
}

/**
 * Show or hide a product. False if there is no product with that id.
 */
function setProductActive(int $id, bool $active): bool
{
    if ($id <= 0) {
        return false;
    }

    /*
     * Whether the row is there is settled with a read, not with rowCount().
     *
     * PDO's MySQL driver reports rows *changed* rather than rows matched, so hiding a
     * product that is already hidden and hiding one that does not exist both come back
     * as 0 -- and only the second of those is worth telling the admin about.
     */
    if (adminFetchProduct($id) === null) {
        return false;
    }

    $statement = getDatabaseConnection()->prepare('UPDATE products SET is_active = :is_active WHERE id = :id');
    $statement->bindValue(':is_active', $active ? 1 : 0, PDO::PARAM_INT);
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();

    return true;
}

/**
 * Delete a hidden product for good. False if it was not there, or was still visible.
 *
 * $imagePath is that product's image_path as the caller already read it, and its photo is
 * removed from disk only once the row is really gone. It is a parameter rather than
 * something this function looks up so that the guard below can stay where it is: reading
 * the row here to find the photo would turn the statement into a read-then-write pair,
 * which is exactly what the comment underneath argues against. A caller that does not
 * have the path leaves the file behind, which is untidy rather than wrong.
 */
function deleteProduct(int $id, string $imagePath = ''): bool
{
    if ($id <= 0) {
        return false;
    }

    /*
     * `AND is_active = 0` is in the statement, not in a read above it.
     *
     * A product has to be hidden before it can be deleted, and that has to hold even
     * if the row changed between the page being drawn and the button being pressed --
     * a read-then-delete pair would happily destroy a product that went back on sale
     * in between. It is also the second lock on the door: config/csrf.php now means a forged
     * cross-site POST cannot reach this statement at all, but the two-step rule was written
     * when it could, and it stays -- destroying a product should take two deliberate presses
     * whoever is pressing, with the hiding visible in the list in between.
     */
    $statement = getDatabaseConnection()->prepare('DELETE FROM products WHERE id = :id AND is_active = 0');
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();

    if ($statement->rowCount() !== 1) {
        return false;
    }

    // Only now. A photo deleted beside a row that turned out to still be on sale would
    // take the picture off a live product.
    deleteStoredImage($imagePath);

    return true;
}
