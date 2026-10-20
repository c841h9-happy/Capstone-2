<?php
// Admin pages start with the guard, before any output. See guard.php. This page also answers
// 404 and 503 and redirects after a save, all of which need the headers unsent.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/inventory.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * What a product is made of: one number beside every part, and one Save.
 *
 * A recipe, not a receipt, and sql/inventory-upgrade.sql sets out the difference at length.
 * The three tables that record what a custom design was built from are frozen snapshots of
 * an order that has already happened; this is a live instruction, read fresh every time
 * anybody asks how many of these the shop could make. So it is edited, and editing it changes
 * the answer for every bouquet made from tomorrow on -- and changes nothing about one already
 * sold.
 *
 * The whole form is the whole recipe. saveProductParts() removes any part the submission does
 * not name, which is what makes "a box beside every part" work: an empty box means "this
 * bouquet does not use that", and there is no add-a-row button to press, no list of parts to
 * pick from first, and no JavaScript. Every POST in this project is a plain form submit, so
 * this one is too -- it works with scripting off, and there is one code path rather than two.
 *
 * That contract is also why fetchRecipeChoices() draws a box for a withdrawn part when the
 * recipe already names one. A part with no box is a part the next Save deletes, and a bouquet
 * quietly losing its stem wire because somebody took that wire off the customizer is the
 * failure this screen would otherwise create.
 *
 * Two things it deliberately does not do.
 *
 * "Can make" is arithmetic over what is in the drawer right now, not a reservation and not a
 * promise. Nothing here is subtracted when somebody buys one, and two people ordering the
 * last bouquet is answered by making another -- see the note at the top of config/inventory.php.
 *
 * And where the recipe disagrees with the flower count on the product, it says so and changes
 * neither. The recipe is what the shop builds from; stem_count is what the catalogue told the
 * customer. Only a person can say which of the two is the mistake.
 */

/*
 * No add mode, unlike product-edit.php: a recipe belongs to a product that already exists, so
 * there is no id that means "a new one". A missing id and an unknown id are the same broken
 * link and get the same answer.
 */
$id = is_numeric($_GET['id'] ?? null) ? (int) $_GET['id'] : 0;

$product = null;
$loadFailed = false;

try {
    $product = adminFetchProduct($id);
} catch (PDOException $exception) {
    error_log('admin/product-parts.php: ' . $exception->getMessage());
    $loadFailed = true;
}

/*
 * A real page with a real status code rather than a redirect to the list, the same call
 * product-edit.php makes. 503 and not 404 when the read itself failed: 404 would say the
 * product does not exist, and the truth is that nobody can tell right now.
 */
if ($product === null) {
    http_response_code($loadFailed ? 503 : 404);

    renderHeader($loadFailed ? 'Something went wrong' : 'Product not found');
    ?>
    <main class="page">
        <div class="empty-state">
            <?php if ($loadFailed): ?>
                <h1>We could not load that</h1>
                <p>The catalogue is unavailable for a moment. Nothing has changed.</p>
            <?php else: ?>
                <h1>Product not found</h1>
                <p>There is no product with that id, so there is no recipe to edit. The products list links to the right one.</p>
            <?php endif; ?>
            <p><a class="btn btn-primary" href="<?php echo escape(url('php/admin/products.php')); ?>">Back to products</a></p>
        </div>
    </main>
    <?php
    renderFooter();
    exit;
}

$pageUrl = url('php/admin/product-parts.php?id=' . $id);

/*
 * What was typed, kept only when a save was refused.
 *
 * null the rest of the time, which is how the boxes below tell "show me the saved recipe"
 * from "show me what I typed, because one box of it was wrong". The same shape product-edit.php
 * uses for its fields.
 */
$posted = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    /*
     * The array has to actually be there, and this is a guard rather than a formality.
     * saveProductParts() reads its argument as the whole recipe, so an empty one means "this
     * is made of nothing" -- and a POST that lost the field, by a rename or by being written
     * by hand, would then wipe a recipe without anybody having pressed anything that said so.
     * The form submits every box including the empty ones, so a missing array cannot have
     * come from it.
     */
    $submitted = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : null;

    if ($submitted === null) {
        flashToast('error', 'Nothing was submitted, so the recipe is exactly as it was.');
        redirectTo($pageUrl);
    }

    try {
        $saved = saveProductParts($id, $submitted);

        if ($saved['errors'] !== []) {
            // Re-rendered inline with the numbers still in the boxes, rather than redirected:
            // the one wrong box is somewhere in a list of seventeen, and a page that came back
            // empty would mean typing the other sixteen again.
            $errors = $saved['errors'];
            $posted = $submitted;
        } else {
            $changes = [];

            if ($saved['added'] > 0) {
                $changes[] = $saved['added'] . ' added';
            }

            if ($saved['updated'] > 0) {
                $changes[] = $saved['updated'] . ' changed';
            }

            if ($saved['removed'] > 0) {
                $changes[] = $saved['removed'] . ' removed';
            }

            flashToast(
                'success',
                $changes === []
                    // Not an error, and worth saying: pressing Save on a recipe you have only
                    // read is a thing people do, and silence would look like a broken button.
                    ? 'That is already what "' . $product['name'] . '" is made of. Nothing to change.'
                    : 'Saved what "' . $product['name'] . '" is made of -- ' . implode(', ', $changes) . '.'
            );

            // A refresh must not be able to submit the same recipe again. It would be harmless
            // here -- the second save reports no changes -- but "harmless because the function
            // is idempotent" is not a thing to rely on from a screen.
            redirectTo($pageUrl);
        }
    } catch (PDOException $exception) {
        error_log('admin/product-parts.php: ' . $exception->getMessage());
        $errors[] = 'Unable to save this recipe right now. Check your database connection.';
        $posted = $submitted;
    }
}

$parts = [];
$quantities = [];
$makesBy = [];
$choices = [];
$choiceCount = 0;
$pieces = 0;
$dataFailed = false;

try {
    $parts = fetchProductParts($id);

    /*
     * Both maps come off that one read rather than from productPartQuantities(), which would
     * run the same query again. Two reads could also disagree -- another admin saving between
     * them -- and a row whose box said 12 while its "enough for" said something computed from
     * 3 would be the sort of inconsistency nobody can explain afterwards.
     */
    foreach ($parts as $part) {
        $componentId = (int) $part['component_id'];

        $quantities[$componentId] = (int) $part['quantity'];
        $makesBy[$componentId] = (int) $part['makes'];
        $pieces += (int) $part['quantity'];
    }

    $choices = fetchRecipeChoices($id);

    foreach ($choices as $group) {
        $choiceCount += count($group);
    }
} catch (PDOException $exception) {
    error_log('admin/product-parts.php: ' . $exception->getMessage());
    $dataFailed = true;
}

/*
 * How many could be made, and which part says so.
 *
 * Worked out from the rows above instead of calling buildableCount(), which exists and would
 * answer the same -- but as a second query it could answer it from a different instant, and
 * then the page would read "can make 3, held up by the rose heads" beside a row saying the
 * rose heads are enough for 4. One array, one answer, and the two halves cannot disagree.
 *
 * null for an empty recipe, never 0: nobody has said what this is made of, which is not the
 * same as the shop being unable to make it.
 */
$buildable = null;
$holdingUp = [];

foreach ($parts as $part) {
    $makes = (int) $part['makes'];

    if ($buildable === null || $makes < $buildable) {
        $buildable = $makes;
        $holdingUp = [];
    }

    // Not elseif: the part that set the new minimum is also one of the parts holding it up,
    // and there can be several of them at the same number.
    if ($makes === $buildable) {
        $holdingUp[] = (string) $part['name'];
    }
}

/*
 * The catalogue's flower count against the recipe's.
 *
 * Only worth comparing once there is a recipe to compare with, and only for a product that
 * advertises a count at all -- a single flower leaves stem_count empty.
 */
$advertised = $product['stem_count'] === null ? null : (int) $product['stem_count'];
$blooms = recipeBloomCount($parts);
$countsDisagree = $advertised !== null && $parts !== [] && $advertised !== $blooms;

$toasts = takeFlashToasts();

foreach ($errors as $error) {
    $toasts[] = ['type' => 'error', 'message' => $error];
}

if ($dataFailed) {
    $toasts[] = [
        'type' => 'error',
        'message' => 'Unable to load this recipe right now. Check your database connection, and that sql\inventory-upgrade.sql has been run.',
    ];
}

renderHeader('What it is made of');
renderToasts($toasts);
?>

    <main class="page">
        <p class="breadcrumb">
            <a href="<?php echo escape(url('php/admin/products.php')); ?>">Products</a>
            <span aria-hidden="true">&rsaquo;</span>
            <a href="<?php echo escape(url('php/admin/product-edit.php?id=' . $id)); ?>"><?php echo escape((string) $product['name']); ?></a>
            <span aria-hidden="true">&rsaquo;</span>
            <span>What it&rsquo;s made of</span>
        </p>

        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1>What it&rsquo;s made of</h1>
            <p class="page-intro">
                Every part that goes into one <strong><?php echo escape((string) $product['name']); ?></strong>,
                and how many of it. This is what the shop builds from, and what
                <a href="<?php echo escape(url('php/admin/inventory.php')); ?>">Stock</a> is counted against.
            </p>
        </header>

        <div class="profile-panels">
            <section class="panel">
                <h2>The recipe</h2>

                <?php if ($dataFailed): ?>
                    <p class="panel-intro">
                        We could not read this recipe, so there is nothing to edit here safely.
                        Nothing has changed.
                    </p>
                <?php elseif ($choiceCount === 0): ?>
                    <p class="panel-intro">
                        There are no parts yet, so there is nothing to build this from. Parts are
                        added on the Components screen, and then they show up here.
                    </p>
                    <p class="panel-actions">
                        <a class="btn btn-primary" href="<?php echo escape(url('php/admin/component-edit.php')); ?>">Add a part</a>
                    </p>
                <?php else: ?>
                    <p class="panel-intro">
                        How many of each part one of these takes. Leave a box empty for a part it
                        does not use &mdash; the boxes are the whole recipe, so emptying one takes
                        that part out.
                    </p>

                    <!--
                        One form over every box on the page. Not .panel-form: this has no ordinary
                        fields in it at all, and `.panel-form input` would outrank the number
                        boxes' own width. See .recipe-form in css/main.css.
                    -->
                    <form class="recipe-form" method="post" action="<?php echo escape($pageUrl); ?>">
                        <?php echo csrfField(); ?>

                        <?php foreach ($choices as $kind => $group): ?>
                            <?php if ($group === []): ?>
                                <?php continue; ?>
                            <?php endif; ?>

                            <!-- A heading per kind rather than a panel per kind: one Save covers
                                 the lot, and four panels would read as four forms. -->
                            <div class="recipe-group">
                                <h3><?php echo escape(componentKindLabel((string) $kind)); ?></h3>

                                <ul class="recipe-list">
                                    <?php foreach ($group as $component): ?>
                                        <?php
                                        $componentId = (int) $component['id'];
                                        $onHand = (int) $component['stock_on_hand'];
                                        $inRecipe = isset($quantities[$componentId]);

                                        /*
                                         * What the box holds. After a refusal it is what was
                                         * typed, junk and all, so the wrong one can be seen and
                                         * corrected; otherwise it is the saved quantity, and an
                                         * empty string for a part this product does not use.
                                         *
                                         * Empty rather than "0": seventeen zeroes is a page that
                                         * has to be read to be understood, and saveProductParts()
                                         * treats the two the same anyway.
                                         */
                                        if ($posted !== null) {
                                            $boxValue = is_scalar($posted[$componentId] ?? null) ? trim((string) $posted[$componentId]) : '';
                                        } else {
                                            $boxValue = $inRecipe ? (string) $quantities[$componentId] : '';
                                        }

                                        // Re-validated on the way out, because this value goes
                                        // inside a style attribute. See componentColourHex().
                                        $hex = componentColourHex($component);
                                        ?>
                                        <li class="recipe-item<?php echo $inRecipe ? ' recipe-item-in' : ''; ?>">
                                            <!--
                                                The name is the label, so clicking it puts the
                                                cursor in the box beside it. A visually-hidden
                                                label would say the same words twice to anybody
                                                listening to the page.
                                            -->
                                            <label class="recipe-item-part" for="part-<?php echo $componentId; ?>">
                                                <span class="colour-swatch" style="background: <?php echo escape($hex); ?>"></span>
                                                <span class="recipe-item-name">
                                                    <?php echo escape((string) $component['name']); ?>
                                                    <?php if ((int) $component['is_active'] !== 1): ?>
                                                        <!--
                                                            A withdrawn part only appears here
                                                            when this recipe already uses it. It
                                                            is shown so it can be taken out on
                                                            purpose rather than by the next save
                                                            doing it quietly.
                                                        -->
                                                        <span class="pill pill-hidden">Withdrawn</span>
                                                    <?php endif; ?>
                                                </span>
                                            </label>

                                            <span class="recipe-item-stock">
                                                <?php echo number_format($onHand); ?> in stock
                                                <?php if ($inRecipe): ?>
                                                    <?php $makes = $makesBy[$componentId] ?? 0; ?>
                                                    <?php if ($makes === 0): ?>
                                                        &middot; <span class="pill pill-danger">not enough for one</span>
                                                    <?php else: ?>
                                                        &middot; enough for <?php echo number_format($makes); ?>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </span>

                                            <!--
                                                A plain text input with inputmode, the convention
                                                the rest of the admin forms use: a type="number"
                                                box disagrees with itself across browsers about
                                                what it will accept and empties itself on the
                                                rest. maxlength matches PART_QUANTITY_MAX.
                                            -->
                                            <input class="admin-row-number" id="part-<?php echo $componentId; ?>"
                                                   name="qty[<?php echo $componentId; ?>]"
                                                   type="text" inputmode="numeric" maxlength="3"
                                                   value="<?php echo escape($boxValue); ?>" placeholder="0">
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>

                        <div class="panel-actions">
                            <button type="submit" class="btn btn-primary">Save the recipe</button>
                            <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/products.php')); ?>">Back to products</a>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <section class="panel">
                <h2>Right now</h2>
                <dl class="card-list">
                    <dt>Can make</dt>
                    <dd>
                        <?php if ($dataFailed): ?>
                            Unknown &mdash; the recipe could not be read.
                        <?php elseif ($buildable === null): ?>
                            <!-- Unknown, not zero. Nobody has written the instructions yet, and
                                 "0" here would read as an emergency instead of as a blank. -->
                            Nobody has said what this is made of yet.
                        <?php else: ?>
                            <strong><?php echo number_format($buildable); ?></strong>
                            from what is in stock
                        <?php endif; ?>
                    </dd>

                    <?php if ($buildable !== null && $holdingUp !== []): ?>
                        <dt>Held up by</dt>
                        <dd>
                            <!-- The part that makes the number what it is. "We can make 4" is
                                 only useful with "because of the tulip heads" beside it. -->
                            <?php echo escape(implode(', ', $holdingUp)); ?>
                        </dd>
                    <?php endif; ?>

                    <dt>Parts</dt>
                    <dd>
                        <?php if ($parts === []): ?>
                            <span class="admin-cell-blank">None yet</span>
                        <?php else: ?>
                            <?php echo count($parts); ?> part<?php echo count($parts) === 1 ? '' : 's'; ?>,
                            <?php echo number_format($pieces); ?> piece<?php echo $pieces === 1 ? '' : 's'; ?> in total
                        <?php endif; ?>
                    </dd>

                    <?php if ($advertised !== null): ?>
                        <dt>Flowers advertised</dt>
                        <dd>
                            <?php echo number_format($advertised); ?>
                            <?php if ($parts === []): ?>
                                <span class="admin-cell-blank">&mdash; nothing to check it against yet</span>
                            <?php elseif ($countsDisagree): ?>
                                <span class="pill pill-warn">The recipe makes <?php echo number_format($blooms); ?></span>
                            <?php else: ?>
                                &mdash; the recipe agrees
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                </dl>

                <?php if ($countsDisagree): ?>
                    <p class="panel-intro">
                        The catalogue says <?php echo number_format($advertised); ?> flowers and the
                        recipe adds up to <?php echo number_format($blooms); ?>. Nothing has been
                        changed either way: fix the count on
                        <a href="<?php echo escape(url('php/admin/product-edit.php?id=' . $id)); ?>">the product</a>
                        if the recipe is right, or the boxes above if it is not. Only wrapping,
                        wire and accents are left out of that total &mdash; flower heads are what a
                        customer counts.
                    </p>
                <?php endif; ?>

                <p class="card-note">
                    &ldquo;Can make&rdquo; is what the parts in the drawer come to right now. It is
                    not a reservation: nothing is taken out of stock until an order is marked as
                    being made, so two of these selling at once is two bouquets to build rather
                    than a number that has gone wrong.
                </p>
            </section>
        </div>
    </main>
<?php renderFooter(); ?>
