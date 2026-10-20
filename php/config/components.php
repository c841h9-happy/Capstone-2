<?php

declare(strict_types=1);

/*
 * products.php is required for formatPrice() and CURRENCY_SYMBOL, and for nothing
 * else. Money has one format in this project, and a second copy of that function
 * living here is how a component ends up printed as "38" on one screen and
 * "PHP 38.00" on another. It also brings database.php and paths.php with it.
 */
require_once __DIR__ . '/products.php';

/**
 * Reading the customizer's parts list.
 *
 * A component is what a bouquet is made *of*: a bloom, the wire it sits on, the paper
 * around it, a ribbon. The 3D builder assembles a design out of these and prices it by
 * adding them up, which is why they are priced individually and why they are not in
 * `products` -- see sql/components-upgrade.sql for that argument in full.
 *
 * This file is the read side, and it is written for a customizer that does not exist
 * yet. It only does the two things such a builder actually needs -- "give me everything
 * a customer may pick" and "tell me about this one part" -- rather than guessing at an
 * API for it. Writes are in config/components-admin.php, the same split products uses.
 */

/** The five things a fuzzy wire bouquet is built from, matching the ENUM. */
const COMPONENT_KINDS = ['bloom', 'wire', 'wrap', 'accent', 'foliage'];

/**
 * What each kind is called on screen.
 *
 * The stored values are short because they are keys; these are what a person reads.
 * "Flower head" rather than "Bloom" because that is what the shop calls it out loud.
 */
const COMPONENT_KIND_LABELS = [
    'bloom' => 'Flower head',
    'wire' => 'Stem wire',
    'wrap' => 'Wrapping',
    'accent' => 'Accent',
    'foliage' => 'Foliage',
];

/**
 * The colour the 3D builder falls back to.
 *
 * colour_hex is NOT NULL with no default, so a row cannot be saved without one -- but a
 * value read back could still be junk if it was written by hand in the MySQL shell, and
 * a renderer handed "; drop" instead of a colour should get grey rather than an error.
 */
const COMPONENT_FALLBACK_HEX = '#cccccc';

/**
 * "Flower head", "Stem wire", "Wrapping" or "Accent", for a value out of kind.
 *
 * Falls back to the raw value rather than to a guess, so a kind added to the ENUM and
 * not added to the labels above shows up as itself instead of silently reading as a
 * bloom.
 */
function componentKindLabel(string $kind): string
{
    return COMPONENT_KIND_LABELS[$kind] ?? $kind;
}

/**
 * The kind filter to actually use: one of COMPONENT_KINDS, or '' for no filter.
 *
 * The mirror of productKindFilter(), and separate from it because the two lists of
 * kinds are different -- sharing one function would mean 'bouquet' filtering the
 * components list to nothing rather than being refused.
 */
function componentKindFilter(mixed $kind): string
{
    return is_string($kind) && in_array($kind, COMPONENT_KINDS, true) ? $kind : '';
}

/**
 * The geometries the 3D builder knows how to draw, matching the ENUM in shape.
 *
 * Here rather than in config/customizer.php, beside COMPONENT_KINDS, because a shape is a
 * fact about the part in the same way its kind and its colour_hex are. The admin form
 * validates against this list and the builder draws from it, and those two live on
 * opposite sides of the read/write split -- so the list has to sit in the file both of
 * them already require.
 *
 * 'none' is first and is the default in the column. It is what every wire, wrap and
 * accent keeps: those three kinds have one drawn form each, so naming a shape for them
 * would be a value nobody reads.
 */
const COMPONENT_SHAPES = ['none', 'rose', 'tulip', 'sunflower', 'daisy', 'sprig', 'bud'];

/**
 * What each shape is called on screen.
 *
 * Read by an admin choosing one, not by the renderer, which only ever sees the key.
 */
const COMPONENT_SHAPE_LABELS = [
    'none' => 'No shape (not a flower head)',
    'rose' => 'Rose -- a furled spiral',
    'tulip' => 'Tulip -- a closed cup',
    'sunflower' => 'Sunflower -- wide, dark centre',
    'daisy' => 'Daisy -- small, five petals',
    'sprig' => 'Sprig -- buds up a stalk',
    'bud' => 'Bud -- a rose part-opened',
];

/**
 * The shape to actually draw for a row, as a key.
 *
 * Falls back to 'none' rather than to 'rose', and that is the whole point of the
 * function. A row holding a shape this build does not know -- the ENUM extended and
 * COMPONENT_SHAPES not, or a value written by hand in the MySQL shell -- gets drawn as
 * nothing at all, which is visible and asks a question. Silently drawing it as a rose
 * would be a bloom quietly wearing the wrong shape, which is not.
 *
 * A non-bloom is forced to 'none' here as well as on the way in. validateComponentInput()
 * already does it for anything the form writes, but the renderer should not be able to be
 * handed a cone-shaped wrap that claims to be a sunflower by a row nobody validated.
 */
function componentShape(array $component): string
{
    $shape = strtolower(trim((string) ($component['shape'] ?? '')));

    if ((string) ($component['kind'] ?? '') !== 'bloom') {
        return 'none';
    }

    return in_array($shape, COMPONENT_SHAPES, true) ? $shape : 'none';
}

/**
 * "Rose -- a furled spiral", for a value out of shape.
 *
 * Falls back to the raw value for the same reason componentKindLabel() does: a shape in
 * the ENUM but missing from the labels should show as itself rather than as a blank.
 */
function componentShapeLabel(string $shape): string
{
    return COMPONENT_SHAPE_LABELS[$shape] ?? $shape;
}

/**
 * A colour_hex value that is safe to put in a style attribute or hand to a renderer.
 *
 * Checked on the way out as well as on the way in. The form validates it, but this is
 * the value that ends up inside `background: <here>` on the admin list and inside a
 * material in the browser, and a column can be written by something that is not the
 * form. Anything not exactly '#' plus six hex digits becomes the fallback grey.
 */
function componentColourHex(array $component): string
{
    $hex = strtolower(trim((string) ($component['colour_hex'] ?? '')));

    return preg_match('/^#[0-9a-f]{6}$/', $hex) === 1 ? $hex : COMPONENT_FALLBACK_HEX;
}

/**
 * A component's photo URL, or null when there is nothing to show.
 *
 * Delegates rather than repeating the is_file() check: image_path means the same thing
 * in both tables and is written by the same storeUploadedImage(). The wrapper exists so
 * that call sites read honestly -- componentImageUrl($component) rather than
 * productImageUrl() applied to something that is not a product.
 */
function componentImageUrl(array $component): ?string
{
    return productImageUrl($component);
}

/**
 * Every component a customer may pick, in picker order.
 *
 * `is_active = 1` is in the statement itself rather than in a parameter, exactly as
 * productFilterSql() does it and for the same reason: forgetting it would offer a part
 * that has been withdrawn, and the customizer would price a design around something the
 * shop no longer has.
 *
 * $kind narrows it to one kind and is validated first, so a value off a query string
 * cannot reach the SQL. No paging: the whole point of a picker is that everything is in
 * it at once, and this list is a couple of dozen rows by design.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchComponents(string $kind = ''): array
{
    $kind = componentKindFilter($kind);

    $statement = getDatabaseConnection()->prepare(
        'SELECT id, name, kind, description, price, colour, colour_hex, shape, image_path, sort_order
         FROM product_components
         WHERE is_active = 1'
        . ($kind === '' ? '' : ' AND kind = :kind')
        // sort_order first, then id. The tie-breaker is not tidiness: every seeded row
        // shares a created_at to the second and an admin may leave every sort_order at
        // 0, and without it the picker's order could change between two page loads.
        . ' ORDER BY sort_order ASC, id ASC'
    );

    if ($kind !== '') {
        $statement->bindValue(':kind', $kind, PDO::PARAM_STR);
    }

    $statement->execute();

    return $statement->fetchAll();
}

/**
 * The same list, split into one array per kind.
 *
 * What the 3D builder actually wants: a picker has a row of blooms, a row of wires and
 * so on, and doing the grouping here means it is done once rather than in whatever
 * markup ends up drawing it. Every kind is present as a key even when it has nothing in
 * it, so a caller can loop over the result without checking isset() on each one.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function fetchComponentsByKind(): array
{
    $grouped = array_fill_keys(COMPONENT_KINDS, []);

    foreach (fetchComponents() as $component) {
        $kind = (string) $component['kind'];

        // A kind in the column but not in COMPONENT_KINDS would otherwise be dropped
        // without trace. It cannot happen through the form, but it can through the ENUM
        // being extended and this constant not being.
        $grouped[$kind][] = $component;
    }

    return $grouped;
}

/**
 * One component a customer may pick, or null.
 *
 * A withdrawn component returns null exactly like a missing one, the same way
 * fetchProduct() treats a hidden product: the customizer should not be able to price a
 * design around a part that is no longer offered, and it has no business being told the
 * difference.
 *
 * This is the function a saved design will be re-priced through later, which is why it
 * returns the price rather than the caller keeping one.
 */
function fetchComponent(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $statement = getDatabaseConnection()->prepare(
        'SELECT id, name, kind, description, price, colour, colour_hex, shape, image_path, sort_order, created_at
         FROM product_components
         WHERE id = :id AND is_active = 1
         LIMIT 1'
    );
    $statement->execute(['id' => $id]);

    $component = $statement->fetch();

    return $component === false ? null : $component;
}
