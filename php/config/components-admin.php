<?php

declare(strict_types=1);

/*
 * products-admin.php is required for three things that are genuinely shared rather than
 * copied: productInputText() (reading one trimmed field out of a submission, which is
 * not about products at all), PRODUCT_STATUSES and adminProductStatus() (the same three
 * views of a list), and uploads.php, which it already brings in.
 *
 * The alternative was a second copy of each, and then two places to change when a
 * fourth status or a fifth escaping rule turns up.
 *
 * productStatusLabel() is deliberately NOT reused -- see componentStatusLabel(). The
 * mechanism is shared; the words are not.
 */
require_once __DIR__ . '/products-admin.php';
require_once __DIR__ . '/components.php';

/**
 * Managing the customizer's parts list: admin reads, and every write.
 *
 * Split from config/components.php exactly as products-admin.php is split from
 * products.php, and for the identical reason -- that file puts `is_active = 1` into the
 * statement itself so that offering a withdrawn part is not something a caller can do by
 * forgetting a flag. This file is allowed to see and change everything, and its name says
 * so.
 *
 * image_path is written by setComponentImage() and by nothing else, so an admin
 * correcting a price cannot blank a photograph. See php/config/uploads.php for what is
 * allowed to become a photo; components use the same storeUploadedImage() as products,
 * with 'components' as the folder.
 */

/** How many rows the admin list shows at once. Matches the products list. */
const ADMIN_COMPONENTS_PER_PAGE = 20;

/*
 * The limits. The first two mirror sql/components-upgrade.sql exactly -- VARCHAR(120)
 * and VARCHAR(40).
 *
 * The last two are ours. description is TEXT and would take far more than 2000
 * characters; sort_order is SMALLINT UNSIGNED and would take 65,535, but a picker with
 * ten thousand positions in it is not a picker, and four digits is what the box on the
 * form is sized for.
 */
const COMPONENT_NAME_MAX = 120;
const COMPONENT_COLOUR_MAX = 40;
const COMPONENT_DESCRIPTION_MAX = 2000;
const COMPONENT_SORT_MAX = 9999;

/**
 * The orderings the admin list offers, as a fixed map.
 *
 * A separate map from PRODUCT_SORTS rather than a shared one, because the default is the
 * whole difference: a parts list is read in picker order, and "newest first" is
 * meaningless to somebody checking that the blooms are in a sensible sequence. The
 * mechanism is the same as PRODUCT_SORTS -- ORDER BY cannot be bound, so the visitor's
 * text is only ever a key into this array -- and every entry ends in an id tie-breaker
 * for the same reason: the seeded rows share a created_at to the second, and an ordering
 * with ties plus LIMIT/OFFSET lets page 2 repeat rows from page 1.
 */
const COMPONENT_SORTS = [
    'order' => 'sort_order ASC, id ASC',
    'newest' => 'created_at DESC, id DESC',
    'price-low' => 'price ASC, id ASC',
    'price-high' => 'price DESC, id DESC',
    'name' => 'name ASC, id ASC',
];

const COMPONENT_SORT_DEFAULT = 'order';

/**
 * The sort key to actually use, given whatever arrived in the query string.
 */
function componentSortKey(mixed $sort): string
{
    return is_string($sort) && isset(COMPONENT_SORTS[$sort]) ? $sort : COMPONENT_SORT_DEFAULT;
}

/**
 * The status filter to actually use: one of PRODUCT_STATUSES.
 *
 * A delegate rather than a second copy. It exists so that the components list reads
 * `componentStatus($_GET['status'])` instead of calling something named after products
 * on a page that has none -- the three statuses are one concept, but a call site should
 * still say what it is filtering.
 */
function componentStatus(mixed $status): string
{
    return adminProductStatus($status);
}

/**
 * "Offered" or "Withdrawn", for a value out of product_components.is_active.
 *
 * Its own words rather than productStatusLabel()'s "Visible" and "Hidden", which is not
 * pedantry: a component is not visible anywhere. It has no page of its own and never
 * appears on the shop front -- what is_active decides is whether the customizer offers it
 * as something to build with. Calling that "visible" would have the admin looking for it
 * on the catalogue.
 */
function componentStatusLabel(int $isActive): string
{
    return $isActive === 1 ? 'Offered' : 'Withdrawn';
}

/**
 * Build the WHERE clause and its bound values for the admin list.
 *
 * Shared by adminFetchComponents() and adminCountComponents() so a count that does not
 * match the rows cannot make the last page of the list empty or unreachable.
 *
 * @return array{sql: string, params: array<string, string>}
 */
function adminComponentFilterSql(array $filters): array
{
    // No is_active by default. That is the whole difference from fetchComponents(), and
    // why this is a separate file rather than a flag on that one.
    $where = [];
    $params = [];

    $status = componentStatus($filters['status'] ?? null);

    if ($status !== 'all') {
        $where[] = 'is_active = :is_active';
        $params['is_active'] = $status === 'active' ? '1' : '0';
    }

    $kind = componentKindFilter($filters['kind'] ?? '');

    if ($kind !== '') {
        $where[] = 'kind = :kind';
        $params['kind'] = $kind;
    }

    $search = trim((string) ($filters['search'] ?? ''));

    if ($search !== '') {
        // % and _ are LIKE wildcards, so a search for "50%" would otherwise match
        // everything. Escaped with ! and ESCAPE says so explicitly, because the default
        // escape character depends on the server's sql_mode and this should not.
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
 * One page of the admin list, withdrawn components included.
 *
 * $filters takes 'status', 'kind', 'search' and 'sort', all of which come from the query
 * string and all of which are reduced to a known value before they reach the SQL.
 *
 * @return array<int, array<string, mixed>>
 */
function adminFetchComponents(array $filters = [], int $limit = ADMIN_COMPONENTS_PER_PAGE, int $offset = 0): array
{
    $filter = adminComponentFilterSql($filters);
    $orderBy = COMPONENT_SORTS[componentSortKey($filters['sort'] ?? null)];

    $statement = getDatabaseConnection()->prepare(
        'SELECT id, name, kind, description, price, colour, colour_hex, shape, image_path, sort_order, is_active, created_at
         FROM product_components'
        . $filter['sql']
        . ' ORDER BY ' . $orderBy
        . ' LIMIT :limit OFFSET :offset'
    );

    foreach ($filter['params'] as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }

    // PARAM_INT rather than passing these through execute(): with ATTR_EMULATE_PREPARES
    // off everything in an execute() array is sent as a string, and LIMIT '20' is a
    // syntax error to MySQL.
    $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * How many components match the filters, ignoring paging.
 */
function adminCountComponents(array $filters = []): int
{
    $filter = adminComponentFilterSql($filters);

    $statement = getDatabaseConnection()->prepare('SELECT COUNT(*) FROM product_components' . $filter['sql']);
    $statement->execute($filter['params']);

    return (int) $statement->fetchColumn();
}

/**
 * One component, withdrawn or not, or null if there is no component with that id.
 *
 * The opposite of fetchComponent(), which cannot see a withdrawn row at all. An admin has
 * to be able to open the thing they just took out of the picker -- that is how it goes
 * back in.
 */
function adminFetchComponent(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $statement = getDatabaseConnection()->prepare(
        'SELECT id, name, kind, description, price, colour, colour_hex, shape, image_path, sort_order, is_active, created_at
         FROM product_components
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $id]);

    $component = $statement->fetch();

    return $component === false ? null : $component;
}

/**
 * Is some other component already called this?
 *
 * $ignoreId is the component being edited, which is allowed to keep its own name.
 *
 * The column collates utf8mb4_unicode_ci, so this ignores case: two parts differing only
 * in capitalisation are the same part entered twice. Unlike products-seed.sql's key, kind
 * is not part of the comparison -- a "Blush Rose Head" that is somehow a wrap is a
 * mistake, not a second part that happens to share a name.
 */
function componentNameExists(string $name, ?int $ignoreId = null): bool
{
    $sql = 'SELECT 1 FROM product_components WHERE name = :name';
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
 * Check a submitted component, and turn it into the values to store.
 *
 * One function for both the create form and the edit form, so the two can never disagree
 * about what a valid component is. $ignoreId is the id being edited.
 *
 * 'values' is only meaningful when 'errors' is empty -- the pages re-render a failed form
 * from the raw submission, so that what is in the boxes is what was typed.
 *
 * @return array{errors: array<int, string>, values: array<string, mixed>}
 */
function validateComponentInput(array $input, ?int $ignoreId = null): array
{
    $errors = [];

    $name = productInputText($input, 'name');
    $kindInput = productInputText($input, 'kind');
    $priceInput = productInputText($input, 'price');
    $colour = productInputText($input, 'colour');
    $hexInput = productInputText($input, 'colour_hex');
    $shapeInput = productInputText($input, 'shape');
    $description = productInputText($input, 'description');
    $sortInput = productInputText($input, 'sort_order');

    // mb_strlen, not strlen: VARCHAR(120) in utf8mb4 counts characters, so a name of 120
    // accented letters fits the column even though it is 240 bytes.
    if ($name === '') {
        $errors[] = 'Enter a name for the part.';
    } elseif (mb_strlen($name) > COMPONENT_NAME_MAX) {
        $errors[] = 'The name has to be ' . COMPONENT_NAME_MAX . ' characters or fewer.';
    } elseif (componentNameExists($name, $ignoreId)) {
        // A courtesy rather than a constraint -- there is no unique index behind it. What
        // it catches is a part added twice by someone who forgot they had added it.
        $errors[] = 'There is already a part called "' . $name . '".';
    }

    // in_array, not componentKindFilter(): that returns '' for junk, which means "no
    // filter" to a query and would quietly mean "bloom" to a write.
    $kind = in_array($kindInput, COMPONENT_KINDS, true) ? $kindInput : '';

    if ($kind === '') {
        $errors[] = 'Choose what sort of part this is.';
    }

    /*
     * The price, checked exactly as a product's is -- see validateProductInput() for why
     * this is in PHP rather than left to DECIMAL(10,2) UNSIGNED, which answers a negative
     * with SQLSTATE 22003 and would reach the admin as "unable to save".
     *
     * 0.00 is allowed here and is not an oversight. A customizer has parts that cost
     * nothing extra -- the plain green stem every bouquet starts with -- and refusing a
     * zero would force it to be priced at a centavo to exist at all.
     */
    $priceDigits = str_replace([' ', ','], '', $priceInput);
    $price = '';

    if ($priceDigits === '') {
        $errors[] = 'Enter a price. Use 0 for a part that costs nothing extra.';
    } elseif (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $priceDigits) !== 1) {
        $errors[] = 'Enter the price as digits, like 38 or 38.50 -- no more than two decimal places, and no more than 99,999,999.99.';
    } else {
        // Stored with both decimals whatever was typed, so "38" and "38.00" go into the
        // column identically and read identically back out.
        $price = number_format((float) $priceDigits, 2, '.', '');
    }

    if (mb_strlen($colour) > COMPONENT_COLOUR_MAX) {
        $errors[] = 'The colour name has to be ' . COMPONENT_COLOUR_MAX . ' characters or fewer.';
    }

    /*
     * The colour the 3D builder paints with.
     *
     * A leading '#' is added if it is missing rather than refused: the form uses
     * <input type="color">, which always sends one, but a browser without support for it
     * renders a plain text box and somebody typing "e8b4b8" into that has not made a
     * mistake worth a refusal. Stored lower case so two rows cannot hold the same colour
     * written two ways.
     */
    $hex = strtolower($hexInput);

    if ($hex !== '' && $hex[0] !== '#') {
        $hex = '#' . $hex;
    }

    if ($hex === '') {
        $errors[] = 'Pick the colour the 3D builder should paint this part.';
    } elseif (preg_match('/^#[0-9a-f]{6}$/', $hex) !== 1) {
        $errors[] = 'The builder colour has to be six hex digits, like #e8b4b8.';
    }

    /*
     * What the 3D builder draws for this part.
     *
     * Forced to 'none' for anything that is not a flower head, whatever was submitted --
     * the same move validateProductInput() makes when it forces stem_count to NULL for a
     * single flower, and for the same reason. The schema's rule is that only a bloom has a
     * chosen shape, so the write enforces it rather than trusting the form to have hidden
     * the field: the field is hidden by JavaScript, and a submission is not obliged to
     * have run any.
     *
     * A bloom, though, must have one. 'none' means "draw nothing", so a flower head left
     * at the column default would be an invisible bloom on a stem -- a bouquet that looks
     * broken, saved without a single complaint. The form offers only the six real shapes,
     * so neither branch below is reachable by filling it in; they are here for a request
     * that did not come from the form.
     *
     * in_array rather than a filter helper that returns a default. A junk shape has to be
     * a refusal: falling back to 'rose' would be an admin picking "Sunflower", getting a
     * rose, and having nothing to read that explains it.
     */
    $shape = 'none';

    if ($kind === 'bloom') {
        if ($shapeInput === '' || $shapeInput === 'none') {
            $errors[] = 'Choose the shape the 3D builder should draw for this flower head.';
        } elseif (!in_array($shapeInput, COMPONENT_SHAPES, true)) {
            $errors[] = 'That is not a shape the builder knows how to draw.';
        } else {
            $shape = $shapeInput;
        }
    }

    if ($description === '') {
        $errors[] = 'Write a short description of the part.';
    } elseif (mb_strlen($description) > COMPONENT_DESCRIPTION_MAX) {
        $errors[] = 'The description has to be ' . COMPONENT_DESCRIPTION_MAX . ' characters or fewer.';
    }

    /*
     * Where it sits in the picker.
     *
     * Blank means 0, not an error: an admin adding one part should not have to think about
     * ordering to get it saved, and 0 with the id tie-breaker puts it in a sensible place.
     * A typed value still has to be a whole number in range, because "10a" silently
     * becoming 10 is the sort of thing that is noticed a month later.
     */
    $sortOrder = 0;

    if ($sortInput !== '') {
        if (preg_match('/^\d+$/', $sortInput) !== 1 || (int) $sortInput > COMPONENT_SORT_MAX) {
            $errors[] = 'The picker position has to be a whole number between 0 and ' . COMPONENT_SORT_MAX . '.';
        } else {
            $sortOrder = (int) $sortInput;
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
            'colour_hex' => $hex,
            'shape' => $shape,
            'sort_order' => $sortOrder,
            // Unticked boxes are not submitted at all, so this is presence, not value. A
            // part can be written now and offered later.
            'is_active' => isset($input['is_active']) ? 1 : 0,
        ],
    ];
}

/**
 * Add a component. Returns its new id.
 *
 * Takes the 'values' half of validateComponentInput() and nothing else -- there is no path
 * into this function that has not been through those checks.
 *
 * image_path is left out so the column takes its '' default: a photo is attached by
 * setComponentImage() once the row exists and has an id to attach it to.
 */
function createComponent(array $values): int
{
    $connection = getDatabaseConnection();

    $statement = $connection->prepare(
        'INSERT INTO product_components (name, kind, description, price, colour, colour_hex, shape, sort_order, is_active)
         VALUES (:name, :kind, :description, :price, :colour, :colour_hex, :shape, :sort_order, :is_active)'
    );

    bindComponentValues($statement, $values);
    $statement->execute();

    return (int) $connection->lastInsertId();
}

/**
 * Change a component.
 *
 * image_path and created_at are not in the statement, for the same two reasons as
 * updateProduct(): a photo is its own field saved by setComponentImage(), and an UPDATE
 * naming the column would blank the picture every time somebody nudged a sort order.
 */
function updateComponent(int $id, array $values): void
{
    $statement = getDatabaseConnection()->prepare(
        'UPDATE product_components
            SET name = :name,
                kind = :kind,
                description = :description,
                price = :price,
                colour = :colour,
                colour_hex = :colour_hex,
                shape = :shape,
                sort_order = :sort_order,
                is_active = :is_active
          WHERE id = :id'
    );

    bindComponentValues($statement, $values);
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();
}

/**
 * The nine columns both writes have in common.
 *
 * Its own function so the INSERT and the UPDATE cannot end up binding the same value two
 * different ways.
 */
function bindComponentValues(PDOStatement $statement, array $values): void
{
    $statement->bindValue(':name', (string) $values['name'], PDO::PARAM_STR);
    $statement->bindValue(':kind', (string) $values['kind'], PDO::PARAM_STR);
    $statement->bindValue(':description', (string) $values['description'], PDO::PARAM_STR);
    // PARAM_STR for the price: it is a DECIMAL, and handing it over as text keeps it out
    // of a float on the way to a column chosen specifically to avoid one.
    $statement->bindValue(':price', (string) $values['price'], PDO::PARAM_STR);
    $statement->bindValue(':colour', (string) $values['colour'], PDO::PARAM_STR);
    $statement->bindValue(':colour_hex', (string) $values['colour_hex'], PDO::PARAM_STR);
    // Already reduced to one of COMPONENT_SHAPES by validateComponentInput(), and to
    // 'none' for anything that is not a bloom. The ?? is for a $values array assembled by
    // something other than that function -- an ENUM would refuse the empty string with
    // SQLSTATE 01000 and a truncated-value warning, which is a confusing way to find out.
    $statement->bindValue(':shape', (string) ($values['shape'] ?? 'none'), PDO::PARAM_STR);
    $statement->bindValue(':sort_order', (int) $values['sort_order'], PDO::PARAM_INT);
    $statement->bindValue(':is_active', (int) $values['is_active'] === 1 ? 1 : 0, PDO::PARAM_INT);
}

/**
 * Point a component at a photo, or at nothing.
 *
 * The only writer of image_path on this table. $path is a project-relative value that
 * storeUploadedImage() has already returned; this function does not re-check it, because
 * the check that matters happened when the bytes were read.
 *
 * The old file is removed after the column is written, never before -- a failed removal
 * leaves a file nobody references, where the other order leaves a component whose picture
 * has vanished, and only one of those looks broken.
 */
function setComponentImage(int $id, string $path): void
{
    if ($id <= 0) {
        return;
    }

    $existing = adminFetchComponent($id);

    if ($existing === null) {
        return;
    }

    $previous = trim((string) $existing['image_path']);

    $statement = getDatabaseConnection()->prepare('UPDATE product_components SET image_path = :image_path WHERE id = :id');
    $statement->bindValue(':image_path', $path, PDO::PARAM_STR);
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();

    // Replacing a photo with the same photo is not something the form can do, but a caller
    // could, and deleting the file we just pointed at would be quite a bug.
    if ($previous !== '' && $previous !== $path) {
        deleteStoredImage($previous);
    }
}

/**
 * Offer or withdraw a component. False if there is no component with that id.
 */
function setComponentActive(int $id, bool $active): bool
{
    if ($id <= 0) {
        return false;
    }

    // Whether the row is there is settled with a read, not with rowCount(): PDO's MySQL
    // driver reports rows *changed*, so withdrawing something already withdrawn and
    // withdrawing something that does not exist both come back as 0 -- and only the second
    // is worth telling the admin about.
    if (adminFetchComponent($id) === null) {
        return false;
    }

    $statement = getDatabaseConnection()->prepare('UPDATE product_components SET is_active = :is_active WHERE id = :id');
    $statement->bindValue(':is_active', $active ? 1 : 0, PDO::PARAM_INT);
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();

    return true;
}

/**
 * Delete a withdrawn component for good. False if it was not there, or still on offer.
 *
 * $imagePath is that component's image_path as the caller already read it, and the photo
 * comes off disk only once the row is really gone.
 *
 * `AND is_active = 0` is in the statement rather than in a read above it, exactly as in
 * deleteProduct(): a part has to be withdrawn before it can be destroyed, and that has to
 * hold even if the row changed between the page being drawn and the button being pressed.
 * It is also the second lock on the door. config/csrf.php now means a forged cross-site POST
 * cannot get this far at all -- but the two-step rule was written when it could, and it stays:
 * destroying a part the customizer is built around should take two deliberate presses whoever
 * is pressing, and the withdrawal is visible in the list in between.
 */
function deleteComponent(int $id, string $imagePath = ''): bool
{
    if ($id <= 0) {
        return false;
    }

    $statement = getDatabaseConnection()->prepare('DELETE FROM product_components WHERE id = :id AND is_active = 0');
    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();

    if ($statement->rowCount() !== 1) {
        return false;
    }

    // Only now. A photo deleted beside a row that turned out to still be on offer would
    // take the picture off a live part.
    deleteStoredImage($imagePath);

    return true;
}
