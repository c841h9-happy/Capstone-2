<?php

declare(strict_types=1);

/**
 * The flower-head shape vocabulary: the ENUM, the two PHP lists beside it, and the builder branch.
 *
 *     C:\xampp\php\php.exe php\tools\shapes-unit.php
 *
 * WHY THIS FILE EXISTS. Before it, `shape` had **no automated coverage at all** -- not one
 * assertion in any of the seven suites mentioned it. That mattered because the value is spread
 * across four places that have to agree and that nothing forced to agree:
 *
 *   1. `product_components.shape`, the ENUM -- what the database will accept.
 *   2. `COMPONENT_SHAPES` in php/config/components.php -- what the admin form offers and what
 *      validateComponentInput() will write.
 *   3. `COMPONENT_SHAPE_LABELS` beside it -- what an admin reads when picking one.
 *   4. `bloomHead()` in js/customizer.js -- what actually gets drawn.
 *
 * `componentShape()`'s docblock already names the failure: *"the ENUM extended and
 * COMPONENT_SHAPES not"*. It handles that case gracefully, by drawing nothing. This suite is what
 * notices it, which is the part that was missing -- a bloom drawn as nothing is visible to a
 * customer long before it is visible to a developer.
 *
 * Added 2026-10-03 with the 'bud' shape, which is the first value appended to that ENUM since it
 * was created and therefore the first time the four-way agreement was actually tested.
 *
 * This is a unit suite, like cart-unit.php and orders-unit.php: no HTTP, no browser. The one
 * fixture it writes is a ZZTest part, swept at both ends.
 */

// Same guard as every other file in this folder. php\tools is under the webroot, and this one
// writes to product_components.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/components.php';
require_once __DIR__ . '/../config/components-admin.php';

$database = getDatabaseConnection();

// A notice has to be as loud as a failure: the point is to catch an undefined index the browser
// would swallow.
$notices = [];
set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$notices): bool {
    $notices[] = sprintf('%s in %s:%d', $message, basename($file), $line);
    return true;
});
error_reporting(E_ALL);

$failures = 0;
$checks = 0;

function heading(string $text): void
{
    echo "\n--- $text ---\n";
}

function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures, $checks;
    $checks++;

    if ($actual === $expected) {
        echo "  ok    $label\n";
        return;
    }

    $failures++;
    printf(
        "  FAIL  %s\n          expected: %s\n          actual:   %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

function checkTrue(string $label, mixed $actual): void
{
    check($label, $actual === true, true);
}

/**
 * The shape values the live column will actually accept, read out of its own definition.
 *
 * Parsed from information_schema rather than hard-coded, because a list typed into this file
 * would only be a fourth place to forget.
 */
function enumShapes(PDO $database): array
{
    $type = (string) $database->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'product_components'
            AND COLUMN_NAME = 'shape'"
    )->fetchColumn();

    preg_match_all("/'([^']+)'/", $type, $found);

    return $found[1];
}

/** Every ZZTest part this file could have left behind, on a previous run or on this one. */
function sweep(PDO $database): void
{
    // product_parts cascades from product_components, and stock_moves keeps its frozen
    // component_name with component_id set null -- so the part itself is the only thing to delete.
    // Each statement in its own try: ERRMODE_EXCEPTION means one failure would abandon the rest.
    foreach ([
        "DELETE FROM stock_moves WHERE component_name LIKE 'ZZTest Shape%'",
        "DELETE FROM product_components WHERE name LIKE 'ZZTest Shape%'",
    ] as $sql) {
        try {
            $database->exec($sql);
        } catch (PDOException $exception) {
            echo '  sweep failed: ', explode("\n", $exception->getMessage())[0], "\n";
        }
    }
}

sweep($database);

$partsBefore = (int) $database->query('SELECT COUNT(*) FROM product_components')->fetchColumn();

/* ------------------------------------------------------------------ *
 * The four places that have to agree.
 * ------------------------------------------------------------------ */

heading('the ENUM and COMPONENT_SHAPES are the same list');

$enum = enumShapes($database);
$const = COMPONENT_SHAPES;

check('the column accepts seven shapes', count($enum), 7);
check('COMPONENT_SHAPES holds seven', count($const), 7);

/*
 * The assertion this suite was written for. Order matters as well as membership: MySQL stores an
 * ENUM as the ordinal of its value, so a list that agrees on contents but not on order is a list
 * where a migration inserted a value in the middle and silently re-pointed every row after it.
 */
check('ENUM and COMPONENT_SHAPES agree exactly, order included', $enum, $const);

check("'bud' is in the ENUM", in_array('bud', $enum, true), true);
check("'bud' is in COMPONENT_SHAPES", in_array('bud', $const, true), true);
check("'bud' is LAST in the ENUM, so no existing row was renumbered", $enum[count($enum) - 1], 'bud');
check("'none' is still first and so still the default", $enum[0], 'none');

heading('every shape an admin can pick has something to read');

foreach ($const as $shape) {
    checkTrue("$shape has a label", array_key_exists($shape, COMPONENT_SHAPE_LABELS));
    checkTrue("$shape's label is not just its key", componentShapeLabel($shape) !== $shape);
}

check('there are no labels for shapes that do not exist', array_diff(array_keys(COMPONENT_SHAPE_LABELS), $const), []);

heading('the builder has a branch for every shape');

/*
 * A PHP suite cannot run js/customizer.js, so this reads it. That is weaker than executing it and
 * it is not pretending otherwise -- but the failure it is written for is "the ENUM and the admin
 * form learned a new shape and the renderer did not", which is a missing `case` and is visible in
 * the source. A bloom whose shape has no branch falls through to `default` and is silently drawn
 * as a rose, which is the one outcome componentShape()'s docblock calls out as unacceptable.
 */
$builder = (string) file_get_contents(__DIR__ . '/../../js/customizer.js');

foreach ($const as $shape) {
    if ($shape === 'none') {
        // 'none' is the absence of a shape; bloomHead() is never called for one.
        continue;
    }

    checkTrue(
        "js/customizer.js has a case for '$shape'",
        str_contains($builder, "case '$shape':")
    );
}

checkTrue('js/customizer.js defines budHead()', str_contains($builder, 'function budHead('));
checkTrue('js/customizer.js defines petalWedge()', str_contains($builder, 'function petalWedge('));
checkTrue('js/customizer.js defines foliageLeaves()', str_contains($builder, 'function foliageLeaves('));
checkTrue('the bouquet renderer adds the chosen foliage', str_contains($builder, 'if (selection.foliage)'));
checkTrue('foliage is a single-pick design kind', str_contains($builder, "['wire', 'wrap', 'accent', 'foliage']"));

/*
 * The petals are open shells. A single-sided material renders the inside of a cupped petal as a
 * hole, which is invisible in a wireframe and obvious on screen, so the DoubleSide helper existing
 * is worth pinning.
 */
checkTrue('the bud uses a double-sided material', str_contains($builder, 'THREE.DoubleSide'));

heading('no name is declared twice at the top level of the builder');

/*
 * WHY THIS CHECK EXISTS, and why it is cruder than it looks.
 *
 * On 2026-10-03 a second `const GOLDEN_ANGLE` was added to js/customizer.js -- the name was
 * already in use further down, for the stem placement, for the same mathematical reason. A
 * duplicate top-level `const` is a SyntaxError for the WHOLE MODULE, so the file never evaluated
 * and EVERY flower shape fell back to the written summary, not just the new one. php/customize.php
 * is built to survive that silently (one fallback for no JavaScript, one for no WebGL), so the
 * only symptom was a canvas that stayed hidden -- indistinguishable from a machine with no GPU.
 *
 * Three things failed to catch it, and the sections above this one are two of them: str_contains()
 * sees a missing `case` but cannot see a file that does not parse. The third was `node --check
 * js/customizer.js`, which parses a .js file as a CommonJS SCRIPT and exits 0 on exactly this
 * error; only the same bytes named .mjs fail correctly. php/tools/run-all.ps1 now does that
 * copy-to-.mjs parse as a real step, which is the proper fix.
 *
 * This is the cheap half, here so that running THIS suite on its own still catches the one
 * failure mode that actually happened. It is a scan, not a parser: it only looks at declarations
 * starting in column 0, which is what "top level" looks like in this file's style. A duplicate
 * nested inside a function is legal and is none of its business.
 */
preg_match_all('/^(?:const|let)\s+([A-Za-z_$][\w$]*)\s*=/m', $builder, $declarations);

$names = $declarations[1];
$duplicates = [];

foreach (array_count_values($names) as $name => $times) {
    if ($times > 1) {
        $duplicates[] = "$name (x$times)";
    }
}

check('every top-level const/let in customizer.js is declared once', $duplicates, []);
checkTrue('and there are enough of them that the scan is really looking', count($names) > 10);

// The specific one, named, because a general check that silently stops matching would pass.
check(
    'GOLDEN_ANGLE in particular is declared exactly once',
    substr_count($builder, "\nconst GOLDEN_ANGLE"),
    1
);

/* ------------------------------------------------------------------ *
 * componentShape(): what actually reaches the renderer.
 * ------------------------------------------------------------------ */

heading('componentShape() resolves a row to a shape');

check('a bloom keeps its bud', componentShape(['kind' => 'bloom', 'shape' => 'bud']), 'bud');
check('a bloom keeps its rose', componentShape(['kind' => 'bloom', 'shape' => 'rose']), 'rose');

/*
 * The two refusals, which are the interesting half. Both return 'none' -- draw nothing -- rather
 * than falling back to a rose, so a wrong row is visible as an absence instead of masquerading as
 * a flower somebody chose.
 */
check('a wrap cannot be a bud however the row reads', componentShape(['kind' => 'wrap', 'shape' => 'bud']), 'none');
check('a shape this build does not know draws nothing', componentShape(['kind' => 'bloom', 'shape' => 'orchid']), 'none');
check('an empty shape draws nothing', componentShape(['kind' => 'bloom', 'shape' => '']), 'none');
check('a missing shape key draws nothing', componentShape(['kind' => 'bloom']), 'none');
check('case and padding are tolerated', componentShape(['kind' => 'bloom', 'shape' => '  BUD  ']), 'bud');

/* ------------------------------------------------------------------ *
 * The form's own validation.
 * ------------------------------------------------------------------ */

heading('validateComponentInput() on the new shape');

$base = [
    'name' => 'ZZTest Shape Bud',
    'kind' => 'bloom',
    'description' => 'A fixture for the shape suite. Deleted when the suite ends.',
    'price' => '15.00',
    'colour' => 'Dusty rose',
    'colour_hex' => '#b07f9f',
    'sort_order' => '950',
    'is_active' => '1',
];

$good = validateComponentInput($base + ['shape' => 'bud']);
check('a bud bloom validates', $good['errors'], []);
check('and the shape survives validation', $good['values']['shape'], 'bud');

$junk = validateComponentInput($base + ['shape' => 'orchid']);
checkTrue('a shape the builder does not know is refused', $junk['errors'] !== []);
check('and it is refused rather than silently made a rose', $junk['values']['shape'], 'none');

$bare = validateComponentInput($base + ['shape' => 'none']);
checkTrue("a bloom left at 'none' is refused", $bare['errors'] !== []);

// A non-bloom is forced to 'none' on the way in as well as on the way out, so a request that did
// not come from the form cannot store a cone-shaped wrap that claims to be a bud.
$wrap = validateComponentInput(['kind' => 'wrap', 'shape' => 'bud'] + $base);
check('a wrap asking to be a bud is written as none', $wrap['values']['shape'], 'none');
check('and that is not an error, just a correction', $wrap['errors'], []);

/* ------------------------------------------------------------------ *
 * It round-trips through the database.
 * ------------------------------------------------------------------ */

heading('a bud part survives a write and a read');

$id = createComponent($good['values']);
checkTrue('the part was created', $id > 0);

$stored = (string) $database->query(
    'SELECT shape FROM product_components WHERE id = ' . (int) $id
)->fetchColumn();
check('the column really holds bud', $stored, 'bud');

$row = adminFetchComponent($id);
checkTrue('and it reads back as a row', is_array($row));
check('with its shape intact', componentShape($row ?? []), 'bud');
check('and its label ready for the form', componentShapeLabel('bud'), 'Bud -- a rose part-opened');

// Changing it to another shape and back, because an ENUM that accepts a value on INSERT but not on
// UPDATE would be a strange failure and a cheap one to rule out.
updateComponent($id, ['shape' => 'rose'] + $good['values']);
check('it can be changed to a rose', (string) $database->query('SELECT shape FROM product_components WHERE id = ' . (int) $id)->fetchColumn(), 'rose');

updateComponent($id, $good['values']);
check('and back to a bud', (string) $database->query('SELECT shape FROM product_components WHERE id = ' . (int) $id)->fetchColumn(), 'bud');

/* ------------------------------------------------------------------ *
 * Put the shop back.
 * ------------------------------------------------------------------ */

heading('cleanup');

sweep($database);

$partsAfter = (int) $database->query('SELECT COUNT(*) FROM product_components')->fetchColumn();
check('product_components is back to its starting count', $partsAfter, $partsBefore);

$leftover = (int) $database->query(
    "SELECT COUNT(*) FROM product_components WHERE name LIKE 'ZZTest%'"
)->fetchColumn();
check('no ZZTest part left behind', $leftover, 0);

check('no PHP notices or warnings', $notices, []);

printf("\n%d checks, %d FAILED\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
