<?php
// Admin pages start with the guard, before any output. See guard.php. It also has to
// run before the redirect below, which needs the headers unsent.
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../config/events-admin.php';
require_once __DIR__ . '/../config/products.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/footer.php';
require_once __DIR__ . '/../components/toast.php';

/*
 * Add or edit an event.
 *
 * The shape of this page is php/admin/component-edit.php's: the same order of operations, the same
 * 404-with-a-real-page for a missing id, the same redirect-after-POST so a refresh cannot
 * re-submit, and the same choice to report refusals as toasts rather than as an inline block.
 * What is different is the promo list.
 *
 * THE PROMO LIST IS PART OF THIS ONE FORM, not a second screen and not a row of add buttons. It
 * posts the whole set of ticked products every time and saveEventProducts() works out the
 * difference -- exactly what php/admin/product-parts.php does for a recipe, for the same reasons.
 * There is no add-a-row JavaScript, which keeps this project's rule that every POST in it is a plain
 * form submit, and UNIQUE (event_id, product_id) means a double submit cannot produce a duplicate.
 *
 * ONE TRANSACTION covers the event row and its promo list together, because the two halves are one
 * intention. A save that stored a 20% discount and then failed to tick the bouquets would leave a
 * promo that looks configured on this screen and does nothing on the shop -- worse than a refusal,
 * because nobody finds out until the day.
 *
 * EVERY PRICE ON THIS PAGE IS A LIST PRICE. The picker and the promo list read through
 * adminFetchProducts() in products-admin.php, which does not apply the promo, rather than through
 * fetchProducts(), which does. The admin needs the number the discount is computed FROM; showing
 * them ₱30.40 beside a bouquet they are about to take 20% off would be circular.
 */

$id = is_numeric($_GET['id'] ?? null) ? (int) $_GET['id'] : 0;
$event = null;
$isEdit = $id > 0;

if ($isEdit) {
    $event = adminFetchEvent($id);

    /*
     * A real 404 page for an id that is not there, rather than a redirect to the list with a toast.
     * An admin who followed a stale link wants to know the event is gone, on the page they asked
     * for; a redirect would read as though the link worked and the list simply did not contain it.
     */
    if ($event === null) {
        http_response_code(404);
        renderHeader('Event not found');
        ?>
        <main class="page">
            <div class="empty-state">
                <h1>That event is not here</h1>
                <p>It may have been deleted since you opened the link.</p>
                <p><a class="btn btn-primary" href="<?php echo escape(url('php/admin/events.php')); ?>">Back to events</a></p>
            </div>
        </main>
        <?php
        renderFooter();
        exit;
    }
}

/*
 * The form's state: the row on a GET, or what was typed on a failed POST, so nothing has to be
 * entered twice.
 *
 * A new event defaults to starting today and ending in a week. Every date field left blank for the
 * admin to fill from scratch is a date field somebody leaves blank and then gets refused for.
 */
$form = $isEdit
    ? [
        'name' => (string) $event['name'],
        'tagline' => (string) $event['tagline'],
        'starts_on' => (string) $event['starts_on'],
        'ends_on' => (string) $event['ends_on'],
        'discount_percent' => (string) (int) $event['discount_percent'],
        'featured_product_id' => $event['featured_product_id'] === null ? '' : (string) (int) $event['featured_product_id'],
        'is_active' => (int) $event['is_active'],
    ]
    : [
        'name' => '',
        'tagline' => '',
        'starts_on' => date('Y-m-d'),
        'ends_on' => date('Y-m-d', strtotime('+7 days')),
        'discount_percent' => '0',
        'featured_product_id' => '',
        'is_active' => 1,
    ];

// Which products are ticked: whatever is stored on a GET, whatever was ticked on a failed POST.
$ticked = $isEdit ? adminEventProductIds($id) : [];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    // Re-read the ticks before validating, so a refused save redraws the boxes the admin actually
    // ticked rather than what is in the database.
    $ticked = [];

    foreach (is_array($_POST['products'] ?? null) ? $_POST['products'] : [] as $raw) {
        $productId = (int) $raw;

        if ($productId > 0) {
            $ticked[$productId] = true;
        }
    }

    // Back in the boxes goes what was typed, not what is stored -- correcting one field should not
    // throw away the other six.
    $form = [
        'name' => productInputText($_POST, 'name'),
        'tagline' => productInputText($_POST, 'tagline'),
        'starts_on' => productInputText($_POST, 'starts_on'),
        'ends_on' => productInputText($_POST, 'ends_on'),
        'discount_percent' => productInputText($_POST, 'discount_percent'),
        'featured_product_id' => productInputText($_POST, 'featured_product_id'),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    // The id being edited is excused from the duplicate-name check, or saving an event without
    // renaming it would complain that it already exists.
    $checked = validateEventInput($_POST, $isEdit ? $id : null);
    $errors = $checked['errors'];

    /*
     * A discount with nothing ticked is refused rather than stored.
     *
     * It is the one combination that reads as configured on this screen and does nothing at all on
     * the shop: the banner would say "20% off selected flowers" and every price would be unchanged.
     * The list screen draws a "No flowers picked" pill for any that already exist, but refusing it
     * here is what stops one being made by accident in the first place.
     */
    if ($errors === [] && (int) $checked['values']['discount_percent'] > 0 && $ticked === []) {
        $errors[] = 'Pick which flowers the discount is on, or set the discount back to 0.';
    }

    if ($errors === []) {
        $database = getDatabaseConnection();

        try {
            $database->beginTransaction();

            if ($isEdit) {
                updateEvent($id, $checked['values']);
            } else {
                $id = createEvent($checked['values']);
            }

            // Inside the same transaction, so the event and its promo list commit or fail together.
            // saveEventProducts() notices it is already in one and does not nest.
            $saved = saveEventProducts($id, array_keys($ticked));

            if ($saved['errors'] !== []) {
                throw new RuntimeException(implode(' ', $saved['errors']));
            }

            $database->commit();

            $percent = (int) $checked['values']['discount_percent'];

            flashToast(
                'success',
                '"' . $checked['values']['name'] . '" is saved'
                    . ($percent > 0
                        ? ', ' . $percent . '% off ' . count($ticked) . ' flower'
                            . (count($ticked) === 1 ? '' : 's') . '.'
                        : '.')
            );

            redirectTo(url('php/admin/events.php'));
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }

            error_log('admin/event-edit.php: ' . $exception->getMessage());
            $errors[] = 'That did not save. Nothing has changed.';
        }
    }
}

/*
 * Every product, for the picker and the promo list, through the admin's own fetcher so the prices
 * shown are list prices.
 *
 * A high limit rather than paging: a promo list spread over pages could not be posted as one form,
 * and this shop has thirteen products. EVENT_PRODUCTS_MAX is the ceiling saveEventProducts()
 * enforces.
 */
$allProducts = [];
$loadFailed = false;

try {
    $allProducts = adminFetchProducts(['status' => 'all'], EVENT_PRODUCTS_MAX);
} catch (PDOException $exception) {
    error_log('admin/event-edit.php products: ' . $exception->getMessage());
    $loadFailed = true;
}

// Grouped by kind, because "bouquets" and "single flowers" is how the shop thinks about its
// catalogue, and two lists of six scan better than one flat list of thirteen checkboxes.
$grouped = ['bouquet' => [], 'flower' => []];

foreach ($allProducts as $product) {
    $kind = (string) $product['kind'];

    if (array_key_exists($kind, $grouped)) {
        $grouped[$kind][] = $product;
    }
}

$toasts = takeFlashToasts();

// Refusals as toasts, the same as component-edit.php: one place on the page says what went wrong,
// whether it came from this request or was flashed by the last one.
foreach ($errors as $error) {
    $toasts[] = ['type' => 'error', 'message' => $error];
}

// What the preview arrows should use. Read from the form rather than the row, so typing 25 and
// submitting a form that then fails still previews at 25.
$previewPercent = (int) ($form['discount_percent'] === '' ? 0 : (int) $form['discount_percent']);

renderHeader($isEdit ? 'Edit ' . (string) $form['name'] : 'Add an event');
renderToasts($toasts);
?>

    <main class="page">
        <p class="breadcrumb"><a href="<?php echo escape(url('php/admin/events.php')); ?>">&larr; Events</a></p>

        <header class="page-head">
            <p class="page-eyebrow">Admin</p>
            <h1><?php echo $isEdit ? 'Edit this event' : 'Add an event'; ?></h1>
            <p class="page-intro">
                An occasion with a featured flower and a promo. One event shows on the shop at a time
                &mdash; if two overlap, the one that started most recently wins.
            </p>
        </header>

        <section class="panel">
            <form class="panel-form" method="post"
                  action="<?php echo escape(url('php/admin/event-edit.php' . ($isEdit ? '?id=' . $id : ''))); ?>">
                <?php echo csrfField(); ?>

                <label for="event-name">Name</label>
                <input id="event-name" name="name" type="text" value="<?php echo escape((string) $form['name']); ?>"
                       maxlength="<?php echo EVENT_NAME_MAX; ?>" required autofocus>
                <p class="field-hint">What the shop calls the occasion &mdash; &ldquo;Valentine&rsquo;s&rdquo;, &ldquo;Mother&rsquo;s Day&rdquo;.</p>

                <label for="event-tagline">Tagline</label>
                <input id="event-tagline" name="tagline" type="text" value="<?php echo escape((string) $form['tagline']); ?>"
                       maxlength="<?php echo EVENT_TAGLINE_MAX; ?>">
                <p class="field-hint">One line under the name on the home page. Optional.</p>

                <label for="event-starts">Starts</label>
                <!--
                    type="date" so a phone gets a date picker, but the value is checked in PHP with
                    checkdate() regardless: a POST is not obliged to have come from this form, and
                    '2026-02-30' passes any pattern while not being a day.
                -->
                <input id="event-starts" name="starts_on" type="date" required
                       value="<?php echo escape((string) $form['starts_on']); ?>">

                <label for="event-ends">Ends</label>
                <input id="event-ends" name="ends_on" type="date" required
                       value="<?php echo escape((string) $form['ends_on']); ?>">
                <p class="field-hint">Both days count. An event ending on the 14th runs all of the 14th.</p>

                <label for="event-featured">Featured flower</label>
                <select id="event-featured" name="featured_product_id">
                    <option value="">Nothing featured</option>
                    <?php foreach ($grouped as $kindKey => $kindProducts): ?>
                        <?php if ($kindProducts === []) { continue; } ?>
                        <optgroup label="<?php echo escape(productKindLabel($kindKey)); ?>">
                            <?php foreach ($kindProducts as $product): ?>
                                <option value="<?php echo (int) $product['id']; ?>"
                                    <?php echo (string) $form['featured_product_id'] === (string) (int) $product['id'] ? 'selected' : ''; ?>>
                                    <?php echo escape((string) $product['name']); ?><?php echo (int) $product['is_active'] === 0 ? ' (withdrawn)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <p class="field-hint">
                    Shown on the home page banner. A withdrawn flower can be picked for an event you
                    are setting up ahead, but it will not appear until you put it back on sale.
                </p>

                <label for="event-percent">Discount</label>
                <input id="event-percent" name="discount_percent" type="number" inputmode="numeric"
                       min="0" max="<?php echo EVENT_PERCENT_MAX; ?>" step="1"
                       value="<?php echo escape((string) $form['discount_percent']); ?>">
                <p class="field-hint">
                    Whole percent off, up to <?php echo EVENT_PERCENT_MAX; ?>. Leave it at 0 for an
                    event that features a flower without a promo.
                </p>

                <label class="auth-check">
                    <input type="checkbox" name="is_active" value="1" <?php echo (int) $form['is_active'] === 1 ? 'checked' : ''; ?>>
                    <span>Switched on</span>
                </label>
                <p class="field-hint">
                    Switched off means the shop never shows it, whatever its dates say. Useful for
                    setting one up in advance.
                </p>

                <!--
                    The promo list. A fieldset rather than more label/input pairs, because this is
                    one question with many answers and .panel-form's grid would otherwise interleave
                    thirteen checkboxes with thirteen labels down the left column.
                -->
                <fieldset class="promo-picker">
                    <legend>Which flowers the discount is on</legend>
                    <p class="field-hint">
                        Only the ticked ones change price. The discount is taken off when a customer
                        puts one in their basket, and <strong>the price they see is the price they
                        pay</strong> &mdash; even if they check out after the event has ended.
                    </p>

                    <?php if ($loadFailed): ?>
                        <p class="field-hint">We could not load the catalogue. Nothing has changed.</p>
                    <?php elseif ($allProducts === []): ?>
                        <p class="field-hint">
                            There are no products yet.
                            <a href="<?php echo escape(url('php/admin/product-edit.php')); ?>">Add one first</a>.
                        </p>
                    <?php else: ?>
                        <?php foreach ($grouped as $kindKey => $kindProducts): ?>
                            <?php if ($kindProducts === []) { continue; } ?>
                            <div class="promo-group">
                                <h3><?php echo escape(productKindLabel($kindKey)); ?></h3>
                                <div class="promo-grid">
                                    <?php foreach ($kindProducts as $product): ?>
                                        <?php
                                        $productId = (int) $product['id'];
                                        $listPrice = (string) $product['price'];
                                        ?>
                                        <label class="promo-item">
                                            <input type="checkbox" name="products[]" value="<?php echo $productId; ?>"
                                                <?php echo isset($ticked[$productId]) ? 'checked' : ''; ?>>
                                            <span class="promo-item-name">
                                                <?php echo escape((string) $product['name']); ?>
                                                <?php if ((int) $product['is_active'] === 0): ?>
                                                    <span class="pill pill-hidden">Hidden</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="promo-item-price">
                                                <?php echo escape(formatPrice($listPrice)); ?>
                                                <?php if ($previewPercent > 0 && $previewPercent <= EVENT_PERCENT_MAX): ?>
                                                    <!--
                                                        The promo price worked out with the same
                                                        function the shop front uses, so what is
                                                        previewed here and what a customer is charged
                                                        come out of one piece of arithmetic rather
                                                        than two that can drift apart.
                                                    -->
                                                    <span class="promo-item-then">&rarr; <?php echo escape(formatPrice(eventPromoPrice($listPrice, $previewPercent))); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </fieldset>

                <div class="panel-actions">
                    <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Save changes' : 'Add event'; ?></button>
                    <a class="btn btn-quiet" href="<?php echo escape(url('php/admin/events.php')); ?>">Cancel</a>
                </div>
            </form>
        </section>
    </main>
<?php renderFooter(); ?>
